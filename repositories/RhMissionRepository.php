<?php

require_once __DIR__ . '/../decaissement/model/MissionOrderManager.php';
require_once __DIR__ . '/../model/Chantier.php';

class RhMissionRepository
{
    private MissionOrderManager $missions;
    private Chantier $chantiers;

    public function __construct(PDO $pdo)
    {
        $this->missions = new MissionOrderManager($pdo);
        $this->chantiers = new Chantier($pdo);
    }

    public function missions(): MissionOrderManager
    {
        return $this->missions;
    }

    public function chantiers(): Chantier
    {
        return $this->chantiers;
    }

    public function getStatusLabels(): array
    {
        return $this->missions->getStatusLabels();
    }

    public function listMissions(array $filters, int $page, int $perPage = 12): array
    {
        return $this->missions->listMissions($filters, $page, $perPage);
    }
}
