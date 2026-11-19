<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../controllers/RhPersonnelController.php';

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $payload = [
            'service_id' => (string) ($_POST['service_id'] ?? ''),
            'fonction_id' => (string) ($_POST['fonction_id'] ?? ''),
            'site_id' => (string) ($_POST['site_id'] ?? ''),
            'status_code' => (string) ($_POST['status_code'] ?? ''),
            'start_date' => (string) ($_POST['start_date'] ?? ''),
            'event_date' => (string) ($_POST['event_date'] ?? ''),
            'title' => trim((string) ($_POST['title'] ?? 'Mutation / affectation RH')),
            'reason' => trim((string) ($_POST['reason'] ?? '')),
        ];
        $directory->applyPersonnelMutation($personnelId, $payload, $actor);
        \AuditTrail::log('rh_personnel_mutation_applied', [
            'module' => 'rh',
            'action' => 'apply_mutation_dedicated_page',
            'entity_type' => 'personnel',
            'entity_id' => (string) $personnelId,
            'entity_label' => (string) $employee['full_name'],
            'details' => $payload,
        ], $pdo);
        header('Location: dossier_personnel_rh.php?id=' . $personnelId . '&mutation_saved=1');
        exit;
    } catch (Throwable $e) {
        $statusType = 'error';
        $statusMessage = $e->getMessage();
    }
}

$filterOptions = $directory->getFilters();
$pageTitle = 'Mutation RH';
$activeMenu = 'rh';
require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>
<div class="min-h-screen bg-[radial-gradient(circle_at_top_left,_rgba(250,232,255,0.9),_transparent_40%),radial-gradient(circle_at_top_right,_rgba(224,231,255,0.86),_transparent_35%),linear-gradient(180deg,_#f8fafc_0%,_#eef2ff_100%)] pb-12">
    <div class="container mx-auto px-4 py-8">
        <div class="relative overflow-hidden rounded-[32px] border border-fuchsia-100 bg-gradient-to-r from-slate-950 via-slate-900 to-fuchsia-900 px-8 py-10 text-white shadow-[0_24px_80px_rgba(15,23,42,0.30)]">
            <div class="absolute -right-16 -top-16 h-48 w-48 rounded-full bg-white/10 blur-2xl"></div>
            <p class="text-xs font-semibold uppercase tracking-[0.28em] text-fuchsia-300">Mutation RH</p>
            <h1 class="mt-3 text-3xl font-semibold">Mutation et affectation</h1>
            <p class="mt-2 text-sm text-slate-300"><?= htmlspecialchars((string) $employee['full_name']) ?> · <?= htmlspecialchars((string) ($employee['matricule'] ?: 'Matricule en attente')) ?></p>
            <div class="mt-5 flex flex-wrap gap-3">
                <a href="dossier_personnel_rh.php?id=<?= (int) $personnelId ?>" class="rounded-2xl border border-white/15 px-5 py-3 text-sm font-semibold text-white hover:bg-white/10">Retour dossier</a>
                <a href="modifier_dossier_personnel_rh.php?id=<?= (int) $personnelId ?>" class="rounded-2xl bg-white px-5 py-3 text-sm font-semibold text-slate-950 hover:bg-fuchsia-50">Modifier dossier</a>
            </div>
        </div>

        <?php if ($statusType !== null): ?>
            <div class="mt-6 rounded-2xl border border-rose-200 bg-rose-50 px-5 py-4 text-sm font-semibold text-rose-800"><?= htmlspecialchars((string) $statusMessage) ?></div>
        <?php endif; ?>

        <form method="post" class="mt-6 rounded-[28px] border border-white/80 bg-white/92 p-6 shadow-[0_18px_60px_rgba(15,23,42,0.07)] backdrop-blur">
            <input type="hidden" name="id" value="<?= (int) $personnelId ?>">
            <section class="rounded-[26px] border border-slate-100 bg-slate-50/70 p-5">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.26em] text-fuchsia-600">Mouvement RH</p>
                        <h2 class="mt-2 text-2xl font-semibold text-slate-950">Mutation et affectation</h2>
                    </div>
                    <span class="rounded-full bg-fuchsia-50 px-4 py-2 text-xs font-semibold text-fuchsia-700">Action metier historisee</span>
                </div>
                <div class="mt-6 grid gap-5 md:grid-cols-2 xl:grid-cols-3">
                <div>
                    <label class="mb-2 block text-sm font-medium text-slate-700">Titre</label>
                    <input type="text" name="title" value="Mutation / affectation RH" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm">
                </div>
                <div>
                    <label class="mb-2 block text-sm font-medium text-slate-700">Date effet</label>
                    <input type="date" name="event_date" required value="<?= date('Y-m-d') ?>" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm">
                </div>
                <div>
                    <label class="mb-2 block text-sm font-medium text-slate-700">Nouvelle prise de service</label>
                    <input type="date" name="start_date" value="<?= htmlspecialchars((string) ($employee['start_date'] ?? '')) ?>" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm">
                </div>
                <div><label class="mb-2 block text-sm font-medium text-slate-700">Service</label><select name="service_id" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm"><option value="">Conserver</option><?php foreach ($filterOptions['services'] as $service): ?><option value="<?= (int) $service['id'] ?>" <?= (string) ($employee['service_id'] ?? '') === (string) $service['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $service['label']) ?></option><?php endforeach; ?></select></div>
                <div><label class="mb-2 block text-sm font-medium text-slate-700">Fonction</label><select name="fonction_id" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm"><option value="">Conserver</option><?php foreach ($filterOptions['fonctions'] as $fonction): ?><option value="<?= (int) $fonction['id'] ?>" <?= (string) ($employee['fonction_id'] ?? '') === (string) $fonction['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $fonction['label']) ?></option><?php endforeach; ?></select></div>
                <div><label class="mb-2 block text-sm font-medium text-slate-700">Statut</label><select name="status_code" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm"><option value="">Conserver</option><?php foreach ($filterOptions['statuts'] as $statut): ?><option value="<?= (int) $statut['id'] ?>" <?= (string) ($employee['status_code'] ?? '') === (string) $statut['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $statut['label']) ?></option><?php endforeach; ?></select></div>
                <div><label class="mb-2 block text-sm font-medium text-slate-700">Site</label><select name="site_id" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm"><option value="">Conserver</option><?php foreach ($filterOptions['sites'] as $site): ?><option value="<?= (int) $site['id'] ?>" <?= (string) ($employee['site_id'] ?? '') === (string) $site['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $site['label']) ?></option><?php endforeach; ?></select></div>
                <div class="md:col-span-2 xl:col-span-3">
                    <label class="mb-2 block text-sm font-medium text-slate-700">Motif ou commentaire</label>
                    <textarea name="reason" rows="5" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm" placeholder="Motif de mutation, affectation, changement de statut..."></textarea>
                </div>
                </div>
            </section>
            <div class="mt-6 flex justify-end gap-3">
                <a href="dossier_personnel_rh.php?id=<?= (int) $personnelId ?>" class="rounded-2xl border border-slate-200 px-5 py-3 text-sm font-semibold text-slate-700">Annuler</a>
                <button class="rounded-2xl bg-fuchsia-600 px-6 py-3 text-sm font-semibold text-white">Enregistrer la mutation</button>
            </div>
        </form>
    </div>
</div>
<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>
