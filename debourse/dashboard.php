<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess('liste_debourse.php');

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/controllers/DebourseDashboardController.php';

$pdo = \Database::getConnection();
$dashboard = (new \Debourse\Controllers\DebourseDashboardController($pdo))->handle();
$stats = $dashboard['stats'] ?? [];
$recent = $dashboard['recent'] ?? [];
$orphans = $dashboard['orphans'] ?? [];
$coverage = (int)($dashboard['coverage'] ?? 0);

$appBase = rtrim(\AccessManager::getApplicationBasePath(), '/');
$rootUrl = static fn(string $path): string => ($appBase === '' ? '' : $appBase) . '/' . ltrim($path, '/');
$fmt = static fn($value): string => number_format((float)$value, 0, ',', ' ');

$pageTitle = 'Dashboard Déboursés';
$activeMenu = 'module_debourse';

$activeDevisModule='debourse';
$moduleTitle='Pilotage des déboursés';
$moduleSubtitle='Suivi des déboursés, des devis et des chantiers.';
require_once __DIR__.'/../devis/includes/module_bootstrap.php';
ob_start();
?>
<div class="devis-debourse-content">
<section class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <article class="rounded-[26px] border border-white/80 bg-white/90 p-6 shadow-[0_18px_50px_rgba(15,23,42,0.07)]">
                <p class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">Montant déboursé</p>
                <div class="mt-3 text-3xl font-semibold text-slate-950"><?= $fmt($stats['montant'] ?? 0) ?></div>
                <p class="mt-1 text-sm text-slate-500">FCFA consolidés</p>
            </article>
            <article class="rounded-[26px] border border-white/80 bg-white/90 p-6 shadow-[0_18px_50px_rgba(15,23,42,0.07)]">
                <p class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">Devis couverts</p>
                <div class="mt-3 text-3xl font-semibold text-slate-950"><?= (int)($stats['devis'] ?? 0) ?></div>
                <p class="mt-1 text-sm text-slate-500">avec au moins un déboursé</p>
            </article>
            <article class="rounded-[26px] border border-white/80 bg-white/90 p-6 shadow-[0_18px_50px_rgba(15,23,42,0.07)]">
                <p class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">Sous-lignes</p>
                <div class="mt-3 text-3xl font-semibold text-slate-950"><?= (int)($stats['sous_lignes'] ?? 0) ?></div>
                <p class="mt-1 text-sm text-slate-500"><?= (int)($stats['linked'] ?? 0) ?> liées à des fiches</p>
            </article>
            <article class="rounded-[26px] border <?= ((int)($stats['orphans'] ?? 0)) > 0 ? 'border-amber-200 bg-amber-50/90' : 'border-white/80 bg-white/90' ?> p-6 shadow-[0_18px_50px_rgba(15,23,42,0.07)]">
                <p class="text-xs font-semibold uppercase tracking-[0.22em] <?= ((int)($stats['orphans'] ?? 0)) > 0 ? 'text-amber-700' : 'text-slate-500' ?>">À contrôler</p>
                <div class="mt-3 text-3xl font-semibold <?= ((int)($stats['orphans'] ?? 0)) > 0 ? 'text-amber-900' : 'text-slate-950' ?>"><?= (int)($stats['orphans'] ?? 0) ?></div>
                <p class="mt-1 text-sm <?= ((int)($stats['orphans'] ?? 0)) > 0 ? 'text-amber-800' : 'text-slate-500' ?>">liaisons orphelines</p>
            </article>
        </section>

        <div class="mt-8 grid gap-6 grid-cols-1">
            <main class="space-y-6">
                <section class="rounded-[30px] border border-white/80 bg-white/90 p-7 shadow-[0_18px_60px_rgba(15,23,42,0.07)] backdrop-blur">
                    <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.28em] text-cyan-700">Lecture opérationnelle</p>
                            <h2 class="mt-2 text-2xl font-semibold text-slate-950">Couverture et qualité des déboursés</h2>
                        </div>
                        <div class="rounded-2xl bg-slate-950 px-5 py-4 text-white">
                            <div class="text-xs font-semibold uppercase tracking-[0.2em] text-cyan-200">Sous-lignes liées</div>
                            <div class="mt-1 text-2xl font-semibold"><?= $coverage ?>%</div>
                        </div>
                    </div>
                    <div class="mt-6 h-4 rounded-full bg-slate-100">
                        <div class="h-4 rounded-full bg-gradient-to-r from-cyan-500 to-emerald-500" style="width: <?= $coverage ?>%"></div>
                    </div>
                    <div class="mt-6 grid gap-4 md:grid-cols-2">
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-5">
                            <div class="text-sm font-semibold text-slate-900">Dépassements détectés</div>
                            <div class="mt-2 text-3xl font-semibold text-rose-700"><?= (int)($stats['overruns'] ?? 0) ?></div>
                            <p class="mt-2 text-sm text-slate-500">Déboursés dont le montant dépasse le budget de ligne de devis.</p>
                        </div>
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-5">
                            <div class="text-sm font-semibold text-slate-900">Qualité chantier</div>
                            <div class="mt-2 text-3xl font-semibold text-cyan-700"><?= (int)($stats['linked'] ?? 0) ?></div>
                            <p class="mt-2 text-sm text-slate-500">Sous-lignes déjà exploitées par les fiches de décaissement.</p>
                        </div>
                    </div>
                </section>

                <section class="rounded-[30px] border border-white/80 bg-white/90 p-7 shadow-[0_18px_60px_rgba(15,23,42,0.07)] backdrop-blur">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.28em] text-indigo-600">Activité récente</p>
                            <h2 class="mt-2 text-2xl font-semibold text-slate-950">Derniers déboursés modifiés</h2>
                        </div>
                        <a href="<?= htmlspecialchars($rootUrl('debourse/liste.php'), ENT_QUOTES, 'UTF-8') ?>" class="rounded-full bg-indigo-50 px-4 py-2 text-sm font-semibold text-indigo-700 hover:bg-indigo-100">Tout voir</a>
                    </div>
                    <div class="mt-5 overflow-hidden rounded-2xl border border-slate-200">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">
                                <tr>
                                    <th class="px-4 py-3">Devis</th>
                                    <th class="px-4 py-3">Ligne</th>
                                    <th class="px-4 py-3 text-right">Derniere modif</th>
                                    <th class="px-4 py-3 text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 bg-white">
                                <?php foreach ($recent as $row): ?>
                                    <?php
                                    $lastModified = trim((string)($row['last_modified_at'] ?? ''));
                                    $lastModifiedTime = $lastModified !== '' ? strtotime($lastModified) : false;
                                    $lastModifiedLabel = $lastModifiedTime !== false ? date('d/m/Y H:i', $lastModifiedTime) : 'Non renseignee';
                                    ?>
                                    <tr>
                                        <td class="px-4 py-4">
                                            <div class="font-semibold text-slate-950"><?= htmlspecialchars((string)($row['numero_devis'] ?? ('Devis #' . ($row['devis_id'] ?? ''))), ENT_QUOTES, 'UTF-8') ?></div>
                                            <div class="mt-1 text-xs text-slate-500"><?= htmlspecialchars((string)($row['destine_a'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                        </td>
                                        <td class="px-4 py-4 text-slate-700"><?= htmlspecialchars((string)($row['designation'] ?? ('Ligne #' . ($row['ligne_devis_id'] ?? ''))), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td class="px-4 py-4 text-right font-semibold text-slate-950"><?= htmlspecialchars($lastModifiedLabel, ENT_QUOTES, 'UTF-8') ?></td>
                                        <td class="px-4 py-4 text-center">
                                            <a href="<?= htmlspecialchars($rootUrl('debourse/gerer.php?devisId=' . (int)($row['devis_id'] ?? 0)), ENT_QUOTES, 'UTF-8') ?>" class="rounded-full bg-slate-950 px-3 py-2 text-xs font-semibold text-white hover:bg-slate-800">Gérer</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if ($recent === []): ?>
                                    <tr><td colspan="4" class="px-4 py-8 text-center text-slate-500">Aucun déboursé enregistré.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </main>

            
        </div>
</div>
<?php $content=ob_get_clean(); require __DIR__.'/../devis/includes/module_layout.php'; ?>
