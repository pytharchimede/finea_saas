<?php
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/Offre.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = Database::getConnection();
    $offreModel = new Offre($pdo);

    $filters = [
        'q' => isset($_GET['q']) ? trim((string)$_GET['q']) : null,
        'commercial_dedie' => isset($_GET['commercial_dedie']) ? (string)$_GET['commercial_dedie'] : null,
        'date_offre_from' => isset($_GET['date_offre_from']) ? (string)$_GET['date_offre_from'] : null,
        'date_offre_to' => isset($_GET['date_offre_to']) ? (string)$_GET['date_offre_to'] : null,
        'created_from' => isset($_GET['created_from']) ? (string)$_GET['created_from'] : null,
        'created_to' => isset($_GET['created_to']) ? (string)$_GET['created_to'] : null,
    ];

    foreach (array_keys($filters) as $k) {
        if ($filters[$k] !== null) {
            $filters[$k] = trim((string)$filters[$k]);
            if ($filters[$k] === '') {
                $filters[$k] = null;
            }
        }
    }

    $offers = $offreModel->searchOffres($filters);

    ob_start();
    foreach ($offers as $offer) {
        include __DIR__ . '/../partials/offre_card.php';
    }
    $html = (string)ob_get_clean();

    echo json_encode([
        'ok' => true,
        'nb_offres' => count($offers),
        'html' => $html,
    ]);
} catch (Throwable $e) {
    http_response_code(200);
    echo json_encode([
        'ok' => false,
        'error' => 'Erreur serveur',
    ]);
}
