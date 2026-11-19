<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../controllers/RhPersonnelController.php';

$pdo = \Database::getConnection();
$controller = new \RhPersonnelController($pdo);
$directory = $controller->service();

$statusType = null;
$statusMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postResult = $controller->handleExitReasonPost($_POST, [
        'user_id' => $_SESSION['user_id'] ?? null,
        'user_name' => $_SESSION['user_name'] ?? null,
    ]);
    if (!empty($postResult['redirect'])) {
        header('Location: ' . $postResult['redirect']);
        exit;
    }
    $statusType = $postResult['statusType'];
    $statusMessage = $postResult['statusMessage'];
}

if (isset($_GET['saved'])) {
    $statusType = 'success';
    $statusMessage = 'Motif ajouté.';
} elseif (isset($_GET['updated'])) {
    $statusType = 'success';
    $statusMessage = 'Motif mis à jour.';
} elseif (isset($_GET['deleted'])) {
    $statusType = 'success';
    $statusMessage = 'Motif supprimé.';
}

$reasons = $directory->getExitReasons(true);

$pageTitle = 'Paramétrage motifs de sortie';
$activeMenu = 'rh';

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>
<div class="min-h-screen bg-[radial-gradient(circle_at_top,_rgba(240,253,244,0.95),_transparent_35%),radial-gradient(circle_at_right,_rgba(219,234,254,0.85),_transparent_30%),linear-gradient(180deg,_#f8fafc_0%,_#eef2ff_100%)] pb-12">
    <div class="container mx-auto px-4 py-8">
        <div class="rounded-[32px] border border-slate-200 bg-white/90 p-8 shadow-[0_18px_60px_rgba(15,23,42,0.07)] backdrop-blur">
            <div class="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.32em] text-emerald-600">RH • Paramétrage</p>
                    <h1 class="mt-3 text-3xl font-semibold tracking-tight text-slate-950 md:text-4xl">Motifs de sortie</h1>
                    <p class="mt-3 max-w-3xl text-sm text-slate-600 md:text-base">Gestion des libellés proposés lors d'une sortie du personnel (select + recherche).</p>
                </div>
                <div class="flex flex-wrap gap-3">
                    <a href="dashboard_rh.php" class="rounded-2xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50">Tableau de bord RH</a>
                    <a href="liste_personnel_rh.php" class="rounded-2xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50">Annuaire</a>
                </div>
            </div>

            <?php if ($statusType !== null && $statusMessage !== null): ?>
                <div class="mt-8 rounded-2xl border px-5 py-4 text-sm font-medium <?= $statusType === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-rose-200 bg-rose-50 text-rose-800' ?>">
                    <?= htmlspecialchars((string) $statusMessage) ?>
                </div>
            <?php endif; ?>

            <div class="mt-10 grid gap-6 lg:grid-cols-3">
                <div class="rounded-[28px] border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="text-sm font-semibold text-slate-900">Ajouter un motif</div>
                    <form method="post" class="mt-5 space-y-4">
                        <input type="hidden" name="action" value="create">
                        <div>
                            <label class="mb-2 block text-sm font-medium text-slate-700">Libellé</label>
                            <input type="text" name="label" required class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none focus:border-emerald-400" placeholder="Ex: Fin de contrat">
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label class="mb-2 block text-sm font-medium text-slate-700">Ordre</label>
                                <input type="number" name="sort_order" value="0" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none focus:border-emerald-400">
                            </div>
                            <div class="flex items-end">
                                <label class="inline-flex items-center gap-2 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-medium text-slate-700">
                                    <input type="checkbox" name="is_active" value="1" checked>
                                    Actif
                                </label>
                            </div>
                        </div>
                        <button type="submit" class="inline-flex w-full items-center justify-center rounded-2xl bg-emerald-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-emerald-700">
                            <i class="fas fa-plus mr-2"></i>
                            Ajouter
                        </button>
                    </form>

                    <div class="mt-6 rounded-2xl border border-slate-100 bg-slate-50 px-5 py-4 text-sm text-slate-600">
                        <div class="font-semibold text-slate-800">Bon à savoir</div>
                        <ul class="mt-2 list-disc space-y-1 pl-5">
                            <li>Désactiver un motif le retire de la liste sans casser l'historique.</li>
                            <li>La suppression est bloquée si le motif est déjà utilisé.</li>
                        </ul>
                    </div>
                </div>

                <div class="lg:col-span-2 rounded-[28px] border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <div class="text-sm font-semibold text-slate-900">Liste</div>
                            <div class="mt-1 text-sm text-slate-600"><?= count($reasons) ?> élément(s)</div>
                        </div>
                    </div>

                    <div class="mt-5 overflow-auto">
                        <table class="min-w-full text-left text-sm">
                            <thead class="text-xs uppercase tracking-wider text-slate-500">
                                <tr>
                                    <th class="py-3 pr-4">ID</th>
                                    <th class="py-3 pr-4">Libellé</th>
                                    <th class="py-3 pr-4">Ordre</th>
                                    <th class="py-3 pr-4">Statut</th>
                                    <th class="py-3">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php if (empty($reasons)): ?>
                                    <tr>
                                        <td colspan="5" class="py-6 text-center text-slate-500">Aucun motif configuré.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($reasons as $reason): ?>
                                        <?php $updateFormId = 'update_exit_reason_' . (int) $reason['id']; ?>
                                        <tr>
                                            <td class="py-3 pr-4 font-semibold text-slate-700">#<?= (int) $reason['id'] ?></td>
                                            <td class="py-3 pr-4">
                                                <input type="text" name="label" form="<?= htmlspecialchars($updateFormId) ?>" value="<?= htmlspecialchars((string) ($reason['label'] ?? '')) ?>" class="w-72 max-w-full rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm outline-none focus:border-emerald-400" required>
                                            </td>
                                            <td class="py-3 pr-4">
                                                <input type="number" name="sort_order" form="<?= htmlspecialchars($updateFormId) ?>" value="<?= htmlspecialchars((string) ($reason['sort_order'] ?? 0)) ?>" class="w-24 rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-emerald-400">
                                            </td>
                                            <td class="py-3 pr-4">
                                                <label class="inline-flex items-center gap-2 rounded-2xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-700">
                                                    <input type="checkbox" name="is_active" form="<?= htmlspecialchars($updateFormId) ?>" value="1" <?= !empty($reason['is_active']) ? 'checked' : '' ?>>
                                                    <?= !empty($reason['is_active']) ? 'Actif' : 'Inactif' ?>
                                                </label>
                                            </td>
                                            <td class="py-3">
                                                <div class="flex flex-wrap gap-2">
                                                    <form method="post" id="<?= htmlspecialchars($updateFormId) ?>">
                                                        <input type="hidden" name="action" value="update">
                                                        <input type="hidden" name="id" value="<?= (int) $reason['id'] ?>">
                                                        <button type="submit" class="inline-flex items-center rounded-2xl bg-slate-900 px-4 py-2 text-xs font-semibold text-white hover:bg-slate-800">
                                                            <i class="fas fa-save mr-2"></i>Enregistrer
                                                        </button>
                                                    </form>

                                                    <form method="post" onsubmit="return confirm('Supprimer ce motif ?');">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="id" value="<?= (int) $reason['id'] ?>">
                                                        <button type="submit" class="inline-flex items-center rounded-2xl border border-rose-200 bg-rose-50 px-4 py-2 text-xs font-semibold text-rose-700 hover:bg-rose-100">
                                                            <i class="fas fa-trash mr-2"></i>Supprimer
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>
