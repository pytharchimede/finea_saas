<?php

require_once __DIR__ . '/module_bootstrap.php';

function devis_module_render_feature_page(string $moduleKey, string $mode): void
{
    global $devisModuleController;

    $modules = $devisModuleController->modules();
    $devisModuleNav = $modules;
    $module = $modules[$moduleKey] ?? null;
    if (!$module) {
        http_response_code(404);
        echo 'Module introuvable.';
        return;
    }

    if (!devis_module_has_access($module['permission'] ?? null)) {
        \AccessManager::denyAccess((string) ($module['permission'] ?? 'access_general'), $moduleKey . '/' . $mode . '.php');
    }

    $activeDevisModule = $moduleKey;
    $moduleTitle = ($mode === 'parametrage' ? 'Parametrage ' : 'Pilotage ') . $module['label'];
    $moduleSubtitle = (string) ($module['description'] ?? '');

    $actions = [
        ['label' => 'Ouvrir le listing', 'href' => (string) $module['href'], 'icon' => 'fa-list', 'primary' => true],
        ['label' => 'Tableau de bord', 'href' => (string) ($module['dashboard_href'] ?? ''), 'icon' => 'fa-gauge-high'],
        ['label' => 'Parametrage', 'href' => (string) ($module['settings_href'] ?? ''), 'icon' => 'fa-sliders'],
    ];
    if (!empty($module['create_href'])) {
        $actions[] = ['label' => 'Nouvelle saisie', 'href' => (string) $module['create_href'], 'icon' => 'fa-plus'];
    }

    ob_start();
    ?>
    <style>
        .devis-feature-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.15fr) minmax(280px, .85fr);
            gap: 16px;
        }

        .devis-feature-panel {
            padding: 25px;
            border: 1px solid var(--devis-border);
            border-radius: 14px;
            background: #fff;
            box-shadow: 0 12px 30px rgba(23, 35, 72, .07);
        }

        .devis-feature-intro {
            display: grid;
            grid-template-columns: 58px minmax(0, 1fr);
            gap: 16px;
            align-items: start;
        }

        .devis-feature-icon {
            display: inline-flex;
            width: 58px;
            height: 58px;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            background: color-mix(in srgb, var(--feature-tone) 13%, white);
            color: var(--feature-tone);
            font-size: 21px;
        }

        .devis-feature-panel h2 {
            margin: 3px 0 0;
            color: #172348;
            font-size: 20px;
            font-weight: 850;
        }

        .devis-feature-panel p {
            margin: 8px 0 0;
            color: #68758b;
            font-size: 13px;
            line-height: 1.65;
        }

        .devis-feature-actions {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 9px;
            margin-top: 24px;
        }

        .devis-feature-actions a {
            display: inline-flex;
            min-height: 43px;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 9px 12px;
            border: 1px solid #d8e0eb;
            border-radius: 8px;
            background: #fff;
            color: #34425a;
            font-size: 12px;
            font-weight: 750;
            text-decoration: none;
        }

        .devis-feature-actions a.is-primary {
            border-color: #172348;
            background: #172348;
            color: #fff;
        }

        .devis-feature-meta {
            display: grid;
            gap: 12px;
            margin-top: 19px;
        }

        .devis-feature-meta div {
            padding: 14px;
            border: 1px solid #e4e9f1;
            border-radius: 8px;
            background: #f8fafc;
        }

        .devis-feature-meta span,
        .devis-feature-meta strong {
            display: block;
        }

        .devis-feature-meta span {
            color: #77839a;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .devis-feature-meta strong {
            margin-top: 5px;
            color: #172348;
            font-size: 12px;
            overflow-wrap: anywhere;
        }

        body.finea-dark-mode .devis-feature-panel {
            border-color: #2a3550;
            background: #172036;
        }

        body.finea-dark-mode .devis-feature-panel h2,
        body.finea-dark-mode .devis-feature-meta strong {
            color: #fff;
        }

        body.finea-dark-mode .devis-feature-panel p {
            color: #aeb9ca;
        }

        body.finea-dark-mode .devis-feature-meta div {
            border-color: #303b56;
            background: #111a2e;
        }

        body.finea-dark-mode .devis-feature-actions a {
            border-color: #3a4763;
            background: #111a2e;
            color: #e4eaf3;
        }

        @media (max-width: 760px) {
            .devis-feature-grid {
                grid-template-columns: 1fr;
            }

            .devis-feature-actions {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <section class="devis-feature-grid">
        <article class="devis-feature-panel" style="--feature-tone: <?= htmlspecialchars((string) $module['accent'], ENT_QUOTES, 'UTF-8') ?>">
            <div class="devis-feature-intro">
                <span class="devis-feature-icon"><i class="fa-solid <?= htmlspecialchars((string) $module['icon'], ENT_QUOTES, 'UTF-8') ?>"></i></span>
                <div>
                    <h2><?= htmlspecialchars((string) $module['label'], ENT_QUOTES, 'UTF-8') ?></h2>
                    <p><?= htmlspecialchars((string) $module['description'], ENT_QUOTES, 'UTF-8') ?></p>
                </div>
            </div>

            <div class="devis-feature-actions">
                <?php foreach ($actions as $action): ?>
                    <?php if ($action['href'] === '') continue; ?>
                    <a class="<?= !empty($action['primary']) ? 'is-primary' : '' ?>" href="<?= htmlspecialchars($action['href'], ENT_QUOTES, 'UTF-8') ?>">
                        <i class="fa-solid <?= htmlspecialchars($action['icon'], ENT_QUOTES, 'UTF-8') ?>"></i>
                        <?= htmlspecialchars($action['label'], ENT_QUOTES, 'UTF-8') ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </article>

        <article class="devis-feature-panel">
            <h2><?= $mode === 'parametrage' ? 'Configuration et droits' : 'Acces operationnels' ?></h2>
            <p>Les formulaires, listings et actions restent separes pour garder une navigation claire et rapide.</p>
            <div class="devis-feature-meta">
                <div>
                    <span>Permission principale</span>
                    <strong><?= htmlspecialchars((string) ($module['permission'] ?? 'Acces libre'), ENT_QUOTES, 'UTF-8') ?></strong>
                </div>
                <div>
                    <span>Organisation</span>
                    <strong>Listing, saisie et parametrage distincts</strong>
                </div>
            </div>
            <div class="devis-feature-actions">
                <a href="<?= htmlspecialchars(devis_module_root_url('admin/edit_user.php'), ENT_QUOTES, 'UTF-8') ?>">
                    <i class="fa-solid fa-user-shield"></i> Gerer les acces
                </a>
            </div>
        </article>
    </section>
    <?php
    $content = ob_get_clean();
    require __DIR__ . '/module_layout.php';
}
