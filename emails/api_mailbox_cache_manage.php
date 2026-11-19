<?php
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../controllers/EmailController.php';
\AccessManager::requirePageAccess();

$emailController = new \EmailController();


header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Méthode non autorisée.']);
    exit;
}

$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$action = strtolower(trim((string) ($payload['action'] ?? 'clear')));
$folder = trim((string) ($payload['folder'] ?? ''));
$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($userId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Utilisateur non identifié.']);
    exit;
}

$cacheManager = $emailController->cache();

if (!in_array($action, ['clear', 'resync'], true)) {
    echo json_encode(['ok' => false, 'error' => 'Action de cache invalide.']);
    exit;
}

$cacheManager->clearUserCache($userId, $folder !== '' ? $folder : null);

$messageStatus = $cacheManager->getMessageCacheStatus($userId, $folder !== '' ? $folder : 'INBOX');
$folderStatus = $cacheManager->getFolderCacheStatus($userId, 'imap_folders');

unset($_SESSION['mailbox_unread_cache_count'], $_SESSION['mailbox_unread_cache_ts']);

echo json_encode([
    'ok' => true,
    'action' => $action,
    'folder' => $folder,
    'message' => $action === 'resync'
        ? 'Le cache a été vidé. La prochaine lecture resynchronisera la boîte.'
        : 'Le cache mail a été vidé avec succès.',
    'messageCacheStatus' => $messageStatus,
    'folderCacheStatus' => $folderStatus,
]);




