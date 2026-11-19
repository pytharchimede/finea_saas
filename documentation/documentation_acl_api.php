<?php
require_once dirname(__DIR__) . '/auth_check.php';
require_once dirname(__DIR__) . '/model/Database.php';
require_once dirname(__DIR__) . '/model/DocumentManager.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    $permissions = is_array($_SESSION['permissions'] ?? null) ? $_SESSION['permissions'] : [];
    $userId = (int)($_SESSION['user_id'] ?? 0);

    $pdo = \Database::getConnection();
    $manager = new \DocumentManager($pdo);
    $manager->ensureTables();

    $entityType = trim((string)($_REQUEST['entity_type'] ?? 'document'));
    $entityId = (int)($_REQUEST['entity_id'] ?? 0);
    if (!in_array($entityType, ['document', 'folder'], true) || $entityId <= 0) {
        throw new RuntimeException('Element invalide.');
    }

    if ($entityType === 'folder') {
        $entity = $manager->assertFolderAccess($entityId, 'share', $userId, $permissions);
    } else {
        $entity = $manager->assertDocumentAccess($entityId, 'share', $userId, $permissions);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        echo json_encode([
            'success' => true,
            'entries' => $manager->listAclEntries($entityType, $entityId),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Methode non autorisee.');
    }

    $subjectType = trim((string)($_POST['subject_type'] ?? 'service'));
    $subjectId = (int)($_POST['subject_id'] ?? 0);
    if (!in_array($subjectType, ['service', 'user'], true) || $subjectId <= 0) {
        throw new RuntimeException('Sujet ACL invalide.');
    }

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
        $manager->logFolderAccess('share', $entity, $userId, 'success', ['operation' => 'acl_update', 'subject_type' => $subjectType, 'subject_id' => $subjectId, 'rights' => $rights]);
    } else {
        $manager->logDocumentAccess('share', $entity, $userId, 'success', ['operation' => 'acl_update', 'subject_type' => $subjectType, 'subject_id' => $subjectId, 'rights' => $rights]);
    }

    echo json_encode([
        'success' => true,
        'message' => 'Droits enregistres.',
        'entries' => $manager->listAclEntries($entityType, $entityId),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
