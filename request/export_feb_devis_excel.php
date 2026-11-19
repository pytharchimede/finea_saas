<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/EmployeeLegalRequestManager.php';
require_once __DIR__ . '/../model/FebValidationPolicy.php';
require_once __DIR__ . '/../employe/includes/helpers.php';

$pdo = \Database::getConnection();
$manager = new EmployeeLegalRequestManager($pdo);

$requestId = isset($_GET['id']) && is_numeric($_GET['id']) ? (int) $_GET['id'] : 0;
if ($requestId <= 0) {
    http_response_code(400);
    echo 'ID manquant.';
    exit;
}

$request = $manager->getRequestById($requestId);
if (!$request || ($request['request_type'] ?? '') !== 'feb') {
    http_response_code(404);
    echo 'FEB introuvable.';
    exit;
}

$actorUserId = (int) ($_SESSION['user_id'] ?? 0);
$isRequester = $actorUserId > 0 && (int) ($request['requester_user_id'] ?? 0) === $actorUserId;

$userEmail = (string) ($_SESSION['mail_pro'] ?? '');
$personnelProfile = employeeFindPersonnelProfile($pdo, $userEmail);
$actorMatricule = strtoupper(trim((string) ($personnelProfile['matricule'] ?? '')));
$payloadMatricule = strtoupper(trim((string) (($request['payload']['matricule'] ?? ''))));
$isRequesterByMatricule = ($actorMatricule !== '' && $payloadMatricule !== '' && $actorMatricule === $payloadMatricule);

$hasFinanceAccess = (
    hasPermission('acces_feb')
    || hasPermission('acces_admin')
);

$canFinanceExport = false;
if ($hasFinanceAccess && $actorUserId > 0) {
    $perm = FebValidationPolicy::getUserServicePermissions($pdo, $actorUserId);
    $serviceId = FebValidationPolicy::requestServiceId($request);
    $canFinanceExport = FebValidationPolicy::canValidateService($perm, $serviceId);
}

if (!$isRequester && !$isRequesterByMatricule && !$canFinanceExport) {
    http_response_code(403);
    echo 'Accès refusé.';
    exit;
}

$payload = (array) ($request['payload'] ?? []);
$besoins = (array) ($payload['besoins'] ?? []);
$febDisplay = (array) ($request['feb_display'] ?? EmployeeLegalRequestManager::extractFebDisplay($request));
$bciNumber = trim((string) ($febDisplay['numero_bon_commande_interne'] ?? $febDisplay['code_autorisation_feb'] ?? ''));

$filename = ($bciNumber !== '' ? ('details_bci_feb_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $bciNumber)) : ('details_feb_' . $requestId)) . '.xls';

header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename=' . $filename);
header('Cache-Control: max-age=0');

// BOM UTF-8 pour Excel (Windows)
echo "\xEF\xBB\xBF";

$title = (string) ($request['title'] ?? 'FEB');
$service = (string) ($payload['departement_label'] ?? '');
$submittedAt = (string) ($request['submitted_at'] ?? '');

$totalGeneral = 0.0;

?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="utf-8">
    <title><?= htmlspecialchars($title) ?></title>
</head>

<body>
    <table border="1" cellpadding="6" cellspacing="0">
        <tr>
            <td><b>N BCI</b></td>
            <td colspan="6"><?= htmlspecialchars($bciNumber !== '' ? $bciNumber : 'Généré au positionnement dans le planning') ?></td>
        </tr>
        <tr>
            <th colspan="7" style="font-size:16px;background:#312e81;color:#fff;">DEVIS RATTACHÉ À LA FEB</th>
        </tr>
        <tr>
            <td><b>Titre</b></td>
            <td colspan="6"><?= htmlspecialchars((string) ($payload['feb_quote_title'] ?? $title)) ?></td>
        </tr>
        <tr>
            <td><b>Service bénéficiaire</b></td>
            <td colspan="6"><?= htmlspecialchars($service) ?></td>
        </tr>
        <tr>
            <td><b>Date</b></td>
            <td colspan="6"><?= htmlspecialchars($submittedAt) ?></td>
        </tr>
        <tr>
            <th style="width:40px;">N°</th>
            <th>Catégorie</th>
            <th>Désignation</th>
            <th style="width:70px;">Qte</th>
            <th style="width:90px;">PU (FCFA)</th>
            <th style="width:110px;">Total (FCFA)</th>
            <th style="width:180px;">Fournisseur (si renseigné)</th>
        </tr>
        <?php if (!$besoins): ?>
            <tr>
                <td colspan="7">Aucun besoin.</td>
            </tr>
        <?php else: ?>
            <?php $i = 0;
            foreach ($besoins as $b): ?>
                <?php
                if (!is_array($b)) {
                    continue;
                }
                $i++;
                $lib = (string) ($b['libelle'] ?? '');
                $qte = (float) ($b['quantite'] ?? 0);
                $pu = 0.0;
                $fournisseur = '';
                if (!empty($b['has_fournisseur']) && !empty($b['fournisseur']) && is_array($b['fournisseur'])) {
                    $pu = (float) ($b['fournisseur']['prix_unitaire'] ?? 0);
                    $fournisseur = (string) ($b['fournisseur']['nom'] ?? '');
                }
                $ligneTotal = ($qte > 0 && $pu > 0) ? ($qte * $pu) : 0.0;
                $totalGeneral += $ligneTotal;
                ?>
                <tr>
                    <td><?= (int) $i ?></td>
                    <td><?= htmlspecialchars((string) ($b['categorie'] ?? '')) ?></td>
                    <td><?= htmlspecialchars($lib) ?></td>
                    <td><?= htmlspecialchars((string) $qte) ?></td>
                    <td><?= $pu > 0 ? htmlspecialchars(number_format($pu, 2, ',', ' ')) : '' ?></td>
                    <td><?= $ligneTotal > 0 ? htmlspecialchars(number_format($ligneTotal, 2, ',', ' ')) : '' ?></td>
                    <td><?= htmlspecialchars($fournisseur) ?></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        <tr>
            <td colspan="5" style="text-align:right;"><b>Total Général</b></td>
            <td colspan="2"><b><?= htmlspecialchars(number_format($totalGeneral, 2, ',', ' ')) ?></b></td>
        </tr>
    </table>
</body>

</html>
