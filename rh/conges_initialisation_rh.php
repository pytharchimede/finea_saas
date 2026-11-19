<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../controllers/RhLegalRequestController.php';
require_once __DIR__ . '/../controllers/RhPersonnelController.php';

$pdo = \Database::getConnection();
$rhLegalController = new \RhLegalRequestController($pdo);
$manager = $rhLegalController->legalRequests();
$directory = (new \RhPersonnelController($pdo))->service();

$userId = (int) ($_SESSION['user_id'] ?? 0);
$errors = [];
$success = '';
$selectedPersonnelId = max(0, (int) ($_GET['personnel_id'] ?? $_POST['personnel_id'] ?? 0));

$eligibleEmployeeIds = [];
try {
    $eligibleStmt = $pdo->query("SELECT id_personnel_soignant
        FROM personnel
        WHERE (date_sortie IS NULL OR date_sortie = '0000-00-00')
          AND COALESCE(NULLIF(personnel_category, ''), 'interne') <> 'prestataire'
          AND COALESCE(statut_pers_soignant_code, 0) NOT IN (2, 3)");
    foreach (($eligibleStmt ? $eligibleStmt->fetchAll(PDO::FETCH_COLUMN) : []) as $id) {
        $eligibleEmployeeIds[(int) $id] = true;
    }
} catch (Throwable $e) {
    $errors[] = 'Impossible de déterminer le périmètre des salariés internes.';
}

if ($selectedPersonnelId > 0 && !isset($eligibleEmployeeIds[$selectedPersonnelId])) {
    $errors[] = 'Les prestataires et consultants ne sont pas concernés par les soldes de congés salariés.';
    $selectedPersonnelId = 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postResult = $rhLegalController->handleLeaveInitializationPost($_POST, $userId);
    if (!empty($postResult['redirect'])) {
        header('Location: ' . $postResult['redirect']);
        exit;
    }
    $errors = array_merge($errors, (array) ($postResult['errors'] ?? []));
}

if (!empty($_GET['saved'])) {
    $success = 'Situation initiale de congé enregistrée.';
} elseif (!empty($_GET['settings_saved'])) {
    $success = 'Paramètres congés enregistrés.';
}

$settings = $manager->getLeaveSettings();
$personnelChoices = array_values(array_filter(
    $directory->getPersonnelChoices('', 3000),
    static fn(array $person): bool => isset($eligibleEmployeeIds[(int) ($person['id'] ?? 0)])
));
$selectedPersonnel = $selectedPersonnelId > 0 ? $directory->getPersonnelDetail($selectedPersonnelId) : null;
$opening = $selectedPersonnelId > 0 ? $manager->getLeaveOpeningBalance($selectedPersonnelId) : null;
$situation = null;
if ($selectedPersonnel) {
    $hireDate = (string) (($selectedPersonnel['start_date'] ?? '') ?: ($selectedPersonnel['hire_date'] ?? ''));
    $situation = $manager->calculateLeaveSituation($selectedPersonnelId, $hireDate);
}
$recentOpeningBalances = array_values(array_filter(
    $manager->listLeaveOpeningBalances(50),
    static fn(array $row): bool => isset($eligibleEmployeeIds[(int) ($row['personnel_id'] ?? 0)])
));
$recentOpeningBalances = array_slice($recentOpeningBalances, 0, 20);

$pageTitle = 'Initialisation congés';
$activeMenu = 'rh';
require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>

<main class="mx-auto max-w-7xl px-4 py-8">
    <header class="rounded-[28px] bg-gradient-to-r from-slate-950 via-cyan-950 to-emerald-900 p-8 text-white shadow-sm">
        <p class="text-xs font-black uppercase tracking-[.25em] text-amber-300">Finéa · Ressources humaines</p>
        <div class="mt-2 flex flex-wrap items-end justify-between gap-5">
            <div class="max-w-3xl">
                <h1 class="text-3xl font-black md:text-4xl">Soldes de congés des salariés</h1>
                <p class="mt-2 text-slate-300">Initialisez uniquement les droits des salariés internes. Les prestataires et consultants sont volontairement exclus de ce périmètre.</p>
            </div>
            <a href="dashboard_rh.php" class="rounded-xl bg-white px-4 py-3 text-sm font-black text-slate-950">Retour RH</a>
        </div>
    </header>

    <?php if ($success !== ''): ?><div class="mt-5 rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm font-semibold text-emerald-800"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php if ($errors): ?><div class="mt-5 rounded-2xl border border-rose-200 bg-rose-50 px-5 py-4 text-sm text-rose-800"><?php foreach ($errors as $error): ?><div><?= htmlspecialchars((string) $error) ?></div><?php endforeach; ?></div><?php endif; ?>

    <section class="mt-6 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="text-xs font-black uppercase tracking-[.2em] text-cyan-700">Salarié concerné</p>
                <h2 class="mt-1 text-xl font-black text-slate-950">Situation individuelle</h2>
            </div>
            <form method="get" class="w-full lg:w-[560px]">
                <select name="personnel_id" onchange="this.form.submit()" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                    <option value="">Sélectionner un salarié interne</option>
                    <?php foreach ($personnelChoices as $person): ?>
                        <option value="<?= (int) ($person['id'] ?? 0) ?>" <?= $selectedPersonnelId === (int) ($person['id'] ?? 0) ? 'selected' : '' ?>><?= htmlspecialchars((string) ($person['full_name'] ?? 'Personnel')) ?><?= !empty($person['service_label']) ? ' · ' . htmlspecialchars((string) $person['service_label']) : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <?php if ($selectedPersonnel): ?>
            <div class="mt-6 grid gap-4 md:grid-cols-4">
                <div class="rounded-2xl bg-slate-50 p-4"><small class="font-bold uppercase text-slate-500">Salarié</small><b class="mt-2 block text-slate-950"><?= htmlspecialchars((string) ($selectedPersonnel['full_name'] ?? '')) ?></b></div>
                <div class="rounded-2xl bg-slate-50 p-4"><small class="font-bold uppercase text-slate-500">Matricule</small><b class="mt-2 block text-slate-950"><?= htmlspecialchars((string) ($selectedPersonnel['matricule'] ?? '—')) ?></b></div>
                <div class="rounded-2xl bg-cyan-50 p-4"><small class="font-bold uppercase text-cyan-700">Solde calculé</small><b class="mt-2 block text-2xl text-cyan-950"><?= number_format((float) ($situation['balance_days'] ?? 0), 2, ',', ' ') ?> j</b></div>
                <div class="rounded-2xl bg-slate-50 p-4"><small class="font-bold uppercase text-slate-500">Référence</small><b class="mt-2 block text-slate-950"><?= htmlspecialchars((string) ($situation['reference_date'] ?? '—')) ?></b></div>
            </div>

            <form method="post" class="mt-6 rounded-3xl border border-cyan-100 bg-cyan-50/40 p-6">
                <input type="hidden" name="action" value="save_opening_balance">
                <input type="hidden" name="personnel_id" value="<?= (int) $selectedPersonnelId ?>">
                <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-4">
                    <label class="text-sm font-bold text-slate-700">Date de référence<input type="date" name="reference_date" value="<?= htmlspecialchars((string) ($_POST['reference_date'] ?? ($opening['reference_date'] ?? ($settings['leave_module_start_date'] ?? date('Y-m-d'))))) ?>" class="mt-2 w-full rounded-xl border border-slate-200 bg-white px-4 py-3" required></label>
                    <label class="text-sm font-bold text-slate-700">Solde initial validé<input type="number" step="0.01" name="balance_days_opening" value="<?= htmlspecialchars((string) ($_POST['balance_days_opening'] ?? ($opening['balance_days_opening'] ?? '0'))) ?>" class="mt-2 w-full rounded-xl border border-slate-200 bg-white px-4 py-3" required></label>
                    <label class="text-sm font-bold text-slate-700">Droits acquis historiques<input type="number" step="0.01" name="accrued_days_opening" value="<?= htmlspecialchars((string) ($_POST['accrued_days_opening'] ?? ($opening['accrued_days_opening'] ?? '0'))) ?>" class="mt-2 w-full rounded-xl border border-slate-200 bg-white px-4 py-3"></label>
                    <label class="text-sm font-bold text-slate-700">Jours consommés historiques<input type="number" step="0.01" name="consumed_days_opening" value="<?= htmlspecialchars((string) ($_POST['consumed_days_opening'] ?? ($opening['consumed_days_opening'] ?? '0'))) ?>" class="mt-2 w-full rounded-xl border border-slate-200 bg-white px-4 py-3"></label>
                </div>
                <div class="mt-5 grid gap-5 md:grid-cols-3">
                    <label class="text-sm font-bold text-slate-700">Dernier départ connu<input type="date" name="last_leave_start_date" value="<?= htmlspecialchars((string) ($_POST['last_leave_start_date'] ?? ($opening['last_leave_start_date'] ?? ''))) ?>" class="mt-2 w-full rounded-xl border border-slate-200 bg-white px-4 py-3"></label>
                    <label class="text-sm font-bold text-slate-700">Dernière fin connue<input type="date" name="last_leave_end_date" value="<?= htmlspecialchars((string) ($_POST['last_leave_end_date'] ?? ($opening['last_leave_end_date'] ?? ''))) ?>" class="mt-2 w-full rounded-xl border border-slate-200 bg-white px-4 py-3"></label>
                    <label class="text-sm font-bold text-slate-700">Dernière reprise<input type="date" name="last_return_date" value="<?= htmlspecialchars((string) ($_POST['last_return_date'] ?? ($opening['last_return_date'] ?? ''))) ?>" class="mt-2 w-full rounded-xl border border-slate-200 bg-white px-4 py-3"></label>
                </div>
                <label class="mt-5 block text-sm font-bold text-slate-700">Commentaire RH<textarea name="comment" rows="3" class="mt-2 w-full rounded-xl border border-slate-200 bg-white px-4 py-3"><?= htmlspecialchars((string) ($_POST['comment'] ?? ($opening['comment'] ?? ''))) ?></textarea></label>
                <div class="mt-5 flex justify-end"><button class="rounded-xl bg-cyan-700 px-5 py-3 font-black text-white">Enregistrer la situation initiale</button></div>
            </form>
        <?php else: ?>
            <div class="mt-6 rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-6 py-12 text-center text-sm text-slate-500">Sélectionnez un salarié interne pour consulter ou initialiser son solde.</div>
        <?php endif; ?>
    </section>

    <section class="mt-6 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-4"><div><p class="text-xs font-black uppercase tracking-[.2em] text-slate-500">Paramétrage global</p><h2 class="mt-1 text-xl font-black text-slate-950">Règles de calcul des congés salariés</h2></div><span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-bold text-amber-900">Prestataires exclus</span></div>
        <form method="post" class="mt-5 grid gap-5 lg:grid-cols-2">
            <input type="hidden" name="action" value="save_settings"><?php if ($selectedPersonnelId > 0): ?><input type="hidden" name="personnel_id" value="<?= (int) $selectedPersonnelId ?>"><?php endif; ?>
            <label class="flex items-start gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4"><input type="checkbox" name="require_opening_balance_for_existing_staff" value="1" class="mt-1" <?= !empty($settings['require_opening_balance_for_existing_staff']) ? 'checked' : '' ?>><span><b class="block text-slate-950">Solde initial requis pour les anciens salariés</b><small class="mt-1 block text-slate-500">Évite un calcul rétroactif sans historique RH fiable.</small></span></label>
            <label class="flex items-start gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4"><input type="checkbox" name="deduct_pending_leave_requests" value="1" class="mt-1" <?= !empty($settings['deduct_pending_leave_requests']) ? 'checked' : '' ?>><span><b class="block text-slate-950">Soustraire les demandes en attente</b><small class="mt-1 block text-slate-500">Réserve les jours déjà demandés avant décision RH.</small></span></label>
            <label class="text-sm font-bold text-slate-700">Date officielle de démarrage du module<input type="date" name="leave_module_start_date" value="<?= htmlspecialchars((string) ($settings['leave_module_start_date'] ?? date('Y-m-d'))) ?>" class="mt-2 w-full rounded-xl border border-slate-200 px-4 py-3"></label>
            <label class="text-sm font-bold text-slate-700">Politique sans solde initial<select name="default_existing_staff_policy" class="mt-2 w-full rounded-xl border border-slate-200 px-4 py-3"><?php foreach (['block_until_initialized'=>'Bloquer jusqu’à initialisation RH','allow_with_hr_validation'=>'Autoriser avec validation RH spéciale','calculate_from_hire_date'=>'Calculer depuis la date d’embauche'] as $key=>$label): ?><option value="<?= htmlspecialchars($key) ?>" <?= (string) ($settings['default_existing_staff_policy'] ?? '') === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option><?php endforeach; ?></select></label>
            <label class="text-sm font-bold text-slate-700">Acquisition mensuelle<input type="number" step="0.01" min="0" name="monthly_accrual_days" value="<?= htmlspecialchars((string) ($settings['monthly_accrual_days'] ?? '2.2')) ?>" class="mt-2 w-full rounded-xl border border-slate-200 px-4 py-3"></label>
            <label class="text-sm font-bold text-slate-700">Ancienneté minimale (mois)<input type="number" min="0" name="minimum_seniority_months" value="<?= htmlspecialchars((string) ($settings['minimum_seniority_months'] ?? '12')) ?>" class="mt-2 w-full rounded-xl border border-slate-200 px-4 py-3"></label>
            <div class="lg:col-span-2 flex justify-end"><button class="rounded-xl bg-slate-950 px-5 py-3 font-black text-white">Enregistrer les paramètres</button></div>
        </form>
    </section>

    <section class="mt-6 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
        <h2 class="text-xl font-black text-slate-950">Dernières reprises enregistrées</h2>
        <div class="mt-4 overflow-x-auto rounded-2xl border border-slate-200"><table class="w-full min-w-[760px] text-sm"><thead class="bg-slate-100 text-left text-xs uppercase text-slate-500"><tr><th class="p-3">Salarié</th><th class="p-3">Référence</th><th class="p-3">Solde</th><th class="p-3">Dernière reprise</th><th class="p-3">Commentaire</th></tr></thead><tbody><?php foreach ($recentOpeningBalances as $row): ?><tr class="border-t"><td class="p-3 font-bold text-slate-950"><?= htmlspecialchars((string) ($row['personnel_name'] ?? '')) ?></td><td class="p-3"><?= htmlspecialchars((string) ($row['reference_date'] ?? '')) ?></td><td class="p-3 font-black text-cyan-800"><?= number_format((float) ($row['balance_days_opening'] ?? 0), 2, ',', ' ') ?> j</td><td class="p-3"><?= htmlspecialchars((string) ($row['last_return_date'] ?? '—')) ?></td><td class="p-3 text-slate-600"><?= htmlspecialchars((string) ($row['comment'] ?? '')) ?></td></tr><?php endforeach; ?><?php if (!$recentOpeningBalances): ?><tr><td colspan="5" class="p-6 text-center text-slate-500">Aucune reprise salariée enregistrée.</td></tr><?php endif; ?></tbody></table></div>
    </section>
</main>

<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>
