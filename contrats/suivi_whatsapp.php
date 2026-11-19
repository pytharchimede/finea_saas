<?php
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/../services/ContractWhatsAppService.php';
require_once __DIR__.'/../services/TwilioWhatsAppConfigService.php';
require_once __DIR__.'/../services/DailyPointExternalSignatureService.php';
if(!hasPermission('acces_admin')&&!hasPermission('acces_rh')){http_response_code(403);exit('Accès réservé aux RH et administrateurs.');}
$admin=hasPermission('acces_admin');$ready=ContractWhatsAppService::ready($pdo);$error='';$message='';
$_SESSION['contract_whatsapp_csrf']??=bin2hex(random_bytes(24));
if($_SERVER['REQUEST_METHOD']==='POST')try{
 if(!$admin)throw new DomainException('Action réservée aux administrateurs.');
 if(!hash_equals($_SESSION['contract_whatsapp_csrf'],(string)($_POST['_csrf']??'')))throw new DomainException('Session expirée.');
 if(!$ready)throw new DomainException('Installez les tables avec la commande serveur.');
 new DailyPointExternalSignatureService($pdo);$c=(new TwilioWhatsAppConfigService($pdo))->credentials();
 $service=new ContractWhatsAppService($pdo,new WhatsAppAdminContentService($c['sid'],$c['token']),new NotificationWhatsAppChannelService($c['sid'],$c['token'],$c['from']));
 $service->locked(function()use($service,$pdo,&$message){
  ContractWhatsAppService::ensureAutomationSchema($pdo);
  if(($_POST['action']??'')==='provision'){$r=$service->provision();$message='Traitement des modèles terminé. Vérifiez chaque statut et éventuel diagnostic ci-dessous.';}
  elseif(($_POST['action']??'')==='poll'){$service->poll(true);$state=$service->refreshAutomation();$message='Statuts contrôlés : '.$state['approved_count'].' modèle(s) Utility approuvé(s). Le cron traite les notifications des étapes actives.';}
  elseif(($_POST['action']??'')==='disable'){$service->setAutomatic(false);$message='Envois suspendus manuellement. Le contrôle des modèles continue.';}
  elseif(($_POST['action']??'')==='enable'){$service->setAutomatic(true);$service->poll(true);$state=$service->refreshAutomation();$message='Activation automatique autorisée : '.$state['approved_count'].' modèle(s) prêt(s).';}
  else throw new DomainException('Action inconnue.');
 });
}catch(Throwable $e){$error=DailyPointWhatsAppTemplateService::safeDiagnostic($e->getMessage());}
$templates=$ready?$pdo->query('SELECT *,CASE WHEN checked_at>=DATE_SUB(NOW(),INTERVAL 15 MINUTE) THEN 1 ELSE 0 END approval_fresh FROM contract_whatsapp_templates ORDER BY template_key')->fetchAll(PDO::FETCH_ASSOC):[];
$jobs=$ready?$pdo->query('SELECT o.*,c.reference FROM contract_whatsapp_outbox o JOIN contrat_sous_traitance c ON c.id=o.contract_id ORDER BY o.id DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC):[];
$settings=$ready?($pdo->query('SELECT * FROM contract_whatsapp_settings WHERE id=1')->fetch(PDO::FETCH_ASSOC)?:[]):[];
$enabled=!empty($settings['enabled']);$automatic=(bool)($settings['auto_activation']??1);
$pageTitle='Contrats · Suivi WhatsApp';$activeMenu='contrats';require __DIR__.'/../decaissement/includes/header.php';require __DIR__.'/../decaissement/includes/navbar.php';
?>
<main class="mx-auto max-w-7xl px-4 py-8"><h1 class="text-3xl font-black">Notifications WhatsApp des contrats</h1>
<p class="mt-3"><?= !$automatic?'Envois suspendus manuellement.':($enabled?'Activation automatique en service.':'Activation automatique : en attente de modèles approuvés.') ?> Chaque modèle est raccordé à son étape dès son approbation en Utility, sans attendre les autres. Chaque notification ouvre l’étape attribuée après connexion. Le prestataire est invité à contacter les RH pour organiser sa signature.</p>
<?php if($error||$message):?><p role="status" class="mt-4 rounded-xl border p-4"><?=$h($error?:$message)?></p><?php endif;?>
<?php if(!$ready):?><p class="mt-4">Installation requise : scripts/contract_whatsapp.php --install --actor=ID_ADMIN</p><?php endif;?>
<?php if($admin&&$ready):?><div class="mt-5 flex flex-wrap gap-3"><?php foreach(['provision'=>'Créer et soumettre les modèles','poll'=>'Contrôler les approbations','enable'=>'Reprendre automatiquement','disable'=>'Suspendre les envois'] as $action=>$label):?><form method="post"><input type="hidden" name="_csrf" value="<?=$h($_SESSION['contract_whatsapp_csrf'])?>"><input type="hidden" name="action" value="<?=$action?>"><button class="rounded-xl border bg-white px-4 py-3 font-bold"><?=$label?></button></form><?php endforeach;?></div><?php endif;?>
<h2 class="mt-8 text-xl font-black">Modèles et approbation Meta</h2><div class="mt-4 overflow-x-auto"><table class="w-full border"><thead><tr><th>Étape</th><th>Nom / SID</th><th>Statut / catégorie</th><th>Raccordement</th><th>Diagnostic</th></tr></thead><tbody><?php foreach($templates as $t):?><tr class="border-t"><td class="p-3"><?=$h($t['template_key'])?></td><td class="p-3"><?=$h($t['name'])?><br><?=$h($t['content_sid'])?></td><td class="p-3"><?=$h($t['status'].' / '.$t['category'])?></td><td class="p-3"><?= !$automatic?'Suspendu':(($t['status']==='approved'&&$t['category']==='UTILITY'&&!empty($t['approval_fresh']))?'Actif pour son étape':'En attente / bloqué') ?></td><td class="p-3"><?=$h($t['last_error'])?></td></tr><?php endforeach;?></tbody></table></div>
<h2 class="mt-8 text-xl font-black">Derniers envois</h2><p class="mt-2 text-sm">« sent » indique l’acceptation par Twilio. Le statut fournisseur précise la livraison. Un envoi incertain n’est pas relancé automatiquement : vérifiez le journal Twilio avant toute reprise.</p><div class="mt-4 overflow-x-auto"><table class="w-full border"><thead><tr><th>Contrat / étape</th><th>État</th><th>Livraison</th><th>SID / diagnostic</th></tr></thead><tbody><?php foreach($jobs as $j):?><tr class="border-t"><td class="p-3"><?=$h($j['reference'].' / '.$j['stage'])?></td><td class="p-3"><?=$h($j['status'])?></td><td class="p-3"><?=$h($j['provider_status'])?></td><td class="p-3"><?=$h($j['message_sid'])?><br><?=$h($j['last_error'])?></td></tr><?php endforeach;?></tbody></table></div></main>
<?php require __DIR__.'/../decaissement/includes/footer.php';?>
