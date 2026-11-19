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

$period = (string) ($_GET['period'] ?? '');
if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
    $period = date('Y-m');
}

[$y, $m] = array_map('intval', explode('-', $period));
$start = sprintf('%04d-%02d-01', $y, $m);
$end = date('Y-m-t', strtotime($start));

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

$stmt = $pdo->prepare('
    SELECT attendance_date, attendance_status, worked_days, overtime_hours, notes
    FROM rh_attendance_daily
    WHERE personnel_id = :pid
      AND attendance_date BETWEEN :start AND :end
    ORDER BY attendance_date ASC
');
$stmt->execute([
    'pid' => $pid,
    'start' => $start,
    'end' => $end,
]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$statuses = [
    'present' => 'Présent',
    'half_day' => 'Demi-journée',
    'mission' => 'Mission',
    'conge' => 'Congé',
    'repos' => 'Repos',
    'absence' => 'Absent',
    'maladie' => 'Maladie',
];

$summaryDays = 0.0;
$summaryOvertime = 0.0;
foreach ($rows as $r) {
    $summaryDays += (float) ($r['worked_days'] ?? 0);
    $summaryOvertime += (float) ($r['overtime_hours'] ?? 0);
}

$unjustifiedAbsences = employeeListUnjustifiedAbsences($pdo, $pid, 60);

$xlsx = new Spreadsheet();
$sheet = $xlsx->getActiveSheet();
$sheet->setTitle('Pointage');

$r = 1;

$sheet->setCellValue('A' . $r, 'Pointage — ' . $period);
$sheet->mergeCells("A{$r}:E{$r}");
$sheet->getStyle("A{$r}:E{$r}")->getFont()->setBold(true)->setSize(16);
$sheet->getStyle("A{$r}:E{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$r += 2;

$sheet->setCellValue('A' . $r, 'Profil');
$sheet->getStyle('A' . $r)->getFont()->setBold(true);
$r++;
$sheet->setCellValue('A' . $r, 'Nom');
$sheet->setCellValue('B' . $r, (string) ($personnel['full_name'] ?? '—'));
$r++;
$sheet->setCellValue('A' . $r, 'Fonction');
$sheet->setCellValue('B' . $r, (string) ($personnel['fonction_label'] ?? '—'));
$r++;
$sheet->setCellValue('A' . $r, 'Service');
$sheet->setCellValue('B' . $r, (string) ($personnel['service_label'] ?? '—'));
$r += 2;

$sheet->setCellValue('A' . $r, 'Synthèse mois');
$sheet->getStyle('A' . $r)->getFont()->setBold(true);
$r++;
$sheet->setCellValue('A' . $r, 'Jours');
$sheet->setCellValue('B' . $r, $summaryDays);
$r++;
$sheet->setCellValue('A' . $r, 'Heures supp');
$sheet->setCellValue('B' . $r, $summaryOvertime);
$r++;
$sheet->setCellValue('A' . $r, 'Absences à justifier (60j)');
$sheet->setCellValue('B' . $r, (int) count($unjustifiedAbsences));
$r += 2;

$headerRow = $r;
$sheet->setCellValue('A' . $r, 'Date');
$sheet->setCellValue('B' . $r, 'Statut');
$sheet->setCellValue('C' . $r, 'Jours');
$sheet->setCellValue('D' . $r, 'Heures supp');
$sheet->setCellValue('E' . $r, 'Note');

$sheet->getStyle("A{$r}:E{$r}")->getFont()->setBold(true);
$sheet->getStyle("A{$r}:E{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EEF2FF');
$sheet->getStyle("A{$r}:E{$r}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('CBD5E1');
$r++;

foreach ($rows as $row) {
    $date = (string) ($row['attendance_date'] ?? '');
    $statusKey = strtolower(trim((string) ($row['attendance_status'] ?? '')));

    $sheet->setCellValue('A' . $r, $date);
    $sheet->setCellValue('B' . $r, $statuses[$statusKey] ?? ($statusKey !== '' ? $statusKey : '—'));
    $sheet->setCellValue('C' . $r, (float) ($row['worked_days'] ?? 0));
    $sheet->setCellValue('D' . $r, (float) ($row['overtime_hours'] ?? 0));
    $sheet->setCellValue('E' . $r, (string) ($row['notes'] ?? ''));
    $r++;
}

$lastDataRow = $r - 1;
if ($lastDataRow >= $headerRow) {
    $sheet->getStyle("A{$headerRow}:E{$lastDataRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('E2E8F0');
    $sheet->getStyle("C" . ($headerRow + 1) . ":D{$lastDataRow}")->getNumberFormat()->setFormatCode('0.00');
}

foreach (['A' => 14, 'B' => 18, 'C' => 10, 'D' => 14, 'E' => 40] as $col => $w) {
    $sheet->getColumnDimension($col)->setWidth($w);
}

$baseName = preg_replace('/[^a-z0-9_-]+/i', '_', (string) ($personnel['full_name'] ?? 'employe'));
$fileName = 'pointage_' . $period . '_' . $baseName . '.xlsx';

\AuditTrail::logFileEvent('export', [
    'module' => 'request',
    'action' => 'export_pointage_employe_excel',
    'entity_type' => 'attendance_monthly_export',
    'entity_id' => (string) $pid,
    'entity_label' => (string) ($personnel['full_name'] ?? $fileName),
    'format' => 'xlsx',
    'user_id' => $_SESSION['user_id'] ?? null,
    'user_name' => $_SESSION['user_name'] ?? $_SESSION['username'] ?? null,
    'details' => [
        'period' => $period,
        'row_count' => count($rows),
        'summary_days' => $summaryDays,
        'summary_overtime' => $summaryOvertime,
        'unjustified_absence_count' => count($unjustifiedAbsences),
    ],
], $pdo);

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($xlsx);
$writer->save('php://output');
exit;
