<?php
require_once __DIR__.'/../model/Auth.php';
require_once __DIR__.'/../model/AccessManager.php';
if(session_status()!==PHP_SESSION_ACTIVE)session_start();
header('Referrer-Policy: no-referrer');header('Cache-Control: no-store');
$invitationToken=is_string($_GET['token'] ?? null)?$_GET['token']:'';
$invitationPreviewId=is_scalar($_GET['preview_id'] ?? null)?max(0,(int)$_GET['preview_id']):0;
if (!Auth::isLoggedIn() && (preg_match('/^[a-f0-9]{64}$/D',$invitationToken) || $invitationPreviewId>0)) {
    // Canonical internal target: never redirect to an arbitrary URL supplied by the client.
    $_SESSION['post_login_redirect']=AccessManager::getApplicationBasePath().'/rh/validation_point_financier.php?'.($invitationPreviewId>0?'preview_id='.$invitationPreviewId:'token='.$invitationToken);
    header('Location: '.AccessManager::getApplicationBasePath().'/login.php');exit;
}
require_once __DIR__.'/../auth_check.php';
AccessManager::requirePageAccess();
require_once __DIR__.'/../services/RhFinancialPointService.php';
$pdo=Database::getConnection();$service=new RhFinancialPointService($pdo);$userId=(int)($_SESSION['user_id'] ?? 0);
header('Referrer-Policy: no-referrer');header('Cache-Control: no-store');
$_SESSION['rh_financial_csrf']??=bin2hex(random_bytes(32));$csrf=$_SESSION['rh_financial_csrf'];$token=$invitationToken;$p=null;$error='';$success=false;$feb=0;$adminPreview=false;
try {
    $previewId=is_scalar($_GET['preview_id'] ?? null)?(int)$_GET['preview_id']:0;
    $explicitPreview=($_GET['preview'] ?? '')==='1' || $previewId>0;
    $adminPreview=$explicitPreview || (AccessManager::hasPermission('acces_admin') && (int)($service->settings()['dg_user_id'] ?? 0)!==$userId);
    if($adminPreview) {
        if(($_SERVER['REQUEST_METHOD'] ?? 'GET')!=='GET')throw new DomainException('Le test administrateur est en lecture seule. Aucune signature ni FEB ne peut être créée.');
        $p=$service->previewInvitation($userId,$previewId,$token);
    } else $p=$service->fromToken($token,$userId);
    if(!$adminPreview && $_SERVER['REQUEST_METHOD']==='POST') {
        if(!hash_equals($csrf,(string)($_POST['_csrf'] ?? '')))throw new DomainException('Session expirée.');
        if(empty($_POST['confirmation']))throw new DomainException('Confirmez la validation du point.');
        $feb=$service->sign((int)$p['id'],(int)$_POST['revision'],$userId,(array)($_POST['lines'] ?? []),$token,(string)($_POST['signature_method'] ?? 'passkey'),(string)($_POST['dg_manual_signature'] ?? ''));$success=true;
    }
}catch(Throwable $e){$error=$e->getMessage();}
$h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');$money=static fn($v)=>number_format((float)$v,0,',',' ').' FCFA';$pageTitle='Signature du point financier RH';$activeMenu='rh';require_once __DIR__.'/../decaissement/includes/header.php';
?>
<link rel="stylesheet" href="../css/rh-financial.css?v=20261014-flow"><main class="rh-points"><header class="rh-hero"><p class="rh-eyebrow">FINEA · Ressources humaines</p><h1>Validation du point financier RH</h1><p>Vérifiez les montants, prenez vos décisions et signez cette version.</p><a class="rh-btn" href="historique_points_financiers_rh.php">Historique des points RH</a></header>
<?php if($adminPreview):?><div class="rh-banner" role="status"><b>Test administrateur - sans expiration</b><br>Aperçu du parcours DG en lecture seule. Ce test ne consomme pas son lien, ne modifie pas le point et ne permet pas de signer à sa place.</div><?php endif?>
<?php if($error):?><div role="alert" class="rh-banner"><?=$h($error)?></div><p>Utilisez le compte FINEA du DG désigné. Si le lien a expiré ou a été remplacé, les RH peuvent renvoyer une invitation depuis l’historique du point.</p><?php endif?>
<?php if($success):?><section style="padding:24px;background:#dcfce7;margin:20px 0"><h2>Signature enregistrée</h2><p><?=$feb?'FEB #'.$feb.' créée avec le PDF final comme justificatif. Le décaissement suit le circuit Finances.':'Aucune ligne retenue : le point est signé sans création de FEB.'?></p><a href="points_financiers_rh.php?id=<?=(int)$p['id']?>">Consulter le point signé</a></section>
<?php elseif($p):?><section><?php $rhPointSummary=$p;require __DIR__.'/includes/financial_point_summary.php';?>
<form method="post" data-rh-manual-signature <?=$adminPreview?'data-admin-preview':'data-passkey-signature'?> data-passkey-endpoint-base="../request" data-passkey-context="rh_financial:<?=(int)$p['id']?>:<?=(int)$p['revision']?>:dg"><?php if($adminPreview):?><fieldset disabled><legend>Aperçu des décisions et signatures</legend><?php endif?><input type="hidden" name="_csrf" value="<?=$h($csrf)?>"><input type="hidden" name="revision" value="<?=(int)$p['revision']?>"><div style="overflow:auto;margin-top:20px"><table style="width:100%;min-width:850px;text-align:left"><thead><tr><th>Collaborateur / demande</th><th>Demandé</th><th>Montant retenu</th><th>Décision</th><th>Report / motif</th></tr></thead><tbody><?php foreach($p['snapshot']['lines'] as $line):$key=$line['key'];?><tr style="border-bottom:1px solid #ddd"><td style="padding:12px"><?=$h($line['name'])?><br><small><?=$h($line['label'])?></small></td><td><?=$money($line['initial_amount'])?></td><td><input style="border:1px solid #ccc;padding:8px;width:150px" type="number" step="1" min="0" name="lines[<?=$h($key)?>][amount]" value="<?=(int)$line['amount']?>" required></td><td><select style="border:1px solid #ccc;padding:8px" name="lines[<?=$h($key)?>][decision]"><?php foreach(['approved'=>'Autoriser','rejected'=>'Refuser','cancelled'=>'Annuler','deferred'=>'Reporter'] as $value=>$label):?><option value="<?=$value?>" <?=$line['decision']===$value?'selected':''?>><?=$label?></option><?php endforeach?></select></td><td><input style="border:1px solid #ccc;padding:8px" type="date" name="lines[<?=$h($key)?>][deferred_until]" value="<?=$h($line['deferred_until'])?>"><br><input style="border:1px solid #ccc;padding:8px" name="lines[<?=$h($key)?>][note]" value="<?=$h($line['note'])?>" placeholder="Motif ou précision"></td></tr><?php endforeach?></tbody></table></div><p style="margin:20px 0">Total retenu : <strong id="rh-total"></strong></p><label><input type="checkbox" name="confirmation" value="1" required> Je valide ces décisions et confirme ma signature sur la version finale du récapitulatif.</label><fieldset style="margin:20px 0"><legend>Mode de signature du DG</legend><label><input type="radio" name="signature_method" value="passkey" checked> Confirmer avec ma Passkey</label><br><label><input type="radio" name="signature_method" value="manual"> Signer manuellement</label></fieldset>
<div><p>Pour signer manuellement, sélectionnez ce mode et dessinez votre signature à la souris ou au doigt.</p><input type="hidden" name="dg_manual_signature" data-rh-manual-value value=""><canvas data-rh-manual-canvas width="600" height="180" aria-label="Zone de signature manuscrite DG" style="<?=$adminPreview?'pointer-events:none;':''?>display:block;width:100%;max-width:600px;height:180px;border:1px solid #cbd5e1;background:white;touch-action:none"></canvas><p data-rh-manual-status role="status">Dessinez votre signature dans le cadre.</p><button type="button" data-rh-manual-clear>Effacer</button></div>
<p style="margin:20px 0"><button style="background:#10233f;color:white;padding:14px 20px;border-radius:9px"><?=$adminPreview?'Signature désactivée en mode test':'Signer le point et générer la FEB'?></button></p><p>Pour modifier un point déjà signé, demandez sa réouverture aux RH. Une nouvelle signature sera requise.</p><?php if($adminPreview):?></fieldset><?php endif?></form></section>
<script>(()=>{const form=document.querySelector('form'),total=document.getElementById('rh-total');const update=()=>{let amount=0;form.querySelectorAll('tbody tr').forEach(row=>{if(row.querySelector('select').value==='approved')amount+=Number(row.querySelector('input[type=number]').value)||0;});total.textContent=new Intl.NumberFormat('fr-FR').format(amount)+' FCFA';};form.addEventListener('input',update);update();})();</script><?php endif?></main><?php if(!$adminPreview):?><script src="../js/rh-financial-signature.js?v=20261014-dg"></script><script src="../js/passkeys.js?v=20261014-dg"></script><?php endif?><?php require_once __DIR__.'/../decaissement/includes/footer.php';?>



