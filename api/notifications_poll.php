<?php

require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../controllers/NotificationController.php';

header('Content-Type: application/json; charset=utf-8');

function notifications_poll_should_log(string $message, int $windowSeconds = 300): bool
{
    $path = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'finea_notifications_poll_'.hash('sha256', $message).'.log';
    $lastLoggedAt = @filemtime($path);
    if ($lastLoggedAt !== false && (time() - $lastLoggedAt) < $windowSeconds) {
        return false;
    }
    return @touch($path) || $lastLoggedAt === false;
}

try {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
    $teams = \NotificationService::inferUserTeamsFromSession();
    session_write_close();
    if ($userId <= 0) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'unauthorized']);
        exit;
    }

    $sinceId = isset($_GET['since_id']) ? (int) $_GET['since_id'] : null;
    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 10;

    $pdo = \Database::getConnection();
    $controller = new \NotificationController($pdo);
    $result = $controller->poll($userId, $teams, $limit, $sinceId);

    echo json_encode([
        'ok' => true,
        'server_time' => date('c'),
        'unread_count' => $result['unread_count'],
        'latest_id' => $result['latest_id'],
        'notifications' => $result['notifications'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    if (notifications_poll_should_log($e->getMessage())) {
        \AuditTrail::log('notifications_poll_failed', [
            'module' => 'api',
            'action' => 'notifications_poll',
            'entity_type' => 'notification',
            'result' => 'failure',
            'severity' => 'warning',
            'details' => ['error' => $e->getMessage()],
        ]);
    }
    echo json_encode(['ok' => false, 'error' => 'server_error']);
}
