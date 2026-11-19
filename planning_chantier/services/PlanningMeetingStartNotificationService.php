<?php
declare(strict_types=1);

final class PlanningMeetingStartNotificationService
{
    public function __construct(private PDO $pdo) {}

    public function notifyOnce(array $preparation): int
    {
        $preparationId=(int)($preparation['id']??0);
        if($preparationId<=0)return 0;

        try{
            $this->pdo->beginTransaction();
            $claim=$this->pdo->prepare("UPDATE planning_chantier_meeting_preparations SET
                    started_at=COALESCE(started_at,NOW()),start_notified_at=NOW(),updated_at=NOW()
                WHERE id=:id AND status='ready' AND start_notified_at IS NULL");
            $claim->execute(['id'=>$preparationId]);
            if($claim->rowCount()!==1){
                $this->pdo->commit();
                return 0;
            }

            $participants=is_array($preparation['participants']??null)?$preparation['participants']:[];
            $userIds=[];
            foreach($participants as $participant){
                $userId=(int)($participant['user_id']??0);
                if($userId<=0){
                    $email=trim((string)($participant['email']??''));
                    if($email!==''){
                        try{
                            $lookup=$this->pdo->prepare("SELECT id FROM user_devis_banamur WHERE LOWER(TRIM(mail_pro))=LOWER(TRIM(:email)) AND active=1 LIMIT 1");
                            $lookup->execute(['email'=>$email]);
                            $userId=(int)($lookup->fetchColumn()?:0);
                        }catch(Throwable){}
                    }
                }
                if($userId>0)$userIds[$userId]=true;
            }

            $serviceName='';
            if(!empty($preparation['office_ligne_devis_id'])){
                $serviceStmt=$this->pdo->prepare("SELECT titre FROM planning_chantier_tasks
                    WHERE source_type='ligne_devis' AND source_id=:id AND statut<>'annule' LIMIT 1");
                $serviceStmt->execute(['id'=>(int)$preparation['office_ligne_devis_id']]);
                $serviceName=trim((string)($serviceStmt->fetchColumn()?:''));
            }
            $title=$serviceName!==''?'Réunion '.$serviceName.' a démarré':'La réunion de 12h a démarré';
            $meetingTitle=trim((string)($preparation['titre']??'Réunion quotidienne de 12h'));
            $message=$meetingTitle.' est ouverte. Vous faites partie des participants concernés.';
            $link='/planning_chantier/reunion.php?preparation_id='.$preparationId;
            $payload=json_encode([
                'preparation_id'=>$preparationId,
                'meeting_date'=>$preparation['meeting_date']??date('Y-m-d'),
                'event'=>'planning_chantier_meeting_started',
            ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $insert=$this->pdo->prepare("INSERT INTO system_notifications
                (created_at,event_key,sub_key,title,message,link_url,recipient_team,recipient_user_id,channels_json,payload_json)
                VALUES(NOW(),'planning_chantier_meeting_started',:sub,:title,:message,:link,NULL,:user,
                '[\"system\",\"push\",\"in_app\"]',:payload)");
            foreach(array_keys($userIds) as $userId){
                $insert->execute([
                    'sub'=>'preparation_'.$preparationId,
                    'title'=>$title,
                    'message'=>$message,
                    'link'=>$link,
                    'user'=>$userId,
                    'payload'=>$payload,
                ]);
            }
            $this->pdo->commit();
            return count($userIds);
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            error_log('[planning_chantier:meeting_start_notification] '.$e->getMessage());
            return 0;
        }
    }
}
