<?php
require_once dirname(__DIR__) . '/model/Database.php';
require_once dirname(__DIR__) . '/model/AuditTrail.php';
require_once dirname(__DIR__) . '/model/DocumentManager.php';

$token = trim((string)($_GET['t'] ?? ''));
$isShareContext = $token !== '';

if (!$isShareContext) {
    require_once dirname(__DIR__) . '/auth_check.php';
    \AccessManager::requirePageAccess();
}

$versionId = (int)($_GET['version_id'] ?? 0);
if ($versionId <= 0 && !$isShareContext) {
    http_response_code(400);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => false, 'message' => 'Archive introuvable.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$permissions = is_array($_SESSION['permissions'] ?? null) ? $_SESSION['permissions'] : [];
$pdo = \Database::getConnection();
$manager = new \DocumentManager($pdo);
$manager->ensureTables();
$userId = (int)($_SESSION['user_id'] ?? 0);

$share = null;
$allowDownload = false;

if ($isShareContext) {
    $share = $manager->getShareByToken($token);
    if (!$share) {
        http_response_code(404);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => false, 'message' => 'Lien de partage introuvable ou expiré.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    if (isset($share['can_view']) && empty($share['can_view'])) {
        http_response_code(403);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => false, 'message' => 'La visualisation n’est pas autorisée pour ce lien.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    $file = $share;
    $versionId = (int)($share['version_id'] ?? 0);
    $allowDownload = !empty($share['allow_download']);
} else {
    $file = $manager->getDownloadableVersion($versionId, $permissions, $userId, 'view');
    if (!$file) {
        http_response_code(404);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => false, 'message' => 'Archive introuvable ou accès refusé.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    $allowDownload = $manager->canAccessDocument($file, 'download', $userId, $permissions);
}

$extension = strtolower(trim((string)($file['extension'] ?? pathinfo((string)($file['original_name'] ?? ''), PATHINFO_EXTENSION))));
if ($extension !== 'zip') {
    http_response_code(400);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => false, 'message' => 'Seules les archives ZIP peuvent être explorées depuis l’interface.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$zip = new ZipArchive();
if ($zip->open((string)$file['absolute_path']) !== true) {
    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => false, 'message' => 'Impossible d’ouvrir cette archive ZIP.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$action = trim((string)($_GET['action'] ?? 'list'));

if ($action === 'download_entry') {
    if (!$allowDownload) {
        if ($share) {
            \AuditTrail::log('documentation_share_download_denied', [
                'module' => 'documentation',
                'action' => 'share_archive_entry_download_denied',
                'entity_type' => 'doc_document',
                'entity_id' => (string)($share['document_id'] ?? 0),
                'entity_label' => (string)($share['title'] ?? $share['original_name'] ?? 'Archive ZIP'),
                'details' => [
                    'token' => $token,
                    'entry' => (int)($_GET['entry'] ?? -1),
                ],
            ]);
        }
        http_response_code(403);
        echo 'Le téléchargement n\'est pas autorisé pour ce lien.';
        exit;
    }

    $entryIndex = isset($_GET['entry']) ? (int)$_GET['entry'] : -1;
    $entryStat = $entryIndex >= 0 ? $zip->statIndex($entryIndex) : false;
    if (!$entryStat || empty($entryStat['name'])) {
        $zip->close();
        http_response_code(404);
        echo 'Entrée introuvable.';
        exit;
    }

    $entryName = (string)$entryStat['name'];
    if (str_ends_with($entryName, '/')) {
        $zip->close();
        http_response_code(400);
        echo 'Ce dossier ne peut pas être téléchargé directement.';
        exit;
    }

    $stream = $zip->getStream($entryName);
    if (!is_resource($stream)) {
        $zip->close();
        http_response_code(500);
        echo 'Impossible de lire cette entrée.';
        exit;
    }

    $downloadName = basename($entryName);
    $entryExtension = strtolower((string)pathinfo($downloadName, PATHINFO_EXTENSION));
    $mimeType = match ($entryExtension) {
        'pdf' => 'application/pdf',
        'jpg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'txt', 'log' => 'text/plain; charset=UTF-8',
        'csv' => 'text/csv; charset=UTF-8',
        'json' => 'application/json',
        'xml' => 'application/xml',
        'zip' => 'application/zip',
        'rar' => 'application/vnd.rar',
        '7z' => 'application/x-7z-compressed',
        default => 'application/octet-stream',
    };

    header('Content-Description: File Transfer');
    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: attachment; filename="' . rawurlencode($downloadName) . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
    if (isset($entryStat['size'])) {
        header('Content-Length: ' . (string)$entryStat['size']);
    }
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    header('Expires: 0');
    header('X-Content-Type-Options: nosniff');

    if ($share) {
        \AuditTrail::log('documentation_share_download', [
            'module' => 'documentation',
            'action' => 'share_archive_entry_download',
            'entity_type' => 'doc_document',
            'entity_id' => (string)($share['document_id'] ?? 0),
            'entity_label' => (string)($share['title'] ?? $share['original_name'] ?? $downloadName),
            'details' => [
                'token' => $token,
                'entry_name' => $entryName,
            ],
        ]);
    } else {
        $manager->logDocumentAccess('download', $file, $userId, 'success', [
            'source' => 'archive_entry',
            'entry_name' => $entryName,
        ]);
    }

    while (!feof($stream)) {
        echo fread($stream, 8192);
    }

    fclose($stream);
    $zip->close();
    exit;
}

$entries = [];
for ($index = 0; $index < $zip->numFiles; $index += 1) {
    $entryStat = $zip->statIndex($index);
    if (!$entryStat || empty($entryStat['name'])) {
        continue;
    }

    $entryName = (string)$entryStat['name'];
    $isDirectory = str_ends_with($entryName, '/');
    $entries[] = [
        'index' => $index,
        'name' => $entryName,
        'basename' => basename(rtrim($entryName, '/')),
        'isDirectory' => $isDirectory,
        'size' => (int)($entryStat['size'] ?? 0),
        'downloadUrl' => $isDirectory ? '' : ('documentation_archive_api.php?' . http_build_query($isShareContext
            ? ['t' => $token, 'action' => 'download_entry', 'entry' => $index]
            : ['version_id' => $versionId, 'action' => 'download_entry', 'entry' => $index])),
    ];
}

$zip->close();

header('Content-Type: application/json; charset=UTF-8');
if (!$share) {
    $manager->logDocumentAccess('view', $file, $userId, 'success', ['source' => 'archive_list', 'version_id' => $versionId]);
}
echo json_encode([
    'success' => true,
    'archiveName' => (string)($file['original_name'] ?? ('archive_' . $versionId . '.zip')),
    'allowDownload' => $allowDownload,
    'entries' => $entries,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;
