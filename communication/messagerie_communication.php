<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();
$pageTitle = 'Messagerie des taches';
$activeMenu = 'communication';
$page = 'communication';
require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>
<div class="min-h-screen bg-[radial-gradient(circle_at_top_left,_rgba(224,231,255,0.95),_transparent_42%),radial-gradient(circle_at_top_right,_rgba(186,230,253,0.85),_transparent_34%),linear-gradient(180deg,_#f8fafc_0%,_#eef2ff_100%)] pb-12">
    <div class="container mx-auto px-4 py-8">
        <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.28em] text-cyan-600">Mes tâches</p>
                <h1 class="mt-2 text-3xl font-semibold text-slate-950">Messagerie de suivi des tâches</h1>
            </div>
            <div class="flex flex-wrap gap-3"><a href="dashboard_communication.php" class="rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">Dashboard</a><a href="taches_communication.php" class="rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">Tâches</a><a href="pieces_jointes_communication.php" class="rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">Pièces jointes</a></div>
        </div>

        <div class="grid gap-6 xl:grid-cols-[320px_1fr]">
            <aside class="rounded-[28px] border border-white/70 bg-white/90 p-6 shadow-[0_16px_50px_rgba(15,23,42,0.06)] backdrop-blur">
                <div class="text-xs font-semibold uppercase tracking-[0.28em] text-cyan-600">Collaborateur</div>
                <select id="assigneeSelect" class="mt-3 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700"></select>
                <div class="mt-5 text-xs font-semibold uppercase tracking-[0.28em] text-cyan-600">Tâche</div>
                <select id="taskSelect" class="mt-3 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700"></select>
                <div class="mt-5 text-xs font-semibold uppercase tracking-[0.28em] text-cyan-600">Recherche</div>
                <input id="searchInput" type="text" placeholder="Commentaire ou tâche" class="mt-3 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700" />
            </aside>
            <main class="rounded-[28px] border border-white/70 bg-white/90 p-6 shadow-[0_16px_50px_rgba(15,23,42,0.06)] backdrop-blur">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-[0.28em] text-cyan-600">Flux</div>
                        <h2 class="mt-2 text-2xl font-semibold text-slate-950">Commentaires et échanges</h2>
                    </div>
                </div>
                <div id="commentsList" class="mt-6 space-y-4"></div>
                <div class="mt-6 rounded-[24px] border border-cyan-100 bg-cyan-50/80 p-5">
                    <div class="text-sm font-semibold text-slate-900">Ajouter un commentaire</div>
                    <textarea id="commentBody" rows="4" placeholder="Donnez une instruction, un retour d'avancement, un blocage..." class="mt-4 w-full rounded-2xl border border-cyan-100 bg-white px-4 py-3 text-sm text-slate-700"></textarea>
                    <div class="mt-4 flex items-center gap-3"><button id="addCommentBtn" type="button" class="rounded-2xl bg-slate-950 px-4 py-3 text-sm font-semibold text-white transition hover:bg-cyan-700">Publier</button><span id="commentMessage" class="text-sm text-slate-500"></span></div>
                </div>
            </main>
        </div>
    </div>
</div>
<script>
    const commState = {
        users: [],
        tasks: [],
        assignee: '',
        taskId: '',
        search: ''
    };
    const assigneeSelect = document.getElementById('assigneeSelect');
    const taskSelect = document.getElementById('taskSelect');
    const searchInput = document.getElementById('searchInput');
    const commentsList = document.getElementById('commentsList');
    const commentMessage = document.getElementById('commentMessage');

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
    async function fetchJson(url, options = {}) {
        const res = await fetch(url, options);
        const text = await res.text();
        const data = text ? JSON.parse(text) : null;
        if (!res.ok || !data?.ok) throw new Error(data?.error || 'Erreur serveur');
        return data;
    }

    function renderUsers() {
        assigneeSelect.innerHTML = ['<option value="">Tous les collaborateurs</option>'].concat(commState.users.map(user => `<option value="${user.id}">${escapeHtml(user.display)}</option>`)).join('');
        assigneeSelect.value = commState.assignee;
    }

    function renderTasks() {
        taskSelect.innerHTML = ['<option value="">Toutes les tâches</option>'].concat(commState.tasks.map(task => `<option value="${task.id}">${escapeHtml(task.title)} - ${escapeHtml(task.assignee_display)}</option>`)).join('');
        taskSelect.value = commState.taskId;
    }

    function renderComments(comments) {
        if (!comments.length) {
            commentsList.innerHTML = '<div class="rounded-[24px] border border-dashed border-slate-300 bg-slate-50 px-6 py-10 text-center text-sm text-slate-500">Aucun commentaire trouvé.</div>';
            return;
        }
        commentsList.innerHTML = comments.map(comment => `<article class="rounded-[24px] border border-slate-200 bg-white px-5 py-4 shadow-sm"><div class="flex items-center justify-between gap-3"><div><div class="font-semibold text-slate-950">${escapeHtml(comment.author_display)}</div><div class="text-xs text-slate-500">Tâche : ${escapeHtml(comment.task_title)} | Assignée à ${escapeHtml(comment.assignee_display)}</div></div><div class="text-xs text-slate-500">${escapeHtml(formatDateTime(comment.created_at_iso))}</div></div><p class="mt-3 text-sm leading-6 text-slate-600">${escapeHtml(comment.body)}</p></article>`).join('');
    }
    async function loadUsers() {
        const data = await fetchJson('../api/communication_state.php');
        commState.users = data.users || [];
        renderUsers();
    }
    async function loadTasks() {
        const params = new URLSearchParams();
        if (commState.assignee) params.set('assignee_user_id', commState.assignee);
        const data = await fetchJson('../api/communication_tasks.php?' + params.toString());
        commState.tasks = data.tasks || [];
        renderTasks();
    }
    async function loadComments() {
        const params = new URLSearchParams();
        if (commState.assignee) params.set('assignee_user_id', commState.assignee);
        if (commState.taskId) params.set('task_id', commState.taskId);
        if (commState.search) params.set('search', commState.search);
        const data = await fetchJson('../api/communication_messages.php?' + params.toString());
        renderComments(data.comments || []);
    }
    async function addComment() {
        if (!taskSelect.value) {
            commentMessage.textContent = 'Sélectionnez une tâche.';
            return;
        }
        const formData = new FormData();
        formData.set('task_id', taskSelect.value);
        formData.set('body', document.getElementById('commentBody').value);
        try {
            commentMessage.textContent = 'Publication...';
            await fetchJson('../api/communication_comment.php', {
                method: 'POST',
                body: formData
            });
            document.getElementById('commentBody').value = '';
            commentMessage.textContent = 'Commentaire ajouté.';
            loadComments();
        } catch (error) {
            commentMessage.textContent = error.message;
        }
    }
    assigneeSelect.addEventListener('change', async () => {
        commState.assignee = assigneeSelect.value;
        await loadTasks();
        await loadComments();
    });
    taskSelect.addEventListener('change', () => {
        commState.taskId = taskSelect.value;
        loadComments();
    });
    searchInput.addEventListener('input', () => {
        commState.search = searchInput.value.trim();
        clearTimeout(window.__commMsgSearch);
        window.__commMsgSearch = setTimeout(loadComments, 250);
    });
    document.getElementById('addCommentBtn').addEventListener('click', addComment);
    document.addEventListener('DOMContentLoaded', async () => {
        await loadUsers();
        await loadTasks();
        await loadComments();
    });
</script>
<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>