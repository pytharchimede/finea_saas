<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';

try {
    $repo = new PlanningTaskRepository($pdo);
    $controller = new PlanningTaskController($repo);
    $_POST['created_by'] = planning_chantier_user_id();
    $id = $controller->save($_POST);
    pc_flash_set('success', 'Tâche planning enregistrée.');
    pc_redirect('../chantier.php?id=' . (int)$_POST['project_id']);
} catch (Throwable $e) {
    pc_flash_set('error', $e->getMessage());
    pc_redirect('../task_form.php?project_id=' . (int)($_POST['project_id'] ?? 0));
}
