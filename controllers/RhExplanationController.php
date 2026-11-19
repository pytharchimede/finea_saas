<?php

require_once __DIR__ . '/../services/RhExplanationService.php';

class RhExplanationController
{
    private RhExplanationService $service;

    public function __construct(PDO $pdo)
    {
        $this->service = new RhExplanationService(new RhExplanationRepository($pdo));
    }

    public function service(): RhExplanationService
    {
        return $this->service;
    }

    public function notifications(): NotificationController
    {
        return $this->service->notifications();
    }

    public function handleRequestPost(array $post, array $actor, string $filter): array
    {
        return $this->service->handleRequestPost($post, $actor, $filter);
    }

    public function handleHierarchyPost(array $post): array
    {
        return $this->service->handleHierarchyPost($post);
    }
}
