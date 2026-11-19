<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/EmployeeLegalRequestManager.php';
require_once __DIR__ . '/../model/FebValidationPolicy.php';

$pdo = \Database::getConnection();
$manager = new EmployeeLegalRequestManager($pdo);

$actorUserId = (int) ($_SESSION['user_id'] ?? 0);
$servicePerm = $actorUserId > 0
    ? FebValidationPolicy::getUserServicePermissions($pdo, $actorUserId)
    : ['all' => false, 'ids' => [], 'configured' => false, 'raw' => ''];
$serviceFilter = !empty($servicePerm['all']) ? null : (array) ($servicePerm['ids'] ?? []);

$tab = strtolower(trim((string) ($_GET['tab'] ?? 'all')));
$includeLegacyFeb = !empty($_GET['archives']) || !empty($_GET['legacy']) || !empty($_GET['include_archives']);
$searchQuery = trim((string) ($_GET['q'] ?? ''));
$searchRequester = trim((string) ($_GET['demandeur'] ?? ''));
$searchAffectation = trim((string) ($_GET['affectation'] ?? ''));
$hasExplicitIds = array_key_exists('ids', $_GET);
$ids = array_values(array_unique(array_filter(array_map('intval', preg_split('/[,\s;]+/', (string) ($_GET['ids'] ?? ''), -1, PREG_SPLIT_NO_EMPTY)), static fn(int $v): bool => $v > 0)));

$statusesByTab = [
    'mine_pending' => ['submitted'],
    'pending_all' => ['submitted'],
    'approved' => ['approved'],
    'rejected' => ['rejected'],
    'cancelled' => ['cancelled'],
    'all' => ['submitted', 'approved', 'rejected', 'cancelled'],
];
$statuses = $statusesByTab[$tab] ?? $statusesByTab['all'];

try {
    $rows = $hasExplicitIds ? $manager->listRequestsByIds($ids) : $manager->listRequestsForTeamAdvanced('finance', $statuses, 'feb', $serviceFilter, 500, $includeLegacyFeb);
    $counts = $manager->countRequestsForTeam('finance', 'feb', $serviceFilter, $includeLegacyFeb);
} catch (Throwable $e) {
    $rows = [];
    $counts = [];
}

$rows = array_values(array_filter($rows, static function (array $row) use ($servicePerm, $searchQuery, $searchRequester, $searchAffectation): bool {
    if (($row['assigned_team'] ?? '') !== 'finance' || ($row['request_type'] ?? '') !== 'feb') {
        return false;
    }
    $serviceId = FebValidationPolicy::requestServiceId($row);
    if (!FebValidationPolicy::canValidateService($servicePerm, $serviceId)) {
        return false;
    }
    $payload = (array) ($row['payload'] ?? []);
    $display = (array) ($row['feb_display'] ?? EmployeeLegalRequestManager::extractFebDisplay($row));
    $haystack = implode(' ', [$row['id'] ?? '', $row['title'] ?? '', $row['requester_name'] ?? '', $payload['departement_label'] ?? '', $payload['service_employe'] ?? '', $display['demandeur'] ?? '', $display['description'] ?? '', $display['numero_bon_commande_interne'] ?? '', $display['code_autorisation_feb'] ?? '']);
    $match = static function (string $hay, string $needle): bool {
        $needle = trim($needle);
        if ($needle === '') return true;
        return stripos($hay, $needle) !== false;
    };
    return $match($haystack, $searchQuery)
        && $match((string) ($display['demandeur'] ?? ($row['requester_name'] ?? '')), $searchRequester)
        && $match((string) (($payload['departement_label'] ?? '') . ' ' . ($payload['service_employe'] ?? '')), $searchAffectation);
}));

$totalAmount = 0.0;
foreach ($rows as $row) {
    $display = (array) ($row['feb_display'] ?? EmployeeLegalRequestManager::extractFebDisplay($row));
    $totalAmount += (float) ($display['montant'] ?? 0);
}

function feb_recap_status_label(string $status): string
{
    $status = strtolower(trim($status));
    return [
        'submitted' => 'En attente',
        'approved' => 'Validee',
        'rejected' => 'Refusee',
        'cancelled' => 'Annulee',
    ][$status] ?? ($status !== '' ? $status : '-');
}

function feb_recap_money($value): string
{
    return number_format((float) $value, 0, ',', ' ') . ' FCFA';
}

$filename = 'recap_feb_bci_' . date('Ymd_His') . '.xls';

header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename=' . $filename);
header('Cache-Control: max-age=0');

echo "\xEF\xBB\xBF";
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="utf-8">
    <title>Recap FEB - BCI</title>
    <style>
        table { border-collapse: collapse; table-layout: fixed; width: 100%; }
        th, td { vertical-align: top; }
        .wrap { white-space: normal; word-wrap: break-word; mso-data-placement: same-cell; }
        .description { width: 420px; }
    </style>
</head>

<body>
    <table border="1" cellpadding="6" cellspacing="0">
        <colgroup>
            <col style="width:90px">
            <col style="width:120px">
            <col style="width:180px">
            <col style="width:180px">
            <col style="width:420px">
            <col style="width:120px">
            <col style="width:110px">
            <col style="width:140px">
            <col style="width:140px">
            <col style="width:160px">
            <col style="width:260px">
        </colgroup>
        <tr>
            <th colspan="11" style="font-size:16px;">Recapitulatif FEB / Bons de commande internes (BCI)</th>
        </tr>
        <tr>
            <td><b>Export du</b></td>
            <td colspan="10"><?= htmlspecialchars(date('d/m/Y H:i')) ?></td>
        </tr>
        <tr>
            <td><b>En attente</b></td>
            <td><?= (int) ($counts['submitted'] ?? 0) ?></td>
            <td><b>Validees</b></td>
            <td><?= (int) ($counts['approved'] ?? 0) ?></td>
            <td><b>Refusees</b></td>
            <td><?= (int) ($counts['rejected'] ?? 0) ?></td>
            <td><b>Annulees</b></td>
            <td><?= (int) ($counts['cancelled'] ?? 0) ?></td>
            <td><b>Total affiche</b></td>
            <td colspan="2"><?= count($rows) ?></td>
        </tr>
        <tr>
            <td><b>Montant total affiche</b></td>
            <td colspan="10"><?= htmlspecialchars(number_format($totalAmount, 0, ',', ' ')) ?> FCFA</td>
        </tr>
        <tr>
            <th>Ref. FEB</th>
            <th>N BCI</th>
            <th>Demandeur</th>
            <th>Service beneficiaire</th>
            <th class="description">Description</th>
            <th>Montant</th>
            <th>Statut</th>
            <th>Date soumission</th>
            <th>Date decision</th>
            <th>Decideur</th>
            <th>Note decision</th>
        </tr>
        <?php if (!$rows): ?>
            <tr>
                <td colspan="11">Aucune FEB dans votre perimetre.</td>
            </tr>
        <?php else: ?>
            <?php foreach ($rows as $row): ?>
                <?php
                $payload = (array) ($row['payload'] ?? []);
                $display = (array) ($row['feb_display'] ?? EmployeeLegalRequestManager::extractFebDisplay($row));
                $bci = trim((string) ($display['numero_bon_commande_interne'] ?? $display['code_autorisation_feb'] ?? ''));
                $description = (string) ($display['description'] ?? ($row['title'] ?? ''));
                ?>
                <tr>
                    <td class="wrap"><?= htmlspecialchars((string) ($display['chantier_no'] ?? ('FEB-' . (int) ($row['id'] ?? 0)))) ?></td>
                    <td class="wrap"><?= htmlspecialchars($bci !== '' ? $bci : '-') ?></td>
                    <td class="wrap"><?= htmlspecialchars((string) ($display['demandeur'] ?? ($row['requester_name'] ?? ''))) ?></td>
                    <td class="wrap"><?= htmlspecialchars((string) ($payload['departement_label'] ?? '')) ?></td>
                    <td class="wrap description"><?= nl2br(htmlspecialchars($description), false) ?></td>
                    <td class="wrap"><?= htmlspecialchars(feb_recap_money((float) ($display['montant'] ?? 0))) ?></td>
                    <td class="wrap"><?= htmlspecialchars(feb_recap_status_label((string) ($row['status'] ?? ''))) ?></td>
                    <td class="wrap"><?= htmlspecialchars((string) ($row['submitted_at'] ?? '')) ?></td>
                    <td class="wrap"><?= htmlspecialchars((string) ($row['decided_at'] ?? '')) ?></td>
                    <td class="wrap"><?= htmlspecialchars((string) ($row['decided_by_name'] ?? '')) ?></td>
                    <td class="wrap"><?= nl2br(htmlspecialchars((string) ($row['decision_note'] ?? '')), false) ?></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </table>
</body>

</html>
