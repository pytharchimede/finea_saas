<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . '/auth_check.php';
if (file_exists($projectRoot . '/model/Database.php')) require_once $projectRoot . '/model/Database.php';
if (file_exists($projectRoot . '/AccessManager.php')) require_once $projectRoot . '/AccessManager.php';
require_once dirname(__DIR__) . '/controllers/FormationAcademyController.php';
require_once $projectRoot . '/model/CompanyExportBranding.php';

$permissions = is_array($_SESSION['permissions'] ?? null) ? $_SESSION['permissions'] : [];
$isFormationAdmin = !empty($permissions['formation_admin']) || !empty($permissions['acces_admin']) || !empty($permissions['administration_systeme']);
if (!$isFormationAdmin) { http_response_code(403); echo 'Accès refusé : administration Formation réservée aux administrateurs.'; exit; }

$appBasePath = class_exists('AccessManager') ? rtrim(AccessManager::getApplicationBasePath(), '/') : '';
$assetBase = $appBasePath === '' ? '' : $appBasePath;
$homeUrl = $assetBase . '/formation/index.php';
$adminUrl = $assetBase . '/formation/admin/index.php';
$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

try { $pdo = Database::getConnection(); $controller = new \Formation\Controllers\FormationAcademyController($pdo); }
catch (Throwable $e) { http_response_code(500); echo 'Impossible de charger FINEA Academy : ' . $h($e->getMessage()); exit; }

function academy_admin_redirect(string $url): void { header('Location: ' . $url); exit; }
function academy_admin_flash(?array $set = null): ?array { if ($set !== null) { $_SESSION['academy_admin_flash'] = $set; return null; } $f = $_SESSION['academy_admin_flash'] ?? null; unset($_SESSION['academy_admin_flash']); return is_array($f) ? $f : null; }
function academy_upload_image(string $projectRoot, array $file): ?string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) throw new RuntimeException('Image non transférée correctement.');
    if (($file['size'] ?? 0) > 5 * 1024 * 1024) throw new RuntimeException('Image trop lourde : maximum 5 Mo.');
    $tmp = (string)($file['tmp_name'] ?? '');
    $info = @getimagesize($tmp);
    if (!$info) throw new RuntimeException('Le fichier ajouté n’est pas une image valide.');
    $allowed = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    $type = (int)($info[2] ?? 0);
    if (!isset($allowed[$type])) throw new RuntimeException('Formats acceptés : JPG, PNG, WEBP.');
    $dir = $projectRoot . '/formation/uploads/academy/' . date('Y/m');
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $name = 'academy_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$type];
    $dest = $dir . '/' . $name;
    if (!move_uploaded_file($tmp, $dest)) throw new RuntimeException('Impossible d’enregistrer l’image.');
    return 'formation/uploads/academy/' . date('Y/m') . '/' . $name;
}
function academy_csv_export(string $filename, array $rows): void {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    if (!$out) exit;
    if ($rows !== []) fputcsv($out, array_keys($rows[0]), ';');
    foreach ($rows as $row) fputcsv($out, array_map(static fn($v) => is_scalar($v) || $v === null ? (string)$v : json_encode($v), $row), ';');
    fclose($out); exit;
}

$action = (string)($_GET['action'] ?? 'dashboard');
$courseId = (int)($_GET['id'] ?? $_GET['course_id'] ?? $_POST['course_id'] ?? 0);
if ($action === 'export') {
    $type = preg_replace('/[^a-z_]/', '', (string)($_GET['type'] ?? 'courses')) ?: 'courses';
    if (!in_array($type, ['courses','attempts','certificates'], true)) { http_response_code(400); exit('Rapport inconnu'); }
    $rows = $controller->adminExportRows($type);
    if (($_GET['format'] ?? '') === 'xlsx') {
        require_once $projectRoot . '/vendor/autoload.php';
        $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $book->getProperties()->setCreator(\CompanyExportBranding::creator())->setTitle('FINEA Academy — '.$type);
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Academy');
        $columns = $rows ? array_keys($rows[0]) : ['Information'];
        $last = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(max(4,count($columns)));
        foreach ([1=>\CompanyExportBranding::creator(),2=>'Rapport formation — '.$type,3=>\CompanyExportBranding::identityLine()] as $i=>$label) {
            $sheet->mergeCells('A'.$i.':'.$last.$i);
            $sheet->setCellValueExplicit('A'.$i,$label,\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        }
        $sheet->getStyle('A1:'.$last.'2')->getFill()->setFillType('solid')->getStartColor()->setRGB(\CompanyExportBranding::rgb('primary'));
        $sheet->getStyle('A1:'.$last.'2')->getFont()->setBold(true)->getColor()->setRGB(\CompanyExportBranding::rgb('primary',true));
        $sheet->fromArray($columns,null,'A5');
        $end = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($columns));
        $sheet->getStyle('A5:'.$end.'5')->getFill()->setFillType('solid')->getStartColor()->setRGB(\CompanyExportBranding::rgb('accent'));
        $sheet->getStyle('A5:'.$end.'5')->getFont()->setBold(true)->getColor()->setRGB(\CompanyExportBranding::rgb('accent',true));
        foreach ($rows as $i=>$row) foreach ($columns as $j=>$key) {
            $v=$row[$key]??''; $cell=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($j+1).($i+6);
            $sheet->setCellValueExplicit($cell,is_scalar($v)?(string)$v:(string)json_encode($v),\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        }
        for($i=1;$i<=count($columns);$i++) $sheet->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
        $sheet->freezePane('A6'); $sheet->setAutoFilter('A5:'.$end.(count($rows)+5));
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="finea_academy_'.$type.'.xlsx"');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save('php://output'); exit;
    }
    academy_csv_export('finea_academy_' . $type . '_' . date('Ymd_His') . '.csv', $rows);
}

$_SESSION['academy_csrf'] ??= bin2hex(random_bytes(32));
$flash = academy_admin_flash();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($_SESSION['academy_csrf'], (string)($_POST['academy_csrf'] ?? ''))) throw new RuntimeException('Session expirée.');
        $postAction = (string)($_POST['admin_action'] ?? '');
        if ($postAction === 'save_course') {
            $id = $controller->saveAdminCourse($_POST);
            academy_admin_flash(['type'=>'ok','message'=>'Parcours enregistré avec succès.']);
            academy_admin_redirect($adminUrl . '?action=edit_course&id=' . $id);
        }
        if ($postAction === 'save_requirement') {
            $controller->saveAdminRequirement($_POST);
            academy_admin_flash(['type'=>'ok','message'=>'Règle d’obligation enregistrée.']);
            academy_admin_redirect($adminUrl . '?action=edit_course&id=' . (int)$_POST['course_id'] . '#requirements');
        }
        if ($postAction === 'save_lesson') {
            $uploaded = null;
            if (isset($_FILES['lesson_video']) && $_FILES['lesson_video']['error'] !== UPLOAD_ERR_NO_FILE) {
                require_once dirname(__DIR__).'/model/AcademyMedia.php';
                $uploaded = academyStoreVideo($_FILES['lesson_video']);
                $_POST['video_url'] = 'private-video:'.$uploaded;
                $_POST['lesson_type'] = 'video';
            }
            try { $lessonId = $controller->saveAdminLesson($_POST); }
            catch (Throwable $e) { if ($uploaded) @unlink(academyVideoDirectory().'/'.$uploaded); throw $e; }
            $imagePath = isset($_FILES['lesson_image']) ? academy_upload_image($projectRoot, $_FILES['lesson_image']) : null;
            if ($imagePath) {
                $controller->saveAdminLessonBlock([
                    'lesson_id' => $lessonId,
                    'block_type' => 'image',
                    'title' => $_POST['image_title'] ?? 'Capture d’écran',
                    'content' => $_POST['image_caption'] ?? '',
                    'asset_path' => $imagePath,
                    'sort_order' => 1,
                ]);
            }
            if (!$uploaded && trim((string)($_POST['video_url'] ?? '')) !== '') {
                $controller->saveAdminLessonBlock([
                    'lesson_id' => $lessonId,
                    'block_type' => 'video',
                    'title' => 'Vidéo tutorielle',
                    'asset_path' => trim((string)$_POST['video_url']),
                    'sort_order' => 2,
                ]);
            }
            academy_admin_flash(['type'=>'ok','message'=>'Leçon enregistrée. Image/vidéo associée si fournie.']);
            academy_admin_redirect($adminUrl . '?action=edit_course&id=' . (int)$_POST['course_id'] . '#lessons');
        }
    } catch (Throwable $e) { $flash = ['type'=>'danger','message'=>$e->getMessage()]; }
}

$admin = $controller->adminHome();
$editor = in_array($action, ['edit_course','new_course'], true) ? $controller->adminCourseEditor($courseId) : null;
$course = $editor['course'] ?? null;
$courses = $admin['courses'] ?? [];
$moduleOptions = array_values(array_unique(array_filter(array_map(static fn($c) => (string)($c['module_slug'] ?? ''), $courses))));
$permissionOptions = ['acces_decaissement','acces_tickets','acces_rh','acces_logistique','acces_crm','acces_dashboard','acces_portail','formation_admin','acces_admin'];
$navItems = [
    ['dashboard','Tableau de bord','Vue synthétique'], ['courses','Parcours','Catalogue MOOC'], ['edit_course','Formulaires','Créer / éditer'], ['reports','Exports & rapports','CSV et suivi'],
];
$hasPortalChrome = file_exists($projectRoot . '/decaissement/includes/header.php') && file_exists($projectRoot . '/decaissement/includes/navbar.php');
if ($hasPortalChrome) { require_once $projectRoot . '/decaissement/includes/header.php'; require_once $projectRoot . '/decaissement/includes/navbar.php'; }
else { ?><!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Administration FINEA Academy</title><link rel="stylesheet" href="<?= $h($assetBase . '/assets/css/finea-ui.css') ?>"></head><body><?php }
?>
<link rel="stylesheet" href="<?= $h($assetBase . '/formation/assets/academy-admin-v12.css') ?>">
<div class="finea-shell academy-admin-v12">
    <aside class="academy-admin-aside">
        <div class="academy-admin-brand"><span>FA</span><div><strong>FINEA Academy</strong><small>Administration MOOC</small></div></div>
        <nav>
            <?php foreach ($navItems as [$key,$label,$sub]): $active = ($action === $key) || ($key === 'edit_course' && $action === 'new_course'); ?>
                <a class="<?= $active ? 'active' : '' ?>" href="<?= $h($adminUrl . '?action=' . ($key === 'edit_course' ? 'new_course' : $key)) ?>"><strong><?= $h($label) ?></strong><small><?= $h($sub) ?></small></a>
            <?php endforeach; ?>
        </nav>
        <div class="academy-admin-aside-footer"><a href="<?= $h($homeUrl . '?academy=dashboard') ?>">Vue apprenant</a><a href="<?= $h($homeUrl) ?>">Formation classique</a></div>
    </aside>

    <main class="academy-admin-main">
        <header class="academy-admin-topbar">
            <div><p class="eyebrow">Administration FINEA Academy</p><h1><?= $action === 'new_course' ? 'Nouveau parcours' : ($action === 'edit_course' ? 'Modifier le parcours' : 'Pilotage de la formation') ?></h1><span>Parcours, obligations, médias, progression, certificats et exports.</span></div>
            <div class="academy-admin-actions"><button type="button" data-academy-theme class="academy-theme-button">Mode sombre</button><a class="finea-action-btn finea-action-btn--secondary" href="<?= $h($adminUrl . '?action=export&type=courses') ?>">Exporter parcours</a><a class="finea-action-btn finea-action-btn--accent" href="<?= $h($adminUrl . '?action=new_course') ?>">Nouveau parcours</a></div>
        </header>

        <?php if ($flash): ?><div class="finea-alert academy-flash academy-flash-<?= $h($flash['type'] ?? 'ok') ?>"><?= $h($flash['message'] ?? '') ?></div><?php endif; ?>

        <?php if ($action === 'dashboard'): ?>
            <section class="academy-kpis">
                <article><small>Parcours</small><strong><?= (int)($admin['stats']['courses'] ?? 0) ?></strong><span><?= (int)($admin['stats']['active_courses'] ?? 0) ?> actifs</span></article>
                <article><small>Tentatives</small><strong><?= (int)($admin['stats']['attempts'] ?? 0) ?></strong><span>QCM soumis</span></article>
                <article><small>Certificats</small><strong><?= (int)($admin['stats']['certificates'] ?? 0) ?></strong><span>validations obtenues</span></article>
                <article><small>Score moyen</small><strong><?= number_format((float)($admin['stats']['avg_score'] ?? 0),0) ?>%</strong><span>maîtrise globale</span></article>
            </section>
            <section class="academy-panel"><div class="academy-panel-head"><h2>Parcours récents</h2><a href="<?= $h($adminUrl . '?action=courses') ?>">Tout afficher</a></div><?php include __DIR__ . '/partials_courses_table.php'; ?></section>
        <?php elseif ($action === 'courses'): ?>
            <section class="academy-panel"><div class="academy-panel-head"><h2>Catalogue des parcours</h2><div><a class="finea-action-btn finea-action-btn--secondary" href="<?= $h($adminUrl . '?action=export&type=courses') ?>">CSV</a><a class="finea-action-btn finea-action-btn--accent" href="<?= $h($adminUrl . '?action=new_course') ?>">Ajouter</a></div></div><?php include __DIR__ . '/partials_courses_table.php'; ?></section>
        <?php elseif (in_array($action, ['edit_course','new_course'], true)): ?>
            <section class="academy-editor-grid">
                <form method="post" class="academy-panel academy-form-card"><input type="hidden" name="academy_csrf" value="<?= $h($_SESSION['academy_csrf']) ?>">
                    <input type="hidden" name="admin_action" value="save_course"><input type="hidden" name="id" value="<?= (int)($course['id'] ?? 0) ?>">
                    <div class="academy-panel-head"><h2><?= $course ? 'Informations du parcours' : 'Créer un parcours' ?></h2><button class="finea-action-btn finea-action-btn--accent">Enregistrer</button></div>
                    <div class="academy-form-grid">
                        <label>Titre<input class="finea-input" name="title" required value="<?= $h($course['title'] ?? '') ?>" placeholder="Ex : Maîtriser les FEB"></label>
                        <label>Slug<input class="finea-input" name="slug" value="<?= $h($course['slug'] ?? '') ?>" placeholder="automatique si vide"></label>
                        <label>Module FINEA<input class="finea-input academy-search-input" name="module_slug" list="academy-modules" value="<?= $h($course['module_slug'] ?? '') ?>" placeholder="feb, tickets, rh..."></label>
                        <label>Audience<select class="finea-select academy-select-search" name="audience"><?php foreach(['utilisateur'=>'Utilisateur','technique'=>'Technique','mixte'=>'Mixte'] as $v=>$l): ?><option value="<?= $v ?>" <?= (($course['audience'] ?? 'utilisateur') === $v) ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label>
                        <label>Score requis<input class="finea-input" type="number" min="1" max="100" name="required_score" value="<?= (int)($course['required_score'] ?? 75) ?>"></label>
                        <label>Durée estimée<input class="finea-input" type="number" min="1" name="estimated_minutes" value="<?= (int)($course['estimated_minutes'] ?? 15) ?>"></label>
                        <label>Ordre<input class="finea-input" type="number" name="sort_order" value="<?= (int)($course['sort_order'] ?? 0) ?>"></label>
                        <label class="academy-toggle"><input type="checkbox" name="is_active" value="1" <?= !isset($course['is_active']) || (int)$course['is_active'] === 1 ? 'checked' : '' ?>> Parcours actif</label>
                        <label class="wide">Description<textarea class="finea-input" name="description" rows="4" placeholder="Objectifs, public concerné, résultat attendu..."><?= $h($course['description'] ?? '') ?></textarea></label>
                        <label class="wide">Permissions liées<textarea class="finea-input academy-token-input" name="permissions_text" rows="3" placeholder="Saisir ou coller une permission par ligne"><?= $h(implode("\n", $editor['permissions'] ?? [])) ?></textarea><small>Exemples : <?= $h(implode(', ', $permissionOptions)) ?></small></label>
                    </div>
                </form>

                <section id="lessons" class="academy-panel academy-form-card">
                    <div class="academy-panel-head"><h2>Leçons & médias</h2><span>Drag & drop image + aperçu vidéo</span></div>
                    <?php if (!empty($course['id'])): ?>
                    <form method="post" enctype="multipart/form-data" class="academy-form-grid"><input type="hidden" name="academy_csrf" value="<?= $h($_SESSION['academy_csrf']) ?>">
                        <input type="hidden" name="admin_action" value="save_lesson"><input type="hidden" name="course_id" value="<?= (int)$course['id'] ?>">
                        <label>Titre de la leçon<input class="finea-input" name="title" required placeholder="Ex : Ajouter les justificatifs FEB"></label>
                        <label>Type<select class="finea-select academy-select-search" name="lesson_type"><option value="lecture">Lecture</option><option value="video">Vidéo</option><option value="interactive">Interactif</option><option value="quiz">Quiz</option></select></label>
                        <label>Chemin Markdown<input class="finea-input" name="markdown_path" placeholder="docs/formation/..."></label>
                        <label>Ordre<input class="finea-input" type="number" name="sort_order" value="0"></label>
                        <label>Durée<input class="finea-input" type="number" name="estimated_minutes" value="5"></label>
                        <label class="academy-toggle"><input type="checkbox" name="is_active" value="1" checked> Active</label>
                        <label class="wide">Vidéo de démonstration (MP4 / WebM)<input type="file" name="lesson_video" accept="video/mp4,video/webm"><small>Maximum 500 Mo, selon la configuration PHP.</small></label><label class="wide">Ou lien vidéo<input class="finea-input academy-video-url" name="video_url" placeholder="YouTube, Vimeo, MP4..."></label>
                        <div class="wide academy-video-preview" data-empty="Aperçu vidéo automatique"></div>
                        <div class="wide academy-dropzone"><input type="file" name="lesson_image" accept="image/jpeg,image/png,image/webp"><div><strong>Glisser-déposer une capture ici</strong><span>ou cliquer pour choisir une image JPG, PNG ou WEBP</span></div><img alt="Aperçu image"></div>
                        <label>Nom de la capture<input class="finea-input" name="image_title" placeholder="Capture étape 1"></label>
                        <label>Légende<input class="finea-input" name="image_caption" placeholder="Explique ce que l’utilisateur doit comprendre"></label>
                        <button class="finea-action-btn finea-action-btn--primary wide">Ajouter la leçon</button>
                    </form>
                    <div class="academy-list-stack"><?php foreach (($editor['lessons'] ?? []) as $lesson): ?><article><strong><?= $h($lesson['title']) ?></strong><span><?= $h($lesson['lesson_type']) ?> · <?= (int)$lesson['estimated_minutes'] ?> min</span></article><?php endforeach; ?></div>
                    <?php else: ?><div class="academy-empty">Enregistre d’abord le parcours pour ajouter des leçons, captures et vidéos.</div><?php endif; ?>
                </section>
            </section>
            <?php if (!empty($course['id'])): ?><section id="requirements" class="academy-panel academy-form-card"><div class="academy-panel-head"><h2>Obligations progressives</h2><span>Recommandée, obligatoire ou bloquante ciblée</span></div><form method="post" class="academy-form-grid"><input type="hidden" name="academy_csrf" value="<?= $h($_SESSION['academy_csrf']) ?>"><input type="hidden" name="admin_action" value="save_requirement"><input type="hidden" name="course_id" value="<?= (int)$course['id'] ?>"><label>Module<input class="finea-input academy-search-input" list="academy-modules" name="module_slug" value="<?= $h($course['module_slug']) ?>"></label><label>Permission<input class="finea-input academy-search-input" list="academy-permissions" name="permission_key" placeholder="acces_decaissement"></label><label>Type<select class="finea-select academy-select-search" name="requirement_type"><option value="recommended">Recommandée</option><option value="mandatory">Obligatoire</option><option value="blocking">Bloquante ciblée</option></select></label><label>Action bloquée<input class="finea-input" name="blocking_action" placeholder="create_feb"></label><label>Score requis<input class="finea-input" type="number" name="required_score" value="<?= (int)$course['required_score'] ?>"></label><label>Délai jours<input class="finea-input" type="number" name="deadline_days" placeholder="7"></label><label class="academy-toggle"><input type="checkbox" name="is_active" value="1" checked> Active</label><button class="finea-action-btn finea-action-btn--accent">Ajouter la règle</button></form><div class="finea-table-wrap academy-table-wrap"><table class="finea-table"><thead><tr><th>Type</th><th>Permission</th><th>Action</th><th>Score</th><th>Délai</th></tr></thead><tbody><?php foreach (($editor['requirements'] ?? []) as $r): ?><tr><td><?= $h($r['requirement_type']) ?></td><td><?= $h($r['permission_key']) ?></td><td><?= $h($r['blocking_action']) ?></td><td><?= (int)$r['required_score'] ?>%</td><td><?= $h($r['deadline_days'] ?? '-') ?></td></tr><?php endforeach; ?></tbody></table></div></section><?php endif; ?>
        <?php elseif ($action === 'reports'): ?>
            <section class="academy-panel"><div class="academy-panel-head"><h2>Exports & rapports</h2><span>Rapports Excel brandés · CSV disponible dans le catalogue</span></div><div class="academy-export-grid"><a href="<?= $h($adminUrl . '?action=export&format=xlsx&type=courses') ?>"><strong>Parcours</strong><span>Catalogue, modules, règles, permissions</span></a><a href="<?= $h($adminUrl . '?action=export&format=xlsx&type=attempts') ?>"><strong>Tentatives QCM</strong><span>Scores, réussites, échecs</span></a><a href="<?= $h($adminUrl . '?action=export&format=xlsx&type=certificates') ?>"><strong>Certificats</strong><span>Validations et niveaux obtenus</span></a></div></section>
        <?php endif; ?>
    </main>
</div>
<datalist id="academy-modules"><?php foreach($moduleOptions as $m): ?><option value="<?= $h($m) ?>"><?php endforeach; ?><option value="feb"><option value="tickets"><option value="rh"><option value="crm"><option value="logistique"><option value="notifications"></datalist>
<datalist id="academy-permissions"><?php foreach($permissionOptions as $p): ?><option value="<?= $h($p) ?>"><?php endforeach; ?></datalist>
<script src="<?= $h($assetBase . '/formation/assets/academy-admin-v12.js') ?>"></script>
<link rel="stylesheet" href="<?= $h($assetBase . '/formation/assets/academy-theme.css') ?>"><script src="<?= $h($assetBase . '/formation/assets/academy-theme.js') ?>"></script>
<?php if ($hasPortalChrome) { require_once $projectRoot . '/decaissement/includes/footer.php'; } else { ?></body></html><?php } ?>
