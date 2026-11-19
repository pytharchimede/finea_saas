<?php
declare(strict_types=1);
require_once __DIR__.'/../model/Database.php';
require_once __DIR__.'/ContractWhatsAppService.php';
require_once __DIR__.'/TwilioWhatsAppConfigService.php';
require_once __DIR__.'/DailyPointExternalSignatureService.php';

final class ContractWhatsAppRunner
{
    public static function execute(array $options):int
    {
        try{
            $pdo=Database::getConnection();
            $mutating=isset($options['install'])||isset($options['provision'])||isset($options['enable'])||isset($options['disable'])||isset($options['reconcile']);
            if($mutating){$q=$pdo->prepare('SELECT COUNT(*) FROM user_devis_banamur WHERE id=? AND active=1 AND acces_admin=1');$q->execute([(int)($options['actor']??0)]);if((int)$q->fetchColumn()!==1)throw new DomainException('Indiquez --actor=ID avec un administrateur actif.');}
            if(isset($options['install'])){$pdo->exec(file_get_contents(__DIR__.'/../migrations/20261016_contract_whatsapp.sql'));echo "Tables contrats WhatsApp installées.\n";}
            if(!ContractWhatsAppService::ready($pdo))throw new DomainException('Exécutez d’abord --install --actor=ID.');
            new DailyPointExternalSignatureService($pdo);$c=(new TwilioWhatsAppConfigService($pdo))->credentials();
            $service=new ContractWhatsAppService($pdo,new WhatsAppAdminContentService($c['sid'],$c['token']),new NotificationWhatsAppChannelService($c['sid'],$c['token'],$c['from']));
            $service->locked(function()use($service,$pdo,$options){
                // DDL hors transaction, sous le verrou dédié : migration des installations existantes.
                ContractWhatsAppService::ensureAutomationSchema($pdo);
                if(isset($options['disable'])){$service->setAutomatic(false);echo "Surveillance active ; envois suspendus manuellement.\n";}
                if(isset($options['reconcile'])){[$key,$sid]=array_pad(explode(':',(string)$options['reconcile'],2),2,'');$service->reconcile($key,$sid);}
                if(isset($options['provision']))echo json_encode($service->provision(),JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
                if(isset($options['enable'])){$service->setAutomatic(true);$service->poll(true);echo json_encode($service->refreshAutomation(),JSON_UNESCAPED_UNICODE).PHP_EOL;}
                if(isset($options['dry-run'])){foreach($pdo->query("SELECT id FROM contrat_sous_traitance WHERE statut IN ('brouillon','a_signer')")->fetchAll(PDO::FETCH_COLUMN) as $id)foreach($service->desired((int)$id) as $r){unset($r['phone'],$r['variables']);echo json_encode($r).PHP_EOL;}}
                if(isset($options['run']))echo json_encode($service->runCycle(),JSON_UNESCAPED_UNICODE).PHP_EOL;
                if(isset($options['status']))echo json_encode(['settings'=>$pdo->query('SELECT * FROM contract_whatsapp_settings')->fetchAll(PDO::FETCH_ASSOC),'templates'=>$pdo->query('SELECT template_key,name,content_sid,status,category,last_error,checked_at FROM contract_whatsapp_templates')->fetchAll(PDO::FETCH_ASSOC),'outbox'=>$pdo->query('SELECT status,COUNT(*) total FROM contract_whatsapp_outbox GROUP BY status')->fetchAll(PDO::FETCH_ASSOC)],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
            });
            return 0;
        }catch(Throwable $e){fwrite(STDERR,DailyPointWhatsAppTemplateService::safeDiagnostic($e->getMessage()).PHP_EOL);return 1;}
    }
}
