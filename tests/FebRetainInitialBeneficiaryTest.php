<?php
declare(strict_types=1);
require_once __DIR__.'/../services/FebPriceChallengeService.php';
require_once __DIR__.'/../services/FebWorkflowGuard.php';
require_once __DIR__.'/../services/FebBeneficiarySyncService.php';

// Exercise the real price-retention transaction without running constructor migrations.
final class RetainInitialPDO extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $query = str_replace(' FOR UPDATE', '', $query);
        if (str_contains($query, 'UPDATE feb_price_challenge_followups f')) {
            $query = 'UPDATE feb_price_challenge_followups SET status="closed",closed_at=NOW()
                WHERE challenge_id IN (SELECT id FROM feb_price_challenges WHERE feb_request_id=:request)
                AND status="open"';
        }
        return parent::prepare($query, $options);
    }
}
function retainCheck(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}
$db = new RetainInitialPDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->sqliteCreateFunction('NOW', static fn() => '2026-10-26 16:00:00');
$db->sqliteCreateFunction('CONCAT', static fn(...$parts) => implode('', $parts));
$db->exec('CREATE TABLE employee_legal_requests(id INT PRIMARY KEY,request_type TEXT,payload_json TEXT,requester_user_id INT,updated_at TEXT);
    CREATE TABLE user_devis_banamur(id INT,personnel_id INT,prenom TEXT,nom TEXT);
    CREATE TABLE feb_price_challenges(id INT,feb_request_id INT,line_index INT,original_unit_price REAL,original_supplier_id INT,original_supplier_name TEXT,original_justification_path TEXT,status TEXT,retained_at TEXT,retained_by INT,archived_at TEXT,archive_reason TEXT);
    CREATE TABLE feb_quote_lines(feb_request_id INT,line_index INT,unit_price REAL,supplier_id INT,supplier_name_snapshot TEXT);
    CREATE TABLE feb_price_challenge_followups(challenge_id INT,status TEXT,closed_at TEXT);
    CREATE TABLE fournisseur_banamur(id_fournisseur INT,nom_commercial TEXT,raison_sociale TEXT,contact_nom TEXT,telephone TEXT)');
$db->exec("INSERT INTO user_devis_banamur VALUES(7,9,'Demandeur','Initial');
    INSERT INTO fournisseur_banamur VALUES(122,'ABOKPOE KOFFI DENIS','SARL','Ets vida','0768780775');
    INSERT INTO feb_price_challenges VALUES(1,13589,0,30000,41,'Djevigan Tetevi','preuve-initiale.pdf','pending',NULL,NULL,NULL,NULL);
    INSERT INTO feb_quote_lines VALUES(13589,0,45000,41,'Djevigan Tetevi');
    INSERT INTO feb_price_challenge_followups VALUES(1,'open',NULL)");
$service = (new ReflectionClass(FebPriceChallengeService::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(FebPriceChallengeService::class, 'pdo'))->setValue($service, $db);
$base = ['global_fournisseur_id'=>41,'fournisseur_id'=>41,'montant'=>45000,
    'besoins'=>[['quantite'=>1,'fournisseur'=>['id'=>41,'nom'=>'Djevigan Tetevi','prix_unitaire'=>45000]]]];
$save = static function(array $payload) use ($db): void {
    $db->prepare('INSERT OR REPLACE INTO employee_legal_requests VALUES(13589,"feb",?,7,NULL)')
        ->execute([json_encode($payload)]);
};
$read = static fn() => json_decode((string)$db->query('SELECT payload_json FROM employee_legal_requests WHERE id=13589')->fetchColumn(), true);
$supplierDraft = ['beneficiary_mode'=>'supplier','beneficiary_choice'=>'supplier','fournisseur_id'=>122,
    'fournisseur_nom'=>'ABOKPOE KOFFI DENIS','personnel_id'=>0,'beneficiaire_fiche'=>'ABOKPOE KOFFI DENIS',
    'tel_beneficiaire_fiche'=>'0768780775','active'=>true,'chantier_id'=>5,'ligne_debourse_id'=>7];
foreach ([
    $supplierDraft,
    ['beneficiary_mode'=>'personnel','personnel_id'=>88,'fournisseur_id'=>122,'beneficiaire_fiche'=>'Autre employé'],
    ['beneficiary_mode'=>'external','fournisseur_id'=>122,'beneficiaire_fiche'=>'Tiers','tel_beneficiaire_fiche'=>'0102030405'],
    // An explicit empty selection must remain invalid, never revive supplier #41.
    ['beneficiary_mode'=>'supplier','fournisseur_id'=>0],
] as $draft) {
    $save($base + ['provisional_decaissement'=>$draft]);
    $db->beginTransaction();
    $service->retainAllInitial(13589, 2);
    retainCheck($db->inTransaction(), 'Price retention committed the approval transaction');
    $result = $read();
    foreach ($draft as $key=>$value) retainCheck($result['provisional_decaissement'][$key] === $value, 'Beneficiary field overwritten: '.$key);
    retainCheck($result['montant'] === 30000 && $result['provisional_decaissement']['montant_fiche'] === 30000, 'Initial amount not restored');
    retainCheck($result['besoins'][0]['fournisseur']['id'] === 41 && $result['besoins'][0]['fournisseur']['prix_unitaire'] === 30000, 'Initial supplier/price trace lost');
    if ($draft === $supplierDraft) {
        $selected = FebBeneficiarySyncService::selected($db, $result);
        retainCheck($selected['fournisseur_id'] === 122 && $selected['name'] === 'ABOKPOE KOFFI DENIS', 'Displayed supplier changed');
        retainCheck(FebWorkflowGuard::supplierId([], $result['provisional_decaissement'], $result) === 122, 'Approval resolver revived historical supplier');
    }
    $db->rollBack();
    retainCheck($read() === $base + ['provisional_decaissement'=>$draft], 'Approval rollback lost original payload');
}
// Preserve the legacy default only when no explicit beneficiary has been saved.
$save($base);
$service->retainAllInitial(13589, 2);
$legacy = $read()['provisional_decaissement'];
retainCheck($legacy['beneficiary_mode'] === 'personnel' && $legacy['personnel_id'] === 9 && $legacy['fournisseur_id'] === 41, 'Legacy default changed');
retainCheck($db->query('SELECT status FROM feb_price_challenge_followups')->fetchColumn() === 'closed', 'Followups left open');
echo "FebRetainInitialBeneficiaryTest: OK (FEB 13589, supplier/personnel/external, invalid selection, initial prices, history, rollback, legacy default)\n";
