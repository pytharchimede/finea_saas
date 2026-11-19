<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../controllers/RhPersonnelController.php';
require_once __DIR__ . '/includes/personnel_helpers.php';

$personnelId = max(0, (int) ($_GET['id'] ?? 0));

$pdo = \Database::getConnection();
$directory = (new \RhPersonnelController($pdo))->service();

$exitReasonsAll = $directory->getExitReasons(true);
$exitReasonsById = [];
$exitReasonsActive = [];
foreach ($exitReasonsAll as $reason) {
    $id = (int) ($reason['id'] ?? 0);
    if ($id <= 0) {
        continue;
    }

    $exitReasonsById[$id] = $reason;
    if ((int) ($reason['is_active'] ?? 0) === 1) {
        $exitReasonsActive[] = $reason;
    }
}

$actor = [
    'user_id' => isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
    'user_name' => isset($_SESSION['user_name']) ? trim((string) $_SESSION['user_name']) : null,
];

$auditPersonnelExit = static function (string $eventType, array $context = []) use ($actor, $personnelId): void {
    \AuditTrail::log($eventType, array_merge([
        'module' => 'rh',
        'entity_type' => 'personnel',
        'entity_id' => $personnelId > 0 ? (string) $personnelId : null,
        'user_id' => $actor['user_id'],
        'user_name' => $actor['user_name'],
    ], $context));
};

$statusType = null;
$statusMessage = null;

$employee = $personnelId > 0 ? $directory->getPersonnelDetail($personnelId) : null;

if ($personnelId <= 0) {
    $statusType = 'error';
    $statusMessage = 'Aucun collaborateur n\'a ete selectionne.';
} elseif ($employee === null) {
    $statusType = 'error';
    $statusMessage = 'Le collaborateur cible est introuvable.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $employee !== null) {
    try {
        $action = trim((string) ($_POST['form_action'] ?? 'set_exit'));

        if ($action === 'set_exit') {
            $endDate = (string) ($_POST['end_date'] ?? '');
            $exitReasonId = (string) ($_POST['exit_reason_id'] ?? '');

            if ($endDate === '') {
                throw new RuntimeException('La date de sortie est obligatoire pour declarer une sortie.');
            }

            $directory->updatePersonnel($personnelId, [
                'end_date' => $endDate,
                'exit_reason_id' => $exitReasonId,
            ], $actor);

            $auditPersonnelExit('rh_personnel_exit_set', [
                'action' => 'set_exit',
                'entity_label' => (string) ($employee['full_name'] ?? 'personnel'),
                'details' => [
                    'end_date' => $endDate,
                    'exit_reason_id' => $exitReasonId !== '' ? $exitReasonId : null,
                ],
            ]);

            header('Location: sortie_personnel_rh.php?id=' . $personnelId . '&saved=1');
            exit;
        }

        if ($action === 'clear_exit') {
            $directory->updatePersonnel($personnelId, [
                'end_date' => '',
                'exit_reason_id' => '',
            ], $actor);

            $auditPersonnelExit('rh_personnel_exit_cleared', [
                'action' => 'clear_exit',
                'entity_label' => (string) ($employee['full_name'] ?? 'personnel'),
            ]);

            header('Location: sortie_personnel_rh.php?id=' . $personnelId . '&cleared=1');
            exit;
        }

        throw new RuntimeException('Action non reconnue.');
    } catch (Throwable $e) {
        $auditPersonnelExit('rh_personnel_exit_failed', [
            'action' => (string) ($_POST['form_action'] ?? 'set_exit'),
            'entity_label' => (string) ($employee['full_name'] ?? 'personnel'),
            'result' => 'failure',
            'severity' => 'warning',
            'details' => ['error' => $e->getMessage()],
        ]);
        $statusType = 'error';
        $statusMessage = $e->getMessage();

        $employee = $directory->getPersonnelDetail($personnelId);
    }
}

if ($employee !== null) {
    if (isset($_GET['saved'])) {
        $statusType = 'success';
        $statusMessage = 'La sortie a ete enregistree.';
    }

    if (isset($_GET['cleared'])) {
        $statusType = 'success';
        $statusMessage = 'La reintegration a ete enregistree (date de sortie effacee).';
    }
}

$pageTitle = 'Sortie du personnel';
$activeMenu = 'rh';

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>
<div class="min-h-screen bg-[radial-gradient(circle_at_top,_rgba(254,243,199,0.8),_transparent_35%),radial-gradient(circle_at_right,_rgba(219,234,254,0.85),_transparent_30%),linear-gradient(180deg,_#f8fafc_0%,_#eef2ff_100%)] pb-12">
    <div class="container mx-auto px-4 py-8">
        <div class="rounded-[32px] border border-slate-200 bg-white/90 p-8 shadow-[0_18px_60px_rgba(15,23,42,0.07)] backdrop-blur">
            <div class="flex flex-col gap-6 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.32em] text-amber-700">Action RH</p>
                    <h1 class="mt-3 text-3xl font-semibold tracking-tight text-slate-950 md:text-4xl">Sortie / reintegration</h1>
                    <p class="mt-3 max-w-3xl text-sm text-slate-600 md:text-base">
                        Declarer rapidement une sortie (date + motif), ou reintegrer un collaborateur.
                    </p>
                </div>

                <div class="flex flex-wrap gap-3">
                    <a href="liste_personnel_rh.php" class="rounded-2xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50">Retour liste</a>
                    <?php if ($personnelId > 0): ?>
                        <a href="dossier_personnel_rh.php?id=<?= (int) $personnelId ?>" class="rounded-2xl bg-emerald-600 px-5 py-3 text-sm font-semibold text-white hover:bg-emerald-700">Dossier RH</a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($statusType !== null && $statusMessage !== null): ?>
                <div class="mt-8 rounded-2xl border px-5 py-4 text-sm font-medium <?= $statusType === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-rose-200 bg-rose-50 text-rose-800' ?>">
                    <?= htmlspecialchars((string) $statusMessage) ?>
                </div>
            <?php endif; ?>

            <?php if ($employee !== null): ?>
                <div class="mt-10 grid gap-6 lg:grid-cols-3">
                    <div class="rounded-[28px] border border-slate-200 bg-white p-6 shadow-sm">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <div class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">Collaborateur</div>
                                <div class="mt-2 text-xl font-semibold text-slate-950"><?= htmlspecialchars((string) $employee['full_name']) ?></div>
                                <div class="mt-1 text-sm text-slate-600"><?= htmlspecialchars((string) ($employee['matricule'] ?: 'Matricule non renseigne')) ?></div>
                            </div>
                            <span class="rounded-full px-3 py-1 text-xs font-semibold <?= (int) $employee['is_active'] === 1 ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' ?>">
                                <?= (int) $employee['is_active'] === 1 ? 'En poste' : 'Sorti' ?>
                            </span>
                        </div>

                        <div class="mt-6 space-y-3 text-sm text-slate-700">
                            <div class="flex items-center justify-between gap-4 rounded-2xl bg-slate-50 px-4 py-3">
                                <div>
                                    <div class="text-xs uppercase tracking-[0.22em] text-slate-400">Date de sortie</div>
                                    <div class="mt-1 font-semibold text-slate-800"><?= htmlspecialchars(rhFormatDate($employee['end_date'] ?? null)) ?></div>
                                </div>
                                <i class="fas fa-calendar-day text-slate-400"></i>
                            </div>
                            <div class="flex items-center justify-between gap-4 rounded-2xl bg-slate-50 px-4 py-3">
                                <div>
                                    <div class="text-xs uppercase tracking-[0.22em] text-slate-400">Motif</div>
                                    <?php
                                    $currentExitReasonId = (int) ($employee['exit_reason_id'] ?? 0);
                                    $currentExitReasonLabel = $employee['exit_reason_label'] ?? null;
                                    if (($currentExitReasonLabel === null || trim((string) $currentExitReasonLabel) === '') && $currentExitReasonId > 0) {
                                        $currentExitReasonLabel = $exitReasonsById[$currentExitReasonId]['label'] ?? null;
                                    }
                                    $currentExitReasonLabel = $currentExitReasonLabel !== null ? trim((string) $currentExitReasonLabel) : '';
                                    ?>
                                    <div class="mt-1 font-semibold text-slate-800">
                                        <?= htmlspecialchars($currentExitReasonLabel !== '' ? $currentExitReasonLabel : 'Non renseigne') ?>
                                        <?php if ($currentExitReasonId > 0): ?>
                                            <span class="ml-2 text-xs font-semibold text-slate-500">(#<?= (int) $currentExitReasonId ?>)</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <i class="fas fa-clipboard-list text-slate-400"></i>
                            </div>
                        </div>
                    </div>

                    <div class="lg:col-span-2 rounded-[28px] border border-slate-200 bg-white p-6 shadow-sm">
                        <form method="post" class="space-y-6">
                            <div class="grid gap-4 md:grid-cols-2">
                                <div>
                                    <label class="mb-2 block text-sm font-medium text-slate-700">Date de sortie</label>
                                    <input type="date" name="end_date" value="<?= htmlspecialchars((string) ($employee['end_date'] ?? '')) ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-amber-400">
                                </div>
                                <div>
                                    <div class="mb-2 flex items-center justify-between gap-3">
                                        <label class="block text-sm font-medium text-slate-700">Motif de sortie</label>
                                        <a href="parametrage_motifs_sortie_rh.php" class="text-xs font-semibold text-amber-700 hover:underline">Parametrer</a>
                                    </div>
                                    <input type="text" id="exit_reason_search" placeholder="Rechercher un motif..." class="mb-3 w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-amber-400">
                                    <select name="exit_reason_id" id="exit_reason_id" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-amber-400">
                                        <option value="">Non renseigne</option>
                                        <?php
                                        $selectedExitReasonId = (int) ($employee['exit_reason_id'] ?? 0);
                                        $selectedExitReason = $selectedExitReasonId > 0 ? ($exitReasonsById[$selectedExitReasonId] ?? null) : null;
                                        ?>
                                        <?php if ($selectedExitReason !== null && (int) ($selectedExitReason['is_active'] ?? 0) !== 1): ?>
                                            <option value="<?= (int) $selectedExitReasonId ?>" selected>
                                                <?= htmlspecialchars((string) ($selectedExitReason['label'] ?? 'Motif inactif')) ?> (inactif)
                                            </option>
                                        <?php endif; ?>
                                        <?php foreach ($exitReasonsActive as $reason): ?>
                                            <option value="<?= (int) $reason['id'] ?>" <?= (string) ($employee['exit_reason_id'] ?? '') === (string) $reason['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars((string) ($reason['label'] ?? '')) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="mt-2 text-xs text-slate-500">Tapez dans la recherche pour filtrer la liste.</div>
                                </div>
                            </div>

                            <div class="rounded-2xl border border-slate-100 bg-slate-50 px-5 py-4 text-sm text-slate-600">
                                <div class="font-semibold text-slate-800">Regles</div>
                                <ul class="mt-2 list-disc space-y-1 pl-5">
                                    <li>La sortie rend le collaborateur "Sorti" dans l'annuaire RH.</li>
                                    <li>La reintegration efface la date de sortie (et le motif si saisi).</li>
                                </ul>
                            </div>

                            <div class="flex flex-wrap items-center gap-3">
                                <button type="submit" name="form_action" value="set_exit" class="inline-flex items-center justify-center rounded-2xl bg-amber-500 px-5 py-3 text-sm font-semibold text-white transition hover:bg-amber-600">
                                    <i class="fas fa-sign-out-alt mr-2"></i>
                                    <?= (int) $employee['is_active'] === 1 ? 'Declarer la sortie' : 'Mettre a jour la sortie' ?>
                                </button>

                                <?php if ((int) $employee['is_active'] !== 1): ?>
                                    <button type="submit" name="form_action" value="clear_exit" class="inline-flex items-center justify-center rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-3 text-sm font-semibold text-emerald-800 transition hover:bg-emerald-100" onclick="return confirm('Confirmer la reintegration (effacer date de sortie) ?');">
                                        <i class="fas fa-undo mr-2 text-emerald-600"></i>
                                        Reintegrer
                                    </button>
                                <?php else: ?>
                                    <button type="submit" name="form_action" value="clear_exit" class="inline-flex items-center justify-center rounded-2xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-400" disabled>
                                        <i class="fas fa-undo mr-2"></i>
                                        Reintegrer
                                    </button>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    (function() {
        const searchInput = document.getElementById('exit_reason_search');
        const select = document.getElementById('exit_reason_id');

        if (!searchInput || !select) {
            return;
        }

        const normalize = (value) => (value || '').toString().toLowerCase();

        const filterOptions = () => {
            const query = normalize(searchInput.value).trim();
            const options = Array.from(select.options);

            options.forEach((option) => {
                if (option.value === '' || option.selected) {
                    option.hidden = false;
                    return;
                }

                if (query === '') {
                    option.hidden = false;
                    return;
                }

                option.hidden = normalize(option.text).indexOf(query) === -1;
            });
        };

        searchInput.addEventListener('input', filterOptions);
    })();
</script>

<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>