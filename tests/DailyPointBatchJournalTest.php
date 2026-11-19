<?php
declare(strict_types=1);
require_once __DIR__.'/FebPaymentControlTest.php';
require_once __DIR__.'/../services/DailyDisbursementPointService.php';
final class BatchJournalPdo extends PDO {
    public PaymentTestPdo $payment;
    public PDO $journal;
    public array $rows;
    public function __construct(){
        $this->payment=new PaymentTestPdo();$this->payment->funded=true;
        $this->rows=['GOOD'=>array_replace($this->payment->fiche,['num_fiche'=>'GOOD']),'BAD'=>array_replace($this->payment->fiche,['num_fiche'=>'BAD','fournisseur_id'=>59])];
        $this->journal=new PDO('sqlite::memory:');$this->journal->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $this->journal->sqliteCreateFunction('NOW',fn()=>date('Y-m-d H:i:s'));
        $this->journal->sqliteCreateFunction('GET_LOCK',fn($name,$timeout)=>1,2);
        $this->journal->sqliteCreateFunction('RELEASE_LOCK',fn($name)=>1,1);
        $this->journal->exec('CREATE TABLE daily_disbursement_point_payments(id INTEGER PRIMARY KEY,point_id INT,user_id INT,payer_name TEXT,signature_path TEXT,payment_mode TEXT,payment_reference TEXT,proof_path TEXT,total_amount REAL,item_count INT,paid_at TEXT)');
        $this->journal->exec('CREATE TABLE daily_disbursement_point_payment_items(payment_id INT,point_item_id INT,fiche_number TEXT,amount REAL)');
    }
    public function inTransaction():bool{return $this->payment->inTransaction();}
    public function beginTransaction():bool{return $this->payment->beginTransaction();}
    public function commit():bool{return $this->payment->commit();}
    public function rollBack():bool{return $this->payment->rollBack();}
    public function exec(string $statement):int|false{return $this->payment->exec($statement);}
    public function lastInsertId(?string $name=null):string|false{return $this->journal->lastInsertId($name);}
    public function prepare(string $query,array $options=[]):PDOStatement|false{
        if(str_contains($query,'daily_disbursement_point_payments')||str_contains($query,'daily_disbursement_point_payment_items')||str_contains($query,'GET_LOCK(?)')||str_contains($query,'RELEASE_LOCK(?)'))return $this->journal->prepare($query);
        return new BatchJournalStatement($this,$query,$this->payment->prepare($query));
    }
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs):PDOStatement|false{
        if(str_starts_with($query,'SELECT id FROM daily_disbursement_points'))return $this->journal->query('SELECT 1');
        return $this->payment->query($query);
    }
}
final class BatchJournalStatement extends PDOStatement {
    public function __construct(private BatchJournalPdo $db,private string $sql,private PDOStatement $delegate){}
    public function execute(?array $params=null):bool{
        $num=$params['num']??$params['n']??null;
        if(is_string($num)&&isset($this->db->rows[$num]))$this->db->payment->fiche=$this->db->rows[$num];
        $ok=$this->delegate->execute($params);
        if(str_starts_with($this->sql,'UPDATE fiche_decaissement SET decaisse=1')&&is_string($num))$this->db->rows[$num]['decaisse']=1;
        return $ok;
    }
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed{return $this->delegate->fetch($mode,$cursorOrientation,$cursorOffset);}
    public function fetchColumn(int $column=0):mixed{return $this->delegate->fetchColumn($column);}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{return $this->delegate->fetchAll($mode,...$args);}
}
final class BatchJournalService extends DailyDisbursementPointService {
    public function __construct(private BatchJournalPdo $db){(new ReflectionProperty(DailyDisbursementPointService::class,'pdo'))->setValue($this,$db);}
    public function find(int $id):?array{
        return ['id'=>1,'status'=>'certified','certified_at'=>'2026-10-26','items'=>[['id'=>1,'fiche_number'=>'GOOD','amount'=>100],['id'=>2,'fiche_number'=>'BAD','amount'=>100]],'point_totals'=>['to_pay'=>empty($this->db->rows['GOOD']['decaisse'])?200:100]];
    }
}
$db=new BatchJournalPdo();$service=new BatchJournalService($db);
try{$service->disburseSelection(1,['GOOD','BAD'],'Payeur',2,'signature.png','virement');throw new RuntimeException('Partial batch did not report the failed fiche');}
catch(RuntimeException $e){paymentCheck(str_contains($e->getMessage(),'enregistrés'),'Successful payments not reported as journalled: '.$e->getMessage());}
$row=$db->journal->query('SELECT * FROM daily_disbursement_point_payments')->fetch(PDO::FETCH_ASSOC);
paymentCheck($row&&(float)$row['total_amount']===100.0&&(int)$row['item_count']===1,'Batch journal lost or included a refused payment');
paymentCheck($db->journal->query('SELECT fiche_number FROM daily_disbursement_point_payment_items')->fetchColumn()==='GOOD','Incorrect journal detail');
paymentCheck($db->rows['GOOD']['decaisse']===1&&$db->rows['BAD']['decaisse']===0,'Incorrect actual payment states');
echo "DailyPointBatchJournalTest: OK (successful payment journalled when next supplier fails)\n";
