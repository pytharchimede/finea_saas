<?php

require_once __DIR__ . '/../services/NotificationService.php';

class NotificationController
{
    private NotificationService $service;

    public function __construct(PDO $pdo)
    {
        $this->service = new NotificationService(
            new NotificationRepository($pdo),
            new NotificationManager($pdo)
        );
    }

    public function service(): NotificationService
    {
        return $this->service;
    }

    public function poll(int $userId, array $teams, int $limit = 10, ?int $sinceId = null): array
    {
        return $this->service->pollForUser($userId, $teams, $limit, $sinceId);
    }

    public function markRead(int $userId, array $teams, array $ids, bool $markAll = false): array
    {
        return $this->service->markReadForUser($userId, $teams, $ids, $markAll);
    }

    public function __call(string $name, array $arguments)
    {
        return $this->service->{$name}(...$arguments);
    }
}
