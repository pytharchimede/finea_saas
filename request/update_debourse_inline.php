<?php
require_once '../auth_check.php';
require_once '../model/Database.php';
require_once '../model/Devis.php';
require_once '../model/AuditTrail.php';
require_once '../services/ActiveChantierEditPermissionService.php';
header('Content-Type: application/json');

if (empty($_SESSION['permissions']['modifier_debourse'])) {
    http_response_code(403);
    AuditTrail::log('debourse_inline_update_failed', [
        'module' => 'request',
        'action' => 'update_debourse_inline',
        'entity_type' => 'debourse_line',
        'entity_label' => 'Mise a jour debourse inline',
        'result' => 'denied',
        'severity' => 'warning',
        'details' => [
            'reason' => 'missing_permission_modifier_debourse',
        ],
    ]);
    echo json_encode(['success' => false, 'message' => 'Accès refusé']);
    exit;
}

$pdo = Database::getConnection();
$devisModel = new Devis($pdo);

// Vérifier si l'utilisateur est connecté
$id = $_POST['id'] ?? null;
$designation = $_POST['designation'] ?? null;
$montant = $_POST['montant'] ?? null;
$date_debut = $_POST['date_debut'] ?? null;
$date_fin = $_POST['date_fin'] ?? null;

if (!$id) {
    AuditTrail::log('debourse_inline_update_failed', [
        'module' => 'request',
        'action' => 'update_debourse_inline',
        'entity_type' => 'debourse_line',
        'entity_label' => 'Mise a jour debourse inline',
        'result' => 'failed',
        'severity' => 'warning',
        'details' => [
            'reason' => 'missing_line_id',
        ],
    ], $pdo);
    echo json_encode(['success' => false, 'message' => 'ID manquant']);
    exit;
}

try {
    ActiveChantierEditPermissionService::assertCanEdit($pdo, ActiveChantierEditPermissionService::devisIdForDetail($pdo, (int) $id));
} catch (Throwable $e) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}

$ok = $devisModel->updateLigneDebourse($id, [
    'designation' => $designation,
    'montant' => $montant,
    'date_debut' => $date_debut,
    'date_fin' => $date_fin,
    'categorie' => $_POST['categorie'] ?? null
]);

if ($ok) {
    // Recalculer montant total et période du déboursé parent
    $debourse = $devisModel->getDebourseByLigneDebourse($id);
    $devisModel->updateDebourseResume($debourse['id']); // <-- AJOUT ICI
    $totaux = $devisModel->getTotauxDebourse($debourse['id']);
    AuditTrail::log('debourse_inline_updated', [
        'module' => 'request',
        'action' => 'update_debourse_inline',
        'entity_type' => 'debourse_line',
        'entity_id' => (string)$id,
        'entity_label' => (string)($designation ?? 'Ligne debourse'),
        'result' => 'success',
        'details' => [
            'debourse_id' => $debourse['id'] ?? null,
            'montant' => $montant,
            'categorie' => $_POST['categorie'] ?? null,
        ],
    ], $pdo);
    echo json_encode([
        'success' => true,
        'montant_debourse' => $totaux['montant_debourse'],
        'date_debut' => $totaux['date_debut'],
        'date_fin' => $totaux['date_fin']
    ]);
} else {
    AuditTrail::log('debourse_inline_update_failed', [
        'module' => 'request',
        'action' => 'update_debourse_inline',
        'entity_type' => 'debourse_line',
        'entity_id' => (string)$id,
        'entity_label' => (string)($designation ?? 'Ligne debourse'),
        'result' => 'failed',
        'severity' => 'warning',
        'details' => [
            'reason' => 'model_update_failed',
            'montant' => $montant,
        ],
    ], $pdo);
    echo json_encode(['success' => false, 'message' => 'Erreur lors de la mise à jour']);
}
