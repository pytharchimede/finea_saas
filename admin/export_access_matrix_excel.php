<?php

declare(strict_types=1);

require_once __DIR__ . '/../auth_check.php';
AccessManager::requirePageAccess();
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../model/AccessMatrixService.php';

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$pdo = Database::getConnection();
$query = trim((string) ($_GET['q'] ?? ''));
$statusInput = (string) ($_GET['status'] ?? 'all');
$status = in_array($statusInput, ['all', 'active', 'inactive'], true) ? $statusInput : 'all';
$group = trim((string) ($_GET['group'] ?? ''));
$catalog = AccessMatrixService::catalog($pdo);
if ($group !== '') {
    $catalog = array_filter($catalog, static fn(array $permission): bool => $permission['group'] === $group);
}
$users = AccessMatrixService::users($pdo, $query, $status);
$stats = AccessMatrixService::stats($users, $catalog);

$spreadsheet = new Spreadsheet();
$spreadsheet->getProperties()
    ->setCreator('FINEA')
    ->setTitle('Matrice globale des accès')
    ->setSubject('Gouvernance des droits utilisateurs FINEA');
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Matrice des accès');
$sheet->setShowGridlines(false);
$sheet->getSheetView()->setZoomScale(80);

$lastColumnIndex = 4 + count($catalog);
$lastColumn = Coordinate::stringFromColumnIndex($lastColumnIndex);
$sheet->mergeCells("A1:{$lastColumn}1");
$sheet->setCellValue('A1', 'FINEA | MATRICE GLOBALE DES ACCÈS');
$sheet->getStyle("A1:{$lastColumn}1")->applyFromArray([
    'font' => ['bold' => true, 'color' => ['ARGB' => 'FFFFFFFF'], 'size' => 18],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['ARGB' => 'FF0F172A']],
    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
]);
$sheet->getRowDimension(1)->setRowHeight(34);

$sheet->mergeCells("A2:{$lastColumn}2");
$filterText = 'Export du ' . date('d/m/Y H:i') . ' | '
    . count($users) . ' compte(s) | ' . count($catalog) . ' droit(s)'
    . ($group !== '' ? ' | Famille : ' . $group : '')
    . ($query !== '' ? ' | Recherche : ' . $query : '');
$sheet->setCellValue('A2', $filterText);
$sheet->getStyle("A2:{$lastColumn}2")->applyFromArray([
    'font' => ['color' => ['ARGB' => 'FF475569'], 'italic' => true],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['ARGB' => 'FFF1F5F9']],
]);
$sheet->getRowDimension(2)->setRowHeight(22);

$summary = [
    ['Comptes', $stats['users']],
    ['Actifs', $stats['active_users']],
    ['Droits', $stats['permissions']],
    ['Couverture', number_format($stats['coverage'], 1, ',', ' ') . ' %'],
];
foreach ($summary as $index => [$label, $value]) {
    $column = Coordinate::stringFromColumnIndex(1 + ($index * 2));
    $valueColumn = Coordinate::stringFromColumnIndex(2 + ($index * 2));
    $sheet->setCellValue($column . '4', $label);
    $sheet->setCellValue($valueColumn . '4', $value);
    $sheet->getStyle($column . '4')->applyFromArray([
        'font' => ['bold' => true, 'color' => ['ARGB' => 'FF64748B']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['ARGB' => 'FFEFF6FF']],
    ]);
    $sheet->getStyle($valueColumn . '4')->applyFromArray([
        'font' => ['bold' => true, 'color' => ['ARGB' => 'FF1E3A8A']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['ARGB' => 'FFEFF6FF']],
    ]);
}

$headers = ['Utilisateur', 'Email', 'Rôle', 'Statut'];
$column = 5;
foreach ($catalog as $permission) {
    $headers[] = $permission['label'];
    $column++;
}
$sheet->fromArray($headers, null, 'A6');
$sheet->getStyle("A6:{$lastColumn}6")->applyFromArray([
    'font' => ['bold' => true, 'color' => ['ARGB' => 'FFFFFFFF'], 'size' => 9],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['ARGB' => 'FF1E3A8A']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['ARGB' => 'FFCBD5E1']]],
]);
$sheet->getRowDimension(6)->setRowHeight(54);

$row = 7;
foreach ($users as $user) {
    $name = trim((string) ($user['prenom'] ?? '') . ' ' . (string) ($user['nom'] ?? ''));
    $values = [
        $name !== '' ? $name : 'Utilisateur #' . (int) $user['id'],
        (string) ($user['mail_pro'] ?? ''),
        (string) ($user['role_label'] ?? ''),
        !empty($user['active']) ? 'Actif' : 'Inactif',
    ];
    foreach ($catalog as $permission) {
        $values[] = !empty($user[$permission['key']]) ? 'OUI' : 'NON';
    }
    $sheet->fromArray($values, null, 'A' . $row);

    $baseFill = $row % 2 === 0 ? 'FFF8FAFC' : 'FFFFFFFF';
    $sheet->getStyle("A{$row}:{$lastColumn}{$row}")->applyFromArray([
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['ARGB' => $baseFill]],
        'borders' => ['bottom' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['ARGB' => 'FFE2E8F0']]],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $sheet->getStyle("D{$row}")->getFont()->getColor()->setARGB(!empty($user['active']) ? 'FF047857' : 'FFBE123C');
    $sheet->getStyle("D{$row}")->getFont()->setBold(true);

    $permissionColumn = 5;
    foreach ($catalog as $permission) {
        $cell = Coordinate::stringFromColumnIndex($permissionColumn) . $row;
        $enabled = !empty($user[$permission['key']]);
        $sheet->getStyle($cell)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['ARGB' => $enabled ? 'FF047857' : 'FF94A3B8']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['ARGB' => $permission['universal'] ? 'FFFFF7ED' : ($enabled ? 'FFECFDF5' : $baseFill)]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $permissionColumn++;
    }
    $row++;
}

$lastRow = max(7, $row - 1);
$sheet->freezePane('E7');
$sheet->setAutoFilter("A6:{$lastColumn}{$lastRow}");
$sheet->getColumnDimension('A')->setWidth(28);
$sheet->getColumnDimension('B')->setWidth(34);
$sheet->getColumnDimension('C')->setWidth(22);
$sheet->getColumnDimension('D')->setWidth(12);
for ($index = 5; $index <= $lastColumnIndex; $index++) {
    $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index))->setWidth(16);
}
$sheet->getStyle("A6:{$lastColumn}{$lastRow}")->getAlignment()->setWrapText(true);
$sheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
$sheet->getPageSetup()->setFitToWidth(1)->setFitToHeight(0);
$sheet->getPageMargins()->setTop(0.3)->setBottom(0.3)->setLeft(0.2)->setRight(0.2);
$sheet->getHeaderFooter()->setOddFooter('&LFINEA - Administration&CConfidentiel&RPage &P / &N');

$legend = $spreadsheet->createSheet();
$legend->setTitle('Légende');
$legend->setShowGridlines(false);
$legend->setCellValue('A1', 'LÉGENDE ET FAMILLES DE DROITS FINEA');
$legend->mergeCells('A1:D1');
$legend->getStyle('A1:D1')->applyFromArray([
    'font' => ['bold' => true, 'color' => ['ARGB' => 'FFFFFFFF'], 'size' => 15],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['ARGB' => 'FF0F172A']],
]);
$legend->fromArray(['Famille', 'Droit', 'Clé technique', 'Accès commun obligatoire'], null, 'A3');
$legend->getStyle('A3:D3')->applyFromArray([
    'font' => ['bold' => true, 'color' => ['ARGB' => 'FFFFFFFF']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['ARGB' => 'FF1E3A8A']],
]);
$legendRow = 4;
foreach ($catalog as $permission) {
    $legend->fromArray([$permission['group'], $permission['label'], $permission['key'], $permission['universal'] ? 'OUI' : 'NON'], null, 'A' . $legendRow);
    if ($permission['universal']) {
        $legend->getStyle("A{$legendRow}:D{$legendRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFF7ED');
    }
    $legendRow++;
}
foreach (['A' => 26, 'B' => 38, 'C' => 36, 'D' => 25] as $letter => $width) {
    $legend->getColumnDimension($letter)->setWidth($width);
}
$legend->freezePane('A4');
$spreadsheet->setActiveSheetIndex(0);

AuditTrail::log('access_matrix_export', [
    'module' => 'admin',
    'action' => 'export_access_matrix_excel',
    'entity_type' => 'access_matrix',
    'result' => 'success',
    'details' => ['users' => count($users), 'permissions' => count($catalog)],
], $pdo);

while (ob_get_level() > 0) {
    ob_end_clean();
}
$fileName = 'finea_matrice_acces_' . date('Ymd_Hi') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('Cache-Control: max-age=0, no-store');
(new Xlsx($spreadsheet))->save('php://output');
exit;
