<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/EmployeeLegalRequestManager.php';
require_once __DIR__ . '/../model/UrgentDisbursement.php';
require_once __DIR__ . '/../services/UrgentDisbursementService.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/../model/AuditTrail.php';

$pdo = \Database::getConnection();
$permissionRefreshUserId = (int) ($_SESSION['user_id'] ?? 0);
if ($permissionRefreshUserId > 0) AccessManager::refreshSessionPermissions();
$manager = new \EmployeeLegalRequestManager($pdo);
$urgentDisbursementService = new \UrgentDisbursementService($pdo);
$canAdjustBeneficiarySupplier = AccessManager::hasPermission('ajuster_beneficiaire_fournisseur_fiche');

$userId = (int) ($_SESSION['user_id'] ?? 0);
$userEmail = (string) ($_SESSION['mail_pro'] ?? '');
$personnelProfile = employeeFindPersonnelProfile($pdo, $userEmail);
$actorMatricule = strtoupper(trim((string) ($personnelProfile['matricule'] ?? '')));

$filterTypeRaw = strtolower(trim((string) ($_GET['type'] ?? '')));
$filterStatusRaw = strtolower(trim((string) ($_GET['status'] ?? '')));
$filterQuery = trim((string) ($_GET['q'] ?? ''));
$filterRequester = trim((string) ($_GET['demandeur'] ?? ''));
$filterDateFromRaw = trim((string) ($_GET['date_from'] ?? ''));
$filterDateToRaw = trim((string) ($_GET['date_to'] ?? ''));
$includeLegacyFeb = !empty($_GET['archives']) || !empty($_GET['legacy']) || !empty($_GET['include_archives']);
$viewModeRaw = strtolower(trim((string) ($_GET['view'] ?? '')));
$allowedViewModes = ['liste', 'cards', 'calendrier'];
$viewMode = in_array($viewModeRaw, $allowedViewModes, true) ? $viewModeRaw : 'liste';
$calendarMonthRaw = trim((string) ($_GET['month'] ?? ''));
$calendarMonth = preg_match('/^\d{4}-\d{2}$/', $calendarMonthRaw) ? $calendarMonthRaw : date('Y-m');

$requestTypes = $manager->getRequestTypes();
$requestTypes['decaissement_urgent'] = ['label' => 'Decaissement urgent', 'team' => 'finance'];

$filterType = $manager->normalizeType($filterTypeRaw);
if (in_array($filterTypeRaw, ['urgent', 'urgence', 'decaissement_urgent'], true)) {
    $filterType = 'decaissement_urgent';
}
if ($filterType === '' && $filterTypeRaw !== '') {
    // Tolérance UX: si l'utilisateur saisit un libellé (ex: "FEB"), on tente de retrouver le type.
    $needle = employeeLower($filterTypeRaw);
    foreach ($requestTypes as $key => $def) {
        $label = employeeLower((string) ($def['label'] ?? $key));
        if ($needle !== '' && (strpos($label, $needle) !== false || strpos(employeeLower((string) $key), $needle) !== false)) {
            $filterType = (string) $key;
            break;
        }
    }
}

$allowedStatuses = ['submitted', 'details_requested', 'approved', 'rejected', 'cancelled', 'decaissed', 'regularization_pending', 'regularized'];
$filterStatus = in_array($filterStatusRaw, $allowedStatuses, true) ? $filterStatusRaw : '';


$typeSearchDisplay = '';
if ($filterType !== '') {
    $label = (string) ($requestTypes[$filterType]['label'] ?? $filterType);
    $typeSearchDisplay = $filterType . ' — ' . $label;
}

$statusLabels = [
    'submitted' => 'En attente',
    'details_requested' => 'Compléments demandés',
    'approved' => 'Approuvée',
    'rejected' => 'Refusée',
    'cancelled' => 'Annulée',
    'decaissed' => 'Décaissée (FEB)',
    'regularization_pending' => 'Regularisation en cours',
    'regularized' => 'Regularisee',
];

$statusSearchDisplay = '';
if ($filterStatus !== '') {
    $statusSearchDisplay = $filterStatus . ' — ' . ($statusLabels[$filterStatus] ?? $filterStatus);
}

$filterDateFrom = '';
$filterDateTo = '';
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDateFromRaw)) {
    $filterDateFrom = $filterDateFromRaw;
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDateToRaw)) {
    $filterDateTo = $filterDateToRaw;
}
if ($calendarMonthRaw === '' && $filterDateFrom !== '') {
    $calendarMonth = substr($filterDateFrom, 0, 7);
}

// UX: le détail s'ouvre désormais sur une page dédiée.
// Compat: si un ancien lien appelle encore mes_demandes_employe.php?id=..., on redirige.
$legacyViewId = isset($_GET['id']) && is_numeric($_GET['id']) ? (int) $_GET['id'] : 0;
if ($legacyViewId > 0) {
    $qs = http_build_query(array_filter([
        'id' => $legacyViewId,
        'type' => $filterType ?: null,
        'status' => $filterStatus ?: null,
        'q' => $filterQuery ?: null,
        'demandeur' => $filterRequester ?: null,
        'date_from' => $filterDateFrom ?: null,
        'date_to' => $filterDateTo ?: null,
        'archives' => $includeLegacyFeb ? 1 : null,
        'view' => $viewMode !== 'liste' ? $viewMode : null,
        'month' => $viewMode === 'calendrier' ? $calendarMonth : null,
    ], static fn($v) => $v !== null && $v !== ''));
    header('Location: details_demande_employe.php?' . $qs);
    exit;
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
        'decaissed' => ['label' => 'Decaissee - a regulariser', 'class' => 'bg-rose-100 text-rose-800 border-rose-200'],
        'regularization_pending' => ['label' => 'Regularisation en cours', 'class' => 'bg-sky-100 text-sky-800 border-sky-200'],
        'regularized' => ['label' => 'Regularisee', 'class' => 'bg-emerald-100 text-emerald-800 border-emerald-200'],
    ];

    return $map[$status] ?? ['label' => $status ?: '—', 'class' => 'bg-slate-100 text-slate-700 border-slate-200'];
}

function employeeLower(string $value): string
{
    if (function_exists('mb_strtolower')) {
        return (string) mb_strtolower($value, 'UTF-8');
    }
    return strtolower($value);
}

function format_fcfa(float $value): string
{
    if ($value <= 0) {
        return '—';
    }
    return number_format($value, 0, ',', ' ') . ' FCFA';
}

function requestAmountForRow(array $row): float
{
    $type = (string) ($row['request_type'] ?? '');
    $payload = (array) ($row['payload'] ?? []);

    if ($type === 'decaissement_urgent') {
        return (float) ($payload['amount'] ?? 0);
    }
    if ($type === 'feb') {
        return (float) ($payload['montant'] ?? 0);
    }
    if ($type === 'reclamation_salariale') {
        return (float) ($payload['montant_manquant'] ?? 0);
    }

    return 0.0;
}

function requestBadgeForRow(array $row): array
{
    if (($row['request_type'] ?? '') === 'decaissement_urgent') {
        return statusBadge((string)($row['status'] ?? ''));
    }

    if (($row['request_type'] ?? '') === 'reclamation_salariale') {
        $meta = \EmployeeLegalRequestManager::getRequestStatusMeta($row);
        return [
            'label' => (string) ($meta['label'] ?? 'En attente RH'),
            'class' => (string) ($meta['class'] ?? 'bg-amber-100 text-amber-800 border-amber-200'),
        ];
    }

    $badge = statusBadge((string) ($row['status'] ?? ''));

    if (($row['request_type'] ?? '') === 'feb' && strtolower((string) ($row['status'] ?? '')) === 'approved') {
        $payload = (array) ($row['payload'] ?? []);
        $legacy = (array) ($payload['legacy'] ?? []);
        $tag = strtolower(trim((string) ($legacy['status_tag'] ?? '')));
        if ($tag === 'decaissed') {
            return ['label' => 'Décaissée', 'class' => 'bg-emerald-100 text-emerald-800 border-emerald-200'];
        }
    }

    return $badge;
}

function decaissementLinkedFicheStatus(array $row, string $todayYmd): array
{
    $approuve = (int) ($row['approuve'] ?? 0);
    $etat = (int) ($row['etat_fiche'] ?? 0);
    $decaisse = (int) ($row['decaisse'] ?? 0);
    $conforme = (int) ($row['conforme'] ?? 0);
    $otp = (int) ($row['otp_valide'] ?? 0);

    $dateMin = trim((string) ($row['date_decaissement_minimum'] ?? ''));
    $dateReport = '';
    if ($dateMin !== '' && preg_match('/^\d{4}-\d{2}-\d{2}/', $dateMin)) {
        $dateReport = substr($dateMin, 0, 10);
    }

    if ($decaisse === 1) {
        return ['key' => 'decaissee', 'label' => 'Décaissée', 'class' => 'bg-emerald-100 text-emerald-800 border-emerald-200', 'date_report' => ''];
    }

    if ($dateReport !== '' && $dateReport > $todayYmd) {
        return ['key' => 'reportee', 'label' => 'Reportée au ' . employeeFormatDateFr($dateReport), 'class' => 'bg-amber-100 text-amber-800 border-amber-200', 'date_report' => $dateReport];
    }

    if ($approuve === 2 || $etat === 2) {
        return ['key' => 'refusee', 'label' => 'Refusée', 'class' => 'bg-rose-100 text-rose-800 border-rose-200', 'date_report' => ''];
    }

    if ($etat === 1) {
        return ['key' => 'tresorerie', 'label' => 'Traitement comptable', 'class' => 'bg-violet-100 text-violet-800 border-violet-200', 'date_report' => ''];
    }

    if ($otp === 1) {
        return ['key' => 'otp', 'label' => 'OTP validé', 'class' => 'bg-sky-100 text-sky-800 border-sky-200', 'date_report' => ''];
    }

    if ($approuve === 1) {
        return ['key' => 'validation_achat', 'label' => 'Validation achat', 'class' => 'bg-indigo-100 text-indigo-800 border-indigo-200', 'date_report' => ''];
    }

    if ($conforme === 1) {
        return ['key' => 'approbation', 'label' => 'Approbation', 'class' => 'bg-blue-100 text-blue-800 border-blue-200', 'date_report' => ''];
    }

    return ['key' => 'conformite', 'label' => 'Conformité', 'class' => 'bg-slate-100 text-slate-700 border-slate-200', 'date_report' => ''];
}

function buildLinkedFichesByFebCode(PDO $pdo, array $rows): array
{
    $codes = [];
    foreach ($rows as $row) {
        if (($row['request_type'] ?? '') !== 'feb') {
            continue;
        }
        $display = (array) ($row['feb_display'] ?? \EmployeeLegalRequestManager::extractFebDisplay($row));
        $code = trim((string) ($display['numero_bon_commande_interne'] ?? $display['code_autorisation_feb'] ?? ''));
        if ($code !== '') {
            $codes[employeeLower($code)] = $code;
        }
    }

    if (!$codes) {
        return [];
    }

    $placeholders = [];
    $params = [];
    foreach (array_values($codes) as $idx => $code) {
        $key = ':code' . $idx;
        $placeholders[] = $key;
        $params[$key] = $code;
    }

    try {
        $stmt = $pdo->prepare('
            SELECT
                id_fiche,
                num_fiche,
                beficiaire_fiche,
                montant_fiche,
                code_autorisation_feb,
                date_creat_fiche,
                approuve,
                conforme,
                decaisse,
                otp_valide,
                etat_fiche,
                date_decaissement_minimum
            FROM fiche_decaissement
            WHERE code_autorisation_feb IN (' . implode(',', $placeholders) . ')
            ORDER BY date_creat_fiche DESC, id_fiche DESC
        ');
        $stmt->execute($params);
        $fiches = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    $todayYmd = date('Y-m-d');
    $map = [];
    foreach ($fiches as $fiche) {
        $code = employeeLower(trim((string) ($fiche['code_autorisation_feb'] ?? '')));
        if ($code === '') {
            continue;
        }
        $fiche['_status'] = decaissementLinkedFicheStatus($fiche, $todayYmd);
        $map[$code][] = $fiche;
    }

    return $map;
}

function employeeRequestDateYmd(array $row): string
{
    $requestType = strtolower(trim((string) ($row['request_type'] ?? '')));
    $submittedAt = (string) ($row['submitted_at'] ?? '');
    $rowDate = preg_match('/^\d{4}-\d{2}-\d{2}/', $submittedAt) ? substr($submittedAt, 0, 10) : '';

    if ($requestType === 'feb') {
        $febDisplay = (array) ($row['feb_display'] ?? \EmployeeLegalRequestManager::extractFebDisplay($row));
        $candidate = trim((string) ($febDisplay['created_date'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $candidate)) {
            $rowDate = $candidate;
        }
    }

    return $rowDate;
}

function employeeRequestRequesterName(array $row, ?array $febDisplay = null, ?array $salaryClaimDisplay = null): string
{
    if (($row['request_type'] ?? '') === 'feb' && is_array($febDisplay)) {
        return trim((string) ($febDisplay['demandeur'] ?? ($row['requester_name'] ?? '')));
    }

    if (($row['request_type'] ?? '') === 'reclamation_salariale' && is_array($salaryClaimDisplay)) {
        return trim((string) ($salaryClaimDisplay['demandeur'] ?? ($row['requester_name'] ?? '')));
    }

    return trim((string) ($row['requester_name'] ?? ''));
}

function employeeRequestTitle(array $row, ?array $febDisplay = null, ?array $salaryClaimDisplay = null): string
{
    if (($row['request_type'] ?? '') === 'feb' && is_array($febDisplay)) {
        return trim((string) ($febDisplay['chantier_label'] ?? ($row['title'] ?? 'FEB')));
    }

    if (($row['request_type'] ?? '') === 'reclamation_salariale' && is_array($salaryClaimDisplay)) {
        return trim((string) ($salaryClaimDisplay['objet_label'] ?? ($row['title'] ?? '')));
    }

    return trim((string) ($row['title'] ?? ''));
}

function employeeRequestDescription(array $row, ?array $febDisplay = null, ?array $salaryClaimDisplay = null): string
{
    if (($row['request_type'] ?? '') === 'decaissement_urgent') {
        $payload = (array)($row['payload'] ?? []);
        return trim((string)($payload['details'] ?? ($row['title'] ?? '')));
    }

    if (($row['request_type'] ?? '') === 'feb' && is_array($febDisplay)) {
        return trim((string) ($febDisplay['description'] ?? ($row['title'] ?? '')));
    }

    if (($row['request_type'] ?? '') === 'reclamation_salariale' && is_array($salaryClaimDisplay)) {
        return trim((string) ($salaryClaimDisplay['details_differend'] ?? ($row['title'] ?? '')));
    }

    return trim((string) ($row['title'] ?? ''));
}

function employeeRequestDetailQuery(array $row, array $filters): string
{
    return http_build_query(array_filter([
        'id' => (int) ($row['id'] ?? 0),
        'type' => $filters['type'] ?? null,
        'status' => $filters['status'] ?? null,
        'q' => $filters['q'] ?? null,
        'demandeur' => $filters['demandeur'] ?? null,
        'date_from' => $filters['date_from'] ?? null,
        'date_to' => $filters['date_to'] ?? null,
        'archives' => !empty($filters['archives']) ? 1 : null,
        'view' => $filters['view'] ?? null,
        'month' => $filters['month'] ?? null,
    ], static fn($v) => $v !== null && $v !== ''));
}

function employeeViewLink(array $filters, array $overrides = []): string
{
    $params = array_merge($filters, $overrides);
    $params = array_filter($params, static fn($v) => $v !== null && $v !== '');
    return 'mes_demandes_employe.php?' . http_build_query($params);
}

function employeeShortText(string $value, int $width = 150): string
{
    $value = trim($value);
    if (function_exists('mb_strimwidth')) {
        return (string) mb_strimwidth($value, 0, $width, '...');
    }
    if (strlen($value) <= $width) {
        return $value;
    }
    return substr($value, 0, max(0, $width - 3)) . '...';
}

$flash = '';
$flashType = 'info';

if (isset($_GET['created']) && is_numeric($_GET['created'])) {
    $flash = 'Demande créée avec succès (réf. #' . (int) $_GET['created'] . ').';
    $flashType = 'success';
}
if (isset($_GET['updated']) && is_numeric($_GET['updated'])) {
    $flash = 'Demande modifiée et renvoyée pour validation (réf. #' . (int) $_GET['updated'] . ').';
    $flashType = 'success';
}
if (!empty($_GET['recurring_created'])) {
    $flash = 'FEB créée avec succès. Le besoin récurrent ' . trim((string)$_GET['recurring_created'])
        . ' est actif et la prochaine FEB sera générée automatiquement avant son échéance.';
    $flashType = 'success';
}
if (!empty($_GET['recurring_updated'])) {
    $flash = 'FEB mise à jour. La programmation du besoin récurrent ' . trim((string)$_GET['recurring_updated']) . ' a également été actualisée.';
    $flashType = 'success';
}
if (!empty($_GET['recurring_error'])) {
    $flash = 'La FEB a bien été enregistrée, mais la programmation récurrente n’a pas pu être finalisée. L’administrateur a été informé.';
    $flashType = 'error';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'cancel' && isset($_POST['request_id']) && is_numeric($_POST['request_id'])) {
        $requestId = (int) $_POST['request_id'];
        $note = trim((string) ($_POST['note'] ?? ''));

        if ($manager->cancelRequest($requestId, $userId, $note)) {
            $flash = 'Demande annulée.';
            $flashType = 'success';
            \AuditTrail::log('employee_request_cancelled', [
                'module' => 'employe',
                'action' => 'mes_demandes_cancel',
                'entity_type' => 'employee_request',
                'entity_id' => (string) $requestId,
                'entity_label' => 'request_' . $requestId,
                'user_id' => $userId,
                'details' => ['note' => $note !== '' ? $note : null],
            ], $pdo);
        } else {
            $flash = 'Impossible d\'annuler cette demande.';
            $flashType = 'error';
            \AuditTrail::log('employee_request_cancel_failed', [
                'module' => 'employe',
                'action' => 'mes_demandes_cancel',
                'entity_type' => 'employee_request',
                'entity_id' => (string) $requestId,
                'entity_label' => 'request_' . $requestId,
                'user_id' => $userId,
                'result' => 'failure',
                'severity' => 'warning',
                'details' => ['note' => $note !== '' ? $note : null],
            ], $pdo);
        }
    }
}

$rows = [];

// Base: toutes les demandes "propres" de l'utilisateur
$rows = $manager->listRequestsForUser($userId, 200, $includeLegacyFeb);

// Complète avec les FEB trouvées par matricule (utile pour FEB legacy importées)
if ($actorMatricule !== '') {
    $statusesForFeb = null;
    if ($filterType === 'feb') {
        // Si on filtre sur FEB, on peut affiner via statusesForFeb.
        if ($filterStatus === 'decaissed') {
            $statusesForFeb = ['approved'];
        } elseif (in_array($filterStatus, ['submitted', 'approved', 'rejected', 'cancelled'], true)) {
            $statusesForFeb = [$filterStatus];
        }
    }

    $febRows = $manager->listFebRequestsForUserOrMatricule($userId, $actorMatricule, $statusesForFeb, 300, $includeLegacyFeb);
    if ($febRows) {
        $byId = [];
        foreach (array_merge($rows, $febRows) as $r) {
            if (is_array($r) && isset($r['id'])) {
                $byId[(int) $r['id']] = $r;
            }
        }
        $rows = array_values($byId);
        usort($rows, static function (array $a, array $b): int {
            $ad = (string) ($a['submitted_at'] ?? '');
            $bd = (string) ($b['submitted_at'] ?? '');
            if ($ad === $bd) {
                return (int) ($b['id'] ?? 0) <=> (int) ($a['id'] ?? 0);
            }
            return strcmp($bd, $ad);
        });
        $rows = array_slice($rows, 0, 200);
    }
}

$urgentRows = $urgentDisbursementService->repository()->listForRequester($userId, 200);
foreach ($urgentRows as $urgentRow) {
    $rows[] = [
        'id' => (int)($urgentRow['id'] ?? 0),
        'request_type' => 'decaissement_urgent',
        'type_label' => 'Decaissement urgent',
        'requester_name' => (string)($urgentRow['requester_name'] ?? ''),
        'status' => (string)($urgentRow['status'] ?? ''),
        'title' => (string)($urgentRow['libelle'] ?? ''),
        'submitted_at' => (string)($urgentRow['submitted_at'] ?? ''),
        'payload' => [
            'request_code' => (string)($urgentRow['request_code'] ?? ''),
            'chantier_label' => (string)($urgentRow['chantier_label'] ?? ''),
            'details' => (string)($urgentRow['details'] ?? ''),
            'amount' => (float)($urgentRow['amount'] ?? 0),
            'regularization_due_at' => (string)($urgentRow['regularization_due_at'] ?? ''),
            'regularized_feb_request_id' => (int)($urgentRow['regularized_feb_request_id'] ?? 0),
            'regularized_fiche_num' => (string)($urgentRow['regularized_fiche_num'] ?? ''),
            'justification_path' => (string)($urgentRow['justification_path'] ?? ''),
        ],
        'urgent_row' => $urgentRow,
    ];
}
usort($rows, static function (array $a, array $b): int {
    $ad = (string)($a['submitted_at'] ?? '');
    $bd = (string)($b['submitted_at'] ?? '');
    if ($ad === $bd) {
        return (int)($b['id'] ?? 0) <=> (int)($a['id'] ?? 0);
    }
    return strcmp($bd, $ad);
});
// Applique les filtres avancés (volumes limités, filtrage côté PHP)
$rows = array_values(array_filter($rows, static function (array $r) use (
    $filterType,
    $filterStatus,
    $filterRequester,
    $filterDateFrom,
    $filterDateTo,
    $filterQuery
): bool {
    $requestType = strtolower(trim((string) ($r['request_type'] ?? '')));
    $status = strtolower(trim((string) ($r['status'] ?? '')));

    if ($filterType !== '' && $requestType !== $filterType) {
        return false;
    }

    if ($filterStatus !== '') {
        if ($filterStatus === 'decaissed') {
            if ($requestType === 'decaissement_urgent') {
                if ($status !== 'decaissed') {
                    return false;
                }
            } else {
            if ($requestType !== 'feb') {
                return false;
            }
            $p = (array) ($r['payload'] ?? []);
            $legacy = (array) ($p['legacy'] ?? []);
            $tag = strtolower(trim((string) ($legacy['status_tag'] ?? '')));
            if ($tag !== 'decaissed') {
                return false;
            }
            }
        } elseif ($status !== $filterStatus) {
            return false;
        }
    }

    $submittedAt = (string) ($r['submitted_at'] ?? '');
    $rowDate = substr($submittedAt, 0, 10);
    if ($requestType === 'feb') {
        $febDisplay = (array) ($r['feb_display'] ?? \EmployeeLegalRequestManager::extractFebDisplay($r));
        $candidate = trim((string) ($febDisplay['created_date'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $candidate)) {
            $rowDate = $candidate;
        }
    }

    if ($filterDateFrom !== '' && $rowDate !== '' && $rowDate < $filterDateFrom) {
        return false;
    }
    if ($filterDateTo !== '' && $rowDate !== '' && $rowDate > $filterDateTo) {
        return false;
    }

    if ($filterRequester !== '') {
        $req = trim((string) ($r['requester_name'] ?? ''));
        if ($requestType === 'feb') {
            $febDisplay = (array) ($r['feb_display'] ?? \EmployeeLegalRequestManager::extractFebDisplay($r));
            $req = trim((string) ($febDisplay['demandeur'] ?? $req));
        }
        if ($req === '') {
            return false;
        }
        if (strpos(employeeLower($req), employeeLower($filterRequester)) === false) {
            return false;
        }
    }

    if ($filterQuery !== '') {
        $needle = employeeLower($filterQuery);
        $typeLabel = (string) ($r['type_label'] ?? $requestType);
        $title = (string) ($r['title'] ?? '');
        $reqName = (string) ($r['requester_name'] ?? '');
        $payload = (array) ($r['payload'] ?? []);
        $payloadStr = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payloadStr === false) {
            $payloadStr = '';
        }

        $extra = '';
        if ($requestType === 'feb') {
            $febDisplay = (array) ($r['feb_display'] ?? \EmployeeLegalRequestManager::extractFebDisplay($r));
            $extra = ' '
                . (string) ($febDisplay['chantier_label'] ?? '')
                . ' ' . (string) ($febDisplay['service_beneficiaire'] ?? '')
                . ' ' . (string) ($febDisplay['demandeur'] ?? '')
                . ' ' . (string) ($febDisplay['description'] ?? '');
        } elseif ($requestType === 'decaissement_urgent') {
            $extra = ' '
                . (string)($payload['request_code'] ?? '')
                . ' ' . (string)($payload['chantier_label'] ?? '')
                . ' ' . (string)($payload['details'] ?? '');
        }

        $hay = '#'
            . (string) ($r['id'] ?? '') . ' '
            . $requestType . ' '
            . $typeLabel . ' '
            . $title . ' '
            . $status . ' '
            . $reqName . ' '
            . $payloadStr
            . $extra;

        if (strpos(employeeLower($hay), $needle) === false) {
            return false;
        }
    }

    return true;
}));

$linkedFichesByFebCode = buildLinkedFichesByFebCode($pdo, $rows);
$openUrgentRegularizations = $urgentDisbursementService->listOpenRegularizationsForUser($userId);
$blockedUrgentRegularizations = $urgentDisbursementService->listBlockedForUser($userId);
$urgentRegularizationMessage = $urgentDisbursementService->blockMessageForUser($userId);
$currentFilters = [
    'type' => $filterType ?: null,
    'status' => $filterStatus ?: null,
    'q' => $filterQuery ?: null,
    'demandeur' => $filterRequester ?: null,
    'date_from' => $filterDateFrom ?: null,
    'date_to' => $filterDateTo ?: null,
    'archives' => $includeLegacyFeb ? 1 : null,
    'view' => $viewMode !== 'liste' ? $viewMode : null,
    'month' => $viewMode === 'calendrier' ? $calendarMonth : null,
];

$requestViews = [];
$requestsByDay = [];
foreach ($rows as $row) {
    $febDisplay = null;
    if (($row['request_type'] ?? '') === 'feb') {
        $febDisplay = (array) ($row['feb_display'] ?? \EmployeeLegalRequestManager::extractFebDisplay($row));
    }
    $salaryClaimDisplay = (($row['request_type'] ?? '') === 'reclamation_salariale')
        ? (array) ($row['salary_claim_display'] ?? \EmployeeLegalRequestManager::extractSalaryClaimDisplay($row))
        : null;
    $febCode = is_array($febDisplay) ? trim((string) ($febDisplay['numero_bon_commande_interne'] ?? $febDisplay['code_autorisation_feb'] ?? '')) : '';
    $linkedFichesForFeb = $febCode !== '' ? (($linkedFichesByFebCode[employeeLower($febCode)] ?? []) ?: []) : [];
    $primaryLinkedFiche = $linkedFichesForFeb[0] ?? null;
    $primaryLinkedStatus = is_array($primaryLinkedFiche) ? (array) ($primaryLinkedFiche['_status'] ?? []) : [];
    $amount = requestAmountForRow($row);
    $badge = requestBadgeForRow($row);
    $dateYmd = employeeRequestDateYmd($row);

    $view = [
        'row' => $row,
        'badge' => $badge,
        'amount' => $amount,
        'febDisplay' => $febDisplay,
        'salaryClaimDisplay' => $salaryClaimDisplay,
        'febCode' => $febCode,
        'linkedFichesForFeb' => $linkedFichesForFeb,
        'primaryLinkedFiche' => $primaryLinkedFiche,
        'primaryLinkedStatus' => $primaryLinkedStatus,
        'date_ymd' => $dateYmd,
        'date_label' => $dateYmd !== '' ? employeeFormatDateFr($dateYmd) : employeeFormatDateFr((string) ($row['submitted_at'] ?? '')),
        'title' => employeeRequestTitle($row, $febDisplay, $salaryClaimDisplay),
        'description' => employeeRequestDescription($row, $febDisplay, $salaryClaimDisplay),
        'requester' => employeeRequestRequesterName($row, $febDisplay, $salaryClaimDisplay),
        'view_query' => ($row['request_type'] ?? '') === 'decaissement_urgent' ? '' : employeeRequestDetailQuery($row, $currentFilters),
    ];

    $requestViews[] = $view;
    if ($dateYmd !== '') {
        $requestsByDay[$dateYmd][] = $view;
    }
}

$calendarBaseDate = DateTime::createFromFormat('!Y-m-d', $calendarMonth . '-01') ?: new DateTime('first day of this month');
$calendarPrev = (clone $calendarBaseDate)->modify('-1 month')->format('Y-m');
$calendarNext = (clone $calendarBaseDate)->modify('+1 month')->format('Y-m');

\AuditTrail::logPageConsultation([
    'module' => 'employe',
    'action' => 'mes_demandes_view',
    'entity_type' => 'employee_request_list',
    'entity_label' => 'mes_demandes_employe',
    'details' => [
        'request_count' => count($rows),
        'filter_type' => $filterType !== '' ? $filterType : null,
        'filter_status' => $filterStatus !== '' ? $filterStatus : null,
        'filter_query' => $filterQuery !== '' ? $filterQuery : null,
        'filter_requester' => $filterRequester !== '' ? $filterRequester : null,
        'date_from' => $filterDateFrom !== '' ? $filterDateFrom : null,
        'date_to' => $filterDateTo !== '' ? $filterDateTo : null,
        'view' => $viewMode,
        'calendar_month' => $viewMode === 'calendrier' ? $calendarMonth : null,
    ],
], $pdo);

$pageTitle = 'Mes demandes';
$layoutRootBase = '../';
$activeEmployeeMenu = 'demandes';

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<div class="min-h-screen bg-[radial-gradient(circle_at_top_left,_rgba(240,249,255,0.95),_transparent_42%),radial-gradient(circle_at_top_right,_rgba(224,231,255,0.85),_transparent_35%),linear-gradient(180deg,_#f8fafc_0%,_#eef2ff_100%)] pb-12">
    <div class="mx-auto max-w-[1600px] px-4 py-6 2xl:px-8">
        <div class="relative overflow-hidden rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="pointer-events-none absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-indigo-600 via-slate-900 to-emerald-500"></div>
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.28em] text-indigo-600">Mes demandes</p>
                    <h1 class="mt-2 text-2xl font-semibold text-slate-950">Suivi & décisions</h1>
                    <p class="mt-1 text-sm text-slate-600">Consultez le statut et les commentaires RH / Finances.</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <?php if ($filterType === 'feb'): ?>
                        <a href="nouvelle_demande_employe.php?type=feb" class="rounded-2xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
                            <i class="fas fa-clipboard-list mr-2"></i>
                            Nouvelle FEB
                        </a>
                    <?php endif; ?>
                    <a href="actes_legaux_employe.php" class="rounded-2xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                        <i class="fas fa-plus mr-2"></i>
                        Nouvelle demande
                    </a>
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

            <?php if ($openUrgentRegularizations): ?>
                <?php $firstUrgency = $openUrgentRegularizations[0]; ?>
                <div class="mt-5 rounded-2xl border <?= $blockedUrgentRegularizations ? 'border-rose-200 bg-rose-50 text-rose-900' : 'border-amber-200 bg-amber-50 text-amber-900' ?> px-4 py-4 text-sm">
                    <div class="font-semibold"><?= $blockedUrgentRegularizations ? 'Blocage de regularisation actif' : 'Regularisation urgente attendue' ?></div>
                    <div class="mt-1">
                        <?= htmlspecialchars($urgentRegularizationMessage !== '' ? $urgentRegularizationMessage : ('Votre decaissement urgent ' . (string)($firstUrgency['request_code'] ?? '') . ' doit etre regularise par une FEB puis une fiche associee.')) ?>
                    </div>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <a href="nouvelle_demande_employe.php?type=feb&urgent_id=<?= (int)($firstUrgency['id'] ?? 0) ?>" class="rounded-xl bg-rose-600 px-4 py-2 text-xs font-semibold text-white hover:bg-rose-700">Creer la FEB de regularisation</a>
                        <?php if (!empty($firstUrgency['regularized_feb_request_id'])): ?>
                            <a href="details_demande_employe.php?id=<?= (int)$firstUrgency['regularized_feb_request_id'] ?>" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">Voir la FEB liee</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <form method="get" class="mt-6 rounded-2xl border border-slate-200 bg-slate-50/70 px-4 py-4">
                <input type="hidden" name="view" value="<?= htmlspecialchars($viewMode) ?>">
                <?php if ($viewMode === 'calendrier'): ?>
                    <input type="hidden" name="month" value="<?= htmlspecialchars($calendarMonth) ?>">
                <?php endif; ?>
                <div class="grid grid-cols-1 gap-3 md:grid-cols-12">
                    <div class="md:col-span-5">
                        <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Libellé / Description</div>
                        <div class="mt-2 flex items-center gap-2 rounded-2xl border border-slate-200 bg-white px-3 py-2">
                            <i class="fas fa-magnifying-glass text-slate-400"></i>
                            <input class="w-full bg-transparent text-sm text-slate-900 outline-none" type="search" name="q" value="<?= htmlspecialchars($filterQuery) ?>" placeholder="Ex: chantier, description, référence, commentaire…">
                        </div>
                    </div>

                    <div class="md:col-span-3">
                        <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Demandeur</div>
                        <div class="mt-2 flex items-center gap-2 rounded-2xl border border-slate-200 bg-white px-3 py-2">
                            <i class="fas fa-user text-slate-400"></i>
                            <input class="w-full bg-transparent text-sm text-slate-900 outline-none" type="text" name="demandeur" value="<?= htmlspecialchars($filterRequester) ?>" placeholder="Nom du demandeur">
                        </div>
                    </div>

                    <div class="md:col-span-2">
                        <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Type</div>
                        <div class="mt-2 flex items-center gap-2 rounded-2xl border border-slate-200 bg-white px-3 py-2">
                            <i class="fas fa-layer-group text-slate-400"></i>
                            <input id="typeSearch" class="w-full bg-transparent text-sm text-slate-900 outline-none" type="text" list="typeOptions" value="<?= htmlspecialchars($typeSearchDisplay) ?>" placeholder="Tous">
                            <input type="hidden" name="type" id="typeValue" value="<?= htmlspecialchars($filterType) ?>">
                        </div>
                    </div>

                    <div class="md:col-span-2">
                        <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Statut</div>
                        <div class="mt-2 flex items-center gap-2 rounded-2xl border border-slate-200 bg-white px-3 py-2">
                            <i class="fas fa-flag text-slate-400"></i>
                            <input id="statusSearch" class="w-full bg-transparent text-sm text-slate-900 outline-none" type="text" list="statusOptions" value="<?= htmlspecialchars($statusSearchDisplay) ?>" placeholder="Tous">
                            <input type="hidden" name="status" id="statusValue" value="<?= htmlspecialchars($filterStatus) ?>">
                        </div>
                    </div>

                    <div class="md:col-span-3">
                        <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Du</div>
                        <div class="mt-2 flex items-center gap-2 rounded-2xl border border-slate-200 bg-white px-3 py-2">
                            <i class="fas fa-calendar text-slate-400"></i>
                            <input class="w-full bg-transparent text-sm text-slate-900 outline-none" type="date" name="date_from" value="<?= htmlspecialchars($filterDateFrom) ?>">
                        </div>
                    </div>

                    <div class="md:col-span-3">
                        <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Au</div>
                        <div class="mt-2 flex items-center gap-2 rounded-2xl border border-slate-200 bg-white px-3 py-2">
                            <i class="fas fa-calendar-check text-slate-400"></i>
                            <input class="w-full bg-transparent text-sm text-slate-900 outline-none" type="date" name="date_to" value="<?= htmlspecialchars($filterDateTo) ?>">
                        </div>
                    </div>

                    <div class="md:col-span-6 flex flex-wrap items-center gap-2 md:justify-end md:self-end">
                        <label class="inline-flex items-center gap-2 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-2 text-sm font-semibold text-amber-900">
                            <input type="checkbox" name="archives" value="1" class="h-4 w-4 rounded border-amber-300 text-amber-600" <?= $includeLegacyFeb ? 'checked' : '' ?>>
                            Afficher FEB archives
                        </label>
                        <button class="inline-flex items-center rounded-2xl bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800" type="submit">
                            <i class="fas fa-search mr-2 text-xs"></i>
                            Filtrer
                        </button>

                        <a class="inline-flex items-center rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-900 hover:bg-slate-50" href="mes_demandes_employe.php">
                            <i class="fas fa-rotate-left mr-2 text-xs text-slate-500"></i>
                            Réinitialiser
                        </a>
                    </div>
                </div>

                <datalist id="typeOptions">
                    <?php foreach ($requestTypes as $key => $def): ?>
                        <option value="<?= htmlspecialchars((string) $key . ' — ' . (string) ($def['label'] ?? $key)) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
                <datalist id="statusOptions">
                    <option value="submitted — En attente"></option>
                    <option value="approved — Approuvée"></option>
                    <option value="rejected — Refusée"></option>
                    <option value="cancelled — Annulée"></option>
                    <option value="decaissed — Décaissée (FEB)"></option>
                    <option value="regularization_pending — Regularisation en cours"></option>
                    <option value="regularized — Regularisee"></option>
                </datalist>

                <?php if ($filterType || $filterStatus || $filterQuery || $filterRequester || $filterDateFrom || $filterDateTo || $includeLegacyFeb): ?>
                    <div class="mt-3 text-xs text-slate-500">
                        Filtres actifs:
                        <?php if ($filterQuery): ?><span class="font-semibold">q=<?= htmlspecialchars($filterQuery) ?></span><?php endif; ?>
                        <?php if ($filterRequester): ?><span class="font-semibold">demandeur=<?= htmlspecialchars($filterRequester) ?></span><?php endif; ?>
                        <?php if ($filterType): ?>
                            <span class="font-semibold">type=<?= htmlspecialchars((string) ($requestTypes[$filterType]['label'] ?? $filterType)) ?></span>
                        <?php endif; ?>
                        <?php if ($filterStatus): ?>
                            <span class="font-semibold">statut=<?= htmlspecialchars((string) ($statusLabels[$filterStatus] ?? $filterStatus)) ?></span>
                        <?php endif; ?>
                        <?php if ($filterDateFrom): ?><span class="font-semibold">du=<?= htmlspecialchars($filterDateFrom) ?></span><?php endif; ?>
                        <?php if ($filterDateTo): ?><span class="font-semibold">au=<?= htmlspecialchars($filterDateTo) ?></span><?php endif; ?>
                        <?php if ($includeLegacyFeb): ?><span class="font-semibold">FEB archives incluses</span><?php endif; ?>
                    </div>
                <?php endif; ?>
            </form>

            <div class="mt-4 flex flex-col gap-3 rounded-2xl border border-slate-200 bg-white px-4 py-3 lg:flex-row lg:items-center lg:justify-between">
                <div class="text-sm text-slate-700">
                    <span class="font-semibold"><?= count($requestViews) ?></span> demande(s) affichée(s)
                </div>
                <div class="inline-flex w-full rounded-2xl border border-slate-200 bg-slate-50 p-1 sm:w-auto">
                    <a class="flex-1 rounded-xl px-4 py-2 text-center text-sm font-semibold <?= $viewMode === 'liste' ? 'bg-white text-indigo-700 shadow-sm' : 'text-slate-600 hover:text-slate-900' ?>"
                        href="<?= htmlspecialchars(employeeViewLink($currentFilters, ['view' => null, 'month' => null])) ?>">
                        <i class="fas fa-list mr-2"></i>Liste
                    </a>
                    <a class="flex-1 rounded-xl px-4 py-2 text-center text-sm font-semibold <?= $viewMode === 'cards' ? 'bg-white text-indigo-700 shadow-sm' : 'text-slate-600 hover:text-slate-900' ?>"
                        href="<?= htmlspecialchars(employeeViewLink($currentFilters, ['view' => 'cards', 'month' => null])) ?>">
                        <i class="fas fa-grip mr-2"></i>Cartes
                    </a>
                    <a class="flex-1 rounded-xl px-4 py-2 text-center text-sm font-semibold <?= $viewMode === 'calendrier' ? 'bg-white text-indigo-700 shadow-sm' : 'text-slate-600 hover:text-slate-900' ?>"
                        href="<?= htmlspecialchars(employeeViewLink($currentFilters, ['view' => 'calendrier', 'month' => $calendarMonth])) ?>">
                        <i class="fas fa-calendar-days mr-2"></i>Calendrier
                    </a>
                </div>
            </div>

            <?php if ($viewMode !== 'calendrier'): ?>
            <div class="mt-4 flex flex-col gap-3 rounded-2xl border border-slate-200 bg-white px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="text-sm text-slate-700">
                    <span class="font-semibold" id="selectedCount">0</span> sélectionnée(s)
                    <span class="mx-2 text-slate-300">|</span>
                    Total sélectionné: <span class="font-semibold" id="selectedTotal">0 FCFA</span>
                </div>
                <div class="flex flex-wrap gap-2">
                    <form id="exportExcelForm" method="post" action="<?= htmlspecialchars($layoutRootBase, ENT_QUOTES, 'UTF-8') ?>request/export_employee_requests_excel.php" target="_blank">
                        <input type="hidden" name="ids" id="exportIdsExcel" value="">
                        <button id="exportExcelBtn" type="submit" disabled class="inline-flex items-center rounded-2xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition disabled:cursor-not-allowed disabled:opacity-40 hover:bg-indigo-700">
                            <i class="fas fa-file-excel mr-2"></i>
                            Export Excel
                        </button>
                    </form>
                    <form id="exportPdfForm" method="post" action="<?= htmlspecialchars($layoutRootBase, ENT_QUOTES, 'UTF-8') ?>request/export_employee_requests_pdf.php" target="_blank">
                        <input type="hidden" name="ids" id="exportIdsPdf" value="">
                        <button id="exportPdfBtn" type="submit" disabled class="inline-flex items-center rounded-2xl bg-gray-900 px-4 py-2 text-sm font-semibold text-white transition disabled:cursor-not-allowed disabled:opacity-40 hover:bg-gray-800">
                            <i class="fas fa-file-pdf mr-2"></i>
                            Export PDF
                        </button>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($viewMode === 'liste'): ?>
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 text-slate-500">
                            <th class="px-3 py-3 font-medium">
                                <input id="selectAll" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-indigo-600">
                            </th>
                            <th class="px-3 py-3 font-medium">Réf.</th>
                            <th class="px-3 py-3 font-medium">Type</th>
                            <th class="px-3 py-3 font-medium">Titre</th>
                            <th class="px-3 py-3 font-medium">Montant</th>
                            <th class="px-3 py-3 font-medium">Statut</th>
                            <th class="px-3 py-3 font-medium">Date</th>
                            <th class="px-3 py-3 font-medium"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$requestViews): ?>
                            <tr>
                                <td class="px-3 py-4 text-slate-600" colspan="8">Aucune demande.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($requestViews as $requestView): ?>
                                <?php
                                $row = $requestView['row'];
                                $badge = $requestView['badge'];
                                $amount = (float) $requestView['amount'];
                                $febDisplay = $requestView['febDisplay'];
                                $salaryClaimDisplay = $requestView['salaryClaimDisplay'];
                                $febCode = (string) $requestView['febCode'];
                                $linkedFichesForFeb = $requestView['linkedFichesForFeb'];
                                $primaryLinkedFiche = $requestView['primaryLinkedFiche'];
                                $primaryLinkedStatus = $requestView['primaryLinkedStatus'];
                                $viewQuery = (string) $requestView['view_query'];
                                $isUrgentRequest = ($row['request_type'] ?? '') === 'decaissement_urgent';
                                $urgentPayload = (array)($row['payload'] ?? []);
                                ?>
                                <tr class="border-b border-slate-100 last:border-b-0">
                                    <td class="px-3 py-4">
                                        <input type="checkbox" class="rowSelect h-4 w-4 rounded border-slate-300 text-indigo-600" value="<?= (int) ($row['id'] ?? 0) ?>" data-amount="<?= htmlspecialchars((string) $amount, ENT_QUOTES, 'UTF-8') ?>" <?= $isUrgentRequest ? 'disabled title="Export non disponible pour les urgences"' : '' ?>>
                                    </td>
                                    <td class="px-3 py-4 font-semibold text-slate-800">#<?= (int) ($row['id'] ?? 0) ?></td>
                                    <td class="px-3 py-4 text-slate-700"><?= htmlspecialchars((string) ($row['type_label'] ?? $row['request_type'])) ?></td>
                                    <td class="px-3 py-4 text-slate-700">
                                        <?php if ($isUrgentRequest): ?>
                                            <div class="font-semibold text-slate-900"><?= htmlspecialchars((string)($row['title'] ?? 'Urgence')) ?></div>
                                            <div class="mt-1 text-xs text-slate-600"><span class="font-semibold">Code :</span> <?= htmlspecialchars((string)($urgentPayload['request_code'] ?? '')) ?></div>
                                            <div class="mt-1 text-xs text-slate-600"><span class="font-semibold">Chantier :</span> <?= htmlspecialchars((string)($urgentPayload['chantier_label'] ?? '')) ?></div>
                                            <div class="mt-1 text-xs text-slate-600"><span class="font-semibold">Details :</span> <?= htmlspecialchars(employeeShortText((string)($urgentPayload['details'] ?? ''), 140)) ?></div>
                                            <?php if (!empty($urgentPayload['regularization_due_at'])): ?>
                                                <div class="mt-2 inline-flex rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-800">
                                                    Regularisation avant <?= htmlspecialchars(employeeFormatDateFr((string)$urgentPayload['regularization_due_at'])) ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($urgentPayload['regularized_fiche_num'])): ?>
                                                <div class="mt-2 inline-flex rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-800">
                                                    Fiche <?= htmlspecialchars((string)$urgentPayload['regularized_fiche_num']) ?>
                                                </div>
                                            <?php endif; ?>
                                        <?php elseif (($row['request_type'] ?? '') === 'feb' && is_array($febDisplay)): ?>
                                            <div class="font-semibold text-slate-900"><?= htmlspecialchars((string) ($febDisplay['chantier_label'] ?? 'CHANTIER N° —')) ?></div>
                                            <div class="mt-1 text-xs text-slate-600"><span class="font-semibold">Demandeur :</span> <?= htmlspecialchars((string) ($febDisplay['demandeur'] ?? ($row['requester_name'] ?? ''))) ?></div>
                                            <div class="mt-1 text-xs text-slate-600"><span class="font-semibold">Description :</span> <?= htmlspecialchars((string) ($febDisplay['description'] ?? ($row['title'] ?? ''))) ?></div>
                                            <?php $febPayload = (array) ($row['payload'] ?? []); $febBeneficiaryChoice = strtolower(trim((string) ($febPayload['beneficiary_choice'] ?? (($febPayload['provisional_decaissement']['beneficiary_choice'] ?? 'requester'))))); ?>
                                            <div class="mt-1 text-xs text-slate-600"><span class="font-semibold">Bénéficiaire des fiches :</span> <?= $febBeneficiaryChoice === 'supplier' ? 'Fournisseur choisi' : 'Moi-même (demandeur)' ?></div>
                                            <div class="mt-1 text-xs text-slate-500"><span class="font-semibold">Créée le :</span> <?= htmlspecialchars((string) (($febDisplay['created_date'] ?? '') ?: substr((string) ($row['submitted_at'] ?? ''), 0, 10))) ?></div>
                                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                                <?php if ($febCode !== ''): ?>
                                                    <button type="button"
                                                        class="copy-feb-code inline-flex items-center rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-800 hover:bg-emerald-100"
                                                        data-copy-feb-code="<?= htmlspecialchars($febCode, ENT_QUOTES, 'UTF-8') ?>"
                                                        title="Copier le code FEB">
                                                        <i class="fas fa-copy mr-1 text-[10px]"></i>
                                                        <?= htmlspecialchars($febCode) ?>
                                                    </button>
                                                <?php else: ?>
                                                    <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-semibold text-slate-700">Référence de demande : FEB-<?= (int) ($row['id'] ?? 0) ?></span>
                                                    <span class="text-xs text-slate-500">Code d’autorisation financière en attente</span>
                                                <?php endif; ?>
                                                <span class="copy-feb-feedback hidden text-xs font-semibold text-emerald-700">Copié</span>
                                            </div>
                                            <?php if (is_array($primaryLinkedFiche)): ?>
                                                <div class="mt-2 rounded-2xl border border-indigo-100 bg-indigo-50/60 px-3 py-2">
                                                    <div class="flex flex-wrap items-center gap-2">
                                                        <span class="text-xs font-semibold text-indigo-900">Fiche liée</span>
                                                        <a class="text-xs font-semibold text-indigo-700 hover:text-indigo-900"
                                                            href="details_decaissement_employe.php?num_fiche=<?= urlencode((string) ($primaryLinkedFiche['num_fiche'] ?? '')) ?>">
                                                            <?= htmlspecialchars((string) ($primaryLinkedFiche['num_fiche'] ?? '')) ?>
                                                        </a>
                                                        <?php if (count($linkedFichesForFeb) > 1): ?>
                                                            <span class="text-xs text-indigo-700">+<?= count($linkedFichesForFeb) - 1 ?> autre(s)</span>
                                                        <?php endif; ?>
                                                        <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-[11px] font-semibold <?= htmlspecialchars((string) ($primaryLinkedStatus['class'] ?? 'bg-slate-100 text-slate-700 border-slate-200')) ?>">
                                                            <?= htmlspecialchars((string) ($primaryLinkedStatus['label'] ?? 'Statut inconnu')) ?>
                                                        </span>
                                                    </div>
                                                    <div class="mt-1 text-xs text-slate-600">
                                                        <span class="font-semibold">Bénéficiaire :</span> <?= htmlspecialchars((string) ($primaryLinkedFiche['beficiaire_fiche'] ?? '—')) ?>
                                                        <span class="mx-1 text-slate-300">|</span>
                                                        <span class="font-semibold">Montant :</span> <?= htmlspecialchars(format_fcfa((float) ($primaryLinkedFiche['montant_fiche'] ?? 0))) ?>
                                                    </div>
                                                    <?php if ($canAdjustBeneficiarySupplier): ?>
                                                        <div class="mt-2 flex flex-wrap gap-2">
                                                            <?php foreach ($linkedFichesForFeb as $linkedFiche): ?>
                                                                <?php if (!empty($linkedFiche['num_fiche'])): ?>
                                                                    <button type="button" class="js-self-adjust-beneficiary rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700"
                                                                        data-num-fiche="<?= htmlspecialchars((string)$linkedFiche['num_fiche'], ENT_QUOTES, 'UTF-8') ?>"
                                                                        data-feb-request-id="<?= (int)($row['id'] ?? 0) ?>">
                                                                        Ajuster <?= htmlspecialchars((string)$linkedFiche['num_fiche']) ?>
                                                                    </button>
                                                                <?php endif; ?>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php elseif ($febCode !== '' && strtolower((string) ($row['status'] ?? '')) === 'approved'): ?>
                                                <div class="mt-2 inline-flex rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-semibold text-slate-700">
                                                    Aucune fiche de décaissement liée
                                                </div>
                                            <?php endif; ?>
                                        <?php elseif (($row['request_type'] ?? '') === 'reclamation_salariale' && is_array($salaryClaimDisplay)): ?>
                                            <div class="font-semibold text-slate-900"><?= htmlspecialchars((string) ($salaryClaimDisplay['objet_label'] ?? ($row['title'] ?? ''))) ?></div>
                                            <div class="mt-1 text-xs text-slate-600"><span class="font-semibold">Mois concerné :</span> <?= htmlspecialchars((string) ($salaryClaimDisplay['mois_label'] ?? '—')) ?></div>
                                            <div class="mt-1 text-xs text-slate-600"><span class="font-semibold">Demandeur :</span> <?= htmlspecialchars((string) ($salaryClaimDisplay['demandeur'] ?? ($row['requester_name'] ?? ''))) ?></div>
                                            <?php
                                            $summaryText = trim((string) ($salaryClaimDisplay['details_differend'] ?? ($row['title'] ?? '')));
                                            if (function_exists('mb_strimwidth')) {
                                                $summaryText = (string) mb_strimwidth($summaryText, 0, 110, '...');
                                            } elseif (strlen($summaryText) > 110) {
                                                $summaryText = substr($summaryText, 0, 107) . '...';
                                            }
                                            ?>
                                            <div class="mt-1 text-xs text-slate-500"><span class="font-semibold">Résumé :</span> <?= htmlspecialchars($summaryText) ?></div>
                                        <?php else: ?>
                                            <?= htmlspecialchars((string) ($row['title'] ?? '')) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-3 py-4 text-slate-700"><?= $amount > 0 ? htmlspecialchars(format_fcfa($amount)) : '—' ?></td>
                                    <td class="px-3 py-4">
                                        <span class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold <?= $badge['class'] ?>">
                                            <?= htmlspecialchars($badge['label']) ?>
                                        </span>
                                    </td>
                                    <td class="px-3 py-4 text-slate-600"><?= htmlspecialchars(employeeFormatDateFr((string) ($row['submitted_at'] ?? ''))) ?></td>
                                    <td class="px-3 py-4 text-right">
                                        <div class="flex items-center justify-end gap-3">
                                            <?php if ($isUrgentRequest && in_array((string)($row['status'] ?? ''), ['decaissed', 'regularization_pending'], true)): ?>
                                                <a class="text-sm font-semibold text-rose-700 hover:text-rose-800" href="nouvelle_demande_employe.php?type=feb&urgent_id=<?= (int)($row['id'] ?? 0) ?>">Regulariser</a>
                                            <?php elseif ($isUrgentRequest && !empty($urgentPayload['regularized_feb_request_id'])): ?>
                                                <a class="text-sm font-semibold text-indigo-700 hover:text-indigo-800" href="details_demande_employe.php?id=<?= (int)$urgentPayload['regularized_feb_request_id'] ?>">Voir FEB</a>
                                            <?php endif; ?>
                                            <?php if (!$isUrgentRequest && $manager->canRequesterEditRequest($row, $userId)): ?>
                                                <a class="text-sm font-semibold text-sky-700 hover:text-sky-800" href="nouvelle_demande_employe.php?type=<?= urlencode((string) ($row['request_type'] ?? '')) ?>&request_id=<?= (int) ($row['id'] ?? 0) ?>"><?= (($row['request_type'] ?? '') === 'feb' && ($row['status'] ?? '') === 'details_requested') ? 'Compléter' : 'Modifier' ?></a>
                                            <?php endif; ?>
                                            <?php if (in_array((string) ($row['request_type'] ?? ''), ['permission_absence', 'conge', 'retard', 'reclamation_salariale', 'heures_supp', 'avance_salaire'], true)): ?>
                                                <a class="text-sm font-semibold text-gray-700 hover:text-gray-800" href="../request/export_employee_request_pdf.php?id=<?= (int) ($row['id'] ?? 0) ?>" target="_blank" rel="noopener">PDF</a>
                                            <?php endif; ?>
                                            <?php if (($row['request_type'] ?? '') === 'feb'): ?><a class="text-sm font-semibold text-teal-700" href="../request/export_feb_submission_pdf.php?id=<?= (int)$row['id'] ?>" target="_blank" rel="noopener">FEB PDF</a><?php endif; ?>
                                            <?php if (($row['request_type'] ?? '') === 'feb' && strtolower((string) ($row['status'] ?? '')) === 'approved' && is_array($febDisplay) && $febCode !== ''): ?>
                                                <a class="text-sm font-semibold text-gray-700 hover:text-gray-800" href="../request/export_feb_pdf.php?id=<?= (int) ($row['id'] ?? 0) ?>" target="_blank" rel="noopener">BCI PDF</a>
                                                <?php if (is_array($primaryLinkedFiche) && !empty($primaryLinkedFiche['num_fiche'])): ?>
                                                    <a class="text-sm font-semibold text-indigo-700 hover:text-indigo-800"
                                                        href="details_decaissement_employe.php?num_fiche=<?= urlencode((string) ($primaryLinkedFiche['num_fiche'] ?? '')) ?>">
                                                        Voir fiche
                                                    </a>
                                                <?php else: ?>
                                                    <a class="text-sm font-semibold text-emerald-700 hover:text-emerald-800"
                                                        href="../decaissement/formulaire_demande_decaissement.php?code_autorisation_feb=<?= urlencode($febCode) ?>">
                                                        Demander le décaissement
                                                    </a>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            <?php if (!$isUrgentRequest): ?>
                                                <a class="text-sm font-semibold text-indigo-700 hover:text-indigo-800" href="details_demande_employe.php?<?= htmlspecialchars($viewQuery) ?>">Voir</a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <?php if ($viewMode === 'cards'): ?>
                <?php if (!$requestViews): ?>
                    <div class="mt-4 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-8 text-center text-sm text-slate-600">Aucune demande.</div>
                <?php else: ?>
                    <div class="mt-4 grid grid-cols-1 gap-3 lg:grid-cols-2">
                        <?php foreach ($requestViews as $requestView): ?>
                            <?php
                            $row = $requestView['row'];
                            $badge = $requestView['badge'];
                            $amount = (float) $requestView['amount'];
                            $febCode = (string) $requestView['febCode'];
                            $primaryLinkedFiche = $requestView['primaryLinkedFiche'];
                            $primaryLinkedStatus = $requestView['primaryLinkedStatus'];
                            $isUrgentRequest = ($row['request_type'] ?? '') === 'decaissement_urgent';
                            $urgentPayload = (array)($row['payload'] ?? []);
                            ?>
                            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="text-sm font-semibold text-slate-500">#<?= (int) ($row['id'] ?? 0) ?></span>
                                            <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-[11px] font-semibold <?= htmlspecialchars((string) $badge['class']) ?>">
                                                <?= htmlspecialchars((string) $badge['label']) ?>
                                            </span>
                                        </div>
                                        <h3 class="mt-2 text-base font-semibold text-slate-950"><?= htmlspecialchars((string) $requestView['title']) ?></h3>
                                        <p class="mt-1 text-sm text-slate-600"><?= htmlspecialchars(employeeShortText((string) $requestView['description'], 150)) ?></p>
                                    </div>
                                    <input type="checkbox" class="rowSelect h-4 w-4 shrink-0 rounded border-slate-300 text-indigo-600" value="<?= (int) ($row['id'] ?? 0) ?>" data-amount="<?= htmlspecialchars((string) $amount, ENT_QUOTES, 'UTF-8') ?>" <?= $isUrgentRequest ? 'disabled title="Export non disponible pour les urgences"' : '' ?>>
                                </div>
                                <div class="mt-4 grid grid-cols-2 gap-3 text-sm">
                                    <div>
                                        <div class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-400">Type</div>
                                        <div class="mt-1 text-slate-700"><?= htmlspecialchars((string) ($row['type_label'] ?? $row['request_type'])) ?></div>
                                    </div>
                                    <div>
                                        <div class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-400">Date</div>
                                        <div class="mt-1 text-slate-700"><?= htmlspecialchars((string) $requestView['date_label']) ?></div>
                                    </div>
                                    <div>
                                        <div class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-400">Demandeur</div>
                                        <div class="mt-1 text-slate-700"><?= htmlspecialchars((string) ($requestView['requester'] ?: '—')) ?></div>
                                    </div>
                                    <div>
                                        <div class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-400">Montant</div>
                                        <div class="mt-1 text-slate-700"><?= $amount > 0 ? htmlspecialchars(format_fcfa($amount)) : '—' ?></div>
                                    </div>
                                </div>
                                <?php if ($febCode !== ''): ?>
                                    <div class="mt-4 flex flex-wrap items-center gap-2">
                                        <button type="button" class="copy-feb-code inline-flex items-center rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-800 hover:bg-emerald-100" data-copy-feb-code="<?= htmlspecialchars($febCode, ENT_QUOTES, 'UTF-8') ?>" title="Copier le code FEB">
                                            <i class="fas fa-copy mr-1 text-[10px]"></i><?= htmlspecialchars($febCode) ?>
                                        </button>
                                        <span class="copy-feb-feedback hidden text-xs font-semibold text-emerald-700">Copié</span>
                                    </div>
                                <?php endif; ?>
                                <?php if (is_array($primaryLinkedFiche)): ?>
                                    <div class="mt-3 flex flex-wrap items-center gap-2 rounded-2xl border border-indigo-100 bg-indigo-50/60 px-3 py-2">
                                        <span class="text-xs font-semibold text-indigo-900">Fiche liée</span>
                                        <a class="text-xs font-semibold text-indigo-700 hover:text-indigo-900" href="details_decaissement_employe.php?num_fiche=<?= urlencode((string) ($primaryLinkedFiche['num_fiche'] ?? '')) ?>">
                                            <?= htmlspecialchars((string) ($primaryLinkedFiche['num_fiche'] ?? '')) ?>
                                        </a>
                                        <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-[11px] font-semibold <?= htmlspecialchars((string) ($primaryLinkedStatus['class'] ?? 'bg-slate-100 text-slate-700 border-slate-200')) ?>">
                                            <?= htmlspecialchars((string) ($primaryLinkedStatus['label'] ?? 'Statut inconnu')) ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                                <div class="mt-4 flex flex-wrap justify-end gap-3">
                                    <?php if ($isUrgentRequest && in_array((string)($row['status'] ?? ''), ['decaissed', 'regularization_pending'], true)): ?>
                                        <a class="text-sm font-semibold text-rose-700 hover:text-rose-800" href="nouvelle_demande_employe.php?type=feb&urgent_id=<?= (int)($row['id'] ?? 0) ?>">Regulariser</a>
                                    <?php elseif ($isUrgentRequest && !empty($urgentPayload['regularized_feb_request_id'])): ?>
                                        <a class="text-sm font-semibold text-indigo-700 hover:text-indigo-800" href="details_demande_employe.php?id=<?= (int)$urgentPayload['regularized_feb_request_id'] ?>">Voir FEB</a>
                                    <?php endif; ?>
                                    <?php if (!$isUrgentRequest && $manager->canRequesterEditRequest($row, $userId)): ?>
                                        <a class="text-sm font-semibold text-sky-700 hover:text-sky-800" href="nouvelle_demande_employe.php?type=<?= urlencode((string) ($row['request_type'] ?? '')) ?>&request_id=<?= (int) ($row['id'] ?? 0) ?>"><?= (($row['request_type'] ?? '') === 'feb' && ($row['status'] ?? '') === 'details_requested') ? 'Compléter' : 'Modifier' ?></a>
                                    <?php endif; ?>
                                    <?php if (in_array((string) ($row['request_type'] ?? ''), ['permission_absence', 'conge', 'retard', 'reclamation_salariale', 'heures_supp', 'avance_salaire'], true)): ?>
                                        <a class="text-sm font-semibold text-gray-700 hover:text-gray-800" href="../request/export_employee_request_pdf.php?id=<?= (int) ($row['id'] ?? 0) ?>" target="_blank" rel="noopener">PDF</a>
                                    <?php endif; ?>
                                    <?php if (($row['request_type'] ?? '') === 'feb'): ?><a class="text-sm font-semibold text-teal-700" href="../request/export_feb_submission_pdf.php?id=<?= (int)$row['id'] ?>" target="_blank" rel="noopener">FEB PDF</a><?php endif; ?>
                                            <?php if (($row['request_type'] ?? '') === 'feb' && strtolower((string) ($row['status'] ?? '')) === 'approved' && $febCode !== ''): ?>
                                        <a class="text-sm font-semibold text-gray-700 hover:text-gray-800" href="../request/export_feb_pdf.php?id=<?= (int) ($row['id'] ?? 0) ?>" target="_blank" rel="noopener">BCI PDF</a>
                                        <?php if (is_array($primaryLinkedFiche) && !empty($primaryLinkedFiche['num_fiche'])): ?>
                                            <a class="text-sm font-semibold text-indigo-700 hover:text-indigo-800" href="details_decaissement_employe.php?num_fiche=<?= urlencode((string) ($primaryLinkedFiche['num_fiche'] ?? '')) ?>">Voir fiche</a>
                                        <?php else: ?>
                                            <a class="text-sm font-semibold text-emerald-700 hover:text-emerald-800" href="../decaissement/formulaire_demande_decaissement.php?code_autorisation_feb=<?= urlencode($febCode) ?>">Demander le décaissement</a>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php if (!$isUrgentRequest): ?>
                                        <a class="text-sm font-semibold text-indigo-700 hover:text-indigo-800" href="details_demande_employe.php?<?= htmlspecialchars((string) $requestView['view_query']) ?>">Voir</a>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($viewMode === 'calendrier'): ?>
                <?php
                $monthStart = clone $calendarBaseDate;
                $monthEnd = (clone $calendarBaseDate)->modify('last day of this month');
                $gridStart = (clone $monthStart)->modify('-' . ((int) $monthStart->format('N') - 1) . ' days');
                $gridEnd = (clone $monthEnd)->modify('+' . (7 - (int) $monthEnd->format('N')) . ' days');
                $monthNames = [1 => 'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];
                $calendarTitle = $monthNames[(int) $calendarBaseDate->format('n')] . ' ' . $calendarBaseDate->format('Y');
                ?>
                <div class="mt-4 rounded-2xl border border-slate-200 bg-white p-4">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 class="text-lg font-semibold text-slate-950"><?= htmlspecialchars($calendarTitle) ?></h2>
                            <p class="text-sm text-slate-500">Cliquez sur un jour pour voir les demandes et exporter le récapitulatif.</p>
                        </div>
                        <div class="flex gap-2">
                            <a class="rounded-2xl border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50" href="<?= htmlspecialchars(employeeViewLink($currentFilters, ['view' => 'calendrier', 'month' => $calendarPrev])) ?>">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                            <a class="rounded-2xl border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50" href="<?= htmlspecialchars(employeeViewLink($currentFilters, ['view' => 'calendrier', 'month' => date('Y-m')])) ?>">Aujourd'hui</a>
                            <a class="rounded-2xl border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50" href="<?= htmlspecialchars(employeeViewLink($currentFilters, ['view' => 'calendrier', 'month' => $calendarNext])) ?>">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        </div>
                    </div>
                    <div class="mt-4 grid grid-cols-7 gap-px overflow-hidden rounded-2xl border border-slate-200 bg-slate-200 text-sm">
                        <?php foreach (['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'] as $dayName): ?>
                            <div class="bg-slate-50 px-2 py-2 text-center text-xs font-semibold uppercase tracking-[0.14em] text-slate-500"><?= htmlspecialchars($dayName) ?></div>
                        <?php endforeach; ?>
                        <?php for ($day = clone $gridStart; $day <= $gridEnd; $day->modify('+1 day')): ?>
                            <?php
                            $dayYmd = $day->format('Y-m-d');
                            $dayRequests = $requestsByDay[$dayYmd] ?? [];
                            $exportableDayRequests = array_filter($dayRequests, static fn(array $item): bool => (($item['row']['request_type'] ?? '') !== 'decaissement_urgent'));
                            $dayIds = implode(',', array_map(static fn(array $item): int => (int) ($item['row']['id'] ?? 0), $exportableDayRequests));
                            $isCurrentMonth = $day->format('Y-m') === $calendarMonth;
                            ?>
                            <button type="button"
                                class="calendar-day min-h-[92px] bg-white p-2 text-left transition hover:bg-indigo-50 <?= $isCurrentMonth ? '' : 'opacity-45' ?> <?= $dayRequests ? 'cursor-pointer' : 'cursor-default' ?>"
                                data-day="<?= htmlspecialchars($dayYmd) ?>"
                                data-title="<?= htmlspecialchars(employeeFormatDateFr($dayYmd), ENT_QUOTES, 'UTF-8') ?>"
                                data-ids="<?= htmlspecialchars($dayIds, ENT_QUOTES, 'UTF-8') ?>"
                                <?= $dayRequests ? '' : 'disabled' ?>>
                                <span class="text-sm font-semibold text-slate-800"><?= (int) $day->format('j') ?></span>
                                <?php if ($dayRequests): ?>
                                    <span class="mt-3 inline-flex rounded-full bg-indigo-600 px-2.5 py-1 text-xs font-semibold text-white"><?= count($dayRequests) ?> demande(s)</span>
                                <?php endif; ?>
                            </button>
                        <?php endfor; ?>
                    </div>
                </div>

                <div class="hidden">
                    <?php foreach ($requestsByDay as $dayYmd => $dayRequests): ?>
                        <div id="calendar-day-content-<?= htmlspecialchars($dayYmd) ?>">
                            <div class="space-y-3">
                                <?php foreach ($dayRequests as $requestView): ?>
                                    <?php
                                    $row = $requestView['row'];
                                    $badge = $requestView['badge'];
                                    $amount = (float) $requestView['amount'];
                                    $febCode = (string) $requestView['febCode'];
                                    $primaryLinkedFiche = $requestView['primaryLinkedFiche'];
                                    $isUrgentRequest = ($row['request_type'] ?? '') === 'decaissement_urgent';
                                    $urgentPayload = (array)($row['payload'] ?? []);
                                    ?>
                                    <div class="rounded-2xl border border-slate-200 bg-white p-3">
                                        <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                            <div>
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <span class="text-sm font-semibold text-slate-500">#<?= (int) ($row['id'] ?? 0) ?></span>
                                                    <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-[11px] font-semibold <?= htmlspecialchars((string) $badge['class']) ?>"><?= htmlspecialchars((string) $badge['label']) ?></span>
                                                </div>
                                                <div class="mt-1 font-semibold text-slate-950"><?= htmlspecialchars((string) $requestView['title']) ?></div>
                                                <div class="mt-1 text-xs text-slate-600"><?= htmlspecialchars((string) ($requestView['requester'] ?: '—')) ?> · <?= htmlspecialchars((string) ($row['type_label'] ?? $row['request_type'])) ?> · <?= $amount > 0 ? htmlspecialchars(format_fcfa($amount)) : '—' ?></div>
                                            </div>
                                            <div class="flex flex-wrap gap-3 sm:justify-end">
                                                <?php if ($isUrgentRequest && in_array((string)($row['status'] ?? ''), ['decaissed', 'regularization_pending'], true)): ?>
                                                    <a class="text-sm font-semibold text-rose-700 hover:text-rose-800" href="nouvelle_demande_employe.php?type=feb&urgent_id=<?= (int)($row['id'] ?? 0) ?>">Regulariser</a>
                                                <?php elseif ($isUrgentRequest && !empty($urgentPayload['regularized_feb_request_id'])): ?>
                                                    <a class="text-sm font-semibold text-indigo-700 hover:text-indigo-800" href="details_demande_employe.php?id=<?= (int)$urgentPayload['regularized_feb_request_id'] ?>">Voir FEB</a>
                                                <?php endif; ?>
                                                <?php if (!$isUrgentRequest && $manager->canRequesterEditRequest($row, $userId)): ?>
                                                    <a class="text-sm font-semibold text-sky-700 hover:text-sky-800" href="nouvelle_demande_employe.php?type=<?= urlencode((string) ($row['request_type'] ?? '')) ?>&request_id=<?= (int) ($row['id'] ?? 0) ?>"><?= (($row['request_type'] ?? '') === 'feb' && ($row['status'] ?? '') === 'details_requested') ? 'Compléter' : 'Modifier' ?></a>
                                                <?php endif; ?>
                                                <?php if (in_array((string) ($row['request_type'] ?? ''), ['permission_absence', 'conge', 'retard', 'reclamation_salariale', 'heures_supp', 'avance_salaire'], true)): ?>
                                                    <a class="text-sm font-semibold text-gray-700 hover:text-gray-800" href="../request/export_employee_request_pdf.php?id=<?= (int) ($row['id'] ?? 0) ?>" target="_blank" rel="noopener">PDF</a>
                                                <?php endif; ?>
                                                <?php if (($row['request_type'] ?? '') === 'feb'): ?><a class="text-sm font-semibold text-teal-700" href="../request/export_feb_submission_pdf.php?id=<?= (int)$row['id'] ?>" target="_blank" rel="noopener">FEB PDF</a><?php endif; ?>
                                            <?php if (($row['request_type'] ?? '') === 'feb' && strtolower((string) ($row['status'] ?? '')) === 'approved' && $febCode !== ''): ?>
                                                    <a class="text-sm font-semibold text-gray-700 hover:text-gray-800" href="../request/export_feb_pdf.php?id=<?= (int) ($row['id'] ?? 0) ?>" target="_blank" rel="noopener">BCI PDF</a>
                                                    <?php if (is_array($primaryLinkedFiche) && !empty($primaryLinkedFiche['num_fiche'])): ?>
                                                        <a class="text-sm font-semibold text-indigo-700 hover:text-indigo-800" href="details_decaissement_employe.php?num_fiche=<?= urlencode((string) ($primaryLinkedFiche['num_fiche'] ?? '')) ?>">Voir fiche</a>
                                                    <?php else: ?>
                                                        <a class="text-sm font-semibold text-emerald-700 hover:text-emerald-800" href="../decaissement/formulaire_demande_decaissement.php?code_autorisation_feb=<?= urlencode($febCode) ?>">Demander le décaissement</a>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                                <?php if (!$isUrgentRequest): ?>
                                                    <a class="text-sm font-semibold text-indigo-700 hover:text-indigo-800" href="details_demande_employe.php?<?= htmlspecialchars((string) $requestView['view_query']) ?>">Voir</a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div id="calendarDayModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/50 px-4 py-6">
    <div class="max-h-[90vh] w-full max-w-3xl overflow-hidden rounded-2xl bg-white shadow-2xl">
        <div class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4">
            <div>
                <div class="text-xs font-semibold uppercase tracking-[0.18em] text-indigo-600">Demandes du jour</div>
                <h2 id="calendarDayModalTitle" class="mt-1 text-lg font-semibold text-slate-950"></h2>
            </div>
            <button type="button" id="calendarDayModalClose" class="rounded-xl border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                <i class="fas fa-xmark"></i>
            </button>
        </div>
        <div id="calendarDayModalBody" class="max-h-[60vh] overflow-y-auto bg-slate-50 px-5 py-4"></div>
        <div class="flex flex-wrap justify-end gap-2 border-t border-slate-200 px-5 py-4">
            <form id="calendarExportExcelForm" method="post" action="<?= htmlspecialchars($layoutRootBase, ENT_QUOTES, 'UTF-8') ?>request/export_employee_requests_excel.php" target="_blank">
                <input type="hidden" name="ids" id="calendarExportIdsExcel" value="">
                <button type="submit" class="rounded-2xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                    <i class="fas fa-file-excel mr-2"></i>Export Excel du jour
                </button>
            </form>
            <form id="calendarExportPdfForm" method="post" action="<?= htmlspecialchars($layoutRootBase, ENT_QUOTES, 'UTF-8') ?>request/export_employee_requests_pdf.php" target="_blank">
                <input type="hidden" name="ids" id="calendarExportIdsPdf" value="">
                <button type="submit" class="rounded-2xl bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800">
                    <i class="fas fa-file-pdf mr-2"></i>Export PDF du jour
                </button>
            </form>
        </div>
    </div>
</div>

<?php if ($canAdjustBeneficiarySupplier): ?>
<div id="selfAdjustBeneficiaryModal" class="fixed inset-0 z-[70] hidden items-center justify-center bg-slate-950/60 px-4 py-6">
    <div class="w-full max-w-xl rounded-2xl bg-white shadow-2xl">
        <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
            <div><div class="text-xs font-bold uppercase tracking-wider text-indigo-600">Correction autorisée</div><h2 class="mt-1 text-lg font-bold text-slate-950">Ajuster le fournisseur bénéficiaire</h2></div>
            <button type="button" id="selfAdjustBeneficiaryClose" class="rounded-lg px-3 py-2 text-slate-500 hover:bg-slate-100"><i class="fas fa-times"></i></button>
        </div>
        <form id="selfAdjustBeneficiaryForm" class="space-y-4 px-5 py-5">
            <input type="hidden" name="num_fiche"><input type="hidden" name="feb_request_id"><input type="hidden" name="adjustment_source" value="employee_self">
            <input type="hidden" name="beficiaire_fiche"><input type="hidden" name="montant_fiche">
            <div id="selfAdjustBeneficiaryCurrent" class="rounded-xl bg-slate-50 px-4 py-3 text-sm text-slate-700"></div>
            <div class="relative">
                <label for="selfAdjustSupplierSearch" class="text-xs font-bold uppercase tracking-wider text-slate-500">Nouveau fournisseur bénéficiaire</label>
                <input type="hidden" name="fournisseur_id" required>
                <div class="relative mt-2">
                    <i class="fas fa-search pointer-events-none absolute left-3 top-3.5 text-xs text-slate-400"></i>
                    <input id="selfAdjustSupplierSearch" type="search" autocomplete="off" placeholder="Rechercher par nom, type, téléphone…" class="w-full rounded-xl border border-slate-200 bg-white py-3 pl-9 pr-10 text-sm outline-none transition focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100">
                    <button type="button" id="selfAdjustSupplierClear" class="absolute right-2 top-2 hidden h-8 w-8 rounded-lg text-slate-400 hover:bg-slate-100" aria-label="Effacer"><i class="fas fa-times text-xs"></i></button>
                </div>
                <div id="selfAdjustSupplierResults" class="absolute z-20 mt-1 hidden max-h-64 w-full overflow-y-auto rounded-xl border border-slate-200 bg-white p-1.5 shadow-xl"></div>
            </div>
            <div id="selfAdjustSupplierPreview" class="hidden rounded-xl border border-indigo-100 bg-gradient-to-r from-indigo-50/80 to-white p-3"></div>
            <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs leading-5 text-amber-900">Seuls le bénéficiaire et les coordonnées du fournisseur seront corrigés. Le montant et le statut de décaissement resteront inchangés.</div>
            <div id="selfAdjustBeneficiaryError" class="hidden rounded-xl bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700"></div>
            <div class="flex justify-end gap-2"><button type="button" id="selfAdjustBeneficiaryCancel" class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold">Annuler</button><button type="submit" class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white">Enregistrer l’ajustement</button></div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
    (function() {
        const typeSearch = document.getElementById('typeSearch');
        const typeValue = document.getElementById('typeValue');
        const statusSearch = document.getElementById('statusSearch');
        const statusValue = document.getElementById('statusValue');
        const adjustModal = document.getElementById('selfAdjustBeneficiaryModal');
        const adjustForm = document.getElementById('selfAdjustBeneficiaryForm');
        const supplierSearch = document.getElementById('selfAdjustSupplierSearch');
        const supplierResults = document.getElementById('selfAdjustSupplierResults');
        const supplierPreview = document.getElementById('selfAdjustSupplierPreview');
        const supplierClear = document.getElementById('selfAdjustSupplierClear');
        let adjustmentSuppliers = [];
        const supplierName = supplier => supplier.supplier_display_name || supplier.nom_commercial || supplier.raison_sociale || `Fournisseur #${supplier.id_fournisseur || ''}`;
        const supplierMissing = supplier => [
            [supplier.nom_commercial || supplier.raison_sociale || supplier.person_last_name, 'nom'],
            [supplier.telephone, 'téléphone'],
            [supplier.email, 'e-mail'],
            [supplier.adresse, 'adresse'],
            [supplier.photo_path || supplier.logo_path, 'photo/logo']
        ].filter(([value]) => !String(value || '').trim()).map(([, label]) => label);
        const supplierVisual = supplier => {
            const path = String(supplier.visual_path || supplier.photo_path || supplier.logo_path || '').trim();
            if (!path) return '';
            if (/^(https?:)?\/\//i.test(path) || path.startsWith('/')) return path;
            return '../' + path.replace(/^\.\//, '').replace(/^\//, '');
        };
        const supplierInitials = supplier => supplierName(supplier).split(/\s+/).slice(0, 2).map(part => part.charAt(0)).join('').toUpperCase() || '?';
        const makeSupplierAvatar = (supplier, sizeClass = 'h-10 w-10') => {
            const holder = document.createElement('div');
            holder.className = `${sizeClass} shrink-0 overflow-hidden rounded-xl bg-indigo-100 text-indigo-700 flex items-center justify-center text-xs font-bold`;
            const visual = supplierVisual(supplier);
            if (visual) {
                const img = document.createElement('img');
                img.src = visual; img.alt = ''; img.className = 'h-full w-full object-cover';
                img.onerror = () => { holder.textContent = supplierInitials(supplier); };
                holder.appendChild(img);
            } else holder.textContent = supplierInitials(supplier);
            return holder;
        };
        const showSupplierPreview = supplier => {
            supplierPreview.replaceChildren();
            if (!supplier) { supplierPreview.classList.add('hidden'); return; }
            const row = document.createElement('div'); row.className = 'flex items-center gap-3';
            row.appendChild(makeSupplierAvatar(supplier, 'h-12 w-12'));
            const info = document.createElement('div'); info.className = 'min-w-0 flex-1';
            const name = document.createElement('div'); name.className = 'truncate text-sm font-bold text-slate-900'; name.textContent = supplierName(supplier);
            const type = document.createElement('div'); type.className = 'mt-0.5 text-xs font-medium text-indigo-600'; type.textContent = supplier.supplier_type_label || (supplier.supplier_type === 'physique' ? 'Personne physique' : 'Personne morale');
            const contact = document.createElement('div'); contact.className = 'mt-1 truncate text-xs text-slate-500'; contact.textContent = [supplier.telephone, supplier.email].filter(Boolean).join(' · ') || 'Coordonnées non renseignées';
            info.append(name, type, contact); row.appendChild(info); supplierPreview.appendChild(row); supplierPreview.classList.remove('hidden');
        };
        const selectSupplier = supplier => {
            adjustForm.elements.fournisseur_id.value = supplier.id_fournisseur || '';
            supplierSearch.value = supplierName(supplier);
            supplierClear.classList.remove('hidden');
            supplierResults.classList.add('hidden');
            showSupplierPreview(supplier);
        };
        const renderSupplierResults = query => {
            const needle = String(query || '').trim().toLocaleLowerCase('fr');
            const matches = adjustmentSuppliers.filter(supplier => [supplierName(supplier), supplier.supplier_type_label, supplier.telephone, supplier.email, supplier.category_names, supplier.code_fournisseur].join(' ').toLocaleLowerCase('fr').includes(needle)).slice(0, 40);
            supplierResults.replaceChildren();
            if (!matches.length) {
                const empty = document.createElement('div'); empty.className = 'px-3 py-5 text-center text-xs text-slate-500'; empty.textContent = 'Aucun bénéficiaire trouvé'; supplierResults.appendChild(empty);
            }
            matches.forEach(supplier => {
                const missing = supplierMissing(supplier);
                const item = document.createElement('button'); item.type = 'button'; item.disabled = missing.length > 0; item.className = 'flex w-full items-center gap-3 rounded-lg px-2.5 py-2 text-left hover:bg-indigo-50 focus:bg-indigo-50 focus:outline-none disabled:cursor-not-allowed disabled:opacity-55';
                item.appendChild(makeSupplierAvatar(supplier));
                const info = document.createElement('div'); info.className = 'min-w-0 flex-1';
                const name = document.createElement('div'); name.className = 'truncate text-sm font-semibold text-slate-800'; name.textContent = supplierName(supplier);
                const meta = document.createElement('div'); meta.className = 'mt-0.5 truncate text-[11px] text-slate-500'; meta.textContent = missing.length ? `À compléter : ${missing.join(', ')}` : `${supplier.supplier_type_label || 'Fournisseur'}${supplier.telephone ? ' · ' + supplier.telephone : ''}`;
                info.append(name, meta); item.appendChild(info); item.addEventListener('click', () => selectSupplier(supplier)); supplierResults.appendChild(item);
            });
            supplierResults.classList.remove('hidden');
        };
        supplierSearch?.addEventListener('focus', () => renderSupplierResults(supplierSearch.value));
        supplierSearch?.addEventListener('input', () => { adjustForm.elements.fournisseur_id.value = ''; showSupplierPreview(null); supplierClear.classList.toggle('hidden', !supplierSearch.value); renderSupplierResults(supplierSearch.value); });
        supplierClear?.addEventListener('click', () => { supplierSearch.value = ''; adjustForm.elements.fournisseur_id.value = ''; showSupplierPreview(null); supplierClear.classList.add('hidden'); renderSupplierResults(''); supplierSearch.focus(); });
        const closeAdjustModal = () => { adjustModal?.classList.add('hidden'); adjustModal?.classList.remove('flex'); supplierResults?.classList.add('hidden'); };
        document.getElementById('selfAdjustBeneficiaryClose')?.addEventListener('click', closeAdjustModal);
        document.getElementById('selfAdjustBeneficiaryCancel')?.addEventListener('click', closeAdjustModal);
        document.querySelectorAll('.js-self-adjust-beneficiary').forEach(button => button.addEventListener('click', async () => {
            const num = button.dataset.numFiche || '';
            const requestId = button.dataset.febRequestId || '';
            const error = document.getElementById('selfAdjustBeneficiaryError');
            error?.classList.add('hidden');
            try {
                const detailsUrl = '../decaissement/request/get_fiche_details.php?num_fiche=' + encodeURIComponent(num) + '&_=' + Date.now();
                const response = await fetch(detailsUrl, {
                    cache: 'no-store',
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' }
                });
                const data = await response.json();
                if (!data.success) throw new Error(data.message || data.error || 'Impossible de charger les informations de cette fiche.');
                if (!data.can_adjust_beneficiary_supplier) throw new Error('Autorisation d’ajustement absente pour le compte connecté.');
                adjustForm.elements.num_fiche.value = num;
                adjustForm.elements.feb_request_id.value = requestId;
                adjustForm.elements.beficiaire_fiche.value = data.fiche.beficiaire_fiche || '';
                adjustForm.elements.montant_fiche.value = data.fiche.montant_fiche || 0;
                document.getElementById('selfAdjustBeneficiaryCurrent').textContent = `Fiche ${num} · bénéficiaire actuel : ${data.fiche.beficiaire_fiche || '—'}`;
                adjustmentSuppliers = data.adjustment_suppliers || [];
                adjustForm.elements.fournisseur_id.value = '';
                supplierSearch.value = '';
                supplierClear.classList.add('hidden');
                showSupplierPreview(null);
                adjustModal.classList.remove('hidden'); adjustModal.classList.add('flex');
                window.setTimeout(() => supplierSearch.focus(), 80);
            } catch (exception) { alert(exception.message); }
        }));
        adjustForm?.addEventListener('submit', async event => {
            event.preventDefault();
            const error = document.getElementById('selfAdjustBeneficiaryError');
            const submit = adjustForm.querySelector('button[type="submit"]');
            if (!adjustForm.elements.fournisseur_id.value) {
                error.textContent = 'Recherchez puis sélectionnez un fournisseur bénéficiaire complet.';
                error.classList.remove('hidden');
                supplierSearch.focus();
                return;
            }
            submit.disabled = true;
            try {
                const response = await fetch('../decaissement/request/modifier_fiche_decaissement.php', { method: 'POST', body: new FormData(adjustForm) });
                const data = await response.json();
                if (!data.ok) throw new Error(data.error || 'Échec de l’ajustement.');
                window.location.reload();
            } catch (exception) { error.textContent = exception.message; error.classList.remove('hidden'); submit.disabled = false; }
        });

        function parseKey(raw) {
            const value = String(raw || '').trim();
            if (!value) return '';
            const parts = value.split('—');
            const key = String(parts[0] || '').trim().toLowerCase();
            return key;
        }

        function syncHidden() {
            if (typeSearch && typeValue) {
                typeValue.value = parseKey(typeSearch.value);
            }
            if (statusSearch && statusValue) {
                statusValue.value = parseKey(statusSearch.value);
            }
        }

        if (typeSearch) {
            typeSearch.addEventListener('input', syncHidden);
            typeSearch.addEventListener('change', syncHidden);
        }
        if (statusSearch) {
            statusSearch.addEventListener('input', syncHidden);
            statusSearch.addEventListener('change', syncHidden);
        }
        syncHidden();

        async function copyText(value) {
            if (navigator.clipboard && window.isSecureContext) {
                await navigator.clipboard.writeText(value);
                return;
            }

            const textarea = document.createElement('textarea');
            textarea.value = value;
            textarea.setAttribute('readonly', 'readonly');
            textarea.style.position = 'fixed';
            textarea.style.left = '-9999px';
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand('copy');
            textarea.remove();
        }

        document.addEventListener('click', async (event) => {
            const button = event.target.closest('[data-copy-feb-code]');
            if (!button) return;

            const code = String(button.getAttribute('data-copy-feb-code') || '').trim();
            if (!code) return;

            try {
                await copyText(code);
                const feedback = button.parentElement ? button.parentElement.querySelector('.copy-feb-feedback') : null;
                if (feedback) {
                    feedback.classList.remove('hidden');
                    window.setTimeout(() => feedback.classList.add('hidden'), 1200);
                }
            } catch (e) {
                // Le bouton reste lisible si la copie navigateur échoue.
            }
        });

        const selectAll = document.getElementById('selectAll');
        const rowSelects = Array.from(document.querySelectorAll('.rowSelect:not(:disabled)'));
        const selectedCountEl = document.getElementById('selectedCount');
        const selectedTotalEl = document.getElementById('selectedTotal');
        const exportExcelBtn = document.getElementById('exportExcelBtn');
        const exportPdfBtn = document.getElementById('exportPdfBtn');
        const exportIdsExcel = document.getElementById('exportIdsExcel');
        const exportIdsPdf = document.getElementById('exportIdsPdf');
        const calendarModal = document.getElementById('calendarDayModal');
        const calendarModalTitle = document.getElementById('calendarDayModalTitle');
        const calendarModalBody = document.getElementById('calendarDayModalBody');
        const calendarModalClose = document.getElementById('calendarDayModalClose');
        const calendarExportExcelForm = document.getElementById('calendarExportExcelForm');
        const calendarExportPdfForm = document.getElementById('calendarExportPdfForm');
        const calendarExportIdsExcel = document.getElementById('calendarExportIdsExcel');
        const calendarExportIdsPdf = document.getElementById('calendarExportIdsPdf');

        function formatFcfa(value) {
            const v = Number(value || 0);
            if (!isFinite(v) || v <= 0) return '0 FCFA';
            return new Intl.NumberFormat('fr-FR', {
                maximumFractionDigits: 0
            }).format(v) + ' FCFA';
        }

        function compute() {
            if (!selectedCountEl || !selectedTotalEl || !exportExcelBtn || !exportPdfBtn || !exportIdsExcel || !exportIdsPdf) {
                return;
            }

            const selected = rowSelects.filter(cb => cb.checked);
            const ids = selected.map(cb => cb.value).filter(Boolean);

            let total = 0;
            selected.forEach(cb => {
                const amount = Number(cb.getAttribute('data-amount') || 0);
                if (isFinite(amount) && amount > 0) total += amount;
            });

            selectedCountEl.textContent = String(ids.length);
            selectedTotalEl.textContent = formatFcfa(total);

            const hasSelection = ids.length > 0;
            exportExcelBtn.disabled = !hasSelection;
            exportPdfBtn.disabled = !hasSelection;

            const serialized = ids.join(',');
            exportIdsExcel.value = serialized;
            exportIdsPdf.value = serialized;

            if (selectAll) {
                selectAll.checked = rowSelects.length > 0 && selected.length === rowSelects.length;
                selectAll.indeterminate = selected.length > 0 && selected.length < rowSelects.length;
            }
        }

        if (selectAll) {
            selectAll.addEventListener('change', () => {
                rowSelects.forEach(cb => {
                    cb.checked = !!selectAll.checked;
                });
                compute();
            });
        }

        rowSelects.forEach(cb => cb.addEventListener('change', compute));
        compute();

        function closeCalendarModal() {
            if (!calendarModal) return;
            calendarModal.classList.add('hidden');
            calendarModal.classList.remove('flex');
            if (calendarModalBody) {
                calendarModalBody.innerHTML = '';
            }
        }

        document.querySelectorAll('.calendar-day[data-day]').forEach(dayButton => {
            dayButton.addEventListener('click', () => {
                if (!calendarModal || !calendarModalTitle || !calendarModalBody) return;

                const day = String(dayButton.getAttribute('data-day') || '');
                const content = document.getElementById('calendar-day-content-' + day);
                if (!content) return;

                calendarModalTitle.textContent = String(dayButton.getAttribute('data-title') || day);
                calendarModalBody.innerHTML = content.innerHTML;

                const ids = String(dayButton.getAttribute('data-ids') || '');
                if (calendarExportIdsExcel) calendarExportIdsExcel.value = ids;
                if (calendarExportIdsPdf) calendarExportIdsPdf.value = ids;
                if (calendarExportExcelForm) calendarExportExcelForm.classList.toggle('hidden', ids === '');
                if (calendarExportPdfForm) calendarExportPdfForm.classList.toggle('hidden', ids === '');

                calendarModal.classList.remove('hidden');
                calendarModal.classList.add('flex');
            });
        });

        if (calendarModalClose) {
            calendarModalClose.addEventListener('click', closeCalendarModal);
        }
        if (calendarModal) {
            calendarModal.addEventListener('click', event => {
                if (event.target === calendarModal) {
                    closeCalendarModal();
                }
            });
        }
        document.addEventListener('keydown', event => {
            if (event.key === 'Escape') {
                closeCalendarModal();
            }
        });
    })();
</script>

<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>
