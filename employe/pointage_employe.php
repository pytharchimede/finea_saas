<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/EmployeeLegalRequestManager.php';
require_once __DIR__ . '/../model/AbsenceJustificationRangeManager.php';
require_once __DIR__ . '/includes/helpers.php';

$pdo = \Database::getConnection();

$requestManager = new EmployeeLegalRequestManager($pdo);
$rangeManager = new AbsenceJustificationRangeManager($pdo);

$userId = (int) ($_SESSION['user_id'] ?? 0);
$userName = employeeCurrentUserName();
$userEmail = (string) ($_SESSION['mail_pro'] ?? '');

$personnel = employeeFindPersonnelProfile($pdo, $userEmail);

$period = (string) ($_GET['period'] ?? '');
if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
    $period = date('Y-m');
}

$year = (int) ($_GET['year'] ?? 0);
if ($year < 2000 || $year > 2100) {
    $year = (int) date('Y');
}

[$y, $m] = array_map('intval', explode('-', $period));
$start = sprintf('%04d-%02d-01', $y, $m);
$end = date('Y-m-t', strtotime($start));

$flash = '';
$flashType = 'info';

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

function employeeAttendanceTimeLabel(?string $time): string
{
    $time = trim((string) $time);
    if ($time === '') {
        return '—';
    }

    $timestamp = strtotime('1970-01-01 ' . $time);
    return $timestamp === false ? '—' : date('H:i', $timestamp);
}

function employeeAttendanceWorkedMinutes(?string $checkIn, ?string $checkOut): ?int
{
    $checkIn = trim((string) $checkIn);
    $checkOut = trim((string) $checkOut);
    if ($checkIn === '' || $checkOut === '') {
        return null;
    }

    $inTimestamp = strtotime('1970-01-01 ' . $checkIn);
    $outTimestamp = strtotime('1970-01-01 ' . $checkOut);
    if ($inTimestamp === false || $outTimestamp === false) {
        return null;
    }

    if ($outTimestamp < $inTimestamp) {
        $outTimestamp += 86400;
    }

    return max(0, (int) round(($outTimestamp - $inTimestamp) / 60));
}

function employeeAttendanceDurationLabel(?int $minutes): string
{
    if ($minutes === null) {
        return '—';
    }

    $hours = intdiv($minutes, 60);
    $remainingMinutes = $minutes % 60;

    return sprintf('%d h %02d', $hours, $remainingMinutes);
}

if (isset($_GET['justif_submitted']) && (string) $_GET['justif_submitted'] === '1') {
    $flash = 'Justificatif soumis à validation RH.';
    $flashType = 'success';
}

if (isset($_GET['justif_error']) && (string) $_GET['justif_error'] !== '') {
    $flash = (string) $_GET['justif_error'];
    $flashType = 'error';
}

$statuses = [
    'present' => 'Présent',
    'half_day' => 'Demi-journée',
    'mission' => 'Mission',
    'conge' => 'Congé',
    'repos' => 'Repos',
    'absence' => 'Absent',
    'maladie' => 'Maladie',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $personnel) {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'justify') {
        $date = (string) ($_POST['date'] ?? '');
        $dateDebutJustificatif = (string) ($_POST['date_debut_justificatif'] ?? $date);
        $dateFinJustificatif = (string) ($_POST['date_fin_justificatif'] ?? $dateDebutJustificatif);
        $note = trim((string) ($_POST['note'] ?? ''));
        $requestedStatus = strtolower(trim((string) ($_POST['requested_status'] ?? '')));

        $allowedTargets = ['present', 'half_day', 'mission', 'conge', 'maladie'];
        if (!in_array($requestedStatus, $allowedTargets, true)) {
            $requestedStatus = 'conge';
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $note === '') {
            $flash = 'Veuillez renseigner une justification valide.';
            $flashType = 'error';
        } else {
            $pid = (int) ($personnel['personnel_id'] ?? 0);
            $rangeValidation = $rangeManager->validateJustificationRange($pid, $date, $dateDebutJustificatif, $dateFinJustificatif);
            if (!($rangeValidation['ok'] ?? false)) {
                $flash = (string) ($rangeValidation['message'] ?? 'La plage de justification est invalide.');
                $flashType = 'error';
            }

            $stmt = $pdo->prepare('
                SELECT attendance_status
                FROM rh_attendance_daily
                WHERE personnel_id = :pid AND attendance_date = :d
                LIMIT 1
            ');
            $stmt->execute(['pid' => $pid, 'd' => $date]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            $currentStatus = strtolower((string) ($row['attendance_status'] ?? ''));
            if (!$row) {
                $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
                $holidaySet = employeeHolidayDateSet($pdo, $date, $date);
                $isWorkingAbsence = $day
                    && $date <= date('Y-m-d')
                    && (int) $day->format('N') < 6
                    && !isset($holidaySet[$date]);

                if ($isWorkingAbsence) {
                    $currentStatus = 'absence';
                }
            }

            if ($flashType === 'error') {
                // Erreur deja renseignee par la validation de plage.
            } elseif (!in_array($currentStatus, ['absence', 'maladie'], true)) {
                $flash = 'Justification impossible pour ce jour.';
                $flashType = 'error';
            } else {
                $existing = $requestManager->listRequestsForUser($userId, 200);
                foreach ($existing as $req) {
                    if (!is_array($req)) {
                        continue;
                    }
                    if (($req['request_type'] ?? '') !== 'regularisation_pointage') {
                        continue;
                    }
                    $payload = (array) ($req['payload'] ?? []);
                    if (($payload['attendance_date'] ?? '') === $date && empty($payload['date_debut_justificatif']) && strtolower((string) ($req['status'] ?? '')) === 'submitted') {
                        $flash = 'Une demande est déjà en attente pour cette date.';
                        $flashType = 'error';
                        break;
                    }
                }

                $errors = [];
                $uploadedJustifs = [];

                $normalizeUploads = static function (array $files): array {
                    $out = [];
                    if (!isset($files['name'])) {
                        return $out;
                    }
                    if (is_array($files['name'])) {
                        $count = count($files['name']);
                        for ($i = 0; $i < $count; $i++) {
                            $out[] = [
                                'name' => (string) ($files['name'][$i] ?? ''),
                                'type' => (string) ($files['type'][$i] ?? ''),
                                'tmp_name' => (string) ($files['tmp_name'][$i] ?? ''),
                                'error' => (int) ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE),
                                'size' => (int) ($files['size'][$i] ?? 0),
                            ];
                        }
                        return $out;
                    }

                    $out[] = [
                        'name' => (string) ($files['name'] ?? ''),
                        'type' => (string) ($files['type'] ?? ''),
                        'tmp_name' => (string) ($files['tmp_name'] ?? ''),
                        'error' => (int) ($files['error'] ?? UPLOAD_ERR_NO_FILE),
                        'size' => (int) ($files['size'] ?? 0),
                    ];
                    return $out;
                };

                if ($flashType !== 'error') {
                    if (!isset($_FILES['justificatifs']) || !is_array($_FILES['justificatifs'])) {
                        $errors[] = 'Veuillez joindre au moins une pièce justificative.';
                    } else {
                        $files = $normalizeUploads($_FILES['justificatifs']);
                        $files = array_values(array_filter($files, static function (array $f): bool {
                            return (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
                        }));

                        if (!$files) {
                            $errors[] = 'Veuillez joindre au moins une pièce justificative.';
                        } else {
                            if (count($files) > 10) {
                                $errors[] = 'Vous pouvez joindre au maximum 10 justificatifs.';
                            }

                            $baseRel = 'uploads/employee_pointage/' . date('Ymd');
                            $token = bin2hex(random_bytes(8));
                            $dirRel = $baseRel . '/' . $userId . '_' . $token;
                            $dirAbs = dirname(__DIR__) . '/' . $dirRel;
                            if (!is_dir($dirAbs) && !mkdir($dirAbs, 0775, true) && !is_dir($dirAbs)) {
                                $errors[] = 'Impossible de créer le dossier de stockage des justificatifs.';
                            }

                            $allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];

                            foreach ($files as $f) {
                                if ($errors) {
                                    break;
                                }
                                if ((int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                                    $errors[] = 'Erreur upload justificatif: ' . (string) ($f['name'] ?? '');
                                    break;
                                }

                                $orig = trim((string) ($f['name'] ?? ''));
                                $tmp = (string) ($f['tmp_name'] ?? '');
                                $size = (int) ($f['size'] ?? 0);
                                $mime = (string) ($f['type'] ?? '');

                                if ($orig === '' || $tmp === '' || !is_uploaded_file($tmp)) {
                                    $errors[] = 'Fichier justificatif invalide.';
                                    break;
                                }
                                if ($size <= 0 || $size > 12 * 1024 * 1024) {
                                    $errors[] = 'Justificatif trop volumineux (max 12 Mo): ' . $orig;
                                    break;
                                }

                                $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
                                if ($ext === '' || !in_array($ext, $allowedExt, true)) {
                                    $errors[] = 'Format non supporté (jpg/png/webp/pdf): ' . $orig;
                                    break;
                                }

                                $newName = bin2hex(random_bytes(12)) . '.' . $ext;
                                $targetAbs = $dirAbs . '/' . $newName;
                                if (!move_uploaded_file($tmp, $targetAbs)) {
                                    $errors[] = 'Impossible de sauvegarder le justificatif: ' . $orig;
                                    break;
                                }

                                $sha = '';
                                try {
                                    $sha = hash_file('sha256', $targetAbs) ?: '';
                                } catch (Throwable $e) {
                                    $sha = '';
                                }

                                $rel = '/' . str_replace('\\', '/', $dirRel . '/' . $newName);
                                $base = employeeAppBasePath();
                                $url = ($base !== '' ? $base : '') . $rel;
                                $uploadedJustifs[] = [
                                    'name' => $orig,
                                    'stored_name' => $newName,
                                    'url' => $url,
                                    'mime' => $mime,
                                    'size' => $size,
                                    'sha256' => $sha,
                                ];
                            }
                        }
                    }
                }

                if ($errors) {
                    $flash = (string) $errors[0];
                    $flashType = 'error';
                } elseif ($flashType !== 'error') {
                    try {
                        $payload = [
                            'attendance_date' => $date,
                            'absence_date_debut' => (string) (($rangeValidation['period']['date_debut'] ?? '') ?: $dateDebutJustificatif),
                            'absence_date_fin' => (string) (($rangeValidation['period']['date_fin'] ?? '') ?: $dateFinJustificatif),
                            'date_debut_justificatif' => $dateDebutJustificatif,
                            'date_fin_justificatif' => $dateFinJustificatif,
                            'nombre_jours_justifies' => (int) ($rangeValidation['nb_jours'] ?? 1),
                            'attendance_status_before' => $currentStatus,
                            'requested_status' => $requestedStatus,
                            'note' => $note,
                            'personnel_id' => $pid,
                            'period' => $period,
                            'justificatifs' => $uploadedJustifs,
                        ];
                        $dateDebutJustificatif = (string) $payload['date_debut_justificatif'];
                        $dateFinJustificatif = (string) $payload['date_fin_justificatif'];

                        $requester = [
                            'name' => $userName,
                            'email' => $userEmail,
                        ];

                        $title = 'Régularisation pointage: ' . employeeFormatDateFr($date);
                        $title = 'Regularisation pointage: ' . employeeFormatDateFr($dateDebutJustificatif) . ' - ' . employeeFormatDateFr($dateFinJustificatif);
                        $requestId = $requestManager->createRequest($userId, $requester, 'regularisation_pointage', $payload, $title);
                        $rangeManager->saveJustificationRange([
                            'legal_request_id' => $requestId,
                            'personnel_id' => $pid,
                            'absence_date_debut' => (string) (($rangeValidation['period']['date_debut'] ?? '') ?: $dateDebutJustificatif),
                            'absence_date_fin' => (string) (($rangeValidation['period']['date_fin'] ?? '') ?: $dateFinJustificatif),
                            'date_debut' => $dateDebutJustificatif,
                            'date_fin' => $dateFinJustificatif,
                            'fichier_justificatif' => json_encode($uploadedJustifs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '',
                            'commentaire' => $note,
                            'statut_validation' => 'submitted',
                            'created_by' => $userId,
                        ]);
                        \AuditTrail::log('employee_attendance_regularization_requested', [
                            'module' => 'employe',
                            'action' => 'pointage_employe_justify',
                            'entity_type' => 'employee_request',
                            'entity_id' => $pid > 0 ? (string) $pid : null,
                            'entity_label' => $title,
                            'result' => 'success',
                            'details' => [
                                'attendance_date' => $date,
                                'date_debut_justificatif' => $dateDebutJustificatif,
                                'date_fin_justificatif' => $dateFinJustificatif,
                                'requested_status' => $requestedStatus,
                                'justificatif_count' => count($uploadedJustifs),
                                'user_id' => $userId,
                            ],
                        ], $pdo);
                        header('Location: pointage_employe.php?period=' . urlencode($period) . '&year=' . urlencode((string) $year) . '&justif_submitted=1');
                        exit();
                    } catch (Throwable $e) {
                        \AuditTrail::log('employee_attendance_regularization_failed', [
                            'module' => 'employe',
                            'action' => 'pointage_employe_justify',
                            'entity_type' => 'employee_request',
                            'entity_id' => $pid > 0 ? (string) $pid : null,
                            'entity_label' => 'Regularisation pointage',
                            'result' => 'failed',
                            'severity' => 'warning',
                            'details' => [
                                'attendance_date' => $date,
                                'requested_status' => $requestedStatus,
                                'error_message' => $e->getMessage(),
                                'user_id' => $userId,
                            ],
                        ], $pdo);
                        $flash = 'Erreur lors de la soumission à la validation RH.';
                        $flashType = 'error';
                    }
                }
            }
        }
    }
}

$entries = [];
$summary = ['worked_days' => 0.0, 'overtime_hours' => 0.0];

$unjustifiedAbsences = [];
$absencesToJustifyCount = 0;
$absencesPendingValidationCount = 0;
$absenceRangeInfoByDate = [];
$absenceJustificationCards = [];

$regularizationByDate = []; // key: YYYY-MM-DD -> latest request

$annualByMonth = []; // key: YYYY-MM
$annualTotals = [
    'worked_days' => 0.0,
    'overtime_hours' => 0.0,
    'present_days' => 0,
    'half_day_days' => 0,
    'mission_days' => 0,
    'conge_days' => 0,
    'repos_days' => 0,
    'absence_days' => 0,
    'maladie_days' => 0,
];

if ($personnel) {
    $pid = (int) ($personnel['personnel_id'] ?? 0);

    if ($userId > 0) {
        $reqs = $requestManager->listRequestsForUser($userId, 200);
        foreach ($reqs as $req) {
            if (!is_array($req)) {
                continue;
            }
            if (($req['request_type'] ?? '') !== 'regularisation_pointage') {
                continue;
            }
            $payload = (array) ($req['payload'] ?? []);

            // Une demande de regularisation peut couvrir plusieurs jours.
            // On indexe donc la demande sur chaque date de la plage pour que
            // tout le range apparaisse "En attente RH", pas seulement la date d'ancrage.
            $rangeStart = (string) ($payload['date_debut_justificatif'] ?? $payload['date_debut'] ?? $payload['attendance_date'] ?? '');
            $rangeEnd = (string) ($payload['date_fin_justificatif'] ?? $payload['date_fin'] ?? $rangeStart);

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $rangeStart) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $rangeEnd)) {
                continue;
            }

            try {
                $cursorReq = new DateTimeImmutable($rangeStart);
                $endReq = new DateTimeImmutable($rangeEnd);
            } catch (Throwable $e) {
                continue;
            }

            if ($endReq < $cursorReq) {
                continue;
            }

            while ($cursorReq <= $endReq) {
                $d = $cursorReq->format('Y-m-d');

                $prev = $regularizationByDate[$d] ?? null;
                if (!$prev) {
                    $regularizationByDate[$d] = $req;
                } else {
                    $prevTs = strtotime((string) ($prev['submitted_at'] ?? '')) ?: 0;
                    $ts = strtotime((string) ($req['submitted_at'] ?? '')) ?: 0;
                    if ($ts >= $prevTs) {
                        $regularizationByDate[$d] = $req;
                    }
                }

                $cursorReq = $cursorReq->modify('+1 day');
            }
        }
    }

    $stmt = $pdo->prepare('
        SELECT attendance_date, attendance_status, worked_days, overtime_hours,
               check_in_time, check_out_time, notes
        FROM rh_attendance_daily
        WHERE personnel_id = :pid
          AND attendance_date BETWEEN :start AND :end
        ORDER BY attendance_date ASC
    ');
    $stmt->execute([
        'pid' => $pid,
        'start' => $start,
        'end' => $end,
    ]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as $r) {
        $entries[(string) $r['attendance_date']] = $r;
        $summary['worked_days'] += (float) ($r['worked_days'] ?? 0);
        $summary['overtime_hours'] += (float) ($r['overtime_hours'] ?? 0);
    }

    // Ne plus utiliser une logique de delai bloquant type 48h pour afficher les absences a justifier.
    // Les mails/relances restent possibles ailleurs, mais l'employe doit toujours pouvoir justifier
    // une absence du mois consulte.
    $stmtAbsencesToJustify = $pdo->prepare('
        SELECT attendance_date, attendance_status, notes
        FROM rh_attendance_daily
        WHERE personnel_id = :pid
          AND attendance_date BETWEEN :start AND :end
          AND attendance_status IN ("absence", "maladie")
        ORDER BY attendance_date ASC
    ');
    $stmtAbsencesToJustify->execute([
        'pid' => $pid,
        'start' => $start,
        'end' => $end,
    ]);
    $unjustifiedAbsences = $stmtAbsencesToJustify->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $absencesToJustifyCount = employeeCountAbsencesToJustify($unjustifiedAbsences);
    $absencesPendingValidationCount = employeeCountAbsencesPendingValidation($unjustifiedAbsences);
    foreach ($unjustifiedAbsences as $absence) {
        $dateKey = (string) ($absence['attendance_date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateKey) || isset($absenceRangeInfoByDate[$dateKey])) {
            continue;
        }
        $absencePeriod = $rangeManager->getAbsencePeriod($pid, $dateKey);
        if (!$absencePeriod) {
            continue;
        }
        $justifiedRanges = $rangeManager->getJustifiedRanges($pid, (string) $absencePeriod['date_debut'], (string) $absencePeriod['date_fin']);
        $remainingRanges = $rangeManager->calculateRemainingRanges($pid, (string) $absencePeriod['date_debut'], (string) $absencePeriod['date_fin']);
        $absenceRangeInfoByDate[$dateKey] = [
            'period' => $absencePeriod,
            'justified' => $justifiedRanges,
            'remaining' => $remainingRanges,
            'covered_days' => $rangeManager->calculateCoveredDays($pid, (string) $absencePeriod['date_debut'], (string) $absencePeriod['date_fin']),
        ];
        $periodKey = (string) $absencePeriod['date_debut'] . '_' . (string) $absencePeriod['date_fin'];
        if (!isset($absenceJustificationCards[$periodKey])) {
            $absenceJustificationCards[$periodKey] = $absenceRangeInfoByDate[$dateKey];
            $firstRemainingRange = (array) ($remainingRanges[0] ?? []);
            $absenceJustificationCards[$periodKey]['anchor_date'] = (string) ($firstRemainingRange['date_debut'] ?? $dateKey);
            $absenceJustificationCards[$periodKey]['attendance_status'] = (string) ($absence['attendance_status'] ?? 'absence');
        }
    }

    $yearStart = sprintf('%04d-01-01', $year);
    $yearEnd = sprintf('%04d-12-31', $year);
    $stmt = $pdo->prepare('
        SELECT
            DATE_FORMAT(attendance_date, "%Y-%m") AS ym,
            SUM(worked_days) AS worked_days,
            SUM(overtime_hours) AS overtime_hours,
            SUM(attendance_status = "present") AS present_days,
            SUM(attendance_status = "half_day") AS half_day_days,
            SUM(attendance_status = "mission") AS mission_days,
            SUM(attendance_status = "conge") AS conge_days,
            SUM(attendance_status = "repos") AS repos_days,
            SUM(attendance_status = "absence") AS absence_days,
            SUM(attendance_status = "maladie") AS maladie_days
        FROM rh_attendance_daily
        WHERE personnel_id = :pid
          AND attendance_date BETWEEN :start AND :end
        GROUP BY ym
        ORDER BY ym ASC
    ');
    $stmt->execute([
        'pid' => $pid,
        'start' => $yearStart,
        'end' => $yearEnd,
    ]);
    $annualRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($annualRows as $r) {
        $ym = (string) ($r['ym'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
            continue;
        }
        $annualByMonth[$ym] = [
            'worked_days' => (float) ($r['worked_days'] ?? 0),
            'overtime_hours' => (float) ($r['overtime_hours'] ?? 0),
            'present_days' => (int) ($r['present_days'] ?? 0),
            'half_day_days' => (int) ($r['half_day_days'] ?? 0),
            'mission_days' => (int) ($r['mission_days'] ?? 0),
            'conge_days' => (int) ($r['conge_days'] ?? 0),
            'repos_days' => (int) ($r['repos_days'] ?? 0),
            'absence_days' => (int) ($r['absence_days'] ?? 0),
            'maladie_days' => (int) ($r['maladie_days'] ?? 0),
        ];

        $annualTotals['worked_days'] += (float) ($annualByMonth[$ym]['worked_days'] ?? 0);
        $annualTotals['overtime_hours'] += (float) ($annualByMonth[$ym]['overtime_hours'] ?? 0);
        $annualTotals['present_days'] += (int) ($annualByMonth[$ym]['present_days'] ?? 0);
        $annualTotals['half_day_days'] += (int) ($annualByMonth[$ym]['half_day_days'] ?? 0);
        $annualTotals['mission_days'] += (int) ($annualByMonth[$ym]['mission_days'] ?? 0);
        $annualTotals['conge_days'] += (int) ($annualByMonth[$ym]['conge_days'] ?? 0);
        $annualTotals['repos_days'] += (int) ($annualByMonth[$ym]['repos_days'] ?? 0);
        $annualTotals['absence_days'] += (int) ($annualByMonth[$ym]['absence_days'] ?? 0);
        $annualTotals['maladie_days'] += (int) ($annualByMonth[$ym]['maladie_days'] ?? 0);
    }
}

$pageTitle = 'Pointage';
$layoutRootBase = '../';
$activeEmployeeMenu = 'pointage';

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<style>
    .employee-pointage-page {
        background:linear-gradient(180deg,#f8fbff 0%,#f2f7fc 48%,#f8fafc 100%) !important;
    }
    .attendance-summary-card {
        --attendance-accent:#2563eb;
        --attendance-soft:#eff6ff;
        --attendance-ink:#1e3a8a;
        position:relative;
        min-height:168px;
        overflow:hidden;
        border:1px solid color-mix(in srgb,var(--attendance-accent) 32%,#dbe4ef) !important;
        border-radius:24px;
        background:linear-gradient(145deg,#fff 35%,var(--attendance-soft)) !important;
        box-shadow:0 12px 26px color-mix(in srgb,var(--attendance-accent) 12%,transparent) !important;
    }
    .attendance-summary-card::before {
        content:'';position:absolute;inset:0 auto 0 0;width:6px;background:var(--attendance-accent);
    }
    .attendance-summary-card::after {
        content:'';position:absolute;right:-45px;bottom:-62px;width:150px;height:150px;border-radius:999px;background:color-mix(in srgb,var(--attendance-accent) 10%,transparent);
    }
    .attendance-summary-card>div { position:relative;z-index:1; }
    .attendance-summary-card>.absolute { display:none; }
    .attendance-summary-card--profile { --attendance-accent:#2563eb;--attendance-soft:#eff6ff;--attendance-ink:#1e40af; }
    .attendance-summary-card--activity { --attendance-accent:#059669;--attendance-soft:#ecfdf5;--attendance-ink:#047857; }
    .attendance-summary-card--alert { --attendance-accent:#d97706;--attendance-soft:#fffbeb;--attendance-ink:#92400e; }
    .attendance-summary-card--safe { --attendance-accent:#0d9488;--attendance-soft:#f0fdfa;--attendance-ink:#0f766e; }
    .attendance-summary-card--danger { --attendance-accent:#e11d48;--attendance-soft:#fff1f2;--attendance-ink:#9f1239; }
    .attendance-summary-label { color:var(--attendance-ink) !important;font-weight:900 !important; }
    .attendance-summary-value { color:var(--attendance-ink) !important;font-weight:900 !important;line-height:1; }
    .attendance-summary-icon {
        display:grid;width:52px;height:52px;flex:none;place-items:center;border-radius:17px;background:var(--attendance-accent) !important;color:#fff;box-shadow:0 9px 20px color-mix(in srgb,var(--attendance-accent) 30%,transparent) !important;
    }
    body.finea-dark-mode .employee-pointage-page { background:linear-gradient(180deg,#1b3a61,#193553 55%,#1c3858) !important; }
    body.finea-dark-mode .attendance-summary-card {
        background:linear-gradient(145deg,#294f7b,color-mix(in srgb,var(--attendance-accent) 24%,#203f65)) !important;
        border-color:color-mix(in srgb,var(--attendance-accent) 55%,#7795b8) !important;
    }
    body.finea-dark-mode .attendance-summary-card :where(.text-slate-950,.text-slate-700) { color:#f8fafc !important; }
    body.finea-dark-mode .attendance-summary-label,body.finea-dark-mode .attendance-summary-value { color:#fff !important; }
</style>

<div class="employee-pointage-page min-h-screen bg-[radial-gradient(circle_at_top_left,_rgba(240,249,255,0.95),_transparent_42%),radial-gradient(circle_at_top_right,_rgba(224,231,255,0.85),_transparent_35%),linear-gradient(180deg,_#f8fafc_0%,_#eef2ff_100%)] pb-12">
    <div class="mx-auto max-w-[1600px] px-4 py-6 2xl:px-8">
        <div class="relative overflow-hidden rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="pointer-events-none absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-indigo-500 via-violet-500 to-emerald-500"></div>
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.28em] text-indigo-600">Pointage</p>
                    <h1 class="mt-2 text-2xl font-semibold text-slate-950">Mon pointage — <?= htmlspecialchars($period) ?></h1>
                    <p class="mt-1 text-sm text-slate-600">Vue personnelle basée sur le profil RH lié à votre email.</p>
                </div>

                <form method="GET" class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-end">
                    <input type="hidden" name="year" value="<?= (int) $year ?>">
                    <div>
                        <label class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Mois</label>
                        <input type="month" name="period" value="<?= htmlspecialchars($period) ?>" class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm">
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <button type="submit" class="inline-flex items-center gap-2 rounded-2xl bg-indigo-600 px-5 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                            <i class="fas fa-magnifying-glass"></i>
                            Afficher
                        </button>
                        <?php if ($personnel): ?>
                            <a href="../request/export_pointage_employe_excel.php?period=<?= urlencode($period) ?>" target="_blank" class="inline-flex items-center gap-2 rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-900 hover:bg-slate-50">
                                <i class="fas fa-file-excel text-emerald-600"></i>
                                Export Excel
                            </a>
                            <a href="../request/export_pointage_employe_pdf.php?period=<?= urlencode($period) ?>" target="_blank" class="inline-flex items-center gap-2 rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-900 hover:bg-slate-50">
                                <i class="fas fa-file-pdf text-rose-600"></i>
                                Export PDF
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
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

            <?php if (!$personnel): ?>
                <div class="mt-5 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-4 text-sm text-amber-900">
                    <div class="font-semibold">Profil RH introuvable</div>
                    <div class="mt-1">Aucun profil du personnel ne correspond à l'email <span class="font-semibold"><?= htmlspecialchars($userEmail) ?></span>.</div>
                </div>
            <?php else: ?>
                <div class="mt-6 grid gap-4 md:grid-cols-3">
                    <div class="attendance-summary-card attendance-summary-card--profile p-5">
                        <div class="absolute inset-0 bg-gradient-to-br from-indigo-50 to-white transition group-hover:from-indigo-100 group-hover:to-purple-50"></div>
                        <div class="relative flex items-start justify-between gap-4">
                            <div>
                                <div class="attendance-summary-label text-xs uppercase tracking-[0.22em]">Profil</div>
                                <div class="mt-2 text-base font-semibold text-slate-950"><?= htmlspecialchars((string) $personnel['full_name']) ?></div>
                                <div class="mt-1 text-sm text-slate-700"><?= htmlspecialchars((string) $personnel['fonction_label']) ?> — <?= htmlspecialchars((string) $personnel['service_label']) ?></div>
                            </div>
                            <div class="attendance-summary-icon">
                                <i class="fas fa-id-badge text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <div class="attendance-summary-card attendance-summary-card--activity p-5">
                        <div class="absolute inset-0 bg-gradient-to-br from-emerald-50 to-white transition group-hover:from-emerald-100 group-hover:to-sky-50"></div>
                        <div class="relative flex items-start justify-between gap-4">
                            <div>
                                <div class="attendance-summary-label text-xs uppercase tracking-[0.22em]">Synthèse du mois</div>
                                <div class="mt-3 grid gap-2">
                                    <div class="flex items-baseline gap-2">
                                        <div class="attendance-summary-value text-3xl"><?= number_format((float) $summary['worked_days'], 2, ',', ' ') ?></div>
                                        <div class="text-sm font-semibold text-slate-700">Jours</div>
                                    </div>
                                    <div class="text-sm text-slate-700"><span class="font-semibold">Heures supp:</span> <?= number_format((float) $summary['overtime_hours'], 2, ',', ' ') ?></div>
                                </div>
                            </div>
                            <div class="attendance-summary-icon">
                                <i class="fas fa-chart-line text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <div class="attendance-summary-card <?= $absencesToJustifyCount>0?'attendance-summary-card--danger':'attendance-summary-card--safe' ?> p-5">
                        <div class="absolute inset-0 bg-gradient-to-br from-amber-50 to-white transition group-hover:from-amber-100 group-hover:to-rose-50"></div>
                        <div class="relative flex items-start justify-between gap-4">
                            <div>
                                <div class="attendance-summary-label text-xs uppercase tracking-[0.22em]">Alertes de présence</div>
                                <div class="mt-3 flex items-baseline gap-2">
                                    <div class="attendance-summary-value text-3xl"><?= (int) $absencesToJustifyCount ?></div>
                                    <div class="text-sm font-semibold text-slate-700"><?= $absencesToJustifyCount>0?'Absence(s) du mois à justifier':'Aucune absence à justifier' ?></div>
                                </div>
                                <?php if ($absencesPendingValidationCount > 0): ?>
                                    <div class="mt-2 text-xs font-semibold text-indigo-700"><?= (int) $absencesPendingValidationCount ?> justification(s) en attente RH</div>
                                <?php endif; ?>
                            </div>
                            <div class="attendance-summary-icon">
                                <i class="fas <?= $absencesToJustifyCount>0?'fa-triangle-exclamation':'fa-circle-check' ?> text-xl"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($absenceJustificationCards): ?>
                    <div class="mt-5 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-4 text-sm text-amber-900">
                        <div class="font-semibold">Absences à justifier</div>
                        <p class="mt-1 text-xs font-medium text-amber-800">Ces jours restent justifiables pendant le mois consulte. Les relances mails ne bloquent plus la soumission du justificatif.</p>
                        <div class="mt-2 overflow-x-auto">
                            <table class="min-w-full text-left text-sm">
                                <thead>
                                    <tr class="border-b border-amber-200/70 text-amber-900">
                                        <th class="px-3 py-2 font-semibold">Periode</th>
                                        <th class="px-3 py-2 font-semibold">Statut</th>
                                        <th class="px-3 py-2 font-semibold">Justification</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (array_slice(array_values($absenceJustificationCards), 0, 12) as $rangeCard): ?>
                                        <?php
                                        $dateAbs = (string) ($rangeCard['anchor_date'] ?? '');
                                        $reg = $regularizationByDate[$dateAbs] ?? null;
                                        $regStatus = strtolower((string) ($reg['status'] ?? ''));
                                        $regId = (int) ($reg['id'] ?? 0);
                                        $rangeInfo = (array) $rangeCard;
                                        $periodInfo = (array) ($rangeInfo['period'] ?? []);
                                        $justifiedRanges = (array) ($rangeInfo['justified'] ?? []);
                                        $remainingRanges = (array) ($rangeInfo['remaining'] ?? []);
                                        $firstRemaining = (array) ($remainingRanges[0] ?? []);
                                        $defaultRangeStart = (string) ($firstRemaining['date_debut'] ?? $dateAbs);
                                        $defaultRangeEnd = (string) ($firstRemaining['date_fin'] ?? $dateAbs);
                                        ?>
                                        <tr class="border-b border-amber-200/50 last:border-b-0">
                                            <td class="px-3 py-2 font-semibold">
                                                <?= htmlspecialchars(employeeFormatDateFr((string) ($periodInfo['date_debut'] ?? $dateAbs))) ?>
                                                -
                                                <?= htmlspecialchars(employeeFormatDateFr((string) ($periodInfo['date_fin'] ?? $dateAbs))) ?>
                                            </td>
                                            <td class="px-3 py-2"><?= htmlspecialchars($statuses[(string) ($rangeCard['attendance_status'] ?? 'absence')] ?? 'Absent') ?></td>
                                            <td class="px-3 py-2">
                                                <?php if ($periodInfo): ?>
                                                    <div class="mb-2 rounded-xl border border-amber-200 bg-amber-100/60 px-3 py-2 text-xs text-amber-950">
                                                        <div><span class="font-semibold">Periode totale:</span> <?= htmlspecialchars(employeeFormatDateFr((string) $periodInfo['date_debut'])) ?> - <?= htmlspecialchars(employeeFormatDateFr((string) $periodInfo['date_fin'])) ?> (<?= (int) ($periodInfo['nb_jours'] ?? 0) ?> j)</div>
                                                        <div><span class="font-semibold">Couvert:</span> <?= (int) ($rangeInfo['covered_days'] ?? 0) ?> j</div>
                                                        <?php if ($justifiedRanges): ?>
                                                            <div class="mt-1"><span class="font-semibold">Justifie:</span>
                                                                <?= htmlspecialchars(implode(', ', array_map(static function (array $r): string {
                                                                    return ((string) ($r['date_debut'] ?? '')) . ' -> ' . ((string) ($r['date_fin'] ?? '')) . ' (' . (int) ($r['nb_jours'] ?? 0) . ' j, ' . (string) ($r['statut_validation'] ?? '') . ')';
                                                                }, $justifiedRanges))) ?>
                                                            </div>
                                                        <?php endif; ?>
                                                        <?php if ($remainingRanges): ?>
                                                            <div class="mt-1"><span class="font-semibold">Restant:</span>
                                                                <?= htmlspecialchars(implode(', ', array_map(static function (array $r): string {
                                                                    return ((string) ($r['date_debut'] ?? '')) . ' -> ' . ((string) ($r['date_fin'] ?? '')) . ' (' . (int) ($r['nb_jours'] ?? 0) . ' j)';
                                                                }, $remainingRanges))) ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if ($reg && $regStatus === 'submitted' && $regId > 0): ?>
                                                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                                        <span class="inline-flex items-center rounded-full border border-indigo-200 bg-indigo-50 px-3 py-1 text-xs font-semibold text-indigo-800">En attente RH</span>
                                                        <a class="text-sm font-semibold text-indigo-700 hover:text-indigo-800" href="details_demande_employe.php?id=<?= (int) $regId ?>" target="_blank" rel="noopener">Voir la demande</a>
                                                    </div>
                                                <?php else: ?>
                                                    <?php
                                                    $rowStatus = strtolower((string) ($rangeCard['attendance_status'] ?? 'absence'));
                                                    $defaultTarget = $rowStatus === 'maladie' ? 'maladie' : 'conge';
                                                    $formKey = preg_replace('/[^0-9]/', '', $dateAbs);
                                                    $inputId = 'justifFiles_' . $formKey;
                                                    $previewId = 'justifPreview_' . $formKey;
                                                    ?>
                                                    <details class="rounded-xl border border-amber-200 bg-white/70 px-3 py-2">
                                                        <summary class="cursor-pointer text-sm font-semibold text-amber-900">Justifier (validation RH)</summary>
                                                        <form method="POST" enctype="multipart/form-data" class="mt-3 space-y-3">
                                                            <input type="hidden" name="action" value="justify">
                                                            <input type="hidden" name="date" value="<?= htmlspecialchars($dateAbs) ?>">
                                                            <div class="grid gap-3 sm:grid-cols-2">
                                                                <div>
                                                                    <label class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Debut justificatif</label>
                                                                    <input type="date" name="date_debut_justificatif" min="<?= htmlspecialchars((string) ($periodInfo['date_debut'] ?? $dateAbs)) ?>" max="<?= htmlspecialchars((string) ($periodInfo['date_fin'] ?? $dateAbs)) ?>" value="<?= htmlspecialchars($defaultRangeStart) ?>" class="mt-1 w-full rounded-xl border border-amber-200 bg-white px-3 py-2 text-sm" required>
                                                                </div>
                                                                <div>
                                                                    <label class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Fin justificatif</label>
                                                                    <input type="date" name="date_fin_justificatif" min="<?= htmlspecialchars((string) ($periodInfo['date_debut'] ?? $dateAbs)) ?>" max="<?= htmlspecialchars((string) ($periodInfo['date_fin'] ?? $dateAbs)) ?>" value="<?= htmlspecialchars($defaultRangeEnd) ?>" class="mt-1 w-full rounded-xl border border-amber-200 bg-white px-3 py-2 text-sm" required>
                                                                </div>
                                                            </div>

                                                            <div>
                                                                <label class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Statut demandé</label>
                                                                <select name="requested_status" class="mt-1 w-full rounded-xl border border-amber-200 bg-white px-3 py-2 text-sm">
                                                                    <?php
                                                                    $targets = ['present', 'half_day', 'mission', 'conge', 'maladie'];
                                                                    foreach ($targets as $t) {
                                                                        $sel = ($t === $defaultTarget) ? 'selected' : '';
                                                                        echo '<option value="' . htmlspecialchars($t) . '" ' . $sel . '>' . htmlspecialchars($statuses[$t] ?? $t) . '</option>';
                                                                    }
                                                                    ?>
                                                                </select>
                                                            </div>

                                                            <div>
                                                                <label class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Motif</label>
                                                                <textarea name="note" rows="2" class="mt-1 w-full rounded-xl border border-amber-200 bg-white px-3 py-2 text-sm" placeholder="Ex: certificat médical, ordre de mission..." required></textarea>
                                                            </div>

                                                            <div>
                                                                <label class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Justificatifs (jpg/png/webp/pdf)</label>
                                                                <div data-justif-dropzone="1" data-input-id="<?= htmlspecialchars($inputId) ?>" data-preview-id="<?= htmlspecialchars($previewId) ?>" class="mt-1 cursor-pointer rounded-xl border border-dashed border-amber-200 bg-amber-50 px-4 py-4 text-center text-sm text-slate-700 transition hover:bg-white hover:border-amber-300">
                                                                    <div class="font-semibold text-slate-900"><i class="fas fa-cloud-upload-alt mr-2"></i>Glisser-déposer ou cliquer</div>
                                                                    <div class="mt-1 text-xs text-slate-500">Vous pouvez joindre plusieurs fichiers.</div>
                                                                    <input id="<?= htmlspecialchars($inputId) ?>" type="file" name="justificatifs[]" multiple accept="image/*,application/pdf" class="hidden">
                                                                </div>
                                                                <div id="<?= htmlspecialchars($previewId) ?>" class="mt-2 grid gap-2 sm:grid-cols-2"></div>
                                                            </div>

                                                            <div class="flex justify-end">
                                                                <button class="rounded-xl bg-amber-700 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-800" type="submit">Soumettre</button>
                                                            </div>
                                                        </form>
                                                    </details>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="mt-6 overflow-x-auto rounded-3xl border border-slate-200 bg-white/90 shadow-sm">
                    <table class="min-w-full text-left text-sm">
                        <thead>
                            <tr class="border-b border-slate-100 bg-slate-50 text-slate-600">
                                <th class="px-3 py-3 font-semibold">Date</th>
                                <th class="px-3 py-3 font-semibold">Statut</th>
                                <th class="px-3 py-3 font-semibold">Arrivée</th>
                                <th class="px-3 py-3 font-semibold">Départ</th>
                                <th class="px-3 py-3 font-semibold">Heures travaillées</th>
                                <th class="px-3 py-3 font-semibold">Jours</th>
                                <th class="px-3 py-3 font-semibold">HS</th>
                                <th class="px-3 py-3 font-semibold">Note</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $statusBadge = static function (string $s): array {
                                $s = strtolower(trim($s));
                                $map = [
                                    'present' => ['class' => 'border-emerald-200 bg-emerald-50 text-emerald-800', 'icon' => 'fa-circle-check'],
                                    'half_day' => ['class' => 'border-sky-200 bg-sky-50 text-sky-800', 'icon' => 'fa-clock'],
                                    'mission' => ['class' => 'border-indigo-200 bg-indigo-50 text-indigo-800', 'icon' => 'fa-briefcase'],
                                    'conge' => ['class' => 'border-violet-200 bg-violet-50 text-violet-800', 'icon' => 'fa-umbrella-beach'],
                                    'repos' => ['class' => 'border-slate-200 bg-slate-50 text-slate-700', 'icon' => 'fa-mug-saucer'],
                                    'absence' => ['class' => 'border-rose-200 bg-rose-50 text-rose-800', 'icon' => 'fa-circle-xmark'],
                                    'maladie' => ['class' => 'border-amber-200 bg-amber-50 text-amber-900', 'icon' => 'fa-kit-medical'],
                                ];
                                return $map[$s] ?? ['class' => 'border-slate-200 bg-white text-slate-700', 'icon' => 'fa-circle-question'];
                            };

                            $d = new DateTimeImmutable($start);
                            $endDt = new DateTimeImmutable($end);
                            while ($d <= $endDt):
                                $dateKey = $d->format('Y-m-d');
                                $e = $entries[$dateKey] ?? null;
                                $status = (string) ($e['attendance_status'] ?? '');
                                $note = (string) ($e['notes'] ?? '');
                                $badge = $statusBadge($status);
                                $checkIn = $e ? (string) ($e['check_in_time'] ?? '') : '';
                                $checkOut = $e ? (string) ($e['check_out_time'] ?? '') : '';
                                $workedMinutes = employeeAttendanceWorkedMinutes($checkIn, $checkOut);
                            ?>
                                <tr class="border-b border-slate-100 last:border-b-0">
                                    <td class="px-3 py-3 font-medium text-slate-800"><?= htmlspecialchars(employeeFormatDateFr($dateKey)) ?></td>
                                    <td class="px-3 py-3 text-slate-700">
                                        <span class="inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs font-semibold <?= htmlspecialchars($badge['class']) ?>">
                                            <i class="fas <?= htmlspecialchars($badge['icon']) ?>"></i>
                                            <?= htmlspecialchars($statuses[$status] ?? ($status ?: '—')) ?>
                                        </span>
                                    </td>
                                    <td class="px-3 py-3 font-medium text-slate-700"><?= htmlspecialchars(employeeAttendanceTimeLabel($checkIn)) ?></td>
                                    <td class="px-3 py-3 font-medium text-slate-700"><?= htmlspecialchars(employeeAttendanceTimeLabel($checkOut)) ?></td>
                                    <td class="px-3 py-3 font-semibold text-slate-900"><?= htmlspecialchars(employeeAttendanceDurationLabel($workedMinutes)) ?></td>
                                    <td class="px-3 py-3 text-slate-700"><?= htmlspecialchars($e ? number_format((float) $e['worked_days'], 2, ',', ' ') : '—') ?></td>
                                    <td class="px-3 py-3 text-slate-700"><?= htmlspecialchars($e ? number_format((float) $e['overtime_hours'], 2, ',', ' ') : '—') ?></td>
                                    <td class="px-3 py-3 text-slate-700">
                                        <?php if ($e && in_array($status, ['absence', 'maladie'], true) && trim($note) === ''): ?>
                                            <?php
                                            $reg = $regularizationByDate[$dateKey] ?? null;
                                            $regStatus = strtolower((string) ($reg['status'] ?? ''));
                                            $regId = (int) ($reg['id'] ?? 0);
                                            ?>
                                            <?php if ($reg && $regStatus === 'submitted' && $regId > 0): ?>
                                                <a class="inline-flex items-center rounded-full border border-indigo-200 bg-indigo-50 px-3 py-1 text-xs font-semibold text-indigo-800 hover:bg-indigo-100" href="details_demande_employe.php?id=<?= (int) $regId ?>" target="_blank" rel="noopener">En attente RH</a>
                                            <?php else: ?>
                                                <span class="inline-flex items-center rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-800">À justifier</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <?= htmlspecialchars($note !== '' ? $note : '—') ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php
                                $d = $d->modify('+1 day');
                            endwhile;
                            ?>
                        </tbody>
                    </table>
                </div>

                <div class="mt-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.28em] text-indigo-600">Annuel</p>
                            <h2 class="mt-2 text-xl font-semibold text-slate-950">Pointage annuel — <?= (int) $year ?></h2>
                            <p class="mt-1 text-sm text-slate-600">Synthèse par mois et indicateurs globaux.</p>
                        </div>
                        <form method="GET" class="flex flex-col gap-2 sm:flex-row sm:items-end">
                            <input type="hidden" name="period" value="<?= htmlspecialchars($period) ?>">
                            <div>
                                <label class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Année</label>
                                <input type="number" min="2000" max="2100" name="year" value="<?= (int) $year ?>" class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm">
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <button type="submit" class="inline-flex items-center gap-2 rounded-2xl bg-indigo-600 px-5 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                                    <i class="fas fa-rotate"></i>
                                    Mettre à jour
                                </button>
                                <a href="../request/export_pointage_employe_annual_excel.php?year=<?= (int) $year ?>" target="_blank" class="inline-flex items-center gap-2 rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-900 hover:bg-slate-50">
                                    <i class="fas fa-file-excel text-emerald-600"></i>
                                    Export Excel
                                </a>
                                <a href="../request/export_pointage_employe_annual_pdf.php?year=<?= (int) $year ?>" target="_blank" class="inline-flex items-center gap-2 rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-900 hover:bg-slate-50">
                                    <i class="fas fa-file-pdf text-rose-600"></i>
                                    Export PDF
                                </a>
                            </div>
                        </form>
                    </div>

                    <div class="mt-6 grid gap-4 md:grid-cols-4">
                        <div class="rounded-3xl border border-slate-200 bg-slate-50 p-4">
                            <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Total jours</div>
                            <div class="mt-2 text-2xl font-semibold text-slate-950"><?= number_format((float) $annualTotals['worked_days'], 2, ',', ' ') ?></div>
                        </div>
                        <div class="rounded-3xl border border-slate-200 bg-slate-50 p-4">
                            <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Total heures supp</div>
                            <div class="mt-2 text-2xl font-semibold text-slate-950"><?= number_format((float) $annualTotals['overtime_hours'], 2, ',', ' ') ?></div>
                        </div>
                        <div class="rounded-3xl border border-rose-200 bg-rose-50 p-4">
                            <div class="text-xs font-semibold uppercase tracking-[0.2em] text-rose-700">Absences</div>
                            <div class="mt-2 text-2xl font-semibold text-slate-950"><?= (int) $annualTotals['absence_days'] ?></div>
                        </div>
                        <div class="rounded-3xl border border-amber-200 bg-amber-50 p-4">
                            <div class="text-xs font-semibold uppercase tracking-[0.2em] text-amber-800">Maladie</div>
                            <div class="mt-2 text-2xl font-semibold text-slate-950"><?= (int) $annualTotals['maladie_days'] ?></div>
                        </div>
                    </div>

                    <div class="mt-6 overflow-x-auto rounded-3xl border border-slate-200 bg-white">
                        <table class="min-w-full text-left text-sm">
                            <thead>
                                <tr class="border-b border-slate-100 bg-slate-50 text-slate-600">
                                    <th class="px-3 py-3 font-semibold">Mois</th>
                                    <th class="px-3 py-3 font-semibold">Jours</th>
                                    <th class="px-3 py-3 font-semibold">HS</th>
                                    <th class="px-3 py-3 font-semibold">Présent</th>
                                    <th class="px-3 py-3 font-semibold">Mission</th>
                                    <th class="px-3 py-3 font-semibold">Congé</th>
                                    <th class="px-3 py-3 font-semibold">Absence</th>
                                    <th class="px-3 py-3 font-semibold">Maladie</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                for ($mm = 1; $mm <= 12; $mm++):
                                    $ym = sprintf('%04d-%02d', $year, $mm);
                                    $r = (array) ($annualByMonth[$ym] ?? []);
                                    $worked = (float) ($r['worked_days'] ?? 0);
                                    $hs = (float) ($r['overtime_hours'] ?? 0);
                                ?>
                                    <tr class="border-b border-slate-100 last:border-b-0">
                                        <td class="px-3 py-3 font-semibold text-slate-800"><?= htmlspecialchars($ym) ?></td>
                                        <td class="px-3 py-3 text-slate-700"><?= htmlspecialchars(number_format($worked, 2, ',', ' ')) ?></td>
                                        <td class="px-3 py-3 text-slate-700"><?= htmlspecialchars(number_format($hs, 2, ',', ' ')) ?></td>
                                        <td class="px-3 py-3 text-slate-700"><?= (int) ($r['present_days'] ?? 0) ?></td>
                                        <td class="px-3 py-3 text-slate-700"><?= (int) ($r['mission_days'] ?? 0) ?></td>
                                        <td class="px-3 py-3 text-slate-700"><?= (int) ($r['conge_days'] ?? 0) ?></td>
                                        <td class="px-3 py-3 text-slate-700"><?= (int) ($r['absence_days'] ?? 0) ?></td>
                                        <td class="px-3 py-3 text-slate-700"><?= (int) ($r['maladie_days'] ?? 0) ?></td>
                                    </tr>
                                <?php endfor; ?>
                                <tr class="border-t border-slate-200 bg-slate-50">
                                    <td class="px-3 py-3 font-semibold text-slate-900">Total</td>
                                    <td class="px-3 py-3 font-semibold text-slate-900"><?= htmlspecialchars(number_format((float) $annualTotals['worked_days'], 2, ',', ' ')) ?></td>
                                    <td class="px-3 py-3 font-semibold text-slate-900"><?= htmlspecialchars(number_format((float) $annualTotals['overtime_hours'], 2, ',', ' ')) ?></td>
                                    <td class="px-3 py-3 font-semibold text-slate-900"><?= (int) $annualTotals['present_days'] ?></td>
                                    <td class="px-3 py-3 font-semibold text-slate-900"><?= (int) $annualTotals['mission_days'] ?></td>
                                    <td class="px-3 py-3 font-semibold text-slate-900"><?= (int) $annualTotals['conge_days'] ?></td>
                                    <td class="px-3 py-3 font-semibold text-slate-900"><?= (int) $annualTotals['absence_days'] ?></td>
                                    <td class="px-3 py-3 font-semibold text-slate-900"><?= (int) $annualTotals['maladie_days'] ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    (function() {
        const humanSize = (bytes) => {
            const b = Number(bytes || 0);
            if (!isFinite(b) || b <= 0) return '0 Ko';
            const kb = b / 1024;
            if (kb < 1024) return Math.round(kb) + ' Ko';
            return (kb / 1024).toFixed(1) + ' Mo';
        };

        const renderPreview = (container, files) => {
            if (!container) return;
            container.innerHTML = '';
            files.forEach((f) => {
                const wrap = document.createElement('div');
                wrap.className = 'flex items-center gap-3 rounded-xl border border-slate-200 bg-white px-3 py-2';

                const left = document.createElement('div');
                left.className = 'flex h-10 w-10 items-center justify-center overflow-hidden rounded-lg border border-slate-200 bg-slate-50 text-slate-600';

                if (f.type && f.type.startsWith('image/')) {
                    const img = document.createElement('img');
                    img.src = URL.createObjectURL(f);
                    img.alt = '';
                    img.className = 'h-10 w-10 object-cover';
                    left.innerHTML = '';
                    left.appendChild(img);
                } else {
                    left.innerHTML = '<i class="fas fa-file-pdf"></i>';
                }

                const meta = document.createElement('div');
                meta.className = 'min-w-0 flex-1';
                const name = document.createElement('div');
                name.className = 'truncate text-sm font-semibold text-slate-900';
                name.textContent = f.name || 'Fichier';
                const size = document.createElement('div');
                size.className = 'text-xs text-slate-500';
                size.textContent = humanSize(f.size);
                meta.appendChild(name);
                meta.appendChild(size);

                wrap.appendChild(left);
                wrap.appendChild(meta);
                container.appendChild(wrap);
            });
        };

        document.querySelectorAll('[data-justif-dropzone]').forEach((zone) => {
            const inputId = zone.getAttribute('data-input-id');
            const previewId = zone.getAttribute('data-preview-id');
            const input = inputId ? document.getElementById(inputId) : null;
            const preview = previewId ? document.getElementById(previewId) : null;
            if (!input) return;

            const refresh = () => renderPreview(preview, Array.from(input.files || []));

            zone.addEventListener('click', () => input.click());
            input.addEventListener('change', refresh);

            zone.addEventListener('dragover', (e) => {
                e.preventDefault();
                zone.classList.add('border-amber-300');
            });
            zone.addEventListener('dragleave', () => zone.classList.remove('border-amber-300'));
            zone.addEventListener('drop', (e) => {
                e.preventDefault();
                zone.classList.remove('border-amber-300');
                const dt = new DataTransfer();
                Array.from(e.dataTransfer.files || []).forEach((f) => dt.items.add(f));
                input.files = dt.files;
                refresh();
            });
        });
    })();
</script>

<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>
