<?php
declare(strict_types=1);
require_once __DIR__.'/FrenchSupplierIdentityTest.php';
require_once __DIR__.'/../repositories/FournisseurRepository.php';
class AccessManager {
    public static array $permissions=[];
    public static function hasPermission(string $key): bool {return in_array($key,self::$permissions,true);}
}
$_SESSION=['user_id'=>7,'role_norm'=>'employe','supplier_exception_csrf'=>'token'];
$input=['identity_method'=>'exception','nom_commercial'=>'Partenaire occasionnel','exception_reason'=>'Autorisation comptable pour prestation ponctuelle','_supplier_exception_csrf'=>'token'];
AccessManager::$permissions=['acces_fournisseur'];
rejects(fn()=>SupplierIdentity::verify($pdo,$input,'morale',[],$offline),'réservés');
AccessManager::$permissions=['acces_direction'];
rejects(fn()=>SupplierIdentity::verify($pdo,$input,'morale',[],$offline),'réservés');
foreach (['dg','directeur general','directeur général'] as $role) {$_SESSION['role_norm']=$role;ensure(SupplierExceptionPolicy::allowed(),'DG exact role allowed');}
$_SESSION['role_norm']='directeur general adjoint';ensure(!SupplierExceptionPolicy::allowed(),'DGA not implicitly DG');
$_SESSION['role_norm']='employe';
foreach (['acces_compta','acces_admin'] as $permission) {
    AccessManager::$permissions=[$permission];
    $exception=SupplierIdentity::verify($pdo,$input,'morale',[],$offline);
    ensure($exception['identity_status']==='exception_approved' && $exception['identity_checked_by']===7,'Authorized and attributable');
    ensure(empty($exception['ncc']) && empty($exception['dgi_verified_at']) && empty($exception['registry_verified_at']),'Exception never marked tax-verified');
}
rejects(fn()=>SupplierIdentity::verify($pdo,array_replace($input,['exception_reason'=>'court']),'morale',[],$offline),'10 à 500');
rejects(fn()=>SupplierIdentity::verify($pdo,array_replace($input,['_supplier_exception_csrf'=>'forged']),'morale',[],$offline),'Session expirée');
rejects(fn()=>SupplierExceptionPolicy::requireAllowed(8),'réservés');
ensure(SupplierIdentity::missing($exception)===[],'No normal completeness requirements for authorized exception');
ensure(SupplierIdentity::summary($exception)['exception'] && !SupplierIdentity::summary($exception)['verified'],'Dashboard separates waiver from verification');
$repo=(new ReflectionClass(FournisseurRepository::class))->newInstanceWithoutConstructor();
$normalize=new ReflectionMethod($repo,'normalizeSupplierPayload');
$validate=new ReflectionMethod($repo,'validateSupplierPayload');
$completeness=new ReflectionMethod($repo,'buildDocumentCompletenessSummary');
$normalized=$normalize->invoke($repo,$exception,'morale');
ensure($validate->invoke($repo,$normalized,[],'morale',$exception)===null,'Name and reason sufficient without contact/logo/documents');
$summary=$completeness->invoke($repo,$exception+['supplier_type'=>'morale']);
ensure($summary['is_complete']===true && str_contains($summary['label'],'Dérogation'),'Exception has a distinct completeness label');
$personException=array_replace($exception,['supplier_type'=>'physique']);
ensure(SupplierIdentity::missing($personException)!==[],'Physical exception still requires document number and file');
ensure($validate->invoke($repo,$normalized,[],'physique',$personException)!==null,'Server rejects physical exception without identity');
$personException['identity_document_number']='P001234';$personException['cni_path']='uploads/fournisseurs/cni/test.pdf';
ensure(SupplierIdentity::missing($personException)===[],'Physical exception complete only with number and file');
AccessManager::$permissions=[];
rejects(fn()=>SupplierIdentity::verify($pdo,['identity_method'=>'fne','ncc'=>'AB1234'],'morale',$exception,$registry),'réservés');
$frSummary=SupplierIdentity::summary(['fiscal_country'=>'FR','identity_method'=>'fr','siret'=>'35038196800089','registry_verified_at'=>'2026-10-17']);
ensure($frSummary['label']==='SIRET' && $frSummary['verified'],'French record does not require FNE in dashboard');
$euSummary=SupplierIdentity::summary(['fiscal_country'=>'DE','identity_method'=>'vies','vat_number'=>'DE123456789','vat_status'=>'valid','identity_status'=>'documentary_pending']);
ensure($euSummary['label']==='TVA' && !$euSummary['verified'],'Valid VAT alone not counted as approved identity');
$usSummary=SupplierIdentity::summary(['fiscal_country'=>'US','identity_method'=>'sec','registration_number'=>'0001652044','identity_status'=>'registry_verified']);
ensure($usSummary['label']==='CIK' && $usSummary['verified'],'SEC label and verification');
$aliased=SupplierIdentity::verify($pdo,['identity_search'=>'ab-1234','fiscal_country'=>'CI','ncc'=>'WRONG'],'morale',[],$registry);
ensure($aliased['ncc']==='AB1234','Primary search field is authoritative for server verification');
echo "SupplierExceptionPolicyTest: OK (roles, CSRF, spoofing, completeness and dashboard)\n";
