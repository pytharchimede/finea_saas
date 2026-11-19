<?php

require_once __DIR__ . '/../services/RhMissionService.php';

class RhMissionController
{
    private RhMissionService $service;

    public function __construct(PDO $pdo)
    {
        $this->service = new RhMissionService(new RhMissionRepository($pdo));
    }

    public function missions(): MissionOrderManager
    {
        return $this->service->missions();
    }

    public function chantiers(): Chantier
    {
        return $this->service->chantiers();
    }

    public function listView(array $query): array
    {
        return $this->service->buildListView($query);
    }
}
