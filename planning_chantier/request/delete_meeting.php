<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';

try {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        throw new RuntimeException('Réunion invalide.');
    }
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM planning_chantier_meeting_participants WHERE meeting_id = :id')->execute(['id' => $id]);
    $pdo->prepare('DELETE FROM planning_chantier_meeting_items WHERE meeting_id = :id')->execute(['id' => $id]);
    $pdo->prepare('DELETE FROM planning_chantier_meetings WHERE id = :id')->execute(['id' => $id]);
    $pdo->commit();
    pc_flash_set('success', 'Réunion supprimée.');
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    pc_flash_set('error', $e->getMessage());
}
pc_redirect('../reunion.php');
