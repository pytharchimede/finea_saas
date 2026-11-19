<?php
declare(strict_types=1);
require_once __DIR__.'/../services/FebPriceChallengeService.php';
require_once __DIR__.'/../services/FinancialWorkflowProgress.php';
require_once __DIR__.'/../services/FebCategoryMigrationService.php';
$source=file_get_contents(__DIR__.'/../validation_demandes_finances.php');
foreach (['febMoneyInput','febBuildEditedPayload','febFinanceSaveApprovalAmount','febFinanceApplyApprovedFebValues'] as $name) {
    $start=strpos($source,'function '.$name.'(');
    $end=strpos($source,"\nfunction ",$start+1);
    eval(substr($source,$start,$end-$start));
}
class CurrentApprovalPDO extends PDO {
    public function prepare(string $query,array $options=[]):PDOStatement|false {
        $query=str_replace(' FOR UPDATE','',$query);
        if (str_contains($query,'UPDATE feb_price_challenge_followups f')) $query='UPDATE feb_price_challenge_followups SET status="closed",closed_at=NOW() WHERE challenge_id IN (SELECT id FROM feb_price_challenges WHERE feb_request_id=:request) AND status="open"';
        if (str_starts_with(ltrim($query),'UPDATE')) $query=str_replace('LIMIT 1','',$query);
        return parent::prepare($query,$options);
    }
}
function currentCheck(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
$db=new CurrentApprovalPDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->sqliteCreateFunction('NOW',static fn()=>'2026-10-26 17:00:00');
$db->exec('CREATE TABLE employee_legal_requests(id INT PRIMARY KEY,request_type TEXT,status TEXT,payload_json TEXT,updated_at TEXT);
    CREATE TABLE feb_price_challenges(id INT,feb_request_id INT,status TEXT,archived_at TEXT,archive_reason TEXT);
    CREATE TABLE feb_price_challenge_followups(challenge_id INT,status TEXT,closed_at TEXT)');
$db->exec('INSERT INTO feb_price_challenges VALUES(1,13589,"pending",NULL,NULL),(2,13589,"retained",NULL,NULL);
    INSERT INTO feb_price_challenge_followups VALUES(1,"open",NULL)');
$payload=['montant'=>45000,'initial_requested_amount'=>30000,'description'=>'Montant corrigé',
    'fournisseur_id'=>41,'besoins'=>[['quantite'=>1,'fournisseur'=>['id'=>41,'prix_unitaire'=>30000]]],
    'provisional_decaissement'=>['beneficiary_mode'=>'supplier','fournisseur_id'=>122,'beneficiaire_fiche'=>'ABOKPOE KOFFI DENIS','montant_fiche'=>45000]];
$db->prepare('INSERT INTO employee_legal_requests VALUES(13589,"feb","submitted",?,NULL)')->execute([json_encode($payload)]);
$manager=new class($db) {
    public function __construct(private PDO $pdo){}
    public function getRequestById($id){$r=$this->pdo->query('SELECT * FROM employee_legal_requests WHERE id=13589')->fetch(PDO::FETCH_ASSOC);$r['payload']=json_decode($r['payload_json'],true);return $r;}
    public function updateFebBeforeDecision($id,$payload,...$args){return $this->pdo->prepare('UPDATE employee_legal_requests SET payload_json=? WHERE id=?')->execute([json_encode($payload),$id]);}
};
$service=(new ReflectionClass(FebPriceChallengeService::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(FebPriceChallengeService::class,'pdo'))->setValue($service,$db);
$db->beginTransaction();
$service->closeForCurrentApproval(13589);
currentCheck($manager->getRequestById(13589)['payload']===$payload,'Saved amount, line history or beneficiary overwritten');
currentCheck($db->query('SELECT status FROM feb_price_challenges WHERE id=2')->fetchColumn()==='retained','Retained proposal changed');
$request=febFinanceSaveApprovalAmount($manager,13589,$manager->getRequestById(13589),['montant'=>'47000'],['user_id'=>2]);
$post=febFinanceApplyApprovedFebValues($request,['fd_montant_fiche'=>30000,'fd_fournisseur_id'=>41]);
currentCheck((float)$post['fd_montant_fiche']===47000.0 && (int)$post['fd_fournisseur_id']===122,'Linked fiche would use stale amount or supplier');
$service->closeForCurrentApproval(13589);
currentCheck($manager->getRequestById(13589)['payload']['montant']===47000,'Approval reset amount entered in popup');
foreach(['0','-1','abc'] as $amount){
    try{febFinanceSaveApprovalAmount($manager,13589,$request,['montant'=>$amount],[]);throw new LogicException('Invalid amount accepted');}catch(DomainException $expected){}
}
// Execute the production BCI snapshot method with a minimal manager fixture.
$model=file_get_contents(__DIR__.'/../model/EmployeeLegalRequestManager.php');
$start=strpos($model,'    public function generateFebBciForScheduledDisbursement(');
$end=strpos($model,'    private function updateFebPayloadJson(',$start);
$method=substr($model,$start,$end-$start);
eval('class CurrentBciFixture {
    private const STATUS_APPROVED="approved";
    public function __construct(private PDO $pdo){}
    public function getRequestById($id){$r=$this->pdo->query("SELECT * FROM employee_legal_requests WHERE id=13589")->fetch(PDO::FETCH_ASSOC);$r["payload"]=json_decode($r["payload_json"],true);return $r;}
    private static function extractFebAuthorizationCodeFromPayload($payload){return "";}
    private function addEvent(...$args){}
'.$method.'}');
$db->exec('UPDATE employee_legal_requests SET status="approved" WHERE id=13589');
$bci=(new CurrentBciFixture($db))->generateFebBciForScheduledDisbursement(13589,'2026-10-28',['user_id'=>2]);
$approved=$manager->getRequestById(13589)['payload'];
currentCheck($bci['created'] && (float)$approved['bci_details']['approved_amount']===47000.0,'BCI did not snapshot final amount');
currentCheck((float)$approved['bci_details']['initial_amount']===30000.0,'Historical amount lost');
$db->rollBack();
currentCheck($manager->getRequestById(13589)['payload']===$payload,'Rollback did not restore FEB');
currentCheck(!str_contains($source,'->retainAllInitial('),'Approval still calls initial-price restoration');
echo "FebCurrentApprovalValuesTest: OK (saved edits, popup amount, beneficiary, BCI snapshot, proposal closure, rollback)\n";
