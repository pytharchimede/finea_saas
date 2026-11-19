<?php
declare(strict_types=1);
require_once __DIR__.'/../services/AccountingService.php';
function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function rejected(callable $callback, string $message): void {
    try { $callback(); } catch (DomainException $e) { return; }
    throw new RuntimeException('Expected rejection: '.$message);
}
check(AccountingService::amount('123,45')===12345,'Decimal conversion');
check(AccountingService::amount('0.01')===1,'Exact cents');
check(AccountingService::decimal(-12345)==='-123.45','Signed formatting');
foreach (['-1','1e3','NaN','1.001','1 000','9999999999999'] as $bad) rejected(fn()=>AccountingService::amount($bad),'Invalid amount '.$bad);
rejected(fn()=>AccountingService::date('2025-02-29'),'Invalid calendar date');
rejected(fn()=>AccountingService::validateDocument('x.php','<?php echo 1;'),'Executable attachment');
$doc=AccountingService::validateDocument('facture.pdf',"%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n");
check($doc['mime']==='application/pdf','PDF identification');
$dsn=getenv('ACCOUNTING_TEST_DSN');
if (!$dsn) { echo "Accounting unit checks: OK. Set ACCOUNTING_TEST_DSN to an empty disposable MySQL database for integration checks.\n"; exit; }
$pdo=new PDO($dsn,getenv('ACCOUNTING_TEST_USER')?:'root',getenv('ACCOUNTING_TEST_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
check($pdo->query('SHOW TABLES')->fetchAll()===[],'Integration database must be empty');
$s=new AccountingService($pdo);
$s->install();
check(count($s->accounts())>=1200,'Chart installed');
check(count($s->journals())===7,'Default journals');
$exercise=$s->createExercise('Test 2024','2024-01-01','2024-12-31',1);
rejected(fn()=>$s->createExercise('Overlap','2024-03-01','2025-03-01',1),'Overlapping exercise');
$draft=static function (string $debit='100.10',string $credit='100.10',string $date='2024-01-10') use ($exercise): array {
    return ['exercise_id'=>$exercise,'journal_code'=>'ACH','entry_date'=>$date,'label'=>'Achat matériaux','reference'=>'FAC-TEST','request_key'=>bin2hex(random_bytes(32)),
        'lines'=>[['account_code'=>'6011','label'=>'Marchandises','third_party'=>'Fournisseur test','debit'=>$debit,'credit'=>'0'],['account_code'=>'4011','label'=>'Dette fournisseur','third_party'=>'Fournisseur test','debit'=>'0','credit'=>$credit]]];
};
$data=$draft(); $id=$s->saveDraft($data,1);
check($s->saveDraft($data,1)===$id,'Duplicate submit idempotency');
check(count($s->report($exercise,'2024-01-01','2024-12-31')['rows'])===0,'Draft excluded from books');
rejected(fn()=>$s->post($id,1,1),'Missing evidence');
$data+=['id'=>$id,'version'=>1]; $s->saveDraft($data,1,$doc);
rejected(fn()=>$s->saveDraft($data,1),'Stale edit');
rejected(fn()=>$s->post($id,1,1),'Stale validation');
$s->post($id,2,1); $entry=$s->entry($id);
check($entry['status']==='posted' && (int)$entry['sequence_number']===1,'First posting');
check($s->document((int)$entry['documents'][0]['id'])['content']===$doc['content'],'Evidence retrievable');
rejected(fn()=>$s->post($id,3,1),'Double posting');
rejected(fn()=>$s->saveDraft($data+['version'=>3],1),'Posted edit');
rejected(fn()=>$s->cancelDraft($id,3,1),'Posted cancellation');
$unequal=$s->saveDraft($draft('100.10','100.11'),1,$doc);
rejected(fn()=>$s->post($unequal,1,1),'One cent imbalance');
rejected(fn()=>$s->closePeriod($exercise,'2024-01-31',1),'Draft blocks closure');
$s->cancelDraft($unequal,1,1);
$back=$s->saveDraft($draft('1','1','2024-01-09'),1,$doc);
rejected(fn()=>$s->post($back,1,1),'Chronology'); $s->cancelDraft($back,1,1);
$invalid=$draft();$invalid['lines'][0]['account_code']='401';
rejected(fn()=>$s->saveDraft($invalid,1),'Grouping account');
$invalid=$draft();$invalid['lines'][0]['credit']='5';
rejected(fn()=>$s->saveDraft($invalid,1),'Both sides positive');
$invalid=$draft();$invalid['lines'][0]['account_code']='999999';
rejected(fn()=>$s->saveDraft($invalid,1),'Unknown account');
$before=(int)$pdo->query('SELECT COUNT(*) FROM accounting_entries')->fetchColumn();
rejected(fn()=>$s->saveDraft($draft(),1,['original_name'=>'bad.php','content'=>'<?php echo 1;']),'Invalid evidence atomic rollback');
check((int)$pdo->query('SELECT COUNT(*) FROM accounting_entries')->fetchColumn()===$before,'No partial draft after failed attachment');
$s->closePeriod($exercise,'2024-01-31',1);
rejected(fn()=>$s->saveDraft($draft(),1),'Closed month rejects entries');
$opening=$s->report($exercise,'2024-02-01','2024-12-31');
check($opening['balances'][6011]['opening']===10010,'Opening debit balance');
check($opening['balances'][4011]['opening']===-10010,'Opening credit balance');
$reversed=$s->reverse($id,$exercise,'2024-02-01','Correction facture',1);
check((int)$s->entry($reversed)['sequence_number']===2,'No sequence consumed on failed validation');
check((int)$s->entry($id)['reversed_by']['id']===$reversed,'Reversal linked');
rejected(fn()=>$s->reverse($id,$exercise,'2024-02-02','Again',1),'Duplicate reversal');
$report=$s->report($exercise,'2024-01-01','2024-12-31');
check(count($report['rows'])===4,'Original and reversal both in journal');
check($report['balances'][6011]['closing']===0 && $report['balances'][4011]['closing']===0,'Reversal neutralizes balances');
check(count($s->report($exercise,'2024-01-01','2024-12-31','ACH','4011')['rows'])===2,'Report filters');
rejected(fn()=>$s->report($exercise,'2023-01-01','2024-12-31'),'Report date boundary');
rejected(fn()=>$s->importAccounts([['code'=>'401100','label'=>'Subdivision utilisée']],1),'Used parent');
check(!in_array('401100',array_map('strval',array_column($s->accounts(),'code')),true),'Rejected import rolled back');
check($s->importAccounts([['code'=>'401900','label'=>'Fournisseur spécial']],1)===1,'New subdivision');
check($s->importAccounts([['code'=>'401900','label'=>'Fournisseur spécial']],1)===0,'Import repeat');
$s->addJournal('BQ2','Banque secondaire','banque',1);
rejected(fn()=>$s->addJournal('BQ2','Banque secondaire','banque',1),'Journal duplicate');
$s->closePeriod($exercise,'2024-12-31',1);
check($s->exercises()[0]['closed_at']!==null,'Exercise closed');
rejected(fn()=>$s->saveDraft($draft('1','1','2024-12-31'),1),'Closed exercise');
check(count($s->history($id))===4,'Audit trail');
check((int)$pdo->query('SELECT COUNT(*) FROM accounting_sequences WHERE last_number<>2')->fetchColumn()===0,'Sequence consistent');
foreach ($pdo->query('SHOW TABLE STATUS')->fetchAll() as $table) check($table['Engine']==='InnoDB','Transactional tables');
echo "AccountingServiceTest: all unit and MySQL integration checks passed.\n";
