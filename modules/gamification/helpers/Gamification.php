<?php

require_once __DIR__ . '/../../../model/Database.php';
require_once __DIR__ . '/../repositories/GamificationRepository.php';
require_once __DIR__ . '/../services/GamificationService.php';

if (!class_exists('Gamification')) {
    class Gamification
    {
        public static function trigger(string $eventKey, int $userId, ?int $referenceId = null, ?string $description = null): array
        {
            $pdo = \Database::getConnection();
            $repo = new \GamificationRepository($pdo);
            $service = new \GamificationService($repo);

            return $service->trigger($eventKey, $userId, $referenceId, $description);
        }
    }
}
