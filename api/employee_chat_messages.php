<?php
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/EmployeeChat.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $currentUserId = (int) ($_SESSION['user_id'] ?? 0);
    session_write_close();
    $threadType = (string) ($_GET['thread_type'] ?? 'peer');
    $threadId = $threadType === 'group'
        ? (int) ($_GET['group_id'] ?? $_GET['thread_id'] ?? 0)
        : (int) ($_GET['peer_user_id'] ?? $_GET['thread_id'] ?? 0);
    $afterId = isset($_GET['after_id']) ? (int) $_GET['after_id'] : null;
    $beforeId = isset($_GET['before_id']) ? (int) $_GET['before_id'] : null;
    $limit = (int) ($_GET['limit'] ?? 60);
    $pdo = \Database::getConnection();

    $thread = \EmployeeChat::listMessages($pdo, $currentUserId, $threadType, $threadId, $limit, $beforeId, $afterId);
    $messages = $thread['messages'] ?? [];
    $lastSeenMessageId = 0;
    if ($messages) {
        $lastSeenMessageId = (int) end($messages)['id'];
    }
    \EmployeeChat::markThreadRead($pdo, $currentUserId, $threadType, $threadId, $lastSeenMessageId);

    echo json_encode([
        'ok' => true,
        'messages' => $messages,
        'has_more' => !empty($thread['has_more']),
        'unread_total' => \EmployeeChat::countUnread($pdo, $currentUserId),
        'typing_by_peer' => $threadType === 'peer' ? \EmployeeChat::listTypingPeers($pdo, $currentUserId, [$threadId]) : [],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
