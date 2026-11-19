<?php

require_once __DIR__ . '/../repositories/CrmProspectRepository.php';

class CrmProspect
{
    private CrmProspectRepository $repository;

    public function __construct(?PDO $pdo = null)
    {
        $this->repository = new CrmProspectRepository($pdo);
    }

    public function create(array $data): int
    {
        return $this->repository->create($data);
    }

    public function listLatest(int $limit = 200): array
    {
        return $this->repository->listLatest($limit);
    }

    public function listForSelect(int $limit = 500): array
    {
        return $this->repository->listForSelect($limit);
    }

    public function countAll(): int
    {
        return $this->repository->countAll();
    }
}
