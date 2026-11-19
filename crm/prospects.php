<?php
require_once __DIR__ . '/../auth_check.php';
AccessManager::requirePageAccess();
require_once __DIR__ . '/../controllers/CrmController.php';
require_once __DIR__ . '/_crm_ui.php';
$pageTitle = 'CRM - Prospects';
$activeMenu = 'crm';
$crmController = new CrmController();
$flashSuccess = (string)($_SESSION['crm_flash_success'] ?? '');
$flashError = (string)($_SESSION['crm_flash_error'] ?? '');
unset($_SESSION['crm_flash_success'], $_SESSION['crm_flash_error']);
require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
$prospects = $crmController->listLatestProspects(300);
?>
<?= crm_page_open() ?>
<?= crm_hero('CRM - Prospects', 'Portefeuille prospects', 'Une vue commerciale claire pour trier, qualifier, exporter et declencher les prochaines actions.', [
    ['href'=>'prospect_form.php','label'=>'Nouveau prospect','icon'=>'fa-user-plus','variant'=>'primary'],
    ['href'=>'opportunite_form.php','label'=>'Creer opportunite','icon'=>'fa-chart-line','variant'=>'light'],
]) ?>
<?= crm_module_tabs('prospects') ?><?= crm_flash_box($flashSuccess) ?><?= crm_flash_box($flashError,'error') ?>
<section class="<?= crm_card_class() ?>">
<div class="mb-5 flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between"><div><p class="text-xs font-semibold uppercase tracking-[0.24em] text-orange-600">Listing direction</p><h2 class="mt-1 text-2xl font-semibold text-slate-950">Prospects</h2><p class="text-sm text-slate-500"><?= count($prospects) ?> lignes affichees</p></div><div class="flex flex-wrap gap-2"><?= crm_action_button('prospect_form.php','Ajouter','fa-user-plus','primary') ?><?= crm_export_buttons('prospects') ?></div></div>
<div class="overflow-x-auto rounded-2xl border border-slate-100"><table class="min-w-full text-sm"><thead class="bg-slate-950 text-white"><tr><th class="px-4 py-3 text-left">Nom</th><th class="px-4 py-3 text-left">Societe</th><th class="px-4 py-3 text-left">Code</th><th class="px-4 py-3 text-left">Telephone</th><th class="px-4 py-3 text-left">Statut</th><th class="px-4 py-3 text-left">Actions</th></tr></thead><tbody class="divide-y bg-white"><?php if(!$prospects): ?><tr><td colspan="6" class="px-4 py-8 text-center text-slate-500">Aucun prospect.</td></tr><?php endif; ?><?php foreach($prospects as $p): ?><tr class="hover:bg-orange-50/60"><td class="px-4 py-3 font-semibold text-slate-900"><?= crm_h($p['nom'] ?? '') ?></td><td class="px-4 py-3 text-slate-600"><?= crm_h($p['societe'] ?? '') ?></td><td class="px-4 py-3 text-slate-600"><?= crm_h($p['code_client'] ?? '') ?></td><td class="px-4 py-3 text-slate-600"><?= crm_h($p['telephone'] ?? '') ?></td><td class="px-4 py-3"><span class="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-semibold"><?= crm_h($p['statut'] ?? '') ?></span></td><td class="px-4 py-3"><div class="flex gap-3"><a class="font-semibold text-orange-700" href="rdv_form.php?type=prospect&prospect_id=<?= (int)($p['id'] ?? 0) ?>">RDV</a><a class="font-semibold text-[#1d2b57]" href="opportunite_form.php?type=prospect&prospect_id=<?= (int)($p['id'] ?? 0) ?>">Opportunite</a></div></td></tr><?php endforeach; ?></tbody></table></div>
</section>
<?= crm_page_close() ?>
<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>
