<?php
header('Content-Type: application/json');
session_start();
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/Dashboard.php';

try {
    $pdo = Database::getConnection();
    $dashboardModel = new Dashboard($pdo);

    $endpoint = $_GET['endpoint'] ?? '';

    switch ($endpoint) {
        case 'stats':
            echo json_encode($dashboardModel->getDonneesDashboard());
            break;

        case 'charts':
            echo json_encode($dashboardModel->getChartsData());
            break;

        case 'alertes':
            echo json_encode($dashboardModel->getAlertes());
            break;

        case 'refresh':
            // Rafraîchir toutes les données
            echo json_encode([
                'stats' => $dashboardModel->getDonneesDashboard(),
                'charts' => $dashboardModel->getChartsData(),
                'alertes' => $dashboardModel->getAlertes()
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Endpoint non valide']);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erreur serveur: ' . $e->getMessage()]);
}
