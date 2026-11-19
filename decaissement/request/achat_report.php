<?php
header('Content-Type: application/json');
session_start();
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../../model/AuditTrail.php';
require_once __DIR__ . '/../model/FicheDecaissement.php';
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Méthode invalide');
    $rawNum = trim($_POST['num_fiche'] ?? '');
    $nums = array_values(array_unique(array_filter(array_map('trim', explode(',', $rawNum)))));
    $num = $rawNum;
    $date = trim($_POST['date_min'] ?? '');
    if (!$num) throw new Exception('num_fiche manquant');
    if (!$date) throw new Exception('date_min manquante');
    $pdo = Database::getConnection();
    $m = new FicheDecaissement($pdo);
    $user = $_SESSION['user_login'] ?? $_SESSION['secur'] ?? 'system';
    $ok = true;$reported=[];$failed=[];
    foreach($nums as $oneNum){if($m->reporterAchat($oneNum,$user,$date,$_SERVER['REMOTE_ADDR']??'',(int)($_SERVER['REMOTE_PORT']??0)))$reported[]=$oneNum;else{$failed[]=$oneNum;$ok=false;}}
    AuditTrail::log($ok ? 'decaissement_achat_reported' : 'decaissement_achat_report_failed', [
        'module' => 'decaissement',
        'action' => 'achat_report',
        'entity_type' => 'fiche_decaissement',
        'entity_id' => $num,
        'entity_label' => $num,
        'user_name' => (string)$user,
        'result' => $ok ? 'success' : 'failure',
        'severity' => $ok ? 'info' : 'warning',
        'details' => ['date_min' => $date,'reported'=>$reported,'failed'=>$failed],
    ], $pdo);
    echo json_encode(['ok' => $ok,'reported'=>$reported,'failed'=>$failed]);
} catch (Throwable $e) {
    AuditTrail::log('decaissement_achat_report_failed', [
        'module' => 'decaissement',
        'action' => 'achat_report',
        'entity_type' => 'fiche_decaissement',
        'entity_id' => isset($num) && $num !== '' ? $num : null,
        'entity_label' => isset($num) && $num !== '' ? $num : 'achat_report',
        'user_name' => (string)($_SESSION['user_login'] ?? $_SESSION['secur'] ?? 'system'),
        'result' => 'failure',
        'severity' => 'warning',
        'details' => ['error' => $e->getMessage()],
    ]);
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
