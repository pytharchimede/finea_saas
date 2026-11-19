<?php

require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../../model/Database.php';
require_once __DIR__ . '/../../modules/gamification/repositories/GamificationRepository.php';
require_once __DIR__ . '/../../modules/gamification/services/GamificationService.php';

header('Content-Type: application/json; charset=UTF-8');

function gam_get_json_body(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function gam_current_user_id(): int
{
    return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
}

function gam_is_admin(): bool
{
    $perms = is_array($_SESSION['permissions'] ?? null) ? $_SESSION['permissions'] : [];
    return !empty($perms['acces_admin'] ?? 0);
}

function gam_service(): GamificationService
{
    $pdo = \Database::getConnection();
    $repo = new \GamificationRepository($pdo);
    return new \GamificationService($repo);
}
