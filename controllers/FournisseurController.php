<?php

require_once __DIR__ . '/../services/FournisseurService.php';

class FournisseurController
{
    private FournisseurService $service;

    public function __construct(PDO $pdo)
    {
        $this->service = new FournisseurService(
            new FournisseurRepository($pdo),
            new FournisseurWorkflowNotificationService($pdo)
        );
    }

    public function service(): FournisseurService
    {
        return $this->service;
    }

    public function model(): FournisseurRepository
    {
        return $this->service->model();
    }

    public function dashboardView(): array
    {
        return $this->service->buildDashboardView();
    }

    public function listView(array $query, int $currentUserId, bool $isEmployeeOnly): array
    {
        return $this->service->buildListView($query, $currentUserId, $isEmployeeOnly);
    }

    public function createView(): array
    {
        return $this->service->buildCreateView();
    }

    public function editView(int $id): array
    {
        return $this->service->buildEditView($id);
    }

    public function detailsView(int $supplierId): array
    {
        return $this->service->buildDetailsView($supplierId);
    }

    public function handleCreate(array $post, array $files, int $currentUserId): array
    {
        return $this->service->handleCreate($post, $files, $currentUserId);
    }

    public function handleEditPost(int $id, array $supplier, array $post, array $files, int $currentUserId, bool $isValidator): array
    {
        return $this->service->handleEditPost($id, $supplier, $post, $files, $currentUserId, $isValidator);
    }

    public function handleCategoryPost(array $post): array
    {
        return $this->service->handleCategoryPost($post);
    }

    public function handleLegalFormPost(array $post): array
    {
        return $this->service->handleLegalFormPost($post);
    }
}
