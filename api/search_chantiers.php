<?php
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/Chantier.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
    $clientId = $_GET['client_id'] ?? null;
    $archives = !empty($_GET['archives']);

    $filters = [
        'q' => ($q !== '') ? $q : null,
        'client_id' => ($clientId !== null && trim((string)$clientId) !== '') ? (int)$clientId : null,
        'archives' => $archives,
    ];

    $pdo = Database::getConnection();
    $chantierModel = new Chantier($pdo);

    $rows = $chantierModel->searchChantiers($filters);

    ob_start();
    foreach ($rows as $chantier) {
        include __DIR__ . '/../partials/chantier_card.php';
    }
    $html = ob_get_clean();

    echo json_encode([
        'ok' => true,
        'nb_chantiers' => count($rows),
        'html' => $html,
    ]);
} catch (Throwable $e) {
    error_log('[search_chantiers] ' . $e->getMessage());
    echo json_encode([
        'ok' => false,
        'error' => 'Erreur serveur',
    ]);
}
