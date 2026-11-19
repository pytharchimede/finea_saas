<?php
require_once __DIR__ . '/../auth_check.php';
AccessManager::requirePageAccess();

$pageTitle = 'Pilotage Workflow Fiches';
$activeMenu = '';
$initialNum = trim((string)($_GET['num_fiche'] ?? ''));

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>

<div class="min-h-screen bg-[radial-gradient(circle_at_top_left,_rgba(14,116,144,0.12),_transparent_32%),radial-gradient(circle_at_top_right,_rgba(79,70,229,0.12),_transparent_28%),linear-gradient(180deg,_#f8fafc_0%,_#eef2ff_100%)]">
    <div class="container mx-auto px-4 py-8 lg:py-10">
        <section class="relative overflow-hidden rounded-[30px] border border-slate-200/70 bg-white/90 shadow-[0_30px_80px_-38px_rgba(15,23,42,0.55)] backdrop-blur">
            <div class="absolute inset-y-0 right-0 w-1/3 bg-[linear-gradient(135deg,rgba(14,116,144,0.08),rgba(79,70,229,0.14))]"></div>
            <div class="relative grid gap-8 px-6 py-8 lg:grid-cols-[1.45fr_0.95fr] lg:px-10">
                <div>
                    <div class="inline-flex items-center gap-2 rounded-full border border-cyan-200 bg-cyan-50 px-3 py-1 text-xs font-semibold uppercase tracking-[0.22em] text-cyan-700">
                        Administration workflow
                    </div>
                    <h1 class="mt-4 max-w-3xl text-3xl font-black tracking-tight text-slate-900 lg:text-5xl">
                        Repositionner une fiche et convertir un décaissement total en partiel.
                    </h1>
                    <p class="mt-4 max-w-2xl text-sm leading-7 text-slate-600 lg:text-base">
                        Cet écran admin permet de rechercher une fiche, visualiser sa famille parent/enfant, la ramener à une étape métier précise et créer une fiche héritière de reliquat qui repart en validation achat.
                    </p>
                    <div class="mt-6 flex flex-wrap gap-3">
                        <a href="dashboard_admin.php" class="inline-flex items-center gap-2 rounded-2xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-slate-800">
                            <i class="fas fa-arrow-left"></i>
                            Retour dashboard admin
                        </a>
                        <a href="../decaissement/point_financier.php" class="inline-flex items-center gap-2 rounded-2xl border border-slate-300 bg-white px-5 py-3 text-sm font-semibold text-slate-700 transition hover:border-cyan-300 hover:text-cyan-700">
                            <i class="fas fa-chart-line"></i>
                            Ouvrir le point financier
                        </a>
                    </div>
                </div>
                <div class="grid gap-4 sm:grid-cols-3 lg:grid-cols-1">
                    <div class="rounded-3xl border border-slate-200 bg-slate-50/90 p-5">
                        <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Étapes cibles</div>
                        <div class="mt-3 flex flex-wrap gap-2 text-xs font-semibold text-slate-700">
                            <span class="rounded-full bg-white px-3 py-1 shadow-sm">Conformité</span>
                            <span class="rounded-full bg-white px-3 py-1 shadow-sm">Approbation</span>
                            <span class="rounded-full bg-white px-3 py-1 shadow-sm">Validation achat</span>
                            <span class="rounded-full bg-white px-3 py-1 shadow-sm">Traitement comptable</span>
                            <span class="rounded-full bg-white px-3 py-1 shadow-sm">Décaissée</span>
                        </div>
                    </div>
                    <div class="rounded-3xl border border-emerald-200 bg-emerald-50/90 p-5">
                        <div class="text-xs font-semibold uppercase tracking-[0.2em] text-emerald-700">Partiel</div>
                        <p class="mt-3 text-sm leading-6 text-emerald-900">
                            Réduit le montant déjà payé, crée une fiche héritière pour le reliquat et la remet en validation achat avec une date de reprise contrôlée.
                        </p>
                    </div>
                    <div class="rounded-3xl border border-amber-200 bg-amber-50/90 p-5">
                        <div class="text-xs font-semibold uppercase tracking-[0.2em] text-amber-700">Garde-fous</div>
                        <p class="mt-3 text-sm leading-6 text-amber-900">
                            Les opérations sont tracées. La conversion total vers partiel est bloquée si une héritière existe déjà.
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <section class="mt-8 rounded-[28px] border border-slate-200/70 bg-white/95 p-6 shadow-[0_24px_55px_-35px_rgba(15,23,42,0.45)]">
            <form id="searchForm" class="grid gap-4 lg:grid-cols-[1.4fr_auto] lg:items-end">
                <div>
                    <label for="numFicheInput" class="block text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">Numéro de fiche</label>
                    <input id="numFicheInput" name="num_fiche" type="text" value="<?= htmlspecialchars($initialNum) ?>" placeholder="Ex: FD-2026-014 ou FD-2026-014-P1" class="mt-2 w-full rounded-2xl border border-slate-300 bg-slate-50 px-4 py-3 text-sm font-medium text-slate-900 outline-none transition focus:border-cyan-400 focus:bg-white focus:ring-4 focus:ring-cyan-100">
                </div>
                <button type="submit" class="inline-flex items-center justify-center gap-2 rounded-2xl bg-gradient-to-r from-cyan-600 to-indigo-600 px-5 py-3 text-sm font-semibold text-white shadow-lg shadow-cyan-900/20 transition hover:translate-y-[-1px] hover:from-cyan-700 hover:to-indigo-700">
                    <i class="fas fa-search"></i>
                    Charger la fiche
                </button>
            </form>
            <div id="statusBox" class="mt-4 hidden rounded-2xl border px-4 py-3 text-sm font-medium"></div>
        </section>

        <section class="mt-8 grid gap-6 xl:grid-cols-[0.95fr_1.05fr]">
            <div class="rounded-[28px] border border-slate-200/70 bg-white/95 p-6 shadow-[0_24px_55px_-35px_rgba(15,23,42,0.45)]">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-bold text-slate-900">Mode lot</h2>
                        <p class="mt-1 text-sm text-slate-500">Prévisualiser puis exécuter un repositionnement groupé sur plusieurs fiches.</p>
                    </div>
                    <div class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">Comparaison groupée</div>
                </div>
                <form id="batchForm" class="mt-5 space-y-4">
                    <div>
                        <label for="batchNumsInput" class="block text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">Numéros de fiches</label>
                        <textarea id="batchNumsInput" name="num_fiches" rows="7" placeholder="FD-2026-014&#10;FD-2026-015&#10;FD-2026-016" class="mt-2 w-full rounded-2xl border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-900 outline-none transition focus:border-cyan-400 focus:bg-white focus:ring-4 focus:ring-cyan-100"></textarea>
                        <p class="mt-2 text-xs text-slate-500">Séparez par ligne, espace, virgule ou point-virgule.</p>
                    </div>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label for="batchTargetStage" class="block text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">Étape cible lot</label>
                            <select id="batchTargetStage" name="batch_target_stage" class="mt-2 w-full rounded-2xl border border-slate-300 bg-slate-50 px-4 py-3 text-sm font-medium text-slate-900 outline-none transition focus:border-cyan-400 focus:bg-white focus:ring-4 focus:ring-cyan-100">
                                <option value="conformite">Conformité</option>
                                <option value="approbation">Approbation</option>
                                <option value="validation_achat">Validation achat</option>
                                <option value="tresorerie">Traitement comptable</option>
                                <option value="decaissee">Décaissée</option>
                            </select>
                        </div>
                        <div>
                            <label for="batchReason" class="block text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">Motif commun</label>
                            <textarea id="batchReason" name="batch_reason" rows="3" placeholder="Motif admin commun à ce lot" class="mt-2 w-full rounded-2xl border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-900 outline-none transition focus:border-cyan-400 focus:bg-white focus:ring-4 focus:ring-cyan-100"></textarea>
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-3">
                        <button id="batchPreviewButton" type="button" class="inline-flex items-center gap-2 rounded-2xl border border-slate-300 bg-white px-5 py-3 text-sm font-semibold text-slate-700 transition hover:border-cyan-300 hover:text-cyan-700">
                            <i class="fas fa-table"></i>
                            Prévisualiser le lot
                        </button>
                        <button id="batchExecuteButton" type="submit" class="inline-flex items-center gap-2 rounded-2xl bg-gradient-to-r from-cyan-600 to-indigo-600 px-5 py-3 text-sm font-semibold text-white shadow-lg shadow-cyan-900/20 transition hover:translate-y-[-1px] hover:from-cyan-700 hover:to-indigo-700">
                            <i class="fas fa-layer-group"></i>
                            Exécuter le lot
                        </button>
                    </div>
                </form>
                <div id="batchStatusBox" class="mt-4 hidden rounded-2xl border px-4 py-3 text-sm font-medium"></div>
            </div>

            <div id="batchCompareCard" class="rounded-[28px] border border-slate-200/70 bg-white/95 p-6 shadow-[0_24px_55px_-35px_rgba(15,23,42,0.45)]"></div>
        </section>

        <section id="historyCard" class="mt-8 rounded-[28px] border border-slate-200/70 bg-white/95 p-6 shadow-[0_24px_55px_-35px_rgba(15,23,42,0.45)]"></section>

        <section id="resultsSection" class="mt-8 hidden space-y-8">
            <div class="grid gap-6 xl:grid-cols-[1.2fr_0.8fr]">
                <div class="space-y-6">
                    <div id="summaryCard" class="rounded-[28px] border border-slate-200/70 bg-white/95 p-6 shadow-[0_24px_55px_-35px_rgba(15,23,42,0.42)]"></div>
                    <div id="familyCard" class="rounded-[28px] border border-slate-200/70 bg-white/95 p-6 shadow-[0_24px_55px_-35px_rgba(15,23,42,0.42)]"></div>
                </div>
                <div class="space-y-6">
                    <div class="rounded-[28px] border border-slate-200/70 bg-white/95 p-6 shadow-[0_24px_55px_-35px_rgba(15,23,42,0.42)]">
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <h2 class="text-lg font-bold text-slate-900">Repositionner l'étape</h2>
                                <p class="mt-1 text-sm text-slate-500">Ramène la fiche active à l'étape cible choisie.</p>
                            </div>
                            <div class="flex flex-wrap items-center justify-end gap-2">
                                <div id="currentStagePill" class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700"></div>
                                <div id="currentStatusPill" class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700"></div>
                            </div>
                        </div>
                        <form id="transitionForm" class="mt-5 space-y-4">
                            <div>
                                <label for="targetStage" class="block text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Étape cible</label>
                                <select id="targetStage" name="target_stage" class="mt-2 w-full rounded-2xl border border-slate-300 bg-slate-50 px-4 py-3 text-sm font-medium text-slate-900 outline-none transition focus:border-cyan-400 focus:bg-white focus:ring-4 focus:ring-cyan-100">
                                    <option value="conformite">Conformité</option>
                                    <option value="approbation">Approbation</option>
                                    <option value="validation_achat">Validation achat</option>
                                    <option value="tresorerie">Traitement comptable</option>
                                    <option value="decaissee">Décaissée</option>
                                </select>
                            </div>
                            <div>
                                <label for="transitionReason" class="block text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Motif admin</label>
                                <textarea id="transitionReason" name="reason" rows="4" placeholder="Expliquez pourquoi cette fiche est renvoyée à cette étape." class="mt-2 w-full rounded-2xl border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-900 outline-none transition focus:border-cyan-400 focus:bg-white focus:ring-4 focus:ring-cyan-100"></textarea>
                            </div>
                            <button type="submit" class="inline-flex w-full items-center justify-center gap-2 rounded-2xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-slate-800">
                                <i class="fas fa-route"></i>
                                Appliquer la transition admin
                            </button>
                        </form>
                    </div>

                    <div class="rounded-[28px] border border-slate-200/70 bg-white/95 p-6 shadow-[0_24px_55px_-35px_rgba(15,23,42,0.42)]">
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <h2 class="text-lg font-bold text-slate-900">Total vers partiel</h2>
                                <p class="mt-1 text-sm text-slate-500">Réduit la fiche décaissée et crée la fiche héritière de reliquat.</p>
                            </div>
                            <div id="partialEligibilityPill" class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700"></div>
                        </div>
                        <form id="partialForm" class="mt-5 space-y-4">
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label for="partialAmount" class="block text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Montant payé conservé</label>
                                    <input id="partialAmount" name="amount" type="number" min="0" step="0.01" class="mt-2 w-full rounded-2xl border border-slate-300 bg-slate-50 px-4 py-3 text-sm font-medium text-slate-900 outline-none transition focus:border-cyan-400 focus:bg-white focus:ring-4 focus:ring-cyan-100">
                                </div>
                                <div>
                                    <label for="partialDate" class="block text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Date de reprise du reliquat</label>
                                    <input id="partialDate" name="next_date" type="date" class="mt-2 w-full rounded-2xl border border-slate-300 bg-slate-50 px-4 py-3 text-sm font-medium text-slate-900 outline-none transition focus:border-cyan-400 focus:bg-white focus:ring-4 focus:ring-cyan-100">
                                </div>
                            </div>
                            <div>
                                <label for="partialReason" class="block text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Motif admin</label>
                                <textarea id="partialReason" name="reason" rows="4" placeholder="Décrivez la raison de la conversion en décaissement partiel." class="mt-2 w-full rounded-2xl border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-900 outline-none transition focus:border-cyan-400 focus:bg-white focus:ring-4 focus:ring-cyan-100"></textarea>
                            </div>
                            <div id="partialHint" class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
                                Cette action n'est disponible que pour une fiche déjà décaissée sans héritière existante.
                            </div>
                            <button id="partialSubmit" type="submit" class="inline-flex w-full items-center justify-center gap-2 rounded-2xl bg-gradient-to-r from-emerald-600 to-cyan-600 px-5 py-3 text-sm font-semibold text-white transition hover:translate-y-[-1px] hover:from-emerald-700 hover:to-cyan-700 disabled:cursor-not-allowed disabled:opacity-50">
                                <i class="fas fa-code-branch"></i>
                                Convertir en partiel
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div id="compareCard" class="rounded-[28px] border border-slate-200/70 bg-white/95 p-6 shadow-[0_24px_55px_-35px_rgba(15,23,42,0.42)]"></div>
            <div id="traceCard" class="rounded-[28px] border border-slate-200/70 bg-white/95 p-6 shadow-[0_24px_55px_-35px_rgba(15,23,42,0.42)]"></div>
        </section>
    </div>
</div>

<script>
    (() => {
        const initialNum = <?= json_encode($initialNum, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const statusBox = document.getElementById('statusBox');
        const resultsSection = document.getElementById('resultsSection');
        const searchForm = document.getElementById('searchForm');
        const batchForm = document.getElementById('batchForm');
        const transitionForm = document.getElementById('transitionForm');
        const partialForm = document.getElementById('partialForm');
        const numInput = document.getElementById('numFicheInput');
        const batchNumsInput = document.getElementById('batchNumsInput');
        const batchTargetStage = document.getElementById('batchTargetStage');
        const batchReason = document.getElementById('batchReason');
        const batchPreviewButton = document.getElementById('batchPreviewButton');
        const batchExecuteButton = document.getElementById('batchExecuteButton');
        const batchStatusBox = document.getElementById('batchStatusBox');
        const batchCompareCard = document.getElementById('batchCompareCard');
        const historyCard = document.getElementById('historyCard');
        const summaryCard = document.getElementById('summaryCard');
        const familyCard = document.getElementById('familyCard');
        const compareCard = document.getElementById('compareCard');
        const traceCard = document.getElementById('traceCard');
        const currentStagePill = document.getElementById('currentStagePill');
        const currentStatusPill = document.getElementById('currentStatusPill');
        const partialEligibilityPill = document.getElementById('partialEligibilityPill');
        const partialSubmit = document.getElementById('partialSubmit');
        const partialHint = document.getElementById('partialHint');
        const partialAmount = document.getElementById('partialAmount');
        const partialDate = document.getElementById('partialDate');

        let currentSnapshot = null;
        let lastComparison = null;
        let lastBatchResult = null;
        let historyItems = [];

        function escapeHtml(value) {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function currencyFormatCFA(value) {
            const amount = Number(value || 0);
            return new Intl.NumberFormat('fr-FR', {
                maximumFractionDigits: 0
            }).format(amount) + ' FCFA';
        }

        function formatDateTime(value) {
            if (!value) return '—';
            const normalized = String(value).replace(' ', 'T');
            const date = new Date(normalized);
            if (Number.isNaN(date.getTime())) return escapeHtml(value);
            return new Intl.DateTimeFormat('fr-FR', {
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            }).format(date);
        }

        function formatDate(value) {
            if (!value) return '—';
            const date = new Date(String(value) + 'T00:00:00');
            if (Number.isNaN(date.getTime())) return escapeHtml(value);
            return new Intl.DateTimeFormat('fr-FR', {
                year: 'numeric',
                month: '2-digit',
                day: '2-digit'
            }).format(date);
        }

        function stageBadge(stageCode, stageLabel) {
            const tones = {
                conformite: 'bg-slate-100 text-slate-700 border-slate-200',
                approbation: 'bg-amber-50 text-amber-700 border-amber-200',
                validation_achat: 'bg-cyan-50 text-cyan-700 border-cyan-200',
                tresorerie: 'bg-indigo-50 text-indigo-700 border-indigo-200',
                refusee: 'bg-rose-50 text-rose-700 border-rose-200',
                decaissee: 'bg-emerald-50 text-emerald-700 border-emerald-200'
            };
            const classes = tones[stageCode] || 'bg-slate-100 text-slate-700 border-slate-200';
            return `<span class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold ${classes}">${escapeHtml(stageLabel || stageCode)}</span>`;
        }

        function statusBadge(statusCode, statusLabel) {
            return stageBadge(statusCode, statusLabel);
        }

        function showStatus(message, tone = 'info') {
            const tones = {
                info: 'border-cyan-200 bg-cyan-50 text-cyan-800',
                success: 'border-emerald-200 bg-emerald-50 text-emerald-800',
                error: 'border-rose-200 bg-rose-50 text-rose-800'
            };
            statusBox.className = `mt-4 rounded-2xl border px-4 py-3 text-sm font-medium ${tones[tone] || tones.info}`;
            statusBox.textContent = message;
            statusBox.classList.remove('hidden');
        }

        function showBatchStatus(message, tone = 'info') {
            const tones = {
                info: 'border-cyan-200 bg-cyan-50 text-cyan-800',
                success: 'border-emerald-200 bg-emerald-50 text-emerald-800',
                error: 'border-rose-200 bg-rose-50 text-rose-800'
            };
            batchStatusBox.className = `mt-4 rounded-2xl border px-4 py-3 text-sm font-medium ${tones[tone] || tones.info}`;
            batchStatusBox.textContent = message;
            batchStatusBox.classList.remove('hidden');
        }

        async function postApi(payload) {
            const body = new URLSearchParams();
            Object.entries(payload).forEach(([key, value]) => {
                body.append(key, value == null ? '' : String(value));
            });
            const response = await fetch('workflow_fiches_admin_api.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: body.toString()
            });
            const json = await response.json().catch(() => ({}));
            if (!response.ok || !json.ok) {
                throw new Error(json.error || 'Erreur serveur');
            }
            return json;
        }

        function renderSummary(snapshot) {
            const current = snapshot.current;
            const warningHtml = (snapshot.warnings || []).map(w =>
                `<div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">${escapeHtml(w)}</div>`
            ).join('');

            summaryCard.innerHTML = `
            <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <div class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">Fiche active</div>
                    <div class="mt-2 flex flex-wrap items-center gap-3">
                        <h2 class="text-2xl font-black text-slate-900">${escapeHtml(current.num_fiche || '')}</h2>
                        ${stageBadge(current.workflow_stage_code, current.workflow_stage_label)}
                        ${statusBadge(current.workflow_status_code, current.workflow_status_label)}
                    </div>
                    <p class="mt-2 text-sm text-slate-500">Racine workflow: <span class="font-semibold text-slate-700">${escapeHtml(snapshot.family.root || current.num_fiche || '')}</span></p>
                </div>
                <div class="rounded-3xl bg-slate-900 px-5 py-4 text-white shadow-lg shadow-slate-900/15">
                    <div class="text-xs uppercase tracking-[0.2em] text-slate-300">Montant actuel</div>
                    <div class="mt-2 text-2xl font-black">${escapeHtml(currencyFormatCFA(current.montant_fiche || 0))}</div>
                </div>
            </div>
            <div class="mt-6 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                    <div class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">Position workflow</div>
                    <div class="mt-2 text-sm font-semibold text-slate-900">${escapeHtml(current.workflow_stage_label || '—')}</div>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                    <div class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">Statut réel</div>
                    <div class="mt-2 text-sm font-semibold text-slate-900">${escapeHtml(current.workflow_status_label || '—')}</div>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                    <div class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">Cible suggérée</div>
                    <div class="mt-2 text-sm font-semibold text-slate-900">${escapeHtml(current.suggested_target_stage_label || '—')}</div>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                    <div class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">Bénéficiaire</div>
                    <div class="mt-2 text-sm font-semibold text-slate-900">${escapeHtml(current.beficiaire_fiche || '—')}</div>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                    <div class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">Chantier</div>
                    <div class="mt-2 text-sm font-semibold text-slate-900">${escapeHtml(current.chantier_titre || current.chantier_code || '—')}</div>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                    <div class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">Ligne déboursé</div>
                    <div class="mt-2 text-sm font-semibold text-slate-900">${escapeHtml(current.ligne_designation || current.precision_fiche || '—')}</div>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 md:col-span-2 xl:col-span-3">
                    <div class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">Détail de demande</div>
                    <div class="mt-2 whitespace-pre-line text-sm leading-6 text-slate-700">${escapeHtml(current.details_demande || '—')}</div>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-slate-950 px-4 py-3 md:col-span-2 xl:col-span-3">
                    <div class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-400">Flags bruts</div>
                    <div class="mt-2 grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                        <div class="rounded-xl bg-slate-900 px-3 py-2 text-xs font-semibold text-slate-100">conforme = ${escapeHtml(String(current.conforme ?? '0'))}</div>
                        <div class="rounded-xl bg-slate-900 px-3 py-2 text-xs font-semibold text-slate-100">approuve = ${escapeHtml(String(current.approuve ?? '0'))}</div>
                        <div class="rounded-xl bg-slate-900 px-3 py-2 text-xs font-semibold text-slate-100">etat_fiche = ${escapeHtml(String(current.etat_fiche ?? '0'))}</div>
                        <div class="rounded-xl bg-slate-900 px-3 py-2 text-xs font-semibold text-slate-100">decaisse = ${escapeHtml(String(current.decaisse ?? '0'))}</div>
                    </div>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 md:col-span-2 xl:col-span-3">
                    <div class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">Motif de refus détecté</div>
                    <div class="mt-2 whitespace-pre-line text-sm leading-6 text-slate-700">${escapeHtml(current.refusal_reason || '—')}</div>
                </div>
            </div>
            ${warningHtml ? `<div class="mt-5 space-y-3">${warningHtml}</div>` : ''}
        `;

            currentStagePill.innerHTML = stageBadge(current.workflow_stage_code, current.workflow_stage_label);
            currentStatusPill.innerHTML = statusBadge(current.workflow_status_code, current.workflow_status_label);
        }

        function renderFamily(snapshot) {
            const rows = (snapshot.family.items || []).map(item => {
                const active = item.num_fiche === snapshot.current.num_fiche;
                const parent = item.parent_num_fiche || item.code_parentale || '—';
                return `
                <tr class="${active ? 'bg-cyan-50/80' : 'bg-white'}">
                    <td class="px-4 py-3 text-sm font-semibold text-slate-900">${escapeHtml(item.num_fiche || '')}</td>
                    <td class="px-4 py-3 text-sm text-slate-600">${escapeHtml(parent)}</td>
                    <td class="px-4 py-3 text-sm">${stageBadge(item.workflow_stage_code, item.workflow_stage_label)}</td>
                    <td class="px-4 py-3 text-sm font-semibold text-slate-900">${escapeHtml(currencyFormatCFA(item.montant_fiche || 0))}</td>
                    <td class="px-4 py-3 text-sm text-slate-600">${escapeHtml(formatDate(item.date_decaissement_minimum || ''))}</td>
                </tr>
            `;
            }).join('');

            familyCard.innerHTML = `
            <div class="flex items-center justify-between gap-4">
                <div>
                    <h2 class="text-lg font-bold text-slate-900">Famille workflow</h2>
                    <p class="mt-1 text-sm text-slate-500">Visualisation parent / héritières pour mesurer l'impact de l'action admin.</p>
                </div>
                <div class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">${escapeHtml(String((snapshot.family.items || []).length))} fiche(s)</div>
            </div>
            <div class="mt-5 overflow-x-auto rounded-3xl border border-slate-200">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-slate-50 text-left text-[11px] font-semibold uppercase tracking-[0.2em] text-slate-500">
                        <tr>
                            <th class="px-4 py-3">Fiche</th>
                            <th class="px-4 py-3">Parent</th>
                            <th class="px-4 py-3">Étape</th>
                            <th class="px-4 py-3">Montant</th>
                            <th class="px-4 py-3">Date min.</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">${rows || `<tr><td colspan="5" class="px-4 py-6 text-center text-sm text-slate-500">Aucune famille détectée.</td></tr>`}</tbody>
                </table>
            </div>
        `;
        }

        function renderTraces(snapshot) {
            const items = (snapshot.traces || []).map(trace => `
            <div class="rounded-3xl border border-slate-200 bg-slate-50/80 p-4">
                <div class="flex flex-col gap-2 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <div class="text-sm font-bold text-slate-900">${escapeHtml(trace.action || 'Action')}</div>
                        <div class="mt-1 text-xs uppercase tracking-[0.16em] text-slate-500">${escapeHtml(trace.num_fiche || '')}</div>
                    </div>
                    <div class="text-sm text-slate-600">${escapeHtml(formatDateTime(trace.date_action || ''))}</div>
                </div>
                <div class="mt-3 flex flex-wrap gap-3 text-xs text-slate-500">
                    <span class="rounded-full bg-white px-3 py-1 font-semibold text-slate-700">${escapeHtml(trace.utilisateur || 'Système')}</span>
                    ${trace.is_synthetic ? '<span class="rounded-full bg-white px-3 py-1 font-semibold text-amber-700">Événement reconstruit</span>' : ''}
                </div>
                ${trace.details ? `<div class="mt-3 whitespace-pre-line text-sm leading-6 text-slate-700">${escapeHtml(trace.details)}</div>` : ''}
            </div>
        `).join('');

            traceCard.innerHTML = `
            <div class="flex items-center justify-between gap-4">
                <div>
                    <h2 class="text-lg font-bold text-slate-900">Historique consolidé</h2>
                    <p class="mt-1 text-sm text-slate-500">Traces réelles et événements reconstruits du workflow.</p>
                </div>
                <div class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">${escapeHtml(String((snapshot.traces || []).length))} événement(s)</div>
            </div>
            <div class="mt-5 grid gap-4">${items || `<div class="rounded-3xl border border-slate-200 bg-slate-50 px-4 py-6 text-center text-sm text-slate-500">Aucune trace disponible.</div>`}</div>
        `;
        }

        function buildCompareFields(snapshot) {
            const current = snapshot?.current || {};
            return [{
                    key: 'workflow_stage_label',
                    label: 'Position workflow',
                    value: current.workflow_stage_label || '—'
                },
                {
                    key: 'workflow_status_label',
                    label: 'Statut réel',
                    value: current.workflow_status_label || '—'
                },
                {
                    key: 'suggested_target_stage_label',
                    label: 'Cible suggérée',
                    value: current.suggested_target_stage_label || '—'
                },
                {
                    key: 'montant_fiche',
                    label: 'Montant',
                    value: currencyFormatCFA(current.montant_fiche || 0)
                },
                {
                    key: 'conforme',
                    label: 'Flag conforme',
                    value: String(current.conforme ?? '0')
                },
                {
                    key: 'approuve',
                    label: 'Flag approuve',
                    value: String(current.approuve ?? '0')
                },
                {
                    key: 'etat_fiche',
                    label: 'Flag etat_fiche',
                    value: String(current.etat_fiche ?? '0')
                },
                {
                    key: 'decaisse',
                    label: 'Flag decaisse',
                    value: String(current.decaisse ?? '0')
                },
                {
                    key: 'date_conforme',
                    label: 'Date conformité',
                    value: formatDateTime(current.date_conforme || '')
                },
                {
                    key: 'date_approbation',
                    label: 'Date approbation',
                    value: formatDateTime(current.date_approbation || '')
                },
                {
                    key: 'date_valid',
                    label: 'Date validation achat',
                    value: formatDateTime(current.date_valid || '')
                },
                {
                    key: 'date_refuse',
                    label: 'Date refus',
                    value: formatDateTime(current.date_refuse || '')
                },
                {
                    key: 'date_decaisse',
                    label: 'Date décaissement',
                    value: formatDateTime(current.date_decaisse || '')
                },
                {
                    key: 'date_decaissement_minimum',
                    label: 'Date minimale décaissement',
                    value: formatDate(current.date_decaissement_minimum || '')
                },
                {
                    key: 'refusal_reason',
                    label: 'Motif de refus',
                    value: current.refusal_reason || '—'
                }
            ];
        }

        function renderComparison(comparison) {
            if (!comparison?.before || !comparison?.after) {
                compareCard.innerHTML = `
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-bold text-slate-900">Comparaison avant / après</h2>
                        <p class="mt-1 text-sm text-slate-500">Le différentiel apparaîtra ici après une transition admin ou une conversion total vers partiel.</p>
                    </div>
                    <div class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">En attente d'action</div>
                </div>`;
                return;
            }

            const beforeFields = buildCompareFields(comparison.before);
            const afterFields = buildCompareFields(comparison.after);
            const rows = afterFields.map((afterField, index) => {
                const beforeField = beforeFields[index] || {
                    value: '—'
                };
                const changed = beforeField.value !== afterField.value;
                return `
                <tr class="${changed ? 'bg-amber-50/70' : 'bg-white'}">
                    <td class="px-4 py-3 text-sm font-semibold text-slate-900">${escapeHtml(afterField.label)}</td>
                    <td class="px-4 py-3 text-sm text-slate-600">${escapeHtml(beforeField.value)}</td>
                    <td class="px-4 py-3 text-sm text-slate-900 ${changed ? 'font-bold' : ''}">${escapeHtml(afterField.value)}</td>
                    <td class="px-4 py-3 text-sm">${changed ? '<span class="inline-flex items-center rounded-full border border-amber-200 bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-800">Modifié</span>' : '<span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">Identique</span>'}</td>
                </tr>`;
            }).join('');
            const changesCount = afterFields.filter((afterField, index) => (beforeFields[index]?.value || '—') !== afterField.value).length;

            compareCard.innerHTML = `
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <h2 class="text-lg font-bold text-slate-900">Comparaison avant / après</h2>
                    <p class="mt-1 text-sm text-slate-500">${escapeHtml(comparison.actionLabel || 'Dernière action admin')} sur la fiche ${escapeHtml(comparison.after?.current?.num_fiche || '')}.</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">${escapeHtml(String(changesCount))} changement(s)</span>
                    ${statusBadge(comparison.after?.current?.workflow_status_code, comparison.after?.current?.workflow_status_label)}
                </div>
            </div>
            <div class="mt-5 grid gap-4 lg:grid-cols-2">
                <div class="rounded-3xl border border-slate-200 bg-slate-50/80 p-5">
                    <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Avant</div>
                    <div class="mt-3 flex flex-wrap gap-2">
                        ${stageBadge(comparison.before?.current?.workflow_stage_code, comparison.before?.current?.workflow_stage_label)}
                        ${statusBadge(comparison.before?.current?.workflow_status_code, comparison.before?.current?.workflow_status_label)}
                    </div>
                </div>
                <div class="rounded-3xl border border-slate-200 bg-slate-50/80 p-5">
                    <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Après</div>
                    <div class="mt-3 flex flex-wrap gap-2">
                        ${stageBadge(comparison.after?.current?.workflow_stage_code, comparison.after?.current?.workflow_stage_label)}
                        ${statusBadge(comparison.after?.current?.workflow_status_code, comparison.after?.current?.workflow_status_label)}
                    </div>
                </div>
            </div>
            <div class="mt-5 overflow-x-auto rounded-3xl border border-slate-200">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-slate-50 text-left text-[11px] font-semibold uppercase tracking-[0.2em] text-slate-500">
                        <tr>
                            <th class="px-4 py-3">Champ</th>
                            <th class="px-4 py-3">Avant</th>
                            <th class="px-4 py-3">Après</th>
                            <th class="px-4 py-3">Diff</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">${rows}</tbody>
                </table>
            </div>`;
        }

        function renderBatchComparison(payload) {
            if (!payload?.items?.length && !payload?.errors?.length) {
                batchCompareCard.innerHTML = `
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-bold text-slate-900">Avant / après groupé</h2>
                        <p class="mt-1 text-sm text-slate-500">Le comparatif lot apparaîtra ici après une prévisualisation ou une exécution.</p>
                    </div>
                    <div class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">Aucun lot chargé</div>
                </div>`;
                return;
            }

            const rows = (payload.items || []).map(item => {
                const before = item.before || {};
                const after = item.after || {};
                const changed = before.workflow_stage_label !== after.workflow_stage_label || before.workflow_status_label !== after.workflow_status_label || before.conforme !== after.conforme || before.approuve !== after.approuve || before.etat_fiche !== after.etat_fiche || before.decaisse !== after.decaisse;
                return `
                <tr class="${changed ? 'bg-amber-50/70' : 'bg-white'}">
                    <td class="px-4 py-3 text-sm font-semibold text-slate-900">${escapeHtml(item.num_fiche || '')}</td>
                    <td class="px-4 py-3 text-sm">${stageBadge(before.workflow_stage_code, before.workflow_stage_label)} ${statusBadge(before.workflow_status_code, before.workflow_status_label)}</td>
                    <td class="px-4 py-3 text-sm">${stageBadge(after.workflow_stage_code, after.workflow_stage_label)} ${statusBadge(after.workflow_status_code, after.workflow_status_label)}</td>
                    <td class="px-4 py-3 text-xs text-slate-700">c:${escapeHtml(String(before.conforme ?? '0'))} a:${escapeHtml(String(before.approuve ?? '0'))} e:${escapeHtml(String(before.etat_fiche ?? '0'))} d:${escapeHtml(String(before.decaisse ?? '0'))}</td>
                    <td class="px-4 py-3 text-xs text-slate-900 font-semibold">c:${escapeHtml(String(after.conforme ?? '0'))} a:${escapeHtml(String(after.approuve ?? '0'))} e:${escapeHtml(String(after.etat_fiche ?? '0'))} d:${escapeHtml(String(after.decaisse ?? '0'))}</td>
                </tr>`;
            }).join('');
            const errors = (payload.errors || []).map(item => `<div class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">${escapeHtml(item.num_fiche || 'Inconnu')} : ${escapeHtml(item.error || 'Erreur')}</div>`).join('');

            batchCompareCard.innerHTML = `
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <h2 class="text-lg font-bold text-slate-900">Avant / après groupé</h2>
                    <p class="mt-1 text-sm text-slate-500">Cible lot: ${escapeHtml(payload.target_stage_label || payload.target_stage || '—')}.</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">Demandées: ${escapeHtml(String(payload.summary?.requested ?? 0))}</span>
                    <span class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700">Prêtes / OK: ${escapeHtml(String((payload.summary?.ready ?? payload.summary?.success ?? 0)))}</span>
                    <span class="rounded-full bg-rose-100 px-3 py-1 text-xs font-semibold text-rose-700">Erreurs: ${escapeHtml(String(payload.summary?.errors ?? 0))}</span>
                </div>
            </div>
            <div class="mt-5 overflow-x-auto rounded-3xl border border-slate-200">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-slate-50 text-left text-[11px] font-semibold uppercase tracking-[0.2em] text-slate-500">
                        <tr>
                            <th class="px-4 py-3">Fiche</th>
                            <th class="px-4 py-3">Avant</th>
                            <th class="px-4 py-3">Après</th>
                            <th class="px-4 py-3">Flags avant</th>
                            <th class="px-4 py-3">Flags après</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">${rows || `<tr><td colspan="5" class="px-4 py-6 text-center text-sm text-slate-500">Aucune fiche exploitable.</td></tr>`}</tbody>
                </table>
            </div>
            ${errors ? `<div class="mt-4 grid gap-3">${errors}</div>` : ''}`;
        }

        function renderHistory(items) {
            historyItems = Array.isArray(items) ? items : [];
            const classificationBadge = (code, label) => {
                const tones = {
                    success: 'border-emerald-200 bg-emerald-50 text-emerald-700',
                    real_error: 'border-rose-200 bg-rose-50 text-rose-700',
                    reread_gap: 'border-amber-200 bg-amber-50 text-amber-800'
                };
                const classes = tones[code] || 'border-slate-200 bg-slate-100 text-slate-700';
                return `<span class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold ${classes}">${escapeHtml(label || code || '—')}</span>`;
            };

            const rows = (items || []).map(item => {
                const details = item.details || {};
                const target = details.target_stage_label || details.next_date || '—';
                const requested = details.requested ?? details.amount_kept ?? '—';
                const success = details.observed_success_count ?? details.success_count ?? '—';
                const realErrors = details.observed_real_error_count ?? null;
                const rereadGaps = details.observed_reread_gap_count ?? null;
                const errors = details.observed_error_count ?? details.error_count ?? '—';
                const countsHint = details.observed_success_count != null ? '<div class="mt-1 text-[11px] text-slate-500">Comptage constaté sur l’état réel des fiches</div>' : '';
                const observedItems = Array.isArray(details.observed_items) ? details.observed_items : [];
                const detailRows = observedItems.map(observed => `
                    <tr>
                        <td class="px-4 py-3 text-sm font-semibold text-slate-900">${escapeHtml(observed.num_fiche || '—')}</td>
                        <td class="px-4 py-3 text-sm text-slate-700">${stageBadge(observed.current_stage_code, observed.current_stage_label || 'Introuvable')}</td>
                        <td class="px-4 py-3 text-sm text-slate-700">${statusBadge(observed.current_status_code, observed.current_status_label || '—')}</td>
                        <td class="px-4 py-3 text-sm text-slate-700">${classificationBadge(observed.classification, observed.classification_label)}</td>
                        <td class="px-4 py-3 text-sm text-slate-700">${escapeHtml(observed.logged_error || observed.observation_error || '—')}</td>
                    </tr>
                `).join('');
                const detailPanel = observedItems.length ? `
                    <details class="mt-3 rounded-2xl border border-slate-200 bg-slate-50/70 p-4">
                        <summary class="cursor-pointer list-none text-sm font-semibold text-slate-800">
                            Voir le détail du lot
                        </summary>
                        <div class="mt-3 flex flex-wrap gap-2 text-xs">
                            ${classificationBadge('success', 'Succès constaté: ' + String(success))}
                            ${realErrors != null ? classificationBadge('real_error', 'Erreurs réelles: ' + String(realErrors)) : ''}
                            ${rereadGaps != null ? classificationBadge('reread_gap', 'Écarts de relecture: ' + String(rereadGaps)) : ''}
                        </div>
                        <div class="mt-4 overflow-x-auto rounded-2xl border border-slate-200 bg-white">
                            <table class="min-w-full divide-y divide-slate-200">
                                <thead class="bg-slate-50 text-left text-[11px] font-semibold uppercase tracking-[0.2em] text-slate-500">
                                    <tr>
                                        <th class="px-4 py-3">Fiche</th>
                                        <th class="px-4 py-3">Étape actuelle</th>
                                        <th class="px-4 py-3">Statut actuel</th>
                                        <th class="px-4 py-3">Diagnostic</th>
                                        <th class="px-4 py-3">Message</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-200">${detailRows}</tbody>
                            </table>
                        </div>
                    </details>
                ` : '';
                const errorCell = realErrors != null || rereadGaps != null ?
                    `<div class="space-y-1"><div>${escapeHtml(String(errors))}</div><div class="text-[11px] text-slate-500">Réelles: ${escapeHtml(String(realErrors ?? 0))} | Relecture: ${escapeHtml(String(rereadGaps ?? 0))}</div></div>` :
                    escapeHtml(String(errors));
                const refreshButton = item.event_type === 'admin_workflow_batch_transition' ?
                    `<button type="button" data-history-refresh-id="${escapeHtml(String(item.id || ''))}" class="inline-flex items-center gap-2 rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 transition hover:border-cyan-300 hover:text-cyan-700"><i class="fas fa-rotate-right"></i>Recharger le diagnostic</button>` :
                    '—';
                return `
                <tr>
                    <td class="px-4 py-3 text-sm font-semibold text-slate-900">${escapeHtml(item.created_at || '—')}</td>
                    <td class="px-4 py-3 text-sm text-slate-700">${escapeHtml(item.entity_label || item.action || '—')}</td>
                    <td class="px-4 py-3 text-sm text-slate-700">${escapeHtml(item.user_name || 'ADMIN')}</td>
                    <td class="px-4 py-3 text-sm text-slate-700">${escapeHtml(String(target))}</td>
                    <td class="px-4 py-3 text-sm text-slate-700">${escapeHtml(String(requested))}</td>
                    <td class="px-4 py-3 text-sm text-slate-700">${escapeHtml(String(success))}${countsHint}</td>
                    <td class="px-4 py-3 text-sm text-slate-700">${errorCell}</td>
                    <td class="px-4 py-3 text-sm text-slate-700">${refreshButton}</td>
                </tr>
                ${detailPanel ? `<tr><td colspan="8" class="px-4 pb-4">${detailPanel}</td></tr>` : ''}`;
            }).join('');

            historyCard.innerHTML = `
            <div class="flex items-center justify-between gap-4">
                <div>
                    <h2 class="text-lg font-bold text-slate-900">Historique persistant admin</h2>
                    <p class="mt-1 text-sm text-slate-500">Historique stocké dans AuditTrail, en complément des traces par fiche. Les lignes de lot sont dépliables avec diagnostic par fiche.</p>
                </div>
                <div class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">${escapeHtml(String((items || []).length))} entrée(s)</div>
            </div>
            <div class="mt-5 overflow-x-auto rounded-3xl border border-slate-200">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-slate-50 text-left text-[11px] font-semibold uppercase tracking-[0.2em] text-slate-500">
                        <tr>
                            <th class="px-4 py-3">Date</th>
                            <th class="px-4 py-3">Action</th>
                            <th class="px-4 py-3">Utilisateur</th>
                            <th class="px-4 py-3">Cible</th>
                            <th class="px-4 py-3">Demandées</th>
                            <th class="px-4 py-3">Succès</th>
                            <th class="px-4 py-3">Erreurs</th>
                            <th class="px-4 py-3">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">${rows || `<tr><td colspan="8" class="px-4 py-6 text-center text-sm text-slate-500">Aucun historique pour le moment.</td></tr>`}</tbody>
                </table>
            </div>`;
        }

        async function refreshHistoryItem(historyId, triggerButton) {
            const id = Number(historyId || 0);
            if (!id) {
                return;
            }

            const button = triggerButton || historyCard.querySelector(`[data-history-refresh-id="${String(id)}"]`);
            const previousHtml = button ? button.innerHTML : '';
            if (button) {
                button.disabled = true;
                button.innerHTML = '<i class="fas fa-spinner fa-spin"></i>Rechargement...';
            }

            try {
                const json = await postApi({
                    action: 'history_refresh_item',
                    history_id: id,
                    num_fiche: '__history_item__'
                });
                historyItems = historyItems.map(item => item.id === id ? (json.item || item) : item);
                renderHistory(historyItems);
            } catch (error) {
                showBatchStatus(error.message || 'Impossible de recharger le diagnostic.', 'error');
                if (button) {
                    button.disabled = false;
                    button.innerHTML = previousHtml;
                }
            }
        }

        async function loadHistory() {
            try {
                const json = await postApi({
                    action: 'history',
                    limit: 20,
                    num_fiche: '__history__'
                });
                renderHistory(json.history || []);
            } catch (error) {
                renderHistory([]);
            }
        }

        function refreshActionState(snapshot) {
            currentSnapshot = snapshot;
            const current = snapshot.current || {};
            partialAmount.value = '';
            partialDate.value = current.recommended_next_date || '';

            const eligible = Boolean(current.can_partialize);
            partialSubmit.disabled = !eligible;
            partialEligibilityPill.innerHTML = eligible ?
                '<span class="inline-flex items-center rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700">Action disponible</span>' :
                '<span class="inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">Action indisponible</span>';
            partialHint.className = `rounded-2xl border px-4 py-3 text-sm ${eligible ? 'border-emerald-200 bg-emerald-50 text-emerald-900' : 'border-slate-200 bg-slate-50 text-slate-600'}`;
            partialHint.textContent = eligible ?
                'Le reliquat sera créé comme fiche héritière et repassera en validation achat.' :
                (current.partialize_error || 'Cette fiche n\'est pas éligible à une conversion total vers partiel.');

            const targetStage = current.suggested_target_stage || current.workflow_stage_code || 'conformite';
            document.getElementById('targetStage').value = targetStage;
        }

        function renderSnapshot(snapshot, options = {}) {
            renderSummary(snapshot);
            renderFamily(snapshot);
            if (options.comparison) {
                lastComparison = options.comparison;
            }
            renderComparison(lastComparison);
            renderTraces(snapshot);
            refreshActionState(snapshot);
            resultsSection.classList.remove('hidden');
        }

        async function loadSnapshot(num, options = {}) {
            const ficheNum = String(num || '').trim();
            if (!ficheNum) {
                showStatus('Saisissez un numéro de fiche.', 'error');
                resultsSection.classList.add('hidden');
                return;
            }
            showStatus('Chargement de la fiche en cours...', 'info');
            lastComparison = null;
            const json = await postApi({
                action: 'snapshot',
                num_fiche: ficheNum
            });
            renderSnapshot(json.snapshot);
            showStatus(json.message || 'Fiche chargée.', 'success');
            if (options.pushState !== false) {
                const url = new URL(window.location.href);
                url.searchParams.set('num_fiche', ficheNum);
                window.history.replaceState({}, '', url.toString());
            }
        }

        searchForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            try {
                await loadSnapshot(numInput.value);
            } catch (error) {
                showStatus(error.message || 'Impossible de charger la fiche.', 'error');
                resultsSection.classList.add('hidden');
            }
        });

        transitionForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (!currentSnapshot?.current?.num_fiche) {
                showStatus('Chargez d\'abord une fiche.', 'error');
                return;
            }
            try {
                showStatus('Transition admin en cours...', 'info');
                const beforeSnapshot = JSON.parse(JSON.stringify(currentSnapshot));
                const json = await postApi({
                    action: 'transition',
                    num_fiche: currentSnapshot.current.num_fiche,
                    target_stage: document.getElementById('targetStage').value,
                    reason: document.getElementById('transitionReason').value
                });
                renderSnapshot(json.snapshot, {
                    comparison: {
                        before: beforeSnapshot,
                        after: json.snapshot,
                        actionLabel: 'Repositionnement workflow admin'
                    }
                });
                showStatus(json.message || 'Transition appliquée.', 'success');
            } catch (error) {
                showStatus(error.message || 'Transition impossible.', 'error');
            }
        });

        partialForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (!currentSnapshot?.current?.num_fiche) {
                showStatus('Chargez d\'abord une fiche.', 'error');
                return;
            }
            try {
                showStatus('Conversion total vers partiel en cours...', 'info');
                const beforeSnapshot = JSON.parse(JSON.stringify(currentSnapshot));
                const json = await postApi({
                    action: 'partialize',
                    num_fiche: currentSnapshot.current.num_fiche,
                    amount: partialAmount.value,
                    next_date: partialDate.value,
                    reason: document.getElementById('partialReason').value
                });
                renderSnapshot(json.snapshot, {
                    comparison: {
                        before: beforeSnapshot,
                        after: json.snapshot,
                        actionLabel: 'Conversion total vers partiel'
                    }
                });
                showStatus(json.message || 'Conversion en partiel réalisée.', 'success');
            } catch (error) {
                showStatus(error.message || 'Conversion en partiel impossible.', 'error');
            }
        });

        if (initialNum) {
            loadSnapshot(initialNum, {
                pushState: false
            }).catch((error) => {
                showStatus(error.message || 'Impossible de charger la fiche initiale.', 'error');
            });
        }

        batchPreviewButton.addEventListener('click', async () => {
            try {
                showBatchStatus('Prévisualisation du lot en cours...', 'info');
                const json = await postApi({
                    action: 'batch_preview',
                    num_fiche: '__batch__',
                    num_fiches: batchNumsInput.value,
                    target_stage: batchTargetStage.value
                });
                lastBatchResult = json.preview || null;
                renderBatchComparison(lastBatchResult);
                renderHistory(json.history || []);
                showBatchStatus('Prévisualisation groupée générée.', 'success');
            } catch (error) {
                showBatchStatus(error.message || 'Prévisualisation lot impossible.', 'error');
            }
        });

        batchForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            try {
                showBatchStatus('Exécution du lot en cours...', 'info');
                const json = await postApi({
                    action: 'batch_transition',
                    num_fiche: '__batch__',
                    num_fiches: batchNumsInput.value,
                    target_stage: batchTargetStage.value,
                    reason: batchReason.value
                });
                lastBatchResult = json.batch || null;
                renderBatchComparison(lastBatchResult);
                renderHistory(json.history || []);
                showBatchStatus(json.message || 'Lot exécuté.', (json.batch?.summary?.errors || 0) > 0 ? 'info' : 'success');
                if (currentSnapshot?.current?.num_fiche) {
                    const nums = String(batchNumsInput.value || '').split(/[\s,;]+/).filter(Boolean);
                    if (nums.includes(currentSnapshot.current.num_fiche)) {
                        await loadSnapshot(currentSnapshot.current.num_fiche);
                    }
                }
            } catch (error) {
                showBatchStatus(error.message || 'Exécution lot impossible.', 'error');
            }
        });

        historyCard.addEventListener('click', (event) => {
            const button = event.target.closest('[data-history-refresh-id]');
            if (!button) {
                return;
            }
            event.preventDefault();
            refreshHistoryItem(button.getAttribute('data-history-refresh-id'), button);
        });

        renderBatchComparison(null);
        loadHistory();
    })();
</script>

<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>
