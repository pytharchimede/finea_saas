<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../model/CompanyExportBranding.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/EmployeeLegalRequestManager.php';
require_once __DIR__ . '/../employe/includes/helpers.php';
require_once __DIR__ . '/../model/AuditTrail.php';

class PDFWowEmployeeExport extends \TCPDF
{
    public string $companyName = '';
    public string $documentTitle = 'Demandes selectionnees';
    public string $logoPath = '';

    public function Header(): void
    {
        $this->SetFillColor(255, 255, 255);
        $this->Rect(0, 0, $this->getPageWidth(), 28, 'F');
        $this->SetDrawColor(203, 213, 225);
        $this->Line(12, 24.5, $this->getPageWidth() - 12, 24.5);
        $this->SetFillColor(...CompanyExportBranding::rgbArray('primary'));
        $this->Rect(12, 24.5, 34, 1.1, 'F');

        if ($this->logoPath !== '' && is_file($this->logoPath)) {
            $info = @getimagesize($this->logoPath);
            if ($info && in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) $this->Image($this->logoPath, 12, 5.8, min(15, 15*$info[0]/$info[1]), 0, '', '', '', false, 300);
        }

        $this->SetXY(31, 7.4);
        $this->SetFont('dejavusans', 'B', 15.5);
        $this->SetTextColor(15, 23, 42);
        $this->Cell($this->getPageWidth()-115, 5.8, $this->companyName, 0, 1, 'L', false, '', 1);
        $this->SetX(31);
        $this->SetFont('dejavusans', '', 8.5);
        $this->SetTextColor(71, 85, 105);
        $this->Cell(0, 4.4, 'Direction administrative - Document officiel', 0, 1, 'L');

        $this->SetXY($this->getPageWidth() - 80, 8.4);
        $this->SetFont('dejavusans', 'B', 8.6);
        $this->SetTextColor(51, 65, 85);
        $this->Cell(68, 4.5, $this->documentTitle, 0, 1, 'R');
        $this->SetX($this->getPageWidth() - 80);
        $this->SetFont('dejavusans', '', 7.6);
        $this->SetTextColor(100, 116, 139);
        $this->Cell(68, 4.3, 'Export consolide du dossier', 0, 1, 'R');
    }

    public function Footer(): void
    {
        $this->SetY(-16);
        $this->SetDrawColor(214, 219, 228);
        $this->Line(12, $this->GetY(), $this->getPageWidth() - 12, $this->GetY());
        $this->Ln(2.5);
        $this->SetFont('dejavusans', '', 8);
        $this->SetTextColor(100, 116, 139);
        $this->Cell(0, 5, $this->companyName . ' — Espace employé — Page ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, 0, 'C');
    }
}

function app_public_base(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host;
}

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

function parse_selected_ids(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return [];
    }

    $parts = preg_split('/[^0-9]+/', $raw);
    if (!is_array($parts)) {
        return [];
    }

    $ids = array_values(array_unique(array_filter(array_map('intval', $parts), static function (int $v): bool {
        return $v > 0;
    })));

    return array_slice($ids, 0, 500);
}

function request_status_label(array $row): string
{
    $status = strtolower(trim((string) ($row['status'] ?? '')));
    if (($row['request_type'] ?? '') === 'feb' && $status === 'approved') {
        $payload = (array) ($row['payload'] ?? []);
        $legacy = (array) ($payload['legacy'] ?? []);
        $tag = strtolower(trim((string) ($legacy['status_tag'] ?? '')));
        if ($tag === 'decaissed') {
            return 'Décaissée';
        }
        return 'Approuvée';
    }

    return match ($status) {
        'submitted' => 'En attente',
        'approved' => 'Approuvée',
        'rejected' => 'Refusée',
        'cancelled' => 'Annulée',
        default => $status !== '' ? $status : '—',
    };
}

function format_fcfa_pdf(float $value): string
{
    if (!is_finite($value) || $value <= 0) {
        return '0 FCFA';
    }
    return number_format($value, 0, ',', ' ') . ' FCFA';
}

function request_amount_pdf(array $row): float
{
    $payload = (array) ($row['payload'] ?? []);
    if (($row['request_type'] ?? '') === 'feb') {
        return (float) ($payload['montant'] ?? 0);
    }
    if (($row['request_type'] ?? '') === 'reclamation_salariale') {
        return (float) ($payload['montant_manquant'] ?? 0);
    }
    return 0.0;
}

$pdo = \Database::getConnection();
$manager = new \EmployeeLegalRequestManager($pdo);

$userId = (int) ($_SESSION['user_id'] ?? 0);
$userEmail = (string) ($_SESSION['mail_pro'] ?? '');

$personnelProfile = employeeFindPersonnelProfile($pdo, $userEmail);
$actorMatricule = strtoupper(trim((string) ($personnelProfile['matricule'] ?? '')));

$idsRaw = (string) ($_POST['ids'] ?? ($_GET['ids'] ?? ''));
$ids = parse_selected_ids($idsRaw);

if (!$ids) {
    http_response_code(400);
    echo 'Aucune sélection.';
    exit;
}

$rows = $manager->listRequestsByIds($ids);

$allowed = [];
foreach ($rows as $row) {
    $rid = (int) ($row['id'] ?? 0);
    if ($rid <= 0) {
        continue;
    }

    $isOwner = $userId > 0 && (int) ($row['requester_user_id'] ?? 0) === $userId;

    $isOwnerByMatricule = false;
    if (!$isOwner && $actorMatricule !== '' && ($row['request_type'] ?? '') === 'feb') {
        $payloadJson = (string) ($row['payload_json'] ?? '');
        $needle = '"matricule":"' . str_replace('"', '\\"', $actorMatricule) . '"';
        $isOwnerByMatricule = $payloadJson !== '' && strpos($payloadJson, $needle) !== false;
    }

    if ($isOwner || $isOwnerByMatricule) {
        $allowed[] = $row;
    }
}

if (!$allowed) {
    http_response_code(403);
    echo 'Accès refusé.';
    exit;
}

$totalAmount = 0.0;
$countApproved = 0;
$countPending = 0;
$countRejected = 0;
$countCancelled = 0;
foreach ($allowed as $row) {
    $totalAmount += request_amount_pdf($row);

    $status = strtolower(trim((string) ($row['status'] ?? '')));
    if ($status === 'approved') {
        $countApproved++;
    } elseif ($status === 'submitted') {
        $countPending++;
    } elseif ($status === 'rejected') {
        $countRejected++;
    } elseif ($status === 'cancelled') {
        $countCancelled++;
    }
}
$pdf = new PDFWowEmployeeExport('P', 'mm', 'A4');
$pdf->SetCreator(CompanyExportBranding::creator());
$pdf->SetAuthor((string)CompanyBranding::get()['company_name']);
$pdf->SetTitle('Demandes sélectionnées');
$pdf->SetSubject('Demandes selectionnees');
$pdf->SetMargins(12, 30, 12);
$pdf->SetAutoPageBreak(true, 20);
$pdf->companyName = (string)CompanyBranding::get()['company_name'];
$pdf->documentTitle = 'Demandes selectionnees';
$pdf->logoPath = CompanyBranding::logoFile();
$pdf->AddPage();

$pageWidth = $pdf->getPageWidth() - 24;

$displayName = trim((string) ($_SESSION['user_name'] ?? (($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? ''))));
$displayName = trim($displayName);

// Hero
$pdf->SetFillColor(255, 255, 255);
$pdf->SetDrawColor(226, 232, 240);
$pdf->RoundedRect(12, 33, $pageWidth, 30, 4, '1111', 'DF');
$pdf->SetFillColor(...CompanyExportBranding::rgbArray('primary'));
$pdf->RoundedRect(12, 33, 4, 30, 4, '1101', 'F');
$pdf->SetFont('dejavusans', 'B', 18);
$pdf->SetTextColor(15, 23, 42);
$pdf->SetXY(19, 37);
$pdf->Cell(120, 8, 'Demandes sélectionnées', 0, 1, 'L');
$pdf->SetFont('dejavusans', '', 9);
$pdf->SetTextColor(71, 85, 105);
$pdf->SetX(19);
$pdf->Cell(140, 5, 'Récapitulatif des demandes — espace employé', 0, 1, 'L');

$pdf->SetFont('dejavusans', 'B', 11);
$pdf->SetTextColor(71, 85, 105);
$pdf->SetXY(19, 54);
$pdf->Cell(120, 5, $displayName !== '' ? $displayName : '—', 0, 1, 'L');

$pdf->SetFont('dejavusans', 'B', 16);
$pdf->SetTextColor(29, 43, 87);
$pdf->SetXY(116, 42);
$pdf->Cell(46, 8, format_fcfa_pdf((float) $totalAmount), 0, 1, 'R');
$pdf->SetFont('dejavusans', '', 8.2);
$pdf->SetTextColor(71, 85, 105);
$pdf->SetXY(116, 51);
$pdf->Cell(46, 4, 'Export ' . date('d/m/Y H:i'), 0, 1, 'R');

$qrUrl = app_public_base() . '/employe/mes_demandes_employe.php';
$style = ['border' => 0, 'padding' => 0, 'fgcolor' => [15, 23, 42], 'bgcolor' => false];
$pdf->SetFillColor(255, 255, 255);
$pdf->SetDrawColor(226, 232, 240);
$pdf->RoundedRect(166, 33, 32, 30, 4, '1111', 'DF');
$pdf->write2DBarcode($qrUrl, 'QRCODE,H', 172, 40.2, 19, 19, $style, 'N');
$pdf->SetFont('dejavusans', '', 6.8);
$pdf->SetTextColor(71, 85, 105);
$pdf->SetXY(170, 35.4);
$pdf->Cell(28, 4, 'QR demandes', 0, 0, 'C');

// KPI strip
$kpiY = 68;
$kpiGap = 4;
$kpiWidth = ($pageWidth - ($kpiGap * 3)) / 4;
$kpis = [
    ['label' => 'Sélection', 'value' => (string) count($allowed), 'fill' => [239, 246, 255], 'text' => [29, 78, 216]],
    ['label' => 'Approuvées', 'value' => (string) $countApproved, 'fill' => [240, 253, 244], 'text' => [21, 128, 61]],
    ['label' => 'En attente', 'value' => (string) $countPending, 'fill' => [255, 251, 235], 'text' => [146, 64, 14]],
    ['label' => 'Refusées', 'value' => (string) $countRejected, 'fill' => [255, 241, 242], 'text' => [190, 18, 60]],
];
foreach ($kpis as $index => $kpi) {
    $x = 12 + (($kpiWidth + $kpiGap) * $index);
    $pdf->SetFillColor($kpi['fill'][0], $kpi['fill'][1], $kpi['fill'][2]);
    $pdf->SetDrawColor(226, 232, 240);
    $pdf->RoundedRect($x, $kpiY, $kpiWidth, 16, 3, '1111', 'DF');
    $pdf->SetXY($x + 3, $kpiY + 2.5);
    $pdf->SetFont('dejavusans', '', 7.2);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->Cell($kpiWidth - 6, 3.8, (string) $kpi['label'], 0, 2, 'L');
    $pdf->SetX($x + 3);
    $pdf->SetFont('dejavusans', 'B', 10.5);
    $pdf->SetTextColor($kpi['text'][0], $kpi['text'][1], $kpi['text'][2]);
    $pdf->Cell($kpiWidth - 6, 5.5, (string) $kpi['value'], 0, 0, 'L');
}

// Repositionner le curseur sous la bande KPI (sinon writeHTML() peut démarrer à droite et être coupé)
$pdf->SetXY(12, 89);
$pdf->SetTextColor(15, 23, 42);
$pdf->SetFont('dejavusans', 'B', 11);
$pdf->Cell(0, 6, 'Listing', 0, 1, 'L');
$pdf->Ln(1);

$html = '';
$html .= '<style>
    table{border-collapse:collapse;width:100%;table-layout:fixed;}
    th{background-color:#fabd02;color:#000000;font-weight:bold;font-size:9.4px;padding:6px;border-bottom:1px solid #e2e8f0;vertical-align:top;}
    td{font-size:9.2px;padding:6px;border-bottom:1px solid #f1f5f9;color:#0f172a;vertical-align:top;}
    .muted{color:#64748b;}
    .badge{display:inline-block;border:1px solid #e2e8f0;border-radius:999px;padding:2px 8px;font-size:8.7px;color:#0f172a;background:#ffffff;}
    .badge-warn{border-color:#fcd34d;background:#fffbeb;color:#92400e;}
    .badge-ok{border-color:#86efac;background:#dcfce7;color:#166534;}
    .badge-bad{border-color:#fda4af;background:#ffe4e6;color:#9f1239;}
</style>';

$html .= '<table style="margin-top:10px;">';
$html .= '<thead>';
$html .= '<tr>';
$html .= '<th width="8%"><b>Réf.</b></th>';
$html .= '<th width="18%"><b>Type</b></th>';
$html .= '<th width="34%"><b>Titre</b></th>';
$html .= '<th width="14%"><b>Statut</b></th>';
$html .= '<th width="14%"><b>Date</b></th>';
$html .= '<th width="12%"><b>Montant</b></th>';
$html .= '</tr>';
$html .= '</thead>';
$html .= '<tbody>';

foreach ($allowed as $row) {
    $amount = request_amount_pdf($row);

    $titleCell = (string) ($row['title'] ?? '');
    if (($row['request_type'] ?? '') === 'feb') {
        $febDisplay = (array) ($row['feb_display'] ?? \EmployeeLegalRequestManager::extractFebDisplay($row));
        $parts = [];
        $parts[] = '<b>' . htmlspecialchars((string) ($febDisplay['chantier_label'] ?? ('CHANTIER N° FEB-' . (int) ($row['id'] ?? 0))), ENT_QUOTES, 'UTF-8') . '</b>';
        $parts[] = '<b>Demandeur :</b> ' . htmlspecialchars((string) ($febDisplay['demandeur'] ?? ($row['requester_name'] ?? '')), ENT_QUOTES, 'UTF-8');
        $parts[] = '<b>Description :</b> ' . htmlspecialchars((string) ($febDisplay['description'] ?? $titleCell), ENT_QUOTES, 'UTF-8');
        $parts[] = '<b>Créée le :</b> ' . htmlspecialchars((string) (($febDisplay['created_date'] ?? '') ?: substr((string) ($row['submitted_at'] ?? ''), 0, 10)), ENT_QUOTES, 'UTF-8');
        $parts[] = '<b>Montant :</b> ' . htmlspecialchars((string) ($febDisplay['montant_label'] ?? format_fcfa_pdf($amount)), ENT_QUOTES, 'UTF-8');
        $titleCell = implode('<br>', $parts);
    } elseif (($row['request_type'] ?? '') === 'reclamation_salariale') {
        $claimDisplay = (array) ($row['salary_claim_display'] ?? \EmployeeLegalRequestManager::extractSalaryClaimDisplay($row));
        $parts = [];
        $parts[] = '<b>' . htmlspecialchars((string) ($claimDisplay['objet_label'] ?? $titleCell), ENT_QUOTES, 'UTF-8') . '</b>';
        $parts[] = '<b>Mois concerné :</b> ' . htmlspecialchars((string) ($claimDisplay['mois_label'] ?? '—'), ENT_QUOTES, 'UTF-8');
        $parts[] = '<b>Demandeur :</b> ' . htmlspecialchars((string) ($claimDisplay['demandeur'] ?? ($row['requester_name'] ?? '')), ENT_QUOTES, 'UTF-8');
        $parts[] = '<b>Montant manquant :</b> ' . htmlspecialchars((string) ($claimDisplay['montant_label'] ?? format_fcfa_pdf($amount)), ENT_QUOTES, 'UTF-8');
        $parts[] = '<b>Détails :</b> ' . htmlspecialchars((string) ($claimDisplay['details_differend'] ?? $titleCell), ENT_QUOTES, 'UTF-8');
        $titleCell = implode('<br>', $parts);
    } else {
        $titleCell = htmlspecialchars($titleCell, ENT_QUOTES, 'UTF-8');
    }

    $statusKey = strtolower(trim((string) ($row['status'] ?? '')));
    $label = request_status_label($row);
    $badgeClass = 'badge';
    if (in_array($statusKey, ['approved'], true)) {
        $badgeClass = 'badge badge-ok';
    } elseif (in_array($statusKey, ['rejected', 'cancelled'], true)) {
        $badgeClass = 'badge badge-bad';
    } elseif ($statusKey === 'submitted') {
        $badgeClass = 'badge badge-warn';
    }

    $html .= '<tr>';
    $html .= '<td width="8%">#' . (int) ($row['id'] ?? 0) . '</td>';
    $html .= '<td width="18%">' . htmlspecialchars((string) ($row['type_label'] ?? $row['request_type'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>';
    $html .= '<td width="34%">' . $titleCell . '</td>';
    $html .= '<td width="14%"><span class="' . $badgeClass . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span></td>';
    $html .= '<td width="14%">' . htmlspecialchars(employeeFormatDateFr((string) ($row['submitted_at'] ?? '')) ?: format_date_fr((string) ($row['submitted_at'] ?? '')), ENT_QUOTES, 'UTF-8') . '</td>';
    $html .= '<td width="12%" align="right">' . htmlspecialchars($amount > 0 ? format_fcfa_pdf($amount) : '—', ENT_QUOTES, 'UTF-8') . '</td>';
    $html .= '</tr>';
}

$html .= '</tbody></table>';

$pdf->SetFont('dejavusans', '', 9);
$pdf->SetTextColor(15, 23, 42);
$pdf->writeHTML($html, true, false, true, false, '');

$filename = 'demandes_selection_' . date('Ymd_His') . '.pdf';
\AuditTrail::logFileEvent('export', [
    'module' => 'request',
    'action' => 'export_employee_requests_pdf',
    'entity_type' => 'employee_legal_request_selection',
    'entity_label' => $filename,
    'format' => 'pdf',
    'user_id' => $_SESSION['user_id'] ?? null,
    'user_name' => $_SESSION['user_name'] ?? $_SESSION['username'] ?? null,
    'details' => [
        'selected_count' => count($ids),
        'allowed_count' => count($allowed),
        'total_amount' => $totalAmount,
        'approved_count' => $countApproved,
        'pending_count' => $countPending,
        'rejected_count' => $countRejected,
        'cancelled_count' => $countCancelled,
    ],
], $pdo);
$pdf->Output($filename, 'I');
