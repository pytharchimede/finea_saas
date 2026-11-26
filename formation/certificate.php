<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/auth_check.php';
require_once __DIR__.'/controllers/FormationAcademyController.php';
require_once dirname(__DIR__).'/model/CompanyBranding.php';
require_once dirname(__DIR__).'/model/CompanyExportBranding.php';
require_once dirname(__DIR__).'/vendor/autoload.php';
$uid=(int)($_SESSION['user_id']??0);$p=$_SESSION['permissions']??[];
if (!$uid || (empty($p['acces_formation'])&&empty($p['formation_admin'])&&empty($p['acces_admin']))) {http_response_code(403);exit;}
$pdo=Database::getConnection();new \Formation\Controllers\FormationAcademyController($pdo);
$isPath=isset($_POST['path']);
if($isPath){
 if(!hash_equals((string)($_SESSION['academy_csrf']??''),(string)($_POST['academy_csrf']??''))||empty($_SESSION['academy_csrf'])){http_response_code(403);exit;}
 require_once __DIR__.'/model/AcademyPaths.php';
 $controller=new \Formation\Controllers\FormationAcademyController($pdo);
 $allowed=array_map('intval',array_column($controller->dashboard($uid,$p,!empty($p['formation_admin'])||!empty($p['acces_admin']))['courses'],'id'));
 try{$cert=academyIssuePath($pdo,$uid,(int)$_POST['path'],$allowed);}catch(RuntimeException $e){http_response_code(403);exit('Parcours non validé.');}
}else{
$q=$pdo->prepare('SELECT cert.*,c.title FROM formation_certificates cert JOIN formation_courses c ON c.id=cert.course_id WHERE cert.user_id=? AND cert.course_id=? AND (cert.expires_at IS NULL OR cert.expires_at>NOW()) ORDER BY cert.issued_at DESC LIMIT 1');
$q->execute([$uid,(int)($_GET['course']??0)]);$cert=$q->fetch(PDO::FETCH_ASSOC);
if (!$cert) {http_response_code(404);exit('Certificat indisponible.');}
}
$name=trim((string)($_SESSION['user_name']??''));if ($name==='') {http_response_code(409);exit('Identité du salarié indisponible.');}
$brand=CompanyBranding::get();$company=(string)$brand['company_name'];
$pdf=new TCPDF('L','mm','A4',true,'UTF-8',false);$pdf->setPrintHeader(false);$pdf->setPrintFooter(false);$pdf->SetAutoPageBreak(false);$pdf->SetMargins(20,15,20);$pdf->SetCreator(CompanyExportBranding::creator());$pdf->SetTitle('Certificat - '.$cert['certificate_ref']);$pdf->AddPage();
$pdf->SetDrawColor(...CompanyExportBranding::rgbArray('primary'));$pdf->SetLineWidth(1);$pdf->Rect(7,7,283,196);$pdf->SetLineWidth(.35);$pdf->Rect(10,10,277,190);
$logos=[getenv('FINEA_ACADEMY_LOGO_FILE')?:dirname(__DIR__).'/img/app_logo.png',CompanyBranding::logoFile()];
foreach($logos as $i=>$logo){if(is_file($logo)&&($info=@getimagesize($logo))&&in_array($info[2],[IMAGETYPE_PNG,IMAGETYPE_JPEG],true)){$w=min(48,20*$info[0]/$info[1]);$pdf->Image($logo,$i?277-$w:20,16,$w,0);}}
$pdf->SetTextColor(25,35,50);$pdf->SetFont('dejavusans','B',12);$pdf->SetXY(20,39);$pdf->Cell(125,8,'FINEA Academy',0,0,'L');$pdf->Cell(132,8,$company,0,1,'R',false,'',1);
$pdf->SetFont('dejavusans','B',26);$pdf->SetXY(20,53);$pdf->Cell(257,15,$isPath?'CERTIFICAT DE PARCOURS':'CERTIFICAT DE COURS',0,1,'C');
$pdf->SetFont('dejavusans','',12);$pdf->Cell(257,10,'Attestation interne de réussite décernée à',0,1,'C');
$pdf->SetFont('dejavusans','B',25);$pdf->Cell(257,17,$name,0,1,'C',false,'',1);
$pdf->SetFont('dejavusans','',12);$pdf->Cell(257,10,$isPath?'Pour avoir validé tous les cours du parcours':'Pour avoir validé avec succès le cours',0,1,'C');
$pdf->SetFont('dejavusans','B',19);$pdf->MultiCell(257,22,(string)$cert['title'],0,'C',false,1);
$pdf->SetFont('dejavusans','',13);$pdf->Cell(257,10,'Score : '.number_format((float)$cert['score'],2,',',' ').' % — '.$cert['level_label'],0,1,'C');
$pdf->SetXY(20,155);$pdf->SetFont('dejavusans','',10);$pdf->Cell(257,8,'Délivré le '.date('d/m/Y',strtotime($cert['issued_at'])),0,1,'C');
$pdf->SetXY(20,178);$pdf->Cell(225,6,'Référence : '.$cert['certificate_ref'],0,1);
$pdf->Cell(225,6,'Vérification interne : '.$cert['verification_code'],0,1,'L',false,'',1);
$pdf->write2DBarcode((string)$cert['verification_code'],'QRCODE,M',252,166,24,24);
$pdf->Output('certificat-'.$cert['certificate_ref'].'.pdf','D');
