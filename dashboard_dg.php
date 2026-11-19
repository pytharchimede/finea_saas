<?php
require_once __DIR__ . '/auth_check.php';
AccessManager::requirePageAccess();
require_once __DIR__ . '/model/Database.php';
require_once __DIR__ . '/model/AuditTrail.php';
require_once __DIR__ . '/controllers/DgDashboardController.php';

$pdo = Database::getConnection();
$dashboard = (new DgDashboardController($pdo))->index((int) ($_SESSION['user_id'] ?? 0), $_GET);
$userName = trim((string) ($_SESSION['user_name'] ?? '')) ?: 'Direction';
$dashboardMode = strtolower(trim((string) ($_GET['view'] ?? 'synthese')));
if (!in_array($dashboardMode, ['synthese', 'statistique', 'analytique'], true)) {
    $dashboardMode = 'synthese';
}

AuditTrail::logPageConsultation([
    'module' => 'direction',
    'action' => 'dg_dashboard_view',
    'entity_type' => 'dashboard',
    'entity_label' => $dashboard['period'],
], $pdo);

function dgMoney($value): string
{
    return number_format((float) $value, 0, ',', ' ') . ' FCFA';
}

function dgKpi(string $label, string $value, string $meta, string $icon): void
{
    echo '<article class="finea-kpi-card"><div class="dg-kpi-top"><span class="finea-kpi-label">' . htmlspecialchars($label) . '</span><i class="fas ' . htmlspecialchars($icon) . '"></i></div><strong class="finea-kpi-value">' . htmlspecialchars($value) . '</strong><span class="finea-kpi-meta">' . htmlspecialchars($meta) . '</span></article>';
}

function dgPercent($value): string
{
    return number_format((float) $value, 1, ',', ' ') . ' %';
}

function dgPeriodLabel(string $period,string $mode): string
{
    $months=[1=>'janvier',2=>'février',3=>'mars',4=>'avril',5=>'mai',6=>'juin',7=>'juillet',8=>'août',9=>'septembre',10=>'octobre',11=>'novembre',12=>'décembre'];
    if($mode==='quarter'&&preg_match('/^(\d{4})-Q([1-4])$/',$period,$m))return ((int)$m[2]).'ᵉ trimestre '.$m[1];
    if($mode==='week'&&preg_match('/^\d{4}-\d{2}-\d{2}$/',$period))return 'Semaine du '.(new DateTimeImmutable($period))->format('d/m/Y');
    if($mode==='day'&&preg_match('/^\d{4}-\d{2}-\d{2}$/',$period))return (new DateTimeImmutable($period))->format('d/m/Y');
    if(preg_match('/^(\d{4})-(\d{2})$/',$period,$m))return ($months[(int)$m[2]]??$period).' '.$m[1];return $period;
}

function dgCardActions(string $cardId,string $detailUrl,array $dashboard): void
{
    $query='date_from='.urlencode($dashboard['start']).'&amp;date_to='.urlencode($dashboard['end']);
    echo '<div class="dg-card-actions"><a href="'.htmlspecialchars($detailUrl).'" title="Voir les détails"><i class="fas fa-arrow-up-right-from-square"></i><span>Détails</span></a><button type="button" data-export-card="'.htmlspecialchars($cardId).'" title="Exporter cette carte en image"><i class="fas fa-download"></i><span>Image</span></button><a href="export_dashboard_dg.php?format=pdf&amp;'.$query.'&amp;section='.urlencode($cardId).'" title="Exporter le rapport PDF"><i class="fas fa-file-pdf"></i><span>PDF</span></a><a href="export_dashboard_dg.php?format=xlsx&amp;'.$query.'&amp;section='.urlencode($cardId).'" title="Exporter les données Excel"><i class="fas fa-file-excel"></i><span>Excel</span></a></div>';
}

$pageTitle = 'Pilotage Direction Generale';
$activeMenu = 'dashboard_dg';
require_once __DIR__ . '/decaissement/includes/header.php';
require_once __DIR__ . '/decaissement/includes/navbar.php';
?>
<link rel="stylesheet" href="assets/css/finea-ui.css">
<style>
.dg-layout{display:grid;grid-template-columns:repeat(12,minmax(0,1fr));gap:18px}.dg-span-8{grid-column:span 8}.dg-span-6{grid-column:span 6}.dg-span-4{grid-column:span 4}.dg-span-12{grid-column:span 12}.dg-chart-box{position:relative;height:300px}.dg-kpi-top{display:flex;justify-content:space-between;gap:12px}.dg-kpi-top i{color:#fabd02;font-size:1.25rem}.dg-alert-list{display:grid;gap:10px}.dg-alert{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:13px 15px;border:1px solid #fecaca;border-left:4px solid #dc2626;border-radius:8px;background:#fff7f7}.dg-metric-row{display:flex;justify-content:space-between;gap:12px;padding:11px 0;border-bottom:1px solid #edf1f7}.dg-metric-row:last-child{border:0}.dg-metric-row strong{color:#1d2b57;text-align:right}.dg-bars{display:flex;align-items:end;gap:10px;height:180px;padding-top:15px}.dg-bar-wrap{display:flex;flex:1;height:100%;flex-direction:column;justify-content:end;align-items:center;gap:7px}.dg-bar{width:min(42px,80%);min-height:4px;border-radius:6px 6px 2px 2px;background:#1d2b57}.dg-bar-label{font-size:.72rem;color:#64748b}.dg-top-item{display:grid;grid-template-columns:1fr auto;gap:8px;padding:11px 0;border-bottom:1px solid #edf1f7}.dg-top-item:last-child{border:0}.dg-tabs{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin:18px 0}.dg-tab{display:flex;gap:13px;align-items:flex-start;padding:17px;border:1px solid #dfe6f1;border-radius:8px;background:#fff;color:#172033;text-decoration:none;box-shadow:0 6px 20px rgba(29,43,87,.05);transition:.18s ease}.dg-tab:hover{transform:translateY(-2px);border-color:#aebbd1}.dg-tab.is-active{color:#fff;background:#1d2b57;border-color:#1d2b57}.dg-tab-icon{display:grid;place-items:center;width:42px;height:42px;flex:0 0 42px;border-radius:8px;color:#1d2b57;background:#fef3c7}.dg-tab.is-active .dg-tab-icon{color:#fabd02;background:rgba(255,255,255,.12)}.dg-tab strong{display:block;margin-bottom:4px}.dg-tab small{display:block;color:#64748b;line-height:1.45}.dg-tab.is-active small{color:#cbd5e1}.dg-stat-grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:16px}.dg-stat-card{padding:20px;border:1px solid #dfe6f1;border-radius:8px;background:#fff;box-shadow:0 8px 24px rgba(29,43,87,.06)}.dg-stat-card span{font-size:.78rem;font-weight:800;color:#64748b}.dg-stat-card strong{display:block;margin:12px 0 10px;color:#1d2b57;font-size:2rem}.dg-progress{height:8px;overflow:hidden;border-radius:999px;background:#e9eef6}.dg-progress i{display:block;height:100%;border-radius:inherit;background:#1d2b57}.dg-stat-card p{margin:10px 0 0;color:#64748b;font-size:.78rem;line-height:1.45}.dg-analysis-head{padding:24px;border:1px solid #cbd5e1;border-left:5px solid #fabd02;border-radius:8px;background:#fff}.dg-analysis-head h2{margin:0 0 8px;color:#1d2b57}.dg-analysis-head p{margin:0;color:#475569;font-size:1rem;line-height:1.65}.dg-insights{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin-top:18px}.dg-insight{padding:20px;border:1px solid #dfe6f1;border-radius:8px;background:#fff;box-shadow:0 8px 24px rgba(29,43,87,.06)}.dg-insight-top{display:flex;gap:12px;align-items:center}.dg-insight-icon{display:grid;place-items:center;width:42px;height:42px;border-radius:8px;background:#eff6ff;color:#1d4ed8}.dg-insight h3{margin:0;color:#172033;font-size:1rem}.dg-insight p{margin:14px 0 0;color:#475569;line-height:1.55}.dg-recommendation{padding-top:13px;border-top:1px solid #edf1f7;font-size:.88rem}.dg-insight--danger{border-top:4px solid #dc2626}.dg-insight--warning{border-top:4px solid #d97706}.dg-insight--success{border-top:4px solid #16a34a}.dg-insight--info{border-top:4px solid #2563eb}.dg-context{margin:0 0 18px;padding:14px 16px;border:1px solid #dbeafe;border-radius:8px;background:#eff6ff;color:#1e3a8a;font-size:.9rem}.dg-export-menu{display:flex;flex-wrap:wrap;gap:10px}.dg-stat-section{margin-top:20px}.dg-natural-title{margin-bottom:6px!important}.dg-natural-copy{margin:0 0 18px;color:#64748b;line-height:1.55}body.portal-theme-dark .dg-tab,body.portal-theme-dark .dg-stat-card,body.portal-theme-dark .dg-analysis-head,body.portal-theme-dark .dg-insight{background:#172033;border-color:#334155;color:#e5e7eb}body.portal-theme-dark .dg-tab strong,body.portal-theme-dark .dg-stat-card strong,body.portal-theme-dark .dg-analysis-head h2,body.portal-theme-dark .dg-insight h3,body.portal-theme-dark .dg-metric-row strong{color:#fff}body.portal-theme-dark .dg-tab small,body.portal-theme-dark .dg-stat-card p,body.portal-theme-dark .dg-analysis-head p,body.portal-theme-dark .dg-insight p{color:#cbd5e1}@media(max-width:1100px){.dg-stat-grid{grid-template-columns:repeat(2,1fr)}}@media(max-width:980px){.dg-span-8,.dg-span-6,.dg-span-4{grid-column:span 12}.dg-tabs,.dg-insights{grid-template-columns:1fr}}@media(max-width:640px){.dg-stat-grid{grid-template-columns:1fr}.dg-export-menu{width:100%}.dg-export-menu .finea-action-btn{flex:1}.dg-chart-box{height:240px}}@media print{nav,.finea-header-actions,.finea-filter-card,.dg-tabs{display:none!important}.finea-shell{background:#fff}.finea-container{width:100%;padding:0}.finea-section-card,.finea-kpi-card{box-shadow:none;break-inside:avoid}}
</style>
<style>.dg-card-head{display:flex;align-items:flex-start;justify-content:space-between;gap:14px}.dg-card-actions{display:flex;flex-wrap:wrap;gap:6px}.dg-card-actions a,.dg-card-actions button{display:inline-flex;align-items:center;gap:6px;border:1px solid #dbe3ef;border-radius:8px;background:#fff;padding:7px 9px;color:#334155;font-size:.72rem;font-weight:800;text-decoration:none;cursor:pointer}.dg-card-actions a:hover,.dg-card-actions button:hover{border-color:#1d2b57;color:#1d2b57;background:#f8fafc}.dg-card-actions span{display:none}.dg-period-switch{display:flex;flex-wrap:wrap;gap:6px;margin:10px 0 16px}.dg-period-switch a{border-radius:999px;background:#eef2f7;padding:7px 12px;color:#475569;font-size:.75rem;font-weight:800;text-decoration:none}.dg-period-switch a.is-active{background:#1d2b57;color:#fff}@media(min-width:1200px){.dg-card-actions span{display:inline}}</style>
<style>.dg-data-warning{display:flex;align-items:center;justify-content:space-between;gap:18px;margin:18px 0;padding:18px 20px;border:1px solid #fcd34d;border-left:5px solid #d97706;border-radius:12px;background:#fffbeb;color:#78350f}.dg-data-warning>div{display:grid;grid-template-columns:auto 1fr;column-gap:12px}.dg-data-warning>div>i{grid-row:1/3;font-size:1.3rem;color:#d97706}.dg-data-warning p{margin:5px 0 0;line-height:1.5}.dg-warning-actions{display:flex;flex:0 0 auto;gap:8px}.dg-data-warning a{border-radius:10px;background:#92400e;padding:10px 14px;color:#fff;font-weight:800;text-decoration:none}.dg-data-warning a:first-child{border:1px solid #d97706;background:#fff;color:#92400e}@media(max-width:760px){.dg-data-warning{align-items:flex-start;flex-direction:column}.dg-warning-actions{flex-wrap:wrap}}</style>
<style>.dg-stat-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.dg-stat-card{border-top:4px solid #1d2b57}.dg-stat-card:last-child{grid-column:1/-1}.dg-stat-label{display:flex;align-items:center;gap:8px;color:#1d2b57!important;font-size:.92rem!important}.dg-stat-formula{padding:10px 12px;border-radius:7px;background:#f8fafc;color:#334155!important}.dg-stat-card p b{color:#1d2b57}.dg-stat-action{padding-top:10px;border-top:1px solid #e8edf4;color:#1e3a8a!important;font-weight:700}.dg-stat-action i{margin-right:6px;color:#fabd02}body.portal-theme-dark .dg-stat-formula{background:#0f172a}body.portal-theme-dark .dg-stat-label,body.portal-theme-dark .dg-stat-card p b{color:#fff!important}@media(max-width:760px){.dg-stat-grid{grid-template-columns:1fr}.dg-stat-card:last-child{grid-column:auto}}</style>
<main class="finea-shell">
    <div class="finea-container">
        <header class="finea-page-header">
            <div>
                <h1>Bonjour <?= htmlspecialchars($userName) ?></h1>
                <p>Vue de direction au <?= htmlspecialchars(date('d/m/Y')) ?>. Les finances couvrent <?= htmlspecialchars(date('m/Y', strtotime($dashboard['start']))) ?>; les alertes opérationnelles reflètent la situation actuelle.</p>
            </div>
            <div class="finea-header-actions">
                <div class="dg-export-menu">
                    <a class="finea-action-btn finea-action-btn--secondary" href="export_dashboard_dg.php?format=pdf&amp;period=<?= urlencode($dashboard['period']) ?>&amp;date_from=<?=urlencode($dashboard['start'])?>&amp;date_to=<?=urlencode($dashboard['end'])?>"><i class="fas fa-file-pdf"></i> PDF direction</a>
                    <a class="finea-action-btn finea-action-btn--secondary" href="export_dashboard_dg.php?format=xlsx&amp;period=<?= urlencode($dashboard['period']) ?>&amp;date_from=<?=urlencode($dashboard['start'])?>&amp;date_to=<?=urlencode($dashboard['end'])?>"><i class="fas fa-file-excel"></i> Excel</a>
                </div>
                <a class="finea-action-btn finea-action-btn--accent" href="facturation/tresorerie_chantiers.php?date_from=<?=urlencode($dashboard['start'])?>&amp;date_to=<?=urlencode($dashboard['end'])?>"><i class="fas fa-vault"></i> Trésorerie détaillée</a>
            </div>
        </header>

        <?php $financialCompliance = (array)($dashboard['financial_compliance'] ?? []); if (($financialCompliance['incomplete'] ?? 0) > 0): ?>
        <section class="dg-data-warning">
            <div><i class="fas fa-triangle-exclamation"></i><strong>Fiabilité documentaire : <?=htmlspecialchars(dgPercent($financialCompliance['reliability_rate'] ?? 0))?></strong><p><?=(int)$financialCompliance['incomplete']?> chantier(s) actif(s) sur <?=(int)$financialCompliance['total']?> restent à régulariser. Leurs données continuent d’être incluses dans les statistiques en temps réel ; cet indicateur signale le niveau de prudence nécessaire dans leur interprétation.</p></div>
            <span class="dg-warning-actions"><a href="chantiers/regularisation.php">Régulariser</a><a href="chantiers/rapport_regularisation.php">Rapport d’analyse <i class="fas fa-arrow-right"></i></a></span>
        </section>
        <?php endif; ?>

        <nav class="dg-tabs" aria-label="Vues du tableau de bord">
            <?php foreach ([
                'synthese' => ['fa-gauge-high', 'Synthèse de direction', 'Les chiffres clés, alertes et situations à arbitrer.'],
                'statistique' => ['fa-chart-column', 'Vue statistique', 'Des ratios simples pour comparer les niveaux de performance.'],
                'analytique' => ['fa-comments', 'Analyse de direction', 'Les données expliquées en langage naturel avec actions conseillées.'],
            ] as $mode => $tab): ?>
                <a class="dg-tab <?= $dashboardMode === $mode ? 'is-active' : '' ?>" href="dashboard_dg.php?view=<?= urlencode($mode) ?>&amp;period=<?= urlencode($dashboard['period']) ?>&amp;date_from=<?=urlencode($dashboard['start'])?>&amp;date_to=<?=urlencode($dashboard['end'])?>">
                    <span class="dg-tab-icon"><i class="fas <?= htmlspecialchars($tab[0]) ?>"></i></span>
                    <span><strong><?= htmlspecialchars($tab[1]) ?></strong><small><?= htmlspecialchars($tab[2]) ?></small></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <form class="finea-filter-card" method="get">
            <input type="hidden" name="view" value="<?= htmlspecialchars($dashboardMode) ?>">
            <input type="hidden" name="period" value="<?=htmlspecialchars($dashboard['period'])?>">
            <input type="hidden" name="trend_mode" value="<?=htmlspecialchars($dashboard['trend_mode'])?>">
            <div class="finea-filter-grid">
                <div class="finea-field"><label for="date_from">Date de début</label><input class="finea-input" id="date_from" name="date_from" type="date" value="<?= htmlspecialchars($dashboard['start']) ?>"></div>
                <div class="finea-field"><label for="date_to">Date de fin</label><input class="finea-input" id="date_to" name="date_to" type="date" value="<?= htmlspecialchars($dashboard['end']) ?>"></div>
                <button class="finea-action-btn finea-action-btn--primary" type="submit"><i class="fas fa-chart-line"></i> Mettre à jour l’analyse</button>
            </div>
        </form>

        <?php if ($dashboardMode === 'synthese'): ?>
        <section class="finea-grid finea-kpi-grid">
            <?php if ($dashboard['finance'] !== null) dgKpi('Décaissements réalisés ce mois', dgMoney($dashboard['finance']['month_paid']), $dashboard['finance']['pending_count'] . ' décision(s) financière(s) en attente', 'fa-money-bill-transfer'); ?>
            <?php if ($dashboard['chantiers'] !== null) dgKpi('Chantiers en activité', (string) $dashboard['chantiers']['active'], $dashboard['chantiers']['late'] . ' chantier(s) avec échéance dépassée', 'fa-helmet-safety'); ?>
            <?php if ($dashboard['tickets'] !== null) dgKpi('Demandes internes ouvertes', (string) $dashboard['tickets']['open'], $dashboard['tickets']['overdue'] . ' demande(s) hors délai', 'fa-headset'); ?>
            <?php if ($dashboard['crm'] !== null) dgKpi('Affaires commerciales potentielles', dgMoney($dashboard['crm']['pipeline']), $dashboard['crm']['opportunities'] . ' opportunité(s) suivie(s)', 'fa-handshake'); ?>
            <?php if ($dashboard['ao'] !== null) dgKpi('Appels d’offres encore accessibles', (string) $dashboard['ao']['active'], $dashboard['ao']['due_soon'] . ' échéance(s) dans moins de 7 jours', 'fa-bullhorn'); ?>
            <?php dgKpi('Points nécessitant une attention', (string) $dashboard['critical_count'], 'À examiner par ordre de priorité', 'fa-triangle-exclamation'); ?>
            <?php if ($dashboard['treasury_decision'] !== null) dgKpi('Solde réel global', dgMoney($dashboard['treasury_decision']['real_balance']), 'Entrées réellement encaissées moins sorties', 'fa-vault'); ?>
            <?php if ($dashboard['treasury_decision'] !== null) dgKpi('Solde projeté à 90 jours', dgMoney($dashboard['treasury_decision']['projected_balance']), dgMoney($dashboard['treasury_decision']['forecast_inflows']) . ' d’entrées attendues', 'fa-chart-line'); ?>
            <?php if ($dashboard['treasury_decision'] !== null) dgKpi('FEB prévisionnelles', dgMoney($dashboard['treasury_decision']['forecast_outflows'] ?? 0), 'Sorties prévues sur l’intervalle', 'fa-file-invoice-dollar'); ?>
            <?php if ($dashboard['treasury_decision'] !== null) dgKpi('Prêts à rembourser', dgMoney($dashboard['treasury_decision']['loans_outstanding'] ?? 0), dgMoney($dashboard['treasury_decision']['loans_received'] ?? 0).' reçus', 'fa-hand-holding-dollar'); ?>
            <?php if ($dashboard['treasury_decision'] !== null) dgKpi('Chiffre d’affaires HT encaissé', dgMoney($dashboard['treasury_decision']['turnover_ht'] ?? 0), (($dashboard['treasury_decision']['inflow_growth_pct'] ?? null)===null?'Sans base comparable':dgPercent($dashboard['treasury_decision']['inflow_growth_pct']).' vs période précédente').' · TVA exclue', 'fa-arrow-trend-up'); ?>
            <?php if ($dashboard['treasury_decision'] !== null) dgKpi('Sorties réelles', dgMoney($dashboard['treasury_decision']['real_outflows'] ?? 0), (($dashboard['treasury_decision']['outflow_growth_pct'] ?? null)===null?'Sans base comparable':dgPercent($dashboard['treasury_decision']['outflow_growth_pct']).' vs période précédente'), 'fa-arrow-trend-down'); ?>
            <?php if ($dashboard['treasury_decision'] !== null) dgKpi('Impôt sur bénéfice estimé', dgMoney($dashboard['treasury_decision']['profit_tax_25'] ?? 0), '25 % du bénéfice positif réalisé sur la période', 'fa-percent'); ?>
            <?php if ($dashboard['treasury_decision'] !== null) dgKpi('Solde TVA', dgMoney($dashboard['treasury_decision']['vat_balance'] ?? 0), dgMoney($dashboard['treasury_decision']['vat_remitted'] ?? 0).' déjà reversés', 'fa-receipt'); ?>
        </section>

        <div class="dg-layout">
            <section class="finea-section-card dg-span-12">
                <h2 class="finea-section-title dg-natural-title"><i class="fas fa-triangle-exclamation"></i> Ce qui demande une décision ou une relance</h2>
                <p class="dg-natural-copy">Cette liste rassemble uniquement les sujets qui peuvent ralentir un chantier, une validation ou une réponse commerciale.</p>
                <div class="dg-alert-list">
                    <?php
                    $alerts = [
                        ['Demandes de dépenses urgentes à valider', (int) ($dashboard['finance']['urgent_feb'] ?? 0), 'validation_demandes_finances.php'],
                        ['Demandes internes dont le délai est dépassé', (int) ($dashboard['tickets']['overdue'] ?? 0), 'ticketing/index.php'],
                        ['Chantiers dont l’échéance est dépassée', (int) ($dashboard['chantiers']['late'] ?? 0), 'chantiers/dashboard.php'],
                        ['Appels d’offres à remettre sous 7 jours', (int) ($dashboard['ao']['due_soon'] ?? 0), 'veille_ao.php'],
                        ['Documents proches de leur échéance', (int) ($dashboard['documentation']['expiring'] ?? 0), 'documentation/index.php'],
                        ['Engins actuellement indisponibles', (int) ($dashboard['logistics']['unavailable_assets'] ?? 0), 'logi/dashboard_logistique.php'],
                    ];
                    $shown = 0;
                    foreach ($alerts as $alert):
                        if ($alert[1] <= 0) continue;
                        $shown++;
                    ?>
                        <a class="dg-alert" href="<?= htmlspecialchars($alert[2]) ?>"><strong><?= htmlspecialchars($alert[0]) ?></strong><span class="finea-status-badge finea-status-badge--danger"><?= $alert[1] ?></span></a>
                    <?php endforeach; ?>
                    <?php if ($shown === 0): ?><div class="finea-empty-state">Aucun blocage majeur n’est détecté dans les modules accessibles.</div><?php endif; ?>
                </div>
            </section>

            <?php if ($dashboard['treasury_decision'] !== null): ?>
            <section class="finea-section-card dg-span-8" id="card-cashflow">
                <div class="dg-card-head"><h2 class="finea-section-title"><i class="fas fa-arrow-right-arrow-left"></i> Entrées et sorties de trésorerie</h2><?php dgCardActions('card-cashflow','facturation/tresorerie_chantiers.php?date_from='.urlencode($dashboard['start']).'&date_to='.urlencode($dashboard['end']),$dashboard); ?></div>
                <p class="dg-natural-copy">Compare les encaissements clients effectivement reçus aux décaissements réellement exécutés.</p>
                <div class="dg-chart-box"><canvas id="dgCashflowChart"></canvas></div>
            </section>
            <section class="finea-section-card dg-span-4" id="card-coverage">
                <div class="dg-card-head"><h2 class="finea-section-title"><i class="fas fa-shield-check"></i> Chantiers à sécuriser commercialement</h2><?php dgCardActions('card-coverage','offres/dashboard.php',$dashboard); ?></div>
                <p class="dg-natural-copy">Lecture décisionnelle : chantiers disposant d’un BC client exploitable, dossiers historiques à régulariser et chantiers ayant déjà donné lieu à facturation.</p>
                <div class="dg-chart-box"><canvas id="dgCoverageChart"></canvas></div>
            </section>
            <section class="finea-section-card dg-span-6" id="card-forecast">
                <h2 class="finea-section-title"><i class="fas fa-calendar-check"></i> Encaissements prévus à 90 jours</h2>
                <p class="dg-natural-copy">Factures émises et non encaissées, regroupées par mois prévu.</p>
                <div class="dg-chart-box"><canvas id="dgForecastChart"></canvas></div>
            </section>
            <section class="finea-section-card dg-span-6" id="card-sites">
                <div class="dg-card-head"><h2 class="finea-section-title"><i class="fas fa-building-circle-arrow-right"></i> Exposition financière par chantier</h2><?php dgCardActions('card-sites','chantiers/dashboard.php',$dashboard); ?></div>
                <p class="dg-natural-copy">Compare contrat, budget total à décaisser, déjà décaissé, reste à décaisser, facturé, encaissé et marge prévisionnelle. Le nom complet apparaît au survol.</p>
                <div class="overflow-x-auto rounded-xl border border-slate-100 bg-slate-50/40 p-3"><div id="dgChantierFinanceChartBox" style="height:430px;min-width:760px"><canvas id="dgChantierFinanceChart"></canvas></div></div>
            </section>
            <?php endif; ?>

            <?php if ($dashboard['finance'] !== null): ?>
            <section class="finea-section-card dg-span-8" id="card-disbursements">
                <div class="dg-card-head"><h2 class="finea-section-title">Évolution des décaissements réalisés</h2><?php dgCardActions('card-disbursements','decaissement/depenses_planifiees.php',$dashboard); ?></div>
                <div class="dg-period-switch"><?php foreach(['day'=>'Jour','week'=>'Semaine','month'=>'Mois','quarter'=>'Trimestre'] as $mode=>$label):?><a class="<?=$dashboard['trend_mode']===$mode?'is-active':''?>" href="?view=<?=urlencode($dashboardMode)?>&date_from=<?=urlencode($dashboard['start'])?>&date_to=<?=urlencode($dashboard['end'])?>&trend_mode=<?=$mode?>"><?=$label?></a><?php endforeach;?></div>
                <div class="dg-bars">
                    <?php
                    $maxTrend = max(array_map(static fn(array $r): float => (float) ($r['total'] ?? 0), $dashboard['finance']['trend']) ?: [1]);
                    foreach ($dashboard['finance']['trend'] as $trend):
                        $height = max(4, (int) (((float) $trend['total'] / max(1, $maxTrend)) * 110));
                    ?>
                        <div class="dg-bar-wrap" title="<?= htmlspecialchars(dgPeriodLabel((string)$trend['period'],$dashboard['trend_mode']).' · '.dgMoney($trend['total'])) ?>"><div class="dg-bar" style="height:<?= $height ?>px"></div><span class="dg-bar-label"><?= htmlspecialchars(dgPeriodLabel((string)$trend['period'],$dashboard['trend_mode'])) ?></span></div>
                    <?php endforeach; ?>
                    <?php if ($dashboard['finance']['trend'] === []): ?><div class="finea-empty-state">Pas encore de tendance financiere disponible.</div><?php endif; ?>
                </div>
            </section>
            <section class="finea-section-card dg-span-4">
                <h2 class="finea-section-title">Décisions financières en cours</h2>
                <div class="dg-metric-row"><span>Montant restant à arbitrer</span><strong><?= dgMoney($dashboard['finance']['pending_amount']) ?></strong></div>
                <div class="dg-metric-row"><span>Demandes en attente de décision</span><strong><?= $dashboard['finance']['pending_count'] ?></strong></div>
                <div class="dg-metric-row"><span>Demandes refusées</span><strong><?= $dashboard['finance']['rejected_count'] ?></strong></div>
            </section>
            <?php endif; ?>

            <?php if ($dashboard['chantiers'] !== null): ?>
            <section class="finea-section-card dg-span-6">
                <h2 class="finea-section-title">Chantiers consommateurs</h2>
                <?php foreach ($dashboard['chantiers']['top_consumers'] as $row): ?>
                    <div class="dg-top-item"><span><?= htmlspecialchars(trim(($row['code'] ?? '') . ' - ' . ($row['titre'] ?? ''))) ?></span><strong><?= dgMoney($row['total']) ?></strong></div>
                <?php endforeach; ?>
                <?php if ($dashboard['chantiers']['top_consumers'] === []): ?><div class="finea-empty-state">Aucune consommation chantier disponible.</div><?php endif; ?>
            </section>
            <?php endif; ?>

            <?php if ($dashboard['rh'] !== null): ?>
            <section class="finea-section-card dg-span-6">
                <h2 class="finea-section-title">Ressources humaines</h2>
                <div class="dg-metric-row"><span>Effectif actif</span><strong><?= $dashboard['rh']['headcount'] ?></strong></div>
                <div class="dg-metric-row"><span>Soumis au pointage</span><strong><?= $dashboard['rh']['attendance_headcount'] ?></strong></div>
                <div class="dg-metric-row"><span>Présents aujourd’hui</span><strong><?= $dashboard['rh']['present'] ?></strong></div>
                <div class="dg-metric-row"><span>Missions / demi-journées</span><strong><?= $dashboard['rh']['mission'] ?> / <?= $dashboard['rh']['half_day'] ?></strong></div>
                <div class="dg-metric-row"><span>Congés / maladie</span><strong><?= $dashboard['rh']['conge'] ?> / <?= $dashboard['rh']['maladie'] ?></strong></div>
                <div class="dg-metric-row"><span>Absents / retards</span><strong><?= $dashboard['rh']['absent'] ?> / <?= $dashboard['rh']['late'] ?></strong></div>
                <?php if (!$dashboard['rh']['is_working_day']): ?>
                    <div class="dg-metric-row"><span>Jour non ouvré / repos</span><strong><?= $dashboard['rh']['repos'] ?></strong></div>
                <?php endif; ?>
                <div class="dg-metric-row"><span>Demandes en attente</span><strong><?= $dashboard['rh']['pending_requests'] ?></strong></div>
            </section>
            <?php endif; ?>

            <?php foreach ([
                'tickets' => ['Traitement des demandes internes', ['Demandes ouvertes' => 'open', 'Demandes urgentes' => 'urgent', 'Délais dépassés' => 'overdue', 'Demandes affectées au DG' => 'assigned_to_me']],
                'crm' => ['Activité commerciale', ['Prospects suivis' => 'prospects', 'Affaires potentielles' => 'opportunities', 'Relances à effectuer' => 'followups', 'Clients enregistrés' => 'clients']],
                'ao' => ['Appels d’offres', ['Encore accessibles' => 'active', 'Nouvellement détectés' => 'new', 'Dossiers en préparation' => 'preparing', 'À remettre sous 7 jours' => 'due_soon']],
                'documentation' => ['Maîtrise documentaire', ['Documents disponibles' => 'documents', 'Ajoutés récemment' => 'recent', 'Documents partagés' => 'shared', 'Échéances proches' => 'expiring']],
                'logistics' => ['Moyens logistiques', ['Engins indisponibles' => 'unavailable_assets', 'Documents à renouveler' => 'expiring_documents', 'Demandes non terminées' => 'open_requests']],
            ] as $key => $config): if ($dashboard[$key] !== null): ?>
                <section class="finea-section-card dg-span-4">
                    <h2 class="finea-section-title"><?= htmlspecialchars($config[0]) ?></h2>
                    <?php foreach ($config[1] as $label => $field): ?><div class="dg-metric-row"><span><?= htmlspecialchars($label) ?></span><strong><?= number_format((float) ($dashboard[$key][$field] ?? 0), 0, ',', ' ') ?></strong></div><?php endforeach; ?>
                </section>
            <?php endif; endforeach; ?>
        </div>
        <?php elseif ($dashboardMode === 'statistique'): ?>
            <?php $statistics = $dashboard['statistics'] ?? []; ?>
            <div class="dg-context">
                <i class="fas fa-circle-info"></i>
                Chaque indicateur précise désormais son calcul, son périmètre et son utilité. Les données financières couvrent exclusivement la période du <?= htmlspecialchars(date('d/m/Y', strtotime($dashboard['start']))) ?> au <?= htmlspecialchars(date('d/m/Y', strtotime($dashboard['end']))) ?>.
            </div>

            <section class="dg-stat-grid">
                <?php
                $paid = (float) ($dashboard['finance']['month_paid'] ?? 0);
                $pending = (float) ($dashboard['finance']['pending_amount'] ?? 0);
                $statCards = [
                    ['Chantiers actifs en retard', $statistics['chantier_late_rate'] ?? 0, ($dashboard['chantiers']['late'] ?? 0) . ' chantier(s) en retard / ' . ($dashboard['chantiers']['active'] ?? 0) . ' chantier(s) actif(s)', 'Permet de savoir combien de chantiers actifs ont dépassé leur date prévue et doivent être remis à jour.', 'Examiner les dates dépassées et désigner un responsable pour chaque retard.', 'fa-helmet-safety'],
                    ['Demandes ouvertes hors délai', $statistics['ticket_overdue_rate'] ?? 0, ($dashboard['tickets']['overdue'] ?? 0) . ' demande(s) hors délai / ' . ($dashboard['tickets']['open'] ?? 0) . ' demande(s) ouverte(s)', 'Permet de voir combien de demandes internes attendent une réponse depuis trop longtemps.', 'Traiter d’abord les demandes qui bloquent un chantier.', 'fa-headset'],
                    ['Effectif disponible aujourd’hui', $statistics['attendance_rate'] ?? 0, ($statistics['available_today'] ?? 0) . ' personne(s) présente(s) ou en mission / ' . ($dashboard['rh']['attendance_headcount'] ?? 0) . ' attendue(s)', 'Permet de savoir combien de personnes attendues sont réellement disponibles pour travailler aujourd’hui.', 'Réaffecter les ressources si une équipe importante manque de personnel.', 'fa-users'],
                    ['Décaissements programmés encore bloqués', $statistics['pending_cash_rate'] ?? 0, dgMoney($pending) . ' en attente / ' . dgMoney($pending + $paid) . ' programmés ou réalisés', 'Permet de voir quelle part des paiements prévus sur la période attend encore une validation.', 'Prioriser les ' . (int) ($dashboard['finance']['pending_count'] ?? 0) . ' fiche(s) en attente.', 'fa-money-bill-transfer'],
                    ['Appels d’offres pris en charge', $statistics['ao_preparation_rate'] ?? 0, ($dashboard['ao']['preparing'] ?? 0) . ' dossier(s) en préparation / ' . ($dashboard['ao']['active'] ?? 0) . ' appel(s) actif(s)', 'Permet de savoir combien d’appels d’offres actifs sont déjà en cours de préparation.', 'Affecter les appels actifs encore sans préparation.', 'fa-bullhorn'],
                ];
                foreach ($statCards as $stat): ?>
                    <article class="dg-stat-card">
                        <span class="dg-stat-label"><i class="fas <?= htmlspecialchars($stat[5]) ?>"></i> <?= htmlspecialchars($stat[0]) ?></span>
                        <strong><?= htmlspecialchars(dgPercent($stat[1])) ?></strong>
                        <div class="dg-progress" aria-label="<?= htmlspecialchars($stat[0]) ?>"><i style="width:<?= max(0, min(100, (float) $stat[1])) ?>%"></i></div>
                        <p class="dg-stat-formula"><b>Calcul :</b> <?= htmlspecialchars($stat[2]) ?></p>
                        <p><b>Pourquoi :</b> <?= htmlspecialchars($stat[3]) ?></p>
                        <p class="dg-stat-action"><i class="fas fa-arrow-right"></i> <?= htmlspecialchars($stat[4]) ?></p>
                    </article>
                <?php endforeach; ?>
            </section>

            <div class="dg-layout dg-stat-section">
                <?php if ($dashboard['finance'] !== null): ?>
                    <section class="finea-section-card dg-span-8">
                        <h2 class="finea-section-title dg-natural-title">Décaissements réalisés au cours des derniers mois</h2>
                        <p class="dg-natural-copy">Cette courbe permet d’identifier les mois de forte mobilisation de trésorerie.</p>
                        <div class="dg-bars">
                            <?php
                            $maxTrend = max(array_map(static fn(array $r): float => (float) ($r['total'] ?? 0), $dashboard['finance']['trend']) ?: [1]);
                            foreach ($dashboard['finance']['trend'] as $trend):
                                $height = max(4, (int) (((float) $trend['total'] / max(1, $maxTrend)) * 135));
                            ?>
                                <div class="dg-bar-wrap" title="<?= htmlspecialchars(dgMoney($trend['total'])) ?>">
                                    <div class="dg-bar" style="height:<?= $height ?>px"></div>
                                    <span class="dg-bar-label"><?= htmlspecialchars(date('m/Y', strtotime($trend['period'] . '-01'))) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endif; ?>

                <section class="finea-section-card dg-span-4">
                    <h2 class="finea-section-title">Volumes à suivre</h2>
                    <div class="dg-metric-row"><span>Décaissements du mois</span><strong><?= dgMoney($dashboard['finance']['month_paid'] ?? 0) ?></strong></div>
                    <div class="dg-metric-row"><span>Pipeline commercial</span><strong><?= dgMoney($dashboard['crm']['pipeline'] ?? 0) ?></strong></div>
                    <div class="dg-metric-row"><span>Chantiers actifs</span><strong><?= (int) ($dashboard['chantiers']['active'] ?? 0) ?></strong></div>
                    <div class="dg-metric-row"><span>Engins indisponibles</span><strong><?= (int) ($dashboard['logistics']['unavailable_assets'] ?? 0) ?></strong></div>
                    <div class="dg-metric-row"><span>Demandes RH en attente</span><strong><?= (int) ($dashboard['rh']['pending_requests'] ?? 0) ?></strong></div>
                </section>

                <section class="finea-section-card dg-span-6">
                    <h2 class="finea-section-title">Situation des équipes aujourd’hui</h2>
                    <div class="dg-metric-row"><span>Présents</span><strong><?= (int) ($dashboard['rh']['present'] ?? 0) ?></strong></div>
                    <div class="dg-metric-row"><span>En mission</span><strong><?= (int) ($dashboard['rh']['mission'] ?? 0) ?></strong></div>
                    <div class="dg-metric-row"><span>En demi-journée</span><strong><?= (int) ($dashboard['rh']['half_day'] ?? 0) ?></strong></div>
                    <div class="dg-metric-row"><span>Absents</span><strong><?= (int) ($dashboard['rh']['absent'] ?? 0) ?></strong></div>
                    <div class="dg-metric-row"><span>Retards détectés</span><strong><?= (int) ($dashboard['rh']['late'] ?? 0) ?></strong></div>
                </section>

                <section class="finea-section-card dg-span-6">
                    <h2 class="finea-section-title">Portefeuille commercial et appels d’offres</h2>
                    <div class="dg-metric-row"><span>Opportunités commerciales</span><strong><?= (int) ($dashboard['crm']['opportunities'] ?? 0) ?></strong></div>
                    <div class="dg-metric-row"><span>Relances commerciales à faire</span><strong><?= (int) ($dashboard['crm']['followups'] ?? 0) ?></strong></div>
                    <div class="dg-metric-row"><span>Appels d’offres actifs</span><strong><?= (int) ($dashboard['ao']['active'] ?? 0) ?></strong></div>
                    <div class="dg-metric-row"><span>Dossiers en préparation</span><strong><?= (int) ($dashboard['ao']['preparing'] ?? 0) ?></strong></div>
                    <div class="dg-metric-row"><span>Échéances sous 7 jours</span><strong><?= (int) ($dashboard['ao']['due_soon'] ?? 0) ?></strong></div>
                </section>
            </div>
        <?php else: ?>
            <?php $analysis = $dashboard['analysis'] ?? []; ?>
            <section class="dg-analysis-head">
                <h2><i class="fas fa-compass"></i> Lecture générale de la situation</h2>
                <p><?= htmlspecialchars((string) ($analysis['headline'] ?? 'Les données disponibles ne font apparaître aucune conclusion particulière.')) ?></p>
            </section>

            <div class="dg-insights">
                <?php foreach (($analysis['insights'] ?? []) as $insight): ?>
                    <article class="dg-insight dg-insight--<?= htmlspecialchars((string) ($insight['severity'] ?? 'info')) ?>">
                        <div class="dg-insight-top">
                            <span class="dg-insight-icon"><i class="fas <?= htmlspecialchars((string) ($insight['icon'] ?? 'fa-chart-line')) ?>"></i></span>
                            <h3><?= htmlspecialchars((string) ($insight['title'] ?? 'Point de situation')) ?></h3>
                        </div>
                        <p><?= htmlspecialchars((string) ($insight['statement'] ?? '')) ?></p>
                        <p class="dg-recommendation"><strong>Décision ou action conseillée :</strong> <?= htmlspecialchars((string) ($insight['recommendation'] ?? '')) ?></p>
                    </article>
                <?php endforeach; ?>
            </div>

            <section class="finea-section-card dg-stat-section">
                <h2 class="finea-section-title">Ordre de lecture conseillé pour le comité de direction</h2>
                <div class="dg-metric-row"><span>1. Continuité des chantiers</span><strong>Délais, moyens humains, engins et décaissements bloquants</strong></div>
                <div class="dg-metric-row"><span>2. Engagements à court terme</span><strong>Tickets hors délai et appels d’offres proches de l’échéance</strong></div>
                <div class="dg-metric-row"><span>3. Développement du carnet de commandes</span><strong>Pipeline CRM et dossiers commerciaux prioritaires</strong></div>
                <div class="dg-metric-row"><span>4. Sécurisation administrative</span><strong>Documents, demandes RH et traçabilité des validations</strong></div>
            </section>
        <?php endif; ?>
    </div>
</main>
<?php if ($dashboardMode === 'synthese' && $dashboard['treasury_decision'] !== null): ?>
<script>
(()=>{if(typeof Chart==='undefined')return;const decision=<?= json_encode($dashboard['treasury_decision'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;const money=value=>new Intl.NumberFormat('fr-FR',{maximumFractionDigits:0}).format(Number(value||0))+' FCFA';const periodLabel=value=>{const raw=String(value||'');if(/^\d{4}-\d{2}$/.test(raw)){const [y,m]=raw.split('-');return new Intl.DateTimeFormat('fr-FR',{month:'long',year:'numeric',timeZone:'UTC'}).format(new Date(Date.UTC(+y,+m-1,1)))}return raw};const baseOptions={responsive:true,maintainAspectRatio:false,interaction:{mode:'index',intersect:false},plugins:{legend:{position:'bottom'},tooltip:{callbacks:{label:context=>`${context.dataset.label||''} : ${money(context.raw)}`}}},scales:{x:{grid:{display:false}},y:{beginAtZero:true,grid:{color:'#e7ecf3'},ticks:{callback:value=>new Intl.NumberFormat('fr-FR',{notation:'compact',maximumFractionDigits:1}).format(value)}}}};
const cash=document.getElementById('dgCashflowChart');if(cash)new Chart(cash,{type:'bar',data:{labels:(decision.monthly_cashflow||[]).map(row=>row.period),datasets:[{label:'Factures encaissées',data:(decision.monthly_cashflow||[]).map(row=>row.real_inflows||row.inflows),backgroundColor:'#16a34a'},{label:'Factures prévues',data:(decision.monthly_cashflow||[]).map(row=>row.forecast_inflows||0),backgroundColor:'#38bdf8'},{label:'BC décaissés',data:(decision.monthly_cashflow||[]).map(row=>row.real_outflows||row.outflows),backgroundColor:'#dc2626'},{label:'FEB prévues',data:(decision.monthly_cashflow||[]).map(row=>row.forecast_outflows||0),backgroundColor:'#f59e0b'}]},options:baseOptions});
const coverage=document.getElementById('dgCoverageChart');if(coverage){const cv=decision.coverage||{},without=Math.max(0,(cv.chantiers||0)-(cv.bc_complete||0)-(cv.bc_legacy||0));new Chart(coverage,{type:'bar',data:{labels:['BC client exploitable','Dossier historique à régulariser','Sans BC client identifié','Déjà facturé'],datasets:[{label:'Chantiers concernés',data:[cv.bc_complete||0,cv.bc_legacy||0,without,cv.invoiced||0],backgroundColor:['#16a34a','#d97706','#dc2626','#2563eb']}]},options:{...baseOptions,indexAxis:'y',onClick:()=>location.href='offres/dashboard.php',plugins:{...baseOptions.plugins,legend:{display:false}}}})}
const forecast=document.getElementById('dgForecastChart');if(forecast){const rows=decision.forecast||[],actualByPeriod=Object.fromEntries((decision.monthly_cashflow||[]).map(row=>[row.period,Number(row.real_inflows||row.inflows||0)]));new Chart(forecast,{type:'line',data:{labels:rows.map(row=>periodLabel(row.period)),datasets:[{label:'Encaissements attendus',data:rows.map(row=>row.total),borderColor:'#2563eb',backgroundColor:'rgba(37,99,235,.12)',fill:true,tension:.3,pointRadius:5},{label:'Réellement encaissé',data:rows.map(row=>actualByPeriod[row.period]||0),borderColor:'#16a34a',backgroundColor:'transparent',borderDash:[6,4],tension:.25,pointRadius:4}]},options:baseOptions})};
const sites=document.getElementById('dgChantierFinanceChart');if(sites){const rows=decision.top_chantiers||[],box=document.getElementById('dgChantierFinanceChartBox');if(box)box.style.width=Math.max(820,rows.length*135)+'px';new Chart(sites,{type:'bar',data:{labels:rows.map(row=>row.code||`CH-${row.id}`),datasets:[{label:'Contrat',data:rows.map(row=>row.contract_amount),backgroundColor:'#1d4ed8',borderRadius:4},{label:'Total à décaisser',data:rows.map(row=>row.budgeted_outflows),backgroundColor:'#7c3aed',borderRadius:4},{label:'Déjà décaissé',data:rows.map(row=>row.outflows),backgroundColor:'#dc2626',borderRadius:4},{label:'Reste à décaisser',data:rows.map(row=>row.remaining_outflows),backgroundColor:'#0ea5e9',borderRadius:4},{label:'Facturé',data:rows.map(row=>row.invoiced),backgroundColor:'#f59e0b',borderRadius:4},{label:'Encaissé',data:rows.map(row=>row.collected),backgroundColor:'#16a34a',borderRadius:4},{label:'Marge prévisionnelle',data:rows.map(row=>row.forecast_margin),backgroundColor:'#0f766e',borderRadius:4}]},options:{...baseOptions,interaction:{mode:'index',intersect:false},datasets:{bar:{categoryPercentage:.88,barPercentage:.88}},scales:{x:{grid:{display:false},ticks:{autoSkip:false,maxRotation:45,minRotation:0,font:{size:10,weight:'bold'}}},y:{beginAtZero:true,grid:{color:'#e2e8f0'},ticks:{callback:value=>new Intl.NumberFormat('fr-FR',{notation:'compact',maximumFractionDigits:1}).format(value)}}},plugins:{...baseOptions.plugins,legend:{position:'top',labels:{usePointStyle:true,boxWidth:9}},tooltip:{callbacks:{title:items=>{const row=rows[items[0]?.dataIndex]||{};return `${row.code||'Sans code'} — ${row.titre||'Sans intitulé'}`},label:context=>`${context.dataset.label} : ${money(context.raw)}`,afterBody:items=>{const row=rows[items[0]?.dataIndex]||{},contract=Number(row.contract_amount||0),budget=Number(row.budgeted_outflows||0),spent=Number(row.outflows||0),billed=Number(row.invoiced||0),collected=Number(row.collected||0);return [`Exécution du budget dépenses : ${budget?Math.round(spent/budget*100):0} %`,`Marge prévisionnelle : ${money(contract-budget)}`,`Reste à facturer : ${money(Math.max(0,contract-billed))}`,`Reste à encaisser : ${money(Math.max(0,billed-collected))}`]}}}},onHover:(event,elements)=>event.native.target.style.cursor=elements.length?'pointer':'default',onClick:(_,elements)=>{if(elements.length){const row=rows[elements[0].index];if(row?.id)location.href='chantiers/details.php?id='+encodeURIComponent(row.id)}}}})}document.querySelectorAll('[data-export-card]').forEach(button=>button.addEventListener('click',()=>{const card=document.getElementById(button.dataset.exportCard),canvas=card?.querySelector('canvas');if(!canvas){window.print();return}const link=document.createElement('a');link.download=button.dataset.exportCard+'.png';link.href=canvas.toDataURL('image/png',1);link.click()}));})();
</script>
<?php endif; ?>
<script>(()=>{const label=raw=>{if(!/^\d{4}-\d{2}$/.test(String(raw)))return raw;const [y,m]=raw.split('-');return new Intl.DateTimeFormat('fr-FR',{month:'long',year:'numeric',timeZone:'UTC'}).format(new Date(Date.UTC(+y,+m-1,1)))};const chart=window.Chart?.getChart(document.getElementById('dgCashflowChart'));if(chart){chart.data.labels=chart.data.labels.map(label);chart.update()}})();</script>
<?php require_once __DIR__ . '/decaissement/includes/footer.php'; ?>
