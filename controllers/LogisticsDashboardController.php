<?php

declare(strict_types=1);

require_once __DIR__ . '/LogisticsBootstrap.php';
require_once __DIR__ . '/../services/LogisticsDashboardService.php';

final class LogisticsDashboardController
{
    public function index(): array
    {
        $pdo = LogisticsBootstrap::pdo();
        return (new LogisticsDashboardService(new LogisticsDashboardRepository($pdo)))->viewData();
    }
}
