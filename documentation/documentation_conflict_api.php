<?php
require_once dirname(__DIR__) . '/auth_check.php';
require_once dirname(__DIR__) . '/model/Database.php';
require_once dirname(__DIR__) . '/model/DocumentManager.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Méthode non autorisée.');
    }

    $permissions = is_array($_SESSION['permissions'] ?? null) ? $_SESSION['permissions'] : [];
    $canEdit = !empty($permissions['document_edit']) || !empty($permissions['document_admin']) || !empty($permissions['acces_admin']);
    $canUpload = !empty($permissions['document_upload']) || !empty($permissions['document_admin']) || !empty($permissions['acces_admin']);

    $pdo = \Database::getConnection();
    $manager = new \DocumentManager($pdo);
    $manager->ensureTables();
    $userId = (int)($_SESSION['user_id'] ?? 0);

    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'check_move_conflicts') {
        if (!$canEdit) {
            throw new RuntimeException('Vous n\'avez pas le droit de déplacer des éléments.');
        }
        $documentIds = array_values(array_filter(array_map('intval', (array)($_POST['document_ids'] ?? [])), static fn(int $id): bool => $id > 0));
        $folderIds = array_values(array_filter(array_map('intval', (array)($_POST['folder_ids'] ?? [])), static fn(int $id): bool => $id > 0));
        $targetFolderId = trim((string)($_POST['target_folder_id'] ?? '')) === '' ? null : (int)$_POST['target_folder_id'];
        foreach ($documentIds as $documentId) {
            $manager->assertDocumentAccess($documentId, 'edit', $userId, $permissions);
        }
        foreach ($folderIds as $folderId) {
            $manager->assertFolderAccess($folderId, 'edit', $userId, $permissions);
        }
        if ($targetFolderId !== null) {
            $manager->assertFolderAccess($targetFolderId, 'view', $userId, $permissions);
        }
        $conflicts = $manager->getMoveConflicts($documentIds, $folderIds, $targetFolderId);
        echo json_encode([
            'success' => true,
            'hasConflict' => !empty($conflicts),
            'conflicts' => $conflicts,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'check_upload_conflict') {
        if (!$canUpload) {
            throw new RuntimeException('Vous n\'avez pas le droit d\'importer des documents.');
        }
        $folderId = trim((string)($_POST['folder_id'] ?? '')) === '' ? null : (int)$_POST['folder_id'];
        if ($folderId !== null) {
            $manager->assertFolderAccess($folderId, 'edit', $userId, $permissions);
        }
        $originalName = trim((string)($_POST['original_name'] ?? ''));
        $title = trim((string)($_POST['title'] ?? ''));
        $conflict = $manager->getUploadConflict($folderId, $originalName, $title);
        echo json_encode([
            'success' => true,
            'hasConflict' => $conflict !== null,
            'conflict' => $conflict,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    throw new RuntimeException('Action inconnue.');
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
