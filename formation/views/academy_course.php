<?php
$course = $academyCourseData['course'] ?? [];
$lessons = $academyCourseData['lessons'] ?? [];
$quiz = $academyCourseData['quiz'] ?? null;
$questions = $academyQuizQuestions ?? [];
$lessons = array_values($lessons);
$hasQuiz = $quiz && !empty($questions);
$totalSteps = count($lessons) + (int)$hasQuiz;
?>
<link rel="stylesheet" href="<?= htmlspecialchars(($appBasePath === '' ? '' : $appBasePath) . '/formation/assets/academy-v11.css', ENT_QUOTES, 'UTF-8') ?>">
<a class="academy-back" href="<?= htmlspecialchars($formationHomeUrl . '?academy=dashboard', ENT_QUOTES, 'UTF-8') ?>">← Retour à FINEA Academy</a>
<section class="academy-course-hero">
    <div>
        <span class="academy-eyebrow"><?= htmlspecialchars((string) ($course['module_slug'] ?? 'Module'), ENT_QUOTES, 'UTF-8') ?></span>
        <h2><?= htmlspecialchars((string) ($course['title'] ?? 'Parcours'), ENT_QUOTES, 'UTF-8') ?></h2>
        <p><?= htmlspecialchars((string) ($course['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
    </div>
    <div class="academy-course-target">
        <span>Score requis</span>
        <strong><?= (int) ($course['required_score'] ?? 75) ?>%</strong>
        <small><?= (int) ($course['estimated_minutes'] ?? 15) ?> min estimées</small>
    </div>
</section>

<?php if (!empty($academyFlash)): ?>
    <div class="finea-alert academy-flash academy-flash--<?= htmlspecialchars((string) ($academyFlash['type'] ?? 'info'), ENT_QUOTES, 'UTF-8') ?>">
        <?= htmlspecialchars((string) ($academyFlash['message'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
        <?php if (isset($academyFlash['score'])): ?> Score : <strong><?= htmlspecialchars((string) $academyFlash['score'], ENT_QUOTES, 'UTF-8') ?>%</strong>.<?php endif; ?>
    </div>
<?php endif; ?>

<div class="academy-learning-layout academy-step-layout" data-academy-steps>
    <aside class="academy-toc">
        <h3>Chapitres</h3><p class="academy-step-counter" aria-live="polite"></p>
        <?php foreach ($lessons as $index => $lesson): ?>
            <a data-step-link href="#lesson-<?= (int) $lesson['id'] ?>"><span><?= $index + 1 ?></span><?= htmlspecialchars((string) $lesson['title'], ENT_QUOTES, 'UTF-8') ?></a>
        <?php endforeach; ?>
        <?php if ($hasQuiz): ?><a data-step-link href="#academy-final-quiz"><span>✓</span>Test final</a><?php endif; ?>
    </aside>
    <main class="academy-lessons">
        <?php foreach ($lessons as $index => $lesson): ?>
            <article data-learning-step class="academy-lesson" id="lesson-<?= (int) $lesson['id'] ?>">
                <div class="academy-lesson-index">Leçon <?= $index + 1 ?></div>
                <h3><?= htmlspecialchars((string) $lesson['title'], ENT_QUOTES, 'UTF-8') ?></h3>
                <?php if (!empty($lesson['video_url'])): ?>
                    <?php if (str_starts_with($lesson['video_url'],'private-video:')): ?>
<div class="academy-video"><video controls playsinline preload="metadata" src="<?= htmlspecialchars($appBasePath.'/formation/video.php?lesson='.(int)$lesson['id'], ENT_QUOTES,'UTF-8') ?>"></video></div>
<?php elseif (preg_match('~^https://(?:www\.youtube(?:-nocookie)?\.com/embed/|player\.vimeo\.com/video/)~i',$lesson['video_url'])): ?>
<div class="academy-video"><iframe sandbox="allow-scripts allow-same-origin allow-presentation" src="<?= htmlspecialchars($lesson['video_url'], ENT_QUOTES,'UTF-8') ?>" allowfullscreen loading="lazy"></iframe></div>
<?php endif; ?>
                <?php endif; ?>
                <?php foreach (($lesson['blocks'] ?? []) as $block): ?>
                    <div class="academy-block academy-block--<?= htmlspecialchars((string) $block['block_type'], ENT_QUOTES, 'UTF-8') ?>">
                        <?php if (!empty($block['title'])): ?><h4><?= htmlspecialchars((string) $block['title'], ENT_QUOTES, 'UTF-8') ?></h4><?php endif; ?>
                        <?php if ($block['block_type'] === 'image' && !empty($block['asset_path'])): ?><img src="<?= htmlspecialchars((string) $block['asset_path'], ENT_QUOTES, 'UTF-8') ?>" alt="Capture formation"><?php endif; ?>
                        <?php if (!empty($block['content'])): ?><p><?= nl2br(htmlspecialchars((string) $block['content'], ENT_QUOTES, 'UTF-8')) ?></p><?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <form method="post"><input type="hidden" name="academy_csrf" value="<?= htmlspecialchars($_SESSION['academy_csrf'],ENT_QUOTES,'UTF-8') ?>"><input type="hidden" name="formation_academy_action" value="complete_lesson"><input type="hidden" name="course_id" value="<?= (int)$course['id'] ?>"><input type="hidden" name="course_slug" value="<?= htmlspecialchars($course['slug'],ENT_QUOTES,'UTF-8') ?>"><input type="hidden" name="lesson_id" value="<?= (int)$lesson['id'] ?>"><button class="finea-action-btn finea-action-btn--primary">Marquer comme terminée</button></form>
            </article>
        <?php endforeach; ?>

        <?php if ($quiz && !empty($questions)): ?>
            <section data-learning-step class="academy-quiz" id="academy-final-quiz">
                <div class="academy-quiz-head">
                    <span class="academy-eyebrow">Validation de maîtrise</span>
                    <h3><?= htmlspecialchars((string) ($quiz['title'] ?? 'QCM final'), ENT_QUOTES, 'UTF-8') ?></h3>
                    <p>Choisissez vos réponses puis cliquez sur <strong>Valider ma réponse</strong> pour chaque question. L’envoi final reste bloqué tant que toutes les questions ne sont pas validées.</p>
                </div>
                <form method="post" class="academy-quiz-form" data-academy-quiz-form>
                    <input type="hidden" name="academy_csrf" value="<?= htmlspecialchars($_SESSION['academy_csrf'],ENT_QUOTES,'UTF-8') ?>"><input type="hidden" name="formation_academy_action" value="submit_quiz">
                    <input type="hidden" name="course_id" value="<?= (int) ($course['id'] ?? 0) ?>">
                    <input type="hidden" name="course_slug" value="<?= htmlspecialchars((string) ($course['slug'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="quiz_id" value="<?= (int) ($quiz['id'] ?? 0) ?>">
                    <?php foreach ($questions as $qIndex => $question):
                        $type = (string) ($question['question_type'] ?? 'single');
                        $inputType = $type === 'multiple' ? 'checkbox' : 'radio';
                    ?>
                        <fieldset class="academy-question" data-academy-question>
                            <legend><span>Question <?= $qIndex + 1 ?></span><?= htmlspecialchars((string) $question['question_text'], ENT_QUOTES, 'UTF-8') ?></legend>
                            <div class="academy-answers">
                                <?php foreach (($question['answers'] ?? []) as $answer): ?>
                                    <label class="academy-answer" data-correct="<?= !empty($answer['is_correct']) ? '1' : '0' ?>">
                                        <input type="<?= $inputType ?>" name="question_<?= (int) $question['id'] ?><?= $inputType === 'checkbox' ? '[]' : '' ?>" value="<?= (int) $answer['id'] ?>">
                                        <span><?= htmlspecialchars((string) $answer['answer_text'], ENT_QUOTES, 'UTF-8') ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" class="finea-action-btn finea-action-btn--secondary" data-academy-validate-question>Valider ma réponse</button>
                            <div class="academy-correction" hidden>
                                <strong>Correction</strong>
                                <p><?= nl2br(htmlspecialchars((string) ($question['explanation'] ?? 'Révisez la leçon correspondante pour comprendre la bonne réponse.'), ENT_QUOTES, 'UTF-8')) ?></p>
                            </div>
                        </fieldset>
                    <?php endforeach; ?>
                    <button type="submit" class="finea-action-btn finea-action-btn--accent academy-submit" data-academy-submit disabled>Envoyer le test final</button>
                </form>
            </section>
        <?php endif; ?>
        <?php if ($totalSteps === 0): ?><p>Aucun contenu publié pour ce parcours.</p><?php endif; ?>
        <nav class="academy-step-nav" aria-label="Navigation des chapitres" hidden>
            <button type="button" data-step-prev class="finea-action-btn finea-action-btn--secondary">← Précédent</button>
            <span data-step-position aria-live="polite"></span>
            <button type="button" data-step-next class="finea-action-btn finea-action-btn--primary">Suivant →</button>
        </nav>
    </main>
</div>
