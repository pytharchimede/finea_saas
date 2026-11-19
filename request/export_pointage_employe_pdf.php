<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../employe/includes/helpers.php';

class PDFWowPointage extends TCPDF
{
    public string $companyName = 'FIDESTCI / BANAMUR';

    public function Header(): void
    {
        $this->SetAutoPageBreak(true, 18);
    }

    public function Footer(): void
    {
        $this->SetY(-20);
        $this->SetDrawColor(209, 213, 219);
        $this->Line(10, $this->GetY(), $this->getPageWidth() - 10, $this->GetY());
        $this->Ln(2);

        $logo = __DIR__ . '/../logo/logo.png';
        if (!@file_exists($logo)) {
            $logo = __DIR__ . '/../img/logo_veritas.jpg';
        }
        if (@file_exists($logo)) {
            $this->Image($logo, 12, $this->GetY(), 16, 0, '', '', '', false, 300);
        }

        $this->SetFont('dejavusans', 'B', 8);
        $this->SetTextColor(31, 41, 55);
        $this->Cell(0, 4, $this->companyName, 0, 1, 'C');
        $this->SetFont('dejavusans', '', 7);
        $this->SetTextColor(75, 85, 99);
        $this->Cell(0, 3.8, 'Fournitures industrielles, depannage et travaux publics', 0, 1, 'C');
        $this->Cell(0, 3.8, '01 BP 1642 Abidjan 01  |  (+225) 27-21-36-27-27  |  info@fidest.org', 0, 1, 'C');
        $this->Cell(0, 3.8, 'Page ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, 0, 'C');
    }
}

function safe_text($v): string
{
    $t = trim((string) $v);
    return $t !== '' ? $t : '—';
}

function fmt_number(float $v): string
{
    return number_format($v, 2, ',', ' ');
}

function app_public_app_base(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '/');
    $basePath = rtrim(dirname(dirname($script)), '/\\');
    return $scheme . '://' . $host . ($basePath !== '' ? $basePath : '');
}

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

$pdf = new PDFWowPointage('P', 'mm', 'A4');
$pdf->SetCreator('Banamur');
$pdf->SetAuthor('Banamur');
$pdf->SetTitle('Pointage ' . $period);
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 18);
$pdf->companyName = 'FIDESTCI / BANAMUR';
$pdf->AddPage();

$pageWidth = $pdf->getPageWidth() - 20;

$fullName = trim((string) ($personnel['full_name'] ?? ''));
$fonction = trim((string) ($personnel['fonction_label'] ?? ''));
$service = trim((string) ($personnel['service_label'] ?? ''));
$roleLine = trim($fonction . ($fonction !== '' && $service !== '' ? ' — ' : '') . $service);

// Hero
$pdf->SetFillColor(31, 41, 55);
$pdf->RoundedRect(10, 10, $pageWidth, 34, 4, '1111', 'F');
$pdf->SetFont('dejavusans', 'B', 18);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetXY(14, 14);
$pdf->Cell(120, 8, 'Pointage employé', 0, 1, 'L');
$pdf->SetFont('dejavusans', '', 9);
$pdf->SetTextColor(226, 232, 240);
$pdf->SetX(14);
$pdf->Cell(140, 5, 'Export PDF premium — espace employé', 0, 1, 'L');

$pdf->SetFont('dejavusans', 'B', 11);
$pdf->SetTextColor(226, 232, 240);
$pdf->SetXY(14, 33);
$pdf->Cell(120, 5, $fullName !== '' ? $fullName : '—', 0, 1, 'L');

$pdf->SetFont('dejavusans', 'B', 16);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetXY(128, 18);
$pdf->Cell(46, 8, fmt_number((float) $summaryDays) . ' j', 0, 1, 'R');
$pdf->SetFont('dejavusans', '', 8.2);
$pdf->SetTextColor(226, 232, 240);
$pdf->SetXY(128, 27);
$pdf->Cell(46, 4, 'Période ' . $period . ' — export ' . date('d/m/Y H:i'), 0, 1, 'R');

if ($roleLine !== '') {
    $pdf->SetFont('dejavusans', '', 8.2);
    $pdf->SetTextColor(226, 232, 240);
    $pdf->SetXY(14, 38.5);
    $pdf->Cell(150, 4, $roleLine, 0, 1, 'L');
}

$qrText = app_public_app_base() . '/employe/pointage_employe.php?period=' . rawurlencode($period);
$style = ['border' => 0, 'padding' => 0, 'fgcolor' => [255, 255, 255], 'bgcolor' => false];
$pdf->write2DBarcode($qrText, 'QRCODE,H', 176, 14, 22, 22, $style, 'N');
$pdf->SetFont('dejavusans', '', 6.8);
$pdf->SetTextColor(226, 232, 240);
$pdf->SetXY(170, 37.5);
$pdf->Cell(28, 4, 'QR pointage', 0, 0, 'C');

// KPI strip
$kpiY = 48;
$kpiGap = 4;
$kpiWidth = ($pageWidth - ($kpiGap * 3)) / 4;
$kpis = [
    ['label' => 'Jours travaillés', 'value' => fmt_number((float) $summaryDays), 'fill' => [239, 246, 255], 'text' => [29, 78, 216]],
    ['label' => 'Heures supp', 'value' => fmt_number((float) $summaryOvertime), 'fill' => [240, 253, 244], 'text' => [21, 128, 61]],
    ['label' => 'Absences à justifier', 'value' => (string) count($unjustifiedAbsences), 'fill' => [255, 247, 237], 'text' => [194, 65, 12]],
    ['label' => 'Lignes mois', 'value' => (string) count($rows), 'fill' => [245, 243, 255], 'text' => [109, 40, 217]],
];
foreach ($kpis as $index => $kpi) {
    $x = 10 + (($kpiWidth + $kpiGap) * $index);
    $pdf->SetFillColor($kpi['fill'][0], $kpi['fill'][1], $kpi['fill'][2]);
    $pdf->SetDrawColor(226, 232, 240);
    $pdf->RoundedRect($x, $kpiY, $kpiWidth, 16, 3, '1111', 'DF');
    $pdf->SetXY($x + 3, $kpiY + 2.5);
    $pdf->SetFont('dejavusans', '', 7.2);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->Cell($kpiWidth - 6, 3.8, $kpi['label'], 0, 2, 'L');
    $pdf->SetX($x + 3);
    $pdf->SetFont('dejavusans', 'B', 10.5);
    $pdf->SetTextColor($kpi['text'][0], $kpi['text'][1], $kpi['text'][2]);
    $pdf->Cell($kpiWidth - 6, 5.5, $kpi['value'], 0, 0, 'L');
}

$pdf->SetFont('dejavusans', 'B', 11);
$pdf->SetTextColor(17, 24, 39);
$pdf->SetXY(10, 69);
$pdf->Cell(0, 6, 'Détail journalier', 0, 1, 'L');
$pdf->Ln(1);

$html = '';
$html .= '<style>
    .muted{color:#64748b;}
    table{border-collapse:collapse;width:100%;}
    table{table-layout:fixed;}
    th{background-color:#fabd02;color:#000000;font-weight:bold;font-size:10px;padding:6px;border-bottom:1px solid #e2e8f0;vertical-align:top;}
    td{font-size:10px;padding:6px;border-bottom:1px solid #f1f5f9;color:#0f172a;vertical-align:top;}
    .badge{display:inline-block;border:1px solid #e2e8f0;border-radius:999px;padding:2px 8px;font-size:9px;color:#0f172a;background:#ffffff;}
    .badge-warn{border-color:#fcd34d;background:#fffbeb;color:#92400e;}
</style>';
$html .= '<table>';
$html .= '<thead><tr>';
$html .= '<th width="18%">Date</th>';
$html .= '<th width="20%">Statut</th>';
$html .= '<th width="12%" align="right">Jours</th>';
$html .= '<th width="12%" align="right">HS</th>';
$html .= '<th width="38%">Note</th>';
$html .= '</tr></thead><tbody>';

foreach ($rows as $row) {
    $date = (string) ($row['attendance_date'] ?? '');
    $statusKey = strtolower(trim((string) ($row['attendance_status'] ?? '')));
    $statusLabel = $statuses[$statusKey] ?? ($statusKey !== '' ? $statusKey : '—');
    $worked = (float) ($row['worked_days'] ?? 0);
    $hs = (float) ($row['overtime_hours'] ?? 0);
    $note = trim((string) ($row['notes'] ?? ''));

    $noteCell = $note !== '' ? htmlspecialchars($note) : '—';
    if (in_array($statusKey, ['absence', 'maladie'], true) && $note === '') {
        $noteCell = '<span class="badge badge-warn">À justifier</span>';
    }

    $html .= '<tr>';
    $html .= '<td width="18%">' . htmlspecialchars(employeeFormatDateFr($date) ?: $date) . '</td>';
    $html .= '<td width="20%"><span class="badge">' . htmlspecialchars($statusLabel) . '</span></td>';
    $html .= '<td width="12%" align="right">' . htmlspecialchars(fmt_number($worked)) . '</td>';
    $html .= '<td width="12%" align="right">' . htmlspecialchars(fmt_number($hs)) . '</td>';
    $html .= '<td width="38%">' . $noteCell . '</td>';
    $html .= '</tr>';
}

if (!$rows) {
    $html .= '<tr><td colspan="5" class="muted">Aucune donnée pour la période.</td></tr>';
}

$html .= '</tbody></table>';

$pdf->writeHTML($html, true, false, true, false, '');

$baseName = preg_replace('/[^a-z0-9_-]+/i', '_', (string) ($personnel['full_name'] ?? 'employe'));
$fileName = 'pointage_' . $period . '_' . $baseName . '.pdf';

$pdf->Output($fileName, 'I');
exit;
