<?php
require_once __DIR__ . '/../auth_check.php';
AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../controllers/RhPayrollController.php';
require_once __DIR__ . '/../model/RhAttendancePeriodManager.php';
require_once __DIR__ . '/../phpqrcode/qrlib.php';
ob_start();
require_once __DIR__ . '/../fpdf186/fpdf.php';

$pdo = Database::getConnection();
$manager = (new RhPayrollController($pdo))->service();
$statuses = $manager->getAttendanceStatuses();


function rhEnsureAttendanceSettingsTable(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS rh_attendance_settings (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        setting_key VARCHAR(80) NOT NULL,
        setting_value VARCHAR(120) NOT NULL,
        setting_label VARCHAR(190) DEFAULT NULL,
        updated_by INT DEFAULT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_rh_attendance_setting_key (setting_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function rhGetAttendanceSettings(PDO $pdo): array
{
    rhEnsureAttendanceSettingsTable($pdo);
    $settings = [
        'daily_work_target_hours' => '10.00',
        'late_reference_time' => '08:40',
        'arrival_tolerance_minutes' => '10',
        'departure_tolerance_minutes' => '10',
        'overtime_tolerance_minutes' => '15',
    ];

    try {
        $stmt = $pdo->query('SELECT setting_key, setting_value FROM rh_attendance_settings');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = (string) ($row['setting_key'] ?? '');
            if (array_key_exists($key, $settings)) {
                $settings[$key] = (string) ($row['setting_value'] ?? $settings[$key]);
            }
        }
    } catch (Throwable $ignored) {
        // Les valeurs par defaut restent utilisables meme si la table vient juste d'etre creee.
    }

    return $settings;
}

function rhTimeToMinutes(?string $time): ?int
{
    $time = trim((string) $time);
    if ($time === '') {
        return null;
    }
    if (!preg_match('/^(\d{1,2}):(\d{2})/', $time, $matches)) {
        return null;
    }
    $hour = max(0, min(23, (int) $matches[1]));
    $minute = max(0, min(59, (int) $matches[2]));
    return ($hour * 60) + $minute;
}

function rhRoundQuarterHour(float $hours): float
{
    return round($hours * 4) / 4;
}

function rhEffectiveAttendanceMetrics(array $attendance, array $settings): array
{
    $status = (string) ($attendance['attendance_status'] ?? 'present');
    $activeStatuses = ['present', 'half_day'];

    $targetHours = max(0.0, (float) str_replace(',', '.', (string) ($settings['daily_work_target_hours'] ?? '10.00')));
    $referenceMinutes = rhTimeToMinutes((string) ($settings['late_reference_time'] ?? '08:40')) ?? (8 * 60 + 40);
    $arrivalTolerance = max(0, (int) ($settings['arrival_tolerance_minutes'] ?? 10));
    $departureTolerance = max(0, (int) ($settings['departure_tolerance_minutes'] ?? 10));
    $overtimeTolerance = max(0, (int) ($settings['overtime_tolerance_minutes'] ?? 15));

    $in = rhTimeToMinutes((string) ($attendance['check_in_time'] ?? ''));
    $out = rhTimeToMinutes((string) ($attendance['check_out_time'] ?? ''));
    $workedMinutes = null;
    if ($in !== null && $out !== null) {
        if ($out < $in) {
            $out += 1440;
        }
        $workedMinutes = max(0, $out - $in);
    }

    if (array_key_exists('is_working_date', $attendance) && empty($attendance['is_working_date'])) {
        $restOvertime = $workedMinutes !== null ? rhRoundQuarterHour($workedMinutes / 60) : (float) ($attendance['overtime_hours'] ?? 0);
        return [
            'worked_minutes' => $workedMinutes,
            'late_minutes' => 0,
            'deficit_minutes' => 0,
            'overtime_hours' => $restOvertime,
            'has_time_pair' => $workedMinutes !== null,
        ];
    }

    $lateMinutes = 0;
    if (in_array($status, $activeStatuses, true) && $in !== null && $in > ($referenceMinutes + $arrivalTolerance)) {
        $lateMinutes = $in - $referenceMinutes;
    }

    $targetMinutes = (int) round($targetHours * 60);
    $rawBalanceMinutes = $workedMinutes !== null ? ($workedMinutes - $targetMinutes) : 0;
    $deficitMinutes = $rawBalanceMinutes < (-1 * $departureTolerance) ? abs($rawBalanceMinutes) : 0;
    $overtimeMinutes = $rawBalanceMinutes > $overtimeTolerance ? $rawBalanceMinutes : 0;
    $calculatedOvertime = $overtimeMinutes > 0 ? rhRoundQuarterHour($overtimeMinutes / 60) : 0.0;

    return [
        'worked_minutes' => $workedMinutes,
        'late_minutes' => $lateMinutes,
        'deficit_minutes' => $deficitMinutes,
        'overtime_hours' => $calculatedOvertime,
        'has_time_pair' => $workedMinutes !== null,
    ];
}

$attendanceSettings = rhGetAttendanceSettings($pdo);

$mode = strtolower(trim((string) ($_GET['mode'] ?? 'journalier')));
if (!in_array($mode, ['journalier', 'mensuel'], true)) {
    $mode = 'journalier';
}

function pdf_text(string $value): string
{
    return mb_convert_encoding($value, 'ISO-8859-1', 'UTF-8');
}

function truncate_for_pdf(FPDF $pdf, string $text, float $maxWidth): string
{
    $text = trim($text);
    if ($text === '') {
        return '';
    }

    if ($pdf->GetStringWidth($text) <= $maxWidth) {
        return $text;
    }

    $ellipsis = '...';
    $maxWidth = max(1, $maxWidth - $pdf->GetStringWidth($ellipsis));
    $result = '';
    $len = mb_strlen($text);
    for ($i = 0; $i < $len; $i++) {
        $candidate = $result . mb_substr($text, $i, 1);
        if ($pdf->GetStringWidth($candidate) > $maxWidth) {
            break;
        }
        $result = $candidate;
    }

    return rtrim($result) . $ellipsis;
}

function rhExportIsApiSource(array $attendance): bool
{
    $recordedBy = strtolower(trim((string) ($attendance['recorded_by_name'] ?? '')));
    $notes = strtolower(trim((string) ($attendance['notes'] ?? '')));

    return $recordedBy === 'attendance-api'
        || $recordedBy === 'badgeuse'
        || $notes === 'auto: attendance-api'
        || strpos($notes, 'attendance-api') !== false;
}

function rhExportDisplayNote(array $attendance): string
{
    $note = trim((string) ($attendance['notes'] ?? ''));
    if ($note === '' || preg_match('/^auto\s*:\s*attendance-api$/i', $note)) {
        return '';
    }

    return $note;
}

function rhExportSourceLabel(array $attendance): string
{
    $recordedByName = trim((string) ($attendance['recorded_by_name'] ?? ''));
    $recordedByUserId = (int) ($attendance['recorded_by_user_id'] ?? 0);
    $hasSavedRow = !empty($attendance['has_saved_row']);
    $hasTime = trim((string) ($attendance['check_in_time'] ?? '')) !== ''
        || trim((string) ($attendance['check_out_time'] ?? '')) !== '';

    if (rhExportIsApiSource($attendance)) {
        return 'Badgeuse';
    }
    if ($hasSavedRow || $recordedByUserId > 0 || $recordedByName !== '' || $hasTime) {
        return $recordedByName !== '' ? ('Manuel: ' . $recordedByName) : 'Manuel';
    }

    return '-';
}

class PDF_Pointage extends FPDF
{
    public string $docTitle = '';
    public string $docSubtitle = '';
    public string $logoPath = '';
    public string $qrPath = '';

    public function Header(): void
    {
        $w = $this->GetPageWidth();
        $this->SetFillColor(0, 0, 0);
        $this->Rect(0, 0, $w, 25, 'F');
        $this->SetFillColor(250, 189, 2);
        $this->Rect(0, 23, $w, 2, 'F');

        if ($this->logoPath !== '' && is_file($this->logoPath)) {
            $this->Image($this->logoPath, 8, 3, 18, 18);
        }
        if ($this->qrPath !== '' && is_file($this->qrPath)) {
            $this->Image($this->qrPath, $w - 27, 3, 18, 18, 'PNG');
        }

        $this->SetFont('Arial', 'B', 14);
        $this->SetTextColor(255, 255, 255);
        $this->SetXY(30, 6);
        $this->Cell(0, 6, pdf_text($this->docTitle), 0, 0, 'L');

        $this->SetFont('Arial', '', 9);
        $this->SetTextColor(250, 189, 2);
        $this->SetXY(30, 13);
        $this->Cell(0, 5, pdf_text($this->docSubtitle), 0, 0, 'L');

        $this->SetFont('Arial', '', 9);
        $this->SetXY($w - 90, 7);
        $this->Cell(80, 5, pdf_text('Export : ' . date('d/m/Y H:i')), 0, 0, 'R');

        $this->Ln(21);
        $this->SetTextColor(17, 24, 39);
    }

    public function Footer(): void
    {
        $this->SetY(-12);
        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(100, 116, 139);
        $this->SetDrawColor(250, 189, 2);
        $this->Line(10, $this->GetY(), $this->GetPageWidth() - 10, $this->GetY());
        $this->Cell(0, 6, pdf_text('BANAMUR INDUSTRIES ET TECHNOLOGIES - RH - Page ' . $this->PageNo() . ' / {nb}'), 0, 0, 'C');
    }
}

if ($mode === 'journalier') {
    $date = (string) ($_GET['date'] ?? date('Y-m-d'));
    $board = $manager->getAttendanceBoard($date);

    $pdf = new PDF_Pointage('L', 'mm', 'A4');
    $pdf->logoPath = __DIR__ . '/../logo/default_logo.jpg';
    $pdf->qrPath = tempnam(sys_get_temp_dir(), 'rh_pointage_qr_') ?: '';
    if ($pdf->qrPath !== '') {
        QRcode::png('RH-POINTAGE-JOUR-' . $board['date'], $pdf->qrPath, QR_ECLEVEL_M, 4, 1);
    }
    $pdf->AliasNbPages();
    $pdf->docTitle = 'Pointage journalier RH';
    $pdf->docSubtitle = 'Date : ' . date('d/m/Y', strtotime($board['date']));
    $pdf->AddPage();

    // Résumé
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetTextColor(51, 65, 85);
    $summary = sprintf(
        'Effectif : %d  |  Present : %d  |  Mission : %d  |  Demi-journee : %d  |  Absence : %d',
        (int) ($board['total'] ?? 0),
        (int) (($board['stats']['present'] ?? 0)),
        (int) (($board['stats']['mission'] ?? 0)),
        (int) (($board['stats']['half_day'] ?? 0)),
        (int) (($board['stats']['absence'] ?? 0))
    );
    $pdf->Cell(0, 7, pdf_text($summary), 0, 1, 'L');
    $pdf->Ln(1);

    // Table
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetFillColor(241, 245, 249); // slate-100
    $pdf->SetTextColor(15, 23, 42);

    // A4 landscape: ~277mm utilisables avec marges 10mm
    $w = [45, 18, 26, 20, 12, 12, 16, 16, 28, 78];
    $headers = ['Collaborateur', 'Matricule', 'Fonction', 'Statut', 'Jours', 'HS', 'Entree', 'Sortie', 'Source', 'Observation'];
    foreach ($headers as $i => $header) {
        $pdf->Cell($w[$i], 7, pdf_text($header), 1, 0, 'C', true);
    }
    $pdf->Ln();

    $pdf->SetFont('Arial', '', 9);
    $pdf->SetTextColor(30, 41, 59);

    $fill = false;
    foreach ($board['items'] as $item) {
        $employee = $item['employee'] ?? [];
        $attendance = $item['attendance'] ?? [];
        $statusKey = (string) ($attendance['attendance_status'] ?? 'present');
        $statusLabel = (string) (($statuses[$statusKey]['label'] ?? $statusKey));

        $pdf->SetFillColor($fill ? 248 : 255, $fill ? 250 : 255, $fill ? 252 : 255);

        $name = (string) ($employee['full_name'] ?? '');
        $matricule = (string) (($employee['matricule'] ?? '') ?: '-');
        $fonction = (string) ($employee['fonction_label'] ?? '');
        $workedDays = (string) number_format((float) ($attendance['worked_days'] ?? 0), 2, ',', ' ');
        $effectiveMetrics = rhEffectiveAttendanceMetrics($attendance, $attendanceSettings);
        $effectiveOvertime = $effectiveMetrics['has_time_pair'] ? (float) $effectiveMetrics['overtime_hours'] : (float) ($attendance['overtime_hours'] ?? 0);
        $overtimeHours = (string) number_format($effectiveOvertime, 2, ',', ' ');
        $checkIn = (string) substr((string) ($attendance['check_in_time'] ?? ''), 0, 5);
        $checkOut = (string) substr((string) ($attendance['check_out_time'] ?? ''), 0, 5);
        $sourceLabel = rhExportSourceLabel($attendance);
        $notes = rhExportDisplayNote($attendance);

        $pdf->Cell($w[0], 7, pdf_text(truncate_for_pdf($pdf, $name, $w[0] - 2)), 1, 0, 'L', $fill);
        $pdf->Cell($w[1], 7, pdf_text(truncate_for_pdf($pdf, $matricule, $w[1] - 2)), 1, 0, 'L', $fill);
        $pdf->Cell($w[2], 7, pdf_text(truncate_for_pdf($pdf, $fonction, $w[2] - 2)), 1, 0, 'L', $fill);
        $pdf->Cell($w[3], 7, pdf_text(truncate_for_pdf($pdf, $statusLabel, $w[3] - 2)), 1, 0, 'L', $fill);
        $pdf->Cell($w[4], 7, pdf_text($workedDays), 1, 0, 'R', $fill);
        $pdf->Cell($w[5], 7, pdf_text($overtimeHours), 1, 0, 'R', $fill);
        $pdf->Cell($w[6], 7, pdf_text($checkIn !== '' ? $checkIn : '-'), 1, 0, 'C', $fill);
        $pdf->Cell($w[7], 7, pdf_text($checkOut !== '' ? $checkOut : '-'), 1, 0, 'C', $fill);
        $pdf->Cell($w[8], 7, pdf_text(truncate_for_pdf($pdf, $sourceLabel, $w[8] - 2)), 1, 0, 'L', $fill);
        $pdf->Cell($w[9], 7, pdf_text(truncate_for_pdf($pdf, $notes, $w[9] - 2)), 1, 1, 'L', $fill);

        $fill = !$fill;
    }

    $filename = 'pointage_journalier_' . $board['date'] . '_' . date('Ymd_His') . '.pdf';
    ob_clean();
    if ($pdf->qrPath !== '' && is_file($pdf->qrPath)) { @unlink($pdf->qrPath); }
    $pdf->Output('D', $filename);
    exit;
}

$period = (string) ($_GET['period'] ?? '');
if ($period === '') {
    $dateFallback = (string) ($_GET['date'] ?? '');
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFallback)) {
        $period = substr($dateFallback, 0, 7);
    }
}
if ($period === '') {
    $period = date('Y-m');
}
$periodManager = new RhAttendancePeriodManager($pdo);
$range = $periodManager->resolve($period, $_GET['date_from'] ?? null, $_GET['date_to'] ?? null);
$monthly = $manager->getMonthlyAttendanceBoard($period, true, $range['start_date'], $range['end_date']);
$selectedPersonnelId = max(0, (int) ($_GET['personnel_id'] ?? 0));
if ($selectedPersonnelId > 0) {
    $monthly['items'] = array_values(array_filter($monthly['items'], static fn(array $item): bool =>
        (int) ($item['employee']['id'] ?? 0) === $selectedPersonnelId
    ));
    $monthly['total']['employees'] = count($monthly['items']);
}

$effectiveMonthlyOvertime = [];
$effectiveMonthlyTotalOvertime = 0.0;
try {
    $startLoop = new DateTimeImmutable((string) ($monthly['start_date'] ?? ($period . '-01')));
    $endLoop = new DateTimeImmutable((string) ($monthly['end_date'] ?? $startLoop->modify('last day of this month')->format('Y-m-d')));
    for ($cursor = $startLoop; $cursor <= $endLoop; $cursor = $cursor->modify('+1 day')) {
        $dailyBoard = $manager->getAttendanceBoard($cursor->format('Y-m-d'));
        foreach (($dailyBoard['items'] ?? []) as $dailyItem) {
            $pid = (int) (($dailyItem['employee']['id'] ?? 0));
            if ($pid <= 0) {
                continue;
            }
            $dailyAttendance = is_array($dailyItem['attendance'] ?? null) ? $dailyItem['attendance'] : [];
            $dailyMetrics = rhEffectiveAttendanceMetrics($dailyAttendance, $attendanceSettings);
            $value = $dailyMetrics['has_time_pair'] ? (float) $dailyMetrics['overtime_hours'] : (float) ($dailyAttendance['overtime_hours'] ?? 0);
            $effectiveMonthlyOvertime[$pid] = ($effectiveMonthlyOvertime[$pid] ?? 0.0) + $value;
            $effectiveMonthlyTotalOvertime += $value;
        }
    }
} catch (Throwable $ignored) {
    $effectiveMonthlyOvertime = [];
    $effectiveMonthlyTotalOvertime = (float) ($monthly['total']['overtime_hours'] ?? 0);
}


$pdf = new PDF_Pointage('L', 'mm', 'A4');
$pdf->logoPath = __DIR__ . '/../logo/default_logo.jpg';
$pdf->qrPath = tempnam(sys_get_temp_dir(), 'rh_pointage_qr_') ?: '';
if ($pdf->qrPath !== '') {
    QRcode::png('RH-POINTAGE-' . $range['start_date'] . '-' . $range['end_date'], $pdf->qrPath, QR_ECLEVEL_M, 4, 1);
}
$pdf->AliasNbPages();
$pdf->docTitle = 'Pointage mensuel RH';
$pdf->docSubtitle = sprintf(
    'Periode : %s (du %s au %s)',
    $monthly['period'],
    date('d/m/Y', strtotime($monthly['start_date'])),
    date('d/m/Y', strtotime($monthly['end_date']))
);
$pdf->AddPage();

$pdf->SetFont('Arial', '', 9);
$pdf->SetTextColor(51, 65, 85);
$pdf->Cell(0, 7, pdf_text(sprintf(
    'Effectif : %d  |  Total jours : %s  |  Total HS : %s',
    (int) ($monthly['total']['employees'] ?? 0),
    number_format((float) ($monthly['total']['worked_days'] ?? 0), 2, ',', ' '),
    number_format($effectiveMonthlyTotalOvertime, 2, ',', ' ')
)), 0, 1, 'L');
$pdf->Ln(1);

$pdf->SetFont('Arial', 'B', 8);
$pdf->SetFillColor(241, 245, 249);
$pdf->SetTextColor(15, 23, 42);

// Largeurs colonnes (A4 landscape)
$w = [50, 18, 26, 12, 12, 12, 12, 12, 12, 12, 12, 12, 55];
$headers = [
    'Collaborateur',
    'Matricule',
    'Fonction',
    'Jours',
    'HS',
    'Present',
    'Mission',
    'Demi',
    'Conge',
    'Repos',
    'Abs.',
    'Mal.',
    'Detail statuts'
];
foreach ($headers as $i => $header) {
    $pdf->Cell($w[$i], 7, pdf_text($header), 1, 0, 'C', true);
}
$pdf->Ln();

$pdf->SetFont('Arial', '', 8);
$pdf->SetTextColor(30, 41, 59);
$fill = false;

foreach ($monthly['items'] as $item) {
    $employee = $item['employee'] ?? [];
    $summary = $item['summary'] ?? [];

    $pdf->SetFillColor($fill ? 248 : 255, $fill ? 250 : 255, $fill ? 252 : 255);

    $name = (string) ($employee['full_name'] ?? '');
    $matricule = (string) (($employee['matricule'] ?? '') ?: '-');
    $fonction = (string) ($employee['fonction_label'] ?? '');

    $workedDays = (float) ($summary['worked_days'] ?? 0);
    $employeeId = (int) (($employee['id'] ?? 0));
    $overtimeHours = array_key_exists($employeeId, $effectiveMonthlyOvertime) ? (float) $effectiveMonthlyOvertime[$employeeId] : (float) ($summary['overtime_hours'] ?? 0);
    $statusDays = is_array($summary['status_days'] ?? null) ? $summary['status_days'] : [];

    $present = (float) ($statusDays['present'] ?? 0);
    $mission = (float) ($statusDays['mission'] ?? 0);
    $halfDay = (float) ($statusDays['half_day'] ?? 0);
    $conge = (float) ($statusDays['conge'] ?? 0);
    $repos = (float) ($statusDays['repos'] ?? 0);
    $absence = (float) ($statusDays['absence'] ?? 0);
    $maladie = (float) ($statusDays['maladie'] ?? 0);

    $detailParts = [];
    foreach ($statuses as $statusKey => $meta) {
        $value = (float) ($statusDays[$statusKey] ?? 0);
        if ($value <= 0) {
            continue;
        }
        $detailParts[] = ($meta['label'] ?? $statusKey) . ':' . number_format($value, 2, ',', ' ');
    }
    $detail = implode(' | ', $detailParts);

    $pdf->Cell($w[0], 7, pdf_text(truncate_for_pdf($pdf, $name, $w[0] - 2)), 1, 0, 'L', $fill);
    $pdf->Cell($w[1], 7, pdf_text(truncate_for_pdf($pdf, $matricule, $w[1] - 2)), 1, 0, 'L', $fill);
    $pdf->Cell($w[2], 7, pdf_text(truncate_for_pdf($pdf, $fonction, $w[2] - 2)), 1, 0, 'L', $fill);

    $pdf->Cell($w[3], 7, pdf_text(number_format($workedDays, 2, ',', ' ')), 1, 0, 'R', $fill);
    $pdf->Cell($w[4], 7, pdf_text(number_format($overtimeHours, 2, ',', ' ')), 1, 0, 'R', $fill);

    $pdf->Cell($w[5], 7, pdf_text(number_format($present, 2, ',', ' ')), 1, 0, 'R', $fill);
    $pdf->Cell($w[6], 7, pdf_text(number_format($mission, 2, ',', ' ')), 1, 0, 'R', $fill);
    $pdf->Cell($w[7], 7, pdf_text(number_format($halfDay, 2, ',', ' ')), 1, 0, 'R', $fill);
    $pdf->Cell($w[8], 7, pdf_text(number_format($conge, 2, ',', ' ')), 1, 0, 'R', $fill);
    $pdf->Cell($w[9], 7, pdf_text(number_format($repos, 2, ',', ' ')), 1, 0, 'R', $fill);
    $pdf->Cell($w[10], 7, pdf_text(number_format($absence, 2, ',', ' ')), 1, 0, 'R', $fill);
    $pdf->Cell($w[11], 7, pdf_text(number_format($maladie, 2, ',', ' ')), 1, 0, 'R', $fill);

    $pdf->Cell($w[12], 7, pdf_text(truncate_for_pdf($pdf, $detail, $w[12] - 2)), 1, 1, 'L', $fill);

    $fill = !$fill;
}

if ($selectedPersonnelId > 0 && $monthly['items'] !== []) {
    $matrix = $manager->getMonthlyAttendanceMatrix($period, true, $range['start_date'], $range['end_date']);
    $selectedItem = null;
    foreach ($matrix['items'] as $matrixItem) {
        if ((int) ($matrixItem['employee']['id'] ?? 0) === $selectedPersonnelId) {
            $selectedItem = $matrixItem;
            break;
        }
    }
    if (is_array($selectedItem)) {
        $employee = (array) ($selectedItem['employee'] ?? []);
        $dayMap = (array) ($selectedItem['days'] ?? []);
        $hoursPerDay = (float) ($selectedItem['hours_per_day'] ?? 8);
        $pdf->AddPage();
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(0, 8, pdf_text('Detail individuel - ' . (string) ($employee['full_name'] ?? '')), 0, 1, 'L');
        $pdf->SetFont('Arial', '', 8);
        $detailWidths = [22, 22, 34, 20, 20, 24, 18, 18, 95];
        $detailHeaders = ['Date', 'Jour', 'Statut', 'Arrivee', 'Depart', 'Heures', 'Jours', 'HS', 'Notes'];
        $pdf->SetFillColor(250, 189, 2);
        $pdf->SetTextColor(0, 0, 0);
        foreach ($detailHeaders as $i => $header) {
            $pdf->Cell($detailWidths[$i], 7, pdf_text($header), 1, 0, 'C', true);
        }
        $pdf->Ln();
        $pdf->SetTextColor(30, 41, 59);
        foreach ($matrix['days'] as $date) {
            $attendance = is_array($dayMap[$date] ?? null) ? $dayMap[$date] : null;
            $checkIn = trim((string) ($attendance['check_in_time'] ?? ''));
            $checkOut = trim((string) ($attendance['check_out_time'] ?? ''));
            $workedHours = 0.0;
            if ($checkIn !== '' && $checkOut !== '') {
                $inTs = strtotime('1970-01-01 ' . $checkIn);
                $outTs = strtotime('1970-01-01 ' . $checkOut);
                if ($inTs !== false && $outTs !== false) {
                    if ($outTs < $inTs) {
                        $outTs += 86400;
                    }
                    $workedHours = ($outTs - $inTs) / 3600;
                }
            } elseif ($attendance) {
                $workedHours = (float) ($attendance['worked_days'] ?? 0) * $hoursPerDay;
            }
            $statusKey = (string) ($attendance['attendance_status'] ?? '');
            $values = [
                date('d/m/Y', strtotime($date)),
                ['Dimanche','Lundi','Mardi','Mercredi','Jeudi','Vendredi','Samedi'][(int) date('w', strtotime($date))],
                $attendance ? (string) ($statuses[$statusKey]['label'] ?? $statusKey) : 'Non saisi',
                $checkIn !== '' ? $checkIn : '-',
                $checkOut !== '' ? $checkOut : '-',
                number_format($workedHours, 2, ',', ' '),
                number_format((float) ($attendance['worked_days'] ?? 0), 2, ',', ' '),
                number_format((float) ($attendance['overtime_hours'] ?? 0), 2, ',', ' '),
                (string) ($attendance['notes'] ?? ''),
            ];
            foreach ($values as $i => $value) {
                $pdf->Cell($detailWidths[$i], 6, pdf_text(truncate_for_pdf($pdf, $value, $detailWidths[$i] - 2)), 1, 0, $i >= 5 && $i <= 7 ? 'R' : 'L');
            }
            $pdf->Ln();
        }
    }
}

$filename = 'pointage_' . $range['start_date'] . '_au_' . $range['end_date'] . '_' . date('Ymd_His') . '.pdf';
ob_clean();
if ($pdf->qrPath !== '' && is_file($pdf->qrPath)) { @unlink($pdf->qrPath); }
$pdf->Output('D', $filename);
exit;
