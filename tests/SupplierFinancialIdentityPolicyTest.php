<?php
declare(strict_types=1);
require_once __DIR__.'/../services/SupplierFinancialIdentityPolicy.php';
require_once __DIR__.'/../services/ExistingBusinessIdentityReview.php';
function check(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
function blocked(array $row):void {try{SupplierFinancialIdentityPolicy::assertCompanyIdentity($row);}catch(DomainException $e){return;}throw new RuntimeException('Identité non validée acceptée');}
SupplierFinancialIdentityPolicy::assertCompanyIdentity(['supplier_type'=>'morale','fiscal_country'=>'CI','ncc'=>'AB1234','dgi_verified_at'=>'2026-10-18']);
blocked(['supplier_type'=>'morale','fiscal_country'=>'CI','ncc'=>'AB1234']);
SupplierFinancialIdentityPolicy::assertCompanyIdentity(['supplier_type'=>'morale','fiscal_country'=>'FR','siret'=>'35038196800089','registry_verified_at'=>'2026-10-18']);
foreach (['DE','US','IE'] as $country) {
    SupplierFinancialIdentityPolicy::assertCompanyIdentity(['supplier_type'=>'morale','fiscal_country'=>$country,'identity_status'=>'documentary_verified']);
    blocked(['supplier_type'=>'morale','fiscal_country'=>$country,'identity_status'=>'documentary_pending','vat_status'=>'valid']);
}
$exception=['supplier_type'=>'morale','identity_method'=>'exception','identity_status'=>'exception_approved','nom_commercial'=>'Exception documentée','identity_evidence'=>'Dossier autorisé','identity_checked_by'=>7];
SupplierFinancialIdentityPolicy::assertCompanyIdentity($exception);blocked(array_replace($exception,['identity_checked_by'=>0]));
// The existing personal-supplier and signed RH circuits are not routed through a corporate NCC test.
SupplierFinancialIdentityPolicy::assertCompanyIdentity(['supplier_type'=>'physique']);
$before=['client_type'=>'physique','fiscal_country'=>'CI','nom_client'=>' Nom ','ncc'=>'OLD','identity_key'=>'CI:PERSON:123','identity_status'=>'documentary_verified'];$copy=$before;
$review=ExistingBusinessIdentityReview::review($before,false);
check($before===$copy,'Audit modifies input');check(in_array('ncc_hors_profil_a_examiner',$review,true),'Private NCC not reported');check(in_array('espaces_denomination',$review,true),'Whitespace not reported');
check(ExistingBusinessIdentityReview::duplicateKeys(['fiscal_country'=>'CI','ncc'=>'AB-1234'])===ExistingBusinessIdentityReview::duplicateKeys(['fiscal_country'=>'CI','ncc'=>'AB 1234']),'Canonical duplicate matching');
echo "SupplierFinancialIdentityPolicyTest: OK (CI, FR, foreign, VAT pending, exception, personal and read-only review)\n";

// Execute the real FEB completion function against SQLite, without booting an HTTP session.
$source=(string)file_get_contents(__DIR__.'/../validation_demandes_finances.php');
check(str_starts_with($source,'<?php'),'FEB file must start with PHP, without tool output');
check(!str_contains($source,'Warning: truncated output')&&!str_contains($source,'tokens truncated'),'FEB source contains truncated tool output');
token_get_all($source,TOKEN_PARSE);
$start=strpos($source,'function febCompleteSelectedSupplier(');$end=strpos($source,'function febBuildEditedPayload(',$start);
check($start!==false&&$end!==false,'FEB completion entry point missing');
eval(substr($source,$start,$end-$start));
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE fournisseur_banamur(id_fournisseur INTEGER PRIMARY KEY,actif INT,validation_status TEXT,supplier_type TEXT,fiscal_country TEXT,identity_status TEXT,identity_method TEXT,ncc TEXT,dgi_verified_at TEXT,siret TEXT,registry_verified_at TEXT,nom_commercial TEXT,raison_sociale TEXT,telephone TEXT,email TEXT,adresse TEXT,photo_path TEXT,logo_path TEXT)');
$db->exec("INSERT INTO fournisseur_banamur VALUES(1,1,'approved','morale','FR','registry_verified','fr',NULL,NULL,'35038196800089','2026-10-18','Société','SAS','0102030405','test@example.com','Paris','photo.png',NULL)");
febCompleteSelectedSupplier($db,['fd_fournisseur_id'=>1,'supplier_completion_enabled'=>1],[]);
$db->exec("UPDATE fournisseur_banamur SET fiscal_country='US',identity_method='manual',identity_status='documentary_verified',siret=NULL,registry_verified_at=NULL");
febCompleteSelectedSupplier($db,['fd_fournisseur_id'=>1,'supplier_completion_enabled'=>1],[]);
$db->exec("UPDATE fournisseur_banamur SET fiscal_country='CI',identity_method='fne'");
try {febCompleteSelectedSupplier($db,['fd_fournisseur_id'=>1,'supplier_completion_enabled'=>1],[]);throw new LogicException('CI missing NCC accepted');}catch(DomainException $expected){}
echo "FEB supplier completion: OK (real function, French and US accepted without NCC, CI still blocked)\n";
