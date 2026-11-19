<?php
session_start();
require_once '../vendor/autoload.php';
require_once '../model/Database.php';
require_once '../model/AuditTrail.php';
require_once '../model/Devis.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

$chantierId = $_GET['chantier_id'] ?? null;
$search = $_GET['search'] ?? null;
$client = $_GET['client'] ?? null;

$chantierId = ($chantierId !== null && trim((string)$chantierId) !== '') ? $chantierId : null;
$search = ($search !== null && trim((string)$search) !== '') ? trim((string)$search) : null;
$client = ($client !== null && trim((string)$client) !== '') ? trim((string)$client) : null;

$pdo = Database::getConnection();
$devisModel = new Devis($pdo);

$debourses = $devisModel->getDeboursesFiltres($chantierId, $search, $client);

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Debourses');

// En-tête
$headers = ['ID', 'Devis', 'Désignation', 'Chantier', 'Client', 'Date début', 'Date fin', 'Montant (FCFA)'];
$sheet->fromArray($headers, null, 'A1');

$sheet->getStyle('A1:H1')->getFont()->setBold(true);
$sheet->getStyle('A1:H1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EEF2FF');

$row = 2;
$total = 0.0;
foreach ($debourses as $d) {
    $montant = (float)($d['montant_debourse'] ?? 0);
    $total += $montant;

    $sheet->setCellValue('A' . $row, (int)($d['id'] ?? 0));
    $sheet->setCellValue('B' . $row, (string)($d['numero_devis'] ?? ''));
    $sheet->setCellValue('C' . $row, (string)($d['designation'] ?? ''));
    $sheet->setCellValue('D' . $row, (string)($d['titre'] ?? ''));
    $sheet->setCellValue('E' . $row, (string)($d['nom_client'] ?? ''));
    $sheet->setCellValue('F' . $row, (string)($d['date_debut'] ?? ''));
    $sheet->setCellValue('G' . $row, (string)($d['date_fin'] ?? ''));
    $sheet->setCellValue('H' . $row, $montant);
    $row++;
}

// Total
$sheet->setCellValue('G' . $row, 'TOTAL');
$sheet->setCellValue('H' . $row, $total);
$sheet->getStyle('G' . $row . ':H' . $row)->getFont()->setBold(true);

// Format montants
$sheet->getStyle('H2:H' . $row)->getNumberFormat()->setFormatCode('#,##0');

// Autosize
foreach (range('A', 'H') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// Bordures
$sheet->getStyle('A1:H' . $row)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

$fileName = 'debourses_liste_' . date('Ymd_His') . '.xlsx';
\AuditTrail::logFileEvent('export', [
    'module' => 'decaissement',
    'action' => 'debourse_list_export',
    'entity_type' => 'debourse_list',
    'entity_label' => 'Liste des déboursés',
    'format' => 'excel',
    'details' => [
        'chantier_id' => $chantierId,
        'search' => $search,
        'client' => $client,
        'row_count' => is_countable($debourses ?? null) ? count($debourses) : null,
        'total' => $total,
    ],
], $pdo);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $fileName . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
