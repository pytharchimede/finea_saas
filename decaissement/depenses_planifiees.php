<?php
session_start();
require_once __DIR__ . '/../auth_check.php';
$pdo = null;
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/AuditTrail.php';
$pageTitle = 'Dépenses Planifiées - DécaissApp';
$activeMenu = 'depenses_planifiees';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';

$pdo = Database::getConnection();
AuditTrail::log('page_view', [
    'module' => 'decaissement',
    'action' => 'depenses_planifiees_view',
    'entity_type' => 'fiche_depenses_planifiees',
    'entity_label' => 'Dépenses planifiées',
    'page_name' => 'depenses_planifiees.php',
    'result' => 'success',
    'details' => [
        'view_mode' => $_GET['view_mode'] ?? 'calendar',
    ],
], $pdo);

?>
<style>
    /* Similar styles to liste_fiches_compta.php */
    .tab-pill {
        background: #f1f5f9;
        padding: 4px 10px;
        border-radius: 999px;
        font-weight: 500;
        transition: .2s;
        font-size: 11px;
    }

    .tab-pill.active {
        background: #4f46e5;
        color: #fff;
    }

    .tab-pill:not(.active):hover {
        background: #e2e8f0;
    }

    .card {
        border-radius: 1rem;
    }

    .modal {
        position: fixed;
        inset: 0;
        display: none;
        z-index: 1050;
        overflow-y: auto;
        overflow-x: hidden;
        background: rgba(0, 0, 0, .45);
        padding: 20px 10px;
    }

    .modal.show {
        display: block;
    }

    #reportModal {
        z-index: 1300;
    }

    .modal-dialog {
        position: relative;
        margin: 40px auto;
        max-width: 1200px;
        pointer-events: none;
        transform: translateY(-6px);
        opacity: .92;
        transition: all .18s ease-out;
    }

    .modal.show .modal-dialog {
        transform: translateY(0);
        opacity: 1;
    }

    .modal-content {
        pointer-events: auto;
        background: #fff;
        border-radius: 18px;
        box-shadow: 0 12px 32px -6px rgba(0, 0, 0, .35);
        border: 1px solid #e2e8f0;
    }

    .modal-header {
        padding: 14px 20px;
        border-bottom: 1px solid #e2e8f0;
    }

    .modal-body {
        padding: 18px 22px;
    }

    .modal-footer {
        padding: 12px 18px;
        background: #f8fafc;
        border-top: 1px solid #e2e8f0;
        border-bottom-left-radius: 18px;
        border-bottom-right-radius: 18px;
    }

    /* Styles du modal de fiche identique à depenses_planifiees_detail.php */
    .pf-modal {
        position: fixed;
        inset: 0;
        z-index: 1200;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 24px;
        background: rgba(15, 23, 42, 0.58);
        backdrop-filter: blur(6px);
    }

    .pf-modal.is-open {
        display: flex;
    }

    .pf-modal-dialog {
        width: min(1040px, 100%);
        max-height: calc(100vh - 48px);
        overflow: hidden;
        border-radius: 28px;
        border: 1px solid rgba(148, 163, 184, 0.25);
        background: radial-gradient(circle at top right, rgba(99, 102, 241, 0.14), transparent 30%), linear-gradient(180deg, rgba(255, 255, 255, 0.99), rgba(248, 250, 252, 0.98));
        box-shadow: 0 30px 80px rgba(15, 23, 42, 0.28);
    }

    .pf-modal-header {
        display: flex;
        align-items: start;
        justify-content: space-between;
        gap: 16px;
        padding: 24px 28px 18px;
        border-bottom: 1px solid rgba(226, 232, 240, 0.95);
    }

    .pf-modal-body {
        max-height: calc(100vh - 180px);
        overflow: auto;
        padding: 24px 28px 28px;
    }

    .pf-modal-grid {
        display: grid;
        gap: 18px;
        grid-template-columns: minmax(0, 1.15fr) minmax(320px, 0.85fr);
    }

    .pf-modal-close {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 42px;
        height: 42px;
        border-radius: 999px;
        border: 1px solid rgba(203, 213, 225, 0.95);
        background: white;
        color: #334155;
        font-size: 20px;
        font-weight: 700;
        transition: all 0.2s ease;
    }

    .pf-modal-close:hover {
        background: #eef2ff;
        color: #4338ca;
    }

    .pf-modal-title {
        font-size: 1.5rem;
        line-height: 1.2;
        font-weight: 800;
        color: #0f172a;
    }

    .pf-modal-subtitle {
        margin-top: 6px;
        font-size: 13px;
        color: #64748b;
    }

    .pf-panel {
        border: 1px solid rgba(226, 232, 240, 0.9);
        border-radius: 18px;
        background: rgba(255, 255, 255, 0.78);
        padding: 14px;
        backdrop-filter: blur(8px);
    }

    .pf-panel-title {
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.16em;
        text-transform: uppercase;
        color: #64748b;
        margin-bottom: 10px;
    }

    .pf-key-value {
        display: grid;
        grid-template-columns: 96px minmax(0, 1fr);
        gap: 10px;
        align-items: start;
        font-size: 13px;
        line-height: 1.55;
    }

    .pf-key-value+.pf-key-value {
        margin-top: 8px;
    }

    .pf-key {
        color: #64748b;
        font-weight: 600;
    }

    .pf-value {
        color: #0f172a;
        font-weight: 600;
        min-width: 0;
        word-break: break-word;
    }

    .pf-amount {
        font-size: 1.35rem;
        font-weight: 800;
        color: #047857;
        letter-spacing: -0.02em;
        line-height: 1;
    }

    @media (max-width: 900px) {
        .pf-modal-grid {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 640px) {
        .pf-modal {
            padding: 12px;
        }

        .pf-modal-header,
        .pf-modal-body {
            padding-left: 16px;
            padding-right: 16px;
        }
    }

    .event-content {
        min-height: 50px;
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        text-align: center;
        font-size: 12px;
        line-height: 1.2;
    }

    .event-content:hover {
        transform: scale(1.05);
        transition: transform 0.2s ease;
    }
</style>
<div class="container mx-auto py-10 px-4">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-3xl font-bold text-indigo-700">Dépenses Planifiées</h1>
        <button id="btnAideGlobal" class="btn btn-soft-info text-sm flex items-center gap-2"><span>🛈</span> Aide</button>
    </div>

    <!-- View Mode Tabs -->
    <div class="flex items-center gap-2 mb-4">
        <button class="tab-pill" data-view="calendar">Calendrier</button>
        <button class="tab-pill" data-view="card">Cartes</button>
        <button class="tab-pill" data-view="table">Tableau</button>
    </div>

    <div id="calendarPeriodControls" class="flex flex-wrap items-center gap-2 mb-4">
        <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Affichage calendrier</span>
        <button type="button" class="tab-pill calendar-period" data-calendar-view="dayGridMonth">Mois</button>
        <button type="button" class="tab-pill calendar-period active" data-calendar-view="timeGridWeek">Semaine</button>
        <button type="button" class="tab-pill calendar-period" data-calendar-view="timeGridDay">Jour</button>
    </div>

    <form id="searchForm" class="bg-white rounded-xl shadow p-6 mb-8 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4 items-end text-xs">
        <div>
            <label class="block text-gray-700 font-medium mb-1">Date début</label>
            <input type="date" name="date_debut" class="form-input w-full">
        </div>
        <div>
            <label class="block text-gray-700 font-medium mb-1">Date fin</label>
            <input type="date" name="date_fin" class="form-input w-full">
        </div>
        <div>
            <label class="block text-gray-700 font-medium mb-1">N° fiche</label>
            <input type="text" name="num_fiche" class="form-input w-full">
        </div>
        <div>
            <label class="block text-gray-700 font-medium mb-1">Montant min</label>
            <input type="number" name="montant_min" class="form-input w-full">
        </div>
        <div>
            <label class="block text-gray-700 font-medium mb-1">Montant max</label>
            <input type="number" name="montant_max" class="form-input w-full">
        </div>
        <div>
            <label class="block text-gray-700 font-medium mb-1">Chantier</label>
            <input type="text" name="chantier" class="form-input w-full">
        </div>
        <div>
            <label class="block text-gray-700 font-medium mb-1">Bénéficiaire</label>
            <input type="text" name="beneficiaire" class="form-input w-full">
        </div>
        <div>
            <label class="block text-gray-700 font-medium mb-1">Date création</label>
            <input type="date" name="date_creat_fiche" class="form-input w-full">
        </div>
        <div class="flex gap-2">
            <button type="submit" class="btn btn-primary">Rechercher</button>
            <button type="button" id="resetBtn" class="btn btn-ghost">Réinitialiser</button>
        </div>
        <div class="flex gap-2">
            <button type="button" id="exportExcel" class="btn btn-success">Export Excel</button>
            <button type="button" id="exportPdf" class="btn btn-danger">Export PDF</button>
        </div>
    </form>

    <!-- Calendar View -->
    <div id="calendar-view" class="bg-white rounded-xl shadow p-6 mb-8">
        <div id="calendar"></div>
    </div>

    <!-- Card View -->
    <div id="card-view" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-8" style="display: none;"></div>

    <!-- Table View -->
    <div id="table-view" class="bg-white rounded-xl shadow p-6" style="display: none;">
        <table class="w-full table-auto">
            <thead>
                <tr class="bg-gray-100">
                    <th class="px-4 py-2">N° Fiche</th>
                    <th class="px-4 py-2">Montant</th>
                    <th class="px-4 py-2">Date Décaissement</th>
                    <th class="px-4 py-2">Chantier</th>
                    <th class="px-4 py-2">Bénéficiaire</th>
                    <th class="px-4 py-2">Statut</th>
                    <th class="px-4 py-2">Action</th>
                </tr>
            </thead>
            <tbody id="table-body"></tbody>
        </table>
    </div>
</div>

<!-- Modal reporter -->
<div id="reportModal" class="modal">
    <div class="modal-dialog" style="max-width: 460px;">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-lg font-semibold">Reporter la fiche <span id="reportFicheLabel"></span></h5>
                <button type="button" class="btn-close" onclick="closeReportModal()">&times;</button>
            </div>
            <form id="reportForm">
                <div class="modal-body">
                    <input type="hidden" name="num_fiche" id="reportNumFiche">
                    <label class="block text-sm font-semibold text-slate-700 mb-2">Nouvelle date de décaissement</label>
                    <input type="date" name="date_min" id="reportDateMin" class="form-input w-full" required>
                    <p id="reportError" class="hidden mt-3 text-sm text-red-600"></p>
                </div>
                <div class="modal-footer flex justify-end gap-2">
                    <button type="button" class="btn btn-secondary" onclick="closeReportModal()">Annuler</button>
                    <button type="submit" class="btn btn-warning">Reporter</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal for date details -->
<div id="dateModal" class="modal">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-lg font-semibold">Dépenses du <span id="modalDate"></span></h5>
                <button type="button" class="btn-close" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="mb-4">
                    <div class="text-sm text-gray-600">Total: <span id="modalTotal" class="font-semibold text-green-600"></span></div>
                    <div class="text-sm text-gray-600">Nombre de fiches: <span id="modalCount" class="font-semibold"></span></div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-4">
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Par chantier</div>
                        <div id="modalByChantier" class="mt-2 space-y-2 text-sm"></div>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Par bénéficiaire</div>
                        <div id="modalByBeneficiaire" class="mt-2 space-y-2 text-sm"></div>
                    </div>
                </div>
                <div class="max-h-[420px] overflow-auto">
                    <table class="w-full table-auto border-collapse border border-gray-300">
                        <thead>
                            <tr class="bg-gray-100">
                                <th class="border border-gray-300 px-4 py-2 text-left">N° Fiche</th>
                                <th class="border border-gray-300 px-4 py-2 text-left">Montant</th>
                                <th class="border border-gray-300 px-4 py-2 text-left">Chantier</th>
                                <th class="border border-gray-300 px-4 py-2 text-left">Bénéficiaire</th>
                                <th class="border border-gray-300 px-4 py-2 text-left">Statut</th>
                                <th class="border border-gray-300 px-4 py-2 text-left">Action</th>
                            </tr>
                        </thead>
                        <tbody id="modalTableBody"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Fermer</button>
                <button type="button" class="btn btn-primary" onclick="openDetailPage()">Voir détails</button>
                <button type="button" class="btn btn-success" onclick="exportDateRecap()">Exporter</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal for fiche details -->
<div id="ficheDetailModal" class="pf-modal" aria-hidden="true">
    <div class="pf-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="ficheDetailModalTitle">
        <div class="pf-modal-header">
            <div>
                <h1 id="ficheDetailModalTitle" class="pf-modal-title">Détails de la fiche</h1>
                <p class="pf-modal-subtitle">Informations complètes de la dépense planifiée</p>
            </div>
            <button type="button" class="pf-modal-close" onclick="closeFicheModal()" aria-label="Fermer">&times;</button>
        </div>
        <div class="pf-modal-body">
            <div class="pf-modal-grid">
                <div>
                    <div class="pf-panel">
                        <h3 class="pf-panel-title">Informations générales</h3>
                        <div id="ficheGeneralInfo"></div>
                    </div>
                    <div class="pf-panel" style="margin-top: 16px;">
                        <h3 class="pf-panel-title">Chaîne hiérarchique</h3>
                        <div id="ficheHierarchyInfo"></div>
                    </div>
                </div>
                <div>
                    <div class="pf-panel">
                        <h3 class="pf-panel-title">Montant</h3>
                        <div class="text-center py-4">
                            <div id="ficheAmount" class="pf-amount"></div>
                            <div class="text-xs text-gray-500 mt-1">FCFA</div>
                        </div>
                    </div>
                    <div class="pf-panel" style="margin-top: 16px;">
                        <h3 class="pf-panel-title">Statut</h3>
                        <div id="ficheStatusInfo"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>
<script>
    let currentView = 'calendar';
    let calendar;
    let draggedQuickFiche = null;

    function switchView(view) {
        document.querySelectorAll('[data-view]').forEach(t => t.classList.remove('active'));
        document.querySelector(`[data-view="${view}"]`).classList.add('active');
        document.getElementById('calendar-view').style.display = view === 'calendar' ? 'block' : 'none';
        document.getElementById('card-view').style.display = view === 'card' ? 'grid' : 'none';
        document.getElementById('table-view').style.display = view === 'table' ? 'block' : 'none';
        document.getElementById('calendarPeriodControls').style.display = view === 'calendar' ? 'flex' : 'none';
        currentView = view;
        if (view === 'calendar') {
            if (calendar) calendar.render();
        }
    }

    document.querySelectorAll('[data-view]').forEach(btn => {
        btn.addEventListener('click', () => switchView(btn.getAttribute('data-view')));
    });

    document.querySelectorAll('.calendar-period').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.calendar-period').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            if (calendar) {
                calendar.changeView(btn.getAttribute('data-calendar-view'));
            }
        });
    });

    // Initialize calendar
    document.addEventListener('DOMContentLoaded', function() {
        switchView('calendar');
        const calendarEl = document.getElementById('calendar');
        calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: 'timeGridWeek',
            locale: 'fr',
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: ''
            },
            buttonText: {
                today: "Aujourd'hui"
            },
            height: 'auto',
            dayMaxEvents: 3,
            moreLinkClick: 'popover',
            slotMinTime: '07:00:00',
            slotMaxTime: '19:00:00',
            editable: true,
            eventStartEditable: true,
            eventDurationEditable: false,
            eventDisplay: 'block',
            eventContent: function(arg) {
                const titleParts = arg.event.title.split(' - ');
                return {
                    html: `
                        <div class="event-content bg-gradient-to-r from-indigo-500 to-purple-600 text-white p-2 rounded-lg shadow-lg hover:shadow-xl transition-shadow cursor-move">
                            <div class="flex w-full items-center justify-between gap-2">
                                <div class="min-w-0 text-left">
                                    <div class="font-bold text-sm">${escapeHtml(titleParts[0] || '')}</div>
                                    <div class="text-xs opacity-90">${escapeHtml(titleParts[1] || '')}</div>
                                </div>
                                <button type="button" class="js-calendar-group-open inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-white/95 text-indigo-700 shadow" title="Détails">+</button>
                            </div>
                        </div>
                    `
                };
            },
            eventDidMount: function(info) {
                const openButton = info.el.querySelector('.js-calendar-group-open');
                if (openButton) {
                    openButton.addEventListener('click', function(event) {
                        event.preventDefault();
                        event.stopPropagation();
                        toggleCalendarQuickPanel(info.event.extendedProps.group, openButton);
                    });
                }
            },
            events: function(fetchInfo, successCallback, failureCallback) {
                fetch('request/fiches_depenses_planifiees_list.php?' + new URLSearchParams(new FormData(document.getElementById('searchForm'))))
                    .then(response => response.json())
                    .then(data => {
                        if (data.ok === false) {
                            console.error('API error:', data.error);
                            return [];
                        }
                        // Store data globally for other views
                        window.currentData = data;

                        const events = data.grouped_by_date.map(group => ({
                            title: `${group.count} fiche(s) - ${currencyFormatCFA(group.total_montant)}`,
                            start: group.date,
                            allDay: true,
                            extendedProps: {
                                group: group
                            }
                        }));
                        successCallback(events);
                    })
                    .catch(failureCallback);
            },
            eventClick: function(info) {
                // Open modal with date details
                openDateModal(info.event.extendedProps.group);
            },
            eventDrop: function(info) {
                reportCalendarGroupDrop(info);
            }
        });
        calendar.render();
        fetchAndRender();
    });

    function fetchAndRender() {
        const form = document.getElementById('searchForm');
        const params = new URLSearchParams(new FormData(form));
        console.log('Fetching with params:', params.toString());
        fetch('request/fiches_depenses_planifiees_list.php?' + params)
            .then(response => {
                console.log('Response status:', response.status);
                return response.json();
            })
            .then(data => {
                console.log('Data received:', data);
                if (data.ok === false) {
                    console.error('API error:', data.error);
                    alert('Erreur: ' + data.error + (data.redirect ? ' Redirection vers login...' : ''));
                    if (data.redirect) window.location.href = data.redirect;
                    return;
                }
                renderCards(data.fiches);
                renderTable(data.fiches);
                if (currentView === 'calendar' && calendar) calendar.refetchEvents();
            })
            .catch(error => {
                console.error('Fetch error:', error);
            });
    }

    async function reportCalendarGroupDrop(info) {
        const group = info.event.extendedProps.group || {};
        const fiches = Array.isArray(group.fiches) ? group.fiches : [];
        const nums = fiches.map(function(fiche) {
            return fiche.num_fiche || '';
        }).filter(Boolean);
        const targetDate = info.event.startStr ? info.event.startStr.slice(0, 10) : '';

        if (!nums.length || !targetDate) {
            info.revert();
            return;
        }

        const confirmed = window.confirm(
            'Reporter ' + nums.length + ' fiche(s) du ' + formatDateFR(group.date) + ' au ' + formatDateFR(targetDate) + ' ?'
        );
        if (!confirmed) {
            info.revert();
            return;
        }

        const formData = new FormData();
        formData.append('date_min', targetDate);
        nums.forEach(function(num) {
            formData.append('num_fiches[]', num);
        });

        try {
            const response = await fetch('request/reporter_depenses_planifiees_bulk.php', {
                method: 'POST',
                body: formData
            });
            const payload = await response.json();
            if (!response.ok || !payload.ok) {
                throw new Error(payload.error || payload.message || 'Report impossible');
            }
            fetchAndRender();
        } catch (error) {
            info.revert();
            alert(error.message || 'Report impossible');
        }
    }

    function toggleCalendarQuickPanel(group, anchor) {
        let panel = document.getElementById('calendarQuickPanel');
        if (panel && panel.dataset.date === String(group && group.date || '')) {
            panel.remove();
            return;
        }
        if (panel) {
            panel.remove();
        }

        const fiches = Array.isArray(group && group.fiches) ? group.fiches : [];
        panel = document.createElement('div');
        panel.id = 'calendarQuickPanel';
        panel.dataset.date = String(group && group.date || '');
        panel.className = 'fixed z-[1250] w-80 max-w-[calc(100vw-24px)] rounded-2xl border border-slate-200 bg-white p-3 text-sm shadow-2xl';
        panel.innerHTML = `
            <div class="flex items-start justify-between gap-3">
                <div>
                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">${escapeHtml(formatDateFR(group.date))}</div>
                    <div class="mt-1 font-bold text-slate-900">${fiches.length} fiche(s)</div>
                    <div class="text-xs font-semibold text-emerald-700">${escapeHtml(currencyFormatCFA(group.total_montant || 0))}</div>
                </div>
                <button type="button" class="js-quick-close inline-flex h-7 w-7 items-center justify-center rounded-full border border-slate-200 bg-white text-slate-600">×</button>
            </div>
            <div class="mt-3 max-h-48 space-y-2 overflow-auto">
                ${fiches.slice(0, 6).map(function(fiche) {
                    return `
                        <div class="cursor-move rounded-xl border border-slate-100 bg-slate-50 px-3 py-2 hover:border-indigo-200 hover:bg-indigo-50"
                            draggable="true"
                            data-quick-fiche-num="${escapeHtml(fiche.num_fiche || '')}"
                            data-quick-fiche-date="${escapeHtml(fiche.date_decaissement_minimum || group.date || '')}">
                            <div class="font-semibold text-slate-800">${escapeHtml(fiche.num_fiche || '')}</div>
                            ${fiche.code_autorisation_feb ? `<div class="mt-1 text-xs font-semibold text-indigo-700">${escapeHtml(fiche.code_autorisation_feb)}</div>` : ''}
                            ${fiche.feb_request_id && fiche.code_autorisation_feb ? `<a class="mt-2 inline-flex rounded-lg bg-indigo-600 px-2 py-1 text-xs font-semibold text-white" href="../request/export_feb_pdf.php?id=${encodeURIComponent(fiche.feb_request_id)}" target="_blank">Télécharger BCI</a>` : ''}
                            <div class="text-xs text-slate-500">${escapeHtml(fiche.beficiaire_fiche || '')}</div>
                            <div class="text-xs font-semibold text-emerald-700">${escapeHtml(currencyFormatCFA(fiche.montant_fiche || 0))}</div>
                        </div>
                    `;
                }).join('')}
                ${fiches.length > 6 ? `<div class="text-xs text-slate-500">+ ${fiches.length - 6} autre(s)</div>` : ''}
            </div>
            <div class="mt-3 flex justify-end gap-2">
                <button type="button" class="js-quick-detail rounded-xl bg-indigo-600 px-3 py-2 text-xs font-semibold text-white">Détail</button>
            </div>
        `;

        document.body.appendChild(panel);
        const rect = anchor.getBoundingClientRect();
        const left = Math.min(Math.max(12, rect.left - 260), window.innerWidth - panel.offsetWidth - 12);
        const top = Math.min(rect.bottom + 8, window.innerHeight - panel.offsetHeight - 12);
        panel.style.left = left + 'px';
        panel.style.top = Math.max(12, top) + 'px';

        panel.querySelector('.js-quick-close').addEventListener('click', function(event) {
            event.stopPropagation();
            panel.remove();
        });
        panel.querySelector('.js-quick-detail').addEventListener('click', function(event) {
            event.stopPropagation();
            panel.remove();
            openDateModal(group);
        });
        panel.querySelectorAll('[data-quick-fiche-num]').forEach(function(row) {
            row.addEventListener('dragstart', function(event) {
                draggedQuickFiche = {
                    num: row.dataset.quickFicheNum || '',
                    currentDate: row.dataset.quickFicheDate || ''
                };
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', draggedQuickFiche.num);
            });
            row.addEventListener('dragend', function() {
                setTimeout(function() {
                    draggedQuickFiche = null;
                }, 50);
            });
        });
    }

    document.addEventListener('click', function(event) {
        const panel = document.getElementById('calendarQuickPanel');
        if (!panel) return;
        if (panel.contains(event.target) || event.target.closest('.js-calendar-group-open')) return;
        panel.remove();
    });

    document.addEventListener('dragover', function(event) {
        if (!draggedQuickFiche) return;
        const targetDate = findCalendarDropDate(event.target);
        if (!targetDate) return;
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';
    });

    document.addEventListener('drop', async function(event) {
        if (!draggedQuickFiche) return;
        const targetDate = findCalendarDropDate(event.target);
        if (!targetDate) return;
        event.preventDefault();

        const fiche = draggedQuickFiche;
        draggedQuickFiche = null;
        await reportSingleFicheFromCalendar(fiche.num, targetDate, fiche.currentDate);
    });

    function findCalendarDropDate(target) {
        const node = target && target.closest ? target.closest('[data-date]') : null;
        if (!node) return '';
        const date = String(node.getAttribute('data-date') || '').slice(0, 10);
        return /^\d{4}-\d{2}-\d{2}$/.test(date) ? date : '';
    }

    async function reportSingleFicheFromCalendar(numFiche, targetDate, currentDate) {
        if (!numFiche || !targetDate) return;
        if (currentDate && currentDate === targetDate) return;

        const confirmed = window.confirm('Reporter la fiche ' + numFiche + ' au ' + formatDateFR(targetDate) + ' ?');
        if (!confirmed) return;

        const formData = new FormData();
        formData.append('num_fiche', numFiche);
        formData.append('date_min', targetDate);

        try {
            const response = await fetch('request/reporter_compta.php', {
                method: 'POST',
                body: formData
            });
            const payload = await response.json();
            if (!response.ok || !payload.ok) {
                throw new Error(payload.error || 'Report impossible');
            }

            const panel = document.getElementById('calendarQuickPanel');
            if (panel) panel.remove();
            fetchAndRender();
        } catch (error) {
            alert(error.message || 'Report impossible');
        }
    }

    function renderCards(fiches) {
        const container = document.getElementById('card-view');
        container.innerHTML = fiches.map(f => `
            <div class="card p-4 border hover:shadow cursor-pointer" data-fiche="${encodeURIComponent(JSON.stringify(f))}" onclick="openFicheModal(this)">
                <h3 class="font-bold">${f.num_fiche}</h3>
                <p>Montant: ${currencyFormatCFA(f.montant_fiche)}</p>
                <p>Date: ${formatDateFR(f.date_decaissement_minimum)}</p>
                <p>Chantier: ${f.chantier || ''}</p>
                <p>Bénéficiaire: ${f.beficiaire_fiche || ''}</p>
                <button type="button" class="mt-3 btn btn-warning text-xs" onclick="event.stopPropagation(); openReportModal('${escapeJsAttr(f.num_fiche)}', '${escapeJsAttr(f.date_decaissement_minimum || '')}')">Reporter</button>
            </div>
        `).join('');
    }

    function escapeJsAttr(value) {
        return String(value || '').replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/\r?\n/g, ' ');
    }

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = value || '';
        return div.innerHTML;
    }

    function renderTable(fiches) {
        const tbody = document.getElementById('table-body');
        tbody.innerHTML = fiches.map(f => `
            <tr class="hover:bg-gray-50 cursor-pointer" data-fiche="${encodeURIComponent(JSON.stringify(f))}" onclick="openFicheModal(this)">
                <td class="px-4 py-2">${f.num_fiche}</td>
                <td class="px-4 py-2">${currencyFormatCFA(f.montant_fiche)}</td>
                <td class="px-4 py-2">${formatDateFR(f.date_decaissement_minimum)}</td>
                <td class="px-4 py-2">${f.chantier || ''}</td>
                <td class="px-4 py-2">${f.beficiaire_fiche || ''}</td>
                <td class="px-4 py-2">${f.date_decaissement_minimum < new Date().toISOString().split('T')[0] ? 'En retard' : 'Planifié'}</td>
                <td class="px-4 py-2"><button type="button" class="btn btn-warning text-xs" onclick="event.stopPropagation(); openReportModal('${escapeJsAttr(f.num_fiche)}', '${escapeJsAttr(f.date_decaissement_minimum || '')}')">Reporter</button></td>
            </tr>
        `).join('');
    }

    function openFicheModal(fiche) {
        if (fiche && fiche.dataset && fiche.dataset.fiche) {
            try {
                fiche = JSON.parse(decodeURIComponent(fiche.dataset.fiche));
            } catch (e) {
                fiche = {};
            }
        }

        const generalInfo = document.getElementById('ficheGeneralInfo');
        const hierarchyInfo = document.getElementById('ficheHierarchyInfo');
        const amountEl = document.getElementById('ficheAmount');
        const statusInfo = document.getElementById('ficheStatusInfo');

        const escapeHtml = text => {
            const div = document.createElement('div');
            div.textContent = text || 'N/A';
            return div.innerHTML;
        };

        generalInfo.innerHTML = `
            <div class="pf-key-value">
                <span class="pf-key">N° Fiche:</span>
                <span class="pf-value">${escapeHtml(fiche.num_fiche)}</span>
            </div>
            <div class="pf-key-value">
                <span class="pf-key">Bénéficiaire:</span>
                <span class="pf-value">${escapeHtml(fiche.beficiaire_fiche)}</span>
            </div>
            <div class="pf-key-value">
                <span class="pf-key">Désignation:</span>
                <span class="pf-value">${escapeHtml(fiche.designation_fiche)}</span>
            </div>
            <div class="pf-key-value">
                <span class="pf-key">Précision:</span>
                <span class="pf-value">${escapeHtml(fiche.precision_fiche)}</span>
            </div>
            <div class="pf-key-value">
                <span class="pf-key">Date création:</span>
                <span class="pf-value">${fiche.date_creat_fiche ? new Date(fiche.date_creat_fiche).toLocaleDateString('fr-FR') : 'N/A'}</span>
            </div>
        `;

        hierarchyInfo.innerHTML = `
            <div class="pf-key-value">
                <span class="pf-key">Chantier:</span>
                <span class="pf-value">${escapeHtml(fiche.chantier_banamur)}</span>
            </div>
            ${fiche.ligne_debourse_designation ? `
            <div class="pf-key-value">
                <span class="pf-key">Ligne Déboursé:</span>
                <span class="pf-value">${escapeHtml(fiche.ligne_debourse_designation)} (${escapeHtml(fiche.ligne_debourse_categorie)})</span>
            </div>
            <div class="pf-key-value">
                <span class="pf-key">Montant prévu:</span>
                <span class="pf-value">${fiche.ligne_debourse_montant ? new Intl.NumberFormat('fr-FR').format(fiche.ligne_debourse_montant) + ' CFA' : 'N/A'}</span>
            </div>
            ` : ''}
            ${fiche.debourse_montant ? `
            <div class="pf-key-value">
                <span class="pf-key">Déboursé total:</span>
                <span class="pf-value">${new Intl.NumberFormat('fr-FR').format(fiche.debourse_montant)} CFA</span>
            </div>
            ` : ''}
            ${fiche.ligne_devis_designation ? `
            <div class="pf-key-value">
                <span class="pf-key">Ligne Devis:</span>
                <span class="pf-value">${escapeHtml(fiche.ligne_devis_designation)}</span>
            </div>
            <div class="pf-key-value">
                <span class="pf-key">Montant devis:</span>
                <span class="pf-value">${fiche.ligne_devis_montant ? new Intl.NumberFormat('fr-FR').format(fiche.ligne_devis_montant) + ' CFA' : 'N/A'}</span>
            </div>
            ` : ''}
            ${fiche.devis_titre ? `
            <div class="pf-key-value">
                <span class="pf-key">Devis:</span>
                <span class="pf-value">${escapeHtml(fiche.devis_titre)}</span>
            </div>
            <div class="pf-key-value">
                <span class="pf-key">Total devis:</span>
                <span class="pf-value">${fiche.devis_montant_total ? new Intl.NumberFormat('fr-FR').format(fiche.devis_montant_total) + ' CFA' : 'N/A'}</span>
            </div>
            ` : ''}
        `;

        amountEl.textContent = fiche.montant_fiche ? new Intl.NumberFormat('fr-FR').format(fiche.montant_fiche) : '0';

        const isOverdue = fiche.date_decaissement_minimum && new Date(fiche.date_decaissement_minimum) < new Date();
        statusInfo.innerHTML = `
            <div class="pf-key-value">
                <span class="pf-key">État:</span>
                <span class="pf-value ${isOverdue ? 'text-red-600' : 'text-green-600'}">${isOverdue ? 'En retard' : 'Planifié'}</span>
            </div>
            <div class="pf-key-value">
                <span class="pf-key">Date décaissement:</span>
                <span class="pf-value">${fiche.date_decaissement_minimum ? new Date(fiche.date_decaissement_minimum).toLocaleDateString('fr-FR') : 'N/A'}</span>
            </div>
            <div style="margin-top: 12px;">
                <button type="button" class="btn btn-warning text-xs" onclick="openReportModal('${escapeJsAttr(fiche.num_fiche)}', '${escapeJsAttr(fiche.date_decaissement_minimum || '')}')">Reporter à nouveau</button>
            </div>
        `;

        document.getElementById('ficheDetailModal').classList.add('is-open');
        document.getElementById('ficheDetailModal').setAttribute('aria-hidden', 'false');
    }

    function closeFicheModal() {
        document.getElementById('ficheDetailModal').classList.remove('is-open');
        document.getElementById('ficheDetailModal').setAttribute('aria-hidden', 'true');
    }

    document.getElementById('ficheDetailModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeFicheModal();
        }
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && document.getElementById('ficheDetailModal').classList.contains('is-open')) {
            closeFicheModal();
        }
    });

    function openDateModal(group) {
        document.getElementById('modalDate').textContent = formatDateFR(group.date);
        document.getElementById('modalTotal').textContent = currencyFormatCFA(group.total_montant);
        document.getElementById('modalCount').textContent = group.count;
        renderDateBreakdown(group.fiches || []);

        const tbody = document.getElementById('modalTableBody');
        tbody.innerHTML = group.fiches.map(f => `
            <tr class="hover:bg-gray-50">
                <td class="border border-gray-300 px-4 py-2">${f.num_fiche}</td>
                <td class="border border-gray-300 px-4 py-2">${currencyFormatCFA(f.montant_fiche)}</td>
                <td class="border border-gray-300 px-4 py-2">${f.chantier_banamur || ''}</td>
                <td class="border border-gray-300 px-4 py-2">${f.beficiaire_fiche || ''}</td>
                <td class="border border-gray-300 px-4 py-2">${f.date_decaissement_minimum < new Date().toISOString().split('T')[0] ? 'En retard' : 'Planifié'}</td>
                <td class="border border-gray-300 px-4 py-2"><button type="button" class="btn btn-warning text-xs" onclick="openReportModal('${escapeJsAttr(f.num_fiche)}', '${escapeJsAttr(f.date_decaissement_minimum || '')}')">Reporter</button></td>
            </tr>
        `).join('');

        // Store current group for export
        window.currentGroup = group;

        document.getElementById('dateModal').classList.add('show');
    }

    function renderDateBreakdown(fiches) {
        renderBreakdownList('modalByChantier', summarizeBy(fiches, function(f) {
            return f.chantier_banamur || f.chantier || 'Sans chantier';
        }));
        renderBreakdownList('modalByBeneficiaire', summarizeBy(fiches, function(f) {
            return f.beficiaire_fiche || 'Sans bénéficiaire';
        }));
    }

    function summarizeBy(fiches, resolver) {
        const map = new Map();
        fiches.forEach(function(fiche) {
            const label = String(resolver(fiche) || 'Non renseigné').trim() || 'Non renseigné';
            const current = map.get(label) || {
                label,
                count: 0,
                total: 0
            };
            current.count += 1;
            current.total += Number(fiche.montant_fiche || 0);
            map.set(label, current);
        });
        return Array.from(map.values()).sort(function(a, b) {
            return b.total - a.total;
        });
    }

    function renderBreakdownList(targetId, rows) {
        const target = document.getElementById(targetId);
        if (!target) return;
        if (!rows.length) {
            target.innerHTML = '<div class="text-slate-500">Aucune donnée.</div>';
            return;
        }
        const visible = rows.slice(0, 6);
        const hiddenCount = Math.max(0, rows.length - visible.length);
        target.innerHTML = visible.map(function(row) {
            return `
                <div class="flex items-start justify-between gap-3 rounded-xl bg-white px-3 py-2 border border-slate-100">
                    <div class="min-w-0">
                        <div class="font-semibold text-slate-800 truncate">${escapeHtml(row.label)}</div>
                        <div class="text-xs text-slate-500">${row.count} fiche(s)</div>
                    </div>
                    <div class="shrink-0 text-right text-xs font-semibold text-emerald-700">${currencyFormatCFA(row.total)}</div>
                </div>
            `;
        }).join('') + (hiddenCount ? `<div class="text-xs text-slate-500">+ ${hiddenCount} autre(s)</div>` : '');
    }

    function closeModal() {
        document.getElementById('dateModal').classList.remove('show');
    }

    function openDetailPage() {
        if (window.currentGroup) {
            const date = window.currentGroup.date;
            window.open(`depenses_planifiees_detail.php?date=${date}`, '_blank');
        }
    }

    function exportDateRecap() {
        if (window.currentGroup) {
            const date = window.currentGroup.date;
            window.open(`request/export_date_recap.php?date=${date}`, '_blank');
        }
    }

    function openReportModal(numFiche, currentDate) {
        closeFicheModal();
        document.getElementById('reportFicheLabel').textContent = numFiche || '';
        document.getElementById('reportNumFiche').value = numFiche || '';
        const dateInput = document.getElementById('reportDateMin');
        const today = new Date().toISOString().slice(0, 10);
        dateInput.min = today;
        dateInput.value = currentDate && currentDate >= today ? currentDate : today;
        document.getElementById('reportError').classList.add('hidden');
        document.getElementById('reportModal').classList.add('show');
    }

    function closeReportModal() {
        document.getElementById('reportModal').classList.remove('show');
    }

    document.getElementById('reportForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        const err = document.getElementById('reportError');
        err.classList.add('hidden');
        const buttons = this.querySelectorAll('button');
        buttons.forEach(button => button.disabled = true);

        try {
            const response = await fetch('request/reporter_compta.php', {
                method: 'POST',
                body: new FormData(this)
            });
            const payload = await response.json();
            if (!response.ok || !payload.ok) {
                throw new Error(payload.error || 'Report impossible');
            }

            closeReportModal();
            closeModal();
            fetchAndRender();
        } catch (error) {
            err.textContent = error.message || 'Report impossible';
            err.classList.remove('hidden');
        } finally {
            buttons.forEach(button => button.disabled = false);
        }
    });

    function currencyFormatCFA(n) {
        try {
            return (Number(n) || 0).toLocaleString('fr-FR') + ' CFA';
        } catch {
            return '0 CFA';
        }
    }

    function formatDateFR(iso) {
        if (!iso) return '';
        const d = new Date(iso);
        return d.toLocaleDateString('fr-FR');
    }

    document.getElementById('searchForm').addEventListener('submit', e => {
        e.preventDefault();
        fetchAndRender();
    });

    document.getElementById('resetBtn').onclick = () => {
        document.getElementById('searchForm').reset();
        fetchAndRender();
    };

    document.getElementById('exportExcel').onclick = () => {
        const params = new URLSearchParams(new FormData(document.getElementById('searchForm')));
        window.open('request/export_depenses_planifiees_excel.php?' + params.toString(), '_blank');
    };

    document.getElementById('exportPdf').onclick = () => {
        const params = new URLSearchParams(new FormData(document.getElementById('searchForm')));
        window.open('request/export_depenses_planifiees_pdf.php?' + params.toString(), '_blank');
    };
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
