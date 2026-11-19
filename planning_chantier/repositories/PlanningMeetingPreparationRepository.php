<?php
declare(strict_types=1);

final class PlanningMeetingPreparationRepository
{
    public function __construct(private PDO $pdo) {}

    public function save(array $data, ?int $id = null): int
    {
        $status = ($data['status'] ?? 'draft') === 'ready' ? 'ready' : 'draft';
        $payload = [
            'meeting_date' => $data['meeting_date'] ?: date('Y-m-d'),
            'titre' => trim((string)($data['titre'] ?? 'Réunion quotidienne de 12h')),
            'agenda' => trim((string)($data['agenda'] ?? '')),
            'chantier_ids_json' => json_encode(array_values($data['chantier_ids'] ?? []), JSON_UNESCAPED_UNICODE),
            'participants_json' => json_encode(array_values($data['participants'] ?? []), JSON_UNESCAPED_UNICODE),
            'office_chantier_id' => !empty($data['office_chantier_id'])?(int)$data['office_chantier_id']:null,
            'office_ligne_devis_id' => !empty($data['office_ligne_devis_id'])?(int)$data['office_ligne_devis_id']:null,
            'status' => $status,
            'created_by' => $data['created_by'] ?? null,
            'ready_at' => $status === 'ready' ? date('Y-m-d H:i:s') : null,
        ];
        if ($status === 'ready' && (!$data['chantier_ids'] || !$data['participants'] || $payload['agenda'] === '')) {
            throw new DomainException('Une préparation terminée exige un ordre du jour, au moins un chantier et un participant.');
        }
        if ($id) {
            $updatePayload=$payload;
            unset($updatePayload['created_by']);
            $updatePayload['id']=$id;
            $this->pdo->prepare("UPDATE planning_chantier_meeting_preparations SET
                meeting_date=:meeting_date,titre=:titre,agenda=:agenda,
                chantier_ids_json=:chantier_ids_json,participants_json=:participants_json,
                office_chantier_id=:office_chantier_id,office_ligne_devis_id=:office_ligne_devis_id,
                status=:status,ready_at=:ready_at,updated_at=NOW()
                WHERE id=:id AND status IN ('draft','ready')")->execute($updatePayload);
            return $id;
        }
        $this->pdo->prepare("INSERT INTO planning_chantier_meeting_preparations
            (meeting_date,titre,agenda,chantier_ids_json,participants_json,office_chantier_id,office_ligne_devis_id,status,created_by,ready_at,created_at)
            VALUES(:meeting_date,:titre,:agenda,:chantier_ids_json,:participants_json,:office_chantier_id,:office_ligne_devis_id,:status,:created_by,
            :ready_at,NOW())")->execute($payload);
        return (int)$this->pdo->lastInsertId();
    }

    public function findReady(int $id): ?array
    {
        $stmt=$this->pdo->prepare("SELECT * FROM planning_chantier_meeting_preparations WHERE id=:id AND status='ready' AND archived_at IS NULL LIMIT 1");
        $stmt->execute(['id'=>$id]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$row)return null;
        $row['chantier_ids']=json_decode((string)$row['chantier_ids_json'],true)?:[];
        $row['participants']=json_decode((string)$row['participants_json'],true)?:[];
        return $row;
    }

    /** Retourne aussi une préparation déjà utilisée, pour consulter/exporter son planning. */
    public function findForConsultation(int $id): ?array
    {
        $stmt=$this->pdo->prepare("SELECT * FROM planning_chantier_meeting_preparations
            WHERE id=:id AND status IN ('ready','used') LIMIT 1");
        $stmt->execute(['id'=>$id]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$row)return null;
        $row['chantier_ids']=json_decode((string)$row['chantier_ids_json'],true)?:[];
        $row['participants']=json_decode((string)$row['participants_json'],true)?:[];
        return $row;
    }

    public function findEditable(int $id):?array
    {
        $stmt=$this->pdo->prepare("SELECT * FROM planning_chantier_meeting_preparations
            WHERE id=:id AND status IN ('draft','ready') AND archived_at IS NULL LIMIT 1");
        $stmt->execute(['id'=>$id]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$row)return null;
        $row['chantier_ids']=json_decode((string)$row['chantier_ids_json'],true)?:[];
        $row['participants']=json_decode((string)$row['participants_json'],true)?:[];
        return $row;
    }

    public function openDrafts(int $limit=20):array
    {
        $stmt=$this->pdo->prepare("SELECT prep.*,
                NULLIF(TRIM(line_task.titre),'') service_label,
                NULLIF(TRIM(c.titre),'') chantier_label
            FROM planning_chantier_meeting_preparations prep
            LEFT JOIN planning_chantier_tasks line_task
              ON line_task.source_type='ligne_devis' AND line_task.source_id=prep.office_ligne_devis_id
            LEFT JOIN chantier_banamur c ON c.id=prep.office_chantier_id
            WHERE prep.status IN ('draft','ready') AND prep.archived_at IS NULL
            ORDER BY prep.updated_at DESC,prep.created_at DESC LIMIT :limit");
        $stmt->bindValue('limit',$limit,PDO::PARAM_INT);$stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
    }

    public function assertNoConflict(array $chantierIds,?int $officeLineId,int $ignoreId=0):void
    {
        $rows=$this->pdo->query("SELECT id,chantier_ids_json,office_ligne_devis_id
            FROM planning_chantier_meeting_preparations
            WHERE status IN ('draft','ready') AND archived_at IS NULL")->fetchAll(PDO::FETCH_ASSOC)?:[];
        foreach($rows as $row){
            if((int)$row['id']===$ignoreId)continue;
            if($officeLineId&&$officeLineId===(int)$row['office_ligne_devis_id'])throw new DomainException('Une réunion ou un brouillon existe déjà pour ce service Bureau. Clôturez-le ou archivez-le avant de continuer.');
            $existing=array_map('intval',json_decode((string)$row['chantier_ids_json'],true)?:[]);
            if(array_intersect($chantierIds,$existing))throw new DomainException('Une réunion ou un brouillon existe déjà pour l’un des chantiers sélectionnés. Clôturez-le ou archivez-le avant de continuer.');
        }
    }

    public function archive(int $id,?int $userId):void
    {
        $this->pdo->beginTransaction();
        try{
            $this->pdo->prepare("UPDATE planning_chantier_meeting_preparations SET
                status='cancelled',archived_at=NOW(),archived_by=:user,updated_at=NOW()
                WHERE id=:id AND status IN ('draft','ready')")->execute(['id'=>$id,'user'=>$userId]);
            $this->pdo->prepare("UPDATE planning_chantier_meetings SET archived_at=NOW(),archived_by=:user,updated_at=NOW()
                WHERE preparation_id=:id AND status='draft' AND archived_at IS NULL")->execute(['id'=>$id,'user'=>$userId]);
            $this->pdo->commit();
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function markUsed(int $id,int $meetingId):void
    {
        $this->pdo->prepare("UPDATE planning_chantier_meeting_preparations SET status='used',meeting_id=:meeting,used_at=NOW(),updated_at=NOW() WHERE id=:id AND status='ready'")
            ->execute(['meeting'=>$meetingId,'id'=>$id]);
    }
}
