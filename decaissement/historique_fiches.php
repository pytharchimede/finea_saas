<?php
session_start();
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/AuditTrail.php';

AuditTrail::logPageConsultation([
    'module' => 'decaissement',
    'action' => 'historique_fiches_view',
    'entity_type' => 'trace_fiche_list',
    'entity_label' => 'Historique des fiches',
    'page_name' => 'historique_fiches.php',
    'details' => [
        'view_mode' => 'list',
        'num_fiche' => $_GET['num_fiche'] ?? null,
        'action_type' => $_GET['action_type'] ?? null,
    ],
]);

$pageTitle = 'Historique - DécaissApp';
$activeMenu = 'historique';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>
<div class="container mx-auto py-10 px-4">
    <h1 class="text-3xl font-bold text-indigo-700 mb-2">Journal des actions (Logs)</h1>
    <p class="text-sm text-gray-500 mb-6">Chaque entrée est un évènement horodaté enregistré sur une fiche (création, approbation, conformité, validation, décaissement, refus...). Utilisez la recherche globale ou les filtres précis. Le bouton "Fiche" ouvre le détail concerné.</p>

    <!-- Section fiches supprimée : page uniquement logs -->

    <!-- Panel Traces -->
    <form id="traceForm" class="bg-white rounded-xl shadow p-6 mb-4 grid md:grid-cols-8 gap-4 items-end text-sm">
        <div>
            <label class="block text-[11px] font-semibold text-gray-600 mb-1">N° fiche</label>
            <input name="num_fiche" class="form-input rounded border-gray-300" placeholder="FD-..." />
        </div>
        <div class="md:col-span-2">
            <label class="block text-[11px] font-semibold text-gray-600 mb-1">Action contient</label>
            <input name="action" class="form-input rounded border-gray-300" placeholder="création, approbation..." />
        </div>
        <div class="md:col-span-2">
            <label class="block text-[11px] font-semibold text-gray-600 mb-1">Recherche globale</label>
            <input name="q" class="form-input rounded border-gray-300" placeholder="mot-clé (fiche, user, ip, texte)" />
        </div>
        <div>
            <label class="block text-[11px] font-semibold text-gray-600 mb-1">Utilisateur</label>
            <input name="utilisateur" class="form-input rounded border-gray-300" />
        </div>
        <div>
            <label class="block text-[11px] font-semibold text-gray-600 mb-1">IP</label>
            <input name="ip" class="form-input rounded border-gray-300" />
        </div>
        <div>
            <label class="block text-[11px] font-semibold text-gray-600 mb-1">Type action</label>
            <select name="action_type" class="form-select rounded border-gray-300 text-[13px]">
                <option value="">(Tous)</option>
                <option value="CREATION">Création</option>
                <option value="APPROBATION">Approbation</option>
                <option value="CONFORMITE">Conformité</option>
                <option value="VALIDATION">Validation</option>
                <option value="DECAISSEMENT">Décaissement</option>
                <option value="REFUS">Refus / Rejet</option>
                <option value="AUTRE">Autre</option>
            </select>
        </div>
        <div class="flex gap-2">
            <div>
                <label class="block text-[11px] font-semibold text-gray-600 mb-1">Du</label>
                <input type="date" name="from" class="form-input rounded border-gray-300" />
            </div>
            <div>
                <label class="block text-[11px] font-semibold text-gray-600 mb-1">Au</label>
                <input type="date" name="to" class="form-input rounded border-gray-300" />
            </div>
        </div>
        <div class="flex gap-2">
            <div>
                <label class="block text-[11px] font-semibold text-gray-600 mb-1">Tri</label>
                <select name="sort" class="form-select rounded border-gray-300 text-[13px]">
                    <option value="desc">Récent d'abord</option>
                    <option value="asc">Plus ancien</option>
                </select>
            </div>
            <div>
                <label class="block text-[11px] font-semibold text-gray-600 mb-1">Limite</label>
                <input type="number" name="limit" value="200" min="10" max="500" class="form-input rounded border-gray-300 w-24" />
            </div>
        </div>
        <div class="md:col-span-2 flex gap-2 mt-2">
            <button type="submit" class="btn btn-primary flex-1">Filtrer</button>
            <button type="button" id="traceReset" class="btn btn-ghost">Reset</button>
            <button type="button" id="traceExport" class="btn btn-soft-secondary" title="Exporter CSV">CSV</button>
            <button type="button" id="traceToggleView" class="btn btn-soft-indigo" title="Basculer Timeline/Table">Vue</button>
        </div>
    </form>
    <div id="traceInfo" class="mb-3 text-xs text-gray-600 flex flex-wrap gap-4"></div>
    <div id="traceCharts" class="mb-4 grid sm:grid-cols-3 gap-3 text-xs"></div>
    <div>
        <div id="traceTimeline" class="bg-white rounded-xl shadow p-5 max-h-[60vh] overflow-auto text-sm"></div>
        <div id="traceTableWrap" class="hidden bg-white rounded-xl shadow p-4 overflow-auto max-h-[60vh]">
            <table class="w-full text-xs">
                <thead class="sticky top-0 bg-indigo-50 text-indigo-700">
                    <tr>
                        <th class="px-2 py-2 text-left">#</th>
                        <th class="px-2 py-2 text-left">Date/Heure</th>
                        <th class="px-2 py-2 text-left">Fiche</th>
                        <th class="px-2 py-2 text-left">Type</th>
                        <th class="px-2 py-2 text-left">Action</th>
                        <th class="px-2 py-2 text-left">Utilisateur</th>
                        <th class="px-2 py-2 text-left">IP</th>
                        <th class="px-2 py-2 text-left">User Agent</th>
                        <th class="px-2 py-2 text-left">Détail</th>
                    </tr>
                </thead>
                <tbody id="traceTableBody"></tbody>
            </table>
        </div>
        <div class="flex justify-between items-center mt-3 text-xs" id="tracePager">
            <div class="flex gap-1">
                <button id="prevTrace" class="btn btn-soft-secondary px-3 py-1" disabled>&larr; Préc.</button>
                <button id="nextTrace" class="btn btn-soft-secondary px-3 py-1" disabled>Suiv. &rarr;</button>
            </div>
            <div id="tracePageInfo" class="text-gray-500"></div>
        </div>
    </div>
</div>

<script>
    // Fallback utilitaires si app.js n'est pas encore chargé
    window.currencyFormatCFA = window.currencyFormatCFA || function(n) {
        try {
            return (Number(n) || 0).toLocaleString('fr-FR') + ' CFA';
        } catch (e) {
            return n + ' CFA';
        }
    };
    const isDemo = (typeof getQueryParam === 'function' && getQueryParam('demo') === '1');

    // Section fiches retirée

    function escapeHtml(str) {
        return (str || '').replace(/[&<>"']/g, s => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            '\'': '&#39;'
        } [s]));
    }

    function formatDateFR(iso) {
        try {
            const d = new Date(iso);
            return d.toLocaleDateString('fr-FR', {
                day: '2-digit',
                month: 'short',
                year: 'numeric'
            });
        } catch {
            return iso;
        }
    }

    // Ancienne section fiches supprimée : on neutralise les anciens handlers pour éviter une erreur JS bloquante
    const legacySearchForm = document.getElementById('searchForm');
    const legacyResetBtn = document.getElementById('resetBtn');
    if (legacySearchForm) {
        legacySearchForm.addEventListener('submit', e => {
            e.preventDefault();
            fetchAndRender && fetchAndRender();
        });
    }
    if (legacyResetBtn && legacySearchForm) {
        legacyResetBtn.onclick = () => {
            legacySearchForm.reset();
            fetchAndRender && fetchAndRender();
        };
    }
    // ---- Traces ----
    const traceForm = document.getElementById('traceForm');
    const traceTimeline = document.getElementById('traceTimeline');
    const traceInfo = document.getElementById('traceInfo');
    const traceCharts = document.getElementById('traceCharts');
    const traceTableWrap = document.getElementById('traceTableWrap');
    const traceTableBody = document.getElementById('traceTableBody');
    const btnPrev = document.getElementById('prevTrace');
    const btnNext = document.getElementById('nextTrace');
    const pageInfo = document.getElementById('tracePageInfo');
    const toggleViewBtn = document.getElementById('traceToggleView');
    let traceData = [];
    let traceMeta = {
        offset: 0,
        limit: 200,
        count: 0,
        has_more: false,
        sort: 'desc'
    };
    let timelineMode = true;

    traceForm.addEventListener('submit', e => {
        e.preventDefault();
        loadTraces();
    });
    document.getElementById('traceReset').onclick = () => {
        traceForm.reset();
        loadTraces();
    };
    document.getElementById('traceExport').onclick = () => exportTraceCsv();

    async function loadTraces(offset = 0) {
        const formData = new FormData(traceForm);
        formData.set('offset', offset);
        const params = new URLSearchParams(formData);
        traceTimeline.innerHTML = '<div class="text-center py-6 text-gray-400 text-sm">Chargement...</div>';
        traceTableBody.innerHTML = '';
        try {
            const r = await fetch('request/trace_fiche_list.php?' + params.toString());
            const j = await r.json();
            if (!j.ok) {
                traceTimeline.innerHTML = '<div class="text-red-500 text-sm">Erreur chargement</div>';
                return;
            }
            traceData = j.items || [];
            traceMeta = j;
            updateStats();
            if (timelineMode) renderTimeline(j);
            else renderTable();
            updatePager();
        } catch (e) {
            traceTimeline.innerHTML = '<div class="text-red-500 text-sm">Erreur réseau</div>';
        }
    }

    function groupByDate(list) {
        const map = {};
        list.forEach(it => {
            const d = it.date_action.slice(0, 10);
            (map[d] = map[d] || []).push(it);
        });
        return Object.entries(map).sort((a, b) => b[0].localeCompare(a[0]));
    }

    function renderTimeline(meta) {
        traceInfo.innerHTML = infoBar(meta);
        const groups = groupByDate(traceData);
        if (!groups.length) {
            traceTimeline.innerHTML = '<div class="text-center py-8 text-gray-400">Aucune trace</div>';
            return;
        }
        traceTimeline.innerHTML = groups.map(([date, items]) => `<div class='mb-6'>
            <div class='sticky top-0 bg-white/90 backdrop-blur border-l-4 border-indigo-400 px-3 py-1 font-semibold text-indigo-700 shadow-sm mb-3 text-sm'>${formatDateFR(date)}</div>
            <ul class='space-y-3'>${items.map(it=>timelineItem(it)).join('')}</ul>
        </div>`).join('');
    }

    function renderTable() {
        traceTimeline.innerHTML = '';
        if (!traceData.length) {
            traceTableBody.innerHTML = '<tr><td colspan="8" class="text-center text-gray-400 py-6">Aucune trace</td></tr>';
            return;
        }
        traceInfo.innerHTML = infoBar(traceMeta);
        traceTableBody.innerHTML = traceData.map(row => tableRow(row)).join('');
    }

    function tableRow(it) {
        const dt = new Date(it.date_action.replace(' ', 'T'));
        const dateFmt = dt.toLocaleDateString('fr-FR') + ' ' + dt.toLocaleTimeString('fr-FR', {
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit'
        });
        const typeBadge = `<span class="px-2 py-0.5 rounded-full text-[10px] font-semibold ${badgeColor(it.action_type)}">${it.action_type}</span>`;
        const ficheBtn = it.num_fiche ? `<a href="voir_fiche_details.php?num_fiche=${encodeURIComponent(it.num_fiche)}" target="_blank" class="inline-flex items-center gap-1 px-2 py-1 rounded bg-indigo-600 hover:bg-indigo-700 text-white text-[10px] font-semibold shadow"><span>Fiche</span><svg class='w-3 h-3' fill='none' stroke='currentColor' stroke-width='2' viewBox='0 0 24 24'><path stroke-linecap='round' stroke-linejoin='round' d='M9 5l7 7-7 7'/></svg></a>` : '';
        return `<tr class="border-b border-gray-50 hover:bg-indigo-50/40">
            <td class="px-2 py-1 text-gray-400">${it.id}</td>
            <td class="px-2 py-1">${dateFmt}</td>
            <td class="px-2 py-1 font-mono text-[11px]">${escapeHtml(it.num_fiche)}</td>
            <td class="px-2 py-1">${typeBadge}</td>
            <td class="px-2 py-1 text-gray-700">${highlightKeywords(escapeHtml(it.action))}</td>
            <td class="px-2 py-1">${escapeHtml(it.utilisateur)}</td>
            <td class="px-2 py-1 text-[11px] text-gray-500">${escapeHtml(it.adresse_ip||'')}</td>
            <td class="px-2 py-1 truncate max-w-[200px]" title="${escapeHtml(it.user_agent||'')}">${escapeHtml((it.user_agent||'').slice(0,60))}</td>
            <td class="px-2 py-1">${ficheBtn}</td>
        </tr>`;
    }

    function badgeColor(t) {
        switch (t) {
            case 'CREATION':
                return 'bg-blue-100 text-blue-700';
            case 'APPROBATION':
                return 'bg-indigo-100 text-indigo-700';
            case 'CONFORMITE':
                return 'bg-teal-100 text-teal-700';
            case 'VALIDATION':
                return 'bg-emerald-100 text-emerald-700';
            case 'DECAISSEMENT':
                return 'bg-purple-100 text-purple-700';
            case 'REFUS':
                return 'bg-rose-100 text-rose-700';
            default:
                return 'bg-gray-100 text-gray-600';
        }
    }

    function updateStats() {
        const meta = traceMeta;
        const dist = meta.counts_by_action_type || {};
        // mini charts / cards
        traceCharts.innerHTML = '';
        const cards = [{
                label: 'Actions',
                value: meta.count
            },
            {
                label: 'Fiches distinctes',
                value: meta.distinct_fiches
            },
            {
                label: 'Utilisateurs',
                value: meta.distinct_users
            },
        ];
        traceCharts.innerHTML = cards.map(c => `<div class='p-3 rounded-lg bg-white shadow border border-gray-100'>
            <div class='text-[11px] uppercase tracking-wide text-gray-500 font-semibold'>${c.label}</div>
            <div class='mt-1 text-xl font-bold text-indigo-600'>${c.value}</div>
        </div>`).join('');
        // distribution stacked inline bars
        const total = Object.values(dist).reduce((a, b) => a + b, 0) || 1;
        const bar = Object.entries(dist).map(([k, v]) => `<div class='flex items-center gap-2'>
            <div class='w-28 text-[10px] font-medium ${badgeColor(k)} px-2 py-0.5 rounded'>${k}</div>
            <div class='flex-1 h-2 bg-gray-100 rounded overflow-hidden'>
                <div class='h-full ${badgeColor(k).replace('text-','bg-').replace('100','500')} opacity-70' style='width:${(v*100/total).toFixed(1)}%'></div>
            </div>
            <div class='w-10 text-right text-[11px] text-gray-500'>${v}</div>
        </div>`).join('');
        const distWrap = document.createElement('div');
        distWrap.className = 'col-span-3 p-3 rounded-lg bg-white shadow border border-gray-100';
        distWrap.innerHTML = `<div class='text-[11px] uppercase tracking-wide text-gray-500 font-semibold mb-1'>Répartition</div>${bar||'<div class="text-[11px] text-gray-400">(vide)</div>'}`;
        traceCharts.appendChild(distWrap);
    }

    function infoBar(meta) {
        const end = Math.min(meta.offset + meta.limit, meta.count);
        const page = Math.floor(meta.offset / meta.limit) + 1;
        const pages = Math.max(1, Math.ceil(meta.count / meta.limit));
        return `<span><strong>${meta.count}</strong> action(s)</span>
            <span>|</span>
            <span>Plage: ${meta.offset+1}-${end}</span>
            <span>|</span>
            <span>Page ${page}/${pages}</span>
            <span>|</span>
            <span>Tri: ${meta.sort==='desc'?'↓ récent':'↑ ancien'}</span>`;
    }

    function updatePager() {
        const meta = traceMeta;
        const page = Math.floor(meta.offset / meta.limit) + 1;
        const pages = Math.max(1, Math.ceil(meta.count / meta.limit));
        btnPrev.disabled = meta.offset === 0;
        btnNext.disabled = !meta.has_more;
        pageInfo.textContent = `Page ${page}/${pages}`;
    }

    btnPrev && (btnPrev.onclick = () => {
        if (traceMeta.offset > 0) loadTraces(traceMeta.offset - traceMeta.limit);
    });
    btnNext && (btnNext.onclick = () => {
        if (traceMeta.has_more) loadTraces(traceMeta.offset + traceMeta.limit);
    });
    toggleViewBtn && (toggleViewBtn.onclick = () => {
        timelineMode = !timelineMode;
        traceTimeline.classList.toggle('hidden', !timelineMode);
        traceTableWrap.classList.toggle('hidden', timelineMode);
        toggleViewBtn.textContent = timelineMode ? 'Table' : 'Timeline';
        if (timelineMode) renderTimeline(traceMeta);
        else renderTable();
    });

    function timelineItem(it) {
        const hour = new Date(it.date_action.replace(' ', 'T')).toLocaleTimeString('fr-FR', {
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit'
        });
        const uaShort = (it.user_agent || '').split(' ').slice(0, 3).join(' ');
        const ficheBtn = it.num_fiche ? `<a href="voir_fiche_details.php?num_fiche=${encodeURIComponent(it.num_fiche)}" target="_blank" class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-indigo-600 hover:bg-indigo-700 text-white text-[10px] font-semibold shadow"><span>Fiche</span><svg class='w-3 h-3' fill='none' stroke='currentColor' stroke-width='2' viewBox='0 0 24 24'><path stroke-linecap='round' stroke-linejoin='round' d='M9 5l7 7-7 7'/></svg></a>` : '';
        return `<li class='relative pl-8'>
            <span class='absolute left-0 top-1.5 w-3 h-3 rounded-full bg-gradient-to-br from-indigo-500 to-purple-500 shadow ring-2 ring-white'></span>
            <div class='p-3 rounded-lg border border-gray-100 bg-gradient-to-r from-white to-indigo-50/40 hover:shadow transition'>
              <div class='flex flex-wrap items-center gap-3 text-[11px] mb-1'>
                <span class='px-2 py-0.5 bg-indigo-100 text-indigo-700 rounded-full font-semibold'>${escapeHtml(it.num_fiche)}</span>
                <span class='text-gray-400'>${hour}</span>
                <span class='px-2 py-0.5 bg-gray-100 rounded-full text-gray-600'>${escapeHtml(it.utilisateur)}</span>
                ${it.adresse_ip?`<span class='text-[10px] text-gray-500'>IP ${escapeHtml(it.adresse_ip)}</span>`:''}
                ${ficheBtn}
              </div>
              <div class='text-sm text-gray-700 font-medium leading-snug'>${highlightKeywords(escapeHtml(it.action))}</div>
              ${uaShort?`<div class='mt-1 text-[10px] text-gray-400'>${escapeHtml(uaShort)}</div>`:''}
            </div>
        </li>`;
    }

    function exportTraceCsv() {
        if (!traceData.length) {
            if (typeof showToast === 'function') showToast('Aucune trace', 'warning');
            return;
        }
        const headers = ['id', 'num_fiche', 'action', 'date_action', 'utilisateur', 'adresse_ip', 'user_agent'];
        const csv = [headers.join(';')].concat(traceData.map(t => headers.map(h => (t[h] || '').toString().replace(/;/g, ',')).join(';'))).join('\n');
        const blob = new Blob([csv], {
            type: 'text/csv;charset=utf-8;'
        });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'trace_fiche.csv';
        a.click();
        setTimeout(() => URL.revokeObjectURL(url), 300);
    }

    function highlightKeywords(str) {
        const kw = ['Création', 'Approb', 'Conform', 'Décaisse', 'Refus'];
        return kw.reduce((acc, k) => acc.replace(new RegExp(k, 'gi'), m => `<mark class='bg-yellow-200 text-gray-800 px-1 rounded'>${m}</mark>`), str);
    }

    document.addEventListener('DOMContentLoaded', () => {
        loadTraces();
        ensureViewToggleFallback();
    });

    // Fallback: si le bouton principal n'existe pas ou est masqué, on injecte un mini bouton flottant
    function ensureViewToggleFallback() {
        const existing = document.getElementById('traceToggleView');
        if (existing) return; // déjà présent
        const wrap = document.createElement('div');
        wrap.style.position = 'relative';
        const btn = document.createElement('button');
        btn.id = 'traceToggleViewFallback';
        btn.type = 'button';
        btn.textContent = 'Table';
        btn.title = 'Basculer Timeline/Table';
        btn.className = 'fixed bottom-6 right-6 z-50 px-4 py-2 rounded-full shadow-lg bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold';
        btn.onclick = () => {
            timelineMode = !timelineMode;
            traceTimeline.classList.toggle('hidden', !timelineMode);
            traceTableWrap.classList.toggle('hidden', timelineMode);
            btn.textContent = timelineMode ? 'Table' : 'Timeline';
            if (timelineMode) renderTimeline(traceMeta);
            else renderTable();
        };
        document.body.appendChild(btn);
    }
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>