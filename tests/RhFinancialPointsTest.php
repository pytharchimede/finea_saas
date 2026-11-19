<?php
declare(strict_types=1);
/** Transactional integration using SQLite for deterministic tests; MariaDB locks require staging validation. */
require_once __DIR__.'/../services/RhFinancialPointService.php';

final class RhFinancialTestPdo extends PDO
{
    public function __construct(){parent::__construct('sqlite::memory:');$this->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$this->sqliteCreateFunction('NOW',static fn()=>date('Y-m-d H:i:s'));}
    private function translate(string $sql):string
    {
        if(str_starts_with($sql,'SHOW TABLES LIKE'))return "SELECT name FROM sqlite_master WHERE type='table' AND name=".substr($sql,17);
        $sql=str_replace([' FOR UPDATE','INSERT IGNORE INTO'],['','INSERT OR IGNORE INTO'],$sql);
        if(str_contains($sql,'ON DUPLICATE KEY UPDATE deferred_until'))$sql=str_replace('ON DUPLICATE KEY UPDATE deferred_until=VALUES(deferred_until)','ON CONFLICT(source_kind,source_id,source_period) DO UPDATE SET deferred_until=excluded.deferred_until',$sql);
        return $sql;
    }
    public function prepare(string $query,array $options=[]):PDOStatement|false{return parent::prepare($this->translate($query),$options);}
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs):PDOStatement|false{return $fetchMode===null?parent::query($this->translate($query)):parent::query($this->translate($query),$fetchMode,...$fetchModeArgs);}
    public function exec(string $statement):int|false{return parent::exec($this->translate($statement));}
}
function check(bool $value,string $label):void{if(!$value)throw new RuntimeException($label);echo "PASS $label\n";}
function fails(callable $call,string $label):void{try{$call();}catch(Throwable $e){echo "PASS $label (".get_class($e).")\n";return;}throw new RuntimeException('Expected failure: '.$label);}
function proof(int $user,int $id,int $revision,string $role):void{$_SESSION['passkey_signature_proof']=['user_id'=>$user,'expires_at'=>time()+120,'credential_id'=>$user,'context'=>'rh_financial:'.$id.':'.$revision.':'.$role];}
$pdo=new RhFinancialTestPdo();$property=new ReflectionProperty(Database::class,'pdo');$property->setValue(null,$pdo);
$schema=file_get_contents(__DIR__.'/../database/migrations/20261002_rh_financial_points.sql');
$schema=preg_replace('/\) ENGINE=[^;]+;/m',');',$schema);
$schema=preg_replace('/\b(?:BIGINT|INT) UNSIGNED AUTO_INCREMENT PRIMARY KEY/','INTEGER PRIMARY KEY AUTOINCREMENT',$schema);
$schema=str_replace(' UNSIGNED','',$schema);$schema=str_replace(' ON UPDATE CURRENT_TIMESTAMP','',$schema);
$schema=preg_replace('/UNIQUE KEY \w+\(/','UNIQUE(',$schema);
$schema=preg_replace('/,?\s*KEY \w+\([^)]*\)/','',$schema);
$schema=preg_replace('/,\s*\)/',')',$schema);$pdo->exec($schema);
$pdo->exec("CREATE TABLE user_devis_banamur(id INTEGER PRIMARY KEY,mail_pro TEXT,active INT,acces_rh INT,acces_direction INT,acces_admin INT);
CREATE TABLE personnel(id_personnel_soignant INTEGER PRIMARY KEY,email_personnel_soignant TEXT,matricule_personnel_soignant TEXT,nom_personnel_soignant TEXT,tel_personnel_soignant TEXT);
CREATE TABLE user_passkeys(id INTEGER PRIMARY KEY,user_id INT,revoked_at TEXT);
CREATE TABLE service(id_service INTEGER PRIMARY KEY);
CREATE TABLE chantier_banamur(id INTEGER PRIMARY KEY,devis_id INT,archived_at TEXT,planning_is_office_hub INT DEFAULT 0);
CREATE TABLE devis_chantier_links(devis_id INT,chantier_id INT);
CREATE TABLE debourse_banamur(id INTEGER PRIMARY KEY,devis_id INT,montant_debourse NUMERIC,feb_request_id INT,motif_modif TEXT,created_at TEXT);
CREATE TABLE ligne_debourse_banamur(id INTEGER PRIMARY KEY,debourse_id INT,categorie TEXT,montant NUMERIC);
CREATE TABLE employee_legal_requests(id INTEGER PRIMARY KEY AUTOINCREMENT,requester_user_id INT,requester_name TEXT,requester_email TEXT,request_type TEXT,assigned_team TEXT,title TEXT,payload_json TEXT,meta_service_id INT,status TEXT,submitted_at TEXT,decided_at TEXT,updated_at TEXT);
CREATE TABLE fiche_decaissement(id_fiche INTEGER PRIMARY KEY AUTOINCREMENT,parent_num_fiche TEXT NOT NULL,code_parentale TEXT NOT NULL,num_fiche TEXT,beficiaire_fiche TEXT,tel_beneficiaire_fiche TEXT,personnel_id INT,montant_fiche NUMERIC,montant_initial_fiche NUMERIC,chantier_id INT,ligne_debourse_id INT,designation_fiche TEXT,precision_fiche TEXT,details_demande TEXT,mode_paiement TEXT,created_by_user_id INT,feb_request_id INT,code_autorisation_feb TEXT,conforme INT,approuve INT,decaisse INT,etat_fiche INT,date_creat_fiche TEXT,planned_start_date TEXT,planned_end_date TEXT,date_decaissement_minimum TEXT,date_decaisse TEXT);
INSERT INTO user_devis_banamur VALUES(1,'rh@example.test',1,1,0,0),(2,'dg@example.test',1,0,1,0),(3,'employee@example.test',1,0,0,0),(4,'other@example.test',1,0,1,0);
INSERT INTO personnel VALUES(1,'rh@example.test','RH001','Porteur RH','+2250100000001'),(3,'employee@example.test','EMP003','Employé test','+2250100000003');
INSERT INTO user_passkeys VALUES(1,1,NULL),(2,2,NULL);
INSERT INTO service VALUES(10);
INSERT INTO chantier_banamur(id,devis_id) VALUES(1,1);INSERT INTO debourse_banamur(id,devis_id,montant_debourse) VALUES(1,1,500000);INSERT INTO ligne_debourse_banamur VALUES(1,1,'RH',500000);");
$pdo->exec("UPDATE rh_financial_settings SET dg_user_id=2,dg_phone='+2250100000002',advance_active_at='2026-10-01 10:00:00',loan_active_at='2026-10-01 10:00:00' WHERE id=1");
$insert=$pdo->prepare("INSERT INTO employee_legal_requests(requester_user_id,requester_name,requester_email,request_type,assigned_team,title,payload_json,status,submitted_at) VALUES(3,'Employé test','employee@example.test',?,'rh',?,?, 'submitted',?)");
foreach([['avance_salaire','Ancienne avance',10000,'2026-09-30 10:00:00'],['avance_salaire','Avance octobre',50000,'2026-10-02 10:00:00'],['avance_salaire','Avance report',20000,'2026-10-02 10:00:00'],['pret','Prêt octobre',100000,'2026-10-02 10:00:00']] as [$type,$title,$amount,$at])$insert->execute([$type,$title,json_encode(['matricule'=>'EMP003','montant'=>$amount,'montant_demande'=>$amount]),$at]);
$service=new RhFinancialPointService($pdo);$budget=['chantier_id'=>1,'ligne_debourse_id'=>1,'service_id'=>10,'date'=>date('Y-m-d'),'mode_paiement'=>'Espèces'];
check(count($service->candidates('advance'))===2,'Activation excludes historical requests');
fails(fn()=>RhFinancialPointPolicy::amount('10.5'),'Fractional FCFA rejected');
fails(fn()=>RhFinancialPointPolicy::decisions([['key'=>'a','decision'=>'approved','amount'=>10]],['foreign'=>[]]),'Foreign decision keys rejected');
fails(fn()=>RhFinancialPointPolicy::decisions([['key'=>'a','decision'=>'approved','amount'=>10]],['a'=>['decision'=>'deferred','deferred_until'=>'2026-02-30']]),'Invalid deferral date rejected');
fails(fn()=>$service->create('advance','',[2],3,$budget),'Employee cannot stop HR point');
$id=$service->create('advance','',[2,3],1,$budget);
check($id>0 && count($service->candidates('advance'))===0,'Selected sources reserved exactly once');
fails(fn()=>$service->create('advance','',[2],1,$budget),'Duplicate point rejected');
proof(1,$id,1,'dg');fails(fn()=>$service->stop($id,1,1),'Proof role bound to signature context');
proof(1,$id,1,'rh');$service->stop($id,1,1);check($service->get($id)['status']==='rh_signed','RH passkey stops snapshot');
$token=str_repeat('a',64);$pdo->prepare('UPDATE rh_financial_point_versions SET token_hash=?,token_expires_at=?,whatsapp_status=? WHERE point_id=?')->execute([hash('sha256',$token),date('Y-m-d H:i:s',time()+3600),'sent',$id]);
fails(fn()=>$service->fromToken($token,4),'Another director cannot use DG invitation');
proof(2,$id,1,'dg');$feb=$service->sign($id,1,2,['advance_2'=>['amount'=>35000],'advance_3'=>['decision'=>'deferred','deferred_until'=>date('Y-m-d',time()+86400)]],$token);
$p=$service->get($id);check($feb>0 && $p['status']==='dg_signed','DG signature creates FEB atomically');
$q=$pdo->prepare('SELECT * FROM employee_legal_requests WHERE id=?');$q->execute([$feb]);$request=$q->fetch(PDO::FETCH_ASSOC);$request['payload']=json_decode($request['payload_json'],true);
check($request['requester_user_id']==1 && $request['requester_name']==='Porteur RH','FEB requester is RH signatory');
check($request['payload']['montant']===35000,'FEB amount reflects final DG decisions');
check(is_file(__DIR__.'/../'.$p['pdf_path']) && str_starts_with(file_get_contents(__DIR__.'/../'.$p['pdf_path']),'%PDF'),'Real TCPDF signed recap saved');
check(count($service->candidates('advance'))===0,'Deferred source excluded before its new date');
fails(fn()=>$service->fromToken($token,2),'Consumed invitation cannot be reused');
$pdo->beginTransaction();$pdo->prepare("UPDATE employee_legal_requests SET status='approved' WHERE id=?")->execute([$feb]);$request['status']='approved';$num=$service->createFiche($request,['user_id'=>2]);$pdo->commit();
$f=$pdo->query('SELECT * FROM fiche_decaissement')->fetch(PDO::FETCH_ASSOC);check($f['beficiaire_fiche']==='Porteur RH' && (int)$f['personnel_id']===1,'Fiche beneficiary remains RH signatory');
check(str_contains($f['details_demande'],'Employé test — Avance octobre — 35 000 FCFA') && !str_contains($f['details_demande'],'Avance report'),'Fiche detail contains approved employee label and exact amount');
require_once __DIR__.'/../services/RhFinancialSettlementService.php';RhFinancialSettlementService::assertFiche($pdo,$f);
$mutated=$f;$mutated['montant_fiche']=40000;fails(fn()=>RhFinancialSettlementService::assertFiche($pdo,$mutated),'Payment rejects changed fiche amount');
$pdo->exec('UPDATE fiche_decaissement SET decaisse=1');fails(fn()=>$service->reopen($id,1,1,'Correction'),'Paid fiche prevents revision');$pdo->exec('UPDATE fiche_decaissement SET decaisse=0');
$service->reopen($id,1,1,'Correction du DG');check($service->get($id)['revision']==2 && $service->get($id)['status']==='draft','Reopening requires RH and DG signatures again');
check($pdo->query('SELECT status FROM employee_legal_requests WHERE id='.$feb)->fetchColumn()==='cancelled' && $pdo->query('SELECT approuve FROM fiche_decaissement')->fetchColumn()==2,'Prior FEB and fiche neutralized');
fails(fn()=>RhFinancialSettlementService::assertFiche($pdo,$f),'Old signed fiche no longer payable');
$fresh=$service->get($id);$service->discard($id,2,1);check($service->get($id)['status']==='cancelled','Draft cancellation releases reserved sources');
foreach($pdo->query('SELECT pdf_path FROM rh_financial_point_versions WHERE pdf_path IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN) as $file)unlink(__DIR__.'/../'.$file);
echo "All RH financial integration checks passed.\n";
