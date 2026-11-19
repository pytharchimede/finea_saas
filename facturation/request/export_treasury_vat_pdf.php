<?php
declare(strict_types=1);
require_once __DIR__.'/../../auth_check.php';
require_once __DIR__.'/../../model/Database.php';
require_once __DIR__.'/../../model/CompanyPdfTemplate.php';
require_once __DIR__.'/../../services/ManualTreasuryService.php';
require_once __DIR__.'/../../services/DailyDisbursementPointService.php';
require_once __DIR__.'/../../vendor/autoload.php';

if(!(AccessManager::hasPermission('acces_tresorerie_lecture')||AccessManager::hasPermission('acces_direction')||AccessManager::hasPermission('acces_admin'))){http_response_code(403);exit('Accès refusé.');}
$from=preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)($_GET['from']??''))?(string)$_GET['from']:date('Y-m-01');
$to=preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)($_GET['to']??''))?(string)$_GET['to']:date('Y-m-t');
$pdo=Database::getConnection();$data=(new ManualTreasuryService($pdo))->dashboard($from,$to);$vat=$data['vat']??[];
$rows=array_values(array_filter((array)($vat['snapshots']??[]),static fn(array $r):bool=>(string)($r['snapshot_date']??'')>=$from&&(string)($r['snapshot_date']??'')<=$to));
$uid=(int)($_SESSION['user_id']??0);$exporter=trim((string)(($_SESSION['prenom']??'').' '.($_SESSION['nom']??'')))?:((string)($_SESSION['user_name']??'Utilisateur'));$docId='FIN-TVA-'.date('Ymd-His').'-U'.$uid;$signature=(new DailyDisbursementPointService($pdo))->profileSignature($uid);
class TreasuryVatPdf extends TCPDF {use CompanyPdfTemplate;public function Header(){$this->drawCompanyPdfHeader();}public function Footer(){$this->drawCompanyPdfFooter();}}
$money=static fn($v)=>number_format((float)$v,0,',',' ').' FCFA';$ht=array_sum(array_map(static fn($r)=>(float)$r['amount_ht'],$rows));$tva=array_sum(array_map(static fn($r)=>(float)$r['amount_vat'],$rows));$ttc=array_sum(array_map(static fn($r)=>(float)$r['amount_ttc'],$rows));
$pdf=new TreasuryVatPdf('L','mm','A4',true,'UTF-8',false);$pdf->companyPdfId=$docId;$pdf->companyPdfTitle='TVA collectée';$pdf->companyPdfType='Récapitulatif fiscal de trésorerie';$pdf->companyPdfCode='FIN-TRE-TVA';$pdf->companyPdfQrPayload=json_encode(['document'=>$docId,'type'=>'tva_collectee','period'=>[$from,$to],'exporter'=>$exporter,'generated_at'=>date(DATE_ATOM)],JSON_UNESCAPED_UNICODE);$pdf->SetCreator('FINEA');$pdf->SetAuthor($exporter);$pdf->SetTitle('TVA collectée — '.$docId);$pdf->SetMargins(14,38,14);$pdf->SetAutoPageBreak(true,25);$pdf->AddPage();
$card=static function(TCPDF $pdf,float $x,string $label,string $value):void{$pdf->SetFillColor(248,250,252);$pdf->SetDrawColor(226,232,240);$pdf->RoundedRect($x,42,62,18,3,'1111','DF');$pdf->SetXY($x+5,45);$pdf->SetFont('dejavusans','',7);$pdf->SetTextColor(100,116,139);$pdf->Cell(52,4,$label);$pdf->SetXY($x+5,50);$pdf->SetFont('dejavusans','B',10);$pdf->SetTextColor(15,23,42);$pdf->Cell(52,6,$value);};
$card($pdf,14,'HT ENCAISSÉ',$money($ht));$card($pdf,80,'TVA COLLECTÉE',$money($tva));$card($pdf,146,'TTC ENCAISSÉ',$money($ttc));$card($pdf,212,'RÉSERVE TVA PROTÉGÉE',$money($vat['vat_protected']??0));
$pdf->SetY(65);$pdf->SetFillColor(236,254,255);$pdf->SetDrawColor(103,232,249);$pdf->SetTextColor(14,116,144);$pdf->SetFont('dejavusans','B',7);$pdf->MultiCell(260,8,'Période : '.date('d/m/Y',strtotime($from)).' au '.date('d/m/Y',strtotime($to)).' · Factures clients réellement encaissées · '.$docId,1,'L',true);
$pdf->Ln(3);$cols=[['DATE',25,'C'],['FACTURE',42,'L'],['CLIENT',55,'L'],['CHANTIER',55,'L'],['HT',27,'R'],['TVA',27,'R'],['TTC',29,'R']];
$header=static function(TCPDF $pdf,array $cols):void{$pdf->SetFillColor(14,116,144);$pdf->SetTextColor(255,255,255);$pdf->SetFont('dejavusans','B',6.8);foreach($cols as [$l,$w,$a])$pdf->MultiCell($w,8,$l,1,$a,true,0,'','',true,0,false,true,8,'M');$pdf->Ln();};$header($pdf,$cols);
foreach($rows as $r){if($pdf->GetY()>180){$pdf->AddPage();$pdf->SetY(42);$header($pdf,$cols);}$chantier=trim(implode(' · ',array_filter([(string)($r['chantier_code']??''),(string)($r['chantier_title']??'')])))?:'Sans chantier';$cells=[date('d/m/Y',strtotime((string)$r['snapshot_date'])),(string)($r['reference_interne']??('Facture #'.$r['invoice_id'])),(string)($r['client_company_name']??''),$chantier,$money($r['amount_ht']),$money($r['amount_vat']),$money($r['amount_ttc'])];$pdf->SetFont('dejavusans','',6.4);$pdf->SetTextColor(51,65,85);foreach($cells as $i=>$v)$pdf->MultiCell($cols[$i][1],9,$v,1,$cols[$i][2],false,0,'','',true,0,false,true,9,'M');$pdf->Ln();}
$pdf->SetFillColor(207,250,254);$pdf->SetTextColor(8,51,68);$pdf->SetFont('dejavusans','B',7);foreach(['TOTAL','','','',$money($ht),$money($tva),$money($ttc)] as $i=>$v)$pdf->MultiCell($cols[$i][1],9,$v,1,$cols[$i][2],true,0,'','',true,0,false,true,9,'M');$pdf->Ln(12);
$pdf->SetFont('dejavusans','',7);$pdf->SetTextColor(71,85,105);$pdf->Cell(130,5,'Édité par : '.$exporter,0,0,'L');$pdf->Cell(130,5,'Généré le '.date('d/m/Y à H:i'),0,1,'R');
if(!empty($signature)){$pdf->Ln(2);$pdf->SetFont('dejavusans','B',7);$pdf->Cell(260,5,'Signature professionnelle enregistrée dans FINEA',0,1,'R');}
$pdf->Output('tva_collectee_'.$from.'_au_'.$to.'.pdf','I');exit;
