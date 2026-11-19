<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../service/TransportProviderService.php';

try {
    $svc = new TransportProviderService();
    $list = $svc->listProviders();
    echo json_encode(['ok' => true, 'providers' => $list], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
