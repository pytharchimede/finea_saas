<?php
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/Communication.php';

$attachmentId = (int)($_GET['attachment_id'] ?? 0);
$inline = !empty($_GET['inline']);
if ($attachmentId <= 0) {
    http_response_code(400);
    echo 'Piece jointe invalide.';
    exit;
}

function comm_is_inline_previewable(string $mimeType): bool
{
    $mimeType = strtolower(trim($mimeType));
    if ($mimeType === '') {
        return false;
    }

    return strpos($mimeType, 'image/') === 0
        || strpos($mimeType, 'video/') === 0
        || strpos($mimeType, 'audio/') === 0
        || in_array($mimeType, ['application/pdf', 'text/plain', 'text/csv'], true);
}

try {
    $pdo = \Database::getConnection();
    \Communication::ensureTables($pdo);
    $row = \Communication::getAttachment($attachmentId, $pdo);
    if (!$row) {
        http_response_code(404);
        echo 'Fichier introuvable.';
        exit;
    }

    $baseStorage = realpath(__DIR__ . '/../storage');
    if ($baseStorage === false) {
        http_response_code(500);
        echo 'Stockage indisponible.';
        exit;
    }

    $storedRel = str_replace(['..', '\\'], ['', '/'], (string)($row['stored_path'] ?? ''));
    $absolutePath = realpath($baseStorage . '/' . $storedRel);
    if ($absolutePath === false || strpos($absolutePath, $baseStorage) !== 0 || !is_file($absolutePath)) {
        http_response_code(404);
        echo 'Fichier introuvable.';
        exit;
    }

    $mimeType = (string)($row['mime_type'] ?? 'application/octet-stream');
    $fileName = str_replace('"', '', (string)($row['original_name'] ?? 'piece-jointe'));

    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: ' . ($inline && comm_is_inline_previewable($mimeType) ? 'inline' : 'attachment') . '; filename="' . $fileName . '"');
    header('X-Content-Type-Options: nosniff');
    if (!empty($row['file_size'])) {
        header('Content-Length: ' . (int)$row['file_size']);
    }
    readfile($absolutePath);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Erreur serveur.';
}
