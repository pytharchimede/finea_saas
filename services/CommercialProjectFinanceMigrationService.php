<?php
declare(strict_types=1);

final class CommercialProjectFinanceMigrationService
{
    private static bool $done = false;

    public function __construct(private PDO $pdo) {}

    public function ensureSchema(): void
    {
        if (self::$done) {
            return;
        }
        if ($this->schemaReady()) {
            self::$done = true;
            return;
        }
        $statements = [
            "CREATE TABLE IF NOT EXISTS client_purchase_orders (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, order_number VARCHAR(120) NOT NULL,
                offre_id INT NOT NULL, devis_id INT NOT NULL, client_id INT NOT NULL,
                amount DECIMAL(20,2) NOT NULL, quote_amount_snapshot DECIMAL(20,2) NOT NULL,
                amount_difference DECIMAL(20,2) NOT NULL DEFAULT 0, currency CHAR(3) NOT NULL DEFAULT 'XOF',
                order_date DATE NULL, received_at DATETIME NULL, expected_start_date DATE NULL,
                document_path VARCHAR(500) NULL, status VARCHAR(32) NOT NULL DEFAULT 'ACTIVE',
                migration_note TEXT NULL, created_by_user_id INT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY(id), UNIQUE KEY uq_client_po_number(order_number),
                KEY idx_client_po_offer(offre_id), KEY idx_client_po_devis(devis_id),
                KEY idx_client_po_client(client_id), KEY idx_client_po_status(status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS chantier_client_purchase_orders (
                chantier_id INT NOT NULL, client_purchase_order_id BIGINT UNSIGNED NOT NULL,
                linked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, linked_by_user_id INT NULL,
                PRIMARY KEY(chantier_id), UNIQUE KEY uq_chantier_client_po(client_purchase_order_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS devis_archives (
                devis_id INT NOT NULL, archived_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                archived_by_user_id INT NULL, archive_reason VARCHAR(500) NOT NULL,
                eligibility_snapshot_json LONGTEXT NOT NULL, restored_at DATETIME NULL,
                restored_by_user_id INT NULL, restore_note VARCHAR(500) NULL,
                PRIMARY KEY(devis_id), KEY idx_devis_archives_active(restored_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS treasury_invoice_collection_cancellations (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, invoice_id BIGINT UNSIGNED NOT NULL,
                reason VARCHAR(500) NOT NULL, cancelled_by_user_id INT NULL,
                cancelled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY(id), UNIQUE KEY uq_invoice(invoice_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS treasury_opening_balances (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, effective_date DATE NOT NULL,
                amount DECIMAL(20,2) NOT NULL, currency CHAR(3) NOT NULL DEFAULT 'XOF',
                note VARCHAR(500) NULL, created_by_user_id INT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(id),
                UNIQUE KEY uq_treasury_opening_currency_date(currency,effective_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS treasury_loans (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                lender_name VARCHAR(190) NOT NULL, reference VARCHAR(120) NULL,
                principal_amount DECIMAL(20,2) NOT NULL, received_date DATE NOT NULL,
                due_date DATE NULL, interest_rate DECIMAL(8,4) NOT NULL DEFAULT 0,
                repaid_amount DECIMAL(20,2) NOT NULL DEFAULT 0,
                status ENUM('active','repaid','cancelled') NOT NULL DEFAULT 'active',
                note VARCHAR(500) NULL, created_by_user_id INT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY(id), KEY idx_treasury_loan_dates(received_date,due_date),
                KEY idx_treasury_loan_status(status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS treasury_vat_remittances (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, period_start DATE NOT NULL, period_end DATE NOT NULL,
                payment_date DATE NOT NULL, amount DECIMAL(20,2) NOT NULL, reference VARCHAR(120) NULL,
                document_path VARCHAR(500) NULL, note VARCHAR(500) NULL, created_by_user_id INT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(id),
                KEY idx_vat_remittance_payment(payment_date), KEY idx_vat_remittance_period(period_start,period_end)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS treasury_fixed_charges (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, label VARCHAR(190) NOT NULL,
                category VARCHAR(100) NULL, amount DECIMAL(20,2) NOT NULL, due_date DATE NOT NULL,
                payment_date DATE NULL, status ENUM('planned','paid','cancelled') NOT NULL DEFAULT 'planned',
                supplier_name VARCHAR(190) NULL, reference VARCHAR(120) NULL, document_path VARCHAR(500) NULL,
                note VARCHAR(500) NULL, created_by_user_id INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY(id), KEY idx_fixed_charge_due(due_date,status), KEY idx_fixed_charge_paid(payment_date,status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];
        foreach ($statements as $sql) {
            $this->pdo->exec($sql);
        }
        $this->addColumn('treasury_loans', 'loan_type', "ENUM('external','vat_credit') NOT NULL DEFAULT 'external' AFTER lender_name");
        $this->addColumn('treasury_loans', 'vat_source_amount', 'DECIMAL(20,2) NULL AFTER loan_type');
        $this->addColumn('treasury_loans', 'vat_repayment_due_date', 'DATE NULL AFTER due_date');
        $this->addColumn('treasury_loans', 'vat_repayment_status', "ENUM('pending','repaid','cancelled') NULL AFTER vat_repayment_due_date");
        $this->addColumn('treasury_loans', 'vat_repaid_at', 'DATETIME NULL AFTER vat_repayment_status');
        $this->addColumn('treasury_loans', 'vat_repaid_by', 'INT NULL AFTER vat_repaid_at');
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS treasury_vat_invoice_snapshots (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            invoice_id BIGINT UNSIGNED NOT NULL,
            snapshot_date DATE NOT NULL,
            amount_ht DECIMAL(20,2) NOT NULL DEFAULT 0,
            amount_vat DECIMAL(20,2) NOT NULL DEFAULT 0,
            amount_ttc DECIMAL(20,2) NOT NULL DEFAULT 0,
            collected_amount DECIMAL(20,2) NOT NULL DEFAULT 0,
            tax_not_invoiced TINYINT(1) NOT NULL DEFAULT 0,
            source_items_json LONGTEXT NULL,
            created_by_user_id INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            UNIQUE KEY uq_vat_invoice_snapshot(invoice_id),
            KEY idx_vat_snapshot_date(snapshot_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS treasury_vat_fund_allocations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            transfer_id BIGINT UNSIGNED NULL,
            allocation_type ENUM('to_available','to_real_entries','remittance','loan_release','loan_repayment') NOT NULL,
            amount DECIMAL(20,2) NOT NULL DEFAULT 0,
            allocation_date DATE NOT NULL,
            reference VARCHAR(120) NULL,
            note VARCHAR(500) NULL,
            created_by_user_id INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            UNIQUE KEY uq_vat_transfer_allocation(transfer_id,allocation_type),
            KEY idx_vat_allocation_date(allocation_date),
            KEY idx_vat_allocation_type(allocation_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS treasury_vat_loan_deferrals (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            loan_id BIGINT UNSIGNED NOT NULL,
            previous_due_date DATE NOT NULL,
            new_due_date DATE NOT NULL,
            reason VARCHAR(500) NOT NULL,
            created_by_user_id INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            KEY idx_vat_deferral_loan(loan_id),
            KEY idx_vat_deferral_new_due(new_due_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        if ($this->tableExists('facture_vente_banamur')) {
            $this->addColumn('facture_vente_banamur', 'client_purchase_order_id', 'BIGINT UNSIGNED NULL');
            $this->addColumn('facture_vente_banamur', 'expected_collection_date', 'DATE NULL');
            $this->addColumn('facture_vente_banamur', 'collected_amount', 'DECIMAL(20,2) NOT NULL DEFAULT 0');
        }
        if ($this->tableExists('devis_banamur')) {
            $this->addColumn('devis_banamur', 'masked_at', 'DATETIME NULL AFTER masque');
            $this->addColumn('devis_banamur', 'masked_by_user_id', 'INT NULL AFTER masked_at');
        }
        if ($this->tableExists('feb_request_lines')) {
            $this->addColumn('feb_request_lines', 'planned_start_date', 'DATE NULL');
            $this->addColumn('feb_request_lines', 'planned_end_date', 'DATE NULL');
        }
        if ($this->tableExists('chantier_banamur') && $this->tableExists('devis_banamur')) {
            $this->backfillLegacyChantiers();
        }
        self::$done = true;
    }

    private function backfillLegacyChantiers(): void
    {
        $this->pdo->exec(
            "INSERT IGNORE INTO client_purchase_orders
                (order_number, offre_id, devis_id, client_id, amount, quote_amount_snapshot,
                 amount_difference, order_date, received_at, status, migration_note)
             SELECT CONCAT('LEGACY-CH-', c.id), COALESCE(d.offre_id,0), COALESCE(d.id,0), c.client_id,
                    COALESCE(c.montant_devis, d.total_ttc, 0), COALESCE(d.total_ttc,c.montant_devis,0),
                    COALESCE(c.montant_devis, d.total_ttc, 0) - COALESCE(d.total_ttc,c.montant_devis,0),
                    DATE(c.date_creation), c.date_creation, 'LEGACY_INCOMPLETE',
                    CASE WHEN d.id IS NULL
                         THEN 'Reprise automatique : devis historique introuvable et document BC client à compléter.'
                         ELSE 'Reprise automatique : document BC client à compléter.' END
             FROM chantier_banamur c
             LEFT JOIN devis_banamur d ON d.id = c.devis_id
             LEFT JOIN chantier_client_purchase_orders link ON link.chantier_id = c.id
             WHERE link.chantier_id IS NULL"
        );
        $this->pdo->exec(
            "INSERT IGNORE INTO chantier_client_purchase_orders (chantier_id, client_purchase_order_id)
             SELECT c.id, po.id FROM chantier_banamur c
             JOIN client_purchase_orders po ON po.order_number = CONCAT('LEGACY-CH-', c.id)"
        );
    }

    private function addColumn(string $table, string $column, string $definition): void
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table_name AND COLUMN_NAME=:column_name'
        );
        $statement->execute(['table_name' => $table, 'column_name' => $column]);
        if ((int) $statement->fetchColumn() === 0) {
            $this->pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        }
    }

    private function tableExists(string $table): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table_name'
        );
        $statement->execute(['table_name' => $table]);
        return (int) $statement->fetchColumn() > 0;
    }

    private function schemaReady(): bool
    {
        foreach (['client_purchase_orders', 'chantier_client_purchase_orders', 'devis_archives', 'treasury_opening_balances', 'treasury_loans', 'treasury_vat_remittances', 'treasury_vat_invoice_snapshots', 'treasury_vat_fund_allocations', 'treasury_vat_loan_deferrals', 'treasury_fixed_charges'] as $table) {
            if (!$this->tableExists($table)) {
                return false;
            }
        }
        foreach ([
            ['treasury_loans', 'loan_type'],
            ['treasury_loans', 'vat_source_amount'],
            ['treasury_loans', 'vat_repayment_due_date'],
            ['treasury_loans', 'vat_repayment_status'],
            ['treasury_loans', 'vat_repaid_at'],
            ['treasury_loans', 'vat_repaid_by'],
            ['facture_vente_banamur', 'client_purchase_order_id'],
            ['facture_vente_banamur', 'expected_collection_date'],
            ['facture_vente_banamur', 'collected_amount'],
            ['feb_request_lines', 'planned_start_date'],
            ['feb_request_lines', 'planned_end_date'],
        ] as [$table, $column]) {
            if ($this->tableExists($table) && !$this->columnExists($table, $column)) {
                return false;
            }
        }
        if ($this->tableExists('chantier_banamur')) {
            $missing = (int) $this->pdo->query(
                'SELECT COUNT(*) FROM chantier_banamur c
                 LEFT JOIN chantier_client_purchase_orders l ON l.chantier_id=c.id
                 WHERE l.chantier_id IS NULL'
            )->fetchColumn();
            if ($missing > 0) {
                return false;
            }
        }
        return true;
    }

    private function columnExists(string $table, string $column): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table_name AND COLUMN_NAME=:column_name'
        );
        $statement->execute(['table_name' => $table, 'column_name' => $column]);
        return (int) $statement->fetchColumn() > 0;
    }
}
