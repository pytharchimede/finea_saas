<?php
require_once dirname(__DIR__, 2) . '/auth_check.php';
require_once dirname(__DIR__) . '/model/Database.php';
require_once dirname(__DIR__) . '/model/ProjectPlanningImport.php';
require_once dirname(__DIR__) . '/includes/planning_dashboard_shared.php';
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/model/AuditTrail.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

$filters = planningDashboardNormalizeFilters($_GET);
$pdo = \Database::getConnection();
$planningModel = new \ProjectPlanningImport($pdo);
$planningRows = $planningModel->getPlanningDashboardSummaries($filters);
$filterOptions = $planningModel->getPlanningDashboardFilterOptions();
$overview = planningDashboardComputeOverview($planningRows);

$selectedConductorLabel = 'Tous les conducteurs';
foreach (($filterOptions['conductors'] ?? []) as $conductorOption) {
    if ((int)($conductorOption['id'] ?? 0) === (int)($filters['conductor_id'] ?? 0)) {
        $selectedConductorLabel = trim((string)($conductorOption['name'] ?? ''));
        if (trim((string)($conductorOption['service_label'] ?? '')) !== '') {
            $selectedConductorLabel .= ' - ' . trim((string)$conductorOption['service_label']);
        }
        break;
    }
}

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Dashboard planning');

$sheet->mergeCells('A1:N1');
$sheet->setCellValue('A1', 'DASHBOARD PLANNING CHANTIER');
$sheet->mergeCells('A2:N2');
$sheet->setCellValue('A2', 'Genere le ' . date('d/m/Y H:i:s'));
$sheet->mergeCells('A3:N3');
$sheet->setCellValue('A3', 'Filtres - Reunion: ' . (!empty($filters['meeting_date']) ? planningDashboardFormatDate((string)$filters['meeting_date']) : 'Toutes') . ' | Conducteur: ' . $selectedConductorLabel);

$sheet->fromArray([
    ['Chantiers dans la vue', (int)($overview['total_chantiers'] ?? 0), 'Avec planning', (int)($overview['with_import_count'] ?? 0), 'Planifie global', (float)($overview['global_planned_progress'] ?? 0) . '%', 'Reel global', (float)($overview['global_actual_progress'] ?? 0) . '%'],
    ['Budget reference', (float)($overview['budget_reference_total'] ?? 0), 'Depenses', (float)($overview['budget_depense_total'] ?? 0), 'Conso budget', (float)($overview['global_budget_consumption'] ?? 0) . '%', 'Retards', (int)($overview['delayed_projects'] ?? 0)],
], null, 'A5');

$headerRow = 9;
$headers = [
    'Code',
    'Chantier',
    'Conducteurs',
    'Etat',
    'Source planning',
    'Debut',
    'Fin',
    'Planifie %',
    'Reel %',
    'Budget ref',
    'Depenses',
    'Conso budget %',
    'Derniere reunion',
    'Lien detail',
];

$sheet->fromArray($headers, null, 'A' . $headerRow);

$rowIndex = $headerRow + 1;
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$appBase = rtrim(dirname(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');

foreach ($planningRows as $row) {
    $detailUrl = $scheme . '://' . $host . $appBase . '/decaissement/detail_travaux_chantier.php?chantier_id=' . urlencode((string)($row['chantier_id'] ?? 0));

    $sheet->fromArray([
        (string)($row['code'] ?? ''),
        (string)($row['libelle'] ?? ''),
        (string)($row['conductor_label'] ?? 'Non affecte'),
        (string)($row['health_label'] ?? ''),
        (string)($row['source_label'] ?? ''),
        planningDashboardFormatDate($row['planning_start'] ?? null),
        planningDashboardFormatDate($row['planning_end'] ?? null),
        (float)($row['planned_progress'] ?? 0),
        (float)($row['actual_progress'] ?? 0),
        (float)($row['budget_reference'] ?? 0),
        (float)($row['total_depenses'] ?? 0),
        (float)($row['budget_consumption_percent'] ?? 0),
        planningDashboardFormatDate($row['last_meeting_date'] ?? null),
        $detailUrl,
    ], null, 'A' . $rowIndex);

    $rowIndex++;
}

$sheet->getStyle('A1:N3')->getFont()->setBold(true);
$sheet->getStyle('A1')->getFont()->setSize(16);
$sheet->getStyle('A5:H6')->getFont()->setBold(true);
$sheet->getStyle('A' . $headerRow . ':N' . $headerRow)->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
$sheet->getStyle('A' . $headerRow . ':N' . $headerRow)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF0F766E');
$sheet->getStyle('A' . $headerRow . ':N' . max($headerRow, $rowIndex - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
$sheet->getStyle('A1:N' . max($headerRow, $rowIndex - 1))->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
$sheet->getStyle('H' . ($headerRow + 1) . ':L' . max($headerRow + 1, $rowIndex - 1))->getNumberFormat()->setFormatCode('0.0');

foreach (range('A', 'N') as $column) {
    $sheet->getColumnDimension($column)->setAutoSize(true);
}

$filename = 'dashboard_planning_' . date('Ymd_His') . '.xlsx';

\AuditTrail::logFileEvent('export', [
    'module' => 'planning',
    'action' => 'dashboard_planning_export',
    'entity_type' => 'planning_dashboard',
    'entity_label' => 'Dashboard planning chantier',
    'format' => 'excel',
    'details' => [
        'meeting_date' => $filters['meeting_date'] ?? null,
        'conductor_id' => $filters['conductor_id'] ?? null,
        'row_count' => is_countable($planningRows ?? null) ? count($planningRows) : null,
    ],
], $pdo);

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
