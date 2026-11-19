<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../model/Database.php';
require_once __DIR__.'/../services/RhFinancialWhatsAppMonitorFactory.php';
// Explicit invocation only: creation never happens in a polling cron or a page GET.
$userId=(int)($argv[1]??0);if($userId<=0){fwrite(STDERR,"Usage : php scripts/create_rh_whatsapp_templates.php ID_COMPTE_ADMIN\n");exit(1);}
try{
    $monitor=RhFinancialWhatsAppMonitorFactory::build(Database::getConnection());$failed=0;
    foreach(array_keys(RhFinancialPointPolicy::KINDS) as $kind){
        try{$s=$monitor->poll($kind,true);if(in_array($s['status'],['not_created','rejected','disabled'],true))$s=$monitor->create($kind,$userId);echo $kind.' : '.$s['status'].' · '.($s['sid']??'').PHP_EOL;}
        catch(Throwable $e){$failed++;fwrite(STDERR,$kind.' : '.DailyPointWhatsAppTemplateService::safeDiagnostic($e->getMessage()).PHP_EOL);}
    }exit($failed?1:0);
}catch(Throwable $e){fwrite(STDERR,DailyPointWhatsAppTemplateService::safeDiagnostic($e->getMessage()).PHP_EOL);exit(1);}
