<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../model/RhExplanationRequestManager.php';

$pdo = \Database::getConnection();
$manager = new \RhExplanationRequestManager($pdo);

$actor = [
    'user_id' => (int) ($_SESSION['user_id'] ?? 0),
    'name' => trim((string) ($_SESSION['user_name'] ?? (($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? '')))),
    'email' => trim((string) ($_SESSION['mail_pro'] ?? '')),
    'role' => 'employee',
    'ip' => trim((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '')),
];

$statusType = null;
$statusMessage = null;
$createDraft = [
    'target_personnel_id' => (string) ($_POST['target_personnel_id'] ?? ''),
    'subject' => (string) ($_POST['subject'] ?? ''),
    'due_days' => (string) ($_POST['due_days'] ?? ''),
    'incident_date' => (string) ($_POST['incident_date'] ?? ''),
    'incident_place' => (string) ($_POST['incident_place'] ?? ''),
    'context_summary' => (string) ($_POST['context_summary'] ?? ''),
    'observed_facts' => (string) ($_POST['observed_facts'] ?? ''),
    'expected_response' => (string) ($_POST['expected_response'] ?? ''),
    'supporting_elements' => (string) ($_POST['supporting_elements'] ?? ''),
];

$senderScope = $manager->getEmployeeSenderScope($actor);
$creatablePersonnelChoices = $manager->getPersonnelChoicesForEmployeeSender($actor, '', 2500);
$selectedCreatePersonnelLabel = '';
foreach ($creatablePersonnelChoices as $personChoice) {
    $personChoiceId = (int) ($personChoice['id'] ?? 0);
    if ((string) $personChoiceId !== (string) ($createDraft['target_personnel_id'] ?? '')) {
        continue;
    }

    $selectedCreatePersonnelLabel = trim((string) ($personChoice['full_name'] ?? ''));
    if ($selectedCreatePersonnelLabel === '') {
        $selectedCreatePersonnelLabel = trim((string) (($personChoice['email'] ?? '') !== '' ? $personChoice['email'] : ('Employé #' . $personChoiceId)));
    }
    break;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = trim((string) ($_POST['action'] ?? ''));
        if ($action === 'create_request') {
            $requestId = $manager->createRequest($actor, [
                'target_personnel_id' => (int) ($_POST['target_personnel_id'] ?? 0),
                'subject' => (string) ($_POST['subject'] ?? ''),
                'due_days' => (int) ($_POST['due_days'] ?? 0),
                'incident_date' => (string) ($_POST['incident_date'] ?? ''),
                'incident_place' => (string) ($_POST['incident_place'] ?? ''),
                'context_summary' => (string) ($_POST['context_summary'] ?? ''),
                'observed_facts' => (string) ($_POST['observed_facts'] ?? ''),
                'expected_response' => (string) ($_POST['expected_response'] ?? ''),
                'supporting_elements' => (string) ($_POST['supporting_elements'] ?? ''),
            ]);
            header('Location: mes_explications_employe.php?created=1&id=' . $requestId);
            exit;
        }
        if ($action === 'send_response') {
            $requestId = (int) ($_POST['request_id'] ?? 0);
            $manager->addEmployeeResponse($requestId, $actor, (string) ($_POST['response_html'] ?? ''));
            header('Location: mes_explications_employe.php?answered=1&id=' . $requestId);
            exit;
        }
        if ($action === 'request_rectification') {
            $requestId = (int) ($_POST['request_id'] ?? 0);
            $manager->requestEmployeeRectification($requestId, $actor, (string) ($_POST['rectification_html'] ?? ''));
            header('Location: mes_explications_employe.php?rectification=1&id=' . $requestId);
            exit;
        }
        if ($action === 'save_employee_signature') {
            $requestId = (int) ($_POST['request_id'] ?? 0);
            $manager->saveRequestSignature($requestId, 'employee', $actor, (string) ($_POST['signature_base64'] ?? ''));
            header('Location: mes_explications_employe.php?signed=1&id=' . $requestId);
            exit;
        }
    } catch (Throwable $e) {
        $statusType = 'error';
        $statusMessage = $e->getMessage();
    }
}

if ($statusMessage === null && isset($_GET['created'])) {
    $statusType = 'success';
    $statusMessage = 'La demande d’explications a bien été envoyée.';
} elseif ($statusMessage === null && isset($_GET['answered'])) {
    $statusType = 'success';
    $statusMessage = 'Votre réponse a bien été transmise.';
} elseif ($statusMessage === null && isset($_GET['rectification'])) {
    $statusType = 'success';
    $statusMessage = 'Votre demande de rectification a été transmise au RH.';
} elseif ($statusMessage === null && isset($_GET['signed'])) {
    $statusType = 'success';
    $statusMessage = 'Votre signature manuscrite a été enregistrée sur le dossier.';
}

$requests = $manager->listRequestsForEmployee((int) $actor['user_id'], (string) $actor['email'], 300);
$createdRequests = !empty($senderScope['can_send']) ? $manager->listRequestsCreatedByUser((int) $actor['user_id'], 300) : [];
$selectedId = max(0, (int) ($_GET['id'] ?? 0));
$selectedRequest = $selectedId > 0 ? $manager->getRequestById($selectedId) : ($requests[0] ?? ($createdRequests[0] ?? null));
$messages = $selectedRequest ? $manager->listMessages((int) $selectedRequest['id'], false) : [];
$settings = $manager->getSettings();
$responseDraft = (string) ($_POST['response_html'] ?? '');
$rectificationDraft = (string) ($_POST['rectification_html'] ?? '');
$employeeStats = [
    'total' => count($requests),
    'created' => count($createdRequests),
    'pending' => 0,
    'answered' => 0,
    'closed' => 0,
];
foreach ($requests as $requestRow) {
    $computedStatus = (string) ($requestRow['computed_status'] ?? $requestRow['status'] ?? '');
    if (in_array($computedStatus, ['pending_response', 'complement_requested', 'overdue'], true)) {
        $employeeStats['pending']++;
    } elseif ($computedStatus === 'answered') {
        $employeeStats['answered']++;
    } elseif ($computedStatus === 'closed') {
        $employeeStats['closed']++;
    }
}

$selectedIsOwner = false;
$selectedIsCreator = false;
if ($selectedRequest) {
    $selectedIsOwner = ((int) ($selectedRequest['target_user_id'] ?? 0) === (int) $actor['user_id'])
        || (strtolower((string) ($selectedRequest['target_email'] ?? '')) === strtolower((string) $actor['email']));
    $selectedIsCreator = (int) ($selectedRequest['created_by_user_id'] ?? 0) === (int) $actor['user_id'];
    if (!$selectedIsOwner && !$selectedIsCreator) {
        $selectedRequest = null;
        $messages = [];
    } else {
        if ($selectedIsOwner) {
            $manager->acknowledgeEmployeeRead((int) ($selectedRequest['id'] ?? 0), $actor);
        }
        $selectedRequest = $manager->getRequestById((int) ($selectedRequest['id'] ?? 0));
        $messages = $selectedRequest ? $manager->listMessages((int) $selectedRequest['id'], false) : [];
        $selectedIsOwner = $selectedRequest
            ? (((int) ($selectedRequest['target_user_id'] ?? 0) === (int) $actor['user_id'])
                || (strtolower((string) ($selectedRequest['target_email'] ?? '')) === strtolower((string) $actor['email'])))
            : false;
        $selectedIsCreator = $selectedRequest ? ((int) ($selectedRequest['created_by_user_id'] ?? 0) === (int) $actor['user_id']) : false;
    }
}

function employeeExplanationBadge(array $request): array
{
    $status = (string) ($request['computed_status'] ?? $request['status'] ?? '');
    $map = [
        'pending_response' => ['label' => 'Réponse attendue', 'class' => 'bg-amber-100 text-amber-800 border-amber-200'],
        'complement_requested' => ['label' => 'Complément demandé', 'class' => 'bg-sky-100 text-sky-800 border-sky-200'],
        'answered' => ['label' => 'Réponse transmise', 'class' => 'bg-emerald-100 text-emerald-800 border-emerald-200'],
        'closed' => ['label' => 'Clôturée', 'class' => 'bg-slate-100 text-slate-700 border-slate-200'],
        'cancelled' => ['label' => 'Annulée', 'class' => 'bg-rose-100 text-rose-800 border-rose-200'],
        'overdue' => ['label' => 'En retard', 'class' => 'bg-rose-100 text-rose-800 border-rose-200'],
    ];
    return $map[$status] ?? ['label' => $status, 'class' => 'bg-slate-100 text-slate-700 border-slate-200'];
}

function employeeExplanationSignatureAsset(int $requestId, string $role, ?string $relativePath): ?string
{
    $relativePath = trim(str_replace('\\', '/', (string) $relativePath));
    $requestId = max(0, $requestId);
    $role = $role === 'employee' ? 'employee' : 'rh';
    if ($relativePath === '' || $requestId <= 0) {
        return null;
    }

    return '../request/view_rh_explanation_signature.php?id=' . $requestId . '&role=' . $role;
}

function employeeExplanationActionSummary(array $request): array
{
    $status = (string) ($request['computed_status'] ?? $request['status'] ?? '');
    $hasSignature = !empty($request['employee_signed_at']);
    $hasResponse = !empty($request['answered_at']) || in_array($status, ['answered', 'closed'], true);

    if ($status === 'cancelled') {
        return [
            'title' => 'Dossier retiré du circuit',
            'message' => 'Aucune action complémentaire n’est attendue de votre part.',
            'tone' => 'border-rose-200 bg-rose-50 text-rose-900',
        ];
    }

    if (!$hasSignature) {
        return [
            'title' => 'Étape prioritaire: enregistrer votre signature',
            'message' => 'Votre signature manuscrite sera intégrée immédiatement au PDF officiel du dossier.',
            'tone' => 'border-amber-200 bg-amber-50 text-amber-900',
        ];
    }

    if (in_array($status, ['pending_response', 'complement_requested', 'overdue'], true) && !$hasResponse) {
        return [
            'title' => 'Étape suivante: transmettre votre réponse',
            'message' => 'Rédigez une réponse claire, factuelle et chronologique pour finaliser votre envoi.',
            'tone' => 'border-indigo-200 bg-indigo-50 text-indigo-900',
        ];
    }

    if ($status === 'answered') {
        return [
            'title' => 'Votre réponse a bien été transmise',
            'message' => 'Le dossier est maintenant en cours d’analyse côté RH. Vous pouvez conserver le PDF si besoin.',
            'tone' => 'border-emerald-200 bg-emerald-50 text-emerald-900',
        ];
    }

    if ($status === 'closed') {
        return [
            'title' => 'Le dossier est clôturé',
            'message' => 'Aucune autre action n’est attendue. Le dossier reste consultable dans votre espace.',
            'tone' => 'border-slate-200 bg-slate-100 text-slate-800',
        ];
    }

    return [
        'title' => 'Dossier consultable',
        'message' => 'Relisez le dossier, conservez le PDF et vérifiez les prochaines consignes.',
        'tone' => 'border-slate-200 bg-slate-50 text-slate-800',
    ];
}

function employeeExplanationCreatorSummary(array $request): array
{
    $status = (string) ($request['computed_status'] ?? $request['status'] ?? '');
    if (in_array($status, ['pending_response', 'complement_requested', 'overdue'], true)) {
        return [
            'title' => 'Demande en attente côté collaborateur',
            'message' => 'Le dossier est envoyé. Vous pouvez suivre les signatures, l’accusé de lecture et la réponse depuis cette vue.',
            'tone' => 'border-amber-200 bg-amber-50 text-amber-900',
        ];
    }
    if ($status === 'answered') {
        return [
            'title' => 'Réponse reçue',
            'message' => 'Le collaborateur a transmis ses explications. Le fil d’échanges et le PDF sont à jour.',
            'tone' => 'border-emerald-200 bg-emerald-50 text-emerald-900',
        ];
    }
    if ($status === 'closed') {
        return [
            'title' => 'Dossier clôturé',
            'message' => 'Le dossier reste consultable pour historique mais ne nécessite plus d’action.',
            'tone' => 'border-slate-200 bg-slate-100 text-slate-800',
        ];
    }
    if ($status === 'cancelled') {
        return [
            'title' => 'Dossier annulé',
            'message' => 'Aucune autre action n’est attendue.',
            'tone' => 'border-rose-200 bg-rose-50 text-rose-900',
        ];
    }

    return [
        'title' => 'Suivi du dossier',
        'message' => 'Consultez l’historique et l’état du dossier à tout moment.',
        'tone' => 'border-slate-200 bg-slate-50 text-slate-800',
    ];
}

$pageTitle = 'Mes explications';
$layoutRootBase = '../';
$activeEmployeeMenu = 'explications';

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>
<style>
    .employee-glass-card {
        background: var(--emp-bg);
        border: 1px solid var(--emp-line);
        box-shadow: none;
    }

    .employee-shell {
        border-radius: 12px;
        border: 1px solid var(--emp-line);
        background: var(--emp-bg);
        box-shadow: none;
    }

    .employee-step-card {
        border-radius: 12px;
        border: 1px solid var(--emp-line);
        background: var(--emp-bg);
        padding: 1.25rem;
    }

    .employee-step-index {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 2rem;
        height: 2rem;
        border-radius: 9999px;
        background: rgb(15 23 42);
        color: white;
        font-size: 0.8rem;
        font-weight: 700;
    }

    .employee-step-index.is-done {
        background: rgb(5 150 105);
    }

    .employee-sidebar-card {
        border-radius: 12px;
        border: 1px solid var(--emp-line);
        background: var(--emp-bg);
        padding: 1rem;
    }

    .employee-note {
        border-radius: 1.2rem;
        border: 1px dashed rgba(148, 163, 184, 0.55);
        background: var(--emp-bg);
        padding: 0.95rem 1rem;
        font-size: 0.88rem;
        color: rgb(71 85 105);
    }

    .signature-pad-shell {
        position: relative;
        border: 1px dashed rgba(148, 163, 184, 0.85);
        border-radius: 12px;
        background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
        overflow: hidden;
    }

    .signature-pad-shell canvas {
        width: 100%;
        height: 160px;
        display: block;
        cursor: crosshair;
        touch-action: none;
    }

    .signature-pad-hint {
        position: absolute;
        inset: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.9rem;
        color: rgb(100 116 139);
        pointer-events: none;
    }

    .employee-timeline-entry {
        border-radius: 12px;
        border: 1px solid var(--emp-line);
        background: var(--emp-bg);
        padding: 1rem 1rem 1.05rem;
    }

    .personnel-picker {
        position: relative;
    }

    .personnel-picker-input {
        width: 100%;
        border-radius: 1rem;
        border: 1px solid rgb(226 232 240);
        background: var(--emp-bg);
        padding: 0.85rem 1rem;
        font-size: 0.875rem;
        color: rgb(15 23 42);
        outline: none;
    }

    .personnel-picker-input:focus {
        border-color: rgb(79 70 229);
        background: var(--emp-bg);
    }

    .personnel-picker-panel {
        position: absolute;
        top: calc(100% + 0.45rem);
        left: 0;
        right: 0;
        z-index: 30;
        max-height: 18rem;
        overflow: auto;
        border-radius: 1rem;
        border: 1px solid var(--emp-line);
        background: var(--emp-bg);
        box-shadow: 0 16px 36px rgba(15, 23, 42, 0.12);
    }

    .personnel-picker-option {
        width: 100%;
        border: 0;
        border-bottom: 1px solid rgba(226, 232, 240, 0.82);
        background: var(--emp-bg);
        padding: 0.85rem 1rem;
        text-align: left;
        color: rgb(15 23 42);
    }

    .personnel-picker-option:hover {
        background: rgb(238 242 255);
    }

    .personnel-picker-empty {
        padding: 0.95rem 1rem;
        font-size: 0.82rem;
        color: rgb(100 116 139);
    }

    .employee-composer-card {
        border-radius: 12px;
        border: 1px solid var(--emp-line);
        background: var(--emp-bg);
        box-shadow: none;
    }
</style>
<div class="min-h-screen employee-explanation-page pb-12">
    <div class="mx-auto max-w-6xl px-4 py-8">
        <div class="employee-glass-card rounded-[32px] p-6">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.28em] text-indigo-600">Espace employé</p>
                    <h1 class="mt-2 text-2xl font-semibold text-slate-950">Mes demandes d’explications</h1>
                    <p class="mt-1 text-sm text-slate-600">Consultez vos dossiers reçus, et si votre fonction le permet, émettez aussi des demandes depuis cet espace.</p>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">Délai recommandé: répondre sous 3 jours ouvrés maximum, sauf indication contraire sur le dossier.</div>
            </div>

            <?php if (!empty($senderScope['can_send'])): ?>
                <section class="employee-composer-card mt-6 p-6">
                    <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                        <div>
                            <div class="text-xs font-semibold uppercase tracking-[0.24em] text-indigo-600">Nouvel envoi</div>
                            <h2 class="mt-2 text-2xl font-semibold text-slate-950">Nouvelle demande d’explications</h2>
                            <p class="mt-2 max-w-3xl text-sm text-slate-600">
                                <?php if (!empty($senderScope['is_global_scope'])): ?>
                                    Votre profil vous permet de cibler l’ensemble du personnel.
                                <?php else: ?>
                                    Votre périmètre est limité au service <?= htmlspecialchars((string) ($senderScope['service_label'] ?? 'Non renseigné')) ?>.
                                <?php endif; ?>
                            </p>
                        </div>
                        <div class="rounded-2xl border border-indigo-200 bg-white px-4 py-3 text-sm text-slate-700">
                            <div class="font-semibold text-slate-900"><?= htmlspecialchars((string) ($senderScope['fonction_label'] ?? 'Responsable')) ?></div>
                            <div class="mt-1 text-xs text-slate-500"><?= (int) count($creatablePersonnelChoices) ?> collaborateur(s) ciblable(s)</div>
                        </div>
                    </div>

                    <form method="post" class="mt-5 space-y-4" id="employee-create-request-form">
                        <input type="hidden" name="action" value="create_request">

                        <div class="grid gap-4 md:grid-cols-2">
                            <div class="md:col-span-2">
                                <label class="mb-2 block text-sm font-medium text-slate-700">Collaborateur concerné</label>
                                <div class="personnel-picker">
                                    <input type="hidden" name="target_personnel_id" id="employee-target-personnel-id" value="<?= htmlspecialchars((string) ($createDraft['target_personnel_id'] ?? '')) ?>">
                                    <input type="text" id="employee-target-personnel-search" class="personnel-picker-input" value="<?= htmlspecialchars($selectedCreatePersonnelLabel) ?>" placeholder="Rechercher un collaborateur autorisé" autocomplete="off" required>
                                    <div id="employee-target-personnel-menu" class="personnel-picker-panel" hidden></div>
                                </div>
                                <div class="mt-2 text-xs text-slate-500">La liste est filtrée automatiquement selon votre rôle et votre service.</div>
                            </div>
                            <div>
                                <label class="mb-2 block text-sm font-medium text-slate-700">Objet</label>
                                <input type="text" name="subject" value="<?= htmlspecialchars((string) ($createDraft['subject'] ?? '')) ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none focus:border-indigo-400" placeholder="Ex: Demande d’explications sur retard chantier" required>
                            </div>
                            <div>
                                <label class="mb-2 block text-sm font-medium text-slate-700">Délai de réponse</label>
                                <input type="number" min="1" max="15" name="due_days" value="<?= htmlspecialchars((string) (($createDraft['due_days'] ?? '') !== '' ? $createDraft['due_days'] : (string) ((int) ($settings['response_due_days'] ?? 3)))) ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none focus:border-indigo-400">
                            </div>
                            <div>
                                <label class="mb-2 block text-sm font-medium text-slate-700">Date ou période concernée</label>
                                <input type="text" name="incident_date" value="<?= htmlspecialchars((string) ($createDraft['incident_date'] ?? '')) ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none focus:border-indigo-400" placeholder="Ex: semaine du 10 avril">
                            </div>
                            <div>
                                <label class="mb-2 block text-sm font-medium text-slate-700">Lieu ou cadre concerné</label>
                                <input type="text" name="incident_place" value="<?= htmlspecialchars((string) ($createDraft['incident_place'] ?? '')) ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none focus:border-indigo-400" placeholder="Ex: chantier, service, mission">
                            </div>
                        </div>

                        <div class="grid gap-4">
                            <div>
                                <label class="mb-2 block text-sm font-medium text-slate-700"><?= htmlspecialchars((string) ($settings['template_context_label'] ?? 'Contexte de la demande')) ?></label>
                                <textarea name="context_summary" rows="4" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none focus:border-indigo-400" placeholder="Rappelez brièvement le contexte général de la demande."><?= htmlspecialchars((string) ($createDraft['context_summary'] ?? '')) ?></textarea>
                            </div>
                            <div>
                                <label class="mb-2 block text-sm font-medium text-slate-700"><?= htmlspecialchars((string) ($settings['template_facts_label'] ?? 'Faits constatés')) ?></label>
                                <textarea name="observed_facts" rows="5" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none focus:border-indigo-400" placeholder="Décrivez les faits de façon précise." required><?= htmlspecialchars((string) ($createDraft['observed_facts'] ?? '')) ?></textarea>
                            </div>
                            <div>
                                <label class="mb-2 block text-sm font-medium text-slate-700"><?= htmlspecialchars((string) ($settings['template_expectations_label'] ?? 'Explications attendues')) ?></label>
                                <textarea name="expected_response" rows="4" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none focus:border-indigo-400" placeholder="Précisez les points attendus dans la réponse." required><?= htmlspecialchars((string) ($createDraft['expected_response'] ?? '')) ?></textarea>
                            </div>
                            <div>
                                <label class="mb-2 block text-sm font-medium text-slate-700"><?= htmlspecialchars((string) ($settings['template_supporting_label'] ?? 'Éléments complémentaires')) ?></label>
                                <textarea name="supporting_elements" rows="4" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none focus:border-indigo-400" placeholder="Pièces, témoins, consignes, éléments utiles."><?= htmlspecialchars((string) ($createDraft['supporting_elements'] ?? '')) ?></textarea>
                            </div>
                        </div>

                        <button type="submit" class="inline-flex items-center rounded-2xl bg-indigo-600 px-5 py-3 text-sm font-semibold text-white hover:bg-indigo-700"><i class="fas fa-paper-plane mr-2"></i>Envoyer la demande</button>
                    </form>
                </section>
            <?php elseif (($senderScope['reason'] ?? '') !== ''): ?>
                <div class="mt-6 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4 text-sm text-slate-700">
                    <?= htmlspecialchars((string) $senderScope['reason']) ?>
                </div>
            <?php endif; ?>

            <div class="mt-6 grid gap-4 md:grid-cols-4">
                <div class="rounded-[24px] border border-slate-200 bg-white p-5">
                    <div class="text-xs font-semibold uppercase tracking-[0.24em] text-slate-500">Dossiers reçus</div>
                    <div class="mt-3 text-3xl font-semibold text-slate-950"><?= (int) $employeeStats['total'] ?></div>
                </div>
                <div class="rounded-[24px] border border-amber-200 bg-amber-50/80 p-5">
                    <div class="text-xs font-semibold uppercase tracking-[0.24em] text-amber-700">À traiter</div>
                    <div class="mt-3 text-3xl font-semibold text-amber-700"><?= (int) $employeeStats['pending'] ?></div>
                </div>
                <div class="rounded-[24px] border border-emerald-200 bg-emerald-50/80 p-5">
                    <div class="text-xs font-semibold uppercase tracking-[0.24em] text-emerald-700">Réponses / clôtures</div>
                    <div class="mt-3 text-3xl font-semibold text-emerald-700"><?= (int) ($employeeStats['answered'] + $employeeStats['closed']) ?></div>
                </div>
                <div class="rounded-[24px] border border-indigo-200 bg-indigo-50/80 p-5">
                    <div class="text-xs font-semibold uppercase tracking-[0.24em] text-indigo-700">Demandes envoyées</div>
                    <div class="mt-3 text-3xl font-semibold text-indigo-700"><?= (int) $employeeStats['created'] ?></div>
                </div>
            </div>

            <?php if ($statusType !== null && $statusMessage !== null): ?>
                <div class="mt-5 rounded-2xl border px-4 py-4 text-sm <?= $statusType === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-900' : 'border-rose-200 bg-rose-50 text-rose-900' ?>">
                    <?= htmlspecialchars((string) $statusMessage) ?>
                </div>
            <?php endif; ?>

            <div class="mt-6 grid gap-6 xl:grid-cols-[0.34fr_0.66fr]">
                <div class="space-y-4">
                    <div class="employee-sidebar-card">
                        <div class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">Mes dossiers</div>
                        <div class="mt-2 text-sm text-slate-600">Choisissez un dossier reçu pour répondre, ou une demande envoyée pour suivre le retour du collaborateur.</div>
                    </div>

                    <div class="space-y-4 max-h-[920px] overflow-auto pr-1">
                        <div>
                            <div class="mb-2 text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">Demandes reçues</div>
                            <div class="space-y-3">
                                <?php foreach ($requests as $request): ?>
                                    <?php $badge = employeeExplanationBadge($request); ?>
                                    <a href="mes_explications_employe.php?id=<?= (int) $request['id'] ?>" class="block rounded-3xl border px-4 py-4 transition <?= $selectedRequest && (int) $selectedRequest['id'] === (int) $request['id'] ? 'border-indigo-300 bg-indigo-50/70' : 'border-slate-200 bg-slate-50 hover:border-slate-300 hover:bg-white' ?>">
                                        <div class="flex items-start justify-between gap-3">
                                            <div>
                                                <div class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-500"><?= htmlspecialchars((string) ($request['request_ref'] ?? '')) ?></div>
                                                <div class="mt-2 text-base font-semibold text-slate-900"><?= htmlspecialchars((string) ($request['subject'] ?? '')) ?></div>
                                                <div class="mt-1 text-sm text-slate-600"><?= htmlspecialchars((string) ($request['target_service_label'] ?? '')) ?></div>
                                            </div>
                                            <span class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold <?= $badge['class'] ?>"><?= htmlspecialchars($badge['label']) ?></span>
                                        </div>
                                        <div class="mt-3 text-xs text-slate-500">Échéance: <?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) ($request['due_at'] ?? 'now')))) ?></div>
                                    </a>
                                <?php endforeach; ?>
                                <?php if (!$requests): ?>
                                    <div class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-6 text-sm text-slate-500">Aucune demande reçue.</div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if (!empty($senderScope['can_send'])): ?>
                            <div>
                                <div class="mb-2 text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">Demandes envoyées par moi</div>
                                <div class="space-y-3">
                                    <?php foreach ($createdRequests as $request): ?>
                                        <?php $badge = employeeExplanationBadge($request); ?>
                                        <a href="mes_explications_employe.php?id=<?= (int) $request['id'] ?>" class="block rounded-3xl border px-4 py-4 transition <?= $selectedRequest && (int) $selectedRequest['id'] === (int) $request['id'] ? 'border-indigo-300 bg-indigo-50/70' : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50' ?>">
                                            <div class="flex items-start justify-between gap-3">
                                                <div>
                                                    <div class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-500"><?= htmlspecialchars((string) ($request['request_ref'] ?? '')) ?></div>
                                                    <div class="mt-2 text-base font-semibold text-slate-900"><?= htmlspecialchars((string) ($request['subject'] ?? '')) ?></div>
                                                    <div class="mt-1 text-sm text-slate-600">Cible: <?= htmlspecialchars((string) ($request['target_name'] ?? '')) ?></div>
                                                </div>
                                                <span class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold <?= $badge['class'] ?>"><?= htmlspecialchars($badge['label']) ?></span>
                                            </div>
                                            <div class="mt-3 text-xs text-slate-500">Créée le <?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) ($request['created_at'] ?? 'now')))) ?></div>
                                        </a>
                                    <?php endforeach; ?>
                                    <?php if (!$createdRequests): ?>
                                        <div class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-6 text-sm text-slate-500">Aucune demande envoyée pour le moment.</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div>
                    <?php if (!$selectedRequest): ?>
                        <div class="rounded-3xl border border-dashed border-slate-300 bg-slate-50 px-8 py-16 text-center text-slate-500">Aucune demande d’explications à afficher.</div>
                    <?php else: ?>
                        <?php $selectedBadge = employeeExplanationBadge($selectedRequest); ?>
                        <?php $employeeSignatureUrl = employeeExplanationSignatureAsset((int) ($selectedRequest['id'] ?? 0), 'employee', $selectedRequest['employee_signature_path'] ?? ''); ?>
                        <?php $actionSummary = $selectedIsOwner ? employeeExplanationActionSummary($selectedRequest) : employeeExplanationCreatorSummary($selectedRequest); ?>

                        <div class="employee-shell px-5 py-5">
                            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                <div>
                                    <div class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-500"><?= htmlspecialchars((string) ($selectedRequest['request_ref'] ?? '')) ?></div>
                                    <h2 class="mt-2 text-2xl font-semibold text-slate-950"><?= htmlspecialchars((string) ($selectedRequest['subject'] ?? '')) ?></h2>
                                    <div class="mt-2 text-sm text-slate-600">Demandée le <?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) ($selectedRequest['created_at'] ?? 'now')))) ?></div>
                                    <div class="mt-1 text-sm text-slate-600">Réponse attendue avant le <?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) ($selectedRequest['due_at'] ?? 'now')))) ?></div>
                                    <?php if ($selectedIsCreator && !$selectedIsOwner): ?>
                                        <div class="mt-1 text-sm text-slate-600">Collaborateur ciblé: <?= htmlspecialchars((string) ($selectedRequest['target_name'] ?? '')) ?>, <?= htmlspecialchars((string) ($selectedRequest['target_service_label'] ?? 'Service non renseigné')) ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="flex flex-col items-start gap-3 lg:items-end">
                                    <span class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold <?= $selectedBadge['class'] ?>"><?= htmlspecialchars($selectedBadge['label']) ?></span>
                                    <div class="flex flex-wrap gap-2">
                                        <a href="../request/export_rh_explanation_pdf.php?id=<?= (int) $selectedRequest['id'] ?>" target="_blank" class="inline-flex items-center rounded-2xl border border-slate-200 bg-white px-4 py-2 text-xs font-semibold text-slate-700 hover:border-indigo-300 hover:text-indigo-700"><i class="fas fa-eye mr-2"></i>Aperçu PDF</a>
                                        <a href="../request/export_rh_explanation_pdf.php?id=<?= (int) $selectedRequest['id'] ?>&download=1" class="inline-flex items-center rounded-2xl bg-slate-950 px-4 py-2 text-xs font-semibold text-white hover:bg-slate-800"><i class="fas fa-file-pdf mr-2"></i>Télécharger</a>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-4 rounded-2xl border px-4 py-4 <?= $actionSummary['tone'] ?>">
                                <div class="text-sm font-semibold"><?= htmlspecialchars($actionSummary['title']) ?></div>
                                <div class="mt-1 text-sm opacity-90"><?= htmlspecialchars($actionSummary['message']) ?></div>
                            </div>

                            <div class="mt-5 space-y-4">
                                <?php if ($selectedIsCreator && !$selectedIsOwner): ?>
                                    <section class="employee-step-card">
                                        <div class="flex items-start gap-3">
                                            <span class="employee-step-index is-done">S</span>
                                            <div class="flex-1">
                                                <div class="text-lg font-semibold text-slate-950">Suivi du dossier envoyé</div>
                                                <div class="mt-1 text-sm text-slate-600">Vous consultez ici le suivi de la demande que vous avez émise depuis l’espace employé.</div>

                                                <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                                    <div class="rounded-2xl border border-slate-200 bg-white p-4">
                                                        <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Cible</div>
                                                        <div class="mt-2 text-sm font-semibold text-slate-900"><?= htmlspecialchars((string) ($selectedRequest['target_name'] ?? '')) ?></div>
                                                    </div>
                                                    <div class="rounded-2xl border border-slate-200 bg-white p-4">
                                                        <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Service</div>
                                                        <div class="mt-2 text-sm font-semibold text-slate-900"><?= htmlspecialchars((string) ($selectedRequest['target_service_label'] ?? 'Non renseigné')) ?></div>
                                                    </div>
                                                    <div class="rounded-2xl border border-slate-200 bg-white p-4">
                                                        <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Signature employé</div>
                                                        <div class="mt-2 text-sm font-semibold text-slate-900"><?= !empty($selectedRequest['employee_signed_at']) ? 'Enregistrée' : 'En attente' ?></div>
                                                    </div>
                                                    <div class="rounded-2xl border border-slate-200 bg-white p-4">
                                                        <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Accusé de lecture</div>
                                                        <div class="mt-2 text-sm font-semibold text-slate-900"><?= !empty($selectedRequest['employee_read_ack_at']) ? 'Effectué' : 'En attente' ?></div>
                                                    </div>
                                                </div>

                                                <?php if ($employeeSignatureUrl): ?>
                                                    <div class="mt-4 rounded-2xl border border-slate-200 bg-white p-4">
                                                        <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Signature du collaborateur</div>
                                                        <div class="mt-3 flex min-h-[92px] items-center justify-center rounded-2xl border border-dashed border-slate-200 bg-slate-50">
                                                            <img src="<?= htmlspecialchars($employeeSignatureUrl) ?>" alt="Signature employé" style="max-width:100%; max-height:92px; object-fit:contain;">
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </section>
                                <?php endif; ?>

                                <?php if ($selectedIsOwner): ?>
                                    <section class="employee-step-card">
                                        <div class="flex items-start gap-3">
                                            <span class="employee-step-index is-done">1</span>
                                            <div class="flex-1">
                                                <div class="text-lg font-semibold text-slate-950">Comprendre le dossier</div>
                                                <div class="mt-1 text-sm text-slate-600">Relisez les informations essentielles avant toute action.</div>

                                                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                                                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                                        <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Signature RH</div>
                                                        <div class="mt-2 text-sm font-semibold text-slate-900"><?= !empty($selectedRequest['rh_signed_at']) ? 'Déjà intégrée au document' : 'En attente côté RH' ?></div>
                                                        <div class="mt-1 text-xs text-slate-500"><?= htmlspecialchars((string) (($selectedRequest['rh_signed_by_name'] ?? '') !== '' ? $selectedRequest['rh_signed_by_name'] : 'Le dossier sera visé côté RH.')) ?></div>
                                                    </div>
                                                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                                        <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Votre signature</div>
                                                        <div class="mt-2 text-sm font-semibold text-slate-900"><?= !empty($selectedRequest['employee_signed_at']) ? 'Enregistrée le ' . htmlspecialchars(date('d/m/Y H:i', strtotime((string) $selectedRequest['employee_signed_at']))) : 'À enregistrer' ?></div>
                                                        <div class="mt-1 text-xs text-slate-500">Elle apparaîtra immédiatement dans le PDF officiel.</div>
                                                    </div>
                                                </div>

                                                <div class="mt-4 grid gap-3 sm:grid-cols-3">
                                                    <div class="rounded-2xl border border-slate-200 bg-white p-4">
                                                        <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Référence</div>
                                                        <div class="mt-2 text-sm font-semibold text-slate-900"><?= htmlspecialchars((string) ($selectedRequest['request_ref'] ?? '')) ?></div>
                                                    </div>
                                                    <div class="rounded-2xl border border-slate-200 bg-white p-4">
                                                        <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Périmètre</div>
                                                        <div class="mt-2 text-sm font-semibold text-slate-900"><?= htmlspecialchars((string) ($selectedRequest['target_service_label'] ?? 'Non renseigné')) ?></div>
                                                    </div>
                                                    <div class="rounded-2xl border border-slate-200 bg-white p-4">
                                                        <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Échéance</div>
                                                        <div class="mt-2 text-sm font-semibold text-slate-900"><?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) ($selectedRequest['due_at'] ?? 'now')))) ?></div>
                                                    </div>
                                                </div>

                                                <div class="mt-4 employee-note">Bon ordre de traitement: lisez la demande, enregistrez votre signature, puis transmettez une réponse claire et structurée.</div>
                                            </div>
                                        </div>
                                    </section>
                                <?php endif; ?>

                                <?php if ($selectedIsOwner && (string) ($selectedRequest['status'] ?? '') !== 'cancelled'): ?>
                                    <form method="post" class="js-signature-pad employee-step-card">
                                        <input type="hidden" name="action" value="save_employee_signature">
                                        <input type="hidden" name="request_id" value="<?= (int) $selectedRequest['id'] ?>">
                                        <input type="hidden" name="signature_base64" value="" class="js-signature-value">
                                        <div class="flex items-start gap-3">
                                            <span class="employee-step-index <?= !empty($selectedRequest['employee_signed_at']) ? 'is-done' : '' ?>">2</span>
                                            <div class="flex-1">
                                                <div class="text-lg font-semibold text-slate-900">Signer le dossier</div>
                                                <div class="mt-1 text-sm text-slate-600">Dessinez votre signature pour qu’elle soit intégrée au document PDF officiel du dossier.</div>
                                                <div class="mt-3 rounded-2xl border border-slate-200 bg-white px-4 py-3 text-xs font-medium text-slate-600 js-signature-status">Aucune signature capturée</div>
                                                <div class="mt-4 signature-pad-shell">
                                                    <canvas class="js-signature-canvas"></canvas>
                                                    <div class="signature-pad-hint js-signature-hint">Signez ici avec la souris ou le doigt</div>
                                                </div>
                                                <div class="mt-4 flex flex-wrap gap-2">
                                                    <button type="button" class="js-signature-clear inline-flex items-center rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:border-slate-300"><i class="fas fa-eraser mr-2"></i>Effacer</button>
                                                    <button type="button" class="js-signature-use inline-flex items-center rounded-2xl border border-indigo-200 bg-indigo-50 px-4 py-2 text-sm font-semibold text-indigo-700 hover:bg-indigo-100"><i class="fas fa-pen-nib mr-2"></i>Utiliser la signature</button>
                                                    <button type="submit" class="inline-flex items-center rounded-2xl bg-slate-950 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800"><i class="fas fa-save mr-2"></i>Enregistrer ma signature</button>
                                                </div>
                                                <div class="mt-4 rounded-2xl border border-slate-200 bg-white p-4">
                                                    <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Signature enregistrée</div>
                                                    <div class="js-signature-preview mt-3 flex min-h-[92px] items-center justify-center rounded-2xl border border-dashed border-slate-200 bg-slate-50 text-sm text-slate-500" data-empty-label="Aucune signature enregistrée">
                                                        <?php if ($employeeSignatureUrl): ?>
                                                            <img src="<?= htmlspecialchars($employeeSignatureUrl) ?>" alt="Signature employé" style="max-width:100%; max-height:92px; object-fit:contain;">
                                                        <?php else: ?>
                                                            Aucune signature enregistrée
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </form>
                                <?php elseif ($selectedIsOwner): ?>
                                    <div class="employee-step-card border-rose-200 bg-rose-50 text-rose-900">
                                        <div class="flex items-start gap-3">
                                            <span class="employee-step-index">2</span>
                                            <div>
                                                <div class="text-lg font-semibold">Signature non modifiable</div>
                                                <div class="mt-1 text-sm">Ce dossier est annulé. La signature manuscrite n’est plus modifiable.</div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if ($selectedIsOwner && in_array((string) ($selectedRequest['status'] ?? ''), ['pending_response', 'complement_requested'], true)): ?>
                                    <form method="post" class="employee-step-card border-indigo-200">
                                        <input type="hidden" name="action" value="send_response">
                                        <input type="hidden" name="request_id" value="<?= (int) $selectedRequest['id'] ?>">
                                        <div class="flex items-start gap-3">
                                            <span class="employee-step-index <?= !empty($selectedRequest['answered_at']) ? 'is-done' : '' ?>">3</span>
                                            <div class="flex-1">
                                                <div class="text-lg font-semibold text-slate-900">Transmettre ma réponse</div>
                                                <div class="text-sm text-slate-600"><?= htmlspecialchars((string) ($settings['employee_response_hint'] ?? 'Expliquez les faits de manière précise, chronologique et factuelle.')) ?></div>
                                                <div class="mt-3 employee-note">Conseil: structurez votre réponse en contexte, faits, précisions, puis engagement ou éléments complémentaires.</div>
                                                <textarea name="response_html" class="mt-4 min-h-[220px] w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 text-sm leading-7 text-slate-800 outline-none focus:border-indigo-400"><?= htmlspecialchars($responseDraft !== '' ? $responseDraft : "Bonjour,\n\nVeuillez trouver ci-dessous mes explications :\n\n- \n\nCordialement.") ?></textarea>
                                                <button type="submit" class="mt-4 inline-flex items-center rounded-2xl bg-indigo-600 px-5 py-3 text-sm font-semibold text-white hover:bg-indigo-700"><i class="fas fa-paper-plane mr-2"></i>Transmettre ma réponse</button>
                                            </div>
                                        </div>
                                    </form>
                                <?php elseif ($selectedIsOwner && (string) ($selectedRequest['status'] ?? '') === 'answered'): ?>
                                    <div class="employee-step-card border-emerald-200 bg-emerald-50">
                                        <div class="flex items-start gap-3">
                                            <span class="employee-step-index is-done">3</span>
                                            <div>
                                                <div class="text-lg font-semibold text-emerald-900">Réponse déjà transmise</div>
                                                <div class="mt-1 text-sm text-emerald-900/80">Votre réponse est bien enregistrée. Vous pouvez désormais simplement suivre l’avancement du dossier.</div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if ($selectedIsOwner && (string) ($selectedRequest['status'] ?? '') !== 'cancelled'): ?>
                                    <form method="post" class="employee-step-card border-sky-200 bg-sky-50/60">
                                        <input type="hidden" name="action" value="request_rectification">
                                        <input type="hidden" name="request_id" value="<?= (int) $selectedRequest['id'] ?>">
                                        <div class="flex items-start gap-3">
                                            <span class="employee-step-index">R</span>
                                            <div class="flex-1">
                                                <div class="text-lg font-semibold text-slate-900">Demander une rectification</div>
                                                <div class="mt-1 text-sm text-slate-600">Utilisez ce bouton si vous constatez une erreur matérielle dans le dossier: nom, date, lieu, fait mal retranscrit ou pièce associée incorrecte.</div>
                                                <div class="mt-3 employee-note">Cette demande n'efface pas votre réponse. Elle ouvre une alerte RH traçable pour correction éventuelle.</div>
                                                <textarea name="rectification_html" class="mt-4 min-h-[160px] w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 text-sm leading-7 text-slate-800 outline-none focus:border-sky-400"><?= htmlspecialchars($rectificationDraft !== '' ? $rectificationDraft : "Bonjour,\n\nJe souhaite demander la rectification des éléments suivants :\n\n- \n\nMerci.") ?></textarea>
                                                <button type="submit" class="mt-4 inline-flex items-center rounded-2xl bg-sky-600 px-5 py-3 text-sm font-semibold text-white hover:bg-sky-700"><i class="fas fa-edit mr-2"></i>Demander rectification</button>
                                            </div>
                                        </div>
                                    </form>
                                <?php endif; ?>

                                <section class="employee-step-card">
                                    <div class="flex items-start gap-3">
                                        <span class="employee-step-index is-done">4</span>
                                        <div class="flex-1">
                                            <div class="text-lg font-semibold text-slate-950">Suivre les échanges</div>
                                            <div class="mt-1 text-sm text-slate-600">Retrouvez ici la demande initiale, les réponses et les retours RH.</div>

                                            <div class="mt-4 space-y-3">
                                                <?php foreach ($messages as $message): ?>
                                                    <?php
                                                    $isEmployee = (string) ($message['author_role'] ?? '') === 'employee';
                                                    $tone = $isEmployee ? 'border-indigo-200 bg-indigo-50/70' : 'border-emerald-200 bg-emerald-50/70';
                                                    ?>
                                                    <article class="employee-timeline-entry border <?= $tone ?>">
                                                        <div class="flex items-center justify-between gap-3">
                                                            <div class="text-sm font-semibold text-slate-900"><?= htmlspecialchars((string) ($message['author_name'] ?? ($isEmployee ? 'Vous' : 'RH'))) ?></div>
                                                            <div class="text-xs text-slate-500"><?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) ($message['created_at'] ?? 'now')))) ?></div>
                                                        </div>
                                                        <div class="mt-1 text-xs uppercase tracking-[0.22em] text-slate-500"><?= htmlspecialchars((string) ($message['message_kind'] ?? 'message')) ?></div>
                                                        <div class="prose prose-sm mt-3 max-w-none text-slate-800"><?= (string) ($message['body_html'] ?? '') ?></div>
                                                    </article>
                                                <?php endforeach; ?>
                                                <?php if (!$messages): ?>
                                                    <div class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-6 text-sm text-slate-500">Aucun échange à afficher pour ce dossier.</div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </section>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <script src="../js/rh_explanation_signature_pad.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const personnelChoices = <?= json_encode(array_map(static function (array $person): array {
                                            $fullName = trim((string) ($person['full_name'] ?? ''));
                                            $email = trim((string) ($person['email'] ?? ''));
                                            $serviceLabel = trim((string) ($person['service_label'] ?? ''));
                                            $displayLabel = $fullName !== '' ? $fullName : ($email !== '' ? $email : ('Employé #' . (int) ($person['id'] ?? 0)));
                                            $searchText = trim(implode(' ', array_filter([$displayLabel, $email, $serviceLabel])));

                                            return [
                                                'id' => (int) ($person['id'] ?? 0),
                                                'label' => $displayLabel,
                                                'meta' => $serviceLabel,
                                                'email' => $email,
                                                'search' => $searchText,
                                            ];
                                        }, $creatablePersonnelChoices), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
            const personnelSearchInput = document.getElementById('employee-target-personnel-search');
            const personnelHiddenInput = document.getElementById('employee-target-personnel-id');
            const personnelMenu = document.getElementById('employee-target-personnel-menu');
            const creationForm = document.getElementById('employee-create-request-form');

            function normalizeValue(value) {
                return String(value || '')
                    .normalize('NFD')
                    .replace(/[\u0300-\u036f]/g, '')
                    .toLowerCase()
                    .trim();
            }

            function escapeHtml(value) {
                return String(value || '')
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }

            function closePersonnelMenu() {
                if (personnelMenu) {
                    personnelMenu.hidden = true;
                }
            }

            function selectPersonnel(choice) {
                if (!personnelSearchInput || !personnelHiddenInput) {
                    return;
                }

                personnelSearchInput.value = choice.label;
                personnelHiddenInput.value = String(choice.id);
                personnelSearchInput.setCustomValidity('');
                closePersonnelMenu();
            }

            function renderPersonnelOptions() {
                if (!personnelSearchInput || !personnelMenu) {
                    return;
                }

                const query = normalizeValue(personnelSearchInput.value);
                const filteredChoices = personnelChoices.filter(function(choice) {
                    return query === '' || normalizeValue(choice.search || choice.label).includes(query);
                }).slice(0, 30);

                personnelMenu.innerHTML = '';

                if (!filteredChoices.length) {
                    const emptyState = document.createElement('div');
                    emptyState.className = 'personnel-picker-empty';
                    emptyState.textContent = 'Aucun collaborateur autorisé ne correspond à la recherche.';
                    personnelMenu.appendChild(emptyState);
                } else {
                    filteredChoices.forEach(function(choice) {
                        const option = document.createElement('button');
                        option.type = 'button';
                        option.className = 'personnel-picker-option';
                        option.innerHTML = [choice.label, choice.meta, choice.email]
                            .filter(Boolean)
                            .map(function(value, index) {
                                if (index === 0) {
                                    return '<div style="font-weight:600;">' + escapeHtml(value) + '</div>';
                                }
                                return '<div style="font-size:0.78rem;opacity:0.78;">' + escapeHtml(value) + '</div>';
                            })
                            .join('');
                        option.addEventListener('click', function() {
                            selectPersonnel(choice);
                        });
                        personnelMenu.appendChild(option);
                    });
                }

                personnelMenu.hidden = false;
            }

            function syncPersonnelSelection() {
                if (!personnelSearchInput || !personnelHiddenInput) {
                    return;
                }

                const query = normalizeValue(personnelSearchInput.value);
                const exactMatch = personnelChoices.find(function(choice) {
                    return normalizeValue(choice.label) === query;
                });

                personnelHiddenInput.value = exactMatch ? String(exactMatch.id) : '';
                personnelSearchInput.setCustomValidity('');
            }

            if (personnelSearchInput && personnelHiddenInput && personnelMenu) {
                personnelSearchInput.addEventListener('focus', renderPersonnelOptions);
                personnelSearchInput.addEventListener('input', function() {
                    syncPersonnelSelection();
                    renderPersonnelOptions();
                });
                personnelSearchInput.addEventListener('keydown', function(event) {
                    if (event.key === 'Escape') {
                        closePersonnelMenu();
                    }
                });
                document.addEventListener('click', function(event) {
                    if (!event.target.closest('.personnel-picker')) {
                        closePersonnelMenu();
                    }
                });
            }

            if (creationForm && personnelSearchInput && personnelHiddenInput) {
                creationForm.addEventListener('submit', function(event) {
                    if (!personnelHiddenInput.value) {
                        event.preventDefault();
                        personnelSearchInput.setCustomValidity('Sélectionnez un collaborateur dans la liste.');
                        personnelSearchInput.reportValidity();
                        renderPersonnelOptions();
                    }
                });
            }
        });
    </script>
    <?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>