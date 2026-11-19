<?php
declare(strict_types=1);
require_once __DIR__.'/../../model/NotificationManager.php';
require_once __DIR__.'/../../model/Mailer.php';

final class ContratWorkflowNotifier
{
    public function __construct(private PDO $pdo){new NotificationManager($pdo);}

    public function notifyNext(int $contractId,string $completedStage,int $actorUserId): void
    {
        $next=['rh'=>'juridique','juridique'=>'technique','technique'=>'logistique','logistique'=>'qhse','qhse'=>'comptabilite','comptabilite'=>'direction'][$completedStage]??null;if(!$next)return;
        $q=$this->pdo->prepare("SELECT c.reference,c.objet,c.created_by,c.signataire_banamur_personnel_id,ch.code chantier_code FROM contrat_sous_traitance c JOIN chantier_banamur ch ON ch.id=c.chantier_id WHERE c.id=?");$q->execute([$contractId]);$contract=$q->fetch(PDO::FETCH_ASSOC);if(!$contract)return;
        if($next==='direction')$targetPersonnelId=(int)$contract['signataire_banamur_personnel_id'];else{$q=$this->pdo->prepare('SELECT signataire_personnel_id FROM contrat_workflow_etape WHERE contrat_id=? AND etape=?');$q->execute([$contractId,$next]);$targetPersonnelId=(int)$q->fetchColumn();}
        $q=$this->pdo->prepare("SELECT DISTINCT u.id user_id,u.mail_pro email,TRIM(CONCAT(COALESCE(u.prenom,''),' ',COALESCE(u.nom,''))) nom,u.personnel_id FROM user_devis_banamur u WHERE u.active=1 AND (u.personnel_id IN (SELECT signataire_personnel_id FROM contrat_workflow_etape WHERE contrat_id=? AND signataire_personnel_id IS NOT NULL) OR u.personnel_id=? OR u.id IN (?,?))");$q->execute([$contractId,(int)$contract['signataire_banamur_personnel_id'],(int)$contract['created_by'],$actorUserId]);$participants=$q->fetchAll(PDO::FETCH_ASSOC);
        $target=null;foreach($participants as $participant)if((int)$participant['personnel_id']===$targetPersonnelId){$target=$participant;break;}if(!$target)return;
        $labels=['juridique'=>'Juridique','technique'=>'Technique','logistique'=>'Logistique','qhse'=>'QHSE','comptabilite'=>'Comptabilité','direction'=>'Direction générale'];$label=$labels[$next];$link='/contrats/workflow.php?id='.$contractId.'&etape='.$next;$title='Contrat '.$contract['reference'].' · Action requise '.$label;$message='L’étape précédente est validée. '.$label.' doit maintenant compléter ou signer sa partie pour le chantier '.$contract['chantier_code'].'.';
        $insert=$this->pdo->prepare("INSERT INTO system_notifications(created_at,event_key,sub_key,title,message,link_url,recipient_user_id,channels_json,payload_json) VALUES(NOW(),'contract_workflow',?,?,?,?,?,'[\"system\",\"in_app\",\"email\"]',?)");
        foreach($participants as $participant){$isTarget=(int)$participant['user_id']===(int)$target['user_id'];$participantMessage=$isTarget?$message:'Pour suivi : '.$message;$insert->execute([$completedStage.'_to_'.$next.'_'.$contractId.'_'.$participant['user_id'],$title,$participantMessage,$link,(int)$participant['user_id'],json_encode(['contract_id'=>$contractId,'completed_stage'=>$completedStage,'next_stage'=>$next,'action_required'=>$isTarget],JSON_UNESCAPED_UNICODE)]);}
        $cc=[];foreach($participants as $participant)if((int)$participant['user_id']!==(int)$target['user_id']&&filter_var($participant['email'],FILTER_VALIDATE_EMAIL))$cc[]=['email'=>$participant['email'],'name'=>$participant['nom']?:$participant['email']];
        if(filter_var($target['email'],FILTER_VALIDATE_EMAIL)){$body=Mailer::buildFineaNotificationEmail($title,'<p>'.htmlspecialchars($message,ENT_QUOTES,'UTF-8').'</p><p><strong>Objet :</strong> '.htmlspecialchars((string)$contract['objet'],ENT_QUOTES,'UTF-8').'</p>',$link,['button_label'=>'Ouvrir mon étape','subtitle'=>'Circuit de validation du contrat de sous-traitance']);Mailer::send($target['email'],$target['nom']?:$target['email'],$title,$body,strip_tags($message),['cc'=>$cc,'from_name'=>'Finéa · Contrats']);}
    }

    /** Alerte le circuit quand une étape déjà signée est corrigée et doit être revalidée. */
    public function notifyRevision(int $contractId,string $editedStage,array $resetStages,int $actorUserId): void
    {
        $q=$this->pdo->prepare('SELECT reference,objet,created_by,signataire_banamur_personnel_id FROM contrat_sous_traitance WHERE id=?');$q->execute([$contractId]);$contract=$q->fetch(PDO::FETCH_ASSOC);if(!$contract)return;
        $q=$this->pdo->prepare("SELECT DISTINCT u.id user_id,u.personnel_id,u.mail_pro email,TRIM(CONCAT(COALESCE(u.prenom,''),' ',COALESCE(u.nom,''))) nom FROM user_devis_banamur u WHERE u.active=1 AND (u.personnel_id IN (SELECT signataire_personnel_id FROM contrat_workflow_etape WHERE contrat_id=? AND signataire_personnel_id IS NOT NULL) OR u.personnel_id=? OR u.id=?)");
        $q->execute([$contractId,(int)$contract['signataire_banamur_personnel_id'],(int)$contract['created_by']]);$recipients=$q->fetchAll(PDO::FETCH_ASSOC)?:[];
        $labels=['rh'=>'RH','juridique'=>'Juridique','technique'=>'Technique','logistique'=>'Logistique','qhse'=>'QHSE','comptabilite'=>'Comptabilité'];
        $editedLabel=$labels[$editedStage]??$editedStage;$resetLabels=array_map(static fn(string $stage):string=>$labels[$stage]??$stage,$resetStages);
        $suffix=$resetLabels?' Les validations suivantes devront être refaites : '.implode(', ',$resetLabels).'.':' La signature de cette étape doit être refaite.';
        $title='Contrat '.$contract['reference'].' · Étape modifiée';$message='L’étape '.$editedLabel.' a été modifiée avant la signature du DG.'.$suffix;
        $q=$this->pdo->prepare('SELECT etape,signataire_personnel_id FROM contrat_workflow_etape WHERE contrat_id=? ORDER BY ordre');$q->execute([$contractId]);$stageByPersonnel=[];foreach($q->fetchAll(PDO::FETCH_ASSOC)?:[] as $stageRow){$personnelId=(int)$stageRow['signataire_personnel_id'];$stageName=(string)$stageRow['etape'];if($personnelId>0&&in_array($stageName,$resetStages,true)&&!isset($stageByPersonnel[$personnelId]))$stageByPersonnel[$personnelId]=$stageName;}
        $link='/contrats/workflow.php?id='.$contractId;
        $insert=$this->pdo->prepare("INSERT INTO system_notifications(created_at,event_key,sub_key,title,message,link_url,recipient_user_id,channels_json,payload_json) VALUES(NOW(),'contract_workflow',?,?,?,?,?,'[\"system\",\"in_app\",\"email\"]',?)");
        foreach($recipients as $recipient){if((int)$recipient['user_id']===$actorUserId)continue;$recipientStage=$stageByPersonnel[(int)($recipient['personnel_id']??0)]??'';$recipientLink=$recipientStage!==''?$link.'&etape='.$recipientStage:$link;$actionRequired=in_array($recipientStage,$resetStages,true);$insert->execute(['revision_'.$contractId.'_'.$editedStage.'_'.(int)$recipient['user_id'],$title,$message,$recipientLink,(int)$recipient['user_id'],json_encode(['contract_id'=>$contractId,'edited_stage'=>$editedStage,'reset_stages'=>$resetStages,'action_required'=>$actionRequired],JSON_UNESCAPED_UNICODE)]);if(filter_var($recipient['email'],FILTER_VALIDATE_EMAIL)){$body=Mailer::buildFineaNotificationEmail($title,'<p>'.htmlspecialchars($message,ENT_QUOTES,'UTF-8').'</p><p><strong>Objet :</strong> '.htmlspecialchars((string)$contract['objet'],ENT_QUOTES,'UTF-8').'</p>',$recipientLink,['button_label'=>$actionRequired?'Ouvrir mon étape':'Ouvrir le contrat','subtitle'=>'Circuit de validation du contrat de sous-traitance']);Mailer::send($recipient['email'],$recipient['nom']?:$recipient['email'],$title,$body,strip_tags($message),['from_name'=>'Finéa · Contrats']);}}
    }
}

