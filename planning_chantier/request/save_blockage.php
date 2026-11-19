<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';

try {
    $_POST['created_by'] = planning_chantier_user_id();
    $controller = new PlanningBlockageController(new PlanningBlockageRepository($pdo));
    $controller->save($_POST);
    pc_flash_set('success', 'Blocage enregistré et tâche marquée comme bloquée.');
} catch (Throwable $e) {
    pc_flash_set('error', $e->getMessage());
}
pc_redirect((string)($_POST['redirect'] ?? '../dashboard.php'));
