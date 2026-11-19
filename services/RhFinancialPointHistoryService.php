<?php
declare(strict_types=1);
require_once __DIR__.'/RhFinancialPointPolicy.php';

final class RhFinancialPointHistoryService
{
    public const STATES = ['draft'=>'Brouillon / réouvert', 'rh_signed'=>'Arrêté RH, en attente du DG', 'dg_signed'=>'Signé DG', 'cancelled'=>'Annulé'];
    public const WHATSAPP = ['pending'=>'À envoyer', 'sending'=>'Envoi en cours', 'sent'=>'Invitation acceptée par Twilio', 'uncertain'=>'Envoi à vérifier'];
    public const SORTS = ['recent'=>'Plus récents', 'oldest'=>'Plus anciens', 'rh_date'=>'Dernières signatures RH', 'requester'=>'Porteur RH (A-Z)', 'kind'=>'Type de point', 'status'=>'État du point'];

    public function __construct(private PDO $pdo) {}

    public function search(array $query): array
    {
        $scalar = static fn(string $key): string => is_scalar($query[$key] ?? null) ? trim((string)$query[$key]) : '';
        $filters = ['scope'=>$scalar('scope') === 'all' ? 'all' : 'stopped', 'kind'=>$scalar('kind'), 'status'=>$scalar('status'), 'q'=>mb_substr($scalar('q'),0,100), 'date_from'=>$scalar('date_from'), 'date_to'=>$scalar('date_to')];
        if (!isset(RhFinancialPointPolicy::KINDS[$filters['kind']])) $filters['kind']='';
        if (!isset(self::STATES[$filters['status']])) $filters['status']='';
        $filters['sort']=isset(self::SORTS[$scalar('sort')])?$scalar('sort'):'recent';
        $orders=['recent'=>'p.id DESC','oldest'=>'p.id ASC','rh_date'=>'p.rh_signed_at DESC,p.id DESC','requester'=>'p.stopped_name ASC,p.id DESC','kind'=>'p.kind ASC,p.id DESC','status'=>'p.status ASC,p.id DESC'];
        $where=[]; $params=[];
        // A reopened point remains in the stopped history through its previous versions.
        if ($filters['scope']==='stopped') $where[]='EXISTS (SELECT 1 FROM rh_financial_point_versions old WHERE old.point_id=p.id AND old.rh_signed_at IS NOT NULL)';
        foreach (['kind','status'] as $key) if ($filters[$key]!=='') { $where[]='p.'.$key.'=?'; $params[]=$filters[$key]; }
        if ($filters['q']!=='') { $where[]='(p.stopped_name LIKE ? OR CAST(p.id AS CHAR)=?)'; $params[]='%'.$filters['q'].'%'; $params[]=ltrim($filters['q'],'#'); }
        foreach (['date_from'=>'>=', 'date_to'=>'<='] as $key=>$operator) {
            if ($filters[$key]==='') continue;
            $date=DateTimeImmutable::createFromFormat('!Y-m-d',$filters[$key]);
            if (!$date || $date->format('Y-m-d')!==$filters[$key]) throw new DomainException('Date de filtre invalide.');
            $where[]='p.created_at '.$operator.' ?'; $params[]=$filters[$key].($key==='date_from'?' 00:00:00':' 23:59:59');
        }
        if ($filters['date_from']!=='' && $filters['date_to']!=='' && $filters['date_from']>$filters['date_to']) throw new DomainException('La date de début doit précéder la date de fin.');
        $condition=$where?' WHERE '.implode(' AND ',$where):'';
        $q=$this->pdo->prepare('SELECT COUNT(*) FROM rh_financial_points p'.$condition);$q->execute($params);$total=(int)$q->fetchColumn();
        $pages=max(1,(int)ceil($total/25));$page=min($pages,max(1,(int)$scalar('page')));$offset=($page-1)*25;
        $q=$this->pdo->prepare('SELECT p.*,v.whatsapp_status,v.snapshot_json,r.status AS feb_status,
            EXISTS(SELECT 1 FROM fiche_decaissement f WHERE f.feb_request_id=p.feb_request_id AND f.decaisse<>0) AS has_disbursement
            FROM rh_financial_points p JOIN rh_financial_point_versions v ON v.point_id=p.id AND v.revision=p.revision
            LEFT JOIN employee_legal_requests r ON r.id=p.feb_request_id'.$condition.' ORDER BY '.$orders[$filters['sort']].' LIMIT 25 OFFSET '.$offset);
        $q->execute($params);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $snapshot=json_decode($row['snapshot_json'],true,512,JSON_THROW_ON_ERROR);
            $row['total']=RhFinancialPointPolicy::total($snapshot['lines']);
            $row['line_count']=count($snapshot['lines']);
            unset($row['snapshot_json']);
        }
        unset($row);
        return compact('filters','rows','total','page','pages');
    }

    public function versions(int $id): array
    {
        $q=$this->pdo->prepare('SELECT revision,rh_signed_at,dg_signed_at,dg_name,whatsapp_status,feb_request_id,created_at FROM rh_financial_point_versions WHERE point_id=? ORDER BY revision DESC');
        $q->execute([$id]);return $q->fetchAll(PDO::FETCH_ASSOC);
    }
}

