<?php
require_once __DIR__.'/../partials/supplier_workspace.php';
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess('dashboard_fournisseur.php');

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../controllers/FournisseurController.php';

$pageTitle = 'Dashboard Fournisseurs';
$activeMenu = 'fournisseur';

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';

$pdo = Database::getConnection();
$controller = new FournisseurController($pdo);
$viewData = $controller->dashboardView();
$model = $viewData['model'];
$stats = $viewData['stats'];
$lastRows = $viewData['lastRows'];
$reporting = $viewData['reporting'];
$pendingRows = $viewData['pendingRows'];
$supplierAlerts = $viewData['alerts'];
?>

<div class="supplier-page-bg">
    <div class="supplier-directory">
        <?php supplierWorkspaceHeader('Statistiques fournisseurs', 'Suivez la qualité du référentiel, les validations et les engagements financiers.', 0); ?>

        <?php if (!$model->isReady()): ?>
            <div class="mt-8 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                <?= htmlspecialchars($model->getInitError() ?: 'Le module fournisseurs n\'a pas pu initialiser ses tables.') ?>
            </div>
        <?php endif; ?>

        <div class="mt-8 grid gap-5 md:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-[28px] border border-white/70 bg-white/90 p-6 shadow-[0_16px_50px_rgba(15,23,42,0.06)] backdrop-blur">
                <p class="text-sm font-medium text-slate-500">Fournisseurs</p>
                <p class="mt-4 text-3xl font-semibold text-slate-950"><?= (int)$stats['total'] ?></p>
                <p class="mt-2 text-sm text-slate-500">Total r&eacute;f&eacute;rentiel</p>
            </div>
            <div class="rounded-[28px] border border-emerald-100 bg-gradient-to-br from-emerald-50 to-white p-6 shadow-[0_16px_50px_rgba(16,185,129,0.10)]">
                <p class="text-sm font-medium text-emerald-700">Actifs</p>
                <p class="mt-4 text-3xl font-semibold text-slate-950"><?= (int)$stats['actifs'] ?></p>
                <p class="mt-2 text-sm text-slate-500">Utilisables pour les factures</p>
            </div>
            <div class="rounded-[28px] border border-slate-200 bg-gradient-to-br from-slate-50 to-white p-6 shadow-[0_16px_50px_rgba(15,23,42,0.06)]">
                <p class="text-sm font-medium text-slate-600">Inactifs</p>
                <p class="mt-4 text-3xl font-semibold text-slate-950"><?= (int)$stats['inactifs'] ?></p>
                <p class="mt-2 text-sm text-slate-500">Archiv&eacute; / non utilis&eacute;</p>
            </div>
            <div class="rounded-[28px] border border-indigo-100 bg-gradient-to-br from-indigo-50 to-white p-6 shadow-[0_16px_50px_rgba(99,102,241,0.10)]">
                <p class="text-sm font-medium text-indigo-700">Derni&egrave;re cr&eacute;ation</p>
                <p class="mt-4 text-lg font-semibold text-slate-950"><?= htmlspecialchars((string)($stats['last_created_at'] ?? '—')) ?></p>
                <p class="mt-2 text-sm text-slate-500">Horodatage (DB)</p>
            </div>
        </div>

        <div class="mt-6 grid gap-5 md:grid-cols-3">
            <a href="fournisseurs.php?validation_status=pending" class="group rounded-[28px] border border-amber-100 bg-gradient-to-br from-amber-50 to-white p-6 shadow-[0_16px_50px_rgba(245,158,11,0.10)] transition hover:-translate-y-0.5 hover:border-amber-300 hover:shadow-[0_20px_60px_rgba(245,158,11,0.16)]"><p class="text-sm font-medium text-amber-700">En attente de validation</p><p class="mt-4 text-3xl font-semibold text-slate-950"><?= (int)$stats['pending_validation'] ?></p><p class="mt-2 flex items-center justify-between gap-3 text-sm text-slate-500"><span>&Agrave; traiter par la comptabilit&eacute;</span><i class="fas fa-arrow-right text-amber-700 transition group-hover:translate-x-1"></i></p></a>
            <div class="rounded-[28px] border border-rose-100 bg-gradient-to-br from-rose-50 to-white p-6 shadow-[0_16px_50px_rgba(244,63,94,0.10)]">
                <p class="text-sm font-medium text-rose-700">Incidents fournisseurs</p>
                <p class="mt-4 text-3xl font-semibold text-slate-950"><?= (int)$stats['incidents_total'] ?></p>
                <p class="mt-2 text-sm text-slate-500">Historique qualit&eacute; / litiges</p>
            </div>
            <div class="rounded-[28px] border border-sky-100 bg-gradient-to-br from-sky-50 to-white p-6 shadow-[0_16px_50px_rgba(14,165,233,0.10)]">
                <p class="text-sm font-medium text-sky-700">D&eacute;caissements rattach&eacute;s</p>
                <p class="mt-4 text-3xl font-semibold text-slate-950"><?= (int)$stats['decaissements_lies'] ?></p>
                <p class="mt-2 text-sm text-slate-500">Fiches d&eacute;j&agrave; li&eacute;es &agrave; un fournisseur</p>
            </div>
        </div>

        <div class="mt-6 grid gap-5 lg:grid-cols-3">
            <div class="rounded-[28px] border border-white/70 bg-white/90 p-6 shadow-[0_16px_50px_rgba(15,23,42,0.06)] backdrop-blur lg:col-span-2">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <p class="text-sm font-semibold text-slate-950">Qualit&eacute; des donn&eacute;es</p>
                        <p class="mt-1 text-sm text-slate-500">Compl&eacute;tude des identifiants + alertes utiles.</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <span class="inline-flex items-center rounded-full border border-indigo-100 bg-indigo-50 px-3 py-1 text-xs font-semibold text-indigo-700">NCC: <?= (int)$stats['with_ncc'] ?></span>
                        <span class="inline-flex items-center rounded-full border border-emerald-100 bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700">RNE: <?= (int)$stats['with_rne'] ?></span>
                    </div>
                </div>

                <div class="mt-5 grid gap-5 md:grid-cols-2">
                    <div class="rounded-2xl border border-slate-200 bg-white p-4">
                        <div class="text-xs font-semibold uppercase tracking-wider text-slate-500">R&eacute;partition</div>
                        <div class="mt-3 h-56">
                            <canvas id="chartFournisseursActifs"></canvas>
                        </div>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-white p-4">
                        <div class="text-xs font-semibold uppercase tracking-wider text-slate-500">Alertes</div>
                        <div class="mt-3 space-y-3 text-sm">
                            <div class="flex items-center justify-between gap-3 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2">
                                <div class="text-amber-900">NCC manquant (isRne=0)</div>
                                <div class="font-semibold text-amber-900"><?= (int)$stats['missing_ncc_when_not_rne'] ?></div>
                            </div>
                            <div class="flex items-center justify-between gap-3 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2">
                                <div class="text-amber-900">RNE manquant (isRne=1)</div>
                                <div class="font-semibold text-amber-900"><?= (int)$stats['missing_rne_when_rne'] ?></div>
                            </div>
                            <div class="flex items-center justify-between gap-3 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2">
                                <div class="text-slate-800">Contact manquant (email+t&eacute;l)</div>
                                <div class="font-semibold text-slate-900"><?= (int)$stats['missing_contact'] ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="rounded-[28px] border border-white/70 bg-white/90 p-6 shadow-[0_16px_50px_rgba(15,23,42,0.06)] backdrop-blur">
                <p class="text-sm font-semibold text-slate-950">Acc&egrave;s rapide</p>
                <p class="mt-1 text-sm text-slate-500">Actions courantes du module.</p>

                <div class="mt-4 grid gap-3">
                    <a href="dashboard_fournisseur.php" class="group flex items-center justify-between rounded-2xl border border-slate-200 bg-white px-4 py-3 transition hover:border-emerald-200 hover:bg-emerald-50">
                        <div>
                            <div class="text-sm font-semibold text-slate-900">Fiches fournisseurs</div>
                            <div class="text-xs text-slate-500">Cr&eacute;er / modifier / activer</div>
                        </div>
                        <div class="text-slate-400 group-hover:text-emerald-700">→</div>
                    </a>
                    <a href="categories.php" class="group flex items-center justify-between rounded-2xl border border-slate-200 bg-white px-4 py-3 transition hover:border-amber-200 hover:bg-amber-50">
                        <div>
                            <div class="text-sm font-semibold text-slate-900">Cat&eacute;gories fournisseur</div>
                            <div class="text-xs text-slate-500">Param&eacute;trage centralis&eacute;</div>
                        </div>
                        <div class="text-slate-400 group-hover:text-amber-700">→</div>
                    </a>
                    <a href="formes_juridiques.php" class="group flex items-center justify-between rounded-2xl border border-slate-200 bg-white px-4 py-3 transition hover:border-cyan-200 hover:bg-cyan-50">
                        <div>
                            <div class="text-sm font-semibold text-slate-900">Formes juridiques</div>
                            <div class="text-xs text-slate-500">Liste des SARL, SA, SAS...</div>
                        </div>
                        <div class="text-slate-400 group-hover:text-cyan-700">→</div>
                    </a>
                    <a href="../facturation/factures_achat.php" class="group flex items-center justify-between rounded-2xl border border-slate-200 bg-white px-4 py-3 transition hover:border-indigo-200 hover:bg-indigo-50">
                        <div>
                            <div class="text-sm font-semibold text-slate-900">Factures d'achat</div>
                            <div class="text-xs text-slate-500">Collecte + verify FNE</div>
                        </div>
                        <div class="text-slate-400 group-hover:text-indigo-700">→</div>
                    </a>
                </div>

                <div class="mt-5">
                    <div class="text-xs font-semibold uppercase tracking-wider text-slate-500">Derniers fournisseurs</div>
                    <div class="mt-3 space-y-2">
                        <?php if (!$lastRows): ?>
                            <div class="rounded-xl border border-slate-200 bg-white px-3 py-3 text-sm text-slate-500">Aucune donn&eacute;e.</div>
                        <?php endif; ?>
                        <?php foreach ($lastRows as $r): ?>
                            <?php
                            $status = (string)($r['validation_status'] ?? 'pending');
                            $statusClass = $status === 'approved'
                                ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                                : ($status === 'rejected'
                                    ? 'border-rose-200 bg-rose-50 text-rose-700'
                                    : 'border-amber-200 bg-amber-50 text-amber-700');
                            $statusLabel = $status === 'approved' ? 'Valid&eacute;' : ($status === 'rejected' ? 'Rejet&eacute;' : 'En attente');
                            ?>
                            <a href="edit_fournisseur.php?id=<?= (int)$r['id_fournisseur'] ?>" class="block rounded-xl border border-slate-200 bg-white px-3 py-3 text-sm transition hover:border-slate-300">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="truncate font-semibold text-slate-900"><?= htmlspecialchars((string)($r['supplier_display_name'] ?? $r['nom_commercial'] ?? $r['raison_sociale'])) ?></div>
                                        <?php if (!empty($r['raison_sociale'])): ?>
                                            <div class="truncate text-xs text-slate-500"><?= htmlspecialchars((string)$r['raison_sociale']) ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($r['contact_nom'])): ?>
                                            <div class="truncate text-xs text-slate-500">Contact: <?= htmlspecialchars((string)$r['contact_nom']) ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex flex-col items-end gap-2">
                                        <span class="inline-flex shrink-0 items-center rounded-full border px-2 py-1 text-xs font-semibold <?= !empty($r['actif']) ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-slate-200 bg-slate-50 text-slate-700' ?>">
                                            <?= !empty($r['actif']) ? 'Actif' : 'Inactif' ?>
                                        </span>
                                        <span class="inline-flex shrink-0 items-center rounded-full border px-2 py-1 text-xs font-semibold <?= $statusClass ?>"><?= $statusLabel ?></span>
                                    </div>
                                </div>
                                <div class="mt-2 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                                    <span><?= htmlspecialchars((string)$r['created_at']) ?></span>
                                    <?php if (!empty($r['is_rne'])): ?>
                                        <span class="rounded-full border border-emerald-100 bg-emerald-50 px-2 py-0.5 font-semibold text-emerald-700">RNE</span>
                                    <?php else: ?>
                                        <span class="rounded-full border border-indigo-100 bg-indigo-50 px-2 py-0.5 font-semibold text-indigo-700">NCC</span>
                                    <?php endif; ?>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-6 grid gap-5 xl:grid-cols-[0.9fr_2.1fr]">
            <div class="rounded-[28px] border border-white/70 bg-white/90 p-6 shadow-[0_16px_50px_rgba(15,23,42,0.06)] backdrop-blur">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <p class="text-sm font-semibold text-slate-950">File de validation comptable</p>
                        <p class="mt-1 text-sm text-slate-500">Fournisseurs saisis depuis l'espace employ&eacute; en attente d'accord.</p>
                    </div>
                    <span class="inline-flex items-center rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-700"><?= (int)$stats['pending_validation'] ?> &agrave; traiter</span>
                </div>

                <div class="mt-4 space-y-3">
                    <?php if (!$pendingRows): ?>
                        <div class="rounded-xl border border-dashed border-slate-300 px-4 py-4 text-sm text-slate-500">Aucun fournisseur en attente pour le moment.</div>
                    <?php endif; ?>
                    <?php foreach ($pendingRows as $pending): ?>
                        <a href="edit_fournisseur.php?id=<?= (int)$pending['id_fournisseur'] ?>" class="block rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4 transition hover:border-amber-300 hover:bg-amber-50">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="truncate font-semibold text-slate-900"><?= htmlspecialchars((string)($pending['supplier_display_name'] ?? $pending['nom_commercial'] ?? $pending['raison_sociale'])) ?></div>
                                    <?php if (!empty($pending['raison_sociale'])): ?>
                                        <div class="truncate text-xs text-slate-500"><?= htmlspecialchars((string)$pending['raison_sociale']) ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($pending['contact_nom'])): ?>
                                        <div class="truncate text-xs text-slate-500">Contact: <?= htmlspecialchars((string)$pending['contact_nom']) ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($pending['telephone'])): ?>
                                        <div class="truncate text-xs text-slate-500">T&eacute;l&eacute;phone: <?= htmlspecialchars((string)$pending['telephone']) ?></div>
                                    <?php endif; ?>
                                </div>
                                <span class="inline-flex shrink-0 items-center rounded-full border border-amber-200 bg-amber-50 px-2 py-1 text-xs font-semibold text-amber-700">En attente</span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="rounded-[28px] border border-white/70 bg-white/90 p-6 shadow-[0_16px_50px_rgba(15,23,42,0.06)] backdrop-blur">
                <div class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                    <div>
                        <p class="text-sm font-semibold text-slate-950">D&eacute;caissements par fournisseur</p>
                        <p class="mt-1 text-sm text-slate-500">Vision de reporting pour le suivi montant, contact et dernier mouvement par fournisseur.</p>
                    </div>
                    <div class="text-xs text-slate-500">Top 25 par montant total</div>
                </div>

                <?php if (empty($reporting['available'])): ?>
                    <div class="mt-4 rounded-2xl border border-sky-200 bg-sky-50 px-4 py-4 text-sm text-sky-900">
                        Le reporting fournisseur sera aliment&eacute; d&egrave;s qu'une fiche de d&eacute;caissement sera cr&eacute;&eacute;e avec rattachement fournisseur sur l'environnement courant.
                    </div>
                <?php else: ?>
                    <div class="mt-4 overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="border-b text-left text-slate-500">
                                    <th class="py-3 pr-4">Fournisseur</th>
                                    <th class="py-3 pr-4">Contact</th>
                                    <th class="py-3 pr-4">T&eacute;l&eacute;phone</th>
                                    <th class="py-3 pr-4 text-right">Fiches</th>
                                    <th class="py-3 pr-4 text-right">Total demand&eacute;</th>
                                    <th class="py-3 pr-4 text-right">Total d&eacute;caiss&eacute;</th>
                                    <th class="py-3 pr-4 text-right">Total approuv&eacute;</th>
                                    <th class="py-3">Derni&egrave;re fiche</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php if (empty($reporting['rows'])): ?>
                                    <tr>
                                        <td colspan="8" class="py-6 text-center text-slate-500">Aucun d&eacute;caissement fournisseur trouv&eacute;.</td>
                                    </tr>
                                <?php endif; ?>
                                <?php foreach (($reporting['rows'] ?? []) as $reportRow): ?>
                                    <tr class="text-slate-800">
                                        <td class="py-3 pr-4 font-medium"><?= htmlspecialchars((string)($reportRow['fournisseur_nom'] ?? '—')) ?></td>
                                        <td class="py-3 pr-4 text-slate-600"><?= htmlspecialchars((string)($reportRow['contact_nom'] ?? '—')) ?></td>
                                        <td class="py-3 pr-4 text-slate-600"><?= htmlspecialchars((string)($reportRow['telephone'] ?? '—')) ?></td>
                                        <td class="py-3 pr-4 text-right font-semibold"><?= number_format((float)($reportRow['nb_fiches'] ?? 0), 0, ',', ' ') ?></td>
                                        <td class="py-3 pr-4 text-right font-semibold text-slate-900"><?= number_format((float)($reportRow['montant_total'] ?? 0), 0, ',', ' ') ?> FCFA</td>
                                        <td class="py-3 pr-4 text-right text-emerald-700"><?= number_format((float)($reportRow['montant_decaisse'] ?? 0), 0, ',', ' ') ?> FCFA</td>
                                        <td class="py-3 pr-4 text-right text-indigo-700"><?= number_format((float)($reportRow['montant_approuve'] ?? 0), 0, ',', ' ') ?> FCFA</td>
                                        <td class="py-3 text-slate-600"><?= htmlspecialchars((string)($reportRow['derniere_fiche'] ?? '—')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
    (function() {
        if (typeof Chart === 'undefined') return;
        var el = document.getElementById('chartFournisseursActifs');
        if (!el) return;

        var actifs = <?= (int)$stats['actifs'] ?>;
        var inactifs = <?= (int)$stats['inactifs'] ?>;

        new Chart(el.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: ['Actifs', 'Inactifs'],
                datasets: [{
                    data: [actifs, inactifs],
                    backgroundColor: ['rgba(16,185,129,0.30)', 'rgba(148,163,184,0.30)'],
                    borderColor: ['rgba(16,185,129,0.60)', 'rgba(148,163,184,0.60)'],
                    borderWidth: 1,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    },
                    tooltip: {
                        enabled: true
                    }
                },
                cutout: '68%'
            }
        });
    })();
</script>

<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>
