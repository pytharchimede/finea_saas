<?php

class DevisModuleRepository
{
    private PDO $pdo;
    private ?array $tables = null;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function countRows(string $table): int
    {
        if (!$this->tableExists($table)) {
            return 0;
        }

        try {
            return (int) $this->pdo->query('SELECT COUNT(*) FROM `' . str_replace('`', '``', $table) . '`')->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    public function sumColumn(string $table, string $column): float
    {
        if (!$this->tableExists($table) || !$this->columnExists($table, $column)) {
            return 0.0;
        }

        try {
            $sql = 'SELECT COALESCE(SUM(`' . str_replace('`', '``', $column) . '`), 0) FROM `' . str_replace('`', '``', $table) . '`';
            return (float) $this->pdo->query($sql)->fetchColumn();
        } catch (Throwable $e) {
            return 0.0;
        }
    }

    public function latestRows(string $table, int $limit = 6): array
    {
        if (!$this->tableExists($table)) {
            return [];
        }

        $limit = max(1, min(12, $limit));
        $orderColumn = $this->firstExistingColumn($table, ['created_at', 'date_creation', 'id_devis', 'id_chantier', 'id_client', 'id_offre', 'id']);
        $orderSql = $orderColumn !== null ? ' ORDER BY `' . str_replace('`', '``', $orderColumn) . '` DESC' : '';

        try {
            $stmt = $this->pdo->query('SELECT * FROM `' . str_replace('`', '``', $table) . '`' . $orderSql . ' LIMIT ' . $limit);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    private function tableExists(string $table): bool
    {
        if ($this->tables === null) {
            try {
                $this->tables = array_map('strtolower', $this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) ?: []);
            } catch (Throwable $e) {
                $this->tables = [];
            }
        }

        return in_array(strtolower($table), $this->tables, true);
    }

    private function columnExists(string $table, string $column): bool
    {
        return $this->firstExistingColumn($table, [$column]) !== null;
    }

    private function firstExistingColumn(string $table, array $columns): ?string
    {
        try {
            $existing = $this->pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`')->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
        } catch (Throwable $e) {
            return null;
        }

        foreach ($columns as $column) {
            if (in_array($column, $existing, true)) {
                return $column;
            }
        }

        return null;
    }
}
