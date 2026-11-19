<?php
declare(strict_types=1);
require_once __DIR__.'/DailyPointExternalSignatureService.php';
require_once __DIR__.'/RhFinancialWhatsAppTemplateMonitor.php';
require_once __DIR__.'/../repositories/NotificationRepository.php';
final class RhFinancialWhatsAppMonitorFactory
{
    public static function build(PDO $pdo):RhFinancialWhatsAppTemplateMonitor
    {
        new DailyPointExternalSignatureService($pdo);new NotificationRepository($pdo);
        $c=(new TwilioWhatsAppConfigService($pdo))->credentials();
        return new RhFinancialWhatsAppTemplateMonitor($pdo,new RhFinancialWhatsAppTemplateService($c['sid'],$c['token']),$c['from']);
    }
}
