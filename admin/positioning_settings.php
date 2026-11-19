<?php

require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/PositioningSettingsManager.php';
require_once __DIR__ . '/../model/EntityPositioningManager.php';
require_once __DIR__ . '/../repositories/FournisseurRepository.php';
require_once __DIR__ . '/../model/AuditTrail.php';

$pageTitle = 'Pilotage GPS fournisseurs';
$activeMenu = 'admin';
$page = 'admin';

$pdo = \Database::getConnection();
$settingsManager = new \PositioningSettingsManager($pdo);
$positioningManager = new \EntityPositioningManager($pdo);
$supplierModel = new \FournisseurRepository($pdo);
$currentUserId = (int) ($_SESSION['user_id'] ?? 0);

$flash = null;
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? 'save_settings'));

    if ($action === 'save_settings') {
        $settingsManager->save('supplier', [
            'api_enabled' => !empty($_POST['api_enabled']),
            'realtime_enabled' => !empty($_POST['realtime_enabled']),
            'geocoding_enabled' => !empty($_POST['geocoding_enabled']),
            'allow_manual_override' => !empty($_POST['allow_manual_override']),
            'provider_key' => 'nominatim',
            'tile_provider' => trim((string) ($_POST['tile_provider'] ?? 'openstreetmap')),
            'tile_url' => trim((string) ($_POST['tile_url'] ?? '')),
            'tile_attribution' => trim((string) ($_POST['tile_attribution'] ?? '')),
            'poll_interval_seconds' => (int) ($_POST['poll_interval_seconds'] ?? 30),
            'map_center_lat' => $_POST['map_center_lat'] ?? null,
            'map_center_lng' => $_POST['map_center_lng'] ?? null,
            'map_zoom' => (int) ($_POST['map_zoom'] ?? 6),
        ], $currentUserId);

        \AuditTrail::log('positioning_settings_updated', [
            'module' => 'admin',
            'action' => 'positioning_settings',
            'entity_type' => 'supplier_positioning',
            'entity_label' => 'supplier',
            'result' => 'success',
        ], $pdo);

        $flash = 'Paramètres GPS enregistrés.';
    } elseif ($action === 'batch_geocode') {
        $settings = $settingsManager->get('supplier');
        $suppliers = $supplierModel->listLatest(500, false);
        $updated = 0;
        if (!empty($settings['api_enabled']) && !empty($settings['geocoding_enabled'])) {
            foreach ($suppliers as $supplier) {
                $addressParts = array_filter([
                    trim((string) ($supplier['adresse'] ?? '')),
                    trim((string) ($supplier['ville'] ?? '')),
                    trim((string) ($supplier['pays'] ?? '')),
                ], static fn(string $value): bool => $value !== '');
                if (!$addressParts) {
                    continue;
                }

                $position = $positioningManager->geocodeAddress(implode(', ', $addressParts));
                if (!$position) {
                    continue;
                }

                $positioningManager->upsertPosition('supplier', (int) ($supplier['id_fournisseur'] ?? 0), [
                    'location_label' => (string) ($position['location_label'] ?? ($supplier['supplier_display_name'] ?? 'Fournisseur')),
                    'address_snapshot' => implode(', ', $addressParts),
                    'latitude' => $position['latitude'] ?? null,
                    'longitude' => $position['longitude'] ?? null,
                    'source' => 'geocoded',
                ], $currentUserId);
                $updated++;
            }
        }

        $flash = $updated > 0
            ? $updated . ' fournisseur(s) géocodé(s) avec succès.'
            : 'Aucun fournisseur n\'a pu être géocodé avec les adresses disponibles.';
    }
}

$settings = $settingsManager->get('supplier');
$positions = $positioningManager->listPositions('supplier');
$allSuppliers = $supplierModel->listLatest(500, false);
$localizedCount = 0;
$completeDocsCount = 0;
foreach ($allSuppliers as $supplier) {
    if (isset($positions[(int) ($supplier['id_fournisseur'] ?? 0)])) {
        $localizedCount++;
    }
    if (!empty($supplier['document_is_complete'])) {
        $completeDocsCount++;
    }
}

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>

<div class="min-h-screen bg-[radial-gradient(circle_at_top_left,_rgba(224,231,255,0.92),_transparent_42%),radial-gradient(circle_at_bottom_right,_rgba(191,219,254,0.7),_transparent_38%),linear-gradient(180deg,_#f8fafc_0%,_#eef2ff_100%)] py-8">
    <div class="mx-auto max-w-7xl px-4">
        <section class="rounded-[32px] border border-slate-200 bg-white/90 p-6 shadow-[0_30px_80px_rgba(15,23,42,0.08)]">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.28em] text-indigo-600">Administration GPS</p>
                    <h1 class="mt-2 text-3xl font-semibold text-slate-950">Pilotage du positionnement fournisseur</h1>
                    <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">Stack gratuit et réutilisable: Leaflet pour l'affichage, OpenStreetMap pour les tuiles et Nominatim pour le géocodage. Ces réglages serviront aussi pour d'autres entités si vous étendez le module ensuite.</p>
                </div>
                <div class="flex flex-wrap gap-3">
                    <a href="../fournisseur/fournisseurs.php" class="inline-flex items-center rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                        <i class="fas fa-arrow-left mr-2"></i> Retour fournisseurs
                    </a>
                </div>
            </div>

            <?php if ($flash): ?>
                <div class="mt-5 rounded-2xl border px-4 py-3 text-sm <?= $flashType === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-rose-200 bg-rose-50 text-rose-800' ?>">
                    <?= htmlspecialchars($flash) ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="mt-6 grid gap-4 md:grid-cols-4">
            <article class="rounded-3xl border border-slate-200 bg-white/90 p-5 shadow-sm">
                <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">API GPS</div>
                <div class="mt-2 text-2xl font-semibold text-slate-950"><?= !empty($settings['api_enabled']) ? 'Activée' : 'Désactivée' ?></div>
            </article>
            <article class="rounded-3xl border border-slate-200 bg-white/90 p-5 shadow-sm">
                <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Temps réel</div>
                <div class="mt-2 text-2xl font-semibold text-slate-950"><?= !empty($settings['realtime_enabled']) ? 'Oui' : 'Non' ?></div>
            </article>
            <article class="rounded-3xl border border-slate-200 bg-white/90 p-5 shadow-sm">
                <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Fournisseurs localisés</div>
                <div class="mt-2 text-2xl font-semibold text-slate-950"><?= $localizedCount ?> / <?= count($allSuppliers) ?></div>
            </article>
            <article class="rounded-3xl border border-slate-200 bg-white/90 p-5 shadow-sm">
                <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Dossiers complets</div>
                <div class="mt-2 text-2xl font-semibold text-slate-950"><?= $completeDocsCount ?> / <?= count($allSuppliers) ?></div>
            </article>
        </section>

        <section class="mt-6 grid gap-6 xl:grid-cols-[1.1fr_0.9fr]">
            <form method="post" class="rounded-[32px] border border-slate-200 bg-white/90 p-6 shadow-sm">
                <input type="hidden" name="action" value="save_settings">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.28em] text-indigo-600">Configuration</p>
                        <h2 class="mt-2 text-xl font-semibold text-slate-950">Réglages de l'API de positionnement</h2>
                    </div>
                    <button class="rounded-2xl bg-slate-950 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">Enregistrer</button>
                </div>

                <div class="mt-5 grid gap-4 md:grid-cols-2">
                    <label class="rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700">
                        <span class="flex items-center justify-between gap-3">
                            <span class="font-semibold text-slate-900">Activer l'API GPS</span>
                            <input type="checkbox" name="api_enabled" value="1" <?= !empty($settings['api_enabled']) ? 'checked' : '' ?> class="rounded border-slate-300">
                        </span>
                        <span class="mt-2 block text-xs text-slate-500">Coupe totalement la lecture et la mise à jour des positions.</span>
                    </label>
                    <label class="rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700">
                        <span class="flex items-center justify-between gap-3">
                            <span class="font-semibold text-slate-900">Activer le temps réel</span>
                            <input type="checkbox" name="realtime_enabled" value="1" <?= !empty($settings['realtime_enabled']) ? 'checked' : '' ?> class="rounded border-slate-300">
                        </span>
                        <span class="mt-2 block text-xs text-slate-500">Autorise le polling périodique de la carte et les rafraîchissements automatiques.</span>
                    </label>
                    <label class="rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700">
                        <span class="flex items-center justify-between gap-3">
                            <span class="font-semibold text-slate-900">Activer le géocodage Nominatim</span>
                            <input type="checkbox" name="geocoding_enabled" value="1" <?= !empty($settings['geocoding_enabled']) ? 'checked' : '' ?> class="rounded border-slate-300">
                        </span>
                        <span class="mt-2 block text-xs text-slate-500">Convertit les adresses en latitude/longitude à l'enregistrement et lors du batch.</span>
                    </label>
                    <label class="rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700">
                        <span class="flex items-center justify-between gap-3">
                            <span class="font-semibold text-slate-900">Autoriser la position manuelle / navigateur</span>
                            <input type="checkbox" name="allow_manual_override" value="1" <?= !empty($settings['allow_manual_override']) ? 'checked' : '' ?> class="rounded border-slate-300">
                        </span>
                        <span class="mt-2 block text-xs text-slate-500">Permet de pousser une position actuelle depuis l'interface avec le GPS du navigateur.</span>
                    </label>
                </div>

                <div class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700">Provider tuiles</label>
                        <input name="tile_provider" value="<?= htmlspecialchars((string) ($settings['tile_provider'] ?? 'openstreetmap')) ?>" class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-800">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700">Polling temps réel (sec)</label>
                        <input type="number" min="5" max="300" name="poll_interval_seconds" value="<?= (int) ($settings['poll_interval_seconds'] ?? 30) ?>" class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-800">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700">Centre latitude</label>
                        <input name="map_center_lat" value="<?= htmlspecialchars((string) ($settings['map_center_lat'] ?? '5.348')) ?>" class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-800">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700">Centre longitude</label>
                        <input name="map_center_lng" value="<?= htmlspecialchars((string) ($settings['map_center_lng'] ?? '-4.03')) ?>" class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-800">
                    </div>
                    <div class="md:col-span-2 xl:col-span-3">
                        <label class="block text-sm font-medium text-slate-700">URL des tuiles</label>
                        <input name="tile_url" value="<?= htmlspecialchars((string) ($settings['tile_url'] ?? '')) ?>" class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-800">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700">Zoom initial</label>
                        <input type="number" min="2" max="18" name="map_zoom" value="<?= (int) ($settings['map_zoom'] ?? 6) ?>" class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-800">
                    </div>
                    <div class="md:col-span-2 xl:col-span-4">
                        <label class="block text-sm font-medium text-slate-700">Attribution</label>
                        <input name="tile_attribution" value="<?= htmlspecialchars((string) ($settings['tile_attribution'] ?? '')) ?>" class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-800">
                    </div>
                </div>
            </form>

            <section class="rounded-[32px] border border-slate-200 bg-white/90 p-6 shadow-sm">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.28em] text-indigo-600">Maintenance</p>
                        <h2 class="mt-2 text-xl font-semibold text-slate-950">Peupler la carte existante</h2>
                    </div>
                </div>
                <div class="mt-4 rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">
                    Le batch appelle Nominatim uniquement pour les fournisseurs ayant une adresse exploitable. Utilisez-le après activation du géocodage ou après import massif.
                </div>
                <form method="post" class="mt-5">
                    <input type="hidden" name="action" value="batch_geocode">
                    <button class="rounded-2xl bg-indigo-600 px-4 py-3 text-sm font-semibold text-white hover:bg-indigo-700">
                        <i class="fas fa-location-dot mr-2"></i> Géocoder les fournisseurs existants
                    </button>
                </form>

                <div class="mt-6 grid gap-3">
                    <div class="rounded-2xl border border-slate-200 bg-white px-4 py-4">
                        <div class="text-sm font-semibold text-slate-900">Stack gratuite retenue</div>
                        <div class="mt-2 text-sm text-slate-600">Leaflet + OpenStreetMap + Nominatim, sans coût d'entrée et facilement extensible aux chantiers, bureaux et engins.</div>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-white px-4 py-4">
                        <div class="text-sm font-semibold text-slate-900">Mode temps réel</div>
                        <div class="mt-2 text-sm text-slate-600">Les listings refetchent les positions selon l'intervalle défini. Les mises à jour navigateur sont soumises via une API interne sécurisée.</div>
                    </div>
                </div>
            </section>
        </section>

        <section class="mt-6 rounded-[32px] border border-slate-200 bg-white/90 p-6 shadow-sm">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.28em] text-indigo-600">Test API</p>
                    <h2 class="mt-2 text-xl font-semibold text-slate-950">Banc d'essai géolocalisation</h2>
                    <p class="mt-1 text-sm text-slate-600">Vérifiez en direct que l'autocomplete, le retour de localisation et l'ajustement du point fonctionnent bien.</p>
                </div>
                <div class="text-sm text-slate-500">Recherche quartier, bâtiment, rue ou point connu.</div>
            </div>

            <form class="mt-5 rounded-3xl border border-slate-200 bg-slate-50 p-5" onsubmit="return false;">
                <div class="grid gap-5 xl:grid-cols-[0.95fr_1.05fr]" data-supplier-location-root>
                    <div>
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <div class="text-sm font-semibold text-slate-900">Recherche test</div>
                                <div class="mt-1 text-xs text-slate-500">Ex: Cocody Riviera, Palmeraie, Deux Plateaux Vallons, immeuble CGRAE.</div>
                            </div>
                            <button type="button" data-location-current class="inline-flex items-center rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                                <i class="fas fa-location-crosshairs mr-2 text-indigo-600"></i>
                                Position actuelle
                            </button>
                        </div>
                        <div class="mt-4">
                            <label class="block text-sm font-medium text-slate-700">Recherche / autocomplétion</label>
                            <input type="text" data-location-search autocomplete="off" spellcheck="false" class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-800" placeholder="Ex: Cocody Riviera Golf, Abidjan">
                        </div>
                        <div class="mt-3 space-y-2" data-location-results></div>
                        <div class="mt-4 rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700">
                            <div class="flex items-center justify-between gap-3">
                                <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Sélection confirmée</div>
                                <span data-location-source-badge class="inline-flex rounded-full border border-slate-200 bg-white px-2.5 py-1 text-[11px] font-semibold text-slate-500">Aucune source</span>
                            </div>
                            <div class="mt-2 font-medium text-slate-900" data-location-selection-label>Aucune localisation confirmée.</div>
                        </div>
                        <div class="mt-4 grid gap-3 md:grid-cols-3">
                            <div>
                                <label class="block text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Adresse retenue</label>
                                <textarea name="adresse" rows="3" class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700" readonly></textarea>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Ville</label>
                                <input name="ville" class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700" readonly>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Pays</label>
                                <input name="pays" class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700" readonly>
                            </div>
                        </div>
                        <input type="hidden" name="location_latitude">
                        <input type="hidden" name="location_longitude">
                        <input type="hidden" name="location_source">
                        <input type="hidden" name="location_label">
                    </div>
                    <div>
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <div class="text-sm font-semibold text-slate-900">Carte de test</div>
                                <div class="mt-1 text-xs text-slate-500">Vous pouvez cliquer ou déplacer le marker pour viser un bâtiment précis.</div>
                            </div>
                            <div class="flex items-center gap-2">
                                <button type="button" data-location-recenter class="inline-flex items-center rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">Recentrer</button>
                                <button type="button" data-location-clear class="inline-flex items-center rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">Réinitialiser</button>
                            </div>
                        </div>
                        <div data-location-map class="mt-4 h-[360px] overflow-hidden rounded-3xl border border-slate-200 bg-slate-100"></div>
                        <div data-location-coordinates class="mt-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-xs font-medium text-slate-700">Coordonnées non fixées</div>
                        <div data-location-status class="mt-3 rounded-2xl border border-slate-200 bg-white px-4 py-3 text-xs text-slate-600">Lancez une recherche pour valider la réponse de l'API.</div>
                    </div>
                </div>
            </form>
        </section>
    </div>
</div>

<script src="../js/supplier-location-picker.js"></script>
<script>
    window.initSupplierLocationPicker({
        rootSelector: '[data-supplier-location-root]',
        apiUrl: '../api/location_lookup.php',
        settings: <?= json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        initialAddress: '',
        initialPosition: null
    });
</script>

<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>