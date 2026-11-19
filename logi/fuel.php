<?php
require_once __DIR__ . '/_logistics_common.php';
require_once __DIR__ . '/../services/FuelLogisticsService.php';

$fuelService = new FuelLogisticsService($pdo);
$stations = $fuelService->listStations();
$flash = '';
$flashType = 'success';
$q = logi_query_value();

if (isset($_GET['station'])) {
    $flash = 'Station partenaire enregistree.';
}
if (isset($_GET['deposit'])) {
    $flash = 'Rechargement carburant enregistre et solde general credite.';
}

$deposits = $fuelService->listDeposits(80);
$vouchers = $fuelService->listVouchers(80);
$stationRows = logi_filter_rows($stations, ['name', 'contact_name', 'phone', 'address', 'status'], $q);
$depositRows = logi_filter_rows($deposits, ['deposit_date', 'station_name', 'amount', 'reference', 'receipt_name', 'notes'], $q);
$voucherRows = logi_filter_rows($vouchers, ['voucher_code', 'station_name', 'chantier_code', 'chantier_titre', 'asset_label', 'vehicle_label', 'driver_name', 'beneficiary_name', 'fiche_num', 'purpose', 'amount'], $q);
$balance = $fuelService->generalBalance();
$deposited = array_sum(array_map(static fn(array $row): float => (float)($row['amount'] ?? 0), $deposits));
$issued = array_sum(array_map(static fn(array $row): float => (float)($row['amount'] ?? 0), $vouchers));
$issuedLiters = array_sum(array_map(static fn(array $row): float => (float)($row['quantity_liters'] ?? 0), $vouchers));

$fuelSummary = [
    'chantier' => [],
    'station' => [],
    'asset' => [],
    'driver' => [],
];
foreach ($vouchers as $voucher) {
    $amount = (float)($voucher['amount'] ?? 0);
    $liters = (float)($voucher['quantity_liters'] ?? 0);
    $summaryKeys = [
        'chantier' => trim((string)($voucher['chantier_code'] ?? '') . ' ' . (string)($voucher['chantier_titre'] ?? '')) ?: 'Sans chantier',
        'station' => trim((string)($voucher['station_name'] ?? '')) ?: 'Sans station',
        'asset' => trim((string)($voucher['asset_label'] ?? '')) ?: (trim((string)($voucher['vehicle_label'] ?? '')) ?: 'Sans engin'),
        'driver' => trim((string)($voucher['driver_name'] ?? '')) ?: (trim((string)($voucher['beneficiary_name'] ?? '')) ?: 'Sans chauffeur'),
    ];
    foreach ($summaryKeys as $type => $label) {
        if (!isset($fuelSummary[$type][$label])) {
            $fuelSummary[$type][$label] = ['label' => $label, 'count' => 0, 'amount' => 0.0, 'liters' => 0.0];
        }
        $fuelSummary[$type][$label]['count']++;
        $fuelSummary[$type][$label]['amount'] += $amount;
        $fuelSummary[$type][$label]['liters'] += $liters;
    }
}
foreach ($fuelSummary as $type => $rows) {
    uasort($rows, static fn(array $a, array $b): int => $b['amount'] <=> $a['amount']);
    $fuelSummary[$type] = array_slice(array_values($rows), 0, 8);
}

logi_page_start('Carburant', 'Pilotage des rechargements, stations partenaires, bons carburant et controles de consommation.', 'fa-gas-pump');
?>

<?php if ($flash !== ''): ?>
    <div class="mb-6 rounded-2xl border <?= $flashType === 'error' ? 'border-rose-200 bg-rose-50 text-rose-800' : 'border-emerald-200 bg-emerald-50 text-emerald-800' ?> px-5 py-4 font-semibold">
        <?= logi_h($flash) ?>
    </div>
<?php endif; ?>

<section class="mb-6 grid gap-4 lg:grid-cols-[1fr_auto] lg:items-center">
    <form method="get" class="rounded-[1.5rem] border border-white/70 bg-white/95 p-4 shadow-sm">
        <div class="grid gap-3 md:grid-cols-[1fr_auto_auto] md:items-center">
            <div class="relative">
                <i class="fas fa-magnifying-glass absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"></i>
                <input name="q" value="<?= logi_h($q) ?>" placeholder="Rechercher station, rechargement, bon, chantier, engin..." class="h-12 w-full rounded-2xl border border-slate-200 bg-slate-50 pl-11 pr-4 text-slate-800 outline-none transition focus:border-orange-300 focus:bg-white focus:ring-4 focus:ring-orange-100">
            </div>
            <button class="h-12 rounded-2xl bg-slate-900 px-5 text-sm font-semibold text-white hover:bg-slate-800" type="submit"><i class="fas fa-filter mr-2"></i>Rechercher</button>
            <a class="inline-flex h-12 items-center justify-center rounded-2xl border border-slate-200 bg-white px-5 text-sm font-semibold text-slate-700 hover:shadow-sm" href="fuel.php">Reinitialiser</a>
        </div>
    </form>
    <div class="flex flex-wrap justify-end gap-3">
        <a href="fuel_deposit_form.php" class="inline-flex h-12 items-center rounded-2xl bg-orange-600 px-5 text-sm font-semibold text-white shadow-lg shadow-orange-600/15 transition hover:bg-orange-700"><i class="fas fa-plus mr-2"></i>Nouveau rechargement</a>
        <a href="fuel_station_form.php" class="inline-flex h-12 items-center rounded-2xl border border-slate-200 bg-white px-5 text-sm font-semibold text-slate-800 shadow-sm transition hover:bg-orange-50"><i class="fas fa-gas-pump mr-2 text-orange-600"></i>Nouvelle station</a>
    </div>
</section>

<section class="grid md:grid-cols-4 gap-5 mb-8">
    <article class="rounded-3xl bg-white/90 p-6 shadow-sm">
        <div class="text-xs uppercase tracking-[0.22em] text-slate-500">Solde carburant general</div>
        <div class="mt-3 text-3xl font-semibold <?= $balance < 0 ? 'text-rose-700' : 'text-emerald-700' ?>"><?= logi_money($balance) ?></div>
    </article>
    <article class="rounded-3xl bg-white/90 p-6 shadow-sm">
        <div class="text-xs uppercase tracking-[0.22em] text-slate-500">Depots station</div>
        <div class="mt-3 text-3xl font-semibold"><?= logi_money($deposited) ?></div>
    </article>
    <article class="rounded-3xl bg-white/90 p-6 shadow-sm">
        <div class="text-xs uppercase tracking-[0.22em] text-slate-500">Bons emis</div>
        <div class="mt-3 text-3xl font-semibold"><?= logi_money($issued) ?></div>
        <div class="mt-2 text-xs font-semibold text-slate-500"><?= number_format($issuedLiters, 2, ',', ' ') ?> L renseignes</div>
    </article>
    <article class="rounded-3xl bg-white/90 p-6 shadow-sm">
        <div class="text-xs uppercase tracking-[0.22em] text-slate-500">Stations actives</div>
        <div class="mt-3 text-3xl font-semibold"><?= count($stations) ?></div>
    </article>
</section>

<section class="mb-8 grid gap-4 md:grid-cols-3">
    <a href="fuel_deposit_form.php" class="rounded-[1.5rem] border border-orange-100 bg-white/95 p-5 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md">
        <div class="flex items-center gap-4"><span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-orange-50 text-orange-700"><i class="fas fa-money-bill-transfer"></i></span><div><div class="font-semibold text-slate-900">Rechargements</div><div class="text-sm text-slate-500">Crediter le solde carburant</div></div></div>
    </a>
    <a href="fuel_station_form.php" class="rounded-[1.5rem] border border-slate-100 bg-white/95 p-5 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md">
        <div class="flex items-center gap-4"><span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-900 text-[#fabd02]"><i class="fas fa-warehouse"></i></span><div><div class="font-semibold text-slate-900">Stations</div><div class="text-sm text-slate-500">Gerer les partenaires carburant</div></div></div>
    </a>
    <a href="#bons-carburant" class="rounded-[1.5rem] border border-sky-100 bg-white/95 p-5 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md">
        <div class="flex items-center gap-4"><span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-sky-50 text-sky-700"><i class="fas fa-ticket"></i></span><div><div class="font-semibold text-slate-900">Bons carburant</div><div class="text-sm text-slate-500">Imprimer et controler</div></div></div>
    </a>
</section>

<?php logi_card_open('Stations partenaires', ($q !== '' ? count($stationRows) . ' resultat(s)' : 'Stations disponibles pour les rechargements carburant'), '<a href="fuel_station_form.php" class="inline-flex items-center rounded-2xl bg-orange-600 px-4 py-2 text-sm font-semibold text-white"><i class="fas fa-plus mr-2"></i>Ajouter</a>'); ?>
<div class="overflow-x-auto">
    <table class="w-full border-separate border-spacing-y-3">
        <thead><tr class="text-left text-xs uppercase tracking-[0.18em] text-slate-500"><th>Station</th><th>Contact</th><th>Telephone</th><th>Adresse</th><th>Statut</th></tr></thead>
        <tbody>
            <?php foreach ($stationRows as $row): ?>
                <tr class="bg-white shadow-sm">
                    <td class="rounded-l-2xl px-4 py-4 font-semibold text-slate-900"><?= logi_h($row['name'] ?? '') ?></td>
                    <td><?= logi_h($row['contact_name'] ?? '') ?></td>
                    <td><?= logi_h($row['phone'] ?? '') ?></td>
                    <td><?= logi_h($row['address'] ?? '') ?></td>
                    <td class="rounded-r-2xl"><?= logi_badge($row['status'] ?? 'active') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($stationRows)): ?>
                <tr><td colspan="5" class="rounded-2xl bg-white px-4 py-5 text-center text-slate-500">Aucune station trouvee.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<?php logi_card_close(); ?>

<?php logi_card_open('Rechargements carburant', ($q !== '' ? count($depositRows) . ' resultat(s)' : 'Approvisionnements carburant par station'), '<a href="fuel_deposit_form.php" class="inline-flex items-center rounded-2xl bg-orange-600 px-4 py-2 text-sm font-semibold text-white"><i class="fas fa-plus mr-2"></i>Ajouter</a>'); ?>
<div class="overflow-x-auto">
    <table class="w-full border-separate border-spacing-y-3">
        <thead><tr class="text-left text-xs uppercase tracking-[0.18em] text-slate-500"><th>Date</th><th>Station</th><th>Montant</th><th>Reference</th><th>Recu</th></tr></thead>
        <tbody>
            <?php foreach ($depositRows as $row): ?>
                <tr class="bg-white shadow-sm">
                    <td class="px-4 py-4 rounded-l-2xl"><?= logi_h($row['deposit_date']) ?></td>
                    <td class="font-semibold text-slate-900"><?= logi_h($row['station_name']) ?></td>
                    <td><?= logi_money($row['amount']) ?></td>
                    <td><?= logi_h($row['reference']) ?></td>
                    <td class="rounded-r-2xl"><?php if (!empty($row['receipt_path'])): ?><a class="text-orange-700 font-semibold" target="_blank" href="../<?= logi_h($row['receipt_path']) ?>">Ouvrir</a><?php else: ?><span class="text-slate-400">-</span><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($depositRows)): ?>
                <tr><td colspan="5" class="rounded-2xl bg-white px-4 py-5 text-center text-slate-500">Aucun rechargement trouve.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<?php logi_card_close(); ?>

<?php logi_card_open('Bons carburant', 'Les bons sont generes automatiquement apres validation comptable des fiches carburant.'); ?>
<div class="rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm leading-6 text-amber-950">
    Les depots en station alimentent le solde general carburant. Chaque prise effective doit passer par une fiche de decaissement carburant rattachee au chantier et a la ligne debourse Carburant. Apres decaissement comptable, le bon apparait ici pour impression et le solde carburant est debite.
</div>
<?php logi_card_close(); ?>

<?php logi_card_open('Recap des prises carburant', 'Synthese des bons emis par chantier, station, engin et chauffeur.'); ?>
<div class="grid lg:grid-cols-4 gap-4">
    <?php foreach ([
        'chantier' => 'Par chantier',
        'station' => 'Par station',
        'asset' => 'Par engin',
        'driver' => 'Par chauffeur',
    ] as $summaryType => $summaryTitle): ?>
        <section class="rounded-2xl border border-slate-100 bg-slate-50/80 p-4">
            <h3 class="text-sm font-bold text-slate-800"><?= logi_h($summaryTitle) ?></h3>
            <div class="mt-3 space-y-3">
                <?php if (empty($fuelSummary[$summaryType])): ?>
                    <div class="rounded-xl bg-white px-3 py-3 text-sm text-slate-500">Aucune prise enregistree.</div>
                <?php endif; ?>
                <?php foreach ($fuelSummary[$summaryType] as $summaryRow): ?>
                    <div class="rounded-xl bg-white px-3 py-3 shadow-sm">
                        <div class="text-sm font-semibold text-slate-900 break-words"><?= logi_h($summaryRow['label']) ?></div>
                        <div class="mt-2 flex flex-wrap items-center gap-2 text-xs font-semibold text-slate-500">
                            <span><?= (int)$summaryRow['count'] ?> bon(s)</span>
                            <span><?= number_format((float)$summaryRow['liters'], 2, ',', ' ') ?> L</span>
                            <span class="text-orange-700"><?= logi_money($summaryRow['amount']) ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
</div>
<?php logi_card_close(); ?>

<div id="bons-carburant">
    <?php logi_card_open('Prises carburant', ($q !== '' ? count($voucherRows) . ' resultat(s)' : 'Detail des bons emis et consommations rattachees.')); ?>
    <div class="overflow-x-auto">
        <table class="w-full border-separate border-spacing-y-3">
            <thead><tr class="text-left text-xs uppercase tracking-[0.18em] text-slate-500"><th>Bon</th><th>Station</th><th>Chantier</th><th>Engin</th><th>Chauffeur</th><th>Litres</th><th>Montant</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($voucherRows as $row): ?>
                    <tr class="bg-white shadow-sm">
                        <td class="px-4 py-4 rounded-l-2xl font-semibold"><?= logi_h($row['voucher_code']) ?></td>
                        <td><?= logi_h($row['station_name']) ?></td>
                        <td><?= logi_h(trim((string)($row['chantier_code'] ?? '') . ' ' . (string)($row['chantier_titre'] ?? ''))) ?></td>
                        <td><?= logi_h(trim((string)($row['asset_label'] ?? '')) ?: (string)($row['vehicle_label'] ?? '')) ?></td>
                        <td><?= logi_h(trim((string)($row['driver_name'] ?? '')) ?: (string)($row['beneficiary_name'] ?? '')) ?></td>
                        <td><?= number_format((float)($row['quantity_liters'] ?? 0), 2, ',', ' ') ?></td>
                        <td><?= logi_money($row['amount']) ?></td>
                        <td class="rounded-r-2xl"><a class="text-orange-700 font-semibold" target="_blank" href="../decaissement/bon_essence.php?id=<?= (int)$row['id'] ?>">Imprimer</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($voucherRows)): ?>
                    <tr><td colspan="8" class="rounded-2xl bg-white px-4 py-5 text-center text-slate-500">Aucune prise carburant enregistree.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php logi_card_close(); ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('[data-fuel-drop]').forEach(function(root) {
        const input = root.querySelector('[data-drop-input]');
        const label = root.querySelector('[data-drop-label]');
        const preview = root.querySelector('[data-drop-preview]');
        const thumb = root.querySelector('[data-drop-thumb]');
        const nameEl = root.querySelector('[data-drop-name]');
        const sizeEl = root.querySelector('[data-drop-size]');
        const remove = root.querySelector('[data-drop-remove]');
        let objectUrl = '';

        function formatSize(bytes) {
            if (!bytes) return '0 Ko';
            if (bytes < 1024 * 1024) return Math.round(bytes / 1024) + ' Ko';
            return (bytes / (1024 * 1024)).toFixed(1) + ' Mo';
        }

        function clearPreview() {
            if (objectUrl) URL.revokeObjectURL(objectUrl);
            objectUrl = '';
            input.value = '';
            preview.classList.add('hidden');
            thumb.innerHTML = '';
            nameEl.textContent = '';
            sizeEl.textContent = '';
        }

        function renderFile(file) {
            if (!file) {
                clearPreview();
                return;
            }
            if (objectUrl) URL.revokeObjectURL(objectUrl);
            objectUrl = '';
            nameEl.textContent = file.name;
            sizeEl.textContent = formatSize(file.size);
            thumb.innerHTML = '';

            if (file.type.indexOf('image/') === 0) {
                objectUrl = URL.createObjectURL(file);
                const img = document.createElement('img');
                img.src = objectUrl;
                img.alt = file.name;
                img.className = 'h-full w-full object-cover';
                thumb.appendChild(img);
            } else {
                const icon = document.createElement('i');
                icon.className = 'fas fa-file-pdf text-3xl text-rose-500';
                thumb.appendChild(icon);
            }
            preview.classList.remove('hidden');
        }

        input.addEventListener('change', function() {
            renderFile(input.files && input.files[0]);
        });

        ['dragenter', 'dragover'].forEach(function(eventName) {
            label.addEventListener(eventName, function(event) {
                event.preventDefault();
                label.classList.add('border-orange-400', 'bg-orange-50');
            });
        });

        ['dragleave', 'drop'].forEach(function(eventName) {
            label.addEventListener(eventName, function(event) {
                event.preventDefault();
                label.classList.remove('border-orange-400', 'bg-orange-50');
            });
        });

        label.addEventListener('drop', function(event) {
            const file = event.dataTransfer && event.dataTransfer.files && event.dataTransfer.files[0];
            if (!file) return;
            const transfer = new DataTransfer();
            transfer.items.add(file);
            input.files = transfer.files;
            renderFile(file);
        });

        remove.addEventListener('click', clearPreview);
    });
});
</script>

<?php logi_page_end(); ?>
