<?php

class AoSyncLogRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS ao_sync_logs (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                source VARCHAR(80) NOT NULL,
                started_at DATETIME NOT NULL,
                ended_at DATETIME NULL,
                status VARCHAR(30) NOT NULL,
                total_found INT NOT NULL DEFAULT 0,
                total_new INT NOT NULL DEFAULT 0,
                total_updated INT NOT NULL DEFAULT 0,
                total_errors INT NOT NULL DEFAULT 0,
                message TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_ao_sync_started (started_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    public function start(string $source): int
    {
        $stmt = $this->pdo->prepare("INSERT INTO ao_sync_logs (source, started_at, status) VALUES (:source, NOW(), 'running')");
        $stmt->execute(['source' => $source]);
        return (int) $this->pdo->lastInsertId();
    }

    public function finish(int $id, array $stats, string $status, string $message = ''): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ao_sync_logs SET ended_at = NOW(), status = :status,
                total_found = :found, total_new = :new_count, total_updated = :updated,
                total_errors = :errors, message = :message WHERE id = :id'
        );
        $stmt->execute([
            'status' => $status, 'found' => (int) ($stats['found'] ?? 0),
            'new_count' => (int) ($stats['new'] ?? 0), 'updated' => (int) ($stats['updated'] ?? 0),
            'errors' => (int) ($stats['errors'] ?? 0), 'message' => $message, 'id' => $id,
        ]);
    }

    public function latest(int $limit = 50): array
    {
        return $this->pdo->query('SELECT * FROM ao_sync_logs ORDER BY id DESC LIMIT ' . max(1, min(200, $limit)))->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
