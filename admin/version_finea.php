<?php
require_once __DIR__ . '/../auth_check.php';
AccessManager::requirePageAccess();
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/PortalModuleManager.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../services/FineaReleaseService.php';

$pdo = Database::getConnection();
$modules = (new PortalModuleManager($pdo))->listActive();
$popupId = FineaReleaseService::ensureCurrentReleasePopup($pdo, (int) ($_SESSION['user_id'] ?? 0));
$version = trim((string) @file_get_contents(dirname(__DIR__) . '/VERSION')) ?: FineaReleaseService::VERSION;

AuditTrail::logPageConsultation([
    'module' => 'admin',
    'action' => 'version_page_view',
    'entity_type' => 'release',
    'entity_id' => $version,
], $pdo);

$pageTitle = 'Version FINEA';
$activeMenu = '';
require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>
<link rel="stylesheet" href="../assets/css/finea-ui.css">
<main class="finea-shell">
    <div class="finea-container">
        <header class="finea-page-header">
            <div><h1>FINEA v<?= htmlspecialchars($version) ?></h1><p>Gouvernance, tracabilite et pilotage transverse BANAMUR.</p></div>
            <div class="finea-header-actions"><a class="finea-action-btn finea-action-btn--secondary" href="dashboard_admin.php"><i class="fas fa-arrow-left"></i> Administration</a></div>
        </header>
        <section class="finea-grid finea-kpi-grid">
            <article class="finea-kpi-card"><span class="finea-kpi-label">Version actuelle</span><strong class="finea-kpi-value">v<?= htmlspecialchars($version) ?></strong><span class="finea-kpi-meta">Stable</span></article>
            <article class="finea-kpi-card"><span class="finea-kpi-label">Mise a jour</span><strong class="finea-kpi-value">07/06/2026</strong><span class="finea-kpi-meta">Gouvernance & pilotage</span></article>
            <article class="finea-kpi-card"><span class="finea-kpi-label">Modules actifs</span><strong class="finea-kpi-value"><?= count($modules) ?></strong><span class="finea-kpi-meta">Cartes actives du portail</span></article>
            <article class="finea-kpi-card"><span class="finea-kpi-label">Annonce interne</span><strong class="finea-kpi-value"><?= $popupId ? 'Active' : 'A verifier' ?></strong><span class="finea-kpi-meta"><?= $popupId ? 'Popup #' . $popupId : 'Creation reportee' ?></span></article>
        </section>
        <div class="finea-grid" style="grid-template-columns:repeat(auto-fit,minmax(300px,1fr))">
            <section class="finea-section-card">
                <h2 class="finea-section-title">Dernieres evolutions</h2>
                <div class="finea-timeline">
                    <div class="finea-timeline-item"><strong>Audit ACL</strong><div class="finea-kpi-meta">Controle pages, menus, permissions et comptes.</div></div>
                    <div class="finea-timeline-item"><strong>Dashboard DG</strong><div class="finea-kpi-meta">Finance, chantiers, RH, tickets, CRM, AO et documentation.</div></div>
                    <div class="finea-timeline-item"><strong>Design system</strong><div class="finea-kpi-meta">Composants communs et mode sombre compatible.</div></div>
                    <div class="finea-timeline-item"><strong>Audit trail enrichi</strong><div class="finea-kpi-meta">Ancien et nouvel etat, commentaire et criticite.</div></div>
                </div>
            </section>
            <section class="finea-section-card">
                <h2 class="finea-section-title">Roadmap courte</h2>
                <p>Extension progressive du design commun, tests automatiques ACL, indicateurs budgetaires chantier et exports signes.</p>
                <div class="finea-actions" style="margin-top:18px">
                    <a class="finea-action-btn finea-action-btn--primary" href="audit_acl.php"><i class="fas fa-shield-halved"></i> Audit ACL</a>
                    <a class="finea-action-btn finea-action-btn--secondary" href="../dashboard_dg.php"><i class="fas fa-chart-pie"></i> Dashboard DG</a>
                    <a class="finea-action-btn finea-action-btn--secondary" href="audit_erp.php"><i class="fas fa-clock-rotate-left"></i> Audit ERP</a>
                </div>
            </section>
        </div>
    </div>
</main>
<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>
