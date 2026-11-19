<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../model/Database.php';
require_once __DIR__.'/../services/WhatsAppAdminConsoleService.php';
require_once __DIR__.'/../services/DailyPointExternalSignatureService.php';
try{
 $pdo=Database::getConnection();if(!WhatsAppAdminConsoleService::ready($pdo))exit(0);
 new DailyPointExternalSignatureService($pdo);$c=(new TwilioWhatsAppConfigService($pdo))->credentials();
 $s=new WhatsAppAdminConsoleService($pdo,new WhatsAppAdminContentService($c['sid'],$c['token']),new NotificationWhatsAppChannelService($c['sid'],$c['token'],$c['from']));
 $failed=$s->pollDue();echo 'Contrôle des modèles centraux terminé : '.$failed.' erreur(s).'.PHP_EOL;exit($failed?1:0);
}catch(Throwable $e){fwrite(STDERR,DailyPointWhatsAppTemplateService::safeDiagnostic($e->getMessage()).PHP_EOL);exit(1);}
