<?php

$moduleTitle = $moduleTitle ?? 'Gestion devis';
$moduleSubtitle = $moduleSubtitle ?? 'Devis, déboursés, clients, projets, unités et chantiers';
$activeDevisModule = $activeDevisModule ?? 'devis';
if (!isset($devisModuleNav) || !is_array($devisModuleNav)) {
    $devisModuleNav = $devisModuleController->modules();
}

$pageTitle = $moduleTitle;
$activeMenuByDevisModule = [
    'clients' => 'module_clients',
    'offres' => 'module_offres',
    'unites' => 'module_unites',
    'debourse' => 'module_debourse',
    'chantiers' => 'module_chantiers',
];
$activeMenu = $activeMenuByDevisModule[$activeDevisModule] ?? 'module_devis';
$rootUrl = static fn(string $path): string => devis_module_root_url($path);
$currentModule = $devisModuleNav[$activeDevisModule] ?? null;

$heroActions = [];
if ($activeDevisModule !== 'dashboard') {
    $heroActions[] = [
        'label' => 'Vue module',
        'href' => $rootUrl('devis/index.php'),
        'icon' => 'fa-gauge-high',
        'variant' => 'light',
    ];
}
if (is_array($currentModule)) {
    $moduleListHref = (string) ($currentModule['href'] ?? '');
    if ($moduleListHref !== '') {
        $heroActions[] = [
            'label' => 'Ouvrir la liste',
            'href' => $moduleListHref,
            'icon' => 'fa-list',
            'variant' => 'light',
        ];
    }
    if (!empty($currentModule['create_href'])) {
        $heroActions[] = [
            'label' => 'Nouvelle saisie',
            'href' => (string) $currentModule['create_href'],
            'icon' => 'fa-plus',
            'variant' => 'accent',
        ];
    }
    if ($activeDevisModule === 'clients') {
        $heroActions[] = [
            'label' => 'Détails clients',
            'href' => $rootUrl('clients/liste.php'),
            'icon' => 'fa-chart-line',
            'variant' => 'light',
        ];
    }
}
if ($activeDevisModule === 'dashboard' && devis_module_has_access('acces_generer_devis')) {
    $heroActions[] = [
        'label' => 'Nouveau devis',
        'href' => $rootUrl('devis/generer.php'),
        'icon' => 'fa-plus',
        'variant' => 'accent',
    ];
}

$quickActions = [
    [
        'label' => 'Nouveau devis',
        'description' => 'Demarrer une proposition commerciale',
        'href' => $rootUrl('devis/generer.php'),
        'icon' => 'fa-file-circle-plus',
        'permission' => 'acces_generer_devis',
    ],
    [
        'label' => 'Liste des devis',
        'description' => 'Consulter et suivre les dossiers',
        'href' => $rootUrl('devis/liste.php'),
        'icon' => 'fa-list-check',
        'permission' => 'acces_devis',
    ],
    [
        'label' => 'Veille appels d offres',
        'description' => 'Analyser les nouvelles opportunites',
        'href' => $rootUrl('devis/veille_ao.php'),
        'icon' => 'fa-binoculars',
        'permission' => 'acces_veille_ao',
    ],
];

require_once dirname(__DIR__, 2) . '/decaissement/includes/header.php';
require_once dirname(__DIR__, 2) . '/decaissement/includes/navbar.php';
?>

<style>
    :root {
        --devis-ink: var(--brand-primary,#081c65);
        --devis-ink-deep: #101a38;
        --devis-accent: var(--brand-accent,#1746b0);
        --devis-bg: #f3f6fb;
        --devis-border: #dfe6f0;
        --devis-muted: #64748b;
    }

    .devis-page {
        min-height: 100vh;
        padding: 28px 18px 56px;
        background:
            linear-gradient(180deg, rgba(29, 43, 87, .04), transparent 300px),
            var(--devis-bg);
        color: #172033;
    }

    .devis-container {
        width: min(1480px, 100%);
        margin: 0 auto;
    }

    .devis-hero {
        position: relative;
        overflow: hidden;
        display: flex;
        align-items: flex-end;
        justify-content: space-between;
        gap: 30px;
        min-height: 216px;
        padding: 34px 38px;
        border: 1px solid rgba(255, 255, 255, .1);
        border-radius: 28px;
        background: linear-gradient(125deg, var(--devis-ink-deep), var(--devis-ink) 68%, #263866);
        box-shadow: 0 24px 60px rgba(23, 35, 72, .18);
        color: #fff;
    }

    .devis-hero::after {
        content: "";
        position: absolute;
        inset: auto 0 0;
        height: 5px;
        background: var(--devis-accent);
    }

    .devis-hero-copy,
    .devis-hero-actions {
        position: relative;
        z-index: 1;
    }

    .devis-kicker {
        display: inline-flex;
        align-items: center;
        gap: 9px;
        margin: 0 0 13px;
        color: #f8d25d;
        font-size: 11px;
        font-weight: 800;
        letter-spacing: .16em;
        text-transform: uppercase;
    }

    .devis-kicker::before {
        content: "";
        width: 24px;
        height: 3px;
        border-radius: 3px;
        background: var(--devis-accent);
    }

    .devis-hero h1 {
        margin: 0;
        max-width: 800px;
        color: #fff;
        font-size: clamp(28px, 3vw, 44px);
        font-weight: 850;
        line-height: 1.08;
        letter-spacing: 0;
    }

    .devis-hero p {
        max-width: 820px;
        margin: 15px 0 0;
        color: rgba(255, 255, 255, .75);
        font-size: 15px;
        line-height: 1.65;
    }

    .devis-hero-actions {
        display: flex;
        flex-wrap: wrap;
        justify-content: flex-end;
        gap: 10px;
        min-width: 250px;
    }

    .devis-action {
        display: inline-flex;
        min-height: 44px;
        align-items: center;
        justify-content: center;
        gap: 9px;
        padding: 10px 16px;
        border: 1px solid rgba(255, 255, 255, .22);
        border-radius: 8px;
        background: rgba(255, 255, 255, .09);
        color: #fff !important;
        font-size: 13px;
        font-weight: 750;
        text-decoration: none !important;
        transition: transform .18s ease, background .18s ease, border-color .18s ease;
    }

    .devis-action:hover {
        transform: translateY(-2px);
        border-color: rgba(255, 255, 255, .48);
        background: rgba(255, 255, 255, .16);
    }

    .devis-action.is-accent {
        border-color: var(--devis-accent);
        background: var(--devis-accent);
        color: #172348 !important;
    }

    .devis-workspace {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 310px;
        gap: 24px;
        align-items: start;
        margin-top: 24px;
    }

    .devis-main {
        min-width: 0;
    }

    .devis-main > :first-child {
        margin-top: 0 !important;
    }

    .devis-aside {
        position: sticky;
        top: 92px;
        overflow: hidden;
        border-radius: 18px;
        background: var(--devis-ink-deep);
        box-shadow: 0 20px 45px rgba(23, 35, 72, .16);
        color: #fff;
    }

    .devis-aside-head {
        padding: 24px 22px 18px;
        border-bottom: 1px solid rgba(255, 255, 255, .09);
    }

    .devis-aside-head strong {
        display: block;
        color: #fff;
        font-size: 15px;
    }

    .devis-aside-head span {
        display: block;
        margin-top: 5px;
        color: rgba(255, 255, 255, .55);
        font-size: 12px;
    }

    .devis-nav {
        display: grid;
        gap: 5px;
        padding: 12px;
    }

    .devis-nav-link {
        display: grid;
        grid-template-columns: 38px minmax(0, 1fr) 18px;
        align-items: center;
        gap: 10px;
        min-height: 52px;
        padding: 7px 10px;
        border-radius: 8px;
        color: rgba(255, 255, 255, .72) !important;
        text-decoration: none !important;
        transition: background .18s ease, color .18s ease, transform .18s ease;
    }

    .devis-nav-link:hover {
        transform: translateX(2px);
        background: rgba(255, 255, 255, .08);
        color: #fff !important;
    }

    .devis-nav-link.is-active {
        background: var(--devis-accent);
        color: #172348 !important;
        box-shadow: 0 8px 18px rgba(250, 189, 2, .18);
    }

    .devis-nav-icon {
        display: inline-flex;
        width: 38px;
        height: 38px;
        align-items: center;
        justify-content: center;
        border-radius: 8px;
        background: rgba(255, 255, 255, .09);
        font-size: 14px;
    }

    .devis-nav-link.is-active .devis-nav-icon {
        background: rgba(23, 35, 72, .1);
    }

    .devis-nav-copy strong,
    .devis-nav-copy small {
        display: block;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .devis-nav-copy strong {
        font-size: 13px;
        font-weight: 750;
    }

    .devis-nav-copy small {
        margin-top: 2px;
        color: inherit;
        font-size: 10px;
        opacity: .66;
    }

    .devis-aside-quick {
        padding: 20px 22px 22px;
        border-top: 1px solid rgba(255, 255, 255, .09);
        background: rgba(255, 255, 255, .025);
    }

    .devis-aside-title {
        margin: 0 0 12px;
        color: rgba(255, 255, 255, .52);
        font-size: 10px;
        font-weight: 800;
        letter-spacing: .14em;
        text-transform: uppercase;
    }

    .devis-quick-link {
        display: flex;
        align-items: center;
        gap: 11px;
        padding: 10px 0;
        color: rgba(255, 255, 255, .74) !important;
        text-decoration: none !important;
    }

    .devis-quick-link + .devis-quick-link {
        border-top: 1px solid rgba(255, 255, 255, .07);
    }

    .devis-quick-link i {
        width: 22px;
        color: var(--devis-accent);
        text-align: center;
    }

    .devis-quick-link strong,
    .devis-quick-link small {
        display: block;
    }

    .devis-quick-link strong {
        font-size: 12px;
    }

    .devis-quick-link small {
        margin-top: 2px;
        color: rgba(255, 255, 255, .43);
        font-size: 10px;
    }

    .devis-panel,
    .devis-main .card {
        border: 1px solid var(--devis-border) !important;
        border-radius: 14px !important;
        background: #fff !important;
        box-shadow: 0 12px 30px rgba(23, 35, 72, .07) !important;
    }

    .devis-main .card-header {
        border-bottom-color: var(--devis-border) !important;
        border-radius: 14px 14px 0 0 !important;
        background: #fff !important;
        color: #172348 !important;
    }

    .devis-main table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
    }

    .devis-main table thead th {
        border-bottom: 1px solid var(--devis-border) !important;
        background: #f7f9fc !important;
        color: #526079 !important;
        font-size: 11px;
        font-weight: 800;
        letter-spacing: .04em;
        text-transform: uppercase;
    }

    .devis-main table tbody td {
        border-bottom-color: #edf1f6 !important;
        color: #29354b;
        vertical-align: middle;
    }

    .devis-main table tbody tr:hover td {
        background: #f8faff;
        color: #172348;
    }

    .devis-main input:not([type="checkbox"]):not([type="radio"]),
    .devis-main select,
    .devis-main textarea,
    .devis-main .form-control,
    .devis-main .form-select,
    .devis-main .select2-selection {
        min-height: 44px;
        border: 1px solid #ccd6e4 !important;
        border-radius: 8px !important;
        background-color: #fff !important;
        box-shadow: none !important;
    }

    .devis-main textarea {
        min-height: 110px;
    }

    .devis-main input:focus,
    .devis-main select:focus,
    .devis-main textarea:focus,
    .devis-main .form-control:focus,
    .devis-main .form-select:focus {
        border-color: #2b4c92 !important;
        box-shadow: 0 0 0 3px rgba(43, 76, 146, .12) !important;
        outline: none;
    }

    .devis-main .select2-container {
        width: 100% !important;
    }

    .devis-main .select2-selection__rendered {
        line-height: 42px !important;
    }

    .devis-main .select2-selection__arrow {
        height: 42px !important;
    }

    .devis-main .btn {
        min-height: 40px;
        border-radius: 8px !important;
        font-weight: 700;
    }

    body.finea-dark-mode .devis-page {
        --devis-bg: #0e1425;
        --devis-border: #2a3550;
        background: #0e1425;
        color: #e8edf7;
    }

    body.finea-dark-mode .devis-panel,
    body.finea-dark-mode .devis-main .card,
    body.finea-dark-mode .devis-main .card-header {
        border-color: #2a3550 !important;
        background: #172036 !important;
        color: #f4f7fb !important;
    }

    body.finea-dark-mode .devis-main table thead th {
        border-color: #303b56 !important;
        background: #1d2841 !important;
        color: #cbd5e1 !important;
    }

    body.finea-dark-mode .devis-main table tbody td {
        border-color: #2a3550 !important;
        color: #e4eaf3;
    }

    body.finea-dark-mode .devis-main table tbody tr:hover td {
        background: #202b45;
        color: #fff;
    }

    body.finea-dark-mode .devis-main input:not([type="checkbox"]):not([type="radio"]),
    body.finea-dark-mode .devis-main select,
    body.finea-dark-mode .devis-main textarea,
    body.finea-dark-mode .devis-main .form-control,
    body.finea-dark-mode .devis-main .form-select,
    body.finea-dark-mode .devis-main .select2-selection {
        border-color: #3a4763 !important;
        background-color: #111a2e !important;
        color: #fff !important;
    }

    @media (max-width: 1050px) {
        .devis-workspace {
            grid-template-columns: 1fr;
        }

        .devis-aside {
            position: static;
            order: -1;
        }

        .devis-nav {
            grid-template-columns: repeat(4, minmax(170px, 1fr));
            overflow-x: auto;
        }

        .devis-aside-quick {
            display: none;
        }
    }

    @media (max-width: 760px) {
        .devis-page {
            padding: 16px 12px 40px;
        }

        .devis-hero {
            align-items: flex-start;
            flex-direction: column;
            min-height: 0;
            padding: 26px 22px 28px;
            border-radius: 18px;
        }

        .devis-hero h1 {
            font-size: 28px;
        }

        .devis-hero-actions {
            justify-content: flex-start;
            min-width: 0;
            width: 100%;
        }

        .devis-action {
            flex: 1 1 145px;
        }

        .devis-workspace {
            gap: 16px;
            margin-top: 16px;
        }

        .devis-aside {
            border-radius: 14px;
        }

        .devis-aside-head {
            padding: 18px 16px 12px;
        }

        .devis-nav {
            display: flex;
            padding: 10px;
        }

        .devis-nav-link {
            flex: 0 0 185px;
        }
    }

.devis-page .devis-hero{background:var(--brand-primary,#081c65);color:var(--brand-on-primary,#fff)}
.devis-page .devis-hero :is(h1,p,.devis-kicker){color:var(--brand-on-primary,#fff)}
.devis-page .devis-hero .devis-action:not(.is-accent){color:var(--brand-on-primary,#fff)!important;border-color:currentColor}
.devis-page :is(.devis-action.is-accent,.devis-nav-link.is-active){color:var(--brand-on-accent,#fff)!important}
.devis-page :is(.devis-action.is-accent,.devis-nav-link.is-active) :is(i,svg,strong,small){color:inherit!important}
:is(html.finea-dark-mode,body.finea-dark-mode) .devis-page{--devis-bg:#08111f;--devis-border:#52647e;--devis-muted:#c6d0de;background:var(--devis-bg);color:#f5f8fc}
:is(html.finea-dark-mode,body.finea-dark-mode) .devis-page .devis-aside{background:#172337;border:1px solid #52647e}
</style>

<div class="devis-page">
    <div class="devis-container">
        <header class="devis-hero">
            <div class="devis-hero-copy">
                <div class="devis-kicker">Finea / Direction commerciale</div>
                <h1><?= htmlspecialchars($moduleTitle, ENT_QUOTES, 'UTF-8') ?></h1>
                <p><?= htmlspecialchars($moduleSubtitle, ENT_QUOTES, 'UTF-8') ?></p>
            </div>

            <div class="devis-hero-actions">
                <?php foreach ($heroActions as $action): ?>
                    <a class="devis-action <?= ($action['variant'] ?? '') === 'accent' ? 'is-accent' : '' ?>" href="<?= htmlspecialchars((string) $action['href'], ENT_QUOTES, 'UTF-8') ?>">
                        <i class="fa-solid <?= htmlspecialchars((string) $action['icon'], ENT_QUOTES, 'UTF-8') ?>"></i>
                        <?= htmlspecialchars((string) $action['label'], ENT_QUOTES, 'UTF-8') ?>
                    </a>
                <?php endforeach; ?>
                <a class="devis-action" href="<?= htmlspecialchars($rootUrl('selection_portail.php'), ENT_QUOTES, 'UTF-8') ?>" title="Retour au portail">
                    <i class="fa-solid fa-table-cells-large"></i>
                    Portail
                </a>
            </div>
        </header>

        <div class="devis-workspace">
            <main class="devis-main">
                <?= $content ?? '' ?>
            </main>

            <aside class="devis-aside" aria-label="Navigation du centre devis et chantiers">
                <div class="devis-aside-head">
                    <strong>Centre devis &amp; chantiers</strong>
                    <span>Pilotage commercial et operationnel</span>
                </div>

                <nav class="devis-nav">
                    <a class="devis-nav-link <?= $activeDevisModule === 'dashboard' ? 'is-active' : '' ?>" href="<?= htmlspecialchars($rootUrl('devis/index.php'), ENT_QUOTES, 'UTF-8') ?>">
                        <span class="devis-nav-icon"><i class="fa-solid fa-gauge-high"></i></span>
                        <span class="devis-nav-copy"><strong>Vue d ensemble</strong><small>Indicateurs et acces rapides</small></span>
                        <i class="fa-solid fa-chevron-right"></i>
                    </a>
                    <?php foreach ($devisModuleNav as $key => $item): ?>
                        <?php if (!devis_module_has_access($item['permission'] ?? null)) continue; ?>
                        <a class="devis-nav-link <?= $activeDevisModule === $key ? 'is-active' : '' ?>" href="<?= htmlspecialchars((string) ($item['dashboard_href'] ?? $item['href']), ENT_QUOTES, 'UTF-8') ?>">
                            <span class="devis-nav-icon"><i class="fa-solid <?= htmlspecialchars((string) $item['icon'], ENT_QUOTES, 'UTF-8') ?>"></i></span>
                            <span class="devis-nav-copy">
                                <strong><?= htmlspecialchars((string) $item['label'], ENT_QUOTES, 'UTF-8') ?></strong>
                                <small><?= htmlspecialchars(mb_strimwidth((string) ($item['description'] ?? ''), 0, 34, '...'), ENT_QUOTES, 'UTF-8') ?></small>
                            </span>
                            <i class="fa-solid fa-chevron-right"></i>
                        </a>
                    <?php endforeach; ?>
                </nav>

                <div class="devis-aside-quick">
                    <p class="devis-aside-title">Raccourcis</p>
                    <?php foreach ($quickActions as $action): ?>
                        <?php if (!devis_module_has_access($action['permission'] ?? null)) continue; ?>
                        <a class="devis-quick-link" href="<?= htmlspecialchars((string) $action['href'], ENT_QUOTES, 'UTF-8') ?>">
                            <i class="fa-solid <?= htmlspecialchars((string) $action['icon'], ENT_QUOTES, 'UTF-8') ?>"></i>
                            <span>
                                <strong><?= htmlspecialchars((string) $action['label'], ENT_QUOTES, 'UTF-8') ?></strong>
                                <small><?= htmlspecialchars((string) $action['description'], ENT_QUOTES, 'UTF-8') ?></small>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </aside>
        </div>
    </div>
</div>
<?php require_once dirname(__DIR__, 2) . '/decaissement/includes/footer.php'; ?>
