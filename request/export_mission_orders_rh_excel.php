<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../controllers/RhMissionController.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function missionExportStatusLabel(string $status, array $labels): string
{
    $status = trim($status);
    return $labels[$status] ?? ($status !== '' ? $status : '-');
}

function missionExportDate(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '' || $value === '0000-00-00') {
        return '-';
    }
    $ts = strtotime($value);
    return $ts ? date('d/m/Y', $ts) : $value;
}

function missionExportMoney(float $value): string
{
    return number_format($value, 0, ',', ' ') . ' FCFA';
}

function missionExpenseTotal(array $lines): float
{
    $total = 0.0;
    foreach ($lines as $line) {
        $total += (float) ($line['line_total'] ?? 0);
    }
    return $total;
}

function missionLinkLabel(array $mission): string
{
    $type = trim((string) ($mission['link_type'] ?? ''));
    if ($type === 'chantier') {
        $code = trim((string) ($mission['chantier_code'] ?? ''));
        $title = trim((string) ($mission['chantier_title'] ?? ''));
        return trim($code . ($title !== '' ? ' - ' . $title : ''));
    }
    if ($type === 'prospection') {
        return 'Prospection';
    }
    if ($type === 'autre') {
        return trim((string) ($mission['link_label'] ?? ''));
    }
    return '-';
}

$pdo = \Database::getConnection();
$controller = new \RhMissionController($pdo);
$manager = $controller->missions();
$statusLabels = $manager->getStatusLabels();

$filters = [
    'q' => trim((string) ($_GET['q'] ?? '')),
    'status' => trim((string) ($_GET['status'] ?? '')),
];

$result = $manager->listMissions($filters, 1, 5000);
$missions = $result['items'] ?? [];

$chantierModel = $controller->chantiers();
foreach ($missions as &$mission) {
    $chantierId = (int) ($mission['chantier_id'] ?? 0);
    if ($chantierId > 0) {
        $chantier = $chantierModel->getById($chantierId);
        if (is_array($chantier)) {
            $mission['chantier_code'] = (string) ($chantier['code'] ?? '');
            $mission['chantier_title'] = (string) ($chantier['titre'] ?? '');
        }
    }
}
unset($mission);

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Ordres mission RH');

$sheet->setCellValue('A1', 'Récapitulatif des ordres de mission RH');
$sheet->mergeCells('A1:L1');
$sheet->setCellValue('A2', 'Export du');
$sheet->setCellValue('B2', date('d/m/Y H:i'));

$headers = ['Code', 'Statut', 'Collaborateur', 'Matricule', 'Service', 'Fonction', 'Objet', 'Destination', 'Période', 'Liaison', 'Total devis', 'Notes'];
$col = 'A';
foreach ($headers as $header) {
    $sheet->setCellValue($col . '4', $header);
    $col++;
}

$rowNum = 5;
foreach ($missions as $mission) {
    $expenses = $manager->getMissionExpenses((int) ($mission['id'] ?? 0));
    $sheet->setCellValue('A' . $rowNum, (string) ($mission['mission_code'] ?? ''));
    $sheet->setCellValue('B' . $rowNum, missionExportStatusLabel((string) ($mission['status'] ?? ''), $statusLabels));
    $sheet->setCellValue('C' . $rowNum, (string) ($mission['personnel_name'] ?? ''));
    $sheet->setCellValue('D' . $rowNum, (string) ($mission['personnel_matricule'] ?? ''));
    $sheet->setCellValue('E' . $rowNum, (string) ($mission['service_label'] ?? ''));
    $sheet->setCellValue('F' . $rowNum, (string) ($mission['fonction_label'] ?? ''));
    $sheet->setCellValue('G' . $rowNum, (string) ($mission['mission_title'] ?? ''));
    $sheet->setCellValue('H' . $rowNum, (string) ($mission['destination'] ?? ''));
    $sheet->setCellValue('I' . $rowNum, missionExportDate((string) ($mission['start_date'] ?? '')) . ' -> ' . missionExportDate((string) ($mission['end_date'] ?? '')));
    $sheet->setCellValue('J' . $rowNum, missionLinkLabel($mission));
    $sheet->setCellValue('K' . $rowNum, missionExportMoney(missionExpenseTotal($expenses)));
    $sheet->setCellValue('L' . $rowNum, (string) ($mission['notes'] ?? ''));
    $rowNum++;
}

foreach (range('A', 'L') as $column) {
    $sheet->getColumnDimension($column)->setAutoSize(true);
}

$filename = 'recap_ordres_mission_rh_' . date('Ymd_His') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
