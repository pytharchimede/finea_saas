<?php
declare(strict_types=1);

require_once __DIR__.'/../repositories/NotificationRepository.php';

final class ChantierReliabilityAlertService
{
    private const THRESHOLD = 50.0;

    public function __construct(private PDO $pdo)
    {
        new NotificationRepository($pdo);
        $pdo->exec("CREATE TABLE IF NOT EXISTS chantier_reliability_alert_state (
            state_key VARCHAR(80) NOT NULL,
            is_below_threshold TINYINT(1) NOT NULL DEFAULT 0,
            last_rate DECIMAL(6,2) NOT NULL DEFAULT 100,
            last_alert_at DATETIME NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY(state_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function evaluate(array $audit): void
    {
        $total=(int)($audit['total']??0);$compliant=(int)($audit['compliant']??0);
        $rate=$total>0?round($compliant/$total*100,2):100.0;
        $stmt=$this->pdo->prepare('SELECT * FROM chantier_reliability_alert_state WHERE state_key=:key LIMIT 1');
        $stmt->execute(['key'=>'active_chantiers']);$state=$stmt->fetch(PDO::FETCH_ASSOC)?:[];
        if($rate>=self::THRESHOLD){
            $this->saveState(false,$rate,null);
            return;
        }
        $lastAlert=(string)($state['last_alert_at']??'');
        $mustAlert=empty($state)||!(bool)($state['is_below_threshold']??false)||$lastAlert===''||strtotime($lastAlert)<=time()-86400;
        if($mustAlert)$this->notify($rate,$total,$compliant,(int)($audit['incomplete']??($total-$compliant)));
        $this->saveState(true,$rate,$mustAlert?date('Y-m-d H:i:s'):($lastAlert?:null));
    }

    private function notify(float $rate,int $total,int $compliant,int $incomplete):void
    {
        $sub='active_chantiers_'.date('Ymd');
        $exists=$this->pdo->prepare("SELECT id FROM system_notifications WHERE event_key='chantier_reliability_critical' AND sub_key=:sub LIMIT 1");
        $exists->execute(['sub'=>$sub]);if($exists->fetchColumn())return;
        $message='La fiabilité des données du périmètre des chantiers actuellement actifs est de '.number_format($rate,1,',',' ').' %, sous le seuil d’alerte de '.number_format(self::THRESHOLD,0,',',' ').' %. Ouvrez la régularisation pour identifier précisément les chantiers actifs concernés et corriger leurs informations.';
        $stmt=$this->pdo->prepare("INSERT INTO system_notifications(created_at,event_key,sub_key,title,message,link_url,recipient_team,channels_json,payload_json) VALUES(NOW(),'chantier_reliability_critical',:sub,'Régularisation urgente des chantiers',:message,'/chantiers/regularisation.php','all','[\"system\",\"push\",\"in_app\"]',:payload)");
        $stmt->execute(['sub'=>$sub,'message'=>$message,'payload'=>json_encode(['reliability_rate'=>$rate,'threshold'=>self::THRESHOLD,'total'=>$total,'compliant'=>$compliant,'incomplete'=>$incomplete],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    }

    private function saveState(bool $below,float $rate,?string $lastAlert):void
    {
        $stmt=$this->pdo->prepare('INSERT INTO chantier_reliability_alert_state(state_key,is_below_threshold,last_rate,last_alert_at) VALUES(:key,:below,:rate,:alert) ON DUPLICATE KEY UPDATE is_below_threshold=VALUES(is_below_threshold),last_rate=VALUES(last_rate),last_alert_at=VALUES(last_alert_at),updated_at=NOW()');
        $stmt->execute(['key'=>'active_chantiers','below'=>$below?1:0,'rate'=>$rate,'alert'=>$lastAlert]);
    }
}
