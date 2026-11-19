<?php
require_once __DIR__ . '/_logistics_common.php';

function logi_stock_code_prefix_form(string $category, string $designation): string
{
    $source = trim($designation) !== '' ? $designation : ($category !== '' ? $category : 'stock');
    if (function_exists('iconv')) {
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $source);
        if ($converted !== false) $source = $converted;
    }
    $letters = preg_replace('/[^A-Z]/', '', strtoupper($source)) ?: 'STK';
    return substr(str_pad($letters, 3, 'X'), 0, 3);
}

function logi_next_stock_code_form(PDO $pdo, string $category = '', string $designation = ''): string
{
    $prefix = logi_stock_code_prefix_form($category, $designation);
    $stmt = $pdo->prepare('SELECT reference_code FROM logistics_stock_items WHERE reference_code LIKE :prefix');
    $stmt->execute(['prefix' => $prefix . '-%']);
    $max = 0;
    foreach (($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []) as $code) {
        if (preg_match('/^' . preg_quote($prefix, '/') . '-(\d+)$/', (string)$code, $m)) {
            $max = max($max, (int)$m[1]);
        }
    }
    return sprintf('%s-%03d', $prefix, $max + 1);
}

function logi_insert_dynamic_form(PDO $pdo, string $table, array $payload): void
{
    $filtered = [];
    foreach ($payload as $column => $value) {
        if (logi_col_exists($pdo, $table, $column)) $filtered[$column] = $value;
    }
    if (!$filtered) return;
    $cols = array_keys($filtered);
    $marks = array_map(fn($c) => ':' . $c, $cols);
    $pdo->prepare('INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', $marks) . ')')->execute($filtered);
}

$suppliers = logi_options_suppliers($pdo);
$categoryOptions = logi_stock_category_options($pdo);
$locationOptions = logi_stock_location_options($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $category = trim((string)($_POST['category'] ?? ''));
    $designation = trim((string)($_POST['designation'] ?? ''));
    $code = logi_next_stock_code_form($pdo, $category, $designation);
    $unit = trim((string)($_POST['unit_label'] ?? 'unite')) ?: 'unite';
    logi_insert_dynamic_form($pdo, 'logistics_stock_items', [
        'item_code' => $code,
        'category' => $category,
        'designation' => $designation,
        'reference_code' => $code,
        'unit_label' => $unit,
        'unit' => $unit,
        'current_quantity' => (float)($_POST['current_quantity'] ?? 0),
        'minimum_quantity' => (float)($_POST['minimum_quantity'] ?? 0),
        'current_stock' => (float)($_POST['current_quantity'] ?? 0),
        'minimum_stock' => (float)($_POST['minimum_quantity'] ?? 0),
        'location_label' => $_POST['location_label'] ?? null,
        'location' => $_POST['location_label'] ?? null,
        'preferred_supplier_id' => ($_POST['preferred_supplier_id'] ?? '') !== '' ? (int)$_POST['preferred_supplier_id'] : null,
        'status' => $_POST['status'] ?? 'active',
    ]);
    $itemId = (int)$pdo->lastInsertId();
    $label = trim($code . ' - ' . $designation);
    logi_notify_asset_event($pdo, 'stock_item_created', ['entity_type' => 'stock_item', 'entity_id' => $itemId, 'entity_label' => $label]);
    logi_audit($pdo, 'create_stock_item', 'logistics_stock_item', $itemId, $label, ['category' => $category, 'quantity' => $_POST['current_quantity'] ?? 0]);
    logi_redirect('stock.php?ok=1');
}

$suggestedStockCode = logi_next_stock_code_form($pdo);
logi_page_start('Ajouter un article stock', 'Formulaire dedie creation de reference stock.');
logi_card_open('Nouvel article', 'Creer une reference stock');
?>
<form method="post" class="grid gap-5 md:grid-cols-2">
    <div class="block logi-stock-category" data-options="<?= logi_h(json_encode($categoryOptions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>">
        <span class="text-sm font-semibold text-slate-700">Categorie</span>
        <input type="hidden" name="category" value="" data-stock-category-value>
        <div class="relative mt-2">
            <input type="text" id="stockCategoryField" class="h-12 w-full rounded-2xl border border-slate-200 bg-slate-50/80 px-4 pr-10 text-slate-800 outline-none transition focus:border-orange-300 focus:bg-white focus:ring-4 focus:ring-orange-100" placeholder="Rechercher ou saisir une categorie" autocomplete="off" data-stock-category-input>
            <button type="button" class="absolute right-2 top-1/2 h-8 w-8 -translate-y-1/2 rounded-xl text-slate-500 hover:bg-slate-100" data-stock-category-toggle aria-label="Ouvrir la liste"><i class="fas fa-chevron-down text-xs"></i></button>
            <div class="absolute z-50 mt-2 hidden max-h-64 w-full overflow-auto rounded-2xl border border-slate-200 bg-white p-2 shadow-xl" data-stock-category-menu></div>
        </div>
        <p class="mt-2 text-xs text-slate-500">Les categories viennent des parametres logistiques. Une nouvelle saisie reste possible.</p>
    </div>
    <?= logi_input('Designation', 'designation', '', 'text', 'required id="stockDesignationField"') ?>
    <?= logi_input('Reference', 'reference_code', $suggestedStockCode, 'text', 'id="stockCodeField" readonly') ?>
    <?= logi_input('Unite', 'unit_label', 'unite') ?>
    <?= logi_input('Stock initial', 'current_quantity', '0', 'number', 'step="0.01"') ?>
    <?= logi_input('Stock minimum', 'minimum_quantity', '0', 'number', 'step="0.01"') ?>
    <div class="block logi-stock-location" data-options="<?= logi_h(json_encode($locationOptions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>">
        <span class="text-sm font-semibold text-slate-700">Emplacement</span>
        <input type="hidden" name="location_label" value="" data-stock-location-value>
        <div class="relative mt-2">
            <input type="text" id="stockLocationField" class="h-12 w-full rounded-2xl border border-slate-200 bg-slate-50/80 px-4 pr-10 text-slate-800 outline-none transition focus:border-orange-300 focus:bg-white focus:ring-4 focus:ring-orange-100" placeholder="Rechercher ou saisir un emplacement" autocomplete="off" data-stock-location-input>
            <button type="button" class="absolute right-2 top-1/2 h-8 w-8 -translate-y-1/2 rounded-xl text-slate-500 hover:bg-slate-100" data-stock-location-toggle aria-label="Ouvrir la liste"><i class="fas fa-chevron-down text-xs"></i></button>
            <div class="absolute z-50 mt-2 hidden max-h-64 w-full overflow-auto rounded-2xl border border-slate-200 bg-white p-2 shadow-xl" data-stock-location-menu></div>
        </div>
        <p class="mt-2 text-xs text-slate-500">Les emplacements viennent des parametres logistiques. Une nouvelle saisie reste possible.</p>
    </div>
    <?= logi_search_select('Fournisseur prefere', 'preferred_supplier_id', logi_search_options($suppliers, 'id', 'label', 'Aucun'), '', '', 'Rechercher un fournisseur') ?>
    <div class="md:col-span-2"><?= logi_submit('Creer article') ?></div>
</form>
<div class="mt-4"><a href="stock.php" class="font-semibold text-orange-600">Retour au listing</a></div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    function initCreatableSingle(root, config) {
        if (!root) return;
        const options = JSON.parse(root.dataset.options || '[]');
        const hidden = root.querySelector(config.hidden);
        const input = root.querySelector(config.input);
        const menu = root.querySelector(config.menu);
        const toggle = root.querySelector(config.toggle);
        if (!hidden || !input || !menu || !toggle) return;

        function normalize(value) {
            return String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
        }
        function closeMenu() {
            menu.classList.add('hidden');
        }
        function choose(label) {
            hidden.value = label;
            input.value = label;
            closeMenu();
        }
        function render() {
            const query = normalize(input.value);
            const matches = options.filter(function(option) {
                return normalize(option.label).includes(query);
            }).slice(0, 60);
            menu.innerHTML = '';
            matches.forEach(function(option) {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'block w-full rounded-xl px-3 py-2 text-left text-sm text-slate-700 hover:bg-orange-50 hover:text-orange-700';
                button.textContent = option.label;
                button.addEventListener('mousedown', function(event) {
                    event.preventDefault();
                    choose(option.label);
                });
                menu.appendChild(button);
            });
            if (input.value.trim()) {
                const custom = document.createElement('button');
                custom.type = 'button';
                custom.className = 'block w-full rounded-xl px-3 py-2 text-left text-sm font-semibold text-orange-700 hover:bg-orange-50';
                custom.textContent = 'Utiliser "' + input.value.trim() + '"';
                custom.addEventListener('mousedown', function(event) {
                    event.preventDefault();
                    choose(input.value.trim());
                });
                menu.appendChild(custom);
            }
            if (!menu.children.length) {
                const empty = document.createElement('div');
                empty.className = 'px-3 py-2 text-sm text-slate-500';
                empty.textContent = config.emptyLabel;
                menu.appendChild(empty);
            }
            menu.classList.remove('hidden');
        }
        input.addEventListener('focus', render);
        input.addEventListener('input', function() {
            hidden.value = input.value.trim();
            render();
        });
        input.addEventListener('keydown', function(event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                choose(input.value.trim());
            }
            if (event.key === 'Escape') closeMenu();
        });
        input.addEventListener('blur', function() {
            window.setTimeout(function() {
                hidden.value = input.value.trim();
                closeMenu();
            }, 120);
        });
        toggle.addEventListener('click', function() {
            input.focus();
            render();
        });
        document.addEventListener('click', function(event) {
            if (!root.contains(event.target)) closeMenu();
        });
    }

    initCreatableSingle(document.querySelector('.logi-stock-category'), {
        hidden: '[data-stock-category-value]',
        input: '[data-stock-category-input]',
        menu: '[data-stock-category-menu]',
        toggle: '[data-stock-category-toggle]',
        emptyLabel: 'Aucune categorie parametree'
    });
    initCreatableSingle(document.querySelector('.logi-stock-location'), {
        hidden: '[data-stock-location-value]',
        input: '[data-stock-location-input]',
        menu: '[data-stock-location-menu]',
        toggle: '[data-stock-location-toggle]',
        emptyLabel: 'Aucun emplacement parametre'
    });
});
</script>
<?php logi_card_close(); logi_page_end(); ?>
