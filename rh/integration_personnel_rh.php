<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../controllers/RhPersonnelController.php';
require_once __DIR__ . '/includes/personnel_helpers.php';

$pdo = \Database::getConnection();
$directory = (new \RhPersonnelController($pdo))->service();

$actor = [
    'user_id' => isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
    'user_name' => isset($_SESSION['user_name']) ? trim((string) $_SESSION['user_name']) : null,
];

$statusType = null;
$statusMessage = null;
$requestedCategory=in_array((string)($_GET['category']??$_POST['personnel_category']??'interne'),['interne','prestataire'],true)?(string)($_GET['category']??$_POST['personnel_category']):'interne';
$formData = ['personnel_category'=>$requestedCategory];

$_SESSION['rh_identity_csrf']=$_SESSION['rh_identity_csrf']??bin2hex(random_bytes(24));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals((string)$_SESSION['rh_identity_csrf'],(string)($_POST['_rh_identity_csrf']??''))) throw new RuntimeException('Session expirée. Rechargez le formulaire RH.');
        $childrenBirthDates = array_values((array) ($_POST['children_birth_date'] ?? []));
        $formData = [
            'full_name'               => trim((string) ($_POST['full_name'] ?? '')),
            'gender_code'             => (string) ($_POST['gender_code'] ?? ''),
            'birth_date'              => (string) ($_POST['birth_date'] ?? ''),
            'phone'                   => trim((string) ($_POST['phone'] ?? '')),
            'email'                   => trim((string) ($_POST['email'] ?? '')),
            'service_id'              => (string) ($_POST['service_id'] ?? ''),
            'fonction_id'             => (string) ($_POST['fonction_id'] ?? ''),
            'status_code'             => (string) ($_POST['status_code'] ?? ''),
            'personnel_category'      => in_array((string)($_POST['personnel_category']??'interne'),['interne','prestataire'],true)?(string)$_POST['personnel_category']:'interne',
            'site_id'                 => (string) ($_POST['site_id'] ?? ''),
            'hire_date'               => (string) ($_POST['hire_date'] ?? ''),
            'start_date'              => (string) ($_POST['start_date'] ?? ''),
            'marital_status'          => trim((string) ($_POST['marital_status'] ?? '')),
            'father_name'             => trim((string) ($_POST['father_name'] ?? '')),
            'father_phone'            => trim((string) ($_POST['father_phone'] ?? '')),
            'mother_name'             => trim((string) ($_POST['mother_name'] ?? '')),
            'mother_phone'            => trim((string) ($_POST['mother_phone'] ?? '')),
            'cni_number'              => trim((string) ($_POST['cni_number'] ?? '')),
            'cnps_number'             => trim((string) ($_POST['cnps_number'] ?? '')),
            'emergency_contact_name'  => trim((string) ($_POST['emergency_contact_name'] ?? '')),
            'emergency_contact_phone' => trim((string) ($_POST['emergency_contact_phone'] ?? '')),
            'contract_duration_months' => (string) ($_POST['contract_duration_months'] ?? ''),
            'has_children'            => (string) ($_POST['has_children'] ?? ''),
            'children_count'          => (string) ($_POST['children_count'] ?? ''),
        ];

        if ($formData['full_name'] === '') {
            throw new RuntimeException('Le nom complet est obligatoire.');
        }

        if ($formData['has_children'] === '' && $formData['children_count'] !== '') {
            $formData['has_children'] = ((int) $formData['children_count'] > 0) ? '1' : '0';
        }

        $childrenCount = max(0, min(20, (int) ($formData['children_count'] !== '' ? $formData['children_count'] : 0)));
        if ($childrenCount === 0) {
            $formData['has_children'] = '0';
            $formData['children_count'] = '0';
        }
        if ($formData['has_children'] === '0') {
            $childrenCount = 0;
            $formData['children_count'] = '0';
        }
        if ($childrenCount > 0) {
            $formData['has_children'] = '1';
            for ($i = 0; $i < $childrenCount; $i++) {
                $birthDate = trim((string) ($childrenBirthDates[$i] ?? ''));
                if ($birthDate === '') {
                    throw new RuntimeException('La date de naissance de chaque enfant est obligatoire.');
                }
                $dt = DateTime::createFromFormat('Y-m-d', $birthDate);
                if (!$dt || $dt->format('Y-m-d') !== $birthDate) {
                    throw new RuntimeException('Une date de naissance enfant est invalide.');
                }
                if (!isset($_FILES['children_birth_certificate']['error'][$i]) || (int) $_FILES['children_birth_certificate']['error'][$i] === UPLOAD_ERR_NO_FILE) {
                    throw new RuntimeException('L extrait de naissance de chaque enfant est obligatoire.');
                }
            }
        }

        PersonIdentityGuard::normalize((string)$formData['cni_number']);
        if (!rhHasUploadedFile('identity_document_file')) throw new DomainException('Le numéro et la pièce d’identité jointe sont obligatoires.');
        if (rhHasUploadedFile('identity_document_file')) $formData['identity_document_path'] = rhStoreUploadedDocument($_FILES['identity_document_file'], 'piece-identite', 0);
        $newId = $directory->createPersonnel($formData, $actor);
        $createdEmployee = $directory->getPersonnelDetail($newId);

        $uploadMap = [
            'photo_file'              => ['alias' => 'photo',                    'folder' => 'photo'],
            'birth_certificate_file'  => ['alias' => 'birth_certificate_path',   'folder' => 'extrait-naissance'],

            'last_degree_file'        => ['alias' => 'last_degree_path',         'folder' => 'diplome'],
            'employment_contract_file' => ['alias' => 'employment_contract_path', 'folder' => 'contrat-embauche'],
        ];

        $documentUpdates = [];
        foreach ($uploadMap as $fieldName => $config) {
            if (!rhHasUploadedFile($fieldName)) {
                continue;
            }
            $documentUpdates[$config['alias']] = rhStoreUploadedDocument($_FILES[$fieldName], $config['folder'], $newId);
        }

        if (!empty($documentUpdates)) {
            $directory->updatePersonnelDocuments($newId, $documentUpdates, $actor);
        }

        if ($childrenCount > 0) {
            $children = [];
            for ($i = 0; $i < $childrenCount; $i++) {
                $childFile = [
                    'name' => $_FILES['children_birth_certificate']['name'][$i] ?? '',
                    'type' => $_FILES['children_birth_certificate']['type'][$i] ?? '',
                    'tmp_name' => $_FILES['children_birth_certificate']['tmp_name'][$i] ?? '',
                    'error' => $_FILES['children_birth_certificate']['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                    'size' => $_FILES['children_birth_certificate']['size'][$i] ?? 0,
                ];
                $children[] = [
                    'birth_date' => trim((string) ($childrenBirthDates[$i] ?? '')),
                    'birth_certificate_path' => rhStoreUploadedDocument($childFile, 'extrait-naissance-enfant', $newId),
                ];
            }
            $directory->replacePersonnelChildren($newId, $children, $actor);
        }

        \AuditTrail::log('personnel_created', [
            'module' => 'rh',
            'action' => 'creation_personnel',
            'entity_type' => 'personnel',
            'entity_id' => (string) $newId,
            'entity_label' => (string) ($formData['full_name'] ?? ('Personnel #' . $newId)),
            'details' => [
                'matricule' => $createdEmployee['matricule'] ?? null,
                'service_id' => $formData['service_id'] ?? null,
                'fonction_id' => $formData['fonction_id'] ?? null,
                'status_code' => $formData['status_code'] ?? null,
                'site_id' => $formData['site_id'] ?? null,
                'documents_uploades' => array_keys($documentUpdates),
            ],
        ], $pdo);

        header('Location: dossier_personnel_rh.php?id=' . $newId . '&integrated=1');
        exit;
    } catch (Throwable $e) {
        \AuditTrail::log('personnel_create_failed', [
            'module' => 'rh',
            'action' => 'creation_personnel',
            'entity_type' => 'personnel',
            'entity_label' => (string) ($formData['full_name'] ?? ''),
            'result' => 'failed',
            'severity' => 'error',
            'details' => [
                'message' => $e->getMessage(),
                'service_id' => $formData['service_id'] ?? null,
            ],
        ], $pdo);
        $statusType = 'error';
        $statusMessage = $e->getMessage();
    }
}

$filterOptions = $directory->getFilters();

$pageTitle = $requestedCategory==='prestataire'?'Intégration d’un prestataire':'Intégration du personnel';
$activeMenu = 'rh';

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>
<div class="min-h-screen bg-[radial-gradient(circle_at_top_left,_rgba(236,253,245,0.9),_transparent_42%),radial-gradient(circle_at_top_right,_rgba(191,219,254,0.8),_transparent_35%),linear-gradient(180deg,_#f8fafc_0%,_#eef2ff_100%)] pb-12">
    <div class="container mx-auto px-4 py-8">

        <!-- En-tête -->
        <div class="relative overflow-hidden rounded-[32px] border border-emerald-100 bg-gradient-to-r from-slate-950 via-slate-900 to-emerald-900 px-8 py-10 text-white shadow-[0_24px_80px_rgba(15,23,42,0.32)]">
            <div class="absolute -right-16 -top-16 h-48 w-48 rounded-full bg-white/10 blur-2xl"></div>
            <div class="absolute bottom-0 right-24 h-24 w-24 rounded-full bg-emerald-300/20 blur-2xl"></div>
            <div class="relative flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="mb-3 text-xs font-semibold uppercase tracking-[0.35em] text-emerald-200">Ressources humaines</p>
                    <h1 class="text-3xl font-semibold tracking-tight md:text-5xl"><?=$requestedCategory==='prestataire'?"Intégration d'un prestataire":"Intégration d'un collaborateur"?></h1>
                    <p class="mt-4 max-w-2xl text-sm text-slate-200 md:text-base">
                        <?=$requestedCategory==='prestataire'?"Créez un dossier distinct pour un prestataire ou consultant externe.":"Créez le dossier RH complet d'un collaborateur interne : identité, affectation, contrat et pièces justificatives."?>
                    </p>
                </div>
                <div class="flex flex-wrap gap-3">
                    <a href="liste_personnel_rh.php" class="inline-flex items-center rounded-2xl border border-white/20 px-5 py-3 text-sm font-semibold text-white/90 transition hover:bg-white/10">
                        <i class="fas fa-id-badge mr-2"></i>
                        Liste du personnel
                    </a>
                    <a href="dashboard_rh.php" class="inline-flex items-center rounded-2xl border border-white/20 px-5 py-3 text-sm font-semibold text-white/90 transition hover:bg-white/10">
                        <i class="fas fa-tachometer-alt mr-2"></i>
                        Tableau de bord RH
                    </a>
                </div>
            </div>
        </div>

        <?php if ($statusType !== null && $statusMessage !== null): ?>
            <div class="mt-6 rounded-2xl border px-5 py-4 text-sm font-medium <?= $statusType === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-rose-200 bg-rose-50 text-rose-800' ?>">
                <i class="fas <?= $statusType === 'success' ? 'fa-check-circle text-emerald-500' : 'fa-exclamation-circle text-rose-500' ?> mr-2"></i>
                <?= htmlspecialchars((string) $statusMessage) ?>
            </div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" class="mt-8 space-y-6" autocomplete="off"><input type="hidden" name="_rh_identity_csrf" value="<?= htmlspecialchars((string)$_SESSION['rh_identity_csrf']) ?>"><input type="hidden" name="personnel_category" value="<?=htmlspecialchars($requestedCategory)?>">

            <!-- Identite -->
            <section class="rounded-[30px] border border-white/80 bg-white/90 p-7 shadow-[0_18px_60px_rgba(15,23,42,0.07)] backdrop-blur">
                <div class="mb-6">
                    <p class="text-xs font-semibold uppercase tracking-[0.28em] text-emerald-600">Etape 1</p>
                    <h2 class="mt-1 text-xl font-semibold text-slate-950">Identite du collaborateur</h2>
                </div>
                <div class="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
                    <div class="xl:col-span-2">
                        <label class="mb-2 block text-sm font-medium text-slate-700">Nom complet <span class="text-rose-500">*</span></label>
                        <input type="text" name="full_name" value="<?= htmlspecialchars((string) ($formData['full_name'] ?? '')) ?>"
                            placeholder="Prénom(s) NOM"
                            required
                            class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-emerald-400 focus:ring-2 focus:ring-emerald-100">
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-slate-700">Matricule</label>
                        <div class="rounded-2xl border border-emerald-100 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                            <span class="font-semibold">Auto-genere</span>
                            <span class="mt-1 block text-xs text-emerald-700">Selon le type de contrat et l'ordre d'integration.</span>
                        </div>
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-slate-700">Sexe</label>
                        <select name="gender_code" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-emerald-400 focus:ring-2 focus:ring-emerald-100">
                            <option value="">Non renseigne</option>
                            <option value="1" <?= ($formData['gender_code'] ?? '') === '1' ? 'selected' : '' ?>>Masculin</option>
                            <option value="2" <?= ($formData['gender_code'] ?? '') === '2' ? 'selected' : '' ?>>Feminin</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-slate-700">Date de naissance</label>
                        <input type="date" name="birth_date" value="<?= htmlspecialchars((string) ($formData['birth_date'] ?? '')) ?>"
                            class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-emerald-400 focus:ring-2 focus:ring-emerald-100">
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-slate-700">Situation matrimoniale</label>
                        <select name="marital_status" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-emerald-400 focus:ring-2 focus:ring-emerald-100">
                            <option value="">Non renseignee</option>
                            <?php foreach (['Celibataire', 'Marie(e)', 'Divorce(e)', 'Veuf(ve)'] as $ms): ?>
                                <option value="<?= htmlspecialchars($ms) ?>" <?= ($formData['marital_status'] ?? '') === $ms ? 'selected' : '' ?>><?= htmlspecialchars($ms) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-slate-700">CNI</label>
                        <input type="text" name="cni_number" required maxlength="110" value="<?= htmlspecialchars((string) ($formData['cni_number'] ?? '')) ?>"
                            placeholder="Numero CNI"
                            class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-emerald-400 focus:ring-2 focus:ring-emerald-100">
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-slate-700">CNPS</label>
                        <input type="text" name="cnps_number" value="<?= htmlspecialchars((string) ($formData['cnps_number'] ?? '')) ?>"
                            placeholder="Numero CNPS"
                            class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-emerald-400 focus:ring-2 focus:ring-emerald-100">
                    </div>
                </div>
            </section>

            <!-- Affectation et contrat -->
            <section class="rounded-[30px] border border-white/80 bg-white/90 p-7 shadow-[0_18px_60px_rgba(15,23,42,0.07)] backdrop-blur">
                <div class="mb-6">
                    <p class="text-xs font-semibold uppercase tracking-[0.28em] text-sky-600">Etape 2</p>
                    <h2 class="mt-1 text-xl font-semibold text-slate-950">Affectation et contrat</h2>
                </div>
                <div class="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
                    <div>
                        <label class="mb-2 block text-sm font-medium text-slate-700">Service</label>
                        <select name="service_id" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-sky-400 focus:ring-2 focus:ring-sky-100">
                            <option value="">Non renseigne</option>
                            <?php foreach ($filterOptions['services'] as $service): ?>
                                <option value="<?= (int) $service['id'] ?>" <?= (string) ($formData['service_id'] ?? '') === (string) $service['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string) $service['label']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-slate-700">Fonction</label>
                        <select name="fonction_id" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-sky-400 focus:ring-2 focus:ring-sky-100">
                            <option value="">Non renseignee</option>
                            <?php foreach ($filterOptions['fonctions'] as $fonction): ?>
                                <option value="<?= (int) $fonction['id'] ?>" <?= (string) ($formData['fonction_id'] ?? '') === (string) $fonction['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string) $fonction['label']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-slate-700">Statut / type de contrat</label>
                        <select name="status_code" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-sky-400 focus:ring-2 focus:ring-sky-100">
                            <option value="">Non renseigne</option>
                            <?php foreach ($filterOptions['statuts'] as $statut): ?>
                                <option value="<?= (int) $statut['id'] ?>" <?= (string) ($formData['status_code'] ?? '') === (string) $statut['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string) $statut['label']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-slate-700">Site</label>
                        <select name="site_id" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-sky-400 focus:ring-2 focus:ring-sky-100">
                            <option value="">Non renseigne</option>
                            <?php foreach ($filterOptions['sites'] as $site): ?>
                                <option value="<?= (int) $site['id'] ?>" <?= (string) ($formData['site_id'] ?? '') === (string) $site['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string) $site['label']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-slate-700">Date de recrutement</label>
                        <input type="date" name="hire_date" value="<?= htmlspecialchars((string) ($formData['hire_date'] ?? '')) ?>"
                            class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-sky-400 focus:ring-2 focus:ring-sky-100">
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-slate-700">Date de prise de service</label>
                        <input type="date" name="start_date" value="<?= htmlspecialchars((string) ($formData['start_date'] ?? '')) ?>"
                            class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-sky-400 focus:ring-2 focus:ring-sky-100">
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-slate-700">Duree du contrat (mois)</label>
                        <input type="number" name="contract_duration_months" min="0" value="<?= htmlspecialchars((string) ($formData['contract_duration_months'] ?? '')) ?>"
                            placeholder="Ex : 12"
                            class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-sky-400 focus:ring-2 focus:ring-sky-100">
                        <p class="mt-1 text-xs text-slate-400">Laisser vide pour CDI / indetermine.</p>
                    </div>
                </div>
            </section>

            <!-- Contacts -->
            <section class="rounded-[30px] border border-white/80 bg-white/90 p-7 shadow-[0_18px_60px_rgba(15,23,42,0.07)] backdrop-blur">
                <div class="mb-6">
                    <p class="text-xs font-semibold uppercase tracking-[0.28em] text-indigo-600">Etape 3</p>
                    <h2 class="mt-1 text-xl font-semibold text-slate-950">Contacts et famille</h2>
                </div>
                <div class="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
                    <div>
                        <label class="mb-2 block text-sm font-medium text-slate-700">Telephone</label>
                        <input type="tel" name="phone" value="<?= htmlspecialchars((string) ($formData['phone'] ?? '')) ?>"
                            placeholder="+225 XX XX XX XX"
                            class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100">
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-slate-700">E-mail professionnel</label>
                        <input type="email" name="email" value="<?= htmlspecialchars((string) ($formData['email'] ?? '')) ?>"
                            placeholder="prenom.nom@banamur.ci"
                            class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100">
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-slate-700">Nom du pere</label>
                        <input type="text" name="father_name" value="<?= htmlspecialchars((string) ($formData['father_name'] ?? '')) ?>"
                            class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100">
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-slate-700">Telephone du pere</label>
                        <input type="tel" name="father_phone" value="<?= htmlspecialchars((string) ($formData['father_phone'] ?? '')) ?>"
                            class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100">
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-slate-700">Nom de la mere</label>
                        <input type="text" name="mother_name" value="<?= htmlspecialchars((string) ($formData['mother_name'] ?? '')) ?>"
                            class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100">
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-slate-700">Telephone de la mere</label>
                        <input type="tel" name="mother_phone" value="<?= htmlspecialchars((string) ($formData['mother_phone'] ?? '')) ?>"
                            class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100">
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-slate-700">Contact urgence (nom)</label>
                        <input type="text" name="emergency_contact_name" value="<?= htmlspecialchars((string) ($formData['emergency_contact_name'] ?? '')) ?>"
                            class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100">
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-slate-700">Contact urgence (telephone)</label>
                        <input type="tel" name="emergency_contact_phone" value="<?= htmlspecialchars((string) ($formData['emergency_contact_phone'] ?? '')) ?>"
                            class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100">
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="mb-2 block text-sm font-medium text-slate-700">A des enfants</label>
                            <select name="has_children" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100">
                                <option value="">-</option>
                                <option value="1" <?= ($formData['has_children'] ?? '') === '1' ? 'selected' : '' ?>>Oui</option>
                                <option value="0" <?= ($formData['has_children'] ?? '') === '0' ? 'selected' : '' ?>>Non</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-slate-700">Nombre</label>
                            <input type="number" name="children_count" min="0" max="20" value="<?= htmlspecialchars((string) ($formData['children_count'] ?? '')) ?>"
                                class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100">
                        </div>
                    </div>
                    <div id="children-details" class="sm:col-span-2 xl:col-span-3 hidden rounded-2xl border border-indigo-100 bg-indigo-50 p-4">
                        <div class="mb-3 flex items-center justify-between gap-3">
                            <div>
                                <div class="text-sm font-semibold text-indigo-950">Enfants declares</div>
                                <div class="text-xs text-indigo-700">Date de naissance et extrait de naissance obligatoires pour chaque enfant.</div>
                            </div>
                        </div>
                        <div id="children-details-list" class="grid gap-3 md:grid-cols-2"></div>
                    </div>
                </div>
            </section>

            <!-- Pieces justificatives -->
            <section class="rounded-[30px] border border-white/80 bg-white/90 p-7 shadow-[0_18px_60px_rgba(15,23,42,0.07)] backdrop-blur">
                <div class="mb-6">
                    <p class="text-xs font-semibold uppercase tracking-[0.28em] text-amber-600">Etape 4 (optionnelle)</p>
                    <h2 class="mt-1 text-xl font-semibold text-slate-950">Pieces justificatives</h2>
                    <p class="mt-1 text-sm text-slate-500">Formats acceptes : PDF, DOC, DOCX, JPG, JPEG, PNG, WEBP. Le numéro et la pièce d’identité sont obligatoires dès la création.</p>
                </div>
                <div class="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
                    <?php
                    $documentFields = [
                        ['label' => 'Photo de profil',       'name' => 'photo_file',               'icon' => 'fa-camera-retro'],
                        ['label' => 'Extrait de naissance',  'name' => 'birth_certificate_file',  'icon' => 'fa-file-lines'],
                        ['label' => "Piece d'identite / CNI", 'name' => 'identity_document_file',  'icon' => 'fa-id-card'],
                        ['label' => 'Dernier diplome',       'name' => 'last_degree_file',         'icon' => 'fa-graduation-cap'],
                        ['label' => "Contrat d'embauche",    'name' => 'employment_contract_file', 'icon' => 'fa-file-signature'],
                    ];
                    foreach ($documentFields as $doc):
                    ?>
                        <div class="rounded-2xl border border-slate-100 bg-slate-50 p-5">
                            <div class="mb-3 flex items-center gap-3">
                                <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-amber-100 text-amber-600">
                                    <i class="fas <?= $doc['icon'] ?> text-sm"></i>
                                </div>
                                <span class="text-sm font-medium text-slate-700"><?= htmlspecialchars($doc['label']) ?></span>
                            </div>
                            <input type="file" name="<?= $doc['name'] ?>" <?= $doc['name']==='identity_document_file'?'required':'' ?> accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.webp"
                                class="w-full rounded-xl border border-slate-200 bg-white text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-xs file:font-semibold file:text-slate-700 hover:file:bg-slate-200">
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- Actions -->
            <div class="flex flex-wrap items-center justify-between gap-4 rounded-[30px] border border-white/80 bg-white/90 p-6 shadow-[0_18px_60px_rgba(15,23,42,0.07)] backdrop-blur">
                <div class="text-sm text-slate-500">
                    <i class="fas fa-info-circle mr-1 text-slate-400"></i>
                    Les champs marques <span class="text-rose-500 font-semibold">*</span> sont obligatoires.
                    Les autres peuvent etre completes depuis le dossier RH.
                </div>
                <div class="flex flex-wrap gap-3">
                    <a href="liste_personnel_rh.php" class="rounded-2xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">
                        Annuler
                    </a>
                    <button type="submit" class="inline-flex items-center rounded-2xl bg-emerald-600 px-6 py-3 text-sm font-semibold text-white shadow-lg shadow-emerald-600/20 transition hover:bg-emerald-700">
                        <i class="fas fa-user-plus mr-2"></i>
                        Creer le dossier RH
                    </button>
                </div>
            </div>

        </form>
    </div>
</div>
<script>
    (() => {
        const countInput = document.querySelector('input[name="children_count"]');
        const hasChildrenSelect = document.querySelector('select[name="has_children"]');
        const wrapper = document.getElementById('children-details');
        const list = document.getElementById('children-details-list');
        if (!countInput || !wrapper || !list) return;

        const render = () => {
            const count = Math.max(0, Math.min(20, parseInt(countInput.value || '0', 10) || 0));
            wrapper.classList.toggle('hidden', count <= 0);
            if (hasChildrenSelect) hasChildrenSelect.value = count > 0 ? '1' : '0';
            list.innerHTML = '';
            for (let i = 0; i < count; i++) {
                const row = document.createElement('div');
                row.className = 'rounded-2xl border border-indigo-100 bg-white p-4';
                row.innerHTML = `
                    <div class="mb-3 text-sm font-semibold text-slate-900">Enfant ${i + 1}</div>
                    <label class="mb-2 block text-xs font-semibold text-slate-600">Date de naissance</label>
                    <input type="date" name="children_birth_date[]" required class="mb-3 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm outline-none focus:border-indigo-400">
                    <label class="mb-2 block text-xs font-semibold text-slate-600">Extrait de naissance</label>
                    <input type="file" name="children_birth_certificate[]" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.webp" required class="w-full rounded-xl border border-slate-200 bg-white text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-xs file:font-semibold file:text-slate-700">
                `;
                list.appendChild(row);
            }
        };

        countInput.addEventListener('input', render);
        hasChildrenSelect?.addEventListener('change', () => {
            if (hasChildrenSelect.value === '0') countInput.value = '0';
            if (hasChildrenSelect.value === '1' && (parseInt(countInput.value || '0', 10) || 0) <= 0) countInput.value = '1';
            render();
        });
        render();
    })();
</script>
<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>

