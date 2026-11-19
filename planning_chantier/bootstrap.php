<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$moduleRoot = __DIR__;
$appRoot = dirname(__DIR__);

$authPaths = [
    $appRoot . '/auth_check.php',
    $appRoot . '/decaissement/auth_check.php',
];

foreach ($authPaths as $authPath) {
    if (is_file($authPath)) {
        require_once $authPath;
        break;
    }
}

$databasePaths = [
    $appRoot . '/decaissement/model/Database.php',
    $appRoot . '/model/Database.php',
    $appRoot . '/config/database.php',
];

$pdo = $pdo ?? null;

foreach ($databasePaths as $databasePath) {
    if ($pdo instanceof PDO) {
        break;
    }

    if (is_file($databasePath)) {
        require_once $databasePath;

        if (class_exists('Database') && method_exists('Database', 'getConnection')) {
            $pdo = Database::getConnection();
            break;
        }

        if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
            $pdo = $GLOBALS['pdo'];
            break;
        }
    }
}

if (!$pdo instanceof PDO) {
    throw new RuntimeException("Connexion PDO introuvable. Vérifier planning_chantier/bootstrap.php");
}

require_once __DIR__ . '/database/PlanningChantierMigrator.php';
require_once __DIR__ . '/includes/planning_helpers.php';

$migrator = new PlanningChantierMigrator($pdo);
$migrator->migrate();

function planning_chantier_user_id(): ?int
{
    foreach (['user_id', 'id_user', 'id', 'auth_user_id'] as $key) {
        if (isset($_SESSION[$key]) && (int)$_SESSION[$key] > 0) {
            return (int)$_SESSION[$key];
        }
    }

    if (isset($_SESSION['user']['id'])) {
        return (int)$_SESSION['user']['id'];
    }

    return null;
}

function planning_chantier_include_layout(string $part): bool
{
    $appRoot = dirname(__DIR__);
    $candidates = [];

    if ($part === 'header') {
        $candidates = [
            $appRoot . '/decaissement/includes/header.php',
            $appRoot . '/includes/header.php',
        ];
    }

    if ($part === 'navbar') {
        $candidates = [
            $appRoot . '/decaissement/includes/navbar.php',
            $appRoot . '/includes/navbar.php',
        ];
    }

    if ($part === 'footer') {
        $candidates = [
            $appRoot . '/decaissement/includes/footer.php',
            $appRoot . '/includes/footer.php',
        ];
    }

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            require_once $candidate;
            return true;
        }
    }

    return false;
}

spl_autoload_register(static function (string $class): void {
    $paths = [
        __DIR__ . '/model/' . $class . '.php',
        __DIR__ . '/repositories/' . $class . '.php',
        __DIR__ . '/services/' . $class . '.php',
        __DIR__ . '/controllers/' . $class . '.php',
    ];

    foreach ($paths as $path) {
        if (is_file($path)) {
            require_once $path;
            return;
        }
    }
});
