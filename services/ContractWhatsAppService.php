<?php
declare(strict_types=1);
require_once __DIR__.'/ContractWhatsAppCatalog.php';
require_once __DIR__.'/WhatsAppAdminContentService.php';
require_once __DIR__.'/NotificationWhatsAppChannelService.php';

final class ContractWhatsAppService
{
    public function __construct(private PDO $pdo,private WhatsAppAdminContentService $api,private NotificationWhatsAppChannelService $channel){}

    public static function ready(PDO $pdo):bool
    {
        return (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('contract_whatsapp_templates','contract_whatsapp_outbox','contract_whatsapp_settings')")->fetchColumn()===3;
    }

    public static function ensureAutomationSchema(PDO $pdo):void
    {
        $q=$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='contract_whatsapp_settings' AND COLUMN_NAME='auto_activation'");
        if((int)$q->fetchColumn()===0)$pdo->exec('ALTER TABLE contract_whatsapp_settings ADD COLUMN IF NOT EXISTS auto_activation TINYINT(1) NOT NULL DEFAULT 1');
    }

    /** Suspension explicite persistante : le cron continue le contrôle sans réactiver les envois. */
    public function setAutomatic(bool $enabled):void
    {
        $this->pdo->prepare('UPDATE contract_whatsapp_settings SET auto_activation=?,enabled=0 WHERE id=1')->execute([$enabled?1:0]);
    }

    public function refreshAutomation():array
    {
        $settings=$this->pdo->query('SELECT * FROM contract_whatsapp_settings WHERE id=1')->fetch(PDO::FETCH_ASSOC);
        $keys=$this->pdo->query("SELECT template_key FROM contract_whatsapp_templates WHERE content_sid IS NOT NULL AND status='approved' AND category='UTILITY' AND checked_at>=DATE_SUB(NOW(),INTERVAL 15 MINUTE) ORDER BY template_key")->fetchAll(PDO::FETCH_COLUMN);
        $automatic=!empty($settings['auto_activation']);$active=$automatic&&count($keys)>0;
        if($automatic)$this->pdo->prepare('UPDATE contract_whatsapp_settings SET enabled=?,activated_at=COALESCE(activated_at,NOW()) WHERE id=1')->execute([$active?1:0]);
        else $this->pdo->exec('UPDATE contract_whatsapp_settings SET enabled=0 WHERE id=1');
        return ['automatic'=>$automatic,'enabled'=>$active,'approved_templates'=>$keys,'approved_count'=>count($keys),'total_templates'=>count(ContractWhatsAppCatalog::inputs())];
    }

    /** Appelé sous le verrou dédié ; surveille même avant la première approbation. */
    public function runCycle():array
    {
        $this->poll();$state=$this->refreshAutomation();
        if($state['automatic'])$this->queue();
        $result=$this->dispatch();$this->deliveries();
        return $state+['dispatch'=>$result];
    }

    public function locked(callable $task):mixed
    {
        $key='finea_contract_wa_'.substr(hash('sha256',(string)$this->pdo->query('SELECT DATABASE()')->fetchColumn()),0,24);
        $q=$this->pdo->prepare('SELECT GET_LOCK(?,0)');$q->execute([$key]);
        if((int)$q->fetchColumn()!==1)throw new RuntimeException('Une opération WhatsApp contrats est déjà en cours.');
        try{return $task();}finally{$this->pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$key]);}
    }

    /** Persiste avant chaque appel externe : une réponse incertaine n’est jamais recréée automatiquement. */
    public function provision():array
    {
        $results=[];
        foreach(ContractWhatsAppCatalog::inputs() as $key=>$input){
            try{
                $q=$this->pdo->prepare('SELECT * FROM contract_whatsapp_templates WHERE template_key=?');$q->execute([$key]);$r=$q->fetch(PDO::FETCH_ASSOC);
                if(!$r){$d=WhatsAppAdminContentService::definitionFromInput($input)['definition'];$this->pdo->prepare('INSERT INTO contract_whatsapp_templates(template_key,name,definition_json) VALUES(?,?,?)')->execute([$key,$d['friendly_name'],json_encode($d,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);$r=['status'=>'prepared','definition_json'=>json_encode($d),'name'=>$d['friendly_name'],'content_sid'=>null];}
                if($r['status']==='prepared'){
                    $this->pdo->prepare("UPDATE contract_whatsapp_templates SET status='creation_uncertain' WHERE template_key=?")->execute([$key]);
                    $content=$this->api->createContent(json_decode($r['definition_json'],true,512,JSON_THROW_ON_ERROR));$sid=(string)($content['sid']??'');
                    if(!preg_match('/^HX[a-fA-F0-9]{32}$/D',$sid))throw new RuntimeException('Création incertaine : rapprochez le modèle depuis Twilio.');
                    $this->pdo->prepare("UPDATE contract_whatsapp_templates SET content_sid=?,status='created',last_error=NULL WHERE template_key=?")->execute([$sid,$key]);$r['content_sid']=$sid;$r['status']='created';
                }
                if($r['status']==='created'){
                    // Un éventuel timeout de soumission se rapproche par GET avant tout nouveau POST.
                    $this->pdo->prepare("UPDATE contract_whatsapp_templates SET status='submission_uncertain' WHERE template_key=?")->execute([$key]);
                    $a=$this->api->submitContent($r['content_sid'],$r['name'],'UTILITY');
                    $this->pdo->prepare('UPDATE contract_whatsapp_templates SET status=?,last_error=NULL WHERE template_key=?')->execute([strtolower($a['status']??'pending'),$key]);
                }
                $q=$this->pdo->prepare('SELECT status FROM contract_whatsapp_templates WHERE template_key=?');$q->execute([$key]);$results[$key]=(string)$q->fetchColumn();
            }catch(Throwable $e){$error=DailyPointWhatsAppTemplateService::safeDiagnostic($e->getMessage());$this->pdo->prepare('UPDATE contract_whatsapp_templates SET last_error=? WHERE template_key=?')->execute([$error,$key]);$results[$key]=$error;}
        }
        return $results;
    }

    public function poll(bool $force=false):void
    {
        foreach($this->pdo->query("SELECT * FROM contract_whatsapp_templates WHERE content_sid IS NOT NULL".($force?'':" AND (checked_at IS NULL OR checked_at<DATE_SUB(NOW(),INTERVAL 5 MINUTE))"))->fetchAll(PDO::FETCH_ASSOC) as $r){
            try{$a=$this->api->fetchApproval($r['content_sid']);$status=$a['status'];if($status==='unknown'&&in_array($r['status'],['created','submission_uncertain'],true))$status='created';$this->pdo->prepare('UPDATE contract_whatsapp_templates SET status=?,category=?,last_error=?,checked_at=NOW() WHERE template_key=?')->execute([$status,$a['category'],$a['reason']?:null,$r['template_key']]);}
            catch(Throwable $e){$this->pdo->prepare('UPDATE contract_whatsapp_templates SET last_error=?,checked_at=NULL WHERE template_key=?')->execute([DailyPointWhatsAppTemplateService::safeDiagnostic($e->getMessage()),$r['template_key']]);}
        }
    }

    /** Le rapprochement exige que le nom et le contenu correspondent exactement. */
    public function reconcile(string $key,string $sid):void
    {
        $q=$this->pdo->prepare('SELECT * FROM contract_whatsapp_templates WHERE template_key=?');$q->execute([$key]);$r=$q->fetch(PDO::FETCH_ASSOC);
        if(!$r||$r['status']!=='creation_uncertain'||$r['content_sid'])throw new DomainException('Ce modèle ne nécessite pas de rapprochement.');
        $c=$this->api->content($sid);$d=json_decode($r['definition_json'],true);
        if(($c['friendly_name']??'')!==$r['name']||($c['types']??[])!==$d['types']||($c['language']??'')!==$d['language'])throw new DomainException('Ce SID ne correspond pas au modèle préparé.');
        $this->pdo->prepare("UPDATE contract_whatsapp_templates SET content_sid=?,status='created',last_error=NULL WHERE template_key=?")->execute([$sid,$key]);
    }

    /** Reconstitue les actions depuis la BDD, y compris après une interruption du serveur. */
    public function desired(int $contractId):array
    {
        $q=$this->pdo->prepare('SELECT c.*,ch.code chantier_code,f.telephone supplier_phone,COALESCE(NULLIF(f.nom_commercial,\'\'),f.raison_sociale) supplier_name FROM contrat_sous_traitance c JOIN chantier_banamur ch ON ch.id=c.chantier_id JOIN fournisseur_banamur f ON f.id_fournisseur=c.fournisseur_id WHERE c.id=?');$q->execute([$contractId]);$c=$q->fetch(PDO::FETCH_ASSOC);
        if(!$c||in_array($c['statut'],['resilie','suspendu','solde'],true))return [];
        $q=$this->pdo->prepare('SELECT * FROM contrat_workflow_etape WHERE contrat_id=? ORDER BY ordre');$q->execute([$contractId]);$stages=$q->fetchAll(PDO::FETCH_ASSOC);
        $recipients=[];
        if(empty($c['signature_banamur'])){
            foreach($stages as $s){if($s['statut']!=='validee'||empty($s['signature'])){if($s['statut']==='en_cours')$recipients[]=['stage'=>$s['etape'],'pid'=>(int)$s['signataire_personnel_id'],'hash'=>ContractWhatsAppCatalog::stageHash($s)];break;}}
            if(!$recipients&&count($stages)===6&&count(array_filter($stages,fn($s)=>$s['statut']==='validee'&&!empty($s['signature'])))===6)$recipients[]=['stage'=>'direction','pid'=>(int)$c['signataire_banamur_personnel_id'],'hash'=>hash('sha256',json_encode(array_column($stages,'signe_at')).json_encode(array_column($stages,'signature')).$c['signataire_banamur_personnel_id'])];
        }elseif(empty($c['signature_prestataire'])){
            $recipients[]=['stage'=>'prestataire','pid'=>0,'hash'=>hash('sha256',(string)$c['signe_banamur_at'].(string)$c['signature_banamur'])];
        }elseif($c['statut']==='actif'){
            $activated=$this->pdo->query('SELECT activated_at FROM contract_whatsapp_settings WHERE id=1')->fetchColumn();
            if(!$activated||max((string)$c['signe_banamur_at'],(string)$c['signe_prestataire_at'])<(string)$activated)return [];
            $pids=array_unique(array_merge(array_column($stages,'signataire_personnel_id'),[$c['signataire_banamur_personnel_id']]));
            foreach($pids as $pid)$recipients[]=['stage'=>'complete','pid'=>(int)$pid,'hash'=>hash('sha256',(string)$c['signe_banamur_at'].(string)$c['signe_prestataire_at'])];
        }
        $out=[];
        foreach($recipients as $r){
            if($r['stage']==='prestataire'){$name=(string)$c['supplier_name'];$phone=(string)$c['supplier_phone'];}
            else{$q=$this->pdo->prepare("SELECT p.nom_personnel_soignant name,p.tel_personnel_soignant phone FROM personnel p WHERE p.id_personnel_soignant=? AND (p.date_sortie IS NULL OR p.date_sortie='0000-00-00') AND EXISTS(SELECT 1 FROM user_devis_banamur u WHERE u.personnel_id=p.id_personnel_soignant AND u.active=1)");$q->execute([$r['pid']]);$person=$q->fetch(PDO::FETCH_ASSOC);$name=(string)($person['name']??'Signataire non affecté');$phone=(string)($person['phone']??'');}
            $stage=$r['stage'];$label=ContractWhatsAppCatalog::STAGES[$stage]??($stage==='prestataire'?'Signature du sous-traitant':'Contrat finalisé');
            $variables=['1'=>self::line($name),'2'=>self::line($c['reference']),'3'=>self::line($c['chantier_code']),'4'=>$label];
            if($stage!=='prestataire')$variables['5']=$stage==='direction'?'signer.php?id='.$contractId:($stage==='complete'?'voir.php?id='.$contractId:'workflow.php?id='.$contractId.'&etape='.$stage);
            $out[]=$r+['contract_id'=>$contractId,'phone'=>$phone,'variables'=>$variables];
        }
        return $out;
    }

    private static function line(string $text):string{return mb_substr(trim(preg_replace('/\s+/u',' ',$text)),0,200)?:'Non renseigné';}

    public function queue():void
    {
        foreach($this->pdo->query("SELECT id FROM contrat_sous_traitance WHERE statut IN ('brouillon','a_signer','actif')")->fetchAll(PDO::FETCH_COLUMN) as $id){
            foreach($this->desired((int)$id) as $r){
                $q=$this->pdo->prepare("SELECT COUNT(*) FROM contract_whatsapp_outbox WHERE contract_id=? AND stage=? AND recipient_personnel_id=? AND fingerprint<>? AND status='sent'");$q->execute([$id,$r['stage'],$r['pid'],$r['hash']]);
                $hasPrevious=(int)$q->fetchColumn()>0;
                $template=$hasPrevious&&isset(ContractWhatsAppCatalog::STAGES[$r['stage']])?'revision':$r['stage'];
                $this->pdo->prepare('INSERT IGNORE INTO contract_whatsapp_outbox(contract_id,stage,recipient_personnel_id,fingerprint,template_key) VALUES(?,?,?,?,?)')->execute([$id,$r['stage'],$r['pid'],$r['hash'],$template]);
            }
        }
    }

    /** Un envoi commencé reste incertain en cas d’interruption : aucun renvoi automatique aveugle. */
    public function dispatch():array
    {
        $counts=['sent'=>0,'blocked'=>0,'obsolete'=>0];
        $settings=$this->pdo->query('SELECT * FROM contract_whatsapp_settings WHERE id=1')->fetch(PDO::FETCH_ASSOC);
        if(empty($settings['enabled'])||empty($settings['auto_activation']))return $counts;
        $this->pdo->exec("UPDATE contract_whatsapp_outbox SET status='uncertain',last_error='Envoi interrompu : contrôler Twilio avant toute reprise.' WHERE status='sending' AND updated_at<DATE_SUB(NOW(),INTERVAL 5 MINUTE)");
        $rows=$this->pdo->query("SELECT * FROM contract_whatsapp_outbox WHERE status IN ('pending','blocked') ORDER BY updated_at,id LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as $job){
            $desired=array_values(array_filter($this->desired((int)$job['contract_id']),fn($r)=>$r['stage']===$job['stage']&&$r['pid']===(int)$job['recipient_personnel_id']&&hash_equals($r['hash'],$job['fingerprint'])));
            if(!$desired){$this->finish((int)$job['id'],'obsolete',null);$counts['obsolete']++;continue;}
            try{
                $r=$desired[0];$phone=ContractWhatsAppCatalog::phone($r['phone']);
                $q=$this->pdo->prepare("SELECT * FROM contract_whatsapp_templates WHERE template_key=? AND status='approved' AND category='UTILITY' AND checked_at>=DATE_SUB(NOW(),INTERVAL 15 MINUTE)");$q->execute([$job['template_key']]);$template=$q->fetch(PDO::FETCH_ASSOC);
                if(!$template)throw new DomainException('Modèle Utility non approuvé ou contrôle à renouveler.');
                $this->pdo->prepare("UPDATE contract_whatsapp_outbox SET status='sending',attempts=attempts+1,last_error=NULL WHERE id=?")->execute([$job['id']]);
                $result=$this->channel->sendTemplateDetailed($phone,$template['content_sid'],$r['variables']);
                $status=!empty($result['ok'])&&!empty($result['message_sid'])?'sent':'uncertain';
                $this->pdo->prepare('UPDATE contract_whatsapp_outbox SET status=?,message_sid=?,provider_status=?,last_error=? WHERE id=?')->execute([$status,$result['message_sid']?:null,$result['status']??null,empty($result['ok'])?DailyPointWhatsAppTemplateService::safeDiagnostic($result['error']??'Résultat incertain.'):null,$job['id']]);
                if($status==='sent')$counts['sent']++;
            }catch(Throwable $e){$q=$this->pdo->prepare('SELECT status FROM contract_whatsapp_outbox WHERE id=?');$q->execute([$job['id']]);$status=$q->fetchColumn()==='sending'?'uncertain':'blocked';$this->finish((int)$job['id'],$status,DailyPointWhatsAppTemplateService::safeDiagnostic($e->getMessage()));$counts['blocked']++;}
        }
        return $counts;
    }

    private function finish(int $id,string $status,?string $error):void{$this->pdo->prepare('UPDATE contract_whatsapp_outbox SET status=?,last_error=?,updated_at=NOW() WHERE id=?')->execute([$status,$error,$id]);}

    public function deliveries():void
    {
        $rows=$this->pdo->query("SELECT id,message_sid FROM contract_whatsapp_outbox WHERE status='sent' AND provider_status IN ('queued','accepted','sending','sent') ORDER BY updated_at LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as $r){try{$d=$this->api->messageStatus($r['message_sid']);$this->pdo->prepare('UPDATE contract_whatsapp_outbox SET provider_status=?,last_error=?,updated_at=NOW() WHERE id=?')->execute([$d['status'],$d['error']?:null,$r['id']]);}catch(Throwable $e){error_log('[contract_whatsapp] Lecture livraison indisponible.');}}
    }
}
