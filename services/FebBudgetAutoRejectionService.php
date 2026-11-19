<?php
declare(strict_types=1);

require_once __DIR__ . '/../model/EmployeeLegalRequestManager.php';
require_once __DIR__ . '/../controllers/NotificationController.php';

final class FebBudgetAutoRejectionService
{
    private PDO $pdo;
    private EmployeeLegalRequestManager $manager;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->manager = new EmployeeLegalRequestManager($pdo);
    }

    public function rejectExpired(int $limit = 1000): array
    {
        $limit = max(1, min($limit, 5000));
        $rows = $this->manager->listRequestsForTeamAdvanced('finance', ['submitted'], 'feb', null, $limit, true);
        $deadline = new DateTimeImmutable('-72 hours');
        $actor = ['user_id' => 0, 'name' => 'Règle automatique FEB'];
        $result = ['rejected' => 0, 'skipped' => 0, 'errors' => 0];

        foreach ($rows as $row) {
            $payload = (array)($row['payload'] ?? []);
            $showsUnverifiedAssignment = !array_key_exists('budget_planned_label', $payload)
                || $payload['budget_planned_label'] === null;
            $submittedAt = trim((string)($row['submitted_at'] ?? ''));
            $submittedDate = $submittedAt !== '' ? DateTimeImmutable::createFromFormat('Y-m-d H:i:s', substr($submittedAt, 0, 19)) : false;

            if ($showsUnverifiedAssignment && (string)($payload['source'] ?? '') === 'recurring_needs') {
                $amount = (float)($payload['montant'] ?? 0);
                $designation = trim((string)($payload['feb_quote_title'] ?? $payload['description'] ?? $row['title'] ?? 'Besoin récurrent')) ?: 'Besoin récurrent';
                $start = trim((string)($payload['planned_start_date'] ?? $payload['date_livraison'] ?? ''));
                $end = trim((string)($payload['planned_end_date'] ?? $start));
                $payload['budget_planned'] = 'no';
                $payload['budget_planned_label'] = 'Non prévue au déboursé - proposition à négocier';
                if (empty($payload['proposed_debourse'])) {
                    $payload['proposed_debourse'] = [
                        'status' => 'submitted_for_analysis',
                        'ligne_devis_id' => 0,
                        'ligne_devis_proposal' => $designation,
                        'title' => $designation,
                        'planned_start_date' => $start,
                        'planned_end_date' => $end,
                        'lines' => [[
                            'designation' => $designation,
                            'categorie' => 'service',
                            'montant' => $amount,
                            'planned_start_date' => $start,
                            'planned_end_date' => $end,
                        ]],
                        'total_amount' => $amount,
                    ];
                }
                $this->manager->updateFebBeforeDecision((int)$row['id'], $payload, $actor, 'Ancienne FEB récurrente reclassée automatiquement en proposition de déboursé à négocier.', true);
                $result['skipped']++;
                continue;
            }

            if (!$showsUnverifiedAssignment || !$submittedDate || $submittedDate > $deadline) {
                $result['skipped']++;
                continue;
            }

            try {
                $this->pdo->beginTransaction();
                $fresh = $this->manager->getRequestById((int)$row['id']);
                if (!$fresh || (string)($fresh['status'] ?? '') !== 'submitted') {
                    $this->pdo->rollBack();
                    $result['skipped']++;
                    continue;
                }
                $this->cancelPreparations($fresh, $actor);
                $note = 'Refus automatique : affectation budgétaire non qualifiée dans les 72 heures suivant la soumission.';
                if (!$this->manager->rejectRequest((int)$row['id'], $actor, $note, false)) {
                    throw new RuntimeException('Le refus automatique n’a pas pu être enregistré.');
                }
                $this->manager->addRequestEvent((int)$row['id'], 'feb_budget_assignment_auto_rejected', $note, $actor);
                $this->pdo->commit();
                $result['rejected']++;
                try {
                    (new NotificationController($this->pdo))->notifyLegalRequestProcessed($fresh, 'rejected', $actor, $note);
                } catch (Throwable $ignored) {
                }
            } catch (Throwable $error) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                $result['errors']++;
            }
        }

        return $result;
    }

    private function cancelPreparations(array $request, array $actor): void
    {
        $requestId = (int)($request['id'] ?? 0);
        $payload = (array)($request['payload'] ?? []);
        $draft = is_array($payload['provisional_decaissement'] ?? null) ? (array)$payload['provisional_decaissement'] : [];
        $draft['active'] = false;
        $draft['generation_cancelled'] = true;
        $draft['generation_cancelled_at'] = date('c');
        $draft['generation_cancelled_by'] = (string)$actor['name'];
        $payload['provisional_decaissement'] = $draft;
        if (is_array($payload['proposed_debourse'] ?? null)) {
            $payload['proposed_debourse']['cancelled_at'] = date('c');
            $payload['proposed_debourse']['cancelled_by'] = (string)$actor['name'];
        }
        unset($payload['validation_deferred_until'], $payload['validation_deferred_by'], $payload['validation_deferred_at']);
        if (!$this->manager->updateFebBeforeDecision($requestId, $payload, $actor, 'Préparations annulées automatiquement avant le refus à 72 heures.', true)) {
            throw new RuntimeException('Annulation des préparations impossible.');
        }

        $this->pdo->prepare('UPDATE feb_price_challenges SET archived_at=COALESCE(archived_at,NOW()), archive_reason=COALESCE(archive_reason,"feb_refusee_auto_72h") WHERE feb_request_id=:id')->execute(['id' => $requestId]);
        $link = $this->pdo->prepare('SELECT l.debourse_id,l.ligne_devis_id,db.feb_request_id FROM feb_disbursement_links l LEFT JOIN debourse_banamur db ON db.id=l.debourse_id WHERE l.feb_request_id=:id LIMIT 1 FOR UPDATE');
        $link->execute(['id' => $requestId]);
        $integration = $link->fetch(PDO::FETCH_ASSOC) ?: [];
        $debourseId = (int)($integration['debourse_id'] ?? 0);
        $ligneDevisId = (int)($integration['ligne_devis_id'] ?? 0);
        if ((int)($integration['feb_request_id'] ?? 0) === $requestId && $debourseId > 0) {
            $used = $this->pdo->prepare('SELECT COUNT(*) FROM fiche_decaissement WHERE feb_request_id=:feb OR ligne_debourse_id IN(SELECT id FROM ligne_debourse_banamur WHERE debourse_id=:debourse)');
            $used->execute(['feb' => $requestId, 'debourse' => $debourseId]);
            if ((int)$used->fetchColumn() === 0) {
                $this->pdo->prepare('DELETE FROM ligne_debourse_banamur WHERE debourse_id=:id')->execute(['id' => $debourseId]);
                $this->pdo->prepare('DELETE FROM debourse_banamur WHERE id=:id AND feb_request_id=:feb')->execute(['id' => $debourseId, 'feb' => $requestId]);
                if ($ligneDevisId > 0) {
                    $deleteLine = $this->pdo->prepare('DELETE FROM ligne_devis_banamur WHERE id=:id AND groupe=:groupe AND NOT EXISTS(SELECT 1 FROM debourse_banamur WHERE ligne_devis_id=:check_id)');
                    $deleteLine->execute(['id' => $ligneDevisId, 'groupe' => 'Ajout FEB #' . $requestId, 'check_id' => $ligneDevisId]);
                }
            }
        }
        $this->pdo->prepare('DELETE FROM feb_disbursement_links WHERE feb_request_id=:id')->execute(['id' => $requestId]);
    }
}
