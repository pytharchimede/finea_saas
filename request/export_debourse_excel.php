<?php
require_once '../vendor/autoload.php';
require_once '../model/Database.php';
require_once '../model/AuditTrail.php';
require_once '../model/Devis.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;

$devis_id = $_GET['devisId'] ?? $_GET['devis_id'] ?? null;
if (!$devis_id) {
    die("ID devis manquant");
}

$pdo = Database::getConnection();
$devisModel = new Devis($pdo);
$devis = $devisModel->getDevisById($devis_id);
$debourses = $devisModel->getDeboursesByDevisId($devis_id);

// Corrige l'ordre inversé: tri stable haut → bas (ligne_devis_id ASC puis id ASC)
if (is_array($debourses) && count($debourses) > 1) {
    usort($debourses, static function ($a, $b) {
        $la = (int)($a['ligne_devis_id'] ?? 0);
        $lb = (int)($b['ligne_devis_id'] ?? 0);
        if ($la !== $lb) return $la <=> $lb;
        return (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0);
    });
}

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$row = 1;

// Titre principal
$sheet->setCellValue('A' . $row, "Déboursés du devis {$devis['numero_devis']}");
$sheet->mergeCells("A{$row}:E{$row}");
$sheet->getStyle("A{$row}:E{$row}")->getFont()->setBold(true)->setSize(16);
$sheet->getStyle("A{$row}:E{$row}")->getAlignment()->setHorizontal('center');
$row += 2;

// Infos client
$sheet->setCellValue('A' . $row, "Client : {$devis['destine_a']}");
$row++;

$totalLignesDebourse = $devisModel->getTotalDebourseByDevisId($devis_id);
$sheet->setCellValue('A' . $row, "Total lignes déboursé : " . number_format($totalLignesDebourse, 0, ',', ' ') . " FCFA");
$row += 2;

$grandTotalRows = [];
foreach ($debourses as $debourse) {
    // Titre déboursé
    $designationDebourse = $debourse['designation_ligne'] ?? ($debourse['designation'] ?? 'N/A');
    $sheet->setCellValue('A' . $row, "Déboursé : " . $designationDebourse);
    $sheet->mergeCells("A{$row}:E{$row}");
    $sheet->getStyle("A{$row}:E{$row}")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
    $sheet->getStyle("A{$row}:E{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('305496');
    $sheet->getStyle("A{$row}:E{$row}")->getAlignment()->setHorizontal('left');
    $row++;

    // En-têtes
    $sheet->setCellValue('A' . $row, 'Catégorie');
    $sheet->setCellValue('B' . $row, 'Désignation');
    $sheet->setCellValue('C' . $row, 'Montant');
    $sheet->setCellValue('D' . $row, 'Date début');
    $sheet->setCellValue('E' . $row, 'Date fin');
    $sheet->getStyle("A{$row}:E{$row}")->getFont()->setBold(true);
    $sheet->getStyle("A{$row}:E{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D9E1F2');
    $sheet->getStyle("A{$row}:E{$row}")->getAlignment()->setHorizontal('center');
    $row++;

    $startDataRow = $row;
    $sousLignes = $devisModel->getLignesDebourseByDebourseId($debourse['id']);
    if (is_array($sousLignes) && count($sousLignes) > 1) {
        usort($sousLignes, static function ($a, $b) {
            return (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0);
        });
    }
    foreach ($sousLignes as $ligne) {
        $sheet->setCellValue('A' . $row, $ligne['categorie']);
        $sheet->setCellValue('B' . $row, $ligne['designation']);
        $sheet->setCellValue('C' . $row, $ligne['montant']);
        $sheet->setCellValue('D' . $row, $ligne['date_debut']);
        $sheet->setCellValue('E' . $row, $ligne['date_fin']);
        $row++;
    }
    $endDataRow = $row - 1;

    // Total par déboursé (formule Excel)
    $sheet->setCellValue('B' . $row, 'Total déboursé :');
    $sheet->setCellValue('C' . $row, "=SUM(C{$startDataRow}:C{$endDataRow})");
    $sheet->getStyle("B{$row}:C{$row}")->getFont()->setBold(true);
    $sheet->getStyle("C{$startDataRow}:C{$row}")->getNumberFormat()->setFormatCode('#,##0 FCFA');
    $grandTotalRows[] = $row;
    $row += 2; // Ligne vide entre les déboursés
}

// Grand total général (somme de tous les totaux déboursés)
if (count($grandTotalRows) > 0) {
    $sheet->setCellValue('B' . $row, 'TOTAL GENERAL :');
    $sumFormula = '=SUM(' . implode(',', array_map(function ($r) {
        return "C$r";
    }, $grandTotalRows)) . ')';
    $sheet->setCellValue('C' . $row, $sumFormula);
    $sheet->getStyle("B{$row}:C{$row}")->getFont()->setBold(true)->getColor()->setRGB('C00000');
    $sheet->getStyle("C{$row}")->getNumberFormat()->setFormatCode('#,##0 FCFA');
}

// Largeur automatique
foreach (range('A', 'E') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// Bordures fines sur tout le tableau
$sheet->getStyle("A1:E{$row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

// Envoi du fichier Excel au navigateur
\AuditTrail::logFileEvent('export', [
    'module' => 'decaissement',
    'action' => 'debourse_detail_export',
    'entity_type' => 'devis',
    'entity_id' => isset($devis['id']) ? (string)$devis['id'] : (string)$devis_id,
    'entity_label' => (string)($devis['numero_devis'] ?? 'Déboursé devis'),
    'format' => 'excel',
    'details' => [
        'debourse_count' => is_countable($debourses ?? null) ? count($debourses) : null,
        'total_debourse' => $totalLignesDebourse,
    ],
], $pdo);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="debourses_devis_' . $devis['id'] . '.xlsx"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
