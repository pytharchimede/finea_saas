<?php
declare(strict_types=1);
require_once __DIR__.'/auth_check.php';
require_once __DIR__.'/vendor/autoload.php';
require_once __DIR__.'/model/Database.php';
require_once __DIR__.'/model/NotificationManager.php';
require_once __DIR__.'/services/UserNotificationHistoryService.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;use PhpOffice\PhpSpreadsheet\Style\Alignment;use PhpOffice\PhpSpreadsheet\Style\Fill;use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
$format=strtolower((string)($_GET['format']??'pdf'));if(!in_array($format,['pdf','xlsx'],true))$format='pdf';
$filters=['q'=>trim((string)($_GET['q']??'')),'state'=>trim((string)($_GET['state']??'')),'event'=>trim((string)($_GET['event']??'')),'date_from'=>trim((string)($_GET['date_from']??'')),'date_to'=>trim((string)($_GET['date_to']??''))];
$pdo=Database::getConnection();$userId=(int)($_SESSION['user_id']??0);$teams=NotificationManager::inferUserTeamsFromSession();$rows=(new UserNotificationHistoryService($pdo))->all($userId,$teams,$filters);
$name=trim((string)($_SESSION['user_name']??''))?:'Utilisateur';$filename='notifications_finea_'.date('Ymd_His');
if($format==='xlsx'){
 $book=new Spreadsheet();$sheet=$book->getActiveSheet();$sheet->setTitle('Mes notifications');$sheet->setShowGridlines(false);
 $sheet->mergeCells('A1:H1')->setCellValue('A1','FINEA | HISTORIQUE PERSONNEL DES NOTIFICATIONS');$sheet->getStyle('A1:H1')->applyFromArray(['font'=>['bold'=>true,'size'=>16,'color'=>['ARGB'=>'FFFFFFFF']],'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['ARGB'=>'FF0F172A']]]);$sheet->getRowDimension(1)->setRowHeight(32);
 $sheet->mergeCells('A2:H2')->setCellValue('A2',$name.' • Export du '.date('d/m/Y H:i').' • '.count($rows).' notification(s)');$sheet->getStyle('A2:H2')->getFont()->setItalic(true)->getColor()->setARGB('FF64748B');
 $headers=['Date','État','Type','Titre','Message','Équipe','Lien','Lecture'];$sheet->fromArray($headers,null,'A4');$sheet->getStyle('A4:H4')->applyFromArray(['font'=>['bold'=>true,'color'=>['ARGB'=>'FFFFFFFF']],'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['ARGB'=>'FF1E3A8A']],'alignment'=>['wrapText'=>true]]);
 $line=5;foreach($rows as $row){$sheet->fromArray([$row['created_at'],$row['is_unread']?'Non lue':'Lue',$row['event_key'],$row['title'],$row['message'],$row['recipient_team'],$row['link_url'],$row['read_at']],null,'A'.$line);if($row['link_url'])$sheet->getCell('G'.$line)->getHyperlink()->setUrl((string)$row['link_url']);$sheet->getStyle('A'.$line.':H'.$line)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);$line++;}
 foreach(['A'=>20,'B'=>13,'C'=>27,'D'=>34,'E'=>62,'F'=>16,'G'=>34,'H'=>20] as $col=>$width)$sheet->getColumnDimension($col)->setWidth($width);$sheet->freezePane('A5');$sheet->setAutoFilter('A4:H'.max(4,$line-1));
 while(ob_get_level())ob_end_clean();header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');header('Content-Disposition: attachment; filename="'.$filename.'.xlsx"');(new Xlsx($book))->save('php://output');exit;
}
class FineaNotificationsPdf extends TCPDF{public function Header():void{$this->SetFillColor(15,23,42);$this->Rect(0,0,$this->getPageWidth(),24,'F');$this->SetXY(12,6);$this->SetFont('dejavusans','B',14);$this->SetTextColor(255,255,255);$this->Cell(0,6,'FINEA | MES NOTIFICATIONS',0,1);}public function Footer():void{$this->SetY(-12);$this->SetDrawColor(203,213,225);$this->Line(12,$this->GetY(),$this->getPageWidth()-12,$this->GetY());$this->SetY(-9);$this->SetFont('dejavusans','',7);$this->SetTextColor(100,116,139);$this->Cell(0,4,'Historique personnel confidentiel • Page '.$this->getAliasNumPage().'/'.$this->getAliasNbPages(),0,0,'C');}}
$pdf=new FineaNotificationsPdf('L','mm','A4',true,'UTF-8',false);$pdf->SetMargins(10,29,10);$pdf->SetAutoPageBreak(true,16);$pdf->AddPage();$pdf->SetFont('dejavusans','B',15);$pdf->SetTextColor(15,23,42);$pdf->Cell(0,7,'Historique complet des notifications',0,1);$pdf->SetFont('dejavusans','',8);$pdf->SetTextColor(71,85,105);$pdf->Cell(0,5,$name.' • '.count($rows).' notification(s) • '.date('d/m/Y H:i'),0,1);$pdf->Ln(3);
$html='<table cellpadding="4" border="1"><thead><tr style="background-color:#1e3a8a;color:#fff;font-weight:bold"><th width="12%">Date</th><th width="8%">État</th><th width="15%">Type</th><th width="22%">Titre</th><th width="38%">Message</th><th width="5%">Lien</th></tr></thead><tbody>';
foreach($rows as $row)$html.='<tr><td>'.htmlspecialchars(date('d/m/Y H:i',strtotime((string)$row['created_at']))).'</td><td>'.($row['is_unread']?'Non lue':'Lue').'</td><td>'.htmlspecialchars(str_replace('_',' ',$row['event_key'])).'</td><td><b>'.htmlspecialchars($row['title']).'</b></td><td>'.nl2br(htmlspecialchars((string)$row['message'])).'</td><td>'.($row['link_url']?'Oui':'—').'</td></tr>';
$html.='</tbody></table>';$pdf->SetFont('dejavusans','',7);$pdf->writeHTML($html,true,false,true,false,'');while(ob_get_level())ob_end_clean();$pdf->Output($filename.'.pdf','I');
