<?php
require_once __DIR__ . '/FebCategoryMigrationService.php';

final class FebWorkflowMigrationService
{
    private const SINGLE_PLANNING_MIGRATION = 'feb_single_planning_20260719_v1';

    public static function ensure(PDO $pdo): void
    {
        self::column($pdo, 'fiche_decaissement', 'planned_start_date', 'DATE NULL AFTER date_creat_fiche');
        self::column($pdo, 'fiche_decaissement', 'planned_end_date', 'DATE NULL AFTER planned_start_date');
        self::column($pdo, 'fiche_decaissement', 'feb_request_id', 'INT NULL AFTER code_autorisation_feb');
        self::column($pdo, 'fiche_decaissement', 'date_decaissement_minimum', 'DATE NULL');
        self::column($pdo, 'fiche_decaissement', 'date_conforme', 'DATETIME NULL');
        self::column($pdo, 'fiche_decaissement', 'secur_conforme', 'VARCHAR(100) NULL');
        self::index($pdo, 'fiche_decaissement', 'idx_fiche_feb_request', 'feb_request_id');

        // Ce lien rend la transformation d'une FEB non prévue idempotente.
        self::column($pdo, 'debourse_banamur', 'feb_request_id', 'INT NULL AFTER ligne_devis_id');
        self::index($pdo, 'debourse_banamur', 'idx_debourse_feb_request', 'feb_request_id');

        $pdo->exec('CREATE TABLE IF NOT EXISTS feb_disbursement_links (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            feb_request_id INT NOT NULL,
            chantier_id INT NOT NULL,
            devis_id INT NOT NULL,
            devis_type VARCHAR(16) NOT NULL DEFAULT "UNCLASSIFIED",
            ligne_devis_id INT NOT NULL,
            debourse_id INT NOT NULL,
            ligne_debourse_id INT NULL,
            integration_mode VARCHAR(24) NOT NULL,
            integrated_by_user_id INT NULL,
            integrated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_feb_disbursement_request (feb_request_id),
            KEY idx_feb_disbursement_debourse (debourse_id),
            KEY idx_feb_disbursement_devis (devis_id, ligne_devis_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $pdo->exec('CREATE TABLE IF NOT EXISTS feb_payload_revision_history (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            feb_request_id INT NOT NULL,
            old_payload_json LONGTEXT NOT NULL,
            new_payload_json LONGTEXT NOT NULL,
            change_summary TEXT NULL,
            changed_by_user_id INT NULL,
            changed_by_name VARCHAR(150) NULL,
            changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_feb_revision_request (feb_request_id, changed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $eventTypeLength = $pdo->query('SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="employee_legal_request_events" AND COLUMN_NAME="event_type" LIMIT 1')->fetchColumn();
        if ($eventTypeLength !== false && (int)$eventTypeLength < 64) {
            $pdo->exec('ALTER TABLE employee_legal_request_events MODIFY event_type VARCHAR(64) NOT NULL');
        }
        FebCategoryMigrationService::ensure($pdo);
        self::migrateToSinglePlanning($pdo);
    }

    private static function migrateToSinglePlanning(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS app_migration_versions (migration_key VARCHAR(120) PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $check = $pdo->prepare('SELECT 1 FROM app_migration_versions WHERE migration_key=:key LIMIT 1');
        $check->execute(['key' => self::SINGLE_PLANNING_MIGRATION]);
        if ($check->fetchColumn()) return;

        $rows = $pdo->query('SELECT id,payload_json FROM employee_legal_requests WHERE request_type="feb"')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $update = $pdo->prepare('UPDATE employee_legal_requests SET payload_json=:payload WHERE id=:id');
        foreach ($rows as $row) {
            $payload = json_decode((string)$row['payload_json'], true);
            if (!is_array($payload)) continue;
            $normalized = FebCategoryMigrationService::normalizePayload($payload);
            if ($normalized !== $payload) {
                $update->execute([
                    'payload' => json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'id' => (int)$row['id'],
                ]);
            }
        }

        $table = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="feb_request_lines"');
        $table->execute();
        if ((int)$table->fetchColumn() > 0) {
            foreach (['planned_start_date', 'planned_end_date'] as $column) {
                $exists = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="feb_request_lines" AND COLUMN_NAME=:column');
                $exists->execute(['column' => $column]);
                if ((int)$exists->fetchColumn() > 0) {
                    $pdo->exec('ALTER TABLE feb_request_lines DROP COLUMN `' . $column . '`');
                }
            }
        }
        $pdo->prepare('INSERT IGNORE INTO app_migration_versions(migration_key) VALUES(:key)')->execute(['key' => self::SINGLE_PLANNING_MIGRATION]);
    }

    private static function column(PDO $pdo, string $table, string $column, string $definition): void
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column');
        $stmt->execute(['table' => $table, 'column' => $column]);
        if ((int) $stmt->fetchColumn() === 0) {
            try {
                $pdo->exec(sprintf('ALTER TABLE `%s` ADD COLUMN `%s` %s', $table, $column, $definition));
            } catch (PDOException $e) {
                // Deux premières requêtes après déploiement peuvent migrer en parallèle.
                $stmt->execute(['table' => $table, 'column' => $column]);
                if ((int) $stmt->fetchColumn() === 0) {
                    throw $e;
                }
            }
        }
    }

    private static function index(PDO $pdo, string $table, string $index, string $columns): void
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND INDEX_NAME = :index');
        $stmt->execute(['table' => $table, 'index' => $index]);
        if ((int) $stmt->fetchColumn() === 0) {
            try {
                $pdo->exec(sprintf('CREATE INDEX `%s` ON `%s` (%s)', $index, $table, $columns));
            } catch (PDOException $e) {
                $stmt->execute(['table' => $table, 'index' => $index]);
                if ((int) $stmt->fetchColumn() === 0) {
                    throw $e;
                }
            }
        }
    }
}
