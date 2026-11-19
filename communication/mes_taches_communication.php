<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

$pageTitle = 'Mes taches';
$activeMenu = 'communication';
$page = 'communication';

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>
<style>
    .todo-shell {
        display: grid;
        gap: 24px;
    }

    .todo-hero {
        border-radius: 34px;
        border: 1px solid rgba(186, 230, 253, .55);
        background:
            radial-gradient(circle at top left, rgba(103, 232, 249, .22), transparent 34%),
            radial-gradient(circle at bottom right, rgba(59, 130, 246, .18), transparent 32%),
            linear-gradient(135deg, #082f49 0%, #0f172a 45%, #164e63 100%);
        color: #fff;
        padding: 28px;
        box-shadow: 0 24px 80px rgba(15, 23, 42, .28);
    }

    .todo-panel {
        border-radius: 30px;
        border: 1px solid rgba(226, 232, 240, .9);
        background: rgba(255, 255, 255, .92);
        padding: 22px;
        box-shadow: 0 18px 60px rgba(15, 23, 42, .07);
    }

    .todo-view-switch {
        display: inline-flex;
        flex-wrap: wrap;
        gap: 8px;
        padding: 6px;
        border-radius: 9999px;
        background: rgba(255, 255, 255, .12);
    }

    .todo-view-switch.light {
        background: #e2e8f0;
    }

    .todo-view-btn {
        border: 0;
        border-radius: 9999px;
        padding: 10px 14px;
        font-size: 12px;
        font-weight: 800;
        color: inherit;
        background: transparent;
    }

    .todo-view-switch.light .todo-view-btn {
        color: #475569;
    }

    .todo-view-btn.is-active {
        background: #fff;
        color: #0f172a;
        box-shadow: 0 8px 20px rgba(15, 23, 42, .10);
    }

    .todo-kpis {
        display: grid;
        gap: 16px;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        margin-top: 24px;
    }

    .todo-kpi {
        border-radius: 24px;
        padding: 18px;
        background: rgba(255, 255, 255, .92);
        color: #0f172a;
    }

    .todo-kpi strong {
        display: block;
        margin-top: 8px;
        font-size: 34px;
        line-height: 1;
    }

    .todo-toolbar {
        display: grid;
        gap: 14px;
        grid-template-columns: 1.2fr .8fr .8fr auto;
    }

    .todo-input,
    .todo-select {
        width: 100%;
        border-radius: 18px;
        border: 1px solid #dbeafe;
        background: #f8fafc;
        padding: 14px 16px;
        font-size: 14px;
        color: #0f172a;
    }

    .todo-chip-row {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        margin-top: 16px;
    }

    .todo-chip {
        border: 1px solid #dbeafe;
        border-radius: 9999px;
        padding: 8px 12px;
        background: #fff;
        color: #475569;
        font-size: 12px;
        font-weight: 800;
    }

    .todo-chip.is-active {
        background: #0f172a;
        color: #fff;
        border-color: #0f172a;
    }

    .todo-workspace {
        display: grid;
        gap: 18px;
        grid-template-columns: minmax(0, 1.5fr) minmax(320px, .8fr);
        margin-top: 18px;
    }

    .todo-sections {
        display: grid;
        gap: 16px;
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .todo-section {
        border-radius: 26px;
        border: 1px solid #e2e8f0;
        background: linear-gradient(180deg, #fff 0%, #f8fafc 100%);
        padding: 16px;
    }

    .todo-section-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 12px;
    }

    .todo-count {
        border-radius: 9999px;
        padding: 6px 10px;
        background: #e2e8f0;
        color: #0f172a;
        font-size: 12px;
        font-weight: 800;
    }

    .todo-section-list {
        display: flex;
        flex-direction: column;
        gap: 12px;
        min-height: 120px;
    }

    .todo-card {
        border-radius: 22px;
        border: 1px solid #dbeafe;
        background: #fff;
        padding: 14px;
        position: relative;
    }

    .todo-card.is-late {
        border-color: #fecaca;
        background: #fff1f2;
    }

    .todo-card.is-selected,
    .todo-check-item.is-selected {
        border-color: #0f172a;
        box-shadow: 0 12px 30px rgba(15, 23, 42, .08);
    }

    .todo-card[draggable="true"],
    .todo-check-item[draggable="true"] {
        cursor: grab;
    }

    .todo-card.is-dragging,
    .todo-check-item.is-dragging {
        opacity: .55;
    }

    .todo-card-head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 12px;
    }

    .todo-status-chip {
        display: inline-flex;
        align-items: center;
        border-radius: 9999px;
        padding: 5px 10px;
        font-size: 11px;
        font-weight: 800;
    }

    .todo-status-chip.todo {
        background: #fef3c7;
        color: #92400e;
    }

    .todo-status-chip.in_progress {
        background: #dbeafe;
        color: #1d4ed8;
    }

    .todo-status-chip.done {
        background: #dcfce7;
        color: #166534;
    }

    .todo-status-chip.blocked {
        background: #fee2e2;
        color: #b91c1c;
    }

    .todo-status-chip.cancelled {
        background: #e2e8f0;
        color: #475569;
    }

    .todo-priority {
        border-radius: 9999px;
        padding: 5px 10px;
        background: #ecfeff;
        color: #155e75;
        font-size: 11px;
        font-weight: 800;
    }

    .todo-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        margin-top: 10px;
        color: #64748b;
        font-size: 12px;
    }

    .todo-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 14px;
    }

    .todo-action {
        border: 1px solid #dbeafe;
        border-radius: 9999px;
        padding: 7px 11px;
        background: #fff;
        color: #0f172a;
        font-size: 12px;
        font-weight: 800;
    }

    .todo-action.primary {
        background: #0f172a;
        color: #fff;
        border-color: #0f172a;
    }

    .todo-pin {
        border: 1px solid #cbd5e1;
        border-radius: 9999px;
        padding: 7px 11px;
        background: #fff;
        color: #475569;
        font-size: 12px;
        font-weight: 800;
    }

    .todo-pin.is-active {
        border-color: #0f172a;
        background: #0f172a;
        color: #fff;
    }

    .todo-drag-handle {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 32px;
        height: 32px;
        border-radius: 9999px;
        border: 1px solid #dbeafe;
        background: #fff;
        color: #64748b;
        font-size: 14px;
        font-weight: 900;
    }

    .todo-schedule {
        margin-top: 10px;
        border-radius: 18px;
        background: #ecfeff;
        padding: 12px;
        color: #155e75;
        font-size: 12px;
    }

    .todo-checklist {
        display: none;
        margin-top: 18px;
    }

    .todo-checklist.is-active {
        display: block;
    }

    .todo-check-item {
        display: grid;
        grid-template-columns: auto 1fr auto;
        gap: 14px;
        align-items: center;
        border-radius: 20px;
        border: 1px solid #e2e8f0;
        background: #fff;
        padding: 14px 16px;
        position: relative;
    }

    .todo-check-item+.todo-check-item {
        margin-top: 10px;
    }

    .todo-check-toggle {
        width: 22px;
        height: 22px;
        border-radius: 9999px;
        border: 2px solid #94a3b8;
        background: #fff;
    }

    .todo-check-item.is-done .todo-check-toggle {
        background: #10b981;
        border-color: #10b981;
    }

    .todo-check-item.is-done .todo-check-title {
        text-decoration: line-through;
        color: #94a3b8;
    }

    .todo-detail {
        position: sticky;
        top: 24px;
        align-self: start;
        border-radius: 26px;
        border: 1px solid #dbeafe;
        background: linear-gradient(180deg, #fff 0%, #f8fbff 100%);
        padding: 18px;
    }

    .todo-detail-empty,
    .todo-empty {
        border-radius: 22px;
        border: 1px dashed #bfdbfe;
        background: #f8fbff;
        padding: 22px;
        color: #64748b;
        text-align: center;
    }

    .todo-attachments-list {
        display: grid;
        gap: 10px;
        margin-top: 14px;
    }

    .todo-attachment-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        border-radius: 18px;
        border: 1px solid #dbeafe;
        background: #fff;
        padding: 12px 14px;
    }

    .todo-detail-section {
        margin-top: 18px;
    }

    .todo-detail-section-title {
        font-size: 13px;
        font-weight: 800;
        color: #0f172a;
    }

    .todo-comment-list {
        display: grid;
        gap: 10px;
        margin-top: 12px;
    }

    .todo-comment-item {
        border-radius: 18px;
        border: 1px solid #e2e8f0;
        background: #fff;
        padding: 12px 14px;
    }

    .todo-textarea {
        width: 100%;
        border-radius: 18px;
        border: 1px solid #dbeafe;
        background: #fff;
        padding: 12px 14px;
        color: #0f172a;
        font-size: 14px;
        min-height: 96px;
    }

    .todo-file-input {
        width: 100%;
        border-radius: 18px;
        border: 1px dashed #7dd3fc;
        background: #f8fbff;
        padding: 12px;
        color: #155e75;
        font-size: 13px;
    }

    .todo-detail-message {
        margin-top: 10px;
        font-size: 12px;
        color: #64748b;
    }

    @media (max-width: 1200px) {

        .todo-workspace,
        .todo-sections {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 1024px) {

        .todo-kpis,
        .todo-toolbar {
            grid-template-columns: 1fr;
        }

        .todo-check-item {
            grid-template-columns: auto 1fr;
        }

        .todo-detail {
            position: static;
        }
    }
</style>
<div class="min-h-screen bg-[radial-gradient(circle_at_top_left,_rgba(224,231,255,0.95),_transparent_42%),radial-gradient(circle_at_top_right,_rgba(186,230,253,0.85),_transparent_34%),linear-gradient(180deg,_#f8fafc_0%,_#eef2ff_100%)] pb-12">
    <div class="container mx-auto px-4 py-8">
        <div class="todo-shell">
            <section class="todo-hero">
                <div class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
                    <div class="max-w-3xl">
                        <p class="text-xs font-semibold uppercase tracking-[0.34em] text-cyan-200">Mes tâches</p>
                        <h1 id="pageTitle" class="mt-3 text-3xl font-semibold tracking-tight md:text-5xl">Mon espace de suivi personnel</h1>
                        <p class="mt-4 max-w-2xl text-sm text-slate-200 md:text-base">Retrouvez un suivi quotidien plus proche de Microsoft To Do, avec panneau détails, épinglage personnel et créneau synchronisé dans l'emploi du temps.</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <div id="viewModeSwitch" class="todo-view-switch">
                            <button type="button" class="todo-view-btn is-active" data-view="sections">Sections</button>
                            <button type="button" class="todo-view-btn" data-view="checklist">Checklist compacte</button>
                        </div>
                        <a href="taches_communication.php?mine=1" class="inline-flex items-center rounded-2xl border border-white/15 px-5 py-3 text-sm font-semibold text-white/90 transition hover:bg-white/10">Vue équipe</a>
                    </div>
                </div>
                <div id="heroStats" class="todo-kpis"></div>
            </section>

            <section class="todo-panel">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.26em] text-cyan-600">Organisation</p>
                        <h2 class="mt-2 text-2xl font-semibold text-slate-950">Mes sections de travail</h2>
                    </div>
                    <div class="todo-view-switch light">
                        <a href="dashboard_communication.php" class="todo-view-btn">Dashboard</a>
                        <a href="taches_communication.php?mine=1" class="todo-view-btn">Ajouter une tâche</a>
                    </div>
                </div>
                <div class="todo-toolbar mt-6">
                    <input id="searchInput" type="text" class="todo-input" placeholder="Rechercher un titre, une description..." />
                    <select id="priorityFilter" class="todo-select">
                        <option value="">Toutes les priorités</option>
                    </select>
                    <select id="statusFilter" class="todo-select">
                        <option value="">Tous les statuts</option>
                    </select>
                    <button id="refreshBtn" type="button" class="todo-action primary">Actualiser</button>
                </div>
                <div id="quickFilters" class="todo-chip-row"></div>
                <div class="todo-workspace">
                    <div>
                        <div id="sectionsView" class="todo-sections"></div>
                        <div id="checklistView" class="todo-checklist"></div>
                    </div>
                    <aside id="detailPanel" class="todo-detail">
                        <div class="todo-detail-empty">Sélectionnez une tâche pour afficher son panneau Détails, son créneau emploi du temps et vos actions personnelles.</div>
                    </aside>
                </div>
            </section>
        </div>
    </div>
</div>
<script>
    const state = {
        me: null,
        statuses: {},
        priorities: {},
        tasks: [],
        search: '',
        status: '',
        priority: '',
        quickFilter: 'all',
        view: 'sections',
        selectedTaskId: 0,
        draggingTaskId: 0,
        savingOrder: false,
        detailComments: {},
        detailAttachments: {},
        detailLoadingTaskId: 0,
        detailMessage: ''
    };

    const heroStats = document.getElementById('heroStats');
    const searchInput = document.getElementById('searchInput');
    const priorityFilter = document.getElementById('priorityFilter');
    const statusFilter = document.getElementById('statusFilter');
    const quickFilters = document.getElementById('quickFilters');
    const sectionsView = document.getElementById('sectionsView');
    const checklistView = document.getElementById('checklistView');
    const refreshBtn = document.getElementById('refreshBtn');
    const viewModeSwitch = document.getElementById('viewModeSwitch');
    const pageTitle = document.getElementById('pageTitle');
    const detailPanel = document.getElementById('detailPanel');

    function escapeHtml(value) {
        return String(value || '').replace(/[&<>"']/g, character => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;'
        } [character]));
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

    function isToday(iso) {
        if (!iso) {
            return false;
        }
        const date = new Date(iso);
        const now = new Date();
        return !Number.isNaN(date.getTime()) &&
            date.getFullYear() === now.getFullYear() &&
            date.getMonth() === now.getMonth() &&
            date.getDate() === now.getDate();
    }

    function isWithinDays(iso, days) {
        if (!iso) {
            return false;
        }
        const date = new Date(iso);
        if (Number.isNaN(date.getTime())) {
            return false;
        }
        const diff = date.getTime() - Date.now();
        return diff >= 0 && diff <= days * 24 * 60 * 60 * 1000;
    }

    async function fetchJson(url, options = {}) {
        const response = await fetch(url, options);
        const text = await response.text();
        const data = text ? JSON.parse(text) : null;
        if (!response.ok || !data?.ok) {
            throw new Error(data?.error || 'Erreur serveur');
        }
        return data;
    }

    function classifyTask(task) {
        if (task.status === 'cancelled') {
            return 'cancelled';
        }
        if (task.status === 'done') {
            return 'recent';
        }
        if (task.is_late) {
            return 'late';
        }
        if (isToday(task.deadline_iso)) {
            return 'today';
        }
        return 'planned';
    }

    function getFilteredTasks() {
        let tasks = state.tasks.slice();
        if (state.search) {
            const query = state.search.toLowerCase();
            tasks = tasks.filter(task => [task.title, task.description, task.priority_label, task.status_label].join(' ').toLowerCase().includes(query));
        }
        if (state.status) {
            tasks = tasks.filter(task => task.status === state.status);
        }
        if (state.priority) {
            tasks = tasks.filter(task => task.priority === state.priority);
        }
        if (state.quickFilter === 'today') {
            tasks = tasks.filter(task => classifyTask(task) === 'today');
        } else if (state.quickFilter === 'late') {
            tasks = tasks.filter(task => classifyTask(task) === 'late');
        } else if (state.quickFilter === 'planned') {
            tasks = tasks.filter(task => classifyTask(task) === 'planned');
        } else if (state.quickFilter === 'recent') {
            tasks = tasks.filter(task => classifyTask(task) === 'recent');
        } else if (state.quickFilter === 'next7') {
            tasks = tasks.filter(task => !['done', 'cancelled'].includes(task.status) && isWithinDays(task.deadline_iso, 7));
        } else if (state.quickFilter === 'cancelled') {
            tasks = tasks.filter(task => classifyTask(task) === 'cancelled');
        }
        return tasks;
    }

    async function loadTaskComments(taskId) {
        const data = await fetchJson('../api/communication_messages.php?task_id=' + encodeURIComponent(String(taskId || 0)));
        return Array.isArray(data.comments) ? data.comments : [];
    }

    async function loadTaskAttachments(taskId) {
        const data = await fetchJson('../api/communication_attachments.php?task_id=' + encodeURIComponent(String(taskId || 0)) + '&limit=250');
        return Array.isArray(data.attachments) ? data.attachments : [];
    }

    async function uploadAttachmentsToTask(taskId, files) {
        const formData = new window.FormData();
        formData.set('action', 'upload_attachments');
        formData.set('task_id', String(taskId || 0));
        Array.from(files || []).forEach(file => formData.append('attachments[]', file));
        await fetchJson('../api/communication_send.php', {
            method: 'POST',
            body: formData
        });
    }

    async function addCommentToTask(taskId, message) {
        const formData = new window.FormData();
        formData.set('task_id', String(taskId || 0));
        formData.set('message', message);
        await fetchJson('../api/communication_comment.php', {
            method: 'POST',
            body: formData
        });
    }

    async function ensureSelectedTaskDetails(task) {
        const taskId = Number(task?.id || 0);
        if (!taskId) {
            return;
        }

        const needsComments = !Array.isArray(state.detailComments[taskId]);
        const needsAttachments = !Array.isArray(state.detailAttachments[taskId]);
        if (!needsComments && !needsAttachments) {
            return;
        }

        state.detailLoadingTaskId = taskId;
        renderDetailPanel(task);

        try {
            const [comments, attachments] = await Promise.all([
                needsComments ? loadTaskComments(taskId) : Promise.resolve(state.detailComments[taskId]),
                needsAttachments ? loadTaskAttachments(taskId) : Promise.resolve(state.detailAttachments[taskId]),
            ]);
            state.detailComments[taskId] = comments;
            state.detailAttachments[taskId] = attachments;
        } finally {
            state.detailLoadingTaskId = 0;
            if (Number(state.selectedTaskId || 0) === taskId) {
                renderDetailPanel(getSelectedTask(getFilteredTasks()));
                bindTaskActions();
            }
        }
    }

    function getSelectedTask(tasks = state.tasks) {
        const selected = tasks.find(task => Number(task.id || 0) === Number(state.selectedTaskId || 0));
        if (selected) {
            return selected;
        }
        const fallback = tasks[0] || null;
        state.selectedTaskId = fallback ? Number(fallback.id || 0) : 0;
        return fallback;
    }

    function renderHeroStats(tasks) {
        const items = [{
                label: 'Mes tâches',
                value: tasks.length,
                helper: 'Périmètre personnel'
            },
            {
                label: 'Aujourd\'hui',
                value: tasks.filter(task => classifyTask(task) === 'today').length,
                helper: 'À traiter maintenant'
            },
            {
                label: 'En retard',
                value: tasks.filter(task => classifyTask(task) === 'late').length,
                helper: 'À rattraper'
            },
            {
                label: 'Terminées',
                value: tasks.filter(task => task.status === 'done').length,
                helper: 'Récentes et clôturées'
            },
            {
                label: 'Annulées',
                value: tasks.filter(task => task.status === 'cancelled').length,
                helper: 'Sorties du flux actif'
            }
        ];
        heroStats.innerHTML = items.map(item => `<div class="todo-kpi"><span class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">${escapeHtml(item.label)}</span><strong>${Number(item.value || 0).toLocaleString('fr-FR')}</strong><div class="mt-2 text-sm text-slate-500">${escapeHtml(item.helper)}</div></div>`).join('');
    }

    function renderFilterOptions() {
        priorityFilter.innerHTML = ['<option value="">Toutes les priorités</option>']
            .concat(Object.entries(state.priorities).map(([key, label]) => `<option value="${key}">${escapeHtml(label)}</option>`))
            .join('');
        priorityFilter.value = state.priority;

        statusFilter.innerHTML = ['<option value="">Tous les statuts</option>']
            .concat(Object.entries(state.statuses).map(([key, label]) => `<option value="${key}">${escapeHtml(label)}</option>`))
            .join('');
        statusFilter.value = state.status;

        const filters = [
            ['all', 'Tout'],
            ['today', 'Aujourd\'hui'],
            ['planned', 'Planifié'],
            ['late', 'En retard'],
            ['recent', 'Terminé récemment'],
            ['next7', '7 prochains jours'],
            ['cancelled', 'Annulées']
        ];
        quickFilters.innerHTML = filters.map(([key, label]) => `<button type="button" class="todo-chip ${state.quickFilter === key ? 'is-active' : ''}" data-quick-filter="${key}">${escapeHtml(label)}</button>`).join('');
        quickFilters.querySelectorAll('[data-quick-filter]').forEach(button => {
            button.addEventListener('click', () => {
                state.quickFilter = button.getAttribute('data-quick-filter') || 'all';
                renderAll();
            });
        });

        viewModeSwitch.querySelectorAll('[data-view]').forEach(button => {
            button.classList.toggle('is-active', button.getAttribute('data-view') === state.view);
        });
    }

    function taskCardMarkup(task, variant = 'card') {
        const isSelected = Number(task.id || 0) === Number(state.selectedTaskId || 0);
        const scheduleMarkup = task.schedule_start_iso ?
            `<div class="todo-schedule"><div class="font-semibold">Créneau emploi du temps</div><div class="mt-1">${escapeHtml(formatDateTime(task.schedule_start_iso))} → ${escapeHtml(formatDateTime(task.schedule_end_iso))}</div><div class="mt-1">${escapeHtml(task.schedule_status_label || '')}</div></div>` :
            '';
        const pinLabel = task.is_pinned ? 'Épinglée' : 'Épingler';

        if (variant === 'check') {
            const nextChecklistStatus = task.status === 'done' ? 'todo' : (task.status === 'cancelled' ? 'todo' : 'done');
            return `<article class="todo-check-item ${task.status === 'done' ? 'is-done' : ''} ${isSelected ? 'is-selected' : ''}" draggable="true" data-task-id="${task.id}"><button type="button" class="todo-check-toggle" data-status-action="${nextChecklistStatus}" data-task-id="${task.id}" aria-label="Basculer le statut"></button><div class="min-w-0"><button type="button" class="w-full text-left" data-select-task="${task.id}"><div class="todo-check-title truncate text-sm font-semibold text-slate-950">${escapeHtml(task.title)}</div><div class="mt-1 text-xs text-slate-500">${escapeHtml(formatDateTime(task.deadline_iso))} • ${escapeHtml(task.priority_label)} • ${escapeHtml(task.status_label)} • ${Number(task.duration_minutes || 60).toLocaleString('fr-FR')} min</div></button></div><div class="flex flex-wrap justify-end gap-2"><button type="button" class="todo-pin ${task.is_pinned ? 'is-active' : ''}" data-pin-task="${task.id}" data-pin-value="${task.is_pinned ? '0' : '1'}">${escapeHtml(pinLabel)}</button><span class="todo-drag-handle">::</span></div></article>`;
        }

        const canStart = !['in_progress', 'done', 'cancelled'].includes(task.status);
        const canCancel = !['done', 'cancelled'].includes(task.status);
        const completionAction = task.status === 'done' || task.status === 'cancelled' ?
            `<button type="button" class="todo-action" data-status-action="todo" data-task-id="${task.id}">Rouvrir</button>` :
            `<button type="button" class="todo-action primary" data-status-action="done" data-task-id="${task.id}">Terminer</button>`;

        return `<article class="todo-card ${task.is_late ? 'is-late' : ''} ${isSelected ? 'is-selected' : ''}" draggable="true" data-task-id="${task.id}"><div class="todo-card-head"><button type="button" class="min-w-0 flex-1 text-left" data-select-task="${task.id}"><div class="truncate text-sm font-semibold text-slate-950">${escapeHtml(task.title)}</div><div class="mt-2 text-sm text-slate-600">${escapeHtml(task.description || 'Sans description')}</div></button><div class="flex items-center gap-2"><span class="todo-status-chip ${escapeHtml(task.status)}">${escapeHtml(task.status_label)}</span><span class="todo-drag-handle">::</span></div></div><div class="todo-meta"><span>Deadline : ${escapeHtml(formatDateTime(task.deadline_iso))}</span><span>${Number(task.duration_minutes || 60).toLocaleString('fr-FR')} min</span><span>${Number(task.comments_count || 0).toLocaleString('fr-FR')} commentaire(s)</span><span>${Number(task.attachments_count || 0).toLocaleString('fr-FR')} pièce(s)</span></div>${scheduleMarkup}<div class="todo-actions">${canStart ? `<button type="button" class="todo-action" data-status-action="in_progress" data-task-id="${task.id}">Démarrer</button>` : ''}${completionAction}${canCancel ? `<button type="button" class="todo-action" data-status-action="cancelled" data-task-id="${task.id}">Annuler</button>` : ''}<button type="button" class="todo-action" data-select-task="${task.id}">Détails</button><button type="button" class="todo-pin ${task.is_pinned ? 'is-active' : ''}" data-pin-task="${task.id}" data-pin-value="${task.is_pinned ? '0' : '1'}">${escapeHtml(pinLabel)}</button><span class="todo-priority">${escapeHtml(task.priority_label)}</span></div></article>`;
    }

    function renderSections(tasks) {
        const groups = [{
                title: 'Aujourd\'hui',
                items: tasks.filter(task => classifyTask(task) === 'today')
            },
            {
                title: 'Planifié',
                items: tasks.filter(task => classifyTask(task) === 'planned')
            },
            {
                title: 'En retard',
                items: tasks.filter(task => classifyTask(task) === 'late')
            },
            {
                title: 'Terminé récemment',
                items: tasks.filter(task => classifyTask(task) === 'recent').slice(0, 12)
            },
            {
                title: 'Annulées',
                items: tasks.filter(task => classifyTask(task) === 'cancelled').slice(0, 12)
            }
        ];

        sectionsView.innerHTML = groups.map(group => `<section class="todo-section"><div class="todo-section-header"><div class="text-sm font-semibold text-slate-950">${escapeHtml(group.title)}</div><span class="todo-count">${Number(group.items.length || 0).toLocaleString('fr-FR')}</span></div><div class="todo-section-list" data-drop-group="1">${group.items.length ? group.items.map(task => taskCardMarkup(task, 'card')).join('') : '<div class="todo-empty">Aucune tâche dans cette section.</div>'}</div></section>`).join('');
        sectionsView.style.display = state.view === 'sections' ? 'grid' : 'none';
    }

    function renderChecklist(tasks) {
        checklistView.classList.toggle('is-active', state.view === 'checklist');
        if (state.view !== 'checklist') {
            checklistView.innerHTML = '';
            return;
        }
        checklistView.innerHTML = tasks.length ?
            `<div data-drop-group="1">${tasks.map(task => taskCardMarkup(task, 'check')).join('')}</div>` :
            '<div class="todo-empty">Aucune tâche personnelle à afficher.</div>';
    }

    function renderDetailPanel(task) {
        if (!task) {
            detailPanel.innerHTML = '<div class="todo-detail-empty">Sélectionnez une tâche pour afficher son panneau Détails, son créneau emploi du temps et vos actions personnelles.</div>';
            return;
        }

        const detailAttachments = Array.isArray(state.detailAttachments[task.id]) ? state.detailAttachments[task.id] : (Array.isArray(task.attachments) ? task.attachments : []);
        const detailComments = Array.isArray(state.detailComments[task.id]) ? state.detailComments[task.id] : [];
        const isLoadingDetails = Number(state.detailLoadingTaskId || 0) === Number(task.id || 0);
        const attachmentsMarkup = detailAttachments.length ?
            `<div class="todo-attachments-list">${detailAttachments.map(attachment => `<div class="todo-attachment-item"><div class="min-w-0"><div class="truncate text-sm font-semibold text-slate-900">${escapeHtml(attachment.original_name || 'Fichier')}</div><div class="mt-1 text-xs text-slate-500">${escapeHtml(attachment.mime_type || 'Fichier joint')}</div></div><a href="${escapeHtml(attachment.download_url || '#')}" class="todo-action">Télécharger</a></div>`).join('')}</div>` :
            '<div class="todo-empty mt-4">Aucune pièce jointe récente.</div>';
        const commentsMarkup = detailComments.length ?
            `<div class="todo-comment-list">${detailComments.map(comment => `<div class="todo-comment-item"><div class="flex items-center justify-between gap-3"><div class="text-sm font-semibold text-slate-950">${escapeHtml(comment.author_display || 'Utilisateur')}</div><div class="text-xs text-slate-500">${escapeHtml(formatDateTime(comment.created_at || ''))}</div></div><div class="mt-2 text-sm leading-6 text-slate-600">${escapeHtml(comment.message || '')}</div></div>`).join('')}</div>` :
            '<div class="todo-empty mt-4">Aucun commentaire pour cette tâche.</div>';
        const canStart = !['in_progress', 'done', 'cancelled'].includes(task.status);
        const canCancel = !['done', 'cancelled'].includes(task.status);
        const detailPrimaryAction = task.status === 'done' || task.status === 'cancelled' ?
            `<button type="button" class="todo-action" data-status-action="todo" data-task-id="${task.id}">Rouvrir</button>` :
            `<button type="button" class="todo-action primary" data-status-action="done" data-task-id="${task.id}">Terminer</button>`;

        detailPanel.innerHTML = `<div class="flex items-start justify-between gap-3"><div><div class="text-xs font-semibold uppercase tracking-[0.24em] text-cyan-600">Détails tâche</div><h3 class="mt-2 text-xl font-semibold text-slate-950">${escapeHtml(task.title)}</h3></div><button type="button" class="todo-pin ${task.is_pinned ? 'is-active' : ''}" data-pin-task="${task.id}" data-pin-value="${task.is_pinned ? '0' : '1'}">${task.is_pinned ? 'Épinglée' : 'Épingler'}</button></div><p class="mt-4 text-sm text-slate-600">${escapeHtml(task.description || 'Aucune description fournie.')}</p><div class="todo-meta"><span>Deadline : ${escapeHtml(formatDateTime(task.deadline_iso))}</span><span>Durée : ${Number(task.duration_minutes || 60).toLocaleString('fr-FR')} min</span><span>Priorité : ${escapeHtml(task.priority_label || '')}</span><span>Statut : ${escapeHtml(task.status_label || '')}</span></div>${task.schedule_start_iso ? `<div class="todo-schedule"><div class="font-semibold">Créneau emploi du temps synchronisé</div><div class="mt-1">${escapeHtml(formatDateTime(task.schedule_start_iso))} → ${escapeHtml(formatDateTime(task.schedule_end_iso))}</div><div class="mt-1">${escapeHtml(task.schedule_status_label || '')}</div></div>` : '<div class="todo-schedule">La tâche n\'a pas encore de créneau visible.</div>'}<div class="todo-actions">${canStart ? `<button type="button" class="todo-action" data-status-action="in_progress" data-task-id="${task.id}">Démarrer</button>` : ''}${detailPrimaryAction}${canCancel ? `<button type="button" class="todo-action" data-status-action="cancelled" data-task-id="${task.id}">Annuler</button>` : ''}<a href="taches_communication.php?mine=1&task_id=${encodeURIComponent(String(task.id || 0))}" class="todo-action">Ouvrir dans la vue équipe</a></div><div class="todo-detail-section"><div class="todo-detail-section-title">Commentaires</div>${isLoadingDetails && !detailComments.length ? '<div class="todo-empty mt-4">Chargement des commentaires...</div>' : commentsMarkup}<textarea class="todo-textarea" data-comment-input="${task.id}" placeholder="Ajouter un commentaire visible par l\'équipe..."></textarea><div class="todo-actions mt-3"><button type="button" class="todo-action primary" data-submit-comment="${task.id}">Publier le commentaire</button></div></div><div class="todo-detail-section"><div class="todo-detail-section-title">Pièces jointes</div>${isLoadingDetails && !detailAttachments.length ? '<div class="todo-empty mt-4">Chargement des pièces jointes...</div>' : attachmentsMarkup}<input type="file" multiple class="todo-file-input mt-4" data-detail-upload-input="${task.id}" /><div class="todo-detail-message">Ajoutez des fichiers ici pour les rattacher directement à cette tâche.</div></div>${state.detailMessage ? `<div class="todo-detail-message">${escapeHtml(state.detailMessage)}</div>` : ''}`;
    }

    async function updateTaskStatus(taskId, status) {
        const formData = new window.FormData();
        formData.set('action', 'update_status');
        formData.set('task_id', String(taskId || 0));
        formData.set('status', status);
        await fetchJson('../api/communication_send.php', {
            method: 'POST',
            body: formData
        });
        state.detailMessage = 'Statut mis à jour.';
    }

    async function togglePinned(taskId, isPinned) {
        const formData = new window.FormData();
        formData.set('action', 'toggle_pin');
        formData.set('task_id', String(taskId || 0));
        formData.set('is_pinned', isPinned ? '1' : '0');
        await fetchJson('../api/communication_send.php', {
            method: 'POST',
            body: formData
        });
    }

    async function persistOrderFromContainer(container) {
        if (!container || state.savingOrder) {
            return;
        }
        const orderedTaskIds = Array.from(container.querySelectorAll('[data-task-id]'))
            .map(node => Number(node.getAttribute('data-task-id') || '0'))
            .filter(Boolean);
        if (!orderedTaskIds.length) {
            return;
        }

        state.savingOrder = true;
        try {
            const formData = new window.FormData();
            formData.set('action', 'save_task_order');
            formData.set('ordered_task_ids', JSON.stringify(orderedTaskIds));
            await fetchJson('../api/communication_send.php', {
                method: 'POST',
                body: formData
            });
        } finally {
            state.savingOrder = false;
        }
    }

    function bindDragAndDrop() {
        document.querySelectorAll('[data-task-id]').forEach(node => {
            node.addEventListener('dragstart', () => {
                state.draggingTaskId = Number(node.getAttribute('data-task-id') || '0');
                node.classList.add('is-dragging');
            });
            node.addEventListener('dragend', () => {
                node.classList.remove('is-dragging');
                state.draggingTaskId = 0;
            });
            node.addEventListener('dragover', event => {
                event.preventDefault();
            });
            node.addEventListener('drop', async event => {
                event.preventDefault();
                const dragged = document.querySelector(`[data-task-id="${state.draggingTaskId}"]`);
                if (!dragged || dragged === node) {
                    return;
                }
                node.parentNode.insertBefore(dragged, node);
                try {
                    await persistOrderFromContainer(node.parentNode);
                    await loadTasks(false);
                } catch (error) {
                    window.alert(error.message);
                    await loadTasks(false);
                }
            });
        });
    }

    function bindTaskActions() {
        document.querySelectorAll('[data-status-action]').forEach(button => {
            button.addEventListener('click', async () => {
                try {
                    await updateTaskStatus(Number(button.getAttribute('data-task-id') || '0'), button.getAttribute('data-status-action') || 'todo');
                    await loadTasks(false);
                } catch (error) {
                    window.alert(error.message);
                }
            });
        });

        document.querySelectorAll('[data-select-task]').forEach(button => {
            button.addEventListener('click', () => {
                state.selectedTaskId = Number(button.getAttribute('data-select-task') || '0');
                state.detailMessage = '';
                renderAll();
            });
        });

        document.querySelectorAll('[data-pin-task]').forEach(button => {
            button.addEventListener('click', async () => {
                try {
                    await togglePinned(Number(button.getAttribute('data-pin-task') || '0'), button.getAttribute('data-pin-value') === '1');
                    state.detailMessage = 'Préférence mise à jour.';
                    await loadTasks(false);
                } catch (error) {
                    window.alert(error.message);
                }
            });
        });

        detailPanel.querySelectorAll('[data-submit-comment]').forEach(button => {
            button.addEventListener('click', async () => {
                const taskId = Number(button.getAttribute('data-submit-comment') || '0');
                const input = detailPanel.querySelector(`[data-comment-input="${taskId}"]`);
                const message = String(input?.value || '').trim();
                if (!taskId || message === '') {
                    return;
                }
                try {
                    await addCommentToTask(taskId, message);
                    delete state.detailComments[taskId];
                    state.detailMessage = 'Commentaire ajouté.';
                    await loadTasks(false);
                } catch (error) {
                    window.alert(error.message);
                }
            });
        });

        detailPanel.querySelectorAll('[data-detail-upload-input]').forEach(input => {
            input.addEventListener('change', async () => {
                const taskId = Number(input.getAttribute('data-detail-upload-input') || '0');
                const files = input.files;
                if (!taskId || !files || !files.length) {
                    return;
                }
                try {
                    await uploadAttachmentsToTask(taskId, files);
                    delete state.detailAttachments[taskId];
                    state.detailMessage = 'Pièces jointes ajoutées.';
                    input.value = '';
                    await loadTasks(false);
                } catch (error) {
                    window.alert(error.message);
                }
            });
        });

        bindDragAndDrop();
    }

    function renderAll() {
        const tasks = getFilteredTasks();
        const selectedTask = getSelectedTask(tasks);
        renderHeroStats(tasks);
        renderFilterOptions();
        renderSections(tasks);
        renderChecklist(tasks);
        renderDetailPanel(selectedTask);
        bindTaskActions();
        if (selectedTask) {
            ensureSelectedTaskDetails(selectedTask);
        }
        pageTitle.textContent = state.me ? 'Mes tâches de ' + state.me.display : 'Mon espace de suivi personnel';
    }

    async function loadBootstrap() {
        const data = await fetchJson('../api/communication_state.php');
        state.me = data.me || null;
        state.statuses = data.statuses || {};
        state.priorities = data.priorities || {};
        renderFilterOptions();
    }

    async function loadTasks(forceSelectFirst = true) {
        if (!state.me?.id) {
            return;
        }
        const params = new window.URLSearchParams();
        params.set('assignee_user_id', String(state.me.id));
        params.set('viewer_user_id', String(state.me.id));
        params.set('limit', '250');
        const data = await fetchJson('../api/communication_tasks.php?' + params.toString());
        state.tasks = Array.isArray(data.tasks) ? data.tasks : [];
        if (forceSelectFirst && !state.selectedTaskId && state.tasks.length) {
            state.selectedTaskId = Number(state.tasks[0].id || 0);
        }
        if (!state.tasks.some(task => Number(task.id || 0) === Number(state.selectedTaskId || 0))) {
            state.selectedTaskId = state.tasks.length ? Number(state.tasks[0].id || 0) : 0;
        }
        const visibleTaskIds = new Set(state.tasks.map(task => Number(task.id || 0)));
        Object.keys(state.detailComments).forEach(taskId => {
            if (!visibleTaskIds.has(Number(taskId))) {
                delete state.detailComments[taskId];
            }
        });
        Object.keys(state.detailAttachments).forEach(taskId => {
            if (!visibleTaskIds.has(Number(taskId))) {
                delete state.detailAttachments[taskId];
            }
        });
        renderAll();
    }

    searchInput.addEventListener('input', () => {
        state.search = searchInput.value.trim();
        renderAll();
    });

    priorityFilter.addEventListener('change', () => {
        state.priority = priorityFilter.value;
        renderAll();
    });

    statusFilter.addEventListener('change', () => {
        state.status = statusFilter.value;
        renderAll();
    });

    refreshBtn.addEventListener('click', () => loadTasks(false));

    viewModeSwitch.querySelectorAll('[data-view]').forEach(button => {
        button.addEventListener('click', () => {
            state.view = button.getAttribute('data-view') || 'sections';
            renderAll();
        });
    });

    document.addEventListener('DOMContentLoaded', async () => {
        await loadBootstrap();
        await loadTasks();
    });
</script>
<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>