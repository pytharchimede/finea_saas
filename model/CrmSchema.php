<?php

class CrmSchema
{
    public static function ensureTables(PDO $pdo): void
    {
        // Prospects
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS crm_prospect_banamur (
                id INT NOT NULL AUTO_INCREMENT,
                nom VARCHAR(200) NOT NULL,
                societe VARCHAR(200) NULL,
                telephone VARCHAR(50) NULL,
                email VARCHAR(190) NULL,
                statut VARCHAR(40) NOT NULL DEFAULT 'Nouveau',
                source VARCHAR(80) NULL,
                notes TEXT NULL,
                created_by INT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_created_at (created_at),
                KEY idx_statut (statut),
                KEY idx_created_by (created_by)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        // Colonnes optionnelles (best-effort) pour liaison à un client existant
        try {
            $columns = $pdo->query("SHOW COLUMNS FROM crm_prospect_banamur")
                ->fetchAll(PDO::FETCH_COLUMN, 0);

            $alter = [];
            if (!in_array('client_id', $columns, true)) {
                $alter[] = "ADD COLUMN client_id INT NULL";
            }
            if (!in_array('code_client', $columns, true)) {
                $alter[] = "ADD COLUMN code_client VARCHAR(80) NULL";
            }
            if (!in_array('localisation', $columns, true)) {
                $alter[] = "ADD COLUMN localisation VARCHAR(255) NULL";
            }
            if (!in_array('commune', $columns, true)) {
                $alter[] = "ADD COLUMN commune VARCHAR(255) NULL";
            }
            if (!in_array('bp', $columns, true)) {
                $alter[] = "ADD COLUMN bp VARCHAR(255) NULL";
            }
            if (!in_array('pays', $columns, true)) {
                $alter[] = "ADD COLUMN pays VARCHAR(255) NULL";
            }

            // Suivi relances (prospects inactifs)
            if (!in_array('inactivity_followup_event_id', $columns, true)) {
                $alter[] = "ADD COLUMN inactivity_followup_event_id INT NULL";
            }
            if (!in_array('inactivity_last_followup_scheduled_at', $columns, true)) {
                $alter[] = "ADD COLUMN inactivity_last_followup_scheduled_at DATETIME NULL";
            }
            if (!in_array('inactivity_last_alert_sent_at', $columns, true)) {
                $alter[] = "ADD COLUMN inactivity_last_alert_sent_at DATETIME NULL";
            }

            if ($alter) {
                $pdo->exec('ALTER TABLE crm_prospect_banamur ' . implode(', ', $alter));
            }

            // Index (best-effort)
            try {
                $pdo->exec('ALTER TABLE crm_prospect_banamur ADD INDEX idx_client_id (client_id)');
            } catch (Throwable $e) {
                // ignore si déjà existant / non supporté
            }

            try {
                $pdo->exec('ALTER TABLE crm_prospect_banamur ADD INDEX idx_inactivity_last_followup (inactivity_last_followup_scheduled_at)');
            } catch (Throwable $e) {
            }
            try {
                $pdo->exec('ALTER TABLE crm_prospect_banamur ADD INDEX idx_inactivity_last_alert (inactivity_last_alert_sent_at)');
            } catch (Throwable $e) {
            }
        } catch (Throwable $e) {
            // ignore si pas de droits ALTER
        }

        // RDV
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS crm_rdv_banamur (
                id INT NOT NULL AUTO_INCREMENT,
                linked_type VARCHAR(20) NOT NULL DEFAULT 'prospect',
                prospect_id INT NULL,
                client_id INT NULL,
                titre VARCHAR(200) NOT NULL,
                date_debut DATETIME NOT NULL,
                date_fin DATETIME NULL,
                lieu VARCHAR(160) NULL,
                statut VARCHAR(40) NOT NULL DEFAULT 'Planifié',
                description TEXT NULL,
                reminder_24h_sent_at DATETIME NULL,
                reminder_1h_sent_at DATETIME NULL,
                created_by INT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_date_debut (date_debut),
                KEY idx_linked (linked_type, prospect_id, client_id),
                KEY idx_created_by (created_by),
                KEY idx_reminder_24h (reminder_24h_sent_at),
                KEY idx_reminder_1h (reminder_1h_sent_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        // Colonnes optionnelles si table déjà existante (best-effort)
        try {
            $columns = $pdo->query("SHOW COLUMNS FROM crm_rdv_banamur")
                ->fetchAll(PDO::FETCH_COLUMN, 0);

            $alter = [];
            if (!in_array('reminder_24h_sent_at', $columns, true)) {
                $alter[] = "ADD COLUMN reminder_24h_sent_at DATETIME NULL";
            }
            if (!in_array('reminder_1h_sent_at', $columns, true)) {
                $alter[] = "ADD COLUMN reminder_1h_sent_at DATETIME NULL";
            }
            if ($alter) {
                $pdo->exec('ALTER TABLE crm_rdv_banamur ' . implode(', ', $alter));
            }

            // Index (best-effort)
            try {
                $pdo->exec('ALTER TABLE crm_rdv_banamur ADD INDEX idx_reminder_24h (reminder_24h_sent_at)');
            } catch (Throwable $e) {
                // ignore si déjà existant
            }
            try {
                $pdo->exec('ALTER TABLE crm_rdv_banamur ADD INDEX idx_reminder_1h (reminder_1h_sent_at)');
            } catch (Throwable $e) {
                // ignore si déjà existant
            }
        } catch (Throwable $e) {
            // ignore si pas de droits ALTER
        }

        // Opportunités (pipeline)
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS crm_opportunite_banamur (
                id INT NOT NULL AUTO_INCREMENT,
                linked_type VARCHAR(20) NOT NULL DEFAULT 'prospect',
                prospect_id INT NULL,
                client_id INT NULL,
                titre VARCHAR(200) NOT NULL,
                etape VARCHAR(40) NOT NULL DEFAULT 'Nouveau',
                montant_xof DECIMAL(18,0) NULL,
                probabilite TINYINT UNSIGNED NOT NULL DEFAULT 10,
                notes TEXT NULL,
                next_action_at DATETIME NULL,
                next_action_duration_min INT NULL,
                next_action_event_id INT NULL,
                reminder_24h_sent_at DATETIME NULL,
                reminder_1h_sent_at DATETIME NULL,
                created_by INT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_created_at (created_at),
                KEY idx_etape (etape),
                KEY idx_linked (linked_type, prospect_id, client_id),
                KEY idx_created_by (created_by),
                KEY idx_next_action_at (next_action_at),
                KEY idx_opp_reminder_24h (reminder_24h_sent_at),
                KEY idx_opp_reminder_1h (reminder_1h_sent_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        // Colonnes optionnelles si table déjà existante (best-effort)
        try {
            $columns = $pdo->query("SHOW COLUMNS FROM crm_opportunite_banamur")
                ->fetchAll(PDO::FETCH_COLUMN, 0);

            $alter = [];
            if (!in_array('next_action_at', $columns, true)) {
                $alter[] = "ADD COLUMN next_action_at DATETIME NULL";
            }
            if (!in_array('next_action_duration_min', $columns, true)) {
                $alter[] = "ADD COLUMN next_action_duration_min INT NULL";
            }
            if (!in_array('next_action_event_id', $columns, true)) {
                $alter[] = "ADD COLUMN next_action_event_id INT NULL";
            }
            if (!in_array('reminder_24h_sent_at', $columns, true)) {
                $alter[] = "ADD COLUMN reminder_24h_sent_at DATETIME NULL";
            }
            if (!in_array('reminder_1h_sent_at', $columns, true)) {
                $alter[] = "ADD COLUMN reminder_1h_sent_at DATETIME NULL";
            }

            if ($alter) {
                $pdo->exec('ALTER TABLE crm_opportunite_banamur ' . implode(', ', $alter));
            }

            // Index (best-effort)
            try {
                $pdo->exec('ALTER TABLE crm_opportunite_banamur ADD INDEX idx_next_action_at (next_action_at)');
            } catch (Throwable $e) {
            }
            try {
                $pdo->exec('ALTER TABLE crm_opportunite_banamur ADD INDEX idx_opp_reminder_24h (reminder_24h_sent_at)');
            } catch (Throwable $e) {
            }
            try {
                $pdo->exec('ALTER TABLE crm_opportunite_banamur ADD INDEX idx_opp_reminder_1h (reminder_1h_sent_at)');
            } catch (Throwable $e) {
            }
        } catch (Throwable $e) {
            // ignore si pas de droits ALTER
        }
    }
}
