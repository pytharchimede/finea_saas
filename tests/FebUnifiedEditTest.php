<?php
require_once __DIR__.'/../services/FinancialWorkflowProgress.php';
require_once __DIR__.'/../services/FebAssignmentBeneficiary.php';
require_once __DIR__.'/../services/FebCategoryMigrationService.php';
require_once __DIR__.'/../services/FebWorkflowGuard.php';
$source=file_get_contents(__DIR__.'/../validation_demandes_finances.php');
foreach(['febMoneyInput','febBuildEditedPayload','febDescribePayloadChanges']as$name){
    $start=strpos($source,'function '.$name.'(');$end=strpos($source,"\nfunction ",$start+1);
    eval(substr($source,$start,$end-$start));
}
class EditPDO extends PDO {public function prepare(string $query,array $options=[]):PDOStatement|false{return parent::prepare(str_replace(' FOR UPDATE','',$query),$options);}}
$pdo=new EditPDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE employee_legal_requests(id INT PRIMARY KEY,status TEXT,payload TEXT)');
$pdo->exec('CREATE TABLE events(note TEXT)');
$pdo->exec('CREATE TABLE fournisseur_banamur(id_fournisseur INT,nom_commercial TEXT,raison_sociale TEXT,contact_nom TEXT,telephone TEXT)');
$pdo->exec("INSERT INTO fournisseur_banamur VALUES(1,'Ancien','','','111'),(2,'Depfu','','','222')");
$manager=new class($pdo){public function __construct(private PDO $pdo){}public function getRequestById($id){$r=$this->pdo->query('SELECT * FROM employee_legal_requests WHERE id=1')->fetch(PDO::FETCH_ASSOC);$r['payload']=json_decode($r['payload'],true);return$r;}public function updateFebBeforeDecision($id,$payload,...$rest){return$this->pdo->prepare('UPDATE employee_legal_requests SET payload=? WHERE id=?')->execute([json_encode($payload),$id]);}public function addRequestEvent($id,$type,$note,$actor){$this->pdo->prepare('INSERT INTO events VALUES(?)')->execute([$note]);}};
$treasuryFundingService=new class{public array $amounts=[];public function synchronizeAmount($id,$amount,$actor){$this->amounts[]=$amount;}};
$febPlanningSync=new class{public array $periods=[];public bool $fail=false;public function synchronizePeriod(...$args){$this->periods[]=$args;if($this->fail)throw new RuntimeException('Fixture synchronisation refusée');}public function synchronizeDate(...$args){}};
function febFinanceSynchronizeLinkedFicheAmount(...$args){$GLOBALS['amountSync']=$args[2];}
$start=strpos($source,"        if (\$action === 'save_popup_adjustments') {");$end=strpos($source," elseif (\$action === 'save_amount_adjustment')",$start);
$branch=substr($source,$start,$end-$start);
$branch=str_replace("header('Location: '.\$postListUrl);exit;",'return true;',$branch);
$run=eval('return function() use($pdo,$manager,$treasuryFundingService,$febPlanningSync){$id=1;$actor=["user_id"=>2,"name"=>"Finance"];$action="save_popup_adjustments";'.$branch.'return $flash;};');
$before=['montant'=>42000,'planned_start_date'=>'2026-10-20','planned_end_date'=>'2026-10-21','provisional_decaissement'=>['active'=>false,'beneficiary_mode'=>'supplier','fournisseur_id'=>1,'chantier_id'=>5,'ligne_debourse_id'=>7]];
$reset=function()use($pdo,$before){$pdo->exec('DELETE FROM employee_legal_requests');$pdo->exec('DELETE FROM events');$pdo->prepare("INSERT INTO employee_legal_requests VALUES(1,'submitted',?)")->execute([json_encode($before)]);};
$check=static function($ok,$message){if(!$ok)throw new RuntimeException($message);};
$reset();$_POST=['fd_beneficiary_mode'=>'supplier','fd_fournisseur_id'=>2,'description'=>'Nouvelle description','montant'=>'45000','fd_montant_fiche'=>'45000','planned_start_date'=>'2026-10-22','planned_end_date'=>'2026-10-23','generate_decaissement'=>1];
$check($run()===true,'Combined save failed');$row=$manager->getRequestById(1);$saved=$row['payload'];
$check($saved['provisional_decaissement']['fournisseur_id']===2&&$saved['description']==='Nouvelle description'&&$saved['montant']==45000,'Combined edits not saved');
$check($row['status']==='submitted'&&$saved['provisional_decaissement']['active']===false,'Decision or generation flag changed');
$check($GLOBALS['amountSync']===45000.0&&$treasuryFundingService->amounts===[45000.0]&&count($febPlanningSync->periods)===1,'Financial synchronisation omitted');
$check((int)$pdo->query('SELECT COUNT(*) FROM events')->fetchColumn()===1,'Missing history');
$reset();$febPlanningSync->fail=true;$check($run()!==true,'Sync failure ignored');$check($manager->getRequestById(1)['payload']===$before,'Partial edits persisted after failure');
$check((int)$pdo->query('SELECT COUNT(*) FROM events')->fetchColumn()===0,'Event persisted after rollback');
$reset();$pdo->exec("UPDATE employee_legal_requests SET status='approved'");$check($run()!==true,'Concurrent approval overwritten');
$check(FebAssignmentBeneficiary::supplierId(['global_fournisseur_id'=>99,'provisional_decaissement'=>['beneficiary_mode'=>'supplier','fournisseur_id'=>2]])===2,'Old higher ID overrides selection');
echo "FebUnifiedEditTest: OK (combined save, supplier precedence, no approval/generation, synchronisation, rollback and concurrent decision)\n";
