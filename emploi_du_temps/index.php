<?php
// Important: on doit pouvoir rediriger (header Location) même si des includes génèrent du HTML.
// Bufferiser l'output évite le "headers already sent" (ex: navbar.php produit du contenu).
ob_start();

$projectRoot = dirname(__DIR__);

require_once $projectRoot . '/auth_check.php';
require_once __DIR__ . '/controllers/EmployeeScheduleModuleController.php';

$pageTitle = 'Emploi du temps';
$activeMenu = 'emploi_du_temps';
require_once $projectRoot . '/decaissement/includes/header.php';
require_once $projectRoot . '/decaissement/includes/navbar.php';

$employeeScheduleController = new \EmploiDuTemps\Controllers\EmployeeScheduleModuleController($projectRoot);
extract($employeeScheduleController->pageContext(), EXTR_SKIP);

$appBasePath = rtrim(\AccessManager::getApplicationBasePath(), '/');
$rootUrl = static function (string $path) use ($appBasePath): string {
    return ($appBasePath === '' ? '' : $appBasePath) . '/' . ltrim($path, '/');
};

$flashError = '';
$flashSuccess = '';

$flashErrorSessionKey = 'emploi_du_temps_flash_error';
$flashSuccessSessionKey = 'emploi_du_temps_flash_success';
if (empty($flashError) && !empty($_SESSION[$flashErrorSessionKey])) {
    $flashError = (string)$_SESSION[$flashErrorSessionKey];
    unset($_SESSION[$flashErrorSessionKey]);
}
if (empty($flashSuccess) && !empty($_SESSION[$flashSuccessSessionKey])) {
    $flashSuccess = (string)$_SESSION[$flashSuccessSessionKey];
    unset($_SESSION[$flashSuccessSessionKey]);
}

$sessionPersonnelIdKey = 'emploi_du_temps_personnel_id';
$sessionMatriculeKey = 'matricule_personnel_tasks';

$personnelId = (int)($_SESSION[$sessionPersonnelIdKey] ?? 0);
$isAdmin = function_exists('hasPermission') ? hasPermission('acces_admin') : false;
$personnelOptions = [];
$serviceOptions = [];
$chantierOptions = [];
$selectedPersonnelDisplay = '';

$resolveCurrentUserPersonnelId = static function (\EmployeeSchedule $schedule): ?int {
    $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
    if ($userId > 0) {
        $linkedId = $schedule->resolvePersonnelIdByUserId($userId);
        if ($linkedId) {
            return (int)$linkedId;
        }
    }

    $email = (string)($_SESSION['mail_pro'] ?? '');
    $linkedId = $schedule->resolvePersonnelIdByEmail($email);
    return $linkedId ? (int)$linkedId : null;
};

// Auto-mapping prioritaire via user_devis_banamur.personnel_id, puis fallback email.
try {
    $currentUserPersonnelId = (int)($resolveCurrentUserPersonnelId($schedule) ?? 0);
} catch (Throwable $e) {
    $currentUserPersonnelId = 0;
}

if ($personnelId <= 0) {
    try {
        $autoId = $currentUserPersonnelId;
        if ($autoId) {
            $personnelId = (int)$autoId;
            $_SESSION[$sessionPersonnelIdKey] = $personnelId;
        }
    } catch (Throwable $e) {
        // ignore
    }
}

$action = (string)($_GET['action'] ?? '');

if (!$scheduleReady) {
    $flashError = $schedule->getInitError() ?: 'Le module n\'a pas pu initialiser le stockage des créneaux.';
}

try {
    $personnelOptions = $schedule->listSelectablePersonnel();
} catch (Throwable $e) {
    $personnelOptions = [];
}

try {
    $serviceOptions = $schedule->listSelectableServices();
} catch (Throwable $e) {
    $serviceOptions = [];
}

try {
    $stmtChantiers = $pdo->query('SELECT id, code, titre FROM chantier_banamur WHERE archived_at IS NULL ORDER BY code ASC, titre ASC LIMIT 2500');
    $chantierOptions = $stmtChantiers ? ($stmtChantiers->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
} catch (Throwable $e) {
    $chantierOptions = [];
}

$canAccessTaskStatsDashboard = function_exists('hasPermission') ? hasPermission('acces_dashboard_stat_taches') : $isAdmin;

// Utilitaire: redirection sans output
$redirect = function (string $url) {
    $url = trim($url);
    if ($url === '') {
        $url = 'emploi_du_temps.php';
    }

    if (!headers_sent()) {
        if (ob_get_level() > 0) {
            @ob_clean();
        }
        header('Location: ' . $url);
        exit;
    }

    // Fallback (devrait être rare si ob_start est actif)
    echo '<script>window.location.href=' . json_encode($url) . ';</script>';
    echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"></noscript>';
    exit;
};

$auditScheduleAction = static function (
    string $event,
    string $action,
    string $result,
    ?string $entityId,
    string $entityLabel,
    array $details = [],
    ?string $severity = null
) use ($pdo): void {
    $payload = [
        'module' => 'core',
        'action' => $action,
        'entity_type' => 'employee_schedule',
        'entity_id' => $entityId,
        'entity_label' => $entityLabel,
        'result' => $result,
        'details' => $details,
    ];
    if ($severity !== null) {
        $payload['severity'] = $severity;
    }

    \AuditTrail::log($event, $payload, $pdo);
};

// Période sélectionnée
$period = (string)($_GET['period'] ?? 'week');
if (!in_array($period, ['day', 'week', 'month', 'year'], true)) {
    $period = 'week';
}

$displayMode = (string)($_GET['display'] ?? 'calendar');
if (!in_array($displayMode, ['calendar', 'list', 'stats'], true)) {
    $displayMode = 'calendar';
}
if ($displayMode === 'stats' && !$canAccessTaskStatsDashboard) {
    \AccessManager::denyAccess('acces_dashboard_stat_taches', 'emploi_du_temps.php');
}
if (in_array($action, ['export_recap', 'export_recap_csv', 'export_recap_xlsx', 'export_recap_pdf'], true) && !$canAccessTaskStatsDashboard) {
    \AccessManager::denyAccess('acces_dashboard_stat_taches', 'emploi_du_temps.php');
}

$riskOnly = $displayMode === 'list' && (string)($_GET['risk_only'] ?? '') === '1';

$printListMode = $displayMode === 'list' && (string)($_GET['print'] ?? '') === '1';

$selectedDate = (string)($_GET['date'] ?? date('Y-m-d'));
$dateObj = DateTimeImmutable::createFromFormat('Y-m-d', $selectedDate);
if (!$dateObj) {
    $dateObj = new DateTimeImmutable('today');
}

switch ($period) {
    case 'day':
        $rangeStart = $dateObj->setTime(0, 0, 0);
        $rangeEnd = $dateObj->setTime(23, 59, 59);
        break;
    case 'month':
        $rangeStart = $dateObj->modify('first day of this month')->setTime(0, 0, 0);
        $rangeEnd = $dateObj->modify('last day of this month')->setTime(23, 59, 59);
        break;
    case 'year':
        $rangeStart = $dateObj->setDate((int)$dateObj->format('Y'), 1, 1)->setTime(0, 0, 0);
        $rangeEnd = $dateObj->setDate((int)$dateObj->format('Y'), 12, 31)->setTime(23, 59, 59);
        break;
    case 'week':
    default:
        // Semaine ISO: lundi -> dimanche
        $rangeStart = $dateObj->modify('monday this week')->setTime(0, 0, 0);
        $rangeEnd = $dateObj->modify('sunday this week')->setTime(23, 59, 59);
        break;
}

$returnPageUrl = 'emploi_du_temps.php?period=' . rawurlencode($period) . '&date=' . rawurlencode($dateObj->format('Y-m-d'));
if ($displayMode === 'stats' && $action === '') {
    $redirect('dashboard_statistiques_taches.php?period=' . rawurlencode($period) . '&date=' . rawurlencode($dateObj->format('Y-m-d')) . '&recap_service_id=' . max(0, (int)($_GET['recap_service_id'] ?? $_GET['service_id'] ?? 0)));
}

// Microsoft To Do (OAuth) actions
if ($action === 'microsoft_connect') {
    if ($msUserId <= 0) {
        $_SESSION[$flashErrorSessionKey] = 'Connexion Microsoft impossible (utilisateur non identifié).';
        $redirect('emploi_du_temps.php');
    }
    if (!$msTodo->isReady()) {
        $_SESSION[$flashErrorSessionKey] = $msTodo->getInitError() ?: 'Connexion Microsoft indisponible.';
        $redirect('emploi_du_temps.php');
    }
    if (!$msConfigured) {
        $_SESSION[$flashErrorSessionKey] = 'Configuration Microsoft manquante : renseignez tenant/client/secret.';
        $redirect('emploi_du_temps.php');
    }

    $state = bin2hex(random_bytes(16));
    $_SESSION['ms_todo_oauth_state'] = $state;
    $_SESSION['ms_todo_return_to'] = 'emploi_du_temps.php?period=' . rawurlencode($period) . '&date=' . rawurlencode($dateObj->format('Y-m-d'));

    $authorizeUrl = 'https://login.microsoftonline.com/' . rawurlencode($msTenantId) . '/oauth2/v2.0/authorize?' . http_build_query([
        'client_id' => $msClientId,
        'response_type' => 'code',
        'redirect_uri' => $msRedirectUri,
        'response_mode' => 'query',
        'scope' => 'offline_access Tasks.Read Tasks.ReadWrite User.Read',
        'state' => $state,
        'prompt' => 'select_account',
    ]);

    $redirect($authorizeUrl);
}

if ($action === 'microsoft_disconnect') {
    if ($msUserId > 0) {
        $msTodo->clearTokenRow($msUserId);
    }
    $_SESSION[$flashSuccessSessionKey] = 'Compte Microsoft déconnecté.';
    $redirect('emploi_du_temps.php?period=' . rawurlencode($period) . '&date=' . rawurlencode($dateObj->format('Y-m-d')));
}

if ($action === 'microsoft_callback') {
    $returnTo = (string)($_SESSION['ms_todo_return_to'] ?? 'emploi_du_temps.php');
    $expectedState = (string)($_SESSION['ms_todo_oauth_state'] ?? '');
    unset($_SESSION['ms_todo_return_to'], $_SESSION['ms_todo_oauth_state']);

    $oauthError = (string)($_GET['error'] ?? '');
    $oauthErrorDescription = (string)($_GET['error_description'] ?? '');
    if ($oauthError !== '') {
        $_SESSION[$flashErrorSessionKey] = $oauthErrorDescription ?: ('Erreur Microsoft: ' . $oauthError);
        $redirect($returnTo);
    }

    $state = (string)($_GET['state'] ?? '');
    $code = (string)($_GET['code'] ?? '');
    if ($expectedState === '' || $state === '' || !hash_equals($expectedState, $state)) {
        $_SESSION[$flashErrorSessionKey] = 'Erreur Microsoft: état OAuth invalide.';
        $redirect($returnTo);
    }
    if ($code === '') {
        $_SESSION[$flashErrorSessionKey] = 'Erreur Microsoft: code OAuth manquant.';
        $redirect($returnTo);
    }

    if ($msUserId <= 0) {
        $_SESSION[$flashErrorSessionKey] = 'Erreur Microsoft: utilisateur non identifié.';
        $redirect($returnTo);
    }

    $res = $msTodo->exchangeCodeForToken($msTenantId, $msClientId, $msClientSecret, $msRedirectUri, $code);
    if (empty($res['ok'])) {
        $_SESSION[$flashErrorSessionKey] = (string)($res['error'] ?? 'Connexion Microsoft impossible.');
        $redirect($returnTo);
    }

    $token = $res['token'] ?? null;
    if (!is_array($token) || empty($token['access_token'])) {
        $_SESSION[$flashErrorSessionKey] = 'Connexion Microsoft impossible (token invalide).';
        $redirect($returnTo);
    }

    $msTodo->saveTokenRow($msUserId, $token);
    $_SESSION[$flashSuccessSessionKey] = 'Connexion Microsoft réussie.';
    $redirect($returnTo);
}

function edt_schedule_rows(PDO $pdo, DateTimeInterface $start, DateTimeInterface $end, int $serviceId = 0): array
{
    $where = ['e.start_at < :end_at', 'e.end_at > :start_at'];
    $params = [
        'start_at' => $start->format('Y-m-d H:i:s'),
        'end_at' => $end->format('Y-m-d H:i:s'),
    ];
    if ($serviceId > 0) {
        $where[] = 'p.service_id = :service_id';
        $params['service_id'] = $serviceId;
    }

    $stmt = $pdo->prepare('
        SELECT e.id, e.personnel_id, e.title, e.description, e.location, e.start_at, e.end_at, e.status_code,
               p.nom_personnel_soignant AS personnel_name,
               p.matricule_personnel_soignant AS personnel_matricule,
               p.service_id,
               COALESCE(s.lib_service, "") AS service_label
        FROM employee_schedule_event e
        INNER JOIN personnel p ON p.id_personnel_soignant = e.personnel_id
        LEFT JOIN service s ON s.id_service = p.service_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY COALESCE(s.lib_service, ""), p.nom_personnel_soignant ASC, e.start_at ASC
    ');
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function edt_schedule_kpis(array $rows, ?DateTimeImmutable $now = null): array
{
    $now = $now ?: new DateTimeImmutable('now');
    $planned = count($rows);
    $done = 0;
    $late = 0;

    foreach ($rows as $row) {
        $status = (string)($row['status_code'] ?? 'planifie');
        if ($status === 'termine') {
            $done++;
            continue;
        }
        if ($status === 'annule') {
            continue;
        }
        try {
            $endAt = trim((string)($row['end_at'] ?? ''));
            if ($endAt !== '' && new DateTimeImmutable($endAt) < $now) {
                $late++;
            }
        } catch (Throwable $e) {
            // ignore invalid dates
        }
    }

    return [
        'planned' => $planned,
        'done' => $done,
        'late' => $late,
        'realization_rate' => $planned > 0 ? round(($done / $planned) * 100, 1) : null,
        'delay_rate' => $planned > 0 ? round(($late / $planned) * 100, 1) : null,
    ];
}

function edt_group_schedule_stats(array $rows, string $keyField, string $labelField): array
{
    $groups = [];
    foreach ($rows as $row) {
        $key = trim((string)($row[$keyField] ?? ''));
        if ($key === '') {
            $key = '0';
        }
        if (!isset($groups[$key])) {
            $label = trim((string)($row[$labelField] ?? ''));
            $groups[$key] = ['label' => $label !== '' ? $label : 'Non renseigné', 'rows' => []];
        }
        $groups[$key]['rows'][] = $row;
    }
    foreach ($groups as &$group) {
        $group['kpis'] = edt_schedule_kpis($group['rows']);
        unset($group['rows']);
    }
    unset($group);
    uasort($groups, static fn(array $a, array $b): int => strcmp((string)$a['label'], (string)$b['label']));
    return $groups;
}

function edt_presence_task_rows(PDO $pdo, DateTimeInterface $start, DateTimeInterface $end, int $serviceId = 0): array
{
    $where = [
        'a.attendance_date BETWEEN :start_date AND :end_date',
        'a.attendance_status IN ("present", "half_day", "mission")',
    ];
    $params = [
        'start_date' => $start->format('Y-m-d'),
        'end_date' => $end->format('Y-m-d'),
    ];
    if ($serviceId > 0) {
        $where[] = 'p.service_id = :service_id';
        $params['service_id'] = $serviceId;
    }

    try {
        $stmt = $pdo->prepare('
            SELECT a.personnel_id, a.attendance_date, a.attendance_status, a.check_in_time, a.check_out_time,
                   p.nom_personnel_soignant AS personnel_name,
                   p.matricule_personnel_soignant AS personnel_matricule,
                   p.service_id,
                   COALESCE(s.lib_service, "") AS service_label,
                   COUNT(e.id) AS task_count
            FROM rh_attendance_daily a
            INNER JOIN personnel p ON p.id_personnel_soignant = a.personnel_id
            LEFT JOIN service s ON s.id_service = p.service_id
            LEFT JOIN employee_schedule_event e ON e.personnel_id = a.personnel_id
                AND e.status_code <> "annule"
                AND DATE(e.start_at) <= a.attendance_date
                AND DATE(e.end_at) >= a.attendance_date
            WHERE ' . implode(' AND ', $where) . '
            GROUP BY a.personnel_id, a.attendance_date, a.attendance_status, a.check_in_time, a.check_out_time,
                     p.nom_personnel_soignant, p.matricule_personnel_soignant, p.service_id, s.lib_service
            ORDER BY COALESCE(s.lib_service, ""), p.nom_personnel_soignant ASC, a.attendance_date ASC
        ');
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function edt_presence_task_gap_stats(array $rows): array
{
    $personnel = [];
    $services = [];
    $presentDays = 0;
    $gapDays = 0;
    foreach ($rows as $row) {
        $presentDays++;
        $isGap = (int)($row['task_count'] ?? 0) <= 0;
        if ($isGap) {
            $gapDays++;
        }

        $personKey = (string)((int)($row['personnel_id'] ?? 0));
        $serviceKey = (string)((int)($row['service_id'] ?? 0));
        if (!isset($personnel[$personKey])) {
            $personnel[$personKey] = [
                'label' => trim((string)($row['personnel_name'] ?? '')) ?: 'Non renseigné',
                'matricule' => (string)($row['personnel_matricule'] ?? ''),
                'service_label' => (string)($row['service_label'] ?? ''),
                'present_days' => 0,
                'gap_days' => 0,
                'dates' => [],
            ];
        }
        if (!isset($services[$serviceKey])) {
            $services[$serviceKey] = [
                'label' => trim((string)($row['service_label'] ?? '')) ?: 'Non renseigné',
                'present_days' => 0,
                'gap_days' => 0,
            ];
        }

        $personnel[$personKey]['present_days']++;
        $services[$serviceKey]['present_days']++;
        if ($isGap) {
            $personnel[$personKey]['gap_days']++;
            $services[$serviceKey]['gap_days']++;
            $personnel[$personKey]['dates'][] = (string)($row['attendance_date'] ?? '');
        }
    }

    foreach ($personnel as &$stat) {
        $stat['gap_rate'] = $stat['present_days'] > 0 ? round(($stat['gap_days'] / $stat['present_days']) * 100, 1) : 0.0;
    }
    unset($stat);
    foreach ($services as &$stat) {
        $stat['gap_rate'] = $stat['present_days'] > 0 ? round(($stat['gap_days'] / $stat['present_days']) * 100, 1) : 0.0;
    }
    unset($stat);

    uasort($personnel, static fn(array $a, array $b): int => ($b['gap_rate'] <=> $a['gap_rate']) ?: strcmp((string)$a['label'], (string)$b['label']));
    uasort($services, static fn(array $a, array $b): int => ($b['gap_rate'] <=> $a['gap_rate']) ?: strcmp((string)$a['label'], (string)$b['label']));

    return [
        'kpis' => [
            'present_days' => $presentDays,
            'gap_days' => $gapDays,
            'gap_rate' => $presentDays > 0 ? round(($gapDays / $presentDays) * 100, 1) : null,
        ],
        'personnel' => $personnel,
        'services' => $services,
        'gap_rows' => array_values(array_filter($rows, static fn(array $row): bool => (int)($row['task_count'] ?? 0) <= 0)),
    ];
}

if (in_array($action, ['export_recap_xlsx', 'export_recap_pdf'], true) && $canAccessTaskStatsDashboard) {
    if (ob_get_level() > 0) {
        @ob_clean();
    }

    $exportServiceId = max(0, (int)($_GET['service_id'] ?? 0));
    $recapRows = edt_schedule_rows($pdo, $rangeStart, $rangeEnd, $exportServiceId);
    $recapKpis = edt_schedule_kpis($recapRows);
    $presenceRows = edt_presence_task_rows($pdo, $rangeStart, $rangeEnd, $exportServiceId);
    $presenceStats = edt_presence_task_gap_stats($presenceRows);
    $serviceLabel = 'Toute l entreprise';
    if ($exportServiceId > 0) {
        foreach ($serviceOptions as $serviceOption) {
            if ((int)($serviceOption['id'] ?? 0) === $exportServiceId) {
                $serviceLabel = (string)($serviceOption['label'] ?? $serviceLabel);
                break;
            }
        }
    }
    $statusLabelsForExport = $schedule->allowedStatusCodes();
    $fileBase = 'statistiques_taches_' . ($exportServiceId > 0 ? ('service_' . $exportServiceId) : 'entreprise') . '_' . $rangeStart->format('Ymd') . '-' . $rangeEnd->format('Ymd');

    if ($action === 'export_recap_xlsx') {
        require_once $projectRoot . '/vendor/autoload.php';
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $spreadsheet->getProperties()->setCreator('FINEA')->setTitle('Statistiques des tâches');
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Synthese');
        $sheet->fromArray([
            ['FINEA - Dashboard statistique des tâches'],
            ['Périmètre', $serviceLabel],
            ['Période', $rangeStart->format('d/m/Y') . ' - ' . $rangeEnd->format('d/m/Y')],
            [],
            ['Indicateur', 'Valeur'],
            ['Tâches planifiées', (int)$recapKpis['planned']],
            ['Tâches terminées validées', (int)$recapKpis['done']],
            ['Réalisation', $recapKpis['realization_rate'] !== null ? ((float)$recapKpis['realization_rate'] / 100) : null],
            ['Présences sans tâche', (int)$presenceStats['kpis']['gap_days']],
            ['Fréquence présence sans tâche', $presenceStats['kpis']['gap_rate'] !== null ? ((float)$presenceStats['kpis']['gap_rate'] / 100) : null],
        ], null, 'A1');
        $sheet->mergeCells('A1:B1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16)->getColor()->setRGB('0F172A');
        $sheet->getStyle('A5:B5')->getFont()->setBold(true);
        $sheet->getStyle('B8:B10')->getNumberFormat()->setFormatCode('0.0%');
        foreach (['A', 'B'] as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $byPerson = $spreadsheet->createSheet();
        $byPerson->setTitle('Frequence individu');
        $byPerson->fromArray(['Service', 'Personnel', 'Matricule', 'Jours présents', 'Jours sans tâche', 'Fréquence', 'Dates trous'], null, 'A1');
        $r = 2;
        foreach ($presenceStats['personnel'] as $stat) {
            $byPerson->fromArray([
                (string)$stat['service_label'],
                (string)$stat['label'],
                (string)$stat['matricule'],
                (int)$stat['present_days'],
                (int)$stat['gap_days'],
                ((float)$stat['gap_rate'] / 100),
                implode(', ', array_filter((array)$stat['dates'])),
            ], null, 'A' . $r);
            $r++;
        }

        $byService = $spreadsheet->createSheet();
        $byService->setTitle('Frequence service');
        $byService->fromArray(['Service', 'Jours présents', 'Jours sans tâche', 'Fréquence'], null, 'A1');
        $r = 2;
        foreach ($presenceStats['services'] as $stat) {
            $byService->fromArray([
                (string)$stat['label'],
                (int)$stat['present_days'],
                (int)$stat['gap_days'],
                ((float)$stat['gap_rate'] / 100),
            ], null, 'A' . $r);
            $r++;
        }

        $holes = $spreadsheet->createSheet();
        $holes->setTitle('Trous feuille temps');
        $holes->fromArray(['Date', 'Service', 'Personnel', 'Matricule', 'Statut présence', 'Entrée', 'Sortie', 'Tâches renseignées'], null, 'A1');
        $r = 2;
        foreach ($presenceStats['gap_rows'] as $row) {
            $holes->fromArray([
                (string)($row['attendance_date'] ?? ''),
                (string)($row['service_label'] ?? ''),
                (string)($row['personnel_name'] ?? ''),
                (string)($row['personnel_matricule'] ?? ''),
                (string)($row['attendance_status'] ?? ''),
                substr((string)($row['check_in_time'] ?? ''), 0, 5),
                substr((string)($row['check_out_time'] ?? ''), 0, 5),
                0,
            ], null, 'A' . $r);
            $r++;
        }

        $tasks = $spreadsheet->createSheet();
        $tasks->setTitle('Listing taches');
        $tasks->fromArray(['Service', 'Personnel', 'Matricule', 'Tâche', 'Début', 'Fin', 'Statut', 'Lieu', 'Description'], null, 'A1');
        $r = 2;
        foreach ($recapRows as $row) {
            $statusCode = (string)($row['status_code'] ?? '');
            $tasks->fromArray([
                (string)($row['service_label'] ?? ''),
                (string)($row['personnel_name'] ?? ''),
                (string)($row['personnel_matricule'] ?? ''),
                (string)($row['title'] ?? ''),
                (string)($row['start_at'] ?? ''),
                (string)($row['end_at'] ?? ''),
                (string)($statusLabelsForExport[$statusCode] ?? $statusCode),
                (string)($row['location'] ?? ''),
                (string)($row['description'] ?? ''),
            ], null, 'A' . $r);
            $r++;
        }

        foreach ($spreadsheet->getAllSheets() as $ws) {
            $highestColumn = $ws->getHighestColumn();
            $highestRow = max(1, $ws->getHighestRow());
            $ws->getStyle('A1:' . $highestColumn . '1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
            $ws->getStyle('A1:' . $highestColumn . '1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('0F172A');
            $ws->getStyle('A1:' . $highestColumn . $highestRow)->getAlignment()->setWrapText(true)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP);
            $ws->getStyle('A1:' . $highestColumn . $highestRow)->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)->getColor()->setRGB('CBD5E1');
            $ws->freezePane('A2');
            foreach (range('A', $highestColumn) as $col) {
                $ws->getColumnDimension($col)->setAutoSize(true);
            }
        }
        $byPerson->getStyle('F2:F' . max(2, $byPerson->getHighestRow()))->getNumberFormat()->setFormatCode('0.0%');
        $byService->getStyle('D2:D' . max(2, $byService->getHighestRow()))->getNumberFormat()->setFormatCode('0.0%');
        $spreadsheet->setActiveSheetIndex(0);

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $fileBase . '.xlsx"');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save('php://output');
        exit;
    }

    require_once $projectRoot . '/vendor/autoload.php';
    $pdf = new \TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('FINEA');
    $pdf->SetAuthor('FINEA');
    $pdf->SetTitle('Dashboard statistique des tâches');
    $pdf->SetMargins(8, 18, 8);
    $pdf->SetAutoPageBreak(true, 12);
    $pdf->AddPage();
    $pdf->SetFillColor(15, 23, 42);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('dejavusans', 'B', 15);
    $pdf->Cell(0, 10, 'FINEA - Dashboard statistique des tâches', 0, 1, 'L', true);
    $pdf->SetTextColor(71, 85, 105);
    $pdf->SetFont('dejavusans', '', 8.5);
    $pdf->MultiCell(0, 5, 'Périmètre : ' . $serviceLabel . ' | Période : ' . $rangeStart->format('d/m/Y') . ' - ' . $rangeEnd->format('d/m/Y') . ' | Export : ' . date('d/m/Y H:i'), 0, 'L');
    $pdf->Ln(2);
    $pdf->SetFillColor(241, 245, 249);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->SetFont('dejavusans', 'B', 8.5);
    $kpiCells = [
        'Tâches planifiées: ' . (int)$recapKpis['planned'],
        'Terminées validées: ' . (int)$recapKpis['done'],
        'Présences sans tâche: ' . (int)$presenceStats['kpis']['gap_days'],
        'Fréquence: ' . ($presenceStats['kpis']['gap_rate'] !== null ? number_format((float)$presenceStats['kpis']['gap_rate'], 1, ',', ' ') . '%' : 'n/d'),
    ];
    foreach ($kpiCells as $cell) {
        $pdf->MultiCell(68, 9, $cell, 1, 'C', true, 0, null, null, true, 0, false, true, 9, 'M');
    }
    $pdf->Ln(13);

    $drawTable = static function (\TCPDF $pdf, string $title, array $headers, array $rows, array $widths): void {
        $pdf->SetFont('dejavusans', 'B', 10);
        $pdf->SetTextColor(15, 23, 42);
        $pdf->Cell(0, 7, $title, 0, 1, 'L');
        $pdf->SetFont('dejavusans', 'B', 7);
        $pdf->SetFillColor(226, 232, 240);
        foreach ($headers as $i => $header) {
            $pdf->MultiCell($widths[$i], 8, $header, 1, 'C', true, 0, null, null, true, 0, false, true, 8, 'M');
        }
        $pdf->Ln();
        $pdf->SetFont('dejavusans', '', 6.7);
        foreach ($rows as $row) {
            $heights = [];
            foreach ($row as $i => $value) {
                $heights[] = $pdf->getStringHeight($widths[$i], (string)$value);
            }
            $height = max(7, min(22, max($heights) + 2));
            if ($pdf->GetY() + $height > $pdf->getPageHeight() - 12) {
                $pdf->AddPage();
            }
            foreach ($row as $i => $value) {
                $pdf->MultiCell($widths[$i], $height, (string)$value, 1, 'L', false, 0, null, null, true, 0, false, true, $height, 'M');
            }
            $pdf->Ln();
        }
        $pdf->Ln(3);
    };

    $serviceGapRows = [];
    foreach (array_slice($presenceStats['services'], 0, 30, true) as $stat) {
        $serviceGapRows[] = [(string)$stat['label'], (string)$stat['present_days'], (string)$stat['gap_days'], number_format((float)$stat['gap_rate'], 1, ',', ' ') . '%'];
    }
    $drawTable($pdf, 'Fréquence du comportement par service', ['Service', 'Jours présents', 'Sans tâche', 'Fréquence'], $serviceGapRows ?: [['-', '0', '0', '0%']], [120, 35, 35, 35]);

    $personRows = [];
    foreach (array_slice($presenceStats['personnel'], 0, 40, true) as $stat) {
        $personRows[] = [(string)$stat['service_label'], (string)$stat['label'], (string)$stat['matricule'], (string)$stat['present_days'], (string)$stat['gap_days'], number_format((float)$stat['gap_rate'], 1, ',', ' ') . '%', implode(', ', array_filter((array)$stat['dates']))];
    }
    $drawTable($pdf, 'Fréquence du comportement par individu', ['Service', 'Personnel', 'Mat.', 'Prés.', 'Sans tâche', '%', 'Jours concernés'], $personRows ?: [['-', '-', '-', '0', '0', '0%', '-']], [42, 50, 23, 15, 22, 16, 105]);

    $gapRows = [];
    foreach (array_slice($presenceStats['gap_rows'], 0, 80) as $row) {
        $gapRows[] = [(string)($row['attendance_date'] ?? ''), (string)($row['service_label'] ?? ''), (string)($row['personnel_name'] ?? ''), (string)($row['personnel_matricule'] ?? ''), substr((string)($row['check_in_time'] ?? ''), 0, 5), substr((string)($row['check_out_time'] ?? ''), 0, 5)];
    }
    $drawTable($pdf, 'Trous visibles sur feuille de temps', ['Date', 'Service', 'Personnel', 'Mat.', 'Entrée', 'Sortie'], $gapRows ?: [['-', '-', '-', '-', '-', '-']], [25, 58, 75, 30, 25, 25]);

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $fileBase . '.pdf"');
    echo $pdf->Output($fileBase . '.pdf', 'S');
    exit;
}

if (($action === 'export_recap' || $action === 'export_recap_csv') && $canAccessTaskStatsDashboard) {
    if (ob_get_level() > 0) {
        @ob_clean();
    }

    $exportServiceId = max(0, (int)($_GET['service_id'] ?? 0));
    $recapRows = edt_schedule_rows($pdo, $rangeStart, $rangeEnd, $exportServiceId);
    $recapKpis = edt_schedule_kpis($recapRows);
    $serviceLabel = 'Toute l entreprise';
    if ($exportServiceId > 0) {
        foreach ($serviceOptions as $serviceOption) {
            if ((int)($serviceOption['id'] ?? 0) === $exportServiceId) {
                $serviceLabel = (string)($serviceOption['label'] ?? $serviceLabel);
                break;
            }
        }
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="recap_taches_' . ($exportServiceId > 0 ? ('service_' . $exportServiceId) : 'entreprise') . '_' . $rangeStart->format('Ymd') . '-' . $rangeEnd->format('Ymd') . '.csv"');

    $out = fopen('php://output', 'w');
    if ($out !== false) {
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Récap global des tâches']);
        fputcsv($out, ['Périmètre', $serviceLabel]);
        fputcsv($out, ['Période', $rangeStart->format('d/m/Y') . ' -> ' . $rangeEnd->format('d/m/Y')]);
        fputcsv($out, ['Tâches planifiées', (int)$recapKpis['planned']]);
        fputcsv($out, ['Tâches effectuées', (int)$recapKpis['done']]);
        fputcsv($out, ['Pourcentage de réalisation', $recapKpis['realization_rate'] !== null ? number_format((float)$recapKpis['realization_rate'], 1, ',', ' ') . '%' : 'n/d']);
        fputcsv($out, ['Taux de retard', $recapKpis['delay_rate'] !== null ? number_format((float)$recapKpis['delay_rate'], 1, ',', ' ') . '%' : 'n/d']);
        fputcsv($out, []);
        fputcsv($out, ['Service', 'Collaborateur', 'Matricule', 'Tâche', 'Début', 'Fin', 'Statut', 'Lieu', 'Description']);
        $statusLabelsForExport = $schedule->allowedStatusCodes();
        foreach ($recapRows as $row) {
            $statusCode = (string)($row['status_code'] ?? '');
            fputcsv($out, [
                (string)($row['service_label'] ?? ''),
                (string)($row['personnel_name'] ?? ''),
                (string)($row['personnel_matricule'] ?? ''),
                (string)($row['title'] ?? ''),
                (string)($row['start_at'] ?? ''),
                (string)($row['end_at'] ?? ''),
                (string)($statusLabelsForExport[$statusCode] ?? $statusCode),
                (string)($row['location'] ?? ''),
                (string)($row['description'] ?? ''),
            ]);
        }
        fclose($out);
    }
    exit;
}

// Exports
if (($action === 'export' || $action === 'export_csv') && $personnelId > 0) {
    if (ob_get_level() > 0) {
        @ob_clean();
    }

    $events = $schedule->listEvents($personnelId, $rangeStart, $rangeEnd);
    $planningTasks = [];
    $personnel = $schedule->getPersonnelById($personnelId);
    $statusLabels = $schedule->allowedStatusCodes();

    $eventStatusCounts = [];
    $eventScopeCounts = [];
    foreach ($events as $row) {
        $statusLabel = $statusLabels[(string)($row['status_code'] ?? '')] ?? (string)($row['status_code'] ?? 'Non défini');
        $scopeLabel = trim((string)($row['group_scope_label'] ?? '')) !== '' ? (string)$row['group_scope_label'] : 'Individuelle';
        $eventStatusCounts[$statusLabel] = (int)($eventStatusCounts[$statusLabel] ?? 0) + 1;
        $eventScopeCounts[$scopeLabel] = (int)($eventScopeCounts[$scopeLabel] ?? 0) + 1;
    }
    ksort($eventStatusCounts);
    ksort($eventScopeCounts);

    $personLabel = trim((string)($personnel['nom'] ?? ''));
    $personMatricule = trim((string)($personnel['matricule'] ?? ''));
    if ($personLabel === '') {
        $personLabel = 'Personnel non défini';
    }
    if ($personMatricule !== '') {
        $personLabel .= ' (' . $personMatricule . ')';
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="emploi_du_temps_' . $period . '_' . $rangeStart->format('Ymd') . '-' . $rangeEnd->format('Ymd') . '.csv"');

    $out = fopen('php://output', 'w');
    if ($out !== false) {
        // BOM UTF-8 pour Excel
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Rapport', 'Emploi du temps Banamur']);
        fputcsv($out, ['Collaborateur', $personLabel]);
        fputcsv($out, ['Période', $rangeStart->format('d/m/Y') . ' -> ' . $rangeEnd->format('d/m/Y')]);
        fputcsv($out, ['Export généré le', date('d/m/Y H:i')]);
        fputcsv($out, ['Créneaux personnels', count($events)]);
        fputcsv($out, ['Tâches suivies externes', count($planningTasks)]);
        fputcsv($out, []);

        fputcsv($out, ['Répartition par statut']);
        foreach ($eventStatusCounts as $statusLabel => $count) {
            fputcsv($out, [$statusLabel, $count]);
        }
        fputcsv($out, []);

        fputcsv($out, ['Répartition par portée']);
        foreach ($eventScopeCounts as $scopeLabel => $count) {
            fputcsv($out, [$scopeLabel, $count]);
        }
        fputcsv($out, []);

        fputcsv($out, ['Détail des éléments']);
        fputcsv($out, ['Type', 'Titre', 'Début', 'Fin', 'Statut', 'Portée', 'Groupe', 'Lieu', 'Description', 'Avancement']);
        foreach ($events as $row) {
            $label = $statusLabels[(string)($row['status_code'] ?? '')] ?? (string)($row['status_code'] ?? '');
            fputcsv($out, [
                'Créneau personnel',
                (string)($row['title'] ?? ''),
                (string)($row['start_at'] ?? ''),
                (string)($row['end_at'] ?? ''),
                $label,
                (string)($row['group_scope_label'] ?? 'Individuelle'),
                (string)($row['event_group_key'] ?? ''),
                (string)($row['location'] ?? ''),
                (string)($row['description'] ?? ''),
                '',
            ]);
        }

        if ($events && $planningTasks) {
            fputcsv($out, []);
        }

        foreach ($planningTasks as $taskRow) {
            fputcsv($out, [
                'Tâche suivie',
                (string)($taskRow['task_name'] ?? ''),
                (string)($taskRow['start_date'] ?? ''),
                (string)($taskRow['end_date'] ?? ''),
                (string)($taskRow['status_code'] ?? ''),
                'Lecture seule',
                '',
                '',
                '',
                isset($taskRow['progress_percent']) && $taskRow['progress_percent'] !== null ? (string)$taskRow['progress_percent'] : '',
            ]);
        }
        fclose($out);
    }
    exit;
}

if ($action === 'export_pdf' && $personnelId > 0) {
    if (ob_get_level() > 0) {
        @ob_clean();
    }

    require_once $projectRoot . '/fpdf186/fpdf.php';

    $events = $schedule->listEvents($personnelId, $rangeStart, $rangeEnd);
    $planningTasks = [];
    $personnel = $schedule->getPersonnelById($personnelId);
    $statusLabels = $schedule->allowedStatusCodes();
    $groupedEventCount = 0;
    $eventStatusCounts = [];
    $eventScopeCounts = [];
    foreach ($events as $eventRow) {
        if (trim((string)($eventRow['event_group_key'] ?? '')) !== '') {
            $groupedEventCount++;
        }
        $statusLabel = $statusLabels[(string)($eventRow['status_code'] ?? '')] ?? (string)($eventRow['status_code'] ?? 'Non défini');
        $scopeLabel = trim((string)($eventRow['group_scope_label'] ?? '')) !== '' ? (string)$eventRow['group_scope_label'] : 'Individuelle';
        $eventStatusCounts[$statusLabel] = (int)($eventStatusCounts[$statusLabel] ?? 0) + 1;
        $eventScopeCounts[$scopeLabel] = (int)($eventScopeCounts[$scopeLabel] ?? 0) + 1;
    }
    ksort($eventStatusCounts);
    ksort($eventScopeCounts);

    $pdfText = static function (string $value): string {
        return mb_convert_encoding($value, 'ISO-8859-1', 'UTF-8');
    };

    $truncate = static function (\FPDF $pdf, string $text, float $maxWidth) use ($pdfText): string {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        if ($pdf->GetStringWidth($pdfText($text)) <= $maxWidth) {
            return $text;
        }
        $ellipsis = '...';
        $maxWidth = max(1, $maxWidth - $pdf->GetStringWidth($pdfText($ellipsis)));
        $result = '';
        $len = mb_strlen($text);
        for ($i = 0; $i < $len; $i++) {
            $candidate = $result . mb_substr($text, $i, 1);
            if ($pdf->GetStringWidth($pdfText($candidate)) > $maxWidth) {
                break;
            }
            $result = $candidate;
        }
        return rtrim($result) . $ellipsis;
    };

    $docTitle = 'Emploi du temps';
    $who = trim((string)($personnel['nom'] ?? ''));
    $mat = trim((string)($personnel['matricule'] ?? ''));
    require_once __DIR__ . '/../model/CompanyBranding.php';
    $docLogoPath = CompanyBranding::logoFile();
    if ($who !== '') {
        $docTitle .= ' — ' . $who . ($mat !== '' ? ' (' . $mat . ')' : '');
    }
    $docSubtitle = sprintf('Période : %s → %s', $rangeStart->format('d/m/Y'), $rangeEnd->format('d/m/Y'));
    $planningLateCount = 0;
    $cancelledCount = 0;
    $todayRef = new DateTimeImmutable('today');
    foreach ($events as $eventRow) {
        if ((string)($eventRow['status_code'] ?? '') === 'annule') {
            $cancelledCount++;
        }
    }
    foreach ($planningTasks as $taskRow) {
        $endDate = trim((string)($taskRow['end_date'] ?? ''));
        $progressPercent = isset($taskRow['progress_percent']) ? (float)$taskRow['progress_percent'] : 0.0;
        if ($endDate === '') {
            continue;
        }
        try {
            if (new DateTimeImmutable($endDate) < $todayRef && $progressPercent < 100) {
                $planningLateCount++;
            }
        } catch (Throwable $e) {
            // ignore invalid export data
        }
    }
    $anomalyCount = $cancelledCount + $planningLateCount;
    $readinessRate = count($events) > 0 ? round((($groupedEventCount + max(0, count($events) - $cancelledCount)) / max(count($events), 1)) * 100) : null;

    $pdf = new class('L', 'mm', 'A4') extends \FPDF {
        public string $docTitle = '';
        public string $docSubtitle = '';
        public string $docLogoPath = '';
        public function Header(): void
        {
            $w = $this->GetPageWidth();
            $this->SetFillColor(15, 23, 42);
            $this->Rect(0, 0, $w, 24, 'F');
            $this->SetFillColor(245, 158, 11);
            $this->Rect(0, 24, $w, 2, 'F');
            $titleX = 10;
            if ($this->docLogoPath !== '' && is_file($this->docLogoPath)) {
                $this->Image($this->docLogoPath, 10, 5, 26);
                $titleX = 42;
            }
            $this->SetFont('Arial', 'B', 14);
            $this->SetTextColor(255, 255, 255);
            $this->SetXY($titleX, 6);
            $this->Cell(0, 6, mb_convert_encoding($this->docTitle, 'ISO-8859-1', 'UTF-8'), 0, 0, 'L');
            $this->SetFont('Arial', '', 9);
            $this->SetXY($titleX, 14);
            $this->Cell(0, 5, mb_convert_encoding($this->docSubtitle, 'ISO-8859-1', 'UTF-8'), 0, 0, 'L');
            $this->SetFont('Arial', '', 9);
            $this->SetXY($w - 96, 7);
            $this->Cell(86, 5, mb_convert_encoding('Export : ' . date('d/m/Y H:i'), 'ISO-8859-1', 'UTF-8'), 0, 0, 'R');
            $this->SetXY($w - 96, 14);
            $this->Cell(86, 5, mb_convert_encoding('Banamur • Signature Finéa', 'ISO-8859-1', 'UTF-8'), 0, 0, 'R');
            $this->Ln(22);
            $this->SetTextColor(17, 24, 39);
        }
        public function Footer(): void
        {
            $this->SetY(-12);
            $this->SetFont('Arial', '', 8);
            $this->SetTextColor(100, 116, 139);
            $this->Cell(90, 6, mb_convert_encoding('Document de travail Banamur', 'ISO-8859-1', 'UTF-8'), 0, 0, 'L');
            $this->Cell(0, 6, mb_convert_encoding('Page ' . $this->PageNo() . ' / {nb}', 'ISO-8859-1', 'UTF-8'), 0, 0, 'R');
        }
    };

    $pdf->AliasNbPages();
    $pdf->docTitle = $docTitle;
    $pdf->docSubtitle = $docSubtitle;
    $pdf->docLogoPath = $docLogoPath;
    $pdf->AddPage();

    $pdf->SetDrawColor(226, 232, 240);
    $pdf->SetFillColor(247, 250, 252);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->SetFont('Arial', 'B', 20);
    $pdf->Cell(0, 12, $pdfText('Rapport de pilotage emploi du temps'), 0, 1, 'L');
    $pdf->SetFont('Arial', '', 10);
    $pdf->SetTextColor(71, 85, 105);
    $pdf->MultiCell(0, 6, $pdfText('Document de partage direction pour lecture rapide des tâches, des regroupements et des points d\'attention observés sur la période.'), 0, 'L');
    $pdf->Ln(4);

    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetFillColor(248, 250, 252);
    $summary = [
        ['label' => 'Collaborateur', 'value' => $who !== '' ? $who : 'Non défini'],
        ['label' => 'Créneaux', 'value' => (string)count($events)],
        ['label' => 'Tâches effectuées', 'value' => (string)count(array_filter($events, static fn(array $row): bool => (string)($row['status_code'] ?? '') === 'termine'))],
        ['label' => 'Anomalies', 'value' => (string)$anomalyCount],
    ];
    foreach ($summary as $index => $summaryItem) {
        $cellWidth = $index === 0 ? 90 : 58;
        $pdf->Cell($cellWidth, 10, $pdfText($summaryItem['label'] . ' : ' . $summaryItem['value']), 1, 0, 'L', true);
    }
    $pdf->Ln(15);

    $pdf->SetFont('Arial', 'B', 11);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell(0, 7, $pdfText('Sommaire exécutif'), 0, 1, 'L');
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetTextColor(71, 85, 105);
    $pdf->MultiCell(0, 6, $pdfText('1. Vue direction : synthèse, indicateurs et anomalies.  2. Répartition par statut et par portée.  3. Détail des tâches et créneaux personnels.'), 1, 'L', true);
    $pdf->Ln(4);

    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell(0, 7, $pdfText('Anomalies utiles à la direction'), 0, 1, 'L');
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetFillColor(255, 251, 235);
    $pdf->SetTextColor(120, 53, 15);
    $pdf->MultiCell(0, 6, $pdfText(sprintf('Créneaux annulés : %d • Tâches en retard : %d • Taux de réalisation estimé : %s', $cancelledCount, $planningLateCount, $readinessRate !== null ? $readinessRate . '%' : 'n/d')), 1, 'L', true);
    $pdf->Ln(5);

    $pdf->SetFillColor(248, 250, 252);
    $pdf->SetTextColor(71, 85, 105);
    $pdf->MultiCell(0, 6, $pdfText('Lecture du document : les tâches apparaissent avec leur portée individuelle ou groupée pour une vue consolidée.'), 1, 'L', true);

    $pdf->AddPage();

    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell(0, 7, $pdfText('Synthèse direction'), 0, 1, 'L');
    $pdf->Ln(1);

    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetFillColor(241, 245, 249);
    $pdf->Cell(70, 7, $pdfText('Répartition par statut'), 1, 0, 'C', true);
    $pdf->Cell(25, 7, $pdfText('Volume'), 1, 0, 'C', true);
    $pdf->Cell(12, 7, '', 0, 0);
    $pdf->Cell(85, 7, $pdfText('Répartition par portée'), 1, 0, 'C', true);
    $pdf->Cell(25, 7, $pdfText('Volume'), 1, 1, 'C', true);

    $pdf->SetFont('Arial', '', 9);
    $leftRows = [];
    foreach ($eventStatusCounts as $label => $count) {
        $leftRows[] = [$label, (string)$count];
    }
    if ($leftRows === []) {
        $leftRows[] = ['Aucun statut', '0'];
    }
    $rightRows = [];
    foreach ($eventScopeCounts as $label => $count) {
        $rightRows[] = [$label, (string)$count];
    }
    if ($rightRows === []) {
        $rightRows[] = ['Aucune portée', '0'];
    }
    $maxRows = max(count($leftRows), count($rightRows));
    for ($i = 0; $i < $maxRows; $i++) {
        $left = $leftRows[$i] ?? ['', ''];
        $right = $rightRows[$i] ?? ['', ''];
        $pdf->Cell(70, 7, $pdfText((string)$left[0]), 1, 0, 'L');
        $pdf->Cell(25, 7, $pdfText((string)$left[1]), 1, 0, 'R');
        $pdf->Cell(12, 7, '', 0, 0);
        $pdf->Cell(85, 7, $pdfText((string)$right[0]), 1, 0, 'L');
        $pdf->Cell(25, 7, $pdfText((string)$right[1]), 1, 1, 'R');
    }
    $pdf->Ln(4);

    // Section : Créneaux personnels
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell(0, 7, $pdfText('Créneaux personnels'), 0, 1, 'L');
    $pdf->Ln(1);

    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetFillColor(241, 245, 249);
    $pdf->SetTextColor(15, 23, 42);
    $w1 = [52, 28, 28, 22, 34, 26, 70];
    $h1 = ['Titre', 'Début', 'Fin', 'Statut', 'Portée', 'Lieu', 'Description'];
    foreach ($h1 as $i => $t) {
        $pdf->Cell($w1[$i], 7, $pdfText($t), 1, 0, 'C', true);
    }
    $pdf->Ln();
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetTextColor(30, 41, 59);
    $fill = false;
    if (empty($events)) {
        $pdf->Cell(array_sum($w1), 7, $pdfText('Aucun créneau.'), 1, 1, 'L', false);
    } else {
        foreach ($events as $ev) {
            $pdf->SetFillColor($fill ? 248 : 255, $fill ? 250 : 255, $fill ? 252 : 255);
            $title = (string)($ev['title'] ?? '');
            $start = (string)($ev['start_at'] ?? '');
            $end = (string)($ev['end_at'] ?? '');
            $code = (string)($ev['status_code'] ?? '');
            $label = (string)($statusLabels[$code] ?? ($code !== '' ? $code : '-'));
            $scopeLabel = trim((string)($ev['group_scope_label'] ?? '')) !== '' ? (string)$ev['group_scope_label'] : 'Individuelle';
            $loc = (string)($ev['location'] ?? '');
            $desc = (string)($ev['description'] ?? '');

            $texts = [$title, fmtHumanDate($start, true), fmtHumanDate($end, true), $label, $scopeLabel, $loc, $desc];
            $maxLines = 1;
            for ($i = 0; $i < count($texts); $i++) {
                $textWidth = $pdf->GetStringWidth($pdfText($texts[$i]));
                $cellWidth = $w1[$i];
                $lines = $textWidth > 0 ? ceil($textWidth / $cellWidth) : 1;
                $maxLines = max($maxLines, $lines);
            }
            $height = min($maxLines * 5, 50); // Limit max height to 50mm to avoid excessive pages

            $currentX = $pdf->GetX();
            $currentY = $pdf->GetY();
            $pageHeight = $pdf->GetPageHeight();
            $margin = 20; // bottom margin
            if ($currentY + $height > $pageHeight - $margin) {
                $pdf->AddPage();
                $currentY = $pdf->GetY();
            }

            // Draw all borders first
            for ($i = 0; $i < count($w1); $i++) {
                $pdf->SetXY($currentX + array_sum(array_slice($w1, 0, $i)), $currentY);
                $pdf->Cell($w1[$i], $height, '', 1, 0, 'L', $fill);
            }

            // Draw text with MultiCell
            $texts[1] = $truncate($pdf, $texts[1], $w1[1] - 2); // keep truncation for short columns
            $texts[2] = $truncate($pdf, $texts[2], $w1[2] - 2);
            $texts[3] = $truncate($pdf, $texts[3], $w1[3] - 2);
            $texts[4] = $truncate($pdf, $texts[4], $w1[4] - 2);
            $texts[5] = $truncate($pdf, $texts[5], $w1[5] - 2);

            for ($i = 0; $i < count($texts); $i++) {
                $pdf->SetXY($currentX + array_sum(array_slice($w1, 0, $i)), $currentY);
                $pdf->MultiCell($w1[$i], 5, $pdfText($texts[$i]), 0, 'L', $fill);
            }

            $pdf->SetY($currentY + $height);
            $fill = !$fill;
        }
    }

    // Section : Planning (lecture seule)
    $pdf->Ln(4);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell(0, 7, $pdfText('Tâches suivies'), 0, 1, 'L');
    $pdf->Ln(1);

    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetFillColor(241, 245, 249);
    $pdf->SetTextColor(15, 23, 42);
    $w2 = [120, 35, 35, 30, 30];
    $h2 = ['Tâche', 'Début', 'Fin', 'Statut', 'Avancement'];
    foreach ($h2 as $i => $t) {
        $pdf->Cell($w2[$i], 7, $pdfText($t), 1, 0, 'C', true);
    }
    $pdf->Ln();
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetTextColor(30, 41, 59);
    $fill = false;
    if (empty($planningTasks)) {
        $pdf->Cell(array_sum($w2), 7, $pdfText('Aucune tâche planning sur la période.'), 1, 1, 'L', false);
    } else {
        foreach ($planningTasks as $t) {
            $pdf->SetFillColor($fill ? 248 : 255, $fill ? 250 : 255, $fill ? 252 : 255);
            $name = (string)($t['task_name'] ?? '');
            $sd = (string)($t['start_date'] ?? '');
            $ed = (string)($t['end_date'] ?? '');
            $st = (string)($t['status_code'] ?? '');
            $prog = isset($t['progress_percent']) && $t['progress_percent'] !== null ? (float)$t['progress_percent'] : null;
            $pdf->Cell($w2[0], 7, $pdfText($truncate($pdf, $name, $w2[0] - 2)), 1, 0, 'L', $fill);
            $pdf->Cell($w2[1], 7, $pdfText($truncate($pdf, fmtHumanDate($sd, false), $w2[1] - 2)), 1, 0, 'L', $fill);
            $pdf->Cell($w2[2], 7, $pdfText($truncate($pdf, fmtHumanDate($ed, false), $w2[2] - 2)), 1, 0, 'L', $fill);
            $pdf->Cell($w2[3], 7, $pdfText($truncate($pdf, $st !== '' ? $st : '-', $w2[3] - 2)), 1, 0, 'L', $fill);
            $pdf->Cell($w2[4], 7, $pdfText($prog !== null ? (number_format($prog, 1) . '%') : '-'), 1, 1, 'R', $fill);
            $fill = !$fill;
        }
    }

    $filename = 'emploi_du_temps_' . $period . '_' . $rangeStart->format('Ymd') . '-' . $rangeEnd->format('Ymd') . '_' . date('Ymd_His') . '.pdf';
    $pdf->Output('D', $filename);
    exit;
}

// POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = (string)($_POST['action'] ?? '');

    if ($postAction === 'clear_personnel') {
        $auditScheduleAction(
            'employee_schedule_cleared',
            'clear_personnel',
            'success',
            $personnelId > 0 ? (string)$personnelId : null,
            'Reinitialisation personnel emploi du temps',
            [
                'previous_personnel_id' => $personnelId > 0 ? $personnelId : null,
            ]
        );
        unset($_SESSION[$sessionPersonnelIdKey], $_SESSION[$sessionMatriculeKey]);
        $redirect('emploi_du_temps.php');
    }

    if ($postAction === 'select_personnel') {
        $selectedPersonnelId = (int)($_POST['personnel_id'] ?? 0);
        $matricule = trim((string)($_POST['matricule'] ?? ''));
        try {
            $person = null;
            if ($selectedPersonnelId > 0) {
                $person = $schedule->getPersonnelById($selectedPersonnelId);
            }
            if (!$person && $matricule !== '') {
                $person = $schedule->resolvePersonnelByMatricule($matricule);
            }
            if (!$person) {
                $auditScheduleAction(
                    'employee_schedule_selection_failed',
                    'select_personnel',
                    'failed',
                    null,
                    'Selection personnel emploi du temps',
                    [
                        'personnel_id' => $selectedPersonnelId > 0 ? $selectedPersonnelId : null,
                        'matricule' => $matricule,
                        'reason' => 'personnel_not_found',
                    ],
                    'warning'
                );
                $flashError = 'Personnel introuvable.';
            } else {
                $personnelId = (int)$person['id'];
                $_SESSION[$sessionPersonnelIdKey] = $personnelId;
                $_SESSION[$sessionMatriculeKey] = (string)($person['matricule'] ?? '');
                $auditScheduleAction(
                    'employee_schedule_personnel_selected',
                    'select_personnel',
                    'success',
                    (string)$personnelId,
                    (string)($person['nom'] ?? $person['matricule'] ?? 'Personnel'),
                    [
                        'matricule' => (string)($person['matricule'] ?? ''),
                    ]
                );
                $redirect('emploi_du_temps.php');
            }
        } catch (Throwable $e) {
            $auditScheduleAction(
                'employee_schedule_selection_failed',
                'select_personnel',
                'failed',
                null,
                'Selection personnel emploi du temps',
                [
                    'matricule' => $matricule,
                    'error_message' => $e->getMessage(),
                ],
                'warning'
            );
            $flashError = 'Erreur lors de la sélection du personnel.';
        }
    }

    if ($personnelId > 0 && $postAction === 'create_event') {
        try {
            $result = $schedule->createEvent(
                $personnelId,
                (string)($_POST['title'] ?? ''),
                (string)($_POST['start_at'] ?? ''),
                (string)($_POST['end_at'] ?? ''),
                (string)($_POST['status_code'] ?? ''),
                isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null
            );
            if (empty($result['ok'])) {
                $auditScheduleAction(
                    'employee_schedule_event_create_failed',
                    'create_event',
                    'failed',
                    (string)$personnelId,
                    (string)($_POST['title'] ?? 'Creation creneau'),
                    [
                        'personnel_id' => $personnelId,
                        'start_at' => (string)($_POST['start_at'] ?? ''),
                        'end_at' => (string)($_POST['end_at'] ?? ''),
                        'status_code' => (string)($_POST['status_code'] ?? ''),
                        'error_message' => $result['error'] ?? null,
                    ],
                    'warning'
                );
                $flashError = (string)($result['error'] ?? 'Création impossible.');
            } else {
                $auditScheduleAction(
                    'employee_schedule_event_created',
                    'create_event',
                    'success',
                    isset($result['id']) ? (string)$result['id'] : (string)$personnelId,
                    (string)($_POST['title'] ?? 'Creation creneau'),
                    [
                        'personnel_id' => $personnelId,
                        'start_at' => (string)($_POST['start_at'] ?? ''),
                        'end_at' => (string)($_POST['end_at'] ?? ''),
                        'status_code' => (string)($_POST['status_code'] ?? ''),
                    ]
                );
                $flashSuccess = 'Créneau ajouté.';
            }
        } catch (Throwable $e) {
            $auditScheduleAction(
                'employee_schedule_event_create_failed',
                'create_event',
                'failed',
                (string)$personnelId,
                (string)($_POST['title'] ?? 'Creation creneau'),
                [
                    'personnel_id' => $personnelId,
                    'error_message' => $e->getMessage(),
                ],
                'warning'
            );
            $flashError = 'Erreur lors de la création du créneau.';
        }
    }

    if ($personnelId > 0 && $postAction === 'update_event') {
        $eventId = (int)($_POST['event_id'] ?? 0);
        try {
            $result = $schedule->updateEvent(
                $eventId,
                $personnelId,
                (string)($_POST['title'] ?? ''),
                (string)($_POST['start_at'] ?? ''),
                (string)($_POST['end_at'] ?? ''),
                (string)($_POST['status_code'] ?? '')
            );
            if (empty($result['ok'])) {
                $auditScheduleAction(
                    'employee_schedule_event_update_failed',
                    'update_event',
                    'failed',
                    $eventId > 0 ? (string)$eventId : null,
                    (string)($_POST['title'] ?? 'Mise a jour creneau'),
                    [
                        'personnel_id' => $personnelId,
                        'event_id' => $eventId,
                        'status_code' => (string)($_POST['status_code'] ?? ''),
                        'error_message' => $result['error'] ?? null,
                    ],
                    'warning'
                );
                $flashError = (string)($result['error'] ?? 'Mise à jour impossible.');
            } else {
                $auditScheduleAction(
                    'employee_schedule_event_updated',
                    'update_event',
                    'success',
                    $eventId > 0 ? (string)$eventId : null,
                    (string)($_POST['title'] ?? 'Mise a jour creneau'),
                    [
                        'personnel_id' => $personnelId,
                        'event_id' => $eventId,
                        'status_code' => (string)($_POST['status_code'] ?? ''),
                    ]
                );
                $flashSuccess = 'Créneau mis à jour.';
            }
        } catch (Throwable $e) {
            $auditScheduleAction(
                'employee_schedule_event_update_failed',
                'update_event',
                'failed',
                $eventId > 0 ? (string)$eventId : null,
                (string)($_POST['title'] ?? 'Mise a jour creneau'),
                [
                    'personnel_id' => $personnelId,
                    'event_id' => $eventId,
                    'error_message' => $e->getMessage(),
                ],
                'warning'
            );
            $flashError = 'Erreur lors de la mise à jour du créneau.';
        }
    }

    if ($personnelId > 0 && $postAction === 'delete_event') {
        $eventId = (int)($_POST['event_id'] ?? 0);
        try {
            if ($schedule->deleteEvent($eventId, $personnelId)) {
                $auditScheduleAction(
                    'employee_schedule_event_deleted',
                    'delete_event',
                    'success',
                    $eventId > 0 ? (string)$eventId : null,
                    'Suppression creneau',
                    [
                        'personnel_id' => $personnelId,
                        'event_id' => $eventId,
                    ]
                );
                $flashSuccess = 'Créneau supprimé.';
            } else {
                $auditScheduleAction(
                    'employee_schedule_event_delete_failed',
                    'delete_event',
                    'failed',
                    $eventId > 0 ? (string)$eventId : null,
                    'Suppression creneau',
                    [
                        'personnel_id' => $personnelId,
                        'event_id' => $eventId,
                        'reason' => 'delete_returned_false',
                    ],
                    'warning'
                );
                $flashError = 'Suppression impossible.';
            }
        } catch (Throwable $e) {
            $auditScheduleAction(
                'employee_schedule_event_delete_failed',
                'delete_event',
                'failed',
                $eventId > 0 ? (string)$eventId : null,
                'Suppression creneau',
                [
                    'personnel_id' => $personnelId,
                    'event_id' => $eventId,
                    'error_message' => $e->getMessage(),
                ],
                'warning'
            );
            $flashError = 'Erreur lors de la suppression du créneau.';
        }
    }
}

$personnel = null;
if ($personnelId > 0) {
    try {
        $personnel = $schedule->getPersonnelById($personnelId);
        if ($personnel) {
            $_SESSION[$sessionMatriculeKey] = (string)($personnel['matricule'] ?? ($_SESSION[$sessionMatriculeKey] ?? ''));
        }
    } catch (Throwable $e) {
        $personnel = null;
    }
}

$selectedServiceId = (int)($personnel['service_id'] ?? 0);
$selectedServiceLabel = trim((string)($personnel['service_label'] ?? ''));
if ($personnel) {
    $selectedPersonnelDisplay = trim((string)($personnel['nom'] ?? ''));
    if (trim((string)($personnel['matricule'] ?? '')) !== '') {
        $selectedPersonnelDisplay .= ' - ' . trim((string)($personnel['matricule'] ?? ''));
    }
    if ($selectedServiceLabel !== '') {
        $selectedPersonnelDisplay .= ' - ' . $selectedServiceLabel;
    }
}
$canAccessScheduleSettingsPage = $isAdmin || (function_exists('hasPermission') ? hasPermission('acces_rh') : false);
$scheduleSettingsUrl = $rootUrl('rh/parametrage_horaires_emploi_du_temps.php');
if ($personnelId > 0 || $selectedServiceId > 0) {
    $scheduleSettingsUrl .= '?' . http_build_query(array_filter([
        'personnel_id' => $personnelId > 0 ? $personnelId : null,
        'service_id' => $selectedServiceId > 0 ? $selectedServiceId : null,
    ], static fn($value) => $value !== null));
}

$effectiveWorkWindows = $personnelId > 0 ? $schedule->getPersonnelWorkWindows($personnelId) : $schedule->getDefaultWorkWindows();
$dailyLoadThresholdHours = $personnelId > 0
    ? $schedule->getPersonnelDailyLoadThresholdHours($personnelId)
    : $schedule->getDefaultDailyLoadThresholdHours();

$events = [];
$planningTasks = [];
$quickTasks = [];
$statusLabels = $schedule->allowedStatusCodes();

// Microsoft To Do : lecture des tâches (si connecté)
$msConnected = false;
$msTodoTasks = [];
$msTodoError = null;

if ($msConfigured && $msUserId > 0 && $msTodo->isReady()) {
    try {
        $tokenRow = $msTodo->getTokenRow($msUserId);
        if ($tokenRow && !empty($tokenRow['access_token'])) {
            $msConnected = true;
            $accessToken = (string)$tokenRow['access_token'];

            $expiresAt = (string)($tokenRow['expires_at'] ?? '');
            $refreshToken = (string)($tokenRow['refresh_token'] ?? '');
            if ($accessToken !== '' && $msTodo->isExpired($expiresAt) && $refreshToken !== '') {
                $refresh = $msTodo->refreshAccessToken($msTenantId, $msClientId, $msClientSecret, $msRedirectUri, $refreshToken);
                if (!empty($refresh['ok']) && !empty($refresh['token']) && is_array($refresh['token'])) {
                    $msTodo->saveTokenRow($msUserId, $refresh['token']);
                    $accessToken = (string)($refresh['token']['access_token'] ?? $accessToken);
                } else {
                    $msTodoError = (string)($refresh['error'] ?? 'Refresh Microsoft impossible.');
                }
            }

            if (!$msTodoError) {
                $tasksRes = $msTodo->listTasks($accessToken, 60);
                if (!empty($tasksRes['ok'])) {
                    $msTodoTasks = $tasksRes['items'] ?? [];
                    if (!is_array($msTodoTasks)) {
                        $msTodoTasks = [];
                    }
                } else {
                    $msTodoError = (string)($tasksRes['error'] ?? 'Lecture Microsoft To Do impossible.');
                }
            }
        }
    } catch (Throwable $e) {
        $msTodoError = 'Erreur Microsoft To Do.';
    }
}

if ($personnelId > 0) {
    try {
        $events = $schedule->listEvents($personnelId, $rangeStart, $rangeEnd);
    } catch (Throwable $e) {
        $events = [];
    }

    $planningTasks = [];

    try {
        $quickTasks = $schedule->listQuickTasks($personnelId, 40);
    } catch (Throwable $e) {
        $quickTasks = [];
    }
}

$badgeClasses = [
    'planifie' => 'bg-slate-100 text-slate-700',
    'en_cours' => 'bg-amber-100 text-amber-800',
    'pause' => 'bg-indigo-100 text-indigo-800',
    'validation_requise' => 'bg-amber-100 text-amber-900',
    'termine' => 'bg-emerald-100 text-emerald-800',
    'annule' => 'bg-rose-100 text-rose-800',
];

$recapServiceId = max(0, (int)($_GET['recap_service_id'] ?? 0));
$recapServiceLabel = 'Toute l entreprise';
if ($recapServiceId > 0) {
    foreach ($serviceOptions as $serviceOption) {
        if ((int)($serviceOption['id'] ?? 0) === $recapServiceId) {
            $recapServiceLabel = (string)($serviceOption['label'] ?? $recapServiceLabel);
            break;
        }
    }
}
$globalScheduleRows = [];
$globalScheduleKpis = edt_schedule_kpis([]);
$globalServiceStats = [];
$globalPersonnelStats = [];
$globalPresenceRows = [];
$globalPresenceGapStats = edt_presence_task_gap_stats([]);
$pendingCompletionValidations = [];
if ($canAccessTaskStatsDashboard) {
    try {
        $globalScheduleRows = edt_schedule_rows($pdo, $rangeStart, $rangeEnd, $recapServiceId);
        $globalScheduleKpis = edt_schedule_kpis($globalScheduleRows);
        $globalServiceStats = edt_group_schedule_stats($globalScheduleRows, 'service_id', 'service_label');
        $globalPersonnelStats = edt_group_schedule_stats($globalScheduleRows, 'personnel_id', 'personnel_name');
        $globalPresenceRows = edt_presence_task_rows($pdo, $rangeStart, $rangeEnd, $recapServiceId);
        $globalPresenceGapStats = edt_presence_task_gap_stats($globalPresenceRows);
        $pendingCompletionValidations = $schedule->listPendingCompletionValidations((int)($_SESSION['user_id'] ?? 0), $rangeStart, $rangeEnd, $recapServiceId);
    } catch (Throwable $e) {
        $globalScheduleRows = [];
        $globalScheduleKpis = edt_schedule_kpis([]);
        $globalServiceStats = [];
        $globalPersonnelStats = [];
        $globalPresenceRows = [];
        $globalPresenceGapStats = edt_presence_task_gap_stats([]);
        $pendingCompletionValidations = [];
    }
}

// KPIs
$kpiTotalSlots = count($events);
$kpiHoursPlanned = 0.0;
$kpiSlotsDone = 0;
$kpiSlotsInProgress = 0;
$kpiSlotsCancelled = 0;
foreach ($events as $ev) {
    $code = (string)($ev['status_code'] ?? 'planifie');
    if ($code === 'termine') {
        $kpiSlotsDone++;
    } elseif ($code === 'en_cours' || $code === 'pause') {
        $kpiSlotsInProgress++;
    } elseif ($code === 'annule') {
        $kpiSlotsCancelled++;
    }

    $startAt = (string)($ev['start_at'] ?? '');
    $endAt = (string)($ev['end_at'] ?? '');
    if ($startAt && $endAt) {
        try {
            $s = new DateTimeImmutable($startAt);
            $e = new DateTimeImmutable($endAt);
            $delta = $e->getTimestamp() - $s->getTimestamp();
            if ($delta > 0) {
                $kpiHoursPlanned += $delta / 3600;
            }
        } catch (Throwable $e) {
            // ignore
        }
    }
}

$kpiPlanningCount = count($planningTasks);
$kpiPlanningAvgProgress = null;
if ($kpiPlanningCount > 0) {
    $sum = 0.0;
    $cnt = 0;
    foreach ($planningTasks as $t) {
        if (isset($t['progress_percent']) && $t['progress_percent'] !== null && $t['progress_percent'] !== '') {
            $sum += (float)$t['progress_percent'];
            $cnt++;
        }
    }
    if ($cnt > 0) {
        $kpiPlanningAvgProgress = $sum / $cnt;
    }
}

$eventRiskMap = [];
$eventBucketsByDate = [];
$dailyHoursByDate = [];

foreach ($events as $eventRow) {
    $eventId = (string)($eventRow['id'] ?? '');
    $startAt = trim((string)($eventRow['start_at'] ?? ''));
    $endAt = trim((string)($eventRow['end_at'] ?? ''));
    $statusCode = (string)($eventRow['status_code'] ?? 'planifie');

    if ($eventId === '' || $startAt === '' || $endAt === '') {
        continue;
    }

    try {
        $startDt = new DateTimeImmutable($startAt);
        $endDt = new DateTimeImmutable($endAt);
    } catch (Throwable $e) {
        continue;
    }

    if ($endDt <= $startDt) {
        continue;
    }

    $dateKey = $startDt->format('Y-m-d');
    $eventBucketsByDate[$dateKey][] = [
        'id' => $eventId,
        'title' => (string)($eventRow['title'] ?? ''),
        'start' => $startDt,
        'end' => $endDt,
        'status_code' => $statusCode,
    ];

    if ($statusCode !== 'annule') {
        $dailyHoursByDate[$dateKey] = (float)($dailyHoursByDate[$dateKey] ?? 0.0) + (($endDt->getTimestamp() - $startDt->getTimestamp()) / 3600);
    }
}

foreach ($eventBucketsByDate as $dateKey => $dayEvents) {
    usort($dayEvents, static function (array $left, array $right): int {
        return $left['start']->getTimestamp() <=> $right['start']->getTimestamp();
    });

    $lastOpenEventId = null;
    $lastOpenEndTs = null;
    foreach ($dayEvents as $dayEvent) {
        $currentStartTs = $dayEvent['start']->getTimestamp();
        $currentEndTs = $dayEvent['end']->getTimestamp();
        $currentEventId = (string)$dayEvent['id'];

        if ($lastOpenEndTs !== null && $currentStartTs < $lastOpenEndTs) {
            $eventRiskMap[$currentEventId]['conflict'] = true;
            if ($lastOpenEventId !== null) {
                $eventRiskMap[$lastOpenEventId]['conflict'] = true;
            }
        }

        if ($lastOpenEndTs === null || $currentEndTs > $lastOpenEndTs) {
            $lastOpenEndTs = $currentEndTs;
            $lastOpenEventId = $currentEventId;
        }
    }

    if ((float)($dailyHoursByDate[$dateKey] ?? 0.0) > $dailyLoadThresholdHours) {
        foreach ($dayEvents as $dayEvent) {
            $eventRiskMap[(string)$dayEvent['id']]['overload'] = true;
        }
    }
}

$kpiConflictSlotCount = 0;
$overloadedDateKeys = [];
foreach ($eventRiskMap as $riskEntry) {
    if (!empty($riskEntry['conflict'])) {
        $kpiConflictSlotCount++;
    }
}
foreach ($dailyHoursByDate as $dateKey => $hours) {
    if ($hours > $dailyLoadThresholdHours) {
        $overloadedDateKeys[] = $dateKey;
    }
}
$kpiOverloadDayCount = count($overloadedDateKeys);

$kpiGroupedSlots = 0;
$groupedKeys = [];
foreach ($events as $ev) {
    $groupKey = trim((string)($ev['event_group_key'] ?? ''));
    if ($groupKey !== '') {
        $kpiGroupedSlots++;
        $groupedKeys[$groupKey] = true;
    }
}
$kpiGroupedSeries = count($groupedKeys);

$kpiPlanningLateCount = 0;
$todayDate = new DateTimeImmutable('today');
foreach ($planningTasks as $taskRow) {
    $endDate = trim((string)($taskRow['end_date'] ?? ''));
    $progressPercent = isset($taskRow['progress_percent']) ? (float)$taskRow['progress_percent'] : 0.0;
    if ($endDate === '') {
        continue;
    }

    try {
        $taskEndDate = new DateTimeImmutable($endDate);
        if ($taskEndDate < $todayDate && $progressPercent < 100) {
            $kpiPlanningLateCount++;
        }
    } catch (Throwable $e) {
        // ignore invalid dates
    }
}

$kpiAnomalyCount = $kpiSlotsCancelled + $kpiPlanningLateCount + $kpiConflictSlotCount + $kpiOverloadDayCount;
$headerPeriodLabel = ucfirst($period);
$headerGeneratedAt = (new DateTimeImmutable('now'))->format('d/m/Y H:i');
$headerReadinessRate = $kpiTotalSlots > 0 ? round((($kpiSlotsDone + $kpiSlotsInProgress) / max($kpiTotalSlots, 1)) * 100) : null;

// Données calendrier (FullCalendar)
$calendarItems = [];
$chantierLabelById = [];
foreach ($chantierOptions as $chantierOption) {
    $chantierId = (int)($chantierOption['id'] ?? 0);
    if ($chantierId <= 0) {
        continue;
    }
    $chantierCode = trim((string)($chantierOption['code'] ?? ''));
    $chantierTitle = trim((string)($chantierOption['titre'] ?? ''));
    $chantierLabelById[$chantierId] = trim($chantierCode . ($chantierCode !== '' && $chantierTitle !== '' ? ' - ' : '') . $chantierTitle);
}

foreach ($events as $ev) {
    $startAt = (string)($ev['start_at'] ?? '');
    $endAt = (string)($ev['end_at'] ?? '');
    if (!$startAt) {
        continue;
    }

    $startIso = null;
    $endIso = null;
    try {
        $startIso = (new DateTimeImmutable($startAt))->format('Y-m-d\\TH:i:s');
    } catch (Throwable $e) {
        $startIso = null;
    }
    if ($endAt) {
        try {
            $endIso = (new DateTimeImmutable($endAt))->format('Y-m-d\\TH:i:s');
        } catch (Throwable $e) {
            $endIso = null;
        }
    }

    if (!$startIso) {
        continue;
    }

    $calendarItems[] = [
        'id' => 'evt_' . (string)($ev['id'] ?? ''),
        'title' => (string)($ev['title'] ?? ''),
        'start' => $startIso,
        'end' => $endIso,
        'allDay' => false,
        'editable' => true,
        'durationEditable' => true,
        'startEditable' => true,
        'extendedProps' => [
            'source' => 'personal',
            'status_code' => (string)($ev['status_code'] ?? ''),
            'description' => (string)($ev['description'] ?? ''),
            'location' => (string)($ev['location'] ?? ''),
            'assignment_type' => (string)($ev['assignment_type'] ?? 'bureau'),
            'assignment_chantier_id' => (int)($ev['assignment_chantier_id'] ?? 0),
            'recurrence_rule_id' => (int)($ev['recurrence_rule_id'] ?? 0),
            'comm_task_id' => (int)($ev['comm_task_id'] ?? 0),
            'event_group_key' => (string)($ev['event_group_key'] ?? ''),
            'group_scope' => (string)($ev['group_scope'] ?? ''),
            'group_scope_label' => (string)($ev['group_scope_label'] ?? ''),
            'risk_conflict' => !empty($eventRiskMap[(string)($ev['id'] ?? '')]['conflict']),
            'risk_overload' => !empty($eventRiskMap[(string)($ev['id'] ?? '')]['overload']),
        ],
    ];
}

foreach ($planningTasks as $t) {
    $startDate = (string)($t['start_date'] ?? '');
    if (!$startDate) {
        continue;
    }
    $endDate = (string)($t['end_date'] ?? '');

    $start = null;
    $endExclusive = null;

    try {
        $start = (new DateTimeImmutable($startDate))->format('Y-m-d');
    } catch (Throwable $e) {
        $start = null;
    }

    if ($endDate) {
        try {
            $endExclusive = (new DateTimeImmutable($endDate))->modify('+1 day')->format('Y-m-d');
        } catch (Throwable $e) {
            $endExclusive = null;
        }
    }

    if (!$start) {
        continue;
    }

    $calendarItems[] = [
        'id' => 'task_' . (string)($t['task_id'] ?? ''),
        'title' => 'Planning : ' . (string)($t['task_name'] ?? ''),
        'start' => $start,
        'end' => $endExclusive,
        'allDay' => true,
        'editable' => false,
        'durationEditable' => false,
        'startEditable' => false,
        'extendedProps' => [
            'source' => 'planning',
            'status_code' => (string)($t['status_code'] ?? ''),
            'progress_percent' => isset($t['progress_percent']) ? (float)$t['progress_percent'] : null,
        ],
    ];
}

$quickTaskItems = array_map(static function (array $row): array {
    return [
        'id' => (int)($row['id'] ?? 0),
        'personnel_id' => (int)($row['personnel_id'] ?? 0),
        'title' => (string)($row['title'] ?? ''),
        'description' => (string)($row['description'] ?? ''),
        'status_code' => (string)($row['status_code'] ?? 'planifie'),
        'created_at' => (string)($row['created_at'] ?? ''),
        'personnel_name' => (string)($row['personnel_name'] ?? ''),
        'personnel_matricule' => (string)($row['personnel_matricule'] ?? ''),
        'service_label' => (string)($row['service_label'] ?? ''),
    ];
}, $quickTasks);

$quickTaskPersonnelItems = array_map(static function (array $row): array {
    $name = trim((string)($row['nom'] ?? ''));
    $matricule = trim((string)($row['matricule'] ?? ''));
    $serviceLabel = trim((string)($row['service_label'] ?? ''));
    return [
        'id' => (int)($row['id'] ?? 0),
        'name' => $name !== '' ? $name : 'Personnel',
        'matricule' => $matricule,
        'service_label' => $serviceLabel,
        'search' => mb_strtolower(trim($name . ' ' . $matricule . ' ' . $serviceLabel)),
    ];
}, $personnelOptions);

function fmtDateTimeLocal(?string $value): string
{
    if (!$value) {
        return '';
    }
    $ts = strtotime($value);
    if ($ts === false) {
        return '';
    }
    return date('Y-m-d\TH:i', $ts);
}

function fmtHumanDate(?string $value, bool $withTime = false): string
{
    if (!$value) {
        return '-';
    }
    $ts = strtotime($value);
    if ($ts === false) {
        return '-';
    }
    return date($withTime ? 'd/m/Y H:i' : 'd/m/Y', $ts);
}

$todoItems = [];
foreach ($events as $ev) {
    $startAt = (string)($ev['start_at'] ?? '');
    $ts = $startAt ? strtotime($startAt) : false;
    $todoItems[] = [
        'kind' => 'personal',
        'id' => (int)($ev['id'] ?? 0),
        'fc_id' => 'evt_' . (string)($ev['id'] ?? 0),
        'title' => (string)($ev['title'] ?? ''),
        'when' => $startAt,
        'ts' => $ts !== false ? $ts : 0,
        'done' => ((string)($ev['status_code'] ?? '') === 'termine'),
        'status_code' => (string)($ev['status_code'] ?? ''),
    ];
}

if (!empty($msTodoTasks)) {
    $undatedSortTs = time() + (86400 * 365 * 3);
    foreach ($msTodoTasks as $mt) {
        if (!is_array($mt)) {
            continue;
        }

        $due = (string)($mt['due'] ?? '');
        $ts = $due ? strtotime($due) : false;

        $todoItems[] = [
            'kind' => 'microsoft',
            'id' => (string)($mt['id'] ?? ''),
            'list_id' => (string)($mt['list_id'] ?? ''),
            'etag' => isset($mt['etag']) ? (string)$mt['etag'] : null,
            'title' => (string)($mt['title'] ?? ''),
            'when' => $due,
            'ts' => $ts !== false ? $ts : $undatedSortTs,
            'done' => (strtolower((string)($mt['status'] ?? '')) === 'completed'),
        ];
    }
}
usort($todoItems, static fn($a, $b) => ($a['ts'] <=> $b['ts']));

$operationalListRows = [];
foreach ($events as $eventRow) {
    $eventId = (string)($eventRow['id'] ?? '');
    $statusCode = (string)($eventRow['status_code'] ?? 'planifie');
    $assignmentType = (string)($eventRow['assignment_type'] ?? 'bureau');
    $assignmentChantierId = (int)($eventRow['assignment_chantier_id'] ?? 0);
    $assignmentLabel = $assignmentType === 'chantier' ? 'Chantier' : 'Bureau';
    if ($assignmentType === 'chantier' && $assignmentChantierId > 0 && !empty($chantierLabelById[$assignmentChantierId])) {
        $assignmentLabel .= ' - ' . $chantierLabelById[$assignmentChantierId];
    }
    $riskLabels = [];
    if (!empty($eventRiskMap[$eventId]['conflict'])) {
        $riskLabels[] = 'Conflit horaire';
    }
    if (!empty($eventRiskMap[$eventId]['overload'])) {
        $riskLabels[] = 'Surcharge journalière';
    }
    if ($statusCode === 'annule') {
        $riskLabels[] = 'Annulé';
    }

    $operationalListRows[] = [
        'sort_ts' => strtotime((string)($eventRow['start_at'] ?? '')) ?: 0,
        'type' => 'Créneau personnel',
        'title' => (string)($eventRow['title'] ?? ''),
        'start' => fmtHumanDate((string)($eventRow['start_at'] ?? ''), true),
        'end' => fmtHumanDate((string)($eventRow['end_at'] ?? ''), true),
        'status' => (string)($statusLabels[$statusCode] ?? $statusCode),
        'scope' => trim((string)($eventRow['group_scope_label'] ?? '')) !== '' ? (string)$eventRow['group_scope_label'] : 'Individuelle',
        'location' => $assignmentLabel,
        'risk' => $riskLabels,
    ];
}

foreach ($planningTasks as $taskRow) {
    $riskLabels = [];
    $endDate = trim((string)($taskRow['end_date'] ?? ''));
    $progressPercent = isset($taskRow['progress_percent']) ? (float)$taskRow['progress_percent'] : 0.0;
    if ($endDate !== '') {
        try {
            if (new DateTimeImmutable($endDate) < $todayDate && $progressPercent < 100) {
                $riskLabels[] = 'Retard planning';
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    $operationalListRows[] = [
        'sort_ts' => strtotime((string)($taskRow['start_date'] ?? '')) ?: 0,
        'type' => 'Tâche suivie',
        'title' => (string)($taskRow['task_name'] ?? ''),
        'start' => fmtHumanDate((string)($taskRow['start_date'] ?? ''), false),
        'end' => fmtHumanDate((string)($taskRow['end_date'] ?? ''), false),
        'status' => (string)($taskRow['status_code'] ?? ''),
        'scope' => 'Lecture seule',
        'location' => '',
        'risk' => $riskLabels,
    ];
}

usort($operationalListRows, static function (array $left, array $right): int {
    return ($left['sort_ts'] ?? 0) <=> ($right['sort_ts'] ?? 0);
});

if ($riskOnly) {
    $operationalListRows = array_values(array_filter($operationalListRows, static function (array $row): bool {
        return !empty($row['risk']);
    }));
}
?>

<style>
    .edt-shell {
        position: relative;
        overflow: hidden;
    }

    .edt-shell::before {
        content: '';
        position: absolute;
        inset: 0 0 auto 0;
        height: 260px;
        background:
            radial-gradient(circle at top left, rgba(37, 99, 235, 0.14), transparent 45%),
            radial-gradient(circle at top right, rgba(14, 165, 233, 0.14), transparent 40%),
            linear-gradient(135deg, rgba(248, 250, 252, 0.95), rgba(255, 255, 255, 0.78));
        pointer-events: none;
    }

    .edt-hero-card {
        position: relative;
        background: linear-gradient(145deg, rgba(15, 23, 42, 0.96), rgba(30, 41, 59, 0.92));
        color: #f8fafc;
        border: 1px solid rgba(148, 163, 184, 0.2);
        box-shadow: 0 24px 60px rgba(15, 23, 42, 0.18);
    }

    .edt-hero-chip {
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
        padding: 0.45rem 0.8rem;
        border-radius: 999px;
        font-size: 0.72rem;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        background: rgba(148, 163, 184, 0.14);
        border: 1px solid rgba(148, 163, 184, 0.2);
    }

    .edt-kpi-card {
        background: linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(248, 250, 252, 0.98));
        border: 1px solid rgba(226, 232, 240, 0.95);
        border-radius: 1.25rem;
        padding: 1rem 1.1rem;
        box-shadow: 0 12px 26px rgba(15, 23, 42, 0.06);
    }

    .edt-side-card {
        background: rgba(255, 255, 255, 0.94);
        border: 1px solid rgba(226, 232, 240, 0.92);
        border-radius: 1.25rem;
        padding: 1rem;
        box-shadow: 0 10px 30px rgba(15, 23, 42, 0.05);
    }

    .edt-quick-task-card {
        cursor: grab;
        user-select: none;
        transition: transform 120ms ease, box-shadow 120ms ease, border-color 120ms ease;
    }

    .edt-quick-task-card:active {
        cursor: grabbing;
    }

    .edt-quick-task-card:hover {
        transform: translateY(-1px);
        box-shadow: 0 12px 26px rgba(15, 23, 42, 0.08);
        border-color: rgba(99, 102, 241, 0.45);
    }

    .edt-quick-person-row.is-selected {
        border-color: rgba(37, 99, 235, 0.45);
        background: #eff6ff;
    }

    .edt-page-header {
        position: relative;
        z-index: 2;
    }

    .edt-top-btn {
        display: inline-flex !important;
        align-items: center !important;
        gap: 0.35rem !important;
        border: 1px solid #1e40af !important;
        background: #eff6ff !important;
        color: #1d4ed8 !important;
        box-shadow: 0 8px 18px rgba(30, 64, 175, 0.16) !important;
        transition: background-color 120ms ease, border-color 120ms ease, color 120ms ease, transform 120ms ease, box-shadow 120ms ease;
    }

    .edt-top-btn:hover {
        background: #1d4ed8 !important;
        border-color: #1e40af !important;
        color: #ffffff !important;
        transform: translateY(-1px);
        box-shadow: 0 12px 28px rgba(30, 64, 175, 0.38) !important;
    }

    .edt-top-btn--active {
        background-color: #111827 !important;
        border-color: #111827 !important;
        color: #ffffff !important;
        box-shadow: 0 10px 24px rgba(15, 23, 42, 0.26) !important;
    }

    .edt-top-btn--active:hover {
        background-color: #1f2937 !important;
        border-color: #1f2937 !important;
        color: #ffffff !important;
    }

    .edt-top-btn--portal {
        border-color: #047857 !important;
        background: #ecfdf5 !important;
        color: #047857 !important;
        box-shadow: 0 8px 18px rgba(4, 120, 87, 0.16) !important;
    }

    .edt-top-btn--portal:hover {
        background: #047857 !important;
        border-color: #065f46 !important;
        color: #ffffff !important;
    }

    .edt-top-btn--todo {
        border-color: #7c3aed !important;
        background: #f5f3ff !important;
        color: #7c3aed !important;
        box-shadow: 0 8px 18px rgba(124, 58, 237, 0.16) !important;
    }

    .edt-top-btn--todo:hover {
        background: #7c3aed !important;
        border-color: #6d28d9 !important;
        color: #ffffff !important;
    }

    .edt-top-btn--csv {
        border-color: #c2410c !important;
        background: #fff7ed !important;
        color: #c2410c !important;
        box-shadow: 0 8px 18px rgba(194, 65, 12, 0.16) !important;
    }

    .edt-top-btn--csv:hover {
        background: #c2410c !important;
        border-color: #9a3412 !important;
        color: #ffffff !important;
    }

    .edt-top-btn--pdf {
        border-color: #be123c !important;
        background: #fff1f2 !important;
        color: #be123c !important;
        box-shadow: 0 8px 18px rgba(190, 18, 60, 0.18) !important;
    }

    .edt-top-btn--pdf:hover {
        background: #be123c !important;
        border-color: #9f1239 !important;
        color: #ffffff !important;
        box-shadow: 0 12px 28px rgba(190, 18, 60, 0.4) !important;
    }

    .edt-anomaly-item {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 0.8rem;
        padding: 0.8rem 0.9rem;
        border-radius: 1rem;
        border: 1px solid rgba(226, 232, 240, 0.9);
        background: #fff;
    }

    .edt-calendar-shell {
        background: linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(248, 250, 252, 0.98));
        border: 1px solid rgba(226, 232, 240, 0.95);
        border-radius: 1.5rem;
        padding: 1rem;
        box-shadow: 0 18px 44px rgba(15, 23, 42, 0.08);
    }

    /* Calendrier plein écran + neutralisation des effets de survol (demande : éviter le survolement) */
    #edt-calendar {
        height: calc(100vh - 200px);
        min-height: 620px;
    }

    @media (max-width: 768px) {
        #edt-calendar {
            height: calc(100vh - 190px);
            min-height: 520px;
        }
    }

    .fc .fc-daygrid-day-frame:hover,
    .fc .fc-timegrid-col:hover,
    .fc .fc-timegrid-slot:hover,
    .fc a:hover {
        background: inherit !important;
        filter: none !important;
        text-decoration: none !important;
        box-shadow: none !important;
    }

    .fc {
        --fc-border-color: #e2e8f0;
        --fc-page-bg-color: transparent;
        --fc-neutral-bg-color: #f8fafc;
        --fc-today-bg-color: rgba(14, 165, 233, 0.08);
        --fc-button-bg-color: #0f172a;
        --fc-button-border-color: #0f172a;
        --fc-button-hover-bg-color: #1e293b;
        --fc-button-hover-border-color: #1e293b;
        --fc-button-active-bg-color: #2563eb;
        --fc-button-active-border-color: #2563eb;
    }

    .fc .fc-toolbar.fc-header-toolbar {
        margin-bottom: 1rem;
        gap: 0.8rem;
        flex-wrap: wrap;
    }

    .fc .fc-toolbar-title {
        font-size: 1.15rem;
        font-weight: 700;
        color: #0f172a;
        letter-spacing: 0.01em;
    }

    .fc .fc-button {
        border-radius: 0.9rem;
        font-size: 0.8rem;
        font-weight: 600;
        padding: 0.58rem 0.9rem;
        box-shadow: none !important;
    }

    .fc .fc-col-header-cell-cushion,
    .fc .fc-timegrid-axis-cushion,
    .fc .fc-timegrid-slot-label-cushion {
        color: #475569;
        font-weight: 600;
        font-size: 0.78rem;
    }

    .fc .fc-daygrid-day-number {
        color: #0f172a;
        font-weight: 600;
        font-size: 0.85rem;
        padding: 0.45rem;
    }

    .fc .fc-daygrid-day.fc-day-today,
    .fc .fc-timegrid-col.fc-day-today {
        background: rgba(14, 165, 233, 0.06) !important;
    }

    .fc .fc-timegrid-slot,
    .fc .fc-daygrid-day-frame,
    .fc .fc-scrollgrid,
    .fc .fc-scrollgrid-section>* {
        border-color: rgba(226, 232, 240, 0.9) !important;
    }

    .fc .fc-event {
        border-radius: 0.9rem;
        border: 0;
        padding: 0.16rem 0.2rem;
        box-shadow: 0 6px 16px rgba(15, 23, 42, 0.1);
        transition: box-shadow 120ms ease, outline-color 120ms ease, transform 120ms ease, filter 120ms ease;
    }

    .fc .fc-event-main {
        padding: 0.2rem 0.35rem;
        font-size: 0.78rem;
    }

    .fc .fc-event:hover,
    .fc .fc-event:focus,
    .fc .fc-event:focus-within,
    .fc .fc-event.fc-event-selected {
        outline: 2px solid rgba(15, 23, 42, 0.32);
        outline-offset: 2px;
        box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.95), 0 12px 26px rgba(15, 23, 42, 0.22) !important;
        filter: saturate(1.08) brightness(0.98);
        transform: translateY(-1px);
    }

    .fc .fc-event:hover .fc-event-main,
    .fc .fc-event:focus .fc-event-main,
    .fc .fc-event:focus-within .fc-event-main {
        color: inherit;
        opacity: 1;
    }

    .fc .fc-list-event:hover td {
        background: rgba(241, 245, 249, 0.8) !important;
    }

    /* Styles statut (lisibles sans dépendre d'un survol) */
    .fc .edt-evt-termine {
        opacity: 0.75;
        text-decoration: line-through;
    }

    .fc .edt-evt-annule {
        opacity: 0.55;
        text-decoration: line-through;
    }

    .fc .edt-evt-planning {
        cursor: default;
    }

    .fc .edt-evt-risk {
        box-shadow: 0 0 0 2px rgba(245, 158, 11, 0.35), 0 8px 18px rgba(15, 23, 42, 0.12);
    }

    .fc .edt-evt-risk-problem {
        box-shadow: 0 0 0 2px rgba(220, 38, 38, 0.35), 0 8px 18px rgba(15, 23, 42, 0.14);
    }

    .edt-risk-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        padding: 0.3rem 0.6rem;
        border-radius: 999px;
        font-size: 0.7rem;
        font-weight: 600;
        border: 1px solid transparent;
    }

    .edt-risk-badge--warn {
        background: #fff7ed;
        color: #c2410c;
        border-color: #fdba74;
    }

    .edt-risk-badge--danger {
        background: #fef2f2;
        color: #b91c1c;
        border-color: #fca5a5;
    }

    .edt-operational-table {
        table-layout: auto;
    }

    .edt-operational-table th,
    .edt-operational-table td {
        vertical-align: top;
        white-space: normal;
        word-break: break-word;
        text-overflow: clip;
        overflow: visible;
        max-width: none;
    }

    @media print {
        body * {
            visibility: hidden;
        }

        .edt-print-scope,
        .edt-print-scope * {
            visibility: visible;
        }

        .edt-print-scope {
            position: absolute;
            left: 0;
            top: 0;
            width: 100%;
            background: #fff;
        }

        .edt-no-print {
            display: none !important;
        }
    }

    .personnel-search-input {
        width: 100%;
        min-height: 44px;
        border-radius: 0.75rem;
        border: 1px solid rgb(209 213 219);
        padding: 0.65rem 0.85rem;
        background: #fff;
        color: rgb(17 24 39);
    }

    .personnel-search-input:focus {
        outline: none;
        border-color: rgb(99 102 241);
        box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.12);
    }

    .personnel-search-input::-webkit-calendar-picker-indicator {
        opacity: 0;
    }

    @media (max-width: 1024px) {
        .edt-hero-grid {
            grid-template-columns: 1fr !important;
        }
    }
</style>

<div class="max-w-none mx-auto px-4 py-8 edt-shell">
    <div class="edt-page-header flex items-start justify-between gap-4 flex-wrap">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">Emploi du temps</h1>
            <p class="text-sm text-gray-600 mt-1">Récap des tâches, suivi RH par personne, service ou toute l'entreprise.</p>
        </div>
        <div class="flex items-center gap-2 flex-wrap">
            <a href="<?= htmlspecialchars($rootUrl('selection_portail.php')) ?>" class="edt-top-btn edt-top-btn--portal px-3 py-2 rounded-xl text-sm font-semibold">Retour portail</a>
            <button type="button" id="edt-open-todo" class="edt-top-btn edt-top-btn--todo px-3 py-2 rounded-xl text-sm font-semibold">
                <i class="fa-solid fa-list-check mr-2"></i>To-Do
            </button>
            <a href="emploi_du_temps.php?period=<?= htmlspecialchars($period) ?>&amp;date=<?= htmlspecialchars($dateObj->format('Y-m-d')) ?>&amp;display=calendar" class="edt-top-btn px-3 py-2 rounded-xl text-sm font-semibold <?= $displayMode === 'calendar' ? 'edt-top-btn--active' : '' ?>">Calendrier</a>
            <a href="emploi_du_temps.php?period=<?= htmlspecialchars($period) ?>&amp;date=<?= htmlspecialchars($dateObj->format('Y-m-d')) ?>&amp;display=list<?= $riskOnly ? '&amp;risk_only=1' : '' ?>" class="edt-top-btn px-3 py-2 rounded-xl text-sm font-semibold <?= $displayMode === 'list' ? 'edt-top-btn--active' : '' ?>">Liste opérationnelle</a>
            <?php if ($canAccessTaskStatsDashboard): ?>
                <a href="<?= htmlspecialchars($rootUrl('dashboard_statistiques_taches.php')) ?>?period=<?= htmlspecialchars($period) ?>&amp;date=<?= htmlspecialchars($dateObj->format('Y-m-d')) ?>&amp;recap_service_id=<?= (int) $recapServiceId ?>" class="edt-top-btn px-3 py-2 rounded-xl text-sm font-semibold">
                    <i class="fa-solid fa-chart-line mr-2"></i>Dashboard statistiques
                </a>
                <a href="<?= htmlspecialchars($rootUrl('dashboard_statistiques_taches.php')) ?>?action=export_recap_xlsx&amp;period=<?= htmlspecialchars($period) ?>&amp;date=<?= htmlspecialchars($dateObj->format('Y-m-d')) ?>&amp;service_id=<?= (int) $recapServiceId ?>" class="edt-top-btn edt-top-btn--csv px-3 py-2 rounded-xl text-sm font-semibold">
                    <i class="fa-solid fa-file-excel mr-2"></i>Excel stats
                </a>
                <a href="<?= htmlspecialchars($rootUrl('dashboard_statistiques_taches.php')) ?>?action=export_recap_pdf&amp;period=<?= htmlspecialchars($period) ?>&amp;date=<?= htmlspecialchars($dateObj->format('Y-m-d')) ?>&amp;service_id=<?= (int) $recapServiceId ?>" class="edt-top-btn edt-top-btn--pdf px-3 py-2 rounded-xl text-sm font-semibold">
                    <i class="fa-solid fa-file-pdf mr-2"></i>PDF stats
                </a>
            <?php endif; ?>
            <?php if ($personnelId > 0): ?>
                <a href="emploi_du_temps.php?action=export_pdf&amp;period=<?= htmlspecialchars($period) ?>&amp;date=<?= htmlspecialchars($dateObj->format('Y-m-d')) ?>" class="edt-top-btn edt-top-btn--pdf px-3 py-2 rounded-xl text-sm font-semibold">
                    <i class="fa-solid fa-file-pdf mr-2"></i>Exporter PDF
                </a>
                <a href="emploi_du_temps.php?action=export&amp;period=<?= htmlspecialchars($period) ?>&amp;date=<?= htmlspecialchars($dateObj->format('Y-m-d')) ?>" class="edt-top-btn edt-top-btn--csv px-3 py-2 rounded-xl text-sm font-semibold">Exporter CSV</a>
                <a href="emploi_du_temps.php?period=<?= htmlspecialchars($period) ?>&amp;date=<?= htmlspecialchars($dateObj->format('Y-m-d')) ?>&amp;display=list<?= $riskOnly ? '&amp;risk_only=1' : '' ?>&amp;print=1" class="edt-top-btn px-3 py-2 rounded-xl text-sm font-semibold edt-no-print">Imprimer la liste</a>
            <?php else: ?>
                <span class="px-3 py-2 rounded-xl bg-gray-100 text-gray-400 text-sm border" title="Association personnel requise">Exporter PDF</span>
                <span class="px-3 py-2 rounded-xl bg-gray-100 text-gray-400 text-sm border" title="Association personnel requise">Exporter CSV</span>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($flashError): ?>
        <div class="mt-4 p-3 rounded-xl bg-rose-50 text-rose-800 border border-rose-100 text-sm">
            <?= htmlspecialchars($flashError) ?>
        </div>
    <?php elseif ($flashSuccess): ?>
        <div class="mt-4 p-3 rounded-xl bg-emerald-50 text-emerald-800 border border-emerald-100 text-sm">
            <?= htmlspecialchars($flashSuccess) ?>
        </div>
    <?php endif; ?>

    <div class="mt-5 grid grid-cols-1 xl:grid-cols-[minmax(0,1.7fr)_minmax(320px,0.9fr)] gap-4 edt-hero-grid">
        <div class="edt-hero-card rounded-[1.75rem] p-6">
            <div class="flex items-start justify-between gap-4 flex-wrap">
                <div>
                    <div class="edt-hero-chip">
                        <i class="fa-solid fa-calendar-days"></i>
                        <span>Vue <?= htmlspecialchars($headerPeriodLabel) ?></span>
                    </div>
                    <h2 class="mt-4 text-2xl font-semibold">Pilotage opérationnel du planning</h2>
                    <p class="mt-2 text-sm text-slate-300 max-w-3xl">Lecture immédiate des tâches planifiées, réalisées et en retard selon le périmètre choisi : collaborateur, service ou entreprise.</p>
                </div>
                <div class="text-right text-sm text-slate-300">
                    <div>Période observée</div>
                    <div class="mt-1 text-base font-semibold text-white"><?= htmlspecialchars($rangeStart->format('d/m/Y')) ?> → <?= htmlspecialchars($rangeEnd->format('d/m/Y')) ?></div>
                    <div class="mt-2 text-xs text-slate-400">Mise à jour : <?= htmlspecialchars($headerGeneratedAt) ?></div>
                </div>
            </div>

            <?php $mainKpis = $canAccessTaskStatsDashboard ? $globalScheduleKpis : edt_schedule_kpis($events); ?>
            <div class="mt-6 grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3">
                <div class="rounded-2xl border border-white/10 bg-white/6 px-4 py-4">
                    <div class="text-xs uppercase tracking-wide text-slate-300">Tâches planifiées</div>
                    <div class="mt-2 text-3xl font-semibold text-white"><?= (int) $mainKpis['planned'] ?></div>
                    <div class="mt-1 text-xs text-slate-300"><?= htmlspecialchars($canAccessTaskStatsDashboard ? $recapServiceLabel : 'Collaborateur sélectionné') ?></div>
                </div>
                <div class="rounded-2xl border border-white/10 bg-white/6 px-4 py-4">
                    <div class="text-xs uppercase tracking-wide text-slate-300">Tâches effectuées</div>
                    <div class="mt-2 text-3xl font-semibold text-white"><?= (int) $mainKpis['done'] ?></div>
                    <div class="mt-1 text-xs text-slate-300">Statut terminé</div>
                </div>
                <div class="rounded-2xl border border-white/10 bg-white/6 px-4 py-4">
                    <div class="text-xs uppercase tracking-wide text-slate-300">Réalisation</div>
                    <div class="mt-2 text-3xl font-semibold text-white"><?= $mainKpis['realization_rate'] !== null ? htmlspecialchars(number_format((float) $mainKpis['realization_rate'], 1, ',', ' ')) . '%' : 'n/d' ?></div>
                    <div class="mt-1 text-xs text-slate-300">Effectuées / planifiées</div>
                </div>
                <div class="rounded-2xl border border-white/10 bg-white/6 px-4 py-4">
                    <div class="text-xs uppercase tracking-wide text-slate-300">Taux de retard</div>
                    <div class="mt-2 text-3xl font-semibold text-white"><?= $mainKpis['delay_rate'] !== null ? htmlspecialchars(number_format((float) $mainKpis['delay_rate'], 1, ',', ' ')) . '%' : 'n/d' ?></div>
                    <div class="mt-1 text-xs text-slate-300"><?= (int) $mainKpis['late'] ?> tâche(s) en retard</div>
                </div>
            </div>
        </div>

        <div class="edt-side-card">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <div class="text-xs uppercase tracking-wide text-slate-500">Anomalies utiles direction</div>
                    <div class="mt-1 text-lg font-semibold text-slate-900"><?= (int) $kpiAnomalyCount ?> point(s) d'attention</div>
                </div>
                <span class="px-3 py-1 rounded-full <?= $kpiAnomalyCount > 0 ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-800' ?> text-xs font-semibold">
                    <?= $kpiAnomalyCount > 0 ? 'ATTENTION' : 'STABLE' ?>
                </span>
            </div>

            <div class="mt-4 space-y-3">
                <div class="edt-anomaly-item">
                    <div>
                        <div class="text-sm font-semibold text-slate-900">Conflits horaires</div>
                        <div class="text-xs text-slate-500 mt-1">Créneaux qui se chevauchent sur une même journée.</div>
                    </div>
                    <div class="text-xl font-semibold text-amber-700"><?= (int) $kpiConflictSlotCount ?></div>
                </div>
                <div class="edt-anomaly-item">
                    <div>
                        <div class="text-sm font-semibold text-slate-900">Jours en surcharge</div>
                        <div class="text-xs text-slate-500 mt-1">Charge journalière supérieure à <?= htmlspecialchars(number_format($dailyLoadThresholdHours, 0, ',', ' ')) ?> h.</div>
                    </div>
                    <div class="text-xl font-semibold text-orange-700"><?= (int) $kpiOverloadDayCount ?></div>
                </div>
                <div class="edt-anomaly-item">
                    <div>
                        <div class="text-sm font-semibold text-slate-900">Créneaux annulés</div>
                        <div class="text-xs text-slate-500 mt-1">Tâches à reprogrammer ou à clôturer.</div>
                    </div>
                    <div class="text-xl font-semibold text-rose-700"><?= (int) $kpiSlotsCancelled ?></div>
                </div>
                <div class="edt-anomaly-item">
                    <div>
                        <div class="text-sm font-semibold text-slate-900">Tâches en retard</div>
                        <div class="text-xs text-slate-500 mt-1">Fin prévue dépassée et tâche non terminée.</div>
                    </div>
                    <div class="text-xl font-semibold text-amber-700"><?= (int) $mainKpis['late'] ?></div>
                </div>
            </div>

            <div class="mt-4 rounded-2xl bg-slate-50 border border-slate-200 p-4">
                <div class="text-xs uppercase tracking-wide text-slate-500">Lecture rapide</div>
                <div class="mt-2 text-sm text-slate-700">
                    <?= $personnel ? htmlspecialchars((string)($personnel['nom'] ?? 'Personnel')) : 'Aucun personnel sélectionné' ?>
                </div>
                <div class="mt-2 flex flex-wrap gap-2 text-xs">
                    <span class="px-2 py-1 rounded-full bg-slate-100 text-slate-700 border">En cours : <?= (int) $kpiSlotsInProgress ?></span>
                    <span class="px-2 py-1 rounded-full bg-sky-50 text-sky-700 border border-sky-100">Réalisation : <?= $mainKpis['realization_rate'] !== null ? htmlspecialchars(number_format((float) $mainKpis['realization_rate'], 1, ',', ' ')) . '%' : 'n/d' ?></span>
                    <span class="px-2 py-1 rounded-full bg-indigo-50 text-indigo-700 border border-indigo-100">Export récap prêt</span>
                </div>
            </div>
        </div>
    </div>

    <?php if ($canAccessTaskStatsDashboard): ?>
        <div id="dashboard-statistique-taches" class="mt-4 rounded-2xl border border-slate-200 bg-white/90 p-5 shadow-sm">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <div class="text-xs font-semibold uppercase tracking-wide text-indigo-600">Récap global des tâches - profil RH</div>
                    <h3 class="mt-1 text-xl font-semibold text-slate-950"><?= htmlspecialchars($recapServiceLabel) ?></h3>
                    <p class="mt-1 text-sm text-slate-500">Indicateurs consolidés sur la période affichée, exportables par service ou pour toute l'entreprise.</p>
                </div>
                <form method="get" class="flex flex-wrap items-end gap-2">
                    <input type="hidden" name="period" value="<?= htmlspecialchars($period) ?>">
                    <input type="hidden" name="date" value="<?= htmlspecialchars($dateObj->format('Y-m-d')) ?>">
                    <div>
                        <label class="block text-xs text-slate-500">Service</label>
                        <select name="recap_service_id" class="mt-1 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
                            <option value="0">Toute l'entreprise</option>
                            <?php foreach ($serviceOptions as $serviceOption): ?>
                                <option value="<?= (int)($serviceOption['id'] ?? 0) ?>" <?= $recapServiceId === (int)($serviceOption['id'] ?? 0) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string)($serviceOption['label'] ?? 'Service')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Afficher</button>
                    <a href="<?= htmlspecialchars($rootUrl('dashboard_statistiques_taches.php')) ?>?action=export_recap_xlsx&amp;period=<?= htmlspecialchars($period) ?>&amp;date=<?= htmlspecialchars($dateObj->format('Y-m-d')) ?>&amp;service_id=<?= (int) $recapServiceId ?>" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm font-semibold text-emerald-800 hover:bg-emerald-100">
                        <i class="fa-solid fa-file-excel mr-2"></i>Excel professionnel
                    </a>
                    <a href="<?= htmlspecialchars($rootUrl('dashboard_statistiques_taches.php')) ?>?action=export_recap_pdf&amp;period=<?= htmlspecialchars($period) ?>&amp;date=<?= htmlspecialchars($dateObj->format('Y-m-d')) ?>&amp;service_id=<?= (int) $recapServiceId ?>" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-2 text-sm font-semibold text-rose-800 hover:bg-rose-100">
                        <i class="fa-solid fa-file-pdf mr-2"></i>PDF FINEA
                    </a>
                </form>
            </div>

            <div class="mt-5 grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-5">
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                    <div class="text-xs uppercase tracking-wide text-slate-500">Tâches planifiées</div>
                    <div class="mt-2 text-3xl font-semibold text-slate-950"><?= (int) $globalScheduleKpis['planned'] ?></div>
                </div>
                <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4">
                    <div class="text-xs uppercase tracking-wide text-emerald-700">Tâches effectuées</div>
                    <div class="mt-2 text-3xl font-semibold text-emerald-900"><?= (int) $globalScheduleKpis['done'] ?></div>
                </div>
                <div class="rounded-2xl border border-sky-200 bg-sky-50 p-4">
                    <div class="text-xs uppercase tracking-wide text-sky-700">Réalisation</div>
                    <div class="mt-2 text-3xl font-semibold text-sky-900"><?= $globalScheduleKpis['realization_rate'] !== null ? htmlspecialchars(number_format((float)$globalScheduleKpis['realization_rate'], 1, ',', ' ')) . '%' : 'n/d' ?></div>
                </div>
                <div class="rounded-2xl border border-rose-200 bg-rose-50 p-4">
                    <div class="text-xs uppercase tracking-wide text-rose-700">Taux de retard</div>
                    <div class="mt-2 text-3xl font-semibold text-rose-900"><?= $globalScheduleKpis['delay_rate'] !== null ? htmlspecialchars(number_format((float)$globalScheduleKpis['delay_rate'], 1, ',', ' ')) . '%' : 'n/d' ?></div>
                </div>
                <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4">
                    <div class="text-xs uppercase tracking-wide text-amber-700">Présent sans tâche</div>
                    <div class="mt-2 text-3xl font-semibold text-amber-900"><?= (int) $globalPresenceGapStats['kpis']['gap_days'] ?></div>
                    <div class="mt-1 text-xs text-amber-700"><?= $globalPresenceGapStats['kpis']['gap_rate'] !== null ? htmlspecialchars(number_format((float)$globalPresenceGapStats['kpis']['gap_rate'], 1, ',', ' ')) . '% de fréquence' : 'n/d' ?></div>
                </div>
            </div>

            <div class="mt-5 rounded-2xl border border-amber-200 bg-white p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div class="text-sm font-semibold text-slate-900">Validations N+1 en attente</div>
                        <div class="mt-1 text-xs text-slate-500">Tâches marquées terminées par le personnel, en attente de validation hiérarchique.</div>
                    </div>
                    <span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-800"><?= count($pendingCompletionValidations) ?> attente(s)</span>
                </div>
                <div id="edt-validation-error" class="mt-3 hidden rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700"></div>
                <div class="mt-4 overflow-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-xs uppercase text-slate-400">
                            <tr><th class="py-2">Tâche</th><th class="py-2">Personnel</th><th class="py-2">Service</th><th class="py-2">Période</th><th class="py-2 text-right">Action</th></tr>
                        </thead>
                        <tbody id="edt-pending-validations">
                            <?php foreach (array_slice($pendingCompletionValidations, 0, 30) as $pendingValidation): ?>
                                <tr class="border-t border-slate-100" data-validation-event-id="<?= (int)($pendingValidation['id'] ?? 0) ?>">
                                    <td class="py-3 pr-3">
                                        <div class="font-semibold text-slate-900"><?= htmlspecialchars((string)($pendingValidation['title'] ?? 'Tâche')) ?></div>
                                        <?php if (trim((string)($pendingValidation['description'] ?? '')) !== ''): ?>
                                            <div class="mt-1 text-xs text-slate-500"><?= htmlspecialchars((string)$pendingValidation['description']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 pr-3 text-slate-700">
                                        <?= htmlspecialchars((string)($pendingValidation['personnel_name'] ?? '')) ?>
                                        <?php if (trim((string)($pendingValidation['personnel_matricule'] ?? '')) !== ''): ?>
                                            <div class="text-xs text-slate-500"><?= htmlspecialchars((string)$pendingValidation['personnel_matricule']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 pr-3 text-slate-600"><?= htmlspecialchars((string)($pendingValidation['service_label'] ?? '')) ?></td>
                                    <td class="py-3 pr-3 text-slate-600"><?= htmlspecialchars(fmtHumanDate((string)($pendingValidation['start_at'] ?? ''), false)) ?> → <?= htmlspecialchars(fmtHumanDate((string)($pendingValidation['end_at'] ?? ''), false)) ?></td>
                                    <td class="py-3 text-right">
                                        <button type="button" class="edt-validate-task-btn rounded-xl bg-emerald-600 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-700" data-event-id="<?= (int)($pendingValidation['id'] ?? 0) ?>">
                                            Valider terminé
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$pendingCompletionValidations): ?>
                                <tr><td colspan="5" class="py-5 text-center text-slate-500">Aucune tâche à valider pour votre périmètre N+1.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="mt-5 grid grid-cols-1 gap-4 xl:grid-cols-2">
                <div class="rounded-2xl border border-slate-200 bg-white p-4">
                    <div class="mb-3 text-sm font-semibold text-slate-900">Piques et relâchements par service</div>
                    <div class="space-y-3">
                        <?php foreach (array_slice($globalServiceStats, 0, 8, true) as $serviceStat): ?>
                            <?php $rate = $serviceStat['kpis']['realization_rate']; ?>
                            <div>
                                <div class="flex justify-between gap-3 text-sm">
                                    <span class="font-medium text-slate-700"><?= htmlspecialchars((string)$serviceStat['label']) ?></span>
                                    <span class="text-slate-500"><?= $rate !== null ? htmlspecialchars(number_format((float)$rate, 1, ',', ' ')) . '%' : 'n/d' ?></span>
                                </div>
                                <div class="mt-1 h-2 rounded-full bg-slate-100">
                                    <div class="h-2 rounded-full bg-indigo-500" style="width: <?= $rate !== null ? max(0, min(100, (float)$rate)) : 0 ?>%"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if (!$globalServiceStats): ?>
                            <div class="text-sm text-slate-500">Aucune tâche sur la période.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="rounded-2xl border border-slate-200 bg-white p-4">
                    <div class="mb-3 text-sm font-semibold text-slate-900">Suivi par collaborateur</div>
                    <div class="max-h-72 overflow-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="text-xs uppercase text-slate-400">
                                <tr><th class="py-2">Collaborateur</th><th class="py-2 text-right">Planifiées</th><th class="py-2 text-right">Faites</th><th class="py-2 text-right">Retard</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_slice($globalPersonnelStats, 0, 12, true) as $personStat): ?>
                                    <tr class="border-t border-slate-100">
                                        <td class="py-2 font-medium text-slate-700"><?= htmlspecialchars((string)$personStat['label']) ?></td>
                                        <td class="py-2 text-right"><?= (int)$personStat['kpis']['planned'] ?></td>
                                        <td class="py-2 text-right"><?= (int)$personStat['kpis']['done'] ?></td>
                                        <td class="py-2 text-right"><?= (int)$personStat['kpis']['late'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (!$globalPersonnelStats): ?>
                                    <tr><td colspan="4" class="py-4 text-center text-slate-500">Aucune tâche à afficher.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="mt-5 grid grid-cols-1 gap-4 xl:grid-cols-2">
                <div class="rounded-2xl border border-amber-200 bg-amber-50/70 p-4">
                    <div class="mb-3 text-sm font-semibold text-amber-950">Fréquence présent sans tâche par individu</div>
                    <div class="max-h-72 overflow-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="text-xs uppercase text-amber-700">
                                <tr><th class="py-2">Personnel</th><th class="py-2">Service</th><th class="py-2 text-right">Trous</th><th class="py-2 text-right">Fréq.</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_slice($globalPresenceGapStats['personnel'], 0, 12, true) as $gapStat): ?>
                                    <tr class="border-t border-amber-100">
                                        <td class="py-2 font-medium text-slate-800">
                                            <?= htmlspecialchars((string)$gapStat['label']) ?>
                                            <?php if (trim((string)$gapStat['matricule']) !== ''): ?>
                                                <div class="text-xs font-normal text-slate-500"><?= htmlspecialchars((string)$gapStat['matricule']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-2 text-slate-600"><?= htmlspecialchars((string)$gapStat['service_label']) ?></td>
                                        <td class="py-2 text-right"><?= (int)$gapStat['gap_days'] ?> / <?= (int)$gapStat['present_days'] ?></td>
                                        <td class="py-2 text-right font-semibold text-amber-800"><?= htmlspecialchars(number_format((float)$gapStat['gap_rate'], 1, ',', ' ')) ?>%</td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($globalPresenceGapStats['personnel'])): ?>
                                    <tr><td colspan="4" class="py-4 text-center text-slate-500">Aucune présence pointée sur la période.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="rounded-2xl border border-slate-200 bg-white p-4">
                    <div class="mb-3 text-sm font-semibold text-slate-900">Trous visibles sur feuille de temps</div>
                    <div class="max-h-72 overflow-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="text-xs uppercase text-slate-400">
                                <tr><th class="py-2">Date</th><th class="py-2">Personnel</th><th class="py-2">Service</th><th class="py-2">Pointage</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_slice($globalPresenceGapStats['gap_rows'], 0, 18) as $gapRow): ?>
                                    <tr class="border-t border-slate-100">
                                        <td class="py-2 font-medium text-slate-800"><?= htmlspecialchars((string)($gapRow['attendance_date'] ?? '')) ?></td>
                                        <td class="py-2 text-slate-700"><?= htmlspecialchars((string)($gapRow['personnel_name'] ?? '')) ?></td>
                                        <td class="py-2 text-slate-600"><?= htmlspecialchars((string)($gapRow['service_label'] ?? '')) ?></td>
                                        <td class="py-2 text-slate-500"><?= htmlspecialchars(substr((string)($gapRow['check_in_time'] ?? ''), 0, 5) . ' - ' . substr((string)($gapRow['check_out_time'] ?? ''), 0, 5)) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($globalPresenceGapStats['gap_rows'])): ?>
                                    <tr><td colspan="4" class="py-4 text-center text-slate-500">Aucun trou détecté.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="mt-5 rounded-2xl border border-slate-200 bg-white p-4">
                <div class="mb-3 text-sm font-semibold text-slate-900">Fréquence présent sans tâche par service</div>
                <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4">
                    <?php foreach (array_slice($globalPresenceGapStats['services'], 0, 8, true) as $serviceGapStat): ?>
                        <div class="rounded-xl border border-slate-100 bg-slate-50 p-3">
                            <div class="truncate text-sm font-semibold text-slate-800"><?= htmlspecialchars((string)$serviceGapStat['label']) ?></div>
                            <div class="mt-2 flex items-end justify-between gap-2">
                                <span class="text-2xl font-semibold text-amber-800"><?= htmlspecialchars(number_format((float)$serviceGapStat['gap_rate'], 1, ',', ' ')) ?>%</span>
                                <span class="text-xs text-slate-500"><?= (int)$serviceGapStat['gap_days'] ?> / <?= (int)$serviceGapStat['present_days'] ?> jours</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($globalPresenceGapStats['services'])): ?>
                        <div class="text-sm text-slate-500">Aucun service avec présence pointée sur la période.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="mt-4 bg-white/80 backdrop-blur rounded-2xl border border-gray-200 p-5">
        <div class="flex items-start justify-between gap-4 flex-wrap">
            <div>
                <div class="text-sm text-gray-500">Personnel du calendrier</div>
                <?php if ($personnel): ?>
                    <div class="mt-1 font-semibold text-gray-900"><?= htmlspecialchars((string)($personnel['nom'] ?? 'Personnel')) ?></div>
                    <div class="text-xs text-gray-500 mt-1">Matricule : <?= htmlspecialchars((string)($personnel['matricule'] ?? ($_SESSION[$sessionMatriculeKey] ?? ''))) ?></div>
                    <?php if ($selectedServiceLabel !== ''): ?>
                        <div class="text-xs text-gray-500 mt-1">Service : <?= htmlspecialchars($selectedServiceLabel) ?></div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="mt-1 font-semibold text-amber-700">Aucun personnel actif sélectionné</div>
                    <div class="text-xs text-gray-500 mt-1">Sans personnel lié, le clic calendrier et la création de créneaux restent désactivés.</div>
                <?php endif; ?>
            </div>

            <div class="flex items-start gap-3 flex-wrap">
                <form method="post" id="edt-personnel-form" class="flex items-end gap-2 flex-wrap">
                    <input type="hidden" name="action" value="select_personnel" />
                    <input type="hidden" name="personnel_id" id="edt-personnel-id" value="<?= $personnelId > 0 ? (int)$personnelId : '' ?>" />
                    <div class="min-w-[320px] flex-1">
                        <label class="block text-xs text-gray-500">Personnel à ouvrir</label>
                        <input type="text" id="edt-personnel-search" class="mt-1 personnel-search-input text-sm" list="edt-personnel-options" value="<?= htmlspecialchars($selectedPersonnelDisplay) ?>" placeholder="Rechercher par nom, matricule ou service" autocomplete="off" />
                        <datalist id="edt-personnel-options">
                            <?php foreach ($personnelOptions as $personOption): ?>
                                <?php
                                $optionId = (int)($personOption['id'] ?? 0);
                                $optionName = trim((string)($personOption['nom'] ?? ''));
                                $optionMatricule = trim((string)($personOption['matricule'] ?? ''));
                                $optionServiceLabel = trim((string)($personOption['service_label'] ?? ''));
                                $optionText = $optionName;
                                if ($optionMatricule !== '') {
                                    $optionText .= ' - ' . $optionMatricule;
                                }
                                if ($optionServiceLabel !== '') {
                                    $optionText .= ' - ' . $optionServiceLabel;
                                }
                                ?>
                                <option value="<?= htmlspecialchars($optionText) ?>" data-personnel-id="<?= $optionId ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                        <div class="mt-1 text-xs text-gray-500">Tapez un nom, un matricule ou un service puis choisissez la personne à charger.</div>
                    </div>
                    <button type="submit" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-sm hover:bg-indigo-700">Charger</button>
                </form>

                <?php if ($personnelId > 0): ?>
                    <form method="post">
                        <input type="hidden" name="action" value="clear_personnel" />
                        <button type="submit" class="px-4 py-2 rounded-xl bg-white border text-sm hover:bg-gray-50">Réinitialiser</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>


    <div class="mt-4 grid grid-cols-1 xl:grid-cols-[minmax(0,1.7fr)_minmax(300px,0.7fr)] gap-4">
        <div class="bg-white/80 backdrop-blur rounded-2xl border border-gray-200 p-5">
            <div class="flex items-start justify-between flex-wrap gap-3">
                <div>
                    <div class="text-sm text-gray-500">Navigation</div>
                    <div class="font-semibold text-gray-900">
                        <?= htmlspecialchars($rangeStart->format('d/m/Y')) ?> → <?= htmlspecialchars($rangeEnd->format('d/m/Y')) ?>
                    </div>
                    <div class="text-xs text-gray-500 mt-1">Astuce : changez de vue via la barre (Année/Mois/Semaine/Jour).</div>
                </div>

                <form method="get" class="flex items-end gap-2 flex-wrap">
                    <input type="hidden" name="action" value="" />
                    <div>
                        <label class="block text-xs text-gray-500">Vue</label>
                        <select name="period" class="mt-1 px-3 py-2 rounded-xl border bg-white text-sm">
                            <option value="day" <?= $period === 'day' ? 'selected' : '' ?>>Jour</option>
                            <option value="week" <?= $period === 'week' ? 'selected' : '' ?>>Semaine</option>
                            <option value="month" <?= $period === 'month' ? 'selected' : '' ?>>Mois</option>
                            <option value="year" <?= $period === 'year' ? 'selected' : '' ?>>Année</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500">Date</label>
                        <input type="date" name="date" value="<?= htmlspecialchars($dateObj->format('Y-m-d')) ?>" class="mt-1 px-3 py-2 rounded-xl border bg-white text-sm" />
                    </div>
                    <button class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-sm hover:bg-indigo-700">Afficher</button>
                </form>
            </div>

            <?php if ($displayMode === 'calendar'): ?>
                <div class="mt-4 edt-calendar-shell overflow-hidden edt-no-print">
                    <div id="edt-calendar"></div>
                </div>

                <div class="mt-3 flex items-center gap-2 flex-wrap text-xs text-gray-700 edt-no-print">
                    <span class="px-2 py-1 rounded-full bg-slate-100 text-slate-700 border">
                        <i class="fa-solid fa-hand-pointer mr-1"></i>Cliquer = ajouter
                    </span>
                    <span class="px-2 py-1 rounded-full bg-gray-50 text-gray-700 border">
                        <i class="fa-solid fa-arrows-up-down-left-right mr-1"></i>Glisser = déplacer
                    </span>
                    <span class="px-2 py-1 rounded-full bg-gray-50 text-gray-700 border">
                        <i class="fa-solid fa-up-right-and-down-left-from-center mr-1"></i>Étirez = durée
                    </span>
                    <span class="px-2 py-1 rounded-full bg-indigo-50 text-indigo-800 border border-indigo-100">
                        <i class="fa-solid fa-chart-simple mr-1"></i>Suivi RH par tâche et par statut
                    </span>
                    <span class="px-2 py-1 rounded-full bg-amber-50 text-amber-800 border border-amber-100">
                        <i class="fa-solid fa-triangle-exclamation mr-1"></i>Risques = conflits ou surcharge
                    </span>
                </div>

                <div class="mt-2 text-xs text-gray-500 edt-no-print">Note : quand vous changez de vue (Année/Mois/Semaine/Jour) ou utilisez prev/next, la page se recharge pour charger la période depuis la base.</div>
            <?php elseif ($displayMode === 'list'): ?>
                <div id="edt-calendar" class="hidden edt-no-print"></div>
                <div class="mt-4 edt-print-scope">
                    <div class="rounded-2xl border border-slate-200 bg-white overflow-visible">
                        <div class="px-4 py-4 border-b border-slate-200 flex items-start justify-between gap-4 flex-wrap">
                            <div>
                                <div class="text-sm font-semibold text-slate-900">Liste opérationnelle imprimable</div>
                                <div class="text-xs text-slate-500 mt-1">Ordonnancement chronologique des créneaux et tâches planning avec signalement immédiat des risques<?= $riskOnly ? ' • filtre risques actif' : '' ?>.</div>
                            </div>
                            <div class="flex items-center gap-2 flex-wrap edt-no-print">
                                <a href="emploi_du_temps.php?period=<?= htmlspecialchars($period) ?>&amp;date=<?= htmlspecialchars($dateObj->format('Y-m-d')) ?>&amp;display=list<?= $riskOnly ? '' : '&amp;risk_only=1' ?>" class="px-3 py-2 rounded-xl text-sm border <?= $riskOnly ? 'bg-amber-100 text-amber-900 border-amber-200' : 'bg-white hover:bg-gray-50' ?>">
                                    <?= $riskOnly ? 'Risques uniquement actifs' : 'Managers : uniquement les risques' ?>
                                </a>
                                <?php if ($riskOnly): ?>
                                    <a href="emploi_du_temps.php?period=<?= htmlspecialchars($period) ?>&amp;date=<?= htmlspecialchars($dateObj->format('Y-m-d')) ?>&amp;display=list" class="px-3 py-2 rounded-xl bg-white border text-sm hover:bg-gray-50">Voir toute la liste</a>
                                <?php endif; ?>
                                <button type="button" onclick="window.print()" class="px-3 py-2 rounded-xl bg-slate-900 text-white text-sm hover:bg-slate-800">Imprimer</button>
                            </div>
                        </div>
                        <div class="overflow-auto">
                            <table class="min-w-full text-sm edt-operational-table">
                                <thead class="bg-slate-50 text-slate-600">
                                    <tr>
                                        <th class="px-4 py-3 text-left">Type</th>
                                        <th class="px-4 py-3 text-left">Titre</th>
                                        <th class="px-4 py-3 text-left">Début</th>
                                        <th class="px-4 py-3 text-left">Fin</th>
                                        <th class="px-4 py-3 text-left">Statut</th>
                                        <th class="px-4 py-3 text-left">Portée</th>
                                        <th class="px-4 py-3 text-left">Lieu</th>
                                        <th class="px-4 py-3 text-left">Risque</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!$operationalListRows): ?>
                                        <tr>
                                            <td colspan="8" class="px-4 py-6 text-center text-slate-500"><?= $riskOnly ? 'Aucun risque détecté sur la période.' : 'Aucune donnée opérationnelle sur la période.' ?></td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($operationalListRows as $listRow): ?>
                                            <tr class="border-t border-slate-200">
                                                <td class="px-4 py-3 font-medium text-slate-800"><?= htmlspecialchars((string)$listRow['type']) ?></td>
                                                <td class="px-4 py-3 text-slate-900"><?= htmlspecialchars((string)$listRow['title']) ?></td>
                                                <td class="px-4 py-3 text-slate-700"><?= htmlspecialchars((string)$listRow['start']) ?></td>
                                                <td class="px-4 py-3 text-slate-700"><?= htmlspecialchars((string)$listRow['end']) ?></td>
                                                <td class="px-4 py-3 text-slate-700"><?= htmlspecialchars((string)$listRow['status']) ?></td>
                                                <td class="px-4 py-3 text-slate-700"><?= htmlspecialchars((string)$listRow['scope']) ?></td>
                                                <td class="px-4 py-3 text-slate-700"><?= htmlspecialchars((string)$listRow['location'] !== '' ? (string)$listRow['location'] : '-') ?></td>
                                                <td class="px-4 py-3">
                                                    <?php if (!empty($listRow['risk'])): ?>
                                                        <div class="flex flex-wrap gap-2">
                                                            <?php foreach ($listRow['risk'] as $riskLabel): ?>
                                                                <?php $riskDanger = stripos((string)$riskLabel, 'Conflit') !== false || stripos((string)$riskLabel, 'Retard') !== false; ?>
                                                                <span class="edt-risk-badge <?= $riskDanger ? 'edt-risk-badge--danger' : 'edt-risk-badge--warn' ?>"><?= htmlspecialchars((string)$riskLabel) ?></span>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php else: ?>
                                                        <span class="text-slate-400">-</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div id="edt-calendar" class="hidden edt-no-print"></div>
            <?php endif; ?>
        </div>

        <div class="space-y-4">
            <div class="edt-side-card edt-no-print" id="edt-quick-task-card">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="text-xs uppercase tracking-wide text-slate-500">Taches rapides</div>
                        <div class="mt-1 text-sm font-semibold text-slate-900">A planifier</div>
                    </div>
                    <button type="button" id="edt-quick-assignee-toggle" class="inline-flex h-9 w-9 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-600 hover:bg-slate-50" title="Assigner a une personne">
                        <i class="fa-solid fa-user-plus text-xs"></i>
                    </button>
                </div>

                <div id="edt-quick-assignee-panel" class="hidden mt-3 rounded-2xl border border-slate-200 bg-slate-50 p-3">
                    <input type="text" id="edt-quick-assignee-search" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm focus:border-sky-400 focus:outline-none focus:ring-4 focus:ring-sky-100" placeholder="Rechercher un personnel..." autocomplete="off" />
                    <div id="edt-quick-assignee-list" class="mt-2 max-h-56 overflow-auto space-y-1"></div>
                </div>

                <div class="mt-3 rounded-2xl border border-slate-200 bg-white p-2">
                    <textarea id="edt-quick-task-input" rows="2" class="w-full resize-none rounded-xl border-0 bg-slate-50 px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-200" placeholder="Ajouter une tache rapide... Entree pour creer"></textarea>
                    <div class="mt-2 flex items-center justify-between gap-2">
                        <div id="edt-quick-assignee-label" class="min-w-0 truncate text-xs text-slate-500">
                            <?= $personnel ? htmlspecialchars((string)($personnel['nom'] ?? 'Personnel'), ENT_QUOTES, 'UTF-8') : 'Aucun personnel selectionne' ?>
                        </div>
                        <button type="button" id="edt-quick-task-add" class="inline-flex h-8 w-8 items-center justify-center rounded-xl bg-indigo-600 text-white hover:bg-indigo-700" title="Creer la tache">
                            <i class="fa-solid fa-plus text-xs"></i>
                        </button>
                    </div>
                </div>

                <div id="edt-quick-task-error" class="hidden mt-3 rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700"></div>
                <div id="edt-quick-task-success" class="hidden mt-3 rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-700"></div>

                <div id="edt-quick-task-list" class="mt-3 space-y-2" data-empty-label="Aucune tache rapide pour ce planning.">
                    <?php if (empty($quickTaskItems)): ?>
                        <div id="edt-quick-task-empty" class="rounded-2xl border border-dashed border-slate-200 bg-slate-50 px-3 py-4 text-center text-xs text-slate-500">Aucune tache rapide pour ce planning.</div>
                    <?php else: ?>
                        <?php foreach ($quickTaskItems as $quickTask): ?>
                            <div class="edt-quick-task-card rounded-2xl border border-slate-200 bg-white px-3 py-3 shadow-sm" draggable="true" data-quick-task-id="<?= (int)$quickTask['id'] ?>" data-personnel-id="<?= (int)$quickTask['personnel_id'] ?>" data-title="<?= htmlspecialchars((string)$quickTask['title'], ENT_QUOTES, 'UTF-8') ?>">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <div class="break-words text-sm font-semibold text-slate-900"><?= htmlspecialchars((string)$quickTask['title'], ENT_QUOTES, 'UTF-8') ?></div>
                                        <div class="mt-1 text-[11px] text-slate-500">
                                            <?= htmlspecialchars((string)($quickTask['personnel_name'] ?: 'Personnel'), ENT_QUOTES, 'UTF-8') ?>
                                            <?php if (!empty($quickTask['service_label'])): ?>
                                                <span>&middot; <?= htmlspecialchars((string)$quickTask['service_label'], ENT_QUOTES, 'UTF-8') ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <button type="button" class="edt-quick-task-delete inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-lg text-slate-400 hover:bg-rose-50 hover:text-rose-700" title="Retirer">
                                        <i class="fa-solid fa-xmark text-xs"></i>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <button type="button" id="edt-quick-task-bottom-add" class="mt-3 inline-flex w-full items-center justify-center gap-2 rounded-xl border border-dashed border-slate-300 bg-slate-50 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-white">
                    <i class="fa-solid fa-plus text-xs"></i>Nouvelle carte
                </button>

                <div class="mt-3 rounded-xl border border-indigo-100 bg-indigo-50 px-3 py-2 text-xs text-indigo-800">
                    Glissez une carte sur le calendrier pour la positionner. Elle disparaitra de cette zone une fois planifiee.
                </div>
            </div>

            <div class="edt-side-card">
                <div class="text-xs uppercase tracking-wide text-slate-500">Tableau de bord</div>
                <div class="mt-3 grid grid-cols-2 gap-3">
                    <div class="edt-kpi-card">
                        <div class="text-xs text-slate-500">Créneaux terminés</div>
                        <div class="mt-2 text-2xl font-semibold text-slate-900"><?= (int) $kpiSlotsDone ?></div>
                    </div>
                    <div class="edt-kpi-card">
                        <div class="text-xs text-slate-500">En cours</div>
                        <div class="mt-2 text-2xl font-semibold text-slate-900"><?= (int) $kpiSlotsInProgress ?></div>
                    </div>
                    <div class="edt-kpi-card">
                        <div class="text-xs text-slate-500">Heures prévues</div>
                        <div class="mt-2 text-2xl font-semibold text-slate-900"><?= htmlspecialchars(number_format($kpiHoursPlanned, 1, ',', ' ')) ?></div>
                    </div>
                    <div class="edt-kpi-card">
                        <div class="text-xs text-slate-500">Alertes</div>
                        <div class="mt-2 text-2xl font-semibold <?= $kpiAnomalyCount > 0 ? 'text-amber-700' : 'text-emerald-700' ?>"><?= (int) $kpiAnomalyCount ?></div>
                    </div>
                </div>
            </div>

            <div class="edt-side-card">
                <div class="text-xs uppercase tracking-wide text-slate-500">Légende de lecture</div>
                <div class="mt-3 space-y-2 text-sm text-slate-700">
                    <div class="flex items-center justify-between gap-2"><span>Créneau personnel</span><span class="px-2 py-1 rounded-full bg-slate-100 border text-xs">Modifiable</span></div>
                    <div class="flex items-center justify-between gap-2"><span>Tâche suivie</span><span class="px-2 py-1 rounded-full bg-indigo-50 border border-indigo-100 text-indigo-700 text-xs">Lecture seule</span></div>
                    <div class="flex items-center justify-between gap-2"><span>Tâche groupée</span><span class="px-2 py-1 rounded-full bg-sky-50 border border-sky-100 text-sky-700 text-xs">Groupe</span></div>
                    <div class="flex items-center justify-between gap-2"><span>Annulation</span><span class="px-2 py-1 rounded-full bg-rose-50 border border-rose-100 text-rose-700 text-xs">À restaurer si erreur</span></div>
                </div>
            </div>

            <div class="edt-side-card">
                <div class="text-xs uppercase tracking-wide text-slate-500">Points d'action</div>
                <ul class="mt-3 space-y-3 text-sm text-slate-700">
                    <li class="flex items-start gap-2"><i class="fa-solid fa-circle text-[0.45rem] mt-2 text-slate-400"></i><span>Export PDF renforcé pour partage direction avec synthèse et anomalies.</span></li>
                    <li class="flex items-start gap-2"><i class="fa-solid fa-circle text-[0.45rem] mt-2 text-slate-400"></i><span>Notifications planning désormais configurables séparément côté admin.</span></li>
                    <li class="flex items-start gap-2"><i class="fa-solid fa-circle text-[0.45rem] mt-2 text-slate-400"></i><span>Les annulations peuvent être restaurées directement depuis la fiche tâche.</span></li>
                </ul>
            </div>
        </div>
    </div>


</div>

<!-- Modal To-Do -->
<div id="edt-todo-modal" class="fixed inset-0 z-50 hidden" aria-hidden="true">
    <div class="absolute inset-0 bg-black/40" data-edt-todo-close></div>
    <div class="absolute inset-0 flex items-center justify-center p-4">
        <div class="w-full max-w-2xl bg-white rounded-2xl border border-gray-200 shadow-sm">
            <div class="px-5 py-4 border-b border-gray-200 flex items-start justify-between gap-3">
                <div>
                    <div class="text-base font-semibold text-gray-900">To-Do</div>
                    <div class="text-xs text-gray-500 mt-1">Cocher = tâche terminée.</div>
                </div>
                <button type="button" class="px-3 py-2 rounded-xl bg-white border text-xs hover:bg-gray-50" data-edt-todo-close>Fermer</button>
            </div>

            <div class="p-5">
                <div id="edt-todo-error" class="hidden mb-3 text-sm text-rose-700"></div>

                <?php if (empty($todoItems)): ?>
                    <div class="text-sm text-gray-600">Aucun élément à afficher.</div>
                <?php else: ?>
                    <div class="space-y-2">
                        <?php foreach (array_slice($todoItems, 0, 30) as $item):
                            $kind = (string)($item['kind'] ?? '');
                            $readonly = !empty($item['readonly']);
                            $done = !empty($item['done']);
                            $withTime = ($kind === 'personal');
                            $labelKind = $kind === 'microsoft' ? 'microsoft' : 'perso';
                        ?>
                            <div class="flex items-start gap-3 p-3 rounded-2xl border bg-white" data-todo-row>
                                <input
                                    type="checkbox"
                                    class="mt-1"
                                    data-todo
                                    data-kind="<?= htmlspecialchars($kind) ?>"
                                    <?= $kind === 'personal' ? 'data-event-id="' . htmlspecialchars((string)($item['fc_id'] ?? '')) . '"' : '' ?>
                                    <?= $kind === 'microsoft' ? 'data-task-id="' . htmlspecialchars((string)($item['id'] ?? '')) . '" data-list-id="' . htmlspecialchars((string)($item['list_id'] ?? '')) . '"' : '' ?>
                                    <?= $kind === 'microsoft' && !empty($item['etag']) ? 'data-etag="' . htmlspecialchars((string)($item['etag'] ?? '')) . '"' : '' ?>
                                    <?= $done ? 'checked' : '' ?>
                                    <?= $readonly ? 'disabled' : '' ?> />
                                <div class="min-w-0 flex-1 <?= $done ? 'opacity-70' : '' ?>">
                                    <div class="text-sm font-semibold text-gray-900 truncate <?= $done ? 'line-through' : '' ?>">
                                        <?= htmlspecialchars(trim((string)($item['title'] ?? '')) !== '' ? (string)$item['title'] : '(sans titre)') ?>
                                        <span class="text-xs font-normal text-gray-500">(<?= htmlspecialchars($labelKind) ?>)</span>
                                    </div>
                                    <div class="text-xs text-gray-500 mt-1">
                                        <?= htmlspecialchars(fmtHumanDate((string)($item['when'] ?? ''), $withTime)) ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if (count($todoItems) > 30): ?>
                        <div class="mt-3 text-xs text-gray-500">Affichage limité aux 30 premiers éléments.</div>
                    <?php endif; ?>
                <?php endif; ?>

                <div class="mt-5 p-3 rounded-2xl bg-gray-50 border border-gray-200">
                    <div class="flex items-start justify-between gap-3 flex-wrap">
                        <div>
                            <div class="text-sm font-semibold text-gray-900">Microsoft To Do</div>
                            <div class="text-xs text-gray-600 mt-1">
                                <?php if (!$msTodo->isReady()): ?>
                                    Indisponible (stockage tokens non initialisé).
                                <?php elseif (!$msConfigured): ?>
                                    Configuration manquante (tenant/client/secret).
                                <?php elseif ($msConnected): ?>
                                    Connecté.
                                <?php else: ?>
                                    Non connecté.
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($msTodoError)): ?>
                                <div class="text-xs text-rose-700 mt-2"><?= htmlspecialchars((string)$msTodoError) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="flex items-center gap-2">
                            <?php if (!$msTodo->isReady()): ?>
                                <span class="px-3 py-2 rounded-xl bg-gray-100 text-gray-400 text-xs border" title="<?= htmlspecialchars((string)($msTodo->getInitError() ?: 'Module Microsoft To Do indisponible.')) ?>">Connecter</span>
                            <?php elseif (!$msConfigured): ?>
                                <span class="px-3 py-2 rounded-xl bg-gray-100 text-gray-400 text-xs border" title="Renseignez la configuration Microsoft Graph dans config/microsoft_todo.php.">Connecter</span>
                            <?php elseif ($msConnected): ?>
                                <a href="emploi_du_temps.php?action=microsoft_disconnect&amp;period=<?= htmlspecialchars($period) ?>&amp;date=<?= htmlspecialchars($dateObj->format('Y-m-d')) ?>" class="px-3 py-2 rounded-xl bg-white border text-xs hover:bg-gray-50">Déconnecter</a>
                            <?php else: ?>
                                <a href="emploi_du_temps.php?action=microsoft_connect&amp;period=<?= htmlspecialchars($period) ?>&amp;date=<?= htmlspecialchars($dateObj->format('Y-m-d')) ?>" class="px-3 py-2 rounded-xl bg-indigo-600 text-white text-xs hover:bg-indigo-700">Connecter</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal tâche (création/édition) -->
<div id="edt-task-modal" class="fixed inset-0 z-50 hidden" aria-hidden="true">
    <div class="absolute inset-0 bg-black/40" data-edt-close></div>
    <div class="absolute inset-0 flex items-center justify-center p-4">
        <div class="w-full max-w-lg bg-white rounded-2xl border border-gray-200 shadow-sm">
            <div class="px-5 py-4 border-b border-gray-200 flex items-start justify-between gap-3">
                <div>
                    <div id="edt-task-modal-title" class="text-base font-semibold text-gray-900">Nouvelle tâche</div>
                    <div class="text-xs text-gray-500 mt-1">Créneau personnel</div>
                </div>
                <button type="button" class="px-3 py-2 rounded-xl bg-white border text-xs hover:bg-gray-50" data-edt-close>Fermer</button>
            </div>

            <form id="edt-task-form" class="p-5 space-y-3">
                <input type="hidden" id="edt-task-id" value="" />
                <div>
                    <label class="block text-xs text-gray-500">Titre</label>
                    <input id="edt-task-title" class="mt-1 w-full px-3 py-2 rounded-xl border bg-white text-sm" placeholder="Ex: Intervention, Réunion…" required />
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs text-gray-500">Début</label>
                        <input type="datetime-local" id="edt-task-start" class="mt-1 w-full px-3 py-2 rounded-xl border bg-white text-sm" required />
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500">Fin</label>
                        <input type="datetime-local" id="edt-task-end" class="mt-1 w-full px-3 py-2 rounded-xl border bg-white text-sm" required />
                    </div>
                </div>
                <div>
                    <label class="block text-xs text-gray-500">Statut</label>
                    <select id="edt-task-status" class="mt-1 w-full px-3 py-2 rounded-xl border bg-white text-sm">
                        <?php foreach ($statusLabels as $code => $label): ?>
                            <option value="<?= htmlspecialchars($code) ?>" <?= $code === 'planifie' ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs text-gray-500">Affectation</label>
                        <select id="edt-task-assignment-type" class="mt-1 w-full px-3 py-2 rounded-xl border bg-white text-sm">
                            <option value="bureau">Bureau</option>
                            <option value="chantier">Chantier</option>
                        </select>
                    </div>
                    <div id="edt-task-assignment-chantier-wrapper" class="hidden">
                        <label class="block text-xs text-gray-500">Chantier concerne</label>
                        <select id="edt-task-assignment-chantier-id" class="mt-1 w-full px-3 py-2 rounded-xl border bg-white text-sm">
                            <option value="">Selectionner un chantier</option>
                            <?php foreach ($chantierOptions as $chantierOption): ?>
                                <?php
                                $chantierId = (int)($chantierOption['id'] ?? 0);
                                $chantierCode = trim((string)($chantierOption['code'] ?? ''));
                                $chantierTitle = trim((string)($chantierOption['titre'] ?? ''));
                                $chantierLabel = trim($chantierCode . ($chantierCode !== '' && $chantierTitle !== '' ? ' - ' : '') . $chantierTitle);
                                ?>
                                <option value="<?= $chantierId ?>"><?= htmlspecialchars($chantierLabel !== '' ? $chantierLabel : ('Chantier #' . $chantierId)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-xs text-gray-500">Lieu (optionnel)</label>
                    <select id="edt-task-location" class="mt-1 w-full px-3 py-2 rounded-xl border bg-white text-sm">
                        <option value="Bureau" selected>Bureau</option>
                        <option value="Domicile">Domicile</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-500">Description (optionnel)</label>
                    <textarea id="edt-task-description" rows="3" class="mt-1 w-full px-3 py-2 rounded-xl border bg-white text-sm" placeholder="Détails / notes…"></textarea>
                </div>

                <div id="edt-task-group-summary" class="hidden p-3 rounded-xl border border-sky-100 bg-sky-50">
                    <div class="text-xs font-semibold uppercase tracking-wide text-sky-700">Tâche groupée</div>
                    <div id="edt-task-group-summary-text" class="mt-1 text-sm text-slate-700"></div>
                </div>

                <div id="edt-task-group-apply-wrapper" class="hidden p-3 rounded-xl border border-amber-100 bg-amber-50">
                    <label class="inline-flex items-start gap-2 text-sm text-slate-900">
                        <input type="checkbox" id="edt-task-apply-group" class="mt-1 rounded border-gray-300" />
                        <span>
                            <span id="edt-task-apply-group-label" class="font-medium">Appliquer au groupe</span>
                            <span class="block text-xs text-slate-500 mt-1">Utilisez cette option pour modifier ou supprimer toutes les tâches qui partagent la même clé de groupe.</span>
                        </span>
                    </label>
                </div>

                <div id="edt-task-recurrence-delete-wrapper" class="hidden p-3 rounded-xl border border-rose-100 bg-rose-50">
                    <label class="inline-flex items-start gap-2 text-sm text-slate-900">
                        <input type="checkbox" id="edt-task-delete-recurrence" class="mt-1 rounded border-gray-300" />
                        <span>
                            <span class="font-medium">Supprimer toute la repetition</span>
                            <span class="block text-xs text-slate-500 mt-1">Cette option supprimera toutes les occurrences rattachees a cette serie.</span>
                        </span>
                    </label>
                </div>

                <div id="edt-task-transfer-wrapper" class="hidden p-3 rounded-xl border border-indigo-100 bg-indigo-50">
                    <label class="block text-xs text-gray-500">Transferer a</label>
                    <select id="edt-task-transfer-personnel-id" class="mt-1 w-full px-3 py-2 rounded-xl border bg-white text-sm">
                        <option value="">Conserver la personne actuelle</option>
                        <?php foreach ($personnelOptions as $personOption): ?>
                            <?php
                            $optionId = (int)($personOption['id'] ?? 0);
                            $optionName = trim((string)($personOption['nom'] ?? ''));
                            $optionMatricule = trim((string)($personOption['matricule'] ?? ''));
                            $optionServiceLabel = trim((string)($personOption['service_label'] ?? ''));
                            $optionLabel = trim(($optionName !== '' ? $optionName : 'Personnel') . ($optionMatricule !== '' ? ' - ' . $optionMatricule : '') . ($optionServiceLabel !== '' ? ' - ' . $optionServiceLabel : ''));
                            ?>
                            <option value="<?= $optionId ?>"><?= htmlspecialchars($optionLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="mt-1 text-xs text-slate-500">Le transfert est refuse si le creneau chevauche deja une tache de la personne cible.</div>
                </div>

                <div id="edt-task-target-wrapper" class="p-3 rounded-xl border bg-slate-50">
                    <div class="flex items-center justify-between gap-2 flex-wrap">
                        <div>
                            <div class="text-sm font-medium text-gray-900">Portée de la tâche</div>
                            <div class="text-xs text-gray-500 mt-1">Choisissez une personne, plusieurs personnes cochées, un service commun ou toute l'équipe.</div>
                        </div>
                        <span class="text-xs text-slate-500">Création uniquement</span>
                    </div>

                    <div class="mt-3">
                        <label class="block text-xs text-gray-500">Mode de ciblage</label>
                        <select id="edt-task-target-scope" class="mt-1 w-full px-3 py-2 rounded-xl border bg-white text-sm">
                            <option value="single">Personnel du calendrier</option>
                            <option value="selected">Personnes cochées</option>
                            <option value="service">Service / département commun</option>
                            <option value="team">Toute l'équipe</option>
                        </select>
                    </div>

                    <div id="edt-task-target-selected" class="mt-3 hidden">
                        <label class="block text-xs text-gray-500">Personnes à affecter</label>
                        <input type="text" id="edt-task-target-filter" class="mt-1 w-full px-3 py-2 rounded-xl border bg-white text-sm" placeholder="Filtrer par nom, matricule ou service" />
                        <div id="edt-task-target-list" class="mt-2 max-h-48 overflow-auto rounded-xl border bg-white p-2 space-y-2">
                            <?php foreach ($personnelOptions as $personOption): ?>
                                <?php
                                $optionId = (int)($personOption['id'] ?? 0);
                                $optionName = trim((string)($personOption['nom'] ?? ''));
                                $optionMatricule = trim((string)($personOption['matricule'] ?? ''));
                                $optionServiceLabel = trim((string)($personOption['service_label'] ?? ''));
                                $searchLabel = strtolower(trim($optionName . ' ' . $optionMatricule . ' ' . $optionServiceLabel));
                                ?>
                                <label class="flex items-start gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm edt-target-person-row" data-search="<?= htmlspecialchars($searchLabel) ?>">
                                    <input type="checkbox" class="mt-1 rounded border-gray-300 edt-target-person-checkbox" value="<?= $optionId ?>" <?= $optionId === $personnelId ? 'checked' : '' ?> />
                                    <span>
                                        <span class="block font-medium text-slate-900"><?= htmlspecialchars($optionName !== '' ? $optionName : 'Personnel') ?></span>
                                        <span class="block text-xs text-slate-500"><?= htmlspecialchars(trim($optionMatricule . ($optionServiceLabel !== '' ? ' • ' . $optionServiceLabel : ''))) ?></span>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div id="edt-task-target-service" class="mt-3 hidden">
                        <label class="block text-xs text-gray-500">Service / département</label>
                        <select id="edt-task-target-service-id" class="mt-1 w-full px-3 py-2 rounded-xl border bg-white text-sm">
                            <option value="">Sélectionner un service</option>
                            <?php foreach ($serviceOptions as $serviceOption): ?>
                                <option value="<?= (int)($serviceOption['id'] ?? 0) ?>">
                                    <?= htmlspecialchars((string)($serviceOption['label'] ?? 'Service')) ?>
                                    <?php if (!empty($serviceOption['personnel_count'])): ?>
                                        (<?= (int)$serviceOption['personnel_count'] ?>)
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div id="edt-task-repeat-wrapper" class="p-3 rounded-xl border bg-gray-50">
                    <div class="flex items-center justify-between gap-2 flex-wrap">
                        <label class="inline-flex items-center gap-2 text-sm text-gray-900">
                            <input type="checkbox" id="edt-task-repeat-enabled" class="rounded border-gray-300" />
                            <span class="font-medium">Répéter</span>
                        </label>
                        <span class="text-xs text-gray-500">Optionnel</span>
                    </div>

                    <div id="edt-task-repeat-fields" class="mt-3 space-y-3 hidden">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs text-gray-500">Fréquence</label>
                                <select id="edt-task-repeat-type" class="mt-1 w-full px-3 py-2 rounded-xl border bg-white text-sm">
                                    <option value="daily">Tous les jours</option>
                                    <option value="weekly">Toutes les semaines</option>
                                    <option value="monthly">Tous les mois</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs text-gray-500">Tous les… (intervalle)</label>
                                <input type="number" min="1" max="365" id="edt-task-repeat-interval" class="mt-1 w-full px-3 py-2 rounded-xl border bg-white text-sm" value="1" />
                            </div>
                        </div>

                        <div id="edt-task-repeat-weekdays" class="hidden">
                            <label class="block text-xs text-gray-500">Jours (semaine)</label>
                            <div class="mt-2 grid grid-cols-4 md:grid-cols-7 gap-2 text-xs">
                                <?php
                                $days = [
                                    1 => 'Lun',
                                    2 => 'Mar',
                                    3 => 'Mer',
                                    4 => 'Jeu',
                                    5 => 'Ven',
                                    6 => 'Sam',
                                    7 => 'Dim',
                                ];
                                foreach ($days as $d => $lab):
                                ?>
                                    <label class="inline-flex items-center gap-1 px-2 py-1 rounded-lg border bg-white">
                                        <input type="checkbox" class="rounded border-gray-300 edt-weekday" value="<?= (int) $d ?>" />
                                        <span><?= htmlspecialchars($lab) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div id="edt-task-repeat-monthday" class="hidden">
                            <label class="block text-xs text-gray-500">Jour du mois</label>
                            <input type="number" min="1" max="31" id="edt-task-repeat-month-day" class="mt-1 w-full px-3 py-2 rounded-xl border bg-white text-sm" value="1" />
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div class="space-y-2">
                                <label class="inline-flex items-center gap-2 text-sm text-gray-900">
                                    <input type="checkbox" id="edt-task-repeat-exclude-weekends" class="rounded border-gray-300" />
                                    <span>Exclure week-end</span>
                                </label>
                                <label class="inline-flex items-center gap-2 text-sm text-gray-900">
                                    <input type="checkbox" id="edt-task-repeat-exclude-holidays" class="rounded border-gray-300" />
                                    <span>Exclure jours fériés</span>
                                </label>
                            </div>
                            <div>
                                <label class="block text-xs text-gray-500">Fin de répétition (optionnel)</label>
                                <input type="date" id="edt-task-repeat-end-date" class="mt-1 w-full px-3 py-2 rounded-xl border bg-white text-sm" />
                            </div>
                        </div>

                        <div class="text-xs text-gray-500">
                            La répétition crée automatiquement les prochaines occurrences et envoie les notifications selon le paramétrage admin.
                        </div>
                    </div>
                </div>

                <div class="pt-2 flex items-center justify-between gap-2 flex-wrap">
                    <div class="flex items-center gap-2">
                        <button type="button" id="edt-task-btn-done" class="px-4 py-2 rounded-xl bg-emerald-600 text-white text-sm hover:bg-emerald-700">Terminer</button>
                        <button type="button" id="edt-task-btn-cancel" class="px-4 py-2 rounded-xl bg-rose-600 text-white text-sm hover:bg-rose-700">Annuler</button>
                        <button type="button" id="edt-task-btn-restore" class="hidden px-4 py-2 rounded-xl bg-sky-600 text-white text-sm hover:bg-sky-700">Restaurer</button>
                        <button type="button" id="edt-task-btn-delete" class="hidden px-4 py-2 rounded-xl bg-white border border-rose-200 text-rose-700 text-sm hover:bg-rose-50">Supprimer</button>
                    </div>
                    <button type="submit" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-sm hover:bg-indigo-700">Enregistrer</button>
                </div>

                <div id="edt-task-error" class="hidden text-sm text-rose-700"></div>
            </form>
        </div>
    </div>
</div>

<footer class="max-w-7xl mx-auto px-4 pb-8">
    <div class="mt-6 flex items-center justify-between gap-2 flex-wrap text-xs text-gray-500">
        <div>© <?= (int)date('Y') ?> — Banamur • Emploi du temps</div>
        <div class="flex items-center gap-3">
            <a href="<?= htmlspecialchars($rootUrl('selection_portail.php')) ?>" class="hover:text-gray-700">Portail</a>
            <a href="<?= htmlspecialchars($rootUrl('profil.php')) ?>" class="hover:text-gray-700">Profil</a>
            <a href="<?= htmlspecialchars($rootUrl('deconnex.php')) ?>" class="hover:text-gray-700">Déconnexion</a>
        </div>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        var personnelForm = document.getElementById('edt-personnel-form');
        var personnelSearchInput = document.getElementById('edt-personnel-search');
        var personnelIdInput = document.getElementById('edt-personnel-id');
        var personnelOptionNodes = document.querySelectorAll('#edt-personnel-options option');

        if (personnelForm && personnelSearchInput && personnelIdInput && personnelOptionNodes.length) {
            var personnelMap = {};
            personnelOptionNodes.forEach(function(option) {
                var label = String(option.value || '').trim();
                var id = String(option.getAttribute('data-personnel-id') || '').trim();
                if (label !== '' && id !== '') {
                    personnelMap[label] = id;
                }
            });

            var syncPersonnelSelection = function() {
                var typedValue = String(personnelSearchInput.value || '').trim();
                personnelIdInput.value = personnelMap[typedValue] || '';
            };

            personnelSearchInput.addEventListener('input', syncPersonnelSelection);
            personnelSearchInput.addEventListener('change', function() {
                syncPersonnelSelection();
                if (personnelIdInput.value) {
                    personnelForm.submit();
                }
            });

            personnelForm.addEventListener('submit', function(event) {
                syncPersonnelSelection();
                if (!personnelIdInput.value) {
                    event.preventDefault();
                    personnelSearchInput.focus();
                }
            });

            personnelSearchInput.addEventListener('keydown', function(event) {
                if (event.key === 'Enter') {
                    syncPersonnelSelection();
                }
            });

            var calendarEl = document.getElementById('edt-calendar');
            if (!calendarEl || !window.FullCalendar) {
                return;
            }

            var currentPeriod = <?= json_encode($period, JSON_UNESCAPED_UNICODE) ?>;
            // Ancre = date demandée (au centre de la navigation), pas le début de période.
            var currentDate = <?= json_encode($dateObj->format('Y-m-d'), JSON_UNESCAPED_UNICODE) ?>;
            var currentPersonnelId = <?= (int)$personnelId ?>;
            var scheduleReady = <?= json_encode(($personnelId > 0 && $scheduleReady), JSON_UNESCAPED_UNICODE) ?>;
            var quickTasks = <?= json_encode($quickTaskItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
            var quickTaskPersonnel = <?= json_encode($quickTaskPersonnelItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
            var quickAssigneeId = currentPersonnelId;

            function pad2(n) {
                return (n < 10 ? '0' : '') + n;
            }

            function toYmd(d) {
                return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate());
            }

            // Vue annuelle : on préfère un "vrai" calendrier (multi-mois). Fallback en liste si indisponible.
            var yearViewName = 'multiMonthYear';

            function viewToPeriod(viewType) {
                if (viewType === yearViewName || viewType === 'listYear') return 'year';
                if (viewType === 'dayGridMonth') return 'month';
                if (viewType === 'timeGridWeek') return 'week';
                if (viewType === 'timeGridDay') return 'day';
                return 'week';
            }

            function periodToView(period) {
                if (period === 'year') return yearViewName;
                if (period === 'month') return 'dayGridMonth';
                if (period === 'week') return 'timeGridWeek';
                if (period === 'day') return 'timeGridDay';
                return 'timeGridWeek';
            }

            function toLocalDateTime(d) {
                if (!d) return '';
                return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate()) + 'T' + pad2(d.getHours()) + ':' + pad2(d.getMinutes());
            }

            function addMinutes(date, minutes) {
                return new Date(date.getTime() + (minutes * 60000));
            }

            function escapeHtml(value) {
                return String(value || '').replace(/[&<>"']/g, function(char) {
                    return ({
                        '&': '&amp;',
                        '<': '&lt;',
                        '>': '&gt;',
                        '"': '&quot;',
                        "'": '&#039;'
                    })[char] || char;
                });
            }

            function quickTaskMeta(task) {
                var parts = [];
                if (task && task.personnel_name) parts.push(task.personnel_name);
                if (task && task.service_label) parts.push(task.service_label);
                return parts.length ? parts.join(' - ') : 'Personnel';
            }

            function quickTaskCardHtml(task) {
                return '<div class="edt-quick-task-card rounded-2xl border border-slate-200 bg-white px-3 py-3 shadow-sm" draggable="true" data-quick-task-id="' + escapeHtml(task.id) + '" data-personnel-id="' + escapeHtml(task.personnel_id) + '" data-title="' + escapeHtml(task.title) + '">' +
                    '<div class="flex items-start justify-between gap-2">' +
                    '<div class="min-w-0">' +
                    '<div class="break-words text-sm font-semibold text-slate-900">' + escapeHtml(task.title) + '</div>' +
                    '<div class="mt-1 text-[11px] text-slate-500">' + escapeHtml(quickTaskMeta(task)) + '</div>' +
                    '</div>' +
                    '<button type="button" class="edt-quick-task-delete inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-lg text-slate-400 hover:bg-rose-50 hover:text-rose-700" title="Retirer">' +
                    '<i class="fa-solid fa-xmark text-xs"></i>' +
                    '</button>' +
                    '</div>' +
                    '</div>';
            }

            function setQuickNotice(type, message) {
                var errorEl = document.getElementById('edt-quick-task-error');
                var successEl = document.getElementById('edt-quick-task-success');
                if (errorEl) {
                    errorEl.classList.add('hidden');
                    errorEl.textContent = '';
                }
                if (successEl) {
                    successEl.classList.add('hidden');
                    successEl.textContent = '';
                }
                var target = type === 'error' ? errorEl : successEl;
                if (target && message) {
                    target.textContent = message;
                    target.classList.remove('hidden');
                }
            }

            function renderQuickTaskList() {
                var list = document.getElementById('edt-quick-task-list');
                if (!list) return;
                var visibleTasks = quickTasks.filter(function(task) {
                    return parseInt(task.personnel_id || 0, 10) === currentPersonnelId;
                });
                if (!visibleTasks.length) {
                    list.innerHTML = '<div id="edt-quick-task-empty" class="rounded-2xl border border-dashed border-slate-200 bg-slate-50 px-3 py-4 text-center text-xs text-slate-500">' + escapeHtml(list.getAttribute('data-empty-label') || 'Aucune tache rapide.') + '</div>';
                    return;
                }
                list.innerHTML = visibleTasks.map(quickTaskCardHtml).join('');
            }

            function selectedQuickAssignee() {
                for (var i = 0; i < quickTaskPersonnel.length; i++) {
                    if (parseInt(quickTaskPersonnel[i].id || 0, 10) === quickAssigneeId) {
                        return quickTaskPersonnel[i];
                    }
                }
                return null;
            }

            function updateQuickAssigneeLabel() {
                var label = document.getElementById('edt-quick-assignee-label');
                var selected = selectedQuickAssignee();
                if (label) {
                    label.textContent = selected ? (selected.name + (selected.service_label ? ' - ' + selected.service_label : '')) : 'Aucun personnel selectionne';
                }
            }

            function renderQuickAssigneeList() {
                var list = document.getElementById('edt-quick-assignee-list');
                var search = document.getElementById('edt-quick-assignee-search');
                if (!list) return;
                var query = search ? String(search.value || '').trim().toLowerCase() : '';
                var matches = quickTaskPersonnel.filter(function(person) {
                    return query === '' || String(person.search || '').indexOf(query) !== -1;
                }).slice(0, 80);
                if (!matches.length) {
                    list.innerHTML = '<div class="rounded-xl border border-dashed border-slate-200 bg-white px-3 py-3 text-xs text-slate-500">Aucun personnel trouve.</div>';
                    return;
                }
                list.innerHTML = matches.map(function(person) {
                    var selected = parseInt(person.id || 0, 10) === quickAssigneeId;
                    return '<button type="button" class="edt-quick-person-row flex w-full items-start gap-2 rounded-xl border px-3 py-2 text-left text-sm ' + (selected ? 'is-selected' : 'border-slate-200 bg-white hover:bg-slate-50') + '" data-personnel-id="' + escapeHtml(person.id) + '">' +
                        '<span class="mt-1 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-lg bg-indigo-50 text-indigo-700"><i class="fa-solid fa-user text-[11px]"></i></span>' +
                        '<span class="min-w-0"><span class="block truncate font-semibold text-slate-900">' + escapeHtml(person.name) + '</span>' +
                        '<span class="block truncate text-xs text-slate-500">' + escapeHtml([person.matricule, person.service_label].filter(Boolean).join(' - ')) + '</span></span>' +
                        '</button>';
                }).join('');
            }

            function createQuickTaskFromInput() {
                setQuickNotice('', '');
                var input = document.getElementById('edt-quick-task-input');
                if (!input) return;
                var title = String(input.value || '').trim();
                if (!title) {
                    input.focus();
                    return;
                }
                if (!quickAssigneeId) {
                    setQuickNotice('error', 'Choisissez une personne a assigner.');
                    return;
                }

                fetch('api/create_employee_schedule_quick_task.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({
                        personnel_id: quickAssigneeId,
                        title: title
                    })
                }).then(function(r) {
                    return r.json();
                }).then(function(data) {
                    if (!data || !data.ok) {
                        setQuickNotice('error', (data && data.error) ? data.error : 'Creation rapide impossible.');
                        return;
                    }
                    input.value = '';
                    if (data.item && parseInt(data.item.personnel_id || 0, 10) === currentPersonnelId) {
                        quickTasks.unshift(data.item);
                        renderQuickTaskList();
                    } else {
                        setQuickNotice('success', 'Tache assignee. Elle apparaitra sur le planning de la personne choisie.');
                    }
                    input.focus();
                }).catch(function() {
                    setQuickNotice('error', 'Erreur reseau : creation rapide impossible.');
                });
            }

            function removeQuickTask(id, personnelId) {
                fetch('api/delete_employee_schedule_quick_task.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({
                        id: id,
                        personnel_id: personnelId || currentPersonnelId
                    })
                }).then(function(r) {
                    return r.json();
                }).then(function(data) {
                    if (!data || !data.ok) {
                        setQuickNotice('error', (data && data.error) ? data.error : 'Suppression impossible.');
                        return;
                    }
                    quickTasks = quickTasks.filter(function(task) {
                        return parseInt(task.id || 0, 10) !== parseInt(id || 0, 10);
                    });
                    renderQuickTaskList();
                }).catch(function() {
                    setQuickNotice('error', 'Erreur reseau : suppression impossible.');
                });
            }

            function scheduleQuickTask(quickTaskId, personnelId, start, end, tempEvent) {
                if (!quickTaskId || !start) {
                    if (tempEvent) tempEvent.remove();
                    return;
                }
                if (!end || end <= start) {
                    end = addMinutes(start, 60);
                }
                fetch('api/schedule_employee_schedule_quick_task.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({
                        quick_task_id: quickTaskId,
                        personnel_id: personnelId || currentPersonnelId,
                        start_at: toLocalDateTime(start),
                        end_at: toLocalDateTime(end)
                    })
                }).then(function(r) {
                    return r.json();
                }).then(function(data) {
                    if (tempEvent) tempEvent.remove();
                    if (!data || !data.ok) {
                        setQuickNotice('error', (data && data.error) ? data.error : 'Planification impossible.');
                        return;
                    }
                    quickTasks = quickTasks.filter(function(task) {
                        return parseInt(task.id || 0, 10) !== parseInt(quickTaskId || 0, 10);
                    });
                    renderQuickTaskList();
                    if (data.item && calendar) {
                        calendar.addEvent(data.item);
                    } else if (data.refresh_required) {
                        window.location.reload();
                    }
                }).catch(function() {
                    if (tempEvent) tempEvent.remove();
                    setQuickNotice('error', 'Erreur reseau : planification impossible.');
                });
            }

            function getStatusCode(event) {
                return (event && event.extendedProps) ? (event.extendedProps.status_code || '') : '';
            }

            function getDescription(event) {
                return (event && event.extendedProps) ? (event.extendedProps.description || '') : '';
            }

            function getLocation(event) {
                return (event && event.extendedProps) ? (event.extendedProps.location || '') : '';
            }

            function getAssignmentType(event) {
                return (event && event.extendedProps) ? (event.extendedProps.assignment_type || 'bureau') : 'bureau';
            }

            function getAssignmentChantierId(event) {
                return (event && event.extendedProps) ? (event.extendedProps.assignment_chantier_id || '') : '';
            }

            function getRecurrenceRuleId(event) {
                return (event && event.extendedProps) ? parseInt(event.extendedProps.recurrence_rule_id || '0', 10) : 0;
            }

            function getGroupKey(event) {
                return (event && event.extendedProps) ? (event.extendedProps.event_group_key || '') : '';
            }

            function getGroupScopeLabel(event) {
                return (event && event.extendedProps) ? (event.extendedProps.group_scope_label || '') : '';
            }

            function isRiskConflict(event) {
                return !!(event && event.extendedProps && event.extendedProps.risk_conflict);
            }

            function isRiskOverload(event) {
                return !!(event && event.extendedProps && event.extendedProps.risk_overload);
            }

            function isCancelledStatus(statusCode) {
                return String(statusCode || '') === 'annule';
            }

            function updateStatusButtons(statusCode, mode) {
                var isEdit = (mode === 'edit');
                var cancelled = isCancelledStatus(statusCode);

                if (btnDone) btnDone.classList.toggle('hidden', !isEdit);
                if (btnDelete) btnDelete.classList.toggle('hidden', !isEdit);
                if (btnCancel) btnCancel.classList.toggle('hidden', !isEdit || cancelled);
                if (btnRestore) btnRestore.classList.toggle('hidden', !isEdit || !cancelled);
            }

            function updateGroupEditingState(ev) {
                var groupKey = getGroupKey(ev);
                var scopeLabel = getGroupScopeLabel(ev);
                var hasGroup = groupKey !== '';

                if (groupSummary) {
                    groupSummary.classList.toggle('hidden', !hasGroup);
                }
                if (groupSummaryText) {
                    groupSummaryText.textContent = hasGroup ? ('Portée : ' + (scopeLabel || 'Groupe') + ' • Clé : ' + groupKey) : '';
                }
                if (applyGroupWrapper) {
                    applyGroupWrapper.classList.toggle('hidden', !hasGroup);
                }
                if (applyGroupCheckbox) {
                    applyGroupCheckbox.checked = false;
                }
                if (applyGroupLabel) {
                    applyGroupLabel.textContent = hasGroup ? ('Appliquer à tout le groupe' + (scopeLabel ? ' (' + scopeLabel + ')' : '')) : 'Appliquer au groupe';
                }
            }

            function isPersonalEvent(event) {
                return !!(event && event.extendedProps && event.extendedProps.source === 'personal');
            }

            function persistEventMove(info) {
                if (!info || !info.event || !isPersonalEvent(info.event)) {
                    if (info && typeof info.revert === 'function') {
                        info.revert();
                    }
                    return;
                }

                var ev = info.event;
                var startAt = toLocalDateTime(ev.start);
                var endAt = toLocalDateTime(ev.end);
                var applyToGroup = false;

                if (getGroupKey(ev)) {
                    applyToGroup = window.confirm('OK = déplacer tout le groupe. Annuler = déplacer seulement cette tâche.');
                }

                if (!startAt || !endAt) {
                    if (typeof info.revert === 'function') {
                        info.revert();
                    }
                    alert('Déplacement impossible : dates/heures invalides.');
                    return;
                }

                fetch('api/update_employee_schedule_event.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({
                        id: ev.id,
                        start_at: startAt,
                        end_at: endAt,
                        apply_to_group: applyToGroup
                    })
                }).then(function(r) {
                    if (!r.ok) {
                        throw new Error('HTTP ' + r.status + ': ' + r.statusText);
                    }
                    return r.json().catch(function(e) {
                        throw new Error('Réponse JSON invalide: ' + e.message);
                    });
                }).then(function(data) {
                    if (!data || !data.ok) {
                        if (typeof info.revert === 'function') {
                            info.revert();
                        }
                        var msg = (data && data.error) ? data.error : 'Mise à jour impossible.';
                        alert(msg);
                        return;
                    }

                    if (data.refresh_required) {
                        window.location.reload();
                    }
                }).catch(function(e) {
                    if (typeof info.revert === 'function') {
                        info.revert();
                    }
                    alert('Erreur réseau : mise à jour impossible. Détails: ' + e.message);
                });
            }

            var didInit = false;

            // Modal
            var modalEl = document.getElementById('edt-task-modal');
            var modalTitleEl = document.getElementById('edt-task-modal-title');
            var formEl = document.getElementById('edt-task-form');
            var fieldId = document.getElementById('edt-task-id');
            var fieldTitle = document.getElementById('edt-task-title');
            var fieldStart = document.getElementById('edt-task-start');
            var fieldEnd = document.getElementById('edt-task-end');
            var fieldStatus = document.getElementById('edt-task-status');
            var fieldLocation = document.getElementById('edt-task-location');
            var fieldAssignmentType = document.getElementById('edt-task-assignment-type');
            var fieldAssignmentChantier = document.getElementById('edt-task-assignment-chantier-id');
            var assignmentChantierWrapper = document.getElementById('edt-task-assignment-chantier-wrapper');
            var fieldDescription = document.getElementById('edt-task-description');
            var errorEl = document.getElementById('edt-task-error');
            var btnDone = document.getElementById('edt-task-btn-done');
            var btnCancel = document.getElementById('edt-task-btn-cancel');
            var btnRestore = document.getElementById('edt-task-btn-restore');
            var btnDelete = document.getElementById('edt-task-btn-delete');
            var groupSummary = document.getElementById('edt-task-group-summary');
            var groupSummaryText = document.getElementById('edt-task-group-summary-text');
            var applyGroupWrapper = document.getElementById('edt-task-group-apply-wrapper');
            var applyGroupCheckbox = document.getElementById('edt-task-apply-group');
            var applyGroupLabel = document.getElementById('edt-task-apply-group-label');
            var recurrenceDeleteWrapper = document.getElementById('edt-task-recurrence-delete-wrapper');
            var deleteRecurrenceCheckbox = document.getElementById('edt-task-delete-recurrence');
            var transferWrapper = document.getElementById('edt-task-transfer-wrapper');
            var transferPersonnel = document.getElementById('edt-task-transfer-personnel-id');
            var targetWrapper = document.getElementById('edt-task-target-wrapper');
            var targetScope = document.getElementById('edt-task-target-scope');
            var targetSelected = document.getElementById('edt-task-target-selected');
            var targetService = document.getElementById('edt-task-target-service');
            var targetServiceId = document.getElementById('edt-task-target-service-id');
            var targetFilter = document.getElementById('edt-task-target-filter');

            var repeatWrapper = document.getElementById('edt-task-repeat-wrapper');
            var repeatEnabled = document.getElementById('edt-task-repeat-enabled');
            var repeatFields = document.getElementById('edt-task-repeat-fields');
            var repeatType = document.getElementById('edt-task-repeat-type');
            var repeatInterval = document.getElementById('edt-task-repeat-interval');
            var repeatWeekdays = document.getElementById('edt-task-repeat-weekdays');
            var repeatMonthday = document.getElementById('edt-task-repeat-monthday');
            var repeatMonthDayInput = document.getElementById('edt-task-repeat-month-day');
            var repeatExcludeWeekends = document.getElementById('edt-task-repeat-exclude-weekends');
            var repeatExcludeHolidays = document.getElementById('edt-task-repeat-exclude-holidays');
            var repeatEndDate = document.getElementById('edt-task-repeat-end-date');

            function setRepeatFieldsVisibility() {
                if (!repeatFields || !repeatEnabled) return;

                var on = !!repeatEnabled.checked;
                repeatFields.classList.toggle('hidden', !on);

                var t = repeatType ? (repeatType.value || 'daily') : 'daily';
                if (repeatWeekdays) {
                    repeatWeekdays.classList.toggle('hidden', !(on && t === 'weekly'));
                }
                if (repeatMonthday) {
                    repeatMonthday.classList.toggle('hidden', !(on && t === 'monthly'));
                }
            }

            function setTargetFieldsVisibility(mode) {
                var allow = (mode !== 'edit');
                if (targetWrapper) {
                    targetWrapper.classList.toggle('hidden', !allow);
                }
                if (!allow || !targetScope) {
                    return;
                }

                var scope = targetScope.value || 'single';
                if (targetSelected) {
                    targetSelected.classList.toggle('hidden', scope !== 'selected');
                }
                if (targetService) {
                    targetService.classList.toggle('hidden', scope !== 'service');
                }
            }

            function setAssignmentFieldsVisibility() {
                var isChantier = fieldAssignmentType && fieldAssignmentType.value === 'chantier';
                if (assignmentChantierWrapper) {
                    assignmentChantierWrapper.classList.toggle('hidden', !isChantier);
                }
                if (!isChantier && fieldAssignmentChantier) {
                    fieldAssignmentChantier.value = '';
                }
            }

            function filterTargetRows() {
                if (!targetFilter) {
                    return;
                }
                var query = String(targetFilter.value || '').trim().toLowerCase();
                var rows = document.querySelectorAll('.edt-target-person-row');
                rows.forEach(function(row) {
                    var haystack = String(row.getAttribute('data-search') || '');
                    var visible = (query === '' || haystack.indexOf(query) !== -1);
                    row.classList.toggle('hidden', !visible);
                });
            }

            function setRepeatEnabledForMode(mode) {
                // La récurrence est gérée uniquement lors de la création (MVP)
                var allow = (mode !== 'edit');

                if (repeatWrapper) {
                    repeatWrapper.classList.toggle('hidden', !allow);
                }

                var controls = [repeatEnabled, repeatType, repeatInterval, repeatMonthDayInput, repeatExcludeWeekends, repeatExcludeHolidays, repeatEndDate];
                for (var i = 0; i < controls.length; i++) {
                    if (controls[i]) controls[i].disabled = !allow;
                }

                var weekdayInputs = document.querySelectorAll('.edt-weekday');
                for (var j = 0; j < weekdayInputs.length; j++) {
                    weekdayInputs[j].disabled = !allow;
                }

                if (!allow) {
                    if (repeatEnabled) repeatEnabled.checked = false;
                    if (repeatEndDate) repeatEndDate.value = '';
                    setRepeatFieldsVisibility();
                }
            }

            function showError(msg) {
                if (!errorEl) return;
                if (!msg) {
                    errorEl.classList.add('hidden');
                    errorEl.textContent = '';
                    return;
                }
                errorEl.textContent = msg;
                errorEl.classList.remove('hidden');
            }

            function openModal(mode) {
                if (!modalEl) return;
                modalEl.classList.remove('hidden');
                modalEl.setAttribute('aria-hidden', 'false');
                modalEl.dataset.mode = mode || 'create';
                showError('');
                setRepeatEnabledForMode(modalEl.dataset.mode);
                setRepeatFieldsVisibility();
                setTimeout(function() {
                    if (fieldTitle) fieldTitle.focus();
                }, 0);
            }

            function closeModal() {
                if (!modalEl) return;
                modalEl.classList.add('hidden');
                modalEl.setAttribute('aria-hidden', 'true');
                showError('');
            }

            if (modalEl) {
                modalEl.addEventListener('click', function(e) {
                    var t = e.target;
                    if (t && t.hasAttribute && t.hasAttribute('data-edt-close')) {
                        closeModal();
                    }
                });
                document.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape' && !modalEl.classList.contains('hidden')) {
                        closeModal();
                    }
                });
            }

            function fillFormFromDates(startDate, endDate, defaults) {
                currentEditingEvent = null;
                if (fieldId) fieldId.value = '';
                if (modalTitleEl) modalTitleEl.textContent = 'Nouvelle tâche';
                if (fieldTitle) fieldTitle.value = '';
                if (fieldStart) fieldStart.value = toLocalDateTime(startDate);
                if (fieldEnd) fieldEnd.value = toLocalDateTime(endDate);
                if (fieldStatus) fieldStatus.value = (defaults && defaults.status_code) ? defaults.status_code : 'planifie';
                if (fieldLocation) fieldLocation.value = '';
                if (fieldAssignmentType) fieldAssignmentType.value = 'bureau';
                if (fieldAssignmentChantier) fieldAssignmentChantier.value = '';
                if (transferPersonnel) transferPersonnel.value = '';
                if (transferWrapper) transferWrapper.classList.add('hidden');
                if (deleteRecurrenceCheckbox) deleteRecurrenceCheckbox.checked = false;
                if (recurrenceDeleteWrapper) recurrenceDeleteWrapper.classList.add('hidden');
                if (fieldDescription) fieldDescription.value = '';
                updateGroupEditingState(null);
                updateStatusButtons(fieldStatus ? fieldStatus.value : 'planifie', 'create');

                if (repeatEnabled) repeatEnabled.checked = false;
                if (repeatType) repeatType.value = 'daily';
                if (repeatInterval) repeatInterval.value = '1';
                if (repeatExcludeWeekends) repeatExcludeWeekends.checked = false;
                if (repeatExcludeHolidays) repeatExcludeHolidays.checked = false;
                if (repeatEndDate) repeatEndDate.value = '';
                if (repeatMonthDayInput && startDate) {
                    repeatMonthDayInput.value = String(startDate.getDate());
                }
                var weekdayInputs = document.querySelectorAll('.edt-weekday');
                for (var i = 0; i < weekdayInputs.length; i++) {
                    weekdayInputs[i].checked = false;
                }
                if (targetScope) targetScope.value = 'single';
                if (targetServiceId) targetServiceId.value = '';
                if (targetFilter) targetFilter.value = '';
                var targetCheckboxes = document.querySelectorAll('.edt-target-person-checkbox');
                for (var j = 0; j < targetCheckboxes.length; j++) {
                    targetCheckboxes[j].checked = (parseInt(targetCheckboxes[j].value || '0', 10) === <?= (int)$personnelId ?>);
                }
                setRepeatEnabledForMode('create');
                setRepeatFieldsVisibility();
                setAssignmentFieldsVisibility();
                setTargetFieldsVisibility('create');
                filterTargetRows();
            }

            var currentEditingEvent = null;

            function fillFormFromEvent(ev) {
                currentEditingEvent = ev;
                if (fieldId) fieldId.value = ev.id || '';
                if (modalTitleEl) modalTitleEl.textContent = 'Modifier la tâche';
                if (fieldTitle) fieldTitle.value = ev.title || '';
                if (fieldStart) fieldStart.value = toLocalDateTime(ev.start);
                if (fieldEnd) fieldEnd.value = toLocalDateTime(ev.end);
                if (fieldStatus) fieldStatus.value = getStatusCode(ev) || 'planifie';
                if (fieldLocation) fieldLocation.value = getLocation(ev) || '';
                if (fieldAssignmentType) fieldAssignmentType.value = getAssignmentType(ev) === 'chantier' ? 'chantier' : 'bureau';
                if (fieldAssignmentChantier) fieldAssignmentChantier.value = String(getAssignmentChantierId(ev) || '');
                if (transferPersonnel) transferPersonnel.value = '';
                if (transferWrapper) transferWrapper.classList.remove('hidden');
                if (deleteRecurrenceCheckbox) deleteRecurrenceCheckbox.checked = false;
                if (recurrenceDeleteWrapper) recurrenceDeleteWrapper.classList.toggle('hidden', getRecurrenceRuleId(ev) <= 0);
                if (fieldDescription) fieldDescription.value = getDescription(ev) || '';
                updateGroupEditingState(ev);
                updateStatusButtons(fieldStatus ? fieldStatus.value : 'planifie', 'edit');

                setRepeatEnabledForMode('edit');
                setAssignmentFieldsVisibility();
                setTargetFieldsVisibility('edit');
            }

            function getFormValue(el) {
                return el ? (el.value || '') : '';
            }

            function saveFromModal() {
                showError('');

                var mode = modalEl ? (modalEl.dataset.mode || 'create') : 'create';
                var id = getFormValue(fieldId);
                var title = getFormValue(fieldTitle).trim();
                var startAt = getFormValue(fieldStart).trim();
                var endAt = getFormValue(fieldEnd).trim();
                var statusCode = getFormValue(fieldStatus).trim() || 'planifie';
                var location = getFormValue(fieldLocation);
                var assignmentType = fieldAssignmentType ? (fieldAssignmentType.value || 'bureau') : 'bureau';
                var assignmentChantierId = fieldAssignmentChantier ? parseInt(fieldAssignmentChantier.value || '0', 10) : 0;
                var description = getFormValue(fieldDescription);
                var scope = targetScope ? (targetScope.value || 'single') : 'single';

                if (!title) {
                    showError('Le titre est requis.');
                    return;
                }
                if (!startAt || !endAt) {
                    showError('Début/fin requis.');
                    return;
                }

                if (mode === 'edit' && (!id || !currentEditingEvent)) {
                    showError('Tâche invalide.');
                    return;
                }

                if (mode !== 'edit' && repeatEnabled && repeatEnabled.checked && scope !== 'single') {
                    showError('La répétition est limitée à une tâche individuelle.');
                    return;
                }

                if (assignmentType === 'chantier' && !assignmentChantierId) {
                    showError('Selectionnez le chantier concerne.');
                    return;
                }

                var url = (mode === 'edit') ? 'api/update_employee_schedule_event.php' : 'api/create_employee_schedule_event.php';
                var payload = {
                    title: title,
                    start_at: startAt,
                    end_at: endAt,
                    status_code: statusCode,
                    location: location,
                    assignment_type: assignmentType,
                    assignment_chantier_id: assignmentType === 'chantier' ? assignmentChantierId : 0,
                    description: description
                };
                if (mode === 'edit') {
                    payload.id = id;
                    payload.apply_to_group = !!(applyGroupCheckbox && applyGroupCheckbox.checked);
                    var transferId = transferPersonnel ? parseInt(transferPersonnel.value || '0', 10) : 0;
                    if (transferId > 0) {
                        payload.transfer_personnel_id = transferId;
                    }
                } else {
                    payload.target_scope = scope;
                    if (scope === 'selected') {
                        var selectedPeople = [];
                        var selectedLabels = [];
                        var checkedBoxes = document.querySelectorAll('.edt-target-person-checkbox:checked');
                        checkedBoxes.forEach(function(box) {
                            var personId = parseInt(box.value || '0', 10);
                            if (personId > 0) {
                                selectedPeople.push(personId);
                                var labelNode = box.closest('.edt-target-person-row');
                                selectedLabels.push(labelNode ? String(labelNode.textContent || '').trim() : String(personId));
                            }
                        });
                        if (!selectedPeople.length) {
                            showError('Cochez au moins une personne pour la tâche groupée.');
                            return;
                        }
                        payload.target_personnel_ids = selectedPeople;
                        payload.target_scope_label = 'Sélection manuelle (' + selectedPeople.length + ' personne(s))';
                    } else if (scope === 'service') {
                        var selectedServiceIdValue = targetServiceId ? parseInt(targetServiceId.value || '0', 10) : 0;
                        if (!selectedServiceIdValue) {
                            showError('Sélectionnez un service pour la tâche groupée.');
                            return;
                        }
                        payload.target_service_id = selectedServiceIdValue;
                        payload.target_scope_label = targetServiceId && targetServiceId.options[targetServiceId.selectedIndex] ? String(targetServiceId.options[targetServiceId.selectedIndex].text || '').trim() : 'Service';
                    } else if (scope === 'team') {
                        payload.target_scope_label = 'Toute l\'équipe';
                    } else {
                        payload.target_scope_label = 'Affectation individuelle';
                    }

                    // récurrence uniquement en création
                    if (repeatEnabled && repeatEnabled.checked) {
                        var t = repeatType ? (repeatType.value || 'daily') : 'daily';
                        var intervalVal = repeatInterval ? parseInt(repeatInterval.value || '1', 10) : 1;
                        if (!intervalVal || intervalVal < 1) intervalVal = 1;

                        var weekDays = [];
                        if (t === 'weekly') {
                            var wInputs = document.querySelectorAll('.edt-weekday');
                            for (var i = 0; i < wInputs.length; i++) {
                                if (wInputs[i].checked) {
                                    weekDays.push(parseInt(wInputs[i].value, 10));
                                }
                            }
                        }

                        var monthDay = null;
                        if (t === 'monthly') {
                            monthDay = repeatMonthDayInput ? parseInt(repeatMonthDayInput.value || '1', 10) : 1;
                            if (!monthDay || monthDay < 1) monthDay = 1;
                            if (monthDay > 31) monthDay = 31;
                        }

                        payload.recurrence = {
                            enabled: true,
                            type: t,
                            interval: intervalVal,
                            week_days: weekDays,
                            month_day: monthDay,
                            exclude_weekends: !!(repeatExcludeWeekends && repeatExcludeWeekends.checked),
                            exclude_holidays: !!(repeatExcludeHolidays && repeatExcludeHolidays.checked),
                            end_date: repeatEndDate ? (repeatEndDate.value || '') : ''
                        };
                    }
                }

                fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify(payload)
                }).then(function(r) {
                    if (!r.ok) {
                        showError('Erreur serveur : ' + r.status + ' ' + r.statusText);
                        return;
                    }
                    return r.json().catch(function() {
                        showError('Réponse invalide du serveur.');
                        return null;
                    });
                }).then(function(data) {
                    if (data === null) return; // already handled
                    if (!data || !data.ok) {
                        showError((data && data.error) ? data.error : 'Enregistrement impossible.');
                        return;
                    }

                    if (mode === 'create') {
                        if (data.refresh_required) {
                            closeModal();
                            window.location.reload();
                            return;
                        }
                        if (!data.item) {
                            closeModal();
                            window.location.reload();
                            return;
                        }
                        calendar.addEvent(data.item);
                        closeModal();
                        return;
                    }

                    // edit: mise à jour locale de l'événement
                    if (data.refresh_required) {
                        closeModal();
                        window.location.reload();
                        return;
                    }
                    currentEditingEvent.setProp('title', title);
                    currentEditingEvent.setStart(startAt);
                    currentEditingEvent.setEnd(endAt);
                    var savedStatusCode = data.status_code || statusCode;
                    currentEditingEvent.setExtendedProp('status_code', savedStatusCode);
                    currentEditingEvent.setExtendedProp('location', location);
                    currentEditingEvent.setExtendedProp('assignment_type', assignmentType);
                    currentEditingEvent.setExtendedProp('assignment_chantier_id', assignmentType === 'chantier' ? assignmentChantierId : 0);
                    currentEditingEvent.setExtendedProp('description', description);
                    closeModal();
                }).catch(function() {
                    showError('Erreur réseau : enregistrement impossible.');
                });
            }

            if (formEl) {
                formEl.addEventListener('submit', function(e) {
                    e.preventDefault();
                    saveFromModal();
                });
            }

            if (btnDone) {
                btnDone.addEventListener('click', function() {
                    if (fieldStatus) fieldStatus.value = 'termine';
                    updateStatusButtons('termine', 'edit');
                    saveFromModal();
                });
            }
            if (btnCancel) {
                btnCancel.addEventListener('click', function() {
                    if (fieldStatus) fieldStatus.value = 'annule';
                    updateStatusButtons('annule', 'edit');
                    saveFromModal();
                });
            }
            if (btnRestore) {
                btnRestore.addEventListener('click', function() {
                    if (fieldStatus) fieldStatus.value = 'planifie';
                    updateStatusButtons('planifie', 'edit');
                    saveFromModal();
                });
            }
            if (btnDelete) {
                btnDelete.addEventListener('click', function() {
                    if (!currentEditingEvent) {
                        showError('Tâche invalide.');
                        return;
                    }

                    var applyToGroup = !!(applyGroupCheckbox && applyGroupCheckbox.checked);
                    var applyToRecurrence = !!(deleteRecurrenceCheckbox && deleteRecurrenceCheckbox.checked);
                    var confirmation = applyToGroup ? 'Supprimer toutes les tâches de ce groupe ?' : 'Supprimer cette tâche ?';
                    if (applyToRecurrence) {
                        confirmation = 'Supprimer toute la repetition de cette tache ?';
                    }
                    if (!window.confirm(confirmation)) {
                        return;
                    }

                    fetch('api/delete_employee_schedule_event.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: JSON.stringify({
                            id: currentEditingEvent.id,
                            apply_to_group: applyToGroup,
                            apply_to_recurrence: applyToRecurrence
                        })
                    }).then(function(r) {
                        if (!r.ok) {
                            throw new Error('HTTP ' + r.status + ': ' + r.statusText);
                        }
                        return r.json().catch(function(e) {
                            throw new Error('Réponse JSON invalide: ' + e.message);
                        });
                    }).then(function(data) {
                        if (!data || !data.ok) {
                            showError((data && data.error) ? data.error : 'Suppression impossible.');
                            return;
                        }

                        if (data.refresh_required) {
                            closeModal();
                            window.location.reload();
                            return;
                        }

                        currentEditingEvent.remove();
                        closeModal();
                    }).catch(function(e) {
                        showError('Erreur réseau : suppression impossible. Détails: ' + e.message);
                    });
                });
            }

            if (repeatEnabled) {
                repeatEnabled.addEventListener('change', function() {
                    setRepeatFieldsVisibility();
                });
            }
            if (repeatType) {
                repeatType.addEventListener('change', function() {
                    setRepeatFieldsVisibility();
                });
            }
            if (targetScope) {
                targetScope.addEventListener('change', function() {
                    setTargetFieldsVisibility(modalEl ? (modalEl.dataset.mode || 'create') : 'create');
                });
            }
            if (fieldStatus) {
                fieldStatus.addEventListener('change', function() {
                    updateStatusButtons(fieldStatus.value || 'planifie', modalEl ? (modalEl.dataset.mode || 'create') : 'create');
                });
            }
            if (fieldAssignmentType) {
                fieldAssignmentType.addEventListener('change', setAssignmentFieldsVisibility);
            }
            if (targetFilter) {
                targetFilter.addEventListener('input', filterTargetRows);
            }

            function buildCalendar(yearViewFallbackToList) {
                var rightButtons = (yearViewName === 'multiMonthYear') ?
                    'multiMonthYear,dayGridMonth,timeGridWeek,timeGridDay' :
                    'dayGridMonth,timeGridWeek,timeGridDay,listYear';

                var buttonText = {
                    today: 'Aujourd\'hui',
                    month: 'Mois',
                    week: 'Semaine',
                    day: 'Jour'
                };
                buttonText[yearViewName] = 'Année';
                buttonText['listYear'] = 'Année';

                return new FullCalendar.Calendar(calendarEl, {
                    initialView: periodToView(currentPeriod),
                    initialDate: currentDate,
                    locale: 'fr',
                    nowIndicator: true,
                    navLinks: false,
                    height: '100%',
                    expandRows: true,
                    firstDay: 1,
                    events: <?= json_encode($calendarItems, JSON_UNESCAPED_UNICODE) ?>,
                    editable: <?= json_encode(($personnelId > 0 && $scheduleReady), JSON_UNESCAPED_UNICODE) ?>,
                    selectable: <?= json_encode(($personnelId > 0 && $scheduleReady), JSON_UNESCAPED_UNICODE) ?>,
                    selectMirror: false,
                    eventStartEditable: <?= json_encode(($personnelId > 0 && $scheduleReady), JSON_UNESCAPED_UNICODE) ?>,
                    eventDurationEditable: <?= json_encode(($personnelId > 0 && $scheduleReady), JSON_UNESCAPED_UNICODE) ?>,
                    headerToolbar: {
                        left: 'prev,next today',
                        center: 'title',
                        right: rightButtons
                    },
                    buttonText: buttonText,
                    views: {
                        // Réglages confortables pour la vue annuelle multi-mois.
                        multiMonthYear: {
                            multiMonthMaxColumns: 3,
                            showNonCurrentDates: false
                        }
                    },
                    eventContent: function(arg) {
                        return {
                            html: '<div style="white-space: normal; word-break: break-word;">' + arg.event.title + '</div>'
                        };
                    },
                    eventClassNames: function(arg) {
                        var ev = arg && arg.event ? arg.event : null;
                        if (!ev) return [];
                        var classes = [];
                        var source = (ev.extendedProps && ev.extendedProps.source) ? ev.extendedProps.source : '';
                        if (source === 'planning') {
                            classes.push('edt-evt-planning');
                            return classes;
                        }
                        var sc = getStatusCode(ev);
                        if (sc === 'termine') classes.push('edt-evt-termine');
                        if (sc === 'annule') classes.push('edt-evt-annule');
                        if (isRiskConflict(ev) || isRiskOverload(ev)) classes.push('edt-evt-risk');
                        if (isRiskConflict(ev)) classes.push('edt-evt-risk-problem');
                        return classes;
                    },
                    eventDidMount: function(arg) {
                        var ev = arg && arg.event ? arg.event : null;
                        if (!ev || !arg || !arg.el) {
                            return;
                        }
                        var riskReasons = [];
                        if (isRiskConflict(ev)) {
                            riskReasons.push('Conflit horaire');
                        }
                        if (isRiskOverload(ev)) {
                            riskReasons.push('Surcharge journalière');
                        }
                        if (riskReasons.length) {
                            arg.el.setAttribute('title', riskReasons.join(' • '));
                        }
                    },
                    eventAllow: function(dropInfo, draggedEvent) {
                        if (!scheduleReady) {
                            return false;
                        }
                        if (draggedEvent && draggedEvent.extendedProps && draggedEvent.extendedProps.quick_task_id) {
                            return true;
                        }
                        return !!(draggedEvent && draggedEvent.extendedProps && draggedEvent.extendedProps.source === 'personal');
                    },
                    droppable: true,
                    dropAccept: '.edt-quick-task-card',
                    eventReceive: function(info) {
                        var ev = info && info.event ? info.event : null;
                        if (!ev || !ev.extendedProps || !ev.extendedProps.quick_task_id) {
                            return;
                        }
                        var start = ev.start;
                        if (!start) {
                            ev.remove();
                            return;
                        }
                        if (ev.allDay) {
                            start = new Date(start.getFullYear(), start.getMonth(), start.getDate(), 9, 0, 0);
                        }
                        var end = ev.end && ev.end > start ? ev.end : addMinutes(start, 60);
                        scheduleQuickTask(
                            parseInt(ev.extendedProps.quick_task_id || 0, 10),
                            parseInt(ev.extendedProps.quick_task_personnel_id || currentPersonnelId, 10),
                            start,
                            end,
                            ev
                        );
                    },
                    datesSet: function(info) {
                        if (!didInit) {
                            didInit = true;
                            return;
                        }

                        var nextPeriod = viewToPeriod(info.view.type);
                        // Ancre = la date courante du calendrier (sinon on tombe sur le début de période)
                        var anchor = (info && info.view && info.view.calendar && typeof info.view.calendar.getDate === 'function') ? info.view.calendar.getDate() : ((info && info.start) ? info.start : new Date());
                        var nextDate = toYmd(anchor);

                        if (nextPeriod === currentPeriod && nextDate === currentDate) {
                            return;
                        }

                        var params = new URLSearchParams(window.location.search);
                        params.set('period', nextPeriod);
                        params.set('date', nextDate);
                        window.location.href = window.location.pathname + '?' + params.toString();
                    },
                    select: function(selectionInfo) {
                        if (!<?= json_encode(($personnelId > 0 && $scheduleReady), JSON_UNESCAPED_UNICODE) ?>) {
                            return;
                        }
                        if (selectionInfo && selectionInfo.view && selectionInfo.view.calendar) {
                            selectionInfo.view.calendar.unselect();
                        }
                        var start = selectionInfo.start;
                        var end = selectionInfo.end;

                        // En vue all-day (mois/année), FullCalendar sélectionne minuit->minuit.
                        // On convertit en créneau horaire simple (09:00-10:00) sur le jour de départ.
                        if (selectionInfo.allDay) {
                            start = new Date(start.getFullYear(), start.getMonth(), start.getDate(), 9, 0, 0);
                            end = addMinutes(start, 60);
                        } else {
                            // Sélection courte : si end==start, force 60 minutes
                            if (!end || end.getTime() === start.getTime()) {
                                end = addMinutes(start, 60);
                            }
                        }

                        fillFormFromDates(start, end, {
                            status_code: 'planifie'
                        });
                        openModal('create');
                    },
                    dateClick: function(arg) {
                        if (!<?= json_encode(($personnelId > 0 && $scheduleReady), JSON_UNESCAPED_UNICODE) ?>) {
                            return;
                        }
                        var start = (arg && arg.date) ? arg.date : null;
                        if (!start) {
                            return;
                        }

                        if (arg && arg.allDay) {
                            start = new Date(start.getFullYear(), start.getMonth(), start.getDate(), 9, 0, 0);
                        }
                        var end = addMinutes(start, 60);

                        fillFormFromDates(start, end, {
                            status_code: 'planifie'
                        });
                        openModal('create');
                    },
                    eventClick: function(clickInfo) {
                        var ev = clickInfo && clickInfo.event ? clickInfo.event : null;
                        if (!ev || !isPersonalEvent(ev)) {
                            return;
                        }

                        if (!<?= json_encode(($personnelId > 0 && $scheduleReady), JSON_UNESCAPED_UNICODE) ?>) {
                            return;
                        }
                        fillFormFromEvent(ev);
                        openModal('edit');
                    },
                    eventDrop: persistEventMove,
                    eventResize: persistEventMove
                });
            }

            var calendar = null;
            try {
                calendar = buildCalendar(false);
            } catch (e) {
                yearViewName = 'listYear';
                calendar = buildCalendar(true);
            }

            calendar.render();

            function initQuickTaskUi() {
                var input = document.getElementById('edt-quick-task-input');
                var addBtn = document.getElementById('edt-quick-task-add');
                var bottomAddBtn = document.getElementById('edt-quick-task-bottom-add');
                var list = document.getElementById('edt-quick-task-list');
                var assigneeToggle = document.getElementById('edt-quick-assignee-toggle');
                var prefilledQuickTask = new URLSearchParams(window.location.search).get('quick_task_title');
                if (input && prefilledQuickTask && !String(input.value || '').trim()) {
                    input.value = prefilledQuickTask;
                    input.focus();
                    input.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
                var assigneePanel = document.getElementById('edt-quick-assignee-panel');
                var assigneeSearch = document.getElementById('edt-quick-assignee-search');

                renderQuickTaskList();
                updateQuickAssigneeLabel();
                renderQuickAssigneeList();

                if (input) {
                    input.addEventListener('keydown', function(e) {
                        if (e.key === 'Enter' && !e.shiftKey) {
                            e.preventDefault();
                            createQuickTaskFromInput();
                        }
                    });
                }
                if (addBtn) {
                    addBtn.addEventListener('click', function() {
                        createQuickTaskFromInput();
                    });
                }
                if (bottomAddBtn && input) {
                    bottomAddBtn.addEventListener('click', function() {
                        input.focus();
                    });
                }
                if (assigneeToggle && assigneePanel) {
                    assigneeToggle.addEventListener('click', function() {
                        assigneePanel.classList.toggle('hidden');
                        renderQuickAssigneeList();
                        if (!assigneePanel.classList.contains('hidden') && assigneeSearch) {
                            setTimeout(function() {
                                assigneeSearch.focus();
                            }, 0);
                        }
                    });
                }
                if (assigneeSearch) {
                    assigneeSearch.addEventListener('input', renderQuickAssigneeList);
                }
                if (assigneePanel) {
                    assigneePanel.addEventListener('click', function(e) {
                        var row = e.target && e.target.closest ? e.target.closest('.edt-quick-person-row') : null;
                        if (!row) return;
                        var id = parseInt(row.getAttribute('data-personnel-id') || '0', 10);
                        if (id > 0) {
                            quickAssigneeId = id;
                            updateQuickAssigneeLabel();
                            renderQuickAssigneeList();
                            assigneePanel.classList.add('hidden');
                            if (input) input.focus();
                        }
                    });
                }
                if (list) {
                    list.addEventListener('click', function(e) {
                        var btn = e.target && e.target.closest ? e.target.closest('.edt-quick-task-delete') : null;
                        if (!btn) return;
                        var card = btn.closest('.edt-quick-task-card');
                        if (!card) return;
                        removeQuickTask(parseInt(card.getAttribute('data-quick-task-id') || '0', 10), parseInt(card.getAttribute('data-personnel-id') || currentPersonnelId, 10));
                    });

                    if (window.FullCalendar && window.FullCalendar.Draggable) {
                        new window.FullCalendar.Draggable(list, {
                            itemSelector: '.edt-quick-task-card',
                            eventData: function(eventEl) {
                                return {
                                    title: eventEl.getAttribute('data-title') || 'Tache rapide',
                                    duration: '01:00',
                                    extendedProps: {
                                        quick_task_id: parseInt(eventEl.getAttribute('data-quick-task-id') || '0', 10),
                                        quick_task_personnel_id: parseInt(eventEl.getAttribute('data-personnel-id') || currentPersonnelId, 10)
                                    }
                                };
                            }
                        });
                    }
                }
            }

            initQuickTaskUi();

            // To-Do modal
            var todoModalEl = document.getElementById('edt-todo-modal');
            var todoOpenBtn = document.getElementById('edt-open-todo');
            var todoErrorEl = document.getElementById('edt-todo-error');
            var validationErrorEl = document.getElementById('edt-validation-error');

            function validationShowError(msg) {
                if (!validationErrorEl) return;
                if (!msg) {
                    validationErrorEl.classList.add('hidden');
                    validationErrorEl.textContent = '';
                    return;
                }
                validationErrorEl.textContent = msg;
                validationErrorEl.classList.remove('hidden');
            }

            document.querySelectorAll('.edt-validate-task-btn').forEach(function(button) {
                button.addEventListener('click', function() {
                    var eventId = parseInt(button.getAttribute('data-event-id') || '0', 10);
                    if (!eventId) return;

                    validationShowError('');
                    button.disabled = true;
                    button.textContent = 'Validation...';

                    fetch('api/validate_employee_schedule_event_completion.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: JSON.stringify({ event_id: eventId })
                    }).then(function(r) {
                        if (!r.ok) {
                            throw new Error('HTTP ' + r.status + ': ' + r.statusText);
                        }
                        return r.json().catch(function(e) {
                            throw new Error('Réponse JSON invalide: ' + e.message);
                        });
                    }).then(function(data) {
                        if (!data || !data.ok) {
                            throw new Error((data && data.error) ? data.error : 'Validation impossible.');
                        }
                        var row = document.querySelector('[data-validation-event-id="' + eventId + '"]');
                        if (row) row.remove();
                        if (calendar && typeof calendar.getEventById === 'function') {
                            var fcEv = calendar.getEventById('evt_' + eventId) || calendar.getEventById(String(eventId));
                            if (fcEv) {
                                fcEv.setExtendedProp('status_code', 'termine');
                            }
                        }
                    }).catch(function(e) {
                        validationShowError(e.message || 'Validation impossible.');
                        button.disabled = false;
                        button.textContent = 'Valider terminé';
                    });
                });
            });

            function todoShowError(msg) {
                if (!todoErrorEl) return;
                if (!msg) {
                    todoErrorEl.classList.add('hidden');
                    todoErrorEl.textContent = '';
                    return;
                }
                todoErrorEl.textContent = msg;
                todoErrorEl.classList.remove('hidden');
            }

            function openTodo() {
                if (!todoModalEl) return;
                todoShowError('');
                todoModalEl.classList.remove('hidden');
                todoModalEl.setAttribute('aria-hidden', 'false');
            }

            function closeTodo() {
                if (!todoModalEl) return;
                todoModalEl.classList.add('hidden');
                todoModalEl.setAttribute('aria-hidden', 'true');
                todoShowError('');
            }

            if (todoOpenBtn) {
                todoOpenBtn.addEventListener('click', function() {
                    openTodo();
                });
            }

            if (todoModalEl) {
                todoModalEl.addEventListener('click', function(e) {
                    var t = e.target;
                    if (t && t.hasAttribute && t.hasAttribute('data-edt-todo-close')) {
                        closeTodo();
                    }
                });
                document.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape' && !todoModalEl.classList.contains('hidden')) {
                        closeTodo();
                    }
                });

                todoModalEl.addEventListener('change', function(e) {
                    var el = e.target;
                    if (!el || !el.matches || !el.matches('input[type="checkbox"][data-todo]')) {
                        return;
                    }

                    todoShowError('');
                    var kind = el.getAttribute('data-kind') || '';
                    var checked = !!el.checked;
                    var row = el.closest('[data-todo-row]');
                    el.disabled = true;

                    function setRowDone(isDone) {
                        if (!row) return;
                        var titleEl = row.querySelector('.text-sm.font-semibold');
                        var contentEl = row.querySelector('.min-w-0');
                        if (contentEl) {
                            contentEl.classList.toggle('opacity-70', isDone);
                        }
                        if (titleEl) {
                            titleEl.classList.toggle('line-through', isDone);
                        }
                    }

                    if (kind === 'personal') {
                        var eventId = el.getAttribute('data-event-id') || '';
                        fetch('api/set_employee_schedule_event_status.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            body: JSON.stringify({
                                id: eventId,
                                status_code: checked ? 'termine' : 'planifie'
                            })
                        }).then(function(r) {
                            if (!r.ok) {
                                throw new Error('HTTP ' + r.status + ': ' + r.statusText);
                            }
                            return r.json().catch(function(e) {
                                throw new Error('Réponse JSON invalide: ' + e.message);
                            });
                        }).then(function(data) {
                            if (!data || !data.ok) {
                                el.checked = !checked;
                                todoShowError((data && data.error) ? data.error : 'Mise à jour impossible.');
                                return;
                            }

                            var savedStatusCode = data.status_code || (checked ? 'termine' : 'planifie');
                            el.checked = savedStatusCode === 'termine';
                            setRowDone(savedStatusCode === 'termine');
                            if (calendar && typeof calendar.getEventById === 'function') {
                                var fcEv = calendar.getEventById(eventId);
                                if (fcEv) {
                                    fcEv.setExtendedProp('status_code', savedStatusCode);
                                }
                            }
                        }).catch(function(e) {
                            el.checked = !checked;
                            todoShowError('Erreur réseau : mise à jour impossible. Détails: ' + e.message);
                        }).finally(function() {
                            el.disabled = false;
                        });
                        return;
                    }

                    if (kind === 'microsoft') {
                        var taskId = el.getAttribute('data-task-id') || '';
                        var listId = el.getAttribute('data-list-id') || '';
                        var etag = el.getAttribute('data-etag') || null;
                        fetch('api/microsoft_todo_set_task_status.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            body: JSON.stringify({
                                task_id: taskId,
                                list_id: listId,
                                status: checked ? 'completed' : 'notStarted',
                                etag: etag
                            })
                        }).then(function(r) {
                            if (!r.ok) {
                                throw new Error('HTTP ' + r.status + ': ' + r.statusText);
                            }
                            return r.json().catch(function(e) {
                                throw new Error('Réponse JSON invalide: ' + e.message);
                            });
                        }).then(function(data) {
                            if (!data || !data.ok) {
                                el.checked = !checked;
                                todoShowError((data && data.error) ? data.error : 'Mise à jour Microsoft impossible.');
                                return;
                            }
                            setRowDone(checked);
                        }).catch(function(e) {
                            el.checked = !checked;
                            todoShowError('Erreur réseau : mise à jour Microsoft impossible. Détails: ' + e.message);
                        }).finally(function() {
                            el.disabled = false;
                        });
                        return;
                    }

                    // planning / autre : lecture seule
                    el.checked = !checked;
                    el.disabled = false;
                });
            }

            <?php if ($printListMode): ?>
                window.setTimeout(function() {
                    window.print();
                }, 250);
            <?php endif; ?>
        }
    });
</script>

<?php require_once $projectRoot . '/decaissement/includes/footer.php'; ?>
