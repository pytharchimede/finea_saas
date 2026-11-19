<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/decaissement/model/Database.php';
require_once __DIR__ . '/decaissement/model/ProjectPlanningImport.php';
require_once __DIR__ . '/decaissement/includes/planning_dashboard_shared.php';

$pdo = \Database::getConnection();
$planningModel = new \ProjectPlanningImport($pdo);
$filters = planningDashboardNormalizeFilters($_GET);
$planningRows = $planningModel->getPlanningDashboardSummaries($filters);
$filterOptions = $planningModel->getPlanningDashboardFilterOptions();
$statusConfig = planningDashboardStatusConfig();
$overview = planningDashboardComputeOverview($planningRows);
$chartPayload = planningDashboardBuildChartPayload($planningRows, $overview);
$exportQuery = planningDashboardBuildQueryString($filters);
$exportSuffix = $exportQuery !== '' ? '?' . $exportQuery : '';
$selectedConductorId = (int)($filters['conductor_id'] ?? 0);
$selectedConductorLabel = 'Tous les conducteurs';

foreach (($filterOptions['conductors'] ?? []) as $conductorOption) {
    if ((int)($conductorOption['id'] ?? 0) === $selectedConductorId) {
        $selectedConductorLabel = trim((string)($conductorOption['name'] ?? ''));
        if (trim((string)($conductorOption['service_label'] ?? '')) !== '') {
            $selectedConductorLabel .= ' - ' . trim((string)$conductorOption['service_label']);
        }
        break;
    }
}

$pageTitle = 'Dashboard Planning Chantier';
$activeMenu = 'chantiers';
require_once __DIR__ . '/decaissement/includes/header.php';
require_once __DIR__ . '/decaissement/includes/navbar.php';
?>

<div class="min-h-screen bg-[radial-gradient(circle_at_top_left,_rgba(45,212,191,0.14),_transparent_28%),radial-gradient(circle_at_top_right,_rgba(59,130,246,0.12),_transparent_24%),linear-gradient(180deg,_#f8fafc_0%,_#ffffff_44%,_#f8fafc_100%)]">
    <div class="container mx-auto px-4 py-10">
        <div class="flex flex-col gap-6 xl:flex-row xl:items-end xl:justify-between mb-8">
            <div>
                <div class="inline-flex items-center gap-2 rounded-full border border-teal-100 bg-white/90 px-4 py-2 text-xs font-semibold uppercase tracking-[0.24em] text-teal-700 shadow-sm">
                    <span class="h-2.5 w-2.5 rounded-full bg-teal-500"></span>
                    Suivi planning avancement travaux
                </div>
                <h1 class="mt-4 text-4xl font-black tracking-tight text-slate-900">Dashboard planning chantier</h1>
                <p class="mt-3 max-w-4xl text-base leading-7 text-slate-600">
                    Recap global de tous les chantiers avec niveau d avancement planifie, progression reelle saisie en reunion et acces direct au suivi detaille de chaque chantier.
                </p>
            </div>

            <div class="flex flex-wrap gap-3 xl:justify-end">
                <a href="decaissement/request/export_dashboard_planing_excel.php<?= htmlspecialchars($exportSuffix, ENT_QUOTES, 'UTF-8') ?>" class="inline-flex items-center rounded-2xl bg-emerald-600 px-5 py-3 text-sm font-semibold text-white shadow-lg shadow-emerald-600/15 transition hover:bg-emerald-700">
                    <i class="fas fa-file-excel mr-2"></i>
                    Export Excel
                </a>
                <a href="decaissement/request/export_dashboard_planing_pdf.php<?= htmlspecialchars($exportSuffix, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" class="inline-flex items-center rounded-2xl bg-rose-600 px-5 py-3 text-sm font-semibold text-white shadow-lg shadow-rose-600/15 transition hover:bg-rose-700">
                    <i class="fas fa-file-pdf mr-2"></i>
                    Export PDF
                </a>
                <a href="decaissement/detail_travaux_chantier.php?chantier_id=63" class="inline-flex items-center rounded-2xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white shadow-lg shadow-slate-900/15 transition hover:bg-slate-800">
                    <i class="fas fa-arrow-up-right-from-square mr-2"></i>
                    Ouvrir le chantier 63
                </a>
                <a href="decaissement/details_chantiers.php" class="inline-flex items-center rounded-2xl bg-white px-5 py-3 text-sm font-semibold text-slate-700 shadow-lg shadow-slate-200/70 ring-1 ring-slate-200 transition hover:bg-slate-50">
                    <i class="fas fa-layer-group mr-2 text-teal-600"></i>
                    Voir les chantiers
                </a>
            </div>
        </div>

        <div class="mb-8 rounded-[2rem] border border-white/80 bg-white/95 p-6 shadow-[0_24px_80px_rgba(15,23,42,0.08)]">
            <div class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-400">Filtres serveur</p>
                    <h2 class="mt-1 text-2xl font-black text-slate-900">Date de reunion et conducteur de travaux</h2>
                    <p class="mt-1 text-sm text-slate-500">La date fige le dashboard sur la derniere situation connue au plus tard a cette reunion, puis conserve uniquement les chantiers effectivement mis a jour ce jour-la.</p>
                </div>
                <form method="GET" class="grid grid-cols-1 gap-3 md:grid-cols-[220px_320px_auto_auto]">
                    <label class="block">
                        <span class="mb-2 block text-sm font-semibold text-slate-600">Date de reunion</span>
                        <input type="date" name="meeting_date" value="<?= htmlspecialchars((string)($filters['meeting_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-teal-400">
                    </label>
                    <label class="block">
                        <span class="mb-2 block text-sm font-semibold text-slate-600">Conducteur de travaux</span>
                        <select name="conductor_id" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-teal-400">
                            <option value="0">Tous les conducteurs</option>
                            <?php foreach (($filterOptions['conductors'] ?? []) as $conductorOption): ?>
                                <?php $conductorOptionId = (int)($conductorOption['id'] ?? 0); ?>
                                <option value="<?= $conductorOptionId ?>" <?= $conductorOptionId === $selectedConductorId ? 'selected' : '' ?>>
                                    <?= htmlspecialchars(trim((string)($conductorOption['name'] ?? '')) . (trim((string)($conductorOption['service_label'] ?? '')) !== '' ? ' - ' . trim((string)$conductorOption['service_label']) : ''), ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button type="submit" class="inline-flex items-center justify-center self-end rounded-2xl bg-teal-600 px-5 py-3 text-sm font-semibold text-white shadow-lg shadow-teal-600/15 transition hover:bg-teal-700">
                        <i class="fas fa-filter mr-2"></i>
                        Appliquer
                    </button>
                    <a href="dashboard_planing.php" class="inline-flex items-center justify-center self-end rounded-2xl bg-slate-100 px-5 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-200">
                        <i class="fas fa-rotate-left mr-2"></i>
                        Reinitialiser
                    </a>
                </form>
            </div>
            <div class="mt-4 flex flex-wrap gap-3 text-sm text-slate-500">
                <span class="inline-flex items-center gap-2 rounded-full bg-slate-100 px-4 py-2">
                    <i class="fas fa-calendar-day text-teal-600"></i>
                    <?= !empty($filters['meeting_date']) ? 'Reunion du ' . htmlspecialchars(planningDashboardFormatDate((string)$filters['meeting_date']), ENT_QUOTES, 'UTF-8') : 'Toutes les reunions' ?>
                </span>
                <span class="inline-flex items-center gap-2 rounded-full bg-slate-100 px-4 py-2">
                    <i class="fas fa-user-hard-hat text-sky-600"></i>
                    <?= htmlspecialchars($selectedConductorLabel, ENT_QUOTES, 'UTF-8') ?>
                </span>
                <span class="inline-flex items-center gap-2 rounded-full bg-slate-100 px-4 py-2">
                    <i class="fas fa-table-list text-fuchsia-600"></i>
                    <?= number_format((int)($overview['total_chantiers'] ?? 0), 0, ',', ' ') ?> chantier(s) dans la vue
                </span>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-6 mb-8">
            <div class="rounded-[1.75rem] border border-white/80 bg-white/95 p-5 shadow-[0_20px_60px_rgba(15,23,42,0.08)]">
                <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Chantiers</div>
                <div class="mt-3 text-3xl font-black text-slate-900"><?= number_format((int)($overview['total_chantiers'] ?? 0), 0, ',', ' ') ?></div>
                <div class="mt-2 text-sm text-slate-500">Base complete des chantiers</div>
            </div>
            <div class="rounded-[1.75rem] border border-white/80 bg-white/95 p-5 shadow-[0_20px_60px_rgba(15,23,42,0.08)]">
                <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Avec planning</div>
                <div class="mt-3 text-3xl font-black text-teal-700"><?= number_format((int)($overview['with_import_count'] ?? 0), 0, ',', ' ') ?></div>
                <div class="mt-2 text-sm text-slate-500"><?= number_format((int)($overview['without_import_count'] ?? 0), 0, ',', ' ') ?> sans import actif</div>
            </div>
            <div class="rounded-[1.75rem] border border-white/80 bg-white/95 p-5 shadow-[0_20px_60px_rgba(15,23,42,0.08)]">
                <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Planifie global</div>
                <div class="mt-3 text-3xl font-black text-sky-700"><?= number_format((float)($overview['global_planned_progress'] ?? 0), 1, ',', ' ') ?>%</div>
                <div class="mt-2 text-sm text-slate-500">Sur les chantiers importes</div>
            </div>
            <div class="rounded-[1.75rem] border border-white/80 bg-white/95 p-5 shadow-[0_20px_60px_rgba(15,23,42,0.08)]">
                <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Reel global</div>
                <div class="mt-3 text-3xl font-black text-indigo-700"><?= number_format((float)($overview['global_actual_progress'] ?? 0), 1, ',', ' ') ?>%</div>
                <div class="mt-2 text-sm text-slate-500"><?= number_format((int)($overview['tracked_projects'] ?? 0), 0, ',', ' ') ?> chantiers deja suivis</div>
            </div>
            <div class="rounded-[1.75rem] border border-white/80 bg-white/95 p-5 shadow-[0_20px_60px_rgba(15,23,42,0.08)]">
                <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Budget reference</div>
                <div class="mt-3 text-3xl font-black text-fuchsia-700"><?= number_format((float)($overview['budget_reference_total'] ?? 0), 0, ',', ' ') ?> F</div>
                <div class="mt-2 text-sm text-slate-500"><?= number_format((float)($overview['budget_depense_total'] ?? 0), 0, ',', ' ') ?> F deja consommes</div>
            </div>
            <div class="rounded-[1.75rem] border border-white/80 bg-white/95 p-5 shadow-[0_20px_60px_rgba(15,23,42,0.08)]">
                <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Alerte / budget</div>
                <div class="mt-3 flex items-end gap-4">
                    <div>
                        <div class="text-3xl font-black text-rose-700"><?= number_format((int)($overview['delayed_projects'] ?? 0), 0, ',', ' ') ?></div>
                        <div class="text-xs text-slate-500">Retards</div>
                    </div>
                    <div>
                        <div class="text-3xl font-black text-red-700"><?= number_format((int)($overview['blocked_projects'] ?? 0), 0, ',', ' ') ?></div>
                        <div class="text-xs text-slate-500">Blocages</div>
                    </div>
                </div>
                <div class="mt-2 text-sm text-slate-500"><?= number_format((float)($overview['global_budget_consumption'] ?? 0), 1, ',', ' ') ?>% de consommation budgetaire</div>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-[1.2fr_0.8fr] mb-8">
            <div class="rounded-[2rem] border border-white/80 bg-white/95 p-6 shadow-[0_24px_80px_rgba(15,23,42,0.08)]">
                <div class="mb-5 flex items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-400">Graphique comparatif</p>
                        <h2 class="mt-1 text-2xl font-black text-slate-900">Planifie vs reel par chantier</h2>
                        <p class="mt-1 text-sm text-slate-500">Les chantiers les plus en ecart remontent en priorite pour action immediate.</p>
                    </div>
                </div>
                <div class="h-[360px]">
                    <canvas id="planningProgressChart"></canvas>
                </div>
            </div>

            <div class="rounded-[2rem] border border-white/80 bg-white/95 p-6 shadow-[0_24px_80px_rgba(15,23,42,0.08)]">
                <div class="mb-5">
                    <p class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-400">Repartition</p>
                    <h2 class="mt-1 text-2xl font-black text-slate-900">Etat global du portefeuille</h2>
                    <p class="mt-1 text-sm text-slate-500">Vue rapide des chantiers sans planning, en retard, bloques ou maitrisés.</p>
                </div>
                <div class="h-[360px]">
                    <canvas id="planningStateChart"></canvas>
                </div>
            </div>
        </div>

        <div class="rounded-[2rem] border border-white/80 bg-white/95 p-6 shadow-[0_24px_80px_rgba(15,23,42,0.08)] mb-8">
            <div class="mb-5">
                <p class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-400">Lien planning / budget</p>
                <h2 class="mt-1 text-2xl font-black text-slate-900">Avancement reel vs consommation budgetaire</h2>
                <p class="mt-1 text-sm text-slate-500">Le rapprochement permet de repérer les chantiers dont les depenses decaissees avancent plus vite que l execution terrain, ou l inverse.</p>
            </div>
            <div class="h-[360px]">
                <canvas id="planningBudgetLinkChart"></canvas>
            </div>
        </div>

        <div class="rounded-[2rem] border border-white/80 bg-white/95 p-6 shadow-[0_24px_80px_rgba(15,23,42,0.08)]">
            <div class="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between mb-6">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-400">Tableau de pilotage</p>
                    <h2 class="mt-1 text-2xl font-black text-slate-900">Tous les chantiers</h2>
                    <p class="mt-1 text-sm text-slate-500">Filtre rapide et ouverture directe du detail planning pour renseigner un chantier particulier.</p>
                </div>
                <div class="flex flex-wrap gap-3">
                    <label class="inline-flex items-center gap-2 rounded-2xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-600">
                        <span class="font-semibold text-slate-500">Etat</span>
                        <select id="planningStatusFilter" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-teal-400">
                            <option value="all">Tous</option>
                            <option value="sans_planning">Sans planning</option>
                            <option value="a_demarrer">A demarrer</option>
                            <option value="maitrise">Sous controle</option>
                            <option value="retard">En retard</option>
                            <option value="bloque">Bloque</option>
                            <option value="termine">Presque termine</option>
                        </select>
                    </label>
                    <label class="inline-flex items-center gap-2 rounded-2xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-600">
                        <span class="font-semibold text-slate-500">Recherche</span>
                        <input id="planningSearch" type="search" class="w-64 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-teal-400" placeholder="Code, chantier, source...">
                    </label>
                </div>
            </div>

            <div class="overflow-auto rounded-[1.5rem] border border-slate-200">
                <table class="min-w-full divide-y divide-slate-200 bg-white text-sm" id="planningDashboardTable">
                    <thead class="bg-slate-50 text-slate-600">
                        <tr>
                            <th class="px-4 py-3 text-left font-semibold">Chantier</th>
                            <th class="px-4 py-3 text-left font-semibold">Conducteurs</th>
                            <th class="px-4 py-3 text-left font-semibold">Etat</th>
                            <th class="px-4 py-3 text-left font-semibold">Planning</th>
                            <th class="px-4 py-3 text-left font-semibold">Horizon</th>
                            <th class="px-4 py-3 text-left font-semibold">Progression</th>
                            <th class="px-4 py-3 text-left font-semibold">Budget</th>
                            <th class="px-4 py-3 text-left font-semibold">Couverture</th>
                            <th class="px-4 py-3 text-left font-semibold">Derniere reunion</th>
                            <th class="px-4 py-3 text-left font-semibold">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 text-slate-700">
                        <?php if (empty($planningRows)): ?>
                            <tr>
                                <td colspan="10" class="px-6 py-12 text-center text-sm text-slate-500">
                                    Aucun chantier ne correspond aux filtres selectionnes.
                                </td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($planningRows as $row): ?>
                            <?php
                            $healthStatus = (string)($row['health_status'] ?? 'sans_planning');
                            $config = $statusConfig[$healthStatus] ?? $statusConfig['sans_planning'];
                            $conductorLabel = trim((string)($row['conductor_label'] ?? 'Non affecte'));
                            $searchBlob = strtolower(trim(
                                (string)($row['code'] ?? '') . ' ' .
                                    (string)($row['libelle'] ?? '') . ' ' .
                                    (string)($row['source_label'] ?? '') . ' ' .
                                    (string)($row['health_label'] ?? '') . ' ' .
                                    $conductorLabel . ' ' .
                                    (string)($row['last_meeting_date'] ?? '')
                            ));
                            $detailUrl = 'decaissement/detail_travaux_chantier.php?chantier_id=' . urlencode((string)($row['chantier_id'] ?? 0));
                            ?>
                            <tr class="planning-row hover:bg-slate-50/80 transition-colors" data-status="<?= htmlspecialchars($healthStatus, ENT_QUOTES, 'UTF-8') ?>" data-search="<?= htmlspecialchars($searchBlob, ENT_QUOTES, 'UTF-8') ?>">
                                <td class="px-4 py-4 align-top">
                                    <div class="flex items-start gap-3">
                                        <div class="mt-1 h-3 w-3 rounded-full <?= htmlspecialchars((string)$config['dot'], ENT_QUOTES, 'UTF-8') ?>"></div>
                                        <div>
                                            <div class="font-semibold text-slate-900"><?= htmlspecialchars((string)($row['libelle'] ?? 'Chantier'), ENT_QUOTES, 'UTF-8') ?></div>
                                            <div class="mt-1 text-xs text-slate-500">
                                                <?= htmlspecialchars(trim((string)($row['code'] ?? '')) !== '' ? (string)$row['code'] : 'Code non renseigne', ENT_QUOTES, 'UTF-8') ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-4 align-top">
                                    <?php if (!empty($row['conductors'])): ?>
                                        <div class="flex flex-wrap gap-2 max-w-[260px]">
                                            <?php foreach (($row['conductors'] ?? []) as $conductor): ?>
                                                <span class="inline-flex rounded-full bg-sky-50 px-3 py-1 text-xs font-semibold text-sky-700">
                                                    <?= htmlspecialchars(trim((string)($conductor['name'] ?? '')) !== '' ? (string)$conductor['name'] : 'Conducteur', ENT_QUOTES, 'UTF-8') ?>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-slate-400">Non affecte</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-4 align-top">
                                    <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold <?= htmlspecialchars((string)$config['badge'], ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars((string)($row['health_label'] ?? 'Sans planning'), ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                    <?php if ((int)($row['blocked_count'] ?? 0) > 0 || (int)($row['delayed_count'] ?? 0) > 0): ?>
                                        <div class="mt-2 text-xs text-slate-500">
                                            <?= number_format((int)($row['blocked_count'] ?? 0), 0, ',', ' ') ?> bloques · <?= number_format((int)($row['delayed_count'] ?? 0), 0, ',', ' ') ?> retards
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-4 align-top">
                                    <?php if (!empty($row['has_import'])): ?>
                                        <div class="font-semibold text-slate-900"><?= htmlspecialchars((string)($row['source_label'] ?? 'Planning Project'), ENT_QUOTES, 'UTF-8') ?></div>
                                        <div class="mt-1 text-xs text-slate-500">
                                            <?= number_format((int)($row['task_count'] ?? 0), 0, ',', ' ') ?> taches · <?= number_format((int)($row['leaf_count'] ?? 0), 0, ',', ' ') ?> feuilles
                                        </div>
                                        <div class="mt-1 text-xs text-slate-400">Importe le <?= htmlspecialchars(planningDashboardFormatDate((string)($row['imported_at'] ?? ''), true), ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php else: ?>
                                        <div class="font-semibold text-slate-500">Aucun import actif</div>
                                        <div class="mt-1 text-xs text-slate-400">Cliquez sur renseigner</div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-4 align-top text-sm text-slate-600">
                                    <?php if (!empty($row['planning_start']) || !empty($row['planning_end'])): ?>
                                        <div><?= htmlspecialchars(planningDashboardFormatDate($row['planning_start'] ?? null), ENT_QUOTES, 'UTF-8') ?></div>
                                        <div class="text-xs text-slate-400">au <?= htmlspecialchars(planningDashboardFormatDate($row['planning_end'] ?? null), ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php else: ?>
                                        <span class="text-slate-400">Non defini</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-4 align-top">
                                    <div class="space-y-3 min-w-[180px]">
                                        <div>
                                            <div class="mb-1 flex items-center justify-between text-xs text-slate-500">
                                                <span>Planifie</span>
                                                <span><?= number_format((float)($row['planned_progress'] ?? 0), 1, ',', ' ') ?>%</span>
                                            </div>
                                            <div class="h-2.5 rounded-full bg-slate-100">
                                                <div class="h-2.5 rounded-full bg-sky-500" style="width: <?= min(100, max(0, (float)($row['planned_progress'] ?? 0))) ?>%"></div>
                                            </div>
                                        </div>
                                        <div>
                                            <div class="mb-1 flex items-center justify-between text-xs text-slate-500">
                                                <span>Reel</span>
                                                <span><?= number_format((float)($row['actual_progress'] ?? 0), 1, ',', ' ') ?>%</span>
                                            </div>
                                            <div class="h-2.5 rounded-full bg-slate-100">
                                                <div class="h-2.5 rounded-full bg-teal-500" style="width: <?= min(100, max(0, (float)($row['actual_progress'] ?? 0))) ?>%"></div>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-4 align-top">
                                    <div class="space-y-2 min-w-[190px]">
                                        <div>
                                            <div class="text-sm font-semibold text-slate-900"><?= number_format((float)($row['budget_reference'] ?? 0), 0, ',', ' ') ?> F</div>
                                            <div class="text-xs text-slate-500">Budget de reference</div>
                                        </div>
                                        <div>
                                            <div class="text-sm font-semibold text-rose-700"><?= number_format((float)($row['total_depenses'] ?? 0), 0, ',', ' ') ?> F</div>
                                            <div class="text-xs text-slate-500">Depenses decaissees</div>
                                        </div>
                                        <div class="text-xs text-slate-500">
                                            Conso budget: <span class="font-semibold text-slate-800"><?= number_format((float)($row['budget_consumption_percent'] ?? 0), 1, ',', ' ') ?>%</span>
                                            · Ecart physique: <span class="font-semibold <?= (float)($row['progress_budget_gap'] ?? 0) < 0 ? 'text-rose-700' : 'text-emerald-700' ?>"><?= number_format((float)($row['progress_budget_gap'] ?? 0), 1, ',', ' ') ?> pts</span>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-4 align-top">
                                    <div class="font-semibold text-slate-900"><?= number_format((float)($row['coverage_percent'] ?? 0), 1, ',', ' ') ?>%</div>
                                    <div class="mt-1 text-xs text-slate-500">
                                        <?= number_format((int)($row['tracked_leaf_count'] ?? 0), 0, ',', ' ') ?> / <?= number_format((int)($row['leaf_count'] ?? 0), 0, ',', ' ') ?> points suivis
                                    </div>
                                    <div class="mt-1 text-xs text-slate-400">
                                        <?= number_format((int)($row['recent_update_count'] ?? 0), 0, ',', ' ') ?> mises a jour
                                    </div>
                                </td>
                                <td class="px-4 py-4 align-top text-sm text-slate-600">
                                    <div><?= htmlspecialchars(planningDashboardFormatDate($row['last_meeting_date'] ?? null), ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="mt-1 text-xs text-slate-400"><?= htmlspecialchars(planningDashboardFormatDate($row['last_update_at'] ?? null, true), ENT_QUOTES, 'UTF-8') ?></div>
                                </td>
                                <td class="px-4 py-4 align-top">
                                    <div class="flex flex-col gap-2 min-w-[180px]">
                                        <a href="<?= htmlspecialchars($detailUrl, ENT_QUOTES, 'UTF-8') ?>" class="inline-flex items-center justify-center rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800">
                                            <i class="fas fa-arrow-up-right-from-square mr-2"></i>
                                            <?= !empty($row['has_import']) ? 'Ouvrir le detail' : 'Renseigner ce chantier' ?>
                                        </a>
                                        <?php if (!empty($row['has_import'])): ?>
                                            <a href="<?= htmlspecialchars($detailUrl, ENT_QUOTES, 'UTF-8') ?>#projectRealtimeForm" class="inline-flex items-center justify-center rounded-xl bg-teal-50 px-4 py-2 text-sm font-semibold text-teal-700 transition hover:bg-teal-100">
                                                <i class="fas fa-pen-to-square mr-2"></i>
                                                Saisir l avancement
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
    const planningDashboardCharts = <?= json_encode($chartPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    const progressCtx = document.getElementById('planningProgressChart');
    if (progressCtx && planningDashboardCharts.labels.length > 0) {
        new window.Chart(progressCtx, {
            type: 'bar',
            data: {
                labels: planningDashboardCharts.labels,
                datasets: [{
                        label: 'Planifie',
                        data: planningDashboardCharts.planned,
                        backgroundColor: 'rgba(59, 130, 246, 0.75)',
                        borderRadius: 10,
                    },
                    {
                        label: 'Reel',
                        data: planningDashboardCharts.actual,
                        backgroundColor: 'rgba(13, 148, 136, 0.82)',
                        borderRadius: 10,
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                scales: {
                    x: {
                        beginAtZero: true,
                        max: 100,
                        ticks: {
                            callback: (value) => `${value}%`
                        }
                    }
                },
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
    }

    const stateCtx = document.getElementById('planningStateChart');
    if (stateCtx) {
        new window.Chart(stateCtx, {
            type: 'doughnut',
            data: {
                labels: planningDashboardCharts.states.labels,
                datasets: [{
                    data: planningDashboardCharts.states.values,
                    backgroundColor: [
                        'rgba(148, 163, 184, 0.82)',
                        'rgba(245, 158, 11, 0.82)',
                        'rgba(16, 185, 129, 0.82)',
                        'rgba(244, 63, 94, 0.82)',
                        'rgba(220, 38, 38, 0.84)',
                        'rgba(14, 165, 233, 0.82)'
                    ],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
    }

    const budgetLinkCtx = document.getElementById('planningBudgetLinkChart');
    if (budgetLinkCtx && planningDashboardCharts.budget_link.labels.length > 0) {
        new window.Chart(budgetLinkCtx, {
            type: 'bar',
            data: {
                labels: planningDashboardCharts.budget_link.labels,
                datasets: [{
                        label: 'Avancement reel',
                        data: planningDashboardCharts.budget_link.actual,
                        backgroundColor: 'rgba(13, 148, 136, 0.82)',
                        borderRadius: 10,
                    },
                    {
                        label: 'Consommation budgetaire',
                        data: planningDashboardCharts.budget_link.budget,
                        backgroundColor: 'rgba(244, 63, 94, 0.72)',
                        borderRadius: 10,
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 100,
                        ticks: {
                            callback: (value) => `${value}%`
                        }
                    }
                },
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
    }

    const statusFilter = document.getElementById('planningStatusFilter');
    const searchInput = document.getElementById('planningSearch');
    const tableRows = Array.from(document.querySelectorAll('.planning-row'));

    function applyPlanningFilters() {
        const selectedStatus = statusFilter ? statusFilter.value : 'all';
        const query = searchInput ? searchInput.value.trim().toLowerCase() : '';

        tableRows.forEach((row) => {
            const rowStatus = row.dataset.status || '';
            const rowSearch = row.dataset.search || '';

            const statusMatch = selectedStatus === 'all' || rowStatus === selectedStatus;
            const searchMatch = query === '' || rowSearch.includes(query);

            row.style.display = statusMatch && searchMatch ? '' : 'none';
        });
    }

    if (statusFilter) {
        statusFilter.addEventListener('change', applyPlanningFilters);
    }
    if (searchInput) {
        searchInput.addEventListener('input', applyPlanningFilters);
    }
</script>

<?php require_once __DIR__ . '/decaissement/includes/footer.php'; ?>