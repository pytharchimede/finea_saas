<?php

require_once __DIR__ . '/../services/DocumentationService.php';

class DocumentationController
{
    private DocumentationService $service;

    public function __construct(PDO $pdo)
    {
        $this->service = new DocumentationService(new DocumentationRepository($pdo));
    }

    public function boot(int $userId, array $permissions): array
    {
        return $this->service->boot($userId, $permissions);
    }
}
