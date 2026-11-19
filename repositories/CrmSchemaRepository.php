<?php

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/CrmSchema.php';

class CrmSchemaRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?: \Database::getConnection();
    }

    public function ensureTables(): void
    {
        \CrmSchema::ensureTables($this->pdo);
    }
}
