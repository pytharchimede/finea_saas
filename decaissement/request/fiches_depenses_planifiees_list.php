<?php
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../model/Database.php';

header('Content-Type: application/json');

$pdo = Database::getConnection();

$filters = [
    'date_debut' => $_GET['date_debut'] ?? null,
    'date_fin' => $_GET['date_fin'] ?? null,
    'num_fiche' => $_GET['num_fiche'] ?? null,
    'montant_min' => $_GET['montant_min'] ?? null,
    'montant_max' => $_GET['montant_max'] ?? null,
    'chantier' => $_GET['chantier'] ?? null,
    'beneficiaire' => $_GET['beneficiaire'] ?? null,
    'date_creat_fiche' => $_GET['date_creat_fiche'] ?? null,
];

$query = "
    SELECT f.*, c.titre as chantier_banamur
    FROM fiche_decaissement f
    LEFT JOIN chantier_banamur c ON f.chantier_id = c.id
    WHERE f.date_decaissement_minimum IS NOT NULL
    AND (f.chantier_id IS NULL OR c.archived_at IS NULL)
    AND f.decaisse = 0
    AND f.etat_fiche != 2
";

$params = [];

if ($filters['date_debut']) {
    $query .= " AND f.date_decaissement_minimum >= ?";
    $params[] = $filters['date_debut'];
}

if ($filters['date_fin']) {
    $query .= " AND f.date_decaissement_minimum <= ?";
    $params[] = $filters['date_fin'];
}

if ($filters['num_fiche']) {
    $query .= " AND f.num_fiche LIKE ?";
    $params[] = '%' . $filters['num_fiche'] . '%';
}

if ($filters['montant_min']) {
    $query .= " AND f.montant_fiche >= ?";
    $params[] = $filters['montant_min'];
}

if ($filters['montant_max']) {
    $query .= " AND f.montant_fiche <= ?";
    $params[] = $filters['montant_max'];
}

if ($filters['chantier']) {
    $query .= " AND c.titre LIKE ?";
    $params[] = '%' . $filters['chantier'] . '%';
}

if ($filters['beneficiaire']) {
    $query .= " AND f.beficiaire_fiche LIKE ?";
    $params[] = '%' . $filters['beneficiaire'] . '%';
}

if ($filters['date_creat_fiche']) {
    $query .= " AND DATE(f.date_creation) = ?";
    $params[] = $filters['date_creat_fiche'];
}

$query .= " ORDER BY f.date_decaissement_minimum ASC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$fiches = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group by date for calendar view
$grouped = [];
foreach ($fiches as $fiche) {
    $date = $fiche['date_decaissement_minimum'];
    if (!isset($grouped[$date])) {
        $grouped[$date] = [
            'date' => $date,
            'total_montant' => 0,
            'count' => 0,
            'fiches' => []
        ];
    }
    $grouped[$date]['total_montant'] += $fiche['montant_fiche'];
    $grouped[$date]['count']++;
    $grouped[$date]['fiches'][] = $fiche;
}

echo json_encode([
    'fiches' => $fiches,
    'grouped_by_date' => array_values($grouped)
]);
