<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../employe/includes/helpers.php';
require_once __DIR__ . '/../model/AuditTrail.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

$year = (int) ($_GET['year'] ?? 0);
if ($year < 2000 || $year > 2100) {
    $year = (int) date('Y');
}

$pdo = \Database::getConnection();

$userEmail = (string) ($_SESSION['mail_pro'] ?? '');
$personnel = employeeFindPersonnelProfile($pdo, $userEmail);
if (!$personnel) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Profil RH introuvable pour l'email: " . $userEmail;
    exit;
}

$pid = (int) ($personnel['personnel_id'] ?? 0);

$yearStart = sprintf('%04d-01-01', $year);
$yearEnd = sprintf('%04d-12-31', $year);

$stmt = $pdo->prepare('
    SELECT
        DATE_FORMAT(attendance_date, "%Y-%m") AS ym,
        SUM(worked_days) AS worked_days,
        SUM(overtime_hours) AS overtime_hours,
        SUM(attendance_status = "present") AS present_days,
        SUM(attendance_status = "mission") AS mission_days,
        SUM(attendance_status = "conge") AS conge_days,
        SUM(attendance_status = "absence") AS absence_days,
        SUM(attendance_status = "maladie") AS maladie_days
    FROM rh_attendance_daily
    WHERE personnel_id = :pid
      AND attendance_date BETWEEN :start AND :end
    GROUP BY ym
    ORDER BY ym ASC
');
$stmt->execute([
    'pid' => $pid,
    'start' => $yearStart,
    'end' => $yearEnd,
]);
$annualRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$byYm = [];
$totals = [
    'worked_days' => 0.0,
    'overtime_hours' => 0.0,
    'present_days' => 0,
    'mission_days' => 0,
    'conge_days' => 0,
    'absence_days' => 0,
    'maladie_days' => 0,
];

foreach ($annualRows as $r) {
    $ym = (string) ($r['ym'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
        continue;
    }

    $byYm[$ym] = [
        'worked_days' => (float) ($r['worked_days'] ?? 0),
        'overtime_hours' => (float) ($r['overtime_hours'] ?? 0),
        'present_days' => (int) ($r['present_days'] ?? 0),
        'mission_days' => (int) ($r['mission_days'] ?? 0),
        'conge_days' => (int) ($r['conge_days'] ?? 0),
        'absence_days' => (int) ($r['absence_days'] ?? 0),
        'maladie_days' => (int) ($r['maladie_days'] ?? 0),
    ];

    foreach ($totals as $k => $v) {
        $totals[$k] += $byYm[$ym][$k];
    }
}

$xlsx = new Spreadsheet();
$sheet = $xlsx->getActiveSheet();
$sheet->setTitle('Pointage annuel');

$row = 1;
$sheet->setCellValue('A' . $row, 'Pointage annuel — ' . $year);
$sheet->mergeCells("A{$row}:H{$row}");
$sheet->getStyle("A{$row}:H{$row}")->getFont()->setBold(true)->setSize(16);
$sheet->getStyle("A{$row}:H{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$row += 2;

$sheet->setCellValue('A' . $row, 'Profil');
$sheet->getStyle('A' . $row)->getFont()->setBold(true);
$row++;
$sheet->setCellValue('A' . $row, 'Nom');
$sheet->setCellValue('B' . $row, (string) ($personnel['full_name'] ?? '—'));
$row++;
$sheet->setCellValue('A' . $row, 'Fonction');
$sheet->setCellValue('B' . $row, (string) ($personnel['fonction_label'] ?? '—'));
$row++;
$sheet->setCellValue('A' . $row, 'Service');
$sheet->setCellValue('B' . $row, (string) ($personnel['service_label'] ?? '—'));
$row += 2;

$sheet->setCellValue('A' . $row, 'Indicateurs');
$sheet->getStyle('A' . $row)->getFont()->setBold(true);
$row++;
$sheet->setCellValue('A' . $row, 'Total jours');
$sheet->setCellValue('B' . $row, (float) $totals['worked_days']);
$row++;
$sheet->setCellValue('A' . $row, 'Total heures supp');
$sheet->setCellValue('B' . $row, (float) $totals['overtime_hours']);
$row++;
$sheet->setCellValue('A' . $row, 'Absences');
$sheet->setCellValue('B' . $row, (int) $totals['absence_days']);
$row++;
$sheet->setCellValue('A' . $row, 'Maladie');
$sheet->setCellValue('B' . $row, (int) $totals['maladie_days']);
$row += 2;

$head = $row;
$sheet->setCellValue('A' . $row, 'Mois');
$sheet->setCellValue('B' . $row, 'Jours');
$sheet->setCellValue('C' . $row, 'HS');
$sheet->setCellValue('D' . $row, 'Présent');
$sheet->setCellValue('E' . $row, 'Mission');
$sheet->setCellValue('F' . $row, 'Congé');
$sheet->setCellValue('G' . $row, 'Absence');
$sheet->setCellValue('H' . $row, 'Maladie');

$sheet->getStyle("A{$row}:H{$row}")->getFont()->setBold(true);
$sheet->getStyle("A{$row}:H{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EEF2FF');
$sheet->getStyle("A{$row}:H{$row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('CBD5E1');
$row++;

for ($mm = 1; $mm <= 12; $mm++) {
    $ym = sprintf('%04d-%02d', $year, $mm);
    $r = (array) ($byYm[$ym] ?? []);

    $sheet->setCellValue('A' . $row, $ym);
    $sheet->setCellValue('B' . $row, (float) ($r['worked_days'] ?? 0));
    $sheet->setCellValue('C' . $row, (float) ($r['overtime_hours'] ?? 0));
    $sheet->setCellValue('D' . $row, (int) ($r['present_days'] ?? 0));
    $sheet->setCellValue('E' . $row, (int) ($r['mission_days'] ?? 0));
    $sheet->setCellValue('F' . $row, (int) ($r['conge_days'] ?? 0));
    $sheet->setCellValue('G' . $row, (int) ($r['absence_days'] ?? 0));
    $sheet->setCellValue('H' . $row, (int) ($r['maladie_days'] ?? 0));
    $row++;
}

$totalRow = $row;
$sheet->setCellValue('A' . $totalRow, 'Total');
$sheet->setCellValue('B' . $totalRow, (float) $totals['worked_days']);
$sheet->setCellValue('C' . $totalRow, (float) $totals['overtime_hours']);
$sheet->setCellValue('D' . $totalRow, (int) $totals['present_days']);
$sheet->setCellValue('E' . $totalRow, (int) $totals['mission_days']);
$sheet->setCellValue('F' . $totalRow, (int) $totals['conge_days']);
$sheet->setCellValue('G' . $totalRow, (int) $totals['absence_days']);
$sheet->setCellValue('H' . $totalRow, (int) $totals['maladie_days']);
$sheet->getStyle("A{$totalRow}:H{$totalRow}")->getFont()->setBold(true);
$sheet->getStyle("A{$totalRow}:H{$totalRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F8FAFC');

$last = $totalRow;
$sheet->getStyle("A{$head}:H{$last}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('E2E8F0');
$sheet->getStyle("B" . ($head + 1) . ":C{$last}")->getNumberFormat()->setFormatCode('0.00');

foreach (['A' => 10, 'B' => 12, 'C' => 10, 'D' => 10, 'E' => 10, 'F' => 10, 'G' => 10, 'H' => 10] as $col => $w) {
    $sheet->getColumnDimension($col)->setWidth($w);
}

$baseName = preg_replace('/[^a-z0-9_-]+/i', '_', (string) ($personnel['full_name'] ?? 'employe'));
$fileName = 'pointage_annuel_' . $year . '_' . $baseName . '.xlsx';

\AuditTrail::logFileEvent('export', [
    'module' => 'request',
    'action' => 'export_pointage_employe_annual_excel',
    'entity_type' => 'attendance_annual_export',
    'entity_id' => (string) $pid,
    'entity_label' => (string) ($personnel['full_name'] ?? $fileName),
    'format' => 'xlsx',
    'user_id' => $_SESSION['user_id'] ?? null,
    'user_name' => $_SESSION['user_name'] ?? $_SESSION['username'] ?? null,
    'details' => [
        'year' => $year,
        'month_count' => count($byYm),
        'worked_days_total' => $totals['worked_days'],
        'overtime_total' => $totals['overtime_hours'],
        'absence_total' => $totals['absence_days'],
    ],
], $pdo);

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($xlsx);
$writer->save('php://output');
exit;
