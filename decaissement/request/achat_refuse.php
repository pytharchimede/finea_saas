<?php
header('Content-Type: application/json');
session_start();
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../../model/AuditTrail.php';
require_once __DIR__ . '/../model/FicheDecaissement.php';
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Méthode invalide');
    $num = trim($_POST['num_fiche'] ?? '');
    $motif = trim($_POST['motif'] ?? '');
    if (!$num) throw new Exception('num_fiche manquant');
    if (!$motif) throw new Exception('motif requis');
    $pdo = Database::getConnection();
    $m = new FicheDecaissement($pdo);
    $user = $_SESSION['user_login'] ?? $_SESSION['secur'] ?? 'system';
    $ok = $m->refuseAchat($num, $user, $motif, $_SERVER['REMOTE_ADDR'] ?? '', (int)($_SERVER['REMOTE_PORT'] ?? 0));
    AuditTrail::log($ok ? 'decaissement_achat_refused' : 'decaissement_achat_refuse_failed', [
        'module' => 'decaissement',
        'action' => 'achat_refuse',
        'entity_type' => 'fiche_decaissement',
        'entity_id' => $num,
        'entity_label' => $num,
        'user_name' => (string)$user,
        'result' => $ok ? 'success' : 'failure',
        'severity' => $ok ? 'info' : 'warning',
        'details' => ['motif' => $motif],
    ], $pdo);
    echo json_encode(['ok' => $ok]);
} catch (Throwable $e) {
    AuditTrail::log('decaissement_achat_refuse_failed', [
        'module' => 'decaissement',
        'action' => 'achat_refuse',
        'entity_type' => 'fiche_decaissement',
        'entity_id' => isset($num) && $num !== '' ? $num : null,
        'entity_label' => isset($num) && $num !== '' ? $num : 'achat_refuse',
        'user_name' => (string)($_SESSION['user_login'] ?? $_SESSION['secur'] ?? 'system'),
        'result' => 'failure',
        'severity' => 'warning',
        'details' => ['error' => $e->getMessage()],
    ]);
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
