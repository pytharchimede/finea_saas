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

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>
<div class="min-h-screen bg-[radial-gradient(circle_at_top_left,_rgba(219,234,254,0.95),_transparent_42%),radial-gradient(circle_at_top_right,_rgba(204,251,241,0.82),_transparent_35%),linear-gradient(180deg,_#f8fafc_0%,_#eef2ff_100%)] pb-12">
    <div class="container mx-auto px-4 py-8">
        <section class="relative overflow-hidden rounded-[32px] border border-cyan-100 bg-gradient-to-r from-slate-950 via-slate-900 to-cyan-900 px-8 py-10 text-white shadow-[0_24px_80px_rgba(15,23,42,0.32)]">
            <div class="absolute -right-14 -top-14 h-48 w-48 rounded-full bg-white/10 blur-2xl"></div>
            <div class="relative flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
                <div class="max-w-3xl">
                    <div class="mb-3 flex flex-wrap items-center gap-3">
                        <p class="text-xs font-semibold uppercase tracking-[0.35em] text-cyan-200">Déboursés travaux</p>
                        <span class="inline-flex items-center rounded-full border border-white/15 bg-white/10 px-3 py-1 text-xs font-semibold text-white">
                            <i class="fas fa-wallet mr-2 text-cyan-300"></i>
                            <?= (int)($stats['debourses'] ?? 0) ?> ligne<?= ((int)($stats['debourses'] ?? 0)) > 1 ? 's' : '' ?> de déboursé
                        </span>
                    </div>
                    <h1 class="text-3xl font-semibold tracking-tight md:text-5xl">Pilotage centralisé des déboursés</h1>
                    <p class="mt-4 max-w-2xl text-sm text-slate-200 md:text-base">
                        Suivez les déboursés issus des devis validés, contrôlez les sous-lignes, préparez les exports et sécurisez la génération chantier.
                    </p>
                </div>
                <div class="flex flex-wrap gap-3">
                    <a href="<?= htmlspecialchars($rootUrl('debourse/liste.php'), ENT_QUOTES, 'UTF-8') ?>" class="inline-flex items-center rounded-2xl bg-white px-5 py-3 text-sm font-semibold text-slate-900 shadow-lg shadow-slate-950/20 transition hover:-translate-y-0.5 hover:bg-cyan-50">
                        <i class="fas fa-list-check mr-2 text-cyan-700"></i> Listing déboursés
                    </a>
                    <a href="<?= htmlspecialchars($rootUrl('devis/liste.php'), ENT_QUOTES, 'UTF-8') ?>" class="inline-flex items-center rounded-2xl bg-cyan-500 px-5 py-3 text-sm font-semibold text-white shadow-lg shadow-cyan-600/20 transition hover:-translate-y-0.5 hover:bg-cyan-600">
                        <i class="fas fa-file-contract mr-2"></i> Choisir un devis
                    </a>
                    <a href="<?= htmlspecialchars($rootUrl('debourse/parametrage.php'), ENT_QUOTES, 'UTF-8') ?>" class="inline-flex items-center rounded-2xl border border-white/15 px-5 py-3 text-sm font-semibold text-white/90 transition hover:bg-white/10">
                        <i class="fas fa-sliders mr-2"></i> Paramétrage
                    </a>
                    <a href="<?= htmlspecialchars($rootUrl('selection_portail.php'), ENT_QUOTES, 'UTF-8') ?>" class="inline-flex items-center rounded-2xl border border-white/20 px-5 py-3 text-sm font-semibold text-white/90 transition hover:bg-white/10">
                        <i class="fas fa-th-large mr-2"></i> Portail
                    </a>
                </div>
            </div>
        </section>

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

        <div class="mt-8 grid gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
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

            <aside class="rounded-[30px] border border-slate-200 bg-slate-950 p-7 text-white shadow-[0_22px_70px_rgba(15,23,42,0.28)]">
                <p class="text-xs font-semibold uppercase tracking-[0.28em] text-cyan-300">Accès rapides</p>
                <h2 class="mt-2 text-2xl font-semibold">Opérations déboursés</h2>
                <div class="mt-6 space-y-3">
                    <a href="<?= htmlspecialchars($rootUrl('debourse/liste.php'), ENT_QUOTES, 'UTF-8') ?>" class="block rounded-2xl border border-cyan-400/20 bg-cyan-500/10 px-4 py-4 transition hover:bg-cyan-500/15">
                        <div class="font-semibold text-cyan-200">Listing des déboursés</div>
                        <div class="mt-1 text-sm text-slate-300">Filtrer par chantier, client ou recherche libre.</div>
                    </a>
                    <a href="<?= htmlspecialchars($rootUrl('devis/liste.php'), ENT_QUOTES, 'UTF-8') ?>" class="block rounded-2xl border border-white/10 bg-white/5 px-4 py-4 transition hover:bg-white/10">
                        <div class="font-semibold">Gérer depuis un devis</div>
                        <div class="mt-1 text-sm text-slate-300">Ouvrir la liste des devis puis accéder à la page gérer.</div>
                    </a>
                    <a href="<?= htmlspecialchars($rootUrl('debourse/voir.php'), ENT_QUOTES, 'UTF-8') ?>" class="block rounded-2xl border border-white/10 bg-white/5 px-4 py-4 transition hover:bg-white/10">
                        <div class="font-semibold">Vue consultation</div>
                        <div class="mt-1 text-sm text-slate-300">Contrôler les déboursés sans modifier les sous-lignes.</div>
                    </a>
                    <a href="<?= htmlspecialchars($rootUrl('debourse/parametrage.php'), ENT_QUOTES, 'UTF-8') ?>" class="block rounded-2xl border border-indigo-400/40 bg-indigo-500/15 px-4 py-4 transition hover:bg-indigo-500/25">
                        <div class="font-semibold text-indigo-200">Paramétrage</div>
                        <div class="mt-1 text-sm text-slate-300">Accès, droits et règles de gestion du module.</div>
                    </a>
                </div>

                <?php if ($orphans !== []): ?>
                    <div class="mt-7 rounded-2xl border border-amber-300/30 bg-amber-400/10 p-4">
                        <div class="font-semibold text-amber-200">Liaisons à reprendre</div>
                        <div class="mt-3 space-y-2">
                            <?php foreach ($orphans as $row): ?>
                                <div class="rounded-xl bg-white/5 px-3 py-3 text-sm">
                                    <div class="font-semibold">Déboursé #<?= (int)($row['id'] ?? 0) ?></div>
                                    <div class="mt-1 text-slate-300">Devis <?= htmlspecialchars((string)($row['numero_devis'] ?? ('#' . ($row['devis_id'] ?? ''))), ENT_QUOTES, 'UTF-8') ?> · ligne <?= (int)($row['ligne_devis_id'] ?? 0) ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </aside>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>
