<?php

class Chantier
{
    private $pdo;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    // Ajouter un nouveau chantier
    public function createChantier($lib_chantier, $societe_chantier_id, $secur_ajout_chantier, $num_chantier, $cout_total_chantier)
    {
        $sql = "INSERT INTO chantier (lib_chantier, societe_chantier_id, date_creat_chantier, secur_ajout_chantier, date_mod_chantier, secur_mod_chantier, num_chantier, cout_total_chantier)
                VALUES (:lib_chantier, :societe_chantier_id, NOW(), :secur_ajout_chantier, NOW(), :secur_mod_chantier, :num_chantier, :cout_total_chantier)";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            ':lib_chantier' => $lib_chantier,
            ':societe_chantier_id' => $societe_chantier_id,
            ':secur_ajout_chantier' => $secur_ajout_chantier,
            ':secur_mod_chantier' => $secur_ajout_chantier,
            ':num_chantier' => $num_chantier,
            ':cout_total_chantier' => $cout_total_chantier,
        ]);
    }

    // Lire tous les chantiers
    public function getAllChantiers()
    {
        $sql = "SELECT * FROM chantier WHERE num_chantier!='' ";
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Lire tous les chantiers de l'entreprise selectionnée
    public function getAllChantiersByEntreprise($entreprise)
    {
        $sql = "SELECT * FROM chantier WHERE entreprise = :entreprise AND num_chantier != '' ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindParam(':entreprise', $entreprise, PDO::PARAM_STR);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }


    // Lire un chantier par ID
    public function getChantierById($id_chantier)
    {
        $sql = "SELECT * FROM chantier WHERE id_chantier = :id_chantier";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id_chantier' => $id_chantier]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Mettre à jour un chantier
    public function updateChantier($id_chantier, $lib_chantier, $societe_chantier_id, $secur_mod_chantier, $num_chantier, $cout_total_chantier)
    {
        $sql = "UPDATE chantier
                SET lib_chantier = :lib_chantier,
                    societe_chantier_id = :societe_chantier_id,
                    date_mod_chantier = NOW(),
                    secur_mod_chantier = :secur_mod_chantier,
                    num_chantier = :num_chantier,
                    cout_total_chantier = :cout_total_chantier
                WHERE id_chantier = :id_chantier";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            ':id_chantier' => $id_chantier,
            ':lib_chantier' => $lib_chantier,
            ':societe_chantier_id' => $societe_chantier_id,
            ':secur_mod_chantier' => $secur_mod_chantier,
            ':num_chantier' => $num_chantier,
            ':cout_total_chantier' => $cout_total_chantier,
        ]);
    }

    // Supprimer un chantier
    public function deleteChantier($id_chantier)
    {
        $sql = "DELETE FROM chantier WHERE id_chantier = :id_chantier";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([':id_chantier' => $id_chantier]);
    }

    /**
     * Statistiques des chantiers
     */
    public function getStatsChantiers()
    {
        $sql = "
            SELECT 
                COUNT(*) as total_chantiers,
                COUNT(CASE WHEN MONTH(date_creat_chantier) = MONTH(CURDATE()) AND YEAR(date_creat_chantier) = YEAR(CURDATE()) THEN 1 END) as nouveaux_mois,
                COUNT(CASE WHEN DATE(date_creat_chantier) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN 1 END) as nouveaux_semaine
            FROM chantier
            WHERE num_chantier != ''
        ";
        $stmt = $this->pdo->query($sql);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Montants par chantier et pourcentages
     */
    public function getMontantsParChantier()
    {
        $sql = "
            SELECT 
                c.lib_chantier as titre,
                c.num_chantier as code,
                COALESCE(c.cout_total_chantier, 0) as montant_budget,
                COALESCE(SUM(f.montant_fiche), 0) as total_depenses,
                COUNT(f.id_fiche) as nombre_fiches
            FROM chantier c
            LEFT JOIN fiche f ON f.chantier_id = c.id_chantier
            WHERE c.num_chantier != ''
            GROUP BY c.id_chantier, c.lib_chantier, c.num_chantier, c.cout_total_chantier
            ORDER BY total_depenses DESC
        ";
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Évolution des chantiers par mois
     */
    public function getEvolutionChantiers()
    {
        $sql = "
            SELECT 
                DATE_FORMAT(date_creat_chantier, '%Y-%m') as mois,
                COUNT(*) as nombre_chantiers
            FROM chantier
            WHERE date_creat_chantier >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                AND num_chantier != ''
            GROUP BY DATE_FORMAT(date_creat_chantier, '%Y-%m')
            ORDER BY mois ASC
        ";
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Récupère les chantiers avec leurs budgets et dépenses
     */
    public function getChantiersAvecBudgets()
    {
        $sql = "
            SELECT 
                c.*,
                COALESCE(c.cout_total_chantier, 0) as budget_total,
                COALESCE(SUM(f.montant_fiche), 0) as total_depenses,
                COUNT(f.id_fiche) as nombre_fiches,
                CASE 
                    WHEN c.cout_total_chantier > 0 THEN ROUND((SUM(f.montant_fiche) / c.cout_total_chantier) * 100, 2)
                    ELSE 0
                END as pourcentage_consomme
            FROM chantier c
            LEFT JOIN fiche f ON f.chantier_id = c.id_chantier
            WHERE c.num_chantier != ''
            GROUP BY c.id_chantier
            ORDER BY c.date_creat_chantier DESC
        ";
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
