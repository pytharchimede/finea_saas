<?php
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../logi/_logistics_common.php';
\AccessManager::requirePageAccess();

$userId = (int)($_SESSION['user_id'] ?? 0);
$requesterName = trim((string)($_SESSION['prenom'] ?? '') . ' ' . (string)($_SESSION['nom'] ?? ''));
$availableStatuses = ['available', 'disponible', 'active'];
$chantiers = logi_chantiers($pdo);
$debourseOptions = logi_debourse_options($pdo);

$assets = $pdo->query("
    SELECT id, COALESCE(NULLIF(TRIM(designation), ''), CONCAT('Element #', id)) designation,
           COALESCE(NULLIF(TRIM(asset_type), ''), NULLIF(TRIM(type), ''), 'autre') item_type,
           COALESCE(NULLIF(TRIM(status), ''), NULLIF(TRIM(statut), ''), 'available') item_status,
           COALESCE(NULLIF(TRIM(current_site), ''), NULLIF(TRIM(localisation), ''), '') current_site,
           COALESCE(NULLIF(TRIM(current_holder), ''), '') current_holder,
           COALESCE(NULLIF(TRIM(asset_code), ''), '') code,
           COALESCE(NULLIF(TRIM(registration_number), ''), '') reference_label,
           'asset' source_type
    FROM logistics_assets
    ORDER BY item_type, designation
")->fetchAll(PDO::FETCH_ASSOC);

$stockRows = $pdo->query("
    SELECT id, COALESCE(NULLIF(TRIM(designation), ''), CONCAT('Article #', id)) designation,
           COALESCE(NULLIF(TRIM(category), ''), 'materiel') item_type,
           COALESCE(NULLIF(TRIM(status), ''), 'active') item_status,
           COALESCE(NULLIF(TRIM(location_label), ''), '') current_site,
           '' current_holder,
           COALESCE(NULLIF(TRIM(reference_code), ''), '') code,
           COALESCE(NULLIF(TRIM(unit_label), ''), '') reference_label,
           COALESCE(current_stock, current_quantity, 0) stock_available,
           'stock' source_type
    FROM logistics_stock_items
    WHERE COALESCE(status, 'active') = 'active'
    ORDER BY category, designation
")->fetchAll(PDO::FETCH_ASSOC);

$objectOptions = [];
foreach (array_merge($assets, $stockRows) as $row) {
    $source = (string)($row['source_type'] ?? 'asset');
    $status = strtolower(trim((string)($row['item_status'] ?? '')));
    $holder = trim((string)($row['current_holder'] ?? ''));
    $site = trim((string)($row['current_site'] ?? ''));
    $isStock = $source === 'stock';
    $stock = (float)($row['stock_available'] ?? 0);
    $occupied = $isStock ? $stock <= 0 : (!in_array($status, $availableStatuses, true) || $holder !== '');
    $meta = $isStock
        ? ('Stock: ' . logi_num($stock) . ' ' . trim((string)($row['reference_label'] ?? '')) . ($site !== '' ? ' - ' . $site : ''))
        : ($occupied ? trim(($holder !== '' ? 'Detenu par ' . $holder : 'Occupe') . ($site !== '' ? ' - ' . $site : '')) : ($site !== '' ? 'Disponible - ' . $site : 'Disponible'));
    $objectOptions[] = [
        'value' => $source . ':' . (int)$row['id'],
        'id' => (int)$row['id'],
        'source' => $source,
        'type' => strtolower((string)($row['item_type'] ?? 'autre')),
        'label' => trim((string)$row['designation'] . ' ' . (trim((string)($row['code'] ?? '')) !== '' ? '(' . trim((string)$row['code']) . ')' : '')),
        'status' => $status,
        'occupied' => $occupied,
        'meta' => $meta,
        'stock' => $stock,
    ];
}

$success = false;
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type = trim((string)($_POST['type'] ?? ''));
    $objectRef = trim((string)($_POST['object_ref'] ?? ''));
    $quantity = max(0, (float)($_POST['requested_quantity'] ?? 0));
    $siteLabel = trim((string)($_POST['site_label'] ?? ''));
    $ligneDebourseId = (int)($_POST['ligne_debourse_id'] ?? 0);
    $dateStart = trim((string)($_POST['date_start'] ?? ''));
    $dateEnd = trim((string)($_POST['date_end'] ?? ''));
    $motif = trim((string)($_POST['motif'] ?? ''));
    $selected = null;
    foreach ($objectOptions as $option) {
        if ((string)$option['value'] === $objectRef) {
            $selected = $option;
            break;
        }
    }
    $isStock = $selected && ($selected['source'] ?? '') === 'stock';
    $selectedChantierId = 0;
    foreach ($chantiers as $chantier) {
        if (trim((string)($chantier['label'] ?? '')) === $siteLabel) {
            $selectedChantierId = (int)($chantier['id'] ?? 0);
            break;
        }
    }
    $selectedDebourse = null;
    foreach ($debourseOptions as $option) {
        if ((int)($option['value'] ?? 0) === $ligneDebourseId) {
            $selectedDebourse = $option;
            break;
        }
    }
    if ($selectedDebourse && $siteLabel === '') {
        $siteLabel = (string)($selectedDebourse['chantier_label'] ?? '');
        $selectedChantierId = (int)($selectedDebourse['chantier_id'] ?? 0);
    }
    if ($type === '' || !$selected || $dateStart === '' || $userId <= 0) {
        $error = 'Veuillez renseigner le type, l objet demande et la date souhaitee.';
    } elseif ($selectedDebourse && $selectedChantierId > 0 && (int)($selectedDebourse['chantier_id'] ?? 0) !== $selectedChantierId) {
        $error = 'Le debourse selectionne ne correspond pas au chantier choisi.';
    } elseif (!empty($selected['occupied'])) {
        $error = $isStock ? 'Cet article est en rupture de stock.' : 'Cet element est deja occupe. Veuillez choisir un element disponible.';
    } elseif ($isStock && $quantity <= 0) {
        $error = 'Veuillez saisir la quantite demandee.';
    } elseif ($isStock && $quantity > (float)($selected['stock'] ?? 0)) {
        $error = 'La quantite demandee depasse le stock disponible.';
    } else {
        $requestCode = 'REQ-LOG-' . date('ymd-His');
        $description = trim($motif . "\n\nObjet demande : " . (string)($selected['label'] ?? ''));
        $stmt = $pdo->prepare("INSERT INTO logistics_requests (request_code, user_id, type, need_type, asset_id, stock_item_id, requested_quantity, date_start, date_end, desired_at, motif, description, requester_name, holder_type, holder_label, chantier_id, site_label, debourse_id, ligne_debourse_id, debourse_label, priority, statut, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'personnel', ?, ?, ?, ?, ?, ?, 'normal', 'en attente', 'submitted', NOW())");
        $stmt->execute([
            $requestCode,
            $userId,
            $type,
            $type,
            !$isStock ? (int)$selected['id'] : null,
            $isStock ? (int)$selected['id'] : null,
            $isStock ? $quantity : 0,
            $dateStart,
            $dateEnd !== '' ? $dateEnd : null,
            $dateStart,
            $motif,
            $description,
            $requesterName,
            $requesterName,
            $selectedChantierId > 0 ? $selectedChantierId : null,
            $siteLabel !== '' ? $siteLabel : null,
            $selectedDebourse ? (int)($selectedDebourse['debourse_id'] ?? 0) : null,
            $ligneDebourseId > 0 ? $ligneDebourseId : null,
            $selectedDebourse ? (string)($selectedDebourse['label'] ?? '') : null,
        ]);
        $requestId = (int)$pdo->lastInsertId();
        logi_notify_request($pdo, 'submitted', [
            'id' => $requestId,
            'request_code' => $requestCode,
            'user_id' => $userId,
            'need_type' => $type,
            'asset_id' => !$isStock ? (int)$selected['id'] : null,
            'asset_label' => (string)($selected['label'] ?? 'Element logistique'),
            'requester_name' => $requesterName,
            'status' => 'submitted',
        ], logi_actor_context());
        logi_audit($pdo, 'submit_request', 'logistics_request', $requestId, $requestCode, ['object_ref' => $objectRef, 'need_type' => $type, 'quantity' => $quantity]);
        $success = true;
    }
}

$pageTitle = 'Nouvelle demande logistique';
$layoutRootBase = '../';
$activeEmployeeMenu = 'logistique';
require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>
<div class="min-h-screen bg-[radial-gradient(circle_at_top_left,_rgba(255,247,237,0.9),_transparent_42%),radial-gradient(circle_at_top_right,_rgba(219,234,254,0.9),_transparent_36%),linear-gradient(180deg,_#f8fafc_0%,_#ffffff_100%)] pb-14">
    <div class="mx-auto max-w-[1180px] px-4 py-7 2xl:px-8">
        <section class="mb-7 overflow-hidden rounded-[30px] border border-slate-200 bg-white shadow-sm">
            <div class="grid gap-0 lg:grid-cols-[0.95fr_1.35fr]">
                <div class="bg-slate-950 p-8 text-white">
                    <p class="text-xs font-semibold uppercase tracking-[0.28em] text-amber-300">Demande logistique</p>
                    <h1 class="mt-4 text-3xl font-semibold">Exprimer un besoin terrain</h1>
                    <p class="mt-4 text-sm leading-6 text-slate-300">Selectionnez un type, choisissez l objet disponible, indiquez la periode et envoyez la demande a la logistique pour validation.</p>
                    <div class="mt-7 grid gap-3 text-sm">
                        <div class="rounded-2xl bg-white/10 p-4"><i class="fa-solid fa-magnifying-glass mr-2 text-amber-300"></i>Recherche moderne par type et disponibilite</div>
                        <div class="rounded-2xl bg-white/10 p-4"><i class="fa-solid fa-boxes-stacked mr-2 text-amber-300"></i>Consommables traces a la sortie du stock</div>
                    </div>
                </div>
                <div class="p-6 md:p-8">
                    <?php if ($success): ?>
                        <div class="mb-5 rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 font-semibold text-emerald-800">Votre demande a ete envoyee avec succes.</div>
                    <?php elseif ($error): ?>
                        <div class="mb-5 rounded-2xl border border-rose-200 bg-rose-50 px-5 py-4 font-semibold text-rose-800"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <form method="post" class="grid gap-5 md:grid-cols-2">
                        <?= logi_select('Type de demande *','type',[''=>'Selectionner','vehicule'=>'Vehicule','engin'=>'Engin','materiel'=>'Materiel','materiaux'=>'Materiaux','piece'=>'Piece','outillage'=>'Outillage','consommable'=>'Consommable'],'','id="requestType" required') ?>
                        <label class="block">
                            <span class="text-sm font-semibold text-slate-700">Quantite si consommable</span>
                            <input type="number" step="0.01" min="0" name="requested_quantity" id="quantityField" class="mt-2 h-12 w-full rounded-2xl border border-slate-200 bg-slate-50/80 px-4 text-slate-800 outline-none focus:border-orange-300 focus:bg-white focus:ring-4 focus:ring-orange-100" placeholder="Ex: 20">
                        </label>
                        <?= logi_chantier_search_input('Chantier concerne', 'site_label', $chantiers, (string)($_POST['site_label'] ?? '')) ?>
                        <?= logi_search_select('Debourse precis', 'ligne_debourse_id', [], (string)($_POST['ligne_debourse_id'] ?? ''), '', 'Choisir un chantier d abord') ?>
                        <div class="md:col-span-2">
                            <label class="mb-2 block text-sm font-semibold text-slate-700">Objet demande *</label>
                            <div id="objectSearch" class="relative" data-objects="<?= htmlspecialchars(json_encode($objectOptions, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="object_ref" id="objectRef" required>
                                <input type="text" id="objectInput" autocomplete="off" placeholder="Choisir un type puis rechercher une ressource ou un article..." class="h-14 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 pr-12 text-slate-800 outline-none focus:border-orange-300 focus:bg-white focus:ring-4 focus:ring-orange-100">
                                <button type="button" id="objectToggle" class="absolute right-3 top-1/2 -translate-y-1/2 rounded-xl px-3 py-2 text-slate-500 hover:bg-slate-100" aria-label="Ouvrir la liste"><i class="fa-solid fa-chevron-down text-xs"></i></button>
                                <div id="objectMenu" class="hidden absolute z-30 mt-2 max-h-80 w-full overflow-auto rounded-2xl border border-slate-200 bg-white p-2 shadow-xl"></div>
                            </div>
                            <p class="mt-2 text-xs text-slate-500">Les elements occupes ou indisponibles restent visibles en gris avec le detenteur, le lieu ou le stock.</p>
                        </div>
                        <?= logi_input('Date/heure debut *', 'date_start', '', 'datetime-local', 'required') ?>
                        <?= logi_input('Date/heure retour prevue', 'date_end', '', 'datetime-local') ?>
                        <div class="md:col-span-2"><?= logi_textarea('Motif / precision', 'motif', '', 'placeholder="Expliquez le besoin, le lieu, la quantite attendue ou les contraintes..."') ?></div>
                        <div class="md:col-span-2 flex justify-end">
                            <button type="submit" class="inline-flex items-center rounded-2xl bg-orange-600 px-6 py-3 text-sm font-semibold text-white shadow-lg shadow-orange-600/20 hover:bg-orange-700">
                                <i class="fa-solid fa-paper-plane mr-2"></i> Envoyer la demande
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </section>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const typeField = document.querySelector('input[name="type"][data-search-value]');
    const root = document.getElementById('objectSearch');
    const input = document.getElementById('objectInput');
    const hidden = document.getElementById('objectRef');
    const menu = document.getElementById('objectMenu');
    const toggle = document.getElementById('objectToggle');
    const quantity = document.getElementById('quantityField');
    const allDebourses = <?= json_encode($debourseOptions, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?: '[]' ?>;
    const allChantiers = <?= json_encode(array_map(static function($chantier) {
        $label = trim((string)($chantier['label'] ?? ''));
        return ['value' => $label, 'label' => $label, 'chantier_id' => (int)($chantier['id'] ?? 0)];
    }, $chantiers), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?: '[]' ?>;
    const chantierField = document.querySelector('input[name="site_label"][data-search-value]');
    const debourseField = document.querySelector('input[name="ligne_debourse_id"][data-search-value]');
    const objects = JSON.parse(root.dataset.objects || '[]');
    const normalize = value => String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
    const stockTypes = ['materiaux', 'materiel', 'piece', 'outillage', 'consommable'];
    const typeValue = () => typeField ? String(typeField.value || '') : '';

    function closeMenu() { menu.classList.add('hidden'); }
    function syncQuantity() {
        const selected = objects.find(item => item.value === hidden.value);
        const isStock = selected && selected.source === 'stock';
        quantity.required = !!isStock;
        quantity.closest('label').classList.toggle('opacity-60', !isStock);
    }
    function rows() {
        const type = typeValue();
        const query = normalize(input.value);
        return objects.filter(item => {
            const sameType = !type || normalize(item.type).includes(normalize(type)) || (stockTypes.includes(type) && item.source === 'stock');
            return sameType && (!query || normalize(item.label + ' ' + item.meta).includes(query));
        }).slice(0, 100);
    }
    function renderMenu() {
        hidden.value = input.value ? hidden.value : '';
        menu.innerHTML = '';
        if (!typeValue()) {
            menu.innerHTML = '<div class="px-3 py-2 text-sm text-slate-400">Choisissez un type de demande.</div>';
            menu.classList.remove('hidden');
            return;
        }
        const matches = rows();
        if (!matches.length) menu.innerHTML = '<div class="px-3 py-2 text-sm text-slate-400">Aucun element trouve.</div>';
        matches.forEach(item => {
            const button = document.createElement('button');
            button.type = 'button';
            button.disabled = !!item.occupied;
            button.className = 'block w-full rounded-xl px-3 py-3 text-left transition ' + (item.occupied ? 'cursor-not-allowed bg-slate-50 text-slate-400' : 'text-slate-700 hover:bg-orange-50 hover:text-orange-700');
            button.innerHTML = '<span class="block text-sm font-semibold"></span><span class="mt-1 block text-xs"></span>';
            button.querySelector('span:first-child').textContent = item.label;
            button.querySelector('span:last-child').textContent = item.meta;
            button.addEventListener('mousedown', function(event) {
                event.preventDefault();
                if (item.occupied) return;
                hidden.value = item.value;
                input.value = item.label;
                syncQuantity();
                closeMenu();
            });
            menu.appendChild(button);
        });
        menu.classList.remove('hidden');
    }
    if (typeField) typeField.addEventListener('change', function() { hidden.value = ''; input.value = ''; syncQuantity(); renderMenu(); });
    input.addEventListener('focus', renderMenu);
    input.addEventListener('input', function() { hidden.value = ''; syncQuantity(); renderMenu(); });
    input.addEventListener('blur', () => window.setTimeout(closeMenu, 140));
    toggle.addEventListener('click', function() { input.focus(); renderMenu(); });
    syncQuantity();
    window.setTimeout(function() {
        if (!chantierField || !debourseField) return;
        const chantierRoot = chantierField.closest('.logi-search-select');
        const debourseRoot = debourseField.closest('.logi-search-select');
        const debourseInput = debourseRoot ? debourseRoot.querySelector('[data-search-input]') : null;
        function chantierIdFromValue() {
            const selected = allChantiers.find(item => item.value === chantierField.value || item.label === chantierField.value);
            return selected ? String(selected.chantier_id) : '';
        }
        function refreshDebourses(chantierId) {
            const options = chantierId ? allDebourses.filter(option => String(option.chantier_id) === chantierId) : [];
            debourseRoot.dispatchEvent(new CustomEvent('logi:update-options', { detail: { options: [{value: '', label: 'Aucun debourse'}].concat(options) } }));
            if (debourseInput) debourseInput.placeholder = chantierId ? 'Rechercher le debourse' : 'Choisir un chantier d abord';
        }
        if (chantierRoot) chantierRoot.addEventListener('logi:selected', event => refreshDebourses(String((event.detail && event.detail.chantier_id) || chantierIdFromValue())));
        refreshDebourses(chantierIdFromValue());
    }, 0);
});
</script>
<?php logi_search_select_assets(); require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>
