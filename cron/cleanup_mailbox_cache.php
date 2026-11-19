<?php

declare(strict_types=1);

require_once __DIR__ . '/../model/EnvConfig.php';
\EnvConfig::loadFromDirectory(__DIR__);
require_once __DIR__ . '/../controllers/EmailController.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Forbidden\n";
    exit(1);
}

function mailbox_cache_log(string $message): void
{
    echo '[' . date('Y-m-d H:i:s') . "] $message\n";
}

try {
    $cacheManager = (new \EmailController())->cache();
    $result = $cacheManager->cleanupExpiredCache();

    mailbox_cache_log(
        'Nettoyage terminé: ' .
            (int) ($result['message_deleted'] ?? 0) . ' entrées mails supprimées, ' .
            (int) ($result['folder_deleted'] ?? 0) . ' entrées dossiers supprimées.'
    );
    exit(0);
} catch (Throwable $e) {
    mailbox_cache_log('ECHEC: ' . $e->getMessage());
    exit(1);
}
