<?php

class LogisticsRequestRepository
{
    public function __construct(private PDO $pdo)
    {
        $this->ensureTable();
    }

    private function ensureTable(): void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS logistics_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            type VARCHAR(32) NOT NULL,
            asset_id INT NOT NULL,
            date_start DATETIME NOT NULL,
            date_end DATETIME NULL,
            motif TEXT,
            statut VARCHAR(32) NOT NULL DEFAULT 'en attente',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    }

    public function create(array $data): bool
    {
        $stmt = $this->pdo->prepare("INSERT INTO logistics_requests (user_id, type, asset_id, date_start, date_end, motif, statut, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
        return $stmt->execute([
            $data['user_id'],
            $data['type'],
            $data['asset_id'],
            $data['date_start'],
            $data['date_end'] ?? null,
            $data['motif'] ?? null,
            $data['statut'] ?? 'en attente',
        ]);
    }
}
