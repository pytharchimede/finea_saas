<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/model/Database.php';
require_once __DIR__ . '/model/AuditTrail.php';
require_once __DIR__ . '/model/PortalModuleManager.php';
require_once __DIR__ . '/model/PortalBadgeManager.php';
require_once __DIR__ . '/model/PortalUserPreferences.php';
require_once __DIR__ . '/model/CompanyBranding.php';
require_once __DIR__ . '/services/FineaReleaseService.php';

function safeJsonForScript($value, string $fallback = '[]'): string
{
    $json = json_encode(
        $value,
        JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_INVALID_UTF8_SUBSTITUTE
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
    );

    return $json === false ? $fallback : $json;
}

function normalizePortalModuleIcon(string $moduleKey, string $icon): string
{
    $moduleKey = strtolower(trim($moduleKey));
    $icon = trim($icon);

    if ($moduleKey === 'module_documentation' && ($icon === '' || $icon === 'fa-folder-tree')) {
        return 'fa-folder-open';
    }

    if ($icon === '') {
        return 'fa-cube';
    }

    return preg_replace('/^fa[srbld]?\s+/i', '', $icon) ?: 'fa-cube';
}

function buildPortalModuleSearchTerms(array $module): string
{
    $requiredPermissions = $module['required_permissions'] ?? [];
    if (!is_array($requiredPermissions)) {
        $requiredPermissions = [];
    }

    $parts = [
        (string) ($module['module_key'] ?? ''),
        (string) ($module['label'] ?? ''),
        (string) ($module['subtitle'] ?? ''),
        (string) ($module['target'] ?? ''),
        (string) ($module['slug'] ?? ''),
        implode(' ', array_filter($requiredPermissions, static function ($permission): bool {
            return is_string($permission) && $permission !== '';
        })),
    ];

    return trim(mb_strtolower(implode(' ', array_filter($parts)), 'UTF-8'));
}

function normalizePortalModuleTarget(array $module): array
{
    $moduleKey = strtolower(trim((string) ($module['module_key'] ?? $module['id'] ?? '')));
    $target = trim((string) ($module['target'] ?? ''));

    if ($moduleKey === 'module_emploi_du_temps' && ($target === '' || $target === 'emploi_du_temps.php')) {
        $module['target'] = 'emploi_du_temps/index.php';
        $module['slug'] = 'emploi_du_temps';
    }

    if ($moduleKey === 'module_formation' && ($target === '' || $target === 'formation.php')) {
        $module['target'] = 'formation/index.php';
        $module['slug'] = 'formation';
    }

    return $module;
}

function loadPortalModuleUsageStats(PDO $pdo, ?int $userId = null): array
{
    \AuditTrail::ensureSchema($pdo);

    $filterSql = '';
    $params = [];
    if ($userId !== null && $userId > 0) {
        $filterSql = ' AND user_id = :user_id';
        $params['user_id'] = $userId;
    }

    $sql = "
        SELECT action_name, SUM(click_count) AS total_clicks
        FROM (
            SELECT action AS action_name, COUNT(*) AS click_count
            FROM audit_trail
            WHERE event_type = 'portal_module_click'
              AND action LIKE 'portal_module_enter_%'
              {$filterSql}
            GROUP BY action

            UNION ALL

            SELECT action AS action_name, COUNT(*) AS click_count
            FROM audit_trail_archive
            WHERE event_type = 'portal_module_click'
              AND action LIKE 'portal_module_enter_%'
              {$filterSql}
            GROUP BY action
        ) usage_rows
        GROUP BY action_name
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $stats = [];
    foreach ($rows as $row) {
        $actionName = strtolower(trim((string) ($row['action_name'] ?? '')));
        if ($actionName === '' || strpos($actionName, 'portal_module_enter_') !== 0) {
            continue;
        }

        $moduleKey = substr($actionName, strlen('portal_module_enter_'));
        $stats[$moduleKey] = (int) ($row['total_clicks'] ?? 0);
    }

    return $stats;
}

$pageTitle = 'Portail - Sélection Modules';
$activeMenu = '';
// Pages autorisées depuis la session (définies par auth_check / PageAccess)
$allowedPages = isset($_SESSION['allowed_pages']) && is_array($_SESSION['allowed_pages'])
    ? array_values($_SESSION['allowed_pages'])
    : [];
$pdo = \Database::getConnection();
$currentUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
\FineaReleaseService::ensureCurrentReleasePopup($pdo, $currentUserId ?? 0);
$portalBadgeManager = new \PortalBadgeManager($pdo, $currentUserId, is_array($_SESSION['permissions'] ?? null) ? $_SESSION['permissions'] : [], $_SESSION);
$portalUserPreferences = new \PortalUserPreferences($pdo);

try {
    $portalModuleManager = new \PortalModuleManager($pdo);
    $portalModules = $portalModuleManager->listActive();
} catch (Throwable $e) {
    $portalModules = \PortalModuleManager::defaultModules();
}

$portalModules = array_map('normalizePortalModuleTarget', $portalModules);

$portalModulesByKey = [];
foreach ($portalModules as $portalModule) {
    $moduleKey = (string) ($portalModule['module_key'] ?? $portalModule['id'] ?? '');
    if ($moduleKey === '') {
        continue;
    }
    $portalModulesByKey[$moduleKey] = $portalModule;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['track_module'])) {
    header('Content-Type: application/json; charset=UTF-8');

    try {
        $payload = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            $payload = $_POST;
        }

        $moduleKey = strtolower(trim((string) ($payload['module_key'] ?? '')));
        if ($moduleKey === '' || !isset($portalModulesByKey[$moduleKey])) {
            throw new RuntimeException('Module inconnu.');
        }

        $moduleDef = $portalModulesByKey[$moduleKey];
        \AuditTrail::log('portal_module_click', [
            'module' => 'portal',
            'action' => 'portal_module_enter_' . $moduleKey,
            'entity_type' => 'portal_module',
            'entity_id' => (string) ($moduleDef['module_key'] ?? $moduleKey),
            'entity_label' => (string) ($moduleDef['label'] ?? $moduleKey),
            'page_name' => 'selection_portail.php',
            'details' => [
                'module_key' => $moduleKey,
                'target' => (string) ($moduleDef['target'] ?? ''),
                'view_mode' => 'portal_module_click',
            ],
        ], $pdo);

        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['ok' => false], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['save_portal_preferences'])) {
    header('Content-Type: application/json; charset=UTF-8');

    try {
        if ($currentUserId === null || $currentUserId <= 0) {
            throw new RuntimeException('Utilisateur introuvable.');
        }

        $payload = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            $payload = $_POST;
        }

        if (array_key_exists('favorite_modules', $payload)) {
            $favoriteModules = $payload['favorite_modules'];
            if (!is_array($favoriteModules)) {
                $favoriteModules = [];
            }

            $portalUserPreferences->saveFavoriteModules($currentUserId, $favoriteModules);
        }

        if (array_key_exists('module_launcher_enabled', $payload)) {
            $previousLauncherEnabled = $portalUserPreferences->getModuleLauncherEnabled($currentUserId);
            $moduleLauncherEnabled = filter_var($payload['module_launcher_enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            $moduleLauncherEnabled = $moduleLauncherEnabled === null ? true : $moduleLauncherEnabled;

            $portalUserPreferences->saveModuleLauncherEnabled($currentUserId, $moduleLauncherEnabled);

            if ($previousLauncherEnabled !== $moduleLauncherEnabled) {
                \AuditTrail::log('portal_preference_change', [
                    'module' => 'portal',
                    'action' => $moduleLauncherEnabled ? 'portal_module_launcher_reactivated' : 'portal_module_launcher_disabled',
                    'entity_type' => 'portal_user_preferences',
                    'entity_id' => (string) $currentUserId,
                    'entity_label' => 'popup_choix_module',
                    'page_name' => 'selection_portail.php',
                    'details' => [
                        'preference' => 'module_launcher_enabled',
                        'previous_enabled' => $previousLauncherEnabled,
                        'enabled' => $moduleLauncherEnabled,
                        'view_mode' => 'portal_preference_change',
                    ],
                ], $pdo);
            }
        }

        echo json_encode([
            'ok' => true,
            'favorite_modules' => $portalUserPreferences->getFavoriteModules($currentUserId),
            'module_launcher_enabled' => $portalUserPreferences->getModuleLauncherEnabled($currentUserId),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['ok' => false], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    exit;
}

$globalModuleUsageStats = loadPortalModuleUsageStats($pdo);
$personalModuleUsageStats = loadPortalModuleUsageStats($pdo, $currentUserId);
$portalPendingBadges = $portalBadgeManager->getBadges();
$favoriteModuleIds = $currentUserId !== null && $currentUserId > 0
    ? $portalUserPreferences->getFavoriteModules($currentUserId)
    : [];
$moduleLauncherEnabled = $currentUserId !== null && $currentUserId > 0
    ? $portalUserPreferences->getModuleLauncherEnabled($currentUserId)
    : true;
$defaultModuleOrderLookup = [];
foreach (array_values($portalModules) as $index => $portalModule) {
    $moduleKey = (string) ($portalModule['module_key'] ?? $portalModule['id'] ?? '');
    if ($moduleKey === '') {
        continue;
    }
    $defaultModuleOrderLookup[$moduleKey] = $index;
}

usort($portalModules, static function (array $left, array $right) use ($personalModuleUsageStats, $globalModuleUsageStats, $defaultModuleOrderLookup): int {
    $leftKey = (string) ($left['module_key'] ?? $left['id'] ?? '');
    $rightKey = (string) ($right['module_key'] ?? $right['id'] ?? '');

    $personalComparison = ((int) ($personalModuleUsageStats[$rightKey] ?? 0)) <=> ((int) ($personalModuleUsageStats[$leftKey] ?? 0));
    if ($personalComparison !== 0) {
        return $personalComparison;
    }

    $globalComparison = ((int) ($globalModuleUsageStats[$rightKey] ?? 0)) <=> ((int) ($globalModuleUsageStats[$leftKey] ?? 0));
    if ($globalComparison !== 0) {
        return $globalComparison;
    }

    return ($defaultModuleOrderLookup[$leftKey] ?? PHP_INT_MAX) <=> ($defaultModuleOrderLookup[$rightKey] ?? PHP_INT_MAX);
});

$debugPortalModules = !empty($_GET['debug_modules']) && !empty($_SESSION['permissions']['acces_admin']);
$portalBranding = CompanyBranding::get($pdo);
$portalAccentColor = strtolower(trim((string)($portalBranding['accent_color'] ?? '#ffcc20')));
if (!preg_match('/^#[0-9a-f]{6}$/', $portalAccentColor)) {
    $portalAccentColor = '#ffcc20';
}

$modulesPayload = array_map(static function (array $module) use ($personalModuleUsageStats, $globalModuleUsageStats, $portalPendingBadges, $portalAccentColor): array {
    $requiredPermissions = $module['required_permissions'] ?? [];
    if (!is_array($requiredPermissions)) {
        $requiredPermissions = [];
    }

    $moduleKey = (string)($module['module_key'] ?? $module['id'] ?? '');
    $personalCount = (int) ($personalModuleUsageStats[$moduleKey] ?? 0);
    $globalCount = (int) ($globalModuleUsageStats[$moduleKey] ?? 0);
    $pendingBadge = $portalPendingBadges[$moduleKey] ?? ['count' => 0, 'label' => '', 'variant' => \PortalBadgeManager::VARIANT_WATCH, 'volatile' => \PortalBadgeManager::isVolatileModule($moduleKey)];

    return [
        'id' => $moduleKey,
        'label' => (string)($module['label'] ?? ''),
        'icon' => normalizePortalModuleIcon($moduleKey, (string)($module['icon'] ?? 'fa-cube')),
        // Une couleur unique garantit que les cartes, favoris et lanceurs
        // respectent la charte globale, quelle que soit l'ancienne couleur du module.
        'color' => $portalAccentColor,
        'target' => (string)($module['target'] ?? ''),
        'slug' => (string)($module['slug'] ?? ''),
        'anyOf' => array_values(array_filter($requiredPermissions, static function ($permission): bool {
            return is_string($permission) && $permission !== '';
        })),
        'subtitle' => (string)($module['subtitle'] ?? ''),
        'searchTerms' => buildPortalModuleSearchTerms($module),
        'personalCount' => $personalCount,
        'globalCount' => $globalCount,
        'pendingBadgeCount' => (int) ($pendingBadge['count'] ?? 0),
        'pendingBadgeLabel' => (string) ($pendingBadge['label'] ?? ''),
        'pendingBadgeVariant' => (string) ($pendingBadge['variant'] ?? \PortalBadgeManager::VARIANT_WATCH),
        'pendingBadgeVolatile' => !empty($pendingBadge['volatile']),
    ];
}, $portalModules);

$allowedPagesJson = safeJsonForScript($allowedPages);
$permissionsJson = safeJsonForScript($_SESSION['permissions'] ?? [], '{}');
$modulesJson = safeJsonForScript($modulesPayload);
$favoriteModulesJson = safeJsonForScript($favoriteModuleIds);
$moduleLauncherEnabledJson = $moduleLauncherEnabled ? 'true' : 'false';
$trackModuleUrlJson = safeJsonForScript('selection_portail.php?track_module=1', '"selection_portail.php?track_module=1"');
$savePortalPreferencesUrlJson = safeJsonForScript('selection_portail.php?save_portal_preferences=1', '"selection_portail.php?save_portal_preferences=1"');
$badgeCountsUrlJson = safeJsonForScript('api/portal_badges.php?scope=volatile', '"api/portal_badges.php?scope=volatile"');
$portalSearchUrlJson = safeJsonForScript('api/portal_global_search.php', '"api/portal_global_search.php"');
$portalCompanyName = trim((string)($portalBranding['company_name'] ?? '')) ?: 'BANAMUR INDUSTRIES ET TECHNOLOGIES';
$portalLogoUrl = 'branding_asset.php?type=logo';
$portalIconUrl = 'branding_asset.php?type=icon';
$aoPortalStats = null;
if (\AccessManager::hasPermission('acces_veille_ao') || \AccessManager::hasPermission('ao_view') || \AccessManager::hasPermission('ao_admin')) {
    try {
        require_once __DIR__ . '/repositories/AoOpportunityRepository.php';
        $aoPortalStats = (new \AoOpportunityRepository($pdo))->stats('active');
    } catch (Throwable $e) {
        $aoPortalStats = null;
    }
}

// Utilise les includes du module decaissement pour style unifié
require_once __DIR__ . '/decaissement/includes/header.php';
// Pas de navbar sur le portail de sélection
?>
<style>
    :root {
        --finea-blue: #1d2b57;
        --finea-gold: #fabd02;
        --portal-ink: #0f172a;
        --portal-muted: #64748b;
        --portal-line: rgba(148, 163, 184, .22);
        --portal-card: rgba(255, 255, 255, .88);
    }

    .portal-page {
        min-height: 100vh;
        position: relative;
        overflow: hidden;
        padding: 2rem clamp(1rem, 2vw, 2rem) 3rem;
        color: var(--portal-ink);
        background:
            radial-gradient(circle at top left, rgba(250, 189, 2, .16), transparent 34rem),
            radial-gradient(circle at top right, rgba(29, 43, 87, .16), transparent 32rem),
            linear-gradient(135deg, #f8fafc 0%, #eef2f7 46%, #f8fafc 100%);
        transition: background .35s ease, color .35s ease;
    }

    .portal-page.is-dark {
        --portal-ink: #e5edf8;
        --portal-muted: #94a3b8;
        --portal-line: rgba(148, 163, 184, .18);
        --portal-card: rgba(15, 23, 42, .78);
        background:
            radial-gradient(circle at 14% 4%, rgba(250, 189, 2, .13), transparent 22rem),
            radial-gradient(circle at 84% 0%, rgba(96, 165, 250, .18), transparent 28rem),
            radial-gradient(circle at 55% 26%, rgba(15, 23, 42, .0), rgba(2, 6, 23, .35) 34rem),
            linear-gradient(135deg, #020617 0%, #0f172a 52%, #111827 100%);
    }

    .portal-page::before {
        content: '';
        position: absolute;
        inset: 0;
        background-image:
            linear-gradient(rgba(29, 43, 87, .045) 1px, transparent 1px),
            linear-gradient(90deg, rgba(29, 43, 87, .045) 1px, transparent 1px);
        background-size: 42px 42px;
        mask-image: linear-gradient(to bottom, rgba(0, 0, 0, .74), transparent 78%);
        pointer-events: none;
    }

    .portal-page.is-dark::before {
        background-image:
            radial-gradient(circle, rgba(255,255,255,.7) 0 1px, transparent 1.4px),
            linear-gradient(rgba(148, 163, 184, .055) 1px, transparent 1px),
            linear-gradient(90deg, rgba(148, 163, 184, .055) 1px, transparent 1px);
        background-size: 120px 120px, 42px 42px, 42px 42px;
        background-position: 14px 18px, 0 0, 0 0;
        mask-image: linear-gradient(to bottom, rgba(0, 0, 0, .86), transparent 82%);
        opacity: .78;
    }

    .portal-shell {
        width: min(1540px, 100%);
        margin: 0 auto;
        position: relative;
        z-index: 2;
    }

    .portal-hero {
        display: grid;
        grid-template-columns: minmax(0, 1.45fr) minmax(330px, .75fr);
        gap: 1.2rem;
        align-items: stretch;
    }

    .portal-hero-main,
    .portal-hero-side,
    .portal-search-card,
    .portal-left-panel,
    .portal-favorites-card {
        border: 1px solid rgba(255, 255, 255, .78);
        background: rgba(255, 255, 255, .82);
        box-shadow: 0 26px 70px rgba(15, 23, 42, .10);
        backdrop-filter: blur(18px);
        -webkit-backdrop-filter: blur(18px);
    }

    .portal-page.is-dark .portal-hero-main,
    .portal-page.is-dark .portal-hero-side,
    .portal-page.is-dark .portal-search-card,
    .portal-page.is-dark .portal-left-panel,
    .portal-page.is-dark .portal-favorites-card {
        border-color: rgba(148, 163, 184, .18);
        background: rgba(15, 23, 42, .72);
        box-shadow: 0 26px 70px rgba(0, 0, 0, .32);
    }

    .portal-hero-main {
        border-radius: 34px;
        padding: clamp(1.35rem, 3vw, 2.6rem);
        overflow: hidden;
        position: relative;
    }

    .portal-hero-main::after {
        content: '';
        position: absolute;
        right: -8rem;
        top: -8rem;
        width: 22rem;
        height: 22rem;
        border-radius: 999px;
        background: radial-gradient(circle, rgba(250, 189, 2, .24), transparent 68%);
        pointer-events: none;
    }

    .portal-brand-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        flex-wrap: wrap;
        position: relative;
        z-index: 1;
    }

    #appMenu {
        display: inline-flex;
        align-items: center;
        gap: .95rem;
        max-width: 100%;
        padding: .8rem 1rem;
        border-radius: 24px;
        background: rgba(255, 255, 255, .72);
        border: 1px solid rgba(226, 232, 240, .82);
        box-shadow: 0 12px 32px rgba(15, 23, 42, .07);
    }

    .portal-logo-box {
        width: 3.4rem;
        height: 3.4rem;
        border-radius: 20px;
        background: #fff;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: .65rem;
        box-shadow: inset 0 0 0 1px rgba(148, 163, 184, .12), 0 12px 24px rgba(15, 23, 42, .08);
    }

    .portal-logo-box img {
        width: 100%;
        height: 100%;
        object-fit: contain;
    }

    .portal-kicker {
        font-size: .72rem;
        font-weight: 900;
        letter-spacing: .22em;
        text-transform: uppercase;
        color: var(--finea-blue);
    }

    .portal-brand-subtitle {
        margin-top: .15rem;
        font-size: .82rem;
        color: var(--portal-muted);
        font-weight: 700;
    }

    .portal-user-actions {
        display: flex;
        gap: .55rem;
        align-items: center;
        flex-wrap: wrap;
    }

    .portal-chip-action {
        display: inline-flex;
        align-items: center;
        gap: .45rem;
        border-radius: 999px;
        border: 1px solid rgba(148, 163, 184, .18);
        background: rgba(255, 255, 255, .78);
        color: #334155;
        font-size: .76rem;
        font-weight: 800;
        padding: .62rem .82rem;
        transition: all .18s ease;
        text-decoration: none;
    }

    .portal-chip-action:hover {
        transform: translateY(-1px);
        border-color: rgba(29, 43, 87, .22);
    }

    .portal-theme-toggle {
        position: relative;
        width: 4.85rem;
        min-width: 4.85rem;
        padding: .32rem;
        gap: 0;
        justify-content: space-between;
        overflow: hidden;
    }

    .portal-theme-toggle .portal-theme-orbit {
        position: absolute;
        top: .32rem;
        left: .32rem;
        width: 2rem;
        height: 2rem;
        border-radius: 999px;
        background:
            radial-gradient(circle at 33% 30%, #fff7ad 0 16%, transparent 17%),
            radial-gradient(circle, #facc15 0 45%, #f59e0b 70%);
        box-shadow: 0 0 18px rgba(250, 204, 21, .52);
        transition: transform .28s ease, background .28s ease, box-shadow .28s ease;
        z-index: 1;
    }

    .portal-theme-toggle i,
    .portal-theme-toggle span {
        position: relative;
        z-index: 2;
    }

    .portal-theme-toggle i {
        width: 2rem;
        height: 2rem;
        display: grid;
        place-items: center;
        font-size: .86rem;
    }

    .portal-theme-toggle > span:not(.portal-theme-orbit) {
        position: absolute;
        width: 1px;
        height: 1px;
        overflow: hidden;
        clip: rect(0 0 0 0);
    }

    .portal-page.is-dark .portal-theme-toggle {
        background: rgba(15, 23, 42, .9);
        color: #e5edf8;
        border-color: rgba(148, 163, 184, .24);
    }

    .portal-page.is-dark .portal-theme-toggle .portal-theme-orbit {
        transform: translateX(2.18rem);
        background:
            radial-gradient(circle at 64% 35%, rgba(15, 23, 42, .95) 0 26%, transparent 27%),
            radial-gradient(circle at 45% 48%, #e2e8f0 0 44%, #94a3b8 74%);
        box-shadow: 0 0 22px rgba(191, 219, 254, .42);
    }

    .portal-page.is-dark .portal-chip-action:not(.portal-chip-danger),
    .portal-page.is-dark #appMenu,
    .portal-page.is-dark .portal-stat-card,
    .portal-page.is-dark .portal-search-result-item,
    .portal-page.is-dark .portal-favorite-card,
    .portal-page.is-dark .portal-module-card {
        background: rgba(15, 23, 42, .72);
        border-color: rgba(148, 163, 184, .18);
        color: #e5edf8;
    }

    .portal-page.is-dark .portal-kicker,
    .portal-page.is-dark .portal-brand-subtitle,
    .portal-page.is-dark .portal-hero-copy,
    .portal-page.is-dark .portal-stat-label,
    .portal-page.is-dark .portal-search-inline-help,
    .portal-page.is-dark .portal-search-meta,
    .portal-page.is-dark .portal-favorites-copy,
    .portal-page.is-dark .portal-favorite-subtitle {
        color: #94a3b8;
    }

    .portal-page.is-dark .portal-title,
    .portal-page.is-dark .portal-side-title,
    .portal-page.is-dark .portal-favorites-title,
    .portal-page.is-dark .portal-favorite-title,
    .portal-page.is-dark .portal-module-title,
    .portal-page.is-dark .portal-search-result-title,
    .portal-page.is-dark .portal-group-title,
    .portal-page.is-dark .portal-stat-value {
        color: #ffffff;
    }

    .portal-page.is-dark .portal-search-input {
        background: rgba(2, 6, 23, .48);
        border-color: rgba(148, 163, 184, .18);
        color: #f8fafc;
    }

    .portal-page.is-dark .portal-module-subtitle,
    .portal-page.is-dark .portal-module-group-pill,
    .portal-page.is-dark .portal-search-result-meta,
    .portal-page.is-dark .portal-group-count {
        color: #cbd5e1;
    }

    .portal-page.is-dark .portal-search-results {
        background: rgba(2, 6, 23, .96);
        border-color: rgba(148, 163, 184, .22);
        box-shadow: 0 24px 60px rgba(0, 0, 0, .45);
    }

    .portal-chip-danger {
        background: #dc2626;
        color: #fff;
        border-color: #dc2626;
    }

    .portal-chip-danger:hover {
        background: #b91c1c;
        color: #fff;
    }

    .portal-title {
        margin-top: 2.4rem;
        max-width: 850px;
        font-size: clamp(2.3rem, 5vw, 5.8rem);
        line-height: .92;
        letter-spacing: -.07em;
        color: var(--portal-ink);
        font-weight: 950;
        position: relative;
        z-index: 1;
    }

    .portal-title span {
        color: var(--finea-blue);
        position: relative;
        white-space: nowrap;
    }

    .portal-title span::after {
        content: '';
        position: absolute;
        left: .04em;
        right: .04em;
        bottom: .05em;
        height: .18em;
        background: rgba(250, 189, 2, .46);
        z-index: -1;
        border-radius: 999px;
    }

    .portal-hero-copy {
        margin-top: 1.15rem;
        max-width: 760px;
        color: #475569;
        font-size: clamp(.98rem, 1.6vw, 1.16rem);
        line-height: 1.65;
        font-weight: 650;
        position: relative;
        z-index: 1;
    }

    .portal-hero-stats {
        margin-top: 1.65rem;
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: .85rem;
        position: relative;
        z-index: 1;
    }

    .portal-stat-card {
        border-radius: 24px;
        padding: 1rem;
        background: rgba(248, 250, 252, .82);
        border: 1px solid rgba(226, 232, 240, .76);
    }

    .portal-stat-value {
        font-size: 1.45rem;
        font-weight: 950;
        color: var(--finea-blue);
        line-height: 1;
    }

    .portal-stat-label {
        margin-top: .35rem;
        font-size: .74rem;
        font-weight: 850;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: .08em;
    }

    .portal-hero-side {
        border-radius: 34px;
        padding: 1.25rem;
        display: grid;
        align-content: space-between;
        gap: 1rem;
        background: linear-gradient(145deg, rgba(29, 43, 87, .96), rgba(15, 23, 42, .94));
        color: #fff;
        overflow: hidden;
        position: relative;
    }

    .portal-hero-side::after {
        content: '';
        position: absolute;
        width: 16rem;
        height: 16rem;
        right: -7rem;
        bottom: -7rem;
        border-radius: 50%;
        background: rgba(250, 189, 2, .18);
    }

    .portal-side-title {
        font-size: 1.15rem;
        font-weight: 950;
        position: relative;
        z-index: 1;
    }

    .portal-side-text {
        margin-top: .45rem;
        color: rgba(255, 255, 255, .72);
        font-size: .86rem;
        line-height: 1.55;
        position: relative;
        z-index: 1;
    }

    .portal-side-stack {
        display: grid;
        gap: .7rem;
        position: relative;
        z-index: 1;
    }

    .portal-side-item {
        display: grid;
        grid-template-columns: auto 1fr;
        gap: .72rem;
        align-items: center;
        padding: .88rem;
        border-radius: 22px;
        background: rgba(255, 255, 255, .08);
        border: 1px solid rgba(255, 255, 255, .10);
    }

    .portal-side-item i {
        width: 2.4rem;
        height: 2.4rem;
        border-radius: 16px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: rgba(250, 189, 2, .18);
        color: var(--finea-gold);
    }

    .portal-side-item strong {
        display: block;
        font-size: .86rem;
    }

    .portal-side-item span {
        display: block;
        margin-top: .12rem;
        font-size: .74rem;
        color: rgba(255, 255, 255, .66);
    }



    .portal-weather-card {
        position: relative;
        z-index: 1;
        min-height: 224px;
        border-radius: 28px;
        overflow: hidden;
        padding: 1rem;
        color: #0f172a;
        background:
            radial-gradient(circle at 18% 18%, rgba(255, 255, 255, .98), rgba(255, 255, 255, .72) 28%, transparent 46%),
            linear-gradient(135deg, rgba(239, 246, 255, .98) 0%, rgba(219, 234, 254, .96) 46%, rgba(241, 245, 249, .98) 100%);
        border: 1px solid rgba(255, 255, 255, .72);
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, .86), 0 18px 44px rgba(15, 23, 42, .18);
        transition: background .35s ease, color .35s ease, border-color .35s ease;
    }

    .portal-weather-card::before,
    .portal-weather-card::after {
        content: '';
        position: absolute;
        pointer-events: none;
    }

    .portal-weather-card::before {
        inset: -40% -18% auto auto;
        width: 14rem;
        height: 14rem;
        border-radius: 999px;
        background: radial-gradient(circle, rgba(250, 189, 2, .42), rgba(250, 189, 2, .08) 55%, transparent 70%);
        filter: blur(.2px);
    }

    .portal-weather-card::after {
        left: -2.4rem;
        right: -2.4rem;
        bottom: -4rem;
        height: 9rem;
        background:
            radial-gradient(ellipse at 18% 40%, rgba(255, 255, 255, .74) 0 34%, transparent 36%),
            radial-gradient(ellipse at 42% 46%, rgba(255, 255, 255, .66) 0 31%, transparent 33%),
            radial-gradient(ellipse at 70% 38%, rgba(255, 255, 255, .58) 0 30%, transparent 32%);
        opacity: .82;
    }

    .portal-weather-card.is-rainy {
        background:
            radial-gradient(circle at 85% 12%, rgba(148, 163, 184, .46), transparent 9rem),
            linear-gradient(135deg, #e2e8f0 0%, #cbd5e1 42%, #94a3b8 100%);
    }

    .portal-weather-card.is-clear {
        background:
            radial-gradient(circle at 76% 13%, rgba(254, 240, 138, .98) 0 4.8rem, rgba(250, 204, 21, .2) 4.9rem, transparent 10rem),
            linear-gradient(135deg, #dbeafe 0%, #bfdbfe 44%, #fef3c7 100%);
    }

    .portal-weather-card.is-cloudy {
        background:
            radial-gradient(ellipse at 16% 22%, rgba(255,255,255,.78) 0 7rem, transparent 7.2rem),
            radial-gradient(ellipse at 72% 18%, rgba(226,232,240,.9) 0 8rem, transparent 8.2rem),
            linear-gradient(135deg, #eff6ff 0%, #dbeafe 45%, #cbd5e1 100%);
    }

    .portal-weather-card.is-stormy {
        color: #f8fafc;
        border-color: rgba(148, 163, 184, .38);
        background:
            radial-gradient(circle at 82% 8%, rgba(250, 204, 21, .55), transparent 6rem),
            linear-gradient(135deg, #111827 0%, #334155 46%, #0f172a 100%);
    }

    .portal-weather-card.is-foggy {
        background:
            repeating-linear-gradient(172deg, rgba(255,255,255,.28) 0 14px, rgba(255,255,255,.05) 15px 30px),
            linear-gradient(135deg, #f8fafc 0%, #e2e8f0 45%, #cbd5e1 100%);
    }

    .portal-weather-card.is-cloudy::before,
    .portal-weather-card.is-foggy::before {
        opacity: .32;
    }

    .portal-weather-card.is-stormy .portal-weather-kicker,
    .portal-weather-card.is-stormy .portal-weather-city,
    .portal-weather-card.is-stormy .portal-weather-status,
    .portal-weather-card.is-stormy .portal-weather-temp,
    .portal-weather-card.is-stormy .portal-weather-note {
        color: #f8fafc;
    }

    .portal-weather-card.is-stormy .portal-weather-pill,
    .portal-weather-card.is-stormy .portal-weather-forecast {
        background: rgba(15, 23, 42, .42);
        border-color: rgba(226, 232, 240, .18);
    }

    .portal-weather-card.is-stormy .portal-weather-pill span,
    .portal-weather-card.is-stormy .portal-weather-forecast-title,
    .portal-weather-card.is-stormy .portal-weather-forecast-slot small {
        color: #cbd5e1;
    }

    .portal-weather-card.is-stormy .portal-weather-pill strong,
    .portal-weather-card.is-stormy .portal-weather-forecast-slot b {
        color: #f8fafc;
    }

    .portal-page.is-dark .portal-weather-card.is-clear {
        color: #e5edf8;
        background:
            radial-gradient(circle at 80% 12%, rgba(226, 232, 240, .82) 0 3.8rem, rgba(96, 165, 250, .08) 4rem, transparent 9rem),
            linear-gradient(135deg, #07111f 0%, #0f2745 48%, #111827 100%);
    }

    .portal-page.is-dark .portal-weather-card.is-cloudy,
    .portal-page.is-dark .portal-weather-card.is-foggy {
        color: #e5edf8;
        background:
            radial-gradient(ellipse at 20% 20%, rgba(148,163,184,.2) 0 7rem, transparent 7.2rem),
            linear-gradient(135deg, #0f172a 0%, #1e293b 48%, #020617 100%);
    }

    .portal-page.is-dark .portal-weather-card.is-rainy {
        color: #e5edf8;
        background:
            radial-gradient(circle at 80% 8%, rgba(96, 165, 250, .26), transparent 8rem),
            linear-gradient(135deg, #020617 0%, #1e293b 42%, #334155 100%);
    }

    .portal-page.is-dark .portal-weather-kicker,
    .portal-page.is-dark .portal-weather-city,
    .portal-page.is-dark .portal-weather-status,
    .portal-page.is-dark .portal-weather-temp,
    .portal-page.is-dark .portal-weather-note {
        color: #e5edf8;
    }

    .portal-page.is-dark .portal-weather-pill,
    .portal-page.is-dark .portal-weather-worksite,
    .portal-page.is-dark .portal-weather-forecast,
    .portal-page.is-dark .portal-weather-forecast-slot {
        background: rgba(15, 23, 42, .42);
        border-color: rgba(226, 232, 240, .16);
    }

    .portal-page.is-dark .portal-weather-pill span,
    .portal-page.is-dark .portal-weather-worksite span,
    .portal-page.is-dark .portal-weather-forecast-title,
    .portal-page.is-dark .portal-weather-forecast-slot small {
        color: #cbd5e1;
    }

    .portal-page.is-dark .portal-weather-pill strong,
    .portal-page.is-dark .portal-weather-worksite strong,
    .portal-page.is-dark .portal-weather-forecast-slot b {
        color: #f8fafc;
    }

    .portal-weather-content {
        position: relative;
        z-index: 2;
        display: grid;
        gap: .85rem;
    }

    .portal-weather-top {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: .85rem;
    }

    .portal-weather-kicker {
        display: inline-flex;
        align-items: center;
        gap: .42rem;
        color: #334155;
        font-size: .68rem;
        font-weight: 950;
        letter-spacing: .13em;
        text-transform: uppercase;
    }

    .portal-weather-city {
        margin-top: .32rem;
        font-size: 1.08rem;
        line-height: 1.05;
        font-weight: 950;
        color: #0f172a;
    }

    .portal-weather-status {
        margin-top: .22rem;
        font-size: .76rem;
        color: #475569;
        font-weight: 800;
    }

    .portal-weather-temp {
        display: flex;
        align-items: flex-start;
        gap: .22rem;
        white-space: nowrap;
        color: #0f172a;
    }

    .portal-weather-temp strong {
        font-size: 3rem;
        line-height: .82;
        font-weight: 950;
        letter-spacing: -.08em;
    }

    .portal-weather-temp span {
        margin-top: .05rem;
        font-size: 1.12rem;
        font-weight: 950;
    }

    .portal-weather-visual {
        position: absolute;
        right: 1rem;
        bottom: 1.15rem;
        width: 7.4rem;
        height: 5.2rem;
        z-index: 1;
        opacity: .95;
    }

    .portal-weather-sun {
        position: absolute;
        right: .6rem;
        top: .05rem;
        width: 3.4rem;
        height: 3.4rem;
        border-radius: 999px;
        background: radial-gradient(circle, #facc15 0 42%, #f59e0b 70%);
        box-shadow: 0 0 34px rgba(250, 204, 21, .72);
        animation: portalWeatherFloat 5.8s ease-in-out infinite;
    }

    .portal-weather-moon {
        position: absolute;
        right: .7rem;
        top: .2rem;
        width: 3.15rem;
        height: 3.15rem;
        border-radius: 999px;
        opacity: 0;
        background:
            radial-gradient(circle at 63% 36%, rgba(15,23,42,.96) 0 28%, transparent 29%),
            radial-gradient(circle, #e2e8f0 0 46%, #94a3b8 78%);
        box-shadow: 0 0 32px rgba(191, 219, 254, .42);
        animation: portalWeatherFloat 6.2s ease-in-out infinite;
    }

    .portal-weather-cloud {
        position: absolute;
        left: .1rem;
        bottom: .72rem;
        width: 5.9rem;
        height: 2.2rem;
        border-radius: 999px;
        background: rgba(255, 255, 255, .92);
        box-shadow: 0 10px 24px rgba(15, 23, 42, .10);
        animation: portalWeatherDrift 7.5s ease-in-out infinite;
    }

    .portal-weather-cloud::before,
    .portal-weather-cloud::after {
        content: '';
        position: absolute;
        border-radius: 999px;
        background: rgba(255, 255, 255, .96);
    }

    .portal-weather-cloud::before {
        width: 2.35rem;
        height: 2.35rem;
        left: .85rem;
        top: -1.15rem;
    }

    .portal-weather-cloud::after {
        width: 3.05rem;
        height: 3.05rem;
        left: 2.25rem;
        top: -1.65rem;
    }

    .portal-weather-card.is-rainy .portal-weather-cloud,
    .portal-weather-card.is-rainy .portal-weather-cloud::before,
    .portal-weather-card.is-rainy .portal-weather-cloud::after {
        background: rgba(148, 163, 184, .92);
    }

    .portal-weather-card.is-stormy .portal-weather-cloud,
    .portal-weather-card.is-stormy .portal-weather-cloud::before,
    .portal-weather-card.is-stormy .portal-weather-cloud::after {
        background: rgba(30, 41, 59, .96);
    }

    .portal-weather-rain {
        position: absolute;
        left: 1rem;
        right: 1rem;
        bottom: -.3rem;
        top: 2.8rem;
        opacity: 0;
        background-image:
            linear-gradient(180deg, rgba(59, 130, 246, .0), rgba(59, 130, 246, .68)),
            linear-gradient(180deg, rgba(59, 130, 246, .0), rgba(59, 130, 246, .55)),
            linear-gradient(180deg, rgba(59, 130, 246, .0), rgba(59, 130, 246, .5));
        background-size: 2px 18px, 2px 15px, 2px 21px;
        background-position: 12px 0, 34px 6px, 56px 2px;
        background-repeat: repeat-y;
        transform: rotate(10deg);
        animation: portalWeatherRain .85s linear infinite;
    }

    .portal-weather-lightning {
        position: absolute;
        right: 2.55rem;
        bottom: .15rem;
        width: 1.45rem;
        height: 2.4rem;
        opacity: 0;
        clip-path: polygon(44% 0, 100% 0, 62% 42%, 100% 42%, 24% 100%, 45% 55%, 0 55%);
        background: #fde047;
        filter: drop-shadow(0 0 10px rgba(250, 204, 21, .72));
        animation: portalWeatherFlash 2.4s ease-in-out infinite;
    }

    .portal-weather-card.is-rainy .portal-weather-rain {
        opacity: 1;
    }

    .portal-weather-card.is-stormy .portal-weather-rain,
    .portal-weather-card.is-stormy .portal-weather-lightning {
        opacity: 1;
    }

    .portal-weather-card.is-rainy .portal-weather-sun {
        opacity: .22;
        filter: blur(1px);
    }

    .portal-page.is-dark .portal-weather-card .portal-weather-sun {
        opacity: 0;
    }

    .portal-page.is-dark .portal-weather-card .portal-weather-moon {
        opacity: .95;
    }

    .portal-page.is-dark .portal-weather-card.is-clear .portal-weather-moon {
        opacity: .78;
    }

    .portal-page.is-dark .portal-weather-card.is-rainy .portal-weather-moon,
    .portal-page.is-dark .portal-weather-card.is-stormy .portal-weather-moon {
        opacity: .45;
        filter: blur(.4px);
    }

    .portal-weather-card.is-cloudy .portal-weather-sun {
        opacity: .45;
    }

    .portal-weather-meta {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: .48rem;
        max-width: 78%;
        position: relative;
        z-index: 3;
    }

    .portal-weather-pill {
        border-radius: 18px;
        padding: .55rem .58rem;
        background: rgba(255, 255, 255, .64);
        border: 1px solid rgba(255, 255, 255, .82);
        box-shadow: 0 8px 18px rgba(15, 23, 42, .06);
    }

    .portal-weather-pill span {
        display: block;
        font-size: .62rem;
        font-weight: 950;
        letter-spacing: .08em;
        text-transform: uppercase;
        color: #64748b;
    }

    .portal-weather-pill strong {
        display: block;
        margin-top: .16rem;
        font-size: .82rem;
        color: #0f172a;
        font-weight: 950;
    }
    .portal-ao-widget { display:grid; gap:.75rem; padding:1rem; border:1px solid var(--portal-line); border-radius:18px; background:var(--portal-card); }
    .portal-ao-widget-head { display:flex; align-items:center; justify-content:space-between; gap:.8rem; }
    .portal-ao-widget-title { font-size:.82rem; font-weight:900; color:var(--portal-ink); }
    .portal-ao-widget-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:.45rem; }
    .portal-ao-widget-stat { padding:.55rem .35rem; border-radius:10px; background:rgba(148,163,184,.11); text-align:center; }
    .portal-ao-widget-stat strong,.portal-ao-widget-stat span { display:block; }
    .portal-ao-widget-stat strong { color:var(--portal-ink); font-size:1rem; }
    .portal-ao-widget-stat span { margin-top:.15rem; color:var(--portal-muted); font-size:.55rem; font-weight:800; text-transform:uppercase; }
    .portal-ao-widget-link { color:#1d2b57; font-size:.7rem; font-weight:900; text-decoration:none; }
    .portal-page.is-dark .portal-ao-widget-link { color:#facc15; }

    .portal-weather-worksite {
        max-width: 78%;
        position: relative;
        z-index: 3;
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: .36rem .7rem;
        margin-top: -.38rem;
        padding: .42rem .58rem;
        border-radius: 14px;
        background: rgba(255, 255, 255, .48);
        border: 1px solid rgba(255, 255, 255, .72);
        box-shadow: 0 6px 16px rgba(15, 23, 42, .04);
        color: #475569;
        font-size: .64rem;
        line-height: 1.2;
        font-weight: 800;
    }

    .portal-weather-worksite-label {
        flex: 1 0 100%;
        color: #64748b;
        font-size: .58rem;
        font-weight: 950;
        letter-spacing: .08em;
        text-transform: uppercase;
    }

    .portal-weather-worksite span {
        display: inline-flex;
        align-items: center;
        gap: .22rem;
        min-width: 0;
    }

    .portal-weather-worksite strong {
        color: #0f172a;
        font-weight: 950;
        white-space: nowrap;
    }

    .portal-weather-worksite strong.impact-low {
        color: #166534;
    }

    .portal-weather-worksite strong.impact-medium {
        color: #92400e;
    }

    .portal-weather-worksite strong.impact-high {
        color: #9f1239;
    }

    .portal-weather-card.is-stormy .portal-weather-worksite {
        background: rgba(15, 23, 42, .42);
        border-color: rgba(226, 232, 240, .18);
        color: #cbd5e1;
    }

    .portal-weather-card.is-stormy .portal-weather-worksite strong {
        color: #f8fafc;
    }

    .portal-page.is-dark .portal-weather-worksite strong.impact-low {
        color: #86efac;
    }

    .portal-page.is-dark .portal-weather-worksite strong.impact-medium {
        color: #fcd34d;
    }

    .portal-page.is-dark .portal-weather-worksite strong.impact-high {
        color: #fda4af;
    }

    .portal-page.is-dark .portal-weather-worksite-label,
    .portal-weather-card.is-stormy .portal-weather-worksite-label {
        color: #cbd5e1;
    }

    .portal-weather-forecast {
        max-width: 78%;
        position: relative;
        z-index: 3;
        display: grid;
        gap: .36rem;
        padding: .58rem .62rem;
        border-radius: 18px;
        background: rgba(255, 255, 255, .52);
        border: 1px solid rgba(255, 255, 255, .78);
        box-shadow: 0 8px 18px rgba(15, 23, 42, .05);
    }

    .portal-weather-forecast-title {
        font-size: .6rem;
        line-height: 1;
        color: #64748b;
        font-weight: 950;
        letter-spacing: .09em;
        text-transform: uppercase;
    }

    .portal-weather-forecast-slots {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: .38rem;
    }

    .portal-weather-forecast-slot {
        min-width: 0;
        border-radius: 14px;
        padding: .42rem .48rem;
        background: rgba(248, 250, 252, .7);
        border: 1px solid rgba(226, 232, 240, .7);
    }

    .portal-weather-forecast-slot b,
    .portal-weather-forecast-slot small {
        display: block;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .portal-weather-forecast-slot b {
        color: #0f172a;
        font-size: .76rem;
        font-weight: 950;
    }

    .portal-weather-forecast-slot small {
        margin-top: .12rem;
        color: #475569;
        font-size: .64rem;
        font-weight: 800;
    }

    .portal-weather-note {
        max-width: 74%;
        font-size: .68rem;
        line-height: 1.35;
        color: #475569;
        font-weight: 750;
        position: relative;
        z-index: 3;
    }

    @media (max-width: 520px) {
        .portal-weather-worksite {
            max-width: 100%;
            gap: .3rem .55rem;
        }
    }

    @keyframes portalWeatherFloat {

        0%,
        100% {
            transform: translateY(0) scale(1);
        }

        50% {
            transform: translateY(-5px) scale(1.03);
        }
    }

    @keyframes portalWeatherDrift {

        0%,
        100% {
            transform: translateX(0);
        }

        50% {
            transform: translateX(8px);
        }
    }

    @keyframes portalWeatherRain {
        from {
            background-position: 12px -20px, 34px -10px, 56px -25px;
        }

        to {
            background-position: 12px 22px, 34px 28px, 56px 19px;
        }
    }

    @keyframes portalWeatherFlash {
        0%,
        76%,
        100% {
            transform: translateY(0) scale(.96);
            filter: drop-shadow(0 0 4px rgba(250, 204, 21, .28));
        }

        80%,
        84% {
            transform: translateY(-2px) scale(1.08);
            filter: drop-shadow(0 0 18px rgba(250, 204, 21, .9));
        }
    }

    .portal-search-shell {
        margin-top: 1.15rem;
        position: relative;
        z-index: 60;
    }

    .portal-search-card {
        border-radius: 30px;
        padding: 1rem;
        position: relative;
        overflow: visible;
    }

    .portal-search-row {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        gap: .85rem;
        align-items: center;
    }

    .portal-search-input-wrap {
        position: relative;
        z-index: 62;
    }

    .portal-search-input {
        width: 100%;
        border: 1px solid rgba(148, 163, 184, .28);
        background: linear-gradient(180deg, #fff 0%, #f8fafc 100%);
        border-radius: 24px;
        min-height: 66px;
        padding: 0 4rem 0 3.35rem;
        font-size: 1rem;
        color: var(--portal-ink);
        outline: none;
        transition: border-color .2s ease, box-shadow .2s ease;
    }

    .portal-search-input:focus {
        border-color: rgba(29, 43, 87, .42);
        box-shadow: 0 0 0 5px rgba(29, 43, 87, .10);
    }

    .portal-search-icon {
        position: absolute;
        left: 1.16rem;
        top: 50%;
        transform: translateY(-50%);
        color: var(--finea-blue);
        font-size: 1rem;
    }

    .portal-search-clear {
        position: absolute;
        right: .78rem;
        top: 50%;
        transform: translateY(-50%);
        border: 0;
        background: #eef2ff;
        color: var(--finea-blue);
        width: 2.25rem;
        height: 2.25rem;
        border-radius: 999px;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }

    .portal-shortcut-chip {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: .42rem;
        border-radius: 999px;
        border: 1px solid rgba(148, 163, 184, .22);
        background: rgba(248, 250, 252, .9);
        color: #475569;
        font-size: .78rem;
        font-weight: 800;
        padding: .82rem 1rem;
        white-space: nowrap;
    }

    .portal-shortcut-chip kbd {
        border-radius: 8px;
        background: #fff;
        border: 1px solid rgba(148, 163, 184, .25);
        box-shadow: inset 0 -1px 0 rgba(148, 163, 184, .22);
        color: #0f172a;
        font-size: .75rem;
        font-weight: 900;
        padding: .2rem .45rem;
    }

    .portal-search-inline-help {
        margin-top: .75rem;
        display: flex;
        justify-content: space-between;
        gap: 1rem;
        align-items: center;
        flex-wrap: wrap;
        color: #64748b;
        font-size: .8rem;
        font-weight: 650;
    }

    .portal-search-inline-help strong {
        color: #0f172a;
    }

    .portal-workspace {
        margin-top: 1.15rem;
        display: grid;
        grid-template-columns: 280px minmax(0, 1fr);
        gap: 1.15rem;
        align-items: start;
    }

    .portal-left-panel {
        position: sticky;
        top: 1rem;
        border-radius: 30px;
        padding: 1rem;
    }

    .portal-panel-title {
        font-size: .78rem;
        font-weight: 950;
        letter-spacing: .12em;
        text-transform: uppercase;
        color: var(--finea-blue);
        margin-bottom: .7rem;
    }

    .portal-filter-chips {
        display: grid;
        gap: .55rem;
    }

    .portal-filter-chip {
        width: 100%;
        border: 1px solid rgba(148, 163, 184, .2);
        background: #fff;
        color: #475569;
        border-radius: 18px;
        padding: .78rem .85rem;
        font-size: .78rem;
        font-weight: 900;
        cursor: pointer;
        transition: all .2s ease;
        text-align: left;
    }

    .portal-filter-chip:hover {
        transform: translateX(2px);
        border-color: rgba(29, 43, 87, .22);
    }

    .portal-filter-chip.is-active {
        background: var(--finea-blue);
        border-color: var(--finea-blue);
        color: #fff;
        box-shadow: 0 14px 26px -18px rgba(29, 43, 87, .95);
    }

    .portal-search-meta {
        margin-top: .8rem;
        padding: .85rem;
        border-radius: 18px;
        background: #f8fafc;
        color: #64748b;
        font-size: .8rem;
        font-weight: 750;
        line-height: 1.45;
    }

    .portal-badge-help-inline {
        margin-top: .85rem;
    }

    .portal-badge-help-card {
        width: 100%;
        padding: 0;
        background: transparent;
        border: 0;
        box-shadow: none;
    }

    .portal-badge-help-actions {
        display: grid;
        gap: .5rem;
    }

    .portal-badge-legend-link {
        display: flex;
        align-items: center;
        gap: .55rem;
        padding: .76rem .85rem;
        border-radius: 18px;
        text-decoration: none;
        font-size: .78rem;
        font-weight: 900;
        color: #475569;
        background: rgba(255, 255, 255, .9);
        border: 1px solid rgba(148, 163, 184, .16);
    }

    #rolesGrid.portal-modules-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(210px, 1fr));
        gap: .95rem;
    }


    .portal-group-section {
        display: grid;
        gap: .85rem;
        grid-column: 1 / -1;
    }

    .portal-group-section+.portal-group-section {
        margin-top: .45rem;
    }

    .portal-group-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        padding: .35rem .25rem .15rem;
    }

    .portal-group-title {
        display: inline-flex;
        align-items: center;
        gap: .62rem;
        color: #0f172a;
        font-size: .94rem;
        font-weight: 950;
        letter-spacing: -.015em;
    }

    .portal-group-title i {
        width: 2.1rem;
        height: 2.1rem;
        border-radius: 14px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: rgba(29, 43, 87, .09);
        color: var(--finea-blue);
    }

    .portal-group-count {
        border-radius: 999px;
        padding: .42rem .68rem;
        background: rgba(248, 250, 252, .88);
        border: 1px solid rgba(148, 163, 184, .18);
        color: #64748b;
        font-size: .72rem;
        font-weight: 900;
    }

    .portal-group-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(210px, 1fr));
        gap: .95rem;
    }

    .portal-module-group-pill {
        display: inline-flex;
        align-items: center;
        gap: .34rem;
        margin-top: .7rem;
        max-width: 100%;
        border-radius: 999px;
        padding: .34rem .58rem;
        background: rgba(29, 43, 87, .06);
        color: var(--finea-blue);
        font-size: .64rem;
        font-weight: 950;
        letter-spacing: .06em;
        text-transform: uppercase;
    }

    .portal-filter-chip span {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        float: right;
        min-width: 1.35rem;
        height: 1.35rem;
        padding: 0 .35rem;
        border-radius: 999px;
        background: rgba(15, 23, 42, .06);
        color: inherit;
        font-size: .68rem;
        font-weight: 950;
    }

    .portal-filter-chip.is-active span {
        background: rgba(255, 255, 255, .18);
    }

    .portal-module-card {
        position: relative;
        min-height: 226px;
        border-radius: 30px;
        padding: 1rem;
        overflow: hidden;
        background: rgba(255, 255, 255, .9);
        border: 1px solid rgba(226, 232, 240, .9);
        box-shadow: 0 18px 45px rgba(15, 23, 42, .07);
        cursor: pointer;
        transition: transform .22s ease, box-shadow .22s ease, border-color .22s ease;
    }

    .portal-module-card::before {
        content: '';
        position: absolute;
        inset: 0;
        background: radial-gradient(circle at top right, var(--module-color-soft, rgba(29, 43, 87, .12)), transparent 58%);
        pointer-events: none;
    }

    .portal-module-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 28px 62px rgba(15, 23, 42, .12);
        border-color: rgba(29, 43, 87, .18);
    }

    .portal-module-card.locked {
        cursor: not-allowed;
        opacity: .55;
        filter: grayscale(.72);
    }

    .portal-module-card-inner {
        position: relative;
        z-index: 1;
        display: flex;
        min-height: 100%;
        flex-direction: column;
    }

    .portal-module-top {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: .75rem;
    }

    .portal-module-icon {
        width: 3.75rem;
        height: 3.75rem;
        border-radius: 24px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        color: #fff;
        font-size: 1.18rem;
        box-shadow: 0 18px 30px rgba(15, 23, 42, .16);
        transition: transform .22s ease;
    }

    .portal-module-card:hover .portal-module-icon {
        transform: scale(1.06) rotate(-2deg);
    }

    .portal-module-title {
        margin-top: 1rem;
        color: #0f172a;
        font-size: .98rem;
        line-height: 1.25;
        font-weight: 950;
    }

    .portal-module-subtitle {
        margin-top: .45rem;
        color: #64748b;
        font-size: .78rem;
        line-height: 1.45;
        min-height: 2.2rem;
        font-weight: 650;
    }

    .portal-module-footer {
        margin-top: auto;
        padding-top: .95rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: .7rem;
    }

    .portal-enter-button {
        border: 0;
        border-radius: 999px;
        padding: .72rem .92rem;
        background: #0f172a;
        color: #fff;
        font-size: .78rem;
        font-weight: 950;
        display: inline-flex;
        align-items: center;
        gap: .45rem;
        transition: all .18s ease;
    }

    .portal-enter-button:hover {
        background: var(--finea-blue);
    }

    .portal-enter-button:disabled {
        background: #e2e8f0;
        color: #64748b;
        cursor: not-allowed;
    }

    .portal-access-pill {
        display: inline-flex;
        align-items: center;
        gap: .35rem;
        border-radius: 999px;
        padding: .48rem .62rem;
        background: #f8fafc;
        color: #64748b;
        font-size: .68rem;
        font-weight: 900;
    }

    .portal-pin-button {
        width: 2.35rem;
        height: 2.35rem;
        border-radius: 999px;
        border: 1px solid rgba(148, 163, 184, .18);
        background: rgba(255, 255, 255, .94);
        color: #94a3b8;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: all .18s ease;
        z-index: 3;
    }

    .portal-pin-button:hover {
        color: var(--finea-blue);
        border-color: rgba(29, 43, 87, .24);
        transform: translateY(-1px);
    }

    .portal-pin-button.is-active {
        color: #d97706;
        background: #fff7ed;
        border-color: rgba(245, 158, 11, .30);
    }

    .usage-hint {
        display: inline-flex;
        align-items: center;
        gap: .4rem;
        margin-top: .72rem;
        padding: .36rem .62rem;
        border-radius: 999px;
        font-size: .66rem;
        font-weight: 900;
        letter-spacing: .04em;
        text-transform: uppercase;
        border: 1px dashed rgba(29, 43, 87, .22);
        color: var(--finea-blue);
        background: rgba(239, 246, 255, .88);
    }

    .module-notification-badge {
        position: absolute;
        top: .85rem;
        right: .85rem;
        min-width: 2.05rem;
        height: 2.05rem;
        padding: 0 .55rem;
        border-radius: 999px;
        color: #fff;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: .78rem;
        font-weight: 950;
        border: 2px solid rgba(255, 255, 255, .95);
        z-index: 4;
    }

    .module-notification-badge.badge-urgent {
        background: linear-gradient(135deg, #dc2626, #b91c1c);
    }

    .module-notification-badge.badge-critical {
        background: linear-gradient(135deg, #7f1d1d, #991b1b 58%, #dc2626 100%);
    }

    .module-notification-badge.badge-watch {
        background: linear-gradient(135deg, #f59e0b, #d97706);
    }

    .module-card.badge-urgent-card,
    .portal-module-card.badge-urgent-card {
        box-shadow: 0 24px 56px rgba(185, 28, 28, .13), 0 18px 45px rgba(15, 23, 42, .06);
    }

    .module-card.badge-critical-card,
    .portal-module-card.badge-critical-card {
        border-color: rgba(127, 29, 29, .18);
        box-shadow: 0 24px 56px rgba(127, 29, 29, .18), 0 18px 45px rgba(15, 23, 42, .06);
    }

    .module-card.badge-watch-card,
    .portal-module-card.badge-watch-card {
        box-shadow: 0 24px 56px rgba(217, 119, 6, .12), 0 18px 45px rgba(15, 23, 42, .06);
    }

    .portal-favorites-shell {
        margin-bottom: 1.15rem;
        display: none;
    }

    .portal-favorites-shell.is-visible {
        display: block;
    }

    .portal-favorites-card {
        border-radius: 30px;
        padding: 1rem;
    }

    .portal-favorites-header {
        display: flex;
        justify-content: space-between;
        gap: 1rem;
        align-items: center;
        flex-wrap: wrap;
        margin-bottom: .85rem;
    }

    .portal-favorites-title {
        font-size: 1rem;
        font-weight: 950;
        color: #0f172a;
    }

    .portal-favorites-copy {
        font-size: .82rem;
        color: #64748b;
        font-weight: 650;
    }

    .portal-favorites-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
        gap: .75rem;
    }

    .portal-favorite-card {
        position: relative;
        border: 1px solid rgba(148, 163, 184, .18);
        background: #fff;
        border-radius: 24px;
        padding: 1rem;
        text-align: left;
        cursor: pointer;
        transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
    }

    .portal-favorite-card:hover {
        transform: translateY(-2px);
        border-color: rgba(29, 43, 87, .25);
        box-shadow: 0 18px 32px rgba(29, 43, 87, .10);
    }

    .portal-favorite-top {
        display: flex;
        align-items: center;
        gap: .8rem;
        margin-bottom: .75rem;
        padding-right: 2.4rem;
    }

    .portal-favorite-icon {
        width: 2.8rem;
        height: 2.8rem;
        border-radius: 18px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        color: #fff;
        font-size: 1rem;
        box-shadow: 0 12px 24px rgba(15, 23, 42, .14);
    }

    .portal-favorite-title {
        font-size: .92rem;
        font-weight: 950;
        color: #111827;
        line-height: 1.3;
    }

    .portal-favorite-subtitle {
        font-size: .76rem;
        color: #64748b;
        line-height: 1.35;
        font-weight: 650;
    }

    .portal-favorites-empty {
        border: 1px dashed rgba(148, 163, 184, .32);
        border-radius: 22px;
        padding: 1rem 1.1rem;
        color: #64748b;
        font-size: .84rem;
        text-align: left;
        background: rgba(255, 255, 255, .72);
    }

    .portal-search-results {
        position: absolute;
        top: calc(100% + .85rem);
        left: 0;
        right: 0;
        z-index: 70;
        display: none;
        overflow: hidden;
        border-radius: 24px;
        border: 1px solid rgba(148, 163, 184, .22);
        background: rgba(255, 255, 255, .98);
        box-shadow: 0 24px 44px rgba(15, 23, 42, .16);
    }

    .portal-search-results.is-open {
        display: block;
    }

    .portal-search-result-section {
        padding: .55rem;
    }

    .portal-search-result-section+.portal-search-result-section {
        border-top: 1px solid rgba(226, 232, 240, .9);
    }

    .portal-search-result-label {
        padding: .55rem .85rem .35rem;
        font-size: .72rem;
        font-weight: 950;
        color: #64748b;
        letter-spacing: .08em;
        text-transform: uppercase;
    }

    .portal-search-result-item {
        width: 100%;
        border: 0;
        background: transparent;
        display: grid;
        grid-template-columns: auto minmax(0, 1fr) auto;
        gap: .85rem;
        align-items: center;
        padding: .85rem;
        border-radius: 18px;
        text-align: left;
        cursor: pointer;
        transition: background .18s ease, transform .18s ease;
    }

    .portal-search-result-item:hover,
    .portal-search-result-item.is-active {
        background: #eef2ff;
        transform: translateY(-1px);
    }

    .portal-search-result-icon {
        width: 2.6rem;
        height: 2.6rem;
        border-radius: 16px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: #e2e8f0;
        color: #1e293b;
        font-size: .95rem;
    }

    .portal-search-result-title {
        font-size: .92rem;
        font-weight: 900;
        color: #0f172a;
        line-height: 1.3;
    }

    .portal-search-result-subtitle {
        margin-top: .18rem;
        font-size: .78rem;
        color: #64748b;
        line-height: 1.35;
    }

    .portal-search-result-meta {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: .35rem .55rem;
        border-radius: 999px;
        font-size: .68rem;
        font-weight: 950;
        letter-spacing: .06em;
        text-transform: uppercase;
        color: #4338ca;
        background: rgba(224, 231, 255, .85);
    }

    .portal-module-mark {
        background: linear-gradient(180deg, rgba(254, 240, 138, .15) 0%, rgba(250, 204, 21, .42) 100%);
        color: inherit;
        padding: 0 .18rem;
        border-radius: .35rem;
    }

    .portal-empty-state {
        grid-column: 1 / -1;
        border: 1px dashed rgba(148, 163, 184, .35);
        background: rgba(255, 255, 255, .82);
        border-radius: 28px;
        padding: 2rem;
        text-align: center;
        color: #64748b;
        box-shadow: 0 14px 32px rgba(15, 23, 42, .05);
    }

    .portal-empty-state strong {
        display: block;
        font-size: 1.05rem;
        color: #0f172a;
        margin-bottom: .45rem;
    }

    @media (max-width: 1180px) {

        .portal-hero,
        .portal-workspace {
            grid-template-columns: 1fr;
        }

        .portal-left-panel {
            position: relative;
            top: auto;
        }

        .portal-filter-chips {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 768px) {
        .portal-page {
            padding: 1rem .8rem 2rem;
        }

        .portal-hero-stats,
        .portal-search-row {
            grid-template-columns: 1fr;
        }

        .portal-title {
            letter-spacing: -.045em;
        }

        .portal-shortcut-chip {
            justify-content: flex-start;
        }

        .portal-filter-chips {
            grid-template-columns: 1fr;
        }

        #rolesGrid.portal-modules-grid {
            grid-template-columns: 1fr;
        }

        .portal-hero-side {
            display: none;
        }
    }

    .portal-module-launcher-backdrop {
        position: fixed;
        inset: 0;
        z-index: 9990;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 1.25rem;
        background:
            radial-gradient(circle at 20% 10%, rgba(250, 189, 2, .18), transparent 28%),
            radial-gradient(circle at 80% 20%, rgba(29, 43, 87, .24), transparent 34%),
            rgba(15, 23, 42, .58);
        backdrop-filter: blur(18px);
        -webkit-backdrop-filter: blur(18px);
    }

    .portal-module-launcher-backdrop.is-open {
        display: flex;
    }

    .portal-module-launcher {
        width: min(760px, 100%);
        border-radius: 2rem;
        border: 1px solid rgba(255, 255, 255, .78);
        background: linear-gradient(145deg, rgba(255, 255, 255, .98), rgba(248, 250, 252, .94));
        box-shadow: 0 34px 90px rgba(15, 23, 42, .34);
        overflow: hidden;
        color: #0f172a;
        transform: translateY(10px) scale(.985);
        animation: portalLauncherIn .24s ease forwards;
    }

    @keyframes portalLauncherIn {
        to {
            transform: translateY(0) scale(1);
        }
    }

    .portal-module-launcher-head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 1rem;
        padding: 1.35rem 1.45rem 1rem;
        border-bottom: 1px solid rgba(148, 163, 184, .22);
        background:
            linear-gradient(135deg, rgba(29, 43, 87, .08), rgba(250, 189, 2, .10)),
            #ffffff;
    }

    .portal-module-launcher-kicker {
        display: inline-flex;
        align-items: center;
        gap: .45rem;
        color: #1d2b57;
        font-size: .76rem;
        font-weight: 900;
        text-transform: uppercase;
        letter-spacing: .11em;
        margin-bottom: .35rem;
    }

    .portal-module-launcher-title {
        font-size: clamp(1.35rem, 2.5vw, 2rem);
        font-weight: 950;
        letter-spacing: -.045em;
        line-height: 1.05;
    }

    .portal-module-launcher-copy {
        margin-top: .45rem;
        color: #64748b;
        font-size: .94rem;
        line-height: 1.55;
        max-width: 38rem;
    }

    .portal-module-launcher-close {
        width: 2.65rem;
        height: 2.65rem;
        border: 0;
        border-radius: 999px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: rgba(15, 23, 42, .06);
        color: #334155;
        cursor: pointer;
        transition: transform .2s ease, background .2s ease;
        flex: 0 0 auto;
    }

    .portal-module-launcher-close:hover {
        transform: rotate(90deg);
        background: rgba(239, 68, 68, .12);
        color: #991b1b;
    }

    .portal-module-launcher-body {
        padding: 1.2rem 1.45rem 1.45rem;
    }

    .portal-module-launcher-input-wrap {
        position: relative;
        display: flex;
        align-items: center;
        gap: .75rem;
        padding: .7rem .85rem;
        border-radius: 1.25rem;
        border: 1px solid rgba(29, 43, 87, .16);
        background: #ffffff;
        box-shadow: 0 16px 42px rgba(15, 23, 42, .08);
    }

    .portal-module-launcher-input-wrap:focus-within {
        border-color: rgba(250, 189, 2, .82);
        box-shadow: 0 18px 48px rgba(250, 189, 2, .18);
    }

    .portal-module-launcher-search-icon {
        width: 2.55rem;
        height: 2.55rem;
        border-radius: 1rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: linear-gradient(135deg, #1d2b57, #0f172a);
        color: #fabd02;
        flex: 0 0 auto;
    }

    .portal-module-launcher-input {
        width: 100%;
        border: 0;
        outline: 0;
        background: transparent;
        color: #0f172a;
        font-size: 1.05rem;
        font-weight: 800;
        min-height: 2.55rem;
    }

    .portal-module-launcher-input::placeholder {
        color: #94a3b8;
        font-weight: 700;
    }

    .portal-module-launcher-enter {
        border: 0;
        border-radius: 1rem;
        padding: .78rem 1rem;
        background: linear-gradient(135deg, #fabd02, #f59e0b);
        color: #1d2b57;
        font-weight: 950;
        cursor: pointer;
        white-space: nowrap;
        box-shadow: 0 12px 28px rgba(245, 158, 11, .25);
    }

    .portal-module-launcher-enter:disabled {
        cursor: not-allowed;
        opacity: .45;
        box-shadow: none;
    }

    .portal-module-launcher-results {
        margin-top: 1rem;
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: .75rem;
        max-height: min(390px, 48vh);
        overflow: auto;
        padding-right: .2rem;
    }

    .portal-module-launcher-result {
        width: 100%;
        border: 1px solid rgba(148, 163, 184, .22);
        border-radius: 1.2rem;
        background: #ffffff;
        padding: .8rem;
        display: flex;
        align-items: center;
        gap: .75rem;
        text-align: left;
        cursor: pointer;
        transition: transform .2s ease, box-shadow .2s ease, border-color .2s ease;
        min-height: 82px;
    }

    .portal-module-launcher-result:hover,
    .portal-module-launcher-result.is-active {
        transform: translateY(-2px);
        border-color: rgba(250, 189, 2, .78);
        box-shadow: 0 16px 36px rgba(15, 23, 42, .12);
    }

    .portal-module-launcher-result.is-locked {
        opacity: .58;
        cursor: not-allowed;
    }

    .portal-module-launcher-icon {
        width: 2.8rem;
        height: 2.8rem;
        border-radius: 1rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        color: white;
        flex: 0 0 auto;
        box-shadow: 0 12px 24px rgba(15, 23, 42, .16);
    }

    .portal-module-launcher-name {
        font-size: .98rem;
        font-weight: 950;
        color: #0f172a;
        line-height: 1.15;
    }

    .portal-module-launcher-desc {
        margin-top: .22rem;
        color: #64748b;
        font-size: .78rem;
        font-weight: 700;
        line-height: 1.35;
    }

    .portal-module-launcher-foot {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: .8rem;
        flex-wrap: wrap;
        margin-top: 1rem;
        color: #64748b;
        font-size: .82rem;
        font-weight: 800;
    }

    .portal-launcher-preference {
        display: inline-flex;
        align-items: center;
        gap: .55rem;
        min-width: 0;
        cursor: pointer;
        user-select: none;
        color: inherit;
    }

    .portal-launcher-preference input {
        position: absolute;
        opacity: 0;
        pointer-events: none;
    }

    .portal-launcher-switch {
        position: relative;
        width: 2.8rem;
        height: 1.55rem;
        border-radius: 999px;
        background: rgba(148, 163, 184, .32);
        border: 1px solid rgba(148, 163, 184, .35);
        flex: 0 0 auto;
        transition: background .18s ease, border-color .18s ease;
    }

    .portal-launcher-switch::after {
        content: '';
        position: absolute;
        top: .19rem;
        left: .2rem;
        width: 1.05rem;
        height: 1.05rem;
        border-radius: 999px;
        background: #ffffff;
        box-shadow: 0 5px 12px rgba(15, 23, 42, .18);
        transition: transform .18s ease;
    }

    .portal-launcher-preference input:checked + .portal-launcher-switch {
        background: linear-gradient(135deg, #fabd02, #f59e0b);
        border-color: rgba(245, 158, 11, .54);
    }

    .portal-launcher-preference input:checked + .portal-launcher-switch::after {
        transform: translateX(1.2rem);
    }

    .portal-module-launcher-foot kbd {
        display: inline-flex;
        min-width: 1.8rem;
        justify-content: center;
        border-radius: .5rem;
        padding: .12rem .38rem;
        background: #f1f5f9;
        color: #334155;
        border: 1px solid rgba(148, 163, 184, .35);
        font-size: .72rem;
    }

    .portal-module-launcher-empty {
        grid-column: 1 / -1;
        border: 1px dashed rgba(148, 163, 184, .45);
        border-radius: 1.2rem;
        padding: 1.1rem;
        color: #64748b;
        background: rgba(248, 250, 252, .85);
        font-weight: 800;
        text-align: center;
    }

    body.portal-theme-dark .portal-module-launcher-backdrop {
        background:
            radial-gradient(circle at 18% 12%, rgba(250, 189, 2, .12), transparent 28%),
            radial-gradient(circle at 82% 22%, rgba(59, 130, 246, .20), transparent 34%),
            rgba(2, 6, 23, .78);
    }

    body.portal-theme-dark .portal-module-launcher {
        border-color: rgba(148, 163, 184, .22);
        background: linear-gradient(145deg, rgba(15, 23, 42, .98), rgba(2, 6, 23, .96));
        color: #f8fafc;
        box-shadow: 0 38px 92px rgba(0, 0, 0, .54);
    }

    body.portal-theme-dark .portal-module-launcher-head {
        border-bottom-color: rgba(148, 163, 184, .18);
        background:
            radial-gradient(circle at 18% 0%, rgba(250, 189, 2, .12), transparent 36%),
            linear-gradient(135deg, rgba(30, 41, 59, .92), rgba(2, 6, 23, .96));
    }

    body.portal-theme-dark .portal-module-launcher-title,
    body.portal-theme-dark .portal-module-launcher-name {
        color: #ffffff;
    }

    body.portal-theme-dark .portal-module-launcher-kicker {
        color: #fde68a;
    }

    body.portal-theme-dark .portal-module-launcher-copy,
    body.portal-theme-dark .portal-module-launcher-desc,
    body.portal-theme-dark .portal-module-launcher-foot,
    body.portal-theme-dark .portal-module-launcher-empty {
        color: #cbd5e1;
    }

    body.portal-theme-dark .portal-module-launcher-close {
        background: rgba(248, 250, 252, .08);
        color: #e2e8f0;
    }

    body.portal-theme-dark .portal-module-launcher-input-wrap,
    body.portal-theme-dark .portal-module-launcher-result,
    body.portal-theme-dark .portal-module-launcher-empty {
        background: rgba(15, 23, 42, .72);
        border-color: rgba(148, 163, 184, .20);
    }

    body.portal-theme-dark .portal-module-launcher-input {
        color: #ffffff;
    }

    body.portal-theme-dark .portal-module-launcher-input::placeholder {
        color: #94a3b8;
    }

    body.portal-theme-dark .portal-module-launcher-foot kbd {
        background: rgba(248, 250, 252, .08);
        color: #e2e8f0;
        border-color: rgba(148, 163, 184, .24);
    }

    @media (max-width: 720px) {
        .portal-module-launcher {
            border-radius: 1.35rem;
        }

        .portal-module-launcher-head,
        .portal-module-launcher-body {
            padding-left: 1rem;
            padding-right: 1rem;
        }

        .portal-module-launcher-input-wrap {
            align-items: stretch;
            flex-wrap: wrap;
        }

        .portal-module-launcher-input {
            min-width: 0;
            flex: 1 1 calc(100% - 3.3rem);
        }

        .portal-module-launcher-enter {
            width: 100%;
        }

        .portal-module-launcher-results {
            grid-template-columns: 1fr;
        }
    }

    /* Charte applicative Finéa : identique au menu global et au dashboard RH. */
    :root {
        --finea-blue: var(--brand-primary, #050505);
        --finea-gold: var(--brand-accent, #ffcc20);
        --portal-brand: var(--brand-accent, #ffcc20);
        --portal-brand-hover: color-mix(in srgb, var(--portal-brand) 92%, #000);
        --portal-brand-ink: var(--brand-primary, #050505);
        --portal-brand-soft: color-mix(in srgb, var(--portal-brand) 12%, #fff);
    }

    .portal-page {
        background:
            radial-gradient(circle at top left, rgb(var(--brand-accent-rgb, 255 204 32) / .19), transparent 34rem),
            radial-gradient(circle at top right, rgba(15, 23, 42, .10), transparent 32rem),
            linear-gradient(135deg, #ffffff 0%, #f8fafc 52%, #fffdf4 100%);
    }

    #appMenu,
    .portal-hero-side {
        background: linear-gradient(145deg, var(--brand-primary, #050505), color-mix(in srgb, var(--brand-primary, #050505) 82%, #fff)) !important;
        border-color: rgb(var(--brand-accent-rgb, 255 204 32) / .48) !important;
        color: #fff !important;
    }

    #appMenu .portal-kicker,
    #appMenu .portal-brand-subtitle,
    .portal-side-kicker,
    .portal-weather-kicker,
    .portal-panel-title,
    .portal-module-launcher-kicker {
        color: var(--portal-brand) !important;
    }

    #appMenu .portal-brand-subtitle { color: #cbd5e1 !important; }
    .portal-logo-box { border: 1px solid rgb(var(--brand-accent-rgb, 255 204 32) / .48); }
    .portal-title span,
    .portal-stat-value,
    .portal-favorites-title { color: var(--portal-brand-ink) !important; }
    .portal-title span::after { background: var(--portal-brand) !important; opacity: .72; }

    .portal-chip-action:not(.portal-chip-danger),
    .portal-shortcut-chip,
    .portal-filter-chip.is-active,
    .portal-enter-button,
    .portal-module-launcher-enter,
    .portal-search-clear,
    .portal-pin-button.is-active {
        background: var(--portal-brand) !important;
        border-color: var(--portal-brand) !important;
        color: var(--portal-brand-ink) !important;
        box-shadow: 0 12px 25px rgb(var(--brand-accent-rgb, 255 204 32) / .22) !important;
    }

    .portal-chip-action:not(.portal-chip-danger):hover,
    .portal-filter-chip.is-active:hover,
    .portal-enter-button:hover,
    .portal-module-launcher-enter:hover {
        background: var(--portal-brand-hover) !important;
        color: var(--portal-brand-ink) !important;
    }

    .portal-chip-danger {
        background: var(--brand-primary, #050505) !important;
        border-color: var(--portal-brand) !important;
        color: var(--portal-brand) !important;
    }

    .portal-module-card,
    .portal-favorite-card,
    .portal-search-card,
    .portal-left-panel,
    .portal-favorites-card {
        border-color: rgb(var(--brand-accent-rgb, 255 204 32) / .30) !important;
    }

    .portal-module-card::before {
        background: radial-gradient(circle at top right, rgb(var(--brand-accent-rgb, 255 204 32) / .19), transparent 60%) !important;
    }

    .portal-module-card:hover,
    .portal-favorite-card:hover {
        border-color: var(--portal-brand) !important;
        box-shadow: 0 26px 58px rgba(15, 23, 42, .14), 0 0 0 1px rgb(var(--brand-accent-rgb, 255 204 32) / .22) !important;
    }

    .portal-module-icon,
    .portal-favorite-icon,
    .portal-module-launcher-icon,
    .portal-group-title i,
    .portal-search-result-icon {
        background: linear-gradient(135deg, var(--portal-brand), color-mix(in srgb, var(--portal-brand) 78%, #000)) !important;
        color: var(--portal-brand-ink) !important;
    }

    .portal-module-group-pill,
    .portal-group-count {
        background: var(--portal-brand-soft) !important;
        border-color: rgb(var(--brand-accent-rgb, 255 204 32) / .42) !important;
        color: #6b5200 !important;
    }

    .portal-filter-chip:hover,
    .portal-badge-legend-link:hover,
    .portal-search-result-item:hover,
    .portal-search-result-item.is-active,
    .portal-module-launcher-result:hover,
    .portal-module-launcher-result.is-active {
        border-color: var(--portal-brand) !important;
        background: var(--portal-brand-soft) !important;
    }

    .portal-search-input:focus,
    .portal-module-launcher-input-wrap:focus-within {
        border-color: var(--portal-brand) !important;
        box-shadow: 0 0 0 4px rgb(var(--brand-accent-rgb, 255 204 32) / .18) !important;
    }

    .portal-module-launcher-backdrop {
        background: rgb(var(--brand-primary-rgb, 5 5 5) / .76) !important;
    }

    .portal-module-launcher {
        border-color: var(--portal-brand) !important;
        box-shadow: 0 36px 90px rgba(0, 0, 0, .42) !important;
    }

    .portal-module-launcher-head {
        color: #fff !important;
        background: linear-gradient(135deg, var(--brand-primary, #050505), color-mix(in srgb, var(--brand-primary, #050505) 82%, #fff)) !important;
        border-bottom: 4px solid var(--portal-brand) !important;
    }

    .portal-module-launcher-title { color: #fff !important; }
    .portal-module-launcher-copy { color: #cbd5e1 !important; }
    .portal-module-launcher-close {
        background: var(--portal-brand) !important;
        color: var(--brand-on-accent, #050505) !important;
        border-color: var(--portal-brand) !important;
    }

    .portal-launcher-switch { border-color: rgb(var(--brand-accent-rgb, 255 204 32) / .62) !important; }
    .portal-launcher-preference input:checked + .portal-launcher-switch { background: var(--portal-brand) !important; }

    body.portal-theme-dark .portal-page {
        background:
            radial-gradient(circle at 12% 0%, rgb(var(--brand-accent-rgb, 255 204 32) / .12), transparent 26rem),
            linear-gradient(135deg, #050505 0%, #111827 55%, #050505 100%) !important;
    }

    body.portal-theme-dark .portal-module-launcher-head {
        background: linear-gradient(135deg, var(--brand-primary, #050505), color-mix(in srgb, var(--brand-primary, #050505) 82%, #fff)) !important;
    }

    body.portal-theme-dark .portal-title span,
    body.portal-theme-dark .portal-stat-value,
    body.portal-theme-dark .portal-favorites-title {
        color: var(--portal-brand) !important;
    }

    body.portal-theme-dark .portal-filter-chip:hover,
    body.portal-theme-dark .portal-badge-legend-link:hover,
    body.portal-theme-dark .portal-search-result-item:hover,
    body.portal-theme-dark .portal-search-result-item.is-active,
    body.portal-theme-dark .portal-module-launcher-result:hover,
    body.portal-theme-dark .portal-module-launcher-result.is-active {
        background: rgb(var(--brand-accent-rgb, 255 204 32) / .14) !important;
        border-color: var(--portal-brand) !important;
        color: #fff !important;
    }

    /* Organisation: explicit opaque surface/foreground pairs, including counters. */
    body.portal-theme-dark .portal-left-panel {
        background:#172337!important;color:#f5f8fc!important;border:1px solid #52647e!important;
    }
    body.portal-theme-dark .portal-left-panel .portal-panel-title {color:#e2e8f0!important}
    body.portal-theme-dark .portal-left-panel :is(.portal-filter-chip,.portal-badge-legend-link,.portal-search-meta) {
        background:#202e43!important;color:#f5f8fc!important;border:1px solid #52647e!important;
    }
    body.portal-theme-dark .portal-left-panel .portal-filter-chip {
        display:flex;align-items:center;gap:.45rem;
    }
    body.portal-theme-dark .portal-left-panel .portal-filter-chip span {
        margin-left:auto;background:#0d1727!important;color:#e2e8f0!important;border:1px solid #52647e!important;
    }
    body.portal-theme-dark .portal-left-panel :is(.portal-filter-chip,.portal-badge-legend-link):hover {
        background:#30435d!important;color:#fff!important;border-color:#a5c7ff!important;
    }
    body.portal-theme-dark .portal-left-panel .portal-filter-chip.is-active,
    body.portal-theme-dark .portal-left-panel .portal-filter-chip.is-active:hover {
        background:var(--brand-accent,#d71920)!important;color:var(--brand-on-accent,#fff)!important;border-color:var(--brand-on-accent,#fff)!important;
    }
    body.portal-theme-dark .portal-left-panel .portal-filter-chip.is-active span {
        background:var(--brand-on-accent,#fff)!important;color:var(--brand-accent,#d71920)!important;border-color:transparent!important;
    }
    body.portal-theme-dark .portal-left-panel :is(.portal-filter-chip,.portal-badge-legend-link) i,
    body.portal-theme-dark .portal-left-panel .portal-badge-legend-link span {color:inherit!important}
    body.portal-theme-dark .portal-left-panel :is(button,a):focus-visible {
        outline:2px solid #a5c7ff!important;outline-offset:3px;
    }
</style>
<div class="portal-page">
    <div class="portal-shell">
        <section class="portal-hero">
            <div class="portal-hero-main">
                <div class="portal-brand-row">
                    <div id="appMenu">
                        <?php if ($portalIconUrl !== ''): ?>
                            <span class="portal-logo-box md:hidden">
                                <img src="<?= htmlspecialchars($portalIconUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Icône Finéa">
                            </span>
                        <?php endif; ?>
                        <?php if ($portalLogoUrl !== ''): ?>
                            <span class="portal-logo-box hidden md:inline-flex" style="width: 8.8rem;">
                                <img src="<?= htmlspecialchars($portalLogoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Logo Finéa">
                            </span>
                        <?php endif; ?>
                        <div>
                            <div class="portal-kicker">Portail applicatif</div>
                            <div class="portal-brand-subtitle"><?= htmlspecialchars($portalCompanyName, ENT_QUOTES, 'UTF-8') ?> · Tous vos espaces Finéa.</div>
                        </div>
                    </div>
                    <div class="portal-user-actions">
                        <span id="welcomeUser" class="portal-chip-action"></span>
                        <button id="helpBtn" type="button" class="portal-chip-action">
                            <i class="fas fa-question-circle"></i>
                            <span>Aide</span>
                        </button>
                        <button id="portalModuleLauncherOpen" type="button" class="portal-chip-action">
                            <i class="fas fa-bolt"></i>
                            <span>Choix module</span>
                        </button>
                        <button id="portalThemeToggle" type="button" class="portal-chip-action portal-theme-toggle" aria-label="Activer le mode sombre" aria-pressed="false">
                            <span class="portal-theme-orbit" aria-hidden="true"></span>
                            <i class="fas fa-sun" aria-hidden="true"></i>
                            <i class="fas fa-moon" aria-hidden="true"></i>
                            <span>Mode sombre</span>
                        </button>
                        <a href="deconnex.php" class="portal-chip-action portal-chip-danger">
                            <i class="fas fa-power-off"></i>
                            <span>Déconnexion</span>
                        </a>
                    </div>
                </div>

                <?php if ($debugPortalModules): ?>
                    <div class="mt-6 text-left rounded-2xl border border-amber-300 bg-amber-50 p-4 shadow-sm relative z-10">
                        <div class="flex items-center justify-between gap-3 mb-3">
                            <h2 class="text-sm font-bold uppercase tracking-wide text-amber-800">Diagnostic modules portail</h2>
                            <span class="text-xs text-amber-700">Mode debug activé via ?debug_modules=1</span>
                        </div>
                        <pre class="max-h-80 overflow-auto rounded-xl bg-slate-950 text-slate-100 p-4 text-xs leading-5"><?= htmlspecialchars((string)json_encode($modulesPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)) ?></pre>
                    </div>
                <?php endif; ?>

                <h1 class="portal-title">Choisissez votre <span>module</span> sans chercher longtemps.</h1>
                <p class="portal-hero-copy">
                    Une présentation plus proche d’un lanceur applicatif moderne : modules épinglés, alertes visibles, recherche globale, filtres rapides et grille élargie pour exploiter tout l’espace disponible.
                </p>
                <div class="portal-hero-stats">
                    <div class="portal-stat-card">
                        <div class="portal-stat-value"><?= count($modulesPayload) ?></div>
                        <div class="portal-stat-label">Modules actifs</div>
                    </div>
                    <div class="portal-stat-card">
                        <div class="portal-stat-value" id="portalAccessibleCount">—</div>
                        <div class="portal-stat-label">Dans vos accès</div>
                    </div>
                    <div class="portal-stat-card">
                        <div class="portal-stat-value" id="portalAlertCount">—</div>
                        <div class="portal-stat-label">Avec alertes</div>
                    </div>
                </div>
            </div>

            <aside class="portal-hero-side" aria-label="Résumé navigation portail">
                <div>
                    <div class="portal-side-title">Vue d’ensemble instantanée</div>
                    <div class="portal-side-text">L’interface met en avant ce qui compte : vos modules utiles, les nouveautés, les badges d’attente et les entrées fréquentes.</div>
                </div>
                <div id="portalWeatherCard" class="portal-weather-card is-cloudy" aria-live="polite">
                    <div class="portal-weather-visual" aria-hidden="true">
                        <span class="portal-weather-sun"></span>
                        <span class="portal-weather-moon"></span>
                        <span class="portal-weather-cloud"></span>
                        <span class="portal-weather-rain"></span>
                        <span class="portal-weather-lightning"></span>
                    </div>
                    <div class="portal-weather-content">
                        <div class="portal-weather-top">
                            <div>
                                <div class="portal-weather-kicker"><i class="fas fa-location-crosshairs"></i><span>Météo locale</span></div>
                                <div id="portalWeatherCity" class="portal-weather-city">Position en cours…</div>
                                <div id="portalWeatherStatus" class="portal-weather-status">Autorisez la localisation pour une météo précise.</div>
                            </div>
                            <div class="portal-weather-temp"><strong id="portalWeatherTemp">--</strong><span>°C</span></div>
                        </div>
                        <div class="portal-weather-meta">
                            <div class="portal-weather-pill"><span>Ressenti</span><strong id="portalWeatherFeels">--°</strong></div>
                            <div class="portal-weather-pill"><span>Vent</span><strong id="portalWeatherWind">--</strong></div>
                            <div class="portal-weather-pill"><span>Pluie</span><strong id="portalWeatherRain">--</strong></div>
                        </div>
                        <div class="portal-weather-worksite">
                            <span class="portal-weather-worksite-label">Prévision chantier · prochaines 24 h</span>
                            <span>Risque pluie max : <strong id="portalWeatherProbability">--</strong></span>
                            <span>Impact prévu : <strong id="portalWeatherImpact">--</strong></span>
                        </div>
                        <div id="portalWeatherForecast" class="portal-weather-forecast" aria-label="Prévision météo des prochaines heures">
                            <span class="portal-weather-forecast-title">Prévision</span>
                            <div class="portal-weather-forecast-slots">
                                <span class="portal-weather-forecast-slot"><b>--</b><small>--</small></span>
                                <span class="portal-weather-forecast-slot"><b>--</b><small>--</small></span>
                                <span class="portal-weather-forecast-slot"><b>--</b><small>--</small></span>
                            </div>
                        </div>
                        <div id="portalWeatherNote" class="portal-weather-note">Fond clair, lecture rapide, fallback automatique sur Abidjan si le GPS est indisponible.</div>
                    </div>
                </div>

                <?php if (is_array($aoPortalStats)): ?>
                    <div class="portal-ao-widget">
                        <div class="portal-ao-widget-head">
                            <div class="portal-ao-widget-title"><i class="fas fa-binoculars"></i> Veille Appels d Offres</div>
                            <a class="portal-ao-widget-link" href="devis/veille_ao.php">Ouvrir la veille <i class="fas fa-arrow-right"></i></a>
                        </div>
                        <div class="portal-ao-widget-grid">
                            <div class="portal-ao-widget-stat"><strong><?= (int) ($aoPortalStats['total'] ?? 0) ?></strong><span>Nouveaux</span></div>
                            <div class="portal-ao-widget-stat"><strong><?= (int) ($aoPortalStats['pertinents'] ?? 0) ?></strong><span>Pertinents</span></div>
                            <div class="portal-ao-widget-stat"><strong><?= (int) ($aoPortalStats['proches'] ?? 0) ?></strong><span>Proches</span></div>
                            <div class="portal-ao-widget-stat"><strong><?= (int) ($aoPortalStats['attente_dg'] ?? 0) ?></strong><span>Decision DG</span></div>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="portal-side-stack">
                    <div class="portal-side-item"><i class="fas fa-thumbtack"></i>
                        <div><strong>Épinglage</strong><span>Vos 5 modules prioritaires restent au-dessus.</span></div>
                    </div>
                    <div class="portal-side-item"><i class="fas fa-bell"></i>
                        <div><strong>Alertes visibles</strong><span>Les compteurs restent lisibles sur chaque carte.</span></div>
                    </div>
                    <div class="portal-side-item"><i class="fas fa-magnifying-glass"></i>
                        <div><strong>Recherche globale</strong><span>Ctrl + K pour retrouver rapidement module, client ou document.</span></div>
                    </div>
                </div>
            </aside>
        </section>

        <section class="portal-search-shell">
            <div class="portal-search-card">
                <div class="portal-search-row">
                    <div class="portal-search-input-wrap">
                        <i class="fas fa-magnifying-glass portal-search-icon"></i>
                        <input id="portalSearchInput" type="search" class="portal-search-input" placeholder="Rechercher un module, une fonctionnalité ou un mot-clé" autocomplete="off">
                        <button id="portalSearchClear" type="button" class="portal-search-clear" hidden aria-label="Effacer la recherche">
                            <i class="fas fa-xmark"></i>
                        </button>
                        <div id="portalSearchResults" class="portal-search-results" aria-live="polite"></div>
                    </div>
                    <div class="portal-shortcut-chip">
                        <span>Accès rapide</span>
                        <kbd>Ctrl</kbd>
                        <kbd>K</kbd>
                    </div>
                </div>
                <div class="portal-search-inline-help">
                    <span><strong>Recherche globale:</strong> modules, pages internes et clients.</span>
                    <span>Entrée directe si un seul résultat, ou flèches clavier pour naviguer.</span>
                </div>
            </div>
        </section>

        <section class="portal-workspace">
            <aside class="portal-left-panel">
                <div class="portal-panel-title">Organisation</div>
                <div id="portalFilterChips" class="portal-filter-chips"></div>
                <div id="portalSearchMeta" class="portal-search-meta"></div>
                <div class="portal-badge-help-inline">
                    <div class="portal-badge-help-card">
                        <div class="portal-badge-help-actions">
                            <a href="portal_badges_help.php" class="portal-badge-legend-link"><i class="fas fa-circle-info"></i><span>Comprendre les badges</span></a>
                        </div>
                    </div>
                </div>
            </aside>

            <main class="portal-main-panel">
                <div id="portalFavoritesShell" class="portal-favorites-shell">
                    <div class="portal-favorites-card">
                        <div class="portal-favorites-header">
                            <div>
                                <div class="portal-favorites-title">Modules épinglés</div>
                                <div class="portal-favorites-copy">Gardez vos 5 espaces les plus utilisés en haut. Cliquez sur l’épingle d’un module pour l’ajouter ici.</div>
                            </div>
                        </div>
                        <div id="portalFavoritesGrid" class="portal-favorites-grid"></div>
                    </div>
                </div>
                <div class="portal-modules-grid" id="rolesGrid"></div>
            </main>
        </section>
    </div>
</div>
<div id="portalModuleLauncherBackdrop" class="portal-module-launcher-backdrop" aria-hidden="true">
    <section class="portal-module-launcher" role="dialog" aria-modal="true" aria-labelledby="portalModuleLauncherTitle">
        <div class="portal-module-launcher-head">
            <div>
                <div class="portal-module-launcher-kicker"><i class="fas fa-bolt"></i><span>Ouverture rapide</span></div>
                <h2 id="portalModuleLauncherTitle" class="portal-module-launcher-title">Quel module voulez-vous ouvrir ?</h2>
                <p class="portal-module-launcher-copy">Tapez le nom d’un module Finéa. Entrée ouvre directement le meilleur résultat.</p>
            </div>
            <button id="portalModuleLauncherClose" type="button" class="portal-module-launcher-close" aria-label="Fermer la recherche rapide">
                <i class="fas fa-xmark"></i>
            </button>
        </div>
        <div class="portal-module-launcher-body">
            <div class="portal-module-launcher-input-wrap">
                <i class="fas fa-magnifying-glass portal-module-launcher-search-icon"></i>
                <input id="portalModuleLauncherInput" type="search" class="portal-module-launcher-input" placeholder="Ex : décaissement, RH, chantier, logistique..." autocomplete="off">
                <button id="portalModuleLauncherEnter" type="button" class="portal-module-launcher-enter" disabled>
                    <span>Ouvrir</span>
                    <i class="fas fa-arrow-right"></i>
                </button>
            </div>
            <div class="portal-module-launcher-foot">
                <label class="portal-launcher-preference" title="Gerer l'apparition automatique de ce popup">
                    <input id="portalModuleLauncherPreference" type="checkbox">
                    <span class="portal-launcher-switch" aria-hidden="true"></span>
                    <span>Afficher au demarrage</span>
                </label>
                <span id="portalModuleLauncherMeta">Modules accessibles suggérés</span>
                <span><kbd>Entrée</kbd> ouvrir · <kbd>Échap</kbd> fermer</span>
            </div>
            <div id="portalModuleLauncherResults" class="portal-module-launcher-results" aria-live="polite"></div>
        </div>
    </section>
</div>

<script>
    // Slugs de pages autorisées (ex: 'dashboard', 'liste_devis') provenant de la session
    const ALLOWED_SLUGS = <?= $allowedPagesJson ?>;
</script>

<script src="js/help_tour.js"></script>
<script src="js/help_tours_app.js"></script>
<script>
    // Permissions serveur
    const PERMISSIONS = <?= $permissionsJson ?>;
    const MODULES = <?= $modulesJson ?>;
    const FAVORITE_MODULE_IDS = <?= $favoriteModulesJson ?>;
    const MODULE_LAUNCHER_ENABLED = <?= $moduleLauncherEnabledJson ?>;
    const TRACK_MODULE_URL = <?= $trackModuleUrlJson ?>;
    const SAVE_PORTAL_PREFERENCES_URL = <?= $savePortalPreferencesUrlJson ?>;
    const BADGE_COUNTS_URL = <?= $badgeCountsUrlJson ?>;
    const PORTAL_SEARCH_URL = <?= $portalSearchUrlJson ?>;
    const DEBUG_PORTAL_MODULES = <?= $debugPortalModules ? 'true' : 'false' ?>;
    const PORTAL_GROUPS = [{
            id: 'direction',
            label: 'Direction',
            icon: 'fa-chart-line',
            keywords: ['direction générale', 'dashboard direction', 'crm']
        },
        {
            id: 'chantier',
            label: 'Devis & chantiers',
            icon: 'fa-helmet-safety',
            keywords: ['devis', 'chantier', 'offre', 'client', 'debourse', 'déboursé', 'unite', 'unité', 'facturation', 'budgétaire']
        },
        {
            id: 'finance',
            label: 'Finance',
            icon: 'fa-money-bill-transfer',
            keywords: ['feb', 'décaissement', 'decaissement', 'comptabilité', 'comptabilite', 'trésorerie', 'tresorerie', 'besoins récurrents', 'paiement', 'journal', 'grand livre', 'syscohada']
        },
        {
            id: 'rh',
            label: 'Ressources humaines',
            icon: 'fa-users',
            keywords: ['ressources humaines', 'rh', 'employé', 'employe', 'emploi du temps', 'pointage']
        },
        {
            id: 'exploitation',
            label: 'Exploitation terrain',
            icon: 'fa-truck-ramp-box',
            keywords: ['logistique', 'planning chantier', 'fournisseur']
        },
        {
            id: 'collaboration',
            label: 'Collaboration',
            icon: 'fa-comments',
            keywords: ['tâches', 'taches', 'emails', 'mailing', 'carnet', 'documentation', 'archives', 'formation', 'assistance', 'centre de services', 'tickets']
        },
        {
            id: 'systeme',
            label: 'Système',
            icon: 'fa-shield-halved',
            keywords: ['administration', 'assistant ia']
        },
        {
            id: 'autres',
            label: 'Autres',
            icon: 'fa-layer-group',
            keywords: []
        }
    ];

    const PORTAL_FILTERS = [{
            id: 'all',
            label: 'Tous les modules'
        },
        {
            id: 'accessible',
            label: 'Mes accès'
        },
        {
            id: 'new',
            label: 'Nouveaux pour moi'
        },
        {
            id: 'alerts',
            label: 'Avec alertes'
        },
        ...PORTAL_GROUPS.filter(group => group.id !== 'autres').map(group => ({
            id: 'group:' + group.id,
            label: group.label,
            groupId: group.id
        }))
    ];

    function getPortalGroupForModule(moduleDef) {
        const haystack = normalizeSearchValue([
            moduleDef?.id,
            moduleDef?.label,
            moduleDef?.subtitle,
            moduleDef?.slug,
            moduleDef?.target
        ].join(' '));

        return PORTAL_GROUPS.find(group =>
            group.id !== 'autres' && group.keywords.some(keyword => haystack.includes(normalizeSearchValue(keyword)))
        ) || PORTAL_GROUPS.find(group => group.id === 'autres');
    }

    function getPortalGroupById(groupId) {
        return PORTAL_GROUPS.find(group => group.id === groupId) || PORTAL_GROUPS.find(group => group.id === 'autres');
    }

    function getVisibleModuleCountForFilter(filterId) {
        return MODULES.filter(module => {
            const unlocked = hasAny(module.anyOf);
            const pendingBadgeCount = Number(module.pendingBadgeCount || 0);
            const isNeverUsedPersonally = unlocked && Number(module.personalCount || 0) === 0 && pendingBadgeCount === 0;

            if (filterId === 'accessible') return unlocked;
            if (filterId === 'new') return isNeverUsedPersonally;
            if (filterId === 'alerts') return unlocked && pendingBadgeCount > 0;
            if (String(filterId || '').startsWith('group:')) {
                return unlocked && getPortalGroupForModule(module).id === String(filterId).replace('group:', '');
            }
            return true;
        }).length;
    }
    const portalState = {
        query: '',
        filter: 'all',
        favoriteModuleIds: Array.isArray(FAVORITE_MODULE_IDS) ? FAVORITE_MODULE_IDS.slice(0, 5) : [],
        moduleLauncherEnabled: Boolean(MODULE_LAUNCHER_ENABLED),
        globalResults: [],
        activeSearchResultIndex: -1,
        lastSearchRequestId: 0
    };

    if (DEBUG_PORTAL_MODULES) {
        console.log('portal module_list', MODULES);
    }

    function formatPortalBadgeCount(value) {
        return String(value);
    }

    function escapeHtmlAttribute(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function escapeHtmlText(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function normalizeSearchValue(value) {
        return String(value || '')
            .toLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .trim();
    }

    function getSearchTokens(value) {
        return normalizeSearchValue(value)
            .split(/\s+/)
            .filter(Boolean);
    }

    function highlightSearchMatch(value, tokens) {
        const raw = String(value || '');
        if (!raw || !Array.isArray(tokens) || tokens.length === 0) {
            return escapeHtmlText(raw);
        }

        const normalizedChars = [];
        for (const character of raw) {
            const normalized = character.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
            normalizedChars.push(normalized || character.toLowerCase());
        }
        const normalizedText = normalizedChars.join('');
        const ranges = [];

        tokens.forEach(token => {
            let startIndex = 0;
            while (startIndex < normalizedText.length) {
                const foundIndex = normalizedText.indexOf(token, startIndex);
                if (foundIndex === -1) {
                    break;
                }
                ranges.push([foundIndex, foundIndex + token.length]);
                startIndex = foundIndex + token.length;
            }
        });

        if (!ranges.length) {
            return escapeHtmlText(raw);
        }

        ranges.sort((left, right) => left[0] - right[0]);
        const mergedRanges = [];
        ranges.forEach(range => {
            const last = mergedRanges[mergedRanges.length - 1];
            if (!last || range[0] > last[1]) {
                mergedRanges.push(range.slice());
                return;
            }
            last[1] = Math.max(last[1], range[1]);
        });

        let cursor = 0;
        let html = '';
        mergedRanges.forEach(range => {
            html += escapeHtmlText(raw.slice(cursor, range[0]));
            html += '<mark class="portal-module-mark">' + escapeHtmlText(raw.slice(range[0], range[1])) + '</mark>';
            cursor = range[1];
        });
        html += escapeHtmlText(raw.slice(cursor));

        return html;
    }

    function getModuleTokens(module) {
        return getSearchTokens(portalState.query);
    }

    function getFilteredModules() {
        const tokens = getSearchTokens(portalState.query);

        const filtered = MODULES.filter(module => {
            const unlocked = hasAny(module.anyOf);
            const pendingBadgeCount = Number(module.pendingBadgeCount || 0);
            const isNeverUsedPersonally = unlocked && Number(module.personalCount || 0) === 0 && pendingBadgeCount === 0;

            if (portalState.filter === 'accessible' && !unlocked) {
                return false;
            }
            if (portalState.filter === 'new' && !isNeverUsedPersonally) {
                return false;
            }
            if (portalState.filter === 'alerts' && pendingBadgeCount <= 0) {
                return false;
            }
            if (String(portalState.filter || '').startsWith('group:')) {
                const groupId = String(portalState.filter).replace('group:', '');
                if (!unlocked || getPortalGroupForModule(module).id !== groupId) {
                    return false;
                }
            }

            if (!tokens.length) {
                return true;
            }

            const haystack = normalizeSearchValue(module.searchTerms || [module.label, module.subtitle, module.slug, module.target].join(' '));
            return tokens.every(token => haystack.includes(token));
        });

        const favoriteOrderLookup = new window.Map((portalState.favoriteModuleIds || []).map((moduleId, index) => [String(moduleId || '').toLowerCase(), index]));

        filtered.sort((left, right) => {
            const leftFavoriteIndex = favoriteOrderLookup.has(String(left.id || '').toLowerCase()) ? favoriteOrderLookup.get(String(left.id || '').toLowerCase()) : Number.POSITIVE_INFINITY;
            const rightFavoriteIndex = favoriteOrderLookup.has(String(right.id || '').toLowerCase()) ? favoriteOrderLookup.get(String(right.id || '').toLowerCase()) : Number.POSITIVE_INFINITY;

            if (leftFavoriteIndex !== rightFavoriteIndex) {
                return leftFavoriteIndex - rightFavoriteIndex;
            }

            return 0;
        });

        return filtered;
    }

    function renderPortalFilterChips() {
        const holder = document.getElementById('portalFilterChips');
        if (!holder) {
            return;
        }

        holder.innerHTML = PORTAL_FILTERS.map(filter => {
            const count = getVisibleModuleCountForFilter(filter.id);
            const iconMarkup = String(filter.id || '').startsWith('group:') ? '<i class="fas fa-folder-tree"></i> ' : '';
            return `
                <button type="button" class="portal-filter-chip ${portalState.filter === filter.id ? 'is-active' : ''}" aria-pressed="${portalState.filter === filter.id ? 'true' : 'false'}" data-portal-filter="${escapeHtmlAttribute(filter.id)}">${iconMarkup}${escapeHtmlText(filter.label)} <span>${count}</span></button>
            `;
        }).join('');
    }

    function renderPortalSearchMeta(filteredModules) {
        const meta = document.getElementById('portalSearchMeta');
        const clearButton = document.getElementById('portalSearchClear');
        if (clearButton) {
            clearButton.hidden = portalState.query.trim() === '';
        }
        if (!meta) {
            return;
        }

        const totalCount = MODULES.length;
        const visibleCount = filteredModules.length;
        const queryLabel = portalState.query.trim();

        if (queryLabel !== '') {
            meta.textContent = `${visibleCount} module(s) trouvé(s) pour “${queryLabel}”`;
            return;
        }

        if (portalState.filter !== 'all') {
            const currentFilter = PORTAL_FILTERS.find(filter => filter.id === portalState.filter);
            meta.textContent = `${visibleCount} module(s) affiché(s) · ${currentFilter ? currentFilter.label : portalState.filter}`;
            return;
        }

        meta.textContent = `${totalCount} modules disponibles dans le portail`;
    }

    function getFavoriteModules() {
        const favoriteIds = Array.isArray(portalState.favoriteModuleIds) ? portalState.favoriteModuleIds : [];
        return favoriteIds
            .map(moduleId => findModuleById(moduleId))
            .filter(module => module && hasAny(module.anyOf));
    }

    function sanitizeFavoriteModuleIds(moduleIds) {
        const uniqueIds = [];
        const seenIds = new window.Set();

        (Array.isArray(moduleIds) ? moduleIds : []).forEach(moduleId => {
            const normalizedId = String(moduleId || '').trim().toLowerCase();
            const moduleDef = findModuleById(normalizedId);
            if (!moduleDef || !hasAny(moduleDef.anyOf) || seenIds.has(normalizedId)) {
                return;
            }

            seenIds.add(normalizedId);
            uniqueIds.push(normalizedId);
        });

        return uniqueIds.slice(0, 5);
    }

    function persistFavoriteModules() {
        if (!SAVE_PORTAL_PREFERENCES_URL) {
            return Promise.resolve();
        }

        return fetch(SAVE_PORTAL_PREFERENCES_URL, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    favorite_modules: portalState.favoriteModuleIds
                })
            })
            .then(response => response.ok ? response.json() : null)
            .then(data => {
                if (data && data.ok && Array.isArray(data.favorite_modules)) {
                    portalState.favoriteModuleIds = sanitizeFavoriteModuleIds(data.favorite_modules);
                }
            })
            .catch(() => {});
    }

    function persistModuleLauncherPreference(enabled) {
        portalState.moduleLauncherEnabled = Boolean(enabled);

        if (!SAVE_PORTAL_PREFERENCES_URL) {
            return Promise.resolve();
        }

        return fetch(SAVE_PORTAL_PREFERENCES_URL, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    module_launcher_enabled: portalState.moduleLauncherEnabled
                })
            })
            .then(response => response.ok ? response.json() : null)
            .then(data => {
                if (data && data.ok && typeof data.module_launcher_enabled !== 'undefined') {
                    portalState.moduleLauncherEnabled = Boolean(data.module_launcher_enabled);
                    syncModuleLauncherPreferenceControl();
                }
            })
            .catch(() => {});
    }

    function syncModuleLauncherPreferenceControl() {
        const preferenceInput = document.getElementById('portalModuleLauncherPreference');
        if (preferenceInput) {
            preferenceInput.checked = Boolean(portalState.moduleLauncherEnabled);
            const preferenceLabel = preferenceInput.closest('.portal-launcher-preference');
            const preferenceText = preferenceLabel ? preferenceLabel.querySelector('span:last-child') : null;
            if (preferenceLabel) {
                preferenceLabel.title = "Gerer l'apparition automatique de ce popup";
            }
            if (preferenceText) {
                preferenceText.textContent = 'Afficher au demarrage';
            }
        }
    }

    function isFavoriteModule(moduleId) {
        return portalState.favoriteModuleIds.includes(String(moduleId || '').toLowerCase());
    }

    function toggleFavoriteModule(moduleId) {
        const normalizedId = String(moduleId || '').trim().toLowerCase();
        if (!normalizedId) {
            return;
        }

        if (isFavoriteModule(normalizedId)) {
            portalState.favoriteModuleIds = portalState.favoriteModuleIds.filter(id => id !== normalizedId);
        } else {
            portalState.favoriteModuleIds = sanitizeFavoriteModuleIds([normalizedId].concat(portalState.favoriteModuleIds));
        }

        renderFavoriteModules();
        buildModuleCards();
        persistFavoriteModules();
    }

    function renderFavoriteModules() {
        const shell = document.getElementById('portalFavoritesShell');
        const grid = document.getElementById('portalFavoritesGrid');
        if (!shell || !grid) {
            return;
        }

        const favoriteModules = getFavoriteModules();
        shell.classList.add('is-visible');

        if (!favoriteModules.length) {
            grid.innerHTML = '<div class="portal-favorites-empty">Épinglez un module avec l’icône en haut à droite d’une carte pour le garder en accès direct ici.</div>';
            return;
        }

        grid.innerHTML = favoriteModules.map(moduleDef => `
            <div class="portal-favorite-card" data-favorite-enter="${escapeHtmlAttribute(moduleDef.id)}" role="button" tabindex="0">
                <button type="button" class="portal-pin-button is-active" data-favorite-toggle="${escapeHtmlAttribute(moduleDef.id)}" aria-label="Retirer ${escapeHtmlAttribute(moduleDef.label)} des favoris">
                    <i class="fas fa-thumbtack"></i>
                </button>
                <div class="portal-favorite-top">
                    <span class="portal-favorite-icon" style="background: linear-gradient(135deg, ${escapeHtmlAttribute(moduleDef.color)}, #111827);">
                        <i class="fas ${escapeHtmlAttribute(moduleDef.icon)}"></i>
                    </span>
                    <div>
                        <div class="portal-favorite-title">${escapeHtmlText(moduleDef.label)}</div>
                        <div class="portal-favorite-subtitle">${escapeHtmlText(moduleDef.subtitle || 'Module')}</div>
                    </div>
                </div>
                <div class="portal-favorite-subtitle">Entrée directe conservée en haut du portail.</div>
            </div>
        `).join('');
    }

    function getSearchResultIcon(result) {
        if (!result || typeof result !== 'object') {
            return 'fa-magnifying-glass';
        }

        if (result.type === 'client') {
            return 'fa-user-tie';
        }
        if (result.type === 'supplier') {
            return 'fa-truck-field';
        }
        if (result.type === 'chantier') {
            return 'fa-helmet-safety';
        }
        if (result.type === 'devis') {
            return 'fa-file-signature';
        }
        if (result.type === 'form') {
            return 'fa-pen-to-square';
        }
        if (result.type === 'request') {
            return 'fa-file-circle-question';
        }
        if (result.type === 'ticket') {
            return 'fa-ticket';
        }
        if (result.type === 'document') {
            return 'fa-file-lines';
        }
        if (result.type === 'person') {
            return 'fa-user';
        }
        if (result.type === 'location') {
            return 'fa-location-dot';
        }
        if (result.type === 'invoice') {
            return 'fa-receipt';
        }
        if (result.type === 'page') {
            return 'fa-file-lines';
        }
        if (result.type === 'module') {
            const moduleDef = findModuleById(result.id || '');
            return moduleDef ? moduleDef.icon : 'fa-cubes';
        }

        return 'fa-arrow-up-right-from-square';
    }

    function closeGlobalSearchResults() {
        const container = document.getElementById('portalSearchResults');
        portalState.activeSearchResultIndex = -1;
        if (container) {
            container.classList.remove('is-open');
            container.innerHTML = '';
        }
    }

    function groupGlobalSearchResults(results) {
        const indexed = results.map((item, index) => ({ ...item, _searchIndex: index }));
        return {
            navigation: indexed.filter(item => item.type === 'module' || item.type === 'page'),
            form: indexed.filter(item => item.type === 'form'),
            request: indexed.filter(item => item.type === 'request' || item.type === 'ticket'),
            document: indexed.filter(item => item.type === 'document'),
            person: indexed.filter(item => item.type === 'person'),
            organization: indexed.filter(item => item.type === 'client' || item.type === 'supplier'),
            project: indexed.filter(item => item.type === 'chantier' || item.type === 'devis'),
            invoice: indexed.filter(item => item.type === 'invoice'),
            location: indexed.filter(item => item.type === 'location')
        };
    }

    function renderGlobalSearchResults() {
        const container = document.getElementById('portalSearchResults');
        if (!container) {
            return;
        }

        const query = portalState.query.trim();
        if (query.length < 2 || !portalState.globalResults.length) {
            closeGlobalSearchResults();
            return;
        }

        const grouped = groupGlobalSearchResults(portalState.globalResults);
        const labels = {
            navigation: 'Modules et pages internes',
            form: 'Formulaires',
            request: 'Demandes et tickets',
            document: 'Documents',
            person: 'Personnes',
            organization: 'Entreprises',
            project: 'Chantiers et devis',
            invoice: 'Factures',
            location: 'Localisations'
        };
        const tokens = getSearchTokens(query);

        container.innerHTML = ['navigation', 'form', 'request', 'document', 'person', 'organization', 'project', 'invoice', 'location'].map(type => {
            const items = grouped[type];
            if (!items.length) {
                return '';
            }

            const itemMarkup = items.map(item => {
                const resultIndex = item._searchIndex;
                const activeClass = portalState.activeSearchResultIndex === resultIndex ? 'is-active' : '';
                return `
                    <button type="button" class="portal-search-result-item ${activeClass}" data-search-result-index="${resultIndex}">
                        <span class="portal-search-result-icon"><i class="fas ${escapeHtmlAttribute(getSearchResultIcon(item))}"></i></span>
                        <span>
                            <span class="portal-search-result-title">${highlightSearchMatch(item.label || '', tokens)}</span>
                            <span class="portal-search-result-subtitle">${highlightSearchMatch(item.subtitle || '', tokens)}</span>
                        </span>
                        <span class="portal-search-result-meta">${escapeHtmlText(item.meta || type)}</span>
                    </button>
                `;
            }).join('');

            return `
                <div class="portal-search-result-section">
                    <div class="portal-search-result-label">${labels[type]}</div>
                    ${itemMarkup}
                </div>
            `;
        }).join('');

        container.classList.add('is-open');
    }

    function activateGlobalSearchResult(result) {
        if (!result || !result.target) {
            return;
        }

        if (result.type === 'module') {
            const moduleDef = findModuleById(result.id || '');
            if (moduleDef) {
                attemptEnter(moduleDef);
                return;
            }
        }

        window.location.href = result.target;
    }

    function updateActiveGlobalResult(nextIndex) {
        const results = Array.isArray(portalState.globalResults) ? portalState.globalResults : [];
        if (!results.length) {
            portalState.activeSearchResultIndex = -1;
            renderGlobalSearchResults();
            return;
        }

        const maxIndex = results.length - 1;
        if (nextIndex < 0) {
            portalState.activeSearchResultIndex = maxIndex;
        } else if (nextIndex > maxIndex) {
            portalState.activeSearchResultIndex = 0;
        } else {
            portalState.activeSearchResultIndex = nextIndex;
        }

        renderGlobalSearchResults();
    }

    function fetchGlobalSearchResults() {
        const query = portalState.query.trim();
        if (!PORTAL_SEARCH_URL || query.length < 2) {
            portalState.globalResults = [];
            closeGlobalSearchResults();
            return;
        }

        const requestId = portalState.lastSearchRequestId + 1;
        portalState.lastSearchRequestId = requestId;

        fetch(`${PORTAL_SEARCH_URL}?q=${encodeURIComponent(query)}`, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json'
                },
                credentials: 'same-origin'
            })
            .then(response => response.ok ? response.json() : null)
            .then(data => {
                if (requestId !== portalState.lastSearchRequestId) {
                    return;
                }

                portalState.globalResults = data && data.ok && Array.isArray(data.results) ? data.results : [];
                portalState.activeSearchResultIndex = portalState.globalResults.length ? 0 : -1;
                renderGlobalSearchResults();
            })
            .catch(() => {
                if (requestId !== portalState.lastSearchRequestId) {
                    return;
                }

                portalState.globalResults = [];
                closeGlobalSearchResults();
            });
    }

    let searchResultsTimer = null;

    function scheduleGlobalSearch() {
        if (searchResultsTimer) {
            window.clearTimeout(searchResultsTimer);
        }

        searchResultsTimer = window.setTimeout(fetchGlobalSearchResults, 180);
    }

    function findModuleById(moduleId) {
        return MODULES.find(module => String(module.id || '') === String(moduleId || '')) || null;
    }

    function applyBadgeCounts(badges) {
        if (!badges || typeof badges !== 'object') {
            return;
        }

        Object.entries(badges).forEach(([moduleId, badgeDef]) => {
            const module = findModuleById(moduleId);
            if (!module) {
                return;
            }

            module.pendingBadgeCount = Number(badgeDef.count || 0);
            module.pendingBadgeLabel = typeof badgeDef.label === 'string' ? badgeDef.label : '';
            module.pendingBadgeVariant = badgeDef.variant === 'critical' ? 'critical' : (badgeDef.variant === 'urgent' ? 'urgent' : 'watch');
            module.pendingBadgeVolatile = Boolean(badgeDef.volatile);
        });

        buildModuleCards();
    }

    function refreshPortalBadges() {
        if (!BADGE_COUNTS_URL) {
            return;
        }

        fetch(BADGE_COUNTS_URL, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json'
                },
                credentials: 'same-origin'
            })
            .then(response => response.ok ? response.json() : null)
            .then(data => {
                if (data && data.ok && data.badges) {
                    applyBadgeCounts(data.badges);
                }
            })
            .catch(() => {});
    }

    function hasVolatileBadgeModules() {
        return MODULES.some(module => Boolean(module.pendingBadgeVolatile));
    }

    function hasAny(permsArray) {
        // Un module avec anyOf vide est considéré comme ouvert à tous.
        if (!Array.isArray(permsArray) || permsArray.length === 0) return true;
        return permsArray.some(p => PERMISSIONS[p]);
    }

    function hexToRgba(hex, alpha) {
        const normalized = String(hex || '').replace('#', '').trim();
        const full = normalized.length === 3 ? normalized.split('').map(char => char + char).join('') : normalized;
        if (!/^[0-9a-f]{6}$/i.test(full)) {
            return `rgba(29, 43, 87, ${alpha})`;
        }
        const numberValue = parseInt(full, 16);
        const red = (numberValue >> 16) & 255;
        const green = (numberValue >> 8) & 255;
        const blue = numberValue & 255;
        return `rgba(${red}, ${green}, ${blue}, ${alpha})`;
    }

    function updatePortalOverviewStats() {
        const accessibleCount = MODULES.filter(module => hasAny(module.anyOf)).length;
        const alertCount = MODULES.filter(module => hasAny(module.anyOf) && Number(module.pendingBadgeCount || 0) > 0).length;
        const accessibleNode = document.getElementById('portalAccessibleCount');
        const alertNode = document.getElementById('portalAlertCount');
        if (accessibleNode) {
            accessibleNode.textContent = String(accessibleCount);
        }
        if (alertNode) {
            alertNode.textContent = String(alertCount);
        }
    }

    function createPortalModuleCard(m, tokens) {
        const unlocked = hasAny(m.anyOf);
        const personalCount = Number(m.personalCount || 0);
        const pendingBadgeCount = Number(m.pendingBadgeCount || 0);
        const pendingBadgeLabel = typeof m.pendingBadgeLabel === 'string' ? m.pendingBadgeLabel : '';
        const pendingBadgeVariant = m.pendingBadgeVariant === 'critical' ? 'critical' : (m.pendingBadgeVariant === 'urgent' ? 'urgent' : 'watch');
        const hasPendingBadge = unlocked && pendingBadgeCount > 0;
        const isNeverUsedPersonally = unlocked && personalCount === 0 && !hasPendingBadge;
        const moduleGroup = getPortalGroupForModule(m);
        const signalMarkup = isNeverUsedPersonally ?
            `<div class="usage-hint"><i class="fas fa-sparkles"></i><span>Nouveau pour vous</span></div>` :
            '';
        const badgeVisualClass = pendingBadgeVariant === 'critical' ? 'badge-critical' : (pendingBadgeVariant === 'urgent' ? 'badge-urgent' : 'badge-watch');
        const cardBadgeClass = hasPendingBadge ? (pendingBadgeVariant === 'critical' ? 'badge-critical-card' : (pendingBadgeVariant === 'urgent' ? 'badge-urgent-card' : 'badge-watch-card')) : '';
        const badgeMarkup = hasPendingBadge ?
            `<div class="module-notification-badge ${badgeVisualClass}" title="${escapeHtmlAttribute(pendingBadgeLabel)}" aria-label="${escapeHtmlAttribute(formatPortalBadgeCount(pendingBadgeCount) + ' ' + pendingBadgeLabel)}">${formatPortalBadgeCount(pendingBadgeCount)}</div>` :
            '';
        const card = document.createElement('div');
        card.setAttribute('data-module-id', String(m.id || ''));
        card.setAttribute('aria-disabled', unlocked ? 'false' : 'true');
        card.className = unlocked ? `portal-module-card ${isNeverUsedPersonally ? 'soft-signal' : ''} ${cardBadgeClass}` : `portal-module-card locked`;
        card.style.setProperty('--module-color-soft', hexToRgba(m.color || '#1d2b57', 0.14));

        const buttonDisabledAttr = unlocked ? '' : 'disabled aria-disabled="true"';
        const labelHtml = highlightSearchMatch(m.label, tokens);
        const subtitleHtml = highlightSearchMatch(m.subtitle, tokens);
        const favoriteButtonClass = isFavoriteModule(m.id) ? 'portal-pin-button is-active' : 'portal-pin-button';
        const favoriteButtonLabel = isFavoriteModule(m.id) ?
            `Retirer ${m.label} des modules épinglés` :
            `Épingler ${m.label} en haut du portail`;
        const accessLabel = unlocked ? 'Accessible' : 'Bloqué';
        const accessIcon = unlocked ? 'fa-unlock-keyhole' : 'fa-lock';

        card.innerHTML = `${badgeMarkup}
        <div class="portal-module-card-inner">
            <div class="portal-module-top">
                <span class="portal-module-icon" style="background: linear-gradient(135deg, ${escapeHtmlAttribute(m.color)}, #111827);">
                    <i class="fas ${escapeHtmlAttribute(m.icon)}"></i>
                </span>
                <button type="button" class="${favoriteButtonClass}" data-favorite-toggle="${escapeHtmlAttribute(m.id)}" aria-label="${escapeHtmlAttribute(favoriteButtonLabel)}">
                    <i class="fas fa-thumbtack"></i>
                </button>
            </div>
            <div class="portal-module-title">${labelHtml}</div>
            <div class="portal-module-subtitle">${subtitleHtml || 'Module Finéa'}</div>
            <div class="portal-module-group-pill"><i class="fas ${escapeHtmlAttribute(moduleGroup.icon)}"></i>${escapeHtmlText(moduleGroup.label)}</div>
            ${signalMarkup}
            <div class="portal-module-footer">
                <span class="portal-access-pill"><i class="fas ${accessIcon}"></i>${accessLabel}</span>
                <button class="portal-enter-button" ${buttonDisabledAttr}>
                    <span>Ouvrir</span>
                    <i class="fas fa-arrow-right"></i>
                </button>
            </div>
        </div>`;

        if (unlocked) {
            card.addEventListener('click', event => {
                if (event.target.closest('[data-favorite-toggle]')) {
                    return;
                }

                attemptEnter(m);
            });
        }

        return card;
    }

    function groupModulesForDisplay(modules) {
        const grouped = new window.Map();
        modules.forEach(moduleDef => {
            const group = getPortalGroupForModule(moduleDef);
            if (!grouped.has(group.id)) {
                grouped.set(group.id, []);
            }
            grouped.get(group.id).push(moduleDef);
        });

        return PORTAL_GROUPS
            .map(group => ({
                group,
                modules: grouped.get(group.id) || []
            }))
            .filter(entry => entry.modules.length > 0);
    }

    function shouldUseGroupedDisplay() {
        return portalState.query.trim() === '' && !String(portalState.filter || '').startsWith('group:');
    }

    function buildModuleCards() {
        const grid = document.getElementById('rolesGrid');
        const filteredModules = getFilteredModules();
        const tokens = getSearchTokens(portalState.query);

        renderFavoriteModules();
        renderPortalFilterChips();
        renderPortalSearchMeta(filteredModules);
        updatePortalOverviewStats();
        grid.classList.add('portal-modules-grid');
        grid.innerHTML = '';

        if (!filteredModules.length) {
            const emptyState = document.createElement('div');
            emptyState.className = 'portal-empty-state';
            emptyState.innerHTML = `
                <strong>Aucun module ne correspond.</strong>
                <div>Essaie un autre mot-clé, retire un filtre, ou utilise le nom fonctionnel du module.</div>
            `;
            grid.appendChild(emptyState);
            return;
        }

        if (shouldUseGroupedDisplay()) {
            groupModulesForDisplay(filteredModules).forEach(entry => {
                const section = document.createElement('section');
                section.className = 'portal-group-section';
                section.innerHTML = `
                    <div class="portal-group-header">
                        <div class="portal-group-title"><i class="fas ${escapeHtmlAttribute(entry.group.icon)}"></i><span>${escapeHtmlText(entry.group.label)}</span></div>
                        <span class="portal-group-count">${entry.modules.length} module(s)</span>
                    </div>
                    <div class="portal-group-grid"></div>
                `;
                const sectionGrid = section.querySelector('.portal-group-grid');
                entry.modules.forEach(moduleDef => sectionGrid.appendChild(createPortalModuleCard(moduleDef, tokens)));
                grid.appendChild(section);
            });
            return;
        }

        filteredModules.forEach(m => grid.appendChild(createPortalModuleCard(m, tokens)));
    }

    function trackModuleUsage(moduleKey) {
        if (!moduleKey || !TRACK_MODULE_URL) {
            return;
        }

        const payload = JSON.stringify({
            module_key: moduleKey
        });
        if (navigator.sendBeacon) {
            navigator.sendBeacon(TRACK_MODULE_URL, payload);
            return;
        }

        fetch(TRACK_MODULE_URL, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: payload,
            keepalive: true
        }).catch(() => {});
    }

    function attemptEnter(moduleDef) {
        trackModuleUsage(moduleDef.id || '');
        const permissions = PERMISSIONS;
        // Si aucun droit n'est requis, entrée directe.
        if (!Array.isArray(moduleDef.anyOf) || moduleDef.anyOf.length === 0) {
            window.location.href = moduleDef.target;
            return;
        }
        // Mapping minimal -> permission principale pour contrôle (fallback si aucune ne correspond)
        const permissionFallback = moduleDef.anyOf.find(p => permissions[p]);
        if (!permissionFallback) {
            const appBasePath = window.location.pathname.replace(/\/[^/]*$/, '');
            window.location.href = `${appBasePath}/acces_refuse.php?type=general&page=${encodeURIComponent(moduleDef.target)}`;
            return;
        }
        window.location.href = moduleDef.target;
    }


    function getModuleLauncherCandidates(query) {
        const tokens = getSearchTokens(query);
        const favoriteLookup = new window.Map((portalState.favoriteModuleIds || []).map((moduleId, index) => [String(moduleId || '').toLowerCase(), index]));

        return MODULES
            .filter(moduleDef => hasAny(moduleDef.anyOf))
            .filter(moduleDef => {
                if (!tokens.length) {
                    return true;
                }
                const haystack = normalizeSearchValue(moduleDef.searchTerms || [moduleDef.label, moduleDef.subtitle, moduleDef.slug, moduleDef.target].join(' '));
                return tokens.every(token => haystack.includes(token));
            })
            .sort((left, right) => {
                const leftFavorite = favoriteLookup.has(String(left.id || '').toLowerCase()) ? favoriteLookup.get(String(left.id || '').toLowerCase()) : 999;
                const rightFavorite = favoriteLookup.has(String(right.id || '').toLowerCase()) ? favoriteLookup.get(String(right.id || '').toLowerCase()) : 999;
                if (leftFavorite !== rightFavorite) {
                    return leftFavorite - rightFavorite;
                }
                return Number(right.personalCount || 0) - Number(left.personalCount || 0);
            })
            .slice(0, tokens.length ? 8 : 6);
    }

    function openModuleLauncher() {
        const backdrop = document.getElementById('portalModuleLauncherBackdrop');
        const input = document.getElementById('portalModuleLauncherInput');
        if (!backdrop || !input) {
            return;
        }
        backdrop.classList.add('is-open');
        backdrop.setAttribute('aria-hidden', 'false');
        input.value = '';
        syncModuleLauncherPreferenceControl();
        renderModuleLauncherResults('');
        window.setTimeout(() => input.focus(), 60);
    }

    function closeModuleLauncher() {
        const backdrop = document.getElementById('portalModuleLauncherBackdrop');
        if (!backdrop) {
            return;
        }
        backdrop.classList.remove('is-open');
        backdrop.setAttribute('aria-hidden', 'true');
    }

    function renderModuleLauncherResults(query) {
        const results = document.getElementById('portalModuleLauncherResults');
        const enterButton = document.getElementById('portalModuleLauncherEnter');
        const meta = document.getElementById('portalModuleLauncherMeta');
        if (!results) {
            return;
        }

        const tokens = getSearchTokens(query);
        const modules = getModuleLauncherCandidates(query);
        const firstModule = modules[0] || null;

        if (enterButton) {
            enterButton.disabled = !firstModule;
            enterButton.dataset.moduleId = firstModule ? String(firstModule.id || '') : '';
        }
        if (meta) {
            meta.textContent = tokens.length ? `${modules.length} module(s) trouvé(s)` : 'Modules accessibles suggérés';
        }

        if (!modules.length) {
            results.innerHTML = '<div class="portal-module-launcher-empty">Aucun module accessible ne correspond à cette recherche.</div>';
            return;
        }

        results.innerHTML = modules.map((moduleDef, index) => `
            <button type="button" class="portal-module-launcher-result ${index === 0 ? 'is-active' : ''}" data-module-launcher-id="${escapeHtmlAttribute(moduleDef.id)}">
                <span class="portal-module-launcher-icon" style="background: linear-gradient(135deg, ${escapeHtmlAttribute(moduleDef.color || '#1d2b57')}, #111827);">
                    <i class="fas ${escapeHtmlAttribute(moduleDef.icon || 'fa-cube')}"></i>
                </span>
                <span>
                    <span class="portal-module-launcher-name">${highlightSearchMatch(moduleDef.label || 'Module', tokens)}</span>
                    <span class="portal-module-launcher-desc">${highlightSearchMatch(moduleDef.subtitle || 'Module Finéa', tokens)}</span>
                </span>
            </button>
        `).join('');
    }

    function openModuleLauncherSelection(moduleId) {
        const moduleDef = findModuleById(moduleId);
        if (!moduleDef || !hasAny(moduleDef.anyOf)) {
            return;
        }
        closeModuleLauncher();
        attemptEnter(moduleDef);
    }

    function initModuleLauncher() {
        const backdrop = document.getElementById('portalModuleLauncherBackdrop');
        const input = document.getElementById('portalModuleLauncherInput');
        const closeButton = document.getElementById('portalModuleLauncherClose');
        const openButton = document.getElementById('portalModuleLauncherOpen');
        const enterButton = document.getElementById('portalModuleLauncherEnter');
        const preferenceInput = document.getElementById('portalModuleLauncherPreference');
        const results = document.getElementById('portalModuleLauncherResults');
        if (!backdrop || !input || !results) {
            return;
        }

        syncModuleLauncherPreferenceControl();

        input.addEventListener('input', event => {
            renderModuleLauncherResults(String(event.target.value || ''));
        });

        input.addEventListener('keydown', event => {
            if (event.key === 'Enter') {
                event.preventDefault();
                const targetId = enterButton?.dataset.moduleId || results.querySelector('[data-module-launcher-id]')?.getAttribute('data-module-launcher-id') || '';
                if (targetId) {
                    openModuleLauncherSelection(targetId);
                }
                return;
            }

            if (event.key === 'Escape') {
                event.preventDefault();
                closeModuleLauncher();
            }
        });

        results.addEventListener('click', event => {
            const item = event.target.closest('[data-module-launcher-id]');
            if (!item) {
                return;
            }
            openModuleLauncherSelection(item.getAttribute('data-module-launcher-id') || '');
        });

        enterButton?.addEventListener('click', () => {
            const targetId = enterButton.dataset.moduleId || '';
            if (targetId) {
                openModuleLauncherSelection(targetId);
            }
        });

        closeButton?.addEventListener('click', closeModuleLauncher);
        openButton?.addEventListener('click', openModuleLauncher);
        preferenceInput?.addEventListener('change', event => {
            persistModuleLauncherPreference(Boolean(event.target.checked));
        });
        backdrop.addEventListener('click', event => {
            if (event.target === backdrop) {
                closeModuleLauncher();
            }
        });

        if (portalState.moduleLauncherEnabled) {
            window.setTimeout(openModuleLauncher, 650);
        }
    }


    const PORTAL_WEATHER_FALLBACK = {
        latitude: 5.35995,
        longitude: -4.00826,
        label: 'Abidjan'
    };

    const PORTAL_WEATHER_CODES = {
        0: ['Ensoleillé', 'clear'],
        1: ['Principalement clair', 'clear'],
        2: ['Partiellement nuageux', 'cloudy'],
        3: ['Nuageux', 'cloudy'],
        45: ['Brouillard', 'cloudy'],
        48: ['Brouillard givrant', 'cloudy'],
        51: ['Bruine légère', 'rainy'],
        53: ['Bruine modérée', 'rainy'],
        55: ['Bruine dense', 'rainy'],
        61: ['Pluie légère', 'rainy'],
        63: ['Pluie modérée', 'rainy'],
        65: ['Forte pluie', 'rainy'],
        80: ['Averses légères', 'rainy'],
        81: ['Averses modérées', 'rainy'],
        82: ['Averses fortes', 'rainy'],
        95: ['Orage', 'rainy'],
        96: ['Orage avec grêle', 'rainy'],
        99: ['Orage violent', 'rainy']
    };

    function updatePortalWeatherCard(state) {
        const card = document.getElementById('portalWeatherCard');
        if (!card) {
            return;
        }

        const cityEl = document.getElementById('portalWeatherCity');
        const statusEl = document.getElementById('portalWeatherStatus');
        const tempEl = document.getElementById('portalWeatherTemp');
        const feelsEl = document.getElementById('portalWeatherFeels');
        const windEl = document.getElementById('portalWeatherWind');
        const rainEl = document.getElementById('portalWeatherRain');
        const probabilityEl = document.getElementById('portalWeatherProbability');
        const impactEl = document.getElementById('portalWeatherImpact');
        const noteEl = document.getElementById('portalWeatherNote');
        const forecastEl = document.getElementById('portalWeatherForecast');

        if (state.city && cityEl) cityEl.textContent = state.city;
        if (state.status && statusEl) statusEl.textContent = state.status;
        if (typeof state.temperature !== 'undefined' && tempEl) tempEl.textContent = state.temperature;
        if (state.feels && feelsEl) feelsEl.textContent = state.feels;
        if (state.wind && windEl) windEl.textContent = state.wind;
        if (state.rain && rainEl) rainEl.textContent = state.rain;
        if (probabilityEl) probabilityEl.textContent = state.probability || '--';
        if (impactEl) {
            impactEl.textContent = state.impact || '--';
            impactEl.classList.remove('impact-low', 'impact-medium', 'impact-high');
            if (state.impactClass) impactEl.classList.add(state.impactClass);
            impactEl.title = state.impactReason || '';
        }
        if (state.note && noteEl) noteEl.textContent = state.note;
        if (forecastEl && Array.isArray(state.forecast)) {
            const slots = state.forecast.length ? state.forecast.slice(0, 3) : [{
                    time: '--',
                    temp: '--',
                    detail: '--',
                    title: ''
                },
                {
                    time: '--',
                    temp: '--',
                    detail: '--',
                    title: ''
                },
                {
                    time: '--',
                    temp: '--',
                    detail: '--',
                    title: ''
                }
            ];
            forecastEl.innerHTML = `
                <span class="portal-weather-forecast-title">Prévision</span>
                <div class="portal-weather-forecast-slots">
                    ${slots.map(slot => `
                        <span class="portal-weather-forecast-slot" title="${escapeHtmlAttribute(slot.title || '')}">
                            <b>${escapeHtmlText(slot.time || '--')} · ${escapeHtmlText(slot.temp || '--')}</b>
                            <small>${escapeHtmlText(slot.detail || '--')}</small>
                        </span>
                    `).join('')}
                </div>
            `;
        }

        card.classList.remove('is-rainy', 'is-cloudy', 'is-clear', 'is-stormy', 'is-foggy');
        card.classList.add(state.visualClass || 'is-cloudy');
    }

    function getPortalWeatherLabel(weatherCode) {
        return PORTAL_WEATHER_CODES[Number(weatherCode)] || ['Météo locale', 'cloudy'];
    }

    function getPortalWeatherVisualClass(weatherCode, fallbackVisual) {
        const code = Number(weatherCode);
        if ([95, 96, 99].includes(code)) {
            return 'is-stormy';
        }
        if ([45, 48].includes(code)) {
            return 'is-foggy';
        }
        if ([51, 53, 55, 61, 63, 65, 80, 81, 82].includes(code)) {
            return 'is-rainy';
        }
        if ([0, 1].includes(code)) {
            return 'is-clear';
        }
        if ([2, 3].includes(code)) {
            return 'is-cloudy';
        }
        return fallbackVisual === 'clear' ? 'is-clear' : (fallbackVisual === 'rainy' ? 'is-rainy' : 'is-cloudy');
    }

    function buildPortalWeatherForecast(payload) {
        const hourly = payload && payload.hourly ? payload.hourly : {};
        const times = Array.isArray(hourly.time) ? hourly.time : [];
        if (!times.length) {
            return [];
        }

        const now = Date.now();
        const firstFutureIndex = Math.max(0, times.findIndex(value => new Date(value).getTime() >= now - 30 * 60 * 1000));
        const indexes = [firstFutureIndex, firstFutureIndex + 2, firstFutureIndex + 4].filter(index => index >= 0 && index < times.length);

        return indexes.map(index => {
            const date = new Date(times[index]);
            const temp = Math.round(Number((hourly.temperature_2m || [])[index] ?? 0));
            const probability = Math.round(Number((hourly.precipitation_probability || [])[index] ?? 0));
            const precipitation = Number((hourly.precipitation || [])[index] ?? 0);
            const wind = Math.round(Number((hourly.wind_speed_10m || [])[index] ?? 0));
            const [label] = getPortalWeatherLabel((hourly.weather_code || [])[index]);
            const time = date.toLocaleTimeString('fr-FR', {
                hour: '2-digit',
                minute: '2-digit'
            });
            const rainText = probability > 0 ? `${probability}% pluie` : (precipitation > 0 ? `${precipitation.toFixed(1)} mm` : 'sec');
            return {
                time,
                temp: `${temp}°`,
                detail: `${label} · ${rainText}`,
                title: `${time}: ${label}, ${temp}°C, pluie ${probability}%, vent ${wind} km/h`
            };
        });
    }

    function pushUniqueLocationPart(parts, value) {
        const clean = String(value || '')
            .replace(/\s+/g, ' ')
            .replace(/^Arrondissement de\s+/i, '')
            .replace(/^Commune de\s+/i, '')
            .trim();

        if (!clean) {
            return;
        }

        const normalized = clean.toLowerCase();
        const alreadyExists = parts.some(existing => {
            const current = existing.toLowerCase();
            return current === normalized || current.includes(normalized) || normalized.includes(current);
        });

        if (!alreadyExists) {
            parts.push(clean);
        }
    }

    function formatPortalLocationFromNominatim(payload, fallbackLabel) {
        const address = payload?.address || {};
        const parts = [];

        // Précision maximale fournie par OpenStreetMap/Nominatim.
        // Important : le numéro exact dépend de la richesse des données OSM autour du point GPS.
        const roadWithNumber = [address.road || address.pedestrian || address.footway || address.path, address.house_number]
            .filter(Boolean)
            .join(' ')
            .trim();

        const microArea = address.hamlet ||
            address.neighbourhood ||
            address.quarter ||
            address.suburb ||
            address.residential ||
            address.city_block ||
            address.locality;

        const commune = address.city_district ||
            address.municipality ||
            address.borough ||
            address.town ||
            address.village ||
            address.county;

        pushUniqueLocationPart(parts, roadWithNumber || address.building || address.amenity || address.shop || address.office);
        pushUniqueLocationPart(parts, microArea);
        pushUniqueLocationPart(parts, commune);
        pushUniqueLocationPart(parts, address.city || 'Abidjan');
        pushUniqueLocationPart(parts, address.country || 'Côte d’Ivoire');

        if (!parts.length && payload?.display_name) {
            return String(payload.display_name).split(',').slice(0, 5).map(item => item.trim()).filter(Boolean).join(', ');
        }

        return parts.length ? parts.slice(0, 5).join(', ') : fallbackLabel;
    }

    function formatPortalLocationFromBigData(payload, fallbackLabel) {
        const parts = [];
        const administrative = Array.isArray(payload?.localityInfo?.administrative) ? payload.localityInfo.administrative : [];
        const informative = Array.isArray(payload?.localityInfo?.informative) ? payload.localityInfo.informative : [];

        const quartier = informative.find(item => ['neighbourhood', 'quarter', 'suburb', 'residential', 'locality'].includes(String(item?.description || '').toLowerCase()))?.name ||
            payload?.locality ||
            payload?.city;
        const commune = administrative.find(item => ['city district', 'municipality', 'commune'].includes(String(item?.description || '').toLowerCase()))?.name ||
            payload?.principalSubdivision;

        pushUniqueLocationPart(parts, quartier);
        pushUniqueLocationPart(parts, commune);
        pushUniqueLocationPart(parts, payload?.city || 'Abidjan');
        pushUniqueLocationPart(parts, payload?.countryName || 'Côte d’Ivoire');

        return parts.length ? parts.slice(0, 4).join(', ') : fallbackLabel;
    }

    function reversePortalLocationWithNominatim(latitude, longitude) {
        const url = new URL('https://nominatim.openstreetmap.org/reverse');
        url.searchParams.set('format', 'jsonv2');
        url.searchParams.set('lat', latitude);
        url.searchParams.set('lon', longitude);
        url.searchParams.set('zoom', '18');
        url.searchParams.set('addressdetails', '1');
        url.searchParams.set('accept-language', 'fr');

        return fetch(url.toString(), {
                cache: 'no-store',
                headers: {
                    'Accept': 'application/json'
                }
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('nominatim_reverse_geocode_failed');
                }
                return response.json();
            })
            .then(payload => formatPortalLocationFromNominatim(payload, 'Position GPS détectée'));
    }

    function reversePortalLocationWithBigData(latitude, longitude) {
        const url = new URL('https://api.bigdatacloud.net/data/reverse-geocode-client');
        url.searchParams.set('latitude', latitude);
        url.searchParams.set('longitude', longitude);
        url.searchParams.set('localityLanguage', 'fr');

        return fetch(url.toString(), {
                cache: 'no-store'
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('bigdata_reverse_geocode_failed');
                }
                return response.json();
            })
            .then(payload => formatPortalLocationFromBigData(payload, 'Position GPS détectée'));
    }

    function resolvePortalGpsLocation(latitude, longitude) {
        // Priorité à OpenStreetMap/Nominatim pour une précision type Leaflet.
        // Si le fournisseur est bloqué, on bascule automatiquement sur BigDataCloud.
        return reversePortalLocationWithNominatim(latitude, longitude)
            .catch(() => reversePortalLocationWithBigData(latitude, longitude))
            .catch(() => 'Position GPS détectée');
    }

    function loadPortalWeather(latitude, longitude, label, isGps) {
        const url = new URL('https://api.open-meteo.com/v1/forecast');
        url.searchParams.set('latitude', latitude);
        url.searchParams.set('longitude', longitude);
        url.searchParams.set('current', 'temperature_2m,relative_humidity_2m,apparent_temperature,precipitation,rain,weather_code,wind_speed_10m');
        url.searchParams.set('hourly', 'temperature_2m,precipitation_probability,precipitation,weather_code,wind_speed_10m');
        url.searchParams.set('forecast_hours', '24');
        url.searchParams.set('timezone', 'auto');

        return fetch(url.toString(), {
                cache: 'no-store'
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('weather_fetch_failed');
                }
                return response.json();
            })
            .then(payload => {
                const current = payload.current || {};
                const [status, visual] = getPortalWeatherLabel(current.weather_code);
                const temperature = Math.round(Number(current.temperature_2m || 0));
                const apparent = Math.round(Number(current.apparent_temperature || temperature));
                const wind = Math.round(Number(current.wind_speed_10m || 0));
                const rain = Number(current.rain ?? current.precipitation ?? 0);
                const forecast = buildPortalWeatherForecast(payload);
                const risk = summarizePortalWeatherRisk(payload);
                const impact = calculatePortalWorksiteImpact({
                    maxProbability: risk.maxProbability,
                    maxPrecipitation: risk.maxPrecipitation,
                    totalPrecipitation: risk.totalPrecipitation,
                    maxWind: risk.maxWind,
                    weatherCodes: risk.weatherCodes
                });

                updatePortalWeatherCard({
                    city: label || (isGps ? 'Position GPS détectée' : PORTAL_WEATHER_FALLBACK.label),
                    status,
                    temperature,
                    feels: apparent + '°',
                    wind: wind + ' km/h',
                    rain: rain > 0 ? rain.toFixed(rain >= 1 ? 1 : 2) + ' mm' : '0 mm',
                    probability: risk.hasData ? risk.maxProbability + '%' : '--',
                    impact: risk.hasData ? impact.label : '--',
                    impactClass: risk.hasData ? impact.className : '',
                    impactReason: risk.hasData
                        ? `${impact.reason} · cumul pluie prévu ${risk.totalPrecipitation.toFixed(1)} mm · vent max ${risk.maxWind} km/h`
                        : '',
                    forecast,
                    visualClass: getPortalWeatherVisualClass(current.weather_code, visual),
                    note: isGps ? 'Météo et prévision horaire calculées sur les coordonnées GPS exactes de cet appareil.' : 'GPS indisponible : météo et prévision affichées pour Abidjan.'
                });
            });
    }

    function initPortalWeather() {
        if (!document.getElementById('portalWeatherCard')) {
            return;
        }

        updatePortalWeatherCard({
            city: 'Localisation…',
            status: 'Récupération de la météo en cours',
            temperature: '--',
            feels: '--°',
            wind: '--',
            rain: '--',
            probability: '--',
            impact: '--',
            impactClass: '',
            forecast: [],
            visualClass: 'is-cloudy',
            note: 'Autorisez la position GPS pour détecter commune, quartier et météo exacte.'
        });

        const fallback = () => loadPortalWeather(PORTAL_WEATHER_FALLBACK.latitude, PORTAL_WEATHER_FALLBACK.longitude, PORTAL_WEATHER_FALLBACK.label, false)
            .catch(() => updatePortalWeatherCard({
                city: 'Météo indisponible',
                status: 'Impossible de charger les données météo',
                temperature: '--',
                feels: '--°',
                wind: '--',
                rain: '--',
                probability: '--',
                impact: '--',
                impactClass: '',
                forecast: [],
                visualClass: 'is-cloudy',
                note: 'Vérifiez la connexion internet ou les restrictions du navigateur.'
            }));

        if (!navigator.geolocation) {
            fallback();
            return;
        }

        navigator.geolocation.getCurrentPosition(
            position => {
                const latitude = position.coords.latitude;
                const longitude = position.coords.longitude;
                const accuracy = Math.round(Number(position.coords.accuracy || 0));
                resolvePortalGpsLocation(latitude, longitude)
                    .then(locationLabel => {
                        const label = accuracy ? `${locationLabel} · précision ±${accuracy} m` : locationLabel;
                        return loadPortalWeather(latitude, longitude, label, true);
                    })
                    .catch(fallback);
            },
            fallback, {
                enableHighAccuracy: true,
                timeout: 20000,
                maximumAge: 0
            }
        );
    }

    function initPortalThemeMode() {
        const page = document.querySelector('.portal-page');
        const toggle = document.getElementById('portalThemeToggle');
        if (!page || !toggle) {
            return;
        }

        const storageKeys = ['finea.portal.theme', 'finea.app.theme'];
        const getStoredTheme = () => {
            try {
                return localStorage.getItem(storageKeys[0]) || localStorage.getItem(storageKeys[1]) || '';
            } catch (error) {
                return '';
            }
        };
        const persistTheme = theme => {
            try {
                storageKeys.forEach(storageKey => localStorage.setItem(storageKey, theme));
            } catch (error) {
                // noop
            }
        };
        const applyTheme = theme => {
            const isDark = theme === 'dark';
            document.documentElement.classList.toggle('finea-dark-mode', isDark);
            page.classList.toggle('is-dark', isDark);
            document.body.classList.toggle('finea-dark-mode', isDark);
            document.body.classList.toggle('portal-theme-dark', isDark);
            toggle.setAttribute('aria-pressed', isDark ? 'true' : 'false');
            toggle.setAttribute('aria-label', isDark ? 'Activer le mode clair' : 'Activer le mode sombre');
            toggle.title = isDark ? 'Passer en mode clair' : 'Passer en mode sombre';
        };

        const savedTheme = getStoredTheme();
        applyTheme(savedTheme || 'light');

        toggle.addEventListener('click', () => {
            const nextTheme = page.classList.contains('is-dark') ? 'light' : 'dark';
            persistTheme(nextTheme);
            applyTheme(nextTheme);
        });

        window.addEventListener('storage', event => {
            if (storageKeys.includes(event.key || '') && (event.newValue === 'dark' || event.newValue === 'light')) {
                applyTheme(event.newValue);
            }
        });
    }

    function summarizePortalWeatherRisk(payload) {
        const hourly = payload && payload.hourly ? payload.hourly : {};
        const times = Array.isArray(hourly.time) ? hourly.time : [];
        const probabilities = Array.isArray(hourly.precipitation_probability) ? hourly.precipitation_probability : [];
        const precipitations = Array.isArray(hourly.precipitation) ? hourly.precipitation : [];
        const winds = Array.isArray(hourly.wind_speed_10m) ? hourly.wind_speed_10m : [];
        const codes = Array.isArray(hourly.weather_code) ? hourly.weather_code : [];
        const now = Date.now() - 30 * 60 * 1000;
        const futureIndexes = times
            .map((value, index) => ({
                index,
                timestamp: new Date(value).getTime()
            }))
            .filter(item => Number.isFinite(item.timestamp) && item.timestamp >= now)
            .map(item => item.index);

        const indexes = futureIndexes.length ? futureIndexes : times.map((_, index) => index);
        const numericValues = (source) => indexes
            .map(index => Number(source[index]))
            .filter(Number.isFinite);
        const precipitationValues = numericValues(precipitations).map(value => Math.max(0, value));
        const hasForecastData = indexes.length > 0 && (
            probabilities.length > 0 ||
            precipitations.length > 0 ||
            winds.length > 0 ||
            codes.length > 0
        );

        return {
            hasData: hasForecastData,
            maxProbability: Math.round(Math.max(0, ...numericValues(probabilities))),
            maxPrecipitation: Math.max(0, ...precipitationValues),
            totalPrecipitation: precipitationValues.reduce((sum, value) => sum + value, 0),
            maxWind: Math.round(Math.max(0, ...numericValues(winds))),
            weatherCodes: [...new Set(numericValues(codes).map(Number))]
        };
    }

    function calculatePortalWorksiteImpact(data) {
        const maxProbability = Math.max(0, Number(data?.maxProbability ?? data?.maxProbabilityAvailable ?? data?.maxProbabilityNext24h) || 0);
        const forecastPrecipitation = Math.max(
            0,
            Number(data?.totalPrecipitation ?? data?.maxPrecipitation ?? data?.maxPrecipitationAvailable ?? data?.maxPrecipitationNext24h) || 0
        );
        const maxWind = Math.max(0, Number(data?.maxWind ?? data?.maxWindAvailable ?? data?.maxWindNext24h) || 0);
        const weatherCodes = Array.isArray(data?.weatherCodes) ? data.weatherCodes.map(Number) : [];

        if (
            maxProbability >= 85 ||
            forecastPrecipitation >= 10 ||
            maxWind >= 40 ||
            weatherCodes.some(code => [95, 96, 99].includes(code))
        ) {
            return {
                label: 'Élevé',
                className: 'impact-high',
                reason: 'Report ou adaptation conseillé'
            };
        }

        if (
            maxProbability >= 60 ||
            forecastPrecipitation >= 3 ||
            maxWind >= 25 ||
            weatherCodes.some(code => [61, 63, 65, 80, 81, 82].includes(code))
        ) {
            return {
                label: 'Modéré',
                className: 'impact-medium',
                reason: 'Vigilance travaux extérieurs'
            };
        }

        return {
            label: 'Faible',
            className: 'impact-low',
            reason: 'Travaux extérieurs possibles'
        };
    }

    initPortalThemeMode();
    initPortalWeather();
    initModuleLauncher();

    fetch('request/user_permissions.php')
        .then(r => r.json())
        .then(d => {
            if (d.user) {
                document.getElementById('welcomeUser').textContent = 'Connecté : ' + d.user.prenom + ' ' + d.user.nom;
            }
            buildModuleCards();
            if (hasVolatileBadgeModules()) {
                refreshPortalBadges();
                window.setInterval(refreshPortalBadges, 90000);
            }
        })
        .catch(() => {
            buildModuleCards();
            if (hasVolatileBadgeModules()) {
                refreshPortalBadges();
                window.setInterval(refreshPortalBadges, 90000);
            }
        });

    document.addEventListener('click', event => {
        const filterButton = event.target.closest('[data-portal-filter]');
        if (filterButton) {
            portalState.filter = String(filterButton.getAttribute('data-portal-filter') || 'all');
            buildModuleCards();
            return;
        }

        const favoriteToggle = event.target.closest('[data-favorite-toggle]');
        if (favoriteToggle) {
            event.preventDefault();
            event.stopPropagation();
            toggleFavoriteModule(favoriteToggle.getAttribute('data-favorite-toggle') || '');
            return;
        }

        const favoriteEnter = event.target.closest('[data-favorite-enter]');
        if (favoriteEnter) {
            const favoriteModule = findModuleById(favoriteEnter.getAttribute('data-favorite-enter') || '');
            if (favoriteModule) {
                attemptEnter(favoriteModule);
            }
            return;
        }

        const searchResult = event.target.closest('[data-search-result-index]');
        if (searchResult) {
            const resultIndex = Number(searchResult.getAttribute('data-search-result-index'));
            if (!Number.isNaN(resultIndex) && portalState.globalResults[resultIndex]) {
                activateGlobalSearchResult(portalState.globalResults[resultIndex]);
            }
            return;
        }

        const clearButton = event.target.closest('#portalSearchClear');
        if (clearButton) {
            portalState.query = '';
            portalState.globalResults = [];
            const input = document.getElementById('portalSearchInput');
            if (input) {
                input.value = '';
                input.focus();
            }
            buildModuleCards();
            closeGlobalSearchResults();
            return;
        }

        if (!event.target.closest('.portal-search-input-wrap')) {
            closeGlobalSearchResults();
        }
    });

    const portalSearchInput = document.getElementById('portalSearchInput');
    if (portalSearchInput) {
        portalSearchInput.addEventListener('input', event => {
            portalState.query = String(event.target.value || '');
            buildModuleCards();
            scheduleGlobalSearch();
        });

        portalSearchInput.addEventListener('keydown', event => {
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                updateActiveGlobalResult(portalState.activeSearchResultIndex + 1);
                return;
            }

            if (event.key === 'ArrowUp') {
                event.preventDefault();
                updateActiveGlobalResult(portalState.activeSearchResultIndex - 1);
                return;
            }

            if (event.key === 'Enter') {
                if (portalState.activeSearchResultIndex >= 0 && portalState.globalResults[portalState.activeSearchResultIndex]) {
                    event.preventDefault();
                    activateGlobalSearchResult(portalState.globalResults[portalState.activeSearchResultIndex]);
                    return;
                }

                const filteredModules = getFilteredModules();
                if (filteredModules.length === 1) {
                    event.preventDefault();
                    attemptEnter(filteredModules[0]);
                }
            }

            if (event.key === 'Escape') {
                portalState.query = '';
                portalState.globalResults = [];
                event.target.value = '';
                buildModuleCards();
                closeGlobalSearchResults();
            }
        });
    }

    document.addEventListener('keydown', event => {
        const activeTag = String(document.activeElement?.tagName || '').toLowerCase();
        const typingInField = activeTag === 'input' || activeTag === 'textarea' || document.activeElement?.isContentEditable;

        if ((event.ctrlKey || event.metaKey) && String(event.key).toLowerCase() === 'k') {
            event.preventDefault();
            portalSearchInput?.focus();
            portalSearchInput?.select();
            return;
        }

        if (!typingInField && event.key === '/') {
            event.preventDefault();
            portalSearchInput?.focus();
        }
    });

    function hasPageAccess(slug) {
        return Array.isArray(ALLOWED_SLUGS) && ALLOWED_SLUGS.includes(slug);
    }
</script>

<style>
    /* Guide interactif du portail : ajout isolé, sans modifier le design existant. */
    .portal-tour-backdrop {
        position: fixed;
        inset: 0;
        z-index: 2147483000;
        display: none;
        background: rgba(15, 23, 42, .54);
        backdrop-filter: blur(2px);
    }

    .portal-tour-backdrop.is-open {
        display: block;
    }

    .portal-tour-spotlight {
        position: fixed;
        z-index: 2147483001;
        pointer-events: none;
        border-radius: 24px;
        box-shadow: 0 0 0 9999px rgba(15, 23, 42, .54), 0 0 0 4px rgb(var(--brand-accent-rgb, 255 204 32) / .92), 0 24px 60px rgba(15, 23, 42, .24);
        transition: top .22s ease, left .22s ease, width .22s ease, height .22s ease, border-radius .22s ease;
    }

    .portal-tour-popover {
        position: fixed;
        z-index: 2147483002;
        width: min(440px, calc(100vw - 32px));
        border: 1px solid var(--brand-accent, #ffcc20);
        border-top: 5px solid var(--brand-accent, #ffcc20);
        border-radius: 26px;
        background: rgba(255, 255, 255, .98);
        color: #0f172a;
        box-shadow: 0 28px 70px rgba(15, 23, 42, .28);
        padding: 22px;
        transition: top .22s ease, left .22s ease;
    }

    .portal-tour-popover h3 {
        margin: 0 0 6px;
        font-size: 1.05rem;
        line-height: 1.25;
        font-weight: 950;
        color: #111827;
    }

    .portal-tour-step-count {
        display: inline-flex;
        align-items: center;
        gap: .35rem;
        margin-bottom: 18px;
        color: #64748b;
        font-size: .82rem;
        font-weight: 850;
    }

    .portal-tour-popover p {
        margin: 0;
        color: #334155;
        font-size: .92rem;
        line-height: 1.55;
        font-weight: 650;
    }

    .portal-tour-actions {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-top: 18px;
    }

    .portal-tour-actions-left,
    .portal-tour-actions-right {
        display: inline-flex;
        align-items: center;
        gap: 9px;
    }

    .portal-tour-btn {
        border: 0;
        border-radius: 18px;
        padding: .72rem 1.05rem;
        font-size: .85rem;
        font-weight: 950;
        cursor: pointer;
        transition: transform .18s ease, box-shadow .18s ease, opacity .18s ease;
    }

    .portal-tour-btn:hover {
        transform: translateY(-1px);
    }

    .portal-tour-btn:disabled {
        opacity: .42;
        cursor: not-allowed;
        transform: none;
    }

    .portal-tour-btn-light {
        color: #111827;
        background: #fff;
        border: 1px solid rgba(148, 163, 184, .3);
        box-shadow: 0 8px 18px rgba(15, 23, 42, .06);
    }

    .portal-tour-btn-dark {
        color: var(--brand-on-primary, #ffffff);
        background: var(--brand-primary, #050505);
        border: 1px solid var(--brand-accent, #ffcc20);
        box-shadow: 0 12px 24px rgba(15, 23, 42, .24);
    }

    .portal-tour-btn-gold {
        color: var(--brand-on-accent, #050505);
        background: var(--brand-accent, #ffcc20);
        box-shadow: 0 12px 24px rgb(var(--brand-accent-rgb, 255 204 32) / .24);
    }

    @media (max-width: 720px) {
        .portal-tour-popover {
            left: 16px !important;
            right: 16px !important;
            width: auto;
            bottom: 16px;
            top: auto !important;
            border-radius: 22px;
        }

        .portal-tour-actions {
            align-items: stretch;
            flex-direction: column;
        }

        .portal-tour-actions-left,
        .portal-tour-actions-right {
            width: 100%;
        }

        .portal-tour-btn {
            flex: 1;
        }
    }
    /* Contrast: icons on dark portal tiles must remain white in every theme. */
    .portal-module-icon,
    .portal-favorite-icon,
    .portal-module-launcher-icon,
    .portal-search-result-icon,
    .portal-module-icon :is(i, svg),
    .portal-favorite-icon :is(i, svg),
    .portal-module-launcher-icon :is(i, svg),
    .portal-search-result-icon :is(i, svg) {
        color: #ffffff !important;
    }
    .portal-user-actions .portal-chip-action,
    .portal-user-actions .portal-chip-action :is(i, svg, span) {
        color: #fff !important;
    }
</style>

<script>
    (function initFullPortalHelpTour() {
        const helpButton = document.getElementById('helpBtn');
        if (!helpButton) {
            return;
        }

        let currentStepIndex = 0;
        let steps = [];
        let backdrop = null;
        let spotlight = null;
        let popover = null;

        function textOf(value, fallback = '') {
            return String(value || fallback).replace(/\s+/g, ' ').trim();
        }

        function getModuleGuideDescription(moduleDef, card) {
            const label = textOf(moduleDef?.label, 'Module Finéa');
            const subtitle = textOf(moduleDef?.subtitle, 'Espace applicatif du portail');
            const badgeCount = Number(moduleDef?.pendingBadgeCount || 0);
            const personalCount = Number(moduleDef?.personalCount || 0);
            const unlocked = typeof hasAny === 'function' ? hasAny(moduleDef?.anyOf) : !card?.classList.contains('locked');
            const badgePart = badgeCount > 0 ? ` Il affiche actuellement ${badgeCount} alerte(s) ou élément(s) en attente.` : '';
            const usagePart = personalCount > 0 ? ` Vous l’avez déjà ouvert ${personalCount} fois.` : ' Il peut être épinglé si vous l’utilisez souvent.';
            const accessPart = unlocked ? 'Le bouton Ouvrir donne accès directement à ce module.' : 'Ce module est visible mais bloqué pour votre profil actuel.';

            return `${subtitle}. ${accessPart}${badgePart}${usagePart}`;
        }

        function buildSteps() {
            const dynamicSteps = [{
                    selector: '.portal-hero',
                    title: 'Bienvenue dans le portail Finéa',
                    body: 'Cette zone présente le portail : identité Finéa, résumé des accès, météo locale, statistiques et raccourcis de navigation.'
                },
                {
                    selector: '#appMenu',
                    title: 'Identité du portail',
                    body: 'Le logo et le libellé confirment que vous êtes dans le lanceur applicatif Finéa. C’est le point d’entrée central vers les modules métier.'
                },
                {
                    selector: '.portal-user-actions',
                    title: 'Actions utilisateur',
                    body: 'Cette zone affiche l’utilisateur connecté, le bouton Aide et la déconnexion. Le guide peut être relancé à tout moment depuis ce bouton.'
                },
                {
                    selector: '.portal-hero-stats',
                    title: 'Vue chiffrée immédiate',
                    body: 'Ces indicateurs résument le nombre de modules actifs, vos modules accessibles et les modules qui portent des alertes.'
                },
                {
                    selector: '#portalWeatherCard',
                    title: 'Météo locale GPS',
                    body: 'La carte météo utilise la position GPS de l’appareil quand elle est autorisée. Elle affiche la zone détectée, la température, le ressenti, le vent et la pluie.'
                },
                {
                    selector: '.portal-side-stack',
                    title: 'Rappels de lecture rapide',
                    body: 'Ces trois blocs expliquent les bénéfices du portail : modules épinglés, alertes visibles et recherche rapide au clavier.'
                },
                {
                    selector: '.portal-search-card',
                    title: 'Recherche globale',
                    body: 'Ce champ permet de retrouver rapidement un module, une page interne, un client, un fournisseur, un chantier ou un devis. Le raccourci Ctrl + K place directement le curseur ici.'
                },
                {
                    selector: '.portal-left-panel',
                    title: 'Filtres et organisation',
                    body: 'La colonne de gauche sert à filtrer les modules : tous les modules, vos accès, les nouveautés et les modules avec alertes.'
                },
                {
                    selector: '#portalFavoritesShell',
                    title: 'Modules épinglés',
                    body: 'Les modules favoris restent en haut du portail. Utilisez l’icône épingle sur une carte pour garder un module important à portée de main.'
                },
                {
                    selector: '#rolesGrid',
                    title: 'Grille complète des modules',
                    body: 'Cette grille liste les modules métier disponibles. Chaque carte indique l’icône, le nom, le résumé, l’état d’accès, les alertes et le bouton d’ouverture.'
                },
                {
                    selector: '#portalModuleLauncherBackdrop',
                    fallbackSelector: '.portal-search-card',
                    title: 'Ouverture rapide au chargement',
                    body: 'Le popup d’ouverture rapide permet de taper uniquement le nom d’un module et de l’ouvrir directement avec Entrée. Il sert d’accès express au portail.'
                }
            ];

            const modulesToDescribe = Array.isArray(window.MODULES) ? window.MODULES : (Array.isArray(MODULES) ? MODULES : []);
            modulesToDescribe.forEach(moduleDef => {
                const moduleId = String(moduleDef?.id || '').trim();
                if (!moduleId) {
                    return;
                }
                const card = document.querySelector(`[data-module-id="${CSS.escape(moduleId)}"]`);
                if (!card) {
                    return;
                }

                dynamicSteps.push({
                    selector: `[data-module-id="${CSS.escape(moduleId)}"]`,
                    title: `Module : ${textOf(moduleDef.label, 'Module Finéa')}`,
                    body: getModuleGuideDescription(moduleDef, card),
                    moduleId
                });
            });

            dynamicSteps.push({
                selector: '#rolesGrid',
                title: 'Fin du parcours',
                body: 'Vous pouvez maintenant rechercher, filtrer, épingler ou ouvrir un module. Relancez ce guide depuis le bouton Aide si nécessaire.'
            });

            return dynamicSteps.filter(step => getTargetElement(step));
        }

        function getTargetElement(step) {
            if (!step) {
                return null;
            }
            return document.querySelector(step.selector) || (step.fallbackSelector ? document.querySelector(step.fallbackSelector) : null);
        }

        function ensureTourDom() {
            if (backdrop && spotlight && popover) {
                return;
            }

            backdrop = document.createElement('div');
            backdrop.className = 'portal-tour-backdrop';
            backdrop.setAttribute('aria-hidden', 'true');

            spotlight = document.createElement('div');
            spotlight.className = 'portal-tour-spotlight';

            popover = document.createElement('section');
            popover.className = 'portal-tour-popover';
            popover.setAttribute('role', 'dialog');
            popover.setAttribute('aria-modal', 'true');
            popover.setAttribute('aria-label', 'Guide interactif du portail');

            backdrop.appendChild(spotlight);
            backdrop.appendChild(popover);
            document.body.appendChild(backdrop);

            backdrop.addEventListener('click', event => {
                if (event.target === backdrop) {
                    closeTour();
                }
            });
        }

        function positionTour(target) {
            if (!target || !spotlight || !popover) {
                return;
            }

            const padding = 10;
            const rect = target.getBoundingClientRect();
            const viewportWidth = window.innerWidth;
            const viewportHeight = window.innerHeight;
            const spotTop = Math.max(8, rect.top - padding);
            const spotLeft = Math.max(8, rect.left - padding);
            const spotWidth = Math.min(viewportWidth - 16, rect.width + padding * 2);
            const spotHeight = Math.min(viewportHeight - 16, rect.height + padding * 2);

            spotlight.style.top = `${spotTop}px`;
            spotlight.style.left = `${spotLeft}px`;
            spotlight.style.width = `${spotWidth}px`;
            spotlight.style.height = `${spotHeight}px`;
            spotlight.style.borderRadius = rect.height < 80 ? '18px' : '26px';

            if (viewportWidth <= 720) {
                popover.style.left = '16px';
                popover.style.top = 'auto';
                return;
            }

            const popoverWidth = Math.min(440, viewportWidth - 32);
            let left = rect.right + 22;
            let top = rect.top;

            if (left + popoverWidth > viewportWidth - 16) {
                left = rect.left - popoverWidth - 22;
            }
            if (left < 16) {
                left = Math.min(Math.max(16, rect.left), viewportWidth - popoverWidth - 16);
                top = rect.bottom + 22;
            }

            const popoverHeightEstimate = 245;
            if (top + popoverHeightEstimate > viewportHeight - 16) {
                top = Math.max(16, viewportHeight - popoverHeightEstimate - 16);
            }

            popover.style.left = `${left}px`;
            popover.style.top = `${Math.max(16, top)}px`;
        }

        function renderStep() {
            const step = steps[currentStepIndex];
            const target = getTargetElement(step);
            if (!step || !target) {
                closeTour();
                return;
            }

            target.scrollIntoView({
                behavior: 'smooth',
                block: 'center',
                inline: 'center'
            });

            window.setTimeout(() => {
                positionTour(target);
                const isFirst = currentStepIndex === 0;
                const isLast = currentStepIndex === steps.length - 1;

                popover.innerHTML = `
                    <h3>${escapeHtmlText(step.title)}</h3>
                    <span class="portal-tour-step-count">Étape ${currentStepIndex + 1} / ${steps.length}</span>
                    <p>${escapeHtmlText(step.body)}</p>
                    <div class="portal-tour-actions">
                        <div class="portal-tour-actions-left">
                            <button type="button" class="portal-tour-btn portal-tour-btn-light" data-tour-close>Fermer</button>
                        </div>
                        <div class="portal-tour-actions-right">
                            <button type="button" class="portal-tour-btn portal-tour-btn-light" data-tour-prev ${isFirst ? 'disabled' : ''}>Précédent</button>
                            <button type="button" class="portal-tour-btn ${isLast ? 'portal-tour-btn-gold' : 'portal-tour-btn-dark'}" data-tour-next>${isLast ? 'Terminer' : 'Suivant'}</button>
                        </div>
                    </div>
                `;

                popover.querySelector('[data-tour-close]')?.addEventListener('click', closeTour);
                popover.querySelector('[data-tour-prev]')?.addEventListener('click', previousStep);
                popover.querySelector('[data-tour-next]')?.addEventListener('click', () => {
                    if (isLast) {
                        closeTour();
                    } else {
                        nextStep();
                    }
                });
            }, 260);
        }

        function openTour() {
            ensureTourDom();
            if (typeof buildModuleCards === 'function') {
                buildModuleCards();
            }
            steps = buildSteps();
            currentStepIndex = 0;
            if (!steps.length) {
                return;
            }
            backdrop.classList.add('is-open');
            backdrop.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
            renderStep();
        }

        function closeTour() {
            if (!backdrop) {
                return;
            }
            backdrop.classList.remove('is-open');
            backdrop.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
        }

        function nextStep() {
            currentStepIndex = Math.min(currentStepIndex + 1, steps.length - 1);
            renderStep();
        }

        function previousStep() {
            currentStepIndex = Math.max(currentStepIndex - 1, 0);
            renderStep();
        }

        helpButton.addEventListener('click', event => {
            event.preventDefault();
            event.stopPropagation();
            event.stopImmediatePropagation();
            openTour();
        }, true);

        document.addEventListener('keydown', event => {
            if (!backdrop || !backdrop.classList.contains('is-open')) {
                return;
            }
            if (event.key === 'Escape') {
                event.preventDefault();
                closeTour();
            } else if (event.key === 'ArrowRight') {
                event.preventDefault();
                nextStep();
            } else if (event.key === 'ArrowLeft') {
                event.preventDefault();
                previousStep();
            }
        });

        window.addEventListener('resize', () => {
            if (backdrop && backdrop.classList.contains('is-open')) {
                renderStep();
            }
        });
    })();
</script>

<?php require_once __DIR__ . '/decaissement/includes/footer.php'; ?>
