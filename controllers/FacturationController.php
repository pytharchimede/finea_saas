<?php

require_once __DIR__ . '/../services/FacturationService.php';

class FacturationController
{
    private FacturationService $service;

    public function __construct(PDO $pdo)
    {
        $this->service = new FacturationService(
            new FactureAchat($pdo),
            new FactureVente($pdo),
            new FournisseurRepository($pdo)
        );
    }

    public function service(): FacturationService
    {
        return $this->service;
    }

    public function achats(): FactureAchat
    {
        return $this->service->achats();
    }

    public function ventes(): FactureVente
    {
        return $this->service->ventes();
    }

    public function dashboardView(bool $fneConfigured): array
    {
        return $this->service->dashboardView($fneConfigured);
    }

    public function achatListView(int $limit = 200): array
    {
        return $this->service->achatListView($limit);
    }

    public function venteListView(int $limit = 200): array
    {
        return $this->service->venteListView($limit);
    }

    public function editAchatView(int $id): array
    {
        return $this->service->editAchatView($id);
    }

    public function editVenteView(int $id): array
    {
        return $this->service->editVenteView($id);
    }
}
