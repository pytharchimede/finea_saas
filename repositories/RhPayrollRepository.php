<?php

require_once __DIR__ . '/../decaissement/model/PayrollManager.php';
require_once __DIR__ . '/../modules/payroll/bootstrap.php';

class RhPayrollRepository
{
    private PDO $pdo;
    private PayrollManager $payroll;
    private EmployeeContractRepository $contracts;
    private EmployeeContractSalaryComponentRepository $contractComponents;
    private PayrollAdjustmentRepository $adjustments;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->payroll = new PayrollManager($pdo);
        $this->contracts = new EmployeeContractRepository($pdo);
        $this->contractComponents = new EmployeeContractSalaryComponentRepository($pdo);
        $this->adjustments = new PayrollAdjustmentRepository($pdo);
    }

    public function model(): PayrollManager
    {
        return $this->payroll;
    }

    public function getEmployeeOptions(bool $activeOnly = true, bool $includePointageExempt = true): array
    {
        return $this->payroll->getEmployeeOptions($activeOnly, $includePointageExempt);
    }

    public function getContractPresets(): array
    {
        return $this->payroll->getContractPresets();
    }

    public function getPayrollContext(int $personnelId, string $period): array
    {
        return $this->payroll->getPayrollContext($personnelId, $period);
    }

    public function getPayrollSalaryItemOptions(string $period): array
    {
        $rules = new PayrollRuleRepository($this->pdo);
        $version = $rules->getActiveVersion('CI', $period);
        if ($version === null) {
            return [];
        }

        return $rules->getSalaryItems((int) $version['id']);
    }

    public function getContractPayrollView(int $personnelId, string $period): array
    {
        $rules = new PayrollRuleRepository($this->pdo);
        $version = $rules->getActiveVersion('CI', $period);
        $contract = $this->contracts->findActiveContractForPeriod($personnelId, $period);
        if ($contract === null || $version === null) {
            return ['contract' => $contract, 'components' => [], 'adjustments' => [], 'version' => $version];
        }

        return [
            'contract' => $contract,
            'components' => $this->contractComponents->findMergedWithPayrollItems((int) $contract['id'], $personnelId, (int) $version['id'], $period),
            'adjustments' => $this->adjustments->findByPersonnelAndPeriod($personnelId, $period),
            'version' => $version,
        ];
    }

    public function saveEmployeeContract(array $data, array $actor = []): int
    {
        if (!isset($data['id']) && isset($data['contract_id'])) {
            $data['id'] = $data['contract_id'];
        }
        if (!isset($data['contract_reference']) && isset($data['contract_code'])) {
            $data['contract_reference'] = $data['contract_code'];
        }
        if (!isset($data['base_salary']) && isset($data['contract_base_salary'])) {
            $data['base_salary'] = $data['contract_base_salary'];
        }
        if (isset($data['contract_over_salary'])) {
            $data['over_salary'] = $data['contract_over_salary'];
        }
        if (!isset($data['base_salary']) && isset($data['salary_category'])) {
            $data['base_salary'] = $data['salary_category'];
        }
        $data['status'] = $data['status'] ?? 'active';
        $data['active'] = array_key_exists('active', $data) ? $data['active'] : 1;
        $data['created_by'] = $data['created_by'] ?? ($actor['user_id'] ?? null);
        $id = $this->contracts->saveContract($data);
        (new PayrollAuditService())->log('EMPLOYEE_CONTRACT_SAVED', [
            'contract_id' => $id,
            'personnel_id' => (int) ($data['personnel_id'] ?? 0),
            'label' => 'Contrat employe enregistre',
        ], $actor);

        return $id;
    }

    public function saveContractSalaryComponent(array $data, array $actor = []): int
    {
        if (isset($data['contract_component_amount'])) {
            $data['amount'] = $data['contract_component_amount'];
        }
        if (isset($data['contract_component_quantity'])) {
            $data['quantity'] = $data['contract_component_quantity'];
        }
        if (!isset($data['effective_date']) && isset($data['period']) && preg_match('/^\d{4}-\d{2}$/', (string) $data['period'])) {
            $data['effective_date'] = (string) $data['period'] . '-01';
        }
        $data['source'] = $data['source'] ?? 'contract';
        $data['is_recurring'] = array_key_exists('is_recurring', $data) ? $data['is_recurring'] : 1;
        $data['active'] = array_key_exists('active', $data) ? $data['active'] : 1;
        $data['created_by'] = $data['created_by'] ?? ($actor['user_id'] ?? null);
        $id = $this->contractComponents->saveComponent($data);
        (new PayrollAuditService())->log('CONTRACT_SALARY_COMPONENT_SAVED', [
            'component_id' => $id,
            'contract_id' => (int) ($data['contract_id'] ?? 0),
            'personnel_id' => (int) ($data['personnel_id'] ?? 0),
            'label' => 'Composant contractuel enregistre',
        ], $actor);

        return $id;
    }

    public function savePayrollAdjustment(array $data, array $actor = []): int
    {
        if (isset($data['adjustment_label'])) {
            $data['label'] = $data['adjustment_label'];
        }
        if (isset($data['adjustment_amount'])) {
            $data['amount'] = $data['adjustment_amount'];
        }
        if (!isset($data['quantity'])) {
            $data['quantity'] = 1;
        }
        if (!isset($data['payroll_period']) && isset($data['period'])) {
            $data['payroll_period'] = (string) $data['period'];
        }
        $data['created_by'] = $data['created_by'] ?? ($actor['user_id'] ?? null);
        $id = $this->adjustments->saveAdjustment($data);
        (new PayrollAuditService())->log('PAYROLL_ADJUSTMENT_CREATED', [
            'adjustment_id' => $id,
            'personnel_id' => (int) ($data['personnel_id'] ?? 0),
            'period' => (string) ($data['payroll_period'] ?? ''),
            'label' => 'Ajustement de paie cree',
        ], $actor);

        return $id;
    }

    public function approvePayrollAdjustment(int $id, array $actor = []): bool
    {
        $approved = $this->adjustments->approveAdjustment($id, (int) ($actor['user_id'] ?? 0));
        if ($approved) {
            (new PayrollAuditService())->log('PAYROLL_ADJUSTMENT_APPROVED', [
                'adjustment_id' => $id,
                'label' => 'Ajustement de paie valide',
            ], $actor);
        }

        return $approved;
    }

    public function rejectPayrollAdjustment(int $id, array $actor = []): bool
    {
        $rejected = $this->adjustments->rejectAdjustment($id, (int) ($actor['user_id'] ?? 0));
        if ($rejected) {
            (new PayrollAuditService())->log('PAYROLL_ADJUSTMENT_REJECTED', [
                'adjustment_id' => $id,
                'label' => 'Ajustement de paie refuse',
            ], $actor);
        }

        return $rejected;
    }

    public function listHolidays(string $startDate, string $endDate): array
    {
        $stmt = $this->pdo->prepare('SELECT id, holiday_date, label FROM rh_holidays WHERE holiday_date BETWEEN :start_date AND :end_date ORDER BY holiday_date ASC');
        $stmt->bindValue(':start_date', $startDate);
        $stmt->bindValue(':end_date', $endDate);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function saveHoliday(string $holidayDate, ?string $label): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO rh_holidays (holiday_date, label) VALUES (:holiday_date, :label) ON DUPLICATE KEY UPDATE label = VALUES(label)');
        $stmt->bindValue(':holiday_date', $holidayDate);
        if ($label === null || $label === '') {
            $stmt->bindValue(':label', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':label', $label);
        }
        $stmt->execute();
    }

    public function deleteHoliday(int $holidayId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM rh_holidays WHERE id = :id');
        $stmt->bindValue(':id', $holidayId, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function getAttendanceRows(int $personnelId, string $startDate, string $endDate): array
    {
        $stmt = $this->pdo->prepare('SELECT attendance_date, attendance_status, worked_days, overtime_hours, check_in_time, check_out_time, notes, recorded_by_user_id, recorded_by_name, updated_at FROM rh_attendance_daily WHERE personnel_id = :personnel_id AND attendance_date BETWEEN :start_date AND :end_date ORDER BY attendance_date ASC');
        $stmt->bindValue(':personnel_id', $personnelId, PDO::PARAM_INT);
        $stmt->bindValue(':start_date', $startDate);
        $stmt->bindValue(':end_date', $endDate);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function calculateProfessionalPayroll(array $employee, array $input): array
    {
        $payload = $this->buildProfessionalPayload($employee, $input);
        $controller = new PayrollController($this->pdo);
        return $this->adaptProfessionalCalculation($controller->preview($payload), $payload, $input);
    }

    public function saveProfessionalPayrollRecord(array $input, array $actor = []): int
    {
        $personnelId = (int) ($input['personnel_id'] ?? 0);
        $context = $this->payroll->getPayrollContext($personnelId, (string) ($input['period'] ?? date('Y-m')));
        $employee = $context['employee'] ?? null;
        if (!is_array($employee)) {
            throw new RuntimeException('Le salarie selectionne est introuvable.');
        }
        if (!isset($input['attendance_summary']) && isset($context['attendance'])) {
            $input['attendance_summary'] = $context['attendance'];
        }

        $payload = $this->buildProfessionalPayload($employee, $input);
        $controller = new PayrollController($this->pdo);
        return $controller->generate($payload, $actor);
    }

    public function getProfessionalPayrollRecord(int $recordId): ?array
    {
        $slip = (new PayrollController($this->pdo))->slip($recordId);
        if ($slip === null) {
            return null;
        }

        $payload = $slip['payload'] ?? [];
        $calculation = $payload['calculation'] ?? [];
        $employee = $calculation['employee'] ?? [];
        $lines = [];
        foreach (($calculation['salary_lines'] ?? []) as $line) {
            $lines[] = [
                'designation' => (string) ($line['label'] ?? $line['code'] ?? ''),
                'number' => $line['number'] ?? null,
                'base' => (float) ($line['amount'] ?? 0),
                'rate' => null,
                'gain' => (float) ($line['amount'] ?? 0),
                'employee_deduction' => 0,
                'employer_contribution' => 0,
            ];
        }
        foreach (($calculation['cnps']['details'] ?? []) as $cnps) {
            $lines[] = [
                'designation' => (string) ($cnps['label'] ?? $cnps['code'] ?? ''),
                'number' => null,
                'base' => (float) ($cnps['applied_base'] ?? 0),
                'rate' => (float) ($cnps['employee_rate'] ?? 0),
                'gain' => 0,
                'employee_deduction' => (float) ($cnps['employee_amount'] ?? 0),
                'employer_contribution' => (float) ($cnps['employer_amount'] ?? 0),
            ];
        }
        $lines[] = [
            'designation' => 'Impot progressif net RICF',
            'number' => null,
            'base' => (float) ($slip['taxable_base'] ?? 0),
            'rate' => null,
            'gain' => 0,
            'employee_deduction' => (float) ($slip['net_tax'] ?? 0),
            'employer_contribution' => 0,
        ];

        return [
            'id' => (int) $slip['id'],
            'personnel_id' => (int) $slip['personnel_id'],
            'payroll_period' => (string) $slip['payroll_period'],
            'category_code' => (string) ($calculation['context']['category_code'] ?? ''),
            'contract_code' => (string) ($calculation['context']['contract_code'] ?? ''),
            'currency_code' => (string) $slip['currency_code'],
            'attendance_days' => (float) ($calculation['context']['attendance_days'] ?? 0),
            'gross_taxable' => (float) $slip['sbi'],
            'total_employee_deductions' => (float) $slip['employee_contributions'] + (float) $slip['net_tax'] + (float) ($calculation['external_employee_deductions'] ?? 0),
            'total_employer_contributions' => (float) $slip['employer_contributions'],
            'net_salary' => (float) $slip['net_pay'],
            'observations' => (string) ($calculation['context']['observations'] ?? ''),
            'updated_at' => (string) $slip['updated_at'],
            'payload' => [
                'employee' => $employee,
                'calculation' => $this->adaptProfessionalCalculation($calculation, [], []),
                'attendance_summary' => $calculation['context']['attendance_summary'] ?? [],
            ],
        ];
    }

    public function getPayrollRecord(int $recordId): ?array
    {
        $professional = $this->getProfessionalPayrollRecord($recordId);
        if ($professional !== null) {
            return $professional;
        }

        return $this->payroll->getPayrollRecord($recordId);
    }

    private function buildProfessionalPayload(array $employee, array $input): array
    {
        $period = preg_match('/^\d{4}-\d{2}$/', (string) ($input['period'] ?? '')) ? (string) $input['period'] : date('Y-m');
        $personnelId = (int) ($input['personnel_id'] ?? $employee['id'] ?? 0);
        $rulesRepository = new PayrollRuleRepository($this->pdo);
        $version = $rulesRepository->getActiveVersion('CI', $period);
        if ($version === null) {
            throw new RuntimeException('Aucune version de paie active pour la periode.');
        }
        $contract = $this->contracts->findActiveContractForPeriod($personnelId, $period);
        if ($contract === null) {
            throw new RuntimeException($this->contracts->explainNoActiveContractForPeriod($personnelId, $period));
        }

        $contractCode = strtoupper((string) ($contract['contract_reference'] ?? $contract['contract_label'] ?? $input['contract_code'] ?? 'AUTRE'));
        if (!isset($this->payroll->getContractPresets()[$contractCode])) {
            $contractCode = strtoupper((string) ($input['contract_code'] ?? 'AUTRE'));
        }
        $rules = $this->payroll->getContractRules($contractCode);
        $attendanceSummary = isset($input['attendance_summary']) && is_array($input['attendance_summary'])
            ? $input['attendance_summary']
            : $this->payroll->getAttendanceSummary($personnelId, $period);

        $attendanceDays = (float) ($input['attendance_days'] ?? ($attendanceSummary['worked_days'] ?? $rules['reference_working_days']));
        $referenceDays = max(1.0, (float) ($input['reference_working_days'] ?? $rules['reference_working_days']));
        $prorata = min(1.0, round($attendanceDays / $referenceDays, 4));
        $salaryCategory = payroll_ci_decimal($contract['base_salary'] ?? 0);
        $overSalary = payroll_ci_decimal($contract['over_salary'] ?? 0);
        $seniority = payroll_ci_decimal($input['seniority_bonus'] ?? 0);
        $fixedGross = $salaryCategory + $overSalary + $seniority;
        $hoursPerDay = max(1.0, (float) ($input['hours_per_day'] ?? $rules['hours_per_day']));
        $overtimeHours = payroll_ci_decimal($input['overtime_hours'] ?? ($attendanceSummary['overtime_hours'] ?? 0));
        $hourlyRate = $fixedGross > 0 ? $fixedGross / $referenceDays / $hoursPerDay : 0.0;
        $overtime = round($overtimeHours * $hourlyRate * (float) ($input['overtime_multiplier'] ?? $rules['overtime_multiplier']), 2);
        $attendanceDeduction = round(max(0, $fixedGross - ($fixedGross * $prorata)), 2);
        $partialGross = ($fixedGross * $prorata) + $overtime + payroll_ci_decimal($input['other_bonus'] ?? 0);
        $precarity = payroll_ci_decimal($input['precarity_bonus'] ?? 0);
        if ($precarity <= 0 && (float) ($rules['precarity_rate'] ?? 0) > 0) {
            $precarity = round($partialGross * ((float) $rules['precarity_rate'] / 100), 2);
        }

        $transportAmount = $this->resolveTransportAmount((int) $version['id'], (string) ($contract['transport_locality_code'] ?? ''));
        $contractComponents = $this->contractComponents->findMergedWithPayrollItems((int) $contract['id'], $personnelId, (int) $version['id'], $period);
        foreach ($contractComponents as $component) {
            if ((string) ($component['code'] ?? '') === 'PRIME_TRANSPORT' && (float) ($component['contract_amount'] ?? 0) > 0) {
                $transportAmount = (float) $component['contract_amount'] * max(1.0, (float) ($component['contract_quantity'] ?? 1));
                break;
            }
        }

        $lineMap = [
            ['SAL_BASE', 'Salaire categoriel', round($salaryCategory * $prorata, 2)],
            ['SURSALAIRE', 'Sursalaire', round($overSalary * $prorata, 2)],
            ['PRIME_ANCIENNETE', 'Prime d anciennete', round($seniority * $prorata, 2)],
            ['HEURES_SUP', 'Heures supplementaires', $overtime],
            ['PRIME_RENDEMENT', 'Autres primes', payroll_ci_decimal($input['other_bonus'] ?? 0)],
            ['PRIME_CONGES_PAYES', 'Prime conges payes', payroll_ci_decimal($input['paid_leave_bonus'] ?? 0)],
            ['PRIME_FIN_ANNEE', 'Gratification', payroll_ci_decimal($input['gratification'] ?? 0)],
            ['PRIME_PRECARITE', 'Prime de precarite', $precarity],
            ['PRIME_TRANSPORT', 'Prime de transport', $transportAmount],
        ];

        $salaryLines = [];
        $standardCodes = [];
        foreach ($lineMap as $line) {
            $standardCodes[$line[0]] = true;
            if ($line[2] <= 0) {
                continue;
            }
            $salaryLines[] = ['code' => $line[0], 'label' => $line[1], 'amount' => $line[2]];
        }
        foreach ($contractComponents as $component) {
            $code = strtoupper(trim((string) ($component['code'] ?? '')));
            $amount = payroll_ci_decimal($component['contract_amount'] ?? 0) * max(1.0, (float) ($component['contract_quantity'] ?? 1));
            if ($code === '' || $amount <= 0 || isset($standardCodes[$code])) {
                continue;
            }
            if (!in_array((string) ($component['category'] ?? ''), ['gain', 'allowance', 'benefit'], true)) {
                continue;
            }

            $salaryLines[] = [
                'code' => $code,
                'label' => (string) ($component['label'] ?? $code),
                'amount' => $amount,
            ];
        }
        $adjustments = $this->adjustments->findApprovedByPersonnelAndPeriod($personnelId, $period);
        $externalAdjustmentDeductions = 0.0;
        foreach ($adjustments as $adjustment) {
            $amount = payroll_ci_decimal($adjustment['amount'] ?? 0) * max(1.0, (float) ($adjustment['quantity'] ?? 1));
            if ($amount <= 0) {
                continue;
            }
            if ((string) ($adjustment['adjustment_type'] ?? '') === 'deduction' || (string) ($adjustment['adjustment_type'] ?? '') === 'advance') {
                $externalAdjustmentDeductions += $amount;
                continue;
            }
            $salaryLines[] = [
                'code' => (string) ($adjustment['salary_item_code'] ?? ('AJUSTEMENT_' . (int) $adjustment['id'])),
                'label' => (string) ($adjustment['label'] ?? 'Ajustement valide'),
                'amount' => $amount,
            ];
        }

        return [
            'personnel_id' => $personnelId,
            'period' => $period,
            'country_code' => 'CI',
            'currency_code' => 'FCFA',
            'employee' => $employee,
            'salary_lines' => $salaryLines,
            'family_situation' => [
                'situation_code' => strtoupper((string) ($input['family_situation'] ?? 'SINGLE')),
                'children_count' => (int) ($input['children_count'] ?? 0),
                'disabled_children_count' => (int) ($input['disabled_children_count'] ?? 0),
                'parts' => min(5.0, max(1.0, (float) ($input['fiscal_parts'] ?? 1))),
            ],
            'context' => [
                'contract_code' => $contractCode,
                'contract_label' => (string) ($rules['label'] ?? $contractCode),
                'contract' => $contract,
                'contract_components_used' => array_values(array_filter($contractComponents, static fn(array $row): bool => (float) ($row['contract_amount'] ?? 0) > 0)),
                'adjustments_used' => $adjustments,
                'category_code' => trim((string) ($input['category_code'] ?? '')),
                'attendance_days' => $attendanceDays,
                'overtime_hours' => $overtimeHours,
                'overtime_hourly_rate' => round($hourlyRate, 2),
                'overtime_amount' => $overtime,
                'attendance_deduction' => $attendanceDeduction,
                'prorata_rate' => $prorata,
                'reference_working_days' => $referenceDays,
                'hours_per_day' => $hoursPerDay,
                'overtime_multiplier' => (float) ($input['overtime_multiplier'] ?? $rules['overtime_multiplier']),
                'mission_paid_rate' => (float) ($rules['mission_paid_rate'] ?? 0),
                'conge_paid_rate' => (float) ($rules['conge_paid_rate'] ?? 0),
                'half_day_paid_rate' => (float) ($rules['half_day_paid_rate'] ?? 0),
                'absence_paid_rate' => (float) ($rules['absence_paid_rate'] ?? 0),
                'maladie_paid_rate' => (float) ($rules['maladie_paid_rate'] ?? 0),
                'repos_paid_rate' => (float) ($rules['repos_paid_rate'] ?? 0),
                'attendance_summary' => $attendanceSummary,
                'external_employee_deductions' => payroll_ci_decimal($input['other_deductions'] ?? 0) + payroll_ci_decimal($input['health_insurance'] ?? 0) + payroll_ci_decimal($input['advance_amount'] ?? 0) + $externalAdjustmentDeductions,
                'observations' => trim((string) ($input['observations'] ?? '')),
            ],
        ];
    }

    private function resolveTransportAmount(int $versionId, string $localityCode): float
    {
        $localityCode = strtoupper(trim($localityCode));
        $rates = (new PayrollRuleRepository($this->pdo))->getTransportRates($versionId);
        $fallback = 0.0;
        foreach ($rates as $rate) {
            $code = strtoupper((string) ($rate['locality_code'] ?? ''));
            if ($code === $localityCode && $localityCode !== '') {
                return (float) ($rate['monthly_amount'] ?? 0);
            }
            if (in_array($code, ['OTHER', 'AUTRE'], true)) {
                $fallback = (float) ($rate['monthly_amount'] ?? 0);
            }
        }

        return $fallback;
    }

    private function adaptProfessionalCalculation(array $calculation, array $payload, array $input): array
    {
        $payloadContext = isset($payload['context']) && is_array($payload['context']) ? $payload['context'] : [];
        $calculationContext = isset($calculation['context']) && is_array($calculation['context']) ? $calculation['context'] : [];
        $context = array_merge($calculationContext, $payloadContext);
        $lines = [];
        foreach (($calculation['salary_lines'] ?? []) as $line) {
            $lines[] = [
                'designation' => (string) ($line['label'] ?? $line['code'] ?? ''),
                'number' => $line['number'] ?? null,
                'base' => (float) ($line['amount'] ?? 0),
                'rate' => null,
                'gain' => (float) ($line['amount'] ?? 0),
                'employee_deduction' => 0,
                'employer_rate' => null,
                'employer_contribution' => 0,
            ];
        }
        foreach (($calculation['cnps']['details'] ?? []) as $cnps) {
            $lines[] = [
                'designation' => (string) ($cnps['label'] ?? $cnps['code'] ?? ''),
                'number' => null,
                'base' => (float) ($cnps['applied_base'] ?? 0),
                'rate' => (float) ($cnps['employee_rate'] ?? 0),
                'gain' => 0,
                'employee_deduction' => (float) ($cnps['employee_amount'] ?? 0),
                'employer_rate' => (float) ($cnps['employer_rate'] ?? 0),
                'employer_contribution' => (float) ($cnps['employer_amount'] ?? 0),
            ];
        }
        $lines[] = [
            'designation' => 'Impot progressif brut',
            'number' => null,
            'base' => (float) ($calculation['tax']['base_imposable'] ?? 0),
            'rate' => null,
            'gain' => 0,
            'employee_deduction' => (float) ($calculation['tax']['gross_tax'] ?? 0),
            'employer_rate' => null,
            'employer_contribution' => 0,
        ];
        $lines[] = [
            'designation' => 'Reduction charges familiales RICF',
            'number' => null,
            'base' => (float) ($calculation['ricf']['parts'] ?? 1),
            'rate' => null,
            'gain' => (float) ($calculation['ricf']['amount'] ?? 0),
            'employee_deduction' => 0,
            'employer_rate' => null,
            'employer_contribution' => 0,
        ];

        return [
            'period' => (string) ($payload['period'] ?? $input['period'] ?? ''),
            'category_code' => (string) ($context['category_code'] ?? $input['category_code'] ?? ''),
            'contract_code' => (string) ($context['contract_code'] ?? $input['contract_code'] ?? ''),
            'contract_label' => (string) ($context['contract_label'] ?? $context['contract_code'] ?? $input['contract_code'] ?? ''),
            'currency' => (string) ($calculation['currency'] ?? 'FCFA'),
            'attendance_days' => (float) ($context['attendance_days'] ?? 0),
            'overtime_hours' => (float) ($context['overtime_hours'] ?? $input['overtime_hours'] ?? 0),
            'overtime_hourly_rate' => (float) ($context['overtime_hourly_rate'] ?? 0),
            'overtime_amount' => (float) ($context['overtime_amount'] ?? 0),
            'attendance_deduction' => (float) ($context['attendance_deduction'] ?? 0),
            'prorata_rate' => (float) ($context['prorata_rate'] ?? 0),
            'reference_working_days' => (float) ($context['reference_working_days'] ?? $input['reference_working_days'] ?? 0),
            'hours_per_day' => (float) ($context['hours_per_day'] ?? $input['hours_per_day'] ?? 0),
            'overtime_multiplier' => (float) ($context['overtime_multiplier'] ?? $input['overtime_multiplier'] ?? 0),
            'mission_paid_rate' => (float) ($context['mission_paid_rate'] ?? 0),
            'conge_paid_rate' => (float) ($context['conge_paid_rate'] ?? 0),
            'half_day_paid_rate' => (float) ($context['half_day_paid_rate'] ?? 0),
            'absence_paid_rate' => (float) ($context['absence_paid_rate'] ?? 0),
            'maladie_paid_rate' => (float) ($context['maladie_paid_rate'] ?? 0),
            'repos_paid_rate' => (float) ($context['repos_paid_rate'] ?? 0),
            'gross_partial' => (float) ($calculation['gross_salary'] ?? 0),
            'gross_salary' => (float) ($calculation['gross_salary'] ?? 0),
            'gross_taxable' => (float) ($calculation['sbi']['amount'] ?? 0),
            'sbi' => (float) ($calculation['sbi']['amount'] ?? 0),
            'sbs' => (float) ($calculation['sbs']['amount'] ?? 0),
            'base_imposable' => (float) ($calculation['tax']['base_imposable'] ?? 0),
            'impot_brut' => (float) ($calculation['tax']['gross_tax'] ?? 0),
            'ricf' => (float) ($calculation['ricf']['amount'] ?? 0),
            'igr' => (float) ($calculation['net_tax'] ?? 0),
            'employee_cnps' => (float) ($calculation['cnps']['employee_total'] ?? 0),
            'total_employee_deductions' => (float) ($calculation['cnps']['employee_total'] ?? 0) + (float) ($calculation['net_tax'] ?? 0) + (float) ($calculation['external_employee_deductions'] ?? 0),
            'total_employer_contributions' => (float) ($calculation['cnps']['employer_total'] ?? 0),
            'net_salary' => (float) ($calculation['net_pay'] ?? 0),
            'tax_brackets' => $calculation['tax']['brackets'] ?? [],
            'cnps' => $calculation['cnps'] ?? [],
            'lines' => $lines,
            'context' => $context,
        ];
    }

    public function __call(string $name, array $arguments)
    {
        return $this->payroll->{$name}(...$arguments);
    }
}
