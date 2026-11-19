<?php
class Dashboard
{
    private $pdo;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
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
     * Statistiques des fiches (reste inchangé car table fiche reste la même)
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
                SUM(montant_fiche) as montant_total_fiches,
                SUM(CASE WHEN decaisse = 1 THEN montant_fiche ELSE 0 END) as montant_decaisse,
                COUNT(CASE WHEN MONTH(date_creat_fiche) = MONTH(CURDATE()) AND YEAR(date_creat_fiche) = YEAR(CURDATE()) THEN 1 END) as fiches_mois_courant,
                COUNT(CASE WHEN WEEK(date_creat_fiche) = WEEK(CURDATE()) AND YEAR(date_creat_fiche) = YEAR(CURDATE()) THEN 1 END) as fiches_semaine_courante
            FROM fiche
        ";
        $stmt = $this->pdo->query($sql);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Statistiques des chantiers (nouvelle table chantier_banamur)
     */
    private function getStatsChantiers()
    {
        $sql = "
            SELECT 
                COUNT(*) as total_chantiers,
                COUNT(CASE WHEN MONTH(date_creation) = MONTH(CURDATE()) AND YEAR(date_creation) = YEAR(CURDATE()) THEN 1 END) as nouveaux_mois,
                COUNT(CASE WHEN WEEK(date_creation) = WEEK(CURDATE()) AND YEAR(date_creation) = YEAR(CURDATE()) THEN 1 END) as nouveaux_semaine,
                COUNT(CASE WHEN DATE(date_creation) = CURDATE() THEN 1 END) as nouveaux_jour
            FROM chantier_banamur
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
                SUM(total_ttc) as montant_total_ttc,
                SUM(total_ht) as montant_total_ht,
                COUNT(CASE WHEN MONTH(date_emission) = MONTH(CURDATE()) AND YEAR(date_emission) = YEAR(CURDATE()) THEN 1 END) as devis_mois_courant,
                AVG(total_ttc) as montant_moyen_devis
            FROM devis_banamur
        ";
        $stmt = $this->pdo->query($sql);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Statistiques des clients (nouvelle table client_banamur)
     */
    private function getStatsClients()
    {
        $sql = "
            SELECT 
                COUNT(*) as total_clients,
                COUNT(CASE WHEN MONTH(date_creat_client) = MONTH(CURDATE()) AND YEAR(date_creat_client) = YEAR(CURDATE()) THEN 1 END) as nouveaux_clients_mois
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
        $sql = "
            SELECT 
                DATE_FORMAT(date_creation, '%Y-%m') as mois,
                COUNT(*) as nombre_chantiers
            FROM chantier_banamur
            WHERE date_creation >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
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
        // Dépenses (fiches décaissées)
        $depensesSql = "
            SELECT 
                DATE_FORMAT(date_creat_fiche, '%Y-%m') as mois,
                SUM(montant_fiche) as montant_depenses
            FROM fiche
            WHERE decaisse = 1 
            AND date_creat_fiche >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
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

        return [
            'depenses' => $depenses,
            'entrees' => $entrees
        ];
    }

    /**
     * Répartition des dépenses par chantier
     */
    private function getRepartitionParChantier()
    {
        $sql = "
            SELECT 
                c.titre as chantier,
                c.code as code_chantier,
                SUM(f.montant_fiche) as montant_total,
                COUNT(f.id) as nombre_fiches
            FROM chantier_banamur c
            LEFT JOIN fiche f ON f.chantier_id = c.id
            WHERE f.decaisse = 1
            GROUP BY c.id, c.titre, c.code
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
            FROM fiche
            WHERE decaisse = 1
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
    public function getChantiersAvecBudgets()
    {
        $sql = "
            SELECT 
                c.id as id_chantier,
                c.titre as lib_chantier,
                c.code as num_chantier,
                c.date_creation,
                cl.nom_client,
                cl.prenom_client,
                d.numero_devis,
                d.total_ttc as budget_total,
                
                -- Calcul des dépenses réelles via les fiches
                COALESCE(SUM(f.montant_fiche), 0) as total_depenses,
                COUNT(f.id) as nombre_fiches,
                
                -- Calcul du pourcentage consommé
                CASE 
                    WHEN d.total_ttc > 0 THEN (COALESCE(SUM(f.montant_fiche), 0) / d.total_ttc) * 100
                    ELSE 0
                END as pourcentage_consomme
                
            FROM chantier_banamur c
            LEFT JOIN devis_banamur d ON d.id = c.devis_id
            LEFT JOIN client_banamur cl ON cl.id = c.client_id
            LEFT JOIN fiche f ON f.chantier_id = c.id AND f.decaisse = 1
            GROUP BY c.id, c.titre, c.code, c.date_creation, cl.nom_client, cl.prenom_client, d.numero_devis, d.total_ttc
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
        $sql = "
            SELECT 
                c.titre as chantier,
                d.total_ttc as budget,
                COALESCE(SUM(f.montant_fiche), 0) as depense
            FROM chantier_banamur c
            LEFT JOIN devis_banamur d ON d.id = c.devis_id
            LEFT JOIN fiche f ON f.chantier_id = c.id AND f.decaisse = 1
            GROUP BY c.id, c.titre, d.total_ttc
            ORDER BY d.total_ttc DESC
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
            cl.prenom_client,
            cl.email_client,
            cl.tel_client,
            d.numero_devis,
            d.total_ttc as budget_devis,
            d.total_ht,
            d.date_emission,
            d.date_expiration
        FROM chantier_banamur c
        LEFT JOIN client_banamur cl ON cl.id = c.client_id
        LEFT JOIN devis_banamur d ON d.id = c.devis_id
        WHERE c.id = ?
    ";

        $chantier = $this->pdo->prepare($chantierSql);
        $chantier->execute([$chantierId]);
        $infoChantier = $chantier->fetch(PDO::FETCH_ASSOC);

        if (!$infoChantier) {
            return null;
        }

        // 2. Lignes de devis + déboursés réels
        $lignesDevisSql = "
        SELECT 
            ld.*,
            u.libelle as unite_libelle,

            -- Déboursé réel
            (
                SELECT COALESCE(SUM(ldb.montant),0)
                FROM debourse_banamur db
                JOIN ligne_debourse_banamur ldb 
                    ON ldb.debourse_id = db.id
                WHERE db.ligne_devis_id = ld.id
            ) AS montant_debourse_reel

        FROM ligne_devis_banamur ld

        LEFT JOIN unites_mesure u 
            ON u.id = ld.unite_id

        WHERE ld.devis_id = ?

        ORDER BY ld.groupe, ld.designation
    ";

        $lignesDevis = $this->pdo->prepare($lignesDevisSql);
        $lignesDevis->execute([$infoChantier['devis_id']]);
        $lignes = $lignesDevis->fetchAll(PDO::FETCH_ASSOC);


        // Regroupement par zones
        $zones = [];
        foreach ($lignes as $ligne) {
            $zone = $ligne['zone'] ?? 'Non spécifié';
            if (!isset($zones[$zone])) {
                $zones[$zone] = [
                    'zone' => $zone,
                    'lignes' => [],
                    'total_debourse' => 0
                ];
            }
            $zones[$zone]['lignes'][] = $ligne;
            $zones[$zone]['total_debourse'] += (float)$ligne['montant_debourse_reel'];
        }

        // 3. Fiches associées
        $fichesSql = "
        SELECT 
            f.*,
            df.libelle as designation_libelle,
            pf.libelle as precision_libelle
        FROM fiche f
        LEFT JOIN designation_fiche df ON df.id = f.designation_fiche
        LEFT JOIN precision_fiche pf ON pf.id = f.precision_fiche
        WHERE f.chantier_id = ?
        ORDER BY f.date_creat_fiche DESC
    ";

        $fiches = $this->pdo->prepare($fichesSql);
        $fiches->execute([$chantierId]);
        $fichesList = $fiches->fetchAll(PDO::FETCH_ASSOC);

        foreach ($fichesList as $fiche) {

            if ($fiche['decaisse'] != 1) {
                continue;
            }

            $zone = $fiche['zone'] ?? 'Non définie';

            if (isset($zones[$zone])) {
                $zones[$zone]['paye'] += floatval($fiche['montant_fiche']);
            }
        }

        foreach ($zones as &$zone) {

            if ($zone['debourse'] > 0) {
                $zone['progression'] = ($zone['paye'] / $zone['debourse']) * 100;
            } else {
                $zone['progression'] = 0;
            }
        }
        unset($zone);

        // 4. Calcul des totaux

        $budgetTotal = floatval($infoChantier['budget_devis']);

        $totalDebourse = array_sum(array_column($lignes, 'montant_debourse_reel'));

        $montantPaye = array_sum(
            array_column(
                array_filter($fichesList, fn($f) => $f['decaisse'] == 1),
                'montant_fiche'
            )
        );

        $montantRestant = $totalDebourse - $montantPaye;

        $pourcentageRealise = $totalDebourse > 0
            ? ($montantPaye / $totalDebourse) * 100
            : 0;

        // 5. Statistiques fiches
        $ficheStats = [
            'total_fiches' => count($fichesList),
            'fiches_approuvees' => count(array_filter($fichesList, fn($f) => $f['approuve'] == 1)),
            'fiches_decaissees' => count(array_filter($fichesList, fn($f) => $f['decaisse'] == 1)),
            'fiches_refusees' => count(array_filter($fichesList, fn($f) => $f['approuve'] == 2)),
            'fiches_en_attente' => count(array_filter($fichesList, fn($f) => $f['approuve'] == 0))
        ];

        return [
            'chantier' => $infoChantier,
            'devis' => [
                'numero_devis' => $infoChantier['numero_devis'],
                'budget_total' => $budgetTotal,
                'date_emission' => $infoChantier['date_emission']
            ],
            'lignes_devis' => $lignes,
            'zones' => array_values($zones),
            'fiches' => $fichesList,
            'totaux' => [
                'budget_total' => $budgetTotal,
                'total_debourse' => $totalDebourse,
                'montant_paye' => $montantPaye,
                'montant_restant' => $montantRestant,
                'pourcentage_realise' => $pourcentageRealise
            ],
            'stats_fiches' => $ficheStats
        ];
    }


    /**
     * Tous les chantiers avec budgets simplifiés
     */
    public function getTousLesChantiersBudgets()
    {
        return $this->getChantiersAvecBudgets();
    }

    /**
     * Données pour graphiques Chart.js
     */
    public function getChartsData()
    {
        return [
            'evolution_montants' => $this->getEvolutionDepensesEtEntrees(),
            'evolution_chantiers' => $this->formatChartData($this->getEvolutionChantiers(), 'mois', 'nombre_chantiers'),
            'repartition_chantiers' => $this->formatPieData($this->getRepartitionParChantier(), 'chantier', 'montant_total'),
            'top_beneficiaires' => $this->formatBarData($this->getTopBeneficiaires(10), 'beficiaire_fiche', 'montant_total')
        ];
    }

    /**
     * Formatage des données pour Chart.js
     */
    private function formatChartData($data, $labelKey, $valueKey)
    {
        return [
            'labels' => array_column($data, $labelKey),
            'datasets' => [
                [
                    'data' => array_column($data, $valueKey),
                    'backgroundColor' => 'rgba(99, 102, 241, 0.6)',
                    'borderColor' => 'rgba(99, 102, 241, 1)',
                    'borderWidth' => 2
                ]
            ]
        ];
    }

    /**
     * Formatage pour graphiques en secteurs
     */
    private function formatPieData($data, $labelKey, $valueKey)
    {
        $colors = [
            'rgba(255, 99, 132, 0.6)',
            'rgba(54, 162, 235, 0.6)',
            'rgba(255, 205, 86, 0.6)',
            'rgba(75, 192, 192, 0.6)',
            'rgba(153, 102, 255, 0.6)',
            'rgba(255, 159, 64, 0.6)'
        ];

        return [
            'labels' => array_column($data, $labelKey),
            'datasets' => [
                [
                    'data' => array_column($data, $valueKey),
                    'backgroundColor' => array_slice($colors, 0, count($data))
                ]
            ]
        ];
    }

    /**
     * Formatage pour graphiques en barres
     */
    private function formatBarData($data, $labelKey, $valueKey)
    {
        return [
            'labels' => array_column($data, $labelKey),
            'datasets' => [
                [
                    'data' => array_column($data, $valueKey),
                    'backgroundColor' => 'rgba(34, 197, 94, 0.6)',
                    'borderColor' => 'rgba(34, 197, 94, 1)',
                    'borderWidth' => 2
                ]
            ]
        ];
    }
}
