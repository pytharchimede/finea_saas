<?php
require_once __DIR__ . '/../auth_check.php';
AccessManager::requirePageAccess();
require_once __DIR__ . '/../controllers/CrmController.php';
require_once __DIR__ . '/_crm_ui.php';

$pageTitle = 'CRM - Nouveau prospect';
$activeMenu = 'crm';
$crmController = new CrmController();
$clients = $crmController->listClientsForProspectForm(2000);
$clientsById = [];
foreach ($clients as $c) {
    $id = (int)($c['id_client'] ?? 0);
    if ($id > 0) {
        $clientsById[(string)$id] = [
            'nom_client' => (string)($c['nom_client'] ?? ''),
            'code_client' => (string)($c['code_client'] ?? ''),
            'telephone_client' => (string)($c['telephone_client'] ?? ''),
            'localisation_client' => (string)($c['localisation_client'] ?? ''),
            'commune_client' => (string)($c['commune_client'] ?? ''),
            'bp_client' => (string)($c['bp_client'] ?? ''),
            'pays_client' => (string)($c['pays_client'] ?? ''),
        ];
    }
}
$clientsJson = json_encode($clientsById, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = $crmController->createProspect($_POST, $_SESSION);
    $_SESSION[!empty($result['ok']) ? 'crm_flash_success' : 'crm_flash_error'] = (string)($result['message'] ?? 'Operation terminee.');
    header('Location: prospects.php');
    exit;
}
require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>
<?= crm_page_open() ?>
<?= crm_hero('CRM - Saisie prospect', 'Nouveau prospect', 'Une fiche de qualification claire, rapide et agreable pour alimenter le portefeuille commercial.', [
    ['href' => 'prospects.php', 'label' => 'Retour listing', 'icon' => 'fa-arrow-left', 'variant' => 'ghost'],
    ['href' => 'opportunites.php', 'label' => 'Pipeline', 'icon' => 'fa-chart-line', 'variant' => 'primary'],
]) ?>
<?= crm_module_tabs('prospects') ?>
<section class="<?= crm_card_class() ?>">
    <div class="mb-5 flex items-center justify-between gap-3"><div><p class="text-xs font-semibold uppercase tracking-[0.24em] text-orange-600">Saisie commerciale</p><h2 class="mt-1 text-2xl font-semibold text-slate-950">Fiche prospect</h2></div><span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-bold text-amber-800">Qualification</span></div>
    <form method="post" id="prospectForm" class="grid grid-cols-1 gap-5 lg:grid-cols-2">
        <input type="hidden" name="client_id" id="clientId" value="">
        <div class="lg:col-span-2 rounded-3xl border border-slate-100 bg-slate-50 p-5">
            <div class="mb-3 font-semibold text-slate-800">Client existant</div>
            <div class="grid gap-4 md:grid-cols-[220px_1fr]">
                <select name="is_existing_client" id="isExistingClient" class="rounded-2xl border border-slate-200 bg-white px-3 py-3 focus:ring-2 focus:ring-amber-200"><option value="0">Nouveau contact</option><option value="1">Client existant</option></select>
                <div class="crm-select-search hidden" id="clientSearchWrap" data-crm-select-search>
                    <input type="hidden" id="clientSearchValue"><input data-crm-select-input type="search" placeholder="Saisir un nom, code ou telephone client...">
                    <div class="crm-select-search-list" data-crm-select-list hidden><?php foreach ($clients as $c): ?><?php $id=(int)($c['id_client'] ?? 0); $label=trim((string)($c['nom_client'] ?? '') . ' - ' . (string)($c['code_client'] ?? '') . ' - ' . (string)($c['telephone_client'] ?? '')); ?><button type="button" class="crm-select-search-option" data-crm-select-option data-value="<?= $id ?>"><?= crm_h($label) ?></button><?php endforeach; ?></div>
                </div>
            </div>
        </div>
        <?php $input='class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-3 focus:outline-none focus:ring-2 focus:ring-amber-200"'; ?>
        <label class="text-sm font-semibold text-slate-700">Nom / contact *<input name="nom" required <?= $input ?>></label>
        <label class="text-sm font-semibold text-slate-700">Societe<input name="societe" id="societe" <?= $input ?>></label>
        <label class="text-sm font-semibold text-slate-700">Code client<input name="code_client" id="code_client" <?= $input ?>></label>
        <label class="text-sm font-semibold text-slate-700">Telephone<input name="telephone" id="telephone" <?= $input ?>></label>
        <label class="text-sm font-semibold text-slate-700">Localisation<input name="localisation" id="localisation" <?= $input ?>></label>
        <label class="text-sm font-semibold text-slate-700">Commune<input name="commune" id="commune" <?= $input ?>></label>
        <label class="text-sm font-semibold text-slate-700">BP<input name="bp" id="bp" <?= $input ?>></label>
        <label class="text-sm font-semibold text-slate-700">Pays<input name="pays" id="pays" <?= $input ?>></label>
        <label class="text-sm font-semibold text-slate-700">Email<input name="email" type="email" <?= $input ?>></label>
        <label class="text-sm font-semibold text-slate-700">Source<input name="source" list="crmSourceOptions" placeholder="Recommandation, appel, salon..." <?= $input ?>></label>
        <datalist id="crmSourceOptions"><option value="Recommandation"><option value="Appel entrant"><option value="Prospection terrain"><option value="Email"><option value="Reseaux sociaux"><option value="Salon professionnel"></datalist>
        <label class="text-sm font-semibold text-slate-700">Statut<select name="statut" class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 focus:ring-2 focus:ring-amber-200"><option>Nouveau</option><option>Qualifie</option><option>En negociation</option><option>Gagne</option><option>Perdu</option></select></label>
        <label class="lg:col-span-2 text-sm font-semibold text-slate-700">Notes<textarea name="notes" rows="4" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-3 focus:ring-2 focus:ring-amber-200"></textarea></label>
        <div class="lg:col-span-2 flex justify-end gap-3"><a href="prospects.php" class="rounded-2xl border border-slate-200 bg-white px-5 py-3 font-semibold text-slate-700">Annuler</a><button class="rounded-2xl bg-[#fabd02] px-6 py-3 font-bold text-slate-950 hover:bg-amber-300">Enregistrer le prospect</button></div>
    </form>
</section>
<?= crm_page_close() ?>
<?= crm_interaction_assets() ?>
<script>
window.CRM_CLIENTS = <?= $clientsJson ?>;
document.addEventListener('DOMContentLoaded', function(){
  const mode = document.getElementById('isExistingClient');
  const wrap = document.getElementById('clientSearchWrap');
  const hiddenClientId = document.getElementById('clientId');
  const value = document.getElementById('clientSearchValue');
  function syncMode(){ wrap.classList.toggle('hidden', mode.value !== '1'); if(mode.value !== '1'){ hiddenClientId.value=''; } }
  mode.addEventListener('change', syncMode); syncMode();
  value.addEventListener('change', function(){
    const id = value.value; hiddenClientId.value = id; const c = window.CRM_CLIENTS[id] || {};
    [['societe','nom_client'],['code_client','code_client'],['telephone','telephone_client'],['localisation','localisation_client'],['commune','commune_client'],['bp','bp_client'],['pays','pays_client']].forEach(([field,key]) => { const el=document.getElementById(field); if(el) el.value=c[key]||''; });
  });
});
</script>
<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>
