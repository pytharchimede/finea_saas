<?php
declare(strict_types=1);
require_once __DIR__ . '/SupplierIdentityTest.php';

function frenchResponse(string $siret = '35038196800089', string $state = 'A'): string
{
    return json_encode(['results'=>[['siren'=>'350381968', 'nom_raison_sociale'=>'ADOBE SYSTEMS FRANCE SAS',
        'etat_administratif'=>'A', 'siege'=>['siret'=>'35038196800089', 'etat_administratif'=>'A', 'adresse'=>'94 RUE LAURISTON 75116 PARIS', 'libelle_commune'=>'PARIS'],
        'matching_etablissements'=>[['siret'=>$siret, 'etat_administratif'=>$state, 'adresse'=>'AUTRE ADRESSE', 'libelle_commune'=>'PARIS']]]]], JSON_THROW_ON_ERROR);
}
$fr = new FrenchCompanyRegistry(static fn($id)=>[200, frenchResponse()]);
$identity = SupplierIdentity::verify($pdo, ['fiscal_country'=>'FR', 'siret'=>'350 381 968 00089',
    'ncc'=>'FAKE', 'nom_commercial'=>'Nom falsifié', 'siren'=>'000000000',
    'registry_response_json'=>'fake', 'vat_number'=>'FR81350381968'], 'morale', [], $offline, $fr);
ensure($identity['nom_commercial'] === 'ADOBE SYSTEMS FRANCE SAS', 'Server registry name required');
ensure($identity['siret'] === '35038196800089' && $identity['siren'] === '350381968', 'Separate canonical identifiers');
ensure($identity['ncc'] === '' && $identity['dgi_verified_at'] === null, 'France must not use FNE');
ensure($identity['adresse'] === '94 RUE LAURISTON 75116 PARIS' && $identity['pays'] === 'FR', 'Use official address');
ensure(!empty($identity['registry_verified_at']) && $identity['registry_response_json'] !== 'fake', 'Server verification proof');
ensure($fr->lookup('350381968')['siret'] === '35038196800089', 'SIREN selects headquarters');
$branch = new FrenchCompanyRegistry(static fn($id)=>[200, frenchResponse('35038196800097')]);
ensure($branch->lookup('35038196800097')['address'] === 'AUTRE ADRESSE', 'SIRET selects requested establishment, not headquarters');
$closed = new FrenchCompanyRegistry(static fn($id)=>[200, frenchResponse('35038196800071', 'F')]);
rejects(fn()=>$closed->lookup('35038196800071'), 'fermé');
rejects(fn()=>$fr->lookup('35038196899999'), 'ne figure pas');
rejects(fn()=>$fr->lookup('350381968X'), '9 chiffres');
rejects(fn()=>SupplierIdentity::verify(new SupplierTestPdo(42), ['fiscal_country'=>'FR', 'siret'=>'35038196800089'], 'morale', [], $offline, $fr), '#42');
$unavailable = new FrenchCompanyRegistry(static fn($id)=>[503, '']);
rejects(fn()=>$unavailable->lookup('350381968'), 'indisponible');
$limited = new FrenchCompanyRegistry(static fn($id)=>[429, '']);
rejects(fn()=>$limited->lookup('350381968'), 'Patientez');
$empty = new FrenchCompanyRegistry(static fn($id)=>[200, '{"results":[]}']);
rejects(fn()=>$empty->lookup('350381968'), 'introuvable');
$wrong = new FrenchCompanyRegistry(static fn($id)=>[200, str_replace('350381968', '123456789', frenchResponse())]);
rejects(fn()=>$wrong->lookup('350381968'), 'autre entreprise');
rejects(fn()=>SupplierIdentity::verify($pdo, ['fiscal_country'=>'FR', 'siret'=>'350381968', 'vat_number'=>'FR00123456789'], 'morale', [], $offline, $fr), 'TVA ne correspond pas');
$back = SupplierIdentity::verify($pdo, ['fiscal_country'=>'CI', 'ncc'=>'AB1234'], 'morale', $identity, $registry, $unavailable);
ensure($back['siret'] === null && $back['registry_verified_at'] === null && $back['ncc_identity'] === 'AB1234', 'Country switch clears stale proof');
ensure(!in_array('Identité NCC à vérifier via FNE', SupplierIdentity::missing($identity), true), 'French suppliers do not require NCC');
echo "FrenchSupplierIdentityTest: OK\n";
