<?php

declare(strict_types=1);

require_once __DIR__ . '/LogisticsBootstrap.php';
require_once __DIR__ . '/../services/LogisticsTripService.php';

final class LogisticsTripController
{
    private LogisticsTripService $service;
    public function __construct()
    {
        $pdo = LogisticsBootstrap::pdo();
        $this->service = new LogisticsTripService(new LogisticsTripRepository($pdo), new LogisticsBusinessService($pdo));
    }
    public function index(array $filters = []): array { return ['items'=>$this->service->list($filters), 'edit'=> isset($filters['id']) ? $this->service->find((int)$filters['id']) : null]; }
    public function save(array $data): int { return $this->service->save($data); }
    public function delete(array $data): void { $this->service->delete((int)($data['id'] ?? 0)); }
}
