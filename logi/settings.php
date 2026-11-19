<?php
require_once __DIR__ . '/_logistics_common.php';

$fuelSettings = [
    'fuel_price_diesel' => ['label' => 'Gasoil', 'default' => '0'],
    'fuel_price_petrol' => ['label' => 'Essence', 'default' => '0'],
    'fuel_price_super' => ['label' => 'Super', 'default' => '0'],
    'fuel_price_lubricant' => ['label' => 'Huile / lubrifiant', 'default' => '0'],
];

$alertSettings = [
    'stock_critical_threshold' => ['label' => 'Stock minimum avant alerte', 'default' => '5', 'help' => 'Exemple : alerter quand une piece descend sous 5 unites.'],
    'fuel_overconsumption_percent' => ['label' => 'Tolerance surconsommation carburant (%)', 'default' => '20', 'help' => 'Au-dela de ce seuil, l anomalie carburant devient rouge.'],
    'document_alert_days' => ['label' => 'Alerte document avant expiration (jours)', 'default' => '30', 'help' => 'Assurance, visite technique, permis, licence.'],
    'maintenance_alert_days' => ['label' => 'Rappel maintenance avant echeance (jours)', 'default' => '7', 'help' => 'Delai pour preparer atelier, pieces et chauffeur.'],
    'immobilization_alert_hours' => ['label' => 'Alerte immobilisation prolongee (heures)', 'default' => '24', 'help' => 'Declenche une vigilance si un engin reste bloque trop longtemps.'],
];

$rolePresets = [
    'Responsable logistique',
    'Assistant logistique',
    'Dispatcher',
    'Gestionnaire carburant',
    'Gestionnaire stock',
    'Responsable maintenance',
    'Mecanicien',
    'Chauffeur',
    'Conducteur engin',
    'Validateur logistique',
    'Controleur documents',
    'Suivi chantier',
];

$stockCategoryPresets = [
    'Pieces detachees',
    'Pneumatiques',
    'Huiles et lubrifiants',
    'Filtres',
    'Batteries',
    'Consommables atelier',
    'Materiaux chantier',
    'EPI et securite',
    'Outillage',
    'Carburant',
    'Autres',
];

$stockLocationPresets = [
    'Magasin central',
    'Atelier mecanique',
    'Base vie',
    'Depot carburant',
    'Zone pieces detachees',
    'Zone pneumatiques',
    'Chantier',
    'Bureau logistique',
    'Conteneur stock',
    'Autres',
];

$planningBlockReasonPresets = [
    'Ressource indisponible',
    'Chauffeur indisponible',
    'Panne ou maintenance',
    'Carburant indisponible',
    'Acces chantier bloque',
    'Attente validation',
    'Meteo defavorable',
    'Debourse non confirme',
    'Autre',
];

$current = [];
foreach ($pdo->query('SELECT setting_key,setting_value FROM logistics_settings')->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $current[(string)$row['setting_key']] = (string)$row['setting_value'];
}

function logi_settings_list_from_raw(string $raw): array
{
    return array_values(array_unique(array_filter(array_map('trim', preg_split('/[\r\n,;]+/', $raw) ?: []))));
}

function logi_settings_used_values(PDO $pdo, string $column): array
{
    if (!in_array($column, ['category', 'location_label'], true)) {
        return [];
    }
    try {
        return $pdo->query("SELECT DISTINCT `$column` FROM logistics_stock_items WHERE TRIM(COALESCE(`$column`,'')) <> '' ORDER BY `$column` ASC")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function logi_settings_rename_linked_values(PDO $pdo, string $column, string $renamesJson): array
{
    if (!in_array($column, ['category', 'location_label'], true)) {
        return [];
    }
    $renames = json_decode($renamesJson, true);
    if (!is_array($renames)) {
        return [];
    }
    $applied = [];
    $stmt = $pdo->prepare("UPDATE logistics_stock_items SET `$column` = :new WHERE `$column` = :old");
    foreach ($renames as $old => $new) {
        $old = trim((string)$old);
        $new = trim((string)$new);
        if ($old === '' || $new === '' || strcasecmp($old, $new) === 0) {
            continue;
        }
        $stmt->execute(['old' => $old, 'new' => $new]);
        if ($stmt->rowCount() > 0) {
            $applied[$old] = $new;
        }
    }
    return $applied;
}

function logi_settings_merge_locked_values(array $requested, array $used, string $label, array &$messages): array
{
    $requestedByKey = [];
    foreach ($requested as $value) {
        $normalized = function_exists('mb_strtolower') ? mb_strtolower(trim((string)$value), 'UTF-8') : strtolower(trim((string)$value));
        $requestedByKey[$normalized] = $value;
    }
    $locked = [];
    foreach ($used as $value) {
        $value = trim((string)$value);
        if ($value === '') {
            continue;
        }
        $key = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        if (!array_key_exists($key, $requestedByKey)) {
            $locked[] = $value;
            $requested[] = $value;
        }
    }
    if ($locked) {
        $messages[] = $label . ' conserve(s), car deja lie(s) a des articles stock : ' . implode(', ', $locked);
    }
    return array_values(array_unique(array_filter(array_map('trim', $requested))));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $settingWarnings = [];
    $payload = [];
    foreach (array_keys($fuelSettings + $alertSettings) as $key) {
        $payload[$key] = trim((string)($_POST[$key] ?? ''));
    }
    $rolesRaw = trim((string)($_POST['logistics_roles'] ?? ''));
    $roles = logi_settings_list_from_raw($rolesRaw);
    $payload['logistics_roles'] = implode("\n", $roles);
    $categoriesRaw = trim((string)($_POST['stock_categories'] ?? ''));
    $categories = logi_settings_list_from_raw($categoriesRaw);
    logi_settings_rename_linked_values($pdo, 'category', (string)($_POST['stock_category_renames'] ?? '{}'));
    $categories = logi_settings_merge_locked_values($categories, logi_settings_used_values($pdo, 'category'), 'Categorie(s)', $settingWarnings);
    $payload['stock_categories'] = implode("\n", $categories);
    $locationsRaw = trim((string)($_POST['stock_locations'] ?? ''));
    $locations = logi_settings_list_from_raw($locationsRaw);
    logi_settings_rename_linked_values($pdo, 'location_label', (string)($_POST['stock_location_renames'] ?? '{}'));
    $locations = logi_settings_merge_locked_values($locations, logi_settings_used_values($pdo, 'location_label'), 'Emplacement(s)', $settingWarnings);
    $payload['stock_locations'] = implode("\n", $locations);
    $blockReasonsRaw = trim((string)($_POST['planning_block_reasons'] ?? ''));
    $blockReasons = logi_settings_list_from_raw($blockReasonsRaw);
    $payload['planning_block_reasons'] = implode("\n", $blockReasons);

    $stmt = $pdo->prepare('INSERT INTO logistics_settings(setting_key,setting_value,setting_group) VALUES(?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
    foreach ($payload as $key => $value) {
        $group = strpos($key, 'fuel_price_') === 0 ? 'fuel' : ($key === 'logistics_roles' ? 'team' : ($key === 'planning_block_reasons' ? 'planning' : (in_array($key, ['stock_categories', 'stock_locations'], true) ? 'stock' : 'alerts')));
        $stmt->execute([$key, $value, $group]);
    }
    logi_audit($pdo, 'update_settings', 'logistics_settings', null, 'Parametres logistiques', ['keys' => array_keys($payload), 'warnings' => $settingWarnings]);
    if ($settingWarnings) {
        $_SESSION['logi_settings_warnings'] = $settingWarnings;
        logi_redirect('settings.php?ok=1&locked=1');
    }
    logi_redirect('settings.php?ok=1');
}

$selectedRoles = array_values(array_filter(array_map('trim', preg_split('/[\r\n,;]+/', (string)($current['logistics_roles'] ?? implode("\n", array_slice($rolePresets, 0, 8)))) ?: [])));
$allRoles = array_values(array_unique(array_merge($rolePresets, $selectedRoles)));
$existingStockCategories = [];
try {
    $existingStockCategories = $pdo->query("SELECT DISTINCT category FROM logistics_stock_items WHERE TRIM(COALESCE(category,'')) <> '' ORDER BY category ASC")->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Throwable $e) {
    $existingStockCategories = [];
}
$selectedStockCategories = array_values(array_filter(array_map('trim', preg_split('/[\r\n,;]+/', (string)($current['stock_categories'] ?? implode("\n", $stockCategoryPresets))) ?: [])));
$allStockCategories = array_values(array_unique(array_merge($stockCategoryPresets, $existingStockCategories, $selectedStockCategories)));
$existingStockLocations = [];
try {
    $existingStockLocations = $pdo->query("SELECT DISTINCT location_label FROM logistics_stock_items WHERE TRIM(COALESCE(location_label,'')) <> '' ORDER BY location_label ASC")->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Throwable $e) {
    $existingStockLocations = [];
}
$selectedStockLocations = array_values(array_filter(array_map('trim', preg_split('/[\r\n,;]+/', (string)($current['stock_locations'] ?? implode("\n", $stockLocationPresets))) ?: [])));
$allStockLocations = array_values(array_unique(array_merge($stockLocationPresets, $existingStockLocations, $selectedStockLocations)));
$selectedPlanningBlockReasons = array_values(array_filter(array_map('trim', preg_split('/[\r\n,;]+/', (string)($current['planning_block_reasons'] ?? implode("\n", $planningBlockReasonPresets))) ?: [])));
$allPlanningBlockReasons = array_values(array_unique(array_merge($planningBlockReasonPresets, $selectedPlanningBlockReasons)));
$settingWarnings = $_SESSION['logi_settings_warnings'] ?? [];
unset($_SESSION['logi_settings_warnings']);

logi_page_start('Parametres logistiques', 'Reglages simples pour le carburant, les alertes et l equipe terrain.');
?>

<?php if (isset($_GET['ok'])): ?>
    <div class="mb-6 rounded-2xl border border-emerald-100 bg-emerald-50 px-5 py-4 text-sm font-semibold text-emerald-800">Parametres enregistres.</div>
<?php endif; ?>
<?php if ($settingWarnings): ?>
    <div class="mb-6 rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm font-semibold text-amber-900">
        <?php foreach ($settingWarnings as $warning): ?>
            <div><?= logi_h((string)$warning) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<form method="post" class="space-y-6">
    <?php logi_card_open('Prix carburant', 'Prix de reference utilises pour calculer les couts par engin, chauffeur et chantier.'); ?>
    <div class="grid md:grid-cols-4 gap-5">
        <?php foreach ($fuelSettings as $key => $meta): ?>
            <?= logi_input($meta['label'] . ' / litre', $key, $current[$key] ?? $meta['default'], 'number', 'step="0.01" min="0"') ?>
        <?php endforeach; ?>
    </div>
    <?php logi_card_close(); ?>

    <?php logi_card_open('Alertes terrain', 'Seuils lisibles par l exploitation, sans jargon informatique.'); ?>
    <div class="grid md:grid-cols-2 gap-5">
        <?php foreach ($alertSettings as $key => $meta): ?>
            <div>
                <?= logi_input($meta['label'], $key, $current[$key] ?? $meta['default'], 'number', 'step="0.01" min="0"') ?>
                <p class="mt-2 text-xs text-slate-500"><?= logi_h($meta['help']) ?></p>
            </div>
        <?php endforeach; ?>
    </div>
    <?php logi_card_close(); ?>

    <?php logi_card_open('Categories stock', 'Preliste utilisee dans le formulaire Nouvel article.'); ?>
    <input type="hidden" name="stock_categories" id="stockCategoriesValue" value="<?= logi_h(implode("\n", $selectedStockCategories)) ?>">
    <input type="hidden" name="stock_category_renames" id="stockCategoryRenames" value="{}">
    <div class="logi-category-picker" data-options="<?= logi_h(json_encode($allStockCategories, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>" data-selected="<?= logi_h(json_encode($selectedStockCategories, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>" data-locked="<?= logi_h(json_encode($existingStockCategories, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>">
        <div class="relative">
            <input type="text" class="w-full h-12 rounded-2xl border border-slate-200 bg-slate-50/80 px-4 pr-10 text-slate-800 outline-none focus:border-orange-300 focus:bg-white focus:ring-4 focus:ring-orange-100 transition" placeholder="Rechercher ou ajouter une categorie" data-category-input>
            <button type="button" class="absolute right-2 top-1/2 -translate-y-1/2 h-8 w-8 rounded-xl text-slate-500 hover:bg-slate-100" data-category-add aria-label="Ajouter"><i class="fas fa-plus text-xs"></i></button>
            <div class="hidden absolute z-50 mt-2 max-h-64 w-full overflow-auto rounded-2xl border border-slate-200 bg-white p-2 shadow-xl" data-category-menu></div>
        </div>
        <div class="mt-4 flex flex-wrap gap-2" data-category-badges></div>
    </div>
    <?php logi_card_close(); ?>

    <?php logi_card_open('Emplacements stock', 'Liste utilisee dans le formulaire Nouvel article pour eviter les saisies incoherentes.'); ?>
    <input type="hidden" name="stock_locations" id="stockLocationsValue" value="<?= logi_h(implode("\n", $selectedStockLocations)) ?>">
    <input type="hidden" name="stock_location_renames" id="stockLocationRenames" value="{}">
    <div class="logi-location-picker" data-options="<?= logi_h(json_encode($allStockLocations, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>" data-selected="<?= logi_h(json_encode($selectedStockLocations, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>" data-locked="<?= logi_h(json_encode($existingStockLocations, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>">
        <div class="relative">
            <input type="text" class="w-full h-12 rounded-2xl border border-slate-200 bg-slate-50/80 px-4 pr-10 text-slate-800 outline-none focus:border-orange-300 focus:bg-white focus:ring-4 focus:ring-orange-100 transition" placeholder="Rechercher ou ajouter un emplacement" data-location-input>
            <button type="button" class="absolute right-2 top-1/2 -translate-y-1/2 h-8 w-8 rounded-xl text-slate-500 hover:bg-slate-100" data-location-add aria-label="Ajouter"><i class="fas fa-plus text-xs"></i></button>
            <div class="hidden absolute z-50 mt-2 max-h-64 w-full overflow-auto rounded-2xl border border-slate-200 bg-white p-2 shadow-xl" data-location-menu></div>
        </div>
        <div class="mt-4 flex flex-wrap gap-2" data-location-badges></div>
    </div>
    <?php logi_card_close(); ?>

    <?php logi_card_open('Equipe logistique', 'Choisissez les roles metier visibles dans le module.'); ?>
    <input type="hidden" name="logistics_roles" id="logisticsRolesValue" value="<?= logi_h(implode("\n", $selectedRoles)) ?>">
    <div class="logi-role-picker" data-options="<?= logi_h(json_encode($allRoles, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>" data-selected="<?= logi_h(json_encode($selectedRoles, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>">
        <div class="relative">
            <input type="text" class="w-full h-12 rounded-2xl border border-slate-200 bg-slate-50/80 px-4 pr-10 text-slate-800 outline-none focus:border-orange-300 focus:bg-white focus:ring-4 focus:ring-orange-100 transition" placeholder="Rechercher ou ajouter un role" data-role-input>
            <button type="button" class="absolute right-2 top-1/2 -translate-y-1/2 h-8 w-8 rounded-xl text-slate-500 hover:bg-slate-100" data-role-add aria-label="Ajouter"><i class="fas fa-plus text-xs"></i></button>
            <div class="hidden absolute z-50 mt-2 max-h-64 w-full overflow-auto rounded-2xl border border-slate-200 bg-white p-2 shadow-xl" data-role-menu></div>
        </div>
        <div class="mt-4 flex flex-wrap gap-2" data-role-badges></div>
    </div>
    <?php logi_card_close(); ?>

    <?php logi_card_open('Motifs de blocage planning', 'Liste utilisee pendant les reunions logistiques pour expliquer les taches bloquees.'); ?>
    <input type="hidden" name="planning_block_reasons" id="planningBlockReasonsValue" value="<?= logi_h(implode("\n", $selectedPlanningBlockReasons)) ?>">
    <div class="logi-block-reason-picker" data-options="<?= logi_h(json_encode($allPlanningBlockReasons, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>" data-selected="<?= logi_h(json_encode($selectedPlanningBlockReasons, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>">
        <div class="relative">
            <input type="text" class="w-full h-12 rounded-2xl border border-slate-200 bg-slate-50/80 px-4 pr-10 text-slate-800 outline-none focus:border-orange-300 focus:bg-white focus:ring-4 focus:ring-orange-100 transition" placeholder="Rechercher ou ajouter un motif de blocage" data-block-input>
            <button type="button" class="absolute right-2 top-1/2 -translate-y-1/2 h-8 w-8 rounded-xl text-slate-500 hover:bg-slate-100" data-block-add aria-label="Ajouter"><i class="fas fa-plus text-xs"></i></button>
            <div class="hidden absolute z-50 mt-2 max-h-64 w-full overflow-auto rounded-2xl border border-slate-200 bg-white p-2 shadow-xl" data-block-menu></div>
        </div>
        <div class="mt-4 flex flex-wrap gap-2" data-block-badges></div>
    </div>
    <?php logi_card_close(); ?>

    <?php logi_card_open('Priorites du module', 'Repere fonctionnel issu du cahier des charges logistique.'); ?>
    <div class="grid md:grid-cols-3 gap-4 text-sm">
        <?php foreach ([
            ['Engins et vehicules', 'Disponibilite, affectation chantier, compteurs et documents.'],
            ['Carburant', 'Consommation par chantier, engin et anomalies rouges.'],
            ['Maintenance', 'Preventif, curatif, immobilisation et historique technique.'],
            ['Terrain', 'Chauffeurs actifs, rotations, incidents et retards.'],
            ['Documents', 'Assurance, visite technique, permis, licences et alertes.'],
            ['Finances', 'Couts chantier, carburant, maintenance et cout horaire reel.'],
        ] as $item): ?>
            <div class="rounded-2xl border border-slate-100 bg-slate-50 px-4 py-4">
                <div class="font-semibold text-slate-900"><?= logi_h($item[0]) ?></div>
                <div class="mt-1 text-slate-600"><?= logi_h($item[1]) ?></div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php logi_card_close(); ?>

    <div class="sticky bottom-4 z-20 flex justify-end">
        <button type="submit" class="rounded-2xl bg-orange-600 px-7 py-3 font-semibold text-white shadow-lg shadow-orange-600/20 hover:bg-orange-700">Enregistrer les parametres</button>
    </div>
</form>

<script>
document.addEventListener('DOMContentLoaded', function() {
    function normalize(value) {
        return String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
    }

    function initEditablePicker(config) {
        const root = document.querySelector(config.root);
        if (!root) return;

        const hidden = document.getElementById(config.hiddenId);
        const input = root.querySelector(config.input);
        const addButton = root.querySelector(config.addButton);
        const menu = root.querySelector(config.menu);
        const badges = root.querySelector(config.badges);
        const renameInput = config.renameId ? document.getElementById(config.renameId) : null;
        if (!hidden || !input || !addButton || !menu || !badges) return;

        let options = JSON.parse(root.dataset.options || '[]');
        let selected = JSON.parse(root.dataset.selected || '[]');
        let renames = {};
        const lockedKeys = new Set((JSON.parse(root.dataset.locked || '[]')).map(function(value) {
            return normalize(value);
        }));

        function uniqueValues(values) {
            const seen = new Set();
            return values.map(function(value) {
                return String(value || '').trim();
            }).filter(function(value) {
                const key = normalize(value);
                if (!value || seen.has(key)) return false;
                seen.add(key);
                return true;
            });
        }

        function updateHidden() {
            selected = uniqueValues(selected);
            hidden.value = selected.join('\n');
        }

        function commitEdit(oldValue, newValue) {
            newValue = String(newValue || '').trim();
            if (!newValue) {
                selected = selected.filter(function(item) { return item !== oldValue; });
            } else {
                selected = selected.map(function(item) { return item === oldValue ? newValue : item; });
                if (!options.some(function(item) { return normalize(item) === normalize(newValue); })) {
                    options.push(newValue);
                }
                if (renameInput && normalize(oldValue) !== normalize(newValue)) {
                    renames[oldValue] = newValue;
                    renameInput.value = JSON.stringify(renames);
                }
                if (lockedKeys.has(normalize(oldValue))) {
                    lockedKeys.add(normalize(newValue));
                }
            }
            sync();
        }

        function startEdit(label, labelNode) {
            const editor = document.createElement('input');
            editor.type = 'text';
            editor.value = label;
            editor.className = 'h-7 min-w-[150px] rounded-full border border-white/70 bg-white px-3 text-sm font-semibold text-slate-900 outline-none ring-2 ring-orange-200';
            labelNode.replaceWith(editor);
            editor.focus();
            editor.select();
            let done = false;

            function finish() {
                if (done) return;
                done = true;
                commitEdit(label, editor.value);
            }
            function cancel() {
                if (done) return;
                done = true;
                sync();
            }
            editor.addEventListener('keydown', function(event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    finish();
                }
                if (event.key === 'Escape') {
                    event.preventDefault();
                    cancel();
                }
            });
            editor.addEventListener('blur', finish);
        }

        function sync() {
            updateHidden();
            badges.innerHTML = '';
            selected.forEach(function(label) {
                const badge = document.createElement('span');
                badge.className = config.badgeClass;

                const labelButton = document.createElement('button');
                labelButton.type = 'button';
                labelButton.className = 'max-w-[240px] truncate text-left outline-none hover:underline';
                labelButton.title = 'Cliquer pour modifier';
                labelButton.textContent = label;
                labelButton.addEventListener('click', function() {
                    startEdit(label, labelButton);
                });

                const removeButton = document.createElement('button');
                removeButton.type = 'button';
                const isLocked = lockedKeys.has(normalize(label));
                removeButton.className = isLocked
                    ? 'flex h-5 w-5 shrink-0 cursor-not-allowed items-center justify-center rounded-full bg-slate-300 text-white'
                    : 'flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-red-600 text-white hover:bg-red-700';
                removeButton.setAttribute('aria-label', isLocked ? 'Suppression interdite : element deja lie' : 'Retirer');
                removeButton.title = isLocked ? 'Suppression interdite : element deja lie a un article stock' : 'Retirer';
                removeButton.disabled = isLocked;
                removeButton.innerHTML = '<i class="fas fa-times text-[10px]"></i>';
                removeButton.addEventListener('click', function() {
                    if (isLocked) return;
                    selected = selected.filter(function(item) { return item !== label; });
                    sync();
                });

                badge.appendChild(labelButton);
                badge.appendChild(removeButton);
                badges.appendChild(badge);
            });
        }

        function addValue(value) {
            value = String(value || '').trim();
            if (!value) return;
            if (!selected.some(function(item) { return normalize(item) === normalize(value); })) {
                selected.push(value);
            }
            if (!options.some(function(item) { return normalize(item) === normalize(value); })) {
                options.push(value);
            }
            input.value = '';
            menu.classList.add('hidden');
            sync();
        }

        function render() {
            const query = normalize(input.value);
            const matches = uniqueValues(options).filter(function(value) {
                return !selected.some(function(item) { return normalize(item) === normalize(value); })
                    && normalize(value).includes(query);
            }).slice(0, config.limit || 50);
            menu.innerHTML = '';
            matches.forEach(function(value) {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'block w-full rounded-xl px-3 py-2 text-left text-sm text-slate-700 hover:bg-orange-50 hover:text-orange-700';
                button.textContent = value;
                button.addEventListener('mousedown', function(event) {
                    event.preventDefault();
                    addValue(value);
                });
                menu.appendChild(button);
            });
            if (input.value.trim() !== '') {
                const custom = document.createElement('button');
                custom.type = 'button';
                custom.className = 'block w-full rounded-xl px-3 py-2 text-left text-sm font-semibold text-orange-700 hover:bg-orange-50';
                custom.textContent = 'Ajouter "' + input.value.trim() + '"';
                custom.addEventListener('mousedown', function(event) {
                    event.preventDefault();
                    addValue(input.value);
                });
                menu.appendChild(custom);
            }
            menu.classList.toggle('hidden', menu.children.length === 0);
        }

        input.addEventListener('focus', render);
        input.addEventListener('input', render);
        input.addEventListener('keydown', function(event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                addValue(input.value);
            }
            if (event.key === 'Escape') menu.classList.add('hidden');
        });
        addButton.addEventListener('click', function() { addValue(input.value); });
        document.addEventListener('click', function(event) {
            if (!root.contains(event.target)) menu.classList.add('hidden');
        });
        sync();
    }

    initEditablePicker({
        root: '.logi-category-picker',
        hiddenId: 'stockCategoriesValue',
        renameId: 'stockCategoryRenames',
        input: '[data-category-input]',
        addButton: '[data-category-add]',
        menu: '[data-category-menu]',
        badges: '[data-category-badges]',
        badgeClass: 'inline-flex items-center gap-2 rounded-full bg-orange-50 px-3 py-1.5 text-sm font-semibold text-orange-700 ring-1 ring-orange-100'
    });
    initEditablePicker({
        root: '.logi-location-picker',
        hiddenId: 'stockLocationsValue',
        renameId: 'stockLocationRenames',
        input: '[data-location-input]',
        addButton: '[data-location-add]',
        menu: '[data-location-menu]',
        badges: '[data-location-badges]',
        badgeClass: 'inline-flex items-center gap-2 rounded-full bg-sky-50 px-3 py-1.5 text-sm font-semibold text-sky-700 ring-1 ring-sky-100'
    });
    initEditablePicker({
        root: '.logi-role-picker',
        hiddenId: 'logisticsRolesValue',
        input: '[data-role-input]',
        addButton: '[data-role-add]',
        menu: '[data-role-menu]',
        badges: '[data-role-badges]',
        badgeClass: 'inline-flex items-center gap-2 rounded-full bg-slate-900 px-3 py-1.5 text-sm font-semibold text-white'
    });
    initEditablePicker({
        root: '.logi-block-reason-picker',
        hiddenId: 'planningBlockReasonsValue',
        input: '[data-block-input]',
        addButton: '[data-block-add]',
        menu: '[data-block-menu]',
        badges: '[data-block-badges]',
        badgeClass: 'inline-flex items-center gap-2 rounded-full bg-amber-50 px-3 py-1.5 text-sm font-semibold text-amber-800 ring-1 ring-amber-100'
    });
});
</script>
<?php logi_page_end();
