<?php
declare(strict_types=1);

final class OfficeMeetingStructureRepository
{
    public function __construct(private PDO $pdo) {}

    public function mutate(array $data,?int $userId):void
    {
        $preparation=$this->preparation((int)($data['preparation_id']??0));
        $scope=(string)($data['scope']??'');
        $action=(string)($data['action']??'');
        $id=(int)($data['id']??0);
        if(!in_array($scope,['task','checklist'],true)||$id<=0)throw new DomainException('Élément de réunion invalide.');
        $this->assertElement($preparation,$scope,$id);
        if($action==='rename'){
            $label=trim((string)($data['label']??''));
            if($label===''||mb_strlen($label)>500)throw new DomainException('Saisissez un nouveau libellé valide.');
            $this->rename($scope,$id,$label,$userId);
            return;
        }
        if($action!=='archive')throw new DomainException('Action inconnue.');
        $this->archive($scope,$id,$userId);
    }

    private function preparation(int $id):array
    {
        $stmt=$this->pdo->prepare("SELECT id,office_chantier_id,office_ligne_devis_id FROM planning_chantier_meeting_preparations
            WHERE id=:id AND status='ready' AND office_chantier_id IS NOT NULL AND office_ligne_devis_id IS NOT NULL LIMIT 1");
        $stmt->execute(['id'=>$id]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new DomainException('Réunion Bureau invalide.');
        return $row;
    }

    private function assertElement(array $preparation,string $scope,int $id):void
    {
        if($scope==='task'){
            $sql="SELECT COUNT(*) FROM planning_chantier_meeting_action_points ap
                WHERE ap.id=:id AND ap.preparation_id=:preparation AND ap.ligne_devis_id=:line AND ap.archived_at IS NULL";
        }else{
            $sql="SELECT COUNT(*) FROM planning_chantier_meeting_action_point_checklist cl
                JOIN planning_chantier_meeting_action_points ap ON ap.id=cl.action_point_id
                WHERE cl.id=:id AND ap.preparation_id=:preparation AND ap.ligne_devis_id=:line
                  AND ap.archived_at IS NULL AND cl.archived_at IS NULL";
        }
        $stmt=$this->pdo->prepare($sql);
        $params=['id'=>$id,'line'=>(int)$preparation['office_ligne_devis_id']];
        if(in_array($scope,['task','checklist'],true))$params['preparation']=(int)$preparation['id'];
        $stmt->execute($params);
        if(!(int)$stmt->fetchColumn())throw new DomainException('Cet élément ne fait pas partie du service en réunion.');
    }

    private function rename(string $scope,int $id,string $label,?int $userId):void
    {
        if($scope==='task')$this->pdo->prepare("UPDATE planning_chantier_meeting_action_points SET task_title=:label,updated_by=:user,updated_at=NOW() WHERE id=:id")
            ->execute(['label'=>$label,'user'=>$userId,'id'=>$id]);
        else $this->pdo->prepare("UPDATE planning_chantier_meeting_action_point_checklist SET label=:label,updated_at=NOW() WHERE id=:id")
            ->execute(['label'=>$label,'id'=>$id]);
    }

    private function archive(string $scope,int $id,?int $userId):void
    {
        $ownsTransaction=!$this->pdo->inTransaction();
        if($ownsTransaction)$this->pdo->beginTransaction();
        try{
            if($scope==='task'){
                $this->archiveChecklist('id',$id,$userId);
                $this->pdo->prepare("UPDATE planning_chantier_meeting_action_points SET archived_at=NOW(),archived_by=:user,status='cancelled' WHERE id=:id")->execute(['user'=>$userId,'id'=>$id]);
            }else{
                $point=$this->pdo->prepare("SELECT action_point_id FROM planning_chantier_meeting_action_point_checklist WHERE id=:id");
                $point->execute(['id'=>$id]);$pointId=(int)$point->fetchColumn();
                $this->pdo->prepare("UPDATE planning_chantier_meeting_action_point_checklist SET archived_at=NOW(),archived_by=:user WHERE id=:id")->execute(['user'=>$userId,'id'=>$id]);
                if($pointId>0)$this->recalculatePoint($pointId,$userId);
            }
            if($ownsTransaction)$this->pdo->commit();
        }catch(Throwable $e){if($ownsTransaction&&$this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    private function archiveChecklist(string $pointColumn,int $id,?int $userId):void
    {
        $this->pdo->prepare("UPDATE planning_chantier_meeting_action_point_checklist cl
            JOIN planning_chantier_meeting_action_points ap ON ap.id=cl.action_point_id
            SET cl.archived_at=NOW(),cl.archived_by=:user
            WHERE ap.`$pointColumn`=:id AND cl.archived_at IS NULL")->execute(['id'=>$id,'user'=>$userId]);
    }

    private function recalculatePoint(int $pointId,?int $userId):void
    {
        $count=$this->pdo->prepare("SELECT COUNT(*) total,SUM(completed_at IS NOT NULL) completed FROM planning_chantier_meeting_action_point_checklist WHERE action_point_id=:point AND archived_at IS NULL");
        $count->execute(['point'=>$pointId]);$row=$count->fetch(PDO::FETCH_ASSOC)?:['total'=>0,'completed'=>0];
        $progress=(int)$row['total']>0?round(100*(int)$row['completed']/(int)$row['total'],2):0;
        $status=$progress>=100?'done':($progress>0?'in_progress':'open');
        $this->pdo->prepare("UPDATE planning_chantier_meeting_action_points SET progress=:progress,status=:status,updated_by=:user,updated_at=NOW() WHERE id=:id")
            ->execute(['progress'=>$progress,'status'=>$status,'user'=>$userId,'id'=>$pointId]);
    }
}
