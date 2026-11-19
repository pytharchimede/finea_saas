<?php
require_once __DIR__ . '/model/Auth.php';
require_once __DIR__ . '/model/AuditTrail.php';
require_once __DIR__ . '/model/AccessManager.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Vérifier si l'utilisateur est connecté
if (!\Auth::isLoggedIn()) {
    $loginBasePath = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/acces_refuse.php'))), '/');
    header('Location: ' . ($loginBasePath !== '' ? $loginBasePath : '') . '/login.php');
    exit();
}

$pageTitle = 'Accès Refusé';
$activeMenu = '';

// Déterminer le type d'erreur d'accès
$accessType = $_GET['type'] ?? 'general';
$requestedPage = $_GET['page'] ?? 'page demandée';

function accessDeniedNormalizePermissions($permission): array
{
    if (is_array($permission)) {
        return array_values(array_filter($permission, static function ($item): bool {
            return is_string($item) && trim($item) !== '';
        }));
    }

    return is_string($permission) && trim($permission) !== '' ? [trim($permission)] : [];
}

function accessDeniedPermissionLabel(string $permission): string
{
    static $map = [
        'acces_admin' => 'Accès Administration',
        'acces_dashboard' => 'Accès Tableau de bord',
        'acces_devis' => 'Accès Devis',
        'acces_generer_devis' => 'Accès Génération devis',
        'acces_gestion_acces' => 'Accès Gestion des accès',
        'acces_decaissement' => 'Accès Décaissement',
        'acces_dec_chantiers' => 'Accès Décaissement Chantiers',
        'acces_budgets_chantiers' => 'Accès Budgets chantiers',
        'acces_conformite' => 'Accès Conformité',
        'acces_approbation' => 'Accès Approbation',
        'acces_achat' => 'Accès Achat',
        'acces_compta' => 'Accès Comptabilité',
        'acces_fiches_decaissement' => 'Accès Liste fiches décaissement',
        'acces_otp' => 'Accès OTP',
        'acces_point_financier' => 'Accès Point financier',
        'acces_relink_fiches_debourse' => 'Accès Relink fiches déboursé',
        'acces_historique_fiches' => 'Accès Historique fiches',
        'acces_fiche_details' => 'Accès Détail fiche',
        'acces_recap_carburant' => 'Accès Récap carburant',
        'acces_operations_carburant' => 'Accès Opérations carburant',
        'acces_creation_fiche_decaissement' => 'Accès Création fiche décaissement',
        'acces_liste_chantier' => 'Accès Liste chantiers',
        'acces_detail_chantier' => 'Accès Détail chantier',
        'acces_liste_client' => 'Accès Liste clients',
        'acces_liste_facture' => 'Accès Liste factures',
        'acces_liste_offre' => 'Accès liste des projets',
        'acces_rh' => 'Accès Ressources humaines',
        'acces_logistique' => 'Accès Logistique',
        'acces_fournisseur' => 'Accès Fournisseurs',
        'acces_facturation' => 'Accès Facturation',
        'acces_mail' => 'Accès Emails / Mailing',
        'acces_communication' => 'Accès Communication',
        'acces_documentation' => 'Accès Documentation',
        'acces_formation' => 'Accès Formation',
        'acces_crm' => 'Accès CRM',
        'acces_direction' => 'Accès Direction',
        'acces_planning' => 'Accès Planning',
        'acces_emploi_du_temps' => 'Accès Emploi du temps',
        'acces_employe' => 'Accès Espace employé',
        'modifier_debourse' => 'Permission Modifier déboursé',
        'modifier_devis' => 'Permission Modifier devis',
        'visualiser_devis' => 'Permission Visualiser devis',
        'soumettre_devis' => 'Permission Soumettre devis',
        'valider_devis' => 'Permission Valider devis',
        'envoyer_devis' => 'Permission Envoyer devis',
        'masquer_devis' => 'Permission Masquer devis',
    ];

    if (isset($map[$permission])) {
        return $map[$permission];
    }

    $label = str_replace('_', ' ', $permission);
    return ucfirst(trim($label));
}

function accessDeniedPageLabel(string $page): string
{
    $page = basename($page);
    $label = preg_replace('/\.php$/i', '', $page);
    $label = str_replace(['_', '-'], ' ', (string)$label);
    $label = preg_replace('/\s+/', ' ', (string)$label);
    return ucwords(trim((string)$label));
}

function accessDeniedModuleLabel(array $permissions, string $page): string
{
    $permission = (string)($permissions[0] ?? '');
    $map = [
        'acces_admin' => 'Administration',
        'acces_dashboard' => 'Tableau de bord',
        'acces_devis' => 'Devis',
        'acces_decaissement' => 'Décaissement',
        'acces_dec_chantiers' => 'Décaissement',
        'acces_budgets_chantiers' => 'Décaissement',
        'acces_conformite' => 'Décaissement',
        'acces_approbation' => 'Décaissement',
        'acces_achat' => 'Décaissement',
        'acces_compta' => 'Décaissement',
        'acces_point_financier' => 'Décaissement',
        'acces_liste_chantier' => 'Chantiers',
        'acces_detail_chantier' => 'Chantiers',
        'acces_liste_client' => 'Clients',
        'acces_liste_facture' => 'Factures',
        'acces_liste_offre' => 'Projets',
        'acces_rh' => 'Ressources humaines',
        'acces_logistique' => 'Logistique',
        'acces_fournisseur' => 'Fournisseurs',
        'acces_facturation' => 'Facturation',
        'acces_mail' => 'Emails',
        'acces_communication' => 'Communication',
        'acces_documentation' => 'Documentation',
        'acces_formation' => 'Formation',
        'acces_crm' => 'CRM',
        'acces_direction' => 'Direction',
        'acces_planning' => 'Planning',
        'acces_emploi_du_temps' => 'Emploi du temps',
        'acces_employe' => 'Espace employé',
    ];

    if (isset($map[$permission])) {
        return $map[$permission];
    }
    if (str_contains($page, 'decaisse')) {
        return 'Décaissement';
    }
    return 'Applications';
}

function accessDeniedIsBrowsablePage(string $page): bool
{
    $page = basename($page);
    if ($page === '' || !str_ends_with(strtolower($page), '.php')) {
        return false;
    }

    $prefixes = ['api_', 'export_', 'download_', 'debug_', 'test_', 'sync_', 'migration_', 'migrate_', 'check_', '__tmp_', 'tmp_'];
    foreach ($prefixes as $prefix) {
        if (str_starts_with(strtolower($page), $prefix)) {
            return false;
        }
    }

    $needles = ['_api.php', '_sse.php', 'logout_', 'attachment_delete', 'typing_poll', 'typing.php', 'send.php', 'comment.php', 'state.php', 'messages.php', 'tasks.php', 'attachments.php'];
    foreach ($needles as $needle) {
        if (str_contains(strtolower($page), strtolower($needle))) {
            return false;
        }
    }

    return true;
}

function accessDeniedBuildAccessiblePageGroups(array $sessionPermissions): array
{
    $appBasePath = accessDeniedAppBasePath();
    $groups = [];
    foreach (\AccessManager::PAGE_PERMISSIONS as $page => $permissionConfig) {
        if (!accessDeniedIsBrowsablePage($page)) {
            continue;
        }

        $permissions = accessDeniedNormalizePermissions($permissionConfig);
        $hasAccess = false;
        foreach ($permissions as $permission) {
            if (!empty($sessionPermissions['acces_admin']) || !empty($sessionPermissions[$permission])) {
                $hasAccess = true;
                break;
            }
        }

        if (!$hasAccess) {
            continue;
        }

        $module = accessDeniedModuleLabel($permissions, $page);
        if (!isset($groups[$module])) {
            $groups[$module] = [];
        }

        $groups[$module][$page] = [
            'page' => $page,
            'label' => accessDeniedPageLabel($page),
            'url' => $appBasePath . '/' . ltrim(str_replace('\\', '/', $page), '/'),
        ];
    }

    ksort($groups);
    foreach ($groups as &$items) {
        uasort($items, static function (array $left, array $right): int {
            return strcasecmp($left['label'], $right['label']);
        });
        $items = array_slice(array_values($items), 0, 8);
    }
    unset($items);

    return $groups;
}

function accessDeniedAppBasePath(): string
{
    $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/acces_refuse.php'));
    $basePath = rtrim(dirname($scriptName), '/');
    if ($basePath === '/' || $basePath === '.') {
        return '';
    }

    return $basePath;
}

$messages = [
    'devis' => [
        'title' => 'Accès refusé - Module Devis',
        'message' => 'Vous n\'avez pas les autorisations nécessaires pour accéder au module de gestion des devis.',
        'icon' => '<i class="fas fa-file-invoice"></i>'
    ],
    'decaissement' => [
        'title' => 'Accès refusé - Module Décaissement',
        'message' => 'Vous n\'avez pas les autorisations nécessaires pour accéder au module de décaissement.',
        'icon' => '<i class="fas fa-money-bill-wave"></i>'
    ],
    'dashboard' => [
        'title' => 'Accès refusé - Tableau de bord',
        'message' => 'Vous n\'avez pas les autorisations nécessaires pour accéder au tableau de bord.',
        'icon' => '<i class="fas fa-chart-line"></i>'
    ],
    'direction' => [
        'title' => 'Accès refusé - Direction',
        'message' => 'Vous n\'avez pas les autorisations nécessaires pour accéder à l\'espace Direction.',
        'icon' => '<i class="fas fa-sitemap"></i>'
    ],
    'planning' => [
        'title' => 'Accès refusé - Planning',
        'message' => 'Vous n\'avez pas les autorisations nécessaires pour accéder au suivi planning.',
        'icon' => '<i class="fas fa-calendar-check"></i>'
    ],
    'emploi_du_temps' => [
        'title' => 'Accès refusé - Emploi du temps',
        'message' => 'Vous n\'avez pas les autorisations nécessaires pour accéder à l\'emploi du temps.',
        'icon' => '<i class="fas fa-calendar-days"></i>'
    ],
    'employe' => [
        'title' => 'Accès refusé - Espace Employé',
        'message' => 'Vous n\'avez pas les autorisations nécessaires pour accéder à l\'espace employé.',
        'icon' => '<i class="fas fa-user"></i>'
    ],
    'chantier' => [
        'title' => 'Accès refusé - Gestion Chantiers',
        'message' => 'Vous n\'avez pas les autorisations nécessaires pour accéder à la gestion des chantiers.',
        'icon' => '<i class="fas fa-hard-hat"></i>'
    ],
    'client' => [
        'title' => 'Accès refusé - Gestion Clients',
        'message' => 'Vous n\'avez pas les autorisations nécessaires pour accéder à la gestion des clients.',
        'icon' => '<i class="fas fa-users"></i>'
    ],
    'facture' => [
        'title' => 'Accès refusé - Gestion Factures',
        'message' => 'Vous n\'avez pas les autorisations nécessaires pour accéder à la gestion des factures.',
        'icon' => '<i class="fas fa-receipt"></i>'
    ],
    'conformite' => [
        'title' => 'Accès refusé - Module Conformité',
        'message' => 'Vous n\'avez pas les autorisations nécessaires pour accéder au module de conformité.',
        'icon' => '<i class="fas fa-check-circle"></i>'
    ],
    'gestion_acces' => [
        'title' => 'Accès refusé - Gestion des Accès',
        'message' => 'Vous n\'avez pas les autorisations nécessaires pour gérer les accès utilisateurs.',
        'icon' => '<i class="fas fa-user-lock"></i>'
    ],
    'rh' => [
        'title' => 'Accès refusé - Ressources Humaines',
        'message' => 'Vous n\'avez pas les autorisations nécessaires pour accéder au module de gestion des ressources humaines.',
        'icon' => '<i class="fas fa-user-group"></i>'
    ],
    'logistique' => [
        'title' => 'Accès refusé - Logistique',
        'message' => 'Vous n\'avez pas les autorisations nécessaires pour accéder au module de gestion logistique.',
        'icon' => '<i class="fas fa-truck-fast"></i>'
    ],
    'fournisseur' => [
        'title' => 'Accès refusé - Fournisseurs',
        'message' => 'Vous n\'avez pas les autorisations nécessaires pour accéder au module de gestion des fournisseurs.',
        'icon' => '<i class="fas fa-industry"></i>'
    ],
    'facturation' => [
        'title' => 'Accès refusé - Facturation',
        'message' => 'Vous n\'avez pas les autorisations nécessaires pour accéder au module de facturation.',
        'icon' => '<i class="fas fa-receipt"></i>'
    ],
    'mail' => [
        'title' => 'Accès refusé - Emails / Mailing',
        'message' => 'Vous n\'avez pas les autorisations nécessaires pour accéder à la boîte email professionnelle.',
        'icon' => '<i class="fas fa-envelope"></i>'
    ],
    'general' => [
        'title' => 'Accès refusé',
        'message' => 'Vous n\'avez pas les autorisations nécessaires pour accéder à cette ressource.',
        'icon' => '<i class="fas fa-ban"></i>'
    ]
];

$currentMessage = $messages[$accessType] ?? $messages['general'];
$appBasePath = accessDeniedAppBasePath();
$userDisplayName = trim((string)($_SESSION['user_name'] ?? trim((string)(($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? '')))));
if ($userDisplayName === '') {
    $userDisplayName = 'Utilisateur';
}

$requestedPagePath = (string)$requestedPage;
$requestedPageKey = basename((string)(parse_url($requestedPagePath, PHP_URL_PATH) ?: $requestedPagePath));
$requiredPermissions = accessDeniedNormalizePermissions(\AccessManager::PAGE_PERMISSIONS[$requestedPageKey] ?? []);
if ($requiredPermissions === [] && str_contains(str_replace('\\', '/', $requestedPagePath), '/decaissement/')) {
    $requiredPermissions = ['acces_decaissement'];
}
$primaryRequiredPermission = (string)($requiredPermissions[0] ?? '');
$alternativePermissions = array_slice($requiredPermissions, 1);
$requiredPermissionLabel = $primaryRequiredPermission !== '' ? accessDeniedPermissionLabel($primaryRequiredPermission) : 'Accès non déterminé automatiquement';
$requiredAccessTypeLabel = $primaryRequiredPermission !== '' ? (\AccessManager::ACCESS_TYPES[$primaryRequiredPermission] ?? 'general') : 'general';
$accessiblePageGroups = accessDeniedBuildAccessiblePageGroups((array)($_SESSION['permissions'] ?? []));
$accessiblePageCount = 0;
foreach ($accessiblePageGroups as $items) {
    $accessiblePageCount += count($items);
}

$mailSubject = sprintf('Demande d\'accès %s pour %s', $requestedPageKey !== '' ? $requestedPageKey : 'application', $userDisplayName);
$mailLines = [
    'Bonjour équipe informatique,',
    '',
    'Merci de bien vouloir attribuer l\'accès suivant à l\'utilisateur ci-dessous :',
    '',
    'Utilisateur : ' . $userDisplayName,
    'Page demandée : ' . ($requestedPageKey !== '' ? $requestedPageKey : $requestedPagePath),
    'Accès principal recommandé : ' . $requiredPermissionLabel . ($primaryRequiredPermission !== '' ? ' (' . $primaryRequiredPermission . ')' : ''),
];
if ($alternativePermissions) {
    $mailLines[] = 'Accès alternatifs acceptés : ' . implode(', ', array_map(static function (string $permission): string {
        return accessDeniedPermissionLabel($permission) . ' (' . $permission . ')';
    }, $alternativePermissions));
}
$mailLines[] = 'Type de module : ' . ucfirst((string)$requiredAccessTypeLabel);
$mailLines[] = '';
$mailLines[] = 'Contexte métier :';
$mailLines[] = '[à compléter par le demandeur]';
$mailLines[] = '';
$mailLines[] = 'Cordialement.';
$copyRequestText = implode("\r\n", $mailLines);
$mailtoHref = 'mailto:ulrich@banamur.com?subject=' . rawurlencode($mailSubject) . '&body=' . rawurlencode(implode("\r\n", $mailLines));
$originUrl = $appBasePath . '/selection_portail.php';
if ($requestedPagePath !== '') {
    $parsedRequestedPath = (string)(parse_url($requestedPagePath, PHP_URL_PATH) ?: $requestedPagePath);
    if ($parsedRequestedPath !== '' && !preg_match('~^https?://~i', $parsedRequestedPath)) {
        $normalizedRequestedPath = '/' . ltrim(str_replace('\\', '/', $parsedRequestedPath), '/');
        $requestedPageUrl = str_starts_with($normalizedRequestedPath, $appBasePath . '/')
            ? $normalizedRequestedPath
            : $appBasePath . $normalizedRequestedPath;
    }
}
if (empty($requestedPageUrl ?? '')) {
    $requestedPageUrl = $appBasePath . '/selection_portail.php';
}
if (!empty($_SERVER['HTTP_REFERER']) && is_string($_SERVER['HTTP_REFERER'])) {
    $referer = trim((string)$_SERVER['HTTP_REFERER']);
    if ($referer !== '' && stripos($referer, 'acces_refuse.php') === false) {
        $originUrl = $referer;
    }
}

\AuditTrail::logPageConsultation([
    'module' => 'security',
    'action' => 'access_denied_page_view',
    'entity_type' => 'access_denied',
    'entity_label' => (string)$requestedPage,
    'page_name' => 'acces_refuse.php',
    'details' => [
        'access_type' => $accessType,
        'requested_page' => $requestedPage,
        'view_mode' => 'error_screen',
    ],
]);

?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($currentMessage['title']) ?> - Banamur</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        :root {
            --page-navy: #0f172a;
            --page-ink: #172554;
            --page-soft: #eef4ff;
            --page-card: rgba(255, 255, 255, 0.88);
            --page-border: rgba(148, 163, 184, 0.28);
            --page-shadow: 0 32px 80px rgba(15, 23, 42, 0.22);
            --danger: #d92d20;
            --danger-soft: rgba(217, 45, 32, 0.12);
            --accent: #0f766e;
            --accent-soft: rgba(15, 118, 110, 0.14);
        }

        * {
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            margin: 0;
            font-family: 'Manrope', sans-serif;
            color: var(--page-navy);
            background:
                radial-gradient(circle at top left, rgba(14, 165, 233, 0.22), transparent 28%),
                radial-gradient(circle at right 20%, rgba(217, 45, 32, 0.18), transparent 26%),
                linear-gradient(135deg, #f4f7fb 0%, #eef2ff 46%, #e0f2fe 100%);
        }

        .access-page {
            position: relative;
            min-height: 100vh;
            padding: 32px 18px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .access-page::before,
        .access-page::after {
            content: '';
            position: fixed;
            border-radius: 999px;
            filter: blur(6px);
            z-index: 0;
        }

        .access-page::before {
            top: 48px;
            left: 28px;
            width: 180px;
            height: 180px;
            background: rgba(14, 165, 233, 0.12);
        }

        .access-page::after {
            right: 26px;
            bottom: 34px;
            width: 220px;
            height: 220px;
            background: rgba(99, 102, 241, 0.12);
        }

        .access-shell {
            position: relative;
            z-index: 1;
            width: min(1120px, 100%);
        }

        .access-denied-card {
            background: var(--page-card);
            backdrop-filter: blur(18px);
            border: 1px solid var(--page-border);
            border-radius: 32px;
            box-shadow: var(--page-shadow);
            overflow: hidden;
        }

        .access-layout {
            display: grid;
            grid-template-columns: minmax(0, 1.2fr) minmax(290px, 0.8fr);
        }

        .access-main {
            padding: 40px 40px 34px;
            background:
                radial-gradient(circle at top left, rgba(255, 255, 255, 0.72), transparent 34%),
                linear-gradient(180deg, rgba(255, 255, 255, 0.94) 0%, rgba(248, 250, 252, 0.88) 100%);
        }

        .access-side {
            padding: 40px 32px;
            background:
                linear-gradient(180deg, rgba(15, 23, 42, 0.95) 0%, rgba(30, 41, 59, 0.94) 100%),
                linear-gradient(135deg, rgba(8, 145, 178, 0.24), rgba(99, 102, 241, 0.18));
            color: #e5eefc;
            position: relative;
        }

        .access-side::before {
            content: '';
            position: absolute;
            inset: 18px;
            border-radius: 24px;
            border: 1px solid rgba(255, 255, 255, 0.09);
            pointer-events: none;
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 14px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.8);
            border: 1px solid rgba(191, 219, 254, 0.9);
            color: #0369a1;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 0.18em;
            text-transform: uppercase;
        }

        .hero-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
        }

        .status-hero {
            margin-top: 24px;
            display: flex;
            align-items: flex-start;
            gap: 18px;
        }

        .icon-container {
            width: 92px;
            height: 92px;
            border-radius: 28px;
            display: grid;
            place-items: center;
            flex-shrink: 0;
            font-size: 2.5rem;
            color: var(--danger);
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.98), rgba(255, 237, 213, 0.9));
            box-shadow: inset 0 0 0 1px rgba(248, 113, 113, 0.12), 0 18px 38px rgba(217, 45, 32, 0.18);
        }

        .error-kicker {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 999px;
            background: var(--danger-soft);
            color: var(--danger);
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 0.12em;
            text-transform: uppercase;
        }

        .error-title {
            margin: 14px 0 10px;
            color: var(--page-navy);
            font-size: clamp(2rem, 4vw, 3.2rem);
            line-height: 1.03;
            font-weight: 800;
            letter-spacing: -0.05em;
        }

        .error-message {
            margin: 0;
            max-width: 620px;
            color: #475569;
            font-size: 1.04rem;
            line-height: 1.8;
        }

        .meta-grid {
            margin-top: 28px;
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }

        .meta-card {
            border-radius: 20px;
            padding: 18px 18px 16px;
            background: rgba(255, 255, 255, 0.86);
            border: 1px solid rgba(226, 232, 240, 0.95);
            box-shadow: 0 14px 34px rgba(15, 23, 42, 0.06);
            text-align: left;
        }

        .meta-label {
            display: block;
            margin-bottom: 6px;
            color: #64748b;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.16em;
            text-transform: uppercase;
        }

        .meta-value {
            color: var(--page-navy);
            font-size: 1rem;
            font-weight: 700;
            word-break: break-word;
        }

        .module-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 16px;
            padding: 10px 14px;
            border-radius: 999px;
            background: rgba(15, 118, 110, 0.08);
            color: var(--accent);
            font-size: 13px;
            font-weight: 700;
        }

        .access-requirement-card {
            margin-top: 22px;
            border-radius: 24px;
            padding: 22px;
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.96) 0%, rgba(248, 250, 252, 0.92) 100%);
            border: 1px solid rgba(226, 232, 240, 0.95);
            box-shadow: 0 16px 30px rgba(15, 23, 42, 0.07);
        }

        .section-kicker {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #0f766e;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.16em;
            text-transform: uppercase;
        }

        .section-title {
            margin: 12px 0 8px;
            color: var(--page-navy);
            font-size: 1.2rem;
            font-weight: 800;
            letter-spacing: -0.03em;
        }

        .section-text {
            margin: 0;
            color: #475569;
            font-size: 0.95rem;
            line-height: 1.7;
        }

        .action-row-top {
            margin-top: 18px;
            margin-bottom: 12px;
        }

        .permission-badges {
            margin-top: 16px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .copy-cta {
            margin-top: 12px;
            width: 100%;
            background: rgba(255, 255, 255, 0.08);
            color: #f8fafc;
            border: 1px solid rgba(255, 255, 255, 0.14);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.04);
        }

        .copy-cta:hover {
            border-color: rgba(125, 211, 252, 0.38);
            color: #fff;
        }

        .send-feedback {
            margin-top: 12px;
            min-height: 22px;
            font-size: 0.9rem;
            font-weight: 700;
            color: rgba(226, 232, 240, 0.92);
        }

        .permission-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 14px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 700;
        }

        .copy-feedback {
            margin-top: 10px;
            min-height: 20px;
            color: rgba(191, 219, 254, 0.92);
            font-size: 0.85rem;
            font-weight: 600;
        }

        .permission-badge-primary {
            background: rgba(15, 118, 110, 0.1);
            color: #0f766e;
            border: 1px solid rgba(15, 118, 110, 0.18);
        }

        .permission-badge-alt {
            background: rgba(37, 99, 235, 0.08);
            color: #1d4ed8;
            border: 1px solid rgba(37, 99, 235, 0.16);
        }

        .permission-code {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 5px 8px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.82);
            color: #334155;
            font-size: 11px;
            font-weight: 800;
        }

        .permission-grid {
            margin-top: 18px;
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }

        .permission-meta {
            border-radius: 18px;
            padding: 14px 16px;
            background: rgba(248, 250, 252, 0.94);
            border: 1px solid rgba(226, 232, 240, 0.95);
        }

        .permission-meta-label {
            display: block;
            color: #64748b;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.14em;
            text-transform: uppercase;
        }

        .permission-meta-value {
            margin-top: 6px;
            color: var(--page-navy);
            font-size: 0.96rem;
            font-weight: 700;
            line-height: 1.5;
        }

        .accessible-panel {
            margin-top: 22px;
            border-radius: 24px;
            padding: 22px;
            background: rgba(255, 255, 255, 0.9);
            border: 1px solid rgba(226, 232, 240, 0.95);
            box-shadow: 0 16px 30px rgba(15, 23, 42, 0.06);
        }

        .accessible-groups {
            margin-top: 18px;
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }

        .accessible-group {
            border-radius: 20px;
            padding: 16px;
            background: linear-gradient(180deg, rgba(248, 250, 252, 0.95) 0%, rgba(255, 255, 255, 0.98) 100%);
            border: 1px solid rgba(226, 232, 240, 0.95);
        }

        .accessible-group-title {
            margin: 0 0 10px;
            color: var(--page-navy);
            font-size: 0.98rem;
            font-weight: 800;
        }

        .accessible-links {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .accessible-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 11px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.96);
            border: 1px solid rgba(191, 219, 254, 0.95);
            color: #1d4ed8;
            font-size: 12px;
            font-weight: 700;
            text-decoration: none;
        }

        .accessible-link:hover {
            color: #1e40af;
            border-color: rgba(96, 165, 250, 0.9);
        }

        .mail-cta {
            margin-top: 20px;
            width: 100%;
            background: linear-gradient(135deg, #f97316, #dc2626);
            color: #fff;
            box-shadow: 0 18px 30px rgba(220, 38, 38, 0.22);
        }

        .mail-helper {
            margin-top: 12px;
            color: rgba(226, 232, 240, 0.84);
            font-size: 0.9rem;
            line-height: 1.65;
        }

        .action-row {
            margin-top: 30px;
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }

        .action-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            min-height: 52px;
            padding: 0 22px;
            border-radius: 16px;
            text-decoration: none;
            font-weight: 700;
            transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
        }

        .action-button:hover {
            transform: translateY(-1px);
        }

        .action-button-primary {
            color: #fff;
            background: linear-gradient(135deg, #0f766e, #2563eb);
            box-shadow: 0 16px 30px rgba(37, 99, 235, 0.22);
        }

        .action-button-secondary {
            color: var(--page-ink);
            background: rgba(255, 255, 255, 0.88);
            border: 1px solid rgba(148, 163, 184, 0.28);
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.08);
        }

        .side-card {
            position: relative;
            z-index: 1;
        }

        .side-kicker {
            color: rgba(191, 219, 254, 0.82);
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 0.18em;
            text-transform: uppercase;
        }

        .side-title {
            margin-top: 14px;
            margin-bottom: 10px;
            font-size: 2rem;
            line-height: 1.08;
            font-weight: 800;
            letter-spacing: -0.04em;
            color: #f8fafc;
        }

        .side-text {
            margin: 0;
            color: rgba(226, 232, 240, 0.84);
            font-size: 0.98rem;
            line-height: 1.75;
        }

        .support-panel,
        .timeline-panel {
            margin-top: 24px;
            border-radius: 24px;
            padding: 22px;
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.08);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.04);
        }

        .panel-title {
            margin-bottom: 14px;
            color: #fff;
            font-size: 0.95rem;
            font-weight: 800;
            letter-spacing: 0.04em;
        }

        .support-list,
        .timeline-list {
            display: grid;
            gap: 14px;
        }

        .support-item,
        .timeline-item {
            display: grid;
            grid-template-columns: 42px minmax(0, 1fr);
            gap: 12px;
            align-items: start;
        }

        .support-icon,
        .timeline-index {
            width: 42px;
            height: 42px;
            border-radius: 14px;
            display: grid;
            place-items: center;
            font-size: 1rem;
            color: #fff;
            background: rgba(255, 255, 255, 0.12);
            box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.08);
        }

        .support-label,
        .timeline-label {
            display: block;
            color: rgba(191, 219, 254, 0.76);
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.16em;
            text-transform: uppercase;
        }

        .support-value,
        .timeline-value {
            margin-top: 4px;
            color: #f8fafc;
            font-size: 0.98rem;
            font-weight: 700;
            line-height: 1.5;
        }

        .support-note {
            margin-top: 16px;
            padding: 14px 16px;
            border-radius: 16px;
            background: rgba(14, 165, 233, 0.1);
            color: rgba(224, 242, 254, 0.92);
            font-size: 0.93rem;
            line-height: 1.65;
        }

        .support-whatsapp {
            margin-top: 18px;
            width: 100%;
            justify-content: center;
            color: #fff;
            background: linear-gradient(135deg, #16a34a, #0f766e);
            box-shadow: 0 16px 28px rgba(22, 163, 74, 0.22);
        }

        @media (max-width: 991.98px) {
            .access-layout {
                grid-template-columns: 1fr;
            }

            .access-main,
            .access-side {
                padding: 28px 22px;
            }
        }

        @media (max-width: 767.98px) {
            .access-page {
                padding: 16px;
            }

            .status-hero {
                flex-direction: column;
            }

            .hero-head {
                flex-direction: column;
                align-items: flex-start;
            }

            .finea-mark-wrap {
                justify-content: flex-start;
            }

            .meta-grid {
                grid-template-columns: 1fr;
            }

            .permission-grid,
            .accessible-groups {
                grid-template-columns: 1fr;
            }

            .action-row {
                flex-direction: column;
            }

            .action-button {
                width: 100%;
            }

            .side-title {
                font-size: 1.7rem;
            }
        }
    </style>
</head>

<body>
    <div class="access-page">
        <div class="access-shell">
            <div class="access-denied-card access-layout">
                <section class="access-main">
                    <div class="hero-head">
                        <span class="eyebrow">
                            <i class="bi bi-lock-fill"></i>
                            Zone sécurisée
                        </span>
                    </div>

                    <div class="action-row action-row-top">
                        <a href="<?= htmlspecialchars($appBasePath . '/selection_portail.php') ?>" class="action-button action-button-primary">
                            <i class="bi bi-house-door-fill"></i>
                            Retour au portail
                        </a>
                        <button type="button" onclick="history.back()" class="action-button action-button-secondary">
                            <i class="bi bi-arrow-left"></i>
                            Page précédente
                        </button>
                    </div>

                    <div class="status-hero">
                        <div class="icon-container">
                            <?= $currentMessage['icon'] ?>
                        </div>
                        <div>
                            <span class="error-kicker">
                                <i class="bi bi-shield-exclamation"></i>
                                Accès bloqué
                            </span>
                            <h1 class="error-title">Vous n'avez pas accès à cette zone.</h1>
                            <p class="error-message">
                                <?= htmlspecialchars($currentMessage['message']) ?>
                            </p>
                            <div class="module-chip">
                                <i class="bi bi-grid-1x2-fill"></i>
                                <?= htmlspecialchars($currentMessage['title']) ?>
                            </div>
                        </div>
                    </div>

                    <div class="meta-grid">
                        <article class="meta-card">
                            <span class="meta-label">Utilisateur connecté</span>
                            <div class="meta-value"><?= htmlspecialchars($userDisplayName) ?></div>
                        </article>
                        <article class="meta-card">
                            <span class="meta-label">Page demandée</span>
                            <div class="meta-value"><?= htmlspecialchars($requestedPage) ?></div>
                        </article>
                    </div>

                    <section class="access-requirement-card">
                        <div class="section-kicker">
                            <i class="bi bi-key-fill"></i>
                            Accès à attribuer
                        </div>
                        <h2 class="section-title">Droit recommandé pour ouvrir cette page</h2>
                        <p class="section-text">
                            Pour accéder à <strong><?= htmlspecialchars($requestedPageKey !== '' ? $requestedPageKey : $requestedPage) ?></strong>, il faut idéalement attribuer à cet utilisateur le droit ci-dessous. Si plusieurs droits sont compatibles, ils sont indiqués comme alternatives.
                        </p>
                        <div class="permission-badges">
                            <span class="permission-badge permission-badge-primary">
                                <i class="bi bi-shield-lock-fill"></i>
                                <?= htmlspecialchars($requiredPermissionLabel) ?>
                                <?php if ($primaryRequiredPermission !== ''): ?>
                                    <span class="permission-code"><?= htmlspecialchars($primaryRequiredPermission) ?></span>
                                <?php endif; ?>
                            </span>
                            <?php foreach ($alternativePermissions as $permission): ?>
                                <span class="permission-badge permission-badge-alt">
                                    <i class="bi bi-diagram-3-fill"></i>
                                    <?= htmlspecialchars(accessDeniedPermissionLabel($permission)) ?>
                                    <span class="permission-code"><?= htmlspecialchars($permission) ?></span>
                                </span>
                            <?php endforeach; ?>
                        </div>
                        <div class="permission-grid">
                            <article class="permission-meta">
                                <span class="permission-meta-label">Page ciblée</span>
                                <div class="permission-meta-value"><?= htmlspecialchars($requestedPageKey !== '' ? $requestedPageKey : $requestedPage) ?></div>
                            </article>
                            <article class="permission-meta">
                                <span class="permission-meta-label">Famille d'accès</span>
                                <div class="permission-meta-value"><?= htmlspecialchars(ucfirst((string)$requiredAccessTypeLabel)) ?></div>
                            </article>
                        </div>
                    </section>

                    <section class="accessible-panel">
                        <div class="section-kicker">
                            <i class="bi bi-compass-fill"></i>
                            Alternatives disponibles
                        </div>
                        <h2 class="section-title">Pages déjà accessibles pour ce profil</h2>
                        <p class="section-text">
                            Cet utilisateur a actuellement accès à <?= htmlspecialchars((string)$accessiblePageCount) ?> page(s) métier visibles dans la cartographie principale. Cela permet d'orienter l'usage en attendant l'ouverture du bon droit.
                        </p>
                        <div class="accessible-groups">
                            <?php if ($accessiblePageGroups): ?>
                                <?php foreach ($accessiblePageGroups as $moduleName => $pages): ?>
                                    <article class="accessible-group">
                                        <h3 class="accessible-group-title"><?= htmlspecialchars($moduleName) ?></h3>
                                        <div class="accessible-links">
                                            <?php foreach ($pages as $pageItem): ?>
                                                <a href="<?= htmlspecialchars($pageItem['url']) ?>" class="accessible-link">
                                                    <i class="bi bi-arrow-up-right-circle"></i>
                                                    <?= htmlspecialchars($pageItem['label']) ?>
                                                </a>
                                            <?php endforeach; ?>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <article class="accessible-group">
                                    <h3 class="accessible-group-title">Aucun autre accès détecté</h3>
                                    <div class="section-text">Le profil courant ne possède pas d'autre page métier visible dans la cartographie principale.</div>
                                </article>
                            <?php endif; ?>
                        </div>
                    </section>

                </section>

                <aside class="access-side">
                    <div class="side-card">
                        <div class="side-kicker">Support et résolution</div>
                        <h2 class="side-title">Demander l’autorisation appropriée.</h2>
                        <p class="side-text">
                            Si cet accès vous est nécessaire pour votre activité, transmettez la page concernée et le besoin métier exact au support afin qu’il puisse vérifier votre profil et valider l’ouverture des droits.
                        </p>

                        <div class="timeline-panel">
                            <div class="panel-title">Étapes recommandées</div>
                            <div class="timeline-list">
                                <div class="timeline-item">
                                    <div class="timeline-index">1</div>
                                    <div>
                                        <span class="timeline-label">Identifier</span>
                                        <div class="timeline-value">Notez la page demandée et le module concerné.</div>
                                    </div>
                                </div>
                                <div class="timeline-item">
                                    <div class="timeline-index">2</div>
                                    <div>
                                        <span class="timeline-label">Justifier</span>
                                        <div class="timeline-value">Précisez le besoin métier et le contexte opérationnel.</div>
                                    </div>
                                </div>
                                <div class="timeline-item">
                                    <div class="timeline-index">3</div>
                                    <div>
                                        <span class="timeline-label">Contacter</span>
                                        <div class="timeline-value">Envoyez votre demande au support via email ou WhatsApp.</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="support-panel">
                            <div class="panel-title">Contacts support</div>
                            <div class="support-list">
                                <div class="support-item">
                                    <div class="support-icon"><i class="bi bi-envelope-at"></i></div>
                                    <div>
                                        <span class="support-label">Email</span>
                                        <div class="support-value">ulrich@banamur.com</div>
                                    </div>
                                </div>
                                <div class="support-item">
                                    <div class="support-icon"><i class="bi bi-telephone"></i></div>
                                    <div>
                                        <span class="support-label">Téléphone</span>
                                        <div class="support-value">(+225) 07 48 36 77 10 / 05 05 05 52 62</div>
                                    </div>
                                </div>
                            </div>

                            <div class="support-note">
                                Mentionnez la page <strong><?= htmlspecialchars($requestedPage) ?></strong> ainsi que la nature de l’action souhaitée pour accélérer le traitement.
                            </div>

                            <button type="button" id="sendAccessRequestBtn" class="action-button mail-cta">
                                <i class="bi bi-send-fill"></i>
                                Envoyer la demande au support
                            </button>

                            <button type="button" id="copyAccessRequestBtn" class="action-button copy-cta">
                                <i class="bi bi-copy"></i>
                                Copier la demande d'accès
                            </button>

                            <div id="copyAccessFeedback" class="copy-feedback" aria-live="polite"></div>
                            <div id="sendAccessFeedback" class="send-feedback" aria-live="polite"></div>

                            <div class="mail-helper">
                                Un email opérationnel sera envoyé au support avec les boutons de décision. En parallèle, vous pouvez toujours copier la demande pour WhatsApp ou Teams.
                            </div>

                            <a href="https://wa.me/2250748367710" target="_blank" rel="noopener noreferrer" class="action-button support-whatsapp">
                                <i class="bi bi-whatsapp"></i>
                                Contacter le support sur WhatsApp
                            </a>
                        </div>
                    </div>
                </aside>
            </div>
        </div>
    </div>

    <script>
        (() => {
            const copyButton = document.getElementById('copyAccessRequestBtn');
            const feedback = document.getElementById('copyAccessFeedback');
            const sendButton = document.getElementById('sendAccessRequestBtn');
            const sendFeedback = document.getElementById('sendAccessFeedback');
            const copyText = <?= json_encode($copyRequestText, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            const requestedPage = <?= json_encode($requestedPageKey !== '' ? $requestedPageKey : $requestedPage, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            const requestedPageUrl = <?= json_encode($requestedPageUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            const originUrl = <?= json_encode($originUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

            if (!copyButton || !feedback || !sendButton || !sendFeedback) {
                return;
            }

            async function copyRequest() {
                try {
                    if (navigator.clipboard && window.isSecureContext) {
                        await navigator.clipboard.writeText(copyText);
                    } else {
                        const textarea = document.createElement('textarea');
                        textarea.value = copyText;
                        textarea.setAttribute('readonly', 'readonly');
                        textarea.style.position = 'fixed';
                        textarea.style.left = '-9999px';
                        document.body.appendChild(textarea);
                        textarea.select();
                        document.execCommand('copy');
                        document.body.removeChild(textarea);
                    }
                    feedback.textContent = 'Demande copiée. Vous pouvez maintenant la coller dans WhatsApp ou Teams.';
                } catch (error) {
                    feedback.textContent = 'Impossible de copier automatiquement. Utilisez le bouton email prérempli.';
                }
            }

            copyButton.addEventListener('click', copyRequest);

            async function submitAccessRequest() {
                const previousHtml = sendButton.innerHTML;
                sendButton.disabled = true;
                sendButton.innerHTML = '<i class="bi bi-arrow-repeat"></i>Envoi en cours...';
                sendFeedback.textContent = 'Transmission de la demande au support informatique...';

                try {
                    const body = new URLSearchParams();
                    body.append('requested_page', requestedPage);
                    body.append('requested_page_url', requestedPageUrl);
                    body.append('origin_url', originUrl);

                    const response = await fetch(<?= json_encode($appBasePath . '/api/access_request_submit.php', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: body.toString()
                    });
                    const json = await response.json();
                    if (!response.ok || !json.ok) {
                        throw new Error(json.error || 'Impossible d\'envoyer la demande');
                    }

                    sendFeedback.textContent = 'Demande envoyée. Redirection vers la page d\'attente...';
                    window.setTimeout(() => {
                        window.location.href = json.wait_url || <?= json_encode($appBasePath . '/access_request_wait.php', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
                    }, 700);
                } catch (error) {
                    sendFeedback.textContent = error.message || 'Impossible d\'envoyer la demande pour le moment.';
                    sendButton.disabled = false;
                    sendButton.innerHTML = previousHtml;
                }
            }

            sendButton.addEventListener('click', submitAccessRequest);
        })();
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>
