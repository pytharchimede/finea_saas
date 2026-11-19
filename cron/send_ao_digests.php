<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once dirname(__DIR__) . '/model/EnvConfig.php';
\EnvConfig::loadFromDirectory(dirname(__DIR__));
require_once dirname(__DIR__) . '/model/Database.php';
require_once dirname(__DIR__) . '/services/AoEmailDigestService.php';

try {
    $stats = (new AoEmailDigestService(\Database::getConnection()))->run();
    echo json_encode(['ok' => true, 'stats' => $stats], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(1);
}
