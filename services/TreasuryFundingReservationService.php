<?php
declare(strict_types=1);

require_once __DIR__ . '/ManualTreasuryService.php';
require_once __DIR__ . '/FebWorkflowGuard.php';

final class TreasuryFundingReservationService
{
    public function __construct(private PDO $pdo) { if (!$pdo->inTransaction()) $this->ensureSchema(); }

    public function forecastInvoices(): array
    {
        $reservationsByInvoice=[];
        foreach($this->reservationBalances() as $reservation){
            if(($reservation['source_type']??'')==='forecast_invoice'&&(int)($reservation['invoice_id']??0)>0){
                $invoiceId=(int)$reservation['invoice_id'];
                $reservationsByInvoice[$invoiceId]=($reservationsByInvoice[$invoiceId]??0)+(float)($reservation['remaining_reserved']??0);
            }
        }
        $sql="SELECT f.id_facture_vente invoice_id,COALESCE(NULLIF(f.reference_interne,''),CONCAT('FACT-',f.id_facture_vente)) reference,COALESCE(NULLIF(f.client_company_name,''),'Client') client,COALESCE(f.expected_collection_date,f.invoice_date,DATE(f.created_at)) collection_date,GREATEST(f.total_ttc-COALESCE(f.collected_amount,0),0) amount FROM facture_vente_banamur f WHERE f.paiement_statut='emise' AND NOT EXISTS(SELECT 1 FROM treasury_invoice_collection_cancellations c WHERE c.invoice_id=f.id_facture_vente) HAVING amount>0 ORDER BY collection_date,reference";
        $rows=$this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC)?:[];
        foreach($rows as &$row){$row['amount']=(float)$row['amount'];$row['reserved']=(float)($reservationsByInvoice[(int)$row['invoice_id']]??0);$row['remaining']=max(0,$row['amount']-$row['reserved']);}unset($row);
        return $rows;
    }

    public function realEntriesCoverage(): array
    {
        $dashboard=(new ManualTreasuryService($this->pdo))->dashboard(date('Y-m-d'),date('Y-m-d'));
        $balance=(float)($dashboard['realEntriesBalance']??0);
        $reserved=0.0;foreach($this->reservationBalances() as $reservation)if(($reservation['effective_source']??'')==='real_entries')$reserved+=(float)($reservation['remaining_reserved']??0);
        return ['amount'=>$balance,'reserved'=>$reserved,'remaining'=>max(0,$balance-$reserved)];
    }

    public function reserve(int $febId,string $sourceType,?int $invoiceId,float $amount,string $scheduledDate,int $userId): array
    {
        if (!$this->pdo->inTransaction()) throw new LogicException('La réservation de financement doit être transactionnelle.');
        if (!FebWorkflowGuard::validDate($scheduledDate)) throw new DomainException('Date de financement invalide.');
        if($febId<=0||!is_finite($amount)||$amount<=0)throw new DomainException('Le montant de couverture de la FEB est invalide.');
        if(!in_array($sourceType,['real_entries','forecast_invoice'],true))throw new DomainException('Choisissez la source des fonds couvrant cette FEB.');
        $this->pdo->prepare('SELECT id FROM treasury_funding_reservations WHERE feb_request_id=:feb FOR UPDATE')->execute(['feb'=>$febId]);
        $date=$scheduledDate;
        if($sourceType==='forecast_invoice'){
            if(($invoiceId??0)<=0)throw new DomainException('Choisissez la facture prévisionnelle qui couvrira cette FEB.');
            $stmt=$this->pdo->prepare("SELECT COALESCE(expected_collection_date,invoice_date,DATE(created_at)) collection_date,GREATEST(total_ttc-COALESCE(collected_amount,0),0) amount FROM facture_vente_banamur WHERE id_facture_vente=:id AND paiement_statut='emise' AND NOT EXISTS (SELECT 1 FROM treasury_invoice_collection_cancellations c WHERE c.invoice_id=facture_vente_banamur.id_facture_vente) FOR UPDATE");$stmt->execute(['id'=>$invoiceId]);$invoice=$stmt->fetch(PDO::FETCH_ASSOC);
            if(!$invoice)throw new DomainException('La facture choisie n’est plus disponible à l’encaissement.');
            $used=0.0;foreach($this->reservationBalances() as $reservation)if(($reservation['original_source_type']??'')==='forecast_invoice'&&(int)($reservation['invoice_id']??0)===$invoiceId&&(int)($reservation['feb_request_id']??0)!==$febId)$used+=(float)($reservation['remaining_reserved']??0);$remaining=max(0,(float)$invoice['amount']-$used);
            if($amount>$remaining+0.01)throw new DomainException('Solde non réservé insuffisant sur cette facture : '.number_format($remaining,0,',',' ').' FCFA disponibles.');
            $date=(string)$invoice['collection_date'];
        }else{
            $this->pdo->query("SELECT source_key FROM treasury_funding_reservation_sources WHERE source_key='real_entries' FOR UPDATE")->fetchColumn();
            $coverage=$this->realEntriesCoverage();$own=$this->pdo->prepare("SELECT reserved_amount FROM treasury_funding_reservations WHERE feb_request_id=:feb AND source_type='real_entries'");$own->execute(['feb'=>$febId]);$remaining=$coverage['remaining']+(float)($own->fetchColumn()?:0);
            if($amount>$remaining+0.01)throw new DomainException('Entrées réelles non réservées insuffisantes : '.number_format($remaining,0,',',' ').' FCFA disponibles.');
        }
        if (!FebWorkflowGuard::validDate($date)) throw new DomainException('La date prévue d’encaissement de la facture est invalide.');
        $save=$this->pdo->prepare("INSERT INTO treasury_funding_reservations(feb_request_id,source_type,invoice_id,reserved_amount,scheduled_date,created_by_user_id) VALUES(:feb,:type,:invoice,:amount,:date,:user) ON DUPLICATE KEY UPDATE source_type=VALUES(source_type),invoice_id=VALUES(invoice_id),reserved_amount=VALUES(reserved_amount),scheduled_date=VALUES(scheduled_date),created_by_user_id=VALUES(created_by_user_id),updated_at=NOW()");
        $save->execute(['feb'=>$febId,'type'=>$sourceType,'invoice'=>$sourceType==='forecast_invoice'?$invoiceId:null,'amount'=>$amount,'date'=>$date,'user'=>$userId?:null]);
        return ['scheduled_date'=>$date,'amount'=>$amount,'source_type'=>$sourceType,'invoice_id'=>$sourceType==='forecast_invoice'?$invoiceId:null];
    }

    /** Met à jour une réservation existante après correction du montant d'une FEB. */
    public function synchronizeAmount(int $febId, float $amount, int $userId): bool
    {
        if ($febId <= 0 || $amount <= 0) return false;
        $stmt = $this->pdo->prepare('SELECT source_type, invoice_id, scheduled_date FROM treasury_funding_reservations WHERE feb_request_id=:feb LIMIT 1 FOR UPDATE');
        $stmt->execute(['feb' => $febId]);
        $reservation = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$reservation) return false;

        $this->reserve(
            $febId,
            (string)$reservation['source_type'],
            isset($reservation['invoice_id']) ? (int)$reservation['invoice_id'] : null,
            $amount,
            (string)$reservation['scheduled_date'],
            $userId
        );
        return true;
    }

    public function totals(): array
    {
        $totals=['real'=>0.0,'forecast'=>0.0];
        foreach($this->reservationBalances() as $reservation){
            $key=($reservation['effective_source']??'')==='forecast_invoice'?'forecast':'real';
            $totals[$key]+=(float)($reservation['remaining_reserved']??0);
        }
        return $totals;
    }

    public function activeReservations(): array
    {
        return array_values(array_filter($this->reservationBalances(),static fn(array $reservation): bool=>(float)($reservation['remaining_reserved']??0)>0.004));
    }

    /**
     * Réserves encore actives, calculées sur la famille complète de fiches.
     * Une fiche refusée ou entièrement décaissée libère sa réserve. Lors d'un
     * décaissement partiel, l'héritière non refusée conserve naturellement le solde.
     */
    private function reservationBalances(): array
    {
        $reservations=$this->pdo->query("SELECT r.id,r.feb_request_id,r.source_type original_source_type,r.source_type,r.invoice_id,r.reserved_amount,r.scheduled_date,el.title,el.status feb_status,CASE WHEN r.source_type='forecast_invoice' AND COALESCE(f.paiement_statut,'')='emise' THEN 'forecast_invoice' ELSE 'real_entries' END effective_source,COALESCE(NULLIF(CASE WHEN JSON_VALID(el.payload_json) THEN JSON_UNQUOTE(JSON_EXTRACT(el.payload_json,'$.numero_bon_commande_interne')) ELSE '' END,''),CONCAT('FEB #',r.feb_request_id)) reference,COALESCE(NULLIF(f.reference_interne,''),CONCAT('Facture #',r.invoice_id)) invoice_reference,COALESCE(NULLIF(f.client_company_name,''),'Client non renseigné') invoice_client FROM treasury_funding_reservations r LEFT JOIN employee_legal_requests el ON el.id=r.feb_request_id LEFT JOIN facture_vente_banamur f ON f.id_facture_vente=r.invoice_id ORDER BY r.scheduled_date,r.id")->fetchAll(PDO::FETCH_ASSOC)?:[];
        if(!$reservations)return [];
        $febIds=array_values(array_unique(array_filter(array_map(static fn(array $row): int=>(int)$row['feb_request_id'],$reservations))));
        $columns=$this->pdo->query('SHOW COLUMNS FROM fiche_decaissement')->fetchAll(PDO::FETCH_COLUMN,0)?:[];
        $parentExpr=in_array('parent_num_fiche',$columns,true)&&in_array('code_parentale',$columns,true)?'COALESCE(NULLIF(parent_num_fiche,\'\'),NULLIF(code_parentale,\'\'))':(in_array('parent_num_fiche',$columns,true)?'NULLIF(parent_num_fiche,\'\')':(in_array('code_parentale',$columns,true)?'NULLIF(code_parentale,\'\')':'NULL'));
        $rows=[];$byNumber=[];
        if($febIds){
            $placeholders=implode(',',array_fill(0,count($febIds),'?'));
            $stmt=$this->pdo->prepare("SELECT num_fiche,feb_request_id,$parentExpr parent_num,decaisse,approuve,etat_fiche,montant_fiche FROM fiche_decaissement WHERE feb_request_id IN ($placeholders)");$stmt->execute($febIds);
            foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){$rows[]=$row;$byNumber[(string)$row['num_fiche']]=$row;}
        }
        // Charge les ancêtres puis les héritières : les anciennes fiches ne portent pas toutes feb_request_id.
        for($round=0;$round<30;$round++){
            $needed=[];foreach($byNumber as $row){$parent=trim((string)($row['parent_num']??''));if($parent!==''&&!isset($byNumber[$parent]))$needed[$parent]=$parent;}
            if(!$needed)break;$marks=implode(',',array_fill(0,count($needed),'?'));
            $stmt=$this->pdo->prepare("SELECT num_fiche,feb_request_id,$parentExpr parent_num,decaisse,approuve,etat_fiche,montant_fiche FROM fiche_decaissement WHERE num_fiche IN ($marks)");$stmt->execute(array_values($needed));$added=0;
            foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){$number=(string)$row['num_fiche'];if(!isset($byNumber[$number])){$rows[]=$row;$byNumber[$number]=$row;$added++;}}if(!$added)break;
        }
        $frontier=array_keys($byNumber);
        for($round=0;$frontier&&$round<30;$round++){
            $marks=implode(',',array_fill(0,count($frontier),'?'));
            $stmt=$this->pdo->prepare("SELECT num_fiche,feb_request_id,$parentExpr parent_num,decaisse,approuve,etat_fiche,montant_fiche FROM fiche_decaissement WHERE $parentExpr IN ($marks)");$stmt->execute($frontier);$frontier=[];
            foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){$number=(string)$row['num_fiche'];if(!isset($byNumber[$number])){$rows[]=$row;$byNumber[$number]=$row;$frontier[]=$number;}}
        }
        $rootOf=function(string $number)use(&$byNumber):string{$seen=[];while(isset($byNumber[$number])&&!isset($seen[$number])){$seen[$number]=true;$parent=trim((string)($byNumber[$number]['parent_num']??''));if($parent===''||!isset($byNumber[$parent]))break;$number=$parent;}return $number;};
        $families=[];foreach($byNumber as $number=>$row)$families[$rootOf($number)][]=$row;
        foreach($reservations as &$reservation){
            $febId=(int)$reservation['feb_request_id'];$roots=[];foreach($byNumber as $number=>$row)if((int)($row['feb_request_id']??0)===$febId)$roots[$rootOf($number)]=true;
            $paid=0.0;$pending=false;$linkedFiches=[];foreach(array_keys($roots) as $root)foreach($families[$root]??[] as $row){$number=trim((string)($row['num_fiche']??''));if($number!=='')$linkedFiches[]=$number;$amount=max(0.0,(float)($row['montant_fiche']??0));if((int)($row['decaisse']??0)===1){$paid+=$amount;continue;}$refused=(int)($row['approuve']??0)===2||(int)($row['etat_fiche']??0)===2;if(!$refused)$pending=true;}
            $febStatus=strtolower(trim((string)($reservation['feb_status']??'')));$febRefused=in_array($febStatus,['rejected','refused','cancelled','canceled','annulee','annulée','refusee','refusée'],true);
            $reservation['linked_fiches']=array_values(array_unique($linkedFiches));$reservation['paid_amount']=$paid;$reservation['remaining_reserved']=(!$febRefused&&(!$roots||$pending))?max(0.0,(float)$reservation['reserved_amount']-$paid):0.0;$reservation['source_type']=$reservation['effective_source'];
        }unset($reservation);
        return $reservations;
    }

    private function ensureSchema(): void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS treasury_funding_reservation_sources(source_key VARCHAR(40) PRIMARY KEY,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->pdo->exec("INSERT IGNORE INTO treasury_funding_reservation_sources(source_key) VALUES('real_entries')");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS treasury_funding_reservations(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,feb_request_id BIGINT UNSIGNED NOT NULL,source_type ENUM('real_entries','forecast_invoice') NOT NULL,invoice_id BIGINT UNSIGNED NULL,reserved_amount DECIMAL(18,2) NOT NULL,scheduled_date DATE NOT NULL,created_by_user_id INT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uq_feb(feb_request_id),KEY idx_invoice(invoice_id),KEY idx_source(source_type)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}
