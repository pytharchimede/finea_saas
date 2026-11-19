<?php
require_once __DIR__ . '/../auth_check.php';
AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../controllers/RhAttendanceController.php';
require_once __DIR__ . '/../controllers/RhPayrollController.php';
require_once __DIR__ . '/../model/AttendanceApiClient.php';
$composerAutoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}

$pdo = Database::getConnection();
$manager = (new RhPayrollController($pdo))->service();
$rhAttendanceController = new RhAttendanceController($pdo);
$attendanceSync = $rhAttendanceController->sync();
$absenceNotifier = $rhAttendanceController->absenceNotifier();
$isAdminUser = \AccessManager::hasPermission('acces_admin');

$selectedDate = (string) ($_GET['date'] ?? $_POST['attendance_date'] ?? date('Y-m-d'));
$message = null;
$errorMessage = null;

function rhEnsureAttendanceSettingsTable(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS rh_attendance_settings (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        setting_key VARCHAR(80) NOT NULL,
        setting_value VARCHAR(120) NOT NULL,
        setting_label VARCHAR(190) DEFAULT NULL,
        updated_by INT DEFAULT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_rh_attendance_setting_key (setting_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function rhGetAttendanceSettings(PDO $pdo): array
{
    rhEnsureAttendanceSettingsTable($pdo);
    $settings = [
        'daily_work_target_hours' => '9.00',
        'late_reference_time' => '08:30',
        'arrival_tolerance_minutes' => '10',
        'departure_tolerance_minutes' => '10',
        'overtime_tolerance_minutes' => '90',
    ];

    $stmt = $pdo->query('SELECT setting_key, setting_value FROM rh_attendance_settings');
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = (string) ($row['setting_key'] ?? '');
        if (array_key_exists($key, $settings)) {
            $settings[$key] = (string) ($row['setting_value'] ?? $settings[$key]);
        }
    }

    return $settings;
}

function rhAttendanceIsApiSource(array $attendance): bool
{
    $recordedBy = strtolower(trim((string) ($attendance['recorded_by_name'] ?? '')));
    $notes = strtolower(trim((string) ($attendance['notes'] ?? '')));

    return !empty($attendance['badgeuse_has_event'])
        || $recordedBy === 'attendance-api'
        || $recordedBy === 'attendance-export'
        || $recordedBy === 'badgeuse'
        || $notes === 'auto: attendance-api'
        || strpos($notes, 'attendance-api') !== false
        || strpos($notes, 'attendance-export') !== false;
}

function rhAttendanceDisplayNote(array $attendance): string
{
    $note = trim((string) ($attendance['notes'] ?? ''));
    if ($note === '' || preg_match('/^auto\s*:\s*attendance-api$/i', $note)) {
        return '';
    }

    return $note;
}

function rhAttendanceSourceMeta(array $attendance): array
{
    $hasSavedRow = !empty($attendance['has_saved_row']);
    $recordedByName = trim((string) ($attendance['recorded_by_name'] ?? ''));
    $recordedByUserId = (int) ($attendance['recorded_by_user_id'] ?? 0);
    $hasTime = trim((string) ($attendance['check_in_time'] ?? '')) !== ''
        || trim((string) ($attendance['check_out_time'] ?? '')) !== '';

    if (rhAttendanceIsApiSource($attendance)) {
        return [
            'icon' => 'fa-fingerprint',
            'label' => 'Badgeuse',
            'detail' => $recordedByName === 'attendance-export'
                ? 'Heures corrigees depuis export badgeuse'
                : (!empty($attendance['badgeuse_has_event'])
                    ? 'Log badgeuse confirme pour ce matricule'
                    : 'Heures importees depuis attendance.fidest.ci'),
            'class' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
            'dot' => 'bg-emerald-500',
        ];
    }

    if ($hasSavedRow || $recordedByUserId > 0 || $recordedByName !== '' || $hasTime) {
        $detail = $recordedByName !== ''
            ? 'Saisie ou correction par ' . $recordedByName
            : 'Saisie ou correction manuelle';

        return [
            'icon' => 'fa-pen-to-square',
            'label' => 'Manuel',
            'detail' => $detail,
            'class' => 'border-indigo-200 bg-indigo-50 text-indigo-700',
            'dot' => 'bg-indigo-500',
        ];
    }

    return [
        'icon' => 'fa-circle-minus',
        'label' => 'Non saisi',
        'detail' => 'Aucune source enregistree',
        'class' => 'border-slate-200 bg-slate-50 text-slate-500',
        'dot' => 'bg-slate-300',
    ];
}

function rhAttendanceNormalizeHeader(string $value): string
{
    $value = trim($value);
    $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if (is_string($converted) && $converted !== '') {
        $value = $converted;
    }

    return strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '', $value));
}

function rhAttendanceNormalizeCode(string $value): string
{
    return strtoupper(trim((string) preg_replace('/\s+/', ' ', $value)));
}

function rhAttendanceParseExportDateTime($value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }

    if (is_numeric($value) && class_exists('\\PhpOffice\\PhpSpreadsheet\\Shared\\Date')) {
        try {
            return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $value)->format('Y-m-d H:i:s');
        } catch (Throwable $ignored) {
        }
    }

    $raw = trim(str_replace(["\xc2\xa0", 'T'], [' ', ' '], (string) $value));
    $raw = preg_replace('/\s+/', ' ', $raw) ?: $raw;
    $formats = [
        '!d/m/Y H:i:s',
        '!d/m/Y H:i',
        '!d-m-Y H:i:s',
        '!d-m-Y H:i',
        '!Y-m-d H:i:s',
        '!Y-m-d H:i',
    ];

    foreach ($formats as $format) {
        $date = DateTimeImmutable::createFromFormat($format, $raw);
        if ($date instanceof DateTimeImmutable) {
            return $date->format('Y-m-d H:i:s');
        }
    }

    $timestamp = strtotime($raw);
    return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
}

function rhAttendanceEnsureImportTables(PDO $pdo): void
{
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS rh_attendance_daily (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            personnel_id INT NOT NULL,
            attendance_date DATE NOT NULL,
            attendance_status VARCHAR(32) NOT NULL DEFAULT "present",
            worked_days DECIMAL(5,2) NOT NULL DEFAULT 1.00,
            overtime_hours DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            check_in_time TIME DEFAULT NULL,
            check_out_time TIME DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            recorded_by_user_id INT DEFAULT NULL,
            recorded_by_name VARCHAR(150) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_rh_attendance_personnel_day (personnel_id, attendance_date),
            KEY idx_rh_attendance_date (attendance_date),
            KEY idx_rh_attendance_status (attendance_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ');

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS attendance_events (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            event_uid VARCHAR(80) NOT NULL,
            occurred_at DATETIME NOT NULL,
            occurred_on DATE NOT NULL,
            employee_code VARCHAR(60) NOT NULL,
            employee_name VARCHAR(150) DEFAULT NULL,
            event_label VARCHAR(190) DEFAULT NULL,
            event_code VARCHAR(20) DEFAULT NULL,
            payload_json LONGTEXT DEFAULT NULL,
            inserted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_attendance_event_uid (event_uid),
            KEY idx_attendance_events_occurred_at (occurred_at),
            KEY idx_attendance_events_employee_code (employee_code),
            KEY idx_attendance_events_occurred_on (occurred_on)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ');
}

function rhAttendanceReadBadgeuseExportRows(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Fichier export badgeuse introuvable ou invalide.');
    }
    if (!class_exists('\\PhpOffice\\PhpSpreadsheet\\IOFactory')) {
        throw new RuntimeException('Le lecteur Excel PhpSpreadsheet est indisponible.');
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    $name = (string) ($file['name'] ?? 'export.xlsx');
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($extension, ['xlsx', 'xls', 'csv'], true)) {
        throw new RuntimeException('Format non supporte. Utilisez un fichier xlsx, xls ou csv.');
    }

    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmpName);
    $sheet = $spreadsheet->getActiveSheet();
    $highestRow = $sheet->getHighestDataRow();
    $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
    $headerRow = 0;
    $columns = [];

    for ($row = 1; $row <= min(10, $highestRow); $row++) {
        $detected = [];
        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $header = rhAttendanceNormalizeHeader((string) $sheet->getCellByColumnAndRow($col, $row)->getCalculatedValue());
            if ($header === '') {
                continue;
            }
            if (str_contains($header, 'recu') && str_contains($header, 'gmt')) {
                $detected['received_at'] = $col;
            } elseif (str_contains($header, 'matricule')) {
                $detected['matricule'] = $col;
            } elseif ($header === 'pin') {
                $detected['pin'] = $col;
            } elseif ($header === 'nom') {
                $detected['name'] = $col;
            } elseif ($header === 'id') {
                $detected['id'] = $col;
            } elseif (str_contains($header, 'badgeusesn')) {
                $detected['device_sn'] = $col;
            } elseif (str_contains($header, 'etatpointag')) {
                $detected['event_code'] = $col;
            } elseif (str_contains($header, 'lignebrute')) {
                $detected['raw_line'] = $col;
            }
        }

        if (isset($detected['received_at']) && (isset($detected['matricule']) || isset($detected['pin']))) {
            $headerRow = $row;
            $columns = $detected;
            break;
        }
    }

    if ($headerRow <= 0) {
        throw new RuntimeException('Colonnes attendues introuvables : Recu GMT+0 et Matricule.');
    }

    $rows = [];
    for ($row = $headerRow + 1; $row <= $highestRow; $row++) {
        $receivedAt = rhAttendanceParseExportDateTime($sheet->getCellByColumnAndRow($columns['received_at'], $row)->getCalculatedValue());
        $matricule = isset($columns['matricule']) ? rhAttendanceNormalizeCode((string) $sheet->getCellByColumnAndRow($columns['matricule'], $row)->getCalculatedValue()) : '';
        $pin = isset($columns['pin']) ? trim((string) $sheet->getCellByColumnAndRow($columns['pin'], $row)->getCalculatedValue()) : '';
        if ($receivedAt === null || ($matricule === '' && $pin === '')) {
            continue;
        }

        $rows[] = [
            'id' => isset($columns['id']) ? trim((string) $sheet->getCellByColumnAndRow($columns['id'], $row)->getCalculatedValue()) : '',
            'received_at' => $receivedAt,
            'date' => substr($receivedAt, 0, 10),
            'time' => substr($receivedAt, 11, 8),
            'matricule' => $matricule,
            'pin' => $pin,
            'name' => isset($columns['name']) ? trim((string) $sheet->getCellByColumnAndRow($columns['name'], $row)->getCalculatedValue()) : '',
            'device_sn' => isset($columns['device_sn']) ? trim((string) $sheet->getCellByColumnAndRow($columns['device_sn'], $row)->getCalculatedValue()) : '',
            'event_code' => isset($columns['event_code']) ? trim((string) $sheet->getCellByColumnAndRow($columns['event_code'], $row)->getCalculatedValue()) : '',
            'raw_line' => isset($columns['raw_line']) ? trim((string) $sheet->getCellByColumnAndRow($columns['raw_line'], $row)->getCalculatedValue()) : '',
        ];
    }

    $spreadsheet->disconnectWorksheets();
    return $rows;
}

function rhAttendanceBuildPersonnelImportMap(PDO $pdo): array
{
    $map = ['matricule' => [], 'pin' => []];
    $stmt = $pdo->query('
        SELECT id_personnel_soignant AS id, matricule_personnel_soignant AS matricule
        FROM personnel
        WHERE matricule_personnel_soignant IS NOT NULL
          AND TRIM(matricule_personnel_soignant) <> ""
    ');
    foreach (($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []) ?: [] as $row) {
        $code = rhAttendanceNormalizeCode((string) ($row['matricule'] ?? ''));
        if ($code !== '') {
            $map['matricule'][$code] = (int) ($row['id'] ?? 0);
        }
    }

    try {
        $stmt = $pdo->query('SELECT employee_code, personnel_id FROM attendance_personnel_links');
        foreach (($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []) ?: [] as $row) {
            $code = trim((string) ($row['employee_code'] ?? ''));
            $personnelId = (int) ($row['personnel_id'] ?? 0);
            if ($code !== '' && $personnelId > 0) {
                $map['pin'][$code] = $personnelId;
            }
        }
    } catch (Throwable $ignored) {
    }

    return $map;
}

function rhAttendanceImportBadgeuseExport(PDO $pdo, array $file, array $actor): array
{
    rhAttendanceEnsureImportTables($pdo);
    $rows = rhAttendanceReadBadgeuseExportRows($file);
    $personnelMap = rhAttendanceBuildPersonnelImportMap($pdo);
    $eventStmt = $pdo->prepare('INSERT INTO attendance_events (
        event_uid, occurred_at, occurred_on, employee_code, employee_name, event_label, event_code, payload_json, inserted_at
    ) VALUES (
        :event_uid, :occurred_at, :occurred_on, :employee_code, :employee_name, "Export badgeuse", :event_code, :payload_json, NOW()
    ) ON DUPLICATE KEY UPDATE
        occurred_at = VALUES(occurred_at),
        occurred_on = VALUES(occurred_on),
        employee_code = VALUES(employee_code),
        employee_name = COALESCE(VALUES(employee_name), employee_name),
        event_label = VALUES(event_label),
        event_code = COALESCE(VALUES(event_code), event_code),
        payload_json = VALUES(payload_json)
    ');
    $dailyStmt = $pdo->prepare('INSERT INTO rh_attendance_daily (
        personnel_id, attendance_date, attendance_status, worked_days, overtime_hours, check_in_time, check_out_time,
        notes, recorded_by_user_id, recorded_by_name, created_at, updated_at
    ) VALUES (
        :personnel_id, :attendance_date, "present", 1.00, 0.00, :check_in_time, :check_out_time,
        NULL, NULL, "attendance-export", NOW(), NOW()
    ) ON DUPLICATE KEY UPDATE
        attendance_status = CASE
            WHEN recorded_by_user_id IS NOT NULL OR LOWER(TRIM(COALESCE(recorded_by_name, ""))) NOT IN ("", "attendance-api", "attendance-export", "badgeuse") THEN attendance_status
            WHEN (VALUES(check_in_time) IS NOT NULL OR VALUES(check_out_time) IS NOT NULL)
                 AND attendance_status IN ("absence", "repos") THEN "present"
            ELSE attendance_status
        END,
        worked_days = CASE
            WHEN recorded_by_user_id IS NOT NULL OR LOWER(TRIM(COALESCE(recorded_by_name, ""))) NOT IN ("", "attendance-api", "attendance-export", "badgeuse") THEN worked_days
            WHEN (VALUES(check_in_time) IS NOT NULL OR VALUES(check_out_time) IS NOT NULL)
                 AND worked_days < 1.00 THEN 1.00
            ELSE worked_days
        END,
        check_in_time = CASE
            WHEN check_in_time IS NULL THEN VALUES(check_in_time)
            WHEN VALUES(check_in_time) IS NULL THEN check_in_time
            WHEN recorded_by_user_id IS NOT NULL OR LOWER(TRIM(COALESCE(recorded_by_name, ""))) NOT IN ("", "attendance-api", "attendance-export", "badgeuse") THEN check_in_time
            WHEN VALUES(check_in_time) < check_in_time THEN VALUES(check_in_time)
            ELSE check_in_time
        END,
        check_out_time = CASE
            WHEN check_out_time IS NULL THEN VALUES(check_out_time)
            WHEN VALUES(check_out_time) IS NULL THEN check_out_time
            WHEN recorded_by_user_id IS NOT NULL OR LOWER(TRIM(COALESCE(recorded_by_name, ""))) NOT IN ("", "attendance-api", "attendance-export", "badgeuse") THEN check_out_time
            WHEN VALUES(check_out_time) > check_out_time THEN VALUES(check_out_time)
            ELSE check_out_time
        END,
        notes = CASE
            WHEN LOWER(TRIM(COALESCE(notes, ""))) = "auto: attendance-api" THEN NULL
            ELSE notes
        END,
        recorded_by_name = CASE
            WHEN recorded_by_user_id IS NULL AND LOWER(TRIM(COALESCE(recorded_by_name, ""))) IN ("", "attendance-api", "attendance-export", "badgeuse") THEN "attendance-export"
            ELSE recorded_by_name
        END,
        updated_at = NOW()
    ');

    $aggregates = [];
    $unmatched = 0;
    $events = 0;
    foreach ($rows as $row) {
        $personnelId = $personnelMap['matricule'][$row['matricule']] ?? 0;
        if ($personnelId <= 0 && $row['pin'] !== '') {
            $personnelId = $personnelMap['pin'][$row['pin']] ?? 0;
        }
        if ($personnelId <= 0) {
            $unmatched++;
            continue;
        }

        $employeeCode = $row['matricule'] !== '' ? $row['matricule'] : $row['pin'];
        $payload = $row;
        $payload['attendance_official_time_source'] = 'received_at_gmt0_export_column';
        $eventStmt->execute([
            ':event_uid' => 'badgeuse-export:' . sha1($employeeCode . '|' . $row['received_at'] . '|' . $row['pin'] . '|' . $row['id']),
            ':occurred_at' => $row['received_at'],
            ':occurred_on' => $row['date'],
            ':employee_code' => $employeeCode,
            ':employee_name' => $row['name'] !== '' ? $row['name'] : null,
            ':event_code' => $row['event_code'] !== '' ? $row['event_code'] : null,
            ':payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $events++;

        $key = $personnelId . '|' . $row['date'];
        if (!isset($aggregates[$key])) {
            $aggregates[$key] = [
                'personnel_id' => $personnelId,
                'date' => $row['date'],
                'in' => $row['time'],
                'out' => $row['time'],
            ];
            continue;
        }
        if ($row['time'] < $aggregates[$key]['in']) {
            $aggregates[$key]['in'] = $row['time'];
        }
        if ($row['time'] > $aggregates[$key]['out']) {
            $aggregates[$key]['out'] = $row['time'];
        }
    }

    $daily = 0;
    foreach ($aggregates as $aggregate) {
        $dailyStmt->execute([
            ':personnel_id' => $aggregate['personnel_id'],
            ':attendance_date' => $aggregate['date'],
            ':check_in_time' => $aggregate['in'],
            ':check_out_time' => $aggregate['out'],
        ]);
        $daily++;
    }

    return [
        'rows_read' => count($rows),
        'events_upserted' => $events,
        'daily_updated' => $daily,
        'unmatched' => $unmatched,
        'actor' => $actor,
    ];
}

function rhAttendanceTimeSourceMeta(array $attendance, string $field, array $apiTimes): array
{
    $time = substr(trim((string) ($attendance[$field] ?? '')), 0, 5);
    if ($time === '') {
        return [
            'label' => '-',
            'detail' => 'Aucune heure renseignee',
            'class' => 'border-slate-200 bg-slate-50 text-slate-500',
            'icon' => 'fa-circle-minus',
        ];
    }

    $apiKey = $field === 'check_out_time' ? 'out' : 'in';
    $apiTime = substr(trim((string) ($apiTimes[$apiKey] ?? '')), 0, 5);
    if ($apiTime !== '' && $apiTime === $time) {
        return [
            'label' => 'Badgeuse',
            'detail' => 'Heure confirmee par attendance.fidest.ci',
            'class' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
            'icon' => 'fa-fingerprint',
        ];
    }

    return [
        'label' => 'Manuel',
        'detail' => 'Heure saisie ou corrigee dans FINEA',
        'class' => 'border-indigo-200 bg-indigo-50 text-indigo-700',
        'icon' => 'fa-pen-to-square',
    ];
}

function rhAttendanceCodeAliases(string $value): array
{
    $normalized = strtoupper(trim($value));
    if ($normalized === '') {
        return [];
    }

    $aliases = [$normalized => true];
    $compact = preg_replace('/[\s\-_]+/', '', $normalized);
    if (is_string($compact) && $compact !== '') {
        $aliases[$compact] = true;
    }

    foreach (array_keys($aliases) as $alias) {
        if (preg_match('/(\d+)$/', (string) $alias, $matches)) {
            $number = ltrim($matches[1], '0');
            if ($number !== '') {
                $aliases[$number] = true;
            }
        }
    }

    return array_keys($aliases);
}

function rhLoadBadgeuseTimeMap(PDO $pdo, array $items, string $date): array
{
    $aliasToPersonnel = [];
    foreach ($items as $item) {
        $employee = $item['employee'] ?? [];
        $personnelId = (int) ($employee['id'] ?? 0);
        $matricule = (string) ($employee['matricule'] ?? '');
        if ($personnelId <= 0 || trim($matricule) === '') {
            continue;
        }
        foreach (rhAttendanceCodeAliases($matricule) as $alias) {
            $aliasToPersonnel[$alias] = $personnelId;
        }
    }

    try {
        $stmt = $pdo->query('SELECT employee_code, personnel_id FROM attendance_personnel_links');
        foreach (($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []) ?: [] as $row) {
            $personnelId = (int) ($row['personnel_id'] ?? 0);
            if ($personnelId <= 0) {
                continue;
            }
            foreach (rhAttendanceCodeAliases((string) ($row['employee_code'] ?? '')) as $alias) {
                $aliasToPersonnel[$alias] = $personnelId;
            }
        }
    } catch (Throwable $ignored) {
    }

    if (!$aliasToPersonnel) {
        return [];
    }

    try {
        $stmt = $pdo->prepare('
            SELECT employee_code, MIN(TIME(occurred_at)) AS first_time, MAX(TIME(occurred_at)) AS last_time
            FROM attendance_events
            WHERE occurred_on = :attendance_date
              AND employee_code IS NOT NULL
              AND TRIM(employee_code) <> ""
            GROUP BY employee_code
        ');
        $stmt->execute([':attendance_date' => $date]);
    } catch (Throwable $ignored) {
        return [];
    }

    $map = [];
    foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
        foreach (rhAttendanceCodeAliases((string) ($row['employee_code'] ?? '')) as $alias) {
            $personnelId = (int) ($aliasToPersonnel[$alias] ?? 0);
            if ($personnelId <= 0) {
                continue;
            }
            $map[$personnelId] = [
                'in' => substr((string) ($row['first_time'] ?? ''), 0, 5),
                'out' => substr((string) ($row['last_time'] ?? ''), 0, 5),
            ];
        }
    }

    return $map;
}

function rhAttendanceDeviceStatus(): array
{
    try {
        $client = new AttendanceApiClient();
        $health = $client->health();
        $devices = $client->devices(['limit' => 10]);
        $rows = is_array($devices['data'] ?? null) ? $devices['data'] : [];
        $device = is_array($rows[0] ?? null) ? $rows[0] : [];
        $status = strtolower(trim((string) ($device['connection_status'] ?? 'unknown')));
        $clockStatus = strtolower(trim((string) ($device['clock_status'] ?? 'unknown')));
        $delay = $device['collection_delay_seconds'] ?? null;
        $timeSources = is_array($health['time_sources'] ?? null) ? $health['time_sources'] : [];
        $delta = isset($timeSources['mysql_php_delta_seconds']) && is_numeric($timeSources['mysql_php_delta_seconds'])
            ? (int) $timeSources['mysql_php_delta_seconds']
            : null;

        return [
            'ok' => true,
            'label' => (string) ($device['name'] ?? $device['serial_number'] ?? 'Badgeuse'),
            'status' => $status,
            'clock_status' => $clockStatus,
            'last_seen_at' => (string) ($device['last_seen_at'] ?? ''),
            'last_device_time_at' => (string) ($device['last_device_time_at'] ?? ''),
            'last_device_time_seen_at' => (string) ($device['last_device_time_seen_at'] ?? ''),
            'collection_delay_seconds' => is_numeric($delay) ? (int) $delay : null,
            'server_time' => (string) ($health['time_sources']['php_gmt'] ?? $health['time'] ?? ''),
            'mysql_php_delta_seconds' => $delta,
        ];
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'error' => $e->getMessage(),
        ];
    }
}

function rhAttendanceDeviceStatusTone(array $status): array
{
    if (empty($status['ok'])) {
        return ['class' => 'border-rose-200 bg-rose-50 text-rose-800', 'icon' => 'fa-triangle-exclamation', 'label' => 'API badgeuse indisponible'];
    }
    $connection = strtolower((string) ($status['status'] ?? ''));
    if (str_contains($connection, 'offline') || str_contains($connection, 'never_seen')) {
        return ['class' => 'border-rose-200 bg-rose-50 text-rose-800', 'icon' => 'fa-plug-circle-xmark', 'label' => 'Badgeuse hors ligne'];
    }
    $delta = $status['mysql_php_delta_seconds'] ?? null;
    if (is_int($delta) && abs($delta) > 30) {
        return ['class' => 'border-amber-200 bg-amber-50 text-amber-800', 'icon' => 'fa-clock-rotate-left', 'label' => 'Heure serveur a verifier'];
    }

    return ['class' => 'border-emerald-200 bg-emerald-50 text-emerald-800', 'icon' => 'fa-circle-check', 'label' => 'Badgeuse en ligne'];
}

$attendanceSettings = rhGetAttendanceSettings($pdo);
$dailyWorkTargetHours = (float) str_replace(',', '.', (string) ($attendanceSettings['daily_work_target_hours'] ?? '10.00'));
$lateReferenceTime = (string) ($attendanceSettings['late_reference_time'] ?? '08:40');
$arrivalToleranceMinutes = max(0, (int) ($attendanceSettings['arrival_tolerance_minutes'] ?? 10));
$departureToleranceMinutes = max(0, (int) ($attendanceSettings['departure_tolerance_minutes'] ?? 10));
$overtimeToleranceMinutes = max(0, (int) ($attendanceSettings['overtime_tolerance_minutes'] ?? 15));

if (!isset($_GET['ajax'])) {
    try {
        $absenceNotifier->scanMissingPointages(date('Y-m'), 50);
        $absenceNotifier->processDueReminders(50);
    } catch (Throwable $ignored) {
        // Les notifications ne doivent jamais bloquer la consultation du pointage.
    }
}

// Endpoint JSON (sans rechargement de page) pour synchroniser attendance.fidest.ci puis rafraichir les heures.
if (isset($_GET['ajax']) && (string) $_GET['ajax'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    $action = (string) ($_GET['action'] ?? 'times');
    if ($action === 'device_status') {
        echo json_encode([
            'ok' => true,
            'device_status' => rhAttendanceDeviceStatus(),
            'server_time' => date('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit();
    }
    if ($action !== 'times') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Action inconnue.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit();
    }
    if (!$isAdminUser) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Synchronisation reservee aux administrateurs.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit();
    }

    $date = (string) ($_GET['date'] ?? date('Y-m-d'));
    try {
        $syncResult = $attendanceSync->syncDate($date, [
            'user_id' => $_SESSION['user_id'] ?? null,
            'user_name' => $_SESSION['user_name'] ?? null,
        ], 200);

        $board = $manager->getAttendanceBoard($date);
        $apiTimeMap = rhLoadBadgeuseTimeMap($pdo, $board['items'] ?? [], (string) ($board['date'] ?? $date));
        $times = [];
        foreach (($board['items'] ?? []) as $item) {
            $pid = (int) (($item['employee']['id'] ?? 0));
            if ($pid <= 0) {
                continue;
            }
            $attendance = $item['attendance'] ?? [];
            $source = rhAttendanceSourceMeta($attendance);
            $apiTimes = $apiTimeMap[$pid] ?? [];
            $entrySource = rhAttendanceTimeSourceMeta($attendance, 'check_in_time', $apiTimes);
            $exitSource = rhAttendanceTimeSourceMeta($attendance, 'check_out_time', $apiTimes);
            $times[(string) $pid] = [
                'check_in_time' => (string) ($attendance['check_in_time'] ?? ''),
                'check_out_time' => (string) ($attendance['check_out_time'] ?? ''),
                'source_label' => $source['label'],
                'entry_source_label' => $entrySource['label'],
                'exit_source_label' => $exitSource['label'],
            ];
        }

        echo json_encode([
            'ok' => true,
            'date' => (string) ($board['date'] ?? $date),
            'times' => $times,
            'sync' => $syncResult,
            'server_time' => date('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit();
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit();
    }
}

$auditAttendance = static function (string $eventType, array $context = []) use ($selectedDate): void {
    \AuditTrail::log($eventType, array_merge([
        'module' => 'rh',
        'entity_type' => 'attendance',
        'user_id' => $_SESSION['user_id'] ?? null,
        'user_name' => $_SESSION['user_name'] ?? null,
    ], $context));
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $statuses = $_POST['status'] ?? [];
        $workedDays = $_POST['worked_days'] ?? [];
        $overtimeHours = $_POST['overtime_hours'] ?? [];
        $checkInTimes = $_POST['check_in_time'] ?? [];
        $checkOutTimes = $_POST['check_out_time'] ?? [];
        $notes = $_POST['notes'] ?? [];

        $entries = [];
        foreach ($statuses as $personnelId => $status) {
            $entries[$personnelId] = [
                'status' => $status,
                'worked_days' => $workedDays[$personnelId] ?? null,
                'overtime_hours' => $overtimeHours[$personnelId] ?? null,
                'check_in_time' => $checkInTimes[$personnelId] ?? null,
                'check_out_time' => $checkOutTimes[$personnelId] ?? null,
                'notes' => $notes[$personnelId] ?? null,
            ];
        }

        $actor = [
            'user_id' => $_SESSION['user_id'] ?? null,
            'user_name' => $_SESSION['user_name'] ?? null,
        ];

        $bulkApply = (string) ($_POST['bulk_apply'] ?? '');
        if ((string) ($_POST['import_badgeuse_export'] ?? '') === '1') {
            if (!$isAdminUser) {
                throw new RuntimeException('Import export badgeuse reserve aux administrateurs.');
            }
            $import = rhAttendanceImportBadgeuseExport($pdo, $_FILES['badgeuse_export_file'] ?? [], $actor);

            $auditAttendance('rh_attendance_badgeuse_export_imported', [
                'action' => 'import_badgeuse_export',
                'entity_label' => substr($selectedDate, 0, 7),
                'details' => $import,
            ]);

            header('Location: pointage_journalier_rh.php?date=' . urlencode($selectedDate)
                . '&import_rows=' . urlencode((string) ($import['rows_read'] ?? 0))
                . '&import_events=' . urlencode((string) ($import['events_upserted'] ?? 0))
                . '&import_daily=' . urlencode((string) ($import['daily_updated'] ?? 0))
                . '&import_unmatched=' . urlencode((string) ($import['unmatched'] ?? 0)));
            exit();
        }

        if (isset($_POST['sync_biometric_month'])) {
            if (!$isAdminUser) {
                throw new RuntimeException('Recuperation biometrique reservee aux administrateurs.');
            }
            $period = substr($selectedDate, 0, 7);
            $startDate = $period . '-01';
            $endDate = (new DateTimeImmutable($startDate))->modify('last day of this month')->format('Y-m-d');
            $sync = $attendanceSync->syncPeriod($startDate, $endDate, $actor, 200);

            $auditAttendance(!empty($sync['ok']) ? 'rh_attendance_biometric_month_synced' : 'rh_attendance_biometric_month_sync_failed', [
                'action' => 'sync_biometric_month',
                'entity_label' => $period,
                'result' => !empty($sync['ok']) ? 'success' : 'failure',
                'severity' => !empty($sync['ok']) ? 'info' : 'warning',
                'details' => $sync,
            ]);

            header('Location: pointage_journalier_rh.php?date=' . urlencode($selectedDate)
                . '&sync_period=' . urlencode($period)
                . '&sync_ok=' . urlencode(!empty($sync['ok']) ? '1' : '0')
                . '&sync_fetched=' . urlencode((string) ($sync['fetched_count'] ?? 0))
                . '&sync_inserted=' . urlencode((string) ($sync['inserted_count'] ?? 0))
                . '&sync_punches=' . urlencode((string) ($sync['day_punch_count'] ?? 0))
                . '&sync_aggregated=' . urlencode((string) ($sync['aggregated_employee_count'] ?? 0))
                . '&sync_linked=' . urlencode((string) ($sync['linked_daily_updated'] ?? 0))
                . '&sync_error=' . urlencode((string) ($sync['error'] ?? '')));
            exit();
        }

        if (isset($_POST['repair_calendar_statuses'])) {
            if (!$isAdminUser) {
                throw new RuntimeException('Correction mensuelle reservee aux administrateurs.');
            }
            $period = substr($selectedDate, 0, 7);
            $repair = $manager->repairAttendanceCalendarStatuses($period, $actor);

            $auditAttendance('rh_attendance_calendar_statuses_repaired', [
                'action' => 'repair_calendar_statuses',
                'entity_label' => $period,
                'details' => $repair,
            ]);

            header('Location: pointage_journalier_rh.php?date=' . urlencode($selectedDate)
                . '&repair_period=' . urlencode($period)
                . '&repair_checked=' . urlencode((string) ($repair['checked'] ?? 0))
                . '&repair_fixed=' . urlencode((string) ($repair['fixed'] ?? 0))
                . '&repair_present=' . urlencode((string) ($repair['present'] ?? 0))
                . '&repair_absence=' . urlencode((string) ($repair['absence'] ?? 0))
                . '&repair_repos=' . urlencode((string) ($repair['repos'] ?? 0)));
            exit();
        }

        if ($bulkApply === 'month_workdays') {
            $selectedBoard = $manager->getAttendanceBoard($selectedDate);
            if (empty($selectedBoard['is_working_day'])) {
                throw new RuntimeException('L application mensuelle est autorisee uniquement depuis un jour ouvre. Les repos sont calcules automatiquement.');
            }

            $period = substr($selectedDate, 0, 7);
            $dates = $manager->listWorkingDatesForPeriod($period);

            $bulkSaved = 0;
            foreach ($dates as $date) {
                $bulkSaved += $manager->saveDailyAttendance($date, $entries, $actor);
            }

            $auditAttendance('rh_bulk_attendance_saved', [
                'action' => 'bulk_month_workdays',
                'entity_label' => $period,
                'details' => [
                    'attendance_date' => $selectedDate,
                    'period' => $period,
                    'working_day_count' => count($dates),
                    'saved_rows' => $bulkSaved,
                    'employee_count' => count($entries),
                ],
            ]);

            header('Location: pointage_journalier_rh.php?date=' . urlencode($selectedDate)
                . '&bulk_period=' . urlencode($period)
                . '&bulk_days=' . urlencode((string) count($dates))
                . '&bulk_saved=' . urlencode((string) $bulkSaved));
            exit();
        }

        $saved = $manager->saveDailyAttendance($selectedDate, $entries, $actor);

        $auditAttendance('rh_daily_attendance_saved', [
            'action' => 'save_daily_attendance',
            'entity_label' => $selectedDate,
            'details' => [
                'attendance_date' => $selectedDate,
                'saved_rows' => $saved,
                'employee_count' => count($entries),
            ],
        ]);

        header('Location: pointage_journalier_rh.php?date=' . urlencode($selectedDate) . '&saved=' . $saved);
        exit();
    } catch (Throwable $exception) {
        $auditAttendance('rh_attendance_save_failed', [
            'action' => (string) ($_POST['bulk_apply'] ?? 'save_daily_attendance'),
            'entity_label' => $selectedDate,
            'result' => 'failure',
            'severity' => 'warning',
            'details' => [
                'attendance_date' => $selectedDate,
                'error' => $exception->getMessage(),
            ],
        ]);
        $errorMessage = $exception->getMessage();
    }
}

if (isset($_GET['import_rows'])) {
    $message = sprintf(
        'Import export badgeuse termine : %d ligne(s) lue(s), %d log(s) historise(s), %d collaborateur(s)/jour corrige(s), %d ligne(s) sans matricule lie.',
        (int) ($_GET['import_rows'] ?? 0),
        (int) ($_GET['import_events'] ?? 0),
        (int) ($_GET['import_daily'] ?? 0),
        (int) ($_GET['import_unmatched'] ?? 0)
    );
} elseif (isset($_GET['sync_period'])) {
    $period = (string) ($_GET['sync_period'] ?? '');
    if ((string) ($_GET['sync_ok'] ?? '') === '1') {
        $message = sprintf(
            'Recuperation biometrique terminee%s : %d log(s) lus, %d nouveau(x), %d pointage(s), %d collaborateur(s)/jour agreges, %d ligne(s) RH mises a jour.',
            $period !== '' ? (' pour ' . $period) : '',
            (int) ($_GET['sync_fetched'] ?? 0),
            (int) ($_GET['sync_inserted'] ?? 0),
            (int) ($_GET['sync_punches'] ?? 0),
            (int) ($_GET['sync_aggregated'] ?? 0),
            (int) ($_GET['sync_linked'] ?? 0)
        );
    } else {
        $errorMessage = 'Recuperation biometrique echouee : ' . (string) ($_GET['sync_error'] ?? 'erreur inconnue');
    }
} elseif (isset($_GET['repair_fixed'])) {
    $period = (string) ($_GET['repair_period'] ?? '');
    $message = sprintf(
        'Correction calendrier terminee%s : %d ligne(s) verifiee(s), %d corrigee(s) (%d present, %d absent, %d repos).',
        $period !== '' ? (' pour ' . $period) : '',
        (int) ($_GET['repair_checked'] ?? 0),
        (int) ($_GET['repair_fixed'] ?? 0),
        (int) ($_GET['repair_present'] ?? 0),
        (int) ($_GET['repair_absence'] ?? 0),
        (int) ($_GET['repair_repos'] ?? 0)
    );
} elseif (isset($_GET['bulk_saved'])) {
    $period = (string) ($_GET['bulk_period'] ?? '');
    $days = (int) ($_GET['bulk_days'] ?? 0);
    $message = sprintf(
        '%d ligne(s) de pointage enregistree(s) via application sur %d jour(s) ouvres%s.',
        (int) ($_GET['bulk_saved'] ?? 0),
        $days,
        $period !== '' ? (' (periode ' . $period . ')') : ''
    );
} elseif (isset($_GET['saved'])) {
    $message = sprintf('%d ligne(s) de pointage enregistree(s).', (int) $_GET['saved']);
}

$board = $manager->getAttendanceBoard($selectedDate);
$badgeuseTimeMap = rhLoadBadgeuseTimeMap($pdo, $board['items'] ?? [], (string) $board['date']);
$badgeuseStatus = rhAttendanceDeviceStatus();
$badgeuseTone = rhAttendanceDeviceStatusTone($badgeuseStatus);
$statuses = $manager->getAttendanceStatuses();
$selectableStatuses = array_filter(
    $statuses,
    static fn($meta, $key): bool => (string) $key !== 'repos',
    ARRAY_FILTER_USE_BOTH
);
$isWorkingDay = !empty($board['is_working_day']);

function attendanceStatusBadgeClass(string $status): string
{
    $map = [
        'present' => 'bg-emerald-100 text-emerald-700 border-emerald-200',
        'half_day' => 'bg-amber-100 text-amber-700 border-amber-200',
        'mission' => 'bg-sky-100 text-sky-700 border-sky-200',
        'conge' => 'bg-indigo-100 text-indigo-700 border-indigo-200',
        'repos' => 'bg-slate-100 text-slate-700 border-slate-200',
        'absence' => 'bg-rose-100 text-rose-700 border-rose-200',
        'maladie' => 'bg-orange-100 text-orange-700 border-orange-200',
    ];

    return $map[$status] ?? 'bg-slate-100 text-slate-700 border-slate-200';
}

$pageTitle = 'Pointage journalier RH';
$activeMenu = 'rh';

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>
<div class="min-h-screen bg-[radial-gradient(circle_at_top_left,_rgba(220,252,231,0.95),_transparent_35%),radial-gradient(circle_at_top_right,_rgba(224,231,255,0.82),_transparent_30%),linear-gradient(180deg,_#f8fafc_0%,_#eef6ff_100%)] pb-12">
    <div class="container mx-auto px-4 py-8">
        <div class="rounded-[32px] border border-emerald-100 bg-gradient-to-r from-slate-950 via-emerald-950 to-teal-900 px-8 py-8 text-white shadow-[0_24px_80px_rgba(15,23,42,0.28)]">
            <div class="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
                <div class="max-w-3xl">
                    <p class="text-xs font-semibold uppercase tracking-[0.35em] text-emerald-200">Pointage quotidien</p>
                    <h1 class="mt-3 text-3xl font-semibold tracking-tight md:text-5xl">Saisie rapide du pointage journalier</h1>
                    <p class="mt-4 text-sm text-emerald-50 md:text-base">
                        Marquez l'etat de presence, les demi-journees, les missions et les heures supplementaires en une seule grille. L'ecran est concu pour un usage quotidien tres rapide.
                    </p>
                    <p class="mt-3 text-xs text-emerald-100">Tolérances actives : arrivée +<?= (int) $arrivalToleranceMinutes ?> min, départ/déficit <?= (int) $departureToleranceMinutes ?> min, HS à partir de <?= (int) $overtimeToleranceMinutes ?> min au-delà de <?= htmlspecialchars(number_format((float) $dailyWorkTargetHours, 2, ',', ' ')) ?>h.</p>
                </div>
                <div class="flex flex-wrap gap-3">
                    <a href="paie_rh.php" class="inline-flex items-center rounded-2xl bg-white px-5 py-3 text-sm font-semibold text-slate-900 transition hover:bg-emerald-50">
                        <i class="fas fa-file-invoice-dollar mr-2 text-emerald-600"></i>
                        Nouvelle paie
                    </a>
                    <a href="feries_rh.php" class="inline-flex items-center rounded-2xl bg-white px-5 py-3 text-sm font-semibold text-slate-900 transition hover:bg-emerald-50">
                        <i class="fas fa-calendar-times mr-2 text-emerald-600"></i>
                        Feriés
                    </a>
                    <a href="dashboard_rh.php" class="inline-flex items-center rounded-2xl border border-white/15 px-5 py-3 text-sm font-semibold text-white/90 transition hover:bg-white/10">
                        <i class="fas fa-chart-line mr-2"></i>
                        Tableau de bord RH
                    </a>
                </div>
            </div>
        </div>

        <div class="mt-8 grid gap-5 md:grid-cols-2 xl:grid-cols-5">
            <div class="rounded-[26px] border border-white/80 bg-white/90 p-6 shadow-[0_16px_50px_rgba(15,23,42,0.06)]">
                <p class="text-sm font-medium text-slate-500">Effectif pointe</p>
                <p class="mt-3 text-4xl font-semibold text-slate-950"><?= number_format((int) $board['total'], 0, ',', ' ') ?></p>
            </div>
            <?php foreach (['present', 'mission', 'half_day', 'absence'] as $statusKey): ?>
                <div class="rounded-[26px] border p-6 shadow-[0_16px_50px_rgba(15,23,42,0.05)] <?= htmlspecialchars(attendanceStatusBadgeClass($statusKey)) ?>">
                    <p class="text-sm font-medium"><?= htmlspecialchars($statuses[$statusKey]['label']) ?></p>
                    <p class="mt-3 text-4xl font-semibold"><?= number_format((int) ($board['stats'][$statusKey] ?? 0), 0, ',', ' ') ?></p>
                </div>
            <?php endforeach; ?>
        </div>

        <section class="mt-6 rounded-[28px] border p-5 shadow-[0_16px_50px_rgba(15,23,42,0.05)] <?= htmlspecialchars($badgeuseTone['class']) ?>" id="badgeuse-health-card">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div class="flex items-start gap-4">
                    <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-white/70 text-xl">
                        <i class="fas <?= htmlspecialchars($badgeuseTone['icon']) ?>"></i>
                    </div>
                    <div>
                        <p class="text-xs font-black uppercase tracking-[0.24em] opacity-75">Etat badgeuse</p>
                        <h2 class="mt-1 text-xl font-black" id="badgeuse-health-title"><?= htmlspecialchars($badgeuseTone['label']) ?></h2>
                        <p class="mt-1 text-sm font-semibold opacity-85" id="badgeuse-health-detail">
                            <?= htmlspecialchars(!empty($badgeuseStatus['ok']) ? (string) ($badgeuseStatus['label'] ?? 'Badgeuse') : (string) ($badgeuseStatus['error'] ?? 'API indisponible')) ?>
                        </p>
                    </div>
                </div>
                <div class="grid gap-3 text-xs font-semibold sm:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-2xl bg-white/70 px-4 py-3">
                        <span class="block opacity-65">Dernier contact</span>
                        <strong id="badgeuse-last-seen"><?= htmlspecialchars((string) ($badgeuseStatus['last_seen_at'] ?? '-')) ?></strong>
                    </div>
                    <div class="rounded-2xl bg-white/70 px-4 py-3">
                        <span class="block opacity-65">Heure observee</span>
                        <strong id="badgeuse-device-time"><?= htmlspecialchars((string) ($badgeuseStatus['last_device_time_at'] ?? '-')) ?></strong>
                    </div>
                    <div class="rounded-2xl bg-white/70 px-4 py-3">
                        <span class="block opacity-65">Reception GMT+0</span>
                        <strong id="badgeuse-seen-time"><?= htmlspecialchars((string) ($badgeuseStatus['last_device_time_seen_at'] ?? '-')) ?></strong>
                    </div>
                    <div class="rounded-2xl bg-white/70 px-4 py-3">
                        <span class="block opacity-65">Delai collecte</span>
                        <strong id="badgeuse-delay"><?= isset($badgeuseStatus['collection_delay_seconds']) && is_int($badgeuseStatus['collection_delay_seconds']) ? htmlspecialchars((string) $badgeuseStatus['collection_delay_seconds']) . ' s' : '-' ?></strong>
                    </div>
                </div>
            </div>
        </section>

        <?php if ($message !== null): ?>
            <div class="mt-6 rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm font-medium text-emerald-700">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>
        <?php if ($errorMessage !== null): ?>
            <div class="mt-6 rounded-2xl border border-rose-200 bg-rose-50 px-5 py-4 text-sm font-medium text-rose-700">
                <?= htmlspecialchars($errorMessage) ?>
            </div>
        <?php endif; ?>

        <form method="get" class="mt-8 rounded-[28px] border border-white/80 bg-white/90 p-6 shadow-[0_16px_50px_rgba(15,23,42,0.06)]">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div class="flex flex-wrap gap-3">
                    <div>
                    <label class="mb-2 block text-sm font-medium text-slate-700">Date de pointage</label>
                    <input type="date" name="date" value="<?= htmlspecialchars($board['date']) ?>" class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-emerald-400 focus:bg-white">
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-slate-700">Mois à exporter</label>
                        <input type="month" name="period" value="<?= htmlspecialchars((string) ($_GET['period'] ?? substr((string) $board['date'], 0, 7))) ?>" class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm">
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-slate-700">Début export</label>
                        <input type="date" name="date_from" value="<?= htmlspecialchars((string) ($_GET['date_from'] ?? '')) ?>" class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm">
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-slate-700">Fin export</label>
                        <input type="date" name="date_to" value="<?= htmlspecialchars((string) ($_GET['date_to'] ?? '')) ?>" class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm">
                    </div>
                </div>
                <div class="flex flex-wrap gap-3">
                    <button type="submit" class="inline-flex items-center rounded-2xl bg-slate-950 px-5 py-3 text-sm font-semibold text-white transition hover:bg-slate-800">
                        <i class="fas fa-calendar-day mr-2"></i>
                        Charger la journee
                    </button>
                    <?php if ($isAdminUser): ?>
                        <button type="button" id="refresh-from-badgeuse" class="inline-flex items-center rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-3 text-sm font-semibold text-emerald-800 transition hover:bg-emerald-100">
                            <i class="fas fa-rotate mr-2 text-emerald-700"></i>
                            Synchroniser attendance.fidest.ci
                        </button>
                    <?php endif; ?>
                    <span id="attendance-live-status" class="inline-flex items-center rounded-2xl border border-slate-200 bg-white px-4 py-3 text-xs font-semibold text-slate-500">
                        <i class="fas fa-circle mr-2 text-emerald-500"></i>
                        <?= $isAdminUser ? 'Temps reel actif' : 'Surveillance badgeuse active' ?>
                    </span>
                    <a href="pointage_mensuel_personnel_rh.php" class="inline-flex items-center rounded-2xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-900 transition hover:bg-slate-50">
                        <i class="fas fa-calendar-alt mr-2 text-emerald-600"></i>
                        Pointage mensuel (1 personne)
                    </a>
                    <button type="submit" name="mode" value="journalier" formaction="export_pointage_pdf.php" formtarget="_blank" class="inline-flex items-center rounded-2xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-900 transition hover:bg-slate-50">
                        <i class="fas fa-file-pdf mr-2 text-rose-600"></i>
                        Exporter PDF (jour)
                    </button>
                    <button type="submit" name="mode" value="mensuel" formaction="export_pointage_pdf.php" formtarget="_blank" class="inline-flex items-center rounded-2xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-900 transition hover:bg-slate-50">
                        <i class="fas fa-file-pdf mr-2 text-indigo-600"></i>
                        Exporter PDF (mois)
                    </button>
                    <button type="submit" formaction="export_pointage_excel.php" formtarget="_blank" class="inline-flex items-center rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-3 text-sm font-semibold text-emerald-800 transition hover:bg-emerald-100">
                        <i class="fas fa-file-excel mr-2 text-emerald-700"></i>
                        Exporter Excel (mois)
                    </button>
                    <?php if ($isAdminUser): ?>
                        <button type="button" id="mark-all-present" class="inline-flex items-center rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-3 text-sm font-semibold text-emerald-700 transition hover:bg-emerald-100">
                            <i class="fas fa-user-check mr-2"></i>
                            Tout le monde present
                        </button>
                        <button type="button" id="mark-all-absent" class="inline-flex items-center rounded-2xl border border-rose-200 bg-rose-50 px-5 py-3 text-sm font-semibold text-rose-700 transition hover:bg-rose-100">
                            <i class="fas fa-user-times mr-2"></i>
                            Tout le monde absent
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </form>

        <form method="post" enctype="multipart/form-data" class="mt-8 rounded-[30px] border border-white/80 bg-white/92 shadow-[0_18px_60px_rgba(15,23,42,0.07)] backdrop-blur">
            <input type="hidden" name="attendance_date" value="<?= htmlspecialchars($board['date']) ?>">
            <input type="hidden" name="import_badgeuse_export" id="import-badgeuse-export-flag" value="">
            <input type="file" name="badgeuse_export_file" id="badgeuse-export-file" accept=".xlsx,.xls,.csv" class="hidden">
            <div class="flex flex-col gap-3 border-b border-slate-100 px-6 py-5 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.28em] text-emerald-600">Feuille du jour</p>
                    <h2 class="mt-2 text-2xl font-semibold text-slate-950">Pointage du <?= htmlspecialchars(date('d/m/Y', strtotime($board['date']))) ?></h2>
                </div>
                <div class="flex flex-wrap gap-3">
                    <button type="submit" class="inline-flex items-center rounded-2xl bg-emerald-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-emerald-700">
                        <i class="fas fa-save mr-2"></i>
                        Enregistrer le pointage
                    </button>
                    <?php if ($isAdminUser): ?>
                        <button type="button" id="import-badgeuse-export" class="inline-flex items-center rounded-2xl border border-violet-200 bg-violet-50 px-5 py-3 text-sm font-semibold text-violet-800 transition hover:bg-violet-100">
                            <i class="fas fa-file-import mr-2"></i>
                            Import export badgeuse
                        </button>
                        <button type="submit" name="sync_biometric_month" value="1" class="inline-flex items-center rounded-2xl border border-sky-200 bg-sky-50 px-5 py-3 text-sm font-semibold text-sky-800 transition hover:bg-sky-100" onclick="return confirm('Recuperer les pointages biometriques du mois selectionne et remettre les presences detectees ?');">
                            <i class="fas fa-fingerprint mr-2"></i>
                            Récupérer biométrie du mois
                        </button>
                        <button type="submit" name="repair_calendar_statuses" value="1" class="inline-flex items-center rounded-2xl border border-amber-200 bg-amber-50 px-5 py-3 text-sm font-semibold text-amber-800 transition hover:bg-amber-100" onclick="return confirm('Corriger les statuts du mois selectionne selon les week-ends et jours feries parametres ? Les heures de pointage seront conservees.');">
                            <i class="fas fa-rotate mr-2"></i>
                            Corriger le mois
                        </button>
                        <button type="submit" name="bulk_apply" value="month_workdays" <?= $isWorkingDay ? '' : 'disabled' ?> class="inline-flex items-center rounded-2xl border px-5 py-3 text-sm font-semibold transition <?= $isWorkingDay ? 'border-slate-200 bg-white text-slate-900 hover:bg-slate-50' : 'cursor-not-allowed border-slate-200 bg-slate-100 text-slate-400' ?>" title="<?= $isWorkingDay ? 'Appliquer cette saisie aux jours ouvres du mois' : 'Indisponible sur un repos ou un jour ferie' ?>">
                            <i class="fas fa-calendar-check mr-2 text-indigo-600"></i>
                            Pointer tout le mois (jours ouvres)
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-[1420px] table-fixed text-left text-sm">
                    <colgroup>
                        <col class="w-[220px]">
                        <col class="w-[285px]">
                        <col class="w-[85px]">
                        <col class="w-[105px]">
                        <col class="w-[105px]">
                        <col class="w-[105px]">
                        <col class="w-[118px]">
                        <col class="w-[122px]">
                        <col class="w-[95px]">
                        <col class="w-[190px]">
                    </colgroup>
                    <thead class="bg-slate-50 text-slate-500">
                        <tr>
                            <th class="px-4 py-4 font-medium">Collaborateur</th>
                            <th class="px-4 py-4 font-medium">Statut</th>
                            <th class="px-4 py-4 font-medium">Jours</th>
                            <th class="px-4 py-4 font-medium">Entree</th>
                            <th class="px-4 py-4 font-medium">Sortie</th>
                            <th class="px-4 py-4 font-medium">Heures jour</th>
                            <th class="px-4 py-4 font-medium">Retard</th>
                            <th class="px-4 py-4 font-medium">HS / Deficit</th>
                            <th class="px-4 py-4 font-medium">HS retenues</th>
                            <th class="px-4 py-4 font-medium">Source</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($board['items'] as $item): ?>
                            <?php
                            $employee = $item['employee'];
                            $attendance = $item['attendance'];
                            $currentStatus = (string) $attendance['attendance_status'];
                            $sourceMeta = rhAttendanceSourceMeta($attendance);
                            $displayNote = rhAttendanceDisplayNote($attendance);
                            $apiTimes = $badgeuseTimeMap[(int) $employee['id']] ?? [];
                            $entrySourceMeta = rhAttendanceTimeSourceMeta($attendance, 'check_in_time', $apiTimes);
                            $exitSourceMeta = rhAttendanceTimeSourceMeta($attendance, 'check_out_time', $apiTimes);
                            ?>
                            <tr class="border-t border-slate-100 align-top">
                                <td class="px-4 py-4">
                                    <div class="font-semibold text-slate-900"><?= htmlspecialchars((string) $employee['full_name']) ?></div>
                                    <div class="mt-1 text-xs text-slate-500">
                                        <?= htmlspecialchars((string) ($employee['matricule'] ?: 'Sans matricule')) ?>
                                        ·
                                        <?= htmlspecialchars((string) $employee['fonction_label']) ?>
                                    </div>
                                </td>
                                <td class="px-4 py-4">
                                    <div class="attendance-selector relative" data-personnel-id="<?= (int) $employee['id'] ?>">
                                        <input type="hidden" name="status[<?= (int) $employee['id'] ?>]" value="<?= htmlspecialchars($currentStatus) ?>">
                                        <?php if (!$isWorkingDay): ?>
                                            <span class="status-active-badge inline-flex min-w-[118px] items-center justify-between gap-2 rounded-full border px-3 py-1.5 text-xs font-semibold leading-none <?= attendanceStatusBadgeClass($currentStatus) ?>" data-value="<?= htmlspecialchars($currentStatus) ?>">
                                                <span><?= htmlspecialchars($statuses[$currentStatus]['label'] ?? $currentStatus) ?></span>
                                                <i class="fas fa-lock text-[10px] opacity-70"></i>
                                            </span>
                                        <?php else: ?>
                                            <button type="button" class="status-active-badge inline-flex min-w-[104px] items-center justify-between gap-2 rounded-full border px-3 py-1.5 text-xs font-semibold leading-none transition <?= attendanceStatusBadgeClass($currentStatus) ?>" data-value="<?= htmlspecialchars($currentStatus) ?>">
                                                <span><?= htmlspecialchars($statuses[$currentStatus]['label'] ?? $currentStatus) ?></span>
                                                <i class="fas fa-chevron-down text-[10px] opacity-70"></i>
                                            </button>
                                            <div class="status-menu absolute left-0 z-30 mt-2 hidden w-44 rounded-2xl border border-slate-200 bg-white p-2 shadow-xl shadow-slate-900/10">
                                                <?php foreach ($selectableStatuses as $statusKey => $statusMeta): ?>
                                                    <button type="button" class="status-chip mb-1 block w-full rounded-xl border px-3 py-2 text-left text-xs font-semibold transition <?= $currentStatus === $statusKey ? attendanceStatusBadgeClass($statusKey) : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300 hover:bg-slate-50' ?>" data-value="<?= htmlspecialchars($statusKey) ?>" data-default-days="<?= htmlspecialchars((string) $statusMeta['default_days']) ?>" data-label="<?= htmlspecialchars((string) $statusMeta['label']) ?>">
                                                        <?= htmlspecialchars($statusMeta['label']) ?>
                                                    </button>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="px-4 py-4">
                                    <input type="number" step="0.25" min="0" max="1" name="worked_days[<?= (int) $employee['id'] ?>]" value="<?= htmlspecialchars((string) $attendance['worked_days']) ?>" class="w-24 rounded-2xl border border-slate-200 bg-slate-50 px-3 py-3 text-sm outline-none transition focus:border-emerald-400 focus:bg-white">
                                </td>
                                <td class="px-4 py-4">
                                    <input type="time" name="check_in_time[<?= (int) $employee['id'] ?>]" value="<?= htmlspecialchars((string) substr((string) $attendance['check_in_time'], 0, 5)) ?>" class="w-28 rounded-2xl border border-slate-200 bg-slate-50 px-3 py-3 text-sm outline-none transition focus:border-emerald-400 focus:bg-white">
                                </td>
                                <td class="px-4 py-4">
                                    <input type="time" name="check_out_time[<?= (int) $employee['id'] ?>]" value="<?= htmlspecialchars((string) substr((string) $attendance['check_out_time'], 0, 5)) ?>" class="w-28 rounded-2xl border border-slate-200 bg-slate-50 px-3 py-3 text-sm outline-none transition focus:border-emerald-400 focus:bg-white">
                                </td>
                                <td class="px-4 py-4">
                                    <div class="attendance-worked-hours min-w-[92px] rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-700">-</div>
                                </td>
                                <td class="px-4 py-4">
                                    <div class="attendance-late-badge min-w-[104px] rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-600">-</div>
                                </td>
                                <td class="px-4 py-4">
                                    <div class="attendance-balance-badge min-w-[112px] rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-600">-</div>
                                </td>
                                <td class="px-4 py-4">
                                    <input type="number" step="0.25" min="0" name="overtime_hours[<?= (int) $employee['id'] ?>]" value="<?= htmlspecialchars((string) $attendance['overtime_hours']) ?>" class="w-24 rounded-2xl border border-slate-200 bg-slate-50 px-3 py-3 text-sm outline-none transition focus:border-emerald-400 focus:bg-white">
                                </td>
                                <td class="px-4 py-4">
                                    <div class="attendance-source min-w-[160px]" data-attendance-source="<?= (int) $employee['id'] ?>">
                                        <span class="inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-semibold <?= htmlspecialchars($sourceMeta['class']) ?>" title="<?= htmlspecialchars($sourceMeta['detail']) ?>">
                                            <span class="h-2 w-2 rounded-full <?= htmlspecialchars($sourceMeta['dot']) ?>"></span>
                                            <i class="fas <?= htmlspecialchars($sourceMeta['icon']) ?>"></i>
                                            <?= htmlspecialchars($sourceMeta['label']) ?>
                                        </span>
                                        <div class="mt-1 text-[11px] leading-snug text-slate-500"><?= htmlspecialchars($sourceMeta['detail']) ?></div>
                                        <div class="mt-2 flex flex-wrap gap-1.5 text-[10px] font-semibold" data-time-source="<?= (int) $employee['id'] ?>">
                                            <span data-time-source-entry class="inline-flex items-center gap-1 rounded-full border px-2 py-1 <?= htmlspecialchars($entrySourceMeta['class']) ?>" title="<?= htmlspecialchars($entrySourceMeta['detail']) ?>">
                                                <i class="fas <?= htmlspecialchars($entrySourceMeta['icon']) ?>"></i>
                                                E: <?= htmlspecialchars($entrySourceMeta['label']) ?>
                                            </span>
                                            <span data-time-source-exit class="inline-flex items-center gap-1 rounded-full border px-2 py-1 <?= htmlspecialchars($exitSourceMeta['class']) ?>" title="<?= htmlspecialchars($exitSourceMeta['detail']) ?>">
                                                <i class="fas <?= htmlspecialchars($exitSourceMeta['icon']) ?>"></i>
                                                S: <?= htmlspecialchars($exitSourceMeta['label']) ?>
                                            </span>
                                        </div>
                                    </div>
                                    <input type="hidden" name="notes[<?= (int) $employee['id'] ?>]" value="<?= htmlspecialchars($displayNote) ?>">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="flex justify-end border-t border-slate-100 px-6 py-5">
                <button type="submit" class="inline-flex items-center rounded-2xl bg-slate-950 px-5 py-3 text-sm font-semibold text-white transition hover:bg-slate-800">
                    <i class="fas fa-floppy-disk mr-2"></i>
                    Sauvegarder la journee
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    var isAttendanceAdmin = <?= $isAdminUser ? 'true' : 'false' ?>;
    var badgeClasses = {
        present: 'bg-emerald-100 text-emerald-700 border-emerald-200',
        half_day: 'bg-amber-100 text-amber-700 border-amber-200',
        mission: 'bg-sky-100 text-sky-700 border-sky-200',
        conge: 'bg-indigo-100 text-indigo-700 border-indigo-200',
        repos: 'bg-slate-100 text-slate-700 border-slate-200',
        absence: 'bg-rose-100 text-rose-700 border-rose-200',
        maladie: 'bg-orange-100 text-orange-700 border-orange-200'
    };
    var dailyWorkTargetHours = <?= json_encode($dailyWorkTargetHours, JSON_UNESCAPED_SLASHES) ?>;
    var lateReferenceTime = <?= json_encode($lateReferenceTime, JSON_UNESCAPED_SLASHES) ?>;
    var arrivalToleranceMinutes = <?= json_encode($arrivalToleranceMinutes, JSON_UNESCAPED_SLASHES) ?>;
    var departureToleranceMinutes = <?= json_encode($departureToleranceMinutes, JSON_UNESCAPED_SLASHES) ?>;
    var overtimeToleranceMinutes = <?= json_encode($overtimeToleranceMinutes, JSON_UNESCAPED_SLASHES) ?>;
    var selectedDateIsWorkingDay = <?= json_encode($isWorkingDay, JSON_UNESCAPED_SLASHES) ?>;

    function setStatus(container, value, defaultDays) {
        var hiddenInput = container.querySelector('input[type="hidden"]');
        if (!hiddenInput) {
            return;
        }

        var newStatus = String(value || 'present');
        hiddenInput.value = newStatus;

        container.querySelectorAll('.status-chip').forEach(function(chip) {
            chip.className = 'status-chip mb-1 block w-full rounded-xl border px-3 py-2 text-left text-xs font-semibold transition border-slate-200 bg-white text-slate-600 hover:border-slate-300 hover:bg-slate-50';
        });

        var selectedLabel = newStatus;
        container.querySelectorAll('.status-chip').forEach(function(chip) {
            if (chip.getAttribute('data-value') === newStatus) {
                selectedLabel = chip.getAttribute('data-label') || chip.textContent.trim() || newStatus;
                chip.className = 'status-chip mb-1 block w-full rounded-xl border px-3 py-2 text-left text-xs font-semibold transition ' + (badgeClasses[newStatus] || 'border-slate-200 bg-white text-slate-600');
            }
        });

        var activeBadge = container.querySelector('.status-active-badge');
        if (activeBadge) {
            activeBadge.setAttribute('data-value', newStatus);
            activeBadge.className = 'status-active-badge inline-flex min-w-[104px] items-center justify-between gap-2 rounded-full border px-3 py-1.5 text-xs font-semibold leading-none transition ' + (badgeClasses[newStatus] || 'border-slate-200 bg-white text-slate-600');
            activeBadge.innerHTML = '<span>' + selectedLabel + '</span><i class="fas fa-chevron-down text-[10px] opacity-70"></i>';
        }

        var menu = container.querySelector('.status-menu');
        if (menu) {
            menu.classList.add('hidden');
        }

        var row = container.closest('tr');
        if (!row) {
            return;
        }

        var workedDaysInput = row.querySelector('input[name^="worked_days["]');
        if (workedDaysInput) {
            workedDaysInput.value = defaultDays;
        }
        recalculateAttendanceRow(row);
    }

    document.querySelectorAll('.attendance-selector').forEach(function(container) {
        var activeBadge = container.querySelector('.status-active-badge');
        var menu = container.querySelector('.status-menu');
        if (activeBadge && menu) {
            activeBadge.addEventListener('click', function(event) {
                event.stopPropagation();
                document.querySelectorAll('.status-menu').forEach(function(otherMenu) {
                    if (otherMenu !== menu) {
                        otherMenu.classList.add('hidden');
                    }
                });
                menu.classList.toggle('hidden');
            });
        }

        container.querySelectorAll('.status-chip').forEach(function(button) {
            button.addEventListener('click', function() {
                setStatus(container, button.getAttribute('data-value'), button.getAttribute('data-default-days'));
            });
        });
    });

    document.addEventListener('click', function() {
        document.querySelectorAll('.status-menu').forEach(function(menu) {
            menu.classList.add('hidden');
        });
    });

    document.getElementById('mark-all-present')?.addEventListener('click', function() {
        document.querySelectorAll('.attendance-selector').forEach(function(container) {
            var presentButton = container.querySelector('.status-chip[data-value="present"]');
            if (presentButton) {
                setStatus(container, 'present', presentButton.getAttribute('data-default-days'));
            }
        });
    });

    document.getElementById('mark-all-absent')?.addEventListener('click', function() {
        document.querySelectorAll('.attendance-selector').forEach(function(container) {
            var absentButton = container.querySelector('.status-chip[data-value="absence"]');
            if (absentButton) {
                setStatus(container, 'absence', absentButton.getAttribute('data-default-days'));

                var row = container.closest('tr');
                if (row) {
                    var overtimeInput = row.querySelector('input[name^="overtime_hours["]');
                    if (overtimeInput) {
                        overtimeInput.value = '0';
                    }
                    var checkInInput = row.querySelector('input[name^="check_in_time["]');
                    if (checkInInput) {
                        checkInInput.value = '';
                    }
                    var checkOutInput = row.querySelector('input[name^="check_out_time["]');
                    if (checkOutInput) {
                        checkOutInput.value = '';
                    }
                    recalculateAttendanceRow(row);
                }
            }
        });
    });

    document.querySelectorAll('input[name^="check_in_time["], input[name^="check_out_time["]').forEach(function(input) {
        input.addEventListener('input', function() {
            recalculateAttendanceRow(input.closest('tr'));
            markEditedTimeSource(input);
        });
        input.addEventListener('change', function() {
            recalculateAttendanceRow(input.closest('tr'));
            markEditedTimeSource(input);
        });
    });

    function normalizeTimeValue(v) {
        v = String(v || '').trim();
        if (!v) {
            return '';
        }
        // attend "HH:MM" (input type=time) ; si "HH:MM:SS" -> tronque.
        if (v.length >= 5) {
            return v.substring(0, 5);
        }
        return v;
    }

    function timeToMinutes(value) {
        value = normalizeTimeValue(value);
        if (!/^\d{2}:\d{2}$/.test(value)) {
            return null;
        }
        var parts = value.split(':');
        var hours = Number(parts[0]);
        var minutes = Number(parts[1]);
        if (!Number.isFinite(hours) || !Number.isFinite(minutes)) {
            return null;
        }
        return (hours * 60) + minutes;
    }

    function formatDurationFromMinutes(minutes) {
        minutes = Math.round(Number(minutes) || 0);
        var sign = minutes < 0 ? '-' : '';
        minutes = Math.abs(minutes);
        var h = Math.floor(minutes / 60);
        var m = minutes % 60;
        if (m === 0) {
            return sign + h + 'h';
        }
        return sign + h + 'h' + String(m).padStart(2, '0');
    }

    function setMetricBox(el, text, className) {
        if (!el) {
            return;
        }
        el.textContent = text;
        el.className = className;
    }

    function recalculateAttendanceRow(row) {
        if (!row) {
            return;
        }

        var checkInInput = row.querySelector('input[name^="check_in_time["]');
        var checkOutInput = row.querySelector('input[name^="check_out_time["]');
        var overtimeInput = row.querySelector('input[name^="overtime_hours["]');
        var workedBox = row.querySelector('.attendance-worked-hours');
        var lateBox = row.querySelector('.attendance-late-badge');
        var balanceBox = row.querySelector('.attendance-balance-badge');
        var inMinutes = timeToMinutes(checkInInput ? checkInInput.value : '');
        var outMinutes = timeToMinutes(checkOutInput ? checkOutInput.value : '');
        var workedClass = 'min-w-[92px] rounded-xl border px-3 py-2 text-xs font-semibold ';
        var lateClass = 'min-w-[104px] rounded-xl border px-3 py-2 text-xs font-semibold ';
        var balanceClass = 'min-w-[112px] rounded-xl border px-3 py-2 text-xs font-semibold ';

        var referenceMinutes = timeToMinutes(lateReferenceTime);
        if (referenceMinutes === null) {
            referenceMinutes = 480;
        }

        if (!selectedDateIsWorkingDay) {
            setMetricBox(lateBox, 'Repos', lateClass + 'border-slate-200 bg-slate-50 text-slate-600');
            if (inMinutes !== null && outMinutes !== null) {
                if (outMinutes < inMinutes) {
                    outMinutes += 1440;
                }
                var restWorkedMinutes = Math.max(0, outMinutes - inMinutes);
                var restOvertimeHours = Math.round((restWorkedMinutes / 60) * 4) / 4;
                setMetricBox(workedBox, formatDurationFromMinutes(restWorkedMinutes), workedClass + 'border-sky-200 bg-sky-50 text-sky-700');
                setMetricBox(balanceBox, 'HS repos +' + formatDurationFromMinutes(restWorkedMinutes), balanceClass + 'border-emerald-200 bg-emerald-50 text-emerald-700');
                if (overtimeInput) {
                    overtimeInput.value = restOvertimeHours.toFixed(2).replace(/\.00$/, '');
                }
            } else {
                setMetricBox(workedBox, '-', workedClass + 'border-slate-200 bg-slate-50 text-slate-600');
                setMetricBox(balanceBox, '-', balanceClass + 'border-slate-200 bg-slate-50 text-slate-600');
                if (overtimeInput) {
                    overtimeInput.value = '0';
                }
            }
            return;
        }

        if (inMinutes === null || outMinutes === null || outMinutes <= inMinutes) {
            setMetricBox(workedBox, '-', workedClass + 'border-slate-200 bg-slate-50 text-slate-600');
            setMetricBox(balanceBox, '-', balanceClass + 'border-slate-200 bg-slate-50 text-slate-600');
            if (inMinutes !== null && inMinutes > (referenceMinutes + Number(arrivalToleranceMinutes || 0))) {
                setMetricBox(lateBox, 'Retard ' + formatDurationFromMinutes(inMinutes - referenceMinutes), lateClass + 'border-rose-200 bg-rose-50 text-rose-700');
            } else if (inMinutes !== null) {
                setMetricBox(lateBox, "A l'heure", lateClass + 'border-emerald-200 bg-emerald-50 text-emerald-700');
            } else {
                setMetricBox(lateBox, '-', lateClass + 'border-slate-200 bg-slate-50 text-slate-600');
            }
            if (overtimeInput && (!overtimeInput.value || Number(overtimeInput.value) === 0)) {
                overtimeInput.value = '0';
            }
            return;
        }

        var workedMinutes = outMinutes - inMinutes;
        var targetMinutes = Math.round(Number(dailyWorkTargetHours || 8) * 60);
        var rawBalanceMinutes = workedMinutes - targetMinutes;
        var balanceMinutes = Math.abs(rawBalanceMinutes) <= Number(departureToleranceMinutes || 0) ? 0 : rawBalanceMinutes;
        var lateMinutes = inMinutes > (referenceMinutes + Number(arrivalToleranceMinutes || 0)) ? Math.max(0, inMinutes - referenceMinutes) : 0;
        var overtimeMinutes = rawBalanceMinutes > Number(overtimeToleranceMinutes || 0) ? rawBalanceMinutes : 0;
        var overtimeHours = Math.max(0, overtimeMinutes / 60);

        setMetricBox(workedBox, formatDurationFromMinutes(workedMinutes), workedClass + 'border-sky-200 bg-sky-50 text-sky-700');

        if (lateMinutes > 0) {
            setMetricBox(lateBox, 'Retard ' + formatDurationFromMinutes(lateMinutes), lateClass + 'border-rose-200 bg-rose-50 text-rose-700');
        } else {
            setMetricBox(lateBox, "A l'heure", lateClass + 'border-emerald-200 bg-emerald-50 text-emerald-700');
        }

        if (balanceMinutes > 0) {
            setMetricBox(balanceBox, '+' + formatDurationFromMinutes(balanceMinutes), balanceClass + 'border-emerald-200 bg-emerald-50 text-emerald-700');
        } else if (balanceMinutes < 0) {
            setMetricBox(balanceBox, formatDurationFromMinutes(balanceMinutes), balanceClass + 'border-amber-200 bg-amber-50 text-amber-700');
        } else {
            setMetricBox(balanceBox, Number(dailyWorkTargetHours || 8).toString().replace('.', ',') + 'h OK', balanceClass + 'border-slate-200 bg-slate-50 text-slate-700');
        }

        if (overtimeInput) {
            overtimeInput.value = (Math.round(overtimeHours * 4) / 4).toFixed(2).replace(/\.00$/, '');
        }
    }

    function recalculateAllAttendanceRows() {
        document.querySelectorAll('tbody tr').forEach(recalculateAttendanceRow);
    }

    function isTimeLess(a, b) {
        // compare "HH:MM" lexicographiquement (OK sur ce format).
        return String(a) < String(b);
    }

    function isTimeGreater(a, b) {
        return String(a) > String(b);
    }

    var attendanceSyncInFlight = false;

    function setLiveStatus(text, tone) {
        var status = document.getElementById('attendance-live-status');
        if (!status) {
            return;
        }

        var color = tone === 'error' ? 'text-rose-500' : (tone === 'loading' ? 'text-amber-500' : 'text-emerald-500');
        status.innerHTML = '<i class="fas fa-circle mr-2 ' + color + '"></i>' + text;
    }

    function setAttendanceSourceBadge(row, sourceLabel) {
        if (!row) {
            return;
        }

        var cell = row.querySelector('[data-attendance-source]');
        if (!cell || sourceLabel !== 'Badgeuse') {
            return;
        }

        var detail = 'Heures importees depuis attendance.fidest.ci';
        cell.innerHTML =
            '<span class="inline-flex items-center gap-2 rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-700" title="' + detail + '">' +
            '<span class="h-2 w-2 rounded-full bg-emerald-500"></span>' +
            '<i class="fas fa-fingerprint"></i>Badgeuse</span>' +
            '<div class="mt-1 text-[11px] leading-snug text-slate-500">' + detail + '</div>' +
            '<div class="mt-2 flex flex-wrap gap-1.5 text-[10px] font-semibold" data-time-source></div>';

        var notesInput = row.querySelector('input[name^="notes["]');
        if (notesInput && /^auto\s*:\s*attendance-api$/i.test(String(notesInput.value || '').trim())) {
            notesInput.value = '';
        }
    }

    function setTimeSourceBadges(row, entrySource, exitSource) {
        if (!row) {
            return;
        }
        var cell = row.querySelector('[data-time-source]');
        if (!cell) {
            return;
        }
        var badgeuseClass = 'border-emerald-200 bg-emerald-50 text-emerald-700';
        var manualClass = 'border-indigo-200 bg-indigo-50 text-indigo-700';
        var emptyClass = 'border-slate-200 bg-slate-50 text-slate-500';

        function badge(label, prefix) {
            var cls = label === 'Badgeuse' ? badgeuseClass : (label === 'Manuel' ? manualClass : emptyClass);
            var icon = label === 'Badgeuse' ? 'fa-fingerprint' : (label === 'Manuel' ? 'fa-pen-to-square' : 'fa-circle-minus');
            var attr = prefix === 'Sortie' ? 'data-time-source-exit' : 'data-time-source-entry';
            var shortPrefix = prefix === 'Sortie' ? 'S' : 'E';
            return '<span ' + attr + ' class="inline-flex items-center gap-1 rounded-full border px-2 py-1 ' + cls + '">' +
                '<i class="fas ' + icon + '"></i>' + shortPrefix + ': ' + label + '</span>';
        }
        cell.innerHTML = badge(entrySource || '-', 'Entree') + badge(exitSource || '-', 'Sortie');
    }

    function markEditedTimeSource(input) {
        var row = input ? input.closest('tr') : null;
        if (!row) {
            return;
        }
        var isExit = input.name && input.name.indexOf('check_out_time') === 0;
        var target = isExit ? row.querySelector('[data-time-source-exit]') : row.querySelector('[data-time-source-entry]');
        if (!target) {
            return;
        }
        target.className = 'inline-flex items-center gap-1 rounded-full border px-2 py-1 border-indigo-200 bg-indigo-50 text-indigo-700';
        target.innerHTML = '<i class="fas fa-pen-to-square"></i>' + (isExit ? 'S' : 'E') + ': Manuel';
        target.title = 'Heure saisie ou corrigee dans FINEA';
    }

    async function refreshBadgeuseHealth() {
        try {
            var res = await fetch('pointage_journalier_rh.php?ajax=1&action=device_status&_ts=' + Date.now(), {
                cache: 'no-store'
            });
            var payload = await res.json();
            var status = payload && payload.device_status ? payload.device_status : {};
            var title = document.getElementById('badgeuse-health-title');
            var detail = document.getElementById('badgeuse-health-detail');
            var lastSeen = document.getElementById('badgeuse-last-seen');
            var deviceTime = document.getElementById('badgeuse-device-time');
            var seenTime = document.getElementById('badgeuse-seen-time');
            var delay = document.getElementById('badgeuse-delay');

            if (title) {
                if (!status.ok) {
                    title.textContent = 'API badgeuse indisponible';
                    setLiveStatus('API badgeuse indisponible', 'error');
                } else if (String(status.status || '').toLowerCase().includes('offline') || String(status.status || '').toLowerCase().includes('never_seen')) {
                    title.textContent = 'Badgeuse hors ligne';
                    setLiveStatus('Badgeuse hors ligne', 'error');
                } else {
                    title.textContent = 'Badgeuse en ligne';
                    setLiveStatus('Badgeuse en ligne', 'ok');
                }
            }
            if (detail) {
                detail.textContent = status.ok ? (status.label || 'Badgeuse') : (status.error || 'API indisponible');
            }
            if (lastSeen) lastSeen.textContent = status.last_seen_at || '-';
            if (deviceTime) deviceTime.textContent = status.last_device_time_at || '-';
            if (seenTime) seenTime.textContent = status.last_device_time_seen_at || '-';
            if (delay) delay.textContent = Number.isFinite(Number(status.collection_delay_seconds)) ? String(status.collection_delay_seconds) + ' s' : '-';
        } catch (e) {
            var title = document.getElementById('badgeuse-health-title');
            if (title) {
                title.textContent = 'API badgeuse indisponible';
            }
            setLiveStatus('API badgeuse indisponible', 'error');
        }
    }

    async function syncAttendanceFromApi(showAlert) {
        if (attendanceSyncInFlight) {
            return;
        }

        attendanceSyncInFlight = true;
        var btn = document.getElementById('refresh-from-badgeuse');
        if (btn) {
            btn.disabled = true;
            btn.classList.add('opacity-70');
        }
        setLiveStatus('Synchronisation...', 'loading');

        try {
            var dateValue = document.querySelector('input[name="date"]')?.value || '';
            var url = 'pointage_journalier_rh.php?ajax=1&action=times&date=' + encodeURIComponent(dateValue) + '&_ts=' + Date.now();
            var res = await fetch(url, {
                cache: 'no-store'
            });
            var payload = await res.json();
            if (!payload || !payload.ok || !payload.times) {
                var msg = 'Synchronisation impossible: ' + (payload && payload.error ? payload.error : 'Erreur');
                setLiveStatus('API indisponible', 'error');
                if (showAlert) {
                    alert(msg);
                }
                return;
            }

            var changed = 0;
            Object.keys(payload.times).forEach(function(pid) {
                var rowTimes = payload.times[pid] || {};
                var newIn = normalizeTimeValue(rowTimes.check_in_time);
                var newOut = normalizeTimeValue(rowTimes.check_out_time);

                var inInput = document.querySelector('input[name="check_in_time[' + pid + ']"]');
                var outInput = document.querySelector('input[name="check_out_time[' + pid + ']"]');
                var row = inInput ? inInput.closest('tr') : (outInput ? outInput.closest('tr') : null);

                if (inInput && newIn) {
                    var curIn = normalizeTimeValue(inInput.value);
                    if (!curIn || isTimeLess(newIn, curIn)) {
                        inInput.value = newIn;
                        changed++;
                        recalculateAttendanceRow(row);
                    }
                }

                if (outInput && newOut) {
                    var curOut = normalizeTimeValue(outInput.value);
                    if (!curOut || isTimeGreater(newOut, curOut)) {
                        outInput.value = newOut;
                        changed++;
                        recalculateAttendanceRow(row);
                    }
                }

                if (row && rowTimes.source_label === 'Badgeuse') {
                    setAttendanceSourceBadge(row, 'Badgeuse');
                }
                if (row) {
                    setTimeSourceBadges(row, rowTimes.entry_source_label || '-', rowTimes.exit_source_label || '-');
                }
            });

            var sync = payload.sync || {};
            var label = 'Temps reel actif';
            if (sync && sync.ok === false) {
                label = 'API indisponible';
                setLiveStatus(label, 'error');
                if (showAlert && sync.error) {
                    alert('Synchronisation impossible: ' + sync.error);
                }
                return;
            }

            if (payload.server_time) {
                label = 'Synchro ' + String(payload.server_time).substring(11, 19);
            }
            var fetchedCount = Number(sync.fetched_count || 0);
            var dayPunchCount = Number(sync.day_punch_count || 0);
            var linkedCount = Number(sync.linked_daily_updated || 0);
            var aggregatedCount = Number(sync.aggregated_employee_count || 0);
            var staleDeleted = Number(sync.stale_auto_deleted || 0);

            if (fetchedCount === 0) {
                label += ' - aucun log API';
            } else if (dayPunchCount === 0) {
                if (sync.source_max_date) {
                    label += ' - 0 pointage ce jour (API jusqu au ' + sync.source_max_date + ')';
                } else {
                    label += ' - 0 pointage ce jour';
                }
            } else if (linkedCount === 0) {
                label += ' - ' + dayPunchCount + ' pointage(s), aucun matricule lie';
            } else {
                label += ' - ' + linkedCount + '/' + aggregatedCount + ' personne(s)';
            }
            if (changed > 0) {
                label += ' - ' + changed + ' champ(s)';
            }
            if (staleDeleted > 0) {
                label += ' - ' + staleDeleted + ' ligne(s) badgeuse corrigee(s)';
            }
            if (showAlert && dayPunchCount > 0 && linkedCount === 0 && Array.isArray(sync.unmatched_codes) && sync.unmatched_codes.length > 0) {
                alert('Pointages recus, mais aucun matricule RH correspondant. Codes ZKTeco: ' + sync.unmatched_codes.join(', '));
            }
            setLiveStatus(label, 'ok');
        } catch (e) {
            setLiveStatus('API indisponible', 'error');
            if (showAlert) {
                alert('Synchronisation impossible.');
            }
        } finally {
            attendanceSyncInFlight = false;
            if (btn) {
                btn.disabled = false;
                btn.classList.remove('opacity-70');
            }
        }
    }

    document.getElementById('refresh-from-badgeuse')?.addEventListener('click', function() {
        syncAttendanceFromApi(true);
    });

    document.getElementById('import-badgeuse-export')?.addEventListener('click', function() {
        document.getElementById('badgeuse-export-file')?.click();
    });

    document.getElementById('badgeuse-export-file')?.addEventListener('change', function() {
        if (!this.files || !this.files.length) {
            return;
        }
        var flag = document.getElementById('import-badgeuse-export-flag');
        if (flag) {
            flag.value = '1';
        }
        this.form?.submit();
    });

    recalculateAllAttendanceRows();

    if (window.attendanceLiveSyncTimer) {
        window.clearInterval(window.attendanceLiveSyncTimer);
    }

    if (isAttendanceAdmin) {
        syncAttendanceFromApi(false);
    } else {
        refreshBadgeuseHealth();
    }
    window.attendanceLiveSyncTimer = window.setInterval(function() {
        if (document.visibilityState === 'hidden') {
            return;
        }
        if (isAttendanceAdmin) {
            syncAttendanceFromApi(false);
        } else {
            refreshBadgeuseHealth();
        }
    }, 15000);
</script>

<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>
