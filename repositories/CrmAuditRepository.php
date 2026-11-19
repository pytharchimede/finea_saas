<?php

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/AuditTrail.php';

class CrmAuditRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?: \Database::getConnection();
    }

    public function log(string $event, array $payload): void
    {
        \AuditTrail::log($event, $payload, $this->pdo);
    }
}
