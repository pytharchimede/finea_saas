<?php

require_once dirname(__DIR__) . '/auth_check.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/model/AuditTrail.php';

use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Import devis');

$headers = ['Designation', 'Unite', 'Quantite', 'Prix Unitaire', 'Groupe'];
$sheet->fromArray($headers, null, 'A1');
$sheet->fromArray([
    ['Fourniture et pose de panneau de chantier', 'U', 2, 85000, 'Installation de chantier'],
    ['Mobilisation du personnel et du materiel', 'Forfait', 1, 250000, 'Installation de chantier'],
    ['Beton dose a 350 kg/m3', 'm3', 12.5, 95000, 'Gros oeuvre'],
], null, 'A2');

$sheet->getStyle('A1:E1')->applyFromArray([
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '172348']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D9E1EC']]],
]);
$sheet->getStyle('A2:E4')->applyFromArray([
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D9E1EC']]],
]);
$sheet->getStyle('D2:D200')->getNumberFormat()->setFormatCode('#,##0');
$sheet->freezePane('A2');
$sheet->setAutoFilter('A1:E200');

$widths = ['A' => 48, 'B' => 18, 'C' => 14, 'D' => 20, 'E' => 30];
foreach ($widths as $column => $width) {
    $sheet->getColumnDimension($column)->setWidth($width);
}

$validation = new DataValidation();
$validation->setType(DataValidation::TYPE_DECIMAL);
$validation->setOperator(DataValidation::OPERATOR_GREATERTHAN);
$validation->setFormula1('0');
$validation->setAllowBlank(false);
$validation->setShowErrorMessage(true);
$validation->setErrorTitle('Valeur invalide');
$validation->setError('La quantite doit etre superieure a zero.');
for ($row = 2; $row <= 200; $row++) {
    $sheet->getCell('C' . $row)->setDataValidation(clone $validation);
}

$guide = $spreadsheet->createSheet();
$guide->setTitle('Guide');
$guide->fromArray([
    ['MODELE D IMPORT DEVIS FINEA'],
    ['Colonne', 'Obligatoire', 'Description'],
    ['Designation', 'Oui', 'Libelle de la prestation ou de l article'],
    ['Unite', 'Non', 'Identifiant, symbole ou libelle existant dans FINEA'],
    ['Quantite', 'Oui', 'Nombre strictement superieur a zero'],
    ['Prix Unitaire', 'Oui', 'Montant unitaire sans separateur de devise'],
    ['Groupe', 'Non', 'Permet de regrouper plusieurs lignes sous un meme titre'],
], null, 'A1');
$guide->mergeCells('A1:C1');
$guide->getStyle('A1:C1')->applyFromArray([
    'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => '172348']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FABD02']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
]);
$guide->getStyle('A2:C2')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
$guide->getStyle('A2:C2')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FABD02');
$guide->getColumnDimension('A')->setWidth(24);
$guide->getColumnDimension('B')->setWidth(16);
$guide->getColumnDimension('C')->setWidth(68);

AuditTrail::log('devis_excel_template_exported', [
    'module' => 'devis',
    'action' => 'export_modele_import',
    'entity_type' => 'devis_import_template',
    'entity_label' => 'Modele import devis',
]);

$filename = 'modele_import_devis_finea.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0, no-store, no-cache, must-revalidate');
header('Pragma: public');

(new Xlsx($spreadsheet))->save('php://output');
exit;
