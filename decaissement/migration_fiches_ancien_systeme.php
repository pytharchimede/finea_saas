<?php
require_once __DIR__ . '/../auth_check.php';

$pageTitle = 'Migration fiches (ancien → nouveau) - DécaissApp';
$activeMenu = '';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';

if (!hasPermission('acces_admin')) {
    http_response_code(403);
    echo '<div class="container mx-auto px-4 py-10">';
    echo '<div class="bg-white border border-red-100 rounded-xl p-6 shadow-sm">';
    echo '<div class="text-lg font-semibold text-red-700 mb-2">Accès refusé</div>';
    echo '<div class="text-sm text-gray-600">Cette page est réservée aux administrateurs.</div>';
    echo '</div></div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$defaultCode = (string)($_GET['chantier_code'] ?? 'CH038');
?>

<div class="container mx-auto px-4">
    <div class="flex items-start justify-between gap-4 mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Migration des fiches de décaissement</h1>
            <p class="text-sm text-gray-600 mt-1">
                Transfère les fiches de l’ancienne base (<span class="font-medium">fidestci_app_db</span>) vers la nouvelle (<span class="font-medium">fidestci_projet_db</span>),
                par défaut en mode <span class="font-medium">payé uniquement</span> (on migre seulement les paiements réels &gt; 0).
                Dans ce mode, la migration ne crée <span class="font-medium">pas</span> de fiches héritières <span class="font-mono">-P1</span> (reste à décaisser).
                Si vous décochez, une compatibilité legacy peut inclure des fiches marquées « décaissées » même sans lignes de paiement.
                Les liens <span class="font-medium">fiche → ligne déboursé → déboursé → ligne devis → devis</span> sont créés à <span class="font-medium">0</span> si manquants.
            </p>
        </div>
        <div class="text-right">
            <div class="text-xs text-gray-500">Accès: admin</div>
        </div>
    </div>

    <div class="bg-white border border-gray-100 rounded-2xl shadow-sm p-6 mb-6">
        <form id="migForm" class="grid grid-cols-1 md:grid-cols-12 gap-4 items-end">
            <div class="md:col-span-4">
                <label class="block text-sm font-medium text-gray-700 mb-1" for="chantier_code">Code chantier</label>
                <input id="chantier_code" name="chantier_code" value="<?= htmlspecialchars($defaultCode, ENT_QUOTES, 'UTF-8') ?>" class="w-full px-3 py-2 rounded-lg border border-gray-200 focus:ring-2 focus:ring-indigo-200 focus:border-indigo-300" placeholder="Ex: CH038" />
            </div>
            <div class="md:col-span-3">
                <label class="block text-sm font-medium text-gray-700 mb-1" for="old_chantier_id">ID chantier (ancien) (optionnel)</label>
                <input id="old_chantier_id" name="old_chantier_id" class="w-full px-3 py-2 rounded-lg border border-gray-200 focus:ring-2 focus:ring-indigo-200 focus:border-indigo-300" placeholder="Ex: 41" />
            </div>
            <div class="md:col-span-5">
                <div class="flex flex-wrap items-center gap-4">
                    <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" id="paid_only" class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-200" checked />
                        <span>Migrer <span class="font-semibold">payé uniquement</span> (ignorer reste à décaisser)</span>
                    </label>
                    <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" id="auto_validate_mig_devis" class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-200" />
                        <span>Auto-valider le devis <span class="font-mono">MIG-*</span> (DG + DT)</span>
                    </label>
                    <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" id="auto_publish_mig_devis" class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-200" disabled />
                        <span>Publier le devis <span class="font-mono">MIG-*</span></span>
                    </label>
                </div>
                <div class="text-xs text-gray-500 mt-1">Le mode payé uniquement ignore les fiches « décaissées » sans lignes dans la table <span class="font-mono">decaissement</span>. Les options devis ne modifient que les devis dont le numéro commence par <span class="font-mono">MIG-</span>.</div>
            </div>
            <div class="md:col-span-12 flex flex-wrap gap-3">
                <button type="button" id="btnPreview" class="px-4 py-2 rounded-lg bg-indigo-600 text-white font-medium hover:bg-indigo-700">Prévisualiser</button>
                <button type="button" id="btnDryRun" class="px-4 py-2 rounded-lg bg-gray-900 text-white font-medium hover:bg-black">Test (dry-run)</button>
                <button type="button" id="btnRun" class="px-4 py-2 rounded-lg bg-emerald-600 text-white font-medium hover:bg-emerald-700">Migrer</button>
                <button type="button" id="btnRefresh" class="px-4 py-2 rounded-lg bg-red-600 text-white font-medium hover:bg-red-700">Refresh (purger + migrer)</button>
                <button type="button" id="btnStop" class="px-4 py-2 rounded-lg bg-gray-100 text-gray-800 font-medium hover:bg-gray-200" disabled>Stop</button>
            </div>

            <div class="md:col-span-12">
                <div id="status" class="text-sm text-gray-600"></div>
            </div>
        </form>
    </div>

    <div id="preview" class="mb-6"></div>

    <div class="bg-white border border-gray-100 rounded-2xl shadow-sm p-6">
        <div class="flex items-center justify-between mb-3">
            <div class="text-lg font-semibold text-gray-900">Journal</div>
            <div class="text-xs text-gray-500">Les erreurs sont affichées par fiche.</div>
        </div>
        <pre id="log" class="text-xs bg-gray-50 border border-gray-100 rounded-xl p-4 overflow-auto max-h-[420px]"></pre>
    </div>
</div>

<script>
    (function() {
        const endpoint = 'request/migrate_old_fiches.php';

        const form = document.getElementById('migForm');
        const statusEl = document.getElementById('status');
        const previewEl = document.getElementById('preview');
        const logEl = document.getElementById('log');

        const btnPreview = document.getElementById('btnPreview');
        const btnDryRun = document.getElementById('btnDryRun');
        const btnRun = document.getElementById('btnRun');
        const btnRefresh = document.getElementById('btnRefresh');
        const btnStop = document.getElementById('btnStop');

        const chkAutoValidate = document.getElementById('auto_validate_mig_devis');
        const chkAutoPublish = document.getElementById('auto_publish_mig_devis');
        const chkPaidOnly = document.getElementById('paid_only');

        let stopRequested = false;

        function getFormData() {
            const chantier_code = (document.getElementById('chantier_code').value || '').trim();
            const old_chantier_id_raw = (document.getElementById('old_chantier_id').value || '').trim();
            return {
                chantier_code,
                old_chantier_id: old_chantier_id_raw,
                paid_only: !!(chkPaidOnly && chkPaidOnly.checked),
                auto_validate_mig_devis: !!chkAutoValidate.checked,
                auto_publish_mig_devis: !!chkAutoPublish.checked,
            };
        }

        function setStatus(msg) {
            statusEl.textContent = msg || '';
        }

        function logLine(line) {
            const s = String(line || '');
            logEl.textContent += s + "\n";
            logEl.scrollTop = logEl.scrollHeight;
        }

        function resetLog() {
            logEl.textContent = '';
        }

        async function doPreview() {
            const {
                chantier_code,
                old_chantier_id,
                paid_only
            } = getFormData();
            if (!chantier_code) {
                setStatus('Code chantier manquant.');
                return;
            }

            setStatus('Prévisualisation en cours…');
            previewEl.innerHTML = '';

            const url = new URL(endpoint, window.location.href);
            url.searchParams.set('action', 'preview');
            url.searchParams.set('chantier_code', chantier_code);
            if (old_chantier_id) url.searchParams.set('old_chantier_id', old_chantier_id);
            if (paid_only) url.searchParams.set('paid_only', '1');

            const res = await fetch(url.toString(), {
                headers: {
                    'Accept': 'application/json'
                }
            });
            const data = await res.json();
            if (!data.ok) {
                setStatus(data.error || 'Erreur preview');
                return;
            }

            setStatus('Prévisualisation OK.');

            const stats = data.stats || {};
            const oldInfo = data.old || {};
            const newInfo = data.new || {};

            const html = `
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="bg-indigo-50 border border-indigo-100 rounded-2xl p-5">
                    <div class="text-xs uppercase tracking-wide text-indigo-700 font-semibold">Ancien</div>
                    <div class="mt-2 text-sm text-gray-800">Chantier ID: <span class="font-semibold">${oldInfo.id ?? ''}</span></div>
                    <div class="text-sm text-gray-700">${escapeHtml(oldInfo.titre ?? '')}</div>
                </div>
                <div class="bg-emerald-50 border border-emerald-100 rounded-2xl p-5">
                    <div class="text-xs uppercase tracking-wide text-emerald-700 font-semibold">Nouveau</div>
                    <div class="mt-2 text-sm text-gray-800">Chantier ID: <span class="font-semibold">${newInfo.id ?? ''}</span></div>
                    <div class="text-sm text-gray-700">${escapeHtml(newInfo.titre ?? '')}</div>
                    <div class="text-xs text-gray-500 mt-1">devis_id: ${newInfo.devis_id ?? 'NULL'}</div>
                </div>
                <div class="bg-white border border-gray-100 rounded-2xl p-5">
                    <div class="text-xs uppercase tracking-wide text-gray-700 font-semibold">Statistiques</div>
                    <div class="mt-2 text-sm text-gray-800">Total fiches: <span class="font-semibold">${stats.total_fiches ?? 0}</span></div>
                    <div class="text-sm text-gray-800">Avec décaissement: <span class="font-semibold">${stats.fiches_avec_decaissement ?? 0}</span></div>
                    <div class="text-sm text-gray-800">Total décaissé: <span class="font-semibold">${formatMoney(stats.total_decaisse_fcfa ?? 0)}</span> FCFA</div>
                </div>
            </div>
        `;
            previewEl.innerHTML = html;

            const sample = Array.isArray(data.sample) ? data.sample : [];
            if (sample.length) {
                let rows = '';
                for (const r of sample) {
                    rows += `
                    <tr class="border-t">
                        <td class="py-2 pr-3 font-mono text-xs">${escapeHtml(r.num_fiche || '')}</td>
                        <td class="py-2 pr-3 text-xs">${escapeHtml(r.beneficiaire || '')}</td>
                        <td class="py-2 pr-3 text-xs text-right">${formatMoney(r.montant_initial || 0)}</td>
                        <td class="py-2 pr-3 text-xs text-right">${formatMoney(r.total_decaisse || 0)}</td>
                        <td class="py-2 pr-3 text-xs text-right">${r.nb_decaissements || 0}</td>
                        <td class="py-2 pr-3 text-xs">${escapeHtml(r.dernier_decaissement || '')}</td>
                    </tr>
                `;
                }

                previewEl.innerHTML += `
                <div class="mt-5 bg-white border border-gray-100 rounded-2xl overflow-hidden">
                    <div class="px-5 py-3 bg-gray-50 border-b text-sm font-semibold text-gray-800">Échantillon (30 premières fiches)</div>
                    <div class="p-5 overflow-auto">
                        <table class="w-full">
                            <thead>
                                <tr class="text-left text-xs text-gray-500">
                                    <th class="pb-2 pr-3">Num</th>
                                    <th class="pb-2 pr-3">Bénéficiaire</th>
                                    <th class="pb-2 pr-3 text-right">Montant initial</th>
                                    <th class="pb-2 pr-3 text-right">Total décaissé</th>
                                    <th class="pb-2 pr-3 text-right">Nb</th>
                                    <th class="pb-2 pr-3">Dernière date</th>
                                </tr>
                            </thead>
                            <tbody>${rows}</tbody>
                        </table>
                    </div>
                </div>
            `;
            }
        }

        async function doRefreshThenMigrate() {
            const {
                chantier_code,
                old_chantier_id
            } = getFormData();
            if (!chantier_code) {
                setStatus('Code chantier manquant.');
                return;
            }

            setStatus('Prévisualisation purge (refresh) en cours…');
            previewEl.innerHTML = '';

            const urlPrev = new URL(endpoint, window.location.href);
            urlPrev.searchParams.set('action', 'refresh_preview');
            urlPrev.searchParams.set('chantier_code', chantier_code);
            if (old_chantier_id) urlPrev.searchParams.set('old_chantier_id', old_chantier_id);

            const resPrev = await fetch(urlPrev.toString(), {
                headers: {
                    'Accept': 'application/json'
                }
            });
            const prev = await resPrev.json();
            if (!prev.ok) {
                setStatus(prev.error || 'Erreur refresh preview');
                return;
            }

            const stats = prev.stats || {};
            const nb = Number(stats.nb_fiches_a_purger || 0);
            const total = Number(stats.total_montant || 0);
            const minCreated = stats.min_created_at || '';
            const maxCreated = stats.max_created_at || '';

            previewEl.innerHTML = `
                <div class="bg-red-50 border border-red-100 rounded-2xl p-5">
                    <div class="text-xs uppercase tracking-wide text-red-700 font-semibold">Refresh (purge migration)</div>
                    <div class="mt-2 text-sm text-gray-800">Fiches à purger (marqueur MIGRATION): <span class="font-semibold">${nb.toLocaleString('fr-FR')}</span></div>
                    <div class="text-sm text-gray-800">Montant total (montant_fiche): <span class="font-semibold">${formatMoney(total)}</span> FCFA</div>
                    ${minCreated || maxCreated ? `<div class="text-xs text-gray-600 mt-1">Période: ${escapeHtml(minCreated)} → ${escapeHtml(maxCreated)}</div>` : ''}
                    <div class="text-xs text-gray-600 mt-2">Cette action supprime les fiches migrées du chantier dans la nouvelle base, puis relance la migration.</div>
                </div>
            `;

            if (nb <= 0) {
                setStatus('Aucune fiche MIGRATION à purger pour ce chantier.');
                return;
            }

            const ok = window.confirm(`Refresh migration: purger ${nb} fiche(s) MIGRATION (total ${formatMoney(total)} FCFA) puis relancer la migration pour ${chantier_code} ?`);
            if (!ok) {
                setStatus('Refresh annulé.');
                return;
            }

            resetLog();
            logLine(`[${new Date().toISOString()}] REFRESH chantier ${chantier_code} — purge MIGRATION`);
            setStatus('Purge en cours…');

            const formData = new FormData();
            formData.set('action', 'refresh_run');
            formData.set('chantier_code', chantier_code);
            if (old_chantier_id) formData.set('old_chantier_id', old_chantier_id);
            formData.set('confirm', '1');

            const resRun = await fetch(endpoint, {
                method: 'POST',
                body: formData,
                headers: {
                    'Accept': 'application/json'
                }
            });
            const run = await resRun.json();
            if (!run.ok) {
                logLine(`ERREUR REFRESH: ${run.error || 'Erreur inconnue'}`);
                setStatus('Erreur refresh.');
                return;
            }

            const del = run.deleted || {};
            logLine(`PURGE OK: fiche_decaissement=${del.fiche_decaissement || 0}, trace_fiche=${del.trace_fiche || 0}`);
            setStatus('Purge OK. Relance migration…');

            await runMigration(false, true);
        }

        async function runMigration(dryRun, appendLog) {
            const {
                chantier_code,
                old_chantier_id,
                paid_only,
                auto_validate_mig_devis,
                auto_publish_mig_devis
            } = getFormData();
            if (!chantier_code) {
                setStatus('Code chantier manquant.');
                return;
            }

            stopRequested = false;
            btnStop.disabled = false;

            if (!appendLog) {
                resetLog();
            } else {
                logLine('');
            }
            logLine(`[${new Date().toISOString()}] Démarrage ${dryRun ? 'DRY-RUN' : 'MIGRATION'} chantier ${chantier_code}`);

            let offset = 0;
            const limit = 20;
            let totalMigrated = 0;
            let totalErrors = 0;
            let loop = 0;
            let migDevisMetaLogged = false;

            while (!stopRequested) {
                loop++;
                setStatus(`Migration en cours… lot ${loop} (offset ${offset})`);

                const formData = new FormData();
                formData.set('action', 'run');
                formData.set('chantier_code', chantier_code);
                if (old_chantier_id) formData.set('old_chantier_id', old_chantier_id);
                if (paid_only) formData.set('paid_only', '1');
                formData.set('offset', String(offset));
                formData.set('limit', String(limit));
                if (dryRun) formData.set('dry_run', '1');
                if (auto_validate_mig_devis) formData.set('auto_validate_mig_devis', '1');
                if (auto_publish_mig_devis) formData.set('auto_publish_mig_devis', '1');

                const res = await fetch(endpoint, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'Accept': 'application/json'
                    }
                });
                const data = await res.json();

                if (!data.ok) {
                    logLine(`ERREUR: ${data.error || 'Erreur inconnue'}`);
                    setStatus('Erreur migration.');
                    break;
                }

                if (!migDevisMetaLogged) {
                    const action = data && data.meta && data.meta.mig_devis_action ? data.meta.mig_devis_action : null;
                    if (action && action.devis_id) {
                        if (action.applied) {
                            logLine(`DEVI ${action.numero_devis || ''} (id=${action.devis_id}) auto-validé (DG+DT)${action.published ? ' + publié' : ''}.`);
                        } else if (action.reason === 'already_ok') {
                            logLine(`DEVI ${action.numero_devis || ''} (id=${action.devis_id}) déjà validé${action.published ? ' + publié' : ''}.`);
                        } else if (action.reason === 'not_mig') {
                            logLine(`DEVI (id=${action.devis_id}) non MIG — aucune auto-validation appliquée.`);
                        }
                        migDevisMetaLogged = true;
                    }
                }

                const r = data.results || {};
                const det = Array.isArray(r.details) ? r.details : [];
                totalMigrated += (r.migrated || 0);
                totalErrors += (r.errors || 0);

                for (const d of det) {
                    if (d.status === 'ok') {
                        logLine(`OK   ${d.num_fiche}`);
                    } else if (d.status === 'dry_run') {
                        logLine(`DRY  ${d.num_fiche}`);
                    } else if (d.status === 'skipped') {
                        logLine(`SKIP ${d.num_fiche} (${d.reason || ''})`);
                    } else {
                        logLine(`ERR  ${d.num_fiche || ''} ${d.error || ''}`);
                    }
                }

                if ((data.count || 0) < limit) {
                    // fin
                    break;
                }

                offset = data.next_offset || (offset + limit);
            }

            btnStop.disabled = true;

            if (stopRequested) {
                setStatus(`${dryRun ? 'Dry-run' : 'Migration'} interrompu. Migrées: ${totalMigrated}, erreurs: ${totalErrors}`);
                logLine('STOP demandé par l\'utilisateur.');
            } else {
                if (dryRun) {
                    setStatus(`Dry-run terminé (aucune écriture en base). Fiches simulées: ${totalMigrated}, erreurs: ${totalErrors}`);
                } else {
                    setStatus(`Migration terminée (écritures en base). Fiches traitées: ${totalMigrated}, erreurs: ${totalErrors}`);
                }
                logLine(`[${new Date().toISOString()}] Terminé. Migrées: ${totalMigrated}, erreurs: ${totalErrors}`);
            }
        }

        btnPreview.addEventListener('click', () => {
            doPreview().catch(err => setStatus(err && err.message ? err.message : String(err)));
        });

        btnDryRun.addEventListener('click', () => {
            runMigration(true, false).catch(err => setStatus(err && err.message ? err.message : String(err)));
        });

        btnRun.addEventListener('click', () => {
            runMigration(false, false).catch(err => setStatus(err && err.message ? err.message : String(err)));
        });

        btnRefresh.addEventListener('click', () => {
            doRefreshThenMigrate().catch(err => setStatus(err && err.message ? err.message : String(err)));
        });

        btnStop.addEventListener('click', () => {
            stopRequested = true;
            btnStop.disabled = true;
            setStatus('Stop demandé…');
        });

        function syncAutoPublishEnabled() {
            const enabled = !!chkAutoValidate.checked;
            chkAutoPublish.disabled = !enabled;
            if (!enabled) chkAutoPublish.checked = false;
        }

        chkAutoValidate.addEventListener('change', syncAutoPublishEnabled);
        syncAutoPublishEnabled();

        function escapeHtml(s) {
            return String(s || '').replace(/[&<>"']/g, m => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;'
            } [m]));
        }

        function formatMoney(n) {
            const x = Number(n || 0);
            return x.toLocaleString('fr-FR', {
                minimumFractionDigits: 0,
                maximumFractionDigits: 0
            });
        }
    })();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>