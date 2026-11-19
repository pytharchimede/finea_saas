<?php
declare(strict_types=1);

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../controllers/CommercialProjectFinanceController.php';

function assertCommercial(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = Database::getConnection();
$controller = new CommercialProjectFinanceController($pdo);
foreach (['client_purchase_orders', 'chantier_client_purchase_orders', 'devis_archives', 'treasury_opening_balances', 'treasury_loans'] as $table) {
    $statement = $pdo->prepare(
        'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table_name'
    );
    $statement->execute(['table_name' => $table]);
    assertCommercial($statement->fetchColumn() === 'InnoDB', "$table doit être InnoDB.");
}
assertCommercial(
    (int) $pdo->query('SELECT COUNT(*) FROM chantier_client_purchase_orders')->fetchColumn()
        === (int) $pdo->query('SELECT COUNT(*) FROM chantier_banamur')->fetchColumn(),
    'Tous les chantiers legacy doivent avoir une liaison BC client.'
);
foreach (['planned_start_date', 'planned_end_date'] as $column) {
    $statement = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='feb_request_lines' AND COLUMN_NAME=:column_name"
    );
    $statement->execute(['column_name' => $column]);
    assertCommercial((int) $statement->fetchColumn() === 1, "Colonne FEB absente: $column");
}
$summary = $controller->treasury(30);
assertCommercial(
    abs($summary['real_balance'] - ($summary['opening_balance'] + $summary['real_inflows'] + $summary['loans_received'] - $summary['real_outflows'] - $summary['loans_repaid'])) < 0.01,
    'Formule de trésorerie réelle invalide.'
);

$quote = $pdo->query('SELECT id,total_ttc FROM devis_banamur ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
assertCommercial((bool) $quote, 'Un devis local est requis pour le test transactionnel.');
$pdo->beginTransaction();
try {
    $result = $controller->createPurchaseOrder([
        'devis_id' => (int) $quote['id'],
        'order_number' => 'TEST-BC-' . bin2hex(random_bytes(6)),
        'amount' => (float) $quote['total_ttc'] + 123,
        'order_date' => date('Y-m-d'),
    ], 2);
    assertCommercial(!empty($result['has_amount_warning']), 'Un écart de montant doit être signalé.');
    assertCommercial((int) $result['id'] > 0, 'Un écart de montant ne doit pas bloquer le BC.');
} finally {
    $pdo->rollBack();
}

assertCommercial(
    (int) $pdo->query('SELECT COUNT(*) FROM devis_archives WHERE restored_at IS NULL')->fetchColumn() > 0,
    'Les archives logiques doivent être présentes et restaurables.'
);
echo "CommercialProjectFinanceTest: OK\n";
