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
    $canUpload = !empty($permissions['document_upload']) || !empty($permissions['document_admin']) || !empty($permissions['acces_admin']);
    if (!$canUpload) {
        throw new RuntimeException('Vous n\'avez pas le droit d\'importer des documents.');
    }

    if (!isset($_FILES['document_file']) || !is_array($_FILES['document_file'])) {
        throw new RuntimeException('Aucun fichier reçu.');
    }

    $pdo = \Database::getConnection();
    $manager = new \DocumentManager($pdo);
    $manager->ensureTables();

    $userId = (int)($_SESSION['user_id'] ?? 0);
    $userContext = $manager->getUserDocumentationContext($userId, $permissions);
    $baseFolderId = trim((string)($_POST['folder_id'] ?? '')) === '' ? null : (int)$_POST['folder_id'];
    $relativePath = trim(str_replace('\\', '/', (string)($_POST['relative_path'] ?? '')));

    $payload = [
        'folder_id' => $baseFolderId,
        'title' => trim((string)($_POST['title'] ?? '')),
        'overwrite' => !empty($_POST['overwrite']) ? 1 : 0,
        'document_type' => trim((string)($_POST['document_type'] ?? 'document')),
        'space_type' => trim((string)($_POST['space_type'] ?? 'general')),
        'required_permission' => trim((string)($_POST['required_permission'] ?? '')),
        'chantier_id' => trim((string)($_POST['chantier_id'] ?? '')),
        'service_id' => trim((string)($_POST['service_id'] ?? '')),
        'direction_code' => trim((string)($_POST['direction_code'] ?? '')),
        'description' => trim((string)($_POST['description'] ?? '')),
    ];

    if ($baseFolderId !== null) {
        $manager->assertFolderAccess($baseFolderId, 'edit', $userId, $permissions);
    } elseif (empty($userContext['is_global_admin']) && empty($userContext['is_document_admin'])) {
        $targetServiceId = (int)($payload['service_id'] ?? 0);
        $userServiceId = (int)($userContext['service_id'] ?? 0);
        if ($targetServiceId > 0 && $targetServiceId !== $userServiceId) {
            throw new RuntimeException('Vous ne pouvez pas importer dans le service d\'un autre utilisateur.');
        }
    }

    if ($relativePath !== '') {
        $parts = array_values(array_filter(explode('/', dirname($relativePath)), static fn(string $part): bool => $part !== '.' && $part !== ''));
        if ($parts) {
            $payload['folder_id'] = $manager->ensureFolderPath($parts, $baseFolderId, $payload, $userId);
        }
        if ($payload['title'] === '') {
            $payload['title'] = pathinfo(basename($relativePath), PATHINFO_FILENAME);
        }
    }

    $documentId = $manager->uploadDocument($payload, $_FILES['document_file'], $userId);
    $document = $manager->getDocumentById($documentId) ?: ['id' => $documentId, 'title' => $payload['title'] ?: ($_FILES['document_file']['name'] ?? 'Document')];
    $manager->logDocumentAccess('edit', $document, $userId, 'success', ['operation' => 'upload']);

    echo json_encode([
        'success' => true,
        'documentId' => $documentId,
        'message' => 'Document importé avec succès.',
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code((int)$e->getCode() === 409 ? 409 : 400);
    echo json_encode([
        'success' => false,
        'conflict' => (int)$e->getCode() === 409,
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
