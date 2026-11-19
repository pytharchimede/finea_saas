<?php
require_once __DIR__.'/../auth_check.php';
if(!AccessManager::hasPermission('acces_admin')){http_response_code(403);exit('Accès réservé aux administrateurs.');}
require_once __DIR__.'/model/Database.php';
require_once __DIR__.'/../services/DailyPointExternalSignatureService.php';
require_once __DIR__.'/../services/DailyPointWhatsAppTemplateMonitor.php';
$pdo=Database::getConnection();new DailyPointExternalSignatureService($pdo);
$config=new TwilioWhatsAppConfigService($pdo);$credentials=$config->credentials();
$monitor=new DailyPointWhatsAppTemplateMonitor($pdo,new DailyPointWhatsAppTemplateService($credentials['sid'],$credentials['token']));
if(empty($_SESSION['template_monitor_csrf']))$_SESSION['template_monitor_csrf']=bin2hex(random_bytes(32));
$flash='';
if(($_SERVER['REQUEST_METHOD']??'')==='POST'){
    try {
        if(!hash_equals($_SESSION['template_monitor_csrf'],(string)($_POST['_csrf']??'')))throw new RuntimeException('Session expirée.');
        $_SESSION['template_monitor_csrf']=bin2hex(random_bytes(32));
        switch($_POST['action']??''){
            case 'refresh':$monitor->poll(true);$flash='Statut contrôlé auprès de Twilio.';break;
            case 'automatic':$monitor->setAutomatic(isset($_POST['enabled']));$flash='Préférence enregistrée.';break;
            case 'create':$monitor->createCandidate(trim((string)($_POST['name']??'')),trim((string)($_POST['body']??'')));$flash='Nouvelle version créée et soumise à Meta.';break;
            default:throw new RuntimeException('Action inconnue.');
        }
    }catch(Throwable $e){$flash=DailyPointWhatsAppTemplateService::safeDiagnostic($e->getMessage());}
}
$credentials=$config->credentials();$state=$monitor->state();$history=$monitor->history();
function templateMonitorEscape($value):string{return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
$pageTitle='Suivi du modèle WhatsApp';$activeMenu='achat';require_once __DIR__.'/includes/header.php';require_once __DIR__.'/includes/navbar.php';
$csrf='<input type="hidden" name="_csrf" value="'.templateMonitorEscape($_SESSION['template_monitor_csrf']).'">';
?>
<main class="mx-auto max-w-5xl px-4 py-8 space-y-6">
<a href="../admin/whatsapp_templates.php" class="inline-flex rounded-xl border border-emerald-300 px-4 py-3 font-bold text-emerald-800">Console WhatsApp centrale</a>

<section class="rounded-lg border bg-white p-6 space-y-4">
<h1 class="text-2xl font-black">Suivi WhatsApp : signature du point journalier</h1>
<a class="text-indigo-700" href="../rh/suivi_templates_whatsapp_rh.php">Suivi WhatsApp RH · avances et prêts</a>
<a class="text-indigo-700" href="parametres_signature_point_jour.php">Configuration Twilio</a>
<?php if($flash):?><p role="status" class="border p-3"><?=templateMonitorEscape($flash)?></p><?php endif;?>
<dl><dt>Modèle suivi</dt><dd><?=templateMonitorEscape($state['name'])?> · <?=templateMonitorEscape($state['sid'])?></dd><dt>Statut Meta</dt><dd class="font-bold"><?=templateMonitorEscape($state['status'])?></dd><dt>Dernier contrôle</dt><dd><?=isset($state['last_checked'])?date('d/m/Y H:i:s',(int)$state['last_checked']):'Pas encore contrôlé sur ce serveur'?></dd><dt>Modèle actif dans FINEA</dt><dd><?=templateMonitorEscape($credentials['point_stopped_content_sid'])?></dd></dl>
<?php foreach(['reason'=>'Motif Meta','error'=>'Erreur de contrôle','activation_error'=>'Activation suspendue'] as $key=>$label):if(!empty($state[$key])):?><p class="border border-amber-300 bg-amber-50 p-3"><strong><?=$label?> :</strong> <?=templateMonitorEscape($state[$key])?></p><?php endif;endforeach;?>
<p>Le cron doit être installé sur le serveur pour effectuer les contrôles même lorsque cette page est fermée. Une erreur API conserve le dernier statut connu et le modèle actif. Les changements sont notifiés à l’administration FINEA.</p>
<form method="post"><?=$csrf?><input type="hidden" name="action" value="refresh"><button class="rounded bg-indigo-700 px-4 py-2 text-white">Contrôler maintenant</button></form>
<form method="post"><?=$csrf?><input type="hidden" name="action" value="automatic"><label><input type="checkbox" name="enabled" <?=!empty($state['auto_activate'])?'checked':''?>> Activer automatiquement après approbation Utility et vérification du lien</label><button class="ml-3 rounded border px-3 py-2">Enregistrer</button></form>
</section>
<?php if(in_array($state['status'],['rejected','disabled'],true)):?>
<section class="rounded-lg border bg-white p-6 space-y-4"><h2 class="text-xl font-bold">Corriger et soumettre une nouvelle version</h2>
<p>Pour l’erreur 2388024 (« already French content »), utilisez un nouveau nom unique. Pour un autre rejet, adaptez le texte au motif indiqué par Meta. Conservez les variables 1 à 6 ; le jeton 7 reste dans le bouton sécurisé. La soumission ne garantit pas l’approbation.</p>
<form method="post" class="space-y-4"><?=$csrf?><input type="hidden" name="action" value="create"><label class="block">Nom unique<input class="block w-full border p-3" name="name" value="finea_point_signature_<?=date('Ymd_His')?>" required pattern="[a-z][a-z0-9_]{0,127}"></label><label class="block">Texte<textarea class="block w-full border p-3" name="body" rows="6" required><?=templateMonitorEscape($state['body'])?></textarea></label><p>Bouton : Consulter et signer · <?=templateMonitorEscape(DailyPointWhatsAppTemplateService::URL)?></p><button class="rounded bg-indigo-700 px-4 py-2 text-white">Créer et soumettre à Meta</button></form></section>
<?php endif;?>
<?php if($state['status']==='created'):?><p class="border p-4">La création a réussi mais la soumission a échoué. Ouvrez ce SID dans Twilio et soumettez le modèle existant ; ne créez pas de doublon. Puis contrôlez son statut ici.</p><?php endif;?>
<section class="rounded-lg border bg-white p-6"><h2 class="text-xl font-bold">Historique des 30 derniers événements</h2><div class="overflow-x-auto"><table class="w-full text-left"><thead><tr><th>Date serveur</th><th>SID / statut</th><th>Diagnostic</th></tr></thead><tbody><?php foreach($history as $event):?><tr class="border-t"><td class="p-2"><?=templateMonitorEscape($event['created_at'])?></td><td class="p-2"><?=templateMonitorEscape($event['sid'])?><br><?=templateMonitorEscape($event['status'])?></td><td class="p-2"><?=templateMonitorEscape($event['detail'])?></td></tr><?php endforeach;?></tbody></table></div></section>
</main><?php require_once __DIR__.'/includes/footer.php';?>

