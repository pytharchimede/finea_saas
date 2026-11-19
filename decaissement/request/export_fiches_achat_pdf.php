<?php
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../includes/fiche_label_helper.php';
require_once __DIR__ . '/../../model/AuditTrail.php';
require_once __DIR__ . '/../../model/CompanyPdfTemplate.php';
require_once __DIR__ . '/../../services/DisbursementDocumentComplianceService.php';

function recap_supplier_label(array $row): string
{
    $supplierName = trim((string) ($row['fournisseur_nom'] ?? ''));
    if ($supplierName !== '') {
        return  $supplierName;
    }

    $supplierId = (int) ($row['fournisseur_id'] ?? 0);
    if ($supplierId > 0) {
        return 'ajouté en BDD';
    }

    return '';
}

class PDFAchat extends TCPDF
{
    use CompanyPdfTemplate;
    public function Header(){ $this->drawCompanyPdfHeader(); }
    public function Footer(){ $this->drawCompanyPdfFooter(); }
}

try {
    $pdo = Database::getConnection();
    $ficheColumns = $pdo->query("SHOW COLUMNS FROM fiche_decaissement")->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
    if (in_array('parent_num_fiche', $ficheColumns, true) && in_array('code_parentale', $ficheColumns, true)) {
        $parentExpr = "COALESCE(NULLIF(f.parent_num_fiche, ''), NULLIF(f.code_parentale, ''))";
    } elseif (in_array('parent_num_fiche', $ficheColumns, true)) {
        $parentExpr = "NULLIF(f.parent_num_fiche, '')";
    } elseif (in_array('code_parentale', $ficheColumns, true)) {
        $parentExpr = "NULLIF(f.code_parentale, '')";
    } else {
        $parentExpr = 'NULL';
    }
    $parentJoinSql = "LEFT JOIN fiche_decaissement p ON p.num_fiche = {$parentExpr}";

    // Sélection/filters
    $selected = [];
    if (!empty($_POST['selected'])) {
        $selected = is_array($_POST['selected']) ? $_POST['selected'] : explode(',', (string)$_POST['selected']);
    } elseif (!empty($_POST['ids'])) {
        $selected = is_array($_POST['ids']) ? $_POST['ids'] : explode(',', (string)$_POST['ids']);
    }
    $selected = array_values(array_filter(array_map('trim', $selected)));

    // Un document de décaissement ne peut provenir que d'un point arrêté.
    // Cet ancien export libre est conservé comme passerelle sécurisée vers le PDF officiel du point.
    if (!$selected) {
        http_response_code(409);
        exit('Export libre interdit. Arrêtez d’abord le point du jour, puis exportez le point provisoire ou le point signé par le Directeur Général.');
    }
    $pointMarks=implode(',',array_fill(0,count($selected),'?'));
    $pointStmt=$pdo->prepare("SELECT DISTINCT p.id FROM daily_disbursement_point_items i JOIN daily_disbursement_points p ON p.id=i.point_id WHERE i.fiche_number IN ($pointMarks) AND p.status IN ('stopped','certified','paid') ORDER BY p.id DESC");
    $pointStmt->execute($selected);$pointIds=array_map('intval',$pointStmt->fetchAll(PDO::FETCH_COLUMN));
    if(count($pointIds)!==1){http_response_code(409);exit('Ces fiches ne correspondent pas à un point unique arrêté. Exportez uniquement le point provisoire ou le point signé par le DG.');}
    $countStmt=$pdo->prepare("SELECT COUNT(DISTINCT fiche_number) FROM daily_disbursement_point_items WHERE point_id=? AND fiche_number IN ($pointMarks)");$countStmt->execute(array_merge([$pointIds[0]],$selected));
    if((int)$countStmt->fetchColumn()!==count($selected)){http_response_code(409);exit('La sélection contient des fiches qui ne sont pas intégrées au point arrêté.');}
    header('Location: export_daily_point_pdf.php?id='.$pointIds[0]);exit;

    $filters = [
        'beneficiaire' => $_GET['beneficiaire'] ?? null,
        'beneficiaire_mode' => $_GET['beneficiaire_mode'] ?? 'include',
        'affectation'  => $_GET['affectation'] ?? null,
        'affectation_mode' => $_GET['affectation_mode'] ?? 'include',
        'designation'  => $_GET['designation'] ?? null,
        'designation_mode' => $_GET['designation_mode'] ?? 'include',
        'montant_min'  => $_GET['montant_min'] ?? null,
        'montant_max'  => $_GET['montant_max'] ?? null,
        'date_min'     => $_GET['date_min'] ?? null,
        'date_max'     => $_GET['date_max'] ?? null,
        'chantier'     => $_GET['chantier'] ?? null,
        'chantier_mode' => $_GET['chantier_mode'] ?? 'include',
    ];

    // Requête enrichie
    if (!empty($selected)) {
        $in = implode(',', array_fill(0, count($selected), '?'));
        $sql = "SELECT f.*, p.details_demande AS parent_details_demande, c.titre AS chantier_titre, c.code AS chantier_code,
                             ldb.designation AS ligne_designation, ldb.categorie AS ligne_categorie, ldb.montant AS ligne_montant,
                   ld.designation AS devis_designation,
                   COALESCE(agg.total_decaisse_ligne, 0) AS total_decaisse_ligne
                FROM fiche_decaissement f
            {$parentJoinSql}
                LEFT JOIN chantier_banamur c ON c.id = f.chantier_id
                LEFT JOIN ligne_debourse_banamur ldb ON ldb.id = f.ligne_debourse_id
                LEFT JOIN debourse_banamur db ON db.id = ldb.debourse_id
                LEFT JOIN ligne_devis_banamur ld ON ld.id = db.ligne_devis_id
                                LEFT JOIN (
                                        SELECT ligne_debourse_id, SUM(montant_fiche) AS total_decaisse_ligne
                                        FROM fiche_decaissement
                                        WHERE decaisse = 1
                                        GROUP BY ligne_debourse_id
                                ) agg ON agg.ligne_debourse_id = f.ligne_debourse_id
                WHERE f.conforme = 1 AND f.approuve = 1 AND f.etat_fiche = 0 AND f.decaisse = 0
                  AND f.num_fiche IN ($in)
                  AND (f.chantier_id IS NULL OR f.chantier_id=0 OR c.archived_at IS NULL)
                ORDER BY f.date_creat_fiche DESC";
        $st = $pdo->prepare($sql);
        $st->execute($selected);
    } else {
        $where = [
            'f.conforme = 1',
            'f.approuve = 1',
            'f.etat_fiche = 0',
            'f.decaisse = 0',
            '(f.date_decaissement_minimum IS NULL OR f.date_decaissement_minimum <= CURDATE())'
        ];
        $where[] = '(f.chantier_id IS NULL OR f.chantier_id=0 OR c.archived_at IS NULL)';
        $params = [];
        foreach (['beneficiaire' => 'f.beficiaire_fiche', 'designation' => 'f.designation_fiche'] as $key => $column) {
            $values = array_values(array_filter(array_map('trim', explode(',', (string)($filters[$key] ?? '')))));
            if ($values) {
                $parts = [];
                foreach ($values as $index => $value) {
                    $param = $key . $index;
                    $parts[] = "$column LIKE :$param";
                    $params[$param] = '%' . $value . '%';
                }
                $group = '(' . implode(' OR ', $parts) . ')';
                $where[] = (($filters[$key . '_mode'] ?? 'include') === 'exclude') ? "NOT $group" : $group;
            }
        }
        if (!empty($filters['montant_min'])) {
            $where[] = 'f.montant_fiche >= :mmin';
            $params['mmin'] = (float)$filters['montant_min'];
        }
        if (!empty($filters['montant_max'])) {
            $where[] = 'f.montant_fiche <= :mmax';
            $params['mmax'] = (float)$filters['montant_max'];
        }
        if (!empty($filters['date_min'])) {
            $where[] = 'DATE(f.date_creat_fiche) >= :dmin';
            $params['dmin'] = $filters['date_min'];
        }
        if (!empty($filters['date_max'])) {
            $where[] = 'DATE(f.date_creat_fiche) <= :dmax';
            $params['dmax'] = $filters['date_max'];
        }
        if (!empty($filters['chantier'])) {
            $parts = [];
            foreach (array_values(array_filter(array_map('trim', explode(',', (string)$filters['chantier'])))) as $index => $value) {
                $param = 'chantier' . $index;
                $parts[] = "(c.titre LIKE :$param OR c.code LIKE :$param)";
                $params[$param] = '%' . $value . '%';
            }
            if ($parts) {
                $group = '(' . implode(' OR ', $parts) . ')';
                $where[] = (($filters['chantier_mode'] ?? 'include') === 'exclude') ? "NOT $group" : $group;
            }
        }
        if (!empty($filters['affectation'])) {
            $mapping = ['chantier' => 'f.chantier_id IS NOT NULL', 'bureau' => 'f.serv_bureau_banamur_id IS NOT NULL AND f.chantier_id IS NULL', 'autre' => 'f.chantier_id IS NULL AND f.serv_bureau_banamur_id IS NULL'];
            $parts = [];
            foreach (array_filter(array_map('trim', explode(',', (string)$filters['affectation']))) as $value) {
                $normalized = strtolower($value);
                if (isset($mapping[$normalized])) $parts[] = '(' . $mapping[$normalized] . ')';
            }
            if ($parts) {
                $group = '(' . implode(' OR ', $parts) . ')';
                $where[] = (($filters['affectation_mode'] ?? 'include') === 'exclude') ? "NOT $group" : $group;
            }
        }
        $sql = "SELECT f.*, p.details_demande AS parent_details_demande, c.titre AS chantier_titre, c.code AS chantier_code,
                       ldb.designation AS ligne_designation, ldb.categorie AS ligne_categorie, ldb.montant AS ligne_montant,
                       ld.designation AS devis_designation,
                       COALESCE(agg.total_decaisse_ligne, 0) AS total_decaisse_ligne
                FROM fiche_decaissement f
            {$parentJoinSql}
                LEFT JOIN chantier_banamur c ON c.id = f.chantier_id
                LEFT JOIN ligne_debourse_banamur ldb ON ldb.id = f.ligne_debourse_id
                LEFT JOIN debourse_banamur db ON db.id = ldb.debourse_id
                LEFT JOIN ligne_devis_banamur ld ON ld.id = db.ligne_devis_id
                LEFT JOIN (
                    SELECT ligne_debourse_id, SUM(montant_fiche) AS total_decaisse_ligne
                    FROM fiche_decaissement
                    WHERE decaisse = 1
                    GROUP BY ligne_debourse_id
                ) agg ON agg.ligne_debourse_id = f.ligne_debourse_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY f.date_creat_fiche DESC";
        $st = $pdo->prepare($sql);
        $st->execute($params);
    }
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $complianceService=new DisbursementDocumentComplianceService($pdo);

    // PDF paysage + style
    $pdf = new PDFAchat('L', 'mm', 'A4');
    $pdf->SetCreator('Banamur');
    $pdf->SetAuthor('Banamur');
    $pdf->SetTitle('Export Fiches Prêtes pour Achat');
    $docId='FIN-DEC-ACH-'.date('Ymd-His').'-U'.(int)($_SESSION['user_id']??0);$pdf->companyPdfId=$docId;$pdf->companyPdfTitle='Fiches prêtes pour achat';$pdf->companyPdfType='État de validation achat';$pdf->companyPdfCode='FIN-DEC-ACHAT';$pdf->companyPdfQrPayload=json_encode(['document'=>$docId,'type'=>'fiches_achat','generated_at'=>date(DATE_ATOM)],JSON_UNESCAPED_UNICODE);
    $pdf->SetMargins(10, 38, 10);
    $pdf->SetAutoPageBreak(true, 25);
    $title = 'Fiches Prêtes pour Achat';
    if (!empty($filters['date_min']) || !empty($filters['date_max'])) {
        $title .= ' — ' . ($filters['date_min'] ?: '…') . ' → ' . ($filters['date_max'] ?: '…');
    }
    $pdf->AddPage();

    // Légende codes (utile en impression N/B)
    $pdf->SetFont('dejavusans', '', 8);
    $pdf->SetTextColor(55, 65, 81);
    $pdf->MultiCell(0, 5, 'Conformité : BCI obligatoire · au-delà de 250 000 FCFA, FNE obligatoire · PV obligatoire sauf acompte/avance.', 0, 'L', false, 1);
    $pdf->Ln(1);

    // Largeur totale (A4 paysage): 277mm (297 - marges 10/10)
    $columns = [
        ['key' => 'alr',   'label' => 'Alertes',         'w' => 14, 'align' => 'L'],
        ['key' => 'bud',   'label' => 'Budget',          'w' => 16, 'align' => 'L'],
        ['key' => 'num',   'label' => 'Num',             'w' => 20, 'align' => 'L'],
        ['key' => 'benef', 'label' => 'Bénéficiaire',    'w' => 22, 'align' => 'L'],
        ['key' => 'four',  'label' => 'Fournisseur',     'w' => 28, 'align' => 'L'],
        ['key' => 'chant', 'label' => 'Chantier',        'w' => 26, 'align' => 'L'],
        ['key' => 'ldb',   'label' => 'Ligne déboursé',  'w' => 26, 'align' => 'L'],
        ['key' => 'ldv',   'label' => 'Ligne devis',     'w' => 26, 'align' => 'L'],
        ['key' => 'prec',  'label' => 'Précision',       'w' => 26, 'align' => 'L'],
        ['key' => 'det',   'label' => 'Détails',         'w' => 29, 'align' => 'L'],
        ['key' => 'mnt',   'label' => 'Montant',         'w' => 22, 'align' => 'R'],
        ['key' => 'date',  'label' => 'Créée le',        'w' => 22, 'align' => 'C'],
    ];

    $pdf->SetFillColor(245, 247, 250);
    $pdf->SetDrawColor(220, 225, 230);
    $pdf->SetTextColor(33, 37, 41);
    $pdf->SetFont('dejavusans', 'B', 9);
    foreach ($columns as $col) {
        $pdf->Cell($col['w'], 8, $col['label'], 1, 0, 'C', true);
    }
    $pdf->Ln();

    // IMPORTANT: repasser en normal après l'entête (sinon tout le tableau reste en gras)
    $pdf->SetFont('dejavusans', '', 8);
    $pdf->SetTextColor(55, 65, 81);

    // Colonnes à mettre en gras sur les lignes (décision rapide)
    $boldKeys = [
        'alr' => true,
        'bud' => true,
        'prec' => true,
        'mnt' => true,
    ];

    $drawRow = function ($pdf, $columns, $data) use ($boldKeys) {
        $lineHeights = [];
        foreach ($columns as $col) {
            $isBold = !empty($boldKeys[$col['key']]);
            $pdf->SetFont('dejavusans', $isBold ? 'B' : '', 8);
            $lineHeights[] = $pdf->getStringHeight($col['w'], (string)($data[$col['key']] ?? ''));
        }
        $h = max($lineHeights);

        // Base (normal) avant calcul de saut de page
        $pdf->SetFont('dejavusans', '', 8);
        if ($pdf->GetY() + $h > ($pdf->getPageHeight() - 16)) {
            $pdf->AddPage();
            $pdf->SetFillColor(245, 247, 250);
            $pdf->SetDrawColor(220, 225, 230);
            $pdf->SetTextColor(33, 37, 41);
            $pdf->SetFont('dejavusans', 'B', 9);
            foreach ($columns as $col) {
                $pdf->Cell($col['w'], 8, $col['label'], 1, 0, 'C', true);
            }
            $pdf->Ln();
            $pdf->SetFont('dejavusans', '', 8);
            $pdf->SetTextColor(55, 65, 81);
        }
        foreach ($columns as $col) {
            $isBold = !empty($boldKeys[$col['key']]);
            $pdf->SetFont('dejavusans', $isBold ? 'B' : '', 8);
            $pdf->MultiCell($col['w'], $h, (string)($data[$col['key']] ?? ''), 1, $col['align'], false, 0, '', '', true, 0, false, true, $h, 'M');
        }
        $pdf->Ln();

        // Revenir en normal pour la suite
        $pdf->SetFont('dejavusans', '', 8);
    };

    $total = 0.0;
    foreach ($rows as $f) {
        $f = enrich_fiche_labels($f);
        $documentState=$complianceService->evaluate($f);
        $num   = $f['num_fiche'] ?? '';
        $supplierLabel = recap_supplier_label($f);
        $benef = $f['beficiaire_fiche'] ?? $f['beneficiaire_fiche'] ?? '';
        $chant = trim(($f['chantier_titre'] ?? '') . (!empty($f['chantier_code']) ? ' [' . $f['chantier_code'] . ']' : ''));
        $ldb = (string)($f['ligne_debourse_label'] ?? '');
        $ldv = (string)($f['ligne_devis_label'] ?? '');
        $prec  = (string)($f['precision_fiche'] ?? '');
        $details = (string)($f['details_label'] ?? $f['details_demande'] ?? $f['precision_fiche'] ?? '');
        $mont  = (float)($f['montant_fiche'] ?? 0);
        $datec = isset($f['date_creat_fiche']) ? date('Y-m-d', strtotime($f['date_creat_fiche'])) : '';

        // Décision rapide (N/B): alertes + budget
        $alerts = [];
        $ligneMontant = isset($f['ligne_montant']) ? (float)$f['ligne_montant'] : 0.0;
        $totalDecaisseLigne = isset($f['total_decaisse_ligne']) ? (float)$f['total_decaisse_ligne'] : 0.0;
        $ratio = ($ligneMontant > 0) ? (($totalDecaisseLigne + $mont) / $ligneMontant) : null;
        if ($ratio !== null && is_finite($ratio)) {
            if ($ratio > 1) $alerts[] = 'HB';
            elseif ($ratio >= 0.95) $alerts[] = 'LIM';
        }
        $hasBC = !empty($f['bc_id']) || !empty($f['bc_number']);
        $hasBR = !empty($f['br_id']);
        if ($hasBC && !$hasBR) $alerts[] = 'BR-';
        $cni = trim((string)($f['cni_beneficiaire'] ?? ''));
        $sig = trim((string)($f['signature_beneficiaire'] ?? ''));
        if ($cni === '') $alerts[] = 'CNI-';
        if ($sig === '') $alerts[] = 'SIGN-';
        $docs=[];foreach(['bci'=>'BCI','fne'=>'FNE','pv'=>'PV'] as $key=>$label)$docs[]=$label.($documentState['present'][$key]?' OK':($documentState['required'][$key]?' MANQ':' N/R'));if($documentState['advance'])$docs[]='ACOMPTE';$alertStr=implode(' · ',$docs).($alerts?' | '.implode(' ',array_values(array_unique($alerts))):'');
        $budStr = '-';
        if ($ratio !== null && is_finite($ratio)) {
            $budStr = ($ratio <= 1 ? 'OK ' : 'HB ') . number_format($ratio * 100, 1, ',', ' ') . '%';
        } elseif ($ligneMontant > 0) {
            $budStr = 'N/A';
        }

        $total += $mont;
        $drawRow($pdf, $columns, [
            'alr' => $alertStr,
            'bud' => $budStr,
            'num' => $num,
            'benef' => $benef,
            'four' => $supplierLabel !== '' ? $supplierLabel : '-',
            'chant' => $chant,
            'ldb' => $ldb,
            'ldv' => $ldv,
            'prec' => $prec,
            'det' => $details,
            'mnt' => number_format($mont, 0, ',', ' '),
            'date' => $datec,
        ]);
    }

    $pdf->Ln(1);
    $pdf->SetFont('dejavusans', 'B', 10);
    $pdf->SetTextColor(33, 37, 41);
    $leftWidth = 0;
    for ($i = 0; $i < count($columns) - 2; $i++) {
        $leftWidth += $columns[$i]['w'];
    }
    $pdf->Cell($leftWidth, 10, 'TOTAL', 1, 0, 'R');
    $pdf->Cell($columns[count($columns) - 2]['w'], 10, number_format($total, 0, ',', ' '), 1, 0, 'R');
    $pdf->Cell($columns[count($columns) - 1]['w'], 10, '', 1, 1, 'C');

    \AuditTrail::logFileEvent('export', [
        'module' => 'decaissement',
        'action' => 'fiches_achat_export',
        'entity_type' => 'fiche_achat_list',
        'entity_label' => 'Fiches prêtes achat',
        'format' => 'pdf',
        'details' => [
            'selection_count' => is_countable($selected ?? null) ? count($selected) : 0,
            'row_count' => is_countable($rows ?? null) ? count($rows) : null,
            'total' => $total,
        ],
    ], $pdo);
    $pdf->Output('export_fiches_achat_' . date('Ymd_His') . '.pdf', 'I');
    exit;
} catch (Throwable $e) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
