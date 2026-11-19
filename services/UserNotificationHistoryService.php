<?php
declare(strict_types=1);

final class UserNotificationHistoryService
{
    public function __construct(private PDO $pdo) {}

    public function search(int $userId,array $teams,array $filters,int $page=1,int $perPage=30): array
    {
        [$where,$params]=$this->where($userId,$teams,$filters);
        $count=$this->pdo->prepare('SELECT COUNT(*) FROM system_notifications n LEFT JOIN system_notification_reads r ON r.notification_id=n.id AND r.user_id=:read_user WHERE '.$where);
        $count->execute(['read_user'=>$userId]+$params);$total=(int)$count->fetchColumn();
        $page=max(1,$page);$perPage=max(10,min(100,$perPage));$offset=($page-1)*$perPage;
        $stmt=$this->pdo->prepare('SELECT n.id,n.created_at,n.event_key,n.sub_key,n.title,n.message,n.link_url,n.recipient_team,(r.notification_id IS NULL) is_unread,r.read_at
            FROM system_notifications n LEFT JOIN system_notification_reads r ON r.notification_id=n.id AND r.user_id=:read_user
            WHERE '.$where.' ORDER BY n.id DESC LIMIT '.$perPage.' OFFSET '.$offset);
        $stmt->execute(['read_user'=>$userId]+$params);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
        return ['rows'=>$rows,'total'=>$total,'page'=>$page,'pages'=>max(1,(int)ceil($total/$perPage)),'per_page'=>$perPage];
    }

    public function all(int $userId,array $teams,array $filters): array
    {
        [$where,$params]=$this->where($userId,$teams,$filters);
        $stmt=$this->pdo->prepare('SELECT n.id,n.created_at,n.event_key,n.sub_key,n.title,n.message,n.link_url,n.recipient_team,(r.notification_id IS NULL) is_unread,r.read_at
            FROM system_notifications n LEFT JOIN system_notification_reads r ON r.notification_id=n.id AND r.user_id=:read_user
            WHERE '.$where.' ORDER BY n.id DESC');
        $stmt->execute(['read_user'=>$userId]+$params);return $stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
    }

    public function events(int $userId,array $teams): array
    {
        [$recipient,$params]=$this->recipient($userId,$teams);
        $stmt=$this->pdo->prepare('SELECT DISTINCT n.event_key FROM system_notifications n WHERE '.$recipient.' ORDER BY n.event_key');
        $stmt->execute($params);return $stmt->fetchAll(PDO::FETCH_COLUMN)?:[];
    }

    private function where(int $userId,array $teams,array $filters): array
    {
        [$recipient,$params]=$this->recipient($userId,$teams);$parts=['('.$recipient.')'];
        $q=trim((string)($filters['q']??''));if($q!==''){$parts[]='(n.title LIKE :q OR n.message LIKE :q OR n.event_key LIKE :q)';$params['q']='%'.$q.'%';}
        $state=(string)($filters['state']??'');if($state==='unread')$parts[]='r.notification_id IS NULL';elseif($state==='read')$parts[]='r.notification_id IS NOT NULL';
        $event=trim((string)($filters['event']??''));if($event!==''){$parts[]='n.event_key=:event';$params['event']=$event;}
        $from=trim((string)($filters['date_from']??''));if($from!==''){$parts[]='DATE(n.created_at)>=:date_from';$params['date_from']=$from;}
        $to=trim((string)($filters['date_to']??''));if($to!==''){$parts[]='DATE(n.created_at)<=:date_to';$params['date_to']=$to;}
        return [implode(' AND ',$parts),$params];
    }

    private function recipient(int $userId,array $teams): array
    {
        $parts=['n.recipient_user_id=:recipient_user'];$params=['recipient_user'=>$userId];$teams=array_values(array_unique(array_filter(array_map('strval',$teams))));
        if($teams){$holders=[];foreach($teams as $i=>$team){$key='team_'.$i;$holders[]=':'.$key;$params[$key]=$team;}$parts[]='(n.recipient_user_id IS NULL AND (n.recipient_team IN ('.implode(',',$holders).') OR n.recipient_team="all"))';}
        return ['('.implode(' OR ',$parts).')',$params];
    }
}
