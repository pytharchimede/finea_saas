<?php
header('Content-Type: application/json');
session_start();
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../../model/AuditTrail.php';
require_once __DIR__ . '/../model/FicheDecaissement.php';
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Méthode invalide');
    $num = trim($_POST['num_fiche'] ?? '');
    if (!$num) throw new Exception('num_fiche manquant');
    $pdo = Database::getConnection();
    $m = new FicheDecaissement($pdo);
    $user = $_SESSION['user_login'] ?? $_SESSION['secur'] ?? 'system';
    $validationError = $m->getAchatValidationError($num);
    if ($validationError !== null) throw new RuntimeException($validationError);
    $ok = $m->validateAchat($num, $user, $_SERVER['REMOTE_ADDR'] ?? '', (int)($_SERVER['REMOTE_PORT'] ?? 0));
    if (!$ok) throw new RuntimeException('La validation achat a été refusée. Rechargez la page puis réessayez.');
    AuditTrail::log($ok ? 'decaissement_achat_validated' : 'decaissement_achat_validate_failed', [
        'module' => 'decaissement',
        'action' => 'achat_validate',
        'entity_type' => 'fiche_decaissement',
        'entity_id' => $num,
        'entity_label' => $num,
        'user_name' => (string)$user,
        'result' => $ok ? 'success' : 'failure',
        'severity' => $ok ? 'info' : 'warning',
    ], $pdo);
    echo json_encode(['ok' => $ok]);
} catch (Throwable $e) {
    AuditTrail::log('decaissement_achat_validate_failed', [
        'module' => 'decaissement',
        'action' => 'achat_validate',
        'entity_type' => 'fiche_decaissement',
        'entity_id' => isset($num) && $num !== '' ? $num : null,
        'entity_label' => isset($num) && $num !== '' ? $num : 'achat_validate',
        'user_name' => (string)($_SESSION['user_login'] ?? $_SESSION['secur'] ?? 'system'),
        'result' => 'failure',
        'severity' => 'warning',
        'details' => ['error' => $e->getMessage()],
    ]);
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
