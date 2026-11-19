<?php

require_once __DIR__ . '/../model/MailboxCacheManager.php';
require_once __DIR__ . '/../model/MailPasswordStore.php';

class EmailService
{
    private MailboxCacheManager $cacheManager;

    public function __construct(MailboxCacheManager $cacheManager)
    {
        $this->cacheManager = $cacheManager;
    }

    public function cache(): MailboxCacheManager
    {
        return $this->cacheManager;
    }

    public function hydrateMailboxPasswordFromStorage(int $userId): void
    {
        MailPasswordStore::hydrateSessionFromDb($userId);
    }

    public function persistMailboxPasswordIfNeeded(int $userId): void
    {
        MailPasswordStore::persistSessionToDbIfNeeded($userId);
    }

    public function markMailboxPasswordAsManual(): void
    {
        MailPasswordStore::markPasswordAsManual();
    }

    public function saveMailboxPasswordForUser(int $userId, string $password): bool
    {
        return MailPasswordStore::savePasswordForUser($userId, $password);
    }

    public function clearMailboxPasswordForUser(int $userId): bool
    {
        return MailPasswordStore::clearPasswordForUser($userId);
    }

    public function isMailboxPasswordStorageEnabled(): bool
    {
        return MailPasswordStore::isEnabled();
    }
}
