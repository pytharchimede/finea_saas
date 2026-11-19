<?php
require_once __DIR__ . '/_logistics_common.php';

$q = logi_query_value();
$allRows = $pdo->query('SELECT t.*, b.batch_code, b.site_label, b.debourse_label, a.designation asset_name, d.full_name driver_name
    FROM logistics_trips t
    LEFT JOIN logistics_trip_batches b ON b.id=t.batch_id
    LEFT JOIN logistics_assets a ON a.id=t.asset_id
    LEFT JOIN logistics_drivers d ON d.id=t.driver_id
    ORDER BY t.id DESC
    LIMIT 700')->fetchAll(PDO::FETCH_ASSOC);
$rows = logi_filter_rows($allRows, ['batch_code','site_label','debourse_label','asset_name','driver_name','delivery_note','status','observation'], $q);
$tripCount = array_sum(array_map(static fn(array $r): int => (int)($r['trip_count'] ?? 0), $allRows));
$fuel = array_sum(array_map(static fn(array $r): float => (float)($r['fuel_liters'] ?? 0), $allRows));
$costs = array_sum(array_map(static fn(array $r): float => (float)($r['fuel_amount'] ?? 0) + (float)($r['road_fees_amount'] ?? 0), $allRows));

logi_page_start('Rotations de voyages', 'Listing detaille des rotations par vague, chauffeur et vehicule.');
?>
<?php if (!empty($_GET['ok'])): ?>
    <div class="mb-6 rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 font-semibold text-emerald-800">Rotation enregistree.</div>
<?php endif; ?>

<section class="mb-8 grid grid-cols-1 gap-5 md:grid-cols-4">
    <?php foreach ([['Rotations', count($allRows), 'fa-repeat', 'bg-orange-50 text-orange-700'], ['Voyages', $tripCount, 'fa-road', 'bg-sky-50 text-sky-700'], ['Carburant', logi_num($fuel) . ' L', 'fa-gas-pump', 'bg-amber-50 text-amber-700'], ['Couts', logi_money($costs), 'fa-coins', 'bg-emerald-50 text-emerald-700']] as $k): ?>
        <article class="rounded-3xl border border-white/60 bg-white/90 p-6 shadow-sm">
            <div class="flex justify-between gap-4">
                <div><div class="text-xs uppercase tracking-[0.22em] text-slate-500"><?= logi_h($k[0]) ?></div><div class="mt-3 text-3xl font-semibold text-slate-900"><?= logi_h((string)$k[1]) ?></div></div>
                <div class="flex h-12 w-12 items-center justify-center rounded-2xl <?= logi_h($k[3]) ?> text-xl"><i class="fas <?= logi_h($k[2]) ?>"></i></div>
            </div>
        </article>
    <?php endforeach; ?>
</section>

<section class="mb-6 rounded-[1.5rem] border border-white/70 bg-white/95 p-4 shadow-sm">
    <form method="get" class="grid grid-cols-1 gap-3 lg:grid-cols-[1fr_auto_auto_auto] lg:items-center">
        <div class="relative"><i class="fas fa-magnifying-glass absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"></i><input name="q" value="<?= logi_h($q) ?>" placeholder="Rechercher rotation, vague, vehicule, chauffeur..." class="h-12 w-full rounded-2xl border border-slate-200 bg-slate-50 pl-11 pr-4 text-slate-800 outline-none transition focus:border-orange-300 focus:bg-white focus:ring-4 focus:ring-orange-100"></div>
        <button class="h-12 rounded-2xl bg-slate-900 px-5 text-sm font-semibold text-white hover:bg-slate-800" type="submit"><i class="fas fa-filter mr-2"></i>Rechercher</button>
        <a class="inline-flex h-12 items-center justify-center rounded-2xl bg-orange-600 px-5 text-sm font-semibold text-white hover:bg-orange-700" href="trip_form.php"><i class="fas fa-plus mr-2"></i>Nouvelle rotation</a>
        <a class="inline-flex h-12 items-center justify-center rounded-2xl border border-slate-200 bg-white px-5 text-sm font-semibold text-slate-700 hover:shadow-sm" href="trip_batches.php"><i class="fas fa-route mr-2"></i>Vagues</a>
    </form>
    <div class="mt-3 flex flex-wrap justify-end gap-2">
        <?php $params = $q !== '' ? '&q=' . rawurlencode($q) : ''; ?>
        <a class="rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700" href="export_logistique.php?dataset=trips&format=xlsx<?= logi_h($params) ?>"><i class="fas fa-file-excel mr-2"></i>Excel <?= $q !== '' ? 'recherche' : 'global' ?></a>
        <a class="rounded-xl bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-700" href="export_logistique.php?dataset=trips&format=pdf<?= logi_h($params) ?>"><i class="fas fa-file-pdf mr-2"></i>PDF <?= $q !== '' ? 'recherche' : 'global' ?></a>
    </div>
</section>

<?php logi_card_open('Rotations', $q !== '' ? count($rows) . ' resultat(s)' : 'Detail chauffeur, vehicule, carburant et frais'); ?>
<div class="overflow-x-auto">
    <table class="w-full border-separate border-spacing-y-3">
        <thead><tr class="text-left text-xs uppercase tracking-[0.18em] text-slate-500"><th class="px-4">Vague</th><th>Vehicule</th><th>Chauffeur</th><th>Voyages</th><th>Quantite</th><th>Carburant</th><th>Bon</th><th>Frais route</th><th>Statut</th></tr></thead>
        <tbody>
        <?php foreach($rows as $t): ?>
            <tr class="bg-white shadow-sm">
                <td class="px-4 py-4 rounded-l-2xl font-semibold"><?= logi_h($t['batch_code']) ?><div class="text-xs text-slate-500"><?= logi_h($t['site_label']) ?></div></td>
                <td><?= logi_h($t['asset_name']) ?></td>
                <td><?= logi_h($t['driver_name']) ?></td>
                <td><?= logi_num($t['trip_count'] ?? 1) ?></td>
                <td><?= logi_num($t['transported_quantity']) ?></td>
                <td><?= logi_num($t['fuel_liters'] ?? 0) ?> L<div class="text-xs text-slate-500"><?= logi_money($t['fuel_amount'] ?? 0) ?></div></td>
                <td>
                    <div class="font-semibold text-slate-800"><?= logi_h($t['delivery_note'] ?: '-') ?></div>
                    <?php if (!empty($t['delivery_note_file_path'])): ?>
                        <a class="mt-1 inline-flex items-center rounded-xl border border-orange-100 bg-orange-50 px-3 py-1 text-xs font-semibold text-orange-700 hover:bg-orange-100" href="<?= logi_h($t['delivery_note_file_path']) ?>" target="_blank" rel="noopener">
                            <i class="fas fa-paperclip mr-2"></i>Voir le bon
                        </a>
                    <?php endif; ?>
                </td>
                <td><?= logi_money($t['road_fees_amount'] ?? 0) ?></td>
                <td class="rounded-r-2xl"><?= logi_badge($t['status']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="9" class="py-8 text-center text-slate-400">Aucune rotation trouvee.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<?php logi_card_close(); logi_page_end(); ?>
