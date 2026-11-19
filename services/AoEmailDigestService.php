<?php

require_once __DIR__ . '/AoAlertService.php';
require_once __DIR__ . '/AoCollectiveDigestService.php';
require_once __DIR__ . '/../repositories/AoOpportunityRepository.php';

class AoEmailDigestService
{
    private AoAlertService $alerts;
    private AoOpportunityRepository $opportunities;
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->alerts = new AoAlertService($pdo);
        $this->opportunities = new AoOpportunityRepository($pdo);
    }

    public function run(): array
    {
        $pending = $this->opportunities->pendingForAlerts(2000);
        return array_merge(['opportunities' => count($pending), 'collective' => (new AoCollectiveDigestService($this->pdo))->run()], $this->alerts->sendPendingDigests($pending));
    }
}

