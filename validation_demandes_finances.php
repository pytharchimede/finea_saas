<?php
require_once __DIR__.'/services/DevisOfferProjectMigrationService.php';
require_once __DIR__ . '/auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/model/Database.php';
require_once __DIR__ . '/model/EmployeeLegalRequestManager.php';
require_once __DIR__ . '/model/AuditTrail.php';
require_once __DIR__ . '/model/FebValidationPolicy.php';
require_once __DIR__ . '/controllers/NotificationController.php';
require_once __DIR__ . '/services/FebWorkflowMigrationService.php';
require_once __DIR__ . '/services/QuoteBillingAlertService.php';
require_once __DIR__ . '/services/FebPriceChallengeService.php';
require_once __DIR__ . '/services/FinancialPlanningSeparationService.php';
require_once __DIR__ . '/services/UrgentDisbursementService.php';
require_once __DIR__ . '/services/FebCategoryMigrationService.php';
require_once __DIR__ . '/repositories/FournisseurRepository.php';
require_once __DIR__ . '/services/SupplierFinancialIdentityPolicy.php';
require_once __DIR__.'/services/SupplierApprovalGuard.php';
require_once __DIR__.'/services/FebAssignmentBeneficiary.php';
require_once __DIR__ . '/services/ManualTreasuryService.php';
require_once __DIR__ . '/services/DisbursementPlanningIntegrityService.php';
require_once __DIR__ . '/services/FebBudgetAutoRejectionService.php';
require_once __DIR__ . '/services/TreasuryFundingReservationService.php';
require_once __DIR__ . '/services/FebPlanningSynchronizationService.php';
require_once __DIR__ . '/services/FebBeneficiarySyncService.php';
require_once __DIR__ . '/services/ExpiredFebPlanningAdjustmentService.php';

$pdo = \Database::getConnection();
require_once __DIR__.'/services/FebWorkflowGuard.php';
$_SESSION['feb_workflow_csrf'] ??= bin2hex(random_bytes(32));
$febWorkflowGuard = new FebWorkflowGuard($pdo);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    FinancialWorkflowProgress::step('Vérification de la session et de la décision FEB.');
    if (!hash_equals((string)$_SESSION['feb_workflow_csrf'], (string)($_POST['feb_workflow_csrf'] ?? ''))) {
        $_SESSION['flash_validation_demandes_finances']=['type'=>'error','message'=>'La session de validation a expiré. Rechargez la page et recommencez.'];
        header('Location: validation_demandes_finances.php'); exit;
    }
    $decisionId=(int)($_POST['request_id'] ?? 0);
    if ($decisionId > 0) {
        try {
            $febWorkflowGuard->acquire($decisionId);
            register_shutdown_function(static function() use ($febWorkflowGuard,$decisionId): void { try { $febWorkflowGuard->release($decisionId); } catch (Throwable $ignored) {} });
            $current=$pdo->prepare('SELECT request_type,status FROM employee_legal_requests WHERE id=?');
            $current->execute([$decisionId]);
            FebWorkflowGuard::assertPending($current->fetch(PDO::FETCH_ASSOC) ?: []);
        } catch (Throwable $e) {
            $_SESSION['flash_validation_demandes_finances']=['type'=>'error','message'=>$e->getMessage()];
            header('Location: validation_demandes_finances.php'); exit;
        }
    }
}

$canManageTreasury=AccessManager::hasPermission('acces_direction')||AccessManager::hasPermission('acces_admin');
$canOpenTreasury=$canManageTreasury||AccessManager::hasPermission('acces_tresorerie_lecture');
$canSeeTreasuryControl=$canOpenTreasury||AccessManager::hasPermission('acces_recommandations_tresorerie');
$treasuryAvailableToday=0.0;$treasuryRealEntries=0.0;$treasuryForecastEntries=0.0;$treasuryForecastOutputs=0.0;$treasuryControlReady=false;
try{$treasurySnapshot=(new ManualTreasuryService($pdo))->dashboard(date('Y-m-d'),date('Y-m-d'));$treasuryAvailableToday=(float)($treasurySnapshot['realBalance']??0);$treasuryRealEntries=(float)($treasurySnapshot['realEntriesBalance']??0);$treasuryForecastEntries=(float)($treasurySnapshot['totals']['forecast_in']??0);$treasuryForecastOutputs=(float)($treasurySnapshot['totals']['forecast_out']??0);$treasuryControlReady=true;}catch(Throwable $ignored){}
// Les migrations et synchronisations globales sont exécutées hors requête HTTP
// via scripts/production_maintenance.php afin de préserver le TTFB en production.
$manager = new EmployeeLegalRequestManager($pdo);
$febAutoRejectionResult = (new FebBudgetAutoRejectionService($pdo))->rejectExpired();
$treasuryFundingService=new TreasuryFundingReservationService($pdo);
$febPlanningSync=new FebPlanningSynchronizationService($pdo);
$treasuryFundingInvoices=[];$treasuryRealCoverage=['amount'=>0,'reserved'=>0,'remaining'=>0];
try{$treasuryRealCoverage=$treasuryFundingService->realEntriesCoverage();$treasuryFundingInvoices=$treasuryFundingService->forecastInvoices();}catch(Throwable $ignored){}
$urgentDisbursementService = new UrgentDisbursementService($pdo);
$febPriceChallengeService = new FebPriceChallengeService($pdo);
if(empty($_SESSION['feb_supplier_unblock_csrf']))$_SESSION['feb_supplier_unblock_csrf']=bin2hex(random_bytes(32));

// Jeux de données des sélecteurs enrichis du popup d'approbation.
$febApprovalSelectors = ['personnel'=>[],'suppliers'=>[],'chantiers'=>[],'devis_lines'=>[],'debourses'=>[],'debourse_lines'=>[]];
try {
    $febApprovalSelectors['personnel']=$pdo->query("SELECT p.id_personnel_soignant id,p.nom_personnel_soignant nom,p.tel_personnel_soignant telephone,p.matricule_personnel_soignant matricule,p.photo_personnel_soignant photo,COALESCE(f.lib_fonction,'') fonction FROM personnel p LEFT JOIN fonction f ON f.id_fonction=p.fonction_id WHERE (p.date_sortie IS NULL OR p.date_sortie='' OR p.date_sortie='0000-00-00') ORDER BY p.nom_personnel_soignant LIMIT 2500")->fetchAll(PDO::FETCH_ASSOC)?:[];
} catch(Throwable $e) {}
try {
    $supplierComplianceRepo=new FournisseurRepository($pdo);$supplierComplianceRepo->refreshDisbursementSelectionBlocks();
    $febApprovalSelectors['suppliers']=$pdo->query("SELECT id_fournisseur id,COALESCE(NULLIF(TRIM(nom_commercial),''),NULLIF(TRIM(raison_sociale),''),NULLIF(TRIM(CONCAT(person_first_name,' ',person_last_name)),''),CONCAT('Fournisseur #',id_fournisseur)) nom,nom_commercial,raison_sociale,telephone,email,adresse,photo_path,logo_path,COALESCE(NULLIF(photo_path,''),logo_path) photo,contact_nom contact,disbursement_selection_blocked blocked,disbursement_block_reason block_reason,disbursement_block_fiches block_fiches FROM fournisseur_banamur WHERE actif=1 AND validation_status='approved' ORDER BY nom LIMIT 2500")->fetchAll(PDO::FETCH_ASSOC)?:[];
} catch(Throwable $e) {}
try {
    $febApprovalSelectors['chantiers']=$pdo->query("SELECT id,code,titre,devis_id FROM chantier_banamur WHERE archived_at IS NULL ORDER BY code,titre LIMIT 2500")->fetchAll(PDO::FETCH_ASSOC)?:[];
    $febApprovalSelectors['devis_lines']=$pdo->query("SELECT DISTINCT ld.id,ld.devis_id,ld.designation,ld.total FROM ligne_devis_banamur ld LEFT JOIN devis_chantier_links dcl ON dcl.devis_id=ld.devis_id INNER JOIN chantier_banamur c ON (c.devis_id=ld.devis_id OR c.id=dcl.chantier_id) AND c.archived_at IS NULL ORDER BY ld.devis_id,ld.designation LIMIT 10000")->fetchAll(PDO::FETCH_ASSOC)?:[];
    $official=FinancialPlanningSeparationService::officialDeboursePredicate('db');
    $febApprovalSelectors['debourses']=$pdo->query("SELECT DISTINCT db.id,db.devis_id,db.ligne_devis_id,db.montant_debourse,COALESCE(NULLIF(TRIM(ld.designation),''),CONCAT('Déboursé #',db.id)) label FROM debourse_banamur db LEFT JOIN ligne_devis_banamur ld ON ld.id=db.ligne_devis_id LEFT JOIN devis_chantier_links dcl ON dcl.devis_id=db.devis_id INNER JOIN chantier_banamur c ON (c.devis_id=db.devis_id OR c.id=dcl.chantier_id) AND c.archived_at IS NULL WHERE $official ORDER BY db.devis_id,db.ligne_devis_id,db.id DESC LIMIT 5000")->fetchAll(PDO::FETCH_ASSOC)?:[];
    $febApprovalSelectors['debourse_lines']=$pdo->query("SELECT ldb.id,ldb.debourse_id,ldb.designation,ldb.categorie,ldb.montant FROM ligne_debourse_banamur ldb INNER JOIN debourse_banamur db ON db.id=ldb.debourse_id WHERE $official ORDER BY ldb.debourse_id,ldb.designation LIMIT 10000")->fetchAll(PDO::FETCH_ASSOC)?:[];
} catch(Throwable $e) {}

$actor = [
    'user_id' => (int) ($_SESSION['user_id'] ?? 0),
    'name' => trim((string) ($_SESSION['user_name'] ?? '')),
];
if ($actor['name'] === '') {
    $actor['name'] = trim((string) (($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? '')));
}

function statusBadge(string $status): array
{
    $status = strtolower(trim($status));
    $map = [
        'submitted' => ['label' => 'À valider', 'class' => 'bg-amber-100 text-amber-800 border-amber-200'],
        'details_requested' => ['label' => 'Compléments demandés', 'class' => 'bg-sky-100 text-sky-800 border-sky-200'],
        'approved' => ['label' => 'Approuvée', 'class' => 'bg-emerald-100 text-emerald-800 border-emerald-200'],
        'rejected' => ['label' => 'Refusée', 'class' => 'bg-rose-100 text-rose-800 border-rose-200'],
        'cancelled' => ['label' => 'Annulée', 'class' => 'bg-slate-100 text-slate-700 border-slate-200'],
    ];

    return $map[$status] ?? ['label' => $status ?: '-', 'class' => 'bg-slate-100 text-slate-700 border-slate-200'];
}

function febMoneyInput($value): string
{
    $raw = str_replace([" ", "\xc2\xa0"], '', (string) $value);
    $raw = str_replace(',', '.', $raw);
    return number_format((float) $raw, 2, '.', '');
}

function febCompleteSelectedSupplier(PDO $pdo, array $post, array $files): void
{
    $supplierId=(int)($post['fd_fournisseur_id']??0);
    if($supplierId<=0||empty($post['supplier_completion_enabled']))return;
    $stmt=$pdo->prepare('SELECT * FROM fournisseur_banamur WHERE id_fournisseur=:id AND actif=1 AND validation_status="approved" LIMIT 1');
    $stmt->execute(['id'=>$supplierId]);$supplier=$stmt->fetch(PDO::FETCH_ASSOC)?:null;
    if(!$supplier)throw new RuntimeException('Le fournisseur à compléter est introuvable ou non validé.');
    SupplierFinancialIdentityPolicy::assertCompanyIdentity($supplier);
    $updates=[];$params=['id'=>$supplierId];
    foreach(['telephone'=>'supplier_completion_phone','email'=>'supplier_completion_email','adresse'=>'supplier_completion_address'] as $column=>$field){
        if(trim((string)($supplier[$column]??''))===''&&trim((string)($post[$field]??''))!==''){$updates[]="$column=:$column";$params[$column]=trim((string)$post[$field]);}
    }
    if(trim((string)($supplier['photo_path']??''))===''&&trim((string)($supplier['logo_path']??''))===''&&!empty($files['supplier_completion_photo'])&&(int)($files['supplier_completion_photo']['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_OK){
        $ext=strtolower(pathinfo((string)$files['supplier_completion_photo']['name'],PATHINFO_EXTENSION));if(!in_array($ext,['jpg','jpeg','png','webp'],true))throw new RuntimeException('La photo du fournisseur doit être une image JPG, PNG ou WebP.');
        $dir=__DIR__.'/uploads/fournisseurs';if(!is_dir($dir)&&!mkdir($dir,0775,true)&&!is_dir($dir))throw new RuntimeException('Impossible de préparer le stockage de la photo fournisseur.');
        $name='supplier_'.$supplierId.'_'.bin2hex(random_bytes(6)).'.'.$ext;if(!move_uploaded_file((string)$files['supplier_completion_photo']['tmp_name'],$dir.'/'.$name))throw new RuntimeException('Impossible d’enregistrer la photo du fournisseur.');
        $updates[]='photo_path=:photo_path';$params['photo_path']='uploads/fournisseurs/'.$name;
    }
    if($updates){$pdo->prepare('UPDATE fournisseur_banamur SET '.implode(',',$updates).' WHERE id_fournisseur=:id')->execute($params);}
    $stmt->execute(['id'=>$supplierId]);$updated=$stmt->fetch(PDO::FETCH_ASSOC)?:[];$missing=[];
    if(trim((string)($updated['nom_commercial']??''))===''&&trim((string)($updated['raison_sociale']??''))==='')$missing[]='nom';
    foreach(['telephone'=>'téléphone','email'=>'e-mail','adresse'=>'adresse'] as $key=>$label)if(trim((string)($updated[$key]??''))==='')$missing[]=$label;
    if(trim((string)($updated['photo_path']??''))===''&&trim((string)($updated['logo_path']??''))==='')$missing[]='photo ou logo';
    if($missing)throw new RuntimeException('Complétez les données obligatoires du fournisseur : '.implode(', ',$missing).'.');
}

function febBuildEditedPayload(array $currentPayload, array $post): array
{
    $payload = $currentPayload;
    foreach (['description', 'impact', 'date_livraison', 'frequence'] as $key) {
        if (array_key_exists($key, $post)) {
            $payload[$key] = trim((string) $post[$key]);
        }
    }
    foreach (['planned_start_date', 'planned_end_date'] as $dateKey) {
        if (!array_key_exists($dateKey, $post)) continue;
        $dateValue = trim((string)$post[$dateKey]);
        if ($dateValue !== '' && !FebWorkflowGuard::validDate($dateValue)) {
            throw new DomainException('La période globale renseignée est invalide.');
        }
        $payload[$dateKey] = $dateValue;
    }
    if (!empty($payload['planned_start_date']) && !empty($payload['planned_end_date']) && $payload['planned_end_date'] < $payload['planned_start_date']) {
        throw new DomainException('La fin de la période globale doit être postérieure ou égale au début.');
    }
    if (array_key_exists('montant', $post)) {
        if (!array_key_exists('initial_requested_amount', $payload)) {
            $payload['initial_requested_amount'] = (float) ($currentPayload['montant'] ?? 0);
        }
        $payload['montant'] = (float) febMoneyInput($post['montant']);
        $proposal = is_array($payload['proposed_debourse'] ?? null) ? (array)$payload['proposed_debourse'] : [];
        $proposalLines = array_values(array_filter((array)($proposal['lines'] ?? []), 'is_array'));
        if ($proposalLines) {
            $oldTotal = array_sum(array_map(static fn(array $line): float => max(0, (float)($line['montant'] ?? 0)), $proposalLines));
            if (count($proposalLines) === 1 || $oldTotal <= 0) {
                $proposalLines[0]['montant'] = $payload['montant'];
            } else {
                // Le montant confirmé devient la référence. Les lignes conservent
                // leur répartition relative afin que FEB, déboursé et fiche restent cohérents.
                $remaining = $payload['montant'];
                $lastIndex = count($proposalLines) - 1;
                foreach ($proposalLines as $index => &$line) {
                    $lineAmount = $index === $lastIndex
                        ? $remaining
                        : round($payload['montant'] * max(0, (float)($line['montant'] ?? 0)) / $oldTotal, 2);
                    $line['montant'] = max(0, $lineAmount);
                    $remaining = round($remaining - $line['montant'], 2);
                }
                unset($line);
            }
            $proposal['lines'] = $proposalLines;
            $proposal['total_amount'] = $payload['montant'];
            $payload['proposed_debourse'] = $proposal;
        }
    }
    if (isset($post['fd_date_decaissement_minimum']) || isset($post['fd_details_demande']) || isset($post['fd_montant_fiche']) || isset($post['fd_beneficiary_mode']) || isset($post['fd_mode_paiement'])) {
        $draft = is_array($payload['provisional_decaissement'] ?? null) ? (array)$payload['provisional_decaissement'] : [];
        $draft['active'] = !empty($post['generate_decaissement']) || !empty($draft['active']);
        if (isset($post['fd_date_decaissement_minimum'])) {
            $date = trim((string)$post['fd_date_decaissement_minimum']);
            if ($date !== '' && !FebWorkflowGuard::validDate($date)) {
                throw new DomainException('La nouvelle date prévue de décaissement est invalide.');
            }
            $draft['date_decaissement_minimum'] = $date !== '' ? $date : null;
        }
        if (isset($post['fd_details_demande'])) $draft['details_demande'] = trim((string)$post['fd_details_demande']);
        if (isset($post['fd_montant_fiche'])) $draft['montant_fiche'] = (float)febMoneyInput($post['fd_montant_fiche']);
        foreach (['fd_personnel_id'=>'personnel_id','fd_chantier_id'=>'chantier_id','fd_ligne_devis_id'=>'ligne_devis_id','fd_debourse_id'=>'debourse_id','fd_ligne_debourse_id'=>'ligne_debourse_id','fd_fournisseur_id'=>'fournisseur_id'] as $postKey=>$draftKey) {
            if (isset($post[$postKey])) $draft[$draftKey] = (int)$post[$postKey];
        }
        if (isset($post['fd_beneficiary_mode'])) $draft['beneficiary_mode'] = trim((string)$post['fd_beneficiary_mode']);
        if (isset($post['fd_mode_paiement'])) $draft['mode_paiement'] = trim((string)$post['fd_mode_paiement']);
        if (isset($post['fd_beneficiaire_fiche'])) $draft['beneficiaire_fiche'] = trim((string)$post['fd_beneficiaire_fiche']);
        if (isset($post['fd_tel_beneficiaire_fiche'])) $draft['tel_beneficiaire_fiche'] = trim((string)$post['fd_tel_beneficiaire_fiche']);
        $payload['provisional_decaissement'] = $draft;
    }

    // Une modification du montant FEB dans le popup est aussi la valeur de la
    // fiche de décaissement : aucun décalage ne doit être possible entre les deux.
    if (array_key_exists('montant', $post)) {
        $draft = is_array($payload['provisional_decaissement'] ?? null) ? (array)$payload['provisional_decaissement'] : [];
        $draft['montant_fiche'] = (float)$payload['montant'];
        $payload['provisional_decaissement'] = $draft;
    }

    $postedBesoins = is_array($post['besoins'] ?? null) ? (array) $post['besoins'] : [];
    if ($postedBesoins !== []) {
        $besoins = [];
        foreach ($postedBesoins as $row) {
            if (!is_array($row)) {
                continue;
            }
            $libelle = trim((string) ($row['libelle'] ?? ''));
            $type = trim((string) ($row['type'] ?? ''));
            $categorie = trim((string) ($row['categorie'] ?? ''));
            $quantite = (float) str_replace(',', '.', (string) ($row['quantite'] ?? 0));
            $fournisseurNom = trim((string) ($row['fournisseur_nom'] ?? ''));
            $prixUnitaire = (float) febMoneyInput($row['prix_unitaire'] ?? 0);
            $telephone = trim((string) ($row['telephone'] ?? ''));
            if ($libelle === '' && $type === '' && $quantite <= 0 && $fournisseurNom === '' && $prixUnitaire <= 0) {
                continue;
            }
            $item = [
                'libelle' => $libelle,
                'type' => $type,
                'categorie' => $categorie,
                'quantite' => $quantite,
            ];
            if ($fournisseurNom !== '' || $prixUnitaire > 0 || $telephone !== '') {
                $item['has_fournisseur'] = true;
                $item['fournisseur'] = ['nom' => $fournisseurNom, 'prix_unitaire' => $prixUnitaire, 'telephone' => $telephone];
            }
            $besoins[] = $item;
        }
        $payload['besoins'] = $besoins;
    }

    if (isset($post['feb_integration_config'])) {
        $selectedChantierId = (int) ($post['fd_chantier_id'] ?? 0);
        if ($selectedChantierId <= 0) $selectedChantierId = (int) ($post['feb_target_chantier_id'] ?? $payload['chantier_id'] ?? 0);
        if ($selectedChantierId <= 0) throw new DomainException('Sélectionnez le chantier réel auquel cette ancienne FEB doit être rattachée.');
        $payload['chantier_id'] = $selectedChantierId;
        $proposal = is_array($payload['proposed_debourse'] ?? null) ? (array) $payload['proposed_debourse'] : [];
        $proposal['devis_id'] = (int) ($post['feb_target_devis_id'] ?? 0);
        $proposal['ligne_devis_mode'] = in_array((string) ($post['feb_ligne_devis_mode'] ?? ''), ['existing', 'new'], true) ? (string) $post['feb_ligne_devis_mode'] : 'new';
        $proposal['ligne_devis_id'] = $proposal['ligne_devis_mode'] === 'existing' ? (int) ($post['feb_target_ligne_devis_id'] ?? 0) : 0;
        $proposal['ligne_devis_proposal'] = trim((string) ($post['feb_new_ligne_devis_label'] ?? $proposal['ligne_devis_proposal'] ?? $proposal['title'] ?? ''));
        $proposal['debourse_mode'] = in_array((string) ($post['feb_debourse_mode'] ?? ''), ['existing', 'new'], true) ? (string) $post['feb_debourse_mode'] : 'new';
        $proposal['existing_debourse_id'] = $proposal['debourse_mode'] === 'existing' ? (int) ($post['feb_target_debourse_id'] ?? 0) : 0;
        $proposal['title'] = trim((string) ($post['feb_debourse_title'] ?? $proposal['title'] ?? ''));
        $postedLines = is_array($post['feb_debourse_lines'] ?? null) ? (array) $post['feb_debourse_lines'] : [];
        $lines = [];
        foreach ($postedLines as $index => $row) {
            if (!is_array($row)) continue;
            $designation = trim((string) ($row['designation'] ?? ''));
            $categorie = trim((string) ($row['categorie'] ?? ''));
            $montant = (float) febMoneyInput($row['montant'] ?? 0);
            $lineStart = trim((string)($payload['planned_start_date'] ?? ''));
            $lineEnd = trim((string)($payload['planned_end_date'] ?? ''));
            if ($designation === '' || $montant <= 0) {
                throw new DomainException('La ligne d’intégration #' . ((int) $index + 1) . ' doit contenir une désignation et un montant positif.');
            }
            if(!in_array($categorie,FebCategoryMigrationService::CATEGORIES,true))throw new DomainException('Sélectionnez une catégorie valide pour la ligne d’intégration #'.((int)$index+1).'.');
            if(!FebWorkflowGuard::validDate($lineStart)||!FebWorkflowGuard::validDate($lineEnd)||$lineEnd<$lineStart)throw new DomainException('Le planning de la ligne d’intégration #'.((int)$index+1).' est invalide.');
            $lines[] = ['designation'=>$designation,'categorie'=>$categorie,'montant'=>$montant,'planned_start_date'=>$lineStart,'planned_end_date'=>$lineEnd];
        }
        if (!$lines) throw new DomainException('Ajoutez au moins une ligne au déboursé à intégrer.');
        $proposal['lines'] = $lines;
        $proposal['total_amount'] = array_sum(array_column($lines, 'montant'));
        $proposal['configured_at_validation'] = true;
        $payload['proposed_debourse'] = $proposal;
        $payload['montant'] = (float) $proposal['total_amount'];
    }

    return FebCategoryMigrationService::normalizePayload($payload);
}

function febDescribePayloadChanges(array $before, array $after): string
{
    $labels = [
        'description'=>'description','impact'=>'impact / justification','montant'=>'montant demandé',
        'date_livraison'=>'date de livraison','frequence'=>'fréquence',
    ];
    $changes = [];
    foreach ($labels as $key=>$label) {
        $old = trim((string)($before[$key]??''));
        $new = trim((string)($after[$key]??''));
        if ($old !== $new) $changes[] = ucfirst($label).' : « '.($old!==''?$old:'non renseigné').' » → « '.($new!==''?$new:'non renseigné').' »';
    }
    $oldDraft = (array)($before['provisional_decaissement']??[]);
    $newDraft = (array)($after['provisional_decaissement']??[]);
    foreach (['fournisseur_id'=>'fournisseur','personnel_id'=>'personnel','beneficiary_mode'=>'type de bénéficiaire','chantier_id'=>'chantier','ligne_devis_id'=>'ligne de devis','debourse_id'=>'déboursé','ligne_debourse_id'=>'poste financier','date_decaissement_minimum'=>'date prévue de décaissement','montant_fiche'=>'montant de la fiche','details_demande'=>'détail de la fiche'] as $key=>$label) {
        $old = trim((string)($oldDraft[$key]??''));
        $new = trim((string)($newDraft[$key]??''));
        if ($old !== $new) $changes[] = ucfirst($label).' : « '.($old!==''?$old:'non renseigné').' » → « '.($new!==''?$new:'non renseigné').' »';
    }
    return $changes ? implode(' ; ', $changes) : 'Ajustement technique de la FEB sans changement de valeur visible.';
}

function febTextMatch(string $haystack, string $needle): bool
{
    $needle = trim($needle);
    if ($needle === '') {
        return true;
    }
    if (function_exists('mb_strtolower')) {
        return strpos((string) mb_strtolower($haystack, 'UTF-8'), (string) mb_strtolower($needle, 'UTF-8')) !== false;
    }
    return stripos($haystack, $needle) !== false;
}

function febChantierSearchLabel(string $value): string
{
    $value = trim($value);
    if ($value !== '' && (preg_match('/\bCH0*66\b/i', $value) || stripos($value, 'BUREAU BANAMUR') !== false)) {
        return trim($value . ' Chantier Bureau — CH0066');
    }
    return $value;
}

function febRowSearchHaystack(array $row): string
{
    $payload = (array) ($row['payload'] ?? []);
    $display = (array) ($row['feb_display'] ?? EmployeeLegalRequestManager::extractFebDisplay($row));
    $financial = (array)($row['financial_search'] ?? []);
    return implode(' ', [
        $row['id'] ?? '',
        $row['title'] ?? '',
        $row['requester_name'] ?? '',
        $row['requester_email'] ?? '',
        $payload['departement_label'] ?? '',
        $payload['service_employe'] ?? '',
        $display['demandeur'] ?? '',
        $display['description'] ?? '',
        $display['chantier_label'] ?? '',
        $display['numero_bon_commande_interne'] ?? '',
        $display['code_autorisation_feb'] ?? '',
        $financial['chantier'] ?? '',
        $financial['debourse'] ?? '',
        $financial['ligne_debourse'] ?? '',
    ]);
}

function febRowsTotalAmount(array $rows): float
{
    $total = 0.0;
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $display = (array) ($row['feb_display'] ?? EmployeeLegalRequestManager::extractFebDisplay($row));
        $total += (float) ($display['montant'] ?? 0);
    }
    return $total;
}

function febApprovalBusinessContext(PDO $pdo, array $payload, array $draft, array $proposal): array
{
    static $personnelCache = [];
    static $chantierCache = [];
    static $devisCache = [];
    static $devisTypeCache = [];
    static $ligneDevisCache = [];
    static $debourseCache = [];
    static $ligneDebourseCache = [];

    $fetch = static function (string $sql, int $id, array &$cache) use ($pdo): array {
        if ($id <= 0) return [];
        if (array_key_exists($id, $cache)) return $cache[$id];
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['id' => $id]);
            return $cache[$id] = ($stmt->fetch(PDO::FETCH_ASSOC) ?: []);
        } catch (Throwable $e) {
            return $cache[$id] = [];
        }
    };
    $firstPositive = static function (...$values): int {
        foreach ($values as $value) {
            if ((int)$value > 0) return (int)$value;
        }
        return 0;
    };

    $personnelId = (int)($draft['personnel_id'] ?? 0);
    $personnel = $fetch(
        'SELECT p.nom_personnel_soignant AS nom,
                COALESCE(NULLIF(TRIM(f.lib_fonction), ""), "") AS fonction,
                COALESCE(NULLIF(TRIM(s.lib_service), ""), "") AS service
         FROM personnel p
         LEFT JOIN fonction f ON f.id_fonction=p.fonction_id
         LEFT JOIN service s ON s.id_service=p.service_id
         WHERE p.id_personnel_soignant=:id LIMIT 1',
        $personnelId,
        $personnelCache
    );

    $chantierId = $firstPositive($draft['chantier_id'] ?? 0, $payload['chantier_id'] ?? 0);
    $chantier = $fetch(
        'SELECT ch.id,ch.code,ch.titre,ch.devis_id,
                COALESCE(NULLIF(TRIM(o.reference_offre), ""), "") AS projet
         FROM chantier_banamur ch
         LEFT JOIN devis_banamur d ON d.id=ch.devis_id
         LEFT JOIN offre_banamur o ON o.id_offre=d.offre_id
         WHERE ch.id=:id LIMIT 1',
        $chantierId,
        $chantierCache
    );

    $debourseId = $firstPositive($draft['debourse_id'] ?? 0, $proposal['existing_debourse_id'] ?? 0);
    $debourse = $fetch(
        'SELECT db.id,db.devis_id,db.ligne_devis_id,
                COALESCE(NULLIF(TRIM(ld.designation), ""), CONCAT("Déboursé ", db.id)) AS designation
         FROM debourse_banamur db
         LEFT JOIN ligne_devis_banamur ld ON ld.id=db.ligne_devis_id
         WHERE db.id=:id LIMIT 1',
        $debourseId,
        $debourseCache
    );

    $devisId = $firstPositive($proposal['devis_id'] ?? 0, $draft['devis_id'] ?? 0, $debourse['devis_id'] ?? 0, $chantier['devis_id'] ?? 0);
    $devis = $fetch(
        'SELECT d.id,d.numero_devis,d.offre_id
         FROM devis_banamur d WHERE d.id=:id LIMIT 1',
        $devisId,
        $devisCache
    );
    $devisClassification = $fetch(
        'SELECT devis_type FROM devis_offer_classifications WHERE devis_id=:id LIMIT 1',
        $devisId,
        $devisTypeCache
    );
    $devisType = strtoupper(trim((string)($devisClassification['devis_type'] ?? '')));
    if ($devisType === '') {
        $devisType = $devisId > 0 && $devisId === (int)($chantier['devis_id'] ?? 0) ? 'PRINCIPAL' : 'NON_CLASSÉ';
    }
    $devisTypeLabel = $devisType === 'PRINCIPAL' ? 'Devis principal' : ($devisType === 'TS' ? 'Devis TS' : 'Devis non classé');
    $devisNumber = trim((string)($devis['numero_devis'] ?? ''));

    $ligneDevisId = $firstPositive($draft['ligne_devis_id'] ?? 0, $proposal['ligne_devis_id'] ?? 0, $debourse['ligne_devis_id'] ?? 0);
    $ligneDevis = $fetch(
        'SELECT id,designation FROM ligne_devis_banamur WHERE id=:id LIMIT 1',
        $ligneDevisId,
        $ligneDevisCache
    );

    $ligneDebourseId = (int)($draft['ligne_debourse_id'] ?? 0);
    $ligneDebourse = $fetch(
        'SELECT id,designation FROM ligne_debourse_banamur WHERE id=:id LIMIT 1',
        $ligneDebourseId,
        $ligneDebourseCache
    );

    $personnelParts = array_values(array_filter([
        trim((string)($personnel['nom'] ?? '')),
        trim((string)($personnel['fonction'] ?? '')),
        trim((string)($personnel['service'] ?? '')),
    ], static fn(string $value): bool => $value !== ''));
    $chantierTitle = trim((string)($chantier['titre'] ?? ''));
    $projectTitle = trim((string)($chantier['projet'] ?? ''));
    if ($projectTitle !== '' && ($chantierTitle === '' || preg_match('/^CHANTIER\\s+N[°O]/iu', $chantierTitle))) {
        $chantierTitle = $projectTitle;
    }
    $chantierCode = trim((string)($chantier['code'] ?? ''));

    return [
        'beneficiary' => $personnelParts ? implode(' · ', $personnelParts) : '',
        'chantier' => $chantierTitle !== ''
            ? $chantierTitle . ($chantierCode !== '' ? ' (' . $chantierCode . ')' : '')
            : '',
        'devis' => $devisId > 0
            ? $devisTypeLabel . ' — ' . ($devisNumber !== '' ? $devisNumber : ('identifiant #' . $devisId))
            : '',
        'devis_pending' => $devisId <= 0,
        'ligne_devis' => trim((string)($ligneDevis['designation'] ?? '')) !== ''
            ? trim((string)$ligneDevis['designation']) . ' (Ligne #' . $ligneDevisId . ')'
            : '',
        'debourse' => trim((string)($debourse['designation'] ?? '')) !== ''
            ? trim((string)$debourse['designation']) . ' (Déboursé #' . $debourseId . ')'
            : '',
        'ligne_debourse' => trim((string)($ligneDebourse['designation'] ?? '')) !== ''
            ? trim((string)$ligneDebourse['designation']) . ' (Ligne #' . $ligneDebourseId . ')'
            : '',
    ];
}

function febSafeUploadUrl(?string $url): string
{
    $url = trim((string) $url);
    if ($url === '') {
        return '';
    }
    // Dedicated authenticated download; never allow arbitrary application URLs here.
    if (preg_match('~^/request/download_rh_financial_point\.php\?point_id=[1-9][0-9]*&revision=[1-9][0-9]*$~D',$url)) return $url;
    if (preg_match('~^https?://~i', $url)) {
        return $url;
    }
    if (strpos($url, '/uploads/') === 0 || strpos($url, 'uploads/') === 0 || strpos($url, '/storage/') === 0 || strpos($url, 'storage/') === 0) {
        return $url[0] === '/' ? $url : '/' . $url;
    }
    return '';
}

function febDocumentName(array $document, string $fallback): string
{
    $name = trim((string) ($document['name'] ?? $document['original_name'] ?? $document['stored_name'] ?? ''));
    return $name !== '' ? $name : $fallback;
}

function febAddDocumentItem(array &$items, string $type, array $document, string $fallbackName, string $number = ''): void
{
    $url = febSafeUploadUrl((string) ($document['url'] ?? $document['path'] ?? ''));
    $items[] = [
        'type' => $type,
        'name' => febDocumentName($document, $fallbackName),
        'number' => trim($number),
        'url' => $url,
    ];
}

function febBuildDocumentList(array $payload): array
{
    $items = [];
    if ((int)($payload['rh_financial_point_id'] ?? 0)>0 && (int)($payload['rh_financial_revision'] ?? 0)>0) {
        $document=(array)($payload['justificatifs'][0] ?? []);
        $document['url']='/request/download_rh_financial_point.php?point_id='.(int)$payload['rh_financial_point_id'].'&revision='.(int)$payload['rh_financial_revision'];
        $document['name']='Point RH signé par les RH et le DG';
        febAddDocumentItem($items,'Justificatif signé',$document,$document['name']);
        return $items;
    }

    $pvDocument = is_array($payload['pv_reception_document'] ?? null) ? (array) $payload['pv_reception_document'] : [];
    if ($pvDocument) {
        febAddDocumentItem($items, 'PV de réception', $pvDocument, 'PV de réception', (string) ($payload['pv_reception_number'] ?? ''));
    }

    $invoiceDocument = is_array($payload['invoice_document'] ?? null) ? (array) $payload['invoice_document'] : [];
    if ($invoiceDocument) {
        febAddDocumentItem($items, 'Facture', $invoiceDocument, 'Facture', (string) ($payload['invoice_number'] ?? ''));
    }

    $supportNumber = (string) ($payload['supporting_document_number'] ?? '');
    foreach (array_values(array_filter((array) ($payload['justificatifs'] ?? []), static fn($item): bool => is_array($item))) as $idx => $document) {
        febAddDocumentItem($items, 'Justificatif', (array) $document, 'Justificatif ' . ($idx + 1), $supportNumber);
    }

    $globalProof = is_array($payload['global_justificatif'] ?? null) ? (array) $payload['global_justificatif'] : [];
    if ($globalProof) {
        febAddDocumentItem($items, 'Justificatif BFEB', $globalProof, 'Justificatif BFEB');
    }

    foreach (array_values(array_filter((array) ($payload['besoins'] ?? []), static fn($item): bool => is_array($item))) as $idx => $need) {
        $proof = is_array($need['justificatif_devis'] ?? null) ? (array) $need['justificatif_devis'] : [];
        if (!$proof) continue;
        $needLabel = trim((string) ($need['libelle'] ?? $need['designation'] ?? ''));
        febAddDocumentItem($items, 'Justificatif BFEB', $proof, $needLabel !== '' ? 'Justificatif · ' . $needLabel : 'Justificatif du besoin ' . ($idx + 1));
    }

    $unique = [];
    foreach ($items as $item) {
        $key = trim((string) ($item['url'] ?? ''));
        if ($key === '') $key = strtolower((string) ($item['type'] ?? '') . '|' . (string) ($item['name'] ?? ''));
        if (!isset($unique[$key])) $unique[$key] = $item;
    }
    return array_values($unique);
}

function febFormatDateLabel(?string $value): string
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

function febInitials(string $name): string
{
    $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
    if ($name === '') {
        return 'FEB';
    }

    $parts = preg_split('/\s+/', $name) ?: [];
    $letters = [];
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }
        $letters[] = function_exists('mb_substr') ? mb_substr($part, 0, 1, 'UTF-8') : substr($part, 0, 1);
        if (count($letters) >= 2) {
            break;
        }
    }

    $initials = implode('', $letters);
    return function_exists('mb_strtoupper') ? mb_strtoupper($initials, 'UTF-8') : strtoupper($initials);
}

function febExcerpt(string $text, int $maxWords = 24): string
{
    $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
    if ($text === '') {
        return 'Aucune description renseignée.';
    }

    $words = preg_split('/\s+/', $text) ?: [];
    if (count($words) <= $maxWords) {
        return $text;
    }

    return implode(' ', array_slice($words, 0, $maxWords)) . '...';
}

function febResolveUserPhotoPath(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    if (preg_match('~^(https?:|data:|/)~i', $raw)) {
        return $raw;
    }

    $projectRoot = realpath(__DIR__);
    if (!$projectRoot) {
        return $raw;
    }

    $normalized = ltrim(str_replace('\\', '/', $raw), '/');
    $candidates = [
        $normalized,
        'photo/' . $normalized,
        'uploads/' . $normalized,
        'uploads/rh/' . $normalized,
        'uploads/photo/' . $normalized,
        'uploads/rh/photo/' . $normalized,
        'storage/' . $normalized,
    ];

    foreach ($candidates as $candidate) {
        $absolute = $projectRoot . '/' . ltrim($candidate, '/');
        if (is_file($absolute)) {
            return ltrim(str_replace('\\', '/', $candidate), '/');
        }
    }

    return '';
}

function febRequesterProfiles(PDO $pdo, array $rows): array
{
    $requesterIds = [];
    $personnelIds = [];
    $matricules = [];

    foreach ($rows as $row) {
        $payload = (array) ($row['payload'] ?? []);

        $requesterId = (int) ($row['requester_user_id'] ?? 0);
        if ($requesterId > 0) {
            $requesterIds[$requesterId] = $requesterId;
        }

        $personnelId = (int) ($payload['personnel_id'] ?? 0);
        if ($personnelId > 0) {
            $personnelIds[$personnelId] = $personnelId;
        }

        $matricule = strtoupper(trim((string) ($payload['matricule'] ?? '')));
        if ($matricule !== '') {
            $matricules[$matricule] = $matricule;
        }
    }

    $userPersonnelById = [];
    if ($requesterIds) {
        try {
            $userColumns = $pdo->query('SHOW COLUMNS FROM user_devis_banamur')->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
            if (in_array('personnel_id', $userColumns, true)) {
                $placeholders = [];
                $params = [];
                foreach (array_values($requesterIds) as $idx => $id) {
                    $key = ':u' . $idx;
                    $placeholders[] = $key;
                    $params[$key] = $id;
                }

                $stmt = $pdo->prepare('SELECT id, personnel_id FROM user_devis_banamur WHERE id IN (' . implode(',', $placeholders) . ')');
                $stmt->execute($params);
                foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $userRow) {
                    $uid = (int) ($userRow['id'] ?? 0);
                    $pid = (int) ($userRow['personnel_id'] ?? 0);
                    if ($uid > 0 && $pid > 0) {
                        $userPersonnelById[$uid] = $pid;
                        $personnelIds[$pid] = $pid;
                    }
                }
            }
        } catch (Throwable $e) {
            $userPersonnelById = [];
        }
    }

    if (!$personnelIds && !$matricules) {
        return [];
    }

    try {
        $columns = $pdo->query('SHOW COLUMNS FROM personnel')->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
        if (!in_array('photo_personnel_soignant', $columns, true)) {
            return [];
        }

        $where = [];
        $params = [];
        if ($personnelIds) {
            $idPlaceholders = [];
            foreach (array_values($personnelIds) as $idx => $id) {
                $key = ':pid' . $idx;
                $idPlaceholders[] = $key;
                $params[$key] = $id;
            }
            $where[] = 'id_personnel_soignant IN (' . implode(',', $idPlaceholders) . ')';
        }

        if ($matricules) {
            $matriculePlaceholders = [];
            foreach (array_values($matricules) as $idx => $matricule) {
                $key = ':mat' . $idx;
                $matriculePlaceholders[] = $key;
                $params[$key] = $matricule;
            }
            $where[] = 'UPPER(TRIM(matricule_personnel_soignant)) IN (' . implode(',', $matriculePlaceholders) . ')';
        }

        if (!$where) {
            return [];
        }

        $stmt = $pdo->prepare('
            SELECT id_personnel_soignant, matricule_personnel_soignant, photo_personnel_soignant
            FROM personnel
            WHERE ' . implode(' OR ', $where) . '
        ');
        $stmt->execute($params);

        $byPersonnelId = [];
        $byMatricule = [];
        foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $personnelRow) {
            $photoUrl = febResolveUserPhotoPath((string) ($personnelRow['photo_personnel_soignant'] ?? ''));
            if ($photoUrl === '') {
                continue;
            }

            $pid = (int) ($personnelRow['id_personnel_soignant'] ?? 0);
            $matricule = strtoupper(trim((string) ($personnelRow['matricule_personnel_soignant'] ?? '')));
            if ($pid > 0) {
                $byPersonnelId[$pid] = $photoUrl;
            }
            if ($matricule !== '') {
                $byMatricule[$matricule] = $photoUrl;
            }
        }

        $profiles = [];
        foreach ($rows as $row) {
            $rowId = (int) ($row['id'] ?? 0);
            if ($rowId <= 0) {
                continue;
            }

            $payload = (array) ($row['payload'] ?? []);
            $requesterId = (int) ($row['requester_user_id'] ?? 0);
            $personnelId = (int) ($payload['personnel_id'] ?? 0);
            if ($personnelId <= 0 && $requesterId > 0) {
                $personnelId = (int) ($userPersonnelById[$requesterId] ?? 0);
            }

            $matricule = strtoupper(trim((string) ($payload['matricule'] ?? '')));
            $photoUrl = '';
            if ($personnelId > 0 && !empty($byPersonnelId[$personnelId])) {
                $photoUrl = $byPersonnelId[$personnelId];
            } elseif ($matricule !== '' && !empty($byMatricule[$matricule])) {
                $photoUrl = $byMatricule[$matricule];
            }

            $profiles[$rowId] = [
                'personnel_id' => $personnelId,
                'photo_url' => $photoUrl,
            ];
        }

        return $profiles;
    } catch (Throwable $e) {
        return [];
    }
}

function febFinanceDecaissementTouched(array $post, array $files): bool
{
    return !empty($post['generate_decaissement']);
}

function febFinanceProvisionalDecaissementPayload(array $request): array
{
    $payload = (array) ($request['payload'] ?? []);
    $draft = is_array($payload['provisional_decaissement'] ?? null) ? (array) $payload['provisional_decaissement'] : [];
    if (empty($draft['active'])) {
        return [];
    }

    return $draft;
}

function febFinanceApplyProvisionalDecaissement(array $request, array $post): array
{
    if (!empty($post['generate_decaissement'])) {
        return $post;
    }

    $draft = febFinanceProvisionalDecaissementPayload($request);
    if (!$draft) {
        return $post;
    }

    $merged = $post;
    $merged['generate_decaissement'] = '1';
    $payload=(array)($request['payload']??[]);$firstSupplier=(array)(($payload['besoins'][0]['fournisseur']??[]));
    $globalSupplierId=FebAssignmentBeneficiary::supplierId($payload);
    $defaults = [
        'fd_beneficiary_mode' => (string) ($draft['beneficiary_mode'] ?? 'personnel'),
        'fd_personnel_id' => (string) ($draft['personnel_id'] ?? 0),
        'fd_chantier_id' => (string) ($draft['chantier_id'] ?? 0),
        'fd_debourse_id' => (string) ($draft['debourse_id'] ?? 0),
        'fd_ligne_debourse_id' => (string) ($draft['ligne_debourse_id'] ?? 0),
        'fd_fournisseur_id' => (string) $globalSupplierId,
        'fd_montant_fiche' => (string) ($draft['montant_fiche'] ?? ''),
        'fd_mode_paiement' => (string) ($draft['mode_paiement'] ?? ''),
        'fd_date_decaissement_minimum' => (string) ($draft['date_decaissement_minimum'] ?? $draft['planned_start_date'] ?? ''),
        'fd_details_demande' => (string) ($draft['details_demande'] ?? ''),
    ];

    foreach ($defaults as $key => $value) {
        if (!array_key_exists($key, $merged) || trim((string) $merged[$key]) === '') {
            $merged[$key] = $value;
        }
    }

    return $merged;
}

/** Enregistrer le montant confirmé dans la transaction de décision. */
function febFinanceSaveApprovalAmount($manager, int $id, array $request, array $post, array $actor): array
{
    if (!array_key_exists('montant',$post)) return $request;
    $amount=(float)febMoneyInput($post['montant']);
    if (!is_finite($amount) || $amount<=0) throw new DomainException('Le montant final de la FEB doit être supérieur à zéro.');
    $payload=(array)($request['payload']??[]);
    if (abs((float)($payload['montant']??0)-$amount)<0.005) return $request;
    $payload=febBuildEditedPayload($payload,['montant'=>$amount]);
    if (!$manager->updateFebBeforeDecision($id,$payload,$actor,'Montant final confirmé par le validateur avant approbation.',true)) throw new RuntimeException('Le montant final n’a pas pu être enregistré.');
    return $manager->getRequestById($id) ?: throw new RuntimeException('La FEB modifiée est introuvable.');
}

function febFinanceApplyApprovedFebValues(array $request, array $post): array
{
    $payload=(array)($request['payload']??[]);
    $amount=(float)febMoneyInput($payload['montant']??0);
    if($amount<=0)throw new RuntimeException('Le montant final de la FEB est invalide : la fiche ne peut pas être créée.');
    $post['fd_montant_fiche']=(string)$amount;
    $description=trim((string)($payload['description']??''));
    if($description==='')$description=trim((string)($request['title']??''));
    if($description!=='')$post['fd_details_demande']=$description;
    $draft=(array)($payload['provisional_decaissement']??[]);
    foreach(['beneficiary_mode'=>'fd_beneficiary_mode','personnel_id'=>'fd_personnel_id','fournisseur_id'=>'fd_fournisseur_id','beneficiaire_fiche'=>'fd_beneficiaire_fiche','tel_beneficiaire_fiche'=>'fd_tel_beneficiaire_fiche','mode_paiement'=>'fd_mode_paiement'] as $source=>$target){
        if(array_key_exists($source,$draft)&&trim((string)$draft[$source])!=='')$post[$target]=(string)$draft[$source];
    }
    return $post;
}

function febFinanceRequireDecaissementPayload(array $post, array $files): array
{
    $errors = [];
    $mode = strtolower(trim((string) ($post['fd_beneficiary_mode'] ?? '')));
    if (!in_array($mode, ['personnel', 'supplier', 'external'], true)) {
        $errors[] = 'Choisissez si le bénéficiaire final fait partie du personnel.';
    }

    if ($mode === 'personnel' && (int) ($post['fd_personnel_id'] ?? 0) <= 0) {
        $errors[] = 'Sélectionnez le bénéficiaire dans le personnel.';
    }
    if ($mode === 'external') {
        if (trim((string) ($post['fd_beneficiaire_fiche'] ?? '')) === '') {
            $errors[] = 'Renseignez le nom complet du bénéficiaire externe.';
        }
        if (trim((string) ($post['fd_tel_beneficiaire_fiche'] ?? '')) === '') {
            $errors[] = 'Renseignez le téléphone du bénéficiaire externe.';
        }
        foreach (['fd_photo_beneficiaire' => 'photo du bénéficiaire', 'fd_cni_beneficiaire' => 'pièce du bénéficiaire'] as $fileKey => $label) {
            if (empty($files[$fileKey]) || (int) ($files[$fileKey]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $errors[] = 'Joignez la ' . $label . '.';
            }
        }
    }

    foreach ([
        'fd_chantier_id' => 'chantier',
        'fd_debourse_id' => 'déboursé',
        'fd_ligne_debourse_id' => 'ligne de déboursé',
        'fd_fournisseur_id' => 'fournisseur',
        'fd_montant_fiche' => 'montant de la fiche',
        'fd_mode_paiement' => 'mode de paiement',
    ] as $key => $label) {
        if (trim((string) ($post[$key] ?? '')) === '') {
            $errors[] = 'Renseignez le ' . $label . '.';
        }
    }

    if ((float) febMoneyInput($post['fd_montant_fiche'] ?? 0) <= 0) {
        $errors[] = 'Le montant de la fiche doit être supérieur à zéro.';
    }
    if (trim((string)($post['fd_details_demande'] ?? '')) === '') {
        $errors[] = 'Renseignez les détails de la fiche de décaissement.';
    }
    $scheduledDate = trim((string) ($post['fd_date_decaissement_minimum'] ?? ''));
    if ($scheduledDate === '' || !FebWorkflowGuard::validDate($scheduledDate)) {
        $errors[] = 'Renseignez une date prévue de décaissement valide.';
    }

    return $errors;
}

function febFinanceProjectRoot(): string
{
    return __DIR__;
}

function febFinanceResolveMediaFs(?string $storedValue): ?string
{
    $storedValue = trim((string) $storedValue);
    if ($storedValue === '' || preg_match('~^https?://~i', $storedValue)) {
        return null;
    }

    $root = febFinanceProjectRoot();
    $normalized = ltrim(str_replace('\\', '/', $storedValue), '/');
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
            return $absolute;
        }
    }

    return null;
}

function febFinanceStoreUploadedFile(array $file, string $subdir, string $prefix, array $allowedExt): string
{
    if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Fichier invalide.');
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    $name = trim((string) ($file['name'] ?? ''));
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($tmp === '' || !is_uploaded_file($tmp) || $ext === '' || !in_array($ext, $allowedExt, true)) {
        throw new RuntimeException('Format de fichier non accepté.');
    }

    $dir = __DIR__ . '/decaissement/uploads/' . trim($subdir, '/');
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Impossible de créer le dossier de stockage.');
    }

    $fileName = $prefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file($tmp, $dir . '/' . $fileName)) {
        throw new RuntimeException('Impossible de sauvegarder le fichier.');
    }

    return $fileName;
}

function febFinanceCopyFileToDecaissementUploads(string $source, string $subdir, string $prefix): string
{
    if (!is_file($source)) {
        return '';
    }
    $ext = strtolower(pathinfo($source, PATHINFO_EXTENSION));
    if ($ext === '' || strlen($ext) > 8) {
        $ext = 'jpg';
    }
    $dir = __DIR__ . '/decaissement/uploads/' . trim($subdir, '/');
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return '';
    }
    $fileName = $prefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    return @copy($source, $dir . '/' . $fileName) ? $fileName : '';
}

function febFinanceGenerateFicheNumber(PDO $pdo): string
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM fiche_decaissement WHERE num_fiche = ?');
    do {
        $num = 'FD-' . date('ym') . '-' . str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT);
        $stmt->execute([$num]);
    } while ((int) $stmt->fetchColumn() > 0);

    return $num;
}

function febFinanceEnsureTraceFicheTable(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS trace_fiche (
        id INT AUTO_INCREMENT PRIMARY KEY,
        num_fiche VARCHAR(50) NOT NULL,
        action VARCHAR(255) NOT NULL,
        date_action DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        utilisateur VARCHAR(100) NOT NULL,
        adresse_ip VARCHAR(100) DEFAULT NULL,
        details TEXT NULL,
        INDEX idx_num_fiche (num_fiche)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
}

/**
 * Les DDL MySQL provoquent un COMMIT implicite. Cette préparation doit donc
 * impérativement être exécutée avant beginTransaction(), jamais pendant la
 * création atomique de la fiche et l'approbation de la FEB.
 */
function febFinanceEnsureFichePlanningColumns(PDO $pdo): void
{
    if ($pdo->inTransaction()) {
        throw new RuntimeException('La préparation du schéma de décaissement doit précéder la transaction FEB.');
    }
    $ficheSchema = $pdo->query('SHOW COLUMNS FROM fiche_decaissement')->fetchAll(PDO::FETCH_COLUMN, 0);
    if (!in_array('feb_request_id', $ficheSchema, true)) {
        $pdo->exec('ALTER TABLE fiche_decaissement ADD COLUMN feb_request_id INT NULL AFTER code_autorisation_feb');
    }
    $ficheIndexes=$pdo->query('SHOW INDEX FROM fiche_decaissement')->fetchAll(PDO::FETCH_ASSOC);
    $ficheIndexNames=array_values(array_unique(array_map(static fn(array $index):string=>(string)($index['Key_name']??''),$ficheIndexes)));
    if(!in_array('idx_fiche_feb_request',$ficheIndexNames,true)){
        $pdo->exec('ALTER TABLE fiche_decaissement ADD INDEX idx_fiche_feb_request (feb_request_id)');
    }
    febFinanceEnsureTraceFicheTable($pdo);
    $columns = $pdo->query('SHOW COLUMNS FROM fiche_decaissement')->fetchAll(PDO::FETCH_COLUMN, 0);
    if (!in_array('planned_start_date', $columns, true)) {
        $pdo->exec('ALTER TABLE fiche_decaissement ADD COLUMN planned_start_date DATE NULL AFTER date_creat_fiche');
        $columns[] = 'planned_start_date';
    }
    if (!in_array('planned_end_date', $columns, true)) {
        $pdo->exec('ALTER TABLE fiche_decaissement ADD COLUMN planned_end_date DATE NULL AFTER planned_start_date');
    }
}

/** Calcule la période à appliquer en tenant compte de la date choisie par le validateur FEB. */
function febFinanceResolveValidatorPlanning(array $payload, array $decision): array
{
    $draft = is_array($payload['provisional_decaissement'] ?? null) ? (array)$payload['provisional_decaissement'] : [];
    $start = trim((string)($decision['planned_start_date'] ?? $payload['planned_start_date'] ?? ''));
    $end = trim((string)($decision['planned_end_date'] ?? $payload['planned_end_date'] ?? ''));
    $scheduled = trim((string)($decision['fd_date_decaissement_minimum'] ?? $draft['date_decaissement_minimum'] ?? ''));
    foreach (['début'=>$start,'fin'=>$end,'décaissement'=>$scheduled] as $label=>$date) {
        if ($date !== '' && !FebWorkflowGuard::validDate($date)) throw new RuntimeException('La date de '.$label.' transmise par le validateur FEB est invalide.');
    }
    if ($start === '' && $end !== '') $start = $end;
    if ($end === '' && $start !== '') $end = $start;
    if ($start === '' || $end === '' || $end < $start) throw new RuntimeException('Le planning global du déboursé ne peut pas être actualisé avec la date choisie.');
    $scheduled=(new DateTimeImmutable($start))->modify('-7 days')->format('Y-m-d');
    return ['start'=>$start,'end'=>$end,'scheduled'=>$scheduled];
}

/** La FEB s'inscrit dans la période du déboursé, sans la modifier. */
function febFinanceAlignExistingDeboursePlanning(PDO $pdo, int $debourseId, ?int $lineId, string $start, string $end, int $requestId, string $title = ''): void
{
    require_once __DIR__.'/services/DeboursePeriodService.php';
    DeboursePeriodService::assertContains($pdo,$debourseId,$start,$end);
}

/** Garantit après chaque rattachement que toutes les lignes restent incluses dans le planning global. */
function febFinanceEnforceDeboursePlanningContainment(PDO $pdo, int $debourseId, int $requestId): void
{
    if ($debourseId <= 0) return;
    require_once __DIR__.'/services/DeboursePeriodService.php';
    DeboursePeriodService::synchronizeDetails($pdo,$debourseId);
}

function febFinanceLinkExistingDebourse(PDO $pdo, array $request, array $actor, array $decision): array
{
    FinancialWorkflowProgress::step('Contrôle du rattachement au déboursé.');
    $payload = (array) ($request['payload'] ?? []);
    $draft = (array) ($payload['provisional_decaissement'] ?? []);
    $requestId = (int) ($request['id'] ?? 0);
    $chantierId = (int) ($decision['fd_chantier_id'] ?? $draft['chantier_id'] ?? $payload['chantier_id'] ?? 0);
    $debourseId = (int) ($decision['fd_debourse_id'] ?? $draft['debourse_id'] ?? 0);
    $ligneDebourseId = (int) ($decision['fd_ligne_debourse_id'] ?? $draft['ligne_debourse_id'] ?? 0);
    if ($requestId <= 0 || $chantierId <= 0 || $debourseId <= 0 || $ligneDebourseId <= 0) {
        throw new RuntimeException('Le rattachement au déboursé existant est incomplet.');
    }
    $stmt = $pdo->prepare('SELECT db.devis_id, db.ligne_devis_id FROM debourse_banamur db INNER JOIN ligne_debourse_banamur ldb ON ldb.debourse_id=db.id AND ldb.id=:ligne INNER JOIN chantier_banamur c ON c.id=:chantier AND c.archived_at IS NULL AND (c.devis_id=db.devis_id OR EXISTS(SELECT 1 FROM devis_chantier_links dcl WHERE dcl.devis_id=db.devis_id AND dcl.chantier_id=c.id)) WHERE db.id=:debourse AND ' . FinancialPlanningSeparationService::officialDeboursePredicate('db') . ' LIMIT 1');
    $stmt->execute(['ligne' => $ligneDebourseId, 'debourse' => $debourseId, 'chantier' => $chantierId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$row) {
        throw new RuntimeException('La ligne choisie ne correspond pas au déboursé existant.');
    }
    $resolvedPlanning=febFinanceResolveValidatorPlanning($payload,$decision);$globalStart=$resolvedPlanning['start'];$globalEnd=$resolvedPlanning['end'];
    febFinanceAlignExistingDeboursePlanning($pdo,$debourseId,$ligneDebourseId,$globalStart,$globalEnd,$requestId,(string)($payload['feb_quote_title']??''));
    febFinanceEnforceDeboursePlanningContainment($pdo,$debourseId,$requestId);
    $devisType = 'UNCLASSIFIED';
    try {
        $typeStmt = $pdo->prepare('SELECT devis_type FROM devis_offer_classifications WHERE devis_id=:id LIMIT 1');
        $typeStmt->execute(['id' => (int) $row['devis_id']]);
        $type = strtoupper(trim((string) $typeStmt->fetchColumn()));
        if (in_array($type, ['PRINCIPAL', 'TS'], true)) $devisType = $type;
    } catch (Throwable $e) {
    }
    $pdo->prepare('INSERT INTO feb_disbursement_links (feb_request_id, chantier_id, devis_id, devis_type, ligne_devis_id, debourse_id, ligne_debourse_id, integration_mode, integrated_by_user_id) VALUES (:feb,:chantier,:devis,:type,:ligne_devis,:debourse,:ligne_debourse,"existing_budget",:actor) ON DUPLICATE KEY UPDATE chantier_id=VALUES(chantier_id),devis_id=VALUES(devis_id),devis_type=VALUES(devis_type),ligne_devis_id=VALUES(ligne_devis_id),debourse_id=VALUES(debourse_id),ligne_debourse_id=VALUES(ligne_debourse_id),integration_mode=VALUES(integration_mode),integrated_by_user_id=VALUES(integrated_by_user_id),updated_at=NOW()')->execute(['feb'=>$requestId,'chantier'=>$chantierId,'devis'=>(int)$row['devis_id'],'type'=>$devisType,'ligne_devis'=>(int)$row['ligne_devis_id'],'debourse'=>$debourseId,'ligne_debourse'=>$ligneDebourseId,'actor'=>(int)($actor['user_id']??0)?:null]);
    return ['debourse_id'=>$debourseId,'ligne_devis_id'=>(int)$row['ligne_devis_id'],'ligne_debourse_id'=>$ligneDebourseId,'devis_id'=>(int)$row['devis_id'],'devis_type'=>$devisType,'existing'=>true];
}

function febFinanceCreateProposedDebourse(PDO $pdo, array $request, array $actor, array $decision = []): ?array
{
    FinancialWorkflowProgress::step('Préparation et intégration du déboursé.');
    $payload = (array) ($request['payload'] ?? []);
    if ((string) ($payload['budget_planned'] ?? '') !== 'no' && empty($payload['proposed_debourse'])) {
        return null;
    }
    $proposal = is_array($payload['proposed_debourse'] ?? null) ? (array) $payload['proposed_debourse'] : [];
    $requestId = (int) ($request['id'] ?? 0);
    $chantierId = (int) ($decision['fd_chantier_id'] ?? 0);
    if ($chantierId <= 0) $chantierId = (int) ($decision['feb_target_chantier_id'] ?? $payload['chantier_id'] ?? 0);
    $lines = array_values(array_filter((array) ($proposal['lines'] ?? []), 'is_array'));
    if (!$lines) {
        foreach (array_values(array_filter((array) ($payload['besoins'] ?? []), 'is_array')) as $need) {
            $supplier = (array) ($need['fournisseur'] ?? []);
            $quantity = max(1, (float) ($need['quantite'] ?? 1));
            $amount = (float) ($supplier['prix_unitaire'] ?? $need['prix_unitaire'] ?? 0) * $quantity;
            if ($amount <= 0 && count((array) ($payload['besoins'] ?? [])) === 1) $amount = (float) ($payload['montant'] ?? 0);
            $lines[] = ['designation'=>(string)($need['libelle']??$need['designation']??$payload['feb_quote_title']??$request['title']??'Besoin FEB'),'categorie'=>(string)($need['categorie']??'matériel'),'montant'=>$amount];
        }
        $proposal['title'] = (string) ($proposal['title'] ?? $payload['feb_quote_title'] ?? $request['title'] ?? ('FEB #' . $requestId));
        $proposal['ligne_devis_proposal'] = (string) ($proposal['ligne_devis_proposal'] ?? $proposal['title']);
    }
    if ($requestId <= 0 || $chantierId <= 0 || !$lines) {
        throw new RuntimeException('La proposition de déboursé est incomplète. Retournez la FEB pour complément.');
    }
    $resolvedPlanning=febFinanceResolveValidatorPlanning($payload,$decision);$globalStart=$resolvedPlanning['start'];$globalEnd=$resolvedPlanning['end'];
    foreach ($lines as $line) {
        $amount = (float) ($line['montant'] ?? 0);
        if (trim((string) ($line['designation'] ?? '')) === '' || $amount <= 0) {
            throw new RuntimeException('Une ligne du déboursé proposé est invalide (désignation ou montant).');
        }
        $lineStart=$globalStart;$lineEnd=$globalEnd;
        if($lineStart<$globalStart||$lineEnd>$globalEnd||$lineEnd<$lineStart)throw new RuntimeException('Le planning de chaque ligne doit être compris dans la période globale du déboursé.');
    }

    $linked = $pdo->prepare('SELECT devis_id, devis_type, ligne_devis_id, debourse_id, ligne_debourse_id FROM feb_disbursement_links WHERE feb_request_id = :feb_id LIMIT 1');
    $linked->execute(['feb_id' => $requestId]);
    if ($row = $linked->fetch(PDO::FETCH_ASSOC)) {
        febFinanceAlignExistingDeboursePlanning($pdo,(int)$row['debourse_id'],(int)($row['ligne_debourse_id']??0)?:null,$globalStart,$globalEnd,$requestId,(string)($proposal['title']??''));
        febFinanceEnforceDeboursePlanningContainment($pdo,(int)$row['debourse_id'],$requestId);
        return ['debourse_id' => (int) $row['debourse_id'], 'ligne_devis_id' => (int) $row['ligne_devis_id'], 'ligne_debourse_id' => (int) ($row['ligne_debourse_id'] ?? 0), 'devis_id' => (int) $row['devis_id'], 'devis_type' => (string) $row['devis_type'], 'existing' => true];
    }

    $existing = $pdo->prepare('SELECT id, devis_id, ligne_devis_id FROM debourse_banamur WHERE feb_request_id = :feb_id LIMIT 1');
    $existing->execute(['feb_id' => $requestId]);
    if ($row = $existing->fetch(PDO::FETCH_ASSOC)) {
        $lineIdStmt = $pdo->prepare('SELECT id FROM ligne_debourse_banamur WHERE debourse_id = :id ORDER BY id LIMIT 1');
        $lineIdStmt->execute(['id' => (int) $row['id']]);
        $lineDebourseId = (int) ($lineIdStmt->fetchColumn() ?: 0);
        $pdo->prepare('INSERT INTO feb_disbursement_links (feb_request_id, chantier_id, devis_id, devis_type, ligne_devis_id, debourse_id, ligne_debourse_id, integration_mode, integrated_by_user_id) VALUES (:feb, :chantier, :devis, "UNCLASSIFIED", :ligne_devis, :debourse, :ligne_debourse, "legacy_recovered", :actor) ON DUPLICATE KEY UPDATE debourse_id=VALUES(debourse_id), ligne_debourse_id=VALUES(ligne_debourse_id), updated_at=NOW()')->execute(['feb'=>$requestId,'chantier'=>$chantierId,'devis'=>(int)$row['devis_id'],'ligne_devis'=>(int)$row['ligne_devis_id'],'debourse'=>(int)$row['id'],'ligne_debourse'=>$lineDebourseId?:null,'actor'=>(int)($actor['user_id']??0)?:null]);
        febFinanceAlignExistingDeboursePlanning($pdo,(int)$row['id'],$lineDebourseId?:null,$globalStart,$globalEnd,$requestId,(string)($proposal['title']??''));
        febFinanceEnforceDeboursePlanningContainment($pdo,(int)$row['id'],$requestId);
        return ['debourse_id' => (int) $row['id'], 'ligne_devis_id' => (int) $row['ligne_devis_id'], 'ligne_debourse_id' => $lineDebourseId, 'devis_id' => (int) $row['devis_id'], 'devis_type' => 'UNCLASSIFIED', 'existing' => true];
    }

    $chantierStmt = $pdo->prepare('SELECT id, devis_id, archived_at FROM chantier_banamur WHERE id = :id LIMIT 1');
    $chantierStmt->execute(['id' => $chantierId]);
    $chantier = $chantierStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$chantier || !empty($chantier['archived_at']) || (int) ($chantier['devis_id'] ?? 0) <= 0) {
        throw new RuntimeException('Le chantier de la FEB est introuvable, archivé ou sans devis.');
    }
    $chantierDevisId = (int) $chantier['devis_id'];
    $devisId = (int) ($decision['feb_target_devis_id'] ?? $proposal['devis_id'] ?? $chantierDevisId);
    if ($devisId <= 0) {
        $devisId = $chantierDevisId;
    }
    $targetStmt = $pdo->prepare('SELECT target.id, target.offre_id, chantier_devis.offre_id AS chantier_offre_id FROM devis_banamur target INNER JOIN devis_banamur chantier_devis ON chantier_devis.id = :chantier_devis_id WHERE target.id = :target_id LIMIT 1');
    $targetStmt->execute(['chantier_devis_id' => $chantierDevisId, 'target_id' => $devisId]);
    $target = $targetStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$target || ($devisId !== $chantierDevisId && ((int) ($target['offre_id'] ?? 0) <= 0 || (int) $target['offre_id'] !== (int) ($target['chantier_offre_id'] ?? 0)))) {
        throw new RuntimeException('Le devis cible doit être le devis du chantier ou un devis Principal/TS du même projet.');
    }
    $devisType = 'UNCLASSIFIED';
    try {
        $classificationStmt = $pdo->prepare('SELECT devis_type FROM devis_offer_classifications WHERE devis_id = :devis_id LIMIT 1');
        $classificationStmt->execute(['devis_id' => $devisId]);
        $classified = strtoupper(trim((string) $classificationStmt->fetchColumn()));
        if (in_array($classified, ['PRINCIPAL', 'TS'], true)) {
            $devisType = $classified;
        }
    } catch (Throwable $e) {
        // Les anciens devis non classés restent compatibles.
    }
    $lineMode = (string) ($decision['feb_ligne_devis_mode'] ?? $proposal['ligne_devis_mode'] ?? ((int)($proposal['ligne_devis_id']??0)>0?'existing':'new'));
    $ligneDevisId = $lineMode === 'existing' ? (int) ($decision['feb_target_ligne_devis_id'] ?? $proposal['ligne_devis_id'] ?? 0) : 0;
    if ($lineMode === 'existing' && $ligneDevisId <= 0) {
        throw new RuntimeException('Sélectionnez la ligne de devis existante à utiliser ou choisissez de créer une nouvelle ligne.');
    }
    if ($ligneDevisId > 0) {
        $lineCheck = $pdo->prepare('SELECT id FROM ligne_devis_banamur WHERE id = :id AND devis_id = :devis_id LIMIT 1');
        $lineCheck->execute(['id' => $ligneDevisId, 'devis_id' => $devisId]);
        if (!$lineCheck->fetchColumn()) {
            if ((int) ($decision['feb_target_devis_id'] ?? 0) > 0) {
                $ligneDevisId = 0;
            } else {
                throw new RuntimeException('La ligne de devis proposée n’appartient pas au devis cible sélectionné.');
            }
        }
    }
    if ($ligneDevisId <= 0) {
        $label = trim((string) ($decision['feb_new_ligne_devis_label'] ?? $proposal['ligne_devis_proposal'] ?? $proposal['title'] ?? ''));
        if ($label === '') {
            throw new RuntimeException('La nouvelle ligne de devis proposée n’a pas de libellé.');
        }
        $total = (float) array_sum(array_map(static fn(array $line): float => (float) ($line['montant'] ?? 0), $lines));
        $unitId = (int) ($pdo->query('SELECT id FROM unite_banamur ORDER BY id LIMIT 1')->fetchColumn() ?: 1);
        $createLine = $pdo->prepare('INSERT INTO ligne_devis_banamur (devis_id, designation, prix, quantite, unite_id, total, groupe) VALUES (:devis_id, :designation, :prix, 1, :unite_id, :total, :groupe)');
        $createLine->execute(['devis_id' => $devisId, 'designation' => $label, 'prix' => $total, 'unite_id' => $unitId, 'total' => $total, 'groupe' => 'Ajout FEB #' . $requestId]);
        $ligneDevisId = (int) $pdo->lastInsertId();
    }

    $total = 0.0;
    $firstLineDebourseId = 0;
    foreach ($lines as $line) {
        $amount = (float) ($line['montant'] ?? 0);
        if (trim((string) ($line['designation'] ?? '')) === '' || $amount <= 0) {
            throw new RuntimeException('Une ligne du déboursé proposé est invalide (désignation ou montant).');
        }
        $total += $amount;
    }
    $debourseMode = (string) ($decision['feb_debourse_mode'] ?? $proposal['debourse_mode'] ?? 'new');
    $debourseId = $debourseMode === 'existing' ? (int) ($decision['feb_target_debourse_id'] ?? $proposal['existing_debourse_id'] ?? 0) : 0;
    if ($debourseMode === 'existing') {
        $debourseCheck = $pdo->prepare('SELECT id FROM debourse_banamur WHERE id=:id AND devis_id=:devis AND ligne_devis_id=:ligne LIMIT 1 FOR UPDATE');
        $debourseCheck->execute(['id'=>$debourseId,'devis'=>$devisId,'ligne'=>$ligneDevisId]);
        if (!$debourseCheck->fetchColumn()) throw new RuntimeException('Le déboursé existant ne correspond pas au devis et à la ligne de devis sélectionnés.');
    } else {
        $insertDebourse = $pdo->prepare('INSERT INTO debourse_banamur (devis_id, ligne_devis_id, feb_request_id, montant_debourse, responsable_id, date_debut, date_fin, motif_modif) VALUES (:devis_id, :ligne_devis_id, :feb_id, :montant, :responsable_id, :date_debut, :date_fin, :motif)');
        $insertDebourse->execute(['devis_id' => $devisId, 'ligne_devis_id' => $ligneDevisId, 'feb_id' => $requestId, 'montant' => $total, 'responsable_id' => (int) ($actor['user_id'] ?? 0), 'date_debut' => $globalStart, 'date_fin' => $globalEnd, 'motif' => trim((string) ($proposal['title'] ?? '')) . ' — créé par validation FEB #' . $requestId]);
        $debourseId = (int) $pdo->lastInsertId();
    }
    $insertLine = $pdo->prepare('INSERT INTO ligne_debourse_banamur (debourse_id, categorie, designation, montant, date_debut, date_fin) VALUES (:debourse_id, :categorie, :designation, :montant, :date_debut, :date_fin)');
    foreach ($lines as $line) {
        $category = FebCategoryMigrationService::normalize((string) ($line['categorie'] ?? ''));
        $insertLine->execute(['debourse_id' => $debourseId, 'categorie' => $category, 'designation' => trim((string) $line['designation']), 'montant' => (float) $line['montant'], 'date_debut' => $globalStart, 'date_fin' => $globalEnd]);
        if ($firstLineDebourseId === 0) {
            $firstLineDebourseId = (int) $pdo->lastInsertId();
        }
    }
    if ($debourseMode === 'existing') {
        febFinanceAlignExistingDeboursePlanning($pdo,$debourseId,null,$globalStart,$globalEnd,$requestId,(string)($proposal['title']??''));
        $pdo->prepare('UPDATE debourse_banamur SET montant_debourse=(SELECT COALESCE(SUM(ldb.montant),0) FROM ligne_debourse_banamur ldb WHERE ldb.debourse_id=:line_debourse_id) WHERE id=:id')->execute(['line_debourse_id'=>$debourseId,'id'=>$debourseId]);
    }
    febFinanceEnforceDeboursePlanningContainment($pdo,$debourseId,$requestId);
    $integrationMode = $debourseMode === 'existing' ? 'approval_existing' : 'approval_created';
    $pdo->prepare('INSERT INTO feb_disbursement_links (feb_request_id, chantier_id, devis_id, devis_type, ligne_devis_id, debourse_id, ligne_debourse_id, integration_mode, integrated_by_user_id) VALUES (:feb, :chantier, :devis, :devis_type, :ligne_devis, :debourse, :ligne_debourse, :mode, :actor) ON DUPLICATE KEY UPDATE devis_id=VALUES(devis_id), devis_type=VALUES(devis_type), ligne_devis_id=VALUES(ligne_devis_id), debourse_id=VALUES(debourse_id), ligne_debourse_id=VALUES(ligne_debourse_id), integration_mode=VALUES(integration_mode), updated_at=NOW()')->execute(['feb'=>$requestId,'chantier'=>$chantierId,'devis'=>$devisId,'devis_type'=>$devisType,'ligne_devis'=>$ligneDevisId,'debourse'=>$debourseId,'ligne_debourse'=>$firstLineDebourseId?:null,'mode'=>$integrationMode,'actor'=>(int)($actor['user_id']??0)?:null]);
    return ['debourse_id' => $debourseId, 'ligne_devis_id' => $ligneDevisId, 'ligne_debourse_id' => $firstLineDebourseId, 'devis_id' => $devisId, 'devis_type' => $devisType, 'existing' => false];
}

/** Synchronise une fiche issue d'une FEB tant qu'elle n'est pas décaissée. */
function febFinanceSynchronizeLinkedFicheAmount(PDO $pdo, int $febRequestId, float $amount, array $actor): bool
{
    if ($febRequestId <= 0 || $amount <= 0) return false;
    if (!$pdo->inTransaction()) throw new RuntimeException('La synchronisation du montant doit être transactionnelle.');
    $find = $pdo->prepare('SELECT id_fiche,num_fiche,decaisse,montant_fiche,montant_initial_fiche FROM fiche_decaissement WHERE feb_request_id=:feb ORDER BY id_fiche FOR UPDATE');
    $find->execute(['feb' => $febRequestId]);
    $family = $find->fetchAll(PDO::FETCH_ASSOC);
    if (!$family) return false;
    if (count($family) > 1) throw new RuntimeException('La FEB possède plusieurs fiches ou un décaissement partiel. Son montant ne peut plus être remplacé depuis la validation.');
    $fiche = $family[0];
    if ((int)($fiche['decaisse'] ?? 0) === 1) throw new RuntimeException('Le montant d’une fiche déjà décaissée ne peut plus être modifié.');
    if (abs((float)$fiche['montant_fiche'] - $amount) < 0.005 && abs((float)($fiche['montant_initial_fiche']??0) - $amount) < 0.005) return false;
    $update = $pdo->prepare('UPDATE fiche_decaissement SET montant_fiche=:amount,montant_initial_fiche=:amount WHERE id_fiche=:id AND decaisse=0');
    $update->execute(['amount' => $amount, 'id' => (int)$fiche['id_fiche']]);
    try {
        $trace = $pdo->prepare('INSERT INTO trace_fiche (num_fiche,action,utilisateur,adresse_ip,details) VALUES (:num,:action,:user,:ip,:details)');
        $trace->execute([
            'num' => (string)$fiche['num_fiche'], 'action' => 'MONTANT_SYNC_DEPUIS_FEB',
            'user' => (string)($actor['name'] ?? 'Finance'), 'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
            'details' => 'Montant synchronisé depuis la FEB #'.$febRequestId.' : '.number_format($amount, 2, '.', '').' FCFA.',
        ]);
    } catch (Throwable) {
        // La traçabilité ne doit pas empêcher la synchronisation financière.
    }
    return true;
}

function febFinanceCreateLinkedFiche(PDO $pdo, array $request, array $actor, array $post, array $files): string
{
    FinancialWorkflowProgress::step('Création de la fiche de décaissement et de ses justificatifs.');
    $requestPayload = (array) ($request['payload'] ?? []);
    $prepared = (array) ($requestPayload['provisional_decaissement'] ?? []);
    $updatedDisplay = (array) ($request['feb_display'] ?? EmployeeLegalRequestManager::extractFebDisplay($request));
    $codeFeb = trim((string) ($updatedDisplay['numero_bon_commande_interne'] ?? $updatedDisplay['code_autorisation_feb'] ?? ''));
    $requestId = (int) ($request['id'] ?? 0);
    if (!$pdo->inTransaction()) throw new RuntimeException('La création liée doit être transactionnelle.');
    $existing = $pdo->prepare('SELECT num_fiche FROM fiche_decaissement WHERE feb_request_id = :request_id ORDER BY id_fiche LIMIT 1 FOR UPDATE');
    $existing->execute(['request_id' => $requestId]);
    $existingNum = trim((string) $existing->fetchColumn());
    if ($existingNum !== '') {
        febFinanceSynchronizeLinkedFicheAmount($pdo, $requestId, (float)febMoneyInput($post['fd_montant_fiche'] ?? 0), $actor);
        $description=trim((string)($requestPayload['description']??''));if($description==='')$description=trim((string)($request['title']??''));
        $pdo->prepare('UPDATE fiche_decaissement SET details_demande=:details WHERE feb_request_id=:feb AND decaisse=0')->execute(['details'=>$description,'feb'=>$requestId]);
        return $existingNum;
    }
    if ($codeFeb !== '') {
        $legacyFiche = $pdo->prepare('SELECT num_fiche FROM fiche_decaissement WHERE feb_request_id IS NULL AND code_autorisation_feb = :code ORDER BY id_fiche LIMIT 1');
        $legacyFiche->execute(['code' => $codeFeb]);
        $legacyNum = trim((string) $legacyFiche->fetchColumn());
        if ($legacyNum !== '') {
            $pdo->prepare('UPDATE fiche_decaissement SET feb_request_id=:request_id WHERE num_fiche=:num AND feb_request_id IS NULL LIMIT 1')->execute(['request_id'=>$requestId,'num'=>$legacyNum]);
            febFinanceSynchronizeLinkedFicheAmount($pdo, $requestId, (float)febMoneyInput($post['fd_montant_fiche'] ?? 0), $actor);
            $description=trim((string)($requestPayload['description']??''));if($description==='')$description=trim((string)($request['title']??''));
            $pdo->prepare('UPDATE fiche_decaissement SET details_demande=:details WHERE feb_request_id=:feb AND decaisse=0')->execute(['details'=>$description,'feb'=>$requestId]);
            return $legacyNum;
        }
    }

    $chantierId = (int) ($post['fd_chantier_id'] ?? $prepared['chantier_id'] ?? 0);
    $debourseId = (int) ($post['fd_debourse_id'] ?? $prepared['debourse_id'] ?? 0);
    $ligneId = (int) ($post['fd_ligne_debourse_id'] ?? $prepared['ligne_debourse_id'] ?? 0);
    $fournisseurId = FebWorkflowGuard::supplierId($post, $prepared, $requestPayload);
    $montant = (float) febMoneyInput($post['fd_montant_fiche'] ?? $prepared['montant_fiche'] ?? 0);
    $plannedStartDate = trim((string) ($prepared['planned_start_date'] ?? $requestPayload['planned_start_date'] ?? ''));
    $plannedEndDate = trim((string) ($prepared['planned_end_date'] ?? $requestPayload['planned_end_date'] ?? ''));
    $scheduledDisbursementDate = trim((string) ($post['fd_date_decaissement_minimum'] ?? $prepared['date_decaissement_minimum'] ?? $plannedStartDate ?? ''));
    if ($scheduledDisbursementDate === '') {
        $scheduledDisbursementDate = trim((string) ($requestPayload['date_livraison'] ?? date('Y-m-d')));
    }
    if (!FebWorkflowGuard::validDate($scheduledDisbursementDate)) throw new RuntimeException('La date prévue de décaissement est invalide.');
    if ($plannedStartDate === '') $plannedStartDate = $scheduledDisbursementDate;
    if ($plannedEndDate === '') $plannedEndDate = $plannedStartDate;
    if ($plannedEndDate < $plannedStartDate) throw new RuntimeException('Le planning global de la FEB est incomplet ou invalide avant création de la fiche.');

    $lineStmt = $pdo->prepare('
        SELECT ldb.id, ldb.designation AS ligne_designation, ldb.debourse_id, db.devis_id, ld.designation AS devis_designation
        FROM ligne_debourse_banamur ldb
        INNER JOIN debourse_banamur db ON db.id = ldb.debourse_id
        LEFT JOIN ligne_devis_banamur ld ON ld.id = db.ligne_devis_id
        WHERE ldb.id = :ligne_id AND ldb.debourse_id = :debourse_id
        LIMIT 1
    ');
    $lineStmt->execute(['ligne_id' => $ligneId, 'debourse_id' => $debourseId]);
    $line = $lineStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$line) {
        throw new RuntimeException('La ligne de déboursé sélectionnée ne correspond pas au déboursé.');
    }

    $chantierStmt = $pdo->prepare('SELECT id, code, titre, devis_id, archived_at FROM chantier_banamur WHERE id = :id LIMIT 1');
    $chantierStmt->execute(['id' => $chantierId]);
    $chantier = $chantierStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$chantier || !empty($chantier['archived_at'])) {
        throw new RuntimeException('Chantier introuvable ou archivé.');
    }
    if ((int) ($chantier['devis_id'] ?? 0) !== (int) ($line['devis_id'] ?? 0)) {
        $sameOffer = $pdo->prepare('SELECT COUNT(*) FROM devis_banamur chantier_devis INNER JOIN devis_banamur target_devis ON target_devis.id=:target_devis WHERE chantier_devis.id=:chantier_devis AND chantier_devis.offre_id IS NOT NULL AND chantier_devis.offre_id=target_devis.offre_id');
        $sameOffer->execute(['target_devis' => (int) ($line['devis_id'] ?? 0), 'chantier_devis' => (int) ($chantier['devis_id'] ?? 0)]);
        if ((int) $sameOffer->fetchColumn() === 0) {
            throw new RuntimeException('Le déboursé choisi n’est lié ni au devis du chantier, ni à un devis Principal/TS du même projet.');
        }
    }

    $supplierStmt = $pdo->prepare('SELECT id_fournisseur, raison_sociale, nom_commercial, contact_nom, telephone, photo_path, logo_path, cni_path, actif, validation_status, disbursement_selection_blocked, disbursement_block_reason, disbursement_block_fiches FROM fournisseur_banamur WHERE id_fournisseur = :id LIMIT 1');
    $supplierStmt->execute(['id' => $fournisseurId]);
    $supplier = $supplierStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$supplier || (int) ($supplier['actif'] ?? 0) !== 1 || (string) ($supplier['validation_status'] ?? '') !== 'approved') {
        throw new RuntimeException('Fournisseur introuvable ou non validé.');
    }
    SupplierApprovalGuard::assertFiche($pdo,['fournisseur_id'=>$fournisseurId]);
    if (!empty($supplier['disbursement_selection_blocked'])) {
        throw new RuntimeException('Fournisseur temporairement indisponible : '.(string)($supplier['disbursement_block_reason'] ?? 'justificatifs à fournir').' — fiche(s) '.(string)($supplier['disbursement_block_fiches'] ?? 'concernée(s)').'.');
    }

    $mode = strtolower(trim((string) ($post['fd_beneficiary_mode'] ?? $prepared['beneficiary_mode'] ?? 'personnel')));
    $beneficiary = '';
    $telephone = '';
    $personnelId = null;
    $photoFile = '';
    $cniFile = '';

    if ($mode === 'personnel') {
        $personnelId = (int) ($post['fd_personnel_id'] ?? $prepared['personnel_id'] ?? 0);
        $personnelStmt = $pdo->prepare('
            SELECT id_personnel_soignant, nom_personnel_soignant, tel_personnel_soignant, photo_personnel_soignant, piece_identite_path
            FROM personnel
            WHERE id_personnel_soignant = :id
            LIMIT 1
        ');
        $personnelStmt->execute(['id' => $personnelId]);
        $personnel = $personnelStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$personnel) {
            throw new RuntimeException('Personnel bénéficiaire introuvable.');
        }
        $beneficiary = trim((string) ($personnel['nom_personnel_soignant'] ?? ''));
        $telephone = trim((string) ($personnel['tel_personnel_soignant'] ?? ''));
        $photoFile = febFinanceCopyFileToDecaissementUploads((string) febFinanceResolveMediaFs((string) ($personnel['photo_personnel_soignant'] ?? '')), 'photo', 'photo');
        $cniFile = febFinanceCopyFileToDecaissementUploads((string) febFinanceResolveMediaFs((string) ($personnel['piece_identite_path'] ?? '')), 'cni', 'cni');
    } elseif ($mode === 'supplier') {
        $beneficiary = trim((string) ($supplier['nom_commercial'] ?? ''));
        if ($beneficiary === '') $beneficiary = trim((string) ($supplier['raison_sociale'] ?? ''));
        if ($beneficiary === '') $beneficiary = trim((string) ($supplier['contact_nom'] ?? ''));
        $telephone = trim((string) ($supplier['telephone'] ?? ''));
        $supplierPhoto = trim((string) ($supplier['photo_path'] ?? '')) ?: trim((string) ($supplier['logo_path'] ?? ''));
        $photoFile = febFinanceCopyFileToDecaissementUploads((string) febFinanceResolveMediaFs($supplierPhoto), 'photo', 'photo');
        $cniFile = febFinanceCopyFileToDecaissementUploads((string) febFinanceResolveMediaFs((string) ($supplier['cni_path'] ?? '')), 'cni', 'cni');
    } else {
        $beneficiary = trim((string) ($post['fd_beneficiaire_fiche'] ?? ''));
        $telephone = trim((string) ($post['fd_tel_beneficiaire_fiche'] ?? ''));
        $photoFile = febFinanceStoreUploadedFile((array) ($files['fd_photo_beneficiaire'] ?? []), 'photo', 'photo', ['jpg', 'jpeg', 'png', 'webp']);
        $cniFile = febFinanceStoreUploadedFile((array) ($files['fd_cni_beneficiaire'] ?? []), 'cni', 'cni', ['jpg', 'jpeg', 'png', 'webp', 'pdf']);
    }

    if ($beneficiary === '' || $telephone === '') {
        throw new RuntimeException('Bénéficiaire incomplet : nom et téléphone sont obligatoires.');
    }
    if ($mode === 'supplier' && $photoFile === '') {
        throw new RuntimeException('La fiche du fournisseur bénéficiaire doit contenir une photo ou un logo.');
    }
    if ($mode === 'external' && ($photoFile === '' || $cniFile === '')) {
        throw new RuntimeException('Bénéficiaire externe incomplet : photo et pièce sont obligatoires.');
    }

    $numFiche = febFinanceGenerateFicheNumber($pdo);
    $details = trim((string) ($post['fd_details_demande'] ?? $prepared['details_demande'] ?? ''));
    if ($details === '') {
        $payload = (array) ($request['payload'] ?? []);
        $details = trim((string) ($payload['description'] ?? ($request['title'] ?? 'Fiche générée depuis FEB')));
    }

    $ficheColumns = $pdo->query('SHOW COLUMNS FROM fiche_decaissement')->fetchAll(PDO::FETCH_COLUMN, 0);
    if (!in_array('planned_start_date', $ficheColumns, true) || !in_array('planned_end_date', $ficheColumns, true)) {
        throw new RuntimeException('Migration FEB planning incomplète avant création de la fiche.');
    }
    $hierarchyColumns=[];$hierarchyValues=[];$hierarchyParams=[];
    if(in_array('parent_num_fiche',$ficheColumns,true)){$hierarchyColumns[]='parent_num_fiche';$hierarchyValues[]=':parent_num_fiche';$hierarchyParams['parent_num_fiche']='';}
    if(in_array('code_parentale',$ficheColumns,true)){$hierarchyColumns[]='code_parentale';$hierarchyValues[]=':code_parentale';$hierarchyParams['code_parentale']='';}
    if(in_array('date_last_modif',$ficheColumns,true)){$hierarchyColumns[]='date_last_modif';$hierarchyValues[]='NOW()';}
    if(in_array('secur_last_modif',$ficheColumns,true)){$hierarchyColumns[]='secur_last_modif';$hierarchyValues[]=':secur_last_modif';$hierarchyParams['secur_last_modif']=trim((string)($actor['name']??'Finance FEB'));}
    $hierarchyColumnSql=$hierarchyColumns?', '.implode(', ',$hierarchyColumns):'';
    $hierarchyValueSql=$hierarchyValues?', '.implode(', ',$hierarchyValues):'';

    $insert = $pdo->prepare('
        INSERT INTO fiche_decaissement (
            beficiaire_fiche, montant_fiche, montant_initial_fiche, tel_beneficiaire_fiche, personnel_id,
            fournisseur_id, fournisseur_nom, fournisseur_contact_nom, fournisseur_telephone, num_fiche'.$hierarchyColumnSql.',
            chantier_id, ligne_debourse_id, designation_fiche, precision_fiche, details_demande, mode_paiement,
            photo_beneficiaire, cni_beneficiaire, date_creat_fiche, planned_start_date, planned_end_date, date_decaissement_minimum, created_by_user_id,
            approuve, conforme, date_conforme, secur_conforme, decaisse, otp_valide, etat_fiche, code_autorisation_feb, feb_request_id
        ) VALUES (
            :benef, :montant, :montant_initial, :tel, :personnel_id,
            :fournisseur_id, :fournisseur_nom, :fournisseur_contact_nom, :fournisseur_telephone, :num'.$hierarchyValueSql.',
            :chantier_id, :ligne_id, :designation, :precision, :details, :mode,
            :photo, :cni, NOW(), :planned_start, :planned_end, :scheduled_date, :created_by,
            0, 1, NOW(), :conformity_by, 0, 0, 0, :code_feb, :feb_request_id
        )
    ');
    $insert->execute(array_merge([
        'benef' => $beneficiary,
        'montant' => $montant,
        'montant_initial' => $montant,
        'tel' => $telephone,
        'personnel_id' => $personnelId,
        'fournisseur_id' => (int) $supplier['id_fournisseur'],
        'fournisseur_nom' => trim((string) ($supplier['nom_commercial'] ?? '')) ?: trim((string) ($supplier['raison_sociale'] ?? '')),
        'fournisseur_contact_nom' => trim((string) ($supplier['contact_nom'] ?? '')) ?: null,
        'fournisseur_telephone' => trim((string) ($supplier['telephone'] ?? '')) ?: null,
        'num' => $numFiche,
        'chantier_id' => $chantierId,
        'ligne_id' => $ligneId,
        'designation' => (string) ($line['devis_designation'] ?? ''),
        'precision' => (string) ($line['ligne_designation'] ?? ''),
        'details' => $details,
        'mode' => trim((string) ($post['fd_mode_paiement'] ?? $prepared['mode_paiement'] ?? 'Espèces')),
        'photo' => $photoFile,
        'cni' => $cniFile,
        'planned_start' => $plannedStartDate !== '' ? $plannedStartDate : null,
        'planned_end' => $plannedEndDate !== '' ? $plannedEndDate : null,
        'scheduled_date' => $scheduledDisbursementDate,
        'created_by' => (int) ($actor['user_id'] ?? 0) ?: null,
        'conformity_by' => trim((string) ($actor['name'] ?? 'Finance FEB')),
        'code_feb' => $codeFeb !== '' ? $codeFeb : null,
        'feb_request_id' => $requestId,
    ],$hierarchyParams));

    try {
        if (!$pdo->inTransaction()) {
            febFinanceEnsureTraceFicheTable($pdo);
        }
        $trace = $pdo->prepare('INSERT INTO trace_fiche (num_fiche, action, utilisateur, adresse_ip, details) VALUES (:num, :action, :user, :ip, :details)');
        $trace->execute([
            'num' => $numFiche,
            'action' => 'CREATION_AUTO_FEB_FINANCE',
            'user' => (string) ($actor['name'] ?? 'Finance'),
            'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            'details' => 'Fiche créée automatiquement depuis la FEB #' . (int) ($request['id'] ?? 0),
        ]);
    } catch (Throwable $e) {
        // La trace ne doit pas annuler la fiche.
    }

    return $numFiche;
}

function febFinanceCancelPreparationsForRejection(PDO $pdo, EmployeeLegalRequestManager $manager, array $request, array $actor): void
{
    FinancialWorkflowProgress::step('Annulation des préparations de la FEB refusée.');
    $requestId=(int)($request['id']??0);if($requestId<=0)throw new RuntimeException('FEB introuvable pour annulation.');
    $payload=(array)($request['payload']??[]);$draft=is_array($payload['provisional_decaissement']??null)?(array)$payload['provisional_decaissement']:[];
    $draft['active']=false;$draft['generation_cancelled']=true;$draft['generation_cancelled_at']=date('c');$draft['generation_cancelled_by']=(string)($actor['name']??'Validateur FEB');$payload['provisional_decaissement']=$draft;
    if(is_array($payload['proposed_debourse']??null)){$payload['proposed_debourse']['cancelled_at']=date('c');$payload['proposed_debourse']['cancelled_by']=(string)($actor['name']??'Validateur FEB');}
    unset($payload['validation_deferred_until'],$payload['validation_deferred_by'],$payload['validation_deferred_at']);
    if(!$manager->updateFebBeforeDecision($requestId,$payload,$actor,'Préparations de génération et de décaissement annulées automatiquement avant refus.',true))throw new RuntimeException('Les préparations de la FEB n’ont pas pu être annulées.');
    $pdo->prepare('UPDATE feb_price_challenges SET archived_at=COALESCE(archived_at,NOW()),archive_reason=COALESCE(archive_reason,"feb_refusee") WHERE feb_request_id=:id')->execute(['id'=>$requestId]);
    $link=$pdo->prepare('SELECT l.debourse_id,l.ligne_devis_id,db.feb_request_id FROM feb_disbursement_links l LEFT JOIN debourse_banamur db ON db.id=l.debourse_id WHERE l.feb_request_id=:id LIMIT 1 FOR UPDATE');$link->execute(['id'=>$requestId]);$integration=$link->fetch(PDO::FETCH_ASSOC)?:[];
    $debourseId=(int)($integration['debourse_id']??0);$ligneDevisId=(int)($integration['ligne_devis_id']??0);$dedicated=(int)($integration['feb_request_id']??0)===$requestId;
    if($dedicated&&$debourseId>0){$used=$pdo->prepare('SELECT COUNT(*) FROM fiche_decaissement WHERE feb_request_id=:feb OR ligne_debourse_id IN(SELECT id FROM ligne_debourse_banamur WHERE debourse_id=:debourse)');$used->execute(['feb'=>$requestId,'debourse'=>$debourseId]);if((int)$used->fetchColumn()===0){$pdo->prepare('DELETE FROM ligne_debourse_banamur WHERE debourse_id=:id')->execute(['id'=>$debourseId]);$pdo->prepare('DELETE FROM debourse_banamur WHERE id=:id AND feb_request_id=:feb')->execute(['id'=>$debourseId,'feb'=>$requestId]);if($ligneDevisId>0){$deleteLine=$pdo->prepare('DELETE FROM ligne_devis_banamur WHERE id=:id AND groupe=:groupe AND NOT EXISTS(SELECT 1 FROM debourse_banamur WHERE ligne_devis_id=:check_id)');$deleteLine->execute(['id'=>$ligneDevisId,'groupe'=>'Ajout FEB #'.$requestId,'check_id'=>$ligneDevisId]);}}}
    $pdo->prepare('DELETE FROM feb_disbursement_links WHERE feb_request_id=:id')->execute(['id'=>$requestId]);
    $manager->addRequestEvent($requestId,'feb_rejection_cleanup','Génération de fiche, consultations ouvertes et rattachements préparatoires annulés automatiquement.',$actor);
}

$actorUserId = (int) ($actor['user_id'] ?? 0);
$servicePerm = $actorUserId > 0 ? FebValidationPolicy::getUserServicePermissions($pdo, $actorUserId) : ['all' => false, 'ids' => [], 'configured' => false, 'raw' => ''];
$serviceFilter = !empty($servicePerm['all']) ? null : (array) ($servicePerm['ids'] ?? []);
$includeLegacyFeb = !empty($_GET['archives']) || !empty($_GET['legacy']) || !empty($_GET['include_archives']);
$searchQuery = trim((string) ($_GET['q'] ?? ''));
$multiFilterValues=static function(string $key):array{$raw=$_GET[$key]??[];if(!is_array($raw))$raw=trim((string)$raw)!==''?[(string)$raw]:[];return array_values(array_unique(array_filter(array_map(static fn($v):string=>trim((string)$v),$raw),static fn(string $v):bool=>$v!=='')));};
$searchRequesters=$multiFilterValues('demandeur');
$searchAffectations=$multiFilterValues('affectation');
$searchChantiers=$multiFilterValues('chantier');
$searchDebourses=$multiFilterValues('debourse');
$searchLignesDebourse=$multiFilterValues('ligne_debourse');
$filterModes=[];foreach(['demandeur','affectation','chantier','debourse','ligne_debourse'] as $filterName)$filterModes[$filterName]=(($_GET[$filterName.'_mode']??'include')==='exclude'?'exclude':'include');
$searchRequester=implode(', ',$searchRequesters);$searchAffectation=implode(', ',$searchAffectations);$searchChantier=implode(', ',$searchChantiers);$searchDebourse=implode(', ',$searchDebourses);$searchLigneDebourse=implode(', ',$searchLignesDebourse);
$searchChallengeStatus = strtolower(trim((string) ($_GET['devis_contradictoire'] ?? '')));
$searchDateFrom = trim((string)($_GET['date_from'] ?? ''));
$searchDateTo = trim((string)($_GET['date_to'] ?? ''));
$searchPeriod = strtolower(trim((string)($_GET['planning_period'] ?? '')));
if(!in_array($searchPeriod,['','today','week','month','quarter','semester','year'],true))$searchPeriod='';
$periodToday=new DateTimeImmutable('today');
if($searchPeriod!==''){
    [$periodFrom,$periodTo]=match($searchPeriod){
        'today'=>[$periodToday,$periodToday],
        'week'=>[$periodToday->modify('monday this week'),$periodToday->modify('sunday this week')],
        'month'=>[$periodToday->modify('first day of this month'),$periodToday->modify('last day of this month')],
        'quarter'=>[new DateTimeImmutable($periodToday->format('Y').'-'.str_pad((string)((intdiv((int)$periodToday->format('n')-1,3)*3)+1),2,'0',STR_PAD_LEFT).'-01'),null],
        'semester'=>[new DateTimeImmutable($periodToday->format('Y').'-'.((int)$periodToday->format('n')<=6?'01-01':'07-01')),null],
        'year'=>[$periodToday->setDate((int)$periodToday->format('Y'),1,1),$periodToday->setDate((int)$periodToday->format('Y'),12,31)],
    };
    if($searchPeriod==='quarter')$periodTo=$periodFrom->modify('+3 months -1 day');
    if($searchPeriod==='semester')$periodTo=$periodFrom->modify('+6 months -1 day');
    $searchDateFrom=$periodFrom->format('Y-m-d');$searchDateTo=$periodTo->format('Y-m-d');
}
if ($searchDateFrom !== '' && !FebWorkflowGuard::validDate($searchDateFrom)) $searchDateFrom='';
if ($searchDateTo !== '' && !FebWorkflowGuard::validDate($searchDateTo)) $searchDateTo='';
if (!in_array($searchChallengeStatus, ['', 'present', 'absent'], true)) $searchChallengeStatus = '';

$tab = strtolower(trim((string) ($_GET['tab'] ?? '')));
$allowedTabs = ['mine_pending', 'unplanned_analysis', 'future', 'deferred', 'details_requested', 'approved', 'rejected', 'cancelled', 'all'];
if (!in_array($tab, $allowedTabs, true)) {
    $tab = 'mine_pending';
}

// An exact FEB search is global across workflow tabs: a valid request must
// remain findable even when its planning status belongs to another tab.
if (preg_match('/^#?(?:FEB[- ]?)?(\\d+)$/i', $searchQuery)) {
    $tab = 'all';
}

$baseListParams = ['tab' => $tab];
if ($searchQuery !== '') {
    $baseListParams['q'] = $searchQuery;
}
foreach(['demandeur'=>$searchRequesters,'affectation'=>$searchAffectations,'chantier'=>$searchChantiers,'debourse'=>$searchDebourses,'ligne_debourse'=>$searchLignesDebourse] as $filterKey=>$values){if($values)$baseListParams[$filterKey]=$values;if(($filterModes[$filterKey]??'include')==='exclude')$baseListParams[$filterKey.'_mode']='exclude';}
if($searchChallengeStatus!=='')$baseListParams['devis_contradictoire']=$searchChallengeStatus;
foreach (['date_from'=>$searchDateFrom,'date_to'=>$searchDateTo] as $filterKey=>$filterValue) if ($filterValue !== '') $baseListParams[$filterKey]=$filterValue;
if(empty($_SESSION['feb_quick_edit_csrf']))$_SESSION['feb_quick_edit_csrf']=bin2hex(random_bytes(32));

if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&($_POST['action']??'')==='quick_replace_feb_proof'){
    $quickId=(int)($_POST['request_id']??0);$quickReturn='validation_demandes_finances.php?'.http_build_query($baseListParams).'#list';
    try{
        if(!hash_equals((string)$_SESSION['feb_quick_edit_csrf'],(string)($_POST['_csrf']??'')))throw new RuntimeException('Session expirée.');
        $quickRequest=$manager->getRequestById($quickId);if(!$quickRequest||($quickRequest['request_type']??'')!=='feb'||($quickRequest['assigned_team']??'')!=='finance')throw new RuntimeException('FEB introuvable.');
        if(!in_array((string)($quickRequest['status']??''),['submitted','details_requested'],true)||!FebValidationPolicy::canValidateService($servicePerm,FebValidationPolicy::requestServiceId($quickRequest)))throw new RuntimeException('Cette FEB ne peut plus être modifiée par ce validateur.');
        $file=(array)($_FILES['feb_proof']??[]);if((int)($file['size']??0)>8*1024*1024)throw new RuntimeException('Le justificatif ne doit pas dépasser 8 Mo.');
        $stored=febFinanceStoreUploadedFile($file,'feb_justificatifs','feb_'.$quickId,['pdf','jpg','jpeg','png','webp']);$document=['url'=>'/decaissement/uploads/feb_justificatifs/'.$stored,'name'=>trim((string)($file['name']??'Justificatif BFEB')),'original_name'=>trim((string)($file['name']??'')),'replaced_at'=>date('c'),'replaced_by'=>(string)($actor['name']??'Validateur FEB')];
        $payload=(array)($quickRequest['payload']??[]);$payload['global_justificatif']=$document;$payload['justificatifs']=[$document];$needs=array_values(array_filter((array)($payload['besoins']??[]),'is_array'));foreach($needs as &$need)$need['justificatif_devis']=$document;unset($need);if($needs)$payload['besoins']=$needs;
        $note='Justificatif BFEB remplacé par le validateur : '.(string)$document['name'].'.';if(!$manager->updateFebBeforeDecision($quickId,$payload,$actor,$note,true))throw new RuntimeException('Le justificatif n’a pas pu être enregistré.');$manager->addRequestEvent($quickId,'feb_proof_replaced_by_validator',$note,$actor);$_SESSION['flash_validation_demandes_finances']=['message'=>'Justificatif BFEB mis à jour.','type'=>'success'];
    }catch(Throwable $e){$_SESSION['flash_validation_demandes_finances']=['message'=>$e->getMessage(),'type'=>'error'];}
    header('Location: '.$quickReturn);exit;
}
if($searchPeriod!=='')$baseListParams['planning_period']=$searchPeriod;
if ($includeLegacyFeb) {
    $baseListParams['archives'] = 1;
}
$listUrl = 'validation_demandes_finances.php?' . http_build_query($baseListParams);
$listUrlAnchor = $listUrl . '#list';
$detailMode = strtolower(trim((string) ($_GET['screen'] ?? ''))) === 'detail';

$countsAll = [];
$countsMine = [];
$deferredCount = 0;
$futurePendingCount = 0;
function febFinanceEffectivePosition(array $payload): string
{
    $start=trim((string)($payload['planned_start_date']??''));
    if ((string)($payload['budget_planned']??'')==='yes' && FebWorkflowGuard::validDate($start)) {
        return (new DateTimeImmutable($start))->modify('-7 days')->format('Y-m-d');
    }
    return trim((string)($payload['feb_position_date']??''));
}

$plannedPendingCount = 0;
$unplannedPendingCount = 0;
$unclassifiedBudgetCount = 0;
try {
    $countsMine = $manager->countRequestsForTeam('finance', 'feb', $serviceFilter, $includeLegacyFeb);
    $countsAll = $countsMine;
    $pendingForCounters=$manager->listRequestsForTeamAdvanced('finance',['submitted'],'feb',$serviceFilter,500,$includeLegacyFeb);
    $todayForCounters=date('Y-m-d');
    $deferredCount=count(array_filter($pendingForCounters,static fn(array $row):bool=>(string)(((array)($row['payload']??[]))['validation_deferred_until']??'')>$todayForCounters));
    foreach($pendingForCounters as $pendingCounterRow){$counterPayload=(array)($pendingCounterRow['payload']??[]);$deferredUntil=(string)($counterPayload['validation_deferred_until']??'');$positionDate=febFinanceEffectivePosition($counterPayload);if($deferredUntil!==''&&$deferredUntil>$todayForCounters)continue;if($positionDate!==''&&$positionDate>$todayForCounters){$futurePendingCount++;continue;}$budgetMode=(string)($counterPayload['budget_planned']??'');if($budgetMode==='yes')$plannedPendingCount++;elseif($budgetMode==='no'||!empty($counterPayload['proposed_debourse']))$unplannedPendingCount++;else$unclassifiedBudgetCount++;}
} catch (Throwable $e) {
    $countsAll = [];
    $countsMine = [];
}

$flash = '';
$flashType = 'info';

if (!empty($_SESSION['flash_validation_demandes_finances']) && is_array($_SESSION['flash_validation_demandes_finances'])) {
    $sessionFlash = (array) $_SESSION['flash_validation_demandes_finances'];
    unset($_SESSION['flash_validation_demandes_finances']);

    $flash = trim((string) ($sessionFlash['message'] ?? ''));
    $flashType = (string) ($sessionFlash['type'] ?? 'info');
    if (!in_array($flashType, ['success', 'error', 'info'], true)) {
        $flashType = 'info';
    }
}

if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&in_array((string)($_POST['action']??''),['bulk_defer','bulk_bring_today','bulk_reject'],true)){
    $bulkAction=(string)$_POST['action'];
    $bulkIds=array_values(array_unique(array_filter(array_map('intval',(array)($_POST['ids']??[])),static fn(int $id):bool=>$id>0)));
    $bulkDate=trim((string)($_POST['deferred_until']??''));$bulkDone=0;$bulkSkipped=0;
    if(!$bulkIds){$flash='Sélectionnez au moins une FEB.';$flashType='error';}
    elseif($bulkAction==='bulk_defer'&&(!FebWorkflowGuard::validDate($bulkDate)||$bulkDate<=date('Y-m-d'))){$flash='La nouvelle date doit être postérieure à aujourd’hui.';$flashType='error';}
    else{
        foreach(array_slice($bulkIds,0,300) as $bulkId){
            $bulkLocked=false;
            try{
                $febWorkflowGuard->acquire($bulkId);$bulkLocked=true;
                $bulkRequest=$manager->getRequestById($bulkId);
                if(!$bulkRequest||($bulkRequest['assigned_team']??'')!=='finance'||($bulkRequest['request_type']??'')!=='feb'||(string)($bulkRequest['status']??'')!=='submitted'||!FebValidationPolicy::canValidateService($servicePerm,FebValidationPolicy::requestServiceId($bulkRequest))){$bulkSkipped++;continue;}
                if($bulkAction==='bulk_reject'){
                    FinancialWorkflowProgress::begin($pdo);
                    febFinanceCancelPreparationsForRejection($pdo,$manager,$bulkRequest,$actor);
                    if(!$manager->rejectRequest($bulkId,$actor,'Refus groupé par le validateur FEB.',false))throw new RuntimeException('Refus impossible.');
                    if($pdo->inTransaction())FinancialWorkflowProgress::commit($pdo);
                    try{FinancialWorkflowProgress::step('Préparation et transmission des notifications.');(new \NotificationController($pdo))->notifyLegalRequestProcessed($bulkRequest,'rejected',$actor,'Refus groupé par le validateur FEB.');}catch(Throwable $ignored){}
                }else{
                    FinancialWorkflowProgress::begin($pdo);
                    $bulkPayload=(array)($bulkRequest['payload']??[]);
                    if($bulkAction==='bulk_bring_today'){
                        unset($bulkPayload['validation_deferred_until'],$bulkPayload['validation_deferred_by'],$bulkPayload['validation_deferred_at']);
                        $bulkNote='FEB ramenée à aujourd’hui par action groupée.';$bulkEvent='feb_brought_today';
                    }else{
                        $bulkPayload['validation_deferred_until']=$bulkDate;$bulkPayload['validation_deferred_by']=(string)($actor['name']??'');$bulkPayload['validation_deferred_at']=date('c');
                        $bulkNote='FEB reportée en lot jusqu’au '.date('d/m/Y',strtotime($bulkDate)).'.';$bulkEvent='feb_deferred';
                    }
                    if(!$manager->updateFebBeforeDecision($bulkId,$bulkPayload,$actor,$bulkNote,true))throw new RuntimeException('Mise à jour impossible.');
                    $febPlanningSync->synchronizeDate($bulkId,$bulkAction==='bulk_bring_today'?date('Y-m-d'):$bulkDate,$bulkNote,(int)($actor['user_id']??0),(string)($actor['name']??''));
                    $manager->addRequestEvent($bulkId,$bulkEvent,$bulkNote,$actor);
                    FinancialWorkflowProgress::commit($pdo);
                }
                $bulkDone++;
            }catch(Throwable $e){if($pdo->inTransaction())FinancialWorkflowProgress::rollback($pdo);$bulkSkipped++;}finally{if($bulkLocked)$febWorkflowGuard->release($bulkId);}
        }
        $bulkLabel=$bulkAction==='bulk_reject'?'refusée(s)':($bulkAction==='bulk_bring_today'?'ramenée(s) à aujourd’hui':'reportée(s) au '.date('d/m/Y',strtotime($bulkDate)));
        $_SESSION['flash_validation_demandes_finances']=['message'=>$bulkDone.' FEB '.$bulkLabel.($bulkSkipped?' · '.$bulkSkipped.' ignorée(s) car non autorisée(s), indisponible(s) ou déjà traitée(s).':''),'type'=>$bulkDone?'success':'error'];
        header('Location: validation_demandes_finances.php?'.http_build_query($baseListParams).'#list');exit;
    }
}

function febApplyExpiredPlanningChoice(PDO $pdo, EmployeeLegalRequestManager $manager, int $id, array $candidate, array $actor): array
{
    $payload=(array)($candidate['payload']??[]);
    if ((string)($payload['budget_planned']??'') !== 'yes') {
        if (!empty($_POST['adjust_expired_planning'])) throw new DomainException('Aucun déboursé existant à replanifier.');
        return $candidate;
    }
    $draft=(array)($payload['provisional_decaissement']??[]);
    $debourseId=(int)($draft['debourse_id']??0);
    $chantierId=(int)($draft['chantier_id']??$payload['chantier_id']??0);
    if ($debourseId<=0 || $chantierId<=0) return $candidate;
    $planning=ExpiredFebPlanningAdjustmentService::preview($pdo,$debourseId,$chantierId);
    if (!$planning) throw new DomainException('Le déboursé ne correspond pas au chantier de la FEB.');
    $adjust=!empty($_POST['adjust_expired_planning']);
    $start=$adjust?trim((string)($_POST['expired_planning_start']??'')):(string)$planning['date_debut'];
    $end=$adjust?trim((string)($_POST['expired_planning_end']??'')):(string)$planning['date_fin'];
    $change=$adjust?ExpiredFebPlanningAdjustmentService::apply($pdo,$debourseId,$chantierId,$start,$end,!empty($_POST['confirm_expired_planning'])):null;
    if (!$adjust && ($payload['planned_start_date']??'')===$start && ($payload['planned_end_date']??'')===$end && ($draft['planned_start_date']??'')===$start && ($draft['planned_end_date']??'')===$end) return $candidate;
    $payload['planned_start_date']=$start;$payload['planned_end_date']=$end;
    $draft['planned_start_date']=$start;$draft['planned_end_date']=$end;
    $payload['provisional_decaissement']=$draft;
    $note=$adjust?'Période expirée du déboursé #'.$debourseId.' ajustée du '.$change['old_start'].'–'.$change['old_end'].' au '.$start.'–'.$end.'.':'Période de la FEB alignée sur le déboursé #'.$debourseId.' sans déplacer les travaux : '.$start.'–'.$end.'.';
    if ($adjust && $change['extended']) $note.=' Chantier #'.$chantierId.' : '.$change['chantier_old_start'].'–'.$change['chantier_old_end'].' → '.$change['chantier_start'].'–'.$change['chantier_end'].'.';
    if (!$manager->updateFebBeforeDecision($id,$payload,$actor,$note,true)) throw new RuntimeException('Le planning de la FEB n’a pas pu être enregistré.');
    $manager->addRequestEvent($id,$adjust?'feb_expired_planning_adjusted':'feb_planning_aligned_to_debourse',$note,$actor);
    return $manager->getRequestById($id) ?: $candidate;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['request_id']) && is_numeric($_POST['request_id'])) {
    $action = (string) $_POST['action'];
    $id = (int) $_POST['request_id'];
    $note = trim((string) ($_POST['note'] ?? ''));

    $postTab = strtolower(trim((string) ($_POST['tab'] ?? $tab)));
    if (!in_array($postTab, $allowedTabs, true)) {
        $postTab = $tab;
    }
    $postListParams = $baseListParams;
    $postListParams['tab'] = $postTab;
    unset($postListParams['screen'], $postListParams['id']);
    if (!empty($_POST['archives'])) {
        $postListParams['archives'] = 1;
    }
    $postListUrl = 'validation_demandes_finances.php?' . http_build_query($postListParams) . '#list';

    $candidate = $manager->getRequestById($id);
    $candidateOk = $candidate && ($candidate['assigned_team'] ?? '') === 'finance' && ($candidate['request_type'] ?? '') === 'feb';
    $candidateServiceId = $candidateOk ? FebValidationPolicy::requestServiceId($candidate) : 0;
    $canValidateThis = $candidateOk ? FebValidationPolicy::canValidateService($servicePerm, $candidateServiceId) : false;
    if ($candidateOk && !empty($candidate['payload']['rh_financial_point_id'])) {
        try {
            if (!hash_equals((string)($_SESSION['rh_financial_csrf'] ?? ''),(string)($_POST['_csrf'] ?? '')) || empty($_POST['_csrf'])) throw new DomainException('Session expirée. Rechargez la FEB RH.');
            if (!$canValidateThis) throw new DomainException('Vous ne pouvez pas valider les FEB de ce service.');
            $rhService=new RhFinancialPointService($pdo);
            $rhService->assertSignedFeb($candidate);
            if ($action !== 'approve') throw new DomainException('Pour modifier ou refuser les lignes du point signé, demandez sa réouverture aux RH.');
            if (!in_array($candidate['status'],['submitted','details_requested'],true)) throw new DomainException('Cette FEB est déjà traitée.');
            febFinanceEnsureFichePlanningColumns($pdo);
            FinancialWorkflowProgress::begin($pdo);
            $signedPoint=$rhService->assertSignedFeb($candidate,true);
            FinancialWorkflowProgress::step('Contrôle de la couverture et réservation du financement.');$funding=$treasuryFundingService->reserve($id,(string)($_POST['treasury_funding_source'] ?? ''),(int)($_POST['treasury_funding_invoice_id'] ?? 0),(float)$candidate['payload']['montant'],$signedPoint['snapshot']['budget']['date'],(int)$actor['user_id']);
            if($funding['scheduled_date']!==$signedPoint['snapshot']['budget']['date']) throw new DomainException('La date de financement diffère de celle du point signé. Faites réviser le point RH avant approbation.');
            if (!$manager->approveRequest($id,$actor,$note,false,true)) throw new RuntimeException('Approbation FEB impossible.');
            $approved=$manager->getRequestById($id);
            $date=$approved['payload']['provisional_decaissement']['date_decaissement_minimum'];
            $manager->generateFebBciForScheduledDisbursement($id,$date,$actor);
            $num=$rhService->createFiche($manager->getRequestById($id),$actor);
            $manager->addRequestEvent($id,'rh_financial_fiche_created','Fiche RH : '.$num,$actor);
            FinancialWorkflowProgress::commit($pdo);
            $_SESSION['flash_validation_demandes_finances']=['message'=>'FEB RH approuvée. Fiche '.$num.' créée ; décaissement en attente.','type'=>'success'];
            header('Location: '.$postListUrl); exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) FinancialWorkflowProgress::rollback($pdo);
            $_SESSION['flash_validation_demandes_finances']=['message'=>$e->getMessage(),'type'=>'error'];
            header('Location: '.$postListUrl); exit;
        }
    }
    $decaissementPost = $candidateOk ? febFinanceApplyProvisionalDecaissement($candidate, $_POST) : $_POST;
    $decaissementTouched = febFinanceDecaissementTouched($decaissementPost, $_FILES);
    $decaissementErrors = [];
    if($candidateOk&&$canValidateThis&&$decaissementTouched&&in_array($action,['approve','integrate_proposed_debourse'],true)&&(string)(((array)($candidate['payload']??[]))['budget_planned']??'')==='no'){
        try{
            $adjustedPayload=(array)($candidate['payload']??[]);$adjustedDraft=is_array($adjustedPayload['provisional_decaissement']??null)?(array)$adjustedPayload['provisional_decaissement']:[];
            $adjustedStart=trim((string)($decaissementPost['planned_start_date']??$adjustedPayload['planned_start_date']??''));
            $adjustedEnd=trim((string)($decaissementPost['planned_end_date']??$adjustedPayload['planned_end_date']??''));
            foreach(['début'=>$adjustedStart,'fin'=>$adjustedEnd]as$dateLabel=>$dateValue)if($dateValue!==''&&!FebWorkflowGuard::validDate($dateValue))throw new RuntimeException('La date de '.$dateLabel.' ajustée est invalide.');
            if($adjustedStart!==''&&$adjustedEnd!==''&&$adjustedEnd<$adjustedStart)throw new RuntimeException('La fin du planning ajusté doit être postérieure ou égale au début.');
            if($adjustedStart!==''&&$adjustedEnd!==''){
                $validatorDate=(new DateTimeImmutable($adjustedStart))->modify('-7 days')->format('Y-m-d');
                $adjustedDraft['date_decaissement_minimum']=$validatorDate;$adjustedDraft['planned_start_date']=$adjustedStart;$adjustedDraft['planned_end_date']=$adjustedEnd;$adjustedDraft['active']=true;
                $adjustedPayload['planned_start_date']=$adjustedStart;$adjustedPayload['planned_end_date']=$adjustedEnd;$adjustedPayload['date_livraison']=$adjustedStart;$adjustedPayload['feb_position_date']=$validatorDate;$adjustedPayload['disbursement_window_start']=$validatorDate;$adjustedPayload['disbursement_window_end']=$adjustedStart;$adjustedPayload['provisional_decaissement']=$adjustedDraft;
                if($adjustedPayload!==(array)($candidate['payload']??[])){
                    $adjustmentNote='Planning ajusté par le validateur du '.date('d/m/Y',strtotime($adjustedStart)).' au '.date('d/m/Y',strtotime($adjustedEnd)).' ; fenêtre de décaissement recalculée du '.date('d/m/Y',strtotime($validatorDate)).' au '.date('d/m/Y',strtotime($adjustedStart)).'.';
                    if(!$manager->updateFebBeforeDecision($id,$adjustedPayload,$actor,$adjustmentNote,true))throw new RuntimeException('L’ajustement du planning n’a pas pu être enregistré.');
                    $manager->addRequestEvent($id,'feb_disbursement_date_adjusted_by_validator',$adjustmentNote,$actor);$candidate=$manager->getRequestById($id)?:$candidate;$decaissementPost=febFinanceApplyProvisionalDecaissement($candidate,$_POST);
                }
            }
        }catch(Throwable $e){$decaissementErrors[]=$e->getMessage();}
    }
    if($decaissementTouched&&in_array($action,['approve','integrate_proposed_debourse'],true))$decaissementErrors=array_merge($decaissementErrors,febFinanceRequireDecaissementPayload($decaissementPost,$_FILES));
    $candidateBudgetPlanned = (string) (((array) ($candidate['payload'] ?? []))['budget_planned'] ?? '');
    if ($candidateOk && $action === 'approve') {
        $workPayload=(array)($candidate['payload']??[]);$workDraft=(array)($workPayload['provisional_decaissement']??[]);
        $workStart=trim((string)($workPayload['planned_start_date']??''));
        if ($candidateBudgetPlanned==='yes') try {$workPlanning=ExpiredFebPlanningAdjustmentService::preview($pdo,(int)($workDraft['debourse_id']??0),(int)($workDraft['chantier_id']??$workPayload['chantier_id']??0));if($workPlanning)$workStart=(string)$workPlanning['date_debut'];}catch(Throwable $ignored){}
        if (FebWorkflowGuard::validDate($workStart) && $workStart > date('Y-m-d',strtotime('+7 days'))) {
            $decaissementErrors[]='Cette dépense ne peut être approuvée qu’à partir de 7 jours avant le début des travaux ('.date('d/m/Y',strtotime($workStart)).').';
        }
        if (!empty($_POST['adjust_expired_planning']) && empty($_POST['confirm_expired_planning'])) {
            $decaissementErrors[]='Confirmez explicitement le nouveau planning avant l’approbation.';
        }
    }
    if($candidateOk&&$canValidateThis&&in_array($action,['approve','integrate_proposed_debourse'],true)){
        try{febCompleteSelectedSupplier($pdo,$_POST,$_FILES);SupplierApprovalGuard::assertPayload($pdo,(array)($candidate['payload']??[]),$decaissementPost);}catch(Throwable $e){$decaissementErrors[]=$e->getMessage();}
    }
    if($candidateOk&&$canValidateThis&&$action==='approve'){
        $treasuryPayload=(array)($candidate['payload']??[]);
        $treasuryDraft=(array)($treasuryPayload['provisional_decaissement']??[]);
        $treasuryAmount=(float)($_POST['feb_edit_amount']??$decaissementPost['fd_montant_fiche']??$treasuryDraft['montant_fiche']??$treasuryPayload['montant']??0);
        $treasuryDate=trim((string)($decaissementPost['fd_date_decaissement_minimum']??$treasuryDraft['date_decaissement_minimum']??$treasuryPayload['planned_start_date']??''));
        $fundingSource=trim((string)($_POST['treasury_funding_source']??''));$fundingInvoice=(int)($_POST['treasury_funding_invoice_id']??0);
        if(!in_array($fundingSource,['real_entries','forecast_invoice'],true))$decaissementErrors[]='Choisissez obligatoirement la source des fonds couvrant cette FEB.';
        if($fundingSource==='forecast_invoice'&&$fundingInvoice<=0)$decaissementErrors[]='Choisissez la facture prévisionnelle qui couvrira cette FEB.';
    }
    $approveCurrentValues = $candidateOk && $action === 'approve' && (!empty($_POST['approve_current_values']) || !empty($_POST['retain_initial_prices']));
    if ($candidateOk && $action === 'approve') {
        $candidatePayloadForBlock = (array)($candidate['payload'] ?? []);
        try {
            $urgentDisbursementService->assertChantierAvailable(
                (int)($candidatePayloadForBlock['chantier_id'] ?? 0),
                (int)($candidatePayloadForBlock['urgent_decaissement_id'] ?? 0)
            );
        } catch (RuntimeException $e) {
            $decaissementErrors[] = $e->getMessage();
        }
    }
    if ($candidateBudgetPlanned === '' && !empty(((array) ($candidate['payload'] ?? []))['proposed_debourse'])) {
        $candidateBudgetPlanned = 'no';
    }
    if ($decaissementTouched && $candidateBudgetPlanned === 'no') {
        $decaissementErrors = array_values(array_filter($decaissementErrors, static fn(string $error): bool => !str_contains($error, 'déboursé') && !str_contains($error, 'ligne de déboursé')));
    }
    if ($candidateOk && $action === 'approve' && in_array((string)($candidate['status']??''), ['submitted','details_requested'], true)) {
        $candidatePayloadForPrices=(array)($candidate['payload']??[]);
        $candidateNeeds = array_values(array_filter((array) ($candidatePayloadForPrices['besoins'] ?? []), 'is_array'));
        $coverage = $febPriceChallengeService->coverageForFeb($id, count($candidateNeeds));
        foreach ($candidateNeeds as $lineIndex => $candidateNeed) {
            if (!$approveCurrentValues) {
                if ((int) ($coverage[$lineIndex]['assigned'] ?? 0) === 0) {
                    $decaissementErrors[] = 'Aucun soumissionnaire contradictoire n’est paramétré pour la ligne FEB #' . ($lineIndex + 1) . ' (' . (string) ($candidateNeed['categorie'] ?? '') . ').';
                } elseif ((int) ($coverage[$lineIndex]['submitted'] ?? 0) === 0) {
                    $decaissementErrors[] = 'Au moins une proposition contradictoire avec justificatif est attendue pour la ligne FEB #' . ($lineIndex + 1) . '.';
                }
            }
        }
    }

    if (!$candidateOk) {
        $flash = 'Demande introuvable ou hors périmètre Finances.';
        $flashType = 'error';
    } elseif (!$canValidateThis) {
        $flash = 'Vous n’êtes pas autorisé à valider les FEB de ce service.';
        $flashType = 'error';
    } elseif (!in_array((string) ($candidate['status'] ?? ''), ['submitted', 'details_requested'], true)) {
        $flash = 'Cette demande n\'est plus disponible pour traitement.';
        $flashType = 'error';
    } elseif ($decaissementTouched && !in_array($action, ['approve','integrate_proposed_debourse','save_popup_adjustments','save_amount_adjustment','save_planning_adjustments','defer','bring_today','reject'], true)) {
        $flash = 'La génération automatique de fiche est renseignée : seule l’approbation de la FEB est possible. Videz le bloc de génération pour choisir une autre décision.';
        $flashType = 'error';
    } elseif ($decaissementErrors) {
        $flash = implode(' ', $decaissementErrors);
        $flashType = 'error';
    } else {
        if ($action === 'save_popup_adjustments') {
            try {
                FinancialWorkflowProgress::begin($pdo);
                $lock=$pdo->prepare('SELECT status FROM employee_legal_requests WHERE id=:id FOR UPDATE');
                $lock->execute(['id'=>$id]);
                if(!in_array((string)$lock->fetchColumn(),['submitted','details_requested'],true))throw new RuntimeException('Cette FEB vient d’être traitée. Rechargez la page.');
                $current=$manager->getRequestById($id);
                $before=(array)($current['payload']??[]);
                $post=$_POST;unset($post['generate_decaissement']);
                if(!empty($post['supplier_completion_enabled']))febCompleteSelectedSupplier($pdo,$post,$_FILES);
                $after=febBuildEditedPayload($before,$post);
                $oldDraft=(array)($before['provisional_decaissement']??[]);
                $newDraft=(array)($after['provisional_decaissement']??[]);
                if(array_key_exists('active',$oldDraft))$newDraft['active']=$oldDraft['active'];else unset($newDraft['active']);
                $after['provisional_decaissement']=$newDraft;
                if(isset($post['fd_beneficiary_mode']) && (
                    (string)$post['fd_beneficiary_mode']!==(string)($oldDraft['beneficiary_mode']??'') ||
                    (int)($post['fd_fournisseur_id']??0)!==(int)($oldDraft['fournisseur_id']??0) ||
                    (int)($post['fd_personnel_id']??0)!==(int)($oldDraft['personnel_id']??0)
                ))$after=FebAssignmentBeneficiary::apply($pdo,$after,$post);
                $newDraft=(array)($after['provisional_decaissement']??[]);
                $budgetChanged=false;
                foreach(['chantier_id','ligne_devis_id','debourse_id','ligne_debourse_id']as$key)
                    if((int)($oldDraft[$key]??0)!==(int)($newDraft[$key]??0))$budgetChanged=true;
                if($budgetChanged){
                    if(($after['budget_planned']??'')==='no' && !empty($after['proposed_debourse']) && (empty($newDraft['ligne_devis_id'])||empty($newDraft['debourse_id'])||empty($newDraft['ligne_debourse_id']))){
                        $site=$pdo->prepare('SELECT id FROM chantier_banamur WHERE id=:id AND archived_at IS NULL');
                        $site->execute(['id'=>(int)($newDraft['chantier_id']??0)]);
                        if(!$site->fetchColumn())throw new DomainException('Sélectionnez un chantier actif pour la proposition budgétaire.');
                    }else FebAssignmentBeneficiary::assertBudget($pdo,$newDraft);
                    $after['chantier_id']=(int)$newDraft['chantier_id'];
                }
                $amountChanged=(float)($after['montant']??0)!==(float)($before['montant']??0);
                if($amountChanged&&(float)$after['montant']<=0)throw new DomainException('Le montant doit être strictement positif.');
                $start=trim((string)($after['planned_start_date']??''));$end=trim((string)($after['planned_end_date']??''));
                $date=trim((string)($newDraft['date_decaissement_minimum']??''));
                $periodChanged=$start!==(string)($before['planned_start_date']??'')||$end!==(string)($before['planned_end_date']??'');
                $dateChanged=$date!==(string)($oldDraft['date_decaissement_minimum']??'');
                if($periodChanged&&($start===''||$end===''))throw new DomainException('Renseignez le début et la fin de la période.');
                $eventNote='Modifications enregistrées sans approbation. '.febDescribePayloadChanges($before,$after);
                if($after!==$before){
                    if(!$manager->updateFebBeforeDecision($id,$after,$actor,$eventNote,true))throw new RuntimeException('Les modifications n’ont pas pu être enregistrées.');
                    if($amountChanged){
                        febFinanceSynchronizeLinkedFicheAmount($pdo,$id,(float)$after['montant'],$actor);
                        $treasuryFundingService->synchronizeAmount($id,(float)$after['montant'],(int)($actor['user_id']??0));
                    }
                    if($periodChanged)$febPlanningSync->synchronizePeriod($id,$start,$end,$date!==''?$date:$start,$eventNote);
                    elseif($dateChanged&&$date!=='')$febPlanningSync->synchronizeDate($id,$date,$eventNote,(int)($actor['user_id']??0),(string)($actor['name']??''));
                    $manager->addRequestEvent($id,'feb_popup_adjusted_by_validator',$eventNote,$actor);
                }
                FinancialWorkflowProgress::commit($pdo);
                $_SESSION['flash_validation_demandes_finances']=['message'=>'Modifications enregistrées. La FEB conserve son statut ; aucune approbation ni création de fiche n’a été effectuée.','type'=>'success'];
                header('Location: '.$postListUrl);exit;
            }catch(Throwable $e){
                if($pdo->inTransaction())FinancialWorkflowProgress::rollback($pdo);
                $flash=$e->getMessage();$flashType='error';
            }
        }
 elseif ($action === 'save_amount_adjustment') {
            try {
                FinancialWorkflowProgress::begin($pdo);
                $payloadBeforeAmount = (array)($candidate['payload'] ?? []);
                $amountPost = [];
                foreach (['montant', 'fd_montant_fiche'] as $amountKey) {
                    if (array_key_exists($amountKey, $_POST)) $amountPost[$amountKey] = $_POST[$amountKey];
                }
                $amountPayload = febBuildEditedPayload($payloadBeforeAmount, $amountPost);
                $amount = (float)($amountPayload['montant'] ?? 0);
                if ($amount <= 0) throw new RuntimeException('Le montant de la FEB doit être strictement supérieur à zéro.');
                if ($amountPayload === $payloadBeforeAmount) throw new RuntimeException('Aucune modification de montant à enregistrer.');
                $eventNote = 'Montant de la FEB ajusté à '.number_format($amount, 0, ',', ' ').' FCFA par le validateur, sans approbation.';
                if (!$manager->updateFebBeforeDecision($id, $amountPayload, $actor, $eventNote, true)) {
                    throw new RuntimeException('Le montant de la FEB n’a pas pu être enregistré.');
                }
                febFinanceSynchronizeLinkedFicheAmount($pdo, $id, $amount, $actor);
                $treasuryFundingService->synchronizeAmount($id, $amount, (int)($actor['user_id'] ?? 0));
                $manager->addRequestEvent($id, 'feb_amount_adjusted_by_validator', $eventNote, $actor);
                FinancialWorkflowProgress::commit($pdo);
                $_SESSION['flash_validation_demandes_finances']=['message'=>'Montant de la FEB enregistré. Son statut reste inchangé : aucune approbation ni création de fiche n’a été effectuée.','type'=>'success'];
                header('Location: '.$postListUrl); exit;
            } catch (Throwable $e) {
                if($pdo->inTransaction())FinancialWorkflowProgress::rollback($pdo);
                $flash=$e->getMessage();$flashType='error';
            }
        } elseif ($action === 'save_planning_adjustments') {
            try {
                FinancialWorkflowProgress::begin($pdo);
                $payloadBeforePlanning = (array)($candidate['payload'] ?? []);
                $planningPost = [];
                foreach (['planned_start_date', 'planned_end_date'] as $planningKey) {
                    if (array_key_exists($planningKey, $_POST)) $planningPost[$planningKey] = $_POST[$planningKey];
                }
                if (array_key_exists('fd_date_decaissement_minimum', $_POST)) {
                    $planningPost['fd_date_decaissement_minimum'] = $_POST['fd_date_decaissement_minimum'];
                }
                $plannedPayload = febBuildEditedPayload($payloadBeforePlanning, $planningPost);
                $startDate = trim((string)($plannedPayload['planned_start_date'] ?? ''));
                $endDate = trim((string)($plannedPayload['planned_end_date'] ?? ''));
                $actionDate = trim((string)(((array)($plannedPayload['provisional_decaissement'] ?? []))['date_decaissement_minimum'] ?? ''));
                if ($startDate === '' || $endDate === '') throw new RuntimeException('Renseignez la date de début et la date de fin prévues pour l’action.');
                if ($actionDate !== '' && !FebWorkflowGuard::validDate($actionDate)) {
                    throw new RuntimeException('La date prévue de décaissement est invalide.');
                }
                if ($plannedPayload === $payloadBeforePlanning) throw new RuntimeException('Aucune modification de planning à enregistrer.');
                $eventNote = 'Planning ajusté par le validateur, sans décision : du '.date('d/m/Y', strtotime($startDate)).' au '.date('d/m/Y', strtotime($endDate)).($actionDate !== '' ? ', action prévue le '.date('d/m/Y', strtotime($actionDate)) : '').'.';
                if (!$manager->updateFebBeforeDecision($id, $plannedPayload, $actor, $eventNote, true)) {
                    throw new RuntimeException('Le planning de la FEB n’a pas pu être enregistré.');
                }
                $febPlanningSync->synchronizePeriod($id,$startDate,$endDate,$actionDate!==''?$actionDate:$startDate,$eventNote);
                $manager->addRequestEvent($id, 'feb_planning_adjusted_by_validator', $eventNote, $actor);
                FinancialWorkflowProgress::commit($pdo);
                $_SESSION['flash_validation_demandes_finances']=['message'=>'Planning de la FEB enregistré. Son statut reste inchangé : aucune approbation ni intégration n’a été effectuée.','type'=>'success'];
                header('Location: '.$postListUrl); exit;
            } catch (Throwable $e) {
                if($pdo->inTransaction())FinancialWorkflowProgress::rollback($pdo);
                $flash=$e->getMessage();$flashType='error';
            }
        } elseif (in_array($action, ['defer', 'bring_today'], true)) {
            try {
                FinancialWorkflowProgress::begin($pdo);
                $deferredPayload = (array)($candidate['payload'] ?? []);
                $targetDate = $action === 'bring_today' ? date('Y-m-d') : trim((string)($_POST['deferred_until'] ?? ''));
                if (!FebWorkflowGuard::validDate($targetDate)) throw new RuntimeException('Sélectionnez une date de report valide.');
                if ($action === 'defer' && $targetDate <= date('Y-m-d')) throw new RuntimeException('La date de report doit être postérieure à aujourd’hui.');
                $deferredPayload['validation_deferred_until'] = $targetDate;
                $deferredPayload['validation_deferred_by'] = (string)($actor['name'] ?? '');
                $deferredPayload['validation_deferred_at'] = date('c');
                if ($action === 'bring_today') {
                    $deferredPayload['planned_start_date'] = $targetDate;
                    $deferredPayload['planned_end_date'] = max($targetDate, (string)($deferredPayload['planned_end_date'] ?? $targetDate));
                    $deferredPayload['date_livraison'] = $targetDate;
                    $draftToday = is_array($deferredPayload['provisional_decaissement'] ?? null) ? (array)$deferredPayload['provisional_decaissement'] : [];
                    $draftToday['date_decaissement_minimum'] = $targetDate;
                    $deferredPayload['provisional_decaissement'] = $draftToday;
                }
                $eventNote = $action === 'bring_today' ? 'FEB ramenée à la date du jour pour anticipation du décaissement.' : 'FEB reportée jusqu’au ' . date('d/m/Y', strtotime($targetDate)) . '.';
                if (!$manager->updateFebBeforeDecision($id, $deferredPayload, $actor, $eventNote, true)) throw new RuntimeException('Le report de la FEB n’a pas pu être enregistré.');
                $febPlanningSync->synchronizeDate($id,$targetDate,$eventNote,(int)($actor['user_id']??0),(string)($actor['name']??''));
                $manager->addRequestEvent($id, $action === 'bring_today' ? 'feb_deferred_brought_today' : 'feb_deferred', $eventNote, $actor);
                FinancialWorkflowProgress::commit($pdo);
                $_SESSION['flash_validation_demandes_finances']=['message'=>$eventNote,'type'=>'success'];
                header('Location: '.$postListUrl); exit;
            } catch (Throwable $e) {
                if($pdo->inTransaction())FinancialWorkflowProgress::rollback($pdo);
                $flash=$e->getMessage();$flashType='error';
            }
        } elseif ($action === 'integrate_proposed_debourse') {
            try {
                FinancialWorkflowProgress::begin($pdo);
                $integrationEditSummary = '';
                $payloadBeforeIntegrationEdit = (array)($candidate['payload'] ?? []);
                $payloadForIntegration = febBuildEditedPayload($payloadBeforeIntegrationEdit, $_POST);
                if ($payloadForIntegration !== $payloadBeforeIntegrationEdit) {
                    $integrationEditSummary = febDescribePayloadChanges($payloadBeforeIntegrationEdit, $payloadForIntegration);
                    if (!$manager->updateFebBeforeDecision($id, $payloadForIntegration, $actor, $integrationEditSummary, true)) {
                        throw new RuntimeException('Les ajustements saisis dans le popup n’ont pas pu être enregistrés.');
                    }
                    $candidate = $manager->getRequestById($id) ?: $candidate;
                }
                $integration = febFinanceCreateProposedDebourse($pdo, $candidate, $actor, $_POST);
                if (!$integration || (int)($integration['debourse_id'] ?? 0) <= 0) {
                    throw new RuntimeException('La création du déboursé n’a retourné aucun identifiant vérifiable.');
                }
                $integratedPayload = (array)($candidate['payload'] ?? []);
                $proposal = (array)($integratedPayload['proposed_debourse'] ?? []);
                $proposalLines = array_values(array_filter((array)($proposal['lines'] ?? []), 'is_array'));
                $payloadNeeds = array_values(array_filter((array)($integratedPayload['besoins'] ?? []), 'is_array'));
                $firstNeed = (array)($payloadNeeds[0] ?? []);
                $firstNeedSupplier = (array)($firstNeed['fournisseur'] ?? []);
                $draft = (array)($integratedPayload['provisional_decaissement'] ?? []);
                $scheduledDate = trim((string)($_POST['fd_date_decaissement_minimum'] ?? $draft['date_decaissement_minimum'] ?? ''));
                $createdLineScheduleStmt=$pdo->prepare('SELECT date_debut,date_fin FROM ligne_debourse_banamur WHERE id=:id LIMIT 1');
                $createdLineScheduleStmt->execute(['id'=>(int)$integration['ligne_debourse_id']]);
                $createdLineSchedule=$createdLineScheduleStmt->fetch(PDO::FETCH_ASSOC)?:[];
                $createdLineStart=trim((string)($createdLineSchedule['date_debut']??$integratedPayload['planned_start_date']??''));
                $createdLineEnd=trim((string)($createdLineSchedule['date_fin']??$integratedPayload['planned_end_date']??$createdLineStart));
                if ($createdLineStart!==''&&FebWorkflowGuard::validDate($createdLineStart))$scheduledDate=(new DateTimeImmutable($createdLineStart))->modify('-7 days')->format('Y-m-d');
                $draft = array_merge($draft, [
                    'active' => true,
                    'beneficiary_choice' => (string)($draft['beneficiary_choice'] ?? $integratedPayload['beneficiary_choice'] ?? 'requester'),
                    'beneficiary_mode' => (string)($_POST['fd_beneficiary_mode'] ?? $draft['beneficiary_mode'] ?? (($integratedPayload['beneficiary_choice'] ?? 'requester') === 'supplier' ? 'supplier' : 'personnel')),
                    'personnel_id' => max((int)($_POST['fd_personnel_id'] ?? 0), (int)($draft['personnel_id'] ?? 0), (int)($integratedPayload['personnel_id'] ?? 0)),
                    'chantier_id' => max((int)($_POST['fd_chantier_id'] ?? 0), (int)($integratedPayload['chantier_id'] ?? 0), (int)($draft['chantier_id'] ?? 0)),
                    'devis_id' => (int)$integration['devis_id'],
                    'ligne_devis_id' => (int)$integration['ligne_devis_id'],
                    'debourse_id' => (int)$integration['debourse_id'],
                    'ligne_debourse_id' => (int)$integration['ligne_debourse_id'],
                    'montant_fiche' => (float)febMoneyInput($_POST['fd_montant_fiche'] ?? $draft['montant_fiche'] ?? $proposal['total_amount'] ?? $integratedPayload['montant'] ?? 0),
                    'fournisseur_id' => (array_key_exists('fd_fournisseur_id',$_POST)?max(0,(int)$_POST['fd_fournisseur_id']):FebAssignmentBeneficiary::supplierId($integratedPayload)),
                    'mode_paiement' => trim((string)($_POST['fd_mode_paiement'] ?? $draft['mode_paiement'] ?? $integratedPayload['mode_paiement'] ?? '')),
                    'date_decaissement_minimum' => $scheduledDate !== '' ? $scheduledDate : null,
                    'details_demande' => trim((string)($_POST['fd_details_demande'] ?? $draft['details_demande'] ?? $integratedPayload['description'] ?? '')),
                ]);
                $integratedPayload['budget_planned'] = 'yes';
                $integratedPayload['budget_planned_label'] = 'Intégrée au déboursé';
                $integratedPayload['planned_start_date']=$createdLineStart;
                $integratedPayload['planned_end_date']=$createdLineEnd;
                $integratedPayload['date_livraison']=$createdLineStart;
                $integratedPayload['feb_position_date']=$scheduledDate;
                $integratedPayload['disbursement_window_start']=$scheduledDate;
                $integratedPayload['disbursement_window_end']=$createdLineStart;
                $integratedPayload['provisional_decaissement'] = $draft;
                $integratedPayload['integrated_debourse'] = [
                    'debourse_id'=>(int)$integration['debourse_id'],
                    'ligne_debourse_id'=>(int)$integration['ligne_debourse_id'],
                    'devis_id'=>(int)$integration['devis_id'],
                    'ligne_devis_id'=>(int)$integration['ligne_devis_id'],
                    'integrated_at'=>date('Y-m-d H:i:s'),
                    'integrated_by'=>(string)($actor['name']??''),
                ];
                $noteIntegration = 'Déboursé proposé intégré sans approbation finale de la FEB : déboursé #' . (int)$integration['debourse_id']
                    . ', ligne de déboursé #' . (int)$integration['ligne_debourse_id'] . '. La FEB est maintenant prête pour le contrôle de génération de fiche.';
                if ($integrationEditSummary !== '') $noteIntegration .= ' Ajustements appliqués : ' . $integrationEditSummary;
                if (!$manager->updateFebBeforeDecision($id, $integratedPayload, $actor, $noteIntegration, true)) {
                    throw new RuntimeException('Le déboursé a été préparé mais la FEB n’a pas pu être rattachée.');
                }
                $manager->addRequestEvent($id, 'feb_disbursement_integrated', $noteIntegration, $actor);
                FinancialWorkflowProgress::commit($pdo);
                try {
                    FinancialWorkflowProgress::step('Préparation et transmission des notifications.');(new \NotificationController($pdo))->notifyLegalRequestModifiedByValidator($candidate, $actor, $noteIntegration);
                } catch (Throwable $notifyError) {
                }
                \AuditTrail::log('finance_request_debourse_integrated', [
                    'module'=>'finance','action'=>'validation_demandes_finances_integrate_debourse',
                    'entity_type'=>'feb_request','entity_id'=>(string)$id,'entity_label'=>(string)($candidate['title']??('FEB #'.$id)),
                    'user_id'=>(int)($actor['user_id']??0),'user_name'=>(string)($actor['name']??''),
                    'details'=>['debourse_id'=>(int)$integration['debourse_id'],'ligne_debourse_id'=>(int)$integration['ligne_debourse_id'],'devis_id'=>(int)$integration['devis_id']],
                ], $pdo);
                header('Location: debourse/gerer_debourse.php?devisId=' . (int)$integration['devis_id'] . '&focus_debourse=' . (int)$integration['debourse_id'] . '#planning-debourse');
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) FinancialWorkflowProgress::rollback($pdo);
                $_SESSION['flash_validation_demandes_finances'] = ['message'=>'Intégration du déboursé échouée : '.$e->getMessage(),'type'=>'error'];
                \AuditTrail::log('finance_request_debourse_integration_failed', [
                    'module'=>'finance','action'=>'validation_demandes_finances_integrate_debourse',
                    'entity_type'=>'feb_request','entity_id'=>(string)$id,'entity_label'=>(string)($candidate['title']??('FEB #'.$id)),
                    'result'=>'failure','severity'=>'warning','user_id'=>(int)($actor['user_id']??0),'user_name'=>(string)($actor['name']??''),
                    'details'=>['error'=>$e->getMessage()],
                ], $pdo);
                header('Location: ' . $postListUrl);
                exit;
            }
        } elseif (in_array($action, ['request_more_details', 'report'], true)) {
            $eventType = $action === 'report' ? 'feb_validation_reported' : 'feb_more_details_requested';
            $eventNote = $note !== '' ? $note : ($action === 'report' ? 'Traitement reporté par le validateur.' : 'Précisions demandées sur le rattachement budgétaire, le montant ou le planning.');
            $detailsStmt = $pdo->prepare('
                UPDATE employee_legal_requests
                SET status = :status,
                    updated_at = NOW(),
                    decided_at = NULL,
                    decided_by_user_id = NULL,
                    decided_by_name = NULL,
                    decision_note = ""
                WHERE id = :id
                  AND request_type = "feb"
                  AND status IN ("submitted", "details_requested")
                LIMIT 1
            ');
            $detailsOk = $detailsStmt->execute([
                'status' => 'details_requested',
                'id' => $id,
            ]);
            if ($detailsOk && $manager->addRequestEvent($id, $eventType, $eventNote, $actor)) {
                if (in_array($action, ['request_more_details', 'report'], true)) {
                    try {
                        FinancialWorkflowProgress::step('Préparation et transmission des notifications.');$notif = new \NotificationController($pdo);
                        $notif->notifyLegalRequestNeedDetails($candidate, $actor, $eventNote);
                    } catch (Throwable $e) {
                        // Ne pas bloquer la demande de complement si la notification echoue.
                    }
                }
                $flash = $action === 'report' ? 'Demande retournee au demandeur pour complements.' : 'Demande de complement envoyee au demandeur.';
                $flashType = 'success';
                $_SESSION['flash_validation_demandes_finances'] = ['message' => $flash, 'type' => $flashType];
                header('Location: ' . $postListUrl);
                exit;
            }
            $flash = 'Impossible d\'envoyer cette demande de complement.';
            $flashType = 'error';
        } else {
            $payloadBeforeEdit = (array)($candidate['payload'] ?? []);
            $editedPayload = febBuildEditedPayload($payloadBeforeEdit, $_POST);
            if ($editedPayload !== $payloadBeforeEdit) {
                FinancialWorkflowProgress::begin($pdo);
                try {
                $changeSummary = febDescribePayloadChanges($payloadBeforeEdit, $editedPayload);
                if (!$manager->updateFebBeforeDecision($id, $editedPayload, $actor, $changeSummary, $action === 'approve')) throw new RuntimeException('La FEB a changé ou ne peut plus être modifiée.');
                $synchronizedAmount = (float)($editedPayload['montant'] ?? 0);
                febFinanceSynchronizeLinkedFicheAmount($pdo, $id, $synchronizedAmount, $actor);
                $treasuryFundingService->synchronizeAmount($id, $synchronizedAmount, (int)($actor['user_id'] ?? 0));
                FinancialWorkflowProgress::commit($pdo);
                } catch (Throwable $editError) {
                    if ($pdo->inTransaction()) FinancialWorkflowProgress::rollback($pdo);
                    $_SESSION['flash_validation_demandes_finances']=['type'=>'error','message'=>'Modification annulée : '.$editError->getMessage()];
                    header('Location: '.$postListUrl); exit;
                }
                try {
                    FinancialWorkflowProgress::step('Préparation et transmission des notifications.');(new \NotificationController($pdo))->notifyLegalRequestModifiedByValidator($candidate, $actor, $changeSummary);
                } catch (Throwable $notifyEditError) {
                }
                $candidate = $manager->getRequestById($id) ?: $candidate;
            }

            if ($action === 'approve') {
                if ($decaissementTouched) {
                    $createdFicheNum = '';
                    try {
                        febFinanceEnsureTraceFicheTable($pdo);
                        febFinanceEnsureFichePlanningColumns($pdo);
                        FinancialWorkflowProgress::begin($pdo);
                        $candidate=febApplyExpiredPlanningChoice($pdo,$manager,$id,$candidate,$actor);
                        $candidate=febFinanceSaveApprovalAmount($manager,$id,$candidate,$_POST,$actor);
                        if($approveCurrentValues){
                            $febPriceChallengeService->closeForCurrentApproval($id);
                            $manager->addRequestEvent($id,'feb_current_values_approved','Approbation sans proposition contradictoire : les dernières valeurs enregistrées de la FEB sont conservées ; les consultations ouvertes sont clôturées.',$actor);
                        }
                        $candidate=$manager->getRequestById($id)?:$candidate;
                        $decaissementPost=febFinanceApplyApprovedFebValues($candidate,$decaissementPost);
                        FinancialWorkflowProgress::step('Contrôle de la couverture et réservation du financement.');$funding=$treasuryFundingService->reserve($id,(string)($_POST['treasury_funding_source']??''),(int)($_POST['treasury_funding_invoice_id']??0),(float)($decaissementPost['fd_montant_fiche']??0),(string)($decaissementPost['fd_date_decaissement_minimum']??date('Y-m-d')),(int)($actor['user_id']??0));
                        $decaissementPost['fd_date_decaissement_minimum']=$funding['scheduled_date'];$_POST['fd_date_decaissement_minimum']=$funding['scheduled_date'];
                        $syncedPlanning=$candidateBudgetPlanned==='no'
                            ? $febPlanningSync->synchronizeDate($id,$funding['scheduled_date'],'Planning synchronisé avec la source de financement réservée.',(int)($actor['user_id']??0),(string)($actor['name']??''))
                            : $febPlanningSync->synchronizePaymentDate($id,$funding['scheduled_date'],'Date de décaissement mise à jour sans déplacer la période du déboursé.',(int)($actor['user_id']??0),(string)($actor['name']??''));
                        $decaissementPost['planned_start_date']=$syncedPlanning['start'];$decaissementPost['planned_end_date']=$syncedPlanning['end'];$candidate=$manager->getRequestById($id)?:$candidate;
                        $integration = $candidateBudgetPlanned === 'no'
                            ? febFinanceCreateProposedDebourse($pdo, $candidate, $actor, $_POST)
                            : febFinanceLinkExistingDebourse($pdo, $candidate, $actor, $decaissementPost);

                        if ($candidateBudgetPlanned === 'no') {
                            $decaissementPost['fd_chantier_id'] = (string) ((int) (((array) ($candidate['payload'] ?? []))['chantier_id'] ?? 0));
                            $decaissementPost['fd_debourse_id'] = (string) $integration['debourse_id'];
                            $decaissementPost['fd_ligne_debourse_id'] = (string) $integration['ligne_debourse_id'];
                        }
                        SupplierApprovalGuard::assertPayload($pdo,(array)($candidate['payload']??[]),$decaissementPost);
                    if (!$manager->approveRequest($id, $actor, $note, false, true)) {
                            throw new RuntimeException('Impossible d’approuver la FEB avant création de la fiche.');
                        }
                        if (!$pdo->inTransaction()) {
                            throw new RuntimeException('La transaction FEB a été interrompue pendant l’approbation.');
                        }
                        $approvedCandidate = $manager->getRequestById($id) ?: $candidate;
                        $approvedPayload = (array) ($approvedCandidate['payload'] ?? []);
                        $approvedDraft = (array) ($approvedPayload['provisional_decaissement'] ?? []);
                        $scheduledDate = trim((string) ($decaissementPost['fd_date_decaissement_minimum']
                            ?? $approvedDraft['date_decaissement_minimum']
                            ?? $approvedDraft['planned_start_date']
                            ?? $approvedPayload['planned_start_date']
                            ?? $approvedPayload['date_livraison']
                            ?? date('Y-m-d')));
                        $bciResult = $manager->generateFebBciForScheduledDisbursement($id, $scheduledDate, $actor);
                        if (!$pdo->inTransaction()) {
                            throw new RuntimeException('La transaction FEB a été interrompue pendant la génération du BCI.');
                        }
                        if (trim((string) ($bciResult['code'] ?? '')) === '') {
                            throw new RuntimeException('Le BCI n’a pas pu être généré pour la date de décaissement prévue.');
                        }
                        $approvedCandidate = $manager->getRequestById($id) ?: $approvedCandidate;
                        $decaissementPost=febFinanceApplyApprovedFebValues($approvedCandidate,$decaissementPost);
                        $createdFicheNum = febFinanceCreateLinkedFiche($pdo, $approvedCandidate, $actor, $decaissementPost, $_FILES);
                        $verifyFiche=$pdo->prepare('SELECT montant_fiche,montant_initial_fiche,details_demande FROM fiche_decaissement WHERE feb_request_id=:feb AND num_fiche=:num LIMIT 1');
                        $verifyFiche->execute(['feb'=>$id,'num'=>$createdFicheNum]);$verified=$verifyFiche->fetch(PDO::FETCH_ASSOC);
                        $finalPayload=(array)($approvedCandidate['payload']??[]);
                        $finalDescription=trim((string)($finalPayload['description']??''));if($finalDescription==='')$finalDescription=trim((string)($approvedCandidate['title']??''));
                        if(!$verified||abs((float)$verified['montant_fiche']-(float)($finalPayload['montant']??0))>=0.005||abs((float)$verified['montant_initial_fiche']-(float)($finalPayload['montant']??0))>=0.005||trim((string)$verified['details_demande'])!==$finalDescription)throw new RuntimeException('La fiche générée ne reprend pas exactement le montant et les détails de la FEB approuvée. Approbation annulée.');
                        if (!$pdo->inTransaction()) {
                            throw new RuntimeException('La transaction FEB a été interrompue pendant la création de la fiche.');
                        }
                        if ($createdFicheNum === '') {
                            throw new RuntimeException('La fiche de décaissement n’a pas retourné de numéro.');
                        }
                        $manager->addRequestEvent($id, 'feb_linked_decaissement_created', 'Fiche de décaissement créée automatiquement : ' . $createdFicheNum, $actor);
                        FinancialWorkflowProgress::commit($pdo);

                        try {
                            $integratedDevisId=(int)($integration['devis_id']??0);
                            if($integratedDevisId>0){
                                $pdo->prepare('UPDATE devis_banamur SET billing_date=COALESCE(billing_date,:billing_date) WHERE id=:id')->execute(['billing_date'=>$scheduledDate,'id'=>$integratedDevisId]);
                                (new QuoteBillingAlertService($pdo))->schedule($integratedDevisId,'validation_feb',(int)($actor['user_id']??0));
                            }
                        } catch(Throwable $billingAlertError) {
                            // L'alerte ne remet pas en cause une approbation et une fiche déjà validées.
                        }

                        $flash = 'FEB approuvée et fiche de décaissement créée avec succès : ' . $createdFicheNum . '.'
                            .($approveCurrentValues?' Les dernières valeurs enregistrées ont été conservées et les consultations contradictoires clôturées.':'');
                        $flashType = 'success';
                        try {
                            FinancialWorkflowProgress::step('Préparation et transmission des notifications.');$notif = new \NotificationController($pdo);
                            $notif->notifyLegalRequestProcessed($approvedCandidate, 'approved', $actor, $note);
                        } catch (Throwable $notifyError) {
                            // Ne pas annuler l'approbation si la notification post-commit echoue.
                        }
                        \AuditTrail::log('finance_request_approved_with_decaissement', [
                            'module' => 'finance',
                            'action' => 'validation_demandes_finances_approve_create_decaissement',
                            'entity_type' => 'feb_request',
                            'entity_id' => (string) $id,
                            'entity_label' => is_array($candidate) ? (string) (($candidate['title'] ?? '') ?: ('request_' . $id)) : ('request_' . $id),
                            'user_id' => (int) ($actor['user_id'] ?? 0),
                            'user_name' => (string) ($actor['name'] ?? ''),
                            'details' => [
                                'note' => $note !== '' ? $note : null,
                                'tab' => $postTab,
                                'service_id' => $candidateServiceId,
                                'linked_decaissement_num' => $createdFicheNum,
                            ],
                        ], $pdo);
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) {
                            FinancialWorkflowProgress::rollback($pdo);
                        }
                        $flash = 'Création de la fiche échouée : ' . $e->getMessage() . ' La FEB n’a pas été approuvée.';
                        $flashType = 'error';
                        \AuditTrail::log('finance_request_approve_create_decaissement_failed', [
                            'module' => 'finance',
                            'action' => 'validation_demandes_finances_approve_create_decaissement',
                            'entity_type' => 'feb_request',
                            'entity_id' => (string) $id,
                            'entity_label' => is_array($candidate) ? (string) (($candidate['title'] ?? '') ?: ('request_' . $id)) : ('request_' . $id),
                            'user_id' => (int) ($actor['user_id'] ?? 0),
                            'user_name' => (string) ($actor['name'] ?? ''),
                            'result' => 'failure',
                            'severity' => 'warning',
                            'details' => [
                                'note' => $note !== '' ? $note : null,
                                'tab' => $postTab,
                                'service_id' => $candidateServiceId,
                                'error' => $e->getMessage(),
                            ],
                        ], $pdo);
                    }
                    $_SESSION['flash_validation_demandes_finances'] = ['message' => $flash, 'type' => $flashType];
                    header('Location: ' . $postListUrl);
                    exit;
                }

                $createdDebourse = null;
                try {
                    FinancialWorkflowProgress::begin($pdo);
                    $candidate=febApplyExpiredPlanningChoice($pdo,$manager,$id,$candidate,$actor);
                    $candidate=febFinanceSaveApprovalAmount($manager,$id,$candidate,$_POST,$actor);
                    if($approveCurrentValues){
                        $febPriceChallengeService->closeForCurrentApproval($id);
                        $manager->addRequestEvent($id,'feb_current_values_approved','Approbation sans proposition contradictoire : les dernières valeurs enregistrées de la FEB sont conservées ; les consultations ouvertes sont clôturées.',$actor);
                    }
                    $decaissementPost=febFinanceApplyApprovedFebValues($candidate,$decaissementPost);
                    $fundingAmount=(float)$decaissementPost['fd_montant_fiche'];
                    FinancialWorkflowProgress::step('Contrôle de la couverture et réservation du financement.');$funding=$treasuryFundingService->reserve($id,(string)($_POST['treasury_funding_source']??''),(int)($_POST['treasury_funding_invoice_id']??0),$fundingAmount,(string)($decaissementPost['fd_date_decaissement_minimum']??date('Y-m-d')),(int)($actor['user_id']??0));
                    $decaissementPost['fd_date_decaissement_minimum']=$funding['scheduled_date'];$_POST['fd_date_decaissement_minimum']=$funding['scheduled_date'];
                    $syncedPlanning=$candidateBudgetPlanned==='no'
                        ? $febPlanningSync->synchronizeDate($id,$funding['scheduled_date'],'Planning synchronisé avec la source de financement réservée.',(int)($actor['user_id']??0),(string)($actor['name']??''))
                        : $febPlanningSync->synchronizePaymentDate($id,$funding['scheduled_date'],'Date de décaissement mise à jour sans déplacer la période du déboursé.',(int)($actor['user_id']??0),(string)($actor['name']??''));
                    $decaissementPost['planned_start_date']=$syncedPlanning['start'];$decaissementPost['planned_end_date']=$syncedPlanning['end'];$candidate=$manager->getRequestById($id)?:$candidate;
                    if ($candidateBudgetPlanned === 'no') {
                        $createdDebourse = febFinanceCreateProposedDebourse($pdo, $candidate, $actor, $_POST);
                    } else {
                        $draft = (array) (((array) ($candidate['payload'] ?? []))['provisional_decaissement'] ?? []);
                        if ((int) ($draft['debourse_id'] ?? 0) > 0 && (int) ($draft['ligne_debourse_id'] ?? 0) > 0) {
                            $createdDebourse = febFinanceLinkExistingDebourse($pdo, $candidate, $actor, $decaissementPost);
                        }
                    }
                    SupplierApprovalGuard::assertPayload($pdo,(array)($candidate['payload']??[]),$decaissementPost);
                    if (!$manager->approveRequest($id, $actor, $note, false, true)) {
                        throw new RuntimeException('Impossible d’approuver ou de régulariser cette FEB.');
                    }
                    FinancialWorkflowProgress::commit($pdo);
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) FinancialWorkflowProgress::rollback($pdo);
                    $flash = 'Intégration du déboursé échouée : ' . $e->getMessage() . ' La FEB n’a pas été approuvée.';
                    $flashType = 'error';
                    $_SESSION['flash_validation_demandes_finances'] = ['message' => $flash, 'type' => $flashType];
                    header('Location: ' . $postListUrl);
                    exit;
                }
                try {
                    $approvedCandidate = $manager->getRequestById($id) ?: $candidate;
                    FinancialWorkflowProgress::step('Préparation et transmission des notifications.');(new \NotificationController($pdo))->notifyLegalRequestProcessed($approvedCandidate, 'approved', $actor, $note);
                } catch (Throwable $notifyError) {
                    // La décision est déjà validée ; une notification ne doit pas l'annuler.
                }
                if ($createdDebourse) {
                    $integrationLabel = !empty($createdDebourse['existing']) ? 'rattaché' : 'créé';
                    $manager->addRequestEvent($id, 'feb_disbursement_integrated', 'Déboursé #' . (int) $createdDebourse['debourse_id'] . ' ' . $integrationLabel . ' au devis ' . (string) ($createdDebourse['devis_type'] ?? 'du chantier') . ' lors de l’approbation.', $actor);
                }
                    $flash = $createdDebourse
                        ? 'FEB approuvée et intégrée au déboursé #' . (int) $createdDebourse['debourse_id'] . ' sur le chantier.'
                        : 'Demande approuvée.';
                    if($approveCurrentValues)$flash.=' Les dernières valeurs enregistrées ont été conservées et les consultations contradictoires clôturées.';
                    $flashType = 'success';
                    $createdFicheNum = '';
                    \AuditTrail::log('finance_request_approved', [
                        'module' => 'finance',
                        'action' => 'validation_demandes_finances_approve',
                        'entity_type' => 'feb_request',
                        'entity_id' => (string) $id,
                        'entity_label' => is_array($candidate) ? (string) (($candidate['title'] ?? '') ?: ('request_' . $id)) : ('request_' . $id),
                        'user_id' => (int) ($actor['user_id'] ?? 0),
                        'user_name' => (string) ($actor['name'] ?? ''),
                        'details' => [
                            'note' => $note !== '' ? $note : null,
                            'tab' => $postTab,
                            'service_id' => $candidateServiceId,
                            'linked_decaissement_num' => $createdFicheNum !== '' ? $createdFicheNum : null,
                        ],
                    ], $pdo);
                    $_SESSION['flash_validation_demandes_finances'] = ['message' => $flash, 'type' => $flashType];
                    header('Location: ' . $postListUrl);
                    exit;
            } elseif ($action === 'reject') {
                $rejectionPrepared=false;
                try{FinancialWorkflowProgress::begin($pdo);febFinanceCancelPreparationsForRejection($pdo,$manager,$candidate,$actor);$rejectionPrepared=true;}catch(Throwable $cleanupError){if($pdo->inTransaction())FinancialWorkflowProgress::rollback($pdo);$flash='Annulation automatique impossible : '.$cleanupError->getMessage();$flashType='error';}
                if ($rejectionPrepared && $manager->rejectRequest($id, $actor, $note, false)) {
                    if ($pdo->inTransaction()) FinancialWorkflowProgress::commit($pdo);
                    try {
                        FinancialWorkflowProgress::step('Préparation et transmission des notifications.');(new \NotificationController($pdo))->notifyLegalRequestProcessed($candidate, 'rejected', $actor, $note);
                    } catch (Throwable $notificationError) {
                        // Une notification indisponible ne doit jamais annuler un refus déjà validé.
                    }
                    $flash = 'Demande refusée. La génération de fiche, les consultations ouvertes et les rattachements préparatoires ont été annulés automatiquement.';
                    $flashType = 'success';
                    \AuditTrail::log('finance_request_rejected', [
                        'module' => 'finance',
                        'action' => 'validation_demandes_finances_reject',
                        'entity_type' => 'feb_request',
                        'entity_id' => (string) $id,
                        'entity_label' => is_array($candidate) ? (string) (($candidate['title'] ?? '') ?: ('request_' . $id)) : ('request_' . $id),
                        'user_id' => (int) ($actor['user_id'] ?? 0),
                        'user_name' => (string) ($actor['name'] ?? ''),
                        'details' => [
                            'note' => $note !== '' ? $note : null,
                            'tab' => $postTab,
                            'service_id' => $candidateServiceId,
                        ],
                    ], $pdo);
                    $_SESSION['flash_validation_demandes_finances'] = ['message' => $flash, 'type' => $flashType];
                    header('Location: ' . $postListUrl);
                    exit;
                } else {
                    if($pdo->inTransaction())FinancialWorkflowProgress::rollback($pdo);
                    if($flashType!=='error')$flash = 'Impossible de refuser cette demande.';
                    $flashType = 'error';
                    \AuditTrail::log('finance_request_reject_failed', [
                        'module' => 'finance',
                        'action' => 'validation_demandes_finances_reject',
                        'entity_type' => 'feb_request',
                        'entity_id' => (string) $id,
                        'entity_label' => 'request_' . $id,
                        'user_id' => (int) ($actor['user_id'] ?? 0),
                        'user_name' => (string) ($actor['name'] ?? ''),
                        'result' => 'failure',
                        'severity' => 'warning',
                        'details' => ['note' => $note !== '' ? $note : null, 'tab' => $postTab],
                    ], $pdo);
                }
            }
        }
    }
}

$rows = [];
try {
    if ($tab === 'mine_pending' || $tab === 'unplanned_analysis' || $tab === 'future' || $tab === 'deferred') {
        $rows = $manager->listRequestsForTeamAdvanced('finance', ['submitted'], 'feb', $serviceFilter, 300, $includeLegacyFeb);
    } elseif ($tab === 'details_requested') {
        $rows = $manager->listRequestsForTeamAdvanced('finance', ['details_requested'], 'feb', $serviceFilter, 300, $includeLegacyFeb);
    } elseif ($tab === 'approved') {
        $rows = $manager->listRequestsForTeamAdvanced('finance', ['approved'], 'feb', $serviceFilter, 300, $includeLegacyFeb);
    } elseif ($tab === 'rejected') {
        $rows = $manager->listRequestsForTeamAdvanced('finance', ['rejected'], 'feb', $serviceFilter, 300, $includeLegacyFeb);
    } elseif ($tab === 'cancelled') {
        $rows = $manager->listRequestsForTeamAdvanced('finance', ['cancelled'], 'feb', $serviceFilter, 300, $includeLegacyFeb);
    } else {
        $rows = $manager->listRequestsForTeamAdvanced('finance', ['submitted', 'details_requested', 'approved', 'rejected', 'cancelled'], 'feb', $serviceFilter, 500, $includeLegacyFeb);
    }
    // An exact FEB identifier must be searchable even when it falls outside
    // the initial 300/500-row window. Keep service permissions and tab status.
    if (preg_match('/^#?(?:FEB[- ]?)?(\\d+)$/i', $searchQuery, $febIdMatch)) {
        $searchedFebId = (int) $febIdMatch[1];
        $alreadyListed = false;
        foreach ($rows as $listedFeb) {
            if ((int) ($listedFeb['id'] ?? 0) === $searchedFebId) {
                $alreadyListed = true;
                break;
            }
        }
        if (!$alreadyListed) {
            $searchedFeb = $manager->getRequestById($searchedFebId);
            $tabStatuses = match ($tab) {
                'details_requested' => ['details_requested'],
                'approved' => ['approved'],
                'rejected' => ['rejected'],
                'cancelled' => ['cancelled'],
                'all' => ['submitted', 'details_requested', 'approved', 'rejected', 'cancelled'],
                default => ['submitted'],
            };
            if (is_array($searchedFeb)
                && ($searchedFeb['request_type'] ?? '') === 'feb'
                && ($searchedFeb['assigned_team'] ?? '') === 'finance'
                && in_array((string) ($searchedFeb['status'] ?? ''), $tabStatuses, true)
                && ($includeLegacyFeb || ($searchedFeb['meta_legacy_source'] ?? '') !== 'feb_legacy')
                && FebValidationPolicy::canValidateService($servicePerm, FebValidationPolicy::requestServiceId($searchedFeb))
            ) {
                $rows[] = $searchedFeb;
            }
        }
    }
    if ($tab === 'unplanned_analysis') {
        $rows = array_values(array_filter($rows, static function(array $row):bool{$payload=(array)($row['payload']??[]);return (string)($payload['budget_planned']??'')==='no'||!empty($payload['proposed_debourse']);}));
    } elseif($tab === 'mine_pending') {
        $rows = array_values(array_filter($rows, static function(array $row):bool{$payload=(array)($row['payload']??[]);return (string)($payload['budget_planned']??'')!=='no'&&empty($payload['proposed_debourse']);}));
    }
    $today=date('Y-m-d');
    if ($tab === 'deferred') {
        $rows=array_values(array_filter($rows,static fn(array $row):bool=>(string)(((array)($row['payload']??[]))['validation_deferred_until']??'')>$today));
    } elseif ($tab === 'future') {
        $rows=array_values(array_filter($rows,static function(array $row)use($today):bool{$payload=(array)($row['payload']??[]);$deferred=(string)($payload['validation_deferred_until']??'');$position=febFinanceEffectivePosition($payload);return($deferred===''||$deferred<=$today)&&$position!==''&&$position>$today;}));
    } elseif (in_array($tab,['mine_pending','unplanned_analysis'],true) && $searchDateFrom === '' && $searchDateTo === '') {
        $rows=array_values(array_filter($rows,static function(array $row)use($today):bool{$payload=(array)($row['payload']??[]);$deferred=(string)($payload['validation_deferred_until']??'');$position=febFinanceEffectivePosition($payload);return($deferred===''||$deferred<=$today)&&($position===''||$position<=$today);}));
    }
} catch (Throwable $e) {
    $rows = [];
}

$febListIds = array_values(array_unique(array_filter(array_map(static fn(array $row): int => (int)($row['id']??0), $rows))));
$febChallengeSummary = [];
$febFinancialSearch = [];
if ($febListIds) {
    $placeholders = implode(',', array_fill(0, count($febListIds), '?'));
    try {
        $stmt=$pdo->prepare("SELECT feb_request_id,
            SUM(status IN ('submitted','retained','dismissed') AND proposed_total_amount>0) submitted_count,
            SUM(status='retained') retained_count
            FROM feb_price_challenges WHERE feb_request_id IN ($placeholders) GROUP BY feb_request_id");
        $stmt->execute($febListIds);
        foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $summary)$febChallengeSummary[(int)$summary['feb_request_id']]=[
            'submitted'=>(int)$summary['submitted_count'],'retained'=>(int)$summary['retained_count']
        ];
    } catch(Throwable $e) {}
    try {
        $stmt=$pdo->prepare("SELECT f.feb_request_id,
            CONCAT_WS(' ',c.code,c.titre) chantier,
            CONCAT_WS(' ',db.id,ld.designation) debourse,
            CONCAT_WS(' ',ldb.id,ldb.designation,ldb.categorie) ligne_debourse
            FROM feb_disbursement_links f
            LEFT JOIN chantier_banamur c ON c.id=f.chantier_id
            LEFT JOIN debourse_banamur db ON db.id=f.debourse_id
            LEFT JOIN ligne_devis_banamur ld ON ld.id=db.ligne_devis_id
            LEFT JOIN ligne_debourse_banamur ldb ON ldb.id=f.ligne_debourse_id
            WHERE f.feb_request_id IN ($placeholders)");
        $stmt->execute($febListIds);
        foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $context)$febFinancialSearch[(int)$context['feb_request_id']]=$context;
    } catch(Throwable $e) {}
}
foreach($rows as &$febListRow){
    $id=(int)($febListRow['id']??0);$payload=(array)($febListRow['payload']??[]);$proposal=(array)($payload['proposed_debourse']??[]);
    $proposalLines=array_values(array_filter((array)($proposal['lines']??[]),'is_array'));
    $linkedContext=(array)($febFinancialSearch[$id]??[]);
    $businessContext=febApprovalBusinessContext($pdo,$payload,(array)($payload['provisional_decaissement']??[]),$proposal);
    $febListRow['challenge_summary']=$febChallengeSummary[$id]??['submitted'=>0,'retained'=>0];
    $febListRow['financial_search']=[
        'chantier'=>febChantierSearchLabel(trim((string)($linkedContext['chantier']??'').' '.(string)($businessContext['chantier']??'').' '.(string)($payload['chantier_label']??''))),
        'debourse'=>trim((string)($linkedContext['debourse']??'').' '.(string)($businessContext['debourse']??'').' '.(string)($proposal['title']??'')),
        'ligne_debourse'=>trim((string)($linkedContext['ligne_debourse']??'').' '.(string)($businessContext['ligne_debourse']??'').' '.implode(' ',array_map(static fn(array $line):string=>(string)($line['designation']??''),$proposalLines))),
    ];
    $draftPlanning=is_array($payload['provisional_decaissement']??null)?(array)$payload['provisional_decaissement']:[];
    $febListRow['planning_date']=trim((string)($payload['validation_deferred_until']??$draftPlanning['date_decaissement_minimum']??$payload['planned_start_date']??$payload['date_livraison']??substr((string)($febListRow['submitted_at']??''),0,10)));
}
unset($febListRow);

$filterOptions=['demandeur'=>[],'affectation'=>[],'chantier'=>[],'debourse'=>[],'ligne_debourse'=>[]];
foreach($rows as $optionRow){$optionPayload=(array)($optionRow['payload']??[]);$optionDisplay=(array)($optionRow['feb_display']??EmployeeLegalRequestManager::extractFebDisplay($optionRow));$optionFinancial=(array)($optionRow['financial_search']??[]);foreach(['demandeur'=>trim((string)($optionDisplay['demandeur']??$optionRow['requester_name']??'')),'affectation'=>trim((string)(($optionPayload['departement_label']??'').' '.($optionPayload['service_employe']??''))),'chantier'=>trim((string)($optionFinancial['chantier']??$optionDisplay['chantier_label']??'')),'debourse'=>trim((string)($optionFinancial['debourse']??'')),'ligne_debourse'=>trim((string)($optionFinancial['ligne_debourse']??''))] as $key=>$value)if($value!=='')$filterOptions[$key][$value]=$value;}
foreach($filterOptions as &$optionValues){natcasesort($optionValues);$optionValues=array_values($optionValues);}unset($optionValues);

$rows = array_values(array_filter($rows, static function (array $row) use ($searchQuery, $searchRequesters, $searchAffectations, $searchChantiers, $searchDebourses, $searchLignesDebourse, $filterModes, $searchChallengeStatus, $searchDateFrom, $searchDateTo): bool {
    $payload = (array) ($row['payload'] ?? []);
    $display = (array) ($row['feb_display'] ?? EmployeeLegalRequestManager::extractFebDisplay($row));
    $requester = trim((string) ($display['demandeur'] ?? ($row['requester_name'] ?? '')));
    $affectation = trim((string) (($payload['departement_label'] ?? '') . ' ' . ($payload['service_employe'] ?? '')));
    $financial=(array)($row['financial_search']??[]);
    $challengeCount=(int)(($row['challenge_summary']??[])['submitted']??0);

    if (!febTextMatch(febRowSearchHaystack($row), $searchQuery)) {
        return false;
    }
    $matches=static function(string $text,array $values,string $mode):bool{if(!$values)return true;$found=false;foreach($values as $value)if(febTextMatch($text,(string)$value)){$found=true;break;}return $mode==='exclude'?!$found:$found;};
    if(!$matches($requester,$searchRequesters,$filterModes['demandeur']))return false;
    if(!$matches($affectation,$searchAffectations,$filterModes['affectation']))return false;
    if(!$matches(trim((string)($financial['chantier']??'').' '.(string)($display['chantier_label']??'')),$searchChantiers,$filterModes['chantier']))return false;
    if(!$matches((string)($financial['debourse']??''),$searchDebourses,$filterModes['debourse']))return false;
    if(!$matches((string)($financial['ligne_debourse']??''),$searchLignesDebourse,$filterModes['ligne_debourse']))return false;
    if ($searchChallengeStatus==='present' && $challengeCount<=0) return false;
    if ($searchChallengeStatus==='absent' && $challengeCount>0) return false;
    $effectiveDate=trim((string)($row['planning_date']??''));
    if ($searchDateFrom!=='' && $effectiveDate<$searchDateFrom) return false;
    if ($searchDateTo!=='' && $effectiveDate>$searchDateTo) return false;
    return true;
}));
usort($rows,static fn(array $a,array $b):int=>[(string)($a['planning_date']??'9999-12-31'),(int)($a['id']??0)]<=>[(string)($b['planning_date']??'9999-12-31'),(int)($b['id']??0)]);

$requesterProfiles = febRequesterProfiles($pdo, $rows);
$totalFilteredAmount = febRowsTotalAmount($rows);
$selectedIdsRaw = trim((string) ($_GET['selected_ids'] ?? ''));
$visibleRowIds = implode(',', array_values(array_filter(array_map(
    static fn(array $row): int => (int) ($row['id'] ?? 0),
    $rows
), static fn(int $id): bool => $id > 0)));

$viewId = isset($_GET['id']) && is_numeric($_GET['id']) ? (int) $_GET['id'] : 0;
$view = $viewId > 0 ? $manager->getRequestById($viewId) : null;
$events = $view ? $manager->listEventsForRequest($viewId, 80) : [];

// Ne montrer que les demandes finance
if ($view && (($view['assigned_team'] ?? '') !== 'finance' || ($view['request_type'] ?? '') !== 'feb')) {
    $view = null;
    $events = [];
}

$viewCanValidate = false;
$viewServiceId = 0;
if ($view) {
    $viewServiceId = FebValidationPolicy::requestServiceId($view);
    $viewCanValidate = FebValidationPolicy::canValidateService($servicePerm, $viewServiceId);
    $viewIsLegacyFeb = (string) ($view['meta_legacy_source'] ?? '') === 'feb_legacy';
    if (!$viewCanValidate || ($viewIsLegacyFeb && !$includeLegacyFeb)) {
        $view = null;
        $events = [];
    }
}
if (!$view) {
    $detailMode = false;
}

$viewRhPoint = null;
if ($view && !empty($view['payload']['rh_financial_point_id'])) {
    try {$viewRhPoint=(new RhFinancialPointService($pdo))->assertSignedFeb($view);}
    catch(Throwable $e) {http_response_code(403);exit('Le point RH signé ne correspond plus à cette FEB.');}
}
if ($detailMode && $view && !$viewRhPoint) {
    $detailParams = ['id' => (int) ($view['id'] ?? 0), 'tab' => $tab];
    if ($includeLegacyFeb) {
        $detailParams['archives'] = 1;
    }
    header('Location: validation_feb_detail.php?' . http_build_query($detailParams));
    exit;
}

\AuditTrail::logPageConsultation([
    'module' => 'finance',
    'action' => 'validation_demandes_finances_view',
    'entity_type' => $view ? 'feb_request' : 'feb_request_list',
    'entity_id' => $view ? (string) ($view['id'] ?? 0) : null,
    'entity_label' => $view ? (string) (($view['title'] ?? '') ?: ('request_' . (int) ($view['id'] ?? 0))) : 'validation_demandes_finances',
    'details' => [
        'tab' => $tab,
        'row_count' => count($rows),
        'view_id' => $view ? (int) ($view['id'] ?? 0) : null,
        'event_count' => is_array($events) ? count($events) : 0,
        'service_filter_all' => !empty($servicePerm['all']),
    ],
], $pdo);

$exportRecapUrl = 'request/export_feb_recap_excel.php?' . http_build_query(array_filter([
    'tab' => $tab,
    // Exporter la liste réellement affichée, après tous les filtres de la page.
    'ids' => $visibleRowIds !== '' ? $visibleRowIds : '0',
    'archives' => $includeLegacyFeb ? 1 : null,
], static fn($v) => $v !== null && $v !== ''));
$exportRecapPdfUrl = 'request/export_feb_recap_pdf.php?' . http_build_query(array_filter([
    'tab' => $tab,
    'ids' => $visibleRowIds !== '' ? $visibleRowIds : '0',
    'archives' => $includeLegacyFeb ? 1 : null,
], static fn($v) => $v !== null && $v !== ''));

$pageTitle = 'Demandes à valider';
$activeMenu = 'feb_finance';
$layoutRootBase = '';

require_once __DIR__ . '/decaissement/includes/header.php';
require_once __DIR__ . '/decaissement/includes/navbar.php';
?>
<?php if($view && ($supplierApprovalError=SupplierApprovalGuard::payloadError($pdo,(array)($view['payload']??[])))): ?><div role="alert" class="mx-4 my-4 rounded-xl border border-red-300 bg-red-50 p-4 text-red-900"><strong>Fiche fournisseur à régulariser avant approbation</strong><p><?= htmlspecialchars($supplierApprovalError,ENT_QUOTES,'UTF-8') ?></p><p>La comptabilité doit compléter et faire valider le dossier fournisseur. Le contrôle sera renouvelé à la décision.</p></div><?php endif; ?>

<style>
    .feb-panel {
        border: 1px solid rgba(226, 232, 240, 0.9);
        border-radius: 24px;
        background: linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(248, 250, 252, 0.98));
        box-shadow: 0 20px 45px rgba(15, 23, 42, 0.06);
    }

    .feb-kicker {
        font-size: 11px;
        font-weight: 800;
        letter-spacing: 0.24em;
        text-transform: uppercase;
        color: #4f46e5;
    }

    .feb-stat-tile {
        border: 1px solid rgba(226, 232, 240, 0.9);
        border-radius: 18px;
        background: rgba(255, 255, 255, 0.82);
        padding: 14px 16px;
    }

    .feb-doc-tile {
        border: 1px solid rgba(191, 219, 254, 0.95);
        border-radius: 18px;
        background: linear-gradient(180deg, rgba(248, 250, 252, 0.96), rgba(255, 255, 255, 0.98));
        padding: 14px 16px;
    }

    .feb-doc-tile--invoice {
        border-color: rgba(125, 211, 252, 0.95);
        background: linear-gradient(180deg, rgba(240, 249, 255, 0.96), rgba(255, 255, 255, 0.99));
    }

    .feb-meta-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 12px;
    }

    .feb-meta-item {
        border: 1px solid rgba(226, 232, 240, 0.95);
        border-radius: 18px;
        background: #fff;
        padding: 14px 16px;
    }

    .feb-meta-label {
        display: block;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.14em;
        text-transform: uppercase;
        color: #64748b;
        margin-bottom: 6px;
    }

    .feb-list-card {
        position: relative;
        overflow: hidden;
        border: 1px solid rgba(224, 231, 255, 0.95);
        border-radius: 22px;
        background:
            radial-gradient(circle at top right, rgba(99, 102, 241, 0.12), transparent 34%),
            linear-gradient(180deg, rgba(255, 255, 255, 0.99), rgba(248, 250, 252, 0.98));
        box-shadow: 0 18px 38px rgba(15, 23, 42, 0.06);
    }

    .feb-list-card::before {
        content: '';
        position: absolute;
        inset: 0 0 auto 0;
        height: 4px;
        background: linear-gradient(90deg, #4f46e5 0%, #0f172a 55%, #10b981 100%);
    }

    .feb-card-topline {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding-bottom: 12px;
        margin-bottom: 14px;
        border-bottom: 1px solid rgba(226, 232, 240, 0.9);
    }

    .feb-card-number {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        min-width: 0;
        font-size: 12px;
        font-weight: 800;
        letter-spacing: 0.16em;
        text-transform: uppercase;
        color: #4338ca;
    }

    .feb-card-badges {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 8px;
        flex-wrap: wrap;
    }

    .feb-card-amountline {
        display: flex;
        align-items: baseline;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 14px;
        padding: 10px 14px 12px;
        border-radius: 18px;
        border: 1px solid rgba(16, 185, 129, 0.12);
        background: linear-gradient(135deg, rgba(236, 253, 245, 0.95), rgba(255, 255, 255, 0.96));
    }

    .feb-card-amountline-label {
        font-size: 11px;
        font-weight: 800;
        letter-spacing: 0.14em;
        text-transform: uppercase;
        color: #047857;
    }

    .feb-amount {
        min-width: 0;
        text-align: right;
        font-size: 16px;
        font-weight: 800;
        color: #065f46;
        overflow-wrap: anywhere;
    }

    .feb-card-header {
        display: grid;
        grid-template-columns: minmax(0, 1fr);
        gap: 12px;
        align-items: center;
    }

    .feb-avatar {
        width: 48px;
        height: 48px;
        border-radius: 16px;
        overflow: hidden;
        border: 3px solid rgba(255, 255, 255, 0.85);
        box-shadow: 0 12px 28px rgba(79, 70, 229, 0.18);
        background: linear-gradient(135deg, #eef2ff 0%, #e0f2fe 100%);
        flex-shrink: 0;
    }

    .feb-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }

    .feb-avatar-fallback {
        display: flex;
        width: 100%;
        height: 100%;
        align-items: center;
        justify-content: center;
        color: #4f46e5;
        font-size: 1.05rem;
        font-weight: 800;
    }

    .feb-card-meta {
        display: grid;
        gap: 8px;
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .feb-card-meta-item {
        border: 1px solid rgba(226, 232, 240, 0.9);
        border-radius: 14px;
        background: rgba(255, 255, 255, 0.82);
        padding: 10px 12px;
        min-width: 0;
    }

    .feb-card-meta-item--wide {
        grid-column: 1 / -1;
    }

    .feb-card-meta-label {
        display: block;
        font-size: 10px;
        font-weight: 700;
        letter-spacing: 0.14em;
        text-transform: uppercase;
        color: #64748b;
        margin-bottom: 4px;
    }

    .feb-card-meta-value {
        display: block;
        font-size: 12px;
        line-height: 1.5;
        font-weight: 600;
        color: #0f172a;
        overflow-wrap: anywhere;
    }

    .feb-card-footer {
        display: flex;
        flex-direction: column;
        align-items: stretch;
        gap: 12px;
        border-top: 1px solid rgba(226, 232, 240, 0.95);
        padding-top: 14px;
    }

    .feb-card-dates {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
        min-width: 0;
    }

    .feb-card-date {
        display: flex;
        flex-direction: column;
        gap: 4px;
        min-width: 0;
        border: 1px solid rgba(226, 232, 240, 0.9);
        border-radius: 12px;
        background: rgba(248, 250, 252, 0.85);
        padding: 10px 12px;
        font-size: 12px;
        line-height: 1.4;
        overflow-wrap: anywhere;
    }

    .feb-card-date-label { color: #64748b; }
    .feb-card-date-value { font-weight: 700; color: #0f172a; }

    .feb-action-link {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        border-radius: 12px;
        padding: 8px 12px;
        min-height: 36px;
        font-size: 12px;
        font-weight: 700;
        white-space: nowrap;
    }

    .feb-doc-list {
        display: grid;
        gap: 6px;
    }

    .feb-doc-row {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        align-items: center;
        gap: 8px;
    }

    .feb-doc-main {
        min-width: 0;
    }

    .feb-doc-type {
        display: block;
        font-size: 11px;
        font-weight: 800;
        color: #0f172a;
    }

    .feb-doc-name {
        display: block;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        color: #64748b;
        font-size: 11px;
        line-height: 1.35;
    }

    .feb-doc-open {
        border-radius: 999px;
        border: 1px solid rgba(99, 102, 241, 0.24);
        background: #fff;
        padding: 5px 8px;
        color: #4338ca;
        font-size: 11px;
        font-weight: 800;
    }

    .feb-doc-open:hover {
        background: #eef2ff;
    }

    @media (max-width: 640px) {
        .feb-card-topline,
        .feb-card-footer,
        .feb-card-amountline {
            align-items: stretch;
            flex-direction: column;
        }

        .feb-card-meta {
            grid-template-columns: 1fr;
        }

        .feb-card-dates { grid-template-columns: 1fr; }

        .feb-amount {
            text-align: left;
        }
    }

    .feb-content-card {
        border: 1px solid rgba(226, 232, 240, 0.95);
        border-radius: 20px;
        background: linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(248, 250, 252, 0.96));
        padding: 16px;
    }

    .feb-content-section {
        border-top: 1px solid rgba(226, 232, 240, 0.9);
        padding-top: 14px;
        margin-top: 14px;
    }

    .feb-content-title {
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.18em;
        text-transform: uppercase;
        color: #64748b;
    }

    .feb-priority-card {
        position: relative;
        overflow: hidden;
        border: 1px solid rgba(226, 232, 240, 0.95);
        border-radius: 22px;
        background: linear-gradient(180deg, rgba(255, 255, 255, 0.99), rgba(248, 250, 252, 0.97));
        box-shadow: 0 16px 34px rgba(15, 23, 42, 0.06);
    }

    .feb-priority-card::before {
        content: '';
        position: absolute;
        inset: 0 0 auto 0;
        height: 4px;
        background: linear-gradient(90deg, #4f46e5 0%, #06b6d4 55%, #10b981 100%);
    }

    .feb-priority-chip {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        border-radius: 9999px;
        padding: 6px 10px;
        font-size: 11px;
        font-weight: 700;
        line-height: 1;
    }

    .feb-decision-panel {
        border: 1px solid rgba(226, 232, 240, 0.95);
        border-radius: 24px;
        background: linear-gradient(180deg, rgba(255, 255, 255, 0.99), rgba(248, 250, 252, 0.97));
        box-shadow: 0 18px 42px rgba(15, 23, 42, 0.07);
    }

    .feb-decision-note {
        border: 1px solid rgba(191, 219, 254, 0.95);
        border-radius: 18px;
        background: linear-gradient(180deg, rgba(239, 246, 255, 0.92), rgba(255, 255, 255, 0.98));
    }

    .feb-action-btn {
        min-height: 48px;
        justify-content: center;
        border-radius: 18px;
        padding: 12px 16px;
        text-align: center;
    }
</style>

<div class="min-h-screen bg-[radial-gradient(circle_at_top_left,_rgba(240,249,255,0.95),_transparent_42%),radial-gradient(circle_at_top_right,_rgba(224,231,255,0.85),_transparent_35%),linear-gradient(180deg,_#f8fafc_0%,_#eef2ff_100%)] pb-12">
    <div class="mx-auto max-w-[1600px] px-4 py-6 2xl:px-8">
        <?php if($viewRhPoint): $_SESSION['rh_financial_csrf']??=bin2hex(random_bytes(32)); ?>
        <section class="mb-6 rounded-2xl border border-amber-200 bg-amber-50 p-6"><h2 class="text-xl font-bold">Point financier RH signé par le DG</h2><p class="mt-2">Les montants, le bénéficiaire RH et les détails individuels sont fixés par le récapitulatif signé. Toute modification nécessite une réouverture par les RH.</p><a class="mt-3 inline-block font-bold underline" target="_blank" href="request/download_rh_financial_point.php?point_id=<?=(int)$view['payload']['rh_financial_point_id']?>&amp;revision=<?=(int)$view['payload']['rh_financial_revision']?>">Consulter le PDF signé</a>
        <p class="mt-3 font-semibold">Service bénéficiaire : Ressources humaines · Circuit personnel, sans fournisseur.</p>
        <p class="mt-2">Bénéficiaire de la fiche : <?=htmlspecialchars((string)$viewRhPoint['stopped_name'])?>. Les collaborateurs bénéficiaires figurent dans le point signé.</p>
        <a class="mt-3 inline-block underline" href="validation_demandes_finances.php?tab=<?=urlencode($tab)?>">Retour aux FEB</a>
        <?php if($viewCanValidate && in_array($view['status'],['submitted','details_requested'],true)): ?>
        <form method="post" class="mt-4 flex flex-wrap items-end gap-3"><input type="hidden" name="_csrf" value="<?=htmlspecialchars($_SESSION['rh_financial_csrf'])?>"><input type="hidden" name="feb_workflow_csrf" value="<?=htmlspecialchars($_SESSION['feb_workflow_csrf'])?>"><input type="hidden" name="action" value="approve"><input type="hidden" name="request_id" value="<?=(int)$view['id']?>"><input type="hidden" name="tab" value="<?=htmlspecialchars($tab)?>"><label>Source des fonds<select name="treasury_funding_source" class="block rounded-lg border p-3" required><option value="real_entries">Entrées réelles disponibles</option><option value="forecast_invoice">Facture prévisionnelle</option></select></label><label>Facture prévisionnelle<select name="treasury_funding_invoice_id" class="block rounded-lg border p-3"><option value="0">Si financement par facture</option><?php foreach($treasuryFundingInvoices as $invoice):?><option value="<?=(int)$invoice['invoice_id']?>"><?=htmlspecialchars((string)($invoice['reference'] ?? ('Facture #'.$invoice['invoice_id'])))?></option><?php endforeach?></select></label><button class="rounded-lg bg-slate-950 p-3 font-bold text-white">Approuver la FEB RH et créer la fiche</button></form>
        <?php endif ?></section><?php endif ?>
        <?php if (!$detailMode): ?>
            <div id="list" class="<?= $view ? 'mt-6 ' : 'mt-0 ' ?>rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.28em] text-indigo-600">Finances</p>
                        <h1 class="mt-2 text-2xl font-semibold text-slate-950">Demandes à valider</h1>
                        <p class="mt-1 text-sm text-slate-600">Consultez, ajustez et validez les fiches d'expression de besoin de vos services autorisés.</p>
                    </div>
                    <div class="flex flex-wrap items-center justify-end gap-2">
                        <a href="finance/journal_actions_feb.php" class="rounded-2xl border border-indigo-200 bg-indigo-50 px-4 py-2 text-sm font-semibold text-indigo-800 hover:bg-indigo-100">Journal financier du jour</a>
                        <a href="<?= htmlspecialchars($exportRecapUrl) ?>" target="_blank" rel="noopener" class="rounded-2xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">Exporter le récapitulatif FEB</a>
                        <?php if($canOpenTreasury):?><a href="facturation/tresorerie_chantiers.php" target="_blank" rel="noopener" class="inline-flex items-center gap-2 rounded-2xl border border-emerald-300 bg-emerald-50 px-4 py-2 text-sm font-bold text-emerald-900 hover:bg-emerald-100"><i class="fas fa-vault"></i>Trésorerie DG</a><?php endif;?>
                    </div>
                </div>

                <?php if ($flash): ?>
                    <?php
                    $flashClasses = [
                        'success' => 'border-emerald-200 bg-emerald-50 text-emerald-900',
                        'error' => 'border-rose-200 bg-rose-50 text-rose-900',
                        'info' => 'border-slate-200 bg-slate-50 text-slate-800',
                    ];
                    ?>
                    <div class="mt-5 rounded-2xl border px-4 py-4 text-sm <?= $flashClasses[$flashType] ?? $flashClasses['info'] ?>">
                        <?= htmlspecialchars($flash) ?>
                    </div>
                <?php endif; ?>

                <?php if($canSeeTreasuryControl&&$treasuryControlReady):?>
                    <div class="mt-5 grid gap-px overflow-hidden rounded-lg border border-slate-200 bg-slate-200 sm:grid-cols-2 xl:grid-cols-4" aria-label="Indicateurs de trésorerie utiles à la validation">
                        <?php foreach([['Disponible réel du jour',$treasuryAvailableToday,'text-amber-700'],['Entrées réelles',$treasuryRealEntries,'text-emerald-700'],['Entrées prévisionnelles',$treasuryForecastEntries,'text-sky-700'],['Fiches planifiées après aujourd’hui',$treasuryForecastOutputs,'text-rose-700']] as [$label,$amount,$color]):?>
                            <div class="bg-white px-3 py-2"><span class="block text-[10px] font-bold uppercase text-slate-500"><?=htmlspecialchars($label)?></span><strong class="mt-0.5 block text-sm <?=$color?>"><?=number_format((float)$amount,0,',',' ')?> FCFA</strong></div>
                        <?php endforeach;?>
                    </div>
                <?php endif;?>

                <?php if (empty($servicePerm['configured']) && empty($servicePerm['raw'])): ?>
                    <div class="mt-5 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-4 text-sm text-amber-900">
                        <div class="font-semibold">Validation FEB par service</div>
                        <div class="mt-1">Aucune liste de services n'est configuree pour votre compte. Vous ne pouvez voir ni valider aucune FEB tant que <span class="font-semibold">user_devis_banamur.feb_validation_services</span> n'est pas renseigne (ex: <span class="font-semibold">1,2,3</span> ou <span class="font-semibold">*</span>).</div>
                    </div>
                <?php endif; ?>

                <nav class="mt-6 border-y border-slate-200 bg-slate-100 px-3 py-3" aria-label="Vues de validation FEB">
                    <?php
                    $tabDef = [
                        'mine_pending' => ['label' => 'FEB à valider', 'count' => $plannedPendingCount+$unclassifiedBudgetCount],
                        'unplanned_analysis' => ['label' => 'Propositions à négocier', 'count' => $unplannedPendingCount],
                        'future' => ['label' => 'Prise en charge à venir', 'count' => $futurePendingCount],
                        'deferred' => ['label' => 'FEB reportées', 'count' => $deferredCount],
                        'details_requested' => ['label' => 'Compléments demandés', 'count' => (int) ($countsAll['details_requested'] ?? 0)],
                        'approved' => ['label' => 'Validées', 'count' => (int) ($countsAll['approved'] ?? 0)],
                        'rejected' => ['label' => 'Refusées', 'count' => (int) ($countsAll['rejected'] ?? 0)],
                        'cancelled' => ['label' => 'Annulées', 'count' => (int) ($countsAll['cancelled'] ?? 0)],
                        'all' => ['label' => 'Historique', 'count' => (int) (($countsAll['submitted'] ?? 0) + ($countsAll['details_requested'] ?? 0) + ($countsAll['approved'] ?? 0) + ($countsAll['rejected'] ?? 0) + ($countsAll['cancelled'] ?? 0))],
                    ];
                    $tabGroups = [
                        ['title'=>'À valider','hint'=>'Vérifier les justificatifs et décider maintenant.','icon'=>'fa-clipboard-check','count'=>$plannedPendingCount+$unclassifiedBudgetCount,'tabs'=>['mine_pending'],'active'=>'border-indigo-600 bg-indigo-600 text-white','idle'=>'border-indigo-200 bg-white text-indigo-900 hover:bg-indigo-50'],
                        ['title'=>'Planification','hint'=>'Suivre les échéances, reports et compléments.','icon'=>'fa-calendar-alt','count'=>$futurePendingCount+$deferredCount+(int)($countsAll['details_requested']??0),'tabs'=>['future','deferred','details_requested'],'active'=>'border-amber-500 bg-amber-500 text-slate-950','idle'=>'border-amber-200 bg-white text-amber-900 hover:bg-amber-50'],
                        ['title'=>'Décisions','hint'=>'Retrouver les validations, refus et annulations.','icon'=>'fa-history','count'=>(int)(($countsAll['approved']??0)+($countsAll['rejected']??0)+($countsAll['cancelled']??0)),'tabs'=>['approved','rejected','cancelled','all'],'active'=>'border-emerald-600 bg-emerald-600 text-white','idle'=>'border-emerald-200 bg-white text-emerald-900 hover:bg-emerald-50'],
                    ];
                    ?>
                    <div class="mb-2 flex items-center gap-2 text-xs font-black uppercase text-slate-500"><i class="fas fa-layer-group text-indigo-600"></i>Choisir une vue</div>
                    <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                        <?php $activeGroup=null; foreach($tabGroups as $group): $groupIsActive=in_array($tab,$group['tabs'],true); if($groupIsActive)$activeGroup=$group; $target=$group['tabs'][0]; $groupUrl='validation_demandes_finances.php?'.http_build_query(array_merge($baseListParams,['tab'=>$target])); ?>
                            <a href="<?=htmlspecialchars($groupUrl)?>" class="flex min-h-16 items-center gap-3 rounded-lg border px-3 py-3 transition <?=$groupIsActive?$group['active']:$group['idle']?>">
                                <i class="fas <?=htmlspecialchars($group['icon'])?> w-5 text-center text-base"></i>
                                <span class="min-w-0 flex-1"><span class="block text-sm font-black"><?=htmlspecialchars($group['title'])?></span><span class="mt-0.5 block truncate text-[11px] opacity-80"><?=htmlspecialchars($group['hint'])?></span></span>
                                <span class="inline-flex h-7 min-w-7 shrink-0 items-center justify-center rounded-full border border-current/20 bg-white/20 px-2 text-xs font-black"><?=(int)$group['count']?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                    <?php if($activeGroup&&count($activeGroup['tabs'])>1): ?>
                        <div class="mt-3 flex flex-wrap items-center gap-2 border-t border-slate-200 pt-3">
                            <span class="mr-1 text-xs font-bold text-slate-500">Afficher :</span>
                            <?php foreach($activeGroup['tabs'] as $k):$info=$tabDef[$k];$isActive=$tab===$k;$tabUrl='validation_demandes_finances.php?'.http_build_query(array_merge($baseListParams,['tab'=>$k])); ?>
                                <a href="<?=htmlspecialchars($tabUrl)?>" class="inline-flex items-center gap-2 rounded-lg border px-3 py-2 text-xs font-bold <?=$isActive?'border-slate-800 bg-slate-800 text-white':'border-slate-200 bg-white text-slate-700 hover:bg-slate-50'?>"><?=htmlspecialchars($info['label'])?><span class="opacity-70"><?=(int)$info['count']?></span></a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </nav>

                <form method="get" class="mt-5 grid gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4 md:grid-cols-2 xl:grid-cols-4">
                    <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
                    <?php if ($includeLegacyFeb): ?><input type="hidden" name="archives" value="1"><?php endif; ?>
                    <div class="flex items-center gap-2 border-b border-slate-200 pb-3 text-sm font-black text-slate-800 md:col-span-2 xl:col-span-4"><i class="fas fa-filter text-slate-500"></i>Recherche et filtres</div>
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Recherche globale</span>
                        <input name="q" value="<?= htmlspecialchars($searchQuery) ?>" placeholder="Nom, BCI, description, service..." class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm">
                    </label>
                    <?php foreach(['demandeur'=>'Demandeur','affectation'=>'Affectation','chantier'=>'Chantier','debourse'=>'Déboursé','ligne_debourse'=>'Ligne de déboursé'] as $filterKey=>$filterLabel):$selectedMap=['demandeur'=>$searchRequesters,'affectation'=>$searchAffectations,'chantier'=>$searchChantiers,'debourse'=>$searchDebourses,'ligne_debourse'=>$searchLignesDebourse]; ?>
                    <label class="block"><span class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500"><?=htmlspecialchars($filterLabel)?></span><div class="mt-1" data-filter-multiselect><select name="<?=$filterKey?>[]" multiple class="hidden"><?php foreach($filterOptions[$filterKey]??[] as $option):?><option value="<?=htmlspecialchars($option)?>" <?=in_array($option,$selectedMap[$filterKey],true)?'selected':''?>><?=htmlspecialchars($option)?></option><?php endforeach;?></select><div class="flex gap-2"><input type="search" data-multi-search placeholder="Rechercher et sélectionner…" class="min-w-0 flex-1 rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm"><select name="<?=$filterKey?>_mode" class="rounded-2xl border border-slate-200 bg-white px-2 py-2 text-xs font-semibold"><option value="include" <?=$filterModes[$filterKey]==='include'?'selected':''?>>Inclure</option><option value="exclude" <?=$filterModes[$filterKey]==='exclude'?'selected':''?>>Exclure</option></select></div><div data-multi-results class="relative hidden"></div><div data-multi-chips class="mt-2 flex flex-wrap gap-1"></div></div></label>
                    <?php endforeach; ?>
                    <label class="block"><span class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Devis contradictoire</span><select name="devis_contradictoire" class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm"><option value="">Tous</option><option value="present" <?=$searchChallengeStatus==='present'?'selected':''?>>Présent</option><option value="absent" <?=$searchChallengeStatus==='absent'?'selected':''?>>Absent</option></select></label>
                    <label class="block"><span class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Période de planning</span><select name="planning_period" class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm"><option value="">Intervalle personnalisé</option><?php foreach(['today'=>'Aujourd’hui','week'=>'Semaine en cours','month'=>'Mois en cours','quarter'=>'Trimestre en cours','semester'=>'Semestre en cours','year'=>'Année en cours'] as $value=>$label):?><option value="<?=$value?>" <?=$searchPeriod===$value?'selected':''?>><?=htmlspecialchars($label)?></option><?php endforeach;?></select></label>
                    <label class="block"><span class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Traitement prévu du</span><input type="date" name="date_from" value="<?=htmlspecialchars($searchDateFrom)?>" class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm"></label>
                    <label class="block"><span class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Traitement prévu au</span><input type="date" name="date_to" value="<?=htmlspecialchars($searchDateTo)?>" class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm"></label>
                    <div class="flex items-end gap-2"><button class="flex-1 rounded-2xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700" type="submit"><i class="fas fa-search mr-2"></i>Rechercher</button><a href="validation_demandes_finances.php?tab=<?=urlencode($tab)?>#list" class="rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700">Effacer</a></div>
                </form>

                <div class="mt-4 grid gap-3 md:grid-cols-4">
                    <div class="rounded-2xl border border-slate-200 bg-white px-4 py-4">
                        <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">FEB affichees</div>
                        <div class="mt-2 text-2xl font-semibold text-slate-950"><?= count($rows) ?></div>
                    </div>
                    <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-4">
                        <div class="text-xs font-semibold uppercase tracking-[0.18em] text-emerald-700">Montant total</div>
                        <div class="mt-2 text-2xl font-semibold text-emerald-950"><?= htmlspecialchars(number_format($totalFilteredAmount, 0, ',', ' ')) ?> FCFA</div>
                    </div>
                    <a href="<?= htmlspecialchars($exportRecapUrl) ?>" target="_blank" rel="noopener" class="rounded-2xl border border-slate-200 bg-white px-4 py-4 hover:bg-slate-50">
                        <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Récapitulatif</div>
                        <div class="mt-2 text-sm font-semibold text-slate-950">Exporter Excel</div>
                    </a>
                    <a href="<?= htmlspecialchars($exportRecapPdfUrl) ?>" target="_blank" rel="noopener" class="rounded-2xl border border-slate-200 bg-white px-4 py-4 hover:bg-slate-50">
                        <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Récapitulatif</div>
                        <div class="mt-2 text-sm font-semibold text-slate-950">Exporter PDF</div>
                    </a>
                </div>

                <form method="get" class="mt-4 inline-flex">
                    <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
                    <label class="inline-flex items-center gap-2 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-2 text-sm font-semibold text-amber-900">
                        <input type="checkbox" name="archives" value="1" class="h-4 w-4 rounded border-amber-300 text-amber-600" <?= $includeLegacyFeb ? 'checked' : '' ?> onchange="this.form.submit()">
                        Afficher FEB archives importées
                    </label>
                </form>

                <div id="febSelectionForm" class="mt-6 space-y-4">
                    <div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white px-4 py-3">
                        <label class="inline-flex items-center gap-2 text-sm font-semibold text-slate-700">
                            <input type="checkbox" id="febSelectAll" class="h-4 w-4 rounded border-slate-300 text-indigo-600">
                            Tout sélectionner
                        </label>
                        <div id="febSelectionSummary" class="hidden rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-2 text-sm text-indigo-950" aria-live="polite">
                            <span id="febSelectedCount" class="font-semibold">0 fiche sélectionnée</span>
                            <span class="mx-2 text-indigo-300">•</span>
                            Total : <strong id="febSelectedAmount">0 FCFA</strong>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <?php if(in_array($tab,['mine_pending','unplanned_analysis'],true)): ?>
                            <input type="date" id="febBulkDeferredUntil" min="<?=date('Y-m-d',strtotime('+1 day'))?>" class="rounded-2xl border border-amber-200 bg-white px-3 py-2 text-sm" aria-label="Date de report de la sélection">
                            <button type="button" id="febBulkDefer" class="rounded-2xl border border-amber-300 bg-amber-50 px-4 py-2 text-sm font-semibold text-amber-900 hover:bg-amber-100"><i class="far fa-calendar-plus mr-2"></i>Reporter la sélection</button>
                            <?php endif; ?>
                            <?php if($tab==='deferred'): ?>
                            <button type="button" id="febBulkBringToday" class="rounded-2xl border border-emerald-300 bg-emerald-50 px-4 py-2 text-sm font-semibold text-emerald-900 hover:bg-emerald-100"><i class="fas fa-calendar-day mr-2"></i>Ramener aujourd’hui</button>
                            <input type="date" id="febBulkDeferredUntil" min="<?=date('Y-m-d',strtotime('+1 day'))?>" class="rounded-2xl border border-amber-200 bg-white px-3 py-2 text-sm" aria-label="Nouvelle date de report">
                            <button type="button" id="febBulkDefer" class="rounded-2xl border border-amber-300 bg-amber-50 px-4 py-2 text-sm font-semibold text-amber-900 hover:bg-amber-100"><i class="far fa-calendar-plus mr-2"></i>Envoyer à cette date</button>
                            <button type="button" id="febBulkReject" class="rounded-2xl border border-rose-300 bg-rose-50 px-4 py-2 text-sm font-semibold text-rose-800 hover:bg-rose-100"><i class="fas fa-ban mr-2"></i>Tout refuser</button>
                            <?php endif; ?>
                            <button type="button" data-export-selected="pdf" class="rounded-2xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">Exporter la sélection PDF</button>
                            <button type="button" data-export-selected="excel" class="rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-900 hover:bg-slate-50">Exporter la sélection Excel</button>
                        </div>
                    </div>

                    <?php if (!$rows): ?>
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-8 text-center text-sm text-slate-600">Aucune FEB dans votre périmètre.</div>
                    <?php else: ?>
                        <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                            <?php foreach ($rows as $row): ?>
                                <?php $badge = statusBadge((string) ($row['status'] ?? '')); ?>
                                <?php $payloadRow = (array) ($row['payload'] ?? []); ?>
                                <?php $febDisplay = (array) ($row['feb_display'] ?? EmployeeLegalRequestManager::extractFebDisplay($row)); ?>
                                <?php $serviceLabel = (string) ($payloadRow['departement_label'] ?? ''); ?>
                                <?php $rowServiceId = FebValidationPolicy::requestServiceId($row); ?>
                                <?php $rowCanValidate = FebValidationPolicy::canValidateService($servicePerm, $rowServiceId); ?>
                                <?php $rowIsPending = ((string) ($row['status'] ?? '')) === 'submitted'; ?>
                                <?php $rowActionIsTreat = $rowIsPending && $rowCanValidate; ?>
                                <?php $rowId = (int) ($row['id'] ?? 0); ?>
                                <?php $rowChallengeSummary=(array)($row['challenge_summary']??[]);$rowChallengeCount=(int)($rowChallengeSummary['submitted']??0);$rowRetainedChallengeCount=(int)($rowChallengeSummary['retained']??0); ?>
                                <?php $rowBci = trim((string) ($febDisplay['numero_bon_commande_interne'] ?? $febDisplay['code_autorisation_feb'] ?? '')); ?>
                                <?php $rowDocuments = febBuildDocumentList($payloadRow); ?>
                                <?php $rowJustificatifs = array_values(array_filter($rowDocuments, static fn(array $document): bool => trim((string)($document['url'] ?? '')) !== '' && str_contains(mb_strtolower((string)($document['type'] ?? '')), 'justificatif'))); ?>
                                <?php $rowPrimaryJustificatif = $rowJustificatifs[0] ?? null; ?>
                                <?php $requesterName = trim((string) ($febDisplay['demandeur'] ?? ($row['requester_name'] ?? ''))); ?>
                                <?php $requesterProfile = $requesterProfiles[$rowId] ?? []; ?>
                                <?php $requesterPhotoUrl = trim((string) ($requesterProfile['photo_url'] ?? '')); ?>
                                <?php $descriptionPreview = febExcerpt((string) ($febDisplay['description'] ?? ($row['title'] ?? '')), 26); ?>
                                <?php $createdLabel = febFormatDateLabel((string) (($febDisplay['created_at'] ?? '') ?: ($row['submitted_at'] ?? ''))); ?>
                                <?php $submittedAt = trim((string) ($row['submitted_at'] ?? ''));$submittedLabel = $submittedAt !== '' ? febFormatDateLabel($submittedAt) : 'Non renseignée'; ?>
                                <?php $planningDate=(string)($row['planning_date']??'');$planningLabel=$planningDate!==''?febFormatDateLabel($planningDate):'Non planifiée'; ?>
                                <?php
                                $approvalDraft = is_array($payloadRow['provisional_decaissement'] ?? null) ? (array)$payloadRow['provisional_decaissement'] : [];
                                $approvalDraftActive = !empty($approvalDraft['active']);
                                $approvalProposal = is_array($payloadRow['proposed_debourse'] ?? null) ? (array)$payloadRow['proposed_debourse'] : [];
                                $approvalLines = array_values(array_filter((array)($approvalProposal['lines'] ?? []), 'is_array'));
                                $approvalScheduledDate = trim((string)($approvalDraft['date_decaissement_minimum'] ?? $approvalDraft['planned_start_date'] ?? $payloadRow['planned_start_date'] ?? $payloadRow['date_livraison'] ?? ''));
                                $approvalAmount = (float)($approvalDraft['montant_fiche'] ?? $febDisplay['montant'] ?? 0);
                                $approvalBusiness = febApprovalBusinessContext($pdo, $payloadRow, $approvalDraft, $approvalProposal);
                                $approvalBeneficiary = trim((string)($approvalDraft['beneficiaire_fiche'] ?? $approvalDraft['beneficiary_name'] ?? ''));
                                if (trim((string)($approvalBusiness['beneficiary'] ?? '')) !== '') $approvalBeneficiary = trim((string)$approvalBusiness['beneficiary']);
                                if ($approvalBeneficiary === '') $approvalBeneficiary = $requesterName !== '' ? $requesterName : 'À déterminer';
                                $approvalDevisPending = !empty($approvalBusiness['devis_pending']);
                                $approvalNoFicheReasons = [];
                                $rowIsSignedRh = false;
                                if (!empty($payloadRow['rh_financial_point_id'])) {
                                    try {(new RhFinancialPointService($pdo))->assertSignedFeb($row);$rowIsSignedRh=true;}
                                    catch(Throwable $ignored) {}
                                }
                                if (!$approvalDraftActive) {
                                    $approvalNoFicheReasons[] = 'La génération automatique de la fiche n’a pas été activée dans la préparation de cette FEB.';
                                }
                                if ($approvalScheduledDate === '') $approvalNoFicheReasons[] = 'Aucune date prévue de décaissement n’a été renseignée.';
                                if (max((int)($approvalDraft['chantier_id'] ?? 0), (int)($payloadRow['chantier_id'] ?? 0)) <= 0) $approvalNoFicheReasons[] = 'Le chantier de la fiche n’est pas confirmé.';
                                if ((int)($approvalDraft['ligne_debourse_id'] ?? 0) <= 0) $approvalNoFicheReasons[] = (string)($payloadRow['budget_planned'] ?? '') === 'no' ? 'Le déboursé proposé doit d’abord être intégré.' : 'La ligne de déboursé à utiliser pour la fiche n’est pas confirmée.';
                                if ((float)($approvalDraft['montant_fiche'] ?? $approvalAmount) <= 0) $approvalNoFicheReasons[] = 'Le montant de la fiche n’est pas valide.';
                                if (trim((string)($approvalDraft['mode_paiement'] ?? '')) === '') $approvalNoFicheReasons[] = 'Le mode de paiement n’est pas renseigné.';
                                $approvalSupplierId=FebAssignmentBeneficiary::supplierId($payloadRow);
                                if (!$rowIsSignedRh && $approvalSupplierId <= 0) $approvalNoFicheReasons[] = 'Le fournisseur à rattacher à la fiche n’est pas sélectionné.';
                                $beneficiaryMode = strtolower(trim((string)($approvalDraft['beneficiary_mode'] ?? 'personnel')));
                                if ($beneficiaryMode === 'personnel' && (int)($approvalDraft['personnel_id'] ?? 0) <= 0) $approvalNoFicheReasons[] = 'Le bénéficiaire interne n’est pas sélectionné dans le personnel.';
                                if ($beneficiaryMode === 'external') $approvalNoFicheReasons[] = 'Les pièces du bénéficiaire externe doivent être contrôlées depuis l’analyse détaillée avant la génération.';
                                $approvalNoFicheReasons = array_values(array_unique($approvalNoFicheReasons));
                                $approvalWillCreateFiche = $approvalDraftActive && !$approvalNoFicheReasons;
                                $beneficiaryDisplayName = $approvalBeneficiary;
                                $beneficiaryPhotoUrl = '';
                                $beneficiaryDisplayRole = 'Bénéficiaire de la FEB';
                                $beneficiaryPersonnelId = (int)($approvalDraft['personnel_id'] ?? 0);
                                $requesterPersonnelId = (int)($requesterProfile['personnel_id'] ?? 0);
                                if ($beneficiaryMode === 'personnel' && $beneficiaryPersonnelId > 0) {
                                    foreach ($febApprovalSelectors['personnel'] as $person) {
                                        if ((int)($person['id'] ?? 0) !== $beneficiaryPersonnelId) continue;
                                        $beneficiaryDisplayName = trim((string)($person['nom'] ?? '')) ?: $beneficiaryDisplayName;
                                        $beneficiaryPhotoUrl = febResolveUserPhotoPath((string)($person['photo'] ?? ''));
                                        break;
                                    }
                                    if ($beneficiaryPersonnelId === $requesterPersonnelId) $beneficiaryDisplayRole = 'Demandeur et bénéficiaire';
                                } elseif ($beneficiaryMode === 'supplier' && $approvalSupplierId > 0) {
                                    foreach ($febApprovalSelectors['suppliers'] as $supplier) {
                                        if ((int)($supplier['id'] ?? 0) !== $approvalSupplierId) continue;
                                        $beneficiaryDisplayName = trim((string)($supplier['nom'] ?? '')) ?: $beneficiaryDisplayName;
                                        $beneficiaryPhotoUrl = trim((string)($supplier['photo'] ?? ''));
                                        break;
                                    }
                                    $beneficiaryDisplayRole = 'Fournisseur bénéficiaire';
                                } elseif ($beneficiaryMode === 'personnel' && $beneficiaryPersonnelId <= 0) {
                                    $beneficiaryDisplayName = $requesterName !== '' ? $requesterName : 'Bénéficiaire à déterminer';
                                    $beneficiaryPhotoUrl = $requesterPhotoUrl;
                                    $beneficiaryDisplayRole = 'Demandeur (bénéficiaire à confirmer)';
                                } elseif ($beneficiaryDisplayName === '' || $beneficiaryDisplayName === 'À déterminer') {
                                    $beneficiaryDisplayName = $requesterName !== '' ? $requesterName : 'Bénéficiaire à déterminer';
                                    $beneficiaryPhotoUrl = $requesterPhotoUrl;
                                    $beneficiaryDisplayRole = 'Demandeur (bénéficiaire à confirmer)';
                                }
                                if ($beneficiaryPhotoUrl === '' && $beneficiaryDisplayName === $requesterName) $beneficiaryPhotoUrl = $requesterPhotoUrl;
                                $approvalReturnNote = $approvalNoFicheReasons
                                    ? 'Merci de compléter la FEB avant une nouvelle soumission : ' . implode(' ', $approvalNoFicheReasons)
                                    : 'Merci de compléter les informations nécessaires à la génération de la fiche de décaissement.';
                                $approvalCanIntegrateDebourse = (string)($payloadRow['budget_planned'] ?? '') === 'no'
                                    && !empty($approvalProposal)
                                    && trim((string)($approvalBusiness['debourse'] ?? '')) === '';
                                $approvalPlanning=[];
                                try {$approvalPlanning=ExpiredFebPlanningAdjustmentService::preview($pdo,(int)($approvalDraft['debourse_id']??0),(int)($approvalDraft['chantier_id']??$payloadRow['chantier_id']??0));} catch(Throwable $ignored) {}
                                $approvalPreview = [
                                    'feb_id' => $rowId,
                                    'rh_financial_signed' => $rowIsSignedRh,
                                    'will_create_fiche' => $approvalWillCreateFiche,
                                    'amount' => $approvalAmount,
                                    'amount_label' => number_format($approvalAmount, 0, ',', ' ') . ' FCFA',
                                    'treasury_control_visible' => $canSeeTreasuryControl,
                                    'treasury_control_ready' => $treasuryControlReady,
                                    'treasury_available_today' => $treasuryAvailableToday,
                                    'treasury_after_approval' => $treasuryAvailableToday-$approvalAmount,
                                    'treasury_sufficient' => $treasuryAvailableToday >= $approvalAmount,
                                    'treasury_real_coverage' => $treasuryRealCoverage,
                                    'treasury_forecast_invoices' => $treasuryFundingInvoices,
                                    'beneficiary' => $approvalBeneficiary,
                                    'scheduled_date' => $approvalScheduledDate,
                                    'planned_start_date' => trim((string)($approvalPlanning['date_debut']??$payloadRow['planned_start_date'] ?? $approvalScheduledDate)),
                                    'planned_end_date' => trim((string)($approvalPlanning['date_fin']??$payloadRow['planned_end_date'] ?? $approvalScheduledDate)),
                                    'chantier_start_date' => (string)($approvalPlanning['chantier_start']??''),
                                    'chantier_end_date' => (string)($approvalPlanning['chantier_end']??''),
                                    'can_adjust_expired_planning' => !empty($approvalPlanning) && !empty($_SESSION['permissions']['modifier_dates_travaux']),
                                    'beneficiary_mode' => $beneficiaryMode,
                                    'personnel_id' => (int)($approvalDraft['personnel_id'] ?? 0),
                                    'fournisseur_id' => (int)($approvalDraft['fournisseur_id'] ?? $payloadRow['fournisseur_id'] ?? 0),
                                    'chantier_id' => max((int)($approvalDraft['chantier_id'] ?? 0), (int)($payloadRow['chantier_id'] ?? 0)),
                                    'ligne_devis_id' => (int)($approvalDraft['ligne_devis_id'] ?? 0),
                                    'debourse_id' => (int)($approvalDraft['debourse_id'] ?? 0),
                                    'ligne_debourse_id' => (int)($approvalDraft['ligne_debourse_id'] ?? 0),
                                    'mode_paiement' => trim((string)($approvalDraft['mode_paiement'] ?? '')),
                                    'scheduled_date_label' => $approvalScheduledDate !== '' ? febFormatDateLabel($approvalScheduledDate) : 'Non planifiée',
                                    'will_create_bci' => $approvalWillCreateFiche,
                                    'no_fiche_reasons' => $approvalNoFicheReasons,
                                    'return_note' => $approvalReturnNote,
                                    'can_integrate_debourse' => $approvalCanIntegrateDebourse,
                                    'challenge_count' => $rowChallengeCount,
                                    'retained_challenge_count' => $rowRetainedChallengeCount,
                                    'need_count' => count((array)($payloadRow['besoins']??[])),
                                    'proposed_debourse_title' => trim((string)($approvalProposal['title'] ?? 'Nouveau déboursé proposé')),
                                    'proposed_ligne_devis_label' => trim((string)($approvalProposal['ligne_devis_proposal'] ?? $approvalProposal['title'] ?? 'Nouvelle ligne FEB')),
                                    'proposed_lines' => $approvalLines,
                                    'description' => trim((string)($payloadRow['description'] ?? '')),
                                    'impact' => trim((string)($payloadRow['impact'] ?? '')),
                                    'delivery_date' => trim((string)($payloadRow['date_livraison'] ?? '')),
                                    'frequency' => trim((string)($payloadRow['frequence'] ?? '')),
                                    'conformity' => $approvalWillCreateFiche ? 'Conforme dès la création' : 'Aucune fiche créée',
                                    'purchase_validation' => $approvalWillCreateFiche ? 'En attente d’approbation par un utilisateur habilité, puis validation Achat' : 'Non applicable',
                                    'chantier' => trim((string)($approvalBusiness['chantier'] ?? '')) ?: trim((string)($payloadRow['chantier_label'] ?? $febDisplay['chantier_label'] ?? ('FEB #' . $rowId))),
                                    'devis' => trim((string)($approvalBusiness['devis'] ?? '')) ?: 'Devis cible à confirmer',
                                    'devis_pending' => $approvalDevisPending,
                                    'detail_url' => 'validation_feb_detail.php?tab=' . rawurlencode($tab) . '&id=' . $rowId,
                                    'ligne_devis' => trim((string)($approvalBusiness['ligne_devis'] ?? '')) ?: (trim((string)($approvalProposal['ligne_devis_proposal'] ?? '')) ?: 'Nouvelle ligne de devis à intégrer'),
                                    'debourse' => trim((string)($approvalBusiness['debourse'] ?? '')) ?: (trim((string)($approvalProposal['title'] ?? '')) ?: 'Nouveau déboursé à intégrer'),
                                    'ligne_debourse' => trim((string)($approvalBusiness['ligne_debourse'] ?? '')) ?: (trim((string)($approvalLines[0]['designation'] ?? '')) ?: 'Ligne de déboursé à créer'),
                                    'details' => trim((string)($approvalDraft['details_demande'] ?? $payloadRow['description'] ?? $descriptionPreview)),
                                ];
                                ?>
                                <article class="feb-list-card p-5 transition hover:-translate-y-0.5 hover:shadow-md">
                                    <div class="feb-card-topline">
                                        <label class="feb-card-number">
                                            <input type="checkbox" name="ids[]" value="<?= $rowId ?>" data-amount="<?= htmlspecialchars((string) ((float) ($febDisplay['montant'] ?? 0))) ?>" class="feb-select h-4 w-4 rounded border-slate-300 text-indigo-600">
                                            <span>FEB #<?= $rowId ?></span>
                                        </label>
                                        <div class="feb-card-badges">
                                            <span class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold <?= $badge['class'] ?>"><?= htmlspecialchars($badge['label']) ?></span>
                                            <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold <?= ($payloadRow['budget_planned'] ?? '') === 'yes' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' ?>"><?= htmlspecialchars((string) ($payloadRow['budget_planned_label'] ?? 'Affectation budgétaire à vérifier')) ?></span>
                                            <?php if (!empty($payloadRow['proposed_debourse'])): ?>
                                                <span class="inline-flex items-center rounded-full bg-orange-100 px-3 py-1 text-xs font-semibold text-orange-800">Déboursé proposé à analyser</span>
                                            <?php endif; ?>
                                            <a href="validation_feb_detail.php?tab=<?=urlencode($tab)?>&id=<?=$rowId?>#comparaison-prix" class="inline-flex items-center rounded-full px-3 py-1 text-xs font-black <?=$rowChallengeCount>0?'bg-violet-100 text-violet-800':'bg-slate-100 text-slate-600'?>"><i class="fas <?=$rowChallengeCount>0?'fa-scale-balanced':'fa-circle-minus'?> mr-2"></i><?=$rowChallengeCount>0?($rowChallengeCount.' devis contradictoire(s)'.($rowRetainedChallengeCount>0?' · choix effectué':'')):'Aucun devis contradictoire'?></a>
                                        </div>
                                    </div>

                                    <div class="feb-card-amountline">
                                        <span class="feb-card-amountline-label">Montant demandé</span>
                                        <span class="feb-amount"><?= htmlspecialchars((string) ($febDisplay['montant_label'] ?? '-')) ?></span>
                                    </div>

                                    <div class="feb-card-header">
                                        <div class="flex items-start gap-4 min-w-0">
                                            <div class="feb-avatar">
                                                <?php if ($beneficiaryPhotoUrl !== ''): ?>
                                                    <img src="<?= htmlspecialchars($beneficiaryPhotoUrl) ?>" alt="Photo de <?= htmlspecialchars($beneficiaryDisplayName) ?>">
                                                <?php else: ?>
                                                    <div class="feb-avatar-fallback"><?= htmlspecialchars(febInitials($beneficiaryDisplayName)) ?></div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="min-w-0 flex-1">
                                                <div class="truncate text-lg font-bold leading-tight text-slate-900"><?= htmlspecialchars($beneficiaryDisplayName) ?></div>
                                                <div class="mt-1 truncate text-[11px] text-slate-500"><?= htmlspecialchars($beneficiaryDisplayRole) ?></div>
                                                <div class="text-[11px] font-semibold text-indigo-700"><i class="far fa-calendar mr-1"></i>Prévue le <?= htmlspecialchars($planningLabel) ?></div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="mt-4 feb-card-meta">
                                        <div class="feb-card-meta-item feb-card-meta-item--wide">
                                            <span class="feb-card-meta-label">Objet / chantier</span>
                                            <span class="feb-card-meta-value"><?= htmlspecialchars((string) ($febDisplay['chantier_label'] ?? ('FEB-' . $rowId))) ?></span>
                                        </div>
                                        <div class="feb-card-meta-item feb-card-meta-item--wide">
                                            <span class="feb-card-meta-label">Description</span>
                                            <span class="feb-card-meta-value"><?= htmlspecialchars($descriptionPreview) ?></span>
                                        </div>
                                        <div class="feb-card-meta-item">
                                            <span class="feb-card-meta-label">BCI</span>
                                            <span class="feb-card-meta-value"><?= htmlspecialchars($rowBci !== '' ? $rowBci : 'Non généré') ?></span>
                                        </div>
                                        <div class="feb-card-meta-item">
                                            <span class="feb-card-meta-label">Analyse budgétaire</span>
                                            <span class="feb-card-meta-value"><?= $rowIsSignedRh ? 'Ressources humaines · Personnel, sans fournisseur. Contrôler le point signé, le budget et la couverture des fonds.' : (($payloadRow['budget_planned'] ?? '') === 'yes' ? 'Contrôler le devis, le déboursé, la ligne, le fournisseur, le montant et le planning.' : 'Analyser la proposition de nouveau déboursé et chacune de ses lignes planifiées.') ?></span>
                                        </div>
                                        <div class="feb-card-meta-item">
                                            <span class="feb-card-meta-label">Bénéficiaire <?php if($rowActionIsTreat):?><button type="button" class="js-edit-feb-beneficiary ml-1 inline-flex h-5 w-5 items-center justify-center rounded-full text-slate-400 hover:bg-indigo-50 hover:text-indigo-700" title="Modifier le bénéficiaire" aria-label="Modifier le bénéficiaire"><i class="fas fa-pencil-alt text-[9px]"></i></button><?php endif;?></span>
                                            <span class="feb-card-meta-value"><?=htmlspecialchars($approvalBeneficiary)?></span>
                                        </div>
                                        <?php if (!empty($payloadRow['validation_deferred_until'])): ?><div class="feb-card-meta-item"><span class="feb-card-meta-label">Report validateur</span><span class="feb-card-meta-value font-bold text-amber-700"><?=htmlspecialchars(febFormatDateLabel((string)$payloadRow['validation_deferred_until']))?></span></div><?php endif; ?>
                                    </div>

                                    <div class="mt-4 feb-card-footer">
                                        <div class="feb-card-dates">
                                            <div class="feb-card-date"><span class="feb-card-date-label">Date prévue de l’action</span><span class="feb-card-date-value text-indigo-700"><?= htmlspecialchars($planningLabel) ?></span></div>
                                            <div class="feb-card-date"><span class="feb-card-date-label">Date de soumission</span><span class="feb-card-date-value"><?= htmlspecialchars($submittedLabel) ?></span></div>
                                        </div>
                                        <div class="flex flex-wrap justify-end gap-2">
                                            <?php if ($rowPrimaryJustificatif): ?><span class="inline-flex items-center gap-0.5"><a class="feb-action-link border border-cyan-200 bg-cyan-50 text-cyan-800 hover:bg-cyan-100" href="<?= htmlspecialchars((string)$rowPrimaryJustificatif['url']) ?>" target="_blank" rel="noopener"><i class="fas fa-paperclip mr-1"></i>Justificatif BFEB<?= count($rowJustificatifs) > 1 ? ' +' . (count($rowJustificatifs) - 1) : '' ?></a><?php if($rowActionIsTreat):?><form method="post" enctype="multipart/form-data" class="inline-flex"><input type="hidden" name="feb_workflow_csrf" value="<?= htmlspecialchars((string)$_SESSION['feb_workflow_csrf'], ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="quick_replace_feb_proof"><input type="hidden" name="request_id" value="<?=$rowId?>"><input type="hidden" name="_csrf" value="<?=htmlspecialchars((string)$_SESSION['feb_quick_edit_csrf'])?>"><label class="inline-flex h-6 w-6 cursor-pointer items-center justify-center rounded-full text-slate-400 hover:bg-cyan-50 hover:text-cyan-700" title="Remplacer le justificatif BFEB"><i class="fas fa-pencil-alt text-[9px]"></i><input type="file" name="feb_proof" accept="application/pdf,image/jpeg,image/png,image/webp" class="hidden" data-no-dropzone onchange="if(this.files.length&&confirm('Remplacer le justificatif BFEB ?'))this.form.submit();else this.value=''"></label></form><?php endif;?></span><?php endif; ?>
                                            <a class="feb-action-link border border-teal-200 bg-teal-50 text-teal-800" href="request/export_feb_submission_pdf.php?id=<?= $rowId ?>" target="_blank" rel="noopener"><i class="fas fa-file-pdf"></i> FEB PDF</a>
                                            <a class="feb-action-link border border-indigo-200 bg-white text-indigo-700 hover:bg-indigo-50" href="validation_feb_detail.php?tab=<?= urlencode($tab) ?>&id=<?= $rowId ?>">Détails</a>
                                            <?php if ($rowBci !== ''): ?><a class="feb-action-link border border-slate-200 bg-white text-slate-800 hover:bg-slate-50" href="request/export_feb_pdf.php?id=<?= $rowId ?>" target="_blank" rel="noopener">BCI PDF</a><?php endif; ?>
                                            <a class="feb-action-link border border-emerald-200 bg-emerald-50 text-emerald-800 hover:bg-emerald-100" href="request/export_feb_devis_excel.php?id=<?= $rowId ?>" target="_blank" rel="noopener">Extraire devis</a>
                                            <?php if ($rowActionIsTreat): ?>
                                                <?php $rowDeferredUntil=trim((string)($payloadRow['validation_deferred_until']??''));$rowIsDeferred=$rowDeferredUntil>date('Y-m-d'); ?>
                                                <?php if ($rowIsDeferred): ?>
                                                <form method="post" class="inline-flex" onsubmit="return confirm('Ramener cette FEB à aujourd’hui et avancer sa prévision de trésorerie ?');"><input type="hidden" name="feb_workflow_csrf" value="<?= htmlspecialchars((string)$_SESSION['feb_workflow_csrf'], ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="bring_today"><input type="hidden" name="request_id" value="<?=$rowId?>"><input type="hidden" name="tab" value="<?=htmlspecialchars($tab)?>"><button class="feb-action-link border border-sky-200 bg-sky-50 text-sky-800 hover:bg-sky-100">Ramener à aujourd’hui</button></form>
                                                <?php else: ?>
                                                <form method="post" class="inline-flex items-center gap-1"><input type="hidden" name="feb_workflow_csrf" value="<?= htmlspecialchars((string)$_SESSION['feb_workflow_csrf'], ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="defer"><input type="hidden" name="request_id" value="<?=$rowId?>"><input type="hidden" name="tab" value="<?=htmlspecialchars($tab)?>"><input type="date" name="deferred_until" min="<?=date('Y-m-d',strtotime('+1 day'))?>" required class="rounded-lg border border-amber-200 px-2 py-1 text-xs"><button class="feb-action-link border border-amber-200 bg-amber-50 text-amber-800 hover:bg-amber-100">Reporter</button></form>
                                                <?php endif; ?>
                                                <?php if (!$rowIsDeferred): ?>
                                                <form method="post" class="inline-flex js-feb-approve-form" data-approval-preview="<?= htmlspecialchars(json_encode($approvalPreview, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="feb_workflow_csrf" value="<?= htmlspecialchars((string)$_SESSION['feb_workflow_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="action" value="approve">
                                                    <input type="hidden" name="request_id" value="<?= $rowId ?>">
                                                    <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
                                                    <?php if ($includeLegacyFeb): ?><input type="hidden" name="archives" value="1"><?php endif; ?>
                                                    <input type="hidden" name="note" value="Approbation rapide depuis la liste FEB.">
                                                    <button type="submit" class="feb-action-link border border-emerald-200 bg-emerald-600 text-white shadow-sm hover:bg-emerald-700">Approuver</button>
                                                </form>
                                                <form method="post" class="inline-flex" onsubmit="return confirm('Confirmer le refus rapide de cette FEB ?');"><input type="hidden" name="feb_workflow_csrf" value="<?= htmlspecialchars((string)$_SESSION['feb_workflow_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="action" value="reject">
                                                    <input type="hidden" name="request_id" value="<?= $rowId ?>">
                                                    <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
                                                    <?php if ($includeLegacyFeb): ?><input type="hidden" name="archives" value="1"><?php endif; ?>
                                                    <input type="hidden" name="note" value="Refus rapide depuis la liste FEB.">
                                                    <button type="submit" class="feb-action-link border border-rose-200 bg-rose-50 text-rose-700 hover:bg-rose-100">Refuser</button>
                                                </form>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="mt-6 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4 text-sm text-slate-700">
                    <div class="font-semibold">Lien Décaissement</div>
                    <div class="mt-1">Après validation, si un paiement est requis, utilisez le workflow habituel de <a class="font-semibold text-indigo-700 hover:text-indigo-800" href="decaissement/formulaire_demande_decaissement.php">fiche de décaissement</a>.</div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<div id="febApprovalModal" class="fixed inset-0 z-[100] hidden items-center justify-center bg-slate-950/60 p-4 backdrop-blur-sm" aria-hidden="true">
    <div class="max-h-[92vh] w-full max-w-4xl overflow-y-auto rounded-[2rem] border border-white/30 bg-white shadow-2xl">
        <div class="bg-gradient-to-r from-slate-950 via-indigo-950 to-indigo-800 px-6 py-6 text-white md:px-8">
            <div class="flex items-start justify-between gap-4">
                <div><div class="text-xs font-black uppercase tracking-[.22em] text-indigo-200">Confirmation financière</div><h2 class="mt-2 text-2xl font-black">Effet de l’approbation FEB</h2><p class="mt-2 text-sm text-indigo-100">Vérifiez précisément ce que la plateforme va créer avant de confirmer.</p></div>
                <button type="button" data-feb-modal-close class="rounded-full bg-white/10 px-4 py-2 text-xl hover:bg-white/20" aria-label="Fermer">×</button>
            </div>
        </div>
        <div data-feb-modal-content class="p-6 md:p-8"></div>
        <div class="flex flex-wrap justify-end gap-3 border-t border-slate-200 bg-slate-50 px-6 py-5 md:px-8">
            <button type="button" data-feb-modal-close class="rounded-xl border border-slate-200 bg-white px-5 py-3 font-bold text-slate-700">Annuler</button>
            <button type="button" data-feb-modal-return class="hidden rounded-xl border border-amber-300 bg-amber-50 px-5 py-3 font-bold text-amber-900 hover:bg-amber-100">Retourner au soumissionnaire</button>
            <button type="button" data-feb-modal-save-amount class="rounded-xl border border-violet-300 bg-violet-50 px-5 py-3 font-bold text-violet-900 hover:bg-violet-100" title="Enregistrer les modifications sans approuver la FEB">Enregistrer les modifications</button>
            <button type="button" data-feb-modal-integrate class="hidden rounded-xl bg-indigo-600 px-5 py-3 font-bold text-white shadow-lg hover:bg-indigo-700">Valider et intégrer le déboursé proposé</button>
            <button type="button" data-feb-modal-confirm class="rounded-xl bg-emerald-600 px-5 py-3 font-bold text-white shadow-lg hover:bg-emerald-700">Confirmer l’approbation</button>
        </div>
    </div>
</div>

<?php if ($flash): ?>
<div id="febTransactionResult" class="fixed inset-0 z-[110] flex items-center justify-center bg-slate-950/55 p-4 backdrop-blur-sm">
    <div class="w-full max-w-lg rounded-[2rem] bg-white p-8 text-center shadow-2xl">
        <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full <?= $flashType === 'success' ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700' ?> text-3xl">
            <i class="fas <?= $flashType === 'success' ? 'fa-check' : 'fa-xmark' ?>"></i>
        </div>
        <h2 class="mt-5 text-2xl font-black text-slate-950"><?= $flashType === 'success' ? 'Transaction terminée' : 'Transaction échouée' ?></h2>
        <p class="mt-3 text-sm leading-6 text-slate-600"><?= htmlspecialchars($flash) ?></p>
        <button type="button" onclick="document.getElementById('febTransactionResult').remove()" class="mt-6 rounded-xl bg-slate-900 px-5 py-3 font-bold text-white">Fermer</button>
    </div>
</div>
<?php endif; ?>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('[data-filter-multiselect]').forEach(root=>{const select=root.querySelector('select[multiple]'),search=root.querySelector('[data-multi-search]'),results=root.querySelector('[data-multi-results]'),chips=root.querySelector('[data-multi-chips]');if(!select||!search||!results||!chips)return;const safe=value=>String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));const renderChips=()=>{chips.innerHTML=Array.from(select.selectedOptions).map(option=>`<button type="button" data-remove-value="${encodeURIComponent(option.value)}" class="rounded-full border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-800">${safe(option.textContent)} ×</button>`).join('');chips.querySelectorAll('[data-remove-value]').forEach(button=>button.addEventListener('click',()=>{const value=decodeURIComponent(button.dataset.removeValue||'');const option=Array.from(select.options).find(item=>item.value===value);if(option)option.selected=false;renderChips();renderResults();}));};const renderResults=()=>{const q=search.value.trim().toLocaleLowerCase('fr');const options=Array.from(select.options).filter(option=>option.textContent.toLocaleLowerCase('fr').includes(q)).slice(0,60);results.innerHTML=`<div class="absolute z-40 mt-1 max-h-72 w-full overflow-y-auto rounded-xl border border-slate-200 bg-white shadow-xl">${options.length?options.map(option=>`<label class="flex cursor-pointer items-center gap-2 border-b border-slate-100 px-3 py-2 text-sm hover:bg-indigo-50"><input type="checkbox" data-option-value="${encodeURIComponent(option.value)}" ${option.selected?'checked':''}><span class="min-w-0 truncate">${safe(option.textContent)}</span></label>`).join(''):'<div class="p-3 text-sm text-slate-500">Aucun résultat</div>'}</div>`;results.classList.remove('hidden');results.querySelectorAll('[data-option-value]').forEach(box=>box.addEventListener('change',()=>{const value=decodeURIComponent(box.dataset.optionValue||'');const option=Array.from(select.options).find(item=>item.value===value);if(option)option.selected=box.checked;renderChips();}));};search.addEventListener('focus',renderResults);search.addEventListener('input',renderResults);search.addEventListener('keydown',event=>{if(event.key==='Escape'){results.classList.add('hidden');search.blur();}});root.addEventListener('focusout',()=>setTimeout(()=>{if(!root.contains(document.activeElement))results.classList.add('hidden');},120));renderChips();});
        const approvalSelectors = <?=json_encode($febApprovalSelectors,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?:'{}'?>;
        const supplierUnblockCsrf = <?=json_encode((string)$_SESSION['feb_supplier_unblock_csrf'])?>;
        const approvalCategories = <?=json_encode(FebCategoryMigrationService::CATEGORIES,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
        const approvalModal = document.getElementById('febApprovalModal');
        const approvalContent = approvalModal?.querySelector('[data-feb-modal-content]');
        const approvalConfirm = approvalModal?.querySelector('[data-feb-modal-confirm]');
        const approvalReturn = approvalModal?.querySelector('[data-feb-modal-return]');
        const approvalIntegrate = approvalModal?.querySelector('[data-feb-modal-integrate]');
        const approvalSaveAmount = approvalModal?.querySelector('[data-feb-modal-save-amount]');
        let pendingApprovalForm = null;
        let previewForPopup = {};
        const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
        const closeApprovalModal = () => {
            approvalModal?.classList.add('hidden');
            approvalModal?.classList.remove('flex');
            approvalModal?.setAttribute('aria-hidden', 'true');
            pendingApprovalForm = null;
            previewForPopup = {};
        };
        document.querySelectorAll('[data-feb-modal-close]').forEach(button => button.addEventListener('click', closeApprovalModal));
        const setPendingValue = (name, value) => {
            if (!pendingApprovalForm) return;
            let input = pendingApprovalForm.querySelector(`[name="${name}"]`);
            if (!input) {
                input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                pendingApprovalForm.appendChild(input);
            }
            input.value = value ?? '';
        };
        const smartSelectorConfig = {
            personnel:{items:'personnel',placeholder:'Rechercher un nom, matricule ou téléphone…',title:i=>i.nom,meta:i=>[i.fonction,i.matricule,i.telephone].filter(Boolean).join(' · '),photo:true},
            supplier:{items:'suppliers',placeholder:'Rechercher un fournisseur, contact ou téléphone…',title:i=>i.nom,meta:i=>[i.contact,i.telephone].filter(Boolean).join(' · '),photo:true},
            chantier:{items:'chantiers',placeholder:'Rechercher un chantier par code ou intitulé…',title:i=>`${i.code||''} — ${i.titre||''}`,meta:i=>`Devis #${i.devis_id||'—'}`},
            devis_line:{items:'devis_lines',placeholder:'Rechercher une ligne de devis…',title:i=>i.designation||`Ligne #${i.id}`,meta:i=>`${Number(i.total||0).toLocaleString('fr-FR')} FCFA`},
            debourse:{items:'debourses',placeholder:'Rechercher un déboursé…',title:i=>i.label||`Déboursé #${i.id}`,meta:i=>`${Number(i.montant_debourse||0).toLocaleString('fr-FR')} FCFA`},
            debourse_line:{items:'debourse_lines',placeholder:'Rechercher un poste financier…',title:i=>i.designation||`Poste #${i.id}`,meta:i=>[i.categorie,`${Number(i.montant||0).toLocaleString('fr-FR')} FCFA`].filter(Boolean).join(' · ')},
        };
        const initializeApprovalSmartSelectors = () => {
            const roots=Array.from(approvalContent.querySelectorAll('[data-smart-select]'));
            const byKind=kind=>roots.find(root=>root.dataset.smartSelect===kind);
            const selectedValue=kind=>byKind(kind)?.querySelector('[data-smart-value]')?.value||'';
            const availableItems=kind=>{
                const cfg=smartSelectorConfig[kind];let items=Array.from(approvalSelectors[cfg.items]||[]);
                const chantier=(approvalSelectors.chantiers||[]).find(i=>String(i.id)===String(selectedValue('chantier')));
                if(kind==='devis_line'&&chantier)items=items.filter(i=>String(i.devis_id)===String(chantier.devis_id));
                if(kind==='debourse'){
                    const lineId=selectedValue('devis_line');
                    if(lineId)items=items.filter(i=>String(i.ligne_devis_id)===String(lineId));else if(chantier)items=items.filter(i=>String(i.devis_id)===String(chantier.devis_id));
                }
                if(kind==='debourse_line'&&selectedValue('debourse'))items=items.filter(i=>String(i.debourse_id)===String(selectedValue('debourse')));
                return items;
            };
            const clearAfter=kind=>{
                const chain=['chantier','devis_line','debourse','debourse_line'];const pos=chain.indexOf(kind);
                if(pos<0)return;chain.slice(pos+1).forEach(next=>{const root=byKind(next);if(!root)return;root.querySelector('[data-smart-value]').value='';root.querySelector('[data-smart-search]').value='';root.querySelector('[data-smart-preview]').innerHTML='';});
            };
            const selectRecipient=kind=>{
                if(!['supplier','personnel'].includes(kind))return;
                const mode=approvalContent.querySelector('[data-feb-edit-beneficiary-mode]');
                if(mode)mode.value=kind==='supplier'?'supplier':'personnel';
                const opposite=byKind(kind==='supplier'?'personnel':'supplier');
                if(opposite){opposite.querySelector('[data-smart-value]').value='';opposite.querySelector('[data-smart-search]').value='';opposite.querySelector('[data-smart-preview]').innerHTML='';}
                const selected=(approvalSelectors[smartSelectorConfig[kind].items]||[]).find(item=>String(item.id)===String(selectedValue(kind)));
                const summary=approvalContent.querySelector('[data-popup-supplier-selection]');
                if(summary)summary.textContent=(kind==='supplier'?'Fournisseur sélectionné : ':'Bénéficiaire personnel : ')+(selected?smartSelectorConfig[kind].title(selected):'')+'. Cliquez sur « Enregistrer les modifications » pour actualiser le récapitulatif.';
            };
            roots.forEach(root=>{
                const kind=root.dataset.smartSelect,cfg=smartSelectorConfig[kind],hidden=root.querySelector('[data-smart-value]');
                const search=root.querySelector('[data-smart-search]'),results=root.querySelector('[data-smart-results]'),preview=root.querySelector('[data-smart-preview]');
                const renderPreview=()=>{const item=(approvalSelectors[cfg.items]||[]).find(i=>String(i.id)===String(hidden.value));if(!item){preview.innerHTML='';return;}search.value=cfg.title(item);preview.innerHTML=`<div class="flex items-center gap-3 rounded-xl border border-indigo-100 bg-indigo-50 p-3">${cfg.photo&&item.photo?`<img src="${esc(item.photo)}" class="h-11 w-11 rounded-full object-cover" onerror="this.style.display='none'">`:'<span class="flex h-11 w-11 items-center justify-center rounded-full bg-indigo-200 font-black text-indigo-800">'+esc((cfg.title(item)||'?').charAt(0))+'</span>'}<span class="min-w-0"><strong class="block truncate text-slate-950">${esc(cfg.title(item))}</strong><small class="block truncate text-slate-600">${esc(cfg.meta(item)||'')}</small></span></div>`;if(kind==='supplier'){const missing=[];if(!String(item.nom_commercial||item.raison_sociale||'').trim())missing.push(['name','Nom commercial','text']);if(!String(item.telephone||'').trim())missing.push(['phone','Téléphone','tel']);if(!String(item.email||'').trim())missing.push(['email','E-mail','email']);if(!String(item.adresse||'').trim())missing.push(['address','Adresse','text']);if(!String(item.photo_path||item.logo_path||'').trim())missing.push(['photo','Photo ou logo','file']);if(missing.length)preview.innerHTML+=`<div class="mt-3 rounded-xl border border-amber-200 bg-amber-50 p-3"><strong class="block text-amber-950">Compléter la fiche fournisseur</strong><p class="mt-1 text-xs text-amber-800">Ces informations seront enregistrées dans la base fournisseur avec la décision.</p><input type="hidden" data-supplier-completion="enabled" value="1"><div class="mt-3 grid gap-2">${missing.map(([key,label,type])=>`<label class="text-xs font-bold text-slate-600">${esc(label)}<input ${type==='file'?'accept="image/jpeg,image/png,image/webp"':''} type="${type}" data-supplier-completion="${key}" class="mt-1 block w-full rounded-lg border border-amber-200 bg-white px-3 py-2 text-sm" required></label>`).join('')}</div></div>`;}};
                const showResults=()=>{const q=search.value.trim().toLocaleLowerCase('fr');const items=availableItems(kind).filter(item=>(cfg.title(item)+' '+cfg.meta(item)+' '+(item.block_reason||'')+' '+(item.block_fiches||'')).toLocaleLowerCase('fr').includes(q)).slice(0,30);results.innerHTML=items.length?items.map(item=>{const blocked=kind==='supplier'&&Boolean(Number(item.blocked));return `<button type="button" ${blocked?'disabled aria-disabled="true"':''} data-smart-option="${item.id}" class="flex w-full items-center gap-3 border-b border-slate-100 px-3 py-3 text-left ${blocked?'cursor-not-allowed bg-slate-100 opacity-65':'hover:bg-indigo-50'}">${cfg.photo&&item.photo?`<img src="${esc(item.photo)}" class="h-10 w-10 rounded-full object-cover" onerror="this.style.display='none'">`:''}<span class="min-w-0"><strong class="block truncate text-sm ${blocked?'text-slate-500':'text-slate-950'}">${esc(cfg.title(item))}</strong><small class="block text-xs ${blocked?'font-bold text-rose-700':'truncate text-slate-500'}">${blocked?esc((item.block_reason||'Justificatifs à fournir')+(item.block_fiches?' · '+item.block_fiches:'')):esc(cfg.meta(item)||'')}</small></span></button>`;}).join(''):'<div class="p-4 text-sm text-slate-500">Aucun résultat. Le fournisseur doit être actif et validé par la comptabilité pour figurer dans cette liste.</div>';results.classList.remove('hidden');results.querySelectorAll('[data-smart-option]:not([disabled])').forEach(button=>button.addEventListener('click',()=>{hidden.value=button.dataset.smartOption;selectRecipient(kind);clearAfter(kind);results.classList.add('hidden');renderPreview();}));};
                const showResultsWithUnblock=()=>{showResults();if(kind!=='supplier')return;results.querySelectorAll('[data-smart-option][disabled]').forEach(row=>{const item=(approvalSelectors.suppliers||[]).find(value=>String(value.id)===String(row.dataset.smartOption));if(!item)return;const action=document.createElement('button');action.type='button';action.className='mx-3 mb-3 mt-2 flex w-[calc(100%-1.5rem)] items-center justify-between rounded-xl border border-rose-200 bg-white px-3 py-2 text-left text-xs font-black text-rose-700 hover:bg-rose-50';action.innerHTML='<span><i class="fas fa-shield-halved mr-2"></i>Régulariser sans preuve<small class="mt-1 block font-semibold text-slate-500">Bypass exceptionnel, nominatif et audité</small></span><span class="relative h-6 w-11 rounded-full bg-slate-300"><span class="absolute left-1 top-1 h-4 w-4 rounded-full bg-white shadow"></span></span>';action.addEventListener('click',async()=>{const reason=prompt(`Motif obligatoire pour régulariser ${cfg.title(item)} sans preuve :`,'');if(reason===null)return;if(reason.trim().length<8){alert('Le motif doit contenir au moins 8 caractères.');return;}if(!confirm(`Confirmer le bypass pour ${cfg.title(item)} ?\n\nVotre identité et le motif seront inscrits dans l’audit. Les fiches ouvertes seront clôturées par exception et le fournisseur sera immédiatement débloqué.`))return;action.disabled=true;action.innerHTML='<span><i class="fas fa-spinner fa-spin mr-2"></i>Régularisation par bypass…</span>';try{const body=new URLSearchParams({_csrf:supplierUnblockCsrf,supplier_id:String(item.id),request_id:String(pendingApprovalForm?.querySelector('[name="request_id"]')?.value||''),bypass_reason:reason.trim()});const response=await fetch('request/unblock_feb_supplier.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'},body});const data=await response.json();if(!response.ok||!data.ok)throw new Error(data.error||'Bypass impossible.');item.blocked=0;item.block_reason='';item.block_fiches='';hidden.value=String(item.id);selectRecipient(kind);search.value=cfg.title(item);results.classList.add('hidden');renderPreview();alert(`Fournisseur régularisé et débloqué. ${Number(data.cancelled_regularizations||0)} fiche(s) clôturée(s) par bypass.`);}catch(error){action.disabled=false;action.innerHTML='<span><i class="fas fa-shield-halved mr-2"></i>Régulariser sans preuve</span>';alert(error.message||'Bypass impossible.');}});row.after(action);});};
                search.addEventListener('focus',showResultsWithUnblock);search.addEventListener('input',()=>{hidden.value='';preview.innerHTML='';clearAfter(kind);showResultsWithUnblock();});search.addEventListener('keydown',event=>{if(event.key==='Escape'){results.classList.add('hidden');search.blur();}});root.addEventListener('focusout',()=>{setTimeout(()=>{if(!root.contains(document.activeElement))results.classList.add('hidden');},120);});renderPreview();
            });
            const closeOnOutsidePointer=event=>{roots.forEach(root=>{if(!root.contains(event.target))root.querySelector('[data-smart-results]')?.classList.add('hidden');});};
            approvalModal.addEventListener('pointerdown',closeOnOutsidePointer,{once:true});
        };
        const setupIntegrationModeSelectors=()=>{
            const lineRoot=approvalContent.querySelector('[data-smart-select="devis_line"]'),debourseRoot=approvalContent.querySelector('[data-smart-select="debourse"]');
            const debourseLineRoot=approvalContent.querySelector('[data-smart-select="debourse_line"]');
            const lineSlot=approvalContent.querySelector('[data-existing-line-slot]'),debourseSlot=approvalContent.querySelector('[data-existing-debourse-slot]');
            if(!lineRoot||!debourseRoot||!lineSlot||!debourseSlot)return;
            const lineHome={parent:lineRoot.parentNode,next:lineRoot.nextSibling};const debourseHome={parent:debourseRoot.parentNode,next:debourseRoot.nextSibling};
            lineHome.parent.classList.add('hidden');debourseHome.parent.classList.add('hidden');if(debourseLineRoot)debourseLineRoot.parentNode.classList.add('hidden');
            const move=(root,slot,home,useExisting)=>{slot.classList.toggle('hidden',!useExisting);if(useExisting)slot.appendChild(root);else home.parent.insertBefore(root,home.next);};
            const sync=()=>{
                const lineExisting=approvalContent.querySelector('input[name="popup_line_mode"]:checked')?.value==='existing';
                const debourseExistingRadio=approvalContent.querySelector('input[name="popup_debourse_mode"][value="existing"]');
                if(debourseExistingRadio)debourseExistingRadio.disabled=!lineExisting;
                if(!lineExisting&&debourseExistingRadio?.checked)approvalContent.querySelector('input[name="popup_debourse_mode"][value="new"]')?.click();
                const debourseExisting=approvalContent.querySelector('input[name="popup_debourse_mode"]:checked')?.value==='existing';
                approvalContent.querySelector('[data-feb-new-line-label]')?.classList.toggle('hidden',lineExisting);
                approvalContent.querySelector('[data-feb-new-debourse-title]')?.classList.toggle('hidden',debourseExisting);
                move(lineRoot,lineSlot,lineHome,lineExisting);move(debourseRoot,debourseSlot,debourseHome,debourseExisting);
            };
            approvalContent.querySelectorAll('input[name="popup_line_mode"],input[name="popup_debourse_mode"]').forEach(input=>input.addEventListener('change',sync));sync();
        };
        const enhanceIntegrationBudgetLines=()=>{
            const container=approvalContent.querySelector('[data-feb-new-lines]');if(!container)return;
            const heading=container.previousElementSibling;if(heading)heading.textContent='Lignes de déboursé à créer et ajouter';
            const info=document.createElement('p');info.className='mt-1 text-xs text-amber-800';info.textContent='Chaque ligne sera ajoutée au déboursé avec sa catégorie, son budget et son planning propre, inclus dans la période globale.';container.insertAdjacentElement('beforebegin',info);
            container.querySelectorAll('[data-line-designation]').forEach((designation,index)=>{
                designation.placeholder='Ligne de déboursé à ajouter';designation.title='Désignation de la ligne de déboursé à créer';
                const categoryInput=container.querySelector(`[data-line-category="${index}"]`);if(categoryInput&&categoryInput.tagName!=='SELECT'){
                    const select=document.createElement('select');select.dataset.lineCategory=String(index);select.className=categoryInput.className;approvalCategories.forEach(category=>{const option=document.createElement('option');option.value=category;option.textContent=category;if(category===categoryInput.value)option.selected=true;select.appendChild(option);});categoryInput.replaceWith(select);
                }
                const row=designation.parentElement;row.classList.add('rounded-xl','border','border-amber-200','bg-white','p-3');
                const globalStart=previewForPopup.planned_start_date||'',globalEnd=previewForPopup.planned_end_date||'';let lineStart=previewForPopup.proposed_lines?.[index]?.planned_start_date||globalStart,lineEnd=previewForPopup.proposed_lines?.[index]?.planned_end_date||globalEnd;if(globalStart&&globalEnd&&(lineStart<globalStart||lineStart>globalEnd))lineStart=globalStart;if(globalStart&&globalEnd&&(lineEnd<globalStart||lineEnd>globalEnd||lineEnd<lineStart))lineEnd=globalEnd;
            });
            const syncLineLimits=()=>{const start=approvalContent.querySelector('[data-feb-edit-start]')?.value||'',end=approvalContent.querySelector('[data-feb-edit-end]')?.value||'';container.querySelectorAll('[data-line-start]').forEach(input=>{input.min=start;input.max=end;if(start&&input.value<start)input.value=start;if(end&&input.value>end)input.value=end;});container.querySelectorAll('[data-line-end]').forEach(input=>{const i=input.dataset.lineEnd,lineStart=container.querySelector(`[data-line-start="${i}"]`)?.value||start;input.min=lineStart||start;input.max=end;if(lineStart&&input.value<lineStart)input.value=lineStart;if(end&&input.value>end)input.value=end;});};
            approvalContent.querySelector('[data-feb-edit-start]')?.addEventListener('change',syncLineLimits);approvalContent.querySelector('[data-feb-edit-end]')?.addEventListener('change',syncLineLimits);
            const note=document.createElement('div');note.className='mt-3 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-xs font-semibold text-emerald-900';note.textContent='Après intégration, la première ligne créée ci-dessus sera automatiquement utilisée comme poste financier de la fiche de décaissement.';container.insertAdjacentElement('afterend',note);
        };
        const copyPopupEditsToForm = () => {
            const date = approvalContent.querySelector('[data-feb-scheduled-date]')?.value || '';
            setPendingValue('fd_date_decaissement_minimum', date);
            setPendingValue('treasury_funding_source',approvalContent.querySelector('[name="popup_treasury_funding_source"]:checked')?.value||'');
            setPendingValue('treasury_funding_invoice_id',approvalContent.querySelector('[data-funding-invoice]')?.value||'');
            setPendingValue('approve_current_values', approvalContent.querySelector('[data-feb-approve-current]')?.checked ? '1' : '');
            const adjustExpired=approvalContent.querySelector('[data-adjust-expired-planning]')?.checked===true;
            setPendingValue('adjust_expired_planning',adjustExpired?'1':'');
            if(adjustExpired){
                setPendingValue('expired_planning_start',approvalContent.querySelector('[data-expired-start]')?.value||'');
                setPendingValue('expired_planning_end',approvalContent.querySelector('[data-expired-end]')?.value||'');
                setPendingValue('confirm_expired_planning',approvalContent.querySelector('[data-confirm-expired-planning]')?.checked?'1':'');
            }
            const popupAmount = approvalContent.querySelector('[data-feb-edit-amount]')?.value || '';
            if (popupAmount !== '') {
                setPendingValue('montant', popupAmount);
                setPendingValue('fd_montant_fiche', popupAmount);
            }
            const editFields = approvalContent.querySelector('[data-feb-edit-fields]');
            if (editFields && !editFields.classList.contains('hidden')) {
                setPendingValue('description', approvalContent.querySelector('[data-feb-edit-description]')?.value || '');

                setPendingValue('date_livraison', approvalContent.querySelector('[data-feb-edit-delivery]')?.value || '');
                setPendingValue('frequence', approvalContent.querySelector('[data-feb-edit-frequency]')?.value || '');
                setPendingValue('montant', approvalContent.querySelector('[data-feb-edit-amount]')?.value || '');
                setPendingValue('fd_montant_fiche', approvalContent.querySelector('[data-feb-edit-amount]')?.value || '');
                setPendingValue('fd_details_demande', approvalContent.querySelector('[data-feb-edit-description]')?.value || '');
                setPendingValue('planned_start_date', approvalContent.querySelector('[data-feb-edit-start]')?.value || '');
                setPendingValue('planned_end_date', approvalContent.querySelector('[data-feb-edit-end]')?.value || '');
                setPendingValue('generate_decaissement', '1');
                ['beneficiary_mode','personnel_id','fournisseur_id','chantier_id','ligne_devis_id','debourse_id','ligne_debourse_id','mode_paiement'].forEach(key => {
                    setPendingValue('fd_' + key, approvalContent.querySelector(`[data-feb-edit-${key.replaceAll('_','-')}]`)?.value || '');
                });
                const completionNames={enabled:'supplier_completion_enabled',name:'supplier_completion_name',phone:'supplier_completion_phone',email:'supplier_completion_email',address:'supplier_completion_address'};
                approvalContent.querySelectorAll('[data-supplier-completion]').forEach(input=>{const key=input.dataset.supplierCompletion;if(key==='photo'){if(input.files?.length){input.name='supplier_completion_photo';pendingApprovalForm.enctype='multipart/form-data';pendingApprovalForm.appendChild(input);}}else if(completionNames[key])setPendingValue(completionNames[key],input.value||'');});
                if(previewForPopup?.can_integrate_debourse&&approvalContent.querySelector('[data-feb-new-lines]')){
                    const chantierId=approvalContent.querySelector('[data-feb-edit-chantier-id]')?.value||'';
                    const chantier=(approvalSelectors.chantiers||[]).find(item=>String(item.id)===String(chantierId));
                    setPendingValue('feb_integration_config','1');setPendingValue('feb_target_chantier_id',chantierId);setPendingValue('feb_target_devis_id',chantier?.devis_id||'');
                    setPendingValue('feb_ligne_devis_mode',approvalContent.querySelector('input[name="popup_line_mode"]:checked')?.value||'new');
                    setPendingValue('feb_target_ligne_devis_id',approvalContent.querySelector('[data-feb-edit-ligne-devis-id]')?.value||'');
                    setPendingValue('feb_new_ligne_devis_label',approvalContent.querySelector('[data-feb-new-line-label]')?.value||'');
                    setPendingValue('feb_debourse_mode',approvalContent.querySelector('input[name="popup_debourse_mode"]:checked')?.value||'new');
                    setPendingValue('feb_target_debourse_id',approvalContent.querySelector('[data-feb-edit-debourse-id]')?.value||'');
                    setPendingValue('feb_debourse_title',approvalContent.querySelector('[data-feb-new-debourse-title]')?.value||'');
                    approvalContent.querySelectorAll('[data-line-designation]').forEach(input=>{const i=input.dataset.lineDesignation;setPendingValue(`feb_debourse_lines[${i}][designation]`,input.value||'');setPendingValue(`feb_debourse_lines[${i}][categorie]`,approvalContent.querySelector(`[data-line-category="${i}"]`)?.value||'');setPendingValue(`feb_debourse_lines[${i}][montant]`,approvalContent.querySelector(`[data-line-amount="${i}"]`)?.value||'');setPendingValue(`feb_debourse_lines[${i}][planned_start_date]`,approvalContent.querySelector('[data-feb-edit-start]')?.value||'');setPendingValue(`feb_debourse_lines[${i}][planned_end_date]`,approvalContent.querySelector('[data-feb-edit-end]')?.value||'');});
                }
            }
        };
        document.querySelectorAll('.js-feb-approve-form').forEach(form => form.addEventListener('submit', event => {
            event.preventDefault();
            let preview = {};
            try { preview = JSON.parse(form.dataset.approvalPreview || '{}'); } catch (_) {}
            if (preview.rh_financial_signed === true) {
                const url=new URL('validation_demandes_finances.php',window.location.href);
                url.searchParams.set('id',String(preview.feb_id));url.searchParams.set('screen','detail');
                url.searchParams.set('tab',form.querySelector('[name="tab"]')?.value||'mine_pending');
                window.location.assign(url.toString());return;
            }
            previewForPopup = preview;
            pendingApprovalForm = form;
            const creates = preview.will_create_fiche === true;
            approvalReturn?.classList.toggle('hidden', creates);
            approvalIntegrate?.classList.toggle('hidden', !preview.can_integrate_debourse);
            approvalContent.innerHTML = `
                <div class="grid gap-4 md:grid-cols-2">
                    <div class="rounded-2xl border ${creates?'border-emerald-200 bg-emerald-50':'border-amber-200 bg-amber-50'} p-5 md:col-span-2">
                        <div class="text-xs font-black uppercase tracking-wider ${creates?'text-emerald-700':'text-amber-700'}">Fiche de décaissement</div>
                        <div class="mt-2 text-xl font-black text-slate-950">${creates?'Une fiche sera générée automatiquement':'Aucune fiche ne sera générée à cette étape'}</div>
                    </div>
                    <div class="rounded-2xl border border-indigo-200 bg-indigo-50 p-5"><label class="block text-xs font-black uppercase text-indigo-700">Montant de la FEB / fiche<input type="number" min="0.01" step="0.01" data-feb-edit-amount value="${Number(preview.amount||0)}" class="mt-3 block w-full rounded-xl border border-indigo-200 bg-white px-4 py-3 text-2xl font-black text-indigo-950"></label><p class="mt-2 text-xs text-indigo-800">Modifiable directement avant confirmation. La couverture de trésorerie est recalculée immédiatement.</p></div>
                    <div class="rounded-2xl border border-slate-200 p-5"><div class="text-xs font-bold uppercase text-slate-500">Bénéficiaire</div><div class="mt-2 text-lg font-black text-slate-900">${esc(preview.beneficiary||'—')}</div></div>
                    <div data-popup-supplier-card class="rounded-2xl border border-amber-200 bg-amber-50 p-5 md:col-span-2"></div>
                    <div class="rounded-2xl border border-indigo-200 bg-indigo-50 p-5 md:col-span-2"><label class="text-xs font-black uppercase tracking-wider text-indigo-700">Date prévue de décaissement — fixée par la couverture</label><input type="date" readonly data-feb-scheduled-date value="${esc(preview.scheduled_date||'')}" class="mt-3 block w-full cursor-not-allowed rounded-xl border border-indigo-200 bg-slate-100 px-4 py-3 text-xl font-black text-indigo-950"><div class="mt-2 text-xs text-indigo-700">Une facture prévisionnelle remplacera automatiquement cette date par sa date d’encaissement. Cette date deviendra celle du BCI.</div></div>
                    ${preview.planned_end_date && preview.planned_end_date < new Date().toISOString().slice(0,10) ? `<div class="md:col-span-2 rounded-2xl border border-amber-300 bg-amber-50 p-5 text-sm text-amber-950"><div class="font-black">Période de travaux expirée</div><p class="mt-1">Période prévue au départ : du ${esc(preview.planned_start_date)} au ${esc(preview.planned_end_date)}. Vous pouvez approuver cette FEB avec ces dates ; elle reste dans le point en cours. Aucun déplacement du planning n’est automatique.</p>${preview.can_adjust_expired_planning?`<label class="mt-4 flex items-center gap-2 font-bold"><input type="checkbox" data-adjust-expired-planning> Ajuster la période du déboursé lors de cette approbation</label><div data-expired-options class="mt-4 hidden space-y-3"><div class="grid gap-3 sm:grid-cols-2"><label>Nouvelle date de début<input type="date" data-expired-start value="${esc(new Date().toISOString().slice(0,10))}" class="mt-1 block w-full rounded-lg border border-amber-300 p-2"></label><label>Nouvelle date de fin<input type="date" data-expired-end value="${esc(new Date().toISOString().slice(0,10))}" class="mt-1 block w-full rounded-lg border border-amber-300 p-2"></label></div><div data-expired-impact class="rounded-lg bg-white p-3"></div><label class="flex items-start gap-2 font-semibold"><input type="checkbox" data-confirm-expired-planning class="mt-1"> Je confirme ces nouvelles dates et, si nécessaire, l’extension des dates du chantier sous réserve de ma validation.</label></div>`:'<p class="mt-3 font-semibold">Le droit de modifier les dates des travaux est nécessaire pour ajuster le planning.</p>'}</div>`:''}
                    <div class="rounded-2xl border border-emerald-200 bg-white p-5 md:col-span-2" data-funding-coverage><div class="text-xs font-black uppercase tracking-wider text-emerald-700">Source des fonds obligatoire</div><p class="mt-1 text-xs text-slate-500">La réservation garantit la couverture sans diminuer le solde. Elle disparaît au rythme des montants réellement décaissés.</p><div class="mt-4 grid gap-2 sm:grid-cols-2"><label class="cursor-pointer rounded-xl border border-emerald-200 p-3 text-sm font-bold"><input type="radio" name="popup_treasury_funding_source" value="real_entries" class="mr-2">Entrées réelles <small class="mt-1 block text-emerald-700">${Number(preview.treasury_real_coverage?.remaining||0).toLocaleString('fr-FR')} FCFA non réservés</small></label><label class="cursor-pointer rounded-xl border border-sky-200 p-3 text-sm font-bold"><input type="radio" name="popup_treasury_funding_source" value="forecast_invoice" class="mr-2">Entrées prévisionnelles <small class="mt-1 block text-sky-700">Choisir une facture à venir</small></label></div><div data-funding-invoice-wrap class="mt-3 hidden"><label class="text-xs font-bold text-slate-600">Facture couvrant la FEB<select data-funding-invoice class="mt-1 block w-full rounded-xl border border-sky-200 bg-white px-3 py-3 text-sm"><option value="">Sélectionner une facture disponible…</option>${(preview.treasury_forecast_invoices||[]).filter(invoice=>Number(invoice.remaining||0)>0).map(invoice=>`<option value="${invoice.invoice_id}" data-date="${esc(invoice.collection_date||'')}" data-remaining="${Number(invoice.remaining||0)}">${esc(invoice.reference)} · ${esc(invoice.client)} · ${Number(invoice.remaining||0).toLocaleString('fr-FR')} FCFA disponibles sur ${Number(invoice.amount||0).toLocaleString('fr-FR')} · ${esc(invoice.collection_date||'')}</option>`).join('')}</select></label><div data-funding-balance class="mt-2 text-xs font-semibold text-sky-800"></div></div><div data-funding-error class="mt-3 hidden rounded-lg bg-rose-50 px-3 py-2 text-xs font-bold text-rose-700"></div></div>
                    <div class="rounded-2xl border border-slate-200 p-5"><div class="text-xs font-bold uppercase text-slate-500">Statut initial</div><div class="mt-2 font-bold text-slate-900">${esc(preview.conformity)}<br>${esc(preview.purchase_validation)}</div></div>
                    <div class="rounded-2xl border border-slate-200 p-5"><div class="text-xs font-bold uppercase text-slate-500">Bon de commande interne</div><div class="mt-2 font-bold text-slate-900">${preview.will_create_bci?'Un bon de commande sera généré pour la date prévue ('+esc(preview.scheduled_date_label||'à déterminer')+').':'Aucun bon de commande ne sera généré à cette étape.'}</div></div>
                </div>
                ${!creates?`
                <div class="mt-5 rounded-2xl border border-rose-200 bg-rose-50 p-5 text-sm leading-6 text-rose-950">
                    <div class="font-black">Pourquoi aucune fiche ne sera générée ?</div>
                    <ul class="mt-2 list-disc space-y-1 pl-5">${(Array.isArray(preview.no_fiche_reasons)&&preview.no_fiche_reasons.length?preview.no_fiche_reasons:['La génération automatique de la fiche n’est pas configurée.']).map(reason=>'<li>'+esc(reason)+'</li>').join('')}</ul>
                    <label class="mt-4 block"><span class="text-xs font-black uppercase tracking-wider text-rose-800">Message envoyé au soumissionnaire de la FEB</span>
                        <textarea data-feb-return-note rows="4" class="mt-2 w-full rounded-xl border border-rose-200 bg-white px-4 py-3 text-slate-800">${esc(preview.return_note||'Merci de compléter les informations nécessaires à la génération de la fiche.')}</textarea>
                    </label>
                </div>`:''}
                ${preview.devis_pending?`
                <div class="mt-5 rounded-2xl border border-amber-300 bg-amber-50 p-5 text-sm leading-6 text-amber-950">
                    <div class="font-black">Devis cible à confirmer avant une approbation sûre</div>
                    <p class="mt-1">Cela signifie qu’aucun devis existant n’est encore clairement rattaché à cette FEB. Ouvrez son analyse détaillée, sélectionnez le devis principal ou le devis TS concerné, puis vérifiez la ligne de devis.</p>
                    <a href="${esc(preview.detail_url||'#')}" class="mt-3 inline-flex rounded-xl bg-amber-600 px-4 py-2 font-bold text-white">Ouvrir l’analyse et confirmer le devis</a>
                </div>`:''}
                <div class="mt-5 rounded-2xl border border-violet-200 bg-violet-50 p-5 text-sm text-violet-950">
                    <label class="flex cursor-pointer items-start gap-3">
                        <input type="checkbox" data-feb-approve-current class="mt-1 h-5 w-5 rounded border-violet-300 text-violet-700">
                        <span>
                            <strong class="block text-base">Approuver sans proposition contradictoire</strong>
                            <span class="mt-1 block leading-6">Le montant final et le bénéficiaire enregistrés seront repris dans le BCI et la fiche de décaissement. Les anciennes valeurs restent dans l’historique. Les consultations non retenues seront clôturées.</span>
                            <span class="mt-2 block text-xs font-bold text-violet-700">${Number(preview.challenge_count||0)} proposition(s) contradictoire(s) actuellement reçue(s).</span>
                        </span>
                    </label>
                </div>
                <div class="mt-5 rounded-2xl border border-slate-200 bg-white p-5">
                    <button type="button" data-feb-toggle-edit class="flex w-full items-center justify-between text-left font-black text-slate-950"><span>Modifier d’autres détails avant la décision</span><span class="text-indigo-600">Activer l’édition</span></button>
                    <div data-feb-edit-fields class="mt-4 hidden grid gap-4 md:grid-cols-2">
                        <label class="md:col-span-2 text-xs font-black uppercase text-slate-500">Description<textarea rows="3" data-feb-edit-description class="mt-2 block w-full rounded-xl border border-slate-200 px-4 py-3 text-sm normal-case text-slate-800">${esc(preview.description||'')}</textarea></label>

                        <label class="text-xs font-black uppercase text-slate-500">Date de livraison<input type="date" data-feb-edit-delivery value="${esc(preview.delivery_date||'')}" class="mt-2 block w-full rounded-xl border border-slate-200 px-4 py-3 text-sm"></label>
                        <label class="text-xs font-black uppercase text-slate-500">Fréquence<input data-feb-edit-frequency value="${esc(preview.frequency||'')}" class="mt-2 block w-full rounded-xl border border-slate-200 px-4 py-3 text-sm"></label>
                        <label class="text-xs font-black uppercase text-slate-500">Début période globale<input type="date" data-feb-edit-start value="${esc(preview.planned_start_date||preview.scheduled_date||'')}" class="mt-2 block w-full rounded-xl border border-slate-200 px-4 py-3 text-sm"></label>
                        <label class="text-xs font-black uppercase text-slate-500">Fin période globale<input type="date" data-feb-edit-end value="${esc(preview.planned_end_date||preview.scheduled_date||'')}" class="mt-2 block w-full rounded-xl border border-slate-200 px-4 py-3 text-sm"></label>
                        ${preview.can_integrate_debourse?`<div class="md:col-span-2 rounded-2xl border border-amber-300 bg-amber-50 p-4 normal-case"><div class="font-black text-amber-950">Créer ou rattacher le budget de cette FEB</div><p class="mt-1 text-xs text-amber-800">Vous pouvez créer une nouvelle ligne de devis et un nouveau déboursé. La période globale ci-dessus deviendra la période du déboursé et de ses postes.</p><div class="mt-4 grid gap-4 md:grid-cols-2"><div><div class="text-xs font-black uppercase text-slate-600">Ligne de devis</div><div class="mt-2 flex gap-4 text-sm"><label><input type="radio" name="popup_line_mode" value="new" checked> Créer une nouvelle ligne</label><label><input type="radio" name="popup_line_mode" value="existing"> Utiliser une ligne existante</label></div><input data-feb-new-line-label value="${esc(preview.proposed_ligne_devis_label||preview.proposed_debourse_title||'')}" placeholder="Libellé de la nouvelle ligne" class="mt-2 block w-full rounded-xl border border-amber-200 bg-white px-4 py-3 text-sm"><div data-existing-line-slot class="mt-3 hidden rounded-xl border border-amber-200 bg-white p-3"><div class="mb-2 text-xs font-bold uppercase text-amber-800">Ligne existante retenue — recherchez pour modifier</div></div></div><div><div class="text-xs font-black uppercase text-slate-600">Déboursé</div><div class="mt-2 flex gap-4 text-sm"><label><input type="radio" name="popup_debourse_mode" value="new" checked> Créer un nouveau déboursé</label><label><input type="radio" name="popup_debourse_mode" value="existing"> Utiliser un déboursé existant</label></div><input data-feb-new-debourse-title value="${esc(preview.proposed_debourse_title||'')}" placeholder="Titre du nouveau déboursé" class="mt-2 block w-full rounded-xl border border-amber-200 bg-white px-4 py-3 text-sm"><div data-existing-debourse-slot class="mt-3 hidden rounded-xl border border-amber-200 bg-white p-3"><div class="mb-2 text-xs font-bold uppercase text-amber-800">Déboursé existant retenu — recherchez pour modifier</div></div></div></div><div class="mt-4 text-xs font-black uppercase text-slate-600">Postes, montants et catégories</div><div data-feb-new-lines class="mt-2 grid gap-2">${(Array.isArray(preview.proposed_lines)&&preview.proposed_lines.length?preview.proposed_lines:[{designation:preview.details||preview.description||'',categorie:'Service',montant:preview.amount||0}]).map((line,index)=>'<div class="grid gap-2 md:grid-cols-[1fr_180px_180px]"><input data-line-designation="'+index+'" value="'+esc(line.designation||'')+'" placeholder="Désignation" class="rounded-xl border border-amber-200 bg-white px-3 py-2 text-sm"><input data-line-category="'+index+'" value="'+esc(line.categorie||'Service')+'" placeholder="Catégorie" class="rounded-xl border border-amber-200 bg-white px-3 py-2 text-sm"><input type="number" min="1" step="0.01" data-line-amount="'+index+'" value="'+Number(line.montant||0)+'" class="rounded-xl border border-amber-200 bg-white px-3 py-2 text-sm"></div>').join('')}</div></div>`:''}
                        <div class="md:col-span-2 mt-2 border-t border-slate-200 pt-4"><div class="font-black normal-case text-slate-900">Données complètes de la fiche de décaissement</div><div class="mt-1 text-xs normal-case text-slate-500">Pour un déboursé non prévu, le déboursé et sa ligne seront remplacés automatiquement par les identifiants créés lors de l’intégration.</div></div>
                        <label class="text-xs font-black uppercase text-slate-500">Type bénéficiaire<select data-feb-edit-beneficiary-mode class="mt-2 block w-full rounded-xl border border-slate-200 px-4 py-3 text-sm normal-case"><option value="personnel" ${preview.beneficiary_mode==='personnel'?'selected':''}>Personnel</option><option value="supplier" ${preview.beneficiary_mode==='supplier'?'selected':''}>Fournisseur</option><option value="external" ${preview.beneficiary_mode==='external'?'selected':''}>Externe</option></select></label>
                        <label class="text-xs font-black uppercase text-slate-500">Personnel bénéficiaire<div data-smart-select="personnel" class="relative mt-2 normal-case"><input type="hidden" data-smart-value data-feb-edit-personnel-id value="${Number(preview.personnel_id||0)||''}"><input data-smart-search autocomplete="off" placeholder="Rechercher le personnel…" class="block w-full rounded-xl border border-slate-200 px-4 py-3 text-sm"><div data-smart-results class="absolute z-30 mt-1 hidden max-h-72 w-full overflow-y-auto rounded-xl border border-slate-200 bg-white shadow-xl"></div><div data-smart-preview class="mt-2"></div></div></label>
                        <label class="text-xs font-black uppercase text-slate-500">Fournisseur validé<div data-smart-select="supplier" class="relative mt-2 normal-case"><input type="hidden" data-smart-value data-feb-edit-fournisseur-id value="${Number(preview.fournisseur_id||0)||''}"><input data-smart-search autocomplete="off" placeholder="Rechercher le fournisseur…" class="block w-full rounded-xl border border-slate-200 px-4 py-3 text-sm"><div data-smart-results class="absolute z-30 mt-1 hidden max-h-72 w-full overflow-y-auto rounded-xl border border-slate-200 bg-white shadow-xl"></div><div data-smart-preview class="mt-2"></div></div></label>
                        <label class="text-xs font-black uppercase text-slate-500">Mode de paiement<select data-feb-edit-mode-paiement class="mt-2 block w-full rounded-xl border border-slate-200 px-4 py-3 text-sm normal-case"><option value="">Sélectionner</option><option value="Cash" ${preview.mode_paiement==='Cash'?'selected':''}>Cash</option><option value="Orange Money" ${preview.mode_paiement==='Orange Money'?'selected':''}>Orange Money</option><option value="MTN Money" ${preview.mode_paiement==='MTN Money'?'selected':''}>MTN Money</option><option value="Moov Money" ${preview.mode_paiement==='Moov Money'?'selected':''}>Moov Money</option><option value="Wave" ${preview.mode_paiement==='Wave'?'selected':''}>Wave</option><option value="Djamo" ${preview.mode_paiement==='Djamo'?'selected':''}>Djamo</option><option value="Chèque" ${preview.mode_paiement==='Chèque'?'selected':''}>Chèque</option><option value="Virement bancaire" ${preview.mode_paiement==='Virement bancaire'?'selected':''}>Virement bancaire</option><option value="Carte bancaire" ${preview.mode_paiement==='Carte bancaire'?'selected':''}>Carte bancaire</option><option value="Espèces" ${preview.mode_paiement==='Espèces'?'selected':''}>Espèces</option><option value="Mobile Money" ${preview.mode_paiement==='Mobile Money'?'selected':''}>Mobile Money</option><option value="Virement" ${preview.mode_paiement==='Virement'?'selected':''}>Virement</option></select></label>
                        <label class="text-xs font-black uppercase text-slate-500">Chantier<div data-smart-select="chantier" class="relative mt-2 normal-case"><input type="hidden" data-smart-value data-feb-edit-chantier-id value="${Number(preview.chantier_id||0)||''}"><input data-smart-search autocomplete="off" placeholder="Rechercher le chantier…" class="block w-full rounded-xl border border-slate-200 px-4 py-3 text-sm"><div data-smart-results class="absolute z-30 mt-1 hidden max-h-72 w-full overflow-y-auto rounded-xl border border-slate-200 bg-white shadow-xl"></div><div data-smart-preview class="mt-2"></div></div></label>
                        <label class="text-xs font-black uppercase text-slate-500">Ligne de devis<div data-smart-select="devis_line" class="relative mt-2 normal-case"><input type="hidden" data-smart-value data-feb-edit-ligne-devis-id value="${Number(preview.ligne_devis_id||0)||''}"><input data-smart-search autocomplete="off" placeholder="Rechercher la ligne…" class="block w-full rounded-xl border border-slate-200 px-4 py-3 text-sm"><div data-smart-results class="absolute z-30 mt-1 hidden max-h-72 w-full overflow-y-auto rounded-xl border border-slate-200 bg-white shadow-xl"></div><div data-smart-preview class="mt-2"></div></div></label>
                        <label class="text-xs font-black uppercase text-slate-500">Déboursé existant<div data-smart-select="debourse" class="relative mt-2 normal-case"><input type="hidden" data-smart-value data-feb-edit-debourse-id value="${Number(preview.debourse_id||0)||''}"><input data-smart-search autocomplete="off" placeholder="Rechercher le déboursé…" class="block w-full rounded-xl border border-slate-200 px-4 py-3 text-sm"><div data-smart-results class="absolute z-30 mt-1 hidden max-h-72 w-full overflow-y-auto rounded-xl border border-slate-200 bg-white shadow-xl"></div><div data-smart-preview class="mt-2"></div></div></label>
                        <label class="text-xs font-black uppercase text-slate-500">Poste / ligne de déboursé<div data-smart-select="debourse_line" class="relative mt-2 normal-case"><input type="hidden" data-smart-value data-feb-edit-ligne-debourse-id value="${Number(preview.ligne_debourse_id||0)||''}"><input data-smart-search autocomplete="off" placeholder="Rechercher le poste financier…" class="block w-full rounded-xl border border-slate-200 px-4 py-3 text-sm"><div data-smart-results class="absolute z-30 mt-1 hidden max-h-72 w-full overflow-y-auto rounded-xl border border-slate-200 bg-white shadow-xl"></div><div data-smart-preview class="mt-2"></div></div></label>
                        <div class="rounded-xl bg-indigo-50 p-4 text-xs leading-5 text-indigo-900">L’ancienne version sera conservée. Le soumissionnaire recevra une notification détaillant les changements effectués.</div>
                    </div>
                </div>
                <div class="mt-5 rounded-2xl border border-slate-200 bg-slate-50 p-5 text-sm leading-7 text-slate-700">
                    <div class="mb-2 font-black text-slate-950">Résumé de l’ordre</div>
                    Vous allez ${creates?'ordonner la génération d’une fiche de décaissement':'approuver la FEB sans génération immédiate de fiche'} de <strong>${esc(preview.amount_label)}</strong>,
                    pour le chantier <strong>${esc(preview.chantier)}</strong>.
                    <div class="mt-4 grid gap-3 md:grid-cols-2">
                        <div><span class="block text-xs font-bold uppercase text-slate-500">Devis concerné</span><strong>${esc(preview.devis)}</strong></div>
                        <div><span class="block text-xs font-bold uppercase text-slate-500">Ligne de devis</span><strong>${esc(preview.ligne_devis)}</strong></div>
                        <div><span class="block text-xs font-bold uppercase text-slate-500">Déboursé</span><strong>${esc(preview.debourse)}</strong></div>
                        <div><span class="block text-xs font-bold uppercase text-slate-500">Ligne de déboursé</span><strong>${esc(preview.ligne_debourse)}</strong></div>
                    </div>
                    <div class="mt-4 border-t border-slate-200 pt-3"><strong>Détail du besoin :</strong> ${esc(preview.details||'—')}</div>
                </div>`;
            const supplierCard=approvalContent.querySelector('[data-popup-supplier-card]'),supplier=(approvalSelectors.suppliers||[]).find(item=>String(item.id)===String(preview.fournisseur_id||''));
            if(supplierCard){
                const supplierName=supplier?.nom||'Fournisseur non renseigné',supplierPhoto=supplier?.photo||'',supplierMeta=[supplier?.contact,supplier?.telephone,supplier?.email,supplier?.adresse].filter(Boolean);
                supplierCard.innerHTML=`<div class="text-xs font-black uppercase tracking-wider text-amber-800">Fournisseur de la FEB</div><div class="mt-3 flex flex-wrap items-center gap-4">${supplierPhoto?`<img src="${esc(supplierPhoto)}" alt="Photo de ${esc(supplierName)}" class="h-20 w-20 rounded-2xl border border-amber-200 bg-white object-cover" onerror="this.style.display='none'">`:`<span class="flex h-20 w-20 items-center justify-center rounded-2xl bg-amber-200 text-2xl font-black text-amber-900">${esc((supplierName||'?').charAt(0))}</span>`}<div class="min-w-0 flex-1"><strong class="block text-lg text-slate-950">${esc(supplierName)}</strong>${supplierMeta.length?`<div class="mt-1 space-y-1 text-sm text-slate-700">${supplierMeta.map(value=>`<div>${esc(value)}</div>`).join('')}</div>`:'<p class="mt-1 text-sm text-amber-900">Aucun fournisseur validé n’est encore associé à cette FEB. Utilisez « Modifier d’autres détails » pour le sélectionner.</p>'}</div></div>`;
            }
            if(supplierCard){
                const summary=document.createElement('p');summary.dataset.popupSupplierSelection='';summary.className='mt-3 text-sm font-semibold text-indigo-900';summary.setAttribute('aria-live','polite');supplierCard.appendChild(summary);
                const change=document.createElement('button');change.type='button';change.className='mt-3 rounded-xl border border-indigo-300 bg-white px-4 py-2 text-sm font-bold text-indigo-900';change.textContent='Choisir / changer le fournisseur';
                change.addEventListener('click',()=>{
                    const fields=approvalContent.querySelector('[data-feb-edit-fields]');if(fields?.classList.contains('hidden'))approvalContent.querySelector('[data-feb-toggle-edit]')?.click();
                    const search=approvalContent.querySelector('[data-smart-select="supplier"] [data-smart-search]');if(search){search.scrollIntoView({behavior:'smooth',block:'center'});search.focus();search.select();}
                });supplierCard.appendChild(change);
            }
            const syncTreasuryApprovalControl=()=>{const source=approvalContent.querySelector('[name="popup_treasury_funding_source"]:checked')?.value||'',invoice=approvalContent.querySelector('[data-funding-invoice]'),amount=Number(approvalContent.querySelector('[data-feb-edit-amount]')?.value||preview.amount||0),realRemaining=Number(preview.treasury_real_coverage?.remaining||0),option=invoice?.selectedOptions?.[0],invoiceRemaining=Number(option?.dataset?.remaining||0),blocked=!source||(source==='real_entries'&&amount>realRemaining)||(source==='forecast_invoice'&&(!invoice?.value||amount>invoiceRemaining)),error=approvalContent.querySelector('[data-funding-error]');if(error){error.classList.toggle('hidden',!blocked);error.textContent=!source?'Choisissez la source des fonds.':source==='forecast_invoice'&&!invoice?.value?'Choisissez une facture.':'Le montant non réservé de cette source est insuffisant.';}if(approvalConfirm){approvalConfirm.disabled=blocked;approvalConfirm.classList.toggle('opacity-40',blocked);approvalConfirm.classList.toggle('cursor-not-allowed',blocked);approvalConfirm.title=blocked?'Une couverture non réservée suffisante est obligatoire.':'';approvalConfirm.textContent=blocked?'Couverture à compléter':'Confirmer l’approbation';}};
            const syncScheduledDateWithPlanning=()=>{syncTreasuryApprovalControl();};
            approvalContent.querySelector('[data-feb-scheduled-date]')?.addEventListener('change',syncScheduledDateWithPlanning);
            const expiredToggle=approvalContent.querySelector('[data-adjust-expired-planning]');
            const updateExpiredImpact=()=>{
                const options=approvalContent.querySelector('[data-expired-options]');options?.classList.toggle('hidden',!expiredToggle?.checked);
                const impact=approvalContent.querySelector('[data-expired-impact]');if(!impact)return;
                const start=approvalContent.querySelector('[data-expired-start]')?.value||'',end=approvalContent.querySelector('[data-expired-end]')?.value||'';
                const oldStart=preview.chantier_start_date||'',oldEnd=preview.chantier_end_date||'';
                const newStart=oldStart&&start?([oldStart,start].sort()[0]):(oldStart||start);
                const newEnd=oldEnd&&end?([oldEnd,end].sort()[1]):(oldEnd||end);
                impact.textContent=start&&end&&end>=start?`Déboursé : ${preview.planned_start_date} → ${start} (début), ${preview.planned_end_date} → ${end} (fin). Chantier : ${oldStart||'non définie'} → ${newStart||'non définie'} (début), ${oldEnd||'non définie'} → ${newEnd||'non définie'} (fin). ${newStart!==oldStart||newEnd!==oldEnd?'Les bornes du chantier seront étendues seulement après votre confirmation.':'Les bornes du chantier restent identiques.'}`:'Choisissez une nouvelle période valide.';
            };
            expiredToggle?.addEventListener('change',updateExpiredImpact);
            approvalContent.querySelector('[data-expired-start]')?.addEventListener('change',updateExpiredImpact);
            approvalContent.querySelector('[data-expired-end]')?.addEventListener('change',updateExpiredImpact);
            updateExpiredImpact();
            approvalContent.querySelector('[data-feb-edit-amount]')?.addEventListener('input',syncTreasuryApprovalControl);
            approvalContent.querySelectorAll('[name="popup_treasury_funding_source"]').forEach(radio=>radio.addEventListener('change',()=>{const forecast=radio.checked&&radio.value==='forecast_invoice';approvalContent.querySelector('[data-funding-invoice-wrap]')?.classList.toggle('hidden',!forecast);if(!forecast){const select=approvalContent.querySelector('[data-funding-invoice]');if(select)select.value='';}syncTreasuryApprovalControl();}));
            approvalContent.querySelector('[data-funding-invoice]')?.addEventListener('change',event=>{const option=event.target.selectedOptions[0],date=option?.dataset?.date||preview.scheduled_date||'',remaining=Number(option?.dataset?.remaining||0),dateInput=approvalContent.querySelector('[data-feb-scheduled-date]'),balance=approvalContent.querySelector('[data-funding-balance]');if(dateInput)dateInput.value=date;if(balance)balance.textContent=event.target.value?`Solde encore réservable : ${remaining.toLocaleString('fr-FR')} FCFA · date imposée au BCI : ${date.split('-').reverse().join('/')}`:'';syncScheduledDateWithPlanning();syncTreasuryApprovalControl();});
            syncTreasuryApprovalControl();
            initializeApprovalSmartSelectors();
            setupIntegrationModeSelectors();
            enhanceIntegrationBudgetLines();
            approvalContent.querySelector('[data-feb-toggle-edit]')?.addEventListener('click', event => {
                const fields = approvalContent.querySelector('[data-feb-edit-fields]');
                fields?.classList.toggle('hidden');
                const label = event.currentTarget.querySelector('span:last-child');
                if (label) label.textContent = fields?.classList.contains('hidden') ? 'Activer l’édition' : 'Édition activée';
            });
            approvalModal.classList.remove('hidden');
            approvalModal.classList.add('flex');
            approvalModal.setAttribute('aria-hidden', 'false');
        }));
        document.querySelectorAll('.js-edit-feb-beneficiary').forEach(button=>button.addEventListener('click',()=>{
            const form=button.closest('.feb-list-card')?.querySelector('.js-feb-approve-form');if(!form)return;
            form.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));
            requestAnimationFrame(()=>{const toggle=approvalContent.querySelector('[data-feb-toggle-edit]'),fields=approvalContent.querySelector('[data-feb-edit-fields]');if(fields?.classList.contains('hidden'))toggle?.click();const target=approvalContent.querySelector('[data-feb-edit-beneficiary-mode]');target?.scrollIntoView({behavior:'smooth',block:'center'});setTimeout(()=>target?.focus(),250);});
        }));
        approvalConfirm?.addEventListener('click', () => {
            if (!pendingApprovalForm) return;
            if(approvalContent.querySelector('[data-adjust-expired-planning]')?.checked){
                const start=approvalContent.querySelector('[data-expired-start]')?.value||'',end=approvalContent.querySelector('[data-expired-end]')?.value||'';
                if(!start||!end||end<start){window.alert('Renseignez une nouvelle période valide pour le déboursé.');return;}
                if(!approvalContent.querySelector('[data-confirm-expired-planning]')?.checked){window.alert('Confirmez le nouveau planning et ses éventuelles nouvelles bornes de chantier.');return;}
            }
            copyPopupEditsToForm();
            approvalConfirm.disabled = true;
            approvalConfirm.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Transaction en cours…';
            approvalContent.innerHTML = '<div data-workflow-progress-host></div>';
            pendingApprovalForm.submit();
        });
        approvalSaveAmount?.addEventListener('click', () => {
            if (!pendingApprovalForm) return;
            copyPopupEditsToForm();
            setPendingValue('action', 'save_popup_adjustments');
            approvalSaveAmount.disabled = true;
            if(approvalConfirm)approvalConfirm.disabled=true;
            if(approvalIntegrate)approvalIntegrate.disabled=true;
            if(approvalReturn)approvalReturn.disabled=true;
            approvalSaveAmount.innerHTML='<i class="fas fa-spinner fa-spin mr-2"></i> Enregistrement…';
            approvalContent.innerHTML='<div data-workflow-progress-host></div>';
            pendingApprovalForm.submit();
        });
        approvalIntegrate?.addEventListener('click', () => {
            if (!pendingApprovalForm) return;
            copyPopupEditsToForm();
            setPendingValue('action', 'integrate_proposed_debourse');
            approvalIntegrate.disabled = true;
            approvalConfirm.disabled = true;
            approvalReturn.disabled = true;
            approvalIntegrate.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Intégration en cours…';
            approvalContent.innerHTML = '<div class="py-16 text-center"><div class="mx-auto h-14 w-14 animate-spin rounded-full border-4 border-indigo-100 border-t-indigo-600"></div><h3 class="mt-5 text-xl font-black text-slate-950">Création et rattachement du déboursé</h3><p class="mt-2 text-sm text-slate-500">La ligne de devis, le déboursé et ses lignes sont contrôlés dans une transaction unique.</p></div>';
            pendingApprovalForm.submit();
        });
        approvalReturn?.addEventListener('click', () => {
            if (!pendingApprovalForm) return;
            const actionInput = pendingApprovalForm.querySelector('input[name="action"]');
            const noteInput = pendingApprovalForm.querySelector('input[name="note"]');
            const returnNote = approvalContent.querySelector('[data-feb-return-note]')?.value?.trim() || 'Merci de compléter les informations nécessaires à la génération de la fiche de décaissement.';
            if (actionInput) actionInput.value = 'report';
            if (noteInput) noteInput.value = returnNote;
            approvalReturn.disabled = true;
            approvalConfirm.disabled = true;
            approvalReturn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Retour en cours…';
            approvalContent.innerHTML = '<div class="py-16 text-center"><div class="mx-auto h-14 w-14 animate-spin rounded-full border-4 border-amber-100 border-t-amber-600"></div><h3 class="mt-5 text-xl font-black text-slate-950">Retour au soumissionnaire en cours</h3><p class="mt-2 text-sm text-slate-500">Le motif sera enregistré dans le journal et envoyé par notification.</p></div>';
            pendingApprovalForm.submit();
        });
        const selectAll = document.getElementById('febSelectAll');
        const checks = Array.from(document.querySelectorAll('.feb-select'));
        const selectionSummary = document.getElementById('febSelectionSummary');
        const selectedCount = document.getElementById('febSelectedCount');
        const selectedAmount = document.getElementById('febSelectedAmount');
        const moneyFormatter = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0 });
        const updateSelectionSummary = () => {
            const selected = checks.filter(cb => cb.checked);
            const total = selected.reduce((sum, cb) => sum + (Number.parseFloat(cb.dataset.amount || '0') || 0), 0);
            if (selectedCount) selectedCount.textContent = `${selected.length} fiche${selected.length > 1 ? 's' : ''} sélectionnée${selected.length > 1 ? 's' : ''}`;
            if (selectedAmount) selectedAmount.textContent = `${moneyFormatter.format(total)} FCFA`;
            selectionSummary?.classList.toggle('hidden', selected.length === 0);
            if (selectAll) {
                selectAll.checked = checks.length > 0 && selected.length === checks.length;
                selectAll.indeterminate = selected.length > 0 && selected.length < checks.length;
            }
        };
        if (selectAll) {
            selectAll.addEventListener('change', function() {
                checks.forEach(cb => {
                    cb.checked = selectAll.checked;
                });
                updateSelectionSummary();
            });
        }
        const submitFebBulkAction=(action,date='')=>{const selected=checks.filter(cb=>cb.checked);if(!selected.length){alert('Sélectionnez au moins une FEB.');return;}const form=document.createElement('form');form.method='post';form.action=window.location.href;[['feb_workflow_csrf',<?= json_encode((string)$_SESSION['feb_workflow_csrf']) ?>],['action',action],['deferred_until',date],['tab','<?=htmlspecialchars($tab,ENT_QUOTES,'UTF-8')?>']].forEach(([name,value])=>{const input=document.createElement('input');input.type='hidden';input.name=name;input.value=value;form.appendChild(input);});selected.forEach(cb=>{const input=document.createElement('input');input.type='hidden';input.name='ids[]';input.value=cb.value;form.appendChild(input);});document.body.appendChild(form);form.submit();};
        document.getElementById('febBulkDefer')?.addEventListener('click',()=>{const selected=checks.filter(cb=>cb.checked),date=document.getElementById('febBulkDeferredUntil')?.value||'';if(!selected.length){alert('Sélectionnez au moins une FEB.');return;}if(!date){alert('Choisissez la date à laquelle les FEB devront réapparaître.');return;}if(confirm(`Envoyer ${selected.length} FEB au ${date.split('-').reverse().join('/')} ?`))submitFebBulkAction('bulk_defer',date);});
        document.getElementById('febBulkBringToday')?.addEventListener('click',()=>{const count=checks.filter(cb=>cb.checked).length;if(!count){alert('Sélectionnez au moins une FEB.');return;}if(confirm(`Ramener ${count} FEB dans les demandes à traiter aujourd’hui ?`))submitFebBulkAction('bulk_bring_today');});
        document.getElementById('febBulkReject')?.addEventListener('click',()=>{const count=checks.filter(cb=>cb.checked).length;if(!count){alert('Sélectionnez au moins une FEB.');return;}if(confirm(`Refuser définitivement ${count} FEB ? Toutes leurs préparations seront annulées automatiquement.`))submitFebBulkAction('bulk_reject');});
        checks.forEach(cb => cb.addEventListener('change', updateSelectionSummary));
        updateSelectionSummary();
        document.querySelectorAll('[data-export-selected]').forEach(function(button) {
            button.addEventListener('click', function() {
                const ids = checks.filter(cb => cb.checked).map(cb => cb.value).join(',');
                if (!ids) {
                    alert('Sélectionnez au moins une FEB.');
                    return;
                }
                const type = button.getAttribute('data-export-selected');
                const base = type === 'pdf' ? 'request/export_feb_recap_pdf.php' : 'request/export_feb_recap_excel.php';
                const url = new URL(base, window.location.href);
                url.searchParams.set('ids', ids);
                url.searchParams.set('tab', <?= json_encode($tab) ?>);
                <?php if ($includeLegacyFeb): ?>url.searchParams.set('archives', '1');
            <?php endif; ?>
            window.open(url.toString(), '_blank', 'noopener');
            });
        });
    });
</script>

<?php require_once __DIR__ . '/decaissement/includes/footer.php'; ?>





