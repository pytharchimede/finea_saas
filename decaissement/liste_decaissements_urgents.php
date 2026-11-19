<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess('liste_decaissements_urgents.php');

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/UrgentDisbursement.php';
require_once __DIR__ . '/../controllers/UrgentDisbursementController.php';

$pdo = \Database::getConnection();
$controller = new \UrgentDisbursementController($pdo);
$repo = $controller->service()->repository();
$flash = '';
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['urgent_action'])) {
    try {
        $flash = $controller->handleAccountingAction($_POST, (int)($_SESSION['user_id'] ?? 0), (string)($_SESSION['nom'] ?? $_SESSION['username'] ?? 'Comptabilite'));
    } catch (Throwable $e) {
        $flash = $e->getMessage();
        $flashType = 'error';
    }
}

$hasExplicitFilters = array_key_exists('status', $_GET) || array_key_exists('q', $_GET)
    || array_key_exists('date_from', $_GET) || array_key_exists('date_to', $_GET)
    || array_key_exists('chantier', $_GET) || array_key_exists('amount_min', $_GET)
    || array_key_exists('amount_max', $_GET);
$filters = [
    'status' => (string)($_GET['status'] ?? ($hasExplicitFilters ? '' : UrgentDisbursement::STATUS_SUBMITTED)),
    'q' => (string)($_GET['q'] ?? ''),
    'date_from' => (string)($_GET['date_from'] ?? ''),
    'date_to' => (string)($_GET['date_to'] ?? ''),
    'chantier' => (string)($_GET['chantier'] ?? ''),
    'amount_min' => (string)($_GET['amount_min'] ?? ''),
    'amount_max' => (string)($_GET['amount_max'] ?? ''),
];
$rows = $repo->list($filters);
$summary = $repo->summarize($rows);
$blockedChantiers = $repo->listBlockedChantiers();
$statusOptions = [UrgentDisbursement::STATUS_SUBMITTED, UrgentDisbursement::STATUS_DECAISSED, UrgentDisbursement::STATUS_REGULARIZATION_PENDING, UrgentDisbursement::STATUS_REGULARIZED, UrgentDisbursement::STATUS_REJECTED];
$exportQuery = http_build_query(array_filter($filters, static fn($value): bool => trim((string)$value) !== ''));

function urgentPagePhotoUrl(array $row): string
{
    $root = dirname(__DIR__);
    foreach ([(string)($row['requester_personnel_photo'] ?? ''), (string)($row['requester_user_photo'] ?? '')] as $path) {
        $path = trim($path);
        if ($path === '') continue;
        if (preg_match('/^https?:\/\//i', $path)) return $path;
        $clean = ltrim(str_replace('\\', '/', $path), '/');
        foreach ([$clean, 'uploads/personnel/' . basename($clean), 'uploads/photos/' . basename($clean), 'photo/' . basename($clean)] as $candidate) {
            if ($candidate !== '' && is_file($root . '/' . $candidate)) return '../' . $candidate;
        }
    }
    return '';
}

function urgentPageInitials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $letters = '';
    foreach (array_slice($parts, 0, 2) as $part) $letters .= mb_substr($part, 0, 1, 'UTF-8');
    return mb_strtoupper($letters ?: 'U', 'UTF-8');
}

$pageTitle = 'Decaissements urgents';
$activeMenu = 'urgences';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<style>
    .urgent-shell { background: radial-gradient(circle at top left, rgba(225,29,72,.12), transparent 34rem), linear-gradient(180deg,#f8fafc 0%,#fff 100%); }
    .urgent-panel { border:1px solid rgba(226,232,240,.9); background:rgba(255,255,255,.94); box-shadow:0 18px 50px rgba(15,23,42,.07); }
    .urgent-card { border:1px solid #e2e8f0; background:#fff; box-shadow:0 14px 34px rgba(15,23,42,.06); }
    .urgent-input { height:44px; border-radius:14px; border:1px solid #e2e8f0; background:#f8fafc; padding:0 12px; outline:none; width:100%; font-size:13px; }
    .urgent-input:focus { border-color:#fb7185; background:#fff; box-shadow:0 0 0 4px rgba(251,113,133,.16); }
    .urgent-action { display:inline-flex; align-items:center; justify-content:center; gap:.45rem; border-radius:14px; padding:.72rem 1rem; font-size:.82rem; font-weight:800; transition:.18s ease; }
</style>

<div class="urgent-shell mx-auto max-w-none py-8 px-4 2xl:px-8">
    <div class="mb-6 flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
        <div>
            <p class="text-xs font-semibold uppercase tracking-[0.28em] text-rose-600">Comptabilite</p>
            <h1 class="mt-2 text-3xl font-bold text-slate-950">Decaissements urgents</h1>
            <p class="mt-1 text-sm text-slate-600">Suivi, validation, regularisation et export professionnel des demandes urgentes.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a class="urgent-action bg-indigo-700 text-white hover:bg-indigo-800" href="validations_regularisations_urgentes.php">Régularisations urgentes</a>
            <a target="_blank" class="urgent-action border border-slate-200 bg-white text-slate-800 hover:bg-slate-50" href="request/export_decaissements_urgents.php?format=pdf<?= $exportQuery ? '&' . htmlspecialchars($exportQuery) : '' ?>">PDF recherche</a>
            <a class="urgent-action border border-emerald-200 bg-emerald-50 text-emerald-800 hover:bg-emerald-100" href="request/export_decaissements_urgents.php?format=excel<?= $exportQuery ? '&' . htmlspecialchars($exportQuery) : '' ?>">Excel recherche</a>
            <button type="button" id="exportSelectedPdf" class="urgent-action border border-rose-200 bg-rose-50 text-rose-800 hover:bg-rose-100">PDF selection</button>
            <button type="button" id="exportSelectedExcel" class="urgent-action bg-slate-900 text-white hover:bg-slate-800">Excel selection</button>
        </div>
    </div>

    <?php if ($blockedChantiers): ?>
        <div class="mb-6 rounded-3xl border border-rose-200 bg-rose-50 p-5 text-rose-900">
            <div class="font-bold">Chantiers bloques pour regularisation urgente</div>
            <div class="mt-2 flex flex-wrap gap-2">
                <?php foreach ($blockedChantiers as $blocked): ?>
                    <span class="rounded-full border border-rose-200 bg-white px-3 py-1 text-sm font-semibold">
                        <?= htmlspecialchars((string)($blocked['chantier_label'] ?? 'Chantier')) ?>
                        (<?= htmlspecialchars((string)($blocked['requester_name'] ?? 'Responsable')) ?>)
                    </span>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($flash !== ''): ?>
        <div class="mb-6 rounded-2xl border px-4 py-3 text-sm font-semibold <?= $flashType === 'error' ? 'border-rose-200 bg-rose-50 text-rose-700' : 'border-emerald-200 bg-emerald-50 text-emerald-700' ?>">
            <?= htmlspecialchars($flash) ?>
        </div>
    <?php endif; ?>

    <section class="urgent-panel mb-6 rounded-3xl p-5">
        <form method="get" class="grid gap-4 md:grid-cols-4 xl:grid-cols-7">
            <label class="block xl:col-span-2"><span class="mb-1 block text-xs font-bold uppercase tracking-wide text-slate-500">Recherche</span><input type="text" name="q" value="<?= htmlspecialchars((string)$filters['q']) ?>" class="urgent-input" placeholder="Code, demandeur, chantier, fiche..."></label>
            <label class="block"><span class="mb-1 block text-xs font-bold uppercase tracking-wide text-slate-500">Statut</span><select name="status" class="urgent-input"><option value="">Tous</option><?php foreach ($statusOptions as $status): ?><option value="<?= htmlspecialchars($status) ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>><?= htmlspecialchars(UrgentDisbursement::statusLabel($status)) ?></option><?php endforeach; ?></select></label>
            <label class="block"><span class="mb-1 block text-xs font-bold uppercase tracking-wide text-slate-500">Du</span><input type="date" name="date_from" value="<?= htmlspecialchars((string)$filters['date_from']) ?>" class="urgent-input"></label>
            <label class="block"><span class="mb-1 block text-xs font-bold uppercase tracking-wide text-slate-500">Au</span><input type="date" name="date_to" value="<?= htmlspecialchars((string)$filters['date_to']) ?>" class="urgent-input"></label>
            <label class="block"><span class="mb-1 block text-xs font-bold uppercase tracking-wide text-slate-500">Chantier</span><input type="text" name="chantier" value="<?= htmlspecialchars((string)$filters['chantier']) ?>" class="urgent-input" placeholder="Code / nom"></label>
            <div class="grid grid-cols-2 gap-2">
                <label class="block"><span class="mb-1 block text-xs font-bold uppercase tracking-wide text-slate-500">Min</span><input type="number" step="0.01" name="amount_min" value="<?= htmlspecialchars((string)$filters['amount_min']) ?>" class="urgent-input"></label>
                <label class="block"><span class="mb-1 block text-xs font-bold uppercase tracking-wide text-slate-500">Max</span><input type="number" step="0.01" name="amount_max" value="<?= htmlspecialchars((string)$filters['amount_max']) ?>" class="urgent-input"></label>
            </div>
            <div class="flex items-end gap-2 xl:col-span-7">
                <button class="urgent-action bg-rose-600 text-white hover:bg-rose-700">Rechercher</button>
                <a href="liste_decaissements_urgents.php" class="urgent-action border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">Reinitialiser</a>
            </div>
        </form>
    </section>

    <section class="mb-6 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <article class="urgent-panel rounded-3xl p-5"><div class="text-xs font-bold uppercase tracking-[0.18em] text-slate-500">Demandes</div><div class="mt-2 text-3xl font-black text-slate-950"><?= (int)$summary['count'] ?></div></article>
        <article class="urgent-panel rounded-3xl p-5"><div class="text-xs font-bold uppercase tracking-[0.18em] text-slate-500">Montant total</div><div class="mt-2 text-3xl font-black text-emerald-700"><?= number_format((float)$summary['total'], 0, ',', ' ') ?> CFA</div></article>
        <?php foreach ([UrgentDisbursement::STATUS_SUBMITTED, UrgentDisbursement::STATUS_DECAISSED] as $status): ?>
            <?php $meta = $summary['by_status'][$status] ?? ['count' => 0, 'total' => 0]; ?>
            <article class="urgent-panel rounded-3xl p-5"><div class="text-xs font-bold uppercase tracking-[0.18em] text-slate-500"><?= htmlspecialchars(UrgentDisbursement::statusLabel($status)) ?></div><div class="mt-2 text-2xl font-black text-slate-950"><?= (int)$meta['count'] ?></div><div class="mt-1 text-sm font-bold text-slate-500"><?= number_format((float)$meta['total'], 0, ',', ' ') ?> CFA</div></article>
        <?php endforeach; ?>
    </section>

    <form id="selectedExportForm" method="post" target="_blank" action="request/export_decaissements_urgents.php">
        <input type="hidden" name="format" id="selectedExportFormat" value="pdf">
        <?php foreach ($filters as $key => $value): ?><input type="hidden" name="<?= htmlspecialchars($key) ?>" value="<?= htmlspecialchars((string)$value) ?>"><?php endforeach; ?>
        <input type="hidden" name="selected" id="selectedExportIds" value="">
    </form>

    <div class="mb-3 flex items-center justify-between gap-3">
        <label class="inline-flex items-center gap-2 text-sm font-bold text-slate-700"><input type="checkbox" id="selectAllUrgent" class="h-4 w-4 rounded border-slate-300 text-rose-600">Tout selectionner</label>
        <div class="text-sm font-semibold text-slate-500"><span id="selectedCount">0</span> selectionnee(s)</div>
    </div>

    <div class="grid grid-cols-1 gap-5 xl:grid-cols-2 2xl:grid-cols-3">
        <?php foreach ($rows as $row): ?>
            <?php
                $status = (string)($row['status'] ?? '');
                $photoUrl = urgentPagePhotoUrl($row);
                $regularizedFicheNum = trim((string)($row['regularized_fiche_num'] ?? ''));
            ?>
            <article class="urgent-card rounded-3xl p-5">
                <div class="flex items-start gap-4">
                    <input type="checkbox" class="urgent-row-checkbox mt-2 h-4 w-4 rounded border-slate-300 text-rose-600" value="<?= (int)$row['id'] ?>">
                    <div class="h-16 w-16 shrink-0 overflow-hidden rounded-2xl bg-rose-100 text-rose-700">
                        <?php if ($photoUrl !== ''): ?><img src="<?= htmlspecialchars($photoUrl) ?>" alt="<?= htmlspecialchars((string)$row['requester_name']) ?>" class="h-full w-full object-cover"><?php else: ?><div class="flex h-full w-full items-center justify-center text-lg font-black"><?= htmlspecialchars(urgentPageInitials((string)$row['requester_name'])) ?></div><?php endif; ?>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0"><div class="text-xs font-black uppercase tracking-wide text-slate-400"><?= htmlspecialchars((string)$row['request_code']) ?></div><h2 class="mt-1 truncate text-lg font-black text-slate-950"><?= htmlspecialchars((string)$row['libelle']) ?></h2></div>
                            <span class="shrink-0 rounded-full border px-3 py-1 text-xs font-bold <?= htmlspecialchars(UrgentDisbursement::statusClass($status)) ?>"><?= htmlspecialchars(UrgentDisbursement::statusLabel($status)) ?></span>
                        </div>
                        <div class="mt-3 text-2xl font-black text-emerald-700"><?= number_format((float)$row['amount'], 0, ',', ' ') ?> CFA</div>
                    </div>
                </div>

                <div class="mt-4 grid gap-3 rounded-2xl bg-slate-50 p-4 text-sm text-slate-600 md:grid-cols-2">
                    <div><span class="font-bold text-slate-900">Demandeur:</span> <?= htmlspecialchars((string)$row['requester_name']) ?></div>
                    <div><span class="font-bold text-slate-900">Telephone:</span> <?= htmlspecialchars((string)($row['requester_phone'] ?? '')) ?></div>
                    <div><span class="font-bold text-slate-900">Service:</span> <?= htmlspecialchars((string)($row['requester_service'] ?? '')) ?></div>
                    <div><span class="font-bold text-slate-900">Fonction:</span> <?= htmlspecialchars((string)($row['requester_function'] ?? '')) ?></div>
                    <div class="md:col-span-2"><span class="font-bold text-slate-900">Chantier:</span> <?= htmlspecialchars((string)$row['chantier_label']) ?></div>
                    <div><span class="font-bold text-slate-900">Soumise:</span> <?= htmlspecialchars((string)$row['submitted_at']) ?></div>
                    <?php if (!empty($row['regularization_due_at'])): ?><div><span class="font-bold text-slate-900">Regularisation:</span> <?= htmlspecialchars((string)$row['regularization_due_at']) ?></div><?php endif; ?>
                    <?php if ($regularizedFicheNum !== ''): ?>
                        <div>
                            <span class="font-bold text-slate-900">Fiche:</span>
                            <a href="voir_fiche_details.php?num_fiche=<?= urlencode($regularizedFicheNum) ?>" target="_blank" rel="noopener" class="font-bold text-indigo-700 underline decoration-indigo-300 underline-offset-2 hover:text-indigo-900" title="Visualiser la fiche <?= htmlspecialchars($regularizedFicheNum) ?>">
                                <?= htmlspecialchars($regularizedFicheNum) ?>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
                <p class="mt-4 min-h-[72px] rounded-2xl border border-slate-100 bg-white p-4 text-sm leading-6 text-slate-600"><?= nl2br(htmlspecialchars((string)$row['details'])) ?></p>
                <div class="mt-4 flex flex-wrap gap-2">
                    <?php if (!empty($row['justification_path'])): ?><a href="../<?= htmlspecialchars((string)$row['justification_path']) ?>" target="_blank" class="urgent-action border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">Justificatif</a><?php endif; ?>
                    <?php if (!empty($row['regularized_feb_request_id'])): ?><a href="../validation_feb_detail.php?tab=mine_pending&id=<?= (int)$row['regularized_feb_request_id'] ?>" target="_blank" rel="noopener" class="urgent-action border border-indigo-200 bg-indigo-50 text-indigo-800 hover:bg-indigo-100">Traiter FEB</a><?php endif; ?>
                    <?php if ($regularizedFicheNum !== ''): ?>
                        <a href="voir_fiche_details.php?num_fiche=<?= urlencode($regularizedFicheNum) ?>" target="_blank" rel="noopener" class="urgent-action border border-emerald-200 bg-emerald-50 text-emerald-800 hover:bg-emerald-100" title="Ouvrir la fiche de regularisation">
                            Voir la fiche
                        </a>
                    <?php endif; ?>
                </div>

                <?php if ($status === UrgentDisbursement::STATUS_SUBMITTED): ?>
                    <form method="post" class="mt-5 border-t border-slate-100 pt-4">
                        <input type="hidden" name="urgent_id" value="<?= (int)$row['id'] ?>">
                        <textarea name="note" rows="2" class="mb-3 w-full rounded-2xl border border-slate-200 px-3 py-2 text-sm outline-none focus:border-rose-300 focus:ring-4 focus:ring-rose-100" placeholder="Note comptable optionnelle"></textarea>
                        <div class="flex flex-wrap justify-end gap-2"><button name="urgent_action" value="reject" class="urgent-action border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">Refuser</button><button name="urgent_action" value="decaiss" class="urgent-action bg-emerald-600 text-white hover:bg-emerald-700">Valider et decaisser</button></div>
                    </form>
                <?php elseif (in_array($status, [UrgentDisbursement::STATUS_DECAISSED, UrgentDisbursement::STATUS_REGULARIZATION_PENDING], true)): ?>
                    <div class="mt-5 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-900">En attente de FEB et fiche de regularisation par le demandeur.</div>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
        <?php if (!$rows): ?><div class="urgent-panel rounded-3xl p-8 text-center text-slate-500 xl:col-span-2 2xl:col-span-3">Aucune demande urgente.</div><?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const boxes = Array.from(document.querySelectorAll('.urgent-row-checkbox'));
    const all = document.getElementById('selectAllUrgent');
    const count = document.getElementById('selectedCount');
    const form = document.getElementById('selectedExportForm');
    const ids = document.getElementById('selectedExportIds');
    const format = document.getElementById('selectedExportFormat');
    function selectedIds() { return boxes.filter(box => box.checked).map(box => box.value); }
    function refresh() { const selected = selectedIds(); count.textContent = selected.length; if (all) all.checked = boxes.length > 0 && selected.length === boxes.length; }
    boxes.forEach(box => box.addEventListener('change', refresh));
    if (all) all.addEventListener('change', function() { boxes.forEach(box => box.checked = all.checked); refresh(); });
    function submitSelection(kind) {
        const selected = selectedIds();
        if (!selected.length) { alert('Veuillez selectionner au moins une demande.'); return; }
        ids.value = selected.join(',');
        format.value = kind;
        form.submit();
    }
    document.getElementById('exportSelectedPdf').addEventListener('click', () => submitSelection('pdf'));
    document.getElementById('exportSelectedExcel').addEventListener('click', () => submitSelection('excel'));
    refresh();
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
