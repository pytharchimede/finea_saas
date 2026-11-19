<?php
declare(strict_types=1);
require_once __DIR__.'/SupplierRegularizationTest.php';
require_once __DIR__.'/../services/SupplierDisbursementUnblockService.php';

final class SupplierValidationStatement extends PDOStatement
{
    public function execute(?array $params = null): bool { return true; }
    public function fetchColumn(int $column = 0): mixed { return false; }
}
final class SupplierValidationPdo extends PDO
{
    public bool $transaction = false;
    public bool $rolledBack = false;
    public function __construct() {}
    public function beginTransaction(): bool { $this->transaction = true; return true; }
    public function inTransaction(): bool { return $this->transaction; }
    public function rollBack(): bool { $this->rolledBack = true; $this->transaction = false; return true; }
    public function prepare(string $query, array $options = []): PDOStatement|false { return new SupplierValidationStatement(); }
}
$pdo = new SupplierValidationPdo();
// Isolate business validation from automatic DDL and audit maintenance.
$managerReflection = new ReflectionClass(DisbursementRegularizationManager::class);
$cache = $managerReflection->getProperty('schemaEnsured')->getValue();
$cache[$pdo] = true;
$reflection = new ReflectionClass(SupplierDisbursementUnblockService::class);
$service = $reflection->newInstanceWithoutConstructor();
$reflection->getProperty('pdo')->setValue($service, $pdo);
try {
    $service->regularizeSupplier('FEB-TEST', 1, 'Test', 'Motif de validation');
    throw new LogicException('Missing supplier accepted');
} catch (DomainException $e) {
    checkRegularization($pdo->rolledBack && !$pdo->inTransaction(), 'Failed regularization must roll back');
}
$pdo->rolledBack = false;
try {
    $service->regularizeSupplier('FEB-TEST', 1, 'Test', '');
    throw new LogicException('Missing reason accepted');
} catch (DomainException $e) {
    checkRegularization(!$pdo->inTransaction() && !$pdo->rolledBack, 'Invalid reason must fail before transaction');
}
echo "SupplierRegularizationValidationTest: OK (missing supplier rollback, mandatory reason)\n";
