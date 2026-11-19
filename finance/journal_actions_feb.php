<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess('validation_demandes_finances.php');
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../services/FebFinancialJournalService.php';
require_once __DIR__ . '/../services/FebWorkflowMigrationService.php';

$pdo = \Database::getConnection();
FebWorkflowMigrationService::ensure($pdo);
$filters = [
    'date_from' => trim((string)($_GET['date_from'] ?? $_GET['date'] ?? date('Y-m-d'))),
    'date_to' => trim((string)($_GET['date_to'] ?? $_GET['date'] ?? date('Y-m-d'))),
    'action' => trim((string)($_GET['action'] ?? '')),
    'submitter' => trim((string)($_GET['submitter'] ?? '')),
    'validator' => trim((string)($_GET['validator'] ?? '')),
    'service' => trim((string)($_GET['service'] ?? '')),
    'chantier' => trim((string)($_GET['chantier'] ?? '')),
    'debourse' => trim((string)($_GET['debourse'] ?? $_GET['debourse_id'] ?? '')),
    'ligne_debourse' => trim((string)($_GET['ligne_debourse'] ?? $_GET['ligne_debourse_id'] ?? '')),
    'q' => trim((string)($_GET['q'] ?? '')),
];
$rows = (new FebFinancialJournalService($pdo))->search($filters);
$failures = count(array_filter($rows, static fn(array $row): bool => !empty($row['is_failure'])));
$generated = count(array_unique(array_filter(array_map(static fn(array $row): string => trim((string)($row['num_fiche'] ?? '')), $rows))));
$actions = FebFinancialJournalService::actionOptions();
foreach ($rows as $row) $actions[(string)$row['event_type']] = (string)$row['action_label'];
asort($actions);
$query = http_build_query(array_filter($filters, static fn($value): bool => $value !== '' && $value !== 0));

$pageTitle = 'Journal financier FEB';
$activeMenu = 'feb_financial_log';
require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>
<main class="min-h-screen bg-slate-50 px-4 py-7 lg:px-8">
 <div class="mx-auto max-w-[1900px]">
  <section class="overflow-hidden rounded-[2rem] bg-gradient-to-r from-slate-950 via-indigo-950 to-indigo-800 p-7 text-white shadow-xl">
   <div class="flex flex-wrap items-end justify-between gap-5">
    <div><div class="text-xs font-black uppercase tracking-[.22em] text-indigo-200">Traçabilité décisionnelle</div><h1 class="mt-2 text-3xl font-black">Journal financier FEB</h1><p class="mt-2 max-w-4xl text-indigo-100">Chaque ligne correspond à une vraie action sur une FEB. Les simples ouvertures de page ne sont plus affichées.</p></div>
    <div class="flex flex-wrap gap-2"><a href="../validation_demandes_finances.php" class="rounded-xl bg-white/10 px-4 py-3 font-bold hover:bg-white/20">Retour aux FEB</a><a href="../request/export_journal_feb.php?format=xlsx&amp;<?=$query?>" class="rounded-xl bg-emerald-500 px-4 py-3 font-bold text-white">Excel</a><a href="../request/export_journal_feb.php?format=pdf&amp;<?=$query?>" target="_blank" class="rounded-xl bg-white px-4 py-3 font-bold text-indigo-950">PDF</a></div>
   </div>
  </section>

  <form method="get" class="mt-6 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
   <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
    <label class="text-xs font-black uppercase text-slate-500">Du<input type="date" name="date_from" value="<?=htmlspecialchars($filters['date_from'])?>" class="mt-2 block w-full rounded-xl border border-slate-200 px-4 py-3 text-sm"></label>
    <label class="text-xs font-black uppercase text-slate-500">Au<input type="date" name="date_to" value="<?=htmlspecialchars($filters['date_to'])?>" class="mt-2 block w-full rounded-xl border border-slate-200 px-4 py-3 text-sm"></label>
    <label class="text-xs font-black uppercase text-slate-500">Action<select name="action" class="mt-2 block w-full rounded-xl border border-slate-200 px-4 py-3 text-sm"><option value="">Toutes les actions</option><?php foreach($actions as $key=>$label):?><option value="<?=htmlspecialchars($key)?>" <?=$filters['action']===$key?'selected':''?>><?=htmlspecialchars($label)?></option><?php endforeach;?></select></label>
    <label class="text-xs font-black uppercase text-slate-500">Soumissionnaire<input name="submitter" value="<?=htmlspecialchars($filters['submitter'])?>" placeholder="Nom du demandeur" class="mt-2 block w-full rounded-xl border border-slate-200 px-4 py-3 text-sm"></label>
    <label class="text-xs font-black uppercase text-slate-500">Validateur<input name="validator" value="<?=htmlspecialchars($filters['validator'])?>" placeholder="Auteur de l’action" class="mt-2 block w-full rounded-xl border border-slate-200 px-4 py-3 text-sm"></label>
    <label class="text-xs font-black uppercase text-slate-500">Service<input name="service" value="<?=htmlspecialchars($filters['service'])?>" placeholder="RH, Logistique…" class="mt-2 block w-full rounded-xl border border-slate-200 px-4 py-3 text-sm"></label>
    <label class="text-xs font-black uppercase text-slate-500">Chantier<input name="chantier" value="<?=htmlspecialchars($filters['chantier'])?>" placeholder="Code ou intitulé" class="mt-2 block w-full rounded-xl border border-slate-200 px-4 py-3 text-sm"></label>
    <label class="text-xs font-black uppercase text-slate-500">Déboursé<input name="debourse" value="<?=htmlspecialchars($filters['debourse'])?>" placeholder="Titre ou identifiant" class="mt-2 block w-full rounded-xl border border-slate-200 px-4 py-3 text-sm"></label>
    <label class="text-xs font-black uppercase text-slate-500">Ligne de déboursé<input name="ligne_debourse" value="<?=htmlspecialchars($filters['ligne_debourse'])?>" placeholder="Libellé ou identifiant" class="mt-2 block w-full rounded-xl border border-slate-200 px-4 py-3 text-sm"></label>
    <label class="text-xs font-black uppercase text-slate-500">Recherche générale<input name="q" value="<?=htmlspecialchars($filters['q'])?>" placeholder="FEB, motif, personne…" class="mt-2 block w-full rounded-xl border border-slate-200 px-4 py-3 text-sm"></label>
   </div>
   <div class="mt-4 flex justify-end gap-2"><a href="journal_actions_feb.php" class="rounded-xl bg-slate-100 px-5 py-3 font-bold text-slate-700">Réinitialiser</a><button class="rounded-xl bg-indigo-600 px-6 py-3 font-bold text-white">Afficher</button></div>
  </form>

  <div class="mt-6 grid gap-4 md:grid-cols-3">
   <div class="rounded-2xl border border-slate-200 bg-white p-5"><div class="text-xs font-black uppercase text-slate-500">Actions métier</div><div class="mt-2 text-3xl font-black"><?=count($rows)?></div></div>
   <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-5"><div class="text-xs font-black uppercase text-emerald-700">Fiches générées</div><div class="mt-2 text-3xl font-black text-emerald-950"><?=$generated?></div></div>
   <div class="rounded-2xl border border-rose-200 bg-rose-50 p-5"><div class="text-xs font-black uppercase text-rose-700">Transactions échouées</div><div class="mt-2 text-3xl font-black text-rose-950"><?=$failures?></div></div>
  </div>

  <section class="mt-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
   <div class="overflow-x-auto"><table class="min-w-full text-sm"><thead class="bg-slate-100 text-left text-xs uppercase tracking-wider text-slate-600"><tr><th class="p-4">Date / FEB</th><th class="p-4">Action réellement effectuée</th><th class="p-4">Soumissionnaire / service</th><th class="p-4">Position financière</th><th class="p-4">Résultat / auteur</th></tr></thead><tbody class="divide-y divide-slate-100">
   <?php foreach($rows as $row): $failed=!empty($row['is_failure']); ?>
    <tr class="align-top hover:bg-slate-50">
     <td class="p-4 whitespace-nowrap"><div class="font-black text-slate-900"><?=htmlspecialchars(date('d/m/Y H:i:s',strtotime((string)$row['created_at'])))?></div><a href="../validation_feb_detail.php?id=<?=(int)$row['feb_id']?>" class="font-bold text-indigo-700">FEB #<?=(int)$row['feb_id']?></a><div class="mt-1 text-xs font-bold text-slate-500"><?=htmlspecialchars((string)$row['status_label'])?></div></td>
     <td class="max-w-xl p-4"><div class="font-black text-slate-950"><?=htmlspecialchars((string)$row['action_label'])?></div><div class="mt-1 text-slate-700"><?=nl2br(htmlspecialchars(trim((string)($row['event_note']??''))?:'Aucune précision enregistrée.'))?></div><div class="mt-2 text-xs text-slate-400"><?=htmlspecialchars((string)$row['event_type'])?></div></td>
     <td class="p-4"><div class="font-bold text-slate-900"><?=htmlspecialchars((string)($row['requester_name']??'—'))?></div><div class="text-xs text-slate-500"><?=htmlspecialchars((string)($row['service_label']??'Service non précisé'))?></div></td>
     <td class="p-4"><div class="font-bold"><?=htmlspecialchars(trim((string)($row['chantier_code']??'').' '.(string)($row['chantier_titre']??''))?:'Aucun chantier lié')?></div><?php if((int)($row['debourse_id']??0)>0):?><div class="mt-1 text-xs"><strong><?=htmlspecialchars((string)($row['debourse_label']??'Déboursé'))?></strong> (#<?=(int)$row['debourse_id']?>)</div><?php endif;?><?php if((int)($row['ligne_debourse_id']??0)>0):?><div class="text-xs"><?=htmlspecialchars((string)($row['ligne_debourse_label']??'Ligne'))?> (#<?=(int)$row['ligne_debourse_id']?>)</div><?php endif;?><?php if(!empty($row['num_fiche'])):?><a href="../decaissement/voir_fiche_details.php?num_fiche=<?=urlencode((string)$row['num_fiche'])?>" class="mt-2 block font-black text-emerald-700"><?=htmlspecialchars((string)$row['num_fiche'])?> · <?=number_format((float)$row['montant_fiche'],0,',',' ')?> FCFA</a><?php else:?><div class="mt-2 text-xs text-slate-400">Aucune fiche générée pour cette action</div><?php endif;?></td>
     <td class="p-4"><span class="rounded-full px-3 py-1 text-xs font-black <?=$failed?'bg-rose-100 text-rose-800':'bg-emerald-100 text-emerald-800'?>"><?=$failed?'Échec':'Effectuée'?></span><div class="mt-3 font-bold"><?=htmlspecialchars((string)($row['event_by_name']??'Système automatique'))?></div></td>
    </tr>
   <?php endforeach; ?>
   <?php if(!$rows):?><tr><td colspan="5" class="p-12 text-center text-slate-500">Aucune action FEB ne correspond à ces critères.</td></tr><?php endif;?>
   </tbody></table></div>
  </section>
 </div>
</main>
<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>
