<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/vendor/autoload.php';
require_once dirname(__DIR__).'/model/CompanyPdfTemplate.php';

final class MissionOrderPdfDocument extends TCPDF
{
    use CompanyPdfTemplate;
    public function Header(): void { $this->drawCompanyPdfHeader(); }
    public function Footer(): void { $this->drawCompanyPdfFooter(); }
}

final class MissionOrderPdfService
{
    public static function render(array $mission,array $expenses,string $destination='I',string $file=''): string
    {
        $h=static fn($v):string=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
        $date=static fn($v):string=>($v&&strtotime((string)$v))?date('d/m/Y',strtotime((string)$v)):'—';
        $money=static fn($v):string=>number_format((float)$v,0,',',' ').' FCFA';
        $signatureValue=static function(string $data,string $path=''):string{
            $data=trim(html_entity_decode($data,ENT_QUOTES,'UTF-8'));
            if(preg_match('#^data:image/(?:png|jpeg|jpg|webp);base64,\s*#i',$data))return preg_replace('#\s+#','',$data)??$data;
            $raw=base64_decode(preg_replace('#\s+#','',$data)??'',true);
            $rawInfo=is_string($raw)?@getimagesizefromstring($raw):false;
            if(is_string($raw)&&is_array($rawInfo)&&in_array((string)($rawInfo['mime']??''),['image/png','image/jpeg','image/webp'],true))return'data:'.$rawInfo['mime'].';base64,'.base64_encode($raw);
            $path=trim(html_entity_decode($path,ENT_QUOTES,'UTF-8')," \t\n\r\0\x0B\"'");
            if(preg_match('#<img[^>]+src=[\"\']([^\"\']+)#i',$path,$match))$path=html_entity_decode($match[1],ENT_QUOTES,'UTF-8');
            if(preg_match('#^data:image/(?:png|jpeg|jpg|webp);base64,#i',$path))return preg_replace('#\s+#','',$path)??$path;
            $urlPath=parse_url($path,PHP_URL_PATH);if(is_string($urlPath)&&$urlPath!=='')$path=rawurldecode($urlPath);
            $root=realpath(dirname(__DIR__));if(!$root)return'';
            $relative=ltrim(str_replace('\\','/',$path),'/');$project=basename($root);$marker=$project.'/';$position=strpos($relative,$marker);if($position!==false)$relative=substr($relative,$position+strlen($marker));
            $base=basename($relative);$candidates=[$root.'/'.$relative,$root.'/uploads/user_signatures/'.$base,$root.'/decaissement/uploads/signatures/'.$base,$root.'/uploads/signatures/'.$base,$root.'/signatures/'.$base,$root.'/uploads/cachet/'.$base];
            foreach(array_unique($candidates)as$candidate){$file=realpath($candidate);if(!$file||!str_starts_with(str_replace('\\','/',$file),rtrim(str_replace('\\','/',$root),'/').'/')||!is_file($file)||!is_readable($file)||filesize($file)>2*1024*1024)continue;$binary=@file_get_contents($file);$info=is_string($binary)?@getimagesizefromstring($binary):false;$mime=is_array($info)?(string)($info['mime']??''):'';if(is_string($binary)&&in_array($mime,['image/png','image/jpeg','image/webp'],true))return'data:'.$mime.';base64,'.base64_encode($binary);}
            return'';
        };
        $firstValue=static function(array $values):string{foreach($values as $value)if(trim((string)$value)!=='')return trim((string)$value);return'';};
        $total=array_sum(array_map(static fn($l)=>(float)($l['line_total']??0),$expenses));
        $pdf=new MissionOrderPdfDocument('P','mm','A4',true,'UTF-8',false);
        $pdf->companyPdfTitle='Ordre de mission';$pdf->companyPdfType='Ressources humaines';$pdf->companyPdfId=(string)($mission['mission_code']??'OM');$pdf->companyPdfCode='RH-OM-01';$pdf->companyPdfQrPayload='FINEA:MISSION:'.($mission['id']??'');
        $pdf->SetCreator('FINEA');$pdf->SetTitle('Ordre de mission '.($mission['mission_code']??''));$pdf->SetMargins(11,40,11);$pdf->SetAutoPageBreak(true,25);$pdf->AddPage();$pdf->SetFont('dejavusans','',9);
        $html='<table cellpadding="6" cellspacing="0" border="1" style="border-color:#d1d5db"><tr style="background-color:#ffcc20;color:#050505"><td colspan="2"><b>INFORMATIONS DE MISSION</b></td></tr>';
        foreach(['Référence'=>$mission['mission_code']??'—','Collaborateur'=>$mission['personnel_name']??'—','Matricule'=>$mission['personnel_matricule']??'—','Fonction / service'=>trim(($mission['fonction_label']??'').' · '.($mission['service_label']??''),' ·'),'Objet'=>$mission['mission_title']??'—','Destination'=>$mission['destination']??'—','Période'=>$date($mission['start_date']??'').' au '.$date($mission['end_date']??'')] as $label=>$value)$html.='<tr><td width="32%" style="background-color:#f8fafc"><b>'.$h($label).'</b></td><td width="68%">'.$h($value).'</td></tr>';
        $html.='</table><br><br><table cellpadding="6" cellspacing="0" border="1"><tr style="background-color:#050505;color:#ffffff"><th width="48%">Dépense</th><th width="12%">Qté</th><th width="20%">Prix unitaire</th><th width="20%">Total</th></tr>';
        foreach($expenses as $line)$html.='<tr><td>'.$h($line['designation']??'').'</td><td align="right">'.$h($line['quantity']??0).'</td><td align="right">'.$h($money($line['unit_price']??0)).'</td><td align="right">'.$h($money($line['line_total']??0)).'</td></tr>';
        $html.='<tr style="background-color:#fff8d6"><td colspan="3" align="right"><b>TOTAL MISSION</b></td><td align="right"><b>'.$h($money($total)).'</b></td></tr></table><br><br>';
        $sigCell=static function(string $title,string $data,string $name,string $dateValue,bool $optional=false,string $detail='')use($h,$date):string{$content=$data!==''?'<img src="'.$h($data).'" height="42"><br><b>'.$h($name).'</b>'.($detail!==''?'<br><small>'.$h($detail).'</small>':'').'<br><small>Signé le '.$h($date($dateValue)).'</small>':'<br><br><b>'.$h($name).'</b><br><span style="color:#64748b">'.($optional?'Signature à compléter':'Signature obligatoire à compléter').'</span><br>';return '<td width="50%" align="center"><b>'.$h($title).'</b><br>'.$content.'</td>';};
        $employeePo=!empty($mission['employee_signed_po']);$employeeName=(string)($mission['personnel_name']??'Collaborateur');$employeeSigner=$firstValue([$mission['employee_signed_by_name']??'',$mission['employee_profile_name']??'']);$employeeDetail=$employeePo?'Pour ordre par '.($employeeSigner?:'DRH'):($employeeSigner!==''&&mb_strtoupper($employeeSigner)!==mb_strtoupper($employeeName)?'Signé par '.$employeeSigner:'');
        $employeeSignature=$signatureValue((string)($mission['employee_signature_data']??''));if($employeeSignature===''&&(string)($mission['status']??'')==='approved')$employeeSignature=$signatureValue($firstValue([$mission['employee_profile_signature_data']??'',$mission['personnel_profile_signature_data']??'']),$firstValue([$mission['employee_profile_signature_path']??'',$mission['personnel_profile_signature_path']??'']));
        $drhSignature=$signatureValue((string)($mission['drh_signature_data']??''));if($drhSignature===''&&(string)($mission['status']??'')==='approved')$drhSignature=$signatureValue($firstValue([$mission['drh_profile_signature_data']??'',$mission['approver_profile_signature_data']??'',$mission['hierarchy_drh_signature_data']??'',$mission['default_drh_signature_data']??'']),$firstValue([$mission['drh_profile_signature_path']??'',$mission['approver_profile_signature_path']??'',$mission['hierarchy_drh_signature_path']??'',$mission['default_drh_signature_path']??'']));$drhName=$firstValue([$mission['drh_signed_by_name']??'',$mission['drh_profile_name']??'',$mission['approved_by_name']??'',$mission['approver_profile_name']??'',$mission['hierarchy_drh_name']??'',$mission['default_drh_name']??'']);$officialDrhName=$firstValue([$mission['hierarchy_drh_name']??'',$mission['default_drh_name']??'']);$drhMode=(string)($mission['drh_signature_mode']??'');$drhIsPo=str_starts_with($drhMode,'po_')||($drhName!==''&&$officialDrhName!==''&&mb_strtoupper($drhName)!==mb_strtoupper($officialDrhName));$drhDetail=$drhIsPo?'Pour ordre du Directeur des Ressources humaines'.($officialDrhName!==''?' · '.$officialDrhName:''):'';
        $html.='<table cellpadding="8" cellspacing="0" border="1"><tr>'.$sigCell('Le collaborateur',$employeeSignature,$employeeName,(string)($mission['employee_signed_at']??''),true,$employeeDetail).$sigCell('Le Directeur des Ressources Humaines',$drhSignature,$drhName,(string)($mission['drh_signed_at']??''),false,$drhDetail).'</tr></table>';
        $pdf->writeHTML($html,true,false,true,false,'');
        $name=$file!==''?$file:'ordre_mission_'.preg_replace('/[^A-Za-z0-9_-]/','_',($mission['mission_code']??'OM')).'.pdf';
        $pdf->Output($name,$destination);return $name;
    }
}
