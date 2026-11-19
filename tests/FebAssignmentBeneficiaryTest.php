<?php
require_once __DIR__.'/../services/FebAssignmentBeneficiary.php';
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE fournisseur_banamur(id_fournisseur INT, nom_commercial TEXT, raison_sociale TEXT, contact_nom TEXT, telephone TEXT)');
$pdo->exec("INSERT INTO fournisseur_banamur VALUES(1,'Ancien','','','111'),(2,'Depfu','','','222')");
$pdo->exec('CREATE TABLE personnel(id_personnel_soignant INT,nom_personnel_soignant TEXT,tel_personnel_soignant TEXT)');
$pdo->exec("INSERT INTO personnel VALUES(3,'Employé','333')");
$check=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
$payload=['montant'=>42000,'status'=>'submitted','provisional_decaissement'=>['chantier_id'=>5,'ligne_debourse_id'=>7,'beneficiary_mode'=>'supplier','fournisseur_id'=>1,'montant_fiche'=>42000]];
$post=['fd_beneficiary_mode'=>'supplier','fd_fournisseur_id'=>2,'fd_beneficiaire_fiche'=>'Nom falsifié','status'=>'approved'];
$result=FebAssignmentBeneficiary::apply($pdo,$payload,$post);
$check($result!==$payload,'Supplier-only edit ignored');
$check($result['provisional_decaissement']['fournisseur_id']===2,'Supplier not persisted');
$check($result['provisional_decaissement']['beneficiaire_fiche']==='Depfu','Untrusted supplier name');
$check($result['status']==='submitted'&&$result['montant']===42000&&$result['provisional_decaissement']['ligne_debourse_id']===7,'Unrelated financial data changed');
$check(FebAssignmentBeneficiary::apply($pdo,$result,$post)===$result,'Repeated save not stable');
$check(FebAssignmentBeneficiary::apply($pdo,$payload,[])===$payload,'Legacy budget-only request changed recipient');
foreach([0,999]as$id){try{FebAssignmentBeneficiary::apply($pdo,$payload,['fd_beneficiary_mode'=>'supplier','fd_fournisseur_id'=>$id]);throw new LogicException('Invalid supplier accepted');}catch(DomainException $expected){}}
$person=FebAssignmentBeneficiary::apply($pdo,$result,['fd_beneficiary_mode'=>'personnel','fd_personnel_id'=>3]);
$check($person['provisional_decaissement']['fournisseur_id']===0&&$person['provisional_decaissement']['beneficiaire_fiche']==='Employé','Stale supplier after recipient change');
echo "FebAssignmentBeneficiaryTest: OK (supplier-only edit, authoritative identity, idempotence, invalid IDs, personnel and financial data preservation)\n";
