<?php
require_once __DIR__ . '/_dashboard_common.php';

$pageTitle = 'Statistiques logistiques';
$activeMenu = 'logistique';
$summary = logi_dashboard_summary($pdo);
$charts = logi_dashboard_charts($pdo);
$dashboardTabs = function_exists('logi_dashboard_tabs')
    ? call_user_func('logi_dashboard_tabs', 'statistique')
    : '';

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>
<div class="min-h-screen bg-slate-50 px-6 pb-12">
    <div class="mx-auto max-w-7xl">
        <div class="mb-8 flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <p class="mb-2 text-xs font-semibold uppercase tracking-[0.22em] text-orange-600">Statistiques logistiques</p>
                <h1 class="text-4xl font-semibold text-slate-900">Volumes et repartitions</h1>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="dashboard_logistique.php" class="rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700">Dashboard</a>
                <a href="export_logistique.php?dataset=summary&format=xlsx" class="rounded-2xl bg-emerald-600 px-4 py-3 text-sm font-semibold text-white">Excel</a>
                <a href="export_logistique.php?dataset=summary&format=pdf" class="rounded-2xl bg-rose-600 px-4 py-3 text-sm font-semibold text-white">PDF</a>
            </div>
        </div>

        <?= $dashboardTabs ?>

        <section class="mb-6 grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-2xl bg-white p-5 shadow-sm"><div class="text-sm text-slate-500">Disponibilite parc</div><div class="mt-3 text-3xl font-semibold"><?= logi_dashboard_number($summary['assets_available']) ?> / <?= logi_dashboard_number($summary['assets_total']) ?></div></div>
            <div class="rounded-2xl bg-white p-5 shadow-sm"><div class="text-sm text-slate-500">Chauffeurs disponibles</div><div class="mt-3 text-3xl font-semibold"><?= logi_dashboard_number($summary['drivers_available']) ?> / <?= logi_dashboard_number($summary['drivers_total']) ?></div></div>
            <div class="rounded-2xl bg-white p-5 shadow-sm"><div class="text-sm text-slate-500">Documents a risque</div><div class="mt-3 text-3xl font-semibold"><?= logi_dashboard_number($summary['documents_expired'] + $summary['documents_expiring']) ?></div></div>
            <div class="rounded-2xl bg-white p-5 shadow-sm"><div class="text-sm text-slate-500">Stock critique</div><div class="mt-3 text-3xl font-semibold"><?= logi_dashboard_number($summary['stock_critical']) ?></div></div>
        </section>

        <section class="grid grid-cols-1 gap-6 xl:grid-cols-2">
            <article class="rounded-2xl border border-slate-100 bg-white p-6 shadow-sm">
                <h2 class="text-xl font-semibold text-slate-900">Repartition du parc</h2>
                <div class="mt-4 h-[320px]"><canvas id="assetChart"></canvas></div>
            </article>
            <article class="rounded-2xl border border-slate-100 bg-white p-6 shadow-sm">
                <h2 class="text-xl font-semibold text-slate-900">Cout carburant par zone</h2>
                <div class="mt-4 h-[320px]"><canvas id="siteChart"></canvas></div>
            </article>
        </section>

        <section class="mt-6 grid grid-cols-1 gap-4 md:grid-cols-3">
            <a href="export_logistique.php?dataset=assets&format=xlsx" class="rounded-2xl bg-white p-5 font-semibold text-slate-900 shadow-sm">Exporter le parc Excel</a>
            <a href="export_logistique.php?dataset=requests&format=xlsx" class="rounded-2xl bg-white p-5 font-semibold text-slate-900 shadow-sm">Exporter les demandes Excel</a>
            <a href="export_logistique.php?dataset=stock&format=xlsx" class="rounded-2xl bg-white p-5 font-semibold text-slate-900 shadow-sm">Exporter le stock Excel</a>
        </section>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    if (!window.Chart) return;
    const textColor = '#1f2937';
    const mutedColor = '#64748b';
    const gridColor = 'rgba(100,116,139,.22)';
    const tooltip = {backgroundColor: '#ffffff', titleColor: '#0f172a', bodyColor: '#334155', borderColor: '#cbd5e1', borderWidth: 1, padding: 12};
    new Chart(document.getElementById('assetChart'), {type: 'doughnut', data: {labels: <?= json_encode($charts['asset_labels']) ?>, datasets: [{data: <?= json_encode($charts['asset_data']) ?>, backgroundColor: ['#059669','#d97706','#0284c7','#dc2626','#64748b'], borderColor: '#ffffff', borderWidth: 3}]}, options: {responsive: true, maintainAspectRatio: false, plugins: {legend: {labels: {color: textColor, font: {size: 12, weight: '600'}}}, tooltip}}});
    new Chart(document.getElementById('siteChart'), {type: 'bar', data: {labels: <?= json_encode($charts['site_labels']) ?>, datasets: [{data: <?= json_encode($charts['site_amounts']) ?>, backgroundColor: '#d97706', borderRadius: 10}]}, options: {responsive: true, maintainAspectRatio: false, indexAxis: 'y', scales: {x: {ticks: {color: mutedColor, font: {weight: '600'}}, grid: {color: gridColor}}, y: {ticks: {color: mutedColor, font: {weight: '600'}}, grid: {color: gridColor}}}, plugins: {legend: {display: false}, tooltip}}});
});
</script>
<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>
