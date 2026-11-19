<?php
declare(strict_types=1);
require_once __DIR__.'/RhFinancialPointPolicy.php';
require_once __DIR__.'/RhFinancialSignature.php';

final class RhFinancialPointPdfBuilder
{
    public function bytes(array $point, array $snapshot, bool $signed): string
    {
        require_once __DIR__.'/../vendor/tecnickcom/tcpdf/tcpdf.php';
        require_once __DIR__.'/../model/CompanyPdfTemplate.php';
        $pdf=new class('P','mm','A4',true,'UTF-8',false) extends TCPDF {
            use CompanyPdfTemplate;
            public function removeGeneratorCredit(): void {$this->tcpdflink=false;}
            public function Header(): void {$this->drawCompanyPdfHeader();}
            public function Footer(): void {$this->drawCompanyPdfFooter();}
        };
        $pdf->companyPdfTitle='Point financier RH';
        $pdf->companyPdfType=RhFinancialPointPolicy::KINDS[$point['kind']];
        $pdf->companyPdfId='RH #'.(int)$point['id'].' - v'.(int)$point['revision'];
        $pdf->companyPdfCode='RH-POINT-FIN-01';
        $pdf->companyPdfQrPayload=$pdf->companyPdfUrl('rh/export_point_financier_pdf.php?id='.(int)$point['id'].'&revision='.(int)$point['revision']);
        $colors=$pdf->companyPdfColors();
        $brand=CompanyBranding::get();
        $pdf->removeGeneratorCredit();$pdf->SetCreator('FINEA');$pdf->SetAuthor((string)$brand['company_name']);
        $pdf->SetTitle('Point financier RH #'.$point['id'].' - version '.$point['revision']);
        $pdf->SetMargins(14,40,14);$pdf->SetHeaderMargin(0);$pdf->SetFooterMargin(7);
        $pdf->SetAutoPageBreak(true,27);$pdf->setPrintHeader(true);$pdf->setPrintFooter(true);
        $pdf->AddPage();$pdf->SetFont('dejavusans','',8.5);$pdf->SetTextColor(...$pdf->companyPdfRgb($colors['text']));
        $e=static fn(mixed $v):string=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
        $money=static fn(mixed $v):string=>number_format((float)$v,0,',',' ');
        $date=static fn(mixed $v):string=>$v?date('d/m/Y à H:i',strtotime((string)$v)):'En attente';
        $total=RhFinancialPointPolicy::total($snapshot['lines']);$requested=0;
        foreach($snapshot['lines'] as $line)$requested+=(int)$line['initial_amount'];
        $state=$signed?'Validé et signé par le DG':(!empty($point['rh_signed_at'])?'Arrêté RH - en attente du DG':'Brouillon - à signer par les RH');
        $html='<p style="font-size:8px;color:'.$colors['muted'].';letter-spacing:1px">RÉCAPITULATIF FINANCIER</p><h1 style="font-size:20px;color:'.$colors['text'].'">'.$e(RhFinancialPointPolicy::KINDS[$point['kind']]).'</h1>';
        $html.='<table cellpadding="8" style="background-color:#f8fafc"><tr><td width="60%"><span style="font-size:8px;color:'.$colors['muted'].'">POINT / VERSION</span><br><b>#'.(int)$point['id'].' / v'.(int)$point['revision'].'</b><br><span style="font-size:8px;color:'.$colors['muted'].'">PÉRIODE</span><br>'.$e($point['period'] ?: 'Courante').'</td><td width="40%"><span style="font-size:8px;color:'.$colors['muted'].'">TOTAL RETENU</span><br><b style="font-size:21px;color:'.$colors['text'].'">'.$money($total).'</b> <span style="font-size:9px">FCFA</span><br>'.count($snapshot['lines']).' demande(s)</td></tr></table>';
        $html.='<p><b>Porteur RH :</b> '.$e($point['stopped_name']).'<br><b>État :</b> '.$e($state).'</p>';
        $html.='<h2 style="font-size:12px;color:'.$colors['text'].'">Détail des demandes</h2><table cellpadding="7"><thead><tr style="background-color:'.$colors['accent'].';color:'.(array_sum($pdf->companyPdfContrast($colors['accent']))?'#ffffff':'#000000').'"><th width="23%"><b>Collaborateur</b></th><th width="25%"><b>Demande</b></th><th width="14%" align="right"><b>Demandé</b></th><th width="14%" align="right"><b>Retenu</b></th><th width="24%"><b>Décision / motif</b></th></tr></thead><tbody>';
        $labels=['approved'=>'Retenue','rejected'=>'Refusée','cancelled'=>'Annulée','deferred'=>'Reportée'];
        foreach($snapshot['lines'] as $index=>$line){
            $decision=$line['decision'];$color=$decision==='approved'?'#237655':($decision==='deferred'?'#90690a':'#a6413b');
            $note=trim((string)$line['note']);$deferred=trim((string)$line['deferred_until']);
            $html.='<tr nobr="true" style="background-color:'.($index%2?'#f8fafc':'#ffffff').'"><td style="border-bottom:1px solid #e0e8ef">'.$e($line['name']).'</td><td style="border-bottom:1px solid #e0e8ef">'.$e($line['label']).'</td><td align="right" style="border-bottom:1px solid #e0e8ef">'.$money($line['initial_amount']).'</td><td align="right" style="border-bottom:1px solid #e0e8ef"><b>'.$money($decision==='approved'?$line['amount']:0).'</b></td><td style="border-bottom:1px solid #e0e8ef"><b style="color:'.$color.'">'.$e($labels[$decision] ?? $decision).'</b>'.($deferred!==''?'<br>Au '.$e(date('d/m/Y',strtotime($deferred))):'').($note!==''?'<br><span style="font-size:8px;color:'.$colors['muted'].'">'.$e($note).'</span>':'').'</td></tr>';
        }
        $html.='</tbody></table><table cellpadding="9"><tr style="background-color:'.$colors['accent'].'"><td width="48%"><span style="font-size:8px;color:'.$colors['muted'].'">MONTANT DEMANDÉ</span><br><b>'.$money($requested).' FCFA</b></td><td width="52%" align="right"><span style="font-size:8px;color:'.$colors['text'].'">TOTAL AUTORISÉ AU DÉCAISSEMENT'.(!$signed?' (PROVISOIRE)':'').'</span><br><b style="font-size:14px;color:'.$colors['text'].'">'.$money($total).' FCFA</b></td></tr></table>';
        $html.='<p style="font-size:8px;color:'.$colors['muted'].'">Les montants retenus concernent uniquement les lignes autorisées. Le porteur RH est le demandeur de la FEB ; les collaborateurs sont les bénéficiaires des lignes. Le paiement devient effectif lors du décaissement.</p>';
        $pdf->writeHTML($html,true,false,true,false,'');
        // Keep the two signature panels together, including on a multi-page recap.
        $displaySignatures=array_replace(['rh_signature'=>$snapshot['rh_signature'] ?? [],'dg_signature'=>$snapshot['dg_signature'] ?? []],$point['supplemental_signatures'] ?? []);
        $signatures=[['RH',$point['stopped_name'],$point['rh_signed_at'] ?? null,$displaySignatures['rh_signature']],['DG',$point['dg_name'] ?? 'Direction générale',$signed?($point['dg_signed_at'] ?? null):null,$signed?$displaySignatures['dg_signature']:[]]];
        $pdf->SetFont('dejavusans','',8);$nameSlot=11.0;
        foreach($signatures as $item)$nameSlot=max($nameSlot,$pdf->getStringHeight(80,(string)$item[1])+2);
        $panelHeight=48+$nameSlot;
        if($pdf->GetY()+$panelHeight+11>$pdf->getPageHeight()-27)$pdf->AddPage();
        $y=$pdf->GetY()+3;
        foreach($signatures as $index=>[$role,$name,$at,$signature]){
            $x=14+$index*94;$pdf->SetDrawColor(224,232,239);$pdf->SetFillColor(250,252,253);$pdf->RoundedRect($x,$y,88,$panelHeight,2,'1111','DF');$pdf->SetFillColor(...$pdf->companyPdfRgb($colors['accent']));$pdf->Rect($x,$y,88,1.3,'F');
            $pdf->SetXY($x+4,$y+4);$pdf->SetFont('dejavusans','B',9);$pdf->SetTextColor(...$pdf->companyPdfRgb($colors['text']));$pdf->Cell(80,5,$role==='RH'?'ARRÊTÉ ET SIGNÉ PAR LES RH':'VALIDATION DE LA DIRECTION');
            $pdf->SetXY($x+4,$y+12);$pdf->SetFont('dejavusans','',8);$pdf->MultiCell(80,8,(string)$name,0,'L',false,1);
            $pdf->SetXY($x+4,$y+12+$nameSlot);$pdf->SetFont('dejavusans','',7);$pdf->SetTextColor(...$pdf->companyPdfRgb($colors['muted']));$pdf->Cell(80,4,$date($at));
            $mode=($signature['method'] ?? 'passkey')==='manual'?'Signature manuscrite':'Identité confirmée par Passkey';
            $pdf->SetXY($x+4,$y+18+$nameSlot);$pdf->Cell(80,4,$at?$mode:'Signature en attente');
            if($at && ($signature['method'] ?? '')==='manual' && !empty($signature['svg'])){
                if(!hash_equals((string)($signature['image_hash'] ?? ''),hash('sha256',$signature['svg'])))throw new RuntimeException('Signature '.$role.' incohérente.');
                $pdf->ImageSVG('@'.$signature['svg'],$x+4,$y+24+$nameSlot,65,19.5);
            } elseif ($at && !empty($signature['image_data'])) {
                $bytes=RhFinancialSignature::imageBytes($signature);
                $pdf->Image('@'.$bytes,$x+4,$y+24+$nameSlot,65,19.5,$signature['image_type'],'','',true,300,'',false,false,0,'CM');
            } elseif ($at && ($signature['method'] ?? 'passkey')==='passkey') {
                $pdf->SetXY($x+4,$y+27+$nameSlot);$pdf->MultiCell(80,5,'Signature de profil non disponible lors de la validation.',0,'L');
            }
            if ($at && !empty($signature['backfilled_at'])) {
                $pdf->SetXY($x+4,$y+$panelHeight-5);$pdf->SetFont('dejavusans','',6);$pdf->Cell(80,3,'Profil ajouté le '.date('d/m/Y H:i',strtotime($signature['backfilled_at'])));
            }
        }
        $pdf->SetXY(14,$y+$panelHeight+4);$pdf->SetFont('dejavusans','',6.5);$pdf->SetTextColor(...$pdf->companyPdfRgb($colors['muted']));
        if($signed)$pdf->MultiCell(182,8,(!empty($point['presentation_copy'])?'Copie de consultation. Original signé conservé. ':'').(!empty($point['supplemental_signatures'])?'Signatures de profil ajoutées après validation ; données et original conservés. ':'').'Empreinte du récapitulatif signé : '.(string)$point['snapshot_hash'],0,'L');
        else $pdf->MultiCell(182,8,'La validation et la signature du DG sont requises avant la création de la FEB définitive.',0,'L');
        return $pdf->Output('','S');
    }

    public function store(array $point, array $snapshot): string
    {
        $directory=__DIR__.'/../uploads/rh_financial_points';
        if(!is_dir($directory) && !mkdir($directory,0750,true) && !is_dir($directory))throw new RuntimeException('Impossible de créer le dossier justificatif.');
        $relative='uploads/rh_financial_points/point-'.$point['id'].'-v'.$point['revision'].'-'.bin2hex(random_bytes(8)).'.pdf';
        if(file_put_contents(__DIR__.'/../'.$relative,$this->bytes($point,$snapshot,true),LOCK_EX)===false)throw new RuntimeException('Impossible de conserver le PDF signé.');
        return $relative;
    }
}



