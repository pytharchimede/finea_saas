<?php
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/AccessManager.php';
require_once __DIR__ . '/../model/PortalModuleManager.php';
require_once __DIR__ . '/../model/SearchEngineKnowledgeManager.php';
require_once __DIR__ . '/../model/Client.php';
require_once __DIR__ . '/../model/Chantier.php';
require_once __DIR__ . '/../model/Devis.php';
require_once __DIR__ . '/../repositories/FournisseurRepository.php';

header('Content-Type: application/json; charset=UTF-8');

function portalSearchNormalize(string $value): string
{
    $value = mb_strtolower(trim($value), 'UTF-8');
    $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

    return $transliterated !== false ? strtolower($transliterated) : $value;
}

function portalSearchStopWords(): array
{
    return [
        'a', 'au', 'aux', 'avec', 'ce', 'ces', 'dans', 'de', 'des', 'du', 'en',
        'et', 'est', 'sont', 'cet', 'cette', 'la', 'le', 'les', 'ma', 'mes', 'moi', 'mon', 'montre', 'montres',
        'nous', 'ouvre', 'ouvrir', 'par', 'pour', 'que', 'quel', 'quelle', 'quels',
        'qui', 'sur', 'un', 'une', 'vos', 'votre', 'je', 'veux', 'voir', 'affiche',
        'afficher', 'trouve', 'trouver', 'donne', 'aujourd', 'hui', 'actuel',
        'actuelle', 'semaine', 'jour', 'mois', 'annee',
    ];
}

function portalSearchSynonymGroups(): array
{
    return [
        ['tresorerie', 'tresor', 'cash', 'argent', 'liquidite', 'liquidites', 'solde', 'banque', 'caisse'],
        ['decaissement', 'decaissements', 'paiement', 'paiements', 'reglement', 'reglements', 'depense', 'depenses', 'sortie', 'sorties', 'avance', 'avances', 'acompte', 'acomptes', 'amortissement', 'prorata'],
        ['regularisation', 'regulariser', 'completion', 'completer', 'normalisation', 'normaliser', 'pv', 'reception', 'preuve', 'recu', 'justificatif', 'signature', 'signataire', 'po'],
        ['comptabilite', 'comptable', 'comptables', 'compta', 'ecriture', 'ecritures', 'journal', 'journaux', 'grand-livre'],
        ['paie', 'paye', 'payroll', 'salaire', 'salaires', 'remuneration', 'remunerations', 'bulletin', 'bulletins'],
        ['rh', 'ressources', 'humaines', 'personnel', 'employe', 'employes', 'collaborateur', 'collaborateurs', 'salarie', 'salaries'],
        ['fournisseur', 'fournisseurs', 'prestataire', 'prestataires', 'vendor'],
        ['client', 'clients', 'customer', 'customers'],
        ['devis', 'cotation', 'cotations', 'offre', 'offres', 'quotation'],
        ['chantier', 'chantiers', 'projet', 'projets', 'site', 'sites'],
        ['facture', 'factures', 'invoice', 'invoices', 'facturation'],
        ['vehicule', 'vehicules', 'voiture', 'voitures', 'engin', 'engins', 'flotte', 'asset', 'assets'],
        ['materiel', 'materiels', 'equipement', 'equipements', 'stock', 'stocks', 'article', 'articles'],
        ['ticket', 'tickets', 'incident', 'incidents', 'assistance', 'support', 'demande', 'demandes'],
        ['document', 'documents', 'fichier', 'fichiers', 'piece', 'pieces', 'archive', 'archives', 'pdf', 'image', 'images', 'photo', 'photos', 'scan', 'capture'],
        ['contrat', 'contrats', 'contract', 'contracts', 'engagement'],
        ['formation', 'formations', 'cours', 'academy', 'apprentissage'],
        ['notification', 'notifications', 'alerte', 'alertes', 'message', 'messages'],
        ['parametre', 'parametres', 'configuration', 'configurations', 'reglage', 'reglages', 'settings'],
        ['rapport', 'rapports', 'reporting', 'statistique', 'statistiques', 'analyse', 'analyses'],
        ['dashboard', 'dashboards', 'tableau', 'tableaux', 'bord', 'pilotage', 'indicateur', 'indicateurs'],
        ['utilisateur', 'utilisateurs', 'user', 'users', 'compte', 'comptes'],
        ['role', 'roles', 'profil', 'profils', 'permission', 'permissions', 'acces', 'habilitation', 'habilitations'],
        ['feb', 'besoin', 'besoins', 'expression', 'approvisionnement', 'achat', 'achats'],
    ];
}

function portalSearchTokenVariants(string $token): array
{
    $token = portalSearchNormalize($token);
    $variants = [$token];
    foreach (portalSearchSynonymGroups() as $group) {
        if (in_array($token, $group, true)) {
            $variants = array_merge($variants, $group);
        }
    }

    if (mb_strlen($token, 'UTF-8') > 4) {
        if (str_ends_with($token, 'es')) {
            $variants[] = substr($token, 0, -2);
        }
        if (str_ends_with($token, 's')) {
            $variants[] = substr($token, 0, -1);
        }
    }

    return array_values(array_unique(array_filter($variants)));
}

function portalSearchTokenFuzzyMatches(string $token, array $haystackWords): bool
{
    $length = strlen($token);
    if ($length < 5) {
        return false;
    }
    $maximumDistance = $length >= 8 ? 2 : 1;
    foreach ($haystackWords as $word) {
        if (abs(strlen($word) - $length) > $maximumDistance) {
            continue;
        }
        if (levenshtein($token, $word) <= $maximumDistance) {
            return true;
        }
    }

    return false;
}

function portalSearchMeaningfulTokens(string $query): array
{
    $tokens = array_values(array_filter(preg_split('/[^a-z0-9]+/', portalSearchNormalize($query)) ?: []));
    $stopWords = portalSearchStopWords();
    return array_values(array_filter($tokens, static function (string $token) use ($stopWords): bool {
        return strlen($token) >= 2 && !in_array($token, $stopWords, true);
    }));
}

function portalSearchDataQuery(string $query): string
{
    $tokens = portalSearchMeaningfulTokens($query);
    $interpretation = portalSearchInterpretation($query);
    if (count($tokens) === 1 && $interpretation !== '' && $interpretation !== $tokens[0]) {
        return $interpretation;
    }
    $domainWords = [];
    foreach (portalSearchSynonymGroups() as $group) {
        $domainWords = array_merge($domainWords, $group);
    }
    $domainWords = array_merge($domainWords, [
        'dernier', 'derniere', 'derniers', 'dernieres', 'responsable', 'semaine',
        'semaine-ci', 'aujourd', 'hui', 'actuel', 'actuelle',
    ]);
    $specificTokens = array_values(array_filter($tokens, static function (string $token) use ($domainWords): bool {
        return !in_array($token, $domainWords, true);
    }));

    return $specificTokens !== [] ? implode(' ', $specificTokens) : $interpretation;
}

function portalSearchInterpretation(string $query): string
{
    $interpreted = [];
    foreach (portalSearchMeaningfulTokens($query) as $token) {
        $canonical = $token;
        foreach (portalSearchSynonymGroups() as $group) {
            if (in_array($token, $group, true)) {
                $canonical = $group[0];
                break;
            }
        }
        if ($canonical === $token && strlen($token) >= 5) {
            $bestDistance = strlen($token) >= 8 ? 2 : 1;
            foreach (portalSearchSynonymGroups() as $group) {
                foreach ($group as $knownWord) {
                    if (abs(strlen($knownWord) - strlen($token)) > $bestDistance) {
                        continue;
                    }
                    $distance = levenshtein($token, $knownWord);
                    if ($distance <= $bestDistance) {
                        $canonical = $group[0];
                        $bestDistance = $distance - 1;
                    }
                }
            }
        }
        $interpreted[] = $canonical;
    }

    return implode(' ', array_values(array_unique($interpreted)));
}

function portalSearchMatches(string $query, array $parts): bool
{
    $queryTokens = portalSearchMeaningfulTokens($query);
    if ($queryTokens === []) {
        return false;
    }

    $haystack = portalSearchNormalize(implode(' ', array_filter(array_map(static function ($part): string {
        return is_scalar($part) ? (string) $part : '';
    }, $parts))));

    $haystackWords = array_values(array_unique(array_filter(preg_split('/[^a-z0-9]+/', $haystack) ?: [])));
    foreach ($queryTokens as $token) {
        $matched = false;
        foreach (portalSearchTokenVariants($token) as $variant) {
            if (strpos($haystack, $variant) !== false || portalSearchTokenFuzzyMatches($variant, $haystackWords)) {
                $matched = true;
                break;
            }
        }
        if (!$matched) {
            return false;
        }
    }

    return true;
}

function portalCanAccessModule(array $module, array $permissions): bool
{
    $requiredPermissions = $module['required_permissions'] ?? [];
    if (!is_array($requiredPermissions) || $requiredPermissions === []) {
        return true;
    }

    foreach ($requiredPermissions as $permission) {
        if (is_string($permission) && $permission !== '' && !empty($permissions[$permission])) {
            return true;
        }
    }

    return false;
}

function portalIsSearchablePage(string $page): bool
{
    $page = str_replace('\\', '/', trim($page));
    if ($page === '' || substr($page, -4) !== '.php') {
        return false;
    }

    $lowerPage = strtolower($page);
    $excludedPrefixes = ['api/', 'request/', 'cron/', 'tools/', 'docs/'];
    foreach ($excludedPrefixes as $excludedPrefix) {
        if (strpos($lowerPage, $excludedPrefix) === 0) {
            return false;
        }
    }

    $excludedNames = [
        'auth_check.php',
        'deconnex.php',
        'tracking_pixel.php',
        'temp_tbody.php',
        '__tmp_bulk_groups_verify.php',
        'regularisation_decaissement.php',
    ];

    return !in_array(basename($lowerPage), $excludedNames, true);
}

function portalPageLabel(string $page): string
{
    static $labels = [
        'selection_portail.php' => 'Portail applicatif',
        'dashboard.php' => 'Tableau de bord',
        'liste_client.php' => 'Liste des clients',
        'liste_devis.php' => 'Liste des devis',
        'liste_offre.php' => 'Liste des offres',
        'liste_chantier.php' => 'Liste des chantiers',
        'liste_facture.php' => 'Liste des factures',
        'liste_utilisateur.php' => 'Liste des utilisateurs',
        'profil.php' => 'Mon profil',
        'documentation.php' => 'Documentation',
        'emploi_du_temps/index.php' => 'Emploi du temps',
        'formation/index.php' => 'Formation',
        'tresorerie_chantiers.php' => 'Trésorerie en temps réel',
        'liste_fiches_compta.php' => 'Traitement comptable des décaissements',
        'regularisations_decaissements.php' => 'Régularisation des décaissements',
        'regularisation_decaissement.php' => 'PV et preuve de paiement d’un décaissement',
        'formulaire_demande_decaissement.php' => 'Créer une fiche de décaissement',
        'nouvelle_demande_employe.php' => 'Créer une demande employé ou une FEB',
        'factures_vente.php' => 'Créer et gérer les factures de vente',
        'import_factures_vente_fne.php' => 'Importer les factures de vente FNE',
        'factures_achat.php' => 'Créer et gérer les factures d’achat',
        'import_factures_achat_fne.php' => 'Importer les factures d’achat FNE',
        'generer_devis.php' => 'Créer un devis',
        'ajouter_utilisateur.php' => 'Créer un utilisateur',
    ];

    $baseName = basename($page);
    if (isset($labels[$baseName])) {
        return $labels[$baseName];
    }

    $name = preg_replace('/\.php$/i', '', $baseName) ?: $baseName;
    $name = str_replace(['_', '-'], ' ', $name);

    return mb_convert_case($name, MB_CASE_TITLE, 'UTF-8');
}

function portalIsFormPage(string $page): bool
{
    $name = portalSearchNormalize(basename($page));
    foreach (['formulaire', 'nouveau', 'nouvelle', 'ajouter', 'generer', 'creer', 'edit', 'modifier', 'integration', 'task_form'] as $marker) {
        if (strpos($name, $marker) !== false) {
            return true;
        }
    }

    return in_array(basename($page), ['factures_vente.php', 'factures_achat.php'], true);
}

function portalHasRequiredPermission($requirement): bool
{
    $requiredPermissions = is_array($requirement) ? $requirement : [$requirement];
    foreach ($requiredPermissions as $permission) {
        if (is_string($permission) && $permission !== '' && \AccessManager::hasPermission($permission)) {
            return true;
        }
    }

    return false;
}

function portalCanAccessPagePath(string $page): bool
{
    $page = trim(str_replace('\\', '/', $page), '/');
    $baseName = basename($page);
    $configuredPermissions = \AccessManager::PAGE_PERMISSIONS[$page]
        ?? \AccessManager::PAGE_PERMISSIONS[$baseName]
        ?? null;

    if ($configuredPermissions !== null) {
        return portalHasRequiredPermission($configuredPermissions);
    }

    $modulePermissions = [
        'admin/' => ['acces_admin'],
        'employe/' => ['acces_employe', 'acces_admin'],
        'rh/' => ['acces_rh', 'acces_direction', 'acces_admin'],
        'logi/' => ['acces_logistique', 'acces_admin'],
        'facturation/' => ['acces_facturation', 'acces_direction', 'acces_admin'],
        'fournisseur/' => ['acces_fournisseur', 'acces_compta', 'acces_admin', 'acces_employe'],
        'crm/' => ['acces_crm', 'acces_crm_dashboard', 'acces_crm_prospects', 'acces_crm_clients', 'acces_crm_opportunites', 'acces_crm_rdv', 'acces_admin'],
        'planning_chantier/' => ['acces_planning_chantier', 'acces_planning', 'acces_liste_chantier', 'acces_direction', 'acces_admin'],
        'documentation/' => ['acces_documentation', 'acces_admin'],
        'formation/' => ['acces_formation', 'formation_admin', 'acces_admin'],
        'recurring_needs/' => ['acces_admin'],
    ];
    foreach ($modulePermissions as $prefix => $permissions) {
        if (strpos($page, $prefix) === 0) {
            return portalHasRequiredPermission($permissions);
        }
    }

    return \AccessManager::hasPageAccess($baseName);
}

function portalDiscoverFormPages(string $root): array
{
    $excludedSegments = [
        '/api/', '/request/', '/cron/', '/tools/', '/tests/', '/test/',
        '/vendor/', '/node_modules/', '/model/', '/models/', '/repositories/',
        '/services/', '/includes/', '/partials/', '/headers/', '/views/',
        '/uploads/', '/storage/', '/phpqrcode/', '/_references_fournies/',
    ];
    $excludedFiles = [
        'login.php', 'reset_password.php', 'reset_password_set.php',
        'moteur.php', 'auth_check.php',
    ];
    $pages = [];
    $directory = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
    $filter = new RecursiveCallbackFilterIterator($directory, static function (SplFileInfo $file) use ($excludedSegments): bool {
        $path = '/' . strtolower(str_replace('\\', '/', $file->getPathname()));
        if ($file->isDir()) {
            if (!$file->isReadable()) {
                return false;
            }
            foreach ($excludedSegments as $segment) {
                if (strpos($path . '/', $segment) !== false) {
                    return false;
                }
            }
        }
        return true;
    });
    $iterator = new RecursiveIteratorIterator($filter);

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile() || strtolower($fileInfo->getExtension()) !== 'php') {
            continue;
        }

        $absolutePath = str_replace('\\', '/', $fileInfo->getPathname());
        $relativePath = ltrim(substr($absolutePath, strlen(rtrim(str_replace('\\', '/', $root), '/'))), '/');
        $wrappedPath = '/' . strtolower($relativePath);
        $excluded = in_array(strtolower(basename($relativePath)), $excludedFiles, true);
        foreach ($excludedSegments as $segment) {
            if (strpos($wrappedPath, $segment) !== false) {
                $excluded = true;
                break;
            }
        }
        if ($excluded || !portalIsSearchablePage($relativePath)) {
            continue;
        }

        $contents = @file_get_contents($absolutePath);
        if (!is_string($contents)) {
            continue;
        }
        if (stripos($contents, '<form') === false && !portalIsFormPage($relativePath)) {
            continue;
        }

        $pages[$relativePath] = $relativePath;
    }

    ksort($pages, SORT_NATURAL | SORT_FLAG_CASE);
    return $pages;
}

function portalPageFormTerms(string $page): string
{
    static $cache = [];
    if (array_key_exists($page, $cache)) {
        return $cache[$page];
    }

    $absolutePath = dirname(__DIR__) . '/' . ltrim(str_replace('\\', '/', $page), '/');
    if (!is_file($absolutePath)) {
        return $cache[$page] = '';
    }

    $contents = @file_get_contents($absolutePath);
    if (!is_string($contents)) {
        return $cache[$page] = '';
    }

    $terms = [];
    $patterns = [
        '/\\$pageTitle\\s*=\\s*[\'"]([^\'"]+)[\'"]/iu',
        '/<(?:title|h1|h2|h3|label|legend|button)\\b[^>]*>(.*?)<\\/(?:title|h1|h2|h3|label|legend|button)>/isu',
        '/\\bplaceholder\\s*=\\s*[\'"]([^\'"]+)[\'"]/iu',
        '/\\baria-label\\s*=\\s*[\'"]([^\'"]+)[\'"]/iu',
    ];
    foreach ($patterns as $pattern) {
        if (!preg_match_all($pattern, $contents, $matches)) {
            continue;
        }
        foreach ($matches[1] ?? [] as $match) {
            $text = html_entity_decode(strip_tags((string) $match), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = preg_replace('/<\\?=.*?\\?>/su', ' ', $text) ?? $text;
            $text = preg_replace('/\\s+/u', ' ', $text) ?? $text;
            $text = trim($text);
            if ($text !== '') {
                $terms[] = $text;
            }
        }
    }

    return $cache[$page] = implode(' ', array_unique($terms));
}

function portalPageSearchTerms(string $page, string $label, string $category): array
{
    $terms = [$page, $label, $category, portalPageFormTerms($page)];
    if (portalIsFormPage($page)) {
        $terms[] = 'formulaire saisie creation créer ajouter modifier renseigner nouvelle demande';
    }

    if (basename($page) === 'tresorerie_chantiers.php') {
        $terms[] = 'tresorerie temps reel solde disponible entree sortie prevision encaissement depense dg';
    }
    if (basename($page) === 'liste_fiches_compta.php') {
        $terms[] = 'traitement comptable caisse decaissement fiche paiement validation';
    }
    if (in_array(basename($page), ['regularisations_decaissements.php', 'regularisation_decaissement.php'], true)) {
        $terms[] = 'regularisation normalisation completion decaissement decaisse feb pv proces verbal reception execution travaux fourniture prestation preuve paiement recu wave orange money cheque cash decharge signature demandeur validation comptable direction avancement progression pondere ligne effectue termine restant attente observation';
    }
    if (basename($page) === 'formulaire_expression_besoin.php' || basename($page) === 'nouvelle_demande_employe.php') {
        $terms[] = 'feb fiche expression besoin formulaire achat approvisionnement demande finance';
    }

    return $terms;
}

function portalPageCategory(string $page): string
{
    $normalized = str_replace('\\', '/', trim($page, '/'));
    if (strpos($normalized, '/') === false) {
        return 'Page interne';
    }

    $prefix = strtok($normalized, '/');
    $categories = [
        'admin' => 'Administration',
        'crm' => 'CRM',
        'decaissement' => 'Décaissement',
        'employe' => 'Employés',
        'facturation' => 'Facturation',
        'fournisseur' => 'Fournisseurs',
        'rh' => 'Ressources humaines',
        'logi' => 'Logistique',
        'planning_chantier' => 'Planning chantier',
        'recurring_needs' => 'Besoins récurrents',
    ];

    return $categories[$prefix] ?? 'Page interne';
}

function portalResolvePageTarget(string $page): ?string
{
    $page = str_replace('\\', '/', trim($page));
    if ($page === '') {
        return null;
    }

    static $specialTargets = [
        'dashboard_employe.php' => 'employe/dashboard_employe.php',
        'pointage_employe.php' => 'employe/pointage_employe.php',
        'actes_legaux_employe.php' => 'employe/actes_legaux_employe.php',
        'nouvelle_demande_employe.php' => 'employe/nouvelle_demande_employe.php?type=permission_absence',
        'mes_demandes_employe.php' => 'employe/mes_demandes_employe.php',
        'mes_explications_employe.php' => 'employe/mes_explications_employe.php',
        'mes_decaissements_employe.php' => 'employe/mes_decaissements_employe.php',
        'details_decaissement_employe.php' => 'employe/mes_decaissements_employe.php',
        'mes_fournisseurs_employe.php' => 'employe/mes_fournisseurs_employe.php',
        'edit_fournisseur_employe.php' => 'employe/mes_fournisseurs_employe.php',
        'dashboard_fournisseur.php' => 'fournisseur/dashboard_fournisseur.php',
        'fournisseurs.php' => 'fournisseur/fournisseurs.php',
        'categories.php' => 'fournisseur/categories.php',
        'edit_fournisseur.php' => 'fournisseur/fournisseurs.php',
        'ajouter_fournisseur.php' => 'fournisseur/ajouter_fournisseur.php',
    ];

    if (isset($specialTargets[$page])) {
        return $specialTargets[$page];
    }

    if (strpos($page, '/') !== false) {
        return is_file(__DIR__ . '/../' . $page) ? $page : null;
    }

    if (is_file(__DIR__ . '/../' . $page)) {
        return $page;
    }

    $directories = ['employe', 'fournisseur', 'admin', 'crm', 'facturation', 'decaissement', 'rh'];
    foreach ($directories as $directory) {
        $candidate = $directory . '/' . $page;
        if (is_file(__DIR__ . '/../' . $candidate)) {
            return $candidate;
        }
    }

    return null;
}

function portalPageSubtitle(string $page, string $target): string
{
    static $subtitles = [
        'dashboard.php' => 'Vue d ensemble des indicateurs et accès rapides',
        'liste_client.php' => 'Parcourir, rechercher et gérer le portefeuille client',
        'liste_devis.php' => 'Consulter les devis, validations et filtres métier',
        'liste_chantier.php' => 'Suivre les chantiers actifs, archivés et leurs détails',
        'liste_offre.php' => 'Consulter les offres commerciales disponibles',
        'liste_facture.php' => 'Accéder aux factures et au suivi de facturation',
        'profil.php' => 'Consulter vos informations personnelles',
        'documentation.php' => 'Guides, procédures et ressources d utilisation',
        'emploi_du_temps/index.php' => 'Organiser le planning et les disponibilités',
        'formation/index.php' => 'Ouvrir les contenus de formation et tutoriels',
        'tresorerie_chantiers.php' => 'Consulter en temps réel les encaissements, dépenses, prévisions et ressources disponibles',
        'liste_fiches_compta.php' => 'Traiter les fiches de décaissement arrivées à l’étape comptable',
        'regularisations_decaissements.php' => 'Suivre les PV signés, preuves de paiement et validations comptables après décaissement',
        'regularisation_decaissement.php' => 'Renseigner ou contrôler le PV de réception et la preuve de paiement d’une fiche décaissée',
        'formulaire_demande_decaissement.php' => 'Saisir une nouvelle fiche de décaissement et son bénéficiaire',
        'nouvelle_demande_employe.php' => 'Choisir puis renseigner une demande RH, financière ou une FEB',
        'factures_vente.php' => 'Saisir une facture de vente et ses informations d’encaissement',
        'import_factures_vente_fne.php' => 'Importer, contrôler et rapprocher un lot de factures de vente issu de la plateforme FNE',
        'factures_achat.php' => 'Saisir une facture d’achat et ses pièces',
        'import_factures_achat_fne.php' => 'Importer, dédoublonner et rapprocher les factures fournisseurs FNE avec leur TVA et leur chantier',
        'generer_devis.php' => 'Renseigner et créer un nouveau devis',
        'dashboard_employe.php' => 'Accueil employé avec vos actions principales',
        'pointage_employe.php' => 'Suivre présence, périodes et justificatifs',
        'actes_legaux_employe.php' => 'Choisir un type de demande légale ou RH',
        'mes_demandes_employe.php' => 'Suivre vos demandes et leurs statuts',
        'mes_explications_employe.php' => 'Retrouver vos demandes d explication',
        'mes_decaissements_employe.php' => 'Suivre vos décaissements et leurs étapes',
        'mes_fournisseurs_employe.php' => 'Gérer vos fournisseurs côté employé',
        'dashboard_fournisseur.php' => 'Vue de pilotage du module fournisseurs',
        'fournisseurs.php' => 'Référentiel, validation et suivi des fournisseurs',
        'categories.php' => 'Structurer les catégories fournisseurs',
        'ajouter_fournisseur.php' => 'Créer une nouvelle fiche fournisseur',
    ];

    return $subtitles[$page] ?? ('Accès direct vers ' . portalPageLabel($page));
}

function portalClientSubtitle(array $client): string
{
    return trim(implode(' · ', array_filter([
        !empty($client['code_client']) ? ('Code ' . (string) $client['code_client']) : '',
        !empty($client['telephone_client']) ? ('Tél ' . (string) $client['telephone_client']) : '',
        !empty($client['localisation_client']) ? ('Localisation ' . (string) $client['localisation_client']) : '',
    ])));
}

function portalSupplierSubtitle(array $supplier): string
{
    return trim(implode(' · ', array_filter([
        !empty($supplier['code_fournisseur']) ? ('Code ' . (string) $supplier['code_fournisseur']) : '',
        !empty($supplier['contact_nom']) ? ('Contact ' . (string) $supplier['contact_nom']) : '',
        !empty($supplier['telephone']) ? ('Tél ' . (string) $supplier['telephone']) : '',
    ])));
}

function portalChantierSubtitle(array $chantier): string
{
    return trim(implode(' · ', array_filter([
        !empty($chantier['code']) ? ('Code ' . (string) $chantier['code']) : '',
        !empty($chantier['client_nom']) ? ('Client ' . (string) $chantier['client_nom']) : '',
        !empty($chantier['devis_numero']) ? ('Devis ' . (string) $chantier['devis_numero']) : '',
    ])));
}

function portalDevisSubtitle(array $devis): string
{
    $status = '';
    if (!empty($devis['validation_technique']) && !empty($devis['validation_generale'])) {
        $status = 'Entièrement validé';
    } elseif (!empty($devis['validation_technique'])) {
        $status = 'Validation technique OK';
    } elseif (!empty($devis['validation_generale'])) {
        $status = 'Validation générale OK';
    }

    return trim(implode(' · ', array_filter([
        !empty($devis['correspondant']) ? ('Rédacteur ' . (string) $devis['correspondant']) : '',
        $status,
    ])));
}

function portalPushResult(array &$results, array &$typeCounters, string $type, array $result, int $typeLimit): void
{
    $typeCounters[$type] = (int) ($typeCounters[$type] ?? 0);
    if ($typeCounters[$type] >= $typeLimit) {
        return;
    }

    $results[] = $result;
    $typeCounters[$type]++;
}

try {
    $query = trim((string) ($_GET['q'] ?? ''));
    if (mb_strlen($query, 'UTF-8') < 2) {
        echo json_encode(['ok' => true, 'results' => []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    $dataQuery = portalSearchDataQuery($query);

    $pdo = \Database::getConnection();
    $permissions = is_array($_SESSION['permissions'] ?? null) ? $_SESSION['permissions'] : [];

    try {
        $portalModuleManager = new \PortalModuleManager($pdo);
        $modules = $portalModuleManager->listActive();
    } catch (Throwable $exception) {
        $modules = \PortalModuleManager::defaultModules();
    }

    $results = [];
    $typeCounters = [
        'module' => 0,
        'page' => 0,
        'form' => 0,
        'client' => 0,
        'supplier' => 0,
        'chantier' => 0,
        'devis' => 0,
        'request' => 0,
        'document' => 0,
        'person' => 0,
        'ticket' => 0,
        'location' => 0,
        'invoice' => 0,
        'user' => 0,
        'role' => 0,
        'asset' => 0,
        'stock' => 0,
        'contract' => 0,
    ];

    try {
        $knowledgeManager = new \SearchEngineKnowledgeManager($pdo, dirname(__DIR__));
        $knowledgeManager->syncIfStale(300);
        foreach ($knowledgeManager->listActive() as $knowledge) {
            $requiredPermissions = (array) ($knowledge['required_permissions'] ?? []);
            if ($requiredPermissions !== [] && !portalHasRequiredPermission($requiredPermissions)) {
                continue;
            }
            if (!portalSearchMatches($query, [
                $knowledge['label'] ?? '',
                $knowledge['target'] ?? '',
                $knowledge['description'] ?? '',
                $knowledge['auto_keywords'] ?? '',
                $knowledge['keywords'] ?? '',
                $knowledge['synonyms'] ?? '',
                $knowledge['source_type'] ?? '',
                $knowledge['result_type'] ?? '',
            ])) {
                continue;
            }
            $knowledgeType = (string) ($knowledge['result_type'] ?? 'page');
            portalPushResult($results, $typeCounters, $knowledgeType, [
                'type' => $knowledgeType,
                'id' => 'knowledge-' . (int) ($knowledge['id'] ?? 0),
                'label' => (string) ($knowledge['label'] ?? 'Ressource Finéa'),
                'subtitle' => trim((string) ($knowledge['description'] ?? '')) ?: 'Résultat enrichi par le catalogue du moteur',
                'target' => (string) ($knowledge['target'] ?? ''),
                'meta' => !empty($knowledge['is_system']) ? 'Index intelligent' : 'Apprentissage',
            ], 300);
        }
    } catch (Throwable $ignored) {
        // Le catalogue enrichi ne doit jamais bloquer les autres sources de recherche.
    }

    foreach ($modules as $module) {
        if (!portalCanAccessModule($module, $permissions)) {
            continue;
        }

        $label = (string) ($module['label'] ?? '');
        $subtitle = (string) ($module['subtitle'] ?? '');
        $target = (string) ($module['target'] ?? '');
        if (!portalSearchMatches($query, [$module['module_key'] ?? '', $label, $subtitle, $target, $module['slug'] ?? ''])) {
            continue;
        }

        portalPushResult($results, $typeCounters, 'module', [
            'type' => 'module',
            'id' => (string) ($module['module_key'] ?? ''),
            'label' => $label,
            'subtitle' => $subtitle !== '' ? $subtitle : 'Module',
            'target' => $target,
            'meta' => 'Module',
        ], 6);

        if ($typeCounters['module'] >= 6) {
            break;
        }
    }

    $employeeForms = [
        'permission_absence' => [
            'label' => 'Formulaire de permission d’absence',
            'subtitle' => 'Demander une autorisation d’absence sur une période définie',
            'keywords' => 'absence permission autorisation indisponibilite formulaire fiche demande rh',
        ],
        'conge' => [
            'label' => 'Formulaire de demande de congé',
            'subtitle' => 'Soumettre une demande de congé à la validation RH',
            'keywords' => 'conge vacances repos absence formulaire fiche demande rh',
        ],
        'retard' => [
            'label' => 'Formulaire de justification de retard',
            'subtitle' => 'Déclarer ou justifier un retard',
            'keywords' => 'retard arrivee justification formulaire fiche demande rh',
        ],
        'pret' => [
            'label' => 'Formulaire de demande de prêt',
            'subtitle' => 'Soumettre une demande de prêt employé',
            'keywords' => 'pret argent finance formulaire fiche demande employe',
        ],
        'avance_salaire' => [
            'label' => 'Formulaire de demande d’avance sur salaire',
            'subtitle' => 'Demander une avance sur salaire',
            'keywords' => 'avance salaire finance paie formulaire fiche demande employe',
        ],
        'heures_supp' => [
            'label' => 'Formulaire d’heures supplémentaires',
            'subtitle' => 'Déclarer une demande liée aux heures supplémentaires',
            'keywords' => 'heure heures supplementaires travail formulaire fiche demande rh',
        ],
        'reclamation_salariale' => [
            'label' => 'Formulaire de réclamation salariale',
            'subtitle' => 'Transmettre une réclamation concernant le salaire',
            'keywords' => 'reclamation salaire paie bulletin formulaire fiche demande rh',
        ],
        'feb' => [
            'label' => 'FEB — Fiche d’expression de besoin',
            'subtitle' => 'Créer une fiche d’expression de besoin, d’achat ou d’approvisionnement',
            'keywords' => 'feb fiche expression besoin achat approvisionnement devis formulaire demande finance',
        ],
    ];
    if (\AccessManager::hasPermission('acces_employe') || \AccessManager::hasPermission('acces_admin')) {
        foreach ($employeeForms as $formKey => $formDefinition) {
            if (!portalSearchMatches($query, [
                $formKey,
                $formDefinition['label'],
                $formDefinition['subtitle'],
                $formDefinition['keywords'],
            ])) {
                continue;
            }
            portalPushResult($results, $typeCounters, 'form', [
                'type' => 'form',
                'id' => 'employee-form-' . $formKey,
                'label' => $formDefinition['label'],
                'subtitle' => $formDefinition['subtitle'],
                'target' => 'employe/nouvelle_demande_employe.php?type=' . rawurlencode($formKey),
                'meta' => 'Formulaire employé',
            ], 300);
        }
    }

    $searchablePages = [];
    foreach (array_keys(\AccessManager::PAGE_PERMISSIONS) as $page) {
        if (!portalIsSearchablePage((string) $page)) {
            continue;
        }

        $page = (string) $page;
        if (!\AccessManager::hasPageAccess($page)) {
            continue;
        }

        $searchablePages[$page] = $page;
    }
    foreach (portalDiscoverFormPages(dirname(__DIR__)) as $page) {
        if (!portalCanAccessPagePath($page)) {
            continue;
        }
        $searchablePages[$page] = $page;
    }

    foreach ($searchablePages as $page) {
        $label = portalPageLabel($page);
        $pageTarget = portalResolvePageTarget($page);
        if ($pageTarget === null) {
            continue;
        }

        $pageCategory = portalPageCategory($page);
        if (!portalSearchMatches($query, portalPageSearchTerms($page, $label, $pageCategory))) {
            continue;
        }

        $pageType = portalIsFormPage($page) ? 'form' : 'page';
        portalPushResult($results, $typeCounters, $pageType, [
            'type' => $pageType,
            'id' => $page,
            'label' => $label,
            'subtitle' => portalPageSubtitle($page, $pageTarget),
            'target' => $pageTarget,
            'meta' => $pageType === 'form' ? 'Formulaire' : $pageCategory,
        ], $pageType === 'form' ? 300 : 40);
    }

    if (\AccessManager::hasPageAccess('liste_client.php')) {
        $clientModel = new \Client($pdo);
        $clients = array_slice($clientModel->searchClients(['q' => $dataQuery]), 0, 8);
        foreach ($clients as $client) {
            $clientName = trim((string) ($client['nom_client'] ?? ''));
            if ($clientName === '') {
                continue;
            }

            portalPushResult($results, $typeCounters, 'client', [
                'type' => 'client',
                'id' => (string) ($client['id_client'] ?? $clientName),
                'label' => $clientName,
                'subtitle' => portalClientSubtitle($client),
                'target' => 'liste_client.php?q=' . rawurlencode($clientName),
                'meta' => 'Client',
            ], 6);
        }
    }

    if (\AccessManager::hasPageAccess('fournisseurs.php')) {
        $supplierModel = new \FournisseurRepository($pdo);
        $suppliers = $supplierModel->listLatest(6, false, ['search' => $dataQuery]);
        foreach ($suppliers as $supplier) {
            $supplierId = (int) ($supplier['id_fournisseur'] ?? 0);
            if ($supplierId <= 0) {
                continue;
            }

            $supplierName = trim((string) ($supplier['supplier_display_name'] ?? $supplier['nom_commercial'] ?? $supplier['raison_sociale'] ?? ''));
            if ($supplierName === '') {
                continue;
            }

            portalPushResult($results, $typeCounters, 'supplier', [
                'type' => 'supplier',
                'id' => (string) $supplierId,
                'label' => $supplierName,
                'subtitle' => portalSupplierSubtitle($supplier),
                'target' => 'fournisseur/edit_fournisseur.php?id=' . $supplierId,
                'meta' => 'Fournisseur',
            ], 4);
        }
    }

    if (\AccessManager::hasPageAccess('liste_chantier.php')) {
        $chantierModel = new \Chantier($pdo);
        $chantiers = array_slice($chantierModel->searchChantiers(['q' => $dataQuery]), 0, 6);
        foreach ($chantiers as $chantier) {
            $chantierId = (int) ($chantier['id'] ?? 0);
            $chantierTitle = trim((string) ($chantier['titre'] ?? ''));
            if ($chantierId <= 0 || $chantierTitle === '') {
                continue;
            }

            $chantierTarget = \AccessManager::hasPageAccess('details_chantier.php')
                ? ('details_chantier.php?id=' . $chantierId)
                : ('liste_chantier.php?q=' . rawurlencode($chantierTitle));

            portalPushResult($results, $typeCounters, 'chantier', [
                'type' => 'chantier',
                'id' => (string) $chantierId,
                'label' => $chantierTitle,
                'subtitle' => portalChantierSubtitle($chantier),
                'target' => $chantierTarget,
                'meta' => 'Chantier',
            ], 4);

            $normalizedQuery = portalSearchNormalize($query);
            $asksForDevis = strpos($normalizedQuery, 'devis') !== false
                || strpos($normalizedQuery, 'cotation') !== false
                || strpos($normalizedQuery, 'offre') !== false;
            $linkedDevisNumber = trim((string) ($chantier['devis_numero'] ?? ''));
            if ($asksForDevis && $linkedDevisNumber !== '' && \AccessManager::hasPageAccess('liste_devis.php')) {
                portalPushResult($results, $typeCounters, 'devis', [
                    'type' => 'devis',
                    'id' => 'chantier-devis-' . $chantierId,
                    'label' => $linkedDevisNumber,
                    'subtitle' => 'Devis lié au chantier ' . $chantierTitle,
                    'target' => 'liste_devis.php?q=' . rawurlencode($linkedDevisNumber),
                    'meta' => 'Devis du chantier',
                ], 8);
            }
        }
    }

    if (\AccessManager::hasPageAccess('liste_devis.php')) {
        $devisModel = new \Devis($pdo);
        $devisRows = array_slice($devisModel->searchDevis(['q' => $dataQuery]), 0, 6);
        foreach ($devisRows as $devis) {
            $devisId = (int) ($devis['id'] ?? 0);
            $devisNumber = trim((string) ($devis['numero_devis'] ?? ''));
            if ($devisId <= 0 || $devisNumber === '') {
                continue;
            }

            portalPushResult($results, $typeCounters, 'devis', [
                'type' => 'devis',
                'id' => (string) $devisId,
                'label' => $devisNumber,
                'subtitle' => portalDevisSubtitle($devis),
                'target' => 'liste_devis.php?q=' . rawurlencode($devisNumber),
                'meta' => 'Devis',
            ], 4);
        }
    }

    if (!empty($permissions['acces_facturation']) || !empty($permissions['acces_direction']) || !empty($permissions['acces_admin'])) {
        try {
            $invoiceTerm = '%' . $dataQuery . '%';
            $invoiceStmt = $pdo->prepare('
                SELECT fv.id_facture_vente, fv.reference_interne, fv.client_company_name,
                       fv.paiement_statut, fv.invoice_date, fv.total_ttc,
                       c.code chantier_code, c.titre chantier_titre
                FROM facture_vente_banamur fv
                LEFT JOIN chantier_banamur c ON c.id=fv.chantier_id
                WHERE c.archived_at IS NULL
                  AND (fv.reference_interne LIKE :term1
                       OR fv.client_company_name LIKE :term2
                       OR fv.fne_invoice_number LIKE :term3
                       OR c.code LIKE :term4 OR c.titre LIKE :term5)
                ORDER BY fv.id_facture_vente DESC LIMIT 10
            ');
            $invoiceStmt->execute([
                'term1' => $invoiceTerm,
                'term2' => $invoiceTerm,
                'term3' => $invoiceTerm,
                'term4' => $invoiceTerm,
                'term5' => $invoiceTerm,
            ]);
            foreach ($invoiceStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $invoice) {
                portalPushResult($results, $typeCounters, 'invoice', [
                    'type' => 'invoice',
                    'id' => (string) ($invoice['id_facture_vente'] ?? ''),
                    'label' => (string) ($invoice['reference_interne'] ?? 'Facture de vente'),
                    'subtitle' => trim(implode(' · ', array_filter([
                        (string) ($invoice['client_company_name'] ?? ''),
                        trim((string) ($invoice['chantier_code'] ?? '') . ' ' . (string) ($invoice['chantier_titre'] ?? '')),
                        (string) ($invoice['paiement_statut'] ?? ''),
                    ]))),
                    'target' => 'facturation/visualiser_facture_vente.php?id=' . (int) ($invoice['id_facture_vente'] ?? 0),
                    'meta' => 'Facture de vente',
                ], 8);
            }
        } catch (Throwable $ignored) {
        }
    }

    $currentUserId = (int) ($_SESSION['user_id'] ?? 0);
    $currentUserService = trim((string) ($_SESSION['service'] ?? $_SESSION['service_name'] ?? ''));
    if ($currentUserService === '' && $currentUserId > 0) {
        try {
            $serviceStmt = $pdo->prepare('SELECT s.lib_service
                FROM user_devis_banamur u
                LEFT JOIN personnel p ON p.id_personnel_soignant = u.personnel_id
                LEFT JOIN service s ON s.id_service = p.service_id
                WHERE u.id = :user_id
                LIMIT 1');
            $serviceStmt->execute(['user_id' => $currentUserId]);
            $currentUserService = trim((string) $serviceStmt->fetchColumn());
        } catch (Throwable $ignored) {
        }
    }
    $canSeeFinanceRequests = !empty($permissions['acces_feb'])
        || !empty($permissions['acces_approbation'])
        || !empty($permissions['acces_direction'])
        || !empty($permissions['acces_admin']);
    $canSeeRhRequests = !empty($permissions['acces_rh'])
        || !empty($permissions['acces_direction'])
        || !empty($permissions['acces_admin']);

    try {
        $requestSql = 'SELECT id, requester_user_id, request_type, title, requester_name, status, assigned_team, submitted_at
            FROM employee_legal_requests
            WHERE (title LIKE :term1 OR requester_name LIKE :term2 OR request_type LIKE :term3
                   OR CAST(id AS CHAR) LIKE :term4 OR payload_json LIKE :term5)
              AND (requester_user_id = :user_id';
        if ($canSeeFinanceRequests) {
            $requestSql .= ' OR request_type = "feb"';
        }
        if ($canSeeRhRequests) {
            $requestSql .= ' OR request_type <> "feb"';
        }
        $requestSql .= ') ORDER BY submitted_at DESC, id DESC LIMIT 12';
        $requestStmt = $pdo->prepare($requestSql);
        $requestTerm = '%' . $dataQuery . '%';
        $requestStmt->execute([
            'term1' => $requestTerm,
            'term2' => $requestTerm,
            'term3' => $requestTerm,
            'term4' => $requestTerm,
            'term5' => $requestTerm,
            'user_id' => $currentUserId,
        ]);
        foreach ($requestStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $requestRow) {
            $requestId = (int) ($requestRow['id'] ?? 0);
            $requestType = strtolower((string) ($requestRow['request_type'] ?? ''));
            $isFeb = $requestType === 'feb';
            if ($isFeb && $canSeeFinanceRequests) {
                $target = 'validation_feb_detail.php?id=' . $requestId;
            } elseif ((int) ($requestRow['requester_user_id'] ?? 0) === $currentUserId) {
                $target = 'employe/details_demande_employe.php?id=' . $requestId;
            } else {
                $target = 'rh/validation_demandes_rh.php?q=' . $requestId;
            }
            portalPushResult($results, $typeCounters, 'request', [
                'type' => 'request',
                'id' => (string) $requestId,
                'label' => trim((string) ($requestRow['title'] ?? '')) ?: ('Demande #' . $requestId),
                'subtitle' => trim(implode(' · ', array_filter([
                    strtoupper($requestType),
                    (string) ($requestRow['requester_name'] ?? ''),
                    (string) ($requestRow['status'] ?? ''),
                ]))),
                'target' => $target,
                'meta' => $isFeb ? 'FEB' : 'Demande',
            ], 10);
        }
    } catch (Throwable $ignored) {
        // Une source métier indisponible ne doit pas bloquer les autres résultats.
    }

    /*
     * Régularisations post-décaissement et pièces produites. Les documents ne
     * sont jamais exposés hors du périmètre du demandeur, de la comptabilité
     * et de la direction ; les endpoints de consultation refont le même contrôle.
     */
    try {
        $searchUserId = (int) ($_SESSION['user_id'] ?? 0);
        $canSearchAllRegularizations = !empty($permissions['acces_compta'])
            || !empty($permissions['acces_tresorerie'])
            || !empty($permissions['acces_direction'])
            || !empty($permissions['acces_admin']);
        $regularizationSql = '
            SELECT r.num_fiche,r.requester_user_id,r.reception_kind,r.reception_status,
                   r.project_name,r.project_location,r.owner_name,r.executing_company,
                   r.observations,r.reserves,r.remaining_actions,r.signer_name,r.signer_mode,
                   r.payment_proof_name,r.payment_proof_mime,r.submitted_at,r.accounting_status,
                   TRIM(CONCAT(COALESCE(u.prenom,"")," ",COALESCE(u.nom,""))) requester_name
            FROM disbursement_regularizations r
            LEFT JOIN user_devis_banamur u ON u.id=r.requester_user_id
        ';
        if (!$canSearchAllRegularizations) {
            $regularizationSql .= ' WHERE r.requester_user_id=:requester_id';
        }
        $regularizationSql .= ' ORDER BY COALESCE(r.submitted_at,r.created_at) DESC LIMIT 80';
        $regularizationStmt = $pdo->prepare($regularizationSql);
        $regularizationStmt->execute($canSearchAllRegularizations ? [] : ['requester_id' => $searchUserId]);
        foreach ($regularizationStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $regularization) {
            $searchFields = [
                $regularization['num_fiche'] ?? '', $regularization['project_name'] ?? '',
                $regularization['project_location'] ?? '', $regularization['owner_name'] ?? '',
                $regularization['executing_company'] ?? '', $regularization['requester_name'] ?? '',
                $regularization['signer_name'] ?? '', $regularization['reception_kind'] ?? '',
                $regularization['reception_status'] ?? '', $regularization['observations'] ?? '',
                $regularization['reserves'] ?? '', $regularization['remaining_actions'] ?? '',
                $regularization['payment_proof_name'] ?? '',
                'regularisation décaissement FEB PV procès verbal réception preuve paiement justificatif signature PO pour ordre avancement progression pondérée lignes effectué terminé restant attente observation checklist',
            ];
            if (!portalSearchMatches($query, $searchFields)) {
                continue;
            }
            $number = (string) ($regularization['num_fiche'] ?? '');
            $subtitle = trim(implode(' · ', array_filter([
                (string) ($regularization['project_name'] ?? ''),
                (string) ($regularization['requester_name'] ?? ''),
                (string) ($regularization['accounting_status'] ?? ''),
            ])));
            portalPushResult($results, $typeCounters, 'request', [
                'type' => 'request', 'id' => 'regularization-' . $number,
                'label' => 'Régularisation du décaissement ' . $number,
                'subtitle' => $subtitle,
                'target' => 'decaissement/regularisation_decaissement.php?num_fiche=' . rawurlencode($number),
                'meta' => 'Régularisation',
            ], 20);
            if (!empty($regularization['submitted_at'])) {
                portalPushResult($results, $typeCounters, 'document', [
                    'type' => 'document', 'id' => 'pv-' . $number,
                    'label' => 'PV de réception — ' . $number,
                    'subtitle' => 'PDF signé par ' . (string) ($regularization['signer_name'] ?? ''),
                    'target' => 'request/export_disbursement_reception_pv.php?num_fiche=' . rawurlencode($number),
                    'meta' => (string) ($regularization['signer_mode'] ?? '') === 'accounting_po' ? 'PV signé PO' : 'PV signé',
                ], 20);
                if (!empty($regularization['payment_proof_name'])) {
                    portalPushResult($results, $typeCounters, 'document', [
                        'type' => 'document', 'id' => 'payment-proof-' . $number,
                        'label' => 'Preuve de paiement — ' . $number,
                        'subtitle' => (string) ($regularization['payment_proof_name'] ?? 'Justificatif de paiement'),
                        'target' => 'request/view_disbursement_payment_proof.php?num_fiche=' . rawurlencode($number),
                        'meta' => str_starts_with((string) ($regularization['payment_proof_mime'] ?? ''), 'image/') ? 'Image' : 'Document',
                    ], 20);
                }
            }
        }
    } catch (Throwable $ignored) {
        // Le moteur reste disponible si le workflow n'est pas encore installé.
    }

    if (!empty($permissions['acces_rh']) || !empty($permissions['acces_direction']) || !empty($permissions['acces_admin'])) {
        try {
            $personStmt = $pdo->prepare('
                SELECT p.id_personnel_soignant id, p.nom_personnel_soignant full_name,
                       p.matricule_personnel_soignant matricule,
                       p.email_personnel_soignant email,
                       s.lib_service service_label, f.lib_fonction fonction_label
                FROM personnel p
                LEFT JOIN service s ON s.id_service=p.service_id
                LEFT JOIN fonction f ON f.id_fonction=p.fonction_id
                WHERE p.nom_personnel_soignant LIKE :term1
                   OR p.matricule_personnel_soignant LIKE :term2
                   OR p.email_personnel_soignant LIKE :term3
                   OR s.lib_service LIKE :term4
                   OR f.lib_fonction LIKE :term5
                ORDER BY p.nom_personnel_soignant LIMIT 10
            ');
            $personTerm = '%' . $dataQuery . '%';
            $personStmt->execute([
                'term1' => $personTerm,
                'term2' => $personTerm,
                'term3' => $personTerm,
                'term4' => $personTerm,
                'term5' => $personTerm,
            ]);
            foreach ($personStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $person) {
                portalPushResult($results, $typeCounters, 'person', [
                    'type' => 'person',
                    'id' => (string) ($person['id'] ?? ''),
                    'label' => (string) ($person['full_name'] ?? 'Collaborateur'),
                    'subtitle' => trim(implode(' · ', array_filter([
                        (string) ($person['fonction_label'] ?? ''),
                        (string) ($person['service_label'] ?? ''),
                        (string) ($person['matricule'] ?? ''),
                    ]))),
                    'target' => 'rh/dossier_personnel_rh.php?id=' . (int) ($person['id'] ?? 0),
                    'meta' => 'Personnel',
                ], 8);
            }
        } catch (Throwable $ignored) {
        }
    }

    if (!empty($permissions['acces_gestion_acces']) || !empty($permissions['acces_admin'])) {
        try {
            $userStmt = $pdo->prepare('
                SELECT u.id, u.prenom, u.nom, u.mail_pro, u.active,
                       COALESCE(r.lib_role_devis, "") role_label
                FROM user_devis_banamur u
                LEFT JOIN role_devis_banamur r ON r.id_role_devis = u.role_id
                WHERE u.prenom LIKE :term1 OR u.nom LIKE :term2 OR u.mail_pro LIKE :term3
                   OR r.lib_role_devis LIKE :term4
                ORDER BY u.active DESC, u.prenom, u.nom
                LIMIT 12
            ');
            $userTerm = '%' . $dataQuery . '%';
            $userStmt->execute([
                'term1' => $userTerm,
                'term2' => $userTerm,
                'term3' => $userTerm,
                'term4' => $userTerm,
            ]);
            foreach ($userStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $userRow) {
                $fullName = trim((string) ($userRow['prenom'] ?? '') . ' ' . (string) ($userRow['nom'] ?? ''));
                portalPushResult($results, $typeCounters, 'user', [
                    'type' => 'user',
                    'id' => (string) ($userRow['id'] ?? ''),
                    'label' => $fullName !== '' ? $fullName : (string) ($userRow['mail_pro'] ?? 'Utilisateur'),
                    'subtitle' => trim(implode(' · ', array_filter([
                        (string) ($userRow['mail_pro'] ?? ''),
                        (string) ($userRow['role_label'] ?? ''),
                        !empty($userRow['active']) ? 'Actif' : 'Inactif',
                    ]))),
                    'target' => 'admin/edit_user.php?id=' . (int) ($userRow['id'] ?? 0),
                    'meta' => 'Utilisateur',
                ], 12);
            }
        } catch (Throwable $ignored) {
        }

        try {
            $roleStmt = $pdo->prepare('
                SELECT id_role_devis, lib_role_devis
                FROM role_devis_banamur
                WHERE lib_role_devis LIKE :term
                ORDER BY lib_role_devis
                LIMIT 10
            ');
            $roleStmt->execute(['term' => '%' . $dataQuery . '%']);
            foreach ($roleStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $roleRow) {
                portalPushResult($results, $typeCounters, 'role', [
                    'type' => 'role',
                    'id' => (string) ($roleRow['id_role_devis'] ?? ''),
                    'label' => (string) ($roleRow['lib_role_devis'] ?? 'Rôle'),
                    'subtitle' => 'Consulter les utilisateurs et les habilitations associés à ce rôle',
                    'target' => 'admin/liste_users.php?role_id=' . (int) ($roleRow['id_role_devis'] ?? 0),
                    'meta' => 'Rôle',
                ], 10);
            }
        } catch (Throwable $ignored) {
        }
    }

    if (!empty($permissions['acces_logistique']) || !empty($permissions['acces_admin'])) {
        try {
            $assetStmt = $pdo->prepare('
                SELECT id, asset_code, asset_type, designation, registration_number,
                       brand, model, current_site, status
                FROM logistics_assets
                WHERE asset_code LIKE :term1 OR asset_type LIKE :term2
                   OR designation LIKE :term3 OR registration_number LIKE :term4
                   OR brand LIKE :term5 OR model LIKE :term6 OR current_site LIKE :term7
                ORDER BY id DESC
                LIMIT 12
            ');
            $assetTerm = '%' . $dataQuery . '%';
            $assetStmt->execute([
                'term1' => $assetTerm, 'term2' => $assetTerm, 'term3' => $assetTerm,
                'term4' => $assetTerm, 'term5' => $assetTerm, 'term6' => $assetTerm,
                'term7' => $assetTerm,
            ]);
            foreach ($assetStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $asset) {
                $label = trim((string) ($asset['designation'] ?? ''));
                portalPushResult($results, $typeCounters, 'asset', [
                    'type' => 'asset',
                    'id' => (string) ($asset['id'] ?? ''),
                    'label' => $label !== '' ? $label : ('Ressource #' . (int) ($asset['id'] ?? 0)),
                    'subtitle' => trim(implode(' · ', array_filter([
                        (string) ($asset['asset_code'] ?? ''),
                        (string) ($asset['registration_number'] ?? ''),
                        trim((string) ($asset['brand'] ?? '') . ' ' . (string) ($asset['model'] ?? '')),
                        (string) ($asset['current_site'] ?? ''),
                        (string) ($asset['status'] ?? ''),
                    ]))),
                    'target' => 'logi/engins_vehicules.php?q=' . rawurlencode($label),
                    'meta' => ucfirst((string) ($asset['asset_type'] ?? 'Matériel')),
                ], 12);
            }
        } catch (Throwable $ignored) {
        }

        try {
            $stockStmt = $pdo->prepare('
                SELECT id, category, designation, reference_code, unit_label,
                       current_quantity, location_label, status
                FROM logistics_stock_items
                WHERE category LIKE :term1 OR designation LIKE :term2
                   OR reference_code LIKE :term3 OR location_label LIKE :term4
                ORDER BY id DESC
                LIMIT 12
            ');
            $stockTerm = '%' . $dataQuery . '%';
            $stockStmt->execute([
                'term1' => $stockTerm, 'term2' => $stockTerm,
                'term3' => $stockTerm, 'term4' => $stockTerm,
            ]);
            foreach ($stockStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $stockRow) {
                portalPushResult($results, $typeCounters, 'stock', [
                    'type' => 'stock',
                    'id' => (string) ($stockRow['id'] ?? ''),
                    'label' => (string) ($stockRow['designation'] ?? 'Article de stock'),
                    'subtitle' => trim(implode(' · ', array_filter([
                        (string) ($stockRow['reference_code'] ?? ''),
                        (string) ($stockRow['category'] ?? ''),
                        'Stock ' . (string) ($stockRow['current_quantity'] ?? 0) . ' ' . (string) ($stockRow['unit_label'] ?? ''),
                        (string) ($stockRow['location_label'] ?? ''),
                    ]))),
                    'target' => 'logi/stock.php?q=' . rawurlencode((string) ($stockRow['designation'] ?? '')),
                    'meta' => 'Matériel / stock',
                ], 12);
            }
        } catch (Throwable $ignored) {
        }
    }

    if (!empty($permissions['acces_rh']) || !empty($permissions['acces_direction']) || !empty($permissions['acces_admin'])) {
        try {
            $contractStmt = $pdo->prepare('
                SELECT c.id, c.personnel_id, c.contract_number, c.contract_code,
                       c.document_state, c.payroll_period, p.nom_personnel_soignant full_name
                FROM rh_contract_registry c
                LEFT JOIN personnel p ON p.id_personnel_soignant = c.personnel_id
                WHERE c.contract_number LIKE :term1 OR c.contract_code LIKE :term2
                   OR p.nom_personnel_soignant LIKE :term3
                ORDER BY c.generated_at DESC
                LIMIT 12
            ');
            $contractTerm = '%' . $dataQuery . '%';
            $contractStmt->execute([
                'term1' => $contractTerm,
                'term2' => $contractTerm,
                'term3' => $contractTerm,
            ]);
            foreach ($contractStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $contractRow) {
                portalPushResult($results, $typeCounters, 'contract', [
                    'type' => 'contract',
                    'id' => (string) ($contractRow['id'] ?? ''),
                    'label' => (string) ($contractRow['contract_number'] ?? 'Contrat'),
                    'subtitle' => trim(implode(' · ', array_filter([
                        (string) ($contractRow['full_name'] ?? ''),
                        (string) ($contractRow['contract_code'] ?? ''),
                        (string) ($contractRow['payroll_period'] ?? ''),
                        (string) ($contractRow['document_state'] ?? ''),
                    ]))),
                    'target' => 'rh/contrats_employes_rh.php?personnel_id=' . (int) ($contractRow['personnel_id'] ?? 0),
                    'meta' => 'Contrat',
                ], 12);
            }
        } catch (Throwable $ignored) {
        }
    }

    if (!empty($permissions['acces_documentation']) || !empty($permissions['acces_direction']) || !empty($permissions['acces_admin'])) {
        try {
            $documentStmt = $pdo->prepare('
                SELECT d.id, d.title, d.description, d.document_type, d.required_permission,
                       v.original_name
                FROM doc_documents d
                LEFT JOIN doc_document_versions v ON v.id=d.current_version_id
                WHERE d.is_archived=0
                  AND (d.title LIKE :term1 OR d.description LIKE :term2 OR v.original_name LIKE :term3)
                ORDER BY d.updated_at DESC LIMIT 16
            ');
            $documentTerm = '%' . $dataQuery . '%';
            $documentStmt->execute([
                'term1' => $documentTerm,
                'term2' => $documentTerm,
                'term3' => $documentTerm,
            ]);
            foreach ($documentStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $document) {
                $requiredPermission = trim((string) ($document['required_permission'] ?? ''));
                if ($requiredPermission !== '' && empty($permissions[$requiredPermission]) && empty($permissions['acces_admin'])) {
                    continue;
                }
                portalPushResult($results, $typeCounters, 'document', [
                    'type' => 'document',
                    'id' => (string) ($document['id'] ?? ''),
                    'label' => (string) ($document['title'] ?? $document['original_name'] ?? 'Document'),
                    'subtitle' => trim((string) ($document['description'] ?? '')) ?: (string) ($document['original_name'] ?? ''),
                    'target' => 'documentation/index.php?document=' . (int) ($document['id'] ?? 0),
                    'meta' => 'Document',
                ], 10);
            }
        } catch (Throwable $ignored) {
        }
    }

    if (!empty($permissions['acces_ticketing']) || !empty($permissions['acces_direction']) || !empty($permissions['acces_admin'])) {
        try {
            $ticketSql = 'SELECT t.id,t.ticket_no,t.title,t.status,t.requester_name,t.assigned_service
                FROM finea_tickets t
                WHERE (t.ticket_no LIKE :term1 OR t.title LIKE :term2 OR t.description LIKE :term3
                       OR t.requester_name LIKE :term4 OR t.chantier LIKE :term5)
                  AND (t.requester_id = :ticket_requester
                       OR t.assigned_user_id = :ticket_assignee
                       OR (:ticket_service_check <> "" AND t.assigned_service = :ticket_service)
                       OR EXISTS (
                           SELECT 1 FROM finea_ticket_watchers tw
                           WHERE tw.ticket_id = t.id AND tw.user_id = :ticket_watcher
                       ))
                ORDER BY t.updated_at DESC LIMIT 10';
            $ticketStmt = $pdo->prepare($ticketSql);
            $ticketTerm = '%' . $dataQuery . '%';
            $ticketParams = [
                'term1' => $ticketTerm,
                'term2' => $ticketTerm,
                'term3' => $ticketTerm,
                'term4' => $ticketTerm,
                'term5' => $ticketTerm,
                'ticket_requester' => $currentUserId,
                'ticket_assignee' => $currentUserId,
                'ticket_service_check' => $currentUserService,
                'ticket_service' => $currentUserService,
                'ticket_watcher' => $currentUserId,
            ];
            $ticketStmt->execute($ticketParams);
            foreach ($ticketStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $ticket) {
                portalPushResult($results, $typeCounters, 'ticket', [
                    'type' => 'ticket',
                    'id' => (string) ($ticket['id'] ?? ''),
                    'label' => (string) ($ticket['title'] ?? 'Ticket'),
                    'subtitle' => trim(implode(' · ', array_filter([
                        (string) ($ticket['ticket_no'] ?? ''),
                        (string) ($ticket['status'] ?? ''),
                        (string) ($ticket['assigned_service'] ?? ''),
                    ]))),
                    'target' => 'ticketing/index.php?ticket=' . (int) ($ticket['id'] ?? 0),
                    'meta' => 'Ticket',
                ], 8);
            }
        } catch (Throwable $ignored) {
        }
    }

    if (\AccessManager::hasPageAccess('liste_chantier.php') || \AccessManager::hasPageAccess('fournisseurs.php') || \AccessManager::hasPageAccess('liste_client.php')) {
        try {
            $locationSql = '
                SELECT "chantier" source_type, id source_id, titre source_name,
                       CONCAT_WS(", ", code, titre) location_label
                FROM chantier_banamur
                WHERE archived_at IS NULL AND (titre LIKE :term1 OR code LIKE :term1)
                UNION ALL
                SELECT "fournisseur", id_fournisseur,
                       COALESCE(NULLIF(nom_commercial,""),raison_sociale),
                       CONCAT_WS(", ", adresse, ville, pays)
                FROM fournisseur_banamur
                WHERE adresse LIKE :term2 OR ville LIKE :term2 OR pays LIKE :term2
                UNION ALL
                SELECT "client", id_client, nom_client,
                       CONCAT_WS(", ", localisation_client, commune_client, pays_client)
                FROM client_banamur
                WHERE localisation_client LIKE :term3 OR commune_client LIKE :term3 OR pays_client LIKE :term3
                LIMIT 12';
            $locationStmt = $pdo->prepare($locationSql);
            $term = '%' . $dataQuery . '%';
            $locationStmt->execute(['term1' => $term, 'term2' => $term, 'term3' => $term]);
            foreach ($locationStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $location) {
                $sourceType = (string) ($location['source_type'] ?? '');
                $sourceId = (int) ($location['source_id'] ?? 0);
                $target = $sourceType === 'chantier'
                    ? 'details_chantier.php?id=' . $sourceId
                    : ($sourceType === 'fournisseur'
                        ? 'fournisseur/edit_fournisseur.php?id=' . $sourceId
                        : 'liste_client.php?q=' . rawurlencode((string) ($location['source_name'] ?? '')));
                portalPushResult($results, $typeCounters, 'location', [
                    'type' => 'location',
                    'id' => $sourceType . '-' . $sourceId,
                    'label' => trim((string) ($location['location_label'] ?? '')) ?: (string) ($location['source_name'] ?? 'Localisation'),
                    'subtitle' => (string) ($location['source_name'] ?? ''),
                    'target' => $target,
                    'meta' => ucfirst($sourceType),
                ], 8);
            }
        } catch (Throwable $ignored) {
        }
    }

    $requestedType = strtolower(trim((string) ($_GET['type'] ?? 'all')));
    if ($requestedType !== '' && $requestedType !== 'all') {
        $results = array_values(array_filter($results, static function (array $result) use ($requestedType): bool {
            $type = (string) ($result['type'] ?? '');
            $aliases = [
                'organizations' => ['client', 'supplier'],
                'business' => ['client', 'supplier'],
                'requests' => ['request', 'ticket'],
                'documents' => ['document', 'contract'],
                'people' => ['person', 'user', 'role'],
                'pages' => ['page'],
                'forms' => ['form'],
                'navigation' => ['module', 'page'],
                'projects' => ['chantier', 'devis', 'asset', 'stock'],
                'invoices' => ['invoice'],
                'modules' => ['module'],
                'locations' => ['location', 'chantier'],
            ];
            return $type === $requestedType || in_array($type, $aliases[$requestedType] ?? [], true);
        }));
    }

    $deduplicatedResults = [];
    $seenResults = [];
    foreach ($results as $result) {
        $deduplicationKey = strtolower((string) ($result['type'] ?? '')) . '|' . strtolower((string) ($result['target'] ?? ''));
        if ($deduplicationKey !== '|' && isset($seenResults[$deduplicationKey])) {
            continue;
        }
        $seenResults[$deduplicationKey] = true;
        $deduplicatedResults[] = $result;
    }
    $results = $deduplicatedResults;

    echo json_encode([
        'ok' => true,
        'results' => array_slice($results, 0, 300),
        'counts' => $typeCounters,
        'interpretation' => portalSearchInterpretation($query),
        'data_query' => $dataQuery,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Recherche indisponible',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
