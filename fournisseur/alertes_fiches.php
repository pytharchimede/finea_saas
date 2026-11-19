<?php
require_once __DIR__.'/../partials/supplier_workspace.php';
require_once __DIR__.'/../auth_check.php';
if (!AccessManager::hasPermission('acces_compta') && !AccessManager::hasPermission('acces_direction') && !AccessManager::hasPermission('acces_admin')) { http_response_code(403); exit('Accès refusé.'); }
require_once __DIR__.'/../model/Database.php';
require_once __DIR__.'/../services/ClientProjectCompletenessAlertService.php';
$items = (new ClientProjectCompletenessAlertService(Database::getConnection()))->listing();
$pageTitle = 'Fiches à normaliser'; $layoutRootBase='../'; $layoutModuleBase='../decaissement/';
require __DIR__.'/../decaissement/includes/header.php';
require __DIR__.'/../decaissement/includes/navbar.php';
$h = static fn($v)=>htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<main class="supplier-directory">
<?php supplierWorkspaceHeader('Fiches à normaliser', count($items).' fiches incomplètes · état actuel du référentiel.', 0); ?>
<div class="my-5 flex flex-wrap gap-3"><a class="rounded-xl border px-4 py-3" href="fournisseurs.php">Fournisseurs</a><a class="rounded-xl border px-4 py-3" href="../clients/liste.php">Clients</a><a class="rounded-xl border px-4 py-3" href="../offres/dashboard.php">Projets</a></div>
<label class="block mb-5">Filtrer les alertes<input id="alert-search" type="search" class="block w-full rounded-xl border p-3" placeholder="Type, nom, information manquante…"></label>
<div class="space-y-3" id="alert-list"><?php foreach($items as $item): ?><article class="rounded-2xl border bg-white p-5"><span class="text-xs font-bold text-indigo-700"><?=$h($item['type'])?> #<?=$item['id']?></span><h2 class="mt-1 font-bold"><?=$h($item['label'])?></h2><p class="my-2 text-sm text-slate-600"><?=$h($item['reason'])?></p><a class="text-indigo-700 font-semibold" href="<?=$h($item['url'])?>">Normaliser la fiche →</a></article><?php endforeach; ?></div>
<?php if (!$items): ?><p class="rounded-2xl bg-emerald-50 p-6">Aucune fiche incomplète.</p><?php endif; ?>
</main>
<script>document.getElementById('alert-search').addEventListener('input',function(){const q=this.value.toLocaleLowerCase();document.querySelectorAll('#alert-list article').forEach(el=>el.hidden=!el.textContent.toLocaleLowerCase().includes(q));});</script>
<?php require __DIR__.'/../decaissement/includes/footer.php'; ?>
