<?php
require_once __DIR__ . '/_logistics_common.php';

$q = logi_query_value();
$allRows = logi_assets($pdo);
$rows = logi_filter_rows($allRows, ['designation','asset_code','asset_type','registration_number','brand','model','current_site','status'], $q);
$total = count($allRows);
$available = count(array_filter($allRows, fn($r) => ($r['status'] ?? '') === 'available'));
$busy = count(array_filter($allRows, fn($r) => ($r['status'] ?? '') === 'busy'));
$break = count(array_filter($allRows, fn($r) => in_array(($r['status'] ?? ''), ['breakdown','maintenance'], true)));

logi_page_start('Parc engins & vehicules', 'Inventaire operationnel, affectations, disponibilite et etat technique du parc.');
?>
<section class="grid grid-cols-1 md:grid-cols-4 gap-5 mb-8">
<?php foreach ([['Parc total',$total,'fa-truck-monster','bg-orange-50 text-orange-700'],['Disponibles',$available,'fa-circle-check','bg-emerald-50 text-emerald-700'],['En mission',$busy,'fa-route','bg-sky-50 text-sky-700'],['A surveiller',$break,'fa-triangle-exclamation','bg-rose-50 text-rose-700']] as $k): ?>
<article class="rounded-3xl border border-white/60 bg-white/90 p-6 shadow-sm"><div class="flex justify-between gap-4"><div><div class="text-xs uppercase tracking-[0.22em] text-slate-500"><?= logi_h($k[0]) ?></div><div class="mt-3 text-3xl font-semibold text-slate-900"><?= logi_num($k[1]) ?></div></div><div class="h-12 w-12 rounded-2xl <?= $k[3] ?> flex items-center justify-center text-xl"><i class="fas <?= $k[2] ?>"></i></div></div></article>
<?php endforeach; ?>
</section>
<?php
logi_listing_toolbar('assets_form.php', 'assets', $q, 'Rechercher code, designation, chantier, statut...');
logi_card_open('Liste du parc', ($q !== '' ? count($rows) . ' resultat(s) pour "' . $q . '"' : 'Ressources logistiques enregistrees')); ?>
<div class="overflow-x-auto"><table class="w-full border-separate border-spacing-y-3"><thead><tr class="text-left text-xs uppercase tracking-[0.18em] text-slate-500"><th class="px-4">Ressource</th><th>Type</th><th>Immat.</th><th>Chantier</th><th>Compteurs</th><th>Statut</th></tr></thead><tbody><?php foreach($rows as $r): ?><tr class="bg-white shadow-sm"><td class="px-4 py-4 rounded-l-2xl"><div class="font-semibold text-slate-900"><?= logi_h($r['designation']) ?></div><div class="text-sm text-slate-500"><?= logi_h($r['asset_code']) ?> <?= logi_h($r['brand']) ?> <?= logi_h($r['model']) ?></div></td><td><?= logi_h($r['asset_type']) ?></td><td><?= logi_h($r['registration_number']) ?></td><td><?= logi_h($r['current_site'] ?: 'Non affecte') ?></td><td><span class="text-sm text-slate-600"><?= logi_num($r['hour_meter']) ?> h / <?= logi_num($r['odometer']) ?> km</span></td><td class="rounded-r-2xl"><?= logi_badge($r['status']) ?></td></tr><?php endforeach; ?><?php if(empty($rows)): ?><tr><td colspan="6" class="py-8 text-center text-slate-400">Aucun element trouve.</td></tr><?php endif; ?></tbody></table></div>
<?php logi_card_close(); logi_page_end();
