<?php
require_once __DIR__ . '/../auth_check.php';
AccessManager::requirePageAccess();
require_once __DIR__ . '/../controllers/CrmController.php';
require_once __DIR__ . '/_crm_ui.php';
$pageTitle = 'CRM - Clients';
$activeMenu = 'crm';
$crmController = new CrmController();
$flashSuccess = (string)($_SESSION['crm_flash_success'] ?? '');
$flashError = (string)($_SESSION['crm_flash_error'] ?? '');
unset($_SESSION['crm_flash_success'], $_SESSION['crm_flash_error']);
require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
$clients = $crmController->listLatestClients(300);
?>
<?= crm_page_open() ?>
<?= crm_hero('CRM - Clients', 'Portefeuille client', 'Une base client sobre, elegante et exportable pour les comites de direction commerciale.', [
 ['href'=>'client_form.php','label'=>'Nouveau client','icon'=>'fa-building-user','variant'=>'primary'],
 ['href'=>'rdv_form.php?type=client','label'=>'Planifier RDV','icon'=>'fa-calendar-plus','variant'=>'light'],
]) ?>
<?= crm_module_tabs('clients') ?><?= crm_flash_box($flashSuccess) ?><?= crm_flash_box($flashError,'error') ?>
<section class="<?= crm_card_class() ?>"><div class="mb-5 flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between"><div><p class="text-xs font-semibold uppercase tracking-[0.24em] text-orange-600">Listing direction</p><h2 class="mt-1 text-2xl font-semibold text-slate-950">Clients</h2><p class="text-sm text-slate-500"><?= count($clients) ?> lignes affichees</p></div><div class="flex flex-wrap gap-2"><?= crm_action_button('client_form.php','Ajouter','fa-plus','primary') ?><?= crm_export_buttons('clients') ?></div></div><div class="overflow-x-auto rounded-2xl border border-slate-100"><table class="min-w-full text-sm"><thead class="bg-slate-950 text-white"><tr><th class="px-4 py-3 text-left">Client</th><th class="px-4 py-3 text-left">Code</th><th class="px-4 py-3 text-left">Telephone</th><th class="px-4 py-3 text-left">Commune</th><th class="px-4 py-3 text-left">Pays</th></tr></thead><tbody class="divide-y bg-white"><?php if(!$clients): ?><tr><td colspan="5" class="px-4 py-8 text-center text-slate-500">Aucun client.</td></tr><?php endif; ?><?php foreach($clients as $c): ?><tr class="hover:bg-orange-50/60"><td class="px-4 py-3 font-semibold text-slate-900"><?= crm_h($c['nom_client'] ?? '') ?></td><td class="px-4 py-3 text-slate-600"><?= crm_h($c['code_client'] ?? '') ?></td><td class="px-4 py-3 text-slate-600"><?= crm_h($c['telephone_client'] ?? '') ?></td><td class="px-4 py-3 text-slate-600"><?= crm_h($c['commune_client'] ?? '') ?></td><td class="px-4 py-3 text-slate-600"><?= crm_h($c['pays_client'] ?? '') ?></td></tr><?php endforeach; ?></tbody></table></div></section>
<?= crm_page_close() ?>
<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>
