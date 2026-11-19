<?php

require_once __DIR__ . '/_bootstrap.php';

try {
    $payload = gam_get_json_body();
    $eventKey = (string) ($payload['event'] ?? $payload['event_key'] ?? ($_POST['event'] ?? $_POST['event_key'] ?? ''));
    $requestedUserId = (int) ($payload['user_id'] ?? ($_POST['user_id'] ?? 0));
    $referenceId = isset($payload['reference_id']) ? (int) $payload['reference_id'] : (isset($_POST['reference_id']) ? (int) $_POST['reference_id'] : null);

    $currentUserId = gam_current_user_id();
    $userId = $requestedUserId > 0 ? $requestedUserId : $currentUserId;

    if ($userId <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Utilisateur non authentifié'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit();
    }

    if ($userId !== $currentUserId && !gam_is_admin()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Interdit'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit();
    }

    $service = gam_service();
    $result = $service->trigger($eventKey, $userId, $referenceId);

    echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erreur interne'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
