<?php
session_start();
require_once __DIR__ . '/../auth_check.php';
$pdo = null;
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/AuditTrail.php';

$date = $_GET['date'] ?? null;
if (!$date) {
    header('Location: depenses_planifiees.php');
    exit;
}

$pageTitle = 'Détails Dépenses Planifiées - ' . date('d/m/Y', strtotime($date));
$activeMenu = 'depenses_planifiees';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';

$pdo = Database::getConnection();
AuditTrail::log('page_view', [
    'module' => 'decaissement',
    'action' => 'depenses_planifiees_detail_view',
    'entity_type' => 'fiche_depenses_planifiees',
    'entity_label' => 'Détails dépenses planifiées',
    'page_name' => 'depenses_planifiees_detail.php',
    'result' => 'success',
    'details' => [
        'date' => $date,
    ],
], $pdo);

// Get fiches for this date
$query = "
    SELECT f.*, 
           c.titre as chantier_banamur,
           ldb.designation as ligne_debourse_designation,
           ldb.categorie as ligne_debourse_categorie,
           ldb.montant as ligne_debourse_montant,
           db.montant_debourse as debourse_montant,
           ld.designation as ligne_devis_designation,
           ld.total as ligne_devis_montant,
           offre.reference_offre as devis_titre,
           d.total_ttc as devis_montant_total
    FROM fiche_decaissement f
    LEFT JOIN chantier_banamur c ON f.chantier_id = c.id
    LEFT JOIN ligne_debourse_banamur ldb ON f.ligne_debourse_id = ldb.id
    LEFT JOIN debourse_banamur db ON ldb.debourse_id = db.id
    LEFT JOIN ligne_devis_banamur ld ON db.ligne_devis_id = ld.id
    LEFT JOIN devis_banamur d ON ld.devis_id = d.id
    LEFT JOIN offre_banamur offre ON d.offre_id = offre.id_offre
    WHERE f.date_decaissement_minimum = ?
    AND (f.chantier_id IS NULL OR c.archived_at IS NULL)
    AND f.decaisse = 0
    AND f.etat_fiche != 2
    ORDER BY f.num_fiche ASC
";

$stmt = $pdo->prepare($query);
$stmt->execute([$date]);
$fiches = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total = array_sum(array_column($fiches, 'montant_fiche'));
$count = count($fiches);

?>
<style>
    .card {
        border-radius: 1rem;
    }

    /* Styles du modal comme dans point_financier.php */
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

    /* Styles pour les panels */
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
</style>
<div class="container mx-auto py-10 px-4">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-3xl font-bold text-indigo-700">Détails Dépenses Planifiées</h1>
            <p class="text-gray-600">Date: <?php echo date('d/m/Y', strtotime($date)); ?> - Total: <?php echo number_format($total, 0, ',', ' '); ?> CFA - <?php echo $count; ?> fiche(s)</p>
        </div>
        <div class="flex gap-2">
            <button onclick="window.location.href='depenses_planifiees.php'" class="btn btn-secondary">← Retour</button>
            <button onclick="window.open('request/export_date_recap.php?date=<?php echo $date; ?>', '_blank')" class="btn btn-success">Exporter</button>
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
        <?php foreach ($fiches as $fiche): ?>
            <div class="card p-6 border hover:shadow-lg transition-shadow cursor-pointer" data-fiche='<?php echo json_encode($fiche); ?>' onclick="openFicheModal(this)">
                <div class="flex justify-between items-start mb-4">
                    <h3 class="font-bold text-lg text-indigo-700"><?php echo htmlspecialchars($fiche['num_fiche']); ?></h3>
                    <span class="text-xs bg-indigo-100 text-indigo-800 px-2 py-1 rounded"><?php echo $fiche['date_decaissement_minimum'] < date('Y-m-d') ? 'En retard' : 'Planifié'; ?></span>
                </div>
                <div class="space-y-2">
                    <p class="text-2xl font-bold text-green-600"><?php echo number_format($fiche['montant_fiche'], 0, ',', ' '); ?> CFA</p>

                    <!-- Détails Chantier -->
                    <div class="border-t pt-2 mt-2">
                        <p class="text-sm font-semibold text-gray-700 mb-1">Chantier</p>
                        <p class="text-sm text-gray-600"><?php echo htmlspecialchars($fiche['chantier_banamur'] ?: 'N/A'); ?></p>
                    </div>

                    <!-- Détails Ligne Déboursé -->
                    <?php if ($fiche['ligne_debourse_designation']): ?>
                        <div class="border-t pt-2 mt-2">
                            <p class="text-sm font-semibold text-gray-700 mb-1">Ligne Déboursé</p>
                            <p class="text-sm text-gray-600"><strong>Catégorie:</strong> <?php echo htmlspecialchars($fiche['ligne_debourse_categorie'] ?: 'N/A'); ?></p>
                            <p class="text-sm text-gray-600"><strong>Désignation:</strong> <?php echo htmlspecialchars($fiche['ligne_debourse_designation']); ?></p>
                            <p class="text-sm text-gray-600"><strong>Montant prévu:</strong> <?php echo number_format($fiche['ligne_debourse_montant'] ?? 0, 0, ',', ' '); ?> CFA</p>
                        </div>
                    <?php endif; ?>

                    <!-- Détails Déboursé -->
                    <?php if ($fiche['debourse_montant']): ?>
                        <div class="border-t pt-2 mt-2">
                            <p class="text-sm font-semibold text-gray-700 mb-1">Déboursé</p>
                            <p class="text-sm text-gray-600"><strong>Montant total:</strong> <?php echo number_format($fiche['debourse_montant'], 0, ',', ' '); ?> CFA</p>
                        </div>
                    <?php endif; ?>

                    <!-- Détails Ligne Devis -->
                    <?php if ($fiche['ligne_devis_designation']): ?>
                        <div class="border-t pt-2 mt-2">
                            <p class="text-sm font-semibold text-gray-700 mb-1">Ligne Devis</p>
                            <p class="text-sm text-gray-600"><strong>Désignation:</strong> <?php echo htmlspecialchars($fiche['ligne_devis_designation']); ?></p>
                            <p class="text-sm text-gray-600"><strong>Montant:</strong> <?php echo number_format($fiche['ligne_devis_montant'] ?? 0, 0, ',', ' '); ?> CFA</p>
                        </div>
                    <?php endif; ?>

                    <!-- Détails Devis -->
                    <?php if ($fiche['devis_titre']): ?>
                        <div class="border-t pt-2 mt-2">
                            <p class="text-sm font-semibold text-gray-700 mb-1">Devis</p>
                            <p class="text-sm text-gray-600"><strong>Titre:</strong> <?php echo htmlspecialchars($fiche['devis_titre']); ?></p>
                            <p class="text-sm text-gray-600"><strong>Montant total:</strong> <?php echo number_format($fiche['devis_montant_total'] ?? 0, 0, ',', ' '); ?> CFA</p>
                        </div>
                    <?php endif; ?>

                    <!-- Informations générales -->
                    <div class="border-t pt-2 mt-2">
                        <p class="text-sm text-gray-600"><strong>Bénéficiaire:</strong> <?php echo htmlspecialchars($fiche['beficiaire_fiche'] ?: 'N/A'); ?></p>
                        <p class="text-sm text-gray-600"><strong>Création:</strong> <?php echo date('d/m/Y', strtotime($fiche['date_creat_fiche'])); ?></p>
                        <p class="text-sm text-gray-600"><strong>Désignation:</strong> <?php echo htmlspecialchars($fiche['designation_fiche'] ?: 'N/A'); ?></p>
                        <p class="text-sm text-gray-600"><strong>Précision:</strong> <?php echo htmlspecialchars($fiche['precision_fiche'] ?: 'N/A'); ?></p>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Modal pour les détails de fiche -->
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
                    <!-- Informations principales -->
                    <div class="pf-panel">
                        <h3 class="pf-panel-title">Informations générales</h3>
                        <div id="ficheGeneralInfo"></div>
                    </div>

                    <!-- Chaîne hiérarchique -->
                    <div class="pf-panel" style="margin-top: 16px;">
                        <h3 class="pf-panel-title">Chaîne hiérarchique</h3>
                        <div id="ficheHierarchyInfo"></div>
                    </div>
                </div>

                <div>
                    <!-- Montant -->
                    <div class="pf-panel">
                        <h3 class="pf-panel-title">Montant</h3>
                        <div class="text-center py-4">
                            <div id="ficheAmount" class="pf-amount"></div>
                            <div class="text-xs text-gray-500 mt-1">FCFA</div>
                        </div>
                    </div>

                    <!-- Statut -->
                    <div class="pf-panel" style="margin-top: 16px;">
                        <h3 class="pf-panel-title">Statut</h3>
                        <div id="ficheStatusInfo"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    function openFicheModal(element) {
        const fiche = JSON.parse(element.getAttribute('data-fiche'));
        console.log('openFicheModal called with:', fiche);
        // Remplir les informations générales
        const generalInfo = document.getElementById('ficheGeneralInfo');
        generalInfo.innerHTML = `
            <div class="pf-key-value">
                <span class="pf-key">N° Fiche:</span>
                <span class="pf-value">${fiche.num_fiche || 'N/A'}</span>
            </div>
            <div class="pf-key-value">
                <span class="pf-key">Bénéficiaire:</span>
                <span class="pf-value">${fiche.beficiaire_fiche || 'N/A'}</span>
            </div>
            <div class="pf-key-value">
                <span class="pf-key">Désignation:</span>
                <span class="pf-value">${fiche.designation_fiche || 'N/A'}</span>
            </div>
            <div class="pf-key-value">
                <span class="pf-key">Précision:</span>
                <span class="pf-value">${fiche.precision_fiche || 'N/A'}</span>
            </div>
            <div class="pf-key-value">
                <span class="pf-key">Date création:</span>
                <span class="pf-value">${fiche.date_creat_fiche ? new Date(fiche.date_creat_fiche).toLocaleDateString('fr-FR') : 'N/A'}</span>
            </div>
        `;

        // Remplir la chaîne hiérarchique
        const hierarchyInfo = document.getElementById('ficheHierarchyInfo');
        hierarchyInfo.innerHTML = `
            <div class="pf-key-value">
                <span class="pf-key">Chantier:</span>
                <span class="pf-value">${fiche.chantier_banamur || 'N/A'}</span>
            </div>
            ${fiche.ligne_debourse_designation ? `
            <div class="pf-key-value">
                <span class="pf-key">Ligne Déboursé:</span>
                <span class="pf-value">${fiche.ligne_debourse_designation} (${fiche.ligne_debourse_categorie || 'N/A'})</span>
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
                <span class="pf-value">${fiche.ligne_devis_designation}</span>
            </div>
            <div class="pf-key-value">
                <span class="pf-key">Montant devis:</span>
                <span class="pf-value">${fiche.ligne_devis_montant ? new Intl.NumberFormat('fr-FR').format(fiche.ligne_devis_montant) + ' CFA' : 'N/A'}</span>
            </div>
            ` : ''}
            ${fiche.devis_titre ? `
            <div class="pf-key-value">
                <span class="pf-key">Devis:</span>
                <span class="pf-value">${fiche.devis_titre}</span>
            </div>
            <div class="pf-key-value">
                <span class="pf-key">Total devis:</span>
                <span class="pf-value">${fiche.devis_montant_total ? new Intl.NumberFormat('fr-FR').format(fiche.devis_montant_total) + ' CFA' : 'N/A'}</span>
            </div>
            ` : ''}
        `;

        // Remplir le montant
        const amountEl = document.getElementById('ficheAmount');
        amountEl.textContent = fiche.montant_fiche ? new Intl.NumberFormat('fr-FR').format(fiche.montant_fiche) : '0';

        // Remplir le statut
        const statusInfo = document.getElementById('ficheStatusInfo');
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
        `;

        // Ouvrir le modal
        document.getElementById('ficheDetailModal').classList.add('is-open');
        document.getElementById('ficheDetailModal').setAttribute('aria-hidden', 'false');
        console.log('Modal opened');
    }

    function closeFicheModal() {
        document.getElementById('ficheDetailModal').classList.remove('is-open');
        document.getElementById('ficheDetailModal').setAttribute('aria-hidden', 'true');
    }

    // Fermer le modal en cliquant sur l'arrière-plan
    document.getElementById('ficheDetailModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeFicheModal();
        }
    });

    // Fermer avec la touche Échap
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && document.getElementById('ficheDetailModal').classList.contains('is-open')) {
            closeFicheModal();
        }
    });
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
