<?php
declare(strict_types=1);

final class CommercialProjectFinanceRepository
{
    public function __construct(private PDO $pdo) {}

    public function quote(int $devisId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, offre_id, client_id, numero_devis, total_ttc FROM devis_banamur WHERE id=:id'
        );
        $statement->execute(['id' => $devisId]);
        return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function createPurchaseOrder(array $data): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO client_purchase_orders
                (order_number,offre_id,devis_id,client_id,amount,quote_amount_snapshot,
                 amount_difference,currency,order_date,received_at,expected_start_date,
                 document_path,status,created_by_user_id)
             VALUES (:order_number,:offre_id,:devis_id,:client_id,:amount,:quote_amount,
                 :difference,:currency,:order_date,NOW(),:expected_start_date,
                 :document_path,:status,:user_id)'
        );
        $statement->execute($data);
        return (int) $this->pdo->lastInsertId();
    }

    public function linkPurchaseOrderToChantier(int $chantierId, int $purchaseOrderId, int $userId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO chantier_client_purchase_orders
                (chantier_id,client_purchase_order_id,linked_by_user_id)
             VALUES (:chantier_id,:purchase_order_id,:user_id)
             ON DUPLICATE KEY UPDATE client_purchase_order_id=VALUES(client_purchase_order_id),
                 linked_by_user_id=VALUES(linked_by_user_id),linked_at=NOW()'
        );
        $statement->execute([
            'chantier_id' => $chantierId,
            'purchase_order_id' => $purchaseOrderId,
            'user_id' => $userId ?: null,
        ]);
    }

    public function indicators(array $devisIds): array
    {
        if (!$devisIds) {
            return [];
        }
        $ids = implode(',', array_map('intval', $devisIds));
        $tableCheck=$this->pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="treasury_invoice_collection_cancellations"');
        $tableCheck->execute();
        $hasCancellationTable=(int)$tableCheck->fetchColumn()>0;
        $cancellationJoin=$hasCancellationTable
            ? 'LEFT JOIN treasury_invoice_collection_cancellations tic ON tic.invoice_id=fv.id_facture_vente'
            : '';
        $notCancelled=$hasCancellationTable ? 'AND tic.invoice_id IS NULL' : '';
        $sql = "SELECT d.id,
                    (COALESCE(d.validation_technique,0)=0 OR COALESCE(d.validation_generale,0)=0) is_draft,
                    (ch.devis_id IS NOT NULL) has_chantier,
                    (inv.devis_id IS NOT NULL) has_invoice,
                    COALESCE(inv.invoice_count,0) invoice_count,
                    COALESCE(inv.cancelable_invoice_count,0) cancelable_invoice_count,
                    COALESCE(inv.paid_invoice_count,0) paid_invoice_count,
                    (sheet.devis_id IS NOT NULL) has_disbursement_sheet,
                    (a.devis_id IS NOT NULL) archived
                FROM devis_banamur d
                LEFT JOIN (SELECT DISTINCT devis_id FROM chantier_banamur WHERE archived_at IS NULL) ch ON ch.devis_id=d.id
                LEFT JOIN (
                    SELECT c.devis_id,
                           COUNT(*) invoice_count,
                           SUM(LOWER(TRIM(COALESCE(fv.paiement_statut,'emise')))='emise'
                               $notCancelled) cancelable_invoice_count,
                           SUM(LOWER(TRIM(COALESCE(fv.paiement_statut,'emise')))='payee') paid_invoice_count
                    FROM facture_vente_banamur fv
                    JOIN chantier_banamur c ON c.id=fv.chantier_id
                    $cancellationJoin
                    GROUP BY c.devis_id
                    HAVING cancelable_invoice_count > 0 OR paid_invoice_count > 0
                ) inv ON inv.devis_id=d.id
                LEFT JOIN (SELECT DISTINCT db.devis_id FROM fiche_decaissement fd
                           JOIN ligne_debourse_banamur ldb ON ldb.id=fd.ligne_debourse_id
                           JOIN debourse_banamur db ON db.id=ldb.debourse_id) sheet ON sheet.devis_id=d.id
                LEFT JOIN devis_archives a ON a.devis_id=d.id AND a.restored_at IS NULL
                WHERE d.id IN ($ids)";
        $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['id']] = $row;
        }
        return $result;
    }

    public function archiveEligibility(int $devisId): array
    {
        return $this->indicators([$devisId])[$devisId] ?? [];
    }

    public function archive(int $devisId, int $userId, string $reason, array $snapshot): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO devis_archives
                (devis_id,archived_by_user_id,archive_reason,eligibility_snapshot_json)
             VALUES (:devis_id,:user_id,:reason,:snapshot)
             ON DUPLICATE KEY UPDATE archived_at=NOW(),archived_by_user_id=VALUES(archived_by_user_id),
                archive_reason=VALUES(archive_reason),eligibility_snapshot_json=VALUES(eligibility_snapshot_json),
                restored_at=NULL,restored_by_user_id=NULL,restore_note=NULL'
        );
        $statement->execute([
            'devis_id' => $devisId,
            'user_id' => $userId,
            'reason' => $reason,
            'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
        ]);
    }

    public function restore(int $devisId, int $userId, string $note): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE devis_archives SET restored_at=NOW(),restored_by_user_id=:user_id,restore_note=:note
             WHERE devis_id=:devis_id AND restored_at IS NULL'
        );
        $statement->execute(['devis_id' => $devisId, 'user_id' => $userId, 'note' => $note]);
    }

    public function treasurySummary(int $forecastDays = 30, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $forecastDays = max(1, min(365, $forecastDays));
        if(!$dateFrom&&!$dateTo){$dateFrom=date('Y-m-01',strtotime('-2 months'));$dateTo=date('Y-m-t',strtotime('+3 months'));}
        else{$dateFrom=$dateFrom?:date('Y-m-01');$dateTo=$dateTo?:date('Y-m-d',strtotime('+'.$forecastDays.' days'));}
        $params = ['from' => $dateFrom, 'to' => $dateTo];
        $openingStmt = $this->pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM treasury_opening_balances WHERE currency='XOF' AND effective_date<=:to");
        $openingStmt->execute(['to'=>$dateTo]);
        $opening = (float)$openingStmt->fetchColumn();
        $inflowStmt=$this->pdo->prepare("SELECT COALESCE(SUM(CASE WHEN fv.collected_amount>0 THEN LEAST(fv.collected_amount,fv.total_ttc) ELSE fv.total_ttc END),0) FROM facture_vente_banamur fv JOIN chantier_banamur c ON c.id=fv.chantier_id AND c.archived_at IS NULL WHERE fv.paiement_statut='payee' AND DATE(COALESCE(fv.paid_at,fv.updated_at)) BETWEEN :from AND :to");
        $inflowStmt->execute($params);$inflows=(float)$inflowStmt->fetchColumn();
        $outflowStmt=$this->pdo->prepare("SELECT COALESCE(SUM(f.montant_fiche),0) FROM fiche_decaissement f JOIN chantier_banamur c ON c.id=f.chantier_id AND c.archived_at IS NULL JOIN employee_legal_requests r ON r.id=f.feb_request_id AND r.request_type='feb' AND r.status='approved' WHERE f.decaisse=1 AND COALESCE(DATE(f.date_decaissement),f.date_decaissement_minimum,f.planned_start_date) BETWEEN :from AND :to AND EXISTS(SELECT 1 FROM bon_commande bc WHERE bc.num_fiche=COALESCE(NULLIF(f.parent_num_fiche,''),NULLIF(f.code_parentale,''),f.num_fiche))");
        $outflowStmt->execute($params);$outflows=(float)$outflowStmt->fetchColumn();
        $forecastStmt=$this->pdo->prepare("SELECT COALESCE(SUM(GREATEST(fv.total_ttc-fv.collected_amount,0)),0) FROM facture_vente_banamur fv JOIN chantier_banamur c ON c.id=fv.chantier_id AND c.archived_at IS NULL WHERE fv.paiement_statut='emise' AND fv.expected_collection_date BETWEEN :from AND :to");
        $forecastStmt->execute($params);$forecast=(float)$forecastStmt->fetchColumn();
        $febStmt=$this->pdo->prepare("SELECT COALESCE(SUM(GREATEST(jt.qty,0)*GREATEST(jt.price,0)),0) FROM employee_legal_requests r JOIN chantier_banamur c ON c.id=CAST(JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.chantier_id')) AS UNSIGNED) AND c.archived_at IS NULL JOIN JSON_TABLE(r.payload_json,'$.besoins[*]' COLUMNS(qty DECIMAL(18,2) PATH '$.quantite' DEFAULT 1 ON EMPTY,price DECIMAL(18,2) PATH '$.fournisseur.prix_unitaire' DEFAULT 0 ON EMPTY,start_date DATE PATH '$.planned_start_date' NULL ON EMPTY)) jt WHERE r.request_type='feb' AND r.status IN ('submitted','pending','approved') AND COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.validation_deferred_until')),'null'),jt.start_date,JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.planned_start_date')),JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.date_livraison')),DATE(r.submitted_at)) BETWEEN :from AND :to AND NOT EXISTS(SELECT 1 FROM fiche_decaissement f WHERE f.feb_request_id=r.id)");
        $febStmt->execute($params);$forecastOutflows=(float)$febStmt->fetchColumn();
        $plannedSheet=$this->pdo->prepare("SELECT COALESCE(SUM(f.montant_fiche),0) FROM fiche_decaissement f JOIN chantier_banamur c ON c.id=f.chantier_id AND c.archived_at IS NULL JOIN employee_legal_requests r ON r.id=f.feb_request_id AND r.request_type='feb' AND r.status='approved' WHERE COALESCE(f.decaisse,0)=0 AND COALESCE(f.date_decaissement_minimum,f.planned_start_date,DATE(f.date_creat_fiche)) BETWEEN :from AND :to AND EXISTS(SELECT 1 FROM bon_commande bc WHERE bc.num_fiche=COALESCE(NULLIF(f.parent_num_fiche,''),NULLIF(f.code_parentale,''),f.num_fiche))");$plannedSheet->execute($params);$forecastOutflows+=(float)$plannedSheet->fetchColumn();
        $loanStmt=$this->pdo->prepare("SELECT COALESCE(SUM(CASE WHEN loan_type='external' THEN principal_amount ELSE 0 END),0) principal,COALESCE(SUM(CASE WHEN loan_type='external' THEN repaid_amount ELSE 0 END),0) repaid,COALESCE(SUM(CASE WHEN loan_type='external' THEN GREATEST(principal_amount+principal_amount*interest_rate/100-repaid_amount,0) ELSE 0 END),0) outstanding,COALESCE(SUM(CASE WHEN loan_type='vat_credit' THEN GREATEST(principal_amount-repaid_amount,0) ELSE 0 END),0) vat_credit_outstanding FROM treasury_loans WHERE status<>'cancelled' AND received_date<=:to");
        $loanStmt->execute(['to'=>$dateTo]);$loan=(array)$loanStmt->fetch(PDO::FETCH_ASSOC);
        $loansReceived=(float)($loan['principal']??0);$loansRepaid=(float)($loan['repaid']??0);$loansOutstanding=(float)($loan['outstanding']??0);$vatCreditOutstanding=(float)($loan['vat_credit_outstanding']??0);
        $monthlyStmt=$this->pdo->prepare("SELECT period,SUM(real_inflows) real_inflows,SUM(forecast_inflows) forecast_inflows,SUM(real_outflows) real_outflows,SUM(forecast_outflows) forecast_outflows FROM (
          SELECT DATE_FORMAT(COALESCE(fv.paid_at,fv.updated_at),'%Y-%m') period,SUM(CASE WHEN fv.collected_amount>0 THEN LEAST(fv.collected_amount,fv.total_ttc) ELSE fv.total_ttc END) real_inflows,0 forecast_inflows,0 real_outflows,0 forecast_outflows FROM facture_vente_banamur fv JOIN chantier_banamur c ON c.id=fv.chantier_id AND c.archived_at IS NULL WHERE fv.paiement_statut='payee' AND DATE(COALESCE(fv.paid_at,fv.updated_at)) BETWEEN :f1 AND :t1 GROUP BY period
          UNION ALL SELECT DATE_FORMAT(fv.expected_collection_date,'%Y-%m'),0,SUM(GREATEST(fv.total_ttc-fv.collected_amount,0)),0,0 FROM facture_vente_banamur fv JOIN chantier_banamur c ON c.id=fv.chantier_id AND c.archived_at IS NULL WHERE fv.paiement_statut='emise' AND fv.expected_collection_date BETWEEN :f2 AND :t2 GROUP BY DATE_FORMAT(fv.expected_collection_date,'%Y-%m')
          UNION ALL SELECT DATE_FORMAT(COALESCE(DATE(f.date_decaissement),f.date_decaissement_minimum,f.planned_start_date),'%Y-%m'),0,0,SUM(f.montant_fiche),0 FROM fiche_decaissement f JOIN chantier_banamur c ON c.id=f.chantier_id AND c.archived_at IS NULL JOIN employee_legal_requests r ON r.id=f.feb_request_id AND r.status='approved' WHERE f.decaisse=1 AND COALESCE(DATE(f.date_decaissement),f.date_decaissement_minimum,f.planned_start_date) BETWEEN :f3 AND :t3 AND EXISTS(SELECT 1 FROM bon_commande bc WHERE bc.num_fiche=COALESCE(NULLIF(f.parent_num_fiche,''),NULLIF(f.code_parentale,''),f.num_fiche)) GROUP BY DATE_FORMAT(COALESCE(DATE(f.date_decaissement),f.date_decaissement_minimum,f.planned_start_date),'%Y-%m')
          UNION ALL SELECT DATE_FORMAT(COALESCE(f.date_decaissement_minimum,f.planned_start_date,DATE(f.date_creat_fiche)),'%Y-%m'),0,0,0,SUM(f.montant_fiche) FROM fiche_decaissement f JOIN chantier_banamur c ON c.id=f.chantier_id AND c.archived_at IS NULL JOIN employee_legal_requests r ON r.id=f.feb_request_id AND r.status='approved' WHERE COALESCE(f.decaisse,0)=0 AND COALESCE(f.date_decaissement_minimum,f.planned_start_date,DATE(f.date_creat_fiche)) BETWEEN :f5 AND :t5 AND EXISTS(SELECT 1 FROM bon_commande bc WHERE bc.num_fiche=COALESCE(NULLIF(f.parent_num_fiche,''),NULLIF(f.code_parentale,''),f.num_fiche)) GROUP BY DATE_FORMAT(COALESCE(f.date_decaissement_minimum,f.planned_start_date,DATE(f.date_creat_fiche)),'%Y-%m')
          UNION ALL SELECT DATE_FORMAT(COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.validation_deferred_until')),'null'),jt.start_date,JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.planned_start_date')),JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.date_livraison')),DATE(r.submitted_at)),'%Y-%m'),0,0,0,SUM(GREATEST(jt.qty,0)*GREATEST(jt.price,0)) FROM employee_legal_requests r JOIN chantier_banamur c ON c.id=CAST(JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.chantier_id')) AS UNSIGNED) AND c.archived_at IS NULL JOIN JSON_TABLE(r.payload_json,'$.besoins[*]' COLUMNS(qty DECIMAL(18,2) PATH '$.quantite' DEFAULT 1 ON EMPTY,price DECIMAL(18,2) PATH '$.fournisseur.prix_unitaire' DEFAULT 0 ON EMPTY,start_date DATE PATH '$.planned_start_date' NULL ON EMPTY)) jt WHERE r.request_type='feb' AND r.status IN ('submitted','pending','approved') AND COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.validation_deferred_until')),'null'),jt.start_date,JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.planned_start_date')),JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.date_livraison')),DATE(r.submitted_at)) BETWEEN :f4 AND :t4 AND NOT EXISTS(SELECT 1 FROM fiche_decaissement f WHERE f.feb_request_id=r.id) GROUP BY DATE_FORMAT(COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.validation_deferred_until')),'null'),jt.start_date,JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.planned_start_date')),JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.date_livraison')),DATE(r.submitted_at)),'%Y-%m')
        ) flow GROUP BY period ORDER BY period");
        $monthlyStmt->execute(['f1'=>$dateFrom,'t1'=>$dateTo,'f2'=>$dateFrom,'t2'=>$dateTo,'f3'=>$dateFrom,'t3'=>$dateTo,'f4'=>$dateFrom,'t4'=>$dateTo,'f5'=>$dateFrom,'t5'=>$dateTo]);
        $rawMonthly=$monthlyStmt->fetchAll(PDO::FETCH_ASSOC)?:[];$monthlyByPeriod=[];foreach($rawMonthly as $row)$monthlyByPeriod[(string)$row['period']]=$row;$monthly=[];
        $cursor=new DateTimeImmutable(substr($dateFrom,0,7).'-01');$last=new DateTimeImmutable(substr($dateTo,0,7).'-01');
        while($cursor<=$last){$key=$cursor->format('Y-m');$month=$monthlyByPeriod[$key]??['period'=>$key,'real_inflows'=>0,'forecast_inflows'=>0,'real_outflows'=>0,'forecast_outflows'=>0];$month['inflows']=(float)($month['real_inflows']??0);$month['outflows']=(float)($month['real_outflows']??0);$monthly[]=$month;$cursor=$cursor->modify('+1 month');}
        $febForecastOutflows=$forecastOutflows;$fixed=$this->fixedChargeTotals($dateFrom,$dateTo);$payroll=$this->payrollTotals($dateFrom,$dateTo);$outflows+=(float)$fixed['actual']+(float)$payroll['actual'];$forecastOutflows+=(float)$fixed['forecast']+(float)$payroll['forecast'];
        $vat=$this->vatSummary($dateFrom,$dateTo);
        $days=(new DateTimeImmutable($dateFrom))->diff(new DateTimeImmutable($dateTo))->days+1;$previousTo=(new DateTimeImmutable($dateFrom))->modify('-1 day');$previousFrom=$previousTo->modify('-'.max(0,$days-1).' days');
        $previous=$this->periodActualTotals($previousFrom->format('Y-m-d'),$previousTo->format('Y-m-d'));
        $growth=static fn(float $current,float $old):?float=>$old>0?round((($current-$old)/$old)*100,1):($current>0?100.0:null);
        $turnoverHt=$this->salesTurnoverHt($dateFrom,$dateTo);$previousTurnoverHt=$this->salesTurnoverHt($previousFrom->format('Y-m-d'),$previousTo->format('Y-m-d'));
        $profit=$turnoverHt-$outflows;$profitTax=max(0,$profit)*.25;
        $realBalance=$opening+$inflows+$loansReceived-$outflows-$loansRepaid-(float)$vat['remitted'];
        $vatReserve=max(0,(float)$vat['balance']);$vatCreditAvailable=max(0,$vatReserve-$vatCreditOutstanding);$operatingAvailable=$realBalance-$vatReserve+$vatCreditOutstanding;
        // Disponible prévisionnel métier : seules les charges fixes provenant
        // des besoins récurrents réduisent les entrées attendues. Les fiches de
        // décaissement restent visibles séparément et ne sont pas déduites ici.
        $forecastAvailableRecurringOnly=$forecast-(float)($fixed['recurring_forecast']??0);
        return [
            'opening_balance' => $opening,
            'real_inflows' => $inflows,
            'real_outflows' => $outflows,
            'real_balance' => $realBalance,
            'forecast_inflows' => $forecast,
            'forecast_outflows' => $forecastOutflows,
            'forecast_total_outflows' => $forecastOutflows,
            'forecast_feb_outflows' => $febForecastOutflows,
            'forecast_available_recurring_only' => $forecastAvailableRecurringOnly,
            'projected_balance' => $realBalance + $forecast - $forecastOutflows,
            'loans_received' => $loansReceived,
            'loans_repaid' => $loansRepaid,
            'loans_outstanding' => $loansOutstanding,
            'vat_credit_outstanding'=>$vatCreditOutstanding,'vat_credit_available'=>$vatCreditAvailable,'operating_available'=>$operatingAvailable,
            'turnover'=>$turnoverHt,'turnover_ht'=>$turnoverHt,'realized_profit'=>$profit,'profit_tax_25'=>$profitTax,
            'previous_real_inflows'=>$previous['inflows'],'previous_real_outflows'=>$previous['outflows'],
            'inflow_growth_pct'=>$growth($turnoverHt,$previousTurnoverHt),'outflow_growth_pct'=>$growth($outflows,$previous['outflows']),
            'fixed_charges_actual'=>(float)$fixed['actual'],'fixed_charges_forecast'=>(float)$fixed['forecast'],
            'recurring_needs_forecast'=>(float)($fixed['recurring_forecast']??0),
            'payroll_actual'=>(float)$payroll['actual'],'payroll_forecast'=>(float)$payroll['forecast'],
            'vat'=>$vat,'vat_collected'=>$vat['collected'],'vat_deductible'=>$vat['deductible'],'vat_remitted'=>$vat['remitted'],'vat_balance'=>$vat['balance'],
            'monthly_cashflow' => $monthly,
            'date_from'=>$dateFrom,'date_to'=>$dateTo,
            'forecast_days' => $forecastDays,
        ];
    }

    private function periodActualTotals(string $from,string $to): array
    {
        $p=['from'=>$from,'to'=>$to];$s=$this->pdo->prepare("SELECT COALESCE(SUM(CASE WHEN fv.collected_amount>0 THEN LEAST(fv.collected_amount,fv.total_ttc) ELSE fv.total_ttc END),0) FROM facture_vente_banamur fv JOIN chantier_banamur c ON c.id=fv.chantier_id AND c.archived_at IS NULL WHERE fv.paiement_statut='payee' AND DATE(COALESCE(fv.paid_at,fv.updated_at)) BETWEEN :from AND :to");$s->execute($p);$in=(float)$s->fetchColumn();
        $s=$this->pdo->prepare("SELECT COALESCE(SUM(f.montant_fiche),0) FROM fiche_decaissement f JOIN chantier_banamur c ON c.id=f.chantier_id AND c.archived_at IS NULL JOIN employee_legal_requests r ON r.id=f.feb_request_id AND r.status='approved' WHERE f.decaisse=1 AND COALESCE(DATE(f.date_decaissement),f.date_decaissement_minimum,f.planned_start_date) BETWEEN :from AND :to AND EXISTS(SELECT 1 FROM bon_commande bc WHERE bc.num_fiche=COALESCE(NULLIF(f.parent_num_fiche,''),NULLIF(f.code_parentale,''),f.num_fiche))");$s->execute($p);$out=(float)$s->fetchColumn();$fixed=$this->fixedChargeTotals($from,$to);return ['inflows'=>$in,'outflows'=>$out+(float)$fixed['actual']];
    }

    private function fixedChargeTotals(string $from,string $to): array
    {
        $s=$this->pdo->prepare("SELECT COALESCE(SUM(CASE WHEN status='paid' AND payment_date BETWEEN :f1 AND :t1 THEN amount ELSE 0 END),0) actual,COALESCE(SUM(CASE WHEN status='planned' AND due_date BETWEEN :f2 AND :t2 THEN amount ELSE 0 END),0) forecast FROM treasury_fixed_charges");$s->execute(['f1'=>$from,'t1'=>$to,'f2'=>$from,'t2'=>$to]);$totals=$s->fetch(PDO::FETCH_ASSOC)?:['actual'=>0,'forecast'=>0];
        $recurring=$this->pdo->prepare("SELECT COALESCE(SUM(n.amount_estimated*COALESCE(NULLIF(cur.rate_to_xof,0),1)),0) FROM finea_recurring_needs n LEFT JOIN chantier_banamur c ON c.id=n.chantier_id LEFT JOIN facturation_currencies cur ON cur.code COLLATE utf8mb4_unicode_ci=n.currency COLLATE utf8mb4_unicode_ci AND cur.is_active=1 WHERE n.status='active' AND n.financial_mode<>'sans_decaissement' AND n.amount_estimated>0 AND n.next_due_date BETWEEN :from AND :to AND (n.end_date IS NULL OR n.next_due_date<=n.end_date) AND (n.chantier_id IS NULL OR c.archived_at IS NULL)");$recurring->execute(compact('from','to'));$totals['recurring_forecast']=(float)$recurring->fetchColumn();$totals['forecast']=(float)$totals['forecast']+$totals['recurring_forecast'];return $totals;
    }

    private function payrollTotals(string $from,string $to): array
    {
        $s=$this->pdo->prepare("SELECT COALESCE(SUM(net_pay),0) FROM payroll_slips WHERE status IN ('generated','approved','validated') AND LAST_DAY(CONCAT(payroll_period,'-01')) BETWEEN :from AND :to");$s->execute(compact('from','to'));return ['actual'=>0.0,'forecast'=>(float)$s->fetchColumn()];
    }

    private function vatSummary(string $from,string $to): array
    {
        $sales=$this->pdo->prepare("SELECT fv.items_json,fv.discount FROM facture_vente_banamur fv JOIN chantier_banamur c ON c.id=fv.chantier_id AND c.archived_at IS NULL WHERE fv.paiement_statut='payee' AND COALESCE(fv.tax_not_invoiced,0)=0 AND DATE(COALESCE(fv.paid_at,fv.updated_at)) BETWEEN :from AND :to");$sales->execute(['from'=>$from,'to'=>$to]);$collected=0.0;foreach($sales->fetchAll(PDO::FETCH_ASSOC) as $row)$collected+=$this->itemsVat((string)($row['items_json']??''),(float)($row['discount']??0));
        $deductible=0.0;$purchases=$this->pdo->prepare("SELECT items_json FROM facture_achat_banamur WHERE statut='payee' AND invoice_date BETWEEN :from AND :to");$purchases->execute(['from'=>$from,'to'=>$to]);foreach($purchases->fetchAll(PDO::FETCH_ASSOC) as $row)$deductible+=$this->itemsVat((string)($row['items_json']??''),0);
        $r=$this->pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM treasury_vat_remittances WHERE payment_date BETWEEN :from AND :to");$r->execute(['from'=>$from,'to'=>$to]);$remitted=(float)$r->fetchColumn();return ['collected'=>$collected,'deductible'=>$deductible,'remitted'=>$remitted,'balance'=>$collected-$deductible-$remitted];
    }

    private function itemsVat(string $json,float $discount): float
    {
        $items=json_decode($json,true);if(!is_array($items))return 0.0;$ht=0.0;$vat=0.0;foreach($items as $item){$line=(float)($item['quantity']??$item['quantite']??1)*(float)($item['amount']??$item['unit_price']??$item['prix_unitaire']??0);$rate=(float)($item['tax_rate']??$item['tva']??$item['vat_rate']??0);$ht+=$line;$vat+=$line*$rate/100;}return $vat*($ht>0?max(0,$ht-$discount)/$ht:1);
    }

    private function salesTurnoverHt(string $from,string $to): float
    {
        $s=$this->pdo->prepare("SELECT fv.items_json,fv.discount,fv.total_ttc,fv.collected_amount FROM facture_vente_banamur fv JOIN chantier_banamur c ON c.id=fv.chantier_id AND c.archived_at IS NULL WHERE fv.paiement_statut='payee' AND DATE(COALESCE(fv.paid_at,fv.updated_at)) BETWEEN :from AND :to");$s->execute(compact('from','to'));$total=0.0;
        foreach($s->fetchAll(PDO::FETCH_ASSOC) as $row){$items=json_decode((string)($row['items_json']??''),true);$ht=0.0;if(is_array($items))foreach($items as $item)$ht+=(float)($item['quantity']??$item['quantite']??1)*(float)($item['amount']??$item['unit_price']??$item['prix_unitaire']??0);$ht=max(0,$ht-(float)($row['discount']??0));$ttc=(float)($row['total_ttc']??0);$collected=(float)($row['collected_amount']??0);$ratio=$ttc>0&&$collected>0?min(1,$collected/$ttc):1;$total+=$ht*$ratio;}
        return round($total,2);
    }

    public function saveVatRemittance(array $data): void { $s=$this->pdo->prepare("INSERT INTO treasury_vat_remittances(period_start,period_end,payment_date,amount,reference,document_path,note,created_by_user_id) VALUES(:period_start,:period_end,:payment_date,:amount,:reference,:document_path,:note,:user)");$s->execute($data); }
    public function listVatRemittances(string $from,string $to): array { $s=$this->pdo->prepare("SELECT * FROM treasury_vat_remittances WHERE payment_date BETWEEN :from AND :to ORDER BY payment_date DESC,id DESC");$s->execute(compact('from','to'));return $s->fetchAll(PDO::FETCH_ASSOC)?:[]; }
    public function saveFixedCharge(array $data): void { $s=$this->pdo->prepare("INSERT INTO treasury_fixed_charges(label,category,amount,due_date,payment_date,status,supplier_name,reference,document_path,note,created_by_user_id) VALUES(:label,:category,:amount,:due_date,:payment_date,:status,:supplier,:reference,:document_path,:note,:user)");$s->execute($data); }
    public function listFixedCharges(string $from,string $to): array { $s=$this->pdo->prepare("SELECT * FROM treasury_fixed_charges WHERE COALESCE(payment_date,due_date) BETWEEN :from AND :to ORDER BY COALESCE(payment_date,due_date),id DESC");$s->execute(compact('from','to'));return $s->fetchAll(PDO::FETCH_ASSOC)?:[]; }
    public function listRecurringCharges(string $from,string $to): array { $s=$this->pdo->prepare("SELECT n.id,n.reference,n.title label,n.amount_estimated,n.currency,n.next_due_date,n.frequency_type,n.frequency_interval,n.fournisseur_name,n.chantier_id,COALESCE(NULLIF(cur.rate_to_xof,0),1) rate_to_xof,n.amount_estimated*COALESCE(NULLIF(cur.rate_to_xof,0),1) amount_xof,c.code chantier_code,c.titre chantier_title FROM finea_recurring_needs n LEFT JOIN chantier_banamur c ON c.id=n.chantier_id LEFT JOIN facturation_currencies cur ON cur.code COLLATE utf8mb4_unicode_ci=n.currency COLLATE utf8mb4_unicode_ci AND cur.is_active=1 WHERE n.status='active' AND n.financial_mode<>'sans_decaissement' AND n.amount_estimated>0 AND n.next_due_date BETWEEN :from AND :to AND (n.end_date IS NULL OR n.next_due_date<=n.end_date) AND (n.chantier_id IS NULL OR c.archived_at IS NULL) ORDER BY n.next_due_date,n.id");$s->execute(compact('from','to'));return $s->fetchAll(PDO::FETCH_ASSOC)?:[]; }
    public function listForecastFebDetails(string $from,string $to): array
    {
        $s=$this->pdo->prepare("SELECT r.id feb_id,r.title feb_title,r.requester_name,r.status,jt.designation,COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.validation_deferred_until')),'null'),jt.start_date,JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.planned_start_date')),JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.date_livraison')),DATE(r.submitted_at)) planned_date,GREATEST(jt.qty,0)*GREATEST(jt.price,0) amount,'feb' source,NULL fiche_number FROM employee_legal_requests r JOIN chantier_banamur c ON c.id=CAST(JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.chantier_id')) AS UNSIGNED) AND c.archived_at IS NULL JOIN JSON_TABLE(r.payload_json,'$.besoins[*]' COLUMNS(designation VARCHAR(255) PATH '$.libelle' DEFAULT 'Besoin FEB' ON EMPTY,qty DECIMAL(18,2) PATH '$.quantite' DEFAULT 1 ON EMPTY,price DECIMAL(18,2) PATH '$.fournisseur.prix_unitaire' DEFAULT 0 ON EMPTY,start_date DATE PATH '$.planned_start_date' NULL ON EMPTY)) jt WHERE r.request_type='feb' AND r.status IN ('submitted','pending','approved') AND COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.validation_deferred_until')),'null'),jt.start_date,JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.planned_start_date')),JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.date_livraison')),DATE(r.submitted_at)) BETWEEN :from AND :to AND NOT EXISTS(SELECT 1 FROM fiche_decaissement f WHERE f.feb_request_id=r.id)");$s->execute(compact('from','to'));$rows=$s->fetchAll(PDO::FETCH_ASSOC)?:[];
        $s=$this->pdo->prepare("SELECT r.id feb_id,r.title feb_title,r.requester_name,r.status,r.title designation,COALESCE(f.date_decaissement_minimum,f.planned_start_date,DATE(f.date_creat_fiche)) planned_date,f.montant_fiche amount,'fiche' source,f.num_fiche fiche_number FROM fiche_decaissement f JOIN chantier_banamur c ON c.id=f.chantier_id AND c.archived_at IS NULL JOIN employee_legal_requests r ON r.id=f.feb_request_id AND r.request_type='feb' AND r.status='approved' WHERE COALESCE(f.decaisse,0)=0 AND COALESCE(f.date_decaissement_minimum,f.planned_start_date,DATE(f.date_creat_fiche)) BETWEEN :from AND :to AND EXISTS(SELECT 1 FROM bon_commande bc WHERE bc.num_fiche=COALESCE(NULLIF(f.parent_num_fiche,''),NULLIF(f.code_parentale,''),f.num_fiche))");$s->execute(compact('from','to'));$rows=array_merge($rows,$s->fetchAll(PDO::FETCH_ASSOC)?:[]);usort($rows,static fn(array $a,array $b):int=>[(string)$a['planned_date'],(int)$a['feb_id']]<=>[(string)$b['planned_date'],(int)$b['feb_id']]);return $rows;
    }
    public function payFixedCharge(int $id,string $date): void { $s=$this->pdo->prepare("UPDATE treasury_fixed_charges SET status='paid',payment_date=:date WHERE id=:id");$s->execute(compact('date','id')); }

    public function saveLoan(array $data): void
    {
        $st=$this->pdo->prepare("INSERT INTO treasury_loans(lender_name,loan_type,reference,principal_amount,received_date,due_date,interest_rate,note,created_by_user_id) VALUES(:lender,:type,:reference,:amount,:received,:due,:rate,:note,:user)");
        $st->execute($data);
    }

    public function listLoans(): array
    {
        return $this->pdo->query("SELECT *,GREATEST(principal_amount+principal_amount*interest_rate/100-repaid_amount,0) outstanding_amount FROM treasury_loans WHERE status<>'cancelled' ORDER BY received_date DESC,id DESC")->fetchAll(PDO::FETCH_ASSOC)?:[];
    }

    public function repayLoan(int $id,float $amount): void
    {
        $this->pdo->beginTransaction();
        try{$q=$this->pdo->prepare("SELECT principal_amount,interest_rate,repaid_amount,status FROM treasury_loans WHERE id=:id FOR UPDATE");$q->execute(['id'=>$id]);$row=$q->fetch(PDO::FETCH_ASSOC);if(!$row||$row['status']==='cancelled')throw new DomainException('Prêt introuvable ou clôturé.');
            $total=(float)$row['principal_amount']*(1+(float)$row['interest_rate']/100);$repaid=min($total,(float)$row['repaid_amount']+$amount);$st=$this->pdo->prepare("UPDATE treasury_loans SET repaid_amount=:repaid,status=:status WHERE id=:id");$st->execute(['repaid'=>$repaid,'status'=>$repaid+0.005>=$total?'repaid':'active','id'=>$id]);$this->pdo->commit();
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function saveOpeningBalance(string $date, float $amount, int $userId, string $note): void
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO treasury_opening_balances
                (effective_date,amount,currency,note,created_by_user_id)
             VALUES (:effective_date,:amount,'XOF',:note,:user_id)
             ON DUPLICATE KEY UPDATE amount=VALUES(amount),note=VALUES(note),
                 created_by_user_id=VALUES(created_by_user_id)"
        );
        $statement->execute([
            'effective_date' => $date,
            'amount' => $amount,
            'note' => $note ?: null,
            'user_id' => $userId ?: null,
        ]);
    }

    public function unmask(int $devisId): void
    {
        try {
            $statement=$this->pdo->prepare('UPDATE devis_banamur SET masque=0,masked_at=NULL,masked_by_user_id=NULL WHERE id=:devis_id');
            $statement->execute(['devis_id'=>$devisId]);
        } catch (Throwable $e) {
            $statement=$this->pdo->prepare('UPDATE devis_banamur SET masque=0 WHERE id=:devis_id');
            $statement->execute(['devis_id'=>$devisId]);
        }
    }
}
