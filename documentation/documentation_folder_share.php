<?php
require_once dirname(__DIR__) . '/model/Database.php';
require_once dirname(__DIR__) . '/model/AuditTrail.php';
require_once dirname(__DIR__) . '/model/DocumentManager.php';

function documentationFormatBytes($bytes)
{
    $size = (float)$bytes;
    $units = ['o', 'Ko', 'Mo', 'Go', 'To'];
    $index = 0;
    while ($size >= 1024 && $index < count($units) - 1) {
        $size /= 1024;
        $index++;
    }
    return number_format($size, $index === 0 ? 0 : 1, ',', ' ') . ' ' . $units[$index];
}

function documentationFileVisualMeta($filename, $mimeType = '')
{
    $extension = strtolower((string)pathinfo((string)$filename, PATHINFO_EXTENSION));
    $mimeType = strtolower(trim((string)$mimeType));

    if ($extension === 'pdf') {
        return ['label' => 'PDF', 'bg' => 'bg-rose-100', 'text' => 'text-rose-700', 'border' => 'border-rose-200'];
    }
    if (in_array($extension, ['doc', 'docx'], true)) {
        return ['label' => 'DOC', 'bg' => 'bg-sky-100', 'text' => 'text-sky-700', 'border' => 'border-sky-200'];
    }
    if (in_array($extension, ['xls', 'xlsx', 'csv'], true)) {
        return ['label' => 'XLS', 'bg' => 'bg-emerald-100', 'text' => 'text-emerald-700', 'border' => 'border-emerald-200'];
    }
    if (in_array($extension, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'svg'], true) || strpos($mimeType, 'image/') === 0) {
        return ['label' => 'IMG', 'bg' => 'bg-violet-100', 'text' => 'text-violet-700', 'border' => 'border-violet-200'];
    }
    if (in_array($extension, ['txt', 'md', 'log', 'json', 'xml'], true) || strpos($mimeType, 'text/') === 0) {
        return ['label' => 'TXT', 'bg' => 'bg-amber-100', 'text' => 'text-amber-700', 'border' => 'border-amber-200'];
    }
    return ['label' => strtoupper($extension !== '' ? substr($extension, 0, 4) : 'FIC'), 'bg' => 'bg-slate-100', 'text' => 'text-slate-700', 'border' => 'border-slate-200'];
}

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
    'tailwind' => 'js/vendor/documentation/tailwindcss.browser.js',
    'mammoth' => 'js/vendor/documentation/mammoth.browser.min.js',
    'xlsx' => 'js/vendor/documentation/xlsx.full.min.js',
    'pdfjs' => 'js/vendor/documentation/pdf.min.js',
    'pdfWorker' => 'js/vendor/documentation/pdf.worker.min.js',
];

$share = $manager->getFolderShareByToken($token);
if (!$share) {
    http_response_code(404);
    echo 'Lien expiré, révoqué ou introuvable.';
    exit;
}

$allowDownload = !empty($share['allow_download']);
$canView = !array_key_exists('can_view', $share) || !empty($share['can_view']);
$canReshare = !empty($share['can_reshare']);
if (!$canView) {
    http_response_code(403);
    echo 'La visualisation n\'est pas autorisée pour ce lien.';
    exit;
}

$rootFolderId = (int)($share['folder_id'] ?? 0);
$currentFolderId = (int)($_GET['folder'] ?? $rootFolderId);
$documentId = (int)($_GET['doc'] ?? 0);
$downloadRequested = !empty($_GET['download']);
$rawRequested = !empty($_GET['raw']);

$manager->markFolderShareAccessed((int)$share['id']);
\AuditTrail::log('documentation_share_view', [
    'module' => 'documentation',
    'action' => 'folder_share_view',
    'entity_type' => 'doc_folder',
    'entity_id' => (string)($share['folder_id'] ?? 0),
    'entity_label' => (string)($share['folder_name'] ?? 'Dossier partagÃ©'),
    'details' => ['token' => $token, 'folder' => $currentFolderId, 'document_id' => $documentId],
]);

$contents = $manager->listFolderShareContents($rootFolderId, $currentFolderId);
$selectedDocument = $documentId > 0 ? $manager->getDocumentForFolderShare($rootFolderId, $documentId) : null;
if ($documentId > 0 && !$selectedDocument) {
    http_response_code(404);
    echo 'Document introuvable dans ce partage.';
    exit;
}

$textPreview = null;
$isImage = false;
$isPdf = false;
$isText = false;
$isWord = false;
$isExcel = false;
$downloadName = '';
$mimeType = 'application/octet-stream';
$absolutePath = '';
$extension = '';

if ($selectedDocument) {
    $downloadName = (string)($selectedDocument['original_name'] ?? $selectedDocument['title'] ?? 'document');
    $mimeType = trim((string)($selectedDocument['mime_type'] ?? 'application/octet-stream'));
    $absolutePath = (string)($selectedDocument['absolute_path'] ?? '');
    $extension = strtolower((string)($selectedDocument['extension'] ?? pathinfo($downloadName, PATHINFO_EXTENSION)));
    $isImage = in_array($extension, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'svg'], true);
    $isPdf = $extension === 'pdf';
    $isText = in_array($extension, ['txt', 'md', 'log', 'csv', 'json', 'xml'], true);
    $isWord = $extension === 'docx';
    $isExcel = in_array($extension, ['xls', 'xlsx'], true);

    if ($downloadRequested) {
        if (!$allowDownload) {
            \AuditTrail::log('documentation_share_download_denied', [
                'module' => 'documentation',
                'action' => 'folder_share_download_denied',
                'entity_type' => 'doc_document',
                'entity_id' => (string)($selectedDocument['id'] ?? 0),
                'entity_label' => (string)($selectedDocument['title'] ?? $downloadName),
                'result' => 'denied',
                'severity' => 'warning',
                'details' => ['token' => $token],
            ]);
            http_response_code(403);
            echo 'Le téléchargement n\'est pas autorisé pour ce lien.';
            exit;
        }
        \AuditTrail::log('documentation_share_download', [
            'module' => 'documentation',
            'action' => 'folder_share_download',
            'entity_type' => 'doc_document',
            'entity_id' => (string)($selectedDocument['id'] ?? 0),
            'entity_label' => (string)($selectedDocument['title'] ?? $downloadName),
            'details' => ['token' => $token],
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

    if ($rawRequested || $isImage) {
        \AuditTrail::log('documentation_share_view', [
            'module' => 'documentation',
            'action' => 'folder_share_preview',
            'entity_type' => 'doc_document',
            'entity_id' => (string)($selectedDocument['id'] ?? 0),
            'entity_label' => (string)($selectedDocument['title'] ?? $downloadName),
            'details' => ['token' => $token],
        ]);
        header('Content-Type: ' . ($mimeType !== '' ? $mimeType : 'application/octet-stream'));
        header('Content-Disposition: inline; filename="' . rawurlencode($downloadName) . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
        header('Content-Length: ' . (string)filesize($absolutePath));
        header('Cache-Control: private, max-age=0, must-revalidate');
        readfile($absolutePath);
        exit;
    }

    if ($isText && $absolutePath !== '' && is_file($absolutePath)) {
        $textPreview = @file_get_contents($absolutePath, false, null, 0, 100000);
    }
}
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars((string)($share['folder_name'] ?? 'Dossier partagé'), ENT_QUOTES, 'UTF-8') ?></title>
    <script src="<?= htmlspecialchars($shareUiAssets['tailwind'], ENT_QUOTES, 'UTF-8') ?>"></script>
</head>

<body class="min-h-screen bg-slate-100 text-slate-900">
    <div class="mx-auto max-w-7xl px-4 py-8">
        <div class="grid gap-6 lg:grid-cols-[360px_minmax(0,1fr)]">
            <aside class="rounded-3xl border border-slate-200 bg-white p-6 shadow-[0_18px_60px_rgba(15,23,42,0.08)]">
                <div class="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">Partage de dossier</div>
                <h1 class="mt-2 text-2xl font-bold text-slate-900"><?= htmlspecialchars((string)($share['folder_name'] ?? 'Dossier partagé'), ENT_QUOTES, 'UTF-8') ?></h1>
                <div class="mt-2 text-sm text-slate-500">
                    Accès en lecture
                    <?php if (!empty($share['expires_at'])): ?>
                        • Expire le <?= htmlspecialchars((string)$share['expires_at'], ENT_QUOTES, 'UTF-8') ?>
                    <?php endif; ?>
                </div>

                <?php if (!empty($share['recipient_name']) || !empty($share['recipient_email'])): ?>
                    <div class="mt-4 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
                        <span class="font-semibold text-slate-900">Destinataire interne prévu :</span>
                        <?= htmlspecialchars((string)($share['recipient_name'] ?: $share['recipient_email']), ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>

                <div class="mt-4 flex flex-wrap gap-2">
                    <span class="inline-flex items-center rounded-full bg-sky-100 px-3 py-1 text-xs font-semibold text-sky-800">Navigation</span>
                    <?php if ($allowDownload): ?>
                        <span class="inline-flex items-center rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-800">Téléchargement</span>
                    <?php endif; ?>
                    <?php if ($canReshare): ?>
                        <span class="inline-flex items-center rounded-full bg-fuchsia-100 px-3 py-1 text-xs font-semibold text-fuchsia-800">Repartage autorisé</span>
                    <?php endif; ?>
                </div>

                <div class="mt-6">
                    <div class="mb-2 text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">Arborescence</div>
                    <div class="flex flex-wrap gap-2 text-sm text-slate-600">
                        <?php foreach ($contents['breadcrumb'] as $crumb): ?>
                            <a href="documentation_folder_share.php?t=<?= rawurlencode($token) ?>&folder=<?= (int)$crumb['id'] ?>" class="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 hover:bg-slate-100"><?= htmlspecialchars((string)$crumb['name'], ENT_QUOTES, 'UTF-8') ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="mt-6 space-y-3">
                    <div>
                        <div class="mb-2 text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">Sous-dossiers</div>
                        <div class="space-y-2">
                            <?php if (empty($contents['folders'])): ?>
                                <div class="rounded-2xl border border-dashed border-slate-200 px-4 py-3 text-sm text-slate-500">Aucun sous-dossier visible.</div>
                            <?php else: ?>
                                <?php foreach ($contents['folders'] as $folder): ?>
                                    <a href="documentation_folder_share.php?t=<?= rawurlencode($token) ?>&folder=<?= (int)$folder['id'] ?>" class="flex items-center justify-between rounded-2xl border border-slate-200 px-4 py-3 text-sm text-slate-700 hover:border-slate-300 hover:bg-slate-50">
                                        <span class="font-medium"><?= htmlspecialchars((string)$folder['name'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <span class="text-xs uppercase tracking-[0.18em] text-slate-400">Dossier</span>
                                    </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div>
                        <div class="mb-2 text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">Documents</div>
                        <div class="space-y-2">
                            <?php if (empty($contents['documents'])): ?>
                                <div class="rounded-2xl border border-dashed border-slate-200 px-4 py-3 text-sm text-slate-500">Aucun document visible dans ce dossier.</div>
                            <?php else: ?>
                                <?php foreach ($contents['documents'] as $document): ?>
                                    <?php $fileMeta = documentationFileVisualMeta((string)($document['original_name'] ?? $document['title'] ?? ''), (string)($document['mime_type'] ?? '')); ?>
                                    <a href="documentation_folder_share.php?t=<?= rawurlencode($token) ?>&folder=<?= (int)($contents['current_folder']['id'] ?? $rootFolderId) ?>&doc=<?= (int)$document['id'] ?>" class="block rounded-2xl border px-4 py-3 text-sm hover:bg-slate-50 <?= $selectedDocument && (int)$selectedDocument['id'] === (int)$document['id'] ? 'border-slate-900 bg-slate-50 text-slate-900' : 'border-slate-200 text-slate-700' ?>">
                                        <div class="flex items-start gap-3">
                                            <div class="inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl border text-xs font-bold uppercase tracking-[0.18em] <?= htmlspecialchars($fileMeta['bg'], ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($fileMeta['text'], ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($fileMeta['border'], ENT_QUOTES, 'UTF-8') ?>">
                                                <?= htmlspecialchars($fileMeta['label'], ENT_QUOTES, 'UTF-8') ?>
                                            </div>
                                            <div class="min-w-0 flex-1">
                                                <div class="font-medium"><?= htmlspecialchars((string)($document['title'] ?? $document['original_name'] ?? 'Document'), ENT_QUOTES, 'UTF-8') ?></div>
                                                <div class="mt-1 text-xs text-slate-500">
                                                    <?= htmlspecialchars((string)($document['original_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                                    <?php if (!empty($document['file_size'])): ?>
                                                        • <?= htmlspecialchars(documentationFormatBytes((int)$document['file_size']), ENT_QUOTES, 'UTF-8') ?>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </aside>

            <main class="rounded-3xl border border-slate-200 bg-white p-6 shadow-[0_18px_60px_rgba(15,23,42,0.08)]">
                <?php if (!$selectedDocument): ?>
                    <div class="flex min-h-[70vh] items-center justify-center rounded-3xl border border-dashed border-slate-200 bg-slate-50 px-6 text-center text-slate-500">
                        Sélectionnez un document du dossier partagé pour le prévisualiser.
                    </div>
                <?php else: ?>
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <div class="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">Document du dossier</div>
                            <h2 class="mt-2 text-2xl font-bold text-slate-900"><?= htmlspecialchars((string)($selectedDocument['title'] ?? $downloadName), ENT_QUOTES, 'UTF-8') ?></h2>
                            <div class="mt-2 text-sm text-slate-500"><?= htmlspecialchars($downloadName, ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                        <?php if ($allowDownload): ?>
                            <a href="documentation_folder_share.php?t=<?= rawurlencode($token) ?>&folder=<?= (int)($contents['current_folder']['id'] ?? $rootFolderId) ?>&doc=<?= (int)$selectedDocument['id'] ?>&download=1" class="inline-flex items-center rounded-2xl bg-slate-950 px-5 py-3 text-sm font-semibold text-white hover:bg-slate-800">Télécharger</a>
                        <?php endif; ?>
                    </div>

                    <div class="mt-4 flex flex-wrap gap-2">
                        <span class="inline-flex items-center rounded-full bg-sky-100 px-3 py-1 text-xs font-semibold text-sky-800">Visualisation</span>
                        <?php if ($allowDownload): ?>
                            <span class="inline-flex items-center rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-800">Téléchargement</span>
                        <?php endif; ?>
                    </div>

                    <div class="mt-6 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                        <?php if ($textPreview !== null): ?>
                            <pre class="max-h-[60vh] overflow-auto whitespace-pre-wrap text-sm text-slate-700"><?= htmlspecialchars($textPreview, ENT_QUOTES, 'UTF-8') ?></pre>
                        <?php elseif ($isPdf): ?>
                            <div class="space-y-3">
                                <?php if (!$allowDownload): ?>
                                    <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">Le téléchargement n'est pas autorisé sur ce lien. Le clic droit est désactivé sur la prévisualisation PDF.</div>
                                <?php endif; ?>
                                <div id="public-pdf-preview" class="min-h-[60vh] rounded-2xl bg-white p-4 text-sm text-slate-500" <?= !$allowDownload ? ' oncontextmenu="return false;"' : '' ?>>Chargement de la prévisualisation PDF...</div>
                            </div>
                        <?php elseif ($isWord || $isExcel): ?>
                            <div id="public-office-preview" class="min-h-[60vh] rounded-2xl bg-white p-4 text-sm text-slate-500">Chargement de la prévisualisation...</div>
                        <?php elseif ($allowDownload): ?>
                            <p class="text-sm text-slate-600">La prévisualisation n'est pas disponible pour ce type de fichier. Le téléchargement vous a été explicitement accordé sur ce lien.</p>
                        <?php else: ?>
                            <p class="text-sm text-slate-600">La prévisualisation n'est pas disponible pour ce type de fichier et le téléchargement n'a pas été octroyé pour ce lien.</p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <?php if ($selectedDocument && ($isPdf || $isWord || $isExcel || $canReshare)): ?>
        <script>
            (function() {
                function loadScript(src, key) {
                    if (window[key]) {
                        return window.Promise.resolve();
                    }
                    return new window.Promise(function(resolve, reject) {
                        const script = document.createElement('script');
                        script.src = src;
                        script.async = true;
                        script.onload = resolve;
                        script.onerror = reject;
                        document.head.appendChild(script);
                    });
                }

                function renderPdfPreview(buffer) {
                    const previewNode = document.getElementById('public-pdf-preview');
                    if (!previewNode) {
                        return window.Promise.resolve();
                    }
                    return loadScript('<?= htmlspecialchars($shareUiAssets['pdfjs'], ENT_QUOTES, 'UTF-8') ?>', 'pdfjsLib').then(function() {
                        window.pdfjsLib.GlobalWorkerOptions.workerSrc = '<?= htmlspecialchars($shareUiAssets['pdfWorker'], ENT_QUOTES, 'UTF-8') ?>';
                        return window.pdfjsLib.getDocument({
                            data: buffer
                        }).promise;
                    }).then(function(pdf) {
                        previewNode.innerHTML = '';
                        let chain = window.Promise.resolve();
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
                                        wrapper.addEventListener('contextmenu', function(event) {
                                            event.preventDefault();
                                        });
                                    <?php endif; ?>
                                    wrapper.appendChild(canvas);
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

                <?php if (!$allowDownload && $isPdf): ?>
                    const pdfRoot = document.getElementById('public-pdf-preview');
                    if (pdfRoot) {
                        pdfRoot.addEventListener('contextmenu', function(event) {
                            event.preventDefault();
                        });
                    }
                <?php endif; ?>

                const previewNode = document.getElementById('public-office-preview');
                fetch('documentation_folder_share.php?t=<?= rawurlencode($token) ?>&folder=<?= (int)($contents['current_folder']['id'] ?? $rootFolderId) ?>&doc=<?= (int)$selectedDocument['id'] ?>&raw=1').then(function(response) {
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
            })();
        </script>
    <?php endif; ?>
</body>

</html>
