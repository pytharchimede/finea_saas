<?php
declare(strict_types=1);

final class PlanningMeetingCustomSectionCarryForwardService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function seed(int $preparationId,int $chantierId,?int $userId):void
    {
        if($preparationId<=0||$chantierId<=0)return;
        $this->pdo->beginTransaction();
        try{
            $reserve=$this->pdo->prepare("INSERT IGNORE INTO planning_chantier_meeting_custom_section_seeds
                (preparation_id,chantier_id,source_preparation_id,seeded_by,seeded_at)
                VALUES(:preparation,:chantier,NULL,:user,NOW())");
            $reserve->execute(['preparation'=>$preparationId,'chantier'=>$chantierId,'user'=>$userId]);
            if($reserve->rowCount()===0){$this->pdo->commit();return;}

            $current=$this->pdo->prepare("SELECT COUNT(*) FROM planning_chantier_meeting_custom_sections
                WHERE preparation_id=:preparation AND chantier_id=:chantier");
            $current->execute(['preparation'=>$preparationId,'chantier'=>$chantierId]);
            if((int)$current->fetchColumn()>0){$this->pdo->commit();return;}

            $source=$this->pdo->prepare("SELECT history.preparation_id
                FROM (
                    SELECT preparation_id,chantier_id FROM planning_chantier_meeting_custom_sections
                    UNION
                    SELECT preparation_id,chantier_id FROM planning_chantier_meeting_custom_section_seeds
                ) history
                JOIN planning_chantier_meeting_preparations old_p ON old_p.id=history.preparation_id
                JOIN planning_chantier_meeting_preparations current_p ON current_p.id=:preparation
                WHERE history.chantier_id=:chantier AND history.preparation_id<>:preparation_again AND old_p.status='used'
                  AND (old_p.meeting_date<current_p.meeting_date
                       OR (old_p.meeting_date=current_p.meeting_date AND old_p.id<current_p.id))
                ORDER BY old_p.meeting_date DESC,history.preparation_id DESC LIMIT 1");
            $source->execute(['preparation'=>$preparationId,'preparation_again'=>$preparationId,'chantier'=>$chantierId]);
            $sourceId=(int)$source->fetchColumn();
            if($sourceId<=0){$this->pdo->commit();return;}

            $sections=$this->pdo->prepare("SELECT * FROM planning_chantier_meeting_custom_sections
                WHERE preparation_id=:preparation AND chantier_id=:chantier AND archived_at IS NULL ORDER BY sort_order,id");
            $sections->execute(['preparation'=>$sourceId,'chantier'=>$chantierId]);
            $insert=$this->pdo->prepare("INSERT INTO planning_chantier_meeting_custom_sections
                (preparation_id,chantier_id,ligne_devis_id,title,responsible_user_id,start_date,end_date,payload_json,
                 sort_order,created_by,created_at)
                VALUES(:preparation,:chantier,:line,:title,:responsible,:start_date,:end_date,:payload,:sort_order,:user,NOW())");
            $copyFiles=$this->pdo->prepare("INSERT INTO planning_chantier_office_attachments
                (preparation_id,ligne_debourse_id,action_point_id,meeting_section_id,original_name,stored_name,mime_type,file_size,uploaded_by,created_at)
                SELECT :current,NULL,NULL,:new_section,original_name,stored_name,mime_type,file_size,:user,NOW()
                FROM planning_chantier_office_attachments
                WHERE preparation_id=:source AND meeting_section_id=:old_section AND archived_at IS NULL");
            foreach($sections->fetchAll(PDO::FETCH_ASSOC)?:[] as $section){
                $insert->execute([
                    'preparation'=>$preparationId,'chantier'=>$chantierId,'line'=>(int)($section['ligne_devis_id']??0)?:null,
                    'title'=>(string)$section['title'],'responsible'=>(int)($section['responsible_user_id']??0)?:null,
                    'start_date'=>$section['start_date']?:null,'end_date'=>$section['end_date']?:null,
                    'payload'=>(string)($section['payload_json']??'[]'),'sort_order'=>(int)($section['sort_order']??0),'user'=>$userId,
                ]);
                $copyFiles->execute(['current'=>$preparationId,'new_section'=>(int)$this->pdo->lastInsertId(),'user'=>$userId,'source'=>$sourceId,'old_section'=>(int)$section['id']]);
            }
            $this->pdo->prepare("UPDATE planning_chantier_meeting_custom_section_seeds SET source_preparation_id=:source
                WHERE preparation_id=:preparation AND chantier_id=:chantier")
                ->execute(['source'=>$sourceId,'preparation'=>$preparationId,'chantier'=>$chantierId]);
            $this->pdo->commit();
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }
}
