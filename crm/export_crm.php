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
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle(substr($dataset, 0, 31));
    $sheet->fromArray($headers, null, 'A1');
    $line = 2;
    foreach ($rows as $row) {
        $sheet->fromArray(array_map(static fn($h) => (string)($row[$h] ?? ''), $headers), null, 'A' . $line++);
    }
    $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));
    $sheet->getStyle('A1:' . $lastCol . '1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
    $sheet->getStyle('A1:' . $lastCol . '1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('1D2B57');
    $sheet->getStyle('A1:' . $lastCol . max(1, $line - 1))->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)->getColor()->setRGB('E2E8F0');
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
        public string $documentTitle = 'Export CRM';
        public function Header(): void
        {
            $this->SetFillColor(255,255,255); $this->Rect(0,0,$this->getPageWidth(),28,'F');
            $this->SetDrawColor(203,213,225); $this->Line(12,24.5,$this->getPageWidth()-12,24.5);
            $this->SetFillColor(250,189,2); $this->Rect(12,24.5,34,1.1,'F');
            $this->SetXY(12,7); $this->SetFont('dejavusans','B',15.5); $this->SetTextColor(15,23,42); $this->Cell(0,6,'BANAMUR',0,1,'L');
            $this->SetX(12); $this->SetFont('dejavusans','',8.5); $this->SetTextColor(71,85,105); $this->Cell(0,4.4,'Direction commerciale - CRM',0,1,'L');
            $this->SetXY($this->getPageWidth()-96,8); $this->SetFont('dejavusans','B',8.5); $this->Cell(84,4.5,$this->documentTitle,0,1,'R');
        }
        public function Footer(): void
        {
            $this->SetY(-16); $this->SetDrawColor(214,219,228); $this->Line(12,$this->GetY(),$this->getPageWidth()-12,$this->GetY());
            $this->Ln(2.5); $this->SetFont('dejavusans','',8); $this->SetTextColor(100,116,139); $this->Cell(0,5,'BANAMUR - Export CRM securise - Page '.$this->getAliasNumPage().'/'.$this->getAliasNbPages(),0,0,'C');
        }
    }
    $pdf = new CrmExportPdfDocument('L', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->documentTitle = $title;
    $pdf->SetCreator('BANAMUR'); $pdf->SetAuthor('BANAMUR CRM'); $pdf->SetTitle($title);
    $pdf->SetMargins(12,30,12); $pdf->SetAutoPageBreak(true,18); $pdf->AddPage();
    $pdf->SetFillColor(248,250,252); $pdf->SetDrawColor(226,232,240); $pdf->RoundedRect(12,33,260,28,4,'1111','DF');
    $pdf->SetFillColor(29,43,87); $pdf->RoundedRect(12,33,4,28,4,'1101','F');
    $pdf->SetXY(20,38); $pdf->SetFont('dejavusans','B',15); $pdf->SetTextColor(15,23,42); $pdf->Cell(0,6,$title,0,1,'L');
    $pdf->SetX(20); $pdf->SetFont('dejavusans','',9); $pdf->SetTextColor(71,85,105); $pdf->Cell(0,5,'Genere le '.date('d/m/Y H:i').' - '.count($rows).' ligne(s)',0,1,'L');
    $pdf->SetY(70);
    $html = '<table cellpadding="4" cellspacing="0" style="width:100%;border-collapse:collapse;font-size:7px"><thead><tr>';
    foreach ($headers as $h) $html .= '<th style="background-color:#fabd02;color:#000000;border:1px solid #000000;font-weight:bold;">'.htmlspecialchars((string)$h,ENT_QUOTES,'UTF-8').'</th>';
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
