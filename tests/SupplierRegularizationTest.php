<?php
declare(strict_types=1);
require_once __DIR__.'/../model/DisbursementRegularizationManager.php';

final class RegularizationSchemaStatement extends PDOStatement
{
    public function __construct(private array $rows) {}
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->rows; }
}
final class RegularizationSchemaPdo extends PDO
{
    public array $executed = [];
    public array $columns = ['id','num_fiche','accounting_validated_at'];
    public function __construct() {}
    public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false
    {
        if (str_contains($query, 'SHOW INDEX')) return new RegularizationSchemaStatement([['Key_name'=>'idx_fiche_feb_request']]);
        return new RegularizationSchemaStatement(str_contains($query, 'fiche_decaissement') ? ['feb_request_id'] : $this->columns);
    }
    public function exec(string $statement): int|false
    {
        $this->executed[] = $statement;
        preg_match_all('/ADD COLUMN (\w+)/', $statement, $matches);
        $this->columns = array_merge($this->columns, $matches[1]);
        return 0;
    }
}
function checkRegularization(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}
$pdo = new RegularizationSchemaPdo();
$manager = new DisbursementRegularizationManager($pdo, false);
foreach (['cancelled_at','access_blocked_at','beneficiary_user_id','bypassed_without_proof_at','bypassed_without_proof_by','bypassed_without_proof_by_name','bypass_reason'] as $column) {
    checkRegularization(in_array($column, $pdo->columns, true), 'Missing automatic migration: '.$column);
}
$count = count($pdo->executed);
new DisbursementRegularizationManager($pdo, false);
checkRegularization(count($pdo->executed) === $count, 'Schema repeated on same connection');
$other = new RegularizationSchemaPdo();
new DisbursementRegularizationManager($other, false);
checkRegularization(count($other->executed) > 0, 'Schema skipped on another connection');
$backfill = $pdo->executed[array_key_last($pdo->executed)];
checkRegularization(str_contains($backfill, 'CONVERT(u.personnel_id USING utf8mb4) COLLATE utf8mb4_unicode_ci=CONVERT(f.personnel_id USING utf8mb4) COLLATE utf8mb4_unicode_ci'), 'Mixed personnel collations are not handled');
$regularizationSources='';
foreach([
    __DIR__.'/../model/DisbursementRegularizationManager.php',
    __DIR__.'/../services/SupplierDisbursementUnblockService.php',
    __DIR__.'/../fournisseur/regularisations_fournisseurs.php',
    __DIR__.'/../model/FournisseurMaster.php',
    __DIR__.'/../repositories/FournisseurRepository.php',
] as $source)$regularizationSources.=file_get_contents($source);
checkRegularization(!preg_match('/num_fiche\s+COLLATE\s+utf8mb4_(?:general|unicode)_ci\s*=/', $regularizationSources), 'A collation-dependent fiche comparison remains');
checkRegularization(str_contains($regularizationSources,'BINARY f.num_fiche=BINARY r.num_fiche'), 'Binary collation fallback missing');
try {
    $manager->attachPaymentProof('FEB-TEST', 1, 'Test', [], 'fne');
    throw new LogicException('Missing invoice upload was accepted');
} catch (RuntimeException $e) {
    checkRegularization($e->getMessage() === 'Sélectionnez un justificatif.', 'Unexpected validation error');
}
echo "SupplierRegularizationTest: OK (automatic schema, connection isolation, collation guard, missing upload)\n";
