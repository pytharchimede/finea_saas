<?php
declare(strict_types=1);
require_once __DIR__.'/../auth_check.php';
require_once __DIR__.'/../model/Database.php';
require_once __DIR__.'/../vendor/tecnickcom/tcpdf/tcpdf.php';

if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){http_response_code(405);exit('Méthode refusée.');}
$devisId=(int)($_POST['devis_id']??0);
$ligneId=(int)($_POST['ligne_id']??0);
if($devisId<=0||$ligneId<=0){http_response_code(400);exit('Ligne invalide.');}
$pdo=Database::getConnection();
$check=$pdo->prepare('SELECT ld.designation,d.numero_devis FROM ligne_devis_banamur ld JOIN devis_banamur d ON d.id=ld.devis_id WHERE ld.id=:ligne AND ld.devis_id=:devis LIMIT 1');
$check->execute(['ligne'=>$ligneId,'devis'=>$devisId]);
$row=$check->fetch(PDO::FETCH_ASSOC);
if(!$row){http_response_code(404);exit('Ligne introuvable.');}
$issue=mb_substr(trim((string)($_POST['issue']??'')),0,1000);
$needsRefresh=($_POST['needs_refresh']??'')==='1';
$pdf=new TCPDF('P','mm','A4',true,'UTF-8',false);
$pdf->SetCreator('FINEA');$pdf->SetAuthor('BANAMUR · FINEA');$pdf->SetTitle('Contrôle des corrections du déboursé');
$pdf->setPrintHeader(false);$pdf->setPrintFooter(false);$pdf->SetMargins(16,16,16);$pdf->SetAutoPageBreak(true,18);$pdf->AddPage();
$logo=dirname(__DIR__).'/img/app_logo.png';if(is_file($logo))$pdf->Image($logo,16,14,43,0,'PNG');
$pdf->SetXY(116,17);$pdf->SetFont('dejavusans','B',9);$pdf->SetTextColor(37,35,91);$pdf->Cell(78,6,'FINEA · CONTRÔLE DOCUMENTÉ',0,1,'R');
$pdf->SetX(116);$pdf->SetFont('dejavusans','',7);$pdf->Cell(78,5,'Réf. FIN-DEB-CTRL · Version 1 · '.date('d/m/Y'),0,1,'R');
$pdf->SetDrawColor(65,46,160);$pdf->Line(16,36,194,36);$pdf->SetY(43);
$pdf->SetTextColor(24,31,56);$pdf->SetFont('dejavusans','B',18);$pdf->MultiCell(0,11,'Points à corriger · Déboursé',0,'L');
$pdf->SetFont('dejavusans','',9);$pdf->MultiCell(0,6,'Devis '.(string)$row['numero_devis'].'  ·  Ligne #'.$ligneId.'  ·  '.(string)$row['designation'],0,'L');$pdf->Ln(5);
$h=static fn(string $text):string=>htmlspecialchars($text,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$steps=[
    'Ouvrir le devis '.(string)$row['numero_devis'].' et la ligne #'.$ligneId.' : '.(string)$row['designation'].'.',
    'Déplier « Détails », puis aller au tableau « Sous-lignes de déboursé ».',
    $needsRefresh?'Cliquer sur « Recharger les postes de cette ligne » après avoir noté les modifications non enregistrées. La liste sera reprise depuis la base.':'Corriger les champs de la ligne concernée : catégorie, désignation, montant, responsable et période.',
    'Vérifier que le montant déboursé calculé correspond à la somme des sous-lignes.',
    'Cliquer sur « Enregistrer cette ligne », puis vérifier le message de confirmation.'
];
$html='<table cellpadding="8" cellspacing="0" width="100%"><tr style="background-color:#eef2ff"><td><b>ANOMALIE À TRAITER</b><br>'.$h($issue?:'Enregistrement non terminé.').'</td></tr></table><br><h3>Liste des actions</h3><ol>';
foreach($steps as $step)$html.='<li>'.$h($step).'</li>';
$html.='</ol><p style="color:#64748b">Ce document guide la correction ; il ne modifie aucune donnée et ne constitue pas une validation financière.</p>';
$pdf->SetFont('dejavusans','',9);$pdf->writeHTML($html,true,false,true,false,'');
$pdf->SetY(-15);$pdf->SetDrawColor(200,210,224);$pdf->Line(16,$pdf->GetY(),194,$pdf->GetY());$pdf->SetFont('dejavusans','',7);$pdf->SetTextColor(90,102,125);$pdf->Cell(0,7,'FINEA · BANAMUR  |  Document de travail · '.date('d/m/Y H:i'),0,0,'L');
$pdf->Output('FINEA-corrections-debourse-'.$devisId.'-'.$ligneId.'.pdf','D');
