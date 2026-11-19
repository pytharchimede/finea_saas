<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/Devis.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../services/ActiveChantierEditPermissionService.php';
$resp = ['success' => false, 'message' => ''];
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new Exception('Méthode non autorisée');
    $pdo = Database::getConnection();
    $devisModel = new Devis($pdo);
    $devisId = (int)($_POST['devis_id'] ?? 0);
    $ligneId = (int)($_POST['ligne_devis_id'] ?? 0);
    $montant = (float)($_POST['montant_debourse'] ?? 0);
    $responsable = (int)($_POST['responsable_id'] ?? 0);
    $dd = $_POST['date_debut'] ?? null;
    $df = $_POST['date_fin'] ?? null;
    if ($devisId <= 0 || $ligneId <= 0) throw new Exception('Paramètres incomplets');
    if (empty($_SESSION['permissions']['modifier_debourse'])) throw new DomainException('Droit de modification des déboursés requis.');
    ActiveChantierEditPermissionService::assertCanEdit($pdo, $devisId);
    $sous = $_POST['sous_lignes'] ?? [];
    if (!is_array($sous)) $sous = [];
    $sousLignes = [];
    foreach ($sous as $json) {
        $obj = json_decode($json, true);
        if (!is_array($obj)) continue;
        $sousLignes[] = $obj;
    }

    $pdo->beginTransaction();
    $id = (int)$devisModel->saveDebourseLigne($devisId, $ligneId, $montant, $responsable, $dd, $df);
    $syncStats = $devisModel->syncSousLignesDebourse($devisId, $ligneId, $sousLignes);
    $pdo->commit();

    $resp['success'] = true;
    $resp['message'] = 'Ligne enregistrée';
    $resp['line_id'] = $ligneId;
    AuditTrail::log('debourse_line_saved', [
        'module' => 'request',
        'action' => 'save_debourse_line',
        'entity_type' => 'debourse_line',
        'entity_id' => $id > 0 ? (string)$id : null,
        'entity_label' => 'Sauvegarde ligne debourse',
        'result' => 'success',
        'details' => [
            'devis_id' => $devisId,
            'ligne_devis_id' => $ligneId,
            'sous_ligne_count' => is_array($sous) ? count($sous) : 0,
            'sync' => $syncStats ?? null,
        ],
    ], $pdo);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $resp['message'] = $e->getMessage();
    $resp['line_id'] = $ligneId ?? 0;
    $resp['needs_refresh'] = str_contains($e->getMessage(), 'ne fait plus partie de la ligne de devis');
    if (isset($pdo) && $pdo instanceof PDO) {
        AuditTrail::log('debourse_line_save_failed', [
            'module' => 'request',
            'action' => 'save_debourse_line',
            'entity_type' => 'debourse_line',
            'entity_id' => isset($id) && $id > 0 ? (string)$id : null,
            'entity_label' => 'Sauvegarde ligne debourse',
            'result' => 'failed',
            'severity' => 'warning',
            'details' => [
                'devis_id' => $devisId ?? null,
                'ligne_devis_id' => $ligneId ?? null,
                'error_message' => $e->getMessage(),
            ],
        ], $pdo);
    }
}
echo json_encode($resp);
