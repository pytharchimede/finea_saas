<?php

require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../../model/NotificationSoundSettingsManager.php';

// Navbar: activer un onglet via $activeMenu ('conformite','approbation','achat','compta','otp','decaissement','historique')
$activeMenu = $activeMenu ?? '';

// Activation automatique du menu lorsque l'utilisateur navigue dans un module précis.
$currentScriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
if ($activeMenu === '' && strpos($currentScriptName, '/recurring_needs/') !== false) {
    $activeMenu = 'besoins_recurrents';
}
if ($activeMenu === '' && strpos($currentScriptName, '/planning_chantier/') !== false) {
    $activeMenu = 'planning_chantier';
}
if ($activeMenu === '' && strpos($currentScriptName, '/ticketing/') !== false) {
    $activeMenu = 'ticketing';
}

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
require_once dirname(__DIR__, 2) . '/model/CompanyBranding.php';
$globalBranding = CompanyBranding::get();
$brandCompanyName = trim((string)($globalBranding['company_name'] ?? '')) ?: 'BANAMUR INDUSTRIES ET TECHNOLOGIES';
$brandLogoUrl = $layoutRootBase . 'branding_asset.php?type=logo';
$brandIconUrl = $layoutRootBase . 'branding_asset.php?type=icon';

if (!function_exists('resolveUserPhotoUrl')) {
    function resolveUserPhotoUrl(string $raw, string $layoutRootBase): string
    {
        $raw = trim($raw);

        if ($raw === '') {
            $mail = trim((string) ($_SESSION['mail_pro'] ?? ''));
            if ($mail !== '') {
                return 'https://www.gravatar.com/avatar/' . md5(strtolower($mail)) . '?d=mp&s=200';
            }
            return $layoutRootBase . 'photo/ma_photo.jpg';
        }

        if (preg_match('~^(https?:|data:)~i', $raw)) {
            return $raw;
        }

        if (strpos($raw, '/') === 0) {
            return $raw;
        }

        return $layoutRootBase . ltrim($raw, '/');
    }
}

$photoSrc = resolveUserPhotoUrl((string) ($_SESSION['photo'] ?? ''), (string) $layoutRootBase);

$fallbackName = trim((string) (($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? '')));
$displayName = (string) ($_SESSION['user_name'] ?? ($fallbackName !== '' ? $fallbackName : 'Utilisateur'));
$userRoleLabel = (string) ($_SESSION['role_raw'] ?? 'Utilisateur');
$userMail = trim((string) ($_SESSION['mail_pro'] ?? ''));

$notifPollUrl = $layoutRootBase . 'api/notifications_poll.php';
$notifMarkReadUrl = $layoutRootBase . 'api/notifications_mark_read.php';
$notificationSoundUrl = '';
try {
    $notificationSoundUrl = (new \NotificationSoundSettingsManager(\Database::getConnection()))->getPublicUrl($layoutRootBase);
} catch (\Throwable $e) {
    $notificationSoundUrl = '';
}

$mailboxPageUrl = $layoutRootBase . 'emails/mailbox_emails.php';
$mailboxUnreadUrl = $layoutRootBase . 'emails/api_mailbox_unread_count.php';

// Font Awesome (optionnel) — chargé une seule fois par requête.
$fontAwesomeHref = '';
if (empty($GLOBALS['__banamur_fa_loaded'])) {
    $GLOBALS['__banamur_fa_loaded'] = true;
    $fontAwesomeHref = 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css';
}

$devisModulePermissions = [
    'acces_dashboard',
    'acces_devis',
    'acces_generer_devis',
    'acces_liste_client',
    'acces_liste_offre',
    'acces_liste_unite',
    'modifier_debourse',
    'acces_decaissement',
    'acces_liste_chantier',
];

$navLinks = [
    [
        'label' => 'Dashboard',
        'href' => $layoutModuleBase . 'dashboard.php',
        'perm' => 'acces_dashboard',
        'active' => 'dashboard',
        'icon' => 'fa-solid fa-gauge-high',
    ],
    [
        'label' => 'Pilotage DG',
        'sublabel' => 'Synthèse décisionnelle',
        'href' => $layoutRootBase . 'dashboard_dg.php',
        'perm' => ['acces_direction','acces_admin'],
        'active' => 'dashboard_dg',
        'icon' => 'fa-solid fa-chart-pie',
    ],
    [
        'label' => 'Centre devis',
        'href' => $layoutRootBase . 'devis/index.php',
        'perm' => $devisModulePermissions,
        'active' => 'module_devis',
        'icon' => 'fa-solid fa-drafting-compass',
    ],
    [
        'label' => 'Nouveau devis',
        'href' => $layoutRootBase . 'devis/generer.php',
        'perm' => 'acces_generer_devis',
        'active' => 'module_devis_create',
        'icon' => 'fa-solid fa-file-signature',
    ],
    [
        'label' => 'Liste devis',
        'href' => $layoutRootBase . 'devis/liste.php',
        'perm' => 'acces_devis',
        'active' => 'module_devis_list',
        'icon' => 'fa-solid fa-file-contract',
    ],
    [
        'label' => 'Clients',
        'href' => $layoutRootBase . 'clients/dashboard.php',
        'perm' => 'acces_liste_client',
        'active' => 'module_clients',
        'icon' => 'fa-solid fa-users',
    ],
    [
        'label' => 'Projets',
        'href' => $layoutRootBase . 'offres/dashboard.php',
        'perm' => 'acces_liste_offre',
        'active' => 'module_offres',
        'icon' => 'fa-solid fa-tags',
    ],
    [
        'label' => 'Unites',
        'href' => $layoutRootBase . 'unites/dashboard.php',
        'perm' => 'acces_liste_unite',
        'active' => 'module_unites',
        'icon' => 'fa-solid fa-ruler-combined',
    ],
    [
        'label' => 'Debourses',
        'href' => $layoutRootBase . 'debourse/dashboard.php',
        'perm' => ['modifier_debourse', 'acces_decaissement'],
        'active' => 'module_debourse',
        'icon' => 'fa-solid fa-wallet',
    ],
    [
        'label' => 'Chantiers',
        'href' => $layoutRootBase . 'chantiers/dashboard.php',
        'perm' => 'acces_liste_chantier',
        'active' => 'module_chantiers',
        'icon' => 'fa-solid fa-helmet-safety',
    ],
    [
        'label' => 'Contrats',
        'sublabel' => 'Sous-traitance BTP',
        'href' => $layoutRootBase . 'contrats/index.php',
        'perm' => ['acces_contrats', 'acces_admin'],
        'active' => 'contrats',
        'icon' => 'fa-solid fa-file-signature',
    ],
    [
        'label' => 'Mes signatures',
        'sublabel' => 'Contrats et PV désignés',
        'href' => $layoutRootBase . 'contrats/mes-signatures.php',
        'perm' => null,
        'active' => 'mes_signatures',
        'icon' => 'fa-solid fa-signature',
    ],
    [
        'label' => 'Régularisation',
        'sublabel' => 'fiabilité chantiers',
        'href' => $layoutRootBase . 'chantiers/regularisation.php',
        'perm' => ['acces_liste_chantier', 'acces_direction', 'acces_admin'],
        'active' => 'chantier_regularisation',
        'icon' => 'fa-solid fa-screwdriver-wrench',
    ],
    [
        'label' => 'Planning Chantier',
        'sublabel' => 'réunion 12h',
        'href' => $layoutRootBase . 'planning_chantier/dashboard.php',
        'perm' => ['acces_planning_chantier', 'acces_planning', 'acces_liste_chantier', 'acces_direction', 'acces_admin'],
        'active' => 'planning_chantier',
        'icon' => 'fa-solid fa-calendar-check',
    ],
    [
        'label' => 'Zones',
        'href' => $layoutModuleBase . 'gestion_zones.php',
        'perm' => ['acces_devis', 'acces_decaissement'],
        'active' => 'Zones',
        'icon' => 'fa-solid fa-map-location-dot',
    ],
    [
        'label' => 'Chantiers',
        'href' => $layoutModuleBase . 'details_chantiers.php',
        'perm' => 'acces_dec_chantiers',
        'active' => 'chantiers',
        'icon' => 'fa-solid fa-helmet-safety',
    ],
    [
        'label' => 'Budgets',
        'href' => $layoutModuleBase . 'selection_budgets_chantiers.php',
        'perm' => 'acces_budgets_chantiers',
        'active' => 'budgets',
        'icon' => 'fa-solid fa-coins',
    ],
    [
        'label' => 'FEB',
        'sublabel' => 'bons internes',
        'href' => $layoutRootBase . 'validation_demandes_finances.php',
        'perm' => 'acces_feb',
        'active' => 'feb_finance',
        'icon' => 'fa-solid fa-clipboard-check',
    ],
    [
        'label' => 'Contestations de prix',
        'sublabel' => 'Mes demandes à traiter',
        'href' => $layoutRootBase . 'employe/contestations_prix_feb.php',
        'perm' => null,
        'active' => 'contestations',
        'icon' => 'fa-solid fa-scale-balanced',
    ],
    [
        'label' => 'Conformite',
        'sublabel' => 'fiche -> debourse',
        'href' => $layoutModuleBase . 'liste_fiches_conformite.php',
        'perm' => 'acces_conformite',
        'active' => 'conformite',
        'icon' => 'fa-solid fa-shield-halved',
    ],
    [
        'label' => 'Approbation',
        'sublabel' => 'Directeur',
        'href' => $layoutModuleBase . 'liste_fiches_approbation.php',
        'perm' => 'acces_approbation',
        'active' => 'approbation',
        'icon' => 'fa-solid fa-user-check',
    ],
    [
        'label' => 'Validation Achat',
        'sublabel' => 'Comptabilité',
        'href' => $layoutModuleBase . 'liste_fiches_achat.php',
        'perm' => 'acces_achat',
        'active' => 'achat',
        'icon' => 'fa-solid fa-cart-shopping',
    ],
    [
        'label' => 'Urgences',
        'sublabel' => 'decaissement',
        'href' => $layoutModuleBase . 'liste_decaissements_urgents.php',
        'perm' => 'acces_achat',
        'active' => 'urgences',
        'icon' => 'fa-solid fa-bolt',
    ],
    [
        'label' => 'Régularisations urgentes',
        'sublabel' => 'Suivi et validation des FEB',
        'href' => $layoutModuleBase . 'validations_regularisations_urgentes.php',
        'perm' => 'acces_achat',
        'active' => 'regularisations_urgentes',
        'icon' => 'fa-solid fa-file-circle-check',
    ],
    [
        'label' => 'Dépenses Planifiées',
        'sublabel' => 'Prévisions',
        'href' => $layoutModuleBase . 'depenses_planifiees.php',
        'perm' => 'acces_depenses_planifiees',
        'active' => 'depenses_planifiees',
        'icon' => 'fa-solid fa-calendar-days',
    ],
    [
        'label' => 'Traitement des paiements',
        'sublabel' => 'Étape du décaissement',
        'href' => $layoutModuleBase . 'liste_fiches_compta.php',
        'perm' => 'acces_tresorerie',
        'active' => 'compta',
        'icon' => 'fa-solid fa-cash-register',
    ],
    [
        'label' => 'Régularisations post-décaissement',
        'sublabel' => 'Justificatifs, PV et preuves après paiement',
        'href' => $layoutModuleBase . 'regularisations_decaissements.php',
        'perm' => ['acces_employe', 'acces_compta', 'acces_tresorerie', 'acces_direction', 'acces_admin'],
        'active' => 'regularisation_decaissements',
        'icon' => 'fa-solid fa-file-circle-check',
    ],
    [
        'label' => 'Trésorerie',
        'sublabel' => 'Entrées, sorties et solde disponible',
        'href' => $layoutRootBase . 'facturation/tresorerie_chantiers.php',
        'perm' => ['acces_facturation', 'acces_direction', 'acces_admin'],
        'active' => 'tresorerie_pilotage',
        'icon' => 'fa-solid fa-building-columns',
    ],
    [
        'label' => 'Point financier',
        'href' => $layoutModuleBase . 'point_financier.php',
        'perm' => 'acces_point_financier',
        'active' => 'pointfinancier',
        'icon' => 'fa-solid fa-chart-line',
    ],
    [
        'label' => 'Budget prévisionnel Bureau',
        'sublabel' => 'Prévisions par service',
        'href' => $layoutModuleBase . 'budget_previsionnel_bureau.php',
        'perm' => ['acces_point_financier', 'acces_finance', 'acces_direction', 'acces_admin'],
        'active' => 'office_budget_forecast',
        'icon' => 'fa-solid fa-chart-column',
    ],
    [
        'label' => 'Formation',
        'href' => $layoutRootBase . 'formation/index.php',
        'perm' => ['acces_formation', 'formation_admin', 'acces_admin'],
        'active' => 'formation',
        'icon' => 'fa-solid fa-graduation-cap',
    ],
    [
        'label' => 'Emploi du temps',
        'href' => $layoutRootBase . 'emploi_du_temps/index.php',
        'perm' => 'acces_emploi_du_temps',
        'active' => 'emploi_du_temps',
        'icon' => 'fa-solid fa-calendar-days',
    ],
    [
        'label' => 'Documentation',
        'href' => $layoutRootBase . 'documentation/index.php',
        'perm' => 'acces_documentation',
        'active' => 'documentation',
        'icon' => 'fa-solid fa-folder-open',
    ],
    [
        'label' => 'Liens cassés',
        'sublabel' => 'Reliaison fiches',
        'href' => $layoutRootBase . 'admin/relink_fiches_debourse.php',
        'perm' => 'acces_relink_fiches_debourse',
        'active' => 'relink_debourse',
        'icon' => 'fa-solid fa-link-slash',
    ],
    [
        'label' => 'Soumissionnaires FEB',
        'sublabel' => 'Devis contradictoires',
        'href' => $layoutRootBase . 'admin/feb_price_challengers.php',
        'perm' => ['acces_admin', 'gestion_utilisateur'],
        'active' => 'feb_price_challengers',
        'icon' => 'fa-solid fa-scale-balanced',
    ],
    [
        'label' => 'Administration système',
        'sublabel' => 'Paramètres et utilisateurs',
        'href' => $layoutRootBase . 'admin/dashboard_admin.php',
        'perm' => 'acces_admin',
        'active' => 'admin',
        'icon' => 'fa-solid fa-gears',
    ],
    [
        'label' => 'Contrôle devis FEB',
        'sublabel' => 'Suivi contradictoire',
        'href' => $layoutRootBase . 'admin/feb_price_challenge_control.php',
        'perm' => ['suivi_devis_contradictoires', 'acces_admin'],
        'active' => 'feb_price_challenge_control',
        'icon' => 'fa-solid fa-tower-observation',
    ],
    [
        'label' => 'Historique',
        'href' => $layoutModuleBase . 'historique_fiches.php',
        'perm' => 'acces_historique_fiches',
        'active' => 'historique',
        'icon' => 'fa-solid fa-clock-rotate-left',
    ],
    [
        'label' => 'RH',
        'href' => $layoutRootBase . 'rh/dashboard_rh.php',
        'perm' => 'acces_rh',
        'active' => 'rh',
        'icon' => 'fa-solid fa-users',
    ],
    [
        'label' => 'Ordres de mission',
        'href' => $layoutRootBase . 'rh/ordres_mission_rh.php',
        'perm' => 'acces_rh',
        'active' => 'rh_missions',
        'icon' => 'fa-solid fa-plane-departure',
    ],
    [
        'label' => 'Mes signatures OM',
        'sublabel' => 'Ordres transmis en PO',
        'href' => $layoutRootBase . 'rh/mes_signatures_mission.php',
        'perm' => ['acces_employe', 'acces_rh'],
        'active' => 'rh_mission_signatures',
        'icon' => 'fa-solid fa-signature',
    ],
    [
        'label' => 'Logistique',
        'href' => $layoutRootBase . 'logi/dashboard_logistique.php',
        'perm' => 'acces_logistique',
        'active' => 'logistique',
        'icon' => 'fa-solid fa-truck-ramp-box',
    ],
    [
        'label' => 'Parc informatique',
        'sublabel' => 'matériels & incidents',
        'href' => $layoutRootBase . 'parc_informatique/index.php',
        'perm' => ['acces_parc_informatique', 'acces_admin'],
        'active' => 'parc_informatique',
        'icon' => 'fa-solid fa-laptop-code',
    ],
    [
        'label' => 'Tickets',
        'sublabel' => 'centre de services',
        'href' => $layoutRootBase . 'ticketing/index.php',
        'perm' => ['acces_ticketing', 'acces_admin'],
        'active' => 'ticketing',
        'icon' => 'fa-solid fa-headset',
    ],
    [
        'label' => 'Fournisseurs',
        'href' => $layoutRootBase . 'fournisseur/dashboard_fournisseur.php',
        'perm' => 'acces_fournisseur',
        'active' => 'fournisseur',
        'icon' => 'fa-solid fa-handshake',
    ],
    [
        'label' => 'Facturation',
        'href' => $layoutRootBase . 'facturation/dashboard_facturation.php',
        'perm' => 'acces_facturation',
        'active' => 'facturation',
        'icon' => 'fa-solid fa-file-invoice',
    ],
    [
        'label' => 'CRM',
        'href' => $layoutRootBase . 'crm/dashboard_crm.php',
        'perm' => ['acces_crm', 'acces_crm_dashboard', 'acces_crm_prospects', 'acces_crm_clients', 'acces_crm_opportunites', 'acces_crm_rdv', 'acces_crm_admin'],
        'active' => 'crm',
        'icon' => 'fa-solid fa-chart-line',
    ],
    [
        'label' => 'Besoins récurrents',
        'sublabel' => 'FEB automatiques',
        'href' => $layoutRootBase . 'recurring_needs/index.php',
        'perm' => null,
        'active' => 'besoins_recurrents',
        'icon' => 'fa-solid fa-arrows-rotate',
    ],
    [
        'label' => 'Espace Employé',
        'href' => $layoutRootBase . 'employe/dashboard_employe.php',
        'perm' => null,
        'active' => 'espace_employe',
        'icon' => 'fa-solid fa-id-badge',
    ],
    [
        'label' => 'Portail',
        'href' => $layoutRootBase . 'selection_portail.php',
        'perm' => null,
        'active' => 'Portail',
        'icon' => 'fa-solid fa-grip',
    ],
];

$navCategoryMap = [
    'dashboard'=>'Général','espace_employe'=>'Général','mes_signatures'=>'Général',
    'rh'=>'RH','rh_missions'=>'RH',
    'logistique'=>'Logistique',
    'module_chantiers'=>'Technique & Chantiers','chantier_regularisation'=>'Technique & Chantiers','planning_chantier'=>'Technique & Chantiers','Zones'=>'Technique & Chantiers','chantiers'=>'Technique & Chantiers',
    'module_devis'=>'Commercial','module_devis_create'=>'Commercial','module_devis_list'=>'Commercial','module_clients'=>'Commercial','module_offres'=>'Commercial','module_unites'=>'Commercial','crm'=>'Commercial',
    'module_debourse'=>'Finance & décisions','budgets'=>'Finance & décisions','feb_finance'=>'Finance & décisions','contestations'=>'Finance & décisions','conformite'=>'Finance & décisions','approbation'=>'Finance & décisions','achat'=>'Finance & décisions','urgences'=>'Finance & décisions','regularisations_urgentes'=>'Finance & décisions','pointfinancier'=>'Finance & décisions','depenses_planifiees'=>'Finance & décisions','compta'=>'Finance & décisions','regularisation_decaissements'=>'Finance & décisions','facturation'=>'Finance & décisions','fournisseur'=>'Finance & décisions','historique'=>'Finance & décisions','relink_debourse'=>'Finance & décisions','besoins_recurrents'=>'Finance & décisions',
    'dashboard_dg'=>'Direction Générale','feb_price_challenge_control'=>'Direction Générale','tresorerie_pilotage'=>'Direction Générale',
    'ticketing'=>'Organisation & collaboration','formation'=>'Organisation & collaboration','documentation'=>'Organisation & collaboration','emploi_du_temps'=>'Organisation & collaboration',
    'feb_price_challengers'=>'Administration','admin'=>'Administration','parc_informatique'=>'Administration',
    'Portail'=>'Portail',
];
$navCategoryIcons=['Général'=>'fa-house','RH'=>'fa-people-group','Logistique'=>'fa-truck','Technique & Chantiers'=>'fa-helmet-safety','Commercial'=>'fa-handshake','Finance & décisions'=>'fa-coins','Direction Générale'=>'fa-chart-pie','Organisation & collaboration'=>'fa-people-arrows','Administration'=>'fa-sliders','Portail'=>'fa-grip'];
$navBadges=[];
try {
    $navPdo=\Database::getConnection();$navUserId=(int)($_SESSION['user_id']??0);
    $navCount=static function(string $sql,array $params=[])use($navPdo):int{try{$countStmt=$navPdo->prepare($sql);$countStmt->execute($params);return(int)$countStmt->fetchColumn();}catch(\Throwable $e){return 0;}};
    $navBadges['contestations']=$navCount('SELECT COUNT(*) FROM feb_price_challenges c JOIN employee_legal_requests r ON r.id=c.feb_request_id WHERE c.challenger_user_id=:user AND c.archived_at IS NULL AND c.status="pending" AND r.status="submitted" AND c.challenger_user_id<>COALESCE(r.requester_user_id,0)',['user'=>$navUserId]);
    $navBadges['mes_signatures']=$navCount('SELECT (SELECT COUNT(*) FROM contrat_sous_traitance c JOIN user_devis_banamur u ON u.personnel_id=c.signataire_banamur_personnel_id WHERE u.id=:uid1 AND c.signature_banamur IS NULL)+(SELECT COUNT(*) FROM contrat_reception_pv r JOIN user_devis_banamur u ON u.personnel_id=r.signataire_banamur_personnel_id WHERE u.id=:uid2 AND r.signature_banamur IS NULL)',['uid1'=>$navUserId,'uid2'=>$navUserId]);
    if(hasPermission('acces_feb'))$navBadges['feb_finance']=$navCount('SELECT COUNT(*) FROM employee_legal_requests r WHERE r.request_type="feb" AND r.status="submitted" AND NOT EXISTS(SELECT 1 FROM chantier_banamur c WHERE c.id=CAST(JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,"$.chantier_id")) AS UNSIGNED) AND c.archived_at IS NOT NULL)');
    if(hasPermission('acces_conformite'))$navBadges['conformite']=$navCount('SELECT COUNT(*) FROM fiche_decaissement f WHERE f.conforme=0 AND f.approuve=0 AND f.decaisse=0 AND NOT EXISTS(SELECT 1 FROM chantier_banamur c WHERE c.id=f.chantier_id AND c.archived_at IS NOT NULL)');
    if(hasPermission('acces_approbation'))$navBadges['approbation']=$navCount('SELECT COUNT(*) FROM fiche_decaissement f WHERE f.conforme=1 AND f.approuve=0 AND f.decaisse=0 AND NOT EXISTS(SELECT 1 FROM chantier_banamur c WHERE c.id=f.chantier_id AND c.archived_at IS NOT NULL)');
    if(hasPermission('acces_achat'))$navBadges['achat']=$navCount('SELECT COUNT(*) FROM fiche_decaissement f WHERE f.conforme=1 AND f.approuve=1 AND f.etat_fiche=0 AND f.decaisse=0 AND (f.date_decaissement_minimum IS NULL OR f.date_decaissement_minimum<=CURDATE()) AND NOT EXISTS(SELECT 1 FROM chantier_banamur c WHERE c.id=f.chantier_id AND c.archived_at IS NOT NULL)');
} catch(\Throwable $e) {
    // La navigation reste disponible même si un compteur métier est temporairement indisponible.
}
foreach($navLinks as &$navItem){$navItem['category']=$navCategoryMap[(string)($navItem['active']??'')]??'Autres';$navItem['badge']=(int)($navBadges[(string)($navItem['active']??'')]??0);}unset($navItem);
$visibleNavGroups=[];
foreach($navLinks as $navItem){if(navCanAccess($navItem['perm']??null)&&$navItem['category']!=='Portail')$visibleNavGroups[(string)$navItem['category']][]=$navItem;}

function navLinkClass(bool $isActive, bool $isMobile = false): string
{
    $base = $isMobile
        ? 'flex items-center gap-3 w-full px-4 py-3 rounded-2xl text-sm font-semibold transition'
        : 'group inline-flex items-center gap-2 px-3 py-2 rounded-2xl text-sm font-semibold transition';

    if ($isActive) {
        return $base . ' finea-brand-portal border shadow-sm';
    }
    return $base . ' bg-white/0 text-slate-700 hover:bg-slate-50';
}

function navCanAccess($permission): bool
{
    if ($permission === null || $permission === '') {
        return true;
    }

    if (is_array($permission)) {
        foreach ($permission as $item) {
            if (is_string($item) && $item !== '' && hasPermission($item)) {
                return true;
            }
        }
        return false;
    }

    return is_string($permission) && hasPermission($permission);
}
?>
<?php if ($fontAwesomeHref !== ''): ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($fontAwesomeHref, ENT_QUOTES, 'UTF-8') ?>" referrerpolicy="no-referrer">
<?php endif; ?>
<link rel="stylesheet" href="<?= htmlspecialchars($layoutRootBase, ENT_QUOTES, 'UTF-8') ?>assets/css/finea-theme.css?v=20260827">
<?php if(!empty($_SESSION['user_id'])): ?>
<link rel="stylesheet" href="<?= htmlspecialchars($layoutRootBase, ENT_QUOTES, 'UTF-8') ?>assets/css/finea-notification-speech.css?v=20261003">
<script>window.FINEA_SPEECH_USER_ID=<?= (int)$_SESSION['user_id'] ?>;</script>
<script src="<?= htmlspecialchars($layoutRootBase, ENT_QUOTES, 'UTF-8') ?>assets/js/finea-notification-speech.js?v=20261003"></script>
<?php endif; ?>
<script>
    (function applyFineaStoredThemeEarly() {
        try {
            var storedTheme = localStorage.getItem('finea.portal.theme') || localStorage.getItem('finea.app.theme') || '';
            var isDark = storedTheme === 'dark';
            document.documentElement.classList.toggle('finea-dark-mode', isDark);
            if (document.body) {
                document.body.classList.toggle('finea-dark-mode', isDark);
                document.body.classList.toggle('portal-theme-dark', isDark);
            }
        } catch (e) {
            // noop
        }
    })();
</script>
<style>
    :root{--finea-brand-hover:color-mix(in srgb,var(--brand-accent) 92%,#000);--finea-brand-soft:color-mix(in srgb,var(--brand-accent) 12%,#fff)}
    .finea-mega-panel{visibility:hidden;opacity:0;transform:translateY(8px);pointer-events:none;width:min(680px,calc(100vw - 24px));max-width:calc(100vw - 24px);overflow-x:hidden;transition:opacity .16s ease,transform .16s ease,visibility .16s}
    .finea-mega:hover .finea-mega-panel,.finea-mega:focus-within .finea-mega-panel,.finea-mega.is-open .finea-mega-panel{visibility:visible;opacity:1;transform:translateY(0);pointer-events:auto}
    .finea-brand-nav{background:color-mix(in srgb,var(--brand-primary,#050505) 97%,transparent);border-bottom:3px solid var(--finea-brand);box-shadow:0 16px 38px rgba(15,23,42,.24)}
    .finea-brand-home{border-color:rgb(var(--brand-accent-rgb) / .36)!important;background:rgba(255,255,255,.07)!important;color:#fff!important}
    .finea-brand-home:hover{border-color:var(--finea-brand)!important;background:rgb(var(--brand-accent-rgb) / .12)!important}
    .finea-brand-action{color:#e2e8f0!important}
    .finea-brand-action:hover{background:rgba(255,255,255,.1)!important;color:var(--finea-brand)!important}
    .finea-brand-action i,.finea-brand-action svg{color:inherit!important}
    .finea-brand-strip{border-color:rgba(255,255,255,.13)!important}
    .finea-brand-category{color:#e2e8f0!important}
    .finea-brand-category:hover,.finea-mega.is-open .finea-brand-category{background:var(--finea-brand)!important;color:var(--brand-on-accent,#fff)!important}
    .finea-brand-category>i{color:var(--finea-brand)!important}
    .finea-brand-category:hover>i,.finea-mega.is-open .finea-brand-category>i{color:var(--brand-on-accent,#fff)!important}
    .finea-brand-panel{border-top:4px solid var(--finea-brand)!important}
    .finea-brand-panel-title{color:#111827!important;border-color:rgb(var(--brand-accent-rgb) / .35)!important}
    .finea-brand-panel-title i{color:var(--brand-primary)}
    .finea-brand-portal{background:var(--finea-brand)!important;color:var(--brand-on-accent,#fff)!important}
    .finea-brand-portal:hover{background:var(--finea-brand)!important;filter:brightness(.96)}
    .finea-brand-portal :where(i,svg,span:not([class*="bg-"]),small),.finea-brand-accent-box :where(i,svg){color:inherit!important}
    .finea-brand-label{color:var(--finea-brand)!important}
    .finea-brand-accent-box{background:var(--finea-brand)!important;border-color:var(--finea-brand)!important;color:var(--brand-on-accent,#050505)!important}
    .finea-brand-mobile-head .finea-brand-label{color:var(--brand-on-primary,#fff)!important}
    .finea-brand-mobile-head{background:var(--brand-primary,#050505)!important;border-color:var(--finea-brand)!important;color:var(--brand-on-primary,#fff)!important}
</style>
<nav id="appMenu" class="finea-brand-nav sticky top-0 z-40 mb-6 backdrop-blur-xl">
    <div class="container mx-auto px-4 py-3">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <a href="<?= htmlspecialchars($layoutRootBase, ENT_QUOTES, 'UTF-8') ?>selection_portail.php" class="finea-brand-home inline-flex items-center gap-3 rounded-2xl border px-3 py-2 shadow-sm transition" aria-label="Retour au portail Finéa" data-tour="true" data-tour-title="Logo et retour portail" data-tour-content="Cliquez ici pour revenir rapidement au portail principal Finéa." data-tour-order="10">
                    <?php if ($brandIconUrl !== ''): ?>
                        <span class="inline-flex h-10 w-10 items-center justify-center rounded-2xl bg-slate-50 p-2 md:hidden">
                            <img src="<?= htmlspecialchars($brandIconUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Icône Finéa" class="h-full w-full object-contain">
                        </span>
                    <?php endif; ?>
                    <?php if ($brandLogoUrl !== ''): ?>
                        <img src="<?= htmlspecialchars($brandLogoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Logo Finéa" class="hidden h-10 w-auto object-contain md:block">
                    <?php elseif ($brandIconUrl !== ''): ?>
                        <span class="hidden items-center gap-3 md:inline-flex">
                            <span class="inline-flex h-10 w-10 items-center justify-center rounded-2xl bg-slate-50 p-2">
                                <img src="<?= htmlspecialchars($brandIconUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Icône Finéa" class="h-full w-full object-contain">
                            </span>
                            <span class="font-bold text-indigo-700 text-xl tracking-tight">Finéa</span>
                        </span>
                    <?php else: ?>
                        <span class="font-bold text-indigo-700 text-xl tracking-tight">Finéa</span>
                    <?php endif; ?>
                    <span class="hidden border-l border-white/15 pl-3 xl:block">
                        <span class="finea-brand-label block text-[10px] font-black uppercase tracking-[.22em]">Finéa</span>
                        <span class="block max-w-[250px] truncate text-xs font-semibold text-white/80"><?= htmlspecialchars($brandCompanyName, ENT_QUOTES, 'UTF-8') ?></span>
                    </span>
                </a>
            </div>

            <div class="relative flex items-center gap-2">

                <div class="md:hidden">
                    <button id="burgerBtn" type="button" class="finea-brand-portal inline-flex items-center justify-center gap-2 rounded-2xl border px-3 py-2 text-sm font-bold" aria-controls="mobileMenu" aria-expanded="false">
                        <i id="burgerIcon" class="fa-solid fa-bars"></i>
                        <span>Menu</span>
                    </button>
                </div>

                <?php if (hasPermission('acces_mail')): ?>
                    <a id="mailBtn" href="<?= htmlspecialchars($mailboxPageUrl, ENT_QUOTES, 'UTF-8') ?>" class="finea-brand-action relative rounded-lg p-2 transition" aria-label="Messagerie pro" title="Messagerie pro" data-tour="true" data-tour-title="Messagerie professionnelle" data-tour-content="Accédez à vos e-mails professionnels et surveillez le compteur de messages non lus." data-tour-order="20">
                        <span class="relative inline-flex items-center justify-center">
                            <i id="mailIcon" class="fa-regular fa-envelope text-gray-700 text-lg"></i>
                            <span id="mailSlash" class="hidden absolute left-1/2 top-1/2 h-[2px] w-7 -translate-x-1/2 -translate-y-1/2 rotate-45 bg-slate-500/70"></span>
                        </span>
                        <span id="mailBadge" class="hidden absolute -top-1 -right-1 min-w-[18px] h-[18px] px-1 text-[11px] leading-[18px] text-center rounded-full bg-indigo-600 text-white">0</span>
                    </a>
                <?php endif; ?>

                <div class="relative">
                    <button id="notifBellBtn" type="button" class="finea-brand-action relative rounded-lg p-2 transition" aria-label="Notifications" data-tour="true" data-tour-title="Notifications" data-tour-content="Consultez les alertes importantes, les validations, les rappels et les informations système." data-tour-order="30">
                        <i class="fa-regular fa-bell text-gray-700 text-lg"></i>
                        <span id="notifBadge" class="hidden absolute -top-1 -right-1 min-w-[18px] h-[18px] px-1 text-[11px] leading-[18px] text-center rounded-full bg-indigo-600 text-white">
                            0
                        </span>
                    </button>

                    <div id="notifMenu" class="hidden absolute right-0 top-12 w-80 bg-white border border-gray-100 rounded-xl shadow-lg z-[60] overflow-hidden">
                        <div class="px-4 py-3 border-b bg-gray-50 flex items-center justify-between">
                            <div class="text-sm font-semibold text-gray-800">Notifications</div>
                            <button id="notifMarkAllBtn" type="button" class="text-xs text-indigo-700 hover:underline">Tout marquer lu</button>
                        </div>
                        <div id="notifList" class="max-h-80 overflow-auto">
                            <div class="px-4 py-4 text-sm text-gray-500">Aucune notification.</div>
                        </div>
                        <a href="<?= htmlspecialchars($layoutRootBase . 'notifications.php', ENT_QUOTES, 'UTF-8') ?>" class="flex items-center justify-center border-t bg-white px-4 py-3 text-sm font-black text-indigo-700 hover:bg-indigo-50"><i class="fa-solid fa-list-check mr-2"></i>Voir toutes les notifications</a>
                    </div>
                </div>

                <button id="helpBtn" type="button" class="finea-brand-action relative rounded-lg p-2 transition" aria-label="Aide" title="Aide" data-tour="true" data-tour-title="Guide interactif" data-tour-content="Lancez le guide pas à pas de l’interface. Le guide s’adapte aux éléments documentés sur chaque page." data-tour-order="40">
                    <i class="fa-regular fa-circle-question text-gray-700 text-lg"></i>
                </button>

                <button id="fineaGlobalThemeToggle" type="button" class="finea-theme-toggle" aria-label="Activer le mode sombre" aria-pressed="false" title="Mode sombre">
                    <span class="finea-theme-orbit" aria-hidden="true"></span>
                    <i class="fa-solid fa-sun" aria-hidden="true"></i>
                    <i class="fa-solid fa-moon" aria-hidden="true"></i>
                    <span class="sr-only" data-theme-label>Mode clair</span>
                </button>
                <span class="hidden text-xs font-black text-slate-600 2xl:inline" data-theme-label>Mode clair</span>

                <button id="userMenuBtn"
                    class="finea-brand-action flex items-center gap-3 rounded-lg px-3 py-2 transition" data-tour="true" data-tour-title="Menu utilisateur" data-tour-content="Ouvrez votre profil, vos paramètres, la sécurité du compte ou déconnectez-vous." data-tour-order="50">

                    <img
                        src="<?= htmlspecialchars($photoSrc, ENT_QUOTES, 'UTF-8') ?>"
                        class="w-9 h-9 rounded-full object-cover border">

                    <div class="hidden md:flex flex-col text-left leading-tight">
                        <span class="text-sm font-semibold text-white">
                            <span class="block max-w-[180px] truncate"><?= htmlspecialchars($displayName) ?></span>
                        </span>

                        <span class="text-xs text-slate-300">
                            <?= htmlspecialchars($userRoleLabel) ?>
                        </span>
                    </div>

                    <svg class="w-4 h-4 text-gray-500"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24">

                        <path stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M19 9l-7 7-7-7" />

                    </svg>

                </button>


                <div id="userMenu"
                    class="hidden absolute right-0 top-12 w-56 bg-white border border-gray-100 rounded-xl shadow-lg z-[60] overflow-hidden">

                    <div class="px-4 py-4 border-b bg-gray-50">
                        <div class="flex items-center gap-3">
                            <img src="<?= htmlspecialchars($photoSrc, ENT_QUOTES, 'UTF-8') ?>" class="w-11 h-11 rounded-2xl object-cover border bg-white" alt="Photo de profil">
                            <div class="min-w-0">
                                <div class="text-sm font-semibold text-gray-800 truncate">
                                    <?= htmlspecialchars($displayName) ?>
                                </div>

                                <div class="text-xs text-gray-500 truncate">
                                    <?= htmlspecialchars($userMail) ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <a href="<?= htmlspecialchars($layoutModuleBase, ENT_QUOTES, 'UTF-8') ?>profil.php"
                        class="flex items-center gap-2 px-4 py-3 text-sm text-gray-700 hover:bg-gray-50">

                        <i class="fa-regular fa-user"></i>
                        Mon profil
                    </a>

                    <a href="<?= htmlspecialchars($layoutModuleBase, ENT_QUOTES, 'UTF-8') ?>profil.php#securite"
                        class="flex items-center gap-2 px-4 py-3 text-sm text-gray-700 hover:bg-gray-50">

                        <i class="fa-solid fa-shield-halved"></i>
                        Sécurité du compte

                    </a>

                    <a href="<?= htmlspecialchars($layoutModuleBase, ENT_QUOTES, 'UTF-8') ?>profil.php#parametres"
                        class="flex items-center gap-2 px-4 py-3 text-sm text-gray-700 hover:bg-gray-50">

                        <i class="fa-solid fa-gear"></i>
                        ParamÃ¨tres

                    </a>

                    <a href="<?= htmlspecialchars($layoutRootBase, ENT_QUOTES, 'UTF-8') ?>selection_portail.php"
                        class="flex items-center gap-2 px-4 py-3 text-sm text-gray-700 hover:bg-gray-50">

                        <i class="fa-solid fa-table-cells"></i>
                        Changer de portail

                    </a>

                    <a href="<?= htmlspecialchars($layoutRootBase, ENT_QUOTES, 'UTF-8') ?>moteur.php"
                        class="flex items-center gap-2 px-4 py-3 text-sm text-gray-700 hover:bg-gray-50">

                        <i class="fa-solid fa-magnifying-glass"></i>
                        Moteur Finéa

                    </a>

                    <hr>

                    <a href="<?= htmlspecialchars($layoutRootBase, ENT_QUOTES, 'UTF-8') ?>deconnex.php"
                        class="flex items-center gap-2 px-4 py-3 text-sm text-red-600 hover:bg-red-50">

                        <i class="fa-solid fa-right-from-bracket"></i>
                        Déconnexion

                    </a>

                </div>

            </div>
        </div>

        <!-- Desktop menu (wrap pour éviter tout débordement) -->
        <style>
a.finea-engine-link.finea-engine-link {background:#f1f5f9!important;color:#17243d!important;border-color:#cbd5e1!important}
a.finea-engine-link.finea-engine-link i {color:inherit!important}
:is(html.finea-dark-mode,body.finea-dark-mode) a.finea-engine-link.finea-engine-link {background:#172337!important;color:#f5f8fc!important;border-color:#52647e!important}
:is(html.finea-dark-mode,body.finea-dark-mode) a.finea-engine-link.finea-engine-link:hover {background:#202e43!important;color:#f5f8fc!important}
a.finea-engine-link.finea-engine-link:focus-visible {outline:2px solid #6495ed;outline-offset:2px}
</style>
<div class="finea-brand-strip mt-3 hidden items-center gap-2 border-t pt-3 md:flex">
            <a href="<?=htmlspecialchars($layoutRootBase.'selection_portail.php',ENT_QUOTES,'UTF-8')?>" class="finea-brand-portal inline-flex shrink-0 items-center gap-2 rounded-xl px-3 py-2 text-sm font-black shadow-sm"><i class="fa-solid fa-grip"></i>Portail</a>
            <a href="<?=htmlspecialchars($layoutRootBase.'moteur.php',ENT_QUOTES,'UTF-8')?>" class="finea-engine-link inline-flex shrink-0 items-center gap-2 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-black text-slate-900 shadow-sm transition hover:border-amber-300 hover:bg-amber-100"><i class="fa-solid fa-magnifying-glass text-amber-600"></i>Moteur</a>
            <div class="flex min-w-0 flex-1 flex-wrap items-center gap-1 overflow-visible">
            <?php foreach ($visibleNavGroups as $categoryLabel=>$categoryItems): ?>
            <?php $categoryBadge=array_sum(array_map(static fn($link)=>(int)($link['badge']??0),$categoryItems)); ?>
            <div class="finea-mega relative shrink-0" data-finea-mega>
                <button type="button" class="finea-brand-category finea-mega-toggle inline-flex items-center gap-2 rounded-xl px-3 py-2 text-sm font-black transition" aria-expanded="false"><i class="fa-solid <?=htmlspecialchars($navCategoryIcons[$categoryLabel]??'fa-folder')?>"></i><?=htmlspecialchars($categoryLabel)?><?php if($categoryBadge>0):?><span class="rounded-full bg-rose-600 px-1.5 py-0.5 text-[10px] text-white"><?=$categoryBadge>99?'99+':$categoryBadge?></span><?php endif;?><i class="fa-solid fa-chevron-down text-[9px] opacity-60"></i></button>
                <div class="finea-brand-panel finea-mega-panel fixed z-[70] rounded-2xl border border-slate-200 bg-white p-3 shadow-2xl">
                    <div class="finea-brand-panel-title mb-2 flex items-center gap-2 border-b px-2 pb-2 text-xs font-black uppercase tracking-[.14em]"><i class="fa-solid <?=htmlspecialchars($navCategoryIcons[$categoryLabel]??'fa-folder')?>"></i><?=htmlspecialchars($categoryLabel)?></div>
                    <div class="grid grid-cols-2 gap-1 lg:grid-cols-3">
            <?php foreach ($categoryItems as $item): ?>
                <?php
                $isActive = ($activeMenu === ($item['active'] ?? ''));
                $icon = (string) ($item['icon'] ?? '');
                $sublabel = (string) ($item['sublabel'] ?? '');
                $badge=(int)($item['badge']??0);
                ?>
                <a href="<?= htmlspecialchars((string) ($item['href'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" class="<?= navLinkClass($isActive, true) ?> min-h-[54px]" data-tour="true" data-tour-title="Module <?= htmlspecialchars((string) ($item['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" data-tour-content="Ouvre le module <?= htmlspecialchars((string) ($item['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?><?= $sublabel !== '' ? ' : ' . htmlspecialchars($sublabel, ENT_QUOTES, 'UTF-8') : '' ?>." data-tour-group="navbar" data-tour-order="<?= 100 + (int) array_search($item, $navLinks, true) ?>">
                    <?php if ($icon !== ''): ?>
                        <i class="<?= htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') ?> <?= $isActive ? 'text-indigo-700' : 'text-slate-500' ?>"></i>
                    <?php endif; ?>
                    <span class="flex flex-col items-start leading-tight">
                        <span><?= htmlspecialchars((string) ($item['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                        <?php if ($sublabel !== ''): ?>
                            <span class="text-[10px] font-medium text-slate-500"><?= htmlspecialchars($sublabel, ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endif; ?>
                    </span>
                    <?php if($badge>0):?><span class="ml-1 inline-flex min-w-[20px] items-center justify-center rounded-full bg-rose-600 px-1.5 py-0.5 text-[10px] font-black text-white"><?=$badge>99?'99+':$badge?></span><?php endif;?>
                </a>
            <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            </div>
        </div>
    </div>
</nav>

<?php if ($activeMenu === 'rh'): ?>
    <?php
    $rhCurrentPage = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $rhQuickLinks = [
        ['dashboard_rh.php', 'fa-gauge-high', 'Dashboard'],
        ['liste_personnel_rh.php', 'fa-users', 'Personnel'],
        ['prestataires_rh.php', 'fa-user-tie', 'Prestataires'],
        ['fiches_poste_rh.php', 'fa-clipboard-list', 'Fiches de poste'],
        ['integration_personnel_rh.php', 'fa-user-plus', 'Intégration'],
        ['pointage_journalier_rh.php', 'fa-user-check', 'Pointage'],
        ['validation_demandes_rh.php', 'fa-clipboard-check', 'Demandes'],
        ['dashboard_rh.php?view=analytique', 'fa-chart-line', 'Rapports'],
        ['conges_initialisation_rh.php', 'fa-umbrella-beach', 'Congés'],
        ['paie_rh.php', 'fa-file-invoice-dollar', 'Paie'],
        ['contrats_employes_rh.php', 'fa-file-signature', 'Contrats'],
        ['organigramme_rh.php', 'fa-sitemap', 'Organigramme'],
        ['parametrage_rh.php', 'fa-sliders', 'Paramètres'],
    ];
    ?>
    <style>
html body .rh-quick-nav{background:var(--brand-primary)!important;color:var(--brand-on-primary)!important;border-color:var(--brand-accent)!important}
html body .rh-quick-nav a.rh-quick-link{background:transparent!important;color:var(--brand-on-primary)!important;border:1px solid transparent!important}
html body .rh-quick-nav a.rh-quick-link:hover{background:color-mix(in srgb,var(--brand-on-primary) 15%,var(--brand-primary))!important;color:var(--brand-on-primary)!important}
html body .rh-quick-nav a.rh-quick-link[aria-current="page"]{background:var(--brand-accent)!important;color:var(--brand-on-accent)!important;border-color:var(--brand-accent)!important}
html body .rh-quick-nav a.rh-quick-link i{color:inherit!important}
html body .rh-quick-nav .finea-brand-label{color:var(--brand-on-primary)!important}
html body .rh-quick-nav a.rh-quick-link:focus-visible{outline:2px solid var(--brand-on-primary);outline-offset:2px}
</style>
<div class="rh-quick-nav finea-brand-mobile-head relative z-30 -mt-6 border-b text-white shadow-lg shadow-slate-950/10">
        <div class="mx-auto flex max-w-[1600px] items-center gap-2 overflow-x-auto px-4 py-3 [scrollbar-width:thin]">
            <div class="mr-2 hidden shrink-0 items-center gap-2 border-r border-white/15 pr-4 lg:flex">
                <span class="finea-brand-accent-box flex h-8 w-8 items-center justify-center rounded-xl"><i class="fas fa-people-group"></i></span>
                <div><div class="finea-brand-label text-[10px] font-bold uppercase tracking-[0.22em]">Finea</div><div class="text-xs font-semibold">Ressources humaines</div></div>
            </div>
            <button type="button" id="rhModuleThemeToggle" class="inline-flex shrink-0 items-center gap-2 rounded-xl px-3 py-2 text-xs font-bold" aria-label="Changer le thème">Mode sombre</button>
            <script>
            (function(){
              const root=document.documentElement,button=document.getElementById('rhModuleThemeToggle');
              function sync(){const dark=root.classList.contains('finea-dark-mode');document.body.classList.toggle('finea-dark-mode',dark);button.textContent=dark?'☀ Mode clair':'☾ Mode sombre';button.setAttribute('aria-pressed',String(dark));}
              sync();
              new MutationObserver(sync).observe(root,{attributes:true,attributeFilter:['class']});
              button.addEventListener('click',()=>{const dark=!root.classList.contains('finea-dark-mode');root.classList.toggle('finea-dark-mode',dark);sync();try{['finea.app.theme','finea.portal.theme'].forEach(k=>localStorage.setItem(k,dark?'dark':'light'));}catch(e){}});
              window.addEventListener('storage',e=>{if(['finea.app.theme','finea.portal.theme'].includes(e.key))root.classList.toggle('finea-dark-mode',e.newValue==='dark');});
            })();
            </script>
            <?php foreach ($rhQuickLinks as [$rhHref, $rhIcon, $rhLabel]): ?>
                <?php $rhIsActive = $rhCurrentPage === $rhHref; ?>
                <a href="<?= htmlspecialchars($layoutRootBase . 'rh/' . $rhHref) ?>" <?= $rhIsActive ? 'aria-current="page"' : '' ?> class="rh-quick-link inline-flex shrink-0 items-center gap-2 rounded-xl px-3.5 py-2 text-xs font-semibold transition <?= $rhIsActive ? 'finea-brand-portal shadow-md' : 'text-slate-200 hover:bg-white/10 hover:text-white' ?>">
                    <i class="fas <?= htmlspecialchars($rhIcon) ?>"></i><?= htmlspecialchars($rhLabel) ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<!-- Mobile drawer : rendu hors du <nav> pour éviter les problèmes de stacking context (backdrop-blur) -->
<div class="md:hidden">
    <div id="mobileMenuOverlay" class="fixed inset-0 bg-slate-900/40 z-[9998] opacity-0 pointer-events-none transition-opacity duration-300" data-nav-close-mobile aria-hidden="true" style="position:fixed; inset:0; opacity:0; pointer-events:none; visibility:hidden;"></div>

    <aside id="mobileMenu" class="fixed inset-y-0 right-0 w-[92%] max-w-sm bg-white z-[9999] flex flex-col translate-x-full pointer-events-none transition-transform duration-300 ease-out will-change-transform border-l border-slate-200 shadow-2xl" role="dialog" aria-modal="true" aria-label="Menu" aria-hidden="true" tabindex="-1" style="position:fixed; top:0; right:0; bottom:0; width:92vw; max-width:24rem; display:flex; flex-direction:column; transform:translateX(100%); pointer-events:none; visibility:hidden;">
        <div class="finea-brand-mobile-head sticky top-0 flex items-center justify-between border-b px-4 py-4">
            <div class="min-w-0 flex items-center gap-3">
                <?php if ($brandIconUrl !== ''): ?>
                    <span class="finea-brand-accent-box inline-flex h-11 w-11 items-center justify-center rounded-2xl border p-2 shadow-sm">
                        <img src="<?= htmlspecialchars($brandIconUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Icône Finéa" class="h-full w-full object-contain">
                    </span>
                <?php endif; ?>
                <div>
                    <div class="finea-brand-label text-xs font-semibold uppercase tracking-wide">Navigation globale</div>
                    <div class="text-xl font-bold text-white truncate">Finéa</div>
                </div>
            </div>
            <button id="mobileMenuCloseBtn" type="button" class="inline-flex items-center justify-center rounded-xl border border-white/20 bg-white/10 p-2 text-white hover:bg-white/20" data-nav-close-mobile aria-label="Fermer le menu">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <!-- Profil utilisateur (visible en mobile) -->
        <div class="px-4 py-4 border-b border-slate-100">
            <div class="flex items-center gap-3">
                <img src="<?= htmlspecialchars($photoSrc, ENT_QUOTES, 'UTF-8') ?>" class="w-12 h-12 rounded-2xl object-cover border" alt="Photo de profil">
                <div class="min-w-0">
                    <div class="text-sm font-bold text-slate-900 truncate"><?= htmlspecialchars($displayName) ?></div>
                    <div class="text-xs text-slate-500 truncate"><?= htmlspecialchars($userRoleLabel) ?></div>
                    <?php if ($userMail !== ''): ?>
                        <div class="text-xs text-slate-500 truncate"><?= htmlspecialchars($userMail) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="grid gap-2 mt-3">
                <button id="fineaGlobalThemeToggleMobile" type="button" class="finea-theme-row">
                    <span class="finea-theme-toggle" aria-hidden="true">
                        <span class="finea-theme-orbit"></span>
                        <i class="fa-solid fa-sun"></i>
                        <i class="fa-solid fa-moon"></i>
                    </span>
                    <span class="truncate">Mode sombre</span>
                    <i class="fa-solid fa-chevron-right ml-auto text-slate-400"></i>
                </button>
                <a href="<?= htmlspecialchars($layoutModuleBase, ENT_QUOTES, 'UTF-8') ?>profil.php" class="flex items-center gap-3 w-full px-4 py-3 rounded-2xl border border-slate-200 text-sm font-semibold text-slate-800 hover:bg-slate-50" data-nav-close-mobile>
                    <i class="fa-regular fa-user text-slate-600"></i>
                    <span class="truncate">Mon profil</span>
                    <i class="fa-solid fa-chevron-right ml-auto text-slate-400"></i>
                </a>
                <a href="<?= htmlspecialchars($layoutModuleBase, ENT_QUOTES, 'UTF-8') ?>profil.php#parametres" class="flex items-center gap-3 w-full px-4 py-3 rounded-2xl border border-slate-200 text-sm font-semibold text-slate-800 hover:bg-slate-50" data-nav-close-mobile>
                    <i class="fa-solid fa-gear text-slate-600"></i>
                    <span class="truncate">ParamÃ¨tres</span>
                    <i class="fa-solid fa-chevron-right ml-auto text-slate-400"></i>
                </a>
                <a href="<?= htmlspecialchars($layoutRootBase, ENT_QUOTES, 'UTF-8') ?>deconnex.php" class="flex items-center gap-3 w-full px-4 py-3 rounded-2xl border border-red-200 text-sm font-semibold text-red-700 hover:bg-red-50" data-nav-close-mobile>
                    <i class="fa-solid fa-right-from-bracket text-red-600"></i>
                    <span class="truncate">Déconnexion</span>
                    <i class="fa-solid fa-chevron-right ml-auto text-red-300"></i>
                </a>
            </div>
        </div>

        <div class="flex-1 overflow-y-auto p-3 grid gap-2">
            <a href="<?=htmlspecialchars($layoutRootBase.'selection_portail.php',ENT_QUOTES,'UTF-8')?>" class="flex items-center gap-3 rounded-2xl bg-slate-950 px-4 py-3 text-sm font-black text-white" data-nav-close-mobile><i class="fa-solid fa-grip"></i>Portail<i class="fa-solid fa-chevron-right ml-auto text-slate-400"></i></a>
            <a href="<?=htmlspecialchars($layoutRootBase.'moteur.php',ENT_QUOTES,'UTF-8')?>" class="finea-engine-link flex items-center gap-3 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-black text-slate-900" data-nav-close-mobile><i class="fa-solid fa-magnifying-glass text-amber-600"></i>Moteur Finéa<i class="fa-solid fa-chevron-right ml-auto text-amber-500"></i></a>
            <?php foreach ($visibleNavGroups as $categoryLabel=>$categoryItems): ?>
                <div class="px-3 pb-1 pt-4 text-[11px] font-black uppercase tracking-[.16em] text-amber-600"><i class="fa-solid <?=htmlspecialchars($navCategoryIcons[$categoryLabel]??'fa-folder')?> mr-2"></i><?=htmlspecialchars($categoryLabel)?></div>
            <?php foreach ($categoryItems as $item): ?>
                <?php
                $isActive = ($activeMenu === ($item['active'] ?? ''));
                $icon = (string) ($item['icon'] ?? '');
                $sublabel = (string) ($item['sublabel'] ?? '');
                $badge=(int)($item['badge']??0);
                ?>
                <a href="<?= htmlspecialchars((string) ($item['href'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" class="<?= navLinkClass($isActive, true) ?>" data-nav-close-mobile>
                    <?php if ($icon !== ''): ?>
                        <i class="<?= htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') ?> <?= $isActive ? 'text-black' : 'text-slate-500' ?>"></i>
                    <?php endif; ?>
                    <span class="flex flex-col min-w-0">
                        <span class="truncate"><?= htmlspecialchars((string) ($item['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                        <?php if ($sublabel !== ''): ?>
                            <span class="text-[11px] font-medium text-slate-500 truncate"><?= htmlspecialchars($sublabel, ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endif; ?>
                    </span>
                    <?php if($badge>0):?><span class="ml-auto inline-flex min-w-[24px] items-center justify-center rounded-full bg-rose-600 px-2 py-1 text-[11px] font-black text-white"><?=$badge>99?'99+':$badge?></span><?php endif;?>
                    <i class="fa-solid fa-chevron-right ml-auto text-slate-400"></i>
                </a>
            <?php endforeach; ?>
            <?php endforeach; ?>
        </div>
    </aside>
</div>

<script>
(() => {
    const menus=[...document.querySelectorAll('[data-finea-mega]')];
    const positionPanel=menu=>{
        const button=menu.querySelector('.finea-mega-toggle'),panel=menu.querySelector('.finea-mega-panel');
        if(!button||!panel)return;
        panel.style.left='12px';panel.style.right='auto';panel.style.top='0';
        const buttonRect=button.getBoundingClientRect(),panelWidth=Math.min(680,window.innerWidth-24);
        const left=Math.max(12,Math.min(buttonRect.left,window.innerWidth-panelWidth-12));
        const top=buttonRect.bottom+8;
        panel.style.left=left+'px';panel.style.top=top+'px';
        panel.style.maxHeight=Math.max(180,window.innerHeight-top-12)+'px';
        panel.style.overflowY='auto';
    };
    menus.forEach(menu=>{
        const button=menu.querySelector('.finea-mega-toggle');
        menu.addEventListener('mouseenter',()=>positionPanel(menu));
        menu.addEventListener('focusin',()=>positionPanel(menu));
        button?.addEventListener('click',event=>{
            event.stopPropagation();
            const open=!menu.classList.contains('is-open');
            menus.forEach(other=>{other.classList.remove('is-open');other.querySelector('.finea-mega-toggle')?.setAttribute('aria-expanded','false');});
            if(open){positionPanel(menu);menu.classList.add('is-open');button.setAttribute('aria-expanded','true');}
        });
    });
    document.addEventListener('click',()=>menus.forEach(menu=>{menu.classList.remove('is-open');menu.querySelector('.finea-mega-toggle')?.setAttribute('aria-expanded','false');}));
    document.addEventListener('keydown',event=>{if(event.key==='Escape')menus.forEach(menu=>{menu.classList.remove('is-open');menu.querySelector('.finea-mega-toggle')?.setAttribute('aria-expanded','false');});});
    window.addEventListener('resize',()=>menus.forEach(menu=>{if(menu.classList.contains('is-open'))positionPanel(menu);}));
    window.addEventListener('scroll',()=>menus.forEach(menu=>menu.classList.remove('is-open')),{passive:true});
})();
</script>

<div id="notifToastContainer" class="fixed top-4 left-1/2 -translate-x-1/2 z-[9000] w-[95%] max-w-xl pointer-events-none"></div>


<style>
    .finea-theme-toggle {
        position: relative;
        width: 4.85rem;
        min-width: 4.85rem;
        height: 2.65rem;
        border: 1px solid rgba(148, 163, 184, .22);
        border-radius: 999px;
        display: inline-flex;
        align-items: center;
        justify-content: space-between;
        padding: .32rem;
        background: rgba(255, 255, 255, .82);
        color: #334155;
        overflow: hidden;
        cursor: pointer;
        transition: background .22s ease, border-color .22s ease, color .22s ease, transform .18s ease;
    }

    .finea-theme-toggle:hover {
        transform: translateY(-1px);
        border-color: rgba(29, 43, 87, .22);
    }

    .finea-theme-toggle .finea-theme-orbit {
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

    .finea-theme-toggle i,
    .finea-theme-toggle span:not(.finea-theme-orbit) {
        position: relative;
        z-index: 2;
    }

    .finea-theme-toggle i {
        width: 2rem;
        height: 2rem;
        display: grid;
        place-items: center;
        font-size: .86rem;
    }

    .finea-theme-row {
        display: flex;
        align-items: center;
        gap: .75rem;
        width: 100%;
        border: 1px solid rgb(226 232 240);
        border-radius: 1rem;
        padding: .75rem 1rem;
        background: #fff;
        color: #1f2937;
        font-size: .875rem;
        font-weight: 700;
        text-align: left;
        transition: background .18s ease, border-color .18s ease, color .18s ease;
    }

    html.finea-dark-mode {
        color-scheme: dark;
    }

    body.finea-dark-mode {
        background:
            radial-gradient(1200px 640px at 0% 0%, rgba(250, 189, 2, .10) 0%, transparent 58%),
            radial-gradient(1000px 580px at 100% 0%, rgba(96, 165, 250, .13) 0%, transparent 62%),
            linear-gradient(180deg, #020617 0%, #0f172a 50%, #111827 100%) !important;
        color: #e5edf8;
    }

    body.finea-dark-mode #appMenu,
    body.finea-dark-mode #mobileMenu,
    body.finea-dark-mode #mobileMenu .sticky,
    body.finea-dark-mode #notifMenu,
    body.finea-dark-mode #userMenu {
        background: rgba(15, 23, 42, .94) !important;
        border-color: rgba(148, 163, 184, .20) !important;
        color: #e5edf8 !important;
        box-shadow: 0 24px 70px rgba(0, 0, 0, .38);
    }

    body.finea-dark-mode #appMenu {
        backdrop-filter: blur(18px);
        -webkit-backdrop-filter: blur(18px);
    }

    body.finea-dark-mode .finea-theme-toggle {
        background: rgba(15, 23, 42, .9);
        color: #e5edf8;
        border-color: rgba(148, 163, 184, .24);
    }

    body.finea-dark-mode .finea-theme-toggle .finea-theme-orbit {
        transform: translateX(2.18rem);
        background:
            radial-gradient(circle at 64% 35%, rgba(15, 23, 42, .95) 0 26%, transparent 27%),
            radial-gradient(circle at 45% 48%, #e2e8f0 0 44%, #94a3b8 74%);
        box-shadow: 0 0 22px rgba(191, 219, 254, .42);
    }

    body.finea-dark-mode .finea-theme-row {
        background: rgba(15, 23, 42, .72);
        border-color: rgba(148, 163, 184, .20);
        color: #e5edf8;
    }

    body.finea-dark-mode :where(.bg-white, .bg-gray-50, .bg-slate-50, .bg-slate-100, .bg-indigo-50):not(.bg-indigo-600):not(.bg-red-600):not(.bg-green-600):not(.bg-emerald-600):not(.bg-blue-600) {
        background-color: rgba(15, 23, 42, .82) !important;
    }

    body.finea-dark-mode :where(.text-gray-900, .text-gray-800, .text-slate-900, .text-slate-800, .text-indigo-800, .text-indigo-700) {
        color: #f8fafc !important;
    }

    body.finea-dark-mode :where(.text-gray-700, .text-gray-600, .text-gray-500, .text-slate-700, .text-slate-600, .text-slate-500, .text-slate-400) {
        color: #cbd5e1 !important;
    }

    body.finea-dark-mode :where(.border, .border-gray-100, .border-gray-200, .border-slate-100, .border-slate-200, .border-indigo-50, .border-indigo-100, .border-indigo-200) {
        border-color: rgba(148, 163, 184, .22) !important;
    }

    body.finea-dark-mode :where(.hover\:bg-gray-100:hover, .hover\:bg-gray-50:hover, .hover\:bg-slate-50:hover, .hover\:border-slate-300:hover) {
        background-color: rgba(30, 41, 59, .92) !important;
        border-color: rgba(148, 163, 184, .30) !important;
    }

    body.finea-dark-mode :where(.shadow, .shadow-sm, .shadow-md, .shadow-lg, .shadow-xl, .shadow-2xl) {
        box-shadow: 0 18px 50px rgba(0, 0, 0, .34) !important;
    }

    body.finea-dark-mode :where(.card, .modal-content, .dropdown-menu, .list-group-item, .table, table, fieldset, .dataTables_wrapper, .select2-container--default .select2-selection--single, .select2-container--default .select2-selection--multiple) {
        background-color: rgba(15, 23, 42, .86) !important;
        border-color: rgba(148, 163, 184, .22) !important;
        color: #e5edf8 !important;
    }

    body.finea-dark-mode :where(input, select, textarea, .form-control, .form-select, .select2-search__field) {
        background-color: rgba(2, 6, 23, .62) !important;
        border-color: rgba(148, 163, 184, .28) !important;
        color: #f8fafc !important;
    }

    body.finea-dark-mode :where(input::placeholder, textarea::placeholder) {
        color: #94a3b8 !important;
    }

    body.finea-dark-mode :where(thead, th) {
        background-color: rgba(30, 41, 59, .95) !important;
        color: #f8fafc !important;
        border-color: rgba(148, 163, 184, .24) !important;
    }

    body.finea-dark-mode :where(td, tbody tr) {
        border-color: rgba(148, 163, 184, .16) !important;
        color: #e5edf8;
    }

    body.finea-dark-mode :where(tbody tr:hover) {
        background-color: rgba(30, 41, 59, .72) !important;
    }

    body.finea-dark-mode :where(.finea-tour-popover) {
        background: rgba(15, 23, 42, .98) !important;
        border-color: rgba(148, 163, 184, .22) !important;
        color: #f8fafc !important;
    }

    body.finea-dark-mode .finea-tour-popover :where(h3, p, .finea-tour-progress) {
        color: #e5edf8 !important;
    }

    body.finea-dark-mode #notifToastContainer > div {
        background: rgba(15, 23, 42, .96) !important;
        border-color: rgba(148, 163, 184, .22) !important;
        color: #e5edf8 !important;
    }

    .finea-tour-mask {
        position: fixed;
        background: rgba(15, 23, 42, .58);
        z-index: 2147483638;
        pointer-events: none;
        transition: all .24s ease;
    }

    .finea-tour-spotlight {
        position: fixed;
        z-index: 2147483639;
        border: 3px solid #4f46e5;
        border-radius: 22px;
        pointer-events: none;
        box-shadow: 0 0 0 2px rgba(255, 255, 255, .95), 0 18px 55px rgba(15, 23, 42, .28);
        transition: all .24s ease;
    }

    .finea-tour-active-element {
        position: relative !important;
        z-index: 2147483640 !important;
        filter: none !important;
        opacity: 1 !important;
    }

    .finea-tour-popover {
        position: fixed;
        z-index: 2147483641;
        width: min(430px, calc(100vw - 32px));
        background: #fff;
        color: #0f172a;
        border: 1px solid rgba(226, 232, 240, .95);
        border-radius: 24px;
        box-shadow: 0 28px 80px rgba(15, 23, 42, .24);
        padding: 20px;
        transition: top .24s ease, left .24s ease;
    }

    .finea-tour-popover h3 {
        margin: 0 0 6px;
        font-size: 17px;
        font-weight: 900;
        color: #0f172a;
    }

    .finea-tour-popover p {
        margin: 0;
        font-size: 14px;
        line-height: 1.55;
        color: #475569;
    }

    .finea-tour-progress {
        margin-top: 6px;
        font-size: 12px;
        font-weight: 800;
        color: #64748b;
    }

    .finea-tour-actions {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        margin-top: 18px;
    }

    .finea-tour-btn {
        border: 0;
        border-radius: 16px;
        padding: 11px 16px;
        font-size: 13px;
        font-weight: 900;
        cursor: pointer;
        transition: transform .18s ease, box-shadow .18s ease, background .18s ease;
    }

    .finea-tour-btn:hover {
        transform: translateY(-1px);
    }

    .finea-tour-btn-secondary {
        background: #f8fafc;
        color: #0f172a;
        border: 1px solid #e2e8f0;
    }

    .finea-tour-btn-primary {
        background: #111827;
        color: #fff;
        box-shadow: 0 10px 22px rgba(15, 23, 42, .22);
    }

    .finea-tour-btn:disabled {
        opacity: .45;
        cursor: not-allowed;
        transform: none;
        box-shadow: none;
    }

    @media (max-width: 640px) {
        .finea-tour-popover {
            border-radius: 20px;
            padding: 16px;
        }

        .finea-tour-actions {
            flex-wrap: wrap;
        }

        .finea-tour-btn {
            flex: 1;
        }
    }
</style>

<script>
    const NOTIF_POLL_URL = <?= json_encode($notifPollUrl, JSON_UNESCAPED_SLASHES) ?>;
    const NOTIF_MARK_READ_URL = <?= json_encode($notifMarkReadUrl, JSON_UNESCAPED_SLASHES) ?>;
    const NOTIF_SOUND_URL = <?= json_encode($notificationSoundUrl, JSON_UNESCAPED_SLASHES) ?>;
    const LAYOUT_ROOT_BASE = <?= json_encode($layoutRootBase, JSON_UNESCAPED_SLASHES) ?>;
    const MAILBOX_PAGE_URL = <?= json_encode($mailboxPageUrl, JSON_UNESCAPED_SLASHES) ?>;
    const MAILBOX_UNREAD_URL = <?= json_encode($mailboxUnreadUrl, JSON_UNESCAPED_SLASHES) ?>;

    (function initFineaGlobalThemeMode() {
        if (window.__fineaGlobalThemeModeReady) return;
        window.__fineaGlobalThemeModeReady = true;

        const storageKeys = ['finea.portal.theme', 'finea.app.theme'];
        const toggles = [
            document.getElementById('fineaGlobalThemeToggle'),
            document.getElementById('fineaGlobalThemeToggleMobile')
        ].filter(Boolean);

        function getStoredTheme() {
            try {
                return localStorage.getItem(storageKeys[0]) || localStorage.getItem(storageKeys[1]) || '';
            } catch (e) {
                return '';
            }
        }

        function persistTheme(theme) {
            try {
                storageKeys.forEach(key => localStorage.setItem(key, theme));
            } catch (e) {
                // noop
            }
        }

        function applyTheme(theme, shouldPersist = false) {
            const isDark = theme === 'dark';
            document.documentElement.classList.toggle('finea-dark-mode', isDark);
            document.body.classList.toggle('finea-dark-mode', isDark);
            document.body.classList.toggle('portal-theme-dark', isDark);
            document.documentElement.dataset.theme = isDark ? 'dark' : 'light';

            toggles.forEach(toggle => {
                toggle.setAttribute('aria-pressed', isDark ? 'true' : 'false');
                toggle.setAttribute('aria-label', isDark ? 'Activer le mode clair' : 'Activer le mode sombre');
                toggle.title = isDark ? 'Passer en mode clair' : 'Passer en mode sombre';
            });
            document.querySelectorAll('[data-theme-label]').forEach(label => {
                label.textContent = isDark ? 'Mode sombre' : 'Mode clair';
            });

            if (shouldPersist) {
                persistTheme(theme);
            }
        }

        const storedTheme = getStoredTheme();
        applyTheme(storedTheme || 'light');

        toggles.forEach(toggle => {
            toggle.addEventListener('click', () => {
                const nextTheme = document.body.classList.contains('finea-dark-mode') ? 'light' : 'dark';
                applyTheme(nextTheme, true);
            });
        });

        window.addEventListener('storage', event => {
            if (storageKeys.includes(event.key || '') && (event.newValue === 'dark' || event.newValue === 'light')) {
                applyTheme(event.newValue);
            }
        });
    })();


    /**
     * Guide interactif global Finéa.
     * Usage sur n'importe quelle page :
     * data-tour="true"
     * data-tour-title="Titre de l'étape"
     * data-tour-content="Description claire"
     * data-tour-order="10"
     * data-tour-group="finance" optionnel
     * data-tour-position="bottom" optionnel : top, bottom, left, right
     */
    (function initFineaGlobalHelpTour() {
        if (window.__fineaGlobalHelpTourReady) return;
        window.__fineaGlobalHelpTourReady = true;

        const state = {
            steps: [],
            index: 0,
            activeEl: null,
            opened: false,
            resizeTimer: null
        };

        function createNode(tag, className, parent) {
            const node = document.createElement(tag);
            if (className) node.className = className;
            if (parent) parent.appendChild(node);
            return node;
        }

        function ensureDom() {
            if (document.getElementById('fineaTourPopover')) return;
            ['top', 'left', 'right', 'bottom'].forEach((name) => {
                const m = createNode('div', 'finea-tour-mask', document.body);
                m.id = 'fineaTourMask' + name.charAt(0).toUpperCase() + name.slice(1);
                m.hidden = true;
            });
            const spotlight = createNode('div', 'finea-tour-spotlight', document.body);
            spotlight.id = 'fineaTourSpotlight';
            spotlight.hidden = true;

            const pop = createNode('div', 'finea-tour-popover', document.body);
            pop.id = 'fineaTourPopover';
            pop.hidden = true;
            pop.setAttribute('role', 'dialog');
            pop.setAttribute('aria-live', 'polite');
            pop.innerHTML = `
                <h3 id="fineaTourTitle">Bienvenue</h3>
                <div id="fineaTourProgress" class="finea-tour-progress">Étape 1 / 1</div>
                <p id="fineaTourContent">Ce guide vous accompagne dans l’interface.</p>
                <div class="finea-tour-actions">
                    <button type="button" id="fineaTourClose" class="finea-tour-btn finea-tour-btn-secondary">Fermer</button>
                    <div style="display:flex; gap:10px; margin-left:auto;">
                        <button type="button" id="fineaTourPrev" class="finea-tour-btn finea-tour-btn-secondary">Précédent</button>
                        <button type="button" id="fineaTourNext" class="finea-tour-btn finea-tour-btn-primary">Suivant</button>
                    </div>
                </div>
            `;
            document.getElementById('fineaTourClose').addEventListener('click', closeTour);
            document.getElementById('fineaTourPrev').addEventListener('click', previousStep);
            document.getElementById('fineaTourNext').addEventListener('click', nextStep);
        }

        function isVisible(el) {
            if (!el || !(el instanceof Element)) return false;
            const style = window.getComputedStyle(el);
            if (style.display === 'none' || style.visibility === 'hidden' || style.opacity === '0') return false;
            const rect = el.getBoundingClientRect();
            return rect.width > 4 && rect.height > 4;
        }

        function escapeText(value) {
            return String(value || '').replace(/[&<>'"]/g, (c) => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                "'": '&#39;',
                '"': '&quot;'
            } [c]));
        }

        function getStepFromElement(el, fallbackOrder) {
            const title = el.dataset.tourTitle || el.getAttribute('aria-label') || el.getAttribute('title') || (el.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 80) || 'Élément';
            const content = el.dataset.tourContent || el.dataset.tourDescription || 'Cette zone fait partie du fonctionnement de la page.';
            const order = Number.parseInt(el.dataset.tourOrder || '', 10);
            return {
                el,
                title,
                content,
                group: el.dataset.tourGroup || 'default',
                position: el.dataset.tourPosition || 'auto',
                order: Number.isFinite(order) ? order : fallbackOrder
            };
        }

        function collectSteps(group) {
            const explicit = Array.from(document.querySelectorAll('[data-tour="true"], [data-tour-title], [data-help-title]'));
            const steps = explicit
                .filter(isVisible)
                .map((el, idx) => getStepFromElement(el, 1000 + idx))
                .filter((step) => !group || step.group === group || step.el.dataset.tourGroup === group);

            // Fallback intelligent : si une page n'a pas encore d'attributs, on documente les zones majeures.
            if (!group || group === 'default') {
                const fallbackSelectors = [
                    ['main h1, .page-title, h1', 'Titre de la page', 'Indique le contexte courant et l’espace de travail dans lequel vous êtes.', 5],
                    ['form:not([data-tour-skip])', 'Zone de formulaire', 'Renseignez ou filtrez les informations depuis cette zone. Les champs peuvent être documentés individuellement avec data-tour.', 500],
                    ['table:not([data-tour-skip])', 'Tableau de données', 'Consultez les lignes, statuts, montants ou actions disponibles. Les boutons de ligne peuvent être ajoutés au guide avec data-tour.', 700]
                ];
                fallbackSelectors.forEach(([selector, title, content, order]) => {
                    const el = document.querySelector(selector);
                    if (el && isVisible(el) && !steps.some((s) => s.el === el)) {
                        steps.push({
                            el,
                            title,
                            content,
                            group: 'default',
                            position: 'auto',
                            order
                        });
                    }
                });
            }

            return steps.sort((a, b) => a.order - b.order);
        }

        function setMasks(rect, padding) {
            const top = document.getElementById('fineaTourMaskTop');
            const left = document.getElementById('fineaTourMaskLeft');
            const right = document.getElementById('fineaTourMaskRight');
            const bottom = document.getElementById('fineaTourMaskBottom');
            const w = window.innerWidth;
            const h = window.innerHeight;
            const x = Math.max(0, rect.left - padding);
            const y = Math.max(0, rect.top - padding);
            const rw = Math.min(w - x, rect.width + padding * 2);
            const rh = Math.min(h - y, rect.height + padding * 2);

            const configs = [
                [top, 0, 0, w, y],
                [left, 0, y, x, rh],
                [right, x + rw, y, Math.max(0, w - (x + rw)), rh],
                [bottom, 0, y + rh, w, Math.max(0, h - (y + rh))]
            ];
            configs.forEach(([el, l, t, width, height]) => {
                el.hidden = false;
                el.style.left = l + 'px';
                el.style.top = t + 'px';
                el.style.width = width + 'px';
                el.style.height = height + 'px';
            });

            const spotlight = document.getElementById('fineaTourSpotlight');
            spotlight.hidden = false;
            spotlight.style.left = x + 'px';
            spotlight.style.top = y + 'px';
            spotlight.style.width = rw + 'px';
            spotlight.style.height = rh + 'px';
            spotlight.style.borderRadius = Math.min(28, Math.max(14, parseFloat(getComputedStyle(state.activeEl).borderRadius) || 20)) + 'px';
        }

        function positionPopover(rect, desired) {
            const pop = document.getElementById('fineaTourPopover');
            pop.hidden = false;
            const margin = 16;
            const gap = 18;
            const viewportW = window.innerWidth;
            const viewportH = window.innerHeight;
            const pr = pop.getBoundingClientRect();
            const candidates = [];
            const pos = desired && desired !== 'auto' ? desired : null;
            const add = (name) => {
                if (name === 'bottom') candidates.push({
                    top: rect.bottom + gap,
                    left: rect.left + rect.width / 2 - pr.width / 2
                });
                if (name === 'top') candidates.push({
                    top: rect.top - pr.height - gap,
                    left: rect.left + rect.width / 2 - pr.width / 2
                });
                if (name === 'right') candidates.push({
                    top: rect.top + rect.height / 2 - pr.height / 2,
                    left: rect.right + gap
                });
                if (name === 'left') candidates.push({
                    top: rect.top + rect.height / 2 - pr.height / 2,
                    left: rect.left - pr.width - gap
                });
            };
            if (pos) add(pos);
            ['bottom', 'top', 'right', 'left'].forEach(add);

            let chosen = candidates.find((c) => c.top >= margin && c.left >= margin && c.top + pr.height <= viewportH - margin && c.left + pr.width <= viewportW - margin) || candidates[0];
            chosen = {
                top: Math.min(Math.max(margin, chosen.top), viewportH - pr.height - margin),
                left: Math.min(Math.max(margin, chosen.left), viewportW - pr.width - margin)
            };
            pop.style.top = chosen.top + 'px';
            pop.style.left = chosen.left + 'px';
        }

        function renderStep() {
            ensureDom();
            if (!state.steps.length) return;
            if (state.activeEl) state.activeEl.classList.remove('finea-tour-active-element');

            const step = state.steps[state.index];
            state.activeEl = step.el;
            step.el.classList.add('finea-tour-active-element');
            try {
                step.el.scrollIntoView({
                    behavior: 'smooth',
                    block: 'center',
                    inline: 'center'
                });
            } catch (e) {
                step.el.scrollIntoView(true);
            }

            window.setTimeout(() => {
                const rect = step.el.getBoundingClientRect();
                setMasks(rect, 8);
                document.getElementById('fineaTourTitle').innerHTML = escapeText(step.title);
                document.getElementById('fineaTourContent').innerHTML = escapeText(step.content);
                document.getElementById('fineaTourProgress').textContent = 'Étape ' + (state.index + 1) + ' / ' + state.steps.length;
                document.getElementById('fineaTourPrev').disabled = state.index === 0;
                document.getElementById('fineaTourNext').textContent = state.index === state.steps.length - 1 ? 'Terminer' : 'Suivant';
                positionPopover(rect, step.position);
            }, 260);
        }

        function openTour(group) {
            state.steps = collectSteps(group);
            if (!state.steps.length) {
                alert('Aucun élément de guide n’est renseigné sur cette page. Ajoutez data-tour="true" sur les composants à expliquer.');
                return;
            }
            state.index = 0;
            state.opened = true;
            document.documentElement.classList.add('overflow-hidden');
            renderStep();
        }

        function closeTour() {
            state.opened = false;
            ['Top', 'Left', 'Right', 'Bottom'].forEach((name) => {
                const el = document.getElementById('fineaTourMask' + name);
                if (el) el.hidden = true;
            });
            const spot = document.getElementById('fineaTourSpotlight');
            const pop = document.getElementById('fineaTourPopover');
            if (spot) spot.hidden = true;
            if (pop) pop.hidden = true;
            if (state.activeEl) state.activeEl.classList.remove('finea-tour-active-element');
            state.activeEl = null;
            document.documentElement.classList.remove('overflow-hidden');
        }

        function nextStep() {
            if (state.index >= state.steps.length - 1) {
                closeTour();
                return;
            }
            state.index += 1;
            renderStep();
        }

        function previousStep() {
            if (state.index <= 0) return;
            state.index -= 1;
            renderStep();
        }

        window.FineaHelpTour = {
            start: openTour,
            close: closeTour,
            refresh: function() {
                state.steps = collectSteps();
            }
        };

        document.addEventListener('keydown', function(e) {
            if (!state.opened) return;
            if (e.key === 'Escape') closeTour();
            if (e.key === 'ArrowRight') nextStep();
            if (e.key === 'ArrowLeft') previousStep();
        });

        window.addEventListener('resize', function() {
            if (!state.opened) return;
            clearTimeout(state.resizeTimer);
            state.resizeTimer = setTimeout(renderStep, 120);
        });

        window.addEventListener('scroll', function() {
            if (!state.opened) return;
            clearTimeout(state.resizeTimer);
            state.resizeTimer = setTimeout(renderStep, 90);
        }, true);

        document.addEventListener('DOMContentLoaded', function() {
            ensureDom();
            const helpBtn = document.getElementById('helpBtn');
            if (helpBtn) {
                helpBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    openTour();
                });
            }
        });
    })();

    function resolveNotifHref(raw) {
        if (!raw) return '';
        if (/^(https?:|data:|#)/i.test(raw)) return raw;
        if (raw.startsWith('/')) {
            return LAYOUT_ROOT_BASE + raw.slice(1);
        }
        return raw;
    }

    function formatNotifTime(isoOrSql) {
        const d = new Date(isoOrSql);
        if (isNaN(d.getTime())) return '';
        return d.toLocaleString('fr-FR', {
            day: '2-digit',
            month: '2-digit',
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    function setNotifBadge(count) {
        const badge = document.getElementById('notifBadge');
        const n = Number(count || 0);
        if (n > 0) {
            badge.textContent = String(n);
            badge.classList.remove('hidden');
        } else {
            badge.textContent = '0';
            badge.classList.add('hidden');
        }
    }

    // ---- Mailbox unread (léger + non bloquant) ----
    function setMailBadge(count) {
        const badge = document.getElementById('mailBadge');
        if (!badge) return;
        const n = Number(count || 0);
        if (n > 0) {
            badge.textContent = String(n);
            badge.classList.remove('hidden');
        } else {
            badge.textContent = '0';
            badge.classList.add('hidden');
        }
    }

    function setMailConnected(connected) {
        const btn = document.getElementById('mailBtn');
        const slash = document.getElementById('mailSlash');
        const icon = document.getElementById('mailIcon');
        if (!btn) return;

        if (connected) {
            if (slash) slash.classList.add('hidden');
            if (icon) {
                icon.classList.remove('text-amber-700');
                icon.classList.add('text-gray-700');
            }
            btn.title = 'Messagerie pro';
            btn.setAttribute('aria-label', 'Messagerie pro');
            return;
        }

        // Non connecté: icône barrée + incitation à connecter (clic -> mailbox_emails)
        setMailBadge(0);
        if (slash) slash.classList.remove('hidden');
        if (icon) {
            icon.classList.remove('text-gray-700');
            icon.classList.add('text-amber-700');
        }
        btn.title = 'Messagerie pro non connectée — cliquer pour connecter';
        btn.setAttribute('aria-label', 'Messagerie pro non connectée — cliquer pour connecter');
        btn.href = MAILBOX_PAGE_URL;
    }

    let mailboxPollTimer = null;
    let mailboxPollInFlight = false;
    let mailboxPollingStopped = false;

    async function pollMailboxUnreadOnce() {
        const btn = document.getElementById('mailBtn');
        if (!btn || mailboxPollingStopped || mailboxPollInFlight) return;
        mailboxPollInFlight = true;
        try {
            const url = MAILBOX_UNREAD_URL + (MAILBOX_UNREAD_URL.includes('?') ? '&' : '?') + '_=' + Date.now();
            const res = await fetch(url, {
                cache: 'no-store'
            });
            const data = await res.json();
            if (!data || !data.ok) {
                // Si l'API échoue, ne spamme pas.
                mailboxPollingStopped = true;
                setMailConnected(false);
                return;
            }

            const connected = !!data.connected;
            setMailConnected(connected);
            if (connected) {
                setMailBadge(Number(data.unreadCount || 0));
            } else {
                // Non connecté: un seul check suffit.
                mailboxPollingStopped = true;
            }
        } catch (e) {
            // En cas d'erreur réseau, on stoppe pour éviter tout impact.
            mailboxPollingStopped = true;
        } finally {
            mailboxPollInFlight = false;
        }
    }

    function startMailboxPolling() {
        const btn = document.getElementById('mailBtn');
        if (!btn) return;
        if (window.__banamurMailboxPollingStarted) return;
        window.__banamurMailboxPollingStarted = true;
        // Démarrage différé: ne ralentit pas le rendu initial.
        setTimeout(() => {
            pollMailboxUnreadOnce();
            if (!mailboxPollingStopped) {
                mailboxPollTimer = setInterval(pollMailboxUnreadOnce, 90000);
            }
        }, 1200);
    }

    function renderNotifList(items) {
        const list = document.getElementById('notifList');
        if (!Array.isArray(items) || items.length === 0) {
            list.innerHTML = '<div class="px-4 py-4 text-sm text-gray-500">Aucune notification.</div>';
            return;
        }

        list.innerHTML = items.map((n) => {
            const unread = !!n.is_unread;
            const title = (n.title || 'Notification').replace(/</g, '&lt;');
            const msg = (n.message || '').replace(/</g, '&lt;');
            const t = formatNotifTime(n.created_at);
            const href = resolveNotifHref(n.link_url || '');

            return `
                <div class="border-b"><button type="button" class="w-full text-left px-4 py-3 border-b last:border-b-0 hover:bg-gray-50 ${unread ? 'bg-indigo-50' : ''}" data-notif-id="${n.id}" data-notif-href="${href}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="text-sm font-semibold text-gray-800 truncate">${title}</div>
                            ${msg ? `<div class="text-xs text-gray-600 mt-1">${msg}</div>` : ''}
                            ${t ? `<div class="text-[11px] text-gray-500 mt-1">${t}</div>` : ''}
                        </div>
                        ${unread ? '<span class="mt-1 text-[10px] px-2 py-0.5 rounded-full bg-indigo-100 text-indigo-700">NOUVEAU</span>' : ''}
                    </div>
                </button></div>
            `;
        }).join('');

        list.querySelectorAll('button[data-notif-id]').forEach((btn) => {
            const notification=items.find(n=>Number(n.id)===Number(btn.dataset.notifId));
            if(notification&&window.FineaSpeech)window.FineaSpeech.mount(btn.parentElement,notification);
            btn.addEventListener('click', async () => {
                const id = Number(btn.getAttribute('data-notif-id'));
                const href = btn.getAttribute('data-notif-href') || '';
                try {
                    await fetch(NOTIF_MARK_READ_URL, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            ids: [id]
                        })
                    });
                } catch (e) {
                    // noop
                }
                if (href) {
                    window.location.href = href;
                }
            });
        });
    }

    function showNotifToast(notif) {
        const container = document.getElementById('notifToastContainer');
        const title = (notif.title || 'Notification').replace(/</g, '&lt;');
        const msg = (notif.message || '').replace(/</g, '&lt;');
        const href = resolveNotifHref(notif.link_url || '');

        const toast = document.createElement('div');
        toast.className = 'pointer-events-auto bg-white border border-gray-200 rounded-xl shadow-lg px-4 py-3 mb-2';
        toast.innerHTML = `
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="text-sm font-semibold text-gray-900 truncate">${title}</div>
                    ${msg ? `<div class="text-xs text-gray-700 mt-1">${msg}</div>` : ''}
                </div>
                <button type="button" class="text-gray-500 hover:text-gray-700" aria-label="Fermer">×</button>
            </div>
        `;

        const closeBtn = toast.querySelector('button');
        const remove = () => {
            if (toast.parentNode) toast.parentNode.removeChild(toast);
        };
        closeBtn.addEventListener('click', remove);
        if (href) {
            toast.addEventListener('click', (e) => {
                if (e.target === closeBtn) return;
                window.location.href = href;
            });
        }

        if(window.FineaSpeech)window.FineaSpeech.mount(toast,notif);
        container.prepend(toast);
        setTimeout(remove, 6000);
    }

    function showBrowserPush(notif) {
        if (!('Notification' in window) || Notification.permission !== 'granted') return;
        try {
            const push = new Notification(notif.title || 'Notification Finéa', {
                body: notif.message || '',
                icon: <?= json_encode($brandIconUrl !== '' ? $brandIconUrl : $brandLogoUrl, JSON_UNESCAPED_SLASHES) ?>,
                tag: 'finea-notification-' + String(notif.id || ''),
            });
            const href = resolveNotifHref(notif.link_url || '');
            push.onclick = () => { window.focus(); if (href) window.location.href = href; push.close(); };
        } catch (e) {
            // Le toast Finéa reste disponible si le navigateur bloque les notifications natives.
        }
    }

    let notifLatestId = 0;
    const shownToastIds = new Set();
    let notifAudioReady = false;
    let notifAudioElement = null;

    function unlockNotifAudio() {
        notifAudioReady = true;
        if (NOTIF_SOUND_URL && !notifAudioElement) {
            notifAudioElement = new Audio(NOTIF_SOUND_URL);
            notifAudioElement.preload = 'auto';
        }
        document.removeEventListener('click', unlockNotifAudio, true);
        document.removeEventListener('keydown', unlockNotifAudio, true);
        document.removeEventListener('touchstart', unlockNotifAudio, true);
    }

    function playDefaultNotifSound() {
        if (!notifAudioReady) return;
        try {
            const AudioCtx = window.AudioContext || window.webkitAudioContext;
            if (!AudioCtx) return;
            const audioContext = new AudioCtx();
            const oscillator = audioContext.createOscillator();
            const gain = audioContext.createGain();
            oscillator.type = 'sine';
            oscillator.frequency.setValueAtTime(820, audioContext.currentTime);
            oscillator.frequency.exponentialRampToValueAtTime(640, audioContext.currentTime + 0.16);
            gain.gain.setValueAtTime(0.001, audioContext.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.08, audioContext.currentTime + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.001, audioContext.currentTime + 0.2);
            oscillator.connect(gain);
            gain.connect(audioContext.destination);
            oscillator.start();
            oscillator.stop(audioContext.currentTime + 0.2);
            oscillator.onended = () => {
                if (typeof audioContext.close === 'function') {
                    audioContext.close().catch(() => {});
                }
            };
        } catch (e) {
            // noop
        }
    }

    function playNotifSound() {
        if (!notifAudioReady) return;
        if (NOTIF_SOUND_URL) {
            try {
                if (!notifAudioElement) {
                    notifAudioElement = new Audio(NOTIF_SOUND_URL);
                    notifAudioElement.preload = 'auto';
                }
                notifAudioElement.pause();
                notifAudioElement.currentTime = 0;
                const playPromise = notifAudioElement.play();
                if (playPromise && typeof playPromise.catch === 'function') {
                    playPromise.catch(() => {
                        playDefaultNotifSound();
                    });
                }
                return;
            } catch (e) {
                // noop
            }
        }
        playDefaultNotifSound();
    }

    async function pollNotifications(initial = false) {
        if (window.__fineaNotificationPollInFlight) return;
        window.__fineaNotificationPollInFlight = true;
        try {
            const url = new URL(NOTIF_POLL_URL, window.location.href);
            url.searchParams.set('limit', '10');
            const res = await fetch(url.toString(), {
                cache: 'no-store'
            });
            const data = await res.json();
            if (!data || !data.ok) return;

            setNotifBadge(data.unread_count || 0);

            const notifications = Array.isArray(data.notifications) ? data.notifications : [];
            if (initial) {
                renderNotifList(notifications);
                notifications.forEach((n) => shownToastIds.add(Number(n.id || 0)));
                notifLatestId = Math.max(notifLatestId, Number(data.latest_id || 0));
                return;
            }

            // nouvelles notifications pour toast
            let hasNewUnreadNotification = false;
            notifications
                .slice()
                .sort((a, b) => Number(a.id || 0) - Number(b.id || 0))
                .forEach((n) => {
                    const id = Number(n.id || 0);
                    notifLatestId = Math.max(notifLatestId, id);
                    if (id > 0 && n.is_unread && !shownToastIds.has(id)) {
                        shownToastIds.add(id);
                        hasNewUnreadNotification = true;
                        if(window.FineaSpeech)window.FineaSpeech.notify(n);
                        showNotifToast(n);
                        showBrowserPush(n);
                    }
                });

            if (hasNewUnreadNotification) {
                playNotifSound();
            }
            renderNotifList(notifications);
        } catch (e) {
            // noop
        } finally {
            window.__fineaNotificationPollInFlight = false;
        }
    }

    // UI interactions
    document.addEventListener('click', unlockNotifAudio, true);
    document.addEventListener('keydown', unlockNotifAudio, true);
    document.addEventListener('touchstart', unlockNotifAudio, true);

    document.getElementById('notifBellBtn').addEventListener('click', function() {
        document.getElementById('notifMenu').classList.toggle('hidden');
        if ('Notification' in window && Notification.permission === 'default') {
            Notification.requestPermission().catch(() => {});
        }
    });

    // Mobile burger (drawer)
    (function() {
        const btn = document.getElementById('burgerBtn');
        const menu = document.getElementById('mobileMenu');
        const overlay = document.getElementById('mobileMenuOverlay');
        const icon = document.getElementById('burgerIcon');
        const closeBtn = document.getElementById('mobileMenuCloseBtn');
        if (!btn || !menu) return;

        // Assure que l'overlay + le drawer sont au niveau <body> pour éviter tout clipping/stacking
        (function ensureDrawerInBody() {
            try {
                if (overlay && overlay.parentElement !== document.body) {
                    document.body.appendChild(overlay);
                }
                if (menu && menu.parentElement !== document.body) {
                    document.body.appendChild(menu);
                }
            } catch (e) {
                // noop
            }
        })();

        let lastActiveEl = null;
        let isOpenState = false;
        let lastOpenedAt = 0;
        let hideTimer = null;

        // Transitions inline (utile quand Tailwind CDN n'a pas encore injecté le CSS)
        try {
            if (menu && !menu.style.transition) {
                menu.style.transition = 'transform 300ms ease-out';
            }
            if (overlay && !overlay.style.transition) {
                overlay.style.transition = 'opacity 300ms ease-out';
            }
        } catch (e) {
            // noop
        }

        function getFocusableElements() {
            const focusables = menu.querySelectorAll(
                'a[href], button:not([disabled]), textarea, input, select, [tabindex]:not([tabindex="-1"])'
            );
            return Array.from(focusables).filter((el) => {
                if (!(el instanceof HTMLElement)) return false;
                const style = window.getComputedStyle(el);
                return style.visibility !== 'hidden' && style.display !== 'none';
            });
        }

        function onTrapTab(e) {
            if (!isOpenState) return;
            if (e.key !== 'Tab') return;

            const focusables = getFocusableElements();
            if (focusables.length === 0) {
                e.preventDefault();
                return;
            }

            const first = focusables[0];
            const last = focusables[focusables.length - 1];
            const active = document.activeElement;

            if (e.shiftKey) {
                if (active === first || !menu.contains(active)) {
                    e.preventDefault();
                    last.focus();
                }
            } else {
                if (active === last) {
                    e.preventDefault();
                    first.focus();
                }
            }
        }

        function preventBackgroundScroll(e) {
            if (!isOpenState) return;
            e.preventDefault();
        }

        function setOpen(open, isInitial = false) {
            isOpenState = !!open;

            if (hideTimer) {
                clearTimeout(hideTimer);
                hideTimer = null;
            }

            if (open) {
                try {
                    lastOpenedAt = (typeof performance !== 'undefined' && typeof performance.now === 'function') ? performance.now() : Date.now();
                } catch (e) {
                    lastOpenedAt = Date.now();
                }
            }
            // Menu (slide)
            menu.classList.toggle('translate-x-full', !open);
            menu.classList.toggle('translate-x-0', open);
            menu.classList.toggle('pointer-events-none', !open);
            menu.setAttribute('aria-hidden', open ? 'false' : 'true');

            // Force un état visuel même si le CSS Tailwind n'est pas encore prêt
            try {
                menu.style.visibility = 'visible';
                menu.style.transform = open ? 'translateX(0)' : 'translateX(100%)';
                menu.style.pointerEvents = open ? 'auto' : 'none';
            } catch (e) {
                // noop
            }

            // Overlay (fade)
            if (overlay) {
                overlay.classList.toggle('opacity-0', !open);
                overlay.classList.toggle('opacity-100', open);
                overlay.classList.toggle('pointer-events-none', !open);
                overlay.setAttribute('aria-hidden', open ? 'false' : 'true');

                try {
                    overlay.style.visibility = 'visible';
                    overlay.style.opacity = open ? '1' : '0';
                    overlay.style.pointerEvents = open ? 'auto' : 'none';
                } catch (e) {
                    // noop
                }
            }

            if (!open) {
                if (isInitial) {
                    try {
                        menu.style.visibility = 'hidden';
                        if (overlay) overlay.style.visibility = 'hidden';
                    } catch (e) {
                        // noop
                    }
                } else {
                    // Laisse la transition se jouer, puis cache pour éviter tout flash (FOUC)
                    hideTimer = setTimeout(() => {
                        if (isOpenState) return;
                        try {
                            menu.style.visibility = 'hidden';
                            if (overlay) overlay.style.visibility = 'hidden';
                        } catch (e) {
                            // noop
                        }
                    }, 320);
                }
            }

            document.documentElement.classList.toggle('overflow-hidden', open);
            document.body.classList.toggle('overflow-hidden', open);
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (icon) {
                icon.className = open ? 'fa-solid fa-xmark' : 'fa-solid fa-bars';
            }

            // Focus management + scroll capture
            if (open) {
                lastActiveEl = document.activeElement;
                document.addEventListener('keydown', onTrapTab, true);
                if (overlay) {
                    overlay.addEventListener('wheel', preventBackgroundScroll, {
                        passive: false
                    });
                    overlay.addEventListener('touchmove', preventBackgroundScroll, {
                        passive: false
                    });
                }
                if (!isInitial) {
                    window.setTimeout(() => {
                        if (closeBtn && typeof closeBtn.focus === 'function') {
                            closeBtn.focus();
                        } else if (typeof menu.focus === 'function') {
                            menu.focus();
                        }
                    }, 0);
                }
            } else {
                document.removeEventListener('keydown', onTrapTab, true);
                if (overlay) {
                    overlay.removeEventListener('wheel', preventBackgroundScroll);
                    overlay.removeEventListener('touchmove', preventBackgroundScroll);
                }
                if (!isInitial && lastActiveEl && typeof lastActiveEl.focus === 'function') {
                    lastActiveEl.focus();
                }
                lastActiveEl = null;
            }
        }

        btn.addEventListener('click', function() {
            setOpen(!isOpenState);
        });

        document.querySelectorAll('[data-nav-close-mobile]').forEach((el) => {
            el.addEventListener('click', (e) => {
                // Sur certains navigateurs mobiles, le clic initial peut « tomber » sur l'overlay juste après l'ouverture.
                if (overlay && el === overlay && isOpenState) {
                    let now = 0;
                    try {
                        now = (typeof performance !== 'undefined' && typeof performance.now === 'function') ? performance.now() : Date.now();
                    } catch (err) {
                        now = Date.now();
                    }
                    if (now - lastOpenedAt < 250) {
                        e.preventDefault();
                        e.stopPropagation();
                        return;
                    }
                }
                setOpen(false);
            });
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                setOpen(false);
            }
        });

        window.addEventListener('resize', function() {
            if (window.innerWidth >= 768) {
                setOpen(false);
            }
        });

        // Anti bfcache: si l'utilisateur revient en arrière et que le DOM est restauré,
        // on force la fermeture pour éviter une "ouverture automatique".
        window.addEventListener('pageshow', function() {
            setOpen(false, true);
        });

        window.addEventListener('pagehide', function() {
            setOpen(false, true);
        });

        // État initial : fermé (garantit que les classes sont cohérentes même si le HTML a été modifié ailleurs)
        setOpen(false, true);
    })();

    document.getElementById('notifMarkAllBtn').addEventListener('click', async function() {
        try {
            await fetch(NOTIF_MARK_READ_URL, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    mark_all: 1
                })
            });
        } catch (e) {
            // noop
        }
        await pollNotifications(true);
    });

    document.addEventListener('click', function(e) {
        const bellBtn = document.getElementById('notifBellBtn');
        const notifMenu = document.getElementById('notifMenu');
        if (bellBtn && notifMenu && !bellBtn.contains(e.target) && !notifMenu.contains(e.target)) {
            notifMenu.classList.add('hidden');
        }
    });

    document.getElementById('userMenuBtn').addEventListener('click', function() {
        document.getElementById('userMenu').classList.toggle('hidden');
    });

    document.addEventListener('click', function(e) {
        if (!document.getElementById('userMenuBtn').contains(e.target)) {
            document.getElementById('userMenu').classList.add('hidden');
        }
    });

    // Init polling
    if (window.__fineaNotificationPollTimer) clearInterval(window.__fineaNotificationPollTimer);
    pollNotifications(true);
    window.__fineaNotificationPollTimer = setInterval(() => {
        if (!document.hidden) pollNotifications(false);
    }, 45000);
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) pollNotifications(false);
    });

    startMailboxPolling();
</script>

