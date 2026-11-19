<?php
declare(strict_types=1);
require_once __DIR__.'/../services/PersonIdentityGuard.php';
function personCheck(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
function personReject(callable $action,string $part):void {try{$action();}catch(DomainException $e){personCheck(str_contains($e->getMessage(),$part),$e->getMessage());return;}throw new RuntimeException('Expected rejection: '.$part);}
final class PersonSqlite extends PDO
{
    public array $locks=[];
    public function prepare(string $query,array $options=[]):PDOStatement|false
    {
        if(str_contains($query,'GET_LOCK')){$this->locks[]='acquire';$query='SELECT 1 WHERE ? IS NOT NULL';}
        if(str_contains($query,'RELEASE_LOCK')){$this->locks[]='release';$query='SELECT 1 WHERE ? IS NOT NULL';}
        if(str_contains($query,'information_schema.COLUMNS'))$query='SELECT 1';
        $query=str_replace('INSERT IGNORE','INSERT OR IGNORE',$query);
        return parent::prepare($query,$options);
    }
}
$db=new PersonSqlite('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->sqliteCreateFunction('REGEXP_REPLACE',fn($v,$pattern,$replacement)=>preg_replace('/'.$pattern.'/',$replacement,$v),3);
$db->exec('CREATE TABLE personnel(id_personnel_soignant INT,num_cni TEXT)');
$db->exec('CREATE TABLE personnel_identity_registry(identity_number TEXT,personnel_id INT,PRIMARY KEY(identity_number,personnel_id))');
$db->exec('CREATE TABLE fournisseur_banamur(id_fournisseur INT,identity_document_number TEXT,supplier_type TEXT,actif INT,validation_status TEXT,validation_note TEXT)');
$db->exec("INSERT INTO personnel VALUES(1,' CI - 001 234 ')");
personCheck(PersonIdentityGuard::normalize(' ci-001 234 ')==='CI001234','Canonical identity retains leading zeroes');
personCheck(PersonIdentityGuard::employeeExists($db,'ci001234'),'Formatted staff number matched');
personReject(fn()=>PersonIdentityGuard::verifySupplier($db,['identity_document_number'=>'CI001234']), 'personnel');
foreach(['','0000','Néant','NA','en cours'] as $bad)personReject(fn()=>PersonIdentityGuard::normalize($bad),'valide');
$db->exec("INSERT INTO fournisseur_banamur VALUES(4,'P000222','physique',1,'approved',NULL)");
personReject(fn()=>PersonIdentityGuard::verifySupplier($db,['identity_document_number'=>'p-000222']),'déjà');
personCheck(PersonIdentityGuard::verifySupplier($db,['identity_document_number'=>'p-000222'],['id_fournisseur'=>4])['identity_document_number']==='P000222','Same supplier edit allowed');
PersonIdentityGuard::locked($db,['P000222'],fn()=>PersonIdentityGuard::recordPersonnel($db,2,['P000222']));
personCheck(PersonIdentityGuard::employeeExists($db,'P000222'),'Historical staff identity retained');
personCheck((int)$db->query('SELECT actif FROM fournisseur_banamur WHERE id_fournisseur=4')->fetchColumn()===0,'Supplier suspended when identity enters RH');
personReject(fn()=>PersonIdentityGuard::verifySupplier($db,['identity_document_number'=>'P000222'],['id_fournisseur'=>4]),'personnel');
$called=false;$result=PersonIdentityGuard::supplierWrite($db,['supplier_type'=>'physique','identity_document_number'=>'CI001234'],[],function()use($db,&$called){PersonIdentityGuard::verifySupplier($db,['identity_document_number'=>'CI001234']);$called=true;return ['ok'=>true];});
personCheck(!$result['ok']&&!$called,'Exception cannot persist an employee as supplier');
personCheck(count(array_filter($db->locks,fn($v)=>$v==='acquire'))===count(array_filter($db->locks,fn($v)=>$v==='release')),'Locks released after rejection');
$root=sys_get_temp_dir().'/finea-person-'.bin2hex(random_bytes(4));mkdir($root);file_put_contents($root.'/piece.pdf','test identity file');
try {
    personReject(fn()=>PersonIdentityGuard::requirePersonnelIdentity(['cni_number'=>'P123456']), 'jointe');
    $old=PersonIdentityGuard::requirePersonnelIdentity(['cni_number'=>'P123456','identity_document_path'=>'piece.pdf'],[],$root);
    personCheck($old['cni_number']==='P123456','RH accepts number and existing file');
    personReject(fn()=>PersonIdentityGuard::requirePersonnelIdentity(['cni_number'=>'P999999'],$old,$root),'changé');
    personReject(fn()=>PersonIdentityGuard::requirePersonnelIdentity(['cni_number'=>'P123456','identity_document_path'=>'../missing.pdf'],[],$root),'jointe');
} finally {unlink($root.'/piece.pdf');rmdir($root);}
echo "PersonIdentityGuardTest: OK (canonicalisation, personnel, history, suspension, duplicate, locks and mandatory RH file)\n";
