<?php

class PayrollSlipGenerationService
{
    private PayrollCalculationService $calculator;
    private PayrollSlipRepository $slips;
    private PayrollAuditService $audit;

    public function __construct(PayrollCalculationService $calculator, PayrollSlipRepository $slips, PayrollAuditService $audit)
    {
        $this->calculator = $calculator;
        $this->slips = $slips;
        $this->audit = $audit;
    }

    public function preview(PayrollInputDto $input): array
    {
        return $this->calculator->calculate($input);
    }

    public function generate(PayrollInputDto $input, array $actor = []): int
    {
        if ($this->slips->isPeriodLocked($input->countryCode, $input->period)) {
            throw new RuntimeException('La periode de paie ' . $input->period . ' est cloturee.');
        }

        $existingSlip = $this->slips->findByPersonnelAndPeriod($input->personnelId, $input->period);
        $forceRecalculate = !empty($input->context['force_recalculate']);
        if ($existingSlip !== null) {
            $status = strtolower((string) ($existingSlip['status'] ?? 'draft'));
            if (!$forceRecalculate) {
                return (int) $existingSlip['id'];
            }
            if (!in_array($status, ['draft', 'brouillon'], true)) {
                throw new RuntimeException('Le bulletin existe deja et ne peut pas etre recalcule car il est deja valide ou cloture.');
            }

            return (int) $existingSlip['id'];
        }

        $calculation = $this->calculator->calculate($input);
        $slip = [
            'personnel_id' => $input->personnelId,
            'payroll_period' => $input->period,
            'rule_version_id' => (int) $calculation['version']['id'],
            'currency_code' => $input->currencyCode,
            'gross_salary' => $calculation['gross_salary'],
            'sbi' => $calculation['sbi']['amount'],
            'sbs' => $calculation['sbs']['amount'],
            'taxable_base' => $calculation['tax']['base_imposable'],
            'gross_tax' => $calculation['tax']['gross_tax'],
            'ricf' => $calculation['ricf']['amount'],
            'net_tax' => $calculation['net_tax'],
            'employee_contributions' => $calculation['cnps']['employee_total'],
            'employer_contributions' => $calculation['cnps']['employer_total'],
            'net_pay' => $calculation['net_pay'],
            'created_by_user_id' => $actor['user_id'] ?? null,
            'created_by_name' => $actor['user_name'] ?? null,
        ];

        $snapshot = [
            'input' => [
                'personnel_id' => $input->personnelId,
                'period' => $input->period,
                'country_code' => $input->countryCode,
                'family_situation' => $input->familySituation,
            ],
            'contract' => $calculation['context']['contract'] ?? null,
            'contract_components_used' => $calculation['context']['contract_components_used'] ?? [],
            'adjustments_used' => $calculation['context']['adjustments_used'] ?? [],
            'payroll_lines' => $calculation['salary_lines'] ?? [],
            'sbi' => $calculation['sbi'] ?? [],
            'sbs' => $calculation['sbs'] ?? [],
            'cnps' => $calculation['cnps'] ?? [],
            'tax' => $calculation['tax'] ?? [],
            'ricf' => $calculation['ricf'] ?? [],
            'net' => [
                'net_tax' => $calculation['net_tax'] ?? 0,
                'net_pay' => $calculation['net_pay'] ?? 0,
            ],
            'calculation' => $calculation,
        ];

        $slipId = $this->slips->createSlip($slip, $snapshot);
        $this->audit->log('slip_generated', [
            'slip_id' => $slipId,
            'label' => 'Bulletin ' . $input->period,
            'rule_version' => $calculation['version']['code'] ?? null,
            'net_pay' => $calculation['net_pay'],
        ], $actor);

        return $slipId;
    }
}
