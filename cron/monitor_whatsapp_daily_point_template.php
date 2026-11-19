<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../decaissement/model/Database.php';
require_once __DIR__.'/../services/DailyPointExternalSignatureService.php';
require_once __DIR__.'/../services/DailyPointWhatsAppTemplateMonitor.php';
try {
    $pdo=Database::getConnection();new DailyPointExternalSignatureService($pdo);
    $config=new TwilioWhatsAppConfigService($pdo);$credentials=$config->credentials();
    $monitor=new DailyPointWhatsAppTemplateMonitor($pdo,new DailyPointWhatsAppTemplateService($credentials['sid'],$credentials['token']));
    $before=$monitor->state();$state=$monitor->poll();
    if(($state['fingerprint']??'')!==($before['fingerprint']??''))echo 'WhatsApp '.$state['sid'].' : '.$state['status'].' '.($state['reason']??'').' '.($state['error']??'').' '.($state['activation_error']??'').PHP_EOL;
    $failed=empty($state['error'])&&empty($state['activation_error'])?0:1;
    // Extend the already-installed daily-point cron, after the RH migration exists.
    if($pdo->query("SHOW TABLES LIKE 'rh_financial_template_monitor'")->fetchColumn()){
        require_once __DIR__.'/../services/RhFinancialWhatsAppMonitorFactory.php';
        $rh=RhFinancialWhatsAppMonitorFactory::build($pdo);
        foreach(array_keys(RhFinancialPointPolicy::KINDS) as $kind){
            try{$beforeRh=$rh->state($kind);$rhState=$rh->poll($kind);if(($rhState['fingerprint']??'')!==($beforeRh['fingerprint']??''))echo 'RH '.$kind.' : '.$rhState['status'].' '.($rhState['error']??'').' '.($rhState['activation_error']??'').PHP_EOL;if(!empty($rhState['error'])||!empty($rhState['activation_error']))$failed++;}
            catch(Throwable $e){$failed++;fwrite(STDERR,'RH '.$kind.' : '.DailyPointWhatsAppTemplateService::safeDiagnostic($e->getMessage()).PHP_EOL);}
        }
    }
    // Observe the central registry without changing any business circuit settings.
    require_once __DIR__.'/../services/WhatsAppAdminConsoleService.php';
    if(WhatsAppAdminConsoleService::ready($pdo)){
        $central=new WhatsAppAdminConsoleService($pdo,new WhatsAppAdminContentService($credentials['sid'],$credentials['token']),new NotificationWhatsAppChannelService($credentials['sid'],$credentials['token'],$credentials['from']));
        try{$failed+=$central->pollDue();}catch(Throwable $e){$failed++;fwrite(STDERR,'Console WhatsApp : '.DailyPointWhatsAppTemplateService::safeDiagnostic($e->getMessage()).PHP_EOL);}
    }
    exit($failed?1:0);
} catch(Throwable $e) {fwrite(STDERR,DailyPointWhatsAppTemplateService::safeDiagnostic($e->getMessage()).PHP_EOL);exit(1);}

