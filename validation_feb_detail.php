<?php
require_once __DIR__ . '/auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/model/Database.php';
require_once __DIR__ . '/model/EmployeeLegalRequestManager.php';
require_once __DIR__ . '/model/FebValidationPolicy.php';
require_once __DIR__ . '/services/FebPriceChallengeService.php';
require_once __DIR__ . '/services/UrgentDisbursementService.php';
require_once __DIR__ . '/services/FebCategoryMigrationService.php';
require_once __DIR__ . '/services/FinancialPlanningSeparationService.php';

$pdo = \Database::getConnection();
$_SESSION['feb_workflow_csrf'] ??= bin2hex(random_bytes(32));
$manager = new EmployeeLegalRequestManager($pdo);
$urgentDisbursementService = new UrgentDisbursementService($pdo);
$urgentBlockedChantierMap = $urgentDisbursementService->blockedChantierMap();
$_SESSION['feb_challenge_review_csrf'] ??= bin2hex(random_bytes(24));
$challengeReviewCsrf = (string) $_SESSION['feb_challenge_review_csrf'];

$detailFlash = '';
$detailFlashType = 'info';
if (!empty($_SESSION['flash_validation_demandes_finances']) && is_array($_SESSION['flash_validation_demandes_finances'])) {
    $detailFlash = trim((string) ($_SESSION['flash_validation_demandes_finances']['message'] ?? ''));
    $detailFlashType = (string) ($_SESSION['flash_validation_demandes_finances']['type'] ?? 'info');
    if (!in_array($detailFlashType, ['success', 'error', 'info'], true)) {
        $detailFlashType = 'info';
    }
    unset($_SESSION['flash_validation_demandes_finances']);
}

$actorUserId = (int) ($_SESSION['user_id'] ?? 0);
$servicePerm = $actorUserId > 0
    ? FebValidationPolicy::getUserServicePermissions($pdo, $actorUserId)
    : ['all' => false, 'ids' => [], 'configured' => false, 'raw' => ''];

function febDetailStatusBadge(string $status): array
{
    $status = strtolower(trim($status));
    $map = [
        'submitted' => ['label' => 'En attente', 'class' => 'bg-amber-100 text-amber-800 border-amber-200'],
        'details_requested' => ['label' => 'Compléments demandés', 'class' => 'bg-sky-100 text-sky-800 border-sky-200'],
        'approved' => ['label' => 'Approuvée', 'class' => 'bg-emerald-100 text-emerald-800 border-emerald-200'],
        'rejected' => ['label' => 'Refusée', 'class' => 'bg-rose-100 text-rose-800 border-rose-200'],
        'cancelled' => ['label' => 'Annulée', 'class' => 'bg-slate-100 text-slate-700 border-slate-200'],
    ];

    return $map[$status] ?? ['label' => $status !== '' ? $status : '-', 'class' => 'bg-slate-100 text-slate-700 border-slate-200'];
}

function febDetailMoneyInput($value): string
{
    $raw = str_replace([" ", "\xc2\xa0"], '', (string) $value);
    $raw = str_replace(',', '.', $raw);
    return number_format((float) $raw, 2, '.', '');
}

function febDetailSafeUploadUrl(?string $url): string
{
    $url = trim((string) $url);

    if ($url === '') {
        return '';
    }

    // URL absolue déjà valide
    if (preg_match('#^https?://#i', $url)) {
        return $url;
    }

    // Normaliser le chemin
    $url = ltrim($url, '/');

    // Sécurité
    if (strpos($url, 'uploads/') !== 0) {
        return '';
    }

    // URL relative propre
    return './' . $url;
}

function febDetailResolveMediaUrl(?string $path): string
{
    $path = trim((string) $path);
    if ($path === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }

    $root = __DIR__;
    $normalized = ltrim(str_replace('\\', '/', $path), '/');
    $candidates = [
        $normalized,
        'photo/' . $normalized,
        'uploads/' . $normalized,
        'uploads/rh/' . $normalized,
        'uploads/photo/' . $normalized,
        'uploads/rh/photo/' . $normalized,
        'uploads/rh/piece-identite/' . $normalized,
        'uploads/cni/' . $normalized,
        'storage/' . $normalized,
    ];

    foreach ($candidates as $candidate) {
        $absolute = $root . '/' . ltrim($candidate, '/');
        if (is_file($absolute)) {
            return './' . ltrim(str_replace('\\', '/', $candidate), '/');
        }
    }

    return '';
}


function febDetailFileExtension(?string $nameOrUrl): string
{
    $path = parse_url((string) $nameOrUrl, PHP_URL_PATH) ?: (string) $nameOrUrl;
    return strtolower(pathinfo($path, PATHINFO_EXTENSION));
}

function febDetailRenderDocumentPreview(string $fileUrl, string $fileName = ''): void
{
    $fileUrl = trim($fileUrl);
    if ($fileUrl === '') {
        return;
    }

    $extension = febDetailFileExtension($fileName !== '' ? $fileName : $fileUrl);
    $safeUrl = htmlspecialchars($fileUrl, ENT_QUOTES, 'UTF-8');
    $safeName = htmlspecialchars($fileName !== '' ? $fileName : 'Document joint', ENT_QUOTES, 'UTF-8');

    if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'], true)) {
        echo '<div class="feb-preview feb-preview--image mt-4">';
        echo '  <img src="' . $safeUrl . '" alt="' . $safeName . '" loading="lazy" class="feb-preview-img">';
        echo '</div>';
        return;
    }

    if ($extension === 'pdf') {
        echo '<div class="feb-preview feb-preview--pdf mt-4">';
        echo '  <iframe src="' . $safeUrl . '#toolbar=1&navpanes=0" title="' . $safeName . '" loading="lazy" class="feb-preview-frame"></iframe>';
        echo '</div>';
        return;
    }

    echo '<div class="mt-4 rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-3 text-xs text-slate-500">';
    echo 'Prévisualisation non disponible pour ce type de fichier. Utilisez le bouton Ouvrir.';
    echo '</div>';
}

function febDetailFormatFileSize($bytes): string
{
    $bytes = (int) $bytes;
    if ($bytes <= 0) {
        return '-';
    }

    if ($bytes < 1024 * 1024) {
        return number_format($bytes / 1024, 0, ',', ' ') . ' Ko';
    }

    return number_format($bytes / (1024 * 1024), 2, ',', ' ') . ' Mo';
}


function febDetailFormatDate(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '-';
    }

    try {
        return (new DateTimeImmutable($value))->format('d/m/Y');
    } catch (Throwable $e) {
        return $value;
    }
}

function febDetailEventLabel(string $type): string
{
    $map = [
        'submitted' => 'Soumise',
        'approved' => 'Approuvée',
        'rejected' => 'Refusée',
        'cancelled' => 'Annulée',
        'feb_more_details_requested' => 'Compléments demandés',
        'feb_validation_reported' => 'Retour pour complément',
        'feb_updated' => 'Mise à jour',
        'feb_updated_by_validator' => 'Modifiée par la Direction / Comptabilité',
        'feb_disbursement_integrated' => 'Déboursé intégré à la FEB',
        'feb_linked_decaissement_created' => 'Fiche de décaissement générée',
        'finance_adjustment' => 'Ajustement Finance',
    ];

    $type = trim($type);
    return $map[$type] ?? ucfirst(str_replace('_', ' ', $type));
}

$tab = strtolower(trim((string) ($_GET['tab'] ?? 'mine_pending')));
$allowedTabs = ['mine_pending', 'pending_all', 'approved', 'rejected', 'cancelled', 'all'];
if (!in_array($tab, $allowedTabs, true)) {
    $tab = 'mine_pending';
}

$includeLegacyFeb = !empty($_GET['archives']) || !empty($_GET['legacy']) || !empty($_GET['include_archives']);
$requestId = isset($_GET['id']) && is_numeric($_GET['id']) ? (int) $_GET['id'] : 0;

$listParams = ['tab' => $tab];
if ($includeLegacyFeb) {
    $listParams['archives'] = 1;
}
$listUrl = 'validation_demandes_finances.php?' . http_build_query($listParams) . '#list';

$view = $requestId > 0 ? $manager->getRequestById($requestId) : null;
if (
    !$view
    || ($view['assigned_team'] ?? '') !== 'finance'
    || ($view['request_type'] ?? '') !== 'feb'
) {
    $_SESSION['flash_validation_demandes_finances'] = [
        'message' => 'FEB introuvable ou non accessible.',
        'type' => 'error',
    ];
    header('Location: ' . $listUrl);
    exit;
}

$viewServiceId = FebValidationPolicy::requestServiceId($view);
$viewCanValidate = FebValidationPolicy::canValidateService($servicePerm, $viewServiceId);
$viewIsLegacyFeb = (string) ($view['meta_legacy_source'] ?? '') === 'feb_legacy';
if (!$viewCanValidate || ($viewIsLegacyFeb && !$includeLegacyFeb)) {
    $_SESSION['flash_validation_demandes_finances'] = [
        'message' => 'Vous n’êtes pas autorisé à consulter cette FEB.',
        'type' => 'error',
    ];
    header('Location: ' . $listUrl);
    exit;
}

$payload = (array) ($view['payload'] ?? []);
if (!empty($payload['rh_financial_point_id'])) {
    require_once __DIR__.'/services/RhFinancialPointService.php';
    try {
        (new RhFinancialPointService($pdo))->assertSignedFeb($view);
        header('Location: validation_demandes_finances.php?'.http_build_query(['id'=>$requestId,'tab'=>$tab,'screen'=>'detail']));
        exit;
    } catch(Throwable $e) {http_response_code(403);exit('Le point RH signé ne correspond plus à cette FEB.');}
}
$febDisplay = (array) ($view['feb_display'] ?? EmployeeLegalRequestManager::extractFebDisplay($view));
$viewUrgentRegularizationId = (int)($payload['urgent_decaissement_id'] ?? 0);
$viewRequesterBlockedUrgencies = $urgentDisbursementService->listBlockedForUser((int)($view['requester_user_id'] ?? 0));
$viewHasBlockingUrgency = $viewRequesterBlockedUrgencies !== [] && $viewUrgentRegularizationId <= 0;
$events = $manager->listEventsForRequest($requestId, 80);
$badge = febDetailStatusBadge((string) ($view['status'] ?? ''));
$detailInvoice = is_array($payload['invoice_document'] ?? null) ? (array) $payload['invoice_document'] : [];
$detailInvoiceUrl = febDetailSafeUploadUrl((string) ($detailInvoice['url'] ?? ''));
$detailPvDocument = is_array($payload['pv_reception_document'] ?? null) ? (array) $payload['pv_reception_document'] : [];
$detailPvUrl = febDetailSafeUploadUrl((string) ($detailPvDocument['url'] ?? ''));
$febNeedScope = strtolower(trim((string) ($payload['feb_need_scope'] ?? 'external')));
if (!in_array($febNeedScope, ['internal', 'external'], true)) {
    $febNeedScope = 'external';
}
$febNeedScopeLabel = $febNeedScope === 'internal' ? 'Besoin interne' : 'Demande externe';
$cashflowNeed = strtolower(trim((string) ($payload['cashflow_need'] ?? '')));
$cashflowNeedLabel = match ($cashflowNeed) {
    'provisional_disbursement' => 'Décaissement provisoire avec justificatifs image',
    'internal_purchase_order' => 'Décaissement complet et unique sur facture',
    default => $febNeedScope === 'internal' ? 'Sans décaissement fournisseur direct' : 'Mode de décaissement non précisé',
};
$hasPvDocument = $detailPvDocument && $detailPvUrl !== '';
$hasInvoiceDocument = $detailInvoice && $detailInvoiceUrl !== '';
$detailJustifs = array_values(array_filter((array) ($payload['justificatifs'] ?? []), static fn($item): bool => is_array($item)));
$budgetPlanned = (string) ($payload['budget_planned'] ?? '');
$budgetPlannedLabel = (string) ($payload['budget_planned_label'] ?? ($budgetPlanned === 'yes' ? 'Prévue au déboursé' : 'Non prévue au déboursé'));
$proposedDebourse = is_array($payload['proposed_debourse'] ?? null) ? (array) $payload['proposed_debourse'] : [];
$hasJustificatifs = count($detailJustifs) > 0;
$besoins = array_values(array_filter((array) ($payload['besoins'] ?? []), static fn($item): bool => is_array($item)));
$priceChallenges = (new FebPriceChallengeService($pdo))->reviewForFeb((int) ($view['id'] ?? 0));
$submittedPriceChallenges = array_values(array_filter($priceChallenges, static fn(array $item): bool => in_array((string)($item['status'] ?? ''), ['submitted','retained','dismissed'], true) && (float)($item['proposed_total_amount'] ?? 0) > 0));
$noProposalChallenges = array_values(array_filter($priceChallenges, static fn(array $item): bool => (string)($item['status'] ?? '') === 'submitted' && (string)($item['response_type'] ?? 'proposal') === 'none'));
$submittedChallengesByLine = [];
foreach ($submittedPriceChallenges as $submittedChallenge) {
    $submittedChallengesByLine[(int)$submittedChallenge['line_index']][] = $submittedChallenge;
}
$detailBci = trim((string) ($febDisplay['numero_bon_commande_interne'] ?? $febDisplay['code_autorisation_feb'] ?? ''));
// Seules les FEB encore soumises ou retournées pour complément peuvent être
// traitées. Afficher le formulaire pour une FEB déjà approuvée/refusée donne
// l'impression qu'elle est validable alors que le contrôleur la refuse.
$isPending = in_array((string) ($view['status'] ?? ''), ['submitted', 'details_requested'], true);
$provisionalDecaissement = is_array($payload['provisional_decaissement'] ?? null) ? (array) $payload['provisional_decaissement'] : [];
$hasProvisionalDecaissement = !empty($provisionalDecaissement['active']);
$firstNeed = is_array($besoins[0] ?? null) ? (array) $besoins[0] : [];
$firstNeedSupplier = is_array($firstNeed['fournisseur'] ?? null) ? (array) $firstNeed['fournisseur'] : [];
$payloadBeneficiaryChoice = strtolower(trim((string) ($payload['beneficiary_choice'] ?? '')));
$draftBeneficiaryMode = (string) ($provisionalDecaissement['beneficiary_mode'] ?? ($payloadBeneficiaryChoice === 'supplier' ? 'supplier' : 'personnel'));
if (!in_array($draftBeneficiaryMode, ['personnel', 'supplier', 'external'], true)) {
    $draftBeneficiaryMode = 'personnel';
}
$draftPersonnelId = (string) ($provisionalDecaissement['personnel_id'] ?? $payload['personnel_id'] ?? '');
$draftFournisseurId = (string) (
    $provisionalDecaissement['fournisseur_id']
    ?? $payload['global_fournisseur_id']
    ?? $payload['fournisseur_id']
    ?? $firstNeedSupplier['id']
    ?? $firstNeedSupplier['id_fournisseur']
    ?? ''
);
$draftChantierId = (string) ($provisionalDecaissement['chantier_id'] ?? $payload['chantier_id'] ?? '');
$draftLigneDevisId = (string) ($provisionalDecaissement['ligne_devis_id'] ?? '');
$draftDebourseId = (string) ($provisionalDecaissement['debourse_id'] ?? '');
$draftLigneId = (string) ($provisionalDecaissement['ligne_debourse_id'] ?? '');
$draftMontantFiche = (string) ($provisionalDecaissement['montant_fiche'] ?? ($payload['provisional_disbursement_amount'] ?? $payload['montant'] ?? ($febDisplay['montant'] ?? 0)));
$draftModePaiement = (string) ($provisionalDecaissement['mode_paiement'] ?? '');
$draftDetailsDemande = (string) ($provisionalDecaissement['details_demande'] ?? ($payload['description'] ?? ''));

$febPersonnelOptions = [];
$febChantierOptions = [];
$febFinancialServiceOptions = [];
$febDebourseOptions = [];
$febLigneOptions = [];
$febFournisseurOptions = [];
$febTargetDevisOptions = [];
$febTargetLigneDevisOptions = [];

try {
    $officialDebourseSql = FinancialPlanningSeparationService::officialDeboursePredicate('db');
    $stmt = $pdo->query('
        SELECT
            p.id_personnel_soignant AS id,
            p.nom_personnel_soignant AS nom,
            p.matricule_personnel_soignant AS matricule,
            p.tel_personnel_soignant AS telephone,
            p.photo_personnel_soignant AS photo,
            p.piece_identite_path AS cni,
            COALESCE(NULLIF(TRIM(s.lib_service), \'\'), \'\') AS service,
            COALESCE(NULLIF(TRIM(f.lib_fonction), \'\'), \'\') AS fonction
        FROM personnel p
        LEFT JOIN service s ON s.id_service = p.service_id
        LEFT JOIN fonction f ON f.id_fonction = p.fonction_id
        WHERE (p.date_sortie IS NULL OR p.date_sortie = \'\' OR p.date_sortie = \'0000-00-00\')
        ORDER BY p.nom_personnel_soignant
        LIMIT 2500
    ');
    $febPersonnelOptions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $febPersonnelOptions = [];
}

try {
    $stmt = $pdo->query('
        SELECT id, code, titre, devis_id
        FROM chantier_banamur
        WHERE archived_at IS NULL
        ORDER BY code, titre
        LIMIT 2500
    ');
    $febChantierOptions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $febChantierOptions = [];
}

if ($budgetPlanned === 'no') {
    try {
        $stmt = $pdo->query('SELECT ch.id AS chantier_id, d.id, d.numero_devis, COALESCE(c.devis_type,"UNCLASSIFIED") AS devis_type FROM chantier_banamur ch INNER JOIN devis_banamur base ON base.id=ch.devis_id INNER JOIN devis_banamur d ON d.id=base.id OR (base.offre_id IS NOT NULL AND d.offre_id=base.offre_id) LEFT JOIN devis_offer_classifications c ON c.devis_id=d.id WHERE ch.archived_at IS NULL ORDER BY ch.id, FIELD(COALESCE(c.devis_type,"UNCLASSIFIED"),"PRINCIPAL","TS","UNCLASSIFIED"),d.id');
        $febTargetDevisOptions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $targetIds = array_values(array_unique(array_filter(array_map(static fn(array $row): int => (int)($row['id']??0), $febTargetDevisOptions))));
        if ($targetIds) {
            $lineStmt = $pdo->query('SELECT id, devis_id, designation, total FROM ligne_devis_banamur WHERE devis_id IN (' . implode(',', $targetIds) . ') ORDER BY devis_id, designation');
            $febTargetLigneDevisOptions = $lineStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    } catch (Throwable $e) {
        $febTargetDevisOptions = [];
        $febTargetLigneDevisOptions = [];
    }
}

try {
    $stmt=$pdo->query('SELECT DISTINCT ld.id,ld.devis_id,ld.designation,ld.total
        FROM ligne_devis_banamur ld
        INNER JOIN chantier_banamur ch ON ch.devis_id=ld.devis_id AND ch.archived_at IS NULL
        ORDER BY ld.devis_id,ld.designation,ld.id
        LIMIT 10000');
    $febFinancialServiceOptions=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
} catch(Throwable $e) {
    $febFinancialServiceOptions=[];
}

try {
    $stmt = $pdo->query('
        SELECT db.id,db.devis_id,db.ligne_devis_id,db.montant_debourse,
            COALESCE(NULLIF(TRIM(ld.designation),""),CONCAT("Déboursé #",db.id)) AS label,
            COUNT(ldb.id) AS sous_lignes,
            CASE WHEN db.date_debut IS NOT NULL AND db.date_fin IS NOT NULL THEN 1 ELSE 0 END AS has_dates,
            CASE WHEN db.montant_debourse>0 THEN 1 ELSE 0 END AS has_amount
        FROM debourse_banamur db
        LEFT JOIN ligne_devis_banamur ld ON ld.id = db.ligne_devis_id
        LEFT JOIN ligne_debourse_banamur ldb ON ldb.debourse_id=db.id
        INNER JOIN chantier_banamur ch ON ch.devis_id=db.devis_id AND ch.archived_at IS NULL
        WHERE ' . $officialDebourseSql . '
        GROUP BY db.id,db.devis_id,db.ligne_devis_id,db.montant_debourse,db.motif_modif,ld.designation,db.date_debut,db.date_fin
        ORDER BY db.devis_id,db.ligne_devis_id,sous_lignes DESC,has_dates DESC,has_amount DESC,db.id DESC
        LIMIT 5000
    ');
    $allDebourseOptions=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
    $canonical=[];
    foreach($allDebourseOptions as $debourseOption){
        $key=(int)($debourseOption['devis_id']??0).':'.(int)($debourseOption['ligne_devis_id']??0);
        if(!isset($canonical[$key]))$canonical[$key]=$debourseOption;
    }
    $febDebourseOptions=array_values($canonical);
} catch (Throwable $e) {
    $febDebourseOptions = [];
}

try {
    $canonicalIds=array_values(array_filter(array_map(static fn(array $row):int=>(int)($row['id']??0),$febDebourseOptions)));
    if($canonicalIds){
        $marks=implode(',',array_fill(0,count($canonicalIds),'?'));
        $stmt=$pdo->prepare("SELECT ldb.id,ldb.debourse_id,db.devis_id,db.ligne_devis_id,
            ldb.designation,ldb.categorie,ldb.montant,
            COALESCE(NULLIF(TRIM(ld.designation),''),CONCAT('Déboursé #',db.id)) debourse_label
            FROM ligne_debourse_banamur ldb
            INNER JOIN debourse_banamur db ON db.id=ldb.debourse_id
            LEFT JOIN ligne_devis_banamur ld ON ld.id=db.ligne_devis_id
            WHERE ldb.debourse_id IN ($marks)
            ORDER BY db.devis_id,db.ligne_devis_id,ldb.categorie,ldb.designation,ldb.id");
        $stmt->execute($canonicalIds);
        $febLigneOptions=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
    }else $febLigneOptions=[];
} catch (Throwable $e) {
    $febLigneOptions = [];
}

if($draftLigneDevisId===''&&$draftDebourseId!==''){
    foreach($febDebourseOptions as $debourseOption){
        if((int)($debourseOption['id']??0)===(int)$draftDebourseId){
            $draftLigneDevisId=(string)(int)($debourseOption['ligne_devis_id']??0);
            break;
        }
    }
}

try {
    $stmt = $pdo->query('
        SELECT
            id_fournisseur AS id,
            COALESCE(NULLIF(TRIM(nom_commercial), ""), NULLIF(TRIM(raison_sociale), ""), NULLIF(TRIM(CONCAT(person_first_name, " ", person_last_name)), ""), CONCAT("Fournisseur #", id_fournisseur)) AS nom,
            contact_nom,
            telephone,
            email,
            supplier_type
        FROM fournisseur_banamur
        WHERE actif = 1 AND validation_status = "approved"
        ORDER BY nom
        LIMIT 2500
    ');
    $febFournisseurOptions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $febFournisseurOptions = [];
}

$analysisDebourseTitle = trim((string) ($proposedDebourse['title'] ?? ''));
if ($budgetPlanned === 'yes') {
    $plannedDebourseId = (int) ($provisionalDecaissement['debourse_id'] ?? 0);
    foreach ($febDebourseOptions as $debourseOption) {
        if ((int) ($debourseOption['id'] ?? 0) === $plannedDebourseId) {
            $analysisDebourseTitle = trim((string) ($debourseOption['label'] ?? ''));
            break;
        }
    }
}
if ($analysisDebourseTitle === '') {
    $analysisDebourseTitle = trim((string) ($payload['feb_quote_title'] ?? $payload['description'] ?? 'Dépense à analyser'));
}

$febBudgetContext = ['chantier' => (string) ($febDisplay['chantier_label'] ?? 'Chantier non renseigné'), 'devis' => 'Devis non identifié', 'devis_type' => 'Non classé', 'debourse' => $analysisDebourseTitle, 'debourse_status' => $budgetPlanned === 'yes' ? 'Existant' : 'À intégrer'];
try {
    $contextStmt = $pdo->prepare('
        SELECT c.code chantier_code, c.titre chantier_titre, d.id devis_id, d.numero_devis,
               COALESCE(doc.devis_type, "NON_CLASSE") devis_type,
               db.id debourse_id, COALESCE(NULLIF(TRIM(ld.designation), ""), CONCAT("Déboursé #", db.id)) debourse_label
        FROM chantier_banamur c
        LEFT JOIN devis_banamur d ON d.id=c.devis_id
        LEFT JOIN devis_offer_classifications doc ON doc.devis_id=d.id
        LEFT JOIN debourse_banamur db ON db.id=:debourse_id
        LEFT JOIN ligne_devis_banamur ld ON ld.id=db.ligne_devis_id
        WHERE c.id=:chantier_id AND c.archived_at IS NULL LIMIT 1
    ');
    $contextStmt->execute(['chantier_id' => (int) ($payload['chantier_id'] ?? 0), 'debourse_id' => (int) ($provisionalDecaissement['debourse_id'] ?? 0)]);
    if ($contextRow = $contextStmt->fetch(PDO::FETCH_ASSOC)) {
        $febBudgetContext['chantier'] = trim((string) (($contextRow['chantier_code'] ?? '') . ' — ' . ($contextRow['chantier_titre'] ?? '')), ' —') ?: $febBudgetContext['chantier'];
        $febBudgetContext['devis'] = trim((string) ($contextRow['numero_devis'] ?? '')) ?: ('Devis #' . (int) ($contextRow['devis_id'] ?? 0));
        $type = strtoupper((string) ($contextRow['devis_type'] ?? 'NON_CLASSE'));
        $febBudgetContext['devis_type'] = $type === 'PRINCIPAL' ? 'Devis principal' : ($type === 'TS' ? 'Travaux supplémentaires (TS)' : 'Devis non classé');
        if ($budgetPlanned === 'yes' && !empty($contextRow['debourse_label'])) $febBudgetContext['debourse'] = (string) $contextRow['debourse_label'];
    }
} catch (Throwable $e) {
    // Les anciens devis non classés restent affichables avec les données de la FEB.
}

$pageTitle = 'Traitement FEB';
$activeMenu = 'pointfinancier';
require_once __DIR__ . '/decaissement/includes/header.php';
require_once __DIR__ . '/decaissement/includes/navbar.php';
?>
<style>
    .feb-shell {
        background:
            radial-gradient(circle at top left, rgba(224, 231, 255, 0.82), transparent 30%),
            radial-gradient(circle at top right, rgba(220, 252, 231, 0.68), transparent 28%),
            linear-gradient(180deg, #f8fafc 0%, #eef2ff 100%);
    }

    .feb-page-container {
        width: 100%;
        max-width: 1600px;
        min-width: 0;
        margin-inline: auto;
        overflow-x: hidden;
        overflow-x: clip;
    }

    .feb-analysis-grid {
        display: grid;
        grid-template-columns: minmax(0, 1.2fr) minmax(0, .8fr);
        gap: 24px;
        width: 100%;
        min-width: 0;
    }

    .feb-analysis-grid > *,
    .feb-analysis-column,
    .feb-panel > *,
    .feb-mini-card,
    .feb-section,
    .feb-decision-box {
        min-width: 0;
        max-width: 100%;
    }

    .feb-scenario-hero {
        position: relative;
        overflow: hidden;
        border-radius: 22px;
        padding: 20px;
    }

    .feb-scenario-hero--planned {
        border: 1px solid rgba(16, 185, 129, .3);
        background: linear-gradient(135deg, #ecfdf5 0%, #ffffff 55%, #eff6ff 100%);
    }

    .feb-scenario-hero--unplanned {
        border: 1px solid rgba(245, 158, 11, .38);
        background: linear-gradient(135deg, #fff7ed 0%, #ffffff 55%, #fffbeb 100%);
    }

    .feb-scenario-title {
        margin-top: 8px;
        overflow-wrap: anywhere;
        font-size: clamp(20px, 2vw, 30px);
        line-height: 1.15;
        font-weight: 900;
        color: #0f172a;
    }

    .feb-context-card {
        border: 1px solid rgba(226, 232, 240, .92);
        border-radius: 18px;
        background: rgba(255,255,255,.88);
        padding: 14px 16px;
    }

    .feb-proof-preview {
        display: block;
        width: 100%;
        height: 190px;
        margin-top: 10px;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        background: #fff;
        object-fit: contain;
    }

    .feb-quote-choice {
        position: relative;
        display: block;
        width: 100%;
        border: 2px solid #e2e8f0;
        border-radius: 20px;
        background: #fff;
        padding: 18px;
        text-align: left;
        transition: border-color .18s ease, box-shadow .18s ease, transform .18s ease;
    }
    .feb-quote-choice:hover { border-color:#6366f1;box-shadow:0 16px 35px rgba(79,70,229,.12);transform:translateY(-2px) }
    .feb-quote-choice.is-retained { border-color:#10b981;background:linear-gradient(145deg,#ecfdf5,#fff) }
    .feb-proof-icon { display:inline-grid;width:38px;height:38px;place-items:center;border-radius:12px;text-decoration:none }

    #decision {
        grid-column: 1 / -1;
    }

    .feb-journal-panel {
        grid-column: 1 / -1;
    }

    .feb-analysis-grid > aside {
        display: contents;
    }

    @media (max-width: 1279px) {
        .feb-analysis-grid {
            grid-template-columns: minmax(0, 1fr);
        }
    }

    .feb-panel {
        position: relative;
        overflow: hidden;
        border: 1px solid rgba(148, 163, 184, 0.24);
        border-radius: 24px;
        background: rgba(255, 255, 255, 0.96);
        box-shadow: 0 20px 45px rgba(15, 23, 42, 0.08);
    }

    .feb-panel::before {
        content: "";
        position: absolute;
        inset: 0 0 auto 0;
        height: 4px;
        background: linear-gradient(90deg, #4f46e5 0%, #0f172a 52%, #10b981 100%);
    }

    .feb-kicker {
        font-size: 11px;
        font-weight: 800;
        letter-spacing: 0.18em;
        text-transform: uppercase;
        color: #4f46e5;
    }

    .feb-stat-card {
        border: 1px solid rgba(226, 232, 240, 0.95);
        border-radius: 18px;
        background: linear-gradient(180deg, rgba(255, 255, 255, 0.96), rgba(248, 250, 252, 0.98));
        padding: 16px 18px;
    }

    .feb-stat-label {
        font-size: 11px;
        font-weight: 800;
        letter-spacing: 0.14em;
        text-transform: uppercase;
        color: #64748b;
    }

    .feb-meta-grid {
        display: grid;
        gap: 12px;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    }

    .feb-meta-item {
        border: 1px solid rgba(226, 232, 240, 0.95);
        border-radius: 18px;
        background: #fff;
        padding: 14px 16px;
    }

    .feb-meta-label {
        display: block;
        margin-bottom: 6px;
        font-size: 11px;
        font-weight: 800;
        letter-spacing: 0.14em;
        text-transform: uppercase;
        color: #94a3b8;
    }

    .feb-section {
        border: 1px solid rgba(226, 232, 240, 0.95);
        border-radius: 20px;
        background: rgba(248, 250, 252, 0.72);
        padding: 18px;
    }

    .feb-section-title {
        font-size: 13px;
        font-weight: 800;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        color: #0f172a;
    }

    .feb-doc {
        border: 1px solid rgba(226, 232, 240, 0.95);
        border-radius: 18px;
        background: #fff;
        padding: 14px 16px;
    }

    .feb-doc--invoice {
        border-color: rgba(125, 211, 252, 0.9);
        background: linear-gradient(180deg, rgba(240, 249, 255, 0.96), rgba(255, 255, 255, 0.98));
    }

    .feb-timeline-item {
        position: relative;
        padding-left: 18px;
    }

    .feb-timeline-item::before {
        content: "";
        position: absolute;
        left: 0;
        top: 7px;
        width: 8px;
        height: 8px;
        border-radius: 999px;
        background: #4f46e5;
        box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.12);
    }

    .feb-input,
    .feb-textarea {
        width: 100%;
        border: 1px solid #dbe3ee;
        border-radius: 16px;
        background: #fff;
        padding: 10px 12px;
        font-size: 13px;
        color: #0f172a;
    }

    .feb-textarea {
        min-height: 88px;
        resize: vertical;
    }

    .feb-input:focus,
    .feb-textarea:focus {
        outline: none;
        border-color: #6366f1;
        box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.12);
    }


    .feb-preview {
        overflow: hidden;
        border: 1px solid rgba(226, 232, 240, 0.95);
        border-radius: 18px;
        background: #f8fafc;
    }

    .feb-preview--image {
        padding: 12px;
    }

    .feb-preview-img {
        display: block;
        width: auto;
        max-width: 100%;
        max-height: 520px;
        margin: 0 auto;
        border-radius: 14px;
        object-fit: contain;
        background: #fff;
    }

    .feb-preview-frame {
        display: block;
        width: 100%;
        height: 560px;
        border: 0;
        background: #fff;
    }

    @media (max-width: 768px) {
        .feb-preview-frame {
            height: 420px;
        }

        .feb-preview-img {
            max-height: 420px;
        }
    }


    .feb-decision-box {
        border: 1px solid rgba(226, 232, 240, 0.95);
        border-radius: 20px;
        background: linear-gradient(180deg, rgba(255, 255, 255, .98), rgba(248, 250, 252, .96));
        padding: 16px;
    }

    .feb-rule-list {
        margin-top: 10px;
        display: grid;
        gap: 8px;
        font-size: 13px;
        color: #334155;
    }

    .feb-rule-ok,
    .feb-rule-warn,
    .feb-rule-info {
        display: flex;
        gap: 8px;
        align-items: flex-start;
        border-radius: 14px;
        padding: 10px 12px;
    }

    .feb-rule-ok {
        background: #ecfdf5;
        color: #065f46;
    }

    .feb-rule-warn {
        background: #fff7ed;
        color: #9a3412;
    }

    .feb-rule-info {
        background: #eff6ff;
        color: #1e3a8a;
    }

    .feb-action-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 16px;
        padding: 11px 14px;
        font-size: 13px;
        font-weight: 700;
        transition: 0.2s ease;
    }

    .feb-action-btn:disabled,
    .feb-action-btn.is-disabled {
        cursor: not-allowed;
        opacity: .48;
        filter: grayscale(.25);
    }

    .feb-auto-panel {
        border: 1px solid rgba(99, 102, 241, .22);
        border-radius: 22px;
        background: linear-gradient(180deg, rgba(238, 242, 255, .78), rgba(255, 255, 255, .98));
        padding: 16px;
    }

    .feb-search-wrap {
        position: relative;
    }

    .feb-native-select-hidden {
        position: absolute !important;
        width: 1px !important;
        height: 1px !important;
        overflow: hidden !important;
        opacity: 0 !important;
        pointer-events: none !important;
    }

    .feb-search-results {
        position: absolute;
        inset: calc(100% + 6px) 0 auto 0;
        z-index: 40;
        max-height: 240px;
        overflow: auto;
        border: 1px solid rgba(226, 232, 240, .95);
        border-radius: 16px;
        background: #fff;
        box-shadow: 0 18px 34px rgba(15, 23, 42, .14);
    }

    .feb-search-option {
        width: 100%;
        border: 0;
        background: transparent;
        padding: 10px 12px;
        text-align: left;
        font-size: 13px;
        color: #0f172a;
    }

    .feb-search-option:hover {
        background: #eef2ff;
    }

    .feb-mini-card {
        border: 1px solid rgba(226, 232, 240, .95);
        border-radius: 18px;
        background: rgba(255, 255, 255, .86);
        padding: 12px;
    }

    .feb-mini-photo {
        width: 56px;
        height: 56px;
        border-radius: 16px;
        object-fit: cover;
        border: 2px solid #fff;
        box-shadow: 0 10px 24px rgba(79, 70, 229, .18);
    }

    .feb-accordion-trigger {
        width: 100%;
        border: 1px solid rgba(148, 163, 184, .35);
        border-radius: 22px;
        background: linear-gradient(180deg, rgba(248, 250, 252, .98), rgba(255, 255, 255, .98));
        padding: 16px;
        text-align: left;
        color: #334155;
        transition: border-color .2s ease, background .2s ease, box-shadow .2s ease, color .2s ease;
    }

    .feb-accordion-trigger.is-open {
        border-color: rgba(16, 185, 129, .48);
        background: linear-gradient(180deg, rgba(236, 253, 245, .95), rgba(255, 255, 255, .98));
        color: #064e3b;
        box-shadow: 0 16px 34px rgba(16, 185, 129, .12);
    }

    .feb-accordion-trigger.is-closed {
        border-color: rgba(148, 163, 184, .35);
        background: linear-gradient(180deg, rgba(248, 250, 252, .98), rgba(255, 255, 255, .98));
    }

    .feb-accordion-badge {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        border-radius: 18px;
        border: 1px solid rgba(148, 163, 184, .35);
        background: #fff;
        padding: 9px 13px;
        font-size: 13px;
        font-weight: 800;
        color: #475569;
        white-space: nowrap;
    }

    .feb-accordion-trigger.is-open .feb-accordion-badge {
        border-color: rgba(16, 185, 129, .35);
        background: #ecfdf5;
        color: #047857;
    }

    .feb-accordion-trigger.is-closed .feb-accordion-badge {
        border-color: rgba(99, 102, 241, .22);
        background: #eef2ff;
        color: #4338ca;
    }

    .feb-auto-panel.is-disabled {
        display: none !important;
    }

    .feb-dropzone {
        display: grid;
        gap: 8px;
        place-items: center;
        min-height: 126px;
        border: 1.5px dashed rgba(99, 102, 241, .35);
        border-radius: 18px;
        background: rgba(255, 255, 255, .78);
        padding: 14px;
        cursor: pointer;
        text-align: center;
    }

    .feb-dropzone.is-dragover {
        border-color: #4f46e5;
        background: #eef2ff;
    }

    .feb-drop-preview {
        max-width: 100%;
        max-height: 110px;
        border-radius: 14px;
        object-fit: contain;
        background: #fff;
    }

    .feb-rh-media-field {
        border: 1px solid rgba(226, 232, 240, .95);
        border-radius: 16px;
        background: #fff;
        padding: 12px;
        min-height: 96px;
    }

    .feb-rh-media-field img {
        width: 86px;
        height: 86px;
        border-radius: 14px;
        object-fit: cover;
        background: #f8fafc;
    }
</style>

<div class="feb-shell min-h-screen pb-10">
    <div class="feb-page-container px-4 py-6 2xl:px-8">
        <section class="feb-panel p-6">
            <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                <div class="min-w-0">
                    <div class="feb-kicker">Traitement FEB</div>
                    <h1 class="mt-3 text-2xl font-semibold text-slate-950">#<?= (int) ($view['id'] ?? 0) ?> - <?= htmlspecialchars((string) ($view['type_label'] ?? 'FEB'), ENT_QUOTES, 'UTF-8') ?></h1>
                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <span class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold <?= $badge['class'] ?>"><?= htmlspecialchars((string) $badge['label'], ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold <?= $budgetPlanned === 'yes' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' ?>"><?= htmlspecialchars($budgetPlannedLabel, ENT_QUOTES, 'UTF-8') ?></span>
                        <a href="#comparaison-prix" class="inline-flex items-center rounded-full px-3 py-1 text-xs font-black <?= $submittedPriceChallenges ? 'bg-violet-100 text-violet-800' : 'bg-slate-100 text-slate-600' ?>"><i class="fas <?= $submittedPriceChallenges ? 'fa-scale-balanced' : 'fa-circle-minus' ?> mr-2"></i><?= $submittedPriceChallenges ? count($submittedPriceChallenges).' contre-proposition(s) reçue(s)' : 'Aucune contre-proposition reçue' ?></a>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <a href="<?= htmlspecialchars($listUrl, ENT_QUOTES, 'UTF-8') ?>" class="feb-action-btn border border-slate-200 bg-white text-slate-900 hover:bg-slate-50">Retour à la liste</a>
                    <a href="request/export_feb_submission_pdf.php?id=<?= (int)$view['id'] ?>" target="_blank" rel="noopener" class="feb-action-btn bg-teal-700 text-white">Télécharger la FEB</a>
                    <?php if ($detailBci !== ''): ?><a href="request/export_feb_pdf.php?id=<?= (int) ($view['id'] ?? 0) ?>" target="_blank" rel="noopener" class="feb-action-btn border border-slate-200 bg-white text-slate-900 hover:bg-slate-50">Télécharger le BCI</a><?php else: ?><span class="feb-action-btn border border-slate-200 bg-slate-50 text-slate-500">BCI généré après planification</span><?php endif; ?>
                </div>
            </div>

            <nav class="feb-flow-nav" aria-label="Parcours de la FEB">
                <a href="#feb-request-content"><i class="fas fa-list-check"></i> Besoins et justificatifs</a>
                <a href="#comparaison-prix"><i class="fas fa-scale-balanced"></i> Comparaison des devis</a>
                <a href="request/export_feb_submission_pdf.php?id=<?= (int)$view['id'] ?>" target="_blank" rel="noopener"><i class="fas fa-file-pdf"></i> Export FEB</a>
                <a href="#decision"><i class="fas fa-check"></i> Examiner la décision</a>
            </nav>
            <details class="mt-5 rounded-2xl border border-slate-200 bg-white p-4" id="feb-document-preview">
                <summary class="cursor-pointer font-bold text-slate-800">Aperçu de la FEB officielle</summary>
                <iframe src="request/export_feb_submission_pdf.php?id=<?= (int)$view['id'] ?>#toolbar=0" title="Aperçu de la FEB #<?= (int)$view['id'] ?>" loading="lazy" class="mt-4 h-[75vh] min-h-[520px] w-full rounded-xl border border-slate-200 bg-white"></iframe>
            </details>
            <div class="feb-process-strip" aria-label="Circuit de traitement">
                <div><strong>01 · Expression du besoin</strong><small>Devis et lignes demandées</small></div>
                <div><strong>02 · Validation</strong><small>Budget, bénéficiaire et planification</small></div>
                <div><strong>03 · Décaissement</strong><small>Fiche rattachée et paiement</small></div>
                <div><strong>04 · Régularisation</strong><small>Facture et justificatifs de réception</small></div>
            </div>
            <div id="feb-request-content"></div>
            <?php if ($detailFlash !== ''): ?>
                <?php
                $detailFlashClasses = [
                    'success' => 'border-emerald-200 bg-emerald-50 text-emerald-800',
                    'error' => 'border-rose-200 bg-rose-50 text-rose-800',
                    'info' => 'border-sky-200 bg-sky-50 text-sky-800',
                ];
                ?>
                <div class="mt-5 rounded-2xl border px-4 py-4 text-sm font-semibold <?= $detailFlashClasses[$detailFlashType] ?? $detailFlashClasses['info'] ?>">
                    <?= htmlspecialchars($detailFlash, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <?php if ($viewHasBlockingUrgency): ?>
                <?php $blockedUrgency = $viewRequesterBlockedUrgencies[0] ?? []; ?>
                <div class="mt-5 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-4 text-sm text-rose-900">
                    <div class="font-semibold">Fiche de <?= htmlspecialchars((string)($blockedUrgency['requester_name'] ?? ($view['requester_name'] ?? 'demandeur')), ENT_QUOTES, 'UTF-8') ?> non regularisee</div>
                    <div class="mt-1">
                        Le chantier <?= htmlspecialchars((string)($blockedUrgency['chantier_label'] ?? ''), ENT_QUOTES, 'UTF-8') ?> est bloque par le decaissement urgent
                        <?= htmlspecialchars((string)($blockedUrgency['request_code'] ?? ''), ENT_QUOTES, 'UTF-8') ?>.
                        Cette FEB n'est pas liee a cette urgence; utilisez le lien de regularisation envoye au demandeur.
                    </div>
                </div>
            <?php elseif ($viewUrgentRegularizationId > 0): ?>
                <div class="mt-5 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-4 text-sm text-emerald-900">
                    FEB de regularisation du decaissement urgent #<?= (int)$viewUrgentRegularizationId ?>.
                </div>
            <?php endif; ?>

            <div class="mt-6 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                <div class="feb-stat-card">
                    <div class="feb-stat-label">Référence</div>
                    <div class="mt-2 text-base font-semibold text-slate-950"><?= htmlspecialchars($detailBci !== '' ? $detailBci : ('FEB #' . (int) ($view['id'] ?? 0)), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="feb-stat-card">
                    <div class="feb-stat-label">Montant</div>
                    <div class="mt-2 text-base font-semibold text-emerald-700"><?= htmlspecialchars((string) ($febDisplay['montant_label'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="feb-stat-card">
                    <div class="feb-stat-label">Demandeur</div>
                    <div class="mt-2 text-base font-semibold text-slate-950"><?= htmlspecialchars((string) ($febDisplay['demandeur'] ?? ($view['requester_name'] ?? '')), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="feb-stat-card"><div class="feb-stat-label">Devis FEB</div><div class="mt-2 text-base font-semibold text-slate-950"><?= count($besoins) ?> ligne(s)</div></div>
            </div>

            <div class="feb-scenario-hero <?= $budgetPlanned === 'yes' ? 'feb-scenario-hero--planned' : 'feb-scenario-hero--unplanned' ?> mt-5">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div class="min-w-0">
                        <div class="text-xs font-black uppercase tracking-[0.18em] <?= $budgetPlanned === 'yes' ? 'text-emerald-700' : 'text-amber-700' ?>">
                            <?= $budgetPlanned === 'yes' ? 'Déboursé existant sélectionné' : 'Nouveau déboursé proposé' ?>
                        </div>
                        <div class="feb-scenario-title"><?= htmlspecialchars($analysisDebourseTitle, ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="mt-2 max-w-4xl text-sm leading-6 text-slate-600">
                            <?= $budgetPlanned === 'yes'
                                ? 'Le budget existe déjà. Vérifiez le chantier, la ligne de déboursé, le fournisseur, le montant et la date prévue avant de générer la fiche.'
                                : 'Ce budget n’existe pas encore. L’approbation intégrera ce déboursé et ses lignes au chantier sélectionné.' ?>
                        </div>
                    </div>
                    <div class="shrink-0 rounded-2xl bg-white/90 px-5 py-4 text-right shadow-sm ring-1 ring-slate-200/80">
                        <div class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500"><?= $budgetPlanned === 'yes' ? 'Montant FEB' : 'Total proposé' ?></div>
                        <div class="mt-1 text-2xl font-black <?= $budgetPlanned === 'yes' ? 'text-emerald-700' : 'text-amber-700' ?>"><?= number_format((float) ($budgetPlanned === 'yes' ? ($payload['montant'] ?? 0) : ($proposedDebourse['total_amount'] ?? $payload['montant'] ?? 0)), 0, ',', ' ') ?> FCFA</div>
                    </div>
                </div>
                <div class="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                    <div class="feb-context-card"><div class="text-xs font-black uppercase tracking-[0.14em] text-slate-500">Chantier</div><div class="mt-2 font-black text-slate-950"><?= htmlspecialchars($febBudgetContext['chantier']) ?></div></div>
                    <div class="feb-context-card"><div class="text-xs font-black uppercase tracking-[0.14em] text-slate-500">Devis de référence</div><div class="mt-2 font-black text-slate-950"><?= htmlspecialchars($febBudgetContext['devis']) ?></div><span class="mt-2 inline-flex rounded-full bg-indigo-100 px-2.5 py-1 text-xs font-bold text-indigo-800"><?= htmlspecialchars($febBudgetContext['devis_type']) ?></span></div>
                    <div class="feb-context-card"><div class="text-xs font-black uppercase tracking-[0.14em] text-slate-500">Déboursé</div><div class="mt-2 font-black text-slate-950"><?= htmlspecialchars($febBudgetContext['debourse']) ?></div></div>
                    <div class="feb-context-card"><div class="text-xs font-black uppercase tracking-[0.14em] text-slate-500">Situation budgétaire</div><span class="mt-2 inline-flex rounded-full <?= $budgetPlanned === 'yes' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' ?> px-3 py-1.5 text-xs font-black"><?= htmlspecialchars($febBudgetContext['debourse_status']) ?></span><div class="mt-2 text-xs leading-5 text-slate-500"><?= $budgetPlanned === 'yes' ? 'Budget déjà disponible pour la fiche.' : 'Sera créé uniquement après validation.' ?></div></div>
                </div>
                <div class="mt-3 flex flex-col gap-3 rounded-2xl border border-sky-200 bg-sky-50/90 px-5 py-4 md:flex-row md:items-center md:justify-between">
                    <div><div class="text-xs font-black uppercase tracking-[0.16em] text-sky-700">Planning à contrôler</div><div class="mt-1 text-sm text-slate-600">Ces dates déterminent l’exécution et le positionnement du décaissement.</div></div>
                    <div class="flex flex-wrap gap-2 text-sm font-black text-slate-900"><span class="rounded-xl bg-white px-4 py-2 ring-1 ring-sky-200">Début : <?= htmlspecialchars(febDetailFormatDate((string) ($payload['planned_start_date'] ?? ''))) ?></span><span class="rounded-xl bg-white px-4 py-2 ring-1 ring-sky-200">Fin : <?= htmlspecialchars(febDetailFormatDate((string) ($payload['planned_end_date'] ?? ''))) ?></span><span class="rounded-xl bg-white px-4 py-2 ring-1 ring-sky-200">Décaissement : <?= htmlspecialchars(febDetailFormatDate((string) ($provisionalDecaissement['date_decaissement_minimum'] ?? $payload['planned_start_date'] ?? $payload['date_livraison'] ?? ''))) ?></span></div>
                </div>
            </div>
        </section>

        <?php if($noProposalChallenges): ?>
        <section class="feb-panel mt-6 p-6">
            <div class="flex flex-wrap items-center justify-between gap-3"><div><div class="feb-kicker">Réponses reçues</div><h2 class="mt-2 text-xl font-black text-slate-950">Challengers sans proposition disponible</h2></div><span class="rounded-full bg-slate-100 px-4 py-2 text-sm font-black text-slate-700"><?=count($noProposalChallenges)?> réponse(s)</span></div>
            <div class="mt-4 grid gap-3 md:grid-cols-2"><?php foreach($noProposalChallenges as $challenge):$line=(array)($besoins[(int)$challenge['line_index']]??[]);?><article class="rounded-2xl border border-slate-200 bg-slate-50 p-4"><div class="flex items-start gap-3"><span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-slate-900 text-white"><i class="fas fa-circle-minus"></i></span><div><div class="font-black text-slate-900">Ligne #<?=((int)$challenge['line_index']+1)?> · <?=htmlspecialchars((string)($line['libelle']??$challenge['category']))?></div><div class="mt-1 text-xs font-bold text-slate-500"><?=htmlspecialchars(trim((string)$challenge['prenom'].' '.(string)$challenge['nom']))?> · <?=!empty($challenge['submitted_at'])?date('d/m/Y H:i',strtotime((string)$challenge['submitted_at'])):'Date non disponible'?></div><p class="mt-3 text-sm leading-6 text-slate-700"><?=nl2br(htmlspecialchars((string)($challenge['proposal_note']??'Aucun motif précisé.')))?></p></div></div></article><?php endforeach;?></div>
        </section>
        <?php endif; ?>

        <section id="comparaison-prix" class="feb-panel mt-6 overflow-hidden p-6 lg:p-8">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div><div class="feb-kicker">Aide à la décision</div><h2 class="mt-2 text-2xl font-black text-slate-950">Comparaison des propositions financières</h2><p class="mt-2 text-sm text-slate-600">Seules les propositions effectivement soumises sont affichées. Cliquez sur une carte pour la retenir.</p></div>
                <span class="rounded-full px-4 py-2 text-sm font-black <?= $submittedPriceChallenges?'bg-violet-100 text-violet-800':'bg-slate-100 text-slate-600' ?>"><?= $submittedPriceChallenges?count($submittedPriceChallenges).' reçue(s)':'Aucune reçue' ?></span>
            </div>
            <?php if (!$submittedChallengesByLine): ?>
                <div class="mt-6 rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-8 text-center"><i class="fas fa-scale-unbalanced-flip text-3xl text-slate-300"></i><h3 class="mt-3 font-black text-slate-900">Absence de devis contradictoire</h3><p class="mt-1 text-sm text-slate-500">Aucun challenger n’a encore transmis une proposition complète pour cette FEB.</p></div>
            <?php else: ?>
                <div class="mt-6 space-y-6">
                <?php foreach($submittedChallengesByLine as $lineIndex=>$lineChallenges): $originalLine=(array)($besoins[$lineIndex]??[]);$originalSupplier=(array)($originalLine['fournisseur']??[]);$originalProof=(array)($originalLine['justificatif_devis']??[]);$snapshot=(array)($lineChallenges[0]??[]);$originalPrice=(float)($snapshot['original_unit_price']??$originalSupplier['prix_unitaire']??0);$originalQuantity=max(1,(float)($originalLine['quantite']??1));$originalTotal=$originalPrice*$originalQuantity;$originalSupplierName=(string)($snapshot['original_supplier_name']??$originalSupplier['nom']??'');$originalProofUrl=(string)($snapshot['original_justification_path']??$originalProof['url']??'');$retainedInfo=(array)($originalLine['proposition_retenue']??[]); ?>
                    <article class="rounded-3xl border border-violet-100 bg-violet-50/40 p-5">
                        <div class="flex flex-wrap items-center justify-between gap-3"><div><span class="text-xs font-black uppercase tracking-wider text-violet-700">Ligne #<?=$lineIndex+1?> · <?=htmlspecialchars((string)($originalLine['categorie']??''))?></span><h3 class="mt-1 text-lg font-black text-slate-950"><?=htmlspecialchars((string)($originalLine['libelle']??'Besoin FEB'))?></h3></div><span class="rounded-full bg-white px-3 py-1 text-xs font-bold text-slate-600">Quantité : <?=htmlspecialchars((string)($originalLine['quantite']??0))?></span></div>
                        <div class="mt-5 grid gap-4 lg:grid-cols-2 2xl:grid-cols-3">
                            <form method="post" action="request/retain_feb_initial_quote.php" class="feb-choice-form"><input type="hidden" name="csrf_token" value="<?=htmlspecialchars($challengeReviewCsrf)?>"><input type="hidden" name="request_id" value="<?=(int)$view['id']?>"><input type="hidden" name="line_index" value="<?=$lineIndex?>"><button class="feb-quote-choice <?=($retainedInfo['type']??'')==='initiale'?'is-retained':''?>" type="submit"><div class="flex items-start justify-between gap-3"><div><span class="rounded-full bg-slate-900 px-3 py-1 text-xs font-black text-white">Proposition du demandeur</span><div class="mt-4 text-2xl font-black text-slate-950"><?=number_format($originalTotal,0,',',' ')?> FCFA</div><div class="mt-1 text-xs font-semibold text-slate-500"><?=number_format($originalPrice,0,',',' ')?> FCFA × <?=number_format($originalQuantity,0,',',' ')?> unité(s)</div><div class="mt-2 font-bold text-slate-600"><?=htmlspecialchars($originalSupplierName?:'Fournisseur non précisé')?></div><div class="mt-2 text-xs text-slate-500"><?=htmlspecialchars((string)($view['requester_name']??'Soumissionnaire FEB'))?></div></div><span class="feb-proof-icon <?=$originalProofUrl!==''?'bg-emerald-100 text-emerald-700':'bg-rose-100 text-rose-600'?>" title="<?=$originalProofUrl!==''?'Justificatif disponible':'Justificatif absent'?>"><i class="fas <?=$originalProofUrl!==''?'fa-file-circle-check':'fa-file-circle-xmark'?>"></i></span></div><div class="mt-4 border-t pt-3 text-sm font-black <?=($retainedInfo['type']??'')==='initiale'?'text-emerald-700':'text-indigo-700'?>"><?=($retainedInfo['type']??'')==='initiale'?'<i class="fas fa-check-circle mr-1"></i> Proposition retenue':'Cliquer pour retenir cette proposition'?></div></button><?php if($originalProofUrl!==''):?><a href="<?=htmlspecialchars($originalProofUrl)?>" target="_blank" rel="noopener" class="mt-2 inline-flex items-center text-xs font-black text-indigo-700"><i class="fas fa-eye mr-2"></i>Visualiser le justificatif du demandeur</a><?php endif;?></form>
                            <?php foreach($lineChallenges as $challenge):$proof=(string)($challenge['justification_path']??'');$retained=(string)$challenge['status']==='retained';?>
                            <form method="post" action="request/retain_feb_price_challenge.php" class="feb-choice-form"><input type="hidden" name="csrf_token" value="<?=htmlspecialchars($challengeReviewCsrf)?>"><input type="hidden" name="challenge_id" value="<?=(int)$challenge['id']?>"><input type="hidden" name="return_id" value="<?=(int)$view['id']?>"><button class="feb-quote-choice <?=$retained?'is-retained':''?>" type="submit"><div class="flex items-start justify-between gap-3"><div><span class="rounded-full bg-violet-100 px-3 py-1 text-xs font-black text-violet-800">Contre-proposition</span><div class="mt-4 text-2xl font-black text-slate-950"><?=number_format((float)$challenge['proposed_total_amount'],0,',',' ')?> FCFA</div><div class="mt-1 text-xs font-semibold text-slate-500">Prix unitaire calculé : <?=number_format((float)$challenge['proposed_unit_price'],2,',',' ')?> FCFA</div><div class="mt-2 font-bold text-slate-600"><?=htmlspecialchars((string)$challenge['proposed_supplier_name'])?></div><div class="mt-2 text-xs text-slate-500">Proposée par <?=htmlspecialchars(trim((string)$challenge['prenom'].' '.(string)$challenge['nom']))?></div></div><span class="feb-proof-icon <?=$proof!==''?'bg-emerald-100 text-emerald-700':'bg-rose-100 text-rose-600'?>" title="<?=$proof!==''?'Justificatif disponible':'Justificatif absent'?>"><i class="fas <?=$proof!==''?'fa-file-circle-check':'fa-file-circle-xmark'?>"></i></span></div><div class="mt-4 border-t pt-3 text-sm font-black <?=$retained?'text-emerald-700':'text-violet-700'?>"><?=$retained?'<i class="fas fa-check-circle mr-1"></i> Proposition retenue · cliquer pour resynchroniser la fiche':'Cliquer pour retenir cette proposition'?></div></button><?php if($proof!==''):?><a href="<?=htmlspecialchars($proof)?>" target="_blank" rel="noopener" class="mt-2 inline-flex items-center text-xs font-black text-indigo-700"><i class="fas fa-eye mr-2"></i>Visualiser le justificatif</a><?php endif;?></form>
                            <?php endforeach;?>
                        </div>
                    </article>
                <?php endforeach;?></div>
            <?php endif;?>
        </section>

        <div class="feb-analysis-grid mt-6">
            <div class="feb-analysis-column space-y-6">
                <section class="feb-panel p-6">
                    <div class="feb-kicker">Contenu</div>
                    <div class="mt-4 feb-meta-grid">
                        <div class="feb-meta-item">
                            <span class="feb-meta-label">Chantier</span>
                            <div class="font-semibold text-slate-900"><?= htmlspecialchars((string) ($febDisplay['chantier_label'] ?? 'CHANTIER N° -'), ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                        <div class="mt-4 feb-section">
                            <div class="feb-section-title"><?= htmlspecialchars((string) ($payload['feb_quote_title'] ?? 'Devis FEB'), ENT_QUOTES, 'UTF-8') ?></div>
                            <?php if ($besoins): ?>
                                <div class="mt-4 space-y-3">
                                    <?php foreach ($besoins as $besoin): ?>
                                        <?php $fournisseur = !empty($besoin['fournisseur']) && is_array($besoin['fournisseur']) ? (array) $besoin['fournisseur'] : []; ?>
                                        <article class="rounded-2xl border border-slate-200 bg-white px-4 py-4">
                                            <div class="flex flex-wrap items-start justify-between gap-3">
                                                <div class="min-w-0">
                                                    <div class="font-semibold text-slate-950"><?= htmlspecialchars((string) ($besoin['libelle'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                                    <div class="mt-1 text-sm text-slate-500"><?= htmlspecialchars((string) ($besoin['categorie'] ?? '—'), ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars((string) ($besoin['type'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                                </div>
                                                <span class="inline-flex rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">Qté: <?= (int) ($besoin['quantite'] ?? 0) ?></span>
                                            </div>
                                            <?php if ($fournisseur): ?>
                                                <div class="mt-3 text-sm text-slate-600">
                                                    <span class="font-semibold text-slate-800">Fournisseur:</span>
                                                    <?= htmlspecialchars((string) ($fournisseur['nom'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                                    <?php if (!empty($fournisseur['prix_unitaire'])): ?>
                                                        - <?= htmlspecialchars(number_format((float) $fournisseur['prix_unitaire'], 0, ',', ' '), ENT_QUOTES, 'UTF-8') ?> XOF
                                                    <?php endif; ?>
                                                    <?php if (!empty($fournisseur['telephone'])): ?>
                                                        - <?= htmlspecialchars((string) $fournisseur['telephone'], ENT_QUOTES, 'UTF-8') ?>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </article>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="mt-3 text-sm text-slate-500">Aucun besoin détaillé n’a été renseigné.</div>
                            <?php endif; ?>
                        </div>
                        <div class="feb-meta-item">
                            <span class="feb-meta-label">Créée le</span>
                            <div class="font-semibold text-slate-900"><?= htmlspecialchars((string) (($febDisplay['created_date'] ?? '') ?: substr((string) ($view['submitted_at'] ?? ''), 0, 10)), ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                        <div class="feb-meta-item">
                            <span class="feb-meta-label">Département bénéficiaire</span>
                            <div class="font-semibold text-slate-900"><?= htmlspecialchars((string) ($payload['departement_label'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                        <div class="feb-meta-item">
                            <span class="feb-meta-label">Livraison</span>
                            <div class="font-semibold text-slate-900"><?= htmlspecialchars(febDetailFormatDate((string) ($payload['date_livraison'] ?? '')), ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                        <div class="feb-meta-item">
                            <span class="feb-meta-label">Fréquence</span>
                            <div class="font-semibold text-slate-900"><?= htmlspecialchars((string) ($payload['frequence'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                        <div class="feb-meta-item"><span class="feb-meta-label">Position budgétaire</span><div class="font-semibold text-slate-900"><?= htmlspecialchars($budgetPlannedLabel, ENT_QUOTES, 'UTF-8') ?></div></div>
                        <div class="feb-meta-item"><span class="feb-meta-label">Planning</span><div class="font-semibold text-slate-900"><?= htmlspecialchars((string) ($payload['planned_start_date'] ?? '—'), ENT_QUOTES, 'UTF-8') ?> → <?= htmlspecialchars((string) ($payload['planned_end_date'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></div></div>
                    </div>

                    <?php if (!empty($febDisplay['cashflow_comment'] ?? '')): ?>
                        <div class="mt-4 feb-section border-amber-200 bg-amber-50/90">
                            <div class="feb-section-title text-amber-700">Précision comptable</div>
                            <div class="mt-2 whitespace-pre-line text-sm text-amber-950"><?= htmlspecialchars((string) $febDisplay['cashflow_comment'], ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($payload['description'])): ?>
                        <div class="mt-4 feb-section">
                            <div class="feb-section-title">Description</div>
                            <div class="mt-2 whitespace-pre-line text-sm leading-6 text-slate-700"><?= htmlspecialchars((string) $payload['description'], ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                    <?php endif; ?>

                    <?php $proposedDebourse = is_array($payload['proposed_debourse'] ?? null) ? (array) $payload['proposed_debourse'] : []; ?>
                    <?php if ($proposedDebourse): ?>
                        <div class="mt-4 feb-section border-amber-300 bg-amber-50/90">
                            <div class="text-xs font-black uppercase tracking-[0.18em] text-amber-700">Déboursé proposé — à analyser</div>
                            <div class="mt-2 text-2xl font-black leading-tight text-slate-950 break-words"><?= htmlspecialchars((string) ($proposedDebourse['title'] ?? 'Déboursé sans titre'), ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="mt-2 grid gap-2 text-sm text-slate-800 md:grid-cols-2">
                                <div><strong>Ligne de devis :</strong> <?= !empty($proposedDebourse['ligne_devis_id']) ? 'Ligne #' . (int) $proposedDebourse['ligne_devis_id'] : htmlspecialchars((string) ($proposedDebourse['ligne_devis_proposal'] ?? 'Nouvelle ligne proposée'), ENT_QUOTES, 'UTF-8') ?></div>
                                <div><strong>Effet de l’approbation :</strong> création sur le chantier</div>
                            </div>
                            <div class="mt-3 overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr class="border-b border-amber-200"><th class="p-2">Ligne</th><th class="p-2">Catégorie</th><th class="p-2">Montant</th></tr></thead><tbody>
                                <?php foreach ((array) ($proposedDebourse['lines'] ?? []) as $proposalLine): ?>
                                    <tr class="border-b border-amber-100"><td class="p-2 font-semibold"><?= htmlspecialchars((string) ($proposalLine['designation'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td><td class="p-2"><?= htmlspecialchars((string) ($proposalLine['categorie'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td><td class="p-2"><?= number_format((float) ($proposalLine['montant'] ?? 0), 0, ',', ' ') ?> FCFA</td></tr>
                                <?php endforeach; ?>
                            </tbody></table></div>
                            <div class="mt-3 text-right font-bold text-amber-950">Total proposé : <?= number_format((float) ($proposedDebourse['total_amount'] ?? 0), 0, ',', ' ') ?> FCFA</div>
                        </div>
                    <?php endif; ?>

                    <?php if (false): // Ancienne table remplacée par la comparaison pleine largeur ci-dessus. ?>
                        <div class="mt-4 feb-section border-violet-200 bg-violet-50/80">
                            <div class="feb-section-title text-violet-800">Contre-propositions internes confidentielles</div>
                            <div class="mt-3 overflow-x-auto"><table class="w-full min-w-[900px] text-sm"><thead><tr><th class="p-2">Ligne FEB</th><th class="p-2">Proposition initiale</th><th class="p-2">Soumissionnaire</th><th class="p-2">Prix contradictoire</th><th class="p-2">Fournisseur proposé</th><th class="p-2">Justificatif</th><th class="p-2">Décision</th></tr></thead><tbody>
                            <?php foreach ($priceChallenges as $challenge): ?><?php $originalLine=(array)($besoins[(int)$challenge['line_index']]??[]);$originalSupplier=(array)($originalLine['fournisseur']??[]);$originalProof=(array)($originalLine['justificatif_devis']??[]);$originalProofUrl=(string)($originalProof['url']??'');$challengeProofUrl=(string)($challenge['justification_path']??'');$challengePending=(string)$challenge['status']==='pending'; ?><tr class="border-t border-violet-100"><td class="p-2"><strong>#<?= (int)$challenge['line_index'] + 1 ?> — <?= htmlspecialchars((string)($originalLine['libelle']??$challenge['category'])) ?></strong><br><span class="text-xs text-slate-500"><?= htmlspecialchars((string)$challenge['category']) ?> · Qté <?= htmlspecialchars((string)($originalLine['quantite']??0)) ?></span></td><td class="p-2"><?= number_format((float)($originalSupplier['prix_unitaire']??0),0,',',' ') ?> FCFA<br><span class="text-xs"><?=htmlspecialchars((string)($originalSupplier['nom']??''))?></span><br><?php if($originalProofUrl!==''):?><a class="text-xs font-bold text-indigo-700" href="<?=htmlspecialchars($originalProofUrl)?>" target="_blank" rel="noopener">Ouvrir le devis initial</a><iframe class="feb-proof-preview" src="<?=htmlspecialchars($originalProofUrl)?>" title="Justificatif du devis initial"></iframe><?php else:?><span class="text-xs font-bold text-rose-700">Justificatif initial absent</span><?php endif;?></td><td class="p-2"><?= htmlspecialchars(trim((string)$challenge['prenom'].' '.(string)$challenge['nom'])) ?><br><span class="text-xs font-bold <?= $challengePending?'text-amber-700':'text-emerald-700' ?>"><?= $challengePending?'En attente':'Soumise' ?></span></td><td class="p-2 font-bold"><?= $challengePending?'—':number_format((float)$challenge['proposed_unit_price'],0,',',' ').' FCFA' ?><?php if(!$challengePending && !empty($challenge['proposal_note'])):?><div class="mt-2 text-xs font-normal leading-5 text-slate-600"><?=nl2br(htmlspecialchars((string)$challenge['proposal_note']))?></div><?php endif;?></td><td class="p-2"><?= $challengePending?'—':htmlspecialchars((string)$challenge['proposed_supplier_name']) ?></td><td class="p-2"><?php if($challengeProofUrl!==''):?><a class="font-semibold text-indigo-700" href="<?=htmlspecialchars($challengeProofUrl)?>" target="_blank" rel="noopener">Ouvrir la preuve</a><iframe class="feb-proof-preview" src="<?=htmlspecialchars($challengeProofUrl)?>" title="Justificatif de la proposition contradictoire"></iframe><?php else:?><span class="text-xs text-slate-400"><?= $challengePending?'Attendue':'Absente' ?></span><?php endif;?></td><td class="p-2"><?php if($challenge['status']==='retained'):?><span class="font-bold text-emerald-700">Retenue</span><?php elseif(!$challengePending):?><form method="post" action="request/retain_feb_price_challenge.php"><input type="hidden" name="csrf_token" value="<?=htmlspecialchars($challengeReviewCsrf)?>"><input type="hidden" name="challenge_id" value="<?=$challenge['id']?>"><input type="hidden" name="return_id" value="<?=(int)$view['id']?>"><button class="rounded-lg bg-violet-600 px-3 py-1 text-white">Retenir</button></form><?php else:?><span class="text-xs text-slate-400">Non disponible</span><?php endif;?></td></tr><?php endforeach; ?>
                            </tbody></table></div>
                            <p class="mt-3 text-xs text-violet-700">Ces identités et montants ne sont visibles que dans l'espace de validation FEB.</p>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($payload['impact'])): ?>
                        <div class="mt-4 feb-section">
                            <div class="feb-section-title">Impact</div>
                            <div class="mt-2 whitespace-pre-line text-sm leading-6 text-slate-700"><?= htmlspecialchars((string) $payload['impact'], ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                    <?php endif; ?>


                </section>
            </div>

            <aside class="feb-analysis-column space-y-6">
                <section class="feb-panel feb-guide-panel p-6">
                    <div class="feb-kicker">Guide d'analyse FEB</div>
                    <div class="mt-4 feb-decision-box">
                        <div class="text-lg font-black text-slate-950">3 vérifications avant de décider</div>
                        <div class="mt-1 text-sm text-slate-600"><?= htmlspecialchars($budgetPlannedLabel, ENT_QUOTES, 'UTF-8') ?> — suivez les points dans l’ordre.</div>

                        <div class="feb-rule-list">
                            <?php if ($budgetPlanned === 'yes'): ?>
                                <div class="feb-rule-info"><span class="font-black">1.</span><span><strong>Rattachement :</strong> chantier, devis, déboursé et ligne doivent correspondre.</span></div>
                                <div class="feb-rule-info"><span class="font-black">2.</span><span><strong>Budget :</strong> comparez le montant demandé au disponible de la ligne.</span></div>
                                <div class="feb-rule-info"><span class="font-black">3.</span><span><strong>Exécution :</strong> vérifiez le fournisseur et les dates prévues.</span></div>
                            <?php else: ?>
                                <div class="feb-rule-info"><span class="font-black">1.</span><span><strong>Objet :</strong> confirmez le titre et la ligne de devis concernée.</span></div>
                                <div class="feb-rule-info"><span class="font-black">2.</span><span><strong>Composition :</strong> contrôlez chaque ligne, son montant et ses dates.</span></div>
                                <div class="feb-rule-warn"><span class="font-black">3.</span><span><strong>Conséquence :</strong> l’approbation créera ce nouveau budget sur le chantier.</span></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>

                <?php if (false): // Documents de l'ancien workflow masqués. ?>
                <section class="feb-panel p-6">
                    <div class="feb-kicker">Documents utiles</div>
                    <div class="mt-4 space-y-5">
                        <?php if ($febNeedScope === 'internal'): ?>
                            <div>
                                <div class="mb-2 text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">PV de réception</div>
                                <?php if ($detailPvDocument): ?>
                                    <?php
                                    $pvName = (string) ($detailPvDocument['name'] ?? 'PV de réception');
                                    $pvSize = febDetailFormatFileSize($detailPvDocument['size'] ?? 0);
                                    ?>
                                    <article class="feb-doc border-emerald-200 bg-emerald-50/60">
                                        <div class="flex items-start justify-between gap-3">
                                            <div class="min-w-0">
                                                <div class="font-semibold text-slate-950"><?= htmlspecialchars($pvName, ENT_QUOTES, 'UTF-8') ?></div>
                                                <div class="mt-1 text-xs text-slate-500">
                                                    <?= htmlspecialchars($pvSize, ENT_QUOTES, 'UTF-8') ?>
                                                    <?php if (!empty($payload['pv_reception_number'])): ?>
                                                        - PV n° <?= htmlspecialchars((string) $payload['pv_reception_number'], ENT_QUOTES, 'UTF-8') ?>
                                                    <?php endif; ?>
                                                    <?php if (!empty($payload['bci_number'])): ?>
                                                        - BCI n° <?= htmlspecialchars((string) $payload['bci_number'], ENT_QUOTES, 'UTF-8') ?>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <?php if ($detailPvUrl !== ''): ?>
                                                <a href="<?= htmlspecialchars($detailPvUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" class="feb-action-btn bg-emerald-600 text-white hover:bg-emerald-700">Ouvrir</a>
                                            <?php else: ?>
                                                <span class="text-xs text-slate-500">Indisponible</span>
                                            <?php endif; ?>
                                        </div>

                                        <?php if ($detailPvUrl !== ''): ?>
                                            <?php febDetailRenderDocumentPreview($detailPvUrl, $pvName); ?>
                                        <?php endif; ?>
                                    </article>
                                <?php else: ?>
                                    <div class="feb-doc text-sm text-amber-700 bg-amber-50 border-amber-200">Aucun PV de réception joint. Cette pièce est attendue pour une demande interne.</div>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <?php if ($cashflowNeed === 'internal_purchase_order' || $hasInvoiceDocument): ?>
                                <div>
                                    <div class="mb-2 text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Facture spécifique</div>
                                    <?php if ($detailInvoice): ?>
                                        <?php
                                        $invoiceName = (string) ($detailInvoice['name'] ?? 'Facture');
                                        $invoiceSize = febDetailFormatFileSize($detailInvoice['size'] ?? 0);
                                        ?>
                                        <article class="feb-doc feb-doc--invoice">
                                            <div class="flex items-start justify-between gap-3">
                                                <div class="min-w-0">
                                                    <div class="font-semibold text-slate-950"><?= htmlspecialchars($invoiceName, ENT_QUOTES, 'UTF-8') ?></div>
                                                    <div class="mt-1 text-xs text-slate-500">
                                                        <?= htmlspecialchars($invoiceSize, ENT_QUOTES, 'UTF-8') ?>
                                                        <?php if (!empty($payload['invoice_number'])): ?>
                                                            - Facture n° <?= htmlspecialchars((string) $payload['invoice_number'], ENT_QUOTES, 'UTF-8') ?>
                                                        <?php endif; ?>
                                                        <?php if (!empty($payload['invoice_supplier']['nom'])): ?>
                                                            - Fournisseur: <?= htmlspecialchars((string) $payload['invoice_supplier']['nom'], ENT_QUOTES, 'UTF-8') ?>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <?php if ($detailInvoiceUrl !== ''): ?>
                                                    <a href="<?= htmlspecialchars($detailInvoiceUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" class="feb-action-btn bg-sky-600 text-white hover:bg-sky-700">Ouvrir</a>
                                                <?php else: ?>
                                                    <span class="text-xs text-slate-500">Indisponible</span>
                                                <?php endif; ?>
                                            </div>

                                            <?php if ($detailInvoiceUrl !== ''): ?>
                                                <?php febDetailRenderDocumentPreview($detailInvoiceUrl, $invoiceName); ?>
                                            <?php endif; ?>
                                        </article>
                                    <?php else: ?>
                                        <div class="feb-doc text-sm text-amber-700 bg-amber-50 border-amber-200">Aucune facture spécifique jointe. Cette pièce est attendue pour un décaissement complet et unique.</div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($cashflowNeed === 'provisional_disbursement' || $hasJustificatifs): ?>
                                <div>
                                    <div class="mb-2 text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Justificatifs</div>
                                    <?php if ($detailJustifs): ?>
                                        <div class="space-y-4">
                                            <?php foreach ($detailJustifs as $doc): ?>
                                                <?php
                                                $docName = (string) ($doc['name'] ?? 'Justificatif');
                                                $docUrl = febDetailSafeUploadUrl((string) ($doc['url'] ?? ''));
                                                $docSize = febDetailFormatFileSize($doc['size'] ?? 0);
                                                ?>
                                                <article class="feb-doc">
                                                    <div class="flex items-start justify-between gap-3">
                                                        <div class="min-w-0">
                                                            <div class="font-semibold text-slate-950"><?= htmlspecialchars($docName, ENT_QUOTES, 'UTF-8') ?></div>
                                                            <div class="mt-1 text-xs text-slate-500">
                                                                <?= htmlspecialchars($docSize, ENT_QUOTES, 'UTF-8') ?>
                                                                <?php if (!empty($payload['supporting_document_number'])): ?>
                                                                    - Justificatif n° <?= htmlspecialchars((string) $payload['supporting_document_number'], ENT_QUOTES, 'UTF-8') ?>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                        <?php if ($docUrl !== ''): ?>
                                                            <a href="<?= htmlspecialchars($docUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" class="feb-action-btn border border-slate-200 bg-white text-slate-900 hover:bg-slate-50">Ouvrir</a>
                                                        <?php else: ?>
                                                            <span class="text-xs text-slate-500">Indisponible</span>
                                                        <?php endif; ?>
                                                    </div>

                                                    <?php if ($docUrl !== ''): ?>
                                                        <?php febDetailRenderDocumentPreview($docUrl, $docName); ?>
                                                    <?php endif; ?>
                                                </article>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="feb-doc text-sm text-amber-700 bg-amber-50 border-amber-200">Aucun justificatif joint. Cette pièce est attendue pour un décaissement provisoire.</div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </section>
                <?php endif; ?>

                <section id="decision" class="feb-panel p-6">
                    <div class="feb-kicker">Décision</div>
                    <div class="mt-3 rounded-2xl border <?= $budgetPlanned === 'yes' ? 'border-emerald-200 bg-emerald-50 text-emerald-950' : 'border-amber-200 bg-amber-50 text-amber-950' ?> px-4 py-4">
                        <div class="font-black"><?= $budgetPlanned === 'yes' ? 'Valider le budget et préparer la fiche' : 'Valider la création du nouveau déboursé' ?></div>
                        <div class="mt-1 text-sm leading-6"><?= $budgetPlanned === 'yes' ? 'Relisez les données modifiables, puis complétez la fiche de décaissement si elle doit être générée.' : 'Relisez le titre et les lignes proposées. Aucune fiche ne sera créée avant l’intégration de ce nouveau budget.' ?></div>
                    </div>
                    <?php if ($isPending): ?>
                        <form method="post" action="validation_demandes_finances.php" enctype="multipart/form-data" class="mt-4 space-y-4" id="febDecisionForm">
                            <input type="hidden" name="feb_workflow_csrf" value="<?= htmlspecialchars((string)$_SESSION['feb_workflow_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="request_id" value="<?= (int) ($view['id'] ?? 0) ?>">
                            <input type="hidden" name="tab" value="<?= htmlspecialchars($tab, ENT_QUOTES, 'UTF-8') ?>">
                            <?php if ($includeLegacyFeb): ?>
                                <input type="hidden" name="archives" value="1">
                            <?php endif; ?>

                            <div class="feb-section">
                                <div class="feb-section-title">Ajustements avant décision</div>
                                <div class="mt-4 grid gap-3">
                                    <label class="block">
                                        <span class="mb-1 block text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Description</span>
                                        <textarea name="description" class="feb-textarea"><?= htmlspecialchars((string) ($payload['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                                    </label>
                                    <div class="grid gap-3 md:grid-cols-2">
                                        <label class="block">
                                            <span class="mb-1 block text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Montant estimé</span>
                                            <input type="number" step="0.01" min="0" name="montant" value="<?= htmlspecialchars(febDetailMoneyInput($payload['montant'] ?? 0), ENT_QUOTES, 'UTF-8') ?>" class="feb-input">
                                        </label>
                                        <label class="block">
                                            <span class="mb-1 block text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Date de livraison</span>
                                            <input type="date" name="date_livraison" value="<?= htmlspecialchars((string) ($payload['date_livraison'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" class="feb-input">
                                        </label>
                                    </div>
                                    <label class="block">
                                        <span class="mb-1 block text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Fréquence</span>
                                        <input type="text" name="frequence" value="<?= htmlspecialchars((string) ($payload['frequence'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" class="feb-input">
                                    </label>
                                </div>
                            </div>

                            <?php if ($besoins): ?>
                                <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white">
                                    <table class="min-w-full text-left text-xs">
                                        <thead class="bg-slate-50 text-slate-500">
                                            <tr>
                                                <th class="px-3 py-3">Catégorie</th>
                                                <th class="px-3 py-3">Besoin</th>
                                                <th class="px-3 py-3">Type</th>
                                                <th class="px-3 py-3">Qté</th>
                                                <th class="px-3 py-3">Fournisseur</th>
                                                <th class="px-3 py-3">PU</th>
                                                <th class="px-3 py-3">Téléphone</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($besoins as $idx => $besoin): ?>
                                                <?php $fournisseur = !empty($besoin['fournisseur']) && is_array($besoin['fournisseur']) ? (array) $besoin['fournisseur'] : []; ?>
                                                <tr class="border-t border-slate-100">
                                                    <td class="px-3 py-2"><select name="besoins[<?= (int) $idx ?>][categorie]" class="feb-input min-w-[130px]" required><?php foreach(FebCategoryMigrationService::CATEGORIES as $category):?><option value="<?=htmlspecialchars($category)?>" <?=$category===FebCategoryMigrationService::normalize((string)($besoin['categorie']??''))?'selected':''?>><?=htmlspecialchars($category)?></option><?php endforeach;?></select></td>
                                                    <td class="px-3 py-2"><input name="besoins[<?= (int) $idx ?>][libelle]" value="<?= htmlspecialchars((string) ($besoin['libelle'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" class="feb-input min-w-[220px]"></td>
                                                    <td class="px-3 py-2"><input name="besoins[<?= (int) $idx ?>][type]" value="<?= htmlspecialchars((string) ($besoin['type'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" class="feb-input min-w-[140px]"></td>
                                                    <td class="px-3 py-2"><input type="number" step="0.01" min="0" name="besoins[<?= (int) $idx ?>][quantite]" value="<?= htmlspecialchars((string) ($besoin['quantite'] ?? 0), ENT_QUOTES, 'UTF-8') ?>" class="feb-input min-w-[90px]"></td>
                                                    <td class="px-3 py-2"><input name="besoins[<?= (int) $idx ?>][fournisseur_nom]" value="<?= htmlspecialchars((string) ($fournisseur['nom'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" class="feb-input min-w-[180px]"></td>
                                                    <td class="px-3 py-2"><input type="number" step="0.01" min="0" name="besoins[<?= (int) $idx ?>][prix_unitaire]" value="<?= htmlspecialchars(febDetailMoneyInput($fournisseur['prix_unitaire'] ?? 0), ENT_QUOTES, 'UTF-8') ?>" class="feb-input min-w-[120px]"></td>
                                                    <td class="px-3 py-2"><input name="besoins[<?= (int) $idx ?>][telephone]" value="<?= htmlspecialchars((string) ($fournisseur['telephone'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" class="feb-input min-w-[140px]"></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>

                            <label class="block">
                                <span class="mb-1 block text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Note de décision</span>
                                <textarea name="note" class="feb-textarea" placeholder="Précisez l’orientation comptable, les éléments attendus ou le motif de la décision."></textarea>
                            </label>

                            <div class="feb-section <?= $budgetPlanned === 'yes' ? 'border-emerald-200 bg-emerald-50/70' : 'border-amber-200 bg-amber-50/80' ?>">
                                <?php if (in_array($budgetPlanned, ['yes', 'no'], true)): ?>
                                <?php
                                ?>
                                <div class="mb-4">
                                    <button type="button" id="decaissementAccordionTrigger" class="feb-accordion-trigger <?= $hasProvisionalDecaissement ? 'is-open' : 'is-closed' ?>" aria-expanded="<?= $hasProvisionalDecaissement ? 'true' : 'false' ?>" aria-controls="decaissementPanel">
                                    <span class="flex flex-wrap items-center justify-between gap-3">
                                        <div class="flex min-w-0 items-start gap-3">
                                            <span class="mt-1 inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-indigo-100 text-indigo-700" id="decaissementAccordionMainIcon"><i class="fa-solid fa-file-circle-plus"></i></span>
                                            <span class="min-w-0">
                                            <div class="feb-section-title"><?= $budgetPlanned === 'no' ? 'Intégrer le déboursé puis générer la fiche' : 'Générer aussi la fiche de décaissement' ?></div>
                                            <div class="mt-1 text-sm text-slate-600"><?= $budgetPlanned === 'no' ? 'Le déboursé et sa ligne seront créés sur le devis du chantier au moment exact de l’approbation, puis la fiche utilisera ce nouveau budget.' : 'Si ce bloc est renseigné, tous les champs deviennent obligatoires et seule l’approbation reste possible.' ?></div>
                                            </span>
                                        </div>
                                        <span id="decaissementAccordionState" class="feb-accordion-badge"><i class="fa-solid fa-chevron-down"></i><span>Ouvrir</span></span>
                                    </span>
                                    </button>
                                    <input type="checkbox" name="generate_decaissement" id="generateDecaissement" value="1" class="hidden" <?= $hasProvisionalDecaissement ? 'checked' : '' ?>>

                                    <div id="decaissementPanel" class="feb-auto-panel mt-4 grid gap-4" <?= $hasProvisionalDecaissement ? '' : 'hidden' ?>>
                                        <?php if ($budgetPlanned === 'no'): ?>
                                        <?php $integrationLines=(array)($proposedDebourse['lines']??[]);$selectedTargetDevis=(int)($proposedDebourse['devis_id']??$febTargetDevisOptions[0]['id']??0);$selectedLineMode=(string)($proposedDebourse['ligne_devis_mode']??(!empty($proposedDebourse['ligne_devis_id'])?'existing':'new'));$selectedDebourseMode=(string)($proposedDebourse['debourse_mode']??'new'); ?>
                                        <input type="hidden" name="feb_integration_config" value="1">
                                        <div class="rounded-3xl border border-amber-200 bg-white p-5 shadow-sm">
                                            <div class="flex flex-wrap items-start justify-between gap-3"><div><div class="text-xs font-black uppercase tracking-[.16em] text-amber-700">Assistant d’intégration budgétaire</div><div class="mt-1 text-xl font-black text-slate-950">Choisissez précisément où intégrer cette FEB</div><p class="mt-1 text-sm text-slate-600">Les montants et libellés ci-dessous remplacent la proposition initiale après validation.</p></div><span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-black text-amber-800">FEB incluse dans le budget</span></div>
                                            <div class="mt-5 grid gap-4 lg:grid-cols-4">
                                                <label class="block"><span class="mb-1 block text-xs font-black uppercase tracking-wide text-slate-500">1. Chantier réel</span><select name="feb_target_chantier_id" id="febTargetChantier" class="feb-input" required><option value="">Rechercher et sélectionner</option><?php foreach($febChantierOptions as $ch):?><?php $chId=(int)$ch['id'];$block=$urgentBlockedChantierMap[$chId]??null;$own=$block&&(int)($block['id']??0)===$viewUrgentRegularizationId; ?><option value="<?=$chId?>" <?= $draftChantierId===(string)$chId?'selected':'' ?> <?= $block&&!$own?'disabled':'' ?>><?=htmlspecialchars(trim((string)(($ch['code']??'').' — '.($ch['titre']??'')), ' —'), ENT_QUOTES, 'UTF-8').($block&&!$own?' — BLOQUÉ : urgence non régularisée':'')?></option><?php endforeach;?></select><span class="mt-1 block text-xs <?= $urgentBlockedChantierMap?'font-bold text-rose-700':'text-slate-500' ?>"><?= $urgentBlockedChantierMap?'Les chantiers grisés sont indisponibles jusqu’à la régularisation complète de leur urgence.':'Obligatoire pour les anciennes FEB sans chantier lié.' ?></span></label>
                                                <label class="block"><span class="mb-1 block text-xs font-black uppercase tracking-wide text-slate-500">2. Devis principal ou TS</span><select name="feb_target_devis_id" id="febTargetDevis" class="feb-input" data-selected="<?=$selectedTargetDevis?>" required><option value="">Sélectionnez d’abord le chantier</option><?php foreach($febTargetDevisOptions as $d):?><option value="<?=(int)$d['id']?>" data-chantier-id="<?=(int)$d['chantier_id']?>" <?= $selectedTargetDevis===(int)$d['id']?'selected':'' ?>><?=htmlspecialchars(((string)$d['devis_type']==='PRINCIPAL'?'Principal':((string)$d['devis_type']==='TS'?'TS':'Non classé')).' — '.((string)($d['numero_devis']??'')?:('#'.(int)$d['id'])))?></option><?php endforeach;?></select></label>
                                                <div><span class="mb-2 block text-xs font-black uppercase tracking-wide text-slate-500">2. Ligne de devis</span><div class="flex gap-3 text-sm font-bold"><label><input type="radio" name="feb_ligne_devis_mode" value="existing" <?=$selectedLineMode==='existing'?'checked':''?>> Choisir</label><label><input type="radio" name="feb_ligne_devis_mode" value="new" <?=$selectedLineMode!=='existing'?'checked':''?>> Créer</label></div><select name="feb_target_ligne_devis_id" id="febTargetLigneDevis" class="feb-input mt-2" data-selected="<?=(int)($proposedDebourse['ligne_devis_id']??0)?>"></select><input name="feb_new_ligne_devis_label" id="febNewLigneLabel" class="feb-input mt-2" value="<?=htmlspecialchars((string)($proposedDebourse['ligne_devis_proposal']??$proposedDebourse['title']??''))?>" placeholder="Libellé de la nouvelle ligne"></div>
                                                <div><span class="mb-2 block text-xs font-black uppercase tracking-wide text-slate-500">3. Déboursé</span><div class="flex gap-3 text-sm font-bold"><label><input type="radio" name="feb_debourse_mode" value="existing" <?=$selectedDebourseMode==='existing'?'checked':''?>> Choisir</label><label><input type="radio" name="feb_debourse_mode" value="new" <?=$selectedDebourseMode!=='existing'?'checked':''?>> Créer</label></div><select name="feb_target_debourse_id" id="febTargetDebourse" class="feb-input mt-2" data-selected="<?=(int)($proposedDebourse['existing_debourse_id']??0)?>"></select><input name="feb_debourse_title" id="febDebourseTitle" class="feb-input mt-2" value="<?=htmlspecialchars((string)($proposedDebourse['title']??''))?>" placeholder="Titre du nouveau déboursé"></div>
                                            </div>
                                        <div class="mt-5 overflow-x-auto"><table class="w-full min-w-[650px] text-sm"><thead><tr class="border-b text-left text-xs uppercase tracking-wide text-slate-500"><th class="p-2">Demande / ligne</th><th class="p-2">Catégorie</th><th class="p-2">Montant</th><th></th></tr></thead><tbody id="febIntegrationLines"><?php foreach($integrationLines as $i=>$line):?><tr class="border-b border-slate-100"><td class="p-2"><input class="feb-input" name="feb_debourse_lines[<?=$i?>][designation]" value="<?=htmlspecialchars((string)($line['designation']??''))?>" required></td><td class="p-2"><select class="feb-input" name="feb_debourse_lines[<?=$i?>][categorie]" required><?php foreach(FebCategoryMigrationService::CATEGORIES as $category):?><option value="<?=htmlspecialchars($category)?>" <?=$category===FebCategoryMigrationService::normalize((string)($line['categorie']??''))?'selected':''?>><?=htmlspecialchars($category)?></option><?php endforeach;?></select></td><td class="p-2"><input type="number" min="1" step=".01" class="feb-input feb-integration-amount" name="feb_debourse_lines[<?=$i?>][montant]" value="<?=htmlspecialchars((string)($line['montant']??0))?>" required></td><td class="p-2"><button type="button" class="feb-remove-integration-line rounded-lg bg-rose-50 px-3 py-2 font-bold text-rose-700">×</button></td></tr><?php endforeach;?></tbody></table></div>
                                            <div class="mt-4 flex flex-wrap items-center justify-between gap-3"><button type="button" id="febAddIntegrationLine" class="rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-2 text-sm font-black text-indigo-700">+ Ajouter une ligne</button><div class="text-right"><div class="text-xs font-bold uppercase text-slate-500">Total à intégrer</div><div id="febIntegrationTotal" class="text-2xl font-black text-indigo-800">0 FCFA</div></div></div>
                                        </div>
                                        <?php endif; ?>
                                        <div class="grid gap-3 lg:grid-cols-2">
                                            <div class="feb-mini-card">
                                                <div class="text-base font-black text-slate-950">Bénéficiaire final</div>
                                                <div class="mt-1 text-xs leading-5 text-slate-500">Personne qui recevra effectivement le paiement.</div>
                                                <div class="mt-3 flex flex-wrap gap-3">
                                                    <label class="inline-flex items-center gap-2 text-sm font-semibold text-slate-700"><input type="radio" name="fd_beneficiary_mode" value="personnel" <?= $draftBeneficiaryMode === 'personnel' ? 'checked' : '' ?>> Personnel</label>
                                                    <label class="inline-flex items-center gap-2 text-sm font-semibold text-slate-700"><input type="radio" name="fd_beneficiary_mode" value="supplier" <?= $draftBeneficiaryMode === 'supplier' ? 'checked' : '' ?>> Fournisseur</label>
                                                    <label class="inline-flex items-center gap-2 text-sm font-semibold text-slate-700"><input type="radio" name="fd_beneficiary_mode" value="external" <?= $draftBeneficiaryMode === 'external' ? 'checked' : '' ?>> Externe</label>
                                                </div>
                                                <div id="fdPersonnelBlock" class="mt-3" hidden>
                                                    <input type="hidden" name="fd_personnel_id" id="fdPersonnelId" value="<?= htmlspecialchars($draftPersonnelId, ENT_QUOTES, 'UTF-8') ?>">
                                                    <div class="feb-search-wrap">
                                                        <input type="text" id="fdPersonnelSearch" class="feb-input" placeholder="Taper le nom du personnel..." autocomplete="off">
                                                        <div id="fdPersonnelResults" class="feb-search-results" hidden></div>
                                                    </div>
                                                    <div id="fdPersonnelPreview" class="mt-3 flex items-center gap-3 rounded-2xl border border-slate-200 bg-white p-3 text-sm text-slate-600" hidden></div>
                                                </div>
                                                <div id="fdExternalBlock" class="mt-3 grid gap-3" hidden>
                                                    <input type="text" name="fd_beneficiaire_fiche" class="feb-input" placeholder="Nom complet du bénéficiaire">
                                                    <input type="text" name="fd_tel_beneficiaire_fiche" class="feb-input" placeholder="Numéro de téléphone">
                                                    <label class="block text-xs font-semibold text-slate-600">Photo du bénéficiaire<input type="file" name="fd_photo_beneficiaire" accept="image/*" class="mt-1 block w-full text-sm"></label>
                                                    <label class="block text-xs font-semibold text-slate-600">Pièce du bénéficiaire<input type="file" name="fd_cni_beneficiaire" accept=".pdf,image/*" class="mt-1 block w-full text-sm"></label>
                                                </div>
                                            </div>

                                            <div class="feb-mini-card">
                                                <div class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Fournisseur validé</div>
                                                <input type="hidden" name="fd_fournisseur_id" id="fdFournisseurId" value="<?= htmlspecialchars($draftFournisseurId, ENT_QUOTES, 'UTF-8') ?>">
                                                <div class="mt-3 feb-search-wrap">
                                                    <input type="text" id="fdFournisseurSearch" class="feb-input" placeholder="Taper fournisseur, contact, téléphone..." autocomplete="off">
                                                    <div id="fdFournisseurResults" class="feb-search-results" hidden></div>
                                                </div>
                                                <div id="fdFournisseurPreview" class="mt-3 text-sm text-slate-600" hidden></div>
                                            </div>
                                        </div>

                                        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                                            <label class="block"><span class="mb-1 block text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Chantier</span><select name="fd_chantier_id" id="fdChantier" class="feb-input"><option value="">Sélectionner</option><?php foreach ($febChantierOptions as $chantier): ?><?php $chantierId = (int) $chantier['id'];$block=$urgentBlockedChantierMap[$chantierId]??null;$own=$block&&(int)($block['id']??0)===$viewUrgentRegularizationId; ?><option value="<?= $chantierId ?>" data-devis-id="<?= (int) ($chantier['devis_id'] ?? 0) ?>" <?= $draftChantierId === (string) $chantierId ? 'selected' : '' ?> <?= $block&&!$own?'disabled':'' ?>><?= htmlspecialchars(trim((string) (($chantier['code'] ?? '') . ' - ' . ($chantier['titre'] ?? ''))) . ($block&&!$own?' — BLOQUÉ : urgence non régularisée':''), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select><?php if($urgentBlockedChantierMap):?><span class="mt-1 block text-xs font-bold text-rose-700">Blocage actif sur les chantiers ayant une urgence décaissée non régularisée.</span><?php endif;?></label>
                                            <label class="block"><span class="mb-1 block text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Service / section budgétaire</span><select name="fd_ligne_devis_id" id="fdServiceLine" data-selected="<?= htmlspecialchars($draftLigneDevisId, ENT_QUOTES, 'UTF-8') ?>" class="feb-input" <?= $budgetPlanned === 'no' ? 'disabled' : '' ?>><option value=""><?= $budgetPlanned === 'no' ? 'Créée automatiquement à l’approbation' : 'Choisir un chantier d’abord' ?></option></select></label>
                                            <label class="block"><span class="mb-1 block text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Déboursé financier du service</span><select name="fd_debourse_id" id="fdDebourse" data-selected="<?= htmlspecialchars($draftDebourseId, ENT_QUOTES, 'UTF-8') ?>" class="feb-input" <?= $budgetPlanned === 'no' ? 'disabled' : '' ?>><option value=""><?= $budgetPlanned === 'no' ? 'Créé automatiquement à l’approbation' : 'Choisir un service d’abord' ?></option></select></label>
                                            <label class="block"><span class="mb-1 block text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Catégorie / poste financier</span><select name="fd_ligne_debourse_id" id="fdLigne" data-selected="<?= htmlspecialchars($draftLigneId, ENT_QUOTES, 'UTF-8') ?>" class="feb-input" <?= $budgetPlanned === 'no' ? 'disabled' : '' ?>><option value=""><?= $budgetPlanned === 'no' ? 'Créée automatiquement à l’approbation' : 'Choisir un service d’abord' ?></option></select></label>
                                        </div>

                                        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                                            <label class="block"><span class="mb-1 block text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Montant fiche</span><input type="number" step="0.01" min="0" name="fd_montant_fiche" class="feb-input" value="<?= htmlspecialchars(febDetailMoneyInput($draftMontantFiche), ENT_QUOTES, 'UTF-8') ?>"></label>
                                            <label class="block"><span class="mb-1 block text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Mode paiement</span><select name="fd_mode_paiement" class="feb-input"><option value="">Sélectionner</option><?php foreach (['Espèces', 'Mobile Money', 'Virement', 'Chèque'] as $paymentMode): ?><option value="<?= htmlspecialchars($paymentMode, ENT_QUOTES, 'UTF-8') ?>" <?= $draftModePaiement === $paymentMode ? 'selected' : '' ?>><?= htmlspecialchars($paymentMode, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label>
                                            <label class="block"><span class="mb-1 block text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Date prévue décaissement</span><input type="date" name="fd_date_decaissement_minimum" class="feb-input" value="<?= htmlspecialchars((string) ($provisionalDecaissement['date_decaissement_minimum'] ?? $provisionalDecaissement['planned_start_date'] ?? $payload['planned_start_date'] ?? $payload['date_livraison'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></label>
                                            <label class="block"><span class="mb-1 block text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Détails fiche</span><input type="text" name="fd_details_demande" class="feb-input" value="<?= htmlspecialchars($draftDetailsDemande, ENT_QUOTES, 'UTF-8') ?>"></label>
                                        </div>

                                        <div id="fdRuleMessage" class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-800">Remplissez tous les champs du bloc pour activer l’approbation avec création automatique.</div>
                                    </div>
                                </div>

                                <?php endif; ?>

                                <div class="feb-section-title">Contrôle avant approbation</div>
                                <div class="mt-2 text-sm leading-6 text-slate-700">
                                    <?php if ($budgetPlanned === 'yes'): ?>
                                        Vérifiez le rattachement chantier → ligne de devis → déboursé → ligne de déboursé, le disponible budgétaire, le fournisseur, le montant et le planning avant approbation.
                                    <?php else: ?>
                                        Analysez la cohérence de la proposition de déboursé, son total et le planning de chaque ligne. Retournez la FEB pour complément si la proposition ne peut pas être intégrée au budget.
                                    <?php endif; ?>
                                </div>
                                <label class="mt-4 flex cursor-pointer items-start gap-3 rounded-2xl border border-violet-200 bg-violet-50 p-4 text-sm text-violet-950">
                                    <input type="checkbox" name="retain_initial_prices" value="1" class="mt-1 h-5 w-5 rounded border-violet-300 text-violet-700">
                                    <span>
                                        <strong class="block">Approuver sans proposition contradictoire</strong>
                                        <span class="mt-1 block leading-6">Les prix et fournisseurs proposés initialement par le demandeur seront retenus. Les consultations contradictoires ouvertes seront annulées et les propositions déjà reçues seront classées non retenues.</span>
                                    </span>
                                </label>
                            </div>

                            <div class="grid gap-2 sm:grid-cols-2">
                                <button type="submit" name="action" value="request_more_details" class="feb-action-btn border border-sky-200 bg-sky-50 text-sky-800 hover:bg-sky-100">Demander des précisions</button>
                                <button type="submit" name="action" value="report" class="feb-action-btn border border-amber-200 bg-amber-50 text-amber-800 hover:bg-amber-100">Retourner pour complément</button>
                                <button type="submit" name="action" value="reject" class="feb-action-btn border border-rose-200 bg-rose-50 text-rose-800 hover:bg-rose-100">Refuser</button>
                                <button type="submit" name="action" value="approve" class="feb-action-btn bg-emerald-600 text-white hover:bg-emerald-700"><i class="fa-solid fa-check mr-2"></i><span class="js-approve-label">Approuver</span></button>
                            </div>
                        </form>
                    <?php else: ?>
                        <div class="mt-4 feb-section">
                            <div class="feb-section-title">Statut actuel</div>
                            <div class="mt-2 text-sm text-slate-600">Cette FEB n’est plus en attente de validation. Son statut actuel est <span class="font-semibold text-slate-900"><?= htmlspecialchars((string) $badge['label'], ENT_QUOTES, 'UTF-8') ?></span>.</div>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="feb-panel feb-journal-panel p-6">
                    <div class="feb-kicker">Journal</div>
                    <div class="mt-4 space-y-4">
                        <?php if (!$events): ?>
                            <div class="text-sm text-slate-500">Aucun évènement enregistré.</div>
                        <?php else: ?>
                            <?php foreach ($events as $event): ?>
                                <article class="feb-timeline-item">
                                    <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4">
                                        <div class="flex flex-wrap items-start justify-between gap-2">
                                            <div class="font-semibold text-slate-900"><?= htmlspecialchars(febDetailEventLabel((string) ($event['event_type'] ?? '')), ENT_QUOTES, 'UTF-8') ?></div>
                                            <div class="text-xs text-slate-500"><?= htmlspecialchars((string) ($event['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                        </div>
                                        <?php if (!empty($event['event_note'])): ?>
                                            <div class="mt-2 whitespace-pre-line text-sm leading-6 text-slate-700"><?= htmlspecialchars((string) $event['event_note'], ENT_QUOTES, 'UTF-8') ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($event['event_by_name'])): ?>
                                            <div class="mt-2 text-xs font-medium text-slate-500">Par: <?= htmlspecialchars((string) $event['event_by_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                        <?php endif; ?>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </section>
            </aside>
        </div>
    </div>
</div>

<script>
    window.febAutoDecaissementData = {
        personnel: <?= json_encode(array_map(static function (array $p): array {
                        return [
                            'id' => (int) ($p['id'] ?? 0),
                            'nom' => (string) ($p['nom'] ?? ''),
                            'matricule' => (string) ($p['matricule'] ?? ''),
                            'telephone' => (string) ($p['telephone'] ?? ''),
                            'photo' => febDetailResolveMediaUrl((string) ($p['photo'] ?? '')),
                            'cni' => febDetailResolveMediaUrl((string) ($p['cni'] ?? '')),
                            'service' => (string) ($p['service'] ?? ''),
                            'fonction' => (string) ($p['fonction'] ?? ''),
                        ];
                    }, $febPersonnelOptions), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        debourses: <?= json_encode(array_map(static function (array $d): array {
                        return [
                            'id' => (int) ($d['id'] ?? 0),
                            'devis_id' => (int) ($d['devis_id'] ?? 0),
                            'ligne_devis_id' => (int) ($d['ligne_devis_id'] ?? 0),
                            'label' => (string) ($d['label'] ?? ''),
                            'montant' => (float) ($d['montant_debourse'] ?? 0),
                        ];
                    }, $febDebourseOptions), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        services: <?= json_encode(array_map(static fn(array $l):array=>[
                        'id'=>(int)($l['id']??0),
                        'devis_id'=>(int)($l['devis_id']??0),
                        'designation'=>(string)($l['designation']??''),
                        'total'=>(float)($l['total']??0),
                    ],$febFinancialServiceOptions),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>,
        lignesDevis: <?= json_encode(array_map(static fn(array $l): array => ['id'=>(int)($l['id']??0),'devis_id'=>(int)($l['devis_id']??0),'designation'=>(string)($l['designation']??''),'total'=>(float)($l['total']??0)], $febTargetLigneDevisOptions), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        devisTargets: <?= json_encode(array_map(static fn(array $d): array => ['chantier_id'=>(int)($d['chantier_id']??0),'id'=>(int)($d['id']??0),'numero'=>(string)($d['numero_devis']??''),'type'=>(string)($d['devis_type']??'UNCLASSIFIED')], $febTargetDevisOptions), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        lignes: <?= json_encode(array_map(static function (array $l): array {
                        return [
                            'id' => (int) ($l['id'] ?? 0),
                            'debourse_id' => (int) ($l['debourse_id'] ?? 0),
                            'devis_id' => (int) ($l['devis_id'] ?? 0),
                            'ligne_devis_id' => (int) ($l['ligne_devis_id'] ?? 0),
                            'debourse_label' => (string) ($l['debourse_label'] ?? ''),
                            'designation' => (string) ($l['designation'] ?? ''),
                            'categorie' => (string) ($l['categorie'] ?? ''),
                            'montant' => (float) ($l['montant'] ?? 0),
                        ];
                    }, $febLigneOptions), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        fournisseurs: <?= json_encode(array_map(static function (array $f): array {
                            return [
                                'id' => (int) ($f['id'] ?? 0),
                                'nom' => (string) ($f['nom'] ?? ''),
                                'contact' => (string) ($f['contact_nom'] ?? ''),
                                'telephone' => (string) ($f['telephone'] ?? ''),
                                'email' => (string) ($f['email'] ?? ''),
                                'type' => (string) ($f['supplier_type'] ?? ''),
                            ];
                        }, $febFournisseurOptions), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
    };
    window.febAutoDecaissementPrefill = {
        personnel_id: <?= json_encode($draftPersonnelId, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        fournisseur_id: <?= json_encode($draftFournisseurId, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        ligne_devis_id: <?= json_encode($draftLigneDevisId, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        debourse_id: <?= json_encode($draftDebourseId, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        ligne_debourse_id: <?= json_encode($draftLigneId, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
    };

    document.addEventListener('DOMContentLoaded', function() {
        const data = window.febAutoDecaissementData || {};
        const prefill = window.febAutoDecaissementPrefill || {};
        const form = document.getElementById('febDecisionForm');
        const toggle = document.getElementById('generateDecaissement');
        const accordionTrigger = document.getElementById('decaissementAccordionTrigger');
        const accordionState = document.getElementById('decaissementAccordionState');
        const accordionMainIcon = document.getElementById('decaissementAccordionMainIcon');
        const panel = document.getElementById('decaissementPanel');
        const rule = document.getElementById('fdRuleMessage');
        const approveBtn = form ? form.querySelector('button[value="approve"]') : null;
        const otherBtns = form ? Array.from(form.querySelectorAll('button[name="action"]')).filter(btn => btn.value !== 'approve') : [];
        const beneficiaryRadios = Array.from(document.querySelectorAll('input[name="fd_beneficiary_mode"]'));
        const personnelBlock = document.getElementById('fdPersonnelBlock');
        const externalBlock = document.getElementById('fdExternalBlock');
        const personnelId = document.getElementById('fdPersonnelId');
        const personnelSearch = document.getElementById('fdPersonnelSearch');
        const personnelResults = document.getElementById('fdPersonnelResults');
        const personnelPreview = document.getElementById('fdPersonnelPreview');
        const supplierId = document.getElementById('fdFournisseurId');
        const supplierSearch = document.getElementById('fdFournisseurSearch');
        const supplierResults = document.getElementById('fdFournisseurResults');
        const supplierPreview = document.getElementById('fdFournisseurPreview');
        const chantier = document.getElementById('fdChantier');
        const serviceLine = document.getElementById('fdServiceLine');
        const debourse = document.getElementById('fdDebourse');
        const ligne = document.getElementById('fdLigne');
        const modePaiement = form ? form.querySelector('[name="fd_mode_paiement"]') : null;
        const enhancedSelects = new Map();
        const integrationDevis = document.getElementById('febTargetDevis');
        const integrationChantier = document.getElementById('febTargetChantier');
        const integrationLineSelect = document.getElementById('febTargetLigneDevis');
        const integrationNewLine = document.getElementById('febNewLigneLabel');
        const integrationDebourseSelect = document.getElementById('febTargetDebourse');
        const integrationDebourseTitle = document.getElementById('febDebourseTitle');
        const integrationRows = document.getElementById('febIntegrationLines');
        const integrationTotal = document.getElementById('febIntegrationTotal');
        let personnelSearchTimer = null;
        let personnelSearchToken = 0;
        let selectedPersonnel = null;

        const normalize = value => String(value || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        const money = value => Number(value || 0).toLocaleString('fr-FR') + ' FCFA';
        const escapeHtml = value => String(value || '').replace(/[&<>"']/g, char => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        }[char]));
        const optionLabel = option => option ? String(option.textContent || '').trim() : '';
        function integrationModes() {
            if (!integrationDevis) return;
            const lineMode = form.querySelector('[name="feb_ligne_devis_mode"]:checked')?.value || 'new';
            const debourseMode = form.querySelector('[name="feb_debourse_mode"]:checked')?.value || 'new';
            integrationLineSelect.hidden = lineMode !== 'existing';
            integrationLineSelect.disabled = lineMode !== 'existing';
            integrationNewLine.hidden = lineMode === 'existing';
            integrationNewLine.disabled = lineMode === 'existing';
            integrationDebourseSelect.hidden = debourseMode !== 'existing';
            integrationDebourseSelect.disabled = debourseMode !== 'existing';
            integrationDebourseTitle.hidden = debourseMode === 'existing';
            integrationDebourseTitle.disabled = debourseMode === 'existing';
            const devisId = Number(integrationDevis.value || 0);
            const selectedLine = Number(integrationLineSelect.dataset.selected || integrationLineSelect.value || 0);
            integrationLineSelect.innerHTML = '<option value="">Choisir une ligne existante</option>' + (data.lignesDevis || []).filter(row => Number(row.devis_id) === devisId).map(row => `<option value="${row.id}" ${Number(row.id)===selectedLine?'selected':''}>${escapeHtml(row.designation || ('Ligne #'+row.id))} — ${money(row.total)}</option>`).join('');
            const lineId = lineMode === 'existing' ? Number(integrationLineSelect.value || 0) : 0;
            const selectedDebourse = Number(integrationDebourseSelect.dataset.selected || integrationDebourseSelect.value || 0);
            integrationDebourseSelect.innerHTML = '<option value="">Choisir un déboursé existant</option>' + (data.debourses || []).filter(row => Number(row.devis_id) === devisId && (!lineId || Number(row.ligne_devis_id) === lineId)).map(row => `<option value="${row.id}" ${Number(row.id)===selectedDebourse?'selected':''}>${escapeHtml(row.label)} — ${money(row.montant)}</option>`).join('');
            integrationLineSelect.dataset.selected = '';
            integrationDebourseSelect.dataset.selected = '';
        }
        function refreshIntegrationDevis() {
            if (!integrationDevis || !integrationChantier) return;
            const chantierId = Number(integrationChantier.value || 0);
            const selected = Number(integrationDevis.dataset.selected || integrationDevis.value || 0);
            const targets = (data.devisTargets || []).filter(row => Number(row.chantier_id) === chantierId);
            integrationDevis.innerHTML = '<option value="">' + (chantierId ? 'Choisir le devis du projet' : 'Sélectionnez d’abord le chantier') + '</option>' + targets.map(row => `<option value="${row.id}" ${Number(row.id)===selected?'selected':''}>${row.type==='PRINCIPAL'?'Principal':(row.type==='TS'?'TS':'Non classé')} — ${escapeHtml(row.numero || ('#'+row.id))}</option>`).join('');
            integrationDevis.dataset.selected = '';
            if (chantier && chantierId) chantier.value = String(chantierId);
            integrationModes();
        }
        function updateIntegrationTotal() {
            if (!integrationTotal) return;
            const total = [...integrationRows.querySelectorAll('.feb-integration-amount')].reduce((sum,input)=>sum+(Number(input.value)||0),0);
            integrationTotal.textContent = money(total);
        }
        function addIntegrationLine() {
            const index = 'new_' + Date.now();
            if (!integrationRows) return;
            const row = document.createElement('tr'); row.className='border-b border-slate-100';
            row.innerHTML=`<td class="p-2"><input class="feb-input" name="feb_debourse_lines[${index}][designation]" required></td><td class="p-2"><select class="feb-input" name="feb_debourse_lines[${index}][categorie]" required><option>RH</option><option>Logistique</option><option>HSE</option><option>ACHAT</option><option>Bureau</option></select></td><td class="p-2"><input type="number" min="1" step=".01" class="feb-input feb-integration-amount" name="feb_debourse_lines[${index}][montant]" required></td><td class="p-2"><button type="button" class="feb-remove-integration-line rounded-lg bg-rose-50 px-3 py-2 font-bold text-rose-700">×</button></td>`;
            integrationRows.appendChild(row);
        }
        if (integrationDevis) {
            integrationChantier?.addEventListener('change', refreshIntegrationDevis);
            integrationDevis.addEventListener('change', integrationModes);
            form.querySelectorAll('[name="feb_ligne_devis_mode"],[name="feb_debourse_mode"]').forEach(el=>el.addEventListener('change',integrationModes));
            integrationLineSelect.addEventListener('change', integrationModes);
            integrationRows.addEventListener('input', updateIntegrationTotal);
            integrationRows.addEventListener('click', event=>{const button=event.target.closest('.feb-remove-integration-line');if(!button)return;if(integrationRows.querySelectorAll('tr').length<=1){alert('Le déboursé doit conserver au moins une ligne.');return;}button.closest('tr').remove();updateIntegrationTotal();});
            document.getElementById('febAddIntegrationLine')?.addEventListener('click',addIntegrationLine);
            refreshIntegrationDevis(); updateIntegrationTotal();
        }
        function normalizePersonnelRow(p) {
            return {
                id: Number(p && p.id ? p.id : 0),
                nom: String((p && (p.nom || p.full_name)) || ''),
                matricule: String((p && p.matricule) || ''),
                telephone: String((p && (p.telephone || p.phone)) || ''),
                photo: String((p && (p.photo || p.photo_url)) || ''),
                cni: String((p && (p.cni || p.cni_url)) || ''),
                service: String((p && p.service) || ''),
                fonction: String((p && p.fonction) || '')
            };
        }
        const personnelLabel = p => {
            const row = normalizePersonnelRow(p);
            return [row.nom, row.matricule, row.service, row.fonction].filter(value => String(value || '').trim() !== '').join(' - ');
        };

        function renderResults(input, box, rows, labelFn, selectFn) {
            const q = normalize(input.value);
            const filtered = rows.filter(row => normalize(labelFn(row)).includes(q)).slice(0, 30);
            box.innerHTML = '';
            if (!filtered.length) {
                const empty = document.createElement('div');
                empty.className = 'px-3 py-3 text-sm text-slate-500';
                empty.textContent = 'Aucun résultat';
                box.appendChild(empty);
            } else {
                filtered.forEach(row => {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'feb-search-option';
                    btn.textContent = labelFn(row);
                    btn.addEventListener('click', () => {
                        input.value = labelFn(row);
                        box.hidden = true;
                        selectFn(row);
                        updateDecisionState();
                    });
                    box.appendChild(btn);
                });
            }
            box.hidden = false;
        }

        function enhanceSelectSearch(select, placeholder) {
            if (!select || enhancedSelects.has(select)) return;
            select.classList.add('feb-native-select-hidden');

            const wrap = document.createElement('div');
            wrap.className = 'feb-search-wrap mt-1';
            const input = document.createElement('input');
            input.type = 'text';
            input.className = 'feb-input';
            input.placeholder = placeholder || 'Rechercher...';
            input.autocomplete = 'off';
            const results = document.createElement('div');
            results.className = 'feb-search-results';
            results.hidden = true;

            select.insertAdjacentElement('afterend', wrap);
            wrap.appendChild(input);
            wrap.appendChild(results);

            const syncInput = () => {
                input.value = select.value ? optionLabel(select.selectedOptions[0]) : '';
            };
            const render = () => {
                const q = normalize(input.value);
                const options = Array.from(select.options).filter(option => option.value !== '');
                const filtered = options.filter(option => normalize(optionLabel(option)).includes(q)).slice(0, 40);
                results.innerHTML = '';
                if (!filtered.length) {
                    const empty = document.createElement('div');
                    empty.className = 'px-3 py-3 text-sm text-slate-500';
                    empty.textContent = 'Aucun résultat';
                    results.appendChild(empty);
                } else {
                    filtered.forEach(option => {
                        const btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'feb-search-option';
                        btn.textContent = optionLabel(option);
                        btn.addEventListener('click', () => {
                            select.value = option.value;
                            syncInput();
                            results.hidden = true;
                            select.dispatchEvent(new Event('change', { bubbles: true }));
                            updateDecisionState();
                        });
                        results.appendChild(btn);
                    });
                }
                results.hidden = false;
            };

            input.addEventListener('focus', render);
            input.addEventListener('input', () => {
                select.value = '';
                if (select === chantier) {
                    serviceLine.value = '';
                    debourse.value = '';
                    ligne.value = '';
                    refreshSelectSearch(serviceLine);
                    refreshSelectSearch(debourse);
                    refreshSelectSearch(ligne);
                } else if (select === serviceLine) {
                    debourse.value = '';
                    ligne.value = '';
                    refreshSelectSearch(debourse);
                    refreshSelectSearch(ligne);
                } else if (select === debourse) {
                    ligne.value = '';
                    refreshSelectSearch(ligne);
                }
                render();
                updateDecisionState();
            });
            select.addEventListener('change', syncInput);
            enhancedSelects.set(select, { input, results, render, syncInput });
            syncInput();
        }

        function refreshSelectSearch(select) {
            const enhanced = enhancedSelects.get(select);
            if (enhanced) enhanced.syncInput();
        }

        function selectedMode() {
            return (beneficiaryRadios.find(r => r.checked) || {}).value || '';
        }

        function updateBeneficiaryMode() {
            if (!beneficiaryRadios.some(radio => radio.checked)) {
                const defaultRadio = beneficiaryRadios.find(radio => radio.value === 'personnel') || beneficiaryRadios[0];
                if (defaultRadio) defaultRadio.checked = true;
            }
            const mode = selectedMode();
            if (personnelBlock) personnelBlock.hidden = mode !== 'personnel';
            if (externalBlock) externalBlock.hidden = mode !== 'external';
            if (mode !== 'personnel') {
                personnelId.value = '';
                selectedPersonnel = null;
                if (personnelSearch) personnelSearch.value = '';
                if (personnelPreview) personnelPreview.hidden = true;
            }
            updateDecisionState();
        }

        beneficiaryRadios.forEach(radio => radio.addEventListener('change', updateBeneficiaryMode));

        function renderPersonnelSearchRows(rows) {
            const normalizedRows = (rows || []).map(normalizePersonnelRow).filter(row => row.id > 0 && row.nom !== '');
            renderResults(personnelSearch, personnelResults, normalizedRows, personnelLabel, selectPersonnel);
        }

        function localPersonnelRows(term) {
            const q = normalize(term);
            return (data.personnel || []).map(normalizePersonnelRow).filter(row => normalize(personnelLabel(row)).includes(q)).slice(0, 30);
        }

        function fetchPersonnelRows(term) {
            const q = String(term || '').trim();
            if (!personnelSearch || !personnelResults || q.length < 2) {
                personnelResults && (personnelResults.hidden = true);
                return;
            }
            const token = ++personnelSearchToken;
            fetch('decaissement/request/search_personnel.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'q=' + encodeURIComponent(q)
            })
                .then(response => response.ok ? response.json() : Promise.reject(new Error('Erreur réseau')))
                .then(payload => {
                    if (token !== personnelSearchToken) return;
                    const remoteRows = payload && payload.success && Array.isArray(payload.results) ? payload.results : [];
                    renderPersonnelSearchRows(remoteRows.length ? remoteRows : localPersonnelRows(q));
                })
                .catch(() => {
                    if (token !== personnelSearchToken) return;
                    renderPersonnelSearchRows(localPersonnelRows(q));
                });
        }

        function fetchPersonnelDetailsForFeb(personnelIdValue) {
            const id = Number(personnelIdValue || 0);
            if (!id) return;
            fetch('decaissement/request/get_personnel_details.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'personnel_id=' + encodeURIComponent(String(id))
            })
                .then(response => response.ok ? response.json() : Promise.reject(new Error('Erreur réseau')))
                .then(payload => {
                    const details = payload && payload.success ? normalizePersonnelRow(payload.personnel || {}) : null;
                    if (!details || !selectedPersonnel || Number(selectedPersonnel.id || 0) !== id) return;
                    selectedPersonnel = {
                        ...selectedPersonnel,
                        nom: details.nom || selectedPersonnel.nom,
                        telephone: details.telephone || selectedPersonnel.telephone,
                        photo: details.photo || selectedPersonnel.photo,
                        cni: details.cni || selectedPersonnel.cni
                    };
                    renderSelectedPersonnelPreview(selectedPersonnel);
                    updateDecisionState();
                })
                .catch(() => {});
        }

        if (personnelSearch && personnelResults) {
            personnelSearch.addEventListener('focus', () => {
                const term = String(personnelSearch.value || '').trim();
                if (term.length >= 2) {
                    fetchPersonnelRows(term);
                } else {
                    renderPersonnelSearchRows((data.personnel || []).slice(0, 30));
                }
            });
            personnelSearch.addEventListener('input', () => {
                personnelId.value = '';
                selectedPersonnel = null;
                if (personnelPreview) personnelPreview.hidden = true;
                const term = String(personnelSearch.value || '').trim();
                if (personnelSearchTimer) clearTimeout(personnelSearchTimer);
                if (term.length < 2) {
                    personnelResults.hidden = true;
                    updateDecisionState();
                    return;
                }
                personnelSearchTimer = setTimeout(() => fetchPersonnelRows(term), 220);
                updateDecisionState();
            });
        }

        function selectPersonnel(p) {
            p = normalizePersonnelRow(p);
            selectedPersonnel = p || null;
            personnelId.value = p.id || '';
            if (personnelSearch) personnelSearch.value = personnelLabel(p);
            if (!personnelPreview) return;
            personnelPreview.hidden = false;
            renderSelectedPersonnelPreview(p);
            fetchPersonnelDetailsForFeb(p.id);
            updateDecisionState();
        }

        function renderSelectedPersonnelPreview(p) {
            if (!personnelPreview) return;
            const photo = p.photo ? `<img src="${escapeHtml(p.photo)}" class="feb-mini-photo" alt="">` : '<div class="feb-mini-photo flex items-center justify-center bg-indigo-50 text-indigo-700 font-bold">RH</div>';
            const cni = p.cni ? `<a class="font-bold text-indigo-700 hover:text-indigo-900" href="${escapeHtml(p.cni)}" target="_blank" rel="noopener">Voir la pièce</a>` : '<span class="font-bold text-rose-700">Pièce indisponible</span>';
            const service = [p.service, p.fonction].filter(value => String(value || '').trim() !== '').join(' - ');
            personnelPreview.innerHTML = `${photo}<div class="min-w-0"><div class="font-bold text-slate-900">${escapeHtml(p.nom || '-')}</div><div class="text-slate-500">${escapeHtml(p.matricule || 'Matricule non renseigné')}</div>${service ? `<div>${escapeHtml(service)}</div>` : ''}<div>Tél: ${escapeHtml(p.telephone || 'Non renseigné')}</div><div>Photo: ${p.photo ? 'disponible' : '<span class="font-bold text-rose-700">indisponible</span>'}</div><div>Pièce: ${cni}</div></div>`;
        }

        if (supplierSearch && supplierResults) {
            supplierSearch.addEventListener('focus', () => renderResults(supplierSearch, supplierResults, data.fournisseurs || [], supplierLabel, selectSupplier));
            supplierSearch.addEventListener('input', () => {
                supplierId.value = '';
                renderResults(supplierSearch, supplierResults, data.fournisseurs || [], supplierLabel, selectSupplier);
                updateDecisionState();
            });
        }

        function supplierLabel(f) {
            return `${f.nom || ''}${f.contact ? ' - ' + f.contact : ''}${f.telephone ? ' - ' + f.telephone : ''}`;
        }

        function selectSupplier(f) {
            supplierId.value = f.id || '';
            if (!supplierPreview) return;
            supplierPreview.hidden = false;
            supplierPreview.innerHTML = `<div class="rounded-2xl border border-slate-200 bg-white p-3"><div class="font-bold text-slate-900">${f.nom || '-'}</div><div>Contact: ${f.contact || 'Non renseigné'}</div><div>Tél: ${f.telephone || 'Non renseigné'}</div></div>`;
        }

        function fillServices() {
            const selected = chantier && chantier.selectedOptions[0] ? chantier.selectedOptions[0] : null;
            const devisId = selected ? Number(selected.dataset.devisId || 0) : 0;
            const selectedId = Number(serviceLine.dataset.selected || serviceLine.value || 0);
            const rows = (data.services || []).filter(row => Number(row.devis_id || 0) === devisId);
            serviceLine.innerHTML = `<option value="">${devisId ? 'Sélectionner un service' : 'Choisir un chantier d’abord'}</option>` +
                rows.map(row => `<option value="${row.id}" ${Number(row.id) === selectedId ? 'selected' : ''}>${escapeHtml(row.designation || ('Service #' + row.id))} — ${money(row.total)}</option>`).join('');
            if (!serviceLine.value && rows.length === 1) serviceLine.value = String(rows[0].id);
            serviceLine.dataset.selected = '';
            refreshSelectSearch(serviceLine);
            fillDebourses();
        }

        function fillDebourses() {
            const selected = chantier && chantier.selectedOptions[0] ? chantier.selectedOptions[0] : null;
            const devisId = selected ? Number(selected.dataset.devisId || 0) : 0;
            const serviceId = Number(serviceLine.value || 0);
            const selectedId = Number(debourse.dataset.selected || debourse.value || 0);
            const rows = (data.debourses || []).filter(d =>
                Number(d.devis_id || 0) === devisId &&
                Number(d.ligne_devis_id || 0) === serviceId
            );
            debourse.innerHTML = `<option value="">${serviceId ? 'Sélectionner' : 'Choisir un service d’abord'}</option>` +
                rows.map(d => `<option value="${d.id}" ${Number(d.id) === selectedId ? 'selected' : ''}>${escapeHtml(d.label || ('Déboursé #' + d.id))} — ${money(d.montant)}</option>`).join('');
            if (!debourse.value && rows.length === 1) debourse.value = String(rows[0].id);
            debourse.dataset.selected = '';
            refreshSelectSearch(debourse);
            fillLignes();
        }

        function fillLignes() {
            const debourseId = Number(debourse.value || 0);
            const selectedId = Number(ligne.dataset.selected || ligne.value || 0);
            const rows = (data.lignes || []).filter(l => Number(l.debourse_id || 0) === debourseId);
            ligne.innerHTML = `<option value="">${debourseId ? 'Sélectionner une catégorie' : 'Choisir un service d’abord'}</option>` +
                rows.map(l => {
                    const designation = escapeHtml(l.designation || ('Ligne #' + l.id));
                    const category = l.categorie && normalize(l.categorie) !== normalize(l.designation)
                        ? `${escapeHtml(l.categorie)} · `
                        : '';
                    return `<option value="${l.id}" ${Number(l.id) === selectedId ? 'selected' : ''}>${category}${designation} — ${money(l.montant)}</option>`;
                }).join('');
            ligne.dataset.selected = '';
            refreshSelectSearch(ligne);
            updateDecisionState();
        }

        enhanceSelectSearch(chantier, 'Rechercher un chantier...');
        enhanceSelectSearch(serviceLine, 'Rechercher un service...');
        enhanceSelectSearch(debourse, 'Rechercher un déboursé...');
        enhanceSelectSearch(ligne, 'Rechercher une catégorie financière...');
        enhanceSelectSearch(modePaiement, 'Rechercher un mode de paiement...');

        chantier && chantier.addEventListener('change', fillServices);
        serviceLine && serviceLine.addEventListener('change', fillDebourses);
        debourse && debourse.addEventListener('change', fillLignes);
        ligne && ligne.addEventListener('change', updateDecisionState);
        modePaiement && modePaiement.addEventListener('change', updateDecisionState);

        serviceLine.dataset.selected = String(prefill.ligne_devis_id || serviceLine.dataset.selected || '');
        debourse.dataset.selected = String(prefill.debourse_id || debourse.dataset.selected || '');
        ligne.dataset.selected = String(prefill.ligne_debourse_id || ligne.dataset.selected || '');
        fillServices();
        if (prefill.personnel_id) {
            const p = (data.personnel || []).find(row => Number(row.id || 0) === Number(prefill.personnel_id)) || { id: Number(prefill.personnel_id), nom: 'Personnel sélectionné' };
            selectPersonnel(p);
        }
        if (prefill.fournisseur_id) {
            const f = (data.fournisseurs || []).find(row => Number(row.id || 0) === Number(prefill.fournisseur_id)) || { id: Number(prefill.fournisseur_id), nom: 'Fournisseur sélectionné' };
            selectSupplier(f);
        }

        function panelHasAnyValue() {
            if (!panel) return false;
            if (!toggle || !toggle.checked) return false;
            if (toggle && toggle.checked) return true;
            return Array.from(panel.querySelectorAll('input, select, textarea')).some(el => {
                if (el.type === 'radio' || el.type === 'checkbox') return el.checked;
                if (el.type === 'file') return el.files && el.files.length > 0;
                return String(el.value || '').trim() !== '';
            });
        }

        function panelComplete() {
            if (!panelHasAnyValue()) return false;
            const mode = selectedMode();
            if (!mode) return false;
            if (mode === 'personnel' && !personnelId.value) return false;
            if (mode === 'external') {
                const name = form.querySelector('[name="fd_beneficiaire_fiche"]');
                const tel = form.querySelector('[name="fd_tel_beneficiaire_fiche"]');
                const photo = form.querySelector('[name="fd_photo_beneficiaire"]');
                const cni = form.querySelector('[name="fd_cni_beneficiaire"]');
                if (!name.value.trim() || !tel.value.trim() || !photo.files.length || !cni.files.length) return false;
            }
            const budgetWillBeCreated = <?= json_encode($budgetPlanned === 'no') ?>;
            if (budgetWillBeCreated && integrationDevis) {
                const lineMode = form.querySelector('[name="feb_ligne_devis_mode"]:checked')?.value || '';
                const debourseMode = form.querySelector('[name="feb_debourse_mode"]:checked')?.value || '';
                if (!integrationDevis.value || (lineMode === 'existing' ? !integrationLineSelect.value : !integrationNewLine.value.trim()) || (debourseMode === 'existing' ? !integrationDebourseSelect.value : !integrationDebourseTitle.value.trim()) || !integrationRows.querySelector('tr')) return false;
            }
            return !!(chantier.value && (budgetWillBeCreated || (serviceLine.value && debourse.value && ligne.value)) && supplierId.value && form.querySelector('[name="fd_montant_fiche"]').value && form.querySelector('[name="fd_mode_paiement"]').value);
        }

        function updateDecisionState() {
            if (!form || !panel) return;
            const any = panelHasAnyValue();
            const complete = panelComplete();
            const isOpen = !!(toggle && toggle.checked);
            if (panel) {
                panel.hidden = !isOpen;
                panel.classList.toggle('is-disabled', !isOpen);
            }
            if (accordionTrigger) {
                accordionTrigger.classList.toggle('is-open', isOpen);
                accordionTrigger.classList.toggle('is-closed', !isOpen);
                accordionTrigger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            }
            if (accordionState) {
                accordionState.innerHTML = isOpen
                    ? '<i class="fa-solid fa-chevron-up"></i><span>Fermer</span>'
                    : '<i class="fa-solid fa-chevron-down"></i><span>Ouvrir</span>';
            }
            if (accordionMainIcon) {
                accordionMainIcon.className = isOpen
                    ? 'mt-1 inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-emerald-100 text-emerald-700'
                    : 'mt-1 inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-indigo-100 text-indigo-700';
                accordionMainIcon.innerHTML = isOpen ? '<i class="fa-solid fa-circle-check"></i>' : '<i class="fa-solid fa-file-circle-plus"></i>';
            }
            Array.from(panel.querySelectorAll('input, select, textarea')).forEach(el => {
                if (el === toggle) return;
                el.disabled = !isOpen;
            });
            if (isOpen && integrationDevis) {
                const lineMode = form.querySelector('[name="feb_ligne_devis_mode"]:checked')?.value || 'new';
                const debourseMode = form.querySelector('[name="feb_debourse_mode"]:checked')?.value || 'new';
                integrationLineSelect.disabled = lineMode !== 'existing';
                integrationNewLine.disabled = lineMode === 'existing';
                integrationDebourseSelect.disabled = debourseMode !== 'existing';
                integrationDebourseTitle.disabled = debourseMode === 'existing';
            }
            if (approveBtn) {
                const approveLabel = approveBtn.querySelector('.js-approve-label');
                if (approveLabel) approveLabel.textContent = isOpen ? 'Approuver et créer fiche' : 'Approuver';
                const approveIcon = approveBtn.querySelector('i');
                if (approveIcon) approveIcon.className = isOpen ? 'fa-solid fa-file-circle-check mr-2' : 'fa-solid fa-check mr-2';
            }
            otherBtns.forEach(btn => btn.disabled = any);
            if (approveBtn) approveBtn.disabled = any && !complete;
            if (rule) {
                rule.className = complete ? 'rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800' : 'rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-800';
                rule.textContent = complete ? 'Tous les champs sont prêts : l’approbation créera automatiquement la fiche en conformité.' : 'Remplissez tous les champs du bloc pour activer l’approbation avec création automatique.';
            }
        }

        accordionTrigger && accordionTrigger.addEventListener('click', () => {
            if (!toggle) return;
            toggle.checked = !toggle.checked;
            updateDecisionState();
        });
        toggle && toggle.addEventListener('change', updateDecisionState);
        form && form.addEventListener('input', updateDecisionState);
        form && form.addEventListener('change', updateDecisionState);
        document.addEventListener('click', event => {
            if (!event.target.closest('.feb-search-wrap')) {
                personnelResults && (personnelResults.hidden = true);
                supplierResults && (supplierResults.hidden = true);
                enhancedSelects.forEach(enhanced => enhanced.results.hidden = true);
            }
        });
        document.querySelectorAll('#fdExternalBlock label:has(input[type="file"])').forEach(label => {
            label.classList.add('feb-dropzone');
            const input = label.querySelector('input[type="file"]');
            if (input) input.classList.add('hidden');
            let output = label.querySelector('.feb-drop-output');
            if (!output) {
                output = document.createElement('span');
                output.className = 'feb-drop-output text-xs text-slate-600';
                label.appendChild(output);
            }
            label.addEventListener('dragover', event => {
                event.preventDefault();
                label.classList.add('is-dragover');
            });
            label.addEventListener('dragleave', () => label.classList.remove('is-dragover'));
            label.addEventListener('drop', event => {
                event.preventDefault();
                label.classList.remove('is-dragover');
                if (event.dataTransfer && event.dataTransfer.files && event.dataTransfer.files[0]) {
                    input.files = event.dataTransfer.files;
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                }
            });
            input && input.addEventListener('change', () => {
                const file = input.files && input.files[0] ? input.files[0] : null;
                if (!file) {
                    output.textContent = '';
                    return;
                }
                if (file.type && file.type.startsWith('image/')) {
                    const url = URL.createObjectURL(file);
                    output.innerHTML = `<img src="${url}" class="feb-drop-preview" alt=""><span>${file.name}</span>`;
                } else {
                    output.textContent = file.name;
                }
            });
        });
        updateBeneficiaryMode();
        updateDecisionState();
    });
</script>

<?php require_once __DIR__ . '/decaissement/includes/footer.php'; ?>

