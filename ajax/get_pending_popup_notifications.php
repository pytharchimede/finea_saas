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

try {
    $userId = currentUserId();

    if ($userId <= 0) {
        echo json_encode([
            'success' => false,
            'notification' => null,
            'message' => 'Utilisateur non connecté.'
        ]);
        exit;
    }

    $pdo = Database::getConnection();
    $controller = new PopupNotificationController($pdo);
    $service = $controller->service();

    $notification = $service->getPendingForUser($userId);

    echo json_encode([
        'success' => true,
        'notification' => $notification
    ]);
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'notification' => null,
        'message' => 'Erreur lors du chargement de la notification.'
    ]);
}
