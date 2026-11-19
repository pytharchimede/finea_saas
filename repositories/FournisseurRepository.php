<?php
require_once __DIR__ . "/../services/SupplierIdentity.php";
require_once __DIR__ . "/../services/PersonIdentityGuard.php";

require_once __DIR__ . '/../model/EntityPositioningManager.php';
require_once __DIR__ . '/../model/PositioningSettingsManager.php';

class FournisseurRepository
{
    /** Les décaissements antérieurs à cette date restent hors blocage fournisseur. */
    private const DISBURSEMENT_COMPLIANCE_BLOCK_START_DATE = '2026-08-10';

    private PDO $pdo;
    private bool $ready = true;
    private ?string $initError = null;
    private string $initOperation='Connexion au référentiel';
    private array $initDiagnostics=[];

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const TYPE_COMPANY = 'morale';
    public const TYPE_INDIVIDUAL = 'physique';

    private const DEFAULT_CATEGORIES = [
        'Fourniture de matériel informatique',
        'Formation',
        'Location d\'engins',
        'Transport',
        'Maintenance',
        'Consommables',
        'Prestations de service',
        'Matériaux BTP',
    ];

    private const DEFAULT_LEGAL_FORMS = [
        'SARL',
        'SARLU',
        'SA',
        'SAS',
        'SASU',
        'SCI',
        'GIE',
        'Association',
        'Coopérative',
        'Entreprise individuelle',
        'Personne physique',
    ];

    private const COMPANY_DOCUMENT_FIELDS = [
        'supplier_trade_register' => ['column' => 'trade_register_path', 'directory' => 'uploads/fournisseurs/documents/trade_register', 'label' => 'Registre de commerce'],
        'supplier_tax_existence' => ['column' => 'tax_existence_path', 'directory' => 'uploads/fournisseurs/documents/tax_existence', 'label' => 'Déclaration fiscale d\'existence'],
        'supplier_tax_clearance' => ['column' => 'tax_clearance_path', 'directory' => 'uploads/fournisseurs/documents/tax_clearance', 'label' => 'Attestation de régularité fiscale'],
        'supplier_social_clearance' => ['column' => 'social_clearance_path', 'directory' => 'uploads/fournisseurs/documents/social_clearance', 'label' => 'Attestation de régularité sociale'],
        'supplier_manager_cni' => ['column' => 'manager_cni_path', 'directory' => 'uploads/fournisseurs/documents/manager_cni', 'label' => 'CNI du gérant'],
    ];

    private const COMPANY_REQUIRED_DOCUMENT_FIELDS = [
        'supplier_logo' => ['column' => 'logo_path', 'label' => 'Logo société'],
    ];

    private const INDIVIDUAL_DOCUMENT_FIELDS = [
        'supplier_photo' => ['column' => 'photo_path', 'directory' => 'uploads/fournisseurs/photos', 'label' => 'Photo'],
        'supplier_cni' => ['column' => 'cni_path', 'directory' => 'uploads/fournisseurs/cni', 'label' => 'CNI'],
    ];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureTables();
    }

    private function ensureTables(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS fournisseur_banamur (
            id_fournisseur INT NOT NULL AUTO_INCREMENT,
            code_fournisseur VARCHAR(60) NULL,
            supplier_type VARCHAR(20) NOT NULL DEFAULT 'morale',
            raison_sociale VARCHAR(255) NOT NULL,
            nom_commercial VARCHAR(255) NULL,
            person_last_name VARCHAR(190) NULL,
            person_first_name VARCHAR(190) NULL,
            contact_nom VARCHAR(190) NULL,
            ncc VARCHAR(80) NULL,
            rne VARCHAR(80) NULL,
            is_rne TINYINT(1) NOT NULL DEFAULT 0,
            email VARCHAR(190) NULL,
            telephone VARCHAR(60) NULL,
            adresse TEXT NULL,
            ville VARCHAR(120) NULL,
            pays VARCHAR(80) NULL,
            logo_path VARCHAR(255) NULL,
            photo_path VARCHAR(255) NULL,
            cni_path VARCHAR(255) NULL,
            trade_register_path VARCHAR(255) NULL,
            tax_existence_path VARCHAR(255) NULL,
            tax_clearance_path VARCHAR(255) NULL,
            social_clearance_path VARCHAR(255) NULL,
            manager_cni_path VARCHAR(255) NULL,
            actif TINYINT(1) NOT NULL DEFAULT 1,
            validation_status VARCHAR(20) NOT NULL DEFAULT 'pending',
            validation_note TEXT NULL,
            created_by_user_id INT NULL,
            validated_by_user_id INT NULL,
            validated_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id_fournisseur),
            UNIQUE KEY uniq_fournisseur_code (code_fournisseur),
            KEY idx_fournisseur_nom (raison_sociale),
            KEY idx_fournisseur_actif (actif),
            KEY idx_fournisseur_validation_status (validation_status),
            KEY idx_fournisseur_creator (created_by_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

        $categorySql = "CREATE TABLE IF NOT EXISTS fournisseur_category_banamur (
            id_category INT NOT NULL AUTO_INCREMENT,
            label VARCHAR(190) NOT NULL,
            description TEXT NULL,
            actif TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id_category),
            UNIQUE KEY uniq_fournisseur_category_label (label)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

        $categoryLinkSql = "CREATE TABLE IF NOT EXISTS fournisseur_category_link_banamur (
            fournisseur_id INT NOT NULL,
            category_id INT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (fournisseur_id, category_id),
            KEY idx_supplier_category_category (category_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

        $legalFormSql = "CREATE TABLE IF NOT EXISTS fournisseur_legal_form_banamur (
            id_legal_form INT NOT NULL AUTO_INCREMENT,
            label VARCHAR(190) NOT NULL,
            description TEXT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            actif TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id_legal_form),
            UNIQUE KEY uniq_fournisseur_legal_form_label (label)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

        $incidentSql = "CREATE TABLE IF NOT EXISTS fournisseur_incident_banamur (
            id_incident INT NOT NULL AUTO_INCREMENT,
            fournisseur_id INT NOT NULL,
            incident_date DATE NOT NULL,
            titre VARCHAR(255) NOT NULL,
            description TEXT NOT NULL,
            gravite VARCHAR(20) NOT NULL DEFAULT 'medium',
            statut VARCHAR(20) NOT NULL DEFAULT 'open',
            created_by_user_id INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id_incident),
            KEY idx_fournisseur_incident_supplier (fournisseur_id),
            KEY idx_fournisseur_incident_date (incident_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

        try {
            $this->schemaExec($sql);
            $this->schemaExec($incidentSql);
            $this->schemaExec($categorySql);
            $this->schemaExec($categoryLinkSql);
            $this->schemaExec($legalFormSql);
            $this->ensureColumns();
            $this->ensureDecaissementColumns();
            $this->ensureDefaultCategories();
            $this->ensureDefaultLegalForms();
        } catch (Throwable $e) {
            $this->ready = false;
            $this->initError = 'Initialisation SQL des fournisseurs interrompue.';
            $this->initDiagnostics=['operation'=>$this->initOperation,'sqlstate'=>$e instanceof PDOException?(string)($e->errorInfo[0]??$e->getCode()):null,'driver_code'=>$e instanceof PDOException?($e->errorInfo[1]??null):null,'message'=>preg_replace("/(?<![a-zA-Z0-9_])'[^']*'|`[^`]*`/","'[valeur masquée]'",$e->getMessage())];
        }
    }

    private function ensureColumns(): void
    {
        $columns = $this->pdo->query('SHOW COLUMNS FROM fournisseur_banamur')->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
        $columns = array_map('strval', $columns);

        foreach (InternationalBusinessIdentity::COLUMNS as $column=>$definition) {
            if (!in_array($column,$columns,true)) $this->schemaExec("ALTER TABLE fournisseur_banamur ADD $column $definition");
        }
        $indexes=$this->pdo->query('SHOW INDEX FROM fournisseur_banamur')->fetchAll(PDO::FETCH_COLUMN,2);
        if (!in_array('uq_supplier_identity_key',$indexes,true)) $this->schemaExec('ALTER TABLE fournisseur_banamur ADD UNIQUE KEY uq_supplier_identity_key(identity_key)');
        $this->initOperation='Préparation des colonnes de pièces RH et de leur historique';
        PersonIdentityGuard::ensureSchema($this->pdo);
        $columnSql = [
            'identity_document_number'=>"ALTER TABLE fournisseur_banamur ADD identity_document_number VARCHAR(80) NULL, ADD UNIQUE KEY uq_supplier_person_identity(identity_document_number)",
            'fiscal_country' => "ALTER TABLE fournisseur_banamur ADD fiscal_country VARCHAR(2) NOT NULL DEFAULT 'CI'",
            'siret' => "ALTER TABLE fournisseur_banamur ADD siret VARCHAR(14) NULL, ADD UNIQUE KEY uq_supplier_siret (siret)",
            'siren' => "ALTER TABLE fournisseur_banamur ADD siren VARCHAR(9) NULL",
            'vat_number' => "ALTER TABLE fournisseur_banamur ADD vat_number VARCHAR(20) NULL",
            'registry_verified_at' => "ALTER TABLE fournisseur_banamur ADD registry_verified_at DATETIME NULL",
            'registry_response_json' => "ALTER TABLE fournisseur_banamur ADD registry_response_json LONGTEXT NULL",
            'archived_at' => "ALTER TABLE fournisseur_banamur ADD archived_at DATETIME NULL",
            'archived_by_user_id' => "ALTER TABLE fournisseur_banamur ADD archived_by_user_id INT NULL",
            'archive_previous_active' => "ALTER TABLE fournisseur_banamur ADD archive_previous_active TINYINT(1) NULL",

            'dgi_verified_at' => "ALTER TABLE fournisseur_banamur ADD dgi_verified_at DATETIME NULL",
            'dgi_response_json' => "ALTER TABLE fournisseur_banamur ADD dgi_response_json LONGTEXT NULL",
            'ncc_identity' => "ALTER TABLE fournisseur_banamur ADD ncc_identity VARCHAR(80) NULL, ADD UNIQUE KEY uq_supplier_ncc_identity (ncc_identity)",
            'code_fournisseur' => "ALTER TABLE fournisseur_banamur ADD code_fournisseur VARCHAR(60) NULL AFTER id_fournisseur",
            'supplier_type' => "ALTER TABLE fournisseur_banamur ADD supplier_type VARCHAR(20) NOT NULL DEFAULT 'morale' AFTER code_fournisseur",
            'contact_nom' => "ALTER TABLE fournisseur_banamur ADD contact_nom VARCHAR(190) NULL AFTER nom_commercial",
            'person_last_name' => "ALTER TABLE fournisseur_banamur ADD person_last_name VARCHAR(190) NULL AFTER nom_commercial",
            'person_first_name' => "ALTER TABLE fournisseur_banamur ADD person_first_name VARCHAR(190) NULL AFTER person_last_name",
            'validation_status' => "ALTER TABLE fournisseur_banamur ADD validation_status VARCHAR(20) NOT NULL DEFAULT 'pending' AFTER actif",
            'validation_note' => "ALTER TABLE fournisseur_banamur ADD validation_note TEXT NULL AFTER validation_status",
            'created_by_user_id' => "ALTER TABLE fournisseur_banamur ADD created_by_user_id INT NULL AFTER validation_note",
            'validated_by_user_id' => "ALTER TABLE fournisseur_banamur ADD validated_by_user_id INT NULL AFTER created_by_user_id",
            'validated_at' => "ALTER TABLE fournisseur_banamur ADD validated_at DATETIME NULL AFTER validated_by_user_id",
            'logo_path' => "ALTER TABLE fournisseur_banamur ADD logo_path VARCHAR(255) NULL AFTER pays",
            'photo_path' => "ALTER TABLE fournisseur_banamur ADD photo_path VARCHAR(255) NULL AFTER logo_path",
            'cni_path' => "ALTER TABLE fournisseur_banamur ADD cni_path VARCHAR(255) NULL AFTER photo_path",
            'trade_register_path' => "ALTER TABLE fournisseur_banamur ADD trade_register_path VARCHAR(255) NULL AFTER cni_path",
            'tax_existence_path' => "ALTER TABLE fournisseur_banamur ADD tax_existence_path VARCHAR(255) NULL AFTER trade_register_path",
            'tax_clearance_path' => "ALTER TABLE fournisseur_banamur ADD tax_clearance_path VARCHAR(255) NULL AFTER tax_existence_path",
            'social_clearance_path' => "ALTER TABLE fournisseur_banamur ADD social_clearance_path VARCHAR(255) NULL AFTER tax_clearance_path",
            'manager_cni_path' => "ALTER TABLE fournisseur_banamur ADD manager_cni_path VARCHAR(255) NULL AFTER social_clearance_path",
            'disbursement_selection_blocked' => "ALTER TABLE fournisseur_banamur ADD disbursement_selection_blocked TINYINT(1) NOT NULL DEFAULT 0 AFTER validation_note",
            'disbursement_block_reason' => "ALTER TABLE fournisseur_banamur ADD disbursement_block_reason VARCHAR(500) NULL AFTER disbursement_selection_blocked",
            'disbursement_block_fiches' => "ALTER TABLE fournisseur_banamur ADD disbursement_block_fiches VARCHAR(500) NULL AFTER disbursement_block_reason",
        ];

        foreach ($columnSql as $column => $sql) {
            if (!in_array($column, $columns, true)) {
                $this->schemaExec($sql);
            }
        }
    }

    private function ensureDefaultCategories(): void
    {
        try {
            $stmt = $this->pdo->prepare('INSERT IGNORE INTO fournisseur_category_banamur (label, actif) VALUES (:label, 1)');
            foreach (self::DEFAULT_CATEGORIES as $label) {
                $stmt->execute(['label' => $label]);
            }
        } catch (Throwable $e) {
        }
    }

    private function ensureDefaultLegalForms(): void
    {
        try {
            $stmt = $this->pdo->prepare('INSERT IGNORE INTO fournisseur_legal_form_banamur (label, sort_order, actif) VALUES (:label, :sort_order, 1)');
            foreach (self::DEFAULT_LEGAL_FORMS as $index => $label) {
                $stmt->execute([
                    'label' => $label,
                    'sort_order' => $index + 1,
                ]);
            }
        } catch (Throwable $e) {
        }
    }

    private function ensureDecaissementColumns(): void
    {
        try {
            $columns = $this->pdo->query('SHOW COLUMNS FROM fiche_decaissement')->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
            $columns = array_map('strval', $columns);
        } catch (Throwable $e) {
            return;
        }

        $columnSql = [
            'fournisseur_id' => "ALTER TABLE fiche_decaissement ADD fournisseur_id INT NULL",
            'fournisseur_nom' => "ALTER TABLE fiche_decaissement ADD fournisseur_nom VARCHAR(255) NULL",
            'fournisseur_contact_nom' => "ALTER TABLE fiche_decaissement ADD fournisseur_contact_nom VARCHAR(190) NULL",
            'fournisseur_telephone' => "ALTER TABLE fiche_decaissement ADD fournisseur_telephone VARCHAR(60) NULL",
        ];

        foreach ($columnSql as $column => $sql) {
            if (!in_array($column, $columns, true)) {
                $this->schemaExec($sql);
            }
        }
    }

    private function schemaExec(string $sql):int|false
    {
        $this->initOperation=substr(preg_replace('/\s+/',' ',trim($sql)),0,220);
        return $this->pdo->exec($sql);
    }

    public function getInitDiagnostics():array
    {
        return $this->initDiagnostics;
    }

    public function isReady(): bool
    {
        return $this->ready;
    }

    public function getInitError(): ?string
    {
        return $this->initError;
    }
    public function model(): self
    {
        return $this;
    }

    public function positioning(): EntityPositioningManager
    {
        return new EntityPositioningManager($this->pdo);
    }

    public function positioningSettings(): PositioningSettingsManager
    {
        return new PositioningSettingsManager($this->pdo);
    }

    public function getDashboardStats(): array
    {
        $stats = [
            'total' => 0,
            'actifs' => 0,
            'inactifs' => 0,
            'pending_validation' => 0,
            'approved_validation' => 0,
            'rejected_validation' => 0,
            'with_ncc' => 0,
            'with_rne' => 0,
            'missing_ncc_when_not_rne' => 0,
            'missing_rne_when_rne' => 0,
            'missing_contact' => 0,
            'incidents_total' => 0,
            'decaissements_lies' => 0,
            'last_created_at' => null,
        ];

        if (!$this->isReady()) {
            return $stats;
        }

        $stats['total'] = (int) $this->pdo->query('SELECT COUNT(*) FROM fournisseur_banamur WHERE archived_at IS NULL')->fetchColumn();
        $stats['actifs'] = (int) $this->pdo->query('SELECT COUNT(*) FROM fournisseur_banamur WHERE archived_at IS NULL AND actif = 1')->fetchColumn();
        $stats['inactifs'] = max(0, $stats['total'] - $stats['actifs']);
        $stats['pending_validation'] = (int) $this->pdo->query("SELECT COUNT(*) FROM fournisseur_banamur WHERE archived_at IS NULL AND validation_status = 'pending'")->fetchColumn();
        $stats['approved_validation'] = (int) $this->pdo->query("SELECT COUNT(*) FROM fournisseur_banamur WHERE archived_at IS NULL AND validation_status = 'approved'")->fetchColumn();
        $stats['rejected_validation'] = (int) $this->pdo->query("SELECT COUNT(*) FROM fournisseur_banamur WHERE archived_at IS NULL AND validation_status = 'rejected'")->fetchColumn();
        $stats['with_ncc'] = (int) $this->pdo->query("SELECT COUNT(*) FROM fournisseur_banamur WHERE archived_at IS NULL AND ncc IS NOT NULL AND TRIM(ncc) <> ''")->fetchColumn();
        $stats['with_rne'] = (int) $this->pdo->query("SELECT COUNT(*) FROM fournisseur_banamur WHERE archived_at IS NULL AND rne IS NOT NULL AND TRIM(rne) <> ''")->fetchColumn();
        $stats['missing_ncc_when_not_rne'] = (int) $this->pdo->query("SELECT COUNT(*) FROM fournisseur_banamur WHERE archived_at IS NULL AND identity_method <> 'exception' AND supplier_type = 'morale' AND ((fiscal_country = 'CI' AND (ncc IS NULL OR TRIM(ncc) = '' OR dgi_verified_at IS NULL)) OR (fiscal_country <> 'CI' AND identity_status NOT IN ('registry_verified','documentary_verified')))")->fetchColumn();
        $stats['missing_rne_when_rne'] = (int) $this->pdo->query("SELECT COUNT(*) FROM fournisseur_banamur WHERE archived_at IS NULL AND is_rne = 1 AND (rne IS NULL OR TRIM(rne) = '')")->fetchColumn();
        $stats['missing_contact'] = (int) $this->pdo->query("SELECT COUNT(*) FROM fournisseur_banamur WHERE archived_at IS NULL AND identity_method <> 'exception' AND (email IS NULL OR TRIM(email) = '') AND (telephone IS NULL OR TRIM(telephone) = '')")->fetchColumn();
        $stats['last_created_at'] = $this->pdo->query('SELECT MAX(created_at) FROM fournisseur_banamur WHERE archived_at IS NULL')->fetchColumn();

        try {
            $stats['incidents_total'] = (int) $this->pdo->query('SELECT COUNT(*) FROM fournisseur_incident_banamur')->fetchColumn();
        } catch (Throwable $e) {
            $stats['incidents_total'] = 0;
        }

        try {
            $stats['decaissements_lies'] = (int) $this->pdo->query('SELECT COUNT(*) FROM fiche_decaissement f LEFT JOIN chantier_banamur c ON c.id=f.chantier_id WHERE f.fournisseur_id IS NOT NULL AND (f.chantier_id IS NULL OR f.chantier_id=0 OR c.archived_at IS NULL)')->fetchColumn();
        } catch (Throwable $e) {
            $stats['decaissements_lies'] = 0;
        }

        return $stats;
    }

    public function getLatestDashboardRows(): array
    {
        if (!$this->isReady()) {
            return [];
        }

        return $this->pdo->query(
            "SELECT id_fournisseur, raison_sociale, nom_commercial, contact_nom, validation_status, actif, is_rne, ncc, rne, created_at
             FROM fournisseur_banamur
             WHERE archived_at IS NULL
             ORDER BY id_fournisseur DESC
             LIMIT 8"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function directoryRows(string $archive = 'active'): array
    {
        if (!$this->ready) return [];
        $where = $archive === 'all' ? '' : ($archive === 'archived' ? ' WHERE archived_at IS NOT NULL' : ' WHERE archived_at IS NULL');
        return $this->hydrateCategoryData($this->pdo->query('SELECT * FROM fournisseur_banamur' . $where . ' ORDER BY id_fournisseur DESC')->fetchAll(PDO::FETCH_ASSOC));
    }

    public function incompleteRows(): array
    {
        $rows = $this->hydrateCategoryData($this->pdo->query('SELECT * FROM fournisseur_banamur WHERE archived_at IS NULL ORDER BY id_fournisseur DESC')->fetchAll(PDO::FETCH_ASSOC));
        return array_values(array_filter($rows, static fn($row)=>empty($row['document_is_complete'])));
    }

    public function listLatest(int $limit = 200, bool $onlyActive = false, array $options = []): array
    {
        if (!$this->ready) {
            return [];
        }

        $limit = max(1, min(500, $limit));
        $sql = 'SELECT * FROM fournisseur_banamur';
        $archive = (string) ($options['archive'] ?? 'active');
        $where = $archive === 'all' ? [] : [$archive === 'archived' ? 'archived_at IS NOT NULL' : 'archived_at IS NULL'];
        $params = [];
        if ($onlyActive) {
            $where[] = 'actif = 1';
        }
        if (!empty($options['created_by_user_id'])) {
            $where[] = 'created_by_user_id = :created_by_user_id';
            $params['created_by_user_id'] = (int)$options['created_by_user_id'];
        }
        if (!empty($options['validation_status']) && in_array((string)$options['validation_status'], $this->getValidationStatuses(), true)) {
            $where[] = 'validation_status = :validation_status';
            $params['validation_status'] = (string)$options['validation_status'];
        }
        if (!empty($options['supplier_type']) && in_array((string)$options['supplier_type'], $this->getSupplierTypes(), true)) {
            $where[] = 'supplier_type = :supplier_type';
            $params['supplier_type'] = (string)$options['supplier_type'];
        }
        if (!empty($options['search'])) {
            $where[] = '(raison_sociale LIKE :search OR nom_commercial LIKE :search OR contact_nom LIKE :search OR code_fournisseur LIKE :search OR email LIKE :search)';
            $params['search'] = '%' . trim((string)$options['search']) . '%';
        }

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY id_fournisseur DESC LIMIT ' . (int)$limit;

        try {
            $st = $this->pdo->prepare($sql);
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            return $this->hydrateCategoryData($rows);
        } catch (Throwable $e) {
            return [];
        }
    }

    public function bulkChangeState(array $ids, string $action, int $actorId): array
    {
        if (!$this->ready || $actorId <= 0 || !in_array($action, ['archive', 'restore', 'activate', 'deactivate'], true)) {
            return ['ok' => false, 'error' => 'Action ou utilisateur invalide.'];
        }
        if (!$ids || count($ids) > 500) return ['ok' => false, 'error' => 'Sélectionnez entre 1 et 500 fournisseurs.'];
        $normalized = [];
        foreach ($ids as $id) {
            if (!is_scalar($id) || !ctype_digit((string) $id) || (int) $id <= 0) {
                return ['ok' => false, 'error' => 'Sélection fournisseur invalide.'];
            }
            $normalized[(int) $id] = (int) $id;
        }
        // Each action is a single atomic UPDATE. Activation never restores an archive implicitly.
        $changes = [
            'archive' => ['archive_previous_active = actif, actif = 0, archived_at = NOW(), archived_by_user_id = ?', 'archived_at IS NULL'],
            'restore' => ['actif = COALESCE(archive_previous_active, 1), archived_at = NULL, archived_by_user_id = NULL, archive_previous_active = NULL', 'archived_at IS NOT NULL'],
            'activate' => ['actif = 1', 'archived_at IS NULL AND actif = 0'],
            'deactivate' => ['actif = 0', 'archived_at IS NULL AND actif = 1'],
        ];
        [$set, $condition] = $changes[$action];
        $params = array_values($normalized);
        $placeholders = implode(',', array_fill(0, count($params), '?'));
        if (!SupplierExceptionPolicy::allowed()) $condition.=" AND COALESCE(identity_method,'fne') <> 'exception'";
        if ($action === 'archive') array_unshift($params, $actorId);
        try {
            $stmt = $this->pdo->prepare("UPDATE fournisseur_banamur SET $set WHERE id_fournisseur IN ($placeholders) AND $condition");
            $stmt->execute($params);
            return ['ok' => true, 'changed' => $stmt->rowCount(), 'selected' => count($normalized)];
        } catch (Throwable $e) {
            error_log('Supplier bulk state: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Impossible de modifier les fournisseurs sélectionnés.'];
        }
    }

    public function setArchived(int $id, bool $archived, int $actorId): array
    {
        if (!$this->ready || $id <= 0 || $actorId <= 0) {
            return ['ok' => false, 'error' => 'Fournisseur ou utilisateur invalide.'];
        }
        // Atomic and idempotent: repeating an archive must not overwrite the original active state.
        $sql = $archived
            ? 'UPDATE fournisseur_banamur SET archive_previous_active = actif, actif = 0, archived_at = NOW(), archived_by_user_id = :actor WHERE id_fournisseur = :id AND archived_at IS NULL'
            : 'UPDATE fournisseur_banamur SET actif = COALESCE(archive_previous_active, 1), archived_at = NULL, archived_by_user_id = NULL, archive_previous_active = NULL WHERE id_fournisseur = :id AND archived_at IS NOT NULL';
        if (!SupplierExceptionPolicy::allowed()) $sql.=" AND COALESCE(identity_method,'fne') <> 'exception'";
        try {
            $params = ['id' => $id];
            if ($archived) $params['actor'] = $actorId;
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return ['ok' => $stmt->rowCount() > 0, 'error' => 'Fournisseur introuvable ou état déjà appliqué.'];
        } catch (Throwable $e) {
            error_log('Supplier archive: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Impossible de modifier l’archivage du fournisseur.'];
        }
    }

    public function getById(int $id): ?array
    {
        if (!$this->ready || $id <= 0) {
            return null;
        }
        $st = $this->pdo->prepare('SELECT * FROM fournisseur_banamur WHERE id_fournisseur = :id LIMIT 1');
        $st->execute(['id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $rows = $this->hydrateCategoryData([$row]);
        return $rows[0] ?? null;
    }

    public function create(array $data, ?int $userId = null, array $files = []): array
    {
        return PersonIdentityGuard::supplierWrite($this->pdo,$data,[],fn()=>$this->createRecord($data,$userId,$files));
    }

    private function createRecord(array $data, ?int $userId = null, array $files = []): array
    {
        if (!$this->ready) {
            return ['ok' => false, 'error' => $this->initError ?? 'Module indisponible.'];
        }

        $supplierType = $this->normalizeSupplierType($data['supplier_type'] ?? null);
        try {
            if ($supplierType===self::TYPE_INDIVIDUAL) $data=PersonIdentityGuard::verifySupplier($this->pdo,$data);
            $data = SupplierIdentity::verify($this->pdo, $data, $supplierType, []);
            if (SupplierExceptionPolicy::isException($data)) SupplierExceptionPolicy::requireAllowed($userId??0);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e instanceof DomainException ? $e->getMessage() : 'Vérification du registre indisponible. Réessayez ultérieurement.'];
        }
        $assetPaths = $this->processSupplierAssets($files, [], $supplierType);
        $normalized = $this->normalizeSupplierPayload($data, $supplierType);
        $validationError = $this->validateSupplierPayload($normalized, $assetPaths, $supplierType, $data);
        if ($validationError !== null) {
            return ['ok' => false, 'error' => $validationError];
        }

        $payload = [
            // Référence interne: générée automatiquement (champ non modifiable côté métier).
            'code_fournisseur' => $this->generateCodeFournisseur(),
            'supplier_type' => $supplierType,
            'raison_sociale' => $normalized['raison_sociale'],
            'nom_commercial' => $normalized['nom_commercial'],
            'person_last_name' => $normalized['person_last_name'],
            'person_first_name' => $normalized['person_first_name'],
            'contact_nom' => $normalized['contact_nom'],
            'fiscal_country' => $data['fiscal_country'],
            'siret' => $data['siret'],
            'siren' => $data['siren'],
            'vat_number' => $data['vat_number'],
            'registry_verified_at' => $data['registry_verified_at'],
            'registry_response_json' => $data['registry_response_json'],
            'registration_number' => $data['registration_number'],
            'registration_type' => $data['registration_type'],
            'identity_method' => $data['identity_method'],
            'identity_status' => $data['identity_status'],
            'identity_key' => $data['identity_key'],
            'identity_evidence' => $data['identity_evidence'],
            'identity_snapshot' => $data['identity_snapshot'],
            'identity_checked_at' => $data['identity_checked_at'],
            'identity_checked_by' => $data['identity_checked_by'],
            'vat_status' => $data['vat_status'],
            'identity_document_number' => $supplierType===self::TYPE_INDIVIDUAL ? $data['identity_document_number'] : null,
            'ncc' => $normalized['ncc'],
            'ncc_identity' => $data['ncc_identity'],
            'dgi_verified_at' => $data['dgi_verified_at'],
            'dgi_response_json' => $data['dgi_response_json'],
            'rne' => $normalized['rne'],
            'is_rne' => $normalized['is_rne'],
            'email' => $normalized['email'],
            'telephone' => $normalized['telephone'],
            'adresse' => $normalized['adresse'],
            'ville' => $normalized['ville'],
            'pays' => $normalized['pays'],
            'logo_path' => $assetPaths['logo_path'],
            'photo_path' => $assetPaths['photo_path'],
            'cni_path' => $assetPaths['cni_path'],
            'trade_register_path' => $assetPaths['trade_register_path'],
            'tax_existence_path' => $assetPaths['tax_existence_path'],
            'tax_clearance_path' => $assetPaths['tax_clearance_path'],
            'social_clearance_path' => $assetPaths['social_clearance_path'],
            'manager_cni_path' => $assetPaths['manager_cni_path'],
            'actif' => array_key_exists('actif', $data) ? (!empty($data['actif']) ? 1 : 0) : 1,
            'validation_status' => SupplierExceptionPolicy::isException($data) ? self::STATUS_APPROVED : self::STATUS_PENDING,
            'validation_note' => SupplierExceptionPolicy::isException($data) ? $data['identity_evidence'] : null,
            'validated_by_user_id' => SupplierExceptionPolicy::isException($data) ? $userId : null,
            'validated_at' => SupplierExceptionPolicy::isException($data) ? $data['identity_checked_at'] : null,
            'created_by_user_id' => $userId ?: null,
        ];

        $positioningPayload = $payload + [
            'location_latitude' => isset($data['location_latitude']) && is_numeric($data['location_latitude']) ? (float) $data['location_latitude'] : null,
            'location_longitude' => isset($data['location_longitude']) && is_numeric($data['location_longitude']) ? (float) $data['location_longitude'] : null,
            'location_source' => $this->asNullableString($data['location_source'] ?? null),
            'location_label' => $this->asNullableString($data['location_label'] ?? null),
        ];

        $sql = 'INSERT INTO fournisseur_banamur (
            code_fournisseur, supplier_type, raison_sociale, nom_commercial, person_last_name, person_first_name, contact_nom, identity_document_number, registration_number, registration_type, identity_method, identity_status, identity_key, identity_evidence, identity_snapshot, identity_checked_at, identity_checked_by, vat_status, fiscal_country, siret, siren, vat_number, registry_verified_at, registry_response_json, ncc, ncc_identity, dgi_verified_at, dgi_response_json, rne, is_rne, email, telephone, adresse, ville, pays, logo_path, photo_path, cni_path, trade_register_path, tax_existence_path, tax_clearance_path, social_clearance_path, manager_cni_path, actif, validation_status, validation_note, validated_by_user_id, validated_at, created_by_user_id
        ) VALUES (
            :code_fournisseur, :supplier_type, :raison_sociale, :nom_commercial, :person_last_name, :person_first_name, :contact_nom, :identity_document_number, :registration_number, :registration_type, :identity_method, :identity_status, :identity_key, :identity_evidence, :identity_snapshot, :identity_checked_at, :identity_checked_by, :vat_status, :fiscal_country, :siret, :siren, :vat_number, :registry_verified_at, :registry_response_json, :ncc, :ncc_identity, :dgi_verified_at, :dgi_response_json, :rne, :is_rne, :email, :telephone, :adresse, :ville, :pays, :logo_path, :photo_path, :cni_path, :trade_register_path, :tax_existence_path, :tax_clearance_path, :social_clearance_path, :manager_cni_path, :actif, :validation_status, :validation_note, :validated_by_user_id, :validated_at, :created_by_user_id
        )';

        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                $st = $this->pdo->prepare($sql);
                $st->execute($payload);
                $insertedId = (int)$this->pdo->lastInsertId();
                $this->runPostSaveTasks($insertedId, $data, $positioningPayload, $userId);
                return ['ok' => true, 'id' => $insertedId, 'code_fournisseur' => (string)$payload['code_fournisseur']];
            } catch (PDOException $e) {
                if ($this->isDuplicateKey($e)) {
                    if (str_contains($e->getMessage(), 'uq_supplier_person_identity')) return ['ok'=>false,'error'=>'Cette pièce est déjà rattachée à un fournisseur.'];
                    if (str_contains($e->getMessage(), 'uq_supplier_identity_key')) return ['ok'=>false,'error'=>'Cet identifiant appartient déjà à un fournisseur.'];
                    if (str_contains($e->getMessage(), 'uq_supplier_siret')) return ['ok' => false, 'error' => 'Ce SIRET est déjà attribué à un fournisseur.'];
                    if (str_contains($e->getMessage(), 'uq_supplier_ncc_identity')) return ['ok' => false, 'error' => 'Ce NCC est déjà attribué à un fournisseur.'];
                    $payload['code_fournisseur'] = $this->generateCodeFournisseur();
                    continue;
                }
                return ['ok' => false, 'error' => 'Erreur SQL lors de la création du fournisseur.'];
            }
        }

        return ['ok' => false, 'error' => 'Impossible de générer un code fournisseur unique.'];
    }

    public function update(int $id, array $data, ?int $editorUserId = null, bool $isValidator = false, array $files = []): array
    {
        $old=$this->getById($id)??[];
        return PersonIdentityGuard::supplierWrite($this->pdo,$data,$old,fn()=>$this->updateRecord($id,$data,$editorUserId,$isValidator,$files));
    }

    private function updateRecord(int $id, array $data, ?int $editorUserId = null, bool $isValidator = false, array $files = []): array
    {
        if (!$this->ready || $id <= 0) {
            return ['ok' => false, 'error' => 'Fournisseur invalide.'];
        }

        $current = $this->getById($id);
        if (!$current) {
            return ['ok' => false, 'error' => 'Fournisseur introuvable.'];
        }

        if (!empty($current['archived_at'])) {
            return ['ok' => false, 'error' => 'Réactivez le fournisseur avant de modifier sa fiche.'];
        }

        $currentCode = $this->asNullableString($current['code_fournisseur'] ?? null);
        $resolvedCode = $currentCode ?: $this->generateCodeFournisseur();

        $supplierType = $this->normalizeSupplierType($data['supplier_type'] ?? ($current['supplier_type'] ?? null));
        try {
            if ($supplierType===self::TYPE_INDIVIDUAL) $data=PersonIdentityGuard::verifySupplier($this->pdo,$data,$current);
            $data = SupplierIdentity::verify($this->pdo, $data, $supplierType, $current);
            if (SupplierExceptionPolicy::isException($data)) SupplierExceptionPolicy::requireAllowed($editorUserId??0);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e instanceof DomainException ? $e->getMessage() : 'Vérification du registre indisponible. Réessayez ultérieurement.'];
        }
        $assetPaths = $this->processSupplierAssets($files, $current, $supplierType);
        $normalized = $this->normalizeSupplierPayload($data, $supplierType, $current);
        $validationError = $this->validateSupplierPayload($normalized, $assetPaths, $supplierType, $data);
        if ($validationError !== null) {
            return ['ok' => false, 'error' => $validationError];
        }

        $payload = [
            'id' => $id,
            // Champ verrouillé: on conserve le code existant, ou on en génère un si absent.
            'code_fournisseur' => $resolvedCode,
            'supplier_type' => $supplierType,
            'raison_sociale' => $normalized['raison_sociale'],
            'nom_commercial' => $normalized['nom_commercial'],
            'person_last_name' => $normalized['person_last_name'],
            'person_first_name' => $normalized['person_first_name'],
            'contact_nom' => $normalized['contact_nom'],
            'fiscal_country' => $data['fiscal_country'],
            'siret' => $data['siret'],
            'siren' => $data['siren'],
            'vat_number' => $data['vat_number'],
            'registry_verified_at' => $data['registry_verified_at'],
            'registry_response_json' => $data['registry_response_json'],
            'registration_number' => $data['registration_number'],
            'registration_type' => $data['registration_type'],
            'identity_method' => $data['identity_method'],
            'identity_status' => $data['identity_status'],
            'identity_key' => $data['identity_key'],
            'identity_evidence' => $data['identity_evidence'],
            'identity_snapshot' => $data['identity_snapshot'],
            'identity_checked_at' => $data['identity_checked_at'],
            'identity_checked_by' => $data['identity_checked_by'],
            'vat_status' => $data['vat_status'],
            'identity_document_number' => $supplierType===self::TYPE_INDIVIDUAL ? $data['identity_document_number'] : null,
            'ncc' => $normalized['ncc'],
            'ncc_identity' => $data['ncc_identity'],
            'dgi_verified_at' => $data['dgi_verified_at'],
            'dgi_response_json' => $data['dgi_response_json'],
            'rne' => $normalized['rne'],
            'is_rne' => $normalized['is_rne'],
            'email' => $normalized['email'],
            'telephone' => $normalized['telephone'],
            'adresse' => $normalized['adresse'],
            'ville' => $normalized['ville'],
            'pays' => $normalized['pays'],
            'logo_path' => $assetPaths['logo_path'],
            'photo_path' => $assetPaths['photo_path'],
            'cni_path' => $assetPaths['cni_path'],
            'trade_register_path' => $assetPaths['trade_register_path'],
            'tax_existence_path' => $assetPaths['tax_existence_path'],
            'tax_clearance_path' => $assetPaths['tax_clearance_path'],
            'social_clearance_path' => $assetPaths['social_clearance_path'],
            'manager_cni_path' => $assetPaths['manager_cni_path'],
            'actif' => array_key_exists('actif', $data) ? (!empty($data['actif']) ? 1 : 0) : 1,
            'validation_status' => SupplierExceptionPolicy::isException($data) ? self::STATUS_APPROVED : ($isValidator && ($data['identity_status']??'') !== 'documentary_pending' ? (string)($current['validation_status'] ?? self::STATUS_PENDING) : self::STATUS_PENDING),
            'validation_note' => SupplierExceptionPolicy::isException($data) ? $data['identity_evidence'] : ($isValidator ? ($this->asNullableString($data['validation_note'] ?? ($current['validation_note'] ?? null))) : null),
            'validated_by_user_id' => SupplierExceptionPolicy::isException($data) ? $editorUserId : ($isValidator ? ($current['validated_by_user_id'] ?? null) : null),
            'validated_at' => SupplierExceptionPolicy::isException($data) ? $data['identity_checked_at'] : ($isValidator ? ($current['validated_at'] ?? null) : null),
        ];

        $positioningPayload = $payload + [
            'location_latitude' => isset($data['location_latitude']) && is_numeric($data['location_latitude']) ? (float) $data['location_latitude'] : null,
            'location_longitude' => isset($data['location_longitude']) && is_numeric($data['location_longitude']) ? (float) $data['location_longitude'] : null,
            'location_source' => $this->asNullableString($data['location_source'] ?? null),
            'location_label' => $this->asNullableString($data['location_label'] ?? null),
        ];

        $sql = 'UPDATE fournisseur_banamur SET
            code_fournisseur = :code_fournisseur,
            supplier_type = :supplier_type,
            raison_sociale = :raison_sociale,
            nom_commercial = :nom_commercial,
            person_last_name = :person_last_name,
            person_first_name = :person_first_name,
            contact_nom = :contact_nom,
            fiscal_country = :fiscal_country, siret = :siret, siren = :siren, vat_number = :vat_number, registry_verified_at = :registry_verified_at, registry_response_json = :registry_response_json,
            registration_number = :registration_number, registration_type = :registration_type, identity_method = :identity_method, identity_status = :identity_status, identity_key = :identity_key, identity_evidence = :identity_evidence, identity_snapshot = :identity_snapshot, identity_checked_at = :identity_checked_at, identity_checked_by = :identity_checked_by, vat_status = :vat_status,
            identity_document_number = :identity_document_number,
            ncc = :ncc, ncc_identity = :ncc_identity, dgi_verified_at = :dgi_verified_at, dgi_response_json = :dgi_response_json,
            rne = :rne,
            is_rne = :is_rne,
            email = :email,
            telephone = :telephone,
            adresse = :adresse,
            ville = :ville,
            pays = :pays,
            logo_path = :logo_path,
            photo_path = :photo_path,
            cni_path = :cni_path,
            trade_register_path = :trade_register_path,
            tax_existence_path = :tax_existence_path,
            tax_clearance_path = :tax_clearance_path,
            social_clearance_path = :social_clearance_path,
            manager_cni_path = :manager_cni_path,
            actif = :actif,
            validation_status = :validation_status,
            validation_note = :validation_note,
            validated_by_user_id = :validated_by_user_id,
            validated_at = :validated_at
        WHERE id_fournisseur = :id';

        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                $st = $this->pdo->prepare($sql);
                $st->execute($payload);
                $this->runPostSaveTasks($id, $data, $positioningPayload, $editorUserId);
                return ['ok' => true, 'code_fournisseur' => (string)$payload['code_fournisseur']];
            } catch (PDOException $e) {
                if ($this->isDuplicateKey($e)) {
                    if (str_contains($e->getMessage(), 'uq_supplier_person_identity')) return ['ok'=>false,'error'=>'Cette pièce est déjà rattachée à un fournisseur.'];
                    if (str_contains($e->getMessage(), 'uq_supplier_identity_key')) return ['ok'=>false,'error'=>'Cet identifiant appartient déjà à un fournisseur.'];
                    if (str_contains($e->getMessage(), 'uq_supplier_siret')) return ['ok' => false, 'error' => 'Ce SIRET est déjà attribué à un fournisseur.'];
                    if (str_contains($e->getMessage(), 'uq_supplier_ncc_identity')) return ['ok' => false, 'error' => 'Ce NCC est déjà attribué à un fournisseur.'];
                    $payload['code_fournisseur'] = $this->generateCodeFournisseur();
                    continue;
                }
                return ['ok' => false, 'error' => 'Erreur SQL lors de la mise à jour du fournisseur.'];
            }
        }

        return ['ok' => false, 'error' => 'Impossible de générer un code fournisseur unique.'];
    }

    private function generateCodeFournisseur(): string
    {
        $prefix = 'FOU-' . date('Y') . '-';

        try {
            $st = $this->pdo->prepare('SELECT code_fournisseur FROM fournisseur_banamur WHERE code_fournisseur LIKE :like ORDER BY code_fournisseur DESC LIMIT 1');
            $st->execute(['like' => $prefix . '%']);
            $last = (string)($st->fetchColumn() ?: '');
        } catch (Throwable $e) {
            $last = '';
        }

        $next = 1;
        if ($last !== '' && str_starts_with($last, $prefix)) {
            $suffix = substr($last, strlen($prefix));
            if (preg_match('/^\d+$/', $suffix)) {
                $next = (int)$suffix + 1;
            }
        }

        return $prefix . str_pad((string)$next, 6, '0', STR_PAD_LEFT);
    }

    private function isDuplicateKey(PDOException $e): bool
    {
        return isset($e->errorInfo[1]) && (int)$e->errorInfo[1] === 1062;
    }

    public function listForSelect(bool $onlyActive = true, bool $onlyValidated = true): array
    {
        if (!$this->ready) {
            return [];
        }

        $this->refreshDisbursementSelectionBlocks();
        $sql = 'SELECT * FROM fournisseur_banamur';
        $where = ['archived_at IS NULL', "(supplier_type<>'physique' OR (identity_document_number IS NOT NULL AND TRIM(identity_document_number)<>'' AND cni_path IS NOT NULL AND TRIM(cni_path)<>'' AND NOT EXISTS(SELECT 1 FROM personnel p WHERE REGEXP_REPLACE(UPPER(TRIM(COALESCE(p.num_cni,''))),'[^A-Z0-9]','')=fournisseur_banamur.identity_document_number) AND NOT EXISTS(SELECT 1 FROM personnel_identity_registry pir WHERE pir.identity_number=fournisseur_banamur.identity_document_number)))"];
        if ($onlyActive) {
            $where[] = 'actif = 1';
        }
        if ($onlyValidated) {
            $where[] = "validation_status = 'approved'";
        }
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= " ORDER BY COALESCE(NULLIF(nom_commercial, ''), NULLIF(raison_sociale, '')) ASC LIMIT 500";

        try {
            $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $rows=$this->hydrateCategoryData($rows);
            if($onlyValidated){
                require_once __DIR__.'/../services/SupplierPaymentGuard.php';
                $rows=array_values(array_filter($rows,fn(array $row):bool=>!SupplierPaymentGuard::missing($this->pdo,$row)));
            }
            return $rows;
        } catch (Throwable $e) {
            return [];
        }
    }

    public function getSupplierTypes(): array
    {
        return [self::TYPE_COMPANY, self::TYPE_INDIVIDUAL];
    }

    public function refreshDisbursementSelectionBlocks(): void
    {
        try {
            $this->pdo->exec("UPDATE fournisseur_banamur SET disbursement_selection_blocked=0,disbursement_block_reason=NULL,disbursement_block_fiches=NULL");
            $statement = $this->pdo->prepare("UPDATE fournisseur_banamur s INNER JOIN (
                SELECT f.fournisseur_id,GROUP_CONCAT(DISTINCT f.num_fiche ORDER BY f.num_fiche SEPARATOR ', ') fiches,
                  MAX(CASE WHEN COALESCE(r.payment_proof_path,'')='' THEN 1 ELSE 0 END) missing_proof,
                  MAX(CASE WHEN COALESCE(f.facture_reference,'')='' THEN 1 ELSE 0 END) missing_invoice
                FROM fiche_decaissement f
                INNER JOIN disbursement_regularizations r ON BINARY r.num_fiche=BINARY f.num_fiche
                WHERE f.decaisse=1 AND f.fournisseur_id IS NOT NULL AND f.fournisseur_id>0
                  AND r.cancelled_at IS NULL
                  AND DATE(COALESCE(f.date_decaisse,f.date_decaissement,f.date_creat_fiche))>=:block_start_date
                  AND (COALESCE(r.payment_proof_path,'')='' OR COALESCE(f.facture_reference,'')='')
                GROUP BY f.fournisseur_id
              ) x ON x.fournisseur_id=s.id_fournisseur
              SET s.disbursement_selection_blocked=1,s.disbursement_block_fiches=LEFT(x.fiches,500),
                s.disbursement_block_reason=CASE WHEN x.missing_proof=1 AND x.missing_invoice=1 THEN 'Preuve de paiement et facture/FNE à fournir' WHEN x.missing_proof=1 THEN 'Preuve de paiement à fournir' ELSE 'Facture/FNE à fournir' END");
            $statement->execute(['block_start_date' => self::DISBURSEMENT_COMPLIANCE_BLOCK_START_DATE]);
        } catch (Throwable $ignored) {
        }
    }

    public function listCategories(bool $onlyActive = true): array
    {
        if (!$this->ready) {
            return [];
        }

        $sql = 'SELECT id_category, label, description, actif FROM fournisseur_category_banamur';
        if ($onlyActive) {
            $sql .= ' WHERE actif = 1';
        }
        $sql .= ' ORDER BY label ASC';

        try {
            return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    public function listLegalForms(bool $onlyActive = true): array
    {
        if (!$this->ready) {
            return [];
        }

        $sql = 'SELECT id_legal_form, label, description, sort_order, actif FROM fournisseur_legal_form_banamur';
        if ($onlyActive) {
            $sql .= ' WHERE actif = 1';
        }
        $sql .= ' ORDER BY sort_order ASC, label ASC';

        try {
            return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    public function listLegalFormsWithUsage(bool $onlyActive = false): array
    {
        if (!$this->ready) {
            return [];
        }

        $sql = "SELECT lf.id_legal_form, lf.label, lf.description, lf.sort_order, lf.actif,
                       COUNT(f.id_fournisseur) AS supplier_count
                FROM fournisseur_legal_form_banamur lf
                LEFT JOIN fournisseur_banamur f ON f.raison_sociale = lf.label";
        if ($onlyActive) {
            $sql .= ' WHERE lf.actif = 1';
        }
        $sql .= ' GROUP BY lf.id_legal_form, lf.label, lf.description, lf.sort_order, lf.actif ORDER BY lf.sort_order ASC, lf.label ASC';

        try {
            return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    public function getLegalFormById(int $id): ?array
    {
        if (!$this->ready || $id <= 0) {
            return null;
        }

        try {
            $stmt = $this->pdo->prepare('SELECT id_legal_form, label, description, sort_order, actif FROM fournisseur_legal_form_banamur WHERE id_legal_form = :id LIMIT 1');
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    public function createLegalForm(array $data): array
    {
        if (!$this->ready) {
            return ['ok' => false, 'error' => $this->initError ?? 'Module indisponible.'];
        }

        $label = $this->asNullableString($data['label'] ?? null);
        if ($label === null) {
            return ['ok' => false, 'error' => 'Le libellé de la forme juridique est requis.'];
        }

        $payload = [
            'label' => $label,
            'description' => $this->asNullableString($data['description'] ?? null),
            'sort_order' => max(0, (int) ($data['sort_order'] ?? 0)),
            'actif' => !empty($data['actif']) ? 1 : 0,
        ];

        try {
            $stmt = $this->pdo->prepare('INSERT INTO fournisseur_legal_form_banamur (label, description, sort_order, actif) VALUES (:label, :description, :sort_order, :actif)');
            $stmt->execute($payload);
            return ['ok' => true, 'id' => (int) $this->pdo->lastInsertId()];
        } catch (PDOException $e) {
            return ['ok' => false, 'error' => $this->isDuplicateKey($e) ? 'Cette forme juridique existe déjà.' : 'Impossible de créer la forme juridique.'];
        }
    }

    public function updateLegalForm(int $id, array $data): array
    {
        if (!$this->ready || $id <= 0) {
            return ['ok' => false, 'error' => 'Forme juridique invalide.'];
        }

        $label = $this->asNullableString($data['label'] ?? null);
        if ($label === null) {
            return ['ok' => false, 'error' => 'Le libellé de la forme juridique est requis.'];
        }

        $payload = [
            'id' => $id,
            'label' => $label,
            'description' => $this->asNullableString($data['description'] ?? null),
            'sort_order' => max(0, (int) ($data['sort_order'] ?? 0)),
            'actif' => !empty($data['actif']) ? 1 : 0,
        ];

        try {
            $stmt = $this->pdo->prepare('UPDATE fournisseur_legal_form_banamur SET label = :label, description = :description, sort_order = :sort_order, actif = :actif WHERE id_legal_form = :id');
            $stmt->execute($payload);
            return ['ok' => true];
        } catch (PDOException $e) {
            return ['ok' => false, 'error' => $this->isDuplicateKey($e) ? 'Cette forme juridique existe déjà.' : 'Impossible de mettre à jour la forme juridique.'];
        }
    }

    public function deleteLegalForm(int $id): array
    {
        if (!$this->ready || $id <= 0) {
            return ['ok' => false, 'error' => 'Forme juridique invalide.'];
        }

        try {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM fournisseur_banamur WHERE raison_sociale = (SELECT label FROM fournisseur_legal_form_banamur WHERE id_legal_form = :id LIMIT 1)');
            $stmt->execute(['id' => $id]);
            $usageCount = (int) $stmt->fetchColumn();
            if ($usageCount > 0) {
                return ['ok' => false, 'error' => 'Cette forme juridique est déjà utilisée. Désactivez-la au lieu de la supprimer.'];
            }

            $delete = $this->pdo->prepare('DELETE FROM fournisseur_legal_form_banamur WHERE id_legal_form = :id');
            $delete->execute(['id' => $id]);
            return ['ok' => true];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Impossible de supprimer la forme juridique.'];
        }
    }

    public function listCategoriesWithUsage(bool $onlyActive = false): array
    {
        if (!$this->ready) {
            return [];
        }

        $sql = "SELECT c.id_category, c.label, c.description, c.actif, c.created_at, c.updated_at,
                       COUNT(l.fournisseur_id) AS supplier_count
                FROM fournisseur_category_banamur c
                LEFT JOIN fournisseur_category_link_banamur l ON l.category_id = c.id_category";
        if ($onlyActive) {
            $sql .= ' WHERE c.actif = 1';
        }
        $sql .= ' GROUP BY c.id_category, c.label, c.description, c.actif, c.created_at, c.updated_at ORDER BY c.label ASC';

        try {
            return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    public function getCategoryById(int $id): ?array
    {
        if (!$this->ready || $id <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT id_category, label, description, actif FROM fournisseur_category_banamur WHERE id_category = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function createCategory(array $data): array
    {
        if (!$this->ready) {
            return ['ok' => false, 'error' => $this->initError ?? 'Module indisponible.'];
        }

        $label = trim((string) ($data['label'] ?? ''));
        if ($label === '') {
            return ['ok' => false, 'error' => 'Le libellé de catégorie est requis.'];
        }

        $description = $this->asNullableString($data['description'] ?? null);
        $actif = !empty($data['actif']) ? 1 : 0;

        try {
            $stmt = $this->pdo->prepare('INSERT INTO fournisseur_category_banamur (label, description, actif) VALUES (:label, :description, :actif)');
            $stmt->execute([
                'label' => $label,
                'description' => $description,
                'actif' => $actif,
            ]);
            return ['ok' => true, 'id' => (int) $this->pdo->lastInsertId()];
        } catch (PDOException $e) {
            if ($this->isDuplicateKey($e)) {
                    if (str_contains($e->getMessage(), 'uq_supplier_identity_key')) return ['ok'=>false,'error'=>'Cet identifiant appartient déjà à un fournisseur.'];
                    if (str_contains($e->getMessage(), 'uq_supplier_siret')) return ['ok' => false, 'error' => 'Ce SIRET est déjà attribué à un fournisseur.'];
                    if (str_contains($e->getMessage(), 'uq_supplier_ncc_identity')) return ['ok' => false, 'error' => 'Ce NCC est déjà attribué à un fournisseur.'];
                return ['ok' => false, 'error' => 'Cette catégorie existe déjà.'];
            }
            return ['ok' => false, 'error' => 'Erreur SQL lors de la création de la catégorie.'];
        }
    }

    public function updateCategory(int $id, array $data): array
    {
        if (!$this->ready || $id <= 0) {
            return ['ok' => false, 'error' => 'Catégorie invalide.'];
        }

        $current = $this->getCategoryById($id);
        if (!$current) {
            return ['ok' => false, 'error' => 'Catégorie introuvable.'];
        }

        $label = trim((string) ($data['label'] ?? ''));
        if ($label === '') {
            return ['ok' => false, 'error' => 'Le libellé de catégorie est requis.'];
        }

        try {
            $stmt = $this->pdo->prepare('UPDATE fournisseur_category_banamur SET label = :label, description = :description, actif = :actif WHERE id_category = :id');
            $stmt->execute([
                'id' => $id,
                'label' => $label,
                'description' => $this->asNullableString($data['description'] ?? null),
                'actif' => !empty($data['actif']) ? 1 : 0,
            ]);
            return ['ok' => true];
        } catch (PDOException $e) {
            if ($this->isDuplicateKey($e)) {
                    if (str_contains($e->getMessage(), 'uq_supplier_identity_key')) return ['ok'=>false,'error'=>'Cet identifiant appartient déjà à un fournisseur.'];
                    if (str_contains($e->getMessage(), 'uq_supplier_siret')) return ['ok' => false, 'error' => 'Ce SIRET est déjà attribué à un fournisseur.'];
                    if (str_contains($e->getMessage(), 'uq_supplier_ncc_identity')) return ['ok' => false, 'error' => 'Ce NCC est déjà attribué à un fournisseur.'];
                return ['ok' => false, 'error' => 'Une autre catégorie porte déjà ce libellé.'];
            }
            return ['ok' => false, 'error' => 'Erreur SQL lors de la mise à jour de la catégorie.'];
        }
    }

    public function deleteCategory(int $id): array
    {
        if (!$this->ready || $id <= 0) {
            return ['ok' => false, 'error' => 'Catégorie invalide.'];
        }

        try {
            $countStmt = $this->pdo->prepare('SELECT COUNT(*) FROM fournisseur_category_link_banamur WHERE category_id = :id');
            $countStmt->execute(['id' => $id]);
            $usageCount = (int) ($countStmt->fetchColumn() ?: 0);
            if ($usageCount > 0) {
                return ['ok' => false, 'error' => 'Impossible de supprimer une catégorie déjà liée à des fournisseurs.'];
            }

            $deleteStmt = $this->pdo->prepare('DELETE FROM fournisseur_category_banamur WHERE id_category = :id');
            $deleteStmt->execute(['id' => $id]);
            return ['ok' => $deleteStmt->rowCount() > 0, 'deleted' => $deleteStmt->rowCount() > 0];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Erreur lors de la suppression de la catégorie.'];
        }
    }

    public function getSupplierDisplayContext(?int $supplierId, array $fallback = []): array
    {
        $context = [
            'supplier_id' => $supplierId ? (int) $supplierId : null,
            'supplier_name' => $this->asNullableString($fallback['fournisseur_nom'] ?? null),
            'supplier_contact' => $this->asNullableString($fallback['fournisseur_contact_nom'] ?? null),
            'supplier_phone' => $this->asNullableString($fallback['fournisseur_telephone'] ?? null),
            'supplier_email' => null,
            'supplier_address' => $this->asNullableString($fallback['fournisseur_adresse'] ?? ($fallback['adresse'] ?? null)),
            'supplier_type' => null,
            'supplier_type_label' => null,
            'supplier_legal_form' => null,
            'supplier_person_last_name' => null,
            'supplier_person_first_name' => null,
            'supplier_categories' => [],
            'supplier_categories_text' => null,
            'supplier_visual_path' => null,
            'supplier_cni_path' => null,
            'supplier_documents' => [],
            'supplier_documents_text' => null,
            'supplier_required_documents' => [],
            'supplier_missing_documents' => [],
            'supplier_document_completion_label' => null,
            'supplier_document_completion_percent' => 0,
            'supplier_document_completion_tone' => 'danger',
            'supplier_document_is_complete' => false,
        ];

        if ($supplierId && $supplierId > 0) {
            $supplier = $this->getById((int) $supplierId);
            if ($supplier) {
                $context['supplier_name'] = $this->getSupplierDisplayName($supplier) ?: $context['supplier_name'];
                $context['supplier_contact'] = $this->asNullableString($supplier['contact_nom'] ?? null) ?: $context['supplier_contact'];
                $context['supplier_phone'] = $this->asNullableString($supplier['telephone'] ?? null) ?: $context['supplier_phone'];
                $context['supplier_email'] = $this->asNullableString($supplier['email'] ?? null);
                $context['supplier_address'] = $this->asNullableString($supplier['adresse'] ?? null) ?: $context['supplier_address'];
                $context['supplier_type'] = $this->normalizeSupplierType($supplier['supplier_type'] ?? null);
                $context['supplier_type_label'] = $supplier['supplier_type_label'] ?? ($context['supplier_type'] === self::TYPE_INDIVIDUAL ? 'Personne physique' : 'Personne morale');
                $context['supplier_legal_form'] = $this->asNullableString($supplier['raison_sociale'] ?? null);
                $context['supplier_person_last_name'] = $this->asNullableString($supplier['person_last_name'] ?? null);
                $context['supplier_person_first_name'] = $this->asNullableString($supplier['person_first_name'] ?? null);
                $context['supplier_categories'] = array_values($supplier['category_labels'] ?? []);
                $context['supplier_categories_text'] = $supplier['category_names'] ?? null;
                $context['supplier_visual_path'] = $this->asNullableString($supplier['visual_path'] ?? null);
                $context['supplier_cni_path'] = $this->asNullableString($supplier['cni_path'] ?? null);
                $context['supplier_documents'] = $this->buildSupplierDocumentList($supplier);
                $context['supplier_documents_text'] = implode(', ', array_map(static fn(array $document): string => (string) ($document['label'] ?? ''), $context['supplier_documents']));
                $completeness = $this->buildDocumentCompletenessSummary($supplier);
                $context['supplier_required_documents'] = $completeness['required_labels'];
                $context['supplier_missing_documents'] = $completeness['missing_labels'];
                $context['supplier_document_completion_label'] = $completeness['label'];
                $context['supplier_document_completion_percent'] = $completeness['percent'];
                $context['supplier_document_completion_tone'] = $completeness['tone'];
                $context['supplier_document_is_complete'] = $completeness['is_complete'];
                if (($supplier['supplier_type']??'')===self::TYPE_INDIVIDUAL && !empty($supplier['identity_document_number']) && PersonIdentityGuard::employeeExists($this->pdo,(string)$supplier['identity_document_number'])) { $context['supplier_document_is_complete']=false; $context['supplier_missing_documents'][]=PersonIdentityGuard::EMPLOYEE_MESSAGE; }
            }
        }

        if ($context['supplier_name'] === null) {
            $context['supplier_name'] = $this->getSupplierDisplayName($fallback);
        }

        return $context;
    }

    private function asNullableString($value): ?string
    {
        $s = trim((string)($value ?? ''));
        return $s === '' ? null : $s;
    }

    private function getSupplierDisplayName(array $row): ?string
    {
        $individualName = trim(implode(' ', array_filter([
            $this->asNullableString($row['person_last_name'] ?? null),
            $this->asNullableString($row['person_first_name'] ?? null),
        ])));

        return $this->asNullableString($row['nom_commercial'] ?? null)
            ?: ($individualName !== '' ? $individualName : null)
            ?: $this->asNullableString($row['supplier_display_name'] ?? null)
            ?: $this->asNullableString($row['fournisseur_nom'] ?? null)
            ?: $this->asNullableString($row['raison_sociale'] ?? null);
    }

    public function approve(int $id, int $validatorUserId, ?string $note = null): array
    {
        return $this->changeValidationStatus($id, self::STATUS_APPROVED, $validatorUserId, $note);
    }

    public function reject(int $id, int $validatorUserId, ?string $note = null): array
    {
        return $this->changeValidationStatus($id, self::STATUS_REJECTED, $validatorUserId, $note);
    }

    private function changeValidationStatus(int $id, string $status, int $validatorUserId, ?string $note = null): array
    {
        $current=$this->getById($id)??[];
        if ($status!==self::STATUS_APPROVED) return $this->persistValidationStatus($id,$status,$validatorUserId,$note);
        return PersonIdentityGuard::supplierWrite($this->pdo,$current,$current,fn()=>$this->persistValidationStatus($id,$status,$validatorUserId,$note));
    }

    private function persistValidationStatus(int $id, string $status, int $validatorUserId, ?string $note = null): array
    {
        if (!$this->ready || $id <= 0 || $validatorUserId <= 0 || !in_array($status, $this->getValidationStatuses(), true)) {
            return ['ok' => false, 'error' => 'Validation fournisseur invalide.'];
        }

        $current=$this->getById($id);
        if (!$current) return ['ok'=>false,'error'=>'Fournisseur introuvable.'];
        if ($status===self::STATUS_APPROVED && ($current['supplier_type']??'morale')===self::TYPE_INDIVIDUAL) {
            try {PersonIdentityGuard::verifySupplier($this->pdo,$current,$current); if (empty($current['cni_path'])) throw new DomainException('La pièce d’identité jointe est obligatoire.');}
            catch (DomainException $e) {return ['ok'=>false,'error'=>$e->getMessage()];}
        }
        if (SupplierExceptionPolicy::isException($current)) {
            try { SupplierExceptionPolicy::requireAllowed($validatorUserId); }
            catch (DomainException $e) { return ['ok'=>false,'error'=>$e->getMessage()]; }
        }
        if ($status===self::STATUS_APPROVED && ($current['identity_status']??'')==='documentary_pending' && empty($current['identity_evidence'])) {
            return ['ok'=>false,'error'=>'Renseignez et enregistrez une référence de justificatif avant la validation documentaire.'];
        }
        if($status===self::STATUS_APPROVED){
            require_once __DIR__.'/../services/SupplierPaymentGuard.php';
            $candidate=array_replace($current,['validation_status'=>'approved']);
            if(($candidate['identity_status']??'')==='documentary_pending'&&!empty($candidate['identity_evidence'])){
                $candidate['identity_status']='documentary_verified';$candidate['identity_checked_by']=$validatorUserId;
            }
            try{$anomalies=SupplierPaymentGuard::missing($this->pdo,$candidate,null,$this->pdo->inTransaction());}
            catch(Throwable $e){return ['ok'=>false,'error'=>'Validation fournisseur bloquée : contrôle indisponible.'];}
            if($anomalies)return ['ok'=>false,'error'=>'Validation fournisseur bloquée : '.implode(' ; ',$anomalies).'.'];
        }
        $identitySql=$status===self::STATUS_APPROVED ? ", identity_status=CASE WHEN identity_status='documentary_pending' THEN 'documentary_verified' ELSE identity_status END, identity_checked_by=:identity_actor, identity_checked_at=NOW()" : '';
        $st = $this->pdo->prepare('UPDATE fournisseur_banamur SET validation_status = :status, validation_note = :note, validated_by_user_id = :validator, validated_at = NOW()'.$identitySql." WHERE id_fournisseur = :id AND COALESCE(identity_snapshot,'')=:expected_identity");
        $st->execute([
            'expected_identity'=>(string)($current['identity_snapshot']??''),
            ...($status===self::STATUS_APPROVED ? ['identity_actor'=>$validatorUserId] : []),
            'status' => $status,
            'note' => $this->asNullableString($note),
            'validator' => $validatorUserId,
            'id' => $id,
        ]);

        return ['ok' => $st->rowCount() > 0];
    }

    public function listIncidents(int $fournisseurId): array
    {
        if (!$this->ready || $fournisseurId <= 0) {
            return [];
        }

        $st = $this->pdo->prepare('SELECT * FROM fournisseur_incident_banamur WHERE fournisseur_id = :id ORDER BY incident_date DESC, id_incident DESC');
        $st->execute(['id' => $fournisseurId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function addIncident(int $fournisseurId, array $data, ?int $userId = null): array
    {
        if (!$this->ready || $fournisseurId <= 0) {
            return ['ok' => false, 'error' => 'Fournisseur invalide.'];
        }

        $titre = trim((string)($data['titre'] ?? ''));
        $description = trim((string)($data['description'] ?? ''));
        $incidentDate = trim((string)($data['incident_date'] ?? ''));
        $gravite = strtolower(trim((string)($data['gravite'] ?? 'medium')));
        if ($titre === '' || $description === '') {
            return ['ok' => false, 'error' => 'Titre et description de l\'incident sont requis.'];
        }
        if ($incidentDate === '') {
            $incidentDate = date('Y-m-d');
        }
        if (!in_array($gravite, ['low', 'medium', 'high', 'critical'], true)) {
            $gravite = 'medium';
        }

        $st = $this->pdo->prepare('INSERT INTO fournisseur_incident_banamur (fournisseur_id, incident_date, titre, description, gravite, statut, created_by_user_id) VALUES (:fournisseur_id, :incident_date, :titre, :description, :gravite, :statut, :created_by_user_id)');
        $st->execute([
            'fournisseur_id' => $fournisseurId,
            'incident_date' => $incidentDate,
            'titre' => $titre,
            'description' => $description,
            'gravite' => $gravite,
            'statut' => 'open',
            'created_by_user_id' => $userId ?: null,
        ]);

        return ['ok' => true, 'id' => (int)$this->pdo->lastInsertId()];
    }

    public function getValidationStatuses(): array
    {
        return [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED];
    }

    private function normalizeSupplierType($value): string
    {
        $type = trim((string) ($value ?? ''));
        return in_array($type, $this->getSupplierTypes(), true) ? $type : self::TYPE_COMPANY;
    }

    private function processSupplierAssets(array $files, array $current, string $supplierType): array
    {
        $paths = [
            'logo_path' => $this->asNullableString($current['logo_path'] ?? null),
            'photo_path' => $this->asNullableString($current['photo_path'] ?? null),
            'cni_path' => $this->asNullableString($current['cni_path'] ?? null),
            'trade_register_path' => $this->asNullableString($current['trade_register_path'] ?? null),
            'tax_existence_path' => $this->asNullableString($current['tax_existence_path'] ?? null),
            'tax_clearance_path' => $this->asNullableString($current['tax_clearance_path'] ?? null),
            'social_clearance_path' => $this->asNullableString($current['social_clearance_path'] ?? null),
            'manager_cni_path' => $this->asNullableString($current['manager_cni_path'] ?? null),
        ];

        if ($supplierType === self::TYPE_COMPANY) {
            $uploadedLogo = $this->storeUploadedFile($files['supplier_logo'] ?? null, 'uploads/fournisseurs/logos');
            if ($uploadedLogo !== null) {
                $paths['logo_path'] = $uploadedLogo;
            }
            foreach (self::COMPANY_DOCUMENT_FIELDS as $inputName => $config) {
                $uploadedFile = $this->storeUploadedFile($files[$inputName] ?? null, $config['directory']);
                if ($uploadedFile !== null) {
                    $paths[$config['column']] = $uploadedFile;
                }
            }
        } else {
            $uploadedPhoto = $this->storeUploadedFile($files['supplier_photo'] ?? null, 'uploads/fournisseurs/photos');
            $uploadedCni = $this->storeUploadedFile($files['supplier_cni'] ?? null, 'uploads/fournisseurs/cni');
            if ($uploadedPhoto !== null) {
                $paths['photo_path'] = $uploadedPhoto;
            }
            if ($uploadedCni !== null) {
                $paths['cni_path'] = $uploadedCni;
            }
        }

        return $paths;
    }

    private function storeUploadedFile($file, string $relativeDirectory): ?string
    {
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return null;
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        $originalName = (string) ($file['name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            return null;
        }

        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, ['png', 'jpg', 'jpeg', 'webp', 'pdf'], true)) {
            return null;
        }

        $targetDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDirectory);
        if (!is_dir($targetDirectory)) {
            @mkdir($targetDirectory, 0775, true);
        }
        if (!is_dir($targetDirectory)) {
            return null;
        }

        $fileName = 'supplier_' . date('YmdHis') . '_' . uniqid('', true) . '.' . $extension;
        $targetFile = $targetDirectory . DIRECTORY_SEPARATOR . $fileName;
        if (!@move_uploaded_file($tmpName, $targetFile)) {
            return null;
        }

        return str_replace('\\', '/', $relativeDirectory . '/' . $fileName);
    }

    private function syncSupplierCategories(int $supplierId, array $data): void
    {
        if ($supplierId <= 0) {
            return;
        }

        $categoryIds = $data['category_ids'] ?? [];
        if (!is_array($categoryIds)) {
            $categoryIds = [$categoryIds];
        }

        $normalizedIds = [];
        foreach ($categoryIds as $categoryId) {
            $categoryId = (int) $categoryId;
            if ($categoryId > 0) {
                $normalizedIds[$categoryId] = $categoryId;
            }
        }

        $this->pdo->prepare('DELETE FROM fournisseur_category_link_banamur WHERE fournisseur_id = :supplier_id')->execute(['supplier_id' => $supplierId]);
        if (!$normalizedIds) {
            return;
        }

        $insert = $this->pdo->prepare('INSERT IGNORE INTO fournisseur_category_link_banamur (fournisseur_id, category_id) VALUES (:supplier_id, :category_id)');
        foreach (array_values($normalizedIds) as $categoryId) {
            $insert->execute([
                'supplier_id' => $supplierId,
                'category_id' => $categoryId,
            ]);
        }
    }

    private function runPostSaveTasks(int $supplierId, array $data, array $payload, ?int $updatedBy = null): void
    {
        try {
            $this->syncSupplierCategories($supplierId, $data);
        } catch (Throwable $e) {
        }

        try {
            $this->syncSupplierPositioning($supplierId, $payload, $updatedBy);
        } catch (Throwable $e) {
        }
    }

    private function ensureCategoryExists(string $label): int
    {
        $label = trim($label);
        if ($label === '') {
            return 0;
        }

        try {
            $select = $this->pdo->prepare('SELECT id_category FROM fournisseur_category_banamur WHERE label = :label LIMIT 1');
            $select->execute(['label' => $label]);
            $existingId = (int) ($select->fetchColumn() ?: 0);
            if ($existingId > 0) {
                return $existingId;
            }

            $insert = $this->pdo->prepare('INSERT INTO fournisseur_category_banamur (label, actif) VALUES (:label, 1)');
            $insert->execute(['label' => $label]);
            return (int) $this->pdo->lastInsertId();
        } catch (Throwable $e) {
            return 0;
        }
    }

    private function hydrateCategoryData(array $rows): array
    {
        if (!$rows) {
            return [];
        }

        $supplierIds = array_values(array_unique(array_map(static function (array $row): int {
            return (int) ($row['id_fournisseur'] ?? 0);
        }, $rows)));
        $supplierIds = array_values(array_filter($supplierIds, static fn(int $id): bool => $id > 0));

        $categoriesBySupplier = [];
        if ($supplierIds) {
            try {
                $placeholders = implode(',', array_fill(0, count($supplierIds), '?'));
                $sql = "SELECT l.fournisseur_id, c.id_category, c.label
                        FROM fournisseur_category_link_banamur l
                        INNER JOIN fournisseur_category_banamur c ON c.id_category = l.category_id
                        WHERE l.fournisseur_id IN ($placeholders)
                        ORDER BY c.label ASC";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($supplierIds);
                $links = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                foreach ($links as $link) {
                    $supplierId = (int) ($link['fournisseur_id'] ?? 0);
                    if ($supplierId <= 0) {
                        continue;
                    }
                    if (!isset($categoriesBySupplier[$supplierId])) {
                        $categoriesBySupplier[$supplierId] = [
                            'category_ids' => [],
                            'category_labels' => [],
                        ];
                    }
                    $categoriesBySupplier[$supplierId]['category_ids'][] = (int) ($link['id_category'] ?? 0);
                    $categoriesBySupplier[$supplierId]['category_labels'][] = (string) ($link['label'] ?? '');
                }
            } catch (Throwable $e) {
            }
        }

        foreach ($rows as &$row) {
            $supplierId = (int) ($row['id_fournisseur'] ?? 0);
            $categoryData = $categoriesBySupplier[$supplierId] ?? ['category_ids' => [], 'category_labels' => []];
            $row['category_ids'] = $categoryData['category_ids'];
            $row['category_labels'] = array_values(array_filter($categoryData['category_labels'], static fn(string $value): bool => trim($value) !== ''));
            $row['category_names'] = implode(', ', $row['category_labels']);
            $row['supplier_type'] = $this->normalizeSupplierType($row['supplier_type'] ?? null);
            if ($row['supplier_type']===self::TYPE_INDIVIDUAL && !empty($row['identity_document_number'])) $row['personnel_identity_conflict']=PersonIdentityGuard::employeeExists($this->pdo,(string)$row['identity_document_number']);
            $row['supplier_type_label'] = $row['supplier_type'] === self::TYPE_INDIVIDUAL ? 'Personne physique' : 'Personne morale';
            $row['supplier_display_name'] = $this->getSupplierDisplayName($row);
            $row['supplier_legal_name'] = $this->asNullableString($row['raison_sociale'] ?? null);
            $row['visual_path'] = $row['supplier_type'] === self::TYPE_INDIVIDUAL
                ? ($this->asNullableString($row['photo_path'] ?? null))
                : ($this->asNullableString($row['logo_path'] ?? null));
            $row['document_labels'] = array_map(static fn(array $document): string => (string) ($document['label'] ?? ''), $this->buildSupplierDocumentList($row));
            $row['document_names'] = implode(', ', $row['document_labels']);
            $completeness = $this->buildDocumentCompletenessSummary($row);
            $row['required_document_labels'] = $completeness['required_labels'];
            $row['missing_document_labels'] = $completeness['missing_labels'];
            $row['required_document_count'] = $completeness['required_count'];
            $row['document_count'] = $completeness['available_count'];
            $row['document_completion_ratio'] = $completeness['ratio'];
            $row['document_completion_percent'] = $completeness['percent'];
            $row['document_completion_label'] = $completeness['label'];
            $row['document_completion_tone'] = $completeness['tone'];
            $row['document_is_complete'] = $completeness['is_complete'];
        }
        unset($row);

        return $rows;
    }

    public function getDecaissementReport(int $limit = 50): array
    {
        if (!$this->ready) {
            return ['available' => false, 'rows' => []];
        }

        try {
            $columns = $this->pdo->query('SHOW COLUMNS FROM fiche_decaissement')->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
            $columns = array_map('strval', $columns);
        } catch (Throwable $e) {
            return ['available' => false, 'rows' => []];
        }

        foreach (['fournisseur_id', 'fournisseur_nom', 'fournisseur_contact_nom', 'fournisseur_telephone'] as $requiredColumn) {
            if (!in_array($requiredColumn, $columns, true)) {
                return ['available' => false, 'rows' => []];
            }
        }

        $limit = max(1, min(200, $limit));

        $sql = "SELECT
                    f.fournisseur_id,
                    COALESCE(NULLIF(f.fournisseur_nom, ''), fb.raison_sociale) AS fournisseur_nom,
                    COALESCE(NULLIF(f.fournisseur_contact_nom, ''), fb.contact_nom) AS contact_nom,
                    COALESCE(NULLIF(f.fournisseur_telephone, ''), fb.telephone) AS telephone,
                    COUNT(*) AS nb_fiches,
                    SUM(COALESCE(f.montant_fiche, 0)) AS montant_total,
                    SUM(CASE WHEN COALESCE(f.decaisse, 0) = 1 THEN COALESCE(f.montant_fiche, 0) ELSE 0 END) AS montant_decaisse,
                    SUM(CASE WHEN COALESCE(f.approuve, 0) = 1 THEN COALESCE(f.montant_fiche, 0) ELSE 0 END) AS montant_approuve,
                    MAX(f.date_creat_fiche) AS derniere_fiche
                FROM fiche_decaissement f
                LEFT JOIN fournisseur_banamur fb ON fb.id_fournisseur = f.fournisseur_id
                LEFT JOIN chantier_banamur c ON c.id = f.chantier_id
                WHERE f.fournisseur_id IS NOT NULL
                  AND (f.chantier_id IS NULL OR f.chantier_id=0 OR c.archived_at IS NULL)
                GROUP BY
                    f.fournisseur_id,
                    COALESCE(NULLIF(f.fournisseur_nom, ''), fb.raison_sociale),
                    COALESCE(NULLIF(f.fournisseur_contact_nom, ''), fb.contact_nom),
                    COALESCE(NULLIF(f.fournisseur_telephone, ''), fb.telephone)
                ORDER BY montant_total DESC, nb_fiches DESC
                LIMIT " . (int)$limit;

        try {
            $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return ['available' => false, 'rows' => [], 'error' => $e->getMessage()];
        }

        return ['available' => true, 'rows' => $rows];
    }

    private function normalizeSupplierPayload(array $data, string $supplierType, array $current = []): array
    {
        $lastName = $this->asNullableString($data['person_last_name'] ?? ($current['person_last_name'] ?? null));
        $firstName = $this->asNullableString($data['person_first_name'] ?? ($current['person_first_name'] ?? null));
        $individualDisplayName = trim(implode(' ', array_filter([$lastName, $firstName])));

        $legalForm = $this->asNullableString($data['raison_sociale'] ?? ($current['raison_sociale'] ?? null));
        if ($supplierType === self::TYPE_INDIVIDUAL) {
            $legalForm = $legalForm ?: 'Personne physique';
        }

        $displayName = $this->asNullableString($data['nom_commercial'] ?? ($current['nom_commercial'] ?? null));
        if ($supplierType === self::TYPE_INDIVIDUAL && $displayName === null && $individualDisplayName !== '') {
            $displayName = $individualDisplayName;
        }

        $contactName = $this->asNullableString($data['contact_nom'] ?? ($current['contact_nom'] ?? null));
        if ($supplierType === self::TYPE_INDIVIDUAL && $contactName === null && $individualDisplayName !== '') {
            $contactName = $individualDisplayName;
        }

        return [
            'raison_sociale' => $legalForm,
            'nom_commercial' => $displayName,
            'person_last_name' => $lastName,
            'person_first_name' => $firstName,
            'contact_nom' => $contactName,
            'ncc' => $this->asNullableString($data['ncc'] ?? ($current['ncc'] ?? null)),
            'rne' => $this->asNullableString($data['rne'] ?? ($current['rne'] ?? null)),
            'is_rne' => !empty($data['is_rne']) ? 1 : 0,
            'email' => $this->asNullableString($data['email'] ?? ($current['email'] ?? null)),
            'telephone' => $this->asNullableString($data['telephone'] ?? ($current['telephone'] ?? null)),
            'adresse' => $this->asNullableString($data['adresse'] ?? ($current['adresse'] ?? null)),
            'ville' => $this->asNullableString($data['ville'] ?? ($current['ville'] ?? null)),
            'pays' => $this->asNullableString($data['pays'] ?? ($current['pays'] ?? null)),
        ];
    }

    private function validateSupplierPayload(array $payload, array $assetPaths, string $supplierType, array $identity = []): ?string
    {
        if ($supplierType===self::TYPE_INDIVIDUAL && (empty($identity['identity_document_number']) || empty($assetPaths['cni_path']))) return 'Le numéro et la pièce d’identité jointe sont obligatoires pour une personne physique.';
        if (SupplierExceptionPolicy::isException($identity)) return empty($payload['nom_commercial']) ? 'Renseignez le nom du fournisseur.' : null;
        if ($supplierType === self::TYPE_COMPANY) {
            if ($payload['raison_sociale'] === null) {
                return 'La forme juridique est requise pour une personne morale.';
            }
            if ($payload['nom_commercial'] === null) {
                return 'La dénomination affichée est requise pour une personne morale.';
            }
            if ($payload['contact_nom'] === null) {
                return 'Le nom du gérant ou du représentant légal est requis.';
            }
            if ($payload['email'] === null) {
                return 'Au moins un mail est requis pour une personne morale.';
            }
            if ($payload['telephone'] === null) {
                return 'Le téléphone est requis pour une personne morale.';
            }
            if ($payload['adresse'] === null) {
                return 'L\'adresse / localisation est requise pour une personne morale.';
            }

            foreach (self::COMPANY_REQUIRED_DOCUMENT_FIELDS as $config) {
                if (empty($assetPaths[$config['column']])) {
                    return 'Le document obligatoire suivant est manquant : ' . $config['label'] . '.';
                }
            }

            return null;
        }

        if ($payload['person_last_name'] === null) {
            return 'Le nom est requis pour une personne physique.';
        }
        if ($payload['person_first_name'] === null) {
            return 'Le prénom est requis pour une personne physique.';
        }
        if ($payload['email'] === null) {
            return 'L\'email est requis pour une personne physique.';
        }
        if ($payload['telephone'] === null) {
            return 'Le téléphone est requis pour une personne physique.';
        }
        if ($payload['adresse'] === null) {
            return 'L\'adresse / localisation est requise pour une personne physique.';
        }
        foreach (self::INDIVIDUAL_DOCUMENT_FIELDS as $config) {
            if (empty($assetPaths[$config['column']])) {
                return 'Le document obligatoire suivant est manquant : ' . $config['label'] . '.';
            }
        }

        return null;
    }

    private function buildSupplierDocumentList(array $supplier): array
    {
        $documents = [];
        $type = $this->normalizeSupplierType($supplier['supplier_type'] ?? null);

        if ($type === self::TYPE_COMPANY) {
            foreach (self::COMPANY_REQUIRED_DOCUMENT_FIELDS as $config) {
                $path = $this->asNullableString($supplier[$config['column']] ?? null);
                if ($path !== null) {
                    $documents[] = [
                        'label' => $config['label'],
                        'path' => $path,
                    ];
                }
            }

            foreach (self::COMPANY_DOCUMENT_FIELDS as $config) {
                $path = $this->asNullableString($supplier[$config['column']] ?? null);
                if ($path !== null) {
                    $documents[] = [
                        'label' => $config['label'],
                        'path' => $path,
                    ];
                }
            }

            return $documents;
        }

        foreach (self::INDIVIDUAL_DOCUMENT_FIELDS as $config) {
            $path = $this->asNullableString($supplier[$config['column']] ?? null);
            if ($path !== null) {
                $documents[] = [
                    'label' => $config['label'],
                    'path' => $path,
                ];
            }
        }

        return $documents;
    }

    private function buildDocumentCompletenessSummary(array $supplier): array
    {
        if (SupplierExceptionPolicy::isException($supplier)) {
            $missing=SupplierIdentity::missing($supplier); $ok=!$missing;
            return ['required_labels'=>$missing,'missing_labels'=>$missing,'required_count'=>count($missing),'available_count'=>0,'ratio'=>$ok?1:0,'percent'=>$ok?100:0,'label'=>$ok?'Dérogation autorisée — contrôles non effectués':'Dérogation à régulariser','tone'=>'warning','is_complete'=>$ok];
        }
        $type = $this->normalizeSupplierType($supplier['supplier_type'] ?? null);
        $requiredMap = $type === self::TYPE_COMPANY ? self::COMPANY_REQUIRED_DOCUMENT_FIELDS : self::INDIVIDUAL_DOCUMENT_FIELDS;
        $requiredLabels = array_map(static fn(array $config): string => (string) ($config['label'] ?? ''), $requiredMap);
        $availableLabels = array_map(static fn(array $document): string => (string) ($document['label'] ?? ''), $this->buildSupplierDocumentList($supplier));
        $missingLabels = array_values(array_diff($requiredLabels, $availableLabels));
        foreach (SupplierIdentity::missing($supplier) as $label) {
            $requiredLabels[] = $label;
            $missingLabels[] = $label;
        }
        $requiredCount = count($requiredLabels);
        $availableCount = count(array_intersect($requiredLabels, $availableLabels));
        $ratio = $requiredCount > 0 ? min(1, $availableCount / $requiredCount) : 1;
        $isComplete = $requiredCount === 0 ? true : $availableCount >= $requiredCount && !$missingLabels;

        return [
            'required_labels' => $requiredLabels,
            'missing_labels' => $missingLabels,
            'required_count' => $requiredCount,
            'available_count' => $availableCount,
            'ratio' => $ratio,
            'percent' => (int) round($ratio * 100),
            'label' => $isComplete ? 'Dossier complet' : ('Dossier incomplet (' . $availableCount . '/' . $requiredCount . ')'),
            'tone' => $isComplete ? 'success' : ($availableCount > 0 ? 'warning' : 'danger'),
            'is_complete' => $isComplete,
        ];
    }

    private function syncSupplierPositioning(int $supplierId, array $payload, ?int $updatedBy = null): void
    {
        if ($supplierId <= 0) {
            return;
        }

        try {
            $positioning = new \EntityPositioningManager($this->pdo);
            $settings = $positioning->getSettings('supplier');
            if (empty($settings['api_enabled'])) {
                return;
            }

            $manualLatitude = isset($payload['location_latitude']) && is_numeric($payload['location_latitude'])
                ? (float) $payload['location_latitude']
                : null;
            $manualLongitude = isset($payload['location_longitude']) && is_numeric($payload['location_longitude'])
                ? (float) $payload['location_longitude']
                : null;
            $manualSource = trim((string) ($payload['location_source'] ?? ''));
            $manualLabel = trim((string) ($payload['location_label'] ?? ''));

            $addressParts = array_filter([
                trim((string) ($payload['adresse'] ?? '')),
                trim((string) ($payload['ville'] ?? '')),
                trim((string) ($payload['pays'] ?? '')),
            ], static fn(string $value): bool => $value !== '');
            if (!$addressParts) {
                return;
            }

            $address = implode(', ', $addressParts);

            if ($manualLatitude !== null && $manualLongitude !== null) {
                $positioning->upsertPosition('supplier', $supplierId, [
                    'location_label' => $manualLabel !== '' ? $manualLabel : ($payload['nom_commercial'] ?? $payload['raison_sociale'] ?? 'Fournisseur'),
                    'address_snapshot' => $address,
                    'latitude' => $manualLatitude,
                    'longitude' => $manualLongitude,
                    'source' => $manualSource !== '' ? $manualSource : 'manual',
                ], $updatedBy);
                return;
            }

            if (empty($settings['geocoding_enabled'])) {
                return;
            }

            $geocoded = $positioning->geocodeAddress($address);
            if (!$geocoded) {
                return;
            }

            $positioning->upsertPosition('supplier', $supplierId, [
                'location_label' => $geocoded['location_label'] ?? ($payload['nom_commercial'] ?? $payload['raison_sociale'] ?? 'Fournisseur'),
                'address_snapshot' => $address,
                'latitude' => $geocoded['latitude'] ?? null,
                'longitude' => $geocoded['longitude'] ?? null,
                'source' => 'geocoded',
            ], $updatedBy);
        } catch (Throwable $e) {
        }
    }
}



