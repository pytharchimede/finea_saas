<?php

class EmployeeContractService
{
    private EmployeeContractRepository $contracts;

    public function __construct(EmployeeContractRepository $contracts)
    {
        $this->contracts = $contracts;
    }

    public function getActiveContractForPayroll(int $personnelId, string $period): array
    {
        $contract = $this->contracts->findActiveContractForPeriod($personnelId, $period);
        if ($contract === null) {
            throw new RuntimeException('Aucun contrat actif trouve pour cet employe sur la periode.');
        }

        return $contract;
    }

    public function saveContractFromRhForm(array $payload, ?int $userId = null): int
    {
        $payload['created_by'] = $payload['created_by'] ?? $userId;
        $id = $this->contracts->saveContract($payload);
        (new PayrollAuditService())->log('EMPLOYEE_CONTRACT_SAVED', [
            'contract_id' => $id,
            'personnel_id' => (int) ($payload['personnel_id'] ?? 0),
            'label' => 'Contrat employe enregistre',
        ], ['user_id' => $userId]);

        return $id;
    }

    public function getEmployeesEligibleForPayroll(string $period): array
    {
        return $this->contracts->getEmployeesWithActiveContractsForPeriod($period);
    }
}
