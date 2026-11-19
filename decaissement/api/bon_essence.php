<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../model/EnvConfig.php';
\EnvConfig::loadFromDirectory(__DIR__);
require_once __DIR__ . '/../service/BonEssenceService.php';

$id = $_GET['id_bon'] ?? $_GET['code_bon'] ?? '';
$debugRequested = isset($_GET['debug']) && $_GET['debug'] == '1';
$debugToken = $_GET['token'] ?? '';
// Token simple; idéalement placer dans une config/variable d'env
$VALID_DEBUG_TOKEN = \EnvConfig::get('BON_DEBUG_TOKEN', '') ?: '';
if ($id === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Paramètre id_bon manquant']);
    exit;
}

try {
    $service = new \BonEssenceService();
    $res = $service->findBonById($id);
    // Cacher le debug par défaut
    if (isset($res['debug']) && !($debugRequested && $VALID_DEBUG_TOKEN !== '' && hash_equals($VALID_DEBUG_TOKEN, $debugToken))) {
        unset($res['debug']);
    }
    if (!$res['ok']) {
        http_response_code(404);
    }
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
