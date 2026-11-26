<?php
require_once __DIR__ . '/../auth_check.php';
AccessManager::requirePageAccess();
require_once __DIR__ . '/../controllers/CrmController.php';
require_once __DIR__ . '/_crm_ui.php';
$pageTitle = 'CRM - Nouvelle opportunite';
$activeMenu = 'crm';
$crmController = new CrmController();
$prefType = (string)($_GET['type'] ?? 'prospect');
$prefProspectId = (int)($_GET['prospect_id'] ?? 0);
$clients = $crmController->listClientsForSelect(1200);
$prospects = $crmController->listProspectsForSelect(1200);
$allowedEtapes = ['Prospection', 'Qualification', 'Proposition', 'Negociation', 'Gagne', 'Perdu'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = $crmController->createOpportunity($_POST, $_SESSION);
    $_SESSION[!empty($result['ok']) ? 'crm_flash_success' : 'crm_flash_error'] = (string)($result['message'] ?? 'Operation terminee.');
    if (!empty($result['warning'])) $_SESSION['crm_flash_error'] = 'Emploi du temps : ' . (string)$result['warning'];
    header('Location: opportunites.php'); exit;
}
require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>
<?= crm_page_open() ?>
<?= crm_hero('CRM - Affaire', 'Nouvelle opportunite', 'Creez une opportunite propre, rattachee au bon tiers, avec prochaine action et montant previsionnel.', [
 ['href'=>'opportunites.php','label'=>'Retour Kanban','icon'=>'fa-arrow-left','variant'=>'ghost'],
 ['href'=>'rdv_form.php','label'=>'Planifier RDV','icon'=>'fa-calendar-plus','variant'=>'primary'],
]) ?>
<?= crm_module_tabs('opportunites') ?>
<section class="<?= crm_card_class() ?>"><form method="post" id="oppForm" class="grid grid-cols-1 gap-5 lg:grid-cols-2"><input type="hidden" name="action" value="create">
<label class="text-sm font-semibold text-slate-700">Lie a<select name="linked_type" id="linkedType" class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 focus:ring-2 focus:ring-amber-200"><option value="prospect" <?= $prefType==='prospect'?'selected':'' ?>>Prospect</option><option value="client" <?= $prefType==='client'?'selected':'' ?>>Client</option></select></label>
<div id="prospectWrap" class="crm-select-search" data-crm-select-search><label class="text-sm font-semibold text-slate-700">Prospect</label><input type="hidden" name="prospect_id" value="<?= $prefProspectId ?>"><input data-crm-select-input type="search" placeholder="Saisir le nom du prospect..."><div class="crm-select-search-list" data-crm-select-list hidden><?php foreach($prospects as $p): ?><?php $label=trim((string)($p['nom']??'') . (!empty($p['societe']) ? ' - ' . (string)$p['societe'] : '')); ?><button type="button" class="crm-select-search-option" data-crm-select-option data-value="<?= (int)$p['id'] ?>"><?= crm_h($label) ?></button><?php endforeach; ?></div></div>
<div id="clientWrap" class="crm-select-search hidden" data-crm-select-search><label class="text-sm font-semibold text-slate-700">Client</label><input type="hidden" name="client_id"><input data-crm-select-input type="search" placeholder="Saisir le nom du client..."><div class="crm-select-search-list" data-crm-select-list hidden><?php foreach($clients as $c): ?><button type="button" class="crm-select-search-option" data-crm-select-option data-value="<?= (int)$c['id_client'] ?>"><?= crm_h($c['nom_client']??'') ?></button><?php endforeach; ?></div></div>
<?php $input='class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-3 focus:ring-2 focus:ring-amber-200"'; ?>
<label class="text-sm font-semibold text-slate-700">Titre *<input name="titre" required <?= $input ?>></label>
<label class="text-sm font-semibold text-slate-700">Etape<select name="etape" class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-4 py-3"><?php foreach($allowedEtapes as $e): ?><option value="<?= crm_h($e) ?>"><?= crm_h($e) ?></option><?php endforeach; ?></select></label>
<label class="text-sm font-semibold text-slate-700">Montant XOF<input name="montant_xof" type="number" min="0" step="1" <?= $input ?>></label>
<label class="text-sm font-semibold text-slate-700">Probabilite (%)<input name="probabilite" type="number" min="0" max="100" step="1" value="10" <?= $input ?>></label>
<label class="text-sm font-semibold text-slate-700">Prochaine action<input name="next_action_at" type="datetime-local" <?= $input ?>></label>
<label class="text-sm font-semibold text-slate-700">Duree (minutes)<input name="next_action_duration_min" type="number" min="5" max="480" step="5" value="30" <?= $input ?>></label>
<label class="lg:col-span-2 text-sm font-semibold text-slate-700">Notes<textarea name="notes" rows="4" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-3 focus:ring-2 focus:ring-amber-200"></textarea></label>
<div class="lg:col-span-2 flex justify-end gap-3"><a href="opportunites.php" class="rounded-2xl border border-slate-200 bg-white px-5 py-3 font-semibold text-slate-700">Annuler</a><button class="rounded-2xl crm-accent px-6 py-3 font-bold text-slate-950 hover:bg-amber-300">Creer l'opportunite</button></div>
</form></section>
<?= crm_page_close() ?><?= crm_interaction_assets() ?>
<script>document.addEventListener('DOMContentLoaded',()=>{const t=document.getElementById('linkedType'),p=document.getElementById('prospectWrap'),c=document.getElementById('clientWrap');function sync(){p.classList.toggle('hidden',t.value==='client');c.classList.toggle('hidden',t.value!=='client')}t.addEventListener('change',sync);sync();});</script>
<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>
