<?php

require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/AccessRequestManager.php';
require_once __DIR__ . '/../model/AccessManager.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $requestId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
    if ($requestId <= 0) {
        throw new RuntimeException('Demande invalide');
    }

    $manager = \AccessRequestManager::connect();
    $request = $manager->getRequestForRequester($requestId, (int)($_SESSION['user_id'] ?? 0));
    if (!$request) {
        throw new RuntimeException('Demande introuvable');
    }

    if ((string)$request['status'] === 'granted') {
        \AccessManager::refreshSessionPermissions();
    }

    echo json_encode([
        'ok' => true,
        'request' => $manager->buildRequesterStatusPayload($request),
    ]);
} catch (Throwable $e) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
