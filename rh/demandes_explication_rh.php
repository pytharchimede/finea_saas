<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../controllers/RhExplanationController.php';

$pdo = \Database::getConnection();
$rhExplanationController = new \RhExplanationController($pdo);
$manager = $rhExplanationController->service();

$actor = [
    'user_id' => (int) ($_SESSION['user_id'] ?? 0),
    'name' => trim((string) ($_SESSION['user_name'] ?? (($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? '')))),
    'email' => trim((string) ($_SESSION['mail_pro'] ?? '')),
    'role' => function_exists('hasPermission') && hasPermission('acces_admin') ? 'admin' : 'rh',
    'ip' => trim((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '')),
    'is_admin' => function_exists('hasPermission') && hasPermission('acces_admin'),
];

$canAdminDeleteRequest = !empty($actor['is_admin']);

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
    'is_cadre_direct' => !empty($_POST['is_cadre_direct']) ? '1' : '',
];
$closureDraft = [
    'fault_level_id' => (string) ($_POST['fault_level_id'] ?? ''),
    'sanction_level_id' => (string) ($_POST['sanction_level_id'] ?? ''),
    'message_html' => (string) ($_POST['message_html'] ?? ''),
];
$deleteDraft = [
    'reason' => (string) ($_POST['delete_reason'] ?? ''),
];

$filter = trim((string) ($_GET['status'] ?? 'open'));
if (!in_array($filter, ['open', 'closed', 'cancelled', 'all'], true)) {
    $filter = 'open';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postResult = $rhExplanationController->handleRequestPost($_POST, $actor, $filter);
    if (!empty($postResult['redirect'])) {
        header('Location: ' . $postResult['redirect']);
        exit;
    }
    $statusType = $postResult['statusType'];
    $statusMessage = $postResult['statusMessage'];
}
if ($statusMessage === null) {
    if (isset($_GET['created'])) {
        $statusType = 'success';
        $statusMessage = 'Demande d’explications envoyée.';
    } elseif (isset($_GET['updated'])) {
        $statusType = 'success';
        $statusMessage = 'Complément demandé avec succès.';
    } elseif (isset($_GET['closed'])) {
        $statusType = 'success';
        $statusMessage = 'Demande clôturée.';
    } elseif (isset($_GET['cancelled'])) {
        $statusType = 'success';
        $statusMessage = 'Demande annulée.';
    } elseif (isset($_GET['signed'])) {
        $statusType = 'success';
        $statusMessage = 'Signature RH enregistrée sur le dossier.';
    } elseif (isset($_GET['resent'])) {
        $statusType = 'success';
        $statusMessage = 'Le PDF a été renvoyé au collaborateur sans recréer la demande.';
    } elseif (isset($_GET['deleted'])) {
        $statusType = 'success';
        $statusMessage = 'La demande d’explications a été supprimée par un administrateur. Une trace complète a été archivée.';
    }
}

$requests = $manager->listRequestsForRh($filter, 300);
$selectedId = max(0, (int) ($_GET['id'] ?? 0));
$selectedRequest = $selectedId > 0 ? $manager->getRequestById($selectedId) : ($requests[0] ?? null);
$messages = $selectedRequest ? $manager->listMessages((int) $selectedRequest['id']) : [];
$latestRectificationMessage = null;
for ($messageIndex = count($messages) - 1; $messageIndex >= 0; $messageIndex--) {
    if ((string) ($messages[$messageIndex]['message_kind'] ?? '') === 'rectification_request') {
        $latestRectificationMessage = $messages[$messageIndex];
        break;
    }
}
$personnelChoices = $manager->getPersonnelChoices('', 2500);
$selectedPersonnelLabel = '';
foreach ($personnelChoices as $personChoice) {
    $personChoiceId = (int) ($personChoice['id'] ?? 0);
    if ((string) $personChoiceId === (string) ($createDraft['target_personnel_id'] ?? '')) {
        $selectedPersonnelLabel = trim((string) ($personChoice['full_name'] ?? ''));
        if ($selectedPersonnelLabel === '') {
            $selectedPersonnelLabel = trim((string) (($personChoice['email'] ?? '') !== '' ? $personChoice['email'] : ('Employé #' . $personChoiceId)));
        }
        break;
    }
}
$settings = $manager->getSettings();
$faultLevels = $manager->listFaultLevels(true);
$sanctionLevels = $manager->listSanctionLevels(true);
$faultDefaultSanctions = [];
foreach ($faultLevels as $faultLevel) {
    $faultDefaultSanctions[(int) ($faultLevel['id'] ?? 0)] = (int) ($faultLevel['default_sanction_level_id'] ?? 0);
}
$rhStats = [
    'total' => count($requests),
    'pending' => 0,
    'answered' => 0,
    'overdue' => 0,
];
foreach ($requests as $requestRow) {
    $computedStatus = (string) ($requestRow['computed_status'] ?? $requestRow['status'] ?? '');
    if (in_array($computedStatus, ['pending_response', 'complement_requested'], true)) {
        $rhStats['pending']++;
    }
    if ($computedStatus === 'answered') {
        $rhStats['answered']++;
    }
    if ($computedStatus === 'overdue') {
        $rhStats['overdue']++;
    }
}

$previewSettings = [
    'intro' => (string) ($settings['template_intro'] ?? ''),
    'contextLabel' => (string) ($settings['template_context_label'] ?? ''),
    'factsLabel' => (string) ($settings['template_facts_label'] ?? ''),
    'expectationsLabel' => (string) ($settings['template_expectations_label'] ?? ''),
    'supportingLabel' => (string) ($settings['template_supporting_label'] ?? ''),
    'closing' => (string) ($settings['template_closing'] ?? ''),
];

function rhExplanationBadge(array $request): array
{
    $status = (string) ($request['computed_status'] ?? $request['status'] ?? '');
    $map = [
        'pending_response' => ['label' => 'En attente', 'class' => 'bg-amber-100 text-amber-800 border-amber-200'],
        'complement_requested' => ['label' => 'Complément demandé', 'class' => 'bg-sky-100 text-sky-800 border-sky-200'],
        'answered' => ['label' => 'Réponse reçue', 'class' => 'bg-emerald-100 text-emerald-800 border-emerald-200'],
        'closed' => ['label' => 'Clôturée', 'class' => 'bg-slate-100 text-slate-700 border-slate-200'],
        'cancelled' => ['label' => 'Annulée', 'class' => 'bg-rose-100 text-rose-800 border-rose-200'],
        'overdue' => ['label' => 'En retard', 'class' => 'bg-rose-100 text-rose-800 border-rose-200'],
    ];

    return $map[$status] ?? ['label' => $status, 'class' => 'bg-slate-100 text-slate-700 border-slate-200'];
}

function rhExplanationSignatureAsset(int $requestId, string $role, ?string $relativePath): ?string
{
    $relativePath = trim(str_replace('\\', '/', (string) $relativePath));
    $requestId = max(0, $requestId);
    $role = $role === 'employee' ? 'employee' : 'rh';
    if ($relativePath === '' || $requestId <= 0) {
        return null;
    }

    return '../request/view_rh_explanation_signature.php?id=' . $requestId . '&role=' . $role;
}

$pageTitle = 'Demandes d\'explications';
$activeMenu = 'rh';

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>
<style>
    .form-shell {
        position: relative;
        background: rgba(255, 255, 255, 0.98);
        border: 1px solid rgba(226, 232, 240, 0.95);
        box-shadow: 0 14px 32px rgba(15, 23, 42, 0.06);
    }

    .form-shell::after,
    .case-shell::after {
        content: '';
        position: absolute;
        inset: auto 24px 0 24px;
        height: 1px;
        background: linear-gradient(90deg, transparent, rgba(148, 163, 184, 0.22), transparent);
    }

    .case-shell {
        position: relative;
        background: rgba(255, 255, 255, 0.98);
        border: 1px solid rgba(226, 232, 240, 0.95);
        box-shadow: 0 16px 34px rgba(15, 23, 42, 0.06);
    }

    .case-list-card {
        position: relative;
        overflow: hidden;
    }

    .case-list-card::before {
        content: '';
        position: absolute;
        inset: 0 auto 0 0;
        width: 4px;
        background: linear-gradient(180deg, rgba(16, 185, 129, 0.95), rgba(59, 130, 246, 0.75));
        opacity: 0;
        transition: opacity 180ms ease;
    }

    .case-list-card:hover::before,
    .case-list-card.is-active::before {
        opacity: 1;
    }

    .timeline-card {
        position: relative;
        padding-left: 1.3rem;
    }

    .timeline-card::before {
        content: '';
        position: absolute;
        left: 0;
        top: 1rem;
        bottom: -1.1rem;
        width: 2px;
        background: linear-gradient(180deg, rgba(203, 213, 225, 0.85), rgba(226, 232, 240, 0));
    }

    .timeline-card:last-child::before {
        display: none;
    }

    .timeline-dot {
        position: absolute;
        left: -0.1rem;
        top: 0.95rem;
        width: 0.75rem;
        height: 0.75rem;
        border-radius: 9999px;
        border: 2px solid white;
        box-shadow: 0 0 0 3px rgba(226, 232, 240, 0.8);
    }

    .signature-pad-shell {
        position: relative;
        border: 1px dashed rgba(148, 163, 184, 0.85);
        border-radius: 1.5rem;
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

    .detail-key {
        font-size: 0.68rem;
        text-transform: uppercase;
        letter-spacing: 0.18em;
        font-weight: 700;
        color: rgb(100 116 139);
    }

    .detail-value {
        margin-top: 0.35rem;
        font-size: 0.97rem;
        font-weight: 600;
        color: rgb(15 23 42);
    }

    .action-panel {
        border: 1px solid rgba(226, 232, 240, 0.92);
        border-radius: 1.7rem;
        background: rgba(255, 255, 255, 0.92);
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.8);
    }

    .section-label {
        font-size: 0.68rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.22em;
        color: rgb(99 102 241);
    }

    .stats-strip {
        display: grid;
        gap: 0.9rem;
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }

    .stats-tile {
        border-radius: 1.5rem;
        border: 1px solid rgba(255, 255, 255, 0.85);
        background: rgba(255, 255, 255, 0.88);
        padding: 1rem;
        box-shadow: 0 10px 24px rgba(15, 23, 42, 0.05);
    }

    .workspace-card {
        border-radius: 1.8rem;
        border: 1px solid rgba(226, 232, 240, 0.92);
        background: rgba(255, 255, 255, 0.96);
        box-shadow: 0 12px 28px rgba(15, 23, 42, 0.05);
    }

    .workspace-hero {
        position: relative;
        background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
        border-bottom: 1px solid rgba(226, 232, 240, 0.95);
    }

    .workspace-hero::before {
        content: '';
        position: absolute;
        inset: 0 auto 0 0;
        width: 6px;
        background: linear-gradient(180deg, #0f766e 0%, #334155 100%);
    }

    .summary-grid {
        display: grid;
        gap: 0.85rem;
        grid-template-columns: repeat(4, minmax(0, 1fr));
    }

    .summary-grid>div {
        border-radius: 1rem;
        border: 1px solid rgba(226, 232, 240, 0.95);
        background: white;
        padding: 0.95rem 1rem;
    }

    .summary-grid span {
        display: block;
        font-size: 0.68rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.18em;
        color: rgb(100 116 139);
    }

    .summary-grid strong {
        display: block;
        margin-top: 0.45rem;
        font-size: 0.95rem;
        font-weight: 700;
        color: rgb(15 23 42);
    }

    .accordion-item {
        border-radius: 1.4rem;
        border: 1px solid rgba(226, 232, 240, 0.95);
        background: rgba(255, 255, 255, 0.98);
        overflow: hidden;
    }

    .accordion-summary {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        padding: 1rem 1.2rem;
        cursor: pointer;
        list-style: none;
    }

    .accordion-summary::-webkit-details-marker {
        display: none;
    }

    .accordion-summary::after {
        content: '+';
        width: 2rem;
        height: 2rem;
        border-radius: 9999px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: rgb(248 250 252);
        color: rgb(51 65 85);
        font-size: 1rem;
        font-weight: 700;
        flex-shrink: 0;
    }

    details[open]>.accordion-summary::after {
        content: '−';
    }

    .accordion-body {
        border-top: 1px solid rgba(226, 232, 240, 0.95);
        padding: 1.15rem 1.2rem 1.25rem;
    }

    .meta-pill {
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
        border-radius: 9999px;
        border: 1px solid rgba(226, 232, 240, 0.95);
        background: rgba(248, 250, 252, 0.95);
        padding: 0.42rem 0.8rem;
        font-size: 0.74rem;
        font-weight: 600;
        color: rgb(51 65 85);
    }

    @media (max-width: 1024px) {

        .stats-strip,
        .summary-grid {
            grid-template-columns: 1fr;
        }
    }

    .request-preview p {
        margin: 0 0 0.9rem;
    }

    .request-preview ul {
        margin: 0.4rem 0 0.9rem 1.2rem;
        list-style: disc;
    }

    .personnel-picker {
        position: relative;
    }

    .personnel-picker-input {
        width: 100%;
        border-radius: 1rem;
        border: 1px solid rgb(226 232 240);
        background: rgb(248 250 252);
        padding: 0.85rem 1rem;
        font-size: 0.875rem;
        color: rgb(15 23 42);
        outline: none;
    }

    .personnel-picker-input:focus {
        border-color: rgb(52 211 153);
        background: white;
    }

    .personnel-picker-panel {
        position: absolute;
        top: calc(100% + 0.45rem);
        left: 0;
        right: 0;
        z-index: 40;
        max-height: 280px;
        overflow: auto;
        border: 1px solid rgb(226 232 240);
        border-radius: 1rem;
        background: white;
        box-shadow: 0 18px 34px rgba(15, 23, 42, 0.12);
    }

    .personnel-picker-panel[hidden] {
        display: none;
    }

    .personnel-picker-option {
        width: 100%;
        border: 0;
        background: white;
        color: rgb(15 23 42);
        text-align: left;
        padding: 0.8rem 1rem;
        font-size: 0.88rem;
        cursor: pointer;
    }

    .personnel-picker-option:hover,
    .personnel-picker-option.is-active {
        background: rgb(15 118 110);
        color: white;
    }

    .personnel-picker-empty {
        padding: 0.85rem 1rem;
        font-size: 0.85rem;
        color: rgb(100 116 139);
        background: white;
    }

    .personnel-picker-help {
        margin-top: 0.4rem;
        font-size: 0.78rem;
        color: rgb(100 116 139);
    }
</style>

<div class="min-h-screen bg-[linear-gradient(180deg,_#f8fafc_0%,_#eef2f7_100%)] pb-12">
    <div class="container mx-auto px-4 py-8">
        <div class="relative overflow-hidden rounded-[32px] border border-slate-200 bg-[linear-gradient(135deg,#0f172a_0%,#1e293b_65%,#334155_100%)] px-8 py-10 text-white shadow-[0_20px_48px_rgba(15,23,42,0.22)]">
            <div class="relative flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="mb-3 text-xs font-semibold uppercase tracking-[0.35em] text-indigo-200">Ressources humaines</p>
                    <h1 class="text-3xl font-semibold tracking-tight md:text-5xl">Demandes d’explications</h1>
                    <p class="mt-4 max-w-3xl text-sm text-slate-200 md:text-base">Créez, diffusez, relancez et clôturez les dossiers RH avec PDF officiel, signatures manuscrites et historique centralisé.</p>
                </div>
                <div class="flex flex-wrap gap-3">
                    <a href="parametrage_hierarchie_rh.php" class="inline-flex items-center rounded-2xl bg-white px-5 py-3 text-sm font-semibold text-slate-900 hover:bg-indigo-50"><i class="fas fa-sitemap mr-2 text-indigo-600"></i>Hiérarchie</a>
                    <a href="dashboard_rh.php" class="inline-flex items-center rounded-2xl border border-white/20 px-5 py-3 text-sm font-semibold text-white/90 hover:bg-white/10"><i class="fas fa-arrow-left mr-2"></i>Retour dashboard</a>
                </div>
            </div>
        </div>

        <?php if ($statusType !== null && $statusMessage !== null): ?>
            <div class="mt-6 rounded-2xl border px-5 py-4 text-sm font-medium <?= $statusType === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-rose-200 bg-rose-50 text-rose-800' ?>">
                <?= htmlspecialchars((string) $statusMessage) ?>
            </div>
        <?php endif; ?>

        <div class="mt-8 grid gap-6 xl:grid-cols-[0.95fr_1.05fr]">
            <section class="form-shell relative rounded-[30px] p-7 backdrop-blur">
                <p class="section-label">Création</p>
                <h2 class="mt-2 text-2xl font-semibold text-slate-950">Nouvelle demande</h2>
                <p class="mt-2 text-sm text-slate-600">Le texte officiel est généré automatiquement à partir des champs ci-dessous, puis injecté dans le PDF et les emails.</p>

                <form method="post" class="mt-6 space-y-5" id="create-request-form">
                    <input type="hidden" name="action" value="create_request">

                    <div class="grid gap-4 md:grid-cols-2">
                        <div class="md:col-span-2">
                            <label class="mb-2 block text-sm font-medium text-slate-700">Collaborateur concerné</label>
                            <div class="personnel-picker">
                                <input type="hidden" name="target_personnel_id" id="target-personnel-id" value="<?= htmlspecialchars((string) ($createDraft['target_personnel_id'] ?? '')) ?>">
                                <input type="text" id="target-personnel-search" class="personnel-picker-input" value="<?= htmlspecialchars($selectedPersonnelLabel) ?>" placeholder="Rechercher un collaborateur" autocomplete="off" required>
                                <div id="target-personnel-menu" class="personnel-picker-panel" hidden></div>
                            </div>
                            <div class="personnel-picker-help">Tapez quelques lettres puis choisissez un collaborateur dans la liste.</div>
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-slate-700">Objet</label>
                            <input type="text" name="subject" value="<?= htmlspecialchars((string) ($createDraft['subject'] ?? '')) ?>" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none focus:border-emerald-400 focus:bg-white" placeholder="Ex: Demande d’explications sur retard chantier" required>
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-slate-700">Délai de réponse</label>
                            <input type="number" min="1" max="15" name="due_days" value="<?= htmlspecialchars((string) (($createDraft['due_days'] ?? '') !== '' ? $createDraft['due_days'] : (string) ((int) ($settings['response_due_days'] ?? 3)))) ?>" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none focus:border-emerald-400 focus:bg-white">
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-slate-700">Date ou période concernée</label>
                            <input type="text" name="incident_date" value="<?= htmlspecialchars((string) ($createDraft['incident_date'] ?? '')) ?>" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none focus:border-emerald-400 focus:bg-white" placeholder="Ex: 05/04/2026 ou semaine du 1er avril">
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-slate-700">Lieu ou cadre concerné</label>
                            <input type="text" name="incident_place" value="<?= htmlspecialchars((string) ($createDraft['incident_place'] ?? '')) ?>" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none focus:border-emerald-400 focus:bg-white" placeholder="Ex: Chantier, bureau, mission">
                        </div>
                    </div>

                    <div class="flex items-end">
                        <label class="flex items-center gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
                            <input type="checkbox" name="is_cadre_direct" value="1" class="h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500" <?= !empty($createDraft['is_cadre_direct']) ? 'checked' : '' ?>>
                            Demande adressée directement à un cadre : DG uniquement en copie
                        </label>
                    </div>

                    <div class="grid gap-4">
                        <div>
                            <label class="mb-2 block text-sm font-medium text-slate-700"><?= htmlspecialchars((string) ($settings['template_context_label'] ?? 'Contexte de la demande')) ?></label>
                            <textarea name="context_summary" rows="4" class="js-preview-field w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none focus:border-emerald-400 focus:bg-white" placeholder="Rappelez brièvement le contexte général de la demande."><?= htmlspecialchars((string) ($createDraft['context_summary'] ?? '')) ?></textarea>
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-slate-700"><?= htmlspecialchars((string) ($settings['template_facts_label'] ?? 'Faits constatés')) ?></label>
                            <textarea name="observed_facts" rows="6" class="js-preview-field w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none focus:border-emerald-400 focus:bg-white" placeholder="Décrivez les faits de façon précise. Une ligne par fait si nécessaire." required><?= htmlspecialchars((string) ($createDraft['observed_facts'] ?? '')) ?></textarea>
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-slate-700"><?= htmlspecialchars((string) ($settings['template_expectations_label'] ?? 'Explications attendues')) ?></label>
                            <textarea name="expected_response" rows="5" class="js-preview-field w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none focus:border-emerald-400 focus:bg-white" placeholder="Précisez les points sur lesquels le collaborateur doit répondre." required><?= htmlspecialchars((string) ($createDraft['expected_response'] ?? '')) ?></textarea>
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-slate-700"><?= htmlspecialchars((string) ($settings['template_supporting_label'] ?? 'Éléments complémentaires')) ?></label>
                            <textarea name="supporting_elements" rows="4" class="js-preview-field w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none focus:border-emerald-400 focus:bg-white" placeholder="Pièces, témoins, consignes internes, rappel de procédure, etc."><?= htmlspecialchars((string) ($createDraft['supporting_elements'] ?? '')) ?></textarea>
                        </div>
                    </div>

                    <div class="rounded-3xl border border-slate-200 bg-slate-50 p-5">
                        <div class="text-lg font-semibold text-slate-900">Aperçu du modèle généré</div>
                        <div class="mt-1 text-sm text-slate-600">Le texte final et le PDF sont construits automatiquement à partir des champs saisis.</div>
                        <div id="request-preview" class="request-preview mt-4 rounded-2xl border border-emerald-100 bg-white px-5 py-4 text-sm leading-7 text-slate-800"></div>
                    </div>

                    <button type="submit" class="inline-flex items-center rounded-2xl bg-emerald-600 px-5 py-3 text-sm font-semibold text-white hover:bg-emerald-700"><i class="fas fa-paper-plane mr-2"></i>Envoyer la demande</button>
                </form>
            </section>

            <section class="case-shell rounded-[32px] p-7 backdrop-blur">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <p class="section-label">Suivi</p>
                        <h2 class="mt-2 text-2xl font-semibold text-slate-950">Centre de pilotage des dossiers</h2>
                        <p class="mt-2 text-sm text-slate-600">Consultez les cas actifs, contrôlez les signatures, renvoyez le PDF et pilotez chaque décision RH depuis une seule vue.</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <a href="demandes_explication_rh.php?status=open" class="rounded-full px-4 py-2 text-sm font-semibold <?= $filter === 'open' ? 'bg-slate-950 text-white' : 'border border-slate-200 bg-white text-slate-700' ?>">Ouvertes</a>
                        <a href="demandes_explication_rh.php?status=closed" class="rounded-full px-4 py-2 text-sm font-semibold <?= $filter === 'closed' ? 'bg-slate-950 text-white' : 'border border-slate-200 bg-white text-slate-700' ?>">Clôturées</a>
                        <a href="demandes_explication_rh.php?status=cancelled" class="rounded-full px-4 py-2 text-sm font-semibold <?= $filter === 'cancelled' ? 'bg-slate-950 text-white' : 'border border-slate-200 bg-white text-slate-700' ?>">Annulées</a>
                        <a href="demandes_explication_rh.php?status=all" class="rounded-full px-4 py-2 text-sm font-semibold <?= $filter === 'all' ? 'bg-slate-950 text-white' : 'border border-slate-200 bg-white text-slate-700' ?>">Toutes</a>
                    </div>
                </div>

                <div class="mt-6 space-y-5">
                    <div>
                        <div class="stats-strip">
                            <div class="stats-tile">
                                <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Flux</div>
                                <div class="mt-2 text-2xl font-semibold text-slate-950"><?= (int) $rhStats['total'] ?></div>
                                <div class="mt-1 text-sm text-slate-600">Dossiers dans le filtre courant.</div>
                            </div>
                            <div class="stats-tile border border-amber-200 bg-amber-50/90">
                                <div class="text-xs font-semibold uppercase tracking-[0.18em] text-amber-700">Sous surveillance</div>
                                <div class="mt-2 text-2xl font-semibold text-amber-800"><?= (int) ($rhStats['pending'] + $rhStats['overdue']) ?></div>
                                <div class="mt-1 text-sm text-amber-800/80">Relances, réponses attendues ou retard.</div>
                            </div>
                            <div class="stats-tile border border-emerald-200 bg-emerald-50/90">
                                <div class="text-xs font-semibold uppercase tracking-[0.18em] text-emerald-700">Prêts décision</div>
                                <div class="mt-2 text-2xl font-semibold text-emerald-800"><?= (int) $rhStats['answered'] ?></div>
                                <div class="mt-1 text-sm text-emerald-800/80">Réponses reçues, prêtes à arbitrage.</div>
                            </div>
                        </div>
                    </div>

                    <div class="workspace-card p-5">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <div class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">File active</div>
                                <div class="mt-2 text-lg font-semibold text-slate-950">Dossiers du filtre sélectionné</div>
                            </div>
                            <div class="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-semibold text-slate-600"><?= count($requests) ?> dossier(s)</div>
                        </div>

                        <div class="mt-4 space-y-3 max-h-[420px] overflow-auto pr-1">
                            <?php foreach ($requests as $request): ?>
                                <?php $badge = rhExplanationBadge($request); ?>
                                <a href="demandes_explication_rh.php?status=<?= urlencode($filter) ?>&id=<?= (int) $request['id'] ?>" class="case-list-card <?= $selectedRequest && (int) $selectedRequest['id'] === (int) $request['id'] ? 'is-active border-emerald-300 bg-white shadow-[0_16px_40px_rgba(16,185,129,0.12)]' : 'border-slate-200 bg-white/85 hover:border-slate-300 hover:bg-white' ?> block rounded-[28px] border px-5 py-4 transition">
                                    <div class="flex items-start justify-between gap-4">
                                        <div>
                                            <div class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-500"><?= htmlspecialchars((string) ($request['request_ref'] ?? '')) ?></div>
                                            <div class="mt-2 text-lg font-semibold text-slate-950"><?= htmlspecialchars((string) ($request['target_name'] ?? '')) ?></div>
                                            <div class="mt-1 line-clamp-2 text-sm text-slate-600"><?= htmlspecialchars((string) ($request['subject'] ?? '')) ?></div>
                                            <div class="mt-3 flex flex-wrap gap-2 text-xs text-slate-500">
                                                <span class="rounded-full bg-slate-100 px-3 py-1 font-medium"><?= htmlspecialchars((string) ($request['target_service_label'] ?? '')) ?></span>
                                                <?php if (!empty($request['target_function_label'])): ?>
                                                    <span class="rounded-full bg-slate-100 px-3 py-1 font-medium"><?= htmlspecialchars((string) $request['target_function_label']) ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <span class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold <?= $badge['class'] ?>"><?= htmlspecialchars($badge['label']) ?></span>
                                    </div>
                                    <div class="mt-4 flex items-center justify-between gap-3 text-xs text-slate-500">
                                        <span>Échéance: <?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) ($request['due_at'] ?? 'now')))) ?></span>
                                        <span><?= !empty($request['employee_signed_at']) ? 'Signé employé' : 'Signature en attente' ?></span>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <?php if (!$selectedRequest): ?>
                        <div class="rounded-[32px] border border-dashed border-slate-300 bg-white/80 px-8 py-16 text-center text-slate-500">Sélectionnez un dossier pour afficher le détail.</div>
                    <?php else: ?>
                        <?php $selectedBadge = rhExplanationBadge($selectedRequest); ?>
                        <?php $rhSignatureUrl = rhExplanationSignatureAsset((int) ($selectedRequest['id'] ?? 0), 'rh', $selectedRequest['rh_signature_path'] ?? ''); ?>
                        <?php $employeeSignatureUrl = rhExplanationSignatureAsset((int) ($selectedRequest['id'] ?? 0), 'employee', $selectedRequest['employee_signature_path'] ?? ''); ?>

                        <div class="workspace-card overflow-hidden">
                            <div class="workspace-hero px-6 py-6">
                                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                    <div>
                                        <div class="text-xs font-semibold uppercase tracking-[0.24em] text-slate-500"><?= htmlspecialchars((string) ($selectedRequest['request_ref'] ?? '')) ?></div>
                                        <h3 class="mt-3 text-2xl font-semibold text-slate-950"><?= htmlspecialchars((string) ($selectedRequest['subject'] ?? '')) ?></h3>
                                        <p class="mt-2 max-w-2xl text-sm text-slate-600">Dossier adressé à <?= htmlspecialchars((string) ($selectedRequest['target_name'] ?? '')) ?>. Les sous-sections ci-dessous sont repliées par défaut pour garder une lecture plus stable.</p>
                                    </div>
                                    <div class="flex flex-col items-start gap-3 lg:items-end">
                                        <span class="inline-flex items-center rounded-full border border-slate-200 bg-white px-3 py-1 text-xs font-semibold text-slate-700"><?= htmlspecialchars($selectedBadge['label']) ?></span>
                                        <div class="flex flex-wrap gap-2">
                                            <a href="../request/export_rh_explanation_pdf.php?id=<?= (int) $selectedRequest['id'] ?>" target="_blank" class="inline-flex items-center rounded-2xl border border-slate-200 bg-white px-4 py-2 text-xs font-semibold text-slate-700 hover:border-slate-300"><i class="fas fa-eye mr-2"></i>Aperçu PDF</a>
                                            <a href="../request/export_rh_explanation_pdf.php?id=<?= (int) $selectedRequest['id'] ?>&download=1" class="inline-flex items-center rounded-2xl bg-slate-950 px-4 py-2 text-xs font-semibold text-white hover:bg-slate-800"><i class="fas fa-file-pdf mr-2 text-rose-300"></i>Télécharger</a>
                                        </div>
                                    </div>
                                </div>

                                <div class="summary-grid mt-5">
                                    <div>
                                        <span>Référence</span>
                                        <strong><?= htmlspecialchars((string) ($selectedRequest['request_ref'] ?? '')) ?></strong>
                                    </div>
                                    <div>
                                        <span>Échéance</span>
                                        <strong><?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) ($selectedRequest['due_at'] ?? 'now')))) ?></strong>
                                    </div>
                                    <div>
                                        <span>État employé</span>
                                        <strong><?= !empty($selectedRequest['employee_signed_at']) ? 'Signature enregistrée' : 'Signature attendue' ?></strong>
                                    </div>
                                    <div>
                                        <span>Traitement RH</span>
                                        <strong><?= !empty($selectedRequest['answered_at']) ? 'Réponse reçue' : 'Instruction en cours' ?></strong>
                                    </div>
                                </div>
                            </div>

                            <div class="space-y-4 px-6 py-6">
                                <details class="accordion-item">
                                    <summary class="accordion-summary">
                                        <div>
                                            <div class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">Fiche dossier</div>
                                            <div class="mt-1 text-base font-semibold text-slate-950">Informations générales</div>
                                        </div>
                                    </summary>
                                    <div class="accordion-body">
                                        <div class="mb-4 flex flex-wrap gap-2">
                                            <span class="meta-pill"><i class="fas fa-user text-slate-400"></i><?= htmlspecialchars((string) ($selectedRequest['target_name'] ?? '')) ?></span>
                                            <span class="meta-pill"><i class="fas fa-briefcase text-slate-400"></i><?= htmlspecialchars((string) ($selectedRequest['target_service_label'] ?? 'Non renseigné')) ?></span>
                                            <?php if (!empty($selectedRequest['target_function_label'])): ?>
                                                <span class="meta-pill"><i class="fas fa-id-badge text-slate-400"></i><?= htmlspecialchars((string) $selectedRequest['target_function_label']) ?></span>
                                            <?php endif; ?>
                                            <span class="meta-pill"><i class="fas fa-hourglass-half text-slate-400"></i><?= htmlspecialchars($selectedBadge['label']) ?></span>
                                        </div>
                                        <div class="space-y-3">
                                            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                                <div class="detail-key">Collaborateur concerné</div>
                                                <div class="detail-value"><?= htmlspecialchars((string) ($selectedRequest['target_name'] ?? '')) ?></div>
                                                <div class="mt-1 text-sm text-slate-600"><?= !empty($selectedRequest['target_email']) ? htmlspecialchars((string) $selectedRequest['target_email']) : 'Email non renseigné' ?></div>
                                            </div>
                                            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                                <div class="detail-key">Périmètre</div>
                                                <div class="detail-value"><?= htmlspecialchars((string) ($selectedRequest['target_service_label'] ?? 'Non renseigné')) ?></div>
                                                <div class="mt-1 text-sm text-slate-600"><?= !empty($selectedRequest['target_function_label']) ? htmlspecialchars((string) $selectedRequest['target_function_label']) : 'Fonction non renseignée' ?></div>
                                            </div>
                                            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                                <div class="detail-key">Échéance</div>
                                                <div class="detail-value"><?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) ($selectedRequest['due_at'] ?? 'now')))) ?></div>
                                                <div class="mt-1 text-sm text-slate-600"><?= !empty($selectedRequest['answered_at']) ? 'Réponse reçue le ' . htmlspecialchars(date('d/m/Y H:i', strtotime((string) $selectedRequest['answered_at']))) : 'Réponse non reçue' ?></div>
                                            </div>
                                            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                                <div class="detail-key">Accusé de lecture numérique</div>
                                                <div class="detail-value"><?= !empty($selectedRequest['employee_read_ack_at']) ? 'Reçu' : 'En attente' ?></div>
                                                <div class="mt-1 text-sm text-slate-600"><?= !empty($selectedRequest['employee_read_ack_at']) ? (!empty($selectedRequest['employee_read_ack_ip']) ? 'Consultation enregistrée le ' . htmlspecialchars(date('d/m/Y H:i', strtotime((string) $selectedRequest['employee_read_ack_at']))) . ' depuis IP ' . htmlspecialchars((string) $selectedRequest['employee_read_ack_ip']) : 'Accusé reconstitué à partir d’une activité salarié enregistrée le ' . htmlspecialchars(date('d/m/Y H:i', strtotime((string) $selectedRequest['employee_read_ack_at']))) . ' (dossier antérieur à la mise à jour).') : 'Si le salarié ne clique pas à temps, une alerte de remise en main propre sera émise.' ?></div>
                                            </div>
                                            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                                <div class="detail-key">Diffusion</div>
                                                <div class="detail-value"><?= !empty($selectedRequest['created_by_name']) ? htmlspecialchars((string) $selectedRequest['created_by_name']) : 'RH' ?></div>
                                                <div class="mt-1 text-sm text-slate-600">Envoi initial + renvois PDF disponibles</div>
                                            </div>
                                            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                                <div class="detail-key">Faute retenue</div>
                                                <div class="detail-value"><?= !empty($selectedRequest['fault_level_label']) ? htmlspecialchars((string) $selectedRequest['fault_level_label']) : 'Non encore qualifiée' ?></div>
                                                <div class="mt-1 text-sm text-slate-600"><?= !empty($selectedRequest['sanction_level_label']) ? 'Sanction associée : ' . htmlspecialchars((string) $selectedRequest['sanction_level_label']) : 'La qualification sera arrêtée lors de la clôture RH.' ?></div>
                                            </div>
                                            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                                <div class="detail-key">Accès plateforme</div>
                                                <div class="detail-value"><?= !empty($selectedRequest['sanction_disables_platform_access']) ? 'Désactivés automatiquement' : 'Maintenus' ?></div>
                                                <div class="mt-1 text-sm text-slate-600"><?= !empty($selectedRequest['platform_access_disabled_at']) ? 'Désactivation effectuée le ' . htmlspecialchars(date('d/m/Y H:i', strtotime((string) $selectedRequest['platform_access_disabled_at']))) : 'Aucune désactivation automatique enregistrée.' ?></div>
                                            </div>
                                            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                                <div class="detail-key">Fenêtre disciplinaire</div>
                                                <div class="detail-value"><?= !empty($selectedRequest['can_apply_sanction']) ? 'Déverrouillée' : 'Verrouillée' ?></div>
                                                <div class="mt-1 text-sm text-slate-600"><?= !empty($selectedRequest['can_apply_sanction']) ? 'Le délai de 72h ouvrables est écoulé. La décision peut être prononcée.' : htmlspecialchars((string) ($selectedRequest['sanction_locked_reason'] ?? 'Verrou juridique actif.')) ?></div>
                                            </div>
                                        </div>
                                    </div>
                                </details>

                                <details class="accordion-item">
                                    <summary class="accordion-summary">
                                        <div>
                                            <div class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">Pilotage</div>
                                            <div class="mt-1 text-base font-semibold text-slate-950">État documentaire, visas et renvoi PDF</div>
                                        </div>
                                    </summary>
                                    <div class="accordion-body space-y-3">
                                        <form method="post">
                                            <input type="hidden" name="action" value="resend_pdf">
                                            <input type="hidden" name="request_id" value="<?= (int) $selectedRequest['id'] ?>">
                                            <button type="submit" class="inline-flex items-center rounded-2xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700"><i class="fas fa-paper-plane mr-2"></i>Envoyer de nouveau le PDF</button>
                                        </form>
                                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                            <div class="detail-key">Signature RH</div>
                                            <div class="detail-value"><?= !empty($selectedRequest['rh_signed_at']) ? 'Enregistrée' : 'En attente' ?></div>
                                            <div class="mt-1 text-sm text-slate-600"><?= !empty($selectedRequest['rh_signed_at']) ? htmlspecialchars(date('d/m/Y H:i', strtotime((string) $selectedRequest['rh_signed_at']))) : 'Aucune signature RH enregistrée' ?></div>
                                        </div>
                                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                            <div class="detail-key">Signature employé</div>
                                            <div class="detail-value"><?= !empty($selectedRequest['employee_signed_at']) ? 'Enregistrée' : 'En attente' ?></div>
                                            <div class="mt-1 text-sm text-slate-600"><?= !empty($selectedRequest['employee_signed_at']) ? htmlspecialchars(date('d/m/Y H:i', strtotime((string) $selectedRequest['employee_signed_at']))) : 'Le collaborateur n’a pas encore signé' ?></div>
                                        </div>
                                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                            <div class="detail-key">Visa RH</div>
                                            <div class="detail-value"><?= !empty($selectedRequest['rh_signed_at']) ? 'Le PDF porte désormais le visa RH' : 'Le visa RH apparaîtra après signature' ?></div>
                                        </div>
                                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                            <div class="detail-key">Visa DG</div>
                                            <div class="detail-value"><?= (int) ($selectedRequest['dg_user_id'] ?? 0) > 0 ? 'Un emplacement DG est réservé dans le PDF' : 'Circuit standard sans visa DG obligatoire' ?></div>
                                        </div>
                                        <div class="rounded-2xl border border-dashed border-slate-300 bg-white px-4 py-4 text-sm text-slate-600">
                                            Le QR code du PDF permet de retrouver le dossier exact transmis. Les renvois utilisent toujours la dernière version consolidée, signatures comprises.
                                        </div>
                                    </div>
                                </details>

                                <details class="accordion-item">
                                    <summary class="accordion-summary">
                                        <div>
                                            <div class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">Validation RH</div>
                                            <div class="mt-1 text-base font-semibold text-slate-950">Signature manuscrite RH</div>
                                        </div>
                                    </summary>
                                    <div class="accordion-body">
                                        <form method="post" class="js-signature-pad space-y-4">
                                            <input type="hidden" name="action" value="save_rh_signature">
                                            <input type="hidden" name="request_id" value="<?= (int) $selectedRequest['id'] ?>">
                                            <input type="hidden" name="signature_base64" value="" class="js-signature-value">
                                            <div class="text-sm text-slate-600">Dessinez votre signature pour la figer dans le PDF officiel et dans tous les prochains renvois.</div>
                                            <div class="rounded-2xl border border-slate-200 bg-white px-4 py-3 text-xs font-medium text-slate-600 js-signature-status">Aucune signature capturée</div>
                                            <div class="signature-pad-shell">
                                                <canvas class="js-signature-canvas"></canvas>
                                                <div class="signature-pad-hint js-signature-hint">Signez ici avec la souris ou le doigt</div>
                                            </div>
                                            <div class="flex flex-wrap gap-2">
                                                <button type="button" class="js-signature-clear inline-flex items-center rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:border-slate-300"><i class="fas fa-eraser mr-2"></i>Effacer</button>
                                                <button type="button" class="js-signature-use inline-flex items-center rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm font-semibold text-emerald-700 hover:bg-emerald-100"><i class="fas fa-pen-nib mr-2"></i>Utiliser la signature</button>
                                                <button type="submit" class="inline-flex items-center rounded-2xl bg-slate-950 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800"><i class="fas fa-save mr-2"></i>Enregistrer ma signature RH</button>
                                            </div>
                                            <div class="rounded-2xl border border-slate-200 bg-white p-4">
                                                <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Dernière signature enregistrée</div>
                                                <div class="js-signature-preview mt-3 flex min-h-[92px] items-center justify-center rounded-2xl border border-dashed border-slate-200 bg-slate-50 text-sm text-slate-500" data-empty-label="Aucune signature RH enregistrée">
                                                    <?php if ($rhSignatureUrl): ?>
                                                        <img src="<?= htmlspecialchars($rhSignatureUrl) ?>" alt="Signature RH" style="max-width:100%; max-height:92px; object-fit:contain;">
                                                    <?php else: ?>
                                                        Aucune signature RH enregistrée
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </form>
                                    </div>
                                </details>

                                <details class="accordion-item">
                                    <summary class="accordion-summary">
                                        <div>
                                            <div class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">Chronologie</div>
                                            <div class="mt-1 text-base font-semibold text-slate-950">Historique détaillé des échanges</div>
                                        </div>
                                        <div class="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-medium text-slate-600"><?= count($messages) ?> événement(s)</div>
                                    </summary>
                                    <div class="accordion-body">
                                        <?php if (!$messages): ?>
                                            <div class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-5 py-8 text-center text-sm text-slate-500">Aucun échange complémentaire sur ce dossier pour le moment.</div>
                                        <?php else: ?>
                                            <div class="space-y-4">
                                                <?php foreach ($messages as $message): ?>
                                                    <?php
                                                    $authorRole = (string) ($message['author_role'] ?? 'rh');
                                                    $isEmployee = $authorRole === 'employee';
                                                    $tone = $isEmployee ? 'border-indigo-200 bg-indigo-50/70' : 'border-emerald-200 bg-emerald-50/70';
                                                    $dot = $isEmployee ? 'bg-indigo-600' : 'bg-emerald-600';
                                                    ?>
                                                    <article class="timeline-card rounded-3xl">
                                                        <span class="timeline-dot <?= $dot ?>"></span>
                                                        <div class="rounded-3xl border <?= $tone ?> px-5 py-4">
                                                            <div class="flex items-center justify-between gap-3">
                                                                <div class="text-sm font-semibold text-slate-900"><?= htmlspecialchars((string) ($message['author_name'] ?? ($isEmployee ? 'Employé' : 'RH'))) ?></div>
                                                                <div class="rounded-full bg-white/80 px-3 py-1 text-xs text-slate-500"><?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) ($message['created_at'] ?? 'now')))) ?></div>
                                                            </div>
                                                            <div class="mt-1 text-xs uppercase tracking-[0.22em] text-slate-500"><?= htmlspecialchars((string) ($message['message_kind'] ?? 'message')) ?></div>
                                                            <div class="prose prose-sm mt-3 max-w-none text-slate-800"><?= (string) ($message['body_html'] ?? '') ?></div>
                                                        </div>
                                                    </article>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>

                                    </div>
                                </details>

                                <?php if ($latestRectificationMessage): ?>
                                    <div class="rounded-3xl border border-sky-200 bg-sky-50 px-5 py-4">
                                        <div class="text-xs font-semibold uppercase tracking-[0.22em] text-sky-700">Rectification signalée</div>
                                        <div class="mt-2 text-sm font-semibold text-slate-900">Dernière demande reçue le <?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) ($latestRectificationMessage['created_at'] ?? 'now')))) ?></div>
                                        <div class="mt-2 prose prose-sm max-w-none text-slate-800"><?= (string) ($latestRectificationMessage['body_html'] ?? '') ?></div>
                                    </div>
                                <?php endif; ?>

                                <?php if (in_array((string) ($selectedRequest['status'] ?? ''), ['pending_response', 'complement_requested', 'answered'], true)): ?>
                                    <details class="accordion-item">
                                        <summary class="accordion-summary">
                                            <div>
                                                <div class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">Décisions RH</div>
                                                <div class="mt-1 text-base font-semibold text-slate-950">Complément, clôture ou annulation</div>
                                            </div>
                                        </summary>
                                        <div class="accordion-body space-y-4">
                                            <form method="post" class="action-panel p-5">
                                                <input type="hidden" name="action" value="request_complement">
                                                <input type="hidden" name="request_id" value="<?= (int) $selectedRequest['id'] ?>">
                                                <div class="flex items-center justify-between gap-3">
                                                    <div>
                                                        <div class="text-xs font-semibold uppercase tracking-[0.22em] text-sky-700">Action 1</div>
                                                        <div class="mt-2 text-lg font-semibold text-slate-900">Demander un complément</div>
                                                        <div class="text-sm text-slate-600">Relance formelle vers l’employé avec une nouvelle échéance claire.</div>
                                                    </div>
                                                    <input type="number" min="1" max="15" name="due_days" value="<?= (int) ($settings['response_due_days'] ?? 3) ?>" class="w-24 rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-sky-400">
                                                </div>
                                                <textarea name="message_html" class="mt-4 min-h-[160px] w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none focus:border-sky-400">Merci de compléter votre réponse sur les points suivants :

- </textarea>
                                                <button type="submit" class="mt-4 inline-flex items-center rounded-2xl bg-sky-600 px-5 py-3 text-sm font-semibold text-white hover:bg-sky-700">Envoyer la relance</button>
                                            </form>

                                            <form method="post" class="action-panel p-5">
                                                <input type="hidden" name="action" value="close_request">
                                                <input type="hidden" name="request_id" value="<?= (int) $selectedRequest['id'] ?>">
                                                <div class="text-xs font-semibold uppercase tracking-[0.22em] text-emerald-700">Action 2</div>
                                                <div class="mt-2 text-lg font-semibold text-slate-900">Clôturer le dossier</div>
                                                <div class="mt-1 text-sm text-slate-600">Qualifiez la faute, confirmez la sanction appliquée et ajoutez si besoin une note finale justifiant l’arbitrage RH.</div>
                                                <?php if (empty($selectedRequest['can_apply_sanction'])): ?>
                                                    <div class="mt-4 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-800">
                                                        Verrou juridique actif : <?= htmlspecialchars((string) ($selectedRequest['sanction_locked_reason'] ?? 'La sanction ne peut pas encore être prononcée.')) ?>
                                                    </div>
                                                <?php endif; ?>
                                                <div class="mt-4 grid gap-4 lg:grid-cols-2">
                                                    <div>
                                                        <label class="mb-2 block text-sm font-medium text-slate-700">Niveau de faute <span class="text-rose-500">*</span></label>
                                                        <select name="fault_level_id" id="closure-fault-level" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none focus:border-emerald-400" required <?= empty($selectedRequest['can_apply_sanction']) ? 'disabled' : '' ?>>
                                                            <option value="">Sélectionner un niveau de faute</option>
                                                            <?php foreach ($faultLevels as $faultLevel): ?>
                                                                <option value="<?= (int) $faultLevel['id'] ?>" <?= (string) ($closureDraft['fault_level_id'] ?? '') === (string) ($faultLevel['id'] ?? '') ? 'selected' : '' ?>><?= htmlspecialchars((string) ($faultLevel['label'] ?? '')) ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div>
                                                        <label class="mb-2 block text-sm font-medium text-slate-700">Niveau de sanction <span class="text-rose-500">*</span></label>
                                                        <select name="sanction_level_id" id="closure-sanction-level" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none focus:border-emerald-400" required <?= empty($selectedRequest['can_apply_sanction']) ? 'disabled' : '' ?>>
                                                            <option value="">Sélectionner une sanction</option>
                                                            <?php foreach ($sanctionLevels as $sanctionLevel): ?>
                                                                <option value="<?= (int) $sanctionLevel['id'] ?>" data-disables-access="<?= !empty($sanctionLevel['disables_platform_access']) ? '1' : '0' ?>" data-category="<?= htmlspecialchars((string) ($sanctionLevel['category'] ?? 'disciplinary')) ?>" <?= (string) ($closureDraft['sanction_level_id'] ?? '') === (string) ($sanctionLevel['id'] ?? '') ? 'selected' : '' ?>><?= htmlspecialchars((string) ($sanctionLevel['label'] ?? '')) ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                        <div id="closure-sanction-help" class="mt-2 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs font-medium text-amber-800">
                                                            Choisissez la sanction finale. Une mise à pied ou un licenciement peuvent désactiver automatiquement les accès à la plateforme.
                                                        </div>
                                                    </div>
                                                </div>
                                                <textarea name="message_html" class="mt-4 min-h-[130px] w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none focus:border-emerald-400" <?= empty($selectedRequest['can_apply_sanction']) ? 'disabled' : '' ?>><?= htmlspecialchars((string) ($closureDraft['message_html'] ?? '')) ?></textarea>
                                                <button type="submit" class="mt-4 inline-flex items-center rounded-2xl bg-emerald-600 px-5 py-3 text-sm font-semibold text-white hover:bg-emerald-700 <?= empty($selectedRequest['can_apply_sanction']) ? 'cursor-not-allowed opacity-50 hover:bg-emerald-600' : '' ?>" <?= empty($selectedRequest['can_apply_sanction']) ? 'disabled title="Verrou 72h ouvrables actif"' : '' ?>>Clôturer</button>
                                            </form>

                                            <form method="post" class="action-panel p-5">
                                                <input type="hidden" name="action" value="cancel_request">
                                                <input type="hidden" name="request_id" value="<?= (int) $selectedRequest['id'] ?>">
                                                <div class="text-xs font-semibold uppercase tracking-[0.22em] text-rose-700">Action 3</div>
                                                <div class="mt-2 text-lg font-semibold text-slate-900">Annuler le dossier</div>
                                                <div class="mt-1 text-sm text-slate-600">À utiliser uniquement si la demande devient sans objet ou doit être retirée du circuit.</div>
                                                <textarea name="message_html" class="mt-4 min-h-[120px] w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none focus:border-rose-400"></textarea>
                                                <button type="submit" class="mt-4 inline-flex items-center rounded-2xl bg-rose-600 px-5 py-3 text-sm font-semibold text-white hover:bg-rose-700">Annuler</button>
                                            </form>
                                        </div>
                                    </details>
                                <?php endif; ?>

                                <?php if ($canAdminDeleteRequest): ?>
                                    <details class="accordion-item">
                                        <summary class="accordion-summary">
                                            <div>
                                                <div class="text-xs font-semibold uppercase tracking-[0.22em] text-rose-700">Administration</div>
                                                <div class="mt-1 text-base font-semibold text-slate-950">Suppression traçable du dossier</div>
                                            </div>
                                        </summary>
                                        <div class="accordion-body">
                                            <div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
                                                <div><?= htmlspecialchars((string) ($selectedRequest['admin_delete_rule_label'] ?? '')) ?></div>
                                                <a href="../admin/rh_explanation_deleted_history.php" class="inline-flex items-center rounded-2xl border border-slate-300 bg-white px-4 py-2 text-xs font-semibold text-slate-700 hover:border-slate-400"><i class="fas fa-clock-rotate-left mr-2"></i>Historique admin</a>
                                            </div>
                                            <form method="post" class="action-panel border-rose-200 bg-rose-50/50 p-5" onsubmit="return window.confirm('Supprimer définitivement ce dossier de test ? Une archive de traçabilité sera conservée.');">
                                                <input type="hidden" name="action" value="delete_request_admin">
                                                <input type="hidden" name="request_id" value="<?= (int) $selectedRequest['id'] ?>">
                                                <div class="text-xs font-semibold uppercase tracking-[0.22em] text-rose-700">Action admin</div>
                                                <div class="mt-2 text-lg font-semibold text-slate-900">Supprimer définitivement la demande</div>
                                                <div class="mt-1 text-sm text-slate-600">Le dossier, ses messages et ses signatures seront retirés du flux actif. Une trace complete restera archivée pour audit. La suppression n’est autorisée que si le sujet ou le contenu contient un marqueur de test comme TEST, ESSAI, DEMO, DÉMO, DÉMONSTRATION, SANDBOX ou BROUILLON.</div>
                                                <textarea name="delete_reason" class="mt-4 min-h-[120px] w-full rounded-3xl border border-rose-200 bg-white px-4 py-3 text-sm outline-none focus:border-rose-400" placeholder="Indiquez pourquoi cette suppression admin est nécessaire (ex: dossier de test, doublon, création de démonstration)." required <?= empty($selectedRequest['is_test_request']) ? 'disabled' : '' ?>><?= htmlspecialchars((string) ($deleteDraft['reason'] ?? '')) ?></textarea>
                                                <button type="submit" class="mt-4 inline-flex items-center rounded-2xl bg-rose-700 px-5 py-3 text-sm font-semibold text-white hover:bg-rose-800 <?= empty($selectedRequest['is_test_request']) ? 'cursor-not-allowed opacity-50 hover:bg-rose-700' : '' ?>" <?= empty($selectedRequest['is_test_request']) ? 'disabled title="Suppression réservée aux dossiers de test"' : '' ?>><i class="fas fa-trash-alt mr-2"></i>Supprimer le dossier</button>
                                            </form>
                                        </div>
                                    </details>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
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
                                    }, $personnelChoices), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const faultDefaultSanctions = <?= json_encode($faultDefaultSanctions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const personnelSearchInput = document.getElementById('target-personnel-search');
        const personnelHiddenInput = document.getElementById('target-personnel-id');
        const personnelMenu = document.getElementById('target-personnel-menu');
        const creationForm = document.getElementById('create-request-form');
        const faultLevelSelect = document.getElementById('closure-fault-level');
        const sanctionLevelSelect = document.getElementById('closure-sanction-level');
        const sanctionHelp = document.getElementById('closure-sanction-help');

        function normalizeValue(value) {
            return String(value || '')
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '')
                .toLowerCase()
                .trim();
        }

        function updateSanctionHelp() {
            if (!sanctionLevelSelect || !sanctionHelp) {
                return;
            }

            const selectedOption = sanctionLevelSelect.options[sanctionLevelSelect.selectedIndex];
            if (!selectedOption || !selectedOption.value) {
                sanctionHelp.textContent = 'Choisissez la sanction finale. Une mise à pied ou un licenciement peuvent désactiver automatiquement les accès à la plateforme.';
                sanctionHelp.className = 'mt-2 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs font-medium text-amber-800';
                return;
            }

            const disablesAccess = selectedOption.dataset.disablesAccess === '1';
            const category = selectedOption.dataset.category || 'disciplinary';

            if (category === 'warning') {
                sanctionHelp.textContent = 'Cette décision correspond à un avertissement simple. Aucun retrait d’accès plateforme ne sera appliqué.';
                sanctionHelp.className = 'mt-2 rounded-2xl border border-sky-200 bg-sky-50 px-4 py-3 text-xs font-medium text-sky-800';
                return;
            }

            if (disablesAccess) {
                sanctionHelp.textContent = 'Cette sanction désactivera automatiquement les accès du collaborateur à la plateforme dès la clôture du dossier.';
                sanctionHelp.className = 'mt-2 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-xs font-medium text-rose-800';
                return;
            }

            sanctionHelp.textContent = 'Cette sanction sera tracée dans le dossier sans désactivation automatique des accès plateforme.';
            sanctionHelp.className = 'mt-2 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-xs font-medium text-emerald-800';
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
                emptyState.textContent = 'Aucun collaborateur ne correspond à la recherche.';
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
                if (String(personnelHiddenInput.value || '').trim() === '') {
                    event.preventDefault();
                    personnelSearchInput.setCustomValidity('Sélectionnez un collaborateur dans la liste proposée.');
                    personnelSearchInput.reportValidity();
                    personnelSearchInput.focus();
                    renderPersonnelOptions();
                }
            });
        }

        if (faultLevelSelect && sanctionLevelSelect) {
            faultLevelSelect.addEventListener('change', function() {
                const defaultSanctionId = String(faultDefaultSanctions[this.value] || '');
                if (defaultSanctionId !== '') {
                    sanctionLevelSelect.value = defaultSanctionId;
                }
                updateSanctionHelp();
            });

            sanctionLevelSelect.addEventListener('change', updateSanctionHelp);
            updateSanctionHelp();
        }

        const preview = document.getElementById('request-preview');
        if (!preview) {
            return;
        }

        const settings = <?= json_encode($previewSettings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const fields = {
            incidentDate: document.querySelector('[name="incident_date"]'),
            incidentPlace: document.querySelector('[name="incident_place"]'),
            context: document.querySelector('[name="context_summary"]'),
            facts: document.querySelector('[name="observed_facts"]'),
            expectations: document.querySelector('[name="expected_response"]'),
            supporting: document.querySelector('[name="supporting_elements"]')
        };

        function escapeHtml(value) {
            return String(value || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function sectionHtml(title, value) {
            const lines = String(value || '').split(/\r?\n/).map(function(line) {
                return line.trim();
            }).filter(Boolean);

            if (!lines.length) {
                return '';
            }

            const allBullets = lines.every(function(line) {
                return /^[-*]|^\d+[.)]/.test(line);
            });

            let body = '';
            if (allBullets) {
                body = '<ul>' + lines.map(function(line) {
                    return '<li>' + escapeHtml(line.replace(/^[-*]|^\d+[.)]/, '').trim()) + '</li>';
                }).join('') + '</ul>';
            } else {
                body = lines.map(function(line) {
                    return '<p>' + escapeHtml(line) + '</p>';
                }).join('');
            }

            return '<section><p><strong>' + escapeHtml(title) + '</strong></p>' + body + '</section>';
        }

        function updatePreview() {
            const blocks = [];
            if (settings.intro) {
                blocks.push('<p>' + escapeHtml(settings.intro) + '</p>');
            }

            const meta = [];
            if (fields.incidentDate && fields.incidentDate.value.trim() !== '') {
                meta.push('<strong>Date ou période concernée :</strong> ' + escapeHtml(fields.incidentDate.value.trim()));
            }
            if (fields.incidentPlace && fields.incidentPlace.value.trim() !== '') {
                meta.push('<strong>Lieu ou cadre concerné :</strong> ' + escapeHtml(fields.incidentPlace.value.trim()));
            }
            if (meta.length) {
                blocks.push('<p>' + meta.join('<br>') + '</p>');
            }

            blocks.push(sectionHtml(settings.contextLabel || 'Contexte de la demande', fields.context ? fields.context.value : ''));
            blocks.push(sectionHtml(settings.factsLabel || 'Faits constatés', fields.facts ? fields.facts.value : ''));
            blocks.push(sectionHtml(settings.expectationsLabel || 'Explications attendues', fields.expectations ? fields.expectations.value : ''));
            blocks.push(sectionHtml(settings.supportingLabel || 'Éléments complémentaires', fields.supporting ? fields.supporting.value : ''));

            if (settings.closing) {
                blocks.push('<p>' + escapeHtml(settings.closing) + '</p>');
            }

            preview.innerHTML = blocks.filter(Boolean).join('') || '<p>Aucun contenu saisi pour le moment.</p>';
        }

        Object.keys(fields).forEach(function(key) {
            if (fields[key]) {
                fields[key].addEventListener('input', updatePreview);
            }
        });

        updatePreview();
    });
</script>

<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>
