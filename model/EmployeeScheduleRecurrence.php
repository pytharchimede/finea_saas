<?php

declare(strict_types=1);

require_once __DIR__ . '/EmployeeSchedule.php';
require_once __DIR__ . '/../controllers/NotificationController.php';

class EmployeeScheduleRecurrence
{
    private PDO $pdo;
    private bool $ready = true;
    private ?string $initError = null;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureTables();
    }

    public function isReady(): bool
    {
        return $this->ready;
    }

    public function getInitError(): ?string
    {
        return $this->initError;
    }

    private function ensureTables(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS employee_schedule_recurrence_rule (
            id INT NOT NULL AUTO_INCREMENT,
            personnel_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            description TEXT NULL,
            location VARCHAR(255) NULL,
            assignment_type VARCHAR(20) NOT NULL DEFAULT 'bureau',
            assignment_chantier_id INT NULL,
            status_code VARCHAR(30) NOT NULL DEFAULT 'planifie',
            pattern_type VARCHAR(16) NOT NULL,
            interval_n INT NOT NULL DEFAULT 1,
            week_days_json TEXT NULL,
            month_day TINYINT NULL,
            start_date DATE NOT NULL,
            end_date DATE NULL,
            time_of_day TIME NOT NULL,
            duration_minutes INT NOT NULL,
            exclude_weekends TINYINT(1) NOT NULL DEFAULT 0,
            exclude_holidays TINYINT(1) NOT NULL DEFAULT 0,
            next_run_at DATETIME NOT NULL,
            is_enabled TINYINT(1) NOT NULL DEFAULT 1,
            created_by_user_id INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_recur_next (is_enabled, next_run_at),
            KEY idx_recur_personnel (personnel_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

        try {
            $this->pdo->exec($sql);
            $this->ensureColumns();
        } catch (Throwable $e) {
            $this->ready = false;
            $this->initError = 'Initialisation SQL récurrence impossible (droits insuffisants ?)';
        }
    }

    private function ensureColumns(): void
    {
        try {
            $cols = [];
            $stmt = $this->pdo->query('SHOW COLUMNS FROM employee_schedule_recurrence_rule');
            if ($stmt) {
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $field = strtolower((string)($row['Field'] ?? ''));
                    if ($field !== '') {
                        $cols[$field] = true;
                    }
                }
            }

            $alter = [];
            if (!isset($cols['assignment_type'])) {
                $alter[] = "ADD COLUMN assignment_type VARCHAR(20) NOT NULL DEFAULT 'bureau' AFTER location";
            }
            if (!isset($cols['assignment_chantier_id'])) {
                $alter[] = 'ADD COLUMN assignment_chantier_id INT NULL AFTER assignment_type';
            }
            if ($alter) {
                $this->pdo->exec('ALTER TABLE employee_schedule_recurrence_rule ' . implode(', ', $alter));
            }
        } catch (Throwable $e) {
            // Ne pas bloquer la recurrence existante.
        }
    }

    /**
     * @param array<string,mixed> $recurrence
     */
    public function createRuleFromCreatePayload(
        int $personnelId,
        string $title,
        string $startAt,
        string $endAt,
        string $statusCode,
        ?int $createdByUserId,
        ?string $description,
        ?string $location,
        array $recurrence,
        ?string $assignmentType = null,
        ?int $assignmentChantierId = null
    ): array {
        if (!$this->ready) {
            return ['ok' => false, 'error' => $this->initError ?? 'Module récurrence indisponible.'];
        }
        if ($personnelId <= 0) {
            return ['ok' => false, 'error' => 'Personnel invalide.'];
        }

        $title = trim($title);
        if ($title === '') {
            return ['ok' => false, 'error' => 'Le titre est requis.'];
        }

        $start = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $startAt) ?: DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $startAt);
        $end = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $endAt) ?: DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $endAt);
        if (!$start || !$end || $end <= $start) {
            return ['ok' => false, 'error' => 'Dates/heures invalides.'];
        }

        $durationMinutes = (int) round(($end->getTimestamp() - $start->getTimestamp()) / 60);
        if ($durationMinutes <= 0) {
            $durationMinutes = 60;
        }

        $patternType = strtolower(trim((string)($recurrence['type'] ?? '')));
        if (!in_array($patternType, ['daily', 'weekly', 'monthly'], true)) {
            return ['ok' => false, 'error' => 'Type de répétition invalide.'];
        }

        $intervalN = (int)($recurrence['interval'] ?? 1);
        if ($intervalN < 1) {
            $intervalN = 1;
        }
        if ($intervalN > 365) {
            $intervalN = 365;
        }

        $weekDays = [];
        if ($patternType === 'weekly') {
            $rawDays = $recurrence['week_days'] ?? [];
            if (is_array($rawDays)) {
                foreach ($rawDays as $d) {
                    $v = (int)$d;
                    if ($v >= 1 && $v <= 7) {
                        $weekDays[$v] = true;
                    }
                }
            }
            $weekDays = array_keys($weekDays);
            sort($weekDays);
            if (!$weekDays) {
                // Par défaut : le jour de la date de départ
                $weekDays = [(int)$start->format('N')];
            }
        }

        $monthDay = null;
        if ($patternType === 'monthly') {
            $md = (int)($recurrence['month_day'] ?? (int)$start->format('j'));
            if ($md < 1) {
                $md = 1;
            }
            if ($md > 31) {
                $md = 31;
            }
            $monthDay = $md;
        }

        $excludeWeekends = !empty($recurrence['exclude_weekends']);
        $excludeHolidays = !empty($recurrence['exclude_holidays']);

        $startDate = $start->format('Y-m-d');
        $endDate = null;
        $rawEndDate = trim((string)($recurrence['end_date'] ?? ''));
        if ($rawEndDate !== '') {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawEndDate)) {
                return ['ok' => false, 'error' => 'Date de fin de répétition invalide.'];
            }
            $endDate = $rawEndDate;
        }

        $timeOfDay = $start->format('H:i:s');

        $next = $this->computeNextOccurrenceAfter(
            $start,
            $patternType,
            $intervalN,
            $weekDays,
            $monthDay,
            $timeOfDay,
            $excludeWeekends,
            $excludeHolidays,
            $startDate,
            $endDate
        );

        if (!$next) {
            return ['ok' => false, 'error' => 'Impossible de calculer la prochaine occurrence.'];
        }

        $description = $description !== null ? trim($description) : null;
        $location = $location !== null ? trim($location) : null;
        if ($description === '') {
            $description = null;
        }
        if ($location === '') {
            $location = null;
        }
        $assignment = $this->normalizeAssignment($assignmentType, $assignmentChantierId);

        $stmt = $this->pdo->prepare('INSERT INTO employee_schedule_recurrence_rule (
            personnel_id, title, description, location, assignment_type, assignment_chantier_id, status_code,
            pattern_type, interval_n, week_days_json, month_day,
            start_date, end_date, time_of_day, duration_minutes,
            exclude_weekends, exclude_holidays,
            next_run_at, is_enabled, created_by_user_id
        ) VALUES (
            :pid, :title, :descr, :loc, :assignment_type, :assignment_chantier_id, :status,
            :ptype, :interval_n, :week_days_json, :month_day,
            :start_date, :end_date, :time_of_day, :duration_minutes,
            :exclude_weekends, :exclude_holidays,
            :next_run_at, 1, :created_by
        )');

        $stmt->execute([
            'pid' => $personnelId,
            'title' => $title,
            'descr' => $description,
            'loc' => $location,
            'assignment_type' => $assignment['type'],
            'assignment_chantier_id' => $assignment['chantier_id'],
            'status' => trim($statusCode) !== '' ? trim($statusCode) : 'planifie',
            'ptype' => $patternType,
            'interval_n' => $intervalN,
            'week_days_json' => $weekDays ? json_encode(array_values($weekDays), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'month_day' => $monthDay,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'time_of_day' => $timeOfDay,
            'duration_minutes' => $durationMinutes,
            'exclude_weekends' => $excludeWeekends ? 1 : 0,
            'exclude_holidays' => $excludeHolidays ? 1 : 0,
            'next_run_at' => $next->format('Y-m-d H:i:s'),
            'created_by' => $createdByUserId !== null && $createdByUserId > 0 ? $createdByUserId : null,
        ]);

        return ['ok' => true, 'id' => (int)$this->pdo->lastInsertId(), 'next_run_at' => $next->format('Y-m-d H:i:s')];
    }

    public function generateDueOccurrences(DateTimeImmutable $now, int $horizonDays = 30, int $maxPerRule = 60): array
    {
        if (!$this->ready) {
            return ['ok' => false, 'error' => $this->initError ?? 'Module récurrence indisponible.'];
        }

        $horizonDays = max(1, min(366, (int)$horizonDays));
        $maxPerRule = max(1, min(200, (int)$maxPerRule));

        $schedule = new EmployeeSchedule($this->pdo);
        $notif = new NotificationController($this->pdo);

        if (!$schedule->isReady()) {
            return ['ok' => false, 'error' => $schedule->getInitError() ?: 'Planning indisponible.'];
        }

        // On génère uniquement dans le futur (à partir de maintenant)
        $rangeStart = $now;
        $rangeEnd = $now->modify('+' . $horizonDays . ' days');
        $holidaySet = $this->loadHolidaySet($now->format('Y-m-d'), $rangeEnd->format('Y-m-d'));

        $stmt = $this->pdo->prepare('SELECT * FROM employee_schedule_recurrence_rule WHERE is_enabled = 1 AND next_run_at <= :max_dt ORDER BY next_run_at ASC');
        $stmt->execute(['max_dt' => $rangeEnd->format('Y-m-d H:i:s')]);
        $rules = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $created = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($rules as $r) {
            $ruleId = (int)($r['id'] ?? 0);
            $personnelId = (int)($r['personnel_id'] ?? 0);
            if ($ruleId <= 0 || $personnelId <= 0) {
                continue;
            }

            $patternType = strtolower(trim((string)($r['pattern_type'] ?? '')));
            $intervalN = (int)($r['interval_n'] ?? 1);
            if ($intervalN < 1) {
                $intervalN = 1;
            }

            $weekDays = [];
            if (!empty($r['week_days_json'])) {
                $decoded = json_decode((string)$r['week_days_json'], true);
                if (is_array($decoded)) {
                    foreach ($decoded as $d) {
                        $v = (int)$d;
                        if ($v >= 1 && $v <= 7) {
                            $weekDays[$v] = true;
                        }
                    }
                }
            }
            $weekDays = array_keys($weekDays);
            sort($weekDays);

            $monthDay = null;
            if (!empty($r['month_day'])) {
                $md = (int)$r['month_day'];
                if ($md >= 1 && $md <= 31) {
                    $monthDay = $md;
                }
            }

            $excludeWeekends = ((int)($r['exclude_weekends'] ?? 0)) === 1;
            $excludeHolidays = ((int)($r['exclude_holidays'] ?? 0)) === 1;

            $startDate = (string)($r['start_date'] ?? '');
            $endDate = !empty($r['end_date']) ? (string)$r['end_date'] : null;
            $timeOfDay = (string)($r['time_of_day'] ?? '09:00:00');
            $durationMinutes = (int)($r['duration_minutes'] ?? 60);
            if ($durationMinutes <= 0) {
                $durationMinutes = 60;
            }

            $title = (string)($r['title'] ?? '');
            $statusCode = (string)($r['status_code'] ?? 'planifie');
            $description = (string)($r['description'] ?? '');
            $location = (string)($r['location'] ?? '');
            $assignmentType = (string)($r['assignment_type'] ?? 'bureau');
            $assignmentChantierId = isset($r['assignment_chantier_id']) ? (int)$r['assignment_chantier_id'] : null;

            $nextRunRaw = (string)($r['next_run_at'] ?? '');
            try {
                $cursor = new DateTimeImmutable($nextRunRaw);
            } catch (Throwable $e) {
                $cursor = $now;
            }

            // Fast-forward: si next_run_at est dans le passé, on saute les occurrences passées
            // en avançant selon la périodicité (sans créer).
            $fast = 0;
            while ($cursor < $rangeStart && $fast < 500) {
                $nextFast = $this->computeNextOccurrenceAfter(
                    $cursor,
                    $patternType,
                    $intervalN,
                    $weekDays,
                    $monthDay,
                    $timeOfDay,
                    $excludeWeekends,
                    $excludeHolidays,
                    $startDate,
                    $endDate
                );
                if (!$nextFast) {
                    $this->disableRule($ruleId);
                    break;
                }
                $cursor = $nextFast;
                $fast++;
            }

            $occCount = 0;
            $lastProcessed = null;

            while ($occCount < $maxPerRule && $cursor <= $rangeEnd) {
                // Bornage end_date
                if ($endDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
                    $endDt = DateTimeImmutable::createFromFormat('Y-m-d', $endDate);
                    if ($endDt && $cursor->format('Y-m-d') > $endDt->format('Y-m-d')) {
                        // Terminé
                        $this->disableRule($ruleId);
                        break;
                    }
                }

                // Exclusions
                $ymd = $cursor->format('Y-m-d');
                $weekday = (int)$cursor->format('N');
                $isWeekend = ($weekday >= 6);
                $isHoliday = isset($holidaySet[$ymd]);

                $excluded = false;
                if ($excludeWeekends && $isWeekend) {
                    $excluded = true;
                }
                if ($excludeHolidays && $isHoliday) {
                    $excluded = true;
                }

                $endCursor = $cursor->modify('+' . $durationMinutes . ' minutes');

                if ($excluded) {
                    $skipped++;
                } else {
                    try {
                        $res = $schedule->createEvent($personnelId, $title, $cursor->format('Y-m-d H:i:s'), $endCursor->format('Y-m-d H:i:s'), $statusCode, null, $description, $location, $ruleId, null, [], $assignmentType, $assignmentChantierId);
                    } catch (Throwable $e) {
                        $res = ['ok' => false, 'error' => 'SQL'];
                    }

                    if (!empty($res['ok'])) {
                        $created++;
                        $eventId = (int)($res['id'] ?? 0);

                        $recipientUserId = $this->resolveActiveUserIdByPersonnelId($personnelId);
                        if ($recipientUserId !== null && $recipientUserId > 0) {
                            try {
                                $notif->notifyScheduleOccurrenceCreated($recipientUserId, [
                                    'event_id' => $eventId,
                                    'title' => $title,
                                    'start_at' => $cursor->format('Y-m-d H:i:s'),
                                    'end_at' => $endCursor->format('Y-m-d H:i:s'),
                                    'personnel_id' => $personnelId,
                                    'recurrence_rule_id' => $ruleId,
                                ]);
                            } catch (Throwable $e) {
                                // Ne pas bloquer la génération.
                            }
                        }
                    } else {
                        // chevauchement/duplicate/etc : on skip l'occurrence
                        $skipped++;
                    }
                }

                $occCount++;
                $lastProcessed = $cursor;

                $next = $this->computeNextOccurrenceAfter(
                    $cursor,
                    $patternType,
                    $intervalN,
                    $weekDays,
                    $monthDay,
                    $timeOfDay,
                    $excludeWeekends,
                    $excludeHolidays,
                    $startDate,
                    $endDate
                );

                if (!$next) {
                    $errors++;
                    $this->disableRule($ruleId);
                    break;
                }

                $cursor = $next;
            }

            if ($lastProcessed) {
                try {
                    $this->updateNextRunAt($ruleId, $cursor);
                } catch (Throwable $e) {
                    $errors++;
                }
            }
        }

        return [
            'ok' => true,
            'created' => $created,
            'skipped' => $skipped,
            'errors' => $errors,
            'rules' => count($rules),
        ];
    }

    private function disableRule(int $ruleId): void
    {
        $ruleId = (int)$ruleId;
        if ($ruleId <= 0) {
            return;
        }
        $stmt = $this->pdo->prepare('UPDATE employee_schedule_recurrence_rule SET is_enabled = 0 WHERE id = :id');
        $stmt->execute(['id' => $ruleId]);
    }

    private function updateNextRunAt(int $ruleId, DateTimeImmutable $next): void
    {
        $ruleId = (int)$ruleId;
        if ($ruleId <= 0) {
            return;
        }
        $stmt = $this->pdo->prepare('UPDATE employee_schedule_recurrence_rule SET next_run_at = :nxt WHERE id = :id');
        $stmt->execute([
            'id' => $ruleId,
            'nxt' => $next->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return array<string,bool> set['Y-m-d']=true
     */
    private function loadHolidaySet(string $startYmd, string $endYmd): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startYmd) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endYmd)) {
            return [];
        }

        try {
            $stmt = $this->pdo->prepare('SELECT holiday_date FROM rh_holidays WHERE holiday_date BETWEEN :s AND :e');
            $stmt->execute(['s' => $startYmd, 'e' => $endYmd]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }

        $set = [];
        foreach ($rows as $row) {
            $d = (string)($row['holiday_date'] ?? '');
            if ($d !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                $set[$d] = true;
            }
        }
        return $set;
    }

    private function resolveActiveUserIdByPersonnelId(int $personnelId): ?int
    {
        $personnelId = (int)$personnelId;
        if ($personnelId <= 0) {
            return null;
        }

        try {
            $stmt = $this->pdo->prepare('SELECT id FROM user_devis_banamur WHERE active = 1 AND personnel_id = :pid LIMIT 1');
            $stmt->execute(['pid' => $personnelId]);
            $id = $stmt->fetchColumn();
            if ($id === false) {
                return null;
            }
            $uid = (int)$id;
            return $uid > 0 ? $uid : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function normalizeAssignment(?string $assignmentType, ?int $chantierId): array
    {
        $type = strtolower(trim((string)$assignmentType));
        if (!in_array($type, ['bureau', 'chantier'], true)) {
            $type = 'bureau';
        }

        $cid = $type === 'chantier' ? max(0, (int)$chantierId) : 0;
        return [
            'type' => $type,
            'chantier_id' => $cid > 0 ? $cid : null,
        ];
    }

    /**
     * Calcule la prochaine occurrence strictement après $after.
     *
     * NOTE: "exclure" = on saute l'occurrence, on ne décale pas la périodicité.
     *
     * @param array<int,int> $weekDays ISO 1..7
     */
    private function computeNextOccurrenceAfter(
        DateTimeImmutable $after,
        string $patternType,
        int $intervalN,
        array $weekDays,
        ?int $monthDay,
        string $timeOfDay,
        bool $excludeWeekends,
        bool $excludeHolidays,
        string $startDate,
        ?string $endDate
    ): ?DateTimeImmutable {
        $patternType = strtolower(trim($patternType));
        if (!in_array($patternType, ['daily', 'weekly', 'monthly'], true)) {
            return null;
        }
        $intervalN = max(1, (int)$intervalN);

        $baseStart = DateTimeImmutable::createFromFormat('Y-m-d', $startDate) ?: $after;

        // On part du lendemain/minute suivante selon le pattern
        $candidate = $after;

        $timeParts = explode(':', $timeOfDay);
        $hh = isset($timeParts[0]) ? (int)$timeParts[0] : 9;
        $mm = isset($timeParts[1]) ? (int)$timeParts[1] : 0;
        $ss = isset($timeParts[2]) ? (int)$timeParts[2] : 0;

        $maxIterations = 2000;
        for ($i = 0; $i < $maxIterations; $i++) {
            if ($patternType === 'daily') {
                $candidate = $candidate->modify('+' . $intervalN . ' days')->setTime($hh, $mm, $ss);
            } elseif ($patternType === 'weekly') {
                // Avancer jour par jour jusqu'à un weekday autorisé et une semaine respectant interval
                $candidate = $candidate->modify('+1 day')->setTime($hh, $mm, $ss);
                $wd = (int)$candidate->format('N');
                $weekOk = true;
                if ($intervalN > 1) {
                    $diffDays = (int)floor(($candidate->setTime(0, 0)->getTimestamp() - $baseStart->setTime(0, 0)->getTimestamp()) / 86400);
                    $weekIndex = (int)floor($diffDays / 7);
                    $weekOk = ($weekIndex % $intervalN) === 0;
                }
                if ($weekDays && (!in_array($wd, $weekDays, true) || !$weekOk)) {
                    continue;
                }
                if (!$weekOk) {
                    continue;
                }
            } else {
                // monthly
                $candidate = $candidate->modify('first day of this month')->setTime($hh, $mm, $ss);
                $candidate = $candidate->modify('+' . $intervalN . ' months');
                $day = $monthDay !== null ? (int)$monthDay : (int)$candidate->format('j');
                if ($day < 1) {
                    $day = 1;
                }
                if ($day > 31) {
                    $day = 31;
                }

                $lastDay = (int)$candidate->format('t');
                if ($day > $lastDay) {
                    $day = $lastDay;
                }
                $candidate = $candidate->setDate((int)$candidate->format('Y'), (int)$candidate->format('m'), $day);
            }

            // bornage end_date
            if ($endDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
                $endDt = DateTimeImmutable::createFromFormat('Y-m-d', $endDate);
                if ($endDt && $candidate->format('Y-m-d') > $endDt->format('Y-m-d')) {
                    return null;
                }
            }

            // Note: les exclusions (week-end / fériés) sont gérées lors de la génération
            // (on saute l'occurrence), pour éviter les requêtes DB ici.

            // Assurer strictement après
            if ($candidate <= $after) {
                continue;
            }

            return $candidate;
        }

        return null;
    }
}
