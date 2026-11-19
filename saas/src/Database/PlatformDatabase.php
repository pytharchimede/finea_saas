<?php

declare(strict_types=1);

namespace Finea\Saas\Database;

use PDO;
use RuntimeException;

final class PlatformDatabase
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $root = dirname(__DIR__, 3);

        require_once $root . '/vendor/autoload.php';

        if (!isset($_ENV['SAAS_DB_NAME'])) {
            \Dotenv\Dotenv::createImmutable($root)->load();
        }

        $required = [
            'SAAS_DB_HOST',
            'SAAS_DB_NAME',
            'SAAS_DB_USER',
            'SAAS_DB_PASS',
        ];

        foreach ($required as $key) {
            if (!isset($_ENV[$key]) || $_ENV[$key] === '') {
                throw new RuntimeException(
                    "Configuration SaaS manquante : {$key}"
                );
            }
        }

        if ($_ENV['SAAS_DB_NAME'] !== 'finea_saas_platform') {
            throw new RuntimeException(
                'La connexion SaaS doit utiliser la base centrale.'
            );
        }

        self::$connection = new PDO(
            'mysql:host=' . $_ENV['SAAS_DB_HOST']
            . ';dbname=' . $_ENV['SAAS_DB_NAME']
            . ';charset=utf8mb4',
            $_ENV['SAAS_DB_USER'],
            $_ENV['SAAS_DB_PASS'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );

        return self::$connection;
    }
}
