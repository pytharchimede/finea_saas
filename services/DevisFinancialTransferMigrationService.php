<?php
declare(strict_types=1);

final class DevisFinancialTransferMigrationService
{
    public function __construct(private PDO $pdo) {}

    public function ensureSchema(): void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS devis_financial_transfers (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            source_devis_id INT NOT NULL,
            target_devis_id INT NOT NULL,
            status ENUM('started','completed','failed') NOT NULL DEFAULT 'started',
            reason VARCHAR(500) NOT NULL,
            mapping_json LONGTEXT NULL,
            snapshot_json LONGTEXT NULL,
            result_json LONGTEXT NULL,
            created_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY idx_dft_source (source_devis_id,status),
            KEY idx_dft_target (target_devis_id,status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $tables = $this->pdo->query("SELECT DISTINCT t.TABLE_NAME
            FROM information_schema.TABLES t
            LEFT JOIN information_schema.COLUMNS c
              ON c.TABLE_SCHEMA=t.TABLE_SCHEMA AND c.TABLE_NAME=t.TABLE_NAME
            WHERE t.TABLE_SCHEMA=DATABASE() AND t.TABLE_TYPE='BASE TABLE'
              AND t.ENGINE<>'InnoDB'
              AND (t.TABLE_NAME IN ('devis_banamur','ligne_devis_banamur','debourse_banamur','ligne_debourse_banamur','chantier_banamur')
                   OR c.COLUMN_NAME IN ('devis_id','ligne_devis_id','debourse_id','ligne_debourse_id'))")
            ->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach ($tables as $table) {
            if (preg_match('/^[a-z0-9_]+$/i', (string) $table)) {
                $this->pdo->exec("ALTER TABLE `$table` ENGINE=InnoDB");
            }
        }
    }
}
