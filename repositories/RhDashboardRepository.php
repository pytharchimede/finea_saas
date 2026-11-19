<?php

require_once __DIR__ . '/../decaissement/model/PersonnelDirectory.php';
require_once __DIR__ . '/../model/EmployeeLegalRequestManager.php';
require_once __DIR__ . '/../modules/payroll/bootstrap.php';

class RhDashboardRepository
{
    private PDO $pdo;
    private PersonnelDirectory $personnelDirectory;
    private EmployeeLegalRequestManager $legalRequestManager;
    private ?array $availableTables = null;
    private array $availableColumns = [];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->personnelDirectory = new PersonnelDirectory($pdo);
        $this->legalRequestManager = new EmployeeLegalRequestManager($pdo);
    }

    public function getPersonnelDashboardData(): array
    {
        return $this->personnelDirectory->getDashboardData();
    }

    public function getPendingLegalRequestCounts(): array
    {
        if (!$this->tableExists('employee_legal_requests')) {
            return [
                'total' => 0,
                'salary_claims' => 0,
                'by_status' => [],
                'by_type' => [],
            ];
        }

        $byStatus = $this->legalRequestManager->countRequestsForTeam('rh');
        $total = (int) ($byStatus['submitted'] ?? 0);

        return [
            'total' => $total,
            'salary_claims' => $this->countPendingSalaryClaims(),
            'by_status' => $byStatus,
            'by_type' => $this->countPendingLegalRequestsByType(),
        ];
    }

    public function getRecentPendingLegalRequests(int $limit = 5): array
    {
        if (!$this->tableExists('employee_legal_requests')) {
            return [];
        }

        $limit = max(1, min(12, $limit));
        $rows = $this->legalRequestManager->listRequestsForTeam('rh', ['submitted'], $limit);
        $types = $this->legalRequestManager->getRequestTypes();

        return array_map(static function (array $row) use ($types): array {
            $type = (string) ($row['request_type'] ?? '');

            return [
                'id' => (int) ($row['id'] ?? 0),
                'label' => (string) ($types[$type]['label'] ?? ucfirst(str_replace('_', ' ', $type))),
                'requester' => (string) ($row['requester_name'] ?? 'Demandeur non renseigne'),
                'submitted_at' => (string) ($row['submitted_at'] ?? ''),
                'stage' => (string) (\EmployeeLegalRequestManager::getRequestStatusMeta($row)['label'] ?? 'En attente'),
            ];
        }, $rows);
    }

    public function getExplanationSummary(): array
    {
        if (!$this->tableExists('rh_explanation_requests')) {
            return [
                'open' => 0,
                'pending_response' => 0,
                'overdue' => 0,
                'answered' => 0,
            ];
        }

        $stmt = $this->pdo->query("
            SELECT
                SUM(CASE WHEN status IN ('pending_response', 'complement_requested', 'answered') THEN 1 ELSE 0 END) AS open_count,
                SUM(CASE WHEN status IN ('pending_response', 'complement_requested') THEN 1 ELSE 0 END) AS pending_count,
                SUM(CASE WHEN status = 'answered' THEN 1 ELSE 0 END) AS answered_count,
                SUM(CASE WHEN status IN ('pending_response', 'complement_requested') AND due_at IS NOT NULL AND due_at < NOW() THEN 1 ELSE 0 END) AS overdue_count
            FROM rh_explanation_requests
        ");
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'open' => (int) ($row['open_count'] ?? 0),
            'pending_response' => (int) ($row['pending_count'] ?? 0),
            'overdue' => (int) ($row['overdue_count'] ?? 0),
            'answered' => (int) ($row['answered_count'] ?? 0),
        ];
    }

    public function getLeaveOpeningSummary(): array
    {
        if (!$this->tableExists('personnel') || !$this->tableExists('rh_leave_opening_balance')) {
            return ['missing' => 0, 'configured' => 0];
        }

        $configured = (int) $this->pdo->query('SELECT COUNT(*) FROM rh_leave_opening_balance')->fetchColumn();
        $stmt = $this->pdo->query("
            SELECT COUNT(*)
            FROM personnel p
            LEFT JOIN rh_leave_opening_balance b ON b.personnel_id = p.id_personnel_soignant
            WHERE (p.date_sortie IS NULL OR p.date_sortie = '0000-00-00')
              AND b.id IS NULL
        ");

        return [
            'missing' => (int) ($stmt->fetchColumn() ?: 0),
            'configured' => $configured,
        ];
    }

    public function getContractSetupSummary(): array
    {
        if (!$this->tableExists('personnel')) {
            return ['missing' => 0, 'configured' => 0];
        }

        $contracts = new EmployeeContractRepository($this->pdo);
        $configuredStmt = $this->pdo->query("
            SELECT COUNT(DISTINCT personnel_id)
            FROM employee_contracts
            WHERE active = 1
              AND status = 'active'
        ");

        return [
            'missing' => $contracts->countActiveEmployeesWithoutContract(),
            'configured' => (int) ($configuredStmt->fetchColumn() ?: 0),
        ];
    }

    public function getJobDescriptionSummary(): array
    {
        if (!$this->tableExists('personnel')) {
            return ['missing' => 0, 'configured' => 0];
        }

        $hasJobDescriptionTable = $this->tableExists('rh_fiches_poste');
        $join = $hasJobDescriptionTable
            ? "LEFT JOIN rh_fiches_poste fp ON fp.fonction_id = p.fonction_id AND fp.statut = 'active'"
            : '';
        $missingCondition = $hasJobDescriptionTable ? 'AND fp.id IS NULL' : '';
        $configuredCondition = $hasJobDescriptionTable ? 'AND fp.id IS NOT NULL' : 'AND 1 = 0';
        $commonWhere = "(p.date_sortie IS NULL OR p.date_sortie = '0000-00-00')
            AND COALESCE(NULLIF(p.personnel_category, ''), 'interne') = 'interne'
            AND p.fonction_id IS NOT NULL";

        $missing = (int) $this->pdo->query("SELECT COUNT(DISTINCT p.id_personnel_soignant)
            FROM personnel p {$join} WHERE {$commonWhere} {$missingCondition}")->fetchColumn();
        $configured = (int) $this->pdo->query("SELECT COUNT(DISTINCT p.id_personnel_soignant)
            FROM personnel p {$join} WHERE {$commonWhere} {$configuredCondition}")->fetchColumn();

        return ['missing' => $missing, 'configured' => $configured];
    }

    public function getOperationalAnalytics(?string $period = null): array
    {
        $period = is_string($period) && preg_match('/^\d{4}-\d{2}$/', $period) ? $period : date('Y-m');
        [$startDate, $endDate] = $this->periodBounds($period);

        return $this->getOperationalAnalyticsForRange($startDate, $endDate, $period);
    }

    public function getOperationalAnalyticsForRange(string $startDate, string $endDate, ?string $label = null, array $filters = []): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
            $startDate = date('Y-m-01');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
            $endDate = date('Y-m-t');
        }
        if ($startDate > $endDate) {
            [$startDate, $endDate] = [$endDate, $startDate];
        }

        $periodLabel = $label ?: ($startDate === $endDate ? $startDate : ($startDate . ' au ' . $endDate));
        $trendPeriod = substr($endDate, 0, 7);
        $personnelIds = $this->resolveAnalyticsPersonnelIds($filters);

        return [
            'period' => $periodLabel,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'attendance' => $this->getAttendanceAnalytics($trendPeriod, $startDate, $endDate, $personnelIds),
            'requests' => $this->getRhRequestAnalytics($startDate, $endDate, $personnelIds),
            'schedule' => $this->getScheduleAnalytics($startDate, $endDate, $personnelIds),
            'overtime' => $this->getOvertimeAnalytics($startDate, $endDate, $personnelIds),
            'personnel_scope' => $personnelIds,
        ];
    }

    public function getPersonnelAnalyticsBreakdown(string $startDate, string $endDate, array $filters = []): array
    {
        if (!$this->tableExists('personnel') || !$this->tableExists('rh_attendance_daily')) {
            return [];
        }

        $personnelIds = $this->resolveAnalyticsPersonnelIds($filters);
        [$personnelSql, $personnelParams] = $this->personnelFilterSql('p.id_personnel_soignant', $personnelIds);
        $lateReference = '08:40';
        $lateTolerance = 10;
        if ($this->tableExists('rh_attendance_settings')) {
            $settings = $this->pdo->query("SELECT setting_key, setting_value FROM rh_attendance_settings WHERE setting_key IN ('late_reference_time','arrival_tolerance_minutes')")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
            $lateReference = preg_match('/^\d{2}:\d{2}$/', (string) ($settings['late_reference_time'] ?? '')) ? (string) $settings['late_reference_time'] : $lateReference;
            $lateTolerance = max(0, (int) ($settings['arrival_tolerance_minutes'] ?? $lateTolerance));
        }
        $lateThreshold = (new DateTimeImmutable('2000-01-01 ' . $lateReference))->modify('+' . $lateTolerance . ' minutes')->format('H:i:s');

        $stmt = $this->pdo->prepare("
            SELECT p.id_personnel_soignant AS personnel_id,
                   COALESCE(NULLIF(TRIM(p.nom_personnel_soignant), ''), CONCAT('Personnel ', p.id_personnel_soignant)) AS employee_name,
                   COALESCE(s.lib_service, 'Non renseigné') AS service_label,
                   COALESCE(f.lib_fonction, 'Non renseignée') AS fonction_label,
                   COUNT(a.id) AS attendance_rows,
                   SUM(CASE WHEN a.attendance_status IN ('present','mission','conge') THEN 1 ELSE 0 END) AS present_rows,
                   SUM(CASE WHEN a.attendance_status IN ('absence','maladie') THEN 1 ELSE 0 END) AS absence_rows,
                   SUM(CASE WHEN a.check_in_time > :late_threshold AND a.attendance_status IN ('present','half_day') THEN 1 ELSE 0 END) AS late_rows,
                   COALESCE(SUM(a.worked_days), 0) AS worked_days,
                   COALESCE(SUM(a.overtime_hours), 0) AS overtime_hours
            FROM personnel p
            LEFT JOIN service s ON s.id_service = p.service_id
            LEFT JOIN fonction f ON f.id_fonction = p.fonction_id
            LEFT JOIN rh_attendance_daily a ON a.personnel_id = p.id_personnel_soignant AND a.attendance_date BETWEEN :start_date AND :end_date
            WHERE 1=1 {$personnelSql}
            GROUP BY p.id_personnel_soignant, employee_name, service_label, fonction_label
            ORDER BY employee_name ASC
        ");
        $stmt->execute(array_merge([':start_date' => $startDate, ':end_date' => $endDate, ':late_threshold' => $lateThreshold], $personnelParams));

        return array_map(static function (array $row): array {
            $total = (int) ($row['attendance_rows'] ?? 0);
            $present = (int) ($row['present_rows'] ?? 0);
            $absence = (int) ($row['absence_rows'] ?? 0);
            $late = (int) ($row['late_rows'] ?? 0);
            return array_merge($row, [
                'attendance_rows' => $total,
                'presence_rate' => $total > 0 ? round(($present / $total) * 100, 1) : 0.0,
                'absence_rate' => $total > 0 ? round(($absence / $total) * 100, 1) : 0.0,
                'late_rate' => $total > 0 ? round(($late / $total) * 100, 1) : 0.0,
                'worked_days' => round((float) ($row['worked_days'] ?? 0), 2),
                'overtime_hours' => round((float) ($row['overtime_hours'] ?? 0), 2),
            ]);
        }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function getIndividualOperationalPortfolio(string $startDate, string $endDate, array $personnelIds): array
    {
        $personnelIds = array_values(array_unique(array_filter(array_map('intval', $personnelIds), static fn(int $id): bool => $id > 0)));
        $empty = ['profiles' => [], 'disbursements' => [], 'disbursement_rows' => [], 'tasks' => [], 'task_rows' => [], 'meeting_tasks' => [], 'meeting_task_rows' => [], 'schedule_rows' => [], 'request_rows' => []];
        if (!$personnelIds) return $empty;

        $makeIn = static function (array $ids, string $prefix): array {
            $holders = []; $params = [];
            foreach ($ids as $i => $id) { $key = ':' . $prefix . $i; $holders[] = $key; $params[$key] = $id; }
            return [implode(',', $holders), $params];
        };
        [$personnelIn, $personnelParams] = $makeIn($personnelIds, 'portfolio_pid_');

        $profilesStmt = $this->pdo->prepare("SELECT p.id_personnel_soignant personnel_id,p.nom_personnel_soignant employee_name,p.matricule_personnel_soignant matricule,p.email_personnel_soignant email,p.tel_personnel_soignant phone,p.photo_personnel_soignant photo,(SELECT NULLIF(u.photo,'') FROM user_devis_banamur u WHERE u.personnel_id=p.id_personnel_soignant AND COALESCE(u.photo,'')<>'' ORDER BY u.id DESC LIMIT 1) user_photo,p.date_recrutement hire_date,p.date_prise_service start_date,p.date_sortie end_date,COALESCE(s.lib_service,'Non renseigné') service_label,COALESCE(f.lib_fonction,'Non renseignée') fonction_label,COALESCE(rs.label,CONCAT('Statut ',p.statut_pers_soignant_code)) status_label FROM personnel p LEFT JOIN service s ON s.id_service=p.service_id LEFT JOIN fonction f ON f.id_fonction=p.fonction_id LEFT JOIN rh_statuts rs ON rs.code=p.statut_pers_soignant_code WHERE p.id_personnel_soignant IN ({$personnelIn}) ORDER BY p.nom_personnel_soignant");
        $profilesStmt->execute($personnelParams);
        $out = $empty;
        $out['profiles'] = $profilesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $userStmt = $this->pdo->prepare("SELECT id FROM user_devis_banamur WHERE personnel_id IN ({$personnelIn})");
        $userStmt->execute($personnelParams);
        $userIds = array_values(array_filter(array_map('intval', $userStmt->fetchAll(PDO::FETCH_COLUMN) ?: []), static fn(int $id): bool => $id > 0));
        [$userIn, $userParams] = $makeIn($userIds ?: [0], 'portfolio_uid_');

        if ($this->tableExists('fiche_decaissement')) {
            $fdParams = array_merge([':portfolio_start' => $startDate, ':portfolio_end' => $endDate], $userParams);
            $fdWhere = "f.created_by_user_id IN ({$userIn}) AND DATE(f.date_creat_fiche) BETWEEN :portfolio_start AND :portfolio_end";
            $regularizationExists = '0';
            if ($this->tableExists('disbursement_regularizations')) {
                $regularizationActiveFilter = $this->columnExists('disbursement_regularizations', 'cancelled_at') ? ' AND dr.cancelled_at IS NULL' : '';
                $regularizationExists = "EXISTS(SELECT 1 FROM disbursement_regularizations dr WHERE CONVERT(dr.num_fiche USING utf8mb4)=CONVERT(f.num_fiche USING utf8mb4) COLLATE utf8mb4_unicode_ci{$regularizationActiveFilter})";
            }
            $stmt = $this->pdo->prepare("SELECT COUNT(*) emitted_count,COALESCE(SUM(f.montant_fiche),0) emitted_amount,
                SUM(CASE WHEN f.date_refuse IS NOT NULL OR COALESCE(f.secur_refus,'')<>'' THEN 1 ELSE 0 END) rejected_count,
                COALESCE(SUM(CASE WHEN f.date_refuse IS NOT NULL OR COALESCE(f.secur_refus,'')<>'' THEN f.montant_fiche ELSE 0 END),0) rejected_amount,
                SUM(CASE WHEN COALESCE(f.decaisse,0)=0 AND f.date_refuse IS NULL AND COALESCE(f.secur_refus,'')='' THEN 1 ELSE 0 END) pending_count,
                COALESCE(SUM(CASE WHEN COALESCE(f.decaisse,0)=0 AND f.date_refuse IS NULL AND COALESCE(f.secur_refus,'')='' THEN f.montant_fiche ELSE 0 END),0) pending_amount,
                SUM(CASE WHEN COALESCE(f.approuve,0)=1 THEN 1 ELSE 0 END) approved_count,
                COALESCE(SUM(CASE WHEN COALESCE(f.approuve,0)=1 THEN f.montant_fiche ELSE 0 END),0) approved_amount,
                SUM(CASE WHEN COALESCE(f.decaisse,0)=1 THEN 1 ELSE 0 END) disbursed_count,
                COALESCE(SUM(CASE WHEN COALESCE(f.decaisse,0)=1 THEN f.montant_fiche ELSE 0 END),0) disbursed_amount,
                SUM(CASE WHEN {$regularizationExists} THEN 1 ELSE 0 END) regularized_count,
                COALESCE(SUM(CASE WHEN {$regularizationExists} THEN f.montant_fiche ELSE 0 END),0) regularized_amount
                FROM fiche_decaissement f WHERE {$fdWhere}");
            $stmt->execute($fdParams);
            $out['disbursements'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $beneficiaryStmt = $this->pdo->prepare("SELECT COUNT(*) beneficiary_count,COALESCE(SUM(montant_fiche),0) beneficiary_amount FROM fiche_decaissement WHERE personnel_id IN ({$personnelIn}) AND DATE(date_creat_fiche) BETWEEN :portfolio_start AND :portfolio_end");
            $beneficiaryStmt->execute(array_merge($personnelParams, [':portfolio_start' => $startDate, ':portfolio_end' => $endDate]));
            $out['disbursements'] = array_merge($out['disbursements'], $beneficiaryStmt->fetch(PDO::FETCH_ASSOC) ?: []);

            $stmt = $this->pdo->prepare("SELECT f.num_fiche,f.designation_fiche,f.montant_fiche,f.date_creat_fiche,f.approuve,f.decaisse,f.date_refuse,CASE WHEN {$regularizationExists} THEN 1 ELSE 0 END regularized FROM fiche_decaissement f WHERE {$fdWhere} ORDER BY f.date_creat_fiche DESC LIMIT 20");
            $stmt->execute($fdParams);
            $out['disbursement_rows'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        if ($this->tableExists('comm_tasks')) {
            $stmt = $this->pdo->prepare("SELECT status,COUNT(*) total FROM comm_tasks WHERE assignee_user_id IN ({$userIn}) AND DATE(COALESCE(completed_at,deadline,created_at)) BETWEEN :portfolio_start AND :portfolio_end AND archived_at IS NULL GROUP BY status");
            $stmt->execute(array_merge($userParams, [':portfolio_start' => $startDate, ':portfolio_end' => $endDate]));
            $out['tasks'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $stmt = $this->pdo->prepare("SELECT title,status,priority,deadline,completed_at FROM comm_tasks WHERE assignee_user_id IN ({$userIn}) AND DATE(COALESCE(completed_at,deadline,created_at)) BETWEEN :portfolio_start AND :portfolio_end AND archived_at IS NULL ORDER BY COALESCE(completed_at,deadline,created_at) DESC LIMIT 20");
            $stmt->execute(array_merge($userParams, [':portfolio_start' => $startDate, ':portfolio_end' => $endDate]));
            $out['task_rows'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        if ($this->tableExists('planning_chantier_meeting_action_points')) {
            $meetingParams = $userParams;
            foreach ($userParams as $key => $value) $meetingParams[str_replace('portfolio_uid_', 'portfolio_uid_copy_', $key)] = $value;
            $meetingSql = "SELECT status,COUNT(*) total,ROUND(AVG(progress),1) average_progress FROM planning_chantier_meeting_action_points WHERE (pilot_user_id IN ({$userIn}) OR copilot_user_id IN (" . str_replace(':portfolio_uid_', ':portfolio_uid_copy_', $userIn) . ")) AND archived_at IS NULL AND DATE(COALESCE(new_deadline,old_deadline,updated_at,created_at)) BETWEEN :portfolio_start AND :portfolio_end GROUP BY status";
            $stmt = $this->pdo->prepare($meetingSql);
            $stmt->execute(array_merge($meetingParams, [':portfolio_start' => $startDate, ':portfolio_end' => $endDate]));
            $out['meeting_tasks'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $meetingDetailSql = "SELECT task_title,status,progress,COALESCE(new_deadline,old_deadline) deadline FROM planning_chantier_meeting_action_points WHERE (pilot_user_id IN ({$userIn}) OR copilot_user_id IN (" . str_replace(':portfolio_uid_', ':portfolio_uid_copy_', $userIn) . ")) AND archived_at IS NULL AND DATE(COALESCE(new_deadline,old_deadline,updated_at,created_at)) BETWEEN :portfolio_start AND :portfolio_end ORDER BY COALESCE(new_deadline,old_deadline,updated_at,created_at) DESC LIMIT 20";
            $stmt = $this->pdo->prepare($meetingDetailSql);
            $stmt->execute(array_merge($meetingParams, [':portfolio_start' => $startDate, ':portfolio_end' => $endDate]));
            $out['meeting_task_rows'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        if ($this->tableExists('employee_schedule_event')) {
            $stmt = $this->pdo->prepare("SELECT title,start_at,end_at,status_code,location,TIMESTAMPDIFF(MINUTE,start_at,end_at)/60 duration_hours FROM employee_schedule_event WHERE personnel_id IN ({$personnelIn}) AND DATE(start_at) BETWEEN :portfolio_start AND :portfolio_end ORDER BY start_at DESC LIMIT 30");
            $stmt->execute(array_merge($personnelParams, [':portfolio_start' => $startDate, ':portfolio_end' => $endDate]));
            $out['schedule_rows'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        if ($this->tableExists('employee_legal_requests')) {
            $emails = array_values(array_filter(array_map(static fn(array $row): string => trim((string) ($row['email'] ?? '')), $out['profiles'])));
            [$emailIn, $emailParams] = $makeIn($emails ?: ['__none__'], 'portfolio_email_');
            $stmt = $this->pdo->prepare("SELECT request_type,title,status,submitted_at,decided_at FROM employee_legal_requests WHERE requester_email IN ({$emailIn}) AND assigned_team='rh' AND DATE(COALESCE(decided_at,submitted_at,created_at)) BETWEEN :portfolio_start AND :portfolio_end ORDER BY COALESCE(decided_at,submitted_at,created_at) DESC LIMIT 30");
            $stmt->execute(array_merge($emailParams, [':portfolio_start' => $startDate, ':portfolio_end' => $endDate]));
            $out['request_rows'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        return $out;
    }

    private function getAttendanceAnalytics(string $period, string $startDate, string $endDate, ?array $personnelIds = null): array
    {
        $statuses = $this->attendanceStatusLabels();
        $empty = [
            'total_rows' => 0,
            'present_rows' => 0,
            'absence_rows' => 0,
            'late_rows' => 0,
            'worked_days' => 0.0,
            'overtime_hours' => 0.0,
            'presence_rate' => 0.0,
            'late_rate' => 0.0,
            'by_status' => [],
            'daily' => [],
            'monthly_trend' => [],
        ];

        if (!$this->tableExists('rh_attendance_daily')) {
            return $empty;
        }

        [$personnelSql, $personnelParams] = $this->personnelFilterSql('personnel_id', $personnelIds);
        $stmt = $this->pdo->prepare("
            SELECT
                attendance_status,
                COUNT(*) AS total,
                SUM(worked_days) AS worked_days,
                SUM(overtime_hours) AS overtime_hours,
                SUM(CASE WHEN check_in_time IS NOT NULL AND check_in_time > '08:00:00' AND attendance_status IN ('present', 'half_day') THEN 1 ELSE 0 END) AS late_count
            FROM rh_attendance_daily
            WHERE attendance_date BETWEEN :start_date AND :end_date
            {$personnelSql}
            GROUP BY attendance_status
            ORDER BY total DESC
        ");
        $stmt->execute(array_merge([':start_date' => $startDate, ':end_date' => $endDate], $personnelParams));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $totalRows = 0;
        $presentRows = 0;
        $absenceRows = 0;
        $lateRows = 0;
        $workedDays = 0.0;
        $overtimeHours = 0.0;
        $byStatus = [];

        foreach ($rows as $row) {
            $status = (string) ($row['attendance_status'] ?? '');
            $total = (int) ($row['total'] ?? 0);
            $worked = (float) ($row['worked_days'] ?? 0);
            $overtime = (float) ($row['overtime_hours'] ?? 0);
            $late = (int) ($row['late_count'] ?? 0);

            $totalRows += $total;
            $workedDays += $worked;
            $overtimeHours += $overtime;
            $lateRows += $late;

            if (in_array($status, ['present', 'mission', 'conge'], true)) {
                $presentRows += $total;
            }
            if (in_array($status, ['absence', 'maladie'], true)) {
                $absenceRows += $total;
            }

            $byStatus[] = [
                'status' => $status,
                'label' => $statuses[$status] ?? ucfirst(str_replace('_', ' ', $status)),
                'total' => $total,
                'worked_days' => round($worked, 2),
                'overtime_hours' => round($overtime, 2),
                'late_count' => $late,
            ];
        }

        return [
            'total_rows' => $totalRows,
            'present_rows' => $presentRows,
            'absence_rows' => $absenceRows,
            'late_rows' => $lateRows,
            'worked_days' => round($workedDays, 2),
            'overtime_hours' => round($overtimeHours, 2),
            'presence_rate' => $totalRows > 0 ? round(($presentRows / $totalRows) * 100, 1) : 0.0,
            'late_rate' => $totalRows > 0 ? round(($lateRows / $totalRows) * 100, 1) : 0.0,
            'by_status' => $byStatus,
            'daily' => $this->getAttendanceDailyTrend($startDate, $endDate, $personnelIds),
            'monthly_trend' => $this->getAttendanceMonthlyTrend($period, $personnelIds),
        ];
    }

    private function getAttendanceDailyTrend(string $startDate, string $endDate, ?array $personnelIds = null): array
    {
        $from = (new DateTimeImmutable($endDate))->modify('-13 days')->format('Y-m-d');
        if ($from < $startDate) {
            $from = $startDate;
        }

        [$personnelSql, $personnelParams] = $this->personnelFilterSql('personnel_id', $personnelIds);
        $stmt = $this->pdo->prepare("
            SELECT
                attendance_date,
                SUM(CASE WHEN attendance_status IN ('present', 'mission', 'conge') THEN 1 ELSE 0 END) AS present_count,
                SUM(CASE WHEN attendance_status IN ('absence', 'maladie') THEN 1 ELSE 0 END) AS absence_count,
                SUM(CASE WHEN check_in_time IS NOT NULL AND check_in_time > '08:00:00' AND attendance_status IN ('present', 'half_day') THEN 1 ELSE 0 END) AS late_count,
                SUM(overtime_hours) AS overtime_hours
            FROM rh_attendance_daily
            WHERE attendance_date BETWEEN :start_date AND :end_date
            {$personnelSql}
            GROUP BY attendance_date
            ORDER BY attendance_date ASC
        ");
        $stmt->execute(array_merge([':start_date' => $from, ':end_date' => $endDate], $personnelParams));

        return array_map(static function (array $row): array {
            $date = (string) ($row['attendance_date'] ?? '');
            return [
                'date' => $date,
                'label' => $date !== '' ? date('d/m', strtotime($date)) : '',
                'present' => (int) ($row['present_count'] ?? 0),
                'absent' => (int) ($row['absence_count'] ?? 0),
                'late' => (int) ($row['late_count'] ?? 0),
                'overtime' => round((float) ($row['overtime_hours'] ?? 0), 2),
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    private function getAttendanceMonthlyTrend(string $period, ?array $personnelIds = null): array
    {
        $end = new DateTimeImmutable($period . '-01');
        $start = $end->modify('-5 months');

        [$personnelSql, $personnelParams] = $this->personnelFilterSql('personnel_id', $personnelIds);
        $stmt = $this->pdo->prepare("
            SELECT
                DATE_FORMAT(attendance_date, '%Y-%m') AS period_key,
                COUNT(*) AS total_rows,
                SUM(CASE WHEN attendance_status IN ('present', 'mission', 'conge') THEN 1 ELSE 0 END) AS present_rows,
                SUM(CASE WHEN check_in_time IS NOT NULL AND check_in_time > '08:00:00' AND attendance_status IN ('present', 'half_day') THEN 1 ELSE 0 END) AS late_rows,
                SUM(overtime_hours) AS overtime_hours
            FROM rh_attendance_daily
            WHERE attendance_date BETWEEN :start_date AND :end_date
            {$personnelSql}
            GROUP BY period_key
        ");
        $stmt->execute(array_merge([
            ':start_date' => $start->format('Y-m-01'),
            ':end_date' => $end->modify('last day of this month')->format('Y-m-d'),
        ], $personnelParams));

        $indexed = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $indexed[(string) ($row['period_key'] ?? '')] = $row;
        }

        $out = [];
        for ($i = 0; $i < 6; $i++) {
            $month = $start->modify('+' . $i . ' months');
            $key = $month->format('Y-m');
            $row = $indexed[$key] ?? [];
            $total = (int) ($row['total_rows'] ?? 0);
            $present = (int) ($row['present_rows'] ?? 0);
            $out[] = [
                'period' => $key,
                'label' => $month->format('m/Y'),
                'presence_rate' => $total > 0 ? round(($present / $total) * 100, 1) : 0.0,
                'late_rows' => (int) ($row['late_rows'] ?? 0),
                'overtime_hours' => round((float) ($row['overtime_hours'] ?? 0), 2),
            ];
        }

        return $out;
    }

    private function getRhRequestAnalytics(string $startDate, string $endDate, ?array $personnelIds = null): array
    {
        $empty = [
            'submitted' => 0,
            'processed' => 0,
            'approved' => 0,
            'rejected' => 0,
            'cancelled' => 0,
            'by_status' => [],
            'by_type' => [],
        ];

        if (!$this->tableExists('employee_legal_requests')) {
            return $empty;
        }

        [$personnelSql, $personnelParams] = $this->personnelFilterSql('p.id_personnel_soignant', $personnelIds);
        $stmt = $this->pdo->prepare("
            SELECT status, COUNT(*) AS total
            FROM employee_legal_requests r
            LEFT JOIN personnel p ON p.email_personnel_soignant = r.requester_email
            WHERE r.assigned_team = 'rh'
              AND DATE(COALESCE(r.decided_at, r.submitted_at, r.created_at)) BETWEEN :start_date AND :end_date
              AND (r.request_type <> 'feb' OR COALESCE(r.meta_legacy_source, '') <> 'feb_legacy')
              {$personnelSql}
            GROUP BY status
        ");
        $stmt->execute(array_merge([':start_date' => $startDate, ':end_date' => $endDate], $personnelParams));
        $byStatusRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $statusLabels = [
            'submitted' => 'En attente',
            'approved' => 'Validées',
            'rejected' => 'Refusées',
            'cancelled' => 'Annulées',
        ];
        $counts = $empty;
        $byStatus = [];
        foreach ($byStatusRows as $row) {
            $status = (string) ($row['status'] ?? '');
            $total = (int) ($row['total'] ?? 0);
            if (array_key_exists($status, $counts)) {
                $counts[$status] = $total;
            }
            $byStatus[] = [
                'status' => $status,
                'label' => $statusLabels[$status] ?? ucfirst($status),
                'total' => $total,
            ];
        }
        $counts['processed'] = (int) $counts['approved'] + (int) $counts['rejected'] + (int) $counts['cancelled'];

        $stmt = $this->pdo->prepare("
            SELECT request_type, COUNT(*) AS total
            FROM employee_legal_requests r
            LEFT JOIN personnel p ON p.email_personnel_soignant = r.requester_email
            WHERE r.assigned_team = 'rh'
              AND r.status IN ('approved', 'rejected', 'cancelled')
              AND DATE(COALESCE(r.decided_at, r.submitted_at, r.created_at)) BETWEEN :start_date AND :end_date
              AND (r.request_type <> 'feb' OR COALESCE(r.meta_legacy_source, '') <> 'feb_legacy')
              {$personnelSql}
            GROUP BY request_type
            ORDER BY total DESC, request_type ASC
            LIMIT 8
        ");
        $stmt->execute(array_merge([':start_date' => $startDate, ':end_date' => $endDate], $personnelParams));
        $types = $this->legalRequestManager->getRequestTypes();
        $byType = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $type = (string) ($row['request_type'] ?? '');
            $byType[] = [
                'type' => $type,
                'label' => (string) ($types[$type]['label'] ?? ucfirst(str_replace('_', ' ', $type))),
                'total' => (int) ($row['total'] ?? 0),
            ];
        }

        $counts['by_status'] = $byStatus;
        $counts['by_type'] = $byType;
        return $counts;
    }

    private function getScheduleAnalytics(string $startDate, string $endDate, ?array $personnelIds = null): array
    {
        $out = [
            'events_total' => 0,
            'events_hours' => 0.0,
            'employees_with_events' => 0,
            'work_windows_personnel' => 0,
            'work_windows_services' => 0,
            'by_status' => [],
            'top_busy' => [],
            'quick_tasks' => [],
            'communication_tasks' => [],
        ];

        if ($this->tableExists('employee_schedule_event')) {
            [$eventPersonnelSql, $eventPersonnelParams] = $this->personnelFilterSql('personnel_id', $personnelIds);
            $stmt = $this->pdo->prepare("
                SELECT
                    COUNT(*) AS total,
                    COUNT(DISTINCT personnel_id) AS employees_count,
                    SUM(TIMESTAMPDIFF(MINUTE, start_at, end_at)) / 60 AS total_hours
                FROM employee_schedule_event
                WHERE DATE(start_at) BETWEEN :start_date AND :end_date
                {$eventPersonnelSql}
            ");
            $stmt->execute(array_merge([':start_date' => $startDate, ':end_date' => $endDate], $eventPersonnelParams));
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $out['events_total'] = (int) ($row['total'] ?? 0);
            $out['employees_with_events'] = (int) ($row['employees_count'] ?? 0);
            $out['events_hours'] = round((float) ($row['total_hours'] ?? 0), 1);

            $stmt = $this->pdo->prepare("
                SELECT status_code, COUNT(*) AS total
                FROM employee_schedule_event
                WHERE DATE(start_at) BETWEEN :start_date AND :end_date
                {$eventPersonnelSql}
                GROUP BY status_code
                ORDER BY total DESC
            ");
            $stmt->execute(array_merge([':start_date' => $startDate, ':end_date' => $endDate], $eventPersonnelParams));
            $out['by_status'] = array_map(static function (array $row): array {
                $status = (string) ($row['status_code'] ?? '');
                return [
                    'status' => $status,
                    'label' => ucfirst(str_replace('_', ' ', $status ?: 'planifié')),
                    'total' => (int) ($row['total'] ?? 0),
                ];
            }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);

            [$aliasPersonnelSql, $aliasPersonnelParams] = $this->personnelFilterSql('e.personnel_id', $personnelIds);
            $stmt = $this->pdo->prepare("
                SELECT
                    e.personnel_id,
                    COALESCE(NULLIF(TRIM(p.nom_personnel_soignant), ''), CONCAT('Personnel ', e.personnel_id)) AS employee_name,
                    COUNT(*) AS events_count,
                    SUM(TIMESTAMPDIFF(MINUTE, e.start_at, e.end_at)) / 60 AS hours_count
                FROM employee_schedule_event e
                LEFT JOIN personnel p ON p.id_personnel_soignant = e.personnel_id
                WHERE DATE(e.start_at) BETWEEN :start_date AND :end_date
                {$aliasPersonnelSql}
                GROUP BY e.personnel_id, employee_name
                ORDER BY hours_count DESC, events_count DESC
                LIMIT 6
            ");
            $stmt->execute(array_merge([':start_date' => $startDate, ':end_date' => $endDate], $aliasPersonnelParams));
            $out['top_busy'] = array_map(static function (array $row): array {
                return [
                    'personnel_id' => (int) ($row['personnel_id'] ?? 0),
                    'employee_name' => (string) ($row['employee_name'] ?? ''),
                    'events_count' => (int) ($row['events_count'] ?? 0),
                    'hours_count' => round((float) ($row['hours_count'] ?? 0), 1),
                ];
            }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
        }

        if ($this->tableExists('employee_schedule_work_window')) {
            $row = $this->pdo->query("
                SELECT
                    COUNT(DISTINCT CASE WHEN scope_type = 'personnel' THEN scope_id END) AS personnel_count,
                    COUNT(DISTINCT CASE WHEN scope_type = 'service' THEN scope_id END) AS service_count
                FROM employee_schedule_work_window
                WHERE is_working_day = 1
            ")->fetch(PDO::FETCH_ASSOC) ?: [];
            $out['work_windows_personnel'] = (int) ($row['personnel_count'] ?? 0);
            $out['work_windows_services'] = (int) ($row['service_count'] ?? 0);
        }

        if ($this->tableExists('employee_schedule_quick_task')) {
            $stmt = $this->pdo->prepare("
                SELECT status_code, COUNT(*) AS total
                FROM employee_schedule_quick_task
                WHERE DATE(created_at) BETWEEN :start_date AND :end_date
                  AND is_archived = 0
                GROUP BY status_code
                ORDER BY total DESC
            ");
            $stmt->execute([':start_date' => $startDate, ':end_date' => $endDate]);
            $out['quick_tasks'] = array_map(static function (array $row): array {
                $status = (string) ($row['status_code'] ?? '');
                return ['label' => ucfirst(str_replace('_', ' ', $status ?: 'planifiée')), 'total' => (int) ($row['total'] ?? 0)];
            }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
        }

        if ($this->tableExists('comm_tasks')) {
            $stmt = $this->pdo->prepare("
                SELECT status, COUNT(*) AS total
                FROM comm_tasks
                WHERE DATE(created_at) BETWEEN :start_date AND :end_date
                  AND archived_at IS NULL
                GROUP BY status
                ORDER BY total DESC
            ");
            $stmt->execute([':start_date' => $startDate, ':end_date' => $endDate]);
            $out['communication_tasks'] = array_map(static function (array $row): array {
                $status = (string) ($row['status'] ?? '');
                return ['label' => ucfirst(str_replace('_', ' ', $status ?: 'à faire')), 'total' => (int) ($row['total'] ?? 0)];
            }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
        }

        return $out;
    }

    private function getOvertimeAnalytics(string $startDate, string $endDate, ?array $personnelIds = null): array
    {
        $out = [
            'hours_total' => 0.0,
            'employees_count' => 0,
            'top_employees' => [],
        ];

        if (!$this->tableExists('rh_attendance_daily')) {
            return $out;
        }

        [$personnelSql, $personnelParams] = $this->personnelFilterSql('personnel_id', $personnelIds);
        $stmt = $this->pdo->prepare("
            SELECT
                SUM(overtime_hours) AS hours_total,
                COUNT(DISTINCT CASE WHEN overtime_hours > 0 THEN personnel_id END) AS employees_count
            FROM rh_attendance_daily
            WHERE attendance_date BETWEEN :start_date AND :end_date
            {$personnelSql}
        ");
        $stmt->execute(array_merge([':start_date' => $startDate, ':end_date' => $endDate], $personnelParams));
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $out['hours_total'] = round((float) ($row['hours_total'] ?? 0), 2);
        $out['employees_count'] = (int) ($row['employees_count'] ?? 0);

        [$aliasPersonnelSql, $aliasPersonnelParams] = $this->personnelFilterSql('a.personnel_id', $personnelIds);
        $stmt = $this->pdo->prepare("
            SELECT
                a.personnel_id,
                COALESCE(NULLIF(TRIM(p.nom_personnel_soignant), ''), CONCAT('Personnel ', a.personnel_id)) AS employee_name,
                SUM(a.overtime_hours) AS hours_total,
                COUNT(CASE WHEN a.overtime_hours > 0 THEN 1 END) AS days_count
            FROM rh_attendance_daily a
            LEFT JOIN personnel p ON p.id_personnel_soignant = a.personnel_id
            WHERE a.attendance_date BETWEEN :start_date AND :end_date
              AND a.overtime_hours > 0
              {$aliasPersonnelSql}
            GROUP BY a.personnel_id, employee_name
            ORDER BY hours_total DESC
            LIMIT 6
        ");
        $stmt->execute(array_merge([':start_date' => $startDate, ':end_date' => $endDate], $aliasPersonnelParams));
        $out['top_employees'] = array_map(static function (array $row): array {
            return [
                'personnel_id' => (int) ($row['personnel_id'] ?? 0),
                'employee_name' => (string) ($row['employee_name'] ?? ''),
                'hours_total' => round((float) ($row['hours_total'] ?? 0), 2),
                'days_count' => (int) ($row['days_count'] ?? 0),
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);

        return $out;
    }

    private function countPendingSalaryClaims(): int
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM employee_legal_requests
            WHERE assigned_team = 'rh'
              AND request_type = 'reclamation_salariale'
              AND status = 'submitted'
              AND COALESCE(current_approval_stage, 'rh_pending') IN ('rh_pending', '')
        ");
        $stmt->execute();

        return (int) ($stmt->fetchColumn() ?: 0);
    }

    private function countPendingLegalRequestsByType(): array
    {
        $stmt = $this->pdo->query("
            SELECT request_type, COUNT(*) AS total
            FROM employee_legal_requests
            WHERE assigned_team = 'rh'
              AND status = 'submitted'
              AND (request_type <> 'feb' OR COALESCE(meta_legacy_source, '') <> 'feb_legacy')
            GROUP BY request_type
            ORDER BY total DESC, request_type ASC
        ");

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $types = $this->legalRequestManager->getRequestTypes();
        $out = [];
        foreach ($rows as $row) {
            $type = (string) ($row['request_type'] ?? '');
            $out[] = [
                'type' => $type,
                'label' => (string) ($types[$type]['label'] ?? ucfirst(str_replace('_', ' ', $type))),
                'total' => (int) ($row['total'] ?? 0),
            ];
        }

        return $out;
    }

    private function periodBounds(string $period): array
    {
        $start = new DateTimeImmutable($period . '-01');
        return [$start->format('Y-m-d'), $start->modify('last day of this month')->format('Y-m-d')];
    }

    private function attendanceStatusLabels(): array
    {
        return [
            'present' => 'Présence',
            'half_day' => 'Demi-journée',
            'mission' => 'Mission',
            'conge' => 'Congé',
            'repos' => 'Repos',
            'absence' => 'Absence',
            'maladie' => 'Maladie',
        ];
    }

    private function resolveAnalyticsPersonnelIds(array $filters): ?array
    {
        $scope = (string) ($filters['report_scope'] ?? 'all');
        if (!in_array($scope, ['personnel', 'service', 'fonction', 'statut'], true)) {
            return null;
        }

        $personnelFilters = ['scope' => 'all'];
        if ($scope === 'personnel') {
            $personnelIds = is_array($filters['personnel_ids'] ?? null) ? (array) $filters['personnel_ids'] : [];
            $personnelIds = array_values(array_unique(array_filter(array_map('intval', $personnelIds), static fn(int $id): bool => $id > 0)));
            if ($personnelIds) {
                return $personnelIds;
            }
            $personnelId = (int) ($filters['personnel_id'] ?? 0);
            return $personnelId > 0 ? [$personnelId] : [];
        }
        if ($scope === 'service') {
            $personnelFilters['service_id'] = (int) ($filters['service_id'] ?? 0);
        }
        if ($scope === 'fonction') {
            $personnelFilters['fonction_id'] = (int) ($filters['fonction_id'] ?? 0);
        }
        if ($scope === 'statut') {
            $personnelFilters['status_code'] = (int) ($filters['status_code'] ?? 0);
        }

        return array_values(array_filter(array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), $this->personnelDirectory->getPersonnelRowsForExport($personnelFilters, 10000)), static fn(int $id): bool => $id > 0));
    }

    private function personnelFilterSql(string $column, ?array $personnelIds): array
    {
        if ($personnelIds === null) {
            return ['', []];
        }
        $personnelIds = array_values(array_unique(array_filter(array_map('intval', $personnelIds), static fn(int $id): bool => $id > 0)));
        if (empty($personnelIds)) {
            return [' AND 1 = 0', []];
        }

        $placeholders = [];
        $params = [];
        foreach ($personnelIds as $index => $personnelId) {
            $key = ':pid_' . $index;
            $placeholders[] = $key;
            $params[$key] = $personnelId;
        }

        return [' AND ' . $column . ' IN (' . implode(',', $placeholders) . ')', $params];
    }

    private function tableExists(string $table): bool
    {
        if ($this->availableTables === null) {
            $this->availableTables = [];
            foreach ($this->pdo->query('SHOW TABLES') as $row) {
                $tableName = (string) array_values($row)[0];
                $this->availableTables[$tableName] = true;
            }
        }

        return isset($this->availableTables[$table]);
    }

    private function columnExists(string $table, string $column): bool
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column) || !$this->tableExists($table)) return false;
        if (!array_key_exists($table, $this->availableColumns)) {
            $this->availableColumns[$table] = array_fill_keys(array_map('strval', $this->pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN) ?: []), true);
        }
        return isset($this->availableColumns[$table][$column]);
    }
}
