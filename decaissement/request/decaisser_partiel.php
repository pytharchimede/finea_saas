<?php
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/FicheDecaissement.php';
require_once __DIR__ . '/../../model/AuditTrail.php';
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    AuditTrail::log('decaissement_partial_failed', [
        'module' => 'decaissement',
        'action' => 'decaisser_partiel',
        'entity_type' => 'fiche_decaissement',
        'entity_label' => 'decaisser_partiel',
        'result' => 'failure',
        'severity' => 'warning',
        'details' => ['reason' => 'invalid_method'],
    ]);
    echo json_encode(['ok' => false, 'error' => 'POST uniquement']);
    exit;
}
$num = $_POST['num_fiche'] ?? '';
$montant = isset($_POST['montant']) ? (float)$_POST['montant'] : 0;
$next = $_POST['date_suivante'] ?? '';
$options = [
    'progress_rate' => isset($_POST['progress_rate']) ? (float)$_POST['progress_rate'] : 0,
    'pv_reception_reference' => trim((string)($_POST['pv_reception_reference'] ?? '')),
    'pv_validator_one' => trim((string)($_POST['pv_validator_one'] ?? '')),
    'pv_validator_two' => trim((string)($_POST['pv_validator_two'] ?? '')),
];
if (!$num || !$montant || !$next) {
    AuditTrail::log('decaissement_partial_failed', [
        'module' => 'decaissement',
        'action' => 'decaisser_partiel',
        'entity_type' => 'fiche_decaissement',
        'entity_id' => $num !== '' ? $num : null,
        'entity_label' => $num !== '' ? $num : 'decaisser_partiel',
        'result' => 'failure',
        'severity' => 'warning',
        'details' => ['reason' => 'missing_required_fields', 'montant' => $montant, 'date_suivante' => $next !== '' ? $next : null],
    ]);
    echo json_encode(['ok' => false, 'error' => 'Champs requis manquants']);
    exit;
}
try {
    if (!isset($pdo) || !$pdo) { // fallback si non fourni par auth_check
        $pdo = Database::getConnection();
    }
    $m = new FicheDecaissement($pdo);
    $user = $_SESSION['username'] ?? 'system';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $port = (int)($_SERVER['REMOTE_PORT'] ?? 0);
    $res = $m->decaisserPartiel($num, $montant, $next, $user, $ip, $port, $options);
    AuditTrail::log(!empty($res['ok']) ? 'decaissement_partial_processed' : 'decaissement_partial_failed', [
        'module' => 'decaissement',
        'action' => 'decaisser_partiel',
        'entity_type' => 'fiche_decaissement',
        'entity_id' => $num,
        'entity_label' => $num,
        'user_name' => (string)$user,
        'result' => !empty($res['ok']) ? 'success' : 'failure',
        'severity' => !empty($res['ok']) ? 'info' : 'warning',
        'details' => [
            'montant' => $montant,
            'date_suivante' => $next,
            'progress_rate' => $options['progress_rate'],
            'pv_reception_reference' => $options['pv_reception_reference'] !== '' ? $options['pv_reception_reference'] : null,
            'pv_validator_one' => $options['pv_validator_one'] !== '' ? $options['pv_validator_one'] : null,
            'pv_validator_two' => $options['pv_validator_two'] !== '' ? $options['pv_validator_two'] : null,
            'error' => $res['error'] ?? null,
        ],
    ], $pdo);
    echo json_encode($res);
} catch (Throwable $e) {
    http_response_code(500);
    AuditTrail::log('decaissement_partial_failed', [
        'module' => 'decaissement',
        'action' => 'decaisser_partiel',
        'entity_type' => 'fiche_decaissement',
        'entity_id' => $num !== '' ? $num : null,
        'entity_label' => $num !== '' ? $num : 'decaisser_partiel',
        'user_name' => (string)($_SESSION['username'] ?? 'system'),
        'result' => 'failure',
        'severity' => 'warning',
        'details' => ['error' => $e->getMessage()],
    ], isset($pdo) && $pdo instanceof PDO ? $pdo : null);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
