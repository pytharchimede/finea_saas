<?php

require_once __DIR__ . '/../model/Database.php';

class CrmDashboardRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?: \Database::getConnection();
    }

    public function listScheduleEvents(int $personnelId, string $from, string $to, int $limit = 50): array
    {
        if ($personnelId <= 0) {
            return [];
        }

        $limit = max(1, min(200, $limit));
        $stmt = $this->pdo->prepare(
            "SELECT id, title, start_at, end_at, status_code, location
             FROM employee_schedule_event
             WHERE personnel_id = :pid
               AND start_at >= :s
               AND start_at <= :e
             ORDER BY start_at ASC
             LIMIT {$limit}"
        );
        $stmt->execute(['pid' => $personnelId, 's' => $from, 'e' => $to]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function listUserNextActions(int $userId, string $from, string $to, int $limit = 50): array
    {
        if ($userId <= 0) {
            return [];
        }

        $limit = max(1, min(200, $limit));
        $stmt = $this->pdo->prepare(
            "SELECT o.id, o.titre, o.next_action_at, COALESCE(p.nom, c.nom_client) AS linked_name
             FROM crm_opportunite_banamur o
             LEFT JOIN crm_prospect_banamur p ON p.id = o.prospect_id
             LEFT JOIN client_banamur c ON c.id_client = o.client_id
             WHERE o.created_by = :uid
               AND o.next_action_at IS NOT NULL
               AND o.next_action_at >= :s
               AND o.next_action_at <= :e
             ORDER BY o.next_action_at ASC
             LIMIT {$limit}"
        );
        $stmt->execute(['uid' => $userId, 's' => $from, 'e' => $to]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function listInactiveProspectsForUser(int $userId, int $limit = 8): array
    {
        if ($userId <= 0) {
            return [];
        }

        $limit = max(1, min(100, $limit));
        $stmt = $this->pdo->prepare(
            "SELECT id, nom, societe, inactivity_last_followup_scheduled_at, inactivity_last_alert_sent_at
             FROM crm_prospect_banamur
             WHERE created_by = :uid
               AND (inactivity_last_followup_scheduled_at IS NOT NULL OR inactivity_last_alert_sent_at IS NOT NULL)
             ORDER BY COALESCE(inactivity_last_followup_scheduled_at, inactivity_last_alert_sent_at) DESC
             LIMIT {$limit}"
        );
        $stmt->execute(['uid' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
