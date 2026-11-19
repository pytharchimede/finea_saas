<?php
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/FicheDecaissement.php';
header('Content-Type: application/json');
$num = $_GET['num_fiche'] ?? '';
if (!$num) {
    echo json_encode(['ok' => false, 'error' => 'num_fiche requis']);
    exit;
}
try {
    if (!isset($pdo) || !$pdo) { // si auth_check ne fournit pas $pdo
        $pdo = Database::getConnection();
    }
    $m = new FicheDecaissement($pdo);
    $data = $m->getHierarchy($num);
    echo json_encode(['ok' => true, 'data' => $data]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
