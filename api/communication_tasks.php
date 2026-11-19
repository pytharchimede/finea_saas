<?php
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/Communication.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = \Database::getConnection();
    \Communication::ensureTables($pdo);

    $result = \Communication::listTasks($pdo, [
        'search' => $_GET['search'] ?? '',
        'status' => $_GET['status'] ?? '',
        'priority' => $_GET['priority'] ?? '',
        'assignee_user_id' => $_GET['assignee_user_id'] ?? 0,
        'viewer_user_id' => $_GET['viewer_user_id'] ?? ($_SESSION['user_id'] ?? 0),
        'limit' => $_GET['limit'] ?? 200,
    ]);

    echo json_encode([
        'ok' => true,
        'tasks' => $result['tasks'],
        'summary' => $result['summary'],
        'statuses' => \Communication::getStatuses(),
        'priorities' => \Communication::getPriorities(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
