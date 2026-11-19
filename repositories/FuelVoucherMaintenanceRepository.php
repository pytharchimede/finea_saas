<?php

require_once __DIR__ . '/../model/Database.php';

class FuelVoucherMaintenanceRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?: \Database::getConnection();
    }

    public function countActiveWithoutReceipt(string $conditionsExtra = ''): int
    {
        return (int)$this->pdo->query($this->buildWithoutReceiptCountSql($conditionsExtra))->fetchColumn();
    }

    public function deactivateActiveWithoutReceipt(string $conditionsExtra = ''): int
    {
        return (int)$this->pdo->exec($this->buildWithoutReceiptUpdateSql($conditionsExtra));
    }

    private function buildWithoutReceiptCountSql(string $conditionsExtra): string
    {
        return "SELECT COUNT(*) FROM demande_essence
                WHERE (img_recu_station IS NULL OR img_recu_station = '')
                  AND (desactive IS NULL OR desactive = 0)
                  $conditionsExtra";
    }

    private function buildWithoutReceiptUpdateSql(string $conditionsExtra): string
    {
        return "UPDATE demande_essence SET desactive = 1
                WHERE (img_recu_station IS NULL OR img_recu_station = '')
                  AND (desactive IS NULL OR desactive = 0)
                  $conditionsExtra";
    }
}
