<?php

require_once __DIR__ . '/../repositories/FactureVenteRepository.php';

class FactureVente
{
    private FactureVenteRepository $repository;

    public function __construct(PDO $pdo)
    {
        $this->repository = new FactureVenteRepository($pdo);
    }

    public function repository(): FactureVenteRepository
    {
        return $this->repository;
    }

    public function isReady(): bool
    {
        return $this->repository->isReady();
    }

    public function getInitError(): ?string
    {
        return $this->repository->getInitError();
    }

    public function listLatest(int $limit = 200): array
    {
        return $this->repository->listLatest($limit);
    }

    public function getById(int $id): ?array
    {
        return $this->repository->getById($id);
    }

    public function create(array $data, string $itemsJson, ?int $userId = null): array
    {
        return $this->repository->create($data, $itemsJson, $userId);
    }

    public function update(int $id, array $data, string $itemsJson): array
    {
        return $this->repository->update($id, $data, $itemsJson);
    }

    public function saveSignResult(int $id, array $meta): array
    {
        return $this->repository->saveSignResult($id, $meta);
    }

    public function saveFneVerificationResult(int $id, array $meta): array
    {
        return $this->repository->saveFneVerificationResult($id, $meta);
    }
}
