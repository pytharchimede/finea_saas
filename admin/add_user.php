<?php
require_once __DIR__ . '/../auth_check.php';
AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/User.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../decaissement/model/PersonnelDirectory.php';

$pdo = Database::getConnection();
AccessManager::ensureOptionalPermissionColumns($pdo);
$userModel = new User($pdo);
$userModel->ensurePersonnelLinkSupport();

$directory = new PersonnelDirectory($pdo);
$personnelChoices = $directory->getPersonnelChoices('', 5000);

$serviceOptions = [];
try {
    $stmtServices = $pdo->query('SELECT id_service, lib_service FROM service ORDER BY lib_service ASC');
    foreach (($stmtServices ? ($stmtServices->fetchAll(PDO::FETCH_ASSOC) ?: []) : []) as $row) {
        $sid = (int)($row['id_service'] ?? 0);
        $lbl = trim((string)($row['lib_service'] ?? ''));
        if ($sid > 0 && $lbl !== '') {
            $serviceOptions[$sid] = $lbl;
        }
    }
} catch (Throwable $e) {
    $serviceOptions = [];
}

$linkedPersonnelLookup = [];
try {
    $linkedIds = $pdo->query('SELECT personnel_id FROM user_devis_banamur WHERE personnel_id IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN, 0);
    foreach ($linkedIds as $pid) {
        $pid = (int)$pid;
        if ($pid > 0) {
            $linkedPersonnelLookup[$pid] = true;
        }
    }
} catch (Throwable $e) {
    $linkedPersonnelLookup = [];
}

$selectedPersonnelId = 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selectedPersonnelId = (int)($_POST['personnel_id'] ?? 0);
}

$message = null;
$messageType = 'success';

$permissions = [

    'DEVIS' => [
        'modifier_devis' => 'Modifier devis',
        'modifier_devis_chantier_actif' => 'Modifier devis et déboursés d’un chantier en cours',
        'modifier_dates_travaux' => 'Modifier les dates de début et de fin des travaux',
        'visualiser_devis' => 'Consulter devis',
        'soumettre_devis' => 'Soumettre devis',
        'masquer_devis' => 'Masquer devis',
        'envoyer_devis' => 'Envoyer devis',
        'valider_devis' => 'Valider devis',
        'acces_generer_devis' => 'Générer devis',
        'acces_devis' => 'Module devis',
        'modifier_classement_devis' => 'Classer les devis Principal / TS par projet'
    ],

    'CHANTIERS' => [
        'acces_liste_chantier' => 'Liste chantiers',
        'acces_detail_chantier' => 'Détail chantier',
        'valider_archivage_chantier' => 'Terminer / Archiver chantier',
        'acces_dec_chantiers' => 'Décaissements chantier',
        'acces_budgets_chantiers' => 'Budgets chantiers'
    ],

    'DIRECTION & PLANNING' => [
        'acces_direction' => 'Direction générale',
        'acces_crm' => 'CRM (Direction commerciale)',
        'acces_planning' => 'Planning chantier',
        'acces_emploi_du_temps' => 'Emploi du temps',
        'acces_dashboard_stat_taches' => 'Dashboard statistiques des tâches'
    ],

    'DECAISSEMENT' => [
        'modifier_debourse' => 'Modifier déboursé',
        'acces_decaissement' => 'Module décaissement',
        'acces_fiches_decaissement' => 'Fiches décaissement',
        'acces_creation_fiche_decaissement' => 'Créer fiche',
        'acces_historique_fiches' => 'Historique fiches',
        'acces_fiche_details' => 'Détails fiches',
        'ajuster_beneficiaire_fournisseur_fiche' => 'Ajuster bénéficiaire/fournisseur après validation'
    ],

    'MENUS FINANCE PRIORITAIRES' => [
        'acces_feb' => 'FEB',
        'acces_signataire_point_jour' => 'Signataire du point de décaissement du jour',
        'acces_arreter_point_jour' => 'Arrêter le point de décaissement du jour',
        'acces_decaisser_point_jour' => 'Effectuer le paiement des points signés',
        'acces_recommandations_tresorerie' => 'Voir les informations et recommandations de trésorerie',
        'acces_tresorerie_lecture' => 'Consulter la trésorerie DG en lecture seule',
        'acces_conformite' => 'Conformite',
        'acces_approbation' => 'Approbation Directeur',
        'acces_achat' => 'Validation Achat',
        'acces_depenses_planifiees' => 'Dépenses Planifiées',
        'acces_tresorerie' => 'Traitement comptable des décaissements',
        'acces_point_financier' => 'Point financier'
    ],

    'FINANCE - AUTRES ACCES' => [
        'acces_compta' => 'Traitement des paiements (ancien accès compta)',
        'acces_relink_fiches_debourse' => 'Relier fiches cassées',
    ],

    'CARBURANT' => [
        'acces_recap_carburant' => 'Récap carburant',
        'acces_operations_carburant' => 'Opérations carburant'
    ],

    'GESTION' => [
        'gestion_utilisateur' => 'Gestion utilisateurs',
        'acces_gestion_acces' => 'Gestion accès',
        'acces_admin' => 'Admin'
    ],

    'OPERATIONS' => [
        'acces_contrats' => 'Contrats de sous-traitance',
        'acces_rh' => 'Ressources humaines',
        'acces_logistique' => 'Logistique',
        'acces_hse' => 'HSE',
        'acces_ticketing' => 'Centre de services / Tickets'
    ],

    'CONTRATS - ÉTAPES DU CIRCUIT' => [
        'acces_contrat_etape_rh' => 'Renseigner et signer l’étape RH',
        'acces_contrat_etape_juridique' => 'Renseigner et signer l’étape Juridique',
        'acces_contrat_etape_technique' => 'Renseigner et signer l’étape Technique',
        'acces_contrat_etape_logistique' => 'Renseigner et signer l’étape Logistique',
        'acces_contrat_etape_qhse' => 'Renseigner et signer l’étape QHSE',
        'acces_contrat_etape_comptabilite' => 'Renseigner et signer l’étape Comptabilité',
    ],

    'FOURNISSEURS & FACTURATION' => [
        'acces_fournisseur' => 'Gestion des fournisseurs',
        'acces_facturation' => 'Facturation'
    ],

    'AUTRES' => [
        'acces_dashboard' => 'Dashboard',
        'acces_communication' => 'Communication',
        'acces_documentation' => 'Documentation',
        'acces_formation' => 'Formation',
        'acces_carnet_adresse' => 'Carnet d\'adresse',
        'acces_assistance_distance' => 'Assistance à distance',
        'document_upload' => 'Déposer documents',
        'document_edit' => 'Modifier documents',
        'document_archive' => 'Archiver documents',
        'document_admin' => 'Admin documentation',
        'formation_admin' => 'Admin formation',
        'acces_mail' => 'Emails / Mailing',
        'acces_profil' => 'Profil',
        'acces_liste_client' => 'Liste clients',
        'acces_liste_facture' => 'Liste factures',
        'acces_liste_offre' => 'Liste offres',
        'acces_liste_unite' => 'Liste unites',
        'acces_otp' => 'OTP',
    ]

];

require_once __DIR__.'/../model/AccountingPermissions.php';
$permissions['COMPTABILITÉ — DROITS DÉTAILLÉS'] = AccountingPermissions::LABELS;
$configuredPermissionKeys = [];
foreach ($permissions as $group) {
    $configuredPermissionKeys = array_merge($configuredPermissionKeys, array_keys($group));
}
foreach (AccessManager::permissionColumns($pdo) as $permissionKey) {
    if (!in_array($permissionKey, $configuredPermissionKeys, true)) {
        $permissions['AUTRES DROITS DÉTECTÉS'][$permissionKey] = ucfirst(str_replace('_', ' ', $permissionKey));
    }
}
$universalPermissions = AccessManager::universalPermissions($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $data = [
        'personnel_id' => $_POST['personnel_id'] ?? null,
        'mail_pro' => strtolower(trim($_POST['mail_pro'] ?? '')),
        'nom' => trim($_POST['nom'] ?? ''),
        'prenom' => trim($_POST['prenom'] ?? ''),
        'password' => trim($_POST['password'] ?? ''),
        'active' => 1,
        'send_welcome_email' => true
    ];

    foreach ($permissions as $group) {
        foreach ($group as $key => $label) {
            $data[$key] = isset($_POST[$key]) ? 1 : 0;
        }
    }

    // Validation FEB par service (stockage: "1,2,3" ou "*")
    $febMode = strtolower(trim((string)($_POST['feb_services_mode'] ?? 'all')));
    if (!in_array($febMode, ['all', 'custom', 'compat'], true)) {
        $febMode = 'all';
    }

    if ($febMode === 'all') {
        $data['feb_validation_services'] = '*';
    } elseif ($febMode === 'custom') {
        $postedIds = $_POST['feb_services'] ?? [];
        $clean = [];
        if (is_array($postedIds)) {
            foreach ($postedIds as $v) {
                if (is_numeric($v)) {
                    $id = (int)$v;
                    if ($id > 0) {
                        $clean[$id] = $id;
                    }
                }
            }
        }
        $ids = array_values($clean);
        sort($ids);

        if (!$serviceOptions) {
            $messageType = 'error';
            $message = 'Liste des services indisponible (table service).';
        } elseif (!$ids) {
            $messageType = 'error';
            $message = 'Sélectionnez au moins un service FEB (ou choisissez "Tous les services").';
        } else {
            $data['feb_validation_services'] = implode(',', $ids);
        }
    } else {
        $data['feb_validation_services'] = null;
    }

    if ($messageType !== 'error' && ($data['mail_pro'] === '' || $data['nom'] === '' || $data['prenom'] === '')) {
        $messageType = 'error';
        $message = 'Veuillez remplir tous les champs requis.';
    }

    if ($messageType !== 'error') {
        $createdId = $userModel->createUser($data);

        if ($createdId) {
            AuditTrail::log('user_created', [
                'module' => 'admin',
                'action' => 'add_user',
                'entity_type' => 'user',
                'entity_id' => (string) $createdId,
                'entity_label' => trim(($data['prenom'] ?? '') . ' ' . ($data['nom'] ?? '')),
                'result' => 'success',
                'details' => [
                    'mail_pro' => $data['mail_pro'] ?? '',
                    'personnel_id' => !empty($data['personnel_id']) ? (int) $data['personnel_id'] : null,
                    'active' => (int) ($data['active'] ?? 0),
                    'feb_validation_services' => $data['feb_validation_services'] ?? null,
                    'view_mode' => 'form_submit',
                ],
            ], $pdo);

            $messageType = 'success';
            $message = 'Utilisateur créé avec succès.';
        } else {
            $messageType = 'error';
            $message = $userModel->getLastError() ?: 'Erreur lors de la création de l’utilisateur.';
        }
    }

    if ($messageType === 'error') {
        AuditTrail::log('user_create_failed', [
            'module' => 'admin',
            'action' => 'add_user',
            'entity_type' => 'user',
            'entity_label' => trim(($data['prenom'] ?? '') . ' ' . ($data['nom'] ?? '')),
            'result' => 'failed',
            'severity' => 'warning',
            'details' => [
                'mail_pro' => $data['mail_pro'] ?? '',
                'personnel_id' => !empty($data['personnel_id']) ? (int) $data['personnel_id'] : null,
                'error_message' => $message,
                'view_mode' => 'form_submit',
            ],
        ], $pdo);
    }
}

$febSelectedMode = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? strtolower(trim((string)($_POST['feb_services_mode'] ?? 'all')))
    : 'all';
if (!in_array($febSelectedMode, ['all', 'custom', 'compat'], true)) {
    $febSelectedMode = 'all';
}
$febSelectedIds = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? (is_array($_POST['feb_services'] ?? null) ? array_values(array_unique(array_map('intval', (array)$_POST['feb_services']))) : [])
    : [];
$febSelectedLookup = [];
foreach ($febSelectedIds as $sid) {
    if ($sid > 0) {
        $febSelectedLookup[(int)$sid] = true;
    }
}

$pageTitle = 'Ajouter un utilisateur';
$activeMenu = '';

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>

<div class="min-h-screen bg-gradient-to-br from-indigo-50 via-white to-purple-50">

    <div class="container mx-auto py-10 px-4">

        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-10">

            <div>
                <h1 class="text-4xl font-bold text-gray-800">
                    Ajouter un utilisateur
                </h1>

                <p class="mt-2 text-gray-600">
                    Créer un compte et définir ses permissions.
                </p>
            </div>

            <div class="flex flex-wrap gap-3">

                <a href="liste_users.php"
                    class="inline-flex items-center gap-2 px-5 py-3 bg-white border border-gray-200 rounded-xl shadow-sm hover:shadow-md text-gray-700 transition">

                    <i class="fas fa-arrow-left"></i>
                    Retour à la liste

                </a>

                <a href="dashboard_admin.php"
                    class="inline-flex items-center gap-2 px-5 py-3 bg-indigo-600 text-white rounded-xl shadow hover:bg-indigo-700 transition">

                    <i class="fas fa-tachometer-alt"></i>
                    Dashboard Admin

                </a>

            </div>

        </div>


        <?php if (!empty($message)): ?>

            <div class="mb-6 p-4 rounded-xl
<?= $messageType === 'success'
                ? 'bg-emerald-50 border border-emerald-200 text-emerald-700'
                : 'bg-red-50 border border-red-200 text-red-700' ?>">

                <?= htmlspecialchars($message) ?>

            </div>

        <?php endif; ?>


        <div class="bg-white rounded-2xl shadow-xl border border-gray-200 p-8">

            <form method="POST" class="space-y-6">

                <input type="hidden" id="permissions_touched" name="permissions_touched" value="0" />

                <div class="bg-gray-50 border border-gray-200 rounded-xl p-6">

                    <h3 class="text-lg font-semibold text-gray-800 mb-4">
                        Personnel (optionnel)
                    </h3>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

                        <div>
                            <label class="block text-sm font-medium text-gray-700">
                                Rechercher
                            </label>
                            <input
                                id="personnel_search"
                                type="text"
                                placeholder="Nom, email ou service…"
                                class="mt-1 block w-full rounded-xl border-gray-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">
                                Sélectionner un membre du personnel
                            </label>

                            <select
                                id="personnel_id"
                                name="personnel_id"
                                class="mt-1 block w-full rounded-xl border-gray-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">

                                <option value="">— Aucun —</option>

                                <?php foreach ($personnelChoices as $p):
                                    $pid = (int)($p['id'] ?? 0);
                                    $fullName = (string)($p['full_name'] ?? '');
                                    $email = (string)($p['email'] ?? '');
                                    $service = (string)($p['service_label'] ?? '');
                                    $isLinked = $pid > 0 && isset($linkedPersonnelLookup[$pid]);
                                    $isSelected = $pid > 0 && $pid === $selectedPersonnelId;
                                    $labelParts = [trim($fullName)];
                                    if (trim($service) !== '') {
                                        $labelParts[] = trim($service);
                                    }
                                    if (trim($email) !== '') {
                                        $labelParts[] = trim($email);
                                    }
                                    if ($isLinked) {
                                        $labelParts[] = 'déjà lié';
                                    }
                                    $label = implode(' — ', array_filter($labelParts, static function ($v) {
                                        return $v !== '';
                                    }));
                                ?>

                                    <option
                                        value="<?= $pid ?>"
                                        data-full-name="<?= htmlspecialchars($fullName, ENT_QUOTES) ?>"
                                        data-email="<?= htmlspecialchars($email, ENT_QUOTES) ?>"
                                        data-service="<?= htmlspecialchars($service, ENT_QUOTES) ?>"
                                        <?= $isLinked && !$isSelected ? 'disabled' : '' ?>
                                        <?= $isSelected ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($label) ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                            <p class="mt-2 text-xs text-gray-500">
                                La sélection pré-remplit email/prénom/nom et applique des droits par défaut selon le service (modifiable ensuite).
                            </p>
                        </div>

                    </div>

                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

                    <div>
                        <label class="block text-sm font-medium text-gray-700">
                            Email professionnel
                        </label>

                        <input
                            name="mail_pro"
                            type="email"
                            required
                            value="<?= htmlspecialchars(strtolower($_POST['mail_pro'] ?? '')) ?>"
                            class="mt-1 block w-full rounded-xl border-gray-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />

                    </div>


                    <div>

                        <label class="block text-sm font-medium text-gray-700">
                            Mot de passe
                        </label>

                        <input
                            name="password"
                            type="text"
                            class="mt-1 block w-full rounded-xl border-gray-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />

                    </div>

                </div>


                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

                    <div>

                        <label class="block text-sm font-medium text-gray-700">
                            Prénom
                        </label>

                        <input
                            name="prenom"
                            required
                            value="<?= htmlspecialchars($_POST['prenom'] ?? '') ?>"
                            class="mt-1 block w-full rounded-xl border-gray-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />

                    </div>


                    <div>

                        <label class="block text-sm font-medium text-gray-700">
                            Nom
                        </label>

                        <input
                            name="nom"
                            required
                            value="<?= htmlspecialchars($_POST['nom'] ?? '') ?>"
                            class="mt-1 block w-full rounded-xl border-gray-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />

                    </div>

                </div>


                <div class="bg-amber-50 border border-amber-200 rounded-xl p-6">

                    <h3 class="text-lg font-semibold text-amber-900 mb-1">Validation FEB par service</h3>
                    <p class="text-sm text-amber-900/80">Paramètre quels services (bénéficiaires) ce compte peut valider côté Finances. Stockage: <span class="font-semibold">user_devis_banamur.feb_validation_services</span>.</p>

                    <div class="mt-4 grid grid-cols-1 lg:grid-cols-[260px_1fr] gap-4">
                        <div class="space-y-3">
                            <label class="flex items-start gap-3 rounded-xl bg-white border border-amber-200 px-4 py-3">
                                <input type="radio" name="feb_services_mode" value="all" class="mt-1" <?= $febSelectedMode === 'all' ? 'checked' : '' ?>>
                                <div>
                                    <div class="text-sm font-semibold text-amber-900">Tous les services</div>
                                    <div class="text-xs text-amber-800/80">Enregistre <span class="font-semibold">*</span>.</div>
                                </div>
                            </label>

                            <label class="flex items-start gap-3 rounded-xl bg-white border border-amber-200 px-4 py-3">
                                <input type="radio" name="feb_services_mode" value="custom" class="mt-1" <?= $febSelectedMode === 'custom' ? 'checked' : '' ?>>
                                <div>
                                    <div class="text-sm font-semibold text-amber-900">Restreindre</div>
                                    <div class="text-xs text-amber-800/80">Enregistre <span class="font-semibold">1,2,3</span>…</div>
                                </div>
                            </label>

                            <label class="flex items-start gap-3 rounded-xl bg-white border border-amber-200 px-4 py-3">
                                <input type="radio" name="feb_services_mode" value="compat" class="mt-1" <?= $febSelectedMode === 'compat' ? 'checked' : '' ?>>
                                <div>
                                    <div class="text-sm font-semibold text-amber-900">Non configuré (compatibilité)</div>
                                    <div class="text-xs text-amber-800/80">Accès global par défaut (affiche un avertissement dans Finances).</div>
                                </div>
                            </label>
                        </div>

                        <div>
                            <div class="text-sm font-semibold text-amber-900">Services autorisés</div>
                            <?php if (!$serviceOptions): ?>
                                <div class="mt-2 text-sm text-amber-900">Aucun service trouvé (table <span class="font-semibold">service</span> vide ou inaccessible).</div>
                            <?php else: ?>
                                <div id="febServicesBox" class="mt-3 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
                                    <?php foreach ($serviceOptions as $sid => $lbl): ?>
                                        <label class="flex items-center gap-2 rounded-xl border border-amber-200 bg-white px-3 py-2">
                                            <input type="checkbox" name="feb_services[]" value="<?= (int)$sid ?>" <?= !empty($febSelectedLookup[(int)$sid]) ? 'checked' : '' ?>>
                                            <span class="text-sm text-slate-800"><?= htmlspecialchars((string)$lbl) ?></span>
                                            <span class="ml-auto text-xs text-slate-500">#<?= (int)$sid ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <div class="mt-2 text-xs text-amber-900/80">Astuce: cochez “Restreindre” puis sélectionnez les services.</div>
                        </div>
                    </div>
                </div>


                <div class="bg-indigo-50 border border-indigo-100 rounded-xl p-6">

                    <h3 class="text-lg font-semibold text-indigo-700 mb-4">
                        Permissions et droits
                    </h3>


                    <?php foreach ($permissions as $groupName => $group): ?>
                    <?php if ($groupName==='COMPTABILITÉ — DROITS DÉTAILLÉS'): ?>
                    <div class="rounded-xl border border-indigo-100 bg-indigo-50 p-4 mb-4 text-sm text-slate-700">
                        <strong>Comptabilité : des droits attribués explicitement</strong>
                        <p>Activez l’accès au module, puis les opérations autorisées. La saisie et la validation nécessitent aussi la consultation des écritures et des justificatifs. La comptabilisation des décaissements nécessite en plus la consultation des points payés. La validation, la contrepassation et la clôture sont indépendantes de la saisie.</p>
                        <p>Les droits Paiements et Direction ne donnent aucun accès automatique. Un administrateur conserve tous les droits.</p>
                    </div>
                    <?php endif; ?>

                        <div class="mb-6">

                            <h4 class="text-md font-semibold text-gray-700 mb-3">

                                <?= $groupName ?>

                            </h4>


                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">

                                <?php foreach ($group as $key => $label):

                                    $isUniversal = in_array($key, $universalPermissions, true);
                                    $checked = $isUniversal;
                                    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                                        $checked = $isUniversal || isset($_POST[$key]);
                                    } else {
                                        // Par défaut, l'emploi du temps reste accessible.
                                        $checked = $isUniversal || ($key === 'acces_emploi_du_temps');
                                    }

                                ?>

                                    <label
                                        class="permission-card flex items-center gap-3 p-3 rounded-xl border cursor-pointer transition <?= $checked ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-gray-100 text-gray-600 border-gray-200' ?>">

                                        <input
                                            type="checkbox"
                                            name="<?= $key ?>"
                                            class="permission-checkbox hidden"
                                            <?= $isUniversal ? 'disabled' : '' ?>
                                            <?= $checked ? 'checked' : '' ?>>
                                        <?php if ($isUniversal): ?>
                                            <input type="hidden" name="<?= htmlspecialchars($key) ?>" value="1">
                                        <?php endif; ?>

                                        <i class="fas <?= $checked ? 'fa-check-circle' : 'fa-circle' ?>"></i>

                                        <span class="text-sm font-medium">

                                            <?= $label ?>
                                            <?php if ($isUniversal): ?>
                                                <small class="block opacity-80">Accès commun obligatoire</small>
                                            <?php endif; ?>

                                        </span>

                                    </label>

                                <?php endforeach; ?>

                            </div>

                        </div>

                    <?php endforeach; ?>

                </div>


                <div class="flex flex-wrap items-center gap-3">

                    <button
                        type="submit"
                        class="inline-flex items-center gap-2 px-6 py-3 bg-indigo-600 text-white rounded-xl shadow hover:bg-indigo-700 transition">

                        <i class="fas fa-user-plus"></i>
                        Créer l'utilisateur

                    </button>


                    <a
                        href="liste_users.php"
                        class="inline-flex items-center gap-2 px-6 py-3 bg-gray-100 text-gray-700 rounded-xl hover:bg-gray-200 transition">

                        <i class="fas fa-times"></i>
                        Annuler

                    </a>

                </div>

            </form>

        </div>

    </div>

</div>


<script>
    (() => {

        const permissionsTouched = document.getElementById('permissions_touched')

        const updatePermissionCard = (cb) => {
            const card = cb.closest('.permission-card')
            if (!card) return
            const icon = card.querySelector('i.fas')

            if (cb.checked) {
                card.classList.remove('bg-gray-100', 'text-gray-600', 'border-gray-200')
                card.classList.add('bg-indigo-600', 'text-white', 'border-indigo-600')
                if (icon) {
                    icon.classList.remove('fa-circle')
                    icon.classList.add('fa-check-circle')
                }
            } else {
                card.classList.remove('bg-indigo-600', 'text-white', 'border-indigo-600')
                card.classList.add('bg-gray-100', 'text-gray-600', 'border-gray-200')
                if (icon) {
                    icon.classList.remove('fa-check-circle')
                    icon.classList.add('fa-circle')
                }
            }
        }

        document.querySelectorAll('.permission-checkbox').forEach(cb => {
            const card = cb.closest('.permission-card')
            if (!card) return

            updatePermissionCard(cb)

            card.addEventListener('click', (e) => {
                e.preventDefault()
                cb.checked = !cb.checked
                if (permissionsTouched) permissionsTouched.value = '1'
                updatePermissionCard(cb)
            })
        })

        const splitName = (fullName) => {
            const s = (fullName || '').trim().replace(/\s+/g, ' ')
            if (!s) return {
                prenom: '',
                nom: ''
            }

            if (s.includes(',')) {
                const parts = s.split(',')
                const nom = (parts[0] || '').trim()
                const prenom = (parts.slice(1).join(',') || '').trim()
                return {
                    prenom,
                    nom
                }
            }

            const parts = s.split(' ').filter(Boolean)
            if (parts.length === 1) return {
                prenom: '',
                nom: parts[0]
            }
            return {
                prenom: parts[0],
                nom: parts.slice(1).join(' ')
            }
        }

        const setFieldValue = (selector, value) => {
            const el = document.querySelector(selector)
            if (!el) return
            el.value = value || ''
        }

        const applyDefaultPermissions = (serviceLabel) => {
            if (permissionsTouched && permissionsTouched.value === '1') return

            const s = (serviceLabel || '').toLowerCase()
            const defaults = new Set(['acces_dashboard', 'acces_profil', 'acces_emploi_du_temps'])

            if (s.includes('rh') || s.includes('ressource')) defaults.add('acces_rh')
            if (s.includes('logist')) defaults.add('acces_logistique')
            if (s.includes('hse') || s.includes('qse')) defaults.add('acces_hse')
            if (s.includes('compta') || s.includes('finance')) {
                defaults.add('acces_compta')
                defaults.add('acces_relink_fiches_debourse')
            }
            if (s.includes('planning')) defaults.add('acces_planning')
            if (s.includes('direction') || s.includes('dg')) defaults.add('acces_direction')

            document.querySelectorAll('.permission-checkbox').forEach(cb => {
                cb.checked = defaults.has(cb.name)
                updatePermissionCard(cb)
            })
        }

        const personnelSearch = document.getElementById('personnel_search')
        const personnelSelect = document.getElementById('personnel_id')

        if (personnelSearch && personnelSelect) {
            const filterOptions = () => {
                const term = (personnelSearch.value || '').toLowerCase().trim()
                Array.from(personnelSelect.options).forEach((opt, idx) => {
                    if (idx === 0) {
                        opt.hidden = false
                        return
                    }
                    const text = (opt.textContent || '').toLowerCase()
                    opt.hidden = term !== '' && !text.includes(term)
                })
            }

            const applyPersonnel = () => {
                const opt = personnelSelect.selectedOptions && personnelSelect.selectedOptions[0]
                if (!opt || !opt.value) return

                const fullName = opt.dataset.fullName || ''
                const email = opt.dataset.email || ''
                const service = opt.dataset.service || ''

                if (email) setFieldValue('input[name="mail_pro"]', String(email).toLowerCase())

                const parts = splitName(fullName)
                if (parts.prenom) setFieldValue('input[name="prenom"]', parts.prenom)
                if (parts.nom) setFieldValue('input[name="nom"]', parts.nom)

                applyDefaultPermissions(service)
            }

            personnelSearch.addEventListener('input', filterOptions)
            personnelSelect.addEventListener('change', applyPersonnel)

            filterOptions()
            applyPersonnel()
        }

        const updateFebServicesUi = () => {
            const selected = document.querySelector('input[name="feb_services_mode"]:checked')
            const mode = selected ? selected.value : 'compat'
            const box = document.getElementById('febServicesBox')
            if (!box) return

            const enabled = mode === 'custom'
            box.classList.toggle('opacity-50', !enabled)
            box.querySelectorAll('input[type="checkbox"]').forEach(cb => {
                cb.disabled = !enabled
            })
        }

        document.querySelectorAll('input[name="feb_services_mode"]').forEach(r => {
            r.addEventListener('change', updateFebServicesUi)
        })
        updateFebServicesUi()

    })()
</script>


<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>
