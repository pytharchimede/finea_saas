<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../model/RhOrganizationChartLayoutManager.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Methode non autorisee.']);
    exit;
}

$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Donnees invalides.']);
    exit;
}

$sessionToken = (string) ($_SESSION['rh_org_chart_csrf'] ?? '');
$requestToken = (string) ($payload['csrf_token'] ?? '');
if ($sessionToken === '' || $requestToken === '' || !hash_equals($sessionToken, $requestToken)) {
    http_response_code(419);
    echo json_encode(['ok' => false, 'message' => 'Session expiree. Rechargez la page.']);
    exit;
}

try {
    $pdo = \Database::getConnection();
    $manager = new \RhOrganizationChartLayoutManager($pdo);
    $action = (string) ($payload['action'] ?? 'save');
    $layout = $action === 'reset'
        ? $manager->reset((int) ($_SESSION['user_id'] ?? 0))
        : $manager->save((array) ($payload['layout'] ?? []), (int) ($_SESSION['user_id'] ?? 0));

    \AuditTrail::log('update', [
        'module' => 'rh',
        'action' => $action === 'reset' ? 'rh_organization_chart_layout_reset' : 'rh_organization_chart_layout_save',
        'entity_type' => 'rh_organization_chart',
        'entity_label' => 'Disposition organigramme BANAMUR',
        'details' => [
            'revision' => (int) ($layout['revision'] ?? 0),
            'node_count' => count($layout['nodes'] ?? []),
            'edge_count' => count($layout['edges'] ?? []),
        ],
    ], $pdo);

    echo json_encode([
        'ok' => true,
        'message' => $action === 'reset' ? 'Disposition automatique restauree.' : 'Disposition enregistree.',
        'layout' => $layout,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'La disposition n a pas pu etre enregistree.',
    ]);
}
