<?php

require_once __DIR__ . '/../repositories/RhMissionRepository.php';

class RhMissionService
{
    private RhMissionRepository $repository;

    public function __construct(RhMissionRepository $repository)
    {
        $this->repository = $repository;
    }

    public function missions(): MissionOrderManager
    {
        return $this->repository->missions();
    }

    public function chantiers(): Chantier
    {
        return $this->repository->chantiers();
    }

    public function buildListView(array $query): array
    {
        $statusLabels = $this->repository->getStatusLabels();
        $filters = [
            'q' => trim((string) ($query['q'] ?? '')),
            'status' => '',
            'statuses' => array_values(array_filter(array_map('strval',(array)($query['statuses']??[])))),
            'chantier_ids' => array_values(array_filter(array_map('intval',(array)($query['chantier_ids']??[])))),
            'personnel_ids' => array_values(array_filter(array_map('intval',(array)($query['personnel_ids']??[])))),
            'validator_ids' => array_values(array_filter(array_map('intval',(array)($query['validator_ids']??[])))),
        ];
        $filters['statuses']=array_values(array_filter($filters['statuses'],static fn($status)=>isset($statusLabels[$status])));

        $page = max(1, (int) ($query['page'] ?? 1));

        return [
            'statusLabels' => $statusLabels,
            'filters' => $filters,
            'page' => $page,
            'result' => $this->repository->listMissions($filters, $page, 12),
        ];
    }
}
