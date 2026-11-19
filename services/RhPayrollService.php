<?php

require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../repositories/RhPayrollRepository.php';

class RhPayrollService
{
    private RhPayrollRepository $repository;

    public function __construct(RhPayrollRepository $repository)
    {
        $this->repository = $repository;
    }

    public function model(): PayrollManager
    {
        return $this->repository->model();
    }

    public function buildPayrollFormView(array $request): array
    {
        $requestedPeriod = (string) ($request['period'] ?? '');
        $period = preg_match('/^\d{4}-\d{2}$/', $requestedPeriod) ? $requestedPeriod : date('Y-m');
        $selectedPersonnelId = (int) ($request['personnel_id'] ?? 0);

        $context = null;
        $formData = ['period' => $period, 'personnel_id' => $selectedPersonnelId];
        $contractPayrollView = ['contract' => null, 'components' => [], 'adjustments' => [], 'version' => null];
        $errorMessage = null;

        if ($selectedPersonnelId > 0) {
            try {
                $context = $this->repository->getPayrollContext($selectedPersonnelId, $period);
                $formData = array_merge($context['defaults'], $formData);
                $contractPayrollView = $this->repository->getContractPayrollView($selectedPersonnelId, $period);
                if (is_array($contractPayrollView['contract'] ?? null)) {
                    $formData['salary_category'] = $contractPayrollView['contract']['base_salary'] ?? 0;
                    $formData['over_salary'] = $contractPayrollView['contract']['over_salary'] ?? 0;
                    $formData['contract_code'] = $contractPayrollView['contract']['contract_reference'] ?? ($formData['contract_code'] ?? 'AUTRE');
                    $formData['transport_locality_code'] = $contractPayrollView['contract']['transport_locality_code'] ?? '';
                    $formData['custom_salary_items'] = [];
                    foreach (($contractPayrollView['components'] ?? []) as $component) {
                        if ((float) ($component['contract_amount'] ?? 0) > 0) {
                            $formData['custom_salary_items'][(string) $component['code']] = (float) $component['contract_amount'];
                        }
                    }
                }
            } catch (Throwable $exception) {
                $errorMessage = $exception->getMessage();
            }
        }

        return [
            'period' => $period,
            'selectedPersonnelId' => $selectedPersonnelId,
            'employees' => $this->repository->getEmployeeOptions(true),
            'contractPresets' => $this->repository->getContractPresets(),
            'payrollSalaryItems' => $this->repository->getPayrollSalaryItemOptions($period),
            'contractPayrollView' => $contractPayrollView,
            'context' => $context,
            'formData' => $formData,
            'errorMessage' => $errorMessage,
        ];
    }

    public function buildHolidayView(array $query): array
    {
        $requestedPeriod = (string) ($query['period'] ?? date('Y-m'));
        $period = preg_match('/^\d{4}-\d{2}$/', $requestedPeriod) ? $requestedPeriod : date('Y-m');
        $startDate = $period . '-01';
        $endDate = (new DateTimeImmutable($startDate))->modify('last day of this month')->format('Y-m-d');

        return [
            'period' => $period,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'message' => isset($query['msg']) ? (string) $query['msg'] : null,
            'errorMessage' => isset($query['err']) ? (string) $query['err'] : null,
            'holidays' => $this->repository->listHolidays($startDate, $endDate),
        ];
    }

    public function saveHoliday(string $holidayDate, string $label): void
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $holidayDate)) {
            throw new RuntimeException('La date du férié est invalide.');
        }

        $this->repository->saveHoliday($holidayDate, trim($label) !== '' ? trim($label) : null);
    }

    public function deleteHoliday(int $holidayId): void
    {
        if ($holidayId <= 0) {
            throw new RuntimeException('Férié introuvable.');
        }

        $this->repository->deleteHoliday($holidayId);
    }

    public function buildMonthlyAttendanceView(array $request): array
    {
        $requestedPeriod = (string) ($request['period'] ?? date('Y-m'));
        $period = preg_match('/^\d{4}-\d{2}$/', $requestedPeriod) ? $requestedPeriod : date('Y-m');
        $selectedPersonnelId = (int) ($request['personnel_id'] ?? 0);
        $employees = $this->repository->getEmployeeOptions(true, false);
        $statuses = $this->repository->model()->getAttendanceStatuses();
        $startDate = $period . '-01';
        $endDate = (new DateTimeImmutable($startDate))->modify('last day of this month')->format('Y-m-d');
        $requestedStart = (string) ($request['date_from'] ?? '');
        $requestedEnd = (string) ($request['date_to'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $requestedStart)) {
            $startDate = $requestedStart;
            $endDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $requestedEnd) ? $requestedEnd : $requestedStart;
            if ($endDate < $startDate) {
                $endDate = $startDate;
            }
        }

        $employee = null;
        foreach ($employees as $row) {
            if ((int) ($row['id'] ?? 0) === $selectedPersonnelId) {
                $employee = $row;
                break;
            }
        }

        $holidaySet = [];
        foreach ($this->repository->listHolidays($startDate, $endDate) as $row) {
            $date = (string) ($row['holiday_date'] ?? '');
            if ($date !== '') {
                $holidaySet[$date] = (string) ($row['label'] ?? '');
            }
        }

        $attendanceRows = [];
        $attendanceSummary = null;
        if ($selectedPersonnelId > 0 && $employee !== null) {
            foreach ($this->repository->getAttendanceRows($selectedPersonnelId, $startDate, $endDate) as $row) {
                $date = (string) ($row['attendance_date'] ?? '');
                if ($date === '') {
                    continue;
                }

                $isWeekend = false;
                try {
                    $isWeekend = (int) (new DateTimeImmutable($date))->format('N') >= 6;
                } catch (Throwable) {
                    $isWeekend = false;
                }

                $isWorkingDate = !$isWeekend && !isset($holidaySet[$date]);
                $status = (string) ($row['attendance_status'] ?? 'present');
                if (!isset($statuses[$status])) {
                    $status = 'present';
                }
                $hasTimePair = trim((string) ($row['check_in_time'] ?? '')) !== ''
                    && trim((string) ($row['check_out_time'] ?? '')) !== '';
                $nonWorkingOvertimeHours = 0.0;
                if (!$isWorkingDate && $hasTimePair) {
                    $inTs = strtotime('1970-01-01 ' . (string) $row['check_in_time']);
                    $outTs = strtotime('1970-01-01 ' . (string) $row['check_out_time']);
                    if ($inTs !== false && $outTs !== false) {
                        if ($outTs < $inTs) {
                            $outTs += 86400;
                        }
                        $nonWorkingOvertimeHours = round(max(0, (($outTs - $inTs) / 3600)) * 4) / 4;
                    }
                }

                if (!$isWorkingDate) {
                    $status = $hasTimePair ? 'present' : 'repos';
                } elseif ($status === 'repos') {
                    $hasActivity = trim((string) ($row['check_in_time'] ?? '')) !== ''
                        || trim((string) ($row['check_out_time'] ?? '')) !== ''
                        || (float) ($row['worked_days'] ?? 0) > 0
                        || (float) ($row['overtime_hours'] ?? 0) > 0;
                    $status = $hasActivity ? 'present' : 'absence';
                }

                $attendanceRows[$date] = [
                    'attendance_status' => $status,
                    'worked_days' => !$isWorkingDate ? 0.0 : (((string) ($row['attendance_status'] ?? '') === 'repos') ? (float) ($statuses[$status]['default_days'] ?? 0) : (float) ($row['worked_days'] ?? 0)),
                    'overtime_hours' => !$isWorkingDate ? max($nonWorkingOvertimeHours, (float) ($row['overtime_hours'] ?? 0)) : (float) ($row['overtime_hours'] ?? 0),
                    'check_in_time' => (string) substr((string) ($row['check_in_time'] ?? ''), 0, 5),
                    'check_out_time' => (string) substr((string) ($row['check_out_time'] ?? ''), 0, 5),
                    'notes' => (string) ($row['notes'] ?? ''),
                    'recorded_by_user_id' => (int) ($row['recorded_by_user_id'] ?? 0),
                    'recorded_by_name' => (string) ($row['recorded_by_name'] ?? ''),
                    'updated_at' => (string) ($row['updated_at'] ?? ''),
                ];
            }

            $attendanceSummary = [
                'entries_count' => count($attendanceRows),
                'worked_days' => array_sum(array_column($attendanceRows, 'worked_days')),
                'overtime_hours' => array_sum(array_column($attendanceRows, 'overtime_hours')),
            ];
        }

        $days = [];
        $cursor = new DateTimeImmutable($startDate);
        $end = new DateTimeImmutable($endDate);
        while ($cursor <= $end) {
            $days[] = $cursor->format('Y-m-d');
            $cursor = $cursor->modify('+1 day');
        }

        return [
            'period' => $period,
            'selectedPersonnelId' => $selectedPersonnelId,
            'employees' => $employees,
            'statuses' => $statuses,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'employee' => $employee,
            'holidaySet' => $holidaySet,
            'attendanceRows' => $attendanceRows,
            'attendanceSummary' => $attendanceSummary,
            'days' => $days,
        ];
    }

    public function saveContractRules(array $rulesPayload, array $actor): array
    {
        try {
            $saved = $this->repository->model()->saveContractRules($rulesPayload, $actor);
            \AuditTrail::log('rh_payroll_rules_saved', [
                'module' => 'rh',
                'action' => 'save_contract_rules',
                'entity_type' => 'payroll_rules',
                'entity_label' => 'parametrage_paie_rh',
                'user_id' => $actor['user_id'] ?? null,
                'user_name' => $actor['user_name'] ?? null,
                'details' => [
                    'saved_count' => $saved,
                    'contract_codes' => array_keys($rulesPayload),
                ],
            ]);

            return ['message' => $saved . ' configuration(s) contrat mises a jour.', 'errorMessage' => null];
        } catch (Throwable $exception) {
            \AuditTrail::log('rh_payroll_rules_failed', [
                'module' => 'rh',
                'action' => 'save_contract_rules',
                'entity_type' => 'payroll_rules',
                'entity_label' => 'parametrage_paie_rh',
                'user_id' => $actor['user_id'] ?? null,
                'user_name' => $actor['user_name'] ?? null,
                'result' => 'failure',
                'severity' => 'warning',
                'details' => ['error' => $exception->getMessage()],
            ]);

            return ['message' => null, 'errorMessage' => $exception->getMessage()];
        }
    }

    public function handlePayrollPost(array $post, array $baseFormData, string $period, int $selectedPersonnelId, array $actor): array
    {
        $manager = $this->repository->model();
        $formData = array_merge($baseFormData, $post);
        $action = (string) ($post['submit_action'] ?? 'calculate');
        $selectedPersonnelId = (int) ($formData['personnel_id'] ?? 0);
        $context = null;
        $calculation = null;
        $savedRecordId = null;
        $message = null;
        $errorMessage = null;

        $auditPayroll = static function (string $eventType, array $payload = []) use (&$selectedPersonnelId, &$period, $actor): void {
            \AuditTrail::log($eventType, array_merge([
                'module' => 'rh',
                'entity_type' => 'payroll',
                'entity_id' => $selectedPersonnelId > 0 ? (string) $selectedPersonnelId : null,
                'user_id' => $actor['user_id'] ?? null,
                'user_name' => $actor['user_name'] ?? null,
            ], $payload));
        };

        try {
            if ($action === 'save_employee_contract') {
                $this->repository->saveEmployeeContract($post, $actor);
                return array_merge($this->buildPayrollFormView($post), [
                    'calculation' => null,
                    'savedRecordId' => null,
                    'message' => 'Contrat RH enregistre.',
                    'errorMessage' => null,
                ]);
            }
            if ($action === 'save_contract_salary_component') {
                $this->repository->saveContractSalaryComponent($post, $actor);
                return array_merge($this->buildPayrollFormView($post), [
                    'calculation' => null,
                    'savedRecordId' => null,
                    'message' => 'Element recurrent du contrat enregistre.',
                    'errorMessage' => null,
                ]);
            }
            if ($action === 'save_payroll_adjustment') {
                $this->repository->savePayrollAdjustment($post, $actor);
                return array_merge($this->buildPayrollFormView($post), [
                    'calculation' => null,
                    'savedRecordId' => null,
                    'message' => 'Ajustement mensuel enregistre. Il devra etre valide avant calcul final.',
                    'errorMessage' => null,
                ]);
            }
            if ($action === 'approve_payroll_adjustment') {
                $this->repository->approvePayrollAdjustment((int) ($post['adjustment_id'] ?? 0), $actor);
                return array_merge($this->buildPayrollFormView($post), [
                    'calculation' => null,
                    'savedRecordId' => null,
                    'message' => 'Ajustement mensuel valide. Il entrera dans le prochain calcul du bulletin.',
                    'errorMessage' => null,
                ]);
            }
            if ($action === 'reject_payroll_adjustment') {
                $this->repository->rejectPayrollAdjustment((int) ($post['adjustment_id'] ?? 0), $actor);
                return array_merge($this->buildPayrollFormView($post), [
                    'calculation' => null,
                    'savedRecordId' => null,
                    'message' => 'Ajustement mensuel refuse.',
                    'errorMessage' => null,
                ]);
            }

            $context = $manager->getPayrollContext($selectedPersonnelId, (string) ($formData['period'] ?? $period));
            $formData['attendance_summary'] = $context['attendance'];

            $advanceTouched = (string) ($formData['advance_amount_touched'] ?? '0') === '1';
            $otherTouched = (string) ($formData['other_deductions_touched'] ?? '0') === '1';
            if (!$advanceTouched && isset($context['defaults']['advance_amount'])) {
                $formData['advance_amount'] = $context['defaults']['advance_amount'];
            }
            if (!$otherTouched && isset($context['defaults']['other_deductions'])) {
                $formData['other_deductions'] = $context['defaults']['other_deductions'];
            }

            $employee = $context['employee'];
            $calculation = $this->repository->calculateProfessionalPayroll($employee, $formData);
            $period = (string) ($calculation['period'] ?? $period);
            $calculationContext = isset($calculation['context']) && is_array($calculation['context']) ? $calculation['context'] : [];
            $defaults = isset($context['defaults']) && is_array($context['defaults']) ? $context['defaults'] : [];
            $attendance = isset($context['attendance']) && is_array($context['attendance']) ? $context['attendance'] : [];
            $computedAttendance = isset($attendance['computed']) && is_array($attendance['computed']) ? $attendance['computed'] : [];
            $formData = array_merge($formData, [
                'attendance_days' => $calculation['attendance_days'] ?? $calculationContext['attendance_days'] ?? $computedAttendance['paid_days'] ?? $attendance['worked_days'] ?? 0,
                'overtime_hours' => $calculation['overtime_hours'] ?? $calculationContext['overtime_hours'] ?? $attendance['overtime_hours'] ?? 0,
                'overtime_amount' => $calculation['overtime_amount'] ?? $calculationContext['overtime_amount'] ?? 0,
                'attendance_deduction' => $calculation['attendance_deduction'] ?? $calculationContext['attendance_deduction'] ?? 0,
                'reference_working_days' => $calculation['reference_working_days'] ?? $calculationContext['reference_working_days'] ?? $defaults['reference_working_days'] ?? 0,
                'hours_per_day' => $calculation['hours_per_day'] ?? $calculationContext['hours_per_day'] ?? $defaults['hours_per_day'] ?? 0,
                'overtime_multiplier' => $calculation['overtime_multiplier'] ?? $calculationContext['overtime_multiplier'] ?? $defaults['overtime_multiplier'] ?? 0,
                'mission_paid_rate' => $calculation['mission_paid_rate'] ?? $calculationContext['mission_paid_rate'] ?? $defaults['mission_paid_rate'] ?? 0,
                'conge_paid_rate' => $calculation['conge_paid_rate'] ?? $calculationContext['conge_paid_rate'] ?? $defaults['conge_paid_rate'] ?? 0,
                'half_day_paid_rate' => $calculation['half_day_paid_rate'] ?? $calculationContext['half_day_paid_rate'] ?? $defaults['half_day_paid_rate'] ?? 0,
                'absence_paid_rate' => $calculation['absence_paid_rate'] ?? $calculationContext['absence_paid_rate'] ?? $defaults['absence_paid_rate'] ?? 0,
                'maladie_paid_rate' => $calculation['maladie_paid_rate'] ?? $calculationContext['maladie_paid_rate'] ?? $defaults['maladie_paid_rate'] ?? 0,
                'repos_paid_rate' => $calculation['repos_paid_rate'] ?? $calculationContext['repos_paid_rate'] ?? $defaults['repos_paid_rate'] ?? 0,
            ]);

            $submitAction = (string) ($post['submit_action'] ?? 'calculate');
            $auditPayroll('rh_payroll_calculated', [
                'action' => 'calculate',
                'entity_label' => (string) ($employee['full_name'] ?? ('personnel_' . $selectedPersonnelId)),
                'details' => [
                    'period' => $calculation['period'] ?? $period,
                    'attendance_days' => $calculation['attendance_days'] ?? null,
                    'net_salary' => $calculation['net_salary'] ?? null,
                    'submit_action' => $submitAction,
                ],
            ]);

            if ($submitAction === 'save') {
                $savedRecordId = $this->repository->saveProfessionalPayrollRecord($formData, $actor);
                $auditPayroll('rh_payroll_saved', [
                    'action' => 'save',
                    'entity_id' => $savedRecordId !== null ? (string) $savedRecordId : ($selectedPersonnelId > 0 ? (string) $selectedPersonnelId : null),
                    'entity_label' => (string) ($employee['full_name'] ?? ('personnel_' . $selectedPersonnelId)),
                    'details' => [
                        'period' => $calculation['period'] ?? $period,
                        'record_id' => $savedRecordId,
                        'net_salary' => $calculation['net_salary'] ?? null,
                        'gross_salary' => $calculation['gross_salary'] ?? null,
                    ],
                ]);
                $message = 'La fiche de paie a ete enregistree.';
                $context = $manager->getPayrollContext($selectedPersonnelId, $period);
            }
        } catch (Throwable $exception) {
            $auditPayroll('rh_payroll_failed', [
                'action' => (string) ($post['submit_action'] ?? 'calculate'),
                'entity_label' => (string) ($formData['full_name'] ?? ('personnel_' . $selectedPersonnelId)),
                'result' => 'failure',
                'severity' => 'warning',
                'details' => [
                    'period' => (string) ($formData['period'] ?? $period),
                    'error' => $exception->getMessage(),
                ],
            ]);
            $errorMessage = $exception->getMessage();
        }

        return [
            'period' => $period,
            'selectedPersonnelId' => $selectedPersonnelId,
            'context' => $context,
            'formData' => $formData,
            'payrollSalaryItems' => $this->repository->getPayrollSalaryItemOptions($period),
            'contractPayrollView' => $selectedPersonnelId > 0 ? $this->repository->getContractPayrollView($selectedPersonnelId, (string) ($formData['period'] ?? $period)) : ['contract' => null, 'components' => [], 'adjustments' => [], 'version' => null],
            'calculation' => $calculation,
            'savedRecordId' => $savedRecordId,
            'message' => $message,
            'errorMessage' => $errorMessage,
        ];
    }

    public function __call(string $name, array $arguments)
    {
        return $this->repository->{$name}(...$arguments);
    }
}
