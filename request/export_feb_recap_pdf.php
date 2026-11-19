<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/CompanyPdfTemplate.php';
require_once __DIR__ . '/../model/EmployeeLegalRequestManager.php';
require_once __DIR__ . '/../model/FebValidationPolicy.php';

$pdo = \Database::getConnection();
$manager = new EmployeeLegalRequestManager($pdo);

$actorUserId = (int) ($_SESSION['user_id'] ?? 0);
$servicePerm = $actorUserId > 0
    ? FebValidationPolicy::getUserServicePermissions($pdo, $actorUserId)
    : ['all' => false, 'ids' => [], 'configured' => false, 'raw' => ''];
$serviceFilter = !empty($servicePerm['all']) ? null : (array) ($servicePerm['ids'] ?? []);

$tab = strtolower(trim((string) ($_GET['tab'] ?? 'all')));
$includeLegacyFeb = !empty($_GET['archives']) || !empty($_GET['legacy']) || !empty($_GET['include_archives']);
$searchQuery = trim((string) ($_GET['q'] ?? ''));
$searchRequester = trim((string) ($_GET['demandeur'] ?? ''));
$searchAffectation = trim((string) ($_GET['affectation'] ?? ''));
$hasExplicitIds = array_key_exists('ids', $_GET);
$ids = array_values(array_unique(array_filter(array_map('intval', preg_split('/[,\s;]+/', (string) ($_GET['ids'] ?? ''), -1, PREG_SPLIT_NO_EMPTY)), static fn(int $v): bool => $v > 0)));

$statusesByTab = [
    'mine_pending' => ['submitted'],
    'pending_all' => ['submitted'],
    'approved' => ['approved'],
    'rejected' => ['rejected'],
    'cancelled' => ['cancelled'],
    'all' => ['submitted', 'approved', 'rejected', 'cancelled'],
];
$statuses = $statusesByTab[$tab] ?? $statusesByTab['all'];

try {
    $rows = $hasExplicitIds ? $manager->listRequestsByIds($ids) : $manager->listRequestsForTeamAdvanced('finance', $statuses, 'feb', $serviceFilter, 500, $includeLegacyFeb);
    $counts = $manager->countRequestsForTeam('finance', 'feb', $serviceFilter, $includeLegacyFeb);
} catch (Throwable $e) {
    $rows = [];
    $counts = [];
}

$match = static function (string $hay, string $needle): bool {
    $needle = trim($needle);
    if ($needle === '') {
        return true;
    }
    return stripos($hay, $needle) !== false;
};

$rows = array_values(array_filter($rows, static function (array $row) use ($servicePerm, $searchQuery, $searchRequester, $searchAffectation, $match): bool {
    if (($row['assigned_team'] ?? '') !== 'finance' || ($row['request_type'] ?? '') !== 'feb') {
        return false;
    }
    if (!FebValidationPolicy::canValidateService($servicePerm, FebValidationPolicy::requestServiceId($row))) {
        return false;
    }
    $payload = (array) ($row['payload'] ?? []);
    $display = (array) ($row['feb_display'] ?? EmployeeLegalRequestManager::extractFebDisplay($row));
    $haystack = implode(' ', [$row['id'] ?? '', $row['title'] ?? '', $row['requester_name'] ?? '', $payload['departement_label'] ?? '', $payload['service_employe'] ?? '', $display['demandeur'] ?? '', $display['description'] ?? '', $display['numero_bon_commande_interne'] ?? '', $display['code_autorisation_feb'] ?? '']);
    return $match($haystack, $searchQuery)
        && $match((string) ($display['demandeur'] ?? ($row['requester_name'] ?? '')), $searchRequester)
        && $match((string) (($payload['departement_label'] ?? '') . ' ' . ($payload['service_employe'] ?? '')), $searchAffectation);
}));

$totalAmount = 0.0;
foreach ($rows as $rowIndex => $row) {
    $display = (array) ($row['feb_display'] ?? EmployeeLegalRequestManager::extractFebDisplay($row));
    $totalAmount += (float) ($display['montant'] ?? 0);
}

function feb_recap_pdf_status(string $status): string
{
    return [
        'submitted' => 'En attente',
        'approved' => 'Validee',
        'rejected' => 'Refusee',
        'cancelled' => 'Annulee',
    ][strtolower(trim($status))] ?? ($status ?: '-');
}

function feb_recap_pdf_short(string $text, int $limit = 55): string
{
    $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    if (function_exists('mb_strimwidth')) {
        return (string) mb_strimwidth($text, 0, $limit, '...');
    }
    return strlen($text) > $limit ? substr($text, 0, $limit - 3) . '...' : $text;
}

class FebRecapIsoPdf extends TCPDF
{
    use CompanyPdfTemplate;
    public function Header(): void { $this->drawCompanyPdfHeader(); }
    public function Footer(): void { $this->drawCompanyPdfFooter(); }
}

function feb_recap_draw_card(TCPDF $pdf, float $x, string $icon, string $label, string $value, array $accent): void
{
    $y = 40.5; $width = 51.4; $height = 20;
    $pdf->SetFillColor(250, 250, 250); $pdf->SetDrawColor(212, 212, 212);
    $pdf->RoundedRect($x, $y, $width, $height, 3, '1111', 'DF');
    $pdf->SetFillColor(...$accent); $pdf->RoundedRect($x, $y, 2.5, $height, 1, '1001', 'F');
    $cx = $x + 9.2; $cy = $y + 10;
    $pdf->RoundedRect($cx - 5.5, $cy - 5.5, 11, 11, 2.6, '1111', 'F');
    $pdf->SetDrawColor(255, 255, 255); $pdf->SetLineWidth(.7);
    if ($icon === 'waiting') {
        $pdf->Ellipse($cx, $cy, 3, 3, 0, 0, 360, 'D'); $pdf->Line($cx, $cy, $cx, $cy - 2); $pdf->Line($cx, $cy, $cx + 1.7, $cy + 1);
    } elseif ($icon === 'approved') {
        $pdf->SetLineWidth(1); $pdf->Line($cx - 2.8, $cy, $cx - .7, $cy + 2); $pdf->Line($cx - .7, $cy + 2, $cx + 3, $cy - 2.5);
    } elseif ($icon === 'rejected') {
        $pdf->SetLineWidth(1); $pdf->Line($cx - 2.5, $cy - 2.5, $cx + 2.5, $cy + 2.5); $pdf->Line($cx + 2.5, $cy - 2.5, $cx - 2.5, $cy + 2.5);
    } elseif ($icon === 'amount') {
        $pdf->Ellipse($cx, $cy, 3.2, 3.2, 0, 0, 360, 'D'); $pdf->Line($cx - 1.8, $cy, $cx + 1.8, $cy); $pdf->Line($cx, $cy - 2, $cx, $cy + 2);
    } else {
        $pdf->RoundedRect($cx - 2.8, $cy - 3.3, 5.6, 6.6, .7, '1111', 'D'); $pdf->Line($cx - 1.6, $cy - 1.2, $cx + 1.6, $cy - 1.2); $pdf->Line($cx - 1.6, $cy + .4, $cx + 1.6, $cy + .4);
    }
    $textX = $x + 16.8; $textWidth = $width - 19.5;
    $pdf->SetXY($textX, $y + 3.1); $pdf->SetTextColor(115, 115, 115); $pdf->SetFont('dejavusans', 'B', 5.8);
    $pdf->Cell($textWidth, 4, mb_strtoupper($label, 'UTF-8'), 0, 1, 'L', false, '', 1);
    $fontSize = 8.8; $pdf->SetFont('dejavusans', 'B', $fontSize);
    while ($fontSize > 6.3 && $pdf->GetStringWidth($value) > $textWidth) { $fontSize -= .25; $pdf->SetFont('dejavusans', 'B', $fontSize); }
    $pdf->SetXY($textX, $y + 8.5); $pdf->SetTextColor(23, 23, 23);
    $pdf->Cell($textWidth, 6, $value, 0, 1, 'L', false, '', 1);
}

$documentId = 'FIN-FEB-REC-' . date('Ymd-His') . '-U' . $actorUserId;
$pdf = new FebRecapIsoPdf('L', 'mm', 'A4', true, 'UTF-8', false);
$pdf->companyPdfTitle = 'Récapitulatif FEB / Bons de commande internes';
$pdf->companyPdfType = 'Récapitulatif multi-FEB';
$pdf->companyPdfId = $documentId;
$pdf->companyPdfCode = 'FIN-FEB-RECAP';
$pdf->companyPdfQrPayload = json_encode([
    'document' => $documentId,
    'type' => 'feb_recap',
    'tab' => $tab,
    'feb_ids' => array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $rows),
    'generated_at' => date(DATE_ATOM),
], JSON_UNESCAPED_UNICODE);
$pdf->SetCreator('FINEA');
$pdf->SetAuthor('FINEA');
$pdf->SetTitle('Recap FEB - BCI');
$pdf->SetMargins(10, 38, 10);
$pdf->SetAutoPageBreak(true, 25);
$pdf->AddPage();

$kpis = [
    ['document', 'FEB affichees', (string) count($rows), [23, 23, 23]],
    ['waiting', 'En attente', (string) ($counts['submitted'] ?? 0), [217, 119, 6]],
    ['approved', 'Validees', (string) ($counts['approved'] ?? 0), [22, 101, 52]],
    ['rejected', 'Refusees', (string) ($counts['rejected'] ?? 0), [153, 27, 27]],
    ['amount', 'Montant selectionne', number_format($totalAmount, 0, ',', ' ') . ' FCFA', [250, 189, 2]],
];
$x = 10;
foreach ($kpis as $kpi) {
    feb_recap_draw_card($pdf, $x, $kpi[0], $kpi[1], $kpi[2], $kpi[3]);
    $x += 56.4;
}

$pdf->SetY(64);
$pdf->SetFont('dejavusans', 'B', 10.5);
$pdf->SetTextColor(23, 23, 23);
$pdf->Cell(0, 6, 'DETAIL DES FEB ET BONS DE COMMANDE INTERNES', 0, 1, 'L');
$pdf->SetDrawColor(250, 189, 2);
$pdf->SetLineWidth(.7);
$pdf->Line(10, $pdf->GetY(), 287, $pdf->GetY());
$pdf->Ln(2);
$cols = [18, 30, 38, 38, 80, 28, 22, 23];
$headers = ['FEB', 'BCI', 'Demandeur', 'Affectation', 'Description', 'Montant', 'Statut', 'Date'];
$drawTableHeader = static function () use ($pdf, $cols, $headers): void {
    $pdf->SetFillColor(250, 189, 2);
    $pdf->SetDrawColor(23, 23, 23);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('dejavusans', 'B', 7.2);
    foreach ($headers as $i => $h) {
        $pdf->Cell($cols[$i], 7, $h, 1, 0, 'L', true);
    }
    $pdf->Ln();
};
$drawTableHeader();
$pdf->SetTextColor(31, 41, 55);
$pdf->SetFont('dejavusans', '', 7.4);

foreach ($rows as $row) {
    $payload = (array) ($row['payload'] ?? []);
    $display = (array) ($row['feb_display'] ?? EmployeeLegalRequestManager::extractFebDisplay($row));
    $bci = trim((string) ($display['numero_bon_commande_interne'] ?? $display['code_autorisation_feb'] ?? ''));
    $values = [
        '#' . (int) ($row['id'] ?? 0),
        $bci !== '' ? $bci : '-',
        feb_recap_pdf_short((string) ($display['demandeur'] ?? ($row['requester_name'] ?? '')), 35),
        feb_recap_pdf_short((string) ($payload['departement_label'] ?? ''), 35),
        trim((string) ($display['description'] ?? ($row['title'] ?? ''))),
        number_format((float) ($display['montant'] ?? 0), 0, ',', ' '),
        feb_recap_pdf_status((string) ($row['status'] ?? '')),
        substr((string) ($row['submitted_at'] ?? ''), 0, 10),
    ];
    $lineHeight = 4.2;
    $rowHeight = 7.0;
    foreach ($values as $i => $v) {
        $cellLines = max(1, $pdf->getNumLines((string) $v, $cols[$i] - 1));
        $rowHeight = max($rowHeight, ($cellLines * $lineHeight) + 2);
    }
    if ($pdf->GetY() + $rowHeight > ($pdf->getPageHeight() - 26)) {
        $pdf->AddPage();
        $drawTableHeader();
        $pdf->SetTextColor(31, 41, 55);
        $pdf->SetFont('dejavusans', '', 7.4);
    }
    $x = $pdf->GetX();
    $y = $pdf->GetY();
    $pdf->SetFillColor(...($rowIndex % 2 === 0 ? [255, 255, 255] : [247, 247, 247]));
    foreach ($values as $i => $v) {
        $align = $i === 5 ? 'R' : 'L';
        if ($i === 0) {
            $pdf->SetFont('dejavusans', 'B', 7.4);
            $pdf->SetTextColor(23, 23, 23);
        } elseif ($i === 6) {
            $status = strtolower((string) ($row['status'] ?? ''));
            $statusColor = $status === 'approved' ? [22, 101, 52] : ($status === 'rejected' ? [153, 27, 27] : ($status === 'cancelled' ? [82, 82, 82] : [180, 83, 9]));
            $pdf->SetFont('dejavusans', 'B', 7.1);
            $pdf->SetTextColor(...$statusColor);
        } else {
            $pdf->SetFont('dejavusans', '', 7.2);
            $pdf->SetTextColor(38, 38, 38);
        }
        $pdf->MultiCell($cols[$i], $rowHeight, (string) $v, 1, $align, true, 0, '', '', true, 0, false, true, $rowHeight, 'T');
    }
    $pdf->SetXY($x, $y + $rowHeight);
}

if ($rows === []) {
    $pdf->SetFillColor(250, 250, 250);
    $pdf->SetTextColor(115, 115, 115);
    $pdf->SetFont('dejavusans', 'I', 9);
    $pdf->MultiCell(array_sum($cols), 18, 'Aucune FEB ne correspond a la selection.', 1, 'C', true, 1, '', '', true, 0, false, true, 18, 'M');
}

$pdf->Output('recap_feb_bci_' . date('Ymd_His') . '.pdf', 'I');
exit;
