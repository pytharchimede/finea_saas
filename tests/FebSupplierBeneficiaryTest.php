<?php
declare(strict_types=1);
require_once __DIR__.'/../services/FebSupplierBeneficiary.php';
final class FebBeneficiaryPdo extends PDO {
    public array $supplier=[];
    public function __construct() {}
    public function prepare(string $query,array $options=[]):PDOStatement|false { return new FebBeneficiaryStatement($this,$query); }
}
final class FebBeneficiaryStatement extends PDOStatement {
    public function __construct(private FebBeneficiaryPdo $db,private string $sql) {}
    public function execute(?array $params=null):bool {return true;}
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed {return $this->db->supplier ?: false;}
    public function fetchColumn(int $column=0):mixed {return false;}
}
$db=new FebBeneficiaryPdo();
function beneficiaryCheck(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
beneficiaryCheck(FebSupplierBeneficiary::resolve($db,['payload'=>['global_fournisseur_id'=>1]])===null,'A purchase supplier must not exempt the natural beneficiary');
foreach(['personnel','external'] as $mode) beneficiaryCheck(FebSupplierBeneficiary::resolve($db,['payload'=>['provisional_decaissement'=>['beneficiary_mode'=>$mode,'fournisseur_id'=>1]]])===null,'Personal recipient bypass');
beneficiaryCheck(!FebSupplierBeneficiary::requiresIdentity(['supplier_type'=>'morale']),'Company requires CNI');
beneficiaryCheck(FebSupplierBeneficiary::requiresIdentity(['supplier_type'=>'physique']),'Individual CNI waived');
beneficiaryCheck(FebSupplierBeneficiary::requiresIdentity([]),'Unknown type bypass');
// Existing real file, company identity and active approval; neither CNI field exists.
$file=tempnam(__DIR__,'beneficiary-');file_put_contents($file,'logo');
try {
$db->supplier=['id_fournisseur'=>1,'supplier_type'=>'morale','fiscal_country'=>'CI','actif'=>1,'validation_status'=>'approved','raison_sociale'=>'SARL','nom_commercial'=>'Société','contact_nom'=>'Gérant','email'=>'contact@example.test','telephone'=>'0102030405','adresse'=>'Abidjan','ncc'=>'AB1234','dgi_verified_at'=>'2026-10-26','logo_path'=>'tests/'.basename($file)];
$request=['status'=>'approved','payload'=>['provisional_decaissement'=>['beneficiary_mode'=>'supplier','fournisseur_id'=>1]]];
beneficiaryCheck(FebSupplierBeneficiary::resolve($db,$request)['id_fournisseur']===1,'Approved company without CNI refused');
$legacy=$request;$legacy['payload']['provisional_decaissement']['beneficiary_mode']='';$legacy['payload']['provisional_decaissement']['beneficiary_choice']='supplier';
beneficiaryCheck(FebSupplierBeneficiary::resolve($db,$legacy)!==null,'Legacy explicit supplier choice lost');
foreach(['CI','FR','DE','US'] as $country){
$db->supplier['fiscal_country']=$country;$db->supplier['siret']='35038196800089';$db->supplier['registry_verified_at']='2026-10-26';$db->supplier['identity_status']='documentary_verified';
beneficiaryCheck(FebSupplierBeneficiary::resolve($db,$request)!==null,'Foreign company without CNI refused');
}
$db->supplier['validation_status']='pending';
try{FebSupplierBeneficiary::resolve($db,$request);throw new RuntimeException('Unapproved company accepted');}catch(DomainException $expected){}
} finally {unlink($file);}
echo "FebSupplierBeneficiaryTest: OK (CI/FR/DE/US companies without CNI, approved supplier required, natural recipients preserved)\n";
