<?php
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/FicheDecaissement.php';
require_once __DIR__ . '/../../model/AuditTrail.php';

$pdo = Database::getConnection();

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    AuditTrail::log('decaissement_report_compta_failed', [
        'module' => 'decaissement',
        'action' => 'reporter_compta',
        'entity_type' => 'fiche_decaissement',
        'entity_label' => 'reporter_compta',
        'result' => 'failure',
        'severity' => 'warning',
        'details' => ['reason' => 'invalid_method'],
    ], $pdo);
    echo json_encode(['ok' => false, 'error' => 'POST uniquement']);
    exit;
}
$num = $_POST['num_fiche'] ?? '';
$date = $_POST['date_min'] ?? '';
if (!$num || !$date) {
    AuditTrail::log('decaissement_report_compta_failed', [
        'module' => 'decaissement',
        'action' => 'reporter_compta',
        'entity_type' => 'fiche_decaissement',
        'entity_id' => $num !== '' ? $num : null,
        'entity_label' => $num !== '' ? $num : 'reporter_compta',
        'result' => 'failure',
        'severity' => 'warning',
        'details' => ['reason' => 'missing_required_fields', 'date_min' => $date !== '' ? $date : null],
    ], $pdo);
    echo json_encode(['ok' => false, 'error' => 'Champs requis manquants']);
    exit;
}
try {
    $m = new FicheDecaissement($pdo);
    $user = $_SESSION['username'] ?? 'system';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $port = (int)($_SERVER['REMOTE_PORT'] ?? 0);
    $ok = $m->reporterCompta($num, $user, $date, $ip, $port);
    AuditTrail::log($ok ? 'decaissement_report_compta_done' : 'decaissement_report_compta_failed', [
        'module' => 'decaissement',
        'action' => 'reporter_compta',
        'entity_type' => 'fiche_decaissement',
        'entity_id' => $num,
        'entity_label' => $num,
        'user_name' => (string)$user,
        'result' => $ok ? 'success' : 'failure',
        'severity' => $ok ? 'info' : 'warning',
        'details' => ['date_min' => $date],
    ], $pdo);
    echo json_encode(['ok' => $ok]);
} catch (Throwable $e) {
    http_response_code(500);
    AuditTrail::log('decaissement_report_compta_failed', [
        'module' => 'decaissement',
        'action' => 'reporter_compta',
        'entity_type' => 'fiche_decaissement',
        'entity_id' => $num !== '' ? $num : null,
        'entity_label' => $num !== '' ? $num : 'reporter_compta',
        'user_name' => (string)($_SESSION['username'] ?? 'system'),
        'result' => 'failure',
        'severity' => 'warning',
        'details' => ['error' => $e->getMessage()],
    ], $pdo);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
