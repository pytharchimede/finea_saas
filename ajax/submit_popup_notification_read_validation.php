<?php
ob_start();
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../controller/PopupNotificationController.php';

header('Content-Type: application/json; charset=utf-8');

function currentPopupUserId(): int
{
    return (int)($_SESSION['user_id'] ?? $_SESSION['id_user'] ?? $_SESSION['admin_id'] ?? 0);
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Méthode non autorisée.');
    }

    $notificationId = (int)($_POST['notification_id'] ?? 0);
    $userId = currentPopupUserId();

    if ($notificationId <= 0 || $userId <= 0) {
        throw new RuntimeException('Session ou notification invalide.');
    }

    $answers = [];
    if (isset($_POST['answers']) && is_array($_POST['answers'])) {
        foreach ($_POST['answers'] as $questionId => $choiceId) {
            $answers[(int)$questionId] = (int)$choiceId;
        }
    }
    session_write_close();

    $pdo = Database::getConnection();
    $controller = new PopupNotificationController($pdo);
    $service = $controller->service();

    $result = $service->submitReadValidation(
        $notificationId,
        $userId,
        $answers,
        $_SERVER['REMOTE_ADDR'] ?? null,
        $_SERVER['HTTP_USER_AGENT'] ?? null
    );

    if (ob_get_length()) ob_clean();
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    if (ob_get_length()) ob_clean();
    echo json_encode([
        'success' => false,
        'passed' => false,
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
