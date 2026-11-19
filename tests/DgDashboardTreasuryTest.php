<?php
declare(strict_types=1);

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../repositories/DgDashboardRepository.php';

$pdo = Database::getConnection();
$beforeArchives = (int) $pdo->query('SELECT COUNT(*) FROM devis_archives WHERE restored_at IS NULL')->fetchColumn();
$data = (new DgDashboardRepository($pdo))->projectFinanceDecision();
if (count($data['monthly_cashflow'] ?? []) !== 6) {
    throw new RuntimeException('Le graphique de trésorerie doit couvrir six mois.');
}
if (abs((float) $data['projected_balance'] - ((float) $data['real_balance'] + (float) $data['forecast_inflows'] - (float)($data['forecast_outflows']??0))) > 0.01) {
    throw new RuntimeException('Le solde projeté du dashboard DG est incohérent.');
}
if ((int) ($data['coverage']['chantiers'] ?? 0) !== (int) $pdo->query('SELECT COUNT(*) FROM chantier_banamur WHERE archived_at IS NULL')->fetchColumn()) {
    throw new RuntimeException('La couverture chantier du dashboard DG est incomplète.');
}
if ((int) $pdo->query('SELECT COUNT(*) FROM devis_archives WHERE restored_at IS NULL')->fetchColumn() !== $beforeArchives) {
    throw new RuntimeException('Le dashboard DG ne doit produire aucune écriture.');
}
echo "DgDashboardTreasuryTest: OK\n";
