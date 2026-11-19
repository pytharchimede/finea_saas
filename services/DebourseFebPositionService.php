<?php
declare(strict_types=1);

require_once __DIR__ . '/FebPlanningSynchronizationService.php';
require_once __DIR__ . '/DeboursePeriodService.php';

/** Déplace les FEB soumises avec la date de début de leur déboursé. */
final class DebourseFebPositionService
{
    public static function shiftSubmitted(PDO $pdo, int $debourseId, string $oldStart, string $newStart): void
    {
        if ($oldStart === '' || $oldStart === $newStart) return;
        $days = (int) (new DateTimeImmutable($oldStart))->diff(new DateTimeImmutable($newStart))->format('%r%a');
        $requests = $pdo->query("SELECT id,payload_json FROM employee_legal_requests WHERE request_type='feb' AND status IN ('submitted','details_requested','retained') FOR UPDATE")->fetchAll(PDO::FETCH_ASSOC);
        $linked = $pdo->prepare('SELECT 1 FROM feb_disbursement_links WHERE feb_request_id=:feb AND debourse_id=:debourse LIMIT 1');
        $detailParent = $pdo->prepare('SELECT debourse_id FROM ligne_debourse_banamur WHERE id=:id LIMIT 1');
        $sync = new FebPlanningSynchronizationService($pdo);
        foreach ($requests as $request) {
            $payload = json_decode((string) $request['payload_json'], true);
            if (!is_array($payload)) continue;
            $draft = is_array($payload['provisional_decaissement'] ?? null) ? $payload['provisional_decaissement'] : [];
            $proposal = is_array($payload['proposed_debourse'] ?? null) ? $payload['proposed_debourse'] : [];
            $detailId = (int) ($draft['ligne_debourse_id'] ?? $payload['ligne_debourse_id'] ?? 0);
            $detailDebourseId = 0;
            if ($detailId > 0) {
                $detailParent->execute(['id' => $detailId]);
                $detailDebourseId = (int) $detailParent->fetchColumn();
            }
            $linked->execute(['feb' => (int) $request['id'], 'debourse' => $debourseId]);
            $belongs = (bool) $linked->fetchColumn()
                || (int) ($draft['debourse_id'] ?? 0) === $debourseId
                || (int) ($payload['debourse_id'] ?? 0) === $debourseId
                || $detailDebourseId === $debourseId
                || (int) ($proposal['existing_debourse_id'] ?? 0) === $debourseId;
            if (!$belongs) continue;
            $position = (string) ($draft['date_decaissement_minimum'] ?? $payload['feb_position_date'] ?? $payload['planned_start_date'] ?? '');
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $position);
            if (!$date || $date->format('Y-m-d') !== $position) {
                throw new DomainException('La FEB #' . (int) $request['id'] . ' a une date de positionnement invalide.');
            }
            $target = $date->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
            $result = $sync->synchronizeDate((int) $request['id'], $target, 'Positionnement recalculé après modification du début du déboursé #' . $debourseId);
            DeboursePeriodService::assertContains($pdo, $debourseId, (string) $result['start'], (string) $result['end']);
        }
    }
}
