<?php
require_once dirname(__DIR__) . '/model/Database.php';
require_once dirname(__DIR__) . '/model/AuditTrail.php';
require_once dirname(__DIR__) . '/model/DocumentManager.php';

$token = trim((string)($_GET['t'] ?? ''));
if ($token === '') {
    http_response_code(404);
    echo 'Lien invalide.';
    exit;
}

$pdo = \Database::getConnection();
$manager = new \DocumentManager($pdo);
$manager->ensureTables();

$shareUiAssets = [
    'tailwind' => '../js/vendor/documentation/tailwindcss.browser.js',
    'mammoth' => '../js/vendor/documentation/mammoth.browser.min.js',
    'xlsx' => '../js/vendor/documentation/xlsx.full.min.js',
    'pdfjs' => '../js/vendor/documentation/pdf.min.js',
    'pdfWorker' => '../js/vendor/documentation/pdf.worker.min.js',
];

$share = $manager->getShareByToken($token);
if (!$share) {
    http_response_code(404);
    echo 'Lien expiré, révoqué ou introuvable.';
    exit;
}

$flashSuccess = '';
$flashError = '';

$absolutePath = (string)$share['absolute_path'];
$downloadName = (string)($share['original_name'] ?? 'document');
$mimeType = trim((string)($share['mime_type'] ?? 'application/octet-stream'));
$allowDownload = !empty($share['allow_download']);
$canView = !array_key_exists('can_view', $share) || !empty($share['can_view']);
$canEdit = !empty($share['can_edit']);
$canReshare = !empty($share['can_reshare']);
$extension = strtolower((string)($share['extension'] ?? pathinfo($downloadName, PATHINFO_EXTENSION)));
$downloadRequested = !empty($_GET['download']);
$rawRequested = !empty($_GET['raw']);

if (!$canView) {
    http_response_code(403);
    echo 'La visualisation n\'est pas autorisée pour ce lien.';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!$canEdit) {
            \AuditTrail::log('documentation_share_edit_denied', [
                'module' => 'documentation',
                'action' => 'share_edit_denied',
                'entity_type' => 'doc_document',
                'entity_id' => (string)($share['document_id'] ?? 0),
                'entity_label' => (string)($share['title'] ?? $downloadName),
                'details' => ['token' => $token],
            ]);
            throw new RuntimeException('Ce lien ne permet pas de modifier le document.');
        }

        $action = trim((string)($_POST['action'] ?? ''));
        if ($action !== 'edit_document') {
            throw new RuntimeException('Action non autorisée.');
        }

        $result = $manager->updateSharedDocument(
            (int)$share['id'],
            ['title' => trim((string)($_POST['title'] ?? ''))],
            isset($_FILES['document_file']) && is_array($_FILES['document_file']) ? $_FILES['document_file'] : null,
            null
        );

        \AuditTrail::log('documentation_share_edited', [
            'module' => 'documentation',
            'action' => 'share_edit',
            'entity_type' => 'doc_document',
            'entity_id' => (string)($result['document_id'] ?? ($share['document_id'] ?? 0)),
            'entity_label' => (string)($result['title'] ?? ($share['title'] ?? $downloadName)),
            'details' => [
                'token' => $token,
                'updated_title' => !empty($result['updated_title']),
                'updated_file' => !empty($result['updated_file']),
            ],
        ]);

        $redirectQuery = [
            't' => $token,
            'updated' => 1,
        ];
        if (!empty($result['updated_title'])) {
            $redirectQuery['title_updated'] = 1;
        }
        if (!empty($result['updated_file'])) {
            $redirectQuery['file_updated'] = 1;
        }
        header('Location: documentation_share.php?' . http_build_query($redirectQuery));
        exit;
    } catch (Throwable $e) {
        $flashError = $e->getMessage();
    }

    $share = $manager->getShareByToken($token);
    if (!$share) {
        http_response_code(404);
        echo 'Lien expiré, révoqué ou introuvable.';
        exit;
    }

    $absolutePath = (string)$share['absolute_path'];
    $downloadName = (string)($share['original_name'] ?? 'document');
    $mimeType = trim((string)($share['mime_type'] ?? 'application/octet-stream'));
    $allowDownload = !empty($share['allow_download']);
    $canView = !array_key_exists('can_view', $share) || !empty($share['can_view']);
    $canEdit = !empty($share['can_edit']);
    $canReshare = !empty($share['can_reshare']);
    $extension = strtolower((string)($share['extension'] ?? pathinfo($downloadName, PATHINFO_EXTENSION)));
    $downloadRequested = !empty($_GET['download']);
    $rawRequested = !empty($_GET['raw']);
}

$manager->markShareAccessed((int)$share['id']);
\AuditTrail::log('documentation_share_view', [
    'module' => 'documentation',
    'action' => 'share_view',
    'entity_type' => 'doc_document',
    'entity_id' => (string)($share['document_id'] ?? 0),
    'entity_label' => (string)($share['title'] ?? $downloadName),
    'details' => ['token' => $token],
]);

if (!empty($_GET['updated'])) {
    $parts = [];
    if (!empty($_GET['title_updated'])) {
        $parts[] = 'titre mis à jour';
    }
    if (!empty($_GET['file_updated'])) {
        $parts[] = 'nouvelle version envoyée';
    }
    $flashSuccess = $parts ? 'Modification enregistrée : ' . implode(' et ', $parts) . '.' : 'Modification enregistrée avec succès.';
}

if ($downloadRequested) {
    if (!$allowDownload) {
        \AuditTrail::log('documentation_share_download_denied', [
            'module' => 'documentation',
            'action' => 'share_download_denied',
            'entity_type' => 'doc_document',
            'entity_id' => (string)($share['document_id'] ?? 0),
            'entity_label' => (string)($share['title'] ?? $downloadName),
            'details' => [
                'token' => $token,
            ],
        ]);
        http_response_code(403);
        echo 'Le téléchargement n\'est pas autorisé pour ce lien.';
        exit;
    }

    \AuditTrail::log('documentation_share_download', [
        'module' => 'documentation',
        'action' => 'share_download',
        'entity_type' => 'doc_document',
        'entity_id' => (string)($share['document_id'] ?? 0),
        'entity_label' => (string)($share['title'] ?? $downloadName),
    ]);

    header('Content-Description: File Transfer');
    header('Content-Type: ' . ($mimeType !== '' ? $mimeType : 'application/octet-stream'));
    header('Content-Disposition: attachment; filename="' . rawurlencode($downloadName) . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
    header('Content-Length: ' . (string)filesize($absolutePath));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    header('Expires: 0');
    readfile($absolutePath);
    exit;
}

$isImage = in_array($extension, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'svg'], true);
$isVideo = in_array($extension, ['mp4', 'webm', 'mov', 'avi', 'mkv', 'm4v', 'ogv'], true);
$isPdf = $extension === 'pdf';
$isText = in_array($extension, ['txt', 'md', 'log', 'csv', 'json', 'xml'], true);
$isWord = $extension === 'docx';
$isExcel = in_array($extension, ['xls', 'xlsx'], true);
$isZip = $extension === 'zip';
$isArchiveDownloadOnly = in_array($extension, ['rar', '7z'], true);
$isExecutable = in_array($extension, ['exe', 'msi', 'bat', 'cmd', 'ps1'], true);

if ($rawRequested) {
    \AuditTrail::log('documentation_share_view', [
        'module' => 'documentation',
        'action' => 'share_raw_preview',
        'entity_type' => 'doc_document',
        'entity_id' => (string)($share['document_id'] ?? 0),
        'entity_label' => (string)($share['title'] ?? $downloadName),
        'details' => ['token' => $token],
    ]);
    header('Content-Type: ' . ($mimeType !== '' ? $mimeType : 'application/octet-stream'));
    header('Content-Disposition: inline; filename="' . rawurlencode($downloadName) . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
    header('Content-Length: ' . (string)filesize($absolutePath));
    header('Cache-Control: private, max-age=0, must-revalidate');
    readfile($absolutePath);
    exit;
}

$textPreview = null;
if ($isText) {
    $textPreview = @file_get_contents($absolutePath, false, null, 0, 100000);
}

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ($_SERVER['SERVER_PORT'] ?? null) == 443;

$protocol = $https ? 'https://' : 'http://';

$host = $_SERVER['HTTP_HOST'];

$baseUrl = $protocol . $host;
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="<?= $baseUrl ?>/img/app_icon.png">
    <title><?= htmlspecialchars((string)($share['title'] ?? $downloadName), ENT_QUOTES, 'UTF-8') ?></title>
    <script src="<?= htmlspecialchars($shareUiAssets['tailwind'], ENT_QUOTES, 'UTF-8') ?>"></script>
</head>

<body class="min-h-screen bg-slate-100 text-slate-900">
    <div class="mx-auto max-w-7xl px-4 py-8">
        <?php if ($flashSuccess !== ''): ?>
            <div class="mb-5 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800"><?= htmlspecialchars($flashSuccess, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <?php if ($flashError !== ''): ?>
            <div class="mb-5 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800"><?= htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
            <main class="rounded-3xl border border-slate-200 bg-white p-6 shadow-[0_18px_60px_rgba(15,23,42,0.08)]">
                <div class="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">Partage externe</div>
                <h1 class="mt-2 text-2xl font-bold text-slate-900"><?= htmlspecialchars((string)($share['title'] ?? $downloadName), ENT_QUOTES, 'UTF-8') ?></h1>
                <div class="mt-2 text-sm text-slate-500">
                    <?= htmlspecialchars((string)$downloadName, ENT_QUOTES, 'UTF-8') ?>
                    <?php if (!empty($share['expires_at'])): ?>
                        • Expire le <?= htmlspecialchars((string)$share['expires_at'], ENT_QUOTES, 'UTF-8') ?>
                    <?php endif; ?>
                </div>
                <?php if (!empty($share['recipient_name']) || !empty($share['recipient_email'])): ?>
                    <div class="mt-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
                        <span class="font-semibold text-slate-900">Destinataire interne prévu :</span>
                        <?= htmlspecialchars((string)($share['recipient_name'] ?: $share['recipient_email']), ENT_QUOTES, 'UTF-8') ?>
                        <?php if (!empty($share['recipient_email']) && ($share['recipient_email'] !== $share['recipient_name'])): ?>
                            <span class="text-slate-500">(<?= htmlspecialchars((string)$share['recipient_email'], ENT_QUOTES, 'UTF-8') ?>)</span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="mt-4 flex flex-wrap gap-2">
                    <span class="inline-flex items-center rounded-full bg-sky-100 px-3 py-1 text-xs font-semibold text-sky-800"><?= $isExecutable ? 'Partage technique' : 'Visualisation' ?></span>
                    <?php if ($allowDownload): ?>
                        <span class="inline-flex items-center rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-800">Téléchargement</span>
                    <?php endif; ?>
                    <?php if ($canEdit): ?>
                        <span class="inline-flex items-center rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-800">Édition autorisée</span>
                    <?php endif; ?>
                    <?php if ($canReshare): ?>
                        <span class="inline-flex items-center rounded-full bg-fuchsia-100 px-3 py-1 text-xs font-semibold text-fuchsia-800">Repartage autorisé</span>
                    <?php endif; ?>
                    <?php if (!$allowDownload && !$canEdit && !$canReshare): ?>
                        <span class="inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700"><?= $isExecutable ? 'Partage sans téléchargement' : 'Visualisation uniquement' ?></span>
                    <?php endif; ?>
                </div>

                <div class="mt-6 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                    <?php if ($textPreview !== null): ?>
                        <pre class="max-h-[72vh] overflow-auto whitespace-pre-wrap text-sm text-slate-700"><?= htmlspecialchars($textPreview, ENT_QUOTES, 'UTF-8') ?></pre>
                    <?php elseif ($isImage): ?>
                        <div class="space-y-3">
                            <?php if (!$allowDownload): ?>
                                <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">Le téléchargement n'est pas autorisé sur ce lien. L'image reste consultable sans bouton de téléchargement.</div>
                            <?php endif; ?>
                            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white p-3" <?= !$allowDownload ? ' oncontextmenu="return false;"' : '' ?>>
                                <img src="documentation_share.php?t=<?= rawurlencode($token) ?>&raw=1" alt="<?= htmlspecialchars($downloadName, ENT_QUOTES, 'UTF-8') ?>" class="max-h-[72vh] w-full rounded-2xl object-contain" <?= !$allowDownload ? ' draggable="false"' : '' ?>>
                            </div>
                        </div>
                    <?php elseif ($isVideo): ?>
                        <div class="space-y-3">
                            <?php if (!$allowDownload): ?>
                                <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">La vidéo est lisible via ce lien, mais son téléchargement n'est pas accordé. Les commandes de téléchargement sont neutralisées dans le lecteur.</div>
                            <?php endif; ?>
                            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-slate-950 p-3" <?= !$allowDownload ? ' oncontextmenu="return false;"' : '' ?>>
                                <video src="documentation_share.php?t=<?= rawurlencode($token) ?>&raw=1" controls preload="metadata" class="max-h-[72vh] w-full rounded-2xl bg-black" <?= !$allowDownload ? ' controlsList="nodownload noplaybackrate" disablepictureinpicture playsinline' : ' playsinline' ?>>Votre navigateur ne peut pas lire cette vidéo.</video>
                            </div>
                        </div>
                    <?php elseif ($isPdf): ?>
                        <div class="space-y-3">
                            <?php if (!$allowDownload): ?>
                                <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">Le téléchargement n'est pas autorisé sur ce lien. Le clic droit est désactivé sur la prévisualisation PDF.</div>
                            <?php endif; ?>
                            <div id="public-pdf-preview" class="min-h-[72vh] rounded-2xl bg-white p-4 text-sm text-slate-500" <?= !$allowDownload ? ' oncontextmenu="return false;"' : '' ?>>Chargement de la prévisualisation PDF...</div>
                        </div>
                    <?php elseif ($isWord || $isExcel): ?>
                        <div id="public-office-preview" class="min-h-[72vh] rounded-2xl bg-white p-4 text-sm text-slate-500">Chargement de la prévisualisation...</div>
                    <?php elseif ($isZip): ?>
                        <div id="public-zip-preview" class="min-h-[72vh] rounded-2xl bg-white p-4 text-sm text-slate-500">Chargement du contenu de l'archive ZIP...</div>
                    <?php elseif ($isExecutable && $allowDownload): ?>
                        <p class="text-sm text-slate-600">Ce fichier exécutable ou script ne peut pas être prévisualisé pour des raisons de sécurité. Le lien sert uniquement au partage et au téléchargement contrôlé.</p>
                    <?php elseif ($isExecutable): ?>
                        <p class="text-sm text-slate-600">Ce fichier exécutable ou script est bien partageable, mais il n'est pas prévisualisé et le téléchargement n'a pas été accordé sur ce lien.</p>
                    <?php elseif ($isArchiveDownloadOnly && $allowDownload): ?>
                        <p class="text-sm text-slate-600">Cette archive peut être téléchargée via ce lien. L'exploration interne n'est disponible que pour les ZIP.</p>
                    <?php elseif ($allowDownload): ?>
                        <p class="text-sm text-slate-600">La prévisualisation n'est pas disponible pour ce type de fichier. Le téléchargement vous a été explicitement accordé sur ce lien.</p>
                    <?php else: ?>
                        <p class="text-sm text-slate-600"><?= $isArchiveDownloadOnly ? 'Cette archive ne peut pas être explorée sur ce serveur et le téléchargement n\'a pas été octroyé pour ce lien.' : 'La prévisualisation n\'est pas disponible pour ce type de fichier et le téléchargement n\'a pas été octroyé pour ce lien.' ?></p>
                    <?php endif; ?>
                </div>
            </main>

            <aside class="xl:sticky xl:top-6 xl:self-start">
                <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-[0_18px_60px_rgba(15,23,42,0.08)]">
                    <div class="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">Actions du lien</div>
                    <h2 class="mt-2 text-xl font-bold text-slate-900">Commandes disponibles</h2>
                    <p class="mt-2 text-sm text-slate-600">Les actions importantes restent visibles à côté du document pour éviter de devoir descendre sous la prévisualisation.</p>

                    <div class="mt-5 space-y-3">
                        <?php if ($allowDownload): ?>
                            <a href="documentation_share.php?t=<?= rawurlencode($token) ?>&download=1" class="inline-flex w-full items-center justify-center rounded-2xl bg-slate-950 px-5 py-3 text-sm font-semibold text-white hover:bg-slate-800">Télécharger</a>
                        <?php endif; ?>
                        <?php if ($canReshare): ?>
                            <button type="button" id="share-copy-link" class="inline-flex w-full items-center justify-center rounded-2xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-100">Repartager ce lien</button>
                        <?php endif; ?>
                    </div>

                    <?php if ($canEdit): ?>
                        <div class="mt-6 rounded-2xl border border-amber-200 bg-amber-50 p-5">
                            <div class="text-xs font-semibold uppercase tracking-[0.18em] text-amber-700">Workflow d'édition</div>
                            <h3 class="mt-2 text-lg font-bold text-slate-900">Modifier ce document depuis le lien</h3>
                            <p class="mt-2 text-sm text-slate-600">Ce lien autorise la mise à jour du titre et l'envoi d'une nouvelle version du fichier. Vous pouvez faire l'un, l'autre, ou les deux.</p>
                            <form method="post" enctype="multipart/form-data" class="mt-4 space-y-4">
                                <input type="hidden" name="action" value="edit_document">
                                <div>
                                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Titre du document</label>
                                    <input type="text" name="title" value="<?= htmlspecialchars((string)($share['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-amber-300">
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Nouvelle version du fichier</label>
                                    <input type="file" name="document_file" class="block w-full rounded-2xl border border-dashed border-slate-300 bg-white px-4 py-3 text-sm text-slate-600">
                                    <p class="mt-2 text-xs text-slate-500">Si vous laissez ce champ vide, seul le titre sera modifié.</p>
                                </div>
                                <button type="submit" class="inline-flex w-full items-center justify-center rounded-2xl bg-amber-500 px-5 py-3 text-sm font-semibold text-white hover:bg-amber-600">Enregistrer les modifications</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            </aside>
        </div>
    </div>

    <?php if ($isPdf || $isWord || $isExcel || $isZip || $canReshare): ?>
        <script>
            (function() {
                const copyButton = document.getElementById('share-copy-link');
                if (copyButton) {
                    copyButton.addEventListener('click', function() {
                        const url = new URL(window.location.href);
                        url.search = '?t=<?= rawurlencode($token) ?>';
                        const link = url.toString();
                        if (navigator.clipboard) {
                            navigator.clipboard.writeText(link);
                            return;
                        }
                        window.prompt('Copiez ce lien :', link);
                    });
                }

                function loadScript(src, key) {
                    if (window[key]) {
                        return Promise.resolve();
                    }
                    return new Promise(function(resolve, reject) {
                        const script = document.createElement('script');
                        script.src = src;
                        script.async = true;
                        script.onload = resolve;
                        script.onerror = reject;
                        document.head.appendChild(script);
                    });
                }

                function escapeHtml(value) {
                    return String(value || '').replace(/[&<>"']/g, function(char) {
                        return ({
                            '&': '&amp;',
                            '<': '&lt;',
                            '>': '&gt;',
                            '"': '&quot;',
                            "'": '&#039;'
                        })[char] || char;
                    });
                }

                function formatFileSize(value) {
                    const size = Number(value || 0);
                    if (!Number.isFinite(size) || size <= 0) {
                        return '0 o';
                    }
                    const units = ['o', 'Ko', 'Mo', 'Go', 'To'];
                    let current = size;
                    let unitIndex = 0;
                    while (current >= 1024 && unitIndex < units.length - 1) {
                        current /= 1024;
                        unitIndex += 1;
                    }
                    const precision = current >= 10 || unitIndex === 0 ? 0 : 1;
                    return current.toFixed(precision).replace('.', ',') + ' ' + units[unitIndex];
                }

                function renderPdfPreview(buffer) {
                    const previewNode = document.getElementById('public-pdf-preview');
                    if (!previewNode) {
                        return Promise.resolve();
                    }
                    return loadScript('<?= htmlspecialchars($shareUiAssets['pdfjs'], ENT_QUOTES, 'UTF-8') ?>', 'pdfjsLib').then(function() {
                        window.pdfjsLib.GlobalWorkerOptions.workerSrc = '<?= htmlspecialchars($shareUiAssets['pdfWorker'], ENT_QUOTES, 'UTF-8') ?>';
                        return window.pdfjsLib.getDocument({
                            data: buffer
                        }).promise;
                    }).then(function(pdf) {
                        previewNode.innerHTML = '';
                        let chain = Promise.resolve();
                        const pagesToRender = Math.min(pdf.numPages || 0, 6);
                        for (let pageNumber = 1; pageNumber <= pagesToRender; pageNumber += 1) {
                            chain = chain.then(function() {
                                return pdf.getPage(pageNumber).then(function(page) {
                                    const viewport = page.getViewport({
                                        scale: 1.2
                                    });
                                    const wrapper = document.createElement('div');
                                    wrapper.className = 'mb-4 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm';
                                    const canvas = document.createElement('canvas');
                                    canvas.width = viewport.width;
                                    canvas.height = viewport.height;
                                    canvas.className = 'block h-auto w-full';
                                    <?php if (!$allowDownload): ?>
                                        canvas.setAttribute('draggable', 'false');
                                        canvas.addEventListener('contextmenu', function(event) {
                                            event.preventDefault();
                                        });
                                    <?php endif; ?>
                                    wrapper.appendChild(canvas);
                                    <?php if (!$allowDownload): ?>
                                        wrapper.addEventListener('contextmenu', function(event) {
                                            event.preventDefault();
                                        });
                                    <?php endif; ?>
                                    previewNode.appendChild(wrapper);
                                    return page.render({
                                        canvasContext: canvas.getContext('2d'),
                                        viewport: viewport
                                    }).promise;
                                });
                            });
                        }
                        return chain;
                    }).catch(function() {
                        previewNode.textContent = 'Prévisualisation PDF indisponible pour ce document.';
                    });
                }

                function renderZipPreview(payload) {
                    const previewNode = document.getElementById('public-zip-preview');
                    if (!previewNode) {
                        return;
                    }
                    const entries = Array.isArray(payload && payload.entries) ? payload.entries : [];
                    const allowDownload = !!(payload && payload.allowDownload);
                    previewNode.innerHTML =
                        '<div class="space-y-4">' +
                        '<div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-4">' +
                        '<div class="text-xs font-semibold uppercase tracking-[0.18em] text-amber-700">Archive ZIP</div>' +
                        '<div class="mt-2 text-lg font-bold text-amber-950">' + escapeHtml(payload && payload.archiveName ? payload.archiveName : 'Archive ZIP') + '</div>' +
                        '<div class="mt-2 text-sm text-amber-900">Explorez le contenu de l\'archive directement depuis ce lien partagé.' + (allowDownload ? ' Les fichiers internes peuvent aussi être extraits.' : ' L\'extraction est désactivée sur ce lien.') + '</div>' +
                        '</div>' +
                        '<div class="overflow-hidden rounded-2xl border border-slate-200 bg-white">' +
                        '<div class="grid grid-cols-[minmax(0,2fr)_120px_150px] gap-0 border-b border-slate-200 bg-slate-100 px-4 py-3 text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">' +
                        '<div>Contenu</div>' +
                        '<div>Taille</div>' +
                        '<div>Action</div>' +
                        '</div>' +
                        (entries.length ? entries.map(function(entry) {
                            const icon = entry.isDirectory ? 'fa-folder' : 'fa-file';
                            const sizeLabel = entry.isDirectory ? '—' : formatFileSize(entry.size);
                            const action = entry.isDirectory ?
                                '<span class="text-xs text-slate-400">Dossier</span>' :
                                (allowDownload && entry.downloadUrl ?
                                    '<a href="' + escapeHtml(entry.downloadUrl) + '" class="inline-flex items-center rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50"><i class="fas fa-file-export mr-1.5"></i>Extraire</a>' :
                                    '<span class="text-xs text-slate-400">Lecture seule</span>');
                            return '<div class="grid grid-cols-[minmax(0,2fr)_120px_150px] gap-0 border-b border-slate-100 px-4 py-3 last:border-b-0">' +
                                '<div class="min-w-0 pr-4"><div class="flex items-center gap-2 truncate text-sm font-medium text-slate-800"><i class="fas ' + icon + ' text-slate-400"></i><span class="truncate">' + escapeHtml(entry.name || '') + '</span></div></div>' +
                                '<div class="text-xs text-slate-500">' + escapeHtml(sizeLabel) + '</div>' +
                                '<div>' + action + '</div>' +
                                '</div>';
                        }).join('') : '<div class="px-4 py-6 text-sm text-slate-500">Cette archive ZIP est vide.</div>') +
                        '</div>' +
                        '</div>';
                }

                <?php if (!$allowDownload && $isPdf): ?>
                    const pdfRoot = document.getElementById('public-pdf-preview');
                    if (pdfRoot) {
                        pdfRoot.addEventListener('contextmenu', function(event) {
                            event.preventDefault();
                        });
                    }
                <?php endif; ?>

                const previewNode = document.getElementById('public-office-preview');
                const zipPreviewNode = document.getElementById('public-zip-preview');

                <?php if ($isZip): ?>
                    fetch('documentation_archive_api.php?t=<?= rawurlencode($token) ?>&action=list', {
                        headers: {
                            'Accept': 'application/json'
                        }
                    }).then(function(response) {
                        return response.json();
                    }).then(function(payload) {
                        if (!payload || !payload.success) {
                            throw new Error(payload && payload.message ? payload.message : 'Exploration ZIP indisponible');
                        }
                        renderZipPreview(payload);
                    }).catch(function(error) {
                        if (zipPreviewNode) {
                            zipPreviewNode.textContent = error.message || 'Prévisualisation ZIP indisponible.';
                        }
                    });
                <?php else: ?>
                    fetch('documentation_share.php?t=<?= rawurlencode($token) ?>&raw=1').then(function(response) {
                        if (!response.ok) {
                            throw new Error('Prévisualisation indisponible');
                        }
                        return response.arrayBuffer();
                    }).then(function(buffer) {
                        <?php if ($isPdf): ?>
                            return renderPdfPreview(buffer);
                        <?php elseif ($isWord): ?>
                            return loadScript('<?= htmlspecialchars($shareUiAssets['mammoth'], ENT_QUOTES, 'UTF-8') ?>', 'mammoth').then(function() {
                                return window.mammoth.convertToHtml({
                                    arrayBuffer: buffer
                                });
                            }).then(function(result) {
                                previewNode.innerHTML = '<article class="prose prose-slate max-w-none">' + (result.value || '<p>Document vide.</p>') + '</article>';
                            });
                        <?php else: ?>
                            return loadScript('<?= htmlspecialchars($shareUiAssets['xlsx'], ENT_QUOTES, 'UTF-8') ?>', 'XLSX').then(function() {
                                const workbook = window.XLSX.read(buffer, {
                                    type: 'array'
                                });
                                const firstSheetName = workbook.SheetNames[0];
                                const firstSheet = firstSheetName ? workbook.Sheets[firstSheetName] : null;
                                if (!firstSheet) {
                                    previewNode.innerHTML = 'Feuille vide.';
                                    return;
                                }
                                previewNode.innerHTML = '<div class="overflow-auto">' + window.XLSX.utils.sheet_to_html(firstSheet, {
                                    editable: false,
                                    header: '',
                                    footer: ''
                                }) + '</div>';
                            });
                        <?php endif; ?>
                    }).catch(function() {
                        const pdfNode = document.getElementById('public-pdf-preview');
                        if (pdfNode) {
                            pdfNode.textContent = 'Prévisualisation indisponible pour ce document.';
                        }
                        if (previewNode) {
                            previewNode.textContent = 'Prévisualisation indisponible pour ce document.';
                        }
                    });
                <?php endif; ?>
            })();
        </script>
    <?php endif; ?>
</body>

</html>