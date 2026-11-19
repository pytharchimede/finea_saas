<?php

require_once __DIR__ . '/../repositories/FactureAchatRepository.php';

class FactureAchat
{
    private FactureAchatRepository $repository;

    public function __construct(PDO $pdo)
    {
        $this->repository = new FactureAchatRepository($pdo);
    }

    public function repository(): FactureAchatRepository
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

    public function create(array $data, ?int $userId = null, ?string $itemsJson = null): array
    {
        return $this->repository->create($data, $userId, $itemsJson);
    }

    public function update(int $id, array $data, ?string $itemsJson = null): array
    {
        return $this->repository->update($id, $data, $itemsJson);
    }

    public function attachFile(int $id, array $file): array
    {
        return $this->repository->attachFile($id, $file);
    }

    public function saveVerifyResult(int $id, array $verifyMeta): array
    {
        return $this->repository->saveVerifyResult($id, $verifyMeta);
    }
}
