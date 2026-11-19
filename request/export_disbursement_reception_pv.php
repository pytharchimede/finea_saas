<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/DisbursementRegularizationManager.php';
require_once __DIR__ . '/../decaissement/model/FicheDecaissement.php';
require_once __DIR__ . '/../vendor/autoload.php';

$manager = new DisbursementRegularizationManager(Database::getConnection());
$num = trim((string)($_GET['num_fiche'] ?? ''));
$fiche = $manager->getFiche($num);
$record = $manager->find($num);
$uid = (int)($_SESSION['user_id'] ?? 0);
$allowed = $fiche && $record && ($manager->isRequester($fiche, $uid) || hasPermission('acces_compta') || hasPermission('acces_tresorerie') || hasPermission('acces_direction') || hasPermission('acces_admin'));
if (!$allowed || empty($record['submitted_at'])) { http_response_code(403); exit('Document indisponible.'); }
$advanceSuggestion=(new FicheDecaissement(Database::getConnection()))->getAdvanceAmortizationSuggestion($num);

$kind = (string)$record['reception_kind'];
$nouns = [
    'travaux'=>['title'=>'PROCÈS-VERBAL DE RÉCEPTION DES TRAVAUX','object'=>'travaux exécutés','state'=>'ÉTAT DES TRAVAUX','remaining'=>'travaux restant à exécuter'],
    'fourniture'=>['title'=>'PROCÈS-VERBAL DE RÉCEPTION DES FOURNITURES','object'=>'biens et fournitures livrés','state'=>'ÉTAT DE LA FOURNITURE','remaining'=>'livraisons restant à effectuer'],
    'prestation'=>['title'=>'PROCÈS-VERBAL DE RÉCEPTION DE LA PRESTATION','object'=>'prestations réalisées','state'=>'ÉTAT DE LA PRESTATION','remaining'=>'prestations restant à réaliser'],
    'autre'=>['title'=>'PROCÈS-VERBAL D’ATTESTATION D’EXÉCUTION','object'=>'actions réalisées','state'=>'ÉTAT D’EXÉCUTION','remaining'=>'actions restant à réaliser'],
];
$word = $nouns[$kind] ?? $nouns['autre'];
$states=['without_reserve'=>'Réceptionné / exécuté sans réserve','with_reserve'=>'Réceptionné / exécuté avec réserves','pending'=>'En attente d’achèvement ou de livraison','not_received'=>'Non réceptionné / non exécuté'];
$e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
$d=static fn($v)=>$v ? date('d/m/Y',strtotime((string)$v)) : '—';
$completionLines=json_decode((string)($record['completion_lines_json'] ?? ''),true);
if (!is_array($completionLines) || $completionLines === []) $completionLines=$manager->getFebLines($fiche);
$completionPercentage=(float)($record['completion_percentage'] ?? 0);
$completionRows='';
foreach ($completionLines as $line) {
    $done=!empty($line['completed']);
    $completionRows.='<tr nobr="true">
      <td width="49%"><b>'.$e($line['label'] ?? 'Ligne FEB').'</b><br><span style="color:#64748b">'.$e(trim(implode(' · ',array_filter([(string)($line['type'] ?? ''),(string)($line['category'] ?? ''),(string)($line['supplier'] ?? '')])))).'</span></td>
      <td width="17%" align="right">'.number_format((float)($line['amount'] ?? 0),0,',',' ').' FCFA</td>
      <td width="14%" align="center">'.number_format((float)($line['weight'] ?? 0),2,',',' ').' %</td>
      <td width="20%" align="center" style="background-color:'.($done?'#dcfce7':'#ffedd5').';color:'.($done?'#047857':'#c2410c').';font-weight:bold">'.($done?'✓ EFFECTUÉ':'● RESTANT<br><span style="font-size:7.5px">Début prévu : '.$d($line['pending_start_date'] ?? null).'</span>').'</td>
    </tr>';
}
$identity=json_decode((string)($record['signer_snapshot_json'] ?? ''),true);
if (!is_array($identity) || $identity === []) {
    $identity=$manager->getUserIdentity((int)($record['signer_user_id'] ?? $record['requester_user_id'] ?? 0));
}
$isPo=(string)($record['signer_mode'] ?? '')==='accounting_po';
$requesterIdentity=$manager->getUserIdentity((int)($record['requester_user_id'] ?? 0));
$requesterName=trim((string)($requesterIdentity['full_name'] ?? ''));
if ($requesterName === '') $requesterName=trim((string)($fiche['beficiaire_fiche'] ?? ''));
$requesterDetails=trim(implode(' · ',array_filter([
    (string)($requesterIdentity['fonction'] ?? ''),
    (string)($requesterIdentity['service'] ?? ''),
    !empty($requesterIdentity['matricule']) ? 'Matricule '.$requesterIdentity['matricule'] : '',
])));
$root=realpath(dirname(__DIR__));
$photoData='';
$photoRelative=trim((string)($identity['photo'] ?? ''));
foreach (array_unique([
    $photoRelative,
    'photo/'.$photoRelative,
    'uploads/photos/'.$photoRelative,
    'uploads/personnel/'.$photoRelative,
]) as $candidate) {
    if ($candidate==='' || !$root) continue;
    $photoFile=realpath($root.'/'.ltrim($candidate,'/'));
    if (!$photoFile || strpos($photoFile,$root.'/')!==0 || !is_file($photoFile)) continue;
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($photoFile);
    if (is_string($mime) && str_starts_with($mime,'image/')) {
        $photoData='data:'.$mime.';base64,'.base64_encode((string)file_get_contents($photoFile));
        break;
    }
}
$pdf=new TCPDF('P','mm','A4',true,'UTF-8',false);
$pdf->SetCreator('Finéa');$pdf->SetAuthor((string)$record['signer_name']);$pdf->SetTitle($word['title'].' '.$num);
$pdf->setPrintHeader(false);$pdf->setPrintFooter(true);$pdf->SetMargins(17,15,17);$pdf->AddPage();
// DejaVu Sans contient réellement les pictogrammes Unicode utilisés dans le
// tableau, contrairement à Helvetica qui les remplaçait par un « ? ».
$pdf->SetFont('dejavusans','',9);
$html='<style>h1{font-size:16px;text-align:center;color:#172554}h2{font-size:11px;color:#312e81;border-bottom:1px solid #c7d2fe;padding-bottom:3px;page-break-after:avoid}p,td{font-size:9.5px;line-height:1.45}.meta td,.progress td{border:1px solid #dbeafe;padding:5px}.progress-head td{background-color:#172554;color:#fff;font-weight:bold}.box{border:1px solid #cbd5e1;background-color:#f8fafc;padding:7px}.identity td{border:1px solid #c7d2fe;background-color:#eef2ff;padding:7px}.sign{border-top:1px solid #334155;text-align:center}</style>';
$html.='<h1>'.$e($word['title']).'</h1><p style="text-align:center"><b>Fiche de décaissement '.$e($num).'</b></p>';
$html.='<div nobr="true"><table class="meta" cellpadding="4"><tr><td><b>Projet / chantier</b><br>'.$e($record['project_name']).'</td><td><b>Lieu / chantier concerné</b><br>'.$e($record['project_location']).'</td></tr><tr><td><b>Maître d’ouvrage</b><br>'.$e($record['owner_name']).'</td><td><b>Entreprise / fournisseur</b><br>'.$e($record['executing_company']).'</td></tr><tr><td colspan="2" style="font-size:11px"><b>Date de réception / constat</b><br>'.$d($record['reception_date']).'</td></tr></table></div>';
$html.='<div nobr="true"><h2>1. OBJET</h2><p>Le présent procès-verbal constate l’état des '.$e($word['object']).' dans le cadre du projet susmentionné, au regard de la demande ayant donné lieu au décaissement.</p></div>';
$html.='<h2>2. AVANCEMENT PONDÉRÉ DES LIGNES DE LA FEB</h2>';
$html.='<table class="progress" cellpadding="5"><thead><tr class="progress-head"><td width="49%">LIGNE FEB</td><td width="17%" align="right">MONTANT</td><td width="14%" align="center">POIDS</td><td width="20%" align="center">ÉTAT</td></tr></thead><tbody>'.$completionRows.'</tbody></table>';
$barDone=max(0,min(100,$completionPercentage));$barRemaining=100-$barDone;
$html.='<table cellpadding="0" cellspacing="0"><tr>'
    .($barDone>0?'<td width="'.$barDone.'%" height="12" style="background-color:#10b981"></td>':'')
    .($barRemaining>0?'<td width="'.$barRemaining.'%" height="12" style="background-color:#fb923c"></td>':'')
    .'</tr></table><p style="text-align:center;font-size:13px;color:#312e81"><b>'.$e(number_format($completionPercentage,2,',',' ')).' % EFFECTUÉ</b></p>';
$html.='<div nobr="true"><h2>3. OBSERVATION GÉNÉRALE</h2><p class="box">'.nl2br($e($record['observations'] ?: 'Aucune observation particulière.')).'</p></div>';
$html.='<div nobr="true"><h2>4. CONSÉQUENCES FINANCIÈRES</h2><p>Montant de la fiche concernée : <b>'.number_format((float)$record['validated_amount'],0,',',' ').' FCFA</b>. Le taux d’avancement ci-dessus est pondéré par la fraction du prix de chaque ligne dans le total de la FEB et constitue une pièce justificative postérieure au décaissement.</p>';
if (!empty($advanceSuggestion['is_provisional'])) {
    $advance=(float)($advanceSuggestion['advance_paid'] ?? 0);
    $total=(float)($advanceSuggestion['contract_total'] ?? 0);
    $html.='<p style="border:1px solid #f59e0b;background-color:#fffbeb;padding:8px"><b style="color:#92400e">AVANCE À AMORTIR SUR LES AVANCEMENTS SUIVANTS</b><br>'
        .'Montant contractuel de référence : <b>'.number_format($total,0,',',' ').' FCFA</b><br>'
        .'Avance déjà versée : <b>'.number_format($advance,0,',',' ').' FCFA</b><br>'
        .'Cette avance n’est pas déduite en une seule fois. À chaque paiement futur, la suggestion nette sera calculée sur la fraction de travaux réalisée, après déduction de la même fraction de l’avance. Exemple de règle : pour une nouvelle tranche d’avancement de 30 %, la retenue d’avance correspond à <b>30 % de l’avance initiale</b>. Le montant finalement retenu reste, à ce stade, soumis à la décision de la Direction et de la comptabilité.</p>';
}
$html.='</div>';
$html.='<div nobr="true"><h2>5. '.($isPo?'ATTESTATION POUR ORDRE':'ENGAGEMENT DU DEMANDEUR').'</h2>';
$html.=$isPo
    ? '<p class="box">Je soussigné(e), <b>'.$e($record['signer_name']).'</b>, agissant pour le service comptable, signe le présent document <b>POUR ORDRE (PO)</b>. Cette signature identifie personnellement le collaborateur ayant effectué la soumission comptable.<br><br><b>Responsable opérationnel du suivi et de la traçabilité :</b> '.$e($requesterName !== '' ? $requesterName : 'Demandeur initial de la fiche').($requesterDetails !== '' ? '<br>'.$e($requesterDetails) : '').'. Le demandeur initial demeure responsable du suivi des lignes restant à exécuter, de la sincérité des informations opérationnelles et de la transmission des éléments complémentaires jusqu’à l’achèvement complet.</p>'
    : '<p class="box">Je soussigné(e), <b>'.$e($record['signer_name']).'</b>, certifie sincères les informations du présent document. Lorsque l’exécution demeure partielle, je m’engage à suivre toutes les lignes automatiquement identifiées comme restantes jusqu’à leur achèvement.</p>';
$html.='</div>';
$pdf->writeHTML($html,true,false,true,false,'');
if ($pdf->GetY() > $pdf->getPageHeight()-92) $pdf->AddPage();
$signatureHtml='<style>h2{font-size:11px;color:#312e81;border-bottom:1px solid #c7d2fe;padding-bottom:3px}p,td{font-size:9.5px;line-height:1.45}.identity td{border:1px solid #c7d2fe;background-color:#eef2ff;padding:7px}</style><div nobr="true"><h2>6. SIGNATURE</h2><p>Fait à <b>Abidjan</b>, le '.$d($record['signed_at']).'</p>';
$photoCell=$photoData!==''?'<img src="'.$e($photoData).'" width="54" height="54">':'<span style="font-size:8px;color:#64748b">Photo non disponible</span>';
$signatureHtml.='<table class="identity" cellpadding="5"><tr nobr="true"><td width="22%" align="center">'.$photoCell.'</td><td width="78%"><b style="font-size:11px">'.$e($identity['full_name'] ?? $record['signer_name']).'</b><br>'.$e($identity['fonction'] ?? 'Fonction non renseignée').'<br>'.$e($identity['service'] ?? 'Service non renseigné').(!empty($identity['matricule'])?'<br>Matricule : '.$e($identity['matricule']):'').($isPo?'<br><b style="color:#7c2d12">SIGNÉ POUR ORDRE (PO) — COMPTABILITÉ</b>':'').'</td></tr></table></div>';
$pdf->writeHTML($signatureHtml,true,false,true,false,'');
$accountingNote = trim((string)($record['accounting_note'] ?? ''));
$signature=(string)$record['signature_data'];
if (preg_match('#^data:image/png;base64,(.+)$#s',$signature,$m)) {
    $binary=base64_decode(preg_replace('/\\s+/','',$m[1]),true);
    if ($binary!==false) $pdf->Image('@'.$binary,75,$pdf->GetY()+1,60,24,'PNG');
}
$pdf->Ln(28);$pdf->writeHTML('<p style="text-align:center"><b>'.$e($record['signer_name']).'</b><br>'.($isPo?'Signataire comptable — Pour ordre (PO)':'Demandeur et signataire').'</p>',true,false,true,false,'');
if (strpos($accountingNote, 'Documents mis à jour par la comptabilité') === 0) {
    $pdf->writeHTML('<div nobr="true"><p class="box"><b>TRAÇABILITÉ :</b> '.$e($accountingNote).'. La signature électronique originale du demandeur a été conservée.</p></div>',true,false,true,false,'');
}
if (($record['accounting_status'] ?? '')==='approved') {
    $pdf->writeHTML('<div nobr="true"><p style="border:1px solid #cbd5e1;background-color:#f8fafc;padding:7px"><b>VISA COMPTABLE :</b> Document validé le '.$d($record['accounting_validated_at']).' par '.$e($record['accounting_validator_name']).'.</p></div>',true,false,true,false,'');
}
$pdf->Output('PV-reception-'.$num.'.pdf','I');
