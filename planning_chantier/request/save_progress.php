<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';

try {
    $service = new PlanningProgressService(new PlanningTaskRepository($pdo));
    $service->updateTaskProgress((int)$_POST['task_id'], (float)$_POST['avancement_reel'], trim((string)($_POST['commentaire'] ?? '')), planning_chantier_user_id());
    pc_flash_set('success', 'Avancement mis à jour.');
} catch (Throwable $e) {
    pc_flash_set('error', $e->getMessage());
}
pc_redirect((string)($_POST['redirect'] ?? '../dashboard.php'));
