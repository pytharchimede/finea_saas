<?php
class Debourse
{
    private $pdo;
    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    // Récupère une ligne debourse par son ID (ici, precision_fiche = id de debourse_banamur)
    public function getDebourseById($id)
    {
        $stmt = $this->pdo->prepare("SELECT d.* FROM debourse_banamur d WHERE d.id = ? AND (NOT EXISTS(SELECT 1 FROM chantier_banamur ca WHERE ca.devis_id=d.devis_id) OR EXISTS(SELECT 1 FROM chantier_banamur c WHERE c.devis_id=d.devis_id AND c.archived_at IS NULL))");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
