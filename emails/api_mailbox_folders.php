<?php
// Cette API doit toujours répondre en JSON. On évite que des warnings PHP polluent la sortie.
@ini_set('display_errors', '0');
@ini_set('html_errors', '0');

require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../controllers/EmailController.php';
\AccessManager::requirePageAccess();

$emailController = new \EmailController();

require_once __DIR__ . '/../decaissement/config/mail_config.php';
require_once __DIR__ . '/../decaissement/model/EmailFetcher.php';

header('Content-Type: application/json; charset=UTF-8');

$jsonOptions = JSON_UNESCAPED_UNICODE;
if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
    $jsonOptions |= JSON_INVALID_UTF8_SUBSTITUTE;
}
if (defined('JSON_PARTIAL_OUTPUT_ON_ERROR')) {
    $jsonOptions |= JSON_PARTIAL_OUTPUT_ON_ERROR;
}

$cleanString = static function ($v) {
    if (!is_string($v)) {
        return $v;
    }
    // Force UTF-8 “safe” (évite que json_encode() renvoie false)
    if (function_exists('iconv')) {
        $out = @iconv('UTF-8', 'UTF-8//IGNORE', $v);
        if ($out !== false) {
            return $out;
        }
    }
    return preg_replace('/[^\x09\x0A\x0D\x20-\x7E\xC2-\xF4][\x80-\xBF]*/', '', $v);
};

$cleanRecursive = static function ($data) use (&$cleanRecursive, $cleanString) {
    if (is_array($data)) {
        $out = [];
        foreach ($data as $k => $v) {
            $out[$k] = $cleanRecursive($v);
        }
        return $out;
    }
    return $cleanString($data);
};

$username = trim((string)($_SESSION['mail_pro'] ?? ''));
$userId = (int)($_SESSION['user_id'] ?? 0);
$forceRefresh = !empty($_GET['force_refresh']) && $_GET['force_refresh'] !== '0';

$emailController->hydrateMailboxPasswordFromStorage($userId);
$password = (string)($_SESSION['personnel_email_password'] ?? '');

$cacheManager = $emailController->cache();
if (!$forceRefresh) {
    $cachedFolders = $cacheManager->getFolderList($userId, 'imap_folders');
    if (is_array($cachedFolders)) {
        $cacheStatus = $cacheManager->getFolderCacheStatus($userId, 'imap_folders');
        echo json_encode([
            'ok' => true,
            'error' => null,
            'folders' => $cachedFolders['folders'],
            'delimiter' => $cachedFolders['delimiter'],
            'cached' => true,
            'cacheTtlSeconds' => $cachedFolders['ttl_seconds'],
            'cacheStatus' => $cacheStatus,
        ], $jsonOptions);
        exit;
    }
}

if ($username === '' || trim($password) === '') {
    $payload = [
        'ok' => false,
        'error' => 'Non connecté à la messagerie.',
        'folders' => [],
        'delimiter' => '.',
    ];
    echo json_encode($cleanRecursive($payload), $jsonOptions);
    exit;
}

$fetcher = new \EmailFetcher(IMAP_HOSTNAME, $username, $password);
if (!$fetcher->isConnected()) {
    $payload = [
        'ok' => false,
        'error' => $fetcher->getLastError() ?: 'Connexion IMAP impossible.',
        'folders' => [],
        'delimiter' => '.',
    ];
    echo json_encode($cleanRecursive($payload), $jsonOptions);
    $fetcher->closeConnection();
    exit;
}

$emailController->persistMailboxPasswordIfNeeded($userId);

$folders = $fetcher->listMailboxes('*');
$delimiter = $fetcher->getMailboxDelimiter();
$fetcher->closeConnection();

$cacheManager->saveFolderList($userId, $folders, $delimiter, 'imap_folders');
$cacheStatus = $cacheManager->getFolderCacheStatus($userId, 'imap_folders');

echo json_encode([
    'ok' => true,
    'error' => null,
    'folders' => $folders,
    'delimiter' => $delimiter,
    'cached' => false,
    'cacheTtlSeconds' => $cacheManager->getFolderCacheTtlSeconds(),
    'cacheStatus' => $cacheStatus,
], $jsonOptions);




