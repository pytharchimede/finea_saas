<?php
require_once __DIR__ . '/_dashboard_common.php';

/** @var PDO $pdo Connexion initialisee dans _logistics_common.php via _dashboard_common.php. */
$pageTitle = 'Logistique';
$activeMenu = 'logistique';
$flashMessage = logi_dashboard_handle_request_action($pdo);
$summary = logi_dashboard_summary($pdo);
$alerts = logi_dashboard_alerts($summary);
$recentRequests = logi_dashboard_recent_requests($pdo, 6);
$dueReturns = logi_dashboard_due_returns($pdo, 6);
$charts = logi_dashboard_charts($pdo);
$field = function_exists('logi_dashboard_field_rows')
    ? call_user_func('logi_dashboard_field_rows', $pdo)
    : ['maintenance_due' => [], 'terrain' => [], 'documents' => []];
$dashboardTabs = function_exists('logi_dashboard_tabs')
    ? call_user_func('logi_dashboard_tabs', 'exploitation')
    : '';

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';

$availability = $summary['assets_total'] > 0 ? round(($summary['assets_available'] / $summary['assets_total']) * 100) : 0;
$urgentTotal = (int)$summary['requests_urgent'] + (int)$summary['critical_incidents'] + (int)$summary['assets_breakdown'] + (int)$summary['documents_expired'];
$kpis = [
    ['label' => 'Disponibilite parc', 'value' => $availability . '%', 'hint' => logi_dashboard_number($summary['assets_available']) . ' / ' . logi_dashboard_number($summary['assets_total']) . ' disponibles', 'icon' => 'fa-truck-field', 'href' => 'assets.php'],
    ['label' => 'Demandes ouvertes', 'value' => logi_dashboard_number($summary['requests_pending']), 'hint' => logi_dashboard_number($summary['requests_urgent']) . ' urgente(s)', 'icon' => 'fa-list-check', 'href' => 'requests.php'],
    ['label' => 'Carburant mois', 'value' => logi_dashboard_number($summary['fuel_liters_month']) . ' L', 'hint' => logi_dashboard_money($summary['fuel_cost_month']), 'icon' => 'fa-gas-pump', 'href' => 'fuel.php'],
    ['label' => 'Maintenance', 'value' => logi_dashboard_number($summary['maintenance_open']), 'hint' => logi_dashboard_money($summary['maintenance_cost_month'] + $summary['repair_cost_month']), 'icon' => 'fa-screwdriver-wrench', 'href' => 'maintenance.php'],
];
$sideLinks = [
    ['href' => 'requests.php', 'icon' => 'fa-list-check', 'label' => 'Demandes', 'hint' => 'Valider et suivre'],
    ['href' => 'planning.php', 'icon' => 'fa-calendar-days', 'label' => 'Planning', 'hint' => 'Reservations'],
    ['href' => 'assets.php', 'icon' => 'fa-truck-monster', 'label' => 'Parc', 'hint' => 'Engins et vehicules'],
    ['href' => 'asset_daily_activity.php', 'icon' => 'fa-calendar-day', 'label' => 'Suivi jour', 'hint' => 'Activite et conso'],
    ['href' => 'trip_batches.php', 'icon' => 'fa-route', 'label' => 'Voyages', 'hint' => 'Vagues chantier'],
    ['href' => 'fuel.php', 'icon' => 'fa-gas-pump', 'label' => 'Carburant', 'hint' => 'Bons et depots'],
    ['href' => 'maintenance.php', 'icon' => 'fa-screwdriver-wrench', 'label' => 'Maintenance', 'hint' => 'Atelier'],
    ['href' => 'documents.php', 'icon' => 'fa-file-shield', 'label' => 'Documents', 'hint' => 'Echeances'],
    ['href' => 'stock.php', 'icon' => 'fa-boxes-stacked', 'label' => 'Stock', 'hint' => 'Pieces'],
    ['href' => 'settings.php', 'icon' => 'fa-sliders', 'label' => 'Parametres', 'hint' => 'Reglages'],
];
?>
<div class="logi-workspace min-h-screen pb-12">
    <div class="container mx-auto px-4 py-8">
        <?php if ($flashMessage !== ''): ?>
            <div class="mb-5 rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 font-semibold text-emerald-800 shadow-sm"><?= logi_h($flashMessage) ?></div>
        <?php endif; ?>

        <section class="logi-hero relative overflow-hidden rounded-[32px] border border-slate-900/10 bg-gradient-to-r from-slate-950 via-[#1d2b57] to-slate-900 px-8 py-10 text-white shadow-[0_24px_80px_rgba(15,23,42,0.32)]">
            <div class="absolute -right-16 -top-16 h-52 w-52 rounded-full bg-[#fabd02]/20 blur-2xl"></div>
            <div class="absolute bottom-0 right-28 h-28 w-28 rounded-full bg-sky-300/20 blur-2xl"></div>
            <div class="relative flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
                <div class="max-w-3xl">
                    <div class="mb-3 flex flex-wrap items-center gap-3">
                        <p class="text-xs font-semibold uppercase tracking-[0.35em] text-amber-200">Module logistique</p>
                        <span class="inline-flex items-center rounded-full border border-white/15 bg-white/10 px-3 py-1 text-xs font-semibold text-white">
                            <i class="fas fa-bell mr-2 text-[#fabd02]"></i>
                            <?= logi_dashboard_number($urgentTotal) ?> point<?= $urgentTotal > 1 ? 's' : '' ?> a surveiller
                        </span>
                    </div>
                    <h1 class="text-3xl font-semibold tracking-tight md:text-5xl">Pilotage terrain du parc <?= logi_h((string)($layoutCompanyBranding['company_name'] ?? 'FINEA')) ?></h1>
                    <p class="mt-4 max-w-2xl text-sm text-slate-200 md:text-base">
                        Une vue courte pour decider vite : disponibilite, demandes, retours, carburant, maintenance et alertes operationnelles.
                    </p>
                </div>
                <div class="flex flex-wrap gap-3">
                    <button type="button" id="logiThemeToggle" class="rounded-2xl border px-5 py-3 text-sm font-semibold">Mode sombre</button>
                    <a href="requests.php" class="inline-flex items-center rounded-2xl bg-white px-5 py-3 text-sm font-semibold text-slate-900 shadow-lg shadow-slate-950/20 transition hover:-translate-y-0.5 hover:bg-amber-50">
                        <i class="fas fa-list-check mr-2 text-orange-600"></i>Validations
                    </a>
                    <a href="planning_form.php" class="logi-accent inline-flex items-center rounded-2xl px-5 py-3 text-sm font-semibold text-slate-950 shadow-lg shadow-amber-500/20 transition hover:-translate-y-0.5 hover:bg-amber-300">
                        <i class="fas fa-plus mr-2"></i>Affecter
                    </a>
                    <a href="asset_daily_activity.php" class="inline-flex items-center rounded-2xl border border-white/15 px-5 py-3 text-sm font-semibold text-white/90 transition hover:bg-white/10">
                        <i class="fas fa-calendar-day mr-2"></i>Suivi journalier
                    </a>
                    <a href="export_logistique.php?dataset=summary&format=pdf" class="inline-flex items-center rounded-2xl border border-white/15 px-5 py-3 text-sm font-semibold text-white/90 transition hover:bg-white/10">
                        <i class="fas fa-file-pdf mr-2"></i>Rapport PDF
                    </a>
                </div>
            </div>
        </section>

        <?= $dashboardTabs ?>

        <section class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <?php foreach ($kpis as $kpi): ?>
                <a href="<?= logi_h($kpi['href']) ?>" class="group block rounded-[26px] border border-white/80 bg-white/95 p-5 shadow-[0_16px_45px_rgba(15,23,42,0.05)] transition hover:-translate-y-0.5 hover:border-orange-200 hover:shadow-[0_20px_55px_rgba(15,23,42,0.10)]">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-500"><?= logi_h($kpi['label']) ?></p>
                            <div class="mt-3 text-3xl font-bold text-slate-950"><?= logi_h($kpi['value']) ?></div>
                            <p class="mt-2 text-sm text-slate-600"><?= logi_h($kpi['hint']) ?></p>
                        </div>
                        <span class="logi-kpi-icon flex h-12 w-12 items-center justify-center rounded-2xl bg-orange-50 text-orange-700 transition group-hover:bg-orange-600 group-hover:text-white"><i class="fas <?= logi_h($kpi['icon']) ?>"></i></span>
                    </div>
                    <div class="mt-4 text-sm font-semibold text-orange-700 opacity-0 transition group-hover:opacity-100">Ouvrir <i class="fas fa-arrow-right ml-1"></i></div>
                </a>
            <?php endforeach; ?>
        </section>

        <div class="mt-8 grid gap-6 xl:grid-cols-[1.35fr_0.65fr]">
            <main class="space-y-6">
                <section class="rounded-[30px] border border-white/80 bg-white/95 p-6 shadow-[0_18px_55px_rgba(15,23,42,0.06)]">
                    <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.28em] text-orange-600">Operations prioritaires</p>
                            <h2 class="mt-2 text-2xl font-semibold text-slate-950">Validations et retours</h2>
                        </div>
                        <div class="flex flex-wrap gap-2 text-sm">
                            <a href="requests.php" class="rounded-full bg-orange-50 px-3 py-2 font-semibold text-orange-700 hover:bg-orange-100">Toutes les demandes</a>
                            <a href="planning.php" class="rounded-full bg-slate-100 px-3 py-2 font-semibold text-slate-700 hover:bg-slate-200">Planning</a>
                        </div>
                    </div>
                    <div class="mt-5 grid gap-4 lg:grid-cols-2">
                        <div class="rounded-3xl border border-slate-100 bg-slate-50/90 p-4">
                            <div class="mb-3 flex items-center justify-between">
                                <h3 class="font-semibold text-slate-900">Demandes recentes</h3><span class="text-xs font-semibold text-slate-500"><?= count($recentRequests) ?> ligne(s)</span>
                            </div>
                            <div class="space-y-3">
                                <?php foreach ($recentRequests as $request): ?>
                                    <a href="requests.php?q=<?= rawurlencode((string)(($request['request_code'] ?? '') ?: ($request['designation'] ?? ''))) ?>" class="group block rounded-2xl bg-white px-4 py-3 shadow-sm transition hover:-translate-y-0.5 hover:bg-orange-50 hover:shadow-md">
                                        <div class="flex items-start justify-between gap-3">
                                            <div class="min-w-0">
                                                <div class="truncate font-semibold text-slate-900"><?= logi_h(($request['request_code'] ?? '') ?: 'Demande #' . ($request['id'] ?? '')) ?></div>
                                                <div class="mt-1 truncate text-sm text-slate-500"><?= logi_h(($request['designation'] ?? '') ?: ($request['need_type'] ?? 'Objet non precise')) ?></div>
                                            </div>
                                            <?= logi_badge((string)($request['workflow_status'] ?? 'submitted')) ?>
                                        </div>
                                        <div class="mt-2 text-xs font-semibold text-orange-700 opacity-0 transition group-hover:opacity-100">Voir la demande <i class="fas fa-arrow-right ml-1"></i></div>
                                    </a>
                                <?php endforeach; ?>
                                <?php if (!$recentRequests): ?><div class="rounded-2xl border border-dashed border-slate-300 p-6 text-center font-semibold text-slate-600">Aucune demande recente.</div><?php endif; ?>
                            </div>
                        </div>
                        <div class="rounded-3xl border border-amber-100 bg-amber-50/70 p-4">
                            <div class="mb-3 flex items-center justify-between">
                                <h3 class="font-semibold text-slate-900">Retours attendus</h3><span class="text-xs font-semibold text-amber-700"><?= count($dueReturns) ?> a recuperer</span>
                            </div>
                            <div class="space-y-3">
                                <?php foreach ($dueReturns as $request): ?>
                                    <form method="post" class="rounded-2xl bg-white px-4 py-3 shadow-sm">
                                        <input type="hidden" name="request_id" value="<?= (int)($request['id'] ?? 0) ?>">
                                        <div class="font-semibold text-slate-900"><?= logi_h($request['designation'] ?? 'Element non precise') ?></div>
                                        <div class="mt-1 text-sm text-slate-500"><?= logi_h(($request['current_holder'] ?? '') ?: ($request['requester_name'] ?? 'Detenteur non renseigne')) ?></div>
                                        <div class="mt-3 flex gap-2">
                                            <input name="return_location" value="<?= logi_h($request['current_site'] ?? '') ?>" placeholder="Lieu retour" class="min-w-0 flex-1 rounded-xl border border-slate-200 px-3 py-2 text-sm">
                                            <button name="logi_request_action" value="recover" class="rounded-xl bg-slate-900 px-3 py-2 text-xs font-semibold text-white">Recuperer</button>
                                        </div>
                                    </form>
                                <?php endforeach; ?>
                                <?php if (!$dueReturns): ?><div class="rounded-2xl border border-dashed border-amber-200 bg-white/70 p-6 text-center font-semibold text-slate-600">Aucun retour en retard.</div><?php endif; ?>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="grid gap-6 lg:grid-cols-2">
                    <article class="rounded-[30px] border border-white/80 bg-white/95 p-6 shadow-[0_18px_55px_rgba(15,23,42,0.06)]">
                        <div class="mb-4 flex items-center justify-between">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-[0.24em] text-orange-600">Parc</p>
                                <h2 class="mt-1 text-xl font-semibold text-slate-950">Disponibilite</h2>
                            </div>
                            <span class="logi-availability rounded-full px-3 py-1 text-sm font-semibold text-[#1d2b57]"><?= $availability ?>%</span>
                        </div>
                        <div class="h-64"><canvas id="assetStatusChart"></canvas></div>
                    </article>
                    <article class="rounded-[30px] border border-white/80 bg-white/95 p-6 shadow-[0_18px_55px_rgba(15,23,42,0.06)]">
                        <div class="mb-4 flex items-center justify-between">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-[0.24em] text-orange-600">Carburant</p>
                                <h2 class="mt-1 text-xl font-semibold text-slate-950">30 derniers jours</h2>
                            </div>
                            <a href="fuel.php" class="text-sm font-semibold text-orange-700">Ouvrir</a>
                        </div>
                        <div class="h-64"><canvas id="fuelChart"></canvas></div>
                    </article>
                </section>

                <section class="rounded-[30px] border border-white/80 bg-white/95 p-6 shadow-[0_18px_55px_rgba(15,23,42,0.06)]">
                    <div class="mb-4 flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.24em] text-orange-600">Terrain</p>
                            <h2 class="mt-1 text-xl font-semibold text-slate-950">Activite recente</h2>
                        </div>
                        <a href="asset_daily_activity.php" class="text-sm font-semibold text-orange-700">Journal</a>
                    </div>
                    <div class="grid gap-3 md:grid-cols-2">
                        <?php foreach (array_slice(($field['terrain'] ?? []), 0, 4) as $row): ?>
                            <a href="asset_daily_activity.php?q=<?= rawurlencode((string)(($row['asset_name'] ?? '') ?: ($row['driver_name'] ?? ''))) ?>" class="group block rounded-2xl border border-slate-100 bg-slate-50 px-4 py-3 transition hover:-translate-y-0.5 hover:border-orange-200 hover:bg-orange-50">
                                <div class="font-semibold text-slate-900"><?= logi_h($row['asset_name'] ?: 'Ressource') ?> <span class="text-slate-500">/</span> <?= logi_h($row['driver_name'] ?: 'Chauffeur') ?></div>
                                <div class="mt-1 text-sm text-slate-600"><?= logi_h($row['site_label'] ?: 'Chantier non renseigne') ?> - <?= logi_h($row['activity_type']) ?></div>
                                <div class="mt-2 text-xs font-semibold text-orange-700 opacity-0 transition group-hover:opacity-100">Ouvrir le suivi <i class="fas fa-arrow-right ml-1"></i></div>
                            </a>
                        <?php endforeach; ?>
                        <?php if (empty($field['terrain'])): ?><div class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-6 text-center font-semibold text-slate-600 md:col-span-2">Aucune activite recente.</div><?php endif; ?>
                    </div>
                </section>
            </main>

            <aside class="logi-sidebar rounded-[30px] border border-slate-200 bg-slate-950 p-7 text-white shadow-[0_22px_70px_rgba(15,23,42,0.28)] xl:sticky xl:top-6 xl:self-start">
                <div class="mb-5">
                    <p class="text-xs font-semibold uppercase tracking-[0.28em] text-amber-200">Acces rapides</p>
                    <h2 class="mt-2 text-2xl font-semibold">Logistique</h2>
                    <p class="mt-2 text-sm text-slate-300">Les actions frequentes restent disponibles sans charger la vue centrale.</p>
                </div>
                <div class="space-y-3">
                    <?php foreach ($sideLinks as $index => $link): ?>
                        <a href="<?= logi_h($link['href']) ?>" class="relative block rounded-2xl border <?= $index < 2 ? 'border-amber-400/25 bg-amber-500/10 hover:bg-amber-500/15' : 'border-white/10 bg-white/5 hover:bg-white/10' ?> px-4 py-4 transition">
                            <?php if ($link['href'] === 'requests.php' && $summary['requests_pending'] > 0): ?>
                                <span class="absolute right-4 top-4 rounded-full bg-[#fabd02] px-2.5 py-1 text-xs font-bold text-slate-950"><?= logi_dashboard_number($summary['requests_pending']) ?></span>
                            <?php endif; ?>
                            <div class="flex items-center gap-3">
                                <span class="flex h-10 w-10 items-center justify-center rounded-2xl bg-white/10 text-[#fabd02]"><i class="fas <?= logi_h($link['icon']) ?>"></i></span>
                                <span><span class="block font-semibold"><?= logi_h($link['label']) ?></span><span class="text-sm text-slate-400"><?= logi_h($link['hint']) ?></span></span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </aside>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const root=document.documentElement, toggle=document.getElementById('logiThemeToggle');
        const css=name=>getComputedStyle(document.body).getPropertyValue(name).trim();
        const textColor=css('--lg-ink'), mutedColor=css('--lg-muted'), gridColor=css('--lg-border');
        function syncTheme(){
            const dark=root.classList.contains('finea-dark-mode');
            document.body.classList.toggle('finea-dark-mode',dark);
            toggle.textContent=dark?'☀ Mode clair':'☾ Mode sombre';
            toggle.setAttribute('aria-pressed',String(dark));
            for(const id of ['assetStatusChart','fuelChart']){
                const chart=window.Chart?.getChart(id);if(!chart)continue;
                const p=chart.options.plugins;
                p.legend.labels.color=css('--lg-ink');
                Object.assign(p.tooltip,{backgroundColor:css('--lg-panel'),titleColor:css('--lg-ink'),bodyColor:css('--lg-muted'),borderColor:css('--lg-border')});
                for(const scale of Object.values(chart.options.scales||{})){if(scale.ticks)scale.ticks.color=css('--lg-muted');if(scale.grid)scale.grid.color=css('--lg-border');}
                if(id==='assetStatusChart')chart.data.datasets[0].borderColor=css('--lg-panel');
                else chart.data.datasets[1].pointBorderColor=css('--lg-panel');
                chart.update('none');
            }
        }
        toggle.addEventListener('click',()=>{const dark=!root.classList.contains('finea-dark-mode');root.classList.toggle('finea-dark-mode',dark);try{['finea.app.theme','finea.portal.theme'].forEach(k=>localStorage.setItem(k,dark?'dark':'light'));}catch(e){}syncTheme();});
        new MutationObserver(syncTheme).observe(root,{attributes:true,attributeFilter:['class']});
        syncTheme();
        if (!window.Chart) return;
        const commonPlugins = {
            legend: {
                labels: {
                    color: textColor,
                    font: {
                        size: 12,
                        weight: '600'
                    },
                    boxWidth: 12,
                    boxHeight: 12
                }
            },
            tooltip: {
                backgroundColor: css('--lg-panel'),
                titleColor: textColor,
                bodyColor: mutedColor,
                borderColor: gridColor,
                borderWidth: 1,
                padding: 12
            }
        };
        new Chart(document.getElementById('assetStatusChart'), {
            type: 'doughnut',
            data: {
                labels: <?= json_encode($charts['asset_labels']) ?>,
                datasets: [{
                    data: <?= json_encode($charts['asset_data']) ?>,
                    backgroundColor: ['#059669', '#d97706', '#0284c7', '#dc2626', '#64748b'],
                    borderColor: css('--lg-panel'),
                    borderWidth: 3
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: commonPlugins,
                cutout: '60%'
            }
        });
        new Chart(document.getElementById('fuelChart'), {
            data: {
                labels: <?= json_encode($charts['fuel_labels']) ?>,
                datasets: [{
                    type: 'bar',
                    label: 'Litres',
                    data: <?= json_encode($charts['fuel_liters']) ?>,
                    backgroundColor: css('--brand-accent'),
                    borderRadius: 10
                }, {
                    type: 'line',
                    label: 'Montant',
                    data: <?= json_encode($charts['fuel_amounts']) ?>,
                    borderColor: css('--brand-primary'),
                    backgroundColor: css('--brand-primary'),
                    pointBackgroundColor: css('--brand-primary'),
                    pointBorderColor: css('--lg-panel'),
                    borderWidth: 3,
                    tension: .35,
                    yAxisID: 'y1'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                scales: {
                    x: {
                        ticks: {
                            color: mutedColor,
                            font: {
                                weight: '600'
                            }
                        },
                        grid: {
                            color: gridColor
                        }
                    },
                    y: {
                        ticks: {
                            color: mutedColor,
                            font: {
                                weight: '600'
                            }
                        },
                        grid: {
                            color: gridColor
                        }
                    },
                    y1: {
                        position: 'right',
                        ticks: {
                            color: mutedColor,
                            font: {
                                weight: '600'
                            }
                        },
                        grid: {
                            drawOnChartArea: false
                        }
                    }
                },
                plugins: commonPlugins
            }
        });
    });
</script>
<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>