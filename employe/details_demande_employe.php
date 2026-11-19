<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/EmployeeLegalRequestManager.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/../model/AuditTrail.php';

$pdo = \Database::getConnection();
$manager = new \EmployeeLegalRequestManager($pdo);

$userId = (int) ($_SESSION['user_id'] ?? 0);
$userEmail = (string) ($_SESSION['mail_pro'] ?? '');
$personnelProfile = employeeFindPersonnelProfile($pdo, $userEmail);
$actorMatricule = strtoupper(trim((string) ($personnelProfile['matricule'] ?? '')));
$requester = [
    'user_id' => $userId,
    'name' => employeeCurrentUserName(),
    'email' => $userEmail,
];

$requestId = isset($_GET['id']) && is_numeric($_GET['id']) ? (int) $_GET['id'] : 0;
$filterType = strtolower(trim((string) ($_GET['type'] ?? '')));
$filterStatus = strtolower(trim((string) ($_GET['status'] ?? '')));

function statusBadge(array $request): array
{
    $meta = \EmployeeLegalRequestManager::getRequestStatusMeta($request);
    return [
        'label' => (string) ($meta['label'] ?? '—'),
        'class' => (string) ($meta['class'] ?? 'bg-slate-100 text-slate-700 border-slate-200'),
    ];
}

function employeeLegalSignatureAsset(int $requestId, string $role, ?string $relativePath): ?string
{
    $relativePath = trim(str_replace('\\', '/', (string) $relativePath));
    if ($requestId <= 0 || $relativePath === '') {
        return null;
    }

    return '../request/view_employee_legal_signature.php?id=' . $requestId . '&role=' . urlencode($role);
}

function employeeAppBasePath(): string
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($script === '') {
        return '';
    }

    $dir = rtrim(dirname($script), '/');
    $root = rtrim(dirname($dir), '/');
    if ($root === '' || $root === '.' || $root === '/') {
        return '';
    }

    return $root;
}

function resolveUploadsHref(string $url): string
{
    $u = str_replace('\\', '/', trim($url));
    if ($u === '') {
        return '';
    }
    if (strpos($u, 'uploads/') === 0) {
        $u = '/' . $u;
    }
    if (substr($u, 0, 9) !== '/uploads/') {
        return '';
    }

    $base = employeeAppBasePath();
    $candidates = [$u];
    if ($base !== '') {
        $candidates[] = $base . $u;
    }

    $docroot = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), "\\/");
    if ($docroot !== '') {
        foreach ($candidates as $cand) {
            $abs = $docroot . str_replace('/', DIRECTORY_SEPARATOR, $cand);
            if (is_file($abs)) {
                return $cand;
            }
        }
    }

    return $candidates[count($candidates) - 1];
}

$flash = '';
$flashType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save_signature' && isset($_POST['request_id']) && is_numeric($_POST['request_id'])) {
        $id = (int) $_POST['request_id'];
        try {
            if ($manager->saveRequestSignature($id, 'employee', $requester, (string) ($_POST['signature_base64'] ?? ''))) {
                $qs = http_build_query(array_filter([
                    'id' => $id,
                    'type' => $filterType ?: null,
                    'status' => $filterStatus ?: null,
                    'signed' => 1,
                ], static fn($v) => $v !== null && $v !== ''));
                header('Location: details_demande_employe.php?' . $qs);
                exit;
            }
            $flash = 'Impossible d’enregistrer votre signature.';
            $flashType = 'error';
        } catch (Throwable $e) {
            $flash = $e->getMessage();
            $flashType = 'error';
        }
    }

    if ($action === 'cancel' && isset($_POST['request_id']) && is_numeric($_POST['request_id'])) {
        $id = (int) $_POST['request_id'];
        $note = trim((string) ($_POST['note'] ?? ''));

        if ($manager->cancelRequest($id, $userId, $note)) {
            $flash = 'Demande annulée.';
            $flashType = 'success';
            \AuditTrail::log('employee_request_cancelled', [
                'module' => 'employe',
                'action' => 'details_demande_cancel',
                'entity_type' => 'employee_request',
                'entity_id' => (string) $id,
                'entity_label' => 'request_' . $id,
                'user_id' => $userId,
                'details' => ['note' => $note !== '' ? $note : null],
            ], $pdo);
        } else {
            $flash = 'Impossible d\'annuler cette demande.';
            $flashType = 'error';
            \AuditTrail::log('employee_request_cancel_failed', [
                'module' => 'employe',
                'action' => 'details_demande_cancel',
                'entity_type' => 'employee_request',
                'entity_id' => (string) $id,
                'entity_label' => 'request_' . $id,
                'user_id' => $userId,
                'result' => 'failure',
                'severity' => 'warning',
                'details' => ['note' => $note !== '' ? $note : null],
            ], $pdo);
        }

        $qs = http_build_query(array_filter([
            'id' => $id,
            'type' => $filterType ?: null,
            'status' => $filterStatus ?: null,
        ], static fn($v) => $v !== null && $v !== ''));
        header('Location: details_demande_employe.php?' . $qs);
        exit;
    }
}

if ($requestId <= 0) {
    http_response_code(400);
    echo 'ID manquant.';
    exit;
}

$view = $manager->getRequestById($requestId);
$events = [];

$canView = false;
if ($view && (int) ($view['requester_user_id'] ?? 0) === $userId) {
    $canView = true;
}
if (!$canView && $view && ($view['request_type'] ?? '') === 'feb' && $actorMatricule !== '') {
    $payloadMatricule = strtoupper(trim((string) (($view['payload']['matricule'] ?? ''))));
    if ($payloadMatricule !== '' && $payloadMatricule === $actorMatricule) {
        $canView = true;
    }
}

if (!$view || !$canView) {
    http_response_code(403);
    echo 'Accès refusé.';
    exit;
}

$events = $manager->listEventsForRequest($requestId, 80);

if ($flash === '' && isset($_GET['signed'])) {
    $flash = 'Votre signature a bien été enregistrée.';
    $flashType = 'success';
}

\AuditTrail::logPageConsultation([
    'module' => 'employe',
    'action' => 'details_demande_view',
    'entity_type' => 'employee_request',
    'entity_id' => (string) $requestId,
    'entity_label' => (string) ($view['title'] ?? ('request_' . $requestId)),
    'details' => [
        'request_type' => $view['request_type'] ?? null,
        'status' => $view['status'] ?? null,
        'event_count' => count($events),
    ],
], $pdo);

$pageTitle = 'Détail demande';
$layoutRootBase = '../';
$activeEmployeeMenu = 'demandes';

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';

$badge = statusBadge($view);
$payload = (array) ($view['payload'] ?? []);
$febDisplay = null;
if (($view['request_type'] ?? '') === 'feb') {
    $febDisplay = (array) ($view['feb_display'] ?? \EmployeeLegalRequestManager::extractFebDisplay($view));
}
$statusMeta = \EmployeeLegalRequestManager::getRequestStatusMeta($view);
$canEditRequest = $manager->canRequesterEditRequest($view, $userId);
$pdfExportableRequestTypes = ['permission_absence', 'conge', 'retard', 'reclamation_salariale', 'heures_supp', 'avance_salaire'];
$canExportStandardPdf = in_array((string) ($view['request_type'] ?? ''), $pdfExportableRequestTypes, true);
$isFebCompletion = (string) ($view['request_type'] ?? '') === 'feb' && (string) ($view['status'] ?? '') === 'details_requested';
$editLabel = $isFebCompletion ? 'Compléter et renvoyer' : 'Modifier';
$employeeSignatureUrl = employeeLegalSignatureAsset((int) ($view['id'] ?? 0), 'employee', $view['employee_signature_path'] ?? '');
$rhSignatureUrl = employeeLegalSignatureAsset((int) ($view['id'] ?? 0), 'rh', $view['rh_signature_path'] ?? '');
$drhSignatureUrl = employeeLegalSignatureAsset((int) ($view['id'] ?? 0), 'drh', $view['drh_signature_path'] ?? '');
$dgSignatureUrl = employeeLegalSignatureAsset((int) ($view['id'] ?? 0), 'dg', $view['dg_signature_path'] ?? '');
$backQuery = http_build_query(array_filter([
    'type' => $filterType ?: null,
    'status' => $filterStatus ?: null,
], static fn($v) => $v !== null && $v !== ''));
$backHref = 'mes_demandes_employe.php' . ($backQuery ? ('?' . $backQuery) : '');
?>

<style>
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
</style>

<div class="min-h-screen bg-[radial-gradient(circle_at_top_left,_rgba(240,249,255,0.95),_transparent_42%),radial-gradient(circle_at_top_right,_rgba(224,231,255,0.85),_transparent_35%),linear-gradient(180deg,_#f8fafc_0%,_#eef2ff_100%)] pb-12">
    <div class="container mx-auto px-4 py-8">
        <div class="rounded-[28px] border border-slate-200 bg-white/90 p-6 shadow-sm">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.28em] text-indigo-600">Mes demandes</p>
                    <h1 class="mt-2 text-2xl font-semibold text-slate-950">Détail — #<?= (int) ($view['id'] ?? 0) ?></h1>
                    <div class="mt-2">
                        <span class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold <?= $badge['class'] ?>"><?= htmlspecialchars($badge['label']) ?></span>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a href="<?= htmlspecialchars($backHref) ?>" class="rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-900 hover:bg-slate-50">Retour</a>
                    <?php if ($canEditRequest): ?>
                        <a href="nouvelle_demande_employe.php?type=<?= urlencode((string) ($view['request_type'] ?? '')) ?>&request_id=<?= (int) ($view['id'] ?? 0) ?>" class="rounded-2xl border border-sky-200 bg-sky-50 px-4 py-2 text-sm font-semibold text-sky-800 hover:bg-sky-100"><?= htmlspecialchars($editLabel) ?></a>
                    <?php endif; ?>
                    <?php if (($view['request_type'] ?? '') === 'feb'): ?>
                        <a href="../request/export_feb_submission_pdf.php?id=<?= (int)$view['id'] ?>" class="rounded-2xl bg-teal-700 px-4 py-2 text-sm font-semibold text-white" target="_blank" rel="noopener"><i class="fas fa-file-pdf mr-2"></i>Télécharger la FEB</a>
                    <?php endif; ?>
                    <?php if ($canExportStandardPdf): ?>
                        <a href="../request/export_employee_request_pdf.php?id=<?= (int) ($view['id'] ?? 0) ?>" class="rounded-2xl border border-gray-700 bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800" target="_blank" rel="noopener">
                            <i class="fas fa-file-pdf mr-2"></i>
                            Export PDF
                        </a>
                    <?php endif; ?>
                    <a href="actes_legaux_employe.php" class="rounded-2xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                        <i class="fas fa-plus mr-2"></i>
                        Nouvelle demande
                    </a>
                </div>
            </div>

            <?php if (($view['request_type'] ?? '') === 'feb'): ?>
                <details class="mt-5 rounded-2xl border border-slate-200 bg-white p-4">
                    <summary class="cursor-pointer font-bold text-slate-800">Aperçu de la FEB officielle</summary>
                    <iframe src="../request/export_feb_submission_pdf.php?id=<?= (int)$view['id'] ?>#toolbar=0" title="Aperçu de la FEB #<?= (int)$view['id'] ?>" loading="lazy" class="mt-4 h-[75vh] min-h-[520px] w-full rounded-xl border border-slate-200 bg-white"></iframe>
                </details>
            <?php endif; ?>

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

            <div class="mt-6 grid gap-4 md:grid-cols-2">
                <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4">
                    <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Informations</div>
                    <div class="mt-2 text-sm text-slate-700"><span class="font-semibold">Type:</span> <?= htmlspecialchars((string) ($view['type_label'] ?? $view['request_type'])) ?></div>
                    <div class="mt-1 text-sm text-slate-700"><span class="font-semibold">Titre:</span> <?= htmlspecialchars((string) ($view['title'] ?? '')) ?></div>
                    <?php if (($view['request_type'] ?? '') === 'feb' && is_array($febDisplay)): ?>
                        <div class="mt-1 text-sm text-slate-700"><span class="font-semibold">Chantier:</span> <?= htmlspecialchars((string) ($febDisplay['chantier_label'] ?? 'CHANTIER N° —')) ?></div>
                        <div class="mt-1 text-sm text-slate-700"><span class="font-semibold">Demandeur:</span> <?= htmlspecialchars((string) ($febDisplay['demandeur'] ?? ($view['requester_name'] ?? ''))) ?></div>
                    <?php endif; ?>
                    <div class="mt-1 text-sm text-slate-700"><span class="font-semibold">Soumise le:</span> <?= htmlspecialchars(employeeFormatDateFr((string) ($view['submitted_at'] ?? ''))) ?></div>
                    <div class="mt-1 text-sm text-slate-700"><span class="font-semibold">Traitée le:</span> <?= htmlspecialchars(employeeFormatDateFr((string) ($view['decided_at'] ?? ''))) ?></div>
                    <?php if (!empty($view['decided_by_name'])): ?>
                        <div class="mt-1 text-sm text-slate-700"><span class="font-semibold">Par:</span> <?= htmlspecialchars((string) $view['decided_by_name']) ?></div>
                    <?php endif; ?>
                </div>

                <div class="rounded-2xl border border-slate-200 bg-white px-4 py-4">
                    <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Contenu</div>
                    <div class="mt-3 space-y-2 text-sm text-slate-700">
                        <?php if (($view['request_type'] ?? '') === 'feb'): ?>
                            <div class="flex flex-wrap gap-2 pb-2">
                                <a class="rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-900 hover:bg-slate-50"
                                    href="../request/export_feb_devis_excel.php?id=<?= (int) ($view['id'] ?? 0) ?>">
                                    Extraire le devis FEB (Excel)
                                </a>
                                <?php $detailFebBci = trim((string) ($febDisplay['numero_bon_commande_interne'] ?? '')); ?>
                                <?php if ($detailFebBci !== ''): ?><a class="rounded-2xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700" href="../request/export_feb_pdf.php?id=<?= (int) ($view['id'] ?? 0) ?>" target="_blank" rel="noopener">Bon de commande interne (PDF)</a><?php else: ?><span class="rounded-2xl bg-slate-100 px-4 py-2 text-sm font-semibold text-slate-500">BCI disponible après planification</span><?php endif; ?>
                            </div>
                            <div><span class="font-semibold">Département bénéficiaire:</span> <?= htmlspecialchars((string) ($payload['departement_label'] ?? '')) ?></div>
                            <div><span class="font-semibold">Montant estimé:</span> <?= htmlspecialchars(number_format((float) ($payload['montant'] ?? 0), 0, ',', ' ')) ?> XOF</div>
                            <div><span class="font-semibold">Date de livraison:</span> <?= htmlspecialchars(employeeFormatDateFr((string) ($payload['date_livraison'] ?? ''))) ?></div>
                            <div><span class="font-semibold">Fréquence:</span> <?= htmlspecialchars((string) ($payload['frequence'] ?? '')) ?></div>
                            <?php if (false): ?>
                                <div><span class="font-semibold">Traitement financier:</span> <span class="inline-flex rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-800"><?= htmlspecialchars((string) $febDisplay['cashflow_need_label']) ?></span></div>
                            <?php endif; ?>
                            <?php if (!empty($febDisplay['cashflow_comment'] ?? '')): ?>
                                <div><span class="font-semibold">Precision comptable:</span> <?= htmlspecialchars((string) $febDisplay['cashflow_comment']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($febDisplay['provisional_disbursement_amount'] ?? 0)): ?>
                                <div><span class="font-semibold">Avance provisoire demandee:</span> <?= number_format((float) $febDisplay['provisional_disbursement_amount'], 0, ',', ' ') ?> FCFA</div>
                            <?php endif; ?>
                            <?php $employeeBciNumber = trim((string) ($febDisplay['numero_bon_commande_interne'] ?? $febDisplay['code_autorisation_feb'] ?? '')); ?>
                            <?php if ($employeeBciNumber !== ''): ?>
                                <div><span class="font-semibold">N bon de commande interne (BCI):</span> <span class="inline-flex rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-800"><?= htmlspecialchars($employeeBciNumber) ?></span></div>
                            <?php endif; ?>

                            <?php if (!empty($payload['description'])): ?>
                                <div class="pt-2">
                                    <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Description</div>
                                    <div class="mt-1 whitespace-pre-line text-slate-700"><?= htmlspecialchars((string) $payload['description']) ?></div>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($payload['impact'])): ?>
                                <div class="pt-2">
                                    <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Impact</div>
                                    <div class="mt-1 whitespace-pre-line text-slate-700"><?= htmlspecialchars((string) $payload['impact']) ?></div>
                                </div>
                            <?php endif; ?>

                            <?php $besoins = (array) ($payload['besoins'] ?? []); ?>
                            <?php if ($besoins): ?>
                                <div class="pt-2">
                                    <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Besoins</div>
                                    <ul class="mt-2 space-y-2">
                                        <?php foreach ($besoins as $b): ?>
                                            <?php if (!is_array($b)) continue; ?>
                                            <li class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2">
                                                <div class="font-semibold text-slate-900"><?= htmlspecialchars((string) ($b['libelle'] ?? '')) ?> <span class="text-slate-600">(x<?= (int) ($b['quantite'] ?? 0) ?>)</span></div>
                                                <div class="text-xs text-slate-600"><?= htmlspecialchars((string) ($b['type'] ?? '')) ?></div>
                                                <?php if (!empty($b['has_fournisseur']) && !empty($b['fournisseur']) && is_array($b['fournisseur'])): ?>
                                                    <div class="mt-2 text-xs text-slate-700">
                                                        <span class="font-semibold">Fournisseur:</span>
                                                        <?= htmlspecialchars((string) ($b['fournisseur']['nom'] ?? '')) ?>
                                                        <?php if (!empty($b['fournisseur']['prix_unitaire'])): ?>
                                                            — <?= htmlspecialchars(number_format((float) ($b['fournisseur']['prix_unitaire'] ?? 0), 0, ',', ' ')) ?> XOF
                                                        <?php endif; ?>
                                                        <?php if (!empty($b['fournisseur']['telephone'])): ?>
                                                            — <?= htmlspecialchars((string) ($b['fournisseur']['telephone'] ?? '')) ?>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>

                            <?php $invoiceDocument = is_array($payload['invoice_document'] ?? null) ? (array) $payload['invoice_document'] : []; ?>
                            <?php if ($invoiceDocument): ?>
                                <?php $invoiceUrl = resolveUploadsHref((string) ($invoiceDocument['url'] ?? '')); ?>
                                <div class="pt-2">
                                    <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Facture liee au decaissement unique</div>
                                    <div class="mt-2 flex items-center justify-between gap-3 rounded-xl border border-sky-200 bg-sky-50 px-3 py-3">
                                        <div>
                                            <div class="text-sm font-semibold text-slate-900"><?= htmlspecialchars((string) ($invoiceDocument['name'] ?? 'Facture')) ?></div>
                                            <div class="text-xs text-slate-500"><?= htmlspecialchars((string) round(((int) ($invoiceDocument['size'] ?? 0)) / 1024)) ?> Ko</div>
                                        </div>
                                        <?php if ($invoiceUrl !== ''): ?>
                                            <a class="text-sm font-semibold text-indigo-700 hover:text-indigo-800" href="<?= htmlspecialchars($invoiceUrl) ?>" target="_blank" rel="noopener">Ouvrir</a>
                                        <?php else: ?>
                                            <span class="text-xs text-slate-500">Indisponible</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php $justifs = (array) ($payload['justificatifs'] ?? []); ?>
                            <?php if ($justifs): ?>
                                <div class="pt-2">
                                    <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Justificatifs</div>
                                    <ul class="mt-2 space-y-2">
                                        <?php foreach ($justifs as $j): ?>
                                            <?php if (!is_array($j)) continue; ?>
                                            <?php
                                            $safeUrl = resolveUploadsHref((string) ($j['url'] ?? ''));
                                            ?>
                                            <li class="flex items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white px-3 py-2">
                                                <div>
                                                    <div class="text-sm font-semibold text-slate-900"><?= htmlspecialchars((string) ($j['name'] ?? '')) ?></div>
                                                    <div class="text-xs text-slate-500"><?= htmlspecialchars((string) round(((int) ($j['size'] ?? 0)) / 1024)) ?> Ko</div>
                                                </div>
                                                <?php if ($safeUrl !== ''): ?>
                                                    <a class="text-sm font-semibold text-indigo-700 hover:text-indigo-800" href="<?= htmlspecialchars($safeUrl) ?>" target="_blank" rel="noopener">Ouvrir</a>
                                                <?php else: ?>
                                                    <span class="text-xs text-slate-500">Indisponible</span>
                                                <?php endif; ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>
                        <?php elseif (($view['request_type'] ?? '') === 'permission_absence'): ?>
                            <div><span class="font-semibold">Nom:</span> <?= htmlspecialchars((string) ($payload['nom'] ?? '')) ?></div>
                            <div><span class="font-semibold">Matricule:</span> <?= htmlspecialchars((string) ($payload['matricule'] ?? '')) ?></div>
                            <div><span class="font-semibold">Fonction:</span> <?= htmlspecialchars((string) ($payload['fonction'] ?? '')) ?></div>
                            <div><span class="font-semibold">Service:</span> <?= htmlspecialchars((string) ($payload['service'] ?? '')) ?></div>
                            <div><span class="font-semibold">Date de départ:</span> <?= htmlspecialchars(employeeFormatDateFr((string) ($payload['date_depart'] ?? ''))) ?></div>
                            <div><span class="font-semibold">Nombre de jours:</span> <?= htmlspecialchars((string) ($payload['nombre_jours'] ?? '')) ?></div>
                            <div><span class="font-semibold">Date de retour:</span> <?= htmlspecialchars(employeeFormatDateFr((string) ($payload['date_retour'] ?? ''))) ?></div>
                            <?php if (trim((string) ($payload['motif_demande'] ?? '')) !== ''): ?>
                                <div class="pt-2">
                                    <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Motif de la demande</div>
                                    <div class="mt-1 whitespace-pre-line rounded-2xl border border-sky-100 bg-sky-50 px-4 py-3 text-slate-700"><?= htmlspecialchars((string) ($payload['motif_demande'] ?? '')) ?></div>
                                </div>
                            <?php endif; ?>
                        <?php elseif (($view['request_type'] ?? '') === 'conge'): ?>
                            <div><span class="font-semibold">Nom:</span> <?= htmlspecialchars((string) ($payload['nom'] ?? '')) ?></div>
                            <div><span class="font-semibold">Matricule:</span> <?= htmlspecialchars((string) ($payload['matricule'] ?? '')) ?></div>
                            <div><span class="font-semibold">Fonction:</span> <?= htmlspecialchars((string) ($payload['fonction'] ?? '')) ?></div>
                            <div><span class="font-semibold">Service:</span> <?= htmlspecialchars((string) ($payload['service'] ?? '')) ?></div>
                            <div><span class="font-semibold">Date de debut:</span> <?= htmlspecialchars(employeeFormatDateFr((string) ($payload['date_debut'] ?? ''))) ?></div>
                            <div><span class="font-semibold">Date de fin:</span> <?= htmlspecialchars(employeeFormatDateFr((string) ($payload['date_fin'] ?? ''))) ?></div>
                            <div><span class="font-semibold">Nombre de jours:</span> <?= htmlspecialchars((string) ($payload['nombre_jours'] ?? '')) ?></div>
                            <?php if (trim((string) ($payload['motif'] ?? '')) !== ''): ?>
                                <div class="pt-2">
                                    <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Motif de la demande</div>
                                    <div class="mt-1 whitespace-pre-line rounded-2xl border border-cyan-100 bg-cyan-50 px-4 py-3 text-slate-700"><?= htmlspecialchars((string) ($payload['motif'] ?? '')) ?></div>
                                </div>
                            <?php endif; ?>
                            <?php $leaveCalc = is_array($payload['leave_calculation'] ?? null) ? (array) $payload['leave_calculation'] : []; ?>
                            <?php if ($leaveCalc): ?>
                                <?php $leaveSourceLabel = match ((string) ($leaveCalc['source'] ?? '')) {
                                    'opening_balance' => 'Solde initial RH',
                                    'hire_date' => 'Date d\'embauche / prise de service',
                                    default => 'Historique ERP / validation RH speciale',
                                }; ?>
                                <div class="pt-2">
                                    <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Base de calcul congé</div>
                                    <div class="mt-1 rounded-2xl border border-cyan-100 bg-cyan-50 px-4 py-3 text-slate-700">
                                        <div><span class="font-semibold">Source:</span> <?= htmlspecialchars($leaveSourceLabel) ?></div>
                                        <div><span class="font-semibold">Date de référence:</span> <?= htmlspecialchars(employeeFormatDateFr((string) ($leaveCalc['reference_date'] ?? ''))) ?></div>
                                        <div><span class="font-semibold">Solde avant demande:</span> <?= number_format((float) ($leaveCalc['balance_before_request'] ?? 0), 2, ',', ' ') ?> j</div>
                                        <div><span class="font-semibold">Solde estimé après demande:</span> <?= number_format((float) ($leaveCalc['balance_after_request'] ?? 0), 2, ',', ' ') ?> j</div>
                                        <?php if (!empty($leaveCalc['requires_special_validation'])): ?>
                                            <div class="mt-2 font-semibold text-amber-800">Validation RH spéciale requise: situation initiale non renseignée.</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php elseif (($view['request_type'] ?? '') === 'retard'): ?>
                            <div><span class="font-semibold">Nom:</span> <?= htmlspecialchars((string) ($payload['nom'] ?? '')) ?></div>
                            <div><span class="font-semibold">Matricule:</span> <?= htmlspecialchars((string) ($payload['matricule'] ?? '')) ?></div>
                            <div><span class="font-semibold">Fonction:</span> <?= htmlspecialchars((string) ($payload['fonction'] ?? '')) ?></div>
                            <div><span class="font-semibold">Service:</span> <?= htmlspecialchars((string) ($payload['service'] ?? '')) ?></div>
                            <div><span class="font-semibold">Date du retard:</span> <?= htmlspecialchars(employeeFormatDateFr((string) ($payload['date_retard'] ?? ''))) ?></div>
                            <div><span class="font-semibold">Heure d'arrivee:</span> <?= htmlspecialchars((string) ($payload['heure_arrivee'] ?? '')) ?></div>
                            <?php if (trim((string) ($payload['duree_retard'] ?? '')) !== ''): ?>
                                <div><span class="font-semibold">Duree:</span> <?= htmlspecialchars((string) ($payload['duree_retard'] ?? '')) ?></div>
                            <?php endif; ?>
                            <?php if (trim((string) ($payload['motif'] ?? '')) !== ''): ?>
                                <div class="pt-2">
                                    <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Motif du retard</div>
                                    <div class="mt-1 whitespace-pre-line rounded-2xl border border-orange-100 bg-orange-50 px-4 py-3 text-slate-700"><?= htmlspecialchars((string) ($payload['motif'] ?? '')) ?></div>
                                </div>
                            <?php endif; ?>
                        <?php elseif (($view['request_type'] ?? '') === 'heures_supp'): ?>
                            <div><span class="font-semibold">Nom:</span> <?= htmlspecialchars((string) ($payload['nom'] ?? '')) ?></div>
                            <div><span class="font-semibold">Matricule:</span> <?= htmlspecialchars((string) ($payload['matricule'] ?? '')) ?></div>
                            <div><span class="font-semibold">Fonction:</span> <?= htmlspecialchars((string) ($payload['fonction'] ?? '')) ?></div>
                            <div><span class="font-semibold">Service:</span> <?= htmlspecialchars((string) ($payload['service'] ?? '')) ?></div>
                            <div><span class="font-semibold">Nombre d'heures:</span> <?= htmlspecialchars((string) ($payload['heures'] ?? '')) ?> h</div>
                            <?php if (!empty($payload['date_effective'])): ?>
                                <div><span class="font-semibold">Date de prise effective:</span> <?= htmlspecialchars(employeeFormatDateFr((string) ($payload['date_effective'] ?? ''))) ?></div>
                            <?php endif; ?>
                            <?php if (trim((string) ($payload['motif'] ?? '')) !== ''): ?>
                                <div class="pt-2">
                                    <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Motif de la demande</div>
                                    <div class="mt-1 whitespace-pre-line rounded-2xl border border-sky-100 bg-sky-50 px-4 py-3 text-slate-700"><?= htmlspecialchars((string) ($payload['motif'] ?? '')) ?></div>
                                </div>
                            <?php endif; ?>
                        <?php elseif (($view['request_type'] ?? '') === 'recrutement'): ?>
                            <div><span class="font-semibold">Poste recherché:</span> <?=htmlspecialchars((string)($payload['poste_recherche']??''))?></div><div><span class="font-semibold">Service:</span> <?=htmlspecialchars((string)($payload['service_demandeur']??''))?></div><div><span class="font-semibold">Nombre de postes:</span> <?=(int)($payload['nombre_postes']??0)?></div><div><span class="font-semibold">Contrat:</span> <?=htmlspecialchars((string)($payload['type_contrat']??''))?></div><div><span class="font-semibold">Prise de poste souhaitée:</span> <?=htmlspecialchars(employeeFormatDateFr((string)($payload['date_souhaitee']??'')))?></div><div class="grid gap-3 pt-2 sm:grid-cols-3"><div class="rounded-2xl bg-emerald-50 p-4"><small class="font-bold text-emerald-700">Impact entrant</small><b class="mt-1 block"><?=number_format((float)($payload['impact_financier_entrant']??0),0,',',' ')?> FCFA</b></div><div class="rounded-2xl bg-rose-50 p-4"><small class="font-bold text-rose-700">Impact sortant</small><b class="mt-1 block"><?=number_format((float)($payload['impact_financier_sortant']??0),0,',',' ')?> FCFA</b></div><div class="rounded-2xl bg-indigo-50 p-4"><small class="font-bold text-indigo-700">Impact net</small><b class="mt-1 block"><?=number_format((float)($payload['impact_financier_net']??0),0,',',' ')?> FCFA</b></div></div><div class="pt-2"><div class="text-xs font-semibold uppercase tracking-[.2em] text-slate-500">Motif</div><div class="mt-1 whitespace-pre-line rounded-2xl bg-indigo-50 p-4"><?=htmlspecialchars((string)($payload['motif_recrutement']??''))?></div></div><div class="pt-2"><div class="text-xs font-semibold uppercase tracking-[.2em] text-slate-500">Profil recherché</div><div class="mt-1 whitespace-pre-line rounded-2xl bg-slate-50 p-4"><?=htmlspecialchars((string)($payload['profil_recherche']??''))?></div></div><div class="pt-2"><div class="text-xs font-semibold uppercase tracking-[.2em] text-slate-500">Valeur ajoutée attendue</div><div class="mt-1 whitespace-pre-line rounded-2xl bg-amber-50 p-4"><?=htmlspecialchars((string)($payload['valeur_ajoutee']??''))?></div></div>
                        <?php elseif (($view['request_type'] ?? '') === 'reclamation_salariale'): ?>
                            <?php $claim = (array) ($view['salary_claim_display'] ?? \EmployeeLegalRequestManager::extractSalaryClaimDisplay($view)); ?>
                            <div><span class="font-semibold">Date de la demande:</span> <?= htmlspecialchars(employeeFormatDateFr((string) ($claim['date_demande'] ?? ''))) ?></div>
                            <div><span class="font-semibold">Nom:</span> <?= htmlspecialchars((string) ($claim['nom'] ?? '')) ?></div>
                            <div><span class="font-semibold">Prénoms:</span> <?= htmlspecialchars((string) ($claim['prenoms'] ?? '')) ?></div>
                            <div><span class="font-semibold">Matricule:</span> <?= htmlspecialchars((string) ($claim['matricule'] ?? '')) ?></div>
                            <div><span class="font-semibold">Service:</span> <?= htmlspecialchars((string) ($claim['service'] ?? '')) ?></div>
                            <div><span class="font-semibold">Fonction:</span> <?= htmlspecialchars((string) ($claim['fonction'] ?? '')) ?></div>
                            <div><span class="font-semibold">Objet:</span> <?= htmlspecialchars((string) ($claim['objet_label'] ?? '')) ?></div>
                            <div><span class="font-semibold">Mois concerné:</span> <?= htmlspecialchars((string) ($claim['mois_label'] ?? '—')) ?></div>
                            <div><span class="font-semibold">Montant manquant:</span> <?= htmlspecialchars((string) ($claim['montant_label'] ?? '—')) ?></div>
                            <?php if (trim((string) ($claim['details_differend'] ?? '')) !== ''): ?>
                                <div class="pt-2">
                                    <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Détails du différend</div>
                                    <div class="mt-1 whitespace-pre-line rounded-2xl border border-rose-100 bg-rose-50 px-4 py-3 text-slate-700"><?= htmlspecialchars((string) ($claim['details_differend'] ?? '')) ?></div>
                                </div>
                            <?php endif; ?>
                            <div class="pt-2">
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Circuit d’approbation</div>
                                    <span class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold <?= $badge['class'] ?>"><?= htmlspecialchars((string) ($statusMeta['label'] ?? '')) ?></span>
                                </div>
                                <?php $signers = [
                                    ['role' => 'Demandeur', 'display' => trim(((string) ($claim['prenoms'] ?? '')) . ' ' . ((string) ($claim['nom'] ?? ''))), 'signed_at' => (string) ($view['employee_signed_at'] ?? ''), 'url' => $employeeSignatureUrl],
                                    ['role' => 'RH', 'display' => (string) (($view['rh_signed_by_name'] ?? '') ?: ($view['rh_approved_by_name'] ?? 'En attente')), 'signed_at' => (string) ($view['rh_signed_at'] ?? ''), 'url' => $rhSignatureUrl],
                                    ['role' => 'DRH', 'display' => (string) (($view['drh_signed_by_name'] ?? '') ?: ($view['drh_approved_by_name'] ?? 'En attente')), 'signed_at' => (string) ($view['drh_signed_at'] ?? ''), 'url' => $drhSignatureUrl],
                                    ['role' => 'DG', 'display' => (string) (($view['dg_signed_by_name'] ?? '') ?: ($view['dg_approved_by_name'] ?? 'En attente')), 'signed_at' => (string) ($view['dg_signed_at'] ?? ''), 'url' => $dgSignatureUrl],
                                ]; ?>
                                <div class="mt-2 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                    <?php foreach ($signers as $signer): ?>
                                        <div class="rounded-2xl border border-slate-200 bg-slate-50 px-3 py-3">
                                            <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500"><?= htmlspecialchars((string) $signer['role']) ?></div>
                                            <div class="mt-2 text-sm font-semibold text-slate-900"><?= htmlspecialchars(trim((string) ($signer['display'] ?? '')) !== '' ? (string) $signer['display'] : 'En attente') ?></div>
                                            <div class="mt-1 text-xs text-slate-500"><?= !empty($signer['signed_at']) ? ('Signé le ' . employeeFormatDateFr((string) $signer['signed_at'])) : 'Signature non enregistrée' ?></div>
                                            <div class="mt-3 flex min-h-[88px] items-center justify-center rounded-2xl border border-dashed border-slate-200 bg-white text-xs text-slate-400">
                                                <?php if (!empty($signer['url'])): ?>
                                                    <img src="<?= htmlspecialchars((string) $signer['url']) ?>" alt="Signature <?= htmlspecialchars((string) $signer['role']) ?>" style="max-width:100%; max-height:80px; object-fit:contain;">
                                                <?php else: ?>
                                                    Aucune signature
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php if (($view['status'] ?? '') === 'submitted'): ?>
                                <form class="js-signature-pad mt-4 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-4" method="POST">
                                    <input type="hidden" name="action" value="save_signature">
                                    <input type="hidden" name="request_id" value="<?= (int) ($view['id'] ?? 0) ?>">
                                    <input type="hidden" name="signature_base64" value="" class="js-signature-value">
                                    <div class="text-sm font-semibold text-rose-900">Signature du demandeur</div>
                                    <div class="mt-2 text-sm text-rose-800">Votre signature est intégrée au dossier officiel et reste visible tout au long du circuit RH, DRH et DG.</div>
                                    <div class="mt-3 rounded-2xl border border-rose-100 bg-white px-4 py-3 text-xs font-medium text-slate-600 js-signature-status">Aucune signature capturée</div>
                                    <div class="mt-4 signature-pad-shell">
                                        <canvas class="js-signature-canvas"></canvas>
                                        <div class="signature-pad-hint js-signature-hint">Signez ici avec la souris ou le doigt</div>
                                    </div>
                                    <div class="mt-4 flex flex-wrap gap-2">
                                        <button type="button" class="js-signature-clear rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:border-slate-300">Effacer</button>
                                        <button type="button" class="js-signature-use rounded-2xl border border-rose-200 bg-rose-100 px-4 py-2 text-sm font-semibold text-rose-800 hover:bg-rose-200">Utiliser la signature</button>
                                        <button type="submit" class="rounded-2xl bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-700">Enregistrer ma signature</button>
                                    </div>
                                    <div class="mt-4 rounded-2xl border border-slate-200 bg-white p-4">
                                        <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Aperçu</div>
                                        <div class="js-signature-preview mt-3 flex min-h-[92px] items-center justify-center rounded-2xl border border-dashed border-slate-200 bg-slate-50 text-sm text-slate-500" data-empty-label="Aucune signature enregistrée">
                                            <?php if ($employeeSignatureUrl): ?>
                                                <img src="<?= htmlspecialchars($employeeSignatureUrl) ?>" alt="Signature demandeur" style="max-width:100%; max-height:92px; object-fit:contain;">
                                            <?php else: ?>
                                                Aucune signature enregistrée
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </form>
                            <?php endif; ?>
                        <?php else: ?>
                            <?php foreach ($payload as $k => $v): ?>
                                <?php
                                $label = str_replace('_', ' ', (string) $k);
                                $label = ucfirst($label);
                                $value = is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                                ?>
                                <div><span class="font-semibold"><?= htmlspecialchars($label) ?>:</span> <?= htmlspecialchars($value ?? '') ?></div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if (!empty($view['decision_note'])): ?>
                <div class="mt-4 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4 text-sm text-slate-700">
                    <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Note décision</div>
                    <div class="mt-2 whitespace-pre-line"><?= htmlspecialchars((string) $view['decision_note']) ?></div>
                </div>
            <?php endif; ?>

            <?php if ($canEditRequest): ?>
                <form class="mt-4 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-4" method="POST">
                    <div class="text-sm font-semibold text-amber-900">Annuler la demande</div>
                    <div class="mt-2 text-sm text-amber-800">Possible tant qu'elle n'est pas traitée.</div>
                    <div class="mt-3">
                        <label class="text-sm font-semibold text-slate-700">Motif (optionnel)</label>
                        <input type="text" name="note" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2" placeholder="Ex: erreur, doublon...">
                    </div>
                    <input type="hidden" name="action" value="cancel">
                    <input type="hidden" name="request_id" value="<?= (int) ($view['id'] ?? 0) ?>">
                    <div class="mt-3 flex justify-end">
                        <button type="submit" class="rounded-2xl bg-amber-600 px-5 py-2 text-sm font-semibold text-white hover:bg-amber-700">Annuler</button>
                    </div>
                </form>
            <?php endif; ?>

            <div class="mt-5 rounded-2xl border border-slate-200 bg-white px-4 py-4">
                <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Journal</div>
                <div class="mt-3 space-y-2 text-sm">
                    <?php if (!$events): ?>
                        <div class="text-slate-600">Aucun événement.</div>
                    <?php else: ?>
                        <?php foreach ($events as $ev): ?>
                            <div class="flex flex-col gap-1 rounded-xl border border-slate-100 bg-slate-50 px-3 py-3">
                                <div class="flex items-center justify-between gap-3">
                                    <?php $eventType=(string)($ev['event_type']??'');$eventLabels=['submitted'=>'Soumise','updated'=>'Complétée puis soumise à nouveau','approved'=>'Approuvée','rejected'=>'Refusée','feb_validation_reported'=>'Retournée pour complément','feb_more_details_requested'=>'Précisions demandées','feb_updated_by_validator'=>'Modifiée par la Direction / Comptabilité','feb_disbursement_integrated'=>'Déboursé intégré','feb_linked_decaissement_created'=>'Fiche de décaissement générée']; ?>
                                    <div class="font-semibold text-slate-800"><?= htmlspecialchars($eventLabels[$eventType]??ucfirst(str_replace('_',' ',$eventType))) ?></div>
                                    <div class="text-xs text-slate-500"><?= htmlspecialchars(employeeFormatDateFr((string) ($ev['created_at'] ?? ''))) ?></div>
                                </div>
                                <?php if (!empty($ev['event_note'])): ?>
                                    <div class="text-slate-700 whitespace-pre-line"><?= htmlspecialchars((string) $ev['event_note']) ?></div>
                                <?php endif; ?>
                                <?php if (!empty($ev['event_by_name'])): ?>
                                    <div class="text-xs text-slate-500">Par: <?= htmlspecialchars((string) $ev['event_by_name']) ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="../js/rh_explanation_signature_pad.js"></script>
<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>
