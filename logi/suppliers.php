<?php require_once __DIR__ . '/_logistics_common.php';
$q = logi_query_value();
$allRows = logi_suppliers($pdo);
$rows = logi_filter_rows($allRows, ['supplier_type','name','contact_name','phone','email','address','tax_number','status'], $q);
logi_page_start('Fournisseurs & intervenants','Garages, mecaniciens externes, carburant, loueurs, transporteurs et chauffeurs externes.');
logi_listing_toolbar('suppliers_form.php', 'suppliers', $q, 'Rechercher fournisseur, contact, telephone, email...');
logi_card_open('Repertoire logistique', ($q !== '' ? count($rows) . ' resultat(s)' : 'Prestataires et fournisseurs enregistres')); ?>
<div class="overflow-x-auto"><table class="w-full border-separate border-spacing-y-3"><thead><tr class="text-left text-xs uppercase tracking-[0.18em] text-slate-500"><th class="px-4">Nom</th><th>Type</th><th>Contact</th><th>Email</th><th>Identifiant</th><th>Statut</th></tr></thead><tbody><?php foreach($rows as $r): ?><tr class="bg-white shadow-sm"><td class="px-4 py-4 rounded-l-2xl font-semibold"><a class="underline" href="suppliers_form.php?id=<?= (int)$r['id'] ?>"><?= logi_h($r['name']) ?></a><?php if(empty($r['master_supplier_id'])): ?><span class="block text-sm text-amber-700">Fiche commune à rattacher</span><?php endif; ?><div class="text-sm text-slate-500"><?= logi_h($r['address']) ?></div></td><td><?= logi_h($r['supplier_type']) ?></td><td><?= logi_h($r['contact_name']) ?><div class="text-sm text-slate-500"><?= logi_h($r['phone']) ?></div></td><td><?= logi_h($r['email']) ?></td><td><?= logi_h($r['tax_number']) ?></td><td class="rounded-r-2xl"><?= logi_badge($r['status']) ?></td></tr><?php endforeach; ?><?php if(empty($rows)): ?><tr><td colspan="6" class="py-8 text-center text-slate-400">Aucun fournisseur trouve.</td></tr><?php endif; ?></tbody></table></div>
<?php logi_card_close(); logi_page_end();

