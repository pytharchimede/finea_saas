<?php

require_once __DIR__ . '/../services/RhPersonnelService.php';

class RhPersonnelController
{
    private RhPersonnelService $service;

    public function __construct(PDO $pdo)
    {
        $this->service = new RhPersonnelService(new RhPersonnelRepository($pdo));
    }

    public function service(): RhPersonnelService
    {
        return $this->service;
    }

    public function model(): PersonnelDirectory
    {
        return $this->service->model();
    }

    public function listView(array $query): array
    {
        return $this->service->buildListView($query);
    }

    public function handleExitReasonPost(array $post, array $actor): array
    {
        return $this->service->handleExitReasonPost($post, $actor);
    }
}
