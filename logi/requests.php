<?php
require_once __DIR__ . '/_logistics_common.php';

$flashMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logi_request_action'], $_POST['request_id'])) {
    $flashMessage = logi_process_request_action($pdo, (int)$_POST['request_id'], (string)$_POST['logi_request_action'], $_POST);
}

$q = logi_query_value();
$allRows = $pdo->query("SELECT r.*,
        a.designation asset_label, a.asset_code, a.registration_number, a.current_holder, a.current_site,
        s.designation stock_label, s.reference_code, s.unit_label, COALESCE(s.current_stock, s.current_quantity, 0) stock_available
    FROM logistics_requests r
    LEFT JOIN logistics_assets a ON a.id = r.asset_id
    LEFT JOIN logistics_stock_items s ON s.id = r.stock_item_id
    ORDER BY r.id DESC
    LIMIT 700")->fetchAll(PDO::FETCH_ASSOC);
$rows = logi_filter_rows($allRows, ['request_code','requesting_service','requester_name','holder_label','site_label','debourse_label','need_type','priority','desired_at','status','statut','asset_label','stock_label'], $q);
$pendingCount = count(array_filter($allRows, static fn(array $r): bool => in_array((string)($r['status'] ?? ''), ['submitted','details_requested','study'], true)));
$approvedCount = count(array_filter($allRows, static fn(array $r): bool => in_array((string)($r['status'] ?? ''), ['approved','in_progress'], true)));
$dueCount = count(array_filter($allRows, static function (array $r): bool {
    $end = substr((string)($r['date_end'] ?? ''), 0, 10);
    return $end !== '' && $end <= date('Y-m-d') && in_array((string)($r['status'] ?? ''), ['approved','in_progress'], true);
}));

logi_page_start('Demandes logistiques','Reception, validation, remise, recuperation et tracabilite des ressources.');
?>
<?php if ($flashMessage !== ''): ?>
    <div class="mb-6 rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 font-semibold text-emerald-800"><?= logi_h($flashMessage) ?></div>
<?php elseif (!empty($_GET['ok'])): ?>
    <div class="mb-6 rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 font-semibold text-emerald-800">Demande enregistree.</div>
<?php endif; ?>

<section class="mb-8 grid grid-cols-1 gap-5 md:grid-cols-3">
    <article class="rounded-3xl border border-white/60 bg-white/90 p-6 shadow-sm">
        <div class="text-xs uppercase tracking-[0.22em] text-slate-500">A traiter</div>
        <div class="mt-3 text-4xl font-semibold text-slate-900"><?= logi_num($pendingCount) ?></div>
    </article>
    <article class="rounded-3xl border border-white/60 bg-white/90 p-6 shadow-sm">
        <div class="text-xs uppercase tracking-[0.22em] text-slate-500">Accordees / remises</div>
        <div class="mt-3 text-4xl font-semibold text-slate-900"><?= logi_num($approvedCount) ?></div>
    </article>
    <article class="rounded-3xl border border-white/60 bg-white/90 p-6 shadow-sm">
        <div class="text-xs uppercase tracking-[0.22em] text-slate-500">Retours attendus</div>
        <div class="mt-3 text-4xl font-semibold text-rose-700"><?= logi_num($dueCount) ?></div>
    </article>
</section>

<?php
logi_listing_toolbar('requests_form.php', 'requests', $q, 'Rechercher code, service, demandeur, ressource, chantier, statut...');
logi_card_open('Demandes recues', ($q !== '' ? count($rows) . ' resultat(s)' : 'Suivi des besoins provenant des services'));
?>
<div class="overflow-x-auto">
    <table class="w-full border-separate border-spacing-y-3">
        <thead>
            <tr class="text-left text-xs uppercase tracking-[0.18em] text-slate-500">
                <th class="px-4">Demande</th>
                <th>Objet</th>
                <th>Demandeur / detention</th>
                <th>Position / debourse</th>
                <th>Echeance</th>
                <th>Statut</th>
                <th class="text-right">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <?php
                $status = (string)($r['status'] ?: ($r['statut'] ?? 'submitted'));
                $isStock = (int)($r['stock_item_id'] ?? 0) > 0;
                $objectLabel = $isStock ? (string)($r['stock_label'] ?? 'Article stock') : (string)($r['asset_label'] ?? 'Objet non precise');
                $holder = trim((string)($r['holder_label'] ?? '')) ?: trim((string)($r['current_holder'] ?? '')) ?: trim((string)($r['requester_name'] ?? ''));
            ?>
            <tr class="bg-white shadow-sm align-top">
                <td class="rounded-l-2xl px-4 py-4">
                    <div class="font-semibold text-slate-900"><?= logi_h($r['request_code']) ?></div>
                    <div class="mt-1 text-sm text-slate-500"><?= logi_h($r['site_label'] ?: 'Chantier / zone non renseigne') ?></div>
                    <div class="mt-2"><?= logi_badge($r['priority'] ?: 'normal') ?></div>
                </td>
                <td class="py-4">
                    <div class="font-semibold text-slate-900"><?= logi_h($objectLabel ?: 'Besoin logistique') ?></div>
                    <div class="mt-1 text-sm text-slate-500"><?= logi_h($r['need_type'] ?: ($r['type'] ?? '')) ?></div>
                    <?php if ($isStock): ?>
                        <div class="mt-2 text-xs font-semibold text-slate-600">Quantite: <?= logi_num($r['requested_quantity'] ?? 0) ?> <?= logi_h($r['unit_label'] ?? '') ?> | Stock: <?= logi_num($r['stock_available'] ?? 0) ?></div>
                    <?php else: ?>
                        <div class="mt-2 text-xs text-slate-500"><?= logi_h(trim((string)($r['asset_code'] ?? '') . ' ' . (string)($r['registration_number'] ?? ''))) ?></div>
                    <?php endif; ?>
                </td>
                <td class="py-4">
                    <div class="font-semibold text-slate-800"><?= logi_h($r['requester_name'] ?: 'Non renseigne') ?></div>
                    <div class="text-sm text-slate-500"><?= logi_h($r['requesting_service'] ?: $r['holder_type'] ?: 'Service non renseigne') ?></div>
                    <?php if ($holder !== ''): ?><div class="mt-2 rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">Chez: <?= logi_h($holder) ?></div><?php endif; ?>
                </td>
                <td class="py-4 max-w-xs">
                    <div class="font-semibold text-slate-800"><?= logi_h($r['site_label'] ?: 'Non renseigne') ?></div>
                    <div class="mt-1 line-clamp-2 text-sm text-slate-500"><?= logi_h($r['debourse_label'] ?: 'Aucun debourse precise') ?></div>
                </td>
                <td class="py-4">
                    <div class="text-sm font-semibold text-slate-700"><?= !empty($r['desired_at']) ? date('d/m/Y H:i', strtotime((string)$r['desired_at'])) : '-' ?></div>
                    <div class="text-sm text-slate-500">Retour <?= !empty($r['date_end']) ? date('d/m/Y H:i', strtotime((string)$r['date_end'])) : 'non renseigne' ?></div>
                </td>
                <td class="py-4"><?= logi_badge($status) ?><div class="mt-2 text-xs text-slate-500"><?= logi_h(logi_request_status_label($status)) ?></div></td>
                <td class="rounded-r-2xl py-4 pr-4">
                    <div class="flex min-w-[280px] flex-col gap-2">
                        <form method="post" class="grid gap-2">
                            <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
                            <input name="holder_label" value="<?= logi_h($holder) ?>" placeholder="Detenteur / service" class="h-10 rounded-xl border border-slate-200 px-3 text-sm">
                            <input name="decision_note" placeholder="Note decision / details" class="h-10 rounded-xl border border-slate-200 px-3 text-sm">
                            <div class="flex flex-wrap justify-end gap-2">
                                <button name="logi_request_action" value="approve" class="rounded-xl bg-emerald-600 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-700">Accorder</button>
                                <button name="logi_request_action" value="details" class="rounded-xl bg-amber-500 px-3 py-2 text-xs font-semibold text-slate-950 hover:bg-amber-400">Details</button>
                                <button name="logi_request_action" value="reject" class="rounded-xl bg-rose-600 px-3 py-2 text-xs font-semibold text-white hover:bg-rose-700">Refuser</button>
                                <button name="logi_request_action" value="handover" class="rounded-xl bg-slate-900 px-3 py-2 text-xs font-semibold text-white hover:bg-slate-800">Remettre</button>
                            </div>
                        </form>
                        <?php if (!$isStock): ?>
                            <form method="post" class="flex gap-2">
                                <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
                                <input name="return_location" value="<?= logi_h($r['current_site'] ?? $r['site_label'] ?? '') ?>" placeholder="Lieu retour" class="min-w-0 flex-1 rounded-xl border border-slate-200 px-3 py-2 text-sm">
                                <button name="logi_request_action" value="recover" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">Recuperer</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($rows)): ?>
            <tr><td colspan="7" class="py-8 text-center font-semibold text-slate-500">Aucune demande trouvee.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?php logi_card_close(); logi_page_end(); ?>
