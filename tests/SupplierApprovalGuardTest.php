<?php
declare(strict_types=1);
require_once __DIR__.'/SupplierPaymentGuardTest.php';
require_once __DIR__.'/../services/SupplierApprovalGuard.php';
require_once __DIR__.'/../decaissement/model/Fiche.php';

$proof=tempnam(__DIR__,'supplier-proof-');file_put_contents($proof,'test proof');
$relative='tests/'.basename($proof);
try{
 $error=SupplierApprovalGuard::error($db,['fournisseur_id'=>1]);
 strictCheck(str_contains($error??'','Approbation bloquée')&&str_contains($error,'validation fournisseur'),'Approver not informed of supplier anomaly');
 $db->prepare("UPDATE fournisseur_banamur SET validation_status='approved',logo_path=?")->execute([$relative]);
 strictCheck(SupplierApprovalGuard::error($db,['fournisseur_id'=>1])===null,'Valid supplier approval blocked');
 $payload=['provisional_decaissement'=>['fournisseur_id'=>1]];
 SupplierApprovalGuard::assertPayload($db,$payload);
 strictCheck(SupplierApprovalGuard::payloadError($db,$payload,['fd_fournisseur_id'=>''])!==null,'Empty posted selection reused previous supplier');
 $db->exec("UPDATE fournisseur_banamur SET actif=0");
 strictCheck(SupplierApprovalGuard::payloadError($db,$payload)!==null,'Supplier changed after preview still approved');
 $db->exec('CREATE TABLE fiche(num_fiche TEXT PRIMARY KEY,approuve INT,decaisse INT,fournisseur_id INT,secur_approuve TEXT,date_approuve TEXT)');
 $db->exec("INSERT INTO fiche VALUES('LEGACY-TEST',0,0,1,NULL,NULL)");
 try{(new Fiche($db))->approveFicheByNum('LEGACY-TEST','Test');throw new LogicException('Legacy endpoint bypassed inactive supplier');}catch(DomainException $expected){}
 strictCheck((int)$db->query("SELECT approuve FROM fiche")->fetchColumn()===0&&!$db->inTransaction(),'Rejected approval changed financial state');
 $db->exec('UPDATE fournisseur_banamur SET actif=1');
 strictCheck((new Fiche($db))->approveFicheByNum('LEGACY-TEST','Test')===true,'Valid legacy approval failed');
 strictCheck((int)$db->query('SELECT approuve FROM fiche')->fetchColumn()===1,'Approval not persisted');
 $db->exec('UPDATE fiche SET approuve=2');
 try{(new Fiche($db))->approveFicheByNum('LEGACY-TEST','Test');throw new LogicException('Refused fiche approved');}catch(DomainException $expected){}
 echo "SupplierApprovalGuardTest: OK (visible reasons, authoritative selection, stale supplier, real legacy transaction, rollback and refused status)\n";
}finally{unlink($proof);}
