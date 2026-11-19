<?php
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/Communication.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = \Database::getConnection();
    \Communication::ensureTables($pdo);

    $userId = (int)($_SESSION['user_id'] ?? 0);
    $meDisplay = trim((string)($_SESSION['user_prenom'] ?? '') . ' ' . (string)($_SESSION['user_nom'] ?? ''));
    if ($meDisplay === '') {
        $meDisplay = (string)($_SESSION['mail_pro'] ?? ('Utilisateur #' . $userId));
    }

    echo json_encode([
        'ok' => true,
        'me' => [
            'id' => $userId,
            'display' => $meDisplay,
        ],
        'users' => \Communication::listUsers($pdo),
        'dashboard' => \Communication::getTaskDashboard($pdo, $userId),
        'statuses' => \Communication::getStatuses(),
        'priorities' => \Communication::getPriorities(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
