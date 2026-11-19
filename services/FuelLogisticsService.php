<?php

class FuelLogisticsService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureSchema();
    }

    public function ensureSchema(): void
    {
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS fuel_partner_stations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(190) NOT NULL,
                contact_name VARCHAR(190) NOT NULL DEFAULT "",
                phone VARCHAR(80) NOT NULL DEFAULT "",
                address VARCHAR(255) NOT NULL DEFAULT "",
                status VARCHAR(40) NOT NULL DEFAULT "active",
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_fuel_station_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS fuel_station_deposits (
                id INT AUTO_INCREMENT PRIMARY KEY,
                station_id INT NOT NULL,
                amount DECIMAL(15,2) NOT NULL DEFAULT 0,
                deposit_date DATE NOT NULL,
                reference VARCHAR(120) NOT NULL DEFAULT "",
                receipt_path VARCHAR(255) NOT NULL DEFAULT "",
                receipt_name VARCHAR(255) NOT NULL DEFAULT "",
                notes TEXT NULL,
                created_by_user_id INT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_fuel_deposit_station (station_id),
                KEY idx_fuel_deposit_date (deposit_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS fuel_vouchers (
                id INT AUTO_INCREMENT PRIMARY KEY,
                voucher_code VARCHAR(60) NOT NULL UNIQUE,
                station_id INT NULL,
                chantier_id INT NULL,
                ligne_debourse_id INT NULL,
                fiche_num VARCHAR(80) NOT NULL DEFAULT "",
                asset_id INT NULL,
                driver_id INT NULL,
                requester_name VARCHAR(190) NOT NULL DEFAULT "",
                beneficiary_name VARCHAR(190) NOT NULL DEFAULT "",
                vehicle_label VARCHAR(190) NOT NULL DEFAULT "",
                amount DECIMAL(15,2) NOT NULL DEFAULT 0,
                quantity_liters DECIMAL(12,2) NOT NULL DEFAULT 0,
                unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
                purpose VARCHAR(255) NOT NULL DEFAULT "",
                status VARCHAR(40) NOT NULL DEFAULT "issued",
                archived_at DATETIME NULL,
                archive_reason VARCHAR(255) NOT NULL DEFAULT "",
                issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                consumed_at DATETIME NULL,
                created_by_user_id INT NULL,
                KEY idx_fuel_voucher_status (status),
                KEY idx_fuel_voucher_station (station_id),
                KEY idx_fuel_voucher_chantier (chantier_id),
                KEY idx_fuel_voucher_fiche (fiche_num)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS fuel_general_ledger (
                id INT AUTO_INCREMENT PRIMARY KEY,
                movement_type VARCHAR(40) NOT NULL,
                source_type VARCHAR(60) NOT NULL DEFAULT "",
                source_id INT NULL,
                station_id INT NULL,
                chantier_id INT NULL,
                ligne_debourse_id INT NULL,
                fiche_num VARCHAR(80) NOT NULL DEFAULT "",
                amount DECIMAL(15,2) NOT NULL DEFAULT 0,
                description VARCHAR(255) NOT NULL DEFAULT "",
                movement_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                created_by_user_id INT NULL,
                archived_at DATETIME NULL,
                archive_reason VARCHAR(255) NOT NULL DEFAULT "",
                KEY idx_fuel_ledger_type (movement_type),
                KEY idx_fuel_ledger_station (station_id),
                KEY idx_fuel_ledger_chantier (chantier_id),
                KEY idx_fuel_ledger_fiche (fiche_num)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS fuel_settings (
                setting_key VARCHAR(120) NOT NULL PRIMARY KEY,
                setting_value TEXT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->ensureColumn('fuel_vouchers', 'archived_at', 'DATETIME NULL');
        $this->ensureColumn('fuel_vouchers', 'archive_reason', 'VARCHAR(255) NOT NULL DEFAULT ""');
        $this->ensureColumn('fuel_general_ledger', 'archived_at', 'DATETIME NULL');
        $this->ensureColumn('fuel_general_ledger', 'archive_reason', 'VARCHAR(255) NOT NULL DEFAULT ""');
    }

    public function listStations(): array
    {
        return $this->pdo->query('SELECT * FROM fuel_partner_stations ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function ensureColumn(string $table, string $column, string $definition): void
    {
        try {
            $db = (string)$this->pdo->query('SELECT DATABASE()')->fetchColumn();
            $stmt = $this->pdo->prepare('
                SELECT COUNT(*)
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :table AND COLUMN_NAME = :column
            ');
            $stmt->execute(['db' => $db, 'table' => $table, 'column' => $column]);
            if ((int)$stmt->fetchColumn() === 0) {
                $this->pdo->exec('ALTER TABLE `' . str_replace('`', '``', $table) . '` ADD COLUMN `' . str_replace('`', '``', $column) . '` ' . $definition);
            }
        } catch (Throwable $e) {
        }
    }

    public function listDeposits(int $limit = 100): array
    {
        $stmt = $this->pdo->query('
            SELECT d.*, s.name AS station_name
            FROM fuel_station_deposits d
            LEFT JOIN fuel_partner_stations s ON s.id = d.station_id
            ORDER BY d.deposit_date DESC, d.id DESC
            LIMIT ' . max(1, min(300, $limit))
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function listVouchers(int $limit = 100): array
    {
        $stmt = $this->pdo->query('
            SELECT v.*,
                   s.name AS station_name,
                   c.code AS chantier_code,
                   c.titre AS chantier_titre,
                   TRIM(CONCAT(COALESCE(a.asset_code, ""), " ", COALESCE(a.designation, ""), " ", COALESCE(a.registration_number, ""))) AS asset_label,
                   d.full_name AS driver_name
            FROM fuel_vouchers v
            LEFT JOIN fuel_partner_stations s ON s.id = v.station_id
            LEFT JOIN chantier_banamur c ON c.id = v.chantier_id
            LEFT JOIN logistics_assets a ON a.id = v.asset_id
            LEFT JOIN logistics_drivers d ON d.id = v.driver_id
            WHERE COALESCE(v.status, "") <> "archived" AND v.archived_at IS NULL AND (v.chantier_id IS NULL OR c.archived_at IS NULL)
            ORDER BY v.issued_at DESC, v.id DESC
            LIMIT ' . max(1, min(300, $limit))
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function generalBalance(): float
    {
        $stmt = $this->pdo->query('
            SELECT COALESCE(SUM(CASE WHEN movement_type = "credit" THEN amount ELSE -amount END), 0)
            FROM fuel_general_ledger
            WHERE archived_at IS NULL
        ');
        return (float)$stmt->fetchColumn();
    }

    public function activeStartAt(): string
    {
        $stmt = $this->pdo->prepare('SELECT setting_value FROM fuel_settings WHERE setting_key = "active_start_at" LIMIT 1');
        $stmt->execute();
        $value = trim((string)($stmt->fetchColumn() ?: ''));
        if ($value !== '' && preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
            return $value;
        }

        $archived = trim((string)($this->pdo->query('SELECT COALESCE(MAX(archived_at), "") FROM fuel_vouchers WHERE archived_at IS NOT NULL')->fetchColumn() ?: ''));
        if ($archived !== '') {
            $this->setSetting('active_start_at', $archived);
            return $archived;
        }

        $now = date('Y-m-d H:i:s');
        $this->setSetting('active_start_at', $now);
        return $now;
    }

    public function activeStartDate(): string
    {
        return substr($this->activeStartAt(), 0, 10);
    }

    private function setSetting(string $key, string $value): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO fuel_settings (setting_key, setting_value)
            VALUES (:setting_key, :setting_value)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
        ');
        $stmt->execute(['setting_key' => $key, 'setting_value' => $value]);
    }

    public function archiveExistingFuelHistory(string $reason = 'Archive historique avant demarrage suivi carburant'): array
    {
        $reason = trim($reason) !== '' ? trim($reason) : 'Archive historique avant demarrage suivi carburant';
        $this->pdo->beginTransaction();
        try {
            $voucherStmt = $this->pdo->prepare('
                UPDATE fuel_vouchers
                SET status = "archived", archived_at = COALESCE(archived_at, NOW()), archive_reason = :reason
                WHERE archived_at IS NULL
            ');
            $voucherStmt->execute(['reason' => $reason]);
            $voucherCount = $voucherStmt->rowCount();

            $ledgerStmt = $this->pdo->prepare('
                UPDATE fuel_general_ledger
                SET archived_at = COALESCE(archived_at, NOW()), archive_reason = :reason
                WHERE archived_at IS NULL
                  AND movement_type = "debit"
                  AND (
                    fiche_num <> ""
                    OR source_type IN ("fuel_voucher", "fiche_decaissement")
                  )
            ');
            $ledgerStmt->execute(['reason' => $reason]);
            $ledgerCount = $ledgerStmt->rowCount();
            $this->setSetting('active_start_at', date('Y-m-d H:i:s'));
            $this->pdo->commit();
            return ['vouchers_archived' => $voucherCount, 'ledger_debits_archived' => $ledgerCount];
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function createStation(array $data): int
    {
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('Nom de station requis.');
        }

        $stmt = $this->pdo->prepare('
            INSERT INTO fuel_partner_stations (name, contact_name, phone, address, status)
            VALUES (:name, :contact_name, :phone, :address, "active")
        ');
        $stmt->execute([
            'name' => $name,
            'contact_name' => trim((string)($data['contact_name'] ?? '')),
            'phone' => trim((string)($data['phone'] ?? '')),
            'address' => trim((string)($data['address'] ?? '')),
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function createDeposit(array $post, array $file, int $userId): int
    {
        $stationId = (int)($post['station_id'] ?? 0);
        $amount = $this->money((string)($post['amount'] ?? ''));
        $date = trim((string)($post['deposit_date'] ?? date('Y-m-d')));
        if ($stationId <= 0) {
            throw new RuntimeException('Station requise.');
        }
        if ($amount <= 0) {
            throw new RuntimeException('Montant de depot invalide.');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = date('Y-m-d');
        }

        $receipt = $this->storeReceipt($file, $userId);
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('
                INSERT INTO fuel_station_deposits (station_id, amount, deposit_date, reference, receipt_path, receipt_name, notes, created_by_user_id)
                VALUES (:station_id, :amount, :deposit_date, :reference, :receipt_path, :receipt_name, :notes, :user_id)
            ');
            $stmt->execute([
                'station_id' => $stationId,
                'amount' => $amount,
                'deposit_date' => $date,
                'reference' => trim((string)($post['reference'] ?? '')),
                'receipt_path' => (string)($receipt['path'] ?? ''),
                'receipt_name' => (string)($receipt['name'] ?? ''),
                'notes' => trim((string)($post['notes'] ?? '')),
                'user_id' => $userId > 0 ? $userId : null,
            ]);
            $id = (int)$this->pdo->lastInsertId();
            $this->addLedger('credit', 'deposit', $id, $amount, 'Depot carburant station', $stationId, null, null, '', $userId);
            $this->pdo->commit();
            return $id;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function createVoucher(array $post, int $userId): int
    {
        $stationId = (int)($post['station_id'] ?? 0);
        $amount = $this->money((string)($post['amount'] ?? ''));
        if ($amount <= 0) {
            throw new RuntimeException('Montant du bon invalide.');
        }
        if ($amount > $this->generalBalance()) {
            throw new RuntimeException('Solde carburant general insuffisant.');
        }

        $stmt = $this->pdo->prepare('
            INSERT INTO fuel_vouchers (
                voucher_code, station_id, chantier_id, ligne_debourse_id, asset_id, driver_id,
                requester_name, beneficiary_name, vehicle_label, amount, quantity_liters, unit_price, purpose,
                created_by_user_id
            ) VALUES (
                :code, :station_id, :chantier_id, :ligne_debourse_id, :asset_id, :driver_id,
                :requester_name, :beneficiary_name, :vehicle_label, :amount, :quantity_liters, :unit_price, :purpose,
                :user_id
            )
        ');
        $stmt->execute([
            'code' => $this->nextVoucherCode(),
            'station_id' => $stationId > 0 ? $stationId : null,
            'chantier_id' => (int)($post['chantier_id'] ?? 0) ?: null,
            'ligne_debourse_id' => (int)($post['ligne_debourse_id'] ?? 0) ?: null,
            'asset_id' => (int)($post['asset_id'] ?? 0) ?: null,
            'driver_id' => (int)($post['driver_id'] ?? 0) ?: null,
            'requester_name' => trim((string)($post['requester_name'] ?? '')),
            'beneficiary_name' => trim((string)($post['beneficiary_name'] ?? '')),
            'vehicle_label' => trim((string)($post['vehicle_label'] ?? '')),
            'amount' => $amount,
            'quantity_liters' => (float)($post['quantity_liters'] ?? 0),
            'unit_price' => (float)($post['unit_price'] ?? 0),
            'purpose' => trim((string)($post['purpose'] ?? '')),
            'user_id' => $userId > 0 ? $userId : null,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function registerDecaissedFicheConsumption(string $numFiche, int $userId = 0): void
    {
        $numFiche = trim($numFiche);
        if ($numFiche === '') {
            return;
        }

        $stmt = $this->pdo->prepare('
            SELECT f.num_fiche, f.montant_fiche, f.chantier_id, f.ligne_debourse_id,
                   COALESCE(f.date_decaisse, f.date_decaissement, f.date_creat_fiche) AS ref_date,
                   f.beficiaire_fiche, f.designation_fiche, f.precision_fiche, f.details_demande,
                   l.designation AS ligne_designation, l.categorie AS ligne_categorie
            FROM fiche_decaissement f
            LEFT JOIN ligne_debourse_banamur l ON l.id = f.ligne_debourse_id
            LEFT JOIN chantier_banamur c ON c.id=f.chantier_id
            WHERE f.num_fiche = :num
              AND (f.chantier_id IS NULL OR c.archived_at IS NULL)
            LIMIT 1
        ');
        $stmt->execute(['num' => $numFiche]);
        $fiche = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$fiche || !$this->isFuelLine($fiche)) {
            return;
        }
        $refDate = trim((string)($fiche['ref_date'] ?? ''));
        if ($refDate !== '' && $refDate < $this->activeStartAt()) {
            return;
        }

        $voucherId = $this->ensureVoucherFromFiche($fiche, $userId);
        $exists = $this->pdo->prepare('SELECT id FROM fuel_general_ledger WHERE movement_type = "debit" AND fiche_num = :num LIMIT 1');
        $exists->execute(['num' => $numFiche]);
        $ledgerId = (int)($exists->fetchColumn() ?: 0);
        if ($ledgerId > 0) {
            if ($voucherId > 0) {
                $link = $this->pdo->prepare('UPDATE fuel_general_ledger SET source_type = "fuel_voucher", source_id = :voucher_id WHERE id = :ledger_id AND (source_id IS NULL OR source_id = 0)');
                $link->execute(['voucher_id' => $voucherId, 'ledger_id' => $ledgerId]);
            }
            return;
        }

        $amount = (float)($fiche['montant_fiche'] ?? 0);
        if ($amount <= 0) {
            return;
        }
        $this->addLedger(
            'debit',
            'fuel_voucher',
            $voucherId > 0 ? $voucherId : null,
            $amount,
            'Consommation carburant fiche ' . $numFiche,
            null,
            (int)($fiche['chantier_id'] ?? 0) ?: null,
            (int)($fiche['ligne_debourse_id'] ?? 0) ?: null,
            $numFiche,
            $userId
        );
    }

    public function backfillMissingFicheVouchers(string $dateFrom, int $limit = 20, bool $dryRun = true, int $userId = 0): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
            $dateFrom = $this->activeStartDate();
        }
        $activeStartDate = $this->activeStartDate();
        if ($dateFrom < $activeStartDate) {
            $dateFrom = $activeStartDate;
        }
        $activeStartAt = $this->activeStartAt();
        $limit = max(1, min(200, $limit));

        $stmt = $this->pdo->prepare('
            SELECT f.num_fiche, f.montant_fiche, f.date_creat_fiche,
                   COALESCE(f.date_decaisse, f.date_decaissement, f.date_creat_fiche) AS ref_date,
                   c.code AS chantier_code, c.titre AS chantier_titre,
                   l.designation AS ligne_designation
            FROM fiche_decaissement f
            INNER JOIN ligne_debourse_banamur l ON l.id = f.ligne_debourse_id
            LEFT JOIN chantier_banamur c ON c.id = f.chantier_id
            WHERE COALESCE(f.decaisse, 0) = 1
              AND DATE(COALESCE(f.date_decaisse, f.date_decaissement, f.date_creat_fiche)) >= :date_from
              AND COALESCE(f.date_decaisse, f.date_decaissement, f.date_creat_fiche) >= :active_start_at
              AND (f.chantier_id IS NULL OR c.archived_at IS NULL)
              AND NOT EXISTS (
                  SELECT 1
                  FROM fuel_vouchers v
                  WHERE v.fiche_num COLLATE utf8mb4_general_ci = f.num_fiche COLLATE utf8mb4_general_ci
                  LIMIT 1
              )
              AND LOWER(TRIM(l.designation)) IN ("carburant", "carburants")
            ORDER BY COALESCE(f.date_decaisse, f.date_decaissement, f.date_creat_fiche) DESC, f.id_fiche DESC
            LIMIT ' . $limit
        );
        $stmt->execute(['date_from' => $dateFrom, 'active_start_at' => $activeStartAt]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $generated = [];
        $failed = [];
        if (!$dryRun) {
            foreach ($rows as $row) {
                $numFiche = (string)($row['num_fiche'] ?? '');
                try {
                    $this->registerDecaissedFicheConsumption($numFiche, $userId);
                    $codeStmt = $this->pdo->prepare('
                        SELECT voucher_code
                        FROM fuel_vouchers
                        WHERE fiche_num COLLATE utf8mb4_general_ci = :num COLLATE utf8mb4_general_ci
                        ORDER BY id DESC
                        LIMIT 1
                    ');
                    $codeStmt->execute(['num' => $numFiche]);
                    $generated[] = [
                        'num_fiche' => $numFiche,
                        'voucher_code' => (string)($codeStmt->fetchColumn() ?: ''),
                    ];
                } catch (Throwable $e) {
                    $failed[] = ['num_fiche' => $numFiche, 'error' => $e->getMessage()];
                }
            }
        }

        return [
            'dry_run' => $dryRun,
            'date_from' => $dateFrom,
            'limit' => $limit,
            'candidates' => $rows,
            'candidate_count' => count($rows),
            'generated' => $generated,
            'failed' => $failed,
        ];
    }

    private function ensureVoucherFromFiche(array $fiche, int $userId = 0): int
    {
        $numFiche = trim((string)($fiche['num_fiche'] ?? ''));
        if ($numFiche === '') {
            return 0;
        }

        $exists = $this->pdo->prepare('SELECT id FROM fuel_vouchers WHERE fiche_num = :num ORDER BY id DESC LIMIT 1');
        $exists->execute(['num' => $numFiche]);
        $existingId = (int)($exists->fetchColumn() ?: 0);
        if ($existingId > 0) {
            return $existingId;
        }

        $amount = (float)($fiche['montant_fiche'] ?? 0);
        if ($amount <= 0) {
            return 0;
        }

        $beneficiary = trim((string)($fiche['beficiaire_fiche'] ?? ''));
        $vehicleLabel = trim((string)($fiche['designation_fiche'] ?? ''));
        $purpose = trim((string)($fiche['details_demande'] ?? ''));
        if ($purpose === '') {
            $purpose = trim((string)($fiche['precision_fiche'] ?? ''));
        }

        $stmt = $this->pdo->prepare('
            INSERT INTO fuel_vouchers (
                voucher_code, station_id, chantier_id, ligne_debourse_id, fiche_num,
                requester_name, beneficiary_name, vehicle_label, amount, purpose, status,
                issued_at, consumed_at, created_by_user_id
            ) VALUES (
                :code, NULL, :chantier_id, :ligne_debourse_id, :fiche_num,
                :requester_name, :beneficiary_name, :vehicle_label, :amount, :purpose, "issued",
                NOW(), NOW(), :user_id
            )
        ');
        $stmt->execute([
            'code' => $this->nextVoucherCodeFromFiche($numFiche),
            'chantier_id' => (int)($fiche['chantier_id'] ?? 0) ?: null,
            'ligne_debourse_id' => (int)($fiche['ligne_debourse_id'] ?? 0) ?: null,
            'fiche_num' => $numFiche,
            'requester_name' => $beneficiary,
            'beneficiary_name' => $beneficiary,
            'vehicle_label' => $vehicleLabel,
            'amount' => $amount,
            'purpose' => mb_substr($purpose, 0, 255, 'UTF-8'),
            'user_id' => $userId > 0 ? $userId : null,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function generateFuelDebourses(?int $chantierId = null): array
    {
        $where = 'WHERE c.devis_id IS NOT NULL AND c.devis_id > 0';
        $params = [];
        if ($chantierId && $chantierId > 0) {
            $where .= ' AND c.id = :chantier_id';
            $params['chantier_id'] = $chantierId;
        }

        $where .= ($where === '' ? ' WHERE ' : ' AND ') . 'c.archived_at IS NULL';
        $stmt = $this->pdo->prepare('SELECT c.id, c.code, c.titre, c.devis_id FROM chantier_banamur c ' . $where . ' ORDER BY c.code ASC');
        $stmt->execute($params);
        $chantiers = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $created = 0;
        $existing = 0;
        foreach ($chantiers as $chantier) {
            $devisId = (int)($chantier['devis_id'] ?? 0);
            if ($devisId <= 0) {
                continue;
            }
            if ($this->findFuelLineForDevis($devisId)) {
                $existing++;
                continue;
            }

            $ligneDevisId = $this->findOrCreateFuelLigneDevis($devisId);
            $debourseId = $this->findOrCreateFuelDebourse($devisId, $ligneDevisId);

            $lineStmt = $this->pdo->prepare('
                INSERT INTO ligne_debourse_banamur (debourse_id, categorie, designation, montant, date_debut, date_fin)
                VALUES (:debourse_id, "transport", "Carburant", 0, CURDATE(), CURDATE())
            ');
            $lineStmt->execute(['debourse_id' => $debourseId]);
            $created++;
        }

        return ['created' => $created, 'existing' => $existing, 'scanned' => count($chantiers)];
    }

    private function findFuelLineForDevis(int $devisId): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT l.*
            FROM ligne_debourse_banamur l
            INNER JOIN debourse_banamur d ON d.id = l.debourse_id
            WHERE d.devis_id = :devis_id
              AND (
                LOWER(TRIM(l.designation)) IN ("carburant", "carburants")
                OR LOWER(TRIM(l.categorie)) IN ("carburant", "carburants", "transport logistique")
              )
            LIMIT 1
        ');
        $stmt->execute(['devis_id' => $devisId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        return is_array($row) ? $row : null;
    }

    private function findOrCreateFuelLigneDevis(int $devisId): int
    {
        $stmt = $this->pdo->prepare('
            SELECT id
            FROM ligne_devis_banamur
            WHERE devis_id = :devis_id
              AND LOWER(TRIM(designation)) IN ("transport logistique", "carburant", "carburants")
            ORDER BY id DESC
            LIMIT 1
        ');
        $stmt->execute(['devis_id' => $devisId]);
        $existing = (int)($stmt->fetchColumn() ?: 0);
        if ($existing > 0) {
            return $existing;
        }

        $insert = $this->pdo->prepare('
            INSERT INTO ligne_devis_banamur (devis_id, designation, prix, quantite, unite_id, total, groupe, zone)
            VALUES (:devis_id, "Transport logistique", 0, 1, 1, 0, "", "")
        ');
        $insert->execute(['devis_id' => $devisId]);
        return (int)$this->pdo->lastInsertId();
    }

    private function findOrCreateFuelDebourse(int $devisId, int $ligneDevisId): int
    {
        $stmt = $this->pdo->prepare('
            SELECT id
            FROM debourse_banamur
            WHERE devis_id = :devis_id AND ligne_devis_id = :ligne_devis_id
            ORDER BY id DESC
            LIMIT 1
        ');
        $stmt->execute(['devis_id' => $devisId, 'ligne_devis_id' => $ligneDevisId]);
        $existing = (int)($stmt->fetchColumn() ?: 0);
        if ($existing > 0) {
            return $existing;
        }

        $insert = $this->pdo->prepare('
            INSERT INTO debourse_banamur (devis_id, ligne_devis_id, montant_debourse, responsable_id, date_debut, date_fin)
            VALUES (:devis_id, :ligne_devis_id, 0, 0, CURDATE(), CURDATE())
        ');
        $insert->execute(['devis_id' => $devisId, 'ligne_devis_id' => $ligneDevisId]);
        return (int)$this->pdo->lastInsertId();
    }

    private function isFuelLine(array $fiche): bool
    {
        $hay = strtolower(trim((string)($fiche['ligne_designation'] ?? '') . ' ' . (string)($fiche['ligne_categorie'] ?? '')));
        return (bool)preg_match('/\bcarburants?\b/i', $hay);
    }

    private function addLedger(
        string $movementType,
        string $sourceType,
        ?int $sourceId,
        float $amount,
        string $description,
        ?int $stationId,
        ?int $chantierId,
        ?int $ligneDebourseId,
        string $ficheNum,
        int $userId
    ): void {
        $stmt = $this->pdo->prepare('
            INSERT INTO fuel_general_ledger (
                movement_type, source_type, source_id, station_id, chantier_id, ligne_debourse_id,
                fiche_num, amount, description, created_by_user_id
            ) VALUES (
                :movement_type, :source_type, :source_id, :station_id, :chantier_id, :ligne_debourse_id,
                :fiche_num, :amount, :description, :user_id
            )
        ');
        $stmt->execute([
            'movement_type' => $movementType,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'station_id' => $stationId,
            'chantier_id' => $chantierId,
            'ligne_debourse_id' => $ligneDebourseId,
            'fiche_num' => $ficheNum,
            'amount' => $amount,
            'description' => $description,
            'user_id' => $userId > 0 ? $userId : null,
        ]);
    }

    private function nextVoucherCode(): string
    {
        return 'BE-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    }

    private function nextVoucherCodeFromFiche(string $numFiche): string
    {
        $seq = preg_replace('/\D+/', '', $numFiche);
        $seq = substr(str_pad($seq, 6, '0', STR_PAD_LEFT), -6);
        $base = 'BE-' . date('ym') . '-' . $seq;
        $code = $base;
        $i = 2;
        $stmt = $this->pdo->prepare('SELECT id FROM fuel_vouchers WHERE voucher_code = :code LIMIT 1');
        while (true) {
            $stmt->execute(['code' => $code]);
            if (!$stmt->fetchColumn()) {
                return $code;
            }
            $code = $base . '-' . $i;
            $i++;
        }
    }

    private function storeReceipt(array $file, int $userId): array
    {
        if (!$file || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return [];
        }
        if ((int)($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Recu non charge correctement.');
        }
        $name = trim((string)($file['name'] ?? ''));
        $tmp = (string)($file['tmp_name'] ?? '');
        if ($name === '' || $tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('Recu invalide.');
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'pdf'], true)) {
            throw new RuntimeException('Format de recu non supporte.');
        }
        $dirRel = 'uploads/fuel_deposits/' . date('Ymd') . '/' . max(0, $userId);
        $dirAbs = dirname(__DIR__) . '/' . $dirRel;
        if (!is_dir($dirAbs) && !mkdir($dirAbs, 0775, true) && !is_dir($dirAbs)) {
            throw new RuntimeException('Impossible de creer le dossier des recus.');
        }
        $stored = bin2hex(random_bytes(12)) . '.' . $ext;
        if (!move_uploaded_file($tmp, $dirAbs . '/' . $stored)) {
            throw new RuntimeException('Impossible de sauvegarder le recu.');
        }
        return ['path' => $dirRel . '/' . $stored, 'name' => $name];
    }

    private function money(string $value): float
    {
        $value = str_replace([" ", "\xc2\xa0"], '', trim($value));
        $value = str_replace(',', '.', $value);
        return (float)$value;
    }
}
