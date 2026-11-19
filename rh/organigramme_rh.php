<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../model/RhOrganizationChartLayoutManager.php';
require_once __DIR__ . '/../services/RhOrganizationChartService.php';

$pdo = \Database::getConnection();
$chartService = new \RhOrganizationChartService($pdo);
$snapshot = $chartService->getSnapshot();
$layoutManager = new \RhOrganizationChartLayoutManager($pdo);
$savedLayout = $layoutManager->get();

if (empty($_SESSION['rh_org_chart_csrf'])) {
    $_SESSION['rh_org_chart_csrf'] = bin2hex(random_bytes(24));
}
$layoutCsrfToken = (string) $_SESSION['rh_org_chart_csrf'];

\AuditTrail::log('page_view', [
    'module' => 'rh',
    'action' => 'rh_organization_chart_view',
    'entity_type' => 'rh_organization_chart',
    'entity_label' => 'Organigramme general BANAMUR',
    'page_name' => 'organigramme_rh.php',
    'details' => [
        'personnel_count' => (int) ($snapshot['stats']['personnel'] ?? 0),
        'service_count' => (int) ($snapshot['stats']['services'] ?? 0),
    ],
], $pdo);

$chartJson = json_encode(
    $snapshot['tree'],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
$chartJson = $chartJson === false ? '{}' : $chartJson;
$layoutJson = json_encode(
    $savedLayout,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
$layoutJson = $layoutJson === false ? '{"nodes":{},"edges":{}}' : $layoutJson;

$pageTitle = 'Organigramme RH';
$activeMenu = 'rh';

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>
<style>
    .org-page {
        --org-ink: #0f172a;
        --org-muted: #64748b;
        --org-surface: rgba(255, 255, 255, .94);
        min-height: 100vh;
        padding-bottom: 3rem;
        background:
            radial-gradient(circle at 8% 5%, rgba(16, 185, 129, .14), transparent 26rem),
            radial-gradient(circle at 92% 3%, rgba(59, 130, 246, .15), transparent 28rem),
            linear-gradient(180deg, #f8fafc 0%, #eef2ff 100%);
    }

    body.finea-dark-mode .org-page {
        --org-ink: #f8fafc;
        --org-muted: #cbd5e1;
        --org-surface: rgba(15, 23, 42, .88);
        background:
            radial-gradient(circle at 8% 5%, rgba(16, 185, 129, .12), transparent 26rem),
            radial-gradient(circle at 92% 3%, rgba(59, 130, 246, .14), transparent 28rem),
            linear-gradient(180deg, #020617 0%, #0f172a 100%);
    }

    .org-hero {
        border-radius: 8px;
        background:
            linear-gradient(110deg, rgba(15, 23, 42, .98), rgba(6, 78, 59, .94) 58%, rgba(30, 64, 175, .90));
        color: #fff;
        box-shadow: 0 26px 70px rgba(15, 23, 42, .25);
    }

    .org-toolbar,
    .org-stage-shell {
        border: 1px solid rgba(148, 163, 184, .22);
        background: var(--org-surface);
        box-shadow: 0 18px 55px rgba(15, 23, 42, .08);
    }

    .org-toolbar {
        border-radius: 8px;
    }

    .org-control {
        height: 2.75rem;
        border: 1px solid rgba(148, 163, 184, .28);
        border-radius: 8px;
        background: rgba(248, 250, 252, .92);
        color: #334155;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: .5rem;
        padding: 0 .85rem;
        font-size: .8rem;
        font-weight: 800;
        transition: transform .16s ease, border-color .16s ease, background .16s ease;
    }

    .org-control:hover {
        transform: translateY(-1px);
        border-color: rgba(16, 185, 129, .5);
        background: #fff;
    }

    .org-control.is-active {
        border-color: #10b981;
        background: #047857;
        color: #fff;
        box-shadow: 0 0 0 3px rgba(16, 185, 129, .14);
    }

    .org-control:disabled {
        cursor: not-allowed;
        opacity: .48;
        transform: none;
    }

    .org-control.org-primary {
        border-color: #047857;
        background: #047857;
        color: #fff;
    }

    .org-control.org-danger {
        color: #b91c1c;
    }

    body.finea-dark-mode .org-control {
        background: rgba(2, 6, 23, .58);
        color: #e5edf8;
    }

    body.finea-dark-mode .org-control.is-active,
    body.finea-dark-mode .org-control.org-primary {
        background: #059669;
        color: #fff;
    }

    .org-search {
        min-width: min(330px, 100%);
        height: 2.75rem;
        border: 1px solid rgba(148, 163, 184, .28);
        border-radius: 8px;
        background: rgba(248, 250, 252, .92);
        color: var(--org-ink);
        padding: 0 .9rem 0 2.5rem;
        font-size: .86rem;
        font-weight: 700;
        outline: none;
    }

    body.finea-dark-mode .org-search {
        background: rgba(2, 6, 23, .58);
    }

    .org-stage-shell {
        position: relative;
        height: min(72vh, 760px);
        min-height: 520px;
        overflow: hidden;
        border-radius: 8px;
    }

    .org-stage {
        width: 100%;
        height: 100%;
        cursor: grab;
        touch-action: none;
        background:
            linear-gradient(rgba(148, 163, 184, .10) 1px, transparent 1px),
            linear-gradient(90deg, rgba(148, 163, 184, .10) 1px, transparent 1px);
        background-size: 32px 32px;
    }

    .org-stage.is-dragging {
        cursor: grabbing;
    }

    .org-stage.is-editing {
        cursor: default;
    }

    .org-stage.is-editing [data-node-id] {
        cursor: move !important;
    }

    .org-edit-banner {
        display: none;
        border: 1px solid rgba(16, 185, 129, .32);
        border-radius: 8px;
        background: rgba(236, 253, 245, .92);
        color: #065f46;
        padding: .7rem .85rem;
        font-size: .76rem;
        font-weight: 750;
    }

    .org-edit-banner.is-visible {
        display: flex;
    }

    body.finea-dark-mode .org-edit-banner {
        background: rgba(6, 78, 59, .42);
        color: #d1fae5;
    }

    .org-save-state {
        min-height: 1rem;
        color: var(--org-muted);
        font-size: .72rem;
        font-weight: 700;
    }

    .org-map-note {
        position: absolute;
        left: 1rem;
        bottom: 1rem;
        border-radius: 8px;
        border: 1px solid rgba(148, 163, 184, .20);
        background: rgba(255, 255, 255, .88);
        color: #64748b;
        padding: .55rem .75rem;
        font-size: .72rem;
        font-weight: 750;
        backdrop-filter: blur(12px);
    }

    body.finea-dark-mode .org-map-note {
        background: rgba(15, 23, 42, .86);
        color: #cbd5e1;
    }

    .org-result-panel {
        max-height: 220px;
        overflow: auto;
        border-radius: 8px;
        border: 1px solid rgba(148, 163, 184, .20);
        background: var(--org-surface);
    }

    .org-result-row {
        width: 100%;
        border-bottom: 1px solid rgba(148, 163, 184, .14);
        padding: .72rem .85rem;
        color: var(--org-ink);
        text-align: left;
        font-size: .8rem;
        font-weight: 750;
    }

    .org-result-row:hover {
        background: rgba(16, 185, 129, .09);
    }

    @media print {
        @page {
            size: A3 landscape;
            margin: 8mm;
        }

        body {
            background: #fff !important;
        }

        #appMenu,
        .org-no-print,
        .org-map-note {
            display: none !important;
        }

        .org-page {
            padding: 0;
            background: #fff !important;
        }

        .org-stage-shell {
            height: 270mm;
            border: 0;
            box-shadow: none;
        }
    }
</style>

<div class="org-page">
    <div class="mx-auto w-full max-w-[1800px] px-4 py-8">
        <header class="org-hero overflow-hidden px-6 py-8 md:px-9 md:py-10">
            <div class="flex flex-col gap-7 xl:flex-row xl:items-end xl:justify-between">
                <div class="max-w-4xl">
                    <div class="text-xs font-bold uppercase tracking-[0.28em] text-emerald-200">Ressources humaines</div>
                    <h1 class="mt-3 text-3xl font-semibold md:text-5xl">Organigramme général de l’entreprise</h1>
                    <p class="mt-4 max-w-3xl text-sm leading-6 text-slate-200 md:text-base">
                        Visualisation complète de la Direction générale, des responsables, des services et des collaborateurs en poste.
                    </p>
                </div>
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    <?php foreach ([
                        ['label' => 'Collaborateurs', 'value' => $snapshot['stats']['personnel'] ?? 0],
                        ['label' => 'Départements', 'value' => $snapshot['stats']['departments'] ?? 0],
                        ['label' => 'Services', 'value' => $snapshot['stats']['services'] ?? 0],
                        ['label' => 'Configurés', 'value' => $snapshot['stats']['configured_services'] ?? 0],
                    ] as $stat): ?>
                        <div class="min-w-[130px] rounded-[8px] border border-white/15 bg-white/10 px-4 py-3 backdrop-blur">
                            <div class="text-2xl font-black"><?= number_format((int) $stat['value'], 0, ',', ' ') ?></div>
                            <div class="mt-1 text-[11px] font-bold uppercase tracking-[0.12em] text-slate-200"><?= htmlspecialchars($stat['label']) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </header>

        <section class="org-toolbar org-no-print mt-5 p-4">
            <div class="flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between">
                <div class="flex min-w-0 flex-1 flex-col gap-3 sm:flex-row sm:items-center">
                    <div class="relative min-w-0">
                        <i class="fas fa-search pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400"></i>
                        <input id="orgSearch" type="search" class="org-search w-full" placeholder="Rechercher un nom, poste ou service">
                    </div>
                    <div id="orgSearchSummary" class="text-xs font-semibold text-slate-500"></div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button id="orgEditLayout" type="button" class="org-control org-primary"><i class="fas fa-edit"></i><span>Redesigner</span></button>
                    <button id="orgSaveLayout" type="button" class="org-control" disabled><i class="fas fa-save"></i><span>Enregistrer</span></button>
                    <button id="orgCancelLayout" type="button" class="org-control" disabled><i class="fas fa-undo"></i><span>Annuler</span></button>
                    <button id="orgResetLayout" type="button" class="org-control org-danger"><i class="fas fa-magic"></i><span>Disposition auto</span></button>
                    <button id="orgZoomOut" type="button" class="org-control" title="Réduire"><i class="fas fa-minus"></i></button>
                    <button id="orgFit" type="button" class="org-control"><i class="fas fa-expand"></i><span>Ajuster</span></button>
                    <button id="orgZoomIn" type="button" class="org-control" title="Agrandir"><i class="fas fa-plus"></i></button>
                    <button id="orgResetBranches" type="button" class="org-control"><i class="fas fa-sitemap"></i><span>Tout déployer</span></button>
                    <button id="orgExportPng" type="button" class="org-control"><i class="fas fa-image"></i><span>PNG</span></button>
                    <button id="orgExportSvg" type="button" class="org-control"><i class="fas fa-code"></i><span>SVG</span></button>
                    <a href="export_organigramme_pdf.php" target="_blank" rel="noopener" class="org-control"><i class="fas fa-file-pdf text-rose-600"></i><span>PDF paysage</span></a>
                    <button type="button" class="org-control" onclick="window.print()"><i class="fas fa-print"></i><span>Imprimer</span></button>
                </div>
            </div>
            <div id="orgEditBanner" class="org-edit-banner mt-3 items-center justify-between gap-3">
                <span><i class="fas fa-arrows-up-down-left-right mr-2"></i>Glissez une carte pour la deplacer, sa poignee carree pour la redimensionner, ou le point d une liaison pour deplacer son coude.</span>
                <span id="orgLayoutRevision" class="shrink-0"></span>
            </div>
            <div id="orgSaveState" class="org-save-state mt-2"></div>
            <div id="orgSearchResults" class="org-result-panel mt-3 hidden"></div>
        </section>

        <section class="org-stage-shell mt-5">
            <svg id="orgChartSvg" class="org-stage" role="img" aria-label="Organigramme général BANAMUR">
                <defs>
                    <filter id="orgShadow" x="-20%" y="-20%" width="140%" height="160%">
                        <feDropShadow dx="0" dy="8" stdDeviation="8" flood-color="#0f172a" flood-opacity=".14"/>
                    </filter>
                    <linearGradient id="orgCompanyGradient" x1="0" x2="1">
                        <stop offset="0" stop-color="#0f172a"/>
                        <stop offset=".55" stop-color="#065f46"/>
                        <stop offset="1" stop-color="#1d4ed8"/>
                    </linearGradient>
                </defs>
                <g id="orgViewport"></g>
            </svg>
            <div id="orgMapNote" class="org-map-note">Molette : zoom · Glisser : déplacer · Cliquer sur une carte : réduire/déployer</div>
        </section>
    </div>
</div>

<script>
    const ORG_TREE = <?= $chartJson ?>;
    const ORG_SAVED_LAYOUT = <?= $layoutJson ?>;
    const ORG_LAYOUT_CSRF = <?= json_encode($layoutCsrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    (function initOrganizationChart() {
        const svg = document.getElementById('orgChartSvg');
        const viewport = document.getElementById('orgViewport');
        const searchInput = document.getElementById('orgSearch');
        const searchResults = document.getElementById('orgSearchResults');
        const searchSummary = document.getElementById('orgSearchSummary');
        const editButton = document.getElementById('orgEditLayout');
        const saveButton = document.getElementById('orgSaveLayout');
        const cancelButton = document.getElementById('orgCancelLayout');
        const resetButton = document.getElementById('orgResetLayout');
        const editBanner = document.getElementById('orgEditBanner');
        const saveState = document.getElementById('orgSaveState');
        const revisionLabel = document.getElementById('orgLayoutRevision');
        const mapNote = document.getElementById('orgMapNote');
        if (!svg || !viewport || !ORG_TREE || !ORG_TREE.id) return;

        const NS = 'http://www.w3.org/2000/svg';
        const CARD_WIDTH = 224;
        const CARD_HEIGHT = 102;
        const EMPLOYEE_WIDTH = 210;
        const EMPLOYEE_HEIGHT = 72;
        const H_GAP = 34;
        const V_GAP = 68;
        const EMPLOYEE_GAP = 18;
        const SERVICE_STACK_GAP = 54;
        const collapsed = new Set();
        const nodeIndex = new Map();
        let layoutState = JSON.parse(JSON.stringify(ORG_SAVED_LAYOUT || { nodes: {}, edges: {} }));
        layoutState.nodes = layoutState.nodes || {};
        layoutState.edges = layoutState.edges || {};
        let layoutBeforeEditing = null;
        let editing = false;
        let layoutDirty = false;
        let editInteraction = null;
        let edgeLayer = null;
        let nodeLayer = null;
        let scale = 1;
        let translateX = 0;
        let translateY = 0;
        let contentBounds = { minX: 0, minY: 0, width: 1, height: 1 };
        let dragging = false;
        let dragStart = null;

        function svgEl(name, attrs = {}) {
            const node = document.createElementNS(NS, name);
            Object.entries(attrs).forEach(([key, value]) => node.setAttribute(key, String(value)));
            return node;
        }

        function normalize(value) {
            return String(value || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        }

        function visibleChildren(node) {
            return collapsed.has(node.id) ? [] : (Array.isArray(node.children) ? node.children : []);
        }

        function nodeCardWidth(node) {
            const stored = layoutState.nodes[node.id];
            return Number(stored?.width) || (node.type === 'employee' ? EMPLOYEE_WIDTH : CARD_WIDTH);
        }

        function nodeCardHeight(node) {
            const stored = layoutState.nodes[node.id];
            return Number(stored?.height) || (node.type === 'employee' ? EMPLOYEE_HEIGHT : CARD_HEIGHT);
        }

        function measure(node) {
            const children = visibleChildren(node);
            nodeIndex.set(node.id, node);
            node._cardWidth = nodeCardWidth(node);
            node._cardHeight = nodeCardHeight(node);

            if (!children.length) {
                node._width = node._cardWidth;
                node._height = node._cardHeight;
                return node._width;
            }

            if (node.type === 'service') {
                const manager = children.length === 1 && children[0].type === 'manager' ? children[0] : null;
                const employees = manager ? visibleChildren(manager) : children.filter(child => child.type === 'employee');
                if (manager) {
                    nodeIndex.set(manager.id, manager);
                    manager._cardWidth = nodeCardWidth(manager);
                    manager._cardHeight = nodeCardHeight(manager);
                }
                employees.forEach(employee => {
                    nodeIndex.set(employee.id, employee);
                    employee._cardWidth = nodeCardWidth(employee);
                    employee._cardHeight = nodeCardHeight(employee);
                    employee._width = employee._cardWidth;
                    employee._height = employee._cardHeight;
                });

                const columns = employees.length <= 1 ? 1 : Math.min(3, Math.ceil(Math.sqrt(employees.length)));
                const rows = employees.length ? Math.ceil(employees.length / columns) : 0;
                const employeeWidth = Math.max(EMPLOYEE_WIDTH, ...employees.map(employee => employee._cardWidth), EMPLOYEE_WIDTH);
                const rowHeights = Array.from({ length: rows }, (_, rowIndex) => {
                    const rowEmployees = employees.slice(rowIndex * columns, (rowIndex + 1) * columns);
                    return Math.max(...rowEmployees.map(employee => employee._cardHeight), EMPLOYEE_HEIGHT);
                });
                node._employeeColumns = columns;
                node._employeeWidth = employeeWidth;
                node._employeeRowHeights = rowHeights;
                node._managerNode = manager;
                node._employeeNodes = employees;
                node._width = Math.max(node._cardWidth, manager?._cardWidth || 0, columns * employeeWidth + Math.max(0, columns - 1) * EMPLOYEE_GAP);
                node._height = node._cardHeight
                    + (manager ? V_GAP + manager._cardHeight : 0)
                    + (rows ? V_GAP + rowHeights.reduce((sum, height) => sum + height, 0) + Math.max(0, rows - 1) * EMPLOYEE_GAP : 0);
                return node._width;
            }

            children.forEach(measure);

            if (node.type === 'dg' || node.type === 'dg-empty') {
                node._unitColumns = children.length;
                node._width = Math.max(node._cardWidth, children.reduce((sum, child) => sum + child._width, 0) + Math.max(0, children.length - 1) * H_GAP);
                node._height = node._cardHeight + V_GAP
                    + Math.max(...children.map(child => child._height), CARD_HEIGHT);
                return node._width;
            }

            if (node.type === 'department' || node.type === 'department-empty') {
                node._serviceSpineWidth = 46;
                node._width = Math.max(CARD_WIDTH, ...children.map(child => child._width + node._serviceSpineWidth));
                node._height = node._cardHeight + V_GAP
                    + children.reduce((sum, child) => sum + child._height, 0)
                    + Math.max(0, children.length - 1) * SERVICE_STACK_GAP;
                return node._width;
            }

            node._width = Math.max(node._cardWidth, children.reduce((sum, child) => sum + child._width, 0) + H_GAP * (children.length - 1));
            node._height = node._cardHeight + V_GAP + Math.max(...children.map(child => child._height));
            return node._width;
        }

        function layout(node, left, top) {
            node._x = left + node._width / 2;
            node._y = top;
            const children = visibleChildren(node);
            if (!children.length) return;

            if (node.type === 'service') {
                let employeeTop = top + node._cardHeight + V_GAP;
                if (node._managerNode) {
                    const manager = node._managerNode;
                    manager._x = node._x;
                    manager._y = employeeTop;
                    manager._width = manager._cardWidth;
                    manager._height = manager._cardHeight;
                    employeeTop += manager._cardHeight + V_GAP;
                }

                const employees = node._employeeNodes || [];
                const columns = node._employeeColumns || 1;
                const employeeWidth = node._employeeWidth || EMPLOYEE_WIDTH;
                const rowHeights = node._employeeRowHeights || [];
                const gridWidth = columns * employeeWidth + Math.max(0, columns - 1) * EMPLOYEE_GAP;
                const gridLeft = left + (node._width - gridWidth) / 2;
                employees.forEach((employee, index) => {
                    const column = index % columns;
                    const row = Math.floor(index / columns);
                    employee._x = gridLeft + column * (employeeWidth + EMPLOYEE_GAP) + employeeWidth / 2;
                    employee._y = employeeTop
                        + rowHeights.slice(0, row).reduce((sum, height) => sum + height, 0)
                        + row * EMPLOYEE_GAP;
                });
                return;
            }

            if (node.type === 'department' || node.type === 'department-empty') {
                let childTop = top + node._cardHeight + V_GAP;
                children.forEach(child => {
                    layout(child, left + node._serviceSpineWidth + (node._width - node._serviceSpineWidth - child._width) / 2, childTop);
                    childTop += child._height + SERVICE_STACK_GAP;
                });
                return;
            }

            if (node.type === 'dg' || node.type === 'dg-empty') {
                let childLeft = left;
                const childTop = top + node._cardHeight + V_GAP;
                children.forEach(child => {
                    layout(child, childLeft, childTop);
                    childLeft += child._width + H_GAP;
                });
                return;
            }

            let childLeft = left;
            children.forEach(child => {
                layout(child, childLeft, top + node._cardHeight + V_GAP);
                childLeft += child._width + H_GAP;
            });
        }

        function splitText(value, maxLength = 29, maxLines = 2) {
            const words = String(value || '').trim().split(/\s+/);
            const lines = [];
            let current = '';
            words.forEach(word => {
                const next = current ? current + ' ' + word : word;
                if (next.length > maxLength && current) {
                    lines.push(current);
                    current = word;
                } else {
                    current = next;
                }
            });
            if (current) lines.push(current);
            return lines.slice(0, maxLines);
        }

        function ellipsize(value, maxLength) {
            const text = String(value || '').trim();
            return text.length > maxLength ? text.slice(0, Math.max(1, maxLength - 1)).trimEnd() + '…' : text;
        }

        function palette(type) {
            const dark = document.body.classList.contains('finea-dark-mode');
            const palettes = {
                company: ['url(#orgCompanyGradient)', '#ffffff', '#d1fae5', '#ffffff'],
                dg: ['#064e3b', '#ffffff', '#a7f3d0', '#10b981'],
                'dg-empty': [dark ? '#422006' : '#fffbeb', dark ? '#ffffff' : '#92400e', '#fbbf24', '#f59e0b'],
                direction: ['#1e3a8a', '#ffffff', '#bfdbfe', '#3b82f6'],
                department: ['#1e3a8a', '#ffffff', '#bfdbfe', '#3b82f6'],
                'department-empty': [dark ? '#3f3f46' : '#f8fafc', dark ? '#ffffff' : '#0f172a', '#f59e0b', '#f59e0b'],
                service: [dark ? '#172554' : '#eff6ff', dark ? '#ffffff' : '#1e3a8a', dark ? '#bfdbfe' : '#475569', '#60a5fa'],
                manager: [dark ? '#064e3b' : '#ecfdf5', dark ? '#ffffff' : '#065f46', dark ? '#a7f3d0' : '#475569', '#10b981'],
                employee: [dark ? '#111827' : '#ffffff', dark ? '#f8fafc' : '#0f172a', dark ? '#cbd5e1' : '#64748b', '#94a3b8']
            };
            return palettes[type] || palettes.employee;
        }

        function addText(group, value, x, y, options = {}) {
            const text = svgEl('text', {
                x, y,
                fill: options.fill || '#0f172a',
                'font-size': options.size || 12,
                'font-weight': options.weight || 700,
                'text-anchor': options.anchor || 'start',
                'font-family': 'Arial, sans-serif'
            });
            text.textContent = value;
            group.appendChild(text);
            return text;
        }

        function renderEdge(parent, child) {
            const startY = parent._y + (parent._cardHeight || CARD_HEIGHT);
            const endY = child._y;
            const storedMiddle = Number(layoutState.edges[child.id]?.midY);
            const middleY = Number.isFinite(storedMiddle) ? storedMiddle : startY + (endY - startY) / 2;
            const path = svgEl('path', {
                d: `M ${parent._x} ${startY} V ${middleY} H ${child._x} V ${endY}`,
                fill: 'none',
                stroke: document.body.classList.contains('finea-dark-mode') ? '#64748b' : '#94a3b8',
                'stroke-width': 2,
                'stroke-linecap': 'round',
                'stroke-linejoin': 'round',
                'data-edge-child': child.id
            });
            edgeLayer.appendChild(path);

            if (editing) {
                const handle = svgEl('circle', {
                    cx: (parent._x + child._x) / 2,
                    cy: middleY,
                    r: 7,
                    fill: '#ffffff',
                    stroke: '#10b981',
                    'stroke-width': 3,
                    'data-edge-handle': child.id
                });
                handle.style.cursor = 'ns-resize';
                handle.addEventListener('pointerdown', event => {
                    event.preventDefault();
                    event.stopPropagation();
                    editInteraction = {
                        type: 'edge',
                        nodeId: child.id,
                        startY: chartPoint(event).y,
                        originalMidY: middleY
                    };
                    svg.setPointerCapture(event.pointerId);
                });
                edgeLayer.appendChild(handle);
            }
        }

        function renderNode(node) {
            const [background, titleColor, subtitleColor, accent] = palette(node.type);
            const cardWidth = node._cardWidth || CARD_WIDTH;
            const cardHeight = node._cardHeight || CARD_HEIGHT;
            const x = node._x - cardWidth / 2;
            const y = node._y;
            const group = svgEl('g', {
                'data-node-id': node.id,
                transform: `translate(${x} ${y})`,
                tabindex: 0,
                role: 'button',
                'aria-label': `${node.title}. ${node.subtitle || ''}`
            });
            group.style.cursor = Array.isArray(node.children) && node.children.length ? 'pointer' : 'default';

            group.appendChild(svgEl('rect', {
                width: cardWidth,
                height: cardHeight,
                rx: 8,
                fill: background,
                stroke: accent,
                'stroke-width': ['company', 'dg', 'dg-empty'].includes(node.type) ? 2 : 1.3,
                filter: 'url(#orgShadow)'
            }));
            group.appendChild(svgEl('rect', { width: 6, height: cardHeight, rx: 3, fill: accent }));

            const showAvatar = !['company', 'service', 'department-empty'].includes(node.type);
            const isEmployee = node.type === 'employee';
            const textX = showAvatar ? (isEmployee ? 49 : 58) : 18;
            if (showAvatar) {
                const avatarX = isEmployee ? 27 : 31;
                const avatarY = isEmployee ? 29 : 34;
                const avatarRadius = isEmployee ? 14 : 18;
                group.appendChild(svgEl('circle', { cx: avatarX, cy: avatarY, r: avatarRadius, fill: accent, opacity: .95 }));
                addText(group, node.initials || 'RH', avatarX, avatarY + 4, { fill: '#fff', size: isEmployee ? 9 : 11, weight: 900, anchor: 'middle' });
            }

            const availableTextWidth = Math.max(70, cardWidth - textX - 15);
            const titleCapacity = Math.max(12, Math.floor(availableTextWidth / (isEmployee ? 5.7 : 6.5)));
            const titleStartY = isEmployee ? 21 : 25;
            const titleLineHeight = isEmployee ? 13 : 15;
            const maxTitleLines = Math.max(2, Math.min(6, Math.floor((cardHeight - titleStartY - 28) / titleLineHeight)));
            const titleLines = splitText(node.title, titleCapacity, maxTitleLines);
            titleLines.forEach((line, index) => addText(group, line, textX, titleStartY + index * titleLineHeight, {
                fill: titleColor,
                size: node.type === 'company' ? 16 : (isEmployee ? 10.5 : 12.5),
                weight: 900
            }));
            const subtitleY = titleStartY + titleLines.length * titleLineHeight + 3;
            if (subtitleY <= cardHeight - 10) {
                addText(group, ellipsize(node.subtitle || '', Math.max(14, Math.floor(availableTextWidth / 5.3))), textX, subtitleY, {
                    fill: subtitleColor,
                    size: isEmployee ? 8.5 : 10.5,
                    weight: 700
                });
            }
            const metaY = subtitleY + (isEmployee ? 12 : 15);
            if (node.meta && metaY <= cardHeight - 9) {
                addText(group, ellipsize(node.meta, Math.max(14, Math.floor(availableTextWidth / 5))), textX, metaY, {
                    fill: subtitleColor,
                    size: isEmployee ? 8 : 9,
                    weight: 600
                });
            }

            if (Array.isArray(node.children) && node.children.length) {
                const collapseControl = svgEl('g', {
                    'data-collapse-node': node.id,
                    role: 'button',
                    'aria-label': collapsed.has(node.id) ? 'Deployer la branche' : 'Reduire la branche'
                });
                collapseControl.style.cursor = 'pointer';
                collapseControl.appendChild(svgEl('circle', {
                    cx: cardWidth - 17,
                    cy: cardHeight - 17,
                    r: 15,
                    fill: 'transparent'
                }));
                collapseControl.appendChild(svgEl('circle', {
                    cx: cardWidth - 17,
                    cy: cardHeight - 17,
                    r: 10,
                    fill: accent
                }));
                addText(collapseControl, collapsed.has(node.id) ? '+' : '−', cardWidth - 17, cardHeight - 13.5, {
                    fill: '#fff', size: 14, weight: 900, anchor: 'middle'
                });
                collapseControl.addEventListener('pointerdown', event => {
                    event.preventDefault();
                    event.stopPropagation();
                });
                collapseControl.addEventListener('click', event => {
                    event.preventDefault();
                    event.stopPropagation();
                    collapsed.has(node.id) ? collapsed.delete(node.id) : collapsed.add(node.id);
                    render();
                    if (!editing) fitChart();
                });
                group.appendChild(collapseControl);
            }

            if ((node.personnel_id || 0) > 0) {
                const link = svgEl('a', { href: `dossier_personnel_rh.php?id=${node.personnel_id}` });
                link.appendChild(svgEl('rect', {
                    x: cardWidth - 40, y: 7, width: 25, height: 20, rx: 6,
                    fill: '#ffffff', opacity: .16
                }));
                addText(link, '↗', cardWidth - 27.5, 22, { fill: titleColor, size: 12, weight: 900, anchor: 'middle' });
                link.addEventListener('click', event => event.stopPropagation());
                group.appendChild(link);
            }

            if (editing) {
                const resizeHandle = svgEl('rect', {
                    x: 7,
                    y: cardHeight - 13,
                    width: 13,
                    height: 13,
                    rx: 2,
                    fill: '#10b981',
                    stroke: '#ffffff',
                    'stroke-width': 2,
                    'data-resize-node': node.id
                });
                resizeHandle.style.cursor = 'nwse-resize';
                resizeHandle.addEventListener('pointerdown', event => {
                    event.preventDefault();
                    event.stopPropagation();
                    const point = chartPoint(event);
                    editInteraction = {
                        type: 'resize',
                        nodeId: node.id,
                        startX: point.x,
                        startY: point.y,
                        originalWidth: cardWidth,
                        originalHeight: cardHeight
                    };
                    svg.setPointerCapture(event.pointerId);
                });
                group.appendChild(resizeHandle);

                group.addEventListener('pointerdown', event => {
                    if (event.target.closest('[data-resize-node], [data-collapse-node], a')) return;
                    event.preventDefault();
                    event.stopPropagation();
                    const point = chartPoint(event);
                    editInteraction = {
                        type: 'move',
                        nodeId: node.id,
                        startX: point.x,
                        startY: point.y,
                        originalX: node._x,
                        originalY: node._y
                    };
                    svg.setPointerCapture(event.pointerId);
                });
            }

            group.addEventListener('click', () => {
                if (editing) return;
                if (!Array.isArray(node.children) || !node.children.length) return;
                collapsed.has(node.id) ? collapsed.delete(node.id) : collapsed.add(node.id);
                render();
                fitChart();
            });
            nodeLayer.appendChild(group);
        }

        function walk(node, callback) {
            callback(node);
            visibleChildren(node).forEach(child => walk(child, callback));
        }

        function applySavedNodeGeometry(nodes) {
            nodes.forEach(node => {
                const stored = layoutState.nodes[node.id];
                if (!stored) return;
                if (Number.isFinite(Number(stored.x))) node._x = Number(stored.x);
                if (Number.isFinite(Number(stored.y))) node._y = Number(stored.y);
                if (Number.isFinite(Number(stored.width))) node._cardWidth = Number(stored.width);
                if (Number.isFinite(Number(stored.height))) node._cardHeight = Number(stored.height);
            });
        }

        function render() {
            viewport.innerHTML = '';
            edgeLayer = svgEl('g', { id: 'orgEdges' });
            nodeLayer = svgEl('g', { id: 'orgNodes' });
            viewport.appendChild(edgeLayer);
            viewport.appendChild(nodeLayer);
            nodeIndex.clear();
            measure(ORG_TREE);
            layout(ORG_TREE, 32, 32);
            const nodes = [];
            walk(ORG_TREE, node => nodes.push(node));
            applySavedNodeGeometry(nodes);
            nodes.forEach(parent => visibleChildren(parent).forEach(child => renderEdge(parent, child)));
            nodes.forEach(renderNode);
            const minX = Math.min(...nodes.map(node => node._x - (node._cardWidth || CARD_WIDTH) / 2), 0) - 32;
            const minY = Math.min(...nodes.map(node => node._y), 0) - 32;
            const maxX = Math.max(...nodes.map(node => node._x + (node._cardWidth || CARD_WIDTH) / 2), 1) + 32;
            const maxBottom = Math.max(...nodes.map(node => node._y + (node._cardHeight || CARD_HEIGHT)), 1) + 32;
            contentBounds = {
                minX,
                minY,
                width: maxX - minX,
                height: maxBottom - minY
            };
            svg.setAttribute('viewBox', `0 0 ${svg.clientWidth || 1200} ${svg.clientHeight || 700}`);
            updateTransform();
            updateEditUi();
        }

        function updateTransform() {
            viewport.setAttribute('transform', `translate(${translateX} ${translateY}) scale(${scale})`);
        }

        function fitChart() {
            const width = svg.clientWidth || 1200;
            const height = svg.clientHeight || 700;
            scale = Math.min(1, Math.max(.1, Math.min((width - 64) / contentBounds.width, (height - 48) / contentBounds.height)));
            translateX = (width - contentBounds.width * scale) / 2 - contentBounds.minX * scale;
            translateY = 24 - contentBounds.minY * scale;
            updateTransform();
        }

        function zoomAt(factor, centerX = svg.clientWidth / 2, centerY = svg.clientHeight / 2) {
            const previousScale = scale;
            scale = Math.min(2.2, Math.max(.1, scale * factor));
            translateX = centerX - ((centerX - translateX) / previousScale) * scale;
            translateY = centerY - ((centerY - translateY) / previousScale) * scale;
            updateTransform();
        }

        function centerNode(node) {
            if (!node) return;
            scale = Math.max(scale, .75);
            translateX = svg.clientWidth / 2 - node._x * scale;
            translateY = svg.clientHeight / 2 - (node._y + (node._cardHeight || CARD_HEIGHT) / 2) * scale;
            updateTransform();
            const card = viewport.querySelector(`[data-node-id="${CSS.escape(node.id)}"] rect`);
            if (card) {
                const oldStroke = card.getAttribute('stroke');
                const oldWidth = card.getAttribute('stroke-width');
                card.setAttribute('stroke', '#f59e0b');
                card.setAttribute('stroke-width', '5');
                setTimeout(() => {
                    card.setAttribute('stroke', oldStroke || '#94a3b8');
                    card.setAttribute('stroke-width', oldWidth || '1.3');
                }, 1800);
            }
        }

        function exportSvg() {
            const clone = svg.cloneNode(true);
            clone.setAttribute('xmlns', NS);
            clone.setAttribute('width', contentBounds.width);
            clone.setAttribute('height', contentBounds.height);
            clone.setAttribute('viewBox', `${contentBounds.minX} ${contentBounds.minY} ${contentBounds.width} ${contentBounds.height}`);
            clone.querySelector('#orgViewport').setAttribute('transform', '');
            clone.querySelectorAll('[data-resize-node], [data-edge-handle]').forEach(handle => handle.remove());
            const blob = new Blob([new XMLSerializer().serializeToString(clone)], { type: 'image/svg+xml;charset=utf-8' });
            downloadBlob(blob, `organigramme_banamur_${new Date().toISOString().slice(0, 10)}.svg`);
        }

        function exportPng() {
            const clone = svg.cloneNode(true);
            clone.setAttribute('xmlns', NS);
            clone.setAttribute('width', contentBounds.width);
            clone.setAttribute('height', contentBounds.height);
            clone.setAttribute('viewBox', `${contentBounds.minX} ${contentBounds.minY} ${contentBounds.width} ${contentBounds.height}`);
            clone.querySelector('#orgViewport').setAttribute('transform', '');
            clone.querySelectorAll('[data-resize-node], [data-edge-handle]').forEach(handle => handle.remove());
            const source = new XMLSerializer().serializeToString(clone);
            const blob = new Blob([source], { type: 'image/svg+xml;charset=utf-8' });
            const url = URL.createObjectURL(blob);
            const image = new Image();
            image.onload = () => {
                const maxSide = 12000;
                const ratio = Math.min(2, maxSide / Math.max(contentBounds.width, contentBounds.height));
                const canvas = document.createElement('canvas');
                canvas.width = Math.max(1, Math.round(contentBounds.width * ratio));
                canvas.height = Math.max(1, Math.round(contentBounds.height * ratio));
                const context = canvas.getContext('2d');
                context.fillStyle = document.body.classList.contains('finea-dark-mode') ? '#020617' : '#f8fafc';
                context.fillRect(0, 0, canvas.width, canvas.height);
                context.drawImage(image, 0, 0, canvas.width, canvas.height);
                canvas.toBlob(pngBlob => {
                    if (pngBlob) downloadBlob(pngBlob, `organigramme_banamur_${new Date().toISOString().slice(0, 10)}.png`);
                    URL.revokeObjectURL(url);
                }, 'image/png');
            };
            image.src = url;
        }

        function downloadBlob(blob, filename) {
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = filename;
            document.body.appendChild(link);
            link.click();
            link.remove();
            setTimeout(() => URL.revokeObjectURL(url), 1000);
        }

        function chartPoint(event) {
            const rect = svg.getBoundingClientRect();
            return {
                x: (event.clientX - rect.left - translateX) / scale,
                y: (event.clientY - rect.top - translateY) / scale
            };
        }

        function captureCurrentLayout() {
            const nodes = JSON.parse(JSON.stringify(layoutState.nodes || {}));
            nodeIndex.forEach((node, nodeId) => {
                nodes[nodeId] = {
                    x: Math.round(node._x * 100) / 100,
                    y: Math.round(node._y * 100) / 100,
                    width: Math.round((node._cardWidth || CARD_WIDTH) * 100) / 100,
                    height: Math.round((node._cardHeight || CARD_HEIGHT) * 100) / 100
                };
            });
            return {
                version: 1,
                nodes,
                edges: JSON.parse(JSON.stringify(layoutState.edges || {})),
                revision: Number(layoutState.revision || 0)
            };
        }

        function markLayoutDirty() {
            layoutDirty = true;
            if (saveState) saveState.textContent = 'Modifications non enregistrees';
            updateEditUi();
        }

        function updateEditUi() {
            svg.classList.toggle('is-editing', editing);
            editButton?.classList.toggle('is-active', editing);
            if (editButton) editButton.querySelector('span').textContent = editing ? 'Edition active' : 'Redesigner';
            if (saveButton) saveButton.disabled = !editing || !layoutDirty;
            if (cancelButton) cancelButton.disabled = !editing;
            editBanner?.classList.toggle('is-visible', editing);
            if (revisionLabel) revisionLabel.textContent = `Version ${Number(layoutState.revision || 0)}`;
            if (mapNote) {
                mapNote.textContent = editing
                    ? 'Edition : deplacer les cartes, les redimensionner et ajuster les liaisons'
                    : 'Molette : zoom · Glisser : déplacer · Cliquer sur une carte : réduire/déployer';
            }
        }

        function startEditing() {
            if (editing) return;
            collapsed.clear();
            render();
            layoutBeforeEditing = JSON.parse(JSON.stringify(layoutState));
            layoutState = captureCurrentLayout();
            editing = true;
            layoutDirty = false;
            if (saveState) saveState.textContent = 'Mode edition active';
            render();
        }

        function cancelEditing() {
            if (!editing) return;
            layoutState = layoutBeforeEditing || layoutState;
            layoutBeforeEditing = null;
            editing = false;
            layoutDirty = false;
            editInteraction = null;
            if (saveState) saveState.textContent = 'Modifications annulees';
            render();
            fitChart();
        }

        async function persistLayout(action = 'save') {
            if (saveButton) saveButton.disabled = true;
            if (saveState) saveState.textContent = action === 'reset' ? 'Restauration en cours...' : 'Enregistrement en cours...';
            try {
                const response = await fetch('save_organigramme_layout.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({
                        action,
                        csrf_token: ORG_LAYOUT_CSRF,
                        layout: action === 'save' ? captureCurrentLayout() : undefined
                    })
                });
                const result = await response.json();
                if (!response.ok || !result.ok) throw new Error(result.message || 'Enregistrement impossible.');
                layoutState = result.layout || { nodes: {}, edges: {}, revision: 0 };
                layoutState.nodes = layoutState.nodes || {};
                layoutState.edges = layoutState.edges || {};
                layoutBeforeEditing = null;
                editing = false;
                layoutDirty = false;
                if (saveState) saveState.textContent = result.message || 'Disposition enregistree.';
                render();
                fitChart();
            } catch (error) {
                if (saveState) saveState.textContent = error.message || 'Enregistrement impossible.';
                updateEditUi();
            }
        }

        svg.addEventListener('wheel', event => {
            event.preventDefault();
            const rect = svg.getBoundingClientRect();
            zoomAt(event.deltaY < 0 ? 1.12 : .89, event.clientX - rect.left, event.clientY - rect.top);
        }, { passive: false });
        svg.addEventListener('pointerdown', event => {
            if (editInteraction) return;
            dragging = true;
            dragStart = { x: event.clientX, y: event.clientY, tx: translateX, ty: translateY };
            svg.classList.add('is-dragging');
            svg.setPointerCapture(event.pointerId);
        });
        svg.addEventListener('pointermove', event => {
            if (editInteraction) {
                const point = chartPoint(event);
                if (editInteraction.type === 'move') {
                    const current = layoutState.nodes[editInteraction.nodeId];
                    if (!current) return;
                    current.x = editInteraction.originalX + point.x - editInteraction.startX;
                    current.y = editInteraction.originalY + point.y - editInteraction.startY;
                } else if (editInteraction.type === 'resize') {
                    const current = layoutState.nodes[editInteraction.nodeId];
                    const node = nodeIndex.get(editInteraction.nodeId);
                    if (!current || !node) return;
                    const minimumHeight = node.type === 'employee' ? 58 : 76;
                    current.width = Math.min(900, Math.max(150, editInteraction.originalWidth + point.x - editInteraction.startX));
                    current.height = Math.min(420, Math.max(minimumHeight, editInteraction.originalHeight + point.y - editInteraction.startY));
                } else if (editInteraction.type === 'edge') {
                    layoutState.edges[editInteraction.nodeId] = {
                        midY: editInteraction.originalMidY + point.y - editInteraction.startY
                    };
                }
                markLayoutDirty();
                render();
                return;
            }
            if (!dragging || !dragStart) return;
            translateX = dragStart.tx + event.clientX - dragStart.x;
            translateY = dragStart.ty + event.clientY - dragStart.y;
            updateTransform();
        });
        svg.addEventListener('pointerup', () => {
            editInteraction = null;
            dragging = false;
            dragStart = null;
            svg.classList.remove('is-dragging');
        });

        editButton?.addEventListener('click', () => editing ? cancelEditing() : startEditing());
        saveButton?.addEventListener('click', () => persistLayout('save'));
        cancelButton?.addEventListener('click', cancelEditing);
        resetButton?.addEventListener('click', () => {
            if (!window.confirm('Restaurer la disposition automatique pour tout le module RH ?')) return;
            persistLayout('reset');
        });
        document.getElementById('orgZoomIn')?.addEventListener('click', () => zoomAt(1.2));
        document.getElementById('orgZoomOut')?.addEventListener('click', () => zoomAt(.82));
        document.getElementById('orgFit')?.addEventListener('click', fitChart);
        document.getElementById('orgResetBranches')?.addEventListener('click', () => {
            collapsed.clear();
            render();
            fitChart();
        });
        document.getElementById('orgExportSvg')?.addEventListener('click', exportSvg);
        document.getElementById('orgExportPng')?.addEventListener('click', exportPng);
        window.addEventListener('resize', fitChart);

        searchInput?.addEventListener('input', () => {
            const query = normalize(searchInput.value);
            if (query.length < 2) {
                searchResults.classList.add('hidden');
                searchResults.innerHTML = '';
                searchSummary.textContent = '';
                return;
            }
            const matches = Array.from(nodeIndex.values()).filter(node =>
                normalize([node.title, node.subtitle, node.meta].join(' ')).includes(query)
            ).slice(0, 30);
            searchSummary.textContent = `${matches.length} résultat(s)`;
            searchResults.innerHTML = matches.map(node => `
                <button type="button" class="org-result-row" data-org-result="${String(node.id).replace(/"/g, '&quot;')}">
                    <strong>${String(node.title || '').replace(/</g, '&lt;')}</strong>
                    <span class="ml-2 text-slate-500">${String(node.subtitle || '').replace(/</g, '&lt;')}</span>
                </button>
            `).join('');
            searchResults.classList.toggle('hidden', matches.length === 0);
        });
        searchResults?.addEventListener('click', event => {
            const button = event.target.closest('[data-org-result]');
            if (!button) return;
            centerNode(nodeIndex.get(button.getAttribute('data-org-result')));
            searchResults.classList.add('hidden');
        });

        window.addEventListener('beforeunload', event => {
            if (!editing || !layoutDirty) return;
            event.preventDefault();
            event.returnValue = '';
        });

        render();
        requestAnimationFrame(fitChart);

        const themeObserver = new MutationObserver(() => render());
        themeObserver.observe(document.body, { attributes: true, attributeFilter: ['class'] });
    })();
</script>

<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>
