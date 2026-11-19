<?php

require_once __DIR__ . '/../services/DevisModuleService.php';

class DevisModuleController
{
    private DevisModuleService $service;

    public function __construct(PDO $pdo)
    {
        $this->service = new DevisModuleService(new DevisModuleRepository($pdo));
    }

    public function dashboard(): array
    {
        return $this->service->dashboard();
    }

    public function modules(): array
    {
        return $this->service->modules();
    }
}
