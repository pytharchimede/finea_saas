<?php

require_once __DIR__ . '/../model/Database.php';

class CrmProspectFollowupRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?: \Database::getConnection();
    }

    public function hasProspectFollowupColumns(): bool
    {
        try {
            $cols = $this->pdo->query('SHOW COLUMNS FROM crm_prospect_banamur')->fetchAll(PDO::FETCH_COLUMN, 0);
            foreach (['id', 'nom', 'statut', 'created_by', 'created_at', 'updated_at', 'inactivity_last_alert_sent_at', 'inactivity_last_followup_scheduled_at'] as $column) {
                if (!in_array($column, $cols, true)) {
                    return false;
                }
            }
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function listProspectCandidates(): array
    {
        $sql = "SELECT
                p.id,
                p.nom,
                p.societe,
                p.statut,
                p.created_by,
                COALESCE(p.updated_at, p.created_at) AS prospect_activity,
                (SELECT MAX(r.date_debut) FROM crm_rdv_banamur r WHERE r.prospect_id = p.id) AS last_rdv_at,
                (SELECT MAX(o.updated_at) FROM crm_opportunite_banamur o WHERE o.prospect_id = p.id) AS last_opp_at,
                p.inactivity_followup_event_id,
                p.inactivity_last_followup_scheduled_at,
                p.inactivity_last_alert_sent_at
            FROM crm_prospect_banamur p
            WHERE p.created_by IS NOT NULL
              AND p.statut NOT IN ('Gagne', 'Gagné', 'Perdu')
            ORDER BY COALESCE(p.updated_at, p.created_at) ASC
            LIMIT 300";

        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getUserMailAndPersonnel(int $userId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT personnel_id, mail_pro FROM user_devis_banamur WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function markAlertSent(int $prospectId): void
    {
        $stmt = $this->pdo->prepare('UPDATE crm_prospect_banamur SET inactivity_last_alert_sent_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => $prospectId]);
    }

    public function markFollowupScheduled(int $prospectId, ?int $eventId = null): void
    {
        if ($eventId !== null) {
            $stmt = $this->pdo->prepare('UPDATE crm_prospect_banamur SET inactivity_last_followup_scheduled_at = NOW(), inactivity_followup_event_id = :eid WHERE id = :id');
            $stmt->execute(['id' => $prospectId, 'eid' => $eventId]);
            return;
        }

        $stmt = $this->pdo->prepare('UPDATE crm_prospect_banamur SET inactivity_last_followup_scheduled_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => $prospectId]);
    }
}
