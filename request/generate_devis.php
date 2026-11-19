<?php
require_once '../auth_check.php';
require_once '../model/Devis.php';
require_once '../model/Offre.php';
require_once '../model/AuditTrail.php';
require_once '../controllers/DevisOfferProjectController.php';
require_once '../services/QuoteBillingAlertService.php';

AccessManager::requirePageAccess('generer_devis.php');

$pdo = Database::getConnection();
$devisModel = new Devis($pdo);
$billingAlerts = new QuoteBillingAlertService($pdo);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    header('Content-Type: application/json; charset=utf-8');


    // Récupération des données du formulaire
    $data = [
        'numero_devis'     => $_POST['numeroDevis'] ?? '',
        'delai_livraison'  => $_POST['delaiLivraison'] ?? '',
        'date_emission'    => $_POST['dateEmission'] ?? '',
        'date_expiration'  => $_POST['dateExpiration'] ?? '',
        'billing_date'     => $_POST['billingDate'] ?? '',
        'emis_par'         => $_POST['emisPar'] ?? '',
        'destine_a'        => $_POST['destineA'] ?? '',
        'termes_conditions' => $_POST['termesConditions'] ?? '',
        'pied_de_page'     => $_POST['piedDePage'] ?? '',
        'total_ht'         => $_POST['totalHT'] ?? '0',
        'total_ttc'        => $_POST['totalTTC'] ?? '0',
        'commercial_discount' => $_POST['commercialDiscount'] ?? '0',
        'logo'             => '', // à gérer ci-dessous
        'client_id'        => $_POST['client_id'] ?? null,
        'offre_id'         => $_POST['offre_id'] ?? null,
        'tva_facturable'   => $_POST['tvaFacturable'] ?? '0',
        'publier_devis'    => $_POST['publierDevis'] ?? '0',
        'tva'              => $_POST['tva'] ?? '0',
        'correspondant'    => $_POST['correspondant'] ?? '',
    ];

    // Gestion du logo
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] == UPLOAD_ERR_OK) {
        $fileTmpPath = $_FILES['logo']['tmp_name'];
        $fileName = $_FILES['logo']['name'];
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $newFileName = 'logo_' . time() . '.' . $fileExtension;
        $uploadFileDir = '../logo/';
        $dest_path = $uploadFileDir . $newFileName;
        if (move_uploaded_file($fileTmpPath, $dest_path)) {
            $data['logo'] = $newFileName;
        }
    }
    // Si aucun logo uploadé, logo par défaut
    if (empty($data['logo'])) {
        $data['logo'] = 'default_logo.jpg';
    }

    // Préparation des lignes de devis (nouveau format : JSON envoyé par AJAX)
    $lignes = [];
    if (isset($_POST['lignes'])) {
        $lignes = json_decode($_POST['lignes'], true);
        // On ne garde que les champs nécessaires : designation, prix, quantite, unite_id, total
        foreach ($lignes as &$ligne) {
            $ligne = [
                'designation' => $ligne['designation'],
                'prix'        => $ligne['prix'],
                'quantite'    => $ligne['quantite'],
                'unite_id'    => $ligne['unite_id'],
                'total'       => $ligne['total'],
                'groupe'      => isset($ligne['groupe']) ? $ligne['groupe'] : null,
            ];
        }
        unset($ligne);
    }

    try {
        if (trim((string)$data['numero_devis']) === '') {
            throw new DomainException('Le numéro du devis est obligatoire.');
        }
        if (trim((string)$data['date_emission']) === '') {
            throw new DomainException('La date d’émission est obligatoire.');
        }
        if (trim((string)$data['billing_date']) === '') {
            throw new DomainException('La date de facturation est obligatoire.');
        }
        if ($lignes === []) {
            throw new DomainException('Ajoutez au moins une ligne complète au devis.');
        }
        foreach ($lignes as $index => $ligne) {
            if (trim((string)($ligne['designation'] ?? '')) === ''
                || (float)($ligne['quantite'] ?? 0) <= 0
                || (float)($ligne['prix'] ?? 0) < 0
                || (int)($ligne['unite_id'] ?? 0) <= 0) {
                throw new DomainException('La ligne ' . ($index + 1) . ' est incomplète ou invalide.');
            }
        }
        $mode = strtoupper(trim((string)($_POST['devis_project_mode'] ?? 'NEW_PROJECT')));
        if ($mode === 'NEW_PROJECT' && (int)($data['client_id'] ?? 0) <= 0) {
            throw new DomainException('Sélectionnez le client du projet.');
        }
        $created = (new DevisOfferProjectController($pdo))->createQuote(
            $devisModel,
            new Offre($pdo),
            $data,
            $lignes,
            [
                'mode' => $_POST['devis_project_mode'] ?? 'NEW_PROJECT',
                'offer_number' => $_POST['new_offer_number'] ?? '',
                'offer_reference' => $_POST['new_offer_reference'] ?? '',
                'user_name' => $_SESSION['user_name'] ?? $_SESSION['username'] ?? '',
            ],
            (int) $_SESSION['user_id']
        );
        $devisId = $created['devis_id'];
        $data['offre_id'] = $created['offre_id'];
        $billingAlerts->schedule((int)$devisId, 'creation_devis', (int)($_SESSION['user_id'] ?? 0));

        \AuditTrail::log('devis_created', [
            'module' => 'request',
            'action' => 'generate_devis',
            'entity_type' => 'devis',
            'entity_id' => (string) $devisId,
            'entity_label' => (string) ($data['numero_devis'] !== '' ? $data['numero_devis'] : ('devis_' . $devisId)),
            'user_id' => $_SESSION['user_id'] ?? null,
            'user_name' => $_SESSION['user_name'] ?? $_SESSION['username'] ?? null,
            'details' => [
                'client_id' => $data['client_id'],
                'offre_id' => $data['offre_id'],
                'line_count' => count($lignes),
                'logo' => $data['logo'],
                'published' => (string) $data['publier_devis'],
            ],
        ], $pdo);

        $_SESSION['devisId'] = $devisId;
        echo json_encode([
            'ok' => true,
            'message' => 'Le devis a été enregistré et vérifié avec succès.',
            'devis_id' => (int)$devisId,
            'numero_devis' => (string)($created['numero_devis'] ?? $data['numero_devis']),
            'project_number' => (string)($created['project_number'] ?? ''),
            'line_count' => (int)($created['line_count'] ?? count($lignes)),
            'verified' => !empty($created['verified']),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $exception) {
        http_response_code(422);
        \AuditTrail::log('devis_creation_failed', [
            'module' => 'request',
            'action' => 'generate_devis',
            'entity_type' => 'devis',
            'entity_label' => (string)($data['numero_devis'] ?? ''),
            'user_id' => $_SESSION['user_id'] ?? null,
            'user_name' => $_SESSION['user_name'] ?? $_SESSION['username'] ?? null,
            'details' => ['error' => $exception->getMessage()],
        ], $pdo);
        echo json_encode([
            'ok' => false,
            'message' => $exception->getMessage(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
