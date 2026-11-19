<?php
header('Content-Type: application/json');
session_start();
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/FicheDecaissement.php';

$num = $_GET['num_fiche'] ?? null;
if (!$num) {
    echo json_encode(['ok' => false, 'error' => 'num_fiche manquant']);
    exit;
}
try {
    $pdo = Database::getConnection();
    $model = new FicheDecaissement($pdo);
    $data = $model->analyseBudgetFiche($num);
    if (!$data) {
        echo json_encode(['ok' => false, 'error' => 'Fiche ou ligne introuvable']);
        exit;
    }
    echo json_encode(['ok' => true, 'analyse' => $data]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
