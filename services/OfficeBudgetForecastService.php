<?php
declare(strict_types=1);

final class OfficeBudgetForecastService
{
    private const OFFICE_SITE_CODE = 'CH066';

    public function __construct(private PDO $pdo) {}

    public function report(array $filters): array
    {
        $targetMonths=max(1,min(24,(int)($filters['target_months']??3)));
        $periodMode=(string)($filters['period_mode']??'auto')==='custom'?'custom':'auto';
        $lookbackMonths=$targetMonths<=3?6:($targetMonths<=6?12:($targetMonths<=12?24:36));
        if($periodMode==='custom'){$from=$this->date((string)($filters['from']??''),date('Y-m-01',strtotime('-'.($lookbackMonths-1).' months')));$to=$this->date((string)($filters['to']??''),date('Y-m-d'));}else{$to=date('Y-m-d');$from=date('Y-m-01',strtotime('-'.($lookbackMonths-1).' months'));}
        if($from>$to)[$from,$to]=[$to,$from];
        $reserve=max(0,min(50,(float)($filters['reserve']??10)));
        $serviceId=max(0,(int)($filters['service_id']??0));
        // Pour une fiche issue d'une FEB, l'affectation courante de la FEB est la
        // source de vérité. Les correspondances sur la fiche ne servent que pour
        // l'historique antérieur à feb_request_id/meta_service_id.
        $febPayloadService='CASE WHEN JSON_VALID(er.payload_json) THEN CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(er.payload_json,"$.departement_id")),"") AS UNSIGNED) ELSE NULL END';
        $legacyService='CASE
            WHEN f.serv_bureau_banamur_id=6 OR UPPER(TRIM(f.designation_fiche)) IN ("SERVICE INFORMATIQUE","INFORMATIQUE") THEN 2
            WHEN f.serv_bureau_banamur_id=8 OR UPPER(TRIM(f.designation_fiche)) IN ("SERVICE LOGISTIQUE","LOGISTIQUE") THEN 17
            WHEN UPPER(TRIM(f.designation_fiche)) IN ("SERVICE RH","RESSOURCES HUMAINES","RESSOURCE HUMAINE") THEN 1
            WHEN UPPER(TRIM(f.designation_fiche)) IN ("SERVICE HSE","HSE","QHSE","HSE/QHSE") THEN 20
            WHEN f.serv_bureau_banamur_id=9 OR UPPER(TRIM(f.designation_fiche)) IN ("DIRECTION GÉNÉRALE","DIRECTION GENERALE","DIRECTION GENERAL") THEN 18
            WHEN UPPER(TRIM(f.designation_fiche)) IN ("COMPTABILITE","COMPTABILITÉ","SERVICE COMPTABILITE","SERVICE COMPTABILITÉ") THEN 3
            WHEN UPPER(TRIM(f.designation_fiche)) IN ("TECHNIQUE","SERVICE TECHNIQUE") THEN 14
            WHEN UPPER(TRIM(f.designation_fiche)) IN ("COMMERCIAL","SERVICE COMMERCIAL") THEN 19
            WHEN UPPER(TRIM(f.designation_fiche)) IN ("SECURITE","SÉCURITÉ","SERVICE SECURITE","SERVICE SÉCURITÉ") THEN 22
            ELSE NULL END';
        $effectiveService='COALESCE(er.meta_service_id,'.$febPayloadService.','.$legacyService.')';
        $where=['UPPER(TRIM(c.code)) IN (:office_site_code,:legacy_office_site_code)','DATE(COALESCE(NULLIF(f.date_decaisse,"0000-00-00 00:00:00"),f.date_creat_fiche)) BETWEEN :from AND :to'];
        $params=['office_site_code'=>self::OFFICE_SITE_CODE,'legacy_office_site_code'=>'CH0066','from'=>$from,'to'=>$to];if($serviceId>0){$where[]=$effectiveService.'=:service';$params['service']=$serviceId;}
        $sql='SELECT f.id_fiche,f.num_fiche,f.beficiaire_fiche,f.montant_fiche,f.date_creat_fiche,f.date_decaisse,f.designation_fiche,f.precision_fiche,f.details_demande,f.decaisse,f.approuve,f.conforme,f.etat_fiche,f.serv_bureau_banamur_id,f.feb_request_id,
            '.$effectiveService.' effective_service_id,
            COALESCE(NULLIF(TRIM(s.lib_service),""),NULLIF(TRIM(f.designation_fiche),""),"Service non renseigné") service_name,
            CASE WHEN er.meta_service_id IS NOT NULL OR '.$febPayloadService.' IS NOT NULL THEN "Affectation actuelle de la FEB" ELSE "Historique de la fiche" END service_source,
            er.status feb_status,
            COALESCE(fp.paid_amount,0) feb_paid_amount,COALESCE(fp.paid_count,0) feb_paid_count,COALESCE(fp.pending_count,0) feb_pending_count,
            c.code chantier_code,c.titre chantier_name,
            COALESCE(NULLIF(f.date_decaisse,"0000-00-00 00:00:00"),f.date_creat_fiche) reference_date
            FROM fiche_decaissement f
            INNER JOIN chantier_banamur c ON c.id=f.chantier_id
            LEFT JOIN employee_legal_requests er ON er.id=f.feb_request_id AND er.request_type="feb"
            LEFT JOIN (SELECT feb_request_id,
                SUM(CASE WHEN decaisse=1 THEN montant_fiche ELSE 0 END) paid_amount,
                SUM(CASE WHEN decaisse=1 THEN 1 ELSE 0 END) paid_count,
                SUM(CASE WHEN decaisse=0 AND COALESCE(approuve,0)<>2 AND COALESCE(etat_fiche,0)<>2 THEN 1 ELSE 0 END) pending_count
                FROM fiche_decaissement WHERE feb_request_id IS NOT NULL GROUP BY feb_request_id) fp ON fp.feb_request_id=er.id
            LEFT JOIN service s ON s.id_service='.$effectiveService.'
            WHERE '.implode(' AND ',$where).' ORDER BY reference_date DESC,f.id_fiche DESC';
        $stmt=$this->pdo->prepare($sql);$stmt->execute($params);$details=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
        $days=max(1,(int)((strtotime($to)-strtotime($from))/86400)+1);$months=max(1/30.4375,$days/30.4375);$midpoint=date('Y-m-d',(int)((strtotime($from)+strtotime($to))/2));
        $groups=[];$totalSpent=0.0;$totalPending=0.0;
        foreach($details as &$row){
            $spent=(int)$row['decaisse']===1;
            $row['final_disbursed_amount']=$spent?(float)$row['montant_fiche']:0.0;
            if((int)$row['feb_request_id']>0){
                if((int)$row['feb_paid_count']>0&&(int)$row['feb_pending_count']>0)$row['status_label']='Partiellement décaissée';
                elseif((int)$row['feb_paid_count']>0)$row['status_label']='Décaissée';
                elseif(in_array((string)$row['feb_status'],['rejected','cancelled'],true))$row['status_label']='Refusée';
                elseif((string)$row['feb_status']==='approved')$row['status_label']='En attente de décaissement';
                else $row['status_label']='En attente';
            }else $row['status_label']=$spent?'Décaissée':(((int)$row['approuve']===2||(int)$row['etat_fiche']===2)?'Refusée':'En attente');
            $key=(int)($row['effective_service_id']??0);$groups[$key]??=['service_id'=>$key,'service_name'=>(string)$row['service_name'],'spent_amount'=>0.0,'pending_amount'=>0.0,'spent_count'=>0,'pending_count'=>0,'recent_spent'=>0.0,'previous_spent'=>0.0,'largest_expense'=>0.0];$amount=(float)$row['montant_fiche'];if($spent){$paidAmount=(float)$row['final_disbursed_amount'];$groups[$key]['spent_amount']+=$paidAmount;$groups[$key]['spent_count']++;$groups[$key][substr((string)$row['reference_date'],0,10)>=$midpoint?'recent_spent':'previous_spent']+=$paidAmount;$groups[$key]['largest_expense']=max($groups[$key]['largest_expense'],$paidAmount);$totalSpent+=$paidAmount;}elseif(!in_array($row['status_label'],['Refusée'],true)){$groups[$key]['pending_amount']+=$amount;$groups[$key]['pending_count']++;$totalPending+=$amount;}}
        unset($row);$halfMonths=max(.5,$months/2);$totalSuggested=0.0;
        foreach($groups as &$group){$monthly=$group['spent_amount']/$months;$previousRate=$group['previous_spent']/$halfMonths;$recentRate=$group['recent_spent']/$halfMonths;$trend=$previousRate>0?(($recentRate-$previousRate)/$previousRate)*100:($recentRate>0?100:0);$trend=max(-50,min(100,$trend));$trendProvision=max(0,min(.35,$trend/100*.5));$suggested=$monthly*$targetMonths*(1+$reserve/100+$trendProvision);$group['monthly_average']=$monthly;$group['suggested_monthly_budget']=$suggested/$targetMonths;$group['trend_percent']=$trend;$group['suggested_budget']=$suggested;$group['coverage_months']=$targetMonths;$group['share_percent']=$totalSpent>0?$group['spent_amount']/$totalSpent*100:0;$totalSuggested+=$suggested;}unset($group);
        usort($groups,static fn($a,$b)=>$b['suggested_budget']<=>$a['suggested_budget']);
        $services=$this->pdo->query('SELECT id_service id,lib_service name FROM service ORDER BY lib_service')->fetchAll(PDO::FETCH_ASSOC)?:[];
        $observedMonthlyAverage=$totalSpent/$months;
        $suggestedMonthlyBudget=$totalSuggested/$targetMonths;
        $officeSiteCode=self::OFFICE_SITE_CODE;
        return compact('from','to','targetMonths','reserve','days','months','groups','details','totalSpent','totalPending','totalSuggested','observedMonthlyAverage','suggestedMonthlyBudget','services','serviceId','periodMode','lookbackMonths','officeSiteCode')+['method'=>'Regroupement selon le service bénéficiaire actuellement affecté à chaque FEB, puis moyenne mensuelle des décaissements du chantier '.self::OFFICE_SITE_CODE.' Bureau × période cible + réserve + 50 % de la tendance positive, plafonnée à 35 %.'];
    }

    private function date(string $value,string $fallback): string{return preg_match('/^\d{4}-\d{2}-\d{2}$/',$value)&&strtotime($value)!==false?$value:$fallback;}
}
