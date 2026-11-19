<?php
session_start();
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/model/Database.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/model/FicheDecaissement.php';
require_once __DIR__ . '/model/Precision.php';
require_once __DIR__ . '/model/Designation.php';
require_once __DIR__ . '/model/Debourse.php';
require_once __DIR__ . '/model/LigneDebourse.php';

$pdo = Database::getConnection();
$ficheObj = new FicheDecaissement($pdo);
$precisionObj = new Precision($pdo);
$designationObj = new Designation($pdo);
$debourseObj = new Debourse($pdo);
$ligneDebourseObj = new LigneDebourse($pdo);

// Nouvelles données initiales via fiche_decaissement
$fiches = $ficheObj->listAcertifier();

AuditTrail::log('page_view', [
    'module' => 'decaissement',
    'action' => 'liste_fiches_conformite_view',
    'entity_type' => 'fiche_conformite_list',
    'entity_label' => 'Liste fiches conformité',
    'page_name' => 'liste_fiches_conformite.php',
    'result' => 'success',
    'details' => [
        'row_count' => is_countable($fiches ?? null) ? count($fiches) : null,
        'initial_filters' => [
            'date_debut' => $_GET['date_debut'] ?? null,
            'date_fin' => $_GET['date_fin'] ?? null,
            'demandeur' => $_GET['demandeur'] ?? null,
            'chantier' => $_GET['chantier'] ?? null,
            'precision' => $_GET['precision'] ?? null,
            'designation' => $_GET['designation'] ?? null,
        ],
    ],
], $pdo);

$pageTitle = 'Fiches à certifier conformes';
$activeMenu = 'conformite';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>
<div class="container mx-auto py-10 px-4">
    <h1 class="text-3xl font-bold text-center mb-10 text-indigo-700 drop-shadow">Fiches à certifier conformes</h1>
    <!-- Formulaire de recherche -->
    <form id="searchForm" class="bg-white rounded-xl shadow p-6 mb-8 flex flex-wrap gap-4 items-end">
        <div class="flex gap-2">
            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">Date début</label>
                <input type="date" name="date_debut" class="form-input rounded border-gray-300" />
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">Date fin</label>
                <input type="date" name="date_fin" class="form-input rounded border-gray-300" />
            </div>
        </div>
        <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">Bénéficiaire</label>
            <input type="text" name="demandeur" placeholder="Nom du bénéficiaire" class="form-input rounded border-gray-300" />
        </div>
        <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">Chantier</label>
            <input type="text" name="chantier" placeholder="Titre du devis" class="form-input rounded border-gray-300" />
        </div>
        <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">Précision</label>
            <input type="text" name="precision" placeholder="Précision" class="form-input rounded border-gray-300" />
        </div>
        <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">Désignation</label>
            <input type="text" name="designation" placeholder="Désignation" class="form-input rounded border-gray-300" />
        </div>
        <div class="flex gap-2">
            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">Montant min</label>
                <input type="number" name="montant_min" placeholder="Min" class="form-input rounded border-gray-300" />
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">Montant max</label>
                <input type="number" name="montant_max" placeholder="Max" class="form-input rounded border-gray-300" />
            </div>
        </div>
        <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg font-semibold shadow transition">Rechercher</button>
        <button type="button" id="resetBtn" class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-4 py-2 rounded-lg font-semibold shadow transition">Réinitialiser</button>
    </form>

    <!-- Résumé dynamique -->
    <div class="flex flex-wrap items-center justify-between mb-4">
        <div>
            <span class="font-bold text-indigo-700" id="nbFiches"><?php echo count($fiches); ?></span> fiche(s) trouvée(s)
            &nbsp;|&nbsp;
            Montant total : <span class="font-bold text-green-600" id="totalMontant"><?php echo number_format(array_sum(array_map(function ($f) {
                                                                                            return $f['montant_fiche'];
                                                                                        }, $fiches)), 0, ',', ' '); ?></span> FCFA
        </div>
        <div class="flex gap-2">
            <button type="button" id="exportAllPdf" class="bg-red-600 hover:bg-red-700 text-white px-3 py-1.5 rounded shadow text-xs">Exporter tout en PDF</button>
            <button type="button" id="exportAllExcel" class="bg-green-600 hover:bg-green-700 text-white px-3 py-1.5 rounded shadow text-xs">Exporter tout en Excel</button>
        </div>
    </div>
    <div id="cards-container" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-8">
        <?php foreach ($fiches as $fiche): ?>
            <?php
            $montantFiche = floatval($fiche['montant_fiche']);
            $badgeColor = 'bg-gray-100 text-gray-700 border-gray-400';
            $badgeIcon = '<svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M12 20h.01M12 4h.01" /></svg>';
            $badgeText = 'Contrôle manuel';
            $badgeTooltip = 'Comparaison automatique non disponible (fiche_decaissement).';
            ?>
            <div class="bg-white rounded-2xl shadow-xl hover:shadow-2xl transition-all flex flex-col justify-between p-6 relative group">
                <!-- Badge analyse budget dynamique (gauche) -->
                <div class="absolute top-4 left-4 flex items-center z-10">
                    <span class="card-badge-analyse" data-num="<?php echo htmlspecialchars($fiche['num_fiche']); ?>" aria-label="Analyse budget" title="Analyse budget" style="display:inline-flex;align-items:center;justify-content:center;width:38px;height:38px;border:2px solid #e2e8f0;border-radius:50%;background:#fff;font-size:11px;color:#64748b;font-weight:600;box-shadow:0 2px 4px rgba(0,0,0,.08);">...</span>
                </div>
                <!-- Badge conformité (droite) -->
                <span class="absolute top-4 right-4 bg-indigo-100 text-indigo-700 px-3 py-1 rounded-full text-xs font-semibold shadow group-hover:bg-opacity-80 transition">
                    À certifier
                </span>
                <?php if(!empty($fiche['contract_id'])):?><span class="absolute right-4 top-12 rounded-full bg-amber-100 px-3 py-1 text-xs font-black text-amber-900">FEB contrat</span><?php endif;?>

                <!-- Photo -->
                <div class="flex justify-center mb-4">
                    <?php
                    $photoFile = trim($fiche['photo_beneficiaire'] ?? '');
                    $originalPhotoValue = $photoFile;
                    $absPhoto = '';
                    $webPhoto = '';
                    if ($photoFile !== '') {
                        // Si déjà un chemin (contient un /), on l'utilise tel quel relatif à la racine app
                        if (strpos($photoFile, '/') !== false) {
                            $cleanRel = ltrim($photoFile, '/');
                            // Normaliser éventuel préfixe ./
                            $cleanRel = preg_replace('#^\./#', '', $cleanRel);
                            // Tentative 1 : relatif depuis ce fichier (../)
                            $candidate1 = realpath(__DIR__ . '/../' . $cleanRel);
                            if ($candidate1 && is_file($candidate1)) {
                                $absPhoto = $candidate1;
                                $webPhoto = '../' . str_replace('\\', '/', $cleanRel);
                            }
                        }
                        // Sinon nom simple => répertoire standard uploads/photo
                        if ($absPhoto === '') {
                            $candidate2 = __DIR__ . '/../uploads/photo/' . $photoFile;
                            if (is_file($candidate2)) {
                                $absPhoto = $candidate2;
                                $webPhoto = '../uploads/photo/' . rawurlencode($photoFile);
                            }
                        }
                        // Fallback : dossier decaissement/uploads/photo (au cas où)
                        if ($absPhoto === '') {
                            $candidate3 = __DIR__ . '/uploads/photo/' . $photoFile;
                            if (is_file($candidate3)) {
                                $absPhoto = $candidate3;
                                $webPhoto = 'uploads/photo/' . rawurlencode($photoFile);
                            }
                        }
                    }
                    $showDebug = isset($_GET['debug_photo']);
                    if ($absPhoto) : ?>
                        <img src="<?= htmlspecialchars($webPhoto) ?>" alt="Photo de <?= htmlspecialchars($fiche['beficiaire_fiche']) ?>" class="w-20 h-20 rounded-full object-cover border-4 border-indigo-400 shadow-md bg-gray-100" loading="lazy" <?= $showDebug ? ' title="OK: ' . htmlspecialchars($originalPhotoValue) . ' -> ' . htmlspecialchars($webPhoto) . '"' : ''; ?>>
                    <?php else: ?>
                        <div class="w-20 h-20 flex items-center justify-center rounded-full border-4 border-indigo-400 shadow-md bg-gray-100" <?= $showDebug ? ' title="MISS: ' . htmlspecialchars($originalPhotoValue) . ' cherchés: uploads/photo, decaissement/uploads/photo"' : ''; ?>>
                            <svg class="w-12 h-12 text-gray-400" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M12 12c2.7 0 4.5-2.1 4.5-4.5S14.7 3 12 3 7.5 5.1 7.5 7.5 9.3 12 12 12zm0 2c-3 0-9 1.5-9 4.5V21h18v-2.5c0-3-6-4.5-9-4.5z" />
                            </svg>
                        </div>
                    <?php endif; ?>
                </div>
                <!-- Infos principales -->
                <h2 class="text-xl font-semibold text-center mb-1 text-gray-800"><?php echo htmlspecialchars($fiche['beficiaire_fiche']); ?></h2>
                <div class="flex items-center justify-center mb-1">
                    <span class="text-gray-500 text-sm">Fiche N° <?php echo htmlspecialchars($fiche['num_fiche']); ?></span>
                    <!-- Lien de visualisation correct basé sur num_fiche -->
                    <a href="voir_fiche_details.php?num_fiche=<?php echo urlencode($fiche['num_fiche']); ?>" target="_blank"
                        class="ml-2 bg-white border border-indigo-200 rounded-full p-1 shadow hover:bg-indigo-50 transition"
                        title="Voir la fiche (détails)">
                        <svg class="w-5 h-5 text-indigo-700" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M12 4v16m0 0l-6-6m6 6l6-6" />
                        </svg>
                    </a>
                </div>
                <p class="text-green-600 font-bold text-lg text-center mb-2">
                    <?php echo number_format($fiche['montant_fiche'], 0, ',', ' ') ?> CFA
                </p>
                <!-- Détails -->
                <div class="text-sm text-gray-700 mb-4 text-center">
                    <p><strong>Ligne déboursé :</strong> <?php echo htmlspecialchars($fiche['ligne_debourse_label'] ?? ''); ?></p>
                    <p><strong>Ligne devis :</strong> <?php echo htmlspecialchars($fiche['ligne_devis_label'] ?? ''); ?></p>
                    <p><strong>Précision :</strong> <?php echo htmlspecialchars($fiche['precision_fiche'] ?? ''); ?></p>
                    <p><strong>Désignation :</strong> <?php echo htmlspecialchars($fiche['designation_fiche'] ?? ''); ?></p>
                    <p class="mt-2 rounded-lg bg-indigo-50 px-2 py-1 text-indigo-800"><strong>Planning :</strong>
                        <?= !empty($fiche['planned_start_date']) ? date('d/m/Y', strtotime($fiche['planned_start_date'])) : 'Non défini' ?>
                        → <?= !empty($fiche['planned_end_date']) ? date('d/m/Y', strtotime($fiche['planned_end_date'])) : 'Non défini' ?>
                    </p>
                </div>
                <!-- Date + Action -->
                <div class="flex justify-between items-center mt-auto">
                    <span class="text-xs text-gray-400">
                        <?= date('d M Y', strtotime($fiche['date_creat_fiche'])); ?>
                    </span>
                    <div class="flex gap-1">
                        <button data-num="<?php echo htmlspecialchars($fiche['num_fiche']); ?>" class="js-analyse px-2 py-1 rounded bg-amber-500 hover:bg-amber-600 text-white text-xs" title="Analyse budget">Aide</button>
                        <button data-num="<?php echo htmlspecialchars($fiche['num_fiche']); ?>" class="js-refuse px-2 py-1 rounded bg-red-600 hover:bg-red-700 text-white text-xs" title="Refuser la fiche">Refuser</button>
                        <button
                            data-num="<?php echo htmlspecialchars($fiche['num_fiche']); ?>"
                            data-requires-invoice="<?php echo !empty($fiche['feb_requires_invoice_before_conformity']) ? '1' : '0'; ?>"
                            data-facture="<?php echo htmlspecialchars((string)($fiche['facture_reference'] ?? '')); ?>"
                            class="js-certify btn btn-primary text-xs">Certifier</button>
                    </div>
                </div>
                <!-- Checkbox pour sélection -->
                <input type="checkbox" name="fiche_select[]" value="<?php echo htmlspecialchars($fiche['num_fiche']); ?>"
                    class="absolute top-4 left-1 z-20 w-5 h-5 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500 fiche-checkbox">
            </div>
        <?php endforeach; ?>
    </div>
    <?php if (empty($fiches)): ?>
        <div class="text-center text-gray-500 mt-16 text-lg">Aucune fiche à certifier pour le moment.</div>
    <?php endif; ?>
    <div class="flex gap-4 mt-6 justify-end">
        <button type="button" id="exportSelectedPdf"
            class="flex items-center gap-2 bg-gradient-to-r from-red-500 to-red-700 hover:from-red-600 hover:to-red-800 text-white px-5 py-2 rounded-xl font-semibold shadow-lg transition">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round"
                    d="M12 4v16m0 0l-6-6m6 6l6-6" />
            </svg>
            Exporter la sélection PDF
        </button>
        <button type="button" id="exportSelectedExcel"
            class="flex items-center gap-2 bg-gradient-to-r from-green-500 to-green-700 hover:from-green-600 hover:to-green-800 text-white px-5 py-2 rounded-xl font-semibold shadow-lg transition">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round"
                    d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2m2 0v4m0-4l-2 2m2-2l2 2" />
            </svg>
            Exporter la sélection Excel
        </button>
    </div>
</div>
<script>
    // Modal analyse budget
    const analyseModal = document.createElement('div');
    analyseModal.id = 'analyse-budget-modal';
    analyseModal.style.position = 'fixed';
    analyseModal.style.inset = '0';
    analyseModal.style.background = 'rgba(0,0,0,.45)';
    analyseModal.style.zIndex = '9998';
    analyseModal.style.display = 'none';
    analyseModal.innerHTML = `\n<div style="max-width:680px;margin:60px auto;background:#fff;border-radius:14px;box-shadow:0 10px 28px -4px rgba(0,0,0,.25);overflow:hidden;font-family:system-ui,Segoe UI,Arial,sans-serif">\n  <div style='display:flex;align-items:center;justify-content:space-between;padding:14px 20px;background:#1e293b;color:#fff'>\n    <h3 style='margin:0;font-size:16px;font-weight:600'>Analyse budget fiche <span id='ab-fiche-num'></span></h3>\n    <button id='ab-close' style='background:transparent;border:0;color:#fff;font-size:20px;cursor:pointer'>&times;</button>\n  </div>\n  <div id='ab-body' style='padding:18px 22px;font-size:14px;line-height:1.4;max-height:58vh;overflow:auto'>Chargement...</div>\n  <div style='padding:12px 18px;display:flex;justify-content:flex-end;gap:8px;background:#f8fafc'>\n     <button id='ab-close-footer' style='background:#334155;color:#fff;border:0;padding:8px 16px;border-radius:8px;font-size:13px;cursor:pointer'>Fermer</button>\n  </div>\n</div>`;
    document.body.appendChild(analyseModal);
    ['ab-close', 'ab-close-footer'].forEach(id => analyseModal.addEventListener('click', e => {
        if (e.target.id === id) analyseModal.style.display = 'none';
    }));

    const rejectModal = document.createElement('div');
    rejectModal.id = 'reject-modal';
    rejectModal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.45);display:none;z-index:9999;';
    rejectModal.innerHTML = `<div style="max-width:480px;margin:80px auto;background:#fff;border-radius:14px;box-shadow:0 10px 28px -4px rgba(0,0,0,.3);overflow:hidden;font-family:system-ui,Segoe UI,Arial,sans-serif">
        <div style='padding:14px 18px;background:#991b1b;color:#fff;display:flex;justify-content:space-between;align-items:center'><strong style='font-size:15px'>Motif de refus</strong><button id='rej-close' style='background:transparent;border:0;color:#fff;font-size:20px;cursor:pointer'>&times;</button></div>
        <div style='padding:16px 20px;font-size:14px'>
           <div style='font-size:12px;color:#64748b;margin-bottom:8px'>Fiche <strong id='rej-label'></strong></div>
           <textarea id='rej-reason' rows='4' style='width:100%;border:1px solid #e2e8f0;border-radius:8px;padding:8px;resize:vertical;font-size:13px' placeholder='Expliquer la raison du refus...'></textarea>
           <input type='hidden' id='rej-num'>
           <div style='margin-top:14px;display:flex;justify-content:flex-end;gap:10px'>
              <button id='rej-cancel' style='background:#e2e8f0;border:0;padding:8px 16px;border-radius:8px;font-size:13px;cursor:pointer'>Annuler</button>
              <button id='rej-submit' style='background:#dc2626;color:#fff;border:0;padding:8px 16px;border-radius:8px;font-size:13px;cursor:pointer;font-weight:600'>Refuser</button>
           </div>
        </div>
    </div>`;
    document.body.appendChild(rejectModal);
    rejectModal.addEventListener('click', e => {
        if (e.target.id === 'rej-close' || e.target.id === 'rej-cancel' || e.target === rejectModal) {
            rejectModal.style.display = 'none';
        }
    });

    function renderAnalyse(a) {
        if (!a) return '<div style="color:#b91c1c">Analyse indisponible.</div>';
        const fmt = n => Number(n).toLocaleString('fr-FR', {
            minimumFractionDigits: 0
        });
        const ratioPct = (a.ratio * 100).toFixed(1) + '%';
        const badges = {
            large_marge: ['#0f766e', '#ccfbf1', 'Large marge'],
            marge: ['#0369a1', '#e0f2fe', 'Marge'],
            limite: ['#92400e', '#fef3c7', 'Limite'],
            depasse_legere: ['#b45309', '#fde68a', 'Dépasse +'],
            depasse_forte: ['#b91c1c', '#fee2e2', 'Dépasse !!!'],
            invalide: ['#475569', '#e2e8f0', 'Invalide']
        };
        const b = badges[a.niveau] || badges.invalide;
        const bar = (() => {
            const perc = Math.min(115, Math.round(a.ratio * 100));
            const col = a.ratio <= 1 ? '#16a34a' : (a.ratio <= 1.1 ? '#f59e0b' : '#dc2626');
            return `<div style='background:#e2e8f0;height:10px;border-radius:6px;overflow:hidden;margin-top:6px'><div style='width:${perc}%;background:${col};height:100%'></div></div>`;
        })();
        return `<div>
                    <div style='display:flex;flex-wrap:wrap;gap:10px;margin-bottom:14px'>
                         <div style='background:${b[1]};color:${b[0]};padding:4px 10px;border-radius:20px;font-size:12px;font-weight:600'>${b[2]}</div>
                         <div style='background:#f1f5f9;color:#334155;padding:4px 10px;border-radius:20px;font-size:12px'>Ligne #${a.ligne_id}</div>
                    </div>
                    <table style='width:100%;border-collapse:collapse;font-size:13px'>
                        <tbody>
                            <tr><td style='padding:4px 6px;color:#475569'>Ligne</td><td style='padding:4px 6px;font-weight:600'>${a.ligne_designation||''}</td></tr>
                            <tr><td style='padding:4px 6px;color:#475569'>Catégorie</td><td style='padding:4px 6px'>${a.ligne_categorie||''}</td></tr>
                            <tr><td style='padding:4px 6px;color:#475569'>Montant prévu</td><td style='padding:4px 6px'>${fmt(a.montant_ligne)} CFA</td></tr>
                            <tr><td style='padding:4px 6px;color:#475569'>Déjà décaissé</td><td style='padding:4px 6px'>${fmt(a.total_deja_decaisse)} CFA</td></tr>
                            <tr><td style='padding:4px 6px;color:#475569'>Non décaissé (autres)</td><td style='padding:4px 6px'>${fmt(a.total_non_decaisse)} CFA</td></tr>
                            <tr><td style='padding:4px 6px;color:#475569'>Montant fiche</td><td style='padding:4px 6px'>${fmt(a.montant_fiche)} CFA</td></tr>
                            <tr><td style='padding:4px 6px;color:#475569'>Projection</td><td style='padding:4px 6px'>${fmt(a.projection_decaisse)} CFA</td></tr>
                            <tr><td style='padding:4px 6px;color:#475569'>Ecart restant</td><td style='padding:4px 6px'>${fmt(a.ecart_restant)} CFA</td></tr>
                            <tr><td style='padding:4px 6px;color:#475569'>Ratio utilisation</td><td style='padding:4px 6px'>${ratioPct}</td></tr>
                        </tbody>
                    </table>
                    ${bar}
                    <div style='margin-top:14px;padding:10px 12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;font-size:13px;color:#334155'>${a.conseil}</div>
                    <div style='margin-top:14px;display:flex;gap:10px;flex-wrap:wrap'>
                         <button id='ab-show-fiches' data-ligne='${a.ligne_id}' style='background:#2563eb;color:#fff;border:0;padding:8px 14px;border-radius:8px;font-size:12px;cursor:pointer'>Fiches ligne</button>
                    </div>
                </div>`;
    }

    async function fetchAnalyse(num) {
        const body = document.getElementById('ab-body');
        document.getElementById('ab-fiche-num').textContent = num;
        body.innerHTML = 'Chargement...';
        analyseModal.style.display = 'block';
        try {
            const r = await fetch('request/analyse_fiche_budget.php?num_fiche=' + encodeURIComponent(num));
            const j = await r.json();
            if (!j.ok) {
                body.innerHTML = '<span style="color:#dc2626">' + (j.error || 'Erreur') + '</span>';
                return;
            }
            body.innerHTML = renderAnalyse(j.analyse);
            const btnF = body.querySelector('#ab-show-fiches');
            if (btnF) {
                btnF.addEventListener('click', () => fetchListeFichesLigne(btnF.getAttribute('data-ligne'), num));
            }
        } catch (e) {
            body.innerHTML = '<span style="color:#dc2626">' + e.message + '</span>';
        }
    }

    async function fetchListeFichesLigne(ligneId, num) {
        const body = document.getElementById('ab-body');
        body.innerHTML += '<div id="ab-ligne-load" style="margin-top:12px;color:#475569">Liste fiches...</div>';
        try {
            const r = await fetch('request/get_ligne_debourse_details.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: new URLSearchParams({
                    ligne_debourse_id: ligneId
                })
            });
            const j = await r.json();
            const wrap = document.createElement('div');
            wrap.style.marginTop = '16px';
            if (!j.success) {
                wrap.innerHTML = '<div style="color:#dc2626">' + (j.message || 'Erreur chargement fiches') + '</div>';
            } else {
                const rows = (j.fiches_existantes || []).map(f => `<tr>
                                     <td style='padding:4px 6px;border-bottom:1px solid #f1f5f9;font-size:12px'>${f.num_fiche||''}</td>
                                     <td style='padding:4px 6px;border-bottom:1px solid #f1f5f9;font-size:12px'>${(Number(f.montant_fiche)||0).toLocaleString('fr-FR')}</td>
                                     <td style='padding:4px 6px;border-bottom:1px solid #f1f5f9;font-size:12px'>${f.decaisse?'<span style="color:#16a34a;font-weight:600">Oui</span>':'<span style="color:#f59e0b">Non</span>'}</td>
                                     <td style='padding:4px 6px;border-bottom:1px solid #f1f5f9;font-size:12px'>${f.approuve? 'Approuvé':''}</td>
                             </tr>`).join('');
                const total = (j.fiches_existantes || []).reduce((s, f) => s + Number(f.montant_fiche || 0), 0);
                wrap.innerHTML = `<h4 style='margin:0 0 8px;font-size:14px'>Fiches de la ligne #${ligneId}</h4>
                                 <table style='width:100%;border-collapse:collapse;background:#fff;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden'>
                                     <thead><tr style='background:#f1f5f9'>
                                         <th style='padding:6px 6px;font-size:12px;text-align:left;color:#475569'>Num</th>
                                         <th style='padding:6px 6px;font-size:12px;text-align:left;color:#475569'>Montant</th>
                                         <th style='padding:6px 6px;font-size:12px;text-align:left;color:#475569'>Décaissé</th>
                                         <th style='padding:6px 6px;font-size:12px;text-align:left;color:#475569'>Statut</th>
                                     </tr></thead>
                                     <tbody>${rows}</tbody>
                                 </table>
                                 <div style='margin-top:8px;font-size:12px;color:#334155'>Total cumulé : <strong>${total.toLocaleString('fr-FR')} CFA</strong></div>`;
            }
            const load = document.getElementById('ab-ligne-load');
            if (load) load.remove();
            body.appendChild(wrap);
        } catch (e) {
            const load = document.getElementById('ab-ligne-load');
            if (load) load.textContent = e.message;
        }
    }

    function toggleFichePopover(id, event) {
        event.stopPropagation();
        // Ferme tous les autres popovers
        document.querySelectorAll('[id^="popover-"]').forEach(function(pop) {
            if (pop.id !== id) pop.classList.add('hidden');
        });
        // Toggle celui cliqué
        const popover = document.getElementById(id);
        if (popover) popover.classList.toggle('hidden');
    }
    // Ferme le popover si on clique ailleurs
    document.addEventListener('click', function(e) {
        document.querySelectorAll('[id^="popover-"]').forEach(function(pop) {
            pop.classList.add('hidden');
        });
    }, true);

    function updateResume() {
        let selectedTotal = 0;
        let selectedCount = 0;
        document.querySelectorAll('.fiche-checkbox:checked').forEach(cb => {
            const card = cb.closest('.bg-white');
            const amountNode = card ? card.querySelector('.text-green-600') : null;
            const amount = amountNode ? parseFloat(amountNode.textContent.replace(/\s|CFA/g, '').replace(',', '.')) : 0;
            selectedTotal += Number.isFinite(amount) ? amount : 0;
            selectedCount++;
        });

        let visibleTotal = 0;
        const visibleCards = [...document.querySelectorAll('#cards-container > .bg-white')];
        visibleCards.forEach(card => {
            const amountNode = card.querySelector('.text-green-600');
            const amount = amountNode ? parseFloat(amountNode.textContent.replace(/\s|CFA/g, '').replace(',', '.')) : 0;
            visibleTotal += Number.isFinite(amount) ? amount : 0;
        });

        document.getElementById('nbFiches').textContent = selectedCount || visibleCards.length;
        document.getElementById('totalMontant').textContent = (selectedCount ? selectedTotal : visibleTotal).toLocaleString('fr-FR');
    }
    document.querySelectorAll('.fiche-checkbox').forEach(cb => {
        cb.addEventListener('change', function() {
            const card = cb.closest('.bg-white');
            if (cb.checked) {
                card.classList.add('fiche-selected');
            } else {
                card.classList.remove('fiche-selected');
            }
            updateResume();
        });
    });
    document.getElementById('resetBtn').onclick = function() {
        document.getElementById('searchForm').reset();
    };
    // Helpers
    const isDemo = (typeof getQueryParam === 'function' && getQueryParam('demo') === '1');

    function setLoading(state) {
        const wrap = document.getElementById('cards-container');
        if (!state) return (wrap.querySelectorAll('.skeleton').forEach(el => el.remove()), null);
        wrap.innerHTML = '';
        const count = 8;
        for (let i = 0; i < count; i++) {
            const sk = document.createElement('div');
            sk.className = 'card p-6 bg-white rounded-2xl shadow skeleton';
            sk.style.height = '220px';
            wrap.appendChild(sk);
        }
    }

    // AJAX search
    document.getElementById('searchForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        const params = new URLSearchParams(new FormData(this));
        const endpoint = 'request/fiches_conformite_list.php?' + params.toString();
        setLoading(true);
        const res = await fetch(endpoint);
        const json = await res.json();
        if (!json.ok) {
            alert('Erreur: ' + (json.error || ''));
            return;
        }
        document.getElementById('nbFiches').textContent = json.count;
        document.getElementById('totalMontant').textContent = (json.total || 0).toLocaleString('fr-FR');
        const wrap = document.getElementById('cards-container');
        wrap.innerHTML = json.items.map(item => cardTemplate(item)).join('');
        bindCardEvents(wrap);
        setLoading(false);
    });

    function getCurrentFiltersQS() {
        const fd = new FormData(document.getElementById('searchForm'));
        const params = new URLSearchParams(fd);
        // Ne pas inclure les vides
        [...params.keys()].forEach(k => {
            if (!params.get(k)) params.delete(k);
        });
        return params.toString();
    }

    function getSelectedNums() {
        const nums = [];
        document.querySelectorAll('.fiche-checkbox:checked').forEach(cb => nums.push(cb.value));
        return nums;
    }

    async function postSelected(url, selected) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = url;
        form.style.display = 'none';
        const inp = document.createElement('input');
        inp.type = 'hidden';
        inp.name = 'selected';
        inp.value = selected.join(',');
        form.appendChild(inp);
        document.body.appendChild(form);
        form.submit();
        setTimeout(() => form.remove(), 2000);
    }

    // Exports globaux (selon filtres courants)
    document.getElementById('exportAllPdf').addEventListener('click', function() {
        const qs = getCurrentFiltersQS();
        const url = 'request/export_fiches_conformite_pdf.php' + (qs ? ('?' + qs) : '');
        window.open(url, '_blank');
    });
    document.getElementById('exportAllExcel').addEventListener('click', function() {
        const qs = getCurrentFiltersQS();
        const url = 'request/export_fiches_conformite_excel.php' + (qs ? ('?' + qs) : '');
        // Soumission GET pour téléchargement direct
        window.location.href = url;
    });

    // Exports de la sélection
    document.getElementById('exportSelectedPdf').addEventListener('click', function() {
        const nums = getSelectedNums();
        if (!nums.length) return alert('Sélectionnez au moins une fiche.');
        postSelected('request/export_fiches_conformite_pdf.php', nums);
    });
    document.getElementById('exportSelectedExcel').addEventListener('click', function() {
        const nums = getSelectedNums();
        if (!nums.length) return alert('Sélectionnez au moins une fiche.');
        postSelected('request/export_fiches_conformite_excel.php', nums);
    });

    function openRejectModal(num) {
        document.getElementById('rej-num').value = num;
        document.getElementById('rej-label').textContent = num;
        document.getElementById('rej-reason').value = '';
        rejectModal.style.display = 'block';
        document.getElementById('rej-reason').focus();
    }

    document.getElementById('rej-submit').addEventListener('click', async function() {
        const num = document.getElementById('rej-num').value;
        const motif = document.getElementById('rej-reason').value.trim();
        if (!motif) {
            alert('Motif requis');
            return;
        }
        try {
            const resp = await fetch('request/refuser_fiche_decaissement.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: new URLSearchParams({
                    num_fiche: num,
                    motif
                })
            });
            const data = await resp.json();
            if (data.ok) {
                const card = [...document.querySelectorAll('[data-num]')].find(node => node.getAttribute('data-num') === num)?.closest('.bg-white');
                card?.parentNode?.removeChild(card);
                rejectModal.style.display = 'none';
                updateResume();
                showMiniToast('Fiche ' + num + ' refusée');
            } else {
                alert(data.error || 'Erreur refus');
            }
        } catch (e) {
            alert('Erreur réseau: ' + e.message);
        }
    });

    function cardTemplate(f) {
        const photo = f.photo ? `<img src="${f.photo}" class="w-20 h-20 rounded-full object-cover border-4 border-indigo-400 shadow-md bg-gray-100">` :
            `<div class=\"w-20 h-20 flex items-center justify-center rounded-full border-4 border-indigo-400 shadow-md bg-gray-100\"><svg class=\"w-12 h-12 text-gray-400\" fill=\"currentColor\" viewBox=\"0 0 24 24\"><path d=\"M12 12c2.7 0 4.5-2.1 4.5-4.5S14.7 3 12 3 7.5 5.1 7.5 7.5 9.3 12 12 12zm0 2c-3 0-9 1.5-9 4.5V21h18v-2.5c0-3-6-4.5-9-4.5z\" /></svg></div>`;
        return `
            <div class="bg-white rounded-2xl shadow-xl hover:shadow-2xl transition-all flex flex-col justify-between p-6 relative group card">
                <div class="absolute top-4 left-4">
                    <span class="card-badge-analyse" data-num="${f.num_fiche}" style="display:inline-flex;align-items:center;justify-content:center;width:38px;height:38px;border:2px solid #e2e8f0;border-radius:50%;background:#fff;font-size:11px;color:#64748b;font-weight:600;box-shadow:0 2px 4px rgba(0,0,0,.08);" title="Analyse budget">...</span>
                </div>
                <span class="absolute top-4 right-4 bg-indigo-100 text-indigo-700 px-3 py-1 rounded-full text-xs font-semibold shadow">À certifier</span>
                ${f.contract_id ? '<span class="absolute right-4 top-12 rounded-full bg-amber-100 px-3 py-1 text-xs font-black text-amber-900">FEB contrat</span>' : ''}

                <div class="flex justify-center mb-4">${photo}</div>

                <h2 class="text-xl font-semibold text-center mb-1 text-gray-800">${escapeHtml(f.beficiaire_fiche || '')}</h2>

                <div class="flex items-center justify-center mb-1">
                    <span class="text-gray-500 text-sm">Fiche N° ${escapeHtml(f.num_fiche || '')}</span>
                </div>

                <p class="text-green-600 font-bold text-lg text-center mb-2">${currencyFormatCFA(f.montant_fiche)}</p>

                <div class="text-sm text-gray-700 mb-4 text-center">
                    <p><strong>Ligne déboursé :</strong> ${escapeHtml(f.ligne_debourse_label || '')}</p>
                    <p class="mt-2 rounded-lg bg-indigo-50 px-2 py-1 text-indigo-800"><strong>Planning :</strong> ${formatPlanningDate(f.planned_start_date)} → ${formatPlanningDate(f.planned_end_date)}</p>
                    <p><strong>Ligne devis :</strong> ${escapeHtml(f.ligne_devis_label || '')}</p>
                    <p><strong>Précision :</strong> ${escapeHtml(f.precision || '')}</p>
                    <p><strong>Désignation :</strong> ${escapeHtml(f.designation || '')}</p>
                    <p class="text-xs text-gray-500">${escapeHtml(f.ligne_categorie || '')}</p>
                </div>

                <div class="flex justify-between items-center mt-auto">
                    <span class="text-xs text-gray-400">${formatDateFR(f.date_creat_fiche)}</span>
                    <div class="flex gap-1">
                        <button data-num="${f.num_fiche}" class="js-analyse px-2 py-1 rounded bg-amber-500 hover:bg-amber-600 text-white text-xs">Aide</button>
                        <button data-num="${f.num_fiche}" class="js-refuse px-2 py-1 rounded bg-red-600 hover:bg-red-700 text-white text-xs">Refuser</button>
                        <button data-num="${f.num_fiche}" data-requires-invoice="${f.feb_requires_invoice_before_conformity ? '1' : '0'}" data-facture="${escapeHtml(f.facture_reference || '')}" class="js-certify btn btn-primary text-xs">Certifier</button>
                    </div>
                </div>

                <input type="checkbox" name="fiche_select[]" value="${f.num_fiche}" class="absolute top-4 left-1 z-20 w-5 h-5 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500 fiche-checkbox">
            </div>`;
    }

    function formatPlanningDate(value) {
        if (!value) return 'Non défini';
        const parts = String(value).slice(0, 10).split('-');
        return parts.length === 3 ? `${parts[2]}/${parts[1]}/${parts[0]}` : escapeHtml(value);
    }

    function bindCardEvents(scope = document) {
        scope.querySelectorAll('.fiche-checkbox').forEach(cb => cb.addEventListener('change', updateResume));
        scope.querySelectorAll('.js-certify').forEach(btn => btn.addEventListener('click', () => handleCertify(btn)));
        scope.querySelectorAll('.js-refuse').forEach(btn => btn.addEventListener('click', () => openRejectModal(btn.getAttribute('data-num'))));
        scope.querySelectorAll('.js-analyse').forEach(btn => btn.addEventListener('click', () => fetchAnalyse(btn.getAttribute('data-num'))));
        loadAnalysesForCards(scope);
    }

    function analyseIconMapping(niveau) {
        switch (niveau) {
            case 'large_marge':
                return {
                    bg: '#ecfdf5', border: '#059669', color: '#047857', html: '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>', label: 'Marge large'
                };
            case 'marge':
                return {
                    bg: '#eff6ff', border: '#2563eb', color: '#1d4ed8', html: '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>', label: 'Marge'
                };
            case 'limite':
                return {
                    bg: '#fff7ed', border: '#f59e0b', color: '#b45309', html: '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/></svg>', label: 'Limite'
                };
            case 'depasse_legere':
                return {
                    bg: '#fef3c7', border: '#d97706', color: '#b45309', html: '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/></svg>', label: 'Dépasse légère'
                };
            case 'depasse_forte':
                return {
                    bg: '#fef2f2', border: '#dc2626', color: '#b91c1c', html: '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M15 9l-6 6"/><path d="M9 9l6 6"/></svg>', label: 'Dépasse forte'
                };
            default:
                return {
                    bg: '#f1f5f9', border: '#94a3b8', color: '#475569', html: '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>', label: 'Indéterminé'
                };
        }
    }

    async function loadAnalysesForCards(scope = document) {
        const badges = [...scope.querySelectorAll('.card-badge-analyse:not([data-loaded])')];
        if (!badges.length) return;
        // Limiter la concurrence
        const pool = 4;
        let index = 0;
        async function worker() {
            while (index < badges.length) {
                const el = badges[index++];
                const num = el.getAttribute('data-num');
                try {
                    const r = await fetch('request/analyse_fiche_budget.php?num_fiche=' + encodeURIComponent(num));
                    const j = await r.json();
                    if (j.ok) {
                        const a = j.analyse || {};
                        const map = analyseIconMapping(a.niveau);
                        el.style.background = map.bg;
                        el.style.borderColor = map.border;
                        el.style.color = map.color;
                        el.innerHTML = map.html;
                        const rPct = a && a.ratio != null ? (a.ratio * 100).toFixed(1) + '%' : '?';
                        el.title = map.label + ` | Ratio: ${rPct}\nMontant fiche: ${(a.montant_fiche||0).toLocaleString('fr-FR')} CFA\nProjection: ${(a.projection_decaisse||0).toLocaleString('fr-FR')} CFA`;
                    } else {
                        el.textContent = '!';
                        el.style.background = '#fef2f2';
                        el.style.color = '#dc2626';
                        el.title = 'Erreur analyse';
                    }
                } catch (e) {
                    el.textContent = '?';
                    el.style.background = '#f1f5f9';
                    el.title = 'Indisponible';
                } finally {
                    el.setAttribute('data-loaded', '1');
                }
            }
        }
        const workers = [];
        for (let i = 0; i < pool; i++) workers.push(worker());
        await Promise.all(workers);
    }
    async function handleCertify(btn) {
        const num = btn.getAttribute('data-num');
        if (!num) return;
        const factureReference = (btn.getAttribute('data-facture') || '').trim();
        btn.disabled = true;
        btn.classList.add('opacity-60');
        try {
            const resp = await fetch('request/certifier_fiche_decaissement.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: new URLSearchParams({
                    num_fiche: num,
                    facture_reference: factureReference
                })
            });
            const data = await resp.json();
            if (data.ok) {
                const card = btn.closest('.bg-white');
                card?.parentNode?.removeChild(card);
                updateResume();
                showMiniToast('Fiche ' + num + ' certifiée');
            } else {
                alert('Échec: ' + (data.error || 'inconnu'));
            }
        } catch (e) {
            alert('Erreur réseau: ' + e.message);
        } finally {
            btn.disabled = false;
            btn.classList.remove('opacity-60');
        }
    }

    function showMiniToast(msg) {
        let box = document.getElementById('mini-toast-box');
        if (!box) {
            box = document.createElement('div');
            box.id = 'mini-toast-box';
            box.style.position = 'fixed';
            box.style.top = '12px';
            box.style.right = '12px';
            box.style.zIndex = '9999';
            document.body.appendChild(box);
        }
        const t = document.createElement('div');
        t.textContent = msg;
        t.style.background = '#16a34a';
        t.style.color = '#fff';
        t.style.padding = '8px 14px';
        t.style.marginTop = '6px';
        t.style.borderRadius = '6px';
        t.style.boxShadow = '0 2px 6px rgba(0,0,0,.2)';
        t.style.fontSize = '13px';
        box.appendChild(t);
        setTimeout(() => {
            t.style.opacity = '0';
            t.style.transition = 'opacity .5s';
            setTimeout(() => t.remove(), 600);
        }, 2500);
    }

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
        } catch (e) {
            return iso;
        }
    }

    bindCardEvents(document);
    // Chargement initial des analyses rapides
    loadAnalysesForCards(document);
    if (isDemo && typeof showToast === 'function') {
        document.getElementById('searchForm').dispatchEvent(new Event('submit'));
        showToast('Mode démo: données factices chargées', 'warn');
    }
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
