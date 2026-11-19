<?php
session_start();
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../../model/AuditTrail.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        AuditTrail::log('decaissement_personnel_search_failed', [
            'module' => 'decaissement',
            'action' => 'search_personnel',
            'entity_type' => 'personnel_search',
            'result' => 'failure',
            'severity' => 'warning',
            'details' => ['reason' => 'invalid_method'],
        ]);
        echo json_encode(['success' => false, 'message' => 'Méthode non autorisée']);
        exit;
    }

    $query = trim((string)($_POST['q'] ?? ''));
    if ($query === '' || mb_strlen($query) < 2) {
        AuditTrail::log('decaissement_personnel_search_short', [
            'module' => 'decaissement',
            'action' => 'search_personnel',
            'entity_type' => 'personnel_search',
            'entity_label' => $query,
            'details' => ['query_length' => mb_strlen($query)],
        ]);
        echo json_encode(['success' => true, 'results' => []]);
        exit;
    }

    $pdo = Database::getConnection();

    $columns = $pdo->query('SHOW COLUMNS FROM personnel')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $colSet = [];
    foreach ($columns as $col) {
        $name = (string)($col['Field'] ?? '');
        if ($name !== '') {
            $colSet[$name] = true;
        }
    }

    $hasDateSortie = isset($colSet['date_sortie']);
    $phoneExpr = isset($colSet['tel_personnel_soignant']) ? 'p.tel_personnel_soignant' : "''";

    $where = 'WHERE p.nom_personnel_soignant LIKE :q';
    if ($hasDateSortie) {
        $where .= " AND (p.date_sortie IS NULL OR p.date_sortie = '0000-00-00')";
    }

    $sql = "
        SELECT
            p.id_personnel_soignant AS id,
            p.nom_personnel_soignant AS full_name,
            {$phoneExpr} AS phone
        FROM personnel p
        {$where}
        ORDER BY p.nom_personnel_soignant ASC
        LIMIT 15
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':q' => '%' . $query . '%']);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $results = [];
    foreach ($rows as $row) {
        $id = (int)($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $results[] = [
            'id' => $id,
            'full_name' => (string)($row['full_name'] ?? ''),
            'phone' => (string)($row['phone'] ?? ''),
        ];
    }

    echo json_encode(['success' => true, 'results' => $results]);
} catch (Throwable $e) {
    AuditTrail::log('decaissement_personnel_search_failed', [
        'module' => 'decaissement',
        'action' => 'search_personnel',
        'entity_type' => 'personnel_search',
        'entity_label' => isset($query) ? $query : 'search_personnel',
        'result' => 'failure',
        'severity' => 'warning',
        'details' => ['error' => $e->getMessage()],
    ], isset($pdo) && $pdo instanceof PDO ? $pdo : null);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

if (isset($query) && $query !== '' && isset($pdo) && $pdo instanceof PDO && isset($results)) {
    AuditTrail::log('decaissement_personnel_search_done', [
        'module' => 'decaissement',
        'action' => 'search_personnel',
        'entity_type' => 'personnel_search',
        'entity_label' => $query,
        'details' => ['result_count' => count($results)],
    ], $pdo);
}
