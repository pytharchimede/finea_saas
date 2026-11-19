<?php
require_once __DIR__.'/DailyPointWhatsAppTemplateService.php';
require_once __DIR__.'/TwilioWhatsAppConfigService.php';

/** Surveillance persistante partagee par le cron et l'administration. */
class DailyPointWhatsAppTemplateMonitor
{
    public const INITIAL_SID = 'HX026611c439c24ebb7dc7121b5ac878b9';
    private string $lockName;
    public function __construct(private PDO $pdo, private DailyPointWhatsAppTemplateService $api)
    {
        $this->lockName = 'finea_whatsapp_'.substr(hash('sha256',(string)$pdo->query('SELECT DATABASE()')->fetchColumn()),0,24);
        $pdo->exec("CREATE TABLE IF NOT EXISTS daily_point_template_monitor (id TINYINT PRIMARY KEY, state_json MEDIUMTEXT NOT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS daily_point_template_monitor_events (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, sid VARCHAR(34) NOT NULL, status VARCHAR(40) NOT NULL, detail TEXT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $s=$pdo->prepare('INSERT IGNORE INTO daily_point_template_monitor(id,state_json) VALUES(1,?)');
        $s->execute([json_encode(['sid'=>self::INITIAL_SID,'name'=>'finea_point_signature_20260930','body'=>DailyPointWhatsAppTemplateService::BODY,'status'=>'unknown','auto_activate'=>true,'next_check'=>0,'failures'=>0],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
        require_once __DIR__.'/../repositories/NotificationRepository.php';
        new NotificationRepository($pdo);
    }
    public function state(): array
    {
        return json_decode((string)$this->pdo->query('SELECT state_json FROM daily_point_template_monitor WHERE id=1')->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
    }
    public function history(): array
    {
        return $this->pdo->query('SELECT sid,status,detail,created_at FROM daily_point_template_monitor_events ORDER BY id DESC LIMIT 30')->fetchAll(PDO::FETCH_ASSOC);
    }
    private function locked(callable $operation): array
    {
        $s=$this->pdo->prepare('SELECT GET_LOCK(?,0)');$s->execute([$this->lockName]);
        if((int)$s->fetchColumn()!==1) throw new RuntimeException('Un contrôle est déjà en cours. Réessayez dans une minute.');
        try{return $operation();}finally{$s=$this->pdo->prepare('SELECT RELEASE_LOCK(?)');$s->execute([$this->lockName]);}
    }
    private function persist(array $state, string $detail='', bool $notify=false, ?array $configurationChange=null): void
    {
        $this->pdo->beginTransaction();
        try {
            if($configurationChange!==null){
                if($configurationChange['action']==='fallback'){
                    $s=$this->pdo->prepare('UPDATE daily_disbursement_point_settings SET point_stopped_cta_sid=NULL,point_stopped_cta_body=NULL WHERE id=1 AND point_stopped_cta_sid=?');$s->execute([$state['sid']]);
                }else{
                    $s=$this->pdo->prepare('UPDATE daily_disbursement_point_settings SET point_stopped_cta_sid=?,point_stopped_cta_body=? WHERE id=1 AND (point_stopped_cta_sid IS NULL OR point_stopped_cta_sid="" OR point_stopped_cta_sid=?)');$s->execute([$state['sid'],$state['body'],$state['sid']]);
                    $active=(string)$this->pdo->query('SELECT point_stopped_cta_sid FROM daily_disbursement_point_settings WHERE id=1')->fetchColumn();
                    if($active!==$state['sid'])throw new RuntimeException('La configuration a changé pendant le contrôle ; activation annulée.');
                }
            }
            $s=$this->pdo->prepare('UPDATE daily_point_template_monitor SET state_json=? WHERE id=1');
            $s->execute([json_encode($state,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
            if($detail!=='') {
                $s=$this->pdo->prepare('INSERT INTO daily_point_template_monitor_events(sid,status,detail) VALUES(?,?,?)');
                $s->execute([$state['sid'],$state['status'],DailyPointWhatsAppTemplateService::safeDiagnostic($detail)]);
                if($notify) {
                    $s=$this->pdo->prepare("INSERT INTO system_notifications(created_at,event_key,title,message,link_url,recipient_team,channels_json) VALUES(NOW(),'daily_point_template','WhatsApp : modèle de signature',?,'/decaissement/suivi_template_whatsapp_point.php','admin','[\"system\"]')");
                    $s->execute([DailyPointWhatsAppTemplateService::safeDiagnostic($detail)]);
                }
            }
            $this->pdo->commit();
        } catch(Throwable $e) {$this->pdo->rollBack();throw $e;}
    }
    public function setAutomatic(bool $enabled): array
    {
        return $this->locked(function()use($enabled){$state=$this->state();$state['auto_activate']=$enabled;$state['next_check']=0;$this->persist($state,'Activation automatique '.($enabled?'activée':'désactivée'));return $state;});
    }
    public function createCandidate(string $name,string $body): array
    {
        return $this->locked(function()use($name,$body){
            $state=$this->state();
            if(!in_array($state['status'],['rejected','disabled'],true)) throw new RuntimeException('Le modèle suivi est déjà soumis ou approuvé. Contrôlez son statut avant de créer une nouvelle version.');
            DailyPointWhatsAppTemplateService::definition($name,$body);
            try {
                $created=$this->api->createAndSubmit($name,$body,function($sid)use(&$state,$name,$body){
                    unset($state['activated_at'],$state['fingerprint']);$state=array_merge($state,['sid'=>$sid,'name'=>$name,'body'=>$body,'status'=>'created','reason'=>'','error'=>'','next_check'=>0,'failures'=>0]);
                    $this->persist($state,'Modèle créé ; soumission en cours.');
                });
                $state['status']=strtolower($created['status']);$this->persist($state,'Nouvelle version soumise à Meta.',true);
            } catch(Throwable $e) {$state['error']=DailyPointWhatsAppTemplateService::safeDiagnostic($e->getMessage());$this->persist($state,$state['error'],true);throw $e;}
            return $state;
        });
    }
    /** Calcul pur : une erreur API conserve le dernier statut connu. */
    public static function observation(array $state, ?array $approval, ?string $error, int $now): array
    {
        $state['last_checked']=$now;
        if($error!==null){$state['failures']=min(8,(int)($state['failures']??0)+1);$state['error']=DailyPointWhatsAppTemplateService::safeDiagnostic($error);$state['next_check']=$now+min(21600,300*(2**($state['failures']-1)));}
        else{$state['status']=$approval['status'];$state['reason']=$approval['reason'];$state['category']=$approval['category'];$state['error']='';$state['failures']=0;$state['last_success']=$now;$state['next_check']=$now+(in_array($state['status'],['pending','received','unknown','created'],true)?300:3600);}
        return $state;
    }
    public function poll(bool $force=false): array
    {
        return $this->locked(function()use($force){
            $before=$this->state();$now=time();
            if(!$force&&$now<(int)($before['next_check']??0))return $before;
            try{$approval=$this->api->fetchApproval($before['sid']);$state=self::observation($before,$approval,null,$now);}
            catch(Throwable $e){$state=self::observation($before,null,$e->getMessage(),$now);}
            $configurationChange=null;$state['activation_error']='';
            $activeSid=(string)$this->pdo->query('SELECT point_stopped_cta_sid FROM daily_disbursement_point_settings WHERE id=1')->fetchColumn();
            if($activeSid!==$state['sid'])unset($state['activated_at']);
            if($state['error']==='') {
                if(in_array($state['status'],['rejected','paused','disabled'],true)) {
                    unset($state['activated_at']);
                    $configurationChange=['action'=>'fallback'];
                } elseif($state['status']==='approved'&&!empty($state['auto_activate'])) {
                    try {
                        if(strtoupper($state['category']??'')!=='UTILITY')throw new RuntimeException('Meta a changé la catégorie : activation suspendue pour vérification.');
                        $this->api->assertApproved($state['sid'],$state['body']);
                        if(!$this->api->signatureRouteReady())throw new RuntimeException('Route de signature indisponible : déployez la PR et vérifiez la réécriture Apache.');
                        $active=(string)$this->pdo->query('SELECT point_stopped_cta_sid FROM daily_disbursement_point_settings WHERE id=1')->fetchColumn();
                        if($active!==''&&$active!==$state['sid'])throw new RuntimeException('Un autre modèle CTA a été activé manuellement. Vérifiez la configuration avant de le remplacer.');
                        $configurationChange=['action'=>'activate'];$state['activated_at']=$state['activated_at']??$now;
                    }catch(Throwable $e){$state['activation_error']=DailyPointWhatsAppTemplateService::safeDiagnostic($e->getMessage());$state['next_check']=$now+300;}
                }
            }
            $fingerprint=hash('sha256',json_encode([$state['sid'],$state['status'],$state['reason']??'',$state['error'],$state['activation_error'],isset($state['activated_at'])]));
            $changed=$fingerprint!==($before['fingerprint']??'');$state['fingerprint']=$fingerprint;
            $detail='Statut Meta : '.$state['status'].'. '.($state['reason']??'').' '.$state['error'].' '.$state['activation_error'];
            if(isset($state['activated_at'])&&$state['activation_error']==='')$detail.=' Modèle activé dans FINEA.';
            $this->persist($state,$changed?trim($detail):'',$changed,$configurationChange);
            return $state;
        });
    }
}
