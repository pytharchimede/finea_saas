<?php

require_once __DIR__ . '/_bootstrap.php';

try {
    $pdo = \Database::getConnection();
    $repo = new \GamificationRepository($pdo);
    $repo->ensureSchema();

    $challenges = $repo->listActiveChallenges();

    echo json_encode(['ok' => true, 'challenges' => $challenges], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'challenges' => [], 'error' => 'Erreur interne'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
