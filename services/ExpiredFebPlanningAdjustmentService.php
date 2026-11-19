<?php
declare(strict_types=1);

require_once __DIR__ . '/DeboursePeriodService.php';

final class ExpiredFebPlanningAdjustmentService
{
    public static function preview(PDO $pdo, int $debourseId, int $chantierId): array
    {
        if ($debourseId <= 0 || $chantierId <= 0) return [];
        $query = $pdo->prepare('SELECT d.date_debut,d.date_fin,c.devis_id,d.devis_id debourse_devis_id FROM debourse_banamur d JOIN chantier_banamur c ON c.id=:chantier AND c.archived_at IS NULL AND (c.devis_id=d.devis_id OR EXISTS(SELECT 1 FROM devis_chantier_links l WHERE l.devis_id=d.devis_id AND l.chantier_id=c.id)) WHERE d.id=:debourse LIMIT 1');
        $query->execute(['debourse'=>$debourseId,'chantier'=>$chantierId]);
        $row=$query->fetch(PDO::FETCH_ASSOC) ?: [];
        if (!$row) return [];
        $row['chantier_start']='';$row['chantier_end']='';
        if (self::tableExists($pdo,'planning_chantier_projects')) {
            $planning=$pdo->prepare('SELECT date_debut,date_fin_prevue FROM planning_chantier_projects WHERE chantier_id=:id ORDER BY id DESC LIMIT 1');
            $planning->execute(['id'=>$chantierId]);
            $dates=$planning->fetch(PDO::FETCH_ASSOC) ?: [];
            $row['chantier_start']=(string)($dates['date_debut']??'');
            $row['chantier_end']=(string)($dates['date_fin_prevue']??'');
        }
        if ($row['chantier_start']==='' || $row['chantier_end']==='') {
            $quote=$pdo->prepare('SELECT project_start_date,project_end_date FROM devis_banamur WHERE id=:id LIMIT 1');
            $quote->execute(['id'=>(int)$row['devis_id']]);
            $dates=$quote->fetch(PDO::FETCH_ASSOC) ?: [];
            $row['chantier_start']=$row['chantier_start'] ?: (string)($dates['project_start_date']??'');
            $row['chantier_end']=$row['chantier_end'] ?: (string)($dates['project_end_date']??'');
        }
        return $row;
    }

    /** Must run inside the FEB approval transaction. */
    public static function apply(PDO $pdo, int $debourseId, int $chantierId, string $start, string $end, bool $confirmExtension): array
    {
        if (!$pdo->inTransaction()) throw new LogicException('Une transaction est nécessaire pour ajuster le planning.');
        foreach ([$start,$end] as $date) {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d',$date);
            if (!$parsed || $parsed->format('Y-m-d') !== $date) throw new DomainException('La nouvelle période du déboursé est invalide.');
        }
        if ($end < $start) throw new DomainException('La fin des travaux doit suivre leur début.');
        $row = self::preview($pdo,$debourseId,$chantierId);
        if (!$row) throw new DomainException('Le déboursé ne correspond pas au chantier de la FEB.');
        $oldStart=(string)$row['date_debut'];$oldEnd=(string)$row['date_fin'];
        if ($oldEnd >= date('Y-m-d')) throw new DomainException('Cet ajustement est réservé à une période de travaux déjà expirée.');
        if (empty($_SESSION['permissions']['modifier_dates_travaux'])) throw new DomainException('Le droit « Modifier les dates de début et de fin des travaux » est requis pour ajuster ce planning.');
        $chantierStart=(string)($row['chantier_start']??'');$chantierEnd=(string)($row['chantier_end']??'');
        $newChantierStart=$chantierStart!==''?min($chantierStart,$start):$start;
        $newChantierEnd=$chantierEnd!==''?max($chantierEnd,$end):$end;
        $extended=$newChantierStart!==$chantierStart||$newChantierEnd!==$chantierEnd;
        if ($extended && !$confirmExtension) throw new DomainException('Confirmez les nouvelles bornes du chantier avant de valider le planning.');
        $pdo->prepare('UPDATE debourse_banamur SET date_debut=:start,date_fin=:end WHERE id=:id')->execute(['start'=>$start,'end'=>$end,'id'=>$debourseId]);
        DeboursePeriodService::synchronizeDetails($pdo,$debourseId);
        if ($extended) {
            $chantierColumns=self::columns($pdo,'chantier_banamur');
            $set=[];$params=['id'=>$chantierId];
            if (in_array('date_debut',$chantierColumns,true)) {$set[]='date_debut=:start';$params['start']=$newChantierStart;}
            if (in_array('date_fin_prevue',$chantierColumns,true)) {$set[]='date_fin_prevue=:end';$params['end']=$newChantierEnd;}
            if ($set) $pdo->prepare('UPDATE chantier_banamur SET '.implode(',',$set).' WHERE id=:id')->execute($params);
            $pdo->prepare('UPDATE devis_banamur SET project_start_date=:start,project_end_date=:end WHERE id=:id')->execute(['start'=>$newChantierStart,'end'=>$newChantierEnd,'id'=>(int)$row['devis_id']]);
            if (self::tableExists($pdo,'planning_chantier_projects')) $pdo->prepare('UPDATE planning_chantier_projects SET date_debut=:start,date_fin_prevue=:end WHERE chantier_id=:id')->execute(['start'=>$newChantierStart,'end'=>$newChantierEnd,'id'=>$chantierId]);
        }
        return ['old_start'=>$oldStart,'old_end'=>$oldEnd,'start'=>$start,'end'=>$end,'chantier_old_start'=>$chantierStart,'chantier_old_end'=>$chantierEnd,'chantier_start'=>$newChantierStart,'chantier_end'=>$newChantierEnd,'extended'=>$extended];
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        static $cache=[];
        if (array_key_exists($table,$cache)) return $cache[$table];
        $query=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table');
        $query->execute(['table'=>$table]);
        return $cache[$table]=(int)$query->fetchColumn()>0;
    }

    private static function columns(PDO $pdo, string $table): array
    {
        static $cache=[];
        if (isset($cache[$table])) return $cache[$table];
        $query=$pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table');
        $query->execute(['table'=>$table]);
        $cache[$table]=$query->fetchAll(PDO::FETCH_COLUMN) ?: [];
        return $cache[$table];
    }
}
