<?php
declare(strict_types=1);

final class QuoteBillingAlertService
{
    public function __construct(private PDO $pdo)
    {
        $this->ensureSchema();
    }

    public function ensureSchema(): void
    {
        $this->ensureColumn('offre_banamur', 'client_id', 'INT NULL');
        $this->ensureColumn('devis_banamur', 'billing_date', 'DATE NULL');
        $this->ensureColumn('devis_banamur', 'commercial_discount', 'DECIMAL(14,2) NOT NULL DEFAULT 0');
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS devis_billing_alerts (
            id INT NOT NULL AUTO_INCREMENT,
            devis_id INT NOT NULL,
            offre_id INT NULL,
            client_id INT NULL,
            billing_date DATE NOT NULL,
            source VARCHAR(40) NOT NULL DEFAULT 'devis',
            status ENUM('pending','invoiced','dismissed') NOT NULL DEFAULT 'pending',
            created_by_user_id INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id), UNIQUE KEY uniq_devis_billing_alert (devis_id),
            KEY idx_billing_alert_due (status,billing_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    }

    public function schedule(int $devisId, string $source = 'devis', ?int $userId = null): void
    {
        $stmt = $this->pdo->prepare("SELECT d.id,d.numero_devis,d.offre_id,
                   COALESCE(o.client_id,d.client_id) client_id,d.billing_date,
                   COALESCE(NULLIF(TRIM(o.reference_offre),''),NULLIF(TRIM(d.numero_devis),''),CONCAT('Devis #',d.id)) label
            FROM devis_banamur d LEFT JOIN offre_banamur o ON o.id_offre=d.offre_id
            WHERE d.id=:id LIMIT 1");
        $stmt->execute(['id' => $devisId]);
        $quote = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$quote || empty($quote['billing_date'])) {
            throw new DomainException('La date de facturation du devis est obligatoire pour programmer les alertes.');
        }
        $upsert = $this->pdo->prepare("INSERT INTO devis_billing_alerts
            (devis_id,offre_id,client_id,billing_date,source,created_by_user_id)
            VALUES(:devis,:offre,:client,:billing_date,:source,:user)
            ON DUPLICATE KEY UPDATE offre_id=VALUES(offre_id),client_id=VALUES(client_id),
              billing_date=VALUES(billing_date),source=VALUES(source),status=IF(status='invoiced','invoiced','pending')");
        $upsert->execute([
            'devis'=>$devisId,'offre'=>(int)($quote['offre_id']??0)?:null,
            'client'=>(int)($quote['client_id']??0)?:null,'billing_date'=>$quote['billing_date'],
            'source'=>$source,'user'=>$userId ?: null,
        ]);
        $sub = 'devis_' . $devisId;
        $exists = $this->pdo->prepare("SELECT id FROM system_notifications WHERE event_key='quote_billing_due' AND sub_key=:sub LIMIT 1");
        $exists->execute(['sub'=>$sub]);
        if (!$exists->fetchColumn()) {
            $message = sprintf('Le devis %s doit être facturé le %s.', (string)$quote['label'], date('d/m/Y', strtotime((string)$quote['billing_date'])));
            $notify = $this->pdo->prepare("INSERT INTO system_notifications
                (created_at,event_key,sub_key,title,message,link_url,recipient_team,channels_json,payload_json)
                VALUES(NOW(),'quote_billing_due',:sub,'Facture à créer',:message,:url,:team,'[\"in_app\"]',:payload)");
            $payload=json_encode(['devis_id'=>$devisId,'billing_date'=>$quote['billing_date'],'source'=>$source], JSON_UNESCAPED_UNICODE);
            $notify->execute(['sub'=>$sub,'message'=>$message,'url'=>'/facturation/factures_vente.php','team'=>'facturation','payload'=>$payload]);
            $notify->execute(['sub'=>$sub . '_crm','message'=>$message,'url'=>'/crm/dashboard_crm.php','team'=>'crm','payload'=>$payload]);
        }
    }

    public function pending(int $limit = 100): array
    {
        // Le Bureau BANAMUR/FIDEST est un centre interne : il ne génère pas de facture client.
        $this->pdo->exec("UPDATE devis_billing_alerts a
            JOIN chantier_banamur c ON c.devis_id=a.devis_id AND c.archived_at IS NULL
            SET a.status='dismissed'
            WHERE a.status='pending' AND (UPPER(TRIM(c.code))='CH0066' OR UPPER(c.titre) LIKE '%BUREAU BANAMUR%')");
        $sql = "SELECT a.*,d.numero_devis,o.reference_offre,cl.nom_client,d.total_ttc AS quote_total,
                       COALESCE(
                           (SELECT GROUP_CONCAT(DISTINCT NULLIF(TRIM(CONCAT_WS(' · ',c.code,c.titre)),'') ORDER BY c.id SEPARATOR ' / ') FROM chantier_banamur c WHERE c.devis_id=d.id AND c.archived_at IS NULL),
                           (SELECT GROUP_CONCAT(DISTINCT NULLIF(TRIM(CONCAT_WS(' · ',c_history.code,c_history.titre)),'') ORDER BY c_history.id SEPARATOR ' / ') FROM chantier_banamur c_history WHERE c_history.devis_id=d.id),
                           NULLIF(TRIM(o.reference_offre),''),
                           NULLIF(TRIM(d.destine_a),''),
                           'Projet à confirmer'
                       ) AS project_title,
                       COALESCE((SELECT SUM(fv.total_ttc) FROM facture_vente_banamur fv JOIN chantier_banamur c2 ON c2.id=fv.chantier_id WHERE c2.devis_id=d.id AND c2.archived_at IS NULL AND LOWER(TRIM(COALESCE(fv.paiement_statut,'emise')))='payee'),0) AS amount_collected,
                       DATEDIFF(a.billing_date,CURDATE()) days_until_due
                FROM devis_billing_alerts a JOIN devis_banamur d ON d.id=a.devis_id
                LEFT JOIN offre_banamur o ON o.id_offre=a.offre_id
                LEFT JOIN client_banamur cl ON cl.id_client=a.client_id
                WHERE a.status='pending' ORDER BY a.billing_date ASC LIMIT " . max(1,min(500,$limit));
        $rows=$this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach($rows as &$row){
            $total=max(0.0,(float)($row['quote_total']??0));
            $collected=min($total,max(0.0,(float)($row['amount_collected']??0)));
            $row['amount_collected']=$collected;
            $row['amount_remaining_to_invoice']=max(0.0,$total-$collected);
            $row['collection_percent']=$total>0?min(100.0,round($collected/$total*100,1)):0.0;
        }
        unset($row);
        return $rows;
    }

    private function ensureColumn(string $table, string $column, string $definition): void
    {
        $stmt=$this->pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table AND COLUMN_NAME=:column');
        $stmt->execute(['table'=>$table,'column'=>$column]);
        if (!(int)$stmt->fetchColumn()) $this->pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}
