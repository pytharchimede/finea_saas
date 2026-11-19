<?php
session_start();
require_once '../model/Database.php';
require_once '../model/Devis.php';
require_once '../model/AuditTrail.php';

$pdo = Database::getConnection();
$devisModel = new Devis($pdo);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    error_log("Received POST request to update_devis.php");
    error_log("POST data: " . print_r($_POST, true));

    // Récupération de l'ID du devis à modifier
    $devisId = $_POST['devisId'] ?? null;
    if (!$devisId) {
        AuditTrail::log('devis_ajax_update_failed', [
            'module' => 'request',
            'action' => 'update_devis',
            'entity_type' => 'devis',
            'entity_label' => 'Mise a jour devis ajax',
            'result' => 'failed',
            'severity' => 'warning',
            'details' => [
                'reason' => 'missing_devis_id',
            ],
        ], $pdo);
        echo json_encode(['success' => false, 'message' => 'ID du devis manquant.']);
        exit;
    }

    error_log("Devis ID: " . $devisId);

    // Récupération des données du formulaire
    $data = [
        'numero_devis'     => $_POST['numeroDevis'] ?? '',
        'delai_livraison'  => $_POST['delaiLivraison'] ?? '',
        'date_emission'    => $_POST['dateEmission'] ?? '',
        'date_expiration'  => $_POST['dateExpiration'] ?? '',
        'emis_par'         => $_POST['emisPar'] ?? '',
        'destine_a'        => $_POST['destineA'] ?? '',
        'termes_conditions' => $_POST['termesConditions'] ?? '',
        'pied_de_page'     => $_POST['piedDePage'] ?? '',
        'total_ht'         => $_POST['totalHT'] ?? '0',
        'total_ttc'        => $_POST['totalTTC'] ?? '0',
        'logo'             => '', // à gérer ci-dessous
        'client_id'        => $_POST['client_id'] ?? null,
        'offre_id'         => $_POST['offre_id'] ?? null,
        'tva_facturable'   => $_POST['tvaFacturable'] ?? '0',
        'publier_devis'    => $_POST['publierDevis'] ?? '0',
        'tva'              => $_POST['tva'] ?? '0',
        'correspondant'    => $_POST['correspondant'] ?? '',
    ];

    // Gestion du logo
    $logoField = null;
    if (isset($_FILES['logoUpload'])) {
        $logoField = 'logoUpload';
    } elseif (isset($_FILES['logo'])) {
        $logoField = 'logo';
    }

    if ($logoField && isset($_FILES[$logoField]) && $_FILES[$logoField]['error'] == UPLOAD_ERR_OK) {
        $fileTmpPath = $_FILES[$logoField]['tmp_name'];
        $fileName = $_FILES[$logoField]['name'];
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $newFileName = 'logo_' . time() . '.' . $fileExtension;
        $uploadFileDir = '../logo/';
        $dest_path = $uploadFileDir . $newFileName;
        if (move_uploaded_file($fileTmpPath, $dest_path)) {
            $data['logo'] = $newFileName;
        }
    }
    // Si aucun logo uploadé, garder l'ancien ou mettre par défaut
    if (empty($data['logo'])) {
        $ancienDevis = $devisModel->getDevisById($devisId);
        $data['logo'] = $ancienDevis['logo_client'] ?? 'default_logo.jpg';
    }

    // Préparation des lignes de devis (JSON envoyé par AJAX)
    $lignes = [];
    if (isset($_POST['lignes'])) {
        $decoded = json_decode($_POST['lignes'], true);
        if (is_array($decoded)) {
            foreach ($decoded as $ligne) {
                $lignes[] = [
                    'id'           => $ligne['id'] ?? null,
                    'designation'  => $ligne['designation'] ?? '',
                    'prix_unitaire' => $ligne['prix_unitaire'] ?? 0,
                    'quantite'     => $ligne['quantite'] ?? 0,
                    'unite_id'     => $ligne['unite_id'] ?? null,
                    'prix_total'   => $ligne['prix_total'] ?? 0,
                    'groupe'       => $ligne['groupe'] ?? null,
                ];
            }
        }
    }

    // Mise à jour du devis via la classe
    $success = $devisModel->updateDevis($devisId, $data, $lignes);

    $_SESSION['devisId'] = $devisId;
    if ($success) {
        AuditTrail::log('devis_ajax_updated', [
            'module' => 'request',
            'action' => 'update_devis',
            'entity_type' => 'devis',
            'entity_id' => (string)$devisId,
            'entity_label' => 'Mise a jour devis ajax',
            'result' => 'success',
            'details' => [
                'client_id' => $data['client_id'],
                'offre_id' => $data['offre_id'],
                'line_count' => count($lignes),
            ],
        ], $pdo);
        echo json_encode(['success' => true]);
    } else {
        AuditTrail::log('devis_ajax_update_failed', [
            'module' => 'request',
            'action' => 'update_devis',
            'entity_type' => 'devis',
            'entity_id' => (string)$devisId,
            'entity_label' => 'Mise a jour devis ajax',
            'result' => 'failed',
            'severity' => 'warning',
            'details' => [
                'reason' => 'model_update_failed',
                'line_count' => count($lignes),
            ],
        ], $pdo);
        echo json_encode(['success' => false, 'message' => 'Erreur lors de la modification du devis']);
    }
    exit;
}
