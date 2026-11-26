<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/EmployeeLegalRequestManager.php';
require_once __DIR__ . '/../employe/includes/helpers.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../model/CompanyExportBranding.php';

function parse_selected_ids(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return [];
    }

    $parts = preg_split('/[^0-9]+/', $raw);
    if (!is_array($parts)) {
        return [];
    }

    $ids = array_values(array_unique(array_filter(array_map('intval', $parts), static function (int $v): bool {
        return $v > 0;
    })));

    return array_slice($ids, 0, 500);
}

function request_status_label(array $row): string
{
    $status = strtolower(trim((string) ($row['status'] ?? '')));
    if (($row['request_type'] ?? '') === 'feb' && $status === 'approved') {
        $payload = (array) ($row['payload'] ?? []);
        $legacy = (array) ($payload['legacy'] ?? []);
        $tag = strtolower(trim((string) ($legacy['status_tag'] ?? '')));
        if ($tag === 'decaissed') {
            return 'Décaissée';
        }
        return 'Approuvée';
    }

    return match ($status) {
        'submitted' => 'En attente',
        'approved' => 'Approuvée',
        'rejected' => 'Refusée',
        'cancelled' => 'Annulée',
        default => $status !== '' ? $status : '—',
    };
}

function format_fcfa_excel(float $value): string
{
    if ($value <= 0) {
        return '—';
    }
    return number_format($value, 0, ',', ' ') . ' FCFA';
}

function request_amount_excel(array $row): float
{
    $payload = (array) ($row['payload'] ?? []);
    if (($row['request_type'] ?? '') === 'feb') {
        return (float) ($payload['montant'] ?? 0);
    }
    if (($row['request_type'] ?? '') === 'reclamation_salariale') {
        return (float) ($payload['montant_manquant'] ?? 0);
    }
    return 0.0;
}

$pdo = \Database::getConnection();
$manager = new \EmployeeLegalRequestManager($pdo);

$userId = (int) ($_SESSION['user_id'] ?? 0);
$userEmail = (string) ($_SESSION['mail_pro'] ?? '');

$personnelProfile = employeeFindPersonnelProfile($pdo, $userEmail);
$actorMatricule = strtoupper(trim((string) ($personnelProfile['matricule'] ?? '')));

$idsRaw = (string) ($_POST['ids'] ?? ($_GET['ids'] ?? ''));
$ids = parse_selected_ids($idsRaw);

if (!$ids) {
    http_response_code(400);
    echo 'Aucune sélection.';
    exit;
}

$rows = $manager->listRequestsByIds($ids);

$allowed = [];
foreach ($rows as $row) {
    $rid = (int) ($row['id'] ?? 0);
    if ($rid <= 0) {
        continue;
    }

    $isOwner = $userId > 0 && (int) ($row['requester_user_id'] ?? 0) === $userId;

    $isOwnerByMatricule = false;
    if (!$isOwner && $actorMatricule !== '' && ($row['request_type'] ?? '') === 'feb') {
        $payloadJson = (string) ($row['payload_json'] ?? '');
        $needle = '"matricule":"' . str_replace('"', '\\"', $actorMatricule) . '"';
        $isOwnerByMatricule = $payloadJson !== '' && strpos($payloadJson, $needle) !== false;
    }

    if ($isOwner || $isOwnerByMatricule) {
        $allowed[] = $row;
    }
}

if (!$allowed) {
    http_response_code(403);
    echo 'Accès refusé.';
    exit;
}

$totalAmount = 0.0;
foreach ($allowed as $row) {
    $totalAmount += request_amount_excel($row);
}

$filename = 'demandes_selection_' . date('Ymd_His') . '.xls';
\AuditTrail::logFileEvent('export', [
    'module' => 'request',
    'action' => 'export_employee_requests_excel',
    'entity_type' => 'employee_legal_request_selection',
    'entity_label' => $filename,
    'format' => 'xls',
    'user_id' => $_SESSION['user_id'] ?? null,
    'user_name' => $_SESSION['user_name'] ?? $_SESSION['username'] ?? null,
    'details' => [
        'selected_count' => count($ids),
        'allowed_count' => count($allowed),
        'total_amount' => $totalAmount,
    ],
], $pdo);
header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

echo "\xEF\xBB\xBF"; // BOM UTF-8

?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <title>Export demandes</title>
</head>

<body>
    <table border="1" cellspacing="0" cellpadding="6">
        <tr>
            <th colspan="6" style="background:#<?=CompanyExportBranding::rgb('primary')?>;color:#<?=CompanyExportBranding::rgb('primary',true)?>;"><?=htmlspecialchars(CompanyExportBranding::identityLine(),ENT_QUOTES,'UTF-8')?> — Demandes sélectionnées</th>
        </tr>
        <tr>
            <td colspan="3"><b>Nombre sélectionné</b></td>
            <td colspan="3"><?= (int) count($allowed) ?></td>
        </tr>
        <tr>
            <td colspan="3"><b>Total montant sélectionné</b></td>
            <td colspan="3"><?= htmlspecialchars(format_fcfa_excel($totalAmount), ENT_QUOTES, 'UTF-8') ?></td>
        </tr>
        <tr>
            <th style="background:#<?=CompanyExportBranding::rgb('primary')?>;color:#<?=CompanyExportBranding::rgb('primary',true)?>;">Réf.</th>
            <th>Type</th>
            <th>Titre</th>
            <th>Statut</th>
            <th>Date</th>
            <th>Montant (FCFA)</th>
        </tr>
        <?php foreach ($allowed as $row): ?>
            <?php
            $amount = request_amount_excel($row);

            $titleCell = (string) ($row['title'] ?? '');
            if (($row['request_type'] ?? '') === 'feb') {
                $febDisplay = (array) ($row['feb_display'] ?? \EmployeeLegalRequestManager::extractFebDisplay($row));
                $titleLines = [];
                $titleLines[] = (string) ($febDisplay['chantier_label'] ?? ('CHANTIER N° FEB-' . (int) ($row['id'] ?? 0)));
                $titleLines[] = 'Demandeur : ' . (string) ($febDisplay['demandeur'] ?? ($row['requester_name'] ?? ''));
                $titleLines[] = 'Description : ' . (string) ($febDisplay['description'] ?? $titleCell);
                $titleLines[] = 'Créée le : ' . (string) (($febDisplay['created_date'] ?? '') ?: substr((string) ($row['submitted_at'] ?? ''), 0, 10));
                $titleLines[] = 'Montant : ' . (string) ($febDisplay['montant_label'] ?? format_fcfa_excel($amount));
                $titleCell = implode(' | ', array_filter(array_map('trim', $titleLines), static fn($v) => $v !== ''));
            } elseif (($row['request_type'] ?? '') === 'reclamation_salariale') {
                $claimDisplay = (array) ($row['salary_claim_display'] ?? \EmployeeLegalRequestManager::extractSalaryClaimDisplay($row));
                $titleLines = [];
                $titleLines[] = (string) ($claimDisplay['objet_label'] ?? $titleCell);
                $titleLines[] = 'Mois concerné : ' . (string) ($claimDisplay['mois_label'] ?? '—');
                $titleLines[] = 'Demandeur : ' . (string) ($claimDisplay['demandeur'] ?? ($row['requester_name'] ?? ''));
                $titleLines[] = 'Montant manquant : ' . (string) ($claimDisplay['montant_label'] ?? format_fcfa_excel($amount));
                $titleLines[] = 'Détails : ' . (string) ($claimDisplay['details_differend'] ?? $titleCell);
                $titleCell = implode(' | ', array_filter(array_map('trim', $titleLines), static fn($v) => $v !== ''));
            }
            ?>
            <tr>
                <td>#<?= (int) ($row['id'] ?? 0) ?></td>
                <td><?= htmlspecialchars((string) ($row['type_label'] ?? $row['request_type'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($titleCell, ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars(request_status_label($row), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($row['submitted_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= $amount > 0 ? (float)$amount : '' ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
</body>

</html>