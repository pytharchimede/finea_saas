<?php

require_once dirname(__DIR__, 3) . '/services/RhEmployeeContractClausePresetService.php';

class EmployeeContractRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureTable();
    }

    public function ensureTable(): void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS employee_contracts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            personnel_id INT NOT NULL,
            contract_type_id INT NULL,
            contract_reference VARCHAR(100) NULL,
            contract_label VARCHAR(150) NULL,
            start_date DATE NOT NULL,
            end_date DATE NULL,
            base_salary DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            over_salary DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            transport_locality_code VARCHAR(50) NULL,
            workplace VARCHAR(190) NULL,
            job_title VARCHAR(190) NULL,
            classification VARCHAR(120) NULL,
            work_schedule_label VARCHAR(190) NULL,
            weekly_hours DECIMAL(8,2) NOT NULL DEFAULT 40.00,
            monthly_working_days DECIMAL(8,2) NOT NULL DEFAULT 26.00,
            daily_work_start TIME NULL,
            daily_work_end TIME NULL,
            probation_period VARCHAR(120) NULL,
            clauses_json TEXT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'active',
            active TINYINT(1) NOT NULL DEFAULT 1,
            notes TEXT NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_employee_contract_personnel (personnel_id),
            INDEX idx_employee_contract_type (contract_type_id),
            INDEX idx_employee_contract_dates (start_date, end_date),
            INDEX idx_employee_contract_active (active, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->ensureColumns([
            'contract_type_id' => 'ALTER TABLE employee_contracts ADD COLUMN contract_type_id INT NULL AFTER personnel_id',
            'contract_reference' => 'ALTER TABLE employee_contracts ADD COLUMN contract_reference VARCHAR(100) NULL AFTER contract_type_id',
            'contract_label' => 'ALTER TABLE employee_contracts ADD COLUMN contract_label VARCHAR(150) NULL AFTER contract_reference',
            'end_date' => 'ALTER TABLE employee_contracts ADD COLUMN end_date DATE NULL AFTER start_date',
            'base_salary' => 'ALTER TABLE employee_contracts ADD COLUMN base_salary DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER end_date',
            'over_salary' => 'ALTER TABLE employee_contracts ADD COLUMN over_salary DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER base_salary',
            'transport_locality_code' => 'ALTER TABLE employee_contracts ADD COLUMN transport_locality_code VARCHAR(50) NULL AFTER over_salary',
            'workplace' => 'ALTER TABLE employee_contracts ADD COLUMN workplace VARCHAR(190) NULL AFTER transport_locality_code',
            'job_title' => 'ALTER TABLE employee_contracts ADD COLUMN job_title VARCHAR(190) NULL AFTER workplace',
            'classification' => 'ALTER TABLE employee_contracts ADD COLUMN classification VARCHAR(120) NULL AFTER job_title',
            'work_schedule_label' => 'ALTER TABLE employee_contracts ADD COLUMN work_schedule_label VARCHAR(190) NULL AFTER classification',
            'weekly_hours' => 'ALTER TABLE employee_contracts ADD COLUMN weekly_hours DECIMAL(8,2) NOT NULL DEFAULT 40.00 AFTER work_schedule_label',
            'monthly_working_days' => 'ALTER TABLE employee_contracts ADD COLUMN monthly_working_days DECIMAL(8,2) NOT NULL DEFAULT 26.00 AFTER weekly_hours',
            'daily_work_start' => 'ALTER TABLE employee_contracts ADD COLUMN daily_work_start TIME NULL AFTER monthly_working_days',
            'daily_work_end' => 'ALTER TABLE employee_contracts ADD COLUMN daily_work_end TIME NULL AFTER daily_work_start',
            'probation_period' => 'ALTER TABLE employee_contracts ADD COLUMN probation_period VARCHAR(120) NULL AFTER daily_work_end',
            'clauses_json' => 'ALTER TABLE employee_contracts ADD COLUMN clauses_json TEXT NULL AFTER probation_period',
            'status' => "ALTER TABLE employee_contracts ADD COLUMN status VARCHAR(30) NOT NULL DEFAULT 'active' AFTER transport_locality_code",
            'active' => 'ALTER TABLE employee_contracts ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1 AFTER status',
            'notes' => 'ALTER TABLE employee_contracts ADD COLUMN notes TEXT NULL AFTER active',
            'created_by' => 'ALTER TABLE employee_contracts ADD COLUMN created_by INT NULL AFTER notes',
            'created_at' => 'ALTER TABLE employee_contracts ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER created_by',
            'updated_at' => 'ALTER TABLE employee_contracts ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at',
            'employer_signer_personnel_id' => 'ALTER TABLE employee_contracts ADD COLUMN employer_signer_personnel_id INT NULL',
            'rh_signer_personnel_id' => 'ALTER TABLE employee_contracts ADD COLUMN rh_signer_personnel_id INT NULL',
            'mentions_json' => 'ALTER TABLE employee_contracts ADD COLUMN mentions_json LONGTEXT NULL',
            'generated_document_path' => 'ALTER TABLE employee_contracts ADD COLUMN generated_document_path VARCHAR(500) NULL',
            'signed_document_path' => 'ALTER TABLE employee_contracts ADD COLUMN signed_document_path VARCHAR(500) NULL',
            'signature_workflow_status' => "ALTER TABLE employee_contracts ADD COLUMN signature_workflow_status VARCHAR(30) NOT NULL DEFAULT 'brouillon'",
            'signature_requested_at' => 'ALTER TABLE employee_contracts ADD COLUMN signature_requested_at DATETIME NULL',
            'rh_signed_by' => 'ALTER TABLE employee_contracts ADD COLUMN rh_signed_by INT NULL',
            'rh_signed_at' => 'ALTER TABLE employee_contracts ADD COLUMN rh_signed_at DATETIME NULL',
            'dg_signed_by' => 'ALTER TABLE employee_contracts ADD COLUMN dg_signed_by INT NULL',
            'dg_signed_at' => 'ALTER TABLE employee_contracts ADD COLUMN dg_signed_at DATETIME NULL',
            'employee_signed_by' => 'ALTER TABLE employee_contracts ADD COLUMN employee_signed_by INT NULL',
            'employee_signed_at' => 'ALTER TABLE employee_contracts ADD COLUMN employee_signed_at DATETIME NULL',
        ]);
        $this->pdo->exec("ALTER TABLE employee_contracts MODIFY signature_workflow_status VARCHAR(30) NOT NULL DEFAULT 'brouillon'");
        $this->ensureClauseTemplateTable();
    }

    public function findActiveContractForPeriod(int $personnelId, string $period): ?array
    {
        [$periodStart, $periodEnd] = $this->periodBounds($period);
        $stmt = $this->pdo->prepare("SELECT * FROM employee_contracts WHERE personnel_id=:personnel_id AND active=1 AND status='active' AND start_date<=:period_end AND (end_date IS NULL OR end_date>=:period_start) ORDER BY start_date DESC,id DESC LIMIT 2");
        $stmt->execute(['personnel_id'=>$personnelId,'period_start'=>$periodStart,'period_end'=>$periodEnd]);
        $rows=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
        if(count($rows)>1&&class_exists('PayrollAuditService'))(new PayrollAuditService())->log('employee_contract_overlap_warning',['personnel_id'=>$personnelId,'period'=>$period,'label'=>'Plusieurs contrats actifs trouves']);
        return $rows[0]??null;
    }

    public function findByPersonnel(int $personnelId): array
    {
        $stmt=$this->pdo->prepare('SELECT * FROM employee_contracts WHERE personnel_id=:personnel_id ORDER BY start_date DESC,id DESC');$stmt->execute(['personnel_id'=>$personnelId]);return$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
    }

    public function explainNoActiveContractForPeriod(int $personnelId,string $period):string
    {
        [$periodStart,$periodEnd]=$this->periodBounds($period);$contracts=$this->findByPersonnel($personnelId);if(!$contracts)return'Aucun contrat RH n est enregistre pour cet employe.';$latest=$contracts[0];if((int)($latest['active']??0)!==1||(string)($latest['status']??'')!=='active')return'Un contrat existe, mais il n est pas actif. Ouvrez le parametrage contrat et reenregistrez-le en contrat actif.';if((string)($latest['start_date']??'')>$periodEnd)return'Un contrat actif existe, mais sa date de debut est posterieure a la periode de paie '.$period.'.';$end=(string)($latest['end_date']??'');if($end!==''&&$end<$periodStart)return'Un contrat actif existe, mais sa date de fin est anterieure a la periode de paie '.$period.'.';return'Un contrat existe, mais il ne couvre pas la periode de paie '.$period.'. Verifiez les dates debut/fin.';
    }

    public function saveContract(array $data): int
    {
        $id=(int)($data['id']??0);$reference=$this->nullableString($data['contract_reference']??null);$clauses=$this->normalizeClauses($data['clauses_json']??($data['clauses']??[]));
        if($clauses===null)$clauses=payroll_ci_json(RhEmployeeContractClausePresetService::forType($reference?:'CDI'));
        $payload=[
            'personnel_id'=>(int)($data['personnel_id']??0),'contract_type_id'=>$this->nullableInt($data['contract_type_id']??null),'contract_reference'=>$reference,
            'contract_label'=>$this->nullableString($data['contract_label']??null),'start_date'=>$this->validDate((string)($data['start_date']??'')),'end_date'=>$this->nullableDate($data['end_date']??null),
            'base_salary'=>$this->positiveDecimal($data['base_salary']??0),'over_salary'=>$this->positiveDecimal($data['over_salary']??0),'transport_locality_code'=>$this->nullableCode($data['transport_locality_code']??null),
            'workplace'=>$this->nullableString($data['workplace']??null),'job_title'=>$this->nullableString($data['job_title']??null),'classification'=>$this->nullableString($data['classification']??null),
            'work_schedule_label'=>$this->nullableString($data['work_schedule_label']??null),'weekly_hours'=>$this->positiveDecimal($data['weekly_hours']??40),'monthly_working_days'=>$this->positiveDecimal($data['monthly_working_days']??26),
            'daily_work_start'=>$this->nullableTime($data['daily_work_start']??null),'daily_work_end'=>$this->nullableTime($data['daily_work_end']??null),'probation_period'=>$this->nullableString($data['probation_period']??null),
            'clauses_json'=>$clauses,'status'=>$this->validStatus((string)($data['status']??'active')),'active'=>array_key_exists('active',$data)?(!empty($data['active'])?1:0):1,'notes'=>$this->nullableString($data['notes']??null),
            'created_by'=>$this->nullableInt($data['created_by']??null),'employer_signer_personnel_id'=>$this->nullableInt($data['employer_signer_personnel_id']??null),'rh_signer_personnel_id'=>$this->nullableInt($data['rh_signer_personnel_id']??null),'mentions_json'=>$this->normalizeMentions($data['mentions']??($data['mentions_json']??[])),
        ];
        if($payload['personnel_id']<=0)throw new RuntimeException('Employe obligatoire.');$this->assertEmployee($payload['personnel_id']);if($payload['end_date']!==null&&$payload['end_date']<$payload['start_date'])throw new RuntimeException('La date de fin du contrat doit etre posterieure au debut.');
        if($id>0){$update=$payload;unset($update['created_by']);$stmt=$this->pdo->prepare("UPDATE employee_contracts SET personnel_id=:personnel_id,contract_type_id=:contract_type_id,contract_reference=:contract_reference,contract_label=:contract_label,start_date=:start_date,end_date=:end_date,base_salary=:base_salary,over_salary=:over_salary,transport_locality_code=:transport_locality_code,workplace=:workplace,job_title=:job_title,classification=:classification,work_schedule_label=:work_schedule_label,weekly_hours=:weekly_hours,monthly_working_days=:monthly_working_days,daily_work_start=:daily_work_start,daily_work_end=:daily_work_end,probation_period=:probation_period,clauses_json=:clauses_json,status=:status,active=:active,notes=:notes,employer_signer_personnel_id=:employer_signer_personnel_id,rh_signer_personnel_id=:rh_signer_personnel_id,mentions_json=:mentions_json,signature_workflow_status='brouillon',rh_signed_by=NULL,rh_signed_at=NULL,dg_signed_by=NULL,dg_signed_at=NULL,employee_signed_by=NULL,employee_signed_at=NULL WHERE id=:id");$stmt->execute($update+['id'=>$id]);return$id;}
        $stmt=$this->pdo->prepare("INSERT INTO employee_contracts(personnel_id,contract_type_id,contract_reference,contract_label,start_date,end_date,base_salary,over_salary,transport_locality_code,workplace,job_title,classification,work_schedule_label,weekly_hours,monthly_working_days,daily_work_start,daily_work_end,probation_period,clauses_json,status,active,notes,created_by,employer_signer_personnel_id,rh_signer_personnel_id,mentions_json) VALUES(:personnel_id,:contract_type_id,:contract_reference,:contract_label,:start_date,:end_date,:base_salary,:over_salary,:transport_locality_code,:workplace,:job_title,:classification,:work_schedule_label,:weekly_hours,:monthly_working_days,:daily_work_start,:daily_work_end,:probation_period,:clauses_json,:status,:active,:notes,:created_by,:employer_signer_personnel_id,:rh_signer_personnel_id,:mentions_json)");$stmt->execute($payload);return(int)$this->pdo->lastInsertId();
    }

    private function assertEmployee(int $personnelId):void
    {
        $q=$this->pdo->prepare("SELECT COUNT(*) FROM personnel WHERE id_personnel_soignant=? AND COALESCE(statut_pers_soignant_code,0) NOT IN (2,3) AND COALESCE(NULLIF(personnel_category,''),'interne')<>'prestataire'");$q->execute([$personnelId]);if((int)$q->fetchColumn()!==1)throw new RuntimeException('Les prestataires et consultants ne peuvent pas recevoir un contrat salarié.');
    }

    public function deactivateContract(int $contractId):bool{$stmt=$this->pdo->prepare("UPDATE employee_contracts SET active=0,status='inactive' WHERE id=:id");$stmt->execute(['id'=>$contractId]);return$stmt->rowCount()>0;}

    public function getEmployeesWithActiveContractsForPeriod(string $period):array
    {
        [$start,$end]=$this->periodBounds($period);$stmt=$this->pdo->prepare("SELECT DISTINCT ec.personnel_id FROM employee_contracts ec JOIN personnel p ON p.id_personnel_soignant=ec.personnel_id WHERE ec.active=1 AND ec.status='active' AND ec.start_date<=:period_end AND (ec.end_date IS NULL OR ec.end_date>=:period_start) AND COALESCE(p.statut_pers_soignant_code,0) NOT IN (2,3) AND COALESCE(NULLIF(p.personnel_category,''),'interne')<>'prestataire' ORDER BY ec.personnel_id");$stmt->execute(['period_start'=>$start,'period_end'=>$end]);return$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
    }

    public function countActiveEmployeesWithoutContract():int
    {
        $stmt=$this->pdo->query("SELECT COUNT(*) FROM personnel p LEFT JOIN employee_contracts ec ON ec.personnel_id=p.id_personnel_soignant AND ec.active=1 AND ec.status='active' WHERE (p.date_sortie IS NULL OR p.date_sortie='0000-00-00') AND COALESCE(p.statut_pers_soignant_code,0) NOT IN (2,3) AND COALESCE(NULLIF(p.personnel_category,''),'interne')<>'prestataire' AND ec.id IS NULL");return(int)($stmt->fetchColumn()?:0);
    }

    public function listActiveEmployeesWithoutContract(int $limit=100):array
    {
        $limit=max(1,min(500,$limit));$stmt=$this->pdo->prepare("SELECT p.id_personnel_soignant id,p.matricule_personnel_soignant matricule,p.nom_personnel_soignant full_name,p.email_personnel_soignant email,p.date_recrutement hire_date,s.lib_service service_label,f.lib_fonction fonction_label FROM personnel p LEFT JOIN service s ON s.id_service=p.service_id LEFT JOIN fonction f ON f.id_fonction=p.fonction_id LEFT JOIN employee_contracts ec ON ec.personnel_id=p.id_personnel_soignant AND ec.active=1 AND ec.status='active' WHERE (p.date_sortie IS NULL OR p.date_sortie='0000-00-00') AND COALESCE(p.statut_pers_soignant_code,0) NOT IN (2,3) AND COALESCE(NULLIF(p.personnel_category,''),'interne')<>'prestataire' AND ec.id IS NULL ORDER BY p.nom_personnel_soignant LIMIT :limit");$stmt->bindValue(':limit',$limit,PDO::PARAM_INT);$stmt->execute();return$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
    }

    public function findActiveContractsForPersonnelIds(array $personnelIds,string $period):array
    {
        $ids=array_values(array_unique(array_filter(array_map('intval',$personnelIds),static fn(int$id):bool=>$id>0)));if(!$ids)return[];[$start,$end]=$this->periodBounds($period);$marks=[];$params=[':period_start'=>$start,':period_end'=>$end];foreach($ids as$i=>$id){$key=':id'.$i;$marks[]=$key;$params[$key]=$id;}$stmt=$this->pdo->prepare("SELECT * FROM employee_contracts WHERE personnel_id IN (".implode(',',$marks).") AND active=1 AND status='active' AND start_date<=:period_end AND (end_date IS NULL OR end_date>=:period_start) ORDER BY personnel_id,start_date DESC,id DESC");$stmt->execute($params);$out=[];foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[] as$row){$pid=(int)($row['personnel_id']??0);if($pid>0&&!isset($out[$pid]))$out[$pid]=$row;}return$out;
    }

    private function periodBounds(string $period):array{if(!preg_match('/^\d{4}-\d{2}$/',$period))throw new RuntimeException('Periode de paie invalide.');$start=$period.'-01';$end=(new DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d');return[$start,$end];}
    private function validDate(string $date):string{if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date))throw new RuntimeException('Date de contrat invalide.');return$date;}
    private function nullableDate($value):?string{$value=trim((string)($value??''));return$value===''?null:$this->validDate($value);}
    private function positiveDecimal($value):float{$amount=(float)str_replace([' ', ','],['','.'],(string)$value);if($amount<0)throw new RuntimeException('Les montants du contrat doivent etre positifs.');return round($amount,2);}
    private function nullableString($value):?string{$value=trim((string)($value??''));return$value===''?null:$value;}
    private function nullableCode($value):?string{$value=strtoupper(trim((string)($value??'')));return$value===''?null:preg_replace('/[^A-Z0-9_\-]+/','_',$value);}
    private function nullableInt($value):?int{return$value===null||$value===''?null:(int)$value;}
    private function validStatus(string $status):string{return in_array($status,['active','inactive','draft','ended'],true)?$status:'active';}
    private function nullableTime($value):?string{$value=trim((string)($value??''));if($value==='')return null;if(preg_match('/^\d{2}:\d{2}$/',$value))return$value.':00';if(preg_match('/^\d{2}:\d{2}:\d{2}$/',$value))return$value;throw new RuntimeException('Heure de travail invalide.');}

    private function normalizeClauses($value):?string
    {
        if(is_string($value)){$decoded=json_decode($value,true);if(is_array($decoded))return payroll_ci_json($decoded);return trim($value)!==''?$value:null;}if(!is_array($value))return null;
        if(isset($value['titles'],$value['bodies'])&&is_array($value['titles'])&&is_array($value['bodies'])){$rows=[];foreach($value['titles']as$i=>$title){$title=trim((string)$title);$body=trim((string)($value['bodies'][$i]??''));if($title!==''&&$body!=='')$rows[]=['title'=>$title,'body'=>$body];}return$rows?payroll_ci_json($rows):null;}
        $clauses=[];foreach($value as$key=>$text){$text=trim((string)$text);if($text!=='')$clauses[(string)$key]=$text;}return$clauses?payroll_ci_json($clauses):null;
    }
    private function normalizeMentions($value):?string{if(is_string($value)){$decoded=json_decode($value,true);$value=is_array($decoded)?$decoded:[];}if(!is_array($value))return null;$titles=(array)($value['titles']??[]);$bodies=(array)($value['bodies']??[]);$levels=(array)($value['levels']??[]);$rows=[];foreach($titles as$i=>$title){$title=trim((string)$title);$body=trim((string)($bodies[$i]??''));$level=in_array((string)($levels[$i]??'info'),['info','warning','danger'],true)?(string)$levels[$i]:'info';if($title!==''||$body!=='')$rows[]=['title'=>$title,'body'=>$body,'level'=>$level];}return$rows?payroll_ci_json($rows):null;}

    public function listClauseTemplates(bool $activeOnly=true):array{$this->ensureClauseTemplateTable();$sql='SELECT * FROM employee_contract_clause_templates'.($activeOnly?' WHERE active=1':'').' ORDER BY sort_order,title';return$this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC)?:[];}
    public function saveClauseTemplate(array $data):int{$this->ensureClauseTemplateTable();$id=(int)($data['id']??0);$title=trim((string)($data['title']??''));$body=trim((string)($data['body']??''));if($title===''||$body==='')throw new RuntimeException('Le titre et le contenu de la clause sont obligatoires.');$payload=['title'=>$title,'category'=>trim((string)($data['category']??'general'))?:'general','body'=>$body,'sort_order'=>(int)($data['sort_order']??0),'active'=>array_key_exists('active',$data)?(!empty($data['active'])?1:0):1];if($id>0){$stmt=$this->pdo->prepare('UPDATE employee_contract_clause_templates SET title=:title,category=:category,body=:body,sort_order=:sort_order,active=:active WHERE id=:id');$stmt->execute($payload+['id'=>$id]);return$id;}$stmt=$this->pdo->prepare('INSERT INTO employee_contract_clause_templates(title,category,body,sort_order,active) VALUES(:title,:category,:body,:sort_order,:active)');$stmt->execute($payload);return(int)$this->pdo->lastInsertId();}

    private function ensureClauseTemplateTable():void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS employee_contract_clause_templates(id INT AUTO_INCREMENT PRIMARY KEY,title VARCHAR(190) NOT NULL,category VARCHAR(80) NOT NULL DEFAULT 'general',body TEXT NOT NULL,sort_order INT NOT NULL DEFAULT 0,active TINYINT(1) NOT NULL DEFAULT 1,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,INDEX idx_contract_clause_templates_active(active),INDEX idx_contract_clause_templates_category(category)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $count=(int)($this->pdo->query('SELECT COUNT(*) FROM employee_contract_clause_templates')->fetchColumn()?:0);if($count>0)return;$stmt=$this->pdo->prepare('INSERT INTO employee_contract_clause_templates(title,category,body,sort_order,active) VALUES(:title,:category,:body,:sort_order,1)');foreach($this->defaultClauseTemplates()as$i=>$template)$stmt->execute(['title'=>$template['title'],'category'=>$template['category'],'body'=>$template['body'],'sort_order'=>($i+1)*10]);
    }

    private function defaultClauseTemplates():array
    {
        return[
            ['title'=>'Missions et responsabilités','category'=>'missions','body'=>'Le salarié exercera les fonctions qui lui sont confiées avec diligence, professionnalisme et respect des procédures internes.'],
            ['title'=>'Confidentialité','category'=>'confidentialite','body'=>'Le salarié conserve strictement confidentielles les informations techniques, commerciales, financières, administratives ou organisationnelles connues dans le cadre de ses fonctions.'],
            ['title'=>'Présence, pointage et discipline','category'=>'discipline','body'=>'Le salarié respecte les horaires, les consignes de pointage, les règles d’absence et les procédures disciplinaires applicables.'],
            ['title'=>'Mobilité et affectation','category'=>'mobilite','body'=>'Selon les nécessités de service, le salarié peut être affecté sur un autre site, une mission ou un chantier compatible avec ses fonctions.'],
            ['title'=>'Fin de contrat et préavis','category'=>'rupture','body'=>'La rupture, le préavis et les obligations de fin de collaboration sont régis par les dispositions légales et conventionnelles applicables.'],
            ['title'=>'Textes applicables','category'=>'cadre_legal','body'=>'Le contrat est régi par le Code du travail ivoirien, les textes conventionnels applicables et leurs textes d’application en vigueur.'],
            ['title'=>'Congés payés','category'=>'conges','body'=>'Le salarié acquiert ses droits à congés selon la législation et les textes conventionnels applicables.'],
            ['title'=>'Protection sociale','category'=>'cnps','body'=>'L’employeur procède aux déclarations sociales obligatoires selon les informations administratives remises par le salarié.'],
            ['title'=>'Rémunération et paiement','category'=>'remuneration','body'=>'La rémunération et ses composantes figurent dans le contrat et sont versées après application des retenues légales et éléments variables validés.'],
            ['title'=>'Propriété et restitution','category'=>'materiel','body'=>'Les documents, équipements et accès remis ou produits pour l’entreprise demeurent sa propriété et doivent être restitués à la fin de la relation de travail.'],
        ];
    }

    private function ensureColumns(array $columns):void{foreach($columns as$column=>$alterSql){$stmt=$this->pdo->prepare('SHOW COLUMNS FROM employee_contracts LIKE :column_name');$stmt->execute(['column_name'=>$column]);$exists=(bool)$stmt->fetch(PDO::FETCH_ASSOC);$stmt->closeCursor();if(!$exists)$this->pdo->exec($alterSql);}}
}
