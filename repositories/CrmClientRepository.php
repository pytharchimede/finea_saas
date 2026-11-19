<?php

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__.'/../services/ClientIdentityMigration.php';

class CrmClientRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?: \Database::getConnection();
        (new ClientIdentityMigration($this->pdo))->install();
    }

    public function create(array $data): int
    {
        require_once __DIR__.'/../services/ClientIdentityService.php';
        return (new ClientIdentityService($this->pdo))->save($data,null,(int)($_SESSION['user_id']??0));
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT *
             FROM client_banamur
             WHERE id_client = :id
             LIMIT 1"
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function listLatest(int $limit = 200): array
    {
        $limit = max(1, min(1000, $limit));
        return $this->pdo->query(
            "SELECT *
             FROM client_banamur
             ORDER BY id_client DESC
             LIMIT {$limit}"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function listForSelect(int $limit = 1200): array
    {
        $limit = max(1, min(3000, $limit));
        return $this->pdo->query(
            "SELECT *
             FROM client_banamur
             ORDER BY nom_client ASC
             LIMIT {$limit}"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function listForProspectForm(int $limit = 2000): array
    {
        $limit = max(1, min(3000, $limit));
        return $this->pdo->query(
            "SELECT *
             FROM client_banamur
             ORDER BY nom_client ASC
             LIMIT {$limit}"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function countAll(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM client_banamur')->fetchColumn();
    }
}


