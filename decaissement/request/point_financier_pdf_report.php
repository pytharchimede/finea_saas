<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../model/CompanyPdfTemplate.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/point_financier_filters.php';
require_once __DIR__ . '/../includes/fiche_label_helper.php';

if (!function_exists('pf_recap_supplier_label')) {
    function pf_recap_supplier_label(array $row): string
    {
        $supplierName = trim((string) ($row['fournisseur_nom'] ?? ''));
        if ($supplierName !== '') {
            return 'FOU: ' . $supplierName;
        }

        $supplierId = (int) ($row['fournisseur_id'] ?? 0);
        if ($supplierId > 0) {
            return 'FOU: ajouté en BDD';
        }

        return '';
    }
}

if (!class_exists('PDFPointFinancier')) {
    class PDFPointFinancier extends \TCPDF
    {
        use CompanyPdfTemplate;
        public string $reportTitle = 'Export point financier';

        public function Header()
        {
            $this->drawCompanyPdfHeader();
        }

        public function Footer()
        {
            $this->drawCompanyPdfFooter();
        }
    }
}

function pf_generate_point_financier_pdf_document(PDO $pdo, ?string $status, array $filters = []): array
{
    $tableChantier = pf_detect_chantier_table($pdo);
    $statusMeta = pf_status_meta($status);
    $filters = [
        'beneficiaire' => $filters['beneficiaire'] ?? '',
        'exact_date' => $filters['exact_date'] ?? null,
        'from' => $filters['from'] ?? null,
        'to' => $filters['to'] ?? null,
        'chantier' => $filters['chantier'] ?? null,
        'debourse' => $filters['debourse'] ?? null,
    ];

    pf_try_repair_chantier_debourse_links($pdo, $tableChantier, $filters['chantier'] ?? null);

    $params = [];
    $where = [$statusMeta['condition']];
    pf_apply_common_filters($where, $params, $filters, $statusMeta['date_expr'], $tableChantier);

    $sqlBase = "FROM fiche_decaissement f
LEFT JOIN $tableChantier c ON c.id=f.chantier_id
LEFT JOIN ligne_debourse_banamur ldb ON ldb.id=f.ligne_debourse_id
LEFT JOIN debourse_banamur db ON db.id=ldb.debourse_id
LEFT JOIN ligne_devis_banamur ld ON ld.id=db.ligne_devis_id";

    $sql = 'SELECT f.num_fiche, f.beficiaire_fiche, f.fournisseur_id, f.fournisseur_nom, f.montant_fiche, f.date_creat_fiche, f.date_decaisse, f.date_refuse, f.date_valid, f.designation_fiche, f.precision_fiche, f.details_demande, f.conforme, f.approuve, f.etat_fiche, f.decaisse, f.ligne_debourse_id, c.code AS chantier_code, c.titre AS chantier_titre, ldb.designation AS ligne_debourse_designation, db.id AS debourse_id, ld.designation AS devis_designation ' . $sqlBase . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . $statusMeta['date_expr'] . ' DESC LIMIT 2000';

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $filterLines = [];
    $periodText = trim((string)($filters['exact_date'] ?? '')) !== ''
        ? 'Date précise (' . $statusMeta['date_label'] . ') : ' . (string)$filters['exact_date']
        : (trim((string)($filters['from'] ?? '')) || trim((string)($filters['to'] ?? ''))
            ? 'Période (' . $statusMeta['date_label'] . ') : ' . (($filters['from'] ?: '...')) . ' -> ' . (($filters['to'] ?: '...'))
            : 'Période (' . $statusMeta['date_label'] . ') : toutes');
    $filterLines[] = $periodText;
    if (!empty($filters['beneficiaire'])) {
        $filterLines[] = 'Bénéficiaire : ' . (string)$filters['beneficiaire'];
    }
    if (!empty($filters['chantier'])) {
        $filterLines[] = 'Chantier : ' . (string)$filters['chantier'];
    }
    if (!empty($filters['debourse'])) {
        $filterLines[] = 'Déboursé : #' . (string)$filters['debourse'];
    }
    $filterLines[] = 'Date export : ' . date('d/m/Y H:i');

    $total = 0.0;
    $beneficiaires = [];
    $chantiers = [];
    foreach ($rows as $row) {
        $total += (float)($row['montant_fiche'] ?? 0);
        $benef = trim((string)($row['beficiaire_fiche'] ?? ''));
        if ($benef !== '') {
            $beneficiaires[$benef] = true;
        }
        $chantierKey = trim((string)($row['chantier_code'] ?? ''));
        if ($chantierKey === '') {
            $chantierKey = trim((string)($row['chantier_titre'] ?? ''));
        }
        if ($chantierKey !== '') {
            $chantiers[$chantierKey] = true;
        }
    }

    $kpis = [
        ['label' => 'Nombre de fiches', 'value' => (string)count($rows), 'fill' => [238, 242, 255], 'text' => [55, 65, 81]],
        ['label' => 'Montant total', 'value' => number_format($total, 0, ',', ' ') . ' CFA', 'fill' => [236, 253, 245], 'text' => [22, 101, 52]],
        ['label' => 'Bénéficiaires', 'value' => (string)count($beneficiaires), 'fill' => [255, 247, 237], 'text' => [154, 52, 18]],
        ['label' => 'Chantiers', 'value' => (string)count($chantiers), 'fill' => [245, 243, 255], 'text' => [91, 33, 182]],
    ];

    $pdf = new PDFPointFinancier('L', 'mm', 'A4');
    $pdf->SetCreator('Banamur');
    $pdf->SetAuthor('Banamur');
    $pdf->SetTitle('Export point financier');
    $pdf->SetMargins(10, 38, 10);
    $pdf->SetAutoPageBreak(true, 25);
    $pdf->reportTitle = 'BANAMUR - ' . $statusMeta['label'];
    $pdf->companyPdfTitle = $statusMeta['label'];
    $pdf->companyPdfType = 'Point financier';
    $pdf->companyPdfId = 'FIN-PF-' . date('Ymd-His');
    $pdf->companyPdfCode = 'FIN-POINT';
    $pdf->companyPdfQrPayload = json_encode(['document' => $pdf->companyPdfId, 'status' => $statusMeta['label'], 'filters' => $filters], JSON_UNESCAPED_UNICODE);
    $pdf->AddPage();

    $columns = [
        ['key' => 'etat', 'label' => 'Etat', 'w' => 18, 'align' => 'L'],
        ['key' => 'num', 'label' => 'Num fiche', 'w' => 18, 'align' => 'L'],
        ['key' => 'benef', 'label' => 'Bénéficiaire', 'w' => 24, 'align' => 'L'],
        ['key' => 'chant', 'label' => 'Chantier', 'w' => 26, 'align' => 'L'],
        ['key' => 'deb', 'label' => 'Déboursé', 'w' => 18, 'align' => 'L'],
        ['key' => 'des', 'label' => 'Désignation', 'w' => 28, 'align' => 'L'],
        ['key' => 'prec', 'label' => 'Précision', 'w' => 28, 'align' => 'L'],
        ['key' => 'details', 'label' => 'Détails', 'w' => 54, 'align' => 'L'],
        ['key' => 'date_ref', 'label' => $statusMeta['date_label'], 'w' => 18, 'align' => 'C'],
        ['key' => 'date_cree', 'label' => 'Date demande', 'w' => 16, 'align' => 'C'],
        ['key' => 'mnt', 'label' => 'Montant', 'w' => 17, 'align' => 'R'],
    ];

    $renderFilters = function () use ($pdf, $filterLines) {
        $pdf->SetFont('dejavusans', '', 8.5);
        $pdf->SetTextColor(90, 90, 90);
        foreach ($filterLines as $line) {
            $pdf->MultiCell(0, 4.6, $line, 0, 'L', false, 1);
        }
        $pdf->Ln(2);
    };

    $renderKpis = function () use ($pdf, $kpis) {
        $startX = $pdf->GetX();
        $startY = $pdf->GetY();
        $gap = 4;
        $boxWidth = ($pdf->getPageWidth() - 20 - ($gap * 3)) / 4;
        $boxHeight = 18;

        foreach ($kpis as $index => $kpi) {
            $x = $startX + (($boxWidth + $gap) * $index);
            $pdf->SetXY($x, $startY);
            $pdf->SetFillColor($kpi['fill'][0], $kpi['fill'][1], $kpi['fill'][2]);
            $pdf->SetDrawColor(220, 225, 230);
            $pdf->Rect($x, $startY, $boxWidth, $boxHeight, 'DF');

            $pdf->SetXY($x + 2.5, $startY + 2.5);
            $pdf->SetFont('dejavusans', '', 7.5);
            $pdf->SetTextColor(107, 114, 128);
            $pdf->Cell($boxWidth - 5, 4, $kpi['label'], 0, 2, 'L');

            $pdf->SetX($x + 2.5);
            $pdf->SetFont('dejavusans', 'B', 11);
            $pdf->SetTextColor($kpi['text'][0], $kpi['text'][1], $kpi['text'][2]);
            $pdf->Cell($boxWidth - 5, 7, $kpi['value'], 0, 0, 'L');
        }

        $pdf->SetXY($startX, $startY + $boxHeight + 4);
    };

    $drawHeader = function () use ($pdf, $columns) {
        $pdf->SetFillColor(250, 189, 2);
        $pdf->SetDrawColor(0, 0, 0);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('dejavusans', 'B', 7.5);
        $heights = [];
        foreach ($columns as $col) {
            $heights[] = $pdf->getStringHeight($col['w'], $col['label']);
        }
        $headerHeight = max(max($heights), 8);
        $startX = $pdf->GetX();
        $startY = $pdf->GetY();
        $x = $startX;
        foreach ($columns as $col) {
            $pdf->SetXY($x, $startY);
            $pdf->MultiCell($col['w'], $headerHeight, $col['label'], 1, 'C', true, 0, null, null, true, 0, false, true, $headerHeight, 'M');
            $x += $col['w'];
        }
        $pdf->SetXY($startX, $startY + $headerHeight);
    };

    $renderFilters();
    $renderKpis();
    $drawHeader();

    $drawRow = function (array $data) use ($pdf, $columns, $drawHeader) {
        $pdf->SetFont('dejavusans', '', 8.5);
        $heights = [];
        foreach ($columns as $col) {
            $heights[] = $pdf->getStringHeight($col['w'], (string)($data[$col['key']] ?? ''));
        }
        // Une description exceptionnellement longue ne doit pas repousser à elle
        // seule tout le tableau sur la page suivante. TCPDF réduira uniquement le
        // texte de la cellule concernée pour le faire tenir dans cette hauteur.
        $rowHeight = min(max($heights), 55.0);
        $pageBottom = $pdf->getPageHeight() - $pdf->getBreakMargin();
        if ($pdf->GetY() + $rowHeight > $pageBottom) {
            $pdf->AddPage();
            $pdf->Ln(2);
            $drawHeader();
            $pdf->SetFont('dejavusans', '', 8.5);
        }

        $startX = $pdf->GetX();
        $startY = $pdf->GetY();
        $x = $startX;
        foreach ($columns as $col) {
            $pdf->SetXY($x, $startY);
            $pdf->MultiCell($col['w'], $rowHeight, (string)($data[$col['key']] ?? ''), 1, $col['align'], false, 0, null, null, true, 0, false, true, $rowHeight, 'M', true);
            $x += $col['w'];
        }
        $pdf->SetXY($startX, $startY + $rowHeight);
    };

    foreach ($rows as $row) {
        $statusRow = pf_format_status($row);
        $chantierLabel = trim((string)($row['chantier_code'] ?? ''));
        if (!empty($row['chantier_titre'])) {
            $chantierLabel = trim($chantierLabel . ' - ' . $row['chantier_titre']);
        }
        $row['ligne_designation'] = $row['ligne_debourse_designation'] ?? ($row['ligne_designation'] ?? '');
        $row = enrich_fiche_labels($row);
        $debourseLabel = trim((!empty($row['debourse_id']) ? ('#' . $row['debourse_id']) : '') . ' ' . ($row['ligne_devis_label'] ?? ''));
        if (!empty($row['ligne_debourse_label'])) {
            $debourseLabel = trim($debourseLabel . ' ' . $row['ligne_debourse_label']);
        }
        $referenceDate = pf_reference_date($row);
        $precision = trim((string)($row['precision_fiche'] ?? ''));
        $detailsDemande = trim((string)($row['details_demande'] ?? ''));
        $montant = (float)($row['montant_fiche'] ?? 0);

        $benefLabel = (string)($row['beficiaire_fiche'] ?? '');
        $supplierLabel = pf_recap_supplier_label($row);
        if ($supplierLabel !== '') {
            $benefLabel = trim($benefLabel) !== '' ? ($benefLabel . "\n" . $supplierLabel) : $supplierLabel;
        }

        $drawRow([
            'etat' => $statusRow['label'],
            'num' => (string)($row['num_fiche'] ?? ''),
            'benef' => $benefLabel,
            'chant' => $chantierLabel,
            'deb' => $debourseLabel,
            'des' => (string)($row['designation_fiche'] ?? ''),
            'prec' => $precision,
            'details' => $detailsDemande,
            'date_ref' => $referenceDate ? date('d/m/Y', strtotime($referenceDate)) : '',
            'date_cree' => !empty($row['date_creat_fiche']) ? date('d/m/Y', strtotime($row['date_creat_fiche'])) : '',
            'mnt' => number_format($montant, 0, ',', ' '),
        ]);
    }

    $pdf->Ln(1.5);
    $remainingHeight = $pdf->getPageHeight() - $pdf->GetY() - $pdf->getBreakMargin();
    if ($remainingHeight < 12) {
        $pdf->AddPage();
    }
    $pdf->SetFillColor(250, 189, 2);
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('dejavusans', 'B', 9);
    $totalWidth = 0;
    foreach ($columns as $index => $column) {
        if ($index < count($columns) - 1) {
            $totalWidth += $column['w'];
        }
    }
    $amountWidth = $columns[count($columns) - 1]['w'];
    $labelWidth = 42;
    $spacerWidth = max(0, $totalWidth - $labelWidth);
    $pdf->Cell($spacerWidth, 10, '', 1, 0, 'R', true);
    $pdf->Cell($labelWidth, 10, 'TOTAL GENERAL', 1, 0, 'C', true);
    $pdf->Cell($amountWidth, 10, number_format($total, 0, ',', ' '), 1, 1, 'R', true);

    $fileName = 'point_financier_' . $statusMeta['key'] . '_' . date('Ymd_His') . '.pdf';

    return [
        'fileName' => $fileName,
        'content' => $pdf->Output($fileName, 'S'),
        'rowCount' => count($rows),
        'total' => $total,
        'statusMeta' => $statusMeta,
        'filters' => $filters,
        'beneficiaryCount' => count($beneficiaires),
        'chantierCount' => count($chantiers),
    ];
}
