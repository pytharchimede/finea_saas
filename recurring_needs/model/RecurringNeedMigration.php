<?php

class RecurringNeedMigration
{
    public static function migrate(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS finea_recurring_needs (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            reference VARCHAR(40) NOT NULL,
            title VARCHAR(190) NOT NULL,
            description TEXT NULL,
            requester_user_id INT UNSIGNED NOT NULL,
            requester_name VARCHAR(190) NULL,
            requester_email VARCHAR(190) NULL,
            source_feb_request_id INT UNSIGNED NULL,
            chantier_id INT UNSIGNED NULL,
            fournisseur_id INT UNSIGNED NULL,
            fournisseur_name VARCHAR(190) NULL,
            need_type ENUM('interne','externe') NOT NULL DEFAULT 'interne',
            financial_mode ENUM('avance_provisoire','decaissement_unique','assignation_logistique','sans_decaissement') NOT NULL DEFAULT 'avance_provisoire',
            amount_estimated DECIMAL(15,2) NOT NULL DEFAULT 0,
            currency VARCHAR(10) NOT NULL DEFAULT 'XOF',
            priority ENUM('basse','normale','haute','critique') NOT NULL DEFAULT 'normale',
            frequency_type ENUM('daily','weekly','monthly','quarterly','semesterly','yearly','custom') NOT NULL DEFAULT 'monthly',
            frequency_interval INT UNSIGNED NOT NULL DEFAULT 1,
            day_of_month TINYINT UNSIGNED NULL,
            day_of_week TINYINT UNSIGNED NULL,
            start_date DATE NOT NULL,
            end_date DATE NULL,
            next_due_date DATE NOT NULL,
            generate_days_before INT UNSIGNED NOT NULL DEFAULT 7,
            reminder_days_before INT UNSIGNED NOT NULL DEFAULT 10,
            max_occurrences INT UNSIGNED NULL,
            generated_count INT UNSIGNED NOT NULL DEFAULT 0,
            status ENUM('draft','pending_validation','active','suspended','expired','cancelled') NOT NULL DEFAULT 'pending_validation',
            feb_template_json LONGTEXT NULL,
            last_generated_at DATETIME NULL,
            validated_by INT UNSIGNED NULL,
            validated_at DATETIME NULL,
            suspended_by INT UNSIGNED NULL,
            suspended_at DATETIME NULL,
            suspension_reason VARCHAR(255) NULL,
            created_by INT UNSIGNED NOT NULL,
            updated_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_finea_recurring_needs_reference (reference),
            KEY idx_finea_recurring_needs_status_due (status, next_due_date),
            KEY idx_finea_recurring_needs_requester (requester_user_id),
            KEY idx_finea_recurring_needs_created_by (created_by),
            KEY idx_finea_recurring_needs_fournisseur (fournisseur_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");


        self::ensureColumn($pdo, 'finea_recurring_needs', 'requester_email', "ALTER TABLE finea_recurring_needs ADD requester_email VARCHAR(190) NULL AFTER requester_name");
        self::ensureColumn($pdo, 'finea_recurring_needs', 'source_feb_request_id', "ALTER TABLE finea_recurring_needs ADD source_feb_request_id INT UNSIGNED NULL AFTER requester_email");
        self::ensureIndex($pdo, 'finea_recurring_needs', 'uq_finea_recurring_needs_source_feb', "ALTER TABLE finea_recurring_needs ADD UNIQUE INDEX uq_finea_recurring_needs_source_feb (source_feb_request_id)");
        self::ensureColumn($pdo, 'finea_recurring_needs', 'departement_id', "ALTER TABLE finea_recurring_needs ADD departement_id INT UNSIGNED NULL AFTER requester_email");
        self::ensureIndex($pdo, 'finea_recurring_needs', 'idx_finea_recurring_needs_fournisseur', "ALTER TABLE finea_recurring_needs ADD INDEX idx_finea_recurring_needs_fournisseur (fournisseur_id)");
        self::ensureColumn($pdo, 'finea_recurring_needs', 'departement_label', "ALTER TABLE finea_recurring_needs ADD departement_label VARCHAR(190) NULL AFTER departement_id");

        $pdo->exec("CREATE TABLE IF NOT EXISTS finea_recurring_need_generations (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            recurring_need_id INT UNSIGNED NOT NULL,
            scheduled_due_date DATE NOT NULL,
            generation_date DATE NOT NULL,
            generated_feb_id INT UNSIGNED NULL,
            generated_decaissement_id INT UNSIGNED NULL,
            status ENUM('pending','generated','skipped','failed') NOT NULL DEFAULT 'pending',
            message VARCHAR(255) NULL,
            payload_json LONGTEXT NULL,
            generated_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_recurring_generation_once (recurring_need_id, scheduled_due_date),
            KEY idx_recurring_generation_status (status, generation_date),
            CONSTRAINT fk_recurring_generation_need FOREIGN KEY (recurring_need_id) REFERENCES finea_recurring_needs(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS finea_recurring_need_notifications (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            recurring_need_id INT UNSIGNED NOT NULL,
            generation_id INT UNSIGNED NULL,
            user_id INT UNSIGNED NULL,
            channel ENUM('system','mail') NOT NULL DEFAULT 'system',
            event_type VARCHAR(80) NOT NULL,
            title VARCHAR(190) NOT NULL,
            message TEXT NULL,
            sent_at DATETIME NULL,
            read_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_recurring_notifications_user (user_id, read_at),
            KEY idx_recurring_notifications_need (recurring_need_id),
            CONSTRAINT fk_recurring_notification_need FOREIGN KEY (recurring_need_id) REFERENCES finea_recurring_needs(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    private static function ensureIndex(PDO $pdo, string $table, string $index, string $alterSql): void
    {
        try {
            $stmt = $pdo->prepare("SHOW INDEX FROM `{$table}` WHERE Key_name = :index_name");
            $stmt->execute(['index_name' => $index]);
            if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                $pdo->exec($alterSql);
            }
        } catch (Throwable $e) {
            // Ne bloque pas le module si l'utilisateur SQL n'a pas SHOW INDEX.
        }
    }

    private static function ensureColumn(PDO $pdo, string $table, string $column, string $alterSql): void
    {
        try {
            $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE :column");
            $stmt->execute(['column' => $column]);
            if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                $pdo->exec($alterSql);
            }
        } catch (Throwable $e) {
            // Ne bloque pas le module si l'utilisateur SQL n'a pas SHOW COLUMNS.
        }
    }
}
