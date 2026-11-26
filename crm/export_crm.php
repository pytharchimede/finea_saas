<?php
if (ob_get_level() === 0) {
    ob_start();
}
require_once __DIR__ . '/../auth_check.php';
AccessManager::requirePageAccess();
require_once __DIR__ . '/../controllers/CrmController.php';
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

require_once __DIR__.'/../model/CompanyPdfTemplate.php';
require_once __DIR__.'/../model/CompanyExportBranding.php';
$brand=CompanyBranding::get();
$palette=CompanyBranding::palette();
$controller = new CrmController();
$dataset = strtolower(trim((string)($_GET['dataset'] ?? 'prospects')));
$format = strtolower(trim((string)($_GET['format'] ?? 'xlsx')));
$allowed = ['prospects', 'clients', 'opportunites', 'rdv'];
if (!in_array($dataset, $allowed, true)) {
    $dataset = 'prospects';
}

$titleMap = [
    'prospects' => 'Export CRM - Prospects',
    'clients' => 'Export CRM - Clients',
    'opportunites' => 'Export CRM - Opportunites',
    'rdv' => 'Export CRM - Rendez-vous',
];
$title = $titleMap[$dataset];
$rows = [];
if ($dataset === 'prospects') {
    foreach ($controller->listLatestProspects(2000) as $r) {
        $rows[] = ['Nom' => $r['nom'] ?? '', 'Societe' => $r['societe'] ?? '', 'Code' => $r['code_client'] ?? '', 'Telephone' => $r['telephone'] ?? '', 'Email' => $r['email'] ?? '', 'Statut' => $r['statut'] ?? '', 'Source' => $r['source'] ?? '', 'Cree le' => $r['created_at'] ?? ''];
    }
} elseif ($dataset === 'clients') {
    foreach ($controller->listLatestClients(2000) as $r) {
        $rows[] = ['Client' => $r['nom_client'] ?? '', 'Code' => $r['code_client'] ?? '', 'Telephone' => $r['telephone_client'] ?? '', 'Localisation' => $r['localisation_client'] ?? '', 'Commune' => $r['commune_client'] ?? '', 'Pays' => $r['pays_client'] ?? ''];
    }
} elseif ($dataset === 'opportunites') {
    foreach ($controller->listRecentOpportunities(2000) as $r) {
        $rows[] = ['Titre' => $r['titre'] ?? '', 'Etape' => $r['etape'] ?? '', 'Montant XOF' => $r['montant_xof'] ?? '', 'Probabilite' => $r['probabilite'] ?? '', 'Lie a' => $r['linked_name'] ?? '', 'Type' => $r['linked_type'] ?? '', 'Prochaine action' => $r['next_action_at'] ?? ''];
    }
} else {
    foreach ($controller->listUpcomingRdv(2000) as $r) {
        $rows[] = ['Date' => $r['date_debut'] ?? '', 'Titre' => $r['titre'] ?? '', 'Type' => $r['linked_type'] ?? '', 'Lie a' => $r['linked_name'] ?? '', 'Lieu' => $r['lieu'] ?? '', 'Statut' => $r['statut'] ?? ''];
    }
}
$headers = $rows ? array_keys($rows[0]) : ['Information'];
$filename = 'crm_' . $dataset . '_' . date('Ymd_His');

function crm_export_clear_output(): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
}

if ($format === 'csv') {
    crm_export_clear_output();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, $headers, ';');
    foreach ($rows as $row) {
        fputcsv($out, array_map(static fn($h) => (string)($row[$h] ?? ''), $headers), ';');
    }
    fclose($out);
    exit;
}

if ($format === 'xlsx' && class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet')) {
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $spreadsheet->getProperties()->setCreator(CompanyExportBranding::creator())->setTitle($title);
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->getTabColor()->setRGB(CompanyExportBranding::rgb('accent'));
    $sheet->setTitle(substr($dataset, 0, 31));
    $sheet->fromArray($headers, null, 'A1');
    $line = 2;
    foreach ($rows as $row) {
        $sheet->fromArray(array_map(static fn($h) => (string)($row[$h] ?? ''), $headers), null, 'A' . $line++);
    }
    $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));
    $sheet->getStyle('A1:' . $lastCol . '1')->getFont()->setBold(true)->getColor()->setRGB(CompanyExportBranding::rgb('primary',true));
    $sheet->getStyle('A1:' . $lastCol . '1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB(CompanyExportBranding::rgb('primary'));
    $sheet->getStyle('A1:' . $lastCol . max(1, $line - 1))->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)->getColor()->setRGB('E2E8F0');
    $sheet->freezePane('A2');
    $sheet->setAutoFilter('A1:'.$lastCol.max(1,$line-1));
    for ($i = 1; $i <= count($headers); $i++) {
        $sheet->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
    }
    crm_export_clear_output();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save('php://output');
    exit;
}

if ($format === 'pdf' && class_exists('TCPDF')) {
    class CrmExportPdfDocument extends TCPDF
    {
        use CompanyPdfTemplate;
        public function Header(): void { $this->drawCompanyPdfHeader(); }
        public function Footer(): void { $this->drawCompanyPdfFooter(); }
    }
    $pdf = new CrmExportPdfDocument('L', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->companyPdfTitle=$title;
    $pdf->companyPdfType='Direction commerciale';
    $pdf->companyPdfShowQr=false;
    $pdf->SetCreator(CompanyExportBranding::creator()); $pdf->SetAuthor((string)$brand['company_name']); $pdf->SetTitle($title);
    $pdf->SetMargins(12,39,12); $pdf->SetAutoPageBreak(true,25); $pdf->AddPage();
    $pdf->SetFillColor(248,250,252); $pdf->SetDrawColor(226,232,240); $pdf->RoundedRect(12,39,260,28,4,'1111','DF');
    $pdf->SetFillColor(...$pdf->companyPdfRgb($palette['accent'])); $pdf->RoundedRect(12,39,4,28,4,'1101','F');
    $pdf->SetXY(20,44); $pdf->SetFont('dejavusans','B',15); $pdf->SetTextColor(15,23,42); $pdf->Cell(0,6,$title,0,1,'L');
    $pdf->SetX(20); $pdf->SetFont('dejavusans','',9); $pdf->SetTextColor(71,85,105); $pdf->Cell(0,5,'Genere le '.date('d/m/Y H:i').' - '.count($rows).' ligne(s)',0,1,'L');
    $pdf->SetY(74);
    $html = '<table cellpadding="4" cellspacing="0" style="width:100%;border-collapse:collapse;font-size:7px"><thead><tr>';
    foreach ($headers as $h) $html .= '<th style="background-color:'.$palette['primary'].';color:'.CompanyBranding::contrastColor($palette['primary']).';border:1px solid #dfe6f1;font-weight:bold;">'.htmlspecialchars((string)$h,ENT_QUOTES,'UTF-8').'</th>';
    $html .= '</tr></thead><tbody>';
    foreach ($rows as $i => $row) { $bg = $i % 2 ? '#f8fafc' : '#ffffff'; $html .= '<tr>'; foreach ($headers as $h) $html .= '<td style="background-color:'.$bg.';border:1px solid #e2e8f0;color:#0f172a;">'.htmlspecialchars((string)($row[$h] ?? ''),ENT_QUOTES,'UTF-8').'</td>'; $html .= '</tr>'; }
    if (!$rows) $html .= '<tr><td style="border:1px solid #e2e8f0;">Aucune donnee.</td></tr>';
    $html .= '</tbody></table>';
    $pdf->writeHTML($html, true, false, true, false, '');
    crm_export_clear_output();
    $pdf->Output($filename . '.pdf', 'D');
    exit;
}

header('Location: export_crm.php?dataset=' . rawurlencode($dataset) . '&format=csv');
exit;
