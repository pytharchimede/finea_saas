<?php

function employeeCurrentUserName(): string
{
    $userName = trim((string) ($_SESSION['user_name'] ?? ''));
    if ($userName !== '') {
        return $userName;
    }

    $prenom = trim((string) ($_SESSION['prenom'] ?? ''));
    $nom = trim((string) ($_SESSION['nom'] ?? ''));
    return trim($prenom . ' ' . $nom);
}

function employeeFindPersonnelProfile(PDO $pdo, ?string $email): ?array
{
    $email = strtolower(trim((string) $email));
    if ($email === '') {
        return null;
    }

    $stmt = $pdo->prepare('
        SELECT
            p.id_personnel_soignant AS personnel_id,
            p.matricule_personnel_soignant AS matricule,
            p.nom_personnel_soignant AS full_name,
            p.email_personnel_soignant AS email,
            p.date_recrutement AS hire_date,
            p.date_prise_service AS start_date,
            COALESCE(NULLIF(TRIM(s.lib_service), ""), "Service non renseigne") AS service_label,
            COALESCE(NULLIF(TRIM(f.lib_fonction), ""), "Fonction non renseignee") AS fonction_label
        FROM personnel p
        LEFT JOIN service s ON s.id_service = p.service_id
        LEFT JOIN fonction f ON f.id_fonction = p.fonction_id
        WHERE LOWER(TRIM(p.email_personnel_soignant)) = :email
        LIMIT 1
    ');

    $stmt->execute(['email' => $email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function employeeListUnjustifiedAbsences(PDO $pdo, int $personnelId, int $daysBack = 60, ?string $period = null): array
{
    if (employeeIsPointageExempt($pdo, $personnelId)) {
        return [];
    }

    if ($period !== null && preg_match('/^\d{4}-\d{2}$/', $period)) {
        $startDate = $period . '-01';
        $monthEnd = date('Y-m-t', strtotime($startDate));
        $today = (new DateTimeImmutable('today'))->format('Y-m-d');
        $endDate = $monthEnd > $today ? $today : $monthEnd;
    } else {
        $daysBack = max(1, min(365, $daysBack));
        $startDate = (new DateTimeImmutable('today'))->modify('-' . ($daysBack - 1) . ' days')->format('Y-m-d');
        $endDate = (new DateTimeImmutable('today'))->format('Y-m-d');
    }

    $stmt = $pdo->prepare('
        SELECT
            attendance_date,
            attendance_status,
            worked_days,
            overtime_hours,
            notes
        FROM rh_attendance_daily
        WHERE personnel_id = :pid
          AND attendance_date BETWEEN :start_date AND :end_date
          AND attendance_status = "absence"
        ORDER BY attendance_date DESC
    ');

    $stmt->bindValue(':pid', $personnelId, PDO::PARAM_INT);
    $stmt->bindValue(':start_date', $startDate);
    $stmt->bindValue(':end_date', $endDate);
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $holidays = employeeHolidayDateSet($pdo, $startDate, $endDate);
    $regularizations = employeeRegularizationsByDate($pdo, $personnelId, $startDate, $endDate);
    $rowByDate = [];
    foreach ($rows as $row) {
        $date = (string) ($row['attendance_date'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $rowByDate[$date] = $row;
        }
    }

    $profileStartDate = employeePersonnelStartDate($pdo, $personnelId);
    $cursor = new DateTimeImmutable($startDate);
    $last = new DateTimeImmutable($endDate);
    while ($cursor <= $last) {
        $date = $cursor->format('Y-m-d');
        if ($profileStartDate !== null && $date < $profileStartDate) {
            $cursor = $cursor->modify('+1 day');
            continue;
        }

        $isWeekend = (int) $cursor->format('N') >= 6;
        if (!$isWeekend && !isset($holidays[$date]) && !isset($rowByDate[$date])) {
            $rowByDate[$date] = [
                'attendance_date' => $date,
                'attendance_status' => 'absence',
                'worked_days' => 0,
                'overtime_hours' => 0,
                'notes' => '',
                'is_virtual_absence' => true,
            ];
        }

        $cursor = $cursor->modify('+1 day');
    }

    krsort($rowByDate);
    $out = [];

    foreach ($rowByDate as $row) {
        $date = (string) ($row['attendance_date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            continue;
        }

        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$day) {
            continue;
        }

        $isWeekend = (int) $day->format('N') >= 6;
        if ($isWeekend || isset($holidays[$date])) {
            continue;
        }

        if (strtolower((string) ($row['attendance_status'] ?? '')) !== 'absence') {
            continue;
        }

        $regularization = $regularizations[$date] ?? null;
        $regularizationStatus = strtolower((string) ($regularization['status'] ?? ''));
        if ($regularizationStatus === 'approved') {
            continue;
        }

        $row['regularization_id'] = (int) ($regularization['id'] ?? 0);
        $row['regularization_status'] = $regularizationStatus;
        $row['regularization_requested_status'] = (string) ($regularization['requested_status'] ?? '');
        $row['is_to_justify'] = !$regularization || in_array($regularizationStatus, ['', 'rejected', 'cancelled'], true);
        $row['is_pending_validation'] = $regularizationStatus === 'submitted';
        $out[] = $row;
    }

    return $out;
}

function employeeIsPointageExempt(PDO $pdo, int $personnelId): bool
{
    if ($personnelId <= 0) {
        return false;
    }

    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM rh_pointage_exemptions WHERE personnel_id = :personnel_id AND is_active = 1');
        $stmt->execute(['personnel_id' => $personnelId]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function employeeCountAbsencesToJustify(array $absences): int
{
    $count = 0;
    foreach ($absences as $absence) {
        if (!empty($absence['is_to_justify'])) {
            $count++;
        }
    }

    return $count;
}

function employeeCountAbsencesPendingValidation(array $absences): int
{
    $count = 0;
    foreach ($absences as $absence) {
        if (!empty($absence['is_pending_validation'])) {
            $count++;
        }
    }

    return $count;
}

function employeeHolidayDateSet(PDO $pdo, string $startDate, string $endDate): array
{
    try {
        $stmt = $pdo->prepare('SELECT holiday_date FROM rh_holidays WHERE holiday_date BETWEEN :start_date AND :end_date');
        $stmt->execute([
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
    } catch (Throwable $e) {
        return [];
    }

    $set = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $date = (string) ($row['holiday_date'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $set[$date] = true;
        }
    }

    return $set;
}

function employeePersonnelStartDate(PDO $pdo, int $personnelId): ?string
{
    try {
        $stmt = $pdo->prepare('
            SELECT COALESCE(NULLIF(date_prise_service, "0000-00-00"), NULLIF(date_recrutement, "0000-00-00")) AS start_date
            FROM personnel
            WHERE id_personnel_soignant = :pid
            LIMIT 1
        ');
        $stmt->execute(['pid' => $personnelId]);
        $date = (string) ($stmt->fetchColumn() ?: '');
    } catch (Throwable $e) {
        return null;
    }

    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null;
}

function employeeRegularizationsByDate(PDO $pdo, int $personnelId, string $startDate, string $endDate): array
{
    $needleNumeric = '%"personnel_id":' . $personnelId . '%';
    $needleString = '%"personnel_id":"' . $personnelId . '"%';

    try {
        $stmt = $pdo->prepare('
            SELECT id, status, payload_json, submitted_at, decided_at, updated_at
            FROM employee_legal_requests
            WHERE request_type = "regularisation_pointage"
              AND (payload_json LIKE :needle_numeric OR payload_json LIKE :needle_string)
              AND status IN ("submitted", "approved", "rejected", "cancelled")
            ORDER BY submitted_at ASC, id ASC
        ');
        $stmt->execute([
            'needle_numeric' => $needleNumeric,
            'needle_string' => $needleString,
        ]);
    } catch (Throwable $e) {
        return [];
    }

    $byDate = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
        if (!is_array($payload)) {
            continue;
        }

        if ((int) ($payload['personnel_id'] ?? 0) !== $personnelId) {
            continue;
        }

        $rangeStart = (string) ($payload['date_debut_justificatif'] ?? ($payload['attendance_date'] ?? ''));
        $rangeEnd = (string) ($payload['date_fin_justificatif'] ?? $rangeStart);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $rangeStart) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $rangeEnd)) {
            continue;
        }
        if ($rangeEnd < $rangeStart) {
            continue;
        }

        $row['requested_status'] = (string) ($payload['requested_status'] ?? '');
        $cursor = new DateTimeImmutable(max($rangeStart, $startDate));
        $end = new DateTimeImmutable(min($rangeEnd, $endDate));
        while ($cursor <= $end) {
            $date = $cursor->format('Y-m-d');
            $previous = $byDate[$date] ?? null;
            if (!$previous) {
                $byDate[$date] = $row;
                $cursor = $cursor->modify('+1 day');
                continue;
            }

            $previousTs = strtotime((string) ($previous['updated_at'] ?? $previous['decided_at'] ?? $previous['submitted_at'] ?? '')) ?: 0;
            $currentTs = strtotime((string) ($row['updated_at'] ?? $row['decided_at'] ?? $row['submitted_at'] ?? '')) ?: 0;
            if ($currentTs >= $previousTs) {
                $byDate[$date] = $row;
            }
            $cursor = $cursor->modify('+1 day');
        }
    }

    return $byDate;
}

function employeeFormatDateFr(?string $date): string
{
    if (empty($date) || $date === '0000-00-00') {
        return '';
    }

    $ts = strtotime($date);
    return $ts ? date('d/m/Y', $ts) : (string) $date;
}
