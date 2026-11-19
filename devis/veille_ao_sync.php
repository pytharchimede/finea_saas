<?php
require_once __DIR__ . '/includes/module_bootstrap.php';
\AccessManager::requirePageAccess('veille_ao_sync.php');
require_once dirname(__DIR__) . '/controllers/AoController.php';
$rows = (new AoController(\Database::getConnection()))->syncLogs();
$activeDevisModule = 'ao';
$moduleTitle = 'Historique synchronisation AO';
$moduleSubtitle = 'Controle des executions automatiques, volumes, erreurs et doublons ignores.';
$h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
ob_start();
?>
<div class="overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
<table class="min-w-full text-sm"><thead class="bg-[#1d2b57] text-white"><tr><th class="p-3 text-left">Debut</th><th class="p-3">Source</th><th class="p-3">Statut</th><th class="p-3">Trouves</th><th class="p-3">Nouveaux</th><th class="p-3">Maj</th><th class="p-3">Erreurs</th><th class="p-3 text-left">Message</th></tr></thead>
<tbody><?php foreach($rows as $row): ?><tr class="border-t"><td class="p-3"><?= $h($row['started_at']) ?></td><td class="p-3 text-center"><?= $h($row['source']) ?></td><td class="p-3 text-center font-bold"><?= $h($row['status']) ?></td><td class="p-3 text-center"><?= (int)$row['total_found'] ?></td><td class="p-3 text-center"><?= (int)$row['total_new'] ?></td><td class="p-3 text-center"><?= (int)$row['total_updated'] ?></td><td class="p-3 text-center"><?= (int)$row['total_errors'] ?></td><td class="p-3"><?= $h($row['message']) ?></td></tr><?php endforeach; ?></tbody></table>
</div>
<?php $content = ob_get_clean(); require __DIR__ . '/includes/module_layout.php';
