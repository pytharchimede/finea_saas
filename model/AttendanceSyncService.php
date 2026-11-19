<?php

declare(strict_types=1);

require_once __DIR__ . '/AttendanceApiClient.php';

final class AttendanceSyncService
{
    private PDO $pdo;
    private AttendanceApiClient $client;

    public function __construct(PDO $pdo, ?AttendanceApiClient $client = null)
    {
        $this->pdo = $pdo;
        $this->client = $client ?? new AttendanceApiClient();
        $this->ensureTables();
    }

    public function getBaseUrl(): string
    {
        return $this->client->getBaseUrl();
    }

    public function health(): array
    {
        return $this->client->health();
    }

    public function syncToday(?array $actor = null, int $limit = 500): array
    {
        return $this->syncDate(date('Y-m-d'), $actor, $limit);
    }

    public function syncDate(string $date, ?array $actor = null, int $limit = 500): array
    {
        $limit = max(1, min(200, $limit));
        $date = $this->normalizeDate($date) ?? date('Y-m-d');

        $runId = $this->createRun('day', ['date' => $date, 'limit' => $limit], $actor);
        $fetchedCount = 0;
        $insertedCount = 0;
        $linkedUpdated = 0;
        $usableCount = 0;
        $dayPunchCount = 0;
        $aggregatedCount = 0;
        $unmatchedCodes = [];
        $sourceMinDate = null;
        $sourceMaxDate = null;
        $staleAutoDeleted = 0;

        try {
            $apiError = null;
            $payload = ['records' => []];
            try {
                $payload = $this->client->records([
                    'date' => $date,
                    'from' => $date . ' 00:00:00',
                    'to' => $date . ' 23:59:59',
                    'start_date' => $date,
                    'end_date' => $date,
                    'limit' => $limit,
                ]);
            } catch (Throwable $apiThrowable) {
                $apiError = $apiThrowable->getMessage();
            }
            $records = $payload['records'] ?? null;
            if (!is_array($records)) {
                throw new RuntimeException('Réponse API attendance invalide: "records" manquant.');
            }

            $fetchedCount = count($records);

            $insertStmt = $this->buildInsertEventStmt();

            $codes = [];
            $aggregates = [];

            foreach ($records as $record) {
                if (!is_array($record)) {
                    continue;
                }

                $prepared = $this->prepareRecordForStorage($record);
                if ($prepared === null) {
                    continue;
                }
                $usableCount++;

                $occurredAt = (string) $prepared['occurred_at'];
                $occurredOn = (string) $prepared['occurred_on'];
                $employeeCode = (string) $prepared['employee_code'];
                $normalizedOccurredOn = $this->normalizeDate($occurredOn);
                if ($normalizedOccurredOn !== null) {
                    $sourceMinDate = $sourceMinDate === null || $normalizedOccurredOn < $sourceMinDate ? $normalizedOccurredOn : $sourceMinDate;
                    $sourceMaxDate = $sourceMaxDate === null || $normalizedOccurredOn > $sourceMaxDate ? $normalizedOccurredOn : $sourceMaxDate;
                }

                $employeeName = $this->truncateNullableString($record['employee_name'] ?? null, 150);
                $insertStmt->execute($prepared['params']);

                if ($insertStmt->rowCount() === 1) {
                    $insertedCount++;
                }

                $codes[$employeeCode] = true;

                $t = $this->extractTime($occurredAt);
                if ($t !== null && $occurredOn === $date) {
                    $dayPunchCount++;
                    if (!isset($aggregates[$employeeCode])) {
                        $aggregates[$employeeCode] = ['in' => $t, 'out' => $t, 'employee_name' => $employeeName ?? '', 'api_personnel_id' => (int) ($record['personnel_id'] ?? 0)];
                    } else {
                        if ($t < $aggregates[$employeeCode]['in']) {
                            $aggregates[$employeeCode]['in'] = $t;
                        }
                        if ($t > $aggregates[$employeeCode]['out']) {
                            $aggregates[$employeeCode]['out'] = $t;
                        }
                        if ((string) ($aggregates[$employeeCode]['employee_name'] ?? '') === '' && $employeeName !== null) {
                            $aggregates[$employeeCode]['employee_name'] = $employeeName;
                        }
                        if ((int) ($aggregates[$employeeCode]['api_personnel_id'] ?? 0) <= 0) {
                            $aggregates[$employeeCode]['api_personnel_id'] = (int) ($record['personnel_id'] ?? 0);
                        }
                    }
                }
            }

            foreach ($this->loadStoredEventAggregates($date, $date) as $aggregate) {
                $employeeCode = (string) ($aggregate['employee_code'] ?? '');
                if ($employeeCode === '') {
                    continue;
                }
                $codes[$employeeCode] = true;
                if (!isset($aggregates[$employeeCode])) {
                    $aggregates[$employeeCode] = [
                        'in' => (string) ($aggregate['in'] ?? ''),
                        'out' => (string) ($aggregate['out'] ?? ''),
                        'employee_name' => (string) ($aggregate['employee_name'] ?? ''),
                        'api_personnel_id' => (int) ($aggregate['api_personnel_id'] ?? 0),
                    ];
                    continue;
                }
                if ((string) ($aggregate['in'] ?? '') < (string) ($aggregates[$employeeCode]['in'] ?? '')) {
                    $aggregates[$employeeCode]['in'] = (string) $aggregate['in'];
                }
                if ((string) ($aggregate['out'] ?? '') > (string) ($aggregates[$employeeCode]['out'] ?? '')) {
                    $aggregates[$employeeCode]['out'] = (string) $aggregate['out'];
                }
                if ((string) ($aggregates[$employeeCode]['employee_name'] ?? '') === '') {
                    $aggregates[$employeeCode]['employee_name'] = (string) ($aggregate['employee_name'] ?? '');
                }
            }

            $aggregatedCount = count($aggregates);

            $validAutoKeys = [];
            if (!empty($aggregates)) {
                $linkMap = $this->getPersonnelIdsByMatricules(array_keys($codes));
                $dailyStmt = $this->buildUpsertDailyAttendanceStmt();

                foreach ($aggregates as $employeeCode => $times) {
                    $personnelId = $this->resolvePersonnelIdByCode((string) $employeeCode, $linkMap);
                    if ($personnelId <= 0) {
                        $unmatchedCodes[] = $employeeCode;
                        continue;
                    }

                    $validAutoKeys[$date . '|' . $personnelId] = true;
                    $ok = $dailyStmt->execute([
                        ':personnel_id' => $personnelId,
                        ':attendance_date' => $date,
                        ':check_in_time' => $times['in'],
                        ':check_out_time' => $times['out'],
                    ]);

                    if ($ok) {
                        $linkedUpdated++;
                    }
                }
            }
            if ($apiError === null || $aggregatedCount > 0) {
                $staleAutoDeleted += $this->deleteStaleApiDailyRows($date, $date, $validAutoKeys);
            }

            $this->finishRun($runId, $apiError === null ? 'success' : 'warning', $fetchedCount, $insertedCount, $apiError);

            return [
                'ok' => $apiError === null || $linkedUpdated > 0,
                'run_id' => $runId,
                'warning' => $apiError,
                'source_min_date' => $sourceMinDate,
                'source_max_date' => $sourceMaxDate,
                'fetched_count' => $fetchedCount,
                'inserted_count' => $insertedCount,
                'usable_count' => $usableCount,
                'day_punch_count' => $dayPunchCount,
                'aggregated_employee_count' => $aggregatedCount,
                'linked_daily_updated' => $linkedUpdated,
                'stale_auto_deleted' => $staleAutoDeleted,
                'unmatched_codes' => array_slice(array_values(array_unique($unmatchedCodes)), 0, 20),
            ];
        } catch (Throwable $e) {
            $this->finishRun($runId, 'error', $fetchedCount, $insertedCount, $e->getMessage());

            return [
                'ok' => false,
                'run_id' => $runId,
                'error' => $e->getMessage(),
                'fetched_count' => $fetchedCount,
                'inserted_count' => $insertedCount,
                'usable_count' => $usableCount,
                'day_punch_count' => $dayPunchCount,
                'aggregated_employee_count' => $aggregatedCount,
                'linked_daily_updated' => $linkedUpdated,
                'stale_auto_deleted' => $staleAutoDeleted,
                'unmatched_codes' => array_slice(array_values(array_unique($unmatchedCodes)), 0, 20),
            ];
        }
    }

    public function syncPeriod(string $startDate, string $endDate, ?array $actor = null, int $limit = 200): array
    {
        $startDate = $this->normalizeDate($startDate) ?? date('Y-m-01');
        $endDate = $this->normalizeDate($endDate) ?? date('Y-m-d');
        if ($endDate < $startDate) {
            [$startDate, $endDate] = [$endDate, $startDate];
        }

        $limit = max(1, min(200, $limit));
        $runId = $this->createRun('period', [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'limit_per_call' => $limit,
        ], $actor);

        $fetchedCount = 0;
        $insertedCount = 0;
        $usableCount = 0;
        $dayPunchCount = 0;
        $linkedUpdated = 0;
        $aggregatedCount = 0;
        $unmatchedCodes = [];
        $apiErrors = [];
        $staleAutoDeleted = 0;

        try {
            $insertStmt = $this->buildInsertEventStmt();
            $dailyStmt = $this->buildUpsertDailyAttendanceStmt();
            $periodAggregates = [];
            $codes = [];

            $cursor = new DateTimeImmutable($startDate);
            $end = new DateTimeImmutable($endDate);
            while ($cursor <= $end) {
                $date = $cursor->format('Y-m-d');
                try {
                    $payload = $this->client->records([
                        'date' => $date,
                        'from' => $date . ' 00:00:00',
                        'to' => $date . ' 23:59:59',
                        'start_date' => $date,
                        'end_date' => $date,
                        'limit' => $limit,
                    ]);

                    $records = $payload['records'] ?? null;
                    if (!is_array($records)) {
                        throw new RuntimeException('Reponse API attendance invalide: "records" manquant.');
                    }
                } catch (Throwable $dayError) {
                    $apiErrors[] = $date . ': ' . $dayError->getMessage();
                    $cursor = $cursor->modify('+1 day');
                    continue;
                }

                $fetchedCount += count($records);
                foreach ($records as $record) {
                    if (!is_array($record)) {
                        continue;
                    }

                    $prepared = $this->prepareRecordForStorage($record);
                    if ($prepared === null) {
                        continue;
                    }

                    $usableCount++;
                    $insertStmt->execute($prepared['params']);
                    if ($insertStmt->rowCount() === 1) {
                        $insertedCount++;
                    }

                    $employeeCode = (string) $prepared['employee_code'];
                    $occurredOn = (string) $prepared['occurred_on'];
                    $time = $this->extractTime((string) $prepared['occurred_at']);
                    if ($time === null || $occurredOn < $startDate || $occurredOn > $endDate) {
                        continue;
                    }

                    $dayPunchCount++;
                    $codes[$employeeCode] = true;
                    $key = $occurredOn . '|' . $employeeCode;
                    if (!isset($periodAggregates[$key])) {
                        $periodAggregates[$key] = [
                            'date' => $occurredOn,
                            'employee_code' => $employeeCode,
                            'employee_name' => (string) ($record['employee_name'] ?? ''),
                            'api_personnel_id' => (int) ($record['personnel_id'] ?? 0),
                            'in' => $time,
                            'out' => $time,
                        ];
                    } else {
                        if ($time < $periodAggregates[$key]['in']) {
                            $periodAggregates[$key]['in'] = $time;
                        }
                        if ($time > $periodAggregates[$key]['out']) {
                            $periodAggregates[$key]['out'] = $time;
                        }
                        if ((string) ($periodAggregates[$key]['employee_name'] ?? '') === '') {
                            $periodAggregates[$key]['employee_name'] = (string) ($record['employee_name'] ?? '');
                        }
                        if ((int) ($periodAggregates[$key]['api_personnel_id'] ?? 0) <= 0) {
                            $periodAggregates[$key]['api_personnel_id'] = (int) ($record['personnel_id'] ?? 0);
                        }
                    }
                }

                $cursor = $cursor->modify('+1 day');
            }

            foreach ($this->loadStoredEventAggregates($startDate, $endDate) as $aggregate) {
                $employeeCode = (string) ($aggregate['employee_code'] ?? '');
                $date = (string) ($aggregate['date'] ?? '');
                if ($employeeCode === '' || $date === '') {
                    continue;
                }

                $codes[$employeeCode] = true;
                $key = $date . '|' . $employeeCode;
                if (!isset($periodAggregates[$key])) {
                    $periodAggregates[$key] = $aggregate;
                    continue;
                }

                if ((string) ($aggregate['in'] ?? '') < (string) ($periodAggregates[$key]['in'] ?? '')) {
                    $periodAggregates[$key]['in'] = (string) $aggregate['in'];
                }
                if ((string) ($aggregate['out'] ?? '') > (string) ($periodAggregates[$key]['out'] ?? '')) {
                    $periodAggregates[$key]['out'] = (string) $aggregate['out'];
                }
                if ((string) ($periodAggregates[$key]['employee_name'] ?? '') === '') {
                    $periodAggregates[$key]['employee_name'] = (string) ($aggregate['employee_name'] ?? '');
                }
            }

            $aggregatedCount = count($periodAggregates);
            $validAutoKeys = [];
            if (!empty($periodAggregates)) {
                $linkMap = $this->getPersonnelIdsByMatricules(array_keys($codes));
                foreach ($periodAggregates as $aggregate) {
                    $personnelId = $this->resolvePersonnelIdByCode((string) $aggregate['employee_code'], $linkMap);

                    if ($personnelId <= 0) {
                        $unmatchedCodes[] = (string) $aggregate['employee_code'];
                        continue;
                    }

                    $validAutoKeys[(string) $aggregate['date'] . '|' . $personnelId] = true;
                    if ($dailyStmt->execute([
                        ':personnel_id' => $personnelId,
                        ':attendance_date' => (string) $aggregate['date'],
                        ':check_in_time' => (string) $aggregate['in'],
                        ':check_out_time' => (string) $aggregate['out'],
                    ])) {
                        $linkedUpdated++;
                    }
                }
            }
            if (empty($apiErrors) || $aggregatedCount > 0) {
                $staleAutoDeleted += $this->deleteStaleApiDailyRows($startDate, $endDate, $validAutoKeys);
            }

            $apiWarning = $apiErrors ? implode(' | ', array_slice($apiErrors, 0, 5)) : null;
            $this->finishRun($runId, $apiWarning === null ? 'success' : 'warning', $fetchedCount, $insertedCount, $apiWarning);

            return [
                'ok' => $apiWarning === null || $linkedUpdated > 0,
                'run_id' => $runId,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'warning' => $apiWarning,
                'fetched_count' => $fetchedCount,
                'inserted_count' => $insertedCount,
                'usable_count' => $usableCount,
                'day_punch_count' => $dayPunchCount,
                'aggregated_employee_count' => $aggregatedCount,
                'linked_daily_updated' => $linkedUpdated,
                'stale_auto_deleted' => $staleAutoDeleted,
                'unmatched_codes' => array_slice(array_values(array_unique($unmatchedCodes)), 0, 50),
            ];
        } catch (Throwable $e) {
            $this->finishRun($runId, 'error', $fetchedCount, $insertedCount, $e->getMessage());

            return [
                'ok' => false,
                'run_id' => $runId,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'error' => $e->getMessage(),
                'fetched_count' => $fetchedCount,
                'inserted_count' => $insertedCount,
                'usable_count' => $usableCount,
                'day_punch_count' => $dayPunchCount,
                'aggregated_employee_count' => $aggregatedCount,
                'linked_daily_updated' => $linkedUpdated,
                'stale_auto_deleted' => $staleAutoDeleted,
                'unmatched_codes' => array_slice(array_values(array_unique($unmatchedCodes)), 0, 50),
            ];
        }
    }

    public function listRecentRuns(int $limit = 20): array
    {
        $limit = max(1, min(200, $limit));
        $stmt = $this->pdo->prepare('SELECT * FROM attendance_sync_runs ORDER BY id DESC LIMIT :limit');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function listRecentEvents(int $limit = 30): array
    {
        $limit = max(1, min(200, $limit));
        $stmt = $this->pdo->prepare('SELECT event_uid, occurred_at, occurred_on, employee_code, employee_name, event_label, event_code
            FROM attendance_events
            ORDER BY occurred_at DESC
            LIMIT :limit');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getLinkForPersonnel(int $personnelId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM attendance_personnel_links WHERE personnel_id = :pid LIMIT 1');
        $stmt->execute([':pid' => $personnelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function linkPersonnel(int $personnelId, string $employeeCode, ?array $actor = null): array
    {
        $personnelId = (int) $personnelId;
        $employeeCode = trim($employeeCode);
        if ($personnelId <= 0) {
            throw new InvalidArgumentException('Personnel invalide.');
        }
        if ($employeeCode === '') {
            throw new InvalidArgumentException('Employee code badgeuse obligatoire.');
        }

        $existing = $this->pdo->prepare('SELECT personnel_id FROM attendance_personnel_links WHERE employee_code = :code LIMIT 1');
        $existing->execute([':code' => $employeeCode]);
        $existingPid = (int) ($existing->fetchColumn() ?: 0);
        if ($existingPid > 0 && $existingPid !== $personnelId) {
            throw new RuntimeException('Ce employee_code est déjà lié à un autre personnel (ID ' . $existingPid . ').');
        }

        $employeeName = null;
        try {
            $resp = $this->client->employees(['employee_code' => $employeeCode]);
            $employees = $resp['employees'] ?? null;
            if (is_array($employees) && isset($employees[0]) && is_array($employees[0])) {
                $employeeName = $this->truncateNullableString($employees[0]['employee_name'] ?? null, 150);
            }
        } catch (Throwable $e) {
            // Non bloquant: on peut lier même si l'API ne répond pas.
        }

        $stmt = $this->pdo->prepare('INSERT INTO attendance_personnel_links (
            personnel_id,
            employee_code,
            employee_name_snapshot,
            linked_at,
            linked_by_user_id,
            linked_by_name
        ) VALUES (
            :pid,
            :code,
            :name,
            NOW(),
            :uid,
            :uname
        ) ON DUPLICATE KEY UPDATE
            employee_code = VALUES(employee_code),
            employee_name_snapshot = VALUES(employee_name_snapshot),
            linked_at = NOW(),
            linked_by_user_id = VALUES(linked_by_user_id),
            linked_by_name = VALUES(linked_by_name)
        ');

        $uid = $actor['user_id'] ?? null;
        $uname = $actor['user_name'] ?? null;

        $stmt->execute([
            ':pid' => $personnelId,
            ':code' => $employeeCode,
            ':name' => $employeeName,
            ':uid' => is_numeric($uid) ? (int) $uid : null,
            ':uname' => $this->truncateNullableString($uname, 150),
        ]);

        return [
            'ok' => true,
            'personnel_id' => $personnelId,
            'employee_code' => $employeeCode,
            'employee_name_snapshot' => $employeeName,
        ];
    }

    public function unlinkPersonnel(int $personnelId, ?array $actor = null): bool
    {
        $personnelId = (int) $personnelId;
        if ($personnelId <= 0) {
            return false;
        }

        $stmt = $this->pdo->prepare('DELETE FROM attendance_personnel_links WHERE personnel_id = :pid');
        $stmt->execute([':pid' => $personnelId]);
        return $stmt->rowCount() > 0;
    }

    private function ensureTables(): void
    {
        // Table RH (si le module n'a pas encore été initialisé via SQL manuel).
        $this->pdo->exec('
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

        $this->pdo->exec('
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

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS attendance_sync_runs (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                scope VARCHAR(20) NOT NULL,
                request_params_json LONGTEXT DEFAULT NULL,
                status VARCHAR(16) NOT NULL DEFAULT "success",
                fetched_count INT NOT NULL DEFAULT 0,
                inserted_count INT NOT NULL DEFAULT 0,
                error_message TEXT DEFAULT NULL,
                started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                finished_at DATETIME DEFAULT NULL,
                created_by_user_id INT DEFAULT NULL,
                created_by_name VARCHAR(150) DEFAULT NULL,
                KEY idx_attendance_sync_runs_started (started_at),
                KEY idx_attendance_sync_runs_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS attendance_personnel_links (
                personnel_id INT NOT NULL,
                employee_code VARCHAR(60) NOT NULL,
                employee_name_snapshot VARCHAR(150) DEFAULT NULL,
                linked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                linked_by_user_id INT DEFAULT NULL,
                linked_by_name VARCHAR(150) DEFAULT NULL,
                PRIMARY KEY (personnel_id),
                UNIQUE KEY uniq_attendance_personnel_employee_code (employee_code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // Index utile si la table existe déjà sans ces index.
        try {
            $this->pdo->exec('CREATE INDEX idx_attendance_personnel_links_code ON attendance_personnel_links (employee_code)');
        } catch (Throwable $e) {
            // ignore
        }
    }

    private function createRun(string $scope, array $params, ?array $actor): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO attendance_sync_runs (scope, request_params_json, status, started_at, created_by_user_id, created_by_name)
            VALUES (:scope, :params, "running", NOW(), :uid, :uname)');

        $uid = $actor['user_id'] ?? null;
        $uname = $actor['user_name'] ?? null;

        $stmt->execute([
            ':scope' => $scope,
            ':params' => json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':uid' => is_numeric($uid) ? (int) $uid : null,
            ':uname' => $this->truncateNullableString($uname, 150),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function buildInsertEventStmt(): PDOStatement
    {
        return $this->pdo->prepare('INSERT INTO attendance_events (
            event_uid,
            occurred_at,
            occurred_on,
            employee_code,
            employee_name,
            event_label,
            event_code,
            payload_json,
            inserted_at
        ) VALUES (
            :event_uid,
            :occurred_at,
            :occurred_on,
            :employee_code,
            :employee_name,
            :event_label,
            :event_code,
            :payload_json,
            NOW()
        ) ON DUPLICATE KEY UPDATE
            occurred_at = VALUES(occurred_at),
            occurred_on = VALUES(occurred_on),
            employee_code = VALUES(employee_code),
            employee_name = COALESCE(VALUES(employee_name), employee_name),
            event_label = COALESCE(VALUES(event_label), event_label),
            event_code = COALESCE(VALUES(event_code), event_code),
            payload_json = VALUES(payload_json)
        ');
    }

    private function prepareRecordForStorage(array $record): ?array
    {
        $eventUid = trim((string) ($record['event_uid'] ?? ''));
        $devicePunchTime = trim((string) ($record['device_punch_time'] ?? $record['punch_time'] ?? ''));
        $occurredAt = $this->officialOccurredAt($record);
        $occurredOn = trim((string) ($record['occurred_on'] ?? ''));
        $employeeCode = $this->normalizeEmployeeCode((string) ($record['employee_code'] ?? ''));

        if ($occurredAt === '' || $employeeCode === '') {
            return null;
        }

        if ($eventUid === '') {
            $eventUid = sha1($employeeCode . '|' . $occurredAt . '|' . $devicePunchTime . '|' . json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        if ($occurredOn === '') {
            $occurredOn = substr($occurredAt, 0, 10);
        }
        $normalizedOccurredOn = $this->normalizeDate($occurredOn);
        if ($normalizedOccurredOn === null || $normalizedOccurredOn !== substr($occurredAt, 0, 10)) {
            $occurredOn = substr($occurredAt, 0, 10);
        }

        $record['occurred_at'] = $occurredAt;
        $record['occurred_on'] = $occurredOn;
        $record['device_punch_time'] = $devicePunchTime;
        $record['attendance_official_time_source'] = $this->hasServerReceivedTime($record) ? 'received_at_gmt0' : 'punch_time_fallback';

        return [
            'event_uid' => $eventUid,
            'occurred_at' => $occurredAt,
            'occurred_on' => $occurredOn,
            'employee_code' => $employeeCode,
            'params' => [
                ':event_uid' => $eventUid,
                ':occurred_at' => $occurredAt,
                ':occurred_on' => $occurredOn,
                ':employee_code' => $employeeCode,
                ':employee_name' => $this->truncateNullableString($record['employee_name'] ?? null, 150),
                ':event_label' => $this->truncateNullableString($record['event_label'] ?? null, 190),
                ':event_code' => $this->truncateNullableString($record['event_code'] ?? null, 20),
                ':payload_json' => json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ],
        ];
    }

    private function officialOccurredAt(array $record): string
    {
        foreach (['received_at', 'server_received_at', 'collected_at', 'ingested_at', 'inserted_at'] as $key) {
            $value = trim((string) ($record[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return trim((string) ($record['occurred_at'] ?? $record['punch_time'] ?? ''));
    }

    private function hasServerReceivedTime(array $record): bool
    {
        foreach (['received_at', 'server_received_at', 'collected_at', 'ingested_at', 'inserted_at'] as $key) {
            if (trim((string) ($record[$key] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    private function finishRun(int $runId, string $status, int $fetched, int $inserted, ?string $error): void
    {
        $stmt = $this->pdo->prepare('UPDATE attendance_sync_runs
            SET status = :status,
                fetched_count = :fetched,
                inserted_count = :inserted,
                error_message = :err,
                finished_at = NOW()
            WHERE id = :id');

        $stmt->execute([
            ':status' => $status,
            ':fetched' => $fetched,
            ':inserted' => $inserted,
            ':err' => $error,
            ':id' => $runId,
        ]);
    }

    private function getPersonnelIdsByEmployeeCodes(array $codes): array
    {
        $codes = array_values(array_filter(array_map('strval', $codes), static fn($v) => trim($v) !== ''));
        if (empty($codes)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($codes), '?'));
        $stmt = $this->pdo->prepare('SELECT employee_code, personnel_id FROM attendance_personnel_links WHERE employee_code IN (' . $placeholders . ')');
        $stmt->execute($codes);

        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $map[(string) $row['employee_code']] = (int) $row['personnel_id'];
        }
        return $map;
    }

    private function getPersonnelIdsByMatricules(array $matricules): array
    {
        $wanted = [];
        foreach ($matricules as $matricule) {
            foreach ($this->employeeCodeAliases((string) $matricule) as $alias) {
                $wanted[$alias] = true;
            }
        }

        if (empty($wanted)) {
            return [];
        }

        $stmt = $this->pdo->query('
            SELECT
                id_personnel_soignant AS personnel_id,
                matricule_personnel_soignant AS matricule
            FROM personnel
            WHERE matricule_personnel_soignant IS NOT NULL
                AND TRIM(matricule_personnel_soignant) <> ""
        ');

        $map = [];
        try {
            $linkStmt = $this->pdo->query('SELECT employee_code, personnel_id FROM attendance_personnel_links');
            foreach (($linkStmt ? $linkStmt->fetchAll(PDO::FETCH_ASSOC) : []) ?: [] as $row) {
                $personnelId = (int) ($row['personnel_id'] ?? 0);
                if ($personnelId <= 0) {
                    continue;
                }
                foreach ($this->employeeCodeAliases((string) ($row['employee_code'] ?? '')) as $alias) {
                    if (isset($wanted[$alias]) && !isset($map[$alias])) {
                        $map[$alias] = $personnelId;
                    }
                }
            }
        } catch (Throwable $e) {
            // Les liens explicites sont un bonus ; la correspondance matricule reste disponible.
        }

        foreach (($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []) ?: [] as $row) {
            $personnelId = (int) ($row['personnel_id'] ?? 0);
            if ($personnelId <= 0) {
                continue;
            }

            foreach ($this->employeeCodeAliases((string) ($row['matricule'] ?? '')) as $alias) {
                if (isset($wanted[$alias]) && !isset($map[$alias])) {
                    $map[$alias] = $personnelId;
                }
            }
        }

        return $map;
    }

    private function resolvePersonnelIdByCode(string $employeeCode, array $codeMap): int
    {
        foreach ($this->employeeCodeAliases($employeeCode) as $alias) {
            $personnelId = (int) ($codeMap[$alias] ?? 0);
            if ($personnelId > 0) {
                return $personnelId;
            }
        }

        return 0;
    }

    private function deleteStaleApiDailyRows(string $startDate, string $endDate, array $validKeys): int
    {
        $stmt = $this->pdo->prepare('
            SELECT id, personnel_id, attendance_date
            FROM rh_attendance_daily
            WHERE attendance_date BETWEEN :start_date AND :end_date
              AND recorded_by_user_id IS NULL
              AND LOWER(TRIM(COALESCE(recorded_by_name, ""))) = "attendance-api"
              AND (
                  notes IS NULL
                  OR TRIM(notes) = ""
                  OR LOWER(TRIM(notes)) = "auto: attendance-api"
              )
        ');
        $stmt->execute([
            ':start_date' => $startDate,
            ':end_date' => $endDate,
        ]);

        $delete = $this->pdo->prepare('DELETE FROM rh_attendance_daily WHERE id = :id');
        $deleted = 0;
        foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
            $key = (string) ($row['attendance_date'] ?? '') . '|' . (int) ($row['personnel_id'] ?? 0);
            if (isset($validKeys[$key])) {
                continue;
            }

            $delete->execute([':id' => (int) ($row['id'] ?? 0)]);
            $deleted += $delete->rowCount();
        }

        return $deleted;
    }

    private function loadStoredEventAggregates(string $startDate, string $endDate): array
    {
        $stmt = $this->pdo->prepare('
            SELECT
                occurred_on AS date,
                employee_code,
                MAX(employee_name) AS employee_name,
                MAX(CAST(JSON_UNQUOTE(JSON_EXTRACT(payload_json, "$.personnel_id")) AS UNSIGNED)) AS api_personnel_id,
                MIN(TIME(occurred_at)) AS check_in_time,
                MAX(TIME(occurred_at)) AS check_out_time
            FROM attendance_events
            WHERE occurred_on BETWEEN :start_date AND :end_date
              AND employee_code IS NOT NULL
              AND TRIM(employee_code) <> ""
            GROUP BY occurred_on, employee_code
            ORDER BY occurred_on ASC, employee_code ASC
        ');
        $stmt->execute([
            ':start_date' => $startDate,
            ':end_date' => $endDate,
        ]);

        $aggregates = [];
        foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
            $date = $this->normalizeDate((string) ($row['date'] ?? ''));
            $employeeCode = $this->normalizeEmployeeCode((string) ($row['employee_code'] ?? ''));
            $in = $this->extractTime((string) ($row['check_in_time'] ?? ''));
            $out = $this->extractTime((string) ($row['check_out_time'] ?? ''));
            if ($date === null || $employeeCode === '' || $in === null || $out === null) {
                continue;
            }

            $aggregates[] = [
                'date' => $date,
                'employee_code' => $employeeCode,
                'employee_name' => (string) ($row['employee_name'] ?? ''),
                'api_personnel_id' => (int) ($row['api_personnel_id'] ?? 0),
                'in' => $in,
                'out' => $out,
            ];
        }

        return $aggregates;
    }

    private function buildUpsertDailyAttendanceStmt(): PDOStatement
    {
        // On n'écrase pas les statuts/notes manuels ; on ne fait que compléter les heures.
        return $this->pdo->prepare('INSERT INTO rh_attendance_daily (
            personnel_id,
            attendance_date,
            attendance_status,
            worked_days,
            overtime_hours,
            check_in_time,
            check_out_time,
            notes,
            recorded_by_user_id,
            recorded_by_name,
            created_at,
            updated_at
        ) VALUES (
            :personnel_id,
            :attendance_date,
            "present",
            1.00,
            0.00,
            :check_in_time,
            :check_out_time,
            NULL,
            NULL,
            "attendance-api",
            NOW(),
            NOW()
        ) ON DUPLICATE KEY UPDATE
            attendance_status = CASE
                WHEN recorded_by_user_id IS NOT NULL OR LOWER(TRIM(COALESCE(recorded_by_name, ""))) NOT IN ("", "attendance-api") THEN attendance_status
                WHEN attendance_status IN ("absence", "repos") AND (VALUES(check_in_time) IS NOT NULL OR VALUES(check_out_time) IS NOT NULL) THEN "present"
                ELSE attendance_status
            END,
            worked_days = CASE
                WHEN recorded_by_user_id IS NOT NULL OR LOWER(TRIM(COALESCE(recorded_by_name, ""))) NOT IN ("", "attendance-api") THEN worked_days
                WHEN (attendance_status IN ("absence", "repos") OR worked_days < 1.00) AND (VALUES(check_in_time) IS NOT NULL OR VALUES(check_out_time) IS NOT NULL) THEN GREATEST(worked_days, 1.00)
                ELSE worked_days
            END,
            check_in_time = CASE
                WHEN recorded_by_user_id IS NOT NULL OR LOWER(TRIM(COALESCE(recorded_by_name, ""))) NOT IN ("", "attendance-api") THEN check_in_time
                WHEN check_in_time IS NULL THEN VALUES(check_in_time)
                WHEN VALUES(check_in_time) IS NULL THEN check_in_time
                WHEN VALUES(check_in_time) < check_in_time THEN VALUES(check_in_time)
                ELSE check_in_time
            END,
            check_out_time = CASE
                WHEN recorded_by_user_id IS NOT NULL OR LOWER(TRIM(COALESCE(recorded_by_name, ""))) NOT IN ("", "attendance-api") THEN check_out_time
                WHEN check_out_time IS NULL THEN VALUES(check_out_time)
                WHEN VALUES(check_out_time) IS NULL THEN check_out_time
                WHEN VALUES(check_out_time) > check_out_time THEN VALUES(check_out_time)
                ELSE check_out_time
            END,
            notes = CASE
                WHEN LOWER(TRIM(COALESCE(notes, ""))) = "auto: attendance-api" THEN NULL
                ELSE notes
            END,
            updated_at = NOW()
        ');
    }

    private function extractTime(string $occurredAt): ?string
    {
        $occurredAt = trim($occurredAt);
        if ($occurredAt === '') {
            return null;
        }

        // Format attendu: YYYY-MM-DD HH:MM:SS
        if (preg_match('/\b(\d{2}:\d{2}:\d{2})\b/', $occurredAt, $m)) {
            return $m[1];
        }

        $ts = strtotime($occurredAt);
        if ($ts === false) {
            return null;
        }

        return date('H:i:s', $ts);
    }

    private function normalizeDate(string $date): ?string
    {
        $date = trim($date);
        if ($date === '') {
            return null;
        }

        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $date);
        if ($dt instanceof DateTimeImmutable && $dt->format('Y-m-d') === $date) {
            return $date;
        }

        $ts = strtotime($date);
        return $ts !== false ? date('Y-m-d', $ts) : null;
    }

    private function normalizeEmployeeCode(string $value): string
    {
        return strtoupper(trim($value));
    }

    private function employeeCodeAliases(string $value): array
    {
        $normalized = $this->normalizeEmployeeCode($value);
        if ($normalized === '') {
            return [];
        }

        $aliases = [$normalized => true];
        $compact = preg_replace('/[\s\-_]+/', '', $normalized);
        if (is_string($compact) && $compact !== '') {
            $aliases[$compact] = true;
        }

        foreach (array_keys($aliases) as $alias) {
            $alias = (string) $alias;
            if (preg_match('/^\d+$/', $alias) === 1) {
                $withoutLeadingZeros = ltrim($alias, '0');
                $aliases[$withoutLeadingZeros !== '' ? $withoutLeadingZeros : '0'] = true;
            }
        }

        return array_keys($aliases);
    }

    private function truncateNullableString($value, int $maxLen): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = trim((string) $value);
        if ($s === '') {
            return null;
        }
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($s) > $maxLen) {
                $s = mb_substr($s, 0, $maxLen);
            }
        } else {
            if (strlen($s) > $maxLen) {
                $s = substr($s, 0, $maxLen);
            }
        }
        return $s;
    }
}
