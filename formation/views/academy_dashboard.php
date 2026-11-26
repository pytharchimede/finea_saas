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
<section class="academy-welcome">
<h2><?= $academyMode === 'catalog' ? 'Catalogue des formations' : ($academyMode === 'progress' ? 'Mes formations' : 'Que souhaitez-vous apprendre ?') ?></h2>
<p><?= $academyMode === 'progress' ? 'Retrouvez vos résultats et vos parcours commencés.' : 'Choisissez un parcours et avancez à votre rythme.' ?></p>
<?php if ($academyMode === 'dashboard'): ?><a class="finea-action-btn finea-action-btn--primary" href="<?= htmlspecialchars(formationBuildUrl(['academy'=>'catalog']), ENT_QUOTES, 'UTF-8') ?>">Explorer le catalogue</a><?php endif; ?>
</section>
<?php if (!empty($academyFlash)): ?>
    <div class="finea-alert academy-flash academy-flash--<?= htmlspecialchars((string) ($academyFlash['type'] ?? 'info'), ENT_QUOTES, 'UTF-8') ?>">
        <?= htmlspecialchars((string) ($academyFlash['message'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
        <?php if (isset($academyFlash['score'])): ?> Score : <strong><?= htmlspecialchars((string) $academyFlash['score'], ENT_QUOTES, 'UTF-8') ?>%</strong>.<?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($academyMode === 'progress'): ?><div class="academy-compact-kpis">
    <article class="academy-compact-kpi"><span>Parcours accessibles</span><strong><?= (int) ($stats['total_courses'] ?? count($courses)) ?></strong></article>
    <article class="academy-compact-kpi"><span>Certifications</span><strong><?= (int) $certifiedCount ?></strong></article>
    <article class="academy-compact-kpi"><span>Score global</span><strong><?= number_format($score,0) ?>%</strong></article>
</div><?php endif; ?>

<?php
$visibleCourses = $courses;
if ($academyMode === 'progress') $visibleCourses = array_values(array_filter($courses, static fn($c) => (float)($c['progress_percent']??0)>0 || (float)($c['best_score']??0)>0));
if ($academyMode === 'dashboard') { usort($visibleCourses, static fn($a,$b)=>(float)($b['progress_percent']??0)<=>(float)($a['progress_percent']??0)); $visibleCourses=array_slice($visibleCourses,0,4); }
?>
<section class="academy-soft-panel">
    <div class="academy-section-head">
        <div><h3><?= $academyMode === 'dashboard' ? 'Parcours à découvrir ou reprendre' : 'Parcours' ?></h3></div>
    </div>
    <?php if (empty($visibleCourses)): ?>
        <div class="finea-empty-state">Aucun parcours à afficher ici. Consultez le catalogue pour commencer.</div>
    <?php else: ?>
        <input type="search" id="academyCourseFilter" placeholder="Rechercher une formation…" aria-label="Rechercher une formation"><div class="academy-course-grid academy-course-list">
            <?php foreach ($visibleCourses as $course):
                $progress = (float) ($course['progress_percent'] ?? 0);
                $best = (float) ($course['best_score'] ?? 0);
                $required = (float) ($course['required_score'] ?? 75);
                $isCertified = $best >= $required && $required > 0;
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
                        
                        <a class="finea-action-btn finea-action-btn--primary" href="<?= $courseUrl($course) ?>"><?= $progress > 0 ? 'Continuer' : 'Démarrer' ?></a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php if(isset($academyPdo)): require_once dirname(__DIR__).'/model/AcademyPaths.php'; $paths=academyPaths($academyPdo,$currentUserId,array_map('intval',array_column($courses,'id'))); ?>
<section class="academy-soft-panel"><h3>Parcours de certification</h3>
<?php foreach($paths as $path): ?><article class="academy-block"><h4><?= htmlspecialchars($path['title'],ENT_QUOTES,'UTF-8') ?></h4><p><?= htmlspecialchars($path['description']??'',ENT_QUOTES,'UTF-8') ?></p><p><?= (int)$path['completed'] ?> / <?= count($path['courses']) ?> cours validés</p>
<?php foreach($path['courses'] as $c): ?><p><a href="<?= htmlspecialchars(formationBuildUrl(['academy'=>'course','course'=>$c['slug']]),ENT_QUOTES,'UTF-8') ?>"><?= $c['score']!==null?'✓':'○' ?> <?= htmlspecialchars($c['title'],ENT_QUOTES,'UTF-8') ?></a></p><?php endforeach; ?>
<?php if($path['completed']===count($path['courses'])): ?><form method="post" action="<?= htmlspecialchars($appBasePath.'/formation/certificate.php',ENT_QUOTES,'UTF-8') ?>"><input type="hidden" name="academy_csrf" value="<?= htmlspecialchars($_SESSION['academy_csrf'],ENT_QUOTES,'UTF-8') ?>"><input type="hidden" name="path" value="<?= (int)$path['id'] ?>"><button class="finea-action-btn finea-action-btn--primary">Télécharger le certificat de parcours</button></form><?php endif; ?></article><?php endforeach; ?>
<?php if(!$paths): ?><p>Aucun parcours multicours disponible.</p><?php endif; ?></section><?php endif; ?>
