<?php

require_once __DIR__ . '/_bootstrap.php';

try {
    $userId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : gam_current_user_id();
    $period = isset($_GET['period']) ? (string) $_GET['period'] : 'monthly';

    $currentUserId = gam_current_user_id();
    if ($userId <= 0) {
        echo json_encode(['ok' => false, 'dashboard' => null, 'error' => 'Utilisateur non authentifié'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit();
    }

    if ($userId !== $currentUserId && !gam_is_admin()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'dashboard' => null, 'error' => 'Interdit'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit();
    }

    $service = gam_service();
    $dashboard = $service->getUserDashboard($userId, $period);

    echo json_encode(['ok' => true, 'dashboard' => $dashboard], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'dashboard' => null, 'error' => 'Erreur interne'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
