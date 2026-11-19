<?php
$stats = $academyDashboard['stats'] ?? ['score' => 0, 'level' => 'Débutant', 'total_courses' => 0];
$courses = $academyDashboard['courses'] ?? [];
$courseUrl = static function (array $course) use ($formationHomeUrl) {
    return htmlspecialchars($formationHomeUrl . '?' . http_build_query(['academy' => 'course', 'course' => $course['slug'] ?? '']), ENT_QUOTES, 'UTF-8');
};
$score = (float) ($stats['score'] ?? 0);
$certifiedCount = 0;
foreach ($courses as $row) {
    if ((float)($row['best_score'] ?? 0) >= (float)($row['required_score'] ?? 75)) {
        $certifiedCount++;
    }
}
?>
<link rel="stylesheet" href="<?= htmlspecialchars(($appBasePath === '' ? '' : $appBasePath) . '/formation/assets/academy-v11.css', ENT_QUOTES, 'UTF-8') ?>">
<section class="academy-hero">
    <div>
        <span class="academy-eyebrow"><i class="fas fa-graduation-cap"></i> FINEA Academy</span>
        <h2>Votre parcours de maîtrise FINEA</h2>
        <p>Un espace MOOC clair, ciblé sur vos accès réels, avec progression, QCM et certification interne.</p>
        <div class="academy-hero-actions">
            <?php if (!empty($courses)): ?>
                <a class="finea-action-btn finea-action-btn--accent" href="<?= $courseUrl($courses[0]) ?>"><i class="fas fa-play"></i> Continuer</a>
            <?php endif; ?>
            <?php if (!empty($isFormationAdmin)): ?>
                <a class="finea-action-btn finea-action-btn--secondary" href="<?= htmlspecialchars(($appBasePath === '' ? '' : $appBasePath) . '/formation/admin/index.php', ENT_QUOTES, 'UTF-8') ?>"><i class="fas fa-sliders"></i> Administration</a>
            <?php endif; ?>
        </div>
    </div>
    <div class="academy-score-card">
        <div class="academy-ring" style="--score: <?= max(0, min(100, $score)) ?>;"><strong><?= number_format($score, 0) ?>%</strong></div>
        <span>Score global</span>
        <b><?= htmlspecialchars((string) ($stats['level'] ?? 'Débutant'), ENT_QUOTES, 'UTF-8') ?></b>
    </div>
</section>

<?php if (!empty($academyFlash)): ?>
    <div class="finea-alert academy-flash academy-flash--<?= htmlspecialchars((string) ($academyFlash['type'] ?? 'info'), ENT_QUOTES, 'UTF-8') ?>">
        <?= htmlspecialchars((string) ($academyFlash['message'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
        <?php if (isset($academyFlash['score'])): ?> Score : <strong><?= htmlspecialchars((string) $academyFlash['score'], ENT_QUOTES, 'UTF-8') ?>%</strong>.<?php endif; ?>
    </div>
<?php endif; ?>

<div class="academy-compact-kpis">
    <article class="academy-compact-kpi"><span>Parcours accessibles</span><strong><?= (int) ($stats['total_courses'] ?? count($courses)) ?></strong></article>
    <article class="academy-compact-kpi"><span>Certifications</span><strong><?= (int) $certifiedCount ?></strong></article>
    <article class="academy-compact-kpi"><span>Objectif standard</span><strong>75%</strong></article>
</div>

<section class="academy-soft-panel">
    <div class="academy-section-head">
        <div><h3>Mes parcours FINEA</h3><p>Chaque carte reprend la couleur et l’icône du module correspondant dans le portail.</p></div>
    </div>
    <?php if (empty($courses)): ?>
        <div class="finea-empty-state">Aucun parcours Academy n’est encore associé à vos droits.</div>
    <?php else: ?>
        <div class="academy-course-grid">
            <?php foreach ($courses as $course):
                $progress = (float) ($course['progress_percent'] ?? 0);
                $best = (float) ($course['best_score'] ?? 0);
                $required = (float) ($course['required_score'] ?? 75);
                $isCertified = $best >= $required;
                $moduleColor = (string)($course['module_color'] ?? '#1d4ed8');
                if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $moduleColor)) {
                    $moduleColor = '#1d4ed8';
                }
                $moduleIcon = (string)($course['module_icon'] ?? 'fa-graduation-cap');
                $moduleLabel = (string)($course['module_label'] ?? ($course['module_slug'] ?? 'Module'));
            ?>
                <article class="academy-course-card" style="--module-color: <?= htmlspecialchars($moduleColor, ENT_QUOTES, 'UTF-8') ?>;">
                    <div class="academy-card-top">
                        <span class="academy-card-icon"><i class="fas <?= htmlspecialchars($moduleIcon, ENT_QUOTES, 'UTF-8') ?>"></i></span>
                        <span class="finea-status-badge <?= $isCertified ? 'finea-status-badge--ok' : 'finea-status-badge--info' ?>"><?= $isCertified ? 'Certifié' : 'À suivre' ?></span>
                    </div>
                    <div>
                        <span class="academy-module"><?= htmlspecialchars($moduleLabel, ENT_QUOTES, 'UTF-8') ?></span>
                        <h4><?= htmlspecialchars((string) ($course['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?></h4>
                    </div>
                    <p><?= htmlspecialchars((string) ($course['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
                    <div class="academy-progress-line"><span style="width: <?= max(0, min(100, $progress)) ?>%; background: <?= htmlspecialchars($moduleColor, ENT_QUOTES, 'UTF-8') ?>"></span></div>
                    <div class="academy-card-meta"><span>Progression <?= number_format($progress, 0) ?>%</span><span>Score <?= number_format($best, 0) ?>%</span></div>
                    <div class="academy-card-footer">
                        <small>Seuil <?= number_format($required, 0) ?>%</small>
                        <a class="finea-action-btn finea-action-btn--primary" href="<?= $courseUrl($course) ?>"><?= $progress > 0 ? 'Continuer' : 'Démarrer' ?></a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
