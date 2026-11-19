<?php

require_once __DIR__ . '/_bootstrap.php';

try {
    $period = isset($_GET['period']) ? (string) $_GET['period'] : 'monthly';
    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 20;

    $pdo = \Database::getConnection();
    $repo = new \GamificationRepository($pdo);
    $repo->ensureSchema();

    $leaderboard = $repo->getLeaderboard($period, $limit);

    echo json_encode([
        'ok' => true,
        'period' => $period,
        'leaderboard' => $leaderboard,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'leaderboard' => [], 'error' => 'Erreur interne'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
