<?php

require_once __DIR__ . '/../model/Database.php';

class CrmProspectRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?: \Database::getConnection();
    }

    private function getTableColumns(): array
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        try {
            $cols = $this->pdo->query('SHOW COLUMNS FROM crm_prospect_banamur')->fetchAll(PDO::FETCH_COLUMN, 0);
            if (is_array($cols)) {
                return $cached = $cols;
            }
        } catch (Throwable $e) {
        }

        return $cached = [];
    }

    private function hasColumn(string $name): bool
    {
        return in_array($name, $this->getTableColumns(), true);
    }

    public function create(array $data): int
    {
        $columns = [];
        $params = [];

        foreach (['client_id', 'code_client', 'localisation', 'commune', 'bp', 'pays'] as $col) {
            if ($this->hasColumn($col)) {
                $columns[] = $col;
                $params[$col] = $data[$col] ?? null;
            }
        }

        foreach (['nom', 'societe', 'telephone', 'email', 'statut', 'source', 'notes', 'created_by'] as $col) {
            $columns[] = $col;
            $params[$col] = $data[$col] ?? null;
        }

        $placeholders = array_map(static fn($c) => ':' . $c, $columns);
        $sql = 'INSERT INTO crm_prospect_banamur (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $this->pdo->lastInsertId();
    }

    public function listLatest(int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));

        $select = [
            'id',
            ($this->hasColumn('client_id') ? 'client_id' : 'NULL AS client_id'),
            ($this->hasColumn('code_client') ? 'code_client' : 'NULL AS code_client'),
            ($this->hasColumn('localisation') ? 'localisation' : 'NULL AS localisation'),
            ($this->hasColumn('commune') ? 'commune' : 'NULL AS commune'),
            ($this->hasColumn('bp') ? 'bp' : 'NULL AS bp'),
            ($this->hasColumn('pays') ? 'pays' : 'NULL AS pays'),
            'nom',
            'societe',
            'telephone',
            'email',
            'statut',
            'source',
            'created_at',
        ];

        $stmt = $this->pdo->query(
            'SELECT ' . implode(', ', $select) .
            " FROM crm_prospect_banamur
              ORDER BY created_at DESC
              LIMIT {$limit}"
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function listForSelect(int $limit = 500): array
    {
        $limit = max(1, min(2000, $limit));
        $stmt = $this->pdo->query(
            "SELECT id, nom, societe
             FROM crm_prospect_banamur
             ORDER BY nom ASC
             LIMIT {$limit}"
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function countAll(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM crm_prospect_banamur')->fetchColumn();
    }
}
