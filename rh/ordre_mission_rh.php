<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../controllers/RhMissionController.php';
require_once __DIR__ . '/../controllers/RhPersonnelController.php';
require_once __DIR__ . '/../services/MissionOrderPdfService.php';
require_once __DIR__ . '/../model/CompanyBranding.php';
require_once __DIR__ . '/../services/PasskeySignatureGuard.php';
require_once __DIR__ . '/includes/personnel_helpers.php';

$missionId = max(0, (int) ($_GET['id'] ?? 0));

$pdo = \Database::getConnection();
$directory = (new \RhPersonnelController($pdo))->service();
$rhMissionController = new \RhMissionController($pdo);
$missionManager = $rhMissionController->missions();
$statusLabels = $missionManager->getStatusLabels();
$financialChoices = $missionManager->financialChoices();
$supplierChoices = $missionManager->activeSuppliers();
$userChoices = $missionManager->activeUsers();
$branding = CompanyBranding::get($pdo);

$actor = [
    'user_id' => isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
    'user_name' => isset($_SESSION['user_name']) ? trim((string) $_SESSION['user_name']) : null,
];

$auditMission = static function (string $eventType, array $context = []) use (&$missionId, $actor): void {
    \AuditTrail::log($eventType, array_merge([
        'module' => 'rh',
        'entity_type' => 'mission_order',
        'entity_id' => $missionId > 0 ? (string) $missionId : null,
        'user_id' => $actor['user_id'],
        'user_name' => $actor['user_name'],
    ], $context));
};

$statusType = null;
$statusMessage = null;
$failedFormInput = [];
$isAjaxRequest = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH']??'')) === 'xmlhttprequest';

$mission = $missionId > 0 ? $missionManager->getMission($missionId) : null;
$events = $missionId > 0 ? $missionManager->getMissionEvents($missionId) : [];
$expenses = $missionId > 0 ? $missionManager->getMissionExpenses($missionId) : [];

$personnelChoices = $directory->getPersonnelChoices('', 2000);

$chantierModel = $rhMissionController->chantiers();
try {
    $chantiers = $chantierModel->getAllActive();
} catch (Throwable $e) {
    $chantiers = $chantierModel->getAll();
}

function parseExpenseLines(array $post): array
{
    $designations = $post['expense_designation'] ?? [];
    $units = $post['expense_unit'] ?? [];
    $quantities = $post['expense_quantity'] ?? [];
    $unitPrices = $post['expense_unit_price'] ?? [];

    if (!is_array($designations) || !is_array($units) || !is_array($quantities) || !is_array($unitPrices)) {
        return [];
    }

    $lines = [];
    $max = max(count($designations), count($units), count($quantities), count($unitPrices));
    for ($i = 0; $i < $max; $i++) {
        $lines[] = [
            'designation' => (string) ($designations[$i] ?? ''),
            'unit' => (string) ($units[$i] ?? ''),
            'quantity' => (string) ($quantities[$i] ?? ''),
            'unit_price' => (string) ($unitPrices[$i] ?? ''),
        ];
    }

    return $lines;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = trim((string) ($_POST['form_action'] ?? 'save'));
        $signsDocument = in_array($action, ['approve', 'sign_employee'], true)
            || ($action === 'save' && ((string)($_POST['drh_signature_confirmed'] ?? '') === '1' || (string)($_POST['employee_signature_choice'] ?? 'none') === 'po'));
        if ($signsDocument) PasskeySignatureGuard::requireVerified((int)($actor['user_id'] ?? 0));

        if($action==='save'&&$missionId>0){$currentForSave=$missionManager->getMission($missionId);if($currentForSave&&($currentForSave['status']??'')==='approved'&&$missionManager->canReopenApprovedMission($currentForSave))$missionManager->reopenApprovedMission($missionId,$actor);}

        if ($action === 'save') {
            $payload = [
                'personnel_id' => (string) ($_POST['personnel_id'] ?? ''),
                'mission_title' => trim((string) ($_POST['mission_title'] ?? '')),
                'mission_purpose' => trim((string) ($_POST['mission_purpose'] ?? '')),
                'destination' => trim((string) ($_POST['destination'] ?? '')),
                'start_date' => (string) ($_POST['start_date'] ?? ''),
                'end_date' => (string) ($_POST['end_date'] ?? ''),
                'notes' => trim((string) ($_POST['notes'] ?? '')),
                'link_type' => (string) ($_POST['link_type'] ?? ''),
                'chantier_id' => (string) ($_POST['chantier_id'] ?? ''),
                'link_label' => trim((string) ($_POST['link_label'] ?? '')),
            ];

            if ($missionId <= 0) {
                $missionId = $missionManager->createMission($payload, $actor);
            } else {
                $missionManager->updateMission($missionId, $payload, $actor);
            }

            $expenseLines = parseExpenseLines($_POST);
            $missionManager->replaceMissionExpenses($missionId, $expenseLines, $actor);
            $financialPost=$_POST;$financialPost['chantier_id']=$_POST['financial_chantier_id']??($_POST['chantier_id']??0);$missionManager->saveFinancialAllocation($missionId, $financialPost, $actor);
            // Le bouton « Enregistrer » doit aussi valider le choix effectué
            // dans la carte DRH. Auparavant ce bloc n'était traité que par
            // l'action finale « Valider », donnant l'impression que la
            // signature disparaissait au retour sur le formulaire.
            $signatureState=$missionManager->getMission($missionId);
            if((string)($_POST['drh_signature_confirmed']??'')==='1'&&trim((string)($signatureState['drh_signature_data']??''))===''){
                $missionManager->signMission(
                    $missionId,
                    'drh',
                    (string)($_POST['drh_signature_mode']??'profile'),
                    (string)($_POST['drh_signature_data']??''),
                    $actor,
                    !$missionManager->isOfficialDrhSigner((int)($actor['user_id']??0))
                );
            }
            // Le choix « DRH signe en PO » est une action de signature, pas
            // seulement un état visuel du formulaire : on le conserve dès
            // l'enregistrement, sans attendre la validation finale.
            if (($_POST['employee_signature_choice'] ?? 'none') === 'po') {
                $missionManager->signMission(
                    $missionId,
                    'employee',
                    (string) ($_POST['drh_signature_mode'] ?? 'profile'),
                    (string) ($_POST['drh_signature_data'] ?? ''),
                    $actor,
                    true
                );
            }

            $auditMission('rh_mission_order_saved', [
                'action' => 'save',
                'entity_label' => $payload['mission_title'] !== '' ? $payload['mission_title'] : 'ordre_mission',
                'details' => [
                    'personnel_id' => $payload['personnel_id'] !== '' ? $payload['personnel_id'] : null,
                    'destination' => $payload['destination'] !== '' ? $payload['destination'] : null,
                    'start_date' => $payload['start_date'] !== '' ? $payload['start_date'] : null,
                    'end_date' => $payload['end_date'] !== '' ? $payload['end_date'] : null,
                    'expense_line_count' => count($expenseLines),
                    'link_type' => $payload['link_type'] !== '' ? $payload['link_type'] : null,
                ],
            ]);

            header('Location: ordre_mission_rh.php?id=' . $missionId . '&saved=1');
            exit;
        }

        if ($missionId <= 0) {
            throw new RuntimeException('Veuillez d\'abord enregistrer le brouillon avant de faire une action de workflow.');
        }

        if ($action === 'submit') {
            $missionManager->submitMission($missionId, $actor);
            $auditMission('rh_mission_order_transitioned', [
                'action' => 'submit',
                'entity_label' => (string) ($mission['mission_title'] ?? 'ordre_mission'),
                'details' => ['transition' => 'submitted'],
            ]);
            header('Location: ordre_mission_rh.php?id=' . $missionId . '&transition=submitted');
            exit;
        }

        if ($action === 'reopen') {
            $missionManager->reopenApprovedMission($missionId,$actor);
            header('Location: ordre_mission_rh.php?id='.$missionId.'&reopened=1');exit;
        }

        // Une ancienne page peut encore poster « approve » alors que l'ordre
        // est déjà dans un état post-validation. S'il manque uniquement la
        // FEB, on lance sa génération sans tenter de modifier la mission.
        if($action==='approve'){$launchState=$missionManager->getMission($missionId);if(in_array((string)($launchState['status']??''),['approved','departed','returned','closed'],true)&&(int)($launchState['feb_request_id']??0)<=0)$action='launch_feb';}
        if($action==='launch_feb'){
            $febMission=$missionManager->getMission($missionId);if(!in_array((string)($febMission['status']??''),['approved','departed','returned','closed'],true))throw new RuntimeException('Validez d’abord l’ordre de mission avant de lancer la demande de décaissement.');
            $febExpenses=$missionManager->getMissionExpenses($missionId);$relativeProof='uploads/mission_orders/'.date('Y').'/ordre_mission_'.preg_replace('/[^A-Za-z0-9_-]/','_',($febMission['mission_code']??$missionId)).'.pdf';$absoluteProof=dirname(__DIR__).'/'.$relativeProof;
            if(!is_dir(dirname($absoluteProof))&&!mkdir(dirname($absoluteProof),0775,true)&&!is_dir(dirname($absoluteProof)))throw new RuntimeException('Impossible de créer le dossier des ordres de mission.');
            MissionOrderPdfService::render($febMission,$febExpenses,'F',$absoluteProof);$febId=$missionManager->createFebForApprovedMission($missionId,$relativeProof,$actor);
            header('Location: ordre_mission_rh.php?id='.$missionId.'&feb_created='.$febId);exit;
        }

        if ($action === 'approve') {
            // Une nouvelle validation après correction remplace la FEB encore
            // soumise avec les anciennes informations. La méthode refuse
            // volontairement toute FEB déjà approuvée ou financièrement liée.
            $missionManager->normalizeMissionFebForRegeneration($missionId,$actor);
            $approvalState=$missionManager->getMission($missionId);
            if(($approvalState['status']??'')==='approved'&&$missionManager->canReopenApprovedMission($approvalState))$missionManager->reopenApprovedMission($missionId,$actor);
            $missionManager->updateMission($missionId,[
                'personnel_id'=>$_POST['personnel_id']??0,'mission_title'=>$_POST['mission_title']??'','mission_purpose'=>$_POST['mission_purpose']??'','destination'=>$_POST['destination']??'',
                'start_date'=>$_POST['start_date']??'','end_date'=>$_POST['end_date']??'','notes'=>$_POST['notes']??'','link_type'=>$_POST['link_type']??'',
                'chantier_id'=>$_POST['chantier_id']??0,'link_label'=>$_POST['link_label']??''
            ],$actor);
            $financialPost=$_POST;$financialPost['chantier_id']=$_POST['financial_chantier_id']??0;$missionManager->saveFinancialAllocation($missionId,$financialPost,$actor);
            $missionManager->replaceMissionExpenses($missionId,parseExpenseLines($_POST),$actor);
            $missionBeforeApproval=$missionManager->getMission($missionId);
            if(trim((string)($missionBeforeApproval['drh_signature_data']??''))==='')$missionManager->signMission($missionId, 'drh', (string)($_POST['drh_signature_mode']??'profile'), (string)($_POST['drh_signature_data']??''), $actor, !$missionManager->isOfficialDrhSigner((int)($actor['user_id']??0)));
            if(($_POST['employee_signature_choice']??'none')==='po') $missionManager->signMission($missionId, 'employee', (string)($_POST['drh_signature_mode']??'profile'), (string)($_POST['drh_signature_data']??''), $actor, true);
            $missionManager->approveMission($missionId, $actor);
            $approvedMission=$missionManager->getMission($missionId);$approvedExpenses=$missionManager->getMissionExpenses($missionId);
            $relativeProof='uploads/mission_orders/'.date('Y').'/ordre_mission_'.preg_replace('/[^A-Za-z0-9_-]/','_',($approvedMission['mission_code']??$missionId)).'.pdf';$absoluteProof=dirname(__DIR__).'/'.$relativeProof;
            if(!is_dir(dirname($absoluteProof))&&!mkdir(dirname($absoluteProof),0775,true)&&!is_dir(dirname($absoluteProof)))throw new RuntimeException('Impossible de créer le dossier des ordres de mission.');
            MissionOrderPdfService::render($approvedMission,$approvedExpenses,'F',$absoluteProof);
            $febId=$missionManager->createFebForApprovedMission($missionId,$relativeProof,$actor);
            $auditMission('rh_mission_order_transitioned', [
                'action' => 'approve',
                'entity_label' => (string) ($mission['mission_title'] ?? 'ordre_mission'),
                'details' => ['transition' => 'approved','feb_request_id'=>$febId],
            ]);
            header('Location: ordre_mission_rh.php?id=' . $missionId . '&transition=approved');
            exit;
        }

        if ($action === 'sign_employee') {
            $identity=$pdo->prepare('SELECT personnel_id FROM user_devis_banamur WHERE id=:id');$identity->execute(['id'=>(int)$actor['user_id']]);
            $targetMission=$missionManager->getMission($missionId);
            if((int)$identity->fetchColumn()!==(int)($targetMission['personnel_id']??0))throw new RuntimeException('Seul le collaborateur concerné peut signer ici. Le DRH doit utiliser le mode PO.');
            $missionManager->signMission($missionId,'employee',(string)($_POST['employee_signature_mode']??'profile'),(string)($_POST['employee_signature_data']??''),$actor,false);
            if($isAjaxRequest){header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>true,'message'=>'Signature du collaborateur enregistrée sans rechargement.','signer'=>$actor['user_name'],'signed_at'=>date('d/m/Y H:i')],JSON_UNESCAPED_UNICODE);exit;}
            header('Location: ordre_mission_rh.php?id='.$missionId.'&signed=employee');exit;
        }
        if ($action === 'request_signature') {
            $missionManager->requestPoSignature($missionId,(int)($_POST['requested_user_id']??0),(string)($_POST['requested_role']??'employee'),$actor);
            header('Location: ordre_mission_rh.php?id='.$missionId.'&requested=1');exit;
        }

        if ($action === 'depart') {
            $missionManager->markDeparture($missionId, $actor);
            $auditMission('rh_mission_order_transitioned', [
                'action' => 'depart',
                'entity_label' => (string) ($mission['mission_title'] ?? 'ordre_mission'),
                'details' => ['transition' => 'departed'],
            ]);
            header('Location: ordre_mission_rh.php?id=' . $missionId . '&transition=departed');
            exit;
        }

        if ($action === 'return') {
            $missionManager->markReturn($missionId, $actor);
            $auditMission('rh_mission_order_transitioned', [
                'action' => 'return',
                'entity_label' => (string) ($mission['mission_title'] ?? 'ordre_mission'),
                'details' => ['transition' => 'returned'],
            ]);
            header('Location: ordre_mission_rh.php?id=' . $missionId . '&transition=returned');
            exit;
        }

        if ($action === 'close') {
            $missionManager->closeMission($missionId, $actor);
            $auditMission('rh_mission_order_transitioned', [
                'action' => 'close',
                'entity_label' => (string) ($mission['mission_title'] ?? 'ordre_mission'),
                'details' => ['transition' => 'closed'],
            ]);
            header('Location: ordre_mission_rh.php?id=' . $missionId . '&transition=closed');
            exit;
        }

        if ($action === 'cancel') {
            $missionManager->cancelMission($missionId, $actor);
            $auditMission('rh_mission_order_transitioned', [
                'action' => 'cancel',
                'entity_label' => (string) ($mission['mission_title'] ?? 'ordre_mission'),
                'details' => ['transition' => 'cancelled'],
            ]);
            header('Location: ordre_mission_rh.php?id=' . $missionId . '&transition=cancelled');
            exit;
        }

        throw new RuntimeException('Action non reconnue.');
    } catch (Throwable $e) {
        if((string)($_POST['form_action']??'')==='approve'&&$missionId>0){$approvedCheck=$missionManager->getMission($missionId);if(($approvedCheck['status']??'')==='approved'&&(int)($approvedCheck['feb_request_id']??0)===0){$pdo->prepare("UPDATE rh_mission_orders SET status='submitted',approved_at=NULL,approved_by_user_id=NULL,approved_by_name=NULL,updated_at=NOW() WHERE id=:id")->execute(['id'=>$missionId]);}}
        $auditMission('rh_mission_order_failed', [
            'action' => (string) ($_POST['form_action'] ?? 'save'),
            'entity_label' => (string) ($_POST['mission_title'] ?? ($mission['mission_title'] ?? 'ordre_mission')),
            'result' => 'failure',
            'severity' => 'warning',
            'details' => ['error' => $e->getMessage()],
        ]);
        $statusType = 'error';
        $statusMessage = $e->getMessage();
        if($isAjaxRequest){http_response_code(422);header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);exit;}
        // Conserver exactement la saisie utilisateur lorsque la création,
        // l'enregistrement ou une transition du workflow échoue.
        $failedFormInput = $_POST;

        $mission = $missionId > 0 ? $missionManager->getMission($missionId) : $mission;
        $events = $missionId > 0 ? $missionManager->getMissionEvents($missionId) : $events;
        $postedExpenses = parseExpenseLines($_POST);
        $expenses = $postedExpenses ?: ($missionId > 0 ? $missionManager->getMissionExpenses($missionId) : $expenses);
    }
}

if (isset($_GET['saved'])) {
    $statusType = 'success';
    $statusMessage = 'Ordre de mission enregistre.';
}

if (isset($_GET['transition'])) {
    $statusType = 'success';
    $statusMessage = 'Workflow mis a jour: ' . (string) $_GET['transition'];
}
if(isset($_GET['signed'])){$statusType='success';$statusMessage='Signature enregistrée.';}
if(isset($_GET['requested'])){$statusType='success';$statusMessage='Demande de signature PO transmise.';}
if(isset($_GET['reopened'])){$statusType='success';$statusMessage='Ordre rouvert : complétez les informations puis validez-le à nouveau. La signature DRH existante a été conservée.';}
if(isset($_GET['feb_created'])){$statusType='success';$statusMessage='Demande de décaissement FEB #'.(int)$_GET['feb_created'].' générée et soumise au circuit financier.';}

$defaults = [
    'personnel_id' => (string) ($failedFormInput['personnel_id'] ?? (($mission['personnel_id'] ?? '') ?: '')),
    'mission_title' => (string) ($failedFormInput['mission_title'] ?? ($mission['mission_title'] ?? '')),
    'mission_purpose' => (string) ($failedFormInput['mission_purpose'] ?? ($mission['mission_purpose'] ?? '')),
    'destination' => (string) ($failedFormInput['destination'] ?? ($mission['destination'] ?? '')),
    'start_date' => (string) ($failedFormInput['start_date'] ?? (($mission['start_date'] ?? '') ?: gmdate('Y-m-d'))),
    'end_date' => (string) ($failedFormInput['end_date'] ?? (($mission['end_date'] ?? '') ?: gmdate('Y-m-d'))),
    'notes' => (string) ($failedFormInput['notes'] ?? ($mission['notes'] ?? '')),
    'status' => (string) ($mission['status'] ?? 'draft'),
    'mission_code' => (string) ($mission['mission_code'] ?? ''),
    'link_type' => (string) ($failedFormInput['link_type'] ?? ($mission['link_type'] ?? '')),
    'chantier_id' => (string) ($failedFormInput['chantier_id'] ?? ($mission['chantier_id'] ?? '')),
    'financial_chantier_id' => (string) ($failedFormInput['financial_chantier_id'] ?? ($mission['chantier_id'] ?? '')),
    'link_label' => (string) ($failedFormInput['link_label'] ?? ($mission['link_label'] ?? '')),
    'ligne_devis_id'=>(string)($failedFormInput['ligne_devis_id']??($mission['ligne_devis_id']??'')),'debourse_id'=>(string)($failedFormInput['debourse_id']??($mission['debourse_id']??'')),'ligne_debourse_id'=>(string)($failedFormInput['ligne_debourse_id']??($mission['ligne_debourse_id']??'')),'planning_id'=>(string)($failedFormInput['planning_id']??($mission['planning_id']??'')),'fournisseur_id'=>(string)($failedFormInput['fournisseur_id']??($mission['fournisseur_id']??'')),'feb_request_id'=>(string)($mission['feb_request_id']??''),
];

if (empty($expenses)) {
    $expenses = [
        ['designation' => '', 'unit' => '', 'quantity' => '', 'unit_price' => '', 'line_total' => ''],
        ['designation' => '', 'unit' => '', 'quantity' => '', 'unit_price' => '', 'line_total' => ''],
        ['designation' => '', 'unit' => '', 'quantity' => '', 'unit_price' => '', 'line_total' => ''],
    ];
}

$pageTitle = 'Ordre de mission';
$activeMenu = 'rh_missions';

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>
<style>:root{--om-primary:<?=htmlspecialchars((string)$branding['primary_color'])?>;--om-accent:<?=htmlspecialchars((string)$branding['accent_color'])?>}.om-shell{background:radial-gradient(circle at top right,color-mix(in srgb,var(--om-accent) 18%,transparent),transparent 34%),#f8fafc}.om-shell input:focus,.om-shell select:focus,.om-shell textarea:focus{border-color:var(--om-accent);box-shadow:0 0 0 3px color-mix(in srgb,var(--om-accent) 22%,transparent)}.om-brand-button{background:var(--om-primary);color:var(--om-accent)}.signature-choice{display:block;cursor:pointer}.signature-choice input{position:absolute;opacity:0;pointer-events:none}.signature-choice span{display:flex;min-height:104px;flex-direction:column;gap:5px;border:2px solid #e2e8f0;border-radius:18px;padding:14px;transition:.2s;background:#fff}.signature-choice span i{font-size:18px;color:#64748b}.signature-choice span b{font-size:14px;color:#0f172a}.signature-choice span small{font-size:11px;line-height:1.4;color:#64748b}.signature-choice input:checked+span{border-color:#f59e0b;background:#fffbeb;box-shadow:0 0 0 4px #fef3c7}.signature-choice input:checked+span i{color:#d97706}.signature-large-canvas{height:260px;min-height:260px;touch-action:none;cursor:crosshair}@media(max-width:640px){.signature-large-canvas{height:300px;min-height:300px}}</style>
<div class="om-shell min-h-screen pb-12">
    <div class="container mx-auto px-4 py-8">
        <div class="rounded-[32px] border border-slate-200 bg-white/90 p-8 shadow-[0_18px_60px_rgba(15,23,42,0.07)] backdrop-blur">
            <div class="flex flex-col gap-6 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <p class="text-xs font-black uppercase tracking-[0.32em] text-amber-600">FINÉA · RH · ISO 9001</p>
                    <h1 class="mt-3 text-3xl font-semibold tracking-tight text-slate-950 md:text-4xl">Ordre de mission</h1>
                    <p class="mt-3 max-w-3xl text-sm text-slate-600 md:text-base">
                        Liaison chantier/prospection/autre + saisie des frais en lignes type devis.
                    </p>
                </div>

                <div class="flex flex-wrap gap-3">
                    <a href="ordres_mission_rh.php" class="rounded-2xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50">Retour liste</a>
                    <?php if ($missionId > 0): ?>
                        <a href="../request/export_mission_order_rh_pdf.php?id=<?= (int) $missionId ?>" target="_blank" rel="noopener" class="rounded-2xl border border-rose-200 bg-rose-50 px-5 py-3 text-sm font-semibold text-rose-800 hover:bg-rose-100">
                            <i class="fas fa-file-pdf mr-2"></i>
                            Exporter l'ordre
                        </a>
                        <span class="rounded-2xl bg-slate-950 px-5 py-3 text-sm font-semibold text-white">
                            <?= htmlspecialchars($defaults['mission_code'] !== '' ? $defaults['mission_code'] : 'OM') ?>
                        </span>
                        <span class="rounded-2xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-700">
                            <?= htmlspecialchars($statusLabels[$defaults['status']] ?? $defaults['status']) ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($statusType !== null && $statusMessage !== null): ?>
                <div class="mt-8 rounded-2xl border px-5 py-4 text-sm font-medium <?= $statusType === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-rose-200 bg-rose-50 text-rose-800' ?>">
                    <?= htmlspecialchars((string) $statusMessage) ?>
                </div>
            <?php endif; ?>

            <div class="mt-10 grid gap-6 lg:grid-cols-3">
                <div class="lg:col-span-2 rounded-[28px] border border-slate-200 bg-white p-6 shadow-sm">
                    <form method="post" id="mission-form" class="space-y-8">
                        <input type="hidden" name="form_action" value="save">

                        <section>
                            <div class="flex items-center justify-between">
                                <h2 class="text-lg font-semibold text-slate-950">Infos mission</h2>
                                <?php if ($missionId > 0): ?>
                                    <div class="flex flex-wrap gap-2">
                                        <?php if ($defaults['status'] === 'draft'): ?>
                                            <button type="submit" name="form_action" value="submit" class="rounded-2xl bg-amber-500 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-600">
                                                <i class="fas fa-paper-plane mr-2"></i>
                                                Soumettre
                                            </button>
                                            <button type="submit" name="form_action" value="cancel" class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-2 text-sm font-semibold text-rose-800 hover:bg-rose-100" onclick="return confirm('Annuler cet ordre de mission ?');">
                                                <i class="fas fa-ban mr-2"></i>
                                                Annuler
                                            </button>
                                        <?php elseif ($defaults['status'] === 'submitted'): ?>
                                            <button type="submit" name="form_action" value="approve" class="rounded-2xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
                                                <i class="fas fa-circle-check mr-2"></i>
                                                Valider
                                            </button>
                                            <button type="submit" name="form_action" value="cancel" class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-2 text-sm font-semibold text-rose-800 hover:bg-rose-100" onclick="return confirm('Annuler cet ordre de mission ?');">
                                                <i class="fas fa-ban mr-2"></i>
                                                Annuler
                                            </button>
                                        <?php elseif ($defaults['status'] === 'approved'): ?>
                                            <?php if($missionManager->canReopenApprovedMission((array)$mission)): ?>
                                            <button type="submit" name="form_action" value="approve" class="rounded-2xl bg-amber-500 px-4 py-2 text-sm font-black text-black" onclick="return confirm('Enregistrer les compléments, signer et soumettre à nouveau cet ordre ?');">
                                                <i class="fas fa-signature mr-2"></i>Signer et soumettre à nouveau
                                            </button>
                                            <?php endif; ?>
                                            <button type="submit" name="form_action" value="depart" class="rounded-2xl bg-sky-600 px-4 py-2 text-sm font-semibold text-white hover:bg-sky-700">
                                                <i class="fas fa-plane-departure mr-2"></i>
                                                Depart
                                            </button>
                                            <button type="submit" name="form_action" value="cancel" class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-2 text-sm font-semibold text-rose-800 hover:bg-rose-100" onclick="return confirm('Annuler cet ordre de mission validé ? Cette action sera conservée dans l’historique.');">
                                                <i class="fas fa-ban mr-2"></i>
                                                Annuler
                                            </button>
                                        <?php elseif ($defaults['status'] === 'departed'): ?>
                                            <button type="submit" name="form_action" value="return" class="rounded-2xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                                                <i class="fas fa-plane-arrival mr-2"></i>
                                                Retour
                                            </button>
                                            <button type="submit" name="form_action" value="cancel" class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-2 text-sm font-semibold text-rose-800 hover:bg-rose-100" onclick="return confirm('Annuler cette mission déjà démarrée ? Cette action sera conservée dans l’historique.');">
                                                <i class="fas fa-ban mr-2"></i>
                                                Annuler
                                            </button>
                                        <?php elseif ($defaults['status'] === 'returned'): ?>
                                            <button type="submit" name="form_action" value="close" class="rounded-2xl bg-slate-950 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                                                <i class="fas fa-lock mr-2"></i>
                                                Cloturer
                                            </button>
                                            <button type="submit" name="form_action" value="cancel" class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-2 text-sm font-semibold text-rose-800 hover:bg-rose-100" onclick="return confirm('Annuler cet ordre de mission revenu ? Cette action sera conservée dans l’historique.');">
                                                <i class="fas fa-ban mr-2"></i>
                                                Annuler
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="mt-6 grid gap-4 md:grid-cols-2">
                                <div class="md:col-span-2">
                                    <label class="mb-2 block text-sm font-medium text-slate-700">Objet / titre</label>
                                    <input type="text" name="mission_title" value="<?= htmlspecialchars($defaults['mission_title']) ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-indigo-400" placeholder="Ex: Visite de chantier, reunion client, prospection...">
                                </div>
                                <div>
                                    <label class="mb-2 block text-sm font-medium text-slate-700">Collaborateur</label>
                                    <select name="personnel_id" data-modern-select data-placeholder="Rechercher par nom ou matricule…" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-indigo-400">
                                        <option value="">Selectionner</option>
                                        <?php foreach ($personnelChoices as $choice): ?>
                                            <option value="<?= (int) $choice['id'] ?>" <?= (string) $choice['id'] === (string) $defaults['personnel_id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars((string) $choice['full_name']) ?>
                                                <?= !empty($choice['matricule']) ? ' • ' . htmlspecialchars((string) $choice['matricule']) : '' ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-2 block text-sm font-medium text-slate-700">Destination</label>
                                    <input type="text" name="destination" value="<?= htmlspecialchars($defaults['destination']) ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-indigo-400" placeholder="Ville / site / pays">
                                </div>
                                <div>
                                    <label class="mb-2 block text-sm font-medium text-slate-700">Debut prevu</label>
                                    <input type="date" name="start_date" value="<?= htmlspecialchars($defaults['start_date']) ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-indigo-400">
                                </div>
                                <div>
                                    <label class="mb-2 block text-sm font-medium text-slate-700">Fin prevue</label>
                                    <input type="date" name="end_date" value="<?= htmlspecialchars($defaults['end_date']) ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-indigo-400">
                                </div>
                                <div class="md:col-span-2">
                                    <label class="mb-2 block text-sm font-medium text-slate-700">But / contexte</label>
                                    <textarea name="mission_purpose" rows="3" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-indigo-400" placeholder="Objectif de la mission, interlocuteurs, agenda..."><?= htmlspecialchars($defaults['mission_purpose']) ?></textarea>
                                </div>
                            </div>
                        </section>

                        <section class="rounded-[26px] border border-amber-200 bg-amber-50/60 p-5">
                            <div class="flex items-start gap-3"><span class="grid h-10 w-10 shrink-0 place-items-center rounded-2xl bg-[#ffcc20] text-black"><i class="fas fa-diagram-project"></i></span><div><h2 class="text-lg font-black text-slate-950">Affectation financière</h2><p class="mt-1 text-sm text-slate-600">Obligatoire pour toute soumission à compter du 24/08/2026. Les anciens ordres incomplets restent modifiables.</p></div></div>
                            <div class="mt-5 grid gap-4 md:grid-cols-2">
                                <div><label class="mb-2 block text-sm font-bold">1. Chantier</label><select name="financial_chantier_id" id="financial_chantier_id" data-modern-select data-placeholder="Rechercher un chantier…"></select></div>
                                <div><label class="mb-2 block text-sm font-bold">2. Ligne de devis</label><select name="ligne_devis_id" id="ligne_devis_id" data-modern-select data-placeholder="Rechercher une ligne de devis…"></select></div>
                                <div><label class="mb-2 block text-sm font-bold">3. Déboursé</label><select name="debourse_id" id="debourse_id" data-modern-select data-placeholder="Rechercher un déboursé…"></select></div>
                                <div><label class="mb-2 block text-sm font-bold">4. Ligne de déboursé</label><select name="ligne_debourse_id" id="ligne_debourse_id" data-modern-select data-placeholder="Rechercher une ligne de déboursé…"></select></div>
                                <div><label class="mb-2 block text-sm font-bold">5. Planning</label><select name="planning_id" id="planning_id" data-modern-select data-placeholder="Rechercher un planning…"></select></div>
                                <div><label class="mb-2 block text-sm font-bold">6. Fournisseur de la FEB</label><select name="fournisseur_id" data-modern-select data-placeholder="Rechercher un fournisseur…"><option value="">Sélectionner le fournisseur</option><?php foreach($supplierChoices as $supplier): ?><option value="<?=(int)$supplier['id']?>" <?=(string)$supplier['id']===$defaults['fournisseur_id']?'selected':''?>><?=htmlspecialchars(trim(($supplier['code']??'').' · '.$supplier['label'],' ·'))?></option><?php endforeach ?></select></div>
                                <?php if($defaults['feb_request_id']!==''): ?><div class="md:col-span-2 flex flex-col gap-3 rounded-2xl border border-emerald-200 bg-emerald-100 px-5 py-4 text-sm text-emerald-950 sm:flex-row sm:items-center sm:justify-between"><div><div class="font-black"><i class="fas fa-circle-check mr-2"></i>FEB #<?= (int)$defaults['feb_request_id'] ?> · <?=htmlspecialchars((string)($mission['feb_status']??'soumise'))?></div><div class="mt-1 text-xs font-semibold text-emerald-800">Demande de décaissement rattachée à cet ordre de mission.</div></div><a href="../employe/details_demande_employe.php?id=<?=(int)$defaults['feb_request_id']?>" target="_blank" rel="noopener" class="rounded-xl bg-emerald-800 px-4 py-2 text-center font-black text-white"><i class="fas fa-arrow-up-right-from-square mr-2"></i>Ouvrir la FEB</a></div><?php else: ?><?php $canLaunchFebDirectly=in_array($defaults['status'],['approved','departed','returned','closed'],true); ?><div class="md:col-span-2 flex flex-col gap-3 rounded-2xl border border-indigo-200 bg-indigo-50 px-5 py-4 text-sm text-indigo-950 sm:flex-row sm:items-center sm:justify-between"><div><div class="font-black"><i class="fas fa-file-circle-plus mr-2"></i>Aucune FEB générée</div><div class="mt-1 text-xs font-semibold text-indigo-700">Le contrôle vérifiera le planning et toute l’affectation financière. Le DRH sera le bénéficiaire.</div></div><button type="submit" name="form_action" value="<?=$canLaunchFebDirectly?'launch_feb':'approve'?>" class="rounded-xl bg-indigo-700 px-4 py-3 font-black text-white"><i class="fas fa-paper-plane mr-2"></i><?=$canLaunchFebDirectly?'Lancer la demande de décaissement':'Valider et lancer la demande'?></button></div><?php endif ?>
                            </div>
                        </section>

                        <section>
                            <h2 class="text-lg font-semibold text-slate-950">Liaison</h2>
                            <p class="mt-1 text-sm text-slate-600">Chantier, prospection, ou autre contexte.</p>

                            <div class="mt-5 grid gap-4 md:grid-cols-2">
                                <div>
                                    <label class="mb-2 block text-sm font-medium text-slate-700">Type</label>
                                    <select name="link_type" id="link_type" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-indigo-400">
                                        <option value="">Aucune</option>
                                        <option value="chantier" <?= $defaults['link_type'] === 'chantier' ? 'selected' : '' ?>>Chantier</option>
                                        <option value="prospection" <?= $defaults['link_type'] === 'prospection' ? 'selected' : '' ?>>Prospection</option>
                                        <option value="autre" <?= $defaults['link_type'] === 'autre' ? 'selected' : '' ?>>Autre</option>
                                    </select>
                                </div>

                                <div id="chantier_box" class="hidden">
                                    <label class="mb-2 block text-sm font-medium text-slate-700">Chantier</label>
                                    <select name="chantier_id" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-indigo-400">
                                        <option value="">Selectionner</option>
                                        <?php foreach ($chantiers as $chantier): ?>
                                            <option value="<?= (int) $chantier['id'] ?>" <?= (string) $chantier['id'] === (string) $defaults['chantier_id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars((string) ($chantier['code'] ?? ('CH-' . $chantier['id']))) ?>
                                                —
                                                <?= htmlspecialchars((string) ($chantier['titre'] ?? '')) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div id="label_box" class="hidden md:col-span-2">
                                    <label class="mb-2 block text-sm font-medium text-slate-700">Precision</label>
                                    <input type="text" name="link_label" value="<?= htmlspecialchars($defaults['link_label']) ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-indigo-400" placeholder="Ex: Prospection Abidjan, Audit fournisseur, Formation...">
                                </div>
                            </div>
                        </section>

                        <section>
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                                <div>
                                    <h2 class="text-lg font-semibold text-slate-950">Frais de mission</h2>
                                    <p class="mt-1 text-sm text-slate-600">Saisie en lignes (designation, unite, quantite, PU) — comme un devis.</p>
                                </div>
                                <button type="button" id="add-expense" class="rounded-2xl bg-slate-950 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                                    <i class="fas fa-plus mr-2"></i>
                                    Ajouter une ligne
                                </button>
                            </div>

                            <div class="mt-5 overflow-hidden rounded-[24px] border border-slate-200">
                                <div class="bg-gradient-to-r from-slate-950 via-slate-900 to-indigo-900 px-5 py-4 text-white">
                                    <div class="flex items-center justify-between">
                                        <div class="text-sm font-semibold">Tableau des frais</div>
                                        <div class="text-xs text-white/80">Auto-calcul des totaux</div>
                                    </div>
                                </div>

                                <div class="bg-white p-4">
                                    <div class="grid grid-cols-12 gap-3 px-2 pb-2 text-xs font-semibold uppercase tracking-[0.22em] text-slate-400">
                                        <div class="col-span-5">Designation</div>
                                        <div class="col-span-2">Unite</div>
                                        <div class="col-span-2 text-right">Qte</div>
                                        <div class="col-span-2 text-right">PU</div>
                                        <div class="col-span-1"></div>
                                    </div>

                                    <div id="expenses-rows" class="space-y-3">
                                        <?php foreach ($expenses as $idx => $line): ?>
                                            <?php
                                            $designation = (string) ($line['designation'] ?? '');
                                            $unit = (string) ($line['unit'] ?? '');
                                            $quantity = (string) ($line['quantity'] ?? '');
                                            $unitPrice = (string) ($line['unit_price'] ?? '');
                                            ?>
                                            <div class="expense-row grid grid-cols-12 gap-3 rounded-2xl bg-slate-50 px-2 py-2">
                                                <div class="col-span-5">
                                                    <input type="text" name="expense_designation[]" value="<?= htmlspecialchars($designation) ?>" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-indigo-400" placeholder="Ex: Hotel, taxi, repas, carburant...">
                                                </div>
                                                <div class="col-span-2">
                                                    <input type="text" name="expense_unit[]" value="<?= htmlspecialchars($unit) ?>" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-indigo-400" placeholder="j, nuit, u">
                                                </div>
                                                <div class="col-span-2">
                                                    <input type="number" step="0.01" min="0" name="expense_quantity[]" value="<?= htmlspecialchars($quantity) ?>" class="expense-qty w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-right outline-none focus:border-indigo-400" placeholder="0">
                                                </div>
                                                <div class="col-span-2">
                                                    <input type="number" step="0.01" min="0" name="expense_unit_price[]" value="<?= htmlspecialchars($unitPrice) ?>" class="expense-pu w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-right outline-none focus:border-indigo-400" placeholder="0">
                                                </div>
                                                <div class="col-span-1 flex items-center justify-end">
                                                    <button type="button" class="remove-expense rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-100" title="Supprimer">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>
                                                <div class="col-span-12 flex items-center justify-between px-1">
                                                    <div class="text-xs text-slate-500">Total ligne</div>
                                                    <div class="expense-total text-sm font-semibold text-slate-900">0</div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>

                                    <div class="mt-5 rounded-2xl border border-slate-200 bg-white px-5 py-4">
                                        <div class="flex items-center justify-between">
                                            <div>
                                                <div class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-400">Total frais</div>
                                                <div class="mt-1 text-2xl font-semibold text-slate-950" id="expenses-grand-total">0</div>
                                            </div>
                                            <div class="text-sm text-slate-600">HT (sans TVA)</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </section>

                        <section>
                            <label class="mb-2 block text-sm font-medium text-slate-700">Notes</label>
                            <textarea name="notes" rows="3" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-indigo-400" placeholder="Infos pratiques, contacts, moyens de transport, consignes..."><?= htmlspecialchars($defaults['notes']) ?></textarea>
                        </section>

                        <?php if($missionId>0): ?><section class="rounded-[30px] border border-slate-200 bg-gradient-to-br from-white to-slate-50 p-6 shadow-sm"><div class="flex items-start gap-4"><span class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-black text-xl text-[#ffcc20]"><i class="fas fa-signature"></i></span><div><h2 class="text-xl font-black text-slate-950">Signatures électroniques</h2><p class="mt-1 text-sm leading-6 text-slate-600">Choisissez clairement qui signe et comment. L’ordre reste modifiable et peut être soumis à nouveau tant que les signatures du DRH et du collaborateur ne sont pas toutes les deux enregistrées.</p></div></div>
                            <div class="mt-6 grid gap-6 xl:grid-cols-2"><div class="rounded-[26px] border-2 border-amber-200 bg-white p-5"><div class="flex items-center justify-between"><div><span class="text-xs font-black uppercase tracking-widest text-amber-700">Validation obligatoire</span><h3 class="mt-1 text-lg font-black">Directeur des Ressources humaines</h3></div><i class="fas fa-user-shield text-2xl text-amber-500"></i></div><?php if(!empty($mission['drh_signed_at'])): ?><div class="mt-5 rounded-2xl bg-emerald-50 p-4 text-sm font-semibold text-emerald-800"><i class="fas fa-check-circle mr-2"></i>Signature DRH enregistrée par <?=htmlspecialchars((string)$mission['drh_signed_by_name'])?></div><?php else: ?><div class="mt-5 grid gap-3 sm:grid-cols-2"><label class="signature-choice"><input type="radio" name="drh_signature_mode" value="profile" checked><span><i class="fas fa-id-badge"></i><b>Signature du profil</b><small>Utiliser la signature déjà enregistrée</small></span></label><label class="signature-choice"><input type="radio" name="drh_signature_mode" value="drawn"><span><i class="fas fa-pen-nib"></i><b>Signer maintenant</b><small>Ouvrir la grande zone tactile</small></span></label></div><div id="drhDrawBlock" class="mt-4 hidden"><canvas id="drhSignatureCanvas" class="signature-large-canvas w-full touch-none rounded-2xl border-2 border-dashed border-amber-400 bg-white"></canvas><input type="hidden" name="drh_signature_data" id="drh_signature_data"><div class="mt-2 flex items-center justify-between text-xs"><span class="font-semibold text-slate-500">Signez lentement avec le doigt, le stylet ou la souris.</span><button type="button" data-clear-signature class="font-black text-rose-700">Effacer</button></div></div><?php endif ?></div>
                            <div class="rounded-[26px] border-2 border-slate-200 bg-white p-5" id="employeeSignatureCard"><div class="flex items-center justify-between"><div><span class="text-xs font-black uppercase tracking-widest text-slate-500">Signature facultative</span><h3 class="mt-1 text-lg font-black">Collaborateur en mission</h3></div><i class="fas fa-user-check text-2xl text-slate-400"></i></div><?php if(!empty($mission['employee_signed_at'])): ?><div class="mt-5 rounded-2xl bg-emerald-50 p-4 text-sm font-semibold text-emerald-800" id="employeeSignedState"><i class="fas fa-check-circle mr-2"></i>Signé par <?=htmlspecialchars((string)$mission['employee_signed_by_name'])?><?=!empty($mission['employee_signed_po'])?' · Pour ordre':''?></div><?php else: ?><div class="mt-5 grid gap-3 sm:grid-cols-2"><label class="signature-choice"><input type="radio" name="employee_signature_choice" value="none" checked><span><i class="fas fa-forward"></i><b>Plus tard</b><small>La signature reste facultative</small></span></label><label class="signature-choice"><input type="radio" name="employee_signature_choice" value="profile"><span><i class="fas fa-id-badge"></i><b>Signature du profil</b><small>Le collaborateur utilise son profil</small></span></label><label class="signature-choice"><input type="radio" name="employee_signature_choice" value="drawn"><span><i class="fas fa-pen-nib"></i><b>Signer maintenant</b><small>Afficher la grande zone tactile</small></span></label><label class="signature-choice"><input type="radio" name="employee_signature_choice" value="po"><span><i class="fas fa-user-shield"></i><b>DRH signe en PO</b><small>Aucune zone collaborateur</small></span></label></div><input type="hidden" name="employee_signature_mode" id="employee_signature_mode" value="profile"><div id="employeeDrawBlock" class="mt-4 hidden"><canvas id="employeeSignatureCanvas" class="signature-large-canvas w-full touch-none rounded-2xl border-2 border-dashed border-slate-400 bg-white"></canvas><input type="hidden" name="employee_signature_data" id="employee_signature_data"><div class="mt-2 flex items-center justify-between text-xs"><span class="font-semibold text-slate-500">Signature du collaborateur uniquement.</span><button type="button" data-clear-signature class="font-black text-rose-700">Effacer</button></div></div><div id="employeeSignActions" class="mt-4 hidden"><button type="button" id="employeeSignButton" class="w-full rounded-2xl bg-black px-5 py-3 font-black text-[#ffcc20]"><i class="fas fa-shield-check mr-2"></i>Enregistrer ma signature sans recharger</button><p id="employeeSignStatus" class="mt-2 text-center text-sm font-semibold"></p></div><?php endif ?></div></div>
                            <div class="mt-5 rounded-2xl border border-dashed bg-white p-4"><h3 class="font-bold">Transmettre une signature en PO</h3><div class="mt-3 grid gap-3 md:grid-cols-3"><select name="requested_user_id" class="rounded-xl border px-3 py-2"><option value="">Choisir un utilisateur</option><?php foreach($userChoices as $user): ?><option value="<?=(int)$user['id']?>"><?=htmlspecialchars($user['label'].' · '.$user['mail_pro'])?></option><?php endforeach ?></select><select name="requested_role" class="rounded-xl border px-3 py-2"><option value="employee">Pour le collaborateur</option><option value="drh">Pour le DRH</option></select><button type="submit" name="form_action" value="request_signature" class="rounded-xl border border-black bg-white px-4 py-2 text-sm font-bold">Transmettre</button></div></div>
                        </section><?php endif ?>

                        <div class="flex flex-wrap gap-3">
                            <button type="submit" name="form_action" value="save" class="om-brand-button rounded-2xl px-6 py-3 text-sm font-black">
                                <i class="fas fa-floppy-disk mr-2"></i>
                                Enregistrer
                            </button>
                            <a href="ordres_mission_rh.php" class="rounded-2xl border border-slate-200 bg-white px-6 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50">Retour liste</a>
                        </div>
                    </form>
                </div>

                <div class="rounded-[28px] border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold text-slate-950">Historique</h2>
                    <p class="mt-1 text-sm text-slate-600">Evenements de workflow et mises a jour.</p>

                    <?php if ($missionId <= 0): ?>
                        <div class="mt-6 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-6 text-sm text-slate-600">Enregistrez le brouillon pour voir l'historique.</div>
                    <?php elseif (empty($events)): ?>
                        <div class="mt-6 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-6 text-sm text-slate-600">Aucun evenement.</div>
                    <?php else: ?>
                        <div class="mt-6 space-y-3">
                            <?php foreach ($events as $event): ?>
                                <div class="rounded-2xl border border-slate-200 bg-white px-4 py-4">
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <div class="text-sm font-semibold text-slate-900"><?= htmlspecialchars((string) ($event['title'] ?? '')) ?></div>
                                            <div class="mt-1 text-xs uppercase tracking-[0.22em] text-slate-400"><?= htmlspecialchars((string) ($event['event_type'] ?? '')) ?></div>
                                        </div>
                                        <div class="text-xs font-semibold text-slate-600"><?= htmlspecialchars((string) ($event['event_at'] ?? '')) ?></div>
                                    </div>
                                    <?php if (!empty($event['created_by_name'])): ?>
                                        <div class="mt-2 text-sm text-slate-600">Par: <span class="font-semibold text-slate-800"><?= htmlspecialchars((string) $event['created_by_name']) ?></span></div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    const financialRows=<?=json_encode($financialChoices,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP)?>,financialCurrent=<?=json_encode(['chantier_id'=>(int)$defaults['financial_chantier_id'],'ligne_devis_id'=>(int)$defaults['ligne_devis_id'],'debourse_id'=>(int)$defaults['debourse_id'],'ligne_debourse_id'=>(int)$defaults['ligne_debourse_id'],'planning_id'=>(int)$defaults['planning_id']])?>;
    const uniqueBy=(rows,key,label)=>{const map=new Map;rows.forEach(r=>{const id=Number(r[key]||0);if(id&&!map.has(id))map.set(id,{id,label:label(r)})});return [...map.values()]};
    const fillSelect=(id,items,selected,placeholder)=>{const el=document.getElementById(id);if(!el)return;el.innerHTML=`<option value="">${placeholder}</option>`+items.map(i=>`<option value="${i.id}" ${i.id===Number(selected)?'selected':''}>${String(i.label).replaceAll('&','&amp;').replaceAll('<','&lt;')}</option>`).join('');el.dispatchEvent(new Event('optionsupdated'))};
    const rebuildFinancial=(level=0)=>{const chantier=Number(document.getElementById('financial_chantier_id')?.value||0),ligne=Number(document.getElementById('ligne_devis_id')?.value||0),debourse=Number(document.getElementById('debourse_id')?.value||0),ligneDebourse=Number(document.getElementById('ligne_debourse_id')?.value||0);if(level<=0)fillSelect('financial_chantier_id',uniqueBy(financialRows,'chantier_id',r=>`${r.chantier_code||''} · ${r.chantier_title||''}`),financialCurrent.chantier_id,'Choisir le chantier');const rows1=financialRows.filter(r=>Number(r.chantier_id)===Number(document.getElementById('financial_chantier_id')?.value||0));if(level<=1)fillSelect('ligne_devis_id',uniqueBy(rows1,'ligne_devis_id',r=>r.ligne_devis_label||`Ligne #${r.ligne_devis_id}`),level===0?financialCurrent.ligne_devis_id:0,'Choisir d’abord le chantier');const rows2=rows1.filter(r=>Number(r.ligne_devis_id)===Number(document.getElementById('ligne_devis_id')?.value||0));if(level<=2)fillSelect('debourse_id',uniqueBy(rows2,'debourse_id',r=>`Déboursé #${r.debourse_id}`),level===0?financialCurrent.debourse_id:0,'Choisir d’abord la ligne de devis');const rows3=rows2.filter(r=>Number(r.debourse_id)===Number(document.getElementById('debourse_id')?.value||0));if(level<=3)fillSelect('ligne_debourse_id',uniqueBy(rows3,'ligne_debourse_id',r=>r.ligne_debourse_label||`Ligne #${r.ligne_debourse_id}`),level===0?financialCurrent.ligne_debourse_id:0,'Choisir d’abord le déboursé');const rows4=rows3.filter(r=>Number(r.ligne_debourse_id)===Number(document.getElementById('ligne_debourse_id')?.value||0));if(level<=4)fillSelect('planning_id',uniqueBy(rows4,'planning_id',r=>r.planning_label||`Planning #${r.planning_id}`),level===0?financialCurrent.planning_id:0,'Choisir le planning')};
    rebuildFinancial(0);[['financial_chantier_id',1],['ligne_devis_id',2],['debourse_id',3],['ligne_debourse_id',4]].forEach(([id,level])=>document.getElementById(id)?.addEventListener('change',()=>rebuildFinancial(level)));
    function bindSignatureCanvas(canvasId,inputId){const canvas=document.getElementById(canvasId),input=document.getElementById(inputId);if(!canvas||!input)return;const ctx=canvas.getContext('2d');canvas.width=1000;canvas.height=360;ctx.lineWidth=6;ctx.lineCap='round';ctx.lineJoin='round';ctx.strokeStyle='#050505';let drawing=false;const point=e=>{const r=canvas.getBoundingClientRect(),p=e.touches?.[0]||e;return{x:(p.clientX-r.left)*canvas.width/r.width,y:(p.clientY-r.top)*canvas.height/r.height}};const start=e=>{drawing=true;const p=point(e);ctx.beginPath();ctx.moveTo(p.x,p.y);e.preventDefault()};const move=e=>{if(!drawing)return;const p=point(e);ctx.lineTo(p.x,p.y);ctx.stroke();input.value=canvas.toDataURL('image/png');e.preventDefault()};const stop=()=>{if(drawing)input.value=canvas.toDataURL('image/png');drawing=false};['pointerdown','touchstart'].forEach(n=>canvas.addEventListener(n,start,{passive:false}));['pointermove','touchmove'].forEach(n=>canvas.addEventListener(n,move,{passive:false}));['pointerup','pointerleave','touchend','touchcancel'].forEach(n=>canvas.addEventListener(n,stop));canvas.closest('div')?.querySelector('[data-clear-signature]')?.addEventListener('click',()=>{ctx.clearRect(0,0,canvas.width,canvas.height);input.value=''})}
    bindSignatureCanvas('drhSignatureCanvas','drh_signature_data');bindSignatureCanvas('employeeSignatureCanvas','employee_signature_data');
    document.querySelectorAll('[name="drh_signature_mode"]').forEach(input=>input.addEventListener('click',()=>{const form=document.getElementById('mission-form');if(!form)return;let confirmation=form.querySelector('[name="drh_signature_confirmed"]');if(!confirmation){confirmation=document.createElement('input');confirmation.type='hidden';confirmation.name='drh_signature_confirmed';form.appendChild(confirmation)}confirmation.value='1'}));
    const initialEmployeeSignatureChoice=<?=json_encode((string)($failedFormInput['employee_signature_choice']??'none'))?>,initialEmployeeSignatureRadio=document.querySelector(`[name="employee_signature_choice"][value="${CSS.escape(initialEmployeeSignatureChoice)}"]`);if(initialEmployeeSignatureRadio)initialEmployeeSignatureRadio.checked=true;
    const syncSignatureUx=()=>{const drhMode=document.querySelector('[name="drh_signature_mode"]:checked')?.value||'profile';document.getElementById('drhDrawBlock')?.classList.toggle('hidden',drhMode!=='drawn');const employeeChoice=document.querySelector('[name="employee_signature_choice"]:checked')?.value||'none',employeeMode=document.getElementById('employee_signature_mode');if(employeeMode)employeeMode.value=employeeChoice==='drawn'?'drawn':'profile';document.getElementById('employeeDrawBlock')?.classList.toggle('hidden',employeeChoice!=='drawn');document.getElementById('employeeSignActions')?.classList.toggle('hidden',!['profile','drawn'].includes(employeeChoice))};document.querySelectorAll('[name="drh_signature_mode"],[name="employee_signature_choice"]').forEach(input=>input.addEventListener('change',syncSignatureUx));syncSignatureUx();
    document.getElementById('employeeSignButton')?.addEventListener('click',async()=>{const button=document.getElementById('employeeSignButton'),status=document.getElementById('employeeSignStatus'),form=document.getElementById('mission-form'),choice=document.querySelector('[name="employee_signature_choice"]:checked')?.value;if(!form||!['profile','drawn'].includes(choice))return;if(choice==='drawn'&&!document.getElementById('employee_signature_data')?.value){status.textContent='Veuillez dessiner la signature.';status.className='mt-2 text-center text-sm font-semibold text-rose-700';return}button.disabled=true;button.innerHTML='<i class="fas fa-spinner fa-spin mr-2"></i>Enregistrement…';status.textContent='';try{const data=new FormData(form);data.set('form_action','sign_employee');data.set('employee_signature_mode',choice==='drawn'?'drawn':'profile');const response=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'},body:data});const result=await response.json();if(!response.ok||!result.ok)throw new Error(result.error||'Signature impossible.');const card=document.getElementById('employeeSignatureCard');card.innerHTML=`<div class="flex items-center justify-between"><div><span class="text-xs font-black uppercase tracking-widest text-slate-500">Signature facultative</span><h3 class="mt-1 text-lg font-black">Collaborateur en mission</h3></div><i class="fas fa-user-check text-2xl text-emerald-500"></i></div><div class="mt-5 rounded-2xl bg-emerald-50 p-5 text-sm font-semibold text-emerald-800"><i class="fas fa-check-circle mr-2"></i>${result.message}<br><small class="mt-1 block">${result.signer||''} · ${result.signed_at||''}</small></div>`}catch(error){status.textContent=error.message;status.className='mt-2 text-center text-sm font-semibold text-rose-700';button.disabled=false;button.innerHTML='<i class="fas fa-shield-check mr-2"></i>Enregistrer ma signature sans recharger'}});
    function toNumber(value) {
        if (typeof value !== 'string') return Number(value || 0);
        if (value.trim() === '') return 0;
        return Number(value.replace(',', '.')) || 0;
    }

    function formatMoney(value) {
        return (Math.round(value * 100) / 100).toLocaleString('fr-FR', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function recalcExpenses() {
        const rows = document.querySelectorAll('.expense-row');
        let grandTotal = 0;

        rows.forEach(function(row) {
            const qtyInput = row.querySelector('.expense-qty');
            const puInput = row.querySelector('.expense-pu');
            const totalEl = row.querySelector('.expense-total');

            const qty = toNumber(qtyInput ? qtyInput.value : 0);
            const pu = toNumber(puInput ? puInput.value : 0);
            const total = Math.round(qty * pu * 100) / 100;

            if (totalEl) totalEl.textContent = formatMoney(total);
            grandTotal += total;
        });

        const grandEl = document.getElementById('expenses-grand-total');
        if (grandEl) grandEl.textContent = formatMoney(Math.round(grandTotal * 100) / 100);
    }

    function bindExpenseRow(row) {
        const qty = row.querySelector('.expense-qty');
        const pu = row.querySelector('.expense-pu');
        const removeBtn = row.querySelector('.remove-expense');

        if (qty) qty.addEventListener('input', recalcExpenses);
        if (pu) pu.addEventListener('input', recalcExpenses);
        if (removeBtn) {
            removeBtn.addEventListener('click', function() {
                row.remove();
                recalcExpenses();
            });
        }
    }

    function addExpenseRow() {
        const container = document.getElementById('expenses-rows');
        if (!container) return;

        const wrapper = document.createElement('div');
        wrapper.className = 'expense-row grid grid-cols-12 gap-3 rounded-2xl bg-slate-50 px-2 py-2';
        wrapper.innerHTML = `
            <div class="col-span-5">
                <input type="text" name="expense_designation[]" value="" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-indigo-400" placeholder="Ex: Hotel, taxi, repas, carburant...">
            </div>
            <div class="col-span-2">
                <input type="text" name="expense_unit[]" value="" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-indigo-400" placeholder="j, nuit, u">
            </div>
            <div class="col-span-2">
                <input type="number" step="0.01" min="0" name="expense_quantity[]" value="" class="expense-qty w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-right outline-none focus:border-indigo-400" placeholder="0">
            </div>
            <div class="col-span-2">
                <input type="number" step="0.01" min="0" name="expense_unit_price[]" value="" class="expense-pu w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-right outline-none focus:border-indigo-400" placeholder="0">
            </div>
            <div class="col-span-1 flex items-center justify-end">
                <button type="button" class="remove-expense rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-100" title="Supprimer">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
            <div class="col-span-12 flex items-center justify-between px-1">
                <div class="text-xs text-slate-500">Total ligne</div>
                <div class="expense-total text-sm font-semibold text-slate-900">0</div>
            </div>
        `;

        container.appendChild(wrapper);
        bindExpenseRow(wrapper);
        recalcExpenses();
    }

    function updateLinkVisibility() {
        const type = document.getElementById('link_type');
        const chantierBox = document.getElementById('chantier_box');
        const labelBox = document.getElementById('label_box');
        const value = type ? type.value : '';

        if (chantierBox) chantierBox.classList.toggle('hidden', value !== 'chantier');
        if (labelBox) labelBox.classList.toggle('hidden', !(value === 'prospection' || value === 'autre'));
    }

    document.querySelectorAll('.expense-row').forEach(bindExpenseRow);
    recalcExpenses();

    const addBtn = document.getElementById('add-expense');
    if (addBtn) addBtn.addEventListener('click', addExpenseRow);

    const typeSelect = document.getElementById('link_type');
    if (typeSelect) typeSelect.addEventListener('change', updateLinkVisibility);
    updateLinkVisibility();
</script>
<script src="assets/mission-select-search.js?v=<?=filemtime(__DIR__.'/assets/mission-select-search.js')?>"></script>

<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>
