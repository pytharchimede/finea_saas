<?php
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/Client.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = Database::getConnection();
    $clientModel = new Client($pdo);

    $filters = [
        'status' => in_array($_GET['status']??'', ['verified','pending'],true)?$_GET['status']:null,
        'q' => isset($_GET['q']) ? trim((string)$_GET['q']) : null,
        'pays' => isset($_GET['pays']) ? (string)$_GET['pays'] : null,
        'commune' => isset($_GET['commune']) ? (string)$_GET['commune'] : null,
        'created_from' => isset($_GET['created_from']) ? (string)$_GET['created_from'] : null,
        'created_to' => isset($_GET['created_to']) ? (string)$_GET['created_to'] : null,
    ];

    foreach (['q', 'pays', 'commune', 'created_from', 'created_to'] as $k) {
        if (isset($filters[$k]) && $filters[$k] !== null) {
            $filters[$k] = trim((string)$filters[$k]);
            if ($filters[$k] === '') {
                $filters[$k] = null;
            }
        }
    }

    $clients = $clientModel->searchClients($filters);

    ob_start();
    foreach ($clients as $client) {
        include __DIR__ . '/../partials/client_card.php';
    }
    $html = (string)ob_get_clean();

    echo json_encode([
        'ok' => true,
        'nb_clients' => count($clients),
        'html' => $html,
    ]);
} catch (Throwable $e) {
    http_response_code(200);
    echo json_encode([
        'ok' => false,
        'error' => 'Erreur serveur',
    ]);
}

