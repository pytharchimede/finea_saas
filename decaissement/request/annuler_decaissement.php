<?php
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../../model/AuditTrail.php';
require_once __DIR__ . '/../model/FicheDecaissement.php';
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    AuditTrail::log('decaissement_cancel_failed', [
        'module' => 'decaissement',
        'action' => 'annuler_decaissement',
        'entity_type' => 'fiche_decaissement',
        'result' => 'failure',
        'severity' => 'warning',
        'details' => ['reason' => 'invalid_method'],
    ]);
    echo json_encode(['ok' => false, 'error' => 'POST uniquement']);
    exit;
}
$num = $_POST['num_fiche'] ?? '';
$motif = trim($_POST['motif'] ?? '');
if (!$num) {
    AuditTrail::log('decaissement_cancel_failed', [
        'module' => 'decaissement',
        'action' => 'annuler_decaissement',
        'entity_type' => 'fiche_decaissement',
        'result' => 'failure',
        'severity' => 'warning',
        'details' => ['reason' => 'missing_num_fiche'],
    ]);
    echo json_encode(['ok' => false, 'error' => 'num_fiche manquant']);
    exit;
}
try {
    $m = new FicheDecaissement($pdo);
    $user = $_SESSION['username'] ?? 'system';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $port = (int)($_SERVER['REMOTE_PORT'] ?? 0);

    $err = $m->getAnnulationDecaissementError($num);
    if ($err !== null) {
        AuditTrail::log('decaissement_cancel_failed', [
            'module' => 'decaissement',
            'action' => 'annuler_decaissement',
            'entity_type' => 'fiche_decaissement',
            'entity_id' => $num,
            'entity_label' => $num,
            'user_name' => (string)$user,
            'result' => 'failure',
            'severity' => 'warning',
            'details' => ['reason' => 'business_rule_block', 'error' => $err],
        ]);
        echo json_encode(['ok' => false, 'error' => $err]);
        exit;
    }

    $ok = $m->annulerDecaissementRetourApprobation($num, $user, $motif, $ip, $port);
    if (!$ok) {
        AuditTrail::log('decaissement_cancel_failed', [
            'module' => 'decaissement',
            'action' => 'annuler_decaissement',
            'entity_type' => 'fiche_decaissement',
            'entity_id' => $num,
            'entity_label' => $num,
            'user_name' => (string)$user,
            'result' => 'failure',
            'severity' => 'warning',
            'details' => ['reason' => 'model_returned_false', 'motif' => $motif !== '' ? $motif : null],
        ]);
        echo json_encode(['ok' => false, 'error' => 'Annulation impossible']);
        exit;
    }
    AuditTrail::log('decaissement_cancelled', [
        'module' => 'decaissement',
        'action' => 'annuler_decaissement',
        'entity_type' => 'fiche_decaissement',
        'entity_id' => $num,
        'entity_label' => $num,
        'user_name' => (string)$user,
        'details' => ['motif' => $motif !== '' ? $motif : null],
    ]);
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    http_response_code(500);
    AuditTrail::log('decaissement_cancel_failed', [
        'module' => 'decaissement',
        'action' => 'annuler_decaissement',
        'entity_type' => 'fiche_decaissement',
        'entity_id' => $num !== '' ? $num : null,
        'entity_label' => $num !== '' ? $num : 'annuler_decaissement',
        'user_name' => (string)($_SESSION['username'] ?? 'system'),
        'result' => 'failure',
        'severity' => 'warning',
        'details' => ['error' => $e->getMessage()],
    ]);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
