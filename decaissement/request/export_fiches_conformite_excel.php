<?php
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../model/CompanyExportBranding.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/FicheDecaissement.php';
require_once __DIR__ . '/../includes/fiche_label_helper.php';
require_once __DIR__ . '/../../model/AuditTrail.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

try {
    $pdo = Database::getConnection();
    $model = new FicheDecaissement($pdo);

    // Récupérer sélection (POST selected[]=num_fiche)
    $selected = [];
    if (!empty($_POST['selected'])) {
        $selected = is_array($_POST['selected']) ? $_POST['selected'] : explode(',', (string)$_POST['selected']);
        $selected = array_values(array_filter(array_map('trim', $selected)));
    }

    // Récupérer filtres (GET)
    $filters = [
        'date_debut' => $_GET['date_debut'] ?? null,
        'date_fin' => $_GET['date_fin'] ?? null,
        'demandeur' => $_GET['demandeur'] ?? null,
        'chantier' => $_GET['chantier'] ?? null,
        'precision' => $_GET['precision'] ?? null,
        'designation' => $_GET['designation'] ?? null,
        'montant_min' => $_GET['montant_min'] ?? null,
        'montant_max' => $_GET['montant_max'] ?? null,
    ];

    $rows = [];
    if (!empty($selected)) {
        // Ignorer les filtres: exporter exactement la sélection
        foreach ($selected as $num) {
            $d = $model->getDetailsByNum($num) ?: $model->getByNum($num);
            if ($d) $rows[] = $d;
        }
    } else {
        // Exporter selon filtres (ou tout si aucun filtre)
        $rows = $model->listAcertifier($filters);
    }

    // Création du fichier Excel
    $xlsx = new Spreadsheet();
    $xlsx->getProperties()->setCreator(CompanyExportBranding::creator());
    $sheet = $xlsx->getActiveSheet();
    $r = 1;

    $title = 'Export fiches à certifier';
    if (!empty($filters['date_debut']) || !empty($filters['date_fin'])) {
        $title .= ' (' . ($filters['date_debut'] ?: '…') . ' → ' . ($filters['date_fin'] ?: '…') . ')';
    }
    $sheet->setCellValue('A' . $r, $title);
    $sheet->mergeCells("A{$r}:J{$r}");
    $sheet->getStyle("A{$r}:J{$r}")->getFont()->setBold(true)->setSize(14);
    $r += 2;

    // En-têtes
    $headers = ['Num fiche', 'Bénéficiaire', 'Chantier', 'Ligne déboursé', 'Ligne devis', 'Désignation', 'Précision', 'Catégorie', 'Montant (CFA)', 'Créée le'];
    $cols = range('A', 'J');
    foreach ($headers as $i => $h) {
        $sheet->setCellValue($cols[$i] . $r, $h);
    }
    $sheet->getStyle("A{$r}:J{$r}")->getFont()->setBold(true);
    $sheet->getStyle("A{$r}:J{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(CompanyExportBranding::rgb('primary'));
    $sheet->getStyle("A{$r}:J{$r}")->getFont()->getColor()->setRGB(CompanyExportBranding::rgb('primary',true));
    $r++;

    $total = 0.0;
    foreach ($rows as $f) {
        $f = enrich_fiche_labels($f);
        $num = $f['num_fiche'] ?? '';
        $benef = $f['beficiaire_fiche'] ?? $f['beneficiaire_fiche'] ?? '';
        $chantier = trim(($f['chantier_titre'] ?? '') . ($f['chantier_code'] ? ' [' . $f['chantier_code'] . ']' : ''));
        $ligneDebourse = $f['ligne_debourse_label'] ?? '';
        $ligneDevis = $f['ligne_devis_label'] ?? '';
        $designation = $f['designation_fiche'] ?? $f['ligne_designation'] ?? '';
        $precision = $f['precision_fiche'] ?? '';
        $categorie = $f['ligne_categorie'] ?? '';
        $montant = (float)($f['montant_fiche'] ?? 0);
        $datec = isset($f['date_creat_fiche']) ? date('Y-m-d', strtotime($f['date_creat_fiche'])) : '';
        $total += $montant;

        $sheet->setCellValue('A' . $r, $num);
        $sheet->setCellValue('B' . $r, $benef);
        $sheet->setCellValue('C' . $r, $chantier);
        $sheet->setCellValue('D' . $r, $ligneDebourse);
        $sheet->setCellValue('E' . $r, $ligneDevis);
        $sheet->setCellValue('F' . $r, $designation);
        $sheet->setCellValue('G' . $r, $precision);
        $sheet->setCellValue('H' . $r, $categorie);
        $sheet->setCellValue('I' . $r, $montant);
        $sheet->setCellValue('J' . $r, $datec);
        $r++;
    }

    // Total
    $sheet->setCellValue('H' . $r, 'TOTAL');
    $sheet->setCellValue('I' . $r, $total);
    $sheet->getStyle("H{$r}:I{$r}")->getFont()->setBold(true);

    // Formats et styles
    $sheet->getStyle("I1:I{$r}")->getNumberFormat()->setFormatCode('#,##0');
    foreach ($cols as $c) $sheet->getColumnDimension($c)->setAutoSize(true);
    $sheet->getStyle("A1:J{$r}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

    // Sortie
    $filename = 'export_fiches_conformite_' . date('Ymd_His') . '.xlsx';
    \AuditTrail::logFileEvent('export', [
        'module' => 'decaissement',
        'action' => 'fiches_conformite_export',
        'entity_type' => 'fiche_conformite_list',
        'entity_label' => 'Fiches à certifier',
        'format' => 'excel',
        'details' => [
            'selection_count' => is_countable($selected ?? null) ? count($selected) : 0,
            'row_count' => is_countable($rows ?? null) ? count($rows) : null,
            'total' => $total,
        ],
    ], $pdo);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    $writer = new Xlsx($xlsx);
    $writer->save('php://output');
    exit;
} catch (Throwable $e) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
