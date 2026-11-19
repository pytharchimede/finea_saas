<?php
require_once __DIR__ . '/../auth_check.php';

if (!\AccessManager::hasPermission('acces_carnet_adresse') && !\AccessManager::hasPermission('acces_admin')) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Accès refusé.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/AddressBookDirectory.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        throw new RuntimeException('Méthode non autorisée.');
    }

    $payload = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }

    $contactId = trim((string) ($payload['contact_id'] ?? ''));
    $interactionType = trim((string) ($payload['interaction_type'] ?? 'note'));
    $channel = trim((string) ($payload['channel'] ?? 'manual'));
    $subject = trim((string) ($payload['subject'] ?? ''));
    $note = trim((string) ($payload['note'] ?? ''));
    $metadata = $payload['metadata'] ?? [];
    if (!is_array($metadata)) {
        $metadata = [];
    }

    $pdo = \Database::getConnection();
    $directory = new \AddressBookDirectory($pdo);
    $currentUserId = (int) ($_SESSION['user_id'] ?? 0);

    if (!$directory->addInteraction($contactId, $currentUserId, $interactionType, $channel, $subject, $note, $metadata)) {
        throw new RuntimeException('Impossible d’enregistrer l’interaction.');
    }

    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
