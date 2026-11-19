<?php
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/Client.php';

$pdo = Database::getConnection();
$clientModel = new Client($pdo);

$filters = [
    'q' => $_GET['q'] ?? null,
    'pays' => $_GET['pays'] ?? null,
    'commune' => $_GET['commune'] ?? null,
    'created_from' => $_GET['created_from'] ?? null,
    'created_to' => $_GET['created_to'] ?? null,
];

$clients = $clientModel->searchClients($filters);
$nb_clients = count($clients);

$paysList = $clientModel->getDistinctPays();
$communesList = $clientModel->getDistinctCommunes();


$page = "liste_client";
