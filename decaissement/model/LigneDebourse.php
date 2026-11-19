<?php

class LigneDebourse
{
    private $pdo;
    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    // Récupère une ligne_debourse par debourse_id (ou par id si besoin)
    public function getLigneDebourseByDebourseId($debourse_id)
    {
        $stmt = $this->pdo->prepare("SELECT l.* FROM ligne_debourse_banamur l INNER JOIN debourse_banamur d ON d.id=l.debourse_id WHERE l.debourse_id = ? AND (NOT EXISTS(SELECT 1 FROM chantier_banamur ca WHERE ca.devis_id=d.devis_id) OR EXISTS(SELECT 1 FROM chantier_banamur c WHERE c.devis_id=d.devis_id AND c.archived_at IS NULL))");
        $stmt->execute([$debourse_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
