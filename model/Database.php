<?php
require_once __DIR__ . '/EnvConfig.php';
require_once __DIR__.'/../services/FinancialWorkflowProgress.php';
FinancialWorkflowProgress::start();

// Évite une redéclaration si un autre fichier a déjà défini Database (ex: decaissement/model/Database.php)
if (!class_exists('Database')) {
    class Database
    {
        private static $pdo;

        public static function getConnection()
        {
            if (self::$pdo === null) {
                try {
                    \EnvConfig::loadFromDirectory(__DIR__);
                    // FINEA_SAAS_DB_GUARD
                    $host = trim((string) \EnvConfig::get('PROJECT_DB_HOST', ''));
                    $name = trim((string) \EnvConfig::get('PROJECT_DB_NAME', ''));
                    $user = trim((string) \EnvConfig::get('PROJECT_DB_USER', ''));
                    $pass = (string) \EnvConfig::get('PROJECT_DB_PASS', '');

                    if ($host === '' || $name === '' || $user === '' || $pass === '') {
                        throw new \RuntimeException('Configuration SaaS incomplète.');
                    }

                    $mode = trim((string) \EnvConfig::get('FINEA_INSTANCE_MODE', 'development'));

                    if ($mode === 'development') {
                        if ($name !== 'finea_saas_dev') {
                            throw new \RuntimeException('Base non autorisée en développement.');
                        }
                    } elseif ($mode === 'dedicated') {
                        $tenantUuid = trim((string) \EnvConfig::get('FINEA_TENANT_UUID', ''));
                        $allowedDatabase = trim((string) \EnvConfig::get('FINEA_TENANT_DB_NAME', ''));

                        if (
                            !preg_match(
                                '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD',
                                $tenantUuid
                            )
                            || $allowedDatabase === ''
                            || !preg_match('/^[a-zA-Z0-9_]+$/D', $allowedDatabase)
                            || !hash_equals($allowedDatabase, $name)
                            || $name === 'finea_saas_dev'
                            || $name === 'finea_saas_platform'
                        ) {
                            throw new \RuntimeException('Configuration de l’instance dédiée invalide.');
                        }
                    } else {
                        throw new \RuntimeException('Mode d’instance FINEA inconnu.');
                    }

                    self::$pdo = new PDO(
                        "mysql:host={$host};dbname={$name};charset=utf8mb4",
                        $user,
                        $pass,
                        [
                            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                            (PHP_VERSION_ID >= 80500 ? \Pdo\Mysql::ATTR_INIT_COMMAND : PDO::MYSQL_ATTR_INIT_COMMAND)
                                => 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci',
                        ]
                    );
                } catch (PDOException $e) {
                    die('Database connection failed: ' . $e->getMessage());
                }
            }
            return self::$pdo;
        }
    }
}

