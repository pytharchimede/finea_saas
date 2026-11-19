<?php

class FuelVoucherReplicationRepository
{
    private PDO $projectPdo;
    private PDO $appPdo;

    public function __construct(PDO $projectPdo, PDO $appPdo)
    {
        $this->projectPdo = $projectPdo;
        $this->appPdo = $appPdo;
    }

    public function resolveAppChantierIdFromProjetNum(string $numFiche): ?int
    {
        try {
            $stmt = $this->projectPdo->prepare('SELECT c.code FROM fiche_decaissement f LEFT JOIN chantier_banamur c ON c.id = f.chantier_id WHERE f.num_fiche = :num_fiche AND (f.chantier_id IS NULL OR f.chantier_id=0 OR c.archived_at IS NULL) LIMIT 1');
            $stmt->execute(['num_fiche' => $numFiche]);
            $code = $stmt->fetchColumn();
            if (!$code) {
                return null;
            }

            $appStmt = $this->appPdo->prepare('SELECT id_chantier FROM chantier WHERE code = :code LIMIT 1');
            $appStmt->execute(['code' => $code]);
            $id = $appStmt->fetchColumn();

            return $id ? (int) $id : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}
