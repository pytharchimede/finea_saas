<?php

class EmployeeContractSalaryComponentService
{
    private EmployeeContractSalaryComponentRepository $components;

    public function __construct(EmployeeContractSalaryComponentRepository $components)
    {
        $this->components = $components;
    }

    public function getComponentsForRhScreen(int $contractId, int $personnelId, int $versionId, string $period): array
    {
        return $this->components->findMergedWithPayrollItems($contractId, $personnelId, $versionId, $period);
    }

    public function saveFromRhForm(array $payload, ?int $userId = null): int
    {
        $payload['created_by'] = $payload['created_by'] ?? $userId;
        $id = $this->components->saveComponent($payload);
        (new PayrollAuditService())->log('CONTRACT_SALARY_COMPONENT_SAVED', [
            'component_id' => $id,
            'contract_id' => (int) ($payload['contract_id'] ?? 0),
            'personnel_id' => (int) ($payload['personnel_id'] ?? 0),
            'label' => 'Composant contractuel enregistre',
        ], ['user_id' => $userId]);

        return $id;
    }

    public function getContractComponentsForPayroll(int $contractId, int $personnelId, int $versionId, string $period): array
    {
        return array_values(array_filter(
            $this->components->findMergedWithPayrollItems($contractId, $personnelId, $versionId, $period),
            static fn(array $row): bool => (float) ($row['contract_amount'] ?? 0) > 0
        ));
    }
}
