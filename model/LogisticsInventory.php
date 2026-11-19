<?php

require_once __DIR__ . '/UniteMesure.php';

class LogisticsInventory
{
    private PDO $pdo;
    private array $tableCache = [];
    private array $columnCache = [];
    private ?\UniteMesure $uniteMesureModel = null;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function getInventoryData(array $filters = []): array
    {
        $normalizedFilters = $this->normalizeFilters($filters);
        $inventoryConfigured = $this->tableExists('logistics_assets');

        return [
            'configured' => $inventoryConfigured,
            'filters' => $normalizedFilters,
            'summary' => $inventoryConfigured ? $this->getInventorySummary($normalizedFilters) : $this->getFallbackSummary(),
            'assets' => $inventoryConfigured ? $this->getAssets($normalizedFilters) : [],
            'repairs' => $inventoryConfigured ? $this->getRepairs($normalizedFilters) : [],
            'movements' => $inventoryConfigured ? $this->getRecentMovements($normalizedFilters) : [],
            'locations' => $this->getLocationOptions(),
            'categories' => $inventoryConfigured ? $this->getAssetCategories() : [],
            'fallback_consumption' => $this->getFallbackConsumptionBySite(),
            'fallback_material_requests' => $this->getFallbackMaterialRequests(),
            'setup_file' => 'Database/add_logistics_inventory_tracking.sql',
        ];
    }

    public function getInventoryWidgetData(): array
    {
        if (!$this->tableExists('logistics_assets')) {
            return [
                'configured' => false,
                'available_total' => 0,
                'unavailable_total' => 0,
                'maintenance_total' => 0,
                'critical_stock_total' => 0,
                'repair_open_total' => 0,
                'assets_in_scope_total' => 0,
                'recent_repairs' => [],
                'critical_assets' => [],
            ];
        }

        $summary = $this->getInventorySummary([]);
        return [
            'configured' => true,
            'available_total' => (int) ($summary['available_assets_total'] ?? 0),
            'unavailable_total' => (int) ($summary['unavailable_assets_total'] ?? 0),
            'maintenance_total' => (int) ($summary['maintenance_assets_total'] ?? 0),
            'critical_stock_total' => (int) ($summary['critical_stock_total'] ?? 0),
            'repair_open_total' => (int) ($summary['repair_open_total'] ?? 0),
            'assets_in_scope_total' => (int) ($summary['assets_total'] ?? 0),
            'recent_repairs' => $this->getRepairs(['repair_status' => 'ouverte'], 4),
            'critical_assets' => $this->getCriticalAssets(4),
        ];
    }

    public function getSetupStatus(): array
    {
        $chantierExists = $this->tableExists('chantier_banamur');
        $chantierHasId = $chantierExists && $this->columnExists('chantier_banamur', 'id');
        $chantierEngine = $chantierExists ? $this->getTableEngine('chantier_banamur') : null;
        $unitsExists = $this->tableExists('unites_mesure');
        $unitsHasId = $unitsExists && $this->columnExists('unites_mesure', 'id');
        $unitsEngine = $unitsExists ? $this->getTableEngine('unites_mesure') : null;

        return [
            'inventory_ready' => $this->tableExists('logistics_assets')
                && $this->tableExists('logistics_asset_repairs')
                && $this->tableExists('logistics_asset_movements'),
            'assets_table_ready' => $this->tableExists('logistics_assets'),
            'repairs_table_ready' => $this->tableExists('logistics_asset_repairs'),
            'movements_table_ready' => $this->tableExists('logistics_asset_movements'),
            'chantier_exists' => $chantierExists,
            'chantier_has_id' => $chantierHasId,
            'chantier_engine' => $chantierEngine,
            'chantier_reference_ready' => $chantierHasId && strtoupper((string) $chantierEngine) === 'INNODB',
            'units_exists' => $unitsExists,
            'units_has_id' => $unitsHasId,
            'units_engine' => $unitsEngine,
            'unit_reference_ready' => $unitsHasId && strtoupper((string) $unitsEngine) === 'INNODB',
        ];
    }

    public function installSchemaFromFile(string $sqlFilePath): array
    {
        if (!is_file($sqlFilePath)) {
            return [
                'success' => false,
                'message' => 'Le fichier SQL d\'initialisation est introuvable.',
                'warnings' => [],
                'setup_status' => $this->getSetupStatus(),
            ];
        }

        $sql = file_get_contents($sqlFilePath);
        if ($sql === false || trim($sql) === '') {
            return [
                'success' => false,
                'message' => 'Le fichier SQL est vide ou illisible.',
                'warnings' => [],
                'setup_status' => $this->getSetupStatus(),
            ];
        }

        try {
            $this->pdo->exec($sql);
            $this->resetSchemaCache();
        } catch (PDOException $exception) {
            $this->resetSchemaCache();

            return [
                'success' => false,
                'message' => 'L\'initialisation SQL a échoué : ' . $exception->getMessage(),
                'warnings' => [],
                'setup_status' => $this->getSetupStatus(),
            ];
        }

        $setupStatus = $this->getSetupStatus();
        $warnings = [];

        if (!$setupStatus['chantier_reference_ready']) {
            $warnings[] = 'La table chantier_banamur n\'est pas encore exploitable pour les clés étrangères. Les tables logistiques sont créées, et les liens chantier seront ajoutés automatiquement lors d\'une prochaine exécution du script quand le prérequis sera prêt.';
        }

        if (!$setupStatus['unit_reference_ready']) {
            $warnings[] = 'La table unites_mesure n\'est pas encore exploitable pour la clé étrangère d\'unité. Le stock fonctionne, et le lien sera ajouté automatiquement lors d\'une prochaine exécution du script quand le prérequis sera prêt.';
        }

        return [
            'success' => (bool) $setupStatus['inventory_ready'],
            'message' => $setupStatus['inventory_ready']
                ? 'Les tables logistiques ont été créées ou mises à jour avec succès.'
                : 'Le script s\'est exécuté mais toutes les tables attendues ne sont pas encore disponibles.',
            'warnings' => $warnings,
            'setup_status' => $setupStatus,
        ];
    }

    public function getAssetRegistrationOptions(): array
    {
        return [
            'locations' => $this->getLocationOptions(),
            'units' => $this->getUnits(),
            'asset_types' => ['materiel', 'materiaux', 'consommable', 'vehicule', 'outillage', 'epi', 'bureau'],
            'condition_states' => ['neuf', 'bon', 'moyen', 'a_reparer', 'hors_service'],
            'availability_statuses' => ['disponible', 'affecte', 'maintenance', 'reserve', 'rupture', 'hors_service'],
            'location_scopes' => ['chantier', 'bureau', 'depot', 'atelier', 'transit'],
        ];
    }

    public function createAsset(array $payload, string $createdBy = ''): array
    {
        if (!$this->tableExists('logistics_assets')) {
            return [
                'success' => false,
                'message' => 'Les tables d\'inventaire ne sont pas encore initialisées. Lancez d\'abord l\'initialisation du module.',
            ];
        }

        $assetCode = trim((string) ($payload['asset_code'] ?? ''));
        $assetCodeMode = trim((string) ($payload['asset_code_mode'] ?? 'auto'));
        $assetType = trim((string) ($payload['asset_type'] ?? 'materiel'));
        $category = trim((string) ($payload['category'] ?? ''));
        $designation = trim((string) ($payload['designation'] ?? ''));
        $unit = trim((string) ($payload['unit'] ?? 'u'));
        $uniteMesureId = (int) ($payload['unite_mesure_id'] ?? 0);
        $uniteMesureId = $uniteMesureId > 0 ? $uniteMesureId : null;
        $conditionState = trim((string) ($payload['condition_state'] ?? 'bon'));
        $availabilityStatus = trim((string) ($payload['availability_status'] ?? 'disponible'));
        $locationScope = trim((string) ($payload['location_scope'] ?? 'bureau'));
        $locationLabel = trim((string) ($payload['location_label'] ?? ''));
        $serialNumber = trim((string) ($payload['serial_number'] ?? ''));
        $acquisitionDate = trim((string) ($payload['acquisition_date'] ?? ''));
        $notes = trim((string) ($payload['notes'] ?? ''));

        $quantityTotal = max(0, (float) ($payload['quantity_total'] ?? 0));
        $quantityAvailableInput = trim((string) ($payload['quantity_available'] ?? ''));
        $quantityAvailable = $quantityAvailableInput === '' ? $quantityTotal : max(0, (float) $quantityAvailableInput);
        $minThreshold = max(0, (float) ($payload['min_threshold'] ?? 0));
        $chantierId = (int) ($payload['chantier_id'] ?? 0);
        $chantierId = $chantierId > 0 ? $chantierId : null;
        $units = $this->getUnits();
        $hasUnitColumn = $this->columnExists('logistics_assets', 'unite_mesure_id');

        if ($assetCode === '' || $assetCodeMode === 'auto') {
            $assetCode = $this->generateNextAssetCode($assetType, $category, $designation);
        }

        if ($units !== []) {
            if (!$hasUnitColumn) {
                return [
                    'success' => false,
                    'message' => 'Le schéma stock doit être remis à niveau pour utiliser la table des unités. Relancez l\'initialisation du module.',
                ];
            }

            if ($uniteMesureId === null) {
                return [
                    'success' => false,
                    'message' => 'Sélectionnez une unité de mesure.',
                ];
            }

            $selectedUnit = $this->getUnitById($uniteMesureId);
            if ($selectedUnit === null) {
                return [
                    'success' => false,
                    'message' => 'L\'unité sélectionnée est introuvable.',
                ];
            }

            $unit = trim((string) ($selectedUnit['symbole'] ?? $unit));
        }

        if ($assetCode === '' || $category === '' || $designation === '') {
            return [
                'success' => false,
                'message' => 'Les champs code article, catégorie et désignation sont obligatoires.',
            ];
        }

        if ($quantityAvailable > $quantityTotal) {
            return [
                'success' => false,
                'message' => 'La quantité disponible ne peut pas dépasser la quantité totale.',
            ];
        }

        if ($locationScope === 'chantier' && $chantierId === null && $locationLabel === '') {
            return [
                'success' => false,
                'message' => 'Pour une localisation chantier, sélectionnez un chantier ou indiquez un libellé d\'emplacement.',
            ];
        }

        if ($quantityAvailable <= 0 && $availabilityStatus === 'disponible') {
            $availabilityStatus = 'rupture';
        }

        $insertAssetSql = 'INSERT INTO logistics_assets (
                asset_code,
                asset_type,
                category,
                designation,
            unite_mesure_id,
                unit,
                quantity_total,
                quantity_available,
                min_threshold,
                condition_state,
                availability_status,
                location_scope,
                chantier_id,
                location_label,
                serial_number,
                acquisition_date,
                notes,
                last_seen_at
            ) VALUES (
                :asset_code,
                :asset_type,
                :category,
                :designation,
                :unite_mesure_id,
                :unit,
                :quantity_total,
                :quantity_available,
                :min_threshold,
                :condition_state,
                :availability_status,
                :location_scope,
                :chantier_id,
                :location_label,
                :serial_number,
                :acquisition_date,
                :notes,
                NOW()
            )';

        try {
            $this->pdo->beginTransaction();

            $statement = $this->pdo->prepare($insertAssetSql);
            $statement->execute([
                'asset_code' => $assetCode,
                'asset_type' => $assetType,
                'category' => $category,
                'designation' => $designation,
                'unite_mesure_id' => $uniteMesureId,
                'unit' => $unit !== '' ? $unit : 'u',
                'quantity_total' => $quantityTotal,
                'quantity_available' => $quantityAvailable,
                'min_threshold' => $minThreshold,
                'condition_state' => $conditionState !== '' ? $conditionState : 'bon',
                'availability_status' => $availabilityStatus !== '' ? $availabilityStatus : 'disponible',
                'location_scope' => $locationScope !== '' ? $locationScope : 'bureau',
                'chantier_id' => $chantierId,
                'location_label' => $locationLabel !== '' ? $locationLabel : null,
                'serial_number' => $serialNumber !== '' ? $serialNumber : null,
                'acquisition_date' => $acquisitionDate !== '' ? $acquisitionDate : null,
                'notes' => $notes !== '' ? $notes : null,
            ]);

            $assetId = (int) $this->pdo->lastInsertId();

            if ($assetId > 0 && $quantityTotal > 0 && $this->tableExists('logistics_asset_movements')) {
                $movementStatement = $this->pdo->prepare('INSERT INTO logistics_asset_movements (
                        asset_id,
                        movement_type,
                        quantity,
                        from_scope,
                        from_label,
                        to_scope,
                        to_chantier_id,
                        to_label,
                        reference_number,
                        note,
                        created_by,
                        performed_at
                    ) VALUES (
                        :asset_id,
                        "entree",
                        :quantity,
                        "fournisseur",
                        :from_label,
                        :to_scope,
                        :to_chantier_id,
                        :to_label,
                        :reference_number,
                        :note,
                        :created_by,
                        NOW()
                    )');

                $movementStatement->execute([
                    'asset_id' => $assetId,
                    'quantity' => $quantityTotal,
                    'from_label' => 'Stock initial',
                    'to_scope' => $locationScope !== '' ? $locationScope : 'bureau',
                    'to_chantier_id' => $chantierId,
                    'to_label' => $locationLabel !== '' ? $locationLabel : null,
                    'reference_number' => 'INIT-' . $assetCode,
                    'note' => 'Création initiale de l\'article en stock',
                    'created_by' => $createdBy !== '' ? $createdBy : null,
                ]);
            }

            $this->pdo->commit();

            return [
                'success' => true,
                'message' => 'L\'article de stock ' . $assetCode . ' a été enregistré avec succès.',
                'asset_id' => $assetId,
                'asset_code' => $assetCode,
            ];
        } catch (PDOException $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            $errorMessage = $exception->getMessage();
            if ((string) $exception->getCode() === '23000') {
                $errorMessage = 'Le code article existe déjà. Utilisez un code unique pour chaque article.';
            }

            return [
                'success' => false,
                'message' => 'Enregistrement impossible : ' . $errorMessage,
            ];
        }
    }

    public function suggestNextAssetCode(array $payload): string
    {
        $assetType = trim((string) ($payload['asset_type'] ?? 'materiel'));
        $category = trim((string) ($payload['category'] ?? ''));
        $designation = trim((string) ($payload['designation'] ?? ''));

        return $this->generateNextAssetCode($assetType, $category, $designation);
    }

    private function normalizeFilters(array $filters): array
    {
        return [
            'search' => trim((string) ($filters['search'] ?? '')),
            'status' => trim((string) ($filters['status'] ?? '')),
            'location_scope' => trim((string) ($filters['location_scope'] ?? '')),
            'site_code' => trim((string) ($filters['site_code'] ?? '')),
            'category' => trim((string) ($filters['category'] ?? '')),
            'repair_status' => trim((string) ($filters['repair_status'] ?? '')),
        ];
    }

    private function generateNextAssetCode(string $assetType, string $category, string $designation): string
    {
        $prefix = $this->buildAssetCodePrefix($assetType, $category, $designation);

        if (!$this->tableExists('logistics_assets')) {
            return $prefix . '-001';
        }

        $statement = $this->pdo->prepare('SELECT asset_code FROM logistics_assets WHERE asset_code LIKE :prefix');
        $statement->execute(['prefix' => $prefix . '-%']);
        $existingCodes = $statement->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $maxSequence = 0;
        foreach ($existingCodes as $existingCode) {
            if (preg_match('/^' . preg_quote($prefix, '/') . '-(\d+)$/', (string) $existingCode, $matches)) {
                $maxSequence = max($maxSequence, (int) $matches[1]);
            }
        }

        return sprintf('%s-%03d', $prefix, $maxSequence + 1);
    }

    private function buildAssetCodePrefix(string $assetType, string $category, string $designation): string
    {
        $source = $designation !== '' ? $designation : ($category !== '' ? $category : $assetType);
        $sourceLetters = preg_replace('/[^A-Z]/', '', $this->normalizeAssetCodeFragment($source)) ?? '';
        $fallbackLetters = preg_replace('/[^A-Z]/', '', $this->normalizeAssetCodeFragment($assetType)) ?? '';

        $letters = $sourceLetters;
        if (strlen($letters) < 3) {
            $letters .= $fallbackLetters;
        }

        return substr(str_pad($letters, 3, 'X'), 0, 3);
    }

    private function normalizeAssetCodeFragment(string $value): string
    {
        $normalized = $value;
        if (function_exists('iconv')) {
            $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if ($converted !== false) {
                $normalized = $converted;
            }
        }

        $normalized = strtoupper($normalized);
        return preg_replace('/[^A-Z0-9 ]+/', '', $normalized) ?? '';
    }

    private function getInventorySummary(array $filters): array
    {
        $where = [];
        $params = [];
        $this->appendAssetFilters($filters, $where, $params);
        $where[]='(a.chantier_id IS NULL OR c.archived_at IS NULL)';

        $sql = 'SELECT
                COUNT(*) AS assets_total,
                SUM(CASE WHEN availability_status = "disponible" AND quantity_available > 0 THEN 1 ELSE 0 END) AS available_assets_total,
                SUM(CASE WHEN availability_status IN ("affecte", "reserve", "rupture", "hors_service") OR quantity_available <= 0 THEN 1 ELSE 0 END) AS unavailable_assets_total,
                SUM(CASE WHEN availability_status = "maintenance" OR condition_state = "a_reparer" THEN 1 ELSE 0 END) AS maintenance_assets_total,
                SUM(CASE WHEN quantity_available <= min_threshold THEN 1 ELSE 0 END) AS critical_stock_total,
                COALESCE(SUM(quantity_total), 0) AS quantity_total,
                COALESCE(SUM(quantity_available), 0) AS quantity_available_total
            FROM logistics_assets a LEFT JOIN chantier_banamur c ON c.id=a.chantier_id';

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $summary = $statement->fetch(PDO::FETCH_ASSOC) ?: [];

        $repairSql = 'SELECT COUNT(*)
            FROM logistics_asset_repairs r
            INNER JOIN logistics_assets a ON a.id = r.asset_id
            LEFT JOIN chantier_banamur c ON c.id = a.chantier_id';

        if ($where !== []) {
            $repairSql .= ' WHERE ' . implode(' AND ', $where);
            $repairSql .= ' AND r.repair_status IN ("ouverte", "en_cours")';
        } else {
            $repairSql .= ' WHERE r.repair_status IN ("ouverte", "en_cours")';
        }

        $repairStatement = $this->pdo->prepare($repairSql);
        $repairStatement->execute($params);
        $summary['repair_open_total'] = (int) $repairStatement->fetchColumn();

        return $summary;
    }

    private function getAssets(array $filters): array
    {
        $where = [];
        $params = [];
        $this->appendAssetFilters($filters, $where, $params);
        $where[]='(a.chantier_id IS NULL OR c.archived_at IS NULL)';
        $hasUnitReference = $this->tableExists('unites_mesure') && $this->columnExists('logistics_assets', 'unite_mesure_id');
        $unitSelect = $hasUnitReference
            ? 'COALESCE(u.symbole, a.unit, "u") AS unit_symbol, COALESCE(u.libelle, a.unit, "") AS unit_label,'
            : 'COALESCE(a.unit, "u") AS unit_symbol, "" AS unit_label,';
        $unitJoin = $hasUnitReference ? ' LEFT JOIN unites_mesure u ON u.id = a.unite_mesure_id' : '';

        $sql = 'SELECT
                a.id,
                a.asset_code,
                a.asset_type,
                a.category,
                a.designation,
                a.unit,
                a.quantity_total,
                a.quantity_available,
                a.min_threshold,
                a.condition_state,
                a.availability_status,
                a.location_scope,
                a.location_label,
                a.serial_number,
                a.last_seen_at,
                ' . $unitSelect . '
                COALESCE(c.code, "") AS chantier_code,
                COALESCE(c.titre, "") AS chantier_label,
                (
                    SELECT COUNT(*)
                    FROM logistics_asset_repairs r
                    WHERE r.asset_id = a.id AND r.repair_status IN ("ouverte", "en_cours")
                ) AS open_repairs
            FROM logistics_assets a
            LEFT JOIN chantier_banamur c ON c.id = a.chantier_id' . $unitJoin;

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY
                CASE a.availability_status
                    WHEN "maintenance" THEN 1
                    WHEN "rupture" THEN 2
                    WHEN "hors_service" THEN 3
                    WHEN "affecte" THEN 4
                    ELSE 5
                END,
                (a.quantity_available <= a.min_threshold) DESC,
                a.designation ASC
            LIMIT 200';

        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function getRepairs(array $filters, int $limit = 12): array
    {
        if (!$this->tableExists('logistics_asset_repairs')) {
            return [];
        }

        $where = [];
        $params = [];
        $this->appendAssetFilters($filters, $where, $params, 'a', 'c');
        $where[]='(a.chantier_id IS NULL OR c.archived_at IS NULL)';

        if (!empty($filters['repair_status'])) {
            $where[] = 'r.repair_status = :repair_status';
            $params['repair_status'] = $filters['repair_status'];
        }

        $sql = 'SELECT
                r.id,
                r.issue_label,
                r.repair_status,
                r.priority_level,
                r.provider_name,
                r.estimated_cost,
                r.actual_cost,
                r.opened_at,
                r.due_at,
                a.asset_code,
                a.designation,
                a.availability_status,
                COALESCE(c.code, "") AS chantier_code,
                COALESCE(c.titre, "") AS chantier_label
            FROM logistics_asset_repairs r
            INNER JOIN logistics_assets a ON a.id = r.asset_id
            LEFT JOIN chantier_banamur c ON c.id = a.chantier_id';

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY
                CASE r.priority_level
                    WHEN "critique" THEN 1
                    WHEN "haute" THEN 2
                    WHEN "normale" THEN 3
                    ELSE 4
                END,
                r.opened_at DESC
            LIMIT ' . max(1, $limit);

        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function getRecentMovements(array $filters): array
    {
        if (!$this->tableExists('logistics_asset_movements')) {
            return [];
        }

        $where = [];
        $params = [];
        $this->appendAssetFilters($filters, $where, $params, 'a', 'c');
        $where[]='(a.chantier_id IS NULL OR c.archived_at IS NULL)';

        $sql = 'SELECT
                m.id,
                m.movement_type,
                m.quantity,
                m.from_scope,
                m.from_label,
                m.to_scope,
                m.to_label,
                m.reference_number,
                m.performed_at,
                a.asset_code,
                a.designation,
                COALESCE(c.code, "") AS chantier_code,
                COALESCE(c.titre, "") AS chantier_label
            FROM logistics_asset_movements m
            INNER JOIN logistics_assets a ON a.id = m.asset_id
            LEFT JOIN chantier_banamur c ON c.id = a.chantier_id';

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY m.performed_at DESC LIMIT 12';

        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function getAssetCategories(): array
    {
        $statement = $this->pdo->query('SELECT DISTINCT category FROM logistics_assets WHERE TRIM(category) <> "" ORDER BY category ASC');
        return $statement ? ($statement->fetchAll(PDO::FETCH_COLUMN) ?: []) : [];
    }

    private function getUnits(): array
    {
        if (!$this->tableExists('unites_mesure')) {
            return [];
        }

        return $this->getUniteMesureModel()->getAll();
    }

    private function getUnitById(int $unitId): ?array
    {
        if ($unitId <= 0 || !$this->tableExists('unites_mesure')) {
            return null;
        }

        $unit = $this->getUniteMesureModel()->getById($unitId);
        return is_array($unit) ? $unit : null;
    }

    private function getUniteMesureModel(): \UniteMesure
    {
        if ($this->uniteMesureModel === null) {
            $this->uniteMesureModel = new \UniteMesure($this->pdo);
        }

        return $this->uniteMesureModel;
    }

    private function getLocationOptions(): array
    {
        if (!$this->tableExists('chantier_banamur')) {
            return [];
        }

        $sql = 'SELECT id, code, titre FROM chantier_banamur';
        if ($this->columnExists('chantier_banamur', 'archived_at')) {
            $sql .= ' WHERE archived_at IS NULL';
        }
        $sql .= ' ORDER BY code ASC';

        $statement = $this->pdo->query($sql);
        return $statement ? ($statement->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    private function getCriticalAssets(int $limit): array
    {
        $sql = 'SELECT
                a.asset_code,
                a.designation,
                a.quantity_available,
                a.min_threshold,
                a.availability_status,
                a.location_scope,
                COALESCE(c.code, "") AS chantier_code,
                COALESCE(c.titre, "") AS chantier_label
            FROM logistics_assets a
            LEFT JOIN chantier_banamur c ON c.id = a.chantier_id
            WHERE a.quantity_available <= a.min_threshold
               OR a.availability_status IN ("maintenance", "rupture", "hors_service")
            ORDER BY
                (a.quantity_available <= a.min_threshold) DESC,
                CASE a.availability_status
                    WHEN "maintenance" THEN 1
                    WHEN "rupture" THEN 2
                    WHEN "hors_service" THEN 3
                    ELSE 4
                END,
                a.designation ASC
            LIMIT ' . max(1, $limit);

        $statement = $this->pdo->query($sql);
        return $statement ? ($statement->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    private function getFallbackSummary(): array
    {
        $summary = [
            'assets_total' => 0,
            'available_assets_total' => 0,
            'unavailable_assets_total' => 0,
            'maintenance_assets_total' => 0,
            'critical_stock_total' => 0,
            'quantity_total' => 0,
            'quantity_available_total' => 0,
            'repair_open_total' => 0,
        ];

        if (!$this->tableExists('fiche_decaissement') || !$this->tableExists('ligne_debourse_banamur')) {
            return $summary;
        }

        $sql = "SELECT
                COUNT(*) AS assets_total,
                COUNT(DISTINCT f.chantier_id) AS available_assets_total,
                COALESCE(SUM(f.montant_fiche), 0) AS quantity_total
            FROM fiche_decaissement f
            INNER JOIN ligne_debourse_banamur l ON l.id = f.ligne_debourse_id
            LEFT JOIN chantier_banamur c ON c.id=f.chantier_id
            WHERE l.categorie IN ('matériel', 'matériaux') AND (f.chantier_id IS NULL OR c.archived_at IS NULL)";

        $statement = $this->pdo->query($sql);
        $row = $statement ? ($statement->fetch(PDO::FETCH_ASSOC) ?: []) : [];
        $summary['assets_total'] = (int) ($row['assets_total'] ?? 0);
        $summary['available_assets_total'] = (int) ($row['available_assets_total'] ?? 0);
        $summary['quantity_total'] = (float) ($row['quantity_total'] ?? 0);

        return $summary;
    }

    private function getFallbackConsumptionBySite(): array
    {
        if (!$this->tableExists('fiche_decaissement') || !$this->tableExists('ligne_debourse_banamur')) {
            return [];
        }

        $sql = "SELECT
                COALESCE(c.code, 'Sans chantier') AS chantier_code,
                COALESCE(c.titre, 'Non rattache') AS chantier_label,
                COUNT(*) AS files_count,
                COALESCE(SUM(f.montant_fiche), 0) AS amount_total
            FROM fiche_decaissement f
            INNER JOIN ligne_debourse_banamur l ON l.id = f.ligne_debourse_id
            LEFT JOIN chantier_banamur c ON c.id = f.chantier_id
            WHERE l.categorie IN ('matériel', 'matériaux') AND (f.chantier_id IS NULL OR c.archived_at IS NULL)
            GROUP BY c.id, c.code, c.titre
            ORDER BY amount_total DESC, files_count DESC
            LIMIT 8";

        $statement = $this->pdo->query($sql);
        return $statement ? ($statement->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    private function getFallbackMaterialRequests(): array
    {
        if (!$this->tableExists('fiche_decaissement') || !$this->tableExists('ligne_debourse_banamur')) {
            return [];
        }

        $sql = "SELECT
                f.num_fiche,
                f.beficiaire_fiche,
                f.montant_fiche,
                f.date_creat_fiche,
                l.categorie,
                l.designation,
                COALESCE(c.code, '') AS chantier_code,
                COALESCE(c.titre, 'Non rattache') AS chantier_label,
                f.approuve,
                f.conforme,
                f.decaisse
            FROM fiche_decaissement f
            INNER JOIN ligne_debourse_banamur l ON l.id = f.ligne_debourse_id
            LEFT JOIN chantier_banamur c ON c.id = f.chantier_id
            WHERE l.categorie IN ('matériel', 'matériaux') AND (f.chantier_id IS NULL OR c.archived_at IS NULL)
            ORDER BY COALESCE(f.created_at, f.date_creat_fiche) DESC
            LIMIT 10";

        $statement = $this->pdo->query($sql);
        return $statement ? ($statement->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    private function appendAssetFilters(array $filters, array &$where, array &$params, string $assetAlias = 'a', string $chantierAlias = 'c'): void
    {
        if (!empty($filters['search'])) {
            $where[] = '(' . $assetAlias . '.asset_code LIKE :search OR ' . $assetAlias . '.designation LIKE :search OR ' . $assetAlias . '.serial_number LIKE :search)';
            $params['search'] = '%' . $filters['search'] . '%';
        }

        if (!empty($filters['status'])) {
            $where[] = $assetAlias . '.availability_status = :status';
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['location_scope'])) {
            $where[] = $assetAlias . '.location_scope = :location_scope';
            $params['location_scope'] = $filters['location_scope'];
        }

        if (!empty($filters['site_code'])) {
            $where[] = $chantierAlias . '.code = :site_code';
            $params['site_code'] = $filters['site_code'];
        }

        if (!empty($filters['category'])) {
            $where[] = $assetAlias . '.category = :category';
            $params['category'] = $filters['category'];
        }
    }

    private function columnExists(string $tableName, string $columnName): bool
    {
        $cacheKey = $tableName . '.' . $columnName;
        if (array_key_exists($cacheKey, $this->columnCache)) {
            return $this->columnCache[$cacheKey];
        }

        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name');
        $statement->execute([
            'table_name' => $tableName,
            'column_name' => $columnName,
        ]);

        $this->columnCache[$cacheKey] = (int) $statement->fetchColumn() > 0;
        return $this->columnCache[$cacheKey];
    }

    private function getTableEngine(string $tableName): ?string
    {
        $statement = $this->pdo->prepare('SELECT ENGINE FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name LIMIT 1');
        $statement->execute(['table_name' => $tableName]);
        $engine = $statement->fetchColumn();
        return $engine !== false ? (string) $engine : null;
    }

    private function resetSchemaCache(): void
    {
        $this->tableCache = [];
        $this->columnCache = [];
    }

    private function tableExists(string $tableName): bool
    {
        if (array_key_exists($tableName, $this->tableCache)) {
            return $this->tableCache[$tableName];
        }

        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name');
        $statement->execute(['table_name' => $tableName]);
        $this->tableCache[$tableName] = (int) $statement->fetchColumn() > 0;
        return $this->tableCache[$tableName];
    }
}
