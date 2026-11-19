<?php

require_once __DIR__ . '/_bootstrap.php';

try {
    $userId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : gam_current_user_id();

    $currentUserId = gam_current_user_id();
    if ($userId <= 0) {
        echo json_encode(['ok' => false, 'challenges' => [], 'error' => 'Utilisateur non authentifié'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit();
    }

    if ($userId !== $currentUserId && !gam_is_admin()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'challenges' => [], 'error' => 'Interdit'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit();
    }

    $pdo = \Database::getConnection();
    $repo = new \GamificationRepository($pdo);
    $repo->ensureSchema();

    $challenges = $repo->listUserChallenges($userId);

    echo json_encode(['ok' => true, 'challenges' => $challenges], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'challenges' => [], 'error' => 'Erreur interne'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
