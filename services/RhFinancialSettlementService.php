<?php
declare(strict_types=1);
require_once __DIR__.'/RhFinancialPointService.php';

final class RhFinancialSettlementService
{
    public function __construct(private PDO $pdo) {}

    public static function request(PDO $pdo,array $fiche): ?array
    {
        $id=(int)($fiche['feb_request_id'] ?? 0); if(!$id)return null;
        $q=$pdo->prepare("SELECT * FROM employee_legal_requests WHERE id=? AND request_type='feb'");$q->execute([$id]);$r=$q->fetch(PDO::FETCH_ASSOC);
        if(!$r)return null;$r['payload']=json_decode($r['payload_json'],true,512,JSON_THROW_ON_ERROR);
        return !empty($r['payload']['rh_financial_point_id'])?$r:null;
    }
    public static function isRhFiche(PDO $pdo,array $fiche):bool{return self::request($pdo,$fiche)!==null;}
    public static function assertFiche(PDO $pdo,array $fiche):void
    {
        $r=self::request($pdo,$fiche);if(!$r)return;
        $p=(new RhFinancialPointService($pdo))->assertSignedFeb($r);
        if($r['status']!=='approved' || (float)$fiche['montant_fiche'] !== (float)RhFinancialPointPolicy::total($p['snapshot']['lines'])
            || trim($fiche['beficiaire_fiche'])!==trim($p['stopped_name']) || (int)$fiche['personnel_id']!==(int)$p['stopped_personnel_id']
            || (int)$fiche['chantier_id']!==$p['snapshot']['budget']['chantier_id'] || (int)$fiche['ligne_debourse_id']!==$p['snapshot']['budget']['ligne_debourse_id']
            || $fiche['mode_paiement']!==$p['snapshot']['budget']['mode_paiement'] || $fiche['date_decaissement_minimum']!==$p['snapshot']['budget']['date']
            || $fiche['details_demande']!==RhFinancialPointPolicy::details($r['title'],$p['snapshot']['lines'])) throw new DomainException('La fiche RH a changé depuis la signature. Réouvrez le point avant paiement.');
    }

    /** Atomic notifications and debt synchronization, resumable by cron after a crash. */
    public function sync(string $num,string $stage,?NotificationManager $notifications=null):void
    {
        if(!in_array($stage,['authorized','paid'],true))throw new InvalidArgumentException('Étape invalide.');
        if($stage==='paid')$this->sync($num,'authorized',$notifications);
        $q=$this->pdo->prepare('SELECT * FROM fiche_decaissement WHERE num_fiche=?');$q->execute([$num]);$f=$q->fetch(PDO::FETCH_ASSOC);
        if(!$f || !self::isRhFiche($this->pdo,$f))return;
        // Legacy constructors run schema preparation, necessarily before our transaction.
        if(!class_exists('NotificationManager'))require_once __DIR__.'/../model/NotificationManager.php';
        $notifications??=new NotificationManager($this->pdo);
        if(!class_exists('PayrollManager'))require_once __DIR__.'/../decaissement/model/PayrollManager.php';
        $payroll=new PayrollManager($this->pdo);
        $r=self::request($this->pdo,$f);$points=new RhFinancialPointService($this->pdo);
        $this->pdo->beginTransaction();
        try {
            // Same lock order as reopen / FEB approval: point, FEB, then fiche.
            $p=$points->assertSignedFeb($r,true);
            $q=$this->pdo->prepare('SELECT * FROM fiche_decaissement WHERE num_fiche=? FOR UPDATE');$q->execute([$num]);$f=$q->fetch(PDO::FETCH_ASSOC);
            if(!$f || (int)$f['approuve']!==1 || ($stage==='paid' && (int)$f['decaisse']!==1)){$this->pdo->commit();return;}
            self::assertFiche($this->pdo,$f);
            foreach($p['snapshot']['lines'] as $line) {
                if($line['decision']!=='approved')continue;
                $claim=$this->pdo->prepare('INSERT IGNORE INTO rh_financial_delivery(point_id,revision,line_key,stage) VALUES(?,?,?,?)');
                $claim->execute([$p['id'],$p['revision'],$line['key'],$stage]);if($claim->rowCount()!==1)continue;
                if($p['kind']!=='salary') {
                    $payload=$line['source']['payload'];
                    $amountKey=$p['kind']==='loan'?'montant_demande':'montant';$payload[$amountKey]=$line['amount'];
                    $payload['rh_financial_authorization']=['point_id'=>(int)$p['id'],'revision'=>(int)$p['revision'],'num_fiche'=>$num,'amount'=>$line['amount']];
                    if($stage==='paid')$payload['rh_financial_paid_at']=$f['date_decaisse'] ?: date('Y-m-d H:i:s');
                    $this->pdo->prepare("UPDATE employee_legal_requests SET status='approved',payload_json=?,decided_at=NOW(),updated_at=NOW() WHERE id=?")->execute([json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$line['source_id']]);
                    if($stage==='paid') {
                        $source=['id'=>$line['source_id'],'request_type'=>RhFinancialPointPolicy::REQUEST_TYPES[$p['kind']],'status'=>'approved','title'=>$line['label'],'payload'=>$payload,'submitted_at'=>$line['source']['submitted_at'],'decided_at'=>$payload['rh_financial_paid_at']];
                        if(!$payroll->syncDebtFromLegalRequest($source,['user_id'=>$p['dg_user_id'],'user_name'=>$p['dg_name']]))throw new RuntimeException('Recouvrement RH impossible : contrôlez le matricule de la demande #'.$line['source_id']);
                    }
                }
                $notificationId=$notifications->notifyRhFinancialLine((int)$line['user_id'],$stage,$line['label'],(int)$line['amount'],$num);
                if(!$notificationId)throw new RuntimeException('Compte de notification manquant pour '.$line['name']);
                $this->pdo->prepare('UPDATE rh_financial_delivery SET notification_id=?,delivered_at=NOW() WHERE point_id=? AND revision=? AND line_key=? AND stage=?')->execute([$notificationId,$p['id'],$p['revision'],$line['key'],$stage]);
            }
            $this->pdo->commit();
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
}
