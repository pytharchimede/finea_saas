<?php
declare(strict_types=1);
require_once __DIR__.'/../services/SupplierReferenceService.php';
function formCheck(bool $value,string $message):void{if(!$value)throw new RuntimeException($message);}
function formReject(callable $action,string $message):void{try{$action();}catch(DomainException $e){formCheck(str_contains($e->getMessage(),$message),$e->getMessage());return;}throw new RuntimeException('Expected form rejection');}
final class FormMasterRepository extends FournisseurRepository
{
    public array $rows=[];
    public function __construct(){}
    public function getById(int $id):?array{return $this->rows[$id]??null;}
    public function listForSelect(bool $onlyActive=true,bool $onlyValidated=true):array{return array_values($this->rows);}
}
$repo=new FormMasterRepository();
$person=['id_fournisseur'=>1,'code_fournisseur'=>'F001','supplier_display_name'=>'Personne autorisée','supplier_type'=>'physique','person_last_name'=>'Nom','person_first_name'=>'Prénom','identity_document_number'=>'P001234','cni_path'=>'uploads/fournisseurs/cni/document.pdf','email'=>'a@example.test','telephone'=>'0102030405','adresse'=>'Abidjan','document_is_complete'=>true,'validation_status'=>'approved','actif'=>1];
$repo->rows[1]=$person;
$foreign=['id_fournisseur'=>2,'code_fournisseur'=>'F002','supplier_display_name'=>'Entreprise allemande','supplier_type'=>'morale','fiscal_country'=>'DE','identity_method'=>'vies','identity_status'=>'documentary_verified','registration_number'=>'DE123456789','registration_type'=>'VAT','raison_sociale'=>'GmbH','nom_commercial'=>'Entreprise allemande','contact_nom'=>'Gérant','email'=>'contact@example.test','telephone'=>'+49301234567','adresse'=>'Berlin','document_is_complete'=>true,'validation_status'=>'approved','actif'=>1];
$repo->rows[2]=$foreign;
$service=(new ReflectionClass(SupplierReferenceService::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(SupplierReferenceService::class,'suppliers'))->setValue($service,$repo);
$bound=$service->bind(['master_supplier_id'=>2,'name'=>'Usurpé','nom'=>'Usurpé','tax_number'=>'FAUX','ncc'=>'FAUX']);
formCheck($bound['name']==='Entreprise allemande'&&$bound['nom']==='Entreprise allemande','Specialist records use authoritative legal name');
formCheck($bound['tax_number']==='DE123456789'&&$bound['ncc']===null,'Foreign identifiers remain distinct from NCC');
formCheck($service->bind([],['master_supplier_id'=>1])['master_supplier_id']===1,'Editing retains an existing explicit reference');
formReject(fn()=>$service->bind([]),'Sélectionnez');
$repo->rows[1]['personnel_identity_conflict']=true;formReject(fn()=>$service->resolve(1),'personnel');
$repo->rows[2]['validation_status']='pending';formReject(fn()=>$service->resolve(2),'Sélectionnez');
formCheck($service->options()===[],'Selectors omit personnel conflicts and unapproved suppliers');
$repo->rows[2]=$foreign;$repo->rows[2]['identity_status']='documentary_pending';formReject(fn()=>$service->resolve(2),'Sélectionnez');

// Render the shared employee edit form with real PHP, then a failed POST redisplay.
$render=static function(array $existing,array $post,string $method):string{
    $supplierCreateOld=$existing;$_POST=$post;$_SESSION=[];$_SERVER['REQUEST_METHOD']=$method;
    $categoryOptions=$legalFormOptions=[];
    ob_start();require __DIR__.'/../partials/supplier_create_form.php';return (string)ob_get_clean();
};
$html=$render($person,[],'GET');
formCheck(str_contains($html,'value="P001234"'),'Employee edit preserves the document number');
formCheck((bool)preg_match('/value="physique" checked/',$html),'Employee edit preserves physical supplier type');
$html=$render($person,['identity_document_number'=>'P009999'],'POST');
formCheck(str_contains($html,'value="P009999"'),'Validation error preserves the submitted number');
formCheck(str_contains($html,'name="person_last_name" value="Nom"'),'Unposted existing fields retained');
// Execute the real transport INSERT/UPDATE with a SQLite adapter for metadata only.
require_once __DIR__.'/../decaissement/model/FournisseurTransport.php';
final class FormTransportSqlite extends PDO
{
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs):PDOStatement|false
    {
        if($query==='SHOW COLUMNS FROM fournisseurs_transport')$query="SELECT name FROM pragma_table_info('fournisseurs_transport')";
        return parent::query($query);
    }
}
$db=new FormTransportSqlite('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE fournisseurs_transport(id INTEGER PRIMARY KEY AUTOINCREMENT,master_supplier_id INT,nom TEXT,telephone TEXT,whatsapp TEXT,actif INT,carburant_mode TEXT,carburant_valeur REAL,frais_mode TEXT,frais_valeur REAL,solde_mode TEXT,solde_valeur REAL,notes TEXT)');
$repo->rows[2]=$foreign;
$transport=new FournisseurTransport($db,$service);
$id=$transport->create(['master_supplier_id'=>2,'nom'=>'Usurpé','carburant_valeur'=>25,'frais_valeur'=>1000]);
$saved=$transport->getById($id);
formCheck((int)$saved['master_supplier_id']===2&&$saved['nom']==='Entreprise allemande','Transport persists the verified master reference and name');
formCheck((float)$saved['carburant_valeur']===25.0&&(float)$saved['frais_valeur']===1000.0,'Transport conditions retained');
formReject(fn()=>$transport->update($id,['master_supplier_id'=>1]),'personnel');
$transport->update($id,['carburant_valeur'=>30]);
formCheck((float)$transport->getById($id)['carburant_valeur']===30.0,'Transport edit retains the master reference');
formCheck((float)$transport->getById($id)['frais_valeur']===1000.0,'Partial edits preserve existing transport conditions');
$db->exec("INSERT INTO fournisseurs_transport(nom,actif) VALUES('Ancienne fiche non rattachée',1)");
formCheck(count($transport->getAllActifs())===1,'Unlinked historical providers excluded from operational selection');
// The FNE shortcut cannot create an auto-approved identity or fill a foreign NCC.
require_once __DIR__.'/../model/FnePartyLinkManager.php';
$manager=(new ReflectionClass(FnePartyLinkManager::class))->newInstanceWithoutConstructor();
formReject(fn()=>$manager->createAndLink('purchase',1,7),'formulaire commun');
$partyKey=new ReflectionMethod(FnePartyLinkManager::class,'importedPartyKey');
formCheck($partyKey->invoke($manager,['id'=>1,'client_ncc'=>'','client_name'=>'Même nom'])!==$partyKey->invoke($manager,['id'=>2,'client_ncc'=>'','client_name'=>'Même nom']),'Foreign invoices without NCC are never bulk-linked by name');
formCheck($partyKey->invoke($manager,['id'=>3,'client_ncc'=>'---','client_name'=>'Même nom'])==='invoice:3','Empty canonical tax ID never falls back to name matching');
(new ReflectionProperty(FnePartyLinkManager::class,'pdo'))->setValue($manager,$db);
(new ReflectionProperty(FnePartyLinkManager::class,'references'))->setValue($manager,$service);
$db->exec('CREATE TABLE client_banamur(id_client INT,fiscal_country TEXT,ncc TEXT,dgi_status INT,dgi_verified_at TEXT,identity_status TEXT,identity_key TEXT,telephone_client TEXT,email_client TEXT)');
$db->exec("INSERT INTO client_banamur VALUES(5,'DE',NULL,0,NULL,'documentary_verified','DE:VAT:DE123456789','','')");
$db->exec("ALTER TABLE client_banamur ADD client_type TEXT DEFAULT 'morale'");
$enrich=new ReflectionMethod(FnePartyLinkManager::class,'enrichEntity');
$enrich->invoke($manager,'sale',5,['client_phone'=>'+49301234567','client_email'=>'test@example.test','client_ncc'=>'FALSE']);
$client=$db->query('SELECT * FROM client_banamur')->fetch(PDO::FETCH_ASSOC);
formCheck($client['ncc']===null&&$client['telephone_client']==='+49301234567','FNE contact enrichment never injects NCC into foreign client');
$assertEntity=new ReflectionMethod(FnePartyLinkManager::class,'assertEntity');
$assertEntity->invoke($manager,'sale',5);
$db->exec("UPDATE client_banamur SET identity_status='documentary_pending'");formReject(fn()=>$assertEntity->invoke($manager,'sale',5),'Régularisez');
formReject(fn()=>$assertEntity->invoke($manager,'purchase',1),'personnel');
echo "SupplierFormsCoverageTest: OK (employee form redisplay, logistics/transport reference, foreign identity and employee exclusion)\n";
