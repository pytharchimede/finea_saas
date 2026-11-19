<?php

class AiAssistantMigrationService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function ensureSchema(): void
    {
        $migrationPath = __DIR__ . '/../Database/migrations/create_ai_assistant_tables.sql';

        if (!is_file($migrationPath)) {
            $migrationPath = __DIR__ . '/../database/migrations/create_ai_assistant_tables.sql';
        }

        if (!is_file($migrationPath)) {
            return;
        }

        $sql = (string) file_get_contents($migrationPath);

        foreach ($this->splitSqlStatements($sql) as $statement) {
            $trimmed = trim($statement);

            if ($trimmed === '') {
                continue;
            }

            if (stripos($trimmed, 'INSERT INTO ai_templates') === 0) {
                $this->ensureRequestTypeColumns();
            }

            $this->pdo->exec($trimmed);
        }

        $this->ensureRequestTypeColumns();
    }

    private function ensureRequestTypeColumns(): void
    {
        foreach (['ai_templates' => 'task_type', 'ai_requests' => 'task_type'] as $table => $afterColumn) {
            try {
                $tableStmt = $this->pdo->prepare('SHOW TABLES LIKE :table_name');
                $tableStmt->execute(['table_name' => $table]);

                if (!$tableStmt->fetchColumn()) {
                    continue;
                }

                $columns = $this->pdo
                    ->query('SHOW COLUMNS FROM `' . $table . '`')
                    ->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];

                if (!in_array('request_type', $columns, true)) {
                    $this->pdo->exec(
                        'ALTER TABLE `' . $table . '` ADD COLUMN request_type VARCHAR(80) DEFAULT NULL AFTER `' . $afterColumn . '`'
                    );
                }
            } catch (Throwable $e) {
                // Ne bloque pas le module si l'hebergeur refuse l'ALTER TABLE.
            }
        }
    }

    private function splitSqlStatements(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $inSingle = false;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $prev = $i > 0 ? $sql[$i - 1] : '';

            if ($char === "'" && $prev !== '\\') {
                $inSingle = !$inSingle;
            }

            if ($char === ';' && !$inSingle) {
                $statements[] = $buffer;
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        if (trim($buffer) !== '') {
            $statements[] = $buffer;
        }

        return $statements;
    }
}
