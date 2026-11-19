<?php
session_start();

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../controller/PopupNotificationController.php';

function currentUserId(): int
{
    return (int)(
        $_SESSION['user_id']
        ?? $_SESSION['id_user']
        ?? $_SESSION['user']['id']
        ?? 0
    );
}

function clientIp(): ?string
{
    return $_SERVER['HTTP_CLIENT_IP']
        ?? $_SERVER['HTTP_X_FORWARDED_FOR']
        ?? $_SERVER['REMOTE_ADDR']
        ?? null;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new RuntimeException('Méthode non autorisée.');
    }

    $userId = currentUserId();

    if ($userId <= 0) {
        throw new RuntimeException('Utilisateur non connecté.');
    }

    $notificationId = (int)($_POST['notification_id'] ?? 0);
    $actionId = (int)($_POST['action_id'] ?? 0);
    if ($notificationId <= 0) {
        throw new RuntimeException('Notification invalide.');
    }

    $pdo = Database::getConnection();
    $service = (new PopupNotificationController($pdo))->service();
    echo json_encode($service->submitResponse(
        $notificationId,
        $userId,
        $actionId,
        clientIp(),
        $_SERVER['HTTP_USER_AGENT'] ?? null
    ));
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
