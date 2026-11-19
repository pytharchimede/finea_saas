<?php
require_once __DIR__ . '/_dashboard_common.php';

$pageTitle = 'Analytics logistiques';
$activeMenu = 'logistique';
$summary = logi_dashboard_summary($pdo);
$charts = logi_dashboard_charts($pdo);
$recentRequests = logi_dashboard_recent_requests($pdo, 10);
$dueReturns = logi_dashboard_due_returns($pdo, 10);
$dashboardTabs = function_exists('logi_dashboard_tabs')
    ? call_user_func('logi_dashboard_tabs', 'analytic')
    : '';

$availabilityRate = $summary['assets_total'] > 0 ? ($summary['assets_available'] / $summary['assets_total']) * 100 : 0;
$riskScore = min(100, ($summary['critical_incidents'] * 25) + ($summary['assets_breakdown'] * 12) + ($summary['documents_expired'] * 8) + ($summary['stock_critical'] * 5));

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>
<div class="min-h-screen bg-slate-50 px-6 pb-12">
    <div class="mx-auto max-w-7xl">
        <div class="mb-8 flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <p class="mb-2 text-xs font-semibold uppercase tracking-[0.22em] text-orange-600">Analytics logistiques</p>
                <h1 class="text-4xl font-semibold text-slate-900">Tendances, couts et risques</h1>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="dashboard_logistique.php" class="rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700">Dashboard</a>
                <a href="export_logistique.php?dataset=requests&format=pdf" class="rounded-2xl bg-rose-600 px-4 py-3 text-sm font-semibold text-white">PDF demandes</a>
            </div>
        </div>

        <?= $dashboardTabs ?>

        <section class="mb-6 grid grid-cols-1 gap-4 md:grid-cols-3">
            <div class="rounded-2xl bg-white p-5 shadow-sm"><div class="text-sm text-slate-500">Taux de disponibilite</div><div class="mt-3 text-4xl font-semibold text-emerald-700"><?= logi_dashboard_number($availabilityRate, 1) ?>%</div></div>
            <div class="rounded-2xl bg-white p-5 shadow-sm"><div class="text-sm text-slate-500">Indice de risque</div><div class="mt-3 text-4xl font-semibold text-orange-700"><?= logi_dashboard_number($riskScore) ?>/100</div></div>
            <div class="rounded-2xl bg-white p-5 shadow-sm"><div class="text-sm text-slate-500">Cout moyen carburant / L</div><div class="mt-3 text-4xl font-semibold text-slate-900"><?= $summary['fuel_liters_month'] > 0 ? logi_dashboard_money($summary['fuel_cost_month'] / $summary['fuel_liters_month']) : '0 F' ?></div></div>
        </section>

        <section class="mb-6 rounded-2xl border border-slate-100 bg-white p-6 shadow-sm">
            <h2 class="text-xl font-semibold text-slate-900">Evolution carburant sur 30 jours</h2>
            <div class="mt-4 h-[340px]"><canvas id="fuelChart"></canvas></div>
        </section>

        <section class="grid grid-cols-1 gap-6 xl:grid-cols-2">
            <article class="rounded-2xl bg-white p-6 shadow-sm">
                <h2 class="text-xl font-semibold text-slate-900">Demandes recentes</h2>
                <div class="mt-4 divide-y divide-slate-100">
                    <?php foreach ($recentRequests as $request): ?>
                        <div class="py-3"><div class="font-semibold text-slate-900"><?= logi_h(($request['request_code'] ?? '') ?: 'Demande #' . ($request['id'] ?? '')) ?></div><div class="text-sm text-slate-500"><?= logi_h($request['designation'] ?? 'Objet non precise') ?> - <?= logi_h(logi_dashboard_status_label($request['workflow_status'] ?? '')) ?></div></div>
                    <?php endforeach; ?>
                </div>
            </article>
            <article class="rounded-2xl bg-white p-6 shadow-sm">
                <h2 class="text-xl font-semibold text-slate-900">Retards de recuperation</h2>
                <div class="mt-4 divide-y divide-slate-100">
                    <?php foreach ($dueReturns as $request): ?>
                        <div class="py-3"><div class="font-semibold text-slate-900"><?= logi_h($request['designation'] ?? 'Objet non precise') ?></div><div class="text-sm text-slate-500"><?= logi_h(($request['current_holder'] ?? '') ?: ($request['requester_name'] ?? 'Non renseigne')) ?> - retour <?= !empty($request['date_end']) ? date('d/m/Y H:i', strtotime((string) $request['date_end'])) : '-' ?></div></div>
                    <?php endforeach; ?>
                    <?php if (empty($dueReturns)): ?><div class="py-8 text-center font-semibold text-slate-600">Aucun retard de recuperation.</div><?php endif; ?>
                </div>
            </article>
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
    new Chart(document.getElementById('fuelChart'), {data: {labels: <?= json_encode($charts['fuel_labels']) ?>, datasets: [{type: 'line', label: 'Litres', data: <?= json_encode($charts['fuel_liters']) ?>, borderColor: '#0284c7', backgroundColor: '#0284c7', pointBackgroundColor: '#0284c7', pointBorderColor: '#ffffff', borderWidth: 3, tension: .35, yAxisID: 'y1'}, {type: 'bar', label: 'Montant', data: <?= json_encode($charts['fuel_amounts']) ?>, backgroundColor: '#d97706', borderRadius: 10, yAxisID: 'y'}]}, options: {responsive: true, maintainAspectRatio: false, interaction: {mode: 'index', intersect: false}, scales: {x: {ticks: {color: mutedColor, font: {weight: '600'}}, grid: {color: gridColor}}, y: {ticks: {color: mutedColor, font: {weight: '600'}}, grid: {color: gridColor}}, y1: {position: 'right', ticks: {color: mutedColor, font: {weight: '600'}}, grid: {drawOnChartArea: false}}}, plugins: {legend: {labels: {color: textColor, font: {size: 12, weight: '600'}}}, tooltip: {backgroundColor: '#ffffff', titleColor: '#0f172a', bodyColor: '#334155', borderColor: '#cbd5e1', borderWidth: 1, padding: 12}}}});
});
</script>
<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>
