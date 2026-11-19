<?php
require_once __DIR__ . '/_dashboard_common.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

if (!class_exists('LogisticsExportPdfDocument')) {
    class LogisticsExportPdfDocument extends \TCPDF
    {
        public string $companyName = 'BANAMUR';
        public string $documentTitle = 'Export logistique';
        public string $logoPath = '';

        public function Header(): void
        {
            $this->SetFillColor(255, 255, 255);
            $this->Rect(0, 0, $this->getPageWidth(), 28, 'F');
            $this->SetDrawColor(203, 213, 225);
            $this->Line(12, 24.5, $this->getPageWidth() - 12, 24.5);
            $this->SetFillColor(250, 189, 2);
            $this->Rect(12, 24.5, 34, 1.1, 'F');

            if ($this->logoPath !== '' && is_file($this->logoPath)) {
                $this->Image($this->logoPath, 12, 5.8, 15, 0, '', '', '', false, 300);
            }

            $this->SetXY(31, 7.4);
            $this->SetFont('dejavusans', 'B', 15.5);
            $this->SetTextColor(15, 23, 42);
            $this->Cell(0, 5.8, $this->companyName, 0, 1, 'L');
            $this->SetX(31);
            $this->SetFont('dejavusans', '', 8.5);
            $this->SetTextColor(71, 85, 105);
            $this->Cell(0, 4.4, 'Direction logistique - Document officiel', 0, 1, 'L');

            $this->SetXY($this->getPageWidth() - 88, 8.4);
            $this->SetFont('dejavusans', 'B', 8.6);
            $this->SetTextColor(51, 65, 85);
            $this->Cell(76, 4.5, $this->documentTitle, 0, 1, 'R');
            $this->SetX($this->getPageWidth() - 88);
            $this->SetFont('dejavusans', '', 7.6);
            $this->SetTextColor(100, 116, 139);
            $this->Cell(76, 4.3, 'Export consolide du module logistique', 0, 1, 'R');
        }

        public function Footer(): void
        {
            $this->SetY(-16);
            $this->SetDrawColor(214, 219, 228);
            $this->Line(12, $this->GetY(), $this->getPageWidth() - 12, $this->GetY());
            $this->Ln(2.5);
            $this->SetFont('dejavusans', '', 8);
            $this->SetTextColor(100, 116, 139);
            $this->Cell(0, 5, 'BANAMUR - Export logistique securise - Page ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, 0, 'C');
        }
    }
}

$dataset = strtolower(trim((string) ($_GET['dataset'] ?? 'summary')));
$format = strtolower(trim((string) ($_GET['format'] ?? 'xlsx')));
$allowedDatasets = ['summary', 'assets', 'requests', 'stock', 'drivers', 'documents', 'incidents', 'maintenance', 'suppliers', 'daily_activity', 'planning', 'trip_batches', 'trips'];
if (!in_array($dataset, $allowedDatasets, true)) {
    $dataset = 'summary';
}
$rows = logi_dashboard_export_rows($pdo, $dataset);
$titleMap = [
    'summary' => 'Synthese logistique',
    'assets' => 'Parc logistique',
    'requests' => 'Demandes logistiques',
    'stock' => 'Stock logistique',
    'drivers' => 'Chauffeurs logistiques',
    'documents' => 'Documents logistiques',
    'incidents' => 'Incidents logistiques',
    'maintenance' => 'Maintenance logistique',
    'suppliers' => 'Fournisseurs logistiques',
    'daily_activity' => 'Suivi journalier parc logistique',
    'planning' => 'Planning logistique',
    'trip_batches' => 'Vagues de voyages',
    'trips' => 'Rotations de voyages',
];
$title = $titleMap[$dataset] ?? 'Export logistique';
$filenameBase = 'logistique_' . $dataset . '_' . date('Ymd_His');

if (class_exists('AuditTrail')) {
    try {
        AuditTrail::logFileEvent('export', [
            'module' => 'logistique',
            'action' => 'export_logistique',
            'entity_type' => 'logistics_export',
            'entity_label' => $title,
            'format' => $format,
            'details' => [
                'dataset' => $dataset,
                'search' => trim((string) ($_GET['q'] ?? '')),
                'rows' => count($rows),
            ],
        ], $pdo);
    } catch (Throwable $e) {
        error_log('Logistics export audit error: ' . $e->getMessage());
    }
}

if ($format === 'pdf') {
    $headers = $rows ? array_keys($rows[0]) : ['Information'];
    $search = trim((string) ($_GET['q'] ?? ''));
    $generatedAt = date('d/m/Y H:i');
    $reference = 'LOGI-' . date('Ymd-His');
    $qrPayload = implode("\n", array_filter([
        'BANAMUR - Export logistique',
        'Reference: ' . $reference,
        'Document: ' . $title,
        'Dataset: ' . $dataset,
        'Lignes: ' . count($rows),
        'Genere le: ' . $generatedAt,
        $search !== '' ? 'Recherche: ' . $search : 'Recherche: globale',
    ]));

    $pdf = new LogisticsExportPdfDocument('L', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->companyName = 'BANAMUR';
    $pdf->documentTitle = $title;
    $pdf->logoPath = __DIR__ . '/../logo/default_logo.jpg';
    $pdf->SetCreator('BANAMUR');
    $pdf->SetAuthor('BANAMUR Logistique');
    $pdf->SetTitle($title);
    $pdf->SetSubject('Export logistique');
    $pdf->SetMargins(12, 30, 12);
    $pdf->SetAutoPageBreak(true, 18);
    $pdf->AddPage();

    $pageWidth = $pdf->getPageWidth() - 24;
    $heroY = 33;
    $heroHeight = 28;
    $heroWidth = $pageWidth - 37;
    $qrBoxX = 12 + $heroWidth + 5;
    $qrBoxWidth = 32;

    $pdf->SetFillColor(248, 250, 252);
    $pdf->SetDrawColor(226, 232, 240);
    $pdf->RoundedRect(12, $heroY, $heroWidth, $heroHeight, 4, '1111', 'DF');
    $pdf->SetFillColor(250, 189, 2);
    $pdf->RoundedRect(12, $heroY, 4, $heroHeight, 4, '1101', 'F');
    $pdf->SetXY(19, 37.5);
    $pdf->SetFont('dejavusans', 'B', 15.2);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell($heroWidth - 16, 5.8, 'Export logistique', 0, 1, 'L');
    $pdf->SetFont('dejavusans', '', 9.3);
    $pdf->SetTextColor(71, 85, 105);
    $pdf->SetX(19);
    $pdf->MultiCell($heroWidth - 18, 4.8, $title, 0, 'L', false, 1);
    $pdf->SetX(19);
    $pdf->SetFont('dejavusans', '', 8.5);
    $pdf->Cell($heroWidth - 18, 4.4, 'Reference : ' . $reference . '   |   Format : PDF officiel', 0, 1, 'L');

    $pdf->SetFillColor(255, 255, 255);
    $pdf->SetDrawColor(226, 232, 240);
    $pdf->RoundedRect($qrBoxX, $heroY, $qrBoxWidth, $heroHeight, 4, '1111', 'DF');
    $pdf->SetXY($qrBoxX + 3, $heroY + 2.4);
    $pdf->SetFont('dejavusans', 'B', 7.2);
    $pdf->SetTextColor(71, 85, 105);
    $pdf->Cell($qrBoxWidth - 6, 4, 'QR EXPORT', 0, 1, 'C');
    if ($qrPayload !== '') {
        $pdf->write2DBarcode($qrPayload, 'QRCODE,H', $qrBoxX + 6.1, $heroY + 7.2, 19, 19, [], 'N');
    }

    $kpiY = 66;
    $gap = 4;
    $boxWidth = ($pageWidth - ($gap * 3)) / 4;
    $cards = [
        ['label' => 'Lignes exportees', 'value' => (string) count($rows)],
        ['label' => 'Jeu de donnees', 'value' => ucfirst(str_replace('_', ' ', $dataset))],
        ['label' => 'Genere le', 'value' => $generatedAt],
        ['label' => 'Filtre recherche', 'value' => $search !== '' ? $search : 'Export global'],
    ];

    foreach ($cards as $index => $card) {
        $x = 12 + (($boxWidth + $gap) * $index);
        $pdf->SetFillColor(255, 255, 255);
        $pdf->SetDrawColor(226, 232, 240);
        $pdf->RoundedRect($x, $kpiY, $boxWidth, 16, 3, '1111', 'DF');
        $pdf->SetXY($x + 3.2, $kpiY + 2.2);
        $pdf->SetFont('dejavusans', '', 7);
        $pdf->SetTextColor(100, 116, 139);
        $pdf->Cell($boxWidth - 6.4, 3.8, $card['label'], 0, 2, 'L');
        $pdf->SetX($x + 3.2);
        $pdf->SetFont('dejavusans', 'B', 8.8);
        $pdf->SetTextColor(15, 23, 42);
        $pdf->MultiCell($boxWidth - 6.4, 4.2, $card['value'], 0, 'L', false, 0);
    }

    $pdf->SetY(88);
    $pdf->SetFont('dejavusans', 'B', 11);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell(0, 6, 'Donnees exportees', 0, 1, 'L');
    $pdf->Ln(1.5);

    $fontSize = count($headers) > 10 ? 6.2 : (count($headers) > 7 ? 6.8 : 7.4);
    $cellPadding = count($headers) > 10 ? 3 : 4;
    $html = '<table cellpadding="' . $cellPadding . '" cellspacing="0" style="width:100%;border-collapse:collapse;font-size:' . $fontSize . 'px;">';
    $html .= '<thead><tr>';
    foreach ($headers as $header) {
        $html .= '<th style="background-color:#fabd02;color:#000000;border:1px solid #000000;font-weight:bold;">' . htmlspecialchars((string) $header, ENT_QUOTES, 'UTF-8') . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    if ($rows) {
        foreach ($rows as $index => $row) {
            $background = $index % 2 === 0 ? '#ffffff' : '#f8fafc';
            $html .= '<tr>';
            foreach ($headers as $header) {
                $html .= '<td style="background-color:' . $background . ';border:1px solid #e2e8f0;color:#0f172a;">' . htmlspecialchars((string) ($row[$header] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>';
            }
            $html .= '</tr>';
        }
    } else {
        $html .= '<tr><td style="border:1px solid #e2e8f0;background-color:#f8fafc;color:#64748b;">Aucune donnee disponible pour cet export.</td></tr>';
    }
    $html .= '</tbody></table>';
    $pdf->writeHTML($html, true, false, true, false, '');
    $pdf->Output($filenameBase . '.pdf', 'D');
    exit;
}

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle(substr($title, 0, 31));
$headers = $rows ? array_keys($rows[0]) : ['Information'];
$col = 1;
foreach ($headers as $header) {
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($col) . '1', $header);
    $col++;
}
$rowIndex = 2;
foreach ($rows as $row) {
    $col = 1;
    foreach ($headers as $header) {
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($col) . $rowIndex, $row[$header] ?? '');
        $col++;
    }
    $rowIndex++;
}
foreach (range(1, count($headers)) as $columnIndex) {
    $sheet->getColumnDimensionByColumn($columnIndex)->setAutoSize(true);
}
$sheet->getStyle('1:1')->getFont()->setBold(true);

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filenameBase . '.xlsx"');
header('Cache-Control: max-age=0');
(new Xlsx($spreadsheet))->save('php://output');
exit;
