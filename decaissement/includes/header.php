<?php
// Header commun. Utiliser $pageTitle si défini.

if (!function_exists('resolveLayoutRelativeRoot')) {
    function resolveLayoutRelativeRoot(): string
    {
        static $cached = null;

        if ($cached !== null) {
            return $cached;
        }

        $projectRoot = realpath(dirname(__DIR__, 2));
        $scriptFilename = $_SERVER['SCRIPT_FILENAME'] ?? null;

        if (!$projectRoot || !$scriptFilename) {
            return $cached = '../';
        }

        $scriptDirectory = realpath(dirname($scriptFilename));
        if (!$scriptDirectory) {
            return $cached = '../';
        }

        $projectRoot = str_replace('\\', '/', $projectRoot);
        $scriptDirectory = str_replace('\\', '/', $scriptDirectory);

        if (strpos($scriptDirectory, $projectRoot) !== 0) {
            return $cached = '../';
        }

        $relativeDirectory = trim(substr($scriptDirectory, strlen($projectRoot)), '/');
        if ($relativeDirectory === '') {
            return $cached = '';
        }

        $depth = substr_count($relativeDirectory, '/') + 1;
        return $cached = str_repeat('../', $depth);
    }
}

$layoutRootBase = $layoutRootBase ?? resolveLayoutRelativeRoot();
$layoutModuleBase = $layoutModuleBase ?? $layoutRootBase . 'decaissement/';
$layoutAssetBase = $layoutAssetBase ?? $layoutModuleBase . 'assets/';
$layoutProjectRoot = dirname(__DIR__, 2);
$layoutIsPublicAuthPage = in_array(basename((string)($_SERVER['SCRIPT_NAME'] ?? '')), ['login.php', 'reset_password.php'], true);
require_once $layoutProjectRoot . '/model/CompanyBranding.php';
// Branding de l'instance, également sur les pages publiques d'authentification.
$layoutCompanyBranding = CompanyBranding::get();
$layoutBrandIconUrl = !empty($layoutCompanyBranding['icon_path']) || !empty($layoutCompanyBranding['logo_path']) ? $layoutRootBase . 'branding_asset.php?type=icon' : '';
$layoutBrandLogoUrl = !empty($layoutCompanyBranding['logo_path']) ? $layoutRootBase . 'branding_asset.php?type=logo' : '';
$layoutIsRhModule = preg_match('~(?:^|/)rh/[^/]+\\.php$~i', str_replace('\\\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''))) === 1;
$layoutDirectoryScript = str_replace(chr(92), '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
$layoutIsSupplierDirectory = preg_match('~(?:^|/)fournisseur/[^/]+[.]php$~i', $layoutDirectoryScript) === 1;
$layoutIsAddressBook = in_array(basename($layoutDirectoryScript), ['carnet_adresse.php','fiche_contact.php'], true);
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title><?= isset($pageTitle) ? htmlspecialchars($pageTitle) : 'Finéa' ?></title>
    <script>
        (function () {
            try {
                var theme = localStorage.getItem('finea.app.theme') || localStorage.getItem('finea.portal.theme') || 'light';
                document.documentElement.classList.toggle('finea-dark-mode', theme === 'dark');
            } catch (e) {}
        })();
    </script>
    <?php if ($layoutBrandIconUrl !== ''): ?>
        <link rel="icon" type="image/png" href="<?= htmlspecialchars($layoutBrandIconUrl, ENT_QUOTES, 'UTF-8') ?>">
        <link rel="shortcut icon" type="image/png" href="<?= htmlspecialchars($layoutBrandIconUrl, ENT_QUOTES, 'UTF-8') ?>">
        <link rel="apple-touch-icon" href="<?= htmlspecialchars($layoutBrandIconUrl, ENT_QUOTES, 'UTF-8') ?>">
    <?php endif; ?>
    <script src="<?= htmlspecialchars($layoutRootBase, ENT_QUOTES, 'UTF-8') ?>js/vendor/documentation/tailwindcss.browser.js"></script>
    <link rel="stylesheet" href="<?= htmlspecialchars($layoutModuleBase, ENT_QUOTES, 'UTF-8') ?>plugins/css/fontawesome/all.min.css">
    <?php if (!$layoutIsPublicAuthPage): ?><script src="<?= htmlspecialchars($layoutRootBase, ENT_QUOTES, 'UTF-8') ?>js/vendor/documentation/chart.min.js"></script><?php endif; ?>
    <style>
        /*
         * Le shell Finéa ne doit jamais agrandir la page au-delà du viewport.
         * Les contenus réellement larges (tableaux, chronologies, etc.) gardent
         * leur défilement dans un conteneur local avec overflow-x: auto.
         */
        html,
        body {
            width: 100%;
            max-width: 100%;
            overflow-x: clip;
        }

        *,
        *::before,
        *::after {
            box-sizing: border-box;
        }

        body > *,
        main,
        section,
        article,
        aside,
        .container {
            min-width: 0;
            max-width: 100%;
        }

        img,
        video,
        canvas,
        svg {
            max-width: 100%;
        }

        [class*="overflow-x-auto"] {
            width: 100%;
            max-width: 100%;
            overscroll-behavior-x: contain;
        }

        @supports not (overflow: clip) {
            html,
            body {
                overflow-x: hidden;
            }
        }

        @keyframes fade-in {
            from {
                opacity: 0;
                transform: translateY(-6px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .animate-fade-in {
            animation: fade-in .2s ease-out;
        }

        .fiche-selected {
            background-color: #f3f4f6 !important;
            opacity: .92;
        }

        .fiche-bureau {
            border-left: 6px solid #a78bfa;
        }

        .fiche-chantier {
            border-left: 6px solid #60a5fa;
        }

        .fiche-autre {
            border-left: 6px solid #9ca3af;
        }
    </style>
    <link rel="stylesheet" href="<?= htmlspecialchars($layoutAssetBase, ENT_QUOTES, 'UTF-8') ?>css/app.css" />
    <link rel="stylesheet" href="<?= htmlspecialchars($layoutRootBase, ENT_QUOTES, 'UTF-8') ?>assets/css/finea-ui.css" />
    <link rel="stylesheet" href="<?= htmlspecialchars($layoutRootBase, ENT_QUOTES, 'UTF-8') ?>assets/css/finea-theme.css?v=20260827" />
    <link rel="stylesheet" href="<?= htmlspecialchars($layoutRootBase, ENT_QUOTES, 'UTF-8') ?>branding_theme.php?v=<?= rawurlencode((string)($layoutCompanyBranding['updated_at'] ?? '1')) ?>" />
<script src="<?= htmlspecialchars($layoutRootBase, ENT_QUOTES, 'UTF-8') ?>assets/js/financial-workflow-progress.js?v=<?= filemtime(__DIR__.'/../../assets/js/financial-workflow-progress.js') ?>" defer></script>
<?php if ($layoutIsRhModule): ?>
<link rel="stylesheet" href="<?= htmlspecialchars($layoutRootBase, ENT_QUOTES, 'UTF-8') ?>rh/assets/module-theme.css?v=<?= filemtime(__DIR__.'/../../rh/assets/module-theme.css') ?>">
<?php endif; ?>
<?php if ($layoutIsSupplierDirectory || $layoutIsAddressBook): ?>
<link rel="stylesheet" href="<?= htmlspecialchars($layoutRootBase, ENT_QUOTES, 'UTF-8') ?>assets/css/directory-dark-compat.css?v=<?= filemtime(__DIR__.'/../../assets/css/directory-dark-compat.css') ?>">
<?php endif; ?>
</head>

<body class="min-h-screen<?= $layoutIsRhModule ? ' finea-rh-module' : '' ?><?= $layoutIsSupplierDirectory ? ' supplier-module-screen' : '' ?><?= $layoutIsAddressBook ? ' address-book-screen' : '' ?>" style="background: radial-gradient(1200px 600px at 0% 0%, rgb(var(--brand-accent-rgb, 255 204 32) / .10) 0%, transparent 70%), radial-gradient(1200px 600px at 100% 0%, rgb(var(--brand-primary-rgb, 5 5 5) / .06) 0%, transparent 70%), linear-gradient(180deg, var(--brand-surface, #ffffff) 0%, color-mix(in srgb, var(--brand-surface, #ffffff) 96%, var(--brand-primary, #050505)) 100%);">

