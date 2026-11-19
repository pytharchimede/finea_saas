<?php

require_once __DIR__ . '/../repositories/FuelVoucherMaintenanceRepository.php';

class FuelVoucherMaintenanceService
{
    private FuelVoucherMaintenanceRepository $repository;

    public function __construct(FuelVoucherMaintenanceRepository $repository)
    {
        $this->repository = $repository;
    }

    public function deactivateWithoutReceipt(string $conditionsExtra = ''): array
    {
        $toDisable = $this->repository->countActiveWithoutReceipt($conditionsExtra);
        if ($toDisable === 0) {
            return ['to_disable' => 0, 'affected' => 0];
        }

        return [
            'to_disable' => $toDisable,
            'affected' => $this->repository->deactivateActiveWithoutReceipt($conditionsExtra),
        ];
    }
}
