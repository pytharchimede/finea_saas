<?php
//Importation des fichiers nécessaires
include '../fpdf186/fpdf.php';
require_once "../phpqrcode/qrlib.php";
require_once "../model/User.php";
require_once "../model/Database.php";
require_once "../model/Devis.php";
require_once "../model/Client.php";
require_once "../model/Offre.php";
require_once "../model/UniteMesure.php";

$pdo = Database::getConnection();
$userObj = new User($pdo);
$devisObj = new Devis($pdo);
$clientObj = new Client($pdo);
$offreObj = new Offre($pdo);

$directeurTechnique = $userObj->findDirecteurTechnique();
$directeurGeneral = $userObj->findDirecteurGeneral();

// Vérifiez que le devisId est défini dans la session ou dans l'URL
if (!isset($_SESSION['devisId']) && !isset($_GET['devisId'])) {
    die('ID de devis non défini.');
}

// Prioriser l'ID du devis reçu via $_GET
if (isset($_GET['devisId'])) {
    $devisId = $_GET['devisId'];
    $_SESSION['devisId'] = $devisId;
} else {
    $devisId = $_SESSION['devisId'];
}

// Récupérer les données du devis via la classe
$devis = $devisObj->getDevisById($devisId);
if (!$devis) {
    die('Devis non trouvé.');
}

// Récupérer les lignes du devis via la classe
$lignes = $devisObj->getLignesDevis($devisId);
if (!$lignes) {
    die('Aucune ligne de devis trouvée pour ce devis.');
}

// Récupérer les données de déboursé via la classe
$debourses = $devisObj->getDeboursesByDevis($devisId);
if (!$debourses) {
    die('Aucun déboursé trouvé pour ce devis.');
}

// Les exports doivent respecter l'ordre naturel des lignes (haut → bas).
// getDeboursesByDevis() renvoie un tableau indexé par ligne_devis_id : on impose un tri numérique ascendant.
if (is_array($debourses)) {
    ksort($debourses, SORT_NUMERIC);
}

// Récupérer les lignes de déboursé via la classe
// Initialiser un tableau pour stocker les lignes de déboursé
$lignes_debourse = [];
foreach ($debourses as $debourse) {
    // Récupérer la désignation de la ligne_devis liée à ce déboursé
    $designation = '';
    if (!empty($debourse['ligne_devis_id'])) {
        foreach ($lignes as $ligne_devis) {
            if ($ligne_devis['id'] == $debourse['ligne_devis_id']) {
                $designation = $ligne_devis['designation'];
                break;
            }
        }
    }
    // Utiliser la désignation comme titre
    $lignes_debourse[] = [
        'is_titre' => true,
        'titre' => $designation ?: 'Déboursé'
    ];
    // Récupérer les lignes du déboursé courant
    $lignes_deb = $devisObj->getLignesDebourse($debourse['id']);
    if ($lignes_deb && is_array($lignes_deb) && count($lignes_deb) > 0) {
        // Sécurise l'ordre des sous-lignes (haut → bas)
        usort($lignes_deb, static function ($a, $b) {
            return (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0);
        });

        $num = 1;
        foreach ($lignes_deb as $ligne) {
            // Fallback: si les dates des sous-lignes ne sont pas renseignées,
            // on récupère celles du déboursé parent pour permettre le planning.
            if (empty($ligne['date_debut']) && !empty($debourse['date_debut'])) {
                $ligne['date_debut'] = $debourse['date_debut'];
            }
            if (empty($ligne['date_fin']) && !empty($debourse['date_fin'])) {
                $ligne['date_fin'] = $debourse['date_fin'];
            }

            $ligne['numero'] = $num++;
            $ligne['is_titre'] = false;
            $lignes_debourse[] = $ligne;
        }
    } else {
        // Aucun détail: on crée une ligne unique basée sur la période du déboursé parent.
        $fallbackDesignation = $designation ?: 'Déboursé';
        $lignes_debourse[] = [
            'is_titre' => false,
            'numero' => 1,
            'categorie' => '',
            'designation' => $fallbackDesignation,
            'montant' => $debourse['montant_debourse'] ?? 0,
            'date_debut' => $debourse['date_debut'] ?? null,
            'date_fin' => $debourse['date_fin'] ?? null,
        ];
    }
}

// Si aucune ligne trouvée
if (empty($lignes_debourse)) {
    die('Aucune ligne de déboursé trouvée pour ce devis.');
}

// Récupérer le client via la classe
$client = $clientObj->getClientById($devis['client_id']);
if (!$client) {
    die('Client non trouvé.');
}

// Récupérer l'offre via la classe
$offre = $offreObj->getOffreById($devis['offre_id']);
if (!$offre) {
    die('Projet non trouvé.');
}

$uniteModel = new UniteMesure($pdo);
$unitesArray = [];
foreach ($uniteModel->getAll() as $u) {
    $unitesArray[$u['id']] = $u;
}
