<?php

require_once __DIR__ . '/../model/DocumentManager.php';

class DocumentationRepository
{
    private PDO $pdo;
    private DocumentManager $manager;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->manager = new DocumentManager($pdo);
    }

    public function manager(): DocumentManager
    {
        $this->manager->ensureTables();
        return $this->manager;
    }

    public function userContext(int $userId, array $permissions): array
    {
        return $this->manager()->getUserDocumentationContext($userId, $permissions);
    }
}
