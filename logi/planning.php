<?php
require_once __DIR__ . '/_logistics_common.php';

$flashMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['planning_action'], $_POST['assignment_id'])) {
    $flashMessage = logi_process_planning_action($pdo, (int)$_POST['assignment_id'], (string)$_POST['planning_action'], $_POST);
}
$blockReasons = logi_planning_block_reasons($pdo);
$q = logi_query_value();
$allRows = $pdo->query("SELECT a.*, la.designation asset_name, la.asset_code, la.registration_number, ld.full_name driver_name,
        COALESCE(att.attachment_count, 0) attachment_count
    FROM logistics_assignments a
    LEFT JOIN logistics_assets la ON la.id = a.asset_id
    LEFT JOIN logistics_drivers ld ON ld.id = a.driver_id
    LEFT JOIN (
        SELECT assignment_id, COUNT(*) attachment_count
        FROM logistics_assignment_attachments
        GROUP BY assignment_id
    ) att ON att.assignment_id = a.id
    ORDER BY COALESCE(a.start_at, a.created_at) DESC, a.id DESC
    LIMIT 600")->fetchAll(PDO::FETCH_ASSOC);
$rows = logi_filter_rows($allRows, ['mission_title','asset_name','asset_code','registration_number','driver_name','executor_name','assistant_name','site_label','debourse_label','status','start_at','end_at'], $q);

$today = date('Y-m-d');
$active = count(array_filter($allRows, static fn(array $r): bool => in_array((string)($r['status'] ?? ''), ['planned','planifiee','in_progress','en_cours','blocked'], true)));
$todayCount = count(array_filter($allRows, static fn(array $r): bool => str_starts_with((string)($r['start_at'] ?? ''), date('Y-m-d'))));
$avgProgressRows = array_filter($allRows, static fn(array $r): bool => in_array((string)($r['status'] ?? ''), ['planned','in_progress','blocked','completed'], true));
$avgProgress = $avgProgressRows ? array_sum(array_map(static fn(array $r): int => (int)($r['progress_percent'] ?? 0), $avgProgressRows)) / count($avgProgressRows) : 0;
$late = count(array_filter($allRows, static function (array $r) use ($today): bool {
    $end = substr((string)($r['end_at'] ?? ''), 0, 10);
    return $end !== '' && $end < $today && in_array((string)($r['status'] ?? ''), ['planned','planifiee','in_progress','en_cours'], true);
}));

logi_page_start('Planning logistique', 'Affectations, reservations et occupations des engins, vehicules et chauffeurs.');
?>
<?php if ($flashMessage !== ''): ?>
    <div class="mb-6 rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 font-semibold text-emerald-800"><?= logi_h($flashMessage) ?></div>
<?php endif; ?>
<?php if (!empty($_GET['success'])): ?>
    <div class="mb-6 rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 font-semibold text-emerald-800">
        Affectation ajoutee au planning.
    </div>
<?php endif; ?>

<section class="mb-8 grid grid-cols-1 gap-5 md:grid-cols-4">
    <?php foreach ([['Affectations', count($allRows), 'fa-calendar-days', 'bg-orange-50 text-orange-700'], ['Actives', $active, 'fa-route', 'bg-sky-50 text-sky-700'], ['Aujourd hui', $todayCount, 'fa-calendar-day', 'bg-emerald-50 text-emerald-700'], ['Avancement moyen', round($avgProgress) . '%', 'fa-chart-simple', 'bg-indigo-50 text-indigo-700']] as $k): ?>
        <article class="rounded-3xl border border-white/60 bg-white/90 p-6 shadow-sm">
            <div class="flex justify-between gap-4">
                <div><div class="text-xs uppercase tracking-[0.22em] text-slate-500"><?= logi_h($k[0]) ?></div><div class="mt-3 text-3xl font-semibold text-slate-900"><?= logi_num($k[1]) ?></div></div>
                <div class="flex h-12 w-12 items-center justify-center rounded-2xl <?= logi_h($k[3]) ?> text-xl"><i class="fas <?= logi_h($k[2]) ?>"></i></div>
            </div>
        </article>
    <?php endforeach; ?>
</section>

<?php
logi_listing_toolbar('planning_form.php', 'planning', $q, 'Rechercher mission, engin, chauffeur, chantier, statut...');
logi_card_open('Planning des affectations', $q !== '' ? count($rows) . ' resultat(s)' : 'Reservations et occupations du parc');
?>
<div class="overflow-x-auto">
    <table class="w-full border-separate border-spacing-y-3">
        <thead>
            <tr class="text-left text-xs uppercase tracking-[0.18em] text-slate-500">
                <th class="px-4">Mission</th>
                <th>Ressource</th>
                <th>Equipe</th>
                <th>Chantier</th>
                <th>Debourse</th>
                <th>Periode</th>
                <th>Statut</th>
                <th class="text-right">Reunion</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr class="bg-white shadow-sm">
                <td class="rounded-l-2xl px-4 py-4">
                    <div class="font-semibold text-slate-900"><?= logi_h($row['mission_title'] ?: 'Mission non renseignee') ?></div>
                    <div class="text-sm text-slate-500"><?= logi_h($row['notes'] ?? '') ?></div>
                </td>
                <td>
                    <div class="font-semibold text-slate-800"><?= logi_h($row['asset_name'] ?: 'Non affecte') ?></div>
                    <div class="text-sm text-slate-500"><?= logi_h(trim((string)($row['asset_code'] ?? '') . ' ' . (string)($row['registration_number'] ?? ''))) ?></div>
                </td>
                <td>
                    <div class="font-semibold text-slate-800"><?= logi_h($row['executor_name'] ?: ($row['driver_name'] ?: 'Non affecte')) ?></div>
                    <div class="text-sm text-slate-500"><?= logi_h($row['assistant_name'] ? 'Adjoint: ' . $row['assistant_name'] : ($row['driver_name'] && $row['executor_name'] ? 'Chauffeur: ' . $row['driver_name'] : '')) ?></div>
                </td>
                <td><?= logi_h($row['site_label'] ?: 'Non renseigne') ?></td>
                <td class="max-w-xs">
                    <div class="line-clamp-2 text-sm text-slate-600"><?= logi_h($row['debourse_label'] ?: 'Non lie') ?></div>
                    <?php if ((int)($row['attachment_count'] ?? 0) > 0): ?>
                        <div class="mt-1 inline-flex items-center rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-600"><i class="fas fa-paperclip mr-1"></i><?= (int)$row['attachment_count'] ?> piece(s)</div>
                    <?php endif; ?>
                </td>
                <td>
                    <div class="text-sm font-semibold text-slate-700"><?= !empty($row['start_at']) ? date('d/m/Y H:i', strtotime((string)$row['start_at'])) : '-' ?></div>
                    <div class="text-sm text-slate-500"><?= !empty($row['end_at']) ? date('d/m/Y H:i', strtotime((string)$row['end_at'])) : 'Fin non renseignee' ?></div>
                </td>
                <td class="rounded-r-2xl"><?= logi_badge((string)($row['status'] ?? 'planned')) ?></td>
                <td class="rounded-r-2xl py-4 pr-4">
                    <form method="post" class="min-w-[320px] space-y-2">
                        <input type="hidden" name="assignment_id" value="<?= (int)$row['id'] ?>">
                        <div class="flex items-center gap-2">
                            <input type="number" min="0" max="100" name="progress_percent" value="<?= (int)($row['progress_percent'] ?? 0) ?>" class="h-10 w-20 rounded-xl border border-slate-200 px-3 text-sm">
                            <div class="h-2 flex-1 overflow-hidden rounded-full bg-slate-100"><div class="h-full bg-orange-500" style="width: <?= max(0, min(100, (int)($row['progress_percent'] ?? 0))) ?>%"></div></div>
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <?= logi_select('Motif blocage', 'blocked_reason', array_combine($blockReasons, $blockReasons), (string)($row['blocked_reason'] ?? '')) ?>
                            <input type="datetime-local" name="postpone_start_at" class="mt-7 h-12 rounded-2xl border border-slate-200 bg-slate-50 px-3 text-sm" title="Date de report">
                        </div>
                        <input name="meeting_note" placeholder="Note reunion logistique" class="h-10 w-full rounded-xl border border-slate-200 px-3 text-sm">
                        <div class="flex flex-wrap justify-end gap-2">
                            <button name="planning_action" value="start" class="rounded-xl bg-sky-600 px-3 py-2 text-xs font-semibold text-white">Demarrer</button>
                            <button name="planning_action" value="progress" class="rounded-xl bg-orange-500 px-3 py-2 text-xs font-semibold text-white">Avancement</button>
                            <button name="planning_action" value="complete" class="rounded-xl bg-emerald-600 px-3 py-2 text-xs font-semibold text-white">Terminer</button>
                            <button name="planning_action" value="block" class="rounded-xl bg-amber-500 px-3 py-2 text-xs font-semibold text-slate-950">Bloquer</button>
                            <button name="planning_action" value="postpone" class="rounded-xl bg-indigo-600 px-3 py-2 text-xs font-semibold text-white">Reporter</button>
                            <button name="planning_action" value="cancel" class="rounded-xl bg-rose-600 px-3 py-2 text-xs font-semibold text-white">Annuler</button>
                        </div>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($rows)): ?>
            <tr><td colspan="8" class="py-8 text-center font-semibold text-slate-600">Aucune affectation trouvee.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?php logi_card_close(); logi_page_end(); ?>
