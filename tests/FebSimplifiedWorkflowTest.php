<?php
declare(strict_types=1);
require_once __DIR__.'/../services/FebSubmissionContent.php';
require_once __DIR__.'/../services/FebPlanningSynchronizationService.php';
function febCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$needs=[['libelle'=>'Ciment','quantite'=>3,'fournisseur'=>['prix_unitaire'=>2500]],['libelle'=>'Transport','quantite'=>1,'fournisseur'=>['prix_unitaire'=>12500]]];
febCheck(FebSubmissionContent::total($needs)===20000.0,'Server total must use line quantities/prices');
febCheck(FebSubmissionContent::description('Approvisionnement',$needs)==="Approvisionnement\nCiment ; Transport",'Description must exist without removed fields');
febCheck(FebSubmissionContent::description('Devis',$needs,'','Historique')==='Historique','Historical description lost');
try{FebSubmissionContent::total([['quantite'=>-1,'fournisseur'=>['prix_unitaire'=>100]]]);throw new LogicException('Negative line accepted');}catch(DomainException $e){}
final class FebFlowStatement extends PDOStatement {
 public function __construct(private FebFlowPdo $db, private string $sql){}
 public function execute(?array $params=null):bool{$this->db->calls[]=[$this->sql,$params];return true;}
 public function fetchColumn(int $column=0):mixed{return str_contains($this->sql,'payload_json')?json_encode($this->db->payload):false;}
 public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed{return str_contains($this->sql,'feb_disbursement_links')?['ligne_debourse_id'=>22,'debourse_id'=>0]:false;}
}
final class FebFlowPdo extends PDO {
 public array $calls=[];
 public function __construct(public array $payload){}
 public function prepare(string $query,array $options=[]):PDOStatement|false{return new FebFlowStatement($this,$query);}
}
$payload=['description'=>FebSubmissionContent::description('Approvisionnement',$needs),'montant'=>20000,'besoins'=>$needs,'fournisseur_id'=>8,'code_autorisation_feb'=>'BCI-TEST','planned_start_date'=>'2026-09-10','planned_end_date'=>'2026-09-12','provisional_decaissement'=>['fournisseur_id'=>8,'montant_fiche'=>20000,'date_decaissement_minimum'=>'2026-09-10'],'urgent_decaissement_id'=>15];
$pdo=new FebFlowPdo($payload);$result=(new FebPlanningSynchronizationService($pdo))->synchronizeDate(9,'2026-09-15','Test');
febCheck($result['start']==='2026-09-15'&&$result['end']==='2026-09-17','Period not synchronized');
foreach(['description','montant','besoins','fournisseur_id','code_autorisation_feb','urgent_decaissement_id'] as $key)febCheck($result['payload'][$key]===$payload[$key],'Lost downstream field '.$key);
febCheck(!isset($result['payload']['impact']),'Removed impact reintroduced');
$febFormSource=(string)file_get_contents(__DIR__.'/../employe/nouvelle_demande_employe.php');
febCheck(substr_count($febFormSource,'<input id="febQuoteDocument" type="file" name="feb_global_justificatif"')===1,'The FEB must expose one supporting-document upload');
febCheck(!str_contains($febFormSource,'name="feb_compliance_document"'),'A second compliance upload was reintroduced');
febCheck(str_contains($febFormSource,'$complianceDocument = $globalProof ?: $legacyComplianceDocument;'),'FIDEST IA must reuse the single supporting document while preserving legacy reads');
febCheck(str_contains($febFormSource,'id="febReviewStep"'),'The ordinary FEB wizard must end with a native review step');
$updates=array_values(array_filter($pdo->calls,fn($c)=>str_contains($c[0],'UPDATE fiche_decaissement')));
febCheck(count($updates)===1&&$updates[0][1]['scheduled']==='2026-09-15','Linked disbursement dates not updated');
febCheck(str_contains($updates[0][0],'COALESCE(decaisse,0)=0'),'Already paid disbursement must not be replanned');
$historicalPayload=['planned_start_date'=>'2025-08-27','planned_end_date'=>'2025-09-10','feb_position_date'=>'2025-08-20','provisional_decaissement'=>['planned_start_date'=>'2025-08-27','planned_end_date'=>'2025-09-10','date_decaissement_minimum'=>'2025-08-20']];
$paymentPdo=new FebFlowPdo($historicalPayload);
$payment=(new FebPlanningSynchronizationService($paymentPdo))->synchronizePaymentDate(10,'2026-09-16','Couverture de trésorerie');
febCheck($payment['start']==='2025-08-27'&&$payment['end']==='2025-09-10','The payment date moved the work period');
febCheck($payment['payload']['provisional_decaissement']['date_decaissement_minimum']==='2026-09-16','The payment date was not updated');
febCheck($payment['payload']['feb_position_date']==='2025-08-20','The original FEB position was moved');
$paymentUpdates=array_values(array_filter($paymentPdo->calls,fn($c)=>str_contains($c[0],'UPDATE fiche_decaissement')));
febCheck(count($paymentUpdates)===1&&$paymentUpdates[0][1]['start']==='2025-08-27'&&$paymentUpdates[0][1]['scheduled']==='2026-09-16','The fiche must keep its work dates and receive the new payment date');
echo "FebSimplifiedWorkflowTest: OK (summary, amount, single document, historical data, planning, downstream links)\n";
