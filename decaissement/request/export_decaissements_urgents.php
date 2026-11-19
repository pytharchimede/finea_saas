<?php
require_once __DIR__ . '/../../auth_check.php';
\AccessManager::requirePageAccess('liste_decaissements_urgents.php');

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../model/Database.php';
require_once __DIR__ . '/../../model/CompanyBranding.php';
require_once __DIR__ . '/../../model/CompanyPdfTemplate.php';
require_once __DIR__ . '/../../model/UrgentDisbursement.php';
require_once __DIR__ . '/../../repositories/UrgentDisbursementRepository.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

$pdo = \Database::getConnection();
$repo = new \UrgentDisbursementRepository($pdo);

$selected = $_POST['selected'] ?? $_POST['ids'] ?? $_GET['selected'] ?? '';
if (is_array($selected)) {
    $ids = $selected;
} else {
    $ids = explode(',', (string)$selected);
}
$ids = array_values(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0));

$filters = [
    'status' => (string)($_GET['status'] ?? $_POST['status'] ?? ''),
    'q' => (string)($_GET['q'] ?? $_POST['q'] ?? ''),
    'date_from' => (string)($_GET['date_from'] ?? $_POST['date_from'] ?? ''),
    'date_to' => (string)($_GET['date_to'] ?? $_POST['date_to'] ?? ''),
    'chantier' => (string)($_GET['chantier'] ?? $_POST['chantier'] ?? ''),
    'amount_min' => (string)($_GET['amount_min'] ?? $_POST['amount_min'] ?? ''),
    'amount_max' => (string)($_GET['amount_max'] ?? $_POST['amount_max'] ?? ''),
];
if ($ids) {
    $filters['ids'] = $ids;
}
$rows = $repo->list($filters);
$summary = $repo->summarize($rows);
$format = strtolower((string)($_GET['format'] ?? $_POST['format'] ?? 'pdf'));
$scope = $ids ? 'selection' : 'recherche';
$actorUserId = (int)($_SESSION['user_id'] ?? 0);
$actorName = trim((string)(($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? ''))) ?: 'Utilisateur';
$documentId = 'FIN-DEC-URG-' . date('Ymd-His') . '-U' . $actorUserId;
$documentCode = 'FIN-DEC-URG-01';

function urgent_export_money($value): string
{
    return number_format((float)$value, 0, ',', ' ') . ' CFA';
}

function urgent_export_statuses(): array
{
    return [
        \UrgentDisbursement::STATUS_SUBMITTED,
        \UrgentDisbursement::STATUS_DECAISSED,
        \UrgentDisbursement::STATUS_REGULARIZATION_PENDING,
        \UrgentDisbursement::STATUS_REGULARIZED,
        \UrgentDisbursement::STATUS_REJECTED,
    ];
}

function urgent_export_sheet_name(string $status): string
{
    $name = \UrgentDisbursement::statusLabel($status);
    $name = str_replace([':', '\\', '/', '?', '*', '[', ']'], ' ', $name);
    $name = trim(preg_replace('/\s+/', ' ', $name) ?: 'Statut');
    return mb_substr($name, 0, 31, 'UTF-8');
}

function urgent_export_filters_label(array $filters, string $scope): string
{
    $parts = ['Perimetre: ' . ($scope === 'selection' ? 'selection' : 'resultats de recherche')];
    if (trim((string)$filters['status']) !== '') $parts[] = 'Statut: ' . \UrgentDisbursement::statusLabel((string)$filters['status']);
    if (trim((string)$filters['q']) !== '') $parts[] = 'Recherche: ' . (string)$filters['q'];
    if (trim((string)$filters['date_from']) !== '' || trim((string)$filters['date_to']) !== '') $parts[] = 'Periode: ' . (($filters['date_from'] ?: '...') . ' -> ' . ($filters['date_to'] ?: '...'));
    if (trim((string)$filters['chantier']) !== '') $parts[] = 'Chantier: ' . (string)$filters['chantier'];
    if (trim((string)$filters['amount_min']) !== '' || trim((string)$filters['amount_max']) !== '') $parts[] = 'Montant: ' . (($filters['amount_min'] ?: '...') . ' -> ' . ($filters['amount_max'] ?: '...'));
    $parts[] = 'Export: ' . date('d/m/Y H:i');
    return implode(' | ', $parts);
}

if ($format === 'excel' || $format === 'xlsx') {
    $spreadsheet = new Spreadsheet();
    $spreadsheet->getProperties()
        ->setCreator($actorName)
        ->setCompany('BANAMUR INDUSTRIES')
        ->setTitle('Récapitulatif des décaissements urgents')
        ->setSubject('Document maîtrisé ISO 9001')
        ->setDescription($documentId . ' · ' . $documentCode);
    $first = true;
    $headers = ['Code', 'Statut', 'Demandeur', 'Telephone', 'Service', 'Fonction', 'Chantier', 'Libelle', 'Details', 'Montant', 'Soumise le', 'Decaissee le', 'Regularisation avant', 'FEB', 'Fiche', 'Note'];

    $groups = [];
    foreach (urgent_export_statuses() as $status) {
        $groups[$status] = [];
    }
    foreach ($rows as $row) {
        $groups[(string)($row['status'] ?? '')][] = $row;
    }

    foreach ($groups as $status => $statusRows) {
        $sheet = $first ? $spreadsheet->getActiveSheet() : $spreadsheet->createSheet();
        $first = false;
        $sheet->setTitle(urgent_export_sheet_name($status));
        $sheet->setCellValue('A1', 'BANAMUR INDUSTRIES · RÉCAPITULATIF DES DÉCAISSEMENTS URGENTS');
        $sheet->mergeCells('A1:P1');
        $sheet->setCellValue('A2', 'DOCUMENT MAÎTRISÉ · ISO 9001');
        $sheet->mergeCells('A2:H2');
        $sheet->setCellValue('I2', 'Code qualité : ' . $documentCode);
        $sheet->mergeCells('I2:L2');
        $sheet->setCellValue('M2', 'Édité le ' . date('d/m/Y à H:i'));
        $sheet->mergeCells('M2:P2');
        $sheet->setCellValue('A3', 'Référence : ' . $documentId);
        $sheet->mergeCells('A3:D3');
        $sheet->setCellValue('E3', 'Statut : ' . \UrgentDisbursement::statusLabel($status));
        $sheet->mergeCells('E3:H3');
        $sheet->setCellValue('I3', 'Nombre : ' . count($statusRows));
        $sheet->mergeCells('I3:L3');
        $sheet->setCellValue('M3', 'Total : ' . array_sum(array_map(static fn(array $r): float => (float)($r['amount'] ?? 0), $statusRows)));
        $sheet->mergeCells('M3:P3');
        $sheet->setCellValue('A4', urgent_export_filters_label($filters, $scope));
        $sheet->mergeCells('A4:P4');
        $sheet->getStyle('A1:P1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('0F172A');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle('A2:P2')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFF8D6');
        $sheet->getStyle('A2:P2')->getFont()->setBold(true)->getColor()->setRGB('312E81');
        $sheet->getStyle('A3:P4')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F8FAFC');
        $sheet->getStyle('A3:P3')->getFont()->setBold(true)->getColor()->setRGB('334155');

        $rowNum = 6;
        foreach ($headers as $i => $header) {
            $sheet->setCellValueByColumnAndRow($i + 1, $rowNum, $header);
        }
        $sheet->getStyle("A{$rowNum}:P{$rowNum}")->getFont()->setBold(true)->getColor()->setRGB('000000');
        $sheet->getStyle("A{$rowNum}:P{$rowNum}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FABD02');
        $rowNum++;
        foreach ($statusRows as $r) {
            $values = [
                $r['request_code'] ?? '',
                \UrgentDisbursement::statusLabel((string)($r['status'] ?? '')),
                $r['requester_name'] ?? '',
                $r['requester_phone'] ?? '',
                $r['requester_service'] ?? '',
                $r['requester_function'] ?? '',
                $r['chantier_label'] ?? '',
                $r['libelle'] ?? '',
                $r['details'] ?? '',
                (float)($r['amount'] ?? 0),
                $r['submitted_at'] ?? '',
                $r['decaissed_at'] ?? '',
                $r['regularization_due_at'] ?? '',
                $r['regularized_feb_request_id'] ?? '',
                $r['regularized_fiche_num'] ?? '',
                $r['decision_note'] ?? '',
            ];
            foreach ($values as $i => $value) {
                $sheet->setCellValueByColumnAndRow($i + 1, $rowNum, $value);
            }
            $rowNum++;
        }
        $last = max(7, $rowNum - 1);
        $sheet->getStyle("A6:P{$last}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('CBD5E1');
        $sheet->getStyle("A1:P{$last}")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle("J7:J{$last}")->getNumberFormat()->setFormatCode('#,##0 "FCFA"');
        foreach (range('A', 'P') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->freezePane('A7');
        $sheet->setAutoFilter("A6:P{$last}");
    }

    $fileName = 'recap_decaissements_urgents_' . $scope . '_' . date('Ymd_His') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Cache-Control: max-age=0');
    (new Xlsx($spreadsheet))->save('php://output');
    exit;
}

class PDFUrgentDisbursements extends \TCPDF
{
    use \CompanyPdfTemplate;
    public function Header(): void { $this->drawCompanyPdfHeader(); }
    public function Footer(): void { $this->drawCompanyPdfFooter(); }
}

$pdf = new PDFUrgentDisbursements('L', 'mm', 'A4', true, 'UTF-8', false);
$pdf->companyPdfTitle = 'Récapitulatif des décaissements urgents';
$pdf->companyPdfType = $scope === 'selection' ? 'État de la sélection' : 'État des résultats de recherche';
$pdf->companyPdfId = $documentId;
$pdf->companyPdfCode = $documentCode;
$pdf->companyPdfQrPayload = json_encode([
    'document' => $documentId,
    'type' => 'urgent_disbursements_recap',
    'scope' => $scope,
    'count' => (int)$summary['count'],
    'generated_at' => date(DATE_ATOM),
], JSON_UNESCAPED_UNICODE);
$pdf->SetCreator('Finéa');
$pdf->SetAuthor($actorName);
$pdf->SetTitle('Récapitulatif des décaissements urgents');
$pdf->SetMargins(14, 38, 14);
$pdf->SetAutoPageBreak(true, 25);
$pdf->AddPage();

$cardWidth = 65;
$drawCard = static function (TCPDF $pdf, float $x, string $icon, string $label, string $value, array $accent) use ($cardWidth): void {
    $y = 40.5;
    $h = 20;
    $pdf->SetFillColor(250, 250, 250);
    $pdf->SetDrawColor(212, 212, 212);
    $pdf->RoundedRect($x, $y, $cardWidth, $h, 3, '1111', 'DF');
    $pdf->SetFillColor(...$accent);
    $pdf->RoundedRect($x, $y, 2.5, $h, 1, '1001', 'F');

    $cx = $x + 10;
    $cy = $y + 10;
    $pdf->RoundedRect($cx - 5.7, $cy - 5.7, 11.4, 11.4, 2.8, '1111', 'F');
    $darkIcon = $accent === [250, 189, 2];
    $pdf->SetDrawColor($darkIcon ? 0 : 255, $darkIcon ? 0 : 255, $darkIcon ? 0 : 255);
    $pdf->SetLineWidth(.75);
    if ($icon === 'amount') {
        $pdf->Ellipse($cx, $cy, 3.4, 3.4, 0, 0, 360, 'D');
        $pdf->Line($cx - 1.8, $cy, $cx + 1.8, $cy);
        $pdf->Line($cx, $cy - 2, $cx, $cy + 2);
    } elseif ($icon === 'waiting') {
        $pdf->Ellipse($cx, $cy, 3.3, 3.3, 0, 0, 360, 'D');
        $pdf->Line($cx, $cy, $cx, $cy - 2.1);
        $pdf->Line($cx, $cy, $cx + 1.8, $cy + 1);
    } elseif ($icon === 'regularized') {
        $pdf->SetLineWidth(1.1);
        $pdf->Line($cx - 3, $cy, $cx - .8, $cy + 2.2);
        $pdf->Line($cx - .8, $cy + 2.2, $cx + 3.2, $cy - 2.6);
    } else {
        $pdf->RoundedRect($cx - 2.8, $cy - 3.4, 5.6, 6.8, .7, '1111', 'D');
        $pdf->Line($cx - 1.6, $cy - 1.4, $cx + 1.6, $cy - 1.4);
        $pdf->Line($cx - 1.6, $cy + .1, $cx + 1.6, $cy + .1);
        $pdf->Line($cx - 1.6, $cy + 1.6, $cx + .7, $cy + 1.6);
    }

    $textX = $x + 18;
    $textWidth = $cardWidth - 21;
    $pdf->SetXY($textX, $y + 3.1);
    $pdf->SetTextColor(115, 115, 115);
    $pdf->SetFont('dejavusans', 'B', 6.2);
    $pdf->Cell($textWidth, 4, mb_strtoupper($label, 'UTF-8'), 0, 1, 'L', false, '', 1);
    $valueSize = 9.2;
    $pdf->SetFont('dejavusans', 'B', $valueSize);
    while ($valueSize > 7 && $pdf->GetStringWidth($value) > $textWidth) {
        $valueSize -= .25;
        $pdf->SetFont('dejavusans', 'B', $valueSize);
    }
    $pdf->SetXY($textX, $y + 8.5);
    $pdf->SetTextColor(23, 23, 23);
    $pdf->Cell($textWidth, 6, $value, 0, 1, 'L', false, '', 1);
};

$waitingCount = (int)(($summary['by_status'][\UrgentDisbursement::STATUS_SUBMITTED]['count'] ?? 0));
$toRegularizeCount = (int)(($summary['by_status'][\UrgentDisbursement::STATUS_DECAISSED]['count'] ?? 0)
    + ($summary['by_status'][\UrgentDisbursement::STATUS_REGULARIZATION_PENDING]['count'] ?? 0));
$regularizedCount = (int)($summary['by_status'][\UrgentDisbursement::STATUS_REGULARIZED]['count'] ?? 0);
$drawCard($pdf, 14, 'requests', 'Demandes exportées', (int)$summary['count'] . ' demande(s)', [23, 23, 23]);
$drawCard($pdf, 82, 'amount', 'Montant cumulé', urgent_export_money($summary['total']), [250, 189, 2]);
$drawCard($pdf, 150, 'waiting', 'À traiter / régulariser', ($waitingCount + $toRegularizeCount) . ' demande(s)', [180, 83, 9]);
$drawCard($pdf, 218, 'regularized', 'Régularisées', $regularizedCount . ' demande(s)', [22, 101, 52]);

$pdf->SetY(64);
$pdf->SetFillColor(255, 248, 214);
$pdf->SetDrawColor(250, 189, 2);
$pdf->SetTextColor(23, 23, 23);
$pdf->SetFont('dejavusans', '', 7);
$pdf->MultiCell(269, 9, urgent_export_filters_label($filters, $scope), 1, 'L', true, 1, '', '', true, 0, false, true, 9, 'M');
$pdf->Ln(2);
$pdf->SetFont('dejavusans', 'B', 10.5);
$pdf->SetTextColor(23, 23, 23);
$pdf->Cell(0, 6, 'DETAIL DES DEMANDES DE DECAISSEMENT URGENT', 0, 1, 'L');
$pdf->SetDrawColor(250, 189, 2);
$pdf->SetLineWidth(.7);
$pdf->Line(14, $pdf->GetY(), 283, $pdf->GetY());
$pdf->Ln(2);

$columns = [
    ['CODE', 25, 'L'], ['STATUT', 31, 'L'], ['DEMANDEUR', 39, 'L'], ['CHANTIER', 43, 'L'],
    ['OBJET', 39, 'L'], ['DÉTAILS', 45, 'L'], ['MONTANT', 26, 'R'], ['DATES', 21, 'C'],
];
$drawTableHeader = static function (TCPDF $pdf) use ($columns): void {
    $pdf->SetFillColor(250, 189, 2);
    $pdf->SetDrawColor(23, 23, 23);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('dejavusans', 'B', 6.5);
    foreach ($columns as [$label, $width, $align]) {
        $pdf->MultiCell($width, 9, $label, 1, $align, true, 0, '', '', true, 0, false, true, 9, 'M');
    }
    $pdf->Ln();
};
$drawTableHeader($pdf);

if (!$rows) {
    $pdf->SetFillColor(248, 250, 252);
    $pdf->SetDrawColor(203, 213, 225);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->SetFont('dejavusans', 'I', 9);
    $pdf->MultiCell(269, 22, 'Aucune demande urgente ne correspond aux filtres sélectionnés.', 1, 'C', true, 1, '', '', true, 0, false, true, 22, 'M');
}

foreach ($rows as $index => $r) {
    $dateLines = [];
    if (!empty($r['submitted_at'])) $dateLines[] = 'S : ' . date('d/m/Y', strtotime((string)$r['submitted_at']));
    if (!empty($r['decaissed_at'])) $dateLines[] = 'D : ' . date('d/m/Y', strtotime((string)$r['decaissed_at']));
    if (!empty($r['regularization_due_at'])) $dateLines[] = 'R : ' . date('d/m/Y', strtotime((string)$r['regularization_due_at']));
    $requester = trim((string)($r['requester_name'] ?? ''));
    $service = trim((string)($r['requester_service'] ?? ''));
    $cells = [
        (string)($r['request_code'] ?? '—'),
        \UrgentDisbursement::statusLabel((string)($r['status'] ?? '')),
        trim($requester . ($service !== '' ? "\n" . $service : '')),
        (string)(($r['chantier_label'] ?? '') ?: 'Sans chantier'),
        (string)(($r['libelle'] ?? '') ?: '—'),
        (string)(($r['details'] ?? '') ?: '—'),
        urgent_export_money($r['amount'] ?? 0),
        $dateLines ? implode("\n", $dateLines) : '—',
    ];
    $pdf->SetFont('dejavusans', '', 6.25);
    $heights = [];
    foreach ($cells as $i => $cell) $heights[] = $pdf->getStringHeight($columns[$i][1] - 3, $cell);
    $rowHeight = max(10, min(22, max($heights) + 3));
    if ($pdf->GetY() + $rowHeight > 184) {
        $pdf->AddPage();
        $pdf->SetY(40);
        $drawTableHeader($pdf);
    }
    $fill = $index % 2 === 1;
    $pdf->SetFillColor(247, 247, 247);
    $pdf->SetDrawColor(212, 212, 212);
    foreach ($cells as $i => $cell) {
        $pdf->SetFont('dejavusans', $i === 0 || $i === 1 || $i === 6 ? 'B' : '', 6.25);
        if ($i === 1) {
            $status = strtolower((string)($r['status'] ?? ''));
            $color = $status === 'regularized' ? [22, 101, 52] : (in_array($status, ['rejected', 'cancelled'], true) ? [153, 27, 27] : [180, 83, 9]);
            $pdf->SetTextColor(...$color);
        } elseif ($i === 0 || $i === 6) {
            $pdf->SetTextColor(23, 23, 23);
        } else {
            $pdf->SetTextColor(64, 64, 64);
        }
        $pdf->MultiCell($columns[$i][1], $rowHeight, $cell, 1, $columns[$i][2], $fill, 0, '', '', true, 0, false, true, $rowHeight, 'M');
    }
    $pdf->Ln();
}

$pdf->Ln(4);
$pdf->SetTextColor(100, 116, 139);
$pdf->SetFont('dejavusans', '', 6.5);
$pdf->MultiCell(269, 5, 'S = soumission · D = décaissement · R = échéance de régularisation', 0, 'L');
$pdf->Output('recap_decaissements_urgents_' . $scope . '_' . date('Ymd_His') . '.pdf', 'I');
exit;
