<?php

require_once __DIR__ . '/../repositories/NotificationRepository.php';
require_once __DIR__ . '/../model/NotificationManager.php';

class NotificationService
{
    private NotificationRepository $repository;
    private ?NotificationManager $legacyManager;

    public function __construct(NotificationRepository $repository, ?NotificationManager $legacyManager = null)
    {
        $this->repository = $repository;
        $this->legacyManager = $legacyManager;
    }

    public static function getKnownTeams(): array
    {
        return NotificationRepository::getKnownTeams();
    }

    public static function getAdminEmailTestScenarios(): array
    {
        return NotificationManager::getAdminEmailTestScenarios();
    }

    public static function inferUserTeamsFromSession(): array
    {
        $teams = [];

        if (function_exists('hasPermission') && hasPermission('acces_admin')) {
            $teams[] = 'admin';
        }
        if (function_exists('hasPermission') && hasPermission('acces_rh')) {
            $teams[] = 'rh';
        }
        if (function_exists('hasPermission') && (hasPermission('acces_compta') || hasPermission('acces_point_financier') || hasPermission('acces_relink_fiches_debourse'))) {
            $teams[] = 'finance';
        }
        if (function_exists('hasPermission') && hasPermission('acces_logistique')) {
            $teams[] = 'logistique';
        }
        if (function_exists('hasPermission') && hasPermission('acces_hse')) {
            $teams[] = 'hse';
        }
        if (function_exists('hasPermission') && hasPermission('acces_ticketing')) {
            $teams[] = 'ticketing';
        }

        return array_values(array_unique($teams));
    }

    public function pollForUser(int $userId, array $teams, int $limit = 10, ?int $sinceId = null): array
    {
        $items = $this->repository->listLatestForUser($userId, $teams, $limit, $sinceId);
        $latestId = 0;
        foreach ($items as $item) {
            $latestId = max($latestId, (int) ($item['id'] ?? 0));
        }

        return [
            'unread_count' => $this->repository->countUnread($userId, $teams),
            'latest_id' => $latestId,
            'notifications' => $items,
        ];
    }

    public function markReadForUser(int $userId, array $teams, array $ids, bool $markAll = false): array
    {
        $affected = $markAll
            ? $this->repository->markAllRead($userId, $teams)
            : $this->repository->markRead($userId, $ids);

        return [
            'affected' => $affected,
            'unread_count' => $this->repository->countUnread($userId, $teams),
        ];
    }

    public function countUnread(int $userId, array $teams): int
    {
        return $this->repository->countUnread($userId, $teams);
    }

    public function listLatestForUser(int $userId, array $teams, int $limit = 10, ?int $sinceId = null): array
    {
        return $this->repository->listLatestForUser($userId, $teams, $limit, $sinceId);
    }

    public function markRead(int $userId, array $notificationIds): int
    {
        return $this->repository->markRead($userId, $notificationIds);
    }

    public function markAllRead(int $userId, array $teams): int
    {
        return $this->repository->markAllRead($userId, $teams);
    }

    public function getRule(string $eventKey, ?string $subKey = null): array
    {
        return $this->repository->getRule($eventKey, $subKey);
    }

    public function upsertRule(string $eventKey, ?string $subKey, string $label, bool $enabled, string $recipientTeam, array $channels, ?string $titleTemplate = null, ?string $messageTemplate = null, array $excludedEmails = []): void
    {
        $this->repository->upsertRule($eventKey, $subKey, $label, $enabled, $recipientTeam, $channels, $titleTemplate, $messageTemplate, $excludedEmails);
    }

    public function listRulesForEvent(string $eventKey): array
    {
        return $this->repository->listRulesForEvent($eventKey);
    }

    public function listCommunicationPushRecipients(): array
    {
        return $this->repository->listCommunicationPushRecipients();
    }

    public function saveCommunicationPushRecipients(array $userIds): void
    {
        $this->repository->saveCommunicationPushRecipients($userIds);
    }

    public function __call(string $name, array $arguments)
    {
        if ($this->legacyManager === null) {
            throw new BadMethodCallException('Notification method unavailable: ' . $name);
        }

        return $this->legacyManager->{$name}(...$arguments);
    }
}
