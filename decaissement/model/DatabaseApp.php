<?php
require_once __DIR__ . '/../../model/EnvConfig.php';

// Connexion secondaire pour les données "app" (bons d'essence, etc.)
// Ajustez le nom de la base si besoin (fidestci_app_db vs fidest_app_db)
if (!class_exists('DatabaseApp')) {
    class DatabaseApp
    {
        private static $pdo;

        public static function getConnection()
        {
            if (self::$pdo === null) {
                try {
                    \EnvConfig::loadFromDirectory(__DIR__);
                    // FINEA_SAAS_DB_GUARD
                    $host = trim((string) \EnvConfig::get('APP_DB_HOST', ''));
                    $name = trim((string) \EnvConfig::get('APP_DB_NAME', ''));
                    $user = trim((string) \EnvConfig::get('APP_DB_USER', ''));
                    $pass = (string) \EnvConfig::get('APP_DB_PASS', '');

                    if ($host === '' || $name === '' || $user === '' || $pass === '') {
                        throw new \RuntimeException('Configuration SaaS incomplète.');
                    }

                    if ($name !== 'finea_saas_dev') {
                        throw new \RuntimeException('Base non autorisée pour FINEA SaaS local.');
                    }

                    self::$pdo = new PDO("mysql:host={$host};dbname={$name};charset=utf8mb4", $user, $pass);
                    self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                    self::$pdo->exec("SET NAMES utf8mb4");
                } catch (PDOException $e) {
                    die('DatabaseApp connection failed: ' . $e->getMessage());
                }
            }
            return self::$pdo;
        }
    }
}
