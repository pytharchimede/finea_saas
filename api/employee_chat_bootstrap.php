<?php
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/EmployeeChat.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $openPeerIds = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) ($_GET['open_peer_ids'] ?? ''))), static function (int $value): bool {
        return $value > 0;
    })));

    $currentUserId = (int) ($_SESSION['user_id'] ?? 0);
    $displayName = trim((string) (($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? '')));
    if ($displayName === '') {
        $displayName = trim((string) ($_SESSION['user_name'] ?? ''));
    }
    if ($displayName === '') {
        $displayName = trim((string) ($_SESSION['mail_pro'] ?? 'Utilisateur'));
    }
    session_write_close();

    $pdo = \Database::getConnection();

    $state = \EmployeeChat::getState($pdo, $currentUserId, $openPeerIds);
    $me = \EmployeeChat::getUserIdentity($pdo, $currentUserId);

    echo json_encode([
        'ok' => true,
        'me' => [
            'id' => $currentUserId,
            'display' => $me['display'] !== '' ? $me['display'] : $displayName,
            'initials' => $me['initials'] ?? 'BA',
            'mail_pro' => $me['mail_pro'] ?? '',
            'photo_url' => $me['photo_url'] ?? '',
            'service_id' => $me['service_id'] ?? 0,
            'service_label' => $me['service_label'] ?? '',
        ],
        'state' => $state,
        'poll_interval_ms' => 15000,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
