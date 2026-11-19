<?php
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/Offre.php';
require_once __DIR__ . '/../services/QuoteBillingAlertService.php';

$pdo = Database::getConnection();
(new QuoteBillingAlertService($pdo))->ensureSchema();
$offreModel = new Offre($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../offres/liste.php');
    exit;
}

$offreId = isset($_POST['id_offre']) ? (int)$_POST['id_offre'] : 0;
if ($offreId <= 0) {
    \AuditTrail::log('offre_update_failed', [
        'module' => 'request',
        'action' => 'modifier_offre',
        'entity_type' => 'offre',
        'entity_label' => 'Modification offre',
        'result' => 'failed',
        'severity' => 'warning',
        'details' => [
            'reason' => 'missing_offre_id',
        ],
    ], $pdo);
    header('Location: ../offres/liste.php');
    exit;
}

$existing = $offreModel->getOffreById($offreId);
if (!$existing) {
    \AuditTrail::log('offre_update_failed', [
        'module' => 'request',
        'action' => 'modifier_offre',
        'entity_type' => 'offre',
        'entity_id' => (string)$offreId,
        'entity_label' => 'Modification offre',
        'result' => 'failed',
        'severity' => 'warning',
        'details' => [
            'reason' => 'offre_not_found',
        ],
    ], $pdo);
    header('Location: ../offres/liste.php');
    exit;
}

$numOffre = (string)($existing['num_offre'] ?? '');
$dateOffre = trim((string)($_POST['date_offre'] ?? ''));
$referenceOffre = trim((string)($_POST['reference_offre'] ?? ''));
$commercialDedie = trim((string)($_POST['commercial_dedie'] ?? ''));
$dateCreatOffre = trim((string)($_POST['date_creat_offre'] ?? ''));
$clientId = (int)($_POST['client_id'] ?? ($existing['client_id'] ?? 0));

if ($dateOffre === '' || $referenceOffre === '' || $commercialDedie === '' || $dateCreatOffre === '' || $clientId <= 0) {
    \AuditTrail::log('offre_update_failed', [
        'module' => 'request',
        'action' => 'modifier_offre',
        'entity_type' => 'offre',
        'entity_id' => (string)$offreId,
        'entity_label' => $referenceOffre !== '' ? $referenceOffre : (string)($existing['reference_offre'] ?? 'Projet'),
        'result' => 'failed',
        'severity' => 'warning',
        'details' => [
            'reason' => 'missing_required_fields',
        ],
    ], $pdo);
    header('Location: ../offres/modifier.php?id=' . $offreId);
    exit;
}

$data = [
    'num_offre' => $numOffre,
    'date_offre' => $dateOffre,
    'reference_offre' => $referenceOffre,
    'commercial_dedie' => $commercialDedie,
    'date_creat_offre' => $dateCreatOffre,
    'client_id' => $clientId,
];

$offreModel->updateOffreById($offreId, $data);

// Ajout de nouveaux fichiers (optionnel)
$uploadedFileCount = 0;
if (!empty($_FILES['offre_files']['name'][0])) {
    $offreModel->ajouterFichiersOffre($offreId, $_FILES['offre_files']);
    $uploadedFileCount = is_array($_FILES['offre_files']['name']) ? count(array_filter($_FILES['offre_files']['name'])) : 0;
}

\AuditTrail::log('offre_updated', [
    'module' => 'request',
    'action' => 'modifier_offre',
    'entity_type' => 'offre',
    'entity_id' => (string)$offreId,
    'entity_label' => $referenceOffre,
    'result' => 'success',
    'details' => [
        'num_offre' => $numOffre,
        'uploaded_file_count' => $uploadedFileCount,
    ],
], $pdo);

header('Location: ../offres/liste.php');
exit;
