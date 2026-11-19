<?php
declare(strict_types=1);
require_once __DIR__.'/../services/AccountingService.php';
$dsn=getenv('ACCOUNTING_TEST_DSN');
if (!$dsn) { echo "Set ACCOUNTING_TEST_DSN to an empty disposable database.\n"; exit; }
$pdo=new PDO($dsn,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
function chartCheck(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
chartCheck($pdo->query('SHOW TABLES')->fetchAll()===[],'Empty database required');
$s=new AccountingService($pdo);
$year=$s->createExercise('Test','2024-01-01','2024-12-31',1);
$s->importAccounts([['code'=>'401999','label'=>'Ancien compte']],1);
$doc=AccountingService::validateDocument('preuve.pdf',"%PDF-1.4\n%%EOF\n");
$id=$s->saveDraft(['exercise_id'=>$year,'journal_code'=>'ACH','entry_date'=>'2024-01-01','label'=>'Historique','reference'=>'TEST','request_key'=>bin2hex(random_bytes(32)),'lines'=>[
['account_code'=>'6011','label'=>'Achat','debit'=>'10','credit'=>'0'],['account_code'=>'401999','label'=>'Dette','debit'=>'0','credit'=>'10']]],1,$doc);
$s->post($id,1,1);
$before=$s->entry($id);
$pdo->exec("INSERT INTO accounting_accounts(code,label) VALUES ('10','Capital'),('332','Ajout')");
$pdo->exec("UPDATE accounting_accounts SET label='Libellé corrigé' WHERE code='603'");
$pdo->exec("ALTER TABLE accounting_settings DROP COLUMN chart_revision");
$pdo->exec("ALTER TABLE accounting_accounts DROP COLUMN is_active");
$pdo->exec("UPDATE accounting_settings SET schema_version=4");
$s=new AccountingService($pdo);
$expected=require __DIR__.'/../comptabilite/data/syscohada.php';
$actual=array_column($s->accounts(),'label','code');
chartCheck(count($actual)===1332,'Exactly 1332 active codes');
foreach ($expected as $code=>$label) chartCheck(($actual[$code]??null)===$label,'Exact label '.$code);
chartCheck(!isset($actual[10],$actual[332]) && !isset($actual[401999]),'Extras excluded');
chartCheck((int)$pdo->query("SELECT COUNT(*) FROM accounting_accounts WHERE code IN ('10','332')")->fetchColumn()===0,'Unused extras deleted');
chartCheck((int)$pdo->query("SELECT is_active FROM accounting_accounts WHERE code='401999'")->fetchColumn()===0,'Used extra archived');
chartCheck($s->entry($id)===$before,'Posted entry and evidence unchanged');
chartCheck(mb_strlen($actual[603])===242,'Long label preserved');
chartCheck($actual[2131]==='Logiciels' && $actual[2193]==='Logiciels','Distinct supplied codes preserved');
$s=new AccountingService($pdo);
chartCheck(count($s->accounts())===1332,'Idempotent replacement');
echo "AccountingChartReplacementTest: exact chart, upgrade, archive, history and idempotence passed.\n";
