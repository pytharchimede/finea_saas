<?php
require_once __DIR__ . '/../auth_check.php';
AccessManager::requirePageAccess();
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../services/AclAuditService.php';

$pdo = Database::getConnection();
$report = (new AclAuditService($pdo, dirname(__DIR__)))->buildReport();
$filter = strtolower(trim((string) ($_GET['filter'] ?? 'all')));
$search = trim((string) ($_GET['q'] ?? ''));
$issues = array_values(array_filter($report['issues'], static function (array $issue) use ($filter, $search): bool {
    if ($filter !== 'all' && $filter !== (string) $issue['level']) {
        return false;
    }
    return $search === '' || stripos($issue['label'] . ' ' . $issue['detail'] . ' ' . $issue['type'], $search) !== false;
}));

AuditTrail::logPageConsultation([
    'module' => 'admin',
    'action' => 'acl_audit_view',
    'entity_type' => 'acl_report',
    'details' => ['issue_count' => count($report['issues'])],
], $pdo);

$pageTitle = 'Audit ACL';
$activeMenu = '';
require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>
<link rel="stylesheet" href="../assets/css/finea-ui.css">
<main class="finea-shell">
    <div class="finea-container">
        <header class="finea-page-header">
            <div>
                <h1><i class="fas fa-shield-halved"></i> Audit des droits et menus</h1>
                <p>Controle croise des pages, permissions, cartes du portail et comptes utilisateurs.</p>
            </div>
            <div class="finea-header-actions">
                <a class="finea-action-btn finea-action-btn--secondary" href="gestion_acces.php"><i class="fas fa-users-gear"></i> Droits</a>
                <a class="finea-action-btn finea-action-btn--accent" href="audit_acl.php"><i class="fas fa-rotate"></i> Actualiser</a>
            </div>
        </header>

        <section class="finea-grid finea-kpi-grid">
            <?php
            $cards = [
                ['Pages analysees', $report['counts']['pages'], 'Inventaire PHP hors bibliotheques'],
                ['Pages protegees', $report['counts']['protected'], 'Authentification et ACL detectees'],
                ['Points a traiter', $report['counts']['issues'], 'Anomalies et controles manuels'],
                ['Permissions', $report['counts']['permissions'], 'Droits disponibles en base'],
                ['Modules portail', $report['counts']['modules'], 'Cartes configurees'],
                ['Comptes incoherents', $report['counts']['users_incoherent'], 'Profils a revoir'],
            ];
            foreach ($cards as $card): ?>
                <article class="finea-kpi-card">
                    <span class="finea-kpi-label"><?= htmlspecialchars($card[0]) ?></span>
                    <strong class="finea-kpi-value"><?= number_format((int) $card[1], 0, ',', ' ') ?></strong>
                    <span class="finea-kpi-meta"><?= htmlspecialchars($card[2]) ?></span>
                </article>
            <?php endforeach; ?>
        </section>

        <form class="finea-filter-card" method="get">
            <div class="finea-filter-grid">
                <div class="finea-field">
                    <label for="q">Recherche</label>
                    <input class="finea-input" id="q" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Page, permission, utilisateur...">
                </div>
                <div class="finea-field">
                    <label for="filter">Niveau</label>
                    <select class="finea-select" id="filter" name="filter">
                        <?php foreach (['all' => 'Tous', 'danger' => 'Critique', 'warning' => 'A verifier'] as $value => $label): ?>
                            <option value="<?= $value ?>" <?= $filter === $value ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button class="finea-action-btn finea-action-btn--primary" type="submit"><i class="fas fa-filter"></i> Filtrer</button>
            </div>
        </form>

        <section class="finea-section-card">
            <h2 class="finea-section-title">Resultats du controle</h2>
            <?php if ($issues === []): ?>
                <div class="finea-empty-state"><i class="fas fa-circle-check fa-2x"></i><p>Aucune anomalie pour ce filtre.</p></div>
            <?php else: ?>
                <div class="finea-table-wrap">
                    <table class="finea-table">
                        <thead><tr><th>Statut</th><th>Type</th><th>Element</th><th>Diagnostic</th></tr></thead>
                        <tbody>
                        <?php foreach ($issues as $issue): ?>
                            <tr>
                                <td><span class="finea-status-badge finea-status-badge--<?= $issue['level'] === 'danger' ? 'danger' : 'warning' ?>"><?= htmlspecialchars($issue['status']) ?></span></td>
                                <td><?= htmlspecialchars(ucfirst($issue['type'])) ?></td>
                                <td><strong><?= htmlspecialchars($issue['label']) ?></strong></td>
                                <td><?= htmlspecialchars($issue['detail']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>
</main>
<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>
