<?php
require_once __DIR__ . '/_logistics_common.php';

$personnel = logi_rh_personnel($pdo);
$chantiers = logi_chantiers($pdo);
$debourseOptions = logi_debourse_options($pdo);
$formError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $siteLabel = trim((string)($_POST['site_label'] ?? ''));
    $selectedChantierId = 0;
    foreach ($chantiers as $chantier) {
        if (trim((string)($chantier['label'] ?? '')) === $siteLabel) {
            $selectedChantierId = (int)($chantier['id'] ?? 0);
            break;
        }
    }
    $ligneDebourseId = (int)($_POST['ligne_debourse_id'] ?? 0);
    $selectedDebourse = null;
    foreach ($debourseOptions as $option) {
        if ((int)$option['value'] === $ligneDebourseId) {
            $selectedDebourse = $option;
            break;
        }
    }
    if ($siteLabel === '' || $selectedChantierId <= 0) {
        $formError = 'Veuillez selectionner le chantier de la vague.';
    } elseif (!$selectedDebourse || (int)($selectedDebourse['chantier_id'] ?? 0) !== $selectedChantierId) {
        $formError = 'Veuillez selectionner un debourse lie au chantier choisi.';
    } else {
        $responsiblePersonnelId = (int)($_POST['responsible_personnel_id'] ?? 0);
        $responsibleName = '';
        foreach ($personnel as $person) {
            if ((int)($person['id'] ?? 0) === $responsiblePersonnelId) {
                $responsibleName = (string)($person['full_name'] ?? '');
                break;
            }
        }
        $code = 'VAG-' . date('ymd-His');
        $pdo->prepare('INSERT INTO logistics_trip_batches(batch_code,chantier_id,site_label,debourse_id,ligne_debourse_id,debourse_label,material_label,planned_quantity,unit_label,loading_point,delivery_point,planned_start_at,planned_end_at,responsible_personnel_id,responsible_name,status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([
            $code,
            $selectedChantierId,
            $siteLabel,
            (int)($selectedDebourse['debourse_id'] ?? 0) ?: null,
            $ligneDebourseId,
            $selectedDebourse['label'] ?? null,
            $_POST['material_label'] ?? null,
            (float)($_POST['planned_quantity'] ?? 0),
            $_POST['unit_label'] ?? null,
            $_POST['loading_point'] ?? null,
            $_POST['delivery_point'] ?? null,
            $_POST['planned_start_at'] ?: null,
            $_POST['planned_end_at'] ?: null,
            $responsiblePersonnelId > 0 ? $responsiblePersonnelId : null,
            $responsibleName !== '' ? $responsibleName : null,
            $_POST['status'] ?? 'planned',
        ]);
        logi_audit($pdo, 'create_trip_batch', 'logistics_trip_batch', (int)$pdo->lastInsertId(), $code, ['chantier_id' => $selectedChantierId, 'ligne_debourse_id' => $ligneDebourseId]);
        logi_redirect('trip_batches.php?ok=1');
    }
}

logi_page_start('Nouvelle vague de voyages', 'Formulaire dedie pour rattacher une vague a un chantier et a un debourse.');
?>
<?php if ($formError): ?>
    <div class="mb-6 rounded-2xl border border-rose-100 bg-rose-50 px-5 py-4 text-sm font-semibold text-rose-700"><?= logi_h($formError) ?></div>
<?php endif; ?>

<?php logi_card_open('Creer une vague', 'Selectionnez le chantier puis le debourse rattache a ce chantier.'); ?>
<form method="post" class="grid md:grid-cols-2 gap-5" id="batchForm">
    <?= logi_chantier_search_input('Chantier', 'site_label', $chantiers, (string)($_POST['site_label'] ?? ''), 'required') ?>
    <?= logi_search_select('Debourse lie', 'ligne_debourse_id', [], (string)($_POST['ligne_debourse_id'] ?? ''), 'required', 'Choisir un chantier d abord') ?>
    <?= logi_input('Materiau / objet transporte', 'material_label', (string)($_POST['material_label'] ?? '')) ?>
    <?= logi_input('Quantite prevue', 'planned_quantity', (string)($_POST['planned_quantity'] ?? '0'), 'number', 'step="0.01"') ?>
    <?= logi_select('Unite', 'unit_label', ['tonnes'=>'Tonnes','m3'=>'m3','voyages'=>'Voyages','unites'=>'Unites'], (string)($_POST['unit_label'] ?? '')) ?>
    <?= logi_input('Point chargement', 'loading_point', (string)($_POST['loading_point'] ?? '')) ?>
    <?= logi_input('Point livraison', 'delivery_point', (string)($_POST['delivery_point'] ?? '')) ?>
    <?= logi_input('Debut prevu', 'planned_start_at', (string)($_POST['planned_start_at'] ?? ''), 'datetime-local') ?>
    <?= logi_input('Fin prevue', 'planned_end_at', (string)($_POST['planned_end_at'] ?? ''), 'datetime-local') ?>
    <?= logi_search_select('Responsable', 'responsible_personnel_id', array_map(fn($p) => ['value' => (string)$p['id'], 'label' => trim((string)$p['full_name'] . (!empty($p['service_label']) ? ' - ' . $p['service_label'] : '') . (!empty($p['phone']) ? ' - ' . $p['phone'] : ''))], $personnel), (string)($_POST['responsible_personnel_id'] ?? ''), 'required', 'Rechercher un membre du personnel') ?>
    <?= logi_select('Statut', 'status', ['planned'=>'Planifiee','in_progress'=>'En cours','completed'=>'Terminee','cancelled'=>'Annulee'], (string)($_POST['status'] ?? 'planned')) ?>
    <div class="md:col-span-2 flex justify-end gap-3"><a href="trip_batches.php" class="rounded-2xl border border-slate-200 bg-white px-5 py-3 font-semibold text-slate-700">Annuler</a><?= logi_submit('Creer la vague') ?></div>
</form>
<?php logi_card_close(); ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const allDebourses = <?= json_encode($debourseOptions, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?: '[]' ?>;
    const allChantiers = <?= json_encode(array_map(function($chantier) {
        $label = trim((string)($chantier['label'] ?? ''));
        return ['value' => $label, 'label' => $label, 'chantier_id' => (int)($chantier['id'] ?? 0)];
    }, $chantiers), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?: '[]' ?>;
    const chantierField = document.querySelector('input[name="site_label"][data-search-value]');
    const debourseField = document.querySelector('input[name="ligne_debourse_id"][data-search-value]');
    if (!chantierField || !debourseField) return;
    const chantierRoot = chantierField.closest('.logi-search-select');
    const debourseRoot = debourseField.closest('.logi-search-select');
    const debourseInput = debourseRoot ? debourseRoot.querySelector('[data-search-input]') : null;
    function chantierIdFromValue() {
        const selected = allChantiers.find(function(chantier) { return chantier.value === chantierField.value || chantier.label === chantierField.value; });
        return selected ? String(selected.chantier_id) : '';
    }
    function refreshDebourses(chantierId) {
        const options = chantierId ? allDebourses.filter(function(option) { return String(option.chantier_id) === chantierId; }) : [];
        debourseRoot.dispatchEvent(new CustomEvent('logi:update-options', { detail: { options: options } }));
        if (debourseInput) debourseInput.placeholder = chantierId ? 'Rechercher le debourse' : 'Choisir un chantier d abord';
    }
    if (chantierRoot) chantierRoot.addEventListener('logi:selected', function(event) { refreshDebourses(String((event.detail && event.detail.chantier_id) || chantierIdFromValue())); });
    refreshDebourses(chantierIdFromValue());
});
</script>
<?php logi_page_end(); ?>
