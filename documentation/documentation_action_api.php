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
    $canArchive = !empty($permissions['document_archive']) || !empty($permissions['document_admin']) || !empty($permissions['acces_admin']);

    $pdo = \Database::getConnection();
    $manager = new \DocumentManager($pdo);
    $manager->ensureTables();
    $userId = (int)($_SESSION['user_id'] ?? 0);
    $userContext = $manager->getUserDocumentationContext($userId, $permissions);
    $canShare = $canEdit || !empty($permissions['document_share']) || !empty($userContext['is_document_admin']) || !empty($userContext['is_global_admin']);

    $action = trim((string)($_POST['action'] ?? ''));
    $documentIds = array_values(array_filter(array_map('intval', (array)($_POST['document_ids'] ?? [])), static fn(int $id): bool => $id > 0));
    $folderIds = array_values(array_filter(array_map('intval', (array)($_POST['folder_ids'] ?? [])), static fn(int $id): bool => $id > 0));

    if ($action === 'save_scope_acl') {
        if (!$canShare) {
            throw new RuntimeException('Vous n\'avez pas le droit de modifier ces droits.');
        }

        $entityType = trim((string)($_POST['entity_type'] ?? 'document'));
        $entityId = (int)($_POST['entity_id'] ?? 0);
        $subjectType = trim((string)($_POST['subject_type'] ?? 'service'));
        $subjectId = (int)($_POST['subject_id'] ?? 0);
        if (!in_array($entityType, ['document', 'folder'], true) || $entityId <= 0) {
            throw new RuntimeException('Element invalide.');
        }
        if (!in_array($subjectType, ['service', 'user'], true) || $subjectId <= 0) {
            throw new RuntimeException('Portee ACL invalide.');
        }

        $entity = $entityType === 'folder'
            ? $manager->assertFolderAccess($entityId, 'share', $userId, $permissions)
            : $manager->assertDocumentAccess($entityId, 'share', $userId, $permissions);

        $rights = [
            'can_view' => !empty($_POST['can_view']),
            'can_edit' => !empty($_POST['can_edit']),
            'can_download' => !empty($_POST['can_download']),
            'can_delete' => !empty($_POST['can_delete']),
            'can_share' => !empty($_POST['can_share']),
            'can_archive' => !empty($_POST['can_archive']),
        ];
        if (!$rights['can_view']) {
            $rights = array_map(static fn(): bool => false, $rights);
        }

        $manager->upsertAcl($entityType, $entityId, $subjectType, $subjectId, $rights, $userId);
        if ($entityType === 'folder' && $subjectType === 'service' && $rights['can_view']) {
            $previousServiceId = (int)($entity['service_id'] ?? 0);
            if ($previousServiceId > 0 && $previousServiceId !== $subjectId) {
                $manager->upsertAcl($entityType, $entityId, 'service', $previousServiceId, [
                    'can_view' => false,
                    'can_edit' => false,
                    'can_download' => false,
                    'can_delete' => false,
                    'can_share' => false,
                    'can_archive' => false,
                ], $userId);
            }
            $manager->updateFolderService($entityId, $subjectId);
            $entity['service_id'] = $subjectId;
        }

        if ($entityType === 'folder') {
            $manager->logFolderAccess('share', $entity, $userId, 'success', ['operation' => 'acl_scope_update', 'subject_type' => $subjectType, 'subject_id' => $subjectId, 'rights' => $rights]);
        } else {
            $manager->logDocumentAccess('share', $entity, $userId, 'success', ['operation' => 'acl_scope_update', 'subject_type' => $subjectType, 'subject_id' => $subjectId, 'rights' => $rights]);
        }

        echo json_encode([
            'success' => true,
            'message' => 'Droits enregistres.',
            'entries' => $manager->listAclEntries($entityType, $entityId),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'move_selection') {
        if (!$canEdit) {
            throw new RuntimeException('Vous n\'avez pas le droit de déplacer des éléments.');
        }
        $targetFolderId = trim((string)($_POST['target_folder_id'] ?? '')) === '' ? null : (int)$_POST['target_folder_id'];
        $overwrite = !empty($_POST['overwrite']);

        foreach ($folderIds as $folderId) {
            $manager->assertFolderAccess($folderId, 'edit', $userId, $permissions);
            $manager->moveFolder($folderId, $targetFolderId);
        }
        foreach ($documentIds as $documentId) {
            $manager->assertDocumentAccess($documentId, 'edit', $userId, $permissions);
            $manager->moveDocument($documentId, $targetFolderId, $overwrite);
        }

        echo json_encode([
            'success' => true,
            'message' => 'Sélection déplacée avec succès.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'delete_selection') {
        if (!$canArchive) {
            throw new RuntimeException('Vous n\'avez pas le droit de supprimer des éléments.');
        }

        foreach ($documentIds as $documentId) {
            $document = $manager->assertDocumentAccess($documentId, 'delete', $userId, $permissions);
            $manager->deleteDocument($documentId);
            $manager->logDocumentAccess('delete', $document, $userId);
        }
        foreach ($folderIds as $folderId) {
            $folder = $manager->assertFolderAccess($folderId, 'delete', $userId, $permissions);
            $manager->deleteFolder($folderId);
            $manager->logFolderAccess('delete', $folder, $userId);
        }

        echo json_encode([
            'success' => true,
            'message' => 'Sélection supprimée définitivement.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'archive_selection') {
        if (!$canArchive) {
            throw new RuntimeException('Vous n\'avez pas le droit de gérer les archives.');
        }

        $archive = !empty($_POST['archive']);
        foreach ($folderIds as $folderId) {
            $folder = $manager->assertFolderAccess($folderId, 'archive', $userId, $permissions);
            $manager->setFolderArchived($folderId, $archive, $userId);
            $manager->logFolderAccess('archive', $folder, $userId, 'success', ['archive' => $archive]);
        }
        foreach ($documentIds as $documentId) {
            $document = $manager->assertDocumentAccess($documentId, 'archive', $userId, $permissions);
            $manager->setDocumentArchived($documentId, $archive, $userId);
            $manager->logDocumentAccess('archive', $document, $userId, 'success', ['archive' => $archive]);
        }

        echo json_encode([
            'success' => true,
            'message' => $archive ? 'Sélection archivée avec succès.' : 'Sélection restaurée avec succès.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    throw new RuntimeException('Action inconnue.');
} catch (Throwable $e) {
    http_response_code((int)$e->getCode() === 409 ? 409 : 400);
    echo json_encode([
        'success' => false,
        'conflict' => (int)$e->getCode() === 409,
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
