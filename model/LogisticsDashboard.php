<?php

require_once __DIR__ . '/LogisticsInventory.php';

class LogisticsDashboard
{
    private PDO $pdo;
    private array $tableCache = [];
    private array $columnCache = [];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function getDashboardData(): array
    {
        $fuelSummary = $this->getFuelSummary();
        $transportSummary = $this->getTransportSummary();
        $expenseSummary = $this->getExpenseSummary();
        $recentFuelRequests = $this->getRecentFuelRequests();
        $recentLogisticsFiles = $this->getRecentLogisticsFiles();
        $inventory = new \LogisticsInventory($this->pdo);
        $inventoryWidget = $inventory->getInventoryWidgetData();

        return [
            'summary' => [
                'fuel_requests_total' => (int) ($fuelSummary['total_requests'] ?? 0),
                'fuel_amount_total' => (float) ($fuelSummary['total_amount'] ?? 0),
                'fuel_quantity_total' => (float) ($fuelSummary['total_quantity'] ?? 0),
                'fuel_pending_total' => (int) ($fuelSummary['pending_requests'] ?? 0),
                'fuel_validated_total' => (int) ($fuelSummary['validated_requests'] ?? 0),
                'fuel_unique_vehicles' => (int) ($fuelSummary['unique_vehicles'] ?? 0),
                'transport_trips_total' => (int) ($transportSummary['trips_total'] ?? 0),
                'transport_sites_total' => (int) ($transportSummary['sites_total'] ?? 0),
                'transport_vehicles_total' => (int) ($transportSummary['vehicles_total'] ?? 0),
                'transport_volume_total' => (float) ($transportSummary['volume_total'] ?? 0),
                'transport_fuel_total' => (float) ($transportSummary['fuel_total'] ?? 0),
                'expense_total' => (float) ($expenseSummary['expense_total'] ?? 0),
                'expense_files_total' => (int) ($expenseSummary['files_total'] ?? 0),
                'active_sites_total' => (int) ($expenseSummary['active_sites_total'] ?? 0),
                'delivered_files_total' => (int) ($expenseSummary['delivered_files_total'] ?? 0),
                'approval_backlog_total' => (int) ($expenseSummary['approval_backlog_total'] ?? 0),
                'inventory_assets_total' => (int) ($inventoryWidget['assets_in_scope_total'] ?? 0),
                'inventory_available_total' => (int) ($inventoryWidget['available_total'] ?? 0),
                'inventory_unavailable_total' => (int) ($inventoryWidget['unavailable_total'] ?? 0),
                'inventory_maintenance_total' => (int) ($inventoryWidget['maintenance_total'] ?? 0),
                'inventory_critical_total' => (int) ($inventoryWidget['critical_stock_total'] ?? 0),
                'inventory_repairs_open_total' => (int) ($inventoryWidget['repair_open_total'] ?? 0),
            ],
            'expense_categories' => $expenseSummary['categories'],
            'top_sites' => $expenseSummary['top_sites'],
            'fuel_timeline' => $this->getFuelTimeline(),
            'recent_fuel_requests' => $recentFuelRequests,
            'recent_logistics_files' => $recentLogisticsFiles,
            'inventory_widget' => $inventoryWidget,
            'alerts' => $this->buildAlerts($fuelSummary, $transportSummary, $expenseSummary, $recentFuelRequests),
            'quick_links' => $this->buildQuickLinks(),
        ];
    }

    private function getFuelSummary(): array
    {
        if (!$this->tableExists('demande_essence')) {
            return [];
        }

        $hasValidationStatus = $this->columnExists('demande_essence', 'validation_status');
        $hasDisabled = $this->columnExists('demande_essence', 'desactive');

        $sql = 'SELECT
                    COUNT(*) AS total_requests,
                    COALESCE(SUM(montant), 0) AS total_amount,
                    COALESCE(SUM(quantite), 0) AS total_quantity,
                    COUNT(DISTINCT NULLIF(TRIM(vehicule), "")) AS unique_vehicles,'
            . ($hasValidationStatus
                ? " SUM(CASE WHEN validation_status = 'validated' THEN 1 ELSE 0 END) AS validated_requests,
                    SUM(CASE WHEN validation_status IS NULL OR validation_status = '' OR validation_status IN ('pending', 'submitted') THEN 1 ELSE 0 END) AS pending_requests"
                : ' 0 AS validated_requests,
                    0 AS pending_requests')
            . ' FROM demande_essence'
            . ($hasDisabled ? ' WHERE COALESCE(desactive, 0) = 0' : '');

        $statement = $this->pdo->query($sql);
        return $statement ? ($statement->fetch(PDO::FETCH_ASSOC) ?: []) : [];
    }

    private function getTransportSummary(): array
    {
        if (!$this->tableExists('fiche_transport_spec')) {
            return [];
        }

        $sql = 'SELECT
                    COUNT(*) AS trips_total,
                    COUNT(DISTINCT NULLIF(TRIM(chantier_code), "")) AS sites_total,
                    COUNT(DISTINCT NULLIF(TRIM(vehicule_matricule), "")) AS vehicles_total,
                    COALESCE(SUM(volume_m3), 0) AS volume_total,
                    COALESCE(SUM(quantite_carburant_litre), 0) AS fuel_total
                FROM fiche_transport_spec';

        $statement = $this->pdo->query($sql);
        return $statement ? ($statement->fetch(PDO::FETCH_ASSOC) ?: []) : [];
    }

    private function getExpenseSummary(): array
    {
        if (!$this->tableExists('fiche_decaissement') || !$this->tableExists('ligne_debourse_banamur')) {
            return [
                'expense_total' => 0.0,
                'files_total' => 0,
                'active_sites_total' => 0,
                'delivered_files_total' => 0,
                'approval_backlog_total' => 0,
                'categories' => [],
                'top_sites' => [],
            ];
        }

        $scopeFilter = $this->logisticsCategorySql();

        $applyArchiveFilter = $this->tableExists('chantier_banamur')
            && $this->columnExists('chantier_banamur', 'archived_at');

        $archiveJoin = $applyArchiveFilter
            ? ' LEFT JOIN chantier_banamur c ON c.id = f.chantier_id'
            : '';
        $archiveWhere = $applyArchiveFilter
            ? ' AND (f.chantier_id IS NULL OR c.archived_at IS NULL)'
            : '';

        $summarySql = "SELECT
                COUNT(*) AS files_total,
                COUNT(DISTINCT f.chantier_id) AS active_sites_total,
                COALESCE(SUM(f.montant_fiche), 0) AS expense_total,
                SUM(CASE WHEN COALESCE(f.decaisse, 0) = 1 THEN 1 ELSE 0 END) AS delivered_files_total,
                SUM(CASE WHEN COALESCE(f.approuve, 0) = 0 THEN 1 ELSE 0 END) AS approval_backlog_total
            FROM fiche_decaissement f
            INNER JOIN ligne_debourse_banamur l ON l.id = f.ligne_debourse_id
            {$archiveJoin}
            WHERE {$scopeFilter}{$archiveWhere}";

        $summaryStatement = $this->pdo->query($summarySql);
        $summary = $summaryStatement ? ($summaryStatement->fetch(PDO::FETCH_ASSOC) ?: []) : [];

        $categoriesSql = "SELECT
                l.categorie,
                COUNT(*) AS files_count,
                COALESCE(SUM(f.montant_fiche), 0) AS amount_total
            FROM fiche_decaissement f
            INNER JOIN ligne_debourse_banamur l ON l.id = f.ligne_debourse_id
            {$archiveJoin}
            WHERE {$scopeFilter}{$archiveWhere}
            GROUP BY l.categorie
            ORDER BY amount_total DESC, files_count DESC";

        $categoriesStatement = $this->pdo->query($categoriesSql);
        $categories = $categoriesStatement ? ($categoriesStatement->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

        $sitesSql = "SELECT
                COALESCE(c.code, 'Sans chantier') AS chantier_code,
                COALESCE(c.titre, 'Non rattache') AS chantier_label,
                COUNT(*) AS files_count,
                COALESCE(SUM(f.montant_fiche), 0) AS amount_total,
                SUM(CASE WHEN l.categorie = 'transport' THEN 1 ELSE 0 END) AS transport_files,
                SUM(CASE WHEN l.categorie IN ('matériel', 'matériaux') THEN 1 ELSE 0 END) AS stock_files
            FROM fiche_decaissement f
            INNER JOIN ligne_debourse_banamur l ON l.id = f.ligne_debourse_id
            LEFT JOIN chantier_banamur c ON c.id = f.chantier_id
            WHERE {$scopeFilter}{$archiveWhere}
            GROUP BY c.id, c.code, c.titre
            ORDER BY amount_total DESC, files_count DESC
            LIMIT 6";

        $sitesStatement = $this->pdo->query($sitesSql);
        $topSites = $sitesStatement ? ($sitesStatement->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

        return [
            'expense_total' => (float) ($summary['expense_total'] ?? 0),
            'files_total' => (int) ($summary['files_total'] ?? 0),
            'active_sites_total' => (int) ($summary['active_sites_total'] ?? 0),
            'delivered_files_total' => (int) ($summary['delivered_files_total'] ?? 0),
            'approval_backlog_total' => (int) ($summary['approval_backlog_total'] ?? 0),
            'categories' => array_map(static function (array $row): array {
                return [
                    'label' => (string) ($row['categorie'] ?? ''),
                    'files_count' => (int) ($row['files_count'] ?? 0),
                    'amount_total' => (float) ($row['amount_total'] ?? 0),
                ];
            }, $categories),
            'top_sites' => array_map(static function (array $row): array {
                return [
                    'chantier_code' => (string) ($row['chantier_code'] ?? ''),
                    'chantier_label' => (string) ($row['chantier_label'] ?? ''),
                    'files_count' => (int) ($row['files_count'] ?? 0),
                    'amount_total' => (float) ($row['amount_total'] ?? 0),
                    'transport_files' => (int) ($row['transport_files'] ?? 0),
                    'stock_files' => (int) ($row['stock_files'] ?? 0),
                ];
            }, $topSites),
        ];
    }

    private function getFuelTimeline(): array
    {
        if (!$this->tableExists('demande_essence')) {
            return [];
        }

        $hasDisabled = $this->columnExists('demande_essence', 'desactive');

        $sql = 'SELECT
                    DATE(date_demande) AS metric_date,
                    COUNT(*) AS requests_count,
                    COALESCE(SUM(montant), 0) AS amount_total,
                    COALESCE(SUM(quantite), 0) AS quantity_total
                FROM demande_essence'
            . ($hasDisabled ? ' WHERE COALESCE(desactive, 0) = 0' : '')
            . ' GROUP BY DATE(date_demande)
                ORDER BY metric_date DESC
                LIMIT 7';

        $statement = $this->pdo->query($sql);
        $rows = $statement ? ($statement->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        $rows = array_reverse($rows);

        return array_map(static function (array $row): array {
            return [
                'date' => (string) ($row['metric_date'] ?? ''),
                'requests_count' => (int) ($row['requests_count'] ?? 0),
                'amount_total' => (float) ($row['amount_total'] ?? 0),
                'quantity_total' => (float) ($row['quantity_total'] ?? 0),
            ];
        }, $rows);
    }

    private function getRecentFuelRequests(): array
    {
        if (!$this->tableExists('demande_essence')) {
            return [];
        }

        $statusSelect = $this->columnExists('demande_essence', 'validation_status')
            ? 'COALESCE(NULLIF(validation_status, ""), "pending") AS request_status,'
            : '"pending" AS request_status,';

        $codeBonSelect = $this->columnExists('demande_essence', 'code_bon')
            ? 'COALESCE(code_bon, "") AS code_bon,'
            : '"" AS code_bon,';

        $numFicheSelect = $this->columnExists('demande_essence', 'num_fiche')
            ? 'COALESCE(num_fiche, "") AS num_fiche'
            : '"" AS num_fiche';

        $sql = "SELECT
                {$codeBonSelect}
                {$numFicheSelect},
                nom_beneficiaire,
                vehicule,
                motif,
                quantite,
                montant,
                date_demande,
                {$statusSelect}
                created_at
            FROM demande_essence
            ORDER BY COALESCE(created_at, date_demande) DESC
            LIMIT 6";

        $statement = $this->pdo->query($sql);
        return $statement ? ($statement->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    private function getRecentLogisticsFiles(): array
    {
        if (!$this->tableExists('fiche_decaissement') || !$this->tableExists('ligne_debourse_banamur')) {
            return [];
        }

        $scopeFilter = $this->logisticsCategorySql();

        $sql = "SELECT
                f.num_fiche,
                f.beficiaire_fiche,
                f.montant_fiche,
                f.date_creat_fiche,
                f.approuve,
                f.conforme,
                f.decaisse,
                l.categorie,
                l.designation,
                COALESCE(c.code, '') AS chantier_code,
                COALESCE(c.titre, 'Non rattache') AS chantier_label
            FROM fiche_decaissement f
            INNER JOIN ligne_debourse_banamur l ON l.id = f.ligne_debourse_id
            LEFT JOIN chantier_banamur c ON c.id = f.chantier_id
            WHERE {$scopeFilter}{$archiveWhere}
            ORDER BY COALESCE(f.created_at, f.date_creat_fiche) DESC
            LIMIT 8";

        $statement = $this->pdo->query($sql);
        return $statement ? ($statement->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    private function buildAlerts(array $fuelSummary, array $transportSummary, array $expenseSummary, array $recentFuelRequests): array
    {
        $alerts = [];

        $pendingFuel = (int) ($fuelSummary['pending_requests'] ?? 0);
        if ($pendingFuel > 0) {
            $alerts[] = [
                'level' => 'warning',
                'title' => 'Demandes carburant en attente',
                'message' => $pendingFuel . ' demande(s) carburant restent en attente de validation ou de traitement.',
            ];
        }

        if ((int) ($transportSummary['trips_total'] ?? 0) === 0) {
            $alerts[] = [
                'level' => 'info',
                'title' => 'Transport chantier non alimente',
                'message' => 'Aucun voyage transport n est encore remonte dans fiche_transport_spec. Le dashboard est pret a l afficher des qu un flux chantier l alimente.',
            ];
        }

        $approvalBacklog = (int) ($expenseSummary['approval_backlog_total'] ?? 0);
        if ($approvalBacklog > 0) {
            $alerts[] = [
                'level' => 'critical',
                'title' => 'Backlog de fiches logistiques',
                'message' => $approvalBacklog . ' fiche(s) logistiques ne sont pas encore approuvees.',
            ];
        }

        if (empty($alerts) && !empty($recentFuelRequests)) {
            $alerts[] = [
                'level' => 'success',
                'title' => 'Activite logistique detectee',
                'message' => 'Le module remonte deja les flux carburant et les depenses materiel/transport depuis les fiches existantes.',
            ];
        }

        return $alerts;
    }

    private function buildQuickLinks(): array
    {
        return [
            [
                'label' => 'Enregistrer stock',
                'description' => 'Créer un article, sa quantité initiale et sa localisation actuelle.',
                'href' => '../logi/enregistrer_stock.php',
                'icon' => 'fa-plus-square',
                'tone' => 'orange',
            ],
            [
                'label' => 'Inventaire matériel',
                'description' => 'Disponibilite temps reel, localisation chantier/bureau et suivi maintenance.',
                'href' => '../logi/inventaire_materiel.php',
                'icon' => 'fa-boxes-stacked',
                'tone' => 'violet',
            ],
            [
                'label' => 'Recap carburant',
                'description' => 'Suivi des bons essence, montants et exports.',
                'href' => '../decaissement/recap_carburant.php',
                'icon' => 'fa-gas-pump',
                'tone' => 'amber',
            ],
            [
                'label' => 'Transport chantier',
                'description' => 'Voyages terrain, volume transporte et detail par chantier.',
                'href' => '../decaissement/recap_chantier_transport.php',
                'icon' => 'fa-truck-ramp-box',
                'tone' => 'sky',
            ],
            [
                'label' => 'Fiches achat',
                'description' => 'Flux d achats et validations pour materiels et materiaux.',
                'href' => '../decaissement/liste_fiches_achat.php',
                'icon' => 'fa-file-invoice',
                'tone' => 'emerald',
            ],
            [
                'label' => 'Fiches decaissement',
                'description' => 'Vue globale des sorties logistiques sur chantier.',
                'href' => '../decaissement/liste_fiches_decaissement.php',
                'icon' => 'fa-clipboard-list',
                'tone' => 'slate',
            ],
        ];
    }

    private function logisticsCategorySql(): string
    {
        return "l.categorie IN ('matériel', 'matériaux', 'transport', 'EPI')";
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

    private function columnExists(string $tableName, string $columnName): bool
    {
        $cacheKey = $tableName . '.' . $columnName;
        if (array_key_exists($cacheKey, $this->columnCache)) {
            return $this->columnCache[$cacheKey];
        }

        if (!$this->tableExists($tableName)) {
            $this->columnCache[$cacheKey] = false;
            return false;
        }

        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name');
        $statement->execute([
            'table_name' => $tableName,
            'column_name' => $columnName,
        ]);

        $this->columnCache[$cacheKey] = (int) $statement->fetchColumn() > 0;
        return $this->columnCache[$cacheKey];
    }
}
