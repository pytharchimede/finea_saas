<?php

class PayrollBulkGenerationService
{
    private EmployeeContractService $contracts;
    private PayrollSlipRepository $slips;
    private PayrollSlipGenerationService $generator;

    public function __construct(EmployeeContractService $contracts, PayrollSlipRepository $slips, PayrollSlipGenerationService $generator)
    {
        $this->contracts = $contracts;
        $this->slips = $slips;
        $this->generator = $generator;
    }

    public function generateForPeriod(string $period, ?array $personnelIds = null, ?int $userId = null): array
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
            throw new RuntimeException('Periode invalide.');
        }

        $eligible = $this->contracts->getEmployeesEligibleForPayroll($period);
        $allowed = $personnelIds !== null ? array_flip(array_map('intval', $personnelIds)) : null;
        $details = [];
        $generated = 0;
        $skipped = 0;
        $failed = 0;

        (new PayrollAuditService())->log('PAYSLIP_BULK_GENERATION_STARTED', ['period' => $period, 'label' => 'Generation groupee'], ['user_id' => $userId]);

        foreach ($eligible as $row) {
            $personnelId = (int) ($row['personnel_id'] ?? 0);
            if ($personnelId <= 0 || ($allowed !== null && !isset($allowed[$personnelId]))) {
                continue;
            }

            try {
                $existing = $this->slips->findByPersonnelAndPeriod($personnelId, $period);
                if ($existing !== null) {
                    $skipped++;
                    $details[] = ['personnel_id' => $personnelId, 'status' => 'skipped', 'message' => 'Bulletin deja genere'];
                    continue;
                }

                $slipId = $this->generator->generate(new PayrollInputDto([
                    'personnel_id' => $personnelId,
                    'period' => $period,
                    'country_code' => 'CI',
                    'currency_code' => 'FCFA',
                    'employee' => ['id' => $personnelId],
                    'salary_lines' => [],
                    'context' => ['auto_from_contract' => true],
                ]), ['user_id' => $userId]);
                $generated++;
                $details[] = ['personnel_id' => $personnelId, 'status' => 'generated', 'slip_id' => $slipId, 'message' => 'Bulletin genere'];
            } catch (Throwable $exception) {
                $failed++;
                $details[] = ['personnel_id' => $personnelId, 'status' => 'failed', 'message' => $exception->getMessage()];
                (new PayrollAuditService())->log('PAYSLIP_GENERATION_FAILED', [
                    'personnel_id' => $personnelId,
                    'period' => $period,
                    'label' => $exception->getMessage(),
                ], ['user_id' => $userId]);
            }
        }

        $report = [
            'period' => $period,
            'total_employees' => count($eligible),
            'generated' => $generated,
            'skipped' => $skipped,
            'failed' => $failed,
            'details' => $details,
        ];
        (new PayrollAuditService())->log('PAYSLIP_BULK_GENERATION_COMPLETED', $report + ['label' => 'Generation groupee terminee'], ['user_id' => $userId]);

        return $report;
    }
}
