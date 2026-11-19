<?php

declare(strict_types=1);

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../logi/includes/LogisticsSchema.php';
require_once __DIR__ . '/../services/LogisticsBusinessService.php';

final class LogisticsBootstrap
{
    public static function pdo(): PDO
    {
        $pdo = Database::getConnection();
        LogisticsSchema::ensure($pdo);
        return $pdo;
    }
}
