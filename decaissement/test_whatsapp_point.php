<?php
require_once __DIR__.'/../auth_check.php';
if(!AccessManager::hasPermission('acces_admin')){http_response_code(403);exit('Accès réservé aux administrateurs.');}
require_once __DIR__.'/../services/DailyPointExternalSignatureService.php';
require_once __DIR__.'/../services/TwilioWhatsAppConfigService.php';
require_once __DIR__.'/../services/WhatsAppTemplateTestService.php';
require_once __DIR__.'/../services/RhFinancialPointPolicy.php';
$pdo=Database::getConnection();new DailyPointExternalSignatureService($pdo);
$c=(new TwilioWhatsAppConfigService($pdo))->credentials();$api=new WhatsAppTemplateTestService($c['sid'],$c['token']);
$catalog=[];$add=static function($sid,$label)use(&$catalog){if(preg_match('/^HX[a-fA-F0-9]{32}$/D',(string)$sid))$catalog[$sid]=$label;};
$add($c['point_stopped_content_sid'],'Finance · Point journalier');
$q=$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='rh_financial_template_monitor'");
if((int)$q->fetchColumn())foreach($pdo->query('SELECT kind,state_json FROM rh_financial_template_monitor')->fetchAll(PDO::FETCH_ASSOC) as $row){$state=json_decode($row['state_json'],true);$add($state['sid']??'', 'RH · '.(RhFinancialPointPolicy::KINDS[$row['kind']]??$row['kind']).' · '.($state['name']??'Modèle suivi'));}
$q=$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='rh_financial_settings'");
if((int)$q->fetchColumn()){$settings=$pdo->query('SELECT * FROM rh_financial_settings WHERE id=1')->fetch(PDO::FETCH_ASSOC)?:[];foreach(RhFinancialPointPolicy::KINDS as $kind=>$label){$sid=$settings[$kind.'_sid']??'';if(!isset($catalog[$sid]))$add($sid,'RH · '.$label.' · Modèle actif');}}
$h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$selected=(string)($_POST['template_sid']??$_GET['template_sid']??array_key_first($catalog));$model=null;$error='';
$flash=$_SESSION['whatsapp_test_flash']??'';unset($_SESSION['whatsapp_test_flash']);
$_SESSION['whatsapp_template_test_csrf']??=bin2hex(random_bytes(32));
$csrf=$_SESSION['whatsapp_template_test_csrf'];
try{if(!isset($catalog[$selected]))throw new DomainException('Sélectionnez un modèle configuré dans FINEA.');$model=$api->inspect($selected);}catch(Throwable $e){$error=DailyPointWhatsAppTemplateService::safeDiagnostic($e->getMessage());}
if(($_SERVER['REQUEST_METHOD']??'')==='POST'){
    try{
        if(!hash_equals($csrf,(string)($_POST['_csrf']??'')))throw new DomainException('Cette demande a déjà été traitée ou la session a expiré. Rechargez la page.');
        // Consume before sending: browser refresh or a repeated POST cannot resend.
        $_SESSION['whatsapp_template_test_csrf']=bin2hex(random_bytes(32));
        if(!$model)throw new DomainException($error);
        if($model['status']!=='approved')throw new DomainException('Le modèle doit être approuvé par Meta avant un test.');
        $phone=WhatsAppTemplateTestService::phone((string)($_POST['test_phone']??''));
        $variables=WhatsAppTemplateTestService::variables($model,is_array($_POST['variables']??null)?$_POST['variables']:[]);
        $result=(new NotificationWhatsAppChannelService($c['sid'],$c['token'],$c['from']))->sendTemplateDetailed($phone,$selected,$variables);
        $detail=!empty($result['ok'])?'Accepté par Twilio · statut '.($result['status']??'queued').' · SID '.($result['message_sid']??''):'Échec · code '.($result['error_code']??'').' · '.($result['error']??'Erreur inconnue');
        $detail=DailyPointWhatsAppTemplateService::safeDiagnostic($detail);
        $_SESSION['whatsapp_template_tests'][]=['date'=>date('d/m/Y H:i:s'),'phone'=>$phone,'name'=>$model['name'],'detail'=>$detail];
        $_SESSION['whatsapp_template_tests']=array_slice($_SESSION['whatsapp_template_tests'],-20);
        $_SESSION['whatsapp_test_phone']=$phone;$_SESSION['whatsapp_test_flash']=$detail;
    }catch(Throwable $e){$_SESSION['whatsapp_test_flash']=DailyPointWhatsAppTemplateService::safeDiagnostic($e->getMessage());}
    header('Location: test_whatsapp_point.php?template_sid='.rawurlencode($selected));exit;
}
$pageTitle='Tests WhatsApp';$activeMenu='test_whatsapp_point';require __DIR__.'/includes/header.php';require __DIR__.'/includes/navbar.php';
$labels=['1'=>'Nom du destinataire dans le message','2'=>'Numéro du point','3'=>'Date du point','4'=>'Nombre de demandes ou fiches','5'=>'Montant total et devise','6'=>'Date et heure d’expiration'];
?>
<link rel="stylesheet" href="../css/rh-financial.css?v=20261003-3">
<main class="rh-points"><section class="rh-hero"><p class="rh-eyebrow">FINEA · Administration</p><h1>Tester un modèle WhatsApp</h1><p>Choisissez le modèle, personnalisez ses données et envoyez un message réel au numéro de votre choix.</p><a class="rh-btn" href="../admin/whatsapp_templates.php">Console WhatsApp centrale</a><a class="rh-btn" href="../rh/suivi_templates_whatsapp_rh.php">Modèles RH</a><a class="rh-btn" href="suivi_template_whatsapp_point.php">Modèle point journalier</a><a class="rh-btn" href="parametres_signature_point_jour.php">Configuration Twilio</a></section>
<?php if($flash):?><p class="rh-warning" role="status"><?=$h($flash)?></p><?php endif?><?php if($error):?><p class="rh-warning" role="alert"><?=$h($error)?></p><?php endif?>
<section><form method="get"><label>Modèle WhatsApp<select name="template_sid" onchange="this.form.submit()" data-rh-search-select><?php foreach($catalog as $sid=>$label):?><option value="<?=$h($sid)?>" <?=$selected===$sid?'selected':''?>><?=$h($label)?></option><?php endforeach?></select></label><button>Charger le modèle</button></form>
<?php if($model):?><p class="rh-template-name"><strong><?=$h($model['name'])?></strong><small><?=$h($model['sid'])?> · Meta : <?=$h($model['status'])?></small></p>
<form method="post" id="whatsapp-test"><input type="hidden" name="_csrf" value="<?=$h($csrf)?>"><input type="hidden" name="template_sid" value="<?=$h($selected)?>"><div class="rh-form-grid"><label>Numéro WhatsApp de réception<input type="tel" name="test_phone" placeholder="+2250700000000" value="<?=$h($_SESSION['whatsapp_test_phone']??'')?>" required><small>Votre numéro personnel ou celui d’un autre destinataire.</small></label>
<?php foreach($model['keys'] as $key):if((string)$key==='7')continue;?><label><?=$h($labels[$key]??'Variable '.$key)?> · {{<?=$h($key)?>}}<input type="text" name="variables[<?=$h($key)?>]" data-variable="<?=$h($key)?>" value="<?=$h($model['samples'][$key]??'')?>" required maxlength="500"></label><?php endforeach?></div>
<p class="rh-monitor-note">Le bouton utilise un jeton fictif de test. Il ne permet pas de signer un point réel.</p><h2>Aperçu du message</h2><p class="rh-preview" id="whatsapp-preview"></p><?php foreach($model['actions'] as $action):?><p><b><?=$h($action['title']??'')?></b></p><?php endforeach?>
<?php if($model['status']!=='approved'):?><p class="rh-warning">Envoi disponible après approbation Meta.</p><?php endif?><button <?=$model['status']!=='approved'?'disabled':''?>>Envoyer le test WhatsApp</button></form>
<script type="application/json" id="whatsapp-body"><?=json_encode($model['body'],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?></script>
<script src="../js/whatsapp-template-test.js?v=20261003"></script><?php endif?></section>
<section><h2>Tests de cette session</h2><div class="rh-scroll"><table><thead><tr><th>Date</th><th>Destinataire</th><th>Modèle</th><th>Résultat Twilio</th></tr></thead><tbody><?php foreach(array_reverse($_SESSION['whatsapp_template_tests']??[]) as $test):?><tr><?php foreach($test as $value):?><td><?=$h($value)?></td><?php endforeach?></tr><?php endforeach?></tbody></table></div><small>L’acceptation par Twilio ne confirme pas la livraison sur le téléphone.</small></section></main>
<script src="../js/rh-financial.js?v=20261003"></script>
<?php require __DIR__.'/includes/footer.php';?>
