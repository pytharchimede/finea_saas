<?php
require_once '../auth_check.php';
require_once '../model/Database.php';
require_once '../services/ActiveChantierEditPermissionService.php';
require_once '../model/AuditTrail.php';

$pdo = Database::getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

if (empty($_SESSION['permissions']['modifier_debourse'])) {
    header('Content-Type: application/json');
    http_response_code(403);
    AuditTrail::log('debourse_create_failed', [
        'module' => 'request',
        'action' => 'add_debourse',
        'entity_type' => 'debourse',
        'entity_label' => 'Creation debourse',
        'result' => 'denied',
        'severity' => 'warning',
        'details' => [
            'reason' => 'missing_permission_modifier_debourse',
        ],
    ], $pdo);
    echo json_encode(['success' => false, 'message' => 'Accès refusé']);
    exit;
}

$designation = $_POST['designation'] ?? '';
$montant = $_POST['montant'] ?? 0;
$date_debut = $_POST['date_debut'] ?? null;
$date_fin = $_POST['date_fin'] ?? null;
$devis_id = $_POST['devis_id'] ?? 0;
$responsable_id = $_POST['responsable_id'] ?? 1; // À adapter selon ton système utilisateur

if (!$designation || !$montant || !$devis_id) {
    AuditTrail::log('debourse_create_failed', [
        'module' => 'request',
        'action' => 'add_debourse',
        'entity_type' => 'debourse',
        'entity_label' => (string)$designation,
        'result' => 'failed',
        'severity' => 'warning',
        'details' => [
            'reason' => 'missing_required_fields',
            'devis_id' => $devis_id ?: null,
        ],
    ], $pdo);
    echo json_encode(['success' => false, 'message' => 'Données manquantes']);
    exit;
}

try {
    ActiveChantierEditPermissionService::assertCanEdit($pdo, (int) $devis_id);
    // 1. Ajouter la ligne dans ligne_devis_banamur
    $stmt = $pdo->prepare("INSERT INTO ligne_devis_banamur (devis_id, designation, prix, quantite, unite_id, total, groupe)
        VALUES (?, ?, ?, ?, ?, ?, ?)");
    $prix = $montant;
    $quantite = 1;
    $unite_id = 1; // à adapter si besoin
    $total = $montant;
    $groupe = ''; // plus de catégorie
    $stmt->execute([$devis_id, $designation, $prix, $quantite, $unite_id, $total, $groupe]);
    $ligne_devis_id = $pdo->lastInsertId();

    // 2. Ajouter le déboursé dans debourse_banamur
    $stmt2 = $pdo->prepare("INSERT INTO debourse_banamur (devis_id, ligne_devis_id, montant_debourse, responsable_id, date_debut, date_fin)
        VALUES (?, ?, ?, ?, ?, ?)");
    $stmt2->execute([$devis_id, $ligne_devis_id, $montant, $responsable_id, $date_debut, $date_fin]);

    AuditTrail::log('debourse_created', [
        'module' => 'request',
        'action' => 'add_debourse',
        'entity_type' => 'debourse',
        'entity_id' => (string)$pdo->lastInsertId(),
        'entity_label' => (string)$designation,
        'result' => 'success',
        'details' => [
            'devis_id' => $devis_id,
            'ligne_devis_id' => $ligne_devis_id,
            'montant' => $montant,
        ],
    ], $pdo);

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    AuditTrail::log('debourse_create_failed', [
        'module' => 'request',
        'action' => 'add_debourse',
        'entity_type' => 'debourse',
        'entity_label' => (string)$designation,
        'result' => 'failed',
        'severity' => 'warning',
        'details' => [
            'devis_id' => $devis_id ?: null,
            'error_message' => $e->getMessage(),
        ],
    ], $pdo);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
