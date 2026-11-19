<?php

require_once __DIR__ . '/../model/Database.php';

class CrmReminderRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?: \Database::getConnection();
    }

    public function hasTableColumns(string $table, array $required): bool
    {
        try {
            $cols = $this->pdo->query('SHOW COLUMNS FROM ' . $table)->fetchAll(PDO::FETCH_COLUMN, 0);
            if (!is_array($cols)) {
                return false;
            }
            foreach ($required as $column) {
                if (!in_array($column, $cols, true)) {
                    return false;
                }
            }
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function listRdvReminderCandidates(string $from, string $to, string $column): array
    {
        if (!in_array($column, ['reminder_24h_sent_at', 'reminder_1h_sent_at'], true)) {
            return [];
        }

        $sql = "SELECT
                    r.id,
                    r.titre,
                    r.date_debut,
                    r.date_fin,
                    r.statut,
                    r.created_by,
                    COALESCE(p.nom, c.nom_client) AS linked_name
                FROM crm_rdv_banamur r
                LEFT JOIN crm_prospect_banamur p ON p.id = r.prospect_id
                LEFT JOIN client_banamur c ON c.id_client = r.client_id
                WHERE r.statut IN ('Planifie', 'Planifié')
                  AND r.created_by IS NOT NULL
                  AND r.date_debut >= :from_dt
                  AND r.date_debut <= :to_dt
                  AND r.$column IS NULL
                ORDER BY r.date_debut ASC
                LIMIT 200";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['from_dt' => $from, 'to_dt' => $to]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function markRdvReminderSent(int $id, string $column): void
    {
        if ($id <= 0 || !in_array($column, ['reminder_24h_sent_at', 'reminder_1h_sent_at'], true)) {
            return;
        }
        $stmt = $this->pdo->prepare("UPDATE crm_rdv_banamur SET $column = NOW() WHERE id = :id AND $column IS NULL");
        $stmt->execute(['id' => $id]);
    }

    public function listOpportunityReminderCandidates(string $from, string $to, string $column): array
    {
        if (!in_array($column, ['reminder_24h_sent_at', 'reminder_1h_sent_at'], true)) {
            return [];
        }

        $sql = "SELECT
                    o.id,
                    o.titre,
                    o.next_action_at,
                    o.created_by,
                    COALESCE(p.nom, c.nom_client) AS linked_name
                FROM crm_opportunite_banamur o
                LEFT JOIN crm_prospect_banamur p ON p.id = o.prospect_id
                LEFT JOIN client_banamur c ON c.id_client = o.client_id
                WHERE o.created_by IS NOT NULL
                  AND o.next_action_at IS NOT NULL
                  AND o.next_action_at >= :from_dt
                  AND o.next_action_at <= :to_dt
                  AND o.$column IS NULL
                ORDER BY o.next_action_at ASC
                LIMIT 200";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['from_dt' => $from, 'to_dt' => $to]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function markOpportunityReminderSent(int $id, string $column): void
    {
        if ($id <= 0 || !in_array($column, ['reminder_24h_sent_at', 'reminder_1h_sent_at'], true)) {
            return;
        }
        $stmt = $this->pdo->prepare("UPDATE crm_opportunite_banamur SET $column = NOW() WHERE id = :id AND $column IS NULL");
        $stmt->execute(['id' => $id]);
    }
}
