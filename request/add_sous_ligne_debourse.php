<?php
require_once '../auth_check.php';
require_once '../model/Database.php';
require_once '../model/Devis.php';
require_once '../model/AuditTrail.php';
require_once '../services/ActiveChantierEditPermissionService.php';
header('Content-Type: application/json');

if (empty($_SESSION['permissions']['modifier_debourse'])) {
    http_response_code(403);
    AuditTrail::log('debourse_subline_create_failed', [
        'module' => 'request',
        'action' => 'add_sous_ligne_debourse',
        'entity_type' => 'debourse_subline',
        'entity_label' => 'Ajout sous-ligne debourse',
        'result' => 'denied',
        'severity' => 'warning',
        'details' => [
            'reason' => 'missing_permission_modifier_debourse',
        ],
    ], $pdo);
    echo json_encode(['success' => false, 'message' => 'Accès refusé']);
    exit;
}

$pdo = Database::getConnection();
$devisModel = new Devis($pdo);

$debourse_id = $_POST['debourse_id'] ?? null;
$designation = $_POST['designation'] ?? null;
$categorie = $_POST['categorie'] ?? '';
$montant = $_POST['montant'] ?? null;
$date_debut = $_POST['date_debut'] ?? null;
$date_fin = $_POST['date_fin'] ?? null;

if (!$debourse_id || !$designation || !$montant) {
    AuditTrail::log('debourse_subline_create_failed', [
        'module' => 'request',
        'action' => 'add_sous_ligne_debourse',
        'entity_type' => 'debourse_subline',
        'entity_label' => (string)$designation,
        'result' => 'failed',
        'severity' => 'warning',
        'details' => [
            'reason' => 'missing_required_fields',
            'debourse_id' => $debourse_id ?: null,
        ],
    ], $pdo);
    echo json_encode(['success' => false, 'message' => 'Champs obligatoires manquants']);
    exit;
}

try {
    ActiveChantierEditPermissionService::assertCanEdit($pdo, ActiveChantierEditPermissionService::devisIdForDebourse($pdo, (int) $debourse_id));
    // Ajout de la sous-ligne via la classe Devis (pour gérer la catégorie)
    $devisModel->addSousLigneDebourseByDebourseId($debourse_id, $categorie, $designation, $montant, $date_debut, $date_fin);

    // Mettre à jour le résumé du déboursé
    $devisModel->updateDebourseResume($debourse_id);

    AuditTrail::log('debourse_subline_created', [
        'module' => 'request',
        'action' => 'add_sous_ligne_debourse',
        'entity_type' => 'debourse_subline',
        'entity_id' => (string)$debourse_id,
        'entity_label' => (string)$designation,
        'result' => 'success',
        'details' => [
            'debourse_id' => $debourse_id,
            'categorie' => $categorie,
            'montant' => $montant,
        ],
    ], $pdo);

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    AuditTrail::log('debourse_subline_create_failed', [
        'module' => 'request',
        'action' => 'add_sous_ligne_debourse',
        'entity_type' => 'debourse_subline',
        'entity_id' => $debourse_id ? (string)$debourse_id : null,
        'entity_label' => (string)$designation,
        'result' => 'failed',
        'severity' => 'warning',
        'details' => [
            'debourse_id' => $debourse_id ?: null,
            'error_message' => $e->getMessage(),
        ],
    ], $pdo);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
