<?php

class ServiceDirectory
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function getAll(): array
    {
        $stmt = $this->pdo->query('SELECT id_service AS id, lib_service AS label FROM service ORDER BY lib_service ASC');
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
