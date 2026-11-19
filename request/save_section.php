<?php
require_once '../model/Database.php';
require_once '../model/Devis.php';
require_once '../model/AuditTrail.php';
require_once '../auth_check.php';
require_once '../services/ActiveChantierEditPermissionService.php';

$pdo = Database::getConnection();
$devisModel = new Devis($pdo);
$devisId = intval($_POST['devis_id'] ?? 0);
$section = $_POST['section'] ?? '';
$contenu = $_POST['contenu'] ?? '';
try {
    ActiveChantierEditPermissionService::assertCanEdit($pdo, $devisId);
    $devisModel->saveSectionContent($devisId, $section, $contenu);
    AuditTrail::log('devis_section_saved', [
        'module' => 'request',
        'action' => 'save_section',
        'entity_type' => 'devis_section',
        'entity_id' => $devisId > 0 ? (string)$devisId : null,
        'entity_label' => 'Section devis ' . $section,
        'result' => 'success',
        'details' => [
            'devis_id' => $devisId,
            'section' => $section,
            'content_length' => mb_strlen((string)$contenu),
        ],
    ], $pdo);
} catch (Throwable $e) {
    AuditTrail::log('devis_section_save_failed', [
        'module' => 'request',
        'action' => 'save_section',
        'entity_type' => 'devis_section',
        'entity_id' => $devisId > 0 ? (string)$devisId : null,
        'entity_label' => 'Section devis ' . $section,
        'result' => 'failed',
        'severity' => 'warning',
        'details' => [
            'devis_id' => $devisId,
            'section' => $section,
            'error_message' => $e->getMessage(),
        ],
    ], $pdo);
    throw $e;
}
header('Location: ../editer_section.php?devisId=' . $devisId . '&section=' . $section . '&saved=1');


exit;
