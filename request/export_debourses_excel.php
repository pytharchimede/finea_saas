<?php
require_once __DIR__.'/../auth_check.php';
AccessManager::requirePageAccess('liste_debourse.php');
require_once __DIR__.'/../debourse/controllers/DebourseDashboardController.php';
require_once '../vendor/autoload.php';
require_once __DIR__.'/../model/CompanyExportBranding.php';
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

$debourses = (new \Debourse\Controllers\DebourseDashboardController($pdo))->listing($chantierId===null?null:(int)$chantierId,(string)$search,(string)$client)['rows'] ?? [];

$spreadsheet = new Spreadsheet();
$spreadsheet->getProperties()->setCreator(CompanyExportBranding::creator())->setTitle('Liste des déboursés');
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Debourses');

// En-tête
$headers = ['ID', 'Devis', 'Désignation', 'Chantier', 'Client', 'Date début', 'Date fin', 'Montant (FCFA)'];
$sheet->fromArray($headers, null, 'A1');

$sheet->getStyle('A1:H1')->getFont()->setBold(true);
$sheet->getStyle('A1:H1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(CompanyExportBranding::rgb('primary'));

$sheet->getStyle('A1:H1')->getFont()->getColor()->setRGB(CompanyExportBranding::rgb('primary',true));
$sheet->freezePane('A2');
$row = 2;
$total = 0.0;
foreach ($debourses as $d) {
    $montant = (float)($d['montant_debourse'] ?? 0);
    $total += $montant;

    $sheet->setCellValue('A' . $row, (int)($d['id'] ?? 0));
    $sheet->setCellValueExplicit('B' . $row, (string)($d['numero_devis'] ?? ''), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $sheet->setCellValueExplicit('C' . $row, (string)($d['designation'] ?? ''), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $sheet->setCellValueExplicit('D' . $row, (string)($d['titre'] ?? ''), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $sheet->setCellValueExplicit('E' . $row, (string)($d['nom_client'] ?? ''), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $sheet->setCellValueExplicit('F' . $row, (string)($d['date_debut'] ?? ''), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $sheet->setCellValueExplicit('G' . $row, (string)($d['date_fin'] ?? ''), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
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
