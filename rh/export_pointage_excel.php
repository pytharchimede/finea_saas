<?php
require_once __DIR__ . '/../auth_check.php';
AccessManager::requirePageAccess();

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../controllers/RhPayrollController.php';
require_once __DIR__ . '/../model/RhAttendancePeriodManager.php';
require_once __DIR__ . '/../services/RhAttendanceClosingNotificationService.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$pdo = Database::getConnection();
$manager = (new RhPayrollController($pdo))->service();
$periodManager = new RhAttendancePeriodManager($pdo);
$closingService = new RhAttendanceClosingNotificationService($pdo);
$period = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['period'] ?? '')) ? (string) $_GET['period'] : date('Y-m');
$range = $periodManager->resolve($period, $_GET['date_from'] ?? null, $_GET['date_to'] ?? null);
$matrix = $manager->getMonthlyAttendanceMatrix($period, true, $range['start_date'], $range['end_date']);
$selectedPersonnelId = max(0, (int) ($_GET['personnel_id'] ?? 0));
if ($selectedPersonnelId > 0) {
    $matrix['items'] = array_values(array_filter($matrix['items'], static fn(array $item): bool =>
        (int) ($item['employee']['id'] ?? 0) === $selectedPersonnelId
    ));
}

function rhExcelStatus(string $status): string
{
    return [
        'present' => 'Présent', 'mission' => 'Mission', 'half_day' => 'Demi-journée',
        'conge' => 'Congé', 'repos' => 'Repos', 'absence' => 'Absent', 'maladie' => 'Maladie',
    ][$status] ?? ucfirst($status);
}

function rhExcelWorkedHours(?array $attendance, float $fallbackHours): float
{
    if (!$attendance) {
        return 0.0;
    }
    $in = trim((string) ($attendance['check_in_time'] ?? ''));
    $out = trim((string) ($attendance['check_out_time'] ?? ''));
    if ($in !== '' && $out !== '') {
        $start = strtotime('1970-01-01 ' . $in);
        $end = strtotime('1970-01-01 ' . $out);
        if ($start !== false && $end !== false) {
            if ($end < $start) {
                $end += 86400;
            }
            return round(($end - $start) / 3600, 2);
        }
    }
    return round((float) ($attendance['worked_days'] ?? 0) * $fallbackHours, 2);
}

function rhExcelSafeSheetTitle(string $name, string $matricule, array &$used): string
{
    $name = preg_replace('/[\\\\\\/\\?\\*\\[\\]:]/u', ' ', trim($name)) ?? trim($name);
    $matricule = preg_replace('/[\\\\\\/\\?\\*\\[\\]:]/u', ' ', trim($matricule)) ?? trim($matricule);
    $suffix = $matricule !== '' ? ' (' . $matricule . ')' : '';
    if (mb_strlen($suffix) >= 31) {
        $suffix = ' (' . mb_substr($matricule, 0, 27) . ')';
    }
    $title = mb_substr($name, 0, max(1, 31 - mb_strlen($suffix))) . $suffix;
    $title = trim($title);
    $title = $title !== '' ? $title : 'Collaborateur';
    $base = $title;
    $suffix = 2;
    while (isset($used[mb_strtolower($title)])) {
        $tail = ' ' . $suffix++;
        $title = mb_substr($base, 0, 31 - mb_strlen($tail)) . $tail;
    }
    $used[mb_strtolower($title)] = true;
    return $title;
}

function rhExcelStyleHeader($sheet, string $range): void
{
    $sheet->getStyle($range)->getFont()->setBold(true)->getColor()->setARGB('FF000000');
    $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFABD02');
    $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle($range)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FFCBD5E1');
}

$spreadsheet = new Spreadsheet();
$summary = $spreadsheet->getActiveSheet();
$summary->setTitle('Synthèse');
$column = 1;
$summary->setCellValue([1, 1], 'Pointage RH du ' . date('d/m/Y', strtotime($range['start_date'])) . ' au ' . date('d/m/Y', strtotime($range['end_date'])));
$summary->mergeCells([1, 1, 6 + count($matrix['days']), 1]);
$summary->getStyle('A1')->getFont()->setBold(true)->setSize(15);
$summary->getStyle('A1:' . \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(6 + count($matrix['days'])) . '1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF000000');
$summary->getStyle('A1')->getFont()->getColor()->setARGB('FFFFFFFF');
$summary->setCellValue([1, 3], 'Collaborateur');
$summary->setCellValue([2, 3], 'Matricule');
$summary->setCellValue([3, 3], 'Fonction');
$column = 4;
foreach ($matrix['days'] as $date) {
    $summary->setCellValue([$column++, 3], date('d/m', strtotime($date)));
}
$summary->setCellValue([$column++, 3], 'Jours');
$summary->setCellValue([$column++, 3], 'Heures');
$summary->setCellValue([$column, 3], 'HS');
rhExcelStyleHeader($summary, 'A3:' . \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($column) . '3');

$row = 4;
$usedTitles = ['synthèse' => true];
foreach ($matrix['items'] as $item) {
    $employee = (array) ($item['employee'] ?? []);
    $dayMap = (array) ($item['days'] ?? []);
    $totals = (array) ($item['totals'] ?? []);
    $summary->setCellValue([1, $row], (string) ($employee['full_name'] ?? ''));
    $summary->setCellValue([2, $row], (string) ($employee['matricule'] ?? ''));
    $summary->setCellValue([3, $row], (string) ($employee['fonction_label'] ?? ''));
    $column = 4;
    foreach ($matrix['days'] as $date) {
        $attendance = is_array($dayMap[$date] ?? null) ? $dayMap[$date] : null;
        $summary->setCellValue([$column++, $row], $attendance ? mb_substr(rhExcelStatus((string) ($attendance['attendance_status'] ?? '')), 0, 3) : '');
    }
    $summary->setCellValue([$column++, $row], (float) ($totals['worked_days'] ?? 0));
    $summary->setCellValue([$column++, $row], (float) ($totals['work_hours'] ?? 0));
    $summary->setCellValue([$column, $row], (float) ($totals['overtime_hours'] ?? 0));
    $row++;

    $name = (string) ($employee['full_name'] ?? '');
    $matricule = (string) ($employee['matricule'] ?? '');
    $detail = $spreadsheet->createSheet();
    $detail->setTitle(rhExcelSafeSheetTitle($name, $matricule, $usedTitles));
    $detail->setCellValue('A1', $name . ($matricule !== '' ? ' (' . $matricule . ')' : ''));
    $detail->mergeCells('A1:L1');
    $detail->getStyle('A1')->getFont()->setBold(true)->setSize(15);
    $detail->getStyle('A1:L1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF000000');
    $detail->getStyle('A1')->getFont()->getColor()->setARGB('FFFFFFFF');
    $detail->setCellValue('A2', 'Période : ' . date('d/m/Y', strtotime($range['start_date'])) . ' au ' . date('d/m/Y', strtotime($range['end_date'])));
    $headers = ['Date', 'Jour', 'Statut', 'Arrivée', 'Départ', 'Heures travaillées', 'Jours', 'HS', 'Fonction', 'Notes', 'Justification', 'Pièces justificatives'];
    foreach ($headers as $index => $header) {
        $detail->setCellValue([$index + 1, 4], $header);
    }
    rhExcelStyleHeader($detail, 'A4:L4');
    $detailRow = 5;
    $hoursPerDay = (float) ($item['hours_per_day'] ?? 8);
    $justifications = $closingService->justifications((int) ($employee['id'] ?? 0), $range['start_date'], $range['end_date']);
    foreach ($matrix['days'] as $date) {
        $attendance = is_array($dayMap[$date] ?? null) ? $dayMap[$date] : null;
        $detail->setCellValue([1, $detailRow], date('d/m/Y', strtotime($date)));
        $detail->setCellValue([2, $detailRow], ['Dimanche','Lundi','Mardi','Mercredi','Jeudi','Vendredi','Samedi'][(int) date('w', strtotime($date))]);
        $detail->setCellValue([3, $detailRow], $attendance ? rhExcelStatus((string) ($attendance['attendance_status'] ?? '')) : 'Non saisi');
        $detail->setCellValue([4, $detailRow], (string) ($attendance['check_in_time'] ?? ''));
        $detail->setCellValue([5, $detailRow], (string) ($attendance['check_out_time'] ?? ''));
        $detail->setCellValue([6, $detailRow], rhExcelWorkedHours($attendance, $hoursPerDay));
        $detail->setCellValue([7, $detailRow], (float) ($attendance['worked_days'] ?? 0));
        $detail->setCellValue([8, $detailRow], (float) ($attendance['overtime_hours'] ?? 0));
        $detail->setCellValue([9, $detailRow], (string) ($employee['fonction_label'] ?? ''));
        $detail->setCellValue([10, $detailRow], (string) ($attendance['notes'] ?? ''));
        $justification = (array) ($justifications[$date] ?? []);
        $detail->setCellValue([11, $detailRow], trim((string) ($justification['status'] ?? '') . ' - ' . (string) ($justification['note'] ?? ''), ' -'));
        $detail->setCellValue([12, $detailRow], implode(', ', (array) ($justification['files'] ?? [])));
        $detailRow++;
    }
    $detail->setAutoFilter('A4:L' . max(4, $detailRow - 1));
    $detail->freezePane('A5');
    foreach (range('A', 'L') as $letter) {
        $detail->getColumnDimension($letter)->setAutoSize(true);
    }
}

$summary->freezePane('D4');
$summary->setAutoFilter('A3:' . \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($column) . max(3, $row - 1));
foreach (range(1, $column) as $index) {
    $summary->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index))->setAutoSize(true);
}
$spreadsheet->setActiveSheetIndex(0);

$filename = 'pointage_' . $range['start_date'] . '_au_' . $range['end_date'] . '_' . date('Ymd_His') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
(new Xlsx($spreadsheet))->save('php://output');
exit;
