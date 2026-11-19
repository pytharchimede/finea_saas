<?php
require_once dirname(__DIR__) . '/auth_check.php';
\AccessManager::requirePageAccess();

require_once dirname(__DIR__) . '/model/Database.php';
require_once dirname(__DIR__) . '/model/AuditTrail.php';
require_once dirname(__DIR__) . '/model/DocumentManager.php';

$versionId = (int)($_GET['version_id'] ?? 0);
if ($versionId <= 0) {
    http_response_code(404);
    echo 'Document introuvable.';
    exit;
}

$pdo = \Database::getConnection();
$manager = new \DocumentManager($pdo);
$manager->ensureTables();
$permissions = is_array($_SESSION['permissions'] ?? null) ? $_SESSION['permissions'] : [];
$userId = (int)($_SESSION['user_id'] ?? 0);

$inline = !empty($_GET['inline']);
$file = $manager->getDownloadableVersion($versionId, $permissions, $userId, 'view');
if (!$file) {
    \AuditTrail::log('documentation_download_denied', [
        'module' => 'documentation',
        'action' => 'download_document',
        'entity_type' => 'doc_document_version',
        'entity_id' => (string)$versionId,
    ]);
    http_response_code(404);
    echo 'Document introuvable ou acces refuse.';
    exit;
}

$absolutePath = (string)$file['absolute_path'];
$downloadName = (string)($file['original_name'] ?? ('document_' . $versionId));
$mimeType = trim((string)($file['mime_type'] ?? 'application/octet-stream'));
$extension = strtolower(trim((string)($file['extension'] ?? pathinfo($downloadName, PATHINFO_EXTENSION))));
if ($mimeType === '') {
    $mimeType = 'application/octet-stream';
}

if (in_array($extension, ['zip', 'rar', '7z', 'mp4', 'webm', 'mov', 'avi', 'mkv', 'm4v', 'ogv', 'exe', 'msi', 'bat', 'cmd', 'ps1'], true)) {
    $mimeType = match ($extension) {
        'zip' => 'application/zip',
        'rar' => 'application/vnd.rar',
        '7z' => 'application/x-7z-compressed',
        'mp4', 'm4v' => 'video/mp4',
        'webm' => 'video/webm',
        'mov' => 'video/quicktime',
        'avi' => 'video/x-msvideo',
        'mkv' => 'video/x-matroska',
        'ogv' => 'video/ogg',
        'exe' => 'application/vnd.microsoft.portable-executable',
        'msi' => 'application/x-msi',
        'bat', 'cmd' => 'application/x-msdos-program',
        'ps1' => 'text/plain',
        default => $mimeType,
    };
}

$archiveDownloadAllowed = in_array($extension, ['zip', 'rar', '7z'], true);
if (!$inline && !$archiveDownloadAllowed && !$manager->canAccessDocument($file, 'download', $userId, $permissions)) {
    \AuditTrail::log('documentation_download_denied', [
        'module' => 'documentation',
        'action' => 'download_document_forbidden',
        'entity_type' => 'doc_document',
        'entity_id' => (string)($file['document_id'] ?? $versionId),
        'entity_label' => (string)($file['title'] ?? $downloadName),
    ]);
    http_response_code(403);
    echo 'Le telechargement n\'est pas autorise pour votre profil.';
    exit;
}

\AuditTrail::log('documentation_download', [
    'module' => 'documentation',
    'action' => 'download_document',
    'entity_type' => 'doc_document',
    'entity_id' => (string)($file['document_id'] ?? $versionId),
    'entity_label' => (string)($file['title'] ?? $downloadName),
]);
$manager->logDocumentAccess($inline ? 'view' : 'download', $file, $userId, 'success', ['version_id' => $versionId, 'inline' => $inline]);

header('Content-Description: File Transfer');
header('Content-Type: ' . $mimeType);
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . rawurlencode($downloadName) . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
header('Content-Length: ' . (string)filesize($absolutePath));
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');
readfile($absolutePath);
exit;
