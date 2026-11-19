<?php
require_once __DIR__ . '/_logistics_common.php';

$q = logi_query_value();
$category = trim((string)($_GET['category'] ?? ''));
$suppliers = logi_options_suppliers($pdo);
$categoryOptions = logi_stock_category_options($pdo);
$allRows = $pdo->query('SELECT s.*, p.name supplier_name FROM logistics_stock_items s LEFT JOIN logistics_suppliers p ON p.id=s.preferred_supplier_id ORDER BY s.id DESC LIMIT 500')->fetchAll(PDO::FETCH_ASSOC);
$rows = logi_filter_rows($allRows, ['category','designation','reference_code','unit_label','location_label','supplier_name','status'], $q);
if ($category !== '') {
    $rows = array_values(array_filter($rows, static fn(array $row): bool => strcasecmp(trim((string)($row['category'] ?? '')), $category) === 0));
}
$critical = count(array_filter($allRows, fn($r) => (float)($r['current_quantity'] ?? $r['current_stock'] ?? 0) <= (float)($r['minimum_quantity'] ?? $r['minimum_stock'] ?? 0)));

logi_page_start('Stock logistique', 'Inventaire des pieces, consommables, pneus, huiles, materiaux et mouvements.');
?>
<section class="mb-8 grid gap-5 md:grid-cols-3">
    <article class="rounded-3xl bg-white/90 p-6 shadow-sm"><div class="text-xs uppercase tracking-[0.22em] text-slate-500">Articles</div><div class="mt-3 text-3xl font-semibold"><?= count($allRows) ?></div></article>
    <article class="rounded-3xl bg-white/90 p-6 shadow-sm"><div class="text-xs uppercase tracking-[0.22em] text-slate-500">Stock critique</div><div class="mt-3 text-3xl font-semibold text-rose-700"><?= $critical ?></div></article>
    <article class="rounded-3xl bg-white/90 p-6 shadow-sm"><div class="text-xs uppercase tracking-[0.22em] text-slate-500">Fournisseurs</div><div class="mt-3 text-3xl font-semibold"><?= count($suppliers) ?></div></article>
</section>

<?php logi_listing_toolbar('stock_item_form.php', 'stock', $q, 'Rechercher article, categorie, reference, emplacement...'); ?>

<section class="mb-6 rounded-[1.5rem] border border-white/70 bg-white/95 p-4 shadow-sm">
    <form method="get" class="grid grid-cols-1 gap-3 lg:grid-cols-[1fr_auto_auto] lg:items-end">
        <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?= logi_h($q) ?>"><?php endif; ?>
        <?= logi_search_select('Filtrer par categorie', 'category', array_merge([['value' => '', 'label' => 'Toutes les categories']], $categoryOptions), $category, '', 'Rechercher une categorie') ?>
        <button class="h-12 rounded-2xl bg-slate-900 px-5 text-sm font-semibold text-white hover:bg-slate-800" type="submit"><i class="fas fa-filter mr-2"></i>Filtrer</button>
        <a class="inline-flex h-12 items-center justify-center rounded-2xl border border-slate-200 bg-white px-5 text-sm font-semibold text-slate-700 hover:shadow-sm" href="stock.php<?= $q !== '' ? '?q=' . rawurlencode($q) : '' ?>">Toutes</a>
    </form>
</section>

<div class="mb-6 flex flex-wrap justify-end gap-2">
    <a href="stock_movement_form.php" class="rounded-2xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white hover:bg-slate-800"><i class="fas fa-right-left mr-2"></i>Ajouter un mouvement</a>
</div>

<?php logi_card_open('Inventaire', ($q !== '' || $category !== '' ? count($rows) . ' resultat(s)' : 'Articles et seuils')); ?>
<div class="overflow-x-auto">
    <table class="w-full border-separate border-spacing-y-3">
        <thead><tr class="text-left text-xs uppercase tracking-[0.18em] text-slate-500"><th class="px-4">Article</th><th>Categorie</th><th>Stock</th><th>Seuil</th><th>Emplacement</th><th>Statut</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $current = (float)($r['current_quantity'] ?? $r['current_stock'] ?? 0); $minimum = (float)($r['minimum_quantity'] ?? $r['minimum_stock'] ?? 0); $crit = $current <= $minimum; ?>
            <tr class="bg-white shadow-sm">
                <td class="rounded-l-2xl px-4 py-4"><div class="font-semibold"><?= logi_h($r['designation']) ?></div><div class="text-sm text-slate-500"><?= logi_h($r['reference_code']) ?></div></td>
                <td><?= logi_h($r['category']) ?></td>
                <td class="font-semibold <?= $crit ? 'text-rose-700' : 'text-slate-900' ?>"><?= logi_num($current) ?> <?= logi_h($r['unit_label']) ?></td>
                <td><?= logi_num($minimum) ?></td>
                <td><?= logi_h($r['location_label']) ?></td>
                <td class="rounded-r-2xl"><?= $crit ? logi_badge('critical') : logi_badge($r['status']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($rows)): ?><tr><td colspan="6" class="py-8 text-center font-semibold text-slate-600">Aucun article trouve.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<?php logi_card_close(); logi_page_end(); ?>
