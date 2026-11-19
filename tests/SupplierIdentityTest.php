<?php
declare(strict_types=1);
require_once __DIR__.'/../services/SupplierIdentity.php';
final class SupplierTestStatement extends PDOStatement {
    public function __construct(private mixed $result=false) {}
    public function execute(?array $params=null): bool {return true;}
    public function fetchColumn(int $column=0): mixed {return $this->result;}
}
final class SupplierTestPdo extends PDO {
    public function __construct(public mixed $duplicate=false) {}
    public function prepare(string $query,array $options=[]): PDOStatement|false {return new SupplierTestStatement($this->duplicate);}
}
function ensure(bool $condition,string $message):void {if(!$condition)throw new RuntimeException($message);}
function rejects(callable $action,string $fragment):void {try{$action();}catch(Throwable $e){ensure(str_contains($e->getMessage(),$fragment),'Unexpected error: '.$e->getMessage());return;}throw new RuntimeException('Expected rejection: '.$fragment);}
$registry=new FneNccRegistry(static fn($ncc)=>[200,json_encode(['status'=>true,'ncc'=>$ncc,'name'=>'SOCIÉTÉ OFFICIELLE','taxRegime'=>'Réel normal'])]);
$pdo=new SupplierTestPdo();
$result=SupplierIdentity::verify($pdo,['ncc'=>' ab-1234 ','nom_commercial'=>'Faux nom','dgi_response_json'=>'falsifié'],'morale',[],$registry);
ensure($result['nom_commercial']==='SOCIÉTÉ OFFICIELLE','Manual name must be replaced');
ensure($result['ncc_identity']==='AB1234','NCC must be normalized');
ensure(json_decode($result['dgi_response_json'],true)['taxRegime']==='Réel normal','Store server-side FNE identity');
ensure(!empty($result['dgi_verified_at']),'Verification timestamp required');
rejects(fn()=>SupplierIdentity::verify($pdo,['is_rne'=>1],'morale',[],$registry),'NCC est obligatoire');
rejects(fn()=>SupplierIdentity::verify(new SupplierTestPdo(42),['ncc'=>'AB1234'],'morale',[],$registry),'#42');
$offline=new FneNccRegistry(static fn($ncc)=>[503,'']);
rejects(fn()=>SupplierIdentity::verify($pdo,['ncc'=>'AB1234'],'morale',[],$offline),'indisponible');
$invalid=new FneNccRegistry(static fn($ncc)=>[200,'{"status":false}']);
rejects(fn()=>SupplierIdentity::verify($pdo,['ncc'=>'AB1234'],'morale',[],$invalid),'pas reconnu');
$mismatch=new FneNccRegistry(static fn($ncc)=>[200,'{"status":true,"ncc":"ZZ9999","name":"Other"}']);
rejects(fn()=>SupplierIdentity::verify($pdo,['ncc'=>'AB1234'],'morale',[],$mismatch),'ne correspond pas');
$individual=SupplierIdentity::verify($pdo,['nom_commercial'=>'Personne','dgi_verified_at'=>'fake'],'physique',[],$offline);
ensure($individual['nom_commercial']==='Personne' && $individual['dgi_verified_at']===null,'Individual does not require FNE');
ensure(in_array('Identité NCC à vérifier via FNE',SupplierIdentity::missing(['supplier_type'=>'morale','ncc'=>'AB1234']),true),'Historical NCC alone must not pass');
echo "SupplierIdentityTest: OK (8 scenarios)\n";

foreach (['company_not_registered'=>'non enregistrée','company_not_found'=>'Aucune entreprise'] as $error=>$message) {
    $missing=new FneNccRegistry(static fn($ncc)=>[404,json_encode(['error'=>$error,'statusCode'=>404])]);
    rejects(fn()=>$missing->lookup('1979106B'),$message);
}
echo "FNE HTTP 404 mapping: OK (not registered / not found)\n";
