<?php

class PayrollAdjustmentService
{
    private PayrollAdjustmentRepository $adjustments;

    public function __construct(PayrollAdjustmentRepository $adjustments)
    {
        $this->adjustments = $adjustments;
    }

    public function getApprovedAdjustmentsForPayroll(int $personnelId, string $period): array
    {
        return $this->adjustments->findApprovedByPersonnelAndPeriod($personnelId, $period);
    }

    public function saveAdjustmentFromForm(array $payload, ?int $userId = null): int
    {
        $payload['created_by'] = $payload['created_by'] ?? $userId;
        $id = $this->adjustments->saveAdjustment($payload);
        (new PayrollAuditService())->log('PAYROLL_ADJUSTMENT_CREATED', [
            'adjustment_id' => $id,
            'personnel_id' => (int) ($payload['personnel_id'] ?? 0),
            'period' => (string) ($payload['payroll_period'] ?? ''),
            'label' => 'Ajustement de paie cree',
        ], ['user_id' => $userId]);

        return $id;
    }

    public function approveAdjustment(int $id, int $userId): bool
    {
        $approved = $this->adjustments->approveAdjustment($id, $userId);
        if ($approved) {
            (new PayrollAuditService())->log('PAYROLL_ADJUSTMENT_APPROVED', [
                'adjustment_id' => $id,
                'label' => 'Ajustement de paie valide',
            ], ['user_id' => $userId]);
        }

        return $approved;
    }

    public function rejectAdjustment(int $id, int $userId): bool
    {
        $rejected = $this->adjustments->rejectAdjustment($id, $userId);
        if ($rejected) {
            (new PayrollAuditService())->log('PAYROLL_ADJUSTMENT_REJECTED', [
                'adjustment_id' => $id,
                'label' => 'Ajustement de paie rejete',
            ], ['user_id' => $userId]);
        }

        return $rejected;
    }
}
