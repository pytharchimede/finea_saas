<?php
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../model/Database.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$date = $_GET['date'] ?? null;
if (!$date) {
    die('Date requise');
}

$pdo = Database::getConnection();

// Get fiches for this date
$query = "
    SELECT f.*, c.titre as chantier_banamur
    FROM fiche_decaissement f
    LEFT JOIN chantier_banamur c ON f.chantier_id = c.id
    WHERE f.date_decaissement_minimum = ?
      AND (f.chantier_id IS NULL OR f.chantier_id=0 OR c.archived_at IS NULL)
    AND f.decaisse = 0
    AND f.etat_fiche != 2
    ORDER BY f.num_fiche ASC
";

$stmt = $pdo->prepare($query);
$stmt->execute([$date]);
$fiches = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total = array_sum(array_column($fiches, 'montant_fiche'));
$count = count($fiches);

// Create spreadsheet
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

$sheet->setTitle('Récapitulatif ' . date('d/m/Y', strtotime($date)));

// Headers
$sheet->setCellValue('A1', 'RÉCAPITULATIF DÉPENSES PLANIFIÉES');
$sheet->mergeCells('A1:E1');
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

$sheet->setCellValue('A3', 'Date:');
$sheet->setCellValue('B3', date('d/m/Y', strtotime($date)));
$sheet->setCellValue('A4', 'Nombre de fiches:');
$sheet->setCellValue('B4', $count);
$sheet->setCellValue('A5', 'Total:');
$sheet->setCellValue('B5', number_format($total, 0, ',', ' ') . ' CFA');

// Table headers
$sheet->setCellValue('A7', 'N° Fiche');
$sheet->setCellValue('B7', 'Montant');
$sheet->setCellValue('C7', 'Chantier');
$sheet->setCellValue('D7', 'Bénéficiaire');
$sheet->setCellValue('E7', 'Statut');

$sheet->getStyle('A7:E7')->getFont()->setBold(true);
$sheet->getStyle('A7:E7')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('E5E7EB');

// Data
$row = 8;
foreach ($fiches as $fiche) {
    $sheet->setCellValue('A' . $row, $fiche['num_fiche']);
    $sheet->setCellValue('B' . $row, $fiche['montant_fiche']);
    $sheet->setCellValue('C' . $row, $fiche['chantier_banamur'] ?: 'N/A');
    $sheet->setCellValue('D' . $row, $fiche['beficiaire_fiche'] ?: 'N/A');
    $sheet->setCellValue('E' . $row, $fiche['date_decaissement_minimum'] < date('Y-m-d') ? 'En retard' : 'Planifié');
    $row++;
}

// Auto-size columns
foreach (range('A', 'E') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// Output
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="recap_depenses_' . $date . '.xlsx"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
