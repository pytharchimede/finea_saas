<?php

declare(strict_types=1);

require_once __DIR__ . '/../repositories/LogisticsFuelRepository.php';

final class LogisticsFuelService
{
    public function __construct(private LogisticsFuelRepository $repository, private ?LogisticsBusinessService $business = null) {}

    public function list(array $filters = []): array { return $this->repository->all($filters); }
    public function find(int $id): ?array { return $this->repository->find($id); }
    public function save(array $data): int { return $this->repository->save($this->prepare($data)); }
    public function delete(int $id): void { $this->repository->delete($id); }

    private function prepare(array $data): array
    {
        if (isset($data['quantity'], $data['unit_price']) && array_key_exists('total_cost', $data)) {
            $data['total_cost'] = $this->business ? $this->business->normalizeMoney((float)$data['quantity'], (float)$data['unit_price']) : ((float)$data['quantity'] * (float)$data['unit_price']);
        }
        if (isset($data['quantity'], $data['unit_price']) && 'LogisticsFuelService' === 'LogisticsFuelService') {
            $anomaly = $this->business?->detectFuelAnomaly($data) ?? ['is_anomaly'=>0,'anomaly_reason'=>null];
            $data['is_anomaly'] = $anomaly['is_anomaly'];
            $data['anomaly_reason'] = $anomaly['anomaly_reason'];
            $data['total_cost'] = $this->business ? $this->business->normalizeMoney((float)$data['quantity'], (float)$data['unit_price']) : ((float)$data['quantity'] * (float)$data['unit_price']);
        }
        return $data;
    }
}
