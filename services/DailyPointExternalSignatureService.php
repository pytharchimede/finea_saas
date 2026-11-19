<?php

require_once __DIR__.'/NotificationWhatsAppChannelService.php';
require_once __DIR__.'/TwilioWhatsAppConfigService.php';

class DailyPointExternalSignatureService
{
    public function __construct(private PDO $pdo){$this->ensureSchema();}

    private function ensureSchema():void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS daily_disbursement_point_settings(id TINYINT UNSIGNED PRIMARY KEY,dg_whatsapp_phone VARCHAR(40) NULL,signature_link_ttl_hours SMALLINT UNSIGNED NOT NULL DEFAULT 24,updated_by INT NULL,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->pdo->exec("INSERT IGNORE INTO daily_disbursement_point_settings(id,signature_link_ttl_hours) VALUES(1,24)");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS daily_disbursement_point_signature_links(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,point_id BIGINT UNSIGNED NOT NULL,signer_user_id INT NOT NULL,token_hash CHAR(64) NOT NULL,pin_hash VARCHAR(255) NOT NULL,phone_e164 VARCHAR(24) NOT NULL,expires_at DATETIME NOT NULL,used_at DATETIME NULL,revoked_at DATETIME NULL,verified_at DATETIME NULL,failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,locked_at DATETIME NULL,whatsapp_status VARCHAR(30) NOT NULL DEFAULT 'pending',whatsapp_sent_at DATETIME NULL,created_by INT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,last_access_at DATETIME NULL,UNIQUE KEY uq_daily_point_token(token_hash),KEY idx_daily_point_active(point_id,signer_user_id,expires_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS daily_disbursement_point_signature_events(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,link_id BIGINT UNSIGNED NOT NULL,event_type VARCHAR(40) NOT NULL,detail VARCHAR(255) NULL,ip_hash CHAR(64) NULL,user_agent_hash CHAR(64) NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,KEY idx_daily_point_link_events(link_id,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS daily_disbursement_point_payment_keys(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,point_id BIGINT UNSIGNED NOT NULL,key_hash CHAR(64) NOT NULL,expires_at DATETIME NOT NULL,activated_by_user_id INT NULL,activated_at DATETIME NULL,revoked_at DATETIME NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uq_daily_point_payment_key(key_hash),KEY idx_daily_point_payment_key(point_id,expires_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        try{$this->pdo->exec("ALTER TABLE daily_disbursement_point_payment_keys ADD COLUMN key_value VARCHAR(13) NULL AFTER key_hash");}catch(Throwable $e){}
    }

    public function settings():array{return$this->pdo->query('SELECT * FROM daily_disbursement_point_settings WHERE id=1')->fetch(PDO::FETCH_ASSOC)?:[];}
    public function saveSettings(string $phone,int $ttlHours,int $userId):void{$phone=$this->normalizePhone($phone);$ttlHours=max(1,min(72,$ttlHours));$s=$this->pdo->prepare('UPDATE daily_disbursement_point_settings SET dg_whatsapp_phone=:phone,signature_link_ttl_hours=:ttl,updated_by=:uid,updated_at=NOW() WHERE id=1');$s->execute(['phone'=>$phone,'ttl'=>$ttlHours,'uid'=>$userId]);}
    public function normalizePhone(string $phone):string{$phone=preg_replace('/^whatsapp:/i','',trim($phone));$digits=preg_replace('/\D+/','',$phone);if(str_starts_with($digits,'00'))$digits=substr($digits,2);if($phone!==''&&!str_starts_with($phone,'+')&&!str_starts_with($digits,'225'))$digits='225'.$digits;$normalized='+'.$digits;if(!preg_match('/^\+[1-9]\d{7,14}$/',$normalized))throw new RuntimeException('Indiquez un numéro WhatsApp valide au format international, par exemple +2250700000000.');return$normalized;}

    public function invite(int $pointId,int $createdBy):array
    {
        $settings=$this->settings();$phone=trim((string)($settings['dg_whatsapp_phone']??''));
        $s=$this->pdo->prepare("SELECT a.user_id,p.point_date,p.version_no,p.status,p.total_amount,p.item_count,TRIM(CONCAT(COALESCE(u.prenom,''),' ',COALESCE(u.nom,''))) signer_name FROM daily_disbursement_point_authorizations a JOIN daily_disbursement_points p ON p.id=a.point_id LEFT JOIN user_devis_banamur u ON u.id=a.user_id WHERE a.point_id=:point AND a.role_code='dg' AND a.revoked_at IS NULL LIMIT 1");$s->execute(['point'=>$pointId]);$row=$s->fetch(PDO::FETCH_ASSOC);if(!$row||$row['status']!=='stopped')return['sent'=>false,'status'=>'not_eligible'];
        $this->pdo->prepare('UPDATE daily_disbursement_point_signature_links SET revoked_at=NOW() WHERE point_id=:point AND used_at IS NULL AND revoked_at IS NULL')->execute(['point'=>$pointId]);
        $token=bin2hex(random_bytes(32));$ttl=max(1,min(72,(int)($settings['signature_link_ttl_hours']??24)));$expiresAt=date('Y-m-d H:i:s',time()+$ttl*3600);
        $insert=$this->pdo->prepare("INSERT INTO daily_disbursement_point_signature_links(point_id,signer_user_id,token_hash,pin_hash,phone_e164,expires_at,created_by) VALUES(:point,:signer,:token,'-',:phone,:expires,:creator)");$insert->execute(['point'=>$pointId,'signer'=>(int)$row['user_id'],'token'=>hash('sha256',$token),'phone'=>$phone,'expires'=>$expiresAt,'creator'=>$createdBy]);$linkId=(int)$this->pdo->lastInsertId();
        $url=$this->baseUrl().'/decaissement/signature_point_jour.php?token='.$token;
        $sent=false;$status='manual';
        if($phone!==''){try{$twilio=(new TwilioWhatsAppConfigService($this->pdo))->credentials();$variables=[
            '1'=>trim((string)($row['signer_name']??''))?:'Directeur Général',
            '2'=>(string)$pointId,
            '3'=>date('d/m/Y',strtotime((string)$row['point_date'])),
            '4'=>(string)(int)($row['item_count']??0),
            '5'=>number_format((float)($row['total_amount']??0),0,',',' ').' FCFA',
            '6'=>date('d/m/Y à H:i',strtotime($expiresAt)),
            '7'=>$token,
        ];$delivery=(new NotificationWhatsAppChannelService($twilio['sid'],$twilio['token'],$twilio['from']))->sendTemplateDetailed($phone,$twilio['point_stopped_content_sid'],!empty($twilio['point_stopped_has_variables'])?$variables:[]);$sent=!empty($delivery['ok']);$status=$sent?'sent':'failed';if(!$sent)$this->event($linkId,'whatsapp_send_failed','Code Twilio : '.preg_replace('/[^0-9]/','',(string)($delivery['error_code']??'')));}catch(Throwable $e){$status='failed';}}
        $this->pdo->prepare('UPDATE daily_disbursement_point_signature_links SET whatsapp_status=:status,whatsapp_sent_at=IF(:sent=1,NOW(),NULL) WHERE id=:id')->execute(['status'=>$status,'sent'=>$sent?1:0,'id'=>$linkId]);
        $this->event($linkId,$sent?'whatsapp_template_sent':'signature_link_created','Point #'.$pointId.' | lien #'.$linkId);
        return['sent'=>$sent,'status'=>$status,'link_id'=>$linkId,'url'=>$url,'expires_at'=>$expiresAt];
    }

    public function lookup(string $token,bool $requireVerified=false):array
    {
        if(!preg_match('/^[a-f0-9]{64}$/',$token))throw new RuntimeException('Lien invalide.');$s=$this->pdo->prepare("SELECT l.*,p.point_date,p.version_no,p.status,p.total_amount,p.item_count,TRIM(CONCAT(COALESCE(u.prenom,''),' ',COALESCE(u.nom,''))) signer_name FROM daily_disbursement_point_signature_links l JOIN daily_disbursement_points p ON p.id=l.point_id LEFT JOIN user_devis_banamur u ON u.id=l.signer_user_id WHERE l.token_hash=:hash LIMIT 1");$s->execute(['hash'=>hash('sha256',$token)]);$row=$s->fetch(PDO::FETCH_ASSOC);if(!$row||$row['revoked_at']||$row['used_at']||$row['locked_at']||strtotime((string)$row['expires_at'])<time()||$row['status']!=='stopped')throw new RuntimeException('Ce lien est expiré, déjà utilisé ou révoqué.');$this->pdo->prepare('UPDATE daily_disbursement_point_signature_links SET last_access_at=NOW() WHERE id=:id')->execute(['id'=>$row['id']]);return$row;
    }
    public function verify(string $token,string $pin):array{$row=$this->lookup($token);$id=(int)$row['id'];if(!preg_match('/^\d{6}$/',$pin)||!password_verify($pin,(string)$row['pin_hash'])){$this->pdo->prepare('UPDATE daily_disbursement_point_signature_links SET failed_attempts=failed_attempts+1,locked_at=IF(failed_attempts+1>=5,NOW(),locked_at) WHERE id=:id')->execute(['id'=>$id]);$this->event($id,'verification_failed');throw new RuntimeException('Code incorrect. Après cinq essais, le lien est bloqué.');}if(session_status()!==PHP_SESSION_ACTIVE)session_start();session_regenerate_id(true);$_SESSION['daily_point_external_verified'][$id]=time()+900;$this->pdo->prepare('UPDATE daily_disbursement_point_signature_links SET verified_at=NOW(),failed_attempts=0 WHERE id=:id')->execute(['id'=>$id]);$this->event($id,'verified');return$row;}
    public function sessionVerified(int $linkId):bool{if(session_status()!==PHP_SESSION_ACTIVE)session_start();return(int)($_SESSION['daily_point_external_verified'][$linkId]??0)>=time();}
    public function markUsed(int $linkId):void{$this->pdo->prepare('UPDATE daily_disbursement_point_signature_links SET used_at=NOW() WHERE id=:id')->execute(['id'=>$linkId]);$this->event($linkId,'signed');unset($_SESSION['daily_point_external_verified'][$linkId]);}
    public function issuePaymentKey(int $pointId):string{$this->pdo->prepare('UPDATE daily_disbursement_point_payment_keys SET revoked_at=NOW() WHERE point_id=:point AND revoked_at IS NULL')->execute(['point'=>$pointId]);$alphabet='ABCDEFGHJKLMNPQRSTUVWXYZ23456789';$random='';for($i=0;$i<8;$i++)$random.=$alphabet[random_int(0,strlen($alphabet)-1)];$key='DEC-'.substr($random,0,4).'-'.substr($random,4);$s=$this->pdo->prepare('INSERT INTO daily_disbursement_point_payment_keys(point_id,key_hash,key_value,expires_at) VALUES(:point,:hash,:key_value,:expires)');$s->execute(['point'=>$pointId,'hash'=>hash('sha256',strtoupper($key)),'key_value'=>$key,'expires'=>date('Y-m-d H:i:s',strtotime('+72 hours'))]);return$key;}
    public function paymentKeyForDirector(int $pointId):string
    {
        $s=$this->pdo->prepare("SELECT key_value FROM daily_disbursement_point_payment_keys WHERE point_id=:point AND revoked_at IS NULL AND expires_at>=NOW() ORDER BY id DESC LIMIT 1");
        $s->execute(['point'=>$pointId]);
        $key=trim((string)$s->fetchColumn());
        // Les anciennes clés n'étaient conservées que sous forme d'empreinte,
        // donc elles ne sont pas récupérables. On les remplace une seule fois.
        return $key!==''?$key:$this->issuePaymentKey($pointId);
    }
    public function activatePaymentKey(string $key,int $userId):int{$key=strtoupper(trim($key));if(!preg_match('/^DEC-[A-Z2-9]{4}-[A-Z2-9]{4}$/',$key))throw new RuntimeException('La clé de décaissement est invalide.');$s=$this->pdo->prepare("SELECT k.id,k.point_id FROM daily_disbursement_point_payment_keys k JOIN daily_disbursement_points p ON p.id=k.point_id WHERE k.key_hash=:hash AND k.revoked_at IS NULL AND k.expires_at>=NOW() AND (k.activated_by_user_id IS NULL OR k.activated_by_user_id=:user) AND p.status='certified' LIMIT 1");$s->execute(['hash'=>hash('sha256',$key),'user'=>$userId]);$row=$s->fetch(PDO::FETCH_ASSOC);if(!$row)throw new RuntimeException('Cette clé est incorrecte, expirée ou déjà attribuée à une autre personne.');$this->pdo->prepare('UPDATE daily_disbursement_point_payment_keys SET activated_by_user_id=:user,activated_at=COALESCE(activated_at,NOW()) WHERE id=:id')->execute(['user'=>$userId,'id'=>$row['id']]);return(int)$row['point_id'];}
    public function revokePaymentKeys(int $pointId):void{$this->pdo->prepare('UPDATE daily_disbursement_point_payment_keys SET revoked_at=COALESCE(revoked_at,NOW()) WHERE point_id=:point')->execute(['point'=>$pointId]);}
    public function revokePointLinks(int $pointId):void{$this->pdo->prepare('UPDATE daily_disbursement_point_signature_links SET revoked_at=COALESCE(revoked_at,NOW()) WHERE point_id=:point AND used_at IS NULL')->execute(['point'=>$pointId]);}
    public function event(int $linkId,string $type,string $detail=''):void{$salt=hash('sha256',(string)$linkId.'|daily-point');$s=$this->pdo->prepare('INSERT INTO daily_disbursement_point_signature_events(link_id,event_type,detail,ip_hash,user_agent_hash) VALUES(:link,:type,:detail,:ip,:ua)');$s->execute(['link'=>$linkId,'type'=>$type,'detail'=>mb_substr($detail,0,255),'ip'=>hash('sha256',$salt.'|'.($_SERVER['REMOTE_ADDR']??'')),'ua'=>hash('sha256',$salt.'|'.($_SERVER['HTTP_USER_AGENT']??''))]);}
    private function baseUrl():string
    {
        $host=preg_replace('/[^a-zA-Z0-9.:-]/','',$_SERVER['HTTP_HOST']??'');
        if($host===''||preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$/i',$host)){$script=$_SERVER['SCRIPT_NAME']??'';$root=str_contains($script,'/banamur_planification/')?'/banamur_planification':'';return'http://'.($host?:'localhost').$root;}
        // Les liens transmis hors de l'entreprise utilisent toujours le portail
        // public HTTPS, quelle que soit l'URL interne ayant servi à créer le point.
        return'https://projet.fidest.ci';
    }
    private function loadTwilioConfig():void{$file=__DIR__.'/../decaissement/config/app_config.php';if(is_file($file))require_once$file;}
}

