<?php
require_once __DIR__.'/_export_recap_common.php';
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../model/CompanyExportBranding.php';
require_once __DIR__.'/../model/CompanyPdfTemplate.php';

class RecurringNeedsIsoPdf extends TCPDF {use CompanyPdfTemplate;public function Header():void{$this->drawCompanyPdfHeader();}public function Footer():void{$this->drawCompanyPdfFooter();}}
$pdf=new RecurringNeedsIsoPdf('L','mm','A4',true,'UTF-8',false);
$pdf->companyPdfTitle='Récapitulatif des besoins récurrents';
$pdf->companyPdfType='État de pilotage des récurrences';
$pdf->companyPdfId=$recurringExportId;$pdf->companyPdfCode='FIN-BR-RECAP';
$pdf->companyPdfQrPayload=json_encode(['document'=>$recurringExportId,'type'=>'recurring_needs_recap','filters'=>$filters,'generated_at'=>date(DATE_ATOM)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
$pdf->SetCreator(CompanyExportBranding::creator());$pdf->SetTitle('Récapitulatif besoins récurrents');$pdf->SetMargins(10,38,10);$pdf->SetAutoPageBreak(true,25);$pdf->AddPage();
$cards=[['Occurrences',count($recurringExportRows)],['Montant planifié',rn_money($recurringExportTotal)],['Période',$recurringExportPeriod]];$x=10;
foreach($cards as[$label,$value]){$w=$label==='Période'?105:72;$pdf->SetFillColor(248,250,252);$pdf->SetDrawColor(226,232,240);$pdf->RoundedRect($x,40,$w,17,3,'1111','DF');$pdf->SetXY($x+3,43);$pdf->SetTextColor(100,116,139);$pdf->SetFont('dejavusans','',7);$pdf->Cell($w-6,4,(string)$label,0,2);$pdf->SetTextColor(15,23,42);$pdf->SetFont('dejavusans','B',10);$pdf->Cell($w-6,5,(string)$value,0,0,'L',false,'',1);$x+=$w+5;}
$pdf->SetY(63);$widths=[22,42,31,34,39,25,31,28,25];$headers=['Référence','Besoin','Demandeur','Bénéficiaire','Affectation','Échéance','Montant','Occurrence','FEB'];
$header=function()use($pdf,$widths,$headers){$pdf->SetFillColor(...CompanyExportBranding::rgbArray('primary'));$pdf->SetTextColor(...CompanyExportBranding::rgbArray('primary',true));$pdf->SetFont('dejavusans','B',7);foreach($headers as$i=>$h)$pdf->Cell($widths[$i],8,$h,1,0,'C',true);$pdf->Ln();};$header();
$pdf->SetFont('dejavusans','',6.8);foreach($recurringExportRows as$row){if($pdf->GetY()>178){$pdf->AddPage();$pdf->SetY(40);$header();}$supplier=trim((string)($row['fournisseur_name']??''));$beneficiary=$supplier!==''?$supplier:(string)($row['requester_name']??'');$assignment=trim(implode(' · ',array_filter([(string)($row['departement_label']??''),(string)($row['chantier_code']??''),(string)($row['chantier_title']??'')])));$values=[(string)($row['reference']??''),(string)($row['title']??''),(string)($row['requester_name']??''),$beneficiary,$assignment,rn_short_date($row['occurrence_due_date']??null),rn_money($row['amount_estimated']??0),(string)($row['occurrence_status']??''),!empty($row['generated_feb_id'])?'#'.(int)$row['generated_feb_id']:'—'];$pdf->SetTextColor(30,41,59);foreach($values as$i=>$value)$pdf->Cell($widths[$i],8,mb_strimwidth((string)$value,0,$i===1?34:25,'…'),1,0,$i===6?'R':'L',false,'',1);$pdf->Ln();}
$pdf->Output('recap_besoins_recurrents_'.$recurringExportId.'.pdf','I');
