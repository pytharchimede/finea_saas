<?php
header('Content-Type: application/json');
session_start();
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../../model/AuditTrail.php';
require_once __DIR__ . '/../model/FicheDecaissement.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    AuditTrail::log('decaissement_fiche_certify_failed', [
        'module' => 'decaissement',
        'action' => 'certifier_fiche_decaissement',
        'entity_type' => 'fiche_decaissement',
        'result' => 'failure',
        'severity' => 'warning',
        'details' => ['reason' => 'invalid_method'],
    ]);
    echo json_encode(['ok' => false, 'error' => 'Méthode invalide']);
    exit;
}

$num = $_POST['num_fiche'] ?? null;
$factureReference = trim((string)($_POST['facture_reference'] ?? ''));
if (!$num) {
    AuditTrail::log('decaissement_fiche_certify_failed', [
        'module' => 'decaissement',
        'action' => 'certifier_fiche_decaissement',
        'entity_type' => 'fiche_decaissement',
        'result' => 'failure',
        'severity' => 'warning',
        'details' => ['reason' => 'missing_num_fiche'],
    ]);
    echo json_encode(['ok' => false, 'error' => 'Numéro de fiche manquant']);
    exit;
}

try {
    FinancialWorkflowProgress::step('Contrôle de la demande et des droits pour la fiche.');
    $pdo = Database::getConnection();
    $model = new FicheDecaissement($pdo);
    $user = isset($_SESSION['secur_projet']) && $_SESSION['secur_projet'] !== ''
        ? $_SESSION['secur_projet']
        : 'SYSTEM';
    $ok = $model->setConforme($num, $user, $factureReference !== '' ? $factureReference : null);
    AuditTrail::log($ok ? 'decaissement_fiche_certified' : 'decaissement_fiche_certify_failed', [
        'module' => 'decaissement',
        'action' => 'certifier_fiche_decaissement',
        'entity_type' => 'fiche_decaissement',
        'entity_id' => (string)$num,
        'entity_label' => (string)$num,
        'user_name' => (string)$user,
        'result' => $ok ? 'success' : 'failure',
        'severity' => $ok ? 'info' : 'warning',
    ], $pdo);
    echo json_encode(['ok' => (bool)$ok]);
} catch (Exception $e) {
    AuditTrail::log('decaissement_fiche_certify_failed', [
        'module' => 'decaissement',
        'action' => 'certifier_fiche_decaissement',
        'entity_type' => 'fiche_decaissement',
        'entity_id' => isset($num) && $num ? (string)$num : null,
        'entity_label' => isset($num) && $num ? (string)$num : 'certifier_fiche_decaissement',
        'user_name' => (string)($_SESSION['secur_projet'] ?? 'SYSTEM'),
        'result' => 'failure',
        'severity' => 'warning',
        'details' => ['error' => $e->getMessage()],
    ], isset($pdo) && $pdo instanceof PDO ? $pdo : null);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

