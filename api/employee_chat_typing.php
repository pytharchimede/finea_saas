<?php
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/EmployeeChat.php';

header('Content-Type: application/json; charset=utf-8');

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Methode non autorisee.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $payload = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }

    $currentUserId = (int) ($_SESSION['user_id'] ?? 0);
    session_write_close();
    $threadType = (string) ($payload['thread_type'] ?? 'peer');
    $peerUserId = (int) ($payload['peer_user_id'] ?? 0);
    $isTyping = !empty($payload['is_typing']);
    $pdo = \Database::getConnection();

    if ($threadType === 'peer') {
        \EmployeeChat::setTypingState($pdo, $currentUserId, $peerUserId, $isTyping);
    }

    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
