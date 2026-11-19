<?php
require_once dirname(__DIR__) . '/auth_check.php';
require_once dirname(__DIR__) . '/model/Database.php';
require_once dirname(__DIR__) . '/model/AuditTrail.php';
require_once dirname(__DIR__) . '/model/DocumentManager.php';
require_once dirname(__DIR__) . '/model/SimpleZipWriter.php';

$permissions = is_array($_SESSION['permissions'] ?? null) ? $_SESSION['permissions'] : [];
$canDownload = !empty($permissions['document_download']) || !empty($permissions['document_export']) || !empty($permissions['document_admin']) || !empty($permissions['acces_admin']);
if (!$canDownload) {
    http_response_code(403);
    echo 'Le téléchargement multiple n\'est pas autorisé.';
    exit;
}

$documentIds = array_values(array_filter(array_map('intval', (array)($_POST['document_ids'] ?? [])), static fn(int $id): bool => $id > 0));
$folderIds = array_values(array_filter(array_map('intval', (array)($_POST['folder_ids'] ?? [])), static fn(int $id): bool => $id > 0));
if (!$documentIds && !$folderIds) {
    http_response_code(400);
    echo 'Aucun élément sélectionné.';
    exit;
}

$pdo = \Database::getConnection();
$manager = new \DocumentManager($pdo);
$manager->ensureTables();
$userId = (int)($_SESSION['user_id'] ?? 0);

$tempZip = tempnam(sys_get_temp_dir(), 'docsel_');
if ($tempZip === false) {
    http_response_code(500);
    echo 'Impossible de préparer l\'archive.';
    exit;
}

$zipPath = $tempZip . '.zip';
@rename($tempZip, $zipPath);
if (class_exists('ZipArchive')) {
    $zip = new ZipArchive();
    $zipOpened = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true;
} else {
    try {
        $zip = new \SimpleZipWriter($zipPath);
        $zipOpened = true;
    } catch (Throwable $e) {
        $zipOpened = false;
    }
}
if (!$zipOpened) {
    @unlink($zipPath);
    http_response_code(500);
    echo 'Impossible de créer l\'archive ZIP.';
    exit;
}

$addedEntries = [];
$addDocument = static function (array $document, string $prefix = '') use (&$zip, &$addedEntries): void {
    $absolutePath = trim((string)($document['absolute_path'] ?? ''));
    $originalName = trim((string)($document['original_name'] ?? $document['title'] ?? 'document'));
    if ($absolutePath === '' || !is_file($absolutePath) || $originalName === '') {
        return;
    }

    $entryName = ltrim(trim($prefix, '/') . '/' . $originalName, '/');
    if (isset($addedEntries[$entryName])) {
        $info = pathinfo($entryName);
        $dir = isset($info['dirname']) && $info['dirname'] !== '.' ? $info['dirname'] . '/' : '';
        $base = (string)($info['filename'] ?? 'document');
        $ext = isset($info['extension']) && $info['extension'] !== '' ? '.' . $info['extension'] : '';
        $suffix = 2;
        do {
            $candidate = $dir . $base . ' (' . $suffix . ')' . $ext;
            $suffix++;
        } while (isset($addedEntries[$candidate]));
        $entryName = $candidate;
    }

    if ($zip->addFile($absolutePath, $entryName)) {
        $addedEntries[$entryName] = true;
    }
};

$collectFolder = function (int $folderId, string $prefix = '') use (&$collectFolder, $manager, $permissions, $addDocument, $userId): void {
    $folder = $manager->getFolderById($folderId);
    if (!$folder || !$manager->canAccessFolder($folder, 'view', $userId, $permissions)) {
        return;
    }

    $folderName = trim((string)($folder['name'] ?? ('Dossier_' . $folderId)));
    $currentPrefix = ltrim(trim($prefix, '/') . '/' . $folderName, '/');

    foreach ($manager->listDocumentsInFolder($folderId, false) as $document) {
        $versionId = (int)($document['version_id'] ?? 0);
        if ($versionId <= 0) {
            continue;
        }
        $downloadable = $manager->getDownloadableVersion($versionId, $permissions, $userId, 'download');
        if (!$downloadable) {
            continue;
        }
        $addDocument($downloadable, $currentPrefix);
    }

    foreach ($manager->listChildFolders($folderId, false) as $childFolder) {
        $collectFolder((int)$childFolder['id'], $currentPrefix);
    }
};

foreach ($documentIds as $documentId) {
    $document = $manager->getDocumentById($documentId);
    if (!$document || !$manager->canAccessDocument($document, 'download', $userId, $permissions)) {
        continue;
    }
    $versionId = (int)($document['current_version_id'] ?? 0);
    if ($versionId <= 0) {
        continue;
    }
    $downloadable = $manager->getDownloadableVersion($versionId, $permissions, $userId, 'download');
    if (!$downloadable) {
        continue;
    }
    $addDocument($downloadable);
}

foreach ($folderIds as $folderId) {
    $collectFolder($folderId);
}

if (!$zip->close()) {
    @unlink($zipPath);
    http_response_code(500);
    echo 'Impossible de finaliser l\'archive ZIP.';
    exit;
}

if (!is_file($zipPath) || filesize($zipPath) <= 0) {
    @unlink($zipPath);
    http_response_code(400);
    echo 'Aucun fichier téléchargeable trouvé dans la sélection.';
    exit;
}

header('Content-Description: File Transfer');
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="selection_documentation_' . date('Ymd_His') . '.zip"');
header('Content-Length: ' . (string)filesize($zipPath));
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');
header('Expires: 0');
\AuditTrail::log('documentation_download', [
    'module' => 'documentation',
    'action' => 'bulk_download',
    'entity_type' => 'doc_selection',
    'entity_id' => implode(',', array_merge($documentIds, $folderIds)),
    'user_id' => $userId ?: null,
    'details' => [
        'document_ids' => $documentIds,
        'folder_ids' => $folderIds,
    ],
]);
readfile($zipPath);
@unlink($zipPath);
exit;
