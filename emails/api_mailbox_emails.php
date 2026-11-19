<?php
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../controllers/EmailController.php';
\AccessManager::requirePageAccess();

$emailController = new \EmailController();

require_once __DIR__ . '/../decaissement/config/mail_config.php';
require_once __DIR__ . '/../decaissement/model/EmailFetcher.php';

header('Content-Type: application/json; charset=UTF-8');

$username = trim((string)($_SESSION['mail_pro'] ?? ''));
$userId = (int)($_SESSION['user_id'] ?? 0);

$emailController->hydrateMailboxPasswordFromStorage($userId);
$password = (string)($_SESSION['personnel_email_password'] ?? '');

$folder = trim((string)($_GET['folder'] ?? 'INBOX'));
if ($folder === '') {
    $folder = 'INBOX';
}
if (strpos($folder, "\0") !== false || strpos($folder, '{') !== false || strpos($folder, '}') !== false) {
    echo json_encode([
        'ok' => false,
        'error' => 'Dossier invalide.',
        'emails' => [],
        'unreadCount' => 0,
    ]);
    exit;
}

$tab = (string)($_GET['tab'] ?? 'inbox'); // inbox|unread
$limit = (int)($_GET['limit'] ?? 25);
$offset = (int)($_GET['offset'] ?? 0);
$forceRefresh = !empty($_GET['force_refresh']) && $_GET['force_refresh'] !== '0';

if ($limit <= 0) {
    $limit = 25;
}
if ($limit > 200) {
    $limit = 200;
}
if ($offset < 0) {
    $offset = 0;
}

if ($username === '' || trim($password) === '') {
    echo json_encode([
        'ok' => false,
        'error' => "Non connecté à la messagerie.",
        'emails' => [],
        'unreadCount' => 0,
    ]);
    exit;
}

$criteria = ($tab === 'unread') ? 'UNSEEN' : 'ALL';

$fetcher = new \EmailFetcher(IMAP_HOSTNAME, $username, $password);
if (!$fetcher->isConnected()) {
    echo json_encode([
        'ok' => false,
        'error' => $fetcher->getLastError() ?: 'Connexion IMAP impossible.',
        'emails' => [],
        'unreadCount' => 0,
    ]);
    $fetcher->closeConnection();
    exit;
}

// Persister une fois par session après une connexion IMAP réussie.
$emailController->persistMailboxPasswordIfNeeded($userId);

if ($folder !== '') {
    if (!$fetcher->reopenMailbox($folder)) {
        $err = $fetcher->getLastError() ?: 'Impossible d\'ouvrir le dossier.';
        $fetcher->closeConnection();
        echo json_encode([
            'ok' => false,
            'error' => $err,
            'emails' => [],
            'unreadCount' => 0,
        ]);
        exit;
    }
}

$unreadCount = (int)$fetcher->countUnreadEmails();
$cacheManager = $emailController->cache();
$cacheTtl = $cacheManager->getReadCacheTtlSeconds();
$unreadCacheTtl = $cacheManager->getUnreadCacheTtlSeconds();

if ($forceRefresh) {
    $cacheManager->clearMessageCache($userId, $folder);
}

$emails = $fetcher->listEmails($criteria, $limit, $offset, $cacheManager, $userId, $folder);
$fetcher->closeConnection();
$cacheStatus = $cacheManager->getMessageCacheStatus($userId, $folder);

// Cache session (pour la navbar) — évite de refaire un IMAP search à chaque page.
$_SESSION['mailbox_unread_cache_count'] = $unreadCount;
$_SESSION['mailbox_unread_cache_ts'] = time();

echo json_encode([
    'ok' => true,
    'error' => null,
    'emails' => $emails,
    'unreadCount' => $unreadCount,
    'folder' => $folder,
    'offset' => $offset,
    'limit' => $limit,
    'cacheTtlSeconds' => $cacheTtl,
    'unreadCacheTtlSeconds' => $unreadCacheTtl,
    'cacheStatus' => $cacheStatus,
    'forceRefresh' => $forceRefresh,
]);




