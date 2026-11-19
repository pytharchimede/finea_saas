<?php
declare(strict_types=1);
require_once __DIR__.'/../services/AccountingService.php';
$dsn=getenv('ACCOUNTING_TEST_DSN');
if (!$dsn) { echo "Set ACCOUNTING_TEST_DSN to an empty disposable database.\n"; exit; }
$pdo=new PDO($dsn,getenv('ACCOUNTING_TEST_USER')?:'root',getenv('ACCOUNTING_TEST_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if (($argv[1]??'')==='worker') {
    $service=new AccountingService($pdo);
    echo $service->prepareForUse('2026-09-05');
    exit;
}
function autoCheck(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
autoCheck($pdo->query('SHOW TABLES')->fetchAll()===[],'Database must be empty');
$workers=[];
foreach ([1,2] as $_) {
    $process=proc_open([PHP_BINARY,__FILE__,'worker'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    autoCheck(is_resource($process),'Worker started');$workers[]=[$process,$pipes];
}
$ids=[];
foreach ($workers as [$process,$pipes]) {
    $ids[]=trim(stream_get_contents($pipes[1]));$error=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);
    autoCheck(proc_close($process)===0 && $error==='','Concurrent install: '.$error);
}
autoCheck($ids[0]===$ids[1] && (int)$ids[0]>0,'One exercise for simultaneous first visits');
$s=new AccountingService($pdo);
autoCheck(count($s->accounts())===1332,'Full plan automatically installed');
autoCheck(count($s->journals())===7,'Journals automatically installed');
autoCheck(count($s->exercises())===1,'Single current exercise');
autoCheck($s->prepareForUse('2026-12-31')===(int)$ids[0],'Repeat visit reuses exercise');
$pdo->exec("UPDATE accounting_exercises SET closed_at='2026-12-31 23:59:59' WHERE id=".(int)$ids[0]);
autoCheck($s->prepareForUse('2026-12-31')===(int)$ids[0],'Closed exercise reused');
autoCheck($s->exercises()[0]['closed_at']!==null,'No automatic reopening');
$next=$s->prepareForUse('2027-01-01');autoCheck($next!==(int)$ids[0] && count($s->exercises())===2,'Automatic annual rollover');
$custom=$s->createExercise('Exercice décalé','2028-07-01','2029-06-30',1);
autoCheck($s->prepareForUse('2028-10-01')===$custom,'Custom fiscal year respected');
autoCheck($s->prepareForUse('2028-01-01')===null,'No overlapping calendar year invented');
$s->importAccounts([['code'=>'401900','label'=>'Compte entreprise']],1);
$s->addJournal('BQTEST','Banque entreprise','banque',1);
// Simulate an interrupted older installation: reference data must survive the retry.
$pdo->exec('UPDATE accounting_settings SET schema_version=1');
$pdo->exec('DROP TABLE accounting_documents');
$s=new AccountingService($pdo);$s->prepareForUse('2027-04-01');
autoCheck(count($s->accounts())===1333 && count($s->journals())===8,'Existing configuration preserved');
autoCheck((int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='accounting_documents'")->fetchColumn()===1,'Interrupted schema repaired');
autoCheck(count($s->exercises())===3,'Retry creates no duplicate exercise');
autoCheck((int)$pdo->query('SELECT COUNT(*) FROM accounting_entries')->fetchColumn()===0,'Installation posts no business entries');
autoCheck((int)$pdo->query("SELECT COUNT(*) FROM accounting_audit WHERE action='exercise_created_automatically'")->fetchColumn()===2,'Automatic creations audited once');
echo "AccountingAutoInstallTest: concurrent installation, full chart, current exercise, annual rollover, preserved configuration and recovery passed.\n";
