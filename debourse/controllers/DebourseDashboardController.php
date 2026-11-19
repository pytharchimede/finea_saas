<?php

declare(strict_types=1);

namespace Debourse\Controllers;

require_once dirname(__DIR__) . '/repositories/DebourseRepository.php';
require_once dirname(__DIR__) . '/services/DebourseDashboardService.php';

use Debourse\Repositories\DebourseRepository;
use Debourse\Services\DebourseDashboardService;
use PDO;

class DebourseDashboardController
{
    private DebourseDashboardService $service;

    public function __construct(PDO $pdo)
    {
        $this->service = new DebourseDashboardService(new DebourseRepository($pdo));
    }

    public function handle(): array
    {
        return $this->service->dashboard();
    }

    public function listing(?int $chantierId, string $search, string $client): array
    {
        return $this->service->listing($chantierId, $search, $client);
    }
}
