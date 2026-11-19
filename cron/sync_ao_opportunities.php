<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once dirname(__DIR__) . '/model/EnvConfig.php';
EnvConfig::loadFromDirectory(dirname(__DIR__));
require_once dirname(__DIR__) . '/model/Database.php';
require_once dirname(__DIR__) . '/model/AccessManager.php';
require_once dirname(__DIR__) . '/services/AoMultiSourceSyncService.php';
try {
    $stats = (new AoMultiSourceSyncService(Database::getConnection()))->run();
    echo json_encode(['ok' => $stats['errors'] === 0, 'stats' => $stats], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($stats['errors'] === 0 ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(1);
}
