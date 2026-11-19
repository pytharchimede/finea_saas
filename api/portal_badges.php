<?php

require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/PortalBadgeManager.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    $pdo = \Database::getConnection();
    $currentUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    $permissions = is_array($_SESSION['permissions'] ?? null) ? $_SESSION['permissions'] : [];
    $badgeManager = new \PortalBadgeManager($pdo, $currentUserId, $permissions, $_SESSION);
    $scope = (string) ($_GET['scope'] ?? \PortalBadgeManager::SCOPE_ALL);
    $includeDebug = !empty($_GET['debug']) && !empty($permissions['acces_admin']);

    $payload = [
        'ok' => true,
        'badges' => $badgeManager->getBadges($scope),
    ];

    if ($includeDebug) {
        $payload['debug'] = $badgeManager->getDebugSnapshot($scope);
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'badges' => [],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
