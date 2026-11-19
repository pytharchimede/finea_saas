<?php
session_start();
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/includes/fiche_label_helper.php';
require_once __DIR__ . '/model/Database.php';
require_once __DIR__ . '/model/FicheDecaissement.php';
require_once __DIR__ . '/../services/UrgentDisbursementService.php';
require_once __DIR__ . '/../controllers/CommercialProjectFinanceController.php';
require_once __DIR__ . '/../services/DailyDisbursementPointService.php';
require_once __DIR__ . '/../services/ManualTreasuryService.php';
require_once __DIR__ . '/../services/DisbursementDocumentComplianceService.php';

AuditTrail::logPageConsultation([
    'module' => 'decaissement',
    'action' => 'liste_fiches_achat_view',
    'entity_type' => 'fiche_achat_list',
    'entity_label' => 'Liste des fiches achat',
    'page_name' => 'liste_fiches_achat.php',
    'details' => [
        'beneficiaire' => $_GET['beneficiaire'] ?? null,
        'affectation' => $_GET['affectation'] ?? null,
        'designation' => $_GET['designation'] ?? null,
        'chantier' => $_GET['chantier'] ?? null,
        'view_mode' => 'list',
    ],
]);

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

// Connexion & modèle
$dataBaseObj = new Database();
$pdo = $dataBaseObj->getConnection();
$ficheDec = new FicheDecaissement($pdo);
$urgentDisbursementService = new UrgentDisbursementService($pdo);
$currentUserId=(int)($_SESSION['user_id']??0);$readPointPermission=static function(string $column)use($pdo,$currentUserId):bool{if(AccessManager::hasPermission($column))return true;if($currentUserId<=0)return false;try{$s=$pdo->prepare("SELECT `$column` FROM user_devis_banamur WHERE id=:id AND active=1");$s->execute(['id'=>$currentUserId]);return(bool)$s->fetchColumn();}catch(Throwable $e){return false;}};
$canStopDailyPoint=$readPointPermission('acces_arreter_point_jour');
$canViewTreasuryRecommendations=$readPointPermission('acces_recommandations_tresorerie');
$treasurySummary=[];
$treasuryAvailable=0.0;
$treasuryCommitted=0.0;
try {
    $treasurySummary=(new ManualTreasuryService($pdo))->dashboard(date('Y-m-d'),date('Y-m-d'));
    $treasuryAvailable=max(0.0,(float)($treasurySummary['realBalance']??0));
    $treasuryCommitted=max(0.0,(float)$pdo->query(
        "SELECT COALESCE(SUM(montant_fiche),0)
         FROM fiche_decaissement
         WHERE conforme=1
           AND approuve=1
           AND etat_fiche=1
           AND decaisse=0
           AND (date_decaissement_minimum IS NULL OR date_decaissement_minimum<=CURDATE())"
    )->fetchColumn());
} catch(Throwable $e) {
    $treasurySummary=[];
}
$treasuryValidationAvailable=max(0.0,$treasuryAvailable-$treasuryCommitted);
$treasuryRealEntries=max(0.0,(float)($treasurySummary['realEntriesBalance']??0));
$dailyPointService=new DailyDisbursementPointService($pdo);$todayPoint=$dailyPointService->today();$pendingPoint=$dailyPointService->pendingOpen();$currentPoint=$pendingPoint?:$todayPoint;if(empty($_SESSION['daily_point_csrf']))$_SESSION['daily_point_csrf']=bin2hex(random_bytes(32));
$documentComplianceService=new DisbursementDocumentComplianceService($pdo);

// Filtres (SQL côté modèle)
$filters = [
    'beneficiaire' => $_GET['beneficiaire'] ?? null,
    'affectation' => $_GET['affectation'] ?? null,
    'designation' => $_GET['designation'] ?? null,
    'montant_min' => $_GET['montant_min'] ?? null,
    'montant_max' => $_GET['montant_max'] ?? null,
    'date_min' => $_GET['date_min'] ?? null,
    'date_max' => $_GET['date_max'] ?? null,
    'chantier' => $_GET['chantier'] ?? null,
    'beneficiaire_mode'=>$_GET['beneficiaire_mode']??'include','affectation_mode'=>$_GET['affectation_mode']??'include','designation_mode'=>$_GET['designation_mode']??'include','chantier_mode'=>$_GET['chantier_mode']??'include',
];
$fiches = $ficheDec->listAchatReady($filters);
$fiches=array_values(array_filter($fiches,static fn(array $fiche):bool=>(string)($fiche['document_completion_status']??'complete')!=='returned'));
$filterCatalogFiches=$ficheDec->listAchatReady([]);$filterCatalog=['beneficiaire'=>[],'designation'=>[],'chantier'=>[]];foreach($filterCatalogFiches as $catalogFiche){foreach(['beneficiaire'=>(string)($catalogFiche['beficiaire_fiche']??''),'designation'=>(string)($catalogFiche['designation_fiche']??''),'chantier'=>trim((string)($catalogFiche['chantier_code']??'').' — '.(string)($catalogFiche['chantier_titre']??''))] as $key=>$value)if(trim($value)!=='')$filterCatalog[$key][trim($value)]=trim($value);}foreach($filterCatalog as &$values){natcasesort($values);$values=array_values($values);}unset($values);
$urgentBlockedChantiers = $urgentDisbursementService->repository()->listBlockedChantiers();

$pageTitle = 'Validation Achat - DécaissApp';
$activeMenu = 'achat';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>
<?php
// Résolution photo réutilisable
function resolvePhotoUrl($filename)
{
    $filename = trim($filename ?? '');
    if ($filename === '') return null;
    $baseRoot = realpath(__DIR__ . '/..'); // racine du projet par rapport à /decaissement
    $cand1 = $baseRoot . '/uploads/photo/' . $filename; // ../uploads/photo
    $cand2 = __DIR__ . '/uploads/photo/' . $filename;   // decaissement/uploads/photo
    if (is_file($cand1)) return '../uploads/photo/' . rawurlencode($filename);
    if (is_file($cand2)) return 'uploads/photo/' . rawurlencode($filename);
    return null;
}

function buildLigneDebourseLabel(array $fiche)
{
    return build_fiche_ligne_debourse_label($fiche);
}

function buildLigneDevisLabel(array $fiche)
{
    return build_fiche_ligne_devis_label($fiche);
}
?>
<div class="container mx-auto py-10 px-4">
    <h1 class="text-3xl font-bold text-center mb-10 text-indigo-700 drop-shadow">Validation Achat</h1>

    <?php if($pendingPoint): ?><section class="mb-7 overflow-hidden rounded-lg border border-amber-300 bg-white shadow-lg"><div class="grid gap-4 bg-amber-50 p-5 md:grid-cols-[1fr_auto]"><div><p class="text-xs font-black uppercase text-amber-700">Point en cours à terminer</p><h2 class="mt-1 text-xl font-black text-slate-950">Point #<?= (int)$pendingPoint['id'] ?> · <?= htmlspecialchars(date('d/m/Y',strtotime((string)$pendingPoint['point_date']))) ?></h2><p class="mt-1 text-sm text-slate-600"><?= (string)$pendingPoint['status']==='stopped'?'La signature du DG est attendue. Vous pouvez consulter le point, exporter sa version provisoire ou recréer son lien sécurisé.':'Le point est signé et attend son décaissement effectif.' ?></p></div><div class="flex flex-wrap items-center gap-2"><a href="point_decaissement_jour.php?id=<?= (int)$pendingPoint['id'] ?>" class="rounded-md bg-slate-900 px-4 py-3 font-bold text-white">Consulter le point</a><a target="_blank" href="request/export_daily_point_pdf.php?id=<?= (int)$pendingPoint['id'] ?>" class="rounded-md border border-red-300 bg-white px-4 py-3 font-bold text-red-800">PDF <?= (string)$pendingPoint['status']==='stopped'?'provisoire':'signé' ?></a><?php if((string)$pendingPoint['status']==='stopped'&&$canStopDailyPoint):?><button type="button" data-resend-signature-link data-point-id="<?= (int)$pendingPoint['id'] ?>" class="rounded-md bg-violet-700 px-4 py-3 font-bold text-white">Obtenir le lien de signature</button><?php endif;?></div></div></section><?php endif; ?>

    <?php if ($urgentBlockedChantiers): ?>
        <div class="mb-8 rounded-2xl border border-rose-200 bg-rose-50 p-5 text-rose-900 shadow-sm">
            <div class="font-bold">Chantiers bloques par decaissements urgents non regularises</div>
            <div class="mt-3 flex flex-wrap gap-2">
                <?php foreach ($urgentBlockedChantiers as $blocked): ?>
                    <span class="rounded-full border border-rose-200 bg-white px-3 py-1 text-sm font-semibold">
                        <?= htmlspecialchars((string)($blocked['chantier_label'] ?? 'Chantier')) ?>
                        (<?= htmlspecialchars((string)($blocked['requester_name'] ?? 'Responsable')) ?>)
                    </span>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <style>
        .pf-card{overflow:hidden;border:1px solid rgba(99,102,241,.10);background:radial-gradient(circle at top right,rgba(99,102,241,.12),transparent 34%),linear-gradient(180deg,rgba(255,255,255,.98),rgba(248,250,252,.98));box-shadow:0 8px 24px rgba(15,23,42,.08)}
        .pf-card:hover{transform:translateY(-2px);box-shadow:0 16px 34px rgba(15,23,42,.13)}
        .pf-card-topline{display:flex;align-items:center;justify-content:space-between;gap:12px;padding-bottom:10px;margin-bottom:10px;border-bottom:1px solid rgba(226,232,240,.95)}
        .pf-card-number{display:inline-flex;align-items:center;gap:7px;font-size:11px;font-weight:900;letter-spacing:.12em;color:#4338ca;text-transform:uppercase}
        .pf-card-detail{border:1px solid #c7d2fe;background:#eef2ff;border-radius:12px;padding:10px;color:#312e81;font-size:12px;line-height:1.45}
        .pf-card-badges{display:flex;align-items:center;justify-content:flex-end;gap:6px;flex-wrap:wrap}.pf-chip{display:inline-flex;align-items:center;gap:5px;padding:4px 8px;border-radius:999px;font-size:10px;font-weight:800;text-transform:uppercase}.pf-card-amountline{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px;padding:10px 14px 12px;border-radius:18px;border:1px solid rgba(16,185,129,.14);background:linear-gradient(135deg,rgba(236,253,245,.96),rgba(255,255,255,.98))}.pf-card-amountline-label{font-size:11px;font-weight:800;letter-spacing:.14em;text-transform:uppercase;color:#047857}.pf-amount{font-size:1.45rem;font-weight:900;color:#047857}.pf-card-header{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:12px;align-items:center}.pf-avatar{width:48px;height:48px;border-radius:16px;overflow:hidden;border:3px solid rgba(255,255,255,.9);box-shadow:0 12px 28px rgba(79,70,229,.18);background:linear-gradient(135deg,#eef2ff,#e0f2fe);flex-shrink:0}.pf-avatar img{width:100%;height:100%;object-fit:cover}.pf-card-meta{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.pf-card-meta-item{border:1px solid rgba(226,232,240,.95);border-radius:14px;background:rgba(255,255,255,.84);padding:10px 12px;min-width:0}.pf-card-meta-item--wide{grid-column:1/-1}.pf-card-meta-label{display:block;font-size:10px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:#64748b;margin-bottom:4px}.pf-card-meta-value{display:block;font-size:12px;line-height:1.5;font-weight:650;color:#0f172a;overflow-wrap:break-word}.pf-card-footer{display:flex;align-items:flex-end;justify-content:space-between;gap:12px;border-top:1px solid #e2e8f0;padding-top:12px}
        .fiche-checkbox{display:inline-grid;place-content:center;border-color:#6ee7b7!important;box-shadow:0 2px 7px rgba(5,150,105,.16)}
        .fiche-checkbox::after{display:none!important;content:none!important}
        .fiche-checkbox::before{content:"";width:12px;height:12px;transform:scale(0);transition:transform .12s ease-in-out;clip-path:polygon(14% 44%,0 59%,40% 100%,100% 18%,84% 4%,39% 70%);background:#fff}
        .fiche-checkbox:checked::before{transform:scale(1)}
        .fiche-checkbox:checked{border-color:#059669!important;background-color:#059669!important}
        .pf-card:has(.fiche-checkbox:checked){border-color:#34d399;box-shadow:0 15px 34px rgba(5,150,105,.18)}
        /* Indicateur d'alerte lisible aussi en impression N/B */
        @media print {
            .card-alert-pill {
                background: #fff !important;
                border-color: #000 !important;
                color: #000 !important;
                box-shadow: none !important;
            }

            .card-badge-analyse {
                box-shadow: none !important;
            }
        }
    </style>

    <!-- Formulaire de recherche avancée -->
    <form id="searchForm" method="get" class="mb-8 bg-white rounded-xl shadow p-6 flex flex-wrap gap-4 items-end">
        <div data-multi-search data-name="beneficiaire" data-options='<?= htmlspecialchars(json_encode($filterCatalog['beneficiaire'],JSON_UNESCAPED_UNICODE),ENT_QUOTES,'UTF-8') ?>' class="relative min-w-[240px]">
            <label class="block text-xs font-semibold text-gray-600 mb-1">Bénéficiaire</label>
            <input type="hidden" name="beneficiaire" value="<?= htmlspecialchars($_GET['beneficiaire'] ?? '') ?>"><div class="flex rounded-xl border border-slate-300 bg-white p-1"><select name="beneficiaire_mode" class="rounded-lg border-0 bg-slate-100 text-xs"><option value="include">Inclure</option><option value="exclude" <?= ($_GET['beneficiaire_mode']??'')==='exclude'?'selected':'' ?>>Exclure</option></select><input data-search-input class="min-w-0 flex-1 border-0 px-2 text-sm focus:ring-0" placeholder="Rechercher et sélectionner…"></div><div data-chips class="mt-1 flex flex-wrap gap-1"></div><div data-results class="absolute z-50 mt-1 hidden max-h-56 w-full overflow-auto rounded-xl border bg-white p-1 shadow-xl"></div>
        </div>
        <div data-multi-search data-name="affectation" data-options='["chantier","bureau","autre"]' class="relative min-w-[220px]">
            <label class="block text-xs font-semibold text-gray-600 mb-1">Affectation</label>
            <input type="hidden" name="affectation" value="<?= htmlspecialchars($_GET['affectation'] ?? '') ?>"><div class="flex rounded-xl border border-slate-300 bg-white p-1"><select name="affectation_mode" class="rounded-lg border-0 bg-slate-100 text-xs"><option value="include">Inclure</option><option value="exclude" <?= ($_GET['affectation_mode']??'')==='exclude'?'selected':'' ?>>Exclure</option></select><input data-search-input class="min-w-0 flex-1 border-0 px-2 text-sm focus:ring-0" placeholder="Bureau, chantier…"></div><div data-chips class="mt-1 flex flex-wrap gap-1"></div><div data-results class="absolute z-50 mt-1 hidden w-full rounded-xl border bg-white p-1 shadow-xl"></div>
        </div>
        <div data-multi-search data-name="designation" data-options='<?= htmlspecialchars(json_encode($filterCatalog['designation'],JSON_UNESCAPED_UNICODE),ENT_QUOTES,'UTF-8') ?>' class="relative min-w-[240px]">
            <label class="block text-xs font-semibold text-gray-600 mb-1">Désignation</label>
            <input type="hidden" name="designation" value="<?= htmlspecialchars($_GET['designation'] ?? '') ?>"><div class="flex rounded-xl border border-slate-300 bg-white p-1"><select name="designation_mode" class="rounded-lg border-0 bg-slate-100 text-xs"><option value="include">Inclure</option><option value="exclude" <?= ($_GET['designation_mode']??'')==='exclude'?'selected':'' ?>>Exclure</option></select><input data-search-input class="min-w-0 flex-1 border-0 px-2 text-sm focus:ring-0" placeholder="Rechercher et sélectionner…"></div><div data-chips class="mt-1 flex flex-wrap gap-1"></div><div data-results class="absolute z-50 mt-1 hidden max-h-56 w-full overflow-auto rounded-xl border bg-white p-1 shadow-xl"></div>
        </div>
        <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">Montant min</label>
            <input type="number" name="montant_min" value="<?= htmlspecialchars($_GET['montant_min'] ?? '') ?>" class="form-input rounded border-gray-300 w-28">
        </div>
        <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">Montant max</label>
            <input type="number" name="montant_max" value="<?= htmlspecialchars($_GET['montant_max'] ?? '') ?>" class="form-input rounded border-gray-300 w-28">
        </div>
        <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">Date min</label>
            <input type="date" name="date_min" value="<?= htmlspecialchars($_GET['date_min'] ?? '') ?>" class="form-input rounded border-gray-300 w-36">
        </div>
        <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">Date max</label>
            <input type="date" name="date_max" value="<?= htmlspecialchars($_GET['date_max'] ?? '') ?>" class="form-input rounded border-gray-300 w-36">
        </div>
        <div data-multi-search data-name="chantier" data-options='<?= htmlspecialchars(json_encode($filterCatalog['chantier'],JSON_UNESCAPED_UNICODE),ENT_QUOTES,'UTF-8') ?>' class="relative min-w-[260px]">
            <label class="block text-xs font-semibold text-gray-600 mb-1">Chantier</label>
            <input type="hidden" name="chantier" value="<?= htmlspecialchars($_GET['chantier'] ?? '') ?>"><div class="flex rounded-xl border border-slate-300 bg-white p-1"><select name="chantier_mode" class="rounded-lg border-0 bg-slate-100 text-xs"><option value="include">Inclure</option><option value="exclude" <?= ($_GET['chantier_mode']??'')==='exclude'?'selected':'' ?>>Exclure</option></select><input data-search-input class="min-w-0 flex-1 border-0 px-2 text-sm focus:ring-0" placeholder="Code ou chantier…"></div><div data-chips class="mt-1 flex flex-wrap gap-1"></div><div data-results class="absolute z-50 mt-1 hidden max-h-56 w-full overflow-auto rounded-xl border bg-white p-1 shadow-xl"></div>
        </div>
        <div>
            <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg font-semibold shadow transition-all">Rechercher</button>
        </div>
    </form>

    <!-- Résultats de la recherche -->
    <?php
    // Calcul du montant total des fiches affichées
    $montantTotal = array_sum(array_column($fiches, 'montant_fiche'));
    $nbResultats = count($fiches);
    ?>
    <div class="flex flex-wrap items-center justify-between mb-6 gap-4">
        <div>
            <span class="font-semibold text-gray-700">Résultats :</span>
            <span class="text-indigo-700 font-bold"><?= $nbResultats ?></span>
            <span class="ml-4 font-semibold text-gray-700">Montant total :</span>
            <span id="montant-total" class="text-green-700 font-bold"><?= number_format($montantTotal, 0, ',', ' ') ?> CFA</span>
        </div>
        <div class="flex flex-wrap gap-2">
            <button type="button" id="selectAllFiches" class="bg-slate-900 hover:bg-slate-800 text-white px-4 py-2 rounded-lg font-semibold shadow">Tout sélectionner</button>
            <button type="button" id="reportSelected" class="bg-amber-600 hover:bg-amber-700 text-white px-4 py-2 rounded-lg font-semibold shadow">Reporter la sélection</button>
            <?php if($canStopDailyPoint): ?><button type="button" id="stopDailyPoint" class="bg-violet-700 hover:bg-violet-800 text-white px-4 py-2 rounded-lg font-semibold shadow">Arrêter le point du jour</button><?php else:?><button type="button" id="stopDailyPoint" class="hidden" disabled aria-hidden="true"></button><?php endif; ?>
            <?php if($currentPoint): ?><a href="point_decaissement_jour.php?id=<?= (int)$currentPoint['id'] ?>" class="bg-cyan-700 hover:bg-cyan-800 text-white px-4 py-2 rounded-lg font-semibold shadow">Voir le point #<?= (int)$currentPoint['id'] ?></a><?php endif; ?>
            <a href="historique_points_decaissement.php" class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-4 py-2 font-semibold text-slate-700 shadow-sm hover:border-emerald-300 hover:text-emerald-700"><i class="fas fa-box-archive mr-2"></i>Historique des points</a>
            <?php if(AccessManager::hasPermission('acces_admin')||AccessManager::hasPermission('acces_direction')): ?><a href="parametres_signature_point_jour.php" class="border border-slate-300 bg-white text-slate-800 px-4 py-2 rounded-lg font-semibold shadow-sm">WhatsApp du DG</a><?php endif; ?>
            <button type="button" id="exportAllPdf" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg font-semibold shadow transition-all">Exporter le point officiel</button>
            <button type="button" id="exportAllExcel" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg font-semibold shadow transition-all">Exporter tout Excel</button>
            <button type="button" id="exportSelectedExcel" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg font-semibold shadow transition-all">Sélection Excel</button>
        </div>
    </div>

    <?php if($canViewTreasuryRecommendations): ?><section id="selectionTreasuryPanel" data-available="<?= htmlspecialchars((string)$treasuryValidationAvailable) ?>" class="sticky top-3 z-30 mb-7 grid gap-3 rounded-3xl border border-indigo-200 bg-gradient-to-r from-slate-950 via-indigo-950 to-sky-900 p-5 text-white shadow-2xl md:grid-cols-4">
        <div><div class="text-xs uppercase tracking-widest text-indigo-200">Disponible réel</div><div id="treasuryAvailable" class="mt-1 text-xl font-black"><?= number_format($treasuryAvailable,0,',',' ') ?> FCFA</div><small class="text-indigo-200"><?= number_format($treasuryCommitted,0,',',' ') ?> FCFA déjà engagés · <?= number_format($treasuryValidationAvailable,0,',',' ') ?> FCFA mobilisables</small></div>
        <div><div class="text-xs uppercase tracking-widest text-indigo-200">Sélection</div><div id="selectedAmount" class="mt-1 text-xl font-black">0 FCFA</div><small id="selectedCount" class="text-indigo-200">0 fiche</small></div>
        <div><div class="text-xs uppercase tracking-widest text-indigo-200">Reste mobilisable</div><div id="remainingAmount" class="mt-1 text-xl font-black"><?= number_format($treasuryValidationAvailable,0,',',' ') ?> FCFA</div></div>
        <div id="coverageStatus" class="flex items-center justify-center rounded-2xl bg-white/10 px-4 text-center text-sm font-black">Sélectionnez des fiches pour simuler le paiement.</div>
    </section><?php endif; ?>

    <div id="cards-container" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
        <?php foreach ($fiches as $fiche): ?>
            <?php
            $photo = trim($fiche['photo_beneficiaire'] ?? '');
            $photoUrl = resolvePhotoUrl($photo);
            $isChantier = !empty($fiche['chantier_id']);
            $isBureau = !empty($fiche['serv_bureau_banamur_id']);
            $ficheTypeClass = $isChantier ? 'fiche-chantier' : ($isBureau ? 'fiche-bureau' : 'fiche-autre');
            ?>
            <?php
            $etat = (int)($fiche['etat_fiche'] ?? 0);
            $dateMin = $fiche['date_decaissement_minimum'] ?? null;
            $isFuture = $dateMin && strtotime($dateMin) > strtotime(date('Y-m-d'));
            $statusLabel = 'En attente';
            $statusColor = 'bg-green-100 text-green-700';
            if ($etat === 2) {
                $statusLabel = 'Refusé achat';
                $statusColor = 'bg-red-100 text-red-700';
            } elseif ($isFuture) {
                $statusLabel = 'Reporté';
                $statusColor = 'bg-amber-100 text-amber-700';
            }
            $refusHistory = [];
            if (!empty($fiche['details_demande'])) {
                foreach (preg_split('/\r?\n/', $fiche['details_demande']) as $l) {
                    $l = trim($l);
                    if (strpos($l, '[REFUS_ACHAT') === 0) {
                        $refusHistory[] = $l;
                    }
                }
            }
            $refusTitle = $refusHistory ? htmlspecialchars(implode("\n", $refusHistory)) : '';
            $hasBonCommande = !empty($fiche['bc_id']) || !empty($fiche['bc_number']);
            $baseNumFiche = trim((string)($fiche['workflow_base_num_fiche'] ?? ($fiche['code_parentale'] ?? ($fiche['parent_num_fiche'] ?? $fiche['num_fiche']))));
            if ($baseNumFiche === '') {
                $baseNumFiche = (string)($fiche['num_fiche'] ?? '');
            }
            $hasBonReception = !empty($fiche['br_id']);
            $hasCni = trim((string)($fiche['cni_beneficiaire'] ?? '')) !== '';
            $hasSignature = trim((string)($fiche['signature_beneficiaire'] ?? '')) !== '';
            $ligneDebourseLabel = buildLigneDebourseLabel($fiche);
            $ligneDevisLabel = buildLigneDevisLabel($fiche);
            if((int)($fiche['debourse_id']??0)>0)$ligneDevisLabel='#'.(int)$fiche['debourse_id'].' - '.$ligneDevisLabel;
            $documentState=$documentComplianceService->evaluate($fiche);
            ?>
            <div class="pf-card rounded-2xl transition-all flex flex-col justify-between p-4 relative group card <?= $ficheTypeClass ?> animate-fade-in"
                data-details="<?= htmlspecialchars($fiche['details_demande'] ?? '') ?>"
                data-etat="<?= $etat ?>"
                data-date-min="<?= htmlspecialchars($dateMin ?? '') ?>"
                data-has-bc="<?= $hasBonCommande ? '1' : '0' ?>"
                data-has-br="<?= $hasBonReception ? '1' : '0' ?>"
                data-has-cni="<?= $hasCni ? '1' : '0' ?>"
                data-has-signature="<?= $hasSignature ? '1' : '0' ?>">
                <div class="pf-card-topline"><label class="pf-card-number group/select cursor-pointer rounded-xl px-2 py-2 transition hover:bg-indigo-50" title="FEB #<?=htmlspecialchars((string)($fiche['feb_request_id']??'non rattachée'))?>"><input type="checkbox" name="fiche_select[]" value="<?=htmlspecialchars((string)$fiche['num_fiche'])?>" data-amount="<?=htmlspecialchars((string)((float)($fiche['montant_fiche']??0)))?>" data-original-amount="<?=htmlspecialchars((string)((float)($fiche['montant_fiche']??0)))?>" class="fiche-checkbox h-6 w-6 shrink-0 cursor-pointer appearance-none rounded-lg border-2 border-indigo-300 bg-white transition checked:border-indigo-600 checked:bg-indigo-600 focus:outline-none focus:ring-4 focus:ring-indigo-100 after:hidden checked:after:block checked:after:content-['✓'] checked:after:text-center checked:after:text-sm checked:after:font-black checked:after:leading-5 checked:after:text-white"><span>Fiche N° <?=htmlspecialchars((string)$fiche['num_fiche'])?></span></label><div class="pf-card-badges"><?php if(!empty($fiche['chantier_code'])):?><span class="pf-chip bg-indigo-50 text-indigo-700"><?=htmlspecialchars((string)$fiche['chantier_code'])?></span><?php endif;?><span class="pf-chip <?=$statusColor?>"><?=$statusLabel?></span><button type="button" class="card-badge-analyse pf-chip border border-slate-200 bg-white text-slate-600" data-num="<?=htmlspecialchars((string)$fiche['num_fiche'])?>" title="Analyse budget">Analyse…</button></div></div>
                <!-- Badge analyse budget (chargé dynamiquement) -->
                <!-- Indicateur décision rapide (problème/attention) -->
                <span class="card-alert-pill hidden absolute top-14 left-4 z-20 bg-white px-2 py-1 rounded-full text-[11px] font-extrabold border border-slate-300 text-slate-700 shadow-sm whitespace-nowrap" title=""></span>
                <!-- Type d'affectation (décalé) -->
                <!-- Badge validation -->
                <?php if ($refusHistory): ?>
                    <span class="absolute top-16 right-4 bg-red-600 text-white w-6 h-6 flex items-center justify-center rounded-full text-[10px] font-bold shadow" title="<?= $refusTitle ?>">R</span>
                <?php endif; ?>
                <button type="button" data-partial-trigger class="pf-card-amountline w-full text-left" title="Cliquer pour prévoir un décaissement partiel">
                    <span><span class="pf-card-amountline-label block">Montant demandé</span><small class="block text-[9px] font-bold uppercase text-emerald-600">Cliquer pour paiement partiel</small><span data-partial-summary class="mt-1 hidden rounded-full bg-amber-100 px-2 py-1 text-[10px] font-black text-amber-800"></span></span><span class="pf-amount whitespace-nowrap"><?= number_format($fiche['montant_fiche'], 0, ',', ' ') ?> CFA</span>
                </button>
                <!-- Photo -->
                <div class="pf-card-header mb-4"><div class="flex min-w-0 items-start gap-4"><div class="pf-avatar">
                    <?php if ($photoUrl): ?>
                        <img src="<?= $photoUrl ?>"
                            alt="Photo de <?= htmlspecialchars($fiche['beficiaire_fiche']) ?>"
                            class="h-full w-full object-cover">
                    <?php else: ?>
                        <div class="flex h-full w-full items-center justify-center">
                            <svg class="w-12 h-12 text-gray-400" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M12 12c2.7 0 4.5-2.1 4.5-4.5S14.7 3 12 3 7.5 5.1 7.5 7.5 9.3 12 12 12zm0 2c-3 0-9 1.5-9 4.5V21h18v-2.5c0-3-6-4.5-9-4.5z" />
                            </svg>
                        </div>
                    <?php endif; ?>
                </div>
                <!-- Infos principales --><div class="min-w-0 flex-1">
                <h2 class="text-lg font-bold leading-tight text-slate-900"><?= htmlspecialchars($fiche['beficiaire_fiche']); ?></h2>
                <div class="mt-1 flex items-center gap-2">
                    <span class="text-[11px] text-slate-500">Date demande</span><strong class="text-[11px] text-slate-700"><?=date('d M Y, H:i:s',strtotime((string)$fiche['date_creat_fiche']))?></strong>
                    <a href="voir_fiche_details.php?num_fiche=<?= urlencode($fiche['num_fiche']); ?>" target="_blank"
                        class="ml-2 bg-white border border-indigo-200 rounded-full p-1 shadow hover:bg-indigo-50 transition"
                        title="Voir la fiche (détails)">
                        <svg class="w-5 h-5 text-indigo-700" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M12 4v16m0 0l-6-6m6 6l6-6" />
                        </svg>
                    </a>
                </div></div></div></div>
                <div data-partial-panel tabindex="-1" class="mb-4 hidden rounded-xl border border-indigo-100 bg-indigo-50 p-3 text-left shadow-lg">
                    <label class="block text-[11px] font-bold uppercase tracking-wide text-indigo-900">Montant à payer dans ce point
                        <input type="number" min="1" max="<?= htmlspecialchars((string)((float)$fiche['montant_fiche'])) ?>" step="1" value="<?= htmlspecialchars((string)((float)$fiche['montant_fiche'])) ?>" data-point-amount class="mt-1 w-full rounded-lg border border-indigo-200 bg-white px-3 py-2 text-sm font-black text-slate-900">
                    </label>
                    <label class="mt-2 hidden text-[11px] font-bold uppercase tracking-wide text-amber-900" data-next-date-wrap>Prochaine date du reliquat
                        <input type="date" min="<?= htmlspecialchars(date('Y-m-d',strtotime('+1 day'))) ?>" data-next-date class="mt-1 w-full rounded-lg border border-amber-200 bg-white px-3 py-2 text-sm text-slate-900">
                    </label>
                    <p class="mt-2 text-[10px] text-indigo-700" data-payment-mode>Paiement total prévu à la signature du boss.</p>
                </div>
                <div class="mb-3 rounded-xl border border-slate-200 bg-slate-50 p-3"><div class="flex flex-wrap gap-2 text-[10px] font-black uppercase"><?php foreach(['bci'=>'BCI','fne'=>'FNE','pv'=>'PV'] as $docKey=>$docLabel):$needed=$documentState['required'][$docKey];$present=$documentState['present'][$docKey];?><?php if($present):?><a target="_blank" rel="noopener" href="request/view_fiche_document.php?num_fiche=<?=urlencode((string)$fiche['num_fiche'])?>&amp;type=<?=$docKey?>" class="rounded-full bg-emerald-100 px-2 py-1 text-emerald-800 hover:bg-emerald-200" title="Ouvrir <?=$docLabel?>"><i class="fas fa-circle-check mr-1"></i><?=$docLabel?> · voir</a><?php elseif($docKey==='fne'&&!empty($documentState['invoiceDeferred'])):?><span class="rounded-full bg-amber-100 px-2 py-1 text-amber-900"><i class="fas fa-clock mr-1"></i>FNE · à collecter</span><?php else:?><span class="rounded-full px-2 py-1 <?=!$needed?'bg-slate-200 text-slate-500':'bg-rose-100 text-rose-800'?>"><i class="fas <?=!$needed?'fa-circle-minus':'fa-circle-xmark'?> mr-1"></i><?=$docLabel?><?=$needed?' · manquant':' · non requis'?></span><?php endif;?><?php endforeach;?></div><label class="mt-3 flex items-center gap-2 text-xs font-bold text-slate-700"><input type="checkbox" data-advance-toggle data-num="<?=htmlspecialchars((string)$fiche['num_fiche'])?>" <?=!empty($documentState['advance'])?'checked':''?>> Fiche d’acompte / avance — PV non obligatoire</label><?php if(!empty($documentState['required']['fne'])&&!$documentState['present']['fne']):?><label class="mt-3 flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 p-2 text-xs font-bold text-amber-950"><input type="checkbox" class="mt-0.5" data-invoice-deferred-toggle data-num="<?=htmlspecialchars((string)$fiche['num_fiche'])?>" <?=!empty($documentState['invoiceDeferred'])?'checked':''?>> <span>Facture à collecter plus tard<small class="mt-0.5 block font-medium text-amber-800">Autorise le passage au point du jour et conserve la mention sur la fiche.</small></span></label><?php endif;?><?php if(!$documentState['eligible']):?><button type="button" data-return-docs data-num="<?=htmlspecialchars((string)$fiche['num_fiche'])?>" class="mt-3 w-full rounded-lg bg-rose-700 px-3 py-2 text-xs font-black text-white">Retourner pour complément documentaire</button><?php else:?><p class="mt-2 text-xs font-bold text-emerald-700"><i class="fas fa-circle-check mr-1"></i>Documents suffisants pour le point du jour</p><?php endif;?></div>
                <div class="mb-3 flex flex-wrap gap-2 text-[10px] font-black uppercase"><span class="rounded-full bg-sky-100 px-2 py-1 text-sky-800"><i class="fas fa-phone mr-1"></i><?=htmlspecialchars((string)($fiche['tel_beneficiaire_fiche']??'Non renseigné'))?></span><span class="rounded-full px-2 py-1 <?=$hasCni?'bg-emerald-100 text-emerald-800':'bg-rose-100 text-rose-800'?>"><i class="fas <?=$hasCni?'fa-circle-check':'fa-circle-xmark'?> mr-1"></i>CNI</span><span class="rounded-full px-2 py-1 <?=$hasSignature?'bg-emerald-100 text-emerald-800':'bg-rose-100 text-rose-800'?>"><i class="fas <?=$hasSignature?'fa-circle-check':'fa-circle-xmark'?> mr-1"></i>Signature</span></div>
                <div class="pf-card-meta mb-4"><div class="pf-card-meta-item pf-card-meta-item--wide"><span class="pf-card-meta-label">Chantier</span><span class="pf-card-meta-value"><?=htmlspecialchars(trim((string)($fiche['chantier_code']??'').' - '.(string)($fiche['chantier_titre']??''))?:'Non affecté')?></span></div><div class="pf-card-meta-item"><span class="pf-card-meta-label">Déboursé</span><span class="pf-card-meta-value"><?=htmlspecialchars($ligneDevisLabel?:'Non renseigné')?></span></div><div class="pf-card-meta-item"><span class="pf-card-meta-label">Ligne déboursé</span><span class="pf-card-meta-value"><?=htmlspecialchars($ligneDebourseLabel?:'Non renseignée')?></span></div><div class="pf-card-meta-item"><span class="pf-card-meta-label">Précision</span><span class="pf-card-meta-value"><?=htmlspecialchars((string)($fiche['precision_fiche']??'—'))?></span></div><div class="pf-card-meta-item"><span class="pf-card-meta-label">Désignation</span><span class="pf-card-meta-value"><?=htmlspecialchars((string)($fiche['designation_fiche']??'—'))?></span></div><div class="pf-card-meta-item pf-card-meta-item--wide"><span class="pf-card-meta-label">Détail saisi</span><span class="pf-card-meta-value"><?=nl2br(htmlspecialchars(trim((string)($fiche['details_demande']??''))?:'Aucun détail manuel renseigné.'))?></span></div></div>
                <!-- Date + Action -->
                <div class="pf-card-footer mt-auto">
                    <span class="grid gap-1 text-xs text-slate-500"><span>Date demande</span><strong class="text-slate-700">Créée le <?=date('d M Y, H:i:s',strtotime((string)$fiche['date_creat_fiche']))?></strong><a target="_blank" href="voir_fiche_details.php?num_fiche=<?=urlencode((string)$fiche['num_fiche'])?>" class="mt-1 font-black text-indigo-700">Visualiser</a></span>
                    <div class="flex flex-wrap gap-2 justify-end" style="max-width:260px">
                        <button data-num="<?= htmlspecialchars($fiche['num_fiche']); ?>" class="js-analyse bg-amber-500 hover:bg-amber-600 text-white px-2 py-1.5 rounded-lg text-[11px] font-semibold shadow" title="Analyse budget">Aide</button>
                        <button data-num="<?= htmlspecialchars($fiche['num_fiche']); ?>" class="js-validate bg-green-600 hover:bg-green-700 text-white px-3 py-2 rounded-lg text-xs font-semibold shadow">Valider</button>
                        <button data-num="<?= htmlspecialchars($fiche['num_fiche']); ?>" class="js-report bg-amber-600 hover:bg-amber-700 text-white px-3 py-2 rounded-lg text-xs font-semibold shadow">Reporter</button>
                        <button data-num="<?= htmlspecialchars($fiche['num_fiche']); ?>" class="js-refuse bg-red-600 hover:bg-red-700 text-white px-3 py-2 rounded-lg text-xs font-semibold shadow">Refuser</button>
                        <?php if (!$hasBonCommande): ?>
                            <button data-num="<?= htmlspecialchars($fiche['num_fiche']); ?>" class="js-edit bg-blue-600 hover:bg-blue-700 text-white px-3 py-2 rounded-lg text-xs font-semibold shadow">Modifier</button>
                        <?php endif; ?>
                        <?php if ($hasBonCommande): ?>
                            <a href="request/export_bon_commande_pdf.php?num_fiche=<?= urlencode($baseNumFiche); ?>" class="bg-gray-900 hover:bg-gray-800 text-white px-3 py-2 rounded-lg text-xs font-semibold shadow" title="Exporter le bon de commande (PDF)">Export BC PDF</a>
                            <a href="bon_reception.php?num_fiche=<?= urlencode($baseNumFiche); ?>" class="bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-2 rounded-lg text-xs font-semibold shadow" title="Joindre le bon de réception signé (BR)"><?= $hasBonReception ? 'BR signé' : 'Uploader BR signé' ?></a>
                        <?php else: ?>
                            <a href="bon_commande.php?num_fiche=<?= urlencode($baseNumFiche); ?>" class="bg-gray-900 hover:bg-gray-800 text-white px-3 py-2 rounded-lg text-xs font-semibold shadow" title="Établir un bon de commande">Bon de commande</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if (empty($fiches)): ?>
        <div class="text-center text-gray-500 mt-16 text-lg">Aucune fiche prête pour validation achat.</div>
    <?php endif; ?>
</div>

<script>
window.purchaseValidationAvailable = <?= json_encode($treasuryValidationAvailable) ?>;
window.purchaseRealEntriesBalance = <?= json_encode($treasuryRealEntries) ?>;
window.purchaseUi = (() => {
    const tones = {
        success: {icon: 'fa-check', iconClass: 'bg-emerald-100 text-emerald-700', button: 'bg-emerald-600 hover:bg-emerald-700'},
        danger: {icon: 'fa-triangle-exclamation', iconClass: 'bg-rose-100 text-rose-700', button: 'bg-rose-600 hover:bg-rose-700'},
        warning: {icon: 'fa-clock', iconClass: 'bg-amber-100 text-amber-700', button: 'bg-amber-600 hover:bg-amber-700'},
        info: {icon: 'fa-circle-info', iconClass: 'bg-indigo-100 text-indigo-700', button: 'bg-indigo-600 hover:bg-indigo-700'}
    };
    const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));

    const toast = (message, tone = 'success') => {
        const style = tones[tone] || tones.info;
        const item = document.createElement('div');
        item.className = 'pointer-events-auto flex max-w-md translate-y-3 items-center gap-3 rounded-2xl border border-slate-200 bg-white px-4 py-3 opacity-0 shadow-2xl transition duration-300';
        item.innerHTML = `<span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl ${style.iconClass}"><i class="fas ${style.icon}"></i></span><strong class="text-sm text-slate-800">${escapeHtml(message)}</strong>`;
        let stack = document.getElementById('purchaseToastStack');
        if (!stack) {
            stack = document.createElement('div');
            stack.id = 'purchaseToastStack';
            stack.className = 'pointer-events-none fixed inset-0 z-[12000] grid place-content-center gap-2 p-4';
            document.body.appendChild(stack);
        }
        stack.appendChild(item);
        requestAnimationFrame(() => item.classList.remove('translate-y-3', 'opacity-0'));
        setTimeout(() => {
            item.classList.add('translate-y-3', 'opacity-0');
            setTimeout(() => item.remove(), 300);
        }, 2600);
    };

    const dialog = ({title = 'Information', message = '', tone = 'info', confirmLabel = 'Continuer', cancelLabel = 'Annuler', cancel = true, input = null} = {}) => new Promise(resolve => {
        const style = tones[tone] || tones.info;
        const modal = document.createElement('div');
        modal.className = 'fixed inset-0 z-[11500] flex items-center justify-center bg-slate-950/70 p-4 backdrop-blur-sm';
        modal.innerHTML = `<section class="w-full max-w-lg overflow-hidden rounded-[28px] bg-white shadow-2xl"><header class="bg-gradient-to-br from-slate-950 via-indigo-950 to-slate-900 p-6 text-white"><div class="flex items-start gap-4"><span class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl ${style.iconClass}"><i class="fas ${style.icon} text-lg"></i></span><div><p class="text-[10px] font-black uppercase tracking-[.22em] text-indigo-200">Validation achat</p><h2 class="mt-1 text-xl font-black">${escapeHtml(title)}</h2></div></div></header><div class="p-6"><p class="whitespace-pre-line text-sm leading-6 text-slate-600">${escapeHtml(message)}</p>${input ? `<label class="mt-5 block text-xs font-black uppercase tracking-wide text-slate-500">${escapeHtml(input.label || 'Précision')}<textarea data-dialog-input rows="4" placeholder="${escapeHtml(input.placeholder || '')}" class="mt-2 w-full rounded-2xl border border-slate-200 p-4 text-sm outline-none focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100"></textarea></label>` : ''}<div class="mt-6 flex justify-end gap-2">${cancel ? `<button type="button" data-cancel class="rounded-xl border border-slate-200 bg-white px-5 py-3 text-sm font-black text-slate-600 hover:bg-slate-50">${escapeHtml(cancelLabel)}</button>` : ''}<button type="button" data-confirm class="rounded-xl px-5 py-3 text-sm font-black text-white shadow-lg ${style.button}">${escapeHtml(confirmLabel)}</button></div></div></section>`;
        document.body.appendChild(modal);
        const finish = value => { modal.remove(); resolve(value); };
        modal.querySelector('[data-cancel]')?.addEventListener('click', () => finish(input ? null : false));
        modal.querySelector('[data-confirm]').addEventListener('click', () => finish(input ? modal.querySelector('[data-dialog-input]').value.trim() : true));
        modal.addEventListener('click', event => { if (event.target === modal && cancel) finish(input ? null : false); });
        const field = modal.querySelector('[data-dialog-input]');
        if (field) setTimeout(() => field.focus(), 60);
    });

    window.alert = message => { dialog({title:'Action impossible', message:String(message), tone:'danger', confirmLabel:'J’ai compris', cancel:false}); };
    return {toast, dialog, confirm: options => dialog({...options, cancel:true}), prompt: options => dialog({...options, input:options.input || {}, cancel:true})};
})();
</script>

<script>
    document.querySelectorAll('[data-multi-search]').forEach(root=>{const hidden=root.querySelector('input[type="hidden"]'),input=root.querySelector('[data-search-input]'),chips=root.querySelector('[data-chips]'),results=root.querySelector('[data-results]');let options=[];try{options=JSON.parse(root.dataset.options||'[]')}catch(e){}let selected=(hidden.value||'').split(',').map(v=>v.trim()).filter(Boolean);const sync=()=>{hidden.value=selected.join(',');chips.innerHTML=selected.map((value,index)=>`<button type="button" data-chip-index="${index}" class="rounded-full bg-indigo-100 px-3 py-1 text-xs font-bold text-indigo-800">${String(value).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]))} ×</button>`).join('');chips.querySelectorAll('[data-chip-index]').forEach(button=>button.onclick=()=>{selected.splice(Number(button.dataset.chipIndex),1);sync();render();});};const render=()=>{const q=input.value.trim().toLocaleLowerCase('fr');const visible=options.filter(value=>!selected.includes(value)&&(!q||value.toLocaleLowerCase('fr').includes(q))).slice(0,40);results.innerHTML=visible.map(value=>`<button type="button" class="block w-full rounded-lg px-3 py-2 text-left text-sm hover:bg-indigo-50" data-value="${encodeURIComponent(value)}">${String(value).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]))}</button>`).join('')||'<div class="px-3 py-2 text-xs text-slate-500">Aucun résultat</div>';results.classList.remove('hidden');results.querySelectorAll('[data-value]').forEach(button=>button.onclick=()=>{selected.push(decodeURIComponent(button.dataset.value));input.value='';sync();render();input.focus();});};input.addEventListener('focus',render);input.addEventListener('input',render);root.addEventListener('focusout',()=>setTimeout(()=>{if(!root.contains(document.activeElement))results.classList.add('hidden');},120));sync();});
    // Gestion sélection/export
    function updateTotalSelectionne() {
        let total = 0;
        let ids = [];
        document.querySelectorAll('.fiche-checkbox:checked').forEach(cb => {
            const card = cb.closest('.card') || cb.closest('.group');
            const montant = Number(card?.querySelector('[data-point-amount]')?.value || cb.dataset.amount || card?.dataset.amount || 0);
            total += montant;
            ids.push(cb.value);
        });
        // stocker en dataset pour export sélection
        document.body.dataset.selectedIds = ids.join(',');
        const treasuryPanel=document.getElementById('selectionTreasuryPanel');if(!treasuryPanel)return;const available=Number(treasuryPanel.dataset.available||0),remaining=available-total,format=v=>new Intl.NumberFormat('fr-FR',{maximumFractionDigits:0}).format(v)+' FCFA';
        document.getElementById('selectedAmount').textContent=format(total);document.getElementById('selectedCount').textContent=ids.length+' fiche'+(ids.length>1?'s':'');document.getElementById('remainingAmount').textContent=format(remaining);
        const status=document.getElementById('coverageStatus');status.textContent=!ids.length?'Sélectionnez des fiches pour simuler le paiement.':remaining>=0?'Trésorerie suffisante · paiement couvert':'Trésorerie insuffisante · manque '+format(Math.abs(remaining));status.className='flex items-center justify-center rounded-2xl px-4 text-center text-sm font-black '+(!ids.length?'bg-white/10':remaining>=0?'bg-emerald-500/30 ring-1 ring-emerald-300':'bg-rose-500/40 ring-1 ring-rose-300');
        if(ids.length&&remaining<0)openTreasuryInsufficiency(total,available);
    }

    function bindCardEvents(scope = document) {
        scope.querySelectorAll('.card').forEach(card=>{if(card.querySelector('[data-point-amount]'))return;const cb=card.querySelector('.fiche-checkbox'),amountText=card.querySelector('.text-green-600')?.textContent||'0',amount=Number(amountText.replace(/[^0-9,-]/g,'').replace(',','.'))||0;if(cb){cb.dataset.amount=String(amount);cb.dataset.originalAmount=String(amount);}const anchor=card.querySelector('.text-green-600');if(anchor){const box=document.createElement('div');box.className='mb-4 rounded-xl border border-indigo-100 bg-indigo-50 p-3 text-left';box.innerHTML=`<label class="block text-[11px] font-bold uppercase tracking-wide text-indigo-900">Montant à payer dans ce point<input type="number" min="1" max="${amount}" step="1" value="${amount}" data-point-amount class="mt-1 w-full rounded-lg border border-indigo-200 bg-white px-3 py-2 text-sm font-black text-slate-900"></label><label class="mt-2 hidden text-[11px] font-bold uppercase tracking-wide text-amber-900" data-next-date-wrap>Prochaine date du reliquat<input type="date" data-next-date class="mt-1 w-full rounded-lg border border-amber-200 bg-white px-3 py-2 text-sm text-slate-900"></label><p class="mt-2 text-[10px] text-indigo-700" data-payment-mode>Paiement total prévu à la signature du boss.</p>`;anchor.after(box);}});
        scope.querySelectorAll('.fiche-checkbox').forEach(cb => {
            if (!cb.dataset.amount) {
                const amountText = cb.closest('.card')?.querySelector('.text-green-600')?.textContent || '0';
                cb.dataset.amount = String(Number(amountText.replace(/[^0-9,-]/g, '').replace(',', '.')) || 0);
            }
            cb.addEventListener('change', updateTotalSelectionne);
        });
        scope.querySelectorAll('[data-point-amount]').forEach(input=>{if(input.dataset.bound)return;input.dataset.bound='1';const card=input.closest('.card'),cb=card?.querySelector('.fiche-checkbox'),wrap=card?.querySelector('[data-next-date-wrap]'),note=card?.querySelector('[data-payment-mode]'),refresh=()=>{const original=Number(cb?.dataset.originalAmount||cb?.dataset.amount||input.max||0),amount=Number(input.value||0),partial=amount>0&&amount<original;wrap?.classList.toggle('hidden',!partial);if(note)note.textContent=partial?'Décaissement partiel : le reliquat sera reprogrammé à la date indiquée.':'Paiement total prévu à la signature du boss.';if(cb?.checked)updateTotalSelectionne();};input.addEventListener('input',refresh);refresh();});
        scope.querySelectorAll('.js-validate').forEach(btn => btn.addEventListener('click', () => handleAction(btn, 'validate')));
        scope.querySelectorAll('.js-refuse').forEach(btn => btn.addEventListener('click', () => openRefuse(btn.getAttribute('data-num'))));
        scope.querySelectorAll('.js-report').forEach(btn => btn.addEventListener('click', () => openReport(btn.getAttribute('data-num'))));
        scope.querySelectorAll('.js-edit').forEach(btn => btn.addEventListener('click', () => openEdit(btn.getAttribute('data-num'))));
        scope.querySelectorAll('.js-analyse').forEach(btn => btn.addEventListener('click', () => fetchAnalyse(btn.getAttribute('data-num'))));
        loadAnalysesForCards(scope);
    }

    function computeCardAlerts(card, analyse) {
        const alerts = [];
        const ratio = Number(analyse && analyse.ratio);
        const ratioValid = Number.isFinite(ratio);
        if (ratioValid) {
            if (ratio > 1) {
                alerts.push({
                    code: 'HB',
                    label: 'Hors budget',
                    severity: 'danger',
                    icon: 'fa-triangle-exclamation'
                });
            } else if (ratio >= 0.95) {
                alerts.push({
                    code: 'LIM',
                    label: 'Budget limite',
                    severity: 'warn',
                    icon: 'fa-circle-exclamation'
                });
            }
        }

        const hasBC = card && card.dataset && card.dataset.hasBc === '1';
        const hasBR = card && card.dataset && card.dataset.hasBr === '1';
        if (hasBC && !hasBR) {
            alerts.push({
                code: 'BR-',
                label: 'BR manquant',
                severity: 'danger',
                icon: 'fa-file-lines'
            });
        }

        const hasCni = card && card.dataset && card.dataset.hasCni === '1';
        const hasSignature = card && card.dataset && card.dataset.hasSignature === '1';
        if (!hasCni) {
            alerts.push({
                code: 'CNI-',
                label: 'CNI manquante',
                severity: 'warn',
                icon: 'fa-id-card'
            });
        }
        if (!hasSignature) {
            alerts.push({
                code: 'SIGN-',
                label: 'Signature manquante',
                severity: 'warn',
                icon: 'fa-pen-nib'
            });
        }

        return alerts;
    }

    function renderCardAlertPill(card, alerts) {
        const pill = card ? card.querySelector('.card-alert-pill') : null;
        if (!pill) return;

        if (!alerts || !alerts.length) {
            pill.classList.add('hidden');
            pill.innerHTML = '';
            pill.removeAttribute('title');
            pill.classList.remove('border-red-600', 'text-red-700', 'border-amber-600', 'text-amber-700', 'border-slate-500', 'text-slate-700');
            pill.classList.add('border-slate-300', 'text-slate-700');
            return;
        }

        const hasDanger = alerts.some(a => a.severity === 'danger');
        const hasWarn = alerts.some(a => a.severity === 'warn');
        const top = hasDanger ? (alerts.find(a => a.severity === 'danger') || alerts[0]) : alerts[0];
        const codes = alerts.map(a => a.code).join(' ');
        const title = 'Alertes: ' + alerts.map(a => `${a.code} = ${a.label}`).join(' | ');

        pill.classList.remove('hidden');
        pill.classList.remove('border-slate-300', 'text-slate-700', 'border-slate-500');
        pill.classList.remove('border-red-600', 'text-red-700', 'border-amber-600', 'text-amber-700');
        if (hasDanger) {
            pill.classList.add('border-red-600', 'text-red-700');
        } else if (hasWarn) {
            pill.classList.add('border-amber-600', 'text-amber-700');
        } else {
            pill.classList.add('border-slate-500', 'text-slate-700');
        }

        const levelLabel = hasDanger ? 'PROBLÈME' : 'ATTENTION';
        pill.innerHTML = `<i class="fa-solid ${top.icon}" aria-hidden="true"></i><span class="ml-1">${levelLabel} · ${codes}</span>`;
        pill.title = title;
    }

    // Chargement dynamique des icônes d'analyse budget (copie adaptée)
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
                        el.title = map.label + ' | Ratio ' + rPct;

                        const card = el.closest('.card');
                        const alerts = computeCardAlerts(card, a);
                        renderCardAlertPill(card, alerts);
                    } else {
                        el.innerHTML = '<span style="display:block;width:10px;height:10px;border-radius:50%;background:#cbd5e1"></span>';
                        el.style.background = '#fff';
                        el.style.borderColor = '#e2e8f0';
                        el.title = 'Analyse indisponible';

                        const card = el.closest('.card');
                        const alerts = computeCardAlerts(card, null);
                        renderCardAlertPill(card, alerts);
                    }
                } catch (e) {
                    el.innerHTML = '<span style="display:block;width:10px;height:10px;border-radius:50%;background:#e2e8f0"></span>';
                    el.style.background = '#fff';
                    el.style.borderColor = '#e2e8f0';
                    el.title = 'Analyse indisponible';

                    const card = el.closest('.card');
                    const alerts = computeCardAlerts(card, null);
                    renderCardAlertPill(card, alerts);
                } finally {
                    el.setAttribute('data-loaded', '1');
                }
            }
        }
        const workers = [];
        for (let i = 0; i < pool; i++) workers.push(worker());
        await Promise.all(workers);
    }

    // Modal analyse budget réutilisé
    const analyseModal = document.createElement('div');
    analyseModal.id = 'analyse-budget-modal';
    analyseModal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.45);display:none;z-index:9998;';
    analyseModal.innerHTML = `<div style="max-width:660px;margin:60px auto;background:#fff;border-radius:14px;box-shadow:0 10px 28px -4px rgba(0,0,0,.25);overflow:hidden;font-family:system-ui,Segoe UI,Arial,sans-serif">\n<div style='display:flex;align-items:center;justify-content:space-between;padding:14px 20px;background:#334155;color:#fff'>\n<h3 style='margin:0;font-size:16px;font-weight:600'>Analyse budget fiche <span id='ab-fiche-num'></span></h3>\n<button id='ab-close' style='background:transparent;border:0;color:#fff;font-size:20px;cursor:pointer'>&times;</button>\n</div>\n<div id='ab-body' style='padding:18px 22px;font-size:14px;line-height:1.4;max-height:58vh;overflow:auto'>Chargement...</div>\n<div style='padding:12px 18px;display:flex;justify-content:flex-end;gap:8px;background:#f8fafc'>\n<button id='ab-close-footer' style='background:#334155;color:#fff;border:0;padding:8px 16px;border-radius:8px;font-size:13px;cursor:pointer'>Fermer</button>\n</div>\n</div>`;
    document.body.appendChild(analyseModal);
    analyseModal.addEventListener('click', e => {
        if (e.target.id === 'ab-close' || e.target.id === 'ab-close-footer') analyseModal.style.display = 'none';
    });

    function renderAnalyse(a) {
        if (!a) return '<div style="color:#b91c1c">Analyse indisponible.</div>';
        const fmt = n => Number(n).toLocaleString('fr-FR', {
            minimumFractionDigits: 0
        });
        const ratio = Number(a.ratio);
        const ratioValid = Number.isFinite(ratio);
        const ratioPct = ratioValid ? (ratio * 100).toFixed(1) + '%' : '?';

        // Récap simple : dans le budget ou non ?
        const inBudget = ratioValid ? (ratio <= 1) : null;
        const recapLabel = inBudget === null ? 'STATUT BUDGET : INCONNU' : (inBudget ? 'STATUT BUDGET : DANS LE BUDGET' : 'STATUT BUDGET : HORS BUDGET');
        const recapBg = inBudget === null ? '#e2e8f0' : (inBudget ? '#dcfce7' : '#fee2e2');
        const recapBorder = inBudget === null ? '#cbd5e1' : (inBudget ? '#86efac' : '#fecaca');
        const recapColor = inBudget === null ? '#334155' : (inBudget ? '#166534' : '#991b1b');
        const recapPill = `<div style='display:flex;align-items:center;justify-content:space-between;gap:10px;margin:0 0 12px 0;padding:10px 12px;background:${recapBg};border:1px solid ${recapBorder};border-radius:12px'>
            <div style='font-weight:800;letter-spacing:.2px;color:${recapColor}'>${recapLabel}</div>
            <div style='font-size:12px;color:#475569'>Ratio : <span style='font-weight:700;color:#0f172a'>${ratioPct}</span></div>
        </div>`;
        // Badge niveau
        const badges = {
            large_marge: ['#0f766e', '#ccfbf1', 'Large marge'],
            marge: ['#0369a1', '#e0f2fe', 'Marge'],
            limite: ['#92400e', '#fef3c7', 'Limite'],
            depasse_legere: ['#b45309', '#fde68a', 'Dépasse +'],
            depasse_forte: ['#b91c1c', '#fee2e2', 'Dépasse !!!'],
            invalide: ['#475569', '#e2e8f0', 'Invalide']
        };
        const b = badges[a.niveau] || badges.invalide;
        // Bar progression
        const perc = ratioValid ? Math.min(115, Math.round(ratio * 100)) : 0;
        const col = !ratioValid ? '#94a3b8' : (ratio <= 1 ? '#16a34a' : (ratio <= 1.1 ? '#f59e0b' : '#dc2626'));
        const bar = `<div style='background:#e2e8f0;height:10px;border-radius:6px;overflow:hidden;margin-top:6px'><div style='width:${perc}%;background:${col};height:100%'></div></div>`;
        return `<div>
            ${recapPill}
            <div style='display:flex;flex-wrap:wrap;gap:10px;margin-bottom:14px'>
                <div style='background:${b[1]};color:${b[0]};padding:4px 10px;border-radius:20px;font-size:12px;font-weight:600'>${b[2]}</div>
                <div style='background:#f1f5f9;color:#334155;padding:4px 10px;border-radius:20px;font-size:12px'>Ligne #${a.ligne_id||''}</div>
            </div>
            <table style='width:100%;border-collapse:collapse;font-size:13px'>
                <tbody>
                    <tr><td style='padding:4px 6px;color:#475569'>Ligne</td><td style='padding:4px 6px;font-weight:600'>${a.ligne_designation||''}</td></tr>
                    <tr><td style='padding:4px 6px;color:#475569'>Catégorie</td><td style='padding:4px 6px'>${a.ligne_categorie||''}</td></tr>
                    <tr><td style='padding:4px 6px;color:#475569'>Montant prévu</td><td style='padding:4px 6px'>${fmt(a.montant_ligne)} CFA</td></tr>
                    <tr><td style='padding:4px 6px;color:#475569'>Déjà décaissé</td><td style='padding:4px 6px'>${fmt(a.total_deja_decaisse)} CFA</td></tr>
                    <tr><td style='padding:4px 6px;color:#475569'>Non décaissé (autres)</td><td style='padding:4px 6px'>${fmt(a.total_non_decaisse||0)} CFA</td></tr>
                    <tr><td style='padding:4px 6px;color:#475569'>Montant fiche</td><td style='padding:4px 6px'>${fmt(a.montant_fiche)} CFA</td></tr>
                    <tr><td style='padding:4px 6px;color:#475569'>Projection</td><td style='padding:4px 6px'>${fmt(a.projection_decaisse)} CFA</td></tr>
                    <tr><td style='padding:4px 6px;color:#475569'>Ecart restant</td><td style='padding:4px 6px'>${fmt(a.ecart_restant)} CFA</td></tr>
                    <tr><td style='padding:4px 6px;color:#475569'>Ratio utilisation</td><td style='padding:4px 6px'>${ratioPct}</td></tr>
                </tbody>
            </table>
            ${bar}
            <div style='margin-top:14px;padding:10px 12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;font-size:13px;color:#334155'>${a.conseil||''}</div>
            <div style='margin-top:14px;display:flex;gap:10px;flex-wrap:wrap'>
                <button id='ab-show-fiches' data-ligne='${a.ligne_id||''}' style='background:#2563eb;color:#fff;border:0;padding:8px 14px;border-radius:8px;font-size:12px;cursor:pointer'>Fiches ligne</button>
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
                const rows = (j.fiches_existantes || []).map(f => {
                    const dateFr = (() => {
                        try {
                            return new Date(f.date_creation).toLocaleDateString('fr-FR', {
                                day: '2-digit',
                                month: '2-digit',
                                year: '2-digit'
                            });
                        } catch (_) {
                            return f.date_creation || '';
                        }
                    })();
                    const statut = f.approuve ? 'Approuvé' : (f.conforme ? 'Conforme' : (f.otp_valide ? 'OTP' : ''));
                    const demandeur = (f.beficiaire_fiche || '').replace(/</g, '&lt;');
                    return `<tr>
                        <td style='padding:4px 6px;border-bottom:1px solid #f1f5f9;font-size:12px'>${f.num_fiche||''}</td>
                        <td style='padding:4px 6px;border-bottom:1px solid #f1f5f9;font-size:12px'>${demandeur}</td>
                        <td style='padding:4px 6px;border-bottom:1px solid #f1f5f9;font-size:12px;text-align:right'>${(Number(f.montant_fiche)||0).toLocaleString('fr-FR')}</td>
                        <td style='padding:4px 6px;border-bottom:1px solid #f1f5f9;font-size:12px'>${dateFr}</td>
                        <td style='padding:4px 6px;border-bottom:1px solid #f1f5f9;font-size:12px'>${f.decaisse?'<span style="color:#16a34a;font-weight:600">Oui</span>':'<span style="color:#f59e0b">Non</span>'}</td>
                        <td style='padding:4px 6px;border-bottom:1px solid #f1f5f9;font-size:12px'>${statut}</td>
                        <td style='padding:4px 6px;border-bottom:1px solid #f1f5f9;font-size:12px'>${f.num_fiche?`<a href='voir_fiche_details.php?num_fiche=${encodeURIComponent(f.num_fiche)}' target='_blank' style='display:inline-block;background:#2563eb;color:#fff;padding:4px 8px;border-radius:6px;font-size:11px;text-decoration:none'>Voir</a>`:''}</td>
                    </tr>`;
                }).join('');
                const total = (j.fiches_existantes || []).reduce((s, f) => s + Number(f.montant_fiche || 0), 0);
                wrap.innerHTML = `<h4 style='margin:0 0 8px;font-size:14px'>Fiches de la ligne #${ligneId}</h4>
                    <div style='overflow:auto'>
                        <table style='width:100%;min-width:760px;border-collapse:collapse;background:#fff;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden'>
                            <thead><tr style='background:#f1f5f9'>
                                <th style='padding:6px 6px;font-size:12px;text-align:left;color:#475569'>Num</th>
                                <th style='padding:6px 6px;font-size:12px;text-align:left;color:#475569'>Bénéficiaire</th>
                                <th style='padding:6px 6px;font-size:12px;text-align:right;color:#475569'>Montant</th>
                                <th style='padding:6px 6px;font-size:12px;text-align:left;color:#475569'>Date</th>
                                <th style='padding:6px 6px;font-size:12px;text-align:left;color:#475569'>Décaissé</th>
                                <th style='padding:6px 6px;font-size:12px;text-align:left;color:#475569'>Statut</th>
                                <th style='padding:6px 6px;font-size:12px;text-align:left;color:#475569'>Voir</th>
                            </tr></thead>
                            <tbody>${rows}</tbody>
                        </table>
                    </div>
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

    async function handleAction(btn, action) {
        const num = btn.getAttribute('data-num');
        const url = action === 'validate' ? 'request/achat_validate.php' : '#';
        if (action === 'validate') {
            const card=btn.closest('.card')||btn.closest('.group');
            const checkbox=[...card.querySelectorAll('.fiche-checkbox')].find(item=>item.value===num);
            const amount=Number(card.querySelector('[data-point-amount]')?.value||checkbox?.dataset.amount||0);
            const available=Number(document.getElementById('selectionTreasuryPanel')?.dataset.available??window.purchaseValidationAvailable??0);
            const covered=amount<=available;
            const confirmed=await purchaseUi.confirm({
                title:covered?'Confirmer la validation':'Couverture de trésorerie insuffisante',
                message:`Fiche ${num}\nMontant : ${new Intl.NumberFormat('fr-FR').format(amount)} FCFA\nDisponible mobilisable : ${new Intl.NumberFormat('fr-FR').format(available)} FCFA${covered?'':'\n\nLa validation restera bloquée tant qu’un transfert des entrées réelles vers le disponible du jour ne couvrira pas le montant.'}`,
                tone:covered?'success':'danger',
                confirmLabel:covered?'Valider la fiche':'Transférer vers le disponible'
            });
            if(!confirmed)return;
            if(!covered){await openTreasuryInsufficiency(amount,available);return;}
        }
        btn.disabled = true;
        btn.classList.add('opacity-60');
        try {
            const resp = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: new URLSearchParams({
                    num_fiche: num
                })
            });
            const data = await resp.json();
            if (data.ok) {
                const card = btn.closest('.card') || btn.closest('.group');
                card?.parentNode?.removeChild(card);
                updateTotalSelectionne();
                purchaseUi.toast('Fiche '+num+' validée avec succès.','success');
            } else {
                const reason=data.error||'Erreur inconnue';
                const amount=Number((btn.closest('.card')||btn.closest('.group'))?.querySelector('[data-point-amount]')?.value||0);
                const available=Number(document.getElementById('selectionTreasuryPanel')?.dataset.available??window.purchaseValidationAvailable??0);
                if(/trésorerie|disponible|solde négatif|décaissement bloqué/i.test(reason))await openTreasuryInsufficiency(amount,available);
                else await purchaseUi.dialog({title:'Validation bloquée',message:reason,tone:'danger',confirmLabel:'J’ai compris',cancel:false});
            }
        } catch (e) {
            await purchaseUi.dialog({title:'Service indisponible',message:'La validation n’a pas pu aboutir. '+e.message,tone:'danger',confirmLabel:'Fermer',cancel:false});
        } finally {
            btn.disabled = false;
            btn.classList.remove('opacity-60');
        }
    }

    // ---- Modales ----
    // Refus
    const refuseWrap = document.createElement('div');
    refuseWrap.id = 'refuse-wrap';
    refuseWrap.className = 'fixed inset-0 z-[11000] hidden items-center justify-center bg-slate-950/70 p-4 backdrop-blur-sm';
    refuseWrap.innerHTML = `<section class='w-full max-w-lg overflow-hidden rounded-[28px] bg-white shadow-2xl'><header class='bg-gradient-to-br from-slate-950 via-rose-950 to-slate-900 p-6 text-white'><div class='flex items-center gap-4'><span class='grid h-12 w-12 place-items-center rounded-2xl bg-rose-100 text-rose-700'><i class='fas fa-ban'></i></span><div><p class='text-[10px] font-black uppercase tracking-[.22em] text-rose-200'>Décision achat</p><h3 class='text-xl font-black'>Refuser la fiche <span id='refuse-num'></span></h3></div></div></header><div class='p-6'><p class='mb-4 text-sm leading-6 text-slate-500'>Le motif sera conservé dans l’historique et communiqué au demandeur.</p><label class='text-xs font-black uppercase tracking-wide text-slate-500'>Motif obligatoire<textarea id='refuse-motif' class='mt-2 h-36 w-full rounded-2xl border border-slate-200 p-4 text-sm outline-none focus:border-rose-400 focus:ring-4 focus:ring-rose-100' placeholder='Expliquez clairement la raison du refus…'></textarea></label><div class='mt-6 flex justify-end gap-2'><button id='refuse-cancel' class='rounded-xl border border-slate-200 px-5 py-3 text-sm font-black text-slate-600'>Annuler</button><button id='refuse-submit' class='rounded-xl bg-rose-600 px-5 py-3 text-sm font-black text-white shadow-lg hover:bg-rose-700'>Confirmer le refus</button></div></div></section>`;
    document.body.appendChild(refuseWrap);

    function openRefuse(num) {
        document.getElementById('refuse-num').textContent = num;
        document.getElementById('refuse-motif').value = '';
        refuseWrap.classList.remove('hidden');
        refuseWrap.classList.add('flex');
    }
    document.addEventListener('click', e => {
        if (e.target.id === 'refuse-cancel') { refuseWrap.classList.add('hidden'); refuseWrap.classList.remove('flex'); }
    });
    document.getElementById('refuse-submit').addEventListener('click', async () => {
        const num = document.getElementById('refuse-num').textContent;
        const motif = document.getElementById('refuse-motif').value.trim();
        if (!motif) {
            alert('Motif requis');
            return;
        }
        try {
            const r = await fetch('request/achat_refuse.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: new URLSearchParams({
                    num_fiche: num,
                    motif
                })
            });
            const j = await r.json();
            if (j.ok) {
                removeCard(num);
                refuseWrap.classList.add('hidden');
                refuseWrap.classList.remove('flex');
                purchaseUi.toast('Fiche '+num+' refusée.','warning');
            } else alert(j.error || 'Erreur refus');
        } catch (err) {
            alert(err.message);
        }
    });

    // Report
    const reportWrap = document.createElement('div');
    reportWrap.id = 'report-wrap';
    reportWrap.className = 'fixed inset-0 z-[11000] hidden items-center justify-center bg-slate-950/70 p-4 backdrop-blur-sm';
    reportWrap.innerHTML = `<section class='w-full max-w-lg overflow-hidden rounded-[28px] bg-white shadow-2xl'><header class='bg-gradient-to-br from-slate-950 via-amber-950 to-slate-900 p-6 text-white'><div class='flex items-center gap-4'><span class='grid h-12 w-12 place-items-center rounded-2xl bg-amber-100 text-amber-700'><i class='fas fa-calendar-days'></i></span><div><p class='text-[10px] font-black uppercase tracking-[.22em] text-amber-200'>Planification</p><h3 class='text-xl font-black'>Reporter la fiche <span id='report-num'></span></h3></div></div></header><div class='p-6'><p class='mb-4 text-sm leading-6 text-slate-500'>Choisissez la nouvelle date minimale de traitement du décaissement.</p><label class='text-xs font-black uppercase tracking-wide text-slate-500'>Nouvelle date<input type='date' id='report-date' class='mt-2 w-full rounded-2xl border border-slate-200 p-4 text-base font-black outline-none focus:border-amber-400 focus:ring-4 focus:ring-amber-100'/></label><div class='mt-6 flex justify-end gap-2'><button id='report-cancel' class='rounded-xl border border-slate-200 px-5 py-3 text-sm font-black text-slate-600'>Annuler</button><button id='report-submit' class='rounded-xl bg-amber-500 px-5 py-3 text-sm font-black text-slate-950 shadow-lg hover:bg-amber-600'>Confirmer le report</button></div></div></section>`;
    document.body.appendChild(reportWrap);

    function openReport(num) {
        document.getElementById('report-num').textContent = num;
        const dateInput = document.getElementById('report-date');
        const today = new Date().toISOString().slice(0, 10);
        dateInput.min = today;
        dateInput.removeAttribute('max');
        dateInput.value = today;
        reportWrap.classList.remove('hidden');
    }
    document.addEventListener('click', e => {
        if (e.target.id === 'report-cancel') { reportWrap.classList.add('hidden'); reportWrap.classList.remove('flex'); }
    });
    document.getElementById('report-submit').addEventListener('click', async () => {
        const num = document.getElementById('report-num').textContent;
        const d = document.getElementById('report-date').value;
        if (!d) {
            alert('Date requise');
            return;
        }
        try {
            const r = await fetch('request/achat_report.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: new URLSearchParams({
                    num_fiche: num,
                    date_min: d
                })
            });
            const j = await r.json();
            if (j.ok) {
                removeCard(num);
                reportWrap.classList.add('hidden');
                reportWrap.classList.remove('flex');
                purchaseUi.toast('Fiche '+num+' reportée avec succès.','success');
            } else alert(j.error || 'Erreur report');
        } catch (err) {
            alert(err.message);
        }
    });

    // Edit
    const editWrap = document.createElement('div');
    editWrap.id = 'edit-wrap';
    editWrap.className = 'fixed inset-0 z-[11000] hidden items-center justify-center bg-slate-950/70 p-4 backdrop-blur-sm';
    editWrap.innerHTML = `<section class='w-full max-w-xl overflow-hidden rounded-[28px] bg-white shadow-2xl'><header class='bg-gradient-to-br from-slate-950 via-indigo-950 to-sky-900 p-6 text-white'><div class='flex items-center gap-4'><span class='grid h-12 w-12 place-items-center rounded-2xl bg-indigo-100 text-indigo-700'><i class='fas fa-pen-to-square'></i></span><div><p class='text-[10px] font-black uppercase tracking-[.22em] text-indigo-200'>Mise à jour</p><h3 class='text-xl font-black'>Modifier la fiche <span id='edit-num'></span></h3></div></div></header><form id='edit-form' class='grid gap-5 p-6'><label class='text-xs font-black uppercase tracking-wide text-slate-500'>Bénéficiaire<input id='edit-benef' name='beficiaire_fiche' class='mt-2 w-full rounded-2xl border border-slate-200 p-4 text-sm outline-none focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100' required></label><label class='text-xs font-black uppercase tracking-wide text-slate-500'>Montant demandé<input id='edit-montant' name='montant_fiche' type='number' min='1' class='mt-2 w-full rounded-2xl border border-slate-200 p-4 text-base font-black outline-none focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100' required></label><div class='flex justify-end gap-2 pt-1'><button type='button' id='edit-cancel' class='rounded-xl border border-slate-200 px-5 py-3 text-sm font-black text-slate-600'>Annuler</button><button type='submit' class='rounded-xl bg-indigo-600 px-5 py-3 text-sm font-black text-white shadow-lg hover:bg-indigo-700'>Enregistrer les modifications</button></div></form></section>`;
    document.body.appendChild(editWrap);

    function openEdit(num) {
        document.getElementById('edit-num').textContent = num;
        document.getElementById('edit-benef').value = '';
        document.getElementById('edit-montant').value = '';
        editWrap.classList.remove('hidden');
        editWrap.classList.add('flex');
        fetch('request/get_fiche_details.php?num_fiche=' + encodeURIComponent(num)).then(r => r.json()).then(j => {
            if (j.ok && j.fiche) {
                document.getElementById('edit-benef').value = j.fiche.beficiaire_fiche || '';
                document.getElementById('edit-montant').value = j.fiche.montant_fiche || '';
            }
        }).catch(() => {});
    }
    document.addEventListener('click', e => {
        if (e.target.id === 'edit-cancel') { editWrap.classList.add('hidden'); editWrap.classList.remove('flex'); }
    });
    document.getElementById('edit-form').addEventListener('submit', async e => {
        e.preventDefault();
        const num = document.getElementById('edit-num').textContent;
        const benef = document.getElementById('edit-benef').value.trim();
        const montant = document.getElementById('edit-montant').value;
        try {
            const r = await fetch('request/achat_modifier.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: new URLSearchParams({
                    num_fiche: num,
                    beficiaire_fiche: benef,
                    montant_fiche: montant
                })
            });
            const j = await r.json();
            if (j.ok) {
                updateCardFields(num, benef, montant);
                editWrap.classList.add('hidden');
                editWrap.classList.remove('flex');
                purchaseUi.toast('Modifications enregistrées pour '+num+'.','success');
            } else alert(j.error || 'Erreur modification');
        } catch (err) {
            alert(err.message);
        }
    });

    function removeCard(num) {
        document.querySelectorAll('.card').forEach(c => {
            if (c.querySelector('[data-num="' + num + '"]')) c.remove();
        });
    }

    function updateCardFields(num, benef, montant) {
        document.querySelectorAll('.card').forEach(c => {
            if (c.querySelector('[data-num="' + num + '"]')) {
                const h = c.querySelector('h2');
                if (h) h.textContent = benef;
                const m = c.querySelector('.text-green-600');
                if (m) m.textContent = currencyFormatCFA(montant);
            }
        });
    }

    // Recherche AJAX
    document.getElementById('searchForm').addEventListener('submit', async function(e) {
        // Le rendu serveur reste la source unique des cartes compactes et de leur conformité documentaire.
        return;
        const params = new URLSearchParams(new FormData(this));
        const res = await fetch('request/fiches_achat_list.php?' + params.toString());
        const json = await res.json();
        if (!json.ok) {
            alert('Erreur: ' + (json.error || ''));
            return;
        }
        document.getElementById('montant-total').textContent = currencyFormatCFA(json.total);
        const wrap = document.getElementById('cards-container');
        wrap.innerHTML = json.items.map(item => cardTemplate(item)).join('');
        bindCardEvents(wrap);
        updateTotalSelectionne();
    });

    function cardTemplate(f) {
        const typeClass = f.chantier_id ? 'fiche-chantier' : (f.serv_bureau_banamur_id ? 'fiche-bureau' : 'fiche-autre');
        const badge = f.chantier_id ? '<span class="badge badge-blue">Chantier</span>' : (f.serv_bureau_banamur_id ? '<span class="badge badge-purple">Bureau</span>' : '<span class="badge badge-gray">Autre</span>');
        const etat = parseInt(f.etat_fiche || 0, 10);
        const dateMin = f.date_decaissement_minimum;
        const isFuture = dateMin && new Date(dateMin) > new Date();
        let statusLabel = 'Prêt validation';
        let statusColor = 'bg-green-100 text-green-700';
        if (etat === 2) {
            statusLabel = 'Refusé achat';
            statusColor = 'bg-red-100 text-red-700';
        } else if (isFuture) {
            statusLabel = 'Reporté';
            statusColor = 'bg-amber-100 text-amber-700';
        }

        const baseNum = (f.workflow_base_num_fiche || f.num_fiche || '').toString();
        const hasBC = !!(f.bc_id || f.bc_number);
        const hasBR = !!(f.br_id);
        const ligneDebourseLabel = (f.ligne_debourse_label || '').toString();
        const ligneDevisLabel = (f.ligne_devis_label || '').toString();

        const refusLines = (f.details_demande || '').split(/\n/).filter(l => l.startsWith('[REFUS_ACHAT'));
        const refusBadge = refusLines.length ? `<span class=\"absolute top-16 right-4 bg-red-600 text-white w-6 h-6 flex items-center justify-center rounded-full text-[10px] font-bold shadow\" title=\"${escapeHtml(refusLines.join('\n'))}\">R</span>` : '';
        const photoUrl = f.photo_url ? f.photo_url : (f.photo_beneficiaire ? ('../uploads/photo/' + encodeURIComponent(f.photo_beneficiaire)) : null);
        const photo = photoUrl ? `<img src="${photoUrl}" alt="Photo de ${escapeHtml(f.beficiaire_fiche||'')}" class="w-20 h-20 rounded-full object-cover border-4 border-indigo-400 shadow-md bg-gray-100">` :
            `<div class=\"w-20 h-20 flex items-center justify-center rounded-full border-4 border-indigo-400 shadow-md bg-gray-100\"><svg class=\"w-12 h-12 text-gray-400\" fill=\"currentColor\" viewBox=\"0 0 24 24\"><path d=\"M12 12c2.7 0 4.5-2.1 4.5-4.5S14.7 3 12 3 7.5 5.1 7.5 7.5 9.3 12 12 12zm0 2c-3 0-9 1.5-9 4.5V21h18v-2.5c0-3-6-4.5-9-4.5z\" /></svg></div>`;

        const bcLine = hasBC ? `<p><strong>BC :</strong> <span class=\"text-gray-900 font-semibold\">Généré</span></p>` : '';
        const brLine = hasBC ? `<p><strong>BR :</strong> <span class=\"${hasBR ? 'text-green-700 font-semibold' : 'text-red-700 font-semibold'}\">${hasBR ? 'Reçu' : 'Manquant'}</span></p>` : '';

        const editBtn = hasBC ? '' : `<button data-num=\"${escapeHtml(f.num_fiche||'')}\" class=\"js-edit bg-blue-600 hover:bg-blue-700 text-white px-3 py-2 rounded-lg text-xs font-semibold shadow\">Modifier</button>`;
        const bcBtn = hasBC ?
            `<a href=\"request/export_bon_commande_pdf.php?num_fiche=${encodeURIComponent(baseNum)}\" class=\"bg-gray-900 hover:bg-gray-800 text-white px-3 py-2 rounded-lg text-xs font-semibold shadow\" title=\"Exporter le bon de commande (PDF)\">Export BC PDF</a>` :
            `<a href=\"bon_commande.php?num_fiche=${encodeURIComponent(baseNum)}\" class=\"bg-gray-900 hover:bg-gray-800 text-white px-3 py-2 rounded-lg text-xs font-semibold shadow\" title=\"Établir un bon de commande\">Bon de commande</a>`;
        const brBtn = hasBC ?
            `<a href=\"bon_reception.php?num_fiche=${encodeURIComponent(baseNum)}\" class=\"bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-2 rounded-lg text-xs font-semibold shadow\" title=\"Joindre le bon de réception signé (BR)\">${hasBR ? 'BR signé' : 'Uploader BR signé'}</a>` :
            '';

        const hasCni = !!((f.cni_beneficiaire || '').toString().trim());
        const hasSignature = !!((f.signature_beneficiaire || '').toString().trim());

        return `<div class=\"bg-white rounded-2xl shadow-xl card flex flex-col justify-between p-6 relative group ${typeClass} animate-fade-in\" data-etat=\"${etat}\" data-date-min=\"${dateMin||''}\" data-details=\"${escapeHtml(f.details_demande||'')}\" data-has-bc=\"${hasBC ? '1' : '0'}\" data-has-br=\"${hasBR ? '1' : '0'}\" data-has-cni=\"${hasCni ? '1' : '0'}\" data-has-signature=\"${hasSignature ? '1' : '0'}\">\n<span class=\"card-badge-analyse absolute top-4 left-4 z-20\" data-num=\"${escapeHtml(f.num_fiche||'')}\" title=\"Analyse budget\" style=\"display:inline-flex;align-items:center;justify-content:center;width:38px;height:38px;border:2px solid #e2e8f0;border-radius:50%;background:#fff;font-size:11px;color:#64748b;font-weight:600;box-shadow:0 2px 4px rgba(0,0,0,.08);\">...</span>\n<span class=\"card-alert-pill hidden absolute top-14 left-4 z-20 bg-white px-2 py-1 rounded-full text-[11px] font-extrabold border border-slate-300 text-slate-700 shadow-sm whitespace-nowrap\" title=\"\"></span>\n<div class=\"absolute top-5 left-16 z-10\">${badge}</div>\n<span class=\"absolute top-4 right-4 ${statusColor} px-3 py-1 rounded-full text-xs font-semibold shadow\" title=\"${escapeHtml(statusLabel)}\">${statusLabel}</span>${refusBadge}\n<div class=\"flex justify-center mb-4 mt-2\">${photo}</div>\n<h2 class=\"text-xl font-semibold text-center mb-1 text-gray-800\">${escapeHtml(f.beficiaire_fiche||'')}</h2>\n<div class=\"flex items-center justify-center mb-1 gap-2\">\n<span class=\"text-gray-500 text-sm\">Fiche N° ${escapeHtml(f.num_fiche||'')}</span>\n${typeof ficheViewLink==='function'? ficheViewLink(f.num_fiche,{classes:'inline-flex items-center justify-center w-7 h-7 rounded-full bg-white border border-indigo-200 text-indigo-600 hover:bg-indigo-50 transition',title:'Voir la fiche'}) : ''}\n</div>\n<p class=\"text-green-600 font-bold text-lg text-center mb-2\">${currencyFormatCFA(f.montant_fiche)}</p>\n<div class=\"text-sm text-gray-700 mb-4 text-center\">\n<p><strong>Ligne déboursé :</strong> ${escapeHtml(ligneDebourseLabel)}</p>\n<p><strong>Ligne devis :</strong> ${escapeHtml(ligneDevisLabel)}</p>\n<p><strong>Précision :</strong> ${escapeHtml(f.precision_fiche||'')}</p>\n<p><strong>Désignation :</strong> ${escapeHtml(f.designation_fiche||'')}</p>\n<p><strong>Tél :</strong> ${escapeHtml(f.tel_beneficiaire_fiche||'')}</p>\n<p><strong>CNI :</strong> <span class=\"${hasCni ? 'text-green-700 font-semibold' : 'text-red-700 font-semibold'}\">${hasCni ? 'OK' : 'Manquante'}</span></p>\n<p><strong>Signature :</strong> <span class=\"${hasSignature ? 'text-green-700 font-semibold' : 'text-red-700 font-semibold'}\">${hasSignature ? 'OK' : 'Manquante'}</span></p>\n${bcLine}${brLine}\n</div>\n<div class=\"flex justify-between items-center mt-auto\">\n<span class=\"text-xs text-gray-400\">${formatDateFR(f.date_creat_fiche)}</span>\n<div class=\"flex flex-wrap gap-2 justify-end\" style=\"max-width:260px\">\n<button data-num=\"${f.num_fiche}\" class=\"js-analyse bg-amber-500 hover:bg-amber-600 text-white px-2 py-1.5 rounded-lg text-[11px] font-semibold shadow\" title=\"Analyse budget\">Aide</button>\n<button data-num=\"${f.num_fiche}\" class=\"js-validate bg-green-600 hover:bg-green-700 text-white px-3 py-2 rounded-lg text-xs font-semibold shadow\">Valider</button>\n<button data-num=\"${f.num_fiche}\" class=\"js-report bg-amber-600 hover:bg-amber-700 text-white px-3 py-2 rounded-lg text-xs font-semibold shadow\">Reporter</button>\n<button data-num=\"${f.num_fiche}\" class=\"js-refuse bg-red-600 hover:bg-red-700 text-white px-3 py-2 rounded-lg text-xs font-semibold shadow\">Refuser</button>\n${editBtn}\n${bcBtn}\n${brBtn}\n</div>\n</div>\n<input type=\"checkbox\" name=\"fiche_select[]\" value=\"${f.num_fiche}\" class=\"absolute top-4 left-1 z-20 w-5 h-5 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500 fiche-checkbox\">\n</div>`;
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
    updateTotalSelectionne();

    function getCurrentFiltersQS() {
        const fd = new FormData(document.getElementById('searchForm'));
        const params = new URLSearchParams(fd);
        [...params.keys()].forEach(k => {
            if (!params.get(k)) params.delete(k);
        });
        return params.toString();
    }

    function getSelectedNums() {
        const data = document.body.dataset.selectedIds || '';
        const arr = data ? data.split(',').filter(Boolean) : [];
        return arr;
    }

    function postSelected(url, selected) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = url;
        form.target = '_blank';
        form.style.display = 'none';
        const inp = document.createElement('input');
        inp.type = 'hidden';
        inp.name = 'selected';
        inp.value = selected.join(',');
        form.appendChild(inp);
        document.body.appendChild(form);
        form.submit();
        setTimeout(() => form.remove(), 1500);
    }

    document.getElementById('exportAllPdf').addEventListener('click', function() {
        const pointId=<?=json_encode((int)($currentPoint['id']??0))?>;if(!pointId)return alert('Arrêtez d’abord le point du jour. Son export provisoire filigrané sera alors disponible.');window.open('request/export_daily_point_pdf.php?id='+pointId,'_blank');
    });
    document.getElementById('selectAllFiches').addEventListener('click',function(){const boxes=[...document.querySelectorAll('.fiche-checkbox')].filter(cb=>cb.offsetParent!==null);const all=boxes.length&&boxes.every(cb=>cb.checked);boxes.forEach(cb=>cb.checked=!all);this.textContent=all?'Tout sélectionner':'Tout désélectionner';updateTotalSelectionne();});
    document.getElementById('reportSelected').addEventListener('click',function(){const nums=getSelectedNums();if(!nums.length)return alert('Sélectionnez au moins une fiche.');document.getElementById('report-num').textContent=nums.join(',');document.getElementById('report-date').value='';reportWrap.classList.remove('hidden');reportWrap.classList.add('flex');});
    function showSignatureLink(data){const url=data.signature_url||'',pointUrl='point_decaissement_jour.php?id='+data.point_id,modal=document.createElement('div');modal.className='fixed inset-0 z-[10000] flex items-center justify-center bg-slate-950/75 p-4';modal.innerHTML=`<section class="w-full max-w-2xl rounded-lg bg-white p-6 shadow-2xl"><p class="text-xs font-black uppercase text-violet-700">Point du jour arrêté</p><h2 class="mt-1 text-2xl font-black text-slate-950">Transmettre la signature au DG</h2><p class="mt-2 text-sm text-slate-600">${data.whatsapp_sent?'Le message WhatsApp a été envoyé. Vous pouvez aussi transmettre ce lien manuellement.':'Copiez ou partagez ce lien sécurisé avec le DG.'}</p>${url?`<input data-link readonly class="mt-5 w-full rounded-md border border-slate-300 bg-slate-50 p-3 text-sm" value="${url.replace(/&/g,'&amp;').replace(/"/g,'&quot;')}"><div class="mt-3 flex flex-wrap gap-2"><button data-copy type="button" class="rounded-md bg-violet-700 px-4 py-3 font-bold text-white">Copier le lien</button><button data-share type="button" class="rounded-md bg-emerald-700 px-4 py-3 font-bold text-white">Partager au DG</button></div>`:'<div class="mt-5 rounded-md bg-amber-50 p-3 text-sm font-bold text-amber-900">Le point est arrêté, mais le lien externe n’a pas pu être créé. Vérifiez le paramétrage du signataire DG.</div>'}<a href="${pointUrl}" class="mt-5 block w-full rounded-md bg-slate-900 px-4 py-3 text-center font-bold text-white">Ouvrir le point arrêté</a></section>`;document.body.appendChild(modal);const input=modal.querySelector('[data-link]'),copy=async()=>{if(!input)return;try{await navigator.clipboard.writeText(input.value)}catch(e){input.select();document.execCommand('copy')}const button=modal.querySelector('[data-copy]');button.textContent='Lien copié';setTimeout(()=>button.textContent='Copier le lien',1800)};modal.querySelector('[data-copy]')?.addEventListener('click',copy);modal.querySelector('[data-share]')?.addEventListener('click',async()=>{if(navigator.share){try{await navigator.share({title:'Point du jour à signer',text:'Veuillez consulter et signer le point du jour BANAMUR.',url:input.value})}catch(e){}}else await copy()});}
    document.querySelector('[data-resend-signature-link]')?.addEventListener('click',async function(){this.disabled=true;try{const body=new URLSearchParams({_csrf:<?=json_encode((string)$_SESSION['daily_point_csrf'])?>,action:'signature_link',point_id:this.dataset.pointId}),response=await fetch('request/daily_point_action.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body}),data=await response.json();if(!response.ok||!data.ok)throw new Error(data.error||'Lien indisponible');showSignatureLink(data);}catch(error){alert(error.message);this.disabled=false;}});
    document.getElementById('stopDailyPoint').addEventListener('click',async function(){const nums=getSelectedNums();if(!nums.length)return alert('Sélectionnez les fiches du point à arrêter.');const plans={};for(const num of nums){const cb=[...document.querySelectorAll('.fiche-checkbox')].find(x=>x.value===num),card=cb?.closest('.card'),amount=Number(card?.querySelector('[data-point-amount]')?.value||cb?.dataset.amount||0),original=Number(cb?.dataset.originalAmount||cb?.dataset.amount||0),next=card?.querySelector('[data-next-date]')?.value||'';if(!(amount>0&&amount<=original))return alert('Montant invalide pour la fiche '+num+'.');if(amount<original&&!next)return alert('Indiquez la prochaine date de décaissement du reliquat pour '+num+'.');plans[num]={amount,next_date:next};}if(!await purchaseUi.confirm({title:'Arrêter le point du jour',message:'Le point contient '+nums.length+' fiche(s). Il sera verrouillé puis transmis pour la signature unique du DG.',tone:'warning',confirmLabel:'Arrêter et transmettre'}))return;this.disabled=true;try{const body=new URLSearchParams({_csrf:<?= json_encode((string)$_SESSION['daily_point_csrf']) ?>,action:'stop',selected:nums.join(','),plans:JSON.stringify(plans)});const response=await fetch('request/daily_point_action.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body});const data=await response.json();if(!response.ok||!data.ok)throw new Error(data.error||'Arrêt impossible');showSignatureLink(data);}catch(error){alert(error.message);this.disabled=false;}});
    document.getElementById('exportAllExcel').addEventListener('click', function() {
        const qs = getCurrentFiltersQS();
        const url = 'request/export_fiches_achat_excel.php' + (qs ? ('?' + qs) : '');
        window.location.href = url;
    });
    document.getElementById('exportSelectedExcel').addEventListener('click', function() {
        const nums = getSelectedNums();
        if (!nums.length) return alert('Sélectionnez au moins une fiche.');
        postSelected('request/export_fiches_achat_excel.php', nums);
    });
</script>
<script>
document.querySelectorAll('[data-advance-toggle]').forEach(input=>input.addEventListener('change',async()=>{try{const body=new URLSearchParams({_csrf:<?=json_encode((string)$_SESSION['daily_point_csrf'])?>,action:'advance',num_fiche:input.dataset.num,is_advance:input.checked?'1':''}),response=await fetch('request/document_compliance_action.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body}),data=await response.json();if(!response.ok||!data.ok)throw new Error(data.error||'Modification impossible');purchaseUi.toast('Nature de la fiche mise à jour.','success');}catch(error){input.checked=!input.checked;alert(error.message);}}));
document.querySelectorAll('[data-invoice-deferred-toggle]').forEach(input=>input.addEventListener('change',async()=>{const checked=input.checked;if(checked&&!await purchaseUi.confirm({title:'Collecter la facture plus tard',message:'Cette fiche pourra intégrer le point du jour sans facture. La facture restera indiquée comme pièce à collecter.',tone:'warning',confirmLabel:'Confirmer le report'})){input.checked=false;return;}input.disabled=true;try{const body=new URLSearchParams({_csrf:<?=json_encode((string)$_SESSION['daily_point_csrf'])?>,action:'invoice_deferred',num_fiche:input.dataset.num,is_deferred:checked?'1':''}),response=await fetch('request/document_compliance_action.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body}),data=await response.json();if(!response.ok||!data.ok)throw new Error(data.error||'Modification impossible');purchaseUi.toast(checked?'Facture marquée à collecter plus tard.':'Report de facture annulé.','success');window.setTimeout(()=>window.location.reload(),450);}catch(error){input.checked=!checked;input.disabled=false;alert(error.message);}}));
document.querySelectorAll('[data-return-docs]').forEach(button=>button.addEventListener('click',async()=>{const reason=await purchaseUi.prompt({title:'Retourner pour complément',message:'Précisez les documents que le demandeur doit ajouter avant une nouvelle validation.',tone:'warning',confirmLabel:'Retourner la fiche',input:{label:'Documents ou corrections attendus',placeholder:'Ex. Ajouter la FNE signée et la preuve de réception…'}});if(!reason)return;try{const body=new URLSearchParams({_csrf:<?=json_encode((string)$_SESSION['daily_point_csrf'])?>,action:'return',num_fiche:button.dataset.num,reason}),response=await fetch('request/document_compliance_action.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body}),data=await response.json();if(!response.ok||!data.ok)throw new Error(data.error||'Retour impossible');button.closest('.card')?.remove();purchaseUi.toast('Fiche retournée pour complément documentaire.','warning');}catch(error){alert(error.message);}}));
</script>
<script>document.querySelectorAll('.card').forEach(card=>{const trigger=card.querySelector('[data-partial-trigger]'),panel=card.querySelector('[data-partial-panel]'),amount=card.querySelector('[data-point-amount]'),date=card.querySelector('[data-next-date]'),summary=card.querySelector('[data-partial-summary]'),original=Number(amount?.max||amount?.value||0),format=value=>new Intl.NumberFormat('fr-FR').format(value)+' FCFA';if(!trigger||!panel||!amount)return;const close=()=>panel.classList.add('hidden'),refresh=()=>{const value=Number(amount.value||0),partial=value>0&&value<original;if(summary){summary.classList.toggle('hidden',!partial);summary.textContent=partial?'Partiel : '+format(value)+(date?.value?' · reliquat le '+new Date(date.value+'T00:00:00').toLocaleDateString('fr-FR'):' · date du reliquat à préciser'):'';}trigger.querySelector('small').textContent=partial?'Décaissement partiel programmé':'Cliquer pour paiement partiel';};trigger.addEventListener('click',event=>{event.stopPropagation();panel.classList.toggle('hidden');if(!panel.classList.contains('hidden'))setTimeout(()=>amount.focus(),0);});panel.addEventListener('click',event=>event.stopPropagation());panel.addEventListener('focusout',()=>setTimeout(()=>{if(!panel.contains(document.activeElement))close();},140));amount.addEventListener('input',refresh);date?.addEventListener('change',refresh);document.addEventListener('click',close);refresh();});</script>
<script>
let treasuryAlertKey='';
async function openTreasuryInsufficiency(total, balance) {
    const key = (document.body.dataset.selectedIds || '') + '|' + total;
    if (key === treasuryAlertKey) return;
    treasuryAlertKey = key;

    let modal = document.getElementById('treasuryInsufficientModal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'treasuryInsufficientModal';
        modal.className = 'fixed inset-0 z-[11000] hidden items-center justify-center bg-slate-950/75 p-4 backdrop-blur-sm';
        modal.innerHTML = `<section class="max-h-[92vh] w-full max-w-2xl overflow-auto rounded-[30px] bg-white shadow-2xl">
            <header class="bg-gradient-to-br from-slate-950 via-indigo-950 to-sky-900 p-6 text-white">
                <div class="flex items-start justify-between gap-4">
                    <div class="flex items-center gap-4">
                        <span class="grid h-12 w-12 place-items-center rounded-2xl bg-rose-100 text-rose-700"><i class="fas fa-wallet"></i></span>
                        <div><p class="text-[10px] font-black uppercase tracking-[.22em] text-sky-200">Couverture du décaissement</p><h2 class="text-2xl font-black">Disponible du jour insuffisant</h2></div>
                    </div>
                    <button type="button" data-close class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-white/10 text-xl hover:bg-white/20">×</button>
                </div>
            </header>
            <div class="p-6">
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <div class="rounded-2xl bg-emerald-50 p-3"><small class="text-emerald-700">Entrées réelles</small><b data-real-entries class="block text-emerald-900"></b></div>
                    <div class="rounded-2xl bg-indigo-50 p-3"><small class="text-indigo-700">Mobilisable</small><b data-balance class="block text-indigo-900"></b></div>
                    <div class="rounded-2xl bg-rose-50 p-3"><small class="text-rose-700">À couvrir</small><b data-selection class="block text-rose-900"></b></div>
                    <div class="rounded-2xl bg-amber-50 p-3"><small class="text-amber-700">Transfert requis</small><b data-deficit class="block text-amber-900"></b></div>
                </div>
                <section class="mt-5 rounded-2xl border border-indigo-100 bg-indigo-50/70 p-5">
                    <div class="flex items-start gap-3"><i class="fas fa-right-left mt-1 text-indigo-700"></i><div><h3 class="font-black text-indigo-950">Transfert compte à compte</h3><p class="mt-1 text-sm leading-6 text-indigo-800">Débiter les entrées réelles et créditer le disponible réel du jour, sans créer une nouvelle entrée et sans double comptage.</p></div></div>
                    <div class="mt-4 grid gap-3 sm:grid-cols-2">
                        <label class="text-xs font-black uppercase tracking-wide text-slate-500">Montant à transférer<input data-transfer type="number" min="1" class="mt-2 w-full rounded-xl border border-indigo-200 bg-white p-3 text-base font-black"></label>
                        <label class="text-xs font-black uppercase tracking-wide text-slate-500">Référence<input data-reference placeholder="Ex. VIR-DISP-ACHAT" class="mt-2 w-full rounded-xl border border-indigo-200 bg-white p-3"></label>
                        <label class="text-xs font-black uppercase tracking-wide text-slate-500 sm:col-span-2">Motif<textarea data-note rows="2" class="mt-2 w-full rounded-xl border border-indigo-200 bg-white p-3">Couverture d’une validation achat</textarea></label>
                    </div>
                    <p data-transfer-warning class="mt-3 hidden rounded-xl bg-rose-100 p-3 text-sm font-bold text-rose-800"></p>
                    <button type="button" data-transfer-submit class="mt-4 w-full rounded-xl bg-indigo-700 px-4 py-3 font-black text-white shadow-lg hover:bg-indigo-800"><i class="fas fa-right-left mr-2"></i>Confirmer le transfert vers le disponible</button>
                </section>
                <section class="mt-4 rounded-2xl bg-amber-50 p-4">
                    <h3 class="font-black text-amber-950">Sinon, reporter le traitement</h3>
                    <p data-suggestion class="mt-1 text-sm text-amber-800">Recherche d’une date raisonnable…</p>
                    <button type="button" data-report class="mt-3 rounded-xl bg-amber-500 px-4 py-3 font-black text-slate-950">Ouvrir le report</button>
                </section>
            </div>
        </section>`;
        document.body.appendChild(modal);
        modal.querySelector('[data-close]').onclick = () => {
            treasuryAlertKey = '';
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        };
        modal.querySelector('[data-report]').onclick = () => {
            treasuryAlertKey = '';
            modal.classList.add('hidden');
            modal.classList.remove('flex');
            document.getElementById('reportSelected')?.click();
        };
    }

    const format = value => new Intl.NumberFormat('fr-FR').format(value) + ' FCFA';
    const deficit = Math.max(0, total - balance);
    const realEntries = Number(window.purchaseRealEntriesBalance || 0);
    const warning = modal.querySelector('[data-transfer-warning]');
    const submit = modal.querySelector('[data-transfer-submit]');

    modal.querySelector('[data-real-entries]').textContent = format(realEntries);
    modal.querySelector('[data-balance]').textContent = format(balance);
    modal.querySelector('[data-selection]').textContent = format(total);
    modal.querySelector('[data-deficit]').textContent = format(deficit);
    modal.querySelector('[data-transfer]').value = deficit;
    modal.querySelector('[data-transfer]').max = realEntries;

    const insufficientSource = deficit > realEntries;
    warning.classList.toggle('hidden', !insufficientSource);
    warning.textContent = insufficientSource
        ? 'Les entrées réelles disponibles ne couvrent pas ce transfert. Enregistrez d’abord l’encaissement dans la trésorerie ou reportez la fiche.'
        : '';
    submit.disabled = insufficientSource;
    submit.classList.toggle('opacity-40', insufficientSource);
    submit.classList.toggle('cursor-not-allowed', insufficientSource);

    modal.classList.remove('hidden');
    modal.classList.add('flex');

    submit.onclick = async () => {
        try {
            submit.disabled = true;
            const body = new URLSearchParams({
                _csrf: <?= json_encode((string) $_SESSION['daily_point_csrf']) ?>,
                action: 'transfer_available',
                amount: String(total),
                transfer_amount: modal.querySelector('[data-transfer]').value,
                reference: modal.querySelector('[data-reference]').value,
                note: modal.querySelector('[data-note]').value
            });
            const response = await fetch('request/treasury_selection_action.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body
            });
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error(data.error || 'Transfert impossible');
            purchaseUi.toast('Transfert effectué : le disponible réel du jour a été crédité.', 'success');
            setTimeout(() => location.reload(), 900);
        } catch (error) {
            submit.disabled = false;
            await purchaseUi.dialog({title:'Transfert bloqué', message:error.message, tone:'danger', confirmLabel:'Fermer', cancel:false});
        }
    };

    try {
        const body = new URLSearchParams({
            _csrf: <?= json_encode((string) $_SESSION['daily_point_csrf']) ?>,
            action: 'suggest',
            amount: String(total)
        });
        const response = await fetch('request/treasury_selection_action.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body
        });
        const data = await response.json();
        modal.querySelector('[data-suggestion]').textContent = data.suggested_date
            ? 'Date raisonnable suggérée : ' + new Date(data.suggested_date + 'T00:00:00').toLocaleDateString('fr-FR')
            : 'Aucune date suffisamment financée trouvée sur les 12 prochains mois.';
    } catch (error) {
        modal.querySelector('[data-suggestion]').textContent = 'Suggestion momentanément indisponible.';
    }
}
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
