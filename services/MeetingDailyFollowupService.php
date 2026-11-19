<?php
declare(strict_types=1);

final class MeetingDailyFollowupService
{
    private static bool $ready=false;
    public function __construct(private PDO $pdo){$this->ensureSchema();}

    public function ensureSchema():void
    {
        if(self::$ready)return;
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS planning_meeting_daily_followups (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,checklist_id INT NOT NULL,action_point_id INT NOT NULL,
            user_id INT NOT NULL,response_date DATE NOT NULL,response_type ENUM('completed','rescheduled','remind','decision_deferred') NOT NULL,
            execution_date DATE NULL,next_prompt_at DATETIME NULL,note VARCHAR(1000) NULL,role_label VARCHAR(20) NOT NULL,
            original_execution_date DATE NULL,previous_execution_date DATE NULL,reschedule_count INT NOT NULL DEFAULT 0,
            total_shift_days INT NOT NULL DEFAULT 0,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_meeting_daily_answer(checklist_id,user_id,response_date),
            INDEX idx_meeting_daily_user(user_id,response_date),INDEX idx_meeting_daily_prompt(user_id,next_prompt_at),
            INDEX idx_meeting_daily_checklist(checklist_id,created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        try{
            $indexes=$this->pdo->query("SHOW INDEX FROM planning_meeting_daily_followups WHERE Key_name='uq_meeting_daily_answer'")->fetchAll(PDO::FETCH_ASSOC)?:[];
            if($indexes){$this->pdo->exec('ALTER TABLE planning_meeting_daily_followups DROP INDEX uq_meeting_daily_answer, ADD INDEX idx_meeting_daily_answer(checklist_id,user_id,response_date)');}
        }catch(Throwable $e){}
        $this->pdo->exec("UPDATE planning_meeting_daily_followups SET next_prompt_at=CONCAT(execution_date,' 00:00:00') WHERE response_type='decision_deferred' AND execution_date IS NOT NULL AND (next_prompt_at IS NULL OR TIME(next_prompt_at)='07:00:00')");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS planning_meeting_followup_reminders (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,checklist_id INT NOT NULL,recipient_user_id INT NOT NULL,
            sent_by_user_id INT NOT NULL,note VARCHAR(500) NULL,sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_meeting_reminder_item(checklist_id,recipient_user_id,sent_at),INDEX idx_meeting_reminder_recipient(recipient_user_id,sent_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        self::$ready=true;
    }

    public function pendingForUser(int $userId,?DateTimeImmutable $now=null):array
    {
        if($userId<=0)return [];$now=$now?:new DateTimeImmutable('now');
        if((int)$now->format('N')>=5)return [];
        $nameStmt=$this->pdo->prepare("SELECT LOWER(TRIM(CONCAT(COALESCE(prenom,''),' ',COALESCE(nom,'')))) FROM user_devis_banamur WHERE id=:id LIMIT 1");$nameStmt->execute(['id'=>$userId]);$userName=(string)$nameStmt->fetchColumn();
        $sql="SELECT item.checklist_id,item.checklist_label,ap.id action_point_id,ap.task_title,ap.section_title,
            ap.pilot_user_id,ap.pilot_name,ap.copilot_user_id,ap.copilot_name,ap.old_deadline,ap.new_deadline,ap.preparation_id,ap.chantier_id,
            prep.id preparation_id,prep.meeting_date,prep.titre meeting_title,prep.office_ligne_devis_id,
            COALESCE(NULLIF(TRIM(service_task.titre),''),NULLIF(TRIM(line_task.titre),''),'Service Bureau') service_label,
            COALESCE(NULLIF(TRIM(ch.titre),''),CONCAT('Chantier #',ap.chantier_id)) chantier_label,
            COALESCE(NULLIF(TRIM(ch.code),''),'') chantier_code,
            latest.response_type latest_response,latest.next_prompt_at,latest.reschedule_count,latest.original_execution_date,
            latest.execution_date latest_execution_date,latest.total_shift_days
          FROM (SELECT cl.id checklist_id,cl.label checklist_label,cl.completed_at,cl.archived_at,cl.action_point_id
                FROM planning_chantier_meeting_action_point_checklist cl
                UNION ALL
                SELECT -ap0.id,'Suivi du point chantier',IF(ap0.status='done',ap0.updated_at,NULL),NULL,ap0.id
                FROM planning_chantier_meeting_action_points ap0 JOIN planning_chantier_meeting_preparations p0 ON p0.id=ap0.preparation_id
                WHERE p0.office_ligne_devis_id IS NULL AND NOT EXISTS(SELECT 1 FROM planning_chantier_meeting_action_point_checklist c0 WHERE c0.action_point_id=ap0.id AND c0.archived_at IS NULL)) item
          JOIN planning_chantier_meeting_action_points ap ON ap.id=item.action_point_id
          JOIN planning_chantier_meeting_preparations prep ON prep.id=ap.preparation_id
          LEFT JOIN planning_chantier_tasks service_task ON service_task.source_type='ligne_devis' AND service_task.source_id=prep.office_ligne_devis_id
          LEFT JOIN planning_chantier_tasks line_task ON line_task.source_type='ligne_devis' AND line_task.source_id=ap.ligne_devis_id
          LEFT JOIN chantier_banamur ch ON ch.id=ap.chantier_id
          LEFT JOIN planning_meeting_daily_followups latest ON latest.id=(SELECT f2.id FROM planning_meeting_daily_followups f2 WHERE f2.checklist_id=item.checklist_id AND f2.user_id=:latest_user ORDER BY f2.created_at DESC,f2.id DESC LIMIT 1)
          WHERE item.archived_at IS NULL AND item.completed_at IS NULL AND ap.archived_at IS NULL
            AND ap.status<>'cancelled' AND (prep.status='used' OR EXISTS(SELECT 1 FROM planning_chantier_meetings meeting_done WHERE meeting_done.preparation_id=prep.id AND meeting_done.status='closed'))
            AND (ap.pilot_user_id=:pilot OR LOWER(TRIM(ap.pilot_name))=:pilot_name)
            AND COALESCE(ap.new_deadline,ap.old_deadline) IS NOT NULL
            AND COALESCE(ap.new_deadline,ap.old_deadline)<=:week_end
            AND NOT EXISTS(SELECT 1 FROM planning_meeting_daily_followups today WHERE today.checklist_id=item.checklist_id AND today.user_id=:today_user AND today.response_date=:today
                AND (today.response_type<>'remind' OR today.next_prompt_at>:today_now))
            AND (latest.next_prompt_at IS NULL OR latest.next_prompt_at<=:now)
          ORDER BY prep.meeting_date DESC,prep.id DESC,ap.sort_order,item.checklist_id";
        $stmt=$this->pdo->prepare($sql);$stmt->execute(['latest_user'=>$userId,'pilot'=>$userId,'pilot_name'=>$userName,'week_end'=>$now->modify('friday this week')->format('Y-m-d'),'today_user'=>$userId,'today'=>$now->format('Y-m-d'),'today_now'=>$now->format('Y-m-d H:i:s'),'now'=>$now->format('Y-m-d H:i:s')]);
        $rows=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
        // A carried-forward checklist can exist in several meeting reports. Keep only its newest visible occurrence.
        $latestPrep=[];foreach($rows as $row){$scope=(int)$row['office_ligne_devis_id']>0?'office:'.(int)$row['office_ligne_devis_id']:'site:'.(int)$row['chantier_id'];if(!isset($latestPrep[$scope]))$latestPrep[$scope]=(int)$row['preparation_id'];}$seen=[];$result=[];
        foreach($rows as $row){
            $scope=(int)$row['office_ligne_devis_id']>0?'office:'.(int)$row['office_ligne_devis_id']:'site:'.(int)$row['chantier_id'];
            if((int)$row['preparation_id']!==$latestPrep[$scope])continue;
            $signature=$scope.'|'.mb_strtolower(trim((string)$row['section_title'])).'|'.mb_strtolower(trim((string)$row['task_title'])).'|'.mb_strtolower(trim((string)$row['checklist_label']));
            if(isset($seen[$signature]))continue;$seen[$signature]=true;
            $row['meeting_type']=(int)$row['office_ligne_devis_id']>0?'office':'site';
            $row['group_label']=$row['meeting_type']==='office'?(string)$row['service_label']:trim((string)$row['chantier_code'].' · '.(string)$row['chantier_label'],' ·');
            $row['role_label']='Pilote';
            $result[]=$row;
        }
        return $result;
    }

    public function submit(int $userId,array $answers,DateTimeImmutable $now,bool $partial=false):array
    {
        if((int)$now->format('N')>=5)throw new RuntimeException('Le suivi quotidien est disponible du lundi au jeudi.');
        $pending=$this->pendingForUser($userId,$now);$allowed=[];foreach($pending as $row)$allowed[(int)$row['checklist_id']]=$row;
        if(!$allowed)return ['saved'=>0,'remaining'=>0];
        if(!$answers)throw new RuntimeException('Renseignez au moins une sous-tâche avant d’enregistrer.');
        if(!$partial)foreach($allowed as $id=>$row){if(!isset($answers[$id])||!is_array($answers[$id]))throw new RuntimeException('Répondez à chaque sous-tâche avant de valider.');}
        $this->pdo->beginTransaction();$saved=0;
        try{
            foreach($allowed as $id=>$row){
                if(!isset($answers[$id])||!is_array($answers[$id]))continue;
                $answer=$answers[$id];$type=(string)($answer['type']??'');$note=mb_substr(trim((string)($answer['note']??'')),0,1000);
                if(!in_array($type,['rescheduled','remind','decision_deferred'],true))throw new RuntimeException('Le point quotidien ne peut pas clôturer un engagement : choisissez un report, un rappel ou un report de décision.');
                $date=null;$next=null;$today=$now->format('Y-m-d');$friday=$now->modify('friday this week')->format('Y-m-d');$thursday=$now->modify('thursday this week')->format('Y-m-d');
                if($type==='rescheduled'){$date=$this->date((string)($answer['date']??''));if($date<$today||$date>$friday)throw new RuntimeException('La nouvelle exécution doit rester entre aujourd’hui et vendredi.');}
                if($type==='remind'){$raw=trim((string)($answer['datetime']??''));$dt=DateTimeImmutable::createFromFormat('Y-m-d\TH:i',$raw);if(!$dt||$dt<=$now||$dt->format('Y-m-d')>$thursday)throw new RuntimeException('Le rappel doit être futur et fixé au plus tard jeudi.');$next=$dt->format('Y-m-d H:i:s');}
                if($type==='decision_deferred'){$date=$this->date((string)($answer['date']??''));if($date<=$today||$date>$thursday)throw new RuntimeException('La décision peut être reportée après aujourd’hui, au plus tard jeudi de la semaine en cours.');$next=$date.' 00:00:00';}
                $previous=trim((string)($row['latest_execution_date']??$row['new_deadline']??$row['old_deadline']??''))?:null;
                $original=trim((string)($row['original_execution_date']??$row['old_deadline']??$row['new_deadline']??$date??''))?:null;
                $count=(int)($row['reschedule_count']??0)+(in_array($type,['rescheduled','decision_deferred'],true)?1:0);$shift=($date&&$original)?(int)((new DateTimeImmutable($original))->diff(new DateTimeImmutable($date))->format('%r%a')):(int)($row['total_shift_days']??0);
                $insert=$this->pdo->prepare("INSERT INTO planning_meeting_daily_followups(checklist_id,action_point_id,user_id,response_date,response_type,execution_date,next_prompt_at,note,role_label,original_execution_date,previous_execution_date,reschedule_count,total_shift_days) VALUES(:checklist,:point,:user,:day,:type,:execution,:prompt,:note,:role,:original,:previous,:reports,:shift)");
                $insert->execute(['checklist'=>$id,'point'=>(int)$row['action_point_id'],'user'=>$userId,'day'=>$today,'type'=>$type,'execution'=>$date,'prompt'=>$next,'note'=>$note,'role'=>$row['role_label'],'original'=>$original,'previous'=>$previous,'reports'=>$count,'shift'=>$shift]);
                // Les réponses quotidiennes restent provisoires : aucun statut, avancement ou délai du point de réunion n’est modifié ici.
                $saved++;
            }
            $this->pdo->commit();return ['saved'=>$saved,'remaining'=>count($this->pendingForUser($userId,$now))];
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function trackingRows():array
    {
        $sql="SELECT assigned.user_id,assigned.role_label,item.checklist_id,item.checklist_label,item.completed_at,
            ap.id action_point_id,ap.chantier_id,ap.section_title,ap.task_title,ap.old_deadline,ap.new_deadline,
            prep.id preparation_id,prep.meeting_date,prep.titre meeting_title,prep.office_ligne_devis_id,
            COALESCE(NULLIF(TRIM(service_task.titre),''),'Service Bureau') service_label,
            COALESCE(NULLIF(TRIM(ch.titre),''),CONCAT('Chantier #',ap.chantier_id)) chantier_label,
            COALESCE(NULLIF(TRIM(ch.code),''),'') chantier_code,
            TRIM(CONCAT(COALESCE(u.prenom,''),' ',COALESCE(u.nom,''))) user_name,u.mail_pro,
            latest.response_type,latest.execution_date,latest.next_prompt_at,latest.note,latest.response_date,
            latest.reschedule_count,latest.total_shift_days,latest.created_at last_response_at,
            (SELECT COUNT(*) FROM planning_meeting_followup_reminders rr WHERE rr.checklist_id=item.checklist_id AND rr.recipient_user_id=assigned.user_id) reminder_count,
            (SELECT MAX(rr.sent_at) FROM planning_meeting_followup_reminders rr WHERE rr.checklist_id=item.checklist_id AND rr.recipient_user_id=assigned.user_id) last_reminder_at
          FROM (SELECT apx.id action_point_id,COALESCE(apx.pilot_user_id,(SELECT ux.id FROM user_devis_banamur ux WHERE ux.active=1 AND LOWER(TRIM(CONCAT(COALESCE(ux.prenom,''),' ',COALESCE(ux.nom,''))))=LOWER(TRIM(apx.pilot_name)) LIMIT 1)) user_id,'Pilote' role_label FROM planning_chantier_meeting_action_points apx WHERE apx.pilot_user_id IS NOT NULL OR TRIM(COALESCE(apx.pilot_name,''))<>'') assigned
          JOIN planning_chantier_meeting_action_points ap ON ap.id=assigned.action_point_id
          JOIN (SELECT cl.id checklist_id,cl.label checklist_label,cl.completed_at,cl.archived_at,cl.action_point_id FROM planning_chantier_meeting_action_point_checklist cl
                UNION ALL SELECT -ap0.id,'Suivi du point chantier',IF(ap0.status='done',ap0.updated_at,NULL),NULL,ap0.id FROM planning_chantier_meeting_action_points ap0 JOIN planning_chantier_meeting_preparations p0 ON p0.id=ap0.preparation_id
                WHERE p0.office_ligne_devis_id IS NULL AND NOT EXISTS(SELECT 1 FROM planning_chantier_meeting_action_point_checklist c0 WHERE c0.action_point_id=ap0.id AND c0.archived_at IS NULL)) item ON item.action_point_id=ap.id AND item.archived_at IS NULL
          JOIN planning_chantier_meeting_preparations prep ON prep.id=ap.preparation_id AND prep.archived_at IS NULL
          JOIN user_devis_banamur u ON u.id=assigned.user_id AND u.active=1
          LEFT JOIN planning_chantier_tasks service_task ON service_task.source_type='ligne_devis' AND service_task.source_id=prep.office_ligne_devis_id
          LEFT JOIN chantier_banamur ch ON ch.id=ap.chantier_id
          LEFT JOIN planning_meeting_daily_followups latest ON latest.id=(SELECT f.id FROM planning_meeting_daily_followups f WHERE f.checklist_id=item.checklist_id AND f.user_id=assigned.user_id ORDER BY f.created_at DESC,f.id DESC LIMIT 1)
          WHERE ap.archived_at IS NULL AND ap.status<>'cancelled' AND (prep.status='used' OR EXISTS(SELECT 1 FROM planning_chantier_meetings meeting_done WHERE meeting_done.preparation_id=prep.id AND meeting_done.status='closed'))
          ORDER BY prep.meeting_date DESC,prep.id DESC,item.completed_at IS NOT NULL,COALESCE(latest.execution_date,ap.new_deadline,ap.old_deadline,'9999-12-31'),user_name";
        $rows=$this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC)?:[];$latestPrep=[];foreach($rows as $row){$scope=(int)$row['office_ligne_devis_id']>0?'office:'.(int)$row['office_ligne_devis_id']:'site:'.(int)$row['chantier_id'];if(!isset($latestPrep[$scope]))$latestPrep[$scope]=(int)$row['preparation_id'];}$unique=[];
        foreach($rows as $row){$scope=(int)$row['office_ligne_devis_id']>0?'office:'.(int)$row['office_ligne_devis_id']:'site:'.(int)$row['chantier_id'];if((int)$row['preparation_id']!==$latestPrep[$scope])continue;$signature=(int)$row['user_id'].'|'.$scope.'|'.mb_strtolower(trim((string)$row['section_title'])).'|'.mb_strtolower(trim((string)$row['task_title'])).'|'.mb_strtolower(trim((string)$row['checklist_label']));if(!isset($unique[$signature]))$unique[$signature]=$row;}
        return array_values($unique);
    }

    public function sendReminder(int $checklistId,int $recipientId,int $sentBy,string $note=''):void
    {
        $row=null;foreach($this->trackingRows() as $candidate)if((int)$candidate['checklist_id']===$checklistId&&(int)$candidate['user_id']===$recipientId){$row=$candidate;break;}
        if(!$row)throw new RuntimeException('Engagement ou destinataire introuvable.');
        if(!empty($row['completed_at']))throw new RuntimeException('Cette sous-tâche est déjà achevée.');
        $note=mb_substr(trim($note),0,500);
        $this->pdo->beginTransaction();
        try{
            $s=$this->pdo->prepare('INSERT INTO planning_meeting_followup_reminders(checklist_id,recipient_user_id,sent_by_user_id,note) VALUES(:item,:recipient,:sender,:note)');
            $s->execute(['item'=>$checklistId,'recipient'=>$recipientId,'sender'=>$sentBy,'note'=>$note]);
            require_once __DIR__.'/../model/NotificationManager.php';new NotificationManager($this->pdo);
            $title='Relance · engagement de réunion';
            $scope=(int)$row['office_ligne_devis_id']>0?(string)$row['service_label']:trim((string)$row['chantier_code'].' · '.(string)$row['chantier_label'],' ·');
            $message=$scope.' › '.($row['section_title']?:'Point de réunion').' › '.$row['task_title'].' › '.$row['checklist_label'].($note!==''?' — '.$note:'');
            $n=$this->pdo->prepare("INSERT INTO system_notifications(created_at,event_key,sub_key,title,message,link_url,recipient_user_id,channels_json,payload_json) VALUES(NOW(),'meeting_daily_followup','reminder',:title,:message,'/planning_chantier/meeting_followups.php',:uid,'[\"system\"]','{}')");
            $n->execute(['title'=>$title,'message'=>$message,'uid'=>$recipientId]);$this->pdo->commit();
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function personnel():array
    {
        $columns=$this->pdo->query('SHOW COLUMNS FROM user_devis_banamur')->fetchAll(PDO::FETCH_COLUMN,0)?:[];$dg=in_array('acces_direction',$columns,true)?'COALESCE(acces_direction,0)':'0';
        return $this->pdo->query("SELECT id,TRIM(CONCAT(COALESCE(prenom,''),' ',COALESCE(nom,''))) name,mail_pro,$dg is_dg FROM user_devis_banamur WHERE active=1 ORDER BY ($dg=1) DESC,prenom,nom")->fetchAll(PDO::FETCH_ASSOC)?:[];
    }

    public function rowsByKeys(array $keys):array
    {
        $wanted=[];foreach($keys as $key)if(preg_match('/^(-?\d+):(\d+)$/',(string)$key,$m))$wanted[(int)$m[1].':'.(int)$m[2]]=true;
        if(!$wanted)return [];$rows=[];foreach($this->trackingRows() as $row)if(isset($wanted[(int)$row['checklist_id'].':'.(int)$row['user_id']]))$rows[]=$row;return $rows;
    }

    public function bulkReminder(array $keys,int $sentBy,string $note,bool $email,array $ccIds=[]):array
    {
        $rows=$this->rowsByKeys($keys);if(!$rows)throw new RuntimeException('Sélectionnez au moins un engagement.');$sent=0;$groups=[];
        foreach($rows as $row){if(!empty($row['completed_at']))continue;$this->sendReminder((int)$row['checklist_id'],(int)$row['user_id'],$sentBy,$note);$groups[(int)$row['user_id']][]=$row;$sent++;}
        $emails=0;if($email&&$groups){require_once __DIR__.'/../model/Mailer.php';$staff=[];foreach($this->personnel() as $p)$staff[(int)$p['id']]=$p;$cc=[];foreach(array_unique(array_map('intval',$ccIds)) as $id)if(!empty($staff[$id]['mail_pro']))$cc[]=['email'=>$staff[$id]['mail_pro'],'name'=>$staff[$id]['name']];
            foreach($groups as $recipientId=>$items){$person=$staff[$recipientId]??null;if(!$person||trim((string)$person['mail_pro'])==='')continue;$scopes=[];$lines=[];foreach($items as $row){$scope=(int)$row['office_ligne_devis_id']>0?(string)$row['service_label']:trim((string)$row['chantier_code'].' · '.(string)$row['chantier_label'],' ·');$scopes[]=$scope;$lines[]='<li style="margin:0 0 10px"><strong>'.htmlspecialchars((string)($row['section_title']?:'Point de réunion')).' › '.htmlspecialchars((string)$row['task_title']).'</strong><br>'.htmlspecialchars((string)$row['checklist_label']).'</li>';}$scopeLabel=implode(', ',array_unique($scopes));$isIt=str_contains(mb_strtolower($scopeLabel),'informatique');$meetingLabel=$isIt?'réunion informatique':'réunion de suivi';$subject='IMPORTANT · Relance de '.$meetingLabel;$body='<div style="font-family:Arial,sans-serif;color:#172033;line-height:1.55"><h2 style="color:#1d2b57">Relance sur vos engagements</h2><p>Les points ci-dessous font partie de la <strong>'.htmlspecialchars($meetingLabel).'</strong> ('.htmlspecialchars($scopeLabel).') et nécessitent votre retour.</p><ul>'.implode('',$lines).'</ul>'.($note!==''?'<p><strong>Message :</strong> '.nl2br(htmlspecialchars($note)).'</p>':'').'<p>Merci de renseigner leur avancement dans Finéa.</p></div>';if(Mailer::send((string)$person['mail_pro'],(string)$person['name'],$subject,$body,null,['cc'=>$cc,'from_name'=>'Finéa · Suivi des réunions']))$emails++;}
        }
        return ['reminders'=>$sent,'emails'=>$emails];
    }

    public function createTickets(array $keys,int $actorId,string $actorName):array
    {
        $rows=$this->rowsByKeys($keys);if(!$rows)throw new RuntimeException('Sélectionnez au moins un engagement.');require_once __DIR__.'/../ticketing/database/Migration.php';require_once __DIR__.'/../ticketing/repositories/TicketRepository.php';require_once __DIR__.'/../ticketing/services/TicketService.php';
        \Ticketing\Database\Migration::run($this->pdo);$repo=new \Ticketing\Repositories\TicketRepository($this->pdo);$ticketService=new \Ticketing\Services\TicketService($repo);$category=(int)$this->pdo->query('SELECT id FROM finea_ticket_categories ORDER BY id LIMIT 1')->fetchColumn();$priority=(int)$this->pdo->query('SELECT id FROM finea_ticket_priorities ORDER BY level DESC,id LIMIT 1')->fetchColumn();$created=0;$existing=0;
        foreach($rows as $row){if(!empty($row['completed_at']))continue;$reference=(int)$row['checklist_id'].':'.(int)$row['user_id'];$q=$this->pdo->prepare("SELECT id FROM finea_tickets WHERE related_reference_type='meeting_followup' AND related_reference_id=:ref AND status NOT IN ('resolu','cloture','rejete') LIMIT 1");$q->execute(['ref'=>$reference]);if($q->fetchColumn()){$existing++;continue;}$scope=(int)$row['office_ligne_devis_id']>0?(string)$row['service_label']:trim((string)$row['chantier_code'].' · '.(string)$row['chantier_label'],' ·');$ticketService->create(['title'=>'Réunion · '.mb_substr((string)$row['checklist_label'],0,195),'description'=>'Engagement issu de '.((int)$row['office_ligne_devis_id']>0?'la réunion Bureau':'la réunion Chantier').' '.$scope."\n\n".($row['section_title']?:'Point de réunion').' › '.$row['task_title']."\nSous-tâche : ".$row['checklist_label'],'category_id'=>$category,'priority_id'=>$priority,'assigned_user_id'=>(int)$row['user_id'],'assigned_user_name'=>$row['user_name'],'assigned_service'=>$scope,'related_module'=>'planning_chantier','related_reference'=>'Engagement de réunion','related_reference_value'=>'meeting_followup:'.$reference],['id'=>$actorId,'name'=>$actorName,'service'=>'Planning']);$created++;}
        return ['created'=>$created,'existing'=>$existing];
    }

    public function exportRows(int $userId):array
    {
        $unique=[];
        foreach($this->trackingRows() as $row){
            if((int)$row['user_id']!==$userId)continue;
            $scope=(int)$row['office_ligne_devis_id']>0?'bureau:'.mb_strtolower(trim((string)$row['service_label'])):'chantier:'.mb_strtolower(trim((string)$row['chantier_code'].' '.(string)$row['chantier_label']));
            $signature=implode('|',[$scope,mb_strtolower(trim((string)$row['section_title'])),mb_strtolower(trim((string)$row['task_title'])),mb_strtolower(trim((string)$row['checklist_label']))]);
            if(isset($unique[$signature])&&(string)$unique[$signature]['meeting_date']>=(string)$row['meeting_date'])continue;
            $row['response_type']=!empty($row['completed_at'])?'completed':((string)($row['response_type']??'')?:'pending');
            $row['group_label']=(int)$row['office_ligne_devis_id']>0?(string)$row['service_label']:trim((string)$row['chantier_code'].' · '.(string)$row['chantier_label'],' ·');
            $row['execution_date']=$row['execution_date']?:($row['new_deadline']?:$row['old_deadline']);$row['next_prompt_at']=$row['next_prompt_at']??null;$row['note']=$row['note']??'';
            $row['reschedule_count']=(int)($row['reschedule_count']??0);$row['total_shift_days']=(int)($row['total_shift_days']??0);
            $unique[$signature]=$row;
        }
        $rows=array_values($unique);usort($rows,static fn(array $a,array $b):int=>[(string)($a['completed_at']??''),(string)($a['execution_date']??'9999-12-31'),(string)$a['task_title']]<=>[(string)($b['completed_at']??''),(string)($b['execution_date']??'9999-12-31'),(string)$b['task_title']]);return $rows;
    }

    private function refreshPoint(int $pointId,int $userId):void
    {$stmt=$this->pdo->prepare("SELECT COUNT(*) total,SUM(completed_at IS NOT NULL) done FROM planning_chantier_meeting_action_point_checklist WHERE action_point_id=:id AND archived_at IS NULL");$stmt->execute(['id'=>$pointId]);$r=$stmt->fetch(PDO::FETCH_ASSOC)?:[];$total=(int)($r['total']??0);$progress=$total?round(100*(int)($r['done']??0)/$total,2):0;$this->pdo->prepare("UPDATE planning_chantier_meeting_action_points SET progress=:progress,status=:status,updated_by=:user,updated_at=NOW() WHERE id=:id")->execute(['progress'=>$progress,'status'=>$progress>=100?'done':($progress>0?'in_progress':'open'),'user'=>$userId,'id'=>$pointId]);}
    private function date(string $value):string
    {if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$value))throw new RuntimeException('Sélectionnez une date valide.');return $value;}
}
