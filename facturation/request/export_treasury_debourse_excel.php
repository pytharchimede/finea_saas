<?php
declare(strict_types=1);


require_once __DIR__.'/../../model/CompanyExportBranding.php';
require_once __DIR__.'/../../auth_check.php';
require_once __DIR__.'/../../model/Database.php';
require_once __DIR__.'/../../services/ManualTreasuryService.php';
require_once __DIR__.'/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

if (!(AccessManager::hasPermission('acces_tresorerie_lecture') || AccessManager::hasPermission('acces_direction') || AccessManager::hasPermission('acces_admin'))) {
    http_response_code(403);
    exit('Accès refusé.');
}

$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? '')) ? (string)$_GET['from'] : date('Y-m-01');
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to'] ?? '')) ? (string)$_GET['to'] : date('Y-m-t');
$group = in_array((string)($_GET['group'] ?? ''), ['chantier', 'ligne', 'categorie'], true) ? (string)$_GET['group'] : 'chantier';
$selectedProjects = array_values(array_filter(array_map('strval', (array)json_decode((string)($_GET['chantiers'] ?? '[]'), true))));
$data = (new ManualTreasuryService(Database::getConnection()))->dashboard($from, $to);
$projectLabel = static fn(array $row): string => trim(implode(' · ', array_filter([(string)($row['chantier_code'] ?? ''), (string)($row['chantier_title'] ?? '')]))) ?: 'Chantier non renseigné';
$rows = array_values(array_filter((array)($data['projectedDebourseRows'] ?? []), static fn(array $row): bool => !$selectedProjects || in_array($projectLabel($row), $selectedProjects, true)));

$groupLabel = match ($group) {
    'ligne' => static fn(array $row): string => trim((string)($row['ligne_debourse_label'] ?? $row['label'] ?? '')) ?: 'Ligne de déboursé',
    'categorie' => static fn(array $row): string => trim((string)($row['ligne_debourse_category'] ?? '')) ?: 'Sans catégorie',
    default => $projectLabel,
};
$groupHeading = $group === 'ligne' ? 'Ligne de déboursé' : ($group === 'categorie' ? 'Catégorie' : 'Chantier');
$byProject = [];
foreach ($rows as $row) $byProject[$groupLabel($row)][] = $row;
ksort($byProject, SORT_NATURAL | SORT_FLAG_CASE);
$moneyFormat = '#,##0 "FCFA"';
$sheetTitle = static function (string $value, array &$used): string {
    $base = trim((string)preg_replace('/[\\\\\/?*\[\]:]/', ' ', $value)) ?: 'Chantier';
    $base = mb_substr($base, 0, 31);
    $name = $base; $i = 2;
    while (isset($used[mb_strtolower($name)])) {
        $suffix = ' '.$i++;
        $name = mb_substr($base, 0, 31 - mb_strlen($suffix)).$suffix;
    }
    $used[mb_strtolower($name)] = true;
    return $name;
};
$writeHeader = static function ($sheet, string $title, string $subtitle): void {
    $sheet->mergeCells('A1:G1');
    $sheet->setCellValue('A1', $title)->getStyle('A1')->getFont()->setBold(true)->setSize(16)->getColor()->setRGB(CompanyExportBranding::rgb('primary'));
    $sheet->mergeCells('A2:G2');
    $sheet->setCellValue('A2', $subtitle)->getStyle('A2')->getFont()->setItalic(true)->getColor()->setRGB('64748B');
    $sheet->fromArray(['Déboursé / ligne', 'Catégorie', 'Prévision initiale', 'Déjà décaissé', 'FEB/fiches engagées', 'Reste à payer', 'Référence'], null, 'A4');
    $sheet->getStyle('A4:G4')->getFont()->setBold(true)->getColor()->setRGB(CompanyExportBranding::rgb('primary',true));
    $sheet->getStyle('A4:G4')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(CompanyExportBranding::rgb('primary'));
    $sheet->freezePane('A5');
    foreach (['A'=>38,'B'=>22,'C'=>19,'D'=>19,'E'=>22,'F'=>19,'G'=>18] as $column=>$width) $sheet->getColumnDimension($column)->setWidth($width);
};
$writeRows = static function ($sheet, array $items) use ($moneyFormat): array {
    $rowNumber = 5; $budget = $paid = $committed = $remaining = 0.0;
    foreach ($items as $item) {
        $budget += (float)($item['budget_amount'] ?? 0); $paid += (float)($item['paid_amount'] ?? 0);
        $committed += (float)($item['committed_amount'] ?? 0); $remaining += (float)($item['amount'] ?? 0);
        $sheet->fromArray([[(string)($item['ligne_debourse_label'] ?? $item['label'] ?? 'Ligne de déboursé'), (string)($item['ligne_debourse_category'] ?? ''), (float)($item['budget_amount'] ?? 0), (float)($item['paid_amount'] ?? 0), (float)($item['committed_amount'] ?? 0), (float)($item['amount'] ?? 0), (string)($item['reference'] ?? '')]], null, 'A'.$rowNumber);
        $rowNumber++;
    }
    $sheet->fromArray([['SOUS-TOTAL', '', $budget, $paid, $committed, $remaining, '']], null, 'A'.$rowNumber);
    $sheet->getStyle('A'.$rowNumber.':G'.$rowNumber)->getFont()->setBold(true);
    $sheet->getStyle('A'.$rowNumber.':G'.$rowNumber)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F1F5F9');
    $sheet->getStyle('C5:F'.$rowNumber)->getNumberFormat()->setFormatCode($moneyFormat);
    $sheet->getStyle('C5:F'.$rowNumber)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    $sheet->getStyle('A4:G'.$rowNumber)->getBorders()->getAllBorders()->setBorderStyle('thin')->getColor()->setRGB('D1D5DB');
    return [$budget, $paid, $committed, $remaining];
};

$book = new Spreadsheet();
$book->getProperties()->setCreator(CompanyExportBranding::creator())->setCompany((string)CompanyBranding::get()['company_name']);
$summary = $book->getActiveSheet(); $summary->setTitle('Récapitulatif');
$summary->mergeCells('A1:E1');
$summary->setCellValue('A1', 'PROJECTION DES DÉBOURSÉS — RÉCAPITULATIF')->getStyle('A1')->getFont()->setBold(true)->setSize(16)->getColor()->setRGB(CompanyExportBranding::rgb('primary'));
$summary->setCellValue('A2', 'Période de consultation : '.date('d/m/Y', strtotime($from)).' au '.date('d/m/Y', strtotime($to)).' · Tous les chantiers actifs');
$summary->fromArray([strtoupper($groupHeading), 'Déboursé global', 'Déjà payé', 'FEB/fiches engagées', 'Reste à payer'], null, 'A4');
$summary->getStyle('A4:E4')->getFont()->setBold(true)->getColor()->setRGB(CompanyExportBranding::rgb('primary',true));
$summary->getStyle('A4:E4')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(CompanyExportBranding::rgb('primary'));
foreach (['A'=>42,'B'=>20,'C'=>20,'D'=>24,'E'=>20] as $column=>$width) $summary->getColumnDimension($column)->setWidth($width);
$summaryRow = 5; $grand = [0,0,0,0]; $used = ['récapitulatif'=>true];
foreach ($byProject as $project=>$items) {
    $sheet = $book->createSheet(); $sheet->setTitle($sheetTitle($project, $used));
    $writeHeader($sheet, 'PROJECTION DES DÉBOURSÉS', $project.' · Détail des lignes');
    [$budget, $paid, $committed, $remaining] = $writeRows($sheet, $items);
    $summary->fromArray([[$project, $budget, $paid, $committed, $remaining]], null, 'A'.$summaryRow++);
    $grand[0]+=$budget; $grand[1]+=$paid; $grand[2]+=$committed; $grand[3]+=$remaining;
}
$summary->fromArray([['TOTAL GÉNÉRAL', $grand[0], $grand[1], $grand[2], $grand[3]]], null, 'A'.$summaryRow);
$summary->getStyle('A'.$summaryRow.':E'.$summaryRow)->getFont()->setBold(true);
$summary->getStyle('A'.$summaryRow.':E'.$summaryRow)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F1F5F9');
$summary->getStyle('B5:E'.$summaryRow)->getNumberFormat()->setFormatCode($moneyFormat);
$summary->getStyle('B5:E'.$summaryRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
$summary->getStyle('A4:E'.$summaryRow)->getBorders()->getAllBorders()->setBorderStyle('thin')->getColor()->setRGB('D1D5DB');
$summary->freezePane('A5');

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="projection_debourses_'.date('Ymd_His').'.xlsx"');
header('Cache-Control: max-age=0');
(new Xlsx($book))->save('php://output');
exit;
