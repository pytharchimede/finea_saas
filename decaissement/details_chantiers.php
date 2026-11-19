<?php
session_start();
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/model/Database.php';
require_once __DIR__ . '/model/Dashboard.php';
require_once __DIR__ . '/model/Fiche.php';
require_once __DIR__ . '/model/Chantier.php';
require_once __DIR__ . '/../services/ChantierPauseService.php';
\AccessManager::requirePageAccess();

AuditTrail::logPageConsultation([
    'module' => 'decaissement',
    'action' => 'details_chantiers_view',
    'entity_type' => 'chantier_dashboard_detail',
    'entity_label' => 'Details chantiers decaissement',
    'page_name' => 'details_chantiers.php',
    'details' => [
        'view_mode' => 'dashboard',
    ],
]);

$pdo = Database::getConnection();
$pauseService = new ChantierPauseService($pdo);
if (empty($_SESSION['chantier_pause_csrf'])) $_SESSION['chantier_pause_csrf'] = bin2hex(random_bytes(24));
$pauseMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pause_action'])) {
    try {
        if (!hash_equals($_SESSION['chantier_pause_csrf'], (string)($_POST['csrf'] ?? ''))) throw new DomainException('Session expirée. Rechargez la page.');
        $id = filter_var($_POST['chantier_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id) throw new DomainException('Chantier invalide.');
        if ($_POST['pause_action'] === 'resume') $pauseService->resume($id, (int)$_SESSION['user_id']);
        elseif ($_POST['pause_action'] === 'pause') $pauseService->pause($id, ($_POST['resume_mode'] ?? '') === 'auto' ? (string)($_POST['resume_date'] ?? '') : null, (int)$_SESSION['user_id']);
        else throw new DomainException('Action invalide.');
        header('Location: details_chantiers.php?pause_saved=1'); exit;
    } catch (Throwable $e) { $pauseMessage = $e->getMessage(); }
}
$dashboardModel = new Dashboard($pdo);
$ficheModel = new Fiche($pdo);
$chantierModel = new Chantier($pdo);

// Récupération des données pour démonstration
$donneesDashboard = $dashboardModel->getDonneesDashboard();
$chartsData = $dashboardModel->getChartsData();
$chantiersGestion = $dashboardModel->getChantiersAvecBudgets(true);
$pauseStates = $pauseService->states();

// Aligner l'affichage sur le calcul partagé du modèle Dashboard
foreach ($donneesDashboard['chantiers_budgets'] as &$c) {
    $budgetInterne = (float)($c['budget_interne_calc'] ?? $c['budget_total'] ?? 0);
    $c['budget_interne_calc'] = $budgetInterne;
    if ($budgetInterne > 0 && isset($c['total_depenses'])) {
        $c['pourcentage_consomme'] = ((float)$c['total_depenses'] / max($budgetInterne, 1)) * 100;
    }
}
unset($c);

$pageTitle = 'Détails Chantiers - DécaissApp';
$activeMenu = 'chantiers';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<div class="min-h-screen bg-gradient-to-br from-indigo-50 via-white to-purple-50">
    <div class="container mx-auto py-10 px-4">
        <!-- En-tête -->
        <div class="text-center mb-12">
            <h1 class="text-4xl font-bold bg-gradient-to-r from-indigo-600 to-purple-600 bg-clip-text text-transparent drop-shadow-lg">
                🏗️ Gestion Détaillée des Chantiers
            </h1>
            <p class="text-xl text-gray-600 mt-4">Analyse approfondie par chantier avec budgets, dépenses et indicateurs</p>
        </div>

        <!-- Filtres et contrôles -->
        <div class="bg-white rounded-2xl shadow-xl p-6 mb-8 border border-gray-100">
            <div class="flex flex-wrap gap-4 items-center justify-between">
                <div class="flex gap-4">
                    <select id="filterStatut" class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500">
                        <option value="">Tous les statuts</option>
                        <option value="normal">Normal (< 70%)</option>
                        <option value="attention">Attention (70-90%)</option>
                        <option value="critique">Critique (> 90%)</option>
                        <option value="pause">En pause</option>
                    </select>
                    <input type="text" id="searchChantier" placeholder="Rechercher un chantier..."
                        class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500">
                </div>
                <div class="flex gap-2">
                    <button onclick="exportExcel()" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors">
                        <i class="fas fa-file-excel mr-2"></i>Excel
                    </button>
                    <button onclick="exportPDF()" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors">
                        <i class="fas fa-file-pdf mr-2"></i>PDF
                    </button>
                    <button onclick="refreshData()" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors">
                        <i class="fas fa-sync mr-2"></i>Actualiser
                    </button>
                </div>
            </div>
        </div>

        <!-- Vue d'ensemble en cartes -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <div class="bg-white rounded-2xl shadow-xl p-6 text-center border border-indigo-100">
                <div class="text-3xl mb-2">🏗️</div>
                <h3 class="text-2xl font-bold text-indigo-600"><?= count($donneesDashboard['chantiers_budgets']) ?></h3>
                <p class="text-gray-600">Chantiers Actifs</p>
            </div>
            <div class="bg-white rounded-2xl shadow-xl p-6 text-center border border-green-100">
                <div class="text-3xl mb-2">💰</div>
                <h3 class="text-2xl font-bold text-green-600">
                    <?= number_format((float)array_sum(array_map(fn($x) => (float)($x['budget_interne_calc'] ?? $x['budget_total'] ?? 0), $donneesDashboard['chantiers_budgets'])), 0, ',', ' ') ?> F
                </h3>
                <p class="text-gray-600">Budget Total</p>
            </div>
            <div class="bg-white rounded-2xl shadow-xl p-6 text-center border border-orange-100">
                <div class="text-3xl mb-2">📊</div>
                <h3 class="text-2xl font-bold text-orange-600">
                    <?= number_format((float)array_sum(array_map(fn($x) => (float)($x['total_depenses'] ?? 0), $donneesDashboard['chantiers_budgets'])), 0, ',', ' ') ?> F
                </h3>
                <p class="text-gray-600">Total Dépensé</p>
            </div>
            <div class="bg-white rounded-2xl shadow-xl p-6 text-center border border-purple-100">
                <div class="text-3xl mb-2">📈</div>
                <h3 class="text-2xl font-bold text-purple-600">
                    <?= round((array_sum(array_column($donneesDashboard['chantiers_budgets'], 'total_depenses')) / max(array_sum(array_map(fn($x) => (float)($x['budget_interne_calc'] ?? $x['budget_total'] ?? 0), $donneesDashboard['chantiers_budgets'])), 1)) * 100, 1) ?>%
                </h3>
                <p class="text-gray-600">Taux Global</p>
            </div>
        </div>

        <?php if ($pauseMessage): ?><div class="mb-5 rounded-xl bg-red-50 border border-red-200 p-4 text-red-800"><?= htmlspecialchars($pauseMessage) ?></div><?php endif; ?>
        <?php if (isset($_GET['pause_saved'])): ?><div class="mb-5 rounded-xl bg-green-50 border border-green-200 p-4 text-green-800">Statut du chantier enregistré.</div><?php endif; ?>
        <div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-6" id="chantiersCards">
        <?php foreach ($chantiersGestion as $chantier):
            $id = (int)$chantier['id_chantier'];
            $pause = $pauseStates[$id] ?? null;
            $isPaused = !empty($pause['is_paused']);
            $budget = (float)($chantier['budget_interne_calc'] ?? $chantier['budget_total'] ?? 0);
            $spent = (float)($chantier['total_depenses'] ?? 0);
            $ratio = $budget > 0 ? $spent / $budget * 100 : 0;
            $status = $isPaused ? 'pause' : ($ratio > 90 ? 'critique' : ($ratio > 70 ? 'attention' : 'normal'));
        ?>
            <article class="chantier-row bg-white rounded-2xl border <?= $isPaused ? 'border-amber-300' : 'border-slate-200' ?> shadow-lg p-6 flex flex-col gap-5" data-status="<?= $status ?>">
                <div class="flex items-start justify-between gap-3">
                    <div><p class="text-xs font-semibold uppercase tracking-wider text-indigo-600"><?= htmlspecialchars((string)$chantier['num_chantier']) ?></p><h2 class="chantier-name text-xl font-bold text-slate-900 mt-1"><?= htmlspecialchars((string)$chantier['lib_chantier']) ?></h2><p class="text-sm text-slate-500 mt-1"><?= htmlspecialchars((string)($chantier['nom_client'] ?? '')) ?></p></div>
                    <span class="shrink-0 rounded-full px-3 py-1 text-xs font-bold <?= $isPaused ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-800' ?>"><?= $isPaused ? 'En pause' : 'Actif' ?></span>
                </div>
                <div class="grid grid-cols-2 gap-3 text-sm"><div class="rounded-xl bg-indigo-50 p-3"><span class="block text-slate-500">Budget</span><strong><?= number_format($budget, 0, ',', ' ') ?> F</strong></div><div class="rounded-xl bg-rose-50 p-3"><span class="block text-slate-500">Dépensé</span><strong><?= number_format($spent, 0, ',', ' ') ?> F</strong></div><div class="rounded-xl bg-emerald-50 p-3"><span class="block text-slate-500">Disponible</span><strong><?= number_format($budget-$spent, 0, ',', ' ') ?> F</strong></div><div class="rounded-xl bg-slate-50 p-3"><span class="block text-slate-500">Fiches</span><strong><?= (int)$chantier['nombre_fiches'] ?></strong></div></div>
                <div><div class="flex justify-between text-xs text-slate-500 mb-1"><span>Consommation</span><span><?= number_format($ratio, 1, ',', ' ') ?> %</span></div><div class="h-2 rounded-full bg-slate-100 overflow-hidden"><div class="h-full <?= $ratio > 90 ? 'bg-rose-500' : 'bg-indigo-500' ?>" style="width:<?= min(100, max(0, $ratio)) ?>%"></div></div></div>
                <?php if ($isPaused): ?><p class="text-sm text-amber-800 bg-amber-50 rounded-xl p-3"><?= $pause['resume_at'] ? 'Réactivation automatique le '.htmlspecialchars(date('d/m/Y', strtotime($pause['resume_at']))) : 'Réactivation manuelle uniquement' ?>. Facturation à encaisser disponible.</p><?php endif; ?>
                <div class="flex flex-wrap gap-2 mt-auto"><button onclick="voirDetails(<?= $id ?>)" class="rounded-lg bg-indigo-100 text-indigo-800 px-3 py-2 text-sm">Détails</button><?php if (!$isPaused): ?><button onclick="voirBudget(<?= $id ?>)" class="rounded-lg bg-orange-100 text-orange-800 px-3 py-2 text-sm">Budget</button><button onclick="voirTravaux(<?= $id ?>)" class="rounded-lg bg-cyan-100 text-cyan-800 px-3 py-2 text-sm">Travaux</button><button onclick="voirFiches(<?= $id ?>)" class="rounded-lg bg-green-100 text-green-800 px-3 py-2 text-sm">Fiches</button><?php endif; ?><button onclick="exportChantier(<?= $id ?>)" class="rounded-lg bg-purple-100 text-purple-800 px-3 py-2 text-sm">Export</button></div>
                <form method="post" class="border-t pt-4 flex flex-wrap gap-2 items-end"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['chantier_pause_csrf']) ?>"><input type="hidden" name="chantier_id" value="<?= $id ?>"><input type="hidden" name="pause_action" value="<?= $isPaused ? 'resume' : 'pause' ?>">
                <?php if (!$isPaused): ?><label class="text-sm">Réactivation <select name="resume_mode" class="block rounded-lg border p-2" onchange="this.form.querySelector('.resume-date').hidden=this.value!=='auto'"><option value="manual">Manuelle uniquement</option><option value="auto">Automatique à une date</option></select></label><label class="resume-date text-sm" hidden>Date <input type="date" name="resume_date" min="<?= date('Y-m-d', strtotime('+1 day')) ?>" class="block rounded-lg border p-2"></label><?php endif; ?>
                <button type="submit" class="rounded-lg px-4 py-2 text-sm font-semibold text-white <?= $isPaused ? 'bg-emerald-600' : 'bg-amber-600' ?>" onclick="return confirm('<?= $isPaused ? 'Réactiver ce chantier ?' : 'Mettre ce chantier en pause ?' ?>')"><?= $isPaused ? 'Réactiver' : 'Mettre en pause' ?></button></form>
            </article>
        <?php endforeach; ?>
        </div>

        <!-- Ancien tableau conservé uniquement pour compatibilité des exports historiques. -->
        <div class="hidden bg-white rounded-2xl shadow-xl overflow-hidden border border-gray-100">
            <div class="px-6 py-4 bg-gradient-to-r from-indigo-500 to-purple-600">
                <h2 class="text-xl font-bold text-white flex items-center">
                    <i class="fas fa-table mr-3"></i>
                    Détail par Chantier
                </h2>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full" id="chantiersTable">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Chantier
                                <i class="fas fa-sort ml-1 cursor-pointer" onclick="sortTable(0)"></i>
                            </th>
                            <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Budget Alloué
                                <i class="fas fa-sort ml-1 cursor-pointer" onclick="sortTable(1)"></i>
                            </th>
                            <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Montant Dépensé
                                <i class="fas fa-sort ml-1 cursor-pointer" onclick="sortTable(2)"></i>
                            </th>
                            <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Reste Disponible
                                <i class="fas fa-sort ml-1 cursor-pointer" onclick="sortTable(3)"></i>
                            </th>
                            <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Progression
                                <i class="fas fa-sort ml-1 cursor-pointer" onclick="sortTable(4)"></i>
                            </th>
                            <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Fiches
                                <i class="fas fa-sort ml-1 cursor-pointer" onclick="sortTable(5)"></i>
                            </th>
                            <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Statut
                            </th>
                            <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Actions
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($donneesDashboard['chantiers_budgets'] as $chantier):
                            $budgetInterneCalc = (float)($chantier['budget_interne_calc'] ?? $chantier['budget_total'] ?? 0);
                            $resteDisponible = $budgetInterneCalc - (float)$chantier['total_depenses'];
                            $statusClass = $chantier['pourcentage_consomme'] > 90 ? 'critique' : ($chantier['pourcentage_consomme'] > 70 ? 'attention' : 'normal');
                        ?>
                            <tr class="hover:bg-gray-50 transition-colors chantier-row" data-status="<?= $statusClass ?>">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="flex-shrink-0 h-12 w-12">
                                            <div class="h-12 w-12 rounded-full bg-gradient-to-br from-indigo-400 to-purple-500 flex items-center justify-center text-white font-bold text-lg">
                                                <?= strtoupper(substr($chantier['lib_chantier'], 0, 2)) ?>
                                            </div>
                                        </div>
                                        <div class="ml-4">
                                            <div class="text-sm font-medium text-gray-900 chantier-name">
                                                <?= htmlspecialchars($chantier['lib_chantier']) ?>
                                            </div>
                                            <div class="text-sm text-gray-500">
                                                N° <?= htmlspecialchars($chantier['num_chantier']) ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-semibold text-gray-900">
                                        <?= number_format($budgetInterneCalc, 0, ',', ' ') ?> F
                                    </div>
                                    <div class="text-xs text-gray-500">Budget alloué</div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-semibold text-red-600">
                                        <?= number_format((float)($chantier['total_depenses'] ?? 0), 0, ',', ' ') ?> F
                                    </div>
                                    <div class="text-xs text-gray-500">Consommé</div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-semibold <?= $resteDisponible > 0 ? 'text-green-600' : 'text-red-600' ?>">
                                        <?= number_format($resteDisponible, 0, ',', ' ') ?> F
                                    </div>
                                    <div class="text-xs text-gray-500">
                                        <?= $resteDisponible > 0 ? 'Disponible' : 'Dépassement' ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="w-full bg-gray-200 rounded-full h-3 mr-3">
                                            <div class="bg-<?= $chantier['pourcentage_consomme'] > 90 ? 'red' : ($chantier['pourcentage_consomme'] > 70 ? 'yellow' : 'green') ?>-600 h-3 rounded-full transition-all"
                                                style="width: <?= min($chantier['pourcentage_consomme'], 100) ?>%"></div>
                                        </div>
                                        <span class="text-sm font-medium text-gray-900 min-w-max">
                                            <?= number_format((float)($chantier['pourcentage_consomme'] ?? 0), 1) ?>%
                                        </span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center">
                                    <div class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-indigo-100 text-indigo-800">
                                        <i class="fas fa-file-invoice mr-1"></i>
                                        <?= $chantier['nombre_fiches'] ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php if ($chantier['pourcentage_consomme'] > 90): ?>
                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-red-100 text-red-800">
                                            🚨 Critique
                                        </span>
                                    <?php elseif ($chantier['pourcentage_consomme'] > 70): ?>
                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-yellow-100 text-yellow-800">
                                            ⚠️ Attention
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-green-100 text-green-800">
                                            ✅ Normal
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                    <div class="flex space-x-2">
                                        <button onclick="voirDetails(<?= $chantier['id_chantier'] ?>)"
                                            class="inline-flex items-center px-2 py-1 bg-indigo-100 text-indigo-700 rounded-lg hover:bg-indigo-200 transition-colors text-xs"
                                            title="Voir détails">
                                            <i class="fas fa-eye mr-1"></i>
                                            Détails
                                        </button>
                                        <button onclick="voirBudget(<?= $chantier['id_chantier'] ?>)"
                                            class="inline-flex items-center px-2 py-1 bg-orange-100 text-orange-700 rounded-lg hover:bg-orange-200 transition-colors text-xs"
                                            title="Détail budgétaire">
                                            <i class="fas fa-chart-pie mr-1"></i>
                                            Budget
                                        </button>
                                        <button onclick="voirTravaux(<?= $chantier['id_chantier'] ?>)"
                                            class="inline-flex items-center px-2 py-1 bg-cyan-100 text-cyan-700 rounded-lg hover:bg-cyan-200 transition-colors text-xs"
                                            title="Suivi des travaux">
                                            <i class="fas fa-calendar-alt mr-1"></i>
                                            Travaux
                                        </button>
                                        <button onclick="voirFiches(<?= $chantier['id_chantier'] ?>)"
                                            class="inline-flex items-center px-2 py-1 bg-green-100 text-green-700 rounded-lg hover:bg-green-200 transition-colors text-xs"
                                            title="Voir fiches">
                                            <i class="fas fa-list mr-1"></i>
                                            Fiches
                                        </button>
                                        <button onclick="exportChantier(<?= $chantier['id_chantier'] ?>)"
                                            class="inline-flex items-center px-2 py-1 bg-purple-100 text-purple-700 rounded-lg hover:bg-purple-200 transition-colors text-xs"
                                            title="Exporter">
                                            <i class="fas fa-download mr-1"></i>
                                            Export
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Graphique de répartition -->
        <div class="mt-8 bg-white rounded-2xl shadow-xl p-6 border border-gray-100">
            <h3 class="text-xl font-bold text-gray-800 mb-6 flex items-center">
                <i class="fas fa-chart-bar text-indigo-600 mr-3"></i>
                Budget vs Dépenses vs Entrées (par Chantier)
            </h3>
            <canvas id="repartitionDepensesChart" width="400" height="150"></canvas>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    // Données (construction côté PHP)
    const repartitionData = <?= json_encode(array_map(function ($chantier) {
                                $prixVente = (float)($chantier['budget_devis_total'] ?? $chantier['budget_total'] ?? 0);
                                $budgetInterne = (float)($chantier['budget_interne_calc'] ?? $chantier['budget_total'] ?? 0);
                                $depenseReelle = (float)($chantier['total_depenses'] ?? 0);
                                return [
                                    'label' => $chantier['lib_chantier'],
                                    'budget_interne' => $budgetInterne,
                                    'prix_vente' => $prixVente,
                                    'depense_reelle' => $depenseReelle
                                ];
                            }, $donneesDashboard['chantiers_budgets'])) ?>;

    const ctx = document.getElementById('repartitionDepensesChart').getContext('2d');
    const valueLabelPlugin = {
        id: 'valueLabelPlugin',
        afterDatasetsDraw(chart) {
            const ctx = chart.ctx;
            ctx.save();
            chart.data.datasets.forEach((ds, di) => {
                if (!chart.isDatasetVisible(di)) return;
                const meta = chart.getDatasetMeta(di);
                meta.data.forEach((el, i) => {
                    const v = ds.data[i];
                    if (!v) return;
                    ctx.font = '10px sans-serif';
                    ctx.fillStyle = '#111';
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'bottom';
                    ctx.fillText(Number(v).toLocaleString('fr-FR'), el.x, el.y - 3);
                });
            });
            ctx.restore();
        }
    };
    Chart.register(valueLabelPlugin);

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: repartitionData.map(i => i.label),
            datasets: [{
                    label: 'Dépenses réelles',
                    data: repartitionData.map(i => i.depense_reelle),
                    backgroundColor: 'rgba(220,38,38,0.85)',
                    borderColor: 'rgba(153,27,27,1)',
                    borderWidth: 2,
                    barPercentage: 0.18,
                    categoryPercentage: 0.6,
                    maxBarThickness: 18,
                    order: 1
                },
                {
                    label: 'Budget (Déboursé)',
                    data: repartitionData.map(i => i.budget_interne),
                    backgroundColor: 'rgba(59,130,246,0.55)',
                    borderColor: 'rgba(37,99,235,1)',
                    borderWidth: 1,
                    barPercentage: 0.24,
                    categoryPercentage: 0.6,
                    maxBarThickness: 20,
                    order: 0
                },
                {
                    label: 'Entrées (Devis)',
                    data: repartitionData.map(i => i.prix_vente),
                    backgroundColor: 'rgba(16,185,129,0.65)',
                    borderColor: 'rgba(4,120,87,1)',
                    borderWidth: 1,
                    barPercentage: 0.24,
                    categoryPercentage: 0.6,
                    maxBarThickness: 20,
                    order: 2
                }
            ]
        },
        options: {
            responsive: true,
            interaction: {
                mode: 'index',
                intersect: false
            },
            plugins: {
                legend: {
                    position: 'top',
                    labels: {
                        font: {
                            size: 11
                        }
                    }
                },
                tooltip: {
                    callbacks: {
                        label: ctx => {
                            const v = ctx.parsed.y || 0;
                            return ctx.dataset.label + ': ' + v.toLocaleString('fr-FR') + ' F';
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: v => v.toLocaleString('fr-FR') + ' F'
                    }
                }
            }
        }
    });

    // Fonctions de filtrage et tri
    function filterByStatus() {
        const filterValue = document.getElementById('filterStatut').value;
        const searchValue = document.getElementById('searchChantier').value.toLowerCase();
        const rows = document.querySelectorAll('.chantier-row');

        rows.forEach(row => {
            if ((filterValue === '' || row.dataset.status === filterValue) && row.querySelector('.chantier-name').textContent.toLowerCase().includes(searchValue)) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    }

    function searchChantiers() {
        filterByStatus();
    }

    // Fonctions d'actions
    function voirDetails(chantierId) {
        // Redirige vers la page de détail chantier (racine) qui contient l'UI d'archivage.
        window.location.href = '../details_chantier.php?id=' + encodeURIComponent(chantierId);
    }

    function voirBudget(chantierId) {
        window.location.href = 'detail_budgetaire_chantier.php?chantier_id=' + chantierId;
    }

    function voirTravaux(chantierId) {
        window.location.href = 'detail_travaux_chantier.php?chantier_id=' + chantierId;
    }

    function voirFiches(chantierId) {
        window.location.href = 'liste_fiches_achat.php?chantier=' + chantierId;
    }

    function exportChantier(chantierId) {
        // Export PDF du chantier sélectionné
        window.location.href = 'export_chantiers.php?format=pdf&chantier_id=' + encodeURIComponent(chantierId);
    }

    function exportExcel() {
        window.location.href = 'export_chantiers.php?format=excel';
    }

    function exportPDF() {
        window.location.href = 'export_chantiers.php?format=pdf';
    }

    function refreshData() {
        location.reload();
    }

    // Tri des colonnes
    let sortDirection = {};

    function sortTable(columnIndex) {
        const table = document.getElementById('chantiersTable');
        const tbody = table.querySelector('tbody');
        const rows = Array.from(tbody.querySelectorAll('tr'));

        const direction = sortDirection[columnIndex] === 'asc' ? 'desc' : 'asc';
        sortDirection[columnIndex] = direction;

        rows.sort((a, b) => {
            const cellA = a.children[columnIndex].textContent.trim();
            const cellB = b.children[columnIndex].textContent.trim();

            if (columnIndex === 1 || columnIndex === 2 || columnIndex === 3) {
                // Colonnes numériques
                const numA = parseFloat(cellA.replace(/[^\d.-]/g, ''));
                const numB = parseFloat(cellB.replace(/[^\d.-]/g, ''));
                return direction === 'asc' ? numA - numB : numB - numA;
            } else {
                // Colonnes texte
                return direction === 'asc' ? cellA.localeCompare(cellB) : cellB.localeCompare(cellA);
            }
        });

        rows.forEach(row => tbody.appendChild(row));
    }

    // Event listeners
    document.getElementById('filterStatut').addEventListener('change', filterByStatus);
    document.getElementById('searchChantier').addEventListener('input', searchChantiers);
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
