<?php

require_once __DIR__ . '/../services/EmailService.php';

class EmailController
{
    private EmailService $service;

    public function __construct(?PDO $pdo = null)
    {
        $this->service = new EmailService(
            new MailboxCacheManager(new MailboxCacheRepository($pdo))
        );
    }

    public function service(): EmailService
    {
        return $this->service;
    }

    public function cache(): MailboxCacheManager
    {
        return $this->service->cache();
    }

    public function hydrateMailboxPasswordFromStorage(int $userId): void
    {
        $this->service->hydrateMailboxPasswordFromStorage($userId);
    }

    public function persistMailboxPasswordIfNeeded(int $userId): void
    {
        $this->service->persistMailboxPasswordIfNeeded($userId);
    }

    public function markMailboxPasswordAsManual(): void
    {
        $this->service->markMailboxPasswordAsManual();
    }

    public function saveMailboxPasswordForUser(int $userId, string $password): bool
    {
        return $this->service->saveMailboxPasswordForUser($userId, $password);
    }

    public function clearMailboxPasswordForUser(int $userId): bool
    {
        return $this->service->clearMailboxPasswordForUser($userId);
    }

    public function isMailboxPasswordStorageEnabled(): bool
    {
        return $this->service->isMailboxPasswordStorageEnabled();
    }
}
