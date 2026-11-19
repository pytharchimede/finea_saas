<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../controllers/RhPersonnelController.php';
require_once __DIR__ . '/includes/personnel_helpers.php';

$personnelId = max(0, (int) ($_GET['id'] ?? $_POST['id'] ?? 0));
$pdo = \Database::getConnection();
$directory = (new \RhPersonnelController($pdo))->service();
$employee = $directory->getPersonnelDetail($personnelId);
if ($employee === null) {
    header('Location: liste_personnel_rh.php?missing=1');
    exit;
}

$actor = [
    'user_id' => isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
    'user_name' => isset($_SESSION['user_name']) ? trim((string) $_SESSION['user_name']) : null,
];

$statusType = null;
$statusMessage = null;
$existingChildren = $directory->getPersonnelChildren($personnelId);

$_SESSION['rh_identity_csrf']=$_SESSION['rh_identity_csrf']??bin2hex(random_bytes(24));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals((string)$_SESSION['rh_identity_csrf'],(string)($_POST['_rh_identity_csrf']??''))) throw new RuntimeException('Session expirée. Rechargez le formulaire RH.');
        $childrenBirthDates = array_values((array) ($_POST['children_birth_date'] ?? []));
        $payload = [
            'full_name' => trim((string) ($_POST['full_name'] ?? '')),
            'gender_code' => (string) ($_POST['gender_code'] ?? ''),
            'birth_date' => (string) ($_POST['birth_date'] ?? ''),
            'phone' => trim((string) ($_POST['phone'] ?? '')),
            'email' => trim((string) ($_POST['email'] ?? '')),
            'service_id' => (string) ($_POST['service_id'] ?? ''),
            'fonction_id' => (string) ($_POST['fonction_id'] ?? ''),
            'status_code' => (string) ($_POST['status_code'] ?? ''),
            'site_id' => (string) ($_POST['site_id'] ?? ''),
            'hire_date' => (string) ($_POST['hire_date'] ?? ''),
            'start_date' => (string) ($_POST['start_date'] ?? ''),
            'end_date' => (string) ($_POST['end_date'] ?? ''),
            'exit_reason_id' => (string) ($_POST['exit_reason_id'] ?? ''),
            'marital_status' => trim((string) ($_POST['marital_status'] ?? '')),
            'father_name' => trim((string) ($_POST['father_name'] ?? '')),
            'father_phone' => trim((string) ($_POST['father_phone'] ?? '')),
            'mother_name' => trim((string) ($_POST['mother_name'] ?? '')),
            'mother_phone' => trim((string) ($_POST['mother_phone'] ?? '')),
            'cni_number' => trim((string) ($_POST['cni_number'] ?? '')),
            'cnps_number' => trim((string) ($_POST['cnps_number'] ?? '')),
            'emergency_contact_name' => trim((string) ($_POST['emergency_contact_name'] ?? '')),
            'emergency_contact_phone' => trim((string) ($_POST['emergency_contact_phone'] ?? '')),
            'contract_duration_months' => (string) ($_POST['contract_duration_months'] ?? ''),
            'has_children' => (string) ($_POST['has_children'] ?? ''),
            'children_count' => (string) ($_POST['children_count'] ?? ''),
        ];
        if ($payload['full_name'] === '') {
            throw new RuntimeException('Le nom complet est obligatoire.');
        }
        if ($payload['has_children'] === '' && $payload['children_count'] !== '') {
            $payload['has_children'] = ((int) $payload['children_count'] > 0) ? '1' : '0';
        }
        $childrenCount = max(0, min(20, (int) ($payload['children_count'] !== '' ? $payload['children_count'] : 0)));
        if ($childrenCount === 0) {
            $payload['has_children'] = '0';
            $payload['children_count'] = '0';
        }
        if ($payload['has_children'] === '0') {
            $childrenCount = 0;
            $payload['children_count'] = '0';
        }
        if ($childrenCount > 0) {
            $payload['has_children'] = '1';
            for ($i = 0; $i < $childrenCount; $i++) {
                $existingChild = $existingChildren[$i] ?? [];
                $birthDate = trim((string) ($childrenBirthDates[$i] ?? ($existingChild['child_birth_date'] ?? '')));
                if ($birthDate === '') {
                    throw new RuntimeException('La date de naissance de chaque enfant est obligatoire.');
                }
                $dt = DateTime::createFromFormat('Y-m-d', $birthDate);
                if (!$dt || $dt->format('Y-m-d') !== $birthDate) {
                    throw new RuntimeException('Une date de naissance enfant est invalide.');
                }
                $hasExistingCertificate = trim((string) ($existingChild['birth_certificate_path'] ?? '')) !== '';
                $hasNewCertificate = isset($_FILES['children_birth_certificate']['error'][$i])
                    && (int) $_FILES['children_birth_certificate']['error'][$i] !== UPLOAD_ERR_NO_FILE;
                if (!$hasExistingCertificate && !$hasNewCertificate) {
                    throw new RuntimeException('Le nombre d extraits de naissance doit correspondre au nombre d enfants saisi.');
                }
            }
        } else {
            $payload['has_children'] = '0';
        }

        PersonIdentityGuard::normalize((string)$payload['cni_number']);
        if (rhHasUploadedFile('identity_document_file')) $payload['identity_document_path'] = rhStoreUploadedDocument($_FILES['identity_document_file'], 'piece-identite', $personnelId);
        $directory->updatePersonnel($personnelId, $payload, $actor);

        $uploadMap = [
            'photo_file' => ['alias' => 'photo', 'folder' => 'photo'],
            'birth_certificate_file' => ['alias' => 'birth_certificate_path', 'folder' => 'extrait-naissance'],

            'last_degree_file' => ['alias' => 'last_degree_path', 'folder' => 'diplome'],
            'employment_contract_file' => ['alias' => 'employment_contract_path', 'folder' => 'contrat-embauche'],
        ];
        $documentUpdates = [];
        foreach ($uploadMap as $fieldName => $config) {
            if (rhHasUploadedFile($fieldName)) {
                $documentUpdates[$config['alias']] = rhStoreUploadedDocument($_FILES[$fieldName], $config['folder'], $personnelId);
            }
        }
        if ($documentUpdates) {
            $directory->updatePersonnelDocuments($personnelId, $documentUpdates, $actor);
        }

        $children = [];
        for ($i = 0; $i < $childrenCount; $i++) {
            $existingChild = $existingChildren[$i] ?? [];
            $certificatePath = trim((string) ($existingChild['birth_certificate_path'] ?? ''));
            if (isset($_FILES['children_birth_certificate']['error'][$i]) && (int) $_FILES['children_birth_certificate']['error'][$i] !== UPLOAD_ERR_NO_FILE) {
                $childFile = [
                    'name' => $_FILES['children_birth_certificate']['name'][$i] ?? '',
                    'type' => $_FILES['children_birth_certificate']['type'][$i] ?? '',
                    'tmp_name' => $_FILES['children_birth_certificate']['tmp_name'][$i] ?? '',
                    'error' => $_FILES['children_birth_certificate']['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                    'size' => $_FILES['children_birth_certificate']['size'][$i] ?? 0,
                ];
                $certificatePath = rhStoreUploadedDocument($childFile, 'extrait-naissance-enfant', $personnelId);
            }
            $children[] = [
                'birth_date' => trim((string) ($childrenBirthDates[$i] ?? ($existingChild['child_birth_date'] ?? ''))),
                'birth_certificate_path' => $certificatePath,
            ];
        }
        $directory->replacePersonnelChildren($personnelId, $children, $actor);

        \AuditTrail::log('rh_personnel_dossier_updated', [
            'module' => 'rh',
            'action' => 'save_dossier_dedicated_page',
            'entity_type' => 'personnel',
            'entity_id' => (string) $personnelId,
            'entity_label' => $payload['full_name'],
        ], $pdo);

        header('Location: dossier_personnel_rh.php?id=' . $personnelId . '&saved=1');
        exit;
    } catch (Throwable $e) {
        $statusType = 'error';
        $statusMessage = $e->getMessage();
    }
}

$employee = $directory->getPersonnelDetail($personnelId) ?? $employee;
$existingChildren = $directory->getPersonnelChildren($personnelId);
$filterOptions = $directory->getFilters();
$exitReasons = $directory->getExitReasons(true);
$jobDescription=null;if(!empty($employee['fonction_id'])){$q=$pdo->prepare("SELECT id,intitule,version,date_effet FROM rh_fiches_poste WHERE fonction_id=? AND statut='active' ORDER BY date_effet DESC,id DESC LIMIT 1");$q->execute([(int)$employee['fonction_id']]);$jobDescription=$q->fetch(PDO::FETCH_ASSOC)?:null;}

$childrenForJs = array_map(static function (array $child): array {
    return [
        'birth_date' => (string) ($child['child_birth_date'] ?? ''),
        'certificate' => (string) ($child['birth_certificate_path'] ?? ''),
    ];
}, $existingChildren);

$pageTitle = 'Modifier dossier RH';
$activeMenu = 'rh';
require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>
<div class="min-h-screen bg-[radial-gradient(circle_at_top_left,_rgba(236,253,245,0.9),_transparent_42%),radial-gradient(circle_at_top_right,_rgba(191,219,254,0.8),_transparent_35%),linear-gradient(180deg,_#f8fafc_0%,_#eef2ff_100%)] pb-12">
    <div class="container mx-auto px-4 py-8">
        <div class="rounded-[28px] bg-slate-950 px-7 py-8 text-white">
            <p class="text-xs font-semibold uppercase tracking-[0.28em] text-emerald-300">Edition RH</p>
            <h1 class="mt-3 text-3xl font-semibold">Modifier le dossier</h1>
            <p class="mt-2 text-sm text-slate-300"><?= htmlspecialchars((string) $employee['full_name']) ?> · <?= htmlspecialchars((string) ($employee['matricule'] ?: 'Matricule en attente')) ?></p>
            <div class="mt-5 flex flex-wrap gap-3">
                <a href="dossier_personnel_rh.php?id=<?= (int) $personnelId ?>" class="rounded-2xl border border-white/15 px-5 py-3 text-sm font-semibold text-white hover:bg-white/10">Retour dossier</a>
                <a href="mutation_personnel_rh.php?id=<?= (int) $personnelId ?>" class="rounded-2xl bg-white px-5 py-3 text-sm font-semibold text-slate-950 hover:bg-emerald-50">Mutation</a>
            </div>
        </div>

        <section class="mt-6 rounded-[26px] border p-5 <?=$jobDescription?'border-emerald-200 bg-emerald-50':'border-amber-300 bg-amber-50'?>"><div class="flex flex-wrap items-center justify-between gap-4"><div><p class="text-xs font-black uppercase tracking-[.2em] <?=$jobDescription?'text-emerald-700':'text-amber-700'?>">Fiche de poste</p><h2 class="mt-2 text-xl font-black text-slate-950"><?=$jobDescription?htmlspecialchars((string)$jobDescription['intitule']):'Fiche de poste à éditer'?></h2><p class="mt-1 text-sm <?=$jobDescription?'text-emerald-800':'text-amber-900'?>"><?=$jobDescription?'Version '.htmlspecialchars((string)$jobDescription['version']).' actuellement applicable.':'Aucune fiche active n’est rattachée à la fonction de ce collaborateur.'?></p></div><a href="fiches_poste_rh.php<?=$jobDescription?'?edit='.(int)$jobDescription['id']:'?fonction_id='.(int)($employee['fonction_id']??0)?>" class="rounded-xl <?=$jobDescription?'bg-emerald-700':'bg-black'?> px-5 py-3 font-black text-white"><?=$jobDescription?'Consulter / modifier':'Créer la fiche de poste'?></a></div></section>

        <?php if ($statusType !== null): ?>
            <div class="mt-6 rounded-2xl border border-rose-200 bg-rose-50 px-5 py-4 text-sm font-semibold text-rose-800"><?= htmlspecialchars((string) $statusMessage) ?></div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" class="mt-6 space-y-6 rounded-[28px] border border-white/80 bg-white/92 p-6 shadow-[0_18px_60px_rgba(15,23,42,0.07)] backdrop-blur"><input type="hidden" name="_rh_identity_csrf" value="<?= htmlspecialchars((string)$_SESSION['rh_identity_csrf']) ?>">
            <input type="hidden" name="id" value="<?= (int) $personnelId ?>">
            <section class="rounded-[26px] border border-slate-100 bg-slate-50/70 p-5">
                <h2 class="text-xl font-semibold text-slate-950">Informations generales et administratives</h2>
                <div class="mt-5 grid gap-5 md:grid-cols-2 xl:grid-cols-3">
                <div>
                    <label class="mb-2 block text-sm font-medium text-slate-700">Matricule</label>
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-800"><?= htmlspecialchars((string) ($employee['matricule'] ?: 'Auto-genere')) ?></div>
                </div>
                <div class="md:col-span-2">
                    <label class="mb-2 block text-sm font-medium text-slate-700">Nom complet</label>
                    <input type="text" name="full_name" required value="<?= htmlspecialchars((string) ($employee['full_name'] ?? '')) ?>" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm">
                </div>
                <div><label class="mb-2 block text-sm font-medium text-slate-700">Sexe</label><select name="gender_code" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm"><option value="">Non renseigne</option><?php foreach ($filterOptions['sexes'] as $sexe): ?><option value="<?= (int) $sexe['id'] ?>" <?= (string) ($employee['gender_code'] ?? '') === (string) $sexe['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $sexe['label']) ?></option><?php endforeach; ?></select></div>
                <div><label class="mb-2 block text-sm font-medium text-slate-700">Date naissance</label><input type="date" name="birth_date" value="<?= htmlspecialchars((string) ($employee['birth_date'] ?? '')) ?>" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm"></div>
                <div><label class="mb-2 block text-sm font-medium text-slate-700">Telephone</label><input type="text" name="phone" value="<?= htmlspecialchars((string) ($employee['phone'] ?? '')) ?>" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm"></div>
                <div><label class="mb-2 block text-sm font-medium text-slate-700">Email</label><input type="email" name="email" value="<?= htmlspecialchars((string) ($employee['email'] ?? '')) ?>" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm"></div>
                <div><label class="mb-2 block text-sm font-medium text-slate-700">Service</label><select name="service_id" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm"><option value="">Non renseigne</option><?php foreach ($filterOptions['services'] as $service): ?><option value="<?= (int) $service['id'] ?>" <?= (string) ($employee['service_id'] ?? '') === (string) $service['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $service['label']) ?></option><?php endforeach; ?></select></div>
                <div><label class="mb-2 block text-sm font-medium text-slate-700">Fonction</label><select name="fonction_id" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm"><option value="">Non renseignee</option><?php foreach ($filterOptions['fonctions'] as $fonction): ?><option value="<?= (int) $fonction['id'] ?>" <?= (string) ($employee['fonction_id'] ?? '') === (string) $fonction['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $fonction['label']) ?></option><?php endforeach; ?></select></div>
                <div><label class="mb-2 block text-sm font-medium text-slate-700">Statut</label><select name="status_code" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm"><option value="">Non renseigne</option><?php foreach ($filterOptions['statuts'] as $statut): ?><option value="<?= (int) $statut['id'] ?>" <?= (string) ($employee['status_code'] ?? '') === (string) $statut['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $statut['label']) ?></option><?php endforeach; ?></select></div>
                <div><label class="mb-2 block text-sm font-medium text-slate-700">Site</label><select name="site_id" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm"><option value="">Non renseigne</option><?php foreach ($filterOptions['sites'] as $site): ?><option value="<?= (int) $site['id'] ?>" <?= (string) ($employee['site_id'] ?? '') === (string) $site['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $site['label']) ?></option><?php endforeach; ?></select></div>
                <div><label class="mb-2 block text-sm font-medium text-slate-700">Date recrutement</label><input type="date" name="hire_date" value="<?= htmlspecialchars((string) ($employee['hire_date'] ?? '')) ?>" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm"></div>
                <div><label class="mb-2 block text-sm font-medium text-slate-700">Prise de service</label><input type="date" name="start_date" value="<?= htmlspecialchars((string) ($employee['start_date'] ?? '')) ?>" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm"></div>
                <div><label class="mb-2 block text-sm font-medium text-slate-700">Date sortie</label><input type="date" name="end_date" value="<?= htmlspecialchars((string) ($employee['end_date'] ?? '')) ?>" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm"></div>
                <div><label class="mb-2 block text-sm font-medium text-slate-700">Motif sortie</label><select name="exit_reason_id" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm"><option value="">Aucun</option><?php foreach ($exitReasons as $reason): ?><option value="<?= (int) $reason['id'] ?>" <?= (string) ($employee['exit_reason_id'] ?? '') === (string) $reason['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $reason['label']) ?></option><?php endforeach; ?></select></div>
                <div><label class="mb-2 block text-sm font-medium text-slate-700">Situation matrimoniale</label><input type="text" name="marital_status" value="<?= htmlspecialchars((string) ($employee['marital_status'] ?? '')) ?>" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm"></div>
                <div><label class="mb-2 block text-sm font-medium text-slate-700">CNI</label><input type="text" name="cni_number" required maxlength="110" value="<?= htmlspecialchars((string) ($employee['cni_number'] ?? '')) ?>" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm"></div>
                <div><label class="mb-2 block text-sm font-medium text-slate-700">CNPS</label><input type="text" name="cnps_number" value="<?= htmlspecialchars((string) ($employee['cnps_number'] ?? '')) ?>" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm"></div>
                <div><label class="mb-2 block text-sm font-medium text-slate-700">Nom pere</label><input type="text" name="father_name" value="<?= htmlspecialchars((string) ($employee['father_name'] ?? '')) ?>" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm"></div>
                <div><label class="mb-2 block text-sm font-medium text-slate-700">Tel pere</label><input type="text" name="father_phone" value="<?= htmlspecialchars((string) ($employee['father_phone'] ?? '')) ?>" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm"></div>
                <div><label class="mb-2 block text-sm font-medium text-slate-700">Nom mere</label><input type="text" name="mother_name" value="<?= htmlspecialchars((string) ($employee['mother_name'] ?? '')) ?>" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm"></div>
                <div><label class="mb-2 block text-sm font-medium text-slate-700">Tel mere</label><input type="text" name="mother_phone" value="<?= htmlspecialchars((string) ($employee['mother_phone'] ?? '')) ?>" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm"></div>
                <div><label class="mb-2 block text-sm font-medium text-slate-700">Contact urgence</label><input type="text" name="emergency_contact_name" value="<?= htmlspecialchars((string) ($employee['emergency_contact_name'] ?? '')) ?>" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm"></div>
                <div><label class="mb-2 block text-sm font-medium text-slate-700">Tel urgence</label><input type="text" name="emergency_contact_phone" value="<?= htmlspecialchars((string) ($employee['emergency_contact_phone'] ?? '')) ?>" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm"></div>
                <div><label class="mb-2 block text-sm font-medium text-slate-700">Duree contrat (mois)</label><input type="number" name="contract_duration_months" value="<?= htmlspecialchars((string) ($employee['contract_duration_months'] ?? '')) ?>" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm"></div>
                <div><label class="mb-2 block text-sm font-medium text-slate-700">A des enfants</label><select name="has_children" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm"><option value="">-</option><option value="1" <?= (string) ($employee['has_children'] ?? '') === '1' ? 'selected' : '' ?>>Oui</option><option value="0" <?= (string) ($employee['has_children'] ?? '') === '0' ? 'selected' : '' ?>>Non</option></select></div>
                <div><label class="mb-2 block text-sm font-medium text-slate-700">Nombre enfants</label><input type="number" name="children_count" min="0" max="20" value="<?= htmlspecialchars((string) ($employee['children_count'] ?? '')) ?>" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm"></div>
                </div>
            </section>

            <section class="rounded-[26px] border border-slate-100 bg-slate-50/70 p-5">
                <h2 class="text-xl font-semibold text-slate-950">Enfants et extraits de naissance</h2>
                <p class="mt-1 text-sm text-slate-500">Le nombre d'extraits disponibles doit correspondre au nombre d'enfants saisi.</p>
                <div id="children-details" class="mt-5 hidden rounded-2xl border border-indigo-100 bg-indigo-50 p-4">
                    <div id="children-details-list" class="grid gap-3 md:grid-cols-2 xl:grid-cols-3"></div>
                </div>
            </section>

            <section class="rounded-[26px] border border-slate-100 bg-slate-50/70 p-5">
                <h2 class="text-xl font-semibold text-slate-950">Pieces du dossier</h2>
                <p class="mt-1 text-sm text-slate-500">Numéro et pièce d’identité obligatoires. Tout changement de numéro exige la pièce correspondante.</p>
                <div class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-5">
                <?php foreach ([['Photo','photo_file','fa-camera-retro'], ['Extrait naissance','birth_certificate_file','fa-file-lines'], ['Piece identite','identity_document_file','fa-id-card'], ['Diplome','last_degree_file','fa-graduation-cap'], ['Contrat','employment_contract_file','fa-file-signature']] as [$label, $name, $icon]): ?>
                    <label class="rh-dropzone group flex min-h-[190px] cursor-pointer flex-col items-center justify-center rounded-2xl border-2 border-dashed border-slate-200 bg-slate-50 p-4 text-center transition hover:border-emerald-300 hover:bg-emerald-50/60" data-dropzone>
                        <input type="file" name="<?= htmlspecialchars($name) ?>" <?= $name==='identity_document_file' && empty($employee['identity_document_path'])?'required':'' ?> accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.webp" class="sr-only" data-file-input>
                        <span class="flex h-11 w-11 items-center justify-center rounded-2xl bg-white text-emerald-600 shadow-sm ring-1 ring-slate-100">
                            <i class="fas <?= htmlspecialchars($icon) ?>"></i>
                        </span>
                        <span class="mt-3 text-sm font-semibold text-slate-800"><?= htmlspecialchars($label) ?></span>
                        <span class="mt-1 text-xs leading-5 text-slate-500">Glisser-deposer ou cliquer</span>
                        <span class="mt-3 hidden w-full overflow-hidden rounded-xl border border-slate-200 bg-white p-2 text-left" data-preview>
                            <span class="block truncate text-xs font-semibold text-slate-800" data-file-name></span>
                            <span class="block text-[11px] text-slate-500" data-file-meta></span>
                            <img src="" alt="" class="mt-2 hidden h-24 w-full rounded-lg object-cover" data-image-preview>
                        </span>
                    </label>
                <?php endforeach; ?>
                </div>
            </section>

            <div class="flex justify-end gap-3">
                <a href="dossier_personnel_rh.php?id=<?= (int) $personnelId ?>" class="rounded-2xl border border-slate-200 px-5 py-3 text-sm font-semibold text-slate-700">Annuler</a>
                <button class="rounded-2xl bg-emerald-600 px-6 py-3 text-sm font-semibold text-white">Enregistrer</button>
            </div>
        </form>
    </div>
</div>
<script>
    (() => {
        const formatSize = (bytes) => {
            if (!Number.isFinite(bytes) || bytes <= 0) return '';
            const units = ['o', 'Ko', 'Mo', 'Go'];
            let size = bytes;
            let unit = 0;
            while (size >= 1024 && unit < units.length - 1) {
                size /= 1024;
                unit++;
            }
            return `${size.toFixed(unit === 0 ? 0 : 1)} ${units[unit]}`;
        };

        const updatePreview = (zone, file) => {
            const preview = zone.querySelector('[data-preview]');
            const fileName = zone.querySelector('[data-file-name]');
            const fileMeta = zone.querySelector('[data-file-meta]');
            const image = zone.querySelector('[data-image-preview]');
            if (!preview || !fileName || !fileMeta || !image || !file) return;

            preview.classList.remove('hidden');
            fileName.textContent = file.name;
            fileMeta.textContent = [file.type || 'Fichier', formatSize(file.size)].filter(Boolean).join(' · ');
            image.classList.add('hidden');
            image.removeAttribute('src');

            if (file.type && file.type.startsWith('image/')) {
                const reader = new FileReader();
                reader.onload = () => {
                    image.src = String(reader.result || '');
                    image.classList.remove('hidden');
                };
                reader.readAsDataURL(file);
            }
        };

        const bindDropzone = (zone) => {
            if (!zone || zone.dataset.boundDropzone === '1') return;
            zone.dataset.boundDropzone = '1';
            const input = zone.querySelector('[data-file-input]');
            if (!input) return;

            input.addEventListener('change', () => {
                updatePreview(zone, input.files && input.files[0]);
            });

            ['dragenter', 'dragover'].forEach((eventName) => {
                zone.addEventListener(eventName, (event) => {
                    event.preventDefault();
                    zone.classList.add('border-emerald-400', 'bg-emerald-50');
                });
            });

            ['dragleave', 'drop'].forEach((eventName) => {
                zone.addEventListener(eventName, (event) => {
                    event.preventDefault();
                    zone.classList.remove('border-emerald-400', 'bg-emerald-50');
                });
            });

            zone.addEventListener('drop', (event) => {
                const file = event.dataTransfer && event.dataTransfer.files ? event.dataTransfer.files[0] : null;
                if (!file) return;
                const transfer = new DataTransfer();
                transfer.items.add(file);
                input.files = transfer.files;
                updatePreview(zone, file);
            });
        };

        document.querySelectorAll('[data-dropzone]').forEach(bindDropzone);

        const childrenExisting = <?= json_encode($childrenForJs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const countInput = document.querySelector('input[name="children_count"]');
        const hasChildrenSelect = document.querySelector('select[name="has_children"]');
        const childrenWrapper = document.getElementById('children-details');
        const childrenList = document.getElementById('children-details-list');

        const renderChildren = () => {
            if (!countInput || !childrenWrapper || !childrenList) return;
            const count = Math.max(0, Math.min(20, parseInt(countInput.value || '0', 10) || 0));
            childrenWrapper.classList.toggle('hidden', count <= 0);
            if (hasChildrenSelect) hasChildrenSelect.value = count > 0 ? '1' : '0';
            childrenList.innerHTML = '';
            for (let i = 0; i < count; i++) {
                const existing = childrenExisting[i] || {};
                const hasCertificate = Boolean(existing.certificate);
                const row = document.createElement('div');
                row.className = 'rounded-2xl border border-indigo-100 bg-white p-4';
                row.innerHTML = `
                    <div class="mb-3 flex items-center justify-between gap-3">
                        <div class="text-sm font-semibold text-slate-900">Enfant ${i + 1}</div>
                        ${hasCertificate ? '<span class="rounded-full bg-emerald-50 px-2 py-1 text-[11px] font-semibold text-emerald-700">Extrait existant</span>' : '<span class="rounded-full bg-rose-50 px-2 py-1 text-[11px] font-semibold text-rose-700">Extrait requis</span>'}
                    </div>
                    <label class="mb-2 block text-xs font-semibold text-slate-600">Date de naissance</label>
                    <input type="date" name="children_birth_date[]" value="${String(existing.birth_date || '').replace(/"/g, '&quot;')}" required class="mb-3 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm outline-none focus:border-indigo-400">
                    <label class="rh-dropzone group flex min-h-[150px] cursor-pointer flex-col items-center justify-center rounded-2xl border-2 border-dashed border-slate-200 bg-slate-50 p-4 text-center transition hover:border-indigo-300 hover:bg-indigo-50/60" data-dropzone>
                        <input type="file" name="children_birth_certificate[]" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.webp" class="sr-only" data-file-input ${hasCertificate ? '' : 'required'}>
                        <span class="text-sm font-semibold text-slate-800">Extrait de naissance</span>
                        <span class="mt-1 text-xs text-slate-500">${hasCertificate ? 'Remplacer le fichier existant' : 'Glisser-deposer ou cliquer'}</span>
                        <span class="mt-3 hidden w-full overflow-hidden rounded-xl border border-slate-200 bg-white p-2 text-left" data-preview>
                            <span class="block truncate text-xs font-semibold text-slate-800" data-file-name></span>
                            <span class="block text-[11px] text-slate-500" data-file-meta></span>
                            <img src="" alt="" class="mt-2 hidden h-24 w-full rounded-lg object-cover" data-image-preview>
                        </span>
                    </label>
                `;
                childrenList.appendChild(row);
                row.querySelectorAll('[data-dropzone]').forEach(bindDropzone);
            }
        };

        countInput?.addEventListener('input', renderChildren);
        hasChildrenSelect?.addEventListener('change', () => {
            if (hasChildrenSelect.value === '0') countInput.value = '0';
            if (hasChildrenSelect.value === '1' && (parseInt(countInput.value || '0', 10) || 0) <= 0) countInput.value = '1';
            renderChildren();
        });
        renderChildren();
    })();
</script>
<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>

