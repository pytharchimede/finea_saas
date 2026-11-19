<?php

require_once '../model/Database.php';
require_once '../model/Precision.php';
require_once __DIR__ . '/../../model/AuditTrail.php';

$designation_id = $_POST['designation_id'] ?? null;

if (!$designation_id) {
    AuditTrail::log('decaissement_precisions_load_failed', [
        'module' => 'decaissement',
        'action' => 'charge_precision',
        'entity_type' => 'designation',
        'result' => 'failure',
        'severity' => 'warning',
        'details' => ['reason' => 'missing_designation_id'],
    ]);
    echo json_encode(["status" => "error", "message" => "Aucune désignation sélectionnée."], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = Database::getConnection();
$precisionObj = new Precision($pdo);
$precisions = $precisionObj->getPrecisionsByDesignation($designation_id);

if (empty($precisions)) {
    AuditTrail::log('decaissement_precisions_load_failed', [
        'module' => 'decaissement',
        'action' => 'charge_precision',
        'entity_type' => 'designation',
        'entity_id' => (string)$designation_id,
        'entity_label' => 'designation_' . $designation_id,
        'result' => 'failure',
        'severity' => 'warning',
        'details' => ['reason' => 'no_precision_found'],
    ], $pdo);
    echo json_encode(["status" => "error", "message" => "Aucune précision trouvée pour cette désignation."], JSON_UNESCAPED_UNICODE);
    exit;
}

AuditTrail::log('decaissement_precisions_loaded', [
    'module' => 'decaissement',
    'action' => 'charge_precision',
    'entity_type' => 'designation',
    'entity_id' => (string)$designation_id,
    'entity_label' => 'designation_' . $designation_id,
    'details' => ['precision_count' => count($precisions)],
], $pdo);

echo json_encode(["status" => "succes", "message" => $precisions], JSON_UNESCAPED_UNICODE);
