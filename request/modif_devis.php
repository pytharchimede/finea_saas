<?php

session_start();
require_once '../model/Database.php';
require_once '../model/Devis.php';
require_once '../model/AuditTrail.php';
require_once '../services/ActiveChantierEditPermissionService.php';
require_once '../auth_check.php';

$pdo = Database::getConnection();
$devisModel = new Devis($pdo);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $devisId = $_POST['devisId'] ?? '';

    try {
        ActiveChantierEditPermissionService::assertCanEdit($pdo, (int) $devisId);

        // Préparation des données
        $data = [
            'emis_par'        => $_POST['emisPar'] ?? '',
            'destine_a'       => $_POST['destineA'] ?? '',
            'delai_livraison' => $_POST['delaiLivraison'] ?? '',
            'date_emission'   => $_POST['dateEmission'] ?? '',
            'date_expiration' => $_POST['dateExpiration'] ?? '',
            'termes_conditions' => $_POST['termesConditions'] ?? '',
            'pied_de_page'    => $_POST['piedDePage'] ?? '',
            'total_ht'        => $_POST['totalHT'] ?? '0',
            'total_ttc'       => $_POST['totalTTC'] ?? '0',
            'tva'             => $_POST['tva'] ?? '0',
            'client_id'       => $_POST['client_id'] ?? null,
            'offre_id'        => $_POST['offre_id'] ?? null,
            'tva_facturable'  => $_POST['tvaFacturable'] ?? '0',
            'publier_devis'   => $_POST['publierDevis'] ?? '0',
            'correspondant'   => $_POST['correspondant'] ?? '',
            'logo'            => '',
        ];

        // Gestion du logo
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] == UPLOAD_ERR_OK) {
            $fileTmpPath = $_FILES['logo']['tmp_name'];
            $fileName = $_FILES['logo']['name'];
            $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $newFileName = 'logo_' . $devisId . '.' . $fileExtension;
            $uploadFileDir = '../logo/';
            $dest_path = $uploadFileDir . $newFileName;
            if (move_uploaded_file($fileTmpPath, $dest_path)) {
                $data['logo'] = $newFileName;
            } else {
                \AuditTrail::log('devis_update_failed', [
                    'module' => 'request',
                    'action' => 'modif_devis',
                    'entity_type' => 'devis',
                    'entity_id' => $devisId !== '' ? (string)$devisId : null,
                    'entity_label' => 'Modification devis',
                    'result' => 'failed',
                    'severity' => 'warning',
                    'details' => [
                        'reason' => 'logo_upload_failed',
                        'logo_name' => $fileName,
                    ],
                ], $pdo);
                echo "Erreur lors du déplacement du fichier.";
                exit;
            }
        }

        // Préparation des lignes de devis
        $lignes = [];
        if (isset($_POST['designation'])) {
            $designations = $_POST['designation'];
            $prix = $_POST['prix'];
            $quantites = $_POST['quantite'];
            $tvas = $_POST['tva'];
            $remises = $_POST['remise'];
            $totaux = $_POST['total'];
            $groupes = isset($_POST['groupe']) ? $_POST['groupe'] : [];


            for ($i = 0; $i < count($designations); $i++) {
                $lignes[] = [
                    'designation' => $designations[$i],
                    'prix'        => $prix[$i],
                    'quantite'    => $quantites[$i],
                    'tva'         => $tvas[$i],
                    'remise'      => $remises[$i],
                    'total'       => $totaux[$i],
                    'groupe'      => isset($groupes[$i]) ? $groupes[$i] : null,
                ];
            }
        }

        // Mise à jour via la classe
        $devisModel->modifierDevis($devisId, $data, $lignes);

        \AuditTrail::log('devis_updated', [
            'module' => 'request',
            'action' => 'modif_devis',
            'entity_type' => 'devis',
            'entity_id' => $devisId !== '' ? (string)$devisId : null,
            'entity_label' => 'Modification devis',
            'result' => 'success',
            'details' => [
                'client_id' => $data['client_id'],
                'offre_id' => $data['offre_id'],
                'line_count' => count($lignes),
                'logo_uploaded' => $data['logo'] !== '',
            ],
        ], $pdo);

        echo "<h1>Devis mis à jour avec succès</h1>";
        $_SESSION['devisId'] = $devisId;
    } catch (Throwable $e) {
        \AuditTrail::log('devis_update_failed', [
            'module' => 'request',
            'action' => 'modif_devis',
            'entity_type' => 'devis',
            'entity_id' => $devisId !== '' ? (string)$devisId : null,
            'entity_label' => 'Modification devis',
            'result' => 'failed',
            'severity' => 'warning',
            'details' => [
                'error_message' => $e->getMessage(),
            ],
        ], $pdo);
        throw $e;
    }
}
