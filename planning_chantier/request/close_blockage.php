<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';

try {
    $controller = new PlanningBlockageController(new PlanningBlockageRepository($pdo));
    $controller->close((int)$_POST['id']);
    pc_flash_set('success', 'Blocage marqué comme résolu.');
} catch (Throwable $e) {
    pc_flash_set('error', $e->getMessage());
}
pc_redirect('../reunion.php');
