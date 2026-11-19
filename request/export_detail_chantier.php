<?php
session_start();
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../decaissement/model/Dashboard.php';
require_once __DIR__ . '/../model/Utils.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;

// Vérifier les paramètres
$chantierId = $_GET['chantier_id'] ?? null;
$format = $_GET['format'] ?? 'excel';

if (!$chantierId) {
    http_response_code(400);
    die('ID du chantier requis');
}

$pdo = Database::getConnection();
$dashboardModel = new Dashboard($pdo);

// Récupérer les détails budgétaires
$detailsChantier = $dashboardModel->getDetailsBudgetaireChantier($chantierId);

if (!$detailsChantier) {
    http_response_code(404);
    die('Chantier non trouvé');
}

switch (strtolower((string)$format)) {
    case 'excel':
    case 'xlsx':
        \AuditTrail::logFileEvent('export', [
            'module' => 'decaissement',
            'action' => 'chantier_detail_export',
            'entity_type' => 'chantier',
            'entity_id' => (string)$chantierId,
            'entity_label' => chantierLabel($detailsChantier),
            'format' => 'excel',
            'details' => [
                'client' => clientLabel($detailsChantier),
                'line_count' => is_countable($detailsChantier['lignes_devis'] ?? null) ? count($detailsChantier['lignes_devis']) : null,
                'fiche_count' => is_countable($detailsChantier['fiches'] ?? null) ? count($detailsChantier['fiches']) : null,
            ],
        ], $pdo);
        exportExcelXlsx($detailsChantier);
        break;
    case 'pdf':
        \AuditTrail::logFileEvent('export', [
            'module' => 'decaissement',
            'action' => 'chantier_detail_export',
            'entity_type' => 'chantier',
            'entity_id' => (string)$chantierId,
            'entity_label' => chantierLabel($detailsChantier),
            'format' => 'pdf',
            'details' => [
                'client' => clientLabel($detailsChantier),
                'line_count' => is_countable($detailsChantier['lignes_devis'] ?? null) ? count($detailsChantier['lignes_devis']) : null,
                'fiche_count' => is_countable($detailsChantier['fiches'] ?? null) ? count($detailsChantier['fiches']) : null,
            ],
        ], $pdo);
        exportPdfTcpdf($detailsChantier);
        break;
    case 'print':
        \AuditTrail::logFileEvent('export', [
            'module' => 'decaissement',
            'action' => 'chantier_detail_export',
            'entity_type' => 'chantier',
            'entity_id' => (string)$chantierId,
            'entity_label' => chantierLabel($detailsChantier),
            'format' => 'print',
            'details' => [
                'client' => clientLabel($detailsChantier),
            ],
        ], $pdo);
        renderPrintHtml($detailsChantier);
        break;
    default:
        http_response_code(400);
        die('Format non supporté');
}

function chantierLabel(array $details): string
{
    $chantier = $details['chantier'] ?? [];
    return (string)($chantier['lib_chantier'] ?? $chantier['titre'] ?? 'Chantier');
}

function clientLabel(array $details): string
{
    $chantier = $details['chantier'] ?? [];
    $nom = trim((string)($chantier['nom_client'] ?? ''));
    if ($nom !== '') return $nom;
    $nom2 = trim((string)($chantier['client_nom'] ?? ''));
    $prenom2 = trim((string)($chantier['client_prenom'] ?? ''));
    return trim($nom2 . ' ' . $prenom2);
}

function statutFiche(array $fiche): string
{
    if (!empty($fiche['decaisse']) && (int)$fiche['decaisse'] === 1) return 'Décaissé';
    if (isset($fiche['approuve'])) {
        if ((int)$fiche['approuve'] === 1) return 'Approuvé';
        if ((int)$fiche['approuve'] === 2) return 'Refusé';
    }
    return 'En attente';
}

function computeFicheStats(array $fiches, array $stats = []): array
{
    // Normalise et complète les stats pour éviter les trous (certaines pages ne renvoient pas tout).
    $out = [
        'total_fiches' => isset($stats['total_fiches']) ? (int)$stats['total_fiches'] : count($fiches),
        'fiches_approuvees' => isset($stats['fiches_approuvees']) ? (int)$stats['fiches_approuvees'] : 0,
        'fiches_decaissees' => isset($stats['fiches_decaissees']) ? (int)$stats['fiches_decaissees'] : 0,
        'fiches_refusees' => isset($stats['fiches_refusees']) ? (int)$stats['fiches_refusees'] : 0,
        'fiches_en_attente' => isset($stats['fiches_en_attente']) ? (int)$stats['fiches_en_attente'] : 0,
        'fiches_conformes' => isset($stats['fiches_conformes']) ? (int)$stats['fiches_conformes'] : 0,
        'fiches_otp_validees' => isset($stats['fiches_otp_validees']) ? (int)$stats['fiches_otp_validees'] : 0,
    ];

    $needRecompute = ($out['total_fiches'] === 0 && count($fiches) > 0)
        || ($out['fiches_approuvees'] === 0 && $out['fiches_refusees'] === 0 && $out['fiches_en_attente'] === 0 && count($fiches) > 0)
        || ($out['fiches_decaissees'] === 0 && count($fiches) > 0);

    if ($needRecompute) {
        $out['total_fiches'] = count($fiches);
        $out['fiches_approuvees'] = 0;
        $out['fiches_decaissees'] = 0;
        $out['fiches_refusees'] = 0;
        $out['fiches_en_attente'] = 0;
        $out['fiches_conformes'] = 0;
        $out['fiches_otp_validees'] = 0;

        foreach ($fiches as $f) {
            if (!empty($f['approuve'])) {
                if ((int)$f['approuve'] === 1) $out['fiches_approuvees']++;
                if ((int)$f['approuve'] === 2) $out['fiches_refusees']++;
            }
            if (isset($f['approuve']) && (int)$f['approuve'] === 0) $out['fiches_en_attente']++;
            if (!empty($f['decaisse']) && (int)$f['decaisse'] === 1) $out['fiches_decaissees']++;
            if (!empty($f['conforme']) && (int)$f['conforme'] === 1) $out['fiches_conformes']++;
            if (!empty($f['otp_valide']) && (int)$f['otp_valide'] === 1) $out['fiches_otp_validees']++;
        }
    }

    // Ajuste en attente si pas cohérent
    $known = $out['fiches_approuvees'] + $out['fiches_refusees'] + $out['fiches_en_attente'];
    if ($out['total_fiches'] > 0 && $known === 0) {
        $out['fiches_en_attente'] = $out['total_fiches'];
    }

    return $out;
}

function computeZoneStats(array $zones, array $lignes = []): array
{
    if (empty($zones) && !empty($lignes)) {
        foreach ($lignes as $ligne) {
            $zone = trim((string)($ligne['zone'] ?? ''));
            if ($zone === '') {
                $zone = 'Zone non définie';
            }
            $zones[$zone][] = $ligne;
        }
    }

    $zoneStats = [];
    foreach ($zones as $zoneName => $zoneLines) {
        $budget = 0.0;
        $debourse = 0.0;
        $paye = 0.0;
        $lineCount = 0;

        foreach ($zoneLines as $ligne) {
            $lineCount++;
            $budget += (float)($ligne['total'] ?? 0);
            $debourse += (float)($ligne['montant_debourse_reel'] ?? 0);
            $paye += (float)($ligne['montant_paye'] ?? 0);
        }

        $reference = $debourse > 0 ? $debourse : $budget;
        $restant = max(0, $reference - $paye);
        $progression = $reference > 0 ? ($paye / $reference) * 100 : 0;

        $zoneStats[] = [
            'zone' => (string)$zoneName,
            'line_count' => $lineCount,
            'budget' => $budget,
            'debourse' => $debourse,
            'paye' => $paye,
            'restant' => $restant,
            'progression' => $progression,
            'lines' => $zoneLines,
        ];
    }

    usort($zoneStats, function ($left, $right) {
        return strcmp((string)$left['zone'], (string)$right['zone']);
    });

    return $zoneStats;
}

function buildZoneProgressChartItems(array $zoneStats): array
{
    $items = [];
    foreach ($zoneStats as $index => $zone) {
        $palette = [
            [59, 130, 246],
            [34, 197, 94],
            [234, 179, 8],
            [249, 115, 22],
            [124, 58, 237],
            [20, 184, 166],
        ];
        $items[] = [
            'label' => (string)$zone['zone'],
            'value' => round((float)($zone['progression'] ?? 0), 1),
            'rgb' => $palette[$index % count($palette)],
        ];
    }

    usort($items, function ($left, $right) {
        $lv = (float)($left['value'] ?? 0);
        $rv = (float)($right['value'] ?? 0);
        if ($lv === $rv) {
            return strcmp((string)$left['label'], (string)$right['label']);
        }
        return $lv < $rv ? 1 : -1;
    });

    return $items;
}

function drawPdfBarChart(TCPDF $pdf, float $x, float $y, float $w, float $h, string $title, array $items, callable $formatValue): float
{
    // items: [ ['label'=>string,'value'=>float,'rgb'=>[r,g,b]] ... ]
    $pdf->SetDrawColor(226, 232, 240);
    $pdf->SetLineWidth(0.2);
    $pdf->RoundedRect($x, $y, $w, $h, 1.5, '1111', 'D');

    $pad = 4;
    $titleH = 8;
    $labelW = 52;
    $valueW = 26;
    $barW = max(10, $w - $pad * 2 - $labelW - $valueW - 6);

    $pdf->SetXY($x + $pad, $y + 2);
    $pdf->SetFont('dejavusans', 'B', 11);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell($w - $pad * 2, 6, Utils::toMbConvertEncoding($title), 0, 1, 'L');

    $max = 0.0;
    foreach ($items as $it) {
        $max = max($max, (float)($it['value'] ?? 0));
    }
    if ($max <= 0) $max = 1;

    $rowH = 10;
    $rowsMax = (int)floor(($h - $titleH - $pad) / $rowH);
    $items = array_slice($items, 0, max(1, $rowsMax));

    $cy = $y + $titleH;
    foreach ($items as $it) {
        $label = (string)($it['label'] ?? '');
        $val = (float)($it['value'] ?? 0);
        $rgb = $it['rgb'] ?? [59, 130, 246];

        $pdf->SetXY($x + $pad, $cy + 1);
        $pdf->SetFont('dejavusans', '', 9);
        $pdf->SetTextColor(71, 85, 105);
        $pdf->MultiCell($labelW, 8, Utils::toMbConvertEncoding($label), 0, 'L', false, 0);

        // Bar background
        $bx = $x + $pad + $labelW + 2;
        $by = $cy + 3.2;
        $bh = 4.2;
        $pdf->SetFillColor(241, 245, 249);
        $pdf->Rect($bx, $by, $barW, $bh, 'F');

        // Bar fill
        $fill = (float)($barW * min(1, $val / $max));
        $pdf->SetFillColor((int)$rgb[0], (int)$rgb[1], (int)$rgb[2]);
        if (method_exists($pdf, 'SetAlpha')) {
            $pdf->SetAlpha(0.35);
        }
        $pdf->Rect($bx, $by, $fill, $bh, 'F');
        if (method_exists($pdf, 'SetAlpha')) {
            $pdf->SetAlpha(1);
        }

        // Value
        $pdf->SetXY($bx + $barW + 2, $cy + 1);
        $pdf->SetFont('dejavusans', 'B', 9);
        $pdf->SetTextColor(15, 23, 42);
        $pdf->Cell($valueW, 8, Utils::toMbConvertEncoding($formatValue($val)), 0, 0, 'R');

        $cy += $rowH;
    }

    return $y + $h;
}

function exportExcelXlsx(array $details): void
{
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    $chantier = $details['chantier'] ?? [];
    $totaux = $details['totaux'] ?? [];
    $lignes = $details['lignes_devis'] ?? [];
    $fiches = $details['fiches'] ?? [];
    $stats = computeFicheStats($fiches, $details['stats_fiches'] ?? []);

    $xlsx = new Spreadsheet();
    $xlsx->getProperties()
        ->setCreator('BANAMUR')
        ->setTitle('Détail budgétaire chantier')
        ->setSubject('Détail budgétaire')
        ->setDescription('Export généré automatiquement');

    $titleStyle = [
        'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => '0F172A']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F1F5F9']],
    ];
    $kpiStyle = [
        'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => '0F172A']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F8FAFC']],
        'borders' => [
            'outline' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E2E8F0']]
        ]
    ];
    $kpiLabelStyle = [
        'font' => ['bold' => true, 'size' => 9, 'color' => ['rgb' => '475569']],
    ];
    $headStyle = [
        'font' => ['bold' => true, 'color' => ['rgb' => '111827']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E5E7EB']],
    ];
    $borderAll = [
        'borders' => [
            'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E5E7EB']]
        ]
    ];

    // --- Feuille Résumé ---
    $sheet = $xlsx->getActiveSheet();
    $sheet->setTitle('Résumé');
    $r = 1;
    $sheet->setCellValue('A' . $r, "Détail budgétaire – " . chantierLabel($details));
    $sheet->mergeCells("A{$r}:F{$r}");
    $sheet->getStyle("A{$r}:F{$r}")->applyFromArray($titleStyle);
    $r += 2;

    $sheet->setCellValue('A' . $r, 'Date export');
    $sheet->setCellValue('B' . $r, date('d/m/Y H:i'));
    $r++;
    $sheet->setCellValue('A' . $r, 'Client');
    $sheet->setCellValue('B' . $r, clientLabel($details));
    $r++;
    $sheet->setCellValue('A' . $r, 'Devis');
    $sheet->setCellValue('B' . $r, (string)($chantier['numero_devis'] ?? ''));
    $r++;

    // Bloc KPI en haut
    $budget = (float)($totaux['budget_total'] ?? 0);
    $paye = (float)($totaux['montant_paye'] ?? 0);
    $restant = (float)($totaux['montant_restant'] ?? 0);
    $pct = round((float)($totaux['pourcentage_realise'] ?? 0), 1);
    $totalFiches = (int)($stats['total_fiches'] ?? 0);
    $fichesDecaissees = (int)($stats['fiches_decaissees'] ?? 0);
    $fichesApprouvees = (int)($stats['fiches_approuvees'] ?? 0);
    $fichesRefusees = (int)($stats['fiches_refusees'] ?? 0);
    $fichesEnAttente = (int)($stats['fiches_en_attente'] ?? 0);
    $fichesConformes = (int)($stats['fiches_conformes'] ?? 0);
    $fichesOtp = (int)($stats['fiches_otp_validees'] ?? 0);

    $r++;
    $sheet->setCellValue('A' . $r, 'Budget total');
    $sheet->setCellValue('C' . $r, 'Payé');
    $sheet->setCellValue('E' . $r, 'Restant');
    $sheet->setCellValue('G' . $r, 'Progression');
    $sheet->getStyle("A{$r}:H{$r}")->applyFromArray($kpiLabelStyle);
    $sheet->mergeCells("A{$r}:B{$r}");
    $sheet->mergeCells("C{$r}:D{$r}");
    $sheet->mergeCells("E{$r}:F{$r}");
    $sheet->mergeCells("G{$r}:H{$r}");
    $r++;
    $sheet->setCellValue('A' . $r, $budget);
    $sheet->setCellValue('C' . $r, $paye);
    $sheet->setCellValue('E' . $r, $restant);
    $sheet->setCellValue('G' . $r, $pct);
    $sheet->mergeCells("A{$r}:B{$r}");
    $sheet->mergeCells("C{$r}:D{$r}");
    $sheet->mergeCells("E{$r}:F{$r}");
    $sheet->mergeCells("G{$r}:H{$r}");
    $sheet->getStyle("A{$r}:H{$r}")->applyFromArray($kpiStyle);
    $sheet->getStyle("A{$r}:F{$r}")->getNumberFormat()->setFormatCode('#,##0');
    $sheet->getStyle("G{$r}:H{$r}")->getNumberFormat()->setFormatCode('0.0');

    $r++;
    $sheet->setCellValue('A' . $r, 'Total fiches');
    $sheet->setCellValue('C' . $r, 'Fiches décaissées');
    $sheet->mergeCells("A{$r}:B{$r}");
    $sheet->mergeCells("C{$r}:D{$r}");
    $sheet->getStyle("A{$r}:D{$r}")->applyFromArray($kpiLabelStyle);
    $r++;
    $sheet->setCellValue('A' . $r, $totalFiches);
    $sheet->setCellValue('C' . $r, $fichesDecaissees);
    $sheet->mergeCells("A{$r}:B{$r}");
    $sheet->mergeCells("C{$r}:D{$r}");
    $sheet->getStyle("A{$r}:D{$r}")->applyFromArray($kpiStyle);

    // Statuts complémentaires
    $r += 2;
    $sheet->setCellValue('A' . $r, 'Approuvées');
    $sheet->setCellValue('C' . $r, 'Refusées');
    $sheet->setCellValue('E' . $r, 'En attente');
    $sheet->setCellValue('G' . $r, 'Conformes / OTP');
    $sheet->getStyle("A{$r}:H{$r}")->applyFromArray($kpiLabelStyle);
    $sheet->mergeCells("A{$r}:B{$r}");
    $sheet->mergeCells("C{$r}:D{$r}");
    $sheet->mergeCells("E{$r}:F{$r}");
    $sheet->mergeCells("G{$r}:H{$r}");
    $r++;
    $sheet->setCellValue('A' . $r, $fichesApprouvees);
    $sheet->setCellValue('C' . $r, $fichesRefusees);
    $sheet->setCellValue('E' . $r, $fichesEnAttente);
    $sheet->setCellValue('G' . $r, $fichesConformes . ' / ' . $fichesOtp);
    $sheet->mergeCells("A{$r}:B{$r}");
    $sheet->mergeCells("C{$r}:D{$r}");
    $sheet->mergeCells("E{$r}:F{$r}");
    $sheet->mergeCells("G{$r}:H{$r}");
    $sheet->getStyle("A{$r}:H{$r}")->applyFromArray($kpiStyle);
    foreach (range('A', 'H') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    // --- Feuille Lignes devis ---
    $sheet2 = $xlsx->createSheet();
    $sheet2->setTitle('Lignes devis');
    $rr = 1;
    $sheet2->setCellValue('A' . $rr, 'Détail par ligne de devis');
    $sheet2->mergeCells("A{$rr}:I{$rr}");
    $sheet2->getStyle("A{$rr}:I{$rr}")->applyFromArray($titleStyle);
    $rr += 2;

    $headers = ['Désignation', 'Groupe', 'Quantité', 'Unité', 'Montant Devis (CFA)', 'Montant Déboursé (CFA)', 'Montant Payé (CFA)', 'Restant (CFA)', 'Progression (%)'];
    $cols = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I'];
    foreach ($headers as $i => $h) {
        $sheet2->setCellValue($cols[$i] . $rr, $h);
    }
    $sheet2->getStyle("A{$rr}:I{$rr}")->applyFromArray($headStyle);
    $rr++;

    foreach ($lignes as $ligne) {
        $prix = (float)($ligne['prix'] ?? 0);
        $budget = (float)($ligne['total'] ?? 0);
        $debourse = (float)($ligne['montant_debourse_reel'] ?? 0);
        $paye = (float)($ligne['montant_paye'] ?? 0);
        $restant = $debourse - $paye;
        $pct = $debourse > 0 ? ($paye / $debourse) * 100 : 0;

        $sheet2->setCellValue('A' . $rr, (string)($ligne['designation'] ?? ''));
        $sheet2->setCellValue('B' . $rr, (string)($ligne['groupe'] ?? ''));
        $sheet2->setCellValue('C' . $rr, (float)($ligne['quantite'] ?? 0));
        $sheet2->setCellValue('D' . $rr, (string)($ligne['unite_libelle'] ?? ''));
        $sheet2->setCellValue('E' . $rr, $prix);
        $sheet2->setCellValue('F' . $rr, $debourse);
        $sheet2->setCellValue('G' . $rr, $paye);
        $sheet2->setCellValue('H' . $rr, $restant);
        $sheet2->setCellValue('I' . $rr, round($pct, 1));
        $rr++;
    }

    $sheet2->getStyle("E3:H{$rr}")->getNumberFormat()->setFormatCode('#,##0');
    $sheet2->getStyle("I3:I{$rr}")->getNumberFormat()->setFormatCode('0.0');
    foreach ($cols as $c) $sheet2->getColumnDimension($c)->setAutoSize(true);
    $sheet2->getStyle("A1:I" . ($rr - 1))->applyFromArray($borderAll);
    $sheet2->freezePane('A4');
    $sheet2->setAutoFilter("A3:I" . ($rr - 1));

    // --- Feuille Fiches ---
    $sheet3 = $xlsx->createSheet();
    $sheet3->setTitle('Fiches');
    $rf = 1;
    $sheet3->setCellValue('A' . $rf, 'Fiches du chantier');
    $sheet3->mergeCells("A{$rf}:G{$rf}");
    $sheet3->getStyle("A{$rf}:G{$rf}")->applyFromArray($titleStyle);
    $rf += 2;

    $headersF = ['N° fiche', 'Bénéficiaire', 'Désignation', 'Précision', 'Montant (CFA)', 'Créée le', 'Statut'];
    $colsF = ['A', 'B', 'C', 'D', 'E', 'F', 'G'];
    foreach ($headersF as $i => $h) {
        $sheet3->setCellValue($colsF[$i] . $rf, $h);
    }
    $sheet3->getStyle("A{$rf}:G{$rf}")->applyFromArray($headStyle);
    $rf++;

    foreach ($fiches as $fiche) {
        $sheet3->setCellValue('A' . $rf, (string)($fiche['num_fiche'] ?? ''));
        $sheet3->setCellValue('B' . $rf, (string)($fiche['beficiaire_fiche'] ?? $fiche['beneficiaire_fiche'] ?? ''));
        $sheet3->setCellValue('C' . $rf, (string)($fiche['designation_libelle'] ?? $fiche['designation_fiche'] ?? ''));
        $sheet3->setCellValue('D' . $rf, (string)($fiche['precision_libelle'] ?? $fiche['precision_fiche'] ?? ''));
        $sheet3->setCellValue('E' . $rf, (float)($fiche['montant_fiche'] ?? 0));
        $sheet3->setCellValue('F' . $rf, !empty($fiche['date_creat_fiche']) ? date('Y-m-d', strtotime($fiche['date_creat_fiche'])) : '');
        $sheet3->setCellValue('G' . $rf, statutFiche($fiche));
        $rf++;
    }
    $sheet3->getStyle("E3:E{$rf}")->getNumberFormat()->setFormatCode('#,##0');
    foreach ($colsF as $c) $sheet3->getColumnDimension($c)->setAutoSize(true);
    $sheet3->getStyle("A1:G" . ($rf - 1))->applyFromArray($borderAll);
    $sheet3->freezePane('A4');
    $sheet3->setAutoFilter("A3:G" . ($rf - 1));

    $filename = 'detail_budgetaire_chantier_' . ($chantier['id'] ?? $chantier['id_chantier'] ?? 'X') . '_' . date('Ymd_His') . '.xlsx';
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    (new Xlsx($xlsx))->save('php://output');
    exit;
}

function exportPdfTcpdf(array $details): void
{
    $chantier = $details['chantier'] ?? [];
    $totaux = $details['totaux'] ?? [];
    $zones = $details['zones'] ?? [];
    $lignes = $details['lignes_devis'] ?? [];
    $fiches = $details['fiches'] ?? [];
    $stats = computeFicheStats($fiches, $details['stats_fiches'] ?? []);
    $zoneStats = computeZoneStats($zones, $lignes);
    $zoneChartItems = buildZoneProgressChartItems($zoneStats);

    class DetailChantierPDF extends TCPDF
    {
        public function Header()
        {
            $margins = $this->getMargins();
            $xLeft = $margins['left'];
            $xRight = $this->getPageWidth() - $margins['right'];

            $details = $GLOBALS['detailsChantierExport'] ?? [];
            $chantier = $details['chantier'] ?? [];

            $logoPath = '';
            $candidate = realpath(__DIR__ . '/../logo/default_logo.jpg');
            if ($candidate && is_file($candidate)) {
                $logoPath = $candidate;
            }

            $yTop = 10;
            $logoH = 16;
            if ($logoPath) {
                $this->Image($logoPath, $xLeft, $yTop, 0, $logoH, '', '', '', false, 300, '', false, false, 0, false, false, false);
            }

            $textX = $logoPath ? ($xLeft + 32) : $xLeft;
            $this->SetXY($textX, $yTop);
            $this->SetFont('dejavusans', 'B', 15);
            $this->SetTextColor(15, 23, 42);
            $this->Cell(0, 7, Utils::toMbConvertEncoding('Détail budgétaire'), 0, 1, 'L');

            $meta = [];
            $meta[] = 'Chantier : ' . (string)($chantier['lib_chantier'] ?? $chantier['titre'] ?? '');
            if (!empty($chantier['numero_devis'])) $meta[] = 'Devis : ' . (string)$chantier['numero_devis'];
            $clientNom = trim((string)($chantier['nom_client'] ?? ''));
            if ($clientNom !== '') $meta[] = 'Client : ' . $clientNom;
            $metaLine = implode('  •  ', array_filter($meta));

            $this->SetX($textX);
            $this->SetFont('dejavusans', '', 10);
            $this->SetTextColor(71, 85, 105);
            $this->Cell(0, 6, Utils::toMbConvertEncoding($metaLine), 0, 1, 'L');

            $yAfterText = $this->GetY();
            $yAfterLogo = $yTop + ($logoPath ? $logoH : 0);
            $ySep = max($yAfterText, $yAfterLogo) + 2;

            $this->SetDrawColor(226, 232, 240);
            $this->SetLineWidth(0.2);
            $this->Line($xLeft, $ySep, $xRight, $ySep);
        }

        public function Footer()
        {
            $this->SetY(-12);
            $this->SetFont('dejavusans', '', 8);
            $this->SetTextColor(100, 116, 139);
            $this->Cell(0, 8, Utils::toMbConvertEncoding('Page ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages()), 0, 0, 'C');
        }
    }

    $GLOBALS['detailsChantierExport'] = $details;

    $pdf = new DetailChantierPDF('L', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetMargins(10, 34, 10);
    $pdf->SetAutoPageBreak(true, 14);
    $pdf->AddPage('L');
    $pdf->SetFont('dejavusans', '', 10);
    $pdf->SetDrawColor(226, 232, 240);
    $pdf->SetLineWidth(0.15);

    $baseCss = <<<CSS
<style>
  .section-title { background-color:#f1f5f9; color:#0f172a; font-weight:bold; padding:6px 8px; border:1px solid #dbe3ec; }
  table.tbl { width:100%; border-collapse:collapse; }
  table.tbl th { background-color:#f3f4f6; color:#111827; font-weight:bold; padding:6px 6px; border:1px solid #d1d5db; }
  table.tbl td { padding:6px 6px; border:1px solid #e5e7eb; color:#111827; }
  .right { text-align:right; }
  .center { text-align:center; }
  .muted { color:#475569; }
</style>
CSS;

    //Totaux en haut (KPI cards)
    $budget = (float)($totaux['budget_total'] ?? 0);
    $paye = (float)($totaux['montant_paye'] ?? 0);
    $restant = (float)($totaux['montant_restant'] ?? 0);
    $pct = (float)($totaux['pourcentage_realise'] ?? 0);
    $totalFiches = (int)($stats['total_fiches'] ?? 0);
    $fichesDecaissees = (int)($stats['fiches_decaissees'] ?? 0);
    $fichesApprouvees = (int)($stats['fiches_approuvees'] ?? 0);
    $fichesRefusees = (int)($stats['fiches_refusees'] ?? 0);
    $fichesEnAttente = (int)($stats['fiches_en_attente'] ?? 0);
    $fichesConformes = (int)($stats['fiches_conformes'] ?? 0);
    $fichesOtp = (int)($stats['fiches_otp_validees'] ?? 0);

    $kpiHtml = $baseCss;
    $kpiHtml .= '<table cellpadding="8" cellspacing="0" style="width:100%; border:1px solid #e2e8f0; background-color:#f8fafc;">'
        . '<tr>'
        . '<td style="width:25%; border-right:1px solid #e2e8f0;">'
        . '<span class="muted">Budget total</span><br><span style="font-size:16px; font-weight:bold; color:#0f172a;">' . number_format($budget, 0, ',', ' ') . ' FCFA</span>'
        . '</td>'
        . '<td style="width:25%; border-right:1px solid #e2e8f0;">'
        . '<span class="muted">Payé</span><br><span style="font-size:16px; font-weight:bold; color:#16a34a;">' . number_format($paye, 0, ',', ' ') . ' FCFA</span>'
        . '</td>'
        . '<td style="width:25%; border-right:1px solid #e2e8f0;">'
        . '<span class="muted">Restant</span><br><span style="font-size:16px; font-weight:bold; color:#ea580c;">' . number_format($restant, 0, ',', ' ') . ' FCFA</span>'
        . '</td>'
        . '<td style="width:25%;">'
        . '<span class="muted">Progression</span><br><span style="font-size:16px; font-weight:bold; color:#7c3aed;">' . number_format($pct, 1, ',', ' ') . '%</span>'
        . '</td>'
        . '</tr>'
        . '</table>';
    $kpiHtml .= '<table cellpadding="8" cellspacing="0" style="width:100%; border:1px solid #e2e8f0; border-top:0; background-color:#ffffff;">'
        . '<tr>'
        . '<td style="width:25%; border-right:1px solid #e2e8f0;">'
        . '<span class="muted">Total fiches</span><br><span style="font-size:14px; font-weight:bold; color:#0f172a;">' . $totalFiches . '</span>'
        . '</td>'
        . '<td style="width:25%; border-right:1px solid #e2e8f0;">'
        . '<span class="muted">Décaissées</span><br><span style="font-size:14px; font-weight:bold; color:#0f172a;">' . $fichesDecaissees . '</span>'
        . '</td>'
        . '<td style="width:25%; border-right:1px solid #e2e8f0;">'
        . '<span class="muted">Approuvées</span><br><span style="font-size:14px; font-weight:bold; color:#0f172a;">' . $fichesApprouvees . '</span>'
        . '</td>'
        . '<td style="width:25%;">'
        . '<span class="muted">En attente</span><br><span style="font-size:14px; font-weight:bold; color:#0f172a;">' . $fichesEnAttente . '</span>'
        . '</td>'
        . '</tr>'
        . '</table>';
    $kpiHtml .= '<table cellpadding="8" cellspacing="0" style="width:100%; border:1px solid #e2e8f0; border-top:0; background-color:#ffffff;">'
        . '<tr>'
        . '<td style="width:33.33%; border-right:1px solid #e2e8f0;">'
        . '<span class="muted">Refusées</span><br><span style="font-size:14px; font-weight:bold; color:#0f172a;">' . $fichesRefusees . '</span>'
        . '</td>'
        . '<td style="width:33.33%; border-right:1px solid #e2e8f0;">'
        . '<span class="muted">Conformes</span><br><span style="font-size:14px; font-weight:bold; color:#0f172a;">' . $fichesConformes . '</span>'
        . '</td>'
        . '<td style="width:33.33%;">'
        . '<span class="muted">OTP validées</span><br><span style="font-size:14px; font-weight:bold; color:#0f172a;">' . $fichesOtp . '</span>'
        . '</td>'
        . '</tr>'
        . '</table>';
    $pdf->writeHTML($kpiHtml, true, false, true, false, '');
    $pdf->Ln(2);

    if (!empty($zoneStats)) {
        $zoneSummaryRows = '';
        $z = 0;
        foreach ($zoneStats as $zoneData) {
            $z++;
            $bg = ($z % 2 === 0) ? '#f8fafc' : '#ffffff';
            $progressionColor = (float)$zoneData['progression'] >= 100 ? '#16a34a' : ((float)$zoneData['progression'] >= 60 ? '#2563eb' : '#ea580c');
            $zoneSummaryRows .= '<tr style="background-color:' . $bg . '">' .
                '<td><strong>' . htmlspecialchars((string)$zoneData['zone'], ENT_QUOTES, 'UTF-8') . '</strong></td>' .
                '<td class="center">' . (int)$zoneData['line_count'] . '</td>' .
                '<td class="right">' . number_format((float)$zoneData['budget'], 0, ',', ' ') . '</td>' .
                '<td class="right">' . number_format((float)$zoneData['debourse'], 0, ',', ' ') . '</td>' .
                '<td class="right">' . number_format((float)$zoneData['paye'], 0, ',', ' ') . '</td>' .
                '<td class="right">' . number_format((float)$zoneData['restant'], 0, ',', ' ') . '</td>' .
                '<td class="right"><span style="font-weight:bold; color:' . $progressionColor . ';">' . number_format((float)$zoneData['progression'], 1, ',', ' ') . '%</span></td>' .
                '</tr>';
        }

        $zoneHtml = $baseCss;
        $zoneHtml .= '<div class="section-title">Synthèse par zone</div>';
        $zoneHtml .= '<table class="tbl">'
            . '<thead><tr>'
            . '<th>Zone</th><th>Lignes</th><th>Budget</th><th>Déboursé</th><th>Payé</th><th>Restant</th><th>Achèvement</th>'
            . '</tr></thead>'
            . '<tbody>' . $zoneSummaryRows . '</tbody>'
            . '</table>';
        $pdf->writeHTML($zoneHtml, true, false, true, false, '');
        $pdf->Ln(2);
    }

    // Section lignes devis regroupée par zone
    if (!empty($zoneStats)) {
        foreach ($zoneStats as $zoneData) {
            $rows = '';
            $z = 0;
            foreach ($zoneData['lines'] as $ligne) {
                $z++;
                $bg = ($z % 2 === 0) ? '#f8fafc' : '#ffffff';
                $prix = (float)($ligne['prix'] ?? 0);
                $budgetL = (float)($ligne['total'] ?? 0);
                $deborseL = (float)($ligne['montant_debourse_reel'] ?? 0);
                $payeL = (float)($ligne['montant_paye'] ?? 0);
                $referenceL = $deborseL > 0 ? $deborseL : $budgetL;
                $restantL = max(0, $referenceL - $payeL);
                $pctL = $referenceL > 0 ? ($payeL / $referenceL) * 100 : 0;

                $rows .= '<tr style="background-color:' . $bg . '">' .
                    '<td>' . htmlspecialchars((string)($ligne['designation'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>' .
                    '<td>' . htmlspecialchars((string)($ligne['groupe'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>' .
                    '<td class="center">' . htmlspecialchars((string)($ligne['unite_libelle'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>' .
                    '<td class="right">' . number_format((float)($ligne['quantite'] ?? 0), 2, ',', ' ') . '</td>' .
                    '<td class="right">' . number_format($budgetL, 0, ',', ' ') . '</td>' .
                    '<td class="right">' . number_format($deborseL, 0, ',', ' ') . '</td>' .
                    '<td class="right">' . number_format($payeL, 0, ',', ' ') . '</td>' .
                    '<td class="right">' . number_format($restantL, 0, ',', ' ') . '</td>' .
                    '<td class="right">' . number_format($pctL, 1, ',', ' ') . '%</td>' .
                    '</tr>';
            }

            $html = $baseCss;
            $html .= '<div class="section-title">Zone : ' . htmlspecialchars((string)$zoneData['zone'], ENT_QUOTES, 'UTF-8') . '</div>';
            $html .= '<table class="tbl">'
                . '<thead><tr>'
                . '<th>Désignation</th><th>Groupe</th><th>Unité</th><th>Qté</th><th>Budget</th><th>Déboursé</th><th>Payé</th><th>Restant</th><th>Achèvement</th>'
                . '</tr></thead>'
                . '<tbody>' . $rows . '</tbody>'
                . '</table>';
            $pdf->writeHTML($html, true, false, true, false, '');
            $pdf->Ln(2);
        }
    } elseif (!empty($lignes)) {
        $rows = '';
        $z = 0;
        foreach ($lignes as $ligne) {
            $z++;
            $bg = ($z % 2 === 0) ? '#f8fafc' : '#ffffff';
            $prix = (float)($ligne['prix'] ?? 0);
            $budgetL = (float)($ligne['total'] ?? 0);
            $deborseL = (float)($ligne['montant_debourse_reel'] ?? 0);
            $payeL = (float)($ligne['montant_paye'] ?? 0);
            $referenceL = $deborseL > 0 ? $deborseL : $budgetL;
            $restantL = max(0, $referenceL - $payeL);
            $pctL = $referenceL > 0 ? ($payeL / $referenceL) * 100 : 0;

            $rows .= '<tr style="background-color:' . $bg . '">' .
                '<td>' . htmlspecialchars((string)($ligne['designation'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>' .
                '<td class="right">' . number_format($prix, 0, ',', ' ') . '</td>' .
                '<td class="right">' . number_format($deborseL, 0, ',', ' ') . '</td>' .
                '<td class="right">' . number_format($payeL, 0, ',', ' ') . '</td>' .
                '<td class="right">' . number_format($restantL, 0, ',', ' ') . '</td>' .
                '<td class="right">' . number_format($pctL, 1, ',', ' ') . '%</td>' .
                '</tr>';
        }

        $html = $baseCss;
        $html .= '<div class="section-title">Détail par ligne de devis</div>';
        $html .= '<table class="tbl">'
            . '<thead><tr>'
            . '<th>Désignation</th><th>Montant Devis</th><th>Montant Débourré</th><th>Montant Payé</th><th>Restant</th><th>Progression</th>'
            . '</tr></thead>'
            . '<tbody>' . $rows . '</tbody>'
            . '</table>';
        $pdf->writeHTML($html, true, false, true, false, '');
        $pdf->Ln(2);
    }

    //Section fiches
    $rowsF = '';
    $z = 0;
    foreach ($fiches as $fiche) {
        $z++;
        $bg = ($z % 2 === 0) ? '#f8fafc' : '#ffffff';
        $rowsF .= '<tr style="background-color:' . $bg . '">' .
            '<td>' . htmlspecialchars((string)($fiche['num_fiche'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>' .
            '<td>' . htmlspecialchars((string)($fiche['beficiaire_fiche'] ?? $fiche['beneficiaire_fiche'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>' .
            '<td>' . htmlspecialchars((string)($fiche['designation_libelle'] ?? $fiche['designation_fiche'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>' .
            '<td class="right">' . number_format((float)($fiche['montant_fiche'] ?? 0), 0, ',', ' ') . '</td>' .
            '<td class="center">' . (!empty($fiche['date_creat_fiche']) ? htmlspecialchars(date('d/m/Y', strtotime($fiche['date_creat_fiche'])), ENT_QUOTES, 'UTF-8') : '-') . '</td>' .
            '<td class="center">' . htmlspecialchars(statutFiche($fiche), ENT_QUOTES, 'UTF-8') . '</td>' .
            '</tr>';
    }

    $htmlF = $baseCss;
    $htmlF .= '<div class="section-title">Fiches du chantier</div>';
    $htmlF .= '<table class="tbl">'
        . '<thead><tr>'
        . '<th>N° fiche</th><th>Bénéficiaire</th><th>Désignation</th><th>Montant</th><th>Date</th><th>Statut</th>'
        . '</tr></thead>'
        . '<tbody>' . $rowsF . '</tbody>'
        . '</table>';
    $pdf->writeHTML($htmlF, true, false, true, false, '');

    // Page suivante : Graphiques
    $pdf->AddPage('L');
    $pdf->SetFont('dejavusans', 'B', 13);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell(0, 8, Utils::toMbConvertEncoding('Graphiques'), 0, 1, 'L');
    $pdf->SetFont('dejavusans', '', 9);
    $pdf->SetTextColor(71, 85, 105);
    $pdf->Cell(0, 6, Utils::toMbConvertEncoding('Synthèse visuelle des montants et des statuts de fiches'), 0, 1, 'L');
    $pdf->Ln(2);

    $m = $pdf->getMargins();
    $x = $m['left'];
    $y = $pdf->GetY();
    $pageW = $pdf->getPageWidth() - $m['left'] - $m['right'];

    $boxW = ($pageW - 8) / 2;
    $boxH = 78;

    // Chart 1: Montants
    $bottomY = drawPdfBarChart(
        $pdf,
        $x,
        $y,
        $boxW,
        $boxH,
        'Montants (CFA)',
        [
            ['label' => 'Budget total', 'value' => $budget, 'rgb' => [59, 130, 246]],
            ['label' => 'Payé', 'value' => $paye, 'rgb' => [34, 197, 94]],
            ['label' => 'Restant', 'value' => $restant, 'rgb' => [249, 115, 22]],
        ],
        function ($v) {
            return number_format((float)$v, 0, ',', ' ');
        }
    );

    // Chart 2: Statuts fiches
    $bottomY = max($bottomY, drawPdfBarChart(
        $pdf,
        $x + $boxW + 8,
        $y,
        $boxW,
        $boxH,
        'Statuts fiches (quantités)',
        [
            ['label' => 'Décaissées', 'value' => $fichesDecaissees, 'rgb' => [34, 197, 94]],
            ['label' => 'Approuvées', 'value' => $fichesApprouvees, 'rgb' => [59, 130, 246]],
            ['label' => 'En attente', 'value' => $fichesEnAttente, 'rgb' => [234, 179, 8]],
            ['label' => 'Refusées', 'value' => $fichesRefusees, 'rgb' => [239, 68, 68]],
            ['label' => 'Conformes', 'value' => $fichesConformes, 'rgb' => [124, 58, 237]],
            ['label' => 'OTP validées', 'value' => $fichesOtp, 'rgb' => [20, 184, 166]],
        ],
        function ($v) {
            return (string)(int)$v;
        }
    ));

    if (!empty($zoneChartItems)) {
        $y2 = $bottomY + 6;
        if ($y2 + 92 > ($pdf->getPageHeight() - 18)) {
            $pdf->AddPage('L');
            $m = $pdf->getMargins();
            $x = $m['left'];
            $pageW = $pdf->getPageWidth() - $m['left'] - $m['right'];
            $y2 = $pdf->GetY();
        }

        $bottomY = drawPdfBarChart(
            $pdf,
            $x,
            $y2,
            $pageW,
            92,
            'Achèvement par zone (%)',
            $zoneChartItems,
            function ($v) {
                return number_format((float)$v, 1, ',', ' ') . '%';
            }
        );
    }

    // Chart complémentaire : top zones par montant payé
    if (!empty($zoneStats)) {
        $zonePaidItems = [];
        foreach ($zoneStats as $zoneData) {
            $zonePaidItems[] = [
                'label' => (string)$zoneData['zone'],
                'value' => (float)$zoneData['paye'],
                'rgb' => [34, 197, 94],
            ];
        }
        usort($zonePaidItems, function ($left, $right) {
            $lv = (float)($left['value'] ?? 0);
            $rv = (float)($right['value'] ?? 0);
            if ($lv === $rv) {
                return strcmp((string)$left['label'], (string)$right['label']);
            }
            return $lv < $rv ? 1 : -1;
        });
        $zonePaidItems = array_slice($zonePaidItems, 0, 10);

        if (!empty($zonePaidItems)) {
            $y3 = $bottomY + 6;
            if ($y3 + 92 > ($pdf->getPageHeight() - 18)) {
                $pdf->AddPage('L');
                $m = $pdf->getMargins();
                $x = $m['left'];
                $pageW = $pdf->getPageWidth() - $m['left'] - $m['right'];
                $y3 = $pdf->GetY();
            }

            drawPdfBarChart(
                $pdf,
                $x,
                $y3,
                $pageW,
                92,
                'Montants payés par zone (CFA)',
                $zonePaidItems,
                function ($v) {
                    return number_format((float)$v, 0, ',', ' ');
                }
            );
        }
    }

    $filename = 'detail_budgetaire_chantier_' . ($chantier['id'] ?? $chantier['id_chantier'] ?? 'X') . '_' . date('Y-m-d') . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    $pdf->Output($filename, 'I');
    exit;
}

function renderPrintHtml(array $details): void
{
    $chantier = $details['chantier'] ?? [];
    $totaux = $details['totaux'] ?? [];
    $lignes = $details['lignes_devis'] ?? [];
    $fiches = $details['fiches'] ?? [];
    $stats = computeFicheStats($fiches, $details['stats_fiches'] ?? []);

    $logoUrl = '../logo/default_logo.jpg';
    $title = 'Détail budgétaire';

    header('Content-Type: text/html; charset=utf-8');
    $budget = (float)($totaux['budget_total'] ?? 0);
    $paye = (float)($totaux['montant_paye'] ?? 0);
    $restant = (float)($totaux['montant_restant'] ?? 0);
    $pct = (float)($totaux['pourcentage_realise'] ?? 0);
    $totalFiches = (int)($stats['total_fiches'] ?? 0);
    $fichesDecaissees = (int)($stats['fiches_decaissees'] ?? 0);
    $fichesApprouvees = (int)($stats['fiches_approuvees'] ?? 0);
    $fichesRefusees = (int)($stats['fiches_refusees'] ?? 0);
    $fichesEnAttente = (int)($stats['fiches_en_attente'] ?? 0);
    $fichesConformes = (int)($stats['fiches_conformes'] ?? 0);
    $fichesOtp = (int)($stats['fiches_otp_validees'] ?? 0);

    $chantierNom = chantierLabel($details);
    $clientNom = clientLabel($details);
    $devisNum = (string)($chantier['numero_devis'] ?? '');

    $dateExport = date('d/m/Y H:i');

    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>';
    echo '<style>
        :root{ --slate-50:#f8fafc; --slate-100:#f1f5f9; --slate-200:#e2e8f0; --slate-600:#475569; --slate-900:#0f172a; --green-50:#ecfdf5; --green-200:#bbf7d0; }
        body{ font-family: Arial, Helvetica, sans-serif; color: var(--slate-900); margin: 18px; }
        .header{ display:flex; gap:14px; align-items:center; border-bottom:1px solid var(--slate-200); padding-bottom:10px; }
        .logo{ height:46px; }
        .h1{ font-size:20px; font-weight:700; margin:0; }
        .meta{ margin-top:4px; color: var(--slate-600); font-size:13px; }
        .kpis{ display:grid; grid-template-columns: repeat(4, 1fr); gap:10px; margin:12px 0; }
        .kpi{ background: var(--slate-50); border:1px solid var(--slate-200); border-radius:10px; padding:10px 12px; }
        .kpi .label{ color: var(--slate-600); font-size:12px; }
        .kpi .value{ font-size:18px; font-weight:800; margin-top:4px; }
        .kpi .value.green{ color:#16a34a; }
        .kpi .value.orange{ color:#ea580c; }
        .kpi .value.purple{ color:#7c3aed; }
        .kpis2{ display:grid; grid-template-columns: repeat(2, 1fr); gap:10px; margin-top:10px; }
        .section{ margin-top:14px; }
        .section-title{ background: var(--slate-100); border:1px solid var(--slate-200); border-radius:8px; padding:8px 10px; font-weight:700; }
        table{ width:100%; border-collapse:collapse; margin-top:8px; }
        th{ background:#f3f4f6; border:1px solid #d1d5db; padding:8px 8px; text-align:left; font-size:12px; }
        td{ border:1px solid #e5e7eb; padding:7px 8px; font-size:12px; vertical-align:top; }
        tr:nth-child(even) td{ background: var(--slate-50); }
        .right{ text-align:right; }
        .center{ text-align:center; }
        .pill{ display:inline-block; padding:2px 8px; border-radius:999px; font-size:11px; border:1px solid var(--slate-200); background:#fff; }
        .pill.ok{ background:var(--green-50); border-color:var(--green-200); }
        .muted{ color: var(--slate-600); }
        .no-print{ margin-top:12px; }
                .chart{ background:#fff; border:1px solid var(--slate-200); border-radius:10px; padding:10px 12px; }
                .chart-title{ font-weight:800; margin:0 0 8px 0; }
                .barrow{ display:grid; grid-template-columns: 180px 1fr 90px; gap:10px; align-items:center; margin:6px 0; }
                .bar{ height:8px; background:var(--slate-100); border-radius:999px; overflow:hidden; border:1px solid var(--slate-200); }
                .bar > i{ display:block; height:100%; background:rgba(59,130,246,.35); }
                .bar.green > i{ background:rgba(34,197,94,.35); }
                .bar.orange > i{ background:rgba(249,115,22,.35); }
                .bar.red > i{ background:rgba(239,68,68,.35); }
                .bar.yellow > i{ background:rgba(234,179,8,.35); }
                .bar.purple > i{ background:rgba(124,58,237,.35); }
                .bar.teal > i{ background:rgba(20,184,166,.35); }
                .page-break{ page-break-before: always; margin-top:18px; }
                @media print{
          .no-print{ display:none; }
          body{ margin: 8mm; }
        }
    </style></head><body>';

    echo '<div class="header">'
        . '<img class="logo" src="' . htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') . '" alt="Logo">'
        . '<div>'
        . '<p class="h1">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<div class="meta">'
        . htmlspecialchars("Chantier : {$chantierNom}", ENT_QUOTES, 'UTF-8')
        . ($devisNum ? '  •  ' . htmlspecialchars("Devis : {$devisNum}", ENT_QUOTES, 'UTF-8') : '')
        . ($clientNom ? '  •  ' . htmlspecialchars("Client : {$clientNom}", ENT_QUOTES, 'UTF-8') : '')
        . '</div>'
        . '<div class="meta">' . htmlspecialchars("Export : {$dateExport}", ENT_QUOTES, 'UTF-8') . '</div>'
        . '</div></div>';

    echo '<div class="kpis">'
        . '<div class="kpi"><div class="label">Budget total</div><div class="value">' . number_format($budget, 0, ',', ' ') . ' FCFA</div></div>'
        . '<div class="kpi"><div class="label">Payé</div><div class="value green">' . number_format($paye, 0, ',', ' ') . ' FCFA</div></div>'
        . '<div class="kpi"><div class="label">Restant</div><div class="value orange">' . number_format($restant, 0, ',', ' ') . ' FCFA</div></div>'
        . '<div class="kpi"><div class="label">Progression</div><div class="value purple">' . number_format($pct, 1, ',', ' ') . '%</div></div>'
        . '</div>'
        . '<div class="kpis2">'
        . '<div class="kpi"><div class="label">Total fiches</div><div class="value">' . (int)$totalFiches . '</div></div>'
        . '<div class="kpi"><div class="label">Fiches décaissées</div><div class="value">' . (int)$fichesDecaissees . '</div></div>'
        . '</div>';

    echo '<div class="kpis" style="grid-template-columns:repeat(4,1fr); margin-top:10px;">'
        . '<div class="kpi"><div class="label">Approuvées</div><div class="value">' . (int)$fichesApprouvees . '</div></div>'
        . '<div class="kpi"><div class="label">Refusées</div><div class="value">' . (int)$fichesRefusees . '</div></div>'
        . '<div class="kpi"><div class="label">En attente</div><div class="value">' . (int)$fichesEnAttente . '</div></div>'
        . '<div class="kpi"><div class="label">Conformes / OTP</div><div class="value">' . (int)$fichesConformes . ' / ' . (int)$fichesOtp . '</div></div>'
        . '</div>';

    if (!empty($lignes)) {
        echo '<div class="section">'
            . '<div class="section-title">Détail par ligne de devis</div>'
            . '<table><thead><tr>'
            . '<th>Désignation</th><th class="center">Unité</th><th class="right">Qté</th><th class="right">PU</th><th class="right">Budget</th><th class="right">Payé</th><th class="right">Restant</th><th class="right">%</th>'
            . '</tr></thead><tbody>';
        foreach ($lignes as $ligne) {
            $budgetL = (float)($ligne['total'] ?? 0);
            $payeL = (float)($ligne['montant_paye'] ?? 0);
            $restantL = $budgetL - $payeL;
            $pctL = $budgetL > 0 ? ($payeL / $budgetL) * 100 : 0;
            echo '<tr>'
                . '<td>' . htmlspecialchars((string)($ligne['designation'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td class="center">' . htmlspecialchars((string)($ligne['unite_libelle'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td class="right">' . number_format((float)($ligne['quantite'] ?? 0), 2, ',', ' ') . '</td>'
                . '<td class="right">' . number_format((float)($ligne['prix'] ?? 0), 0, ',', ' ') . '</td>'
                . '<td class="right">' . number_format($budgetL, 0, ',', ' ') . '</td>'
                . '<td class="right">' . number_format($payeL, 0, ',', ' ') . '</td>'
                . '<td class="right">' . number_format($restantL, 0, ',', ' ') . '</td>'
                . '<td class="right">' . number_format($pctL, 1, ',', ' ') . '%</td>'
                . '</tr>';
        }
        echo '</tbody></table></div>';
    }

    echo '<div class="section">'
        . '<div class="section-title">Fiches du chantier</div>'
        . '<table><thead><tr>'
        . '<th>N° fiche</th><th>Bénéficiaire</th><th>Désignation</th><th class="right">Montant</th><th class="center">Date</th><th class="center">Statut</th>'
        . '</tr></thead><tbody>';
    foreach ($fiches as $fiche) {
        $st = statutFiche($fiche);
        $pillClass = $st === 'Décaissé' ? 'pill ok' : 'pill';
        echo '<tr>'
            . '<td>' . htmlspecialchars((string)($fiche['num_fiche'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td>' . htmlspecialchars((string)($fiche['beficiaire_fiche'] ?? $fiche['beneficiaire_fiche'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td>' . htmlspecialchars((string)($fiche['designation_libelle'] ?? $fiche['designation_fiche'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td class="right">' . number_format((float)($fiche['montant_fiche'] ?? 0), 0, ',', ' ') . '</td>'
            . '<td class="center">' . (!empty($fiche['date_creat_fiche']) ? htmlspecialchars(date('d/m/Y', strtotime($fiche['date_creat_fiche'])), ENT_QUOTES, 'UTF-8') : '-') . '</td>'
            . '<td class="center"><span class="' . $pillClass . '">' . htmlspecialchars($st, ENT_QUOTES, 'UTF-8') . '</span></td>'
            . '</tr>';
    }
    echo '</tbody></table></div>';

    // Page suivante: Graphiques
    echo '<div class="page-break"></div>';
    echo '<div class="section">'
        . '<div class="section-title">Graphiques</div>'
        . '<div class="chart" style="margin-top:10px;">'
        . '<p class="chart-title">Montants (CFA)</p>';
    $maxAmount = max(1, $budget, $paye, $restant);
    $mkBar = function ($label, $value, $cls) use ($maxAmount) {
        $pct = min(100, max(0, ($value / $maxAmount) * 100));
        return '<div class="barrow">'
            . '<div class="muted">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</div>'
            . '<div class="bar ' . $cls . '"><i style="width:' . number_format($pct, 2, '.', '') . '%"></i></div>'
            . '<div class="right"><b>' . number_format($value, 0, ',', ' ') . '</b></div>'
            . '</div>';
    };
    echo $mkBar('Budget total', $budget, '');
    echo $mkBar('Payé', $paye, 'green');
    echo $mkBar('Restant', $restant, 'orange');
    echo '</div>';

    echo '<div class="chart" style="margin-top:10px;">'
        . '<p class="chart-title">Statuts fiches (quantités)</p>';
    $maxCount = max(1, $fichesDecaissees, $fichesApprouvees, $fichesEnAttente, $fichesRefusees, $fichesConformes, $fichesOtp);
    $mkBar2 = function ($label, $value, $cls) use ($maxCount) {
        $pct = min(100, max(0, ($value / $maxCount) * 100));
        return '<div class="barrow">'
            . '<div class="muted">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</div>'
            . '<div class="bar ' . $cls . '"><i style="width:' . number_format($pct, 2, '.', '') . '%"></i></div>'
            . '<div class="right"><b>' . (int)$value . '</b></div>'
            . '</div>';
    };
    echo $mkBar2('Décaissées', $fichesDecaissees, 'green');
    echo $mkBar2('Approuvées', $fichesApprouvees, '');
    echo $mkBar2('En attente', $fichesEnAttente, 'yellow');
    echo $mkBar2('Refusées', $fichesRefusees, 'red');
    echo $mkBar2('Conformes', $fichesConformes, 'purple');
    echo $mkBar2('OTP validées', $fichesOtp, 'teal');
    echo '</div>';

    if (!empty($lignes)) {
        $top = [];
        foreach ($lignes as $l) {
            $b = (float)($l['total'] ?? 0);
            if ($b <= 0) continue;
            $top[] = ['designation' => (string)($l['designation'] ?? ''), 'budget' => $b];
        }
        usort($top, function ($a, $b) {
            $av = (float)($a['budget'] ?? 0);
            $bv = (float)($b['budget'] ?? 0);
            if ($av === $bv) return 0;
            return ($av < $bv) ? 1 : -1;
        });
        $top = array_slice($top, 0, 10);
        if (!empty($top)) {
            $maxTop = max(1, ...array_map(function ($x) {
                return (float)($x['budget'] ?? 0);
            }, $top));
            echo '<div class="chart" style="margin-top:10px;">'
                . '<p class="chart-title">Top lignes de devis (budget)</p>';
            foreach ($top as $t) {
                $label = mb_strlen($t['designation']) > 40 ? (mb_substr($t['designation'], 0, 37) . '…') : $t['designation'];
                $pct = min(100, max(0, ($t['budget'] / $maxTop) * 100));
                echo '<div class="barrow">'
                    . '<div class="muted">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</div>'
                    . '<div class="bar"><i style="width:' . number_format($pct, 2, '.', '') . '%"></i></div>'
                    . '<div class="right"><b>' . number_format((float)$t['budget'], 0, ',', ' ') . '</b></div>'
                    . '</div>';
            }
            echo '</div>';
        }
    }
    echo '</div>';

    echo '<div class="no-print"><button onclick="window.print()">Imprimer</button></div>';
    echo '<script>setTimeout(()=>window.print(), 200);</script>';
    echo '</body></html>';
    exit;
}
