<?php
require_once __DIR__ . '/../../auth_check.php';
\AccessManager::requirePageAccess();
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/FicheDecaissement.php';
require_once __DIR__ . '/../../model/AuditTrail.php';
require_once __DIR__ . '/../../model/CompanyPdfTemplate.php';

final class PDFComptaFinea extends TCPDF
{
    use CompanyPdfTemplate;
    public function Header(): void
    {
        $this->drawCompanyPdfHeader();
    }

    public function Footer(): void
    {
        $this->drawCompanyPdfFooter();
    }
}

function comptaPdfFilters(): array
{
    $keys = ['beneficiaire','num_fiche','designation','precision','montant_min','montant_max','date_min','date_max','date_valid_min','date_valid_max','chantier','entreprise','ligne','affectation','status_filter'];
    $filters = [];
    foreach ($keys as $key) $filters[$key] = trim((string)($_GET[$key] ?? ''));
    return $filters;
}

function regularizationLabels(PDO $pdo, array $numbers): array
{
    $result = [];
    if (!$numbers || !$pdo->query("SHOW TABLES LIKE 'disbursement_regularizations'")->fetchColumn()) return $result;
    $in = implode(',', array_fill(0, count($numbers), '?'));
    $stmt = $pdo->prepare("SELECT num_fiche, submitted_at, accounting_status, completion_percentage FROM disbursement_regularizations WHERE num_fiche IN ($in)");
    $stmt->execute($numbers);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $result[(string)$row['num_fiche']] = $row;
    return $result;
}

try {
    $pdo = Database::getConnection();
    $model = new FicheDecaissement($pdo);
    $filters = comptaPdfFilters();
    $mode = (string)($_GET['mode'] ?? '');
    $single = trim((string)($_GET['num_fiche'] ?? ''));
    if ($single !== '') {
        $one = $model->getByNum($single);
        $rows = $one ? [$one] : [];
    } else {
        $rows = $mode === 'all' ? $model->listComptaAll($filters) : $model->listComptaReady($filters);
    }

    $numbers = array_values(array_unique(array_filter(array_map(static fn(array $row): string => (string)($row['num_fiche'] ?? ''), $rows))));
    $regularizations = regularizationLabels($pdo, $numbers);
    $statusLabels = ['not_submitted'=>'À transmettre','pending'=>'À valider','approved'=>'Régularisés','rejected'=>'À corriger'];
    $summaryTotal=array_sum(array_map(static fn(array $row):float=>(float)($row['montant_fiche']??0),$rows));
    $summaryPaid=count(array_filter($rows,static fn(array $row):bool=>!empty($row['decaisse'])));
    $summaryReady=count(array_filter($rows,static fn(array $row):bool=>empty($row['decaisse'])&&!empty($row['etat_fiche'])));
    $documentId='FIN-COMPTA-'.date('Ymd-His').($single!==''?'-'.preg_replace('/[^A-Za-z0-9-]/','',$single):'');

    $pdf = new PDFComptaFinea('L', 'mm', 'A4');
    $pdf->companyPdfTitle=$single!==''?'Fiche de suivi comptable':'État de traitement comptable';
    $pdf->companyPdfType=$single!==''?'Suivi comptable individuel':'État comptable consolidé';
    $pdf->companyPdfId=$documentId;
    $pdf->companyPdfCode='FIN-DEC-COMPTA';
    $pdf->companyPdfQrPayload=json_encode(['document'=>$documentId,'type'=>'fiches_compta','mode'=>$mode==='all'?'all':'ready','fiche'=>$single,'count'=>count($rows),'total'=>$summaryTotal,'filters'=>$filters],JSON_UNESCAPED_UNICODE);
    $pdf->SetCreator('FINEA');
    $pdf->SetAuthor('Comptabilité');
    $pdf->SetTitle('État de traitement comptable Finéa');
    $pdf->SetMargins(11, 38, 11);
    $pdf->SetAutoPageBreak(true, 25);
    $pdf->AddPage();
    $drawCard=static function(TCPDF $pdf,float $x,string $label,string $value,array $accent):void{$y=39;$w=65;$pdf->SetFillColor(248,250,252);$pdf->SetDrawColor(226,232,240);$pdf->RoundedRect($x,$y,$w,18,3,'1111','DF');$pdf->SetFillColor(...$accent);$pdf->RoundedRect($x,$y,3,18,1,'1001','F');$pdf->SetXY($x+7,$y+3);$pdf->SetFont('dejavusans','B',6.5);$pdf->SetTextColor(100,116,139);$pdf->Cell($w-10,4,mb_strtoupper($label),0,1,'L');$pdf->SetX($x+7);$pdf->SetFont('dejavusans','B',10);$pdf->SetTextColor(15,23,42);$pdf->Cell($w-10,7,$value,0,1,'L',false,'',1);};
    $drawCard($pdf,11,'Fiches',number_format(count($rows),0,',',' '),[79,70,229]);
    $drawCard($pdf,80,'Montant total',number_format($summaryTotal,0,',',' ').' FCFA',[14,165,233]);
    $drawCard($pdf,149,'Prêtes compta',number_format($summaryReady,0,',',' '),[245,158,11]);
    $drawCard($pdf,218,'Décaissées',number_format($summaryPaid,0,',',' '),[16,185,129]);
    $pdf->SetY(62);$pdf->SetFillColor(241,245,249);$pdf->SetDrawColor(203,213,225);$pdf->SetTextColor(71,85,105);$pdf->SetFont('dejavusans','',7.5);$scope=$single!==''?'Fiche ciblée : '.$single:($mode==='all'?'Toutes les fiches comptables':'Fiches prêtes pour traitement comptable');$pdf->MultiCell(0,8,$scope.' · Généré le '.date('d/m/Y à H:i'),1,'L',true,1,'','',true,0,false,true,8,'M');$pdf->Ln(3);

    if (!$rows) {
        $pdf->SetFont('dejavusans', '', 11);
        $pdf->Cell(0, 12, 'Aucune fiche ne correspond aux critères de cette vue.', 1, 1, 'C');
    } else {
        $html = '<table border="1" cellpadding="4"><thead><tr style="background-color:#172554;color:#ffffff;font-weight:bold">'
            .'<th width="11%">Fiche</th><th width="14%">Bénéficiaire</th><th width="17%">Chantier / objet</th>'
            .'<th width="12%" align="right">Montant fiche</th><th width="12%" align="right">Ligne devis</th>'
            .'<th width="9%" align="center">Ratio</th><th width="15%">Justificatifs</th><th width="10%">État</th></tr></thead><tbody>';
        $total = 0.0;
        foreach ($rows as $row) {
            $num = (string)($row['num_fiche'] ?? '');
            $analysis = $model->analyseBudgetFiche($num);
            $lineAmount = $analysis ? (float)$analysis['montant_ligne'] : 0.0;
            $ratio = $analysis ? (float)$analysis['ratio'] * 100 : null;
            $reg = $regularizations[$num] ?? null;
            $regKey = empty($row['decaisse']) ? 'not_applicable' : (!$reg || empty($reg['submitted_at']) ? 'not_submitted' : (string)($reg['accounting_status'] ?? 'pending'));
            $regLabel = $regKey === 'not_applicable' ? 'Après paiement' : ($statusLabels[$regKey] ?? 'À valider');
            if ($reg && $reg['completion_percentage'] !== null) $regLabel .= ' ('.number_format((float)$reg['completion_percentage'], 0, ',', ' ').' %)';
            $state = !empty($row['decaisse']) ? 'Décaissée' : (!empty($row['etat_fiche']) ? 'Prête compta' : 'Pré-achat');
            $project = trim((string)($row['chantier_titre'] ?? '')) ?: trim((string)($row['designation_fiche'] ?? ''));
            $total += (float)($row['montant_fiche'] ?? 0);
            $e = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html .= '<tr><td><b>'.$e($num).'</b><br><small>'.$e(isset($row['date_creat_fiche']) ? date('d/m/Y', strtotime((string)$row['date_creat_fiche'])) : '').'</small></td>'
                .'<td>'.$e($row['beficiaire_fiche'] ?? '').'</td><td>'.$e($project).'</td>'
                .'<td align="right"><b>'.number_format((float)($row['montant_fiche'] ?? 0), 0, ',', ' ').'</b></td>'
                .'<td align="right">'.($analysis ? number_format($lineAmount, 0, ',', ' ') : 'N/D').'</td>'
                .'<td align="center"><b>'.($ratio === null ? 'N/D' : number_format($ratio, 1, ',', ' ').' %').'</b></td>'
                .'<td>'.$e($regLabel).'</td><td>'.$e($state).'</td></tr>';
        }
        $html .= '<tr style="background-color:#f1f5f9;font-weight:bold"><td colspan="3" align="right">TOTAL</td><td align="right">'.number_format($total, 0, ',', ' ').' FCFA</td><td colspan="4"></td></tr></tbody></table>';
        $pdf->SetFont('dejavusans', '', 7.5);
        $pdf->SetTextColor(30, 41, 59);
        $pdf->writeHTML($html, true, false, true, false, '');
        $pdf->Ln(3);
        $pdf->SetFont('dejavusans', '', 7.5);
        $pdf->SetTextColor(80, 90, 110);
        $pdf->MultiCell(0, 4, 'Ratio = (décaissements déjà réalisés sur la ligne + montant de la fiche) / montant budgété de la ligne de devis. Les justificatifs deviennent exigibles après décaissement.', 0, 'L');
    }

    AuditTrail::logFileEvent('export', ['module'=>'decaissement','action'=>'fiches_compta_export','entity_type'=>'fiche_compta_list','entity_label'=>'Traitement comptable Finéa','format'=>'pdf','details'=>['mode'=>$mode === 'all' ? 'all' : 'ready','count'=>count($rows),'filters'=>$filters]], $pdo);
    $pdf->Output(($single !== '' ? 'fiche_compta_'.$single : 'traitement_comptable_finea_'.date('Ymd_His')).'.pdf', 'I');
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Export PDF impossible : '.$e->getMessage();
}
