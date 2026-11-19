<?php
declare(strict_types=1);
require_once __DIR__.'/../decaissement/model/FicheDecaissement.php';
function paymentCheck(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
final class PaymentTestPdo extends PDO {
    public bool $transaction=false,$locked=false,$hasBc=false,$failRead=false,$funded=false;
    public ?array $dailyItem=null;
    public int $writes=0,$commits=0,$rollbacks=0,$ddlInTransaction=0;
    public array $fiche=['num_fiche'=>'TEST','decaisse'=>0,'conforme'=>1,'approuve'=>1,'etat_fiche'=>0,'personnel_id'=>7,'montant_fiche'=>100,'date_decaissement_minimum'=>'2000-01-01'];
    public function __construct(){}
    public function inTransaction():bool{return $this->transaction;}
    public function beginTransaction():bool{paymentCheck($this->locked,'Payment not serialized');$this->transaction=true;return true;}
    public function commit():bool{$this->transaction=false;$this->commits++;return true;}
    public function rollBack():bool{$this->transaction=false;$this->rollbacks++;return true;}
    public function exec(string $statement):int|false{if($this->transaction&&preg_match('/^\s*(CREATE|ALTER)/i',$statement)){$this->ddlInTransaction++;throw new RuntimeException('DDL inside transaction');}return 0;}
    public function prepare(string $query,array $options=[]):PDOStatement|false{return new PaymentTestStatement($this,$query);}
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs):PDOStatement|false{if(str_contains($query,'RELEASE_LOCK'))$this->locked=false;return new PaymentTestStatement($this,$query);}
}
final class PaymentTestStatement extends PDOStatement {
    public function __construct(private PaymentTestPdo $db,private string $sql){}
    public function execute(?array $params=null):bool{if(str_starts_with($this->sql,'UPDATE fiche_decaissement SET decaisse=1')){$this->db->writes++;paymentCheck($this->db->transaction,'Payment update outside transaction');}return true;}
    public function fetchColumn(int $column=0):mixed{if(str_contains($this->sql,"SHOW TABLES LIKE 'daily_disbursement_points'"))return $this->db->dailyItem!==null?'daily_disbursement_points':false;if(str_contains($this->sql,'GET_LOCK')){$this->db->locked=true;return 1;}if(str_contains($this->sql,'RELEASE_LOCK')){$this->db->locked=false;return 1;}if(str_contains($this->sql,'FROM personnel WHERE id_personnel_soignant='))return 1;if(str_contains($this->sql,'FROM bon_commande'))return $this->db->hasBc?1:false;return false;}
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed{if(str_contains($this->sql,'FROM daily_disbursement_point_items i JOIN daily_disbursement_points p'))return $this->db->dailyItem;if(str_contains($this->sql,'SELECT * FROM fiche_decaissement WHERE num_fiche=:n FOR UPDATE')){paymentCheck($this->db->transaction,'Ineffective row lock');if($this->db->failRead)throw new RuntimeException('Simulated database failure');return $this->db->fiche;}if(str_contains($this->sql,'SELECT f.* FROM fiche_decaissement'))return $this->db->fiche;return false;}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{if($this->db->funded&&str_contains($this->sql,'FROM treasury_fund_transfers'))return [['amount'=>1000,'transfer_type'=>'to_available']];if(str_starts_with($this->sql,'SHOW COLUMNS'))return ['decaisse_definitif','details','date_decaisse','secur_decaisse','code_parentale','montant_initial_fiche','date_decaissement_minimum','transfer_type'];return [];}
}
// Early exits must commit/rollback, release the shared lock and perform no payment.
foreach(['already_paid','future_date','not_approved','missing_receipt','insufficient_treasury','sql_failure','funded_payment','invalid_supplier'] as $case){
    $db=new PaymentTestPdo();
    if($case==='funded_payment')$db->funded=true;
    if($case==='invalid_supplier'){$db->funded=true;$db->fiche['fournisseur_id']=59;}
    if($case==='already_paid')$db->fiche['decaisse']=1;
    if($case==='future_date')$db->fiche['date_decaissement_minimum']='2099-12-31';
    if($case==='not_approved')$db->fiche['approuve']=0;
    if($case==='missing_receipt')$db->hasBc=true;
    if($case==='sql_failure')$db->failRead=true;
    try{$result=(new FicheDecaissement($db))->decaisserCompta('TEST','Test');paymentCheck($case!=='sql_failure','SQL error swallowed');paymentCheck($result===in_array($case,['already_paid','funded_payment'],true),'Wrong payment result: '.$case);}catch(RuntimeException $e){if($case!=='sql_failure')throw $e;}
    paymentCheck(!$db->transaction&&!$db->locked&&$db->writes===($case==='funded_payment'?1:0),'Early exit changed financial state: '.$case);
    paymentCheck($db->ddlInTransaction===0,'DDL interrupted payment transaction: '.$case);
    paymentCheck($db->commits+$db->rollbacks===1,'Transaction not closed exactly once');
}
echo "FebPaymentControlTest: OK (idempotence, schedule, approval, receipt, treasury, rollback)\n";

$db=new PaymentTestPdo();$db->funded=true;$db->fiche['fournisseur_id']=59;
$result=(new FicheDecaissement($db))->decaisserPartiel('TEST',50,'2026-12-01','Test');
paymentCheck(empty($result['ok'])&&str_contains($result['error']??'','fournisseur'),'Invalid supplier partial payment allowed');
paymentCheck($db->writes===0&&$db->rollbacks===1&&!$db->transaction&&!$db->locked,'Partial refusal altered financial state');
echo "Supplier payment enforcement: OK (total and partial refused without payment or balance mutation)\n";



// The same locked model protects endpoints that bypass the daily-point screen.
foreach (['certified','unsigned','different_amount','different_beneficiary','different_budget'] as $case) {
    $db=new PaymentTestPdo();$db->funded=true;
    $db->dailyItem=['point_status'=>'certified','certified_at'=>'2026-10-26','dg_signed'=>1,'amount'=>100,'snapshot_json'=>json_encode($db->fiche)];
    if($case==='unsigned')$db->dailyItem['point_status']='stopped';
    if($case==='different_amount')$db->dailyItem['amount']=50;
    if($case==='different_beneficiary')$db->fiche['personnel_id']=8;
    if($case==='different_budget')$db->fiche['chantier_id']=9;
    $result=(new FicheDecaissement($db))->decaisserCompta('TEST','Test');
    paymentCheck($result===($case==='certified'),'Point protection result: '.$case);
    paymentCheck($db->writes===($case==='certified'?1:0)&&!$db->transaction&&!$db->locked,'Unauthorized payment or lock leak: '.$case);
}
echo "Daily point model integration: OK (signed total, unsigned/mismatched amount, beneficiary and budget refused)\n";
