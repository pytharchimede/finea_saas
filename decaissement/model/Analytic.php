<?php

class Analytics
{

    private $pdo;
    private ?bool $hasChantierArchiveColumn = null;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    /* =========================
       KPI PRINCIPAUX
    ========================== */

    public function getKPIs()
    {

        $kpi = [];

        /* total dépenses */
        $sql = "SELECT SUM(f.montant_fiche) FROM fiche_decaissement f LEFT JOIN chantier_banamur c ON c.id=f.chantier_id WHERE f.decaisse = 1 AND (f.chantier_id IS NULL OR f.chantier_id=0 OR c.archived_at IS NULL)";
        $kpi['total_depenses'] = (float)$this->pdo->query($sql)->fetchColumn();

        /* dépenses en attente */
        $sql = "SELECT SUM(f.montant_fiche) FROM fiche_decaissement f LEFT JOIN chantier_banamur c ON c.id=f.chantier_id WHERE f.decaisse = 0 AND (f.chantier_id IS NULL OR f.chantier_id=0 OR c.archived_at IS NULL)";
        $kpi['depenses_attente'] = (float)$this->pdo->query($sql)->fetchColumn();

        /* total devis */
        $sql = "SELECT SUM(d.total_ttc) FROM devis_banamur d WHERE d.masque=0 AND (NOT EXISTS(SELECT 1 FROM chantier_banamur ca WHERE ca.devis_id=d.id) OR EXISTS(SELECT 1 FROM chantier_banamur c WHERE c.devis_id=d.id AND c.archived_at IS NULL))";
        $kpi['total_devis'] = (float)$this->pdo->query($sql)->fetchColumn();

        /* nombre chantiers */
        $sql = "SELECT COUNT(*) FROM chantier_banamur WHERE code!='' AND archived_at IS NULL";
        if ($this->chantierHasArchiveColumn()) {
            $sql .= " AND archived_at IS NULL";
        }
        $kpi['nb_chantiers'] = (int)$this->pdo->query($sql)->fetchColumn();

        return $kpi;
    }

    private function chantierHasArchiveColumn(): bool
    {
        if ($this->hasChantierArchiveColumn !== null) {
            return $this->hasChantierArchiveColumn;
        }

        try {
            $stmt = $this->pdo->query("SHOW COLUMNS FROM chantier_banamur LIKE 'archived_at'");
            $this->hasChantierArchiveColumn = (bool) ($stmt && $stmt->fetch(PDO::FETCH_ASSOC));
        } catch (Throwable $e) {
            $this->hasChantierArchiveColumn = false;
        }

        return $this->hasChantierArchiveColumn;
    }

    /* =========================
       EVOLUTION MENSUELLE
    ========================== */

    public function getCashflowMensuel()
    {

        $sql_depenses = "
        SELECT DATE_FORMAT(date_creat_fiche,'%Y-%m') mois,
        SUM(montant_fiche) total
        FROM fiche_decaissement f
        LEFT JOIN chantier_banamur c ON c.id=f.chantier_id
        WHERE f.decaisse=1 AND (f.chantier_id IS NULL OR f.chantier_id=0 OR c.archived_at IS NULL)
        GROUP BY mois
        ";

        $sql_devis = "
        SELECT DATE_FORMAT(date_emission,'%Y-%m') mois,
        SUM(total_ttc) total
        FROM devis_banamur
        WHERE masque=0
        GROUP BY mois
        ";

        $depenses = $this->pdo->query($sql_depenses)->fetchAll(PDO::FETCH_KEY_PAIR);
        $devis = $this->pdo->query($sql_devis)->fetchAll(PDO::FETCH_KEY_PAIR);

        $mois = array_unique(array_merge(array_keys($depenses), array_keys($devis)));
        sort($mois);

        $data = [];

        foreach ($mois as $m) {

            $entree = $devis[$m] ?? 0;
            $dep = $depenses[$m] ?? 0;

            $data[] = [
                'mois' => $m,
                'entrees' => $entree,
                'depenses' => $dep,
                'cashflow' => $entree - $dep
            ];
        }

        return $data;
    }

    /* =========================
       TOP CHANTIERS DEPENSES
    ========================== */

    public function getTopChantiersDepenses()
    {

        $sql = "

        SELECT
        c.titre,
        SUM(f.montant_fiche) total

        FROM fiche_decaissement f

        LEFT JOIN chantier_banamur c
        ON c.id = f.chantier_id

        WHERE f.decaisse=1 AND c.archived_at IS NULL

        GROUP BY c.id

        ORDER BY total DESC

        LIMIT 10

        ";

        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    /* =========================
       RENTABILITE CHANTIER
    ========================== */

    public function getRentabiliteChantiers()
    {

        $sql = "

        SELECT

        c.titre,
        c.cout_total_chantier budget,

        COALESCE(SUM(f.montant_fiche),0) depenses,

        (c.cout_total_chantier - COALESCE(SUM(f.montant_fiche),0)) marge

        FROM chantier_banamur c

        LEFT JOIN fiche_decaissement f
        ON f.chantier_id = c.id

        WHERE c.archived_at IS NULL
        GROUP BY c.id

        ORDER BY marge DESC

        ";

        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    /* =========================
       REPARTITION DEPENSES
    ========================== */

    public function getRepartitionCategories()
    {

        $sql = "

        SELECT

        l.categorie,
        SUM(l.montant) total

        FROM ligne_debourse_banamur l

        GROUP BY l.categorie

        ORDER BY total DESC

        ";

        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    /* =========================
       HEATMAP DEPENSES
    ========================== */

    public function getHeatmapBudget()
    {

        $sql = "

        SELECT

        c.titre,
        DATE_FORMAT(f.date_creat_fiche,'%Y-%m') mois,
        SUM(f.montant_fiche) total

        FROM fiche_decaissement f

        JOIN chantier_banamur c
        ON c.id = f.chantier_id

        GROUP BY c.id,mois

        ORDER BY mois ASC

        ";

        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    /* =========================
       PREVISION SIMPLE
    ========================== */

    public function getForecast()
    {

        $data = $this->getCashflowMensuel();

        $forecast = [];

        foreach ($data as $d) {

            $forecast[] = $d['entrees'] * 1.1;
        }

        return $forecast;
    }


    public function getBudgetDebourses()
    {

        $sql = "

SELECT
DATE_FORMAT(date_debut,'%Y-%m') mois,
SUM(montant_debourse) total

FROM debourse_banamur

GROUP BY mois
ORDER BY mois ASC

";

        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getComparatifChantiers()
    {

        $sql = "

SELECT

c.titre,

COALESCE(SUM(d.montant_debourse),0) budget,

COALESCE(SUM(f.montant_fiche),0) depenses

FROM chantier_banamur c

LEFT JOIN debourse_banamur d
ON d.devis_id = c.devis_id

LEFT JOIN fiche_decaissement f
ON f.chantier_id = c.id

GROUP BY c.id

ORDER BY budget DESC

";

        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
}
