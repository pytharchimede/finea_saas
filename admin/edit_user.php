<?php
require_once __DIR__ . '/../auth_check.php';
AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/User.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../decaissement/model/PersonnelDirectory.php';
require_once __DIR__ . '/../services/PasskeyPolicyService.php';

$pdo = Database::getConnection();
AccessManager::ensureOptionalPermissionColumns($pdo);
try {
    $permissionColumnStmt = $pdo->query("SHOW COLUMNS FROM user_devis_banamur LIKE 'ajuster_beneficiaire_fournisseur_fiche'");
    if (!$permissionColumnStmt || !$permissionColumnStmt->fetch(PDO::FETCH_ASSOC)) {
        $pdo->exec("ALTER TABLE user_devis_banamur ADD COLUMN ajuster_beneficiaire_fournisseur_fiche TINYINT(1) NOT NULL DEFAULT 0");
    }
} catch (Throwable $permissionSchemaError) {
    // Le formulaire affichera toujours le droit; updateUser retournera une erreur si la migration est réellement impossible.
}
try {
    $permissionColumnStmt = $pdo->query("SHOW COLUMNS FROM user_devis_banamur LIKE 'regulariser_fournisseur_sans_preuve'");
    if (!$permissionColumnStmt || !$permissionColumnStmt->fetch(PDO::FETCH_ASSOC)) {
        $pdo->exec("ALTER TABLE user_devis_banamur ADD COLUMN regulariser_fournisseur_sans_preuve TINYINT(1) NOT NULL DEFAULT 0");
    }
} catch (Throwable $permissionSchemaError) {
    // La matrice globale pourra également initialiser ce droit.
}
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

function feb_services_mode_from_raw(?string $raw): string
{
    $raw = trim((string)$raw);
    if ($raw === '') {
        return 'compat';
    }
    $lower = strtolower($raw);
    if ($lower === '*' || $lower === 'all' || $lower === 'tous') {
        return 'all';
    }
    return 'custom';
}

function feb_services_ids_from_raw(?string $raw): array
{
    $raw = trim((string)$raw);
    if ($raw === '') {
        return [];
    }
    $lower = strtolower($raw);
    if ($lower === '*' || $lower === 'all' || $lower === 'tous') {
        return [];
    }
    $parts = preg_split('/[^0-9]+/', $raw) ?: [];
    $ids = [];
    foreach ($parts as $p) {
        $v = (int)$p;
        if ($v > 0) {
            $ids[$v] = $v;
        }
    }
    return array_values($ids);
}

$linkedPersonnelToUser = [];
try {
    $stmtLinked = $pdo->query('SELECT id, personnel_id FROM user_devis_banamur WHERE personnel_id IS NOT NULL');
    foreach ($stmtLinked->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $pid = (int)($row['personnel_id'] ?? 0);
        $uid = (int)($row['id'] ?? 0);
        if ($pid > 0 && $uid > 0) {
            $linkedPersonnelToUser[$pid] = $uid;
        }
    }
} catch (Throwable $e) {
    $linkedPersonnelToUser = [];
}

$userId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

if ($userId <= 0) {
    header('Location: liste_users.php');
    exit;
}

$userBeforeUpdate = $userModel->getUserById($userId) ?: [];
$passkeyPolicyService = new PasskeyPolicyService($pdo);
$userPasskeyPolicy = $passkeyPolicyService->get($userId);

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

    'VEILLE APPELS D OFFRES' => [
        'acces_veille_ao' => 'Acceder a la veille AO',
        'ao_view' => 'Consulter les appels d offres',
        'ao_export' => 'Exporter PDF et Excel',
        'ao_manage' => 'Modifier les statuts internes',
        'ao_mark_interesting' => 'Marquer interessant',
        'ao_mark_not_interested' => 'Marquer pas interessant',
        'ao_create_crm' => 'Creer une opportunite CRM',
        'ao_manage_alerts' => 'Gerer ses preferences d alertes',
        'ao_manage_diffusion' => 'AO : gérer la diffusion collective et les destinataires',
        'ao_dg_decision' => 'Prendre la decision DG',
        'ao_admin' => 'Administrer toute la veille AO',
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
        'acces_fiche_details' => 'Détails fiche',
        'ajuster_beneficiaire_fournisseur_fiche' => 'Ajuster bénéficiaire/fournisseur après validation',
        'regulariser_fournisseur_sans_preuve' => 'Régulariser un fournisseur sans preuve (bypass audité)'
    ],

    'MENUS FINANCE PRIORITAIRES' => [
        'acces_feb' => 'FEB',
        'acces_conformite' => 'Conformite',
        'acces_approbation' => 'Approbation Directeur',
        'acces_achat' => 'Validation Achat',
        'acces_depenses_planifiees' => 'Dépenses Planifiées',
        'acces_tresorerie' => 'Traitement comptable des décaissements',
        'acces_point_financier' => 'Point financier',
        'acces_signataire_point_jour' => 'Signataire du point de décaissement du jour',
        'acces_arreter_point_jour' => 'Arrêter le point de décaissement du jour',
        'acces_decaisser_point_jour' => 'Effectuer le paiement des points signés',
        'acces_recommandations_tresorerie' => 'Voir les informations et recommandations de trésorerie'
        ,'acces_tresorerie_lecture' => 'Consulter la trésorerie DG en lecture seule'
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
        'active' => isset($_POST['active'])
    ];

    foreach ($permissions as $group) {
        foreach ($group as $key => $label) {
            $data[$key] = isset($_POST[$key]) ? 1 : 0;
        }
    }

    // Validation FEB par service (stockage: "1,2,3" ou "*")
    $febMode = strtolower(trim((string)($_POST['feb_services_mode'] ?? 'compat')));
    if (!in_array($febMode, ['all', 'custom', 'compat'], true)) {
        $febMode = 'compat';
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
        // compat: non configuré (accès global par compatibilité)
        $data['feb_validation_services'] = null;
    }

    if ($messageType !== 'error' && ($data['mail_pro'] === '' || $data['nom'] === '' || $data['prenom'] === '')) {
        $messageType = 'error';
        $message = 'Veuillez remplir les informations obligatoires.';
    }

    if ($messageType !== 'error') {
        $success = $userModel->updateUser($userId, $data);
        if ($success) {
            // Sauvegarde explicite de ce droit sensible afin qu'il ne dépende d'aucune
            // liste dynamique de permissions ni d'un cache de schéma.
            $adjustPermissionValue = isset($_POST['ajuster_beneficiaire_fournisseur_fiche']) ? 1 : 0;
            $adjustPermissionStmt = $pdo->prepare('UPDATE user_devis_banamur SET ajuster_beneficiaire_fournisseur_fiche=:enabled WHERE id=:id');
            $success = $adjustPermissionStmt->execute(['enabled' => $adjustPermissionValue, 'id' => $userId]);
            if ($success) {
                $verifyAdjustStmt = $pdo->prepare('SELECT ajuster_beneficiaire_fournisseur_fiche FROM user_devis_banamur WHERE id=:id');
                $verifyAdjustStmt->execute(['id' => $userId]);
                $success = (int)$verifyAdjustStmt->fetchColumn() === $adjustPermissionValue;
            }
        }

        if ($success) {
            $passkeyPolicyService->setByAdmin($userId, isset($_POST['passkey_only']), (int)($_SESSION['user_id']??0));
            $userPasskeyPolicy = $passkeyPolicyService->get($userId);
            $changedFields = [];
            $trackedFields = array_values(array_unique(array_merge(
                ['mail_pro', 'nom', 'prenom', 'personnel_id', 'active', 'feb_validation_services'],
                AccessManager::permissionColumns($pdo)
            )));
            foreach ($trackedFields as $field) {
                $beforeValue = $userBeforeUpdate[$field] ?? null;
                $afterValue = $data[$field] ?? null;
                if ((string) $beforeValue !== (string) $afterValue) {
                    $changedFields[$field] = [
                        'from' => $beforeValue,
                        'to' => $afterValue,
                    ];
                }
            }

            AuditTrail::log('user_updated', [
                'module' => 'admin',
                'action' => 'edit_user',
                'entity_type' => 'user',
                'entity_id' => (string) $userId,
                'entity_label' => trim((string) (($userBeforeUpdate['prenom'] ?? '') . ' ' . ($userBeforeUpdate['nom'] ?? ''))),
                'result' => 'success',
                'old_value' => array_map(static fn(array $change) => $change['from'] ?? null, $changedFields),
                'new_value' => array_map(static fn(array $change) => $change['to'] ?? null, $changedFields),
                'comment' => 'Modification du compte utilisateur depuis l administration.',
                'details' => [
                    'changed_fields' => $changedFields,
                    'changed_count' => count($changedFields),
                    'view_mode' => 'form_submit',
                ],
            ], $pdo);

            $messageType = 'success';
            $message = 'Utilisateur mis à jour avec succès.';

            // Si l'admin modifie son propre compte, recharger les permissions en session.
            if (!empty($_SESSION['user_id']) && (int)$_SESSION['user_id'] === (int)$userId) {
                AccessManager::refreshSessionPermissions();
            }
        } else {
            $messageType = 'error';
            $message = $userModel->getLastError() ?: 'Erreur lors de la mise à jour.';
        }
    }

    if ($messageType === 'error') {
        AuditTrail::log('user_update_failed', [
            'module' => 'admin',
            'action' => 'edit_user',
            'entity_type' => 'user',
            'entity_id' => (string) $userId,
            'entity_label' => trim((string) (($userBeforeUpdate['prenom'] ?? '') . ' ' . ($userBeforeUpdate['nom'] ?? ''))),
            'result' => 'failed',
            'severity' => 'warning',
            'details' => [
                'mail_pro' => $data['mail_pro'] ?? '',
                'error_message' => $message,
                'view_mode' => 'form_submit',
            ],
        ], $pdo);
    }
}

$user = $userModel->getUserById($userId);
$selectedPersonnelId = (int)($_POST['personnel_id'] ?? ($user['personnel_id'] ?? 0));

$febRaw = (string)($_POST['feb_services_mode'] ?? '');
$febSelectedMode = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? strtolower(trim((string)($_POST['feb_services_mode'] ?? 'compat')))
    : feb_services_mode_from_raw((string)($user['feb_validation_services'] ?? ''));
if (!in_array($febSelectedMode, ['all', 'custom', 'compat'], true)) {
    $febSelectedMode = 'compat';
}

$febSelectedIds = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? (is_array($_POST['feb_services'] ?? null) ? array_values(array_unique(array_map('intval', (array)$_POST['feb_services']))) : [])
    : feb_services_ids_from_raw((string)($user['feb_validation_services'] ?? ''));
$febSelectedLookup = [];
foreach ($febSelectedIds as $sid) {
    if ($sid > 0) {
        $febSelectedLookup[(int)$sid] = true;
    }
}

$pageTitle = 'Modifier utilisateur';
$activeMenu = '';

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>


<div class="min-h-screen bg-gradient-to-br from-indigo-50 via-white to-purple-50">

    <div class="container mx-auto py-10 px-4">

        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-10">

            <div>

                <h1 class="text-4xl font-bold text-gray-800">
                    Modifier un utilisateur
                </h1>

                <p class="mt-2 text-gray-600">
                    Mettre à jour les informations et permissions.
                </p>

                <p class="mt-1 text-sm text-gray-500">
                    Utilisateur :
                    <span class="font-semibold">
                        <?= htmlspecialchars($user['prenom'] . ' ' . $user['nom']) ?>
                    </span>
                </p>

            </div>


            <div class="flex flex-wrap gap-3">

                <a href="liste_users.php"
                    class="inline-flex items-center gap-2 px-5 py-3 bg-white border border-gray-200 rounded-xl shadow-sm hover:shadow-md text-gray-700 transition">

                    <i class="fas fa-arrow-left"></i>
                    Retour

                </a>

                <a href="dashboard_admin.php"
                    class="inline-flex items-center gap-2 px-5 py-3 bg-indigo-600 text-white rounded-xl shadow hover:bg-indigo-700 transition">

                    <i class="fas fa-tachometer-alt"></i>
                    Dashboard

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

                <input type="hidden" name="id" value="<?= $userId ?>">

                <div class="bg-gray-50 border border-gray-200 rounded-xl p-6">

                    <h3 class="text-lg font-semibold text-gray-800 mb-4">
                        Personnel lié (optionnel)
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
                                Sélectionner
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
                                    $linkedTo = $linkedPersonnelToUser[$pid] ?? 0;
                                    $disabled = ($pid > 0 && $linkedTo > 0 && $linkedTo !== (int)$userId);
                                    $isSelected = ($pid > 0 && $pid === (int)$selectedPersonnelId);

                                    $labelParts = [trim($fullName)];
                                    if (trim($service) !== '') {
                                        $labelParts[] = trim($service);
                                    }
                                    if (trim($email) !== '') {
                                        $labelParts[] = trim($email);
                                    }
                                    if ($disabled) {
                                        $labelParts[] = 'déjà lié';
                                    }
                                    $label = implode(' — ', array_filter($labelParts, static function ($v) {
                                        return $v !== '';
                                    }));
                                ?>

                                    <option
                                        value="<?= $pid ?>"
                                        <?= $disabled && !$isSelected ? 'disabled' : '' ?>
                                        <?= $isSelected ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($label) ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                            <p class="mt-2 text-xs text-gray-500">
                                Un membre du personnel ne peut être lié qu’à un seul compte.
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
                            value="<?= htmlspecialchars(strtolower($user['mail_pro'])) ?>"
                            class="mt-1 block w-full rounded-xl border-gray-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />

                    </div>


                    <div>

                        <label class="block text-sm font-medium text-gray-700">
                            Mot de passe (laisser vide pour conserver)
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
                            value="<?= htmlspecialchars($user['prenom']) ?>"
                            class="mt-1 block w-full rounded-xl border-gray-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />

                    </div>


                    <div>

                        <label class="block text-sm font-medium text-gray-700">
                            Nom
                        </label>

                        <input
                            name="nom"
                            required
                            value="<?= htmlspecialchars($user['nom']) ?>"
                            class="mt-1 block w-full rounded-xl border-gray-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />

                    </div>

                </div>


                <div class="bg-amber-50 border border-amber-200 rounded-xl p-6">

                    <h3 class="text-lg font-semibold text-amber-900 mb-1">Validation FEB par service</h3>
                    <p class="text-sm text-amber-900/80">Choisissez quels services (bénéficiaires) cet utilisateur peut valider côté Finances. Stockage: <span class="font-semibold">user_devis_banamur.feb_validation_services</span>.</p>

                    <div class="mt-4 grid grid-cols-1 lg:grid-cols-[260px_1fr] gap-4">
                        <div class="space-y-3">
                            <label class="flex items-start gap-3 rounded-xl bg-white border border-amber-200 px-4 py-3">
                                <input type="radio" name="feb_services_mode" value="all" class="mt-1" <?= $febSelectedMode === 'all' ? 'checked' : '' ?>>
                                <div>
                                    <div class="text-sm font-semibold text-amber-900">Tous les services</div>
                                    <div class="text-xs text-amber-800/80">Enregistre <span class="font-semibold">*</span> (pas d'alerte “non configuré”).</div>
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
                                    <div class="text-xs text-amber-800/80">Accès global autorisé par défaut (affiche un avertissement dans Finances).</div>
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
                                    $checked = $isUniversal || !empty($user[$key]);

                                ?>

                                    <label class="permission-card flex items-center gap-3 p-3 rounded-xl border cursor-pointer transition
<?= $checked ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-gray-100 text-gray-600 border-gray-200' ?>">

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


                    <label class="flex items-center gap-3 mt-6">

                        <input
                            type="checkbox"
                            name="active"
                            <?= $user['active'] ? 'checked' : '' ?>>

                        <span class="text-sm text-gray-700">
                            Compte actif
                        </span>

                    </label>

                    <label class="mt-4 flex items-start gap-3 rounded-2xl border border-violet-200 bg-violet-50 p-4">
                        <input type="checkbox" name="passkey_only" class="mt-1 h-5 w-5" <?= !empty($userPasskeyPolicy['passkey_only'])?'checked':'' ?>>
                        <span><b class="block text-violet-950">Imposer la connexion uniquement via Passkey</b><small class="mt-1 block text-violet-700">Si aucune Passkey n’existe encore, une seule connexion classique sera autorisée et l’utilisateur sera bloqué sur sa configuration.</small></span>
                    </label>


                    <div class="flex flex-wrap items-center gap-3 mt-6">

                        <button
                            type="submit"
                            class="inline-flex items-center gap-2 px-6 py-3 bg-indigo-600 text-white rounded-xl shadow hover:bg-indigo-700 transition">

                            <i class="fas fa-save"></i>
                            Enregistrer

                        </button>


                        <a href="liste_users.php"
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
    document.querySelectorAll('.permission-checkbox').forEach(cb => {

        const card = cb.closest('.permission-card')

        const update = () => {

            if (cb.checked) {

                card.classList.remove('bg-gray-100', 'text-gray-600', 'border-gray-200')

                card.classList.add('bg-indigo-600', 'text-white', 'border-indigo-600')

            } else {

                card.classList.remove('bg-indigo-600', 'text-white', 'border-indigo-600')

                card.classList.add('bg-gray-100', 'text-gray-600', 'border-gray-200')

            }

        }

        update()

        cb.addEventListener('change', update)

    })

    ;
    (() => {
        const personnelSearch = document.getElementById('personnel_search')
        const personnelSelect = document.getElementById('personnel_id')

        if (!personnelSearch || !personnelSelect) return

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

        personnelSearch.addEventListener('input', filterOptions)
        filterOptions()
    })()

    ;
    (() => {
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

