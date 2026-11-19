<?php

class FactureAchatRepository
{
    private PDO $pdo;
    private bool $ready = true;
    private ?string $initError = null;
    private bool $hasItemsJsonColumn = false;
    private ?array $febDocumentRequestIds = null;
    private ?array $operationalPurchaseRows = null;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureTables();
    }

    private function ensureTables(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS facture_achat_banamur (
            id_facture_achat INT NOT NULL AUTO_INCREMENT,
            fournisseur_id INT NULL,
            reference_interne VARCHAR(80) NULL,
            invoice_number VARCHAR(120) NULL,
            verification_code VARCHAR(120) NULL,
            invoice_date DATE NULL,
            amount_total DECIMAL(14,2) NOT NULL DEFAULT 0,
            devise VARCHAR(10) NOT NULL DEFAULT 'FCFA',
            items_json LONGTEXT NULL,
            statut ENUM('brouillon','a_verifier','verifiee','rejetee','payee') NOT NULL DEFAULT 'a_verifier',

            file_path VARCHAR(255) NULL,
            file_original_name VARCHAR(255) NULL,
            file_mime VARCHAR(100) NULL,
            file_size INT NULL,

            fne_last_verify_at DATETIME NULL,
            fne_last_verify_ok TINYINT(1) NULL,
            fne_last_verify_http_status INT NULL,
            fne_last_verify_status VARCHAR(50) NULL,
            fne_last_verify_amount DECIMAL(14,2) NULL,
            fne_last_verify_amount_ok TINYINT(1) NULL,
            fne_last_verify_response_json LONGTEXT NULL,

            created_by_user_id INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            PRIMARY KEY (id_facture_achat),
            UNIQUE KEY uniq_facture_achat_ref (reference_interne),
            KEY idx_facture_achat_fournisseur (fournisseur_id),
            KEY idx_facture_achat_invoice_number (invoice_number),
            KEY idx_facture_achat_verification_code (verification_code),
            KEY idx_facture_achat_statut (statut)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

        try {
            $this->pdo->exec($sql);
        } catch (Throwable $e) {
            $this->ready = false;
            $this->initError = 'Initialisation SQL des factures d\'achat impossible (droits insuffisants ?)';
        }

        if ($this->ready) {
            $this->ensureOptionalColumns();
        }
    }

    private function ensureOptionalColumns(): void
    {
        $this->hasItemsJsonColumn = false;

        try {
            $columns=$this->pdo->query('SHOW COLUMNS FROM facture_achat_banamur')->fetchAll(PDO::FETCH_COLUMN,0)?:[];
            $add=[
                'items_json'=>"ALTER TABLE facture_achat_banamur ADD items_json LONGTEXT NULL AFTER devise",
                'fne_token'=>"ALTER TABLE facture_achat_banamur ADD fne_token VARCHAR(190) NULL AFTER verification_code",
                'chantier_id'=>"ALTER TABLE facture_achat_banamur ADD chantier_id INT NULL AFTER fournisseur_id",
                'total_ht'=>"ALTER TABLE facture_achat_banamur ADD total_ht DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER amount_total",
                'total_vat'=>"ALTER TABLE facture_achat_banamur ADD total_vat DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER total_ht",
                'vat_applicable'=>"ALTER TABLE facture_achat_banamur ADD vat_applicable TINYINT(1) NOT NULL DEFAULT 0 AFTER total_vat",
                'payment_method'=>"ALTER TABLE facture_achat_banamur ADD payment_method VARCHAR(60) NULL AFTER invoice_date",
            ];
            foreach($add as$column=>$sql)if(!in_array($column,$columns,true))$this->pdo->exec($sql);
            $columns=$this->pdo->query('SHOW COLUMNS FROM facture_achat_banamur')->fetchAll(PDO::FETCH_COLUMN,0)?:$columns;
            $this->hasItemsJsonColumn=in_array('items_json',$columns,true);
            $indexes=$this->pdo->query('SHOW INDEX FROM facture_achat_banamur')->fetchAll(PDO::FETCH_ASSOC)?:[];
            $names=array_unique(array_column($indexes,'Key_name'));
            foreach(['uniq_facture_achat_fne_token'=>'fne_token','uniq_facture_achat_fne_number'=>'invoice_number']as$name=>$column){
                if(in_array($name,$names,true))continue;
                $duplicates=(int)$this->pdo->query("SELECT COUNT(*) FROM (SELECT $column FROM facture_achat_banamur WHERE $column IS NOT NULL AND TRIM($column)<>'' GROUP BY $column HAVING COUNT(*)>1) d")->fetchColumn();
                if($duplicates===0)$this->pdo->exec("ALTER TABLE facture_achat_banamur ADD UNIQUE KEY $name ($column)");
            }
        } catch (Throwable $e) {
            $this->hasItemsJsonColumn = in_array('items_json',$columns??[],true);
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

        $sql = "SELECT fa.*, f.raison_sociale AS fournisseur_raison_sociale
                FROM facture_achat_banamur fa
                LEFT JOIN fournisseur_banamur f ON f.id_fournisseur = fa.fournisseur_id
                ORDER BY fa.id_facture_achat DESC
                LIMIT " . (int)$limit;

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
            'a_verifier' => 0,
            'verifiee' => 0,
            'rejetee' => 0,
            'payee' => 0,
            'amount_total_sum' => 0.0,
            'missing_piece' => 0,
            'missing_invoice_number' => 0,
            'missing_verification_code' => 0,
            'verify_ok' => 0,
            'verify_ko' => 0,
            'verify_none' => 0,
            'last_verify_at' => null,
            'last_created_at' => null,
        ];

        if (!$this->ready) {
            return $stats;
        }

        try {
            $stats['total'] = (int)$this->pdo->query('SELECT COUNT(*) FROM facture_achat_banamur')->fetchColumn();
            foreach (['brouillon', 'a_verifier', 'verifiee', 'rejetee', 'payee'] as $status) {
                $st = $this->pdo->prepare('SELECT COUNT(*) FROM facture_achat_banamur WHERE statut = :statut');
                $st->execute(['statut' => $status]);
                $stats[$status] = (int)$st->fetchColumn();
            }
            $stats['amount_total_sum'] = (float)$this->pdo->query('SELECT COALESCE(SUM(amount_total), 0) FROM facture_achat_banamur')->fetchColumn();
            $stats['missing_piece'] = (int)$this->pdo->query("SELECT COUNT(*) FROM facture_achat_banamur WHERE file_path IS NULL OR TRIM(file_path) = ''")->fetchColumn();
            $stats['missing_invoice_number'] = (int)$this->pdo->query("SELECT COUNT(*) FROM facture_achat_banamur WHERE invoice_number IS NULL OR TRIM(invoice_number) = ''")->fetchColumn();
            $stats['missing_verification_code'] = (int)$this->pdo->query("SELECT COUNT(*) FROM facture_achat_banamur WHERE verification_code IS NULL OR TRIM(verification_code) = ''")->fetchColumn();

            $verifyRow = $this->pdo->query(
                "SELECT
                    SUM(CASE WHEN fne_last_verify_ok = 1 THEN 1 ELSE 0 END) AS ok_count,
                    SUM(CASE WHEN fne_last_verify_ok = 0 THEN 1 ELSE 0 END) AS ko_count,
                    SUM(CASE WHEN fne_last_verify_ok IS NULL THEN 1 ELSE 0 END) AS none_count,
                    MAX(fne_last_verify_at) AS last_verify_at
                 FROM facture_achat_banamur"
            )->fetch(PDO::FETCH_ASSOC);

            if (is_array($verifyRow)) {
                $stats['verify_ok'] = (int)($verifyRow['ok_count'] ?? 0);
                $stats['verify_ko'] = (int)($verifyRow['ko_count'] ?? 0);
                $stats['verify_none'] = (int)($verifyRow['none_count'] ?? 0);
                $stats['last_verify_at'] = $verifyRow['last_verify_at'] ?? null;
            }

            $stats['last_created_at'] = $this->pdo->query('SELECT MAX(created_at) FROM facture_achat_banamur')->fetchColumn();
        } catch (Throwable $e) {
        }

        return $stats;
    }

    public function getCurrencySummary(int $limit = 4): array
    {
        if (!$this->ready) {
            return [];
        }

        $limit = max(1, min(20, $limit));
        try {
            return $this->pdo->query(
                "SELECT devise, COUNT(*) AS c, COALESCE(SUM(amount_total),0) AS s
                 FROM facture_achat_banamur
                 GROUP BY devise
                 ORDER BY c DESC
                 LIMIT " . (int)$limit
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    public function getOperationalDashboardStats(): array
    {
        $rows = $this->buildOperationalPurchaseRows(100000);
        $stats = [
            'total' => 0,
            'brouillon' => 0,
            'a_verifier' => 0,
            'verifiee' => 0,
            'rejetee' => 0,
            'payee' => 0,
            'amount_total_sum' => 0.0,
            'missing_piece' => 0,
            'missing_invoice_number' => 0,
            'missing_verification_code' => 0,
            'verify_ok' => 0,
            'verify_ko' => 0,
            'verify_none' => 0,
            'last_verify_at' => null,
            'last_created_at' => null,
        ];

        foreach ($rows as $row) {
            $status = (string)($row['statut'] ?? '');
            if (array_key_exists($status, $stats)) {
                $stats[$status]++;
            }
            $stats['total']++;
            $stats['amount_total_sum'] += (float)($row['amount_total'] ?? 0);
            if (empty($row['has_document'])) {
                $stats['missing_piece']++;
            }
            $date = trim((string)($row['date_reference'] ?? ''));
            if ($date !== '' && ($stats['last_created_at'] === null || $date > (string)$stats['last_created_at'])) {
                $stats['last_created_at'] = $date;
            }
        }

        $stats['verify_ok'] = $stats['verifiee'] + $stats['payee'];
        $stats['verify_ko'] = $stats['rejetee'];
        $stats['verify_none'] = $stats['brouillon'] + $stats['a_verifier'];
        $stats['last_verify_at'] = $stats['last_created_at'];

        return $stats;
    }

    public function listOperationalLatest(int $limit = 6): array
    {
        return array_slice($this->buildOperationalPurchaseRows(max(1, $limit)), 0, max(1, $limit));
    }

    private function buildOperationalPurchaseRows(int $limit = 1000): array
    {
        if ($this->operationalPurchaseRows !== null) {
            return array_slice($this->operationalPurchaseRows, 0, max(1, $limit));
        }

        $fiches = $this->fetchOperationalFiches();
        $linkedCodes = [];
        $rows = [];

        foreach ($fiches as $fiche) {
            $code = strtolower(trim((string)($fiche['code_autorisation_feb'] ?? '')));
            if ($code !== '') {
                $linkedCodes[$code] = true;
            }
            $rows[] = $this->mapFicheToOperationalPurchase($fiche);
        }

        foreach ($this->fetchOperationalFebs() as $feb) {
            $status = strtolower(trim((string)($feb['status'] ?? '')));
            $code = $this->resolveFebBciCode($feb);
            if ($status === 'approved' && $code !== '' && isset($linkedCodes[strtolower($code)])) {
                continue;
            }
            $rows[] = $this->mapFebToOperationalPurchase($feb, $code);
        }

        usort($rows, static function (array $a, array $b): int {
            return strcmp((string)($b['date_reference'] ?? ''), (string)($a['date_reference'] ?? ''));
        });

        $this->operationalPurchaseRows = $rows;

        return array_slice($this->operationalPurchaseRows, 0, max(1, $limit));
    }

    private function fetchOperationalFiches(): array
    {
        try {
            $hasBonCommande = (bool)$this->pdo->query("SHOW TABLES LIKE 'bon_commande'")->fetchColumn();
            $selectBc = $hasBonCommande
                ? ', bc.bc_number, bc.id AS bon_commande_id'
                : ', NULL AS bc_number, NULL AS bon_commande_id';
            $joinBc = $hasBonCommande
                ? " LEFT JOIN (SELECT num_fiche,MAX(id) id,MAX(bc_number) bc_number FROM bon_commande GROUP BY num_fiche) bc ON bc.num_fiche = COALESCE(NULLIF(f.parent_num_fiche, ''), NULLIF(f.code_parentale, ''), f.num_fiche)"
                : '';
            $sql = "SELECT f.id_fiche, f.num_fiche, f.beficiaire_fiche, f.fournisseur_nom, f.montant_fiche,
                           f.code_autorisation_feb, f.date_creat_fiche, f.date_decaissement, f.date_refuse,
                           f.details_demande, f.designation_fiche, f.precision_fiche,
                           f.approuve, f.etat_fiche, f.decaisse,
                           f.cni_beneficiaire, f.signature_fichier, f.num_piece,
                           COALESCE(NULLIF(f.parent_num_fiche, ''), NULLIF(f.code_parentale, ''), f.num_fiche) AS workflow_base_num_fiche
                           {$selectBc}
                    FROM fiche_decaissement f LEFT JOIN chantier_banamur c ON c.id=f.chantier_id
                    {$joinBc}
                    WHERE (f.chantier_id IS NULL OR c.archived_at IS NULL)
                    ORDER BY f.date_creat_fiche DESC, f.id_fiche DESC";
            return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    private function fetchOperationalFebs(): array
    {
        try {
            $sql = "SELECT id, requester_name, title, payload_json, status, submitted_at, decided_at, updated_at
                    FROM employee_legal_requests r
                    WHERE request_type = 'feb'
                      AND EXISTS(SELECT 1 FROM chantier_banamur c WHERE c.id=CAST(JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.chantier_id')) AS UNSIGNED) AND c.archived_at IS NULL)
                    ORDER BY submitted_at DESC, id DESC";
            return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    private function mapFicheToOperationalPurchase(array $fiche): array
    {
        $status = 'verifiee';
        if ((int)($fiche['decaisse'] ?? 0) === 1) {
            $status = 'payee';
        } elseif ((int)($fiche['approuve'] ?? 0) === 2 || (int)($fiche['etat_fiche'] ?? 0) === 2) {
            $status = 'rejetee';
        }

        $numFiche = trim((string)($fiche['num_fiche'] ?? ''));
        $baseNum = trim((string)($fiche['workflow_base_num_fiche'] ?? $numFiche));
        if ($baseNum === '') {
            $baseNum = $numFiche;
        }
        $label = trim((string)($fiche['fournisseur_nom'] ?? ''));
        if ($label === '') {
            $label = trim((string)($fiche['beficiaire_fiche'] ?? ''));
        }
        if ($label === '') {
            $label = 'Fiche de decaissement';
        }
        $details = trim((string)($fiche['details_demande'] ?? ''));
        if ($details === '') {
            $details = trim((string)($fiche['designation_fiche'] ?? $fiche['precision_fiche'] ?? ''));
        }

        return [
            'source' => 'fiche',
            'id' => (int)($fiche['id_fiche'] ?? 0),
            'reference' => $numFiche,
            'label' => $label,
            'description' => $details,
            'amount_total' => (float)($fiche['montant_fiche'] ?? 0),
            'devise' => 'FCFA',
            'statut' => $status,
            'date_reference' => (string)($fiche['date_decaissement'] ?? $fiche['date_refuse'] ?? $fiche['date_creat_fiche'] ?? ''),
            'has_document' => !empty($fiche['bon_commande_id']) || trim((string)($fiche['cni_beneficiaire'] ?? '')) !== '' || trim((string)($fiche['signature_fichier'] ?? '')) !== '' || trim((string)($fiche['num_piece'] ?? '')) !== '',
            'primary_url' => '../decaissement/voir_fiche_details.php?num_fiche=' . rawurlencode($numFiche),
            'primary_label' => 'Voir fiche',
            'document_url' => '../decaissement/request/export_bon_commande_pdf.php?num_fiche=' . rawurlencode($baseNum),
            'document_label' => !empty($fiche['bon_commande_id']) ? 'BC PDF' : 'Generer BC',
            'secondary_url' => '../decaissement/bon_commande.php?num_fiche=' . rawurlencode($baseNum),
            'secondary_label' => 'Bon de commande',
        ];
    }

    private function mapFebToOperationalPurchase(array $feb, string $code): array
    {
        $payload = $this->decodePayload((string)($feb['payload_json'] ?? ''));
        $status = strtolower(trim((string)($feb['status'] ?? '')));
        $invoiceStatus = 'brouillon';
        if ($status === 'rejected') {
            $invoiceStatus = 'rejetee';
        } elseif ($status === 'approved') {
            $invoiceStatus = 'a_verifier';
        }

        $amount = $this->payloadAmount($payload);
        $label = trim((string)($payload['nom'] ?? $payload['demandeur'] ?? $feb['requester_name'] ?? 'FEB'));
        $description = trim((string)($payload['description'] ?? $feb['title'] ?? ''));
        $reference = $code !== '' ? $code : ('FEB-' . (int)($feb['id'] ?? 0));

        return [
            'source' => 'feb',
            'id' => (int)($feb['id'] ?? 0),
            'reference' => $reference,
            'label' => $label,
            'description' => $description,
            'amount_total' => $amount,
            'devise' => 'FCFA',
            'statut' => $invoiceStatus,
            'date_reference' => (string)($feb['decided_at'] ?? $feb['submitted_at'] ?? $feb['updated_at'] ?? ''),
            'has_document' => $this->febHasRegisteredDocument((int)($feb['id'] ?? 0), $payload),
            'primary_url' => '../validation_feb_detail.php?id=' . (int)($feb['id'] ?? 0),
            'primary_label' => 'Voir FEB',
            'document_url' => '../request/export_feb_pdf.php?id=' . (int)($feb['id'] ?? 0),
            'document_label' => 'BCI / FEB PDF',
            'secondary_url' => $status === 'approved' && $code !== '' ? '../decaissement/formulaire_demande_decaissement.php?code_autorisation_feb=' . rawurlencode($code) : '',
            'secondary_label' => $status === 'approved' && $code !== '' ? 'Creer fiche' : '',
        ];
    }

    private function resolveFebBciCode(array $feb): string
    {
        $payload = $this->decodePayload((string)($feb['payload_json'] ?? ''));
        $code = $this->payloadFirstNonEmpty($payload, [
            'numero_bon_commande_interne',
            'bci_number',
            'numero_bci',
            'code_autorisation_feb',
            'code_autorisation',
            'autorisation_feb',
            'code_feb',
        ]);
        if ($code === '' && !empty($payload['legacy']) && is_array($payload['legacy'])) {
            $code = $this->payloadFirstNonEmpty((array)$payload['legacy'], [
                'numero_bon_commande_interne',
                'bci_number',
                'numero_bci',
                'code_autorisation_feb',
                'code_autorisation',
                'autorisation_feb',
                'code_feb',
            ]);
        }

        if ($code !== '' && stripos($code, 'AUTH-FEB') !== 0) {
            return $code;
        }

        if (strtolower(trim((string)($feb['status'] ?? ''))) !== 'approved') {
            return '';
        }

        $timestamp = strtotime((string)($feb['submitted_at'] ?? '')) ?: time();
        $id = (int)($feb['id'] ?? 0);
        return $id > 0 ? sprintf('BCI-FEB-%s-%05d', date('ym', $timestamp), $id) : '';
    }

    private function decodePayload(string $payloadJson): array
    {
        $decoded = json_decode(trim($payloadJson), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function payloadFirstNonEmpty(array $payload, array $keys): string
    {
        foreach ($keys as $key) {
            $value = trim((string)($payload[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    private function payloadAmount(array $payload): float
    {
        $raw = $payload['montant'] ?? $payload['amount'] ?? 0;
        if (is_string($raw)) {
            $clean = str_replace([" ", "\xC2\xA0"], ['', ''], $raw);
            $clean = strpos($clean, ',') !== false && strpos($clean, '.') === false ? str_replace(',', '.', $clean) : str_replace(',', '', $clean);
            return is_numeric($clean) ? round((float)$clean, 2) : 0.0;
        }
        return is_numeric($raw) ? round((float)$raw, 2) : 0.0;
    }

    private function febHasRegisteredDocument(int $requestId, array $payload): bool
    {
        if ($requestId > 0) {
            $docIds = $this->getFebDocumentRequestIds();
            if (isset($docIds[$requestId])) {
                return true;
            }
        }

        foreach (['justificatifs', 'invoice_document', 'pv_reception_document'] as $key) {
            if (!empty($payload[$key])) {
                return true;
            }
        }
        return false;
    }

    private function getFebDocumentRequestIds(): array
    {
        if ($this->febDocumentRequestIds !== null) {
            return $this->febDocumentRequestIds;
        }

        $this->febDocumentRequestIds = [];
        try {
            $rows = $this->pdo->query('SELECT DISTINCT request_id FROM feb_document_registry WHERE request_id IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
            foreach ($rows as $id) {
                $this->febDocumentRequestIds[(int)$id] = true;
            }
        } catch (Throwable $e) {
            $this->febDocumentRequestIds = [];
        }

        return $this->febDocumentRequestIds;
    }

    public function getById(int $id): ?array
    {
        if (!$this->ready || $id <= 0) {
            return null;
        }
        $st = $this->pdo->prepare('SELECT * FROM facture_achat_banamur WHERE id_facture_achat = :id LIMIT 1');
        $st->execute(['id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function create(array $data, ?int $userId = null, ?string $itemsJson = null): array
    {
        if (!$this->ready) {
            return ['ok' => false, 'error' => $this->initError ?? 'Module indisponible.'];
        }

        $fournisseurId = isset($data['fournisseur_id']) ? (int)$data['fournisseur_id'] : 0;
        if ($fournisseurId <= 0) {
            return ['ok' => false, 'error' => 'Le fournisseur est requis.'];
        }

        // Preserve the supplier already linked to an invoice; validate every new assignment.
        if ($fournisseurId !== (int) ($current['fournisseur_id'] ?? 0)) {
            $supplier = $this->pdo->prepare('SELECT actif FROM fournisseur_banamur WHERE id_fournisseur = :id');
            $supplier->execute(['id' => $fournisseurId]);
            if ((int) $supplier->fetchColumn() !== 1) {
                return ['ok' => false, 'error' => 'Ce fournisseur est archivé, inactif ou introuvable.'];
            }
        }

        $amount = $this->asMoney($data['amount_total'] ?? 0);
        if ($amount < 0) {
            $amount = 0;
        }

        $fneToken=$this->asNullableString($data['fne_token']??null);
        $invoiceNumber=$this->asNullableString($data['invoice_number']??null);
        foreach(['fne_token'=>$fneToken,'invoice_number'=>$invoiceNumber]as$column=>$value){
            if($value===null)continue;
            $duplicate=$this->pdo->prepare("SELECT reference_interne FROM facture_achat_banamur WHERE $column=:value LIMIT 1");$duplicate->execute(['value'=>$value]);
            if($reference=$duplicate->fetchColumn())return['ok'=>false,'error'=>"Cette facture FNE est déjà liée à la facture d’achat Finéa $reference."];
        }
        $payload = [
            'fournisseur_id' => $fournisseurId,
            'chantier_id' => isset($data['chantier_id']) && (int)$data['chantier_id']>0?(int)$data['chantier_id']:null,
            'reference_interne' => $this->generateReferenceInterne('FA'),
            'invoice_number' => $invoiceNumber,
            'verification_code' => $this->asNullableString($data['verification_code'] ?? null),
            'fne_token'=>$fneToken,
            'invoice_date' => $this->asNullableDate($data['invoice_date'] ?? null),
            'payment_method'=>$this->asNullableString($data['payment_method']??null),
            'amount_total' => $amount,
            'total_ht'=>$this->asMoney($data['total_ht']??0),
            'total_vat'=>$this->asMoney($data['total_vat']??0),
            'vat_applicable'=>(float)($data['total_vat']??0)>0?1:0,
            'devise' => $this->asNonEmptyDefault((string)($data['devise'] ?? ''), 'FCFA'),
            'statut' => $this->asNonEmptyDefault((string)($data['statut'] ?? ''), 'a_verifier'),

            'file_path' => $this->asNullableString($data['file_path'] ?? null),
            'file_original_name' => $this->asNullableString($data['file_original_name'] ?? null),
            'file_mime' => $this->asNullableString($data['file_mime'] ?? null),
            'file_size' => isset($data['file_size']) ? (int)$data['file_size'] : null,

            'created_by_user_id' => $userId,
        ];

        if ($this->hasItemsJsonColumn) {
            $payload['items_json'] = $this->asNullableString($itemsJson);
        }

        if ($this->hasItemsJsonColumn) {
            $sql = 'INSERT INTO facture_achat_banamur (
                fournisseur_id, chantier_id, reference_interne, invoice_number, verification_code, fne_token, invoice_date, payment_method,
                amount_total, total_ht, total_vat, vat_applicable, devise, items_json, statut,
                file_path, file_original_name, file_mime, file_size, created_by_user_id
            ) VALUES (
                :fournisseur_id, :chantier_id, :reference_interne, :invoice_number, :verification_code, :fne_token, :invoice_date, :payment_method,
                :amount_total, :total_ht, :total_vat, :vat_applicable, :devise, :items_json, :statut,
                :file_path, :file_original_name, :file_mime, :file_size, :created_by_user_id
            )';
        } else {
            $sql = 'INSERT INTO facture_achat_banamur (
                fournisseur_id, chantier_id, reference_interne, invoice_number, verification_code, fne_token, invoice_date, payment_method,
                amount_total, total_ht, total_vat, vat_applicable, devise, statut,
                file_path, file_original_name, file_mime, file_size, created_by_user_id
            ) VALUES (
                :fournisseur_id, :chantier_id, :reference_interne, :invoice_number, :verification_code, :fne_token, :invoice_date, :payment_method,
                :amount_total, :total_ht, :total_vat, :vat_applicable, :devise, :statut,
                :file_path, :file_original_name, :file_mime, :file_size, :created_by_user_id
            )';
        }

        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                $st = $this->pdo->prepare($sql);
                $st->execute($payload);
                return ['ok' => true, 'id' => (int)$this->pdo->lastInsertId(), 'reference_interne' => (string)$payload['reference_interne']];
            } catch (PDOException $e) {
                if ($this->isDuplicateKey($e)) {
                    $payload['reference_interne'] = $this->generateReferenceInterne('FA');
                    continue;
                }
                return ['ok' => false, 'error' => 'Erreur SQL lors de la création de la facture d\'achat.'];
            }
        }

        return ['ok' => false, 'error' => 'Impossible de générer une référence interne unique.'];
    }

    public function update(int $id, array $data, ?string $itemsJson = null): array
    {
        if (!$this->ready || $id <= 0) {
            return ['ok' => false, 'error' => 'Facture invalide.'];
        }

        $current = $this->getById($id);
        if (!$current) {
            return ['ok' => false, 'error' => 'Facture introuvable.'];
        }

        $currentRef = $this->asNullableString($current['reference_interne'] ?? null);
        $resolvedRef = $currentRef ?: $this->generateReferenceInterne('FA');

        $fournisseurId = isset($data['fournisseur_id']) ? (int)$data['fournisseur_id'] : 0;
        if ($fournisseurId <= 0) {
            return ['ok' => false, 'error' => 'Le fournisseur est requis.'];
        }

        // Preserve the supplier already linked to an invoice; validate every new assignment.
        if ($fournisseurId !== (int) ($current['fournisseur_id'] ?? 0)) {
            $supplier = $this->pdo->prepare('SELECT actif FROM fournisseur_banamur WHERE id_fournisseur = :id');
            $supplier->execute(['id' => $fournisseurId]);
            if ((int) $supplier->fetchColumn() !== 1) {
                return ['ok' => false, 'error' => 'Ce fournisseur est archivé, inactif ou introuvable.'];
            }
        }

        $amount = $this->asMoney($data['amount_total'] ?? 0);
        if ($amount < 0) {
            $amount = 0;
        }

        $payload = [
            'id' => $id,
            'fournisseur_id' => $fournisseurId,
            // Champ verrouillé: on conserve la référence existante, ou on en génère une si absente.
            'reference_interne' => $resolvedRef,
            'invoice_number' => $this->asNullableString($data['invoice_number'] ?? null),
            'verification_code' => $this->asNullableString($data['verification_code'] ?? null),
            'invoice_date' => $this->asNullableDate($data['invoice_date'] ?? null),
            'amount_total' => $amount,
            'devise' => $this->asNonEmptyDefault((string)($data['devise'] ?? ''), 'FCFA'),
            'statut' => $this->asNonEmptyDefault((string)($data['statut'] ?? ''), 'a_verifier'),
        ];

        if ($this->hasItemsJsonColumn) {
            $payload['items_json'] = $this->asNullableString($itemsJson);
        }

        if ($this->hasItemsJsonColumn) {
            $sql = 'UPDATE facture_achat_banamur SET
                fournisseur_id = :fournisseur_id,
                reference_interne = :reference_interne,
                invoice_number = :invoice_number,
                verification_code = :verification_code,
                invoice_date = :invoice_date,
                amount_total = :amount_total,
                devise = :devise,
                items_json = :items_json,
                statut = :statut
            WHERE id_facture_achat = :id';
        } else {
            $sql = 'UPDATE facture_achat_banamur SET
                fournisseur_id = :fournisseur_id,
                reference_interne = :reference_interne,
                invoice_number = :invoice_number,
                verification_code = :verification_code,
                invoice_date = :invoice_date,
                amount_total = :amount_total,
                devise = :devise,
                statut = :statut
            WHERE id_facture_achat = :id';
        }

        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                $st = $this->pdo->prepare($sql);
                $st->execute($payload);
                return ['ok' => true, 'reference_interne' => (string)$payload['reference_interne']];
            } catch (PDOException $e) {
                if ($this->isDuplicateKey($e)) {
                    $payload['reference_interne'] = $this->generateReferenceInterne('FA');
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
            $st = $this->pdo->prepare('SELECT reference_interne FROM facture_achat_banamur WHERE reference_interne LIKE :like ORDER BY reference_interne DESC LIMIT 1');
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

    public function attachFile(int $id, array $file): array
    {
        if (!$this->ready || $id <= 0) {
            return ['ok' => false, 'error' => 'Facture invalide.'];
        }

        $payload = [
            'id' => $id,
            'file_path' => $this->asNullableString($file['file_path'] ?? null),
            'file_original_name' => $this->asNullableString($file['file_original_name'] ?? null),
            'file_mime' => $this->asNullableString($file['file_mime'] ?? null),
            'file_size' => isset($file['file_size']) ? (int)$file['file_size'] : null,
        ];

        $sql = 'UPDATE facture_achat_banamur SET
            file_path = :file_path,
            file_original_name = :file_original_name,
            file_mime = :file_mime,
            file_size = :file_size
        WHERE id_facture_achat = :id';

        try {
            $st = $this->pdo->prepare($sql);
            $st->execute($payload);
            return ['ok' => true];
        } catch (PDOException $e) {
            return ['ok' => false, 'error' => 'Erreur SQL lors de l\'attachement du fichier.'];
        }
    }

    public function saveVerifyResult(int $id, array $verifyMeta): array
    {
        if (!$this->ready || $id <= 0) {
            return ['ok' => false, 'error' => 'Facture invalide.'];
        }

        $payload = [
            'id' => $id,
            'at' => $verifyMeta['fne_last_verify_at'] ?? date('Y-m-d H:i:s'),
            'ok' => array_key_exists('fne_last_verify_ok', $verifyMeta) ? (int)(!empty($verifyMeta['fne_last_verify_ok'])) : null,
            'http' => isset($verifyMeta['fne_last_verify_http_status']) ? (int)$verifyMeta['fne_last_verify_http_status'] : null,
            'status' => $this->asNullableString($verifyMeta['fne_last_verify_status'] ?? null),
            'amount' => isset($verifyMeta['fne_last_verify_amount']) && is_numeric($verifyMeta['fne_last_verify_amount']) ? (float)$verifyMeta['fne_last_verify_amount'] : null,
            'amount_ok' => array_key_exists('fne_last_verify_amount_ok', $verifyMeta) ? (int)(!empty($verifyMeta['fne_last_verify_amount_ok'])) : null,
            'json' => $this->asNullableString($verifyMeta['fne_last_verify_response_json'] ?? null),
        ];

        $sql = 'UPDATE facture_achat_banamur SET
            fne_last_verify_at = :at,
            fne_last_verify_ok = :ok,
            fne_last_verify_http_status = :http,
            fne_last_verify_status = :status,
            fne_last_verify_amount = :amount,
            fne_last_verify_amount_ok = :amount_ok,
            fne_last_verify_response_json = :json
        WHERE id_facture_achat = :id';

        try {
            $st = $this->pdo->prepare($sql);
            $st->execute($payload);
            return ['ok' => true];
        } catch (PDOException $e) {
            return ['ok' => false, 'error' => 'Erreur SQL lors de l\'enregistrement du résultat verify.'];
        }
    }

    private function asNullableString($value): ?string
    {
        $s = trim((string)($value ?? ''));
        return $s === '' ? null : $s;
    }

    private function asNonEmptyDefault(string $value, string $default): string
    {
        $value = trim($value);
        return $value === '' ? $default : $value;
    }

    private function asNullableDate($value): ?string
    {
        $s = trim((string)($value ?? ''));
        if ($s === '') {
            return null;
        }
        $d = DateTimeImmutable::createFromFormat('Y-m-d', $s);
        return $d ? $d->format('Y-m-d') : null;
    }

    private function asMoney($value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }
        return is_numeric($value) ? round((float)$value, 2) : 0.0;
    }
}

