<?php

require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/Chantier.php';

$pdo = Database::getConnection();
$chantierModel = new Chantier($pdo);

$filters = [
    'q' => $_GET['q'] ?? null,
    'client_id' => $_GET['client_id'] ?? null,
    'archives' => !empty($_GET['archives']),
];

$chantiers = $chantierModel->searchChantiers($filters);
$nb_chantiers = count($chantiers);

// Pour le filtre client (tous statuts, afin de rester stable quand on bascule Actifs/Archives)
$clientsList = $chantierModel->getDistinctClients(null);

$page = "liste_chantier";
