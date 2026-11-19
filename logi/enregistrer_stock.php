<?php
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/LogisticsInventory.php';

\AccessManager::requirePageAccess();

$pageTitle = 'Enregistrer stock';
$activeMenu = 'logistique';

$pdo = \Database::getConnection();
$inventory = new \LogisticsInventory($pdo);
$setupStatus = $inventory->getSetupStatus();
$options = $inventory->getAssetRegistrationOptions();

if (empty($_SESSION['logistics_inventory_asset_token'])) {
    $_SESSION['logistics_inventory_asset_token'] = bin2hex(random_bytes(24));
}

$assetResult = null;
$formData = [
    'asset_code' => trim((string) ($_POST['asset_code'] ?? '')),
    'asset_code_mode' => trim((string) ($_POST['asset_code_mode'] ?? 'auto')),
    'asset_type' => trim((string) ($_POST['asset_type'] ?? 'materiel')),
    'category' => trim((string) ($_POST['category'] ?? '')),
    'designation' => trim((string) ($_POST['designation'] ?? '')),
    'unite_mesure_id' => trim((string) ($_POST['unite_mesure_id'] ?? '')),
    'unit' => trim((string) ($_POST['unit'] ?? 'u')),
    'quantity_total' => trim((string) ($_POST['quantity_total'] ?? '1')),
    'quantity_available' => trim((string) ($_POST['quantity_available'] ?? '')),
    'min_threshold' => trim((string) ($_POST['min_threshold'] ?? '0')),
    'condition_state' => trim((string) ($_POST['condition_state'] ?? 'bon')),
    'availability_status' => trim((string) ($_POST['availability_status'] ?? 'disponible')),
    'location_scope' => trim((string) ($_POST['location_scope'] ?? 'bureau')),
    'chantier_id' => trim((string) ($_POST['chantier_id'] ?? '')),
    'location_label' => trim((string) ($_POST['location_label'] ?? '')),
    'serial_number' => trim((string) ($_POST['serial_number'] ?? '')),
    'acquisition_date' => trim((string) ($_POST['acquisition_date'] ?? '')),
    'notes' => trim((string) ($_POST['notes'] ?? '')),
];

$availableUnits = $options['units'] ?? [];
$hasMeasureUnits = !empty($availableUnits);

$suggestedAssetCode = $inventory->suggestNextAssetCode($formData);
$displayedAssetCode = $formData['asset_code_mode'] === 'manual' && $formData['asset_code'] !== ''
    ? $formData['asset_code']
    : $suggestedAssetCode;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'create_asset') {
    $submittedToken = (string) ($_POST['asset_token'] ?? '');
    if (!hash_equals((string) $_SESSION['logistics_inventory_asset_token'], $submittedToken)) {
        \AuditTrail::log('inventory_asset_create_failed', [
            'module' => 'logi',
            'action' => 'create_asset',
            'entity_type' => 'inventory_asset',
            'entity_label' => (string)($formData['designation'] ?: 'Nouvel article'),
            'result' => 'failed',
            'severity' => 'warning',
            'details' => [
                'reason' => 'invalid_token',
            ],
        ], $pdo);
        $assetResult = [
            'success' => false,
            'message' => 'La demande est invalide. Rechargez la page puis réessayez.',
        ];
    } else {
        $assetResult = $inventory->createAsset($formData, (string) ($_SESSION['user_name'] ?? $_SESSION['username'] ?? 'système'));
        \AuditTrail::log(!empty($assetResult['success']) ? 'inventory_asset_created' : 'inventory_asset_create_failed', [
            'module' => 'logi',
            'action' => 'create_asset',
            'entity_type' => 'inventory_asset',
            'entity_id' => isset($assetResult['asset']['id']) ? (string)$assetResult['asset']['id'] : null,
            'entity_label' => (string)($formData['designation'] ?: 'Nouvel article'),
            'result' => !empty($assetResult['success']) ? 'success' : 'failed',
            'severity' => !empty($assetResult['success']) ? 'info' : 'warning',
            'details' => [
                'asset_code' => $formData['asset_code'] !== '' ? $formData['asset_code'] : $displayedAssetCode,
                'asset_type' => $formData['asset_type'],
                'quantity_total' => $formData['quantity_total'],
                'message' => $assetResult['message'] ?? null,
            ],
        ], $pdo);
        if (!empty($assetResult['success'])) {
            $setupStatus = $inventory->getSetupStatus();
            $formData = [
                'asset_code' => '',
                'asset_code_mode' => 'auto',
                'asset_type' => 'materiel',
                'category' => '',
                'designation' => '',
                'unite_mesure_id' => '',
                'unit' => 'u',
                'quantity_total' => '1',
                'quantity_available' => '',
                'min_threshold' => '0',
                'condition_state' => 'bon',
                'availability_status' => 'disponible',
                'location_scope' => 'bureau',
                'chantier_id' => '',
                'location_label' => '',
                'serial_number' => '',
                'acquisition_date' => '',
                'notes' => '',
            ];
            $suggestedAssetCode = $inventory->suggestNextAssetCode($formData);
            $displayedAssetCode = $suggestedAssetCode;
        }
    }
}

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>
<div class="min-h-screen bg-[radial-gradient(circle_at_top,_#fff0db,_#fff_42%,_#fff7ed_100%)] px-6 pb-14">
    <div class="max-w-6xl mx-auto">
        <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-6 mb-10">
            <div>
                <p class="text-xs uppercase tracking-[0.25em] text-orange-600 font-semibold mb-3">Saisie inventaire</p>
                <h1 class="text-4xl md:text-5xl font-semibold text-slate-900">Enregistrer un article de stock</h1>
                <p class="mt-4 max-w-3xl text-slate-600">
                    Utilise cet écran pour créer ton stock initial ou enregistrer un nouvel équipement avec sa quantité, sa localisation et son état de disponibilité.
                </p>
            </div>
            <div class="flex flex-wrap gap-3">
                <a href="inventaire_materiel.php" class="px-5 py-3 rounded-2xl border border-slate-200 bg-white text-slate-700 font-semibold shadow-sm hover:shadow-md transition">Voir l'inventaire</a>
                <a href="dashboard_logistique.php" class="px-5 py-3 rounded-2xl bg-slate-900 text-white font-semibold shadow-sm hover:bg-slate-800 transition">Dashboard</a>
            </div>
        </div>

        <?php if (empty($setupStatus['inventory_ready'])): ?>
            <section class="rounded-[2rem] border border-amber-200 bg-white/90 p-8 shadow-sm mb-8">
                <div class="flex flex-col xl:flex-row xl:items-start xl:justify-between gap-6">
                    <div>
                        <p class="text-xs uppercase tracking-[0.22em] text-amber-600 font-semibold">Initialisation requise</p>
                        <h2 class="mt-2 text-2xl font-semibold text-slate-900">Le stock ne peut pas encore être enregistré dans les nouvelles tables</h2>
                        <p class="mt-4 max-w-3xl text-slate-600 leading-7">
                            Lance d'abord l'initialisation depuis l'inventaire ou le dashboard logistique. Dès que les tables sont prêtes, cette page permet la création directe des articles.
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-3">
                        <a href="inventaire_materiel.php" class="px-5 py-3 rounded-2xl bg-amber-500 text-white font-semibold shadow-lg shadow-amber-500/20 hover:bg-amber-600 transition">Initialiser le module</a>
                        <a href="dashboard_logistique.php" class="px-5 py-3 rounded-2xl border border-slate-200 bg-white text-slate-700 font-semibold shadow-sm hover:shadow-md transition">Retour dashboard</a>
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($assetResult !== null): ?>
            <section class="mb-8 rounded-[1.75rem] border px-6 py-5 shadow-sm <?= !empty($assetResult['success']) ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-rose-200 bg-rose-50 text-rose-800' ?>">
                <div class="font-semibold"><?= htmlspecialchars((string) ($assetResult['message'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
            </section>
        <?php endif; ?>

        <section class="grid grid-cols-1 xl:grid-cols-[1.15fr,0.85fr] gap-6">
            <article class="rounded-[2rem] border border-white/60 bg-white/90 p-8 shadow-sm">
                <div class="flex items-center justify-between gap-4 mb-6">
                    <div>
                        <p class="text-xs uppercase tracking-[0.22em] text-slate-500">Création article</p>
                        <h2 class="mt-2 text-2xl font-semibold text-slate-900">Fiche stock initial</h2>
                    </div>
                </div>

                <form method="post" class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <input type="hidden" name="action" value="create_asset">
                    <input type="hidden" name="asset_token" value="<?= htmlspecialchars((string) $_SESSION['logistics_inventory_asset_token'], ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="asset_code_mode" id="assetCodeModeField" value="<?= htmlspecialchars($formData['asset_code_mode'], ENT_QUOTES, 'UTF-8') ?>">

                    <label class="block md:col-span-2">
                        <span class="mb-2 block text-sm font-medium text-slate-600">Code article</span>
                        <div class="flex flex-col md:flex-row gap-3">
                            <input type="text" id="assetCodeField" name="asset_code" value="<?= htmlspecialchars($displayedAssetCode, ENT_QUOTES, 'UTF-8') ?>" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700 outline-none transition focus:border-orange-400" placeholder="EXC-001" readonly required>
                            <button type="button" id="assetCodeAutoButton" class="rounded-2xl border border-orange-200 bg-orange-50 px-4 py-3 text-sm font-semibold text-orange-700 transition hover:bg-orange-100">Auto</button>
                        </div>
                        <span class="mt-2 block text-xs text-slate-500">Le code se génère automatiquement depuis le type, la catégorie et la désignation. Le numéro final est ajusté automatiquement à l'enregistrement pour rester unique.</span>
                    </label>

                    <label class="block">
                        <span class="mb-2 block text-sm font-medium text-slate-600">Type</span>
                        <select id="assetTypeField" name="asset_type" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 outline-none transition focus:border-orange-400">
                            <?php foreach (($options['asset_types'] ?? []) as $assetType): ?>
                                <option value="<?= htmlspecialchars((string) $assetType, ENT_QUOTES, 'UTF-8') ?>" <?= $formData['asset_type'] === (string) $assetType ? 'selected' : '' ?>><?= htmlspecialchars(ucfirst((string) $assetType), ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="block">
                        <span class="mb-2 block text-sm font-medium text-slate-600">Catégorie</span>
                        <input type="text" id="assetCategoryField" name="category" value="<?= htmlspecialchars($formData['category'], ENT_QUOTES, 'UTF-8') ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 outline-none transition focus:border-orange-400" placeholder="Bétonnière, Acier, Carburant" required>
                    </label>

                    <label class="block">
                        <span class="mb-2 block text-sm font-medium text-slate-600">Désignation</span>
                        <input type="text" id="assetDesignationField" name="designation" value="<?= htmlspecialchars($formData['designation'], ENT_QUOTES, 'UTF-8') ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 outline-none transition focus:border-orange-400" placeholder="Bétonnière 350L" required>
                    </label>

                    <label class="block">
                        <span class="mb-2 block text-sm font-medium text-slate-600">Unité</span>
                        <?php if ($hasMeasureUnits): ?>
                            <select name="unite_mesure_id" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 outline-none transition focus:border-orange-400" required>
                                <option value="">Sélectionner une unité</option>
                                <?php foreach ($availableUnits as $unitOption): ?>
                                    <?php $unitId = (string) ($unitOption['id'] ?? ''); ?>
                                    <?php $unitLabel = trim((string) (($unitOption['symbole'] ?? '') . ' - ' . ($unitOption['libelle'] ?? ''))); ?>
                                    <option value="<?= htmlspecialchars($unitId, ENT_QUOTES, 'UTF-8') ?>" <?= $formData['unite_mesure_id'] === $unitId ? 'selected' : '' ?>><?= htmlspecialchars($unitLabel, ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span class="mt-2 block text-xs text-slate-500">Liste chargée depuis la table unites_mesure.</span>
                        <?php else: ?>
                            <input type="text" name="unit" value="<?= htmlspecialchars($formData['unit'], ENT_QUOTES, 'UTF-8') ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 outline-none transition focus:border-orange-400" placeholder="u, m3, sac">
                            <span class="mt-2 block text-xs text-slate-500">Aucune unité de mesure disponible dans la table dédiée, saisie libre utilisée temporairement.</span>
                        <?php endif; ?>
                    </label>

                    <label class="block">
                        <span class="mb-2 block text-sm font-medium text-slate-600">Quantité totale</span>
                        <input type="number" step="0.01" min="0" name="quantity_total" value="<?= htmlspecialchars($formData['quantity_total'], ENT_QUOTES, 'UTF-8') ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 outline-none transition focus:border-orange-400" required>
                    </label>

                    <label class="block">
                        <span class="mb-2 block text-sm font-medium text-slate-600">Quantité disponible</span>
                        <input type="number" step="0.01" min="0" name="quantity_available" value="<?= htmlspecialchars($formData['quantity_available'], ENT_QUOTES, 'UTF-8') ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 outline-none transition focus:border-orange-400" placeholder="Laisser vide pour reprendre le total">
                    </label>

                    <label class="block">
                        <span class="mb-2 block text-sm font-medium text-slate-600">Seuil minimum</span>
                        <input type="number" step="0.01" min="0" name="min_threshold" value="<?= htmlspecialchars($formData['min_threshold'], ENT_QUOTES, 'UTF-8') ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 outline-none transition focus:border-orange-400">
                    </label>

                    <label class="block">
                        <span class="mb-2 block text-sm font-medium text-slate-600">État matériel</span>
                        <select name="condition_state" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 outline-none transition focus:border-orange-400">
                            <?php foreach (($options['condition_states'] ?? []) as $conditionState): ?>
                                <option value="<?= htmlspecialchars((string) $conditionState, ENT_QUOTES, 'UTF-8') ?>" <?= $formData['condition_state'] === (string) $conditionState ? 'selected' : '' ?>><?= htmlspecialchars((string) $conditionState, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="block">
                        <span class="mb-2 block text-sm font-medium text-slate-600">Disponibilité</span>
                        <select name="availability_status" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 outline-none transition focus:border-orange-400">
                            <?php foreach (($options['availability_statuses'] ?? []) as $availabilityStatus): ?>
                                <option value="<?= htmlspecialchars((string) $availabilityStatus, ENT_QUOTES, 'UTF-8') ?>" <?= $formData['availability_status'] === (string) $availabilityStatus ? 'selected' : '' ?>><?= htmlspecialchars((string) $availabilityStatus, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="block">
                        <span class="mb-2 block text-sm font-medium text-slate-600">Localisation</span>
                        <select name="location_scope" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 outline-none transition focus:border-orange-400">
                            <?php foreach (($options['location_scopes'] ?? []) as $locationScope): ?>
                                <option value="<?= htmlspecialchars((string) $locationScope, ENT_QUOTES, 'UTF-8') ?>" <?= $formData['location_scope'] === (string) $locationScope ? 'selected' : '' ?>><?= htmlspecialchars(ucfirst((string) $locationScope), ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="block">
                        <span class="mb-2 block text-sm font-medium text-slate-600">Chantier</span>
                        <select name="chantier_id" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 outline-none transition focus:border-orange-400">
                            <option value="">Aucun chantier spécifique</option>
                            <?php foreach (($options['locations'] ?? []) as $location): ?>
                                <?php $siteId = (string) ($location['id'] ?? ''); ?>
                                <?php $siteLabel = trim((string) (($location['code'] ?? '') . ' - ' . ($location['titre'] ?? ''))); ?>
                                <option value="<?= htmlspecialchars($siteId, ENT_QUOTES, 'UTF-8') ?>" <?= $formData['chantier_id'] === $siteId ? 'selected' : '' ?>><?= htmlspecialchars($siteLabel, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="block md:col-span-2">
                        <span class="mb-2 block text-sm font-medium text-slate-600">Libellé emplacement</span>
                        <input type="text" name="location_label" value="<?= htmlspecialchars($formData['location_label'], ENT_QUOTES, 'UTF-8') ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 outline-none transition focus:border-orange-400" placeholder="Magasin central, base vie, zone nord chantier">
                    </label>

                    <label class="block">
                        <span class="mb-2 block text-sm font-medium text-slate-600">Numéro de série</span>
                        <input type="text" name="serial_number" value="<?= htmlspecialchars($formData['serial_number'], ENT_QUOTES, 'UTF-8') ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 outline-none transition focus:border-orange-400">
                    </label>

                    <label class="block">
                        <span class="mb-2 block text-sm font-medium text-slate-600">Date d'acquisition</span>
                        <input type="date" name="acquisition_date" value="<?= htmlspecialchars($formData['acquisition_date'], ENT_QUOTES, 'UTF-8') ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 outline-none transition focus:border-orange-400">
                    </label>

                    <label class="block md:col-span-2">
                        <span class="mb-2 block text-sm font-medium text-slate-600">Notes</span>
                        <textarea name="notes" rows="4" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 outline-none transition focus:border-orange-400" placeholder="Origine du stock, affectation initiale, commentaire logistique"><?= htmlspecialchars($formData['notes'], ENT_QUOTES, 'UTF-8') ?></textarea>
                    </label>

                    <div class="md:col-span-2 flex flex-wrap gap-3 pt-2">
                        <button type="submit" class="rounded-2xl bg-orange-600 px-6 py-3 text-sm font-semibold text-white shadow-lg shadow-orange-600/20 transition hover:bg-orange-700" <?= empty($setupStatus['inventory_ready']) ? 'disabled' : '' ?>>Enregistrer l'article</button>
                        <a href="enregistrer_stock.php" class="rounded-2xl border border-slate-200 bg-white px-6 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">Réinitialiser</a>
                    </div>
                </form>
            </article>

            <article class="rounded-[2rem] border border-white/60 bg-white/90 p-8 shadow-sm">
                <p class="text-xs uppercase tracking-[0.22em] text-slate-500">Mode opératoire</p>
                <h2 class="mt-2 text-2xl font-semibold text-slate-900">Comment enregistrer ton stock maintenant</h2>
                <div class="mt-6 space-y-4 text-sm leading-7 text-slate-600">
                    <div class="rounded-3xl border border-slate-200 bg-slate-50 px-4 py-4">1. Initialiser les tables inventaire si ce n'est pas déjà fait.</div>
                    <div class="rounded-3xl border border-slate-200 bg-slate-50 px-4 py-4">2. Créer chaque article ici avec son unité issue de la table unites_mesure, sa quantité initiale, son état et sa position.</div>
                    <div class="rounded-3xl border border-slate-200 bg-slate-50 px-4 py-4">3. L'article crée automatiquement une entrée initiale dans le journal des mouvements quand la table movements est disponible.</div>
                    <div class="rounded-3xl border border-slate-200 bg-slate-50 px-4 py-4">4. Pour l'instant, il n'y a pas encore d'écran séparé entrée/sortie/transfert ou réparation. Je peux l'ajouter juste après.</div>
                </div>

                <div class="mt-8 rounded-3xl border border-orange-100 bg-orange-50 px-5 py-5 text-sm text-slate-700">
                    <div class="font-semibold text-slate-900">État actuel du module</div>
                    <div class="mt-3">Assets: <?= !empty($setupStatus['assets_table_ready']) ? 'prête' : 'absente' ?></div>
                    <div>Repairs: <?= !empty($setupStatus['repairs_table_ready']) ? 'prête' : 'absente' ?></div>
                    <div>Movements: <?= !empty($setupStatus['movements_table_ready']) ? 'prête' : 'absente' ?></div>
                    <div>Référence chantier: <?= !empty($setupStatus['chantier_reference_ready']) ? 'prête' : 'différée' ?></div>
                    <div>Référence unité: <?= !empty($setupStatus['unit_reference_ready']) ? 'prête' : 'différée' ?></div>
                </div>
            </article>
        </section>
    </div>
</div>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const assetCodeField = document.getElementById('assetCodeField');
        const assetCodeModeField = document.getElementById('assetCodeModeField');
        const assetCodeAutoButton = document.getElementById('assetCodeAutoButton');
        const assetTypeField = document.getElementById('assetTypeField');
        const assetCategoryField = document.getElementById('assetCategoryField');
        const assetDesignationField = document.getElementById('assetDesignationField');

        if (!assetCodeField || !assetCodeModeField || !assetCodeAutoButton || !assetTypeField || !assetCategoryField || !assetDesignationField) {
            return;
        }

        function normalizeFragment(value) {
            return (value || '')
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '')
                .toUpperCase()
                .replace(/[^A-Z0-9 ]+/g, '');
        }

        function buildPrefix() {
            const source = assetDesignationField.value.trim() || assetCategoryField.value.trim() || assetTypeField.value.trim();
            const sourceLetters = normalizeFragment(source).replace(/[^A-Z]/g, '');
            const fallbackLetters = normalizeFragment(assetTypeField.value.trim() || 'materiel').replace(/[^A-Z]/g, '');
            const merged = (sourceLetters + fallbackLetters + 'XXX').slice(0, 3);
            return merged;
        }

        function buildAutoCode() {
            return buildPrefix() + '-001';
        }

        function applyAutoCode(forceMode) {
            if (forceMode) {
                assetCodeModeField.value = 'auto';
            }

            if (assetCodeModeField.value === 'auto') {
                assetCodeField.value = buildAutoCode();
            }
        }

        [assetTypeField, assetCategoryField, assetDesignationField].forEach(function(field) {
            field.addEventListener('input', function() {
                applyAutoCode(false);
            });
            field.addEventListener('change', function() {
                applyAutoCode(false);
            });
        });

        assetCodeField.addEventListener('input', function() {
            if (assetCodeField.value.trim() === '' || assetCodeField.value === buildAutoCode()) {
                assetCodeModeField.value = 'auto';
                return;
            }

            assetCodeModeField.value = 'manual';
        });

        assetCodeAutoButton.addEventListener('click', function() {
            applyAutoCode(true);
        });
    });
</script>
<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>
