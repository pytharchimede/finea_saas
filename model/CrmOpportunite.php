<?php

require_once __DIR__ . '/../repositories/CrmOpportuniteRepository.php';

class CrmOpportunite
{
    private CrmOpportuniteRepository $repository;

    public function __construct(?PDO $pdo = null)
    {
        $this->repository = new CrmOpportuniteRepository($pdo);
    }

    public function create(array $data): int
    {
        return $this->repository->create($data);
    }

    public function updateEtape(int $id, string $etape, ?int $probabilite = null): void
    {
        $this->repository->updateEtape($id, $etape, $probabilite);
    }

    public function listRecent(int $limit = 120): array
    {
        return $this->repository->listRecent($limit);
    }

    public function setNextAction(int $id, ?string $nextActionAt, ?int $durationMin, ?int $eventId = null): void
    {
        $this->repository->setNextAction($id, $nextActionAt, $durationMin, $eventId);
    }
}
