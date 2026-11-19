<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();
$pageTitle = 'Pieces jointes des taches';
$activeMenu = 'communication';
$page = 'communication';
require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>
<style>
    .comm-attach-card {
        display: flex;
        flex-direction: column;
        gap: 14px;
        border-radius: 24px;
        border: 1px solid #e2e8f0;
        background: linear-gradient(135deg, #fff 0%, #f8fafc 100%);
        padding: 18px;
        box-shadow: 0 10px 30px rgba(15, 23, 42, .06)
    }

    .comm-attach-preview {
        height: 180px;
        border-radius: 18px;
        overflow: hidden;
        background: linear-gradient(135deg, #cffafe 0%, #dbeafe 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        width: 100%;
        border: 0;
        padding: 0;
        cursor: pointer
    }

    .comm-attach-preview img,
    .comm-attach-preview iframe {
        width: 100%;
        height: 100%;
        border: 0;
        object-fit: cover;
        background: #fff
    }

    .comm-attach-preview.is-pdf {
        background: linear-gradient(135deg, #fee2e2 0%, #ffe4e6 100%)
    }

    .comm-attach-preview.is-file {
        background: linear-gradient(135deg, #e2e8f0 0%, #f8fafc 100%)
    }

    .comm-attach-ext {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 72px;
        height: 72px;
        border-radius: 24px;
        background: rgba(255, 255, 255, .92);
        color: #0f172a;
        font-size: 16px;
        font-weight: 900;
        letter-spacing: .08em;
        text-transform: uppercase
    }

    .comm-attach-delete {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 16px;
        border: 0;
        background: #fee2e2;
        color: #b91c1c;
        padding: 10px 14px;
        font-size: 13px;
        font-weight: 800
    }

    .comm-preview-overlay {
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, .72);
        backdrop-filter: blur(6px);
        display: none;
        align-items: center;
        justify-content: center;
        padding: 24px;
        z-index: 70
    }

    .comm-preview-overlay.is-open {
        display: flex
    }

    .comm-preview-modal {
        width: min(1100px, 100%);
        max-height: calc(100vh - 48px);
        display: flex;
        flex-direction: column;
        border-radius: 28px;
        overflow: hidden;
        background: #fff;
        box-shadow: 0 30px 90px rgba(15, 23, 42, .35)
    }

    .comm-preview-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        padding: 18px 22px;
        border-bottom: 1px solid #e2e8f0
    }

    .comm-preview-body {
        min-height: 360px;
        background: #e2e8f0;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 18px
    }

    .comm-preview-body img,
    .comm-preview-body iframe,
    .comm-preview-body video,
    .comm-preview-body audio {
        max-width: 100%;
        width: 100%;
        border: 0;
        border-radius: 18px;
        background: #fff
    }

    .comm-preview-body img,
    .comm-preview-body iframe,
    .comm-preview-body video {
        max-height: calc(100vh - 220px);
        object-fit: contain
    }

    .comm-preview-empty {
        width: 100%;
        max-width: 420px;
        border-radius: 24px;
        background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
        padding: 28px;
        text-align: center
    }
</style>
<div class="min-h-screen bg-[radial-gradient(circle_at_top_left,_rgba(224,231,255,0.95),_transparent_42%),radial-gradient(circle_at_top_right,_rgba(186,230,253,0.85),_transparent_34%),linear-gradient(180deg,_#f8fafc_0%,_#eef2ff_100%)] pb-12">
    <div class="container mx-auto px-4 py-8">
        <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.28em] text-cyan-600">Mes tâches</p>
                <h1 class="mt-2 text-3xl font-semibold text-slate-950">Bibliothèque des pièces jointes</h1>
            </div>
            <div class="flex flex-wrap gap-3"><a href="dashboard_communication.php" class="rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">Dashboard</a><a href="taches_communication.php" class="rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">Tâches</a><a href="messagerie_communication.php" class="rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">Messagerie</a></div>
        </div>

        <div class="rounded-[28px] border border-white/70 bg-white/90 p-6 shadow-[0_16px_50px_rgba(15,23,42,0.06)] backdrop-blur">
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <select id="assigneeSelect" class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700"></select>
                <input id="searchInput" type="text" placeholder="Nom de fichier ou tâche" class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700" />
                <div class="rounded-2xl bg-slate-50 px-4 py-3 text-sm text-slate-600">Retrouvez tous les fichiers joints aux tâches.</div>
            </div>
            <div id="attachmentsList" class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-3"></div>
        </div>
    </div>
</div>
<div id="previewModal" class="comm-preview-overlay" aria-hidden="true">
    <div class="comm-preview-modal" role="dialog" aria-modal="true" aria-labelledby="previewTitle">
        <div class="comm-preview-header">
            <div class="min-w-0">
                <div id="previewTitle" class="truncate text-lg font-semibold text-slate-950">Aperçu</div>
                <div id="previewMeta" class="mt-1 text-sm text-slate-500"></div>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <a id="previewDownloadLink" href="#" class="rounded-2xl bg-slate-950 px-4 py-2 text-sm font-semibold text-white transition hover:bg-cyan-700">Télécharger</a>
                <button id="previewCloseBtn" type="button" class="rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">Fermer</button>
            </div>
        </div>
        <div id="previewBody" class="comm-preview-body"></div>
    </div>
</div>
<script>
    const attachState = {
        users: [],
        assignee: '',
        search: ''
    };
    const assigneeSelect = document.getElementById('assigneeSelect');
    const searchInput = document.getElementById('searchInput');
    const attachmentsList = document.getElementById('attachmentsList');
    const previewModal = document.getElementById('previewModal');
    const previewTitle = document.getElementById('previewTitle');
    const previewMeta = document.getElementById('previewMeta');
    const previewBody = document.getElementById('previewBody');
    const previewDownloadLink = document.getElementById('previewDownloadLink');
    const previewCloseBtn = document.getElementById('previewCloseBtn');

    function escapeHtml(value) {
        return String(value || '').replace(/[&<>"']/g, s => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;'
        } [s]));
    }

    function formatDateTime(iso) {
        if (!iso) {
            return 'Sans date';
        }
        const date = new Date(iso);
        return Number.isNaN(date.getTime()) ? iso : date.toLocaleString('fr-FR', {
            day: '2-digit',
            month: 'short',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    function formatBytes(value) {
        const size = Number(value || 0);
        if (size <= 0) return '0 Ko';
        if (size >= 1024 * 1024) return (size / (1024 * 1024)).toFixed(1) + ' Mo';
        return Math.max(1, Math.round(size / 1024)) + ' Ko';
    }

    function isImageAttachment(item) {
        return String(item?.mime_type || '').toLowerCase().startsWith('image/');
    }

    function isPdfAttachment(item) {
        return String(item?.mime_type || '').toLowerCase() === 'application/pdf';
    }

    function isVideoAttachment(item) {
        return String(item?.mime_type || '').toLowerCase().startsWith('video/');
    }

    function isAudioAttachment(item) {
        return String(item?.mime_type || '').toLowerCase().startsWith('audio/');
    }

    function attachmentPreviewMarkup(item) {
        if (isImageAttachment(item)) {
            return `<button type="button" class="comm-attach-preview js-preview-attachment" data-preview-url="../${item.preview_url}" data-download-url="../${item.download_url}" data-preview-name="${escapeHtml(item.original_name)}" data-preview-type="${escapeHtml(item.mime_type || '')}" data-preview-size="${escapeHtml(formatBytes(item.file_size))}"><img src="../${item.preview_url}" alt="${escapeHtml(item.original_name)}" loading="lazy" /></button>`;
        }
        if (isPdfAttachment(item)) {
            return `<button type="button" class="comm-attach-preview is-pdf js-preview-attachment" data-preview-url="../${item.preview_url}" data-download-url="../${item.download_url}" data-preview-name="${escapeHtml(item.original_name)}" data-preview-type="${escapeHtml(item.mime_type || '')}" data-preview-size="${escapeHtml(formatBytes(item.file_size))}"><iframe src="../${item.preview_url}#toolbar=0&navpanes=0&scrollbar=0" title="${escapeHtml(item.original_name)}" loading="lazy"></iframe></button>`;
        }

        return `<button type="button" class="comm-attach-preview is-file js-preview-attachment" data-preview-url="${item.is_previewable ? '../' + item.preview_url : ''}" data-download-url="../${item.download_url}" data-preview-name="${escapeHtml(item.original_name)}" data-preview-type="${escapeHtml(item.mime_type || '')}" data-preview-size="${escapeHtml(formatBytes(item.file_size))}"><span class="comm-attach-ext">${escapeHtml((item.extension || 'file').slice(0, 4))}</span></button>`;
    }

    function openPreviewModal(item) {
        const previewType = String(item?.type || '').toLowerCase();
        const previewUrl = String(item?.previewUrl || '');
        const downloadUrl = String(item?.downloadUrl || '#');
        previewTitle.textContent = item?.name || 'Aperçu';
        previewMeta.textContent = [item?.size || '', item?.type || ''].filter(Boolean).join(' • ');
        previewDownloadLink.href = downloadUrl;
        previewBody.innerHTML = '';

        if (previewUrl && previewType.startsWith('image/')) {
            previewBody.innerHTML = `<img src="${escapeHtml(previewUrl)}" alt="${escapeHtml(item?.name || 'Aperçu')}" />`;
        } else if (previewUrl && previewType === 'application/pdf') {
            previewBody.innerHTML = `<iframe src="${escapeHtml(previewUrl)}#toolbar=1&navpanes=0" title="${escapeHtml(item?.name || 'Aperçu')}"></iframe>`;
        } else if (previewUrl && previewType.startsWith('video/')) {
            previewBody.innerHTML = `<video src="${escapeHtml(previewUrl)}" controls autoplay></video>`;
        } else if (previewUrl && previewType.startsWith('audio/')) {
            previewBody.innerHTML = `<div class="comm-preview-empty"><div class="text-base font-semibold text-slate-900">Lecture audio</div><div class="mt-2 text-sm text-slate-600">Le fichier est prêt à être écouté dans cette fenêtre.</div><audio class="mt-5" src="${escapeHtml(previewUrl)}" controls autoplay></audio></div>`;
        } else if (previewUrl) {
            previewBody.innerHTML = `<iframe src="${escapeHtml(previewUrl)}" title="${escapeHtml(item?.name || 'Aperçu')}"></iframe>`;
        } else {
            previewBody.innerHTML = `<div class="comm-preview-empty"><div class="text-base font-semibold text-slate-900">Aperçu non disponible</div><div class="mt-2 text-sm text-slate-600">Ce format ne peut pas être affiché directement. Utilisez le bouton de téléchargement.</div></div>`;
        }

        previewModal.classList.add('is-open');
        previewModal.setAttribute('aria-hidden', 'false');
    }

    function closePreviewModal() {
        previewModal.classList.remove('is-open');
        previewModal.setAttribute('aria-hidden', 'true');
        previewBody.innerHTML = '';
    }

    async function fetchJson(url, options = {}) {
        const res = await fetch(url, options);
        const text = await res.text();
        const data = text ? JSON.parse(text) : null;
        if (!res.ok || !data?.ok) {
            throw new Error(data?.error || 'Erreur serveur');
        }
        return data;
    }

    async function deleteAttachment(attachmentId) {
        const formData = new FormData();
        formData.set('attachment_id', String(attachmentId || 0));
        return fetchJson('../api/communication_attachment_delete.php', {
            method: 'POST',
            body: formData
        });
    }

    function renderUsers() {
        assigneeSelect.innerHTML = ['<option value="">Tous les collaborateurs</option>'].concat(attachState.users.map(user => `<option value="${user.id}">${escapeHtml(user.display)}</option>`)).join('');
        assigneeSelect.value = attachState.assignee;
    }

    function renderAttachments(items) {
        if (!items.length) {
            attachmentsList.innerHTML = '<div class="rounded-[24px] border border-dashed border-slate-300 bg-slate-50 px-6 py-10 text-center text-sm text-slate-500 md:col-span-2 xl:col-span-3">Aucune pièce jointe trouvée.</div>';
            return;
        }
        attachmentsList.innerHTML = items.map(item => `<article class="comm-attach-card">${attachmentPreviewMarkup(item)}<div class="flex items-center justify-between gap-3"><div class="font-semibold text-slate-950">${escapeHtml(item.original_name)}</div><span class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700">${escapeHtml(item.task_status_label)}</span></div><div class="text-sm text-slate-600">Tâche : <strong>${escapeHtml(item.task_title)}</strong></div><div class="text-sm text-slate-600">Assignée à : <strong>${escapeHtml(item.assignee_display)}</strong></div><div class="text-sm text-slate-600">Ajoutée par : <strong>${escapeHtml(item.uploader_display)}</strong></div><div class="flex items-center justify-between text-xs text-slate-500"><span>${escapeHtml(formatBytes(item.file_size))}</span><span>${escapeHtml(formatDateTime(item.created_at_iso))}</span></div><div class="flex flex-wrap gap-2">${item.is_previewable ? `<button type="button" class="inline-flex items-center rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-800 transition hover:bg-slate-50 js-preview-attachment" data-preview-url="../${item.preview_url}" data-download-url="../${item.download_url}" data-preview-name="${escapeHtml(item.original_name)}" data-preview-type="${escapeHtml(item.mime_type || '')}" data-preview-size="${escapeHtml(formatBytes(item.file_size))}">Consulter</button>` : ''}<a href="../${item.download_url}" class="inline-flex items-center rounded-2xl bg-slate-950 px-4 py-2 text-sm font-semibold text-white transition hover:bg-cyan-700">Télécharger</a><button type="button" class="comm-attach-delete" data-delete-attachment="${item.id}">Supprimer</button></div></article>`).join('');

        attachmentsList.querySelectorAll('.js-preview-attachment').forEach(button => {
            button.addEventListener('click', () => {
                openPreviewModal({
                    previewUrl: button.getAttribute('data-preview-url') || '',
                    downloadUrl: button.getAttribute('data-download-url') || '#',
                    name: button.getAttribute('data-preview-name') || 'Aperçu',
                    type: button.getAttribute('data-preview-type') || '',
                    size: button.getAttribute('data-preview-size') || ''
                });
            });
        });

        attachmentsList.querySelectorAll('[data-delete-attachment]').forEach(button => {
            button.addEventListener('click', async () => {
                const attachmentId = Number(button.getAttribute('data-delete-attachment') || '0');
                if (!attachmentId) {
                    return;
                }
                if (!window.confirm('Supprimer cette pièce jointe ?')) {
                    return;
                }
                try {
                    await deleteAttachment(attachmentId);
                    await loadAttachments();
                } catch (error) {
                    window.alert(error.message);
                }
            });
        });
    }
    async function loadUsers() {
        const data = await fetchJson('../api/communication_state.php');
        attachState.users = data.users || [];
        renderUsers();
    }
    async function loadAttachments() {
        const params = new URLSearchParams();
        if (attachState.assignee) params.set('assignee_user_id', attachState.assignee);
        if (attachState.search) params.set('search', attachState.search);
        const data = await fetchJson('../api/communication_attachments.php?' + params.toString());
        renderAttachments(data.attachments || []);
    }
    assigneeSelect.addEventListener('change', () => {
        attachState.assignee = assigneeSelect.value;
        loadAttachments();
    });
    searchInput.addEventListener('input', () => {
        attachState.search = searchInput.value.trim();
        clearTimeout(window.__commAttachSearch);
        window.__commAttachSearch = setTimeout(loadAttachments, 250);
    });
    previewCloseBtn.addEventListener('click', closePreviewModal);
    previewModal.addEventListener('click', event => {
        if (event.target === previewModal) {
            closePreviewModal();
        }
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && previewModal.classList.contains('is-open')) {
            closePreviewModal();
        }
    });
    document.addEventListener('DOMContentLoaded', async () => {
        await loadUsers();
        await loadAttachments();
    });
</script>
<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>