<?php

require_once __DIR__ . '/../repositories/MailboxCacheRepository.php';

class MailboxCacheManager
{
    private MailboxCacheRepository $repository;

    public function __construct($dependency = null)
    {
        if ($dependency instanceof MailboxCacheRepository) {
            $this->repository = $dependency;
            return;
        }

        $this->repository = new MailboxCacheRepository($dependency instanceof PDO ? $dependency : null);
    }

    public function getSummary(int $userId, string $folder, int $uid): ?array
    {
        return $this->repository->getSummary($userId, $folder, $uid);
    }

    public function saveSummary(int $userId, string $folder, array $email): void
    {
        $this->repository->saveSummary($userId, $folder, $email);
    }

    public function getFullMessage(int $userId, string $folder, int $uid): ?array
    {
        return $this->repository->getFullMessage($userId, $folder, $uid);
    }

    public function saveFullMessage(int $userId, string $folder, array $message): void
    {
        $this->repository->saveFullMessage($userId, $folder, $message);
    }

    public function markAsRead(int $userId, string $folder, int $uid): void
    {
        $this->repository->markAsRead($userId, $folder, $uid);
    }

    public function getFolderList(int $userId, string $cacheKey = 'default'): ?array
    {
        return $this->repository->getFolderList($userId, $cacheKey);
    }

    public function saveFolderList(int $userId, array $folders, string $delimiter = '.', string $cacheKey = 'default'): void
    {
        $this->repository->saveFolderList($userId, $folders, $delimiter, $cacheKey);
    }

    public function clearUserCache(int $userId, ?string $folder = null): void
    {
        $this->repository->clearUserCache($userId, $folder);
    }

    public function clearMessageCache(int $userId, ?string $folder = null): void
    {
        $this->repository->clearMessageCache($userId, $folder);
    }

    public function clearFolderCache(int $userId, ?string $cacheKey = null): void
    {
        $this->repository->clearFolderCache($userId, $cacheKey);
    }

    public function getMessageCacheStatus(int $userId, string $folder): array
    {
        return $this->repository->getMessageCacheStatus($userId, $folder);
    }

    public function getFolderCacheStatus(int $userId, string $cacheKey = 'default'): array
    {
        return $this->repository->getFolderCacheStatus($userId, $cacheKey);
    }

    public function cleanupExpiredCache(?int $userId = null): array
    {
        return $this->repository->cleanupExpiredCache($userId);
    }

    public function getReadCacheTtlSeconds(): int
    {
        return $this->repository->getReadCacheTtlSeconds();
    }

    public function getUnreadCacheTtlSeconds(): int
    {
        return $this->repository->getUnreadCacheTtlSeconds();
    }

    public function getFolderCacheTtlSeconds(): int
    {
        return $this->repository->getFolderCacheTtlSeconds();
    }
}
