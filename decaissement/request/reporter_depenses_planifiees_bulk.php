<?php
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/FicheDecaissement.php';
require_once __DIR__ . '/../../model/AuditTrail.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = Database::getConnection();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST uniquement']);
    exit;
}

$date = trim((string)($_POST['date_min'] ?? ''));
$nums = $_POST['num_fiches'] ?? [];
if (!is_array($nums)) {
    $nums = [$nums];
}
$nums = array_values(array_unique(array_filter(array_map(static function ($value): string {
    return trim((string)$value);
}, $nums), static fn(string $value): bool => $value !== '')));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Date invalide']);
    exit;
}

if (!$nums) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Aucune fiche à reporter']);
    exit;
}

$user = (string)($_SESSION['username'] ?? $_SESSION['user_login'] ?? $_SESSION['secur'] ?? 'system');
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$port = (int)($_SERVER['REMOTE_PORT'] ?? 0);
$manager = new FicheDecaissement($pdo);

$updated = [];
$failed = [];
foreach ($nums as $num) {
    try {
        if ($manager->reporterCompta($num, $user, $date, $ip, $port)) {
            $updated[] = $num;
        } else {
            $failed[] = $num;
        }
    } catch (Throwable $e) {
        $failed[] = $num;
    }
}

AuditTrail::log($failed ? 'decaissement_report_planned_bulk_partial' : 'decaissement_report_planned_bulk_done', [
    'module' => 'decaissement',
    'action' => 'reporter_depenses_planifiees_bulk',
    'entity_type' => 'fiche_decaissement',
    'entity_label' => 'report_depenses_planifiees_' . $date,
    'user_name' => $user,
    'result' => $failed ? 'partial' : 'success',
    'severity' => $failed ? 'warning' : 'info',
    'details' => [
        'date_min' => $date,
        'updated' => $updated,
        'failed' => $failed,
    ],
], $pdo);

echo json_encode([
    'ok' => !$failed,
    'updated' => $updated,
    'failed' => $failed,
    'message' => count($updated) . ' fiche(s) reportée(s)' . ($failed ? ', ' . count($failed) . ' échec(s)' : ''),
]);
