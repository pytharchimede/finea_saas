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
require_once __DIR__ . '/includes/fiche_label_helper.php';
require_once __DIR__.'/../services/SupplierApprovalGuard.php';

// Résolution d'URL photo bénéficiaire (multi-emplacements)
function resolvePhotoUrl($filename)
{
    $filename = trim((string)$filename);
    if ($filename === '') return null;
    $baseRoot = realpath(__DIR__ . '/..');
    $basename = basename($filename);
    $candidates = [
        [$baseRoot . '/uploads/photo/' . $basename, '../uploads/photo/' . rawurlencode($basename)],
        [__DIR__ . '/uploads/photo/' . $basename, 'uploads/photo/' . rawurlencode($basename)],
        [$baseRoot . '/' . ltrim($filename, '/'), '../' . ltrim($filename, '/')],
    ];
    foreach ($candidates as [$abs, $url]) {
        if (is_file($abs)) return $url;
    }
    return null;
}

// Fonction pour afficher le label d'affectation
function getAffectationLabel($fiche)
{
    if (!empty($fiche['chantier_id'])) {
        return '<span class="inline-block bg-blue-100 text-blue-700 px-2 py-0.5 rounded text-xs font-semibold mr-1">Chantier</span>';
    } elseif (!empty($fiche['serv_bureau_banamur_id'])) {
        return '<span class="inline-block bg-purple-100 text-purple-700 px-2 py-0.5 rounded text-xs font-semibold mr-1">Bureau</span>';
    } else {
        return '<span class="inline-block bg-gray-100 text-gray-700 px-2 py-0.5 rounded text-xs font-semibold mr-1">Autre</span>';
    }
}

function userCanAction($fiche)
{
    return true;
}

$dataBaseObj = new Database();
$pdo = $dataBaseObj->getConnection();
$ficheObj = new FicheDecaissement($pdo);
$precisionObj = new Precision($pdo);
$designationObj = new Designation($pdo);
$debourseObj = new Debourse($pdo);
$ligneDebourseObj = new LigneDebourse($pdo);
$canAdjustBeneficiarySupplier = AccessManager::hasPermission('ajuster_beneficiaire_fournisseur_fiche');

// Corriger uniquement les montants des fiches FEB encore en attente. Une lecture
// ciblée et au plus 20 mises à jour par ouverture protègent la base en production.
try {
    $febCandidates = $pdo->query("SELECT f.id_fiche,f.num_fiche,f.feb_request_id,f.montant_fiche,f.montant_initial_fiche,
            CAST(JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.montant')) AS DECIMAL(18,2)) feb_amount
        FROM fiche_decaissement f
        JOIN employee_legal_requests r ON r.id=f.feb_request_id
        WHERE f.conforme=1 AND f.decaisse=0 AND f.approuve=0
          AND (f.montant_initial_fiche IS NULL OR f.montant_initial_fiche<=f.montant_fiche)
          AND r.request_type='feb' AND r.status='approved'
          AND CAST(JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.montant')) AS DECIMAL(18,2))>0
          AND (ABS(f.montant_fiche-CAST(JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.montant')) AS DECIMAL(18,2)))>=0.005
            OR f.montant_initial_fiche IS NULL
            OR ABS(f.montant_initial_fiche-CAST(JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.montant')) AS DECIMAL(18,2)))>=0.005)
        ORDER BY f.id_fiche LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
    $updateAmount = $pdo->prepare("UPDATE fiche_decaissement f
        JOIN employee_legal_requests r ON r.id=f.feb_request_id AND r.request_type='feb' AND r.status='approved'
        SET f.montant_fiche=CAST(JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.montant')) AS DECIMAL(18,2)),
            f.montant_initial_fiche=CAST(JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.montant')) AS DECIMAL(18,2))
        WHERE f.id_fiche=:id AND f.feb_request_id=:feb AND f.decaisse=0 AND f.approuve=0
          AND (f.montant_initial_fiche IS NULL OR f.montant_initial_fiche<=f.montant_fiche)
          AND CAST(JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.montant')) AS DECIMAL(18,2))>0");
    $familyCount = $pdo->prepare('SELECT COUNT(*) FROM fiche_decaissement WHERE feb_request_id=:feb');
    $children = $pdo->prepare('SELECT 1 FROM fiche_decaissement WHERE id_fiche<>:id AND (parent_num_fiche=:num1 OR code_parentale=:num2) LIMIT 1');
    foreach ($febCandidates as $febFiche) {
        $amount = (float)$febFiche['feb_amount'];
        if ($amount <= 0) continue;
        $familyCount->execute(['feb' => $febFiche['feb_request_id']]);
        if ((int)$familyCount->fetchColumn() !== 1) continue;
        $children->execute(['id' => $febFiche['id_fiche'], 'num1' => $febFiche['num_fiche'], 'num2' => $febFiche['num_fiche']]);
        if ($children->fetchColumn()) continue;
        $updateAmount->execute(['id' => $febFiche['id_fiche'], 'feb' => $febFiche['feb_request_id']]);
    }
} catch (Throwable $syncScanError) {
    error_log('FEB fiche amount refresh: ' . $syncScanError->getMessage());
}

$where = ["f.conforme = 1"];
$statut = $_GET['statut'] ?? 'attente';
if ($statut === 'decaissees' && $canAdjustBeneficiarySupplier) {
    $where[] = 'f.decaisse = 1';
    $where[] = 'f.approuve = 1';
} elseif ($statut === 'refusees') {
    $where[] = 'f.decaisse = 0';
    $where[] = 'f.approuve = 2';
} elseif ($statut === 'approuvees' && $canAdjustBeneficiarySupplier) {
    $where[] = 'f.decaisse = 0';
    $where[] = 'f.approuve = 1';
} elseif ($statut === 'tous') {
    $where[] = 'f.decaisse = 0';
    $where[] = 'f.approuve IN (0,2)';
} else {
    $where[] = 'f.decaisse = 0';
    $where[] = 'f.approuve = 0';
}
$params = [];
if (!empty($_GET['num_fiche'])) {$where[] = 'f.num_fiche LIKE :num_fiche';$params['num_fiche'] = '%' . $_GET['num_fiche'] . '%';}
if (!empty($_GET['beficiaire_fiche'])) {$where[] = 'f.beficiaire_fiche LIKE :benef';$params['benef'] = '%' . $_GET['beficiaire_fiche'] . '%';}
if (!empty($_GET['designation'])) {$where[] = 'f.designation_fiche LIKE :designation';$params['designation'] = '%' . $_GET['designation'] . '%';}
if (!empty($_GET['precision'])) {$where[] = 'f.precision_fiche LIKE :precision';$params['precision'] = '%' . $_GET['precision'] . '%';}
if (!empty($_GET['montant_min'])) {$where[] = 'f.montant_fiche >= :montant_min';$params['montant_min'] = (float)$_GET['montant_min'];}
if (!empty($_GET['montant_max'])) {$where[] = 'f.montant_fiche <= :montant_max';$params['montant_max'] = (float)$_GET['montant_max'];}
if (!empty($_GET['date_min'])) {$where[] = 'f.date_creat_fiche >= :date_min';$params['date_min'] = $_GET['date_min'] . ' 00:00:00';}
if (!empty($_GET['date_max'])) {$where[] = 'f.date_creat_fiche <= :date_max';$params['date_max'] = $_GET['date_max'] . ' 23:59:59';}
if (!empty($_GET['chantier'])) {$where[] = '(c.titre LIKE :chantier OR c.code LIKE :chantier)';$params['chantier'] = '%' . $_GET['chantier'] . '%';}
if (!empty($_GET['affectation'])) {
    if ($_GET['affectation'] === 'chantier') $where[] = 'f.chantier_id IS NOT NULL';
    elseif ($_GET['affectation'] === 'bureau') $where[] = 'f.serv_bureau_banamur_id IS NOT NULL';
    elseif ($_GET['affectation'] === 'autre') $where[] = '(f.chantier_id IS NULL AND f.serv_bureau_banamur_id IS NULL)';
}
$where[]='(f.chantier_id IS NULL OR c.archived_at IS NULL)';
$sql = "SELECT f.*, c.titre AS chantier_titre, c.code AS chantier_code, ldb.designation AS ligne_designation, ldb.categorie AS ligne_categorie,
    ld.designation AS devis_designation
        FROM fiche_decaissement f
        LEFT JOIN chantier_banamur c ON c.id = f.chantier_id
        LEFT JOIN ligne_debourse_banamur ldb ON ldb.id = f.ligne_debourse_id
        LEFT JOIN debourse_banamur db ON db.id = ldb.debourse_id
        LEFT JOIN ligne_devis_banamur ld ON ld.id = db.ligne_devis_id
        WHERE " . implode(' AND ', $where) . " ORDER BY f.date_creat_fiche DESC";
$st = $pdo->prepare($sql);
$st->execute($params);
$fiches = array_map('enrich_fiche_labels', $st->fetchAll(PDO::FETCH_ASSOC));

AuditTrail::log('page_view', [
    'module' => 'decaissement',
    'action' => 'liste_fiches_approbation_view',
    'entity_type' => 'fiche_approbation_list',
    'entity_label' => 'Liste fiches approbation',
    'page_name' => 'liste_fiches_approbation.php',
    'result' => 'success',
    'details' => [
        'statut' => $statut,
        'row_count' => is_countable($fiches ?? null) ? count($fiches) : null,
        'filters' => [
            'num_fiche' => $_GET['num_fiche'] ?? null,
            'beficiaire_fiche' => $_GET['beficiaire_fiche'] ?? null,
            'designation' => $_GET['designation'] ?? null,
            'precision' => $_GET['precision'] ?? null,
            'chantier' => $_GET['chantier'] ?? null,
            'affectation' => $_GET['affectation'] ?? null,
        ],
    ],
], $pdo);
?>
<?php
$pageTitle = "Fiches en attente d'approbation";
if ($statut === 'refusees') $pageTitle = 'Fiches refusées';
elseif ($statut === 'tous') $pageTitle = 'Fiches (en attente & refusées)';
$activeMenu = 'approbation';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<div class="container mx-auto py-10 px-4">
    <h1 class="text-3xl font-bold text-center mb-10 text-indigo-700 drop-shadow"><?= htmlspecialchars($pageTitle); ?></h1>
    <form id="searchForm" method="get" class="mb-8 bg-white rounded-xl shadow p-6 flex flex-wrap gap-4 items-end">
        <div><label class="block text-xs font-semibold text-gray-600 mb-1">N° Fiche</label><input type="text" name="num_fiche" value="<?= htmlspecialchars($_GET['num_fiche'] ?? '') ?>" class="form-input rounded border-gray-300 w-36"></div>
        <div><label class="block text-xs font-semibold text-gray-600 mb-1">Statut</label><?php $stSel = $_GET['statut'] ?? 'attente'; ?><select name="statut" class="form-select rounded border-gray-300 w-40"><option value="attente" <?= $stSel === 'attente' ? 'selected' : ''; ?>>En attente</option><option value="refusees" <?= $stSel === 'refusees' ? 'selected' : ''; ?>>Refusées</option><?php if ($canAdjustBeneficiarySupplier): ?><option value="approuvees" <?= $stSel === 'approuvees' ? 'selected' : ''; ?>>Approuvées à ajuster</option><option value="decaissees" <?= $stSel === 'decaissees' ? 'selected' : ''; ?>>Décaissées à ajuster</option><?php endif; ?><option value="tous" <?= $stSel === 'tous' ? 'selected' : ''; ?>>Tous (0 & 2)</option></select></div>
        <div><label class="block text-xs font-semibold text-gray-600 mb-1">Affectation</label><select name="affectation" class="form-select rounded border-gray-300 w-36"><option value="">Toutes</option><option value="chantier" <?= (($_GET['affectation'] ?? '') == 'chantier') ? 'selected' : '' ?>>Chantier</option><option value="bureau" <?= (($_GET['affectation'] ?? '') == 'bureau') ? 'selected' : '' ?>>Bureau</option><option value="autre" <?= (($_GET['affectation'] ?? '') == 'autre') ? 'selected' : '' ?>>Autre</option></select></div>
        <div><label class="block text-xs font-semibold text-gray-600 mb-1">Bénéficiaire</label><input type="text" name="beficiaire_fiche" value="<?= htmlspecialchars($_GET['beficiaire_fiche'] ?? '') ?>" class="form-input rounded border-gray-300 w-36"></div>
        <div><label class="block text-xs font-semibold text-gray-600 mb-1">Désignation</label><input type="text" name="designation" value="<?= htmlspecialchars($_GET['designation'] ?? '') ?>" class="form-input rounded border-gray-300 w-36"></div>
        <div><label class="block text-xs font-semibold text-gray-600 mb-1">Précision</label><input type="text" name="precision" value="<?= htmlspecialchars($_GET['precision'] ?? '') ?>" class="form-input rounded border-gray-300 w-36"></div>
        <div><label class="block text-xs font-semibold text-gray-600 mb-1">Montant min</label><input type="number" name="montant_min" value="<?= htmlspecialchars($_GET['montant_min'] ?? '') ?>" class="form-input rounded border-gray-300 w-28"></div>
        <div><label class="block text-xs font-semibold text-gray-600 mb-1">Montant max</label><input type="number" name="montant_max" value="<?= htmlspecialchars($_GET['montant_max'] ?? '') ?>" class="form-input rounded border-gray-300 w-28"></div>
        <div><label class="block text-xs font-semibold text-gray-600 mb-1">Date min</label><input type="date" name="date_min" value="<?= htmlspecialchars($_GET['date_min'] ?? '') ?>" class="form-input rounded border-gray-300 w-36"></div>
        <div><label class="block text-xs font-semibold text-gray-600 mb-1">Date max</label><input type="date" name="date_max" value="<?= htmlspecialchars($_GET['date_max'] ?? '') ?>" class="form-input rounded border-gray-300 w-36"></div>
        <div><label class="block text-xs font-semibold text-gray-600 mb-1">Chantier</label><input type="text" name="chantier" value="<?= htmlspecialchars($_GET['chantier'] ?? '') ?>" class="form-input rounded border-gray-300 w-36"></div>
        <div><button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg font-semibold shadow transition-all">Rechercher</button></div>
    </form>
    <?php
    $montantTotal = array_sum(array_column($fiches, 'montant_fiche'));
    $nbResultats = count($fiches);
    $countAttente = 0;
    $countRefuse = 0;
    foreach ($fiches as $f) {
        $ap = (int)($f['approuve'] ?? 0);
        if ($ap === 0) $countAttente++;
        elseif ($ap === 2) $countRefuse++;
    }
    ?>
    <div class="flex flex-wrap items-center justify-between mb-6 gap-4">
        <div><span class="font-semibold text-gray-700">Résultats :</span> <span id="count-resultats" class="text-indigo-700 font-bold"><?= $nbResultats ?></span><span class="ml-4 font-semibold text-gray-700">Montant total :</span> <span id="montant-total" class="text-green-700 font-bold"><?= number_format($montantTotal, 0, ',', ' ') ?> CFA</span><span class="ml-4 font-semibold text-gray-700">Total sélectionné :</span> <span id="total-selectionne" class="text-blue-700 font-bold">0 CFA</span><span class="ml-4 font-semibold text-gray-700">Statuts :</span> <span id="count-attente" class="inline-flex items-center gap-1 text-xs bg-amber-100 text-amber-700 px-2 py-0.5 rounded"><strong><?= $countAttente ?></strong> attente</span> <span id="count-refuse" class="inline-flex items-center gap-1 text-xs bg-red-100 text-red-700 px-2 py-0.5 rounded"><strong><?= $countRefuse ?></strong> refusées</span></div>
        <div class="flex gap-2"><button type="button" id="exportAllPdf" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg font-semibold shadow transition-all">Exporter tout PDF</button><button type="button" id="exportAllExcel" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg font-semibold shadow transition-all">Exporter tout Excel</button><button type="button" id="exportSelectedPdf" class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg font-semibold shadow transition-all">Sélection PDF</button><button type="button" id="exportSelectedExcel" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg font-semibold shadow transition-all">Sélection Excel</button></div>
    </div>
    <div id="cards-container" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-8">
        <?php foreach ($fiches as $fiche): ?>
            <?php
            $supplierApprovalError=SupplierApprovalGuard::error($pdo,$fiche);
            $photo = trim((string)($fiche['beneficiary_visual_path'] ?? $fiche['photo_beneficiaire'] ?? ''));
            $photoUrl = resolvePhotoUrl($photo);
            $isChantier = !empty($fiche['chantier_id']);
            $isBureau = !empty($fiche['serv_bureau_banamur_id']);
            $ficheTypeClass = $isChantier ? 'fiche-chantier' : ($isBureau ? 'fiche-bureau' : 'fiche-autre');
            $isRefuse = ((int)($fiche['approuve'] ?? 0) === 2);
            $isApproved = ((int)($fiche['approuve'] ?? 0) === 1);
            $isDisbursed = ((int)($fiche['decaisse'] ?? 0) === 1);
            $hasEvents = !empty($fiche['details_demande']) && (strpos($fiche['details_demande'], '[REFUS') !== false || strpos($fiche['details_demande'], '[RESET') !== false);
            $motifRefus = '';
            if ($isRefuse && !empty($fiche['details_demande']) && preg_match_all('/\[REFUS[^\]]*\]\s*(.+)/u', $fiche['details_demande'], $m)) {
                $last = trim(end($m[1]));
                $motifRefus = mb_strlen($last) > 140 ? mb_substr($last, 0, 140) . '…' : $last;
            }
            ?>
            <div class="approval-card bg-white rounded-2xl shadow-xl hover:shadow-2xl transition-all flex flex-col justify-between p-6 pt-7 relative group <?= $ficheTypeClass ?> animate-fade-in <?= $isRefuse ? 'ring-2 ring-red-300' : '' ?>" data-montant="<?= (float)$fiche['montant_fiche'] ?>" data-statut="<?= $isRefuse ? 'refuse' : ($isApproved ? 'approuve' : 'attente') ?>" <?= $isRefuse && $motifRefus ? 'title="Motif: ' . htmlspecialchars($motifRefus) . '"' : '' ?>>
                <div class="absolute top-4 left-4 z-10 flex gap-2 items-center"><span class="card-badge-analyse" data-num="<?= htmlspecialchars($fiche['num_fiche']); ?>" style="display:inline-flex;align-items:center;justify-content:center;width:38px;height:38px;border:2px solid #e2e8f0;border-radius:50%;background:#fff;font-size:11px;color:#64748b;font-weight:600;box-shadow:0 2px 4px rgba(0,0,0,.08);">...</span><?= getAffectationLabel($fiche) ?></div>
                <div class="absolute top-2 right-2 z-10"><?php if ($isDisbursed): ?><span class="px-2 py-0.5 text-[10px] font-semibold rounded-full bg-emerald-600 text-white shadow">DÉCAISSÉE</span><?php elseif ($isRefuse): ?><span class="px-2 py-0.5 text-[10px] font-semibold rounded-full bg-red-600 text-white shadow">REFUSÉE</span><?php else: ?><span class="px-2 py-0.5 text-[10px] font-semibold rounded-full bg-amber-500 text-white shadow">EN ATTENTE</span><?php endif; ?></div>
                <div class="flex justify-center mb-4 mt-2"><?php if ($photoUrl): ?><img src="<?= htmlspecialchars($photoUrl) ?>" alt="Photo du bénéficiaire <?= htmlspecialchars($fiche['beneficiary_display_name'] ?? $fiche['beficiaire_fiche']) ?>" class="w-20 h-20 rounded-full object-cover border-4 border-indigo-400 shadow-md bg-gray-100"><?php else: ?><div class="w-20 h-20 flex items-center justify-center rounded-full border-4 border-indigo-400 shadow-md bg-gray-100"><svg class="w-12 h-12 text-gray-400" fill="currentColor" viewBox="0 0 24 24"><path d="M12 12c2.7 0 4.5-2.1 4.5-4.5S14.7 3 12 3 7.5 5.1 7.5 7.5 9.3 12 12 12zm0 2c-3 0-9 1.5-9 4.5V21h18v-2.5c0-3-6-4.5-9-4.5z" /></svg></div><?php endif; ?></div>
                <h2 class="text-xl font-semibold text-center mb-1 text-gray-800 flex items-center justify-center gap-2"><?= htmlspecialchars($fiche['beneficiary_display_name'] ?? $fiche['beficiaire_fiche']); ?><?php if ($isRefuse): ?><span class="inline-flex items-center px-2 py-0.5 rounded-full bg-red-100 text-red-700 text-[10px] font-semibold" title="Refusée">REF</span><?php endif; ?></h2>
                <?php if (!empty($fiche['beneficiary_display_phone'])): ?><p class="text-center text-xs text-gray-500 mb-1"><?= htmlspecialchars($fiche['beneficiary_display_phone']) ?></p><?php endif; ?>
                <div class="flex items-center justify-center mb-1"><span class="text-gray-500 text-sm">Fiche N° <?= htmlspecialchars($fiche['num_fiche']); ?></span><a href="voir_fiche_details.php?num_fiche=<?= urlencode($fiche['num_fiche']); ?>" target="_blank" class="ml-2 bg-white border border-indigo-200 rounded-full p-1 shadow hover:bg-indigo-50 transition" title="Voir la fiche (détails)"><svg class="w-5 h-5 text-indigo-700" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m0 0l-6-6m6 6l6-6" /></svg></a></div>
                <div data-approval-supplier-alert <?= $supplierApprovalError?'':'hidden' ?> role="alert" class="mb-3 rounded-xl border border-red-300 bg-red-50 p-3 text-sm text-red-900"><strong>Approbation bloquée</strong><p><?= htmlspecialchars($supplierApprovalError,ENT_QUOTES,'UTF-8') ?></p></div>
                <p class="text-green-600 font-bold text-lg text-center mb-2"><?= number_format($fiche['montant_fiche'], 0, ',', ' ') ?> CFA</p>
                <div class="text-sm text-gray-700 mb-4 text-center"><p><strong>Ligne déboursé :</strong> <?= htmlspecialchars($fiche['ligne_debourse_label'] ?? ''); ?></p><p><strong>Ligne devis :</strong> <?= htmlspecialchars($fiche['ligne_devis_label'] ?? ''); ?></p><p><strong>Précision :</strong> <?= htmlspecialchars($fiche['precision_fiche'] ?? ''); ?></p><p><strong>Désignation :</strong> <?= htmlspecialchars($fiche['designation_fiche'] ?? ''); ?></p></div>
                <div class="flex justify-between items-center mt-auto gap-2"><span class="text-xs text-gray-400"><?= date('d M Y', strtotime($fiche['date_creat_fiche'])); ?></span><?php if (userCanAction($fiche)): ?><div class="actions-wrap flex flex-wrap gap-2 justify-end" style="max-width:225px"><button data-num="<?= htmlspecialchars($fiche['num_fiche']); ?>" class="js-analyse bg-amber-500 hover:bg-amber-600 text-white px-2 py-1.5 rounded-lg text-[11px] font-semibold shadow" title="Analyse budget">Aide</button><?php if (($isApproved || $isDisbursed) && $canAdjustBeneficiarySupplier): ?><button data-num="<?= htmlspecialchars($fiche['num_fiche']); ?>" class="js-edit bg-blue-600 hover:bg-blue-700 text-white px-2 py-1.5 rounded-lg text-[11px] font-semibold shadow">Ajuster bénéficiaire</button><?php elseif (!$isRefuse && !$isApproved): ?><button data-num="<?= htmlspecialchars($fiche['num_fiche']); ?>" <?= $supplierApprovalError ? 'disabled data-supplier-blocked="1" title="Fournisseur à régulariser"' : '' ?> class="js-approve bg-green-600 hover:bg-green-700 text-white px-2 py-1.5 rounded-lg text-[11px] font-semibold shadow">OK</button><button data-num="<?= htmlspecialchars($fiche['num_fiche']); ?>" class="js-reject bg-red-600 hover:bg-red-700 text-white px-2 py-1.5 rounded-lg text-[11px] font-semibold shadow">Refus</button><button data-num="<?= htmlspecialchars($fiche['num_fiche']); ?>" class="js-edit bg-blue-600 hover:bg-blue-700 text-white px-2 py-1.5 rounded-lg text-[11px] font-semibold shadow">Modif</button><?php else: ?><button data-num="<?= htmlspecialchars($fiche['num_fiche']); ?>" class="js-reset bg-orange-500 hover:bg-orange-600 text-white px-2 py-1.5 rounded-lg text-[11px] font-semibold shadow" title="Réinitialiser (remettre en attente)">Reset</button><?php endif; ?><?php if ($hasEvents): ?><button data-num="<?= htmlspecialchars($fiche['num_fiche']); ?>" class="js-history bg-slate-600 hover:bg-slate-700 text-white px-2 py-1.5 rounded-lg text-[11px] font-semibold shadow" title="Historique refus / reset">Hist.</button><?php endif; ?></div><?php else: ?><span class="text-gray-400 italic text-xs">Aucune action possible</span><?php endif; ?></div>
                <?php if (!$isRefuse): ?><input type="checkbox" name="fiche_select[]" value="<?= htmlspecialchars($fiche['num_fiche']); ?>" class="absolute top-4 left-1 z-20 w-5 h-5 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500 fiche-checkbox"><?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php if (empty($fiches)): ?><div class="text-center text-gray-500 mt-16 text-lg">Aucune fiche en attente d'approbation pour le moment.</div><?php endif; ?>
</div>
<script>
    window.addEventListener('error', function(e) { try { console.error('GLOBAL JS ERROR:', e.message, e.filename + ':' + e.lineno + ':' + e.colno, e.error); } catch (_) {} });
    window.addEventListener('unhandledrejection', function(e) { console.error('UNHANDLED PROMISE REJECTION:', e.reason); });
    function updateTotalSelectionne() { let total=0,ids=[];document.querySelectorAll('.fiche-checkbox:checked').forEach(cb=>{const card=cb.closest('.card')||cb.closest('.group');const montantText=card.querySelector('.text-green-600')?.textContent||'0';const montant=parseFloat(montantText.replace(/\s|CFA/g,'').replace(',','.'))||0;total+=montant;ids.push(cb.value);});document.getElementById('total-selectionne').textContent=total.toLocaleString('fr-FR')+' CFA';document.body.dataset.selectedIds=ids.join(','); }
    function refreshApprovalSummary() {
        const cards = Array.from(document.querySelectorAll('#cards-container .approval-card'));
        const resultats = cards.length;
        const montantTotal = cards.reduce((sum, card) => sum + (Number(card.dataset.montant) || 0), 0);
        const attente = cards.filter(card => card.dataset.statut === 'attente').length;
        const refusees = cards.filter(card => card.dataset.statut === 'refuse').length;

        const resultEl = document.getElementById('count-resultats');
        const montantEl = document.getElementById('montant-total');
        const attenteEl = document.querySelector('#count-attente strong');
        const refuseEl = document.querySelector('#count-refuse strong');

        if (resultEl) resultEl.textContent = String(resultats);
        if (montantEl) montantEl.textContent = montantTotal.toLocaleString('fr-FR') + ' CFA';
        if (attenteEl) attenteEl.textContent = String(attente);
        if (refuseEl) refuseEl.textContent = String(refusees);

        updateTotalSelectionne();
    }

    function bindApprovalEvents(scope=document) {
        scope.querySelectorAll('.fiche-checkbox').forEach(cb => cb.addEventListener('change', updateTotalSelectionne));
        scope.querySelectorAll('.js-approve').forEach(btn => btn.addEventListener('click', () => approveFiche(btn)));
    }

    async function approveFiche(btn) {
        const num = btn.dataset.num;
        if (!num || btn.disabled) return;

        btn.disabled = true;
        btn.classList.add('opacity-60');

        try {
            const response = await fetch('request/approuver_fiche_decaissement.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({num_fiche: num})
            });

            const raw = await response.text();
            let data;
            try {
                data = JSON.parse(raw);
            } catch (_) {
                throw new Error('Réponse serveur invalide lors de l’approbation.');
            }

            if (!response.ok || !data.ok) {
                throw new Error(data.error || 'L’approbation a échoué.');
            }

            const card = btn.closest('.bg-white');
            if (card) card.remove();
            refreshApprovalSummary();

            let toast = document.getElementById('approval-toast');
            if (!toast) {
                toast = document.createElement('div');
                toast.id = 'approval-toast';
                toast.className = 'fixed top-4 right-4 z-[9999] bg-green-600 text-white px-4 py-3 rounded-lg shadow-lg';
                document.body.appendChild(toast);
            }
            toast.textContent = 'Fiche ' + num + ' approuvée';
            toast.style.display = 'block';
            setTimeout(() => { toast.style.display = 'none'; }, 2500);
        } catch (error) {
            console.error('Erreur approbation fiche', num, error);
            alert(error.message || 'Erreur lors de l’approbation.');
        } finally {
            btn.disabled = false;
            btn.classList.remove('opacity-60');
        }
    }

    bindApprovalEvents(document);
</script>
<script src="../assets/js/supplier-approval-refresh.js?v=<?= filemtime(__DIR__.'/../assets/js/supplier-approval-refresh.js') ?>" defer></script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>

