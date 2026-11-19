<?php
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../controllers/EmailController.php';
AccessManager::requirePageAccess();

$emailController = new \EmailController();

require_once __DIR__ . '/../decaissement/config/mail_config.php';
require_once __DIR__ . '/../decaissement/model/EmailFetcher.php';

header('Content-Type: application/json; charset=UTF-8');

$userId = (int)($_SESSION['user_id'] ?? 0);
$username = trim((string)($_SESSION['mail_pro'] ?? ''));

// Réhydrate le mot de passe depuis la BD si présent (léger) — évite de forcer une reconnexion manuelle.
$emailController->hydrateMailboxPasswordFromStorage($userId);
$password = (string)($_SESSION['personnel_email_password'] ?? '');

// Si pas configuré, on n'essaie surtout pas d'ouvrir IMAP.
if ($username === '' || trim($password) === '') {
    echo json_encode([
        'ok' => true,
        'connected' => false,
        'unreadCount' => 0,
        'cached' => true,
    ]);
    exit;
}

$cacheTtlSeconds = 120;
$now = time();
$cachedAt = (int)($_SESSION['mailbox_unread_cache_ts'] ?? 0);
$cachedCountRaw = $_SESSION['mailbox_unread_cache_count'] ?? null;

if ($cachedAt > 0 && ($now - $cachedAt) < $cacheTtlSeconds && is_numeric($cachedCountRaw)) {
    echo json_encode([
        'ok' => true,
        'connected' => true,
        'unreadCount' => (int)$cachedCountRaw,
        'cached' => true,
        'cacheTtlSeconds' => $cacheTtlSeconds,
    ]);
    exit;
}

// Cache expiré: on fait un appel IMAP (asynchrone côté navigateur) et on re-cache.
$fetcher = new EmailFetcher(IMAP_HOSTNAME, $username, $password);
if (!$fetcher->isConnected()) {
    $err = $fetcher->getLastError() ?: 'Connexion IMAP impossible.';
    $fetcher->closeConnection();

    echo json_encode([
        'ok' => true,
        'connected' => false,
        'unreadCount' => 0,
        'cached' => false,
        'error' => $err,
    ]);
    exit;
}

// Persister une fois par session après une connexion IMAP réussie.
$emailController->persistMailboxPasswordIfNeeded($userId);

$unreadCount = (int)$fetcher->countUnreadEmails();
$fetcher->closeConnection();

$_SESSION['mailbox_unread_cache_count'] = $unreadCount;
$_SESSION['mailbox_unread_cache_ts'] = $now;

echo json_encode([
    'ok' => true,
    'connected' => true,
    'unreadCount' => $unreadCount,
    'cached' => false,
    'cacheTtlSeconds' => $cacheTtlSeconds,
]);




