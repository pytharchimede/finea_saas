<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2).'/model/CompanyExportBranding.php';


require_once __DIR__ . '/../bootstrap.php';
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/model/CompanyPdfTemplate.php';

if (class_exists('AccessManager')) {
    AccessManager::requirePageAccess('planning_chantier/dashboard.php');
}

$chantierId = (int) ($_GET['chantier_id'] ?? 0);
$preparationId = (int) ($_GET['preparation_id'] ?? 0);
$service = new PlanningWorkProgressService($pdo);
$allowedChantierIds = array_map(static fn(array $row): int => (int) $row['id'], $service->chantiers());
if (!in_array($chantierId, $allowedChantierIds, true)) {
    http_response_code(404);
    exit('Ce document est réservé aux chantiers travaux.');
}
$allowedPreparationIds = array_map(static fn(array $row): int => (int) $row['id'], $service->preparations($chantierId));
if (!in_array($preparationId, $allowedPreparationIds, true)) {
    http_response_code(404);
    exit('Aucun compte rendu clôturé correspondant à ce chantier.');
}
$report = $service->report($chantierId, $preparationId);
$chantier = [];
foreach ($service->chantiers() as $candidate) {
    if ((int) $candidate['id'] === $chantierId) {
        $chantier = $candidate;
        break;
    }
}

class WorkProgressIsoPdf extends TCPDF
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

$documentId = 'TECH-AVT-' . date('Ymd-His') . '-C' . $chantierId;
$pdf = new WorkProgressIsoPdf('L', 'mm', 'A4', true, 'UTF-8', false);
$pdf->companyPdfTitle = 'Taux d’avancement des travaux';
$pdf->companyPdfType = 'Situation technique et financière';
$pdf->companyPdfId = $documentId;
$pdf->companyPdfCode = 'TECH-AVT-TRAVAUX';
$pdf->companyPdfQrPayload = json_encode([
    'document' => $documentId,
    'chantier_id' => $chantierId,
    'preparation_id' => $preparationId,
    'generated_at' => date(DATE_ATOM),
]);
$pdf->SetMargins(8, 38, 8);
$pdf->SetAutoPageBreak(true, 25);
$pdf->AddPage();

$summary = $report['summary'];
$pdf->SetFont('dejavusans', 'B', 11);
$pdf->Cell(0, 7, trim((string) ($chantier['code'] ?? '') . ' — ' . (string) ($chantier['titre'] ?? '')), 0, 1, 'C');
$pdf->SetFont('dejavusans', '', 7);
$pdf->Cell(0, 5, 'Situation de référence #' . $preparationId . ' · Éditée le ' . date('d/m/Y H:i'), 0, 1, 'C');

$cards = [
    ['Montant client', number_format((float) $summary['budget'], 0, ',', ' ') . ' FCFA'],
    ['Part exécutée', number_format((float) $summary['executed'], 0, ',', ' ') . ' FCFA'],
    ['Technique', number_format((float) $summary['technical_rate'], 1, ',', ' ') . '%'],
    ['Financier', number_format((float) $summary['financial_rate'], 1, ',', ' ') . '%'],
];
$x = 8;
foreach ($cards as [$label, $value]) {
    $pdf->SetFillColor(248, 250, 252);
    $pdf->SetDrawColor(203, 213, 225);
    $pdf->RoundedRect($x, 52, 66, 16, 2, '1111', 'DF');
    $pdf->SetXY($x + 3, 55);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->SetFont('dejavusans', '', 7);
    $pdf->Cell(60, 3, $label, 0, 2);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->SetFont('dejavusans', 'B', 10);
    $pdf->Cell(60, 5, $value);
    $x += 70;
}
$pdf->SetY(73);

$widths = [9, 70, 13, 18, 18, 18, 31, 31, 18, 45];
$headers = ['N°', 'Point, tâche ou sous-tâche', 'Unité', 'Qté prévue', 'Qté exécutée', 'Av. tech.', 'Montant client', 'Part exécutée', 'Av. fin.', 'Observations'];
$drawHeader = static function () use ($pdf, $widths, $headers): void {
    $pdf->SetFillColor(...CompanyExportBranding::rgbArray('primary'));
    $pdf->SetTextColor(...CompanyExportBranding::rgbArray('primary',true));
    $pdf->SetFont('dejavusans', 'B', 6.5);
    foreach ($headers as $index => $header) {
        $pdf->Cell($widths[$index], 8, $header, 1, 0, 'C', true);
    }
    $pdf->Ln();
};
$ensureSpace = static function () use ($pdf, $drawHeader): void {
    if ($pdf->GetY() > 178) {
        $pdf->AddPage();
        $pdf->SetY(40);
        $drawHeader();
    }
};
$drawRow = static function (array $values, bool $subtask = false) use ($pdf, $widths): void {
    $pdf->SetFont('dejavusans', $subtask ? 'I' : '', $subtask ? 5.9 : 6.2);
    $pdf->SetTextColor($subtask ? 71 : 30, $subtask ? 85 : 41, $subtask ? 105 : 59);
    if ($subtask) {
        $pdf->SetFillColor(248, 250, 252);
    }
    foreach ($values as $index => $value) {
        $limit = $index === 1 ? 54 : ($index === 9 ? 34 : 18);
        $align = in_array($index, [0, 2, 3, 4, 5, 8], true) ? 'C' : (in_array($index, [6, 7], true) ? 'R' : 'L');
        $pdf->Cell($widths[$index], 7, mb_strimwidth((string) $value, 0, $limit, '…'), 1, 0, $align, $subtask, '', 1);
    }
    $pdf->Ln();
};

$drawHeader();
$number = 0;
foreach ($report['groups'] as $group) {
    $ensureSpace();
    $pdf->SetFillColor(226, 232, 240);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->SetFont('dejavusans', 'B', 7);
    $pdf->Cell(array_sum(array_slice($widths, 0, 5)), 7, $group['title'], 1, 0, 'L', true, '', 1);
    $pdf->Cell($widths[5], 7, number_format((float) $group['technical_rate'], 1, ',', ' ') . '%', 1, 0, 'C', true);
    $pdf->Cell($widths[6], 7, number_format((float) $group['effective_budget'], 0, ',', ' '), 1, 0, 'R', true);
    $pdf->Cell($widths[7], 7, number_format((float) $group['executed_amount'], 0, ',', ' '), 1, 0, 'R', true);
    $pdf->Cell($widths[8], 7, number_format((float) $group['financial_rate'], 1, ',', ' ') . '%', 1, 0, 'C', true);
    $pdf->Cell($widths[9], 7, $group['observation'], 1, 1, 'L', true, '', 1);

    foreach ($group['rows'] as $row) {
        $ensureSpace();
        ++$number;
        $drawRow([
            $number,
            $row['task_title'],
            $row['unit_label'],
            number_format((float) $row['planned_quantity'], 2, ',', ' '),
            number_format((float) $row['executed_quantity'], 2, ',', ' '),
            number_format((float) $row['technical_rate'], 1, ',', ' ') . '%',
            number_format((float) $row['client_billed_amount'], 0, ',', ' '),
            number_format((float) $row['executed_billed_amount'], 0, ',', ' '),
            number_format((float) $row['financial_rate'], 1, ',', ' ') . '%',
            $row['observation'],
        ]);

        foreach ($row['subtasks'] ?? [] as $subIndex => $subtask) {
            $ensureSpace();
            $drawRow([
                $number . '.' . ($subIndex + 1),
                '↳ ' . $subtask['label'],
                $subtask['unit_label'],
                number_format((float) $subtask['planned_quantity'], 2, ',', ' '),
                number_format((float) $subtask['executed_quantity'], 2, ',', ' '),
                number_format((float) $subtask['progress'], 1, ',', ' ') . '%',
                number_format((float) $subtask['client_billed_amount'], 0, ',', ' '),
                number_format((float) $subtask['executed_billed_amount'], 0, ',', ' '),
                number_format((float) $subtask['progress'], 1, ',', ' ') . '%',
                $subtask['observation'],
            ], true);
        }
    }
}

$pdf->Output('taux_avancement_' . $documentId . '.pdf', 'I');
