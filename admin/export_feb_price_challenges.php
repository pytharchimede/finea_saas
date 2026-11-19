<?php
declare(strict_types=1);
require_once __DIR__.'/../auth_check.php';AccessManager::requirePageAccess('admin/export_feb_price_challenges.php');
require_once __DIR__.'/../vendor/autoload.php';require_once __DIR__.'/../model/Database.php';require_once __DIR__.'/../services/FebPriceChallengeService.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;use PhpOffice\PhpSpreadsheet\Style\Alignment;use PhpOffice\PhpSpreadsheet\Style\Border;use PhpOffice\PhpSpreadsheet\Style\Fill;use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
$format=strtolower(trim((string)($_GET['format']??'pdf')));if(!in_array($format,['pdf','xlsx'],true))$format='pdf';
$filters=['status'=>trim((string)($_GET['status']??'')),'category'=>trim((string)($_GET['category']??'')),'challenger'=>(int)($_GET['challenger']??0),'date_from'=>trim((string)($_GET['date_from']??'')),'date_to'=>trim((string)($_GET['date_to']??''))];
$pdo=Database::getConnection();$rows=(new FebPriceChallengeService($pdo))->supervision($filters);
$person='Tous les soumissionnaires';$personServices='Tous les services';$personFunction='';
if($filters['challenger']){
 $personStmt=$pdo->prepare('SELECT TRIM(CONCAT(COALESCE(u.prenom, ""), " ", COALESCE(u.nom, ""))) person_name,COALESCE(NULLIF(f.lib_fonction,""),"Collaborateur") function_name,COALESCE(NULLIF(sv.lib_service,""),"Service non renseigné") service_name FROM user_devis_banamur u LEFT JOIN personnel p ON p.id_personnel_soignant=u.personnel_id LEFT JOIN fonction f ON f.id_fonction=p.fonction_id LEFT JOIN service sv ON sv.id_service=p.service_id WHERE u.id=? LIMIT 1');
 $personStmt->execute([$filters['challenger']]);$personRow=$personStmt->fetch(PDO::FETCH_ASSOC)?:[];
 $person=trim((string)($personRow['person_name']??''))?:'Soumissionnaire #'.$filters['challenger'];
 $personFunction=trim((string)($personRow['function_name']??''));
 $serviceStmt=$pdo->prepare('SELECT DISTINCT COALESCE(NULLIF(sv.lib_service,""),"Service non renseigné") service_name FROM feb_price_challenger_settings s LEFT JOIN service sv ON sv.id_service=s.service_id WHERE s.user_id=? AND s.active=1 ORDER BY service_name');
 $serviceStmt->execute([$filters['challenger']]);$serviceNames=array_values(array_filter(array_map('trim',$serviceStmt->fetchAll(PDO::FETCH_COLUMN)?:[])));
 $personServices=$serviceNames?implode(', ',$serviceNames):(trim((string)($personRow['service_name']??''))?:'Service non renseigné');
}
$statusLabels=['pending'=>'À faire','submitted'=>'Soumise','retained'=>'Retenue','dismissed'=>'Non retenue'];
$data=[];foreach($rows as $r){$data[]=[
 'FEB'=>'FEB #'.(int)$r['feb_request_id'],'Besoin'=>(string)$r['designation'],'Chantier'=>(string)$r['chantier_label'],
 'Catégorie'=>(string)$r['category'],'Responsable'=>(string)$r['challenger_name'],'Service'=>(string)($r['challenger_service']??''),
 'Quantité'=>(float)$r['quantity'],'Montant demandé'=>(float)($r['initial_total_amount']??0),'Montant proposé'=>(float)($r['proposed_total_amount']??0),'Prix unitaire proposé'=>(float)($r['proposed_unit_price']??0),
 'Fournisseur'=>(string)($r['proposed_supplier_name']??''),
 'État'=>$statusLabels[$r['status']]??(string)$r['status'],'Justificatif'=>(string)($r['justification_path']??''),'Créée le'=>(string)$r['created_at'],
];}
$scope='Périmètre : '.$person.($filters['status']!==''?' | État : '.($statusLabels[$filters['status']]??$filters['status']):'').($filters['category']!==''?' | Catégorie : '.$filters['category']:'').($filters['date_from']!==''?' | Du '.$filters['date_from']:'').($filters['date_to']!==''?' au '.$filters['date_to']:'');
$filename='taches_devis_contradictoires_'.date('Ymd_His');
if($format==='xlsx'){
 $book=new Spreadsheet();$sheet=$book->getActiveSheet();$sheet->setTitle('Tâches devis FEB');$sheet->setShowGridlines(false);
 $sheet->mergeCells('A1:P1')->setCellValue('A1','FINEA | FEUILLE DE TÂCHES · DEVIS CONTRADICTOIRES');
 $sheet->getStyle('A1:P1')->applyFromArray(['font'=>['bold'=>true,'color'=>['ARGB'=>'FFFFFFFF'],'size'=>17],'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['ARGB'=>'FF0F172A']],'alignment'=>['vertical'=>Alignment::VERTICAL_CENTER]]);$sheet->getRowDimension(1)->setRowHeight(34);
 $sheet->mergeCells('A2:P2')->setCellValue('A2',$scope.' | Export du '.date('d/m/Y H:i').' | '.count($data).' tâche(s)');
 $sheet->getStyle('A2:P2')->applyFromArray(['font'=>['italic'=>true,'color'=>['ARGB'=>'FF475569']],'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['ARGB'=>'FFF1F5F9']]]);
 $headers=array_keys($data[0]??['FEB'=>'','Besoin'=>'','Chantier'=>'','Catégorie'=>'','Responsable'=>'','Service'=>'','Quantité'=>'','Montant demandé'=>'','Montant proposé'=>'','Prix unitaire proposé'=>'','Fournisseur'=>'','Début'=>'','Fin'=>'','État'=>'','Justificatif'=>'','Créée le'=>'']);
 $sheet->fromArray($headers,null,'A4');$sheet->getStyle('A4:P4')->applyFromArray(['font'=>['bold'=>true,'color'=>['ARGB'=>'FFFFFFFF']],'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['ARGB'=>'FF1E3A8A']],'alignment'=>['wrapText'=>true,'horizontal'=>Alignment::HORIZONTAL_CENTER],'borders'=>['allBorders'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['ARGB'=>'FFCBD5E1']]]]);
 $line=5;foreach($data as $row){$sheet->fromArray(array_values($row),null,'A'.$line);$sheet->getStyle('A'.$line.':P'.$line)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);if($row['Justificatif']!==''){$sheet->getCell('O'.$line)->getHyperlink()->setUrl($row['Justificatif']);$sheet->getCell('O'.$line)->setValue('Ouvrir le justificatif');}$line++;}
 foreach(range('A','P') as $column)$sheet->getColumnDimension($column)->setWidth(in_array($column,['B','C'],true)?34:18);$sheet->freezePane('A5');$sheet->setAutoFilter('A4:P'.max(4,$line-1));
 while(ob_get_level())ob_end_clean();header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');header('Content-Disposition: attachment; filename="'.$filename.'.xlsx"');(new Xlsx($book))->save('php://output');exit;
}
class FebChallengeTaskPdf extends TCPDF
{
 public string $logoPath='';
 public function Header():void
 {
  $this->SetFillColor(255,255,255);$this->Rect(0,0,$this->getPageWidth(),27,'F');
  $this->SetDrawColor(203,213,225);$this->Line(10,24.5,$this->getPageWidth()-10,24.5);
  $this->SetFillColor(181,146,74);$this->Rect(10,24.5,38,1.1,'F');
  if($this->logoPath!==''&&is_file($this->logoPath))$this->Image($this->logoPath,10,5.5,15,0,'','','',false,300);
  $this->SetXY(29,7);$this->SetFont('dejavusans','B',15);$this->SetTextColor(15,23,42);$this->Cell(80,6,'FINEA',0,1);
  $this->SetX(29);$this->SetFont('dejavusans','',8);$this->SetTextColor(71,85,105);$this->Cell(100,4.5,'Pilotage financier • Consultations FEB',0,1);
  $this->SetXY($this->getPageWidth()-105,7.5);$this->SetFont('dejavusans','B',9);$this->SetTextColor(30,41,59);$this->Cell(95,5,'FEUILLE DE TÂCHES',0,1,'R');
  $this->SetX($this->getPageWidth()-105);$this->SetFont('dejavusans','',7.5);$this->SetTextColor(100,116,139);$this->Cell(95,4,'Devis contradictoires • '.date('d/m/Y H:i'),0,1,'R');
 }
 public function Footer():void
 {
  $this->SetY(-13);$this->SetDrawColor(214,219,228);$this->Line(10,$this->GetY(),$this->getPageWidth()-10,$this->GetY());$this->Ln(2);
  $this->SetFont('dejavusans','',7.5);$this->SetTextColor(100,116,139);$this->Cell(0,4,'FINEA • Document de travail confidentiel • Page '.$this->getAliasNumPage().'/'.$this->getAliasNbPages(),0,0,'C');
 }
}
$pdf=new FebChallengeTaskPdf('L','mm','A4',true,'UTF-8',false);$pdf->logoPath=__DIR__.'/../logo/default_logo.jpg';
$pdf->SetCreator('FINEA');$pdf->SetAuthor('FINEA');$pdf->SetTitle('Feuille de tâches des devis contradictoires');
$pdf->SetMargins(10,28,10);$pdf->SetAutoPageBreak(false,18);$pdf->AddPage();
$pageWidth=$pdf->getPageWidth()-20;$bottom=$pdf->getPageHeight()-18;
$pdf->SetFillColor(248,250,252);$pdf->SetDrawColor(226,232,240);$pdf->RoundedRect(10,32,$pageWidth,25,4,'1111','DF');
$pdf->SetFillColor(15,118,110);$pdf->RoundedRect(10,32,4,25,4,'1101','F');
$pdf->SetXY(18,36);$pdf->SetFont('dejavusans','B',15);$pdf->SetTextColor(15,23,42);$pdf->Cell($pageWidth-16,6,'Consultations de prix à piloter',0,1);
$pdf->SetX(18);$pdf->SetFont('dejavusans','',8.5);$pdf->SetTextColor(71,85,105);$pdf->MultiCell($pageWidth-16,4.5,$scope,0,'L',false,1);
$cards=[
 ['Soumissionnaire',$person],
 ['Service(s)',$personServices],
 ['Fonction',$personFunction!==''?$personFunction:'Toutes fonctions'],
 ['Volume',count($data).' tâche(s)'],
];
$gap=4;$cardWidth=($pageWidth-$gap*3)/4;$cardY=62;
foreach($cards as $index=>$card){$x=10+($cardWidth+$gap)*$index;$pdf->SetFillColor(255,255,255);$pdf->SetDrawColor(226,232,240);$pdf->RoundedRect($x,$cardY,$cardWidth,17,3,'1111','DF');$pdf->SetXY($x+3,$cardY+2);$pdf->SetFont('dejavusans','',6.8);$pdf->SetTextColor(100,116,139);$pdf->Cell($cardWidth-6,3.5,$card[0],0,1);$pdf->SetXY($x+3,$cardY+6);$pdf->SetFont('dejavusans','B',8.2);$pdf->SetTextColor(15,23,42);$pdf->MultiCell($cardWidth-6,4,$card[1],0,'L',false,1);}
$columns=[
 ['FEB',16,'C'],['Besoin / chantier',49,'L'],['Catégorie',20,'L'],['Responsable / service',36,'L'],['Qté',12,'C'],
 ['Demandé',25,'R'],['Proposé',28,'R'],['Fournisseur',28,'L'],['Planning',28,'C'],['État',20,'C'],['Pièce',15,'C'],
];
$drawHeader=static function(FebChallengeTaskPdf $doc,array $columns):void{
 $x=10;$y=$doc->GetY();$height=11;
 foreach($columns as [$label,$width,$align]){$doc->SetFillColor(30,58,138);$doc->SetDrawColor(255,255,255);$doc->Rect($x,$y,$width,$height,'DF');$doc->SetXY($x+1,$y+2);$doc->SetFont('dejavusans','B',6.7);$doc->SetTextColor(255,255,255);$doc->MultiCell($width-2,3.4,$label,0,$align,false,0);$x+=$width;}
 $doc->SetY($y+$height);
};
$pdf->SetY(84);$drawHeader($pdf,$columns);
if(!$data){$pdf->SetFillColor(248,250,252);$pdf->SetDrawColor(226,232,240);$pdf->Rect(10,$pdf->GetY(),$pageWidth,18,'DF');$pdf->SetXY(12,$pdf->GetY()+5);$pdf->SetFont('dejavusans','',9);$pdf->SetTextColor(100,116,139);$pdf->Cell($pageWidth-4,6,'Aucune tâche ne correspond aux filtres sélectionnés.',0,1,'C');}
foreach($data as $index=>$row){
 $values=[
  $row['FEB'],
  trim($row['Besoin']."\n".$row['Chantier']),
  $row['Catégorie'],
  trim($row['Responsable']."\n".($row['Service']?:'Service non renseigné')),
  number_format((float)$row['Quantité'],2,',',' '),
  number_format((float)$row['Montant demandé'],0,',',' ')." FCFA",
  $row['Montant proposé']>0?number_format((float)$row['Montant proposé'],0,',',' ')." FCFA\nPU ".number_format((float)$row['Prix unitaire proposé'],2,',',' '):'À renseigner',
  $row['Fournisseur']!==''?$row['Fournisseur']:'—',
  ($row['Début']?:'—')."\n→ ".($row['Fin']?:'—'),
  $row['État'],
  $row['Justificatif']!==''?'Disponible':'Absent',
 ];
 $pdf->SetFont('dejavusans','',6.5);$height=10;
 foreach($values as $cellIndex=>$value)$height=max($height,$pdf->getStringHeight($columns[$cellIndex][1]-2,(string)$value)+3);
 if($pdf->GetY()+$height>$bottom){$pdf->AddPage();$pdf->SetY(31);$drawHeader($pdf,$columns);}
 $x=10;$y=$pdf->GetY();
 foreach($values as $cellIndex=>$value){
  [$label,$width,$align]=$columns[$cellIndex];
  if($index%2===0)$pdf->SetFillColor(248,250,252);else $pdf->SetFillColor(255,255,255);
  if($cellIndex===9&&$row['État']==='Retenue')$pdf->SetFillColor(236,253,245);
  if($cellIndex===9&&$row['État']==='À faire')$pdf->SetFillColor(255,251,235);
  $pdf->SetDrawColor(226,232,240);$pdf->Rect($x,$y,$width,$height,'DF');$pdf->SetXY($x+1,$y+1.5);
  $pdf->SetFont('dejavusans',in_array($cellIndex,[0,5,6,9],true)?'B':'',6.5);$pdf->SetTextColor(30,41,59);
  $pdf->MultiCell($width-2,3.3,(string)$value,0,$align,false,0);$x+=$width;
 }
 $pdf->SetY($y+$height);
}
while(ob_get_level())ob_end_clean();$pdf->Output($filename.'.pdf','I');
