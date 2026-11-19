<?php

require_once __DIR__ . '/../model/RhExplanationRequestManager.php';
require_once __DIR__ . '/../controllers/NotificationController.php';

class RhExplanationRepository
{
    private PDO $pdo;
    private RhExplanationRequestManager $manager;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->manager = new RhExplanationRequestManager($pdo);
    }

    public function model(): RhExplanationRequestManager
    {
        return $this->manager;
    }

    public function notifications(): NotificationController
    {
        return new NotificationController($this->pdo);
    }

    public function __call(string $name, array $arguments)
    {
        return $this->manager->{$name}(...$arguments);
    }
}
