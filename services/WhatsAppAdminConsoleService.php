<?php
declare(strict_types=1);
require_once __DIR__.'/WhatsAppAdminContentService.php';
require_once __DIR__.'/NotificationWhatsAppChannelService.php';
require_once __DIR__.'/TwilioWhatsAppConfigService.php';
final class WhatsAppAdminConsoleService
{
    public function __construct(private PDO $pdo,private WhatsAppAdminContentService $api,private NotificationWhatsAppChannelService $channel){}
    public static function ready(PDO $pdo):bool
    {
        $q=$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('whatsapp_admin_templates','whatsapp_admin_events')");return (int)$q->fetchColumn()===2;
    }
    private function admin(int $userId):void
    {
        $q=$this->pdo->prepare('SELECT COUNT(*) FROM user_devis_banamur WHERE id=? AND active=1 AND acces_admin=1');$q->execute([$userId]);if((int)$q->fetchColumn()!==1)throw new DomainException('Accès réservé aux administrateurs actifs.');
    }
    private function lock(callable $operation):mixed
    {
        $key='finea_wa_console_'.substr(hash('sha256',(string)$this->pdo->query('SELECT DATABASE()')->fetchColumn()),0,24);
        $q=$this->pdo->prepare('SELECT GET_LOCK(?,0)');$q->execute([$key]);if((int)$q->fetchColumn()!==1)throw new DomainException('Une opération WhatsApp est déjà en cours. Réessayez après son résultat.');
        try{return $operation();}finally{$this->pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$key]);}
    }
    public function event(?int $id,?int $actor,string $action,string $status,string $detail,?string $phone=null,?string $messageSid=null):int
    {
        $this->pdo->prepare('INSERT INTO whatsapp_admin_events(template_id,actor_user_id,action,status,detail,recipient_phone,message_sid) VALUES(?,?,?,?,?,?,?)')->execute([$id,$actor,$action,$status,DailyPointWhatsAppTemplateService::safeDiagnostic($detail),$phone,$messageSid]);return (int)$this->pdo->lastInsertId();
    }
    public function templates():array{return $this->pdo->query('SELECT * FROM whatsapp_admin_templates ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);}
    public function template(int $id):array
    {
        $q=$this->pdo->prepare('SELECT * FROM whatsapp_admin_templates WHERE id=?');$q->execute([$id]);return $q->fetch(PDO::FETCH_ASSOC)?:throw new DomainException('Modèle introuvable.');
    }
    public function history(?int $id=null):array
    {
        $q=$this->pdo->prepare('SELECT e.*,t.name FROM whatsapp_admin_events e LEFT JOIN whatsapp_admin_templates t ON t.id=e.template_id'.($id?' WHERE e.template_id=?':'').' ORDER BY e.id DESC LIMIT 100');$q->execute($id?[$id]:[]);return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    private function exists(string $table):bool
    {
        $q=$this->pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$q->execute([$table]);return (int)$q->fetchColumn()===1;
    }
    public function bindings():array
    {
        $bindings=[];$add=static function($sid,$label,$active,$date,$url)use(&$bindings){if(preg_match('/^HX[a-fA-F0-9]{32}$/D',(string)$sid))$bindings[$sid][]=['label'=>$label,'active'=>$active,'date'=>$date,'url'=>$url];};
        if($this->exists('daily_disbursement_point_settings')){$r=$this->pdo->query('SELECT * FROM daily_disbursement_point_settings WHERE id=1')->fetch(PDO::FETCH_ASSOC)?:[];$add($r['point_stopped_cta_sid']?:TwilioWhatsAppConfigService::POINT_STOPPED_TEMPLATE_SID,'Point journalier',true,null,'../decaissement/suivi_template_whatsapp_point.php');}
        if($this->exists('daily_point_template_monitor')){$s=json_decode((string)$this->pdo->query('SELECT state_json FROM daily_point_template_monitor WHERE id=1')->fetchColumn(),true)?:[];$add($s['sid']??'','Point journalier · suivi',false,null,'../decaissement/suivi_template_whatsapp_point.php');}
        if($this->exists('rh_financial_settings')){$r=$this->pdo->query('SELECT * FROM rh_financial_settings WHERE id=1')->fetch(PDO::FETCH_ASSOC)?:[];foreach(['advance'=>'Avances RH','loan'=>'Prêts RH','salary'=>'Salaires RH'] as $kind=>$label)$add($r[$kind.'_sid']??'',$label,!empty($r[$kind.'_active_at']),$r[$kind.'_active_at']??null,'../rh/suivi_templates_whatsapp_rh.php');}
        if($this->exists('rh_financial_template_monitor'))foreach($this->pdo->query('SELECT kind,state_json FROM rh_financial_template_monitor')->fetchAll(PDO::FETCH_ASSOC) as $r){$s=json_decode($r['state_json'],true)?:[];$add($s['sid']??'','RH '.$r['kind'].' · suivi',false,null,'../rh/suivi_templates_whatsapp_rh.php');}
        return $bindings;
    }
    private function register(array $content,?int $actor):int
    {
        $sid=(string)($content['sid']??'');if(!preg_match('/^HX[a-fA-F0-9]{32}$/D',$sid))throw new DomainException('Content SID invalide.');
        $q=$this->pdo->prepare('SELECT id FROM whatsapp_admin_templates WHERE content_sid=?');$q->execute([$sid]);$id=(int)$q->fetchColumn();
        if(!$id){$this->pdo->prepare('INSERT INTO whatsapp_admin_templates(content_sid,name,language,definition_json,created_by) VALUES(?,?,?,?,?)')->execute([$sid,$content['friendly_name']??$sid,$content['language']??'fr',json_encode($content,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$actor]);$id=(int)$this->pdo->lastInsertId();$this->event($id,$actor,'import','unknown','Modèle ajouté au registre central.');}
        else $this->pdo->prepare('UPDATE whatsapp_admin_templates SET name=?,language=?,definition_json=? WHERE id=?')->execute([$content['friendly_name']??$sid,$content['language']??'fr',json_encode($content,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$id]);
        return $id;
    }
    public function importSid(string $sid,int $actor):int
    {
        $this->admin($actor);return $this->lock(function()use($sid,$actor){$id=$this->register($this->api->content($sid),$actor);$this->pollOne($id,$actor);return $id;});
    }
    public function syncInventory(string $token,int $actor):array
    {
        $this->admin($actor);return $this->lock(function()use($token,$actor){$page=$this->api->inventory($token);foreach($page['contents'] as $content)$this->register($content,$actor);$this->event(null,$actor,'sync','success',count($page['contents']).' modèles synchronisés.');return ['count'=>count($page['contents']),'next'=>$page['next']];});
    }
    public function create(array $input,int $actor):int
    {
        $this->admin($actor);$validated=WhatsAppAdminContentService::definitionFromInput($input);
        return $this->lock(function()use($validated,$actor){
            $d=$validated['definition'];$this->pdo->prepare('INSERT INTO whatsapp_admin_templates(name,language,category,definition_json,status,created_by) VALUES(?,?,?,?,?,?)')->execute([$d['friendly_name'],$d['language'],$validated['category'],json_encode($d,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'creating',$actor]);$id=(int)$this->pdo->lastInsertId();$this->event($id,$actor,'create','creating','Création demandée à Twilio.');
            try{$content=$this->api->createContent($d);$sid=(string)($content['sid']??'');if(!preg_match('/^HX[a-fA-F0-9]{32}$/D',$sid))throw new RuntimeException('Résultat de création incertain.');$this->pdo->prepare("UPDATE whatsapp_admin_templates SET content_sid=?,status='created' WHERE id=?")->execute([$sid,$id]);$this->event($id,$actor,'create','created','SID enregistré.');}
            catch(Throwable $e){$this->pdo->prepare("UPDATE whatsapp_admin_templates SET status='creation_uncertain',last_error=? WHERE id=?")->execute([DailyPointWhatsAppTemplateService::safeDiagnostic($e->getMessage()),$id]);$this->event($id,$actor,'create','creation_uncertain',$e->getMessage());throw $e;}
            // Persist the SID before approval. Submission failure must never create a duplicate.
            $this->submitOne($id,$actor);return $id;
        });
    }
    private function submitOne(int $id,int $actor):void
    {
        $r=$this->template($id);if($r['status']!=='created'||empty($r['content_sid']))throw new DomainException('Seul un modèle créé non soumis peut être soumis. Pour un refus, créez une nouvelle version.');
        try{$a=$this->api->submitContent($r['content_sid'],$r['name'],$r['category']);$status=strtolower((string)($a['status']??'pending'));$this->pdo->prepare('UPDATE whatsapp_admin_templates SET status=?,last_error=NULL,next_check=0 WHERE id=?')->execute([$status,$id]);$this->event($id,$actor,'submit',$status,'Modèle soumis à Meta.');}
        catch(Throwable $e){$this->pdo->prepare('UPDATE whatsapp_admin_templates SET last_error=? WHERE id=?')->execute([DailyPointWhatsAppTemplateService::safeDiagnostic($e->getMessage()),$id]);$this->event($id,$actor,'submit','failed',$e->getMessage());throw $e;}
    }
    public function submit(int $id,int $actor):void{$this->admin($actor);$this->lock(fn()=>$this->submitOne($id,$actor));}
    public function reconcile(int $id,string $sid,int $actor):void
    {
        $this->admin($actor);$this->lock(function()use($id,$sid,$actor){$r=$this->template($id);if(!in_array($r['status'],['creating','creation_uncertain'],true)||$r['content_sid'])throw new DomainException('Ce modèle ne nécessite pas de rapprochement.');$c=$this->api->content($sid);$d=json_decode($r['definition_json'],true);if(($c['friendly_name']??'')!==$r['name']||($c['types']??[])!==($d['types']??[])||($c['language']??'')!==$r['language'])throw new DomainException('Le SID ne correspond pas au modèle créé.');$this->pdo->prepare("UPDATE whatsapp_admin_templates SET content_sid=?,status='created',last_error=NULL WHERE id=?")->execute([$sid,$id]);$this->event($id,$actor,'reconcile','created','Création rapprochée du SID Twilio.');});
    }
    private function pollOne(int $id,?int $actor):void
    {
        $r=$this->template($id);if(!$r['content_sid'])return;
        try{$c=$this->api->content($r['content_sid']);$a=$this->api->fetchApproval($r['content_sid']);$status=$a['status'];if($r['status']==='created'&&$status==='unknown')$status='created';$reason=$a['reason'];$next=time()+(in_array($status,['pending','received','unknown'],true)?300:3600);
            $this->pdo->prepare('UPDATE whatsapp_admin_templates SET name=?,language=?,definition_json=?,status=?,category=?,last_error=?,checked_at=CURRENT_TIMESTAMP,next_check=? WHERE id=?')->execute([$c['friendly_name']??$r['name'],$c['language']??$r['language'],json_encode($c,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$status,$a['category']?:$r['category'],$reason?:null,$next,$id]);
            if($status!==$r['status']||($reason?:null)!==$r['last_error'])$this->event($id,$actor,'check',$status,$reason?:'Statut Meta : '.$status);
        }catch(Throwable $e){$error=DailyPointWhatsAppTemplateService::safeDiagnostic($e->getMessage());$this->pdo->prepare('UPDATE whatsapp_admin_templates SET last_error=?,next_check=?,checked_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$error,time()+900,$id]);if($error!==$r['last_error'])$this->event($id,$actor,'check','failed',$error);throw $e;}
    }
    public function poll(int $id,int $actor):void{$this->admin($actor);$this->lock(fn()=>$this->pollOne($id,$actor));}
    public function pollDue():int
    {
        return $this->lock(function(){$q=$this->pdo->prepare('SELECT id FROM whatsapp_admin_templates WHERE content_sid IS NOT NULL AND next_check<=? ORDER BY next_check,id LIMIT 3');$q->execute([time()]);$failed=0;foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id){try{$this->pollOne((int)$id,null);}catch(Throwable $e){$failed++;}}return $failed;});
    }
    public function sendTest(int $id,string $phone,array $input,int $actor):array
    {
        $this->admin($actor);return $this->lock(function()use($id,$phone,$input,$actor){$r=$this->template($id);if(!$r['content_sid'])throw new DomainException('Aucun modèle Twilio créé.');$model=$this->api->inspect($r['content_sid']);if($model['status']!=='approved')throw new DomainException('Approbation Meta requise avant envoi.');
            $phone=WhatsAppTemplateTestService::phone($phone);$bindings=$this->bindings();$signature=isset($bindings[$r['content_sid']]);
            // Known signature endpoints always use a fake token, including imported former versions.
            foreach($model['actions'] as $action)if(str_contains((string)($action['url']??''),'signature-point/')||str_contains((string)($action['url']??''),'validation_point_financier.php'))$signature=true;
            $values=[];foreach($model['keys'] as $key){$raw=$input[$key]??'';if($signature&&(string)$key==='7')$raw=str_repeat('a',64);if(!is_string($raw)||trim($raw)===''||mb_strlen($raw)>500||preg_match('/[\r\n\t]/',$raw))throw new DomainException('Variable '.$key.' invalide.');$values[$key]=trim($raw);}
            $event=$this->event($id,$actor,'test','sending','Envoi du test demandé.',$phone);
            try{$result=$this->channel->sendTemplateDetailed($phone,$r['content_sid'],$values);}catch(Throwable $e){$result=['ok'=>false,'error'=>$e->getMessage()];}
            $ok=!empty($result['ok']);$detail=$ok?'Accepté par Twilio : '.($result['status']??'queued'):'Code '.($result['error_code']??'').' : '.($result['error']??'Erreur inconnue');
            $this->pdo->prepare('UPDATE whatsapp_admin_events SET status=?,message_sid=?,detail=? WHERE id=?')->execute([$ok?'accepted':'failed',$result['message_sid']??null,DailyPointWhatsAppTemplateService::safeDiagnostic($detail),$event]);return $result;
        });
    }
    public function checkDelivery(int $eventId,int $actor):void
    {
        $this->admin($actor);$this->lock(function()use($eventId,$actor){
            $q=$this->pdo->prepare("SELECT * FROM whatsapp_admin_events WHERE id=? AND action='test'");$q->execute([$eventId]);$e=$q->fetch(PDO::FETCH_ASSOC);
            if(!$e||!$e['message_sid'])throw new DomainException('Aucun message Twilio à contrôler.');
            try{$r=$this->api->messageStatus($e['message_sid']);$detail='Statut Twilio : '.$r['status'].($r['error_code']?' · code '.$r['error_code']:'').($r['error']?' · '.$r['error']:'');
                $this->pdo->prepare('UPDATE whatsapp_admin_events SET status=?,detail=? WHERE id=?')->execute([$r['status'],DailyPointWhatsAppTemplateService::safeDiagnostic($detail),$eventId]);
                if($r['status']!==$e['status'])$this->event($e['template_id']?(int)$e['template_id']:null,$actor,'delivery',$r['status'],$detail,$e['recipient_phone'],$e['message_sid']);
            }catch(Throwable $error){$this->event($e['template_id']?(int)$e['template_id']:null,$actor,'delivery','check_failed',$error->getMessage(),$e['recipient_phone'],$e['message_sid']);throw $error;}
        });
    }
    public function legacyHistory():array
    {
        $events=[];
        foreach(['daily_point_template_monitor_events'=>'Finance','rh_financial_template_events'=>'RH','daily_point_whatsapp_tests'=>'Tests finance'] as $table=>$label){if(!$this->exists($table))continue;foreach($this->pdo->query('SELECT * FROM '.$table.' ORDER BY id DESC LIMIT 30')->fetchAll(PDO::FETCH_ASSOC) as $row)$events[]=['source'=>$label]+$row;}
        usort($events,static fn($a,$b)=>strcmp($b['created_at'],$a['created_at']));return array_slice($events,0,60);
    }
}
