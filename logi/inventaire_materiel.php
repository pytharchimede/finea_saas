<?php
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/LogisticsInventory.php';
require_once __DIR__ . '/../model/AuditTrail.php';

\AccessManager::requirePageAccess();

$pageTitle = 'Inventaire matériel';
$activeMenu = 'logistique';

$filters = [
    'search' => trim((string) ($_GET['search'] ?? '')),
    'status' => trim((string) ($_GET['status'] ?? '')),
    'location_scope' => trim((string) ($_GET['location_scope'] ?? '')),
    'site_code' => trim((string) ($_GET['site_code'] ?? '')),
    'category' => trim((string) ($_GET['category'] ?? '')),
    'repair_status' => trim((string) ($_GET['repair_status'] ?? '')),
];

$inventory = new \LogisticsInventory(\Database::getConnection());
$setupStatus = $inventory->getSetupStatus();
$setupResult = null;

if (empty($_SESSION['logistics_inventory_setup_token'])) {
    $_SESSION['logistics_inventory_setup_token'] = bin2hex(random_bytes(24));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'run_inventory_setup') {
    $submittedToken = (string) ($_POST['setup_token'] ?? '');
    if (!hash_equals((string) $_SESSION['logistics_inventory_setup_token'], $submittedToken)) {
        $setupResult = [
            'success' => false,
            'message' => 'La demande d\'initialisation est invalide. Rechargez la page puis réessayez.',
            'warnings' => [],
            'setup_status' => $setupStatus,
        ];
        \AuditTrail::log('logistics_inventory_setup_failed', [
            'module' => 'logi',
            'action' => 'inventaire_materiel_setup',
            'entity_type' => 'logistics_inventory_schema',
            'entity_label' => 'inventaire_materiel',
            'result' => 'failure',
            'severity' => 'warning',
            'details' => ['reason' => 'invalid_setup_token'],
        ], null);
    } else {
        $setupResult = $inventory->installSchemaFromFile(__DIR__ . '/../Database/add_logistics_inventory_tracking.sql');
        $setupStatus = $setupResult['setup_status'] ?? $inventory->getSetupStatus();
        \AuditTrail::log(!empty($setupResult['success']) ? 'logistics_inventory_setup_completed' : 'logistics_inventory_setup_failed', [
            'module' => 'logi',
            'action' => 'inventaire_materiel_setup',
            'entity_type' => 'logistics_inventory_schema',
            'entity_label' => 'inventaire_materiel',
            'result' => !empty($setupResult['success']) ? 'success' : 'failure',
            'severity' => !empty($setupResult['success']) ? 'info' : 'warning',
            'details' => [
                'message' => $setupResult['message'] ?? null,
                'warning_count' => is_countable($setupResult['warnings'] ?? null) ? count($setupResult['warnings']) : 0,
            ],
        ], null);
    }
}

$inventoryData = $inventory->getInventoryData($filters);

$summary = $inventoryData['summary'] ?? [];
$assets = $inventoryData['assets'] ?? [];
$repairs = $inventoryData['repairs'] ?? [];
$movements = $inventoryData['movements'] ?? [];
$categories = $inventoryData['categories'] ?? [];
$locations = $inventoryData['locations'] ?? [];
$fallbackConsumption = $inventoryData['fallback_consumption'] ?? [];
$fallbackMaterialRequests = $inventoryData['fallback_material_requests'] ?? [];
$isConfigured = !empty($inventoryData['configured']);
$setupToken = (string) $_SESSION['logistics_inventory_setup_token'];

if (!function_exists('logisticsInventoryFormatMoney')) {
    function logisticsInventoryFormatMoney($value): string
    {
        return number_format((float) $value, 0, ',', ' ') . ' F';
    }
}

if (!function_exists('logisticsInventoryFormatNumber')) {
    function logisticsInventoryFormatNumber($value, int $decimals = 0): string
    {
        return number_format((float) $value, $decimals, ',', ' ');
    }
}

$statusOptions = [
    '' => 'Tous les états',
    'disponible' => 'Disponible',
    'affecte' => 'Affecté',
    'maintenance' => 'Maintenance',
    'reserve' => 'Réservé',
    'rupture' => 'Rupture',
    'hors_service' => 'Hors service',
];

$scopeOptions = [
    '' => 'Toutes les positions',
    'chantier' => 'Chantier',
    'bureau' => 'Bureau',
    'depot' => 'Dépôt',
    'atelier' => 'Atelier',
    'transit' => 'Transit',
];

$repairStatusOptions = [
    '' => 'Toutes les réparations',
    'ouverte' => 'Ouverte',
    'en_cours' => 'En cours',
    'terminee' => 'Terminée',
    'annulee' => 'Annulée',
];

\AuditTrail::logPageConsultation([
    'module' => 'logi',
    'action' => 'inventaire_materiel_view',
    'entity_type' => 'logistics_inventory',
    'entity_label' => 'inventaire_materiel',
    'details' => [
        'configured' => $isConfigured,
        'asset_count' => count($assets),
        'repair_count' => count($repairs),
        'movement_count' => count($movements),
        'search' => $filters['search'] !== '' ? $filters['search'] : null,
        'status' => $filters['status'] !== '' ? $filters['status'] : null,
        'location_scope' => $filters['location_scope'] !== '' ? $filters['location_scope'] : null,
        'site_code' => $filters['site_code'] !== '' ? $filters['site_code'] : null,
        'category' => $filters['category'] !== '' ? $filters['category'] : null,
    ],
], null);

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>
<div class="min-h-screen bg-[radial-gradient(circle_at_top,_#fef3c7,_#fff_40%,_#fff7ed_100%)] px-6 pb-14">
    <div class="max-w-7xl mx-auto">
        <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-6 mb-10">
            <div>
                <p class="text-xs uppercase tracking-[0.25em] text-orange-600 font-semibold mb-3">Logistique terrain</p>
                <h1 class="text-4xl md:text-5xl font-semibold text-slate-900">Inventaire matériel et stock</h1>
                <p class="mt-4 max-w-3xl text-slate-600">
                    Suivi de la position du matériel, de sa disponibilité, des réparations ouvertes et des consommations, avec distinction chantier, bureau, dépôt et atelier.
                </p>
            </div>
            <div class="flex flex-wrap gap-3">
                <a href="enregistrer_stock.php" class="px-5 py-3 rounded-2xl bg-orange-600 text-white font-semibold shadow-sm hover:bg-orange-700 transition">Enregistrer du stock</a>
                <a href="dashboard_logistique.php" class="px-5 py-3 rounded-2xl border border-slate-200 bg-white text-slate-700 font-semibold shadow-sm hover:shadow-md transition">Retour dashboard</a>
                <a href="../selection_portail.php" class="px-5 py-3 rounded-2xl bg-slate-900 text-white font-semibold shadow-sm hover:bg-slate-800 transition">Portail</a>
            </div>
        </div>

        <?php if (!$isConfigured || $setupResult !== null): ?>
            <section class="rounded-[2rem] border border-amber-200 bg-white/90 p-8 shadow-sm mb-8">
                <div class="flex flex-col xl:flex-row xl:items-start xl:justify-between gap-6">
                    <div>
                        <p class="text-xs uppercase tracking-[0.22em] text-amber-600 font-semibold">Initialisation requise</p>
                        <h2 class="mt-2 text-2xl font-semibold text-slate-900">Le moteur d'inventaire temps réel est prêt, mais les tables ne sont pas encore déployées</h2>
                        <p class="mt-4 max-w-3xl text-slate-600 leading-7">
                            Appliquez le script SQL prévu pour activer le suivi détaillé des matériels, stocks, réparations et mouvements. En attendant, cette page exploite les fiches de décaissement existantes pour fournir une lecture partielle des consommations matériel et matériaux.
                        </p>
                        <div class="mt-5 flex flex-wrap gap-3 text-xs font-semibold">
                            <span class="rounded-full px-3 py-2 <?= !empty($setupStatus['assets_table_ready']) ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-500' ?>">Assets <?= !empty($setupStatus['assets_table_ready']) ? 'prête' : 'absente' ?></span>
                            <span class="rounded-full px-3 py-2 <?= !empty($setupStatus['repairs_table_ready']) ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-500' ?>">Repairs <?= !empty($setupStatus['repairs_table_ready']) ? 'prête' : 'absente' ?></span>
                            <span class="rounded-full px-3 py-2 <?= !empty($setupStatus['movements_table_ready']) ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-500' ?>">Movements <?= !empty($setupStatus['movements_table_ready']) ? 'prête' : 'absente' ?></span>
                            <span class="rounded-full px-3 py-2 <?= !empty($setupStatus['chantier_reference_ready']) ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' ?>">Référence chantier <?= !empty($setupStatus['chantier_reference_ready']) ? 'prête' : 'différée' ?></span>
                            <span class="rounded-full px-3 py-2 <?= !empty($setupStatus['unit_reference_ready']) ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' ?>">Référence unité <?= !empty($setupStatus['unit_reference_ready']) ? 'prête' : 'différée' ?></span>
                        </div>
                    </div>
                    <div class="rounded-3xl border border-slate-200 bg-slate-50 px-5 py-5 text-sm text-slate-600 min-w-[280px]">
                        <div class="font-semibold text-slate-900">Script à exécuter</div>
                        <div class="mt-2">Database/add_logistics_inventory_tracking.sql</div>
                        <div class="mt-4 text-slate-500">Ce script crée:</div>
                        <div class="mt-2">logistics_assets, logistics_asset_repairs, logistics_asset_movements</div>
                        <div class="mt-4 text-slate-500">Pré-requis chantier:</div>
                        <div class="mt-2">
                            <?= !empty($setupStatus['chantier_exists']) ? 'chantier_banamur détectée' : 'chantier_banamur absente' ?>
                            <?php if (!empty($setupStatus['chantier_engine'])): ?>
                                · moteur <?= htmlspecialchars((string) $setupStatus['chantier_engine'], ENT_QUOTES, 'UTF-8') ?>
                            <?php endif; ?>
                        </div>
                        <form method="post" class="mt-5">
                            <input type="hidden" name="action" value="run_inventory_setup">
                            <input type="hidden" name="setup_token" value="<?= htmlspecialchars($setupToken, ENT_QUOTES, 'UTF-8') ?>">
                            <button type="submit" class="w-full rounded-2xl bg-amber-500 px-4 py-3 text-sm font-semibold text-white shadow-lg shadow-amber-500/20 transition hover:bg-amber-600">Exécuter l'initialisation</button>
                        </form>
                        <div class="mt-3 text-xs text-slate-500">Le script peut être relancé. Les tables manquantes seront créées et les clés étrangères chantier seront ajoutées plus tard si nécessaire.</div>
                    </div>
                </div>

                <?php if ($setupResult !== null): ?>
                    <div class="mt-6 rounded-3xl border px-5 py-4 <?= !empty($setupResult['success']) ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-rose-200 bg-rose-50 text-rose-800' ?>">
                        <div class="font-semibold"><?= htmlspecialchars((string) ($setupResult['message'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                        <?php foreach (($setupResult['warnings'] ?? []) as $warning): ?>
                            <div class="mt-2 text-sm"><?= htmlspecialchars((string) $warning, ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <section class="rounded-[2rem] border border-white/60 bg-white/90 p-6 shadow-sm mb-8">
            <form method="get" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-6 gap-4">
                <label class="block">
                    <span class="mb-2 block text-sm font-medium text-slate-600">Recherche</span>
                    <input type="text" name="search" value="<?= htmlspecialchars($filters['search'], ENT_QUOTES, 'UTF-8') ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 outline-none transition focus:border-orange-400" placeholder="Code, désignation, série">
                </label>
                <label class="block">
                    <span class="mb-2 block text-sm font-medium text-slate-600">Disponibilité</span>
                    <select name="status" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 outline-none transition focus:border-orange-400">
                        <?php foreach ($statusOptions as $value => $label): ?>
                            <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>" <?= $filters['status'] === $value ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="block">
                    <span class="mb-2 block text-sm font-medium text-slate-600">Position</span>
                    <select name="location_scope" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 outline-none transition focus:border-orange-400">
                        <?php foreach ($scopeOptions as $value => $label): ?>
                            <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>" <?= $filters['location_scope'] === $value ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="block">
                    <span class="mb-2 block text-sm font-medium text-slate-600">Chantier</span>
                    <select name="site_code" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 outline-none transition focus:border-orange-400">
                        <option value="">Tous les chantiers</option>
                        <?php foreach ($locations as $location): ?>
                            <?php $code = (string) ($location['code'] ?? ''); ?>
                            <option value="<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>" <?= $filters['site_code'] === $code ? 'selected' : '' ?>><?= htmlspecialchars($code . ' - ' . ($location['titre'] ?? ''), ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="block">
                    <span class="mb-2 block text-sm font-medium text-slate-600">Catégorie</span>
                    <select name="category" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 outline-none transition focus:border-orange-400">
                        <option value="">Toutes les catégories</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= htmlspecialchars((string) $category, ENT_QUOTES, 'UTF-8') ?>" <?= $filters['category'] === (string) $category ? 'selected' : '' ?>><?= htmlspecialchars((string) $category, ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div class="flex items-end gap-3">
                    <button type="submit" class="w-full rounded-2xl bg-orange-600 px-4 py-3 text-sm font-semibold text-white shadow-lg shadow-orange-600/15 transition hover:bg-orange-700">Filtrer</button>
                    <a href="inventaire_materiel.php" class="rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">Reset</a>
                </div>
            </form>
        </section>

        <section class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-5 gap-5 mb-8">
            <article class="rounded-3xl border border-white/60 bg-white/90 p-6 shadow-sm">
                <div class="text-xs uppercase tracking-[0.22em] text-slate-500">Articles suivis</div>
                <div class="mt-3 text-3xl font-semibold text-slate-900"><?= logisticsInventoryFormatNumber($summary['assets_total'] ?? 0) ?></div>
                <div class="mt-2 text-sm text-slate-500">Inventaire ou base de consommation</div>
            </article>
            <article class="rounded-3xl border border-emerald-100 bg-emerald-50/80 p-6 shadow-sm">
                <div class="text-xs uppercase tracking-[0.22em] text-emerald-700">Disponibles</div>
                <div class="mt-3 text-3xl font-semibold text-slate-900"><?= logisticsInventoryFormatNumber($summary['available_assets_total'] ?? 0) ?></div>
                <div class="mt-2 text-sm text-slate-500">Mobilisables immédiatement</div>
            </article>
            <article class="rounded-3xl border border-rose-100 bg-rose-50/80 p-6 shadow-sm">
                <div class="text-xs uppercase tracking-[0.22em] text-rose-700">Indisponibles</div>
                <div class="mt-3 text-3xl font-semibold text-slate-900"><?= logisticsInventoryFormatNumber($summary['unavailable_assets_total'] ?? 0) ?></div>
                <div class="mt-2 text-sm text-slate-500">Affectés, ruptures ou hors service</div>
            </article>
            <article class="rounded-3xl border border-amber-100 bg-amber-50/80 p-6 shadow-sm">
                <div class="text-xs uppercase tracking-[0.22em] text-amber-700">Maintenance</div>
                <div class="mt-3 text-3xl font-semibold text-slate-900"><?= logisticsInventoryFormatNumber($summary['maintenance_assets_total'] ?? 0) ?></div>
                <div class="mt-2 text-sm text-slate-500"><?= logisticsInventoryFormatNumber($summary['repair_open_total'] ?? 0) ?> réparation(s) active(s)</div>
            </article>
            <article class="rounded-3xl border border-violet-100 bg-violet-50/80 p-6 shadow-sm">
                <div class="text-xs uppercase tracking-[0.22em] text-violet-700">Seuil critique</div>
                <div class="mt-3 text-3xl font-semibold text-slate-900"><?= logisticsInventoryFormatNumber($summary['critical_stock_total'] ?? 0) ?></div>
                <div class="mt-2 text-sm text-slate-500">Articles sous seuil ou tension</div>
            </article>
        </section>

        <section class="grid grid-cols-1 xl:grid-cols-[1.2fr,0.8fr] gap-6 mb-8">
            <article class="rounded-[1.75rem] border border-white/60 bg-white/90 p-6 shadow-sm">
                <div class="flex items-center justify-between gap-4 mb-6">
                    <div>
                        <p class="text-xs uppercase tracking-[0.22em] text-slate-500">Position et disponibilité</p>
                        <h2 class="mt-2 text-2xl font-semibold text-slate-900">Inventaire détaillé</h2>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-200 text-left text-slate-500 uppercase tracking-[0.18em] text-[11px]">
                                <th class="px-3 py-3">Code</th>
                                <th class="px-3 py-3">Désignation</th>
                                <th class="px-3 py-3">Position</th>
                                <th class="px-3 py-3">État</th>
                                <th class="px-3 py-3 text-right">Disponible</th>
                                <th class="px-3 py-3 text-right">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($assets as $asset): ?>
                                <tr class="border-b border-slate-100 last:border-0">
                                    <td class="px-3 py-4 font-semibold text-slate-900"><?= htmlspecialchars((string) ($asset['asset_code'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="px-3 py-4">
                                        <div class="font-medium text-slate-900"><?= htmlspecialchars((string) ($asset['designation'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                        <div class="mt-1 text-xs text-slate-500"><?= htmlspecialchars((string) ($asset['category'] ?? ''), ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars((string) ($asset['asset_type'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                    </td>
                                    <td class="px-3 py-4 text-slate-600">
                                        <?= htmlspecialchars((string) (($asset['chantier_code'] ?? '') ?: (($asset['location_label'] ?? '') ?: ucfirst((string) ($asset['location_scope'] ?? 'bureau')))), ENT_QUOTES, 'UTF-8') ?>
                                    </td>
                                    <td class="px-3 py-4 text-slate-600">
                                        <?= htmlspecialchars((string) ($asset['availability_status'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                        <?php if ((int) ($asset['open_repairs'] ?? 0) > 0): ?>
                                            <div class="mt-1 text-xs text-rose-500"><?= logisticsInventoryFormatNumber($asset['open_repairs'] ?? 0) ?> réparation(s)</div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-3 py-4 text-right font-semibold text-slate-900"><?= logisticsInventoryFormatNumber($asset['quantity_available'] ?? 0, 0) ?> <?= htmlspecialchars((string) ($asset['unit_symbol'] ?? 'u'), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="px-3 py-4 text-right text-slate-600"><?= logisticsInventoryFormatNumber($asset['quantity_total'] ?? 0, 0) ?> <?= htmlspecialchars((string) ($asset['unit_symbol'] ?? 'u'), ENT_QUOTES, 'UTF-8') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($assets)): ?>
                                <tr>
                                    <td colspan="6" class="px-3 py-8 text-center text-slate-400">
                                        <?= $isConfigured ? 'Aucun article ne correspond aux filtres sélectionnés.' : 'Aucun article d\'inventaire saisi pour le moment.' ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </article>

            <article class="rounded-[1.75rem] border border-white/60 bg-white/90 p-6 shadow-sm">
                <div class="flex items-center justify-between gap-4 mb-6">
                    <div>
                        <p class="text-xs uppercase tracking-[0.22em] text-slate-500">Maintenance</p>
                        <h2 class="mt-2 text-2xl font-semibold text-slate-900">Réparations ouvertes</h2>
                    </div>
                </div>
                <div class="space-y-3">
                    <?php foreach ($repairs as $repair): ?>
                        <div class="rounded-3xl border border-slate-200 bg-slate-50 px-4 py-4">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <div class="font-semibold text-slate-900"><?= htmlspecialchars((string) ($repair['asset_code'] ?? ''), ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars((string) ($repair['designation'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="mt-1 text-sm text-slate-600"><?= htmlspecialchars((string) ($repair['issue_label'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="mt-1 text-xs text-slate-400"><?= htmlspecialchars((string) (($repair['chantier_code'] ?? '') ?: 'Bureau'), ENT_QUOTES, 'UTF-8') ?></div>
                                </div>
                                <div class="text-right text-xs text-slate-500">
                                    <div class="font-semibold text-slate-700"><?= htmlspecialchars((string) ($repair['repair_status'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="mt-1"><?= !empty($repair['opened_at']) ? date('d/m/Y', strtotime((string) $repair['opened_at'])) : '' ?></div>
                                </div>
                            </div>
                            <?php if (!empty($repair['estimated_cost'])): ?>
                                <div class="mt-3 text-sm text-slate-500">Coût estimé: <?= logisticsInventoryFormatMoney($repair['estimated_cost']) ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($repairs)): ?>
                        <div class="rounded-3xl border border-dashed border-slate-200 bg-slate-50 px-5 py-8 text-center text-slate-400">Aucune réparation sur le filtre courant.</div>
                    <?php endif; ?>
                </div>
            </article>
        </section>

        <section class="grid grid-cols-1 xl:grid-cols-2 gap-6 mb-8">
            <article class="rounded-[1.75rem] border border-white/60 bg-white/90 p-6 shadow-sm">
                <div class="flex items-center justify-between gap-4 mb-6">
                    <div>
                        <p class="text-xs uppercase tracking-[0.22em] text-slate-500">Historique terrain</p>
                        <h2 class="mt-2 text-2xl font-semibold text-slate-900">Mouvements récents</h2>
                    </div>
                </div>
                <div class="space-y-3">
                    <?php foreach ($movements as $movement): ?>
                        <div class="rounded-3xl border border-slate-200 bg-slate-50 px-4 py-4">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <div class="font-semibold text-slate-900"><?= htmlspecialchars((string) ($movement['asset_code'] ?? ''), ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars((string) ($movement['designation'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="mt-1 text-sm text-slate-600"><?= htmlspecialchars((string) ($movement['movement_type'] ?? ''), ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars((string) (($movement['reference_number'] ?? '') ?: 'Sans référence'), ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="mt-1 text-xs text-slate-400"><?= htmlspecialchars((string) (($movement['from_label'] ?? '') ?: ($movement['from_scope'] ?? '')), ENT_QUOTES, 'UTF-8') ?> → <?= htmlspecialchars((string) (($movement['to_label'] ?? '') ?: ($movement['to_scope'] ?? '')), ENT_QUOTES, 'UTF-8') ?></div>
                                </div>
                                <div class="text-right text-xs text-slate-500">
                                    <div class="font-semibold text-slate-700"><?= logisticsInventoryFormatNumber($movement['quantity'] ?? 0, 0) ?></div>
                                    <div class="mt-1"><?= !empty($movement['performed_at']) ? date('d/m/Y H:i', strtotime((string) $movement['performed_at'])) : '' ?></div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($movements)): ?>
                        <div class="rounded-3xl border border-dashed border-slate-200 bg-slate-50 px-5 py-8 text-center text-slate-400">Aucun mouvement d'inventaire saisi pour le moment.</div>
                    <?php endif; ?>
                </div>
            </article>

            <article class="rounded-[1.75rem] border border-white/60 bg-white/90 p-6 shadow-sm">
                <div class="flex items-center justify-between gap-4 mb-6">
                    <div>
                        <p class="text-xs uppercase tracking-[0.22em] text-slate-500">Consommation existante</p>
                        <h2 class="mt-2 text-2xl font-semibold text-slate-900">Lecture issue des fiches actuelles</h2>
                    </div>
                </div>
                <div class="space-y-3">
                    <?php foreach ($fallbackConsumption as $row): ?>
                        <div class="rounded-3xl border border-slate-200 bg-slate-50 px-4 py-4">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <div class="font-semibold text-slate-900"><?= htmlspecialchars((string) ($row['chantier_code'] ?? ''), ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars((string) ($row['chantier_label'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="mt-1 text-sm text-slate-500"><?= logisticsInventoryFormatNumber($row['files_count'] ?? 0) ?> fiche(s) matériel/matériaux</div>
                                </div>
                                <div class="text-right text-sm font-semibold text-slate-900"><?= logisticsInventoryFormatMoney($row['amount_total'] ?? 0) ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($fallbackConsumption)): ?>
                        <div class="rounded-3xl border border-dashed border-slate-200 bg-slate-50 px-5 py-8 text-center text-slate-400">Aucune consommation matériel ou matériaux détectée dans les fiches existantes.</div>
                    <?php endif; ?>
                </div>
            </article>
        </section>

        <section class="rounded-[1.75rem] border border-white/60 bg-white/90 p-6 shadow-sm">
            <div class="flex items-center justify-between gap-4 mb-6">
                <div>
                    <p class="text-xs uppercase tracking-[0.22em] text-slate-500">Demandes récentes</p>
                    <h2 class="mt-2 text-2xl font-semibold text-slate-900">Consommations matériel/matériaux déjà tracées</h2>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 text-left text-slate-500 uppercase tracking-[0.18em] text-[11px]">
                            <th class="px-3 py-3">Fiche</th>
                            <th class="px-3 py-3">Chantier</th>
                            <th class="px-3 py-3">Désignation</th>
                            <th class="px-3 py-3">Catégorie</th>
                            <th class="px-3 py-3 text-right">Montant</th>
                            <th class="px-3 py-3">Workflow</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($fallbackMaterialRequests as $request): ?>
                            <?php
                            $workflowLabel = 'À approuver';
                            if ((int) ($request['decaisse'] ?? 0) === 1) {
                                $workflowLabel = 'Décaissée';
                            } elseif ((int) ($request['conforme'] ?? 0) === 1) {
                                $workflowLabel = 'Conforme';
                            } elseif ((int) ($request['approuve'] ?? 0) === 1) {
                                $workflowLabel = 'Approuvée';
                            }
                            ?>
                            <tr class="border-b border-slate-100 last:border-0">
                                <td class="px-3 py-4 font-semibold text-slate-900"><?= htmlspecialchars((string) ($request['num_fiche'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="px-3 py-4 text-slate-600"><?= htmlspecialchars((string) (($request['chantier_code'] ?? '') . ' ' . ($request['chantier_label'] ?? '')), ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="px-3 py-4 text-slate-700"><?= htmlspecialchars((string) ($request['designation'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="px-3 py-4 text-slate-500"><?= htmlspecialchars((string) ($request['categorie'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="px-3 py-4 text-right font-semibold text-slate-900"><?= logisticsInventoryFormatMoney($request['montant_fiche'] ?? 0) ?></td>
                                <td class="px-3 py-4 text-slate-600"><?= htmlspecialchars($workflowLabel, ENT_QUOTES, 'UTF-8') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($fallbackMaterialRequests)): ?>
                            <tr>
                                <td colspan="6" class="px-3 py-8 text-center text-slate-400">Aucune demande récente de matériel ou matériaux.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>
<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>