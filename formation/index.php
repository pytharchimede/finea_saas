<?php
$projectRoot = dirname(__DIR__);

require_once $projectRoot . '/auth_check.php';
require_once __DIR__ . '/controllers/FormationController.php';
require_once __DIR__ . '/controllers/FormationAcademyController.php';
require_once __DIR__ . '/model/FormationPdfExporter.php';

$portalModuleManagerPath = $projectRoot . '/model/PortalModuleManager.php';
if (is_file($portalModuleManagerPath)) {
    require_once $portalModuleManagerPath;
}

$formationController = new \Formation\Controllers\FormationController($projectRoot);
extract($formationController->moduleContext(), EXTR_SKIP);
$appBasePath = rtrim(\AccessManager::getApplicationBasePath(), '/');
$formationHomeUrl = ($appBasePath === '' ? '' : $appBasePath) . '/formation/index.php';

$permissions = is_array($_SESSION['permissions'] ?? null) ? $_SESSION['permissions'] : [];
$canAccessFormation = !empty($permissions['acces_formation']) || !empty($permissions['formation_admin']) || !empty($permissions['acces_admin']);
$canAccessTechnical = !empty($permissions['formation_admin']) || !empty($permissions['acces_admin']);
$canUseCaptureMode = $canAccessTechnical;

$currentUserId = (int) ($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
$isFormationAdmin = !empty($permissions['formation_admin']) || !empty($permissions['acces_admin']);
$academyController = null;
$academyDashboard = null;
$academyCourseData = null;
$academyQuizQuestions = [];
$academyFlash = $_SESSION['formation_academy_flash'] ?? null;
unset($_SESSION['formation_academy_flash']);
$academyMode = trim((string) ($_GET['academy'] ?? (isset($_GET['module']) || isset($_GET['doc']) || isset($_GET['capture_mode']) || isset($_GET['edit']) || isset($_GET['export']) || isset($_GET['download']) ? '' : 'dashboard')));
if (!in_array($academyMode, ['', 'dashboard', 'catalog', 'progress', 'course'], true)) $academyMode = 'dashboard';
$academyCourseSlug = trim((string) ($_GET['course'] ?? ''));

try {
    if (class_exists('\\Database')) {
        $academyPdo = \Database::getConnection();
        if ($academyPdo instanceof \PDO) {
            $academyController = new \Formation\Controllers\FormationAcademyController($academyPdo);
            $academyDashboard = $academyController->dashboard($currentUserId, $permissions, $isFormationAdmin);
        }
    }
} catch (\Throwable $academyError) {
    $academyDashboard = [
        'courses' => [],
        'stats' => ['score' => 0, 'level' => 'Indisponible', 'total_courses' => 0],
        'error' => $academyError->getMessage(),
    ];
}

$_SESSION['academy_csrf'] ??= bin2hex(random_bytes(32));
$allowedCourseIds = array_map('intval', array_column($academyDashboard['courses'] ?? [], 'id'));
if (!$canAccessFormation || !$currentUserId) { http_response_code(403); exit('Accès refusé'); }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['formation_academy_action'] ?? '') === 'complete_lesson') {
    if (!hash_equals($_SESSION['academy_csrf'], (string)($_POST['academy_csrf']??'')) || !in_array((int)($_POST['course_id']??0),$allowedCourseIds,true)) {http_response_code(403);exit;}
    $q=$academyPdo->prepare('SELECT id FROM formation_lessons WHERE id=? AND course_id=? AND is_active=1');$q->execute([(int)$_POST['lesson_id'],(int)$_POST['course_id']]);
    if (!$q->fetchColumn()) {http_response_code(404);exit;}
    $academyController->markLessonCompleted($currentUserId,(int)$_POST['course_id'],(int)$_POST['lesson_id'],0);
    header('Location: '.formationBuildUrl(['academy'=>'course','course'=>(string)$_POST['course_slug']]).'#lesson-'.(int)$_POST['lesson_id']);exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['formation_academy_action'] ?? '') === 'submit_quiz' && $academyController !== null) {
    if (!hash_equals($_SESSION['academy_csrf'], (string)($_POST['academy_csrf']??'')) || !in_array((int)($_POST['course_id']??0),$allowedCourseIds,true)) {http_response_code(403);exit;}
    $postedCourseSlug = trim((string) ($_POST['course_slug'] ?? ''));
    try {
        $result = $academyController->submitQuiz(
            $currentUserId,
            (int) ($_POST['course_id'] ?? 0),
            (int) ($_POST['quiz_id'] ?? 0),
            $_POST
        );
        $_SESSION['formation_academy_flash'] = [
            'type' => !empty($result['passed']) ? 'success' : 'warning',
            'message' => !empty($result['passed'])
                ? 'Parcours validé. Certificat généré si aucun certificat existant n’était déjà disponible.'
                : 'Test terminé, mais le score est insuffisant. Relisez le parcours puis réessayez.',
            'score' => $result['score'] ?? 0,
            'level' => $result['level'] ?? 'Débutant',
        ];
    } catch (\Throwable $quizError) {
        $_SESSION['formation_academy_flash'] = [
            'type' => 'danger',
            'message' => 'Impossible de corriger le QCM : ' . $quizError->getMessage(),
        ];
    }

    header('Location: ' . formationBuildUrl(['academy' => 'course', 'course' => $postedCourseSlug]));
    exit;
}

if ($academyMode === 'course' && $academyCourseSlug !== '' && $academyController !== null) {
    $academyCourseData = $academyController->course($academyCourseSlug);
    if (!in_array((int)($academyCourseData['course']['id']??0),$allowedCourseIds,true)) {http_response_code(403);exit;}
    if (is_array($academyCourseData) && !empty($academyCourseData['quiz']['id'])) {
        $academyQuizQuestions = $academyController->quiz((int) $academyCourseData['quiz']['id']);
    }
}


if (!$canAccessFormation) {
    header('Location: ' . ($appBasePath === '' ? '' : $appBasePath) . '/acces_refuse.php?type=formation&page=formation/index.php');
    exit;
}

$pageTitle = 'Formation';
$activeMenu = 'formation';

function formationModuleLabel(string $slug): string
{
    $knownLabels = [
        'presentation_finea' => 'Presentation Finéa',
        'espace_employe' => 'Espace employe',
        'point_financier' => 'Point financier',
        'documentation_archives' => 'Documentation & Archives',
        'administration_systeme' => 'Administration systeme',
        'emploi_du_temps' => 'Emploi du temps',
    ];

    if (isset($knownLabels[$slug])) {
        return $knownLabels[$slug];
    }

    return formationHumanizeSlug($slug);
}


function formationNormalizeMatchKey(string $value): string
{
    $value = trim(strtolower($value));
    $value = str_replace(['module_', '.php'], ['', ''], $value);
    $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?: $value;
    return trim($value, '_');
}

function formationUserHasAnyPermission(array $sessionPermissions, array $requiredPermissions): bool
{
    if (!empty($sessionPermissions['acces_admin'])) {
        return true;
    }
    if ($requiredPermissions === []) {
        return true;
    }
    foreach ($requiredPermissions as $permission) {
        $permission = trim((string) $permission);
        if ($permission !== '' && !empty($sessionPermissions[$permission])) {
            return true;
        }
    }
    return false;
}

function formationPortalModuleMatchesSlug(array $portalModule, string $moduleSlug): bool
{
    $wanted = formationNormalizeMatchKey($moduleSlug);
    if ($wanted === '') {
        return false;
    }

    $candidates = [
        (string)($portalModule['slug'] ?? ''),
        (string)($portalModule['module_key'] ?? ''),
        (string)($portalModule['target'] ?? ''),
        (string)($portalModule['label'] ?? ''),
    ];

    $aliases = [
        'tickets' => ['ticketing', 'centre_de_services', 'centre_services'],
        'ticketing' => ['tickets', 'centre_de_services', 'centre_services'],
        'decaissement' => ['liste_fiches_approbation', 'validation_feb', 'feb'],
        'feb' => ['decaissement', 'validation_feb'],
        'rh' => ['gestion_des_ressources_humaines', 'dashboard_rh'],
        'documentation_archives' => ['documentation', 'documentation_archives'],
        'formation' => ['presentation_finea', 'centre_de_guides_applicatifs_finea'],
        'presentation_finea' => ['formation'],
        'besoins_recurrents' => ['recurring_needs'],
        'recurring_needs' => ['besoins_recurrents'],
        'logistique' => ['dashboard_logistique', 'gestion_logistique'],
        'planning_chantier' => ['planning'],
    ];
    $wantedSet = array_merge([$wanted], $aliases[$wanted] ?? []);

    foreach ($candidates as $candidate) {
        $normalized = formationNormalizeMatchKey($candidate);
        if ($normalized === '') {
            continue;
        }
        foreach ($wantedSet as $expected) {
            if ($normalized === $expected || str_contains($normalized, $expected) || str_contains($expected, $normalized)) {
                return true;
            }
        }
    }

    return false;
}

function formationFindPortalModuleForSlug(string $moduleSlug, array $accessiblePortalModules, array $allPortalModules): ?array
{
    foreach ($accessiblePortalModules as $portalModule) {
        if (formationPortalModuleMatchesSlug($portalModule, $moduleSlug)) {
            return $portalModule;
        }
    }
    foreach ($allPortalModules as $portalModule) {
        if (formationPortalModuleMatchesSlug($portalModule, $moduleSlug)) {
            return null;
        }
    }

    if (in_array($moduleSlug, ['presentation_finea', 'notifications', 'notifications_popup'], true)) {
        foreach ($accessiblePortalModules as $portalModule) {
            if (formationPortalModuleMatchesSlug($portalModule, 'formation')) {
                return $portalModule;
            }
        }
    }

    return null;
}

function formationCompareModuleOrder(string $left, string $right): int
{
    static $preferredOrder = [
        'presentation_finea',
    ];

    $leftIndex = array_search($left, $preferredOrder, true);
    $rightIndex = array_search($right, $preferredOrder, true);

    if ($leftIndex !== false && $rightIndex !== false) {
        return $leftIndex <=> $rightIndex;
    }
    if ($leftIndex !== false) {
        return -1;
    }
    if ($rightIndex !== false) {
        return 1;
    }

    return strcmp(formationModuleLabel($left), formationModuleLabel($right));
}

function formationBuildUrl(array $params = []): string
{
    global $formationHomeUrl;

    $query = array_filter($params, static function ($value): bool {
        return $value !== null && $value !== '';
    });

    return $formationHomeUrl . ($query ? ('?' . http_build_query($query)) : '');
}

function formationHumanizeSlug(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return 'Module';
    }

    $value = str_replace(['_', '-'], ' ', $value);
    $value = preg_replace('/\s+/', ' ', $value) ?: $value;

    return mb_convert_case($value, MB_CASE_TITLE, 'UTF-8');
}

function formationDocTitleFromFile(string $fileName): string
{
    $stem = pathinfo($fileName, PATHINFO_FILENAME);
    return formationHumanizeSlug($stem);
}

function formationSlugify(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return 'capture';
    }

    $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?: $value;
    $value = trim($value, '-');

    return $value !== '' ? $value : 'capture';
}

function formationRenderInline(string $text): string
{
    $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    $escaped = preg_replace('/`([^`]+)`/', '<code class="formation-inline-keyword">$1</code>', $escaped) ?? $escaped;
    $escaped = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $escaped) ?? $escaped;
    return preg_replace('/\*([^*]+)\*/', '<em>$1</em>', $escaped) ?? $escaped;
}

function formationApplyPlaceholders(string $content, string $appName, string $captureCollaborator, string $captureCollaboratorEmail, string $brandSlogan): string
{
    return str_replace(
        ['{{APP_NAME}}', '{{COLLABORATEUR}}', '{{COLLABORATEUR_EMAIL}}', '{{APP_SLOGAN}}'],
        [
            $appName,
            $captureCollaborator !== '' ? $captureCollaborator : 'le collaborateur',
            $captureCollaboratorEmail !== '' ? $captureCollaboratorEmail : 'email à préciser',
            $brandSlogan,
        ],
        $content
    );
}

function formationResolveAssetUrl(string $source, string $baseDir = ''): string
{
    global $appBasePath, $projectRoot;

    $source = trim($source);
    if ($source === '') {
        return '';
    }

    if (preg_match('~^https?://~i', $source)) {
        return $source;
    }

    if ($source[0] === '/') {
        return ($appBasePath === '' ? '' : $appBasePath) . '/' . ltrim($source, '/');
    }

    $candidate = $baseDir !== '' ? $baseDir . DIRECTORY_SEPARATOR . $source : $projectRoot . DIRECTORY_SEPARATOR . $source;
    $resolved = realpath($candidate);
    if ($resolved === false) {
        return '';
    }

    $projectRoot = str_replace('\\', '/', realpath($projectRoot) ?: $projectRoot);
    $normalizedResolved = str_replace('\\', '/', $resolved);
    if (strpos($normalizedResolved, $projectRoot) !== 0) {
        return '';
    }

    return ($appBasePath === '' ? '' : $appBasePath) . '/' . ltrim(substr($normalizedResolved, strlen($projectRoot)), '/');
}

function formationCreateCaptureUploadFlash(string $type, string $message, array $data = []): void
{
    $_SESSION['formation_capture_upload_flash'] = [
        'type' => $type,
        'message' => $message,
        'data' => $data,
    ];
}

function formationConsumeCaptureUploadFlash(): ?array
{
    $flash = $_SESSION['formation_capture_upload_flash'] ?? null;
    unset($_SESSION['formation_capture_upload_flash']);

    return is_array($flash) ? $flash : null;
}

function formationBuildCaptureSnippet(string $path, string $alt, string $caption): string
{
    $path = trim($path);
    $alt = trim($alt);
    $caption = trim($caption);

    return '![' . $alt . '](' . $path . ($caption !== '' ? ' "' . $caption . '"' : '') . ')';
}
function formationParseMarkdownImage(string $line): ?array
{
    $line = trim($line);
    if ($line === '' || !preg_match('/^!\[(.*?)\]\((.+)\)$/', $line, $matches)) {
        return null;
    }

    $alt = trim((string) $matches[1]);
    $inner = trim((string) $matches[2]);
    $source = '';
    $title = '';

    if ($inner !== '' && $inner[0] === '<') {
        $closingPos = strrpos($inner, '>');
        if ($closingPos === false) {
            return null;
        }

        $source = substr($inner, 1, $closingPos - 1);
        $rest = trim(substr($inner, $closingPos + 1));
        if ($rest !== '') {
            if (!preg_match('/^"([^"]*)"$/', $rest, $titleMatches)) {
                return null;
            }
            $title = trim((string) $titleMatches[1]);
        }
    } else {
        if (!preg_match('/^(.*?)(?:\s+"([^"]*)")?$/', $inner, $innerMatches)) {
            return null;
        }

        $source = trim((string) ($innerMatches[1] ?? ''));
        $title = trim((string) ($innerMatches[2] ?? ''));
    }

    $source = trim($source, " \t\n\r\0\x0B<>");
    if ($source === '') {
        return null;
    }

    return [
        'alt' => $alt,
        'src' => $source,
        'title' => $title,
    ];
}

function formationNormalizeSearchValue(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
    $value = strtolower($value);
    $value = preg_replace('/\s+/', ' ', $value) ?: $value;

    return trim($value);
}

function formationExtractMarkdownHeadings(string $markdown): array
{
    $lines = preg_split('/\r\n|\r|\n/', $markdown) ?: [];
    $headings = [];

    foreach ($lines as $line) {
        if (!preg_match('/^(#{1,6})\s+(.*)$/', trim($line), $matches)) {
            continue;
        }

        $title = trim((string) $matches[2]);
        if ($title === '') {
            continue;
        }

        $headings[] = [
            'level' => strlen((string) $matches[1]),
            'title' => $title,
            'normalized' => formationNormalizeSearchValue($title),
        ];
    }

    return $headings;
}

function formationInsertSnippetIntoMarkdown(string $content, string $snippet, string $targetMode, string $targetValue): array
{
    $trimmedContent = rtrim($content);
    $snippetBlock = "\n\n" . $snippet . "\n";

    if ($targetMode === 'after_heading' || $targetMode === 'before_heading') {
        $normalizedTarget = formationNormalizeSearchValue($targetValue);
        if ($normalizedTarget === '') {
            return [
                'success' => false,
                'content' => $content,
                'message' => 'Sélectionnez le titre autour duquel insérer la capture.',
            ];
        }

        $lines = preg_split('/\r\n|\r|\n/', $trimmedContent) ?: [];
        foreach ($lines as $index => $line) {
            if (!preg_match('/^(#{1,6})\s+(.*)$/', trim($line), $matches)) {
                continue;
            }
            if (formationNormalizeSearchValue((string) $matches[2]) !== $normalizedTarget) {
                continue;
            }

            $insertIndex = $targetMode === 'before_heading' ? $index : ($index + 1);
            array_splice($lines, $insertIndex, 0, ['', $snippet, '']);
            return [
                'success' => true,
                'content' => rtrim(implode("\n", $lines)) . "\n",
                'message' => $targetMode === 'before_heading'
                    ? 'Bloc Markdown inséré avant le titre demandé.'
                    : 'Bloc Markdown inséré après le titre demandé.',
            ];
        }

        return [
            'success' => false,
            'content' => $content,
            'message' => 'Aucun titre correspondant n a été trouvé dans le guide.',
        ];
    }

    if ($targetMode === 'after_marker') {
        $normalizedTarget = formationNormalizeSearchValue($targetValue);
        if ($normalizedTarget === '') {
            return [
                'success' => false,
                'content' => $content,
                'message' => 'Renseignez le repère texte après lequel insérer la capture.',
            ];
        }

        $lines = preg_split('/\r\n|\r|\n/', $trimmedContent) ?: [];
        foreach ($lines as $index => $line) {
            if (strpos(formationNormalizeSearchValue($line), $normalizedTarget) === false) {
                continue;
            }

            array_splice($lines, $index + 1, 0, ['', $snippet, '']);
            return [
                'success' => true,
                'content' => rtrim(implode("\n", $lines)) . "\n",
                'message' => 'Bloc Markdown inséré après le repère demandé.',
            ];
        }

        return [
            'success' => false,
            'content' => $content,
            'message' => 'Aucun repère correspondant n a été trouvé dans le guide.',
        ];
    }

    return [
        'success' => true,
        'content' => $trimmedContent . $snippetBlock,
        'message' => 'Bloc Markdown inséré automatiquement à la fin du guide.',
    ];
}

function formationRenderMarkdown(string $markdown, string $baseDir = ''): string
{
    $lines = preg_split('/\r\n|\r|\n/', $markdown) ?: [];
    $html = [];
    $inUl = false;
    $inOl = false;
    $inCode = false;
    $paragraph = [];

    $flushParagraph = static function () use (&$paragraph, &$html): void {
        if ($paragraph === []) {
            return;
        }
        $text = trim(implode(' ', $paragraph));
        if ($text !== '') {
            $html[] = '<p>' . formationRenderInline($text) . '</p>';
        }
        $paragraph = [];
    };

    $closeLists = static function () use (&$inUl, &$inOl, &$html): void {
        if ($inUl) {
            $html[] = '</ul>';
            $inUl = false;
        }
        if ($inOl) {
            $html[] = '</ol>';
            $inOl = false;
        }
    };

    foreach ($lines as $line) {
        $trimmed = trim($line);

        if (preg_match('/^```/', $trimmed)) {
            $flushParagraph();
            $closeLists();
            if (!$inCode) {
                $html[] = '<pre class="overflow-x-auto rounded-2xl bg-slate-950 p-4 text-sm text-slate-100"><code>';
                $inCode = true;
            } else {
                $html[] = '</code></pre>';
                $inCode = false;
            }
            continue;
        }

        if ($inCode) {
            $html[] = htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . "\n";
            continue;
        }

        if ($trimmed === '') {
            $flushParagraph();
            $closeLists();
            continue;
        }

        if (preg_match('/^(#{1,3})\s+(.*)$/', $trimmed, $matches)) {
            $flushParagraph();
            $closeLists();
            $level = strlen($matches[1]) + 1;
            $level = min($level, 4);
            $html[] = '<h' . $level . '>' . formationRenderInline($matches[2]) . '</h' . $level . '>';
            continue;
        }

        $imageData = formationParseMarkdownImage($trimmed);
        if ($imageData !== null) {
            $flushParagraph();
            $closeLists();

            $alt = trim((string) $imageData['alt']);
            $caption = trim((string) $imageData['title']);
            if ($caption === '') {
                $caption = $alt;
            }

            $assetUrl = formationResolveAssetUrl((string) $imageData['src'], $baseDir);
            if ($assetUrl !== '') {
                $html[] = '<figure class="formation-figure">'
                    . '<img src="' . htmlspecialchars($assetUrl, ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars($alt, ENT_QUOTES, 'UTF-8') . '" class="formation-figure__image">'
                    . ($caption !== '' ? '<figcaption class="formation-figure__caption">' . htmlspecialchars($caption, ENT_QUOTES, 'UTF-8') . '</figcaption>' : '')
                    . '</figure>';
            }
            continue;
        }

        if (preg_match('/^[-*]\s+(.*)$/', $trimmed, $matches)) {
            $flushParagraph();
            if ($inOl) {
                $html[] = '</ol>';
                $inOl = false;
            }
            if (!$inUl) {
                $html[] = '<ul>';
                $inUl = true;
            }
            $html[] = '<li>' . formationRenderInline($matches[1]) . '</li>';
            continue;
        }

        if (preg_match('/^\d+\.\s+(.*)$/', $trimmed, $matches)) {
            $flushParagraph();
            if ($inUl) {
                $html[] = '</ul>';
                $inUl = false;
            }
            if (!$inOl) {
                $html[] = '<ol>';
                $inOl = true;
            }
            $html[] = '<li>' . formationRenderInline($matches[1]) . '</li>';
            continue;
        }

        $paragraph[] = $trimmed;
    }

    $flushParagraph();
    $closeLists();

    if ($inCode) {
        $html[] = '</code></pre>';
    }

    return implode("\n", $html);
}

$defaultCaptureCollaborator = '';
$defaultCaptureCollaboratorEmail = '';
$captureMode = $canUseCaptureMode && !empty($_GET['capture_mode']);
$captureCollaborator = trim((string)($_GET['collaborateur'] ?? $defaultCaptureCollaborator));
$captureCollaboratorEmail = trim((string)($_GET['collaborateur_email'] ?? $defaultCaptureCollaboratorEmail));
$appName = 'Finéa';
$brandSlogan = 'Finéa, pilotez votre entreprise avec fluidité, maîtrise et performance.';
$brandSignature = 'Finance + innovation + fluidité';
require_once $projectRoot . '/model/CompanyBranding.php';
$academyBrand = \CompanyBranding::get();
$appLogoPath = \CompanyBranding::logoFile();
$appIconPath = \CompanyBranding::iconFile();
$appLogoUrl = \CompanyBranding::logoUrl($appBasePath . '/') ?: null;
$appIconUrl = \CompanyBranding::iconUrl($appBasePath . '/') ?: null;
$captureNotes = [
    'presentation_finea' => [
        'utilisateur' => [
            'rh_gestionnaire' => [
                'Portail de selection des modules au nom Finéa.',
                'Entree dans le module Formation.',
                'Vue du module de presentation generale ouvert.',
                'Exemple de navigation vers un module metier depuis le portail.',
            ],
            'employe' => [
                'Connexion puis arrivee sur le portail.',
                'Vue d ensemble des principaux modules visibles.',
                'Ouverture du module Formation.',
                'Lecture d un premier guide dans Finéa.',
            ],
        ],
        'technique' => [
            'reprise_it' => [
                'Capturer le portail et l entree Formation avec un compte representatif.',
                'Montrer le module de presentation en premier dans la liste.',
                'Illustrer le contexte collaborateur utilise pour les captures.',
                'Eviter toute donnee sensible visible sur les captures.',
            ],
            'guide_developpement' => [
                'Verifier la conservation du parametre collaborateur dans les URLs.',
                'Capturer la nav droite avec le panneau capture visible.',
                'Illustrer le mode capture active sur un document Finéa.',
                'Conserver le meme collaborateur de reference sur tout le module.',
            ],
        ],
    ],
];

$portalModules = [];
$accessiblePortalModules = [];
$portalModuleFallback = [
    'icon' => 'fa-graduation-cap',
    'color' => '#1d4ed8',
    'subtitle' => 'Guides et parcours FINEA Academy',
];
try {
    if (class_exists('PortalModuleManager') && class_exists('\Database')) {
        $portalPdo = \Database::getConnection();
        if ($portalPdo instanceof \PDO) {
            $portalManager = new \PortalModuleManager($portalPdo);
            $portalModules = $portalManager->listActive();
            foreach ($portalModules as $portalModule) {
                if (formationUserHasAnyPermission($permissions, (array)($portalModule['required_permissions'] ?? []))) {
                    $accessiblePortalModules[] = $portalModule;
                }
            }
        }
    }
} catch (\Throwable $portalModuleError) {
    $portalModules = [];
    $accessiblePortalModules = [];
}

$moduleCatalog = [];
if (is_dir($docsRoot)) {
    $moduleDirs = scandir($docsRoot) ?: [];
    foreach ($moduleDirs as $moduleDir) {
        if ($moduleDir === '.' || $moduleDir === '..') {
            continue;
        }

        $modulePath = $docsRoot . DIRECTORY_SEPARATOR . $moduleDir;
        if (!is_dir($modulePath)) {
            continue;
        }

        $portalMeta = formationFindPortalModuleForSlug($moduleDir, $accessiblePortalModules, $portalModules);
        if ($portalModules !== [] && $portalMeta === null && !$isFormationAdmin) {
            continue;
        }

        $moduleData = [
            'slug' => $moduleDir,
            'label' => (string)($portalMeta['label'] ?? formationModuleLabel($moduleDir)),
            'icon' => (string)($portalMeta['icon'] ?? $portalModuleFallback['icon']),
            'color' => (string)($portalMeta['color'] ?? $portalModuleFallback['color']),
            'subtitle' => (string)($portalMeta['subtitle'] ?? $portalModuleFallback['subtitle']),
            'utilisateur' => [],
            'technique' => [],
        ];

        foreach (['utilisateur', 'technique'] as $audience) {
            if ($audience === 'technique' && !$canAccessTechnical) {
                continue;
            }

            $audiencePath = $modulePath . DIRECTORY_SEPARATOR . $audience;
            if (!is_dir($audiencePath)) {
                continue;
            }

            $files = scandir($audiencePath) ?: [];
            foreach ($files as $fileName) {
                if ($fileName === '.' || $fileName === '..') {
                    continue;
                }
                if (strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) !== 'md') {
                    continue;
                }

                $moduleData[$audience][] = [
                    'slug' => pathinfo($fileName, PATHINFO_FILENAME),
                    'file' => $fileName,
                    'path' => $audiencePath . DIRECTORY_SEPARATOR . $fileName,
                    'title' => formationDocTitleFromFile($fileName),
                ];
            }

            usort($moduleData[$audience], static function (array $left, array $right): int {
                return strcmp($left['title'], $right['title']);
            });
        }

        $moduleCatalog[$moduleDir] = $moduleData;
    }
}

uksort($moduleCatalog, 'formationCompareModuleOrder');

$moduleKeys = array_keys($moduleCatalog);
$currentModule = trim((string)($_GET['module'] ?? ($moduleKeys[0] ?? '')));
if ($currentModule === '' || !isset($moduleCatalog[$currentModule])) {
    $currentModule = $moduleKeys[0] ?? '';
}

$availableAudiences = ['utilisateur'];
if ($canAccessTechnical) {
    $availableAudiences[] = 'technique';
}

$currentAudience = trim((string)($_GET['audience'] ?? 'utilisateur'));
if (!in_array($currentAudience, $availableAudiences, true)) {
    $currentAudience = $availableAudiences[0] ?? 'utilisateur';
}

$currentDocs = $currentModule !== '' ? ($moduleCatalog[$currentModule][$currentAudience] ?? []) : [];
$currentDocSlug = trim((string)($_GET['doc'] ?? ($currentDocs[0]['slug'] ?? '')));
$currentDoc = null;
foreach ($currentDocs as $docRow) {
    if ($docRow['slug'] === $currentDocSlug) {
        $currentDoc = $docRow;
        break;
    }
}
if ($currentDoc === null && !empty($currentDocs)) {
    $currentDoc = $currentDocs[0];
    $currentDocSlug = $currentDoc['slug'];
}

$currentModuleLabel = $currentModule !== '' && isset($moduleCatalog[$currentModule])
    ? (string) $moduleCatalog[$currentModule]['label']
    : 'Formation';
$currentAudienceLabel = $currentAudience === 'technique' ? 'Documentation technique' : 'Guides utilisateurs';

$exportMode = trim((string)($_GET['export'] ?? ''));
if ($exportMode !== '') {
    $exporterContext = [
        'project_root' => $projectRoot,
        'app_base_path' => $appBasePath,
        'app_name' => (string)$academyBrand['company_name'] . ' — FINEA Academy',
        'brand_slogan' => $brandSlogan,
        'brand_signature' => $brandSignature,
        'logo_path' => $appLogoPath,
        'icon_path' => $appIconPath,
        'module_slug' => $currentModule,
        'module_label' => $currentModuleLabel,
        'audience_slug' => $currentAudience,
        'audience_label' => $currentAudienceLabel,
        'capture_collaborator' => $captureMode ? $captureCollaborator : '',
        'capture_email' => $captureMode ? $captureCollaboratorEmail : '',
    ];
    $pdfExporter = new \FormationPdfExporter($exporterContext);

    if ($exportMode === 'pdf_doc' && $currentDoc !== null && is_file($currentDoc['path'])) {
        $pdfExporter->outputCurrentDocument([
            'slug' => $currentDoc['slug'],
            'title' => $currentDoc['title'],
            'path' => $currentDoc['path'],
            'content' => formationApplyPlaceholders((string) file_get_contents($currentDoc['path']), $appName, $captureCollaborator, $captureCollaboratorEmail, $brandSlogan),
        ]);
        exit;
    }

    if ($exportMode === 'pdf_module' && $currentModule !== '' && isset($moduleCatalog[$currentModule])) {
        $bundleDocuments = [];
        foreach ($currentDocs as $docRow) {
            if (!is_file($docRow['path'])) {
                continue;
            }
            $bundleDocuments[] = [
                'slug' => $docRow['slug'],
                'title' => $docRow['title'],
                'path' => $docRow['path'],
                'content' => formationApplyPlaceholders((string) file_get_contents($docRow['path']), $appName, $captureCollaborator, $captureCollaboratorEmail, $brandSlogan),
            ];
        }

        if ($bundleDocuments !== []) {
            $pdfExporter->outputModuleBundle($bundleDocuments);
            exit;
        }
    }
}

if (!empty($_GET['download']) && $currentDoc !== null && is_file($currentDoc['path'])) {
    header('Content-Type: text/markdown; charset=utf-8');
    header('Content-Disposition: inline; filename="' . basename($currentDoc['path']) . '"');
    $downloadContent = formationApplyPlaceholders((string)file_get_contents($currentDoc['path']), $appName, $captureCollaborator, $captureCollaboratorEmail, $brandSlogan);
    echo $downloadContent;
    exit;
}

$currentDocContent = '';
$currentDocRawContent = '';
if ($currentDoc !== null && is_file($currentDoc['path'])) {
    $currentDocRawContent = (string) file_get_contents($currentDoc['path']);
    $currentDocContent = formationApplyPlaceholders($currentDocRawContent, $appName, $captureCollaborator, $captureCollaboratorEmail, $brandSlogan);
}

$currentDocBaseDir = $currentDoc !== null && isset($currentDoc['path']) ? dirname((string) $currentDoc['path']) : '';
$currentDocHeadings = $currentDocRawContent !== '' ? formationExtractMarkdownHeadings($currentDocRawContent) : [];
$editMode = $canAccessTechnical && !empty($_GET['edit']);
$formationEditFlash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canAccessTechnical && (string) ($_POST['formation_action'] ?? '') === 'save_markdown_document') {
    $redirectParams = [
        'module' => $currentModule,
        'audience' => $currentAudience,
        'doc' => $currentDoc['slug'] ?? null,
        'collaborateur' => $captureCollaborator,
        'collaborateur_email' => $captureCollaboratorEmail,
        'capture_mode' => $captureMode ? 1 : null,
        'edit' => 1,
    ];

    if ($currentDoc === null || empty($currentDoc['path'])) {
        $_SESSION['formation_edit_flash'] = ['type' => 'error', 'message' => 'Aucun guide valide n est sélectionné pour la modification.'];
        header('Location: ' . formationBuildUrl($redirectParams));
        exit;
    }

    $newContent = (string) ($_POST['markdown_content'] ?? '');
    if ($formationController->saveMarkdownDocument((string) $currentDoc['path'], $newContent)) {
        $_SESSION['formation_edit_flash'] = ['type' => 'success', 'message' => 'Guide mis à jour. Les captures et exports utilisent maintenant cette version.'];
        $redirectParams['edit'] = null;
    } else {
        $_SESSION['formation_edit_flash'] = ['type' => 'error', 'message' => 'Impossible d enregistrer ce guide. Vérifiez les droits du fichier.'];
    }

    header('Location: ' . formationBuildUrl($redirectParams));
    exit;
}

if (isset($_SESSION['formation_edit_flash']) && is_array($_SESSION['formation_edit_flash'])) {
    $formationEditFlash = $_SESSION['formation_edit_flash'];
    unset($_SESSION['formation_edit_flash']);
}
$captureSnippetDefaultPath = $currentDoc !== null ? 'captures/' . $currentDoc['slug'] . '.png' : 'captures/ma-capture.png';
$captureSnippetDefaultAlt = $currentDoc !== null ? 'Vue ' . $currentDoc['title'] : 'Vue Finéa';
$captureSnippetDefaultCaption = $currentDoc !== null ? 'Capture de ' . $currentDoc['title'] : 'Capture de l interface Finéa';
$captureInsertTargetMode = 'end';
$captureInsertTargetValue = '';
$captureSnippetPreviewUrl = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canUseCaptureMode && $captureMode && (string) ($_POST['formation_action'] ?? '') === 'upload_capture_image') {
    $redirectParams = [
        'module' => $currentModule,
        'audience' => $currentAudience,
        'doc' => $currentDoc['slug'] ?? null,
        'collaborateur' => $captureCollaborator,
        'collaborateur_email' => $captureCollaboratorEmail,
        'capture_mode' => 1,
    ];

    if ($currentDoc === null || $currentDocBaseDir === '' || !is_dir($currentDocBaseDir)) {
        formationCreateCaptureUploadFlash('error', 'Aucun guide valide n est sélectionné pour recevoir une capture.');
        header('Location: ' . formationBuildUrl($redirectParams));
        exit;
    }

    $upload = $_FILES['capture_image_file'] ?? null;
    if (!is_array($upload) || (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        formationCreateCaptureUploadFlash('error', 'Sélectionnez une image à envoyer depuis votre poste.');
        header('Location: ' . formationBuildUrl($redirectParams));
        exit;
    }

    if ((int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        formationCreateCaptureUploadFlash('error', 'L envoi de l image a échoué avant le traitement serveur.');
        header('Location: ' . formationBuildUrl($redirectParams));
        exit;
    }

    $tmpPath = (string) ($upload['tmp_name'] ?? '');
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        formationCreateCaptureUploadFlash('error', 'Le fichier reçu n est pas reconnu comme un upload valide.');
        header('Location: ' . formationBuildUrl($redirectParams));
        exit;
    }

    $imageInfo = @getimagesize($tmpPath);
    $allowedMimeToExt = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];
    $mimeType = is_array($imageInfo) ? (string) ($imageInfo['mime'] ?? '') : '';
    if (!isset($allowedMimeToExt[$mimeType])) {
        formationCreateCaptureUploadFlash('error', 'Formats autorisés : JPG, PNG, WEBP ou GIF.');
        header('Location: ' . formationBuildUrl($redirectParams));
        exit;
    }

    $maxUploadBytes = 8 * 1024 * 1024;
    if ((int) ($upload['size'] ?? 0) > $maxUploadBytes) {
        formationCreateCaptureUploadFlash('error', 'L image dépasse la taille maximale autorisée de 8 Mo.');
        header('Location: ' . formationBuildUrl($redirectParams));
        exit;
    }

    $capturesDir = $currentDocBaseDir . DIRECTORY_SEPARATOR . 'captures';
    if (!is_dir($capturesDir) && !mkdir($capturesDir, 0775, true) && !is_dir($capturesDir)) {
        formationCreateCaptureUploadFlash('error', 'Impossible de créer le dossier captures pour ce guide.');
        header('Location: ' . formationBuildUrl($redirectParams));
        exit;
    }

    $desiredBaseName = trim((string) ($_POST['capture_image_name'] ?? ''));
    $altText = trim((string) ($_POST['capture_image_alt'] ?? ''));
    $captionText = trim((string) ($_POST['capture_image_caption'] ?? ''));
    $baseName = formationSlugify($desiredBaseName !== '' ? $desiredBaseName : ($altText !== '' ? $altText : (($currentDoc['slug'] ?? 'capture') . '-' . date('Ymd-His'))));
    $extension = $allowedMimeToExt[$mimeType];
    $finalFileName = $baseName . '.' . $extension;
    $targetPath = $capturesDir . DIRECTORY_SEPARATOR . $finalFileName;
    $suffix = 1;
    while (is_file($targetPath)) {
        $finalFileName = $baseName . '-' . $suffix . '.' . $extension;
        $targetPath = $capturesDir . DIRECTORY_SEPARATOR . $finalFileName;
        $suffix++;
    }

    if (!move_uploaded_file($tmpPath, $targetPath)) {
        formationCreateCaptureUploadFlash('error', 'Le serveur n a pas pu enregistrer l image dans le guide.');
        header('Location: ' . formationBuildUrl($redirectParams));
        exit;
    }

    $relativePath = 'captures/' . $finalFileName;
    $snippetAlt = $altText !== '' ? $altText : ($captureSnippetDefaultAlt !== '' ? $captureSnippetDefaultAlt : 'Vue Finéa');
    $snippetCaption = $captionText !== '' ? $captionText : ($captureSnippetDefaultCaption !== '' ? $captureSnippetDefaultCaption : $snippetAlt);
    $snippet = formationBuildCaptureSnippet($relativePath, $snippetAlt, $snippetCaption);

    formationCreateCaptureUploadFlash('success', 'Image envoyée avec succès dans le dossier captures du guide.', [
        'path' => $relativePath,
        'alt' => $snippetAlt,
        'caption' => $snippetCaption,
        'snippet' => $snippet,
        'file_name' => $finalFileName,
    ]);
    header('Location: ' . formationBuildUrl($redirectParams) . '#capture-upload');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canUseCaptureMode && $captureMode && (string) ($_POST['formation_action'] ?? '') === 'insert_capture_snippet') {
    $redirectParams = [
        'module' => $currentModule,
        'audience' => $currentAudience,
        'doc' => $currentDoc['slug'] ?? null,
        'collaborateur' => $captureCollaborator,
        'collaborateur_email' => $captureCollaboratorEmail,
        'capture_mode' => 1,
    ];

    if ($currentDoc === null || empty($currentDoc['path']) || !is_file($currentDoc['path']) || !is_writable($currentDoc['path'])) {
        formationCreateCaptureUploadFlash('error', 'Le guide courant n est pas accessible en écriture pour insérer le bloc Markdown.');
        header('Location: ' . formationBuildUrl($redirectParams) . '#capture-upload');
        exit;
    }

    $snippetPath = trim((string) ($_POST['capture_snippet_path'] ?? ''));
    $snippetAlt = trim((string) ($_POST['capture_snippet_alt'] ?? ''));
    $snippetCaption = trim((string) ($_POST['capture_snippet_caption'] ?? ''));
    $targetMode = trim((string) ($_POST['capture_insert_target_mode'] ?? 'end'));
    $targetValue = trim((string) ($_POST['capture_insert_target_value'] ?? ''));

    if ($snippetPath === '' || $snippetAlt === '') {
        formationCreateCaptureUploadFlash('error', 'Le chemin image et le texte alternatif sont requis pour insérer le bloc dans le guide.');
        header('Location: ' . formationBuildUrl($redirectParams) . '#capture-upload');
        exit;
    }

    $snippet = formationBuildCaptureSnippet($snippetPath, $snippetAlt, $snippetCaption);
    $existingContent = (string) file_get_contents($currentDoc['path']);
    if (strpos($existingContent, $snippet) !== false) {
        formationCreateCaptureUploadFlash('success', 'Le bloc Markdown est déjà présent dans ce guide.', [
            'path' => $snippetPath,
            'alt' => $snippetAlt,
            'caption' => $snippetCaption,
            'snippet' => $snippet,
            'target_mode' => $targetMode,
            'target_value' => $targetValue,
        ]);
        header('Location: ' . formationBuildUrl($redirectParams) . '#capture-upload');
        exit;
    }

    $insertResult = formationInsertSnippetIntoMarkdown($existingContent, $snippet, $targetMode, $targetValue);
    if (empty($insertResult['success'])) {
        formationCreateCaptureUploadFlash('error', (string) ($insertResult['message'] ?? 'Le bloc Markdown n a pas pu être inséré dans le guide.'), [
            'path' => $snippetPath,
            'alt' => $snippetAlt,
            'caption' => $snippetCaption,
            'snippet' => $snippet,
            'target_mode' => $targetMode,
            'target_value' => $targetValue,
        ]);
        header('Location: ' . formationBuildUrl($redirectParams) . '#capture-upload');
        exit;
    }

    if (file_put_contents($currentDoc['path'], (string) ($insertResult['content'] ?? $existingContent)) === false) {
        formationCreateCaptureUploadFlash('error', 'Le bloc Markdown n a pas pu être écrit dans le guide.');
        header('Location: ' . formationBuildUrl($redirectParams) . '#capture-upload');
        exit;
    }

    formationCreateCaptureUploadFlash('success', (string) ($insertResult['message'] ?? 'Bloc Markdown inséré automatiquement dans le guide.'), [
        'path' => $snippetPath,
        'alt' => $snippetAlt,
        'caption' => $snippetCaption,
        'snippet' => $snippet,
        'inserted' => true,
        'target_mode' => $targetMode,
        'target_value' => $targetValue,
    ]);
    header('Location: ' . formationBuildUrl($redirectParams) . '#capture-upload');
    exit;
}

$captureUploadFlash = formationConsumeCaptureUploadFlash();
if (is_array($captureUploadFlash) && is_array($captureUploadFlash['data'] ?? null)) {
    $flashData = $captureUploadFlash['data'];
    $captureSnippetDefaultPath = trim((string) ($flashData['path'] ?? $captureSnippetDefaultPath));
    $captureSnippetDefaultAlt = trim((string) ($flashData['alt'] ?? $captureSnippetDefaultAlt));
    $captureSnippetDefaultCaption = trim((string) ($flashData['caption'] ?? $captureSnippetDefaultCaption));
    $captureInsertTargetMode = trim((string) ($flashData['target_mode'] ?? $captureInsertTargetMode));
    $captureInsertTargetValue = trim((string) ($flashData['target_value'] ?? $captureInsertTargetValue));
}
$captureSnippetPreviewUrl = $currentDocBaseDir !== '' ? formationResolveAssetUrl($captureSnippetDefaultPath, $currentDocBaseDir) : '';

if (is_array($academyDashboard) && !empty($academyDashboard['courses']) && is_array($academyDashboard['courses'])) {
    foreach ($academyDashboard['courses'] as &$academyCourseRow) {
        $academyModuleSlug = (string)($academyCourseRow['module_slug'] ?? '');
        $academyPortalMeta = formationFindPortalModuleForSlug($academyModuleSlug, $accessiblePortalModules, $portalModules);
        if ($academyPortalMeta !== null) {
            $academyCourseRow['module_label'] = (string)($academyPortalMeta['label'] ?? $academyModuleSlug);
            $academyCourseRow['module_icon'] = (string)($academyPortalMeta['icon'] ?? 'fa-graduation-cap');
            $academyCourseRow['module_color'] = (string)($academyPortalMeta['color'] ?? '#1d4ed8');
            $academyCourseRow['module_subtitle'] = (string)($academyPortalMeta['subtitle'] ?? '');
        } else {
            $academyCourseRow['module_label'] = formationModuleLabel($academyModuleSlug);
            $academyCourseRow['module_icon'] = 'fa-graduation-cap';
            $academyCourseRow['module_color'] = '#1d4ed8';
            $academyCourseRow['module_subtitle'] = '';
        }
    }
    unset($academyCourseRow);
}

require_once $projectRoot . '/decaissement/includes/header.php';
require_once $projectRoot . '/decaissement/includes/navbar.php';
?>
<link rel="stylesheet" href="<?= htmlspecialchars(($appBasePath === '' ? '' : $appBasePath) . '/formation/assets/academy-v11.css', ENT_QUOTES, 'UTF-8') ?>">
<div class="formation-page-shell min-h-screen bg-slate-100 py-6">
    <div class="formation-page-container space-y-6">
        <section class="overflow-hidden rounded-[28px] border border-slate-200 bg-white shadow-[0_18px_60px_rgba(15,23,42,0.07)]">
            <header class="academy-simple-header">
 <div class="academy-title"><?php if ($appIconUrl !== null): ?><img src="<?= htmlspecialchars($appIconUrl, ENT_QUOTES, 'UTF-8') ?>" alt=""><?php endif; ?><div><h1>FINEA Academy</h1><small><?= htmlspecialchars((string)$academyBrand['company_name'], ENT_QUOTES, 'UTF-8') ?></small></div></div>
 <button type="button" data-academy-theme class="academy-theme-button">Mode sombre</button>
</header>
<nav class="academy-simple-nav" aria-label="Navigation Formation">
<?php foreach (['dashboard'=>'Accueil','catalog'=>'Catalogue','progress'=>'Mes formations','guides'=>'Guides pratiques'] as $mode=>$label): ?>
<a <?= ($academyMode === $mode || ($mode === 'guides' && $academyMode === '')) ? 'aria-current="page"' : '' ?> href="<?= htmlspecialchars(formationBuildUrl($mode === 'guides' ? ['module'=>$currentModule] : ['academy'=>$mode]), ENT_QUOTES, 'UTF-8') ?>"><?= $label ?></a>
<?php endforeach; ?>
<?php if ($isFormationAdmin): ?><a href="<?= htmlspecialchars($appBasePath.'/formation/admin/index.php', ENT_QUOTES, 'UTF-8') ?>">Administration</a><?php endif; ?>
</nav>
            <?php if ($canUseCaptureMode && $academyMode === ''): ?>
                <details class="academy-capture" <?= $captureMode ? 'open' : '' ?>><summary>Outils techniques et captures</summary><div class="border-t border-slate-200 bg-white px-6 py-5 md:px-8">
                    <form method="get" action="<?= htmlspecialchars($formationHomeUrl, ENT_QUOTES, 'UTF-8') ?>" class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_320px_320px_auto] xl:items-end">
                        <input type="hidden" name="module" value="<?= htmlspecialchars($currentModule, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="audience" value="<?= htmlspecialchars($currentAudience, ENT_QUOTES, 'UTF-8') ?>">
                        <?php if ($currentDoc !== null): ?>
                            <input type="hidden" name="doc" value="<?= htmlspecialchars($currentDoc['slug'], ENT_QUOTES, 'UTF-8') ?>">
                        <?php endif; ?>
                        <div>
                            <label for="collaborateur" class="mb-2 block text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Collaborateur pour captures</label>
                            <input id="collaborateur" name="collaborateur" value="<?= htmlspecialchars($captureCollaborator, ENT_QUOTES, 'UTF-8') ?>" placeholder="Ex: Yao Amani" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-800 shadow-sm focus:border-blue-300 focus:outline-none focus:ring-2 focus:ring-blue-100">
                        </div>
                        <div>
                            <label for="collaborateur_email" class="mb-2 block text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Email du collaborateur</label>
                            <input id="collaborateur_email" name="collaborateur_email" value="<?= htmlspecialchars($captureCollaboratorEmail, ENT_QUOTES, 'UTF-8') ?>" placeholder="Ex: collaborateur@entreprise.ci" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-800 shadow-sm focus:border-blue-300 focus:outline-none focus:ring-2 focus:ring-blue-100">
                        </div>
                        <label class="inline-flex items-center gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-700">
                            <input type="checkbox" name="capture_mode" value="1" <?= $captureMode ? 'checked' : '' ?> class="rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                            Mode enrichissement captures
                        </label>
                        <button type="submit" class="inline-flex items-center justify-center gap-2 rounded-2xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white hover:bg-slate-800">
                            <i class="fas fa-sliders"></i>
                            Appliquer le contexte
                        </button>
                    </form>

                    <?php if ($captureMode): ?>
                        <div class="mt-4 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-4 text-sm text-amber-900">
                            <div class="font-semibold">Mode captures activé</div>
                            <div class="mt-1">Le parcours d enrichissement est préparé pour <?= htmlspecialchars($captureCollaborator !== '' ? $captureCollaborator : 'un collaborateur', ENT_QUOTES, 'UTF-8') ?><?= $captureCollaboratorEmail !== '' ? ' (' . htmlspecialchars($captureCollaboratorEmail, ENT_QUOTES, 'UTF-8') . ')' : '' ?>. Cette information reste réservée aux profils techniques autorisés.</div>
                        </div>
                    <?php endif; ?>
                </div>
                </details>
            <?php endif; ?>
            <div class="academy-workspace<?= $academyMode !== '' ? ' academy-workspace-learning academy-focus' : ' academy-guides' ?>">
                <?php if ($academyMode === ''): ?><aside class="academy-library border-b border-slate-200 bg-slate-50/80 p-4 lg:border-b-0 lg:border-r"><a class="academy-home-link" href="<?= htmlspecialchars(formationBuildUrl(['academy'=>'dashboard']), ENT_QUOTES, 'UTF-8') ?>"><i class="fas fa-graduation-cap"></i> Mes parcours</a><label class="academy-filter-label" for="academyFilter">Rechercher un module</label><input id="academyFilter" type="search" placeholder="Filtrer les guides…">
                    <div class="mb-3 text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Modules</div>
                    <div class="space-y-3">
                        <?php if ($moduleCatalog === []): ?>
                            <div class="rounded-2xl border border-dashed border-slate-200 bg-white px-4 py-5 text-sm text-slate-500">Aucun module de formation n est encore publié.</div>
                        <?php else: ?>
                            <?php foreach ($moduleCatalog as $moduleSlug => $moduleData): ?>
                                <?php
                                $isCurrentModule = $moduleSlug === $currentModule;
                                $userCount = count($moduleData['utilisateur'] ?? []);
                                $techCount = count($moduleData['technique'] ?? []);
                                $moduleLink = formationBuildUrl([
                                    'module' => $moduleSlug,
                                    'audience' => $currentAudience,
                                    'collaborateur' => $captureCollaborator,
                                    'collaborateur_email' => $captureCollaboratorEmail,
                                    'capture_mode' => $captureMode ? 1 : null,
                                ]);
                                ?>
                                <?php $moduleColor = preg_match('/^#[0-9A-Fa-f]{6}$/', (string)($moduleData['color'] ?? '')) ? (string)$moduleData['color'] : '#1d4ed8'; ?>
                                <a href="<?= htmlspecialchars($moduleLink, ENT_QUOTES, 'UTF-8') ?>" class="formation-module-pill <?= $isCurrentModule ? 'is-active' : '' ?>" style="--module-color: <?= htmlspecialchars($moduleColor, ENT_QUOTES, 'UTF-8') ?>;">
                                    <span class="formation-module-icon"><i class="fas <?= htmlspecialchars((string)($moduleData['icon'] ?? 'fa-graduation-cap'), ENT_QUOTES, 'UTF-8') ?>"></i></span>
                                    <span class="formation-module-text">
                                        <strong><?= htmlspecialchars($moduleData['label'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <small><?= $userCount ?> guide<?= $userCount > 1 ? 's' : '' ?><?= $canAccessTechnical ? ' • ' . $techCount . ' tech.' : '' ?></small>
                                    </span>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </aside><?php endif; ?>

                <main class="academy-content border-b border-slate-200 p-5 lg:border-b-0 lg:border-r">
                    <?php if ($academyMode === 'course'): ?>
                        <?php if (is_array($academyCourseData)): ?>
                            <?php require __DIR__ . '/views/academy_course.php'; ?>
                        <?php else: ?>
                            <div class="finea-alert">Parcours Academy introuvable ou base FINEA Academy non initialisée.</div>
                        <?php endif; ?>
                    <?php elseif (in_array($academyMode, ['dashboard','catalog','progress'], true)): ?>
                        <?php if (is_array($academyDashboard)): ?>
                            <?php require __DIR__ . '/views/academy_dashboard.php'; ?>
                        <?php endif; ?>
                    <?php else: ?>
                    <?php if ($currentModule === '' || !isset($moduleCatalog[$currentModule])): ?>
                        <div class="rounded-3xl border border-dashed border-slate-200 bg-slate-50 px-6 py-10 text-center text-slate-500">Aucune formation disponible pour le moment.</div>
                    <?php else: ?>
                        <div class="mb-5 flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                            <div>
                                <div class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-400">Module sélectionné</div>
                                <h2 class="mt-1 text-2xl font-bold text-slate-900"><?= htmlspecialchars($moduleCatalog[$currentModule]['label'], ENT_QUOTES, 'UTF-8') ?></h2>
                            </div>
                            <div class="inline-flex rounded-2xl border border-slate-200 bg-slate-50 p-1">
                                <?php foreach ($availableAudiences as $audience): ?>
                                    <?php $audienceLink = formationBuildUrl(['module' => $currentModule, 'audience' => $audience, 'collaborateur' => $captureCollaborator, 'collaborateur_email' => $captureCollaboratorEmail, 'capture_mode' => $captureMode ? 1 : null]); ?>
                                    <a href="<?= htmlspecialchars($audienceLink, ENT_QUOTES, 'UTF-8') ?>" class="rounded-xl px-4 py-2 text-sm font-semibold transition <?= $currentAudience === $audience ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-800' ?>">
                                        <?= $audience === 'technique' ? 'Documentation technique' : 'Guides utilisateurs' ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <?php if ($currentDoc === null): ?>
                            <div class="rounded-3xl border border-dashed border-slate-200 bg-slate-50 px-6 py-10 text-center text-slate-500">Aucun document disponible dans cette catégorie pour ce module.</div>
                        <?php else: ?>
                            <article class="formation-doc prose prose-slate max-w-none rounded-[26px] border border-slate-200 bg-white px-6 py-6 shadow-sm" data-guide-preview-container>
                                <div class="mb-6 flex flex-col gap-3 border-b border-slate-200 pb-5 md:flex-row md:items-center md:justify-between">
                                    <div>
                                        <div class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-400"><?= $currentAudience === 'technique' ? 'Technique' : 'Utilisateur' ?></div>
                                        <h3 class="mt-1 text-2xl font-bold text-slate-900"><?= htmlspecialchars($currentDoc['title'], ENT_QUOTES, 'UTF-8') ?></h3>
                                        <?php if ($captureMode): ?>
                                            <div class="mt-2 inline-flex items-center gap-2 rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-800">
                                                <i class="fas fa-camera"></i>
                                                Contexte capture : <?= htmlspecialchars($captureCollaborator !== '' ? $captureCollaborator : 'collaborateur a préciser', ENT_QUOTES, 'UTF-8') ?><?= $captureCollaboratorEmail !== '' ? ' • ' . htmlspecialchars($captureCollaboratorEmail, ENT_QUOTES, 'UTF-8') : '' ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex flex-wrap items-center gap-3">
                                        <button type="button" class="inline-flex items-center gap-2 rounded-2xl border border-blue-200 bg-blue-50 px-4 py-3 text-sm font-semibold text-blue-800 hover:bg-blue-100" data-guide-preview-reload>
                                            <i class="fas fa-rotate-right"></i>
                                            <span>Recharger l aperçu du guide</span>
                                        </button>
                                        <?php if ($canAccessTechnical): ?>
                                            <a href="<?= htmlspecialchars(formationBuildUrl(['module' => $currentModule, 'audience' => $currentAudience, 'doc' => $currentDoc['slug'], 'edit' => $editMode ? null : 1, 'collaborateur' => $captureCollaborator, 'collaborateur_email' => $captureCollaboratorEmail, 'capture_mode' => $captureMode ? 1 : null]), ENT_QUOTES, 'UTF-8') ?>" class="inline-flex items-center gap-2 rounded-2xl border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm font-semibold text-indigo-800 hover:bg-indigo-100">
                                                <i class="fas fa-pen-to-square"></i>
                                                <span><?= $editMode ? 'Fermer l édition' : 'Modifier le guide' ?></span>
                                            </a>
                                        <?php endif; ?>
                                        <a href="<?= htmlspecialchars(formationBuildUrl(['module' => $currentModule, 'audience' => $currentAudience, 'doc' => $currentDoc['slug'], 'export' => 'pdf_doc', 'collaborateur' => $captureCollaborator, 'collaborateur_email' => $captureCollaboratorEmail, 'capture_mode' => $captureMode ? 1 : null]), ENT_QUOTES, 'UTF-8') ?>" class="inline-flex items-center gap-2 rounded-2xl bg-slate-900 px-4 py-3 text-sm font-semibold text-white hover:bg-slate-800">
                                            <i class="fas fa-file-pdf"></i>
                                            <span>Exporter ce guide en PDF</span>
                                        </a>
                                        <a href="<?= htmlspecialchars(formationBuildUrl(['module' => $currentModule, 'audience' => $currentAudience, 'export' => 'pdf_module', 'collaborateur' => $captureCollaborator, 'collaborateur_email' => $captureCollaboratorEmail, 'capture_mode' => $captureMode ? 1 : null]), ENT_QUOTES, 'UTF-8') ?>" class="inline-flex items-center gap-2 rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                                            <i class="fas fa-books"></i>
                                            <span>Exporter tout le module en PDF</span>
                                        </a>
                                        <a href="<?= htmlspecialchars(formationBuildUrl(['module' => $currentModule, 'audience' => $currentAudience, 'doc' => $currentDoc['slug'], 'download' => 1, 'collaborateur' => $captureCollaborator, 'collaborateur_email' => $captureCollaboratorEmail, 'capture_mode' => $captureMode ? 1 : null]), ENT_QUOTES, 'UTF-8') ?>" class="inline-flex items-center gap-2 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-100">
                                            <i class="fas fa-file-arrow-down"></i>
                                            <span>Voir le Markdown</span>
                                        </a>
                                    </div>
                                </div>
                                <?php if (is_array($formationEditFlash)): ?>
                                    <?php $editFlashType = (string) ($formationEditFlash['type'] ?? 'success'); ?>
                                    <div class="mb-5 rounded-2xl border px-4 py-4 text-sm <?= $editFlashType === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-900' : 'border-rose-200 bg-rose-50 text-rose-900' ?>">
                                        <?= htmlspecialchars((string) ($formationEditFlash['message'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($editMode): ?>
                                    <form method="post" action="<?= htmlspecialchars(formationBuildUrl(['module' => $currentModule, 'audience' => $currentAudience, 'doc' => $currentDoc['slug'], 'edit' => 1, 'collaborateur' => $captureCollaborator, 'collaborateur_email' => $captureCollaboratorEmail, 'capture_mode' => $captureMode ? 1 : null]), ENT_QUOTES, 'UTF-8') ?>" class="mb-6 rounded-3xl border border-indigo-100 bg-indigo-50 p-5">
                                        <input type="hidden" name="formation_action" value="save_markdown_document">
                                        <label for="markdown_content" class="mb-3 block text-xs font-semibold uppercase tracking-[0.2em] text-indigo-700">Contenu Markdown</label>
                                        <textarea id="markdown_content" name="markdown_content" rows="22" class="w-full resize-y rounded-2xl border border-indigo-100 bg-white px-4 py-4 font-mono text-sm leading-6 text-slate-800 shadow-sm focus:border-indigo-300 focus:outline-none focus:ring-2 focus:ring-indigo-100"><?= htmlspecialchars($currentDocRawContent, ENT_QUOTES, 'UTF-8') ?></textarea>
                                        <div class="mt-4 flex flex-wrap items-center gap-3">
                                            <button type="submit" class="inline-flex items-center gap-2 rounded-2xl bg-indigo-700 px-4 py-3 text-sm font-semibold text-white hover:bg-indigo-800">
                                                <i class="fas fa-floppy-disk"></i>
                                                <span>Enregistrer</span>
                                            </button>
                                            <a href="<?= htmlspecialchars(formationBuildUrl(['module' => $currentModule, 'audience' => $currentAudience, 'doc' => $currentDoc['slug'], 'collaborateur' => $captureCollaborator, 'collaborateur_email' => $captureCollaboratorEmail, 'capture_mode' => $captureMode ? 1 : null]), ENT_QUOTES, 'UTF-8') ?>" class="inline-flex items-center gap-2 rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                                                <i class="fas fa-xmark"></i>
                                                <span>Annuler</span>
                                            </a>
                                        </div>
                                    </form>
                                <?php endif; ?>
                                <?= formationRenderMarkdown($currentDocContent, $currentDocBaseDir) ?>
                            </article>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php endif; ?>
                </main>

                <?php if ($academyMode === ''): ?><aside class="academy-doc-sidebar bg-slate-50/70 p-4">
                    <div class="mb-3 text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Documents</div>
                    <div class="space-y-3">
                        <?php if (empty($currentDocs)): ?>
                            <div class="rounded-2xl border border-dashed border-slate-200 bg-white px-4 py-5 text-sm text-slate-500">Aucun document dans cette catégorie.</div>
                        <?php else: ?>
                            <?php foreach ($currentDocs as $docRow): ?>
                                <?php $docLink = formationBuildUrl(['module' => $currentModule, 'audience' => $currentAudience, 'doc' => $docRow['slug'], 'collaborateur' => $captureCollaborator, 'collaborateur_email' => $captureCollaboratorEmail, 'capture_mode' => $captureMode ? 1 : null]); ?>
                                <a href="<?= htmlspecialchars($docLink, ENT_QUOTES, 'UTF-8') ?>" class="block rounded-2xl border px-4 py-4 transition <?= $currentDoc !== null && $docRow['slug'] === $currentDoc['slug'] ? 'border-slate-900 bg-slate-900 text-white shadow-sm' : 'border-slate-200 bg-white text-slate-700 hover:border-slate-300 hover:bg-slate-50' ?>">
                                    <div class="flex items-start gap-3">
                                        <span class="mt-0.5 inline-flex h-9 w-9 items-center justify-center rounded-xl <?= $currentDoc !== null && $docRow['slug'] === $currentDoc['slug'] ? 'bg-white/15 text-white' : 'bg-blue-100 text-blue-700' ?>">
                                            <i class="fas fa-file-lines"></i>
                                        </span>
                                        <div>
                                            <div class="text-sm font-semibold"><?= htmlspecialchars($docRow['title'], ENT_QUOTES, 'UTF-8') ?></div>
                                            <div class="mt-1 text-xs <?= $currentDoc !== null && $docRow['slug'] === $currentDoc['slug'] ? 'text-slate-300' : 'text-slate-500' ?>"><?= htmlspecialchars($docRow['file'], ENT_QUOTES, 'UTF-8') ?></div>
                                        </div>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <?php if ($currentModule === 'presentation_finea'): ?>
                        <div class="mt-6 rounded-3xl border border-fuchsia-100 bg-white p-5 shadow-sm">
                            <div class="text-xs font-semibold uppercase tracking-[0.22em] text-fuchsia-500">Branding</div>
                            <h3 class="mt-2 text-lg font-bold text-slate-900">Identité <?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?></h3>
                            <div class="mt-3 space-y-3 text-sm text-slate-600">
                                <?php if ($appLogoUrl !== null): ?>
                                    <div class="rounded-3xl border border-slate-200 bg-[radial-gradient(circle_at_left,_rgba(244,114,182,0.18),_transparent_28%),radial-gradient(circle_at_right,_rgba(99,102,241,0.18),_transparent_28%),linear-gradient(180deg,#ffffff_0%,#f8fafc_100%)] px-4 py-5">
                                        <img src="<?= htmlspecialchars($appLogoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Logo <?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?>" class="h-auto w-full object-contain">
                                    </div>
                                <?php endif; ?>
                                <p><span class="font-semibold text-slate-900">Finéa</span> évoque à la fois la finance, la fluidité, l innovation et une expérience plus moderne de gestion.</p>
                                <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4">
                                    <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">Slogan principal</div>
                                    <p class="mt-2 font-medium text-slate-800"><?= htmlspecialchars($brandSlogan, ENT_QUOTES, 'UTF-8') ?></p>
                                    <div class="mt-3 text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">Signature courte</div>
                                    <p class="mt-1 text-sm font-medium text-slate-700"><?= htmlspecialchars($brandSignature, ENT_QUOTES, 'UTF-8') ?></p>
                                </div>
                                <div class="rounded-2xl border border-slate-200 bg-white px-4 py-4">
                                    <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">Narration logo</div>
                                    <ul class="mt-3 list-disc space-y-2 pl-5 text-sm text-slate-600">
                                        <li>Les barres renvoient au pilotage, aux indicateurs et à la croissance.</li>
                                        <li>Le check symbolise la validation, le contrôle et la fiabilité.</li>
                                        <li>Le dégradé rose vers violet porte une image moderne, technologique et premium.</li>
                                        <li>La courbe traduit la fluidité des processus et la circulation de l information.</li>
                                    </ul>
                                </div>
                                <?php if ($appIconUrl !== null): ?>
                                    <div class="rounded-2xl border border-slate-200 bg-white px-4 py-4">
                                        <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">Icône applicative</div>
                                        <div class="mt-3 inline-flex items-center gap-4 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                                            <span class="inline-flex h-16 w-16 items-center justify-center rounded-2xl bg-white p-2 shadow-sm">
                                                <img src="<?= htmlspecialchars($appIconUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Icône <?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?>" class="h-full w-full object-contain">
                                            </span>
                                            <div class="text-sm text-slate-600">Version carrée du signe Finéa, adaptée aux usages favicon, raccourci et repère applicatif.</div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php
                    $currentCaptureNotes = [];
                    if ($captureMode && $currentDoc !== null) {
                        $currentCaptureNotes = $captureNotes[$currentModule][$currentAudience][$currentDoc['slug']] ?? [];
                    }
                    ?>
                    <?php if ($captureMode && $currentCaptureNotes !== []): ?>
                        <div class="mt-6 rounded-3xl border border-amber-200 bg-amber-50 p-5 shadow-sm">
                            <div class="text-xs font-semibold uppercase tracking-[0.22em] text-amber-700">Notes capture</div>
                            <div class="mt-2 text-sm font-semibold text-amber-950">Réservé à l exécutant capture</div>
                            <div class="mt-1 text-xs text-amber-800">Collaborateur de référence : <?= htmlspecialchars($captureCollaborator !== '' ? $captureCollaborator : 'à préciser', ENT_QUOTES, 'UTF-8') ?><?= $captureCollaboratorEmail !== '' ? ' • ' . htmlspecialchars($captureCollaboratorEmail, ENT_QUOTES, 'UTF-8') : '' ?></div>
                            <ol class="mt-4 space-y-2 pl-5 text-sm text-amber-900">
                                <?php foreach ($currentCaptureNotes as $captureNote): ?>
                                    <li><?= htmlspecialchars($captureNote, ENT_QUOTES, 'UTF-8') ?></li>
                                <?php endforeach; ?>
                            </ol>
                        </div>
                    <?php endif; ?>

                    <?php if ($captureMode && $currentDoc !== null): ?>
                        <div id="capture-upload" class="mt-6 rounded-3xl border border-blue-200 bg-blue-50 p-5 shadow-sm">
                            <div class="text-xs font-semibold uppercase tracking-[0.22em] text-blue-700">Insertion d image</div>
                            <div class="mt-2 text-sm font-semibold text-slate-900">Upload direct vers le guide courant</div>
                            <p class="mt-2 text-xs leading-6 text-slate-600">L image est enregistrée dans un dossier captures à côté du guide, puis le bloc Markdown est généré avec le bon chemin relatif.</p>

                            <?php if (is_array($captureUploadFlash)): ?>
                                <?php $flashType = (string) ($captureUploadFlash['type'] ?? 'success'); ?>
                                <div class="mt-4 rounded-2xl border px-4 py-4 text-sm <?= $flashType === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-900' : 'border-rose-200 bg-rose-50 text-rose-900' ?>">
                                    <div class="font-semibold"><?= htmlspecialchars((string) ($captureUploadFlash['message'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php if ($flashType === 'success' && !empty($captureUploadFlash['data']['path'])): ?>
                                        <div class="mt-1 text-xs">Chemin prêt à insérer : <?= htmlspecialchars((string) $captureUploadFlash['data']['path'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                    <?php if ($flashType === 'success' && !empty($captureUploadFlash['data']['inserted'])): ?>
                                        <div class="mt-1 text-xs">Le guide courant a été mis à jour automatiquement.</div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <form method="post" action="<?= htmlspecialchars(formationBuildUrl(['module' => $currentModule, 'audience' => $currentAudience, 'doc' => $currentDoc['slug'], 'collaborateur' => $captureCollaborator, 'collaborateur_email' => $captureCollaboratorEmail, 'capture_mode' => 1]), ENT_QUOTES, 'UTF-8') ?>#capture-upload" enctype="multipart/form-data" class="mt-4 space-y-4">
                                <input type="hidden" name="formation_action" value="upload_capture_image">
                                <div>
                                    <label for="capture_image_file" class="mb-2 block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Fichier image</label>
                                    <input id="capture_image_file" name="capture_image_file" type="file" accept="image/png,image/jpeg,image/webp,image/gif" class="block w-full rounded-2xl border border-blue-100 bg-white px-4 py-3 text-sm text-slate-800 shadow-sm file:mr-4 file:rounded-xl file:border-0 file:bg-slate-900 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-white hover:file:bg-slate-800 focus:border-blue-300 focus:outline-none focus:ring-2 focus:ring-blue-100">
                                </div>
                                <div>
                                    <label for="capture_image_name" class="mb-2 block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Nom de fichier souhaité</label>
                                    <input id="capture_image_name" name="capture_image_name" type="text" value="<?= htmlspecialchars($currentDoc['slug'] . '-capture', ENT_QUOTES, 'UTF-8') ?>" class="w-full rounded-2xl border border-blue-100 bg-white px-4 py-3 text-sm text-slate-800 shadow-sm focus:border-blue-300 focus:outline-none focus:ring-2 focus:ring-blue-100">
                                    <div class="mt-1 text-[11px] text-slate-500">Le système nettoie ce nom et ajoute automatiquement l extension réelle du fichier.</div>
                                </div>
                                <div>
                                    <label for="capture_image_upload_alt" class="mb-2 block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Texte alternatif</label>
                                    <input id="capture_image_upload_alt" name="capture_image_alt" type="text" value="<?= htmlspecialchars($captureSnippetDefaultAlt, ENT_QUOTES, 'UTF-8') ?>" class="w-full rounded-2xl border border-blue-100 bg-white px-4 py-3 text-sm text-slate-800 shadow-sm focus:border-blue-300 focus:outline-none focus:ring-2 focus:ring-blue-100">
                                </div>
                                <div>
                                    <label for="capture_image_upload_caption" class="mb-2 block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Légende</label>
                                    <input id="capture_image_upload_caption" name="capture_image_caption" type="text" value="<?= htmlspecialchars($captureSnippetDefaultCaption, ENT_QUOTES, 'UTF-8') ?>" class="w-full rounded-2xl border border-blue-100 bg-white px-4 py-3 text-sm text-slate-800 shadow-sm focus:border-blue-300 focus:outline-none focus:ring-2 focus:ring-blue-100">
                                </div>
                                <button type="submit" class="inline-flex items-center gap-2 rounded-2xl bg-blue-700 px-4 py-3 text-sm font-semibold text-white hover:bg-blue-800">
                                    <i class="fas fa-upload"></i>
                                    Envoyer l image
                                </button>
                            </form>

                            <div class="mt-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                                <div class="mb-2 text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-400">Aperçu immédiat</div>
                                <div class="text-xs leading-6 text-slate-500">L aperçu se met à jour dès la sélection locale du fichier, puis réaffiche le fichier réellement stocké après upload.</div>
                                <div class="mt-4 overflow-hidden rounded-2xl border border-slate-200 bg-slate-50">
                                    <img src="<?= htmlspecialchars($captureSnippetPreviewUrl !== '' ? $captureSnippetPreviewUrl : '', ENT_QUOTES, 'UTF-8') ?>" alt="Aperçu capture" class="<?= $captureSnippetPreviewUrl !== '' ? '' : 'hidden ' ?>block h-auto max-h-80 w-full object-contain bg-white" data-capture-preview-image>
                                    <div class="<?= $captureSnippetPreviewUrl !== '' ? 'hidden ' : '' ?>px-4 py-10 text-center text-sm text-slate-500" data-capture-preview-empty>Aucune image sélectionnée pour le moment.</div>
                                </div>
                                <div class="mt-2 text-[11px] text-slate-500" data-capture-preview-meta>
                                    <?= $captureSnippetPreviewUrl !== '' ? htmlspecialchars($captureSnippetDefaultPath, ENT_QUOTES, 'UTF-8') : 'Choisissez une image ou envoyez-en une pour afficher son aperçu ici.' ?>
                                </div>
                            </div>

                            <form method="post" action="<?= htmlspecialchars(formationBuildUrl(['module' => $currentModule, 'audience' => $currentAudience, 'doc' => $currentDoc['slug'], 'collaborateur' => $captureCollaborator, 'collaborateur_email' => $captureCollaboratorEmail, 'capture_mode' => 1]), ENT_QUOTES, 'UTF-8') ?>#capture-upload" class="mt-4 space-y-4" data-capture-snippet-builder>
                                <input type="hidden" name="formation_action" value="insert_capture_snippet">
                                <div>
                                    <label for="capture_image_path" class="mb-2 block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Chemin image relatif</label>
                                    <input id="capture_image_path" name="capture_snippet_path" type="text" value="<?= htmlspecialchars($captureSnippetDefaultPath, ENT_QUOTES, 'UTF-8') ?>" class="w-full rounded-2xl border border-blue-100 bg-white px-4 py-3 text-sm text-slate-800 shadow-sm focus:border-blue-300 focus:outline-none focus:ring-2 focus:ring-blue-100" data-capture-field="path">
                                </div>
                                <div>
                                    <label for="capture_image_alt" class="mb-2 block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Texte alternatif</label>
                                    <input id="capture_image_alt" name="capture_snippet_alt" type="text" value="<?= htmlspecialchars($captureSnippetDefaultAlt, ENT_QUOTES, 'UTF-8') ?>" class="w-full rounded-2xl border border-blue-100 bg-white px-4 py-3 text-sm text-slate-800 shadow-sm focus:border-blue-300 focus:outline-none focus:ring-2 focus:ring-blue-100" data-capture-field="alt">
                                </div>
                                <div>
                                    <label for="capture_image_caption" class="mb-2 block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Légende</label>
                                    <input id="capture_image_caption" name="capture_snippet_caption" type="text" value="<?= htmlspecialchars($captureSnippetDefaultCaption, ENT_QUOTES, 'UTF-8') ?>" class="w-full rounded-2xl border border-blue-100 bg-white px-4 py-3 text-sm text-slate-800 shadow-sm focus:border-blue-300 focus:outline-none focus:ring-2 focus:ring-blue-100" data-capture-field="caption">
                                </div>
                                <div>
                                    <label for="capture_insert_target_mode" class="mb-2 block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Emplacement d insertion</label>
                                    <select id="capture_insert_target_mode" name="capture_insert_target_mode" class="w-full rounded-2xl border border-blue-100 bg-white px-4 py-3 text-sm text-slate-800 shadow-sm focus:border-blue-300 focus:outline-none focus:ring-2 focus:ring-blue-100" data-capture-target-mode>
                                        <option value="end" <?= $captureInsertTargetMode === 'end' ? 'selected' : '' ?>>Fin du guide</option>
                                        <option value="before_heading" <?= $captureInsertTargetMode === 'before_heading' ? 'selected' : '' ?>>Avant un titre Markdown</option>
                                        <option value="after_heading" <?= $captureInsertTargetMode === 'after_heading' ? 'selected' : '' ?>>Après un titre Markdown</option>
                                        <option value="after_marker" <?= $captureInsertTargetMode === 'after_marker' ? 'selected' : '' ?>>Après un repère texte</option>
                                    </select>
                                </div>
                                <input type="hidden" name="capture_insert_target_value" value="<?= htmlspecialchars($captureInsertTargetValue, ENT_QUOTES, 'UTF-8') ?>" data-capture-target-value-hidden>
                                <div data-capture-target-heading-group>
                                    <label for="capture_insert_heading_value" class="mb-2 block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Titre détecté</label>
                                    <select id="capture_insert_heading_value" class="w-full rounded-2xl border border-blue-100 bg-white px-4 py-3 text-sm text-slate-800 shadow-sm focus:border-blue-300 focus:outline-none focus:ring-2 focus:ring-blue-100" data-capture-target-heading>
                                        <option value="">Sélectionner un titre du guide</option>
                                        <?php foreach ($currentDocHeadings as $headingRow): ?>
                                            <option value="<?= htmlspecialchars($headingRow['title'], ENT_QUOTES, 'UTF-8') ?>" <?= formationNormalizeSearchValue($captureInsertTargetValue) === (string) $headingRow['normalized'] ? 'selected' : '' ?>><?= htmlspecialchars(str_repeat('• ', max(0, (int) $headingRow['level'] - 1)) . $headingRow['title'], ENT_QUOTES, 'UTF-8') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="mt-1 text-[11px] text-slate-500">Liste construite à partir des vrais titres Markdown détectés dans le guide courant.</div>
                                </div>
                                <div data-capture-target-marker-group>
                                    <label for="capture_insert_marker_value" class="mb-2 block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Repère texte</label>
                                    <input id="capture_insert_marker_value" type="text" value="<?= htmlspecialchars($captureInsertTargetMode === 'after_marker' ? $captureInsertTargetValue : '', ENT_QUOTES, 'UTF-8') ?>" placeholder="Ex: Préparer la capture" class="w-full rounded-2xl border border-blue-100 bg-white px-4 py-3 text-sm text-slate-800 shadow-sm focus:border-blue-300 focus:outline-none focus:ring-2 focus:ring-blue-100" data-capture-target-marker>
                                </div>
                                <div class="mt-1 text-[11px] text-slate-500" data-capture-target-help>Choisissez fin du guide, ou indiquez le titre/repère après lequel la capture doit être insérée.</div>
                        </div>
                        <div class="rounded-2xl border border-slate-200 bg-slate-950 p-4 text-slate-100">
                            <div class="mb-2 text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-400">Bloc à insérer</div>
                            <textarea rows="4" readonly class="w-full resize-none border-0 bg-transparent p-0 text-sm leading-6 text-slate-100 focus:outline-none" data-capture-output></textarea>
                        </div>
                        <div class="flex flex-wrap items-center gap-3">
                            <button type="button" class="inline-flex items-center gap-2 rounded-2xl bg-slate-900 px-4 py-3 text-sm font-semibold text-white hover:bg-slate-800" data-capture-copy>
                                <i class="fas fa-copy"></i>
                                Copier le bloc
                            </button>
                            <button type="submit" class="inline-flex items-center gap-2 rounded-2xl bg-emerald-600 px-4 py-3 text-sm font-semibold text-white hover:bg-emerald-700">
                                <i class="fas fa-file-pen"></i>
                                Insérer dans le guide
                            </button>
                            <div class="text-xs text-slate-500">Exemple recommandé : dossier captures dans le même répertoire que [<?= htmlspecialchars($currentDoc['file'], ENT_QUOTES, 'UTF-8') ?>].</div>
                        </div>
                        </form>
            </div>
        <?php endif; ?>
        </aside><?php endif; ?>
    </div>
    </section>
</div>
</div>

<style>
    .formation-doc h2,
    .formation-doc h3,
    .formation-doc h4 {
        color: #0f172a;
        font-weight: 700;
        margin-top: 1.6rem;
        margin-bottom: 0.85rem;
    }

    .formation-doc p,
    .formation-doc li {
        color: #334155;
        line-height: 1.75;
        font-size: 0.97rem;
    }

    .formation-doc ul,
    .formation-doc ol {
        margin: 1rem 0 1.25rem 1.25rem;
    }

    .formation-doc ul {
        list-style: disc;
    }

    .formation-doc ol {
        list-style: decimal;
    }

    .formation-doc pre {
        white-space: pre-wrap;
    }

    .formation-doc code {
        font-family: Consolas, Monaco, monospace;
    }

    .formation-doc .formation-inline-keyword {
        display: inline-block;
        border: 1px solid #bfdbfe;
        border-radius: 999px;
        background: #eff6ff;
        padding: 0.1rem 0.55rem;
        font-size: 0.88em;
        font-weight: 600;
        color: #1d4ed8;
        vertical-align: baseline;
    }

    .formation-doc .formation-figure {
        margin: 1.5rem 0;
        border: 1px solid #e2e8f0;
        border-radius: 1.5rem;
        background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
        padding: 1rem;
        box-shadow: 0 8px 30px rgba(15, 23, 42, 0.05);
    }

    .formation-doc .formation-figure__image {
        display: block;
        width: 100%;
        height: auto;
        max-height: 28rem;
        object-fit: contain;
        margin: 0 auto;
        border-radius: 1rem;
        background: #ffffff;
    }

    .formation-doc .formation-figure__caption {
        margin-top: 0.85rem;
        text-align: center;
        font-size: 0.88rem;
        line-height: 1.6;
        color: #475569;
    }
</style>

<script src="<?= htmlspecialchars(($appBasePath === '' ? '' : $appBasePath) . '/formation/assets/academy.js', ENT_QUOTES, 'UTF-8') ?>"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        var builder = document.querySelector('[data-capture-snippet-builder]');
        if (!builder) {
            return;
        }

        var pathField = builder.querySelector('[data-capture-field="path"]');
        var altField = builder.querySelector('[data-capture-field="alt"]');
        var captionField = builder.querySelector('[data-capture-field="caption"]');
        var outputField = builder.querySelector('[data-capture-output]');
        var copyButton = builder.querySelector('[data-capture-copy]');
        var uploadField = document.querySelector('#capture_image_file');
        var previewImage = document.querySelector('[data-capture-preview-image]');
        var previewEmpty = document.querySelector('[data-capture-preview-empty]');
        var previewMeta = document.querySelector('[data-capture-preview-meta]');
        var targetModeField = builder.querySelector('[data-capture-target-mode]');
        var targetValueHiddenField = builder.querySelector('[data-capture-target-value-hidden]');
        var targetHeadingGroup = builder.querySelector('[data-capture-target-heading-group]');
        var targetHeadingField = builder.querySelector('[data-capture-target-heading]');
        var targetMarkerGroup = builder.querySelector('[data-capture-target-marker-group]');
        var targetMarkerField = builder.querySelector('[data-capture-target-marker]');
        var targetHelp = builder.querySelector('[data-capture-target-help]');
        var guidePreviewReloadButton = document.querySelector('[data-guide-preview-reload]');

        var setPreviewState = function(src, label) {
            if (!previewImage || !previewEmpty || !previewMeta) {
                return;
            }

            if (src) {
                previewImage.src = src;
                previewImage.classList.remove('hidden');
                previewEmpty.classList.add('hidden');
                previewMeta.textContent = label || pathField.value.trim();
                return;
            }

            previewImage.classList.add('hidden');
            previewEmpty.classList.remove('hidden');
            previewMeta.textContent = 'Choisissez une image ou envoyez-en une pour afficher son aperçu ici.';
        };

        var updateOutput = function() {
            var path = pathField.value.trim();
            var alt = altField.value.trim();
            var caption = captionField.value.trim();
            outputField.value = '![' + alt + '](' + path + (caption !== '' ? ' "' + caption + '"' : '') + ')';
            if (previewImage && !previewImage.classList.contains('hidden') && previewImage.src && !previewImage.src.startsWith('blob:')) {
                previewMeta.textContent = path !== '' ? path : previewMeta.textContent;
            }
        };

        var syncTargetValue = function() {
            if (!targetModeField || !targetValueHiddenField) {
                return;
            }

            if (targetModeField.value === 'before_heading' || targetModeField.value === 'after_heading') {
                targetValueHiddenField.value = targetHeadingField ? targetHeadingField.value : '';
                return;
            }

            if (targetModeField.value === 'after_marker') {
                targetValueHiddenField.value = targetMarkerField ? targetMarkerField.value : '';
                return;
            }

            targetValueHiddenField.value = '';
        };

        var updateTargetHelp = function() {
            if (!targetModeField || !targetHelp) {
                return;
            }

            var isHeadingMode = targetModeField.value === 'before_heading' || targetModeField.value === 'after_heading';
            var isMarkerMode = targetModeField.value === 'after_marker';

            if (targetHeadingGroup) {
                targetHeadingGroup.classList.toggle('hidden', !isHeadingMode);
            }
            if (targetMarkerGroup) {
                targetMarkerGroup.classList.toggle('hidden', !isMarkerMode);
            }

            if (targetModeField.value === 'before_heading') {
                targetHelp.textContent = 'Sélectionnez le titre détecté avant lequel la capture doit être insérée.';
                syncTargetValue();
                return;
            }

            if (targetModeField.value === 'after_heading') {
                targetHelp.textContent = 'Sélectionnez le titre détecté après lequel la capture doit être insérée.';
                syncTargetValue();
                return;
            }

            if (targetModeField.value === 'after_marker') {
                targetHelp.textContent = 'Indiquez un texte repère contenu dans le guide. La capture sera insérée après la première occurrence trouvée.';
                syncTargetValue();
                return;
            }

            targetHelp.textContent = 'La capture sera ajoutée à la fin du guide si aucun ciblage particulier n est demandé.';
            syncTargetValue();
        };

        var reloadGuidePreview = function() {
            var previewContainer = document.querySelector('[data-guide-preview-container]');
            if (!guidePreviewReloadButton || !previewContainer) {
                return;
            }

            var reloadUrl = new window.URL(window.location.href);
            reloadUrl.searchParams.set('_preview_refresh', String(Date.now()));
            guidePreviewReloadButton.disabled = true;
            guidePreviewReloadButton.classList.add('opacity-60');

            window.fetch(reloadUrl.toString(), {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            }).then(function(response) {
                return response.text();
            }).then(function(html) {
                var parser = new window.DOMParser();
                var doc = parser.parseFromString(html, 'text/html');
                var freshPreview = doc.querySelector('[data-guide-preview-container]');
                if (!freshPreview) {
                    throw new Error('preview_not_found');
                }
                previewContainer.replaceWith(freshPreview);
            }).catch(function() {
                // silent fallback for now, the page state remains unchanged
            }).finally(function() {
                guidePreviewReloadButton.disabled = false;
                guidePreviewReloadButton.classList.remove('opacity-60');
            });
        };

        [pathField, altField, captionField].forEach(function(field) {
            field.addEventListener('input', updateOutput);
        });

        if (targetModeField) {
            targetModeField.addEventListener('change', updateTargetHelp);
        }
        if (targetHeadingField) {
            targetHeadingField.addEventListener('change', syncTargetValue);
        }
        if (targetMarkerField) {
            targetMarkerField.addEventListener('input', syncTargetValue);
        }

        if (copyButton && navigator.clipboard) {
            copyButton.addEventListener('click', function() {
                outputField.select();
                navigator.clipboard.writeText(outputField.value).then(function() {
                    copyButton.dataset.originalLabel = copyButton.dataset.originalLabel || copyButton.textContent.trim();
                    copyButton.lastChild.textContent = 'Copié';
                    window.setTimeout(function() {
                        copyButton.lastChild.textContent = copyButton.dataset.originalLabel;
                    }, 1600);
                });
            });
        }

        if (uploadField) {
            uploadField.addEventListener('change', function(event) {
                var file = event.target.files && event.target.files[0] ? event.target.files[0] : null;
                if (!file) {
                    setPreviewState(previewImage && !previewImage.classList.contains('hidden') && !previewImage.src.startsWith('blob:') ? previewImage.src : '', pathField.value.trim());
                    return;
                }

                var reader = new window.FileReader();
                reader.onload = function(loadEvent) {
                    setPreviewState(typeof loadEvent.target.result === 'string' ? loadEvent.target.result : '', file.name + ' • aperçu local avant envoi');
                };
                reader.readAsDataURL(file);
            });
        }

        if (guidePreviewReloadButton) {
            guidePreviewReloadButton.addEventListener('click', reloadGuidePreview);
        }

        updateTargetHelp();
        syncTargetValue();
        updateOutput();
    });
</script>

<link rel="stylesheet" href="<?= htmlspecialchars($appBasePath . '/formation/assets/academy-theme.css?v=' . filemtime(__DIR__.'/assets/academy-theme.css'), ENT_QUOTES, 'UTF-8') ?>">
<script src="<?= htmlspecialchars($appBasePath . '/formation/assets/academy-theme.js?v=' . filemtime(__DIR__.'/assets/academy-theme.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<?php require_once $projectRoot . '/decaissement/includes/footer.php'; ?>
