<?php

/**
 * Synchronisation des pointages (API attendance -> DB locale).
 *
 * - Idempotent via event_uid (INSERT IGNORE)
 * - Conçu pour être exécuté en CLI (Task Scheduler / cron)
 *
 * Exemples:
 *   php cron/sync_attendance_today.php --limit=500
 */

declare(strict_types=1);

$root = realpath(__DIR__ . '/..');
if ($root === false) {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Unable to resolve project root\n");
    } else {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Unable to resolve project root\n";
    }
    exit(1);
}

require_once $root . '/controllers/CronController.php';
require_once $root . '/model/EnvConfig.php';

function attendance_parse_cli_args(array $argv): array
{
    $limit = 500;

    foreach ($argv as $arg) {
        if (strpos($arg, '--limit=') === 0) {
            $v = substr($arg, strlen('--limit='));
            if (is_numeric($v)) {
                $limit = (int) $v;
            }
        }
    }

    $limit = max(1, min(500, $limit));
    return ['limit' => $limit];
}

if (PHP_SAPI !== 'cli') {
    \EnvConfig::loadFromDirectory($root);
    $expected = (string) (\EnvConfig::get('ATTENDANCE_SYNC_TOKEN', '') ?? '');
    $provided = (string) ($_GET['token'] ?? '');

    if ($expected === '' || !hash_equals($expected, $provided)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Forbidden\n";
        exit(1);
    }

    header('Content-Type: application/json; charset=utf-8');
    $result = (new CronController())->syncAttendanceToday(null, 500);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit(0);
}

try {
    $args = attendance_parse_cli_args($argv);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$result = (new CronController())->syncAttendanceToday(null, (int) $args['limit']);

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
exit(($result['ok'] ?? false) ? 0 : 2);
