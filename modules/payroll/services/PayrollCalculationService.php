<?php

class PayrollCalculationService
{
    private PayrollRuleRepository $rules;
    private PayrollVersionResolverService $versionResolver;
    private PayrollValidator $validator;
    private SbiCalculationService $sbi;
    private SbsCalculationService $sbs;
    private TaxCalculationService $tax;
    private RicfCalculationService $ricf;
    private CnpsCalculationService $cnps;

    public function __construct(PayrollRuleRepository $rules)
    {
        $this->rules = $rules;
        $this->versionResolver = new PayrollVersionResolverService($rules);
        $this->validator = new PayrollValidator();
        $this->sbi = new SbiCalculationService();
        $this->sbs = new SbsCalculationService();
        $this->tax = new TaxCalculationService();
        $this->ricf = new RicfCalculationService();
        $this->cnps = new CnpsCalculationService();
    }

    public function calculate(PayrollInputDto $input): array
    {
        if ($input->salaryLines === [] && !empty($input->context['auto_from_contract'])) {
            $this->hydrateFromContract($input);
        }

        $this->validator->validateInput($input);
        $version = $this->versionResolver->resolve($input);
        $versionId = (int) $version['id'];
        $items = $this->rules->getSalaryItems($versionId);
        $params = $this->rules->getParameters($versionId);
        $brackets = $this->rules->getTaxBrackets($versionId);
        $contributions = $this->rules->getSocialContributions($versionId);
        $familyRules = $this->rules->getFamilyParts($versionId);

        $this->validator->validateRates(array_merge($brackets, $contributions));

        $gross = 0.0;
        foreach ($input->salaryLines as $line) {
            $gross += payroll_ci_decimal($line['amount'] ?? 0);
        }
        $externalDeductions = payroll_ci_decimal($input->context['external_employee_deductions'] ?? 0);

        $sbi = $this->sbi->calculate($input->salaryLines, $items, $params);
        $sbs = $this->sbs->calculate($input->salaryLines, $items, $params);
        if ($sbi['amount'] < 0 || $sbs['amount'] < 0) {
            throw new RuntimeException('Assiettes SBI/SBS incoherentes.');
        }

        $tax = $this->tax->calculate($sbi['amount'], $brackets, $params);
        $ricf = $this->ricf->calculate($input->familySituation, $familyRules, $params);
        $netTax = round(max(0.0, $tax['gross_tax'] - $ricf['amount']), 2);
        $cnps = $this->cnps->calculate($sbs['amount'], $sbi['amount'], $contributions);
        $netPay = round($gross - $cnps['employee_total'] - $netTax - $externalDeductions, 2);

        return [
            'version' => $version,
            'currency' => $input->currencyCode,
            'gross_salary' => round($gross, 2),
            'sbi' => $sbi,
            'sbs' => $sbs,
            'tax' => $tax,
            'ricf' => $ricf,
            'net_tax' => $netTax,
            'cnps' => $cnps,
            'external_employee_deductions' => $externalDeductions,
            'net_pay' => $netPay,
            'salary_lines' => $input->salaryLines,
            'employee' => $input->employee,
            'context' => $input->context,
        ];
    }

    private function hydrateFromContract(PayrollInputDto $input): void
    {
        $version = $this->rules->getActiveVersion($input->countryCode, $input->period);
        if ($version === null) {
            throw new RuntimeException('Aucune version de paie active pour la periode.');
        }

        $pdo = $this->rules->connection();
        $contracts = new EmployeeContractRepository($pdo);
        $components = new EmployeeContractSalaryComponentRepository($pdo);
        $adjustments = new PayrollAdjustmentRepository($pdo);

        $contract = $contracts->findActiveContractForPeriod($input->personnelId, $input->period);
        if ($contract === null) {
            throw new RuntimeException($contracts->explainNoActiveContractForPeriod($input->personnelId, $input->period));
        }

        $lines = [];
        $baseSalary = payroll_ci_decimal($contract['base_salary'] ?? 0);
        $overSalary = payroll_ci_decimal($contract['over_salary'] ?? 0);
        if ($baseSalary > 0) {
            $lines[] = ['code' => 'SAL_BASE', 'label' => 'Salaire categoriel', 'amount' => $baseSalary];
        }
        if ($overSalary > 0) {
            $lines[] = ['code' => 'SURSALAIRE', 'label' => 'Sursalaire', 'amount' => $overSalary];
        }

        $transportAmount = $this->resolveTransportAmount((int) $version['id'], (string) ($contract['transport_locality_code'] ?? ''));
        $contractComponents = $components->findMergedWithPayrollItems((int) $contract['id'], $input->personnelId, (int) $version['id'], $input->period);
        foreach ($contractComponents as $component) {
            $code = strtoupper(trim((string) ($component['code'] ?? '')));
            $amount = payroll_ci_decimal($component['contract_amount'] ?? 0) * max(1.0, (float) ($component['contract_quantity'] ?? 1));
            if ($code === 'PRIME_TRANSPORT' && $amount > 0) {
                $transportAmount = $amount;
            }
        }
        if ($transportAmount > 0) {
            $lines[] = ['code' => 'PRIME_TRANSPORT', 'label' => 'Prime de transport', 'amount' => $transportAmount];
        }

        foreach ($contractComponents as $component) {
            $code = strtoupper(trim((string) ($component['code'] ?? '')));
            $amount = payroll_ci_decimal($component['contract_amount'] ?? 0) * max(1.0, (float) ($component['contract_quantity'] ?? 1));
            if ($code === '' || $amount <= 0 || in_array($code, ['SAL_BASE', 'SURSALAIRE', 'PRIME_TRANSPORT'], true)) {
                continue;
            }
            if (!in_array((string) ($component['category'] ?? ''), ['gain', 'allowance', 'benefit'], true)) {
                continue;
            }
            $lines[] = ['code' => $code, 'label' => (string) ($component['label'] ?? $code), 'amount' => $amount];
        }

        $approvedAdjustments = $adjustments->findApprovedByPersonnelAndPeriod($input->personnelId, $input->period);
        $externalDeductions = payroll_ci_decimal($input->context['external_employee_deductions'] ?? 0);
        foreach ($approvedAdjustments as $adjustment) {
            $amount = payroll_ci_decimal($adjustment['amount'] ?? 0) * max(1.0, (float) ($adjustment['quantity'] ?? 1));
            if ($amount <= 0) {
                continue;
            }
            if (in_array((string) ($adjustment['adjustment_type'] ?? ''), ['deduction', 'advance'], true)) {
                $externalDeductions += $amount;
                continue;
            }
            $lines[] = [
                'code' => (string) ($adjustment['salary_item_code'] ?? ('AJUSTEMENT_' . (int) $adjustment['id'])),
                'label' => (string) ($adjustment['label'] ?? 'Ajustement valide'),
                'amount' => $amount,
            ];
        }

        $input->salaryLines = $lines;
        $input->context['contract'] = $contract;
        $input->context['contract_components_used'] = array_values(array_filter($contractComponents, static fn(array $row): bool => (float) ($row['contract_amount'] ?? 0) > 0));
        $input->context['adjustments_used'] = $approvedAdjustments;
        $input->context['external_employee_deductions'] = $externalDeductions;
    }

    private function resolveTransportAmount(int $versionId, string $localityCode): float
    {
        $localityCode = strtoupper(trim($localityCode));
        $fallback = 0.0;
        foreach ($this->rules->getTransportRates($versionId) as $rate) {
            $code = strtoupper((string) ($rate['locality_code'] ?? ''));
            if ($localityCode !== '' && $code === $localityCode) {
                return (float) ($rate['monthly_amount'] ?? 0);
            }
            if (in_array($code, ['OTHER', 'AUTRE'], true)) {
                $fallback = (float) ($rate['monthly_amount'] ?? 0);
            }
        }

        return $fallback;
    }
}
