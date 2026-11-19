<?php
declare(strict_types=1);

final class FebFinancialJournalService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function search(array $filters): array
    {
        $from = $this->date((string)($filters['date_from'] ?? ''), date('Y-m-d'));
        $to = $this->date((string)($filters['date_to'] ?? ''), $from);
        if ($to < $from) [$from, $to] = [$to, $from];
        $params = ['start' => $from . ' 00:00:00', 'end' => (new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d 00:00:00')];
        $where = ['e.created_at>=:start', 'e.created_at<:end', 'r.request_type="feb"'];

        $this->like($where, $params, 'r.requester_name', 'submitter', $filters);
        $this->like($where, $params, 'e.event_by_name', 'validator', $filters);
        $this->like($where, $params, 'COALESCE(s.lib_service, JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,"$.departement_label")))', 'service', $filters);
        $this->like($where, $params, 'COALESCE(ch.titre, JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,"$.chantier_label")))', 'chantier', $filters);
        if (trim((string)($filters['action'] ?? '')) !== '') {
            if (trim((string)$filters['action']) === 'auto_recurring') {
                $where[] = 'e.event_type="submitted" AND JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,"$.source"))="recurring_needs"';
            } else {
                $where[] = 'e.event_type=:action';
                $params['action'] = trim((string)$filters['action']);
            }
        }
        $debourse = trim((string)($filters['debourse'] ?? $filters['debourse_id'] ?? ''));
        if ($debourse !== '') {
            $where[] = '(CAST(l.debourse_id AS CHAR) LIKE :debourse OR ld.designation LIKE :debourse)';
            $params['debourse'] = '%' . $debourse . '%';
        }
        $ligneDebourse = trim((string)($filters['ligne_debourse'] ?? $filters['ligne_debourse_id'] ?? ''));
        if ($ligneDebourse !== '') {
            $where[] = '(CAST(l.ligne_debourse_id AS CHAR) LIKE :ligne_debourse OR ldb.designation LIKE :ligne_debourse)';
            $params['ligne_debourse'] = '%' . $ligneDebourse . '%';
        }
        if (trim((string)($filters['q'] ?? '')) !== '') {
            $where[] = '(CAST(r.id AS CHAR) LIKE :q OR r.title LIKE :q OR e.event_note LIKE :q OR r.requester_name LIKE :q OR e.event_by_name LIKE :q)';
            $params['q'] = '%' . trim((string)$filters['q']) . '%';
        }

        $sql = 'SELECT e.id,e.request_id AS feb_id,e.event_type,e.event_note,e.event_by_name,e.created_at,
                       r.title AS feb_title,r.status AS feb_status,r.requester_name,r.payload_json,
                       COALESCE(s.lib_service,JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,"$.departement_label")),"") AS service_label,
                       l.chantier_id,l.devis_id,l.debourse_id,l.ligne_debourse_id,l.integration_mode,
                       ch.code AS chantier_code,ch.titre AS chantier_titre,
                       db.ligne_devis_id,COALESCE(ld.designation,"") AS debourse_label,
                       COALESCE(ldb.designation,"") AS ligne_debourse_label,
                       f.num_fiche,f.montant_fiche,f.beficiaire_fiche,f.conforme,f.approuve,f.date_decaissement_minimum
                FROM employee_legal_request_events e
                INNER JOIN employee_legal_requests r ON r.id=e.request_id
                LEFT JOIN service s ON s.id_service=r.meta_service_id
                LEFT JOIN feb_disbursement_links l ON l.feb_request_id=r.id
                LEFT JOIN chantier_banamur ch ON ch.id=COALESCE(l.chantier_id,CAST(JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,"$.chantier_id")) AS UNSIGNED))
                LEFT JOIN debourse_banamur db ON db.id=l.debourse_id
                LEFT JOIN ligne_devis_banamur ld ON ld.id=db.ligne_devis_id
                LEFT JOIN ligne_debourse_banamur ldb ON ldb.id=l.ligne_debourse_id
                LEFT JOIN fiche_decaissement f ON f.feb_request_id=r.id
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY e.created_at DESC,e.id DESC LIMIT 3000';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Les échecs transactionnels n’engendrent volontairement aucun événement FEB :
        // on les ajoute depuis l’audit, en excluant strictement les consultations de pages.
        $failure = $this->pdo->prepare(
            'SELECT a.id,CAST(a.entity_id AS UNSIGNED) AS feb_id,a.event_type,a.action,a.entity_label,
                    a.user_name AS event_by_name,a.created_at,a.details_json,
                    r.title AS feb_title,r.status AS feb_status,r.requester_name,r.payload_json
             FROM audit_trail a
             INNER JOIN employee_legal_requests r ON r.id=CAST(a.entity_id AS UNSIGNED) AND r.request_type="feb"
             WHERE a.created_at>=:start AND a.created_at<:end
               AND a.result="failure" AND a.entity_type="feb_request"
               AND a.event_type<>"page_view" AND a.action<>"page_view"
               AND (a.event_type LIKE "finance_request_%" OR a.action LIKE "validation_demandes_finances_%")'
        );
        $failure->execute(['start' => $params['start'], 'end' => $params['end']]);
        foreach (($failure->fetchAll(PDO::FETCH_ASSOC) ?: []) as $failed) {
            if (!$this->matchesFailure($failed, $filters)) continue;
            $details = json_decode((string)($failed['details_json'] ?? ''), true) ?: [];
            $rows[] = $failed + [
                'event_type' => (string)($failed['event_type'] ?: $failed['action']),
                'event_note' => (string)($details['error'] ?? 'Échec de la transaction financière.'),
                'is_failure' => 1,
            ];
        }
        usort($rows, static fn(array $a, array $b): int => strcmp((string)$b['created_at'], (string)$a['created_at']));
        foreach ($rows as &$row) {
            $row += ['is_failure' => 0];
            $row['action_label'] = self::actionLabel((string)$row['event_type']);
            $payload = json_decode((string)($row['payload_json'] ?? ''), true) ?: [];
            if ((string)$row['event_type'] === 'submitted' && (string)($payload['source'] ?? '') === 'recurring_needs') {
                $row['event_type'] = 'auto_recurring';
                $row['action_label'] = 'FEB générée automatiquement depuis un besoin récurrent';
            }
            $row['status_label'] = self::statusLabel((string)($row['feb_status'] ?? ''));
        }
        unset($row);
        return $rows;
    }

    public static function actionLabel(string $type): string
    {
        return self::actionOptions()[$type] ?? ucfirst(str_replace('_', ' ', $type));
    }

    public static function actionOptions(): array
    {
        return [
            'submitted' => 'FEB soumise',
            'auto_recurring' => 'FEB générée automatiquement depuis un besoin récurrent',
            'created' => 'FEB créée',
            'updated' => 'FEB complétée puis soumise à nouveau',
            'feb_auto_created' => 'FEB créée automatiquement (besoin récurrent)',
            'feb_updated_by_validator' => 'FEB modifiée par un validateur',
            'assigned' => 'FEB affectée à un validateur',
            'reassigned' => 'FEB réaffectée à un autre validateur',
            'feb_validation_reported' => 'FEB retournée pour complément',
            'feb_more_details_requested' => 'Précisions demandées',
            'approved' => 'FEB approuvée',
            'feb_reconciled_and_approved' => 'FEB régularisée et approuvée',
            'rejected' => 'FEB refusée',
            'cancelled' => 'FEB annulée',
            'feb_disbursement_integrated' => 'Déboursé intégré',
            'feb_linked_decaissement_created' => 'Fiche de décaissement générée',
            'feb_internal_purchase_order_generated' => 'Bon de commande interne généré',
            'feb_internal_purchase_order_gene' => 'Bon de commande interne généré',
            'finance_request_approved' => 'Approbation financière',
            'finance_request_approved_with_decaissement' => 'Approbation et génération de fiche',
            'finance_request_approve_create_decaissement_failed' => 'Échec de génération de fiche',
            'finance_request_debourse_integrated' => 'Déboursé proposé intégré séparément',
            'finance_request_debourse_integration_failed' => 'Échec d’intégration du déboursé proposé',
        ];
    }

    public static function statusLabel(string $status): string
    {
        return ['submitted'=>'En attente','details_requested'=>'Compléments demandés','approved'=>'Approuvée','rejected'=>'Refusée','cancelled'=>'Annulée'][$status] ?? ($status ?: '—');
    }

    private function date(string $value, string $fallback): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : $fallback;
    }

    private function like(array &$where, array &$params, string $column, string $key, array $filters): void
    {
        $value = trim((string)($filters[$key] ?? ''));
        if ($value === '') return;
        $where[] = $column . ' LIKE :' . $key;
        $params[$key] = '%' . $value . '%';
    }

    private function matchesFailure(array $row, array $filters): bool
    {
        if (trim((string)($filters['service'] ?? '')) !== ''
            || trim((string)($filters['chantier'] ?? '')) !== ''
            || trim((string)($filters['debourse'] ?? $filters['debourse_id'] ?? '')) !== ''
            || trim((string)($filters['ligne_debourse'] ?? $filters['ligne_debourse_id'] ?? '')) !== '') {
            return false;
        }
        foreach (['submitter' => 'requester_name', 'validator' => 'event_by_name'] as $filter => $column) {
            $needle = trim((string)($filters[$filter] ?? ''));
            if ($needle !== '' && stripos((string)($row[$column] ?? ''), $needle) === false) return false;
        }
        $action = trim((string)($filters['action'] ?? ''));
        if ($action !== '' && $action !== (string)($row['event_type'] ?? '') && $action !== (string)($row['action'] ?? '')) return false;
        $q = trim((string)($filters['q'] ?? ''));
        return $q === '' || stripos(implode(' ', [(string)($row['feb_id']??''),(string)($row['feb_title']??''),(string)($row['requester_name']??''),(string)($row['event_by_name']??'')]), $q) !== false;
    }
}
