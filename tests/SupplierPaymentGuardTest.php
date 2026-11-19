<?php
declare(strict_types=1);
require_once __DIR__.'/../services/SupplierPaymentGuard.php';
function strictCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
final class SupplierGuardSqlite extends PDO{
    public array $reads=[];
    public function prepare(string $query,array $options=[]):PDOStatement|false{$this->reads[]=$query;return parent::prepare(str_replace(' FOR UPDATE','',$query),$options);}
}
$db=new SupplierGuardSqlite('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->sqliteCreateFunction('REGEXP_REPLACE',static fn($v,$pattern,$replacement)=>preg_replace('/'.$pattern.'/',$replacement,(string)$v),3);
$db->exec('CREATE TABLE personnel(id_personnel_soignant INTEGER,num_cni TEXT)');$db->exec('CREATE TABLE personnel_identity_registry(identity_number TEXT)');
$fields=['id_fournisseur INTEGER PRIMARY KEY','supplier_type TEXT','fiscal_country TEXT','actif INT','validation_status TEXT','archived_at TEXT','raison_sociale TEXT','nom_commercial TEXT','contact_nom TEXT','email TEXT','telephone TEXT','adresse TEXT','ncc TEXT','dgi_verified_at TEXT','identity_status TEXT','identity_method TEXT','identity_key TEXT','identity_evidence TEXT','identity_checked_by INT','siret TEXT','registry_verified_at TEXT','identity_document_number TEXT','person_first_name TEXT','person_last_name TEXT','cni_path TEXT','photo_path TEXT','logo_path TEXT'];
$db->exec('CREATE TABLE fournisseur_banamur('.implode(',',$fields).')');
$root=sys_get_temp_dir().'/finea-supplier-guard-'.bin2hex(random_bytes(4));mkdir($root,0700);file_put_contents($root.'/proof.pdf','proof');
$row=['id_fournisseur'=>1,'supplier_type'=>'morale','fiscal_country'=>'CI','actif'=>1,'validation_status'=>'approved','raison_sociale'=>'SARL','nom_commercial'=>'Société','contact_nom'=>'Représentant','email'=>'test@example.com','telephone'=>'0102030405','adresse'=>'Adresse officielle','ncc'=>'AB1234','dgi_verified_at'=>'2026-10-18','logo_path'=>'proof.pdf'];
function rejected(PDO $db,array $row,string $root,string $hint):void{strictCheck((bool)SupplierPaymentGuard::missing($db,$row,$root),$hint);}
try{
 strictCheck(SupplierPaymentGuard::missing($db,$row,$root)===[],'CI valid rejected');
 foreach([['dgi_verified_at'=>null],['actif'=>0],['validation_status'=>'pending'],['archived_at'=>'2026-10-18'],['logo_path'=>'missing']] as $change)rejected($db,array_replace($row,$change),$root,'Incomplete company accepted');
 $foreign=array_replace($row,['fiscal_country'=>'DE','ncc'=>null,'dgi_verified_at'=>null,'identity_method'=>'manual','identity_status'=>'documentary_verified','identity_key'=>'DE:HRB:24498']);
 strictCheck(SupplierPaymentGuard::missing($db,$foreign,$root)===[],'Foreign supplier requires NCC');rejected($db,array_replace($foreign,['identity_status'=>'documentary_pending']),$root,'Pending foreign identity accepted');
 $person=array_replace($row,['supplier_type'=>'physique','identity_document_number'=>'CNI-123','person_first_name'=>'Test','person_last_name'=>'Prestataire','cni_path'=>'proof.pdf','photo_path'=>'proof.pdf']);
 strictCheck(SupplierPaymentGuard::missing($db,$person,$root)===[],'Valid individual rejected');
 rejected($db,array_replace($person,['identity_document_number'=>'']),$root,'Missing ID accepted');rejected($db,array_replace($person,['cni_path'=>'absent.pdf']),$root,'Missing document accepted');
 $db->exec("INSERT INTO personnel VALUES(7,'CNI 123')");rejected($db,$person,$root,'Current employee accepted');$db->exec('DELETE FROM personnel');
 $db->exec("INSERT INTO personnel_identity_registry VALUES('CNI123')");rejected($db,$person,$root,'Historical employee accepted');$db->exec('DELETE FROM personnel_identity_registry');
 $columns=array_keys($row);$q=$db->prepare('INSERT INTO fournisseur_banamur('.implode(',',$columns).') VALUES('.implode(',',array_fill(0,count($columns),'?')).')');$q->execute(array_values($row));
 $duplicate=array_replace($row,['id_fournisseur'=>2,'ncc'=>'AB-1234']);$q->execute(array_values($duplicate));
 rejected($db,$row,$root,'Duplicate tax identity accepted');$db->exec('DELETE FROM fournisseur_banamur WHERE id_fournisseur=2');
 $db->beginTransaction();SupplierPaymentGuard::assertFiche($db,['fournisseur_id'=>1,'decaisse'=>0],$root);strictCheck((bool)array_filter($db->reads,static fn($sql)=>str_contains($sql,'fournisseur_banamur WHERE id_fournisseur=? LIMIT 1 FOR UPDATE')),'Supplier not locked during payment');$db->rollBack();
 $db->exec("UPDATE fournisseur_banamur SET validation_status='pending'");
 try{SupplierPaymentGuard::assertFiche($db,['fournisseur_id'=>1,'decaisse'=>0],$root);throw new LogicException('Invalid supplier payable');}catch(DomainException $expected){}
 SupplierPaymentGuard::assertFiche($db,['fournisseur_id'=>1,'decaisse'=>1],$root);
 try{SupplierPaymentGuard::assertFiche($db,['fournisseur_id'=>0,'code_autorisation_feb'=>'BCI-TEST'],$root);throw new LogicException('Unlinked supplier FEB paid');}catch(DomainException $expected){}
 try{SupplierPaymentGuard::assertFiche($db,['fournisseur_id'=>0],$root);throw new LogicException('Unidentified beneficiary paid');}catch(DomainException $expected){}
 $db->exec("INSERT INTO personnel VALUES(7,'STAFF777')");SupplierPaymentGuard::assertFiche($db,['personnel_id'=>7,'fournisseur_id'=>0],$root);
 echo "SupplierPaymentGuardTest: OK (companies, foreigners, individual ID/file, employees/history, current row lock, idempotence and unlinked FEB)\n";
}finally{unlink($root.'/proof.pdf');rmdir($root);}
