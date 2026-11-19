<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

// L'export "Tous les dossiers" peut produire plusieurs centaines de pages.
@ini_set('memory_limit', '512M');
@set_time_limit(120);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/CompanyPdfTemplate.php';
require_once __DIR__ . '/../controllers/RhPersonnelController.php';

final class RhPersonnelDirectoryPdf extends TCPDF
{
    use CompanyPdfTemplate;

    public function Header(): void
    {
        $this->drawCompanyPdfHeader();
    }

    public function Footer(): void
    {
        $this->drawCompanyPdfFooter();
    }
}

$pdo = \Database::getConnection();
$controller = new \RhPersonnelController($pdo);
$directory = $controller->model();

$filters = [
    'q' => trim((string) ($_GET['q'] ?? '')),
    'service_id' => trim((string) ($_GET['service_id'] ?? '')),
    'fonction_id' => trim((string) ($_GET['fonction_id'] ?? '')),
    'status_code' => trim((string) ($_GET['status_code'] ?? '')),
    'gender_code' => trim((string) ($_GET['gender_code'] ?? '')),
    'site_id' => trim((string) ($_GET['site_id'] ?? '')),
    'scope' => (string) ($_GET['scope'] ?? 'active'),
    'search_mode' => (string) ($_GET['search_mode'] ?? 'include'),
];
if (!in_array($filters['scope'], ['active', 'inactive', 'all'], true)) {
    $filters['scope'] = 'active';
}
if (!in_array($filters['search_mode'], ['include', 'exclude'], true)) {
    $filters['search_mode'] = 'include';
}

$rows = $directory->getPersonnelRowsForExport($filters, 10000);

function rh_personnel_pdf_h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function rh_personnel_pdf_date($value): string
{
    $value = trim((string) $value);
    if ($value === '' || $value === '0000-00-00') {
        return '-';
    }
    $timestamp = strtotime($value);
    return $timestamp ? date('d/m/Y', $timestamp) : $value;
}

$scopeLabel = [
    'active' => 'Personnel en poste',
    'inactive' => 'Personnel sorti',
    'all' => 'Tous les dossiers',
][$filters['scope']] ?? 'Personnel';

$rowsHtml = '';
foreach ($rows as $index => $row) {
    $isActive = (int) ($row['is_active'] ?? 0) === 1;
    $rowBackground = $index % 2 === 0 ? '#ffffff' : '#f7f7f7';
    $stateStyle = $isActive
        ? 'color:#166534;font-weight:bold;'
        : 'color:#991b1b;font-weight:bold;';
    $rowsHtml .= '<tr style="background-color:' . $rowBackground . '">
        <td width="10%" class="code">' . rh_personnel_pdf_h($row['matricule'] ?? '-') . '</td>
        <td width="20%" class="name">' . rh_personnel_pdf_h($row['full_name'] ?? '-') . '</td>
        <td width="16%">' . rh_personnel_pdf_h($row['service_label'] ?? '-') . '</td>
        <td width="17%">' . rh_personnel_pdf_h($row['fonction_label'] ?? '-') . '</td>
        <td width="13%">' . rh_personnel_pdf_h($row['statut_label'] ?? '-') . '</td>
        <td width="10%">' . rh_personnel_pdf_h($row['site_label'] ?? '-') . '</td>
        <td width="8%" class="center">' . rh_personnel_pdf_h(rh_personnel_pdf_date($row['hire_date'] ?? '')) . '</td>
        <td width="6%" class="center" style="' . $stateStyle . '">' . ($isActive ? 'ACTIF' : 'SORTI') . '</td>
    </tr>';
}

function rh_personnel_draw_kpi_card(TCPDF $pdf, float $x, string $icon, string $label, string $value, bool $yellow = false): void
{
    $y = 40.5;
    $width = 65.5;
    $height = 20;
    $accent = $yellow ? [250, 189, 2] : [23, 23, 23];

    $pdf->SetFillColor(250, 250, 250);
    $pdf->SetDrawColor(212, 212, 212);
    $pdf->RoundedRect($x, $y, $width, $height, 3, '1111', 'DF');
    $pdf->SetFillColor(...$accent);
    $pdf->RoundedRect($x, $y, 2.6, $height, 1, '1001', 'F');

    $cx = $x + 10.3;
    $cy = $y + 10;
    $pdf->RoundedRect($cx - 5.8, $cy - 5.8, 11.6, 11.6, 2.8, '1111', 'F');
    $pdf->SetDrawColor($yellow ? 0 : 255, $yellow ? 0 : 255, $yellow ? 0 : 255);
    $pdf->SetLineWidth(.7);

    if ($icon === 'people') {
        $pdf->Ellipse($cx, $cy - 2.1, 1.8, 1.8, 0, 0, 360, 'D');
        $pdf->RoundedRect($cx - 3.1, $cy + .4, 6.2, 3.3, 1.3, '1111', 'D');
    } elseif ($icon === 'scope') {
        $pdf->Ellipse($cx, $cy, 3.2, 3.2, 0, 0, 360, 'D');
        $pdf->Ellipse($cx, $cy, 1.1, 1.1, 0, 0, 360, 'D');
    } elseif ($icon === 'search') {
        $pdf->Ellipse($cx - .7, $cy - .7, 2.5, 2.5, 0, 0, 360, 'D');
        $pdf->Line($cx + 1.2, $cy + 1.2, $cx + 3.3, $cy + 3.3);
    } else {
        $pdf->Line($cx - 3.2, $cy - 2, $cx + 3.2, $cy - 2);
        $pdf->Line($cx - 2.1, $cy, $cx + 3.2, $cy);
        $pdf->Line($cx - 1, $cy + 2, $cx + 3.2, $cy + 2);
    }

    $textX = $x + 18.5;
    $textWidth = $width - 21.5;
    $pdf->SetXY($textX, $y + 3.1);
    $pdf->SetFont('dejavusans', 'B', 6.1);
    $pdf->SetTextColor(115, 115, 115);
    $pdf->Cell($textWidth, 4, mb_strtoupper($label, 'UTF-8'), 0, 1, 'L', false, '', 1);
    $fontSize = 9.3;
    $pdf->SetFont('dejavusans', 'B', $fontSize);
    while ($fontSize > 6.8 && $pdf->GetStringWidth($value) > $textWidth) {
        $fontSize -= .25;
        $pdf->SetFont('dejavusans', 'B', $fontSize);
    }
    $pdf->SetXY($textX, $y + 8.6);
    $pdf->SetTextColor(23, 23, 23);
    $pdf->Cell($textWidth, 6, $value, 0, 1, 'L', false, '', 1);
}
$rowsHtml = $rowsHtml ?: '<tr><td colspan="8" class="center muted">Aucun collaborateur trouve.</td></tr>';

$html = '
<style>
    body { font-family: dejavusans; color:#171717; font-size:7.5px; }
    .kpi { border:1px solid #d4d4d4; background-color:#fafafa; padding:7px; }
    .kpi-value { color:#171717; font-size:10px; font-weight:bold; }
    .kpi-label { color:#737373; font-size:6.5px; text-transform:uppercase; }
    .section-title { color:#171717; font-size:11px; font-weight:bold; border-bottom:2px solid #fabd02; padding-bottom:4px; }
    table { border-collapse:collapse; width:100%; }
    th { background-color:#fabd02; border:1px solid #171717; color:#000000; padding:6px 4px; font-size:7px; font-weight:bold; text-transform:uppercase; }
    td { border:1px solid #d4d4d4; padding:4px; vertical-align:middle; }
    .name { font-weight:bold; color:#171717; }
    .code { font-weight:bold; color:#525252; }
    .center { text-align:center; }
    .muted { color:#64748b; }
</style>
<div class="section-title">Registre des collaborateurs</div>
<table cellpadding="4">
    <thead>
        <tr>
            <th width="10%">Matricule</th>
            <th width="20%">Collaborateur</th>
            <th width="16%">Service</th>
            <th width="17%">Fonction</th>
            <th width="13%">Statut RH</th>
            <th width="10%">Site</th>
            <th width="8%">Entree</th>
            <th width="6%">Etat</th>
        </tr>
    </thead>
    <tbody>' . $rowsHtml . '</tbody>
</table>';

$pdf = new RhPersonnelDirectoryPdf('L', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('FINEA');
$pdf->SetAuthor('BANAMUR INDUSTRIES ET TECHNOLOGIES');
$pdf->SetTitle('Liste du personnel RH');
$pdf->companyPdfTitle = 'Liste du personnel RH';
$pdf->companyPdfType = 'Registre du personnel';
$pdf->companyPdfId = 'RH-LISTE-' . date('Ymd-His');
$pdf->companyPdfCode = 'RH-PERS-01';
$pdf->companyPdfQrPayload = $pdf->companyPdfId;
$pdf->companyPdfQrFirstPageOnly = true;
$pdf->SetMargins(9, 38, 9);
$pdf->SetHeaderMargin(0);
$pdf->SetFooterMargin(8);
$pdf->SetAutoPageBreak(true, 25);
$pdf->AddPage();
$searchLabel = $filters['q'] !== '' ? $filters['q'] : 'Aucun filtre';
$modeLabel = $filters['search_mode'] === 'exclude' ? 'Exclusion' : 'Inclusion';
rh_personnel_draw_kpi_card($pdf, 9, 'people', 'Effectif exporte', number_format(count($rows), 0, ',', ' '), true);
rh_personnel_draw_kpi_card($pdf, 78.5, 'scope', 'Perimetre', $scopeLabel);
rh_personnel_draw_kpi_card($pdf, 148, 'search', 'Recherche', $searchLabel, true);
rh_personnel_draw_kpi_card($pdf, 217.5, 'mode', 'Mode de recherche', $modeLabel);
$pdf->SetY(64);
$pdf->writeHTML($html, true, false, true, false, '');
$pdf->Output('liste_personnel_rh_' . date('Ymd_His') . '.pdf', 'I');
exit;
