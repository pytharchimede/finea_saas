<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../../model/AuditTrail.php';
require_once __DIR__ . '/../model/Fiche.php';
session_start();

try {
    $pdo = Database::getConnection();
    $ficheObj = new Fiche($pdo);
    $id = $_POST['id'] ?? null;
    $num = $_POST['num_fiche'] ?? null;

    if (!$num && $id) {
        $fiche = $ficheObj->getById((int)$id);
        $num = $fiche['num_fiche'] ?? null;
    }
    if (!$num) {
        throw new Exception('num_fiche manquant');
    }

    $user = $_SESSION['user_login'] ?? $_SESSION['secur'] ?? 'system';
    $ok = $ficheObj->approveFicheByNum($num, (string)$user);

    AuditTrail::log($ok ? 'decaissement_fiche_approved' : 'decaissement_fiche_approve_failed', [
        'module' => 'decaissement',
        'action' => 'approuver_fiche',
        'entity_type' => 'fiche',
        'entity_id' => (string)$num,
        'entity_label' => (string)$num,
        'user_name' => (string)$user,
        'result' => $ok ? 'success' : 'failure',
        'severity' => $ok ? 'info' : 'warning',
    ], $pdo);

    echo json_encode(['ok' => (bool)$ok]);
} catch (Throwable $e) {
    http_response_code(400);
    AuditTrail::log('decaissement_fiche_approve_failed', [
        'module' => 'decaissement',
        'action' => 'approuver_fiche',
        'entity_type' => 'fiche',
        'entity_id' => isset($num) && $num ? (string)$num : null,
        'entity_label' => isset($num) && $num ? (string)$num : 'approuver_fiche',
        'user_name' => (string)($_SESSION['user_login'] ?? $_SESSION['secur'] ?? 'system'),
        'result' => 'failure',
        'severity' => 'warning',
        'details' => ['error' => $e->getMessage()],
    ], isset($pdo) && $pdo instanceof PDO ? $pdo : null);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
