<?php

require_once __DIR__ . '/PasskeySignatureGuard.php';
require_once __DIR__ . '/RhPersonnelBankService.php';
require_once __DIR__ . '/../model/Mailer.php';
require_once __DIR__ . '/../model/AuditTrail.php';

final class RhPayrollWorkflowService
{
    private RhPersonnelBankService $banks;

    public function __construct(private PDO $pdo)
    {
        $this->ensureSchema();
        $this->banks = new RhPersonnelBankService($pdo);
    }

    private function ensureSchema(): void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS rh_payroll_workflows (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            payroll_period CHAR(7) NOT NULL,
            status ENUM('draft','rh_signed','dg_signed','distributed') NOT NULL DEFAULT 'draft',
            initiated_by INT NULL, initiated_by_name VARCHAR(190) NULL,
            rh_signed_by INT NULL, rh_signed_by_name VARCHAR(190) NULL, rh_signed_at DATETIME NULL,
            dg_signed_by INT NULL, dg_signed_by_name VARCHAR(190) NULL, dg_signed_at DATETIME NULL,
            distributed_at DATETIME NULL, distribution_report_json LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_rh_payroll_workflow_period (payroll_period)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS rh_payroll_distribution_log (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            payroll_period CHAR(7) NOT NULL, personnel_id INT NOT NULL, payroll_slip_id INT NULL,
            employee_email VARCHAR(190) NULL,
            status ENUM('pending','sent','failed','skipped') NOT NULL DEFAULT 'pending',
            attempt_count INT UNSIGNED NOT NULL DEFAULT 0, last_error VARCHAR(1000) NULL,
            sent_at DATETIME NULL, last_attempt_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_rh_payroll_distribution_person (payroll_period, personnel_id),
            KEY idx_rh_payroll_distribution_status (payroll_period,status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function getOrCreate(string $period, array $actor): array
    {
        $this->assertPeriod($period);
        $stmt=$this->pdo->prepare('INSERT IGNORE INTO rh_payroll_workflows(payroll_period,initiated_by,initiated_by_name) VALUES(?,?,?)');
        $stmt->execute([$period,$actor['user_id']??null,$actor['user_name']??null]);
        return $this->find($period);
    }

    public function find(string $period): array
    {
        $this->assertPeriod($period);
        $stmt=$this->pdo->prepare('SELECT * FROM rh_payroll_workflows WHERE payroll_period=?');
        $stmt->execute([$period]);
        return $stmt->fetch(PDO::FETCH_ASSOC)?:[];
    }

    public function sign(string $period,string $role,array $actor): array
    {
        $workflow=$this->getOrCreate($period,$actor);
        $userId=(int)($actor['user_id']??0);
        PasskeySignatureGuard::requireVerified($userId, 'rh_payroll:'.$period.':'.$role);
        $rights=$this->pdo->prepare('SELECT acces_rh,acces_direction,acces_admin FROM user_devis_banamur WHERE id=? AND active=1');
        $rights->execute([$userId]);
        $access=$rights->fetch(PDO::FETCH_ASSOC)?:[];
        if($role==='rh'&&empty($access['acces_rh'])&&empty($access['acces_admin'])) throw new RuntimeException('La signature RH est réservée aux Ressources humaines.');
        if($role==='dg'&&empty($access['acces_direction'])&&empty($access['acces_admin'])) throw new RuntimeException('La signature DG est réservée à la Direction générale.');
        if($role==='rh'&&$workflow['status']==='draft'){
            $preflight=$this->preflight($period);
            if(($preflight['blocking_count']??0)>0) throw new RuntimeException('La paie comporte des anomalies bloquantes. Corrigez-les avant la signature RH.');
            $stmt=$this->pdo->prepare("UPDATE rh_payroll_workflows SET status='rh_signed',rh_signed_by=?,rh_signed_by_name=?,rh_signed_at=NOW() WHERE payroll_period=? AND status='draft'");
        } elseif($role==='dg'&&$workflow['status']==='rh_signed'){
            $stmt=$this->pdo->prepare("UPDATE rh_payroll_workflows SET status='dg_signed',dg_signed_by=?,dg_signed_by_name=?,dg_signed_at=NOW() WHERE payroll_period=? AND status='rh_signed'");
        } else throw new RuntimeException('Cette signature ne correspond pas à l’étape actuellement attendue.');
        $stmt->execute([$userId,(string)($actor['user_name']??''),$period]);
        if($stmt->rowCount()!==1) throw new RuntimeException('Le dossier de paie a changé. Rechargez la page.');
        AuditTrail::log('RH_PAYROLL_WORKFLOW_SIGNED',['module'=>'rh','action'=>'payroll_workflow_signed','user_id'=>$userId,'user_name'=>(string)($actor['user_name']??''),'entity_type'=>'payroll_period','entity_label'=>$period,'details'=>['role'=>$role,'period'=>$period]]);
        return $this->find($period);
    }

    public function slips(string $from,string $to,int $personnelId=0): array
    {
        $this->assertPeriod($from);$this->assertPeriod($to);
        if($from>$to) throw new RuntimeException('La période de début doit précéder la période de fin.');
        $sql="SELECT ps.*,p.nom_personnel_soignant employee_name,p.matricule_personnel_soignant matricule,p.email_personnel_soignant employee_email
            FROM payroll_slips ps JOIN personnel p ON p.id_personnel_soignant=ps.personnel_id
            JOIN (SELECT personnel_id,payroll_period,MAX(id) id FROM payroll_slips WHERE payroll_period BETWEEN ? AND ? GROUP BY personnel_id,payroll_period) latest ON latest.id=ps.id
            WHERE COALESCE(p.statut_pers_soignant_code,0) NOT IN (2,3)
              AND COALESCE(NULLIF(p.personnel_category,''),'interne') <> 'prestataire'";
        $params=[$from,$to];
        if($personnelId>0){$sql.=' AND ps.personnel_id=?';$params[]=$personnelId;}
        $sql.=' ORDER BY ps.payroll_period,employee_name';
        $stmt=$this->pdo->prepare($sql);$stmt->execute($params);
        $rows=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
        $bankMap=$this->banks->mapForPersonnelIds(array_column($rows,'personnel_id'));
        foreach($rows as &$row){
            $row['payload']=json_decode((string)($row['payload_json']??''),true)?:[];
            $bank=$bankMap[(int)$row['personnel_id']]??[];
            $row['bank_name']=$bank['bank_name']??'';$row['bank_account_holder']=$bank['account_holder']??'';
            $row['bank_account_number']=$bank['account_number']??'';$row['bank_iban']=$bank['iban']??'';$row['bank_swift_bic']=$bank['swift_bic']??'';
            $row['bank_complete']=$this->banks->isComplete($bank);
        }
        unset($row);return $rows;
    }

    public function attendanceControl(string $period): array
    {
        $this->assertPeriod($period);[$start,$end]=$this->periodBounds($period);
        $sql="SELECT p.id_personnel_soignant personnel_id,p.nom_personnel_soignant employee_name,
            COUNT(a.id) attendance_rows,
            SUM(CASE WHEN a.check_in_time IS NOT NULL AND a.check_out_time IS NULL THEN 1 ELSE 0 END) incomplete_rows,
            SUM(CASE WHEN a.attendance_status='absent' THEN 1 ELSE 0 END) absent_rows,
            COALESCE(SUM(a.overtime_hours),0) overtime_hours
            FROM personnel p LEFT JOIN rh_attendance_daily a ON a.personnel_id=p.id_personnel_soignant AND a.attendance_date BETWEEN ? AND ?
            WHERE (p.date_sortie IS NULL OR p.date_sortie='0000-00-00' OR p.date_sortie>=?)
              AND (p.date_recrutement IS NULL OR p.date_recrutement='0000-00-00' OR p.date_recrutement<=?)
              AND COALESCE(p.statut_pers_soignant_code,0) NOT IN (2,3)
              AND COALESCE(NULLIF(p.personnel_category,''),'interne') <> 'prestataire'
            GROUP BY p.id_personnel_soignant,p.nom_personnel_soignant ORDER BY employee_name";
        $stmt=$this->pdo->prepare($sql);$stmt->execute([$start,$end,$start,$end]);return $stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
    }

    public function preflight(string $period): array
    {
        $this->assertPeriod($period);$slips=$this->slips($period,$period);$slipByPerson=[];
        foreach($slips as $row)$slipByPerson[(int)$row['personnel_id']]=$row;
        $attendance=$this->attendanceControl($period);$issues=[];
        if(!$slips)$issues[]=['severity'=>'blocking','code'=>'no_slips','personnel_id'=>0,'employee'=>'','message'=>'Aucun bulletin figé n’a été généré pour cette période.'];
        foreach($attendance as $row){
            $pid=(int)$row['personnel_id'];$name=(string)$row['employee_name'];
            if(!isset($slipByPerson[$pid]))$issues[]=['severity'=>'blocking','code'=>'missing_slip','personnel_id'=>$pid,'employee'=>$name,'message'=>'Aucun bulletin de salaire figé pour ce salarié.'];
            if((int)$row['attendance_rows']===0)$issues[]=['severity'=>'warning','code'=>'missing_attendance','personnel_id'=>$pid,'employee'=>$name,'message'=>'Aucune donnée de pointage récupérée pour la période.'];
            if((int)$row['incomplete_rows']>0)$issues[]=['severity'=>'warning','code'=>'incomplete_attendance','personnel_id'=>$pid,'employee'=>$name,'message'=>(int)$row['incomplete_rows'].' journée(s) avec sortie manquante.'];
        }
        foreach($slips as $row){
            $pid=(int)$row['personnel_id'];$name=(string)$row['employee_name'];
            if((float)$row['gross_salary']<0||(float)$row['net_pay']<0)$issues[]=['severity'=>'blocking','code'=>'negative_payroll','personnel_id'=>$pid,'employee'=>$name,'message'=>'Montant brut ou net négatif : bulletin à contrôler.'];
            if(trim((string)($row['employee_email']??''))===''||!filter_var((string)$row['employee_email'],FILTER_VALIDATE_EMAIL))$issues[]=['severity'=>'warning','code'=>'missing_email','personnel_id'=>$pid,'employee'=>$name,'message'=>'Adresse e-mail absente ou invalide : diffusion automatique impossible.'];
            if(empty($row['bank_complete']))$issues[]=['severity'=>'blocking','code'=>'missing_bank','personnel_id'=>$pid,'employee'=>$name,'message'=>'Coordonnées bancaires de paie absentes ou incomplètes.'];
        }
        try{
            [$start,$end]=$this->periodBounds($period);
            $q=$this->pdo->prepare("SELECT p.id_personnel_soignant,p.nom_personnel_soignant FROM personnel p LEFT JOIN employee_contracts ec ON ec.personnel_id=p.id_personnel_soignant AND ec.active=1 AND ec.start_date<=? AND (ec.end_date IS NULL OR ec.end_date='0000-00-00' OR ec.end_date>=?) WHERE (p.date_sortie IS NULL OR p.date_sortie='0000-00-00' OR p.date_sortie>=?) AND COALESCE(p.statut_pers_soignant_code,0) NOT IN (2,3) AND COALESCE(NULLIF(p.personnel_category,''),'interne') <> 'prestataire' AND ec.id IS NULL ORDER BY p.nom_personnel_soignant");
            $q->execute([$end,$start,$start]);foreach($q->fetchAll(PDO::FETCH_ASSOC)?:[] as $r)$issues[]=['severity'=>'blocking','code'=>'missing_contract','personnel_id'=>(int)$r['id_personnel_soignant'],'employee'=>(string)$r['nom_personnel_soignant'],'message'=>'Aucun contrat RH actif couvrant cette période.'];
        }catch(Throwable $e){}
        $blocking=count(array_filter($issues,static fn($i)=>($i['severity']??'')==='blocking'));$warnings=count($issues)-$blocking;
        return ['period'=>$period,'issues'=>$issues,'blocking_count'=>$blocking,'warning_count'=>$warnings,'slip_count'=>count($slips),'attendance_count'=>count($attendance)];
    }

    public function distributionLog(string $period): array
    {
        $this->assertPeriod($period);$stmt=$this->pdo->prepare('SELECT * FROM rh_payroll_distribution_log WHERE payroll_period=? ORDER BY id');$stmt->execute([$period]);return $stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
    }

    public function markDistributed(string $period,array $report): void
    {
        $stmt=$this->pdo->prepare("UPDATE rh_payroll_workflows SET status='distributed',distributed_at=NOW(),distribution_report_json=? WHERE payroll_period=? AND status='dg_signed'");$stmt->execute([json_encode($report,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$period]);
    }

    public function distribute(string $period,bool $retryFailedOnly=false): array
    {
        $workflow=$this->find($period);if(!in_array(($workflow['status']??''),['dg_signed','distributed'],true))throw new RuntimeException('La signature du DG est requise avant l’envoi des bulletins.');
        if(($workflow['status']??'')==='distributed'&&!$retryFailedOnly)return ['sent'=>0,'failed'=>0,'skipped'=>0,'already_sent'=>count(array_filter($this->distributionLog($period),fn($r)=>$r['status']==='sent'))];
        $rows=$this->slips($period,$period);$report=['sent'=>0,'failed'=>0,'skipped'=>0,'already_sent'=>0];
        foreach($rows as $row){
            $pid=(int)$row['personnel_id'];$email=trim((string)($row['employee_email']??''));$existing=$this->distributionRow($period,$pid);
            if(($existing['status']??'')==='sent'){$report['already_sent']++;continue;}if($retryFailedOnly&&($existing['status']??'')!=='failed')continue;
            if(!filter_var($email,FILTER_VALIDATE_EMAIL)){$this->writeDistributionRow($period,$pid,(int)($row['id']??0),$email,'skipped','Adresse e-mail absente ou invalide.');$report['skipped']++;continue;}
            try{
                $attachments=[['name'=>'bulletin_'.$period.'.pdf','content'=>$this->slipPdf($row),'type'=>'application/pdf'],['name'=>'pointage_'.$period.'.pdf','content'=>$this->attendancePdf($pid,$period,(string)$row['employee_name']),'type'=>'application/pdf']];
                $subject='Votre bulletin et votre pointage — '.$period;$body='<p>Votre paie du mois <strong>'.htmlspecialchars($period).'</strong> a été validée par la Direction générale.</p><p>Votre bulletin individuel et le relevé de pointage retenu sont joints à ce message.</p>';
                $html=Mailer::buildFineaNotificationEmail($subject,$body,'employe/pointage_employe.php',['header_badge'=>'Paie validée','button_label'=>'Consulter mon espace']);
                $ok=Mailer::send($email,(string)$row['employee_name'],$subject,$html,strip_tags($body),['attachments'=>$attachments,'from_name'=>'Finéa · Ressources humaines']);
                if($ok){$this->writeDistributionRow($period,$pid,(int)($row['id']??0),$email,'sent',null);$report['sent']++;}else{$this->writeDistributionRow($period,$pid,(int)($row['id']??0),$email,'failed','Le service de messagerie a refusé ou interrompu l’envoi.');$report['failed']++;}
            }catch(Throwable $e){$this->writeDistributionRow($period,$pid,(int)($row['id']??0),$email,'failed',$e->getMessage());$report['failed']++;}
        }
        if($report['failed']===0&&($workflow['status']??'')==='dg_signed')$this->markDistributed($period,$report);else{$q=$this->pdo->prepare('UPDATE rh_payroll_workflows SET distribution_report_json=? WHERE payroll_period=?');$q->execute([json_encode($report,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$period]);}
        AuditTrail::log('RH_PAYROLL_DISTRIBUTION',['module'=>'rh','action'=>'payroll_distribution','entity_type'=>'payroll_period','entity_label'=>$period,'result'=>$report['failed']>0?'partial':'success','details'=>$report]);return $report;
    }

    private function distributionRow(string $period,int $personnelId): array
    {
        $q=$this->pdo->prepare('SELECT * FROM rh_payroll_distribution_log WHERE payroll_period=? AND personnel_id=? LIMIT 1');$q->execute([$period,$personnelId]);return $q->fetch(PDO::FETCH_ASSOC)?:[];
    }

    private function writeDistributionRow(string $period,int $personnelId,int $slipId,string $email,string $status,?string $error): void
    {
        $sql="INSERT INTO rh_payroll_distribution_log(payroll_period,personnel_id,payroll_slip_id,employee_email,status,attempt_count,last_error,sent_at,last_attempt_at) VALUES(?,?,?,?,?,1,?,IF(?='sent',NOW(),NULL),NOW()) ON DUPLICATE KEY UPDATE payroll_slip_id=VALUES(payroll_slip_id),employee_email=VALUES(employee_email),status=VALUES(status),attempt_count=attempt_count+1,last_error=VALUES(last_error),sent_at=IF(VALUES(status)='sent',NOW(),sent_at),last_attempt_at=NOW()";
        $q=$this->pdo->prepare($sql);$q->execute([$period,$personnelId,$slipId?:null,$email?:null,$status,$error,$status]);
    }

    private function slipPdf(array $r): string
    {
        require_once __DIR__.'/../vendor/tecnickcom/tcpdf/tcpdf.php';$pdf=new TCPDF('P','mm','A4',true,'UTF-8',false);$pdf->setPrintHeader(false);$pdf->setPrintFooter(false);$pdf->SetMargins(14,14,14);$pdf->AddPage();
        $pdf->SetFillColor(15,23,42);$pdf->SetTextColor(255);$pdf->SetFont('dejavusans','B',18);$pdf->Cell(0,18,'BULLETIN DE SALAIRE',0,1,'L',true);$pdf->SetTextColor(15,23,42);$pdf->Ln(5);$pdf->SetFont('dejavusans','B',13);$pdf->Cell(0,8,(string)$r['employee_name'].' · '.(string)$r['payroll_period'],0,1);
        $m=static fn($v)=>number_format((float)$v,0,',',' ').' FCFA';$pdf->SetFont('dejavusans','',10.5);foreach(['Salaire brut'=>$r['gross_salary'],'Cotisations salariales'=>$r['employee_contributions'],'Impôt net'=>$r['net_tax']] as $label=>$value){$pdf->Cell(120,9,$label,1,0);$pdf->Cell(0,9,$m($value),1,1,'R');}$pdf->SetFillColor(5,150,105);$pdf->SetTextColor(255);$pdf->SetFont('dejavusans','B',15);$pdf->Cell(120,14,'NET À PAYER',1,0,'L',true);$pdf->Cell(0,14,$m($r['net_pay']),1,1,'R',true);return $pdf->Output('','S');
    }

    private function attendancePdf(int $personnelId,string $period,string $name): string
    {
        require_once __DIR__.'/../vendor/tecnickcom/tcpdf/tcpdf.php';[$start,$end]=$this->periodBounds($period);$q=$this->pdo->prepare('SELECT attendance_date,attendance_status,check_in_time,check_out_time,worked_days,overtime_hours FROM rh_attendance_daily WHERE personnel_id=? AND attendance_date BETWEEN ? AND ? ORDER BY attendance_date');$q->execute([$personnelId,$start,$end]);
        $pdf=new TCPDF('P','mm','A4',true,'UTF-8',false);$pdf->setPrintHeader(false);$pdf->setPrintFooter(false);$pdf->SetMargins(14,14,14);$pdf->AddPage();$pdf->SetFont('dejavusans','B',17);$pdf->Cell(0,12,'RELEVÉ DE POINTAGE · '.$period,0,1);$pdf->SetFont('dejavusans','',11);$pdf->Cell(0,8,$name,0,1);
        $html='<table border="1" cellpadding="5"><tr style="background-color:#fabe02;font-weight:bold"><th>Date</th><th>Statut</th><th>Arrivée</th><th>Départ</th><th>Jours</th><th>HS</th></tr>';foreach($q->fetchAll(PDO::FETCH_ASSOC)?:[] as $r)$html.='<tr><td>'.htmlspecialchars((string)$r['attendance_date']).'</td><td>'.htmlspecialchars((string)$r['attendance_status']).'</td><td>'.htmlspecialchars(substr((string)$r['check_in_time'],0,5)).'</td><td>'.htmlspecialchars(substr((string)$r['check_out_time'],0,5)).'</td><td>'.htmlspecialchars((string)$r['worked_days']).'</td><td>'.htmlspecialchars((string)$r['overtime_hours']).'</td></tr>';$html.='</table>';$pdf->writeHTML($html,true,false,true,false,'');return $pdf->Output('','S');
    }

    private function periodBounds(string $period): array
    {
        $start=$period.'-01';$end=(new DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d');return[$start,$end];
    }

    private function assertPeriod(string $period): void
    {
        if(!preg_match('/^\d{4}-\d{2}$/',$period))throw new RuntimeException('Période de paie invalide.');$date=DateTimeImmutable::createFromFormat('!Y-m',$period);$errors=DateTimeImmutable::getLastErrors();if(!$date||($errors!==false&&(($errors['warning_count']??0)>0||($errors['error_count']??0)>0))||$date->format('Y-m')!==$period)throw new RuntimeException('Période de paie invalide.');
    }
}
