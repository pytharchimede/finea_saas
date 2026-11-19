<?php
declare(strict_types=1);

require_once __DIR__ . '/../model/AuditTrail.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Sans transaction, l'audit strict doit être refusé.
$refused = false;

try {
    AuditTrail::logStrict('test_audit_strict', [], $pdo);
} catch (LogicException $e) {
    $refused = true;
}

check($refused, 'Audit strict autorisé sans transaction.');

// Une erreur SQL ne doit pas être masquée.
$pdo->beginTransaction();

$failed = false;

try {
    AuditTrail::logStrict(
        'test_audit_strict',
        [
            'module' => 'finance',
            'action' => 'test',
            'user_id' => 1,
            'user_name' => 'Test'
        ],
        $pdo
    );
} catch (PDOException $e) {
    $failed = true;
}

check($failed, 'Erreur SQL masquée par logStrict().');
check($pdo->inTransaction(), 'Transaction fermée prématurément.');

$pdo->rollBack();

echo "AuditTrailStrictTest : OK\n";
