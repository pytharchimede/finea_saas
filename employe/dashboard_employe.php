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

<main class="employee-home">
<header class="employee-welcome"><div><small>MON ESPACE PERSONNEL</small><h1>Bonjour, <?=htmlspecialchars(employeeCurrentUserName())?></h1><?php if($personnel):?><p><?=htmlspecialchars((string)$personnel['fonction_label'])?> · <?=htmlspecialchars((string)$personnel['service_label'])?> · <?=htmlspecialchars((string)$personnel['matricule'])?></p><?php else:?><p>Votre compte n’est pas encore lié à un profil RH.</p><?php endif;?></div><a class="employee-primary" href="actes_legaux_employe.php"><i class="fas fa-plus"></i> Nouvelle demande</a></header>
<section class="employee-panel"><h2>À traiter</h2><div class="employee-rows"><?php
$actions=[['Absences à justifier',$absencesToJustifyCount,'pointage_employe.php'],['FEB à compléter',$febToComplete,'mes_demandes_employe.php?type=feb&status=details_requested']];
if($hasFinanceAccess)$actions[]=['FEB à valider',$febToValidate,'../validation_demandes_finances.php?tab=mine_pending'];
foreach($actions as [$label,$count,$url]):if(!$count)continue;?><a href="<?=htmlspecialchars($url)?>"><span><?=htmlspecialchars($label)?></span><strong><?=(int)$count?> →</strong></a><?php endforeach;?>
<?php foreach($pendingDisbursementRegularizations as $regularization):?><a href="../decaissement/regularisation_decaissement.php?num_fiche=<?=rawurlencode((string)$regularization['num_fiche'])?>"><span>Décaissement à régulariser</span><strong><?=htmlspecialchars((string)$regularization['num_fiche'])?> →</strong></a><?php endforeach;?>
<?php if(!$absencesToJustifyCount&&!$febToComplete&&!$febToValidate&&!$pendingDisbursementRegularizations):?><p>Aucune action urgente signalée dans ces rubriques.</p><?php endif;?></div></section>
<div class="employee-home-grid"><section class="employee-panel"><h2>Mes démarches</h2><div class="employee-rows"><?php foreach([['Pointage','pointage_employe.php'],['Demandes RH et FEB','actes_legaux_employe.php'],['Fiche de décaissement','../decaissement/formulaire_demande_decaissement.php'],['Décaissement urgent','nouvelle_demande_decaissement_urgent.php'],['Demande logistique','nouvelle_demande_logistique.php'],['Mes explications','mes_explications_employe.php']] as [$label,$url]):?><a href="<?=htmlspecialchars($url)?>"><?=htmlspecialchars($label)?><span>→</span></a><?php endforeach;?></div></section>
<section class="employee-panel"><h2>Mes suivis</h2><div class="employee-rows"><?php foreach([['FEB en attente',$febPending,'mes_demandes_employe.php?type=feb&status=submitted'],['FEB validées',$febValidated,'mes_demandes_employe.php?type=feb&status=approved'],['FEB décaissées',$febDecaissed,'mes_demandes_employe.php?type=feb&status=decaissed'],['FEB refusées',$febRejected,'mes_demandes_employe.php?type=feb&status=rejected'],['Fournisseurs saisis',$mySuppliersCount,'mes_fournisseurs_employe.php'],['Absences en validation',$absencesPendingValidationCount,'pointage_employe.php']] as [$label,$count,$url]):?><a href="<?=htmlspecialchars($url)?>"><?=htmlspecialchars($label)?><strong><?=(int)$count?></strong></a><?php endforeach;?></div></section></div>
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
</main>
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
