<?php
require_once dirname(__DIR__) . '/auth_check.php';
require_once dirname(__DIR__) . '/model/Database.php';
require_once dirname(__DIR__) . '/model/DocumentManager.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new RuntimeException('Methode non autorisee.');
    }

    $documentId = (int)($_GET['document_id'] ?? 0);
    if ($documentId <= 0) {
        throw new RuntimeException('Document introuvable.');
    }

    $permissions = is_array($_SESSION['permissions'] ?? null) ? $_SESSION['permissions'] : [];
    $pdo = \Database::getConnection();
    $manager = new \DocumentManager($pdo);
    $manager->ensureTables();
    $userId = (int)($_SESSION['user_id'] ?? 0);

    $document = $manager->getDocumentById($documentId);
    if (!$document) {
        throw new RuntimeException('Document introuvable.');
    }
    if (!$manager->canAccessDocument($document, 'view', $userId, $permissions)) {
        $manager->logDocumentAccess('view', $document, $userId, 'denied', ['source' => 'history']);
        throw new RuntimeException('Acces refuse a cet historique.');
    }
    $manager->logDocumentAccess('view', $document, $userId, 'success', ['source' => 'history']);

    $shares = $manager->listDocumentShares($documentId);
    $activity = $manager->listDocumentShareActivity($documentId, 30);

    echo json_encode([
        'success' => true,
        'shares' => array_map(static function (array $row): array {
            return [
                'id' => (int)($row['id'] ?? 0),
                'token_preview' => substr((string)($row['token'] ?? ''), 0, 10),
                'recipient_name' => (string)($row['recipient_name'] ?? ''),
                'recipient_email' => (string)($row['recipient_email'] ?? ''),
                'creator_name' => (string)($row['creator_name'] ?? ''),
                'allow_download' => !empty($row['allow_download']),
                'can_edit' => !empty($row['can_edit']),
                'can_reshare' => !empty($row['can_reshare']),
                'expires_at' => (string)($row['expires_at'] ?? ''),
                'created_at' => (string)($row['created_at'] ?? ''),
                'access_count' => (int)($row['access_count'] ?? 0),
                'revoked' => !empty($row['revoked_at']),
            ];
        }, $shares),
        'activity' => array_map(static function (array $row): array {
            return [
                'id' => (int)($row['id'] ?? 0),
                'event_type' => (string)($row['event_type'] ?? ''),
                'action' => (string)($row['action'] ?? ''),
                'created_at' => (string)($row['created_at'] ?? ''),
                'user_name' => (string)($row['user_name'] ?? ''),
                'user_email' => (string)($row['user_email'] ?? ''),
                'result' => (string)($row['result'] ?? ''),
                'details' => is_array($row['details'] ?? null) ? $row['details'] : [],
            ];
        }, $activity),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
