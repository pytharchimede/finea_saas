<?php

require_once __DIR__ . '/includes/module_bootstrap.php';
if (!\AccessManager::hasPermission('acces_veille_ao')) {
    \AccessManager::requirePageAccess('dashboard.php');
}

$view = $devisModuleController->dashboard();
$stats = $view['stats'];
$activeDevisModule = 'dashboard';
$moduleTitle = 'Centre devis et chantiers';
$moduleSubtitle = 'Pilotez le cycle commercial complet, de la detection d opportunites a la transformation des devis en chantiers.';

$kpis = [
    ['label' => 'Devis en base', 'value' => number_format((int) $stats['devis'], 0, ',', ' '), 'icon' => 'fa-file-contract', 'tone' => '#2456a6'],
    ['label' => 'Montant TTC cumule', 'value' => number_format((float) $stats['montant_devis'], 0, ',', ' ') . ' FCFA', 'icon' => 'fa-chart-line', 'tone' => '#0f766e'],
    ['label' => 'Debourses', 'value' => number_format((int) $stats['debourses'], 0, ',', ' '), 'icon' => 'fa-wallet', 'tone' => '#b45309'],
    ['label' => 'Chantiers', 'value' => number_format((int) $stats['chantiers'], 0, ',', ' '), 'icon' => 'fa-helmet-safety', 'tone' => '#7c3aed'],
];

ob_start();
?>
<style>
    .devis-dashboard-kpis {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 14px;
    }

    .devis-kpi {
        position: relative;
        overflow: hidden;
        min-height: 142px;
        padding: 20px;
        border: 1px solid var(--devis-border);
        border-radius: 14px;
        background: #fff;
        box-shadow: 0 12px 30px rgba(23, 35, 72, .07);
    }

    .devis-kpi-top {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
    }

    .devis-kpi-label {
        margin: 0;
        color: #667085;
        font-size: 12px;
        font-weight: 750;
        text-transform: uppercase;
    }

    .devis-kpi-icon {
        display: inline-flex;
        width: 39px;
        height: 39px;
        align-items: center;
        justify-content: center;
        border-radius: 8px;
        background: color-mix(in srgb, var(--kpi-tone) 12%, white);
        color: var(--kpi-tone);
    }

    .devis-kpi-value {
        margin: 20px 0 0;
        color: #172348;
        font-size: clamp(23px, 2vw, 31px);
        font-weight: 850;
        line-height: 1;
    }

    .devis-section-head {
        display: flex;
        align-items: end;
        justify-content: space-between;
        gap: 18px;
        margin: 30px 0 14px;
    }

    .devis-section-head h2 {
        margin: 0;
        color: #172348;
        font-size: 20px;
        font-weight: 850;
    }

    .devis-section-head p {
        margin: 5px 0 0;
        color: #68758b;
        font-size: 13px;
    }

    .devis-module-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 14px;
    }

    .devis-module-card {
        display: grid;
        grid-template-columns: 54px minmax(0, 1fr);
        gap: 16px;
        padding: 20px;
        border: 1px solid var(--devis-border);
        border-radius: 14px;
        background: #fff;
        box-shadow: 0 12px 30px rgba(23, 35, 72, .06);
        transition: border-color .18s ease, transform .18s ease, box-shadow .18s ease;
    }

    .devis-module-card:hover {
        transform: translateY(-2px);
        border-color: #bbc8db;
        box-shadow: 0 18px 34px rgba(23, 35, 72, .1);
    }

    .devis-module-icon {
        display: inline-flex;
        width: 54px;
        height: 54px;
        align-items: center;
        justify-content: center;
        border-radius: 10px;
        background: color-mix(in srgb, var(--module-tone) 12%, white);
        color: var(--module-tone);
        font-size: 20px;
    }

    .devis-module-card h3 {
        margin: 2px 0 0;
        color: #172348;
        font-size: 16px;
        font-weight: 850;
    }

    .devis-module-card p {
        min-height: 40px;
        margin: 7px 0 0;
        color: #667085;
        font-size: 12px;
        line-height: 1.55;
    }

    .devis-module-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 7px;
        margin-top: 15px;
    }

    .devis-module-actions a {
        display: inline-flex;
        min-height: 34px;
        align-items: center;
        gap: 7px;
        padding: 7px 10px;
        border: 1px solid #dbe3ed;
        border-radius: 7px;
        background: #fff;
        color: #34425a;
        font-size: 11px;
        font-weight: 750;
        text-decoration: none;
    }

    .devis-module-actions a:first-child {
        border-color: #172348;
        background: #172348;
        color: #fff;
    }

    .devis-process {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        align-items: center;
        gap: 24px;
        margin-top: 24px;
        padding: 24px;
        border: 1px solid #e5d692;
        border-radius: 14px;
        background: #fffdf5;
    }

    .devis-process-steps {
        display: flex;
        align-items: center;
        gap: 9px;
        margin-top: 15px;
        overflow-x: auto;
    }

    .devis-process-step {
        display: inline-flex;
        flex: 0 0 auto;
        align-items: center;
        gap: 7px;
        color: #536078;
        font-size: 11px;
        font-weight: 750;
        white-space: nowrap;
    }

    .devis-process-step span {
        display: inline-flex;
        width: 25px;
        height: 25px;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        background: #172348;
        color: #fff;
        font-size: 10px;
    }

    .devis-process-step:not(:last-child)::after {
        content: "";
        width: 24px;
        height: 1px;
        margin-left: 2px;
        background: #d6c67e;
    }

    .devis-process-create {
        display: inline-flex;
        min-height: 44px;
        align-items: center;
        justify-content: center;
        gap: 9px;
        padding: 10px 16px;
        border-radius: 8px;
        background: #fabd02;
        color: #172348 !important;
        font-size: 13px;
        font-weight: 800;
        text-decoration: none !important;
    }

    body.finea-dark-mode .devis-kpi,
    body.finea-dark-mode .devis-module-card {
        border-color: #2a3550;
        background: #172036;
    }

    body.finea-dark-mode .devis-kpi-value,
    body.finea-dark-mode .devis-section-head h2,
    body.finea-dark-mode .devis-module-card h3 {
        color: #fff;
    }

    body.finea-dark-mode .devis-kpi-label,
    body.finea-dark-mode .devis-section-head p,
    body.finea-dark-mode .devis-module-card p {
        color: #aeb9ca;
    }

    body.finea-dark-mode .devis-module-actions a {
        border-color: #3a4763;
        background: #111a2e;
        color: #e4eaf3;
    }

    body.finea-dark-mode .devis-process {
        border-color: #5c5127;
        background: #201f1a;
    }

    @media (max-width: 1180px) {
        .devis-dashboard-kpis {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 680px) {
        .devis-dashboard-kpis,
        .devis-module-grid {
            grid-template-columns: 1fr;
        }

        .devis-process {
            grid-template-columns: 1fr;
        }

        .devis-process-create {
            width: 100%;
        }
    }
</style>

<section class="devis-dashboard-kpis" aria-label="Indicateurs du centre">
    <?php foreach ($kpis as $kpi): ?>
        <article class="devis-kpi" style="--kpi-tone: <?= htmlspecialchars($kpi['tone'], ENT_QUOTES, 'UTF-8') ?>">
            <div class="devis-kpi-top">
                <p class="devis-kpi-label"><?= htmlspecialchars($kpi['label'], ENT_QUOTES, 'UTF-8') ?></p>
                <span class="devis-kpi-icon"><i class="fa-solid <?= htmlspecialchars($kpi['icon'], ENT_QUOTES, 'UTF-8') ?>"></i></span>
            </div>
            <p class="devis-kpi-value"><?= htmlspecialchars($kpi['value'], ENT_QUOTES, 'UTF-8') ?></p>
        </article>
    <?php endforeach; ?>
</section>

<div class="devis-section-head">
    <div>
        <h2>Espaces de travail</h2>
        <p>Acces direct aux listings, formulaires et reglages de chaque activite.</p>
    </div>
</div>

<section class="devis-module-grid">
    <?php foreach ($view['modules'] as $key => $item): ?>
        <?php if (!devis_module_has_access($item['permission'] ?? null)) continue; ?>
        <article class="devis-module-card" style="--module-tone: <?= htmlspecialchars($item['accent'], ENT_QUOTES, 'UTF-8') ?>">
            <span class="devis-module-icon"><i class="fa-solid <?= htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8') ?>"></i></span>
            <div>
                <h3><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></h3>
                <p><?= htmlspecialchars($item['description'], ENT_QUOTES, 'UTF-8') ?></p>
                <div class="devis-module-actions">
                    <a href="<?= htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8') ?>"><i class="fa-solid fa-list"></i> Listing</a>
                    <?php if (!empty($item['create_href'])): ?>
                        <a href="<?= htmlspecialchars($item['create_href'], ENT_QUOTES, 'UTF-8') ?>"><i class="fa-solid fa-plus"></i> Saisie</a>
                    <?php endif; ?>
                    <?php if (!empty($item['settings_href'])): ?>
                        <a href="<?= htmlspecialchars($item['settings_href'], ENT_QUOTES, 'UTF-8') ?>"><i class="fa-solid fa-sliders"></i> Reglages</a>
                    <?php endif; ?>
                    <?php if ($key === 'ao'): ?>
                        <a href="export_veille_ao.php?format=excel"><i class="fa-solid fa-file-excel"></i> Export</a>
                    <?php endif; ?>
                </div>
            </div>
        </article>
    <?php endforeach; ?>
</section>

<section class="devis-process">
    <div>
        <div class="devis-section-head" style="margin:0">
            <div>
                <h2>Processus commercial</h2>
                <p>Une lecture simple du parcours, de l opportunite jusqu au suivi du chantier.</p>
            </div>
        </div>
        <div class="devis-process-steps" aria-label="Etapes du processus">
            <span class="devis-process-step"><span>1</span> Opportunite</span>
            <span class="devis-process-step"><span>2</span> Devis</span>
            <span class="devis-process-step"><span>3</span> Validation</span>
            <span class="devis-process-step"><span>4</span> Debourse</span>
            <span class="devis-process-step"><span>5</span> Chantier</span>
        </div>
    </div>
    <?php if (devis_module_has_access('acces_generer_devis')): ?>
        <a href="generer.php" class="devis-process-create"><i class="fa-solid fa-plus"></i> Creer un devis</a>
    <?php endif; ?>
</section>
<?php
$content = ob_get_clean();
require __DIR__ . '/includes/module_layout.php';
