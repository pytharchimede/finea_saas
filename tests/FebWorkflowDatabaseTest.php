<?php
declare(strict_types=1);
// Opt-in: all DML targets are TEMPORARY tables, never live financial records.
if (getenv('FEB_WORKFLOW_DB_TEST') !== '1') { echo "SKIP: set FEB_WORKFLOW_DB_TEST=1 for temporary-table MySQL tests.\n"; exit; }
require_once __DIR__.'/../model/Database.php';
require_once __DIR__.'/../services/FebWorkflowGuard.php';
require_once __DIR__.'/../model/DisbursementRegularizationManager.php';
function dbCheck(bool $ok,string $message):void { if (!$ok) throw new RuntimeException($message); }
function expectBlocked(callable $call):void { try {$call();}catch(RuntimeException|DomainException $e){return;}throw new LogicException('Operation should have been blocked'); }
$pdo=Database::getConnection();
$source=file_get_contents(__DIR__.'/../validation_demandes_finances.php');
$a=strpos($source,'function febFinanceSynchronizeLinkedFicheAmount(');$b=strpos($source,'function febFinanceCreateLinkedFiche(',$a);
eval(substr($source,$a,$b-$a));
$pdo->exec('CREATE TEMPORARY TABLE fiche_decaissement (id_fiche INT PRIMARY KEY,num_fiche VARCHAR(50),decaisse INT DEFAULT 0,montant_fiche DECIMAL(18,2),feb_request_id INT) ENGINE=InnoDB');
$pdo->exec('CREATE TEMPORARY TABLE trace_fiche (num_fiche VARCHAR(50),action VARCHAR(255),utilisateur VARCHAR(100),adresse_ip VARCHAR(100),details TEXT) ENGINE=InnoDB');
$pdo->exec("INSERT INTO fiche_decaissement VALUES (1,'TEST-FEB',0,100,99)");
expectBlocked(fn()=>febFinanceSynchronizeLinkedFicheAmount($pdo,99,200,[]));
$pdo->beginTransaction();
dbCheck(febFinanceSynchronizeLinkedFicheAmount($pdo,99,200,[]),'Pending amount not synchronized');
dbCheck($pdo->inTransaction(),'Implicit commit during synchronization');
$pdo->rollBack();
dbCheck((float)$pdo->query('SELECT montant_fiche FROM fiche_decaissement')->fetchColumn()===100.0,'Rollback lost amount');
dbCheck((int)$pdo->query('SELECT COUNT(*) FROM trace_fiche')->fetchColumn()===0,'Rollback lost trace');
$pdo->exec('UPDATE fiche_decaissement SET decaisse=1');
$pdo->beginTransaction();expectBlocked(fn()=>febFinanceSynchronizeLinkedFicheAmount($pdo,99,200,[]));$pdo->rollBack();
$pdo->exec("INSERT INTO fiche_decaissement VALUES (2,'TEST-REMAINDER',0,50,99)");
$pdo->beginTransaction();expectBlocked(fn()=>febFinanceSynchronizeLinkedFicheAmount($pdo,99,150,[]));$pdo->rollBack();
dbCheck((float)$pdo->query('SELECT montant_fiche FROM fiche_decaissement WHERE id_fiche=2')->fetchColumn()===50.0,'Partial remainder overwritten');
$pdo->exec("CREATE TEMPORARY TABLE disbursement_regularizations (num_fiche VARCHAR(60) PRIMARY KEY,submitted_at DATETIME NULL,cancelled_at DATETIME NULL,accounting_status VARCHAR(20),signature_data TEXT,payment_proof_path TEXT,beneficiary_user_id INT DEFAULT 0,accounting_note TEXT,accounting_validated_by INT,accounting_validator_name VARCHAR(100),accounting_validated_at DATETIME) ENGINE=InnoDB");
$pdo->exec("INSERT INTO disbursement_regularizations (num_fiche,submitted_at,accounting_status,signature_data,payment_proof_path) VALUES ('TEST-FEB',NOW(),'pending','signed','proof.pdf')");
$ref=new ReflectionClass(DisbursementRegularizationManager::class);$manager=$ref->newInstanceWithoutConstructor();$ref->getProperty('pdo')->setValue($manager,$pdo);
$manager->validate('TEST-FEB',1,'Test','approved','');
dbCheck(!$pdo->inTransaction(),'Review left open transaction');
expectBlocked(fn()=>$manager->validate('TEST-FEB',1,'Test','rejected',''));
dbCheck($pdo->query('SELECT accounting_status FROM disbursement_regularizations')->fetchColumn()==='approved','Second review overwrote decision');
$pdo->exec("UPDATE disbursement_regularizations SET accounting_status='pending',payment_proof_path=NULL");
expectBlocked(fn()=>$manager->validate('TEST-FEB',1,'Test','approved',''));
dbCheck(!$pdo->inTransaction(),'Missing proof left open transaction');
$pdo->exec("UPDATE disbursement_regularizations SET payment_proof_path='proof.pdf',cancelled_at=NOW()");
expectBlocked(fn()=>$manager->validate('TEST-FEB',1,'Test','approved',''));
// Separate connections exercise real MySQL advisory lock exclusion.
$other=new PDO('mysql:host='.EnvConfig::get('PROJECT_DB_HOST','localhost').';dbname='.EnvConfig::get('PROJECT_DB_NAME','fidestci_projet_db').';charset=utf8mb4',EnvConfig::get('PROJECT_DB_USER','fidestci_ulrich'),EnvConfig::get('PROJECT_DB_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$g1=new FebWorkflowGuard($pdo);$g2=new FebWorkflowGuard($other);$id=random_int(2000000000,2100000000);
$g1->acquire($id);
try {expectBlocked(fn()=>$g2->acquire($id));} finally {$g1->release($id);}
$g2->acquire($id);$g2->release($id);
echo "FebWorkflowDatabaseTest: OK (rollback, paid/split amounts, accounting review, missing proof, cancelled regularization, concurrent FEB lock)\n";
