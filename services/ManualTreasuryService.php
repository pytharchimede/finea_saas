<?php
declare(strict_types=1);
require_once __DIR__.'/FebPlanningSynchronizationService.php';
require_once __DIR__.'/ChantierPauseService.php';
require_once __DIR__.'/CommercialProjectFinanceMigrationService.php';

final class ManualTreasuryService
{
    public function __construct(private PDO $pdo)
    {
        // MySQL valide implicitement une transaction dès qu'un DDL est exécuté,
        // même pour CREATE TABLE IF NOT EXISTS. Les validations FEB réutilisent
        // ce service au milieu de leur transaction atomique : le schéma est
        // préparé à l'ouverture de la page, jamais pendant cette transaction.
        if (!$pdo->inTransaction()) {
            $this->ensureSchema();
            (new CommercialProjectFinanceMigrationService($pdo))->ensureSchema();
        }
    }

    public function saveOpening(string $date, float $amount, string $note, int $userId): void
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new DomainException('Date de situation initiale invalide.');
        $stmt = $this->pdo->prepare("INSERT INTO treasury_opening_balances(effective_date,amount,currency,note,created_by_user_id) VALUES(:date,:amount,'XOF',:note,:user) ON DUPLICATE KEY UPDATE amount=VALUES(amount),note=VALUES(note),created_by_user_id=VALUES(created_by_user_id)");
        $stmt->execute(['date'=>$date,'amount'=>$amount,'note'=>$note?:null,'user'=>$userId?:null]);
    }

    public function saveMovement(array $data, int $userId): void
    {
        $direction = in_array(($data['direction'] ?? ''), ['in','out'], true) ? $data['direction'] : '';
        $status = in_array(($data['status'] ?? ''), ['forecast','real'], true) ? $data['status'] : '';
        $date = (string)($data['movement_date'] ?? '');
        $amount = (float)($data['amount'] ?? 0);
        if (!$direction || !$status || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $amount <= 0 || trim((string)($data['label'] ?? '')) === '') throw new DomainException('Complétez le libellé, le montant, la date, le sens et le statut du mouvement.');
        $category=$direction==='in'?trim((string)($data['entry_category']??'')):null;$freeAssignment=$direction==='out'&&!empty($data['free_assignment']);$assignmentLabel=$freeAssignment?trim((string)($data['assignment_label']??'')):null;$chantierId=$direction==='out'&&!$freeAssignment?(int)($data['chantier_id']??0):0;$lineId=$direction==='out'&&!$freeAssignment?(int)($data['ligne_devis_id']??0):0;
        if($direction==='in'&&$category==='')throw new DomainException('Choisissez la catégorie de l’entrée.');
        if($direction==='in')$status='real';
        $repayable=$direction==='in'&&in_array($category,['pret','apport'],true)&&!empty($data['repayment_required']);$repaymentDate=trim((string)($data['repayment_date']??''));
        if($repayable&&(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$repaymentDate)||$repaymentDate<$date))throw new DomainException('Indiquez une date de remboursement valide, égale ou postérieure à la date de réception.');
        if($direction==='out'&&$freeAssignment&&$assignmentLabel==='')throw new DomainException('Renseignez l’affectation du chantier ou projet à venir.');
        if($direction==='out'&&!$freeAssignment&&($chantierId<=0||$lineId<=0))throw new DomainException('Choisissez le chantier et la ligne de devis de la sortie, ou activez l’affectation libre.');
        if($direction==='out'&&!$freeAssignment){$check=$this->pdo->prepare('SELECT 1 FROM ligne_devis_banamur ld JOIN chantier_banamur c ON c.devis_id=ld.devis_id WHERE ld.id=:line AND c.id=:chantier AND c.archived_at IS NULL AND '.ChantierPauseService::activePredicate($this->pdo,'c.id'));$check->execute(['line'=>$lineId,'chantier'=>$chantierId]);if(!$check->fetchColumn())throw new DomainException('La ligne de devis ne correspond pas à un chantier actif.');}
        $stmt=$this->pdo->prepare('INSERT INTO treasury_manual_movements(direction,status,label,amount,movement_date,reference,note,entry_category,chantier_id,ligne_devis_id,assignment_label,repayment_required,repayment_date,repayment_status,created_by_user_id) VALUES(:direction,:status,:label,:amount,:movement_date,:reference,:note,:category,:chantier,:line,:assignment,:repayable,:repayment_date,:repayment_status,:user)');
        $stmt->execute(['direction'=>$direction,'status'=>$status,'label'=>trim((string)$data['label']),'amount'=>$amount,'movement_date'=>$date,'reference'=>trim((string)($data['reference']??''))?:null,'note'=>trim((string)($data['note']??''))?:null,'category'=>$category?:null,'chantier'=>$chantierId?:null,'line'=>$lineId?:null,'assignment'=>$assignmentLabel?:null,'repayable'=>$repayable?1:0,'repayment_date'=>$repayable?$repaymentDate:null,'repayment_status'=>$repayable?'pending':null,'user'=>$userId?:null]);
    }

    public function confirmInvoiceCollection(int $invoiceId,bool $received,?string $newDate,int $userId):void
    {
        if($invoiceId<=0)throw new DomainException('Facture invalide.');
        if(!$received&&(!$newDate||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$newDate)||$newDate<=date('Y-m-d')))throw new DomainException('Choisissez une nouvelle date future d’encaissement.');
        if($received){$stmt=$this->pdo->prepare("UPDATE facture_vente_banamur SET paiement_statut='payee',paid_at=NOW(),collected_amount=CASE WHEN COALESCE(collected_amount,0)>0 THEN collected_amount ELSE total_ttc END,expected_collection_date=CURDATE() WHERE id_facture_vente=:id AND paiement_statut<>'payee'");}
        else{$stmt=$this->pdo->prepare("UPDATE facture_vente_banamur SET expected_collection_date=:date WHERE id_facture_vente=:id AND paiement_statut<>'payee'");$stmt->bindValue(':date',$newDate);}
        $stmt->bindValue(':id',$invoiceId,PDO::PARAM_INT);$stmt->execute();if($stmt->rowCount()===0)throw new RuntimeException('Cette facture est déjà encaissée ou n’existe plus.');
        $this->pdo->prepare('INSERT INTO treasury_collection_confirmations(invoice_id,received,new_expected_date,confirmed_by_user_id) VALUES(:invoice,:received,:date,:user)')->execute(['invoice'=>$invoiceId,'received'=>$received?1:0,'date'=>$received?null:$newDate,'user'=>$userId?:null]);
    }

    public function cancelInvoiceCollection(int $invoiceId,string $reason,int $userId):void
    {
        $reason=trim($reason);if($invoiceId<=0||$reason==='')throw new DomainException('Indiquez le motif de l’annulation du suivi de cette facture.');
        $check=$this->pdo->prepare("SELECT 1 FROM facture_vente_banamur WHERE id_facture_vente=:id AND paiement_statut='emise'");$check->execute(['id'=>$invoiceId]);if(!$check->fetchColumn())throw new DomainException('Cette facture est déjà encaissée ou ne peut plus être annulée du suivi.');
        $stmt=$this->pdo->prepare('INSERT INTO treasury_invoice_collection_cancellations(invoice_id,reason,cancelled_by_user_id) VALUES(:invoice,:reason,:user) ON DUPLICATE KEY UPDATE reason=VALUES(reason),cancelled_by_user_id=VALUES(cancelled_by_user_id),cancelled_at=CURRENT_TIMESTAMP');$stmt->execute(['invoice'=>$invoiceId,'reason'=>$reason,'user'=>$userId?:null]);
    }

    public function authorizeRepayment(int $movementId,int $userId):void
    {
        $this->pdo->beginTransaction();try{$stmt=$this->pdo->prepare("SELECT * FROM treasury_manual_movements WHERE id=:id AND direction='in' AND repayment_required=1 AND repayment_status='pending' FOR UPDATE");$stmt->execute(['id'=>$movementId]);$source=$stmt->fetch(PDO::FETCH_ASSOC);if(!$source)throw new DomainException('Ce remboursement n’est plus en attente d’autorisation.');$insert=$this->pdo->prepare("INSERT INTO treasury_manual_movements(direction,status,label,amount,movement_date,reference,note,entry_category,repayment_source_movement_id,created_by_user_id) VALUES('out','real',:label,:amount,CURDATE(),:reference,:note,'remboursement_pret_sortant',:source,:user)");$insert->execute(['label'=>'Remboursement prêt sortant · '.$source['label'],'amount'=>$source['amount'],'reference'=>$source['reference'],'note'=>'Remboursement autorisé depuis l’entrée de trésorerie #'.$movementId,'source'=>$movementId,'user'=>$userId?:null]);$this->pdo->prepare("UPDATE treasury_manual_movements SET repayment_status='authorized',repayment_authorized_at=NOW(),repayment_authorized_by=:user WHERE id=:id")->execute(['user'=>$userId?:null,'id'=>$movementId]);$this->pdo->commit();}catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw$e;}
    }

    public function borrowVat(float $amount,string $borrowDate,string $dueDate,string $reason,int $userId):void
    {
        $reason=trim($reason);
        if($amount<=0)throw new DomainException('Le montant de TVA à mobiliser doit être positif.');
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$borrowDate)||$borrowDate>date('Y-m-d'))throw new DomainException('La date d’emprunt TVA doit être valide et non future.');
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$dueDate)||$dueDate<$borrowDate)throw new DomainException('La date de restitution TVA doit être égale ou postérieure à la date d’emprunt.');
        if($reason==='')throw new DomainException('Le motif de mobilisation de la TVA est obligatoire.');
        $this->syncVatSnapshots($userId);
        $summary=$this->vatSummary();
        if($amount>(float)$summary['vat_free_to_borrow']+0.001)throw new DomainException('Mobilisation impossible : seulement '.number_format((float)$summary['vat_free_to_borrow'],0,',',' ').' FCFA de TVA restent mobilisables.');
        $reference='TVA-'.date('Ymd-His');
        $stmt=$this->pdo->prepare("INSERT INTO treasury_loans(lender_name,loan_type,reference,principal_amount,vat_source_amount,received_date,due_date,vat_repayment_due_date,interest_rate,repaid_amount,status,vat_repayment_status,note,created_by_user_id) VALUES('Réserve TVA BANAMUR','vat_credit',:reference,:amount,:source,:received,:due,:vat_due,0,0,'active','pending',:note,:user)");
        $stmt->execute(['reference'=>$reference,'amount'=>$amount,'source'=>$amount,'received'=>$borrowDate,'due'=>$dueDate,'vat_due'=>$dueDate,'note'=>$reason,'user'=>$userId?:null]);
    }

    public function repayVatLoan(int $loanId,int $userId):void
    {
        if($loanId<=0)throw new DomainException('Emprunt TVA invalide.');
        $this->pdo->beginTransaction();
        try{
            // Même verrou de trésorerie que les transferts : deux restitutions
            // ne doivent pas consommer simultanément le même disponible.
            $this->pdo->query("SELECT id FROM treasury_opening_balances WHERE effective_date<=CURDATE() ORDER BY effective_date DESC,id DESC LIMIT 1 FOR UPDATE")->fetchColumn();
            $stmt=$this->pdo->prepare("SELECT * FROM treasury_loans WHERE id=:id AND loan_type='vat_credit' AND status='active' AND COALESCE(vat_repayment_status,'pending')='pending' FOR UPDATE");
            $stmt->execute(['id'=>$loanId]);
            $loan=$stmt->fetch(PDO::FETCH_ASSOC);
            if(!$loan)throw new DomainException('Cet emprunt TVA n’est plus à restituer.');
            $amount=max(0,(float)$loan['principal_amount']-(float)$loan['repaid_amount']);
            if($amount<=0)throw new DomainException('Cet emprunt TVA est déjà soldé.');
            $available=$this->dashboard(date('Y-m-01'),date('Y-m-t'))['available']??0;
            if($amount>(float)$available+0.001)throw new DomainException('Restitution impossible : le disponible réel est insuffisant.');
            $this->pdo->prepare("UPDATE treasury_loans SET repaid_amount=principal_amount,status='repaid',vat_repayment_status='repaid',vat_repaid_at=NOW(),vat_repaid_by=:user,updated_at=NOW() WHERE id=:id")->execute(['user'=>$userId?:null,'id'=>$loanId]);
            $this->pdo->commit();
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw$e;}
    }

    public function deferVatLoan(int $loanId,string $newDate,string $reason,int $userId):void
    {
        $reason=trim($reason);
        if($loanId<=0)throw new DomainException('Emprunt TVA invalide.');
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$newDate)||$newDate<=date('Y-m-d'))throw new DomainException('Choisissez une nouvelle date future de restitution.');
        if($reason==='')throw new DomainException('Le motif du report est obligatoire.');
        $this->pdo->beginTransaction();
        try{
            $stmt=$this->pdo->prepare("SELECT id,COALESCE(vat_repayment_due_date,due_date) due_date FROM treasury_loans WHERE id=:id AND loan_type='vat_credit' AND status='active' AND COALESCE(vat_repayment_status,'pending')='pending' FOR UPDATE");
            $stmt->execute(['id'=>$loanId]);$loan=$stmt->fetch(PDO::FETCH_ASSOC);
            if(!$loan)throw new DomainException('Cet emprunt TVA n’est plus à reporter.');
            $this->pdo->prepare("INSERT INTO treasury_vat_loan_deferrals(loan_id,previous_due_date,new_due_date,reason,created_by_user_id) VALUES(:loan,:previous,:new,:reason,:user)")->execute(['loan'=>$loanId,'previous'=>$loan['due_date'],'new'=>$newDate,'reason'=>$reason,'user'=>$userId?:null]);
            $this->pdo->prepare("UPDATE treasury_loans SET due_date=:date,vat_repayment_due_date=:date,updated_at=NOW() WHERE id=:id")->execute(['date'=>$newDate,'id'=>$loanId]);
            $this->pdo->commit();
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw$e;}
    }

    public function remitVat(float $amount,string $paymentDate,string $periodStart,string $periodEnd,string $reference,string $note,int $userId):void
    {
        $reference=trim($reference);$note=trim($note);
        if($amount<=0)throw new DomainException('Le montant de TVA à reverser doit être positif.');
        foreach([$paymentDate,$periodStart,$periodEnd] as $date)if(!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/',$date))throw new DomainException('Les dates de reversement TVA sont invalides.');
        if($paymentDate>date('Y-m-d'))throw new DomainException('La date de reversement ne peut pas être future.');
        if($periodEnd<$periodStart)throw new DomainException('La période fiscale est invalide.');
        $vat=$this->vatSummary();
        if($amount>(float)$vat['vat_protected']+0.001)throw new DomainException('Reversement impossible : '.number_format((float)$vat['vat_protected'],0,',',' ').' FCFA seulement sont actuellement protégés et reversables. La TVA empruntée doit d’abord être restituée.');
        $stmt=$this->pdo->prepare("INSERT INTO treasury_vat_remittances(period_start,period_end,payment_date,amount,reference,note,created_by_user_id) VALUES(:start,:end,:date,:amount,:reference,:note,:user)");
        $stmt->execute(['start'=>$periodStart,'end'=>$periodEnd,'date'=>$paymentDate,'amount'=>$amount,'reference'=>$reference?:null,'note'=>$note?:null,'user'=>$userId?:null]);
    }

    public function vatSummary():array
    {
        $this->syncVatSnapshots((int)($_SESSION['user_id']??0));
        $snapshots=$this->pdo->query("SELECT s.*,f.reference_interne,f.client_company_name,f.chantier_id,c.code chantier_code,c.titre chantier_title FROM treasury_vat_invoice_snapshots s JOIN facture_vente_banamur f ON f.id_facture_vente=s.invoice_id LEFT JOIN chantier_banamur c ON c.id=f.chantier_id ORDER BY s.snapshot_date DESC,s.id DESC")->fetchAll(PDO::FETCH_ASSOC)?:[];
        $vatCollected=array_sum(array_map(static fn($r)=>(float)$r['amount_vat'],$snapshots));
        $remitted=(float)$this->pdo->query("SELECT COALESCE(SUM(amount),0) FROM treasury_vat_remittances")->fetchColumn();
        $loans=$this->pdo->query("SELECT * FROM treasury_loans WHERE loan_type='vat_credit' AND status<>'cancelled' ORDER BY COALESCE(vat_repayment_due_date,due_date),id")->fetchAll(PDO::FETCH_ASSOC)?:[];
        $borrowed=0.0;$outstanding=0.0;
        foreach($loans as $loan){$principal=(float)$loan['principal_amount'];$repaid=(float)$loan['repaid_amount'];$borrowed+=$principal;$outstanding+=max(0,$principal-$repaid);}
        $reserveGross=max(0,$vatCollected-$remitted);
        return [
            'vat_collected'=>$vatCollected,
            'vat_remitted'=>$remitted,
            'vat_reserve_gross'=>$reserveGross,
            'vat_borrowed_total'=>$borrowed,
            'vat_outstanding'=>$outstanding,
            'vat_protected'=>max(0,$reserveGross-$outstanding),
            'vat_free_to_borrow'=>max(0,$reserveGross-$outstanding),
            'snapshots'=>$snapshots,
            'loans'=>$loans,
            'due_loans'=>array_values(array_filter($loans,static fn($l)=>(string)($l['status']??'')==='active'&&(string)($l['vat_repayment_status']??'pending')==='pending'&&!empty($l['vat_repayment_due_date']??$l['due_date'])&&($l['vat_repayment_due_date']??$l['due_date'])<=date('Y-m-d'))),
        ];
    }

    /** Même remise globale en montant que les exports de ventes ; TVA répartie au prorata du HT. */
    public static function vatSnapshotAmounts(array $row,array $taxRates=[]):array
    {
        $decoded=json_decode((string)($row['items_json']??'[]'),true);
        $items=is_array($decoded)&&isset($decoded['items'])&&is_array($decoded['items'])
            ?$decoded['items']:(is_array($decoded)?$decoded:[]);
        $ht=0.0;$vat=0.0;
        foreach($items as $item){
            if(!is_array($item))continue;
            $qty=is_numeric($item['quantity']??null)?(float)$item['quantity']:1.0;
            $amount=is_numeric($item['amount']??null)?(float)$item['amount']:0.0;
            $line=max(0,$qty*$amount);
            $code=is_array($item['taxes']??null)?(string)($item['taxes'][0]??''):'';
            $rate=is_numeric($item['tax_rate']??null)?(float)$item['tax_rate']
                :(is_numeric($taxRates[$code]??null)?(float)$taxRates[$code]:0.0);
            $ht+=$line;
            if(empty($row['tax_not_invoiced']))$vat+=$line*$rate;
        }
        $discount=min($ht,max(0,(float)($row['discount']??0)));
        $discountRatio=$ht>0?($ht-$discount)/$ht:0.0;
        $ttc=max(0,(float)($row['total_ttc']??0));
        $collected=(float)(($row['collected_amount']??0)>0?$row['collected_amount']:$ttc);
        $collectionRatio=$ttc>0?min(1,max(0,$collected/$ttc)):1.0;
        return ['ht'=>round(($ht-$discount)*$collectionRatio,2),
            'vat'=>round($vat*$discountRatio*$collectionRatio,2),
            'collected'=>$collected];
    }

    private function syncVatSnapshots(int $userId):void
    {
        try{
            $rows=$this->pdo->query("SELECT id_facture_vente,paid_at,collected_amount,total_ttc,discount,tax_not_invoiced,items_json FROM facture_vente_banamur WHERE paiement_statut='payee' AND paid_at IS NOT NULL AND NOT EXISTS(SELECT 1 FROM treasury_vat_invoice_snapshots s WHERE s.invoice_id=facture_vente_banamur.id_facture_vente)")->fetchAll(PDO::FETCH_ASSOC)?:[];
            if(!$rows)return;
            $taxRates=[];$taxPath=dirname(__DIR__).'/config/taxes.php';if(is_file($taxPath)){$loaded=require $taxPath;$taxRates=is_array($loaded)?$loaded:[];}
            $insert=$this->pdo->prepare("INSERT IGNORE INTO treasury_vat_invoice_snapshots(invoice_id,snapshot_date,amount_ht,amount_vat,amount_ttc,collected_amount,tax_not_invoiced,source_items_json,created_by_user_id) VALUES(:invoice,:date,:ht,:vat,:ttc,:collected,:tax_not_invoiced,:items,:user)");
            foreach($rows as $row){
                $amounts=self::vatSnapshotAmounts($row,$taxRates);
                $insert->execute(['invoice'=>$row['id_facture_vente'],'date'=>substr((string)$row['paid_at'],0,10),'ht'=>$amounts['ht'],'vat'=>$amounts['vat'],'ttc'=>$amounts['collected'],'collected'=>$amounts['collected'],'tax_not_invoiced'=>(int)$row['tax_not_invoiced'],'items'=>$row['items_json'],'user'=>$userId?:null]);
            }
        }catch(Throwable $e){error_log('VAT snapshot sync: '.$e->getMessage());}
    }

    public function transferToAvailable(float $amount,string $date,string $reference,string $note,int $userId):void
    {
        if($amount<=0||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)||$date>date('Y-m-d'))throw new DomainException('Indiquez un montant positif et une date de transfert non future.');
        $this->pdo->beginTransaction();try{$opening=$this->pdo->query("SELECT * FROM treasury_opening_balances WHERE effective_date<=CURDATE() ORDER BY effective_date DESC,id DESC LIMIT 1 FOR UPDATE")->fetch(PDO::FETCH_ASSOC)?:null;$start=(string)($opening['effective_date']??date('Y-m-d'));if($date<$start)throw new DomainException('La date du transfert ne peut pas précéder la situation initiale du '.$start.'.');$invoiceRows=$this->invoiceRows($start,$date,true);$invoiceTtc=array_sum(array_column($invoiceRows,'amount'));$vat=$this->vatSummary();$invoiceVat=$this->vatCollectedBetween($start,$date);$invoiceNet=max(0,$invoiceTtc-$invoiceVat);$resources=(float)($opening['amount']??0)+$this->sum($this->manualRows($start,$date),'in','real')+$invoiceNet+$this->vatBorrowedIntoRealEntries($start,$date);$transferred=$this->netTransferTotal($start,$date);$remaining=$resources-$transferred;$transferable=max(0,$remaining);if($amount>$transferable+0.001)throw new DomainException('Transfert impossible : '.number_format($transferable,0,',',' ').' FCFA seulement restent dans les entrées réelles hors TVA.');$stmt=$this->pdo->prepare("INSERT INTO treasury_fund_transfers(amount,transfer_date,reference,note,transfer_type,created_by_user_id) VALUES(:amount,:date,:reference,:note,'to_available',:user)");$stmt->execute(['amount'=>$amount,'date'=>$date,'reference'=>trim($reference)?:null,'note'=>trim($note)?:null,'user'=>$userId?:null]);$this->pdo->commit();}catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw$e;}
    }

    public function returnToRealEntries(float $amount,string $date,string $reference,string $note,int $userId):void
    {
        if($amount<=0||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)||$date>date('Y-m-d'))throw new DomainException('Indiquez un montant positif et une date de retour non future.');
        $this->pdo->beginTransaction();try{$opening=$this->pdo->query("SELECT * FROM treasury_opening_balances WHERE effective_date<=CURDATE() ORDER BY effective_date DESC,id DESC LIMIT 1 FOR UPDATE")->fetch(PDO::FETCH_ASSOC)?:null;$start=(string)($opening['effective_date']??date('Y-m-d'));if($date<$start)throw new DomainException('La date du retour ne peut pas précéder la situation initiale.');$available=$this->netTransferTotal($start,$date)-$this->sum($this->manualRows($start,$date),'out','real')-array_sum(array_column($this->ficheRows($start,$date,true),'amount'));if($amount>$available+0.001)throw new DomainException('Retour impossible : le disponible réel est limité à '.number_format(max(0,$available),0,',',' ').' FCFA.');$stmt=$this->pdo->prepare("INSERT INTO treasury_fund_transfers(amount,transfer_date,reference,note,transfer_type,created_by_user_id) VALUES(:amount,:date,:reference,:note,'to_real_entries',:user)");$stmt->execute(['amount'=>$amount,'date'=>$date,'reference'=>trim($reference)?:null,'note'=>trim($note)?:null,'user'=>$userId?:null]);$transferId=(int)$this->pdo->lastInsertId();$vatInAvailable=$this->vatProtectedInAvailable($date);$vatShare=min($amount,$vatInAvailable);if($vatShare>0)$this->recordVatAllocation($transferId,'to_real_entries',$vatShare,$date,trim($reference),trim($note),$userId);$this->pdo->commit();}catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw$e;}
    }

    public function catalogs(): array
    {
        $chantiers=$this->pdo->query("SELECT id,devis_id,code,titre,CONCAT(COALESCE(code,''),' — ',COALESCE(titre,'')) label FROM chantier_banamur WHERE archived_at IS NULL AND ".ChantierPauseService::activePredicate($this->pdo,'id')." ORDER BY code,titre")->fetchAll(PDO::FETCH_ASSOC)?:[];
        $lines=$this->pdo->query("SELECT ld.id,ld.devis_id,ld.designation label FROM ligne_devis_banamur ld JOIN chantier_banamur c ON c.devis_id=ld.devis_id AND c.archived_at IS NULL AND ".ChantierPauseService::activePredicate($this->pdo,'c.id')." GROUP BY ld.id,ld.devis_id,ld.designation ORDER BY ld.designation")->fetchAll(PDO::FETCH_ASSOC)?:[];
        return ['chantiers'=>$chantiers,'lines'=>$lines,'entry_categories'=>['encaissement_facture'=>'Encaissement de facture','pret'=>'Prêt reçu','apport'=>'Apport de trésorerie','remboursement'=>'Remboursement reçu','subvention'=>'Subvention','autre'=>'Autre entrée']];
    }

    public function dashboard(string $from, string $to): array
    {
        $today=date('Y-m-d');
        $openingStmt=$this->pdo->prepare("SELECT * FROM treasury_opening_balances WHERE effective_date<=:today ORDER BY effective_date DESC,id DESC LIMIT 1");
        $openingStmt->execute(['today'=>$today]);
        $opening=$openingStmt->fetch(PDO::FETCH_ASSOC)?:null;
        $openingDate=(string)($opening['effective_date']??$today);
        $manual=$this->manualRows($from,$to);
        $manualSinceOpening=$this->manualRows($openingDate,$today);
        $invoiceRealToday=$this->invoiceRows($openingDate,$today,true);
        $invoiceTtcToday=array_sum(array_column($invoiceRealToday,'amount'));
        $invoiceVatToday=$this->vatCollectedBetween($openingDate,$today);
        $invoiceNetToday=max(0,$invoiceTtcToday-$invoiceVatToday);
        $vatBorrowedIntoEntries=$this->vatBorrowedIntoRealEntries($openingDate,$today);
        $realInToday=$this->sum($manualSinceOpening,'in','real')+$invoiceNetToday+$vatBorrowedIntoEntries;
        $realOutToday=$this->sum($manualSinceOpening,'out','real');
        $paidFichesToday=$this->ficheRows($openingDate,$today,true);
        $paidFichesPeriod=$this->ficheRows($from,$to,true);
        $plannedFichesPeriod=$this->ficheRows($from,$to,false);
        // Les cartes de sorties suivent les FEB encore à traiter par la finance,
        // même lorsqu'aucune fiche de décaissement n'a encore été créée.
        $febToDateRows = $this->pendingFebRows('2000-01-01', $to);

        $futureStart = date('Y-m-d', strtotime($to . ' +1 day'));
        $futureFebRows = $futureStart <= '2099-12-31'
            ? $this->pendingFebRows($futureStart, '2099-12-31')
            : [];

        $validationPurchaseRows = array_values(array_filter(
            $this->ficheRows('2000-01-01', $today, false, 'all'),
            static fn (array $row): bool =>
                (int) ($row['conforme'] ?? 0) === 1
                && (int) ($row['approuve'] ?? 0) === 1
                && (int) ($row['etat_fiche'] ?? 0) === 0
                && (string) ($row['document_completion_status'] ?? 'complete') !== 'returned'
        ));
        $invoiceRealRows=$this->invoiceRows($from,$to,true);
        // Les échéances dépassées restent toujours visibles. Les échéances à venir
        // suivent en revanche strictement la période choisie par l'utilisateur.
        $forecastByInvoice=[];
        foreach($this->invoiceRows($from,$to,false) as $row)$forecastByInvoice[(int)$row['invoice_id']]=$row;
        $yesterday=date('Y-m-d',strtotime('-1 day'));
        foreach($this->invoiceRows('2000-01-01',$yesterday,false) as $row)$forecastByInvoice[(int)$row['invoice_id']]=$row;
        $invoiceForecastRows=array_values($forecastByInvoice);
        $recurringForecastRows=$this->recurringRows($from,$to);
        $projectedDebourseRows=$this->projectedDebourseRows($from,$to);
        $paidToday=array_sum(array_column($paidFichesToday,'amount'));
        $realResourcesToday=(float)($opening['amount']??0)+$realInToday;
        $transfersSinceOpening=$this->transferRows($openingDate,$today);$transferredToday=$this->netTransferAmount($transfersSinceOpening);
        $vat=$this->vatSummary();
        $vatRemittedCash=(float)$vat['vat_remitted'];
        // La TVA est un portefeuille fiscal autonome : elle n'entre jamais dans
        // les entrées réelles sauf lorsqu'un emprunt TVA est explicitement créé.
        $availableRealBeforeVat=$transferredToday-$realOutToday-$paidToday;
        $availableBeforeVat=$availableRealBeforeVat;
        $available=$availableRealBeforeVat;
        $realEntriesBalance=$realResourcesToday-$transferredToday;
        $vatProtectedInEntries=0.0;
        $realEntriesTransferable=max(0,$realEntriesBalance);
        $totals=[
            'manual_real_in'=>$this->sum($manual,'in','real'),
            'manual_forecast_in'=>$this->sum($manual,'in','forecast'),
            'manual_real_out'=>$this->sum($manual,'out','real'),
            'manual_forecast_out'=>$this->sum($manual,'out','forecast'),
            'invoice_real_in'=>max(0,array_sum(array_column($invoiceRealRows,'amount'))-$this->vatCollectedBetween($from,$to)),
            'invoice_forecast_in'=>array_sum(array_column($invoiceForecastRows,'amount')),
            'fiche_real_out'=>array_sum(array_column($paidFichesPeriod,'amount')),
            'bci_forecast_out'=>array_sum(array_column($plannedFichesPeriod,'amount')),
            'recurring_forecast_out'=>array_sum(array_column($recurringForecastRows,'amount')),
            'feb_to_date' => array_sum(array_column($febToDateRows, 'amount')),
            'future_feb' => array_sum(array_column($futureFebRows, 'amount')),
            'validation_purchase' => array_sum(array_column($validationPurchaseRows, 'amount')),
            'projected_debourse' => array_sum(array_column($projectedDebourseRows, 'amount')),
        ];
        $totals['fiche_forecast_out']=$totals['bci_forecast_out']; // compatibilité des vues existantes
        $openingInPeriod=$opening&&$from<=(string)$opening['effective_date']&&$to>=(string)$opening['effective_date']?(float)$opening['amount']:0.0;
        $transferRows=$this->transferRows($from,$to);$totals['transferred_to_available']=$this->netTransferAmount($transferRows);$totals['returned_to_real_entries']=array_sum(array_column(array_filter($transferRows,static fn($r)=>($r['transfer_type']??'')==='to_real_entries'),'amount'));
        $totals['real_in']=$totals['manual_real_in']+$totals['invoice_real_in']+$openingInPeriod-$totals['transferred_to_available'];
        $totals['forecast_in']=$totals['invoice_forecast_in'];
        $totals['real_out'] = $totals['feb_to_date'];
        $totals['forecast_out'] = $totals['future_feb'];
        $totals['projected_available']=$available; // Compatibilité des anciens exports : aucune projection distincte n'est désormais calculée.
        $availableBreakdown=['opening'=>(float)($opening['amount']??0),'manual_real_in'=>$this->sum($manualSinceOpening,'in','real'),'invoice_real_in'=>$invoiceNetToday,'invoice_ttc_collected'=>$invoiceTtcToday,'invoice_vat_separated'=>$invoiceVatToday,'vat_borrowed_into_entries'=>$vatBorrowedIntoEntries,'real_in_total'=>$realInToday,'real_resources'=>$realResourcesToday,'transferred_to_available'=>$transferredToday,'real_entries_balance'=>$realEntriesBalance,'manual_real_out'=>$realOutToday,'fiche_real_out'=>$paidToday,'real_out_total'=>$realOutToday+$paidToday+$vatRemittedCash,'vat_remitted'=>$vatRemittedCash,'available_real_before_vat'=>$availableRealBeforeVat,'available_before_vat'=>$availableBeforeVat,'vat_protected'=>(float)$vat['vat_protected'],'vat_protected_in_available'=>0.0,'vat_protected_in_entries'=>$vatProtectedInEntries,'real_entries_transferable'=>$realEntriesTransferable,'real_net'=>$available,'opening_entered'=>(bool)$opening];
        $realBalance=$available;
        $real_balance=$realBalance;
        $dueInvoiceAlerts=array_values(array_filter($this->invoiceRows('2000-01-01',$today,false),static fn(array $r):bool=>(string)($r['movement_date']??'')<=$today));$repaymentAlerts=array_values(array_filter($this->manualRows('2000-01-01','2099-12-31'),static fn(array $r):bool=>!empty($r['repayment_required'])&&($r['repayment_status']??'')==='pending'&&!empty($r['repayment_date'])&&$r['repayment_date']<=date('Y-m-d')));
        return compact('opening','available','realBalance','real_balance','realEntriesBalance','availableBreakdown','totals','manual','transferRows','transfersSinceOpening','paidFichesPeriod','plannedFichesPeriod','febToDateRows','futureFebRows','validationPurchaseRows','projectedDebourseRows','invoiceRealRows','invoiceForecastRows','recurringForecastRows','dueInvoiceAlerts','repaymentAlerts','vat','from','to');
    }

    /**
     * Reste à payer de toutes les lignes de déboursé des chantiers actifs.
     * Une fiche/FEB refusée ne consomme pas le budget. À l'inverse, une FEB
     * soumise (même reportée) reste engagée jusqu'à son refus ou sa conversion
     * en fiche, afin de réserver le montant initialement prévu sans double compte.
     */
    private function projectedDebourseRows(string $from,string $to):array
    {
        $columns=$this->pdo->query('SHOW COLUMNS FROM chantier_banamur')->fetchAll(PDO::FETCH_COLUMN,0)?:[];
        $legacyPause=in_array('paused_at',$columns,true)?' AND c.paused_at IS NULL':'';
        $sql="SELECT ldb.id ligne_debourse_id,ldb.debourse_id,ldb.designation ligne_debourse_label,
                    ldb.categorie ligne_debourse_category,ldb.montant budget_amount,
                    ldb.date_debut movement_date,ldb.date_fin,
                    db.montant_debourse debourse_amount,ld.designation ligne_devis_label,
                    c.code chantier_code,c.titre chantier_title,
                    COALESCE(fs.paid_amount,0) paid_amount,
                    COALESCE(fs.pending_amount,0)+COALESCE(rs.request_amount,0) committed_amount
             FROM ligne_debourse_banamur ldb
             JOIN debourse_banamur db ON db.id=ldb.debourse_id
             JOIN ligne_devis_banamur ld ON ld.id=db.ligne_devis_id
             JOIN chantier_banamur c ON c.devis_id=db.devis_id AND c.archived_at IS NULL AND c.closed_date IS NULL{$legacyPause} AND ".ChantierPauseService::activePredicate($this->pdo,'c.id')."
             LEFT JOIN (
                 SELECT f.ligne_debourse_id,
                        SUM(CASE WHEN COALESCE(f.decaisse,0)=1 THEN f.montant_fiche ELSE 0 END) paid_amount,
                        SUM(CASE WHEN COALESCE(f.decaisse,0)=0 AND COALESCE(f.approuve,0)<>2
                                      AND COALESCE(f.etat_fiche,0)<>2
                                      AND COALESCE(f.document_completion_status,'complete')<>'returned'
                                 THEN f.montant_fiche ELSE 0 END) pending_amount
                 FROM fiche_decaissement f
                 WHERE f.ligne_debourse_id IS NOT NULL
                 GROUP BY f.ligne_debourse_id
             ) fs ON fs.ligne_debourse_id=ldb.id
             LEFT JOIN (
                 SELECT fdl.ligne_debourse_id,
                        SUM(COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(er.payload_json,'$.montant')) AS DECIMAL(18,2)),
                                     CAST(JSON_UNQUOTE(JSON_EXTRACT(er.payload_json,'$.amount')) AS DECIMAL(18,2)),0)) request_amount
                 FROM feb_disbursement_links fdl
                 JOIN employee_legal_requests er ON er.id=fdl.feb_request_id
                    AND er.request_type='feb' AND er.status IN ('submitted','approved')
                 WHERE fdl.ligne_debourse_id IS NOT NULL
                   AND NOT EXISTS(SELECT 1 FROM fiche_decaissement f WHERE f.feb_request_id=er.id)
                 GROUP BY fdl.ligne_debourse_id
             ) rs ON rs.ligne_debourse_id=ldb.id
             WHERE COALESCE(ldb.planning_archived_at,'')=''
             ORDER BY c.code,c.titre,ld.designation,ldb.date_debut,ldb.designation";
        try{
            $stmt=$this->pdo->prepare($sql);$stmt->execute();
            $rows=[];
            foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){
                $budget=(float)$row['budget_amount'];$paid=(float)$row['paid_amount'];$committed=(float)$row['committed_amount'];
                $remaining=max(0,$budget-$paid-$committed);
                if($remaining<=0)continue;
                $row['amount']=$remaining;
                $row['label']=(string)($row['ligne_debourse_label']?:'Ligne de déboursé');
                $row['reference']='DEB-'.$row['debourse_id'].' / L'.$row['ligne_debourse_id'];
                $row['request_details']='Prévision initiale '.$budget.' · déjà décaissé '.$paid.' · FEB/fiches engagées '.$committed.' · reste à payer '.$remaining;
                $row['movement_source']='projected_debourse';$row['direction']='out';
                $rows[]=$row;
            }
            return $rows;
        }catch(Throwable $e){error_log('Treasury projected disbursement: '.$e->getMessage());return[];}
    }

    public function suggestPaymentDate(float $amount, int $days=365): ?string
    {
        $amount=max(0,$amount);$today=date('Y-m-d');$end=date('Y-m-d',strtotime('+'.$days.' days'));
        $data=$this->dashboard($today,$end);$balance=(float)$data['realBalance'];$flows=[];
        foreach((array)$data['manual'] as $r)if(($r['status']??'')==='forecast')$flows[$r['movement_date']]=($flows[$r['movement_date']]??0)+(($r['direction']??'')==='in'?1:-1)*(float)$r['amount'];
        foreach((array)$data['invoiceForecastRows'] as $r)$flows[$r['movement_date']]=($flows[$r['movement_date']]??0)+(float)$r['amount'];
        foreach(array_merge((array)$data['plannedFichesPeriod'],(array)$data['recurringForecastRows']) as $r)$flows[$r['movement_date']]=($flows[$r['movement_date']]??0)-(float)$r['amount'];
        for($ts=strtotime('+1 day');$ts<=strtotime($end);$ts+=86400){$d=date('Y-m-d',$ts);$balance+=(float)($flows[$d]??0);if($balance>=$amount)return$d;}return null;
    }

    public function deferPayments(array $ficheNumbers,string $type,?string $until,string $reason,int $userId): void
    {
        $type=in_array($type,['processing','payment'],true)?$type:'';$numbers=array_values(array_unique(array_filter(array_map('trim',$ficheNumbers))));
        if(!$type||!$numbers)throw new DomainException('Sélection de report invalide.');
        if($type==='payment'&&(!$until||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$until)))throw new DomainException('Choisissez la date du report pour décaissement.');
        $this->pdo->beginTransaction();try{$insert=$this->pdo->prepare('INSERT INTO treasury_payment_deferrals(fiche_number,deferral_type,deferred_until,reason,created_by_user_id) VALUES(?,?,?,?,?)');$lookup=$this->pdo->prepare('SELECT feb_request_id FROM fiche_decaissement WHERE num_fiche=? LIMIT 1');$sync=new FebPlanningSynchronizationService($this->pdo);$syncedFebs=[];foreach($numbers as $number){$insert->execute([$number,$type,$until,$reason?:'Insuffisance de trésorerie',$userId?:null]);if($type==='payment'){$lookup->execute([$number]);$febId=(int)$lookup->fetchColumn();if($febId>0&&!isset($syncedFebs[$febId])){$sync->synchronizeDate($febId,(string)$until,'Planning reporté pour indisponibilité de fonds.',$userId);$syncedFebs[$febId]=true;}elseif($febId<=0){$this->pdo->prepare('UPDATE fiche_decaissement SET date_decaissement_minimum=? WHERE num_fiche=? AND COALESCE(decaisse,0)=0')->execute([$until,$number]);}}}$this->pdo->commit();}catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw$e;}
    }

    private function manualRows(string $from,string $to): array
    {
        $stmt=$this->pdo->prepare("SELECT m.*,m.amount amount_value,'manual' movement_source,c.code chantier_code,c.titre chantier_title,ld.designation ligne_devis_label,TRIM(CONCAT(COALESCE(u.prenom,''),' ',COALESCE(u.nom,''))) requester_name,COALESCE(NULLIF(p.photo_personnel_soignant,''),NULLIF(u.photo,'')) requester_photo,p.tel_personnel_soignant requester_phone FROM treasury_manual_movements m LEFT JOIN chantier_banamur c ON c.id=m.chantier_id LEFT JOIN ligne_devis_banamur ld ON ld.id=m.ligne_devis_id LEFT JOIN user_devis_banamur u ON u.id=m.created_by_user_id LEFT JOIN personnel p ON p.id_personnel_soignant=u.personnel_id WHERE m.cancelled_at IS NULL AND (m.direction='in' OR ".ChantierPauseService::activePredicate($this->pdo,'m.chantier_id').") AND m.movement_date BETWEEN :from AND :to ORDER BY m.movement_date DESC,m.id DESC");$stmt->execute(compact('from','to'));$rows=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];foreach($rows as &$row)$row['requester_photo']=$this->resolveMediaPath((string)($row['requester_photo']??''));unset($row);
        // Une restitution est une sortie du disponible vers la réserve fiscale.
        // Le prêt soldé et horodaté est la pièce d'origine : pas de copie dans
        // les mouvements manuels, donc ni doublon ni migration des prêts anciens.
        $rows=array_merge($rows,$this->vatLoanRepaymentRows($from,$to));
        usort($rows,static fn(array $a,array $b):int=>strcmp((string)$b['movement_date'],(string)$a['movement_date']));
        return $rows;
    }

    private function vatLoanRepaymentRows(string $from,string $to):array
    {
        $stmt=$this->pdo->prepare("SELECT l.id,l.reference,
            CONCAT('Restitution réserve TVA · ',COALESCE(NULLIF(l.reference,''),CONCAT('#',l.id))) label,
            LEAST(l.principal_amount,l.repaid_amount) amount,
            LEAST(l.principal_amount,l.repaid_amount) amount_value,
            DATE(l.vat_repaid_at) movement_date,'out' direction,'real' status,
            'vat_repayment' movement_source,'restitution_tva' entry_category,
            'Réserve fiscale TVA' assignment_label,l.note,
            TRIM(CONCAT(COALESCE(u.prenom,''),' ',COALESCE(u.nom,''))) requester_name
            FROM treasury_loans l
            LEFT JOIN user_devis_banamur u ON u.id=l.vat_repaid_by
            WHERE l.loan_type='vat_credit' AND l.status='repaid'
              AND l.vat_repayment_status='repaid' AND l.repaid_amount>0
              AND DATE(l.vat_repaid_at) BETWEEN :from AND :to
            ORDER BY l.vat_repaid_at DESC,l.id DESC");
        $stmt->execute(compact('from','to'));
        return $stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
    }

    private function sum(array $rows,string $direction,string $status): float
    {
        return array_sum(array_map(static fn($r)=>(($r['direction']??'')===$direction&&($r['status']??'')===$status)?(float)$r['amount']:0,$rows));
    }
    private function transferRows(string $from,string $to):array{$stmt=$this->pdo->prepare("SELECT t.id,t.reference,COALESCE(NULLIF(t.note,''),CASE WHEN t.transfer_type='to_real_entries' THEN 'Retour du disponible vers les entrées réelles' ELSE 'Transfert des entrées réelles vers le disponible' END) label,t.amount,t.transfer_date movement_date,t.transfer_type,'transfer' direction,TRIM(CONCAT(COALESCE(u.prenom,''),' ',COALESCE(u.nom,''))) requester_name FROM treasury_fund_transfers t LEFT JOIN user_devis_banamur u ON u.id=t.created_by_user_id WHERE t.cancelled_at IS NULL AND t.transfer_date BETWEEN :from AND :to ORDER BY t.transfer_date DESC,t.id DESC");$stmt->execute(compact('from','to'));return$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];}

    private function vatCollectedBetween(string $from,string $to):float
    {
        $this->syncVatSnapshots((int)($_SESSION['user_id']??0));
        $stmt=$this->pdo->prepare("SELECT COALESCE(SUM(amount_vat),0) FROM treasury_vat_invoice_snapshots WHERE snapshot_date BETWEEN :from AND :to");
        $stmt->execute(['from'=>$from,'to'=>$to]);return(float)$stmt->fetchColumn();
    }
    private function vatBorrowedIntoRealEntries(string $from,string $to):float
    {
        // Conserver l'entrée historique brute : la restitution est comptée une
        // seule fois en sortie du disponible par vatLoanRepaymentRows().
        $stmt=$this->pdo->prepare("SELECT COALESCE(SUM(principal_amount),0) FROM treasury_loans WHERE loan_type='vat_credit' AND status<>'cancelled' AND received_date BETWEEN :from AND :to");
        $stmt->execute(['from'=>$from,'to'=>$to]);return(float)$stmt->fetchColumn();
    }
    private function vatAllocationTotal(string $type,string $toDate):float
    {
        $stmt=$this->pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM treasury_vat_fund_allocations WHERE allocation_type=:type AND allocation_date<=:date");
        $stmt->execute(['type'=>$type,'date'=>$toDate]);return(float)$stmt->fetchColumn();
    }
    private function recordVatAllocation(?int $transferId,string $type,float $amount,string $date,string $reference,string $note,int $userId):void
    {
        if($amount<=0)return;
        $stmt=$this->pdo->prepare("INSERT INTO treasury_vat_fund_allocations(transfer_id,allocation_type,amount,allocation_date,reference,note,created_by_user_id) VALUES(:transfer,:type,:amount,:date,:reference,:note,:user)");
        $stmt->execute(['transfer'=>$transferId?:null,'type'=>$type,'amount'=>$amount,'date'=>$date,'reference'=>$reference?:null,'note'=>$note?:null,'user'=>$userId?:null]);
    }
    private function vatProtectedInAvailable(string $toDate):float
    {
        return max(0,$this->vatAllocationTotal('to_available',$toDate)-$this->vatAllocationTotal('to_real_entries',$toDate)-$this->vatAllocationTotal('remittance',$toDate)-$this->vatAllocationTotal('loan_release',$toDate)+$this->vatAllocationTotal('loan_repayment',$toDate));
    }
    private function vatProtectedInRealEntries(string $toDate):float
    {
        $vat=$this->vatSummary();
        return max(0,(float)$vat['vat_protected']-$this->vatProtectedInAvailable($toDate));
    }
    private function netTransferAmount(array $rows):float{return array_sum(array_map(static fn($r)=>(($r['transfer_type']??'to_available')==='to_real_entries'?-1:1)*(float)$r['amount'],$rows));}
    private function netTransferTotal(string $from,string $to):float{return $this->netTransferAmount($this->transferRows($from,$to));}
    private function ficheRows(string $from, string $to, bool $paid, string $febScope = 'authorized'): array
    {
        $columns=$this->pdo->query('SHOW COLUMNS FROM fiche_decaissement')->fetchAll(PDO::FETCH_COLUMN,0)?:[];
        // Une sortie réelle suit la date d'exécution, y compris lorsqu'une date
        // prévisionnelle différente subsiste sur une fiche décaissée manuellement.
        $paidDate=in_array('date_decaissement',$columns,true)&&in_array('date_decaisse',$columns,true)?'COALESCE(f.date_decaisse,f.date_decaissement,f.date_creat_fiche)':(in_array('date_decaisse',$columns,true)?'COALESCE(f.date_decaisse,f.date_creat_fiche)':(in_array('date_decaissement',$columns,true)?'COALESCE(f.date_decaissement,f.date_creat_fiche)':'f.date_creat_fiche'));
        $plannedDate=in_array('date_decaissement_minimum',$columns,true)?'COALESCE(f.date_decaissement_minimum,f.planned_start_date,DATE(f.date_creat_fiche))':'COALESCE(f.planned_start_date,DATE(f.date_creat_fiche))';
        $dateExpr=$paid?"DATE($paidDate)":$plannedDate;
        $febCondition = '';
        if (!$paid && $febScope === 'authorized') {
            $febCondition = " AND NULLIF(TRIM(COALESCE(f.code_autorisation_feb,'')),'') IS NOT NULL";
        } elseif (!$paid && $febScope === 'linked') {
            $febCondition = " AND (f.feb_request_id IS NOT NULL OR NULLIF(TRIM(COALESCE(f.code_autorisation_feb,'')),'') IS NOT NULL)";
        }
        $sql="SELECT f.num_fiche reference,
            COALESCE(NULLIF(f.designation_fiche,''),'Fiche de décaissement') label,
            COALESCE(NULLIF(TRIM(f.beficiaire_fiche),''),NULLIF(TRIM(p.nom_personnel_soignant),''),NULLIF(TRIM(COALESCE(fo.raison_sociale,fo.nom_commercial,CONCAT_WS(' ',fo.person_first_name,fo.person_last_name))),''),'Bénéficiaire non renseigné') beneficiary,
            CASE WHEN f.personnel_id IS NOT NULL AND f.personnel_id>0 THEN COALESCE(NULLIF(p.tel_personnel_soignant,''),NULLIF(f.tel_beneficiaire_fiche,'')) WHEN f.fournisseur_id IS NOT NULL AND f.fournisseur_id>0 THEN COALESCE(NULLIF(fo.telephone,''),NULLIF(f.fournisseur_telephone,''),NULLIF(f.tel_beneficiaire_fiche,'')) ELSE NULLIF(f.tel_beneficiaire_fiche,'') END beneficiary_phone,
            CASE WHEN f.personnel_id IS NOT NULL AND f.personnel_id>0 THEN COALESCE(NULLIF(p.photo_personnel_soignant,''),NULLIF(f.photo_beneficiaire,'')) WHEN f.fournisseur_id IS NOT NULL AND f.fournisseur_id>0 THEN COALESCE(NULLIF(fo.photo_path,''),NULLIF(fo.logo_path,''),NULLIF(f.photo_beneficiaire,'')) ELSE NULLIF(f.photo_beneficiaire,'') END beneficiary_photo,
            CASE WHEN f.personnel_id IS NOT NULL AND f.personnel_id>0 THEN 'Personnel RH' WHEN f.fournisseur_id IS NOT NULL AND f.fournisseur_id>0 THEN 'Fournisseur' ELSE 'Bénéficiaire externe' END beneficiary_type,
            f.montant_fiche amount,$dateExpr movement_date,f.conforme,f.approuve,f.etat_fiche,f.decaisse,
            COALESCE(f.document_completion_status,'complete') document_completion_status,
            DATE(f.date_creat_fiche) creation_date,c.code chantier_code,c.titre chantier_title,
            ld.designation ligne_devis_label,db.id debourse_id,COALESCE(NULLIF(ld.designation,''),CONCAT('Déboursé #',db.id)) debourse_label,db.montant_debourse debourse_amount,
            ldb.id ligne_debourse_id,ldb.designation ligne_debourse_label,ldb.categorie ligne_debourse_category,ldb.montant ligne_debourse_amount,
            NULLIF(f.mode_paiement,'') payment_mode,NULLIF(f.details_demande,'') request_details,NULLIF(f.precision_fiche,'') fiche_precision,NULLIF(f.code_autorisation_feb,'') authorization_code,
            COALESCE(NULLIF(TRIM(er.requester_name),''),NULLIF(TRIM(CONCAT(COALESCE(ru.prenom,''),' ',COALESCE(ru.nom,''))),''),NULLIF(TRIM(CONCAT(COALESCE(u.prenom,''),' ',COALESCE(u.nom,''))),''),'Demandeur non renseigné') requester_name,
            COALESCE(NULLIF(rrp.photo_personnel_soignant,''),NULLIF(ru.photo,''),NULLIF(rp.photo_personnel_soignant,''),NULLIF(u.photo,'')) requester_photo,COALESCE(NULLIF(rrp.tel_personnel_soignant,''),NULLIF(rp.tel_personnel_soignant,'')) requester_phone,er.requester_user_id,
            TRIM(CONCAT(COALESCE(cu.prenom,''),' ',COALESCE(cu.nom,''))) challenger_name,COALESCE(NULLIF(cp.photo_personnel_soignant,''),NULLIF(cu.photo,'')) challenger_photo,cp.tel_personnel_soignant challenger_phone,'fiche_decaissement' movement_source,'out' direction
            FROM fiche_decaissement f
            LEFT JOIN chantier_banamur c ON c.id=f.chantier_id
            LEFT JOIN feb_disbursement_links fdl ON fdl.feb_request_id=f.feb_request_id
            LEFT JOIN ligne_debourse_banamur ldb ON ldb.id=COALESCE(f.ligne_debourse_id,fdl.ligne_debourse_id)
            LEFT JOIN debourse_banamur db ON db.id=COALESCE(ldb.debourse_id,fdl.debourse_id)
            LEFT JOIN ligne_devis_banamur ld ON ld.id=COALESCE(db.ligne_devis_id,fdl.ligne_devis_id)
            LEFT JOIN personnel p ON p.id_personnel_soignant=f.personnel_id
            LEFT JOIN fournisseur_banamur fo ON fo.id_fournisseur=f.fournisseur_id
            LEFT JOIN employee_legal_requests er ON er.id=f.feb_request_id
            LEFT JOIN user_devis_banamur ru ON ru.id=er.requester_user_id
            LEFT JOIN personnel rrp ON rrp.id_personnel_soignant=ru.personnel_id
            LEFT JOIN user_devis_banamur u ON u.id=f.created_by_user_id
            LEFT JOIN personnel rp ON rp.id_personnel_soignant=u.personnel_id
            LEFT JOIN (SELECT feb_request_id,MAX(challenger_user_id) challenger_user_id FROM feb_price_challenges WHERE status='retained' GROUP BY feb_request_id) fc ON fc.feb_request_id=f.feb_request_id
            LEFT JOIN user_devis_banamur cu ON cu.id=fc.challenger_user_id
            LEFT JOIN personnel cp ON cp.id_personnel_soignant=cu.personnel_id
            WHERE COALESCE(f.decaisse,0)=".($paid?'1':'0')."$febCondition
              AND NOT EXISTS(
                  SELECT 1
                  FROM chantier_banamur archived_chantier
                  WHERE archived_chantier.id=f.chantier_id
                    AND archived_chantier.archived_at IS NOT NULL
              )
              AND ".ChantierPauseService::activePredicate($this->pdo,'f.chantier_id')."
              AND $dateExpr BETWEEN :from AND :to
            ORDER BY movement_date DESC";
        try{$stmt=$this->pdo->prepare($sql);$stmt->execute(compact('from','to'));$rows=$this->enrichFicheValidators($stmt->fetchAll(PDO::FETCH_ASSOC)?:[]);foreach($rows as &$row){if(trim((string)($row['requester_name']??''))==='Demandeur non renseigné')$row['requester_name']=null;foreach(['beneficiary_photo','requester_photo','validator_photo','challenger_photo'] as $key)$row[$key]=$this->resolveMediaPath((string)($row[$key]??''));}unset($row);return$rows;}catch(Throwable $e){return[];}
    }

    private function pendingFebRows(string $from, string $to): array
    {
        try {
            $activeFeb = ChantierPauseService::storageExists($this->pdo) ? "NOT EXISTS(SELECT 1 FROM finea_chantier_pauses paused_feb WHERE paused_feb.chantier_id=CAST(JSON_UNQUOTE(JSON_EXTRACT(er.payload_json,'$.chantier_id')) AS UNSIGNED) AND paused_feb.resumed_at IS NULL AND (paused_feb.resume_at IS NULL OR paused_feb.resume_at>NOW()))" : '1=1';
            $stmt=$this->pdo->prepare(
                "SELECT er.id,er.title,er.requester_user_id,er.requester_name,er.submitted_at,
                        er.payload_json,u.photo requester_photo,p.tel_personnel_soignant requester_phone
                 FROM employee_legal_requests er
                 LEFT JOIN user_devis_banamur u ON u.id=er.requester_user_id
                 LEFT JOIN personnel p ON p.id_personnel_soignant=u.personnel_id
                 WHERE er.assigned_team='finance'
                   AND er.request_type='feb'
                   AND er.status='submitted'
                   AND COALESCE(er.meta_legacy_source,'')<>'feb_legacy'
                                     AND NOT EXISTS(
                                             SELECT 1
                                             FROM chantier_banamur archived_chantier
                                             WHERE archived_chantier.id=CAST(JSON_UNQUOTE(JSON_EXTRACT(er.payload_json,'$.chantier_id')) AS UNSIGNED)
                                                 AND archived_chantier.archived_at IS NOT NULL
                                     )
                   AND $activeFeb
                 ORDER BY er.submitted_at DESC,er.id DESC"
            );
            $stmt->execute();
            $rows=[];
            foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $request){
                $payload=json_decode((string)($request['payload_json']??''),true);
                if(!is_array($payload))$payload=[];
                $draft=is_array($payload['provisional_decaissement']??null)?$payload['provisional_decaissement']:[];
                $proposal=is_array($payload['proposed_debourse']??null)?$payload['proposed_debourse']:[];
                $budgetMode=(string)($payload['budget_planned']??'');
                if($budgetMode==='no'||!empty($proposal))continue;
                $planningDate=trim((string)($payload['validation_deferred_until']??$draft['date_decaissement_minimum']??$payload['planned_start_date']??$payload['date_livraison']??substr((string)$request['submitted_at'],0,10)));
                if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$planningDate))$planningDate=substr((string)$request['submitted_at'],0,10);
                if($planningDate<$from||$planningDate>$to)continue;
                $amount=(float)($payload['montant']??$payload['amount']??0);
                if($amount<=0)continue;
                $beneficiary=trim((string)($draft['beficiaire_fiche']??$draft['beneficiaire']??$payload['beneficiaire']??$payload['nom_beneficiaire']??''));
                $allocation=trim((string)($payload['chantier_label']??$payload['departement_label']??$payload['service_employe']??''));
                $rows[]=[
                    'reference'=>'FEB-'.(int)$request['id'],
                    'feb_request_id'=>(int)$request['id'],
                    'label'=>trim((string)($request['title']??''))?:'Demande FEB',
                    'amount'=>$amount,
                    'movement_date'=>$planningDate,
                    'creation_date'=>substr((string)$request['submitted_at'],0,10),
                    'beneficiary'=>$beneficiary?:'Bénéficiaire à confirmer',
                    'beneficiary_type'=>'Bénéficiaire FEB',
                    'requester_name'=>trim((string)($request['requester_name']??''))?:'Demandeur non renseigné',
                    'requester_photo'=>$this->resolveMediaPath((string)($request['requester_photo']??'')),
                    'requester_phone'=>$request['requester_phone']??null,
                    'chantier_title'=>$allocation?:null,
                    'assignment_label'=>$allocation?:'Affectation non renseignée',
                    'request_details'=>trim((string)($payload['description']??$payload['details']??$payload['objet']??'')),
                    'movement_source'=>'feb_request',
                    'direction'=>'out',
                ];
            }
            return $rows;
        } catch(Throwable $e) {
            return [];
        }
    }
    private function enrichFicheValidators(array $rows):array
    {
        $references=array_values(array_unique(array_filter(array_map(static fn(array $row):string=>(string)($row['reference']??''),$rows))));
        if(!$references)return$rows;
        $marks=implode(',',array_fill(0,count($references),'?'));
        $sql="SELECT f.num_fiche,COALESCE(NULLIF(TRIM(er.decided_by_name),''),NULLIF(TRIM(CONCAT(COALESCE(vu.prenom,''),' ',COALESCE(vu.nom,''))),'')) validator_name,COALESCE(NULLIF(vp.photo_personnel_soignant,''),NULLIF(vu.photo,'')) validator_photo,vp.tel_personnel_soignant validator_phone FROM fiche_decaissement f LEFT JOIN employee_legal_requests er ON er.id=f.feb_request_id LEFT JOIN user_devis_banamur vu ON vu.id=er.decided_by_user_id LEFT JOIN personnel vp ON vp.id_personnel_soignant=vu.personnel_id WHERE f.num_fiche IN ($marks)";
        $stmt=$this->pdo->prepare($sql);$stmt->execute($references);$byReference=[];foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $identity)$byReference[(string)$identity['num_fiche']]=$identity;
        foreach($rows as &$row){$identity=$byReference[(string)($row['reference']??'')]??[];$row['validator_name']=$identity['validator_name']??null;$row['validator_photo']=$identity['validator_photo']??null;$row['validator_phone']=$identity['validator_phone']??null;}unset($row);return$rows;
    }
    private function invoiceRows(string $from,string $to,bool $real): array
    {
        $date=$real?'DATE(f.paid_at)':'COALESCE(f.expected_collection_date,f.invoice_date,DATE(f.created_at))';
        $amount=$real?'CASE WHEN f.collected_amount>0 THEN f.collected_amount ELSE f.total_ttc END':'GREATEST(f.total_ttc-COALESCE(f.collected_amount,0),0)';
        $where=$real?"f.paiement_statut='payee' AND f.paid_at IS NOT NULL":"f.paiement_statut='emise' AND NOT EXISTS(SELECT 1 FROM treasury_invoice_collection_cancellations tic WHERE tic.invoice_id=f.id_facture_vente)";
        try{$s=$this->pdo->prepare("SELECT f.id_facture_vente invoice_id,COALESCE(NULLIF(f.reference_interne,''),CONCAT('FACT-',f.id_facture_vente)) reference,CONCAT('Encaissement facture · ',COALESCE(NULLIF(f.client_company_name,''),'Client')) label,COALESCE(NULLIF(f.client_company_name,''),'Client') beneficiary,'Client facturé' beneficiary_type,f.chantier_id,c.code chantier_code,c.titre chantier_title,f.facture_travaux_type invoice_work_type,f.avancement_percent,CASE WHEN f.expected_collection_date IS NULL THEN 1 ELSE 0 END expected_date_missing,$amount amount,$date movement_date,CASE WHEN $date<CURDATE() THEN 1 ELSE 0 END is_overdue,GREATEST(DATEDIFF(CURDATE(),$date),0) overdue_days,'in' direction FROM facture_vente_banamur f LEFT JOIN chantier_banamur c ON c.id=f.chantier_id WHERE $where AND $date BETWEEN :from AND :to AND $amount>0 ORDER BY movement_date");$s->execute(compact('from','to'));return$s->fetchAll(PDO::FETCH_ASSOC)?:[];}catch(Throwable){return[];}
    }
    private function recurringRows(string $from,string $to): array
    {
        try{$s=$this->pdo->prepare("SELECT n.reference,CONCAT('Besoin récurrent · ',n.title) label,n.amount_estimated amount,g.scheduled_due_date movement_date,c.code chantier_code,c.titre chantier_title,COALESCE(NULLIF(n.fournisseur_name,''),NULLIF(fo.raison_sociale,''),NULLIF(fo.nom_commercial,''),'Bénéficiaire non renseigné') beneficiary,'Fournisseur' beneficiary_type,COALESCE(NULLIF(fo.telephone,''),'') beneficiary_phone,COALESCE(NULLIF(fo.photo_path,''),NULLIF(fo.logo_path,'')) beneficiary_photo,COALESCE(NULLIF(n.requester_name,''),NULLIF(TRIM(CONCAT(COALESCE(ru.prenom,''),' ',COALESCE(ru.nom,''))),'')) requester_name,COALESCE(NULLIF(rp.photo_personnel_soignant,''),NULLIF(ru.photo,'')) requester_photo,rp.tel_personnel_soignant requester_phone,COALESCE(NULLIF(TRIM(CONCAT(COALESCE(vu.prenom,''),' ',COALESCE(vu.nom,''))),''),'Validateur non identifié') validator_name,COALESCE(NULLIF(vp.photo_personnel_soignant,''),NULLIF(vu.photo,'')) validator_photo,vp.tel_personnel_soignant validator_phone,'out' direction FROM finea_recurring_need_generations g JOIN finea_recurring_needs n ON n.id=g.recurring_need_id LEFT JOIN chantier_banamur c ON c.id=n.chantier_id LEFT JOIN fournisseur_banamur fo ON fo.id_fournisseur=n.fournisseur_id LEFT JOIN user_devis_banamur ru ON ru.id=n.requester_user_id LEFT JOIN personnel rp ON rp.id_personnel_soignant=ru.personnel_id LEFT JOIN user_devis_banamur vu ON vu.id=n.validated_by LEFT JOIN personnel vp ON vp.id_personnel_soignant=vu.personnel_id WHERE g.scheduled_due_date BETWEEN :from AND :to AND g.status IN('pending','failed') AND n.status='active' AND n.financial_mode<>'sans_decaissement' AND g.generated_feb_id IS NULL AND g.generated_decaissement_id IS NULL AND ".ChantierPauseService::activePredicate($this->pdo,'n.chantier_id')." ORDER BY g.scheduled_due_date");$s->execute(compact('from','to'));$rows=$s->fetchAll(PDO::FETCH_ASSOC)?:[];foreach($rows as &$row){if(($row['validator_name']??'')==='Validateur non identifié')$row['validator_name']=null;foreach(['beneficiary_photo','requester_photo','validator_photo'] as $key)$row[$key]=$this->resolveMediaPath((string)($row[$key]??''));}unset($row);return$rows;}catch(Throwable){return[];}
    }
    private function resolveMediaPath(string $raw): string
    {
        $raw=trim(str_replace('\\','/',$raw));if($raw===''||preg_match('~^(https?:|data:|/)~i',$raw))return$raw;$root=realpath(dirname(__DIR__));if(!$root)return'';$name=basename($raw);$candidates=[$raw,'photo/'.$raw,'uploads/'.$raw,'uploads/users/'.$raw,'uploads/photo/'.$raw,'uploads/rh/'.$raw,'uploads/rh/photo/'.$raw,'uploads/fournisseurs/photos/'.$raw,'uploads/fournisseurs/logos/'.$raw];foreach($candidates as $candidate){$candidate=ltrim($candidate,'/');if(is_file($root.'/'.$candidate))return$candidate;}foreach(['uploads/rh/photo','uploads/users','uploads/photo','uploads/fournisseurs/photos','uploads/fournisseurs/logos','photo'] as $directory){$matches=glob($root.'/'.$directory.'/'.$name.'*')?:[];if($matches){$relative=substr($matches[0],strlen($root)+1);return str_replace('\\','/',$relative);}}return'';
    }
    private function ensureSchema(): void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS treasury_opening_balances(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,effective_date DATE NOT NULL,amount DECIMAL(18,2) NOT NULL DEFAULT 0,currency CHAR(3) NOT NULL DEFAULT 'XOF',note TEXT NULL,created_by_user_id INT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uq_effective_date(effective_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS treasury_manual_movements(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,direction ENUM('in','out') NOT NULL,status ENUM('forecast','real') NOT NULL,label VARCHAR(255) NOT NULL,amount DECIMAL(18,2) NOT NULL,movement_date DATE NOT NULL,reference VARCHAR(120) NULL,note TEXT NULL,created_by_user_id INT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,cancelled_at DATETIME NULL,KEY idx_date_status(movement_date,status,direction)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $columns=$this->pdo->query('SHOW COLUMNS FROM treasury_manual_movements')->fetchAll(PDO::FETCH_COLUMN,0)?:[];
        if(!in_array('entry_category',$columns,true))$this->pdo->exec("ALTER TABLE treasury_manual_movements ADD COLUMN entry_category VARCHAR(80) NULL AFTER note");
        if(!in_array('chantier_id',$columns,true))$this->pdo->exec("ALTER TABLE treasury_manual_movements ADD COLUMN chantier_id INT NULL AFTER entry_category");
        if(!in_array('ligne_devis_id',$columns,true))$this->pdo->exec("ALTER TABLE treasury_manual_movements ADD COLUMN ligne_devis_id INT NULL AFTER chantier_id");
        if(!in_array('assignment_label',$columns,true))$this->pdo->exec("ALTER TABLE treasury_manual_movements ADD COLUMN assignment_label VARCHAR(255) NULL AFTER ligne_devis_id");
        foreach(['repayment_required'=>'TINYINT(1) NOT NULL DEFAULT 0','repayment_date'=>'DATE NULL','repayment_status'=>'VARCHAR(30) NULL','repayment_authorized_at'=>'DATETIME NULL','repayment_authorized_by'=>'INT NULL','repayment_source_movement_id'=>'BIGINT UNSIGNED NULL'] as $name=>$definition)if(!in_array($name,$columns,true))$this->pdo->exec("ALTER TABLE treasury_manual_movements ADD COLUMN $name $definition");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS treasury_collection_confirmations(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,invoice_id BIGINT UNSIGNED NOT NULL,received TINYINT(1) NOT NULL,new_expected_date DATE NULL,confirmed_by_user_id INT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,KEY idx_invoice(invoice_id,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS treasury_invoice_collection_cancellations(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,invoice_id BIGINT UNSIGNED NOT NULL,reason VARCHAR(500) NOT NULL,cancelled_by_user_id INT NULL,cancelled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uq_invoice(invoice_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS treasury_fund_transfers(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,amount DECIMAL(18,2) NOT NULL,transfer_date DATE NOT NULL,reference VARCHAR(120) NULL,note TEXT NULL,created_by_user_id INT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,cancelled_at DATETIME NULL,KEY idx_transfer_date(transfer_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $transferColumns=$this->pdo->query('SHOW COLUMNS FROM treasury_fund_transfers')->fetchAll(PDO::FETCH_COLUMN,0)?:[];if(!in_array('transfer_type',$transferColumns,true))$this->pdo->exec("ALTER TABLE treasury_fund_transfers ADD COLUMN transfer_type ENUM('to_available','to_real_entries') NOT NULL DEFAULT 'to_available' AFTER note");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS treasury_payment_deferrals(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,fiche_number VARCHAR(50) NOT NULL,deferral_type ENUM('processing','payment') NOT NULL,deferred_until DATE NULL,reason VARCHAR(255) NULL,created_by_user_id INT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,status ENUM('active','processed','cancelled') NOT NULL DEFAULT 'active',KEY idx_fiche_status(fiche_number,status),KEY idx_until(deferred_until)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}

