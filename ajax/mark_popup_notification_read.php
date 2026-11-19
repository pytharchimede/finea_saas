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
    $userId = currentUserId();

    if ($userId <= 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Utilisateur non connecté.'
        ]);
        exit;
    }

    $notificationId = (int)($_POST['notification_id'] ?? 0);

    if ($notificationId <= 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Notification invalide.'
        ]);
        exit;
    }

    $pdo = Database::getConnection();
    $controller = new PopupNotificationController($pdo);
    $service = $controller->service();

    $ok = $service->markRead(
        $notificationId,
        $userId,
        clientIp(),
        $_SERVER['HTTP_USER_AGENT'] ?? null
    );

    echo json_encode([
        'success' => $ok
    ]);
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Erreur lors de l’enregistrement de la lecture.'
    ]);
}
