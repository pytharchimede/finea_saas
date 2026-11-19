<?php
declare(strict_types=1);
require_once __DIR__.'/../services/ClientBusinessPolicy.php';
require_once __DIR__.'/../model/FneValidator.php';
function clientCheck(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
function clientReject(callable $action):void {try{$action();}catch(DomainException $e){return;}throw new RuntimeException('Expected invalid client rejection');}
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE client_banamur(id_client INT,nom_client TEXT,fiscal_country TEXT,ncc TEXT,dgi_status INT,dgi_verified_at TEXT,identity_status TEXT,identity_key TEXT,registration_type TEXT,registration_number TEXT,siret TEXT,vat_number TEXT,telephone_client TEXT,email_client TEXT)');
$db->exec('CREATE TABLE chantier_banamur(id INT,client_id INT)');
$db->exec("INSERT INTO client_banamur VALUES(1,'Entreprise France','FR',NULL,0,NULL,'registry_verified','FR:SIRET:35038196800089','SIRET','35038196800089','35038196800089',NULL,'','')");
$db->exec("INSERT INTO client_banamur VALUES(2,'Entreprise CI','CI','AB1234',1,'2026-10-17','registry_verified',NULL,NULL,NULL,NULL,NULL,'0102030405','contact@example.test')");
$db->exec('INSERT INTO chantier_banamur VALUES(11,1),(22,2)');
$db->exec("ALTER TABLE client_banamur ADD client_type TEXT DEFAULT 'morale'");
$foreign=ClientBusinessPolicy::forChantier($db,['chantier_id'=>11,'template'=>'B2B','client_ncc'=>'FALSE','client_company_name'=>'FAKE']);
clientCheck($foreign['template']==='B2F'&&$foreign['client_ncc']===null,'Foreign identity never sent as NCC');
clientCheck($foreign['client_id']===1&&$foreign['client_company_name']==='Entreprise France','Authoritative project client overrides submitted identity');
clientCheck($foreign['client_identity_number']==='35038196800089','Foreign identity snapshot retained');
clientReject(fn()=>ClientBusinessPolicy::forChantier($db,['chantier_id'=>11,'client_id'=>2]));
clientReject(fn()=>ClientBusinessPolicy::forChantier($db,['chantier_id'=>999]));
$local=ClientBusinessPolicy::forChantier($db,['chantier_id'=>22,'template'=>'B2F']);
clientCheck($local['template']==='B2B'&&$local['client_ncc']==='AB1234','Domestic clients retain FNE NCC');
$db->exec("UPDATE client_banamur SET identity_status='documentary_pending' WHERE id_client=1");clientReject(fn()=>ClientBusinessPolicy::forChantier($db,['chantier_id'=>11]));
$db->exec("UPDATE client_banamur SET identity_status=NULL WHERE id_client=1");clientCheck((int)$db->query('SELECT COUNT(*) FROM client_banamur WHERE NOT '.ClientBusinessPolicy::sqlUsable())->fetchColumn()===1,'NULL status appears in pending filters');clientReject(fn()=>ClientBusinessPolicy::forChantier($db,['chantier_id'=>11]));
$db->exec("UPDATE client_banamur SET identity_status='documentary_verified' WHERE id_client=1");clientCheck(ClientBusinessPolicy::forChantier($db,['chantier_id'=>11])['template']==='B2F','Authorized manual foreign identity supported');
// The same invoice with no contacts is permitted only for the foreign template.
$base=['invoiceType'=>'sale','paymentMethod'=>'cash','template'=>'B2F','clientCompanyName'=>'Foreign Ltd','clientPhone'=>'','clientEmail'=>'','pointOfSale'=>'SITE','establishment'=>'SIEGE','items'=>[['description'=>'Service','quantity'=>1,'amount'=>100,'taxes'=>['TVA']]]];
$errors=FneValidator::validateSignInvoicePayload($base)['errors'];
clientCheck(!array_filter($errors,fn($e)=>str_contains($e,'clientPhone')||str_contains($e,'clientEmail')),'B2F allows absent contact details');
$base['template']='B2B';$errors=FneValidator::validateSignInvoicePayload($base)['errors'];
clientCheck((bool)array_filter($errors,fn($e)=>str_contains($e,'clientPhone')),'B2B keeps contact validation');
echo "ClientBusinessPipelineTest: OK (CI, foreign identity, project binding, spoofing, revocation and FNE contacts)\n";
