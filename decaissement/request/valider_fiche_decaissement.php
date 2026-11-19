<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/FicheDecaissement.php';
require_once __DIR__ . '/../../model/AuditTrail.php';
require_once __DIR__ . '/../../services/UrgentDisbursementService.php';
session_start();

try {
    FinancialWorkflowProgress::step('Contrôle de la demande et des droits pour la fiche.');
    $pdo = Database::getConnection();
    $model = new FicheDecaissement($pdo);

    $num = $_POST['num_fiche'] ?? null;
    $id = $_POST['id'] ?? null;

    if (!$num && $id) {
        $st = $pdo->prepare("SELECT f.num_fiche FROM fiche_decaissement f LEFT JOIN chantier_banamur c ON c.id=f.chantier_id WHERE f.id_fiche=? AND (f.chantier_id IS NULL OR c.archived_at IS NULL)");
        $st->execute([(int)$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $num = $row['num_fiche'] ?? null;
    }
    if (!$num) {
        throw new Exception('num_fiche manquant');
    }

    $guardStmt = $pdo->prepare('SELECT chantier_id, code_autorisation_feb FROM fiche_decaissement WHERE num_fiche=:num LIMIT 1');
    $guardStmt->execute(['num' => $num]);
    $guardRow = $guardStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $urgentService = new \UrgentDisbursementService($pdo);
    $urgentId = $urgentService->urgentIdForFebCode((int)($guardRow['chantier_id'] ?? 0), (string)($guardRow['code_autorisation_feb'] ?? ''));
    $urgentService->assertChantierAvailable((int)($guardRow['chantier_id'] ?? 0), $urgentId);

    $user = $_SESSION['user_login'] ?? $_SESSION['secur'] ?? 'system';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $port = (int)($_SERVER['REMOTE_PORT'] ?? 0);

    $validationError = $model->getAchatValidationError((string)$num);
    if ($validationError !== null) {
        throw new RuntimeException($validationError);
    }
    $ok = $model->validateAchat($num, (string)$user, $ip, $port);
    if (!$ok) {
        throw new RuntimeException('La validation achat a été refusée. Rechargez la page puis réessayez.');
    }

    AuditTrail::log($ok ? 'decaissement_fiche_validated' : 'decaissement_fiche_validate_failed', [
        'module' => 'decaissement',
        'action' => 'valider_fiche_decaissement',
        'entity_type' => 'fiche_decaissement',
        'entity_id' => (string)$num,
        'entity_label' => (string)$num,
        'user_name' => (string)$user,
        'result' => $ok ? 'success' : 'failure',
        'severity' => $ok ? 'info' : 'warning',
    ], $pdo);

    echo json_encode(['ok' => (bool)$ok]);
} catch (Throwable $e) {
    http_response_code(400);
    AuditTrail::log('decaissement_fiche_validate_failed', [
        'module' => 'decaissement',
        'action' => 'valider_fiche_decaissement',
        'entity_type' => 'fiche_decaissement',
        'entity_id' => isset($num) && $num ? (string)$num : null,
        'entity_label' => isset($num) && $num ? (string)$num : 'valider_fiche_decaissement',
        'user_name' => (string)($_SESSION['user_login'] ?? $_SESSION['secur'] ?? 'system'),
        'result' => 'failure',
        'severity' => 'warning',
        'details' => ['error' => $e->getMessage()],
    ], isset($pdo) && $pdo instanceof PDO ? $pdo : null);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

