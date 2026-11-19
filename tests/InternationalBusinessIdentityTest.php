<?php
declare(strict_types=1);
require_once __DIR__.'/FrenchSupplierIdentityTest.php';
require_once __DIR__.'/../services/ClientIdentityService.php';
$manual=['fiscal_country'=>'US','identity_method'=>'manual','registration_type'=>'EIN','registration_number'=>'12-3456789','nom_client'=>'ACME LLC','localisation_client'=>'10 Main Street','identity_evidence'=>'W9 signé, dossier ACME'];
$resolver=new InternationalBusinessIdentity(static fn()=>[503,'']);
$pending=$resolver->resolve($manual+['documentary_approve'=>1],[],false,7);
ensure($pending['identity_status']==='documentary_pending','Posted approval cannot grant rights');
rejects(fn()=>$resolver->resolve(array_replace($manual,['fiscal_country'=>'ZZ'])),'code pays');
$approved=$resolver->resolve($manual+['documentary_approve'=>1],[],true,7);
ensure($approved['identity_status']==='documentary_verified' && $approved['identity_checked_by']===7,'Authorized documentary approval');
ensure($approved['registration_number']==='123456789','Normalize EIN');
ensure($resolver->resolve($manual,$approved)['identity_status']==='documentary_verified','Unchanged approved proof preserved');
ensure($resolver->resolve(array_replace($manual,['nom_client'=>'OTHER LLC']),$approved)['identity_status']==='documentary_pending','Changed identity revokes approval');
rejects(fn()=>$resolver->resolve(array_replace($manual,['registration_number'=>'x'])),'9 chiffres');
rejects(fn()=>$resolver->resolve(array_replace($manual,['identity_evidence'=>'','documentary_approve'=>1]),[],true,7),'justificatif');
$vies=new InternationalBusinessIdentity(static fn($kind,$args)=>[200,json_encode(['countryCode'=>$args['countryCode'],'vatNumber'=>$args['vatNumber'],'valid'=>true,'name'=>'---','address'=>'---'])]);
$de=['fiscal_country'=>'DE','identity_method'=>'vies','vat_number'=>'DE123456789','nom_client'=>'Entreprise allemande','localisation_client'=>'Berlin'];
$vat=$vies->resolve($de);
ensure($vat['vat_status']==='valid' && $vat['identity_status']==='documentary_pending','VAT alone does not approve legal identity');
$invalid=new InternationalBusinessIdentity(static fn()=>[200,'{"countryCode":"DE","vatNumber":"123456789","valid":false}']);
rejects(fn()=>$invalid->resolve($de),'non confirmée');
$sec=new InternationalBusinessIdentity(static fn()=>[200,'{"cik":"1652044","name":"ALPHABET INC.","addresses":{"business":{"street1":"1600 AMPHITHEATRE PARKWAY","city":"MOUNTAIN VIEW","stateOrCountry":"CA","zipCode":"94043"}}}']);
$us=$sec->resolve(['fiscal_country'=>'US','identity_method'=>'sec','registration_number'=>'1652044']);
ensure($us['identity_status']==='registry_verified' && $us['name']==='ALPHABET INC.' && $us['registration_number']==='0001652044','SEC exact CIK identity');
rejects(fn()=>$sec->resolve(['fiscal_country'=>'US','identity_method'=>'sec','registration_number'=>'1']),'incohérente');
rejects(fn()=>$resolver->resolve($de),'indisponible');

// Persist the real client service SQL using SQLite; only the MySQL row-lock syntax is removed.
final class IdentitySqlite extends PDO {
    public function prepare(string $query,array $options=[]): PDOStatement|false {return parent::prepare(str_replace(' FOR UPDATE','',$query),$options);}
}
$db=new IdentitySqlite('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE client_identity_schema(id INTEGER PRIMARY KEY,version INT)');$db->exec('INSERT INTO client_identity_schema VALUES(1,5)');
$columns=['id_client INTEGER PRIMARY KEY AUTOINCREMENT','code_client TEXT UNIQUE','nom_client TEXT','telephone_client TEXT','email_client TEXT','localisation_client TEXT','commune_client TEXT','bp_client TEXT','pays_client TEXT','rccm TEXT','rne TEXT','activity_description TEXT','vat_liability_status TEXT','vat_last_period TEXT','activity_start_date TEXT','ncc TEXT','tax_regime TEXT','fiscal_activity_status TEXT','fiscal_activity_label TEXT','dgi_status INTEGER','dgi_registered_at TEXT','dgi_first_registration_at TEXT','dgi_verified_at TEXT','dgi_response_json TEXT','fiscal_country TEXT','siret TEXT','siren TEXT','vat_number TEXT','registry_verified_at TEXT','registry_response_json TEXT','date_creat_client INTEGER','logo_client TEXT'];
foreach (InternationalBusinessIdentity::COLUMNS as $k=>$def) $columns[]=$k.' TEXT'.($k==='identity_key'?' UNIQUE':'');
$columns[]='ncc_identity TEXT GENERATED ALWAYS AS (ncc) STORED';
$db->exec('CREATE TABLE client_banamur('.implode(',',$columns).')');
$db->exec('CREATE TABLE client_identity_audit(client_id INTEGER,user_id INTEGER,old_identity TEXT,new_identity TEXT)');
$db->exec("ALTER TABLE client_banamur ADD client_type TEXT DEFAULT 'morale'");
$service=new ClientIdentityService($db,$registry,$resolver);
$id=$service->save($manual,null,7);
ensure($service->find($id)['identity_status']==='documentary_pending','Pending foreign client persisted');
$service->save($manual+['documentary_approve'=>1],$id,7,true);
ensure($service->find($id)['identity_status']==='documentary_verified','Client approval persisted');
$service->save(array_replace($manual,['nom_client'=>'Changed ACME']),$id,8,false);
ensure($service->find($id)['identity_status']==='documentary_pending','Editing client revokes documentary approval');
rejects(fn()=>$service->save($manual,null,7),'déjà');
ensure((int)$db->query('SELECT COUNT(*) FROM client_identity_audit')->fetchColumn()===3,'Client writes audited');
$ci=$service->save(['ncc'=>'AB1234','nom_client'=>'Fake'],null,7);
ensure($service->find($ci)['nom_client']==='SOCIÉTÉ OFFICIELLE' && $service->find($ci)['fiscal_country']==='CI','CI route still FNE');
echo "InternationalBusinessIdentityTest: OK (adapters, permissions, revocation, client SQL and audit)\n";

