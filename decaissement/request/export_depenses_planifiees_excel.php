<?php
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../model/Database.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="depenses_planifiees_' . date('Y-m-d') . '.xlsx"');

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
      AND (f.chantier_id IS NULL OR f.chantier_id=0 OR c.archived_at IS NULL)
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

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

$sheet->setCellValue('A1', 'N° Fiche');
$sheet->setCellValue('B1', 'Montant');
$sheet->setCellValue('C1', 'Date Décaissement Minimum');
$sheet->setCellValue('D1', 'Chantier');
$sheet->setCellValue('E1', 'Bénéficiaire');
$sheet->setCellValue('F1', 'Date Création');
$sheet->setCellValue('G1', 'Statut');

$row = 2;
foreach ($fiches as $fiche) {
    $statut = $fiche['date_decaissement_minimum'] < date('Y-m-d') ? 'En retard' : 'Planifié';
    $sheet->setCellValue('A' . $row, $fiche['num_fiche']);
    $sheet->setCellValue('B' . $row, $fiche['montant_fiche']);
    $sheet->setCellValue('C' . $row, $fiche['date_decaissement_minimum']);
    $sheet->setCellValue('D' . $row, $fiche['chantier']);
    $sheet->setCellValue('E' . $row, $fiche['beficiaire_fiche']);
    $sheet->setCellValue('F' . $row, $fiche['date_creation']);
    $sheet->setCellValue('G' . $row, $statut);
    $row++;
}

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
