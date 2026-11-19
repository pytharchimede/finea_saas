<?php

require_once '../model/Database.php';
require_once '../model/Operation.php';
require_once '../model/Designation.php';
require_once __DIR__ . '/../../model/AuditTrail.php';

$chantier_id = $_POST['chantier'];

$pdo = Database::getConnection();

$operationObj = new Operation($pdo);
$operations = $operationObj->getOperationByChantierId($chantier_id);

$designationObj = new Designation($pdo);

$designations = [];

// Parcourir les opérations et fusionner toutes les désignations dans un tableau unique
foreach ($operations as $operation) {
    $operation_id = $operation['id_operation'];
    $designationsPerOperation = $designationObj->getDesignationByOperationId($operation_id);

    // Fusionner avec le tableau principal
    $designations = array_merge($designations, $designationsPerOperation);
}

AuditTrail::log('decaissement_designations_loaded', [
    'module' => 'decaissement',
    'action' => 'charge_designation',
    'entity_type' => 'chantier',
    'entity_id' => $chantier_id !== null ? (string)$chantier_id : null,
    'entity_label' => $chantier_id !== null ? ('chantier_' . $chantier_id) : 'charge_designation',
    'details' => [
        'operation_count' => is_array($operations) ? count($operations) : 0,
        'designation_count' => is_array($designations) ? count($designations) : 0,
    ],
], $pdo);

echo json_encode(["status" => "succes", "message" => $designations], JSON_UNESCAPED_UNICODE);
