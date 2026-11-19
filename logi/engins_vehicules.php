<?php
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../controllers/LogisticsAssetController.php';
require_once __DIR__ . '/_logistics_common.php';

if (class_exists('AccessManager')) {
    AccessManager::requirePageAccess();
}

$pageTitle = 'Parc Logistique';
$activeMenu = 'logistique';

$controller = new LogisticsAssetController();
$view = $controller->index($_GET);
$assets = $view['assets'] ?? $view['items'] ?? [];
$chantiers = logi_chantiers($pdo);
$summary = $view['summary'] ?? [
    'total' => count($assets),
    'disponible' => count(array_filter($assets, fn($asset) => ($asset['status'] ?? '') === 'disponible')),
    'occupe' => count(array_filter($assets, fn($asset) => ($asset['status'] ?? '') === 'occupe')),
    'maintenance' => count(array_filter($assets, fn($asset) => ($asset['status'] ?? '') === 'maintenance')),
    'panne' => count(array_filter($assets, fn($asset) => in_array(($asset['status'] ?? ''), ['panne', 'hors_service'], true))),
];

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function badgeAssetStatus(string $status): string
{
    return match ($status) {
        'disponible' => 'bg-emerald-100 text-emerald-700 border-emerald-200',
        'occupe' => 'bg-blue-100 text-blue-700 border-blue-200',
        'maintenance' => 'bg-amber-100 text-amber-700 border-amber-200',
        'panne', 'hors_service' => 'bg-red-100 text-red-700 border-red-200',
        default => 'bg-slate-100 text-slate-700 border-slate-200',
    };
}

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>
<div class="min-h-screen bg-slate-50 px-4 md:px-6 pb-12">
    <div class="max-w-7xl mx-auto pt-5">
        <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-5 mb-7">
            <div>
                <p class="text-xs uppercase tracking-[0.25em] text-orange-600 font-semibold mb-2">Module logistique</p>
                <h1 class="text-3xl md:text-4xl font-bold text-slate-900">Parc engins & véhicules</h1>
                <p class="mt-3 text-slate-600 max-w-3xl">Création, suivi et pilotage opérationnel des ressources logistiques affectées aux chantiers.</p>
            </div>
            <div class="flex gap-3 flex-wrap">
                <a href="dashboard_logistique.php" class="px-4 py-3 rounded-2xl bg-white border border-slate-200 text-slate-700 font-semibold shadow-sm">Dashboard</a>
                <button type="button" onclick="openAssetModal(true)" class="px-5 py-3 rounded-2xl bg-orange-600 text-white font-semibold shadow-lg shadow-orange-600/20">Ajouter une ressource</button>
            </div>
        </div>

        <div class="grid gap-6 xl:grid-cols-[1fr_320px]">
            <main class="min-w-0">

        <?php if (!empty($_GET['success'])): ?>
            <div class="mb-5 rounded-2xl bg-emerald-50 border border-emerald-200 text-emerald-700 px-5 py-4 font-semibold">Opération effectuée avec succès.</div>
        <?php endif; ?>
        <?php if (!empty($_GET['error'])): ?>
            <div class="mb-5 rounded-2xl bg-red-50 border border-red-200 text-red-700 px-5 py-4 font-semibold"><?= e($_GET['error']) ?></div>
        <?php endif; ?>

        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-5 gap-4 mb-7">
            <?php
            $cards = [
                ['Total parc', $summary['total'] ?? 0, 'fa-layer-group'],
                ['Disponibles', $summary['disponible'] ?? 0, 'fa-circle-check'],
                ['Occupés', $summary['occupe'] ?? 0, 'fa-route'],
                ['Maintenance', $summary['maintenance'] ?? 0, 'fa-screwdriver-wrench'],
                ['Panne / hors service', $summary['panne'] ?? 0, 'fa-triangle-exclamation'],
            ];
            foreach ($cards as $card): ?>
                <div class="rounded-3xl bg-white border border-slate-200 p-5 shadow-sm">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-slate-500"><?= e($card[0]) ?></p>
                            <p class="text-3xl font-bold text-slate-900 mt-2"><?= e($card[1]) ?></p>
                        </div>
                        <div class="w-12 h-12 rounded-2xl bg-orange-50 text-orange-600 grid place-items-center"><i class="fas <?= e($card[2]) ?>"></i></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <form method="get" class="rounded-3xl bg-white border border-slate-200 p-4 shadow-sm mb-6 grid grid-cols-1 md:grid-cols-4 gap-3">
            <input name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Rechercher code, désignation, immatriculation, chantier..." class="md:col-span-2 rounded-2xl border border-slate-200 px-4 py-3 outline-none focus:ring-2 focus:ring-orange-200">
            <select name="asset_type" class="rounded-2xl border border-slate-200 px-4 py-3 outline-none focus:ring-2 focus:ring-orange-200">
                <option value="">Tous les types</option>
                <?php foreach (['engin'=>'Engin','vehicule'=>'Véhicule','materiel'=>'Matériel','piece'=>'Pièce','autre'=>'Autre'] as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= (($_GET['asset_type'] ?? '') === $value) ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="status" class="rounded-2xl border border-slate-200 px-4 py-3 outline-none focus:ring-2 focus:ring-orange-200">
                <option value="">Tous les statuts</option>
                <?php foreach (['disponible'=>'Disponible','occupe'=>'Occupé','maintenance'=>'Maintenance','panne'=>'Panne','hors_service'=>'Hors service'] as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= (($_GET['status'] ?? '') === $value) ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="md:col-span-4 rounded-2xl bg-slate-900 text-white px-4 py-3 font-semibold">Filtrer</button>
        </form>

        <div class="rounded-3xl bg-white border border-slate-200 shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-900 text-white">
                        <tr>
                            <th class="text-left px-5 py-4">Code</th>
                            <th class="text-left px-5 py-4">Désignation</th>
                            <th class="text-left px-5 py-4">Type</th>
                            <th class="text-left px-5 py-4">Immatriculation</th>
                            <th class="text-left px-5 py-4">Chantier actuel</th>
                            <th class="text-left px-5 py-4">Statut</th>
                            <th class="text-right px-5 py-4">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php if (empty($assets)): ?>
                            <tr><td colspan="7" class="px-5 py-10 text-center text-slate-500">Aucune ressource logistique enregistrée.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($assets as $asset): ?>
                            <tr class="hover:bg-slate-50">
                                <td class="px-5 py-4 font-semibold text-slate-900"><?= e($asset['asset_code']) ?></td>
                                <td class="px-5 py-4">
                                    <div class="font-semibold text-slate-800"><?= e($asset['designation']) ?></div>
                                    <div class="text-xs text-slate-500"><?= e(trim(($asset['brand'] ?? '') . ' ' . ($asset['model'] ?? ''))) ?></div>
                                </td>
                                <td class="px-5 py-4 capitalize"><?= e($asset['asset_type']) ?></td>
                                <td class="px-5 py-4"><?= e($asset['registration_number'] ?? '-') ?></td>
                                <td class="px-5 py-4"><?= e($asset['current_site'] ?? '-') ?></td>
                                <td class="px-5 py-4"><span class="inline-flex border px-3 py-1 rounded-full text-xs font-bold <?= badgeAssetStatus((string) $asset['status']) ?>"><?= e(str_replace('_', ' ', $asset['status'])) ?></span></td>
                                <td class="px-5 py-4 text-right">
                                    <button type="button" class="text-orange-600 font-semibold" onclick='editAsset(<?= json_encode($asset, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>)'>Modifier</button>
                                    <form method="post" action="request/save_asset.php" class="inline" onsubmit="return confirm('Supprimer cette ressource ?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int) $asset['id'] ?>">
                                        <button class="ml-4 text-red-600 font-semibold">Supprimer</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
            </main>
            <?= logi_quick_access_sidebar() ?>
        </div>
    </div>
</div>

<div id="assetModal" class="fixed inset-0 bg-slate-900/60 hidden items-center justify-center z-50 px-4">
    <div class="bg-white rounded-[2rem] max-w-4xl w-full max-h-[92vh] overflow-y-auto shadow-2xl">
        <div class="p-6 border-b border-slate-100 flex items-center justify-between">
            <div>
                <h2 class="text-2xl font-bold text-slate-900">Ressource logistique</h2>
                <p class="text-slate-500 mt-1">Engin, véhicule, matériel, pièce ou autre ressource.</p>
            </div>
            <button onclick="closeAssetModal()" class="w-10 h-10 rounded-full bg-slate-100 text-slate-600">×</button>
        </div>
        <form method="post" action="request/save_asset.php" class="p-6 grid grid-cols-1 md:grid-cols-2 gap-4">
            <input type="hidden" name="id" id="asset_id">
            <div><label class="text-sm font-semibold text-slate-700">Code interne *</label><input name="asset_code" id="asset_code" readonly class="mt-1 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3"></div>
            <div><label class="text-sm font-semibold text-slate-700">Type *</label><select name="asset_type" id="asset_type" required class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-3"><option value="engin">Engin</option><option value="vehicule">Véhicule</option><option value="materiel">Matériel</option><option value="piece">Pièce</option><option value="autre">Autre</option></select></div>
            <div><label class="text-sm font-semibold text-slate-700">Catégorie</label><input name="category" id="category" placeholder="Pelle, camion, grue..." class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-3"></div>
            <div><label class="text-sm font-semibold text-slate-700">Désignation *</label><input name="designation" id="designation" required class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-3"></div>
            <div><label class="text-sm font-semibold text-slate-700">Marque</label><input name="brand" id="brand" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-3"></div>
            <div><label class="text-sm font-semibold text-slate-700">Modèle</label><input name="model" id="model" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-3"></div>
            <div><label class="text-sm font-semibold text-slate-700">Immatriculation</label><input name="registration_number" id="registration_number" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-3"></div>
            <div><label class="text-sm font-semibold text-slate-700">N° série</label><input name="serial_number" id="serial_number" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-3"></div>
            <div><label class="text-sm font-semibold text-slate-700">Statut</label><select name="status" id="status" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-3"><option value="disponible">Disponible</option><option value="occupe">Occupé</option><option value="maintenance">Maintenance</option><option value="panne">Panne</option><option value="hors_service">Hors service</option></select></div>
            <?= logi_chantier_search_input('Chantier actuel', 'current_site', $chantiers, '', 'id="current_site"') ?>
            <div><label class="text-sm font-semibold text-slate-700">Compteur horaire</label><input type="number" step="0.01" name="hour_meter" id="hour_meter" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-3"></div>
            <div><label class="text-sm font-semibold text-slate-700">Kilométrage</label><input type="number" step="0.01" name="mileage" id="mileage" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-3"></div>
            <div><label class="text-sm font-semibold text-slate-700">Capacité carburant</label><input type="number" step="0.01" name="fuel_capacity" id="fuel_capacity" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-3"></div>
            <div><label class="text-sm font-semibold text-slate-700">Date acquisition</label><input type="date" name="acquisition_date" id="acquisition_date" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-3"></div>
            <div class="md:col-span-2"><label class="text-sm font-semibold text-slate-700">Notes</label><textarea name="notes" id="notes" rows="3" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-3"></textarea></div>
            <div class="md:col-span-2 flex justify-end gap-3 pt-3">
                <button type="button" onclick="closeAssetModal()" class="px-5 py-3 rounded-2xl bg-slate-100 text-slate-700 font-semibold">Annuler</button>
                <button class="px-5 py-3 rounded-2xl bg-orange-600 text-white font-semibold">Enregistrer</button>
            </div>
        </form>
    </div>
</div>

<script>
const existingAssetCodes = <?= json_encode(array_values(array_filter(array_column($assets, 'asset_code'))), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>;
function openAssetModal(resetForm) {
    if (resetForm) {
        const form = document.querySelector('#assetModal form');
        if (form) form.reset();
        const id = document.getElementById('asset_id');
        if (id) id.value = '';
    }
    document.getElementById('assetModal').classList.remove('hidden');
    document.getElementById('assetModal').classList.add('flex');
    previewAssetCode();
}
function closeAssetModal() {
    document.getElementById('assetModal').classList.add('hidden');
    document.getElementById('assetModal').classList.remove('flex');
}
function editAsset(asset) {
    openAssetModal();
    const fields = ['id','asset_code','asset_type','category','designation','brand','model','registration_number','serial_number','status','current_site','hour_meter','mileage','fuel_capacity','acquisition_date','notes'];
    fields.forEach(function(field) {
        const el = document.getElementById(field === 'id' ? 'asset_id' : field);
        if (el) {
            el.value = asset[field] || '';
            const searchRoot = el.closest('.logi-search-select');
            const searchInput = searchRoot ? searchRoot.querySelector('[data-search-input]') : null;
            if (searchInput) searchInput.value = asset[field] || '';
        }
    });
}
function normalizeAssetCode(value) {
    return (value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toUpperCase().replace(/[^A-Z]/g, '');
}
function previewAssetCode() {
    const id = document.getElementById('asset_id');
    const code = document.getElementById('asset_code');
    const type = document.getElementById('asset_type');
    const designation = document.getElementById('designation');
    const registration = document.getElementById('registration_number');
    if (!code || !type || !designation || !registration || (id && id.value)) return;
    const source = designation.value.trim() || registration.value.trim() || type.value.trim() || 'engin';
    const fallback = normalizeAssetCode(type.value || 'engin') || 'ENG';
    const prefix = (normalizeAssetCode(source) + fallback + 'XXX').slice(0, 3);
    let max = 0;
    existingAssetCodes.forEach(function(existingCode) {
        const match = String(existingCode).match(new RegExp('^' + prefix + '-(\\d+)$'));
        if (match) max = Math.max(max, parseInt(match[1], 10) || 0);
    });
    code.value = prefix + '-' + String(max + 1).padStart(3, '0');
}
['asset_type','designation','registration_number'].forEach(function(id) {
    const field = document.getElementById(id);
    if (field) {
        field.addEventListener('input', previewAssetCode);
        field.addEventListener('change', previewAssetCode);
    }
});
</script>
<?php logi_search_select_assets(); require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>
