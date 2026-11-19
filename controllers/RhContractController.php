<?php

require_once __DIR__ . '/../services/RhContractService.php';

class RhContractController
{
    private RhContractService $service;

    public function __construct(PDO $pdo)
    {
        $this->service = new RhContractService(new RhContractRepository($pdo));
    }

    public function service(): RhContractService
    {
        return $this->service;
    }

    public function model(): RhContractManager
    {
        return $this->service->model();
    }

    public function handleSettingsPost(array $post, array $actor): array
    {
        return $this->service->saveSettings((array) ($post['settings'] ?? []), $actor);
    }
}
