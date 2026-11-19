<?php
declare(strict_types=1);
require_once __DIR__.'/../services/AccountingService.php';
$dsn=getenv('ACCOUNTING_TEST_DSN');
if (!$dsn) { echo "Set ACCOUNTING_TEST_DSN to an empty disposable database.\n"; exit; }
$pdo=new PDO($dsn,getenv('ACCOUNTING_TEST_USER')?:'root',getenv('ACCOUNTING_TEST_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if (($argv[1]??'')==='worker') {
    $s=new AccountingService($pdo,false);
    try { $s->post((int)$argv[2],1,1); echo 'posted'; } catch (DomainException $e) { echo 'rejected'; }
    exit;
}
if ($pdo->query('SHOW TABLES')->fetchAll()!==[]) throw new RuntimeException('Database must be empty');
$s=new AccountingService($pdo); $exercise=$s->createExercise('Concurrent 2024','2024-01-01','2024-12-31',1);
$doc=AccountingService::validateDocument('test.pdf',"%PDF-1.4\n%%EOF\n");
$create=static function() use ($s,$exercise,$doc): int {
    return $s->saveDraft(['exercise_id'=>$exercise,'entry_date'=>'2024-01-10','journal_code'=>'OD','label'=>'Concurrent','reference'=>'TEST','request_key'=>bin2hex(random_bytes(32)),
        'lines'=>[['account_code'=>'4011','label'=>'Supplier','debit'=>'1','credit'=>'0'],['account_code'=>'5211','label'=>'Bank','debit'=>'0','credit'=>'1']]],1,$doc);
};
$parallel=static function(array $ids): array {
    $workers=[];
    foreach ($ids as $id) {
        $process=proc_open([PHP_BINARY,__FILE__,'worker',(string)$id],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if (!is_resource($process)) throw new RuntimeException('Cannot start worker');
        $workers[]=[$process,$pipes];
    }
    $output=[];
    foreach ($workers as [$process,$pipes]) {
        $output[]=stream_get_contents($pipes[1]); $error=stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process)!==0 || $error!=='') throw new RuntimeException('Worker error: '.$error);
    }
    sort($output); return $output;
};
$one=$create();$two=$create();
if ($parallel([$one,$two])!==['posted','posted']) throw new RuntimeException('Concurrent distinct entries failed');
$numbers=$pdo->query("SELECT sequence_number FROM accounting_entries WHERE status='posted' ORDER BY sequence_number")->fetchAll(PDO::FETCH_COLUMN);
if (array_map('intval',$numbers)!==[1,2]) throw new RuntimeException('Sequence collision');
$three=$create();
if ($parallel([$three,$three])!==['posted','rejected']) throw new RuntimeException('Concurrent duplicate validation not prevented');
if ((int)$pdo->query('SELECT last_number FROM accounting_sequences')->fetchColumn()!==3) throw new RuntimeException('Unexpected numbering gap');
echo "AccountingConcurrencyTest: parallel numbering and duplicate posting passed.\n";
