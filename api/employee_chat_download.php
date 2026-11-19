<?php
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/EmployeeChat.php';

try {
    $attachmentId = (int) ($_GET['id'] ?? 0);
    $scope = (string) ($_GET['scope'] ?? 'peer');
    $currentUserId = (int) ($_SESSION['user_id'] ?? 0);
    session_write_close();
    if ($attachmentId <= 0 || $currentUserId <= 0) {
        throw new RuntimeException('Piece jointe introuvable.');
    }
    $pdo = \Database::getConnection();

    $row = \EmployeeChat::getAttachmentForUser($pdo, $currentUserId, $scope, $attachmentId);
    if (!$row) {
        throw new RuntimeException('Piece jointe introuvable.');
    }

    $storedPath = trim((string) ($row['stored_path'] ?? ''));
    $absolutePath = realpath(__DIR__ . '/../storage/' . ltrim($storedPath, '/'));
    $storageRoot = realpath(__DIR__ . '/../storage');
    if (!$absolutePath || !$storageRoot || strpos(str_replace('\\', '/', $absolutePath), str_replace('\\', '/', $storageRoot)) !== 0 || !is_file($absolutePath)) {
        throw new RuntimeException('Fichier introuvable sur le serveur.');
    }

    header('Content-Type: ' . ((string) ($row['mime_type'] ?? '') !== '' ? (string) $row['mime_type'] : 'application/octet-stream'));
    header('Content-Length: ' . (string) filesize($absolutePath));
    header('Content-Disposition: inline; filename="' . rawurlencode((string) ($row['original_name'] ?? 'piece_jointe')) . '"');
    readfile($absolutePath);
    exit;
} catch (Throwable $e) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo $e->getMessage();
}
