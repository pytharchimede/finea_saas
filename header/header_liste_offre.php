<?php
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/Offre.php';

$pdo = Database::getConnection();
$offreModel = new Offre($pdo);

$filters = [
    'q' => $_GET['q'] ?? null,
    'commercial_dedie' => $_GET['commercial_dedie'] ?? null,
    'date_offre_from' => $_GET['date_offre_from'] ?? null,
    'date_offre_to' => $_GET['date_offre_to'] ?? null,
    'created_from' => $_GET['created_from'] ?? null,
    'created_to' => $_GET['created_to'] ?? null,
];

$offers = $offreModel->searchOffres($filters);
$nb_offres = count($offers);

$commercialsList = $offreModel->getDistinctCommercials();

$page = "liste_offre";
