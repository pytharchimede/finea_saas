<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../controllers/RhMissionController.php';

function missionPdfStatusLabel(string $status, array $labels): string
{
    $status = trim($status);
    return $labels[$status] ?? ($status !== '' ? $status : '-');
}

function missionPdfDate(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '' || $value === '0000-00-00') {
        return '-';
    }
    $ts = strtotime($value);
    return $ts ? date('d/m/Y', $ts) : $value;
}

function missionPdfShort(string $text, int $limit = 34): string
{
    $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    if (function_exists('mb_strimwidth')) {
        return (string) mb_strimwidth($text, 0, $limit, '...');
    }
    return strlen($text) > $limit ? substr($text, 0, $limit - 3) . '...' : $text;
}

function missionPdfTotal(array $lines): float
{
    $total = 0.0;
    foreach ($lines as $line) {
        $total += (float) ($line['line_total'] ?? 0);
    }
    return $total;
}

$pdo = \Database::getConnection();
$controller = new \RhMissionController($pdo);
$manager = $controller->missions();
$statusLabels = $manager->getStatusLabels();
$filters = [
    'q' => trim((string) ($_GET['q'] ?? '')),
    'status' => trim((string) ($_GET['status'] ?? '')),
];
$missions = ($manager->listMissions($filters, 1, 5000)['items'] ?? []);

$pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('Banamur');
$pdf->SetAuthor('Banamur');
$pdf->SetTitle('Récapitulatif ordres de mission RH');
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 12);
$pdf->AddPage();

$pdf->SetFillColor(17, 24, 39);
$pdf->RoundedRect(10, 10, 277, 28, 4, '1111', 'F');
$pdf->SetTextColor(255, 255, 255);
$pdf->SetFont('dejavusans', 'B', 17);
$pdf->SetXY(14, 15);
$pdf->Cell(180, 7, 'Récapitulatif des ordres de mission RH', 0, 1, 'L');
$pdf->SetFont('dejavusans', '', 9);
$pdf->SetX(14);
$pdf->Cell(180, 5, 'Export du ' . date('d/m/Y H:i'), 0, 0, 'L');
$pdf->SetFont('dejavusans', 'B', 14);
$pdf->SetXY(210, 16);
$pdf->Cell(70, 7, (string) count($missions) . ' ordre(s)', 0, 1, 'R');

$pdf->SetY(45);
$pdf->SetFillColor(239, 246, 255);
$pdf->SetTextColor(30, 64, 175);
$pdf->SetFont('dejavusans', 'B', 8);
$cols = [30, 26, 48, 55, 42, 28, 24, 24];
$headers = ['Code', 'Statut', 'Collaborateur', 'Objet', 'Destination', 'Service', 'Début', 'Total'];
foreach ($headers as $i => $header) {
    $pdf->Cell($cols[$i], 7, $header, 1, 0, 'L', true);
}
$pdf->Ln();
$pdf->SetTextColor(31, 41, 55);
$pdf->SetFont('dejavusans', '', 7.3);

foreach ($missions as $mission) {
    $expenses = $manager->getMissionExpenses((int) ($mission['id'] ?? 0));
    $values = [
        (string) ($mission['mission_code'] ?? ''),
        missionPdfStatusLabel((string) ($mission['status'] ?? ''), $statusLabels),
        missionPdfShort((string) ($mission['personnel_name'] ?? ''), 30),
        missionPdfShort((string) ($mission['mission_title'] ?? ''), 38),
        missionPdfShort((string) ($mission['destination'] ?? ''), 30),
        missionPdfShort((string) ($mission['service_label'] ?? ''), 24),
        missionPdfDate((string) ($mission['start_date'] ?? '')),
        number_format(missionPdfTotal($expenses), 0, ',', ' '),
    ];
    foreach ($values as $i => $value) {
        $pdf->Cell($cols[$i], 6, $value, 1, 0, $i === 7 ? 'R' : 'L');
    }
    $pdf->Ln();
}

$pdf->Output('recap_ordres_mission_rh_' . date('Ymd_His') . '.pdf', 'I');
exit;
