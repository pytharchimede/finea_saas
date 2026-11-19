<?php
require_once __DIR__ . '/../auth_check.php';
AccessManager::requirePageAccess('liste_offre.php');
require_once '../model/Database.php';
require_once '../model/Offre.php';
require_once '../model/AuditTrail.php';
require_once '../services/QuoteBillingAlertService.php';

$pdo = Database::getConnection();
(new QuoteBillingAlertService($pdo))->ensureSchema();
$offreModel = new Offre($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'num_offre' => trim((string)($_POST['num_offre'] ?? '')),
        'date_offre' => trim((string)($_POST['date_offre'] ?? '')),
        'reference_offre' => trim((string)($_POST['reference_offre'] ?? '')),
        'commercial_dedie' => trim((string)($_POST['commercial_dedie'] ?? '')),
        'date_creat_offre' => trim((string)($_POST['date_creat_offre'] ?? '')),
        'client_id' => (int)($_POST['client_id'] ?? 0),
    ];
    if ($data['date_offre'] === '' || $data['reference_offre'] === '' || $data['commercial_dedie'] === '' || $data['date_creat_offre'] === '' || $data['client_id'] <= 0) {
        $_SESSION['project_flash'] = ['type'=>'error','message'=>'Tous les champs du projet sont obligatoires.'];
        header('Location: ../offres/liste.php?create=1');
        exit;
    }

    // Ajout de l'offre et récupération de l'ID
    if (!$offreModel->ajouterOffre($data)) {
        $_SESSION['project_flash'] = ['type'=>'error','message'=>'Le projet n’a pas pu être enregistré. Aucune création n’a été confirmée.'];
        header('Location: ../offres/liste.php?create=1');
        exit;
    }
    $offreId = $offreModel->getLastCreatedId();
    if ($offreId <= 0) {
        throw new RuntimeException('Le projet a été inséré sans identifiant vérifiable.');
    }
    $createdProject = $offreModel->getOffreById($offreId) ?: [];

    // Gestion des fichiers joints via la classe Offre
    if (!empty($_FILES['offre_files']['name'][0])) {
        $offreModel->ajouterFichiersOffre($offreId, $_FILES['offre_files']);
    }

    \AuditTrail::log('offre_created', [
        'module' => 'request',
        'action' => 'ajouter_offre',
        'entity_type' => 'offre',
        'entity_id' => (string) $offreId,
        'entity_label' => (string) ($createdProject['num_offre'] ?? ('projet_' . $offreId)),
        'details' => [
            'reference_offre' => $data['reference_offre'] ?? null,
            'commercial_dedie' => $data['commercial_dedie'] ?? null,
            'has_files' => !empty($_FILES['offre_files']['name'][0]),
        ],
    ], $pdo);

    $_SESSION['project_flash'] = ['type'=>'success','message'=>'Le projet a été créé avec succès.'];

    header('Location: ../offres/liste.php');
    exit();
}
