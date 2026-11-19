<?php

declare(strict_types=1);

namespace EmploiDuTemps\Controllers;

require_once dirname(__DIR__) . '/services/EmployeeScheduleModuleService.php';

use EmploiDuTemps\Repositories\EmployeeScheduleModuleRepository;
use EmploiDuTemps\Services\EmployeeScheduleModuleService;

class EmployeeScheduleModuleController
{
    private EmployeeScheduleModuleService $service;

    public function __construct(string $projectRoot)
    {
        $repository = new EmployeeScheduleModuleRepository();
        $this->service = new EmployeeScheduleModuleService($repository, $projectRoot);
    }

    /**
     * @return array<string,mixed>
     */
    public function pageContext(): array
    {
        return $this->service->buildPageContext();
    }
}
