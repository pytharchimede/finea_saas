<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess('nouvelle_demande_decaissement_urgent.php');

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../controllers/UrgentDisbursementController.php';
require_once __DIR__ . '/includes/helpers.php';

$pdo = \Database::getConnection();
$controller = new \UrgentDisbursementController($pdo);
$repo = $controller->service()->repository();
$chantiers = $repo->listChantiers();

$userId = (int)($_SESSION['user_id'] ?? 0);
$userEmail = (string)($_SESSION['mail_pro'] ?? '');
$personnel = employeeFindPersonnelProfile($pdo, $userEmail);
$requesterPhone = '';
if ($personnel) {
    try {
        $phoneStmt = $pdo->prepare('SELECT tel_personnel_soignant FROM personnel WHERE id_personnel_soignant = :id LIMIT 1');
        $phoneStmt->execute(['id' => (int)($personnel['personnel_id'] ?? 0)]);
        $requesterPhone = trim((string)($phoneStmt->fetchColumn() ?: ''));
    } catch (Throwable $e) {
        $requesterPhone = '';
    }
}
$selectedChantierId = (int)($_POST['chantier_id'] ?? 0);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $id = $controller->submitEmployee($_POST, $_FILES, [
            'user_id' => $userId,
            'name' => employeeCurrentUserName(),
            'email' => $userEmail,
            'personnel_id' => (int)($personnel['personnel_id'] ?? 0),
        ]);
        header('Location: dashboard_employe.php?urgent_created=' . urlencode((string)$id));
        exit;
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

$pageTitle = 'Decaissement urgent';
$layoutRootBase = '../';
$activeEmployeeMenu = 'actes';
require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<div class="min-h-screen bg-slate-50 px-4 py-8">
    <div class="mx-auto max-w-5xl">
        <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <p class="text-xs font-semibold uppercase tracking-[0.28em] text-rose-600">Urgence</p>
            <h1 class="mt-2 text-2xl font-bold text-slate-950">Demande de decaissement en urgence</h1>
            <p class="mt-2 text-sm text-slate-600">Cette demande est envoyee directement a la comptabilite. Elle ne cree pas de fiche de decaissement et ne consomme pas le budget du chantier.</p>

            <?php if ($errors): ?>
                <div class="mt-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                    <?= htmlspecialchars(implode(' ', $errors)) ?>
                </div>
            <?php endif; ?>

            <form method="post" enctype="multipart/form-data" class="mt-6 grid gap-5">
                <section class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                    <div class="mb-3 text-sm font-bold text-slate-900">Identite du demandeur</div>
                    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                        <label class="block">
                            <span class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Nom</span>
                            <input readonly value="<?= htmlspecialchars((string)($personnel['full_name'] ?? employeeCurrentUserName())) ?>" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700">
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Telephone</span>
                            <input readonly value="<?= htmlspecialchars($requesterPhone !== '' ? $requesterPhone : 'Non renseigne') ?>" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700">
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Email</span>
                            <input readonly value="<?= htmlspecialchars($userEmail) ?>" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700">
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Service</span>
                            <input readonly value="<?= htmlspecialchars((string)($personnel['service_label'] ?? 'Service non renseigne')) ?>" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700">
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Fonction</span>
                            <input readonly value="<?= htmlspecialchars((string)($personnel['fonction_label'] ?? 'Fonction non renseignee')) ?>" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700">
                        </label>
                    </div>
                </section>

                <?php
                $selectedChantierLabel = '';
                foreach ($chantiers as $chantier) {
                    if ((int)($chantier['id'] ?? 0) === $selectedChantierId) {
                        $selectedChantierLabel = trim((string)(($chantier['code'] ?? '') . ' - ' . ($chantier['titre'] ?? '')));
                        break;
                    }
                }
                ?>
                <div class="relative" data-chantier-search>
                    <label for="chantierSearch" class="mb-1 block text-sm font-semibold text-slate-700">Chantier concerne</label>
                    <input type="hidden" name="chantier_id" id="chantierId" value="<?= (int)$selectedChantierId ?>" required>
                    <input id="chantierSearch" type="text" autocomplete="off" value="<?= htmlspecialchars($selectedChantierLabel) ?>" class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 pr-11 text-sm" placeholder="Tapez le code ou le nom du chantier">
                    <button type="button" id="clearChantierSearch" class="absolute right-3 top-9 hidden h-8 w-8 rounded-full border border-slate-200 bg-white text-slate-500 hover:bg-slate-50">
                        <i class="fas fa-xmark"></i>
                    </button>
                    <div id="chantierSearchMenu" class="absolute z-30 mt-2 hidden max-h-72 w-full overflow-auto rounded-2xl border border-slate-200 bg-white p-2 shadow-xl">
                        <?php foreach ($chantiers as $chantier): ?>
                            <?php $label = trim((string)(($chantier['code'] ?? '') . ' - ' . ($chantier['titre'] ?? ''))); ?>
                            <button type="button" class="chantier-search-option flex w-full items-start rounded-xl px-3 py-2 text-left text-sm hover:bg-slate-50" data-id="<?= (int)$chantier['id'] ?>" data-label="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>" data-search="<?= htmlspecialchars(strtolower($label), ENT_QUOTES, 'UTF-8') ?>">
                                <span class="font-semibold text-slate-900"><?= htmlspecialchars((string)($chantier['code'] ?? '')) ?></span>
                                <span class="ml-2 text-slate-600"><?= htmlspecialchars((string)($chantier['titre'] ?? '')) ?></span>
                            </button>
                        <?php endforeach; ?>
                        <div id="chantierSearchEmpty" class="hidden px-3 py-4 text-sm text-slate-500">Aucun chantier trouve.</div>
                    </div>
                </div>

                <label class="block">
                    <span class="mb-1 block text-sm font-semibold text-slate-700">Libelle</span>
                    <input name="libelle" required value="<?= htmlspecialchars((string)($_POST['libelle'] ?? '')) ?>" class="w-full rounded-xl border border-slate-300 px-4 py-3 text-sm" placeholder="Ex: carburant intervention urgente">
                </label>

                <label class="block">
                    <span class="mb-1 block text-sm font-semibold text-slate-700">Montant</span>
                    <input name="amount" required inputmode="decimal" value="<?= htmlspecialchars((string)($_POST['amount'] ?? '')) ?>" class="w-full rounded-xl border border-slate-300 px-4 py-3 text-sm" placeholder="Ex: 150000">
                </label>

                <label class="block">
                    <span class="mb-1 block text-sm font-semibold text-slate-700">Detail de l'urgence</span>
                    <textarea name="details" required rows="5" class="w-full rounded-xl border border-slate-300 px-4 py-3 text-sm" placeholder="Expliquez le besoin et pourquoi il doit etre traite sans attendre"><?= htmlspecialchars((string)($_POST['details'] ?? '')) ?></textarea>
                </label>

                <div>
                    <span class="mb-1 block text-sm font-semibold text-slate-700">Justificatif image ou PDF (optionnel)</span>
                    <label id="urgentDropzone" class="flex cursor-pointer flex-col items-center justify-center rounded-2xl border-2 border-dashed border-slate-300 bg-slate-50 px-4 py-8 text-center transition hover:border-rose-300 hover:bg-rose-50/40">
                        <input id="urgentJustificatif" type="file" name="justificatif" accept=".jpg,.jpeg,.png,.webp,.pdf,image/*,application/pdf" class="hidden">
                        <span class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-white text-rose-600 shadow-sm">
                            <i class="fas fa-cloud-arrow-up"></i>
                        </span>
                        <span class="mt-3 text-sm font-semibold text-slate-900">Glissez le fichier ici ou cliquez pour parcourir</span>
                        <span class="mt-1 text-xs text-slate-500">JPG, PNG, WEBP ou PDF - 10 Mo max</span>
                    </label>
                    <div id="urgentFilePreview" class="mt-3 hidden rounded-2xl border border-slate-200 bg-white p-3">
                        <div class="flex items-center justify-between gap-3">
                            <div class="flex min-w-0 items-center gap-3">
                                <div id="urgentPreviewThumb" class="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-slate-100 text-slate-500"></div>
                                <div class="min-w-0">
                                    <div id="urgentPreviewName" class="truncate text-sm font-semibold text-slate-900"></div>
                                    <div id="urgentPreviewMeta" class="text-xs text-slate-500"></div>
                                </div>
                            </div>
                            <button type="button" id="urgentRemoveFile" class="rounded-xl border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">Supprimer</button>
                        </div>
                    </div>
                </div>

                <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                    Apres decaissement, vous devrez creer une FEB de regularisation puis la fiche associee. Apres 3 jours sans regularisation, le chantier sera bloque pour vos nouvelles fiches.
                </div>

                <div class="flex flex-wrap justify-end gap-2">
                    <a href="actes_legaux_employe.php" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Annuler</a>
                    <button class="rounded-xl bg-rose-600 px-5 py-2 text-sm font-semibold text-white hover:bg-rose-700">Envoyer l'urgence</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    (function() {
        const chantierInput = document.getElementById('chantierSearch');
        const chantierId = document.getElementById('chantierId');
        const chantierMenu = document.getElementById('chantierSearchMenu');
        const clearChantier = document.getElementById('clearChantierSearch');
        const empty = document.getElementById('chantierSearchEmpty');
        const options = Array.from(document.querySelectorAll('.chantier-search-option'));

        function filterChantiers() {
            const term = (chantierInput.value || '').trim().toLowerCase();
            let shown = 0;
            options.forEach(function(option) {
                const match = term === '' || String(option.dataset.search || '').includes(term);
                option.classList.toggle('hidden', !match);
                if (match) shown++;
            });
            empty.classList.toggle('hidden', shown > 0);
            chantierMenu.classList.remove('hidden');
            clearChantier.classList.toggle('hidden', chantierInput.value.trim() === '');
        }

        chantierInput && chantierInput.addEventListener('focus', filterChantiers);
        chantierInput && chantierInput.addEventListener('input', function() {
            chantierId.value = '';
            filterChantiers();
        });
        options.forEach(function(option) {
            option.addEventListener('click', function() {
                chantierId.value = option.dataset.id || '';
                chantierInput.value = option.dataset.label || '';
                chantierMenu.classList.add('hidden');
                clearChantier.classList.remove('hidden');
            });
        });
        clearChantier && clearChantier.addEventListener('click', function() {
            chantierId.value = '';
            chantierInput.value = '';
            filterChantiers();
            chantierInput.focus();
        });
        document.addEventListener('click', function(event) {
            if (!event.target.closest('[data-chantier-search]')) {
                chantierMenu && chantierMenu.classList.add('hidden');
            }
        });

        const dropzone = document.getElementById('urgentDropzone');
        const fileInput = document.getElementById('urgentJustificatif');
        const preview = document.getElementById('urgentFilePreview');
        const thumb = document.getElementById('urgentPreviewThumb');
        const name = document.getElementById('urgentPreviewName');
        const meta = document.getElementById('urgentPreviewMeta');
        const remove = document.getElementById('urgentRemoveFile');

        function formatSize(bytes) {
            if (!bytes) return '0 Ko';
            if (bytes < 1024 * 1024) return Math.round(bytes / 1024) + ' Ko';
            return (bytes / (1024 * 1024)).toFixed(2).replace('.', ',') + ' Mo';
        }

        function showFile(file) {
            if (!file) return;
            name.textContent = file.name;
            meta.textContent = formatSize(file.size) + (file.type ? ' - ' + file.type : '');
            thumb.innerHTML = '';
            if (file.type && file.type.startsWith('image/')) {
                const img = document.createElement('img');
                img.className = 'h-full w-full object-cover';
                img.alt = file.name;
                img.src = URL.createObjectURL(file);
                thumb.appendChild(img);
            } else {
                thumb.innerHTML = '<i class="fas fa-file-pdf text-2xl text-rose-600"></i>';
            }
            preview.classList.remove('hidden');
        }

        dropzone && ['dragenter', 'dragover'].forEach(function(type) {
            dropzone.addEventListener(type, function(event) {
                event.preventDefault();
                dropzone.classList.add('border-rose-400', 'bg-rose-50');
            });
        });
        dropzone && ['dragleave', 'drop'].forEach(function(type) {
            dropzone.addEventListener(type, function(event) {
                event.preventDefault();
                dropzone.classList.remove('border-rose-400', 'bg-rose-50');
            });
        });
        dropzone && dropzone.addEventListener('drop', function(event) {
            const file = event.dataTransfer && event.dataTransfer.files ? event.dataTransfer.files[0] : null;
            if (!file) return;
            const transfer = new DataTransfer();
            transfer.items.add(file);
            fileInput.files = transfer.files;
            showFile(file);
        });
        fileInput && fileInput.addEventListener('change', function() {
            showFile(fileInput.files && fileInput.files[0]);
        });
        remove && remove.addEventListener('click', function() {
            fileInput.value = '';
            thumb.innerHTML = '';
            preview.classList.add('hidden');
        });
    })();
</script>

<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>