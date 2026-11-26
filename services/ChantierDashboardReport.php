<?php
declare(strict_types=1);
final class ChantierDashboardReport {
    public static function rows(PDO $pdo,string $scope='active',string $q='',int $client=0): array {
        if(!in_array($scope,['active','archived','all'],true))throw new InvalidArgumentException('Périmètre invalide.');
        $sql="SELECT c.id,c.code,c.titre,c.archived_at,c.paused_at,c.financial_data_status,c.date_creation,c.devis_id,cl.nom_client,d.numero_devis,d.total_ttc,db.budget FROM chantier_banamur c LEFT JOIN client_banamur cl ON cl.id_client=c.client_id LEFT JOIN devis_banamur d ON d.id=c.devis_id LEFT JOIN offre_banamur o ON o.id_offre=d.offre_id LEFT JOIN (SELECT devis_id,SUM(montant_debourse) budget FROM debourse_banamur GROUP BY devis_id) db ON db.devis_id=c.devis_id WHERE 1=1";
        $params=[];
        if($scope==='active')$sql.=' AND c.archived_at IS NULL';
        if($scope==='archived')$sql.=' AND c.archived_at IS NOT NULL';
        if($client>0){$sql.=' AND c.client_id=?';$params[]=$client;}
        if($q!==''){$sql.=' AND (c.titre LIKE ? OR c.code LIKE ? OR cl.nom_client LIKE ? OR d.numero_devis LIKE ? OR o.reference_offre LIKE ?)';$params=array_merge($params,array_fill(0,5,'%'.$q.'%'));}
        $stmt=$pdo->prepare($sql.' ORDER BY c.id DESC');$stmt->execute($params);return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    public static function state(array $r):string{return $r['archived_at']!==null?'Archivé':($r['paused_at']!==null?'Suspendu':'Non archivé');}
    public static function totals(array $rows):array{
        $t=['count'=>count($rows),'compliant'=>0,'paused'=>0,'contract'=>0.0,'budget'=>0.0,'missing'=>0];$seen=[];
        foreach($rows as $r){if($r['financial_data_status']==='compliant')$t['compliant']++;if($r['archived_at']===null&&$r['paused_at']!==null)$t['paused']++;if($r['budget']===null)$t['missing']++;$id=(int)$r['devis_id'];if($id>0&&!isset($seen[$id])){$seen[$id]=true;$t['contract']+=(float)$r['total_ttc'];$t['budget']+=(float)$r['budget'];}}
        return $t;
    }
}
