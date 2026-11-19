<?php
require_once __DIR__ . '/../auth_check.php';
AccessManager::requirePageAccess();
require_once __DIR__ . '/../controllers/CrmController.php';
require_once __DIR__ . '/_crm_ui.php';
$pageTitle = 'CRM - RDV';
$activeMenu = 'crm';
$crmController = new CrmController();
$flashSuccess = (string)($_SESSION['crm_flash_success'] ?? '');
$flashError = (string)($_SESSION['crm_flash_error'] ?? '');
unset($_SESSION['crm_flash_success'], $_SESSION['crm_flash_error']);
require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
$rdvs = $crmController->listUpcomingRdv(120);
?>
<?= crm_page_open() ?>
<?= crm_hero('CRM - Rendez-vous', 'Agenda commercial', 'Les rendez-vous a venir, exportables et relies a vos actions commerciales.', [
 ['href'=>'rdv_form.php','label'=>'Nouveau RDV','icon'=>'fa-calendar-plus','variant'=>'primary'],
 ['href'=>'/emploi_du_temps/index.php','label'=>'Emploi du temps','icon'=>'fa-calendar-days','variant'=>'light'],
]) ?>
<?= crm_module_tabs('rdv') ?><?= crm_flash_box($flashSuccess) ?><?= crm_flash_box($flashError,'error') ?>
<section class="<?= crm_card_class() ?>"><div class="mb-5 flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between"><div><p class="text-xs font-semibold uppercase tracking-[0.24em] text-orange-600">Agenda direction</p><h2 class="mt-1 text-2xl font-semibold text-slate-950">RDV a venir</h2><p class="text-sm text-slate-500"><?= count($rdvs) ?> lignes affichees</p></div><div class="flex flex-wrap gap-2"><?= crm_action_button('rdv_form.php','Planifier','fa-calendar-plus','primary') ?><?= crm_export_buttons('rdv') ?></div></div><div class="overflow-x-auto rounded-2xl border border-slate-100"><table class="min-w-full text-sm"><thead class="bg-slate-950 text-white"><tr><th class="px-4 py-3 text-left">Date</th><th class="px-4 py-3 text-left">Titre</th><th class="px-4 py-3 text-left">Lie a</th><th class="px-4 py-3 text-left">Lieu</th><th class="px-4 py-3 text-left">Statut</th></tr></thead><tbody class="divide-y bg-white"><?php if(!$rdvs): ?><tr><td colspan="5" class="px-4 py-8 text-center text-slate-500">Aucun RDV planifie.</td></tr><?php endif; ?><?php foreach($rdvs as $r): ?><tr class="hover:bg-orange-50/60"><td class="px-4 py-3 text-slate-600"><?= crm_h($r['date_debut'] ?? '') ?></td><td class="px-4 py-3 font-semibold text-slate-900"><?= crm_h($r['titre'] ?? '') ?></td><td class="px-4 py-3 text-slate-600"><span class="rounded-full border border-slate-200 bg-slate-50 px-2 py-1 text-xs"><?= crm_h($r['linked_type'] ?? '') ?></span> <?= crm_h($r['linked_name'] ?? '') ?></td><td class="px-4 py-3 text-slate-600"><?= crm_h($r['lieu'] ?? '') ?></td><td class="px-4 py-3 text-slate-600"><?= crm_h($r['statut'] ?? '') ?></td></tr><?php endforeach; ?></tbody></table></div></section>
<?= crm_page_close() ?>
<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>
