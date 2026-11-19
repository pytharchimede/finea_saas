<?php
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/Devis.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = Database::getConnection();
    $devisModel = new Devis($pdo);

    $chantierId = $_GET['chantier_id'] ?? null;
    $search = $_GET['search'] ?? null;
    $client = $_GET['client'] ?? null;

    $chantierId = ($chantierId !== null && trim((string)$chantierId) !== '') ? $chantierId : null;
    $search = ($search !== null && trim((string)$search) !== '') ? trim((string)$search) : null;
    $client = ($client !== null && trim((string)$client) !== '') ? trim((string)$client) : null;

    $debourses = $devisModel->getDeboursesFiltres($chantierId, $search, $client);
    $totalMontant = 0;
    foreach ($debourses as $d) {
        $totalMontant += (float)($d['montant_debourse'] ?? 0);
    }

    ob_start();
    foreach ($debourses as $deb) {
        include __DIR__ . '/../partials/debourse_card.php';
    }
    $html = (string)ob_get_clean();

    echo json_encode([
        'ok' => true,
        'nb_debourses' => count($debourses),
        'total_montant' => $totalMontant,
        'html' => $html,
    ]);
} catch (Throwable $e) {
    http_response_code(200);
    echo json_encode([
        'ok' => false,
        'error' => 'Erreur serveur',
    ]);
}
