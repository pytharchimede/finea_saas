<?php
declare(strict_types=1);
require_once __DIR__.'/RhFinancialPointPolicy.php';
require_once __DIR__.'/RhFinancialPointPdfBuilder.php';
require_once __DIR__.'/PasskeySignatureGuard.php';
require_once __DIR__.'/RhFinancialSignature.php';

/** All schema changes belong to the explicit migration; none run inside decisions. */
final class RhFinancialPointService
{
    public function __construct(private PDO $pdo) {}

    public function settings(): array
    {
        return $this->pdo->query('SELECT * FROM rh_financial_settings WHERE id=1')->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    public static function managedRequest(PDO $pdo, array $request): bool
    {
        $kind = array_search($request['request_type'] ?? '',RhFinancialPointPolicy::REQUEST_TYPES,true);
        if ($kind === false) return false;
        try { $q=$pdo->query('SELECT '.$kind.'_active_at FROM rh_financial_settings WHERE id=1'); }
        catch (PDOException $e) { if (($e->errorInfo[1] ?? 0) === 1146) return false; throw $e; }
        $cutoff = $q->fetchColumn();
        return $cutoff && (string)($request['submitted_at'] ?? '') >= $cutoff;
    }

    public function actor(int $userId, string $role): array
    {
        $q=$this->pdo->prepare('SELECT id,acces_rh,acces_direction,acces_admin,mail_pro AS email,active FROM user_devis_banamur WHERE id=?');
        $q->execute([$userId]); $user=$q->fetch(PDO::FETCH_ASSOC);
        if (!$user || !$user['active']) throw new DomainException('Compte actif requis.');
        if ($role==='admin' && empty($user['acces_admin'])) throw new DomainException('Paramétrage réservé à l’administration.');
        if ($role==='rh' && empty($user['acces_rh']) && empty($user['acces_admin'])) throw new DomainException('Action réservée aux RH.');
        if ($role==='dg' && ((int)($this->settings()['dg_user_id'] ?? 0)!==$userId || empty($user['acces_direction']))) throw new DomainException('Seul le DG désigné peut signer ce point.');
        return $user;
    }

    public function directionCandidates(int $userId): array
    {
        $this->actor($userId,'admin');
        return $this->pdo->query('SELECT id,mail_pro AS email,nom,prenom FROM user_devis_banamur WHERE active=1 AND acces_direction=1 ORDER BY nom,prenom,mail_pro')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function configure(int $userId, int $dgId, string $phone): void
    {
        $this->actor($userId,'admin');
        $q=$this->pdo->prepare('SELECT COUNT(*) FROM user_devis_banamur WHERE id=? AND active=1 AND acces_direction=1');
        $q->execute([$dgId]); if ((int)$q->fetchColumn()!==1) throw new DomainException('Sélectionnez le compte actif du DG disposant de l’accès Direction.');
        $phone=preg_replace('/[\s.-]/','',trim($phone));
        if (!preg_match('/^\+[1-9]\d{7,14}$/D',$phone)) throw new DomainException('Numéro international requis, par exemple +225…');
        $pending=$this->pdo->query("SELECT COUNT(*) FROM rh_financial_points WHERE status IN ('draft','rh_signed')")->fetchColumn();
        $settings=$this->settings();
        if ($pending && ((int)$settings['dg_user_id']!==$dgId || $settings['dg_phone']!==$phone)) throw new DomainException('Terminez ou réouvrez les points en cours avant de changer de DG.');
        $this->pdo->prepare('UPDATE rh_financial_settings SET dg_user_id=?,dg_phone=?,updated_by=? WHERE id=1')->execute([$dgId,$phone,$userId]);
    }

    public function activate(string $kind, string $sid, int $userId): void
    {
        $this->actor($userId,'admin'); $this->assertKind($kind);
        $s=$this->settings(); if (empty($s['dg_user_id']) || empty($s['dg_phone'])) throw new DomainException('Configurez le DG et son numéro WhatsApp.');
        require_once __DIR__.'/DailyPointExternalSignatureService.php';
        require_once __DIR__.'/RhFinancialWhatsAppTemplateService.php';
        new DailyPointExternalSignatureService($this->pdo);
        $c=(new TwilioWhatsAppConfigService($this->pdo))->credentials();
        if ($c['from']==='') throw new DomainException('Configurez l’émetteur WhatsApp.');
        (new RhFinancialWhatsAppTemplateService($c['sid'],$c['token']))->assertKindApproved($kind,$sid);
        $this->pdo->prepare('UPDATE rh_financial_settings SET '.$kind.'_sid=?,'.$kind.'_active_at=COALESCE('.$kind.'_active_at,NOW()),updated_by=? WHERE id=1')->execute([$sid,$userId]);
    }

    public function candidates(string $kind, string $period=''): array
    {
        $this->assertKind($kind); $settings=$this->settings(); $cutoff=$settings[$kind.'_active_at'] ?? null;
        if (!$cutoff) return [];
        if ($kind==='salary') {
            $this->assertPeriod($period);
            if ($period < substr($cutoff,0,7)) throw new DomainException('Cette paie est antérieure à l’activation du circuit.');
            require_once __DIR__.'/RhPayrollWorkflowService.php';
            $payroll=new RhPayrollWorkflowService($this->pdo);
            $preflight=$payroll->preflight($period);
            if ($preflight['blocking_count']>0) throw new DomainException('Corrigez les anomalies bloquantes de paie avant d’arrêter le point.');
            $slips=$payroll->slips($period,$period); $lines=[];
            foreach ($slips as $slip) {
                $q=$this->pdo->prepare("SELECT deferred_until FROM rh_financial_source_deferrals WHERE source_kind='salary' AND source_id=? AND source_period=?");$q->execute([$slip['personnel_id'],$period]);
                if(($q->fetchColumn() ?: '')>date('Y-m-d'))continue;
                $q=$this->pdo->prepare("SELECT 1 FROM rh_financial_source_claims WHERE source_kind='salary' AND source_id=? AND source_period=?");
                $q->execute([$slip['personnel_id'],$period]); if ($q->fetchColumn()) continue;
                if (self::money($slip['net_pay'])===0) continue;
                $q=$this->pdo->prepare('SELECT id FROM user_devis_banamur WHERE mail_pro=? AND active=1 ORDER BY id LIMIT 1'); $q->execute([$slip['employee_email']]);
                $lines[]=$this->line('salary',(int)$slip['personnel_id'],$period,(int)$q->fetchColumn(),(int)$slip['personnel_id'],$slip['employee_name'],'Salaire '.$period,self::money($slip['net_pay']),['slip_id'=>(int)$slip['id'],'payload'=>$slip['payload'],'bank_account_number'=>$slip['bank_account_number'],'bank_name'=>$slip['bank_name']]);
            }
            return $lines;
        }
        $type=RhFinancialPointPolicy::REQUEST_TYPES[$kind];
        $q=$this->pdo->prepare("SELECT r.* FROM employee_legal_requests r LEFT JOIN rh_financial_source_claims c ON c.source_kind=? AND c.source_id=r.id AND c.source_period='' WHERE r.request_type=? AND r.status='submitted' AND r.submitted_at>=? AND c.point_id IS NULL ORDER BY r.submitted_at,r.id");
        $q->execute([$kind,$type,$cutoff]); $lines=[];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $payload=json_decode($r['payload_json'],true,512,JSON_THROW_ON_ERROR);
            if (($payload['rh_deferred_until'] ?? '')>date('Y-m-d')) continue;
            $deferred=$this->pdo->prepare('SELECT deferred_until FROM rh_financial_source_deferrals WHERE source_kind=? AND source_id=? AND source_period=\'\'');$deferred->execute([$kind,$r['id']]);
            if(($deferred->fetchColumn() ?: '')>date('Y-m-d'))continue;
            $amount=self::money($kind==='loan' ? ($payload['montant_demande'] ?? 0) : ($payload['montant'] ?? 0));
            if ($amount===0) continue;
            $lines[]=$this->line($kind,(int)$r['id'],'',(int)$r['requester_user_id'],(int)($payload['personnel_id'] ?? 0),$r['requester_name'],$r['title'],$amount,['payload'=>$payload,'submitted_at'=>$r['submitted_at']]);
        }
        return $lines;
    }

    public function create(string $kind, string $period, array $ids, int $userId, array $budget): int
    {
        $user=$this->actor($userId,'rh'); $this->assertKind($kind);
        $settings=$this->settings(); if (empty($settings[$kind.'_active_at'])) throw new DomainException('Activez d’abord le modèle WhatsApp de ce point.');
        $ids=array_values(array_unique(array_map('intval',$ids))); if (!$ids) throw new DomainException('Sélectionnez les lignes du point.');
        $candidates=$this->candidates($kind,$period);
        $lines=array_values(array_filter($candidates,static fn(array $line):bool=>in_array($line['source_id'],$ids,true)));
        if (count($lines)!==count($ids)) throw new DomainException('Une demande sélectionnée a changé ou figure déjà dans un point.');
        $q=$this->pdo->prepare('SELECT id_personnel_soignant,nom_personnel_soignant,tel_personnel_soignant FROM personnel WHERE email_personnel_soignant=? ORDER BY id_personnel_soignant LIMIT 1');
        $q->execute([$user['email']]); $person=$q->fetch(PDO::FETCH_ASSOC);
        if (!$person) throw new DomainException('Le porteur RH doit être rattaché à un dossier personnel par son adresse e-mail.');
        foreach($lines as &$line) {
            if($line['user_id']<=0) throw new DomainException('Compte utilisateur manquant pour '.$line['name'].'.');
            $q=$this->pdo->prepare('SELECT COUNT(*) FROM user_devis_banamur WHERE id=? AND active=1');$q->execute([$line['user_id']]);
            if((int)$q->fetchColumn()!==1)throw new DomainException('Compte actif requis pour '.$line['name'].'.');
            if($kind!=='salary') {
                $matricule=trim((string)($line['source']['payload']['matricule'] ?? ''));
                $q=$this->pdo->prepare('SELECT id_personnel_soignant FROM personnel WHERE matricule_personnel_soignant=? LIMIT 1');$q->execute([$matricule]);$personnelId=(int)$q->fetchColumn();
                if(!$personnelId)throw new DomainException('Matricule RH manquant pour '.$line['name'].'.');
                $line['personnel_id']=$personnelId;
            }
        }
        unset($line);
        $budget=$this->budget($budget);
        return $this->transaction(function() use($kind,$period,$lines,$userId,$user,$settings,$person,$budget):int {
            $q=$this->pdo->prepare('INSERT INTO rh_financial_points(kind,period,stopped_by,stopped_name,stopped_email,stopped_personnel_id,stopped_phone,dg_user_id) VALUES(?,?,?,?,?,?,?,?)');
            $q->execute([$kind,$kind==='salary'?$period:null,$userId,$person['nom_personnel_soignant'],$user['email'],$person['id_personnel_soignant'],$person['tel_personnel_soignant'],$settings['dg_user_id']]);
            $id=(int)$this->pdo->lastInsertId();
            foreach($lines as $line) {
                if ($kind!=='salary') {
                    $q=$this->pdo->prepare('SELECT status,payload_json FROM employee_legal_requests WHERE id=? FOR UPDATE'); $q->execute([$line['source_id']]);$source=$q->fetch(PDO::FETCH_ASSOC);
                    if (!$source || $source['status']!=='submitted' || json_decode($source['payload_json'],true)!==$line['source']['payload']) throw new DomainException('Une demande a changé pendant l’arrêt du point.');
                }
                $this->claim($line,$id);
            }
            $this->pdo->prepare('INSERT INTO rh_financial_point_versions(point_id,revision,snapshot_json) VALUES(?,1,?)')->execute([$id,self::json(['budget'=>$budget,'lines'=>$lines])]);
            $this->event($id,1,'created',$userId); return $id;
        });
    }

    public function get(int $id, bool $lock=false): array
    {
        $q=$this->pdo->prepare('SELECT p.*,v.snapshot_json,v.snapshot_hash,v.pdf_path,v.dg_name,v.whatsapp_status,v.token_hash,v.token_expires_at,v.token_used_at FROM rh_financial_points p JOIN rh_financial_point_versions v ON v.point_id=p.id AND v.revision=p.revision WHERE p.id=?'.($lock?' FOR UPDATE':''));
        $q->execute([$id]); $p=$q->fetch(PDO::FETCH_ASSOC);
        if (!$p) throw new DomainException('Point introuvable.'); $p['snapshot']=json_decode($p['snapshot_json'],true,512,JSON_THROW_ON_ERROR);return $p;
    }

    public function list(): array
    {
        return $this->pdo->query('SELECT * FROM rh_financial_points ORDER BY id DESC LIMIT 200')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function stop(int $id, int $revision, int $userId, string $method = 'passkey', string $manualSignature = ''): void
    {
        $this->actor($userId,'rh');
        if (!in_array($method, ['passkey', 'manual'], true)) throw new DomainException('Mode de signature inconnu.');
        $signature = $method === 'manual' ? RhFinancialSignature::manual($manualSignature) : RhFinancialSignature::profile($this->pdo,$userId);
        $this->transaction(function() use($id,$revision,$userId,$method,$signature):void {
            $p=$this->get($id,true);$this->assertVersion($p,$revision,'draft');
            if ((int)$p['stopped_by']!==$userId) throw new DomainException('La personne ayant arrêté le point doit le signer.');
            if ($method === 'passkey') PasskeySignatureGuard::requireVerified($userId,'rh_financial:'.$id.':'.$revision.':rh');
            $snapshot = $p['snapshot'];
            $snapshot['rh_signature'] = $signature + ['user_id'=>$userId, 'revision'=>$revision, 'signed_at'=>date('Y-m-d H:i:s')];
            $encoded = self::json($snapshot); $hash = hash('sha256', $encoded);
            $this->pdo->prepare("UPDATE rh_financial_points SET status='rh_signed',rh_signed_at=NOW() WHERE id=?")->execute([$id]);
            $this->pdo->prepare('UPDATE rh_financial_point_versions SET rh_signed_at=NOW(),snapshot_json=?,snapshot_hash=? WHERE point_id=? AND revision=?')->execute([$encoded,$hash,$id,$revision]);
            $this->event($id,$revision,'rh_signed',$userId,['snapshot_hash'=>$hash,'signature_method'=>$method,'signature_hash'=>$signature['image_hash'] ?? null]);
        });
    }

    public function notify(int $id, int $revision, int $userId, bool $resend = false): void
    {
        $this->actor($userId,'rh');
        require_once __DIR__.'/DailyPointExternalSignatureService.php'; new DailyPointExternalSignatureService($this->pdo);
        $settings=$this->settings(); $credentials=(new TwilioWhatsAppConfigService($this->pdo))->credentials();
        require_once __DIR__.'/RhFinancialWhatsAppTemplateService.php';
        $p=$this->get($id); $this->assertVersion($p,$revision,'rh_signed');
        (new RhFinancialWhatsAppTemplateService($credentials['sid'],$credentials['token']))->assertKindApproved($p['kind'],$settings[$p['kind'].'_sid']);
        $token=bin2hex(random_bytes(32)); $expires=date('Y-m-d H:i:s',time()+86400);
        $this->transaction(function() use($id,$revision,$token,$expires,$userId,$resend):void {
            $p=$this->get($id,true);$this->assertVersion($p,$revision,'rh_signed');
            if ($p['whatsapp_status']==='sending') throw new DomainException('Un envoi est déjà en cours. Vérifiez son résultat avant de renvoyer.');
            if (!$resend && in_array($p['whatsapp_status'],['sent','uncertain'],true)) throw new DomainException('Une invitation existe déjà. Utilisez « Renvoyer le lien au DG » pour la remplacer.');
            $this->pdo->prepare("UPDATE rh_financial_point_versions SET token_hash=?,token_expires_at=?,token_used_at=NULL,whatsapp_status='sending' WHERE point_id=? AND revision=?")->execute([hash('sha256',$token),$expires,$id,$revision]);
            $this->event($id,$revision,$resend?'whatsapp_resending':'whatsapp_sending',$userId);
        });
        $channel=new NotificationWhatsAppChannelService($credentials['sid'],$credentials['token'],$credentials['from']);
        $result=$channel->sendTemplateDetailed($settings['dg_phone'],$settings[$p['kind'].'_sid'],['1'=>'Directeur Général','2'=>(string)$id.' / v'.$revision,'3'=>date('d/m/Y',strtotime($p['rh_signed_at'])),'4'=>(string)count($p['snapshot']['lines']),'5'=>number_format(RhFinancialPointPolicy::total($p['snapshot']['lines']),0,',',' ').' FCFA','6'=>date('d/m/Y H:i',strtotime($expires)),'7'=>$token]);
        $status=$result['ok']?'sent':'uncertain';
        $this->pdo->prepare("UPDATE rh_financial_point_versions SET whatsapp_status=?,message_sid=? WHERE point_id=? AND revision=? AND token_hash=? AND whatsapp_status='sending'")->execute([$status,$result['message_sid'] ?: null,$id,$revision,hash('sha256',$token)]);
        $this->event($id,$revision,'whatsapp_'.$status,$userId,['resend'=>$resend,'message_sid'=>$result['message_sid'] ?: null,'provider_status'=>$result['status'] ?? null,'error_code'=>$result['error_code'] ?? null]);
        if (!$result['ok']) throw new RuntimeException('Envoi non confirmé. Vérifiez Twilio avant une nouvelle invitation.');
    }

    /** Read-only administrator preview; never consumes an invitation or a signature. */
    public function previewInvitation(int $userId, int $pointId = 0, string $token = ''): array
    {
        $this->actor($userId,'admin');
        $version=null;
        if ($pointId<=0) {
            if (!preg_match('/^[a-f0-9]{64}$/D',$token)) throw new DomainException('Lien de test invalide.');
            $q=$this->pdo->prepare('SELECT * FROM rh_financial_point_versions WHERE token_hash=?');
            $q->execute([hash('sha256',$token)]);$version=$q->fetch(PDO::FETCH_ASSOC);
            if (!$version) throw new DomainException('Cette invitation a été remplacée. Utilisez le test administrateur depuis le détail du point.');
            $pointId=(int)$version['point_id'];
        }
        $point=$this->get($pointId);
        if ($version) {
            $point=array_replace($point,$version);
            $point['snapshot']=json_decode($version['snapshot_json'],true,512,JSON_THROW_ON_ERROR);
            $point['status']=!empty($version['dg_signed_at'])?'dg_signed':(!empty($version['rh_signed_at'])?'rh_signed':'draft');
        }
        return $point;
    }

    public function fromToken(string $token, int $userId): array
    {
        $this->actor($userId,'dg');
        if (!preg_match('/^[a-f0-9]{64}$/D',$token)) throw new DomainException('Lien invalide.');
        $q=$this->pdo->prepare("SELECT p.id FROM rh_financial_points p JOIN rh_financial_point_versions v ON v.point_id=p.id AND v.revision=p.revision WHERE v.token_hash=? AND v.token_expires_at>NOW() AND v.token_used_at IS NULL AND p.status='rh_signed' AND p.dg_user_id=?");
        $q->execute([hash('sha256',$token),$userId]); $id=(int)$q->fetchColumn();
        if (!$id) throw new DomainException('Lien expiré, remplacé ou déjà signé.'); return $this->get($id);
    }

    public function sign(int $id, int $revision, int $userId, array $changes, string $token, string $method = 'passkey', string $manualSignature = ''): int
    {
        $user=$this->actor($userId,'dg'); $this->fromToken($token,$userId);
        if (!in_array($method, ['passkey', 'manual'], true)) throw new DomainException('Mode de signature inconnu.');
        $signature = $method === 'manual' ? RhFinancialSignature::manual($manualSignature) : RhFinancialSignature::profile($this->pdo,$userId);
        $pdfPath='';
        try { return $this->transaction(function() use($id,$revision,$userId,$changes,$token,$user,$method,$signature,&$pdfPath):int {
            $p=$this->get($id,true);$this->assertVersion($p,$revision,'rh_signed');
            if ((int)$p['dg_user_id']!==$userId || !hash_equals((string)$p['token_hash'],hash('sha256',$token)) || $p['token_used_at'] || $p['token_expires_at']<=date('Y-m-d H:i:s')) throw new DomainException('Invitation remplacée ou expirée.');
            if ($method === 'passkey') PasskeySignatureGuard::requireVerified($userId,'rh_financial:'.$id.':'.$revision.':dg');
            $snapshot=$p['snapshot']; $snapshot['lines']=RhFinancialPointPolicy::decisions($snapshot['lines'],$changes);
            $snapshot['dg_signature'] = $signature + ['user_id'=>$userId, 'revision'=>$revision, 'signed_at'=>date('Y-m-d H:i:s')];
            $snapshot['budget']=$this->budget($snapshot['budget']);
            $encoded=self::json($snapshot);$p['snapshot_hash']=hash('sha256',$encoded);$p['dg_signed_at']=date('Y-m-d H:i:s');
            $q=$this->pdo->prepare('SELECT nom_personnel_soignant FROM personnel WHERE email_personnel_soignant=? LIMIT 1');$q->execute([$user['email']]); $p['dg_name']=$q->fetchColumn() ?: 'Directeur Général';
            $pdfPath=(new RhFinancialPointPdfBuilder())->store($p,$snapshot);
            $feb=RhFinancialPointPolicy::total($snapshot['lines'])>0 ? $this->createFeb($p,$snapshot,$pdfPath) : 0;
            foreach($snapshot['lines'] as $line) if($line['decision']==='deferred') {
                $this->pdo->prepare('INSERT INTO rh_financial_source_deferrals(source_kind,source_id,source_period,deferred_until) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE deferred_until=VALUES(deferred_until)')->execute([$line['source_kind'],$line['source_id'],$line['source_period'],$line['deferred_until']]);
                $this->releaseClaim($line,$id);
            }
            foreach($snapshot['lines'] as $line) if ($line['decision']!=='approved' && $p['kind']!=='salary') {
                $payload=$line['source']['payload'];
                $payload['rh_financial_decision']=['point_id'=>$id,'revision'=>$revision,'decision'=>$line['decision'],'amount'=>$line['amount'],'note'=>$line['note']];
                if ($line['decision']==='deferred') $payload['rh_deferred_until']=$line['deferred_until'];
                $status=match($line['decision']){'rejected'=>'rejected','cancelled'=>'cancelled',default=>'submitted'};
                $this->pdo->prepare('UPDATE employee_legal_requests SET status=?,payload_json=?,updated_at=NOW() WHERE id=?')->execute([$status,self::json($payload),$line['source_id']]);
                if ($line['decision']==='deferred') $this->releaseClaim($line,$id);
            }
            $this->pdo->prepare("UPDATE rh_financial_points SET status='dg_signed',dg_signed_at=?,feb_request_id=? WHERE id=?")->execute([$p['dg_signed_at'],$feb ?: null,$id]);
            $this->pdo->prepare('UPDATE rh_financial_point_versions SET snapshot_json=?,snapshot_hash=?,dg_signed_at=?,dg_name=?,pdf_path=?,feb_request_id=?,token_used_at=NOW() WHERE point_id=? AND revision=?')->execute([$encoded,$p['snapshot_hash'],$p['dg_signed_at'],$p['dg_name'],$pdfPath,$feb ?: null,$id,$revision]);
            $this->event($id,$revision,'dg_signed',$userId,['hash'=>$p['snapshot_hash'],'feb_id'=>$feb,'signature_method'=>$method,'signature_hash'=>$signature['image_hash'] ?? null]); return $feb;
        }); } catch(Throwable $e) { if ($pdfPath && is_file(__DIR__.'/../'.$pdfPath)) unlink(__DIR__.'/../'.$pdfPath); throw $e; }
    }

    public function discard(int $id,int $revision,int $userId):void
    {
        $this->actor($userId,'rh');
        $this->transaction(function()use($id,$revision,$userId):void{
            $p=$this->get($id,true);$this->assertVersion($p,$revision,'draft');
            foreach($p['snapshot']['lines'] as $line)$this->releaseClaim($line,$id);
            $this->pdo->prepare("UPDATE rh_financial_points SET status='cancelled' WHERE id=?")->execute([$id]);
            $this->event($id,$revision,'draft_cancelled',$userId);
        });
    }

    public function reopen(int $id, int $revision, int $userId, string $reason): void
    {
        $this->actor($userId,'rh'); if (trim($reason)==='') throw new DomainException('Indiquez le motif de réouverture.');
        $this->transaction(function() use($id,$revision,$userId,$reason):void {
            $p=$this->get($id,true); if ((int)$p['revision']!==$revision || in_array($p['status'],['draft','cancelled'],true)) throw new DomainException('Cette version a changé ou attend déjà la signature RH.');
            if ($p['feb_request_id']) {
                $q=$this->pdo->prepare('SELECT id FROM employee_legal_requests WHERE id=? FOR UPDATE');$q->execute([$p['feb_request_id']]);$q->fetchColumn();
                $q=$this->pdo->prepare('SELECT num_fiche,decaisse FROM fiche_decaissement WHERE feb_request_id=? FOR UPDATE');$q->execute([$p['feb_request_id']]);$fiches=$q->fetchAll(PDO::FETCH_ASSOC);
                foreach($fiches as $f) if ((int)$f['decaisse']!==0) throw new DomainException('Un décaissement a eu lieu : la réouverture est interdite.');
                $this->pdo->prepare('UPDATE fiche_decaissement SET approuve=2,conforme=0,etat_fiche=2 WHERE feb_request_id=? AND decaisse=0')->execute([$p['feb_request_id']]);
                $this->pdo->prepare("UPDATE employee_legal_requests SET status='cancelled',updated_at=NOW() WHERE id=?")->execute([$p['feb_request_id']]);
            }
            foreach($p['snapshot']['lines'] as $line) {
                $this->claim($line,$id,true);
                if ($p['kind']!=='salary') $this->pdo->prepare("UPDATE employee_legal_requests SET status='submitted',payload_json=?,updated_at=NOW() WHERE id=?")->execute([self::json($line['source']['payload']),$line['source_id']]);
            }
            $next=$revision+1;
            unset($p['snapshot']['rh_signature'], $p['snapshot']['dg_signature']);
            $this->pdo->prepare("UPDATE rh_financial_point_versions SET token_used_at=COALESCE(token_used_at,NOW()) WHERE point_id=? AND revision=?")->execute([$id,$revision]);
            $this->pdo->prepare('INSERT INTO rh_financial_point_versions(point_id,revision,snapshot_json) VALUES(?,?,?)')->execute([$id,$next,self::json($p['snapshot'])]);
            // The original RH signer remains the requester/beneficiary and signs the new version.
            $this->pdo->prepare("UPDATE rh_financial_points SET revision=?,status='draft',rh_signed_at=NULL,dg_signed_at=NULL,feb_request_id=NULL WHERE id=?")->execute([$next,$id]);
            $this->event($id,$next,'reopened',$userId,['reason'=>mb_substr($reason,0,1000),'replaces'=>$revision]);
        });
    }

    public function assertSignedFeb(array $request, bool $lock=false): array
    {
        $payload=$request['payload'] ?? json_decode($request['payload_json'] ?? '{}',true);
        $id=(int)($payload['rh_financial_point_id'] ?? 0);
        $p=$this->get($id,$lock);
        if (!hash_equals((string)$p['snapshot_hash'],hash('sha256',$p['snapshot_json'])) || $p['status']!=='dg_signed' || (int)$p['revision']!==(int)($payload['rh_financial_revision'] ?? 0) || (int)$p['feb_request_id']!==(int)$request['id']
            || !hash_equals((string)$p['snapshot_hash'],(string)($payload['rh_financial_snapshot_hash'] ?? ''))
            || (float)($payload['montant'] ?? -1)!== (float)RhFinancialPointPolicy::total($p['snapshot']['lines'])) throw new DomainException('Cette FEB ne correspond plus au point RH signé.');
        $b=$p['snapshot']['budget'];$draft=$payload['provisional_decaissement'] ?? [];
        if((int)($payload['chantier_id'] ?? 0)!==$b['chantier_id'] || (int)($payload['departement_id'] ?? 0)!==$b['service_id']
            || (int)($draft['personnel_id'] ?? 0)!==(int)$p['stopped_personnel_id'] || (int)($draft['ligne_debourse_id'] ?? 0)!==$b['ligne_debourse_id']
            || ($draft['mode_paiement'] ?? '')!==$b['mode_paiement'] || ($draft['date_decaissement_minimum'] ?? '')!==$b['date']
            || ($draft['details_demande'] ?? '')!==RhFinancialPointPolicy::details($request['title'],$p['snapshot']['lines'])
            || ($payload['justificatifs'][0]['original_path'] ?? $payload['justificatifs'][0]['path'] ?? '')!==$p['pdf_path']) throw new DomainException('Le contenu de cette FEB diffère du récapitulatif signé.');
        if (isset($payload['rh_financial_documents_version']) && ($payload['besoins'] ?? [])!==self::febNeeds($p['snapshot'],$payload['justificatifs'][0] ?? [])) throw new DomainException('Les lignes FEB diffèrent des demandes RH autorisées.');
        return $p;
    }

    public function createFiche(array $request, array $actor): string
    {
        $p=$this->assertSignedFeb($request,true);$snapshot=$p['snapshot'];$budget=$this->budget($snapshot['budget']);
        $q=$this->pdo->prepare('SELECT num_fiche FROM fiche_decaissement WHERE feb_request_id=? LIMIT 1');$q->execute([$request['id']]);$existing=$q->fetchColumn();if($existing)return $existing;
        $num='FD-RH-'.date('ymd').'-'.strtoupper(bin2hex(random_bytes(4)));
        $payload=$request['payload'];$code=$payload['code_autorisation_feb'] ?? null;
        $q=$this->pdo->prepare('INSERT INTO fiche_decaissement(parent_num_fiche,code_parentale,num_fiche,beficiaire_fiche,tel_beneficiaire_fiche,personnel_id,montant_fiche,montant_initial_fiche,chantier_id,ligne_debourse_id,designation_fiche,precision_fiche,details_demande,mode_paiement,created_by_user_id,feb_request_id,code_autorisation_feb,conforme,approuve,decaisse,etat_fiche,date_creat_fiche,planned_start_date,planned_end_date,date_decaissement_minimum) VALUES(\'\',\'\',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,0,0,0,NOW(),?,?,?)');
        $q->execute([$num,$p['stopped_name'],(string)$p['stopped_phone'],$p['stopped_personnel_id'],RhFinancialPointPolicy::total($snapshot['lines']),RhFinancialPointPolicy::total($snapshot['lines']),$budget['chantier_id'],$budget['ligne_debourse_id'],RhFinancialPointPolicy::KINDS[$p['kind']],$p['period'],RhFinancialPointPolicy::details($request['title'],$snapshot['lines']),$budget['mode_paiement'],$p['stopped_by'],$request['id'],$code,$budget['date'],$budget['date'],$budget['date']]);
        return $num;
    }

    public static function febDocument(array $p, string $path): array
    {
        return ['name'=>'Point RH #'.(int)$p['id'].' v'.(int)$p['revision'].' signé RH et DG.pdf','path'=>$path,'original_path'=>!empty($p['pdf_path'])?$p['pdf_path']:$path,
            'url'=>'/request/download_rh_financial_point.php?point_id='.(int)$p['id'].'&revision='.(int)$p['revision'],'mime'=>'application/pdf'];
    }

    /** One FEB need for each authorized source, with its final DG amount. */
    public static function febNeeds(array $snapshot, array $document): array
    {
        $needs=[];
        foreach ($snapshot['lines'] as $line) {
            if ($line['decision']!=='approved') continue;
            $amount=RhFinancialPointPolicy::amount($line['amount']);
            if ($amount<=0) throw new DomainException('Montant de ligne RH autorisée invalide.');
            $needs[]=['libelle'=>$line['name'].' — '.$line['label'],'type'=>'Demande RH','categorie'=>'RH','quantite'=>1,
                'has_fournisseur'=>false,'fournisseur'=>['nom'=>'','prix_unitaire'=>$amount],
                'rh_source_key'=>$line['key'],'rh_source_id'=>(int)$line['source_id'],'rh_source_kind'=>$line['source_kind'],
                'rh_beneficiary'=>$line['name'],'justificatif_devis'=>$document];
        }
        return $needs;
    }

    /** Separately audited display supplement; never changes the signed snapshot. */
    public function supplementalSignatures(int $id,int $revision): array
    {
        $q=$this->pdo->prepare("SELECT detail_json FROM rh_financial_events WHERE point_id=? AND revision=? AND event_type='profile_signatures_backfilled' ORDER BY id DESC LIMIT 1");
        $q->execute([$id,$revision]);$json=$q->fetchColumn();
        return $json?(json_decode($json,true,512,JSON_THROW_ON_ERROR)['signatures'] ?? []):[];
    }

    /** Explicit administrative repair, also safe for already approved/paid FEBs: no financial mutations. */
    public function repairFebDocuments(int $id,int $actorId,bool $apply=false): array
    {
        $this->actor($actorId,'admin');$newPdf='';
        $repair=function() use($id,$actorId,$apply,&$newPdf):array {
            $p=$this->get($id,$apply);
            if ($p['status']!=='dg_signed' || empty($p['feb_request_id'])) return ['point'=>$id,'status'=>'skipped'];
            $q=$this->pdo->prepare('SELECT * FROM employee_legal_requests WHERE id=?'.($apply?' FOR UPDATE':''));
            $q->execute([$p['feb_request_id']]);$request=$q->fetch(PDO::FETCH_ASSOC);
            if (!$request || $request['request_type']!=='feb') throw new DomainException('FEB rattachée introuvable.');
            $payload=json_decode($request['payload_json'],true,512,JSON_THROW_ON_ERROR);$request['payload']=$payload;
            $this->assertSignedFeb($request,$apply);
            $original=realpath(__DIR__.'/../'.$p['pdf_path']);$root=realpath(__DIR__.'/../uploads/rh_financial_points');
            if (!$original || !$root || !str_starts_with($original,$root.DIRECTORY_SEPARATOR) || !is_file($original) || !is_readable($original)) throw new DomainException('Original signé absent : restauration nécessaire avant rattrapage.');
            $supplements=$this->supplementalSignatures($id,(int)$p['revision']);$before=$supplements;$missing=[];
            foreach (['rh_signature'=>(int)$p['stopped_by'],'dg_signature'=>(int)$p['dg_user_id']] as $key=>$userId) {
                $signature=$p['snapshot'][$key] ?? ['method'=>'passkey'];
                if (($signature['method'] ?? 'passkey')!=='passkey' || !empty($signature['image_data']) || isset($supplements[$key])) continue;
                $captured=RhFinancialSignature::profile($this->pdo,(int)($signature['user_id'] ?? $userId));
                if (empty($captured['image_data'])) {$missing[]=$key;continue;}
                $supplements[$key]=$captured+['user_id'=>(int)($signature['user_id'] ?? $userId),'backfilled_at'=>date('Y-m-d H:i:s')];
            }
            $ready=(int)($payload['rh_financial_documents_version'] ?? 0)===1 && $supplements===$before;
            $oldPath=(string)($payload['justificatifs'][0]['path'] ?? '');
            if ($ready && is_file(__DIR__.'/../'.$oldPath)) return ['point'=>$id,'feb'=>(int)$request['id'],'status'=>'unchanged','missing_profiles'=>$missing];
            $report=['point'=>$id,'feb'=>(int)$request['id'],'status'=>$apply?'repaired':'planned','lines'=>count(self::febNeeds($p['snapshot'],[])),'amount'=>RhFinancialPointPolicy::total($p['snapshot']['lines']),'missing_profiles'=>$missing];
            if (!$apply) return $report;
            $p['supplemental_signatures']=$supplements;$p['presentation_copy']=true;
            $newPdf=(new RhFinancialPointPdfBuilder())->store($p,$p['snapshot']);$document=self::febDocument($p,$newPdf);
            $payload['rh_financial_documents_version']=1;$payload['justificatifs']=[$document];$payload['global_justificatif']=$document;$payload['besoins']=self::febNeeds($p['snapshot'],$document);
            if ($supplements!==$before) $this->event($id,(int)$p['revision'],'profile_signatures_backfilled',$actorId,['signatures'=>$supplements]);
            $this->event($id,(int)$p['revision'],'feb_documents_repaired',$actorId,['feb_id'=>(int)$request['id'],'previous_payload'=>$request['payload'],'original_pdf'=>$p['pdf_path'],'copy_pdf'=>$newPdf,'snapshot_hash'=>$p['snapshot_hash']]);
            $this->pdo->prepare('UPDATE employee_legal_requests SET payload_json=?,updated_at=NOW() WHERE id=?')->execute([self::json($payload),$request['id']]);
            return $report;
        };
        try {return $apply?$this->transaction($repair):$repair();}
        catch(Throwable $e){if($newPdf!=='')@unlink(__DIR__.'/../'.$newPdf);throw $e;}
    }

    private function createFeb(array $p,array $snapshot,string $pdf):int
    {
        $budget=$snapshot['budget'];$total=RhFinancialPointPolicy::total($snapshot['lines']);$title='Point RH · '.RhFinancialPointPolicy::KINDS[$p['kind']].($p['period']?' '.$p['period']:'').' #'.$p['id'].' v'.$p['revision'];
        $document=self::febDocument($p,$pdf);
        $payload=['rh_financial_point_id'=>(int)$p['id'],'rh_financial_revision'=>(int)$p['revision'],'rh_financial_snapshot_hash'=>$p['snapshot_hash'],
            'departement_id'=>$budget['service_id'],'chantier_id'=>$budget['chantier_id'],'budget_planned'=>'yes','budget_planned_label'=>'Prévue au déboursé','montant'=>$total,'categorie'=>'RH',
            'planned_start_date'=>$budget['date'],'planned_end_date'=>$budget['date'],'description'=>RhFinancialPointPolicy::details($title,$snapshot['lines']),
            'personnel_id'=>(int)$p['stopped_personnel_id'],'demandeur'=>$p['stopped_name'],'beneficiary_choice'=>'requester',
            'rh_financial_documents_version'=>1,'justificatifs'=>[$document],'global_justificatif'=>$document,'besoins'=>self::febNeeds($snapshot,$document),
            'provisional_decaissement'=>['active'=>true,'beneficiary_mode'=>'personnel','personnel_id'=>(int)$p['stopped_personnel_id'],'beneficiaire_fiche'=>$p['stopped_name'],
                'tel_beneficiaire_fiche'=>$p['stopped_phone'],'chantier_id'=>$budget['chantier_id'],'debourse_id'=>$budget['debourse_id'],'ligne_debourse_id'=>$budget['ligne_debourse_id'],
                'montant_fiche'=>$total,'details_demande'=>RhFinancialPointPolicy::details($title,$snapshot['lines']),'mode_paiement'=>$budget['mode_paiement'],'date_decaissement_minimum'=>$budget['date']]];
        $q=$this->pdo->prepare("INSERT INTO employee_legal_requests(requester_user_id,requester_name,requester_email,request_type,assigned_team,title,payload_json,meta_service_id,status,submitted_at) VALUES(?,?,?,'feb','finance',?,?,?,'submitted',NOW())");
        $q->execute([$p['stopped_by'],$p['stopped_name'],$p['stopped_email'],$title,self::json($payload),$budget['service_id']]);return (int)$this->pdo->lastInsertId();
    }

    private function budget(array $budget):array
    {
        $line=(int)($budget['ligne_debourse_id'] ?? 0);$q=$this->pdo->prepare('SELECT l.id,l.debourse_id,c.id chantier_id FROM ligne_debourse_banamur l JOIN debourse_banamur d ON d.id=l.debourse_id JOIN chantier_banamur c ON (c.devis_id=d.devis_id OR EXISTS(SELECT 1 FROM devis_chantier_links cl WHERE cl.devis_id=d.devis_id AND cl.chantier_id=c.id)) WHERE l.id=? AND c.id=? AND c.archived_at IS NULL');
        $q->execute([$line,(int)($budget['chantier_id'] ?? 0)]);$row=$q->fetch(PDO::FETCH_ASSOC);if(!$row)throw new DomainException('Sélectionnez une ligne de déboursé d’un chantier actif.');
        $date=(string)($budget['date'] ?? date('Y-m-d'));$parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        if(!$parsed||$parsed->format('Y-m-d')!==$date)throw new DomainException('Date de décaissement invalide.');
        $service=(int)($budget['service_id'] ?? 0);
        $q=$this->pdo->prepare('SELECT 1 FROM service WHERE id_service=?');$q->execute([$service]);
        if(!$q->fetchColumn())throw new DomainException('Sélectionnez le service RH.');
        require_once __DIR__.'/FinancialPlanningSeparationService.php';
        $q=$this->pdo->prepare('SELECT 1 FROM debourse_banamur d WHERE d.id=? AND '.FinancialPlanningSeparationService::officialDeboursePredicate('d'));$q->execute([$row['debourse_id']]);
        if(!$q->fetchColumn())throw new DomainException('Sélectionnez un déboursé financier officiel.');
        require_once __DIR__.'/ChantierPauseService.php';
        if(ChantierPauseService::storageExists($this->pdo)){
            $q=$this->pdo->prepare('SELECT 1 FROM finea_chantier_pauses WHERE chantier_id=? AND resumed_at IS NULL AND (resume_at IS NULL OR resume_at>NOW())');$q->execute([$row['chantier_id']]);
            if($q->fetchColumn())throw new DomainException('Le chantier choisi est en pause.');
        }
        $mode=trim((string)($budget['mode_paiement'] ?? 'Espèces'));if(!in_array($mode,['Espèces','Virement','Chèque','Wave','Orange Money','MTN MoMo','Moov Money'],true))throw new DomainException('Mode de paiement invalide.');
        return ['ligne_debourse_id'=>$line,'debourse_id'=>(int)$row['debourse_id'],'chantier_id'=>(int)$row['chantier_id'],'service_id'=>$service,'date'=>$date,'mode_paiement'=>$mode];
    }
    private function line(string $kind,int $sourceId,string $period,int $userId,int $personnelId,string $name,string $label,int $amount,array $source):array
    {
        return ['key'=>$kind.'_'.$sourceId,'source_id'=>$sourceId,'source_kind'=>$kind,'source_period'=>$period,'user_id'=>$userId,'personnel_id'=>$personnelId,'name'=>$name,'label'=>$label,'initial_amount'=>$amount,'amount'=>$amount,'decision'=>'approved','deferred_until'=>'','note'=>'','source'=>$source];
    }
    private function claim(array $line,int $id,bool $allowOwn=false):void
    {
        $q=$this->pdo->prepare('SELECT point_id FROM rh_financial_source_claims WHERE source_kind=? AND source_id=? AND source_period=? FOR UPDATE');$q->execute([$line['source_kind'],$line['source_id'],$line['source_period']]);$owner=$q->fetchColumn();
        if($owner){if($allowOwn&&(int)$owner===$id)return;throw new DomainException('Cette ligne appartient déjà à un autre point.');}
        $this->pdo->prepare('INSERT INTO rh_financial_source_claims(source_kind,source_id,source_period,point_id) VALUES(?,?,?,?)')->execute([$line['source_kind'],$line['source_id'],$line['source_period'],$id]);
    }
    private function releaseClaim(array $line,int $id):void{$this->pdo->prepare('DELETE FROM rh_financial_source_claims WHERE source_kind=? AND source_id=? AND source_period=? AND point_id=?')->execute([$line['source_kind'],$line['source_id'],$line['source_period'],$id]);}
    private function assertKind(string $kind):void{if(!isset(RhFinancialPointPolicy::KINDS[$kind]))throw new DomainException('Type de point invalide.');}
    private function assertPeriod(string $period):void{if(!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D',$period))throw new DomainException('Mois de paie invalide.');}
    private function assertVersion(array $p,int $revision,string $status):void{if((int)$p['revision']!==$revision||$p['status']!==$status)throw new DomainException('La version ou l’étape a changé. Rechargez le point.');}
    private static function money(mixed $amount):int{return RhFinancialPointPolicy::amount($amount);}
    private static function json(array $value):string{return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
    private function event(int $id,int $revision,string $event,int $userId,array $detail=[]):void{$this->pdo->prepare('INSERT INTO rh_financial_events(point_id,revision,event_type,actor_id,detail_json) VALUES(?,?,?,?,?)')->execute([$id,$revision,$event,$userId,self::json($detail)]);}
    private function transaction(callable $callback):mixed
    {
        if($this->pdo->inTransaction())throw new LogicException('Transaction RH déjà ouverte.');$this->pdo->beginTransaction();
        try{$result=$callback();$this->pdo->commit();return $result;}catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
}




