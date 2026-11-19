<?php
session_start();

require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/AuditTrail.php';

require_once __DIR__ . '/model/Database.php';
require_once __DIR__ . '/model/FicheDecaissement.php';
require_once __DIR__ . '/model/BonCommande.php';
require_once __DIR__ . '/model/BonReception.php';

AuditTrail::logPageConsultation([
    'module' => 'decaissement',
    'action' => 'bon_reception_view',
    'entity_type' => 'bon_reception',
    'entity_id' => $_GET['num_fiche'] ?? null,
    'entity_label' => 'Bon de reception',
    'page_name' => 'bon_reception.php',
    'details' => [
        'num_fiche' => $_GET['num_fiche'] ?? null,
        'view_mode' => 'upload',
    ],
]);

$pageTitle = 'Bon de réception - DécaissApp';
$activeMenu = 'achat';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';

$pdo = Database::getConnection();
$ficheModel = new FicheDecaissement($pdo);
$bcModel = new BonCommande($pdo);
$brModel = new BonReception($pdo);

$inputNum = trim((string)($_GET['num_fiche'] ?? ''));
if ($inputNum === '') {
    http_response_code(400);
    echo '<div class="container mx-auto p-6">Numéro de fiche manquant.</div>';
    exit;
}

$fiche = $ficheModel->getByNum($inputNum);
if (!$fiche) {
    http_response_code(404);
    echo '<div class="container mx-auto p-6">Fiche introuvable.</div>';
    exit;
}

$baseNum = (string)($fiche['parent_num_fiche'] ?? ($fiche['code_parentale'] ?? ($fiche['num_fiche'] ?? $inputNum)));
$baseNum = trim($baseNum) !== '' ? trim($baseNum) : $inputNum;

$bc = $bcModel->getByNumFiche($baseNum);
$receipt = $brModel->getByNumFiche($baseNum);

$flashError = '';
$flashSuccess = '';

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function safeBasename(string $name): string
{
    $name = basename($name);
    $name = preg_replace('/[^0-9A-Za-z._ -]/', '_', $name);
    return $name ?: 'document';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$bc) {
        $flashError = 'Aucun bon de commande n\'est lié à cette fiche : bon de réception non requis.';
    } elseif (!$brModel->isReady()) {
        $flashError = $brModel->getInitError() ?: 'Module bon de réception indisponible.';
    } else {
        $file = $_FILES['bon_reception'] ?? null;
        if (!$file || !is_array($file)) {
            $flashError = 'Fichier manquant.';
        } elseif (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $flashError = 'Erreur upload (code ' . (int)($file['error'] ?? -1) . ').';
        } else {
            $maxBytes = 10 * 1024 * 1024; // 10 MB
            $size = (int)($file['size'] ?? 0);
            if ($size <= 0) {
                $flashError = 'Fichier vide.';
            } elseif ($size > $maxBytes) {
                $flashError = 'Fichier trop volumineux (max 10MB).';
            } else {
                $tmp = (string)($file['tmp_name'] ?? '');
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $tmp ? (string)$finfo->file($tmp) : '';

                $allowed = [
                    'application/pdf' => 'pdf',
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                ];
                if (!isset($allowed[$mime])) {
                    $flashError = 'Format non autorisé. Formats acceptés : PDF, JPG, PNG.';
                } else {
                    $ext = $allowed[$mime];
                    $root = realpath(__DIR__ . '/..');
                    if (!$root) {
                        $flashError = 'Racine projet introuvable.';
                    } else {
                        $dir = $root . '/storage/bon_reception';
                        if (!is_dir($dir)) {
                            @mkdir($dir, 0775, true);
                        }
                        if (!is_dir($dir)) {
                            $flashError = 'Impossible de créer le dossier de stockage.';
                        } else {
                            $cleanNum = preg_replace('/[^0-9A-Za-z_-]/', '_', $baseNum);
                            $stamp = date('Ymd_His');
                            $rand = bin2hex(random_bytes(4));
                            $storedName = 'BR_' . $cleanNum . '_' . $stamp . '_' . $rand . '.' . $ext;
                            $dest = $dir . '/' . $storedName;
                            if (!move_uploaded_file($tmp, $dest)) {
                                $flashError = 'Impossible de sauvegarder le fichier.';
                            } else {
                                $originalName = safeBasename((string)($file['name'] ?? 'bon_reception.' . $ext));
                                $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

                                $res = $brModel->upsertByNumFiche($baseNum, [
                                    'file_path' => 'storage/bon_reception/' . $storedName,
                                    'original_name' => $originalName,
                                    'mime_type' => $mime,
                                    'file_size' => $size,
                                ], $userId);

                                if (empty($res['ok'])) {
                                    $flashError = (string)($res['error'] ?? 'Enregistrement impossible.');
                                } else {
                                    AuditTrail::log('bon_reception_uploaded', [
                                        'module' => 'decaissement',
                                        'action' => 'upload_bon_reception',
                                        'entity_type' => 'bon_reception',
                                        'entity_id' => $baseNum,
                                        'entity_label' => 'Bon de reception ' . $baseNum,
                                        'result' => 'success',
                                        'details' => [
                                            'num_fiche' => $baseNum,
                                            'mime_type' => $mime,
                                            'file_size' => $size,
                                            'original_name' => $originalName,
                                        ],
                                    ], $pdo);
                                    $flashSuccess = 'Bon de réception enregistré.';
                                    $receipt = $brModel->getByNumFiche($baseNum);
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    if ($flashError !== '') {
        AuditTrail::log('bon_reception_upload_failed', [
            'module' => 'decaissement',
            'action' => 'upload_bon_reception',
            'entity_type' => 'bon_reception',
            'entity_id' => $baseNum,
            'entity_label' => 'Bon de reception',
            'result' => 'failed',
            'severity' => 'warning',
            'details' => [
                'num_fiche' => $baseNum,
                'error_message' => $flashError,
            ],
        ], $pdo);
    }
}

$downloadUrl = 'request/download_bon_reception.php?num_fiche=' . urlencode($baseNum);
?>

<div class="container mx-auto py-10 px-4 max-w-4xl">
    <div class="flex items-start justify-between gap-4 flex-wrap mb-6">
        <div>
            <h1 class="text-3xl font-bold text-indigo-700 drop-shadow">Bon de réception signé</h1>
            <p class="text-sm text-gray-600 mt-1">Fiche N° <span class="font-semibold text-gray-800"><?= h($inputNum) ?></span>
                <?php if ($baseNum !== $inputNum): ?>
                    <span class="text-gray-400">(rattachée à <?= h($baseNum) ?>)</span>
                <?php endif; ?>
            </p>
        </div>
        <div class="flex items-center gap-2">
            <a href="bon_commande.php?num_fiche=<?= urlencode($baseNum) ?>" class="px-3 py-2 rounded-xl bg-white border text-sm hover:bg-gray-50">Retour BC</a>
            <a href="liste_fiches_achat.php" class="px-3 py-2 rounded-xl bg-white border text-sm hover:bg-gray-50">Retour liste</a>
        </div>
    </div>

    <?php if ($flashError): ?>
        <div class="mb-4 p-3 rounded-xl bg-rose-50 text-rose-800 border border-rose-100 text-sm"><?= h($flashError) ?></div>
    <?php elseif ($flashSuccess): ?>
        <div class="mb-4 p-3 rounded-xl bg-emerald-50 text-emerald-800 border border-emerald-100 text-sm"><?= h($flashSuccess) ?></div>
    <?php endif; ?>

    <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <div class="font-semibold text-gray-900">Statut</div>
                <div class="mt-3 text-sm text-gray-700 space-y-2">
                    <div><span class="text-gray-500">Bon de commande :</span>
                        <?php if ($bc): ?>
                            <span class="font-semibold text-emerald-700">Oui</span>
                            <span class="text-gray-500">(<?= h($bc['bc_number'] ?? '') ?>)</span>
                        <?php else: ?>
                            <span class="font-semibold text-rose-700">Non</span>
                        <?php endif; ?>
                    </div>
                    <div><span class="text-gray-500">Bon de réception :</span>
                        <?php if ($receipt): ?>
                            <span class="font-semibold text-emerald-700">Uploadé</span>
                            <div class="text-xs text-gray-500 mt-1">Fichier : <?= h($receipt['original_name'] ?? '') ?> |
                                <?= h($receipt['uploaded_at'] ?? '') ?></div>
                            <?php
                            $receiptMime = (string)($receipt['mime_type'] ?? '');
                            $isReceiptImage = $receiptMime !== '' && strpos($receiptMime, 'image/') === 0;
                            ?>
                            <div class="mt-3">
                                <div class="p-3 rounded-xl bg-gray-50 border border-gray-200">
                                    <div class="text-xs text-gray-600 font-semibold mb-2">Aperçu</div>
                                    <?php if ($isReceiptImage): ?>
                                        <img src="<?= h($downloadUrl) ?>" alt="Aperçu BR" class="w-full max-h-56 object-contain rounded-lg bg-white border" loading="lazy" />
                                    <?php else: ?>
                                        <div class="flex items-center gap-3">
                                            <div class="w-12 h-12 rounded-xl bg-white border border-gray-200 flex items-center justify-center">
                                                <svg class="w-6 h-6 text-rose-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M7 2h7l5 5v15a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2z" />
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M14 2v6h6" />
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 13h8M8 17h5" />
                                                </svg>
                                            </div>
                                            <div class="min-w-0">
                                                <div class="text-sm font-semibold text-gray-900 truncate"><?= h($receipt['original_name'] ?? '') ?></div>
                                                <div class="text-xs text-gray-600">Document (PDF)</div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <span class="font-semibold text-amber-700">Manquant</span>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($receipt): ?>
                    <div class="mt-4">
                        <a href="<?= h($downloadUrl) ?>" class="inline-flex items-center gap-2 px-3 py-2 rounded-xl bg-gray-900 text-white text-sm hover:bg-gray-800" target="_blank">
                            Télécharger / ouvrir le document
                        </a>
                    </div>
                <?php endif; ?>
            </div>

            <div>
                <div class="font-semibold text-gray-900">Uploader le document signé</div>
                <p class="text-xs text-gray-500 mt-1">Formats : PDF, JPG, PNG. Taille max : 10MB.</p>

                <?php if (!$bc): ?>
                    <div class="mt-4 p-3 rounded-xl bg-gray-50 text-gray-600 border text-sm">
                        Aucun bon de commande n'est enregistré pour cette fiche. L'upload du bon de réception n'est pas requis.
                    </div>
                <?php else: ?>
                    <form method="post" enctype="multipart/form-data" class="mt-4 space-y-3" id="br-upload-form">
                        <input
                            id="br-file-input"
                            type="file"
                            name="bon_reception"
                            required
                            class="hidden"
                            accept="application/pdf,image/jpeg,image/png" />

                        <div
                            id="br-dropzone"
                            class="rounded-2xl border-2 border-dashed border-gray-200 bg-gray-50 p-4 hover:bg-white transition cursor-pointer"
                            role="button"
                            tabindex="0"
                            aria-label="Déposer un fichier ou cliquer pour choisir">
                            <div class="flex items-start gap-3">
                                <div class="shrink-0 w-11 h-11 rounded-xl bg-white border border-gray-200 flex items-center justify-center">
                                    <svg class="w-6 h-6 text-indigo-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M7 16a4 4 0 0 1 .88-7.903A5 5 0 0 1 15.9 6L16 6a5 5 0 0 1 1 9.9M12 12v9m0-9 3 3m-3-3-3 3" />
                                    </svg>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="text-sm font-semibold text-gray-900">Glissez-déposez votre BR signé ici</div>
                                    <div class="text-xs text-gray-600 mt-0.5">ou cliquez pour choisir un fichier (PDF/JPG/PNG, 10MB max)</div>
                                    <div class="mt-3 flex flex-wrap items-center gap-2">
                                        <span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-xl bg-white border text-xs text-gray-700">
                                            <svg class="w-4 h-4 text-gray-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14m7-7H5" />
                                            </svg>
                                            Choisir un fichier
                                        </span>
                                        <?php if ($receipt): ?>
                                            <span class="text-xs text-gray-500">Fichier actuel : <span class="font-semibold text-gray-800"><?= h($receipt['original_name'] ?? '') ?></span></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <div id="br-preview" class="mt-4 hidden">
                                <div class="flex items-start gap-3 p-3 rounded-xl bg-white border border-gray-200">
                                    <div id="br-preview-icon" class="shrink-0 w-12 h-12 rounded-xl bg-gray-50 border border-gray-200 flex items-center justify-center overflow-hidden"></div>
                                    <div class="flex-1 min-w-0">
                                        <div id="br-preview-name" class="text-sm font-semibold text-gray-900 truncate"></div>
                                        <div id="br-preview-meta" class="text-xs text-gray-600 mt-0.5"></div>
                                        <div class="mt-2 flex flex-wrap gap-2">
                                            <button type="button" id="br-clear" class="px-3 py-1.5 rounded-lg bg-white border text-xs text-gray-700 hover:bg-gray-50">Retirer</button>
                                            <a href="<?= h($downloadUrl) ?>" target="_blank" class="px-3 py-1.5 rounded-lg bg-gray-900 text-white text-xs hover:bg-gray-800 <?= $receipt ? '' : 'hidden' ?>" id="br-open-current">Ouvrir le BR actuel</a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center gap-2">
                            <button type="submit" id="br-submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg font-semibold shadow transition-all">
                                <?= $receipt ? 'Remplacer le fichier' : 'Uploader le fichier' ?>
                            </button>
                            <?php if ($receipt): ?>
                                <a href="<?= h($downloadUrl) ?>" class="px-4 py-2 rounded-lg bg-gray-900 text-white font-semibold shadow hover:bg-gray-800" target="_blank">Ouvrir</a>
                            <?php endif; ?>
                        </div>
                        <div class="text-[11px] text-gray-500">Astuce : pour une photo, prenez une image nette du document signé.</div>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="mt-6 text-xs text-gray-500">
            Rappel : si un bon de commande existe, le bon de réception signé est obligatoire avant décaissement.
        </div>
    </div>
</div>

<?php if ($bc): ?>
    <script>
        (function() {
            const input = document.getElementById('br-file-input');
            const dz = document.getElementById('br-dropzone');
            const preview = document.getElementById('br-preview');
            const icon = document.getElementById('br-preview-icon');
            const nameEl = document.getElementById('br-preview-name');
            const metaEl = document.getElementById('br-preview-meta');
            const clearBtn = document.getElementById('br-clear');
            const submitBtn = document.getElementById('br-submit');

            const MAX_BYTES = 10 * 1024 * 1024;
            const ALLOWED = ['application/pdf', 'image/jpeg', 'image/png'];
            let lastObjectUrl = null;

            if (!input || !dz || !preview || !icon || !nameEl || !metaEl || !clearBtn || !submitBtn) return;

            function formatBytes(bytes) {
                const b = Number(bytes || 0);
                if (!b) return '0 B';
                const units = ['B', 'KB', 'MB', 'GB'];
                const i = Math.min(units.length - 1, Math.floor(Math.log(b) / Math.log(1024)));
                const v = b / Math.pow(1024, i);
                return (i === 0 ? v.toFixed(0) : v.toFixed(1)) + ' ' + units[i];
            }

            function renderIconForFile(file) {
                icon.innerHTML = '';
                icon.classList.remove('bg-rose-50', 'bg-emerald-50');

                if (lastObjectUrl) {
                    try {
                        URL.revokeObjectURL(lastObjectUrl);
                    } catch (_) {}
                    lastObjectUrl = null;
                }

                if (!file) {
                    preview.classList.add('hidden');
                    return;
                }

                // Image preview
                if (file.type && file.type.startsWith('image/')) {
                    const img = document.createElement('img');
                    img.alt = 'Aperçu';
                    img.className = 'w-full h-full object-cover';
                    lastObjectUrl = URL.createObjectURL(file);
                    img.src = lastObjectUrl;
                    icon.appendChild(img);
                    return;
                }

                // PDF icon
                const wrap = document.createElement('div');
                wrap.className = 'w-full h-full flex items-center justify-center';
                wrap.innerHTML = `<svg class="w-7 h-7 text-rose-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M7 2h7l5 5v15a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" d="M14 2v6h6"/>
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 13h8M8 17h5"/>
                </svg>`;
                icon.appendChild(wrap);
            }

            function setSubmitEnabled(enabled) {
                submitBtn.disabled = !enabled;
                submitBtn.classList.toggle('opacity-60', !enabled);
                submitBtn.classList.toggle('cursor-not-allowed', !enabled);
            }

            function validateFile(file) {
                if (!file) return {
                    ok: false,
                    error: 'Fichier manquant.'
                };
                if (!file.size || file.size <= 0) return {
                    ok: false,
                    error: 'Fichier vide.'
                };
                if (file.size > MAX_BYTES) return {
                    ok: false,
                    error: 'Fichier trop volumineux (max 10MB).'
                };
                if (file.type && !ALLOWED.includes(file.type)) {
                    return {
                        ok: false,
                        error: 'Format non autorisé. Formats acceptés : PDF, JPG, PNG.'
                    };
                }
                return {
                    ok: true,
                    error: null
                };
            }

            function setFile(file) {
                if (!file) return;
                nameEl.textContent = file.name || 'Fichier sélectionné';
                const v = validateFile(file);
                const t = (file.type || '').toUpperCase();
                if (!v.ok) {
                    metaEl.textContent = v.error;
                    metaEl.classList.add('text-rose-700', 'font-semibold');
                    setSubmitEnabled(false);
                } else {
                    metaEl.textContent = (t ? (t + ' • ') : '') + formatBytes(file.size);
                    metaEl.classList.remove('text-rose-700', 'font-semibold');
                    setSubmitEnabled(true);
                }
                preview.classList.remove('hidden');
                renderIconForFile(file);
            }

            function clearSelection() {
                input.value = '';
                preview.classList.add('hidden');
                icon.innerHTML = '';
                nameEl.textContent = '';
                metaEl.textContent = '';
                metaEl.classList.remove('text-rose-700', 'font-semibold');
                setSubmitEnabled(true);

                if (lastObjectUrl) {
                    try {
                        URL.revokeObjectURL(lastObjectUrl);
                    } catch (_) {}
                    lastObjectUrl = null;
                }
            }

            function openPicker() {
                input.click();
            }

            dz.addEventListener('click', openPicker);
            dz.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    openPicker();
                }
            });

            input.addEventListener('change', () => {
                const file = input.files && input.files[0] ? input.files[0] : null;
                if (file) setFile(file);
                else clearSelection();
            });

            clearBtn.addEventListener('click', () => clearSelection());

            dz.addEventListener('dragenter', (e) => {
                e.preventDefault();
                dz.classList.add('border-indigo-300');
            });
            dz.addEventListener('dragover', (e) => {
                e.preventDefault();
                dz.classList.add('border-indigo-300');
            });
            dz.addEventListener('dragleave', () => {
                dz.classList.remove('border-indigo-300');
            });
            dz.addEventListener('drop', (e) => {
                e.preventDefault();
                dz.classList.remove('border-indigo-300');
                const files = e.dataTransfer && e.dataTransfer.files ? e.dataTransfer.files : null;
                if (!files || !files.length) return;
                const file = files[0];
                if (!file) return;

                let assigned = false;
                try {
                    const dt = new DataTransfer();
                    dt.items.add(file);
                    input.files = dt.files;
                    assigned = !!(input.files && input.files.length);
                } catch (_) {
                    assigned = false;
                }

                setFile(file);

                // Si on ne peut pas attacher le fichier au champ, on force l'utilisateur à passer par le picker.
                if (!assigned) {
                    metaEl.textContent = 'Votre navigateur ne permet pas d\'attacher ce fichier via glisser-déposer. Cliquez pour choisir le fichier.';
                    metaEl.classList.add('text-rose-700', 'font-semibold');
                    setSubmitEnabled(false);
                    setTimeout(() => openPicker(), 50);
                }
            });
        })();
    </script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>