<?php

require_once __DIR__ . '/../auth_check.php';
require_once 'model/Database.php';
require_once 'model/Analytic.php';

$pdo = Database::getConnection();
$analytics = new Analytics($pdo);

$kpis = $analytics->getKPIs();
$cashflow = $analytics->getCashflowMensuel();
$topChantiers = $analytics->getTopChantiersDepenses();
$rentabilite = $analytics->getRentabiliteChantiers();
$categories = $analytics->getRepartitionCategories();
$budgets = $analytics->getBudgetDebourses();
$comparatif = $analytics->getComparatifChantiers();

$pageTitle = "Dashboard Analytique";
$activeMenu = "dashboard";

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';

?>

<div class="bg-gray-50 min-h-screen">

    <div class="container mx-auto px-6 py-8">

        <h1 class="text-3xl font-bold text-gray-800 mb-8">
            Dashboard Analytique
        </h1>

        <!-- KPI -->

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-10">

            <div class="bg-white p-6 rounded-xl shadow-md border-l-4 border-red-500">
                <p class="text-gray-500 text-sm">Dépenses</p>
                <p class="text-2xl font-bold text-red-600">
                    <?= number_format($kpis['total_depenses'], 0, ',', ' ') ?> F
                </p>
            </div>

            <div class="bg-white p-6 rounded-xl shadow-md border-l-4 border-yellow-500">
                <p class="text-gray-500 text-sm">Dépenses en attente</p>
                <p class="text-2xl font-bold text-yellow-600">
                    <?= number_format($kpis['depenses_attente'], 0, ',', ' ') ?> F
                </p>
            </div>

            <div class="bg-white p-6 rounded-xl shadow-md border-l-4 border-green-500">
                <p class="text-gray-500 text-sm">Total devis</p>
                <p class="text-2xl font-bold text-green-600">
                    <?= number_format($kpis['total_devis'], 0, ',', ' ') ?> F
                </p>
            </div>

            <div class="bg-white p-6 rounded-xl shadow-md border-l-4 border-indigo-500">
                <p class="text-gray-500 text-sm">Chantiers</p>
                <p class="text-2xl font-bold text-indigo-600">
                    <?= $kpis['nb_chantiers'] ?>
                </p>
            </div>

        </div>

        <canvas id="comparatifFinance"></canvas>


        <canvas id="budgetChantier"></canvas>

        <!-- EVOLUTION FINANCIERE -->

        <div class="bg-white p-6 rounded-xl shadow-md mb-10">

            <h2 class="text-lg font-semibold text-gray-700 mb-4">
                Evolution financière
            </h2>

            <canvas id="financeChart" height="80"></canvas>

        </div>

        <!-- GRAPHIQUES -->

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-10">

            <div class="bg-white p-6 rounded-xl shadow-md">

                <h3 class="font-semibold text-gray-700 mb-4">
                    Top chantiers dépenses
                </h3>

                <canvas id="topChantiersChart"></canvas>

            </div>

            <div class="bg-white p-6 rounded-xl shadow-md">

                <h3 class="font-semibold text-gray-700 mb-4">
                    Répartition dépenses
                </h3>

                <canvas id="categoriesChart"></canvas>

            </div>

        </div>

        <!-- RENTABILITE -->

        <div class="bg-white p-6 rounded-xl shadow-md">

            <h3 class="font-semibold text-gray-700 mb-4">
                Rentabilité chantiers
            </h3>

            <canvas id="rentabiliteChart"></canvas>

        </div>

    </div>

</div>


<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
    const cashflow = <?= json_encode($cashflow) ?>;

    const labels = cashflow.map(x => x.mois);
    const entrees = cashflow.map(x => x.entrees);
    const depenses = cashflow.map(x => x.depenses);


    // evolution finances

    new Chart(document.getElementById('financeChart'), {

        type: 'line',

        data: {
            labels: labels,
            datasets: [{
                    label: 'Entrées',
                    data: entrees,
                    borderColor: '#10b981',
                    backgroundColor: 'rgba(16,185,129,0.1)',
                    fill: true,
                    tension: 0.4
                },
                {
                    label: 'Dépenses',
                    data: depenses,
                    borderColor: '#ef4444',
                    backgroundColor: 'rgba(239,68,68,0.1)',
                    fill: true,
                    tension: 0.4
                }
            ]
        },

        options: {
            responsive: true,
            plugins: {
                legend: {
                    position: 'bottom'
                }
            }
        }

    });


    // top chantiers

    const topChantiers = <?= json_encode($topChantiers) ?>;

    new Chart(document.getElementById('topChantiersChart'), {

        type: 'bar',

        data: {
            labels: topChantiers.map(x => x.titre),
            datasets: [{
                data: topChantiers.map(x => x.total),
                backgroundColor: '#6366f1'
            }]
        },

        options: {
            plugins: {
                legend: {
                    display: false
                }
            }
        }

    });


    // categories

    const categories = <?= json_encode($categories) ?>;

    new Chart(document.getElementById('categoriesChart'), {

        type: 'doughnut',

        data: {
            labels: categories.map(x => x.categorie),
            datasets: [{
                data: categories.map(x => x.total),
                backgroundColor: [
                    '#6366f1',
                    '#10b981',
                    '#f59e0b',
                    '#ef4444',
                    '#8b5cf6'
                ]
            }]
        }

    });


    // rentabilite

    const rentabilite = <?= json_encode($rentabilite) ?>;

    new Chart(document.getElementById('rentabiliteChart'), {

        type: 'bar',

        data: {
            labels: rentabilite.map(x => x.titre),
            datasets: [{
                    label: 'Budget',
                    data: rentabilite.map(x => x.budget),
                    backgroundColor: '#3b82f6'
                },
                {
                    label: 'Dépenses',
                    data: rentabilite.map(x => x.depenses),
                    backgroundColor: '#ef4444'
                }
            ]
        }

    });


    const devis = <?= json_encode($cashflow) ?>;
    const budgets = <?= json_encode($budgets) ?>;

    new Chart(document.getElementById('comparatifFinance'), {

        type: 'line',

        data: {

            labels: devis.map(x => x.mois),

            datasets: [

                {
                    label: 'Devis',
                    data: devis.map(x => x.entrees),
                    borderColor: '#10b981'
                },

                {
                    label: 'Budget (Déboursés)',
                    data: budgets.map(x => x.total),
                    borderColor: '#6366f1'
                },

                {
                    label: 'Dépenses réelles',
                    data: devis.map(x => x.depenses),
                    borderColor: '#ef4444'
                }

            ]

        }

    })



    const comparatif = <?= json_encode($comparatif) ?>;

    new Chart(document.getElementById('budgetChantier'), {

        type: 'bar',

        data: {

            labels: comparatif.map(x => x.titre),

            datasets: [

                {
                    label: 'Budget',
                    data: comparatif.map(x => x.budget),
                    backgroundColor: '#6366f1'
                },

                {
                    label: 'Dépenses',
                    data: comparatif.map(x => x.depenses),
                    backgroundColor: '#ef4444'
                }

            ]

        }

    })
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>