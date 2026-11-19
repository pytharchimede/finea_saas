<?php

require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/PortalBadgeManager.php';
require_once __DIR__ . '/../model/PortalModuleManager.php';

if (empty($_SESSION['permissions']['acces_admin'])) {
    http_response_code(403);
    exit('Accès refusé');
}

$pdo = \Database::getConnection();
$currentUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
$permissions = is_array($_SESSION['permissions'] ?? null) ? $_SESSION['permissions'] : [];
$scope = (string) ($_GET['scope'] ?? \PortalBadgeManager::SCOPE_ALL);
$badgeManager = new \PortalBadgeManager($pdo, $currentUserId, $permissions, $_SESSION);
$snapshot = $badgeManager->getDebugSnapshot($scope);
$portalModuleManager = new \PortalModuleManager($pdo);
$modulesByKey = [];

foreach ($portalModuleManager->listAll() as $module) {
    $moduleKey = (string) ($module['module_key'] ?? '');
    if ($moduleKey !== '') {
        $modulesByKey[$moduleKey] = $module;
    }
}

$debugModules = array_values(array_map(static function (array $entry) use ($modulesByKey): array {
    $moduleKey = (string) ($entry['module_key'] ?? '');
    $definition = $modulesByKey[$moduleKey] ?? [];
    $badge = is_array($entry['badge'] ?? null) ? $entry['badge'] : ['count' => 0, 'label' => '', 'variant' => \PortalBadgeManager::VARIANT_WATCH, 'volatile' => false];

    return [
        'module_key' => $moduleKey,
        'label' => (string) ($definition['label'] ?? $moduleKey),
        'target' => (string) ($definition['target'] ?? ''),
        'source' => (string) ($entry['source'] ?? ''),
        'thresholds' => is_array($entry['thresholds'] ?? null) ? $entry['thresholds'] : [],
        'counts' => is_array($entry['counts'] ?? null) ? $entry['counts'] : [],
        'reason' => (string) ($entry['reason'] ?? ''),
        'handled' => !empty($entry['handled']),
        'permission_granted' => !empty($entry['permission_granted']),
        'volatile' => !empty($entry['volatile']),
        'badge' => $badge,
    ];
}, $snapshot['modules'] ?? []));

$summary = [
    'critical' => 0,
    'urgent' => 0,
    'watch' => 0,
    'inactive' => 0,
];

foreach ($debugModules as $module) {
    $variant = (string) ($module['badge']['variant'] ?? '');
    $count = (int) ($module['badge']['count'] ?? 0);
    if ($count <= 0) {
        $summary['inactive']++;
        continue;
    }
    if ($variant === \PortalBadgeManager::VARIANT_CRITICAL) {
        $summary['critical']++;
    } elseif ($variant === \PortalBadgeManager::VARIANT_URGENT) {
        $summary['urgent']++;
    } else {
        $summary['watch']++;
    }
}

usort($debugModules, static function (array $left, array $right): int {
    $priority = [
        \PortalBadgeManager::VARIANT_CRITICAL => 0,
        \PortalBadgeManager::VARIANT_URGENT => 1,
        \PortalBadgeManager::VARIANT_WATCH => 2,
    ];

    $leftCount = (int) ($left['badge']['count'] ?? 0);
    $rightCount = (int) ($right['badge']['count'] ?? 0);
    $leftVariant = (string) ($left['badge']['variant'] ?? \PortalBadgeManager::VARIANT_WATCH);
    $rightVariant = (string) ($right['badge']['variant'] ?? \PortalBadgeManager::VARIANT_WATCH);

    $variantComparison = ($priority[$leftVariant] ?? 9) <=> ($priority[$rightVariant] ?? 9);
    if ($variantComparison !== 0) {
        return $variantComparison;
    }

    $countComparison = $rightCount <=> $leftCount;
    if ($countComparison !== 0) {
        return $countComparison;
    }

    return strcmp((string) ($left['label'] ?? ''), (string) ($right['label'] ?? ''));
});

$rawJson = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

require_once __DIR__ . '/../decaissement/includes/header.php';
?>
<style>
    .badge-debug-shell {
        min-height: 100vh;
        background:
            radial-gradient(circle at top left, rgba(14, 165, 233, 0.16), transparent 28%),
            radial-gradient(circle at bottom right, rgba(239, 68, 68, 0.12), transparent 26%),
            #f8fafc;
        padding: 2rem 1.25rem 3rem;
    }

    .badge-debug-wrap {
        max-width: 1280px;
        margin: 0 auto;
    }

    .badge-debug-hero {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        padding: 1.5rem;
        border-radius: 28px;
        background: rgba(255, 255, 255, 0.9);
        border: 1px solid rgba(148, 163, 184, 0.18);
        box-shadow: 0 18px 50px -28px rgba(15, 23, 42, 0.25);
    }

    .badge-debug-title {
        font-size: 1.9rem;
        line-height: 1.1;
        font-weight: 800;
        color: #0f172a;
        margin: 0;
    }

    .badge-debug-subtitle {
        margin-top: .45rem;
        color: #475569;
        font-size: .98rem;
    }

    .badge-debug-actions {
        display: flex;
        flex-wrap: wrap;
        gap: .75rem;
    }

    .badge-debug-btn {
        display: inline-flex;
        align-items: center;
        gap: .5rem;
        padding: .8rem 1rem;
        border-radius: 16px;
        font-weight: 700;
        text-decoration: none;
        border: 1px solid rgba(148, 163, 184, 0.22);
        background: #fff;
        color: #0f172a;
    }

    .badge-debug-btn.primary {
        background: linear-gradient(135deg, #0f766e, #0f172a);
        color: #fff;
        border-color: transparent;
    }

    .badge-debug-summary {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 1rem;
        margin-top: 1.25rem;
    }

    .badge-debug-stat {
        padding: 1.1rem 1rem;
        border-radius: 22px;
        background: rgba(255, 255, 255, 0.9);
        border: 1px solid rgba(148, 163, 184, 0.16);
        box-shadow: 0 12px 36px -26px rgba(15, 23, 42, 0.3);
    }

    .badge-debug-stat .label {
        font-size: .72rem;
        text-transform: uppercase;
        letter-spacing: .12em;
        color: #64748b;
        font-weight: 800;
    }

    .badge-debug-stat .value {
        margin-top: .45rem;
        font-size: 2rem;
        font-weight: 800;
        color: #020617;
    }

    .badge-debug-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 1rem;
        margin-top: 1.5rem;
    }

    .badge-debug-card {
        border-radius: 24px;
        background: rgba(255, 255, 255, 0.94);
        border: 1px solid rgba(148, 163, 184, 0.16);
        box-shadow: 0 14px 34px -24px rgba(15, 23, 42, 0.28);
        overflow: hidden;
    }

    .badge-debug-card[data-variant="critical"] {
        border-color: rgba(153, 27, 27, 0.2);
        box-shadow: 0 18px 38px -24px rgba(127, 29, 29, 0.32);
    }

    .badge-debug-card[data-variant="urgent"] {
        border-color: rgba(185, 28, 28, 0.16);
    }

    .badge-debug-card-header {
        padding: 1rem 1rem .8rem;
        border-bottom: 1px solid rgba(226, 232, 240, 0.75);
    }

    .badge-debug-topline {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: .75rem;
    }

    .badge-debug-name {
        font-size: 1rem;
        font-weight: 800;
        color: #0f172a;
    }

    .badge-debug-key {
        margin-top: .28rem;
        font-size: .74rem;
        color: #64748b;
    }

    .badge-debug-pill {
        min-width: 42px;
        height: 42px;
        padding: 0 .8rem;
        border-radius: 999px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-weight: 800;
        color: #fff;
        background: linear-gradient(135deg, #f59e0b, #d97706);
    }

    .badge-debug-pill.critical {
        background: linear-gradient(135deg, #7f1d1d, #dc2626);
    }

    .badge-debug-pill.urgent {
        background: linear-gradient(135deg, #b91c1c, #ef4444);
    }

    .badge-debug-card-body {
        padding: 1rem;
        display: grid;
        gap: .85rem;
    }

    .badge-debug-meta {
        display: flex;
        flex-wrap: wrap;
        gap: .5rem;
    }

    .badge-debug-chip {
        display: inline-flex;
        align-items: center;
        padding: .35rem .6rem;
        border-radius: 999px;
        background: #f8fafc;
        border: 1px solid rgba(148, 163, 184, 0.18);
        color: #334155;
        font-size: .72rem;
        font-weight: 700;
    }

    .badge-debug-stack {
        display: grid;
        gap: .45rem;
    }

    .badge-debug-stack-row {
        display: flex;
        align-items: baseline;
        justify-content: space-between;
        gap: 1rem;
        font-size: .88rem;
    }

    .badge-debug-stack-row .key {
        color: #64748b;
        font-weight: 700;
    }

    .badge-debug-stack-row .val {
        color: #0f172a;
        font-weight: 800;
        text-align: right;
    }

    .badge-debug-raw {
        margin-top: 1.5rem;
        border-radius: 24px;
        background: #020617;
        color: #e2e8f0;
        padding: 1rem;
        box-shadow: 0 16px 40px -28px rgba(15, 23, 42, 0.55);
    }

    .badge-debug-raw pre {
        margin: 0;
        white-space: pre-wrap;
        word-break: break-word;
        font-size: .8rem;
        line-height: 1.55;
        max-height: 420px;
        overflow: auto;
    }
</style>
<div class="badge-debug-shell">
    <div class="badge-debug-wrap">
        <section class="badge-debug-hero">
            <div>
                <h1 class="badge-debug-title">Debug badges portail</h1>
                <p class="badge-debug-subtitle">Lecture rapide des sources, seuils et compteurs sans charger le portail applicatif complet.</p>
                <p class="badge-debug-subtitle">Scope actif : <strong><?= htmlspecialchars((string) ($snapshot['scope'] ?? \PortalBadgeManager::SCOPE_ALL), ENT_QUOTES, 'UTF-8') ?></strong> | Généré le <?= htmlspecialchars((string) ($snapshot['generated_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <div class="badge-debug-actions">
                <a class="badge-debug-btn primary" href="portal_badges_debug.php?scope=all">Vue complète</a>
                <a class="badge-debug-btn" href="portal_badges_debug.php?scope=volatile">Vue volatile</a>
                <a class="badge-debug-btn" href="../api/portal_badges.php?scope=<?= urlencode((string) ($snapshot['scope'] ?? \PortalBadgeManager::SCOPE_ALL)) ?>&debug=1" target="_blank" rel="noreferrer">JSON debug API</a>
                <a class="badge-debug-btn" href="../selection_portail.php?debug_modules=1" target="_blank" rel="noreferrer">Portail avec payload</a>
            </div>
        </section>

        <section class="badge-debug-summary">
            <article class="badge-debug-stat">
                <div class="label">Critiques</div>
                <div class="value"><?= (int) $summary['critical'] ?></div>
            </article>
            <article class="badge-debug-stat">
                <div class="label">Urgents</div>
                <div class="value"><?= (int) $summary['urgent'] ?></div>
            </article>
            <article class="badge-debug-stat">
                <div class="label">Surveillance</div>
                <div class="value"><?= (int) $summary['watch'] ?></div>
            </article>
            <article class="badge-debug-stat">
                <div class="label">Sans signal</div>
                <div class="value"><?= (int) $summary['inactive'] ?></div>
            </article>
        </section>

        <section class="badge-debug-grid">
            <?php foreach ($debugModules as $module): ?>
                <?php
                $badge = $module['badge'];
                $variant = (string) ($badge['variant'] ?? \PortalBadgeManager::VARIANT_WATCH);
                $count = (int) ($badge['count'] ?? 0);
                ?>
                <article class="badge-debug-card" data-variant="<?= htmlspecialchars($variant, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="badge-debug-card-header">
                        <div class="badge-debug-topline">
                            <div>
                                <div class="badge-debug-name"><?= htmlspecialchars((string) $module['label'], ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="badge-debug-key"><?= htmlspecialchars((string) $module['module_key'], ENT_QUOTES, 'UTF-8') ?></div>
                            </div>
                            <div class="badge-debug-pill <?= htmlspecialchars($variant, ENT_QUOTES, 'UTF-8') ?>"><?= $count > 99 ? '99+' : $count ?></div>
                        </div>
                    </div>
                    <div class="badge-debug-card-body">
                        <div class="badge-debug-meta">
                            <span class="badge-debug-chip">Raison : <?= htmlspecialchars((string) $module['reason'], ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="badge-debug-chip">Volatile : <?= !empty($module['volatile']) ? 'oui' : 'non' ?></span>
                            <span class="badge-debug-chip">Droits : <?= !empty($module['permission_granted']) ? 'ok' : 'manquants' ?></span>
                            <span class="badge-debug-chip">Scope : <?= !empty($module['handled']) ? 'géré' : 'filtré' ?></span>
                        </div>
                        <div class="badge-debug-stack">
                            <div class="badge-debug-stack-row">
                                <span class="key">Source</span>
                                <span class="val"><?= htmlspecialchars((string) $module['source'], ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                            <div class="badge-debug-stack-row">
                                <span class="key">Libellé badge</span>
                                <span class="val"><?= htmlspecialchars((string) ($badge['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                            <?php if ($module['target'] !== ''): ?>
                                <div class="badge-debug-stack-row">
                                    <span class="key">Cible</span>
                                    <span class="val"><?= htmlspecialchars((string) $module['target'], ENT_QUOTES, 'UTF-8') ?></span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($module['counts'])): ?>
                            <div>
                                <div class="badge-debug-stack-row">
                                    <span class="key">Compteurs</span>
                                    <span class="val"><?= count($module['counts']) ?></span>
                                </div>
                                <div class="badge-debug-stack">
                                    <?php foreach ($module['counts'] as $key => $value): ?>
                                        <div class="badge-debug-stack-row">
                                            <span class="key"><?= htmlspecialchars((string) $key, ENT_QUOTES, 'UTF-8') ?></span>
                                            <span class="val"><?= htmlspecialchars(is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($module['thresholds'])): ?>
                            <div>
                                <div class="badge-debug-stack-row">
                                    <span class="key">Seuils</span>
                                    <span class="val"><?= count($module['thresholds']) ?></span>
                                </div>
                                <div class="badge-debug-stack">
                                    <?php foreach ($module['thresholds'] as $key => $value): ?>
                                        <div class="badge-debug-stack-row">
                                            <span class="key"><?= htmlspecialchars((string) $key, ENT_QUOTES, 'UTF-8') ?></span>
                                            <span class="val"><?= htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>

        <section class="badge-debug-raw">
            <pre><?= htmlspecialchars((string) $rawJson, ENT_QUOTES, 'UTF-8') ?></pre>
        </section>
    </div>
</div>