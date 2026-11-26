<?php
require_once __DIR__ . '/includes/module_bootstrap.php';
\AccessManager::requirePageAccess('veille_ao_detail.php');
require_once dirname(__DIR__) . '/controllers/AoController.php';

$controller = new AoController(\Database::getConnection());
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$canDgDecision = \AccessManager::hasPermission('ao_dg_decision') || \AccessManager::hasPermission('ao_admin');
$canCreateCrm = \AccessManager::hasPermission('ao_create_crm') || \AccessManager::hasPermission('ao_admin');
$canManage = \AccessManager::hasPermission('ao_manage') || \AccessManager::hasPermission('ao_admin');
$canExport = \AccessManager::hasPermission('ao_export') || \AccessManager::hasPermission('ao_admin');
if (empty($_SESSION['ao_csrf'])) {
    $_SESSION['ao_csrf'] = bin2hex(random_bytes(24));
}
$flash = '';
$flashType = 'success';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals((string) $_SESSION['ao_csrf'], (string) ($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Session expiree.');
        }
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'dg_decision' && $canDgDecision) {
            $controller->recordDgDecision($id, (string) ($_POST['decision'] ?? ''), (int) ($_SESSION['user_id'] ?? 0), (string) ($_POST['note'] ?? ''));
            $flash = 'Decision DG enregistree.';
        } elseif ($action === 'crm' && $canCreateCrm) {
            $crmId = $controller->createCrmOpportunity($id, (int) ($_SESSION['user_id'] ?? 0));
            $flash = 'Opportunite CRM disponible sous le numero #' . $crmId . '.';
        } elseif ($action === 'submission_folder' && $canManage) {
            $controller->updateStatus($id, 'dossier_en_preparation');
            $flash = 'Dossier de soumission initialise. Le statut est passe a "dossier en preparation".';
        } else {
            throw new RuntimeException('Action non autorisee.');
        }
    } catch (Throwable $exception) {
        $flash = $exception->getMessage();
        $flashType = 'error';
    }
}
$item = $controller->detail($id);
if (!$item) {
    http_response_code(404);
    exit('Appel d offres introuvable.');
}

$activeDevisModule = 'ao';
$moduleTitle = 'Fiche appel d offres';
$moduleSubtitle = (string) ($item['numero_ao'] ?: $item['reference']);
$h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$formatDate = static function ($value): string {
    if (!$value) {
        return '-';
    }
    $timestamp = strtotime((string) $value);
    return $timestamp ? date('d/m/Y', $timestamp) : (string) $value;
};
$detailsSource = json_decode((string) ($item['details_source_json'] ?? '[]'), true);
$detailsSource = is_array($detailsSource) ? $detailsSource : [];
$documentsSource = json_decode((string) ($item['documents_source_json'] ?? '[]'), true);
$documentsSource = is_array($documentsSource) ? $documentsSource : [];
$numeroAo = (string) ($item['numero_ao'] ?: $item['reference']);
$compatibility = $item['banamur_compatibility'] ?? ['score' => 0, 'reasons' => []];

$mainDetails = [
    ['label' => 'Numero AO', 'value' => $numeroAo, 'icon' => 'fa-hashtag'],
    ['label' => 'Type de marche', 'value' => $item['type_marche'], 'icon' => 'fa-layer-group'],
    ['label' => 'Date de publication', 'value' => $formatDate($item['date_publication']), 'icon' => 'fa-calendar-plus'],
    ['label' => 'Date limite', 'value' => $formatDate($item['date_limite']), 'icon' => 'fa-calendar-xmark'],
    ['label' => 'Autorite contractante', 'value' => $item['autorite_contractante'], 'icon' => 'fa-building-columns'],
    ['label' => 'Lieu d execution', 'value' => $item['lieu_execution'], 'icon' => 'fa-location-dot'],
    ['label' => 'Secteur', 'value' => $item['secteur'], 'icon' => 'fa-briefcase'],
    ['label' => 'Statut FINEA', 'value' => $item['statut_interne'], 'icon' => 'fa-circle-check'],
];

$alreadyDisplayed = [];
foreach ($mainDetails as $detail) {
    $alreadyDisplayed[mb_strtolower(trim((string) $detail['label']), 'UTF-8')] = trim((string) $detail['value']);
}

ob_start();
?>
<style>
    .ao-detail-card {
        overflow: hidden;
        border: 1px solid var(--devis-border);
        border-radius: 16px;
        background: #fff;
        box-shadow: 0 14px 36px rgba(23, 35, 72, .08);
    }

    .ao-detail-head {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 25px;
        align-items: start;
        padding: 28px;
        border-bottom: 1px solid var(--devis-border);
    }

    .ao-number {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 7px 10px;
        border-radius: 7px;
        background: #fff6d8;
        color: #6d5100;
        font-size: 11px;
        font-weight: 850;
        text-transform: uppercase;
    }

    .ao-detail-head h2 {
        max-width: 900px;
        margin: 14px 0 0;
        color: #172348;
        font-size: clamp(22px, 2.4vw, 32px);
        font-weight: 850;
        line-height: 1.28;
    }

    .ao-authority {
        margin: 12px 0 0;
        color: #64748b;
        font-size: 14px;
    }

    .ao-score {
        display: grid;
        width: 108px;
        height: 108px;
        place-items: center;
        border: 8px solid #eef2f7;
        border-top-color: #fabd02;
        border-radius: 50%;
        color: #172348;
        text-align: center;
    }

    .ao-score strong,
    .ao-score small {
        display: block;
    }

    .ao-score strong {
        font-size: 24px;
        line-height: 1;
    }

    .ao-score small {
        margin-top: 4px;
        color: #748096;
        font-size: 9px;
        font-weight: 800;
        text-transform: uppercase;
    }

    .ao-detail-body {
        padding: 26px 28px 30px;
    }

    .ao-detail-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 12px;
    }

    .ao-detail-field {
        min-height: 106px;
        padding: 16px;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        background: #f8fafc;
    }

    .ao-detail-field i {
        color: #31589c;
        font-size: 14px;
    }

    .ao-detail-field dt {
        margin-top: 12px;
        color: #77839a;
        font-size: 10px;
        font-weight: 850;
        text-transform: uppercase;
    }

    .ao-detail-field dd {
        margin: 5px 0 0;
        color: #172348;
        font-size: 13px;
        font-weight: 750;
        overflow-wrap: anywhere;
    }

    .ao-detail-section {
        margin-top: 22px;
        padding-top: 22px;
        border-top: 1px solid var(--devis-border);
    }

    .ao-detail-section h3 {
        margin: 0;
        color: #172348;
        font-size: 16px;
        font-weight: 850;
    }

    .ao-detail-section > p {
        margin: 10px 0 0;
        color: #64748b;
        font-size: 13px;
        line-height: 1.75;
    }

    .ao-source-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 9px 20px;
        margin-top: 16px;
    }

    .ao-source-row {
        display: grid;
        grid-template-columns: minmax(130px, .75fr) minmax(0, 1.25fr);
        gap: 12px;
        padding: 11px 0;
        border-bottom: 1px solid #edf1f6;
        font-size: 12px;
    }

    .ao-source-row span {
        color: #77839a;
        font-weight: 750;
    }

    .ao-source-row strong {
        color: #26334a;
        overflow-wrap: anywhere;
    }

    .ao-documents {
        display: grid;
        gap: 8px;
        margin-top: 14px;
    }

    .ao-document {
        display: flex;
        align-items: center;
        gap: 11px;
        padding: 12px 14px;
        border: 1px solid #dce4ef;
        border-radius: 8px;
        color: #26334a !important;
        font-size: 12px;
        font-weight: 750;
        text-decoration: none !important;
    }

    .ao-document i {
        color: #b45309;
    }

    .ao-detail-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 9px;
        margin-top: 22px;
    }

    .ao-detail-actions a {
        display: inline-flex;
        min-height: 42px;
        align-items: center;
        gap: 8px;
        padding: 9px 14px;
        border: 1px solid #d8e0eb;
        border-radius: 8px;
        color: #34425a;
        font-size: 12px;
        font-weight: 800;
        text-decoration: none;
    }
    .ao-decision-panel { margin-top:22px; padding:20px; border:1px solid #dce4ef; border-radius:12px; background:#f8fafc; }
    .ao-decision-panel textarea { width:100%; min-height:92px; margin-top:12px; padding:11px; border:1px solid #cbd5e1; border-radius:8px; resize:vertical; }
    /* Decision DG: follow the shared FINEA theme, including early HTML theme initialization. */
    .devis-page .ao-decision-panel {
        background: var(--dv-card, #fff) !important;
        border-color: var(--devis-border, #dce4ef) !important;
        color: var(--dv-text, #17243d) !important;
    }
    .devis-page .ao-decision-panel textarea {
        background: var(--dv-field, #fff) !important;
        color: var(--dv-text, #17243d) !important;
        border-color: var(--devis-border, #cbd5e1) !important;
    }
    .devis-page .ao-decision-panel textarea::placeholder {
        color: var(--dv-muted, #64748b) !important;
        opacity: 1;
    }
    :is(html.finea-dark-mode, body.finea-dark-mode) .devis-page .ao-decision-panel {
        background: #172337 !important;
        border-color: #52647e !important;
        color: #f5f8fc !important;
    }
    :is(html.finea-dark-mode, body.finea-dark-mode) .devis-page .ao-decision-panel textarea {
        background: #0d1727 !important;
        color: #f5f8fc !important;
        border-color: #52647e !important;
    }
    :is(html.finea-dark-mode, body.finea-dark-mode) .devis-page .ao-decision-panel textarea::placeholder {
        color: #c6d0de !important;
    }
    :is(html.finea-dark-mode, body.finea-dark-mode) .devis-page .ao-decision-buttons button[value="interested"] {
        background: #123c32 !important;
        color: #a7f3d0 !important;
        border-color: #38866d !important;
    }
    :is(html.finea-dark-mode, body.finea-dark-mode) .devis-page .ao-decision-buttons button[value="not_interested"] {
        background: #481f2b !important;
        color: #fecdd3 !important;
        border-color: #a65365 !important;
    }
    .devis-page .ao-decision-panel :is(textarea,button):focus-visible {
        outline: 2px solid var(--dv-focus, #31589c);
        outline-offset: 2px;
    }
    .ao-decision-buttons { display:flex; flex-wrap:wrap; gap:9px; margin-top:11px; }
    .ao-decision-buttons button { min-height:40px; padding:9px 13px; border-radius:8px; font-size:12px; font-weight:800; }
    .ao-compatibility { display:grid; grid-template-columns:130px minmax(0,1fr); gap:18px; align-items:center; }
    .ao-compatibility-score { padding:18px; border-radius:10px; background:#172348; color:#fff; text-align:center; }
    .ao-compatibility-score strong { display:block; font-size:28px; }
    .ao-reasons { display:flex; flex-wrap:wrap; gap:7px; }
    .ao-reasons span { padding:7px 9px; border-radius:7px; background:#edf3fc; color:#244b91; font-size:11px; font-weight:750; }

    .ao-detail-actions a.is-primary {
        border-color: #fabd02;
        background: #fabd02;
        color: #172348;
    }

    body.finea-dark-mode .ao-detail-card {
        border-color: #2a3550;
        background: #172036;
    }

    body.finea-dark-mode .ao-detail-head,
    body.finea-dark-mode .ao-detail-section {
        border-color: #2a3550;
    }

    body.finea-dark-mode .ao-detail-head h2,
    body.finea-dark-mode .ao-detail-section h3,
    body.finea-dark-mode .ao-detail-field dd,
    body.finea-dark-mode .ao-source-row strong {
        color: #fff;
    }

    body.finea-dark-mode .ao-detail-field {
        border-color: #303b56;
        background: #111a2e;
    }

    body.finea-dark-mode .ao-source-row {
        border-color: #2a3550;
    }

    @media (max-width: 900px) {
        .ao-detail-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .ao-source-grid {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 560px) {
        .ao-detail-head {
            grid-template-columns: 1fr;
            padding: 22px;
        }

        .ao-score {
            width: 88px;
            height: 88px;
        }

        .ao-detail-body {
            padding: 22px;
        }

        .ao-detail-grid {
            grid-template-columns: 1fr;
        }

        .ao-source-row {
            grid-template-columns: 1fr;
            gap: 4px;
        }
    }
</style>

<article class="ao-detail-card">
    <?php if ($flash !== ''): ?><div style="padding:13px 20px;background:<?= $flashType === 'error' ? '#fff0f0;color:#9b2828' : '#effcf6;color:#176847' ?>;font-weight:750;"><?= $h($flash) ?></div><?php endif; ?>
    <header class="ao-detail-head">
        <div>
            <span class="ao-number"><i class="fa-solid fa-hashtag"></i> AO <?= $h($numeroAo ?: 'Non communique') ?></span>
            <h2><?= $h($item['titre']) ?></h2>
            <p class="ao-authority"><i class="fa-solid fa-building-columns mr-2"></i><?= $h($item['autorite_contractante'] ?: 'Autorite contractante non communiquee') ?></p>
        </div>
        <div class="ao-score">
            <span><strong><?= (int) $item['score_pertinence'] ?>%</strong><small>Pertinence</small></span>
        </div>
    </header>

    <div class="ao-detail-body">
        <dl class="ao-detail-grid">
            <?php foreach ($mainDetails as $detail): ?>
                <div class="ao-detail-field">
                    <i class="fa-solid <?= $h($detail['icon']) ?>"></i>
                    <dt><?= $h($detail['label']) ?></dt>
                    <dd><?= $h($detail['value'] ?: '-') ?></dd>
                </div>
            <?php endforeach; ?>
        </dl>

        <section class="ao-detail-section">
            <h3>Objet et resume de l avis</h3>
            <p><?= nl2br($h($item['resume'] ?: $item['titre'])) ?></p>
        </section>

        <section class="ao-detail-section">
            <h3>Compatibilite BANAMUR</h3>
            <div class="ao-compatibility">
                <div class="ao-compatibility-score"><strong><?= (int) ($compatibility['score'] ?? 0) ?>%</strong><small>Score BANAMUR</small></div>
                <div class="ao-reasons">
                    <?php foreach (($compatibility['reasons'] ?? []) as $reason): ?><span><i class="fa-solid fa-check mr-1"></i><?= $h($reason) ?></span><?php endforeach; ?>
                    <?php if (empty($compatibility['reasons'])): ?><span>Aucun motif BTP fort detecte</span><?php endif; ?>
                </div>
            </div>
        </section>

        <section class="ao-detail-section">
            <h3>Decision Direction generale</h3>
            <p>
                <?= ($item['dg_decision'] ?? 'pending') === 'pending' ? 'En attente de decision.' : (($item['dg_decision'] === 'interested' ? 'Interesse' : 'Pas interesse') . ' le ' . $h($formatDate($item['dg_decision_at'] ?? null)) . (!empty($item['dg_decision_by_name']) ? ' par ' . $h($item['dg_decision_by_name']) : '')) ?>
                <?php if (!empty($item['dg_decision_note'])): ?><br><strong>Note :</strong> <?= nl2br($h($item['dg_decision_note'])) ?><?php endif; ?>
            </p>
            <?php if ($canDgDecision): ?>
                <form method="post" class="ao-decision-panel">
                    <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= $h($_SESSION['ao_csrf']) ?>">
                    <input type="hidden" name="action" value="dg_decision">
                    <textarea name="note" placeholder="Note facultative de decision..."><?= $h($item['dg_decision_note'] ?? '') ?></textarea>
                    <div class="ao-decision-buttons">
                        <button name="decision" value="interested" style="border:1px solid #9dd9bc;background:#ecfdf5;color:#147a55;"><i class="fa-solid fa-thumbs-up mr-1"></i>Interesse</button>
                        <button name="decision" value="not_interested" style="border:1px solid #efc2c2;background:#fff0f0;color:#9b2828;" onclick="return confirm('Cette decision arretera toutes les alertes et relances automatiques. Continuer ?');"><i class="fa-solid fa-thumbs-down mr-1"></i>Pas interesse</button>
                    </div>
                </form>
            <?php endif; ?>
        </section>

        <?php if ($detailsSource !== []): ?>
            <section class="ao-detail-section">
                <h3>Informations publiees par la source officielle</h3>
                <div class="ao-source-grid">
                    <?php foreach ($detailsSource as $label => $value): ?>
                        <?php
                        $value = is_scalar($value) ? trim((string) $value) : '';
                        if ($value === '') continue;
                        ?>
                        <div class="ao-source-row">
                            <span><?= $h($label) ?></span>
                            <strong><?= $h($value) ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($documentsSource !== []): ?>
            <section class="ao-detail-section">
                <h3>Documents et liens associes</h3>
                <div class="ao-documents">
                    <?php foreach ($documentsSource as $document): ?>
                        <?php if (!is_array($document) || empty($document['url'])) continue; ?>
                        <a class="ao-document" href="<?= $h($document['url']) ?>" target="_blank" rel="noopener">
                            <i class="fa-solid fa-file-arrow-down"></i>
                            <?= $h($document['label'] ?? 'Document officiel') ?>
                            <i class="fa-solid fa-arrow-up-right-from-square ml-auto"></i>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <div class="ao-detail-actions">
            <a class="is-primary" href="<?= $h($item['url_source']) ?>" target="_blank" rel="noopener">
                <i class="fa-solid fa-arrow-up-right-from-square"></i> Consulter la source officielle
            </a>
            <a href="veille_ao.php"><i class="fa-solid fa-arrow-left"></i> Retour a la veille</a>
            <?php if ($canExport): ?>
                <a href="export_veille_ao.php?format=pdf&amp;q=<?= urlencode($numeroAo) ?>" target="_blank"><i class="fa-solid fa-file-pdf"></i> Export PDF</a>
                <a href="export_veille_ao.php?format=excel&amp;q=<?= urlencode($numeroAo) ?>"><i class="fa-solid fa-file-excel"></i> Export Excel</a>
            <?php endif; ?>
        </div>
        <?php if ($canCreateCrm && ($item['dg_decision'] ?? 'pending') === 'interested'): ?>
            <form method="post" style="margin-top:12px;">
                <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                <input type="hidden" name="csrf_token" value="<?= $h($_SESSION['ao_csrf']) ?>">
                <input type="hidden" name="action" value="crm">
                <button style="min-height:43px;padding:9px 15px;border:0;border-radius:8px;background:#fabd02;color:#172348;font-weight:850;"><i class="fa-solid fa-handshake mr-2"></i><?= !empty($item['crm_opportunity_id']) ? 'Ouvrir / verifier l opportunite CRM #' . (int) $item['crm_opportunity_id'] : 'Creer l opportunite CRM' ?></button>
            </form>
        <?php endif; ?>
        <?php if (($item['dg_decision'] ?? 'pending') === 'interested'): ?>
            <div class="ao-detail-actions">
                <?php if (\AccessManager::hasPermission('acces_emploi_du_temps')): ?>
                    <a href="../emploi_du_temps/index.php?quick_task_title=<?= urlencode('AO ' . $numeroAo . ' - ' . $item['titre']) ?>"><i class="fa-solid fa-list-check"></i> Creer une tache</a>
                <?php endif; ?>
                <?php if ($canManage): ?>
                    <form method="post">
                        <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                        <input type="hidden" name="csrf_token" value="<?= $h($_SESSION['ao_csrf']) ?>">
                        <input type="hidden" name="action" value="submission_folder">
                        <button style="min-height:42px;padding:9px 14px;border:1px solid #d8e0eb;border-radius:8px;background:#fff;color:#34425a;font-size:12px;font-weight:800;"><i class="fa-solid fa-folder-plus mr-2"></i>Creer le dossier de soumission</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</article>
<?php
$content = ob_get_clean();
require __DIR__ . '/includes/module_layout.php';
