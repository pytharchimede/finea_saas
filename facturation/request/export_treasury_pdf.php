<?php
declare(strict_types=1);
require_once __DIR__.'/../../auth_check.php';require_once __DIR__.'/../../model/Database.php';require_once __DIR__.'/../../model/CompanyPdfTemplate.php';require_once __DIR__.'/../../services/ManualTreasuryService.php';require_once __DIR__.'/../../services/DailyDisbursementPointService.php';require_once __DIR__.'/../../vendor/autoload.php';
if(!(AccessManager::hasPermission('acces_tresorerie_lecture')||AccessManager::hasPermission('acces_direction')||AccessManager::hasPermission('acces_admin'))){http_response_code(403);exit('Accès refusé.');}
$from=preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)($_GET['from']??''))?(string)$_GET['from']:date('Y-m-01');$to=preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)($_GET['to']??''))?(string)$_GET['to']:date('Y-m-t');$view=(string)($_GET['view']??'projected');$group=in_array(($_GET['group']??''),['allocation','beneficiary','requester','chantier','ligne','categorie'],true)?(string)$_GET['group']:'list';$state=in_array(($_GET['state']??''),['pending','collected'],true)?(string)$_GET['state']:'';$decode=static fn($key)=>array_values(array_filter(array_map('strval',(array)json_decode((string)($_GET[$key]??'[]'),true))));$allocations=$decode('allocations');$beneficiaries=$decode('beneficiaries');$requesters=$decode('requesters');$chantiers=$decode('chantiers');
$pdo=Database::getConnection();$service=new ManualTreasuryService($pdo);$data=$service->dashboard($from,$to);$manual=static fn($d,$s)=>array_values(array_filter($data['manual'],static fn($r)=>($r['direction']??'')===$d&&($r['status']??'')===$s));$opening=$data['opening']&&$data['opening']['effective_date']>=$from&&$data['opening']['effective_date']<=$to?[['direction'=>'in','label'=>'Solde initial des entrées réelles','reference'=>'INITIALISATION','amount'=>$data['opening']['amount'],'movement_date'=>$data['opening']['effective_date']]]:[];$transfersOut=array_map(static fn($r)=>array_merge($r,['direction'=>'out','label'=>$r['label']?:'Transfert vers le disponible']),$data['transferRows']);$transfersIn=array_map(static fn($r)=>array_merge($r,['direction'=>'in','label'=>$r['label']?:'Transfert reçu des entrées réelles']),$data['transfersSinceOpening']);$title='État de trésorerie';$rows=[];$isGlobal=$view==='global';
if ($isGlobal) {
    $title = 'État global de trésorerie';
    $rows = array_merge(
        $opening,
        $manual('in', 'real'),
        $data['invoiceRealRows'],
        $transfersOut,
        $transfersIn,
        $data['febToDateRows'],
        $data['invoiceForecastRows'],
        $data['futureFebRows']
    );
} elseif ($view === 'available') {
    $title = 'Disponible réel du jour';
    $rows = array_merge($transfersIn, $manual('out', 'real'), $data['paidFichesPeriod']);
} elseif ($view === 'real-in') {
    $title = 'Solde des entrées réelles';
    $rows = array_merge($opening, $manual('in', 'real'), $data['invoiceRealRows'], $transfersOut);
} elseif ($view === 'forecast-in') {
    $title = 'Suivi des entrées prévisionnelles';
    $pendingForecast = array_map(
        static fn ($row) => array_merge($row, ['forecast_state' => 'pending']),
        $data['invoiceForecastRows']
    );
    $collectedForecast = array_map(
        static fn ($row) => array_merge($row, [
            'direction' => 'out',
            'forecast_state' => 'collected',
            'label' => ($row['label'] ?? 'Facture') . ' · sortie des prévisions après encaissement',
        ]),
        $data['invoiceRealRows']
    );
    $rows = array_merge($pendingForecast, $collectedForecast);
    if ($state !== '') {
        $rows = array_values(array_filter(
            $rows,
            static fn ($row) => ($row['forecast_state'] ?? '') === $state
        ));
    }
} elseif ($view === 'real-out') {
    $title = 'FEB à traiter';
    $rows = $data['febToDateRows'];
} elseif ($view === 'forecast-out') {
    $title = 'FEB à venir';
    $rows = $data['futureFebRows'];
} elseif ($view === 'validation-purchase') {
    $title = 'Décaissements en validation achat';
    $rows = $data['validationPurchaseRows'];
} elseif ($view === 'projected-out') {
    $title = 'Projection des déboursés — reste à payer';
    $rows = $data['projectedDebourseRows'];
} else {
    $rows = $data['invoiceForecastRows'];
}
$flow=static fn($r)=>trim((string)($r['assignment_label']??''))?:trim(implode(' · ',array_filter([(string)($r['chantier_code']??''),(string)($r['chantier_title']??''),(string)($r['ligne_devis_label']??'')])))?:'Sans affectation';$project=static fn($r)=>trim(implode(' · ',array_filter([(string)($r['chantier_code']??''),(string)($r['chantier_title']??'')])))?:'Chantier non renseigné';$isProjection=$view==='projected-out';$isForecastIn=$view==='forecast-in';$benef=static fn($r)=>trim((string)($r['beneficiary']??''))?:($isForecastIn?'Client non renseigné':'Bénéficiaire non renseigné');$requester=static fn($r)=>trim((string)($r['requester_name']??''))?:'Demandeur non renseigné';if($isForecastIn){$requesters=[];if($group==='requester')$group='list';}$rows=array_values(array_filter($rows,static fn($r)=>(!$allocations||in_array($flow($r),$allocations,true))&&(!$beneficiaries||in_array($benef($r),$beneficiaries,true))&&(!$requesters||in_array($requester($r),$requesters,true))&&(!$chantiers||in_array($project($r),$chantiers,true))));$getter=$isProjection?($group==='ligne'?static fn($r)=>(string)($r['ligne_debourse_label']??'Ligne de déboursé'):($group==='categorie'?static fn($r)=>(string)($r['ligne_debourse_category']??'Sans catégorie'):$project)):($group==='allocation'?$flow:($group==='beneficiary'?$benef:($group==='requester'?$requester:static fn()=> 'Tous les mouvements')));$groups=[];foreach($rows as $row)$groups[$getter($row)][]=$row;
$uid=(int)($_SESSION['user_id']??0);$exporter=trim((string)(($_SESSION['prenom']??'').' '.($_SESSION['nom']??'')))?:((string)($_SESSION['user_name']??'Utilisateur'));$docId='FIN-TRE-'.date('Ymd-His').'-U'.$uid;$signature=(new DailyDisbursementPointService($pdo))->profileSignature($uid);$money=static fn($v)=>number_format((float)$v,0,',',' ').' FCFA';$incoming=array_sum(array_map(static fn($r)=>(($r['direction']??'out')==='in')?(float)$r['amount']:0,$rows));$outgoing=array_sum(array_map(static fn($r)=>(($r['direction']??'out')!=='in')?(float)$r['amount']:0,$rows));
class TreasuryStyledPdf extends TCPDF
{
 use CompanyPdfTemplate;
 public function Header(){ $this->drawCompanyPdfHeader(); }
 public function Footer(){ $this->drawCompanyPdfFooter(); }
}
function card(TCPDF $pdf,float $x,float $y,float $w,string $label,string $value,array $rgb):void{$pdf->SetFillColor(248,250,252);$pdf->SetDrawColor(226,232,240);$pdf->RoundedRect($x,$y,$w,18,3,'1111','DF');$pdf->SetFillColor(...$rgb);$pdf->RoundedRect($x,$y,3,18,1,'1001','F');$contentWidth=$w-12;$pdf->SetXY($x+6,$y+3);$pdf->SetFont('dejavusans','',7);$pdf->SetTextColor(100,116,139);$pdf->Cell($contentWidth,4,$label,0,1,'L',false);$valueSize=10.0;$pdf->SetFont('dejavusans','B',$valueSize);while($valueSize>7.0&&$pdf->GetStringWidth($value)>$contentWidth){$valueSize-=0.25;$pdf->SetFont('dejavusans','B',$valueSize);}$pdf->SetXY($x+6,$y+8);$pdf->SetTextColor(15,23,42);$pdf->Cell($contentWidth,6,$value,0,1,'L',false);}
function tableHeader(TCPDF $pdf,array $cols):void{$pdf->SetFillColor(250,189,2);$pdf->SetTextColor(0,0,0);$pdf->SetFont('dejavusans','B',6.7);foreach($cols as [$label,$width,$align])$pdf->MultiCell($width,8,$label,1,$align,true,0,'','',true,0,false,true,8,'M');$pdf->Ln();}
function tableRow(TCPDF $pdf,array $cells,array $cols):void{$heights=[];$pdf->SetFont('dejavusans','',6.6);foreach($cells as $i=>$text)$heights[]=$pdf->getStringHeight($cols[$i][1]-2,(string)$text);$h=max(8,min(18,max($heights)+2));if($pdf->GetY()+$h>185){$pdf->AddPage();$pdf->SetY(40);tableHeader($pdf,$cols);}$fill=((int)($pdf->GetY()*10)%2)===0;$pdf->SetFillColor(248,250,252);$pdf->SetTextColor(51,65,85);$lastCell=count($cells)-1;foreach($cells as $i=>$text){$font=$i===$lastCell?'B':'';$pdf->SetFont('dejavusans',$font,6.6);$pdf->MultiCell($cols[$i][1],$h,(string)$text,1,$cols[$i][2],$fill,0,'','',true,0,false,true,$h,'M');}$pdf->Ln();}
$pdf=new TreasuryStyledPdf('L','mm','A4',true,'UTF-8',false);$pdf->companyPdfId=$docId;$pdf->companyPdfTitle=$title;$pdf->companyPdfType='État de trésorerie';$pdf->companyPdfCode='FIN-TRE-ETAT';$pdf->companyPdfQrPayload=json_encode(['document'=>$docId,'type'=>'etat_tresorerie','period'=>[$from,$to],'exporter'=>$exporter,'generated_at'=>date(DATE_ATOM),'rows'=>count($rows)],JSON_UNESCAPED_UNICODE);$pdf->SetCreator('FINEA');$pdf->SetAuthor($exporter);$pdf->SetTitle($title.' — '.$docId);$pdf->SetMargins(14,38,14);$pdf->SetAutoPageBreak(true,25);$pdf->AddPage();
if ($isGlobal) {
    $totals = $data['totals'];
    card($pdf, 14, 41, 64, 'PÉRIODE CHOISIE', 'Du ' . date('d/m/Y', strtotime($from)) . ' au ' . date('d/m/Y', strtotime($to)), [79, 70, 229]);
    card($pdf, 82, 41, 61, 'DISPONIBLE HORS TVA', $money($data['available']), [16, 185, 129]);
    card($pdf, 147, 41, 61, 'VALIDATION ACHAT', $money($totals['validation_purchase']), [14, 165, 233]);
    card($pdf, 212, 41, 71, 'SOLDE ENTRÉES RÉELLES', $money($data['realEntriesBalance']), [245, 158, 11]);
    card($pdf, 14, 62, 64, 'ENTRÉES RÉELLES', $money($totals['real_in']), [16, 185, 129]);
    card($pdf, 82, 62, 61, 'FICHES À DÉCAISSER', $money($totals['real_out']), [239, 68, 68]);
    card($pdf, 147, 62, 61, 'ENTRÉES PRÉVISIONNELLES', $money($totals['forecast_in']), [6, 182, 212]);
    card($pdf, 212, 62, 71, 'FICHES PLANIFIÉES', $money($totals['forecast_out']), [245, 158, 11]);
    $vat=$data['vat']??[];
    card($pdf, 14, 83, 64, 'TVA COLLECTÉE', $money($vat['vat_collected']??0), [6, 182, 212]);
    card($pdf, 82, 83, 61, 'TVA PROTÉGÉE', $money($vat['vat_protected']??0), [14, 165, 233]);
    card($pdf, 147, 83, 61, 'TVA EMPRUNTÉE', $money($vat['vat_outstanding']??0), [245, 158, 11]);
    card($pdf, 212, 83, 71, 'TVA MOBILISABLE', $money($vat['vat_free_to_borrow']??0), [79, 70, 229]);
    $pdf->SetY(105);
} else {
    card($pdf, 14, 41, 64, 'PÉRIODE', 'Du ' . date('d/m/Y', strtotime($from)) . ' au ' . date('d/m/Y', strtotime($to)), [79, 70, 229]);
    card($pdf, 82, 41, 61, 'ENTRÉES', $money($incoming), [16, 185, 129]);
    card($pdf, 147, 41, 61, 'SORTIES', $money($outgoing), [239, 68, 68]);
    card($pdf, 212, 41, 71, 'SOLDE NET DE LA VUE', $money($incoming - $outgoing), $incoming - $outgoing >= 0 ? [14, 165, 233] : [245, 158, 11]);
    $pdf->SetY(64);
}

$filterText = [];
if ($state) $filterText[] = 'État : ' . ($state === 'collected' ? 'Déjà encaissées' : 'À percevoir');
if ($allocations) $filterText[] = ($isForecastIn ? 'Chantiers' : 'Affectations') . ' : ' . implode(', ', $allocations);
if ($beneficiaries) $filterText[] = ($isForecastIn ? 'Clients' : 'Bénéficiaires') . ' : ' . implode(', ', $beneficiaries);
if ($requesters) $filterText[] = 'Demandeurs : ' . implode(', ', $requesters);

$pdf->SetFillColor(255, 248, 214);
$pdf->SetDrawColor(250, 189, 2);
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('dejavusans', '', 7);
$defaultFilterText = $isGlobal
    ? 'État global · période du ' . date('d/m/Y', strtotime($from)) . ' au ' . date('d/m/Y', strtotime($to)) . ' · situation du jour au ' . date('d/m/Y')
    : 'Vue complète — aucun filtre nominatif appliqué';
$pdf->MultiCell(269, 8, $filterText ? implode('  |  ', $filterText) : $defaultFilterText, 1, 'L', true, 1, '', '', true, 0, false, true, 8, 'M');
$pdf->Ln(3);
$cols=$isProjection?[['CHANTIER',48,'L'],['DÉBOURSÉ / LIGNE',61,'L'],['PRÉVISION',31,'R'],['DÉJÀ PAYÉ',31,'R'],['ENGAGÉ',31,'R'],['RESTE À PAYER',36,'R'],['RÉF.',31,'L']]:($isForecastIn?[['DATE',25,'C'],['ÉTAT',28,'C'],['FACTURE / RÉFÉRENCE',70,'L'],['CHANTIER',72,'L'],['CLIENT',49,'L'],['MONTANT',25,'R']]:[['DATE',23,'C'],['SENS',21,'C'],['LIBELLÉ / RÉFÉRENCE',55,'L'],['AFFECTATION',61,'L'],['BÉNÉFICIAIRE',42,'L'],['DEMANDEUR',42,'L'],['MONTANT',25,'R']]);
foreach($groups as $label=>$items){$subtotal=$isProjection?array_sum(array_map(static fn($r)=>(float)$r['amount'],$items)):array_sum(array_map(static fn($r)=>(($r['direction']??'out')==='in'?1:-1)*(float)$r['amount'],$items));if($pdf->GetY()>172)$pdf->AddPage();$pdf->SetFillColor(255,248,214);$pdf->SetTextColor(0,0,0);$pdf->SetFont('dejavusans','B',8);$pdf->MultiCell(215,8,$label.' · '.count($items).($isProjection?' ligne(s) de déboursé':($isForecastIn?' facture(s)':' mouvement(s)')),1,'L',true,0);$pdf->SetTextColor($isProjection?190:($subtotal>=0?4:190),$isProjection?24:($subtotal>=0?120:24),$isProjection?93:($subtotal>=0?87:93));$pdf->MultiCell(54,8,($isProjection?'Reste : ':($subtotal>=0?'+ ':'− ')).$money(abs($subtotal)),1,'R',true,1);tableHeader($pdf,$cols);foreach($items as $row){$cells=$isProjection?[$project($row),trim((string)($row['ligne_debourse_label']??$row['label']??'').' · '.(string)($row['ligne_devis_label']??'')),$money($row['budget_amount']??0),$money($row['paid_amount']??0),$money($row['committed_amount']??0),$money($row['amount']??0),(string)($row['reference']??'')]:($isForecastIn?[date('d/m/Y',strtotime((string)$row['movement_date'])),(($row['direction']??'out')==='in'?'À PERCEVOIR':'ENCAISSÉE'),trim((string)($row['label']??'Facture').' · '.(string)($row['reference']??'')),$flow($row),$benef($row),$money($row['amount'])]:[date('d/m/Y',strtotime((string)$row['movement_date'])),(($row['direction']??'out')==='in'?'ENTRÉE':'SORTIE'),trim((string)($row['label']??'').' · '.(string)($row['reference']??'')),$flow($row),$benef($row),$requester($row),$money($row['amount'])]);tableRow($pdf,$cells,$cols);}$pdf->Ln(4);}
if(!$rows){$pdf->SetFillColor(248,250,252);$pdf->SetDrawColor(203,213,225);$pdf->SetTextColor(100,116,139);$pdf->SetFont('dejavusans','',10);$pdf->MultiCell(269,24,'Aucun mouvement ne correspond aux filtres sélectionnés.',1,'C',true,1,'','',true,0,false,true,24,'M');}
if($pdf->GetY()>155)$pdf->AddPage();$pdf->Ln(5);$y=$pdf->GetY();$pdf->SetFillColor(248,250,252);$pdf->SetDrawColor(203,213,225);$pdf->RoundedRect(14,$y,269,27,3,'1111','DF');$pdf->SetXY(19,$y+4);$pdf->SetFont('dejavusans','B',8);$pdf->SetTextColor(15,23,42);$pdf->Cell(165,5,'CERTIFICATION DE L’EXPORT',0,1);$pdf->SetX(19);$pdf->SetFont('dejavusans','',7);$pdf->SetTextColor(71,85,105);$pdf->Cell(165,4,'Exporté par : '.$exporter,0,1);$pdf->SetX(19);$pdf->Cell(165,4,'Date et heure : '.date('d/m/Y H:i').' · Identifiant : '.$docId,0,1);$abs=$signature?realpath(dirname(__DIR__,2).'/'.ltrim($signature,'/')):false;$pdf->SetXY(210,$y+3);$pdf->SetFont('dejavusans','B',7);$pdf->Cell(67,4,'SIGNATURE DE L’EXPORTATEUR',0,1,'C');if($abs&&is_file($abs))$pdf->Image($abs,228,$y+8,30,14,'','','',false,300);else{$pdf->SetXY(210,$y+12);$pdf->SetFont('dejavusans','I',6.5);$pdf->SetTextColor(148,163,184);$pdf->Cell(67,5,'Aucune signature enregistrée au profil',0,1,'C');}
$pdf->Output('etat_tresorerie_'.$docId.'.pdf','I');
