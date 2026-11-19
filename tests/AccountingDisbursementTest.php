<?php
declare(strict_types=1);
require_once __DIR__.'/../services/AccountingService.php';
require_once __DIR__.'/../services/AccountingDisbursementSource.php';
function sourceCheck(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
function sourceReject(callable $fn,string $message): void { try {$fn();} catch (DomainException $e) {return;} throw new RuntimeException('Expected rejection: '.$message); }
$dsn=getenv('ACCOUNTING_TEST_DSN');
if (!$dsn) { echo "Set ACCOUNTING_TEST_DSN to an empty disposable database.\n"; exit; }
$pdo=new PDO($dsn,getenv('ACCOUNTING_TEST_USER')?:'root',getenv('ACCOUNTING_TEST_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
sourceCheck($pdo->query('SHOW TABLES')->fetchAll()===[],'Database must be empty');
$root=getenv('ACCOUNTING_TEST_FILES_ROOT')?:sys_get_temp_dir().'/accounting-source-'.bin2hex(random_bytes(6));
if (!is_dir($root.'/uploads')) mkdir($root.'/uploads',0700,true);
$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
file_put_contents($root.'/uploads/signature.png',$png);
file_put_contents($root.'/uploads/payment.pdf',"%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n");
file_put_contents($root.'/uploads/invoice.pdf',"%PDF-1.4\n% Invoice test\n%%EOF\n");
$s=new AccountingService($pdo,true,$root); $bridge=new AccountingDisbursementSource($pdo,$root);
sourceCheck(!$bridge->available(),'Missing source module detected');
$statements=[
"CREATE TABLE daily_disbursement_points (id BIGINT UNSIGNED PRIMARY KEY,point_date DATE,version_no INT,status VARCHAR(30),total_amount DECIMAL(18,2),item_count INT,certified_at DATETIME,paid_at DATETIME)",
"CREATE TABLE daily_disbursement_point_items (id BIGINT UNSIGNED PRIMARY KEY,point_id BIGINT UNSIGNED,fiche_number VARCHAR(120),amount DECIMAL(18,2),beneficiary VARCHAR(190),designation VARCHAR(255),chantier_code VARCHAR(80))",
"CREATE TABLE daily_disbursement_point_signatures (id BIGINT UNSIGNED PRIMARY KEY,point_id BIGINT UNSIGNED,role_code VARCHAR(30),signature_path VARCHAR(255),signed_at DATETIME,signer_name VARCHAR(190))",
"CREATE TABLE daily_disbursement_point_payments (id BIGINT UNSIGNED PRIMARY KEY,point_id BIGINT UNSIGNED,paid_at DATETIME,signature_path VARCHAR(255),proof_path VARCHAR(255),payer_name VARCHAR(190),payment_mode VARCHAR(40),payment_reference VARCHAR(190))",
"CREATE TABLE daily_disbursement_point_payment_items (id BIGINT UNSIGNED PRIMARY KEY,payment_id BIGINT UNSIGNED,point_item_id BIGINT UNSIGNED,amount DECIMAL(18,2))",
"CREATE TABLE fiche_decaissement (num_fiche VARCHAR(120) PRIMARY KEY,decaisse INT,date_decaisse DATETIME,code_autorisation_feb VARCHAR(190),facture_reference VARCHAR(255),pv_reception_reference VARCHAR(255),document_completion_status VARCHAR(30))",
"INSERT INTO daily_disbursement_points VALUES (1,'2025-01-10',1,'paid',150000,1,'2025-01-10 08:00:00','2025-01-10 10:00:00')",
"INSERT INTO daily_disbursement_point_items VALUES (1,1,'FD-TEST-001',150000,'Fournisseur matériaux','Règlement matériaux','CH-001')",
"INSERT INTO daily_disbursement_point_signatures VALUES (1,1,'dg','uploads/signature.png','2025-01-10 08:00:00','Directeur général')",
"INSERT INTO daily_disbursement_point_payments VALUES (1,1,'2025-01-10 10:00:00','uploads/signature.png','uploads/payment.pdf','Agent payeur','virement','VIR-TEST-001')",
"INSERT INTO daily_disbursement_point_payment_items VALUES (1,1,1,150000)",
"INSERT INTO fiche_decaissement VALUES ('FD-TEST-001',1,'2025-01-10 10:00:00','BCI-001','uploads/invoice.pdf','PV-001','complete')",
];
foreach ($statements as $sql) $pdo->exec($sql);
sourceCheck($bridge->available(),'Source schema available');
$ex=$s->createExercise('Exercice 2025','2025-01-01','2025-12-31',1);
$source=$bridge->item(1);$bridge->assertEligible($source);
sourceCheck($source['amount']===15000000,'Actual payment amount');
sourceCheck(count($source['files'])===4,'Source proof, invoice and signatures');
$data=['exercise_id'=>$ex,'journal_code'=>'BQ','entry_date'=>'2025-01-10','label'=>'Paiement facture matériaux','reference'=>'FD-TEST-001','request_key'=>bin2hex(random_bytes(32)),'debit_account'=>'4011','credit_account'=>'5211'];
$context=['id'=>1,'checks'=>['point_checked'=>'1','payment_checked'=>'1','expense_checked'=>'1'],'uploads'=>[]];
sourceReject(fn()=>$s->saveDraft($data,1,null,['id'=>1,'checks'=>[]]),'Review required');
$bad=$data; $bad['credit_account']='7011'; sourceReject(fn()=>$s->saveDraft($bad,1,null,$context),'Credit must be treasury');
$bad=$data; $bad['entry_date']='2025-01-09'; sourceReject(fn()=>$s->saveDraft($bad,1,null,$context),'Cannot predate payment');
$pdo->exec("UPDATE daily_disbursement_point_signatures SET signature_path='' WHERE id=1");
sourceReject(fn()=>$s->saveDraft($data,1,null,$context),'DG signature required');
$pdo->exec("UPDATE daily_disbursement_point_signatures SET signature_path='uploads/signature.png' WHERE id=1");
$pdo->exec("UPDATE fiche_decaissement SET decaisse=0");sourceReject(fn()=>$s->saveDraft($data,1,null,$context),'Unpaid fiche');$pdo->exec('UPDATE fiche_decaissement SET decaisse=1');
$pdo->exec("UPDATE daily_disbursement_points SET status='certified'");sourceReject(fn()=>$s->saveDraft($data,1,null,$context),'Unpaid point');$pdo->exec("UPDATE daily_disbursement_points SET status='paid'");
$pdo->exec('UPDATE daily_disbursement_point_payment_items SET amount=149999');sourceReject(fn()=>$s->saveDraft($data,1,null,$context),'Mismatched payment');$pdo->exec('UPDATE daily_disbursement_point_payment_items SET amount=150000');
$pdo->exec("UPDATE fiche_decaissement SET facture_reference='FAC-TEXT-ONLY'");sourceReject(fn()=>$s->saveDraft($data,1,null,$context),'Reference is not an attached invoice');$pdo->exec("UPDATE fiche_decaissement SET facture_reference='uploads/invoice.pdf'");
sourceCheck((int)$pdo->query('SELECT COUNT(*) FROM accounting_entries')->fetchColumn()===0,'Rejected preparations are atomic');
$id=$s->saveDraft($data,1,null,$context); $e=$s->entry($id);
sourceCheck(count($e['documents'])===4 && $e['source']['point_item_id']==1,'Evidence and origin archived');
sourceCheck((int)$e['lines'][0]['debit']===15000000 && (int)$e['lines'][1]['credit']===15000000,'Debit and credit generated from real paid amount');
$duplicate=$data;$duplicate['request_key']=bin2hex(random_bytes(32));sourceReject(fn()=>$s->saveDraft($duplicate,1,null,$context),'Duplicate source');
$edit=$data+['id'=>$id,'version'=>1,'lines'=>[['account_code'=>'4011','label'=>'x','debit'=>'149999','credit'=>'0'],['account_code'=>'5211','label'=>'x','debit'=>'0','credit'=>'149999']]];
sourceReject(fn()=>$s->saveDraft($edit,1),'Editing paid amount prohibited');
file_put_contents($root.'/uploads/invoice.pdf',"%PDF-1.4\n% Changed invoice\n%%EOF\n");
sourceReject(fn()=>$s->post($id,1,1),'Source proof changed after review');
$s->cancelDraft($id,1,1);
$id=$s->saveDraft($duplicate,1,null,$context);$s->post($id,1,1);
sourceCheck((int)$bridge->points()[0]['posted_count']===1,'Point completion progress');
sourceCheck((int)$s->report($ex,'2025-01-01','2025-12-31')['balances'][5211]['closing']===-15000000,'Bank credited');
$s->reverse($id,$ex,'2025-01-11','Correction imputation',1);
sourceCheck($bridge->points()[0]['posted_count']==0,'Reversed source available for correction');
$data['request_key']=bin2hex(random_bytes(32));$data['entry_date']='2025-01-11';$new=$s->saveDraft($data,1,null,$context);
sourceCheck($new!==$id,'Corrected source creates new retained history');
// All codes from the supplied plan, including class 9, are installed.
$provided=fopen(__DIR__.'/../comptabilite/data/plan_fourni.csv','r');fgetcsv($provided,0,';','"','');$codes=array_map('strval',array_column($s->accounts(),'code'));
while (($row=fgetcsv($provided,0,';','"',''))!==false) sourceCheck(in_array($row[0],$codes,true),'Supplied account '.$row[0]);fclose($provided);
$hb=['exercise_id'=>$ex,'journal_code'=>'HB','entry_date'=>'2025-01-10','label'=>'Engagement test','reference'=>'HB-1','request_key'=>bin2hex(random_bytes(32)),
 'lines'=>[['account_code'=>'9051','label'=>'Engagement','debit'=>'100','credit'=>'0'],['account_code'=>'9151','label'=>'Contrepartie','debit'=>'0','credit'=>'100']]];
$hd=$s->saveDraft($hb,1,AccountingService::validateDocument('preuve.png',$png));$s->post($hd,1,1);
sourceCheck(count($s->report($ex,'2025-01-01','2025-12-31','HB')['rows'])===2,'Class 9 report');
foreach ($s->report($ex,'2025-01-01','2025-12-31')['rows'] as $row) sourceCheck(!str_starts_with($row['account_code'],'9'),'Class 9 excluded from general balance');
$hb['request_key']=bin2hex(random_bytes(32));$hb['journal_code']='OD';sourceReject(fn()=>$s->saveDraft($hb,1),'Class 9 book segregation');
echo "AccountingDisbursementTest: signed/paid workflow, source integrity, evidence, amount, duplicate controls and full chart checks passed.\n";
if (!getenv('ACCOUNTING_TEST_FILES_ROOT')) { foreach (glob($root.'/uploads/*') as $path) unlink($path);rmdir($root.'/uploads');rmdir($root); }
