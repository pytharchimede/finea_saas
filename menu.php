<?php
// menu.php est inclus dans plusieurs layouts.
// - Par defaut: rendu Bootstrap (navbar)
// - Optionnel: variant "portal" (look & feel identique a selection_portail.php)
$menuVariant = isset($menuVariant) ? (string)$menuVariant : 'bootstrap';
$menuShowLogout = isset($menuShowLogout) ? (bool)$menuShowLogout : true;

$allowed = $_SESSION['allowed_pages'] ?? [];
$role = $_SESSION['role_norm'] ?? '';

$appBasePathForMenu = '';
if (class_exists('\\AccessManager') && method_exists('\\AccessManager', 'getApplicationBasePath')) {
    try {
        $appBasePathForMenu = (string)\AccessManager::getApplicationBasePath();
    } catch (Throwable $e) {
        $appBasePathForMenu = '';
    }
}
if ($appBasePathForMenu === '') {
    $scriptNameForMenu = (string)($_SERVER['SCRIPT_NAME'] ?? '/');
    $basePathForMenu = rtrim(str_replace('\\', '/', dirname($scriptNameForMenu)), '/');
    if ($basePathForMenu === '/' || $basePathForMenu === '.') {
        $basePathForMenu = '';
    } elseif (preg_match('~/(devis|debourse|clients|offres|unites|chantiers)$~', $basePathForMenu)) {
        $basePathForMenu = dirname($basePathForMenu);
        if ($basePathForMenu === '/' || $basePathForMenu === '.') {
            $basePathForMenu = '';
        }
    }
    $appBasePathForMenu = $basePathForMenu;
}
$menuUrl = static function (string $path) use ($appBasePathForMenu): string {
    return rtrim($appBasePathForMenu, '/') . '/' . ltrim($path, '/');
};

$items = [
    'dashboard' => [$menuUrl('devis/index.php'), '<i class="fas fa-home"></i>', 'Accueil'],
    'generer_devis' => [$menuUrl('devis/generer.php'), '<i class="fas fa-file-signature"></i>', 'Generer un devis'],
    'liste_devis' => [$menuUrl('devis/liste.php'), '<i class="fas fa-file-contract"></i>', 'Devis'],
    'gestion_zones' => [$menuUrl('decaissement/gestion_zones.php'), '<i class="fas fa-layer-group"></i>', 'Gestion Zones'],
    'liste_client' => [$menuUrl('clients/liste.php'), '<i class="fas fa-users"></i>', 'Clients'],
    'liste_offre' => [$menuUrl('offres/liste.php'), '<i class="fas fa-diagram-project"></i>', 'Projets'],
    'liste_unite' => [$menuUrl('unites/liste.php'), '<i class="fas fa-balance-scale"></i>', 'Unites'],
    'liste_debourse' => [$menuUrl('debourse/liste.php'), '<i class="fas fa-coins"></i>', 'Debourses'],
    'liste_chantier' => [$menuUrl('chantiers/liste.php'), '<i class="fas fa-warehouse"></i>', 'Chantiers'],
    'liste_utilisateur' => [$menuUrl('liste_utilisateur.php'), '<i class="fas fa-user-cog"></i>', 'Utilisateurs'],
    'gestion_acces' => [$menuUrl('admin/gestion_acces.php'), '<i class="fas fa-shield-alt"></i>', 'Acces'],
    'contrats' => [$menuUrl('contrats/index.php'), '<i class="fas fa-file-signature"></i>', 'Contrats'],
    'portail' => [$menuUrl('selection_portail.php'), '<i class="fas fa-th-large"></i>', 'Portail'],
    'communication' => [$menuUrl('communication/mes_taches_communication.php'), '<i class="fas fa-list-check"></i>', 'Mes taches'],
    'procedures' => [$menuUrl('procedures/index.php'), '<i class="fas fa-project-diagram"></i>', 'Procédures'],
];

$filteredItems = [];
foreach ($items as $slug => $cfg) {
    [$href, $iconHtml, $text] = $cfg;
    if ($slug === 'gestion_acces' && $role !== 'directeur general') {
        continue;
    }

    if ($slug === 'communication') {
        $can = !empty($_SESSION['permissions']['acces_communication'] ?? 0) || !empty($_SESSION['permissions']['acces_admin'] ?? 0);
        if (!$can) {
            continue;
        }
    } elseif ($slug === 'contrats') {
        $can = !empty($_SESSION['permissions']['acces_contrats'] ?? 0) || !empty($_SESSION['permissions']['acces_admin'] ?? 0);
        if (!$can) {
            continue;
        }
    } else {
        if (!in_array($slug, $allowed, true) && $slug !== 'profil' && $slug !== 'gestion_acces' && $slug !== 'procedures') {
            continue;
        }
    }
    $filteredItems[$slug] = [$href, $iconHtml, $text];
}

if ($menuVariant === 'portal'):
?>
    <div id="appMenu" class="flex flex-wrap items-center gap-2">
        <?php foreach ($filteredItems as $slug => $cfg):
            [$href, $iconHtml, $text] = $cfg;
            $isActive = (($page ?? '') === $slug);
            $base = 'inline-flex items-center gap-2 rounded-xl px-3 py-2 text-sm font-semibold transition border';
            $cls = $isActive
                ? $base . ' bg-gray-900 text-white border-gray-900'
                : $base . ' bg-white/70 text-gray-700 border-gray-200 hover:bg-indigo-50 hover:border-indigo-200';
        ?>
            <a class="<?= $cls ?>" href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>">
                <span class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-gradient-to-br from-indigo-500 to-gray-900 text-white">
                    <?= $iconHtml ?>
                </span>
                <span class="hidden md:inline"><?= htmlspecialchars($text, ENT_QUOTES, 'UTF-8') ?></span>
            </a>
        <?php endforeach; ?>

        <button id="helpBtn" type="button" class="inline-flex items-center gap-2 rounded-xl px-3 py-2 text-sm font-semibold transition border bg-white/70 text-gray-700 border-gray-200 hover:bg-indigo-50 hover:border-indigo-200">
            <span class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-gradient-to-br from-indigo-500 to-gray-900 text-white">
                <i class="fas fa-question-circle"></i>
            </span>
            <span class="hidden md:inline">Aide</span>
        </button>

        <?php if ($menuShowLogout): ?>
            <a class="inline-flex items-center gap-2 rounded-xl px-3 py-2 text-sm font-semibold transition border bg-red-600 text-white border-red-600 hover:bg-red-700" href="<?= htmlspecialchars($menuUrl('deconnex.php'), ENT_QUOTES, 'UTF-8') ?>">
                <span class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-gradient-to-br from-red-500 to-gray-900 text-white">
                    <i class="fas fa-sign-out-alt"></i>
                </span>
                <span class="hidden md:inline">Deconnexion</span>
            </a>
        <?php endif; ?>
    </div>
<?php
else:
?>
    <ul id="appMenu" class="navbar-nav ms-auto align-items-center gap-lg-1">
        <?php foreach ($filteredItems as $slug => $cfg):
            [$href, $iconHtml, $text] = $cfg;
            $active = (($page ?? '') === $slug) ? 'active' : '';
        ?>
            <li class="nav-item">
                <a class="nav-link text-light <?= $active ?>" href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>">
                    <?= $iconHtml ?> <?= htmlspecialchars($text, ENT_QUOTES, 'UTF-8') ?>
                </a>
            </li>
        <?php endforeach; ?>

        <li class="nav-item ms-lg-2">
            <button id="helpBtn" type="button" class="btn btn-sm btn-outline-light px-3">
                <i class="fas fa-question-circle"></i>
                <span class="d-none d-lg-inline"> Aide</span>
            </button>
        </li>

        <?php if ($menuShowLogout): ?>
            <li class="nav-item ms-lg-3">
                <a href="<?= htmlspecialchars($menuUrl('deconnex.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-danger px-3">
                    <i class="fas fa-sign-out-alt"></i>
                </a>
            </li>
        <?php endif; ?>
    </ul>
<?php
endif;
?>

<?php
if (empty($GLOBALS['__finea_help_tour_assets_included'])):
    $GLOBALS['__finea_help_tour_assets_included'] = true;
?>
    <script defer src="<?= htmlspecialchars($menuUrl('js/help_tour.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
    <script defer src="<?= htmlspecialchars($menuUrl('js/help_tours_app.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<?php
endif;
?>
