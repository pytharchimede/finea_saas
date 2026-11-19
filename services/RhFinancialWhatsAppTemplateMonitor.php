<?php
declare(strict_types=1);
require_once __DIR__.'/RhFinancialWhatsAppTemplateService.php';
require_once __DIR__.'/RhFinancialPointService.php';

/** Explicit migration; no schema mutation during control or activation. */
final class RhFinancialWhatsAppTemplateMonitor
{
    private string $lockName;
    public function __construct(private PDO $pdo,private RhFinancialWhatsAppTemplateService $api,private string $sender)
    {
        $this->lockName='finea_rh_templates_'.substr(hash('sha256',(string)$pdo->query('SELECT DATABASE()')->fetchColumn()),0,24);
    }
    public function state(string $kind):array
    {
        $this->kind($kind);$q=$this->pdo->prepare('SELECT state_json FROM rh_financial_template_monitor WHERE kind=?');$q->execute([$kind]);$json=$q->fetchColumn();
        if(!$json)throw new RuntimeException('Appliquez la migration de suivi des modèles WhatsApp RH.');
        return json_decode($json,true,512,JSON_THROW_ON_ERROR);
    }
    public function history():array{return $this->pdo->query('SELECT * FROM rh_financial_template_events ORDER BY id DESC LIMIT 40')->fetchAll(PDO::FETCH_ASSOC);}
    private function kind(string $kind):void{if(!isset(RhFinancialPointPolicy::KINDS[$kind]))throw new DomainException('Type de point invalide.');}
    private function locked(callable $operation):array
    {
        if($this->pdo->inTransaction())throw new RuntimeException('Le contrôle doit être effectué hors transaction financière.');
        $q=$this->pdo->prepare('SELECT GET_LOCK(?,0)');$q->execute([$this->lockName]);if((int)$q->fetchColumn()!==1)throw new RuntimeException('Un contrôle ou une création est déjà en cours.');
        try{return $operation();}finally{$q=$this->pdo->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$this->lockName]);}
    }
    private function persist(string $kind,array $state,string $detail='',bool $notify=false,bool $activate=false):void
    {
        $this->pdo->beginTransaction();
        try {
            if($activate){
                $settings=$this->pdo->query('SELECT * FROM rh_financial_settings WHERE id=1 FOR UPDATE')->fetch(PDO::FETCH_ASSOC);
                $dg=$this->pdo->prepare('SELECT COUNT(*) FROM user_devis_banamur WHERE id=? AND active=1 AND acces_direction=1');$dg->execute([(int)($settings['dg_user_id']??0)]);
                if((int)$dg->fetchColumn()!==1 || !preg_match('/^\+[1-9]\d{7,14}$/D',(string)($settings['dg_phone']??'')))throw new RuntimeException('La configuration DG a changé avant activation.');
                $q=$this->pdo->prepare('UPDATE rh_financial_settings SET '.$kind.'_sid=?,'.$kind.'_active_at=COALESCE('.$kind.'_active_at,NOW()) WHERE id=1 AND (COALESCE('.$kind.'_sid,\'\')=? OR '.$kind.'_sid=?)');$q->execute([$state['sid'],$state['base_sid']??'',$state['sid']]);
                $settings=(new RhFinancialPointService($this->pdo))->settings();
                if(($settings[$kind.'_sid']??'')!==$state['sid'] || empty($settings[$kind.'_active_at']))throw new RuntimeException('Le modèle configuré a changé pendant le contrôle.');
                $state['activated_at']=$settings[$kind.'_active_at'];
            }
            $this->pdo->prepare('UPDATE rh_financial_template_monitor SET state_json=? WHERE kind=?')->execute([json_encode($state,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$kind]);
            if($detail!==''){
                $detail=DailyPointWhatsAppTemplateService::safeDiagnostic($detail);
                $this->pdo->prepare('INSERT INTO rh_financial_template_events(kind,sid,status,detail) VALUES(?,?,?,?)')->execute([$kind,$state['sid']??'',$state['status'],$detail]);
                if($notify)$this->pdo->prepare("INSERT INTO system_notifications(created_at,event_key,title,message,link_url,recipient_team,channels_json) VALUES(NOW(),'rh_financial_template','WhatsApp : point RH',?,'/rh/suivi_templates_whatsapp_rh.php','admin','[\"system\"]')")->execute([RhFinancialPointPolicy::KINDS[$kind].' : '.$detail]);
            }
            $this->pdo->commit();
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
    public function setAutomatic(string $kind,bool $enabled,int $userId):array
    {
        (new RhFinancialPointService($this->pdo))->actor($userId,'admin');
        return $this->locked(function()use($kind,$enabled){$s=$this->state($kind);$s['auto_activate']=$enabled;$s['next_check']=0;$this->persist($kind,$s,'Activation automatique '.($enabled?'activée':'désactivée'));return $s;});
    }
    private function adopt(string $kind,array $s):array
    {
        $sid=(string)((new RhFinancialPointService($this->pdo))->settings()[$kind.'_sid']??'');
        if(empty($s['sid']) && $sid!=='')$s=['sid'=>$sid,'base_sid'=>$sid,'status'=>'unknown','auto_activate'=>$s['auto_activate']??($kind!=='salary'),'next_check'=>0,'failures'=>0];
        return $s;
    }
    public function create(string $kind,int $userId):array
    {
        (new RhFinancialPointService($this->pdo))->actor($userId,'admin');
        return $this->locked(function()use($kind,$userId){
            $s=$this->adopt($kind,$this->state($kind));
            if(!in_array($s['status'],['not_created','rejected','disabled'],true))throw new DomainException('Un modèle est déjà créé ou sa création est incertaine. Contrôlez ou rattachez son SID pour éviter un doublon.');
            if(empty($s['sid']) && $s['status']==='not_created')$s['auto_activate']=true;
            $name='finea_rh_'.$kind.'_'.gmdate('Ymd_His').'_'.bin2hex(random_bytes(3));
            $s['base_sid']=(string)((new RhFinancialPointService($this->pdo))->settings()[$kind.'_sid']??'');
            $s=array_merge($s,['name'=>$name,'status'=>'creating','next_check'=>0,'failures'=>0,'reason'=>'','error'=>'','activation_error'=>'']);unset($s['fingerprint'],$s['activated_at']);
            $this->persist($kind,$s,'Création demandée à Twilio.');$created=false;
            try{
                $result=$this->api->createForKind($kind,$name,function(string $sid)use($kind,$userId,&$s,&$created){$s['sid']=$sid;$s['status']='created';$created=true;$this->persist($kind,$s,'SID enregistré ; soumission à Meta en cours.',false,false);});
                $s['status']=strtolower((string)$result['status']);$this->persist($kind,$s,'Modèle créé et soumis à Meta.',true);
            }catch(Throwable $e){$s['status']=$created?'created':'creation_uncertain';$s['error']=DailyPointWhatsAppTemplateService::safeDiagnostic($e->getMessage());$this->persist($kind,$s,$s['error'],true);throw $e;}
            return $s;
        });
    }
    public function attach(string $kind,string $sid,int $userId):array
    {
        (new RhFinancialPointService($this->pdo))->actor($userId,'admin');
        return $this->locked(function()use($kind,$sid,$userId){
            $this->state($kind);$approval=$this->api->fetchKindApproval($kind,$sid);
            $s=['sid'=>$sid,'base_sid'=>(string)((new RhFinancialPointService($this->pdo))->settings()[$kind.'_sid']??''),'status'=>$approval['status'],'reason'=>$approval['reason'],'category'=>$approval['category'],'name'=>$approval['name']??'','auto_activate'=>true,'next_check'=>0,'failures'=>0];
            $this->persist($kind,$s,'Modèle existant rattaché après vérification du texte et du bouton.',true,false);return $s;
        });
    }
    public function submitExisting(string $kind,int $userId):array
    {
        (new RhFinancialPointService($this->pdo))->actor($userId,'admin');
        return $this->locked(function()use($kind){$s=$this->adopt($kind,$this->state($kind));if($s['status']!=='created'||empty($s['name']))throw new DomainException('Aucun modèle créé en attente de soumission.');
            $r=$this->api->submitKind($kind,$s['sid'],$s['name']);$s['status']=strtolower((string)($r['status']??'pending'));$s['error']='';$s['next_check']=0;$this->persist($kind,$s,'Modèle existant soumis à Meta.',true);return $s;});
    }
    public static function observation(array $state,?array $approval,?string $error,int $now):array
    {
        $state['last_checked']=$now;
        if($error!==null){$state['failures']=min(8,(int)($state['failures']??0)+1);$state['error']=DailyPointWhatsAppTemplateService::safeDiagnostic($error);$state['next_check']=$now+min(21600,300*(2**($state['failures']-1)));}
        else{if(!empty($approval['name']))$state['name']=$approval['name'];$state['status']=$approval['status'];$state['reason']=$approval['reason'];$state['category']=$approval['category'];$state['error']='';$state['failures']=0;$state['last_success']=$now;$state['next_check']=$now+(in_array($state['status'],['pending','received','unknown','created'],true)?300:3600);}
        return $state;
    }
    public function poll(string $kind,bool $force=false):array
    {
        return $this->locked(function()use($kind,$force){
            $before=$this->state($kind);$s=$this->adopt($kind,$before);$now=time();
            if(in_array($s['status'],['creating','creation_uncertain'],true))return $s;
            if(empty($s['sid'])){if($s!==$before)$this->persist($kind,$s);return $s;}
            if(!$force&&$now<(int)($s['next_check']??0))return $s;
            try{$s=self::observation($s,$this->api->fetchKindApproval($kind,$s['sid']),null,$now);}catch(Throwable $e){$s=self::observation($s,null,$e->getMessage(),$now);}
            $activate=false;$s['activation_error']='';
            if(empty($s['error'])&&$s['status']==='approved'&&!empty($s['auto_activate'])){
                try{
                    if(strtoupper($s['category']??'')!=='UTILITY')throw new DomainException('Catégorie Meta différente de Utility : activation suspendue.');
                    $settings=(new RhFinancialPointService($this->pdo))->settings();
                    $q=$this->pdo->prepare('SELECT COUNT(*) FROM user_devis_banamur WHERE id=? AND active=1 AND acces_direction=1');$q->execute([(int)($settings['dg_user_id']??0)]);
                    if((int)$q->fetchColumn()!==1 || !preg_match('/^\+[1-9]\d{7,14}$/D',(string)($settings['dg_phone']??'')))throw new DomainException('Configurez le compte actif du DG et son numéro WhatsApp dans les points financiers RH.');
                    if($this->sender==='')throw new DomainException('Configurez l’émetteur WhatsApp Twilio.');
                    if(!is_file(__DIR__.'/../rh/validation_point_financier.php'))throw new RuntimeException('Déployez la page de signature RH avant activation.');
                    $activate=true;
                }catch(Throwable $e){$s['activation_error']=DailyPointWhatsAppTemplateService::safeDiagnostic($e->getMessage());$s['next_check']=$now+300;}
            }
            // Never clear the historical cutoff on rejection, replacement or API failure.
            $fingerprint=hash('sha256',json_encode([$s['sid'],$s['status'],$s['reason']??'',$s['error'],$s['activation_error'],$activate]));$changed=$fingerprint!==($before['fingerprint']??'');$s['fingerprint']=$fingerprint;
            $detail=trim('Statut Meta : '.$s['status'].'. '.($s['reason']??'').' '.$s['error'].' '.$s['activation_error'].($activate?' Modèle activé dans FINEA.':''));
            $this->persist($kind,$s,$changed?$detail:'',$changed,$activate);return $this->state($kind);
        });
    }
}
