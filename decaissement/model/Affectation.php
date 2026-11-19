<?php

class Affectation
{
    private $pdo;
    private ?bool $tableAvailable = null;

    private const DEFAULT_AFFECTATIONS = [
        [
            'id_affectation' => 1,
            'lib_affectation' => 'CHANTIER',
            'num_affectation' => 'AF00',
            'societe_affectation_id' => 0,
        ],
        [
            'id_affectation' => 19,
            'lib_affectation' => 'BUREAU',
            'num_affectation' => 'AF02',
            'societe_affectation_id' => 0,
        ],
    ];

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    private function hasAffectationTable(): bool
    {
        if ($this->tableAvailable !== null) {
            return $this->tableAvailable;
        }

        try {
            $stmt = $this->pdo->query("SHOW TABLES LIKE 'affectation'");
            $this->tableAvailable = (bool) $stmt->fetchColumn();
        } catch (Throwable $e) {
            $this->tableAvailable = false;
        }

        return $this->tableAvailable;
    }

    private function getDefaultAffectations(): array
    {
        return self::DEFAULT_AFFECTATIONS;
    }

    // Ajouter une nouvelle affectation
    public function createAffectation($lib_affectation, $num_affectation, $societe_affectation_id, $secur_ajout_affectation)
    {
        if (!$this->hasAffectationTable()) {
            return false;
        }

        $sql = "INSERT INTO affectation (lib_affectation, date_creat_affectation, secur_ajout_affectation, num_affectation, societe_affectation_id, date_mod_affectation, secur_mod_affectation)
                VALUES (:lib_affectation, NOW(), :secur_ajout_affectation, :num_affectation, :societe_affectation_id, NOW(), :secur_mod_affectation)";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            ':lib_affectation' => $lib_affectation,
            ':secur_ajout_affectation' => $secur_ajout_affectation,
            ':num_affectation' => $num_affectation,
            ':societe_affectation_id' => $societe_affectation_id,
            ':secur_mod_affectation' => $secur_ajout_affectation,
        ]);
    }

    // Lire toutes les affectations
    public function getAllAffectations()
    {
        if (!$this->hasAffectationTable()) {
            return $this->getDefaultAffectations();
        }

        $sql = "SELECT * FROM affectation";
        try {
            $stmt = $this->pdo->query($sql);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return $rows ?: $this->getDefaultAffectations();
        } catch (Throwable $e) {
            return $this->getDefaultAffectations();
        }
    }

    // Lire une affectation par ID
    public function getAffectationById($id_affectation)
    {
        if (!$this->hasAffectationTable()) {
            foreach ($this->getDefaultAffectations() as $affectation) {
                if ((int) ($affectation['id_affectation'] ?? 0) === (int) $id_affectation) {
                    return $affectation;
                }
            }
            return false;
        }

        $sql = "SELECT * FROM affectation WHERE id_affectation = :id_affectation";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id_affectation' => $id_affectation]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Mettre à jour une affectation
    public function updateAffectation($id_affectation, $lib_affectation, $num_affectation, $societe_affectation_id, $secur_mod_affectation)
    {
        if (!$this->hasAffectationTable()) {
            return false;
        }

        $sql = "UPDATE affectation
                SET lib_affectation = :lib_affectation,
                    num_affectation = :num_affectation,
                    societe_affectation_id = :societe_affectation_id,
                    date_mod_affectation = NOW(),
                    secur_mod_affectation = :secur_mod_affectation
                WHERE id_affectation = :id_affectation";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            ':id_affectation' => $id_affectation,
            ':lib_affectation' => $lib_affectation,
            ':num_affectation' => $num_affectation,
            ':societe_affectation_id' => $societe_affectation_id,
            ':secur_mod_affectation' => $secur_mod_affectation,
        ]);
    }

    // Supprimer une affectation
    public function deleteAffectation($id_affectation)
    {
        if (!$this->hasAffectationTable()) {
            return false;
        }

        $sql = "DELETE FROM affectation WHERE id_affectation = :id_affectation";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([':id_affectation' => $id_affectation]);
    }
}
