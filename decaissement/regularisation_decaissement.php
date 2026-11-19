<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/DisbursementRegularizationManager.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../services/PasskeySignatureGuard.php';

$pdo = Database::getConnection();
$manager = new DisbursementRegularizationManager($pdo);
$numFiche = trim((string)($_GET['num_fiche'] ?? $_POST['num_fiche'] ?? ''));
$fiche = $manager->getFiche($numFiche);
if (!$fiche || (int)($fiche['decaisse'] ?? 0) !== 1 || !$manager->isEligibleFiche($fiche)) {
    http_response_code(404);
    exit('Fiche décaissée introuvable.');
}
// Initialise aussi les anciennes fiches déjà décaissées avant l'installation
// de ce workflow afin que le formulaire soit immédiatement disponible.
if ($manager->find($numFiche) === null) {
    $manager->openAfterDisbursement($numFiche);
}
$userId = (int)($_SESSION['user_id'] ?? 0);
$isRequester = $manager->isRequester($fiche, $userId); // bénéficiaire responsable du PV
$isAccounting = hasPermission('acces_compta') || hasPermission('acces_tresorerie') || hasPermission('acces_admin');
$canReview = $isAccounting;
$canView = $isRequester || $canReview || hasPermission('acces_direction');
if (!$canView) {
    http_response_code(403);
    require __DIR__ . '/../acces_refuse.php';
    exit;
}
if (empty($_SESSION['disbursement_regularization_csrf'])) {
    $_SESSION['disbursement_regularization_csrf'] = bin2hex(random_bytes(24));
}
$csrf = (string)$_SESSION['disbursement_regularization_csrf'];
$flash = (string)($_SESSION['disbursement_regularization_flash'] ?? '');
unset($_SESSION['disbursement_regularization_flash']);
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) throw new RuntimeException('Session expirée. Rechargez la page.');
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'submit') {
            PasskeySignatureGuard::requireVerified($userId);
            $manager->saveByRequester($fiche, $userId, $_POST, $_FILES['payment_proof'] ?? []);
            AuditTrail::log('disbursement_regularization_submitted', ['module'=>'decaissement','action'=>'regularization_submit','entity_type'=>'fiche_decaissement','entity_id'=>$numFiche,'entity_label'=>$numFiche,'result'=>'success'], $pdo);
            $next = $manager->getNextUnsubmittedForUser($userId, $numFiche);
            if ($next) {
                $_SESSION['disbursement_regularization_flash'] = 'La fiche '.$numFiche.' a bien été transmise. Poursuivez avec la régularisation suivante.';
                $nextQuery = ['num_fiche'=>(string)$next['num_fiche'], 'sequence'=>1];
                if (!empty($next['access_blocked_at'])) $nextQuery['access_limited'] = 1;
                header('Location: regularisation_decaissement.php?'.http_build_query($nextQuery));
                exit;
            }
            $_SESSION['disbursement_regularization_flash'] = 'PV signé et preuve de paiement transmis à la comptabilité. Toutes vos régularisations à transmettre sont terminées.';
            header('Location: regularisations_decaissements.php?submitted=1');
            exit;
        } elseif ($action === 'accounting_update' && $canReview) {
            if (!empty($_POST['submit_po'])) PasskeySignatureGuard::requireVerified($userId);
            $manager->updateByAccounting($fiche, $userId, (string)($_SESSION['username'] ?? 'Comptabilité'), $_POST, $_FILES['payment_proof'] ?? []);
            $flash = !empty($_POST['submit_po'])
                ? 'PV signé pour ordre par la comptabilité et preuve de paiement transmis.'
                : 'Brouillon du PV et preuve de paiement mis à jour.';
            AuditTrail::log('disbursement_regularization_documents_updated', ['module'=>'decaissement','action'=>'regularization_accounting_update','entity_type'=>'fiche_decaissement','entity_id'=>$numFiche,'entity_label'=>$numFiche,'result'=>'success'], $pdo);
        } elseif ($action === 'review' && $canReview) {
            $manager->validate($numFiche, $userId, (string)($_SESSION['username'] ?? 'Comptabilité'), (string)($_POST['decision'] ?? ''), (string)($_POST['accounting_note'] ?? ''));
            $flash = 'Décision comptable enregistrée.';
            AuditTrail::log('disbursement_regularization_reviewed', ['module'=>'decaissement','action'=>'regularization_review','entity_type'=>'fiche_decaissement','entity_id'=>$numFiche,'entity_label'=>$numFiche,'result'=>'success','details'=>['decision'=>$_POST['decision'] ?? '']], $pdo);
        } elseif ($action === 'block_requester' && $canReview) {
            $manager->setAccessBlock($numFiche, $userId, (string)($_SESSION['username'] ?? 'Comptabilité'), (string)($_POST['block_reason'] ?? ''));
            $flash = 'Le demandeur est maintenant limité à cette régularisation jusqu’à sa soumission.';
            AuditTrail::log('disbursement_regularization_requester_blocked', ['module'=>'decaissement','action'=>'regularization_access_block','entity_type'=>'user','entity_id'=>(int)($manager->find($numFiche)['requester_user_id'] ?? 0),'entity_label'=>$numFiche,'result'=>'success'], $pdo);
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
$record = $manager->find($numFiche);
$requesterName = '';
if ($record && (int)($record['beneficiary_user_id'] ?? 0) > 0) {
    $requesterStmt = $pdo->prepare("SELECT TRIM(CONCAT(COALESCE(prenom,''),' ',COALESCE(nom,''))) FROM user_devis_banamur WHERE id=:id LIMIT 1");
    $requesterStmt->execute(['id'=>(int)$record['beneficiary_user_id']]);
    $requesterName = trim((string)$requesterStmt->fetchColumn());
}
$project = (string)($record['project_name'] ?? ($fiche['chantier_titre'] ?? $fiche['designation_fiche'] ?? ''));
$location = (string)($record['project_location'] ?? ($fiche['chantier_code'] ?? ''));
$company = (string)($record['executing_company'] ?? ($fiche['chantier_entreprise'] ?? $fiche['entreprise'] ?? ''));
$owner = (string)($record['owner_name'] ?? ($fiche['nom_client'] ?? ''));
$signer = (string)($record['signer_name'] ?? ($requesterName ?: ($_SESSION['username'] ?? '')));
$signingIdentity = $manager->getUserIdentity($canReview ? $userId : (int)($record['beneficiary_user_id'] ?? $userId));
$identityPhoto = trim((string)($signingIdentity['photo'] ?? ''));
$identityPhotoUrl = '../photo/ma_photo.jpg';
$identityPhotoIsUrl = (bool)preg_match('~^(https?:|data:)~i', $identityPhoto);
if ($identityPhotoIsUrl) {
  $identityPhotoUrl = $identityPhoto;
}
$photoBasename = basename(str_replace('\\', '/', $identityPhoto));
foreach (array_unique([
  $identityPhoto,
  $photoBasename,
  'photo/'.$identityPhoto,
  'photo/'.$photoBasename,
  'uploads/photo/'.$identityPhoto,
  'uploads/photo/'.$photoBasename,
  'uploads/photos/'.$identityPhoto,
  'uploads/photos/'.$photoBasename,
  'uploads/personnel/'.$identityPhoto,
  'uploads/personnel/'.$photoBasename,
  'uploads/rh/photo/'.$identityPhoto,
  'uploads/rh/photo/'.$photoBasename,
]) as $photoCandidate) {
  if ($identityPhotoIsUrl) break;
    if ($photoCandidate === '') continue;
    $photoPath = realpath(dirname(__DIR__).'/'.ltrim($photoCandidate, '/'));
    $appRoot = realpath(dirname(__DIR__));
    if ($photoPath && $appRoot && strpos($photoPath, $appRoot.'/') === 0 && is_file($photoPath)) {
        $identityPhotoUrl = '../'.ltrim($photoCandidate, '/');
        break;
    }
}
$hasProfileSignature = trim((string)($signingIdentity['signature'] ?? '')) !== '';
$febLines = $manager->getFebLines($fiche);
$savedCompletionLines = json_decode((string)($record['completion_lines_json'] ?? ''), true);
$completedLineKeys = [];
$pendingStartDates = [];
foreach (is_array($savedCompletionLines) ? $savedCompletionLines : [] as $savedLine) {
    $savedKey=(string)($savedLine['key'] ?? '');
    if (!empty($savedLine['completed'])) $completedLineKeys[$savedKey] = true;
    if (!empty($savedLine['pending_start_date'])) $pendingStartDates[$savedKey]=(string)$savedLine['pending_start_date'];
}
$projectOptions = $locationOptions = $ownerOptions = $companyOptions = [];
try {
    $projectOptions = $pdo->query("SELECT DISTINCT titre FROM chantier_banamur WHERE titre IS NOT NULL AND TRIM(titre)<>'' AND archived_at IS NULL ORDER BY titre LIMIT 500")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $locationOptions = $pdo->query("SELECT DISTINCT TRIM(CONCAT(COALESCE(code,''),IF(code IS NOT NULL AND TRIM(code)<>'' AND titre IS NOT NULL AND TRIM(titre)<>'',' — ',''),COALESCE(titre,''))) FROM chantier_banamur WHERE archived_at IS NULL AND (TRIM(COALESCE(code,''))<>'' OR TRIM(COALESCE(titre,''))<>'') ORDER BY titre LIMIT 500")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $ownerOptions = $pdo->query("SELECT DISTINCT nom_client FROM client_banamur WHERE nom_client IS NOT NULL AND TRIM(nom_client)<>'' ORDER BY nom_client LIMIT 500")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $companyOptions = $pdo->query("SELECT DISTINCT COALESCE(NULLIF(nom_commercial,''),raison_sociale) FROM fournisseur_banamur WHERE actif=1 ORDER BY COALESCE(NULLIF(nom_commercial,''),raison_sociale) LIMIT 500")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $companyOptions = array_merge($companyOptions, $pdo->query("SELECT DISTINCT entreprise FROM chantier_banamur WHERE entreprise IS NOT NULL AND TRIM(entreprise)<>'' ORDER BY entreprise LIMIT 300")->fetchAll(PDO::FETCH_COLUMN) ?: []);
} catch (Throwable $ignored) {}
$projectOptions = array_values(array_unique(array_filter(array_merge([$project], $projectOptions))));
$locationOptions = array_values(array_unique(array_filter(array_merge([$location], $locationOptions))));
$ownerOptions = array_values(array_unique(array_filter(array_merge([$owner], $ownerOptions))));
$companyOptions = array_values(array_unique(array_filter(array_merge([$company], $companyOptions))));
$statusLabels = ['pending'=>'À transmettre','approved'=>'Validée','rejected'=>'À corriger'];
$pageTitle = 'Régularisation du décaissement';
$activeMenu = 'compta';
$activeEmployeeMenu = 'regularisation_decaissements';
$layoutRootBase = '../';
require __DIR__ . '/includes/header.php';
if ($isRequester && !$canReview && !hasPermission('acces_direction')) {
    require __DIR__ . '/../employe/includes/navbar.php';
} else {
    require __DIR__ . '/includes/navbar.php';
}
?>
<style>
.reg-form{background:linear-gradient(145deg,#fff 0%,#fbfdff 52%,#f5f3ff 100%)}
.reg-field{transition:border-color .2s,box-shadow .2s,background .2s}
.reg-field:focus{border-color:#6366f1;box-shadow:0 0 0 4px rgba(99,102,241,.12);background:#fff;outline:none}
.smart-combobox{position:relative}
.smart-combobox-input{width:100%;border:1px solid #cbd5e1;border-radius:14px;background:#fff;padding:12px 42px 12px 14px;font-size:14px;transition:.2s}
.smart-combobox-input:focus{border-color:#6366f1;box-shadow:0 0 0 4px rgba(99,102,241,.12);outline:none}
.smart-combobox-chevron{pointer-events:none;position:absolute;right:15px;top:50%;transform:translateY(-50%);color:#64748b}
.smart-combobox-menu{position:absolute;z-index:10030;left:0;right:0;top:calc(100% + 8px);max-height:250px;overflow:auto;border:1px solid #e2e8f0;border-radius:16px;background:#fff;padding:7px;box-shadow:0 22px 55px rgba(15,23,42,.18)}
.smart-combobox-option{display:flex;width:100%;align-items:center;gap:10px;border-radius:11px;padding:10px 12px;text-align:left;font-size:13px;font-weight:650;color:#334155}
.smart-combobox-option:hover,.smart-combobox-option.is-active{background:#eef2ff;color:#3730a3}
.smart-combobox-empty{padding:16px;text-align:center;font-size:12px;color:#64748b}
.proof-dropzone{position:relative;overflow:hidden;transition:border-color .2s,background .2s,transform .2s}
.proof-dropzone.is-dragging{border-color:#4f46e5;background:#eef2ff;transform:translateY(-2px)}
.proof-preview{display:grid;grid-template-columns:72px minmax(0,1fr) auto;align-items:center;gap:14px}
.proof-thumb{display:flex;height:72px;width:72px;align-items:center;justify-content:center;overflow:hidden;border-radius:15px;background:#eef2ff;color:#4f46e5}
.proof-thumb img{height:100%;width:100%;object-fit:cover}
.proof-progress-track{height:7px;overflow:hidden;border-radius:999px;background:#e2e8f0}
.proof-progress-bar{height:100%;width:0;border-radius:999px;background:linear-gradient(90deg,#4f46e5,#7c3aed,#059669);transition:width .12s}
.completion-row{display:grid;grid-template-columns:minmax(0,1fr) 150px 180px;gap:12px;align-items:stretch}
.completion-side{display:flex;align-items:center;justify-content:center;gap:8px;border-radius:16px;padding:12px;font-size:12px;font-weight:900;transition:.2s}
.completion-done{border:1px solid #a7f3d0;background:#ecfdf5;color:#047857}
.completion-pending{border:1px solid #fed7aa;background:#fff7ed;color:#c2410c}
.completion-row.is-completed .completion-pending{opacity:.35;filter:grayscale(.4)}
.completion-row:not(.is-completed) .completion-done{opacity:.55}
@media(max-width:760px){.completion-row{grid-template-columns:1fr 1fr}.completion-detail{grid-column:1/-1}.completion-side{min-height:54px}}
</style>
<div class="min-h-screen bg-slate-50 px-4 py-8">
 <div class="mx-auto max-w-6xl space-y-6">
  <div class="rounded-3xl bg-slate-950 p-6 text-white shadow-xl">
   <div class="flex flex-wrap items-start justify-between gap-4">
    <div><p class="text-xs font-bold uppercase tracking-[.25em] text-indigo-300">Après décaissement</p>
     <h1 class="mt-2 text-2xl font-bold">PV de réception et attestation d’exécution</h1>
     <p class="mt-2 text-sm text-slate-300">Fiche <?= htmlspecialchars($numFiche) ?> · <?= number_format((float)$fiche['montant_fiche'],0,',',' ') ?> FCFA</p>
    </div>
    <span class="rounded-full bg-white/10 px-4 py-2 text-sm font-semibold"><?= htmlspecialchars($statusLabels[$record['accounting_status'] ?? 'pending'] ?? 'À transmettre') ?></span>
   </div>
  </div>
  <?php if ($flash): ?><div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-800"><?= htmlspecialchars($flash) ?></div><?php endif ?>
  <?php if ($error): ?><div class="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-800"><?= htmlspecialchars($error) ?></div><?php endif ?>
  <?php if (!empty($_GET['access_limited']) && $isRequester): ?><div class="rounded-2xl border border-rose-300 bg-rose-50 p-5 text-sm text-rose-950"><b><i class="fas fa-lock mr-2"></i>Accès du bénéficiaire temporairement limité.</b><br>En tant que bénéficiaire, vous devez transmettre le PV signé. La preuve de paiement déjà enregistrée est reprise automatiquement. Votre accès normal sera rétabli dès la soumission.</div><?php endif ?>

  <?php $accountingCanEdit = $canReview && $record; ?>
  <?php if (($isRequester && (($record['accounting_status'] ?? '') !== 'approved')) || $accountingCanEdit): ?>
  <form method="post" enctype="multipart/form-data" novalidate class="reg-form space-y-7 rounded-[28px] border border-slate-200 p-6 shadow-[0_24px_70px_-35px_rgba(15,23,42,.35)] md:p-8" id="pvForm">
   <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>"><input type="hidden" name="num_fiche" value="<?= htmlspecialchars($numFiche) ?>"><input type="hidden" name="action" value="<?= $accountingCanEdit ? 'accounting_update' : 'submit' ?>">
   <?php if ($accountingCanEdit): ?><div class="rounded-2xl border border-violet-200 bg-violet-50 p-4 text-sm text-violet-900"><b>Mode comptabilité :</b> vous pouvez préparer ou corriger le contenu du PV et ajouter/remplacer la preuve de paiement. <?= !empty($record['signature_data']) ? 'La signature électronique existante du demandeur reste inchangée.' : 'Le demandeur devra ensuite vérifier et signer le PV.' ?></div><?php endif ?>
   <div><h2 class="text-lg font-bold text-slate-950">1. Identification et objet</h2><p class="mt-1 text-sm text-slate-500">Le texte du PDF s’adaptera à la nature sélectionnée.</p></div>
   <div class="grid gap-4 md:grid-cols-2">
    <label class="text-sm font-semibold text-slate-700">Nature
     <select name="reception_kind" class="js-smart-select mt-2 w-full rounded-xl border-slate-300" data-placeholder="Rechercher une nature…" data-required="1">
      <?php foreach (['travaux'=>'Travaux','fourniture'=>'Fourniture de biens','prestation'=>'Prestation de service','autre'=>'Autre exécution'] as $k=>$v): ?><option value="<?= $k ?>" <?= (($record['reception_kind'] ?? 'travaux')===$k?'selected':'') ?>><?= $v ?></option><?php endforeach ?>
     </select>
    </label>
    <label class="text-sm font-semibold text-slate-700">Projet / chantier
     <select name="project_name" class="js-smart-select mt-2 w-full rounded-xl border-slate-300" data-placeholder="Saisir ou rechercher un chantier…" data-required="1">
      <option value="" <?= !in_array($project,$projectOptions,true)?'selected':'' ?>>Sélectionner un chantier actif…</option>
      <?php foreach ($projectOptions as $option): ?><option value="<?= htmlspecialchars((string)$option) ?>" <?= ((string)$option===$project?'selected':'') ?>><?= htmlspecialchars((string)$option) ?></option><?php endforeach ?>
     </select>
    </label>
    <label class="text-sm font-semibold text-slate-700">Maître d’ouvrage / bénéficiaire final
     <?php if (trim($owner) !== ''): ?>
      <input type="hidden" name="owner_name" value="<?= htmlspecialchars($owner) ?>">
      <span class="mt-2 flex min-h-[58px] items-center gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-950"><i class="fas fa-circle-check text-emerald-600"></i><span><span class="block"><?= htmlspecialchars($owner) ?></span><small class="font-medium text-emerald-700">Déjà renseigné en amont · modification désactivée</small></span></span>
     <?php else: ?>
      <select name="owner_name" class="js-smart-select mt-2 w-full rounded-xl border-slate-300" data-placeholder="Rechercher un bénéficiaire…"><?php foreach ($ownerOptions as $option): ?><option value="<?= htmlspecialchars((string)$option) ?>" <?= ((string)$option===$owner?'selected':'') ?>><?= htmlspecialchars((string)$option) ?></option><?php endforeach ?></select>
     <?php endif ?>
    </label>
    <label class="text-sm font-semibold text-slate-700">Entreprise / fournisseur / prestataire
     <select name="executing_company" class="js-smart-select mt-2 w-full rounded-xl border-slate-300" data-placeholder="Rechercher une entreprise…"><?php foreach ($companyOptions as $option): ?><option value="<?= htmlspecialchars((string)$option) ?>" <?= ((string)$option===$company?'selected':'') ?>><?= htmlspecialchars((string)$option) ?></option><?php endforeach ?></select>
    </label>
    <label class="text-sm font-semibold text-slate-700">Lieu / chantier concerné
     <select name="project_location" class="js-smart-select mt-2 w-full rounded-xl border-slate-300" data-placeholder="Rechercher un chantier par code ou nom…" data-required="1"><option value="" <?= !in_array($location,$locationOptions,true)?'selected':'' ?>>Sélectionner un chantier actif…</option><?php foreach ($locationOptions as $option): ?><option value="<?= htmlspecialchars((string)$option) ?>" <?= ((string)$option===$location?'selected':'') ?>><?= htmlspecialchars((string)$option) ?></option><?php endforeach ?></select>
    </label>
    <label class="text-sm font-semibold text-slate-700">Date de réception / constat<input type="date" name="reception_date" value="<?= htmlspecialchars((string)($record['reception_date'] ?? date('Y-m-d'))) ?>" class="mt-2 min-h-[58px] w-full rounded-2xl border-slate-300 px-4 py-4 text-base font-bold text-slate-900 shadow-sm" required></label>
   </div>
   <div><h2 class="text-lg font-bold text-slate-950">2. Avancement des lignes de la FEB</h2><p class="mt-1 text-sm text-slate-500">Cochez uniquement les lignes réellement terminées. Les autres restent automatiquement en attente.</p></div>
   <div class="overflow-hidden rounded-[24px] border border-slate-200 bg-white shadow-sm">
    <div class="grid grid-cols-[minmax(0,1fr)_150px_180px] gap-3 bg-slate-950 px-4 py-3 text-[11px] font-black uppercase tracking-wider text-white max-md:hidden"><span>Ligne et poids financier</span><span class="text-center text-emerald-300">Effectué</span><span class="text-center text-orange-300">Restant / début prévu</span></div>
    <div class="space-y-3 p-3">
     <?php foreach ($febLines as $line): $checked=!empty($completedLineKeys[(string)$line['key']]); ?>
      <div class="completion-row <?= $checked?'is-completed':'' ?>" data-completion-row data-amount="<?= htmlspecialchars((string)((float)$line['amount'] > 0 ? $line['amount'] : 1)) ?>">
       <div class="completion-detail rounded-2xl border border-slate-200 bg-slate-50 p-4">
        <div class="flex flex-wrap items-center gap-2"><span class="rounded-full bg-indigo-100 px-2 py-1 text-[10px] font-black text-indigo-700">#<?= (int)$line['index'] ?></span><b class="text-sm text-slate-950"><?= htmlspecialchars((string)$line['label']) ?></b></div>
        <div class="mt-2 text-xs text-slate-500"><?= htmlspecialchars(trim(implode(' · ',array_filter([(string)$line['type'],(string)$line['category'],(string)$line['supplier']])))) ?></div>
        <div class="mt-3 flex flex-wrap gap-2 text-[11px] font-bold"><span class="rounded-full bg-white px-3 py-1 text-slate-600"><?= number_format((float)$line['quantity'],2,',',' ') ?> × <?= number_format((float)$line['unit_price'],0,',',' ') ?> FCFA</span><span class="rounded-full bg-indigo-600 px-3 py-1 text-white"><?= number_format((float)$line['amount'],0,',',' ') ?> FCFA · poids <?= number_format((float)$line['weight'],2,',',' ') ?> %</span></div>
       </div>
       <label class="completion-side completion-done cursor-pointer"><input type="checkbox" name="completed_lines[]" value="<?= htmlspecialchars((string)$line['key']) ?>" class="completion-checkbox rounded border-emerald-400 text-emerald-600" <?= $checked?'checked':'' ?>><i class="fas fa-circle-check"></i><span>Terminé</span></label>
       <div class="completion-side completion-pending flex-col">
        <div><i class="fas fa-clock mr-1"></i><span>En attente</span></div>
        <label class="w-full text-[10px] font-black uppercase tracking-wide">Début prévu
         <input type="date" name="pending_start_dates[<?= htmlspecialchars((string)$line['key']) ?>]" value="<?= htmlspecialchars((string)($pendingStartDates[(string)$line['key']] ?? '')) ?>" class="pending-start-date mt-1 w-full rounded-lg border-orange-200 bg-white px-2 py-2 text-[11px] font-bold text-slate-800" <?= $checked?'disabled':'required' ?>>
        </label>
       </div>
      </div>
     <?php endforeach ?>
    </div>
    <div class="border-t border-slate-200 bg-gradient-to-r from-orange-50 via-white to-emerald-50 p-5">
     <div class="flex flex-wrap items-end justify-between gap-4"><div><div class="text-xs font-black uppercase tracking-wider text-slate-500">Progression pondérée en temps réel</div><div class="mt-1 text-xs text-slate-500">Le calcul change immédiatement à chaque clic, sans enregistrer le formulaire.</div><div class="mt-2 flex flex-wrap gap-3 text-xs font-bold"><span class="text-emerald-700">Effectué : <b id="completionDoneAmount">0 FCFA</b></span><span class="text-orange-700">Restant : <b id="completionRemainingAmount">0 FCFA</b></span></div></div><div id="completionPercent" aria-live="polite" class="text-3xl font-black text-indigo-700">0 %</div></div>
     <div class="mt-3 h-4 overflow-hidden rounded-full bg-slate-200"><div id="completionBar" class="h-full rounded-full bg-gradient-to-r from-orange-400 via-indigo-500 to-emerald-500 transition-all duration-500" style="width:0%"></div></div>
    </div>
   </div>
   <label class="block text-sm font-semibold text-slate-700">Observation générale<textarea name="observations" rows="4" class="mt-2 w-full rounded-2xl border-slate-300" placeholder="Ajoutez uniquement les précisions utiles sur l’exécution ou le suivi."><?= htmlspecialchars((string)($record['observations'] ?? '')) ?></textarea></label>
   <input type="hidden" name="validated_amount" value="<?= htmlspecialchars((string)($record['validated_amount'] ?? $fiche['montant_fiche'])) ?>">
   <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">En signant, je certifie l’état des lignes ci-dessus et m’engage à suivre celles qui restent automatiquement identifiées « En attente ».</div>
   <div class="grid gap-5 lg:grid-cols-2">
    <div>
     <label class="text-sm font-semibold text-slate-700">Preuve de paiement <?= empty($record['payment_proof_path'])?'(obligatoire)':'' ?></label>
     <?php if (!empty($record['payment_proof_path'])): ?>
      <div class="mt-2 rounded-[22px] border border-emerald-200 bg-emerald-50 p-4 shadow-sm">
       <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex min-w-0 items-center gap-3"><span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-emerald-600 text-white"><i class="fas fa-receipt"></i></span><span class="min-w-0"><b class="block text-sm text-emerald-950">Preuve déjà fournie lors du paiement</b><small class="block truncate text-emerald-700"><?= htmlspecialchars((string)($record['payment_proof_name'] ?: basename((string)$record['payment_proof_path']))) ?><?= !empty($record['payment_proof_size']) ? ' · '.number_format((int)$record['payment_proof_size']/1024, 0, ',', ' ').' Ko' : '' ?></small></span></div>
        <a target="_blank" class="rounded-xl bg-emerald-700 px-4 py-2 text-xs font-bold text-white hover:bg-emerald-800" href="../request/view_disbursement_payment_proof.php?num_fiche=<?= rawurlencode($numFiche) ?>"><i class="fas fa-eye mr-2"></i>Voir la preuve</a>
       </div>
       <p class="mt-3 text-xs font-medium text-emerald-800">La preuve est acquise. Complétez uniquement le PV ; un nouveau dépôt ci-dessous la remplacera.</p>
      </div>
     <?php endif ?>
     <label id="paymentProofDropzone" data-proof-required="<?= empty($record['payment_proof_path']) ? '1' : '0' ?>" class="proof-dropzone mt-2 block min-h-44 cursor-pointer rounded-[22px] border-2 border-dashed border-indigo-300 bg-white p-5 shadow-sm hover:border-indigo-500 hover:bg-indigo-50/40">
      <div id="paymentProofEmpty" class="flex min-h-32 flex-col items-center justify-center text-center">
       <span class="flex h-14 w-14 items-center justify-center rounded-2xl bg-indigo-100 text-indigo-700 shadow-inner"><i class="fas fa-cloud-arrow-up text-2xl"></i></span>
       <span class="mt-3 text-sm font-extrabold text-slate-900"><?= empty($record['payment_proof_path']) ? 'Déposez la preuve de paiement ici' : 'Remplacer la preuve (facultatif)' ?></span>
       <span class="mt-1 text-xs text-slate-500">ou cliquez pour choisir un reçu, une capture, un chèque ou une décharge</span>
       <span class="mt-3 rounded-full bg-slate-100 px-3 py-1 text-[11px] font-bold text-slate-600">PDF · JPG · PNG · WEBP · HEIC · 12 Mo max.</span>
      </div>
      <div id="paymentProofPreview" class="proof-preview hidden">
       <div id="paymentProofThumb" class="proof-thumb"><i class="fas fa-file-pdf text-3xl"></i></div>
       <div class="min-w-0"><div id="paymentProofName" class="truncate text-sm font-extrabold text-slate-900"></div><div id="paymentProofMeta" class="mt-1 text-xs text-slate-500"></div><div class="proof-progress-track mt-3"><div id="paymentProofProgress" class="proof-progress-bar"></div></div><div id="paymentProofStatus" class="mt-1 text-[11px] font-bold text-indigo-700">Préparation…</div></div>
       <button id="paymentProofRemove" type="button" class="flex h-9 w-9 items-center justify-center rounded-full bg-rose-100 text-rose-700 shadow-sm transition hover:bg-rose-600 hover:text-white" title="Supprimer le fichier" aria-label="Supprimer le fichier"><i class="fas fa-xmark"></i></button>
      </div>
      <input id="paymentProofInput" type="file" name="payment_proof" accept=".pdf,image/jpeg,image/png,image/webp,image/heic" class="hidden">
     </label>
    </div>
    <div class="overflow-hidden rounded-[22px] border border-slate-200 bg-slate-950 text-white shadow-xl">
     <div class="bg-gradient-to-r from-indigo-600 to-violet-700 px-4 py-2 text-[10px] font-black uppercase tracking-[.2em]"><?= $accountingCanEdit ? 'Signataire comptable — pour ordre' : 'Identité professionnelle du signataire' ?></div>
     <div class="flex items-center gap-4 p-4">
      <img src="<?= htmlspecialchars($identityPhotoUrl) ?>" onerror="this.src='../photo/ma_photo.jpg'" class="h-20 w-20 rounded-2xl border-2 border-white/30 object-cover shadow-lg" alt="Photo du signataire">
      <div class="min-w-0"><div class="truncate text-lg font-black"><?= htmlspecialchars((string)($signingIdentity['full_name'] ?? $signer)) ?></div><div class="mt-1 text-sm font-semibold text-indigo-200"><?= htmlspecialchars((string)($signingIdentity['fonction'] ?? 'Fonction non renseignée')) ?></div><div class="text-xs text-slate-300"><?= htmlspecialchars((string)($signingIdentity['service'] ?? 'Service non renseigné')) ?><?= !empty($signingIdentity['matricule']) ? ' · '.htmlspecialchars((string)$signingIdentity['matricule']) : '' ?></div><div class="mt-1 truncate text-[11px] text-slate-400"><?= htmlspecialchars((string)($signingIdentity['mail_pro'] ?? '')) ?></div></div>
     </div>
     <input type="hidden" name="signer_name" value="<?= htmlspecialchars((string)($signingIdentity['full_name'] ?? $signer)) ?>">
    </div>
   </div>
   <div class="rounded-[22px] border border-slate-200 bg-white p-5">
    <div class="flex flex-wrap gap-3">
     <?php if ($hasProfileSignature): ?><label class="cursor-pointer rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm font-bold text-indigo-900"><input type="radio" name="signature_mode" value="profile" class="mr-2" checked>Utiliser ma signature du profil</label><?php endif ?>
     <label class="cursor-pointer rounded-xl border border-slate-200 px-4 py-3 text-sm font-bold"><input type="radio" name="signature_mode" value="drawn" class="mr-2" <?= $hasProfileSignature?'':'checked' ?>>Signer maintenant</label>
    </div>
    <div id="drawnSignatureBlock" class="<?= $hasProfileSignature?'hidden':'' ?> mt-4"><div class="rounded-2xl border-2 border-dashed border-slate-300 bg-white p-2"><canvas id="signatureCanvas" class="h-44 w-full touch-none"></canvas></div><input type="hidden" name="signature_data" id="signatureData"><button type="button" id="clearSignature" class="mt-2 text-sm font-semibold text-rose-600">Effacer la signature</button></div>
   </div>
   <?php if ($accountingCanEdit): ?><label class="flex items-start gap-3 rounded-2xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950"><input type="checkbox" name="submit_po" value="1" class="mt-1"><span><b>Signer et soumettre en PO</b><br>Je certifie signer « Pour ordre » au nom du service comptable. Mon identité, ma photo, mon service et ma fonction apparaîtront sur le PV.</span></label><?php endif ?>
   <div class="space-y-2">
    <button type="submit" id="submitRegularization" class="rounded-2xl bg-indigo-600 px-6 py-3 font-bold text-white hover:bg-indigo-700"><?= $accountingCanEdit ? 'Enregistrer / soumettre en PO selon le choix' : 'Signer et transmettre à la comptabilité' ?></button>
    <p id="regularizationSubmitHint" class="hidden text-sm font-semibold text-indigo-700" role="status">Vérification de la signature et transmission en cours…</p>
   </div>
  </form>
  <?php endif ?>

  <?php if ($canReview && $record && empty($record['submitted_at'])): ?>
  <form method="post" class="rounded-3xl border border-rose-200 bg-rose-50 p-6">
   <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>"><input type="hidden" name="num_fiche" value="<?= htmlspecialchars($numFiche) ?>"><input type="hidden" name="action" value="block_requester">
   <h2 class="text-lg font-bold text-rose-950">Régularisation obligatoire</h2>
   <p class="mt-2 text-sm text-rose-800">Le blocage limite la navigation du demandeur à cet écran. Il pourra se connecter et transmettre les documents ; sa soumission le débloquera immédiatement, avant la validation comptable.</p>
   <?php if ((int)($record['requester_user_id'] ?? 0) <= 0): ?><div class="mt-4 rounded-xl bg-white/70 p-3 text-sm font-bold text-amber-900">Le compte demandeur n’est pas encore identifiable depuis cette ancienne fiche. Le blocage est indisponible, mais la comptabilité peut préparer les documents.</div>
   <?php elseif (!empty($record['access_blocked_at'])): ?><div class="mt-4 rounded-xl bg-white/70 p-3 text-sm font-bold text-rose-900">Blocage actif depuis <?= htmlspecialchars((string)$record['access_blocked_at']) ?>.</div><?php else: ?>
   <textarea name="block_reason" rows="2" class="mt-4 w-full rounded-xl border-rose-200" placeholder="Motif communiqué au demandeur">PV signé et preuve de paiement obligatoires pour la fiche <?= htmlspecialchars($numFiche) ?>.</textarea>
   <button class="mt-3 rounded-xl bg-rose-700 px-5 py-3 font-bold text-white" onclick="return confirm('Limiter le compte du demandeur à cette régularisation jusqu’à sa soumission ?')">Bloquer jusqu’à la soumission</button>
   <?php endif ?>
  </form>
  <?php endif ?>

  <?php if ($record && !empty($record['submitted_at'])): ?>
  <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
   <div class="flex flex-wrap items-center justify-between gap-3"><div><h2 class="text-lg font-bold">Documents transmis</h2><p class="text-sm text-slate-500">Signé par <?= htmlspecialchars((string)$record['signer_name']) ?> le <?= htmlspecialchars((string)$record['signed_at']) ?></p></div>
   <div class="flex gap-2"><a target="_blank" class="rounded-xl bg-rose-600 px-4 py-2 text-sm font-bold text-white" href="../request/export_disbursement_reception_pv.php?num_fiche=<?= rawurlencode($numFiche) ?>">Voir le PV PDF</a><a target="_blank" class="rounded-xl bg-slate-800 px-4 py-2 text-sm font-bold text-white" href="../request/view_disbursement_payment_proof.php?num_fiche=<?= rawurlencode($numFiche) ?>">Voir la preuve</a></div></div>
   <?php if (!empty($record['accounting_note'])): ?><div class="mt-4 rounded-xl bg-slate-50 p-4 text-sm"><b>Note comptable :</b> <?= nl2br(htmlspecialchars((string)$record['accounting_note'])) ?></div><?php endif ?>
  </div>
  <?php endif ?>
  <?php if ($canReview && $record && !empty($record['submitted_at']) && ($record['accounting_status'] ?? '') !== 'approved'): ?>
  <form method="post" class="rounded-3xl border border-indigo-200 bg-indigo-50 p-6">
   <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>"><input type="hidden" name="num_fiche" value="<?= htmlspecialchars($numFiche) ?>"><input type="hidden" name="action" value="review">
   <h2 class="text-lg font-bold text-indigo-950">Validation du service comptable</h2>
   <textarea name="accounting_note" rows="3" class="mt-4 w-full rounded-xl border-indigo-200" placeholder="Observation ou motif de correction"></textarea>
   <div class="mt-4 flex gap-3"><button name="decision" value="approved" class="rounded-xl bg-emerald-600 px-5 py-3 font-bold text-white">Valider la complétion</button><button name="decision" value="rejected" class="rounded-xl bg-rose-600 px-5 py-3 font-bold text-white">Demander une correction</button></div>
  </form>
  <?php endif ?>
 </div>
</div>
<script>
(() => {
 document.querySelectorAll('.js-smart-select').forEach(select=>{
   const options=[...select.options].map(o=>({value:o.value,label:o.textContent.trim(),selected:o.selected}));
   const box=document.createElement('div'); box.className='smart-combobox mt-2';
   const input=document.createElement('input'); input.type='text'; input.className='smart-combobox-input';
   input.placeholder=select.dataset.placeholder||'Rechercher…'; input.autocomplete='off';
   input.setAttribute('role','combobox'); input.setAttribute('aria-expanded','false');
   const icon=document.createElement('i'); icon.className='fas fa-chevron-down smart-combobox-chevron';
   const menu=document.createElement('div'); menu.className='smart-combobox-menu hidden'; menu.setAttribute('role','listbox');
   box.append(input,icon,menu); select.classList.add('hidden'); select.insertAdjacentElement('afterend',box);
   let active=-1;
   const initiallyRequired=select.required||select.dataset.required==='1';
   // Le <select> est masqué par le composant : la validation native doit donc
   // cibler l'input visible, sinon le navigateur bloque silencieusement l'envoi.
   select.required=false;
   input.required=initiallyRequired;
   input._regularizationSelect=select;
   let selected=options.find(o=>o.selected)||options[0];
   if(selected)input.value=selected.value ? selected.label : '';
   const render=(query='')=>{
     const q=query.trim().toLocaleLowerCase('fr'); menu.innerHTML=''; active=-1;
     const filtered=options.filter(o=>!q||o.label.toLocaleLowerCase('fr').includes(q));
     if(!filtered.length){menu.innerHTML='<div class="smart-combobox-empty">Aucune option ne correspond à cette recherche.</div>';return}
     filtered.forEach((o,index)=>{
       const btn=document.createElement('button');btn.type='button';btn.className='smart-combobox-option';
       btn.dataset.value=o.value;btn.setAttribute('role','option');
       btn.innerHTML=`<i class="far ${select.value===o.value?'fa-circle-check text-indigo-600':'fa-circle text-slate-300'}"></i><span>${o.label}</span>`;
       btn.addEventListener('mousedown',e=>{e.preventDefault();choose(o)});
       menu.appendChild(btn);
     });
   };
   const open=()=>{render(input.value===selected?.label?'':input.value);menu.classList.remove('hidden');input.setAttribute('aria-expanded','true')};
   const close=()=>{menu.classList.add('hidden');input.setAttribute('aria-expanded','false');active=-1};
   const choose=o=>{select.value=o.value;input.value=o.value ? o.label : '';selected=o;input.setCustomValidity('');select.dispatchEvent(new Event('change',{bubbles:true}));close()};
   input.addEventListener('focus',open);
   input.addEventListener('input',()=>{render(input.value);menu.classList.remove('hidden')});
   input.addEventListener('keydown',e=>{
     const items=[...menu.querySelectorAll('.smart-combobox-option')];
     if(e.key==='ArrowDown'||e.key==='ArrowUp'){e.preventDefault();if(menu.classList.contains('hidden'))open();active=e.key==='ArrowDown'?Math.min(active+1,items.length-1):Math.max(active-1,0);items.forEach((x,i)=>x.classList.toggle('is-active',i===active));items[active]?.scrollIntoView({block:'nearest'})}
     if(e.key==='Enter'&&active>=0){e.preventDefault();const item=items[active],o=options.find(x=>x.value===item?.dataset.value);if(o)choose(o)}
     if(e.key==='Escape')close();
   });
   input.addEventListener('blur',()=>setTimeout(()=>{const current=options.find(o=>o.value===select.value);if(current)input.value=current.value ? current.label : '';close()},120));
   input.addEventListener('invalid',()=>input.setCustomValidity('Sélectionnez une valeur dans cette liste.'));
   input.addEventListener('input',()=>input.setCustomValidity(''));
 });

 const completionRows=[...document.querySelectorAll('[data-completion-row]')];
 const completionPercent=document.getElementById('completionPercent'),completionBar=document.getElementById('completionBar');
 const completionDoneAmount=document.getElementById('completionDoneAmount'),completionRemainingAmount=document.getElementById('completionRemainingAmount');
 const formatMoney=value=>`${Math.round(value).toLocaleString('fr-FR')} FCFA`;
 const updateCompletion=()=>{
   const total=completionRows.reduce((sum,row)=>sum+Number(row.dataset.amount||0),0)||completionRows.length||1;
   const done=completionRows.reduce((sum,row)=>{
     const checked=!!row.querySelector('.completion-checkbox')?.checked;
     const pendingDate=row.querySelector('.pending-start-date');
     row.classList.toggle('is-completed',checked);
     if(pendingDate){pendingDate.disabled=checked;pendingDate.required=!checked}
     return sum+(checked?(Number(row.dataset.amount||0)||1):0);
   },0);
   const percent=Math.min(100,Math.max(0,done/total*100));
   if(completionPercent)completionPercent.textContent=`${percent.toLocaleString('fr-FR',{maximumFractionDigits:2})} %`;
   if(completionBar)completionBar.style.width=`${percent}%`;
   if(completionDoneAmount)completionDoneAmount.textContent=formatMoney(done);
   if(completionRemainingAmount)completionRemainingAmount.textContent=formatMoney(Math.max(0,total-done));
 };
 completionRows.forEach(row=>{
   const checkbox=row.querySelector('.completion-checkbox');
   checkbox?.addEventListener('input',updateCompletion);
   checkbox?.addEventListener('change',updateCompletion);
 });
 updateCompletion();

 const proofInput=document.getElementById('paymentProofInput'),proofZone=document.getElementById('paymentProofDropzone');
 const proofEmpty=document.getElementById('paymentProofEmpty'),proofPreview=document.getElementById('paymentProofPreview');
 const proofName=document.getElementById('paymentProofName'),proofMeta=document.getElementById('paymentProofMeta');
 const proofThumb=document.getElementById('paymentProofThumb'),proofProgress=document.getElementById('paymentProofProgress');
 const proofStatus=document.getElementById('paymentProofStatus'),proofRemove=document.getElementById('paymentProofRemove');
 let previewUrl='';
 const resetProof=()=>{
   if(previewUrl)URL.revokeObjectURL(previewUrl);previewUrl='';proofInput.value='';
   proofPreview.classList.add('hidden');proofEmpty.classList.remove('hidden');proofProgress.style.width='0%';proofZone.classList.remove('is-dragging');
 };
 const formatSize=bytes=>bytes<1024?`${bytes} o`:bytes<1048576?`${(bytes/1024).toFixed(1)} Ko`:`${(bytes/1048576).toFixed(1)} Mo`;
 const loadProof=file=>{
   if(!file)return;
   if(file.size>12*1024*1024){alert('Le fichier dépasse la limite de 12 Mo.');resetProof();return}
   proofEmpty.classList.add('hidden');proofPreview.classList.remove('hidden');
   proofName.textContent=file.name;proofMeta.textContent=`${formatSize(file.size)} · ${file.type||'Type à vérifier'}`;
   proofProgress.style.width='4%';proofStatus.textContent='Lecture du fichier…';
   proofThumb.innerHTML=file.type.startsWith('image/')?'<i class="fas fa-image text-3xl"></i>':'<i class="fas fa-file-pdf text-3xl"></i>';
   const reader=new FileReader();
   reader.onprogress=e=>{if(e.lengthComputable)proofProgress.style.width=`${Math.max(6,Math.round(e.loaded/e.total*100))}%`};
   reader.onload=()=>{
     proofProgress.style.width='100%';proofStatus.textContent='Fichier prêt à être envoyé';
     if(file.type.startsWith('image/')){previewUrl=URL.createObjectURL(file);proofThumb.innerHTML=`<img src="${previewUrl}" alt="Aperçu de la preuve">`}
   };
   reader.onerror=()=>{proofStatus.textContent='Lecture impossible';proofStatus.className='mt-1 text-[11px] font-bold text-rose-700'};
   reader.readAsArrayBuffer(file);
 };
 if(proofInput&&proofZone){
   proofZone.addEventListener('click',e=>{
     if(e.target===proofInput||e.target.closest('#paymentProofRemove'))return;
     e.preventDefault();
     proofInput.click();
   });
   proofInput.addEventListener('change',()=>loadProof(proofInput.files?.[0]));
   ['dragenter','dragover'].forEach(n=>proofZone.addEventListener(n,e=>{e.preventDefault();e.stopPropagation();proofZone.classList.add('is-dragging')}));
   ['dragleave','drop'].forEach(n=>proofZone.addEventListener(n,e=>{e.preventDefault();e.stopPropagation();proofZone.classList.remove('is-dragging')}));
   proofZone.addEventListener('drop',e=>{if(e.dataTransfer.files.length){const transfer=new DataTransfer();transfer.items.add(e.dataTransfer.files[0]);proofInput.files=transfer.files;loadProof(proofInput.files[0])}});
   proofRemove.addEventListener('click',e=>{e.preventDefault();e.stopPropagation();resetProof()});
 }
 const pvForm=document.getElementById('pvForm');
 const submitHint=document.getElementById('regularizationSubmitHint');
 if(pvForm)pvForm.addEventListener('submit',e=>{
   const missingSelection=[...pvForm.querySelectorAll('.smart-combobox-input[required]')].find(input=>!input._regularizationSelect?.value);
   if(missingSelection){
     e.preventDefault();
     missingSelection.setCustomValidity('Sélectionnez une valeur dans cette liste.');
     missingSelection.reportValidity();
     missingSelection.focus();
     return;
   }
   const invalidVisibleField=[...pvForm.querySelectorAll('input, textarea, select')].find(field=>field.required&&!field.disabled&&!field.classList.contains('hidden')&&!field.checkValidity());
   if(invalidVisibleField){
     e.preventDefault();
     invalidVisibleField.reportValidity();
     invalidVisibleField.focus();
     return;
   }
   if(proofZone?.dataset.proofRequired==='1'&&!proofInput?.files?.length){
     e.preventDefault();proofZone.classList.add('border-rose-500','bg-rose-50');proofZone.scrollIntoView({behavior:'smooth',block:'center'});alert('Ajoutez une preuve de paiement pour continuer.');
   }
 });
 const canvas=document.getElementById('signatureCanvas'); if(!canvas)return;
 const signatureBlock=document.getElementById('drawnSignatureBlock');
 const signatureModes=[...document.querySelectorAll('input[name="signature_mode"]')];
 const poCheckbox=document.querySelector('input[name="submit_po"]');
 const ctx=canvas.getContext('2d'); let drawing=false,signatureTouched=false;
 function resize(){const r=canvas.getBoundingClientRect(),d=devicePixelRatio||1;canvas.width=r.width*d;canvas.height=r.height*d;ctx.scale(d,d);ctx.lineWidth=2.2;ctx.lineCap='round';ctx.strokeStyle='#0f172a'}
 resize(); window.addEventListener('resize',resize);
 function pos(e){const r=canvas.getBoundingClientRect(),p=e.touches?e.touches[0]:e;return [p.clientX-r.left,p.clientY-r.top]}
 canvas.addEventListener('pointerdown',e=>{drawing=true;signatureTouched=true;ctx.beginPath();ctx.moveTo(...pos(e))});
 canvas.addEventListener('pointermove',e=>{if(drawing){ctx.lineTo(...pos(e));ctx.stroke()}});
 window.addEventListener('pointerup',()=>drawing=false);
 const updateSignatureMode=()=>{
   const drawn=document.querySelector('input[name="signature_mode"]:checked')?.value==='drawn';
   signatureBlock?.classList.toggle('hidden',!drawn);
   if(drawn)requestAnimationFrame(resize);
 };
 signatureModes.forEach(radio=>radio.addEventListener('change',updateSignatureMode));
 updateSignatureMode();
 document.getElementById('clearSignature').onclick=()=>{ctx.clearRect(0,0,canvas.width,canvas.height);signatureTouched=false};
 document.getElementById('pvForm').addEventListener('submit',e=>{
   const drawn=document.querySelector('input[name="signature_mode"]:checked')?.value==='drawn';
   const signatureRequired=!poCheckbox||poCheckbox.checked;
   if(drawn&&signatureRequired&&!signatureTouched){
     e.preventDefault();
     signatureBlock?.scrollIntoView({behavior:'smooth',block:'center'});
     alert('Veuillez dessiner votre signature avant de transmettre le document.');
     return;
   }
   if(drawn)document.getElementById('signatureData').value=canvas.toDataURL('image/png');
   if(!e.defaultPrevented&&submitHint)submitHint.classList.remove('hidden');
 });
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
