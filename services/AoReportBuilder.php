<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../vendor/tecnickcom/tcpdf/tcpdf_barcodes_2d.php';
require_once __DIR__ . '/../model/CompanyBranding.php';
require_once __DIR__ . '/../model/CompanyPdfTemplate.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;


final class AoWatchIsoPdf extends TCPDF{use CompanyPdfTemplate;public function Header():void{$this->drawCompanyPdfHeader();}public function Footer():void{$this->drawCompanyPdfFooter();}}

final class AoReportBuilder
{
private static function formatDate(mixed $value):string{$time=$value?strtotime((string)$value):false;return $time?date('d/m/Y',$time):'—';}
private static function formatStatus(mixed $value):string{$value=trim((string)$value);return $value!==''?ucfirst(str_replace('_',' ',$value)):'À qualifier';}
private static function filterLabel(array $filters):string{$parts=[];if($filters['q']!=='')$parts[]='Recherche : '.$filters['q'];if($filters['status']!=='')$parts[]='Statut : '.self::formatStatus($filters['status']);if($filters['min_score']!=='')$parts[]='Score minimum : '.(int)$filters['min_score'].' %';$scopes=['active'=>'Échéances actives','expired'=>'Échéances passées','all'=>'Toutes les échéances'];$parts[]='Périmètre : '.($scopes[$filters['deadline_scope']]??$filters['deadline_scope']);return implode(' · ',$parts);}

    public static function build(array $rows, string $format, array $filters, string $author = 'FINEA · Bilan automatique', int $uid = 0): string
    {
        $docId='FIN-DEV-AO-'.date('Ymd-His').'-U'.$uid; $docCode='FIN-DEV-AO-01'; $filename='recap_veille_ao_'.date('Ymd_His');
        $active=0;$priority=0;$scoreSum=0;
        foreach($rows as $row){$deadline=!empty($row['date_limite'])?strtotime((string)$row['date_limite']):false;if($deadline!==false&&$deadline>=strtotime('today'))$active++;$score=(int)($row['score_pertinence']??0);$priority+=(int)($score>=70);$scoreSum+=$score;}
        $average=$rows?(int)round($scoreSum/count($rows)):0;
        
        $qrPayload=json_encode(['document'=>$docId,'code'=>$docCode,'type'=>'veille_ao','filters'=>$filters,'count'=>count($rows),'generated_at'=>date(DATE_ATOM)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?:$docId;
        
        
        if(in_array($format,['excel','xlsx'],true)){
            $book=new Spreadsheet();$book->getProperties()->setCreator($author)->setCompany('BANAMUR INDUSTRIES')->setTitle('Récapitulatif de veille des appels d’offres')->setSubject('Document maîtrisé ISO 9001')->setDescription($docId.' · '.$docCode);
            $sheet=$book->getActiveSheet();$sheet->setTitle('Veille AO');$sheet->setShowGridlines(false);$sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_A3)->setFitToWidth(1)->setFitToHeight(0);$sheet->getPageMargins()->setTop(.35)->setRight(.25)->setBottom(.55)->setLeft(.25);$sheet->getHeaderFooter()->setOddFooter('&L'.$docCode.' · '.$docId.'&CDocument maîtrisé · ISO 9001&RPage &P / &N');
            $sheet->mergeCells('A1:I1')->setCellValue('A1','BANAMUR INDUSTRIES · RÉCAPITULATIF DE VEILLE DES APPELS D’OFFRES');
            $sheet->mergeCells('A2:F2')->setCellValue('A2','DOCUMENT MAÎTRISÉ · FINEA · ISO 9001');$sheet->mergeCells('G2:I2')->setCellValue('G2','Édité le '.date('d/m/Y à H:i'));
            $sheet->mergeCells('A3:C3')->setCellValue('A3','Référence : '.$docId);$sheet->mergeCells('D3:F3')->setCellValue('D3','Code qualité : '.$docCode);$sheet->mergeCells('G3:I3')->setCellValue('G3','Édité par : '.$author);
            $sheet->mergeCells('A4:I4')->setCellValue('A4',self::filterLabel($filters));
            $sheet->mergeCells('A6:B6')->setCellValue('A6','DOSSIERS EXPORTÉS');$sheet->setCellValue('C6',count($rows));$sheet->mergeCells('D6:E6')->setCellValue('D6','ÉCHÉANCES ACTIVES');$sheet->setCellValue('F6',$active);$sheet->mergeCells('G6:H6')->setCellValue('G6','SCORE MOYEN / PRIORITÉS');$sheet->setCellValue('I6',$average.' % / '.$priority);
            $headers=['N° / Référence AO','Objet du marché','Autorité contractante','Type de marché','Publication','Date limite','Score','Statut interne','Source'];$sheet->fromArray($headers,null,'A8');$line=9;
            foreach($rows as $row){$values = [(string)(($row['numero_ao']??'')?:($row['reference']??'—')),(string)($row['titre']??'—'),(string)($row['autorite_contractante']??'—'),(string)($row['type_marche']??'—'),self::formatDate($row['date_publication']??null),self::formatDate($row['date_limite']??null),(int)($row['score_pertinence']??0)/100,self::formatStatus($row['statut_interne']??''),(string)($row['url_source']??'')];foreach($values as $i=>$value){$column=chr(65+$i);if(is_string($value))$sheet->setCellValueExplicit($column.$line,$value,\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);else $sheet->setCellValue($column.$line,$value);}if(!empty($row['url_source']))$sheet->getCell('I'.$line)->getHyperlink()->setUrl((string)$row['url_source']);$sheet->getRowDimension($line)->setRowHeight(34);if($line%2===0)$sheet->getStyle('A'.$line.':I'.$line)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F8FAFC');$line++;}
            if(!$rows){$sheet->mergeCells('A9:I10')->setCellValue('A9','Aucun appel d’offres ne correspond aux filtres sélectionnés.');$sheet->getStyle('A9')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);$line=11;}
            $last=max(9,$line-1);$sheet->mergeCells('A'.($line+1).':I'.($line+1))->setCellValue('A'.($line+1),'RÉCAPITULATIF · '.count($rows).' dossier(s) · '.$active.' échéance(s) active(s) · '.$priority.' opportunité(s) prioritaire(s) · score moyen '.$average.' %.');$sheet->mergeCells('A'.($line+2).':I'.($line+2))->setCellValue('A'.($line+2),'Document généré automatiquement par FINEA. Le QR code et la référence assurent la traçabilité de cette édition.');
            $sheet->getStyle('A1:I1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('000000');$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(17)->getColor()->setRGB('FFFFFF');$sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getStyle('A2:I2')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FABD02');$sheet->getStyle('A2:I2')->getFont()->setBold(true);$sheet->getStyle('A3:I4')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F8FAFC');$sheet->getStyle('A3:I4')->getFont()->setSize(9)->getColor()->setRGB('334155');$sheet->getStyle('A6:I6')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFF8D6');$sheet->getStyle('A6:I6')->getFont()->setBold(true)->getColor()->setRGB('422006');
            $sheet->getStyle('A8:I8')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FABD02');$sheet->getStyle('A8:I8')->getFont()->setBold(true)->setSize(9);$sheet->getStyle('A8:I'.$last)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('CBD5E1');$sheet->getStyle('A8:I'.$last)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);$sheet->getStyle('E9:H'.$last)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);$sheet->getStyle('G9:G'.$last)->getNumberFormat()->setFormatCode('0%');$sheet->getStyle('I9:I'.$last)->getFont()->getColor()->setRGB('1D4ED8');
            $sheet->getStyle('A'.($line+1).':I'.($line+2))->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F1F5F9');$sheet->getStyle('A'.($line+1))->getFont()->setBold(true);$sheet->getStyle('A'.($line+1).':I'.($line+2))->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);
            foreach(['A'=>22,'B'=>56,'C'=>34,'D'=>22,'E'=>15,'F'=>15,'G'=>11,'H'=>22,'I'=>38] as $column=>$width)$sheet->getColumnDimension($column)->setWidth($width);foreach([1=>34,2=>22,4=>28,6=>27,8=>30] as $r=>$height)$sheet->getRowDimension($r)->setRowHeight($height);$sheet->freezePane('A9');if($rows)$sheet->setAutoFilter('A8:I'.$last);$sheet->getPageSetup()->setPrintArea('A1:I'.($line+2))->setRowsToRepeatAtTopByStartAndEnd(8,8);
            $logo=CompanyBranding::logoFile();if($logo!==''&&is_file($logo)){$drawing=new Drawing();$drawing->setName('Logo BANAMUR')->setPath($logo)->setHeight(50)->setCoordinates('A1')->setOffsetX(5)->setOffsetY(3)->setWorksheet($sheet);}
            $qrFile=tempnam(sys_get_temp_dir(),'finea_ao_qr_');if($qrFile!==false){@unlink($qrFile);$qrFile.='.png';$barcode=new TCPDF2DBarcode($qrPayload,'QRCODE,M');file_put_contents($qrFile,$barcode->getBarcodePngData(3,3,[0,0,0]));if(is_file($qrFile)){$qr=new Drawing();$qr->setName('QR de traçabilité')->setPath($qrFile)->setHeight(67)->setCoordinates('I1')->setOffsetX(135)->setOffsetY(2)->setWorksheet($sheet);}}
            $tmp = tmpfile();
            if ($tmp === false) throw new RuntimeException('Création Excel temporaire impossible.');
            try {
                $path = stream_get_meta_data($tmp)['uri'];
                (new Xlsx($book))->save($path);
                $bytes = file_get_contents($path);
                if ($bytes === false) throw new RuntimeException('Lecture Excel impossible.');
                return $bytes;
            } finally {
                fclose($tmp);
                if (isset($qrFile) && is_file($qrFile)) @unlink($qrFile);
                $book->disconnectWorksheets();
            }
        }
        
        
        $pdf=new AoWatchIsoPdf('L','mm','A3',true,'UTF-8',false);$pdf->companyPdfTitle='Récapitulatif de veille des appels d’offres';$pdf->companyPdfType='État des opportunités détectées';$pdf->companyPdfId=$docId;$pdf->companyPdfCode=$docCode;$pdf->companyPdfQrPayload=$qrPayload;$pdf->SetCreator('FINEA');$pdf->SetAuthor($author);$pdf->SetTitle('Récapitulatif de veille des appels d’offres');$pdf->SetMargins(10,38,10);$pdf->SetAutoPageBreak(true,25);$pdf->AddPage();
        $faFont='dejavusans';
        $cards=[['AO','Dossiers exportés',count($rows).' dossier(s)',[15,23,42]],['DATE','Échéances actives',$active.' active(s)',[22,101,52]],['TOP','Opportunités prioritaires',$priority.' score(s) ≥ 70 %',[180,83,9]],['%','Score moyen',$average.' %',[37,99,235]]];foreach($cards as $index=>$card){$x=10+$index*95.25;$pdf->SetFillColor(248,250,252);$pdf->SetDrawColor(203,213,225);$pdf->RoundedRect($x,41,92.25,19,3,'1111','DF');$pdf->SetFillColor(...$card[3]);$pdf->RoundedRect($x,41,3,19,1,'1001','F');$pdf->RoundedRect($x+7,45,11,11,3,'1111','F');$pdf->SetXY($x+7,47);$pdf->SetTextColor(255,255,255);$pdf->SetFont($faFont,'',7);$pdf->Cell(11,6,$card[0],0,0,'C');$pdf->SetXY($x+22,44);$pdf->SetTextColor(100,116,139);$pdf->SetFont('dejavusans','B',7);$pdf->Cell(66,4,mb_strtoupper($card[1]),0,1);$pdf->SetXY($x+22,49);$pdf->SetTextColor(15,23,42);$pdf->SetFont('dejavusans','B',11);$pdf->Cell(66,6,$card[2],0,1);}
        $pdf->SetY(64);$pdf->SetFillColor(255,248,214);$pdf->SetDrawColor(250,189,2);$pdf->SetTextColor(66,32,6);$pdf->SetFont('dejavusans','',7.5);$pdf->MultiCell(387,10,self::filterLabel($filters).' · Édité par '.$author,1,'L',true,1,'','',true,0,false,true,10,'M');$pdf->Ln(3);$pdf->SetFont('dejavusans','B',11);$pdf->SetTextColor(15,23,42);$pdf->Cell(0,6,'DÉTAIL DES OPPORTUNITÉS',0,1);$pdf->SetDrawColor(250,189,2);$pdf->SetLineWidth(.7);$pdf->Line(10,$pdf->GetY(),397,$pdf->GetY());$pdf->Ln(2);
        $columns=[['N° / RÉF.',35,'L'],['OBJET DU MARCHÉ',105,'L'],['AUTORITÉ CONTRACTANTE',57,'L'],['TYPE',38,'L'],['PUBLICATION',27,'C'],['DATE LIMITE',27,'C'],['SCORE',22,'C'],['STATUT INTERNE',36,'L'],['SOURCE',40,'L']];
        $drawHeader=static function()use($pdf,$columns):void{$pdf->SetFillColor(250,189,2);$pdf->SetDrawColor(23,23,23);$pdf->SetTextColor(0,0,0);$pdf->SetFont('dejavusans','B',6.8);foreach($columns as [$label,$width,$align])$pdf->MultiCell($width,9,$label,1,$align,true,0,'','',true,0,false,true,9,'M');$pdf->Ln();};$drawHeader();
        if(!$rows){$pdf->SetFillColor(248,250,252);$pdf->SetTextColor(100,116,139);$pdf->SetFont('dejavusans','I',9);$pdf->MultiCell(387,20,'Aucun appel d’offres ne correspond aux filtres sélectionnés.',1,'C',true,1,'','',true,0,false,true,20,'M');}
        foreach($rows as $index=>$row){$url=(string)($row['url_source']??'');$cells=[(string)(($row['numero_ao']??'')?:($row['reference']??'—')),(string)($row['titre']??'—'),(string)($row['autorite_contractante']??'—'),(string)($row['type_marche']??'—'),self::formatDate($row['date_publication']??null),self::formatDate($row['date_limite']??null),(int)($row['score_pertinence']??0).' %',self::formatStatus($row['statut_interne']??''),$url!==''?'Ouvrir la source':'—'];$pdf->SetFont('dejavusans','',6.5);$heights=[];foreach($cells as $i=>$cell)$heights[]=$pdf->getStringHeight($columns[$i][1]-3,$cell);$height=max(10,max($heights)+3);if($pdf->GetY()+$height>270){$pdf->AddPage();$pdf->SetY(40);$drawHeader();}$fill=$index%2===1;if($fill)$pdf->SetFillColor(248,250,252);$pdf->SetDrawColor(203,213,225);foreach($cells as $i=>$cell){$pdf->SetFont('dejavusans',in_array($i,[0,6,7],true)?'B':'',6.5);if($i===6){$score=(int)($row['score_pertinence']??0);$pdf->SetTextColor(...($score>=70?[22,101,52]:($score>=50?[180,83,9]:[100,116,139])));}elseif($i===8)$pdf->SetTextColor(29,78,216);else$pdf->SetTextColor(51,65,85);$cellX=$pdf->GetX();$cellY=$pdf->GetY();$pdf->MultiCell($columns[$i][1],$height,$cell,1,$columns[$i][2],$fill,0,'','',true,0,false,true,$height,'M');if($i===8&&$url!=='')$pdf->Link($cellX,$cellY,$columns[$i][1],$height,$url);}$pdf->Ln();}
        $pdf->Ln(4);$pdf->SetFillColor(241,245,249);$pdf->SetDrawColor(203,213,225);$pdf->SetTextColor(51,65,85);$pdf->SetFont('dejavusans','B',8);$pdf->MultiCell(387,10,'RÉCAPITULATIF · '.count($rows).' dossier(s) · '.$active.' échéance(s) active(s) · '.$priority.' opportunité(s) prioritaire(s) · score moyen '.$average.' %.',1,'L',true,1,'','',true,0,false,true,10,'M');$pdf->SetTextColor(100,116,139);$pdf->SetFont('dejavusans','',6.5);$pdf->MultiCell(387,5,'Le QR code contient la référence, la date de génération, le périmètre filtré et le nombre d’enregistrements de cette édition.',0,'L');return $pdf->Output($filename.'.pdf','S');
        
    }
}
