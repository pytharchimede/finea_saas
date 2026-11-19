<?php
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../../model/AuditTrail.php';
require_once __DIR__ . '/../../model/CompanyPdfTemplate.php';
require_once __DIR__ . '/../includes/fiche_label_helper.php';

function recap_supplier_label(array $row): string
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

// PDF entreprise: header/pied stylés
class PDFConformite extends TCPDF
{
    use CompanyPdfTemplate;
    public string $reportTitle = '';
    public function Header()
    {
        $this->drawCompanyPdfHeader();
        return;
        // Bannière d'entête si disponible (modèle: img/entete_fiche.jpg)
        $banner = __DIR__ . '/../../img/entete_fiche.jpg';
        if (@file_exists($banner)) {
            $left = 10; // marge gauche
            $top = 6;   // proche du bord haut
            $width = $this->getPageWidth() - 20; // pleine largeur utile
            $this->Image($banner, $left, $top, $width, 0, '', '', '', false, 300, '', false, false, 0, false, false, false);
            $this->SetY($top + 18);
        } else {
            // Fallback: logo + titre
            $logo = __DIR__ . '/../../logo/logo.png';
            if (!@file_exists($logo)) {
                // Deuxième fallback: ancien logo dans img/
                $logo = __DIR__ . '/../../img/logo_veritas.jpg';
            }
            if (@file_exists($logo)) {
                $this->Image($logo, 10, 6, 24, 0, '', '', '', false, 300);
            }
            $this->SetFont('dejavusans', 'B', 14);
            $this->SetTextColor(33, 37, 41);
            $this->Cell(0, 8, $this->reportTitle ?: 'Export fiches à certifier', 0, 1, 'C');
        }
        // Ligne de séparation
        $this->SetDrawColor(22, 119, 255);
        $this->SetLineWidth(0.5);
        $this->Line(10, 18, $this->getPageWidth() - 10, 18);
        $this->Ln(2);
    }
    public function Footer()
    {
        $this->drawCompanyPdfFooter();
        return;
        // Positionner proche du bas et tracer la ligne de séparation
        $this->SetY(-24);
        $this->SetDrawColor(230, 230, 230);
        $this->Line(10, $this->GetY(), $this->getPageWidth() - 10, $this->GetY());
        $this->Ln(2);

        // Logos gauche/droite (modèle)
        $logo1 = __DIR__ . '/../../logo/logo.png';
        if (!@file_exists($logo1)) {
            $logo1 = __DIR__ . '/../../img/logo_veritas.jpg';
        }
        $logo2 = $logo1; // même logo des deux côtés par défaut
        $logoW = 20;
        $yLogo = $this->GetY() + 1;
        if (@file_exists($logo1)) {
            $this->Image($logo1, 10, $yLogo, $logoW);
            $this->Image($logo2, $this->getPageWidth() - 10 - $logoW, $yLogo, $logoW);
        }

        // Texte pied de page (4 lignes comme le modèle)
        $this->SetFont('dejavusans', '', 7);
        $this->SetTextColor(60, 60, 60);
        $this->Cell(0, 3.5, 'FOURNITURES INDUSTRIELLES, DEPANNAGE ET TRAVAUX PUBLIQUES', 0, 1, 'C');
        $this->Cell(0, 3.5, 'Au capital de 10 000 000 F CFA - Siège Social : Abidjan, Koumassi, Zone industrielle', 0, 1, 'C');
        $this->Cell(0, 3.5, '01 BP 1642 Abidjan 01 - Téléphone : (+225) 27-21-36-27-27  -  Email : info@fidest.org', 0, 1, 'C');
        $this->Cell(0, 3.5, 'RCCM : CI-ABJ-2017-B-20163  -  N° CC : 010274200088', 0, 1, 'C');

        // Pagination centr\xc3\xa9e
        $this->Ln(1);
        $this->SetFont('dejavusans', '', 8);
        $this->SetTextColor(100, 100, 100);
        $this->Cell(0, 6, 'Page ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, 0, 'C');
    }
}

try {
    $pdo = Database::getConnection();

    // Récup sélection/filtre
    $selected = [];
    if (!empty($_POST['selected'])) {
        $selected = is_array($_POST['selected']) ? $_POST['selected'] : explode(',', (string)$_POST['selected']);
        $selected = array_values(array_filter(array_map('trim', $selected)));
    }
    $filters = [
        'date_debut' => $_GET['date_debut'] ?? null,
        'date_fin'    => $_GET['date_fin'] ?? null,
        'demandeur'   => $_GET['demandeur'] ?? null,
        'chantier'    => $_GET['chantier'] ?? null,
        'precision'   => $_GET['precision'] ?? null,
        'designation' => $_GET['designation'] ?? null,
        'montant_min' => $_GET['montant_min'] ?? null,
        'montant_max' => $_GET['montant_max'] ?? null,
    ];

    // Construction de la requête enrichie (chantier, ligne déboursé, ligne devis)
    $params = [];
    if (!empty($selected)) {
        $in = implode(',', array_fill(0, count($selected), '?'));
        $sql = "SELECT f.*, c.titre AS chantier_titre, c.code AS chantier_code,
                       ldb.designation AS ligne_designation, ldb.categorie AS ligne_categorie,
                       ld.designation AS devis_designation
                FROM fiche_decaissement f
                LEFT JOIN chantier_banamur c ON c.id = f.chantier_id
                LEFT JOIN ligne_debourse_banamur ldb ON ldb.id = f.ligne_debourse_id
                LEFT JOIN debourse_banamur db ON db.id = ldb.debourse_id
                LEFT JOIN ligne_devis_banamur ld ON ld.id = db.ligne_devis_id
                WHERE f.conforme = 0 AND f.approuve = 0 AND f.decaisse = 0
                  AND f.num_fiche IN ($in)
                  AND (f.chantier_id IS NULL OR f.chantier_id=0 OR c.archived_at IS NULL)
                ORDER BY f.date_creat_fiche DESC";
        $st = $pdo->prepare($sql);
        $st->execute($selected);
    } else {
        $where = ["f.conforme = 0", "f.approuve = 0", "f.decaisse = 0"];
        if (!empty($filters['date_debut'])) {
            $where[] = 'f.date_creat_fiche >= :date_debut';
            $params['date_debut'] = $filters['date_debut'] . ' 00:00:00';
        }
        if (!empty($filters['date_fin'])) {
            $where[] = 'f.date_creat_fiche <= :date_fin';
            $params['date_fin']   = $filters['date_fin']   . ' 23:59:59';
        }
        if (!empty($filters['demandeur'])) {
            $where[] = 'f.beficiaire_fiche LIKE :benef';
            $params['benef']      = '%' . $filters['demandeur'] . '%';
        }
        if (!empty($filters['chantier'])) {
            $where[] = '(c.titre LIKE :chantier OR c.code LIKE :chantier)';
            $params['chantier'] = '%' . $filters['chantier'] . '%';
        }
        if (!empty($filters['precision'])) {
            $where[] = '(f.precision_fiche LIKE :precision OR ldb.designation LIKE :precision OR ldb.categorie LIKE :precision)';
            $params['precision'] = '%' . $filters['precision'] . '%';
        }
        if (!empty($filters['designation'])) {
            $where[] = 'f.designation_fiche LIKE :designation';
            $params['designation'] = '%' . $filters['designation'] . '%';
        }
        if (!empty($filters['montant_min'])) {
            $where[] = 'f.montant_fiche >= :montant_min';
            $params['montant_min'] = (float)$filters['montant_min'];
        }
        if (!empty($filters['montant_max'])) {
            $where[] = 'f.montant_fiche <= :montant_max';
            $params['montant_max'] = (float)$filters['montant_max'];
        }

        $where[] = '(f.chantier_id IS NULL OR f.chantier_id=0 OR c.archived_at IS NULL)';
        $sql = "SELECT f.*, c.titre AS chantier_titre, c.code AS chantier_code,
                       ldb.designation AS ligne_designation, ldb.categorie AS ligne_categorie,
                       ld.designation AS devis_designation
                FROM fiche_decaissement f
                LEFT JOIN chantier_banamur c ON c.id = f.chantier_id
                LEFT JOIN ligne_debourse_banamur ldb ON ldb.id = f.ligne_debourse_id
                LEFT JOIN debourse_banamur db ON db.id = ldb.debourse_id
                LEFT JOIN ligne_devis_banamur ld ON ld.id = db.ligne_devis_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY f.date_creat_fiche DESC";
        $st = $pdo->prepare($sql);
        $st->execute($params);
    }
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    // PDF paysage + style
    $pdf = new PDFConformite('L', 'mm', 'A4');
    $pdf->SetCreator('Banamur');
    $pdf->SetAuthor('Banamur');
    $pdf->SetTitle('Export fiches à certifier');
    $pdf->SetMargins(10, 38, 10);
    $pdf->SetAutoPageBreak(true, 25);
    $title = 'Fiches à certifier';
    if (!empty($filters['date_debut']) || !empty($filters['date_fin'])) {
        $title .= ' — ' . ($filters['date_debut'] ?: '…') . ' → ' . ($filters['date_fin'] ?: '…');
    }
    $pdf->reportTitle = 'BANAMUR — ' . $title;
    $pdf->companyPdfTitle = $title;
    $pdf->companyPdfType = 'Liste de conformité';
    $pdf->companyPdfId = 'FIN-CONF-' . date('Ymd-His');
    $pdf->companyPdfCode = 'FIN-DEC-CONF';
    $pdf->companyPdfQrPayload = json_encode(['document'=>$pdf->companyPdfId,'type'=>'fiches_conformite','selection'=>$selected,'filters'=>$filters], JSON_UNESCAPED_UNICODE);
    $pdf->AddPage();

    // Définition colonnes (total largeur intérieure ~277mm)
    $columns = [
        ['key' => 'num',   'label' => 'Num fiche',       'w' => 22, 'align' => 'L'],
        ['key' => 'benef', 'label' => 'Bénéficiaire',    'w' => 30, 'align' => 'L'],
        ['key' => 'chant', 'label' => 'Chantier',        'w' => 35, 'align' => 'L'],
        ['key' => 'ldb',   'label' => 'Ligne déboursé',  'w' => 35, 'align' => 'L'],
        ['key' => 'ldv',   'label' => 'Ligne devis',     'w' => 35, 'align' => 'L'],
        ['key' => 'prec',  'label' => 'Précision',       'w' => 35, 'align' => 'L'],
        ['key' => 'det',   'label' => 'Détails demande', 'w' => 40, 'align' => 'L'], // 👈 AJOUT
        ['key' => 'mnt',   'label' => 'Montant(CFA)',   'w' => 22, 'align' => 'R'],
        ['key' => 'date',  'label' => 'Créée le',        'w' => 23, 'align' => 'C'],
    ];

    // En-têtes de colonnes
    $pdf->SetFillColor(245, 247, 250);
    $pdf->SetDrawColor(220, 225, 230);
    $pdf->SetTextColor(33, 37, 41);
    $pdf->SetFont('dejavusans', 'B', 9);
    foreach ($columns as $col) {
        $pdf->Cell($col['w'], 8, $col['label'], 1, 0, 'C', true);
    }
    $pdf->Ln();

    // Helper pour dessiner une ligne avec MultiCell (retour à la ligne, pas de débordement)
    $drawRow = function ($pdf, $columns, $data) {
        $lineHeights = [];
        foreach ($columns as $col) {
            $txt = (string)($data[$col['key']] ?? '');
            $lineHeights[] = $pdf->getStringHeight($col['w'], $txt, false, true, '', 0);
        }
        $h = max($lineHeights);
        // Saut de page si nécessaire
        if ($pdf->GetY() + $h > ($pdf->getPageHeight() - 26)) {
            $pdf->AddPage();
            // réimprimer l'entête de colonnes après AddPage
            $pdf->SetFillColor(245, 247, 250);
            $pdf->SetDrawColor(220, 225, 230);
            $pdf->SetTextColor(33, 37, 41);
            $pdf->SetFont('dejavusans', 'B', 9);
            foreach ($columns as $col) {
                $pdf->Cell($col['w'], 8, $col['label'], 1, 0, 'C', true);
            }
            $pdf->Ln();
            $pdf->SetFont('dejavusans', '', 9);
        }
        $x = $pdf->GetX();
        $y = $pdf->GetY();
        foreach ($columns as $col) {
            $pdf->MultiCell($col['w'], $h, (string)($data[$col['key']] ?? ''), 1, $col['align'], false, 0, '', '', true, 0, false, true, $h, 'M');
        }
        $pdf->Ln();
    };

    // Données
    $pdf->SetFont('dejavusans', '', 9);
    $pdf->SetTextColor(55, 65, 81);
    $total = 0.0;
    foreach ($rows as $f) {
        $f = enrich_fiche_labels($f);
        $num   = $f['num_fiche'] ?? '';
        $benef = $f['beficiaire_fiche'] ?? $f['beneficiaire_fiche'] ?? '';
        $supplierLabel = recap_supplier_label($f);
        if ($supplierLabel !== '') {
            $benef = trim($benef) !== '' ? ($benef . "\n" . $supplierLabel) : $supplierLabel;
        }
        $chant = trim(($f['chantier_titre'] ?? '') . (!empty($f['chantier_code']) ? ' [' . $f['chantier_code'] . ']' : ''));
        $ldb   = (string)($f['ligne_debourse_label'] ?? '');
        $ldv   = (string)($f['ligne_devis_label'] ?? '');
        $prec  = (string)($f['precision_fiche'] ?? '');
        $details = (string)($f['details_demande'] ?? '');
        $mont  = (float)($f['montant_fiche'] ?? 0);
        $datec = isset($f['date_creat_fiche']) ? date('Y-m-d', strtotime($f['date_creat_fiche'])) : '';
        $total += $mont;

        $drawRow($pdf, $columns, [
            'num' => $num,
            'benef' => $benef,
            'chant' => $chant,
            'ldb' => $ldb,
            'ldv' => $ldv,
            'prec' => $prec,
            'det' => $details, // 👈 AJOUT
            'mnt' => number_format($mont, 0, ',', ' '),
            'date' => $datec,
        ]);
    }

    // TOTAL
    $pdf->Ln(1);
    $pdf->SetFont('dejavusans', 'B', 10);
    $pdf->SetTextColor(33, 37, 41);
    // Largeur cumulée jusqu'à la colonne Montant (toutes sauf les 2 dernières colonnes Montant/Date)
    $leftWidth = 0;
    for ($i = 0; $i < count($columns) - 2; $i++) {
        $leftWidth += $columns[$i]['w'];
    }
    $pdf->Cell($leftWidth, 10, 'TOTAL', 1, 0, 'R');
    $pdf->Cell($columns[count($columns) - 2]['w'], 10, number_format($total, 0, ',', ' '), 1, 0, 'R');
    $pdf->Cell($columns[count($columns) - 1]['w'], 10, '', 1, 1, 'C');

    \AuditTrail::logFileEvent('export', [
        'module' => 'decaissement',
        'action' => 'fiches_conformite_export',
        'entity_type' => 'fiche_conformite_list',
        'entity_label' => 'Fiches à certifier',
        'format' => 'pdf',
        'details' => [
            'selection_count' => is_countable($selected ?? null) ? count($selected) : 0,
            'row_count' => is_countable($rows ?? null) ? count($rows) : null,
            'total' => $total,
        ],
    ], $pdo);
    $pdf->Output('export_fiches_conformite_' . date('Ymd_His') . '.pdf', 'I');
    exit;
} catch (Throwable $e) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
