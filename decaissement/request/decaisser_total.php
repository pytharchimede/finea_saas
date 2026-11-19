<?php
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/FicheDecaissement.php';
require_once __DIR__ . '/../../model/AuditTrail.php';
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    AuditTrail::log('decaissement_total_failed', [
        'module' => 'decaissement',
        'action' => 'decaisser_total',
        'entity_type' => 'fiche_decaissement',
        'entity_label' => 'decaisser_total',
        'result' => 'failure',
        'severity' => 'warning',
        'details' => ['reason' => 'invalid_method'],
    ]);
    echo json_encode(['ok' => false, 'error' => 'POST uniquement']);
    exit;
}
$num = $_POST['num_fiche'] ?? '';
if (!$num) {
    AuditTrail::log('decaissement_total_failed', [
        'module' => 'decaissement',
        'action' => 'decaisser_total',
        'entity_type' => 'fiche_decaissement',
        'entity_label' => 'decaisser_total',
        'result' => 'failure',
        'severity' => 'warning',
        'details' => ['reason' => 'missing_num_fiche'],
    ]);
    echo json_encode(['ok' => false, 'error' => 'num_fiche manquant']);
    exit;
}
try {
    if (!isset($pdo) || !$pdo) { // fallback si non fourni par auth_check
        $pdo = Database::getConnection();
    }
    $m = new FicheDecaissement($pdo);
    $blockingError = $m->getDecaissementTotalError($num);
    if ($blockingError !== null) {
        AuditTrail::log('decaissement_total_failed', [
            'module' => 'decaissement',
            'action' => 'decaisser_total',
            'entity_type' => 'fiche_decaissement',
            'entity_id' => $num,
            'entity_label' => $num,
            'result' => 'failure',
            'severity' => 'warning',
            'details' => ['reason' => 'business_rule_block', 'error' => $blockingError],
        ], $pdo);
        echo json_encode(['ok' => false, 'error' => $blockingError]);
        exit;
    }
    $user = $_SESSION['username'] ?? 'system';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $port = (int)($_SERVER['REMOTE_PORT'] ?? 0);
    $ok = $m->decaisserCompta($num, $user, $ip, $port);
    AuditTrail::log($ok ? 'decaissement_total_processed' : 'decaissement_total_failed', [
        'module' => 'decaissement',
        'action' => 'decaisser_total',
        'entity_type' => 'fiche_decaissement',
        'entity_id' => $num,
        'entity_label' => $num,
        'user_name' => (string)$user,
        'result' => $ok ? 'success' : 'failure',
        'severity' => $ok ? 'info' : 'warning',
        'details' => ['error' => $ok ? null : 'Décaissement impossible'],
    ], $pdo);
    echo json_encode(['ok' => $ok, 'error' => $ok ? null : 'Décaissement impossible']);
} catch (Throwable $e) {
    http_response_code(500);
    AuditTrail::log('decaissement_total_failed', [
        'module' => 'decaissement',
        'action' => 'decaisser_total',
        'entity_type' => 'fiche_decaissement',
        'entity_id' => $num !== '' ? $num : null,
        'entity_label' => $num !== '' ? $num : 'decaisser_total',
        'user_name' => (string)($_SESSION['username'] ?? 'system'),
        'result' => 'failure',
        'severity' => 'warning',
        'details' => ['error' => $e->getMessage()],
    ], isset($pdo) && $pdo instanceof PDO ? $pdo : null);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
