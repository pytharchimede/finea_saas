<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../model/Database.php';
require_once __DIR__.'/../services/RhFinancialWhatsAppMonitorFactory.php';
try{
    $monitor=RhFinancialWhatsAppMonitorFactory::build(Database::getConnection());$failed=0;
    foreach(array_keys(RhFinancialPointPolicy::KINDS) as $kind){
        try{$before=$monitor->state($kind);$s=$monitor->poll($kind);if(($s['fingerprint']??'')!==($before['fingerprint']??''))echo $kind.' : '.$s['status'].' '.($s['reason']??'').' '.($s['error']??'').' '.($s['activation_error']??'').PHP_EOL;if(!empty($s['error'])||!empty($s['activation_error']))$failed++;}
        catch(Throwable $e){$failed++;fwrite(STDERR,$kind.' : '.DailyPointWhatsAppTemplateService::safeDiagnostic($e->getMessage()).PHP_EOL);}
    }exit($failed?1:0);
}catch(Throwable $e){fwrite(STDERR,DailyPointWhatsAppTemplateService::safeDiagnostic($e->getMessage()).PHP_EOL);exit(1);}
