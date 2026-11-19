<?php
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/Communication.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = \Database::getConnection();
    \Communication::ensureTables($pdo);

    echo json_encode([
        'ok' => true,
        'attachments' => \Communication::listAttachments($pdo, [
            'search' => $_GET['search'] ?? '',
            'task_id' => $_GET['task_id'] ?? 0,
            'assignee_user_id' => $_GET['assignee_user_id'] ?? 0,
            'limit' => $_GET['limit'] ?? 150,
        ]),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
