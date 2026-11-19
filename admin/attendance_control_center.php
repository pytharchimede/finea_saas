<?php
require_once __DIR__ . '/../auth_check.php';
AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../model/AttendanceSyncService.php';
require_once __DIR__ . '/../decaissement/model/PersonnelDirectory.php';

$pdo = Database::getConnection();
$service = new AttendanceSyncService($pdo);
$directory = new PersonnelDirectory($pdo);

$pageTitle = 'Centre de contrôle Pointage (Hikvision)';
$activeMenu = '';

$search = trim((string)($_GET['q'] ?? ''));
$personnelChoices = $directory->getPersonnelChoices($search, 200);

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>
<div class="min-h-screen bg-gradient-to-br from-slate-50 via-white to-emerald-50">
    <div class="container mx-auto py-10 px-4">
        <div class="mb-10 rounded-[28px] border border-emerald-100 bg-gradient-to-r from-slate-950 via-slate-900 to-emerald-900 px-8 py-8 text-white shadow-[0_24px_80px_rgba(15,23,42,0.28)]">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.35em] text-emerald-200">Administration</p>
                    <h1 class="mt-2 text-3xl font-semibold tracking-tight md:text-5xl">Centre de contrôle — Pointage Hikvision</h1>
                    <p class="mt-4 max-w-3xl text-sm text-slate-200 md:text-base">
                        Suivi de la connexion API, synchronisation des pointages et liaison Personnel RH ↔ Identifiant badgeuse.
                    </p>
                </div>
                <div class="flex flex-wrap gap-3">
                    <button id="btn-sync" class="inline-flex items-center rounded-2xl bg-emerald-500 px-5 py-3 text-sm font-semibold text-white shadow-lg shadow-emerald-600/20 transition hover:bg-emerald-600">
                        <i class="fas fa-cloud-download-alt mr-2"></i>
                        Synchroniser maintenant (aujourd'hui)
                    </button>
                    <a href="dashboard_admin.php" class="inline-flex items-center rounded-2xl bg-white px-5 py-3 text-sm font-semibold text-slate-900 transition hover:bg-emerald-50">
                        <i class="fas fa-arrow-left mr-2 text-emerald-600"></i>
                        Retour admin
                    </a>
                </div>
            </div>
        </div>

        <div class="grid gap-6 xl:grid-cols-3">
            <section class="rounded-[28px] border border-white/80 bg-white/90 p-6 shadow-[0_18px_60px_rgba(15,23,42,0.06)]">
                <p class="text-xs font-semibold uppercase tracking-[0.28em] text-emerald-600">Connexion</p>
                <h2 class="mt-2 text-2xl font-semibold text-slate-950">API Attendance</h2>
                <p class="mt-3 text-sm text-slate-600">Base URL</p>
                <div class="mt-2 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-800">
                    <?= htmlspecialchars($service->getBaseUrl()) ?>
                </div>

                <div class="mt-4 flex flex-wrap gap-3">
                    <button id="btn-health" class="inline-flex items-center rounded-2xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-900 transition hover:bg-slate-50">
                        <i class="fas fa-heartbeat mr-2 text-rose-600"></i>
                        Tester la connexion
                    </button>
                    <div id="health-status" class="text-sm font-semibold text-slate-600"></div>
                </div>

                <div id="health-details" class="mt-4 hidden rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800"></div>
                <div id="health-error" class="mt-4 hidden rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700"></div>
            </section>

            <section class="rounded-[28px] border border-white/80 bg-white/90 p-6 shadow-[0_18px_60px_rgba(15,23,42,0.06)] xl:col-span-2">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.28em] text-indigo-600">Logs</p>
                        <h2 class="mt-2 text-2xl font-semibold text-slate-950">Dernières synchronisations</h2>
                    </div>
                    <div class="text-xs font-semibold text-slate-500">
                        Auto-refresh: 5s • Serveur: <span id="server-time">...</span>
                        <span class="mx-2 text-slate-300">•</span>
                        Dernière synchro: <span id="last-sync">...</span>
                        <span class="mx-2 text-slate-300">•</span>
                        Prochaine synchro: <span id="next-sync">...</span>
                    </div>
                </div>
                <div class="mt-2 flex flex-col gap-2 text-xs font-semibold text-slate-500 sm:flex-row sm:items-center">
                    <div>
                        Dernier pointage importé: <span id="latest-event">...</span>
                        <span id="latest-event-badge" class="ml-2 inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-[11px] font-semibold text-slate-700">...</span>
                    </div>
                    <div class="sm:ml-auto">Dernière nouvelle donnée: <span id="last-data">...</span></div>
                </div>
                <div class="mt-5 overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead class="bg-slate-50 text-slate-500">
                            <tr>
                                <th class="px-3 py-3 font-medium">#</th>
                                <th class="px-3 py-3 font-medium">Scope</th>
                                <th class="px-3 py-3 font-medium">Statut</th>
                                <th class="px-3 py-3 font-medium">Début</th>
                                <th class="px-3 py-3 font-medium">Fin</th>
                                <th class="px-3 py-3 font-medium">Lues</th>
                                <th class="px-3 py-3 font-medium">Insérées</th>
                                <th class="px-3 py-3 font-medium">Erreur</th>
                            </tr>
                        </thead>
                        <tbody id="runs-tbody" class="divide-y divide-slate-100"></tbody>
                    </table>
                </div>
            </section>
        </div>

        <div class="mt-8 grid gap-6 xl:grid-cols-2">
            <section class="rounded-[30px] border border-white/80 bg-white/90 p-7 shadow-[0_18px_60px_rgba(15,23,42,0.07)] backdrop-blur">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.28em] text-cyan-600">Pointages</p>
                        <h2 class="mt-2 text-2xl font-semibold text-slate-950">Derniers événements importés</h2>
                    </div>
                </div>

                <div class="mt-5 overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead class="bg-slate-50 text-slate-500">
                            <tr>
                                <th class="px-3 py-3 font-medium">Date/heure</th>
                                <th class="px-3 py-3 font-medium">Employé</th>
                                <th class="px-3 py-3 font-medium">Code</th>
                                <th class="px-3 py-3 font-medium">Événement</th>
                            </tr>
                        </thead>
                        <tbody id="events-tbody" class="divide-y divide-slate-100"></tbody>
                    </table>
                </div>
            </section>

            <section class="rounded-[30px] border border-white/80 bg-white/90 p-7 shadow-[0_18px_60px_rgba(15,23,42,0.07)] backdrop-blur">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.28em] text-fuchsia-600">Supervision</p>
                        <h2 class="mt-2 text-2xl font-semibold text-slate-950">Collecteurs (PC) & tâches</h2>
                        <p class="mt-2 text-sm text-slate-600">
                            Données lues depuis le centre de contrôle de <span class="font-semibold">attendance.fidest.ci</span>.
                        </p>
                    </div>
                    <div class="text-xs font-semibold text-slate-500">Fréquence attendue: <span id="remote-expected">...</span></div>
                </div>

                <div id="remote-error" class="mt-4 hidden rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700"></div>

                <div class="mt-5 overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead class="bg-slate-50 text-slate-500">
                            <tr>
                                <th class="px-3 py-3 font-medium">Collecteur</th>
                                <th class="px-3 py-3 font-medium">Dernier envoi</th>
                                <th class="px-3 py-3 font-medium">Statut</th>
                                <th class="px-3 py-3 font-medium">Manqués (estim.)</th>
                            </tr>
                        </thead>
                        <tbody id="collectors-tbody" class="divide-y divide-slate-100"></tbody>
                    </table>
                </div>

                <div class="mt-6 overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead class="bg-slate-50 text-slate-500">
                            <tr>
                                <th class="px-3 py-3 font-medium">Tâche / envoi</th>
                                <th class="px-3 py-3 font-medium">Collecteur</th>
                                <th class="px-3 py-3 font-medium">Début</th>
                                <th class="px-3 py-3 font-medium">Fin</th>
                                <th class="px-3 py-3 font-medium">Statut</th>
                            </tr>
                        </thead>
                        <tbody id="remote-runs-tbody" class="divide-y divide-slate-100"></tbody>
                    </table>
                </div>
            </section>

            <section class="rounded-[30px] border border-white/80 bg-white/90 p-7 shadow-[0_18px_60px_rgba(15,23,42,0.07)] backdrop-blur">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.28em] text-emerald-600">Liaison</p>
                    <h2 class="mt-2 text-2xl font-semibold text-slate-950">Personnel RH ↔ Badgeuse</h2>
                    <p class="mt-3 text-sm text-slate-600">
                        Recherchez un collaborateur, puis renseignez son <span class="font-semibold">employee_code</span> Hikvision (code badgeuse).
                    </p>
                </div>

                <form method="get" class="mt-5 flex flex-col gap-3 sm:flex-row sm:items-end">
                    <div class="flex-1">
                        <label class="mb-2 block text-sm font-medium text-slate-700">Recherche personnel</label>
                        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Nom, email, matricule, service..." class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-emerald-400 focus:bg-white">
                    </div>
                    <button type="submit" class="inline-flex items-center rounded-2xl bg-slate-950 px-5 py-3 text-sm font-semibold text-white transition hover:bg-slate-800">
                        <i class="fas fa-search mr-2"></i>
                        Chercher
                    </button>
                </form>

                <div class="mt-5 overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead class="bg-slate-50 text-slate-500">
                            <tr>
                                <th class="px-3 py-3 font-medium">Personnel</th>
                                <th class="px-3 py-3 font-medium">Service</th>
                                <th class="px-3 py-3 font-medium">Statut</th>
                                <th class="px-3 py-3 font-medium">employee_code</th>
                                <th class="px-3 py-3 font-medium">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php if (empty($personnelChoices)): ?>
                                <tr>
                                    <td colspan="5" class="px-3 py-6 text-center text-sm text-slate-500">Aucun résultat.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($personnelChoices as $p): ?>
                                    <?php
                                    $pid = (int)($p['id'] ?? 0);
                                    $link = $pid > 0 ? $service->getLinkForPersonnel($pid) : null;
                                    $currentCode = (string)($link['employee_code'] ?? '');
                                    ?>
                                    <tr>
                                        <td class="px-3 py-3">
                                            <div class="font-semibold text-slate-900"><?= htmlspecialchars((string)($p['full_name'] ?? '')) ?></div>
                                            <div class="text-xs text-slate-500">ID #<?= (int)$pid ?><?= !empty($p['matricule']) ? (' • ' . htmlspecialchars((string)$p['matricule'])) : '' ?></div>
                                        </td>
                                        <td class="px-3 py-3 text-slate-700"><?= htmlspecialchars((string)($p['service_label'] ?? '')) ?></td>
                                        <td class="px-3 py-3">
                                            <?php if (!empty($p['is_active'])): ?>
                                                <span class="inline-flex items-center rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700">En poste</span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">Sortie</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-3 py-3">
                                            <input type="text" value="<?= htmlspecialchars($currentCode) ?>" data-personnel-id="<?= (int)$pid ?>" class="employee-code-input w-40 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-emerald-400" placeholder="ex: 45">
                                        </td>
                                        <td class="px-3 py-3">
                                            <div class="flex flex-wrap gap-2">
                                                <button type="button" class="btn-link inline-flex items-center rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700" data-personnel-id="<?= (int)$pid ?>">
                                                    Enregistrer
                                                </button>
                                                <button type="button" class="btn-unlink inline-flex items-center rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-900 hover:bg-slate-50" data-personnel-id="<?= (int)$pid ?>">
                                                    Délier
                                                </button>
                                                <span class="link-msg text-xs font-semibold text-slate-500" data-personnel-id="<?= (int)$pid ?>"></span>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>
</div>

<script>
    (function() {
        const apiUrl = 'attendance_control_center_api.php';

        const elRuns = document.getElementById('runs-tbody');
        const elEvents = document.getElementById('events-tbody');
        const elServerTime = document.getElementById('server-time');
        const elLastSync = document.getElementById('last-sync');
        const elNextSync = document.getElementById('next-sync');
        const elLatestEvent = document.getElementById('latest-event');
        const elLatestEventBadge = document.getElementById('latest-event-badge');
        const elLastData = document.getElementById('last-data');

        const elRemoteExpected = document.getElementById('remote-expected');
        const elRemoteError = document.getElementById('remote-error');
        const elCollectors = document.getElementById('collectors-tbody');
        const elRemoteRuns = document.getElementById('remote-runs-tbody');

        const elHealthStatus = document.getElementById('health-status');
        const elHealthDetails = document.getElementById('health-details');
        const elHealthError = document.getElementById('health-error');

        function esc(s) {
            return String(s ?? '').replace(/[&<>"']/g, c => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                '\'': '&#39;'
            } [c]));
        }

        async function postForm(action, data) {
            const form = new URLSearchParams();
            for (const [k, v] of Object.entries(data || {})) {
                form.set(k, v);
            }

            const res = await fetch(apiUrl + '?action=' + encodeURIComponent(action), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: form.toString(),
            });

            return await res.json();
        }

        function renderRuns(runs) {
            if (!Array.isArray(runs) || runs.length === 0) {
                elRuns.innerHTML = '<tr><td colspan="8" class="px-3 py-6 text-center text-sm text-slate-500">Aucun log.</td></tr>';
                return;
            }

            elRuns.innerHTML = runs.map(r => {
                const status = String(r.status || '');
                const badge = status === 'success' ?
                    '<span class="inline-flex items-center rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700">success</span>' :
                    status === 'running' ?
                    '<span class="inline-flex items-center rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-700">running</span>' :
                    '<span class="inline-flex items-center rounded-full bg-rose-100 px-3 py-1 text-xs font-semibold text-rose-700">error</span>';

                return `<tr>
                <td class="px-3 py-3 font-semibold">${esc(r.id)}</td>
                <td class="px-3 py-3">${esc(r.scope)}</td>
                <td class="px-3 py-3">${badge}</td>
                <td class="px-3 py-3 text-slate-600">${esc(r.started_at)}</td>
                <td class="px-3 py-3 text-slate-600">${esc(r.finished_at || '')}</td>
                <td class="px-3 py-3 font-semibold">${esc(r.fetched_count)}</td>
                <td class="px-3 py-3 font-semibold">${esc(r.inserted_count)}</td>
                <td class="px-3 py-3 text-rose-700">${esc(r.error_message || '')}</td>
            </tr>`;
            }).join('');
        }

        function renderEvents(events) {
            if (!Array.isArray(events) || events.length === 0) {
                elEvents.innerHTML = '<tr><td colspan="4" class="px-3 py-6 text-center text-sm text-slate-500">Aucun événement.</td></tr>';
                return;
            }

            elEvents.innerHTML = events.map(e => {
                return `<tr>
                <td class="px-3 py-3 text-slate-700">${esc(e.occurred_at)}</td>
                <td class="px-3 py-3 font-semibold text-slate-900">${esc(e.employee_name || '')}</td>
                <td class="px-3 py-3 font-semibold">${esc(e.employee_code)}</td>
                <td class="px-3 py-3 text-slate-700">${esc(e.event_label || '')} <span class="text-xs text-slate-500">${esc(e.event_code || '')}</span></td>
            </tr>`;
            }).join('');
        }

        function renderHealth(payload) {
            elHealthDetails.classList.add('hidden');
            elHealthError.classList.add('hidden');

            if (payload.health_error) {
                elHealthStatus.textContent = 'KO';
                elHealthError.textContent = payload.health_error;
                elHealthError.classList.remove('hidden');
                return;
            }

            if (payload.health && payload.health.ok) {
                elHealthStatus.textContent = 'OK';
                elHealthDetails.textContent = 'Service: ' + (payload.health.service || 'attendance-api') + ' • ' + (payload.health.timestamp || '');
                elHealthDetails.classList.remove('hidden');
                return;
            }

            elHealthStatus.textContent = 'Inconnu';
        }

        function formatAge(seconds) {
            if (typeof seconds !== 'number' || !isFinite(seconds) || seconds < 0) {
                return '';
            }
            const m = Math.floor(seconds / 60);
            const s = seconds % 60;
            if (m <= 0) {
                return s + 's';
            }
            if (m < 60) {
                return m + 'min';
            }
            const h = Math.floor(m / 60);
            const rm = m % 60;
            return h + 'h' + (rm ? (String(rm).padStart(2, '0')) : '00');
        }

        function renderIndicators(payload) {
            const lastSuccess = payload && payload.last_success_run ? payload.last_success_run : null;
            const nextExpected = payload && payload.next_expected_sync_at ? String(payload.next_expected_sync_at) : '';
            const lastData = payload && payload.last_data_run ? payload.last_data_run : null;
            const latest = payload && payload.latest_event ? payload.latest_event : null;

            elLastSync.textContent = lastSuccess && lastSuccess.finished_at ? String(lastSuccess.finished_at) : '—';
            elNextSync.textContent = nextExpected || '—';

            if (latest && latest.occurred_at) {
                const labelParts = [String(latest.occurred_at)];
                if (latest.employee_name || latest.employee_code) {
                    labelParts.push((latest.employee_name ? String(latest.employee_name) : '') + (latest.employee_code ? (' #' + String(latest.employee_code)) : ''));
                }
                elLatestEvent.textContent = labelParts.filter(Boolean).join(' • ');
            } else {
                elLatestEvent.textContent = '—';
            }

            const age = payload && typeof payload.latest_event_age_seconds === 'number' ? payload.latest_event_age_seconds : null;
            const isLate = payload && payload.latest_event_is_late === true;
            const threshold = payload && payload.late_threshold_minutes ? payload.late_threshold_minutes : null;

            if (age === null) {
                elLatestEventBadge.textContent = 'Inconnu';
                elLatestEventBadge.className = 'ml-2 inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-[11px] font-semibold text-slate-700';
            } else if (isLate) {
                elLatestEventBadge.textContent = 'Retard ' + formatAge(age) + (threshold ? (' (>' + threshold + 'min)') : '');
                elLatestEventBadge.className = 'ml-2 inline-flex items-center rounded-full bg-rose-100 px-3 py-1 text-[11px] font-semibold text-rose-700';
            } else {
                elLatestEventBadge.textContent = 'OK ' + formatAge(age);
                elLatestEventBadge.className = 'ml-2 inline-flex items-center rounded-full bg-emerald-100 px-3 py-1 text-[11px] font-semibold text-emerald-700';
            }

            elLastData.textContent = lastData && lastData.finished_at ? (String(lastData.finished_at) + ' (+' + String(lastData.inserted_count || 0) + ')') : '—';
        }

        function pickArray(obj, keys) {
            if (!obj || typeof obj !== 'object') {
                return [];
            }
            for (const k of keys) {
                if (Array.isArray(obj[k])) {
                    return obj[k];
                }
            }
            for (const k of keys) {
                if (obj.data && Array.isArray(obj.data[k])) {
                    return obj.data[k];
                }
            }
            return [];
        }

        function firstValue(o, keys) {
            if (!o || typeof o !== 'object') {
                return '';
            }
            for (const k of keys) {
                const v = o[k];
                if (v !== null && v !== undefined && String(v).trim() !== '') {
                    return String(v).trim();
                }
            }
            return '';
        }

        function renderRemoteControl(payload) {
            elRemoteError.classList.add('hidden');
            elRemoteError.textContent = '';

            const expectedSec = payload && payload.remote_expected_sec ? Number(payload.remote_expected_sec) : null;
            if (expectedSec && isFinite(expectedSec) && expectedSec > 0) {
                elRemoteExpected.textContent = Math.round(expectedSec / 60) + ' min';
            } else {
                elRemoteExpected.textContent = '—';
            }

            if (payload && payload.remote_control_error) {
                elRemoteError.textContent = String(payload.remote_control_error);
                elRemoteError.classList.remove('hidden');
            }

            const control = payload && payload.remote_control ? payload.remote_control : null;
            const collectors = pickArray(control, ['devices', 'attendance_devices', 'terminals', 'collectors', 'collector', 'collector_list', 'data']);
            const runs = pickArray(control, ['runs', 'recent_runs', 'sync_runs', 'tasks']);

            if (!Array.isArray(collectors) || collectors.length === 0) {
                elCollectors.innerHTML = '<tr><td colspan="4" class="px-3 py-6 text-center text-sm text-slate-500">Aucun collecteur visible.</td></tr>';
            } else {
                elCollectors.innerHTML = collectors.slice(0, 50).map(c => {
                    const name = firstValue(c, ['name', 'device_label', 'collector_name', 'serial_number', 'device_sn', 'hostname', 'host', 'pc_name', 'collector_id', 'id']) || 'badgeuse';
                    const last = firstValue(c, ['last_seen_at', 'last_device_time_seen_at', 'last_sent_at', 'last_ok_at', 'last_push_at', 'last_run_at', 'updated_at', 'created_at']);
                    const statusRaw = firstValue(c, ['connection_status', 'device_status', 'status', 'state', 'health', 'level']);
                    const delay = firstValue(c, ['collection_delay_seconds']);
                    const missed = delay ? (delay + ' s') : firstValue(c, ['clock_status', 'missed', 'missed_count', 'missed_runs', 'missed_estimated', 'missed_estimate']);

                    const status = statusRaw ? statusRaw.toLowerCase() : '';
                    let badge = '<span class="inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">inconnu</span>';
                    if (status.includes('ok') || status.includes('success') || status.includes('online')) {
                        badge = '<span class="inline-flex items-center rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700">OK</span>';
                    } else if (status.includes('att') || status.includes('warn') || status.includes('out_of_sync')) {
                        badge = '<span class="inline-flex items-center rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-700">ATTENTION</span>';
                    } else if (status.includes('prob') || status.includes('err') || status.includes('ko') || status.includes('fail') || status.includes('offline') || status.includes('never_seen')) {
                        badge = '<span class="inline-flex items-center rounded-full bg-rose-100 px-3 py-1 text-xs font-semibold text-rose-700">PROBLÈME</span>';
                    }

                    return `<tr>
                        <td class="px-3 py-3 font-semibold text-slate-900">${esc(name)}</td>
                        <td class="px-3 py-3 text-slate-700">${esc(last || '—')}</td>
                        <td class="px-3 py-3">${badge}</td>
                        <td class="px-3 py-3 font-semibold">${esc(missed || '—')}</td>
                    </tr>`;
                }).join('');
            }

            if (!Array.isArray(runs) || runs.length === 0) {
                elRemoteRuns.innerHTML = '<tr><td colspan="5" class="px-3 py-6 text-center text-sm text-slate-500">Aucune tâche visible.</td></tr>';
            } else {
                elRemoteRuns.innerHTML = runs.slice(0, 50).map(r => {
                    const label = firstValue(r, ['label', 'scope', 'task', 'name', 'type']) || 'run';
                    const collector = firstValue(r, ['collector', 'collector_name', 'host', 'hostname', 'pc_name', 'source']) || '—';
                    const started = firstValue(r, ['started_at', 'start_at', 'created_at']) || '—';
                    const finished = firstValue(r, ['finished_at', 'end_at', 'updated_at']) || '—';
                    const statusRaw = firstValue(r, ['status', 'state', 'result']) || '';

                    const status = statusRaw.toLowerCase();
                    let badge = '<span class="inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">inconnu</span>';
                    if (status.includes('ok') || status.includes('success')) {
                        badge = '<span class="inline-flex items-center rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700">success</span>';
                    } else if (status.includes('run') || status.includes('progress')) {
                        badge = '<span class="inline-flex items-center rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-700">running</span>';
                    } else if (status.includes('err') || status.includes('ko') || status.includes('fail')) {
                        badge = '<span class="inline-flex items-center rounded-full bg-rose-100 px-3 py-1 text-xs font-semibold text-rose-700">error</span>';
                    }

                    return `<tr>
                        <td class="px-3 py-3 font-semibold text-slate-900">${esc(label)}</td>
                        <td class="px-3 py-3 text-slate-700">${esc(collector)}</td>
                        <td class="px-3 py-3 text-slate-600">${esc(started)}</td>
                        <td class="px-3 py-3 text-slate-600">${esc(finished)}</td>
                        <td class="px-3 py-3">${badge}</td>
                    </tr>`;
                }).join('');
            }
        }

        async function refreshStatus() {
            try {
                const res = await fetch(apiUrl + '?action=status', {
                    cache: 'no-store'
                });
                const payload = await res.json();
                elServerTime.textContent = payload.server_time || '';
                renderIndicators(payload);
                renderRuns(payload.runs);
                renderEvents(payload.events);
                renderHealth(payload);
                renderRemoteControl(payload);
            } catch (e) {
                // silencieux
            }
        }

        document.getElementById('btn-health').addEventListener('click', function() {
            refreshStatus();
        });

        document.getElementById('btn-sync').addEventListener('click', async function() {
            this.disabled = true;
            this.classList.add('opacity-70');
            try {
                const result = await postForm('run_sync_today', {
                    limit: '500'
                });
                await refreshStatus();
                if (!result.ok) {
                    alert('Sync en erreur: ' + (result.error || ''));
                }
            } finally {
                this.disabled = false;
                this.classList.remove('opacity-70');
            }
        });

        document.querySelectorAll('.btn-link').forEach(btn => {
            btn.addEventListener('click', async function() {
                const pid = this.getAttribute('data-personnel-id');
                const input = document.querySelector('.employee-code-input[data-personnel-id="' + pid + '"]');
                const msg = document.querySelector('.link-msg[data-personnel-id="' + pid + '"]');
                msg.textContent = '...';
                try {
                    const result = await postForm('link_personnel', {
                        personnel_id: pid,
                        employee_code: input.value || ''
                    });
                    msg.textContent = result.ok ? ('OK (' + (result.employee_name_snapshot || '') + ')') : ('KO');
                } catch (e) {
                    msg.textContent = 'KO';
                }
            });
        });

        document.querySelectorAll('.btn-unlink').forEach(btn => {
            btn.addEventListener('click', async function() {
                const pid = this.getAttribute('data-personnel-id');
                const input = document.querySelector('.employee-code-input[data-personnel-id="' + pid + '"]');
                const msg = document.querySelector('.link-msg[data-personnel-id="' + pid + '"]');
                msg.textContent = '...';
                try {
                    const result = await postForm('unlink_personnel', {
                        personnel_id: pid
                    });
                    if (result.ok) {
                        input.value = '';
                        msg.textContent = 'Délié';
                    } else {
                        msg.textContent = 'KO';
                    }
                } catch (e) {
                    msg.textContent = 'KO';
                }
            });
        });

        refreshStatus();
        setInterval(refreshStatus, 5000);
    })();
</script>

<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>
