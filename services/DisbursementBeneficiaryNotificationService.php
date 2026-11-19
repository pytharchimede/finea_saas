<?php
declare(strict_types=1);
require_once __DIR__.'/RhFinancialSettlementService.php';

final class DisbursementBeneficiaryNotificationService
{
    public function __construct(private PDO $pdo){}
    public function sync(string $num,string $stage,?NotificationManager $notifications=null):void
    {
        if(!in_array($stage,['authorized','paid'],true))throw new InvalidArgumentException('Étape invalide.');
        if($num==='')return;
        // Optional until the migration has been deployed; existing FEB behavior is preserved.
        if(!$this->pdo->query("SHOW TABLES LIKE 'finea_fiche_beneficiary_alerts'")->fetchColumn())return;
        $q=$this->pdo->prepare('SELECT f.*,u.id recipient_user_id FROM fiche_decaissement f JOIN personnel p ON p.id_personnel_soignant=f.personnel_id JOIN user_devis_banamur u ON u.mail_pro=p.email_personnel_soignant AND u.active=1 WHERE f.num_fiche=?');$q->execute([$num]);$f=$q->fetch(PDO::FETCH_ASSOC);
        if(!$f || RhFinancialSettlementService::isRhFiche($this->pdo,$f))return;
        if(!class_exists('NotificationManager'))require_once __DIR__.'/../model/NotificationManager.php';$notifications??=new NotificationManager($this->pdo);
        $this->pdo->beginTransaction();
        try {
            $q=$this->pdo->prepare('SELECT * FROM fiche_decaissement WHERE num_fiche=? FOR UPDATE');$q->execute([$num]);$fresh=$q->fetch(PDO::FETCH_ASSOC);
            if(!$fresh || (int)$fresh['approuve']!==1 || ($stage==='paid' && (int)$fresh['decaisse']!==1)){$this->pdo->commit();return;}
            $claim=$this->pdo->prepare('INSERT IGNORE INTO finea_fiche_beneficiary_alerts(num_fiche,stage,recipient_user_id) VALUES(?,?,?)');$claim->execute([$num,$stage,$f['recipient_user_id']]);
            if($claim->rowCount()===1){
                $label=trim((string)($fresh['details_demande'] ?: ($fresh['precision_fiche'] ?: $fresh['designation_fiche']))) ?: ('Demande '.$num);
                $id=$notifications->notifyRhFinancialLine((int)$f['recipient_user_id'],$stage,$label,RhFinancialPointPolicy::amount($fresh['montant_fiche']),$num);
                if(!$id)throw new RuntimeException('Notification bénéficiaire impossible.');
                $this->pdo->prepare('UPDATE finea_fiche_beneficiary_alerts SET notification_id=?,delivered_at=NOW() WHERE num_fiche=? AND stage=? AND recipient_user_id=?')->execute([$id,$num,$stage,$f['recipient_user_id']]);
            }
            $this->pdo->commit();
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
}
