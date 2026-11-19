<?php
require_once dirname(__DIR__) . '/auth_check.php';
require_once dirname(__DIR__) . '/model/Database.php';
require_once dirname(__DIR__) . '/model/DocumentManager.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new RuntimeException('Methode non autorisee.');
    }

    $permissions = is_array($_SESSION['permissions'] ?? null) ? $_SESSION['permissions'] : [];
    $userId = (int)($_SESSION['user_id'] ?? 0);
    $entityType = trim((string)($_GET['entity_type'] ?? 'document'));
    $entityId = (int)($_GET['entity_id'] ?? 0);
    $serviceId = trim((string)($_GET['service_id'] ?? '')) === '' ? null : (int)$_GET['service_id'];
    $targetUserId = trim((string)($_GET['user_id'] ?? '')) === '' ? null : (int)$_GET['user_id'];
    if (!in_array($entityType, ['document', 'folder'], true)) {
        throw new RuntimeException('Type invalide.');
    }

    $pdo = \Database::getConnection();
    $manager = new \DocumentManager($pdo);
    $manager->ensureTables();

    if ($entityId > 0) {
        if ($entityType === 'folder') {
            $entity = $manager->assertFolderAccess($entityId, 'view', $userId, $permissions);
        } else {
            $entity = $manager->assertDocumentAccess($entityId, 'view', $userId, $permissions);
        }
        if ($serviceId === null) {
            $serviceId = isset($entity['service_id']) ? (int)$entity['service_id'] : null;
        }
    }

    echo json_encode([
        'success' => true,
        'users' => $manager->listEffectiveAccessUsers($entityType, $entityId, $serviceId, $targetUserId),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
