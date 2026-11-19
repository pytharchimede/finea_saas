<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../employe/includes/helpers.php';

class PDFWowEmployeeExport extends TCPDF
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

function employeeLower(string $value): string
{
    if (function_exists('mb_strtolower')) {
        return (string) mb_strtolower($value, 'UTF-8');
    }
    return strtolower($value);
}

function format_fcfa_pdf(float $value): string
{
    if (!is_finite($value) || $value <= 0) {
        return '0 FCFA';
    }
    return number_format($value, 0, ',', ' ') . ' FCFA';
}

function parseDateYmd(?string $raw): string
{
    $raw = trim((string) $raw);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) ? $raw : '';
}

function decaissementStatus(array $row, string $todayYmd): array
{
    $approuve = (int) ($row['approuve'] ?? 0);
    $etat = (int) ($row['etat_fiche'] ?? 0);
    $decaisse = (int) ($row['decaisse'] ?? 0);
    $otp = (int) ($row['otp_valide'] ?? 0);
    $conforme = (int) ($row['conforme'] ?? 0);

    $dateMin = trim((string) ($row['date_decaissement_minimum'] ?? ''));
    $dateReport = '';
    if ($dateMin !== '' && preg_match('/^\d{4}-\d{2}-\d{2}/', $dateMin)) {
        $dateReport = substr($dateMin, 0, 10);
    }

    if ($decaisse === 1) {
        return ['key' => 'decaissee', 'label' => 'Décaissée', 'date_report' => ''];
    }

    if ($dateReport !== '' && $dateReport > $todayYmd) {
        return ['key' => 'reportee', 'label' => 'Reportée', 'date_report' => $dateReport];
    }

    if ($approuve === 2 || $etat === 2) {
        return ['key' => 'refusee', 'label' => 'Refusée', 'date_report' => ''];
    }

    if ($otp === 1) {
        return ['key' => 'otp', 'label' => 'OTP validé', 'date_report' => ''];
    }

    if ($approuve === 1) {
        return ['key' => 'acceptee', 'label' => 'Acceptée', 'date_report' => ''];
    }

    if ($conforme === 1) {
        return ['key' => 'conforme', 'label' => 'Conforme', 'date_report' => ''];
    }

    return ['key' => 'attente', 'label' => 'En attente', 'date_report' => ''];
}

function app_public_base(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host;
}

$todayYmd = date('Y-m-d');

$connectedName = employeeCurrentUserName();
$connectedNameLower = employeeLower($connectedName);
$connectedUserId = (int) ($_SESSION['user_id'] ?? 0);

$filterQuery = trim((string) ($_GET['q'] ?? ''));
$filterStatusRaw = strtolower(trim((string) ($_GET['status'] ?? '')));
$filterDateFrom = parseDateYmd($_GET['date_from'] ?? null);
$filterDateTo = parseDateYmd($_GET['date_to'] ?? null);

$allowedStatuses = ['attente', 'acceptee', 'refusee', 'reportee', 'decaissee', 'conforme', 'otp'];
$filterStatus = in_array($filterStatusRaw, $allowedStatuses, true) ? $filterStatusRaw : '';

$pdo = \Database::getConnection();

$where = [];
$params = [];

$tokens = preg_split('/\s+/u', $connectedNameLower) ?: [];
$tokens = array_values(array_filter(array_unique(array_map('trim', $tokens)), static function (string $t): bool {
    return $t !== '' && (function_exists('mb_strlen') ? mb_strlen($t, 'UTF-8') : strlen($t)) >= 3;
}));

$beneficiaryScope = [];
if (!$tokens) {
    $beneficiaryScope[] = 'LOWER(TRIM(f.beficiaire_fiche)) LIKE :benef_all';
    $params['benef_all'] = '%' . $connectedNameLower . '%';
} else {
    foreach ($tokens as $idx => $token) {
        $key = ':benef_t' . $idx;
        $beneficiaryScope[] = 'LOWER(TRIM(f.beficiaire_fiche)) LIKE ' . $key;
        $params[ltrim($key, ':')] = '%' . $token . '%';
    }
}
$scopeSql = '(' . implode(' AND ', $beneficiaryScope) . ')';
if ($connectedUserId > 0) {
    $scopeSql = '(' . $scopeSql . ' OR EXISTS (SELECT 1 FROM employee_legal_requests elr WHERE elr.id=f.feb_request_id AND elr.requester_user_id=:connected_user_id AND elr.request_type="feb"))';
    $params['connected_user_id'] = $connectedUserId;
}
$where[] = $scopeSql;

if ($filterDateFrom !== '') {
    $where[] = 'f.date_creat_fiche >= :date_from';
    $params['date_from'] = $filterDateFrom . ' 00:00:00';
}
if ($filterDateTo !== '') {
    $where[] = 'f.date_creat_fiche <= :date_to';
    $params['date_to'] = $filterDateTo . ' 23:59:59';
}

if ($filterQuery !== '') {
    $where[] = '(
        f.num_fiche LIKE :q
        OR f.beficiaire_fiche LIKE :q
        OR f.designation_fiche LIKE :q
        OR f.precision_fiche LIKE :q
        OR f.details_demande LIKE :q
        OR f.mode_paiement LIKE :q
        OR f.num_piece LIKE :q
        OR f.entreprise LIKE :q
        OR f.code_autorisation_feb LIKE :q
        OR c.titre LIKE :q
        OR c.code LIKE :q
    )';
    $params['q'] = '%' . $filterQuery . '%';
}

$sql = '
    SELECT
        f.num_fiche,
        f.montant_fiche,
        f.date_creat_fiche,
        f.designation_fiche,
        f.precision_fiche,
        f.details_demande,
        f.mode_paiement,
        f.approuve,
        f.conforme,
        f.decaisse,
        f.otp_valide,
        f.etat_fiche,
        f.date_decaissement_minimum,
        c.code AS chantier_code,
        c.titre AS chantier_titre
    FROM fiche_decaissement f
    LEFT JOIN chantier_banamur c ON c.id = f.chantier_id
    WHERE ' . implode(' AND ', $where) . '
    ORDER BY f.date_creat_fiche DESC
    LIMIT 500
';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$filtered = [];
foreach ($rows as $r) {
    $status = decaissementStatus($r, $todayYmd);
    $r['_status'] = $status;
    if ($filterStatus !== '' && ($status['key'] ?? '') !== $filterStatus) {
        continue;
    }
    $filtered[] = $r;
}
$rows = $filtered;

$metrics = [
    'count' => 0,
    'total' => 0.0,
    'acceptee_count' => 0,
    'acceptee_total' => 0.0,
    'refusee_count' => 0,
    'refusee_total' => 0.0,
    'reportee_count' => 0,
    'reportee_total' => 0.0,
    'decaissee_count' => 0,
    'decaissee_total' => 0.0,
];

foreach ($rows as $r) {
    $amount = (float) ($r['montant_fiche'] ?? 0);
    $metrics['count']++;
    $metrics['total'] += max(0.0, $amount);

    $k = (string) (($r['_status']['key'] ?? '') ?: '');
    if ($k === 'acceptee') {
        $metrics['acceptee_count']++;
        $metrics['acceptee_total'] += max(0.0, $amount);
    } elseif ($k === 'refusee') {
        $metrics['refusee_count']++;
        $metrics['refusee_total'] += max(0.0, $amount);
    } elseif ($k === 'reportee') {
        $metrics['reportee_count']++;
        $metrics['reportee_total'] += max(0.0, $amount);
    } elseif ($k === 'decaissee') {
        $metrics['decaissee_count']++;
        $metrics['decaissee_total'] += max(0.0, $amount);
    }
}

$pdf = new PDFWowEmployeeExport('P', 'mm', 'A4');
$pdf->SetCreator('Banamur');
$pdf->SetAuthor('Banamur');
$pdf->SetTitle('Mes décaissements');
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 18);
$pdf->companyName = 'FIDESTCI / BANAMUR';
$pdf->AddPage();

$pageWidth = $pdf->getPageWidth() - 20;

// Hero
$pdf->SetFillColor(31, 41, 55);
$pdf->RoundedRect(10, 10, $pageWidth, 34, 4, '1111', 'F');
$pdf->SetFont('dejavusans', 'B', 18);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetXY(14, 14);
$pdf->Cell(110, 8, 'Mes décaissements', 0, 1, 'L');
$pdf->SetFont('dejavusans', '', 9);
$pdf->SetTextColor(226, 232, 240);
$pdf->SetX(14);
$pdf->Cell(120, 5, 'Récap financier — export PDF premium', 0, 1, 'L');

$pdf->SetFont('dejavusans', 'B', 11);
$pdf->SetTextColor(226, 232, 240);
$pdf->SetXY(14, 33);
$pdf->Cell(120, 5, trim($connectedName) !== '' ? $connectedName : '—', 0, 1, 'L');

$pdf->SetFont('dejavusans', 'B', 16);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetXY(128, 18);
$pdf->Cell(46, 8, format_fcfa_pdf((float) $metrics['total']), 0, 1, 'R');
$pdf->SetFont('dejavusans', '', 8.2);
$pdf->SetTextColor(226, 232, 240);
$pdf->SetXY(128, 27);
$pdf->Cell(46, 4, 'Export ' . date('d/m/Y H:i'), 0, 1, 'R');

$query = http_build_query([
    'q' => $filterQuery,
    'status' => $filterStatus,
    'date_from' => $filterDateFrom,
    'date_to' => $filterDateTo,
]);
$qrUrl = app_public_base() . '/employe/mes_decaissements_employe.php' . ($query ? ('?' . $query) : '');
$style = ['border' => 0, 'padding' => 0, 'fgcolor' => [255, 255, 255], 'bgcolor' => false];
$pdf->write2DBarcode($qrUrl, 'QRCODE,H', 176, 14, 22, 22, $style, 'N');
$pdf->SetFont('dejavusans', '', 6.8);
$pdf->SetTextColor(226, 232, 240);
$pdf->SetXY(170, 37.5);
$pdf->Cell(28, 4, 'QR récap', 0, 0, 'C');

// KPI strip
$kpiY = 48;
$kpiGap = 3;
$kpiCount = 5;
$kpiWidth = ($pageWidth - ($kpiGap * ($kpiCount - 1))) / $kpiCount;
$kpis = [
    ['label' => 'Fiches', 'value' => (string) (int) $metrics['count'], 'fill' => [239, 246, 255], 'text' => [29, 78, 216]],
    ['label' => 'Décaissées', 'value' => format_fcfa_pdf((float) $metrics['decaissee_total']), 'fill' => [240, 253, 244], 'text' => [21, 128, 61]],
    ['label' => 'Acceptées', 'value' => format_fcfa_pdf((float) $metrics['acceptee_total']), 'fill' => [238, 242, 255], 'text' => [79, 70, 229]],
    ['label' => 'Refusées', 'value' => format_fcfa_pdf((float) $metrics['refusee_total']), 'fill' => [255, 241, 242], 'text' => [190, 18, 60]],
    ['label' => 'Reportées', 'value' => format_fcfa_pdf((float) $metrics['reportee_total']), 'fill' => [255, 251, 235], 'text' => [146, 64, 14]],
];

foreach ($kpis as $index => $kpi) {
    $x = 10 + (($kpiWidth + $kpiGap) * $index);
    $pdf->SetFillColor($kpi['fill'][0], $kpi['fill'][1], $kpi['fill'][2]);
    $pdf->SetDrawColor(226, 232, 240);
    $pdf->RoundedRect($x, $kpiY, $kpiWidth, 16, 3, '1111', 'DF');

    $pdf->SetXY($x + 3, $kpiY + 2.5);
    $pdf->SetFont('dejavusans', '', 7.2);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->Cell($kpiWidth - 6, 3.8, (string) $kpi['label'], 0, 2, 'L');

    $pdf->SetX($x + 3);
    $pdf->SetFont('dejavusans', 'B', 9.6);
    $pdf->SetTextColor($kpi['text'][0], $kpi['text'][1], $kpi['text'][2]);
    $pdf->Cell($kpiWidth - 6, 5.5, (string) $kpi['value'], 0, 0, 'L');
}

$pdf->SetXY(10, 69);
$pdf->SetTextColor(15, 23, 42);
$pdf->SetFont('dejavusans', 'B', 11);
$pdf->Cell(0, 6, 'Résultats', 0, 1, 'L');
$pdf->Ln(1);

$pdf->SetFont('dejavusans', '', 9);

$html = '';
$html .= '<style>
    table{border-collapse:collapse;width:100%;table-layout:fixed;}
    th{background-color:#fabd02;color:#000000;font-weight:bold;font-size:9.4px;padding:6px;border-bottom:1px solid #e2e8f0;vertical-align:top;}
    td{font-size:9.2px;padding:6px;border-bottom:1px solid #f1f5f9;color:#0f172a;vertical-align:top;}
    .muted{color:#64748b;}
    .badge{display:inline-block;border:1px solid #e2e8f0;border-radius:999px;padding:2px 8px;font-size:8.7px;color:#0f172a;background:#ffffff;}
    .badge-warn{border-color:#fcd34d;background:#fffbeb;color:#92400e;}
</style>';
$html .= '<table>';
$html .= '<thead><tr>';
$html .= '<th width="12%">N° fiche</th>';
$html .= '<th width="16%">Date</th>';
$html .= '<th width="22%">Chantier</th>';
$html .= '<th width="28%">Désignation</th>';
$html .= '<th width="12%">Statut</th>';
$html .= '<th width="10%">Montant</th>';
$html .= '</tr></thead><tbody>';

foreach ($rows as $r) {
    $num = (string) ($r['num_fiche'] ?? '');
    $date = (string) ($r['date_creat_fiche'] ?? '');
    $chantier = trim((string) (($r['chantier_code'] ?? '') . ' ' . ($r['chantier_titre'] ?? '')));
    $designation = trim((string) ($r['designation_fiche'] ?? ''));
    $precision = trim((string) ($r['precision_fiche'] ?? ''));
    $details = trim((string) ($r['details_demande'] ?? ''));
    $status = (array) ($r['_status'] ?? []);
    $statusLabel = (string) ($status['label'] ?? '—');
    $dateReport = (string) ($status['date_report'] ?? '');
    $montant = (float) ($r['montant_fiche'] ?? 0);

    $statusCell = '<span class="badge">' . htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') . '</span>';
    if (($status['key'] ?? '') === 'reportee' && $dateReport !== '') {
        $statusCell = '<span class="badge badge-warn">Reportée au ' . htmlspecialchars(employeeFormatDateFr($dateReport) ?: $dateReport, ENT_QUOTES, 'UTF-8') . '</span>';
    }

    $designationFull = $designation;
    if ($precision !== '') {
        $designationFull .= ' — ' . $precision;
    }

    $designationCell = htmlspecialchars($designationFull !== '' ? $designationFull : '—', ENT_QUOTES, 'UTF-8');
    if ($details !== '') {
        $designationCell .= '<br><span class="muted"><b>Détails :</b> ' . nl2br(htmlspecialchars($details, ENT_QUOTES, 'UTF-8'), false) . '</span>';
    }

    $html .= '<tr>';
    $html .= '<td width="12%"><b>' . htmlspecialchars($num, ENT_QUOTES, 'UTF-8') . '</b></td>';
    $html .= '<td width="16%">' . htmlspecialchars(format_date_fr($date), ENT_QUOTES, 'UTF-8') . '</td>';
    $html .= '<td width="22%">' . htmlspecialchars($chantier !== '' ? $chantier : '—', ENT_QUOTES, 'UTF-8') . '</td>';
    $html .= '<td width="28%">' . $designationCell . '</td>';
    $html .= '<td width="12%">' . $statusCell . '</td>';
    $html .= '<td width="10%" align="right"><b>' . htmlspecialchars(format_fcfa_pdf($montant), ENT_QUOTES, 'UTF-8') . '</b></td>';
    $html .= '</tr>';
}

if (!$rows) {
    $html .= '<tr><td colspan="6" class="muted">Aucune donnée selon les filtres.</td></tr>';
}

$html .= '</tbody></table>';

$pdf->writeHTML($html, true, false, true, false, '');

$fileName = 'mes_decaissements_' . date('Ymd_His') . '.pdf';
$pdf->Output($fileName, 'I');
exit;

function format_date_fr(?string $value): string
{
    if (!$value) {
        return '—';
    }
    try {
        return (new DateTime($value))->format('d/m/Y H:i');
    } catch (Throwable $e) {
        return (string) $value;
    }
}
