<?php
require_once __DIR__.'/../services/ClientBusinessPolicy.php';
require_once __DIR__.'/../services/ClientIdentityMigration.php';

class FactureVenteRepository
{
    private PDO $pdo;
    private bool $ready = true;
    private ?string $initError = null;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureTables();
    }

    private function ensureTables(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS facture_vente_banamur (
            id_facture_vente INT NOT NULL AUTO_INCREMENT,
            reference_interne VARCHAR(80) NULL,

            invoice_type VARCHAR(20) NOT NULL DEFAULT 'sale',
            payment_method VARCHAR(30) NOT NULL DEFAULT 'cash',
            template VARCHAR(10) NOT NULL DEFAULT 'B2F',
            is_rne TINYINT(1) NOT NULL DEFAULT 0,
            rne VARCHAR(80) NULL,
            client_ncc VARCHAR(80) NULL,

            client_company_name VARCHAR(255) NOT NULL,
            client_phone VARCHAR(60) NOT NULL,
            client_email VARCHAR(190) NOT NULL,

            point_of_sale VARCHAR(80) NOT NULL,
            establishment VARCHAR(80) NOT NULL,

            discount DECIMAL(14,2) NOT NULL DEFAULT 0,
            foreign_currency VARCHAR(10) NULL,
            foreign_currency_rate DECIMAL(14,6) NULL,

            items_json LONGTEXT NOT NULL,

            statut ENUM('brouillon','a_signer','signe','erreur') NOT NULL DEFAULT 'a_signer',

            fne_signed_at DATETIME NULL,
            fne_http_status INT NULL,
            fne_invoice_id VARCHAR(120) NULL,
            fne_response_json LONGTEXT NULL,

            created_by_user_id INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            PRIMARY KEY (id_facture_vente),
            UNIQUE KEY uniq_facture_vente_ref (reference_interne),
            KEY idx_facture_vente_statut (statut),
            KEY idx_facture_vente_client (client_company_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

        try {
            $this->pdo->exec($sql);
            require_once __DIR__ . '/../services/CommercialProjectFinanceMigrationService.php';
            (new CommercialProjectFinanceMigrationService($this->pdo))->ensureSchema();
            require_once __DIR__ . '/../services/CurrencySettingsService.php';
            (new CurrencySettingsService($this->pdo))->ensureSchema();
            $this->ensureColumns();
        } catch (Throwable $e) {
            $this->ready = false;
            $this->initError = 'Initialisation SQL des factures de vente impossible (droits insuffisants ?)';
        }
    }

    private function ensureColumns(): void
    {
        (new ClientIdentityMigration($this->pdo))->install();
        $identityCols=$this->pdo->query('SHOW COLUMNS FROM facture_vente_banamur')->fetchAll(PDO::FETCH_COLUMN);
        foreach(['client_id'=>'INT NULL','client_fiscal_country'=>'VARCHAR(2) NULL','client_identity_type'=>'VARCHAR(80) NULL','client_identity_number'=>'VARCHAR(190) NULL'] as $column=>$definition) if(!in_array($column,$identityCols,true)) $this->pdo->exec("ALTER TABLE facture_vente_banamur ADD $column $definition");
        $columns = $this->pdo->query('SHOW COLUMNS FROM facture_vente_banamur')->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
        $add = [
            'chantier_id' => "ALTER TABLE facture_vente_banamur ADD chantier_id INT NULL AFTER reference_interne",
            'paiement_statut' => "ALTER TABLE facture_vente_banamur ADD paiement_statut ENUM('emise','payee') NOT NULL DEFAULT 'emise' AFTER statut",
            'invoice_date' => "ALTER TABLE facture_vente_banamur ADD invoice_date DATE NULL AFTER reference_interne",
            'tax_not_invoiced' => "ALTER TABLE facture_vente_banamur ADD tax_not_invoiced TINYINT(1) NOT NULL DEFAULT 0 AFTER discount",
            'paid_at' => "ALTER TABLE facture_vente_banamur ADD paid_at DATETIME NULL AFTER paiement_statut",
            'facture_file_path' => "ALTER TABLE facture_vente_banamur ADD facture_file_path VARCHAR(255) NULL AFTER items_json",
            'facture_file_original_name' => "ALTER TABLE facture_vente_banamur ADD facture_file_original_name VARCHAR(255) NULL AFTER facture_file_path",
            'facture_file_mime' => "ALTER TABLE facture_vente_banamur ADD facture_file_mime VARCHAR(100) NULL AFTER facture_file_original_name",
            'facture_file_size' => "ALTER TABLE facture_vente_banamur ADD facture_file_size INT NULL AFTER facture_file_mime",
            'total_ttc' => "ALTER TABLE facture_vente_banamur ADD total_ttc DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER discount",
            'facture_travaux_type' => "ALTER TABLE facture_vente_banamur ADD facture_travaux_type ENUM('avance','situation','finale','complement') NOT NULL DEFAULT 'situation' AFTER chantier_id",
            'avancement_percent' => "ALTER TABLE facture_vente_banamur ADD avancement_percent DECIMAL(7,2) NULL AFTER facture_travaux_type",
            'fne_invoice_number' => "ALTER TABLE facture_vente_banamur ADD fne_invoice_number VARCHAR(120) NULL AFTER fne_invoice_id",
            'fne_verification_code' => "ALTER TABLE facture_vente_banamur ADD fne_verification_code VARCHAR(120) NULL AFTER fne_invoice_number",
            'fne_verified_at' => "ALTER TABLE facture_vente_banamur ADD fne_verified_at DATETIME NULL AFTER fne_verification_code",
            'fne_verify_ok' => "ALTER TABLE facture_vente_banamur ADD fne_verify_ok TINYINT(1) NULL AFTER fne_verified_at",
            'fne_verify_error' => "ALTER TABLE facture_vente_banamur ADD fne_verify_error TEXT NULL AFTER fne_verify_ok",
        ];
        foreach ($add as $column => $sql) {
            if (!in_array($column, $columns, true)) {
                $this->pdo->exec($sql);
            }
        }
        $indexes = $this->pdo->query("SHOW INDEX FROM facture_vente_banamur")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $indexNames = array_unique(array_map(static fn(array $r): string => (string)($r['Key_name'] ?? ''), $indexes));
        if (!in_array('idx_facture_vente_chantier', $indexNames, true)) {
            $this->pdo->exec('ALTER TABLE facture_vente_banamur ADD KEY idx_facture_vente_chantier (chantier_id)');
        }
        if (!in_array('idx_facture_vente_paiement', $indexNames, true)) {
            $this->pdo->exec('ALTER TABLE facture_vente_banamur ADD KEY idx_facture_vente_paiement (paiement_statut)');
        }
        foreach ([
            'uniq_facture_vente_fne_id'=>'fne_invoice_id',
            'uniq_facture_vente_fne_number'=>'fne_invoice_number',
        ] as $indexName=>$column) {
            if (in_array($indexName,$indexNames,true)) continue;
            try {
                $duplicates=(int)$this->pdo->query("SELECT COUNT(*) FROM (
                    SELECT $column FROM facture_vente_banamur
                    WHERE $column IS NOT NULL AND TRIM($column)<>''
                    GROUP BY $column HAVING COUNT(*)>1
                ) duplicate_fne_values")->fetchColumn();
                if ($duplicates===0) {
                    $this->pdo->exec("ALTER TABLE facture_vente_banamur ADD UNIQUE KEY $indexName ($column)");
                }
            } catch(Throwable $ignored) {
                // La vérification applicative de create() reste active si le schéma historique contient un doublon.
            }
        }

        $clientColumns = $this->pdo->query('SHOW COLUMNS FROM client_banamur')->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
        if (!in_array('email_client', $clientColumns, true)) {
            $this->pdo->exec('ALTER TABLE client_banamur ADD email_client VARCHAR(190) NULL AFTER telephone_client');
        }
    }

    public function isReady(): bool
    {
        return $this->ready;
    }

    public function getInitError(): ?string
    {
        return $this->initError;
    }

    public function listLatest(int $limit = 200): array
    {
        if (!$this->ready) {
            return [];
        }
        $limit = max(1, min(500, $limit));

        $sql = 'SELECT fv.*, c.code AS chantier_code, c.titre AS chantier_titre, COALESCE(c.montant_devis, d.total_ttc, 0) AS chantier_devis_total
                FROM facture_vente_banamur fv
                LEFT JOIN chantier_banamur c ON c.id = fv.chantier_id
                LEFT JOIN devis_banamur d ON d.id = c.devis_id
                WHERE c.archived_at IS NULL
                ORDER BY fv.id_facture_vente DESC LIMIT ' . (int)$limit;

        try {
            return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    public function getDashboardStats(): array
    {
        $stats = [
            'total' => 0,
            'brouillon' => 0,
            'a_signer' => 0,
            'signe' => 0,
            'erreur' => 0,
            'signed_count' => 0,
            'last_signed_at' => null,
            'last_created_at' => null,
            'payee' => 0,
            'emise' => 0,
        ];

        if (!$this->ready) {
            return $stats;
        }

        try {
            // Même périmètre que listLatest(): une facture sans chantier historique
            // reste visible, mais tout chantier explicitement archivé est exclu.
            $scope=' FROM facture_vente_banamur fv LEFT JOIN chantier_banamur c ON c.id=fv.chantier_id';
            $activeWhere=' WHERE c.archived_at IS NULL';
            $paymentStats=$this->pdo->query("
                SELECT
                    COUNT(*) AS total,
                    SUM(LOWER(TRIM(COALESCE(fv.paiement_statut,'emise')))='payee') AS payee,
                    SUM(LOWER(TRIM(COALESCE(fv.paiement_statut,'emise')))<>'payee') AS emise
                $scope $activeWhere
            ")->fetch(PDO::FETCH_ASSOC) ?: [];
            $stats['total']=(int)($paymentStats['total']??0);
            $stats['payee']=(int)($paymentStats['payee']??0);
            $stats['emise']=(int)($paymentStats['emise']??0);
            foreach (['brouillon', 'a_signer', 'signe', 'erreur'] as $status) {
                $st = $this->pdo->prepare('SELECT COUNT(*)'.$scope.$activeWhere.' AND statut = :statut');
                $st->execute(['statut' => $status]);
                $stats[$status] = (int)$st->fetchColumn();
            }
            $stats['signed_count'] = (int)$this->pdo->query("SELECT COUNT(*)$scope$activeWhere AND fne_invoice_id IS NOT NULL AND TRIM(fne_invoice_id) <> ''")->fetchColumn();
            $stats['last_signed_at'] = $this->pdo->query('SELECT MAX(fne_signed_at)'.$scope.$activeWhere)->fetchColumn();
            $stats['last_created_at'] = $this->pdo->query('SELECT MAX(fv.created_at)'.$scope.$activeWhere)->fetchColumn();
        } catch (Throwable $e) {
        }

        return $stats;
    }

    public function listDashboardAmountRows(): array
    {
        if (!$this->ready) {
            return [];
        }

        try {
            $st = $this->pdo->query('SELECT fv.created_at,fv.items_json,fv.discount,fv.foreign_currency,fv.foreign_currency_rate FROM facture_vente_banamur fv JOIN chantier_banamur c ON c.id=fv.chantier_id AND c.archived_at IS NULL ORDER BY fv.created_at ASC');
            return $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        } catch (Throwable $e) {
            return [];
        }
    }

    public function listChantiersForSelect(): array
    {
        try {
            $sql = "SELECT c.id, c.code, c.titre, cl.nom_client,
                           CASE WHEN UPPER(TRIM(c.code))='CH0066' OR UPPER(c.titre) LIKE '%BUREAU BANAMUR%' THEN 1 ELSE 0 END AS is_internal_bureau,
                           link.client_purchase_order_id,
                           COALESCE(c.montant_devis, d.total_ttc, c.cout_total_chantier, 0) AS devis_total
                    FROM chantier_banamur c
                    LEFT JOIN devis_banamur d ON d.id = c.devis_id
                    LEFT JOIN client_banamur cl ON cl.id_client = c.client_id
                    LEFT JOIN chantier_client_purchase_orders link ON link.chantier_id = c.id
                    WHERE c.archived_at IS NULL
                    ORDER BY c.code ASC, c.titre ASC";
            return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    public function listClientsForSelect(): array
    {
        try {
            $sql = "SELECT *
                    FROM client_banamur
                    WHERE nom_client IS NOT NULL AND TRIM(nom_client) <> ''
                    ORDER BY nom_client ASC
                    LIMIT 1000";
            return array_values(array_filter($this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [],[ClientBusinessPolicy::class,'usable']));
        } catch (Throwable $e) {
            return [];
        }
    }

    public function getChantierBillingSummary(int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        try {
            $sql = "SELECT
                        c.id AS chantier_id,
                        c.code AS chantier_code,
                        c.titre AS chantier_titre,
                        cl.nom_client,
                        COALESCE(c.montant_devis, d.total_ttc, c.cout_total_chantier, 0) AS devis_total,
                        COALESCE(SUM(CASE WHEN fv.id_facture_vente IS NOT NULL THEN 1 ELSE 0 END), 0) AS nb_factures,
                        COALESCE(SUM(CASE WHEN fv.paiement_statut = 'payee' THEN {$this->invoiceTotalSql('fv')} ELSE 0 END), 0) AS montant_percu,
                        COALESCE(SUM(CASE WHEN fv.paiement_statut = 'emise' THEN {$this->invoiceTotalSql('fv')} ELSE 0 END), 0) AS montant_emis_non_paye,
                        (
                            SELECT COALESCE(SUM(f.montant_fiche), 0)
                            FROM fiche_decaissement f
                            WHERE f.chantier_id = c.id AND COALESCE(f.decaisse, 0) = 1
                        ) AS montant_decaisse
                    FROM chantier_banamur c
                    LEFT JOIN devis_banamur d ON d.id = c.devis_id
                    LEFT JOIN client_banamur cl ON cl.id_client = c.client_id
                    LEFT JOIN facture_vente_banamur fv ON fv.chantier_id = c.id
                    WHERE c.archived_at IS NULL
                      AND UPPER(TRIM(c.code)) <> 'CH0066'
                      AND UPPER(c.titre) NOT LIKE '%BUREAU BANAMUR%'
                    GROUP BY c.id, c.code, c.titre, cl.nom_client, c.montant_devis, d.total_ttc, c.cout_total_chantier
                    ORDER BY c.id DESC
                    LIMIT " . (int)$limit;
            return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    public function getById(int $id): ?array
    {
        if (!$this->ready || $id <= 0) {
            return null;
        }
        $st = $this->pdo->prepare('SELECT fv.*, c.code AS chantier_code, c.titre AS chantier_titre, COALESCE(c.montant_devis, d.total_ttc, 0) AS chantier_devis_total
            FROM facture_vente_banamur fv
            LEFT JOIN chantier_banamur c ON c.id = fv.chantier_id
            LEFT JOIN devis_banamur d ON d.id = c.devis_id
            WHERE fv.id_facture_vente = :id AND c.archived_at IS NULL LIMIT 1');
        $st->execute(['id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function create(array $data, string $itemsJson, ?int $userId = null): array
    {
        if (!$this->ready) {
            return ['ok' => false, 'error' => $this->initError ?? 'Module indisponible.'];
        }

        try {$data=ClientBusinessPolicy::forChantier($this->pdo,$data);} catch (DomainException $e) {return ['ok'=>false,'error'=>$e->getMessage()];}
        $clientName = trim((string)($data['client_company_name'] ?? ''));
        $clientPhone = trim((string)($data['client_phone'] ?? ''));
        $clientEmail = trim((string)($data['client_email'] ?? ''));
        if ($clientName === '' || ($data['client_fiscal_country']==='CI' && $clientPhone === '')) {
            return ['ok' => false, 'error' => 'Client: nom et téléphone sont requis.'];
        }

        $chantierId=$this->asNullableInt($data['chantier_id'] ?? null);
        if ($chantierId === null) {
            return ['ok' => false, 'error' => 'Le chantier est obligatoire pour une facture de vente.'];
        }
        $scope=$this->pdo->prepare("SELECT code,titre FROM chantier_banamur WHERE id=:id LIMIT 1");
        $scope->execute(['id'=>$chantierId]);
        $selectedChantier=$scope->fetch(PDO::FETCH_ASSOC)?:[];
        if(strtoupper(trim((string)($selectedChantier['code']??'')))==='CH0066'||stripos((string)($selectedChantier['titre']??''),'BUREAU BANAMUR')!==false){
            return ['ok'=>false,'error'=>'CH0066 · BUREAU BANAMUR/FIDEST est une activité interne non facturable. Utilisez le suivi des charges internes, pas une facture de vente.'];
        }

        $invoiceDate=$this->validateInvoiceDate((string)($data['invoice_date']??''),(string)($data['paiement_statut']??'emise'));
        $fneInvoiceId=$this->asNullableString($data['fne_invoice_id'] ?? null);
        $fneInvoiceNumber=$this->asNullableString($data['fne_invoice_number'] ?? null);
        foreach (['fne_invoice_id'=>$fneInvoiceId,'fne_invoice_number'=>$fneInvoiceNumber] as $column=>$value) {
            if ($value===null) continue;
            $duplicate=$this->pdo->prepare("SELECT reference_interne FROM facture_vente_banamur WHERE $column=:value LIMIT 1");
            $duplicate->execute(['value'=>$value]);
            $existingReference=$duplicate->fetchColumn();
            if ($existingReference) {
                return ['ok'=>false,'error'=>'Cette facture FNE est déjà liée à la facture Finéa '.$existingReference.'.'];
            }
        }
        $currency=$this->resolveCurrency((string)($data['foreign_currency']??''));
        $taxNotInvoiced=!empty($data['tax_not_invoiced']) ? 1 : 0;
        $payload = [
            'reference_interne' => $this->generateReferenceInterne('FV'),
            'invoice_date' => $invoiceDate,
            'chantier_id' => $this->asNullableInt($data['chantier_id'] ?? null),
            'client_purchase_order_id' => $this->resolvePurchaseOrderId(
                (int) ($data['chantier_id'] ?? 0),
                $data['client_purchase_order_id'] ?? null
            ),
            'facture_travaux_type' => $this->normalizeTravauxType((string)($data['facture_travaux_type'] ?? 'situation')),
            'avancement_percent' => $this->asNullablePercent($data['avancement_percent'] ?? null),
            'invoice_type' => $this->asNonEmptyDefault((string)($data['invoice_type'] ?? ''), 'sale'),
            'payment_method' => $this->asNonEmptyDefault((string)($data['payment_method'] ?? ''), 'cash'),
            'template' => $this->asNonEmptyDefault((string)($data['template'] ?? ''), 'B2F'),
            'is_rne' => trim((string)($data['rne'] ?? '')) !== '' ? 1 : 0,
            'rne' => $this->asNullableString($data['rne'] ?? null),
            'client_ncc' => $this->asNullableString($data['client_ncc'] ?? null),
            'client_id'=>$data['client_id'], 'client_fiscal_country'=>$data['client_fiscal_country'],
            'client_identity_type'=>$data['client_identity_type'], 'client_identity_number'=>$data['client_identity_number'],

            'client_company_name' => $clientName,
            'client_phone' => $clientPhone,
            'client_email' => $clientEmail,

            'point_of_sale' => $this->resolvePointOfSale($data),
            'establishment' => $this->resolveEstablishment($data),

            'discount' => $this->asMoney($data['discount'] ?? 0),
            'tax_not_invoiced' => $taxNotInvoiced,
            'total_ttc' => $this->computeInvoiceTotal($itemsJson, $data['discount'] ?? 0, (bool)$taxNotInvoiced),
            'foreign_currency' => $currency['code'],
            'foreign_currency_rate' => $currency['rate'],

            'items_json' => $itemsJson,
            'facture_file_path' => $this->asNullableString($data['facture_file_path'] ?? null),
            'facture_file_original_name' => $this->asNullableString($data['facture_file_original_name'] ?? null),
            'facture_file_mime' => $this->asNullableString($data['facture_file_mime'] ?? null),
            'facture_file_size' => $this->asNullableInt($data['facture_file_size'] ?? null),

            'statut' => $this->asNonEmptyDefault((string)($data['statut'] ?? ''), 'a_signer'),
            'paiement_statut' => $this->normalizePaiementStatut((string)($data['paiement_statut'] ?? 'emise')),
            'paid_at' => $this->resolvePaidAt((string)($data['paiement_statut'] ?? 'emise'), $data['expected_collection_date'] ?? null),
            'expected_collection_date' => $this->validateCollectionDate((string)($data['expected_collection_date'] ?? ''),(string)($data['paiement_statut'] ?? 'emise')),
            'collected_amount' => $this->normalizePaiementStatut((string)($data['paiement_statut'] ?? 'emise')) === 'payee'
                ? $this->computeInvoiceTotal($itemsJson, $data['discount'] ?? 0, (bool)$taxNotInvoiced) : 0,
            'fne_invoice_number' => $this->asNullableString($data['fne_invoice_number'] ?? null),
            'fne_verification_code' => $this->asNullableString($data['fne_verification_code'] ?? null),
            'fne_invoice_id' => $fneInvoiceId,
            'created_by_user_id' => $userId,
        ];

        $sql = 'INSERT INTO facture_vente_banamur (
            reference_interne, invoice_date, chantier_id, client_purchase_order_id, facture_travaux_type, avancement_percent,
            invoice_type, payment_method, template, is_rne, rne, client_ncc,
            client_id, client_fiscal_country, client_identity_type, client_identity_number, client_company_name, client_phone, client_email,
            point_of_sale, establishment,
            discount, tax_not_invoiced, total_ttc, foreign_currency, foreign_currency_rate,
            items_json, facture_file_path, facture_file_original_name, facture_file_mime, facture_file_size,
            statut, paiement_statut, paid_at, expected_collection_date, collected_amount,
            fne_invoice_id, fne_invoice_number, fne_verification_code, created_by_user_id
        ) VALUES (
            :reference_interne, :invoice_date, :chantier_id, :client_purchase_order_id, :facture_travaux_type, :avancement_percent,
            :invoice_type, :payment_method, :template, :is_rne, :rne, :client_ncc,
            :client_id, :client_fiscal_country, :client_identity_type, :client_identity_number, :client_company_name, :client_phone, :client_email,
            :point_of_sale, :establishment,
            :discount, :tax_not_invoiced, :total_ttc, :foreign_currency, :foreign_currency_rate,
            :items_json, :facture_file_path, :facture_file_original_name, :facture_file_mime, :facture_file_size,
            :statut, :paiement_statut, :paid_at, :expected_collection_date, :collected_amount,
            :fne_invoice_id, :fne_invoice_number, :fne_verification_code, :created_by_user_id
        )';

        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                $st = $this->pdo->prepare($sql);
                $st->execute($payload);
                $createdId=(int)$this->pdo->lastInsertId();
                try{$close=$this->pdo->prepare("UPDATE devis_billing_alerts a JOIN chantier_banamur c ON c.devis_id=a.devis_id SET a.status='invoiced' WHERE c.id=:chantier_id");$close->execute(['chantier_id'=>$payload['chantier_id']]);}catch(Throwable $ignored){}
                return ['ok' => true, 'id' => $createdId, 'reference_interne' => (string)$payload['reference_interne']];
            } catch (PDOException $e) {
                if ($this->isDuplicateKey($e)) {
                    $payload['reference_interne'] = $this->generateReferenceInterne('FV');
                    continue;
                }
                return ['ok' => false, 'error' => 'Erreur SQL lors de la création de la facture de vente.'];
            }
        }

        return ['ok' => false, 'error' => 'Impossible de générer une référence interne unique.'];
    }

    public function update(int $id, array $data, string $itemsJson): array
    {
        if (!$this->ready || $id <= 0) {
            return ['ok' => false, 'error' => 'Facture invalide.'];
        }

        $current = $this->getById($id);
        if (!$current) {
            return ['ok' => false, 'error' => 'Facture introuvable.'];
        }

        $currentRef = $this->asNullableString($current['reference_interne'] ?? null);
        $resolvedRef = $currentRef ?: $this->generateReferenceInterne('FV');

        try {$data=ClientBusinessPolicy::forChantier($this->pdo,$data);} catch (DomainException $e) {return ['ok'=>false,'error'=>$e->getMessage()];}
        $clientName = trim((string)($data['client_company_name'] ?? ''));
        $clientPhone = trim((string)($data['client_phone'] ?? ''));
        $clientEmail = trim((string)($data['client_email'] ?? ''));
        if ($clientName === '' || ($data['client_fiscal_country']==='CI' && $clientPhone === '')) {
            return ['ok' => false, 'error' => 'Client: nom et téléphone sont requis.'];
        }

        if ($this->asNullableInt($data['chantier_id'] ?? null) === null) {
            return ['ok' => false, 'error' => 'Le chantier est obligatoire pour une facture de vente.'];
        }

        $invoiceDate=$this->validateInvoiceDate((string)($data['invoice_date']??($current['invoice_date']??'')),(string)($data['paiement_statut']??($current['paiement_statut']??'emise')));
        $currency=$this->resolveCurrency((string)($data['foreign_currency']??($current['foreign_currency']??'')));
        $taxNotInvoiced=!empty($data['tax_not_invoiced']) ? 1 : 0;
        $payload = [
            'id' => $id,
            // Champ verrouillé: on conserve la référence existante, ou on en génère une si absente.
            'reference_interne' => $resolvedRef,
            'invoice_date' => $invoiceDate,
            'chantier_id' => $this->asNullableInt($data['chantier_id'] ?? null),
            'client_purchase_order_id' => $this->resolvePurchaseOrderId(
                (int) ($data['chantier_id'] ?? 0),
                $data['client_purchase_order_id'] ?? ($current['client_purchase_order_id'] ?? null)
            ),
            'facture_travaux_type' => $this->normalizeTravauxType((string)($data['facture_travaux_type'] ?? ($current['facture_travaux_type'] ?? 'situation'))),
            'avancement_percent' => $this->asNullablePercent($data['avancement_percent'] ?? ($current['avancement_percent'] ?? null)),
            'invoice_type' => $this->asNonEmptyDefault((string)($data['invoice_type'] ?? ''), 'sale'),
            'payment_method' => $this->asNonEmptyDefault((string)($data['payment_method'] ?? ''), 'cash'),
            'template' => $this->asNonEmptyDefault((string)($data['template'] ?? ''), 'B2F'),
            'is_rne' => trim((string)($data['rne'] ?? '')) !== '' ? 1 : 0,
            'rne' => $this->asNullableString($data['rne'] ?? null),
            'client_ncc' => $this->asNullableString($data['client_ncc'] ?? null),
            'client_id'=>$data['client_id'], 'client_fiscal_country'=>$data['client_fiscal_country'],
            'client_identity_type'=>$data['client_identity_type'], 'client_identity_number'=>$data['client_identity_number'],

            'client_company_name' => $clientName,
            'client_phone' => $clientPhone,
            'client_email' => $clientEmail,

            'point_of_sale' => $this->resolvePointOfSale($data),
            'establishment' => $this->resolveEstablishment($data),

            'discount' => $this->asMoney($data['discount'] ?? 0),
            'tax_not_invoiced' => $taxNotInvoiced,
            'total_ttc' => $this->computeInvoiceTotal($itemsJson, $data['discount'] ?? 0, (bool)$taxNotInvoiced),
            'foreign_currency' => $currency['code'],
            'foreign_currency_rate' => $currency['rate'],

            'items_json' => $itemsJson,
            'facture_file_path' => $this->asNullableString($data['facture_file_path'] ?? ($current['facture_file_path'] ?? null)),
            'facture_file_original_name' => $this->asNullableString($data['facture_file_original_name'] ?? ($current['facture_file_original_name'] ?? null)),
            'facture_file_mime' => $this->asNullableString($data['facture_file_mime'] ?? ($current['facture_file_mime'] ?? null)),
            'facture_file_size' => $this->asNullableInt($data['facture_file_size'] ?? ($current['facture_file_size'] ?? null)),

            'statut' => $this->asNonEmptyDefault((string)($data['statut'] ?? ''), 'a_signer'),
            'paiement_statut' => $this->normalizePaiementStatut((string)($data['paiement_statut'] ?? ($current['paiement_statut'] ?? 'emise'))),
            'paid_at' => $this->resolvePaidAt((string)($data['paiement_statut'] ?? ($current['paiement_statut'] ?? 'emise')), $data['expected_collection_date'] ?? ($current['expected_collection_date'] ?? null)),
            'expected_collection_date' => $this->validateCollectionDate((string)($data['expected_collection_date'] ?? ($current['expected_collection_date'] ?? '')),(string)($data['paiement_statut'] ?? ($current['paiement_statut'] ?? 'emise'))),
            'collected_amount' => $this->normalizePaiementStatut((string)($data['paiement_statut'] ?? ($current['paiement_statut'] ?? 'emise'))) === 'payee'
                ? $this->computeInvoiceTotal($itemsJson, $data['discount'] ?? 0, (bool)$taxNotInvoiced) : 0,
            'fne_invoice_number' => $this->asNullableString($data['fne_invoice_number'] ?? null),
            'fne_verification_code' => $this->asNullableString($data['fne_verification_code'] ?? null),
        ];

        $sql = 'UPDATE facture_vente_banamur SET
            reference_interne = :reference_interne,
            invoice_date = :invoice_date,
            chantier_id = :chantier_id,
            client_purchase_order_id = :client_purchase_order_id,
            facture_travaux_type = :facture_travaux_type,
            avancement_percent = :avancement_percent,
            invoice_type = :invoice_type,
            payment_method = :payment_method,
            template = :template,
            is_rne = :is_rne,
            rne = :rne,
            client_ncc = :client_ncc,
            client_id = :client_id, client_fiscal_country = :client_fiscal_country, client_identity_type = :client_identity_type, client_identity_number = :client_identity_number,
            client_company_name = :client_company_name,
            client_phone = :client_phone,
            client_email = :client_email,
            point_of_sale = :point_of_sale,
            establishment = :establishment,
            discount = :discount,
            tax_not_invoiced = :tax_not_invoiced,
            total_ttc = :total_ttc,
            foreign_currency = :foreign_currency,
            foreign_currency_rate = :foreign_currency_rate,
            items_json = :items_json,
            facture_file_path = :facture_file_path,
            facture_file_original_name = :facture_file_original_name,
            facture_file_mime = :facture_file_mime,
            facture_file_size = :facture_file_size,
            statut = :statut,
            paiement_statut = :paiement_statut,
            paid_at = :paid_at,
            expected_collection_date = :expected_collection_date,
            collected_amount = :collected_amount,
            fne_invoice_number = :fne_invoice_number,
            fne_verification_code = :fne_verification_code
        WHERE id_facture_vente = :id';

        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                $st = $this->pdo->prepare($sql);
                $st->execute($payload);
                return ['ok' => true, 'reference_interne' => (string)$payload['reference_interne']];
            } catch (PDOException $e) {
                if ($this->isDuplicateKey($e)) {
                    $payload['reference_interne'] = $this->generateReferenceInterne('FV');
                    continue;
                }
                return ['ok' => false, 'error' => 'Erreur SQL lors de la mise à jour.'];
            }
        }

        return ['ok' => false, 'error' => 'Impossible de générer une référence interne unique.'];
    }

    private function generateReferenceInterne(string $typePrefix): string
    {
        $typePrefix = strtoupper(trim($typePrefix));
        $prefix = $typePrefix . '-' . date('Ym') . '-';

        try {
            $st = $this->pdo->prepare('SELECT reference_interne FROM facture_vente_banamur WHERE reference_interne LIKE :like ORDER BY reference_interne DESC LIMIT 1');
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

    public function saveSignResult(int $id, array $meta): array
    {
        if (!$this->ready || $id <= 0) {
            return ['ok' => false, 'error' => 'Facture invalide.'];
        }

        $payload = [
            'id' => $id,
            'signed_at' => $meta['fne_signed_at'] ?? date('Y-m-d H:i:s'),
            'http' => isset($meta['fne_http_status']) ? (int)$meta['fne_http_status'] : null,
            'invoice_id' => $this->asNullableString($meta['fne_invoice_id'] ?? null),
            'json' => $this->asNullableString($meta['fne_response_json'] ?? null),
            'statut' => $this->asNonEmptyDefault((string)($meta['statut'] ?? ''), 'signe'),
        ];

        $sql = 'UPDATE facture_vente_banamur SET
            fne_signed_at = :signed_at,
            fne_http_status = :http,
            fne_invoice_id = :invoice_id,
            fne_response_json = :json,
            statut = :statut
        WHERE id_facture_vente = :id';

        try {
            $st = $this->pdo->prepare($sql);
            $st->execute($payload);
            return ['ok' => true];
        } catch (PDOException $e) {
            return ['ok' => false, 'error' => 'Erreur SQL lors de l\'enregistrement du résultat de signature.'];
        }
    }

    private function asNullableString($value): ?string
    {
        $s = trim((string)($value ?? ''));
        return $s === '' ? null : $s;
    }

    public function saveFneVerificationResult(int $id, array $meta): array
    {
        if (!$this->ready || $id <= 0) {
            return ['ok' => false, 'error' => 'Facture invalide.'];
        }
        try {
            $st = $this->pdo->prepare('UPDATE facture_vente_banamur SET
                fne_http_status = :http,
                fne_invoice_id = COALESCE(:invoice_id, fne_invoice_id),
                fne_response_json = :json,
                fne_verified_at = :verified_at,
                fne_verify_ok = :verify_ok,
                fne_verify_error = :verify_error
                WHERE id_facture_vente = :id');
            $st->execute([
                'id' => $id,
                'http' => isset($meta['fne_http_status']) ? (int)$meta['fne_http_status'] : null,
                'invoice_id' => $this->asNullableString($meta['fne_invoice_id'] ?? null),
                'json' => $this->asNullableString($meta['fne_response_json'] ?? null),
                'verified_at' => $meta['fne_verified_at'] ?? date('Y-m-d H:i:s'),
                'verify_ok' => array_key_exists('fne_verify_ok', $meta) ? (int)(bool)$meta['fne_verify_ok'] : null,
                'verify_error' => $this->asNullableString($meta['fne_verify_error'] ?? null),
            ]);
            return ['ok' => true];
        } catch (PDOException $e) {
            return ['ok' => false, 'error' => 'Erreur SQL lors de la verification FNE.'];
        }
    }

    private function asNullableInt($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $n = (int)$value;
        return $n > 0 ? $n : null;
    }

    private function asNullableDate($value): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value ? $value : null;
    }

    private function resolvePurchaseOrderId(int $chantierId, $requested): ?int
    {
        $requestedId = $this->asNullableInt($requested);
        if ($requestedId !== null) {
            $statement = $this->pdo->prepare(
                'SELECT COUNT(*) FROM chantier_client_purchase_orders
                 WHERE chantier_id=:chantier_id AND client_purchase_order_id=:purchase_order_id'
            );
            $statement->execute(['chantier_id' => $chantierId, 'purchase_order_id' => $requestedId]);
            if ((int) $statement->fetchColumn() === 0) {
                throw new DomainException('Le bon de commande client ne correspond pas au chantier.');
            }
            return $requestedId;
        }
        $statement = $this->pdo->prepare(
            'SELECT client_purchase_order_id FROM chantier_client_purchase_orders WHERE chantier_id=:chantier_id'
        );
        $statement->execute(['chantier_id' => $chantierId]);
        return $this->asNullableInt($statement->fetchColumn());
    }

    private function normalizePaiementStatut(string $value): string
    {
        $value = strtolower(trim($value));
        return in_array($value, ['emise', 'payee'], true) ? $value : 'emise';
    }

    private function resolvePaidAt(string $statut, $paidAt): ?string
    {
        if ($this->normalizePaiementStatut($statut) !== 'payee') {
            return null;
        }
        $paidAt = trim((string)($paidAt ?? ''));
        return $paidAt !== '' ? $paidAt : date('Y-m-d H:i:s');
    }

    private function normalizeTravauxType(string $value): string
    {
        $value = strtolower(trim($value));
        return in_array($value, ['avance', 'situation', 'finale', 'complement'], true) ? $value : 'situation';
    }

    private function asNullablePercent($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }
        return max(0.0, min(100.0, round((float)$value, 2)));
    }

    private function resolvePointOfSale(array $data): string
    {
        $value = trim((string)($data['point_of_sale'] ?? ''));
        return $value !== '' ? $value : 'CHANTIER';
    }

    private function resolveEstablishment(array $data): string
    {
        $value = trim((string)($data['establishment'] ?? ''));
        return $value !== '' ? $value : 'BANAMUR BTP';
    }

    private function invoiceTotalSql(string $alias): string
    {
        return 'GREATEST(0, COALESCE(' . $alias . '.total_ttc, 0))';
    }

    private function computeInvoiceTotal(string $itemsJson, $discount, bool $taxNotInvoiced=false): float
    {
        $decoded = json_decode($itemsJson, true);
        $items = is_array($decoded) && isset($decoded['items']) && is_array($decoded['items']) ? $decoded['items'] : (is_array($decoded) ? $decoded : []);
        $taxRates = [];
        $taxPath = __DIR__ . '/../config/taxes.php';
        if (is_file($taxPath)) {
            $loaded = require $taxPath;
            $taxRates = is_array($loaded) ? $loaded : [];
        }
        $ht = 0.0;
        $tax = 0.0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $qty = isset($item['quantity']) && is_numeric($item['quantity']) ? (float)$item['quantity'] : 1.0;
            $amount = isset($item['amount']) && is_numeric($item['amount']) ? (float)$item['amount'] : 0.0;
            $lineHt = max(0.0, $qty * $amount);
            $taxCode = is_array($item['taxes'] ?? null) ? (string)($item['taxes'][0] ?? '') : '';
            $rate = isset($item['tax_rate']) && is_numeric($item['tax_rate'])
                ? (float)$item['tax_rate']
                : (isset($taxRates[$taxCode]) && is_numeric($taxRates[$taxCode]) ? (float)$taxRates[$taxCode] : 0.0);
            $ht += $lineHt;
            $tax += $lineHt * $rate;
        }
        $discount = $this->asMoney($discount);
        return round(max(0.0, $ht + ($taxNotInvoiced ? 0.0 : $tax) - $discount), 2);
    }

    private function validateInvoiceDate(string $date, string $paymentStatus): string
    {
        $date=trim($date);$parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        if(!$parsed || $parsed->format('Y-m-d')!==$date) throw new DomainException('La date de facturation est obligatoire.');
        return $date;
    }

    private function validateCollectionDate(string $date,string $paymentStatus): string
    {
        $date=trim($date);$parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        if(!$parsed||$parsed->format('Y-m-d')!==$date) throw new DomainException('La date d’encaissement est obligatoire.');
        return $date;
    }

    private function resolveCurrency(string $code): array
    {
        $code=strtoupper(trim($code));
        if($code===''){$row=$this->pdo->query("SELECT code,rate_to_xof FROM facturation_currencies WHERE is_active=1 ORDER BY is_default DESC,(code='XOF') DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);}
        else{$stmt=$this->pdo->prepare('SELECT code,rate_to_xof FROM facturation_currencies WHERE code=:code AND is_active=1 LIMIT 1');$stmt->execute(['code'=>$code]);$row=$stmt->fetch(PDO::FETCH_ASSOC);}
        if(!$row)throw new DomainException('La devise choisie est inactive ou non paramétrée.');
        return ['code'=>(string)$row['code'],'rate'=>(float)$row['rate_to_xof']];
    }

    private function withoutTaxes(string $itemsJson): string
    {
        $items=json_decode($itemsJson,true);
        if(!is_array($items)) return $itemsJson;
        foreach($items as &$item){if(is_array($item)){$item['taxes']=['TVA_NON_FACTUREE'];$item['tax_rate']=0;}}
        unset($item);
        return json_encode($items,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?: $itemsJson;
    }

    private function asNonEmptyDefault(string $value, string $default): string
    {
        $value = trim($value);
        return $value === '' ? $default : $value;
    }

    private function asMoney($value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }
        return is_numeric($value) ? round((float)$value, 2) : 0.0;
    }
}

