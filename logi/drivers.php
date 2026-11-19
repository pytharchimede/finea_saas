<?php
require_once __DIR__ . '/_logistics_common.php';

$rh = logi_rh_personnel($pdo);
$q = logi_query_value();
$allRows = logi_drivers($pdo);
$rows = logi_filter_rows($allRows, ['full_name','driver_type','phone','license_number','license_category','service_label','availability_status'], $q);
$available = count(array_filter($allRows, fn($r) => ($r['availability_status'] ?? '') === 'available'));

logi_page_start('Chauffeurs logistiques', 'Rattachement des conducteurs internes RH et gestion des chauffeurs externes.');
?>
<section class="grid md:grid-cols-3 gap-5 mb-8">
    <article class="rounded-3xl bg-white/90 p-6 shadow-sm"><div class="text-xs uppercase tracking-[0.22em] text-slate-500">Chauffeurs</div><div class="mt-3 text-3xl font-semibold"><?= count($allRows) ?></div></article>
    <article class="rounded-3xl bg-white/90 p-6 shadow-sm"><div class="text-xs uppercase tracking-[0.22em] text-slate-500">Disponibles</div><div class="mt-3 text-3xl font-semibold"><?= $available ?></div></article>
    <article class="rounded-3xl bg-white/90 p-6 shadow-sm"><div class="text-xs uppercase tracking-[0.22em] text-slate-500">Personnel RH detecte</div><div class="mt-3 text-3xl font-semibold"><?= count($rh) ?></div></article>
</section>
<?php logi_listing_toolbar('drivers_form.php', 'drivers', $q, 'Rechercher chauffeur, service, permis, telephone...');
logi_card_open('Liste des chauffeurs', ($q !== '' ? count($rows) . ' resultat(s)' : 'Disponibilite et validite des habilitations')); ?>
<div class="overflow-x-auto"><table class="w-full border-separate border-spacing-y-3"><thead><tr class="text-left text-xs uppercase tracking-[0.18em] text-slate-500"><th class="px-4">Chauffeur</th><th>Type</th><th>Permis</th><th>Service</th><th>Statut</th></tr></thead><tbody><?php foreach($rows as $r): ?><tr class="bg-white shadow-sm"><td class="px-4 py-4 rounded-l-2xl"><div class="font-semibold"><?= logi_h($r['full_name']) ?></div><div class="text-sm text-slate-500"><?= logi_h($r['phone']) ?></div></td><td><?= logi_h($r['driver_type']) ?></td><td><?= logi_h($r['license_number']) ?><div class="text-sm text-slate-500"><?= logi_h($r['license_category']) ?><?= $r['license_expires_at']?' - exp. '.logi_h($r['license_expires_at']):'' ?></div></td><td><?= logi_h($r['service_label']) ?></td><td class="rounded-r-2xl"><?= logi_badge($r['availability_status']) ?></td></tr><?php endforeach; ?><?php if(empty($rows)): ?><tr><td colspan="5" class="py-8 text-center text-slate-400">Aucun chauffeur trouve.</td></tr><?php endif; ?></tbody></table></div>
<?php logi_card_close(); logi_page_end();
