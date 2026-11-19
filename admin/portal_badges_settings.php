<?php

require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/PortalBadgeManager.php';
require_once __DIR__ . '/../model/PortalModuleManager.php';

if (empty($_SESSION['permissions']['acces_admin'])) {
    http_response_code(403);
    exit('Accès refusé');
}

$pdo = \Database::getConnection();
$currentUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
$permissions = is_array($_SESSION['permissions'] ?? null) ? $_SESSION['permissions'] : [];
$badgeManager = new \PortalBadgeManager($pdo, $currentUserId, $permissions, $_SESSION);
$portalModuleManager = new \PortalModuleManager($pdo);

function ensurePortalBadgeSettingsTable(\PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS portal_badge_settings (
            module_key VARCHAR(120) NOT NULL PRIMARY KEY,
            counted_description TEXT NULL,
            variant_labels_json LONGTEXT NULL,
            thresholds_json LONGTEXT NULL,
            updated_by INT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
}

function loadPortalBadgeStoredSettings(\PDO $pdo): array
{
    ensurePortalBadgeSettingsTable($pdo);
    $stmt = $pdo->query('SELECT module_key, counted_description, variant_labels_json, thresholds_json, updated_by, updated_at FROM portal_badge_settings ORDER BY module_key ASC');
    $rows = $stmt ? ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []) : [];
    $settings = [];

    foreach ($rows as $row) {
        $moduleKey = trim((string) ($row['module_key'] ?? ''));
        if ($moduleKey === '') {
            continue;
        }

        $variantLabels = json_decode((string) ($row['variant_labels_json'] ?? ''), true);
        $thresholds = json_decode((string) ($row['thresholds_json'] ?? ''), true);
        $settings[$moduleKey] = [
            'module_key' => $moduleKey,
            'counted_description' => trim((string) ($row['counted_description'] ?? '')),
            'variant_labels' => is_array($variantLabels) ? $variantLabels : [],
            'thresholds' => is_array($thresholds) ? $thresholds : [],
            'updated_by' => isset($row['updated_by']) ? (int) $row['updated_by'] : null,
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    return $settings;
}

function savePortalBadgeSettings(\PDO $pdo, string $moduleKey, array $data, ?int $updatedBy = null): void
{
    ensurePortalBadgeSettingsTable($pdo);

    $moduleKey = trim($moduleKey);
    if ($moduleKey === '') {
        throw new \InvalidArgumentException('Module invalide.');
    }

    $variantLabels = is_array($data['variant_labels'] ?? null) ? $data['variant_labels'] : [];
    $thresholds = is_array($data['thresholds'] ?? null) ? $data['thresholds'] : [];
    $normalizedVariantLabels = [];
    foreach (['watch', 'urgent', 'critical'] as $variant) {
        $normalizedVariantLabels[$variant] = trim((string) ($variantLabels[$variant] ?? ''));
    }

    $normalizedThresholds = [];
    foreach ($thresholds as $key => $value) {
        $thresholdKey = trim((string) $key);
        if ($thresholdKey === '') {
            continue;
        }
        $normalizedThresholds[$thresholdKey] = is_numeric($value) ? (int) $value : trim((string) $value);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO portal_badge_settings (module_key, counted_description, variant_labels_json, thresholds_json, updated_by)
         VALUES (:module_key, :counted_description, :variant_labels_json, :thresholds_json, :updated_by)
         ON DUPLICATE KEY UPDATE
            counted_description = VALUES(counted_description),
            variant_labels_json = VALUES(variant_labels_json),
            thresholds_json = VALUES(thresholds_json),
            updated_by = VALUES(updated_by),
            updated_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute([
        'module_key' => $moduleKey,
        'counted_description' => trim((string) ($data['counted_description'] ?? '')),
        'variant_labels_json' => json_encode($normalizedVariantLabels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'thresholds_json' => json_encode($normalizedThresholds, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'updated_by' => $updatedBy,
    ]);
}

$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $moduleKey = trim((string) ($_POST['module_key'] ?? ''));
        $variantLabels = is_array($_POST['variant_labels'] ?? null) ? $_POST['variant_labels'] : [];
        $thresholds = is_array($_POST['thresholds'] ?? null) ? $_POST['thresholds'] : [];

        savePortalBadgeSettings($pdo, $moduleKey, [
            'counted_description' => trim((string) ($_POST['counted_description'] ?? '')),
            'variant_labels' => $variantLabels,
            'thresholds' => $thresholds,
        ], $currentUserId);

        $flash = [
            'type' => 'success',
            'message' => 'Le paramétrage du badge a été enregistré.',
        ];
    } catch (Throwable $e) {
        $flash = [
            'type' => 'error',
            'message' => 'Impossible d’enregistrer ce paramétrage.',
        ];
    }
}

$guide = $badgeManager->getSettingsSnapshot();
$storedSettings = loadPortalBadgeStoredSettings($pdo);
$modulesByKey = [];
foreach ($portalModuleManager->listAll() as $module) {
    $moduleKey = (string) ($module['module_key'] ?? '');
    if ($moduleKey === '') {
        continue;
    }
    $modulesByKey[$moduleKey] = $module;
}

$moduleCards = [];
foreach (($guide['modules'] ?? []) as $moduleKey => $moduleGuide) {
    $definition = $modulesByKey[$moduleKey] ?? [];
    $moduleCards[] = [
        'module_key' => $moduleKey,
        'label' => (string) ($definition['label'] ?? $moduleKey),
        'target' => (string) ($definition['target'] ?? ''),
        'icon' => preg_replace('/^fa[srbld]?\s+/i', '', (string) ($definition['icon'] ?? 'fa-circle')) ?: 'fa-circle',
        'guide' => $moduleGuide,
        'stored' => $storedSettings[$moduleKey] ?? [],
    ];
}

usort($moduleCards, static function (array $left, array $right): int {
    return strcmp((string) ($left['label'] ?? ''), (string) ($right['label'] ?? ''));
});

require_once __DIR__ . '/../decaissement/includes/header.php';
?>
<style>
    .badge-settings-shell {
        min-height: 100vh;
        padding: 2rem 1rem 3rem;
        background:
            radial-gradient(circle at top left, rgba(15, 118, 110, 0.16), transparent 24%),
            radial-gradient(circle at bottom right, rgba(37, 99, 235, 0.14), transparent 26%),
            #f8fafc;
    }

    .badge-settings-wrap {
        max-width: 1280px;
        margin: 0 auto;
    }

    .badge-settings-hero,
    .badge-settings-card {
        background: rgba(255, 255, 255, 0.94);
        border: 1px solid rgba(148, 163, 184, 0.18);
        border-radius: 28px;
        box-shadow: 0 18px 46px -30px rgba(15, 23, 42, 0.3);
    }

    .badge-settings-hero {
        padding: 1.6rem;
    }

    .badge-settings-title {
        margin: 0;
        font-size: 2rem;
        font-weight: 800;
        color: #0f172a;
    }

    .badge-settings-subtitle {
        margin-top: .65rem;
        color: #475569;
        line-height: 1.65;
        max-width: 880px;
    }

    .badge-settings-actions,
    .badge-settings-color-grid,
    .badge-settings-grid,
    .badge-settings-fields,
    .badge-settings-thresholds {
        display: grid;
        gap: 1rem;
    }

    .badge-settings-actions {
        display: flex;
        flex-wrap: wrap;
        gap: .8rem;
        margin-top: 1.15rem;
    }

    .badge-settings-link {
        display: inline-flex;
        align-items: center;
        gap: .55rem;
        padding: .85rem 1rem;
        border-radius: 16px;
        text-decoration: none;
        font-weight: 700;
        background: #fff;
        border: 1px solid rgba(148, 163, 184, 0.22);
        color: #0f172a;
    }

    .badge-settings-link.primary {
        background: linear-gradient(135deg, #0f766e, #0f172a);
        color: #fff;
        border-color: transparent;
    }

    .badge-settings-flash {
        margin-top: 1rem;
        padding: 1rem 1.1rem;
        border-radius: 18px;
        font-weight: 700;
    }

    .badge-settings-flash.success {
        background: #ecfdf5;
        color: #065f46;
        border: 1px solid #a7f3d0;
    }

    .badge-settings-flash.error {
        background: #fef2f2;
        color: #991b1b;
        border: 1px solid #fecaca;
    }

    .badge-settings-color-grid {
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        margin-top: 1.3rem;
    }

    .badge-settings-color-card {
        padding: 1.1rem;
        border-radius: 22px;
        border: 1px solid rgba(148, 163, 184, 0.18);
        background: rgba(255, 255, 255, 0.92);
    }

    .badge-settings-pill {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 42px;
        height: 42px;
        padding: 0 .8rem;
        border-radius: 999px;
        color: #fff;
        font-weight: 800;
        font-size: .88rem;
        margin-bottom: .7rem;
    }

    .badge-settings-pill.watch {
        background: linear-gradient(135deg, #f59e0b, #d97706);
    }

    .badge-settings-pill.urgent {
        background: linear-gradient(135deg, #dc2626, #b91c1c);
    }

    .badge-settings-pill.critical {
        background: linear-gradient(135deg, #7f1d1d, #991b1b 58%, #dc2626 100%);
    }

    .badge-settings-grid {
        grid-template-columns: repeat(auto-fit, minmax(340px, 1fr));
        margin-top: 1.5rem;
    }

    .badge-settings-card {
        padding: 1.3rem;
    }

    .badge-settings-card-header {
        display: flex;
        align-items: center;
        gap: .85rem;
        margin-bottom: 1rem;
    }

    .badge-settings-icon {
        width: 46px;
        height: 46px;
        border-radius: 14px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        color: #fff;
        background: linear-gradient(135deg, #1d4ed8, #0f172a);
    }

    .badge-settings-card-title {
        margin: 0;
        font-size: 1.08rem;
        font-weight: 800;
        color: #0f172a;
    }

    .badge-settings-card-meta {
        margin-top: .25rem;
        font-size: .84rem;
        color: #64748b;
    }

    .badge-settings-fields {
        grid-template-columns: 1fr;
    }

    .badge-settings-thresholds {
        grid-template-columns: repeat(auto-fit, minmax(110px, 1fr));
    }

    .badge-settings-label {
        display: block;
        margin-bottom: .42rem;
        font-size: .8rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: .08em;
        color: #475569;
    }

    .badge-settings-input,
    .badge-settings-textarea {
        width: 100%;
        border: 1px solid rgba(148, 163, 184, 0.26);
        border-radius: 16px;
        padding: .85rem .95rem;
        background: #fff;
        color: #0f172a;
    }

    .badge-settings-textarea {
        min-height: 108px;
        resize: vertical;
    }

    .badge-settings-note {
        margin-top: .55rem;
        color: #64748b;
        font-size: .84rem;
        line-height: 1.55;
    }

    .badge-settings-submit {
        margin-top: 1rem;
        width: 100%;
        border: 0;
        border-radius: 16px;
        padding: .95rem 1rem;
        background: linear-gradient(135deg, #0f766e, #0f172a);
        color: #fff;
        font-weight: 800;
    }
</style>

<div class="badge-settings-shell">
    <div class="badge-settings-wrap">
        <section class="badge-settings-hero">
            <h1 class="badge-settings-title">Paramétrage des badges du portail</h1>
            <p class="badge-settings-subtitle">Cette page sert à régler ce que chaque badge doit expliquer à l’utilisateur, le texte du niveau d’alerte à afficher, et les seuils qui font passer un badge d’un niveau à un autre. Le calcul métier reste le même. Ici, vous ajustez surtout la lecture et les règles de déclenchement.</p>
            <div class="badge-settings-actions">
                <a href="../selection_portail.php" class="badge-settings-link primary"><i class="fas fa-th-large"></i><span>Retour au portail</span></a>
                <a href="../portal_badges_help.php" class="badge-settings-link"><i class="fas fa-circle-info"></i><span>Voir l’explication utilisateur</span></a>
                <a href="portal_badges_debug.php" class="badge-settings-link"><i class="fas fa-bug"></i><span>Voir le debug technique</span></a>
            </div>
            <?php if ($flash): ?>
                <div class="badge-settings-flash <?= htmlspecialchars((string) $flash['type'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $flash['message'], ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
        </section>

        <section class="badge-settings-color-grid">
            <?php foreach (($guide['colors'] ?? []) as $variant => $color): ?>
                <article class="badge-settings-color-card">
                    <div class="badge-settings-pill <?= htmlspecialchars((string) $variant, ENT_QUOTES, 'UTF-8') ?>">12</div>
                    <div class="font-extrabold text-slate-900"><?= htmlspecialchars((string) ($color['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?> : <?= htmlspecialchars((string) ($color['meaning'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="mt-2 text-slate-600 leading-6"><?= htmlspecialchars((string) ($color['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                </article>
            <?php endforeach; ?>
        </section>

        <section class="badge-settings-grid">
            <?php foreach ($moduleCards as $card): ?>
                <?php
                $moduleKey = (string) $card['module_key'];
                $moduleGuide = (array) ($card['guide'] ?? []);
                $variantLabels = (array) ($moduleGuide['variant_labels'] ?? []);
                $thresholds = (array) ($moduleGuide['thresholds'] ?? []);
                $stored = (array) ($card['stored'] ?? []);
                ?>
                <form method="post" class="badge-settings-card">
                    <input type="hidden" name="module_key" value="<?= htmlspecialchars($moduleKey, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="badge-settings-card-header">
                        <div class="badge-settings-icon"><i class="fas <?= htmlspecialchars((string) $card['icon'], ENT_QUOTES, 'UTF-8') ?>"></i></div>
                        <div>
                            <h2 class="badge-settings-card-title"><?= htmlspecialchars((string) $card['label'], ENT_QUOTES, 'UTF-8') ?></h2>
                            <div class="badge-settings-card-meta"><?= htmlspecialchars((string) $moduleKey, ENT_QUOTES, 'UTF-8') ?><?php if ((string) $card['target'] !== ''): ?> • <?= htmlspecialchars((string) $card['target'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?></div>
                        </div>
                    </div>

                    <div class="badge-settings-fields">
                        <div>
                            <label class="badge-settings-label" for="desc_<?= htmlspecialchars($moduleKey, ENT_QUOTES, 'UTF-8') ?>">Ce qui est compté</label>
                            <textarea class="badge-settings-textarea" id="desc_<?= htmlspecialchars($moduleKey, ENT_QUOTES, 'UTF-8') ?>" name="counted_description"><?= htmlspecialchars((string) ($moduleGuide['counted_description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                            <div class="badge-settings-note">Expliquez avec des mots simples ce que le chiffre du badge représente.</div>
                        </div>

                        <div>
                            <div class="badge-settings-label">Libellés par niveau d’alerte</div>
                            <div class="badge-settings-thresholds">
                                <?php foreach (['watch' => 'Orange', 'urgent' => 'Rouge', 'critical' => 'Rouge foncé'] as $variantKey => $variantTitle): ?>
                                    <div>
                                        <label class="badge-settings-label" for="label_<?= htmlspecialchars($moduleKey . '_' . $variantKey, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($variantTitle, ENT_QUOTES, 'UTF-8') ?></label>
                                        <input class="badge-settings-input" id="label_<?= htmlspecialchars($moduleKey . '_' . $variantKey, ENT_QUOTES, 'UTF-8') ?>" type="text" name="variant_labels[<?= htmlspecialchars($variantKey, ENT_QUOTES, 'UTF-8') ?>]" value="<?= htmlspecialchars((string) ($variantLabels[$variantKey] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="badge-settings-note">Ce texte est repris dans l’aide utilisateur et dans l’info du badge.</div>
                        </div>

                        <?php if ($thresholds !== []): ?>
                            <div>
                                <div class="badge-settings-label">Seuils utilisés</div>
                                <div class="badge-settings-thresholds">
                                    <?php foreach ($thresholds as $thresholdKey => $thresholdValue): ?>
                                        <div>
                                            <label class="badge-settings-label" for="threshold_<?= htmlspecialchars($moduleKey . '_' . (string) $thresholdKey, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $thresholdKey, ENT_QUOTES, 'UTF-8') ?></label>
                                            <input class="badge-settings-input" id="threshold_<?= htmlspecialchars($moduleKey . '_' . (string) $thresholdKey, ENT_QUOTES, 'UTF-8') ?>" type="text" name="thresholds[<?= htmlspecialchars((string) $thresholdKey, ENT_QUOTES, 'UTF-8') ?>]" value="<?= htmlspecialchars((string) $thresholdValue, ENT_QUOTES, 'UTF-8') ?>">
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="badge-settings-note">Si vous augmentez un seuil, le badge montera moins vite de niveau. Si vous le baissez, l’alerte apparaîtra plus tôt.</div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <button type="submit" class="badge-settings-submit">Enregistrer ce module</button>
                </form>
            <?php endforeach; ?>
        </section>
    </div>
</div>

<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>