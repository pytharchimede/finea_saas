<?php

require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/UniteMesure.php';

$pdo = Database::getConnection();
$uniteModel = new UniteMesure($pdo);

$filters = [
    'q' => $_GET['q'] ?? null,
];

$unites = $uniteModel->search($filters);
$nb_unites = count($unites);


$page = "liste_unite";
