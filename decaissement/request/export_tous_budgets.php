<?php
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/Dashboard.php';
require_once __DIR__ . '/../includes/budget_summary_helper.php';
require_once __DIR__ . '/../../model/AuditTrail.php';

require_once __DIR__ . '/../../model/CompanyPdfTemplate.php';
require_once __DIR__ . '/../../model/CompanyExportBranding.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class PDFBudgetsChantiers extends TCPDF
{
    use CompanyPdfTemplate;
    public function Header(){ $this->drawCompanyPdfHeader(); }
    public function Footer(){ $this->drawCompanyPdfFooter(); }
}

function clean_str($v): string
{
    return trim((string)$v);
}

function get_filters_from_get(): array
{
    return [
        'chantier_id' => isset($_GET['chantier_id']) && trim((string)$_GET['chantier_id']) !== '' ? (int)$_GET['chantier_id'] : null,
        'chantier' => isset($_GET['chantier']) ? trim((string)$_GET['chantier']) : '',
        'client' => isset($_GET['client']) ? trim((string)$_GET['client']) : '',
        'entreprise' => isset($_GET['entreprise']) ? trim((string)$_GET['entreprise']) : '',
        'devis' => isset($_GET['devis']) ? trim((string)$_GET['devis']) : '',
        'statut' => isset($_GET['statut']) ? trim((string)$_GET['statut']) : '',
        'archives_scope' => isset($_GET['archives_scope']) ? trim((string)$_GET['archives_scope']) : 'active',
    ];
}

function build_filter_lines(array $filters): array
{
    $lines = [];

    if (!empty($filters['chantier_id'])) {
        $lines[] = 'Chantier : #' . (string)$filters['chantier_id'];
    } elseif (!empty($filters['chantier'])) {
        $lines[] = 'Chantier : ' . (string)$filters['chantier'];
    }

    if (!empty($filters['client'])) {
        $lines[] = 'Client : ' . (string)$filters['client'];
    }
    if (!empty($filters['entreprise'])) {
        $lines[] = 'Entreprise : ' . (string)$filters['entreprise'];
    }
    if (!empty($filters['devis'])) {
        $lines[] = 'Devis : ' . (string)$filters['devis'];
    }
    if (!empty($filters['statut'])) {
        $label = (string)$filters['statut'];
        if ($label === 'normal') $label = 'Normal (≤ 70%)';
        if ($label === 'attention') $label = 'Attention (70–90%)';
        if ($label === 'critique') $label = 'Critique (> 90%)';
        $lines[] = 'Statut : ' . $label;
    }
    if (!empty($filters['archives_scope'])) {
        $scopeLabel = (string)$filters['archives_scope'];
        if ($scopeLabel === 'active') $scopeLabel = 'Actifs';
        if ($scopeLabel === 'archive') $scopeLabel = 'Archives';
        if ($scopeLabel === 'all') $scopeLabel = 'Actifs + archives';
        $lines[] = 'Portée : ' . $scopeLabel;
    }

    $lines[] = 'Date export : ' . date('d/m/Y H:i');
    return $lines;
}

function compute_status(float $pct): array
{
    if ($pct > 90) {
        return ['key' => 'CRITIQUE', 'color' => [185, 28, 28]];
    }
    if ($pct > 70) {
        return ['key' => 'ATTENTION', 'color' => [161, 98, 7]];
    }
    return ['key' => 'NORMAL', 'color' => [21, 128, 61]];
}

try {
    $format = isset($_GET['format']) ? strtolower(trim((string)$_GET['format'])) : 'excel';
    $report = isset($_GET['report']) ? strtolower(trim((string)$_GET['report'])) : 'detail';
    if (!in_array($format, ['excel', 'pdf'], true)) {
        $format = 'excel';
    }
    if (!in_array($report, ['detail', 'summary'], true)) {
        $report = 'detail';
    }

    $pdo = Database::getConnection();
    $dashboard = new Dashboard($pdo);

    $filters = get_filters_from_get();
    $rows = $dashboard->getTousLesChantiersBudgets($filters);
    $summary = budget_summary_compute($rows);

    $data = [];
    $sumBudget = 0.0;
    $sumDepense = 0.0;
    $sumResteReel = 0.0;
    $sumMargeDevis = 0.0;

    foreach ($rows as $r) {
        $budget = (float)($r['budget_interne_calc'] ?? $r['budget_total'] ?? 0);
        $budgetDevis = (float)($r['budget_devis_total'] ?? $r['budget_total'] ?? 0);
        $dep = (float)($r['total_depenses'] ?? 0);
        $resteReel = $budget - $dep;
        $margeDevis = $budgetDevis - $dep;
        $pct = $budget > 0 ? ($dep / max($budget, 1.0)) * 100.0 : 0.0;
        $pctDevis = $budgetDevis > 0 ? ($dep / max($budgetDevis, 1.0)) * 100.0 : 0.0;

        $sumBudget += $budget;
        $sumDepense += $dep;
        $sumResteReel += $resteReel;
        $sumMargeDevis += $margeDevis;

        $status = compute_status($pct);

        $data[] = [
            'num_chantier' => clean_str($r['num_chantier'] ?? ''),
            'chantier' => clean_str($r['lib_chantier'] ?? ''),
            'client' => clean_str($r['nom_client'] ?? ''),
            'entreprise' => clean_str($r['entreprise'] ?? ''),
            'devis' => clean_str($r['numero_devis'] ?? ''),
            'date' => !empty($r['date_creation']) ? date('d/m/Y', strtotime((string)$r['date_creation'])) : '',
            'budget' => $budget,
            'budget_devis' => $budgetDevis,
            'depense' => $dep,
            'reste_reel' => $resteReel,
            'marge_devis' => $margeDevis,
            'pct' => $pct,
            'pct_devis' => $pctDevis,
            'fiches' => (int)($r['nombre_fiches'] ?? 0),
            'statut' => $status['key'],
        ];
    }

    $summaryRows = [
        $summary['global'],
        $summary['active'],
        $summary['archive'],
    ];

    if ($format === 'excel') {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()->setCreator(CompanyExportBranding::creator())->setCompany((string)CompanyBranding::get()['company_name']);
        $sheet = $spreadsheet->getActiveSheet();

        if ($report === 'summary') {
            $row = 1;
            $sheet->mergeCells("A$row:I$row");
            $sheet->setCellValue("A$row", 'Synthèse budgets chantiers');
            $sheet->getStyle("A$row")->getFont()->setBold(true)->setSize(16)->getColor()->setRGB('1F2937');
            $sheet->getStyle("A$row")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("A$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F1F5F9');
            $row++;

            $filterLines = build_filter_lines($filters);
            $sheet->mergeCells("A$row:I$row");
            $sheet->setCellValue("A$row", implode('   |   ', $filterLines));
            $sheet->getStyle("A$row")->getFont()->setItalic(true)->setSize(10)->getColor()->setRGB('4B5563');
            $row += 2;

            $headers = ['Périmètre', 'Chantiers', 'Budget réel (F)', 'Devis (F)', 'Dépensé réel (F)', 'Restant budget réel (F)', 'Marge sur devis (F)', 'Fiches', '% consommation interne', '% consommation devis'];
            $sheet->fromArray($headers, null, "A$row");
            $sheet->getStyle("A$row:J$row")->getFont()->setBold(true)->getColor()->setRGB(CompanyExportBranding::rgb('primary',true));
            $sheet->getStyle("A$row:J$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(CompanyExportBranding::rgb('primary'));
            $row++;

            $startDataRow = $row;
            foreach ($summaryRows as $summaryRow) {
                $sheet->fromArray([
                    $summaryRow['label'],
                    $summaryRow['count'],
                    $summaryRow['budget_reel'],
                    $summaryRow['budget_devis'],
                    $summaryRow['depenses'],
                    $summaryRow['restant_reel'],
                    $summaryRow['marge_devis'],
                    $summaryRow['fiches'],
                    round((float)$summaryRow['progression_reel'], 1),
                    round((float)$summaryRow['progression_devis'], 1),
                ], null, "A$row");
                $sheet->getStyle("B$row")->getNumberFormat()->setFormatCode('#,##0');
                $sheet->getStyle("C$row:G$row")->getNumberFormat()->setFormatCode('#,##0');
                $sheet->getStyle("H$row")->getNumberFormat()->setFormatCode('#,##0');
                $sheet->getStyle("I$row:J$row")->getNumberFormat()->setFormatCode('0.0');
                $row++;
            }

            $row++;
            $sheet->mergeCells("A$row:J$row");
            $sheet->setCellValue("A$row", 'Indicateurs de lecture');
            $sheet->getStyle("A$row")->getFont()->setBold(true);
            $row++;
            $alerts = [
                ['Chantiers archivés dans le résultat', $summary['archived_count']],
                ['Dépassement cumulé global (F)', $summary['global']['depassement']],
                ['Chantiers sans budget réel avec dépense', $summary['global']['no_budget_with_depense_count']],
                ['Montant dépensé sans budget réel (F)', $summary['global']['no_budget_with_depense_total']],
            ];
            foreach ($alerts as $alert) {
                $sheet->setCellValue("A$row", $alert[0]);
                $sheet->setCellValue("B$row", $alert[1]);
                $row++;
            }

            foreach (range('A', 'J') as $col) {
                $sheet->getColumnDimension($col)->setAutoSize(true);
            }

            $fileName = 'synthese_budgets_chantiers_' . date('Ymd_His') . '.xlsx';
            \AuditTrail::logFileEvent('export', [
                'module' => 'decaissement',
                'action' => 'budgets_chantiers_summary_export',
                'entity_type' => 'budget_chantiers',
                'entity_label' => 'Synthèse budgets chantiers',
                'format' => 'excel',
                'details' => [
                    'filters' => $filters,
                    'row_count' => is_countable($rows ?? null) ? count($rows) : null,
                    'archived_count' => $summary['archived_count'],
                ],
            ], $pdo);
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment;filename="' . $fileName . '"');
            header('Cache-Control: max-age=0');
            (new Xlsx($spreadsheet))->save('php://output');
            exit;
        }

        $row = 1;
        $sheet->mergeCells("A$row:N$row");
        $sheet->setCellValue("A$row", 'Budgets détaillés des chantiers');
        $sheet->getStyle("A$row")->getFont()->setBold(true)->setSize(16)->getColor()->setRGB('1F2937');
        $sheet->getStyle("A$row")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("A$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F1F5F9');
        $row++;

        $filterLines = build_filter_lines($filters);
        if ($filterLines) {
            $sheet->mergeCells("A$row:N$row");
            $sheet->setCellValue("A$row", implode('   |   ', $filterLines));
            $sheet->getStyle("A$row")->getFont()->setItalic(true)->setSize(10)->getColor()->setRGB('4B5563');
            $row++;
        }

        $row++;

        $headers = ['N° Chantier', 'Chantier', 'Client', 'Entreprise', 'N° Devis', 'Date', 'Budget réel (F)', 'Devis (F)', 'Dépensé réel (F)', 'Restant budget réel (F)', 'Marge sur devis (F)', '% consommation interne', '% consommation devis', 'Fiches', 'Statut'];
        $sheet->fromArray($headers, null, "A$row");
        $sheet->getStyle("A$row:O$row")->getFont()->setBold(true)->getColor()->setRGB(CompanyExportBranding::rgb('primary',true));
        $sheet->getStyle("A$row:O$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(CompanyExportBranding::rgb('primary'));
        $sheet->getStyle("A$row:O$row")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $row++;

        $startDataRow = $row;
        foreach ($data as $d) {
            $sheet->fromArray([
                $d['num_chantier'],
                $d['chantier'],
                $d['client'],
                $d['entreprise'],
                $d['devis'],
                $d['date'],
                $d['budget'],
                $d['budget_devis'],
                $d['depense'],
                $d['reste_reel'],
                $d['marge_devis'],
                round($d['pct'], 1),
                round($d['pct_devis'], 1),
                $d['fiches'],
                $d['statut'],
            ], null, "A$row");

            $sheet->getStyle("G$row:K$row")->getNumberFormat()->setFormatCode('#,##0');
            $sheet->getStyle("L$row:M$row")->getNumberFormat()->setFormatCode('0.0');
            $sheet->getStyle("N$row")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("O$row")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

            $row++;
        }

        // Totaux
        $sheet->setCellValue("F$row", 'TOTAL');
        $sheet->setCellValue("G$row", $sumBudget);
        $sheet->setCellValue("I$row", $sumDepense);
        $sheet->setCellValue("J$row", $sumResteReel);
        $sheet->setCellValue("K$row", $sumMargeDevis);
        $sheet->getStyle("F$row:K$row")->getFont()->setBold(true);
        $sheet->getStyle("F$row:K$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F1F5F9');
        $sheet->getStyle("G$row:K$row")->getNumberFormat()->setFormatCode('#,##0');

        // Largeurs
        foreach (range('A', 'O') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        // Bordures
        $endRow = $row;
        $sheet->getStyle("A" . ($startDataRow - 1) . ":O$endRow")->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['argb' => 'FFCBD5E1'],
                ]
            ]
        ]);

        $fileName = 'budgets_chantiers_' . date('Ymd_His') . '.xlsx';
        \AuditTrail::logFileEvent('export', [
            'module' => 'decaissement',
            'action' => 'budgets_chantiers_export',
            'entity_type' => 'budget_chantiers',
            'entity_label' => 'Budgets détaillés des chantiers',
            'format' => 'excel',
            'details' => [
                'chantier_id' => $filters['chantier_id'] ?? null,
                'client' => $filters['client'] ?? null,
                'entreprise' => $filters['entreprise'] ?? null,
                'statut' => $filters['statut'] ?? null,
                'archives_scope' => $filters['archives_scope'] ?? null,
                'row_count' => is_countable($data ?? null) ? count($data) : null,
                'sum_budget' => $sumBudget,
                'sum_depense' => $sumDepense,
            ],
        ], $pdo);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="' . $fileName . '"');
        header('Cache-Control: max-age=0');
        (new Xlsx($spreadsheet))->save('php://output');
        exit;
    }

    // PDF
    $pdf = new PDFBudgetsChantiers('L', 'mm', 'A4');
    $pdf->SetCreator(CompanyExportBranding::creator());
    $pdf->SetAuthor((string)CompanyBranding::get()['company_name']);
    $pdf->SetTitle('Export budgets chantiers');
    $pdf->SetMargins(10, 39, 10);
    $pdf->SetAutoPageBreak(true, 25);
    $pdf->companyPdfTitle = $report === 'summary' ? 'Synthèse des budgets chantiers' : 'Budgets détaillés des chantiers';
    $pdf->companyPdfType = 'Rapport budgétaire';
    $pdf->companyPdfCode = 'FIN-BUD';
    $pdf->companyPdfId = 'FIN-BUD-'.date('Ymd-His');
    $pdf->companyPdfQrPayload = $pdf->companyPdfId;

    $pdf->AddPage();

    $filterLines = build_filter_lines($filters);
    $pdf->SetFont('dejavusans', '', 9);
    $pdf->SetTextColor(90, 90, 90);
    foreach ($filterLines as $line) {
        $pdf->MultiCell(0, 5, $line, 0, 'L', false, 1);
    }
    $pdf->Ln(2);

    if ($report === 'summary') {
        $kpis = [
            ['label' => 'Chantiers trouvés', 'value' => (string)$summary['global']['count'], 'fill' => [238, 242, 255], 'text' => [55, 65, 81]],
            ['label' => 'Archivés', 'value' => (string)$summary['archived_count'], 'fill' => [241, 245, 249], 'text' => [51, 65, 85]],
            ['label' => 'Budget réel', 'value' => number_format((float)$summary['global']['budget_reel'], 0, ',', ' ') . ' F', 'fill' => [245, 243, 255], 'text' => [91, 33, 182]],
            ['label' => 'Dépensé réel', 'value' => number_format((float)$summary['global']['depenses'], 0, ',', ' ') . ' F', 'fill' => [236, 253, 245], 'text' => [22, 101, 52]],
        ];

        $startX = $pdf->GetX();
        $startY = $pdf->GetY();
        $gap = 4;
        $boxWidth = ($pdf->getPageWidth() - 20 - ($gap * 3)) / 4;
        $boxHeight = 18;

        foreach ($kpis as $i => $kpi) {
            $x = $startX + (($boxWidth + $gap) * $i);
            $pdf->SetXY($x, $startY);
            $pdf->SetFillColor($kpi['fill'][0], $kpi['fill'][1], $kpi['fill'][2]);
            $pdf->SetDrawColor(220, 225, 230);
            $pdf->Rect($x, $startY, $boxWidth, $boxHeight, 'DF');
            $pdf->SetXY($x + 2.5, $startY + 2.5);
            $pdf->SetFont('dejavusans', '', 8);
            $pdf->SetTextColor(107, 114, 128);
            $pdf->Cell($boxWidth - 5, 4, $kpi['label'], 0, 2, 'L');
            $pdf->SetX($x + 2.5);
            $pdf->SetFont('dejavusans', 'B', 11);
            $pdf->SetTextColor($kpi['text'][0], $kpi['text'][1], $kpi['text'][2]);
            $pdf->Cell($boxWidth - 5, 7, $kpi['value'], 0, 0, 'L');
        }

        $pdf->SetXY($startX, $startY + $boxHeight + 6);
        $summaryColumns = [
            ['key' => 'label', 'label' => 'Périmètre', 'w' => 42, 'align' => 'L'],
            ['key' => 'count', 'label' => 'Chantiers', 'w' => 24, 'align' => 'C'],
            ['key' => 'budget_reel', 'label' => 'Budget réel', 'w' => 28, 'align' => 'R'],
            ['key' => 'budget_devis', 'label' => 'Devis', 'w' => 28, 'align' => 'R'],
            ['key' => 'depenses', 'label' => 'Dépensé réel', 'w' => 28, 'align' => 'R'],
            ['key' => 'restant_reel', 'label' => 'Restant budget réel', 'w' => 30, 'align' => 'R'],
            ['key' => 'marge_devis', 'label' => 'Marge devis', 'w' => 28, 'align' => 'R'],
            ['key' => 'fiches', 'label' => 'Fiches', 'w' => 16, 'align' => 'C'],
            ['key' => 'progression_reel', 'label' => '% interne', 'w' => 18, 'align' => 'R'],
            ['key' => 'progression_devis', 'label' => '% devis', 'w' => 18, 'align' => 'R'],
        ];

        $pdf->SetFillColor(...CompanyExportBranding::rgbArray('primary'));
        $pdf->SetDrawColor(0, 0, 0);
        $pdf->SetTextColor(...CompanyExportBranding::rgbArray('primary',true));
        $pdf->SetFont('dejavusans', 'B', 8);
        foreach ($summaryColumns as $col) {
            $pdf->MultiCell($col['w'], 8, $col['label'], 1, 'C', true, 0, '', '', true, 0, false, true, 8, 'M');
        }
        $pdf->Ln();

        $pdf->SetFont('dejavusans', '', 8.5);
        foreach ($summaryRows as $summaryRow) {
            foreach ($summaryColumns as $col) {
                $value = $summaryRow[$col['key']] ?? '';
                if (in_array($col['key'], ['budget_reel', 'budget_devis', 'depenses', 'restant_reel', 'marge_devis'], true)) {
                    $value = number_format((float)$value, 0, ',', ' ');
                } elseif (in_array($col['key'], ['progression_reel', 'progression_devis'], true)) {
                    $value = number_format((float)$value, 1, ',', ' ');
                }
                $pdf->MultiCell($col['w'], 8, (string)$value, 1, $col['align'], false, 0, '', '', true, 0, false, true, 8, 'M');
            }
            $pdf->Ln();
        }

        $pdf->Ln(4);
        $pdf->SetFont('dejavusans', 'B', 9);
        $pdf->Cell(0, 6, 'Indicateurs de lecture', 0, 1, 'L');
        $pdf->SetFont('dejavusans', '', 8.5);
        $pdf->MultiCell(0, 5, 'Dépassement cumulé global : ' . number_format((float)$summary['global']['depassement'], 0, ',', ' ') . ' F', 0, 'L', false, 1);
        $pdf->MultiCell(0, 5, 'Chantiers sans budget réel avec dépense : ' . (string)$summary['global']['no_budget_with_depense_count'] . ' (' . number_format((float)$summary['global']['no_budget_with_depense_total'], 0, ',', ' ') . ' F)', 0, 'L', false, 1);

        $fileName = 'synthese_budgets_chantiers_' . date('Ymd_His') . '.pdf';
        \AuditTrail::logFileEvent('export', [
            'module' => 'decaissement',
            'action' => 'budgets_chantiers_summary_export',
            'entity_type' => 'budget_chantiers',
            'entity_label' => 'Synthèse budgets chantiers',
            'format' => 'pdf',
            'details' => [
                'filters' => $filters,
                'row_count' => is_countable($rows ?? null) ? count($rows) : null,
                'archived_count' => $summary['archived_count'],
            ],
        ], $pdo);
        $pdf->Output($fileName, 'I');
        exit;
    }

    // KPI boxes
    $kpis = [
        ['label' => 'Chantiers', 'value' => (string)count($data), 'fill' => [238, 242, 255], 'text' => [55, 65, 81]],
        ['label' => 'Budget réel total', 'value' => number_format($sumBudget, 0, ',', ' ') . ' F', 'fill' => [245, 243, 255], 'text' => [91, 33, 182]],
        ['label' => 'Dépensé réel total', 'value' => number_format($sumDepense, 0, ',', ' ') . ' F', 'fill' => [236, 253, 245], 'text' => [22, 101, 52]],
        ['label' => 'Restant budget réel', 'value' => number_format($sumResteReel, 0, ',', ' ') . ' F', 'fill' => [255, 247, 237], 'text' => [154, 52, 18]],
    ];

    $startX = $pdf->GetX();
    $startY = $pdf->GetY();
    $gap = 4;
    $boxWidth = ($pdf->getPageWidth() - 20 - ($gap * 3)) / 4;
    $boxHeight = 18;

    foreach ($kpis as $i => $kpi) {
        $x = $startX + (($boxWidth + $gap) * $i);
        $pdf->SetXY($x, $startY);
        $pdf->SetFillColor($kpi['fill'][0], $kpi['fill'][1], $kpi['fill'][2]);
        $pdf->SetDrawColor(220, 225, 230);
        $pdf->Rect($x, $startY, $boxWidth, $boxHeight, 'DF');

        $pdf->SetXY($x + 2.5, $startY + 2.5);
        $pdf->SetFont('dejavusans', '', 8);
        $pdf->SetTextColor(107, 114, 128);
        $pdf->Cell($boxWidth - 5, 4, $kpi['label'], 0, 2, 'L');

        $pdf->SetX($x + 2.5);
        $pdf->SetFont('dejavusans', 'B', 11);
        $pdf->SetTextColor($kpi['text'][0], $kpi['text'][1], $kpi['text'][2]);
        $pdf->Cell($boxWidth - 5, 7, $kpi['value'], 0, 0, 'L');
    }

    $pdf->SetXY($startX, $startY + $boxHeight + 5);

    $columns = [
        ['key' => 'num_chantier', 'label' => 'N°', 'w' => 16, 'align' => 'L'],
        ['key' => 'chantier', 'label' => 'Chantier', 'w' => 48, 'align' => 'L'],
        ['key' => 'client', 'label' => 'Client', 'w' => 32, 'align' => 'L'],
        ['key' => 'entreprise', 'label' => 'Entreprise', 'w' => 26, 'align' => 'L'],
        ['key' => 'devis', 'label' => 'Devis', 'w' => 22, 'align' => 'L'],
        ['key' => 'date', 'label' => 'Date', 'w' => 18, 'align' => 'C'],
        ['key' => 'budget', 'label' => 'Budget réel', 'w' => 22, 'align' => 'R'],
        ['key' => 'budget_devis', 'label' => 'Devis', 'w' => 22, 'align' => 'R'],
        ['key' => 'depense', 'label' => 'Dépensé réel', 'w' => 22, 'align' => 'R'],
        ['key' => 'reste_reel', 'label' => 'Restant réel', 'w' => 22, 'align' => 'R'],
        ['key' => 'marge_devis', 'label' => 'Marge devis', 'w' => 22, 'align' => 'R'],
        ['key' => 'pct', 'label' => '% interne', 'w' => 14, 'align' => 'R'],
        ['key' => 'pct_devis', 'label' => '% devis', 'w' => 14, 'align' => 'R'],
        ['key' => 'fiches', 'label' => 'Fiches', 'w' => 12, 'align' => 'C'],
        ['key' => 'statut', 'label' => 'Statut', 'w' => 18, 'align' => 'C'],
    ];

    $drawHeader = function () use ($pdf, $columns) {
        $pdf->SetFillColor(...CompanyExportBranding::rgbArray('primary'));
        $pdf->SetDrawColor(0, 0, 0);
        $pdf->SetTextColor(...CompanyExportBranding::rgbArray('primary',true));
        $pdf->SetFont('dejavusans', 'B', 8);

        $heights = [];
        foreach ($columns as $col) {
            $heights[] = $pdf->getStringHeight($col['w'], $col['label']);
        }
        $headerHeight = max(max($heights), 8);

        foreach ($columns as $col) {
            $pdf->MultiCell($col['w'], $headerHeight, $col['label'], 1, 'C', true, 0, '', '', true, 0, false, true, $headerHeight, 'M');
        }
        $pdf->Ln();
    };

    $drawHeader();

    $drawRow = function (array $row) use ($pdf, $columns, $drawHeader) {
        $pdf->SetFont('dejavusans', '', 8.5);
        $heights = [];
        foreach ($columns as $col) {
            $val = (string)($row[$col['key']] ?? '');
            $heights[] = $pdf->getStringHeight($col['w'], $val);
        }
        $rowHeight = max($heights);

        if ($pdf->GetY() + $rowHeight > ($pdf->getPageHeight() - 14)) {
            $pdf->AddPage();
            $pdf->Ln(2);
            $drawHeader();
            $pdf->SetFont('dejavusans', '', 8.5);
        }

        foreach ($columns as $col) {
            $val = (string)($row[$col['key']] ?? '');
            $pdf->MultiCell($col['w'], $rowHeight, $val, 1, $col['align'], false, 0, '', '', true, 0, false, true, $rowHeight, 'M');
        }
        $pdf->Ln();
    };

    foreach ($data as $d) {
        $drawRow([
            'num_chantier' => $d['num_chantier'],
            'chantier' => $d['chantier'],
            'client' => $d['client'],
            'entreprise' => $d['entreprise'],
            'devis' => $d['devis'],
            'date' => $d['date'],
            'budget' => number_format((float)$d['budget'], 0, ',', ' '),
            'budget_devis' => number_format((float)$d['budget_devis'], 0, ',', ' '),
            'depense' => number_format((float)$d['depense'], 0, ',', ' '),
            'reste_reel' => number_format((float)$d['reste_reel'], 0, ',', ' '),
            'marge_devis' => number_format((float)$d['marge_devis'], 0, ',', ' '),
            'pct' => number_format((float)$d['pct'], 1, ',', ' '),
            'pct_devis' => number_format((float)$d['pct_devis'], 1, ',', ' '),
            'fiches' => (string)$d['fiches'],
            'statut' => $d['statut'],
        ]);
    }

    $fileName = 'budgets_chantiers_' . date('Ymd_His') . '.pdf';
    \AuditTrail::logFileEvent('export', [
        'module' => 'decaissement',
        'action' => 'budgets_chantiers_export',
        'entity_type' => 'budget_chantiers',
        'entity_label' => 'Budgets détaillés des chantiers',
        'format' => 'pdf',
        'details' => [
            'chantier_id' => $filters['chantier_id'] ?? null,
            'client' => $filters['client'] ?? null,
            'entreprise' => $filters['entreprise'] ?? null,
            'statut' => $filters['statut'] ?? null,
            'archives_scope' => $filters['archives_scope'] ?? null,
            'row_count' => is_countable($data ?? null) ? count($data) : null,
            'sum_budget' => $sumBudget,
            'sum_depense' => $sumDepense,
        ],
    ], $pdo);
    $pdf->Output($fileName, 'I');
    exit;
} catch (Throwable $e) {
    error_log('[export_tous_budgets] ' . $e->getMessage());
    http_response_code(500);
    echo 'Erreur export';
    exit;
}
