<?php

require_once __DIR__ . '/../services/CrmService.php';
require_once __DIR__ . '/../repositories/CrmSchemaRepository.php';

class CrmController
{
    private CrmService $service;

    public function __construct()
    {
        (new CrmSchemaRepository())->ensureTables();
        $this->service = new CrmService(
            new CrmClientRepository(),
            new CrmProspectRepository(),
            new CrmRdvRepository(),
            new CrmOpportuniteRepository(),
            new CrmDashboardRepository(),
            new CrmAuditRepository(),
            new CrmScheduleRepository(),
            new CrmNotificationRepository()
        );
    }

    public function service(): CrmService
    {
        return $this->service;
    }

    public function createClient(array $post): array
    {
        return $this->service->createClient($post);
    }

    public function createProspect(array $post, array $session): array
    {
        return $this->service->createProspect($post, $session);
    }

    public function createRdv(array $post, array &$session): array
    {
        return $this->service->createRdv($post, $session);
    }

    public function updateOpportunityStage(array $post): array
    {
        return $this->service->updateOpportunityStage($post);
    }

    public function setOpportunityNextAction(array $post, array &$session): array
    {
        return $this->service->setOpportunityNextAction($post, $session);
    }

    public function createOpportunity(array $post, array &$session): array
    {
        return $this->service->createOpportunity($post, $session);
    }

    public function isScheduleReady(): bool
    {
        return $this->service->isScheduleReady();
    }

    public function resolvePersonnelIdByEmail(string $email): ?int
    {
        return $this->service->resolvePersonnelIdByEmail($email);
    }

    public function listLatestClients(int $limit = 200): array
    {
        return $this->service->listLatestClients($limit);
    }

    public function listClientsForSelect(int $limit = 1200): array
    {
        return $this->service->listClientsForSelect($limit);
    }

    public function listClientsForProspectForm(int $limit = 2000): array
    {
        return $this->service->listClientsForProspectForm($limit);
    }

    public function listLatestProspects(int $limit = 200): array
    {
        return $this->service->listLatestProspects($limit);
    }

    public function listProspectsForSelect(int $limit = 1000): array
    {
        return $this->service->listProspectsForSelect($limit);
    }

    public function listUpcomingRdv(int $limit = 60): array
    {
        return $this->service->listUpcomingRdv($limit);
    }

    public function listRecentOpportunities(int $limit = 160): array
    {
        return $this->service->listRecentOpportunities($limit);
    }

    public function buildDashboardData(int $currentUserId, int $personnelId, bool $scheduleReady): array
    {
        return $this->service->buildDashboardData($currentUserId, $personnelId, $scheduleReady);
    }
}
