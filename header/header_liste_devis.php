<?php

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/Devis.php';
require_once __DIR__ . '/../model/Client.php';
require_once __DIR__ . '/../model/Offre.php';
require_once __DIR__ . '/../model/UniteMesure.php';
require_once __DIR__ . '/../model/Chantier.php';
require_once __DIR__ . '/../controllers/DevisOfferProjectController.php';

$pdo = Database::getConnection();
$devisModel = new Devis($pdo);
$chantierModel = new Chantier($pdo);
$clientModel = new Client($pdo);
$offreModel = new Offre($pdo);
$uniteModel = new UniteMesure($pdo);

// Permission: peut-il modifier le déboursé ? (via session chargée par auth_check)
$canModifierDebourse = function_exists('canModifyDebourse') ? canModifyDebourse() : (!empty($_SESSION['can_modify_debourse']));

// Récupérer les filtres depuis $_GET (nouvelle recherche)
$filtres = [
    'q' => $_GET['q'] ?? null,
    'client_id' => $_GET['client_id'] ?? null,
    'correspondant' => $_GET['correspondant'] ?? null,
    'validation' => $_GET['validation'] ?? null,
    'chantier' => $_GET['chantier'] ?? null,
    'created_from' => $_GET['created_from'] ?? null,
    'created_to' => $_GET['created_to'] ?? null,
    'archive_status' => $_GET['archive_status'] ?? 'active',
    'billing_status' => $_GET['billing_status'] ?? null,
    'sort' => $_GET['sort'] ?? 'recent',
];

// Utiliser la nouvelle méthode du modèle pour récupérer les devis filtrés
$devis = $devisModel->searchDevis($filtres);
$devisOfferProjectController = new DevisOfferProjectController($pdo);
$devisGroups = $devisOfferProjectController->group($devis);
$canClassifyDevis = AccessManager::hasPermission('modifier_classement_devis');
$canCancelDevisBilling = AccessManager::hasPermission('acces_facturation')
    || AccessManager::hasPermission('acces_direction')
    || AccessManager::hasPermission('acces_admin');
if (empty($_SESSION['devis_offer_csrf'])) {
    $_SESSION['devis_offer_csrf'] = bin2hex(random_bytes(32));
}

// Calcul du montant total TTC des devis affichés
$total_ttc = 0;
$nb_devis = 0;
foreach ($devis as $de) {
    $total_ttc += $de['total_ttc'];
    $nb_devis++;
}


// Récupérer les offres via la classe
$offres = $offreModel->getAllOffres();

// Pour le formulaire de recherche
$clients = $clientModel->getAllClients();
$correspondants = $devisModel->getDistinctCorrespondants();

$page = "liste_devis";
