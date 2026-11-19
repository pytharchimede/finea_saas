<?php
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/EmployeeChat.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $currentUserId = (int) ($_SESSION['user_id'] ?? 0);
    session_write_close();
    $groupId = (int) ($_GET['group_id'] ?? $_GET['thread_id'] ?? 0);
    if ($groupId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Groupe invalide.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    $pdo = \Database::getConnection();

    $members = \EmployeeChat::listGroupMembers($pdo, $currentUserId, $groupId);

    echo json_encode([
        'ok' => true,
        'members' => $members,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
