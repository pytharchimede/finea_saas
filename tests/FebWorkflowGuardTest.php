<?php
declare(strict_types=1);
require_once __DIR__.'/../services/FebWorkflowGuard.php';
function guardCheck(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
foreach (['submitted','details_requested'] as $status) FebWorkflowGuard::assertPending(['request_type'=>'feb','status'=>$status]);
foreach (['approved','rejected','cancelled','draft',''] as $status) {
    try { FebWorkflowGuard::assertPending(['request_type'=>'feb','status'=>$status]); throw new LogicException('Closed FEB accepted: '.$status); }
    catch (DomainException $expected) {}
}
foreach (['2026-02-29','2026-04-31','2026-13-01','2026-00-10','2026-1-01',"2026-01-01\n"] as $date) guardCheck(!FebWorkflowGuard::validDate($date),'Invalid date accepted');
guardCheck(FebWorkflowGuard::validDate('2028-02-29'),'Leap year rejected');
guardCheck(FebWorkflowGuard::supplierId(['fd_fournisseur_id'=>3],['fournisseur_id'=>90],['fournisseur_id'=>100])===3,'Explicit lower supplier ID lost');
guardCheck(FebWorkflowGuard::supplierId(['fd_fournisseur_id'=>''],['fournisseur_id'=>90],[])===0,'Empty selection must not reuse old supplier');
guardCheck(FebWorkflowGuard::supplierId([],['fournisseur_id'=>3],['fournisseur_id'=>100])===3,'Draft priority lost');
echo "FebWorkflowGuardTest: OK (states, calendar dates, authoritative supplier selection)\n";

require_once __DIR__.'/../model/EmployeeLegalRequestManager.php';
final class DecisionRacePdo extends PDO {
    public array $writes=[];
    public function __construct(){}
    public function inTransaction():bool{return true;}
    public function prepare(string $query,array $options=[]):PDOStatement|false{return new DecisionRaceStatement($this,$query);}
}
final class DecisionRaceStatement extends PDOStatement {
    public function __construct(private DecisionRacePdo $db,private string $sql){}
    public function execute(?array $params=null):bool{$this->db->writes[]=[$this->sql,$params];return true;}
    public function rowCount():int{return 0;} // Another decision changed the row before the UPDATE.
}
final class DecisionRaceManager extends EmployeeLegalRequestManager {
    public function __construct(PDO $pdo,public string $testStatus){(new ReflectionProperty(EmployeeLegalRequestManager::class,'pdo'))->setValue($this,$pdo);}
    public function getRequestById(int $requestId):?array{return ['id'=>$requestId,'request_type'=>'feb','status'=>$this->testStatus,'payload'=>[]];}
}
foreach(['approved','rejected','cancelled'] as $status){
    $db=new DecisionRacePdo();$manager=new DecisionRaceManager($db,$status);
    guardCheck(!$manager->approveRequest(1,[], '',false,true),'Model reopened a closed FEB');
    guardCheck($db->writes===[],'Closed FEB caused side effects');
}
foreach(['approveRequest','rejectRequest'] as $method){
    $db=new DecisionRacePdo();$manager=new DecisionRaceManager($db,'submitted');
    guardCheck(!$manager->$method(1,[],'',false),'Concurrent model decision reported success');
    guardCheck(count($db->writes)===1&&str_contains($db->writes[0][0],'status = :previous_status'),'Decision event emitted after lost race');
}
echo "FEB model transitions: OK (closed states and concurrent decision)\n";
