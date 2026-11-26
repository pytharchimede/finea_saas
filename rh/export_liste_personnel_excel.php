<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../controllers/RhPersonnelController.php';

$pdo = \Database::getConnection();
$controller = new \RhPersonnelController($pdo);
$directory = $controller->model();

$filters = [
    'q' => trim((string) ($_GET['q'] ?? '')),
    'service_id' => trim((string) ($_GET['service_id'] ?? '')),
    'fonction_id' => trim((string) ($_GET['fonction_id'] ?? '')),
    'status_code' => trim((string) ($_GET['status_code'] ?? '')),
    'gender_code' => trim((string) ($_GET['gender_code'] ?? '')),
    'site_id' => trim((string) ($_GET['site_id'] ?? '')),
    'scope' => (string) ($_GET['scope'] ?? 'active'),
    'search_mode' => (string) ($_GET['search_mode'] ?? 'include'),
];
if (!in_array($filters['scope'], ['active', 'inactive', 'all'], true)) {
    $filters['scope'] = 'active';
}
if (!in_array($filters['search_mode'], ['include', 'exclude'], true)) {
    $filters['search_mode'] = 'include';
}

$rows = $directory->getPersonnelRowsForExport($filters, 10000);
require_once __DIR__.'/../model/CompanyBranding.php';
$brand=CompanyBranding::get($pdo);
$palette=CompanyBranding::palette($pdo);
$primary=$palette['primary'];$accent=$palette['accent'];
$onPrimary=CompanyBranding::contrastColor($primary);$onAccent=CompanyBranding::contrastColor($accent);

header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="liste_personnel_rh_' . date('Ymd_His') . '.xls"');
echo "\xEF\xBB\xBF";
?>
<table border="1">
    <tr>
        <td colspan="13" style="background:<?= $primary ?>;color:<?= $onPrimary ?>;font-size:18px;font-weight:bold;padding:12px;"><?= htmlspecialchars((string)$brand['company_name'], ENT_QUOTES, 'UTF-8') ?> - LISTE DU PERSONNEL RH</td>
    </tr>
    <tr>
        <td colspan="13" style="background:<?= $accent ?>;color:<?= $onAccent ?>;font-weight:bold;padding:7px;">Export du <?= date('d/m/Y H:i') ?> - <?= count($rows) ?> collaborateur(s)</td>
    </tr>
    <thead>
        <tr style="background:<?= $accent ?>;color:<?= $onAccent ?>;font-weight:bold;">
            <th>Matricule</th>
            <th>Nom complet</th>
            <th>Email</th>
            <th>Telephone</th>
            <th>Service</th>
            <th>Fonction</th>
            <th>Statut</th>
            <th>Site</th>
            <th>Sexe</th>
            <th>Date recrutement</th>
            <th>Date prise service</th>
            <th>Date sortie</th>
            <th>Etat</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td><?= htmlspecialchars((string) ($row['matricule'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($row['full_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($row['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($row['phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($row['service_label'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($row['fonction_label'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($row['statut_label'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($row['site_label'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($row['gender_label'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($row['hire_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($row['start_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($row['end_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= (int) ($row['is_active'] ?? 0) === 1 ? 'En poste' : 'Sorti' ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
