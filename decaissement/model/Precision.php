<?php

class Precision
{
    private $pdo;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    // Ajouter une nouvelle précision
    public function createPrecision($chantier_id, $designation_id, $libelle, $montant)
    {
        $sql = "INSERT INTO precision_fiche_banamur (chantier_id, designation_id, libelle, montant)
                VALUES (:chantier_id, :designation_id, :libelle, :montant)";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            ':chantier_id' => $chantier_id,
            ':designation_id' => $designation_id,
            ':libelle' => $libelle,
            ':montant' => $montant,
        ]);
    }

    // Lire toutes les précisions d'une désignation (et éventuellement d'un chantier)
    public function getPrecisionsByDesignation($designation_id, $chantier_id = null)
    {
        if ($chantier_id) {
            $sql = "SELECT * FROM precision_fiche_banamur WHERE designation_id = :designation_id AND chantier_id = :chantier_id";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':designation_id' => $designation_id,
                ':chantier_id' => $chantier_id
            ]);
        } else {
            $sql = "SELECT * FROM precision_fiche_banamur WHERE designation_id = :designation_id";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':designation_id' => $designation_id]);
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAllPrecisions()
    {
        $sql = "SELECT * FROM precision_fiche_banamur";
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getPrecisionById($id)
    {
        $sql = "SELECT * FROM precision_fiche_banamur WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
