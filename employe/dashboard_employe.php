<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/EmployeeLegalRequestManager.php';
require_once __DIR__ . '/../model/EmployeeSchedule.php';
require_once __DIR__ . '/../model/FebValidationPolicy.php';
require_once __DIR__ . '/../model/DisbursementRegularizationManager.php';
require_once __DIR__ . '/../repositories/FournisseurRepository.php';
require_once __DIR__ . '/includes/helpers.php';

$pdo = \Database::getConnection();
$requests = new \EmployeeLegalRequestManager($pdo);
$employeeSchedule = new \EmployeeSchedule($pdo);
$fournisseurRepo = new \FournisseurRepository($pdo);

$userId = (int) ($_SESSION['user_id'] ?? 0);
$userEmail = (string) ($_SESSION['mail_pro'] ?? '');
$pendingDisbursementRegularizations = [];
try {
    $pendingDisbursementRegularizations = (new \DisbursementRegularizationManager($pdo))->pendingForUser($userId);
} catch (Throwable $e) {
    $pendingDisbursementRegularizations = [];
}

$personnel = employeeFindPersonnelProfile($pdo, $userEmail);
$unjustifiedAbsences = $personnel ? employeeListUnjustifiedAbsences($pdo, (int) $personnel['personnel_id'], 31, date('Y-m')) : [];
$absencesToJustifyCount = employeeCountAbsencesToJustify($unjustifiedAbsences);
$absencesPendingValidationCount = employeeCountAbsencesPendingValidation($unjustifiedAbsences);

$myRequests = $requests->listRequestsForUser($userId, 10);

$taskValidationRows = [];
$showTaskValidationQueue = false;
if ($userId > 0) {
    try {
        $showTaskValidationQueue = $employeeSchedule->userHasConfiguredCompletionValidationScope($userId);
        if ($showTaskValidationQueue) {
            $validationStart = (new DateTimeImmutable('today'))->modify('-12 months')->setTime(0, 0, 0);
            $validationEnd = (new DateTimeImmutable('today'))->modify('+12 months')->setTime(23, 59, 59);
            $taskValidationRows = $employeeSchedule->listPendingCompletionValidations($userId, $validationStart, $validationEnd);
        }
    } catch (Throwable $e) {
        $taskValidationRows = [];
        $showTaskValidationQueue = false;
    }
}

$actorMatricule = strtoupper(trim((string) ($personnel['matricule'] ?? '')));

// KPI FEB (inclut legacy via matricule)
$febCounts = $requests->countFebRequestsForUserOrMatricule($userId, $actorMatricule);
$febPending = (int) ($febCounts['submitted'] ?? 0);
$febToComplete = (int) ($febCounts['details_requested'] ?? 0);
$febApproved = (int) ($febCounts['approved'] ?? 0);
$febRejected = (int) ($febCounts['rejected'] ?? 0);

// "Décaissées" = sous-ensemble des approuvées marquées legacy.status_tag=decaissed
$febDecaissed = 0;
try {
    $params = ['uid' => $userId];
    $ownerClause = 'requester_user_id = :uid';
    if ($actorMatricule !== '') {
        $ownerClause .= ' OR payload_json LIKE :matricule_like';
        $params['matricule_like'] = '%"matricule":"' . str_replace('"', '\\"', $actorMatricule) . '"%';
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM employee_legal_requests WHERE request_type = "feb" AND status = "approved" AND COALESCE(meta_legacy_source, "") <> "feb_legacy" AND (' . $ownerClause . ') AND payload_json LIKE :tag');
    $params['tag'] = '%"status_tag":"decaissed"%';
    $stmt->execute($params);
    $febDecaissed = (int) ($stmt->fetchColumn() ?: 0);
} catch (Throwable $e) {
    $febDecaissed = 0;
}

$febValidated = max(0, $febApproved - $febDecaissed);

// KPI "FEB à valider" (périmètre de validation FEB explicite uniquement)
$hasFebValidationAccess = $userId > 0 && \FebValidationPolicy::userHasConfiguredValidationAccess($pdo, $userId);
$hasFinanceAccess = $hasFebValidationAccess;

$febToValidate = 0;
$financeServicePerm = ['all' => false, 'ids' => []];
$febToValidateRows = [];
if ($hasFinanceAccess && $userId > 0) {
    try {
        $perm = \FebValidationPolicy::getUserServicePermissions($pdo, $userId);
        if (is_array($perm)) {
            $financeServicePerm = $perm;
        }

        if (!empty($financeServicePerm['all'])) {
            $counts = $requests->countRequestsForTeam('finance', 'feb', null);
            $febToValidate = (int) ($counts['submitted'] ?? 0);
            $febToValidateRows = $requests->listRequestsForTeamAdvanced('finance', ['submitted'], 'feb', null, 10);
        } elseif (!empty($financeServicePerm['ids']) && is_array($financeServicePerm['ids'])) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $financeServicePerm['ids']), static function (int $v): bool {
                return $v > 0;
            })));
            if ($ids) {
                $counts = $requests->countRequestsForTeam('finance', 'feb', $ids);
                $febToValidate = (int) ($counts['submitted'] ?? 0);
                $febToValidateRows = $requests->listRequestsForTeamAdvanced('finance', ['submitted'], 'feb', $ids, 10);
            }
        }
    } catch (Throwable $e) {
        $febToValidate = 0;
        $febToValidateRows = [];
    }
}

$pendingCount = 0;
foreach ($myRequests as $row) {
    if (($row['status'] ?? '') === 'submitted') {
        $pendingCount++;
    }
}

$mySuppliersCount = 0;
$myPendingSuppliers = 0;
$myApprovedSuppliers = 0;
$myRejectedSuppliers = 0;
$myLatestSuppliers = [];

if ($userId > 0 && $fournisseurRepo->isReady()) {
    try {
        $stmtSuppliers = $pdo->prepare('SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN validation_status = "pending" THEN 1 ELSE 0 END) AS pending_count,
                SUM(CASE WHEN validation_status = "approved" THEN 1 ELSE 0 END) AS approved_count,
                SUM(CASE WHEN validation_status = "rejected" THEN 1 ELSE 0 END) AS rejected_count
            FROM fournisseur_banamur
            WHERE created_by_user_id = :user_id');
        $stmtSuppliers->execute(['user_id' => $userId]);
        $supplierCounts = $stmtSuppliers->fetch(PDO::FETCH_ASSOC) ?: [];
        $mySuppliersCount = (int) ($supplierCounts['total'] ?? 0);
        $myPendingSuppliers = (int) ($supplierCounts['pending_count'] ?? 0);
        $myApprovedSuppliers = (int) ($supplierCounts['approved_count'] ?? 0);
        $myRejectedSuppliers = (int) ($supplierCounts['rejected_count'] ?? 0);
        $myLatestSuppliers = $fournisseurRepo->listLatest(5, false, ['created_by_user_id' => $userId]);
    } catch (Throwable $e) {
        $mySuppliersCount = 0;
        $myPendingSuppliers = 0;
        $myApprovedSuppliers = 0;
        $myRejectedSuppliers = 0;
        $myLatestSuppliers = [];
    }
}

function statusBadge(string $status): array
{
    $status = strtolower(trim($status));

    $map = [
        'submitted' => ['label' => 'En attente', 'class' => 'bg-amber-100 text-amber-800 border-amber-200'],
        'details_requested' => ['label' => 'Compléments demandés', 'class' => 'bg-sky-100 text-sky-800 border-sky-200'],
        'approved' => ['label' => 'Approuvée', 'class' => 'bg-emerald-100 text-emerald-800 border-emerald-200'],
        'rejected' => ['label' => 'Refusée', 'class' => 'bg-rose-100 text-rose-800 border-rose-200'],
        'cancelled' => ['label' => 'Annulée', 'class' => 'bg-slate-100 text-slate-700 border-slate-200'],
    ];

    return $map[$status] ?? ['label' => $status ?: '—', 'class' => 'bg-slate-100 text-slate-700 border-slate-200'];
}

$pageTitle = 'Espace Employé';
$layoutRootBase = '../';
$activeEmployeeMenu = 'dashboard';

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<style>
    .employee-semantic-card {
        --card-accent: #4f46e5;
        --card-soft: #eef2ff;
        --card-strong: #312e81;
        position: relative;
        isolation: isolate;
        overflow: hidden;
        border: 1px solid color-mix(in srgb, var(--card-accent) 32%, #dbe3ee) !important;
        background: linear-gradient(145deg, #fff 32%, var(--card-soft) 100%) !important;
        box-shadow: 0 12px 28px color-mix(in srgb, var(--card-accent) 12%, transparent) !important;
    }
    .employee-semantic-card::before {
        content: '';
        position: absolute;
        inset: 0 auto 0 0;
        width: 5px;
        background: var(--card-accent);
        z-index: 2;
    }
    .employee-semantic-card::after {
        content: '';
        position: absolute;
        right: -42px;
        top: -54px;
        width: 130px;
        height: 130px;
        border-radius: 999px;
        background: color-mix(in srgb, var(--card-accent) 12%, transparent);
        pointer-events: none;
    }
    .employee-semantic-card > * { position: relative; z-index: 1; }
    .employee-semantic-card > .absolute.inset-0 { display:none; }
    .employee-semantic-card [class*="to-slate-900"] { background-image:none !important;background-color:var(--card-accent) !important; }
    a.employee-semantic-card { transition: transform .2s ease, box-shadow .2s ease, border-color .2s ease; }
    a.employee-semantic-card:hover {
        transform: translateY(-4px);
        border-color: var(--card-accent) !important;
        box-shadow: 0 18px 38px color-mix(in srgb, var(--card-accent) 20%, transparent) !important;
    }
    .employee-card-icon {
        background: var(--card-accent) !important;
        color: #fff !important;
        box-shadow: 0 8px 20px color-mix(in srgb, var(--card-accent) 32%, transparent) !important;
    }
    .employee-card-link, .employee-card-value { color: var(--card-strong) !important; }
    .employee-tone-indigo { --card-accent:#4f46e5;--card-soft:#eef2ff;--card-strong:#3730a3; }
    .employee-tone-emerald { --card-accent:#059669;--card-soft:#ecfdf5;--card-strong:#047857; }
    .employee-tone-amber { --card-accent:#d97706;--card-soft:#fffbeb;--card-strong:#92400e; }
    .employee-tone-rose { --card-accent:#e11d48;--card-soft:#fff1f2;--card-strong:#9f1239; }
    .employee-tone-cyan { --card-accent:#0891b2;--card-soft:#ecfeff;--card-strong:#155e75; }
    .employee-tone-violet { --card-accent:#7c3aed;--card-soft:#f5f3ff;--card-strong:#5b21b6; }
    .employee-tone-fuchsia { --card-accent:#c026d3;--card-soft:#fdf4ff;--card-strong:#86198f; }
    .employee-tone-sky { --card-accent:#0284c7;--card-soft:#f0f9ff;--card-strong:#075985; }
    body.finea-dark-mode .employee-semantic-card {
        background: linear-gradient(145deg, #172337 25%, color-mix(in srgb, var(--card-accent) 18%, #111c2d) 100%) !important;
        border-color: color-mix(in srgb, var(--card-accent) 50%, #34445c) !important;
    }
    body.finea-dark-mode .employee-semantic-card :where(.text-slate-950,.text-slate-900) { color:#f8fafc !important; }
    body.finea-dark-mode .employee-card-link,
    body.finea-dark-mode .employee-card-value { color:color-mix(in srgb, var(--card-accent) 35%, #fff) !important; }
</style>

<div class="min-h-screen bg-[radial-gradient(circle_at_top_left,_rgba(240,249,255,0.95),_transparent_42%),radial-gradient(circle_at_top_right,_rgba(224,231,255,0.85),_transparent_35%),linear-gradient(180deg,_#f8fafc_0%,_#eef2ff_100%)] pb-12">
    <div class="mx-auto max-w-[1600px] px-4 py-6 2xl:px-8">
        <?php if ($pendingDisbursementRegularizations): ?>
        <div class="mb-6 rounded-2xl border border-amber-300 bg-amber-50 p-5 shadow-sm">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <div class="font-bold text-amber-950"><i class="fas fa-triangle-exclamation mr-2"></i><?= count($pendingDisbursementRegularizations) ?> décaissement(s) à régulariser</div>
                    <p class="mt-1 text-sm text-amber-900">Transmettez le PV signé et la preuve de paiement pour obtenir le visa comptable.</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <?php foreach (array_slice($pendingDisbursementRegularizations, 0, 3) as $regularization): ?>
                    <a class="rounded-xl bg-amber-600 px-4 py-2 text-sm font-bold text-white hover:bg-amber-700" href="../decaissement/regularisation_decaissement.php?num_fiche=<?= rawurlencode((string)$regularization['num_fiche']) ?>">Régulariser <?= htmlspecialchars((string)$regularization['num_fiche']) ?></a>
                    <?php endforeach ?>
                </div>
            </div>
        </div>
        <?php endif ?>
        <div class="relative overflow-hidden rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="pointer-events-none absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-indigo-500 via-violet-500 to-emerald-500"></div>
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.28em] text-indigo-600">Accueil</p>
                    <h1 class="mt-2 text-3xl font-bold tracking-tight text-slate-950">Bonjour, <?= htmlspecialchars(employeeCurrentUserName()) ?></h1>
                    <p class="mt-1 max-w-3xl text-sm text-slate-600">Retrouvez vos alertes, vos demandes RH/FEB, vos fournisseurs et vos suivis financiers dans un espace de travail plus direct.</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a href="pointage_employe.php" class="inline-flex items-center rounded-2xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                        <i class="fas fa-clock mr-2"></i>
                        Pointage
                    </a>
                    <a href="actes_legaux_employe.php" class="inline-flex items-center rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-900 hover:bg-slate-50">
                        <i class="fas fa-file-signature mr-2 text-indigo-600"></i>
                        Actes légaux
                    </a>
                    <a href="../decaissement/formulaire_demande_decaissement.php" class="inline-flex items-center rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-900 hover:bg-slate-50">
                        <i class="fas fa-money-check-dollar mr-2 text-emerald-600"></i>
                        Fiche de Décaissement
                    </a>
                    <a href="nouvelle_demande_decaissement_urgent.php" class="inline-flex items-center rounded-2xl border border-rose-200 bg-rose-50 px-4 py-2 text-sm font-semibold text-rose-800 hover:bg-rose-100">
                        <i class="fas fa-bolt mr-2 text-rose-600"></i>
                        Urgence
                    </a>
                    <a href="mes_fournisseurs_employe.php" class="inline-flex items-center rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-900 hover:bg-slate-50">
                        <i class="fas fa-industry mr-2 text-cyan-600"></i>
                        Mes fournisseurs
                    </a>
                    <a href="mes_explications_employe.php" class="inline-flex items-center rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-900 hover:bg-slate-50">
                        <i class="fas fa-comments mr-2 text-rose-600"></i>
                        Mes explications
                    </a>
                    <a href="nouvelle_demande_logistique.php" class="inline-flex items-center rounded-2xl border border-violet-200 bg-violet-50 px-4 py-2 text-sm font-semibold text-violet-800 hover:bg-violet-100">
                        <i class="fas fa-truck-ramp-box mr-2 text-violet-600"></i>
                        Demande logistique
                    </a>
                </div>
            </div>

            <details class="group mt-5 overflow-hidden rounded-2xl border border-slate-200 bg-slate-50/80 shadow-sm">
                <summary class="flex cursor-pointer list-none items-center justify-between gap-4 px-5 py-4 [&::-webkit-details-marker]:hidden">
                    <div class="flex items-center gap-3">
                        <span class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-violet-600 text-white shadow-sm">
                            <i class="fas fa-bolt"></i>
                        </span>
                        <div>
                            <div class="text-sm font-bold text-slate-950">Acces rapides</div>
                            <div class="text-xs text-slate-500">Ouvrir les actions frequentes sans quitter le haut du tableau de bord</div>
                        </div>
                    </div>
                    <i class="fas fa-chevron-down text-slate-400 transition-transform group-open:rotate-180"></i>
                </summary>

                <div class="border-t border-slate-200 bg-white p-4">
                    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-6">
                        <a href="../decaissement/formulaire_demande_decaissement.php" class="employee-semantic-card employee-tone-emerald group/action rounded-2xl px-4 py-3">
                            <div class="relative flex items-center justify-between gap-3">
                                <div>
                                    <div class="text-sm font-semibold text-slate-950">Fiche de Decaissement</div>
                                    <div class="mt-0.5 text-xs text-slate-600">Ouvrir le formulaire</div>
                                </div>
                                <div class="employee-card-icon flex h-10 w-10 items-center justify-center rounded-xl">
                                    <i class="fas fa-money-check-dollar"></i>
                                </div>
                            </div>
                        </a>

                        <a href="nouvelle_demande_decaissement_urgent.php" class="employee-semantic-card employee-tone-rose group/action rounded-2xl px-4 py-3">
                            <div class="relative flex items-center justify-between gap-3">
                                <div>
                                    <div class="text-sm font-semibold text-slate-950">Urgence</div>
                                    <div class="mt-0.5 text-xs text-slate-600">Decaissement direct</div>
                                </div>
                                <div class="employee-card-icon flex h-10 w-10 items-center justify-center rounded-xl">
                                    <i class="fas fa-bolt"></i>
                                </div>
                            </div>
                        </a>
                        <a href="actes_legaux_employe.php" class="employee-semantic-card employee-tone-indigo group/action rounded-2xl px-4 py-3">
                            <div class="relative flex items-center justify-between gap-3">
                                <div>
                                    <div class="text-sm font-semibold text-slate-950">Demande RH ou FEB</div>
                                    <div class="mt-0.5 text-xs text-slate-600">Absence, avance, HS, FEB</div>
                                </div>
                                <div class="employee-card-icon flex h-10 w-10 items-center justify-center rounded-xl">
                                    <i class="fas fa-file-signature"></i>
                                </div>
                            </div>
                        </a>

                        <a href="mes_demandes_employe.php" class="employee-semantic-card employee-tone-violet group/action rounded-2xl px-4 py-3">
                            <div class="relative flex items-center justify-between gap-3">
                                <div>
                                    <div class="text-sm font-semibold text-slate-950">Suivre mes demandes</div>
                                    <div class="mt-0.5 text-xs text-slate-600">Statuts RH / Finances</div>
                                </div>
                                <div class="employee-card-icon flex h-10 w-10 items-center justify-center rounded-xl">
                                    <i class="fas fa-clipboard-list"></i>
                                </div>
                            </div>
                        </a>

                        <a href="mes_fournisseurs_employe.php" class="employee-semantic-card employee-tone-cyan group/action rounded-2xl px-4 py-3">
                            <div class="relative flex items-center justify-between gap-3">
                                <div>
                                    <div class="text-sm font-semibold text-slate-950">Mes fournisseurs</div>
                                    <div class="mt-0.5 text-xs text-slate-600">Creation et suivi comptable</div>
                                </div>
                                <div class="employee-card-icon flex h-10 w-10 items-center justify-center rounded-xl">
                                    <i class="fas fa-industry"></i>
                                </div>
                            </div>
                        </a>

                        <a href="mes_explications_employe.php" class="employee-semantic-card employee-tone-amber group/action rounded-2xl px-4 py-3">
                            <div class="relative flex items-center justify-between gap-3">
                                <div>
                                    <div class="text-sm font-semibold text-slate-950">Explications RH</div>
                                    <div class="mt-0.5 text-xs text-slate-600">Reponses et historique</div>
                                </div>
                                <div class="employee-card-icon flex h-10 w-10 items-center justify-center rounded-xl">
                                    <i class="fas fa-comments"></i>
                                </div>
                            </div>
                        </a>
                    </div>
                </div>
            </details>

            <?php if (!$personnel): ?>
                <div class="mt-5 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-4 text-sm text-amber-900">
                    <div class="font-semibold">Compte non lié au personnel RH</div>
                    <div class="mt-1 text-amber-800">Aucun profil du personnel n'a été trouvé pour l'email <span class="font-semibold"><?= htmlspecialchars($userEmail) ?></span>. Le pointage et les alertes RH seront indisponibles.</div>
                </div>
            <?php else: ?>
                <div class="mt-5 grid gap-4 md:grid-cols-3 xl:grid-cols-5">
                    <div class="employee-semantic-card employee-tone-fuchsia group rounded-3xl p-5">
                        <div class="absolute inset-0 bg-gradient-to-br from-white to-slate-50 transition group-hover:from-fuchsia-50 group-hover:to-indigo-50"></div>
                        <div class="relative">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Profil RH</div>
                                    <div class="mt-2 text-sm font-semibold text-slate-950"><?= htmlspecialchars((string) $personnel['full_name']) ?></div>
                                    <div class="mt-1 text-sm text-slate-600"><?= htmlspecialchars((string) $personnel['fonction_label']) ?> — <?= htmlspecialchars((string) $personnel['service_label']) ?></div>
                                    <div class="mt-1 text-xs text-slate-500">Matricule: <?= htmlspecialchars((string) $personnel['matricule']) ?></div>
                                </div>
                                <div class="employee-card-icon flex h-12 w-12 items-center justify-center rounded-2xl transition-transform duration-300 group-hover:scale-110">
                                    <i class="fas fa-id-badge"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <a class="employee-semantic-card employee-tone-amber group rounded-3xl p-5" href="pointage_employe.php">
                        <div class="absolute inset-0 bg-gradient-to-br from-white to-slate-50 transition group-hover:from-amber-50 group-hover:to-rose-50"></div>
                        <div class="relative">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Absences a justifier</div>
                                    <div class="mt-2 flex items-end gap-2">
                                        <div class="text-3xl font-semibold text-slate-950"><?= (int) $absencesToJustifyCount ?></div>
                                        <div class="pb-1 text-sm text-slate-600">à justifier</div>
                                    </div>
                                    <?php if ($absencesPendingValidationCount > 0): ?>
                                        <div class="mt-2 text-xs font-semibold text-indigo-700"><?= (int) $absencesPendingValidationCount ?> en attente RH</div>
                                    <?php endif; ?>
                                </div>
                                <div class="employee-card-icon flex h-12 w-12 items-center justify-center rounded-2xl transition-transform duration-300 group-hover:scale-110">
                                    <i class="fas fa-bell"></i>
                                </div>
                            </div>
                            <div class="employee-card-link mt-3 inline-flex items-center gap-2 text-sm font-semibold">
                                <span>Voir le détail</span>
                                <i class="fas fa-arrow-right text-xs"></i>
                            </div>
                        </div>
                    </a>

                    <a class="employee-semantic-card employee-tone-indigo group rounded-3xl p-5" href="mes_demandes_employe.php">
                        <div class="absolute inset-0 bg-gradient-to-br from-white to-slate-50 transition group-hover:from-indigo-50 group-hover:to-emerald-50"></div>
                        <div class="relative">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Demandes</div>
                                    <div class="mt-2 flex items-end gap-2">
                                        <div class="text-3xl font-semibold text-slate-950"><?= (int) $pendingCount ?></div>
                                        <div class="pb-1 text-sm text-slate-600">en attente</div>
                                    </div>
                                </div>
                                <div class="employee-card-icon flex h-12 w-12 items-center justify-center rounded-2xl transition-transform duration-300 group-hover:scale-110">
                                    <i class="fas fa-inbox"></i>
                                </div>
                            </div>
                            <div class="employee-card-link mt-3 inline-flex items-center gap-2 text-sm font-semibold">
                                <span>Suivre mes demandes</span>
                                <i class="fas fa-arrow-right text-xs"></i>
                            </div>
                        </div>
                    </a>

                    <a class="employee-semantic-card employee-tone-cyan group rounded-3xl p-5" href="mes_fournisseurs_employe.php">
                        <div class="absolute inset-0 bg-gradient-to-br from-white to-slate-50 transition group-hover:from-cyan-50 group-hover:to-sky-50"></div>
                        <div class="relative">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Fournisseurs</div>
                                    <div class="mt-2 flex items-end gap-2">
                                        <div class="text-3xl font-semibold text-slate-950"><?= (int) $mySuppliersCount ?></div>
                                        <div class="pb-1 text-sm text-slate-600">saisis</div>
                                    </div>
                                </div>
                                <div class="employee-card-icon flex h-12 w-12 items-center justify-center rounded-2xl transition-transform duration-300 group-hover:scale-110">
                                    <i class="fas fa-industry"></i>
                                </div>
                            </div>
                            <div class="employee-card-link mt-3 inline-flex items-center gap-2 text-sm font-semibold">
                                <span>Gerer</span>
                                <i class="fas fa-arrow-right text-xs"></i>
                            </div>
                        </div>
                    </a>

                    <a class="employee-semantic-card employee-tone-rose group rounded-3xl p-5" href="mes_explications_employe.php">
                        <div class="absolute inset-0 bg-gradient-to-br from-white to-slate-50 transition group-hover:from-rose-50 group-hover:to-amber-50"></div>
                        <div class="relative">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Explications RH</div>
                                    <div class="mt-2 text-sm font-semibold text-slate-950">Suivi et reponses</div>
                                    <div class="mt-1 text-xs text-slate-500">Historique RH</div>
                                </div>
                                <div class="employee-card-icon flex h-12 w-12 items-center justify-center rounded-2xl transition-transform duration-300 group-hover:scale-110">
                                    <i class="fas fa-comments"></i>
                                </div>
                            </div>
                            <div class="employee-card-link mt-3 inline-flex items-center gap-2 text-sm font-semibold">
                                <span>Ouvrir</span>
                                <i class="fas fa-arrow-right text-xs"></i>
                            </div>
                        </div>
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <section class="mt-6 grid gap-5 overflow-hidden 2xl:grid-cols-[minmax(0,1.15fr)_minmax(320px,0.85fr)]">
            <div class="min-w-0 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.28em] text-indigo-600">Priorites</p>
                        <h2 class="mt-1 text-xl font-bold text-slate-950">FEB a valider</h2>
                        <p class="mt-1 text-sm text-slate-600">Les demandes a traiter sont remontees ici pour eviter de descendre en bas de page.</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="inline-flex items-center rounded-full border border-indigo-200 bg-indigo-50 px-3 py-1 text-sm font-bold text-indigo-800">
                            <?= (int) $febToValidate ?> a traiter
                        </span>
                        <a href="../validation_demandes_finances.php?tab=mine_pending" class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                            <i class="fas fa-arrow-up-right-from-square"></i>
                            Validation
                        </a>
                    </div>
                </div>

                <?php if (!$hasFinanceAccess): ?>
                    <div class="mt-4 rounded-xl border border-slate-200 bg-slate-50 px-4 py-5 text-sm text-slate-600">
                        Aucun perimetre de validation FEB n'est configure pour ce compte.
                    </div>
                <?php elseif (empty($febToValidateRows)): ?>
                    <div class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-5 text-sm text-emerald-800">
                        Aucune FEB en attente pour vos services.
                    </div>
                <?php else: ?>
                    <div class="mt-5 grid gap-4 2xl:grid-cols-2">
                        <?php foreach (array_slice($febToValidateRows, 0, 6) as $row): ?>
                            <?php
                            $rowId = (int) ($row['id'] ?? 0);
                            $payload = (array) ($row['payload'] ?? []);
                            $febDisplay = (array) ($row['feb_display'] ?? \EmployeeLegalRequestManager::extractFebDisplay($row));
                            $serviceLabel = (string) ($payload['departement_label'] ?? '');
                            if ($serviceLabel === '') {
                                $metaServiceId = (int) ($row['meta_service_id'] ?? 0);
                                $serviceLabel = $metaServiceId > 0 ? ('Service #' . $metaServiceId) : 'Service non renseigne';
                            }
                            $montantLabel = (string) ($febDisplay['montant_label'] ?? 'Montant non renseigne');
                            $createdDate = (string) (($febDisplay['created_date'] ?? '') ?: substr((string) ($row['submitted_at'] ?? ''), 0, 10));
                            $desc = (string) ($febDisplay['description'] ?? '');
                            $demandeurLabel = trim((string) ($febDisplay['demandeur'] ?? ($row['requester_name'] ?? '')));
                            if ($demandeurLabel === '') {
                                $demandeurLabel = 'Demandeur non renseigne';
                            }
                            ?>
                            <article class="employee-semantic-card employee-tone-amber min-w-0 rounded-2xl p-5 transition hover:-translate-y-0.5">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="inline-flex rounded-full bg-indigo-50 px-2.5 py-1 text-[11px] font-bold text-indigo-700">FEB #<?= $rowId ?></span>
                                            <span class="inline-flex rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-semibold text-slate-600"><?= htmlspecialchars($serviceLabel) ?></span>
                                        </div>
                                        <div class="mt-3 break-words text-base font-bold leading-6 text-slate-950"><?= htmlspecialchars((string) ($febDisplay['chantier_label'] ?? ('FEB-' . $rowId))) ?></div>
                                    </div>
                                    <span class="shrink-0 rounded-full border border-amber-200 bg-amber-50 px-2.5 py-1 text-[11px] font-bold text-amber-700">En attente</span>
                                </div>
                                <p class="mt-4 min-h-[72px] break-words text-sm leading-6 text-slate-600"><?= htmlspecialchars($desc !== '' ? $desc : 'Aucune description disponible.') ?></p>
                                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                                    <div class="rounded-xl border border-slate-200 bg-white px-3 py-3">
                                        <span class="block text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-500">Demandeur</span>
                                        <span class="mt-1 block break-words text-sm font-semibold text-slate-900"><?= htmlspecialchars($demandeurLabel) ?></span>
                                    </div>
                                    <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-3">
                                        <span class="block text-[11px] font-semibold uppercase tracking-[0.14em] text-emerald-700">Montant</span>
                                        <span class="mt-1 block break-words text-sm font-bold text-emerald-900"><?= htmlspecialchars($montantLabel) ?></span>
                                    </div>
                                </div>
                                <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 pt-4">
                                    <span class="text-xs font-medium text-slate-500"><?= htmlspecialchars($createdDate) ?></span>
                                    <?php if ($rowId > 0): ?>
                                        <a class="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-4 py-2 text-xs font-bold text-white hover:bg-indigo-700" href="../validation_demandes_finances.php?id=<?= $rowId ?>">
                                            Traiter
                                            <i class="fas fa-arrow-right"></i>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <aside class="min-w-0 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.28em] text-slate-500">Synthese</p>
                        <h2 class="mt-1 text-lg font-bold text-slate-950">Vue immediate</h2>
                    </div>
                    <a href="mes_demandes_employe.php" class="text-sm font-semibold text-indigo-700 hover:text-indigo-800">Tout voir</a>
                </div>
                <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    <a href="mes_demandes_employe.php?type=feb&status=submitted" class="employee-semantic-card employee-tone-amber min-w-0 rounded-2xl p-4">
                        <div class="text-xs font-semibold uppercase text-amber-700">Mes FEB attente</div>
                        <div class="employee-card-value mt-2 text-3xl font-bold"><?= (int) $febPending ?></div>
                    </a>
                    <a href="mes_demandes_employe.php?type=feb&status=details_requested" class="employee-semantic-card employee-tone-sky min-w-0 rounded-2xl p-4">
                        <div class="text-xs font-semibold uppercase text-sky-700">FEB à compléter</div>
                        <div class="employee-card-value mt-2 text-3xl font-bold"><?= (int) $febToComplete ?></div>
                    </a>
                    <a href="mes_demandes_employe.php?type=feb&status=approved" class="employee-semantic-card employee-tone-emerald min-w-0 rounded-2xl p-4">
                        <div class="text-xs font-semibold uppercase text-emerald-700">FEB validees</div>
                        <div class="employee-card-value mt-2 text-3xl font-bold"><?= (int) $febValidated ?></div>
                    </a>
                    <a href="mes_fournisseurs_employe.php" class="employee-semantic-card employee-tone-cyan min-w-0 rounded-2xl p-4">
                        <div class="text-xs font-semibold uppercase text-cyan-700">Fournisseurs</div>
                        <div class="employee-card-value mt-2 text-3xl font-bold"><?= (int) $mySuppliersCount ?></div>
                    </a>
                    <a href="pointage_employe.php" class="employee-semantic-card employee-tone-rose min-w-0 rounded-2xl p-4">
                        <div class="text-xs font-semibold uppercase text-rose-700">Absences</div>
                        <div class="employee-card-value mt-2 text-3xl font-bold"><?= (int) $absencesToJustifyCount ?></div>
                    </a>
                </div>

                <div class="mt-5 space-y-3">
                    <?php foreach (array_slice($myRequests, 0, 3) as $row): ?>
                        <?php
                        $requestStatus=(string)($row['status']??'');
                        $badge=statusBadge($requestStatus);
                        $requestTone=match($requestStatus){
                            'submitted'=>'employee-tone-amber',
                            'details_requested'=>'employee-tone-sky',
                            'approved'=>'employee-tone-emerald',
                            'rejected'=>'employee-tone-rose',
                            default=>'employee-tone-indigo',
                        };
                        ?>
                        <div class="employee-semantic-card <?= $requestTone ?> min-w-0 rounded-2xl px-4 py-3">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="break-words text-sm font-semibold text-slate-900"><?= htmlspecialchars((string) ($row['type_label'] ?? $row['request_type'])) ?></div>
                                    <div class="mt-1 break-words text-xs leading-5 text-slate-500"><?= htmlspecialchars((string) ($row['title'] ?? '')) ?></div>
                                </div>
                                <span class="shrink-0 rounded-full border px-2 py-1 text-[10px] font-bold <?= $badge['class'] ?>"><?= htmlspecialchars($badge['label']) ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </aside>
        </section>

        <?php if ($showTaskValidationQueue): ?>
            <section class="mt-6 rounded-2xl border border-amber-200 bg-white p-5 shadow-sm">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.28em] text-amber-600">Validation hierarchique</p>
                        <h2 class="mt-1 text-xl font-bold text-slate-950">Taches terminees a valider</h2>
                        <p class="mt-1 text-sm text-slate-600">File N+1 issue de la hierarchie RH configuree pour votre compte.</p>
                    </div>
                    <span class="inline-flex items-center rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-sm font-bold text-amber-800">
                        <?= count($taskValidationRows) ?> en attente
                    </span>
                </div>

                <div id="employee-task-validation-error" class="mt-4 hidden rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700"></div>

                <div class="mt-5 overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead>
                            <tr class="border-b border-slate-100 text-xs uppercase tracking-wide text-slate-400">
                                <th class="py-3 pr-4">Tache</th>
                                <th class="py-3 pr-4">Personnel</th>
                                <th class="py-3 pr-4">Service</th>
                                <th class="py-3 pr-4">Periode</th>
                                <th class="py-3 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody id="employee-task-validation-rows">
                            <?php foreach (array_slice($taskValidationRows, 0, 12) as $taskValidation): ?>
                                <tr class="border-b border-slate-100 last:border-b-0" data-validation-event-id="<?= (int)($taskValidation['id'] ?? 0) ?>">
                                    <td class="py-4 pr-4">
                                        <div class="font-semibold text-slate-950"><?= htmlspecialchars((string)($taskValidation['title'] ?? 'Tache')) ?></div>
                                        <?php if (trim((string)($taskValidation['description'] ?? '')) !== ''): ?>
                                            <div class="mt-1 max-w-2xl text-xs leading-5 text-slate-500"><?= htmlspecialchars((string)$taskValidation['description']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-4 pr-4 text-slate-700">
                                        <?= htmlspecialchars((string)($taskValidation['personnel_name'] ?? '')) ?>
                                        <?php if (trim((string)($taskValidation['personnel_matricule'] ?? '')) !== ''): ?>
                                            <div class="text-xs text-slate-500"><?= htmlspecialchars((string)$taskValidation['personnel_matricule']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-4 pr-4 text-slate-600"><?= htmlspecialchars((string)($taskValidation['service_label'] ?? '')) ?></td>
                                    <td class="py-4 pr-4 text-slate-600">
                                        <?= htmlspecialchars(substr((string)($taskValidation['start_at'] ?? ''), 0, 16)) ?>
                                        <span class="text-slate-400">-</span>
                                        <?= htmlspecialchars(substr((string)($taskValidation['end_at'] ?? ''), 0, 16)) ?>
                                    </td>
                                    <td class="py-4 text-right">
                                        <button type="button" class="employee-validate-task-btn rounded-xl bg-emerald-600 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-700" data-event-id="<?= (int)($taskValidation['id'] ?? 0) ?>">
                                            Valider termine
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$taskValidationRows): ?>
                                <tr>
                                    <td colspan="5" class="py-6 text-center text-slate-500">Aucune tache a valider pour votre perimetre RH.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>

        <section class="hidden mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.28em] text-indigo-600">Actions</p>
                    <h2 class="mt-1 text-lg font-semibold text-slate-950">Accès rapide</h2>
                </div>
                <div class="text-sm text-slate-600">Raccourcis pratiques</div>
            </div>

            <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-5">


                <a href="../decaissement/formulaire_demande_decaissement.php" class="group relative overflow-hidden rounded-2xl border border-slate-200 bg-white/80 px-4 py-3 shadow-[0_6px_22px_rgb(0,0,0,0.05)] backdrop-blur-md transition-all duration-300 hover:-translate-y-0.5 hover:shadow-[0_12px_34px_rgb(0,0,0,0.08)]">
                    <div class="absolute inset-0 bg-gradient-to-br from-white to-slate-50 transition group-hover:from-cyan-50 group-hover:to-emerald-50"></div>
                    <div class="relative flex items-center justify-between gap-3">
                        <div>
                            <div class="text-sm font-semibold text-slate-950">Fiche de Décaissement</div>
                            <div class="mt-0.5 text-xs text-slate-600">Ouvrir le formulaire</div>
                        </div>
                        <div class="flex h-10 w-10 items-center justify-center rounded-2xl bg-gradient-to-br from-cyan-600 to-slate-900 text-white shadow-md ring-1 ring-white/20">
                            <i class="fas fa-money-check-dollar text-lg drop-shadow"></i>
                        </div>
                    </div>
                </a>

                <a href="actes_legaux_employe.php" class="group relative overflow-hidden rounded-2xl border border-slate-200 bg-white/80 px-4 py-3 shadow-[0_6px_22px_rgb(0,0,0,0.05)] backdrop-blur-md transition-all duration-300 hover:-translate-y-0.5 hover:shadow-[0_12px_34px_rgb(0,0,0,0.08)]">
                    <div class="absolute inset-0 bg-gradient-to-br from-white to-slate-50 transition group-hover:from-indigo-50 group-hover:to-purple-50"></div>
                    <div class="relative flex items-center justify-between gap-3">
                        <div>
                            <div class="text-sm font-semibold text-slate-950">Faire une demande RH ou FEB</div>
                            <div class="mt-0.5 text-xs text-slate-600">Absence, prêt, avance, heures sup, réclamation salariale, FEB</div>
                        </div>
                        <div class="flex h-10 w-10 items-center justify-center rounded-2xl bg-gradient-to-br from-indigo-600 to-slate-900 text-white shadow-md ring-1 ring-white/20">
                            <i class="fas fa-file-signature text-lg drop-shadow"></i>
                        </div>
                    </div>
                </a>

                <a href="mes_demandes_employe.php" class="group relative overflow-hidden rounded-2xl border border-slate-200 bg-white/80 px-4 py-3 shadow-[0_6px_22px_rgb(0,0,0,0.05)] backdrop-blur-md transition-all duration-300 hover:-translate-y-0.5 hover:shadow-[0_12px_34px_rgb(0,0,0,0.08)]">
                    <div class="absolute inset-0 bg-gradient-to-br from-white to-slate-50 transition group-hover:from-emerald-50 group-hover:to-indigo-50"></div>
                    <div class="relative flex items-center justify-between gap-3">
                        <div>
                            <div class="text-sm font-semibold text-slate-950">Suivre mes demandes</div>
                            <div class="mt-0.5 text-xs text-slate-600">Statuts RH / Finances</div>
                        </div>
                        <div class="flex h-10 w-10 items-center justify-center rounded-2xl bg-gradient-to-br from-emerald-600 to-slate-900 text-white shadow-md ring-1 ring-white/20">
                            <i class="fas fa-clipboard-list text-lg drop-shadow"></i>
                        </div>
                    </div>
                </a>

                <a href="mes_fournisseurs_employe.php" class="group relative overflow-hidden rounded-2xl border border-slate-200 bg-white/80 px-4 py-3 shadow-[0_6px_22px_rgb(0,0,0,0.05)] backdrop-blur-md transition-all duration-300 hover:-translate-y-0.5 hover:shadow-[0_12px_34px_rgb(0,0,0,0.08)]">
                    <div class="absolute inset-0 bg-gradient-to-br from-white to-slate-50 transition group-hover:from-cyan-50 group-hover:to-sky-50"></div>
                    <div class="relative flex items-center justify-between gap-3">
                        <div>
                            <div class="text-sm font-semibold text-slate-950">Mes fournisseurs</div>
                            <div class="mt-0.5 text-xs text-slate-600">Créer un fournisseur puis attendre la validation comptable</div>
                        </div>
                        <div class="flex h-10 w-10 items-center justify-center rounded-2xl bg-gradient-to-br from-cyan-600 to-slate-900 text-white shadow-md ring-1 ring-white/20">
                            <i class="fas fa-industry text-lg drop-shadow"></i>
                        </div>
                    </div>
                </a>

                <a href="mes_explications_employe.php" class="group relative block overflow-hidden rounded-2xl border border-slate-200 bg-white/80 px-4 py-3 shadow-[0_6px_22px_rgb(0,0,0,0.05)] backdrop-blur-md transition-all duration-300 hover:-translate-y-0.5 hover:shadow-[0_12px_34px_rgb(0,0,0,0.08)]">
                    <div class="absolute inset-0 bg-gradient-to-br from-white to-slate-50 transition group-hover:from-rose-50 group-hover:to-amber-50"></div>
                    <div class="relative flex items-center justify-between gap-3">
                        <div>
                            <div class="text-sm font-semibold text-slate-950">Répondre aux demandes d’explications</div>
                            <div class="mt-0.5 text-xs text-slate-600">Suivi RH, réponse et historique</div>
                        </div>
                        <div class="flex h-10 w-10 items-center justify-center rounded-2xl bg-gradient-to-br from-rose-600 to-slate-900 text-white shadow-md ring-1 ring-white/20">
                            <i class="fas fa-comments text-lg drop-shadow"></i>
                        </div>
                    </div>
                </a>

            </div>
        </section>

        <section class="mt-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.28em] text-cyan-600">Fournisseurs</p>
                    <h2 class="mt-2 text-xl font-semibold text-slate-950">Mes fournisseurs</h2>
                    <p class="mt-1 text-sm text-slate-600">Vous ajoutez le fournisseur ici, puis la comptabilité le valide avant son utilisation en décaissement.</p>
                </div>
                <a class="text-sm font-semibold text-cyan-700 hover:text-cyan-800" href="mes_fournisseurs_employe.php">Ouvrir la gestion fournisseur</a>
            </div>

            <div class="mt-5 grid gap-4 md:grid-cols-4">
                <a href="mes_fournisseurs_employe.php" class="employee-semantic-card employee-tone-cyan group rounded-3xl p-5">
                    <div class="absolute inset-0 bg-gradient-to-br from-white to-slate-50 transition group-hover:from-cyan-50 group-hover:to-sky-50"></div>
                    <div class="relative">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Total</div>
                                <div class="mt-2 flex items-end gap-2">
                                    <div class="text-3xl font-semibold text-slate-950"><?= (int) $mySuppliersCount ?></div>
                                    <div class="pb-1 text-sm text-slate-600">fournisseurs</div>
                                </div>
                            </div>
                            <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br from-cyan-600 to-slate-900 text-white shadow-md transition-transform duration-300 group-hover:scale-110">
                                <i class="fas fa-industry"></i>
                            </div>
                        </div>
                        <div class="mt-3 inline-flex items-center gap-2 text-sm font-semibold text-cyan-700">
                            <span>Gérer</span>
                            <i class="fas fa-arrow-right text-xs"></i>
                        </div>
                    </div>
                </a>

                <a href="mes_fournisseurs_employe.php" class="employee-semantic-card employee-tone-amber group rounded-3xl p-5">
                    <div class="absolute inset-0 bg-gradient-to-br from-white to-slate-50 transition group-hover:from-amber-50 group-hover:to-orange-50"></div>
                    <div class="relative">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">En attente</div>
                                <div class="mt-2 flex items-end gap-2">
                                    <div class="text-3xl font-semibold text-slate-950"><?= (int) $myPendingSuppliers ?></div>
                                    <div class="pb-1 text-sm text-slate-600">à valider</div>
                                </div>
                            </div>
                            <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br from-amber-500 to-slate-900 text-white shadow-md transition-transform duration-300 group-hover:scale-110">
                                <i class="fas fa-hourglass-half"></i>
                            </div>
                        </div>
                        <div class="mt-3 text-sm font-semibold text-amber-700">Validation par la comptabilité</div>
                    </div>
                </a>

                <a href="mes_fournisseurs_employe.php" class="employee-semantic-card employee-tone-emerald group rounded-3xl p-5">
                    <div class="absolute inset-0 bg-gradient-to-br from-white to-slate-50 transition group-hover:from-emerald-50 group-hover:to-cyan-50"></div>
                    <div class="relative">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Validés</div>
                                <div class="mt-2 flex items-end gap-2">
                                    <div class="text-3xl font-semibold text-slate-950"><?= (int) $myApprovedSuppliers ?></div>
                                    <div class="pb-1 text-sm text-slate-600">prêts à l'emploi</div>
                                </div>
                            </div>
                            <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br from-emerald-600 to-slate-900 text-white shadow-md transition-transform duration-300 group-hover:scale-110">
                                <i class="fas fa-circle-check"></i>
                            </div>
                        </div>
                        <div class="mt-3 text-sm font-semibold text-emerald-700">Sélectionnables dans les décaissements</div>
                    </div>
                </a>

                <a href="mes_fournisseurs_employe.php" class="employee-semantic-card employee-tone-rose group rounded-3xl p-5">
                    <div class="absolute inset-0 bg-gradient-to-br from-white to-slate-50 transition group-hover:from-rose-50 group-hover:to-orange-50"></div>
                    <div class="relative">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Rejetés</div>
                                <div class="mt-2 flex items-end gap-2">
                                    <div class="text-3xl font-semibold text-slate-950"><?= (int) $myRejectedSuppliers ?></div>
                                    <div class="pb-1 text-sm text-slate-600">à corriger</div>
                                </div>
                            </div>
                            <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br from-rose-600 to-slate-900 text-white shadow-md transition-transform duration-300 group-hover:scale-110">
                                <i class="fas fa-triangle-exclamation"></i>
                            </div>
                        </div>
                        <div class="mt-3 text-sm font-semibold text-rose-700">Corriger puis renvoyer en validation</div>
                    </div>
                </a>
            </div>
        </section>

        <section class="mt-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.28em] text-cyan-600">Suivi</p>
                    <h2 class="mt-2 text-xl font-semibold text-slate-950">5 derniers fournisseurs saisis</h2>
                    <p class="mt-1 text-sm text-slate-600">Statut comptable de vos dernières demandes de création ou de mise à jour fournisseur.</p>
                </div>
                <a class="text-sm font-semibold text-cyan-700 hover:text-cyan-800" href="mes_fournisseurs_employe.php">Voir toute la liste</a>
            </div>

            <div class="mt-5 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 text-left text-slate-500">
                            <th class="py-3 pr-4">Fournisseur</th>
                            <th class="py-3 pr-4">Type</th>
                            <th class="py-3 pr-4">Catégories</th>
                            <th class="py-3 pr-4">Contact</th>
                            <th class="py-3 pr-4">Téléphone</th>
                            <th class="py-3 pr-4">Statut comptable</th>
                            <th class="py-3">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$myLatestSuppliers): ?>
                            <tr>
                                <td colspan="7" class="py-6 text-center text-slate-500">Aucun fournisseur saisi pour le moment.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($myLatestSuppliers as $supplierRow): ?>
                                <?php
                                $supplierStatus = (string) ($supplierRow['validation_status'] ?? \FournisseurRepository::STATUS_PENDING);
                                $supplierStatusMeta = $supplierStatus === \FournisseurRepository::STATUS_APPROVED
                                    ? ['label' => 'Validé', 'class' => 'border-emerald-200 bg-emerald-50 text-emerald-700']
                                    : ($supplierStatus === \FournisseurRepository::STATUS_REJECTED
                                        ? ['label' => 'Rejeté', 'class' => 'border-rose-200 bg-rose-50 text-rose-700']
                                        : ['label' => 'En attente', 'class' => 'border-amber-200 bg-amber-50 text-amber-700']);
                                ?>
                                <tr class="border-b border-slate-100 text-slate-800 last:border-b-0">
                                    <td class="py-3 pr-4">
                                        <div class="flex items-start gap-3">
                                            <?php if (!empty($supplierRow['visual_path'])): ?>
                                                <img src="../<?= htmlspecialchars((string) $supplierRow['visual_path']) ?>" alt="Visuel fournisseur" class="h-12 w-12 rounded-2xl border border-slate-200 object-cover">
                                            <?php else: ?>
                                                <div class="flex h-12 w-12 items-center justify-center rounded-2xl border border-dashed border-slate-300 bg-slate-50 text-[10px] font-semibold text-slate-400">AUCUN</div>
                                            <?php endif; ?>
                                            <div>
                                                <div class="font-semibold text-slate-950"><?= htmlspecialchars((string) ($supplierRow['supplier_display_name'] ?? $supplierRow['nom_commercial'] ?? $supplierRow['raison_sociale'] ?? '')) ?></div>
                                                <?php if (!empty($supplierRow['raison_sociale'])): ?>
                                                    <div class="text-xs text-slate-500"><?= htmlspecialchars((string) ($supplierRow['raison_sociale'] ?? '')) ?></div>
                                                <?php endif; ?>
                                                <?php if (!empty($supplierRow['code_fournisseur'])): ?>
                                                    <div class="text-xs text-slate-500"><?= htmlspecialchars((string) $supplierRow['code_fournisseur']) ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="py-3 pr-4 text-slate-600"><?= htmlspecialchars(($supplierRow['supplier_type'] ?? 'morale') === \FournisseurRepository::TYPE_INDIVIDUAL ? 'Personne physique' : 'Personne morale') ?></td>
                                    <td class="py-3 pr-4 text-slate-600">
                                        <?php if (!empty($supplierRow['category_labels'])): ?>
                                            <div class="flex flex-wrap gap-2">
                                                <?php foreach (($supplierRow['category_labels'] ?? []) as $label): ?>
                                                    <span class="inline-flex rounded-full border border-cyan-200 bg-cyan-50 px-2 py-1 text-xs font-semibold text-cyan-800"><?= htmlspecialchars((string) $label) ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-slate-400">Aucune</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 pr-4 text-slate-600"><?= htmlspecialchars((string) ($supplierRow['contact_nom'] ?? '—')) ?></td>
                                    <td class="py-3 pr-4 text-slate-600"><?= htmlspecialchars((string) ($supplierRow['telephone'] ?? '—')) ?></td>
                                    <td class="py-3 pr-4">
                                        <span class="inline-flex rounded-full border px-3 py-1 text-xs font-semibold <?= $supplierStatusMeta['class'] ?>"><?= htmlspecialchars($supplierStatusMeta['label']) ?></span>
                                    </td>
                                    <td class="py-3">
                                        <div class="flex flex-wrap gap-3">
                                            <a class="text-sm font-semibold text-cyan-700 hover:text-cyan-800" href="edit_fournisseur_employe.php?id=<?= (int) ($supplierRow['id_fournisseur'] ?? 0) ?>">Ouvrir</a>
                                            <?php if (($supplierRow['validation_status'] ?? '') === \FournisseurRepository::STATUS_APPROVED): ?>
                                                <a class="text-sm font-semibold text-emerald-700 hover:text-emerald-800" href="../decaissement/formulaire_demande_decaissement.php?fournisseur_id=<?= (int) ($supplierRow['id_fournisseur'] ?? 0) ?>">Utiliser</a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="mt-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.28em] text-indigo-600">FEB</p>
                    <h2 class="mt-2 text-xl font-semibold text-slate-950">Fiches d'expression de besoin</h2>
                    <p class="mt-1 text-sm text-slate-600">Accès rapide à vos FEB et, si autorisé, aux FEB à valider.</p>
                </div>
                <a class="text-sm font-semibold text-indigo-700 hover:text-indigo-800" href="mes_demandes_employe.php?type=feb">Voir toutes mes FEB</a>
            </div>



            <div class="mt-5 grid gap-4 md:grid-cols-4">
                <a href="mes_demandes_employe.php?type=feb&status=submitted" class="employee-semantic-card employee-tone-amber group rounded-3xl p-5">
                    <div class="absolute inset-0 bg-gradient-to-br from-white to-slate-50 transition group-hover:from-indigo-50 group-hover:to-purple-50"></div>
                    <div class="relative">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">En attente</div>
                                <div class="mt-2 flex items-end gap-2">
                                    <div class="text-3xl font-semibold text-slate-950"><?= (int) $febPending ?></div>
                                    <div class="pb-1 text-sm text-slate-600">FEB</div>
                                </div>
                            </div>
                            <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br from-amber-500 to-slate-900 text-white shadow-md transition-transform duration-300 group-hover:scale-110">
                                <i class="fas fa-hourglass-half"></i>
                            </div>
                        </div>
                        <div class="mt-3 inline-flex items-center gap-2 text-sm font-semibold text-indigo-700">
                            <span>Consulter</span>
                            <i class="fas fa-arrow-right text-xs"></i>
                        </div>
                    </div>
                </a>

                <a href="mes_demandes_employe.php?type=feb&status=details_requested" class="employee-semantic-card employee-tone-sky group rounded-3xl p-5">
                    <div class="absolute inset-0 bg-gradient-to-br from-white to-slate-50 transition group-hover:from-sky-50 group-hover:to-indigo-50"></div>
                    <div class="relative">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">À compléter</div>
                                <div class="mt-2 flex items-end gap-2">
                                    <div class="text-3xl font-semibold text-slate-950"><?= (int) $febToComplete ?></div>
                                    <div class="pb-1 text-sm text-slate-600">FEB</div>
                                </div>
                            </div>
                            <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br from-sky-600 to-slate-900 text-white shadow-md transition-transform duration-300 group-hover:scale-110">
                                <i class="fas fa-file-circle-plus"></i>
                            </div>
                        </div>
                        <div class="mt-3 inline-flex items-center gap-2 text-sm font-semibold text-indigo-700">
                            <span>Compléter</span>
                            <i class="fas fa-arrow-right text-xs"></i>
                        </div>
                    </div>
                </a>

                <a href="mes_demandes_employe.php?type=feb&status=approved" class="employee-semantic-card employee-tone-emerald group rounded-3xl p-5">
                    <div class="absolute inset-0 bg-gradient-to-br from-white to-slate-50 transition group-hover:from-emerald-50 group-hover:to-indigo-50"></div>
                    <div class="relative">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Validées</div>
                                <div class="mt-2 flex items-end gap-2">
                                    <div class="text-3xl font-semibold text-slate-950"><?= (int) $febValidated ?></div>
                                    <div class="pb-1 text-sm text-slate-600">FEB</div>
                                </div>
                            </div>
                            <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br from-emerald-600 to-slate-900 text-white shadow-md transition-transform duration-300 group-hover:scale-110">
                                <i class="fas fa-circle-check"></i>
                            </div>
                        </div>
                        <div class="mt-3 inline-flex items-center gap-2 text-sm font-semibold text-indigo-700">
                            <span>Consulter</span>
                            <i class="fas fa-arrow-right text-xs"></i>
                        </div>
                    </div>
                </a>

                <a href="mes_demandes_employe.php?type=feb&status=decaissed" class="employee-semantic-card employee-tone-cyan group rounded-3xl p-5">
                    <div class="absolute inset-0 bg-gradient-to-br from-white to-slate-50 transition group-hover:from-cyan-50 group-hover:to-indigo-50"></div>
                    <div class="relative">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Décaissées</div>
                                <div class="mt-2 flex items-end gap-2">
                                    <div class="text-3xl font-semibold text-slate-950"><?= (int) $febDecaissed ?></div>
                                    <div class="pb-1 text-sm text-slate-600">FEB</div>
                                </div>
                            </div>
                            <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br from-cyan-600 to-slate-900 text-white shadow-md transition-transform duration-300 group-hover:scale-110">
                                <i class="fas fa-coins"></i>
                            </div>
                        </div>
                        <div class="mt-3 inline-flex items-center gap-2 text-sm font-semibold text-indigo-700">
                            <span>Consulter</span>
                            <i class="fas fa-arrow-right text-xs"></i>
                        </div>
                    </div>
                </a>

                <a href="mes_demandes_employe.php?type=feb&status=rejected" class="employee-semantic-card employee-tone-rose group rounded-3xl p-5">
                    <div class="absolute inset-0 bg-gradient-to-br from-white to-slate-50 transition group-hover:from-rose-50 group-hover:to-purple-50"></div>
                    <div class="relative">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Refusées</div>
                                <div class="mt-2 flex items-end gap-2">
                                    <div class="text-3xl font-semibold text-slate-950"><?= (int) $febRejected ?></div>
                                    <div class="pb-1 text-sm text-slate-600">FEB</div>
                                </div>
                            </div>
                            <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br from-rose-600 to-slate-900 text-white shadow-md transition-transform duration-300 group-hover:scale-110">
                                <i class="fas fa-circle-xmark"></i>
                            </div>
                        </div>
                        <div class="mt-3 inline-flex items-center gap-2 text-sm font-semibold text-indigo-700">
                            <span>Consulter</span>
                            <i class="fas fa-arrow-right text-xs"></i>
                        </div>
                    </div>
                </a>
            </div>

            <?php if (false && $hasFinanceAccess): ?>
                <div class="mt-5">
                    <details class="group relative overflow-hidden rounded-3xl border border-indigo-200 bg-white/80 shadow-[0_8px_30px_rgb(0,0,0,0.05)] backdrop-blur-md transition-all duration-300 hover:shadow-[0_15px_40px_rgb(0,0,0,0.08)]">
                        <div class="absolute inset-0 bg-gradient-to-br from-indigo-50 to-white transition group-hover:from-indigo-100 group-hover:to-purple-50"></div>

                        <summary class="relative cursor-pointer select-none list-none p-5 [&::-webkit-details-marker]:hidden">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <div class="text-xs font-semibold uppercase tracking-[0.2em] text-indigo-700">FEB à valider (mes services)</div>
                                    <div class="mt-2 flex items-end gap-2">
                                        <div class="text-3xl font-semibold text-slate-950"><?= (int) $febToValidate ?></div>
                                        <div class="pb-1 text-sm text-slate-700">à traiter</div>
                                    </div>
                                    <div class="mt-2 text-sm font-semibold text-indigo-800">
                                        <span class="group-open:hidden">Cliquer pour afficher la liste</span>
                                        <span class="hidden group-open:inline">Cliquer pour replier</span>
                                    </div>
                                </div>
                                <div class="flex items-center gap-3">
                                    <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br from-indigo-600 to-slate-900 text-white shadow-md ring-1 ring-white/20 transition-transform duration-300 group-hover:scale-110">
                                        <i class="fas fa-list text-2xl drop-shadow"></i>
                                    </div>
                                    <i class="fas fa-chevron-down text-indigo-400 transition-transform duration-300 group-open:rotate-180"></i>
                                </div>
                            </div>
                        </summary>

                        <div class="relative border-t border-indigo-100 px-5 pb-5 pt-4">
                            <div class="flex flex-wrap items-center gap-2">
                                <a href="../validation_demandes_finances.php?tab=mine_pending" class="inline-flex items-center gap-2 rounded-2xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                                    <i class="fas fa-arrow-up-right-from-square"></i>
                                    Ouvrir la page de validation
                                </a>
                                <button type="button" class="inline-flex items-center gap-2 rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-900 hover:bg-slate-50" onclick="this.closest('details')?.removeAttribute('open')">
                                    <i class="fas fa-chevron-up text-slate-500"></i>
                                    Replier
                                </button>
                                <?php if (!empty($febToValidateRows) && is_array($febToValidateRows)): ?>
                                    <?php $firstId = (int) ($febToValidateRows[0]['id'] ?? 0); ?>
                                    <?php if ($firstId > 0): ?>
                                        <a href="../validation_demandes_finances.php?id=<?= $firstId ?>" class="inline-flex items-center gap-2 rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-900 hover:bg-slate-50">
                                            <i class="fas fa-bolt text-amber-600"></i>
                                            Traiter la première
                                        </a>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>

                            <div class="mt-4 overflow-x-auto">
                                <table class="min-w-full text-left text-sm">
                                    <thead>
                                        <tr class="border-b border-indigo-100 text-slate-500">
                                            <th class="px-3 py-3 font-medium">Chantier</th>
                                            <th class="px-3 py-3 font-medium">Demandeur</th>
                                            <th class="px-3 py-3 font-medium">Détails</th>
                                            <th class="px-3 py-3 font-medium">Montant</th>
                                            <th class="px-3 py-3 font-medium">Créée le</th>
                                            <th class="px-3 py-3 font-medium"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($febToValidateRows)): ?>
                                            <tr>
                                                <td class="px-3 py-4 text-slate-600" colspan="6">Aucune FEB à traiter pour vos services.</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($febToValidateRows as $row): ?>
                                                <?php
                                                $rowId = (int) ($row['id'] ?? 0);
                                                $payload = (array) ($row['payload'] ?? []);
                                                $febDisplay = (array) ($row['feb_display'] ?? \EmployeeLegalRequestManager::extractFebDisplay($row));
                                                $serviceLabel = (string) ($payload['departement_label'] ?? '');
                                                if ($serviceLabel === '') {
                                                    $metaServiceId = (int) ($row['meta_service_id'] ?? 0);
                                                    $serviceLabel = $metaServiceId > 0 ? ('Service #' . $metaServiceId) : '—';
                                                }
                                                $montantLabel = (string) ($febDisplay['montant_label'] ?? '—');
                                                $createdDate = (string) (($febDisplay['created_date'] ?? '') ?: substr((string) ($row['submitted_at'] ?? ''), 0, 10));
                                                $desc = (string) ($febDisplay['description'] ?? '');
                                                $demandeurLabel = trim((string) ($febDisplay['demandeur'] ?? ($row['requester_name'] ?? '')));
                                                if ($demandeurLabel === '') {
                                                    $demandeurLabel = '—';
                                                }
                                                ?>
                                                <tr class="border-b border-indigo-50 last:border-b-0">
                                                    <td class="px-3 py-4 text-slate-700">
                                                        <div class="font-semibold text-slate-900"><?= htmlspecialchars((string) ($febDisplay['chantier_label'] ?? 'CHANTIER N° —')) ?></div>
                                                    </td>
                                                    <td class="px-3 py-4 text-slate-700">
                                                        <div class="font-semibold text-slate-900">Demandeur : <?= htmlspecialchars($demandeurLabel) ?></div>
                                                    </td>
                                                    <td class="px-3 py-4 text-slate-700">
                                                        <div class="font-semibold text-slate-900"><?= htmlspecialchars($serviceLabel) ?></div>
                                                        <div class="mt-1 text-xs text-slate-600"><span class="font-semibold">Description :</span> <?= htmlspecialchars($desc !== '' ? $desc : '—') ?></div>
                                                    </td>
                                                    <td class="px-3 py-4 text-slate-700"><span class="font-semibold">Montant :</span> <?= htmlspecialchars($montantLabel) ?></td>
                                                    <td class="px-3 py-4 text-slate-600"><span class="font-semibold">Créée le :</span> <?= htmlspecialchars($createdDate) ?></td>
                                                    <td class="px-3 py-4 text-right">
                                                        <?php if ($rowId > 0): ?>
                                                            <a class="text-sm font-semibold text-indigo-700 hover:text-indigo-800" href="../validation_demandes_finances.php?id=<?= $rowId ?>">Traiter</a>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </details>
                </div>
            <?php endif; ?>
        </section>

        <div class="mt-6">
            <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.28em] text-indigo-600">Mes dernières demandes</p>
                        <h2 class="mt-2 text-xl font-semibold text-slate-950">Historique</h2>
                    </div>
                    <a href="actes_legaux_employe.php" class="text-sm font-semibold text-indigo-700 hover:text-indigo-800">Nouvelle demande</a>
                </div>

                <div class="mt-5 overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead>
                            <tr class="border-b border-slate-100 text-slate-500">
                                <th class="px-3 py-3 font-medium">Type</th>
                                <th class="px-3 py-3 font-medium">Titre</th>
                                <th class="px-3 py-3 font-medium">Statut</th>
                                <th class="px-3 py-3 font-medium">Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$myRequests): ?>
                                <tr>
                                    <td class="px-3 py-4 text-slate-600" colspan="4">Aucune demande pour le moment.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach (array_slice($myRequests, 0, 6) as $row): ?>
                                    <?php $badge = statusBadge((string) ($row['status'] ?? '')); ?>
                                    <?php $febDisplay = (($row['request_type'] ?? '') === 'feb') ? (array) ($row['feb_display'] ?? \EmployeeLegalRequestManager::extractFebDisplay($row)) : null; ?>
                                    <tr class="border-b border-slate-100 last:border-b-0">
                                        <td class="px-3 py-4 font-medium text-slate-800"><?= htmlspecialchars((string) ($row['type_label'] ?? $row['request_type'])) ?></td>
                                        <td class="px-3 py-4 text-slate-700">
                                            <?php if (($row['request_type'] ?? '') === 'feb' && is_array($febDisplay)): ?>
                                                <div class="font-semibold text-slate-900"><?= htmlspecialchars((string) ($febDisplay['chantier_label'] ?? 'CHANTIER N° —')) ?></div>
                                                <div class="mt-1 text-xs text-slate-600"><span class="font-semibold">Description :</span> <?= htmlspecialchars((string) ($febDisplay['description'] ?? ($row['title'] ?? ''))) ?></div>
                                                <div class="mt-1 text-xs text-slate-600"><span class="font-semibold">Montant :</span> <?= htmlspecialchars((string) ($febDisplay['montant_label'] ?? '—')) ?></div>
                                                <div class="mt-1 text-xs text-slate-500"><span class="font-semibold">Créée le :</span> <?= htmlspecialchars((string) (($febDisplay['created_date'] ?? '') ?: substr((string) ($row['submitted_at'] ?? ''), 0, 10))) ?></div>
                                            <?php else: ?>
                                                <?= htmlspecialchars((string) ($row['title'] ?? '')) ?>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-3 py-4">
                                            <span class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold <?= $badge['class'] ?>">
                                                <?= htmlspecialchars($badge['label']) ?>
                                            </span>
                                        </td>
                                        <td class="px-3 py-4 text-slate-600"><?= htmlspecialchars(employeeFormatDateFr((string) ($row['submitted_at'] ?? ''))) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>
</div>

<script>
    document.querySelectorAll('.employee-validate-task-btn').forEach(function(button) {
        button.addEventListener('click', async function() {
            const eventId = parseInt(button.dataset.eventId || '0', 10);
            const errorBox = document.getElementById('employee-task-validation-error');
            if (!eventId) {
                return;
            }
            button.disabled = true;
            button.textContent = 'Validation...';
            try {
                const response = await fetch('../api/validate_employee_schedule_event_completion.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        event_id: eventId
                    })
                });
                const data = await response.json();
                if (!data.ok) {
                    throw new Error(data.error || 'Validation impossible');
                }
                const row = button.closest('[data-validation-event-id]');
                if (row) {
                    row.remove();
                }
                if (errorBox) {
                    errorBox.classList.add('hidden');
                }
            } catch (error) {
                if (errorBox) {
                    errorBox.textContent = error.message || 'Validation impossible';
                    errorBox.classList.remove('hidden');
                }
                button.disabled = false;
                button.textContent = 'Valider termine';
            }
        });
    });
</script>

<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>
