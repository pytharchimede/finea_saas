<?php
declare(strict_types=1);
require_once __DIR__.'/ClientBusinessPolicy.php';
final class DevisOfferProjectMigrationService
{
    private static bool $done = false;

    public function __construct(private PDO $pdo) {}

    public function ensureSchema(): void
    {
        if (self::$done) {
            return;
        }
        require_once __DIR__ . '/CommercialProjectFinanceMigrationService.php';
        (new CommercialProjectFinanceMigrationService($this->pdo))->ensureSchema();
        require_once __DIR__ . '/ProjectNumberingService.php';
        (new ProjectNumberingService($this->pdo))->ensureAndMigrate();

        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS devis_offer_project_settings (
                offre_id INT NOT NULL,
                principal_devis_id INT NULL,
                updated_by_user_id INT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (offre_id),
                UNIQUE KEY uq_offer_principal_devis (principal_devis_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS devis_offer_classifications (
                devis_id INT NOT NULL,
                offre_id INT NOT NULL,
                devis_type VARCHAR(16) NOT NULL,
                principal_offer_id INT GENERATED ALWAYS AS (
                    CASE WHEN devis_type = 'PRINCIPAL' THEN offre_id ELSE NULL END
                ) STORED,
                classified_by_user_id INT NOT NULL,
                classified_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (devis_id),
                UNIQUE KEY uq_one_principal_per_offer (principal_offer_id),
                KEY idx_devis_offer_classification (offre_id, devis_type),
                CONSTRAINT chk_devis_offer_type CHECK (devis_type IN ('PRINCIPAL', 'TS'))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS devis_chantier_links (
            devis_id INT NOT NULL PRIMARY KEY,
            chantier_id INT NOT NULL,
            link_type VARCHAR(16) NOT NULL DEFAULT 'TS',
            linked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_devis_chantier_links_chantier (chantier_id,link_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // Reconstitue d'abord le devis principal depuis les classements historiques.
        $this->pdo->exec("INSERT INTO devis_offer_project_settings(offre_id,principal_devis_id)
            SELECT offre_id,MAX(devis_id) FROM devis_offer_classifications
            WHERE devis_type='PRINCIPAL' GROUP BY offre_id
            ON DUPLICATE KEY UPDATE principal_devis_id=COALESCE(principal_devis_id,VALUES(principal_devis_id))");

        // Rattache tous les TS historiques au chantier de leur devis principal.
        $this->pdo->exec("INSERT INTO devis_chantier_links(devis_id,chantier_id,link_type)
            SELECT ts.devis_id,MAX(c.id),'TS' FROM devis_offer_classifications ts
            INNER JOIN devis_offer_project_settings s ON s.offre_id=ts.offre_id
            INNER JOIN chantier_banamur c ON c.devis_id=s.principal_devis_id AND c.archived_at IS NULL
            WHERE ts.devis_type='TS' GROUP BY ts.devis_id
            ON DUPLICATE KEY UPDATE chantier_id=VALUES(chantier_id),link_type='TS'");

        // Repli pour les très anciens dossiers sans classement PRINCIPAL : tout TS
        // encore non lié rejoint l'unique chantier actif porté par la même offre.
        $this->pdo->exec("INSERT IGNORE INTO devis_chantier_links(devis_id,chantier_id,link_type)
            SELECT ts.devis_id,MAX(c.id),'TS' FROM devis_offer_classifications ts
            INNER JOIN devis_banamur base_quote ON base_quote.offre_id=ts.offre_id AND base_quote.id<>ts.devis_id
            INNER JOIN chantier_banamur c ON c.devis_id=base_quote.id AND c.archived_at IS NULL
            LEFT JOIN devis_chantier_links existing_link ON existing_link.devis_id=ts.devis_id
            WHERE ts.devis_type='TS' AND existing_link.devis_id IS NULL
            GROUP BY ts.devis_id");

        // A previous deployment may already have created the table without the
        // database-level single-principal guard. Upgrade it in place, additively.
        if (!$this->columnExists('devis_offer_classifications', 'principal_offer_id')) {
            $this->assertNoDuplicatePrincipals();
            $this->pdo->exec(
                "ALTER TABLE devis_offer_classifications
                 ADD COLUMN principal_offer_id INT GENERATED ALWAYS AS (
                    CASE WHEN devis_type = 'PRINCIPAL' THEN offre_id ELSE NULL END
                 ) STORED"
            );
        }
        if (!$this->indexExists('devis_offer_classifications', 'uq_one_principal_per_offer')) {
            $this->assertNoDuplicatePrincipals();
            $this->pdo->exec(
                'ALTER TABLE devis_offer_classifications
                 ADD UNIQUE KEY uq_one_principal_per_offer (principal_offer_id)'
            );
        }
        if (!$this->indexExists('devis_offer_project_settings', 'uq_offer_principal_devis')) {
            $this->pdo->exec(
                'ALTER TABLE devis_offer_project_settings
                 ADD UNIQUE KEY uq_offer_principal_devis (principal_devis_id)'
            );
        }

        if (!$this->columnExists('user_devis_banamur', 'modifier_classement_devis')) {
            $this->pdo->exec(
                'ALTER TABLE user_devis_banamur
                 ADD COLUMN modifier_classement_devis TINYINT(1) NOT NULL DEFAULT 0'
            );
        }

        if (!$this->columnExists('offre_banamur', 'client_id')) {
            $this->pdo->exec('ALTER TABLE offre_banamur ADD COLUMN client_id INT NULL AFTER commercial_dedie');
        }
        if (!$this->indexExists('offre_banamur', 'idx_offre_client')) {
            $this->pdo->exec('ALTER TABLE offre_banamur ADD KEY idx_offre_client (client_id)');
        }
        $this->backfillProjectClients();

        self::$done = true;
    }

    private function columnExists(string $table, string $column): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name'
        );
        $statement->execute(['table_name' => $table, 'column_name' => $column]);
        return (int) $statement->fetchColumn() > 0;
    }

    private function indexExists(string $table, string $index): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND INDEX_NAME = :index_name'
        );
        $statement->execute(['table_name' => $table, 'index_name' => $index]);
        return (int) $statement->fetchColumn() > 0;
    }

    private function assertNoDuplicatePrincipals(): void
    {
        $duplicate = $this->pdo->query(
            "SELECT offre_id
             FROM devis_offer_classifications
             WHERE devis_type = 'PRINCIPAL'
             GROUP BY offre_id
             HAVING COUNT(*) > 1
             LIMIT 1"
        )->fetchColumn();
        if ($duplicate !== false) {
            throw new RuntimeException(
                'Migration interrompue : plusieurs devis principaux existent pour le projet #' . $duplicate . '.'
            );
        }
    }

    private function backfillProjectClients(): void
    {
        try {
            require_once __DIR__.'/ClientIdentityMigration.php';
            (new ClientIdentityMigration($this->pdo))->install();
            $this->pdo->exec("UPDATE offre_banamur o
            SET o.client_id = COALESCE(
                (SELECT d.client_id FROM devis_banamur d
                 INNER JOIN devis_offer_classifications dc ON dc.devis_id=d.id AND dc.devis_type='PRINCIPAL'
                 INNER JOIN client_banamur cl ON cl.id_client=d.client_id AND ".ClientBusinessPolicy::sqlUsable('cl')."
                 WHERE d.offre_id=o.id_offre AND d.client_id IS NOT NULL AND d.client_id>0
                 ORDER BY d.id DESC LIMIT 1),
                (SELECT d.client_id FROM devis_banamur d
                 INNER JOIN client_banamur cl ON cl.id_client=d.client_id AND ".ClientBusinessPolicy::sqlUsable('cl')."
                 WHERE d.offre_id=o.id_offre AND d.client_id IS NOT NULL AND d.client_id>0
                 ORDER BY d.id DESC LIMIT 1),
                (SELECT c.client_id FROM chantier_banamur c
                 INNER JOIN devis_banamur d ON d.id=c.devis_id
                 INNER JOIN client_banamur cl ON cl.id_client=c.client_id AND ".ClientBusinessPolicy::sqlUsable('cl')."
                 WHERE d.offre_id=o.id_offre AND c.client_id IS NOT NULL AND c.client_id>0
                 ORDER BY c.id DESC LIMIT 1)
            )
            WHERE o.client_id IS NULL OR o.client_id=0");
        } catch (PDOException $exception) {
            error_log('Rattachement automatique des projets aux clients ignoré : '.$exception->getMessage());
        }
    }
}


