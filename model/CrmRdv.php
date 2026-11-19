<?php

require_once __DIR__ . '/../repositories/CrmRdvRepository.php';

class CrmRdv
{
    private CrmRdvRepository $repository;

    public function __construct(?PDO $pdo = null)
    {
        $this->repository = new CrmRdvRepository($pdo);
    }

    public function getById(int $id): ?array
    {
        return $this->repository->getById($id);
    }

    public function create(array $data): int
    {
        return $this->repository->create($data);
    }

    public function countToday(): int
    {
        return $this->repository->countToday();
    }

    public function countNextDays(int $days = 7): int
    {
        return $this->repository->countNextDays($days);
    }

    public function listUpcoming(int $limit = 50): array
    {
        return $this->repository->listUpcoming($limit);
    }
}
