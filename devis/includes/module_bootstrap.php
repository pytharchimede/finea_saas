<?php

require_once dirname(__DIR__, 2) . '/auth_check.php';
require_once dirname(__DIR__, 2) . '/model/Database.php';
require_once dirname(__DIR__, 2) . '/controllers/DevisModuleController.php';
require_once dirname(__DIR__, 2) . '/services/ClientIdentityMigration.php';

(new ClientIdentityMigration(Database::getConnection()))->install();

if (!function_exists('devis_module_root_url')) {
    function devis_module_root_url(string $path = ''): string
    {
        $base = devis_module_app_base_path();
        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }
}

if (!function_exists('devis_module_app_base_path')) {
    function devis_module_app_base_path(): string
    {
        $base = '';
        if (class_exists('\\AccessManager') && method_exists('\\AccessManager', 'getApplicationBasePath')) {
            try {
                $base = (string) \AccessManager::getApplicationBasePath();
            } catch (Throwable $e) {
                $base = '';
            }
        }

        if ($base !== '') {
            return rtrim($base, '/');
        }

        $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $directory = rtrim(dirname($scriptName), '/');
        if ($directory === '' || $directory === '.' || $directory === '/') {
            return '';
        }

        $segments = array_values(array_filter(explode('/', trim($directory, '/'))));
        $moduleSegments = ['devis', 'debourse', 'clients', 'offres', 'unites', 'chantiers'];
        if ($segments !== [] && in_array(end($segments), $moduleSegments, true)) {
            array_pop($segments);
        }

        return $segments !== [] ? '/' . implode('/', $segments) : '';
    }
}

if (!function_exists('devis_module_has_access')) {
    function devis_module_has_access(?string $permission): bool
    {
        if ($permission === null || $permission === '') {
            return true;
        }

        return \AccessManager::hasPermission($permission);
    }
}

if (!function_exists('devis_module_require_legacy_page')) {
    function devis_module_require_legacy_page(string $page): void
    {
        \AccessManager::requirePageAccess($page);
    }
}

if (!function_exists('devis_module_render_legacy_page')) {
    function devis_module_render_legacy_page(string $page, ?string $accessPage = null): void
    {
        devis_module_require_legacy_page($accessPage ?? basename($page));

        $root = dirname(__DIR__, 2);
        $legacyPath = $root . '/' . ltrim($page, '/');
        if (!is_file($legacyPath)) {
            http_response_code(404);
            echo 'Page introuvable.';
            return;
        }

        $baseHref = rtrim(devis_module_app_base_path(), '/') . '/';
        if ($baseHref === '/') {
            $baseHref = '/';
        }

        ob_start();
        $previousCwd = getcwd();
        try {
            chdir($root);
            require $legacyPath;
        } finally {
            if (is_string($previousCwd) && $previousCwd !== '') {
                chdir($previousCwd);
            }
        }
        $html = (string) ob_get_clean();
        $html = devis_module_strip_legacy_footer($html);

        $baseTag = '<base href="' . htmlspecialchars($baseHref, ENT_QUOTES, 'UTF-8') . '">';
        $assetBase = htmlspecialchars($baseHref, ENT_QUOTES, 'UTF-8');
        $moduleAssets = "\n"
            . '    <script src="' . $assetBase . 'js/vendor/documentation/tailwindcss.browser.js"></script>' . "\n"
            . '    <link rel="stylesheet" href="' . $assetBase . 'decaissement/assets/css/app.css">' . "\n";
        $moduleChrome = devis_module_legacy_chrome($accessPage ?? basename($page));
        $moduleFooter = devis_module_legacy_footer();
        $moduleStyle = <<<'HTML'
    <style>
        #notifMenu.hidden,
        #userMenu.hidden,
        #mobileMenu.hidden,
        #mailBadge.hidden,
        #notifBadge.hidden,
        #mailSlash.hidden,
        [x-cloak] {
            display: none;
        }
        body > nav.navbar,
        body > .navbar {
            display: none !important;
        }
        .finea-module-bar {
            background: linear-gradient(135deg, #0f172a 0%, #334155 58%, #0f766e 100%);
            color: #fff;
            box-shadow: 0 18px 40px rgba(15, 23, 42, .18);
        }
        .finea-module-inner {
            max-width: 1280px;
            margin: 0 auto;
            padding: 20px 16px;
        }
        .finea-module-title {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
            justify-content: space-between;
        }
        .finea-module-kicker {
            margin: 0 0 6px;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .18em;
            text-transform: uppercase;
            color: #a5f3fc;
        }
        .finea-module-title h1 {
            margin: 0;
            color: #fff !important;
            font-size: 24px;
            font-weight: 800;
        }
        .finea-module-title p {
            margin: 6px 0 0;
            max-width: 760px;
            color: #dbeafe;
            font-size: 13px;
        }
        .finea-module-actions,
        .finea-module-nav {
            display: flex;
            gap: 8px;
            overflow-x: auto;
            padding-bottom: 2px;
        }
        .finea-module-actions a,
        .finea-module-nav a {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
            border-radius: 8px;
            padding: 9px 12px;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
        }
        .finea-module-actions a {
            border: 1px solid rgba(255, 255, 255, .18);
            color: #fff;
        }
        .finea-module-nav {
            margin-top: 16px;
        }
        .finea-module-nav a {
            border: 1px solid rgba(255, 255, 255, .14);
            color: rgba(255, 255, 255, .88);
        }
        .finea-module-nav a.is-active {
            background: #fff;
            color: #0f172a;
            border-color: #fff;
        }
        body {
            background: linear-gradient(180deg, #f8fafc 0%, #eef2f7 100%) !important;
            color: #0f172a;
        }
        .navbar {
            background: linear-gradient(135deg, #0f172a 0%, #334155 58%, #0f766e 100%) !important;
            box-shadow: 0 18px 40px rgba(15, 23, 42, .18);
        }
        .navbar .container,
        body > .container {
            max-width: 1280px;
        }
        body > .container {
            margin-top: 24px;
            margin-bottom: 40px;
            padding: 24px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            background: rgba(255, 255, 255, .94);
            box-shadow: 0 18px 50px rgba(15, 23, 42, .08);
        }
        h1, .h1 {
            color: #0f172a !important;
            font-weight: 800 !important;
            letter-spacing: 0 !important;
        }
        .card {
            border: 1px solid #e2e8f0 !important;
            border-radius: 10px !important;
            box-shadow: 0 12px 30px rgba(15, 23, 42, .06) !important;
            overflow: hidden;
        }
        .card-header {
            background: #f8fafc !important;
            color: #0f172a !important;
            border-bottom: 1px solid #e2e8f0 !important;
            font-weight: 700;
        }
        .btn-primary,
        .btn-success {
            border-color: #0f172a !important;
            background: #0f172a !important;
        }
        .btn-primary:hover,
        .btn-success:hover {
            border-color: #0f766e !important;
            background: #0f766e !important;
        }
        .table thead th {
            background: #0f172a !important;
            color: #fff !important;
            border-color: #1e293b !important;
        }
        .form-control,
        .form-select,
        .select2-container .select2-selection--single {
            border-color: #cbd5e1 !important;
            border-radius: 8px !important;
        }
        .modal-content {
            border: 0 !important;
            border-radius: 12px !important;
            box-shadow: 0 24px 80px rgba(15, 23, 42, .22) !important;
        }
        body {
            padding: 28px 18px 48px !important;
            background: #f3f6fb !important;
        }
        body > header,
        body > .finea-module-bar,
        body > .container,
        body > main,
        body > section:not(.finea-legacy-aside),
        body > .quote-builder,
        body > form {
            width: auto;
            max-width: 1120px !important;
            margin-left: max(18px, calc((100vw - 1480px) / 2)) !important;
            margin-right: max(342px, calc((100vw - 1480px) / 2 + 324px)) !important;
        }
        .finea-module-bar {
            overflow: hidden;
            margin-top: 0 !important;
            margin-bottom: 24px !important;
            border: 1px solid rgba(255, 255, 255, .1);
            border-radius: 28px;
            background: linear-gradient(125deg, #101a38, #172348 68%, #263866) !important;
            box-shadow: 0 24px 60px rgba(23, 35, 72, .18);
        }
        .finea-module-bar::after {
            content: "";
            display: block;
            height: 5px;
            background: #fabd02;
        }
        .finea-module-inner {
            max-width: none;
            min-height: 190px;
            padding: 34px 38px 30px;
        }
        .finea-module-kicker {
            color: #f8d25d;
        }
        .finea-module-title h1 {
            font-size: clamp(28px, 3vw, 42px);
        }
        .finea-module-title p {
            color: rgba(255, 255, 255, .72);
            line-height: 1.6;
        }
        .finea-module-actions a {
            min-height: 42px;
            border-radius: 8px;
            background: rgba(255, 255, 255, .08);
        }
        .finea-module-actions a:last-child {
            border-color: #fabd02;
            background: #fabd02;
            color: #172348;
        }
        .finea-module-nav {
            display: none;
        }
        .finea-legacy-aside {
            position: fixed;
            z-index: 30;
            top: 98px;
            right: max(18px, calc((100vw - 1480px) / 2));
            overflow: hidden;
            width: 300px;
            max-height: calc(100vh - 120px);
            overflow-y: auto;
            border-radius: 18px;
            background: #101a38;
            box-shadow: 0 20px 45px rgba(23, 35, 72, .2);
        }
        .finea-legacy-aside-head {
            padding: 22px 20px 16px;
            border-bottom: 1px solid rgba(255, 255, 255, .09);
            color: #fff;
        }
        .finea-legacy-aside-head strong,
        .finea-legacy-aside-head small {
            display: block;
        }
        .finea-legacy-aside-head strong {
            font-size: 14px;
        }
        .finea-legacy-aside-head small {
            margin-top: 4px;
            color: rgba(255, 255, 255, .48);
            font-size: 11px;
        }
        .finea-legacy-nav {
            display: grid;
            gap: 5px;
            padding: 12px;
        }
        .finea-legacy-nav a {
            display: grid;
            grid-template-columns: 36px minmax(0, 1fr) 14px;
            align-items: center;
            gap: 10px;
            min-height: 50px;
            padding: 7px 10px;
            border-radius: 8px;
            color: rgba(255, 255, 255, .72);
            font-size: 12px;
            font-weight: 750;
            text-decoration: none;
        }
        .finea-legacy-nav a:hover {
            background: rgba(255, 255, 255, .08);
            color: #fff;
        }
        .finea-legacy-nav a.is-active {
            background: #fabd02;
            color: #172348;
        }
        .finea-legacy-nav .finea-nav-icon {
            display: inline-flex;
            width: 36px;
            height: 36px;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            background: rgba(255, 255, 255, .09);
        }
        .finea-legacy-nav a.is-active .finea-nav-icon {
            background: rgba(23, 35, 72, .1);
        }
        .finea-legacy-nav .fa-chevron-right {
            font-size: 9px;
        }
        body > .container {
            margin-top: 0 !important;
            padding: 24px;
            border-radius: 14px;
        }
        .table-responsive {
            border-radius: 10px;
        }
        .table thead th {
            background: #f7f9fc !important;
            color: #526079 !important;
            border-color: #dfe6f0 !important;
            font-size: 11px;
            letter-spacing: .04em;
            text-transform: uppercase;
        }
        .table tbody tr:hover > * {
            background: #f8faff !important;
        }
        .form-control,
        .form-select,
        .select2-container .select2-selection--single,
        textarea {
            min-height: 44px;
            border-radius: 8px !important;
        }
        .select2-container {
            width: 100% !important;
        }
        .btn {
            border-radius: 8px !important;
            font-weight: 700;
        }
        @media (max-width: 1100px) {
            body > header,
            body > .finea-module-bar,
            body > .container,
            body > main,
            body > section:not(.finea-legacy-aside),
            body > .quote-builder,
            body > form {
                max-width: none !important;
                margin-left: 0 !important;
                margin-right: 0 !important;
            }
            .finea-legacy-aside {
                position: static;
                width: auto;
                max-height: none;
                margin: 0 0 18px !important;
            }
            .finea-legacy-nav {
                display: flex;
                overflow-x: auto;
            }
            .finea-legacy-nav a {
                flex: 0 0 180px;
            }
        }
        @media (max-width: 700px) {
            body {
                padding: 14px 10px 36px !important;
            }
            .finea-module-bar {
                border-radius: 18px;
            }
            .finea-module-inner {
                min-height: 0;
                padding: 26px 22px;
            }
            .finea-module-title {
                align-items: flex-start;
                flex-direction: column;
            }
            .finea-module-actions {
                width: 100%;
            }
            .finea-module-actions a {
                flex: 1;
                justify-content: center;
            }
            body > .container {
                padding: 16px;
            }
        }
    </style>
HTML;
        if (($accessPage ?? basename($page)) === 'gerer_debourse.php') {
            $moduleStyle .= <<<'HTML'
    <style>
        /* Saisie des déboursés : navigation horizontale, formulaire pleine largeur. */
        body > header,
        body > .finea-module-bar,
        body > .container,
        body > main,
        body > section:not(.finea-legacy-aside),
        body > .quote-builder,
        body > form {
            width: min(100%, 1600px) !important;
            max-width: 1600px !important;
            margin-left: auto !important;
            margin-right: auto !important;
        }
        body > .container.debourse-page-shell { max-width: 1600px !important; }
        body > .finea-legacy-aside {
            position: static;
            display: flex;
            align-items: center;
            gap: 10px;
            width: min(100%, 1600px);
            max-height: none;
            margin: 0 auto 18px !important;
            overflow: hidden;
            border-radius: 16px;
        }
        .finea-legacy-aside-head {
            flex: 0 0 auto;
            padding: 10px 18px;
            border: 0;
            white-space: nowrap;
        }
        .finea-legacy-aside-head small { margin-top: 2px; }
        .finea-legacy-nav {
            display: flex;
            flex: 1 1 auto;
            gap: 5px;
            min-width: 0;
            overflow-x: auto;
            padding: 8px;
            scrollbar-width: thin;
        }
        .finea-legacy-nav a {
            display: inline-flex;
            flex: 0 0 auto;
            gap: 7px;
            min-height: 42px;
            padding: 6px 10px;
            white-space: nowrap;
        }
        .finea-legacy-nav .finea-nav-icon { width: 28px; height: 28px; }
        .finea-legacy-nav .fa-chevron-right { display: none; }
        @media (max-width: 700px) {
            body > .finea-legacy-aside { display: block; }
            .finea-legacy-aside-head { padding: 12px 15px 4px; }
            .finea-legacy-aside-head small { display: none; }
            .finea-legacy-nav { width: 100%; }
        }
    </style>
HTML;
        }
        if (stripos($html, '<base ') === false && stripos($html, '<head') !== false) {
            $html = preg_replace('~(<head\b[^>]*>)~i', '$1' . "\n    " . $baseTag . $moduleAssets . $moduleStyle, $html, 1) ?? $html;
        }
        if (stripos($html, '<body') !== false) {
            $html = preg_replace('~(<body\b[^>]*>)~i', '$1' . "\n" . $moduleChrome, $html, 1) ?? $html;
        }
        if (stripos($html, '</body>') !== false) {
            $html = preg_replace('~</body>~i', $moduleFooter . "\n</body>", $html, 1) ?? $html;
        }

        echo $html;
    }
}

if (!function_exists('devis_module_strip_legacy_footer')) {
    function devis_module_strip_legacy_footer(string $html): string
    {
        $patterns = [
            '~<footer\b[^>]*class=(["\'])(?:(?!\1).)*\bfooter\b(?:(?!\1).)*\1[^>]*>.*?</footer>~is',
            '~<footer\b[^>]*>.*?BANAMUR INDUSTRIES.*?</footer>~is',
            '~<footer\b[^>]*>.*?Tous droits r(?:&eacute;|é)serv(?:&eacute;|é)s.*?</footer>~is',
        ];

        foreach ($patterns as $pattern) {
            $html = preg_replace($pattern, '', $html) ?? $html;
        }

        return $html;
    }
}

if (!function_exists('devis_module_capture_include')) {
    function devis_module_capture_include(string $path, array $vars = []): string
    {
        if (!is_file($path)) {
            return '';
        }

        extract($vars, EXTR_SKIP);
        ob_start();
        require $path;
        return (string) ob_get_clean();
    }
}

if (!function_exists('devis_module_legacy_chrome')) {
    function devis_module_legacy_chrome(string $accessPage): string
    {
        global $devisModuleNav;

        $activeMap = [
            'dashboard.php' => 'dashboard',
            'generer_devis.php' => 'devis_create',
            'liste_devis.php' => 'devis_list',
            'modifier_devis.php' => 'devis_list',
            'liste_client.php' => 'clients',
            'modifier_client.php' => 'clients',
            'liste_offre.php' => 'offres',
            'modifier_offre.php' => 'offres',
            'liste_unite.php' => 'unites',
            'liste_debourse.php' => 'debourse',
            'gerer_debourse.php' => 'debourse',
            'voir_debourse.php' => 'debourse',
            'liste_chantier.php' => 'chantiers',
            'choisir_devis_pour_chantier.php' => 'chantiers',
            'debuter_chantier.php' => 'chantiers',
            'details_chantier.php' => 'chantiers',
        ];
        $active = $activeMap[$accessPage] ?? 'dashboard';
        $pageHeadings = [
            'generer_devis.php' => [
                'title' => 'Generer un devis',
                'subtitle' => 'Construisez un devis detaille, importez ses lignes et finalisez son document dans le standard FINEA.',
            ],
            'liste_devis.php' => [
                'title' => 'Liste des devis',
                'subtitle' => 'Consultez, filtrez et suivez les propositions commerciales.',
            ],
            'modifier_devis.php' => [
                'title' => 'Modifier le devis',
                'subtitle' => 'Mettez a jour les informations et les lignes du document.',
            ],
        ];
        $pageHeading = $pageHeadings[$accessPage] ?? [
            'title' => 'Centre devis et chantiers',
            'subtitle' => 'Formulaires, listings et suivi operationnel reunis dans un espace de travail coherent.',
        ];
        $root = static fn(string $path): string => devis_module_root_url($path);
        $appBase = rtrim(devis_module_app_base_path(), '/') . '/';
        $activeMenuMap = [
            'devis_create' => 'module_devis_create',
            'devis_list' => 'module_devis_list',
            'clients' => 'module_clients',
            'offres' => 'module_offres',
            'unites' => 'module_unites',
            'debourse' => 'module_debourse',
            'chantiers' => 'module_chantiers',
        ];
        $navbar = devis_module_capture_include(dirname(__DIR__, 2) . '/decaissement/includes/navbar.php', [
            'activeMenu' => $activeMenuMap[$active] ?? 'module_devis',
            'layoutRootBase' => $appBase,
            'layoutModuleBase' => $appBase . 'decaissement/',
            'layoutAssetBase' => $appBase . 'decaissement/assets/',
        ]);

        $nav = '<a class="' . ($active === 'dashboard' ? 'is-active' : '') . '" href="' . htmlspecialchars($root('devis/index.php'), ENT_QUOTES, 'UTF-8') . '"><span class="finea-nav-icon"><i class="fa-solid fa-gauge-high"></i></span><span>Vue d ensemble</span><i class="fa-solid fa-chevron-right"></i></a>';
        foreach ((array) $devisModuleNav as $key => $item) {
            if (!devis_module_has_access($item['permission'] ?? null)) {
                continue;
            }
            $navHref = (string) ($item['dashboard_href'] ?? $item['href']);
            if (!preg_match('~^(?:https?:)?//~i', $navHref)) {
                $navHref = $root((string) preg_replace('~^(?:\.\./)+~', '', $navHref));
            }
            $nav .= '<a class="' . ($active === $key ? 'is-active' : '') . '" href="' . htmlspecialchars($navHref, ENT_QUOTES, 'UTF-8') . '"><span class="finea-nav-icon"><i class="fa-solid ' . htmlspecialchars((string) $item['icon'], ENT_QUOTES, 'UTF-8') . '"></i></span><span>' . htmlspecialchars((string) $item['label'], ENT_QUOTES, 'UTF-8') . '</span><i class="fa-solid fa-chevron-right"></i></a>';
        }

        return $navbar . '<header class="finea-module-bar"><div class="finea-module-inner">'
            . '<div class="finea-module-title"><div><p class="finea-module-kicker">Finea / Direction commerciale</p>'
            . '<h1>' . htmlspecialchars($pageHeading['title'], ENT_QUOTES, 'UTF-8') . '</h1><p>' . htmlspecialchars($pageHeading['subtitle'], ENT_QUOTES, 'UTF-8') . '</p></div>'
            . '<div class="finea-module-actions"><a href="' . htmlspecialchars($root('selection_portail.php'), ENT_QUOTES, 'UTF-8') . '"><i class="fa-solid fa-table-cells-large"></i>Portail</a>'
            . (devis_module_has_access('transferer_historique_devis') ? '<a href="' . htmlspecialchars($root('devis/transfert_financier.php'), ENT_QUOTES, 'UTF-8') . '"><i class="fa-solid fa-code-merge"></i>Fusion financière</a>' : '')
            . '<a href="' . htmlspecialchars($root('devis/parametrage.php'), ENT_QUOTES, 'UTF-8') . '"><i class="fa-solid fa-sliders"></i>Parametrage</a></div></div>'
            . '</div></header>'
            . '<aside class="finea-legacy-aside" aria-label="Navigation du centre devis et chantiers">'
            . '<div class="finea-legacy-aside-head"><strong>Centre devis &amp; chantiers</strong><small>Navigation rapide</small></div>'
            . '<nav class="finea-legacy-nav">' . $nav . '</nav></aside>';
    }
}

if (!function_exists('devis_module_legacy_footer')) {
    function devis_module_legacy_footer(): string
    {
        $appBase = rtrim(devis_module_app_base_path(), '/') . '/';
        $footer = devis_module_capture_include(dirname(__DIR__, 2) . '/decaissement/includes/footer.php', [
            'layoutRootBase' => $appBase,
            'layoutModuleBase' => $appBase . 'decaissement/',
            'layoutAssetBase' => $appBase . 'decaissement/assets/',
        ]);
        $footer = preg_replace('~</body>\s*</html>\s*$~i', '', $footer) ?? $footer;

        return $footer;
    }
}

$devisModuleController = new \DevisModuleController(\Database::getConnection());
$devisModuleNav = $devisModuleController->modules();
