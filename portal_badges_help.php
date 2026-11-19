<?php

require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/model/Database.php';
require_once __DIR__ . '/model/PortalBadgeManager.php';
require_once __DIR__ . '/model/PortalModuleManager.php';

$pdo = \Database::getConnection();
$currentUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
$permissions = is_array($_SESSION['permissions'] ?? null) ? $_SESSION['permissions'] : [];
$badgeManager = new \PortalBadgeManager($pdo, $currentUserId, $permissions, $_SESSION);
$portalModuleManager = new \PortalModuleManager($pdo);

$modules = [];
foreach ($portalModuleManager->listActive() as $module) {
    $moduleKey = (string) ($module['module_key'] ?? '');
    if ($moduleKey === '') {
        continue;
    }

    $requiredPermissions = is_array($module['required_permissions'] ?? null) ? $module['required_permissions'] : [];
    $hasAccess = $requiredPermissions === [];
    foreach ($requiredPermissions as $permission) {
        if (!empty($permissions[$permission])) {
            $hasAccess = true;
            break;
        }
    }

    if (!$hasAccess) {
        continue;
    }

    $modules[$moduleKey] = $module;
}

$guide = $badgeManager->getBadgeGuide(array_keys($modules));
$guideModules = $guide['modules'] ?? [];

uasort($modules, static function (array $left, array $right): int {
    return strcmp((string) ($left['label'] ?? ''), (string) ($right['label'] ?? ''));
});

function portalBadgeThresholdText(array $thresholds): string
{
    $parts = [];
    if (isset($thresholds['watch']) && is_numeric($thresholds['watch'])) {
        $parts[] = 'orange à partir de ' . (int) $thresholds['watch'];
    }
    if (isset($thresholds['urgent']) && is_numeric($thresholds['urgent'])) {
        $parts[] = 'rouge à partir de ' . (int) $thresholds['urgent'];
    }
    if (isset($thresholds['critical']) && is_numeric($thresholds['critical'])) {
        $parts[] = 'rouge foncé à partir de ' . (int) $thresholds['critical'];
    }

    if ($parts === []) {
        return 'La couleur reste simple et dépend seulement de la présence d’éléments à traiter.';
    }

    return 'En pratique : ' . implode(', ', $parts) . '.';
}

require_once __DIR__ . '/decaissement/includes/header.php';
?>
<style>
    .portal-badge-help-shell {
        min-height: 100vh;
        padding: 2rem 1rem 3rem;
        background:
            radial-gradient(circle at top left, rgba(245, 158, 11, 0.12), transparent 24%),
            radial-gradient(circle at bottom right, rgba(14, 165, 233, 0.12), transparent 28%),
            #f8fafc;
    }

    .portal-badge-help-wrap {
        max-width: 1180px;
        margin: 0 auto;
    }

    .portal-badge-help-hero {
        padding: 1.6rem;
        border-radius: 28px;
        background: rgba(255, 255, 255, 0.92);
        border: 1px solid rgba(148, 163, 184, 0.18);
        box-shadow: 0 18px 46px -30px rgba(15, 23, 42, 0.3);
    }

    .portal-badge-help-title {
        margin: 0;
        font-size: 2rem;
        line-height: 1.08;
        font-weight: 800;
        color: #0f172a;
    }

    .portal-badge-help-subtitle {
        margin-top: .7rem;
        font-size: 1rem;
        color: #475569;
        max-width: 780px;
    }

    .portal-badge-help-actions {
        display: flex;
        flex-wrap: wrap;
        gap: .8rem;
        margin-top: 1.2rem;
    }

    .portal-badge-help-link {
        display: inline-flex;
        align-items: center;
        gap: .55rem;
        padding: .85rem 1rem;
        border-radius: 16px;
        text-decoration: none;
        font-weight: 700;
        background: #fff;
        border: 1px solid rgba(148, 163, 184, 0.22);
        color: #0f172a;
    }

    .portal-badge-help-link.primary {
        background: linear-gradient(135deg, #0f172a, #1d4ed8);
        color: #fff;
        border-color: transparent;
    }

    .portal-badge-color-grid,
    .portal-badge-module-grid {
        display: grid;
        gap: 1rem;
        margin-top: 1.35rem;
    }

    .portal-badge-color-grid {
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    }

    .portal-badge-module-grid {
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        margin-top: 1.6rem;
    }

    .portal-badge-card,
    .portal-badge-module-card {
        background: rgba(255, 255, 255, 0.94);
        border: 1px solid rgba(148, 163, 184, 0.18);
        border-radius: 24px;
        box-shadow: 0 14px 32px -28px rgba(15, 23, 42, 0.32);
    }

    .portal-badge-card {
        padding: 1.2rem;
    }

    .portal-badge-pill {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 42px;
        height: 42px;
        padding: 0 .8rem;
        border-radius: 999px;
        color: #fff;
        font-weight: 800;
        font-size: .88rem;
        margin-bottom: .85rem;
    }

    .portal-badge-pill.watch {
        background: linear-gradient(135deg, #f59e0b, #d97706);
    }

    .portal-badge-pill.urgent {
        background: linear-gradient(135deg, #dc2626, #b91c1c);
    }

    .portal-badge-pill.critical {
        background: linear-gradient(135deg, #7f1d1d, #991b1b 58%, #dc2626 100%);
    }

    .portal-badge-card-title,
    .portal-badge-module-title {
        margin: 0;
        font-weight: 800;
        color: #0f172a;
    }

    .portal-badge-card-text,
    .portal-badge-module-text {
        margin-top: .55rem;
        color: #475569;
        line-height: 1.6;
    }

    .portal-badge-module-card {
        padding: 1.3rem;
    }

    .portal-badge-module-header {
        display: flex;
        align-items: center;
        gap: .8rem;
    }

    .portal-badge-module-icon {
        width: 44px;
        height: 44px;
        border-radius: 14px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        color: #fff;
        font-size: 1rem;
        background: linear-gradient(135deg, #1d4ed8, #0f172a);
    }

    .portal-badge-module-levels {
        display: flex;
        flex-wrap: wrap;
        gap: .55rem;
        margin-top: 1rem;
    }

    .portal-badge-level-tag {
        display: inline-flex;
        align-items: center;
        gap: .45rem;
        padding: .45rem .7rem;
        border-radius: 999px;
        background: #f8fafc;
        border: 1px solid rgba(148, 163, 184, 0.22);
        color: #334155;
        font-size: .78rem;
        font-weight: 700;
    }

    .portal-badge-threshold-note {
        margin-top: .95rem;
        padding: .8rem .9rem;
        border-radius: 16px;
        background: #f8fafc;
        color: #334155;
        font-size: .9rem;
        line-height: 1.5;
    }
</style>

<div class="portal-badge-help-shell">
    <div class="portal-badge-help-wrap">
        <section class="portal-badge-help-hero">
            <h1 class="portal-badge-help-title">Comprendre les badges du portail</h1>
            <p class="portal-badge-help-subtitle">Chaque badge montre combien d’actions, de dossiers ou de points restent à traiter. Le chiffre vous aide à savoir où agir. La couleur vous aide à voir si c’est simplement à suivre, urgent, ou critique.</p>
            <div class="portal-badge-help-actions">
                <a href="selection_portail.php" class="portal-badge-help-link primary"><i class="fas fa-th-large"></i><span>Retour au portail</span></a>
                <?php if (!empty($permissions['acces_admin'])): ?>
                    <a href="admin/portal_badges_settings.php" class="portal-badge-help-link"><i class="fas fa-sliders-h"></i><span>Paramétrer les badges</span></a>
                <?php endif; ?>
            </div>
        </section>

        <section class="portal-badge-color-grid">
            <?php foreach (($guide['colors'] ?? []) as $variant => $color): ?>
                <article class="portal-badge-card">
                    <div class="portal-badge-pill <?= htmlspecialchars($variant, ENT_QUOTES, 'UTF-8') ?>">12</div>
                    <h2 class="portal-badge-card-title"><?= htmlspecialchars((string) ($color['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?> : <?= htmlspecialchars((string) ($color['meaning'] ?? ''), ENT_QUOTES, 'UTF-8') ?></h2>
                    <p class="portal-badge-card-text"><?= htmlspecialchars((string) ($color['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
                </article>
            <?php endforeach; ?>
        </section>

        <section class="portal-badge-module-grid">
            <?php foreach ($modules as $moduleKey => $module): ?>
                <?php $moduleGuide = $guideModules[$moduleKey] ?? null; ?>
                <?php if (!$moduleGuide): ?>
                    <?php continue; ?>
                <?php endif; ?>
                <article class="portal-badge-module-card">
                    <div class="portal-badge-module-header">
                        <div class="portal-badge-module-icon"><i class="fas <?= htmlspecialchars(preg_replace('/^fa[srbld]?\s+/i', '', (string) ($module['icon'] ?? 'fa-circle')), ENT_QUOTES, 'UTF-8') ?>"></i></div>
                        <div>
                            <h2 class="portal-badge-module-title"><?= htmlspecialchars((string) ($module['label'] ?? $moduleKey), ENT_QUOTES, 'UTF-8') ?></h2>
                            <div class="portal-badge-module-text"><?= htmlspecialchars((string) ($moduleGuide['counted_description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                    </div>

                    <div class="portal-badge-module-levels">
                        <?php foreach ((array) ($moduleGuide['variant_labels'] ?? []) as $variant => $label): ?>
                            <?php if (trim((string) $label) === '') {
                                continue;
                            } ?>
                            <span class="portal-badge-level-tag">
                                <span class="portal-badge-pill <?= htmlspecialchars((string) $variant, ENT_QUOTES, 'UTF-8') ?>" style="min-width:30px;height:30px;font-size:.75rem;margin-bottom:0;">1</span>
                                <span><?= htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8') ?></span>
                            </span>
                        <?php endforeach; ?>
                    </div>

                    <div class="portal-badge-threshold-note"><?= htmlspecialchars(portalBadgeThresholdText((array) ($moduleGuide['thresholds'] ?? [])), ENT_QUOTES, 'UTF-8') ?></div>
                </article>
            <?php endforeach; ?>
        </section>
    </div>
</div>

<?php require_once __DIR__ . '/decaissement/includes/footer.php'; ?>