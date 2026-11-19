<?php

require_once __DIR__ . '/../model/Database.php';

class DatabaseBackupRepository
{
    private PDO $pdo;
    private ?array $exportState = null;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?: \Database::getConnection();
    }

    public function getDatabaseName(): string
    {
        $name = (string)($this->pdo->query('SELECT DATABASE()')->fetchColumn() ?: '');
        return $name !== '' ? $name : 'database';
    }

    public function listBaseTables(): array
    {
        $rows = $this->pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_NUM) ?: [];
        return array_values(array_filter(array_map(static fn(array $row): string => (string)($row[0] ?? ''), $rows)));
    }

    public function getCreateTableSql(string $table): string
    {
        $st = $this->pdo->query('SHOW CREATE TABLE ' . $this->escapeIdent($table));
        $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : null;
        if (!is_array($row)) {
            return '';
        }
        $values = array_values($row);
        return (string)($values[1] ?? '');
    }

    public function fetchTableRows(string $table): Generator
    {
        // PDO otherwise buffers the entire SELECT before the first fetch.
        $mysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $attribute = PHP_VERSION_ID >= 80500 ? \Pdo\Mysql::ATTR_USE_BUFFERED_QUERY : (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY') ? constant('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY') : 1000);
        $buffered = $mysql ? $this->pdo->getAttribute($attribute) : null;
        $stmt = null;
        try {
            if ($mysql) $this->pdo->setAttribute($attribute, false);
            $stmt = $this->pdo->query('SELECT * FROM ' . $this->escapeIdent($table));
            if (!$stmt) throw new RuntimeException('Lecture impossible de la table '.$table);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) yield $row;
        } finally {
            if ($stmt) $stmt->closeCursor();
            if ($mysql) $this->pdo->setAttribute($attribute, $buffered);
        }
    }

    public function beginExport(): void
    {
        if ($this->exportState !== null) throw new LogicException('Un export est déjà ouvert.');
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') return;
        if ($this->pdo->inTransaction()) throw new RuntimeException('L’export doit utiliser une connexion sans transaction active.');
        $version = (string)$this->pdo->query('SELECT VERSION()')->fetchColumn();
        $setting = stripos($version, 'MariaDB') !== false ? 'max_statement_time' : 'max_execution_time';
        $previous = (string)$this->pdo->query('SELECT @@SESSION.'.$setting)->fetchColumn();
        $this->exportState = ['setting'=>$setting,'previous'=>$previous];
        try {
            $this->pdo->exec('SET SESSION '.$setting.'=0');
            $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        } catch (Throwable $e) {
            $this->endExport();
            throw $e;
        }
    }

    public function endExport(): void
    {
        if ($this->exportState === null) return;
        $state=$this->exportState;
        $this->exportState=null;
        try {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack(); // Read-only export snapshot.
        } finally {
            $this->pdo->exec('SET SESSION '.$state['setting'].'='.(float)$state['previous']);
        }
    }

    public function quoteValue($value): string
    {
        return $value === null ? 'NULL' : (string)$this->pdo->quote((string)$value);
    }

    public function executeStatement(string $sql): void
    {
        $sql = trim($sql);
        if ($sql !== '') {
            $this->pdo->exec($sql);
        }
    }

    public function escapeIdent(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }
}

