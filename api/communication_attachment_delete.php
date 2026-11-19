<?php
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/Communication.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = \Database::getConnection();
    \Communication::ensureTables($pdo);

    $userId = (int)($_SESSION['user_id'] ?? 0);
    $attachmentId = (int)($_POST['attachment_id'] ?? 0);
    $canManageAll = !empty($_SESSION['permissions']['acces_admin'] ?? 0);

    echo json_encode(
        \Communication::deleteAttachment($pdo, $attachmentId, $userId, $canManageAll),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
