<?php

require_once dirname(__DIR__, 2) . '/model/RhAbsenceNotifier.php';

class PayrollManager
{
    private PDO $pdo;
    private ?array $contractPresetCache = null;

    private const DEBT_TYPE_ADVANCE = 'advance';
    private const DEBT_TYPE_LOAN = 'loan';

    private array $attendanceStatuses = [
        'present' => ['label' => 'Present', 'default_days' => 1.0],
        'half_day' => ['label' => 'Demi-journee', 'default_days' => 0.5],
        'mission' => ['label' => 'Mission', 'default_days' => 1.0],
        'conge' => ['label' => 'Conge', 'default_days' => 1.0],
        'repos' => ['label' => 'Repos', 'default_days' => 0.0],
        'absence' => ['label' => 'Absent', 'default_days' => 0.0],
        'maladie' => ['label' => 'Maladie', 'default_days' => 0.0],
    ];

    private array $contractLabels = [
        'CDD' => 'CDD',
        'CDI' => 'CDI',
        'STAGE' => 'STAGE DE PERFECTIONNEMENT',
        'VACATAIRE' => 'VACATAIRE',
        'AUTRE' => 'AUTRE',
    ];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureSupportTables();
    }
    public function getReferenceWorkingDaysForPeriod(string $period): int
    {
        [$startDate, $endDate] = $this->getPeriodBounds($period);
        return $this->countWorkingDaysBetween($startDate, $endDate);
    }

    public function listWorkingDatesForPeriod(string $period): array
    {
        [$startDate, $endDate] = $this->getPeriodBounds($period);
        return $this->listWorkingDatesBetween($startDate, $endDate);
    }

    public function getAttendanceStatuses(): array
    {
        return $this->attendanceStatuses;
    }

    public function getContractPresets(): array
    {
        if ($this->contractPresetCache !== null) {
            return $this->contractPresetCache;
        }

        $defaults = $this->getDefaultContractPresets();
        $this->seedContractRuleDefaults($defaults);

        $stmt = $this->pdo->query('SELECT * FROM rh_payroll_contract_rules WHERE is_active = 1 ORDER BY contract_label ASC, contract_code ASC');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $row) {
            $contractCode = $this->normalizeContractCode((string) ($row['contract_code'] ?? 'AUTRE'), false);
            $baseRule = $defaults[$contractCode] ?? $defaults['AUTRE'];

            $defaults[$contractCode] = array_merge($baseRule, [
                'label' => $this->normalizeNullableString($row['contract_label'] ?? null) ?? (string) ($baseRule['label'] ?? $contractCode),
                'reference_working_days' => $this->normalizeDecimal($row['reference_working_days'] ?? $baseRule['reference_working_days']),
                'hours_per_day' => $this->normalizeDecimal($row['hours_per_day'] ?? $baseRule['hours_per_day']),
                'overtime_multiplier' => $this->normalizeDecimal($row['overtime_multiplier'] ?? $baseRule['overtime_multiplier']),
                'mission_paid_rate' => $this->normalizeRuleRate($row['mission_paid_rate'] ?? $baseRule['mission_paid_rate']),
                'conge_paid_rate' => $this->normalizeRuleRate($row['conge_paid_rate'] ?? $baseRule['conge_paid_rate']),
                'half_day_paid_rate' => $this->normalizeRuleRate($row['half_day_paid_rate'] ?? $baseRule['half_day_paid_rate']),
                'absence_paid_rate' => $this->normalizeRuleRate($row['absence_paid_rate'] ?? $baseRule['absence_paid_rate']),
                'maladie_paid_rate' => $this->normalizeRuleRate($row['maladie_paid_rate'] ?? $baseRule['maladie_paid_rate']),
                'repos_paid_rate' => $this->normalizeRuleRate($row['repos_paid_rate'] ?? $baseRule['repos_paid_rate']),
                'precarity_rate' => $this->normalizeDecimal($row['precarity_rate'] ?? $baseRule['precarity_rate']),
            ]);
        }

        $this->contractPresetCache = $defaults;
        return $this->contractPresetCache;
    }

    public function getContractRules(string $contractCode): array
    {
        $contractCode = $this->normalizeContractCode($contractCode);
        $presets = $this->getContractPresets();
        return $presets[$contractCode] ?? $presets['AUTRE'];
    }

    public function saveContractRules(array $rules, array $actor = []): int
    {
        $saved = 0;
        $defaults = $this->getDefaultContractPresets();
        $sql = '
            INSERT INTO rh_payroll_contract_rules (
                contract_code,
                contract_label,
                reference_working_days,
                hours_per_day,
                overtime_multiplier,
                mission_paid_rate,
                conge_paid_rate,
                half_day_paid_rate,
                absence_paid_rate,
                maladie_paid_rate,
                repos_paid_rate,
                precarity_rate,
                is_active,
                updated_by_user_id,
                updated_by_name
            ) VALUES (
                :contract_code,
                :contract_label,
                :reference_working_days,
                :hours_per_day,
                :overtime_multiplier,
                :mission_paid_rate,
                :conge_paid_rate,
                :half_day_paid_rate,
                :absence_paid_rate,
                :maladie_paid_rate,
                :repos_paid_rate,
                :precarity_rate,
                :is_active,
                :updated_by_user_id,
                :updated_by_name
            )
            ON DUPLICATE KEY UPDATE
                contract_label = VALUES(contract_label),
                reference_working_days = VALUES(reference_working_days),
                hours_per_day = VALUES(hours_per_day),
                overtime_multiplier = VALUES(overtime_multiplier),
                mission_paid_rate = VALUES(mission_paid_rate),
                conge_paid_rate = VALUES(conge_paid_rate),
                half_day_paid_rate = VALUES(half_day_paid_rate),
                absence_paid_rate = VALUES(absence_paid_rate),
                maladie_paid_rate = VALUES(maladie_paid_rate),
                repos_paid_rate = VALUES(repos_paid_rate),
                precarity_rate = VALUES(precarity_rate),
                is_active = VALUES(is_active),
                updated_by_user_id = VALUES(updated_by_user_id),
                updated_by_name = VALUES(updated_by_name),
                updated_at = CURRENT_TIMESTAMP
        ';

        $stmt = $this->pdo->prepare($sql);
        foreach ($rules as $contractCode => $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $label = trim((string) ($rule['label'] ?? ''));
            $contractCode = $this->normalizeContractCode((string) ($rule['contract_code'] ?? $contractCode), false);
            if ($label === '' && !isset($defaults[$contractCode])) {
                continue;
            }
            if ($label === '') {
                $label = (string) ($defaults[$contractCode]['label'] ?? $contractCode);
            }
            if ($contractCode === '' || $contractCode === 'NEW' || $contractCode === '__NEW__') {
                $contractCode = $this->generateContractCode($label);
            }
            $defaultRule = $defaults[$contractCode] ?? $defaults['AUTRE'];
            $stmt->bindValue(':contract_code', $contractCode);
            $stmt->bindValue(':contract_label', $label);
            $stmt->bindValue(':reference_working_days', max(1.0, $this->normalizeDecimal($rule['reference_working_days'] ?? $defaultRule['reference_working_days'])));
            $stmt->bindValue(':hours_per_day', max(1.0, $this->normalizeDecimal($rule['hours_per_day'] ?? $defaultRule['hours_per_day'])));
            $stmt->bindValue(':overtime_multiplier', max(0.0, $this->normalizeDecimal($rule['overtime_multiplier'] ?? $defaultRule['overtime_multiplier'])));
            $stmt->bindValue(':mission_paid_rate', $this->normalizeRuleRate($rule['mission_paid_rate'] ?? $defaultRule['mission_paid_rate']));
            $stmt->bindValue(':conge_paid_rate', $this->normalizeRuleRate($rule['conge_paid_rate'] ?? $defaultRule['conge_paid_rate']));
            $stmt->bindValue(':half_day_paid_rate', $this->normalizeRuleRate($rule['half_day_paid_rate'] ?? $defaultRule['half_day_paid_rate']));
            $stmt->bindValue(':absence_paid_rate', $this->normalizeRuleRate($rule['absence_paid_rate'] ?? $defaultRule['absence_paid_rate']));
            $stmt->bindValue(':maladie_paid_rate', $this->normalizeRuleRate($rule['maladie_paid_rate'] ?? $defaultRule['maladie_paid_rate']));
            $stmt->bindValue(':repos_paid_rate', $this->normalizeRuleRate($rule['repos_paid_rate'] ?? $defaultRule['repos_paid_rate']));
            $stmt->bindValue(':precarity_rate', max(0.0, $this->normalizeDecimal($rule['precarity_rate'] ?? $defaultRule['precarity_rate'])));
            $stmt->bindValue(':is_active', !empty($rule['is_active']) ? 1 : 0, PDO::PARAM_INT);
            $stmt->bindValue(':updated_by_user_id', $actor['user_id'] ?? null, isset($actor['user_id']) ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $updatedByName = $this->normalizeNullableString($actor['user_name'] ?? null);
            $stmt->bindValue(':updated_by_name', $updatedByName, $updatedByName !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->execute();
            $saved++;
        }

        $this->contractPresetCache = null;
        return $saved;
    }

    private function getDefaultContractPresets(): array
    {
        return [
            'CDD' => [
                'label' => 'CDD',
                'reference_working_days' => 30.0,
                'hours_per_day' => 8.0,
                'overtime_multiplier' => 1.15,
                'mission_paid_rate' => 1.0,
                'conge_paid_rate' => 1.0,
                'half_day_paid_rate' => 0.5,
                'absence_paid_rate' => 0.0,
                'maladie_paid_rate' => 0.5,
                'repos_paid_rate' => 0.0,
                'precarity_rate' => 3.0,
            ],
            'CDI' => [
                'label' => 'CDI permanent',
                'reference_working_days' => 26.0,
                'hours_per_day' => 8.0,
                'overtime_multiplier' => 1.25,
                'mission_paid_rate' => 1.0,
                'conge_paid_rate' => 1.0,
                'half_day_paid_rate' => 0.5,
                'absence_paid_rate' => 0.0,
                'maladie_paid_rate' => 0.75,
                'repos_paid_rate' => 1.0,
                'precarity_rate' => 0.0,
            ],
            'STAGE' => [
                'label' => 'Stage de perfectionnement',
                'reference_working_days' => 22.0,
                'hours_per_day' => 7.0,
                'overtime_multiplier' => 0.0,
                'mission_paid_rate' => 1.0,
                'conge_paid_rate' => 0.5,
                'half_day_paid_rate' => 0.5,
                'absence_paid_rate' => 0.0,
                'maladie_paid_rate' => 0.0,
                'repos_paid_rate' => 0.0,
                'precarity_rate' => 0.0,
            ],
            'VACATAIRE' => [
                'label' => 'Vacataire',
                'reference_working_days' => 26.0,
                'hours_per_day' => 8.0,
                'overtime_multiplier' => 1.0,
                'mission_paid_rate' => 1.0,
                'conge_paid_rate' => 0.0,
                'half_day_paid_rate' => 0.5,
                'absence_paid_rate' => 0.0,
                'maladie_paid_rate' => 0.0,
                'repos_paid_rate' => 0.0,
                'precarity_rate' => 0.0,
            ],
            'AUTRE' => [
                'label' => 'Parametrage libre',
                'reference_working_days' => 30.0,
                'hours_per_day' => 8.0,
                'overtime_multiplier' => 1.0,
                'mission_paid_rate' => 1.0,
                'conge_paid_rate' => 1.0,
                'half_day_paid_rate' => 0.5,
                'absence_paid_rate' => 0.0,
                'maladie_paid_rate' => 0.0,
                'repos_paid_rate' => 0.0,
                'precarity_rate' => 0.0,
            ],
        ];
    }

    public function getEmployeeOptions(bool $activeOnly = true, bool $includePointageExempt = true): array
    {
        if (!$includePointageExempt) {
            try {
                new \RhAbsenceNotifier($this->pdo);
            } catch (Throwable) {
                // La liste reste disponible meme si la table d'exemption n'a pas pu etre preparee.
            }
        }

        $sql = "
            SELECT
                p.id_personnel_soignant AS id,
                p.matricule_personnel_soignant AS matricule,
                p.nom_personnel_soignant AS full_name,
                p.email_personnel_soignant AS email,
                p.num_cnps AS cnps_number,
                p.statut_pers_soignant_code AS status_code,
                p.date_recrutement AS hire_date,
                p.date_prise_service AS start_date,
                p.date_sortie AS end_date,
                COALESCE(NULLIF(TRIM(s.lib_service), ''), 'Service non renseigne') AS service_label,
                COALESCE(NULLIF(TRIM(f.lib_fonction), ''), 'Fonction non renseignee') AS fonction_label,
                CASE
                    WHEN p.statut_pers_soignant_code = 1 THEN 'CDD'
                    WHEN p.statut_pers_soignant_code = 3 THEN 'Vacataire'
                    WHEN p.statut_pers_soignant_code = 4 THEN 'Stage de perfectionnement'
                    ELSE CONCAT('Statut ', COALESCE(p.statut_pers_soignant_code, 0))
                END AS statut_label
            FROM personnel p
            LEFT JOIN service s ON s.id_service = p.service_id
            LEFT JOIN fonction f ON f.id_fonction = p.fonction_id
        ";
        if (!$includePointageExempt) {
            $sql .= ' LEFT JOIN rh_pointage_exemptions pex ON pex.personnel_id = p.id_personnel_soignant AND pex.is_active = 1 ';
        }

        $where = [];
        if ($activeOnly) {
            $where[] = "(p.date_sortie IS NULL OR p.date_sortie = '0000-00-00')";
        }
        if (!$includePointageExempt) {
            $where[] = 'pex.id IS NULL';
        }
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY p.nom_personnel_soignant ASC';
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getAttendanceBoard(string $date): array
    {
        $date = $this->normalizeDate($date) ?? date('Y-m-d');
        $employees = $this->getEmployeeOptions(true, false);

        $holidayDates = $this->getHolidayDatesBetween($date, $date);
        $isWorkingDay = $this->isWorkingDate($date, $holidayDates);

        $stmt = $this->pdo->prepare(
            'SELECT * FROM rh_attendance_daily WHERE attendance_date = :attendance_date ORDER BY personnel_id ASC'
        );
        $stmt->bindValue(':attendance_date', $date);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $indexedRows = [];
        foreach ($rows as $row) {
            $indexedRows[(int) $row['personnel_id']] = $row;
        }
        $badgeusePersonnelIds = $this->getBadgeusePersonnelIdsForDate($date, $employees);

        $items = [];
        $stats = array_fill_keys(array_keys($this->attendanceStatuses), 0);
        foreach ($employees as $employee) {
            $personnelId = (int) $employee['id'];
            $existing = $indexedRows[$personnelId] ?? null;
            $status = $this->resolveAttendanceStatusForDate($date, $existing, $holidayDates);
            $storedStatus = (string) ($existing['attendance_status'] ?? '');
            $useStoredWorkedDays = $existing !== null && $isWorkingDay && $storedStatus !== 'repos';

            $stats[$status]++;
            $items[] = [
                'employee' => $employee,
                'attendance' => [
                    'attendance_status' => $status,
                    'worked_days' => !$isWorkingDay ? 0.0 : ($useStoredWorkedDays && isset($existing['worked_days']) ? (float) $existing['worked_days'] : (float) $this->attendanceStatuses[$status]['default_days']),
                    'overtime_hours' => $existing !== null ? $this->resolveAttendanceOvertimeHours($existing, $isWorkingDay) : 0.0,
                    'check_in_time' => $existing['check_in_time'] ?? '',
                    'check_out_time' => $existing['check_out_time'] ?? '',
                    'notes' => $existing['notes'] ?? '',
                    'recorded_by_user_id' => isset($existing['recorded_by_user_id']) ? (int) $existing['recorded_by_user_id'] : 0,
                    'recorded_by_name' => (string) ($existing['recorded_by_name'] ?? ''),
                    'created_at' => (string) ($existing['created_at'] ?? ''),
                    'updated_at' => (string) ($existing['updated_at'] ?? ''),
                    'has_saved_row' => $existing !== null,
                    'badgeuse_has_event' => isset($badgeusePersonnelIds[$personnelId]),
                    'is_working_date' => $isWorkingDay,
                ],
            ];
        }

        return [
            'date' => $date,
            'is_working_day' => $isWorkingDay,
            'is_rest_day' => !$isWorkingDay,
            'items' => $items,
            'stats' => $stats,
            'total' => count($items),
        ];
    }

    private function getBadgeusePersonnelIdsForDate(string $date, array $employees): array
    {
        $codeToPersonnel = [];
        foreach ($employees as $employee) {
            $personnelId = (int) ($employee['id'] ?? 0);
            $matricule = (string) ($employee['matricule'] ?? '');
            if ($personnelId <= 0 || trim($matricule) === '') {
                continue;
            }

            foreach ($this->attendanceCodeAliases($matricule) as $alias) {
                $codeToPersonnel[$alias] = $personnelId;
            }
        }
        try {
            $linkStmt = $this->pdo->query('SELECT employee_code, personnel_id FROM attendance_personnel_links');
            foreach (($linkStmt ? $linkStmt->fetchAll(PDO::FETCH_ASSOC) : []) ?: [] as $row) {
                $personnelId = (int) ($row['personnel_id'] ?? 0);
                if ($personnelId <= 0) {
                    continue;
                }
                foreach ($this->attendanceCodeAliases((string) ($row['employee_code'] ?? '')) as $alias) {
                    $codeToPersonnel[$alias] = $personnelId;
                }
            }
        } catch (Throwable $e) {
            // Les liens explicites badgeuse restent optionnels.
        }

        if (!$codeToPersonnel) {
            return [];
        }

        try {
            $stmt = $this->pdo->prepare('
                SELECT DISTINCT employee_code
                FROM attendance_events
                WHERE occurred_on = :attendance_date
                  AND employee_code IS NOT NULL
                  AND TRIM(employee_code) <> ""
            ');
            $stmt->execute([':attendance_date' => $date]);
        } catch (Throwable $e) {
            return [];
        }

        $personnelIds = [];
        foreach (($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []) as $employeeCode) {
            foreach ($this->attendanceCodeAliases((string) $employeeCode) as $alias) {
                $personnelId = (int) ($codeToPersonnel[$alias] ?? 0);
                if ($personnelId > 0) {
                    $personnelIds[$personnelId] = true;
                }
            }
        }

        return $personnelIds;
    }

    private function attendanceCodeAliases(string $value): array
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
            if (preg_match('/(\d+)$/', $alias, $matches)) {
                $number = ltrim($matches[1], '0');
                if ($number !== '') {
                    $aliases[$number] = true;
                }
            }
        }

        return array_keys($aliases);
    }

    public function getMonthlyAttendanceBoard(string $period, bool $activeOnly = true, ?string $customStartDate = null, ?string $customEndDate = null): array
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
            $period = date('Y-m');
        }

        [$startDate, $endDate] = $this->resolveAttendanceRange($period, $customStartDate, $customEndDate);
        $employees = $this->getEmployeeOptions($activeOnly, false);

        $indexed = [];
        foreach ($employees as $employee) {
            $indexed[(int) $employee['id']] = [
                'employee' => $employee,
                'summary' => [
                    'period' => $period,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'entries_count' => 0,
                    'worked_days' => 0.0,
                    'overtime_hours' => 0.0,
                    'status_counts' => array_fill_keys(array_keys($this->attendanceStatuses), 0),
                    'status_days' => array_fill_keys(array_keys($this->attendanceStatuses), 0.0),
                ],
            ];
        }

        $holidayDates = $this->getHolidayDatesBetween($startDate, $endDate);

        $stmt = $this->pdo->prepare(
            'SELECT personnel_id, attendance_date, attendance_status, worked_days, overtime_hours, check_in_time, check_out_time FROM rh_attendance_daily WHERE attendance_date BETWEEN :start_date AND :end_date ORDER BY personnel_id ASC, attendance_date ASC'
        );
        $stmt->bindValue(':start_date', $startDate);
        $stmt->bindValue(':end_date', $endDate);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            $personnelId = (int) ($row['personnel_id'] ?? 0);
            if ($personnelId <= 0 || !isset($indexed[$personnelId])) {
                continue;
            }

            $date = $this->normalizeDate((string) ($row['attendance_date'] ?? ''));
            $status = $date !== null ? $this->resolveAttendanceStatusForDate($date, $row, $holidayDates) : 'present';

            $storedStatus = (string) ($row['attendance_status'] ?? '');
            $isWorkingDate = $date !== null ? $this->isWorkingDate($date, $holidayDates) : false;
            $workedDays = !$isWorkingDate ? 0.0 : (($storedStatus === 'repos') ? (float) $this->attendanceStatuses[$status]['default_days'] : (float) ($row['worked_days'] ?? 0));
            $overtimeHours = $this->resolveAttendanceOvertimeHours($row, $isWorkingDate);

            $indexed[$personnelId]['summary']['entries_count']++;
            $indexed[$personnelId]['summary']['worked_days'] += $workedDays;
            $indexed[$personnelId]['summary']['overtime_hours'] += $overtimeHours;
            $indexed[$personnelId]['summary']['status_counts'][$status]++;
            $indexed[$personnelId]['summary']['status_days'][$status] += $workedDays;
        }

        $totals = [
            'employees' => count($employees),
            'worked_days' => 0.0,
            'overtime_hours' => 0.0,
            'status_days' => array_fill_keys(array_keys($this->attendanceStatuses), 0.0),
        ];

        foreach ($indexed as &$item) {
            $item['summary']['worked_days'] = round((float) $item['summary']['worked_days'], 2);
            $item['summary']['overtime_hours'] = round((float) $item['summary']['overtime_hours'], 2);
            foreach ($item['summary']['status_days'] as $statusKey => $value) {
                $item['summary']['status_days'][$statusKey] = round((float) $value, 2);
            }

            $totals['worked_days'] += (float) $item['summary']['worked_days'];
            $totals['overtime_hours'] += (float) $item['summary']['overtime_hours'];
            foreach ($item['summary']['status_days'] as $statusKey => $value) {
                $totals['status_days'][$statusKey] += (float) $value;
            }
        }
        unset($item);

        $totals['worked_days'] = round((float) $totals['worked_days'], 2);
        $totals['overtime_hours'] = round((float) $totals['overtime_hours'], 2);
        foreach ($totals['status_days'] as $statusKey => $value) {
            $totals['status_days'][$statusKey] = round((float) $value, 2);
        }

        return [
            'period' => $period,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'items' => array_values($indexed),
            'total' => $totals,
        ];
    }

    public function getMonthlyAttendanceMatrix(string $period, bool $activeOnly = true, ?string $customStartDate = null, ?string $customEndDate = null): array
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
            $period = date('Y-m');
        }

        [$startDate, $endDate] = $this->resolveAttendanceRange($period, $customStartDate, $customEndDate);
        $employees = $this->getEmployeeOptions($activeOnly, false);

        $days = [];
        $cursor = new DateTimeImmutable($startDate);
        $end = new DateTimeImmutable($endDate);
        while ($cursor <= $end) {
            $days[] = $cursor->format('Y-m-d');
            $cursor = $cursor->modify('+1 day');
        }

        $items = [];
        $indexByPersonnel = [];

        foreach ($employees as $employee) {
            $personnelId = (int) ($employee['id'] ?? 0);
            if ($personnelId <= 0) {
                continue;
            }

            $contractCode = $this->normalizeContractCode($this->resolveContractCode($employee));
            $rules = $this->getContractRules($contractCode);
            $hoursPerDay = (float) ($rules['hours_per_day'] ?? 8.0);
            if ($hoursPerDay <= 0) {
                $hoursPerDay = 8.0;
            }

            $indexByPersonnel[$personnelId] = count($items);
            $items[] = [
                'employee' => $employee,
                'contract_code' => $contractCode,
                'hours_per_day' => round($hoursPerDay, 2),
                'days' => [],
                'totals' => [
                    'worked_days' => 0.0,
                    'work_hours' => 0.0,
                    'overtime_hours' => 0.0,
                ],
            ];
        }

        $holidayDates = $this->getHolidayDatesBetween($startDate, $endDate);

        $stmt = $this->pdo->prepare(
            'SELECT personnel_id, attendance_date, attendance_status, worked_days, overtime_hours, check_in_time, check_out_time, notes FROM rh_attendance_daily WHERE attendance_date BETWEEN :start_date AND :end_date ORDER BY personnel_id ASC, attendance_date ASC'
        );
        $stmt->bindValue(':start_date', $startDate);
        $stmt->bindValue(':end_date', $endDate);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            $personnelId = (int) ($row['personnel_id'] ?? 0);
            if ($personnelId <= 0 || !isset($indexByPersonnel[$personnelId])) {
                continue;
            }

            $date = $this->normalizeDate((string) ($row['attendance_date'] ?? ''));
            if ($date === null) {
                continue;
            }

            $status = $this->resolveAttendanceStatusForDate($date, $row, $holidayDates);

            $storedStatus = (string) ($row['attendance_status'] ?? '');
            $isWorkingDate = $this->isWorkingDate($date, $holidayDates);
            $workedDays = !$isWorkingDate ? 0.0 : (($storedStatus === 'repos') ? (float) $this->attendanceStatuses[$status]['default_days'] : (isset($row['worked_days']) ? (float) $row['worked_days'] : (float) $this->attendanceStatuses[$status]['default_days']));
            $overtimeHours = $this->resolveAttendanceOvertimeHours($row, $isWorkingDate);
            $checkIn = $this->normalizeTime($row['check_in_time'] ?? null);
            $checkOut = $this->normalizeTime($row['check_out_time'] ?? null);

            $idx = (int) $indexByPersonnel[$personnelId];
            $items[$idx]['days'][$date] = [
                'attendance_status' => $status,
                'worked_days' => round($workedDays, 2),
                'overtime_hours' => round($overtimeHours, 2),
                'check_in_time' => $checkIn,
                'check_out_time' => $checkOut,
                'notes' => $row['notes'] ?? null,
            ];
        }

        $globalTotals = [
            'employees' => count($items),
            'worked_days' => 0.0,
            'work_hours' => 0.0,
            'overtime_hours' => 0.0,
        ];

        foreach ($items as &$item) {
            $hoursPerDay = (float) ($item['hours_per_day'] ?? 8.0);
            $totalDays = 0.0;
            $totalHours = 0.0;
            $totalOvertime = 0.0;

            foreach ($item['days'] as $date => $attendance) {
                $rowDays = (float) ($attendance['worked_days'] ?? 0);
                $rowOvertime = (float) ($attendance['overtime_hours'] ?? 0);
                $totalDays += $rowDays;
                $totalOvertime += $rowOvertime;

                $checkIn = (string) ($attendance['check_in_time'] ?? '');
                $checkOut = (string) ($attendance['check_out_time'] ?? '');

                $rowHours = 0.0;
                if ($checkIn !== '' && $checkOut !== '') {
                    $startTs = strtotime('1970-01-01 ' . $checkIn);
                    $endTs = strtotime('1970-01-01 ' . $checkOut);
                    if (is_int($startTs) && is_int($endTs)) {
                        if ($endTs < $startTs) {
                            $endTs += 86400;
                        }
                        $rowHours = ($endTs - $startTs) / 3600;
                    }
                }

                if ($rowHours <= 0) {
                    $rowHours = $rowDays * $hoursPerDay;
                }
                $totalHours += $rowHours;
            }

            $item['totals']['worked_days'] = round($totalDays, 2);
            $item['totals']['work_hours'] = round($totalHours, 2);
            $item['totals']['overtime_hours'] = round($totalOvertime, 2);

            $globalTotals['worked_days'] += (float) $item['totals']['worked_days'];
            $globalTotals['work_hours'] += (float) $item['totals']['work_hours'];
            $globalTotals['overtime_hours'] += (float) $item['totals']['overtime_hours'];
        }
        unset($item);

        $globalTotals['worked_days'] = round((float) $globalTotals['worked_days'], 2);
        $globalTotals['work_hours'] = round((float) $globalTotals['work_hours'], 2);
        $globalTotals['overtime_hours'] = round((float) $globalTotals['overtime_hours'], 2);

        return [
            'period' => $period,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'days' => $days,
            'items' => $items,
            'total' => $globalTotals,
        ];
    }

    private function resolveAttendanceRange(string $period, ?string $customStartDate, ?string $customEndDate): array
    {
        [$defaultStart, $defaultEnd] = $this->getPeriodBounds($period);
        $startDate = $this->normalizeDate((string) $customStartDate) ?? $defaultStart;
        $endDate = $this->normalizeDate((string) $customEndDate) ?? $defaultEnd;
        if ($endDate < $startDate) {
            return [$defaultStart, $defaultEnd];
        }
        return [$startDate, $endDate];
    }

    public function saveDailyAttendance(string $date, array $entries, array $actor = []): int
    {
        $date = $this->normalizeDate($date);
        if ($date === null) {
            throw new RuntimeException('La date de pointage est invalide.');
        }

        $holidayDates = $this->getHolidayDatesBetween($date, $date);
        $isWorkingDate = $this->isWorkingDate($date, $holidayDates);

        $saved = 0;
        $sql = '
            INSERT INTO rh_attendance_daily (
                personnel_id,
                attendance_date,
                attendance_status,
                worked_days,
                overtime_hours,
                check_in_time,
                check_out_time,
                notes,
                recorded_by_user_id,
                recorded_by_name
            ) VALUES (
                :personnel_id,
                :attendance_date,
                :attendance_status,
                :worked_days,
                :overtime_hours,
                :check_in_time,
                :check_out_time,
                :notes,
                :recorded_by_user_id,
                :recorded_by_name
            )
            ON DUPLICATE KEY UPDATE
                attendance_status = VALUES(attendance_status),
                worked_days = VALUES(worked_days),
                overtime_hours = VALUES(overtime_hours),
                check_in_time = CASE
                    WHEN check_in_time IS NULL THEN VALUES(check_in_time)
                    WHEN VALUES(check_in_time) IS NULL THEN check_in_time
                    WHEN VALUES(check_in_time) < check_in_time THEN VALUES(check_in_time)
                    ELSE check_in_time
                END,
                check_out_time = CASE
                    WHEN check_out_time IS NULL THEN VALUES(check_out_time)
                    WHEN VALUES(check_out_time) IS NULL THEN check_out_time
                    WHEN VALUES(check_out_time) > check_out_time THEN VALUES(check_out_time)
                    ELSE check_out_time
                END,
                notes = VALUES(notes),
                recorded_by_user_id = VALUES(recorded_by_user_id),
                recorded_by_name = VALUES(recorded_by_name),
                updated_at = CURRENT_TIMESTAMP
        ';

        $stmt = $this->pdo->prepare($sql);
        foreach ($entries as $personnelId => $entry) {
            $personnelId = (int) $personnelId;
            if ($personnelId <= 0 || !is_array($entry)) {
                continue;
            }

            $submittedStatus = (string) ($entry['status'] ?? 'present');
            $status = $submittedStatus;
            if (!isset($this->attendanceStatuses[$status])) {
                $status = 'present';
            }

            $checkInTime = $this->normalizeTime($entry['check_in_time'] ?? null);
            $checkOutTime = $this->normalizeTime($entry['check_out_time'] ?? null);
            $notes = $this->normalizeNullableString($entry['notes'] ?? null);

            if (!$isWorkingDate) {
                $status = 'repos';
            } elseif ($status === 'repos') {
                $status = ($checkInTime !== null || $checkOutTime !== null) ? 'present' : 'absence';
            }

            $workedDays = $this->normalizeDecimal($entry['worked_days'] ?? $this->attendanceStatuses[$status]['default_days']);
            $overtimeHours = $this->normalizeDecimal($entry['overtime_hours'] ?? 0);
            if (!$isWorkingDate || $submittedStatus === 'repos') {
                $workedDays = (float) $this->attendanceStatuses[$status]['default_days'];
            }
            if (!$isWorkingDate) {
                $overtimeHours = 0.0;
            }

            $stmt->bindValue(':personnel_id', $personnelId, PDO::PARAM_INT);
            $stmt->bindValue(':attendance_date', $date);
            $stmt->bindValue(':attendance_status', $status);
            $stmt->bindValue(':worked_days', $workedDays);
            $stmt->bindValue(':overtime_hours', $overtimeHours);
            $stmt->bindValue(':check_in_time', $checkInTime, $checkInTime !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindValue(':check_out_time', $checkOutTime, $checkOutTime !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindValue(':notes', $notes, $notes !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindValue(':recorded_by_user_id', $actor['user_id'] ?? null, isset($actor['user_id']) ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $recordedByName = $this->normalizeNullableString($actor['user_name'] ?? null);
            $stmt->bindValue(':recorded_by_name', $recordedByName, $recordedByName !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->execute();
            if ($status === 'absence') {
                try {
                    (new \RhAbsenceNotifier($this->pdo))->notifyAbsenceMarked($personnelId, $date, $actor);
                } catch (Throwable) {
                    // La sauvegarde du pointage ne doit pas echouer a cause d'un email.
                }
            }
            $saved++;
        }

        return $saved;
    }

    public function repairAttendanceCalendarStatuses(string $period, array $actor = []): array
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
            throw new RuntimeException('La periode de correction est invalide.');
        }

        [$startDate, $endDate] = $this->getPeriodBounds($period);
        $holidayDates = $this->getHolidayDatesBetween($startDate, $endDate);

        $select = $this->pdo->prepare('
            SELECT id, attendance_date, attendance_status, worked_days, overtime_hours, check_in_time, check_out_time
            FROM rh_attendance_daily
            WHERE attendance_date BETWEEN :start_date AND :end_date
            ORDER BY attendance_date ASC, personnel_id ASC
        ');
        $select->bindValue(':start_date', $startDate);
        $select->bindValue(':end_date', $endDate);
        $select->execute();

        $update = $this->pdo->prepare('
            UPDATE rh_attendance_daily
            SET attendance_status = :attendance_status,
                worked_days = :worked_days,
                overtime_hours = :overtime_hours,
                recorded_by_user_id = :recorded_by_user_id,
                recorded_by_name = :recorded_by_name,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ');

        $checked = 0;
        $fixed = 0;
        $toPresent = 0;
        $toAbsence = 0;
        $toRepos = 0;
        $recordedByName = $this->normalizeNullableString($actor['user_name'] ?? null);

        foreach (($select->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
            $checked++;
            $date = $this->normalizeDate((string) ($row['attendance_date'] ?? ''));
            if ($date === null) {
                continue;
            }

            $isWorkingDate = $this->isWorkingDate($date, $holidayDates);
            $currentStatus = (string) ($row['attendance_status'] ?? '');
            $targetStatus = $currentStatus;
            $targetWorkedDays = (float) ($row['worked_days'] ?? 0);
            $targetOvertimeHours = (float) ($row['overtime_hours'] ?? 0);

            if (!$isWorkingDate) {
                $targetStatus = $this->hasAttendanceTimes($row) ? 'present' : 'repos';
                $targetWorkedDays = 0.0;
                $targetOvertimeHours = $this->resolveAttendanceOvertimeHours($row, false);
            } elseif ($currentStatus === 'repos') {
                if (!$this->hasAttendanceActivity($row)) {
                    continue;
                }

                $targetStatus = 'present';
                $targetWorkedDays = max((float) ($row['worked_days'] ?? 0), (float) $this->attendanceStatuses[$targetStatus]['default_days']);
            }

            if (
                $targetStatus === $currentStatus
                && abs($targetWorkedDays - (float) ($row['worked_days'] ?? 0)) < 0.001
                && abs($targetOvertimeHours - (float) ($row['overtime_hours'] ?? 0)) < 0.001
            ) {
                continue;
            }

            $update->bindValue(':attendance_status', $targetStatus);
            $update->bindValue(':worked_days', $targetWorkedDays);
            $update->bindValue(':overtime_hours', $targetOvertimeHours);
            $update->bindValue(':recorded_by_user_id', $actor['user_id'] ?? null, isset($actor['user_id']) ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $update->bindValue(':recorded_by_name', $recordedByName, $recordedByName !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $update->bindValue(':id', (int) $row['id'], PDO::PARAM_INT);
            $update->execute();

            $fixed++;
            if ($targetStatus === 'present') {
                $toPresent++;
            } elseif ($targetStatus === 'absence') {
                $toAbsence++;
            } elseif ($targetStatus === 'repos') {
                $toRepos++;
            }
        }

        return [
            'period' => $period,
            'checked' => $checked,
            'fixed' => $fixed,
            'present' => $toPresent,
            'absence' => $toAbsence,
            'repos' => $toRepos,
        ];
    }

    public function getAttendanceSummary(int $personnelId, string $period): array
    {
        if ($personnelId <= 0) {
            return $this->emptyAttendanceSummary();
        }

        [$startDate, $endDate] = $this->getPeriodBounds($period);

        [$effectiveStartDate, $effectiveEndDate] = $this->getPersonnelPayrollBounds($personnelId, $startDate, $endDate);
        $holidayDates = $this->getHolidayDatesBetween($effectiveStartDate, $effectiveEndDate);
        $referenceWorkingDays = $this->countWorkingDaysBetween($effectiveStartDate, $effectiveEndDate, $holidayDates);

        $stmt = $this->pdo->prepare(
            'SELECT attendance_date, attendance_status, worked_days, overtime_hours, check_in_time, check_out_time FROM rh_attendance_daily WHERE personnel_id = :personnel_id AND attendance_date BETWEEN :start_date AND :end_date'
        );
        $stmt->bindValue(':personnel_id', $personnelId, PDO::PARAM_INT);
        $stmt->bindValue(':start_date', $effectiveStartDate);
        $stmt->bindValue(':end_date', $effectiveEndDate);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $statusCounts = array_fill_keys(array_keys($this->attendanceStatuses), 0);
        $statusDays = array_fill_keys(array_keys($this->attendanceStatuses), 0.0);
        $workingStatusCounts = array_fill_keys(array_keys($this->attendanceStatuses), 0);
        $workingStatusDays = array_fill_keys(array_keys($this->attendanceStatuses), 0.0);
        $workedDays = 0.0;
        $overtimeHours = 0.0;
        $workingEntries = 0;
        $workingWorkedDays = 0.0;
        $recordedWorkingDates = [];
        foreach ($rows as $row) {
            $date = $this->normalizeDate((string) ($row['attendance_date'] ?? ''));
            $isWorkingDate = $date !== null ? $this->isWorkingDate($date, $holidayDates) : false;

            $status = $date !== null ? $this->resolveAttendanceStatusForDate($date, $row, $holidayDates) : 'present';

            $storedStatus = (string) ($row['attendance_status'] ?? '');
            $rowWorkedDays = !$isWorkingDate ? 0.0 : (($storedStatus === 'repos') ? (float) $this->attendanceStatuses[$status]['default_days'] : (float) ($row['worked_days'] ?? 0));
            $rowOvertimeHours = $this->resolveAttendanceOvertimeHours($row, $isWorkingDate);
            $statusCounts[$status]++;
            $statusDays[$status] += $rowWorkedDays;
            $workedDays += $rowWorkedDays;
            $overtimeHours += $rowOvertimeHours;

            if ($isWorkingDate) {
                $recordedWorkingDates[$date] = true;
                $workingEntries++;
                $workingStatusCounts[$status]++;
                $workingStatusDays[$status] += $rowWorkedDays;
                $workingWorkedDays += $rowWorkedDays;
            }
        }

        $missingWorkingAbsences = 0;
        $isPointageExempt = false;
        try {
            $isPointageExempt = (new \RhAbsenceNotifier($this->pdo))->isPointageExempt($personnelId);
        } catch (Throwable) {
            $isPointageExempt = false;
        }

        if (!$isPointageExempt) {
            foreach ($this->listWorkingDatesBetween($effectiveStartDate, $effectiveEndDate, $holidayDates) as $workingDate) {
                if (isset($recordedWorkingDates[$workingDate])) {
                    continue;
                }

                $missingWorkingAbsences++;
                $statusCounts['absence']++;
                $workingStatusCounts['absence']++;
            }
        }

        return [
            'period' => $period,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'effective_start_date' => $effectiveStartDate,
            'effective_end_date' => $effectiveEndDate,
            'entries_count' => count($rows) + $missingWorkingAbsences,
            'recorded_entries_count' => count($rows),
            'missing_working_absence_days' => $missingWorkingAbsences,
            'worked_days' => round($workedDays, 2),
            'overtime_hours' => round($overtimeHours, 2),
            'status_counts' => $statusCounts,
            'status_days' => array_map(static fn($value) => round((float) $value, 2), $statusDays),
            'working_entries_count' => (int) ($workingEntries + $missingWorkingAbsences),
            'working_worked_days' => round((float) $workingWorkedDays, 2),
            'working_status_counts' => $workingStatusCounts,
            'working_status_days' => array_map(static fn($value) => round((float) $value, 2), $workingStatusDays),
            'reference_working_days' => (int) $referenceWorkingDays,
            'calendar_days' => (int) ((new DateTimeImmutable($startDate))->diff(new DateTimeImmutable($endDate))->days + 1),
        ];
    }

    public function getPayrollContext(int $personnelId, string $period): array
    {
        $employee = $this->getEmployeeSnapshot($personnelId);
        if ($employee === null) {
            throw new RuntimeException('Le salarie selectionne est introuvable.');
        }

        $summary = $this->getAttendanceSummary($personnelId, $period);
        $lastPayroll = $this->getLatestPayrollForEmployee($personnelId);
        $contractCode = $this->normalizeContractCode((string) ($lastPayroll['contract_code'] ?? $this->resolveContractCode($employee)));
        $attendanceRules = $this->getContractRules($contractCode);

        // Les jours de référence pour le plein salaire dépendent du mois (jours ouvrés lun->ven, fériés exclus).
        // On override la règle de contrat (souvent 30) avec le calendrier du mois demandé.
        $attendanceRules['reference_working_days'] = (float) ($summary['reference_working_days'] ?? $this->getReferenceWorkingDaysForPeriod($period));

        $attendanceBreakdown = $this->computeAttendanceCompensation($summary, $attendanceRules);

        $context = [
            'employee' => $employee,
            'attendance' => array_merge($summary, ['computed' => $attendanceBreakdown]),
            'attendance_rules' => $attendanceRules,
            'defaults' => [
                'personnel_id' => $personnelId,
                'period' => $period,
                'category_code' => $lastPayroll['category_code'] ?? 'M2',
                'contract_code' => $contractCode,
                'salary_category' => $lastPayroll['salary_category'] ?? 0,
                'over_salary' => $lastPayroll['over_salary'] ?? 0,
                'seniority_bonus' => $lastPayroll['seniority_bonus'] ?? 0,
                'other_bonus' => $lastPayroll['other_bonus'] ?? 0,
                'paid_leave_bonus' => $lastPayroll['paid_leave_bonus'] ?? 0,
                'gratification' => $lastPayroll['gratification'] ?? 0,
                'precarity_bonus' => $lastPayroll['precarity_bonus'] ?? ($contractCode === 'CDD' ? 0 : 0),
                'tax_is_rate' => $lastPayroll['tax_is_rate'] ?? 1.2,
                'cnps_employee_rate' => $lastPayroll['cnps_employee_rate'] ?? 6.3,
                'cnps_employer_rate' => $lastPayroll['cnps_employer_rate'] ?? 7.7,
                'family_benefit_rate' => $lastPayroll['family_benefit_rate'] ?? 5.75,
                'accident_rate' => $lastPayroll['accident_rate'] ?? 5.0,
                'apprenticeship_tax_rate' => $lastPayroll['apprenticeship_tax_rate'] ?? 0.4,
                'professional_training_tax_rate' => $lastPayroll['professional_training_tax_rate'] ?? 0.6,
                'formation_tax_rate' => $lastPayroll['formation_tax_rate'] ?? 0.6,
                'fiscal_parts' => $lastPayroll['fiscal_parts'] ?? 1,
                'manual_igr' => $lastPayroll['manual_igr'] ?? 0,
                'other_deductions' => $lastPayroll['other_deductions'] ?? 0,
                'transport_bonus' => $lastPayroll['transport_bonus'] ?? 30000,
                'health_insurance' => $lastPayroll['health_insurance'] ?? 1000,
                'advance_amount' => $lastPayroll['advance_amount'] ?? 0,
                'rounding_amount' => $lastPayroll['rounding_amount'] ?? 0,
                'reference_working_days' => $attendanceRules['reference_working_days'],
                'hours_per_day' => $attendanceRules['hours_per_day'],
                'overtime_multiplier' => $attendanceRules['overtime_multiplier'],
                'mission_paid_rate' => $attendanceRules['mission_paid_rate'],
                'conge_paid_rate' => $attendanceRules['conge_paid_rate'],
                'half_day_paid_rate' => $attendanceRules['half_day_paid_rate'],
                'absence_paid_rate' => $attendanceRules['absence_paid_rate'],
                'maladie_paid_rate' => $attendanceRules['maladie_paid_rate'],
                'repos_paid_rate' => $attendanceRules['repos_paid_rate'],
                'overtime_hours' => $summary['overtime_hours'],
                'attendance_days' => $attendanceBreakdown['paid_days'],
                'observations' => $lastPayroll['observations'] ?? '',
            ],
            'history' => $this->getPayrollHistory($personnelId, 6),
        ];

        $period = $this->normalizePeriod($period);
        $debtDefaults = $this->getDebtDeductionDefaults($personnelId, $period);
        $context['debt_summary'] = $debtDefaults['summary'] ?? null;
        if ($debtDefaults['has_advance_debt']) {
            $context['defaults']['advance_amount'] = $debtDefaults['advance_amount'];
        }
        if ($debtDefaults['has_loan_debt']) {
            $context['defaults']['other_deductions'] = $debtDefaults['loan_amount'];
        }

        return $context;
    }

    /**
     * Synchronise une dette (avance/prêt) depuis une demande RH approuvée.
     * - Avance -> champ paie: advance_amount
     * - Prêt -> champ paie: other_deductions
     */
    public function syncDebtFromLegalRequest(array $request, array $actor = []): bool
    {
        require_once __DIR__.'/../../services/RhFinancialPointService.php';
        if (RhFinancialPointService::managedRequest($this->pdo,$request)) {
            $payload=$request['payload'] ?? json_decode($request['payload_json'] ?? '{}',true);
            if (empty($payload['rh_financial_paid_at'])) return false;
        }
        $type = strtolower(trim((string) ($request['request_type'] ?? '')));
        $status = strtolower(trim((string) ($request['status'] ?? '')));
        if ($status !== 'approved') {
            return false;
        }

        if (!in_array($type, ['avance_salaire', 'pret'], true)) {
            return false;
        }

        $payload = [];
        if (isset($request['payload']) && is_array($request['payload'])) {
            $payload = (array) $request['payload'];
        } elseif (!empty($request['payload_json'])) {
            $decoded = json_decode((string) $request['payload_json'], true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        $matricule = trim((string) ($payload['matricule'] ?? ''));
        if ($matricule === '') {
            return false;
        }

        $personnelId = $this->findPersonnelIdByMatricule($matricule);
        if ($personnelId <= 0) {
            return false;
        }

        $requestId = (int) ($request['id'] ?? 0);
        $title = trim((string) ($request['title'] ?? ''));

        if ($type === 'avance_salaire') {
            $amount = $this->normalizeDecimal($payload['montant'] ?? 0);
            if ($amount <= 0) {
                return false;
            }

            $startPeriod = $this->resolveStartPeriodForAdvance($request);
            $note = 'Demande #' . ($requestId > 0 ? $requestId : '—') . ($title !== '' ? ' — ' . $title : '');
            $this->createOrIncreaseDebt($personnelId, self::DEBT_TYPE_ADVANCE, $amount, $amount, $startPeriod, $requestId, $note, $actor);
            return true;
        }

        if ($type === 'pret') {
            $principal = $this->normalizeDecimal($payload['montant_demande'] ?? 0);
            $recurring = $this->normalizeDecimal($payload['montant_recouvrement'] ?? 0);
            if ($principal <= 0) {
                return false;
            }
            if ($recurring < 0) {
                $recurring = 0.0;
            }

            $startPeriod = $this->resolveStartPeriodForLoan($payload, $request);
            $note = 'Demande #' . ($requestId > 0 ? $requestId : '—') . ($title !== '' ? ' — ' . $title : '');
            $this->createOrIncreaseDebt($personnelId, self::DEBT_TYPE_LOAN, $principal, $recurring > 0 ? $recurring : min($principal, $principal), $startPeriod, $requestId, $note, $actor);
            return true;
        }

        return false;
    }

    /**
     * Applique une régularisation de pointage (demande employé approuvée) sur rh_attendance_daily.
     * La demande doit être de type "regularisation_pointage" et avoir le statut "approved".
     *
     * @return array{ok:bool,message:string}
     */
    public function applyAttendanceRegularizationFromLegalRequest(array $request, array $actor = [], ?string $forcedStatus = null): array
    {
        $type = strtolower(trim((string) ($request['request_type'] ?? '')));
        $status = strtolower(trim((string) ($request['status'] ?? '')));
        if ($status !== 'approved') {
            return ['ok' => false, 'message' => 'La demande n\'est pas approuvée.'];
        }
        if ($type !== 'regularisation_pointage') {
            return ['ok' => false, 'message' => 'Type de demande non supporté.'];
        }

        $payload = [];
        if (isset($request['payload']) && is_array($request['payload'])) {
            $payload = (array) $request['payload'];
        } elseif (!empty($request['payload_json'])) {
            $decoded = json_decode((string) $request['payload_json'], true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        $personnelId = (int) ($payload['personnel_id'] ?? 0);
        if ($personnelId <= 0) {
            return ['ok' => false, 'message' => 'Personnel manquant dans la demande.'];
        }

        $date = $this->normalizeDate((string) ($payload['attendance_date'] ?? ''));
        if ($date === null) {
            return ['ok' => false, 'message' => 'Date de pointage invalide dans la demande.'];
        }
        $rangeStart = $this->normalizeDate((string) ($payload['date_debut_justificatif'] ?? $date)) ?? $date;
        $rangeEnd = $this->normalizeDate((string) ($payload['date_fin_justificatif'] ?? $rangeStart)) ?? $rangeStart;
        if ($rangeEnd < $rangeStart) {
            return ['ok' => false, 'message' => 'Plage de regularisation invalide.'];
        }

        $targetStatus = strtolower(trim((string) ($forcedStatus !== null ? $forcedStatus : ($payload['requested_status'] ?? ''))));
        if (!isset($this->attendanceStatuses[$targetStatus])) {
            return ['ok' => false, 'message' => 'Statut de régularisation invalide.'];
        }

        $note = trim((string) ($payload['note'] ?? ''));

        $stmt = $this->pdo->prepare('
            SELECT overtime_hours, check_in_time, check_out_time
            FROM rh_attendance_daily
            WHERE personnel_id = :pid AND attendance_date = :d
            LIMIT 1
        ');
        if ($rangeStart !== $rangeEnd) {
            $updated = 0;
            $cursor = new DateTimeImmutable($rangeStart);
            $end = new DateTimeImmutable($rangeEnd);
            while ($cursor <= $end) {
                $currentDate = $cursor->format('Y-m-d');
                if (!$this->isWorkingDate($currentDate)) {
                    $cursor = $cursor->modify('+1 day');
                    continue;
                }

                $stmt->execute(['pid' => $personnelId, 'd' => $currentDate]);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
                $this->saveDailyAttendance($currentDate, [
                    $personnelId => [
                        'status' => $targetStatus,
                        'worked_days' => $this->attendanceStatuses[$targetStatus]['default_days'],
                        'overtime_hours' => $existing ? (float) ($existing['overtime_hours'] ?? 0) : 0.0,
                        'check_in_time' => $existing ? ($existing['check_in_time'] ?? null) : null,
                        'check_out_time' => $existing ? ($existing['check_out_time'] ?? null) : null,
                        'notes' => $note,
                    ],
                ], [
                    'user_id' => (int) ($actor['user_id'] ?? 0) ?: null,
                    'user_name' => (string) ($actor['user_name'] ?? $actor['name'] ?? ''),
                ]);
                $updated++;
                $cursor = $cursor->modify('+1 day');
            }

            return ['ok' => true, 'message' => $updated . ' jour(s) de pointage mis a jour.'];
        }
        $date = $rangeStart;
        $stmt->execute(['pid' => $personnelId, 'd' => $date]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $entry = [
            $personnelId => [
                'status' => $targetStatus,
                'worked_days' => $this->attendanceStatuses[$targetStatus]['default_days'],
                'overtime_hours' => $existing ? (float) ($existing['overtime_hours'] ?? 0) : 0.0,
                'check_in_time' => $existing ? ($existing['check_in_time'] ?? null) : null,
                'check_out_time' => $existing ? ($existing['check_out_time'] ?? null) : null,
                'notes' => $note,
            ],
        ];

        $this->saveDailyAttendance($date, $entry, [
            'user_id' => (int) ($actor['user_id'] ?? 0) ?: null,
            'user_name' => (string) ($actor['user_name'] ?? $actor['name'] ?? ''),
        ]);

        return ['ok' => true, 'message' => 'Pointage mis à jour.'];
    }

    public function calculatePayroll(array $employee, array $input): array
    {
        $period = preg_match('/^\d{4}-\d{2}$/', (string) ($input['period'] ?? '')) ? (string) $input['period'] : date('Y-m');
        $personnelId = (int) ($input['personnel_id'] ?? $employee['id'] ?? 0);
        $contractCode = $this->normalizeContractCode((string) ($input['contract_code'] ?? $this->resolveContractCode($employee)));
        $attendanceSummary = isset($input['attendance_summary']) && is_array($input['attendance_summary'])
            ? $input['attendance_summary']
            : ($personnelId > 0 ? $this->getAttendanceSummary($personnelId, $period) : $this->emptyAttendanceSummary());
        $attendanceRules = $this->getContractRules($contractCode);
        $attendanceBreakdown = $this->computeAttendanceCompensation($attendanceSummary, $attendanceRules);

        $salaryCategory = $this->normalizeDecimal($input['salary_category'] ?? 0);
        $overSalary = $this->normalizeDecimal($input['over_salary'] ?? 0);
        $seniorityBonus = $this->normalizeDecimal($input['seniority_bonus'] ?? 0);
        $otherBonus = $this->normalizeDecimal($input['other_bonus'] ?? 0);
        $paidLeaveBonus = $this->normalizeDecimal($input['paid_leave_bonus'] ?? 0);
        $gratification = $this->normalizeDecimal($input['gratification'] ?? 0);
        $transportBonus = $this->normalizeDecimal($input['transport_bonus'] ?? 0);
        $healthInsurance = $this->normalizeDecimal($input['health_insurance'] ?? 0);
        $advanceAmount = $this->normalizeDecimal($input['advance_amount'] ?? 0);
        $roundingAmount = $this->normalizeDecimal($input['rounding_amount'] ?? 0);
        $otherDeductions = $this->normalizeDecimal($input['other_deductions'] ?? 0);
        $attendanceDays = $attendanceBreakdown['paid_days'];
        $overtimeHours = $this->normalizeDecimal($input['overtime_hours'] ?? ($attendanceSummary['overtime_hours'] ?? 0));

        $taxIsRate = $this->normalizeDecimal($input['tax_is_rate'] ?? 1.2);
        $cnpsEmployeeRate = $this->normalizeDecimal($input['cnps_employee_rate'] ?? 6.3);
        $cnpsEmployerRate = $this->normalizeDecimal($input['cnps_employer_rate'] ?? 7.7);
        $familyBenefitRate = $this->normalizeDecimal($input['family_benefit_rate'] ?? 5.75);
        $accidentRate = $this->normalizeDecimal($input['accident_rate'] ?? 5.0);
        $apprenticeshipTaxRate = $this->normalizeDecimal($input['apprenticeship_tax_rate'] ?? 0.4);
        $professionalTrainingTaxRate = $this->normalizeDecimal($input['professional_training_tax_rate'] ?? 0.6);
        $formationTaxRate = $this->normalizeDecimal($input['formation_tax_rate'] ?? 0.6);
        $fiscalParts = max(1.0, $this->normalizeDecimal($input['fiscal_parts'] ?? 1));
        $manualIgr = $this->normalizeDecimal($input['manual_igr'] ?? 0);

        $fixedMonthlyGross = $salaryCategory + $overSalary + $seniorityBonus;
        $prorataRate = $attendanceRules['reference_working_days'] > 0
            ? min(1.0, round($attendanceDays / $attendanceRules['reference_working_days'], 4))
            : 1.0;
        $attendanceDeduction = round(max(0, $fixedMonthlyGross - ($fixedMonthlyGross * $prorataRate)), 2);
        $hourlyRateBase = $attendanceRules['reference_working_days'] > 0 && $attendanceRules['hours_per_day'] > 0
            ? $fixedMonthlyGross / $attendanceRules['reference_working_days'] / $attendanceRules['hours_per_day']
            : 0.0;
        $overtimeAmount = round($overtimeHours * $hourlyRateBase * $attendanceRules['overtime_multiplier'], 2);
        $partialGross = $fixedMonthlyGross - $attendanceDeduction + $overtimeAmount + $otherBonus;
        $precarityBonus = $this->normalizeDecimal($input['precarity_bonus'] ?? 0);
        if ($precarityBonus <= 0 && $attendanceRules['precarity_rate'] > 0) {
            $precarityBonus = round($partialGross * ($attendanceRules['precarity_rate'] / 100), 2);
        }

        $grossTaxable = $partialGross + $paidLeaveBonus + $gratification + $precarityBonus;
        $employeeIs = round($grossTaxable * ($taxIsRate / 100), 2);
        $employeeCnps = round($grossTaxable * ($cnpsEmployeeRate / 100), 2);
        $autoIgr = $this->computeEstimatedIgr($grossTaxable, $fiscalParts, $employeeIs + $employeeCnps);
        $igr = $manualIgr > 0 ? $manualIgr : $autoIgr;
        $cn = 0.0;
        $totalEmployeeDeductions = $employeeIs + $cn + $igr + $employeeCnps + $otherDeductions + $healthInsurance + $advanceAmount;
        $netSalary = $grossTaxable - $totalEmployeeDeductions + $transportBonus + $roundingAmount;

        $employerCnps = round($grossTaxable * ($cnpsEmployerRate / 100), 2);
        $familyBenefit = round($grossTaxable * ($familyBenefitRate / 100), 2);
        $accident = round($grossTaxable * ($accidentRate / 100), 2);
        $apprenticeshipTax = round($grossTaxable * ($apprenticeshipTaxRate / 100), 2);
        $professionalTrainingTax = round($grossTaxable * ($professionalTrainingTaxRate / 100), 2);
        $formationTax = round($grossTaxable * ($formationTaxRate / 100), 2);
        $employerIs = 0.0;
        $totalEmployerContributions = $employerIs + $employerCnps + $familyBenefit + $accident + $apprenticeshipTax + $professionalTrainingTax + $formationTax;

        $lines = [
            ['designation' => 'Salaire categoriel mensuel', 'number' => $attendanceRules['reference_working_days'], 'base' => $salaryCategory, 'rate' => null, 'gain' => $salaryCategory, 'employee_deduction' => 0, 'employer_rate' => null, 'employer_contribution' => 0],
            ['designation' => 'Sursalaire mensuel', 'number' => $attendanceRules['reference_working_days'], 'base' => $overSalary, 'rate' => null, 'gain' => $overSalary, 'employee_deduction' => 0, 'employer_rate' => null, 'employer_contribution' => 0],
            ['designation' => 'Prime d\'anciennete mensuelle', 'number' => $attendanceRules['reference_working_days'], 'base' => $seniorityBonus, 'rate' => null, 'gain' => $seniorityBonus, 'employee_deduction' => 0, 'employer_rate' => null, 'employer_contribution' => 0],
            ['designation' => 'Regularisation pointage', 'number' => $attendanceDays, 'base' => $fixedMonthlyGross, 'rate' => round((1 - $prorataRate) * 100, 2), 'gain' => 0, 'employee_deduction' => $attendanceDeduction, 'employer_rate' => null, 'employer_contribution' => 0],
            ['designation' => 'Heures supplementaires valorisees', 'number' => $overtimeHours, 'base' => round($hourlyRateBase, 2), 'rate' => round($attendanceRules['overtime_multiplier'] * 100, 2), 'gain' => $overtimeAmount, 'employee_deduction' => 0, 'employer_rate' => null, 'employer_contribution' => 0],
            ['designation' => 'Autres primes', 'number' => null, 'base' => $otherBonus, 'rate' => null, 'gain' => $otherBonus, 'employee_deduction' => 0, 'employer_rate' => null, 'employer_contribution' => 0],
            ['designation' => 'Prime conges payes', 'number' => null, 'base' => $paidLeaveBonus, 'rate' => null, 'gain' => $paidLeaveBonus, 'employee_deduction' => 0, 'employer_rate' => null, 'employer_contribution' => 0],
            ['designation' => 'Gratification', 'number' => null, 'base' => $gratification, 'rate' => null, 'gain' => $gratification, 'employee_deduction' => 0, 'employer_rate' => null, 'employer_contribution' => 0],
            ['designation' => 'Precarite (3%) si CDD', 'number' => null, 'base' => $partialGross, 'rate' => $contractCode === 'CDD' ? 3 : 0, 'gain' => $precarityBonus, 'employee_deduction' => 0, 'employer_rate' => null, 'employer_contribution' => 0],
            ['designation' => 'Impots sur salaire part salariale', 'number' => null, 'base' => $grossTaxable, 'rate' => $taxIsRate, 'gain' => 0, 'employee_deduction' => $employeeIs, 'employer_rate' => null, 'employer_contribution' => $employerIs],
            ['designation' => 'C.N', 'number' => null, 'base' => 0, 'rate' => 0, 'gain' => 0, 'employee_deduction' => $cn, 'employer_rate' => null, 'employer_contribution' => 0],
            ['designation' => 'I.G.R', 'number' => null, 'base' => $grossTaxable, 'rate' => null, 'gain' => 0, 'employee_deduction' => $igr, 'employer_rate' => null, 'employer_contribution' => 0],
            ['designation' => 'CNPS part salariale', 'number' => null, 'base' => $grossTaxable, 'rate' => $cnpsEmployeeRate, 'gain' => 0, 'employee_deduction' => $employeeCnps, 'employer_rate' => $cnpsEmployerRate, 'employer_contribution' => $employerCnps],
            ['designation' => 'Prime de transport', 'number' => null, 'base' => $transportBonus, 'rate' => null, 'gain' => $transportBonus, 'employee_deduction' => 0, 'employer_rate' => null, 'employer_contribution' => 0],
            ['designation' => 'Assurance maladie', 'number' => null, 'base' => 0, 'rate' => null, 'gain' => 0, 'employee_deduction' => $healthInsurance, 'employer_rate' => null, 'employer_contribution' => 0],
            ['designation' => 'Avance', 'number' => null, 'base' => 0, 'rate' => null, 'gain' => 0, 'employee_deduction' => $advanceAmount, 'employer_rate' => null, 'employer_contribution' => 0],
            ['designation' => 'Arrondi de paie', 'number' => null, 'base' => 0, 'rate' => null, 'gain' => $roundingAmount, 'employee_deduction' => 0, 'employer_rate' => null, 'employer_contribution' => 0],
        ];

        return [
            'period' => $period,
            'category_code' => trim((string) ($input['category_code'] ?? 'M2')),
            'contract_code' => $contractCode,
            'contract_label' => (string) ($attendanceRules['label'] ?? $contractCode),
            'currency' => 'FCFA',
            'reference_working_days' => round($attendanceRules['reference_working_days'], 2),
            'hours_per_day' => round($attendanceRules['hours_per_day'], 2),
            'overtime_multiplier' => round($attendanceRules['overtime_multiplier'], 2),
            'mission_paid_rate' => round($attendanceRules['mission_paid_rate'], 2),
            'conge_paid_rate' => round($attendanceRules['conge_paid_rate'], 2),
            'half_day_paid_rate' => round($attendanceRules['half_day_paid_rate'], 2),
            'absence_paid_rate' => round($attendanceRules['absence_paid_rate'], 2),
            'maladie_paid_rate' => round($attendanceRules['maladie_paid_rate'], 2),
            'repos_paid_rate' => round($attendanceRules['repos_paid_rate'], 2),
            'precarity_rate' => round($attendanceRules['precarity_rate'], 2),
            'attendance_days' => round($attendanceDays, 2),
            'attendance_summary' => $attendanceSummary,
            'attendance_breakdown' => $attendanceBreakdown,
            'attendance_deduction' => $attendanceDeduction,
            'prorata_rate' => round($prorataRate, 4),
            'overtime_hours' => round($overtimeHours, 2),
            'overtime_hourly_rate' => round($hourlyRateBase, 2),
            'overtime_amount' => $overtimeAmount,
            'salary_category' => $salaryCategory,
            'over_salary' => $overSalary,
            'seniority_bonus' => $seniorityBonus,
            'other_bonus' => $otherBonus,
            'paid_leave_bonus' => $paidLeaveBonus,
            'gratification' => $gratification,
            'precarity_bonus' => $precarityBonus,
            'transport_bonus' => $transportBonus,
            'health_insurance' => $healthInsurance,
            'advance_amount' => $advanceAmount,
            'rounding_amount' => $roundingAmount,
            'other_deductions' => $otherDeductions,
            'tax_is_rate' => $taxIsRate,
            'cnps_employee_rate' => $cnpsEmployeeRate,
            'cnps_employer_rate' => $cnpsEmployerRate,
            'family_benefit_rate' => $familyBenefitRate,
            'accident_rate' => $accidentRate,
            'apprenticeship_tax_rate' => $apprenticeshipTaxRate,
            'professional_training_tax_rate' => $professionalTrainingTaxRate,
            'formation_tax_rate' => $formationTaxRate,
            'fiscal_parts' => $fiscalParts,
            'manual_igr' => $manualIgr,
            'auto_igr' => $autoIgr,
            'igr' => $igr,
            'gross_partial' => round($partialGross, 2),
            'gross_taxable' => round($grossTaxable, 2),
            'employee_is' => $employeeIs,
            'cn' => $cn,
            'employee_cnps' => $employeeCnps,
            'employer_is' => $employerIs,
            'employer_cnps' => $employerCnps,
            'family_benefit' => $familyBenefit,
            'accident' => $accident,
            'apprenticeship_tax' => $apprenticeshipTax,
            'professional_training_tax' => $professionalTrainingTax,
            'formation_tax' => $formationTax,
            'total_employee_deductions' => round($totalEmployeeDeductions, 2),
            'total_employer_contributions' => round($totalEmployerContributions, 2),
            'net_salary' => round($netSalary, 2),
            'observations' => trim((string) ($input['observations'] ?? '')),
            'lines' => $lines,
        ];
    }

    public function savePayrollRecord(array $input, array $actor = []): int
    {
        $personnelId = (int) ($input['personnel_id'] ?? 0);
        if ($personnelId <= 0) {
            throw new RuntimeException('Le salarie est obligatoire.');
        }

        $employee = $this->getEmployeeSnapshot($personnelId);
        if ($employee === null) {
            throw new RuntimeException('Le salarie selectionne est introuvable.');
        }

        $calculation = $this->calculatePayroll($employee, $input);
        $sql = '
            INSERT INTO rh_payroll_records (
                personnel_id,
                payroll_period,
                category_code,
                contract_code,
                currency_code,
                attendance_days,
                salary_category,
                over_salary,
                seniority_bonus,
                other_bonus,
                paid_leave_bonus,
                gratification,
                precarity_bonus,
                tax_is_rate,
                cnps_employee_rate,
                cnps_employer_rate,
                family_benefit_rate,
                accident_rate,
                apprenticeship_tax_rate,
                professional_training_tax_rate,
                formation_tax_rate,
                fiscal_parts,
                manual_igr,
                other_deductions,
                transport_bonus,
                health_insurance,
                advance_amount,
                rounding_amount,
                gross_taxable,
                total_employee_deductions,
                total_employer_contributions,
                net_salary,
                observations,
                payload_json,
                created_by_user_id,
                created_by_name
            ) VALUES (
                :personnel_id,
                :payroll_period,
                :category_code,
                :contract_code,
                :currency_code,
                :attendance_days,
                :salary_category,
                :over_salary,
                :seniority_bonus,
                :other_bonus,
                :paid_leave_bonus,
                :gratification,
                :precarity_bonus,
                :tax_is_rate,
                :cnps_employee_rate,
                :cnps_employer_rate,
                :family_benefit_rate,
                :accident_rate,
                :apprenticeship_tax_rate,
                :professional_training_tax_rate,
                :formation_tax_rate,
                :fiscal_parts,
                :manual_igr,
                :other_deductions,
                :transport_bonus,
                :health_insurance,
                :advance_amount,
                :rounding_amount,
                :gross_taxable,
                :total_employee_deductions,
                :total_employer_contributions,
                :net_salary,
                :observations,
                :payload_json,
                :created_by_user_id,
                :created_by_name
            )
            ON DUPLICATE KEY UPDATE
                category_code = VALUES(category_code),
                contract_code = VALUES(contract_code),
                currency_code = VALUES(currency_code),
                attendance_days = VALUES(attendance_days),
                salary_category = VALUES(salary_category),
                over_salary = VALUES(over_salary),
                seniority_bonus = VALUES(seniority_bonus),
                other_bonus = VALUES(other_bonus),
                paid_leave_bonus = VALUES(paid_leave_bonus),
                gratification = VALUES(gratification),
                precarity_bonus = VALUES(precarity_bonus),
                tax_is_rate = VALUES(tax_is_rate),
                cnps_employee_rate = VALUES(cnps_employee_rate),
                cnps_employer_rate = VALUES(cnps_employer_rate),
                family_benefit_rate = VALUES(family_benefit_rate),
                accident_rate = VALUES(accident_rate),
                apprenticeship_tax_rate = VALUES(apprenticeship_tax_rate),
                professional_training_tax_rate = VALUES(professional_training_tax_rate),
                formation_tax_rate = VALUES(formation_tax_rate),
                fiscal_parts = VALUES(fiscal_parts),
                manual_igr = VALUES(manual_igr),
                other_deductions = VALUES(other_deductions),
                transport_bonus = VALUES(transport_bonus),
                health_insurance = VALUES(health_insurance),
                advance_amount = VALUES(advance_amount),
                rounding_amount = VALUES(rounding_amount),
                gross_taxable = VALUES(gross_taxable),
                total_employee_deductions = VALUES(total_employee_deductions),
                total_employer_contributions = VALUES(total_employer_contributions),
                net_salary = VALUES(net_salary),
                observations = VALUES(observations),
                payload_json = VALUES(payload_json),
                created_by_user_id = VALUES(created_by_user_id),
                created_by_name = VALUES(created_by_name),
                updated_at = CURRENT_TIMESTAMP
        ';

        $payload = [
            'employee' => $employee,
            'calculation' => $calculation,
            'attendance_summary' => $this->getAttendanceSummary($personnelId, $calculation['period']),
        ];

        $startedTransaction = false;

        try {
            if (!$this->pdo->inTransaction()) {
                $this->pdo->beginTransaction();
                $startedTransaction = true;
            }

            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':personnel_id', $personnelId, PDO::PARAM_INT);
            $stmt->bindValue(':payroll_period', $calculation['period']);
            $stmt->bindValue(':category_code', $calculation['category_code']);
            $stmt->bindValue(':contract_code', $calculation['contract_code']);
            $stmt->bindValue(':currency_code', $calculation['currency']);
            $stmt->bindValue(':attendance_days', $calculation['attendance_days']);
            $stmt->bindValue(':salary_category', $calculation['salary_category']);
            $stmt->bindValue(':over_salary', $calculation['over_salary']);
            $stmt->bindValue(':seniority_bonus', $calculation['seniority_bonus']);
            $stmt->bindValue(':other_bonus', $calculation['other_bonus']);
            $stmt->bindValue(':paid_leave_bonus', $calculation['paid_leave_bonus']);
            $stmt->bindValue(':gratification', $calculation['gratification']);
            $stmt->bindValue(':precarity_bonus', $calculation['precarity_bonus']);
            $stmt->bindValue(':tax_is_rate', $calculation['tax_is_rate']);
            $stmt->bindValue(':cnps_employee_rate', $calculation['cnps_employee_rate']);
            $stmt->bindValue(':cnps_employer_rate', $calculation['cnps_employer_rate']);
            $stmt->bindValue(':family_benefit_rate', $calculation['family_benefit_rate']);
            $stmt->bindValue(':accident_rate', $calculation['accident_rate']);
            $stmt->bindValue(':apprenticeship_tax_rate', $calculation['apprenticeship_tax_rate']);
            $stmt->bindValue(':professional_training_tax_rate', $calculation['professional_training_tax_rate']);
            $stmt->bindValue(':formation_tax_rate', $calculation['formation_tax_rate']);
            $stmt->bindValue(':fiscal_parts', $calculation['fiscal_parts']);
            $stmt->bindValue(':manual_igr', $calculation['manual_igr']);
            $stmt->bindValue(':other_deductions', $calculation['other_deductions']);
            $stmt->bindValue(':transport_bonus', $calculation['transport_bonus']);
            $stmt->bindValue(':health_insurance', $calculation['health_insurance']);
            $stmt->bindValue(':advance_amount', $calculation['advance_amount']);
            $stmt->bindValue(':rounding_amount', $calculation['rounding_amount']);
            $stmt->bindValue(':gross_taxable', $calculation['gross_taxable']);
            $stmt->bindValue(':total_employee_deductions', $calculation['total_employee_deductions']);
            $stmt->bindValue(':total_employer_contributions', $calculation['total_employer_contributions']);
            $stmt->bindValue(':net_salary', $calculation['net_salary']);
            $stmt->bindValue(':observations', $calculation['observations'] !== '' ? $calculation['observations'] : null, $calculation['observations'] !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindValue(':payload_json', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $stmt->bindValue(':created_by_user_id', $actor['user_id'] ?? null, isset($actor['user_id']) ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $createdByName = $this->normalizeNullableString($actor['user_name'] ?? ($actor['name'] ?? null));
            $stmt->bindValue(':created_by_name', $createdByName, $createdByName !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->execute();

            $recordId = (int) $this->pdo->lastInsertId();
            if ($recordId <= 0) {
                $lookup = $this->pdo->prepare('SELECT id FROM rh_payroll_records WHERE personnel_id = :personnel_id AND payroll_period = :payroll_period LIMIT 1');
                $lookup->bindValue(':personnel_id', $personnelId, PDO::PARAM_INT);
                $lookup->bindValue(':payroll_period', $calculation['period']);
                $lookup->execute();
                $recordId = (int) $lookup->fetchColumn();
            }

            if ($recordId > 0) {
                $this->applyDebtPaymentsForPayrollRecord($personnelId, (string) $calculation['period'], $recordId, $calculation, $actor);
            }

            if ($startedTransaction) {
                $this->pdo->commit();
            }

            return $recordId;
        } catch (Throwable $e) {
            if ($startedTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function applyDebtPaymentsForPayrollRecord(int $personnelId, string $period, int $payrollRecordId, array $calculation, array $actor = []): void
    {
        $period = $this->normalizePeriod($period);
        $advanceDeclared = $this->normalizeDecimal($calculation['advance_amount'] ?? 0);
        $loanDeclared = $this->normalizeDecimal($calculation['other_deductions'] ?? 0);

        $this->applyDebtPaymentForPeriod($personnelId, self::DEBT_TYPE_ADVANCE, $period, $advanceDeclared, $payrollRecordId, $actor);
        $this->applyDebtPaymentForPeriod($personnelId, self::DEBT_TYPE_LOAN, $period, $loanDeclared, $payrollRecordId, $actor);
    }

    private function applyDebtPaymentForPeriod(int $personnelId, string $debtType, string $period, float $declaredAmount, int $payrollRecordId, array $actor = []): void
    {
        $debtType = $this->normalizeDebtType($debtType);
        if ($debtType === '' || $personnelId <= 0) {
            return;
        }

        $debt = $this->getOpenDebtForUpdate($personnelId, $debtType);
        if (!$debt) {
            return;
        }

        $startPeriod = $this->normalizeNullablePeriod($debt['start_period'] ?? null);
        if ($startPeriod !== null && $period < $startPeriod) {
            return;
        }

        $declaredAmount = $this->normalizeDecimal($declaredAmount);
        if ($declaredAmount < 0) {
            $declaredAmount = 0.0;
        }

        $debtId = (int) ($debt['id'] ?? 0);
        if ($debtId <= 0) {
            return;
        }

        $existingPayment = $this->getDebtPaymentForUpdate($debtId, $period);
        $remaining = $this->normalizeDecimal($debt['remaining_amount'] ?? 0);
        $original = $this->normalizeDecimal($debt['original_amount'] ?? 0);

        if ($existingPayment) {
            $prevApplied = $this->normalizeDecimal($existingPayment['applied_amount'] ?? 0);
            $remaining = $remaining + $prevApplied;
            if ($original > 0) {
                $remaining = min($original, $remaining);
            }
        }

        $applied = min($remaining, $declaredAmount);
        $remainingAfter = $remaining - $applied;

        $recurringAmount = $this->normalizeDecimal($declaredAmount);
        $this->updateDebtAfterPayment($debtId, $recurringAmount, $remainingAfter, $remainingAfter <= 0.0001);

        $this->upsertDebtPayment(
            $debtId,
            $period,
            $payrollRecordId,
            $declaredAmount,
            $applied,
            max(0.0, $remainingAfter),
            $actor
        );
    }

    private function getDebtDeductionDefaults(int $personnelId, string $period): array
    {
        $period = $this->normalizePeriod($period);

        $advanceDebt = $this->getOpenDebt($personnelId, self::DEBT_TYPE_ADVANCE);
        $loanDebt = $this->getOpenDebt($personnelId, self::DEBT_TYPE_LOAN);

        $advanceAmount = 0.0;
        $loanAmount = 0.0;

        $advanceRecurring = $advanceDebt ? $this->normalizeDecimal($advanceDebt['recurring_amount'] ?? 0) : 0.0;
        $loanRecurring = $loanDebt ? $this->normalizeDecimal($loanDebt['recurring_amount'] ?? 0) : 0.0;

        $hasAdvanceDebt = false;
        if ($advanceDebt) {
            $hasAdvanceDebt = $this->isDebtActiveForPeriod($advanceDebt, $period);
            if ($hasAdvanceDebt) {
                $advanceAmount = $this->suggestDebtPayment($advanceDebt);
            }
        }

        $hasLoanDebt = false;
        if ($loanDebt) {
            $hasLoanDebt = $this->isDebtActiveForPeriod($loanDebt, $period);
            if ($hasLoanDebt) {
                $loanAmount = $this->suggestDebtPayment($loanDebt);
            }
        }

        $advanceRemaining = $advanceDebt ? $this->normalizeDecimal($advanceDebt['remaining_amount'] ?? 0) : 0.0;
        $loanRemaining = $loanDebt ? $this->normalizeDecimal($loanDebt['remaining_amount'] ?? 0) : 0.0;

        $summary = [
            'advance' => [
                'exists' => (bool) ($advanceDebt && $advanceRemaining > 0.0001),
                'active' => (bool) $hasAdvanceDebt,
                'remaining_amount' => round(max(0.0, $advanceRemaining), 2),
                'recurring_amount' => round(max(0.0, $advanceRecurring), 2),
                'suggested_amount' => round(max(0.0, $advanceAmount), 2),
                'start_period' => $this->normalizeNullablePeriod($advanceDebt['start_period'] ?? null),
            ],
            'loan' => [
                'exists' => (bool) ($loanDebt && $loanRemaining > 0.0001),
                'active' => (bool) $hasLoanDebt,
                'remaining_amount' => round(max(0.0, $loanRemaining), 2),
                'recurring_amount' => round(max(0.0, $loanRecurring), 2),
                'suggested_amount' => round(max(0.0, $loanAmount), 2),
                'start_period' => $this->normalizeNullablePeriod($loanDebt['start_period'] ?? null),
            ],
        ];

        return [
            'has_advance_debt' => $hasAdvanceDebt,
            'has_loan_debt' => $hasLoanDebt,
            'advance_amount' => round($advanceAmount, 2),
            'loan_amount' => round($loanAmount, 2),
            'summary' => $summary,
        ];
    }

    private function suggestDebtPayment(array $debt): float
    {
        $remaining = $this->normalizeDecimal($debt['remaining_amount'] ?? 0);
        $recurring = $this->normalizeDecimal($debt['recurring_amount'] ?? 0);
        if ($remaining <= 0) {
            return 0.0;
        }
        if ($recurring < 0) {
            $recurring = 0.0;
        }
        return min($remaining, $recurring);
    }

    private function isDebtActiveForPeriod(array $debt, string $period): bool
    {
        if ((int) ($debt['is_closed'] ?? 0) === 1) {
            return false;
        }

        $remaining = $this->normalizeDecimal($debt['remaining_amount'] ?? 0);
        if ($remaining <= 0.0001) {
            return false;
        }

        $startPeriod = $this->normalizeNullablePeriod($debt['start_period'] ?? null);
        return $startPeriod === null || $period >= $startPeriod;
    }

    private function normalizeDebtType(string $type): string
    {
        $type = strtolower(trim($type));
        return in_array($type, [self::DEBT_TYPE_ADVANCE, self::DEBT_TYPE_LOAN], true) ? $type : '';
    }

    private function normalizePeriod(string $period): string
    {
        $period = trim((string) $period);
        if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
            return date('Y-m');
        }
        return $period;
    }

    private function normalizeNullablePeriod($period): ?string
    {
        if ($period === null) {
            return null;
        }
        $period = trim((string) $period);
        if ($period === '' || !preg_match('/^\d{4}-\d{2}$/', $period)) {
            return null;
        }
        return $period;
    }

    private function findPersonnelIdByMatricule(string $matricule): int
    {
        $matricule = trim($matricule);
        if ($matricule === '') {
            return 0;
        }

        $stmt = $this->pdo->prepare('SELECT id_personnel_soignant FROM personnel WHERE UPPER(TRIM(matricule_personnel_soignant)) = UPPER(TRIM(:matricule)) LIMIT 1');
        $stmt->bindValue(':matricule', $matricule);
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    private function getOpenDebt(int $personnelId, string $debtType): ?array
    {
        $debtType = $this->normalizeDebtType($debtType);
        if ($debtType === '' || $personnelId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare('
            SELECT *
            FROM rh_employee_debts
            WHERE personnel_id = :personnel_id
              AND debt_type = :debt_type
              AND is_closed = 0
            ORDER BY id DESC
            LIMIT 1
        ');
        $stmt->bindValue(':personnel_id', $personnelId, PDO::PARAM_INT);
        $stmt->bindValue(':debt_type', $debtType);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function getOpenDebtForUpdate(int $personnelId, string $debtType): ?array
    {
        $debtType = $this->normalizeDebtType($debtType);
        if ($debtType === '' || $personnelId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare('
            SELECT *
            FROM rh_employee_debts
            WHERE personnel_id = :personnel_id
              AND debt_type = :debt_type
              AND is_closed = 0
            ORDER BY id DESC
            LIMIT 1
            FOR UPDATE
        ');
        $stmt->bindValue(':personnel_id', $personnelId, PDO::PARAM_INT);
        $stmt->bindValue(':debt_type', $debtType);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function getDebtPaymentForUpdate(int $debtId, string $period): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT *
            FROM rh_employee_debt_payments
            WHERE debt_id = :debt_id
              AND payroll_period = :payroll_period
            LIMIT 1
            FOR UPDATE
        ');
        $stmt->bindValue(':debt_id', $debtId, PDO::PARAM_INT);
        $stmt->bindValue(':payroll_period', $period);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function updateDebtAfterPayment(int $debtId, float $recurringAmount, float $remainingAmount, bool $shouldClose): void
    {
        $sql = '
            UPDATE rh_employee_debts
            SET recurring_amount = :recurring_amount,
                remaining_amount = :remaining_amount,
                is_closed = :is_closed,
                closed_at = ' . ($shouldClose ? 'NOW()' : 'NULL') . ',
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
            LIMIT 1
        ';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':recurring_amount', $this->normalizeDecimal($recurringAmount));
        $stmt->bindValue(':remaining_amount', max(0.0, $this->normalizeDecimal($remainingAmount)));
        $stmt->bindValue(':is_closed', $shouldClose ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':id', $debtId, PDO::PARAM_INT);
        $stmt->execute();
    }

    private function upsertDebtPayment(
        int $debtId,
        string $period,
        int $payrollRecordId,
        float $declaredAmount,
        float $appliedAmount,
        float $remainingAfter,
        array $actor = []
    ): void {
        $sql = '
            INSERT INTO rh_employee_debt_payments (
                debt_id,
                payroll_period,
                payroll_record_id,
                declared_amount,
                applied_amount,
                remaining_after,
                created_by_user_id,
                created_by_name,
                created_at
            ) VALUES (
                :debt_id,
                :payroll_period,
                :payroll_record_id,
                :declared_amount,
                :applied_amount,
                :remaining_after,
                :created_by_user_id,
                :created_by_name,
                NOW()
            )
            ON DUPLICATE KEY UPDATE
                payroll_record_id = VALUES(payroll_record_id),
                declared_amount = VALUES(declared_amount),
                applied_amount = VALUES(applied_amount),
                remaining_after = VALUES(remaining_after),
                created_by_user_id = VALUES(created_by_user_id),
                created_by_name = VALUES(created_by_name),
                created_at = NOW()
        ';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':debt_id', $debtId, PDO::PARAM_INT);
        $stmt->bindValue(':payroll_period', $period);
        $stmt->bindValue(':payroll_record_id', $payrollRecordId > 0 ? $payrollRecordId : null, $payrollRecordId > 0 ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':declared_amount', $this->normalizeDecimal($declaredAmount));
        $stmt->bindValue(':applied_amount', $this->normalizeDecimal($appliedAmount));
        $stmt->bindValue(':remaining_after', $this->normalizeDecimal($remainingAfter));
        $stmt->bindValue(':created_by_user_id', $actor['user_id'] ?? null, isset($actor['user_id']) ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $createdByName = $this->normalizeNullableString($actor['user_name'] ?? ($actor['name'] ?? null));
        $stmt->bindValue(':created_by_name', $createdByName, $createdByName !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->execute();
    }

    private function createOrIncreaseDebt(
        int $personnelId,
        string $debtType,
        float $principalAmount,
        float $recurringAmount,
        ?string $startPeriod,
        ?int $sourceRequestId,
        string $note,
        array $actor = []
    ): int {
        $startedTransaction = false;

        $debtType = $this->normalizeDebtType($debtType);
        if ($debtType === '' || $personnelId <= 0) {
            return 0;
        }

        $principalAmount = $this->normalizeDecimal($principalAmount);
        $recurringAmount = $this->normalizeDecimal($recurringAmount);
        if ($principalAmount <= 0) {
            return 0;
        }
        if ($recurringAmount < 0) {
            $recurringAmount = 0.0;
        }

        try {
            if (!$this->pdo->inTransaction()) {
                $this->pdo->beginTransaction();
                $startedTransaction = true;
            }

            $existing = $this->getOpenDebtForUpdate($personnelId, $debtType);
            if ($existing) {
                $id = (int) ($existing['id'] ?? 0);
                if ($id <= 0) {
                    if ($startedTransaction) {
                        $this->pdo->rollBack();
                    }
                    return 0;
                }

                $original = $this->normalizeDecimal($existing['original_amount'] ?? 0);
                $remaining = $this->normalizeDecimal($existing['remaining_amount'] ?? 0);
                $newOriginal = $original + $principalAmount;
                $newRemaining = $remaining + $principalAmount;

                $existingRecurring = $this->normalizeDecimal($existing['recurring_amount'] ?? 0);
                $finalRecurring = $existingRecurring;
                if ($finalRecurring <= 0 && $recurringAmount > 0) {
                    $finalRecurring = $recurringAmount;
                }

                $stmt = $this->pdo->prepare('
                    UPDATE rh_employee_debts
                    SET original_amount = :original_amount,
                        remaining_amount = :remaining_amount,
                        recurring_amount = :recurring_amount,
                        start_period = COALESCE(start_period, :start_period),
                        source_request_id = COALESCE(source_request_id, :source_request_id),
                        notes = TRIM(CONCAT(COALESCE(notes, \'\'), CASE WHEN COALESCE(notes, \'\') = \'\' THEN \'\' ELSE "\n" END, :note)),
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id
                    LIMIT 1
                ');
                $stmt->bindValue(':original_amount', $newOriginal);
                $stmt->bindValue(':remaining_amount', $newRemaining);
                $stmt->bindValue(':recurring_amount', $finalRecurring);
                $stmt->bindValue(':start_period', $startPeriod !== null ? $startPeriod : null, $startPeriod !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $stmt->bindValue(':source_request_id', $sourceRequestId !== null && $sourceRequestId > 0 ? $sourceRequestId : null, $sourceRequestId !== null && $sourceRequestId > 0 ? PDO::PARAM_INT : PDO::PARAM_NULL);
                $stmt->bindValue(':note', $note);
                $stmt->bindValue(':id', $id, PDO::PARAM_INT);
                $stmt->execute();

                if ($startedTransaction) {
                    $this->pdo->commit();
                }

                return $id;
            }

            $sql = '
                INSERT INTO rh_employee_debts (
                    personnel_id,
                    debt_type,
                    original_amount,
                    remaining_amount,
                    recurring_amount,
                    start_period,
                    source_request_id,
                    notes,
                    created_by_user_id,
                    created_by_name,
                    created_at
                ) VALUES (
                    :personnel_id,
                    :debt_type,
                    :original_amount,
                    :remaining_amount,
                    :recurring_amount,
                    :start_period,
                    :source_request_id,
                    :notes,
                    :created_by_user_id,
                    :created_by_name,
                    NOW()
                )
            ';

            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':personnel_id', $personnelId, PDO::PARAM_INT);
            $stmt->bindValue(':debt_type', $debtType);
            $stmt->bindValue(':original_amount', $principalAmount);
            $stmt->bindValue(':remaining_amount', $principalAmount);
            $stmt->bindValue(':recurring_amount', $recurringAmount);
            $stmt->bindValue(':start_period', $startPeriod !== null ? $startPeriod : null, $startPeriod !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindValue(':source_request_id', $sourceRequestId !== null && $sourceRequestId > 0 ? $sourceRequestId : null, $sourceRequestId !== null && $sourceRequestId > 0 ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $stmt->bindValue(':notes', trim($note) !== '' ? $note : null, trim($note) !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindValue(':created_by_user_id', $actor['user_id'] ?? null, isset($actor['user_id']) ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $createdByName = $this->normalizeNullableString($actor['user_name'] ?? ($actor['name'] ?? null));
            $stmt->bindValue(':created_by_name', $createdByName, $createdByName !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->execute();
            $id = (int) $this->pdo->lastInsertId();

            if ($startedTransaction) {
                $this->pdo->commit();
            }

            return $id;
        } catch (Throwable $e) {
            if ($startedTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function resolveStartPeriodForLoan(array $payload, array $request): string
    {
        $candidate = trim((string) ($payload['date_debut'] ?? ''));
        if ($candidate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $candidate)) {
            return substr($candidate, 0, 7);
        }

        $decidedAt = trim((string) ($request['decided_at'] ?? ''));
        if ($decidedAt !== '' && preg_match('/^\d{4}-\d{2}-\d{2}/', $decidedAt)) {
            $dt = new DateTimeImmutable(substr($decidedAt, 0, 10));
        } else {
            $dt = new DateTimeImmutable('now');
        }

        return $dt->modify('first day of next month')->format('Y-m');
    }

    private function resolveStartPeriodForAdvance(array $request): string
    {
        $decidedAt = trim((string) ($request['decided_at'] ?? ''));
        if ($decidedAt !== '' && preg_match('/^\d{4}-\d{2}-\d{2}/', $decidedAt)) {
            $dt = new DateTimeImmutable(substr($decidedAt, 0, 10));
        } else {
            $dt = new DateTimeImmutable('now');
        }

        return $dt->modify('first day of next month')->format('Y-m');
    }

    public function getPayrollRecord(int $recordId): ?array
    {
        if ($recordId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT * FROM rh_payroll_records WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $recordId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
        $row['payload'] = is_array($payload) ? $payload : [];
        return $row;
    }

    public function getPayrollHistory(?int $personnelId = null, int $limit = 12): array
    {
        $sql = '
            SELECT
                r.id,
                r.personnel_id,
                r.payroll_period,
                r.net_salary,
                r.gross_taxable,
                r.updated_at,
                p.matricule_personnel_soignant AS matricule,
                p.nom_personnel_soignant AS full_name
            FROM rh_payroll_records r
            INNER JOIN personnel p ON p.id_personnel_soignant = r.personnel_id
        ';

        if ($personnelId !== null && $personnelId > 0) {
            $sql .= ' WHERE r.personnel_id = :personnel_id ';
        }
        $sql .= ' ORDER BY r.payroll_period DESC, r.updated_at DESC LIMIT :limit';

        $stmt = $this->pdo->prepare($sql);
        if ($personnelId !== null && $personnelId > 0) {
            $stmt->bindValue(':personnel_id', $personnelId, PDO::PARAM_INT);
        }
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getLatestPayrollRecord(int $personnelId): ?array
    {
        if ($personnelId <= 0) {
            return null;
        }

        return $this->getLatestPayrollForEmployee($personnelId);
    }

    private function getEmployeeSnapshot(int $personnelId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                p.id_personnel_soignant AS id,
                p.matricule_personnel_soignant AS matricule,
                p.nom_personnel_soignant AS full_name,
                p.email_personnel_soignant AS email,
                p.num_cnps AS cnps_number,
                p.date_recrutement AS hire_date,
                p.date_prise_service AS start_date,
                p.date_sortie AS end_date,
                p.statut_pers_soignant_code AS status_code,
                p.num_cni AS cni_number,
                p.tel_personnel_soignant AS phone,
                COALESCE(NULLIF(TRIM(s.lib_service), ''), 'Service non renseigne') AS service_label,
                COALESCE(NULLIF(TRIM(f.lib_fonction), ''), 'Fonction non renseignee') AS fonction_label,
                CASE
                    WHEN p.statut_pers_soignant_code = 1 THEN 'CDD'
                    WHEN p.statut_pers_soignant_code = 3 THEN 'Vacataire'
                    WHEN p.statut_pers_soignant_code = 4 THEN 'Stage de perfectionnement'
                    ELSE CONCAT('Statut ', COALESCE(p.statut_pers_soignant_code, 0))
                END AS statut_label
            FROM personnel p
            LEFT JOIN service s ON s.id_service = p.service_id
            LEFT JOIN fonction f ON f.id_fonction = p.fonction_id
            WHERE p.id_personnel_soignant = :personnel_id
            LIMIT 1"
        );
        $stmt->bindValue(':personnel_id', $personnelId, PDO::PARAM_INT);
        $stmt->execute();
        $employee = $stmt->fetch(PDO::FETCH_ASSOC);
        return $employee ?: null;
    }

    private function getLatestPayrollForEmployee(int $personnelId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM rh_payroll_records WHERE personnel_id = :personnel_id ORDER BY payroll_period DESC, updated_at DESC LIMIT 1');
        $stmt->bindValue(':personnel_id', $personnelId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $row['payload'] = $this->decodePayrollPayload($row['payload_json'] ?? null);
        }
        return $row ?: null;
    }

    private function decodePayrollPayload(?string $payload): array
    {
        if ($payload === null || trim($payload) === '') {
            return [];
        }

        $decoded = json_decode($payload, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function computeAttendanceCompensation(array $summary, array $rules): array
    {
        // Pour la paie, on retient les jours ouvrés (lun->ven, fériés exclus). Les week-ends ne doivent pas
        // augmenter les jours payés; ils sont gérés via les heures supplémentaires.
        $statusCounts = $summary['working_status_counts'] ?? ($summary['status_counts'] ?? array_fill_keys(array_keys($this->attendanceStatuses), 0));
        $statusDays = $summary['working_status_days'] ?? ($summary['status_days'] ?? array_fill_keys(array_keys($this->attendanceStatuses), 0.0));

        $entriesCount = (int) ($summary['working_entries_count'] ?? ($summary['entries_count'] ?? 0));
        if ($entriesCount === 0) {
            return [
                'paid_days' => 0.0,
                'unpaid_days' => round($rules['reference_working_days'], 2),
                'present_paid_days' => 0.0,
                'mission_paid_days' => 0.0,
                'conge_paid_days' => 0.0,
                'half_day_paid_days' => 0.0,
                'absence_paid_days' => 0.0,
                'maladie_paid_days' => 0.0,
                'repos_paid_days' => 0.0,
            ];
        }

        $presentPaidDays = (float) ($statusDays['present'] ?? 0.0);
        $missionPaidDays = (float) ($statusDays['mission'] ?? 0.0) * $rules['mission_paid_rate'];
        $congePaidDays = (float) ($statusDays['conge'] ?? 0.0) * $rules['conge_paid_rate'];
        $halfDayPaidDays = (float) ($statusCounts['half_day'] ?? 0) * $rules['half_day_paid_rate'];
        $absencePaidDays = (float) ($statusCounts['absence'] ?? 0) * $rules['absence_paid_rate'];
        $maladiePaidDays = (float) ($statusCounts['maladie'] ?? 0) * $rules['maladie_paid_rate'];
        $reposPaidDays = (float) ($statusCounts['repos'] ?? 0) * $rules['repos_paid_rate'];

        $paidDays = min(
            $rules['reference_working_days'],
            round($presentPaidDays + $missionPaidDays + $congePaidDays + $halfDayPaidDays + $absencePaidDays + $maladiePaidDays + $reposPaidDays, 2)
        );

        return [
            'paid_days' => round($paidDays, 2),
            'unpaid_days' => round(max(0, $rules['reference_working_days'] - $paidDays), 2),
            'present_paid_days' => round($presentPaidDays, 2),
            'mission_paid_days' => round($missionPaidDays, 2),
            'conge_paid_days' => round($congePaidDays, 2),
            'half_day_paid_days' => round($halfDayPaidDays, 2),
            'absence_paid_days' => round($absencePaidDays, 2),
            'maladie_paid_days' => round($maladiePaidDays, 2),
            'repos_paid_days' => round($reposPaidDays, 2),
        ];
    }

    private function seedContractRuleDefaults(array $defaults): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO rh_payroll_contract_rules (
                contract_code,
                contract_label,
                reference_working_days,
                hours_per_day,
                overtime_multiplier,
                mission_paid_rate,
                conge_paid_rate,
                half_day_paid_rate,
                absence_paid_rate,
                maladie_paid_rate,
                repos_paid_rate,
                precarity_rate
            ) VALUES (
                :contract_code,
                :contract_label,
                :reference_working_days,
                :hours_per_day,
                :overtime_multiplier,
                :mission_paid_rate,
                :conge_paid_rate,
                :half_day_paid_rate,
                :absence_paid_rate,
                :maladie_paid_rate,
                :repos_paid_rate,
                :precarity_rate
            )'
        );

        foreach ($defaults as $contractCode => $defaultRule) {
            $stmt->bindValue(':contract_code', $contractCode);
            $stmt->bindValue(':contract_label', $defaultRule['label']);
            $stmt->bindValue(':reference_working_days', $defaultRule['reference_working_days']);
            $stmt->bindValue(':hours_per_day', $defaultRule['hours_per_day']);
            $stmt->bindValue(':overtime_multiplier', $defaultRule['overtime_multiplier']);
            $stmt->bindValue(':mission_paid_rate', $defaultRule['mission_paid_rate']);
            $stmt->bindValue(':conge_paid_rate', $defaultRule['conge_paid_rate']);
            $stmt->bindValue(':half_day_paid_rate', $defaultRule['half_day_paid_rate']);
            $stmt->bindValue(':absence_paid_rate', $defaultRule['absence_paid_rate']);
            $stmt->bindValue(':maladie_paid_rate', $defaultRule['maladie_paid_rate']);
            $stmt->bindValue(':repos_paid_rate', $defaultRule['repos_paid_rate']);
            $stmt->bindValue(':precarity_rate', $defaultRule['precarity_rate']);
            $stmt->execute();
        }
    }

    private function computeEstimatedIgr(float $grossTaxable, float $fiscalParts, float $socialDeductions): float
    {
        $taxableBase = max(0, $grossTaxable - $socialDeductions);
        $quotient = $taxableBase / max(1, $fiscalParts);

        if ($quotient <= 75000) {
            $rate = 0.0;
        } elseif ($quotient <= 130000) {
            $rate = 0.10;
        } elseif ($quotient <= 220000) {
            $rate = 0.15;
        } elseif ($quotient <= 350000) {
            $rate = 0.20;
        } else {
            $rate = 0.25;
        }

        return round($quotient * $rate * max(1, $fiscalParts), 2);
    }

    private function resolveContractCode(array $employee): string
    {
        $statusCode = (int) ($employee['status_code'] ?? 0);
        if ($statusCode === 1) {
            return 'CDD';
        }
        if ($statusCode === 4) {
            return 'STAGE';
        }
        if ($statusCode === 3) {
            return 'VACATAIRE';
        }
        return 'AUTRE';
    }

    private function normalizeContractCode(string $value, bool $fallbackToOther = true): string
    {
        $value = strtoupper(trim($value));
        $value = preg_replace('/[^A-Z0-9_\\-]+/', '_', $value) ?: '';
        $value = trim($value, '_-');
        if ($value === '') {
            return $fallbackToOther ? 'AUTRE' : '';
        }

        return substr($value, 0, 30);
    }

    private function generateContractCode(string $label): string
    {
        $base = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label) ?: $label;
        $base = strtoupper($base);
        $base = preg_replace('/[^A-Z0-9]+/', '_', $base) ?: 'CONTRAT';
        $base = trim($base, '_');
        if ($base === '') {
            $base = 'CONTRAT';
        }
        if (!preg_match('/^[A-Z]/', $base)) {
            $base = 'CONTRAT_' . $base;
        }
        $base = substr($base, 0, 24);

        $candidate = $base;
        $suffix = 2;
        while ($this->contractCodeExists($candidate)) {
            $suffixText = '_' . $suffix;
            $candidate = substr($base, 0, 30 - strlen($suffixText)) . $suffixText;
            $suffix++;
        }

        return $candidate;
    }

    private function contractCodeExists(string $contractCode): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM rh_payroll_contract_rules WHERE contract_code = :contract_code');
        $stmt->bindValue(':contract_code', $contractCode);
        $stmt->execute();

        return (int) $stmt->fetchColumn() > 0;
    }

    private function normalizeDate(?string $value): ?string
    {
        $value = $this->normalizeNullableString($value);
        if ($value === null) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);
        return $date ? $date->format('Y-m-d') : null;
    }

    private function normalizeTime($value): ?string
    {
        $value = $this->normalizeNullableString($value);
        if ($value === null) {
            return null;
        }

        if (preg_match('/^\d{2}:\d{2}$/', $value)) {
            return $value . ':00';
        }

        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $value)) {
            return $value;
        }

        return null;
    }

    private function normalizeDecimal($value): float
    {
        if (is_string($value)) {
            $value = str_replace([' ', ','], ['', '.'], trim($value));
        }

        return round((float) $value, 2);
    }

    private function normalizeRuleRate($value): float
    {
        return min(1.0, max(0.0, $this->normalizeDecimal($value)));
    }

    private function normalizeNullableString($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function getPeriodBounds(string $period): array
    {
        if (!preg_match('/^(\d{4})-(\d{2})$/', $period, $matches)) {
            $period = date('Y-m');
            preg_match('/^(\d{4})-(\d{2})$/', $period, $matches);
        }

        $year = (int) $matches[1];
        $month = (int) $matches[2];
        $start = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $end = $start->modify('last day of this month');
        return [$start->format('Y-m-d'), $end->format('Y-m-d')];
    }

    private function emptyAttendanceSummary(): array
    {
        return [
            'period' => date('Y-m'),
            'start_date' => date('Y-m-01'),
            'end_date' => date('Y-m-t'),
            'entries_count' => 0,
            'worked_days' => 0.0,
            'overtime_hours' => 0.0,
            'status_counts' => array_fill_keys(array_keys($this->attendanceStatuses), 0),
            'status_days' => array_fill_keys(array_keys($this->attendanceStatuses), 0.0),
            'working_entries_count' => 0,
            'working_worked_days' => 0.0,
            'working_status_counts' => array_fill_keys(array_keys($this->attendanceStatuses), 0),
            'working_status_days' => array_fill_keys(array_keys($this->attendanceStatuses), 0.0),
            'reference_working_days' => 0,
            'calendar_days' => (int) date('t'),
        ];
    }

    private function ensureSupportTables(): void
    {
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS rh_holidays (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                holiday_date DATE NOT NULL,
                label VARCHAR(150) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_rh_holiday_date (holiday_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

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
            CREATE TABLE IF NOT EXISTS rh_payroll_records (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                personnel_id INT NOT NULL,
                payroll_period VARCHAR(7) NOT NULL,
                category_code VARCHAR(30) DEFAULT NULL,
                contract_code VARCHAR(30) DEFAULT NULL,
                currency_code VARCHAR(10) NOT NULL DEFAULT "FCFA",
                attendance_days DECIMAL(6,2) NOT NULL DEFAULT 30.00,
                salary_category DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                over_salary DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                seniority_bonus DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                other_bonus DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                paid_leave_bonus DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                gratification DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                precarity_bonus DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                tax_is_rate DECIMAL(8,2) NOT NULL DEFAULT 0.00,
                cnps_employee_rate DECIMAL(8,2) NOT NULL DEFAULT 0.00,
                cnps_employer_rate DECIMAL(8,2) NOT NULL DEFAULT 0.00,
                family_benefit_rate DECIMAL(8,2) NOT NULL DEFAULT 0.00,
                accident_rate DECIMAL(8,2) NOT NULL DEFAULT 0.00,
                apprenticeship_tax_rate DECIMAL(8,2) NOT NULL DEFAULT 0.00,
                professional_training_tax_rate DECIMAL(8,2) NOT NULL DEFAULT 0.00,
                formation_tax_rate DECIMAL(8,2) NOT NULL DEFAULT 0.00,
                fiscal_parts DECIMAL(8,2) NOT NULL DEFAULT 1.00,
                manual_igr DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                other_deductions DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                transport_bonus DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                health_insurance DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                advance_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                rounding_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                gross_taxable DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                total_employee_deductions DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                total_employer_contributions DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                net_salary DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                observations TEXT DEFAULT NULL,
                payload_json LONGTEXT DEFAULT NULL,
                created_by_user_id INT DEFAULT NULL,
                created_by_name VARCHAR(150) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_rh_payroll_personnel_period (personnel_id, payroll_period),
                KEY idx_rh_payroll_period (payroll_period)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS rh_payroll_contract_rules (
                contract_code VARCHAR(30) NOT NULL PRIMARY KEY,
                contract_label VARCHAR(190) DEFAULT NULL,
                reference_working_days DECIMAL(6,2) NOT NULL DEFAULT 30.00,
                hours_per_day DECIMAL(5,2) NOT NULL DEFAULT 8.00,
                overtime_multiplier DECIMAL(6,2) NOT NULL DEFAULT 1.00,
                mission_paid_rate DECIMAL(5,2) NOT NULL DEFAULT 1.00,
                conge_paid_rate DECIMAL(5,2) NOT NULL DEFAULT 1.00,
                half_day_paid_rate DECIMAL(5,2) NOT NULL DEFAULT 0.50,
                absence_paid_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00,
                maladie_paid_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00,
                repos_paid_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00,
                precarity_rate DECIMAL(6,2) NOT NULL DEFAULT 0.00,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                updated_by_user_id INT DEFAULT NULL,
                updated_by_name VARCHAR(150) DEFAULT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->ensureColumn('rh_payroll_contract_rules', 'contract_label', 'ALTER TABLE rh_payroll_contract_rules ADD COLUMN contract_label VARCHAR(190) DEFAULT NULL AFTER contract_code');
        $this->ensureColumn('rh_payroll_contract_rules', 'is_active', 'ALTER TABLE rh_payroll_contract_rules ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER precarity_rate');
        $this->normalizeDefaultContractLabels();

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS rh_employee_debts (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                personnel_id INT NOT NULL,
                debt_type VARCHAR(30) NOT NULL,
                original_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                remaining_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                recurring_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                start_period VARCHAR(7) DEFAULT NULL,
                source_request_id INT DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                is_closed TINYINT(1) NOT NULL DEFAULT 0,
                closed_at DATETIME DEFAULT NULL,
                created_by_user_id INT DEFAULT NULL,
                created_by_name VARCHAR(150) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_rh_debt_active (personnel_id, debt_type, is_closed),
                KEY idx_rh_debt_personnel (personnel_id),
                KEY idx_rh_debt_type (debt_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS rh_employee_debt_payments (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                debt_id INT UNSIGNED NOT NULL,
                payroll_period VARCHAR(7) NOT NULL,
                payroll_record_id INT UNSIGNED DEFAULT NULL,
                declared_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                applied_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                remaining_after DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                created_by_user_id INT DEFAULT NULL,
                created_by_name VARCHAR(150) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_rh_debt_payment_period (debt_id, payroll_period),
                KEY idx_rh_debt_payment_period (payroll_period)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    private function ensureColumn(string $table, string $column, string $alterSql): void
    {
        $stmt = $this->pdo->prepare("SHOW COLUMNS FROM {$table} LIKE :column_name");
        $stmt->bindValue(':column_name', $column);
        $stmt->execute();
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            $this->pdo->exec($alterSql);
        }
    }

    private function normalizeDefaultContractLabels(): void
    {
        $this->pdo->exec("
            UPDATE rh_payroll_contract_rules
            SET contract_label = 'CDD'
            WHERE contract_code = 'CDD'
              AND (contract_label IS NULL OR TRIM(contract_label) = '' OR contract_label = 'CDD chantier')
        ");
    }

    private function getHolidayDatesBetween(string $startDate, string $endDate): array
    {
        if ($startDate > $endDate) {
            return [];
        }

        $stmt = $this->pdo->prepare('SELECT holiday_date FROM rh_holidays WHERE holiday_date BETWEEN :start_date AND :end_date');
        $stmt->bindValue(':start_date', $startDate);
        $stmt->bindValue(':end_date', $endDate);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $dates = [];
        foreach ($rows as $row) {
            $date = $this->normalizeDate((string) ($row['holiday_date'] ?? ''));
            if ($date !== null) {
                $dates[$date] = true;
            }
        }
        return $dates;
    }

    private function getPersonnelPayrollBounds(int $personnelId, string $periodStartDate, string $periodEndDate): array
    {
        $startDate = $periodStartDate;
        $endDate = $periodEndDate;

        try {
            $stmt = $this->pdo->prepare('
                SELECT
                    COALESCE(NULLIF(date_prise_service, "0000-00-00"), NULLIF(date_recrutement, "0000-00-00")) AS start_date,
                    NULLIF(date_sortie, "0000-00-00") AS end_date
                FROM personnel
                WHERE id_personnel_soignant = :personnel_id
                LIMIT 1
            ');
            $stmt->bindValue(':personnel_id', $personnelId, PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $row = [];
        }

        $employeeStart = $this->normalizeDate((string) ($row['start_date'] ?? ''));
        if ($employeeStart !== null && $employeeStart > $startDate) {
            $startDate = $employeeStart;
        }

        $employeeEnd = $this->normalizeDate((string) ($row['end_date'] ?? ''));
        if ($employeeEnd !== null && $employeeEnd < $endDate) {
            $endDate = $employeeEnd;
        }

        return [$startDate, $endDate];
    }

    private function isWeekendDate(DateTimeImmutable $dt): bool
    {
        $dow = (int) $dt->format('N');
        return $dow >= 6;
    }

    private function isWorkingDate(string $date, ?array $holidayDates = null): bool
    {
        $normalized = $this->normalizeDate($date);
        if ($normalized === null) {
            return false;
        }

        $dt = new DateTimeImmutable($normalized);
        if ($this->isWeekendDate($dt)) {
            return false;
        }

        if ($holidayDates !== null && isset($holidayDates[$normalized])) {
            return false;
        }

        return true;
    }

    private function resolveAttendanceStatusForDate(string $date, ?array $row, ?array $holidayDates = null): string
    {
        if (!$this->isWorkingDate($date, $holidayDates)) {
            return $this->hasAttendanceTimes($row ?? []) ? 'present' : 'repos';
        }

        $status = (string) ($row['attendance_status'] ?? 'absence');
        if (!isset($this->attendanceStatuses[$status])) {
            $status = 'present';
        }

        if ($status === 'repos') {
            return $this->hasAttendanceActivity($row ?? []) ? 'present' : 'absence';
        }

        return $status;
    }

    private function hasAttendanceTimes(array $row): bool
    {
        return $this->normalizeTime($row['check_in_time'] ?? null) !== null
            || $this->normalizeTime($row['check_out_time'] ?? null) !== null;
    }

    private function hasAttendanceActivity(array $row): bool
    {
        return $this->hasAttendanceTimes($row)
            || (float) ($row['worked_days'] ?? 0) > 0
            || (float) ($row['overtime_hours'] ?? 0) > 0;
    }

    private function resolveAttendanceOvertimeHours(array $row, bool $isWorkingDate): float
    {
        $storedOvertime = (float) ($row['overtime_hours'] ?? 0);
        if ($isWorkingDate) {
            return $storedOvertime;
        }

        $in = $this->normalizeTime($row['check_in_time'] ?? null);
        $out = $this->normalizeTime($row['check_out_time'] ?? null);
        if ($in === null || $out === null) {
            return $storedOvertime;
        }

        $inMinutes = $this->timeToMinutes($in);
        $outMinutes = $this->timeToMinutes($out);
        if ($inMinutes === null || $outMinutes === null) {
            return $storedOvertime;
        }
        if ($outMinutes < $inMinutes) {
            $outMinutes += 1440;
        }

        $workedHours = max(0, ($outMinutes - $inMinutes) / 60);
        return round($workedHours * 4) / 4;
    }

    private function timeToMinutes(string $time): ?int
    {
        if (!preg_match('/^(\d{2}):(\d{2})/', $time, $matches)) {
            return null;
        }

        return ((int) $matches[1] * 60) + (int) $matches[2];
    }

    private function listWorkingDatesBetween(string $startDate, string $endDate, ?array $holidayDates = null): array
    {
        $startDate = $this->normalizeDate($startDate) ?? $startDate;
        $endDate = $this->normalizeDate($endDate) ?? $endDate;

        if ($holidayDates === null) {
            $holidayDates = $this->getHolidayDatesBetween($startDate, $endDate);
        }

        $dates = [];
        $cursor = new DateTimeImmutable($startDate);
        $end = new DateTimeImmutable($endDate);
        if ($cursor > $end) {
            return [];
        }

        while ($cursor <= $end) {
            $date = $cursor->format('Y-m-d');
            if ($this->isWorkingDate($date, $holidayDates)) {
                $dates[] = $date;
            }
            $cursor = $cursor->modify('+1 day');
        }
        return $dates;
    }

    private function countWorkingDaysBetween(string $startDate, string $endDate, ?array $holidayDates = null): int
    {
        return count($this->listWorkingDatesBetween($startDate, $endDate, $holidayDates));
    }
}

