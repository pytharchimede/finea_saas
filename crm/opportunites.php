<?php
require_once __DIR__ . '/../auth_check.php';
AccessManager::requirePageAccess();
require_once __DIR__ . '/../controllers/CrmController.php';
require_once __DIR__ . '/_crm_ui.php';
$pageTitle = 'CRM - Opportunites';
$activeMenu = 'crm';
$crmController = new CrmController();
$flashSuccess = (string)($_SESSION['crm_flash_success'] ?? '');
$flashError = (string)($_SESSION['crm_flash_error'] ?? '');
unset($_SESSION['crm_flash_success'], $_SESSION['crm_flash_error']);
$allowedEtapes = ['Prospection', 'Qualification', 'Proposition', 'Negociation', 'Gagne', 'Perdu'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'update') {
    $result = $crmController->updateOpportunityStage($_POST);
    $_SESSION[!empty($result['ok']) ? 'crm_flash_success' : 'crm_flash_error'] = (string)($result['message'] ?? 'Operation terminee.');
    header('Location: opportunites.php'); exit;
}
function crm_format_xof($value): string { return ($value === null || $value === '') ? '' : number_format((float)$value, 0, ',', ' ') . ' XOF'; }
function crm_stage_key(string $stage): string { $s=strtolower(trim($stage)); if($s==='nouveau')return'Prospection'; if(str_contains($s,'devis'))return'Proposition'; if(str_contains($s,'gagn'))return'Gagne'; if(str_contains($s,'perdu'))return'Perdu'; if(str_contains($s,'nego'))return'Negociation'; if(str_contains($s,'qualification'))return'Qualification'; return in_array($stage,['Prospection','Qualification','Proposition','Negociation','Gagne','Perdu'],true)?$stage:'Prospection'; }
function crm_stage_badge(string $stage): string { if($stage==='Gagne')return'border-emerald-200 bg-emerald-50 text-emerald-800'; if($stage==='Perdu')return'border-rose-200 bg-rose-50 text-rose-800'; if($stage==='Negociation')return'border-sky-200 bg-sky-50 text-sky-800'; return'border-slate-200 bg-slate-50 text-slate-700'; }
require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
$opportunites = $crmController->listRecentOpportunities(220);
$byStage = array_fill_keys($allowedEtapes, []);
foreach ($opportunites as $o) $byStage[crm_stage_key((string)($o['etape'] ?? ''))][] = $o;
?>
<?= crm_page_open() ?>
<?= crm_hero('CRM - Pipeline', 'Kanban opportunites', 'Un pipeline drag-and-drop, rapide, lisible et calibre pour une direction commerciale exigeante.', [
 ['href'=>'opportunite_form.php','label'=>'Nouvelle opportunite','icon'=>'fa-plus','variant'=>'primary'],
 ['href'=>'dashboard_crm.php','label'=>'Pilotage','icon'=>'fa-gauge-high','variant'=>'light'],
]) ?>
<?= crm_module_tabs('opportunites') ?><?= crm_flash_box($flashSuccess) ?><?= crm_flash_box($flashError,'error') ?>
<section class="<?= crm_card_class() ?>"><div class="mb-5 flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between"><div><p class="text-xs font-semibold uppercase tracking-[0.24em] text-orange-600">Pipeline dynamique</p><h2 class="mt-1 text-2xl font-semibold text-slate-950">Glisser-deposer les cartes entre les etapes</h2><p class="text-sm text-slate-500"><?= count($opportunites) ?> opportunites affichees</p></div><div class="flex flex-wrap gap-2"><?= crm_action_button('opportunite_form.php','Ajouter','fa-plus','primary') ?><?= crm_export_buttons('opportunites') ?></div></div>
<div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-6"><?php foreach($allowedEtapes as $stage): ?><div class="crm-kanban-column rounded-[26px] border border-white/80 bg-slate-50/90 p-4 shadow-[0_12px_35px_rgba(15,23,42,0.05)]" data-crm-drop-stage="<?= crm_h($stage) ?>"><div class="mb-3 flex items-center justify-between"><strong class="text-slate-950"><?= crm_h($stage) ?></strong><span class="rounded-full bg-white px-2 py-1 text-xs font-bold text-slate-500"><?= count($byStage[$stage]) ?></span></div><span class="inline-flex rounded-full border px-2 py-1 text-xs <?= crm_stage_badge($stage) ?>">Etape</span><div class="mt-3 min-h-24 space-y-3"><?php if(!$byStage[$stage]): ?><div class="rounded-2xl border border-dashed border-slate-200 p-5 text-center text-xs font-semibold text-slate-400">Deposer ici</div><?php endif; ?><?php foreach($byStage[$stage] as $o): ?><article class="crm-kanban-card rounded-2xl border border-slate-100 bg-white p-4 shadow-sm transition hover:-translate-y-0.5 hover:border-orange-200 hover:shadow-md" data-crm-draggable-id="<?= (int)($o['id'] ?? 0) ?>"><div class="font-semibold text-slate-900"><?= crm_h($o['titre'] ?? '') ?></div><div class="mt-2 text-xs text-slate-600"><?= crm_h($o['linked_type'] ?? '') ?> - <?= crm_h($o['linked_name'] ?? '') ?></div><div class="mt-3 flex justify-between text-xs font-semibold text-slate-600"><span><?= crm_h(crm_format_xof($o['montant_xof'] ?? null)) ?></span><span><?= (int)($o['probabilite'] ?? 0) ?>%</span></div><form method="post" data-crm-stage-form="<?= (int)($o['id'] ?? 0) ?>" class="mt-3 flex gap-2"><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= (int)($o['id'] ?? 0) ?>"><select name="etape" class="min-w-0 flex-1 rounded-xl border border-slate-200 bg-white px-2 py-1 text-xs"><?php foreach($allowedEtapes as $e): ?><option value="<?= crm_h($e) ?>" <?= crm_stage_key((string)($o['etape'] ?? ''))===$e?'selected':'' ?>><?= crm_h($e) ?></option><?php endforeach; ?></select><button class="rounded-xl border border-slate-200 px-3 py-1 text-xs font-semibold">OK</button></form></article><?php endforeach; ?></div></div><?php endforeach; ?></div></section>
<?= crm_page_close() ?><?= crm_interaction_assets() ?>
<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>
