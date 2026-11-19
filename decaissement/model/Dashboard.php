<?php
require_once __DIR__ . '/FicheDecaissement.php';
require_once __DIR__ . '/../../services/ChantierPauseService.php';

class Dashboard
{
    private $pdo;
    private $hasArchiveColumns = false;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;

        // Auto-migration: colonnes d'archivage chantier (utilisées pour exclure les chantiers terminés du dashboard)
        $this->ensureArchiveColumns();
    }

    private function buildChantierFicheScopeCondition(
        string $chantierAlias = 'c',
        string $ficheAlias = 'f_scope',
        string $debourseAlias = 'db_scope'
    ): string {
        return "(
            {$ficheAlias}.chantier_id = {$chantierAlias}.id
            OR (
                ({$ficheAlias}.chantier_id IS NULL OR {$ficheAlias}.chantier_id = 0)
                AND {$chantierAlias}.devis_id IS NOT NULL
                AND {$debourseAlias}.devis_id = {$chantierAlias}.devis_id
            )
        )";
    }

    private function buildChantierDecaisseSumExpr(string $chantierAlias = 'c'): string
    {
        $scope = $this->buildChantierFicheScopeCondition($chantierAlias, 'f_scope', 'db_scope');

        return "(
            SELECT COALESCE(SUM(f_scope.montant_fiche), 0)
            FROM fiche_decaissement f_scope
            LEFT JOIN ligne_debourse_banamur ldb_scope ON ldb_scope.id = f_scope.ligne_debourse_id
            LEFT JOIN debourse_banamur db_scope ON db_scope.id = ldb_scope.debourse_id
            WHERE {$scope}
              AND f_scope.decaisse = 1
        )";
    }

    private function buildChantierFicheCountExpr(string $chantierAlias = 'c'): string
    {
        $scope = $this->buildChantierFicheScopeCondition($chantierAlias, 'f_scope', 'db_scope');

        return "(
            SELECT COUNT(DISTINCT f_scope.id_fiche)
            FROM fiche_decaissement f_scope
            LEFT JOIN ligne_debourse_banamur ldb_scope ON ldb_scope.id = f_scope.ligne_debourse_id
            LEFT JOIN debourse_banamur db_scope ON db_scope.id = ldb_scope.debourse_id
            WHERE {$scope}
        )";
    }

    private function normalizeBudgetLabel(?string $value): string
    {
        $label = trim((string)$value);
        if ($label === '') {
            return '';
        }

        $label = function_exists('mb_strtolower')
            ? mb_strtolower($label, 'UTF-8')
            : strtolower($label);

        $label = preg_replace('/\s+/u', ' ', $label) ?? $label;

        return $label;
    }

    private function findBestMatchingLigneDevisId(array $lignes, ?string $designation): ?int
    {
        $needle = $this->normalizeBudgetLabel($designation);
        if ($needle === '') {
            return null;
        }

        $bestLineId = null;
        $bestScore = -1;

        foreach ($lignes as $ligne) {
            $lineId = (int)($ligne['id'] ?? 0);
            if ($lineId <= 0) {
                continue;
            }

            $candidate = $this->normalizeBudgetLabel($ligne['designation'] ?? '');
            if ($candidate === '') {
                continue;
            }

            $score = -1;
            if ($needle === $candidate) {
                $score = 300 + strlen($candidate);
            } elseif (strpos($needle, $candidate) !== false || strpos($candidate, $needle) !== false) {
                $score = 200 + min(strlen($needle), strlen($candidate));
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestLineId = $lineId;
            }
        }

        return $bestLineId;
    }

    private function ensureArchiveColumns(): void
    {
        static $done = false;
        if ($done) {
            return;
        }

        try {
            $columns = $this->pdo->query("SHOW COLUMNS FROM chantier_banamur")
                ->fetchAll(PDO::FETCH_COLUMN, 0);

            $this->hasArchiveColumns = in_array('archived_at', $columns, true);

            $alter = [];
            if (!in_array('archived_at', $columns, true)) {
                $alter[] = "ADD COLUMN archived_at DATETIME NULL";
            }
            if (!in_array('archived_by', $columns, true)) {
                $alter[] = "ADD COLUMN archived_by INT NULL";
            }
            if (!in_array('archived_note', $columns, true)) {
                $alter[] = "ADD COLUMN archived_note TEXT NULL";
            }
            if (!in_array('closed_date', $columns, true)) {
                $alter[] = "ADD COLUMN closed_date DATE NULL";
            }

            if ($alter) {
                $this->pdo->exec('ALTER TABLE chantier_banamur ' . implode(', ', $alter));
            }

            // Index (best-effort)
            try {
                $idx = $this->pdo->query("SHOW INDEX FROM chantier_banamur WHERE Key_name = 'idx_chantier_archived_at'")
                    ->fetch(PDO::FETCH_ASSOC);
                if (!$idx) {
                    $this->pdo->exec('CREATE INDEX idx_chantier_archived_at ON chantier_banamur (archived_at)');
                }
            } catch (Throwable $e) {
                // ignore
            }

            // Re-check
            $columns2 = $this->pdo->query("SHOW COLUMNS FROM chantier_banamur")
                ->fetchAll(PDO::FETCH_COLUMN, 0);
            $this->hasArchiveColumns = in_array('archived_at', $columns2, true);
        } catch (Throwable $e) {
            // Si pas de droits ALTER, on ne filtre pas (sinon SQL casserait si colonne absente).
            $this->hasArchiveColumns = false;
        }

        $done = true;
    }

    /**
     * Données générales du dashboard selon la nouvelle logique _banamur
     */
    public function getDonneesDashboard()
    {
        return [
            'stats_fiches' => $this->getStatsFiches(),
            'stats_chantiers' => $this->getStatsChantiers(),
            'stats_devis' => $this->getStatsDevis(),
            'stats_clients' => $this->getStatsClients(),
            'evolution_montants' => $this->getEvolutionDepensesEtEntrees(),
            'evolution_chantiers' => $this->getEvolutionChantiers(),
            'repartition_chantier' => $this->getRepartitionParChantier(),
            'top_beneficiaires' => $this->getTopBeneficiaires(10),
            'chantiers_budgets' => $this->getChantiersAvecBudgets(),
            'montants_chantiers' => $this->getMontantsParChantier()
        ];
    }

    /**
     * Statistiques des fiches (nouvelle table fiche_decaissement)
     */
    private function getStatsFiches()
    {
        $sql = "
            SELECT 
                COUNT(*) as total_fiches,
                COUNT(CASE WHEN approuve = 1 THEN 1 END) as fiches_approuvees,
                COUNT(CASE WHEN decaisse = 1 THEN 1 END) as fiches_decaissees,
                COUNT(CASE WHEN approuve = 2 THEN 1 END) as fiches_refusees,
                COUNT(CASE WHEN approuve = 0 AND decaisse = 0 THEN 1 END) as fiches_en_attente,
                COUNT(CASE WHEN conforme = 1 THEN 1 END) as fiches_conformes,
                COUNT(CASE WHEN otp_valide = 1 THEN 1 END) as fiches_otp_validees,
                SUM(montant_fiche) as montant_total_fiches,
                SUM(CASE WHEN decaisse = 1 THEN montant_fiche ELSE 0 END) as montant_decaisse,
                COUNT(CASE WHEN MONTH(date_creat_fiche) = MONTH(CURDATE()) AND YEAR(date_creat_fiche) = YEAR(CURDATE()) THEN 1 END) as fiches_mois_courant,
                COUNT(CASE WHEN WEEK(date_creat_fiche) = WEEK(CURDATE()) AND YEAR(date_creat_fiche) = YEAR(CURDATE()) THEN 1 END) as fiches_semaine_courante
            FROM fiche_decaissement f
            LEFT JOIN chantier_banamur c ON c.id=f.chantier_id
            WHERE ".ChantierPauseService::activePredicate($this->pdo,'f.chantier_id')."
            " . ($this->hasArchiveColumns ? "AND (f.chantier_id IS NULL OR f.chantier_id=0 OR c.archived_at IS NULL)" : "") . "
        ";
        $stmt = $this->pdo->query($sql);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Statistiques des chantiers (nouvelle table chantier_banamur)
     */
    private function getStatsChantiers()
    {
        $whereActive = 'WHERE '.($this->hasArchiveColumns ? 'archived_at IS NULL AND ' : '').ChantierPauseService::activePredicate($this->pdo,'id');
        $sql = "
            SELECT 
                COUNT(*) as total_chantiers,
                COUNT(CASE WHEN MONTH(date_creation) = MONTH(CURDATE()) AND YEAR(date_creation) = YEAR(CURDATE()) THEN 1 END) as nouveaux_mois,
                COUNT(CASE WHEN WEEK(date_creation) = WEEK(CURDATE()) AND YEAR(date_creation) = YEAR(CURDATE()) THEN 1 END) as nouveaux_semaine,
                COUNT(CASE WHEN DATE(date_creation) = CURDATE() THEN 1 END) as nouveaux_jour
            FROM chantier_banamur
            $whereActive
        ";
        $stmt = $this->pdo->query($sql);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Statistiques des devis (nouvelle table devis_banamur)
     */
    private function getStatsDevis()
    {
        $sql = "
            SELECT 
                COUNT(*) as total_devis,
                COUNT(CASE WHEN publier_devis = 1 THEN 1 END) as devis_publies,
                COUNT(CASE WHEN validation_technique = 1 AND validation_generale = 1 
                           AND id NOT IN (SELECT devis_id FROM chantier_banamur WHERE devis_id IS NOT NULL) 
                           THEN 1 END) as devis_actifs,
                COUNT(CASE WHEN validation_technique = 1 THEN 1 END) as devis_valides_technique,
                COUNT(CASE WHEN validation_generale = 1 THEN 1 END) as devis_valides_general,
                COUNT(CASE WHEN validation_technique = 1 AND validation_generale = 1 THEN 1 END) as devis_valides_complet,
                SUM(total_ttc) as montant_total_ttc,
                SUM(total_ht) as montant_total_ht,
                COUNT(CASE WHEN MONTH(date_emission) = MONTH(CURDATE()) AND YEAR(date_emission) = YEAR(CURDATE()) THEN 1 END) as devis_mois_courant,
                AVG(total_ttc) as montant_moyen_devis
            FROM devis_banamur
        ";
        $stmt = $this->pdo->query($sql);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        // Assurer que toutes les valeurs numériques ne sont pas null
        return array_map(function ($value) {
            return $value !== null ? $value : 0;
        }, $result);
    }

    /**
     * Statistiques des clients (nouvelle table client_banamur)
     */
    private function getStatsClients()
    {
        $sql = "
            SELECT 
                COUNT(*) as total_clients,
                COUNT(CASE WHEN date_creat_client = YEAR(CURDATE()) THEN 1 END) as nouveaux_clients_annee,
                COUNT(CASE WHEN telephone_client IS NOT NULL AND telephone_client != '' THEN 1 END) as clients_avec_telephone,
                COUNT(CASE WHEN logo_client IS NOT NULL AND logo_client != '' THEN 1 END) as clients_avec_logo
            FROM client_banamur
        ";
        $stmt = $this->pdo->query($sql);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Évolution des chantiers sur 12 mois
     */
    private function getEvolutionChantiers()
    {
        $activeClause = ($this->hasArchiveColumns ? "AND archived_at IS NULL " : "") . 'AND '.ChantierPauseService::activePredicate($this->pdo,'id');
        $sql = "
            SELECT 
                DATE_FORMAT(date_creation, '%Y-%m') as mois,
                COUNT(*) as nombre_chantiers
            FROM chantier_banamur
            WHERE date_creation >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
            $activeClause
            GROUP BY DATE_FORMAT(date_creation, '%Y-%m')
            ORDER BY mois
        ";
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Évolution des dépenses et entrées sur 12 mois
     */
    private function getEvolutionDepensesEtEntrees()
    {
        // Dépenses (fiches décaissées de la nouvelle table)
        $depensesSql = "
            SELECT 
                DATE_FORMAT(date_creat_fiche, '%Y-%m') as mois,
                SUM(montant_fiche) as montant_depenses
            FROM fiche_decaissement f
            LEFT JOIN chantier_banamur c ON c.id=f.chantier_id
            WHERE f.decaisse = 1
            AND f.date_creat_fiche >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
            AND ".ChantierPauseService::activePredicate($this->pdo,'f.chantier_id')."
            " . ($this->hasArchiveColumns ? "AND (f.chantier_id IS NULL OR f.chantier_id=0 OR c.archived_at IS NULL)" : "") . "
            GROUP BY DATE_FORMAT(date_creat_fiche, '%Y-%m')
            ORDER BY mois
        ";
        $depenses = $this->pdo->query($depensesSql)->fetchAll(PDO::FETCH_ASSOC);

        // Entrées (devis validés/publiés)
        $entreesSql = "
            SELECT 
                DATE_FORMAT(date_emission, '%Y-%m') as mois,
                SUM(total_ttc) as montant_entrees
            FROM devis_banamur
            WHERE publier_devis = 1 
            AND date_emission >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
            GROUP BY DATE_FORMAT(date_emission, '%Y-%m')
            ORDER BY mois
        ";
        $entrees = $this->pdo->query($entreesSql)->fetchAll(PDO::FETCH_ASSOC);

        // Créer une liste complète des 12 derniers mois
        $moisComplets = array();
        for ($i = 11; $i >= 0; $i--) {
            $moisComplets[] = date('Y-m', strtotime("-$i months"));
        }

        // Mapper les données pour avoir des valeurs pour chaque mois
        $depensesParMois = array();
        $entreesParMois = array();

        foreach ($moisComplets as $mois) {
            // Chercher les dépenses pour ce mois
            $depenseMois = 0;
            foreach ($depenses as $depense) {
                if ($depense['mois'] === $mois) {
                    $depenseMois = floatval($depense['montant_depenses']);
                    break;
                }
            }
            $depensesParMois[] = $depenseMois;

            // Chercher les entrées pour ce mois
            $entreeMois = 0;
            foreach ($entrees as $entree) {
                if ($entree['mois'] === $mois) {
                    $entreeMois = floatval($entree['montant_entrees']);
                    break;
                }
            }
            $entreesParMois[] = $entreeMois;
        }

        // Formater les labels (noms des mois)
        $labels = array_map(function ($mois) {
            return date('M Y', strtotime($mois . '-01'));
        }, $moisComplets);

        return array(
            'labels' => $labels,
            'depenses' => $depensesParMois,
            'entrees' => $entreesParMois
        );
    }

    /**
     * Répartition des dépenses par chantier
     */
    private function getRepartitionParChantier()
    {
        $activeClause = ($this->hasArchiveColumns ? "AND c.archived_at IS NULL " : "") . 'AND '.ChantierPauseService::activePredicate($this->pdo,'c.id');
        $totalDepensesExpr = $this->buildChantierDecaisseSumExpr('c');
        $nombreFichesExpr = "(
            SELECT COUNT(DISTINCT f_scope.id_fiche)
            FROM fiche_decaissement f_scope
            LEFT JOIN ligne_debourse_banamur ldb_scope ON ldb_scope.id = f_scope.ligne_debourse_id
            LEFT JOIN debourse_banamur db_scope ON db_scope.id = ldb_scope.debourse_id
            WHERE " . $this->buildChantierFicheScopeCondition('c', 'f_scope', 'db_scope') . "
              AND f_scope.decaisse = 1
        )";
        $sql = "
            SELECT 
                c.titre as chantier,
                c.code as code_chantier,
                $totalDepensesExpr as montant_total,
                $nombreFichesExpr as nombre_fiches
            FROM chantier_banamur c
            WHERE 1=1
            $activeClause
            HAVING montant_total > 0
            ORDER BY montant_total DESC
        ";
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Top bénéficiaires des fiches
     */
    private function getTopBeneficiaires($limit = 10)
    {
        $sql = "
            SELECT 
                beficiaire_fiche,
                SUM(montant_fiche) as montant_total,
                COUNT(*) as nombre_fiches,
                AVG(montant_fiche) as montant_moyen
            FROM fiche_decaissement f
            LEFT JOIN chantier_banamur c ON c.id = f.chantier_id
            WHERE f.decaisse = 1
              AND (f.chantier_id IS NULL OR f.chantier_id = 0 OR c.archived_at IS NULL)
              AND ".ChantierPauseService::activePredicate($this->pdo,'f.chantier_id')."
            GROUP BY beficiaire_fiche
            ORDER BY montant_total DESC
            LIMIT :limit
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Chantiers avec leurs budgets (selon nouvelle logique)
     */
    public function getChantiersAvecBudgets(bool $includePaused = false)
    {
        $conditions = $this->hasArchiveColumns ? ['c.archived_at IS NULL'] : [];
        if (!$includePaused) $conditions[] = ChantierPauseService::activePredicate($this->pdo, 'c.id');
        $whereActive = $conditions ? 'WHERE '.implode(' AND ', $conditions) : '';
        $totalDepensesExpr = $this->buildChantierDecaisseSumExpr('c');
        $nombreFichesExpr = $this->buildChantierFicheCountExpr('c');
        $sql = "
            SELECT 
                c.id as id_chantier,
                c.titre as lib_chantier,
                c.code as num_chantier,
                c.date_creation,
                c.montant_devis as budget_total,
                c.cout_total_chantier,
                c.entreprise,
                cl.nom_client,
                d.numero_devis,
                d.total_ttc as budget_devis_total,
                
                -- Calcul des dépenses réelles via les fiches
                $totalDepensesExpr as total_depenses,
                $nombreFichesExpr as nombre_fiches,
                
                -- Calcul du pourcentage consommé (basé sur montant_devis du chantier si disponible, sinon devis total)
                CASE 
                    WHEN COALESCE(c.montant_devis, d.total_ttc) > 0 THEN 
                        ($totalDepensesExpr / COALESCE(c.montant_devis, d.total_ttc)) * 100
                    ELSE 0
                END as pourcentage_consomme
                
            FROM chantier_banamur c
            LEFT JOIN devis_banamur d ON d.id = c.devis_id
            LEFT JOIN client_banamur cl ON cl.id_client = c.client_id
            $whereActive
            ORDER BY c.date_creation DESC
        ";
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Montants par chantier pour graphiques
     */
    private function getMontantsParChantier()
    {
        $whereActive = 'WHERE '.($this->hasArchiveColumns ? 'c.archived_at IS NULL AND ' : '').ChantierPauseService::activePredicate($this->pdo, 'c.id');
        $totalDepensesExpr = $this->buildChantierDecaisseSumExpr('c');
        $sql = "
            SELECT 
                c.titre as chantier,
                COALESCE(c.montant_devis, d.total_ttc, 0) as budget,
                $totalDepensesExpr as depense
            FROM chantier_banamur c
            LEFT JOIN devis_banamur d ON d.id = c.devis_id
            $whereActive
            ORDER BY COALESCE(c.montant_devis, d.total_ttc, 0) DESC
            LIMIT 10
        ";
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Détails budgétaires d'un chantier spécifique (nouvelle logique complète)
     */
    public function getDetailsBudgetaireChantier($chantierId)
    {
        // 1. Informations générales du chantier
        $chantierSql = "
            SELECT 
                c.*,
                cl.nom_client,
                cl.telephone_client,
                cl.localisation_client,
                cl.commune_client,
                d.numero_devis,
                d.total_ttc as budget_devis,
                d.total_ht,
                d.date_emission,
                d.date_expiration,
                o.reference_offre as devis_libelle
            FROM chantier_banamur c
            LEFT JOIN client_banamur cl ON cl.id_client = c.client_id
            LEFT JOIN devis_banamur d ON d.id = c.devis_id
            LEFT JOIN offre_banamur o ON o.id_offre = d.offre_id
            WHERE c.id = ? AND c.archived_at IS NULL
        ";
        $chantier = $this->pdo->prepare($chantierSql);
        $chantier->execute(array($chantierId));
        $infoChantier = $chantier->fetch(PDO::FETCH_ASSOC);

        if (!$infoChantier) {
            return null;
        }

        try {
            $ficheModel = new FicheDecaissement($this->pdo);
            $ficheModel->repairMissingDebourseLinksForChantier((int)$chantierId);
        } catch (Throwable $e) {
            // La page doit rester consultable même si la réparation échoue.
        }

        // 2. Lignes de devis avec leurs montants payés et débourés
        $lignesDevisSql = "
            SELECT 
                ld.*,
                u.libelle as unite_libelle,
                COALESCE(ld.zone, CONCAT('Zone ', ROW_NUMBER() OVER (ORDER BY ld.id))) as zone,
                
                -- Calcul des montants débourés pour chaque ligne
                COALESCE(
                    (
                        SELECT SUM(ldb.montant)
                        FROM ligne_debourse_banamur ldb
                        INNER JOIN debourse_banamur db ON db.id = ldb.debourse_id
                        WHERE db.devis_id = ld.devis_id
                          AND db.ligne_devis_id = ld.id
                    ), 0
                ) as montant_debourse_reel
                
            FROM ligne_devis_banamur ld
            LEFT JOIN unites_mesure u ON u.id = ld.unite_id
            WHERE ld.devis_id = ?
            ORDER BY ld.zone, ld.groupe, ld.designation
        ";
        $lignesDevis = $this->pdo->prepare($lignesDevisSql);
        $lignesDevis->execute(array($infoChantier['devis_id']));
        $lignes = $lignesDevis->fetchAll(PDO::FETCH_ASSOC);

        // 3. Fiches associées au chantier
        $ficheScope = ['f.chantier_id = :chantier_id'];
        $ficheParams = ['chantier_id' => (int)$chantierId];
        if (!empty($infoChantier['devis_id'])) {
            $ficheScope[] = '((f.chantier_id IS NULL OR f.chantier_id = 0) AND db.devis_id = :devis_id)';
            $ficheParams['devis_id'] = (int)$infoChantier['devis_id'];
        }

        $fichesSql = "
            SELECT 
                f.*,
                f.designation_fiche as designation_libelle,
                f.precision_fiche as precision_libelle,
                ldb.designation as ligne_debourse_designation,
                ldb.categorie as ligne_debourse_categorie,
                ldb.montant as ligne_debourse_montant,
                db.ligne_devis_id as ligne_devis_source_id,
                ld.designation as ligne_devis_designation
            FROM fiche_decaissement f
            LEFT JOIN ligne_debourse_banamur ldb ON ldb.id = f.ligne_debourse_id
            LEFT JOIN debourse_banamur db ON db.id = ldb.debourse_id
            LEFT JOIN ligne_devis_banamur ld ON ld.id = db.ligne_devis_id
            WHERE (" . implode(' OR ', $ficheScope) . ")
            ORDER BY f.date_creat_fiche DESC
        ";
        $fiches = $this->pdo->prepare($fichesSql);
        $fiches->execute($ficheParams);
        $fichesList = $fiches->fetchAll(PDO::FETCH_ASSOC);

        // 3.5. Lignes de déboursé associées au chantier
        $lignesDebourseSql = "
            SELECT 
                ldb.*,
                db.montant_debourse as montant_debourse_parent,
                db.date_debut as date_debut_debourse,
                db.date_fin as date_fin_debourse,
                db.ligne_devis_id as ligne_devis_source_id,
                ld.designation as designation_ligne_devis,
                ld.zone as zone_ligne_devis
            FROM ligne_debourse_banamur ldb
            INNER JOIN debourse_banamur db ON db.id = ldb.debourse_id
            LEFT JOIN ligne_devis_banamur ld ON ld.id = db.ligne_devis_id
            WHERE db.devis_id = ?
            ORDER BY ldb.categorie, ldb.designation
        ";
        $lignesDebourse = $this->pdo->prepare($lignesDebourseSql);
        $lignesDebourse->execute(array($infoChantier['devis_id']));
        $lignesDebourseList = $lignesDebourse->fetchAll(PDO::FETCH_ASSOC);

        $ligneIndexById = [];
        foreach ($lignes as $index => &$ligne) {
            $ligne['montant_paye'] = 0.0;
            $ligneId = (int)($ligne['id'] ?? 0);
            if ($ligneId > 0) {
                $ligneIndexById[$ligneId] = $index;
            }
        }
        unset($ligne);

        $ligneDevisIdByLigneDebourseId = [];
        foreach ($lignesDebourseList as $ligneDebourse) {
            $ligneDebourseId = (int)($ligneDebourse['id'] ?? 0);
            $ligneDevisId = (int)($ligneDebourse['ligne_devis_source_id'] ?? 0);
            if ($ligneDebourseId > 0 && $ligneDevisId > 0) {
                $ligneDevisIdByLigneDebourseId[$ligneDebourseId] = $ligneDevisId;
            }
        }

        $montantNonRattache = 0.0;
        $nbFichesNonRattachees = 0;
        foreach ($fichesList as $fiche) {
            if ((int)($fiche['decaisse'] ?? 0) !== 1) {
                continue;
            }

            $montantFiche = floatval($fiche['montant_fiche'] ?? 0);
            if ($montantFiche <= 0) {
                continue;
            }

            $ligneDevisId = (int)($fiche['ligne_devis_source_id'] ?? 0);
            if ($ligneDevisId <= 0) {
                $ligneDebourseId = (int)($fiche['ligne_debourse_id'] ?? 0);
                if ($ligneDebourseId > 0 && isset($ligneDevisIdByLigneDebourseId[$ligneDebourseId])) {
                    $ligneDevisId = $ligneDevisIdByLigneDebourseId[$ligneDebourseId];
                }
            }

            if ($ligneDevisId <= 0) {
                $ligneDevisId = (int)($this->findBestMatchingLigneDevisId($lignes, $fiche['designation_fiche'] ?? null) ?? 0);
            }

            if ($ligneDevisId > 0 && isset($ligneIndexById[$ligneDevisId])) {
                $lignes[$ligneIndexById[$ligneDevisId]]['montant_paye'] += $montantFiche;
                continue;
            }

            $montantNonRattache += $montantFiche;
            $nbFichesNonRattachees++;
        }

        if ($montantNonRattache > 0) {
            $lignes[] = [
                'id' => 0,
                'devis_id' => $infoChantier['devis_id'],
                'designation' => 'Décaissements non rattachés',
                'groupe' => 'Ajustement',
                'zone' => 'Hors correspondance',
                'prix' => 0,
                'quantite' => 0,
                'total' => 0,
                'unite_libelle' => '',
                'montant_debourse_reel' => 0,
                'montant_paye' => $montantNonRattache,
                'fiches_non_rattachees' => $nbFichesNonRattachees,
                '__synthetic_unmatched' => true,
            ];
        }

        // Grouper les lignes par zone
        $zones = [];
        foreach ($lignes as $ligne) {
            $zone = $ligne['zone'] ?? 'Sans zone';
            if (!isset($zones[$zone])) {
                $zones[$zone] = [];
            }
            $zones[$zone][] = $ligne;
        }

        // 4. Calculs des totaux
        // Prioriser le total des déboursés si présent, sinon utiliser le devis
        $budgetFromDevis = floatval($infoChantier['budget_devis']);
        $totalDebourse = 0;
        // Essayer d'abord le total à partir des sous-lignes (source de vérité de gerer_debourse)
        if (!empty($infoChantier['devis_id'])) {
            $debStmt = $this->pdo->prepare(
                "SELECT COALESCE(SUM(ldb.montant),0) as total
                 FROM ligne_debourse_banamur ldb
                 INNER JOIN debourse_banamur db ON db.id = ldb.debourse_id
                 WHERE db.devis_id = ?"
            );
            $debStmt->execute([$infoChantier['devis_id']]);
            $totalDebourse = floatval($debStmt->fetchColumn());

            // Fallback : si aucune sous-ligne, prendre 1 enregistrement par ligne (le plus récent) pour éviter les doublons.
            if ($totalDebourse <= 0) {
                $debStmt2 = $this->pdo->prepare(
                    "SELECT COALESCE(SUM(db.montant_debourse),0) as total
                     FROM debourse_banamur db
                     INNER JOIN (
                        SELECT MAX(id) AS id
                        FROM debourse_banamur
                        WHERE devis_id = ?
                        GROUP BY ligne_devis_id
                     ) latest ON latest.id = db.id
                     WHERE db.devis_id = ?"
                );
                $debStmt2->execute([$infoChantier['devis_id'], $infoChantier['devis_id']]);
                $totalDebourse = floatval($debStmt2->fetchColumn());
            }
        }

        // Si toujours rien, utiliser la même logique que dans selection_budgets_chantiers
        // (somme des montants de debourse via un sous-select sur numero_devis) pour être cohérent
        if ($totalDebourse <= 0 && !empty($infoChantier['numero_devis'])) {
            $debByNum = $this->pdo->prepare(
                "SELECT COALESCE(SUM(ldb.montant),0) as total
                 FROM ligne_debourse_banamur ldb
                 INNER JOIN debourse_banamur db ON db.id = ldb.debourse_id
                 WHERE db.devis_id = (SELECT id FROM devis_banamur WHERE TRIM(numero_devis) = TRIM(:num) LIMIT 1)"
            );
            $debByNum->execute(['num' => $infoChantier['numero_devis']]);
            $totalDebourse = floatval($debByNum->fetchColumn());
        }

        // Budget total affiché : priorité au déboursé saisi, sinon au devis, sinon à la somme des lignes de devis.
        $budgetFromLignes = 0.0;
        foreach ($lignes as $ligneBudget) {
            $budgetFromLignes += floatval($ligneBudget['total'] ?? 0);
        }

        $budgetTotal = 0.0;
        if ($totalDebourse > 0) {
            $budgetTotal = $totalDebourse;
        } elseif ($budgetFromDevis > 0) {
            $budgetTotal = $budgetFromDevis;
        } elseif ($budgetFromLignes > 0) {
            $budgetTotal = $budgetFromLignes;
        }
        $montantPaye = 0;
        foreach ($fichesList as $f) {
            if ($f['decaisse'] == 1) {
                $montantPaye += floatval($f['montant_fiche']);
            }
        }
        $montantRestant = max($budgetTotal - $montantPaye, 0);
        $pourcentageRealise = $budgetTotal > 0 ? ($montantPaye / $budgetTotal) * 100 : 0;

        // 5. Statistiques des fiches
        $ficheStats = array(
            'total_fiches' => count($fichesList),
            'fiches_approuvees' => 0,
            'fiches_decaissees' => 0,
            'fiches_refusees' => 0,
            'fiches_en_attente' => 0,
            'fiches_conformes' => 0,
            'fiches_otp_validees' => 0
        );

        foreach ($fichesList as $f) {
            if ($f['approuve'] == 1) $ficheStats['fiches_approuvees']++;
            if ($f['decaisse'] == 1) $ficheStats['fiches_decaissees']++;
            if ($f['approuve'] == 2) $ficheStats['fiches_refusees']++;
            if ($f['approuve'] == 0) $ficheStats['fiches_en_attente']++;
            if ($f['conforme'] == 1) $ficheStats['fiches_conformes']++;
            if ($f['otp_valide'] == 1) $ficheStats['fiches_otp_validees']++;
        }
        return array(
            'chantier' => $infoChantier,
            'devis' => array(
                'numero_devis' => $infoChantier['numero_devis'],
                'libelle_devis' => $infoChantier['devis_libelle'] ?? null,
                'budget_devis' => $budgetFromDevis,
                'date_emission' => $infoChantier['date_emission']
            ),
            'zones' => $zones,
            'lignes_devis' => $lignes, // Garder pour compatibilité
            'lignes_debourse' => $lignesDebourseList,
            'fiches' => $fichesList,
            'totaux' => array(
                'budget_total' => $budgetTotal,
                'total_debourse' => $totalDebourse,
                'montant_paye' => $montantPaye,
                'montant_restant' => $montantRestant,
                'pourcentage_realise' => $pourcentageRealise
            ),
            'stats_fiches' => $ficheStats
        );
    }

    /**
     * Tous les chantiers avec budgets simplifiés
     */
    public function getTousLesChantiersBudgets(array $filters = [])
    {
        return $this->getChantiersAvecBudgetsFiltres($filters);
    }

    /**
     * Chantiers avec budgets + filtres (utilisé par la recherche + exports)
     *
     * Filtres supportés:
     * - chantier_id (int)
     * - chantier (string) -> recherche titre / code
     * - client (string)
     * - entreprise (string)
     * - devis (string)
     * - statut (normal|attention|critique)
     * - archives_scope (active|archive|all)
     */
    public function getChantiersAvecBudgetsFiltres(array $filters = []): array
    {
        $where = [];
        $params = [];

        if ($this->hasArchiveColumns) {
            $archivesScope = trim((string)($filters['archives_scope'] ?? 'active'));
            if ($archivesScope === 'archive') {
                $where[] = 'c.archived_at IS NOT NULL';
            } elseif ($archivesScope !== 'all') {
                $where[] = 'c.archived_at IS NULL';
                $where[] = ChantierPauseService::activePredicate($this->pdo,'c.id');
            }
        }
        if (($archivesScope ?? 'active') === 'all') $where[] = ChantierPauseService::activePredicate($this->pdo, 'c.id');

        $chantierId = $filters['chantier_id'] ?? null;
        if ($chantierId !== null && trim((string)$chantierId) !== '') {
            $where[] = 'c.id = :chantier_id';
            $params[':chantier_id'] = (int)$chantierId;
        }

        $chantierQ = trim((string)($filters['chantier'] ?? ''));
        if (($chantierId === null || trim((string)$chantierId) === '') && $chantierQ !== '') {
            $where[] = '(c.titre LIKE :chantier_q OR c.code LIKE :chantier_q)';
            $params[':chantier_q'] = '%' . $chantierQ . '%';
        }

        $clientQ = trim((string)($filters['client'] ?? ''));
        if ($clientQ !== '') {
            $where[] = 'cl.nom_client LIKE :client_q';
            $params[':client_q'] = '%' . $clientQ . '%';
        }

        $entrepriseQ = trim((string)($filters['entreprise'] ?? ''));
        if ($entrepriseQ !== '') {
            $where[] = 'c.entreprise LIKE :entreprise_q';
            $params[':entreprise_q'] = '%' . $entrepriseQ . '%';
        }

        $devisQ = trim((string)($filters['devis'] ?? ''));
        if ($devisQ !== '') {
            $where[] = 'd.numero_devis LIKE :devis_q';
            $params[':devis_q'] = '%' . $devisQ . '%';
        }

        $budgetExpr = "CASE
                    WHEN COALESCE(dbs.total_sous_lignes, 0) > 0 THEN dbs.total_sous_lignes
                    WHEN COALESCE(dbs.total_latest_debourse, 0) > 0 THEN dbs.total_latest_debourse
                    ELSE COALESCE(c.montant_devis, d.total_ttc)
                END";

        $totalDepensesExpr = $this->buildChantierDecaisseSumExpr('c');
        $nombreFichesExpr = $this->buildChantierFicheCountExpr('c');

        $pctExpr = "CASE
                    WHEN ($budgetExpr) > 0 THEN
                        (($totalDepensesExpr) / ($budgetExpr)) * 100
                    ELSE 0
                END";

        $statut = trim((string)($filters['statut'] ?? ''));
        if ($statut === 'normal') {
            $where[] = "($pctExpr) <= 70";
        } elseif ($statut === 'attention') {
            $where[] = "($pctExpr) > 70 AND ($pctExpr) <= 90";
        } elseif ($statut === 'critique') {
            $where[] = "($pctExpr) > 90";
        }

        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $sql = "
            SELECT 
                c.id as id_chantier,
                c.titre as lib_chantier,
                c.code as num_chantier,
                c.date_creation,
                c.archived_at,
                c.closed_date,
                c.archived_note,
                c.montant_devis as budget_total,
                c.cout_total_chantier,
                c.entreprise,
                cl.nom_client,
                d.numero_devis,
                d.total_ttc as budget_devis_total,
                ($budgetExpr) as budget_interne_calc,
                ($totalDepensesExpr) as total_depenses,
                ($nombreFichesExpr) as nombre_fiches,
                $pctExpr as pourcentage_consomme
            FROM chantier_banamur c
            LEFT JOIN devis_banamur d ON d.id = c.devis_id
            LEFT JOIN (
                SELECT 
                    base.devis_id,
                    COALESCE(ldb_tot.total_sous_lignes, 0) AS total_sous_lignes,
                    COALESCE(latest_tot.total_latest_debourse, 0) AS total_latest_debourse
                FROM (
                    SELECT DISTINCT devis_id
                    FROM debourse_banamur
                ) base
                LEFT JOIN (
                    SELECT db.devis_id, SUM(ldb.montant) AS total_sous_lignes
                    FROM ligne_debourse_banamur ldb
                    INNER JOIN debourse_banamur db ON db.id = ldb.debourse_id
                    GROUP BY db.devis_id
                ) ldb_tot ON ldb_tot.devis_id = base.devis_id
                LEFT JOIN (
                    SELECT latest.devis_id, SUM(latest.montant_debourse) AS total_latest_debourse
                    FROM (
                        SELECT db.*
                        FROM debourse_banamur db
                        INNER JOIN (
                            SELECT MAX(id) AS id
                            FROM debourse_banamur
                            GROUP BY devis_id, ligne_devis_id
                        ) latest_ids ON latest_ids.id = db.id
                    ) latest
                    GROUP BY latest.devis_id
                ) latest_tot ON latest_tot.devis_id = base.devis_id
            ) dbs ON dbs.devis_id = d.id
            LEFT JOIN client_banamur cl ON cl.id_client = c.client_id
            $whereSql
            ORDER BY COALESCE(c.archived_at, c.date_creation) DESC, c.id DESC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Données pour graphiques Chart.js
     */
    public function getChartsData()
    {
        return array(
            'evolution_montants' => $this->getEvolutionDepensesEtEntrees(),
            'evolution_chantiers' => $this->formatChartData($this->getEvolutionChantiers(), 'mois', 'nombre_chantiers'),
            'repartition_chantiers' => $this->formatPieData($this->getRepartitionParChantier(), 'chantier', 'montant_total'),
            'top_beneficiaires' => $this->formatBarData($this->getTopBeneficiaires(10), 'beficiaire_fiche', 'montant_total')
        );
    }

    /**
     * Formatage des données pour Chart.js
     */
    private function formatChartData($data, $labelKey, $valueKey)
    {
        $labels = array();
        $values = array();
        foreach ($data as $item) {
            $labels[] = $item[$labelKey];
            $values[] = $item[$valueKey];
        }

        return array(
            'labels' => $labels,
            'datasets' => array(
                array(
                    'data' => $values,
                    'backgroundColor' => 'rgba(99, 102, 241, 0.6)',
                    'borderColor' => 'rgba(99, 102, 241, 1)',
                    'borderWidth' => 2
                )
            )
        );
    }

    /**
     * Formatage pour graphiques en secteurs
     */
    private function formatPieData($data, $labelKey, $valueKey)
    {
        $colors = array(
            'rgba(255, 99, 132, 0.6)',
            'rgba(54, 162, 235, 0.6)',
            'rgba(255, 205, 86, 0.6)',
            'rgba(75, 192, 192, 0.6)',
            'rgba(153, 102, 255, 0.6)',
            'rgba(255, 159, 64, 0.6)'
        );

        $labels = array();
        $values = array();
        foreach ($data as $item) {
            $labels[] = $item[$labelKey];
            $values[] = $item[$valueKey];
        }

        return array(
            'labels' => $labels,
            'datasets' => array(
                array(
                    'data' => $values,
                    'backgroundColor' => array_slice($colors, 0, count($data))
                )
            )
        );
    }

    /**
     * Formatage pour graphiques en barres
     */
    private function formatBarData($data, $labelKey, $valueKey)
    {
        $labels = array();
        $values = array();
        foreach ($data as $item) {
            $labels[] = $item[$labelKey];
            $values[] = $item[$valueKey];
        }

        return array(
            'labels' => $labels,
            'datasets' => array(
                array(
                    'data' => $values,
                    'backgroundColor' => 'rgba(34, 197, 94, 0.6)',
                    'borderColor' => 'rgba(34, 197, 94, 1)',
                    'borderWidth' => 2
                )
            )
        );
    }

    /**
     * Alertes du système
     */
    public function getAlertes()
    {
        $alertes = array();

        // Chantiers qui dépassent 90% du budget
        $chantiersDepasses = array();
        $chantiers = $this->getChantiersAvecBudgets();
        foreach ($chantiers as $chantier) {
            if ($chantier['pourcentage_consomme'] > 90) {
                $chantiersDepasses[] = $chantier;
            }
        }

        if (!empty($chantiersDepasses)) {
            $alertes[] = array(
                'type' => 'danger',
                'titre' => 'Budgets dépassés',
                'message' => count($chantiersDepasses) . ' chantier(s) ont dépassé 90% de leur budget',
                'data' => $chantiersDepasses
            );
        }

        // Fiches en attente d'approbation
        $fichesSql = "SELECT COUNT(*) as count FROM fiche_decaissement f LEFT JOIN chantier_banamur c ON c.id=f.chantier_id WHERE f.approuve = 0 AND (f.chantier_id IS NULL OR f.chantier_id=0 OR c.archived_at IS NULL)";
        $ficheCount = $this->pdo->query($fichesSql)->fetch()['count'];

        if ($ficheCount > 0) {
            $alertes[] = array(
                'type' => 'warning',
                'titre' => 'Fiches en attente',
                'message' => $ficheCount . ' fiche(s) en attente d\'approbation',
                'data' => $ficheCount
            );
        }

        // Fiches non conformes
        $fichesNonConformesSql = "SELECT COUNT(*) as count FROM fiche_decaissement f LEFT JOIN chantier_banamur c ON c.id=f.chantier_id WHERE f.conforme = 0 AND f.approuve = 1 AND (f.chantier_id IS NULL OR f.chantier_id=0 OR c.archived_at IS NULL)";
        $fichesNonConformesCount = $this->pdo->query($fichesNonConformesSql)->fetch()['count'];

        if ($fichesNonConformesCount > 0) {
            $alertes[] = array(
                'type' => 'info',
                'titre' => 'Fiches non conformes',
                'message' => $fichesNonConformesCount . ' fiche(s) approuvée(s) mais non conforme(s)',
                'data' => $fichesNonConformesCount
            );
        }
        return $alertes;
    }
}
