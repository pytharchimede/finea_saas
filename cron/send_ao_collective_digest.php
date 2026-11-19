<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$arguments = array_slice($argv, 1);
if ($arguments !== [] && $arguments !== ['--send-now']) {
    fwrite(STDERR, "Usage : php cron/send_ao_collective_digest.php [--send-now]\n");
    exit(2);
}

require_once dirname(__DIR__) . '/model/EnvConfig.php';
EnvConfig::loadFromDirectory(dirname(__DIR__));
require_once dirname(__DIR__) . '/model/Database.php';
require_once dirname(__DIR__) . '/services/AoCollectiveDigestService.php';

try {
    // Envoi initial autorisé hors calendrier ; activation et verrou restent obligatoires.
    $stats = (new AoCollectiveDigestService(Database::getConnection()))->run($arguments === ['--send-now']);
    $ok = in_array($stats['status'], ['sent', 'not_due'], true);
    echo json_encode(['ok' => $ok, 'stats' => $stats], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($ok ? 0 : 1);
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(1);
}
