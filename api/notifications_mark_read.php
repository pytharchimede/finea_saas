<?php

require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../controllers/NotificationController.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
    if ($userId <= 0) {
        http_response_code(401);
        \AuditTrail::log('notifications_mark_read_failed', [
            'module' => 'api',
            'action' => 'notifications_mark_read',
            'entity_type' => 'notification',
            'result' => 'denied',
            'severity' => 'warning',
            'details' => ['reason' => 'unauthorized'],
        ]);
        echo json_encode(['ok' => false, 'error' => 'unauthorized']);
        exit;
    }

    $pdo = \Database::getConnection();

    $controller = new \NotificationController($pdo);
    $teams = \NotificationService::inferUserTeamsFromSession();

    $raw = file_get_contents('php://input');
    $data = [];
    if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $data = $decoded;
        }
    }

    if (!$data) {
        $data = $_POST;
    }

    $markAll = !empty($data['mark_all']);
    $ids = $data['ids'] ?? [];
    if (!is_array($ids)) {
        $ids = [];
    }
    $result = $controller->markRead($userId, $teams, $ids, $markAll);

    \AuditTrail::log('notifications_marked_read', [
        'module' => 'api',
        'action' => 'notifications_mark_read',
        'entity_type' => 'notification',
        'entity_id' => (string)$userId,
        'entity_label' => $markAll ? 'all_notifications' : 'selected_notifications',
        'user_id' => $userId,
        'details' => [
            'mark_all' => $markAll,
            'affected' => $result['affected'],
            'unread_count' => $result['unread_count'],
        ],
    ], $pdo);

    echo json_encode([
        'ok' => true,
        'affected' => $result['affected'],
        'unread_count' => $result['unread_count'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    \AuditTrail::log('notifications_mark_read_failed', [
        'module' => 'api',
        'action' => 'notifications_mark_read',
        'entity_type' => 'notification',
        'result' => 'failure',
        'severity' => 'warning',
        'details' => ['error' => $e->getMessage()],
    ]);
    echo json_encode(['ok' => false, 'error' => 'server_error']);
}
