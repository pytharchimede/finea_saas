<?php
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$id = (int)($_GET['id'] ?? 0);
$contract = $contractManager->get($id);
if (!$contract) {
    http_response_code(404);
    exit('Contrat introuvable.');
}

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Registre QHSE');
$sheet->mergeCells('A1:C1');
$sheet->setCellValue('A1', 'REGISTRE QHSE — ' . $contract['reference']);
$sheet->mergeCells('A2:C2');
$sheet->setCellValue('A2', 'Objet : ' . ($contract['objet'] ?: 'Non renseigné') . ' · Chantier : ' . ($contract['chantier_code'] ?? '') . ' ' . ($contract['chantier_titre'] ?? ''));
$sheet->setCellValue('A4', 'EPI obligatoires');
$sheet->setCellValue('B4', 'Exigences QHSE et mesures de prévention');
$sheet->setCellValue('C4', 'Formations, habilitations et accueils sécurité requis');
$sheet->fromArray([
    ['Casque, gilet haute visibilité et chaussures de sécurité.', 'Port obligatoire sur toute la zone de travaux ; contrôle quotidien.', 'Accueil sécurité avant prise de poste et émargement des intervenants.'],
    ['Gants adaptés et lunettes de protection selon le poste.', 'Balisage de la zone, signalisation et gestion des accès.', 'Habilitations techniques requises selon les tâches à exécuter.'],
    ['', 'Prévention des chutes, manutentions et coactivité.', 'Briefing quotidien et rappel des consignes de sécurité.'],
], null, 'A5');

$sheet->getStyle('A1:C1')->getFont()->setBold(true)->setSize(16)->getColor()->setARGB('FFFFFFFF');
$sheet->getStyle('A1:C1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF047857');
$sheet->getStyle('A1:C2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
$sheet->getStyle('A2:C2')->getFont()->setItalic(true)->getColor()->setARGB('FF475569');
$sheet->getStyle('A4:C4')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
$sheet->getStyle('A4:C4')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF0F172A');
$sheet->getStyle('A4:C20')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FFCBD5E1');
$sheet->getStyle('A4:C20')->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);
$sheet->getStyle('A5:C7')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF0FDFA');
$sheet->getRowDimension(1)->setRowHeight(28);
$sheet->getRowDimension(2)->setRowHeight(30);
$sheet->freezePane('A5');
$sheet->setAutoFilter('A4:C20');
foreach (['A' => 44, 'B' => 58, 'C' => 62] as $column => $width) $sheet->getColumnDimension($column)->setWidth($width);

$instructions = $spreadsheet->createSheet();
$instructions->setTitle('Instructions');
$instructions->mergeCells('A1:A1');
$instructions->setCellValue('A1', 'MODE D’EMPLOI DU REGISTRE QHSE');
$instructions->getStyle('A1')->getFont()->setBold(true)->setSize(15)->getColor()->setARGB('FFFFFFFF');
$instructions->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF047857');
$instructions->setCellValue('A3', "1. Les trois colonnes reprennent exactement toutes les rubriques de l’étape QHSE du contrat : EPI obligatoires ; Exigences QHSE et mesures de prévention ; Formations, habilitations et accueils sécurité requis.\n2. Renseignez chaque élément dans la colonne de sa rubrique ; ajoutez autant de lignes que nécessaire.\n3. Enregistrez le fichier au format Excel (.xlsx ou .xls), puis importez-le dans l’étape QHSE du contrat.\n4. L’import du registre remplace les listes saisies manuellement pour cette validation.");
$instructions->getStyle('A3')->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
$instructions->getColumnDimension('A')->setWidth(110);
$instructions->getRowDimension(3)->setRowHeight(105);

$spreadsheet->setActiveSheetIndex(0);
$filename = 'registre_qhse_' . preg_replace('/[^A-Za-z0-9_-]/', '_', (string)$contract['reference']) . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0, no-store');
(new Xlsx($spreadsheet))->save('php://output');
exit;
