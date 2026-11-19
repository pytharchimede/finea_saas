<?php
session_start();
require_once __DIR__ . '/../../model/AuditTrail.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['companyName']) && !empty($_POST['companyName'])) {
        $_SESSION['companyName'] = $_POST['companyName'];
        AuditTrail::log('decaissement_company_defined', [
            'module' => 'decaissement',
            'action' => 'define_company',
            'entity_type' => 'company_context',
            'entity_label' => (string)$_SESSION['companyName'],
            'details' => ['company_name' => (string)$_SESSION['companyName']],
        ]);
        echo json_encode(['success' => true, 'message' => 'Vous avez selectionne ' . $_SESSION['companyName'] . ' ']);
    } else {
        AuditTrail::log('decaissement_company_define_failed', [
            'module' => 'decaissement',
            'action' => 'define_company',
            'entity_type' => 'company_context',
            'result' => 'failure',
            'severity' => 'warning',
            'details' => ['reason' => 'missing_company_name'],
        ]);
        echo json_encode(['success' => false, 'message' => 'Le nom de l\'entreprise est requis.']);
    }
} else {
    AuditTrail::log('decaissement_company_define_failed', [
        'module' => 'decaissement',
        'action' => 'define_company',
        'entity_type' => 'company_context',
        'result' => 'failure',
        'severity' => 'warning',
        'details' => ['reason' => 'invalid_method'],
    ]);
    echo json_encode(['success' => false, 'message' => 'Méthode non autorisée.']);
}
