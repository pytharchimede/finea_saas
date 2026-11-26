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

<link rel="stylesheet" href="<?= htmlspecialchars($rootUrl('assets/css/devis-theme.css'), ENT_QUOTES, 'UTF-8') ?>?v=20261126f">

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
