<?php

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/User.php';
require_once __DIR__ . '/../services/NotificationEmailChannelService.php';
require_once __DIR__ . '/../model/AuditTrail.php';

$response = [
    'status' => 'error',
    'code' => null,
    'message' => '',
];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $response['code'] = 'method_not_allowed';
    $response['message'] = 'Méthode non autorisée.';
    \AuditTrail::log('password_reset_request_failed', [
        'module' => 'request',
        'action' => 'password_reset_request',
        'entity_type' => 'user_account',
        'entity_label' => 'Demande reinitialisation mot de passe',
        'result' => 'failed',
        'severity' => 'warning',
        'details' => [
            'reason' => 'invalid_method',
            'request_method' => (string)($_SERVER['REQUEST_METHOD'] ?? ''),
        ],
    ]);
    echo json_encode($response);
    exit;
}

$mail = strtolower(trim((string)($_POST['mail_pro'] ?? '')));
if ($mail === '') {
    $response['code'] = 'missing_email';
    $response['message'] = 'Veuillez saisir votre e-mail.';
    \AuditTrail::log('password_reset_request_failed', [
        'module' => 'request',
        'action' => 'password_reset_request',
        'entity_type' => 'user_account',
        'entity_label' => 'Demande reinitialisation mot de passe',
        'result' => 'failed',
        'severity' => 'warning',
        'details' => [
            'reason' => 'missing_email',
        ],
    ]);
    echo json_encode($response);
    exit;
}

if (!filter_var($mail, FILTER_VALIDATE_EMAIL)) {
    $response['code'] = 'invalid_email';
    $response['message'] = 'Adresse e-mail invalide.';
    \AuditTrail::log('password_reset_request_failed', [
        'module' => 'request',
        'action' => 'password_reset_request',
        'entity_type' => 'user_account',
        'entity_label' => 'Demande reinitialisation mot de passe',
        'result' => 'failed',
        'severity' => 'warning',
        'details' => [
            'reason' => 'invalid_email',
            'email' => $mail,
        ],
    ]);
    echo json_encode($response);
    exit;
}

try {
    $pdo = \Database::getConnection();

    // Table de tokens (créée à la volée comme login_history)
    $pdo->exec("CREATE TABLE IF NOT EXISTS password_reset_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        token_hash CHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL,
        used_at DATETIME NULL,
        request_ip VARCHAR(45) NULL,
        request_user_agent TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_token_hash (token_hash),
        INDEX idx_user_id (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $userModel = new \User($pdo);
    $user = $userModel->findUserByEmail($mail);

    if (!$user) {
        $response['code'] = 'email_not_found';
        $response['message'] = "Aucun compte n'est associé à cet e-mail. Merci de vous rapprocher de l'administrateur.";
        \AuditTrail::log('password_reset_request_failed', [
            'module' => 'request',
            'action' => 'password_reset_request',
            'entity_type' => 'user_account',
            'entity_label' => 'Demande reinitialisation mot de passe',
            'result' => 'failed',
            'severity' => 'warning',
            'details' => [
                'reason' => 'email_not_found',
                'email' => $mail,
            ],
        ], $pdo);
        echo json_encode($response);
        exit;
    }

    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);

    $expiresAt = (new DateTimeImmutable('now'))->modify('+60 minutes');

    $stmt = $pdo->prepare("INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, used_at, request_ip, request_user_agent)
        VALUES (:user_id, :token_hash, :expires_at, NULL, :ip, :ua)");
    $stmt->execute([
        'user_id' => (int)$user['id'],
        'token_hash' => $tokenHash,
        'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
        'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        'ua' => $_SERVER['HTTP_USER_AGENT'] ?? null,
    ]);

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $resetUrl = sprintf('%s://%s/reset_password_set.php?token=%s', $scheme, $host, urlencode($token));

    $emailChannel = new \NotificationEmailChannelService();
    $sent = $emailChannel->sendPasswordResetEmail($user, $resetUrl, 60);

    if (!$sent) {
        $response['code'] = 'email_send_failed';
        $response['message'] = "Impossible d'envoyer l'e-mail pour le moment. Merci de réessayer ou de contacter l'administrateur.";
        \AuditTrail::log('password_reset_request_failed', [
            'module' => 'request',
            'action' => 'password_reset_request',
            'entity_type' => 'user_account',
            'entity_id' => (string)$user['id'],
            'entity_label' => (string)($user['mail_pro'] ?? $mail),
            'result' => 'failed',
            'severity' => 'warning',
            'details' => [
                'reason' => 'email_send_failed',
                'email' => $mail,
            ],
        ], $pdo);
        echo json_encode($response);
        exit;
    }

    $response['status'] = 'success';
    $response['code'] = 'reset_email_sent';
    $response['message'] = "Un e-mail de réinitialisation vient de vous être envoyé.";

    \AuditTrail::log('password_reset_requested', [
        'module' => 'request',
        'action' => 'password_reset_request',
        'entity_type' => 'user_account',
        'entity_id' => (string)$user['id'],
        'entity_label' => (string)($user['mail_pro'] ?? $mail),
        'result' => 'success',
        'details' => [
            'email' => $mail,
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
        ],
    ], $pdo);

    echo json_encode($response);
} catch (Throwable $e) {
    $response['code'] = 'server_error';
    $response['message'] = 'Erreur serveur.';
    if (isset($pdo) && $pdo instanceof PDO) {
        \AuditTrail::log('password_reset_request_failed', [
            'module' => 'request',
            'action' => 'password_reset_request',
            'entity_type' => 'user_account',
            'entity_label' => 'Demande reinitialisation mot de passe',
            'result' => 'failed',
            'severity' => 'warning',
            'details' => [
                'email' => $mail,
                'error_message' => $e->getMessage(),
            ],
        ], $pdo);
    }
    echo json_encode($response);
}
