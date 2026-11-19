<?php

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/User.php';
require_once __DIR__ . '/../model/AuditTrail.php';

$response = [
    'status' => 'error',
    'code' => null,
    'message' => '',
];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $response['code'] = 'method_not_allowed';
    $response['message'] = 'Méthode non autorisée.';
    \AuditTrail::log('password_reset_update_failed', [
        'module' => 'request',
        'action' => 'password_reset_update',
        'entity_type' => 'user_account',
        'entity_label' => 'Validation reinitialisation mot de passe',
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

$token = trim((string)($_POST['token'] ?? ''));
$password = (string)($_POST['password'] ?? '');
$passwordConfirm = (string)($_POST['password_confirm'] ?? '');

if ($token === '') {
    $response['code'] = 'missing_token';
    $response['message'] = 'Lien de réinitialisation invalide.';
    \AuditTrail::log('password_reset_update_failed', [
        'module' => 'request',
        'action' => 'password_reset_update',
        'entity_type' => 'user_account',
        'entity_label' => 'Validation reinitialisation mot de passe',
        'result' => 'failed',
        'severity' => 'warning',
        'details' => [
            'reason' => 'missing_token',
        ],
    ]);
    echo json_encode($response);
    exit;
}

if ($password === '' || $passwordConfirm === '') {
    $response['code'] = 'missing_password';
    $response['message'] = 'Veuillez saisir et confirmer le mot de passe.';
    \AuditTrail::log('password_reset_update_failed', [
        'module' => 'request',
        'action' => 'password_reset_update',
        'entity_type' => 'user_account',
        'entity_label' => 'Validation reinitialisation mot de passe',
        'result' => 'failed',
        'severity' => 'warning',
        'details' => [
            'reason' => 'missing_password',
        ],
    ]);
    echo json_encode($response);
    exit;
}

if ($password !== $passwordConfirm) {
    $response['code'] = 'password_mismatch';
    $response['message'] = 'Les deux mots de passe ne correspondent pas.';
    \AuditTrail::log('password_reset_update_failed', [
        'module' => 'request',
        'action' => 'password_reset_update',
        'entity_type' => 'user_account',
        'entity_label' => 'Validation reinitialisation mot de passe',
        'result' => 'failed',
        'severity' => 'warning',
        'details' => [
            'reason' => 'password_mismatch',
        ],
    ]);
    echo json_encode($response);
    exit;
}

if (mb_strlen($password) < 8) {
    $response['code'] = 'password_too_short';
    $response['message'] = 'Le mot de passe doit contenir au moins 8 caractères.';
    \AuditTrail::log('password_reset_update_failed', [
        'module' => 'request',
        'action' => 'password_reset_update',
        'entity_type' => 'user_account',
        'entity_label' => 'Validation reinitialisation mot de passe',
        'result' => 'failed',
        'severity' => 'warning',
        'details' => [
            'reason' => 'password_too_short',
        ],
    ]);
    echo json_encode($response);
    exit;
}

try {
    $pdo = \Database::getConnection();

    // Table de tokens (sécurité: s'assurer qu'elle existe)
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

    $tokenHash = hash('sha256', $token);

    $stmt = $pdo->prepare("SELECT * FROM password_reset_tokens WHERE token_hash = :token_hash LIMIT 1");
    $stmt->execute(['token_hash' => $tokenHash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $response['code'] = 'invalid_token';
        $response['message'] = 'Ce lien est invalide.';
        \AuditTrail::log('password_reset_update_failed', [
            'module' => 'request',
            'action' => 'password_reset_update',
            'entity_type' => 'user_account',
            'entity_label' => 'Validation reinitialisation mot de passe',
            'result' => 'failed',
            'severity' => 'warning',
            'details' => [
                'reason' => 'invalid_token',
            ],
        ], $pdo);
        echo json_encode($response);
        exit;
    }

    if (!empty($row['used_at'])) {
        $response['code'] = 'token_used';
        $response['message'] = 'Ce lien a déjà été utilisé.';
        \AuditTrail::log('password_reset_update_failed', [
            'module' => 'request',
            'action' => 'password_reset_update',
            'entity_type' => 'user_account',
            'entity_id' => (string)$row['user_id'],
            'entity_label' => 'Validation reinitialisation mot de passe',
            'result' => 'failed',
            'severity' => 'warning',
            'details' => [
                'reason' => 'token_used',
                'token_row_id' => (int)$row['id'],
            ],
        ], $pdo);
        echo json_encode($response);
        exit;
    }

    $now = new DateTimeImmutable('now');
    $expiresAt = new DateTimeImmutable((string)$row['expires_at']);
    if ($expiresAt < $now) {
        $response['code'] = 'token_expired';
        $response['message'] = 'Ce lien a expiré. Merci de refaire une demande.';
        \AuditTrail::log('password_reset_update_failed', [
            'module' => 'request',
            'action' => 'password_reset_update',
            'entity_type' => 'user_account',
            'entity_id' => (string)$row['user_id'],
            'entity_label' => 'Validation reinitialisation mot de passe',
            'result' => 'failed',
            'severity' => 'warning',
            'details' => [
                'reason' => 'token_expired',
                'expires_at' => (string)$row['expires_at'],
            ],
        ], $pdo);
        echo json_encode($response);
        exit;
    }

    $userId = (int)$row['user_id'];

    $newHash = \User::hashPassword($password);

    $pdo->beginTransaction();

    $stmtUpd = $pdo->prepare("UPDATE user_devis_banamur SET password = :password WHERE id = :id");
    $stmtUpd->execute([
        'password' => $newHash,
        'id' => $userId,
    ]);

    $stmtUse = $pdo->prepare("UPDATE password_reset_tokens SET used_at = :used_at WHERE id = :id");
    $stmtUse->execute([
        'used_at' => $now->format('Y-m-d H:i:s'),
        'id' => (int)$row['id'],
    ]);

    $pdo->commit();

    \AuditTrail::log('password_reset_completed', [
        'module' => 'request',
        'action' => 'password_reset_update',
        'entity_type' => 'user_account',
        'entity_id' => (string)$userId,
        'entity_label' => 'Reinitialisation mot de passe',
        'result' => 'success',
        'details' => [
            'token_row_id' => (int)$row['id'],
            'used_at' => $now->format('Y-m-d H:i:s'),
        ],
    ], $pdo);

    $response['status'] = 'success';
    $response['code'] = 'password_updated';
    $response['message'] = 'Votre mot de passe a été mis à jour. Vous pouvez vous connecter.';

    echo json_encode($response);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (isset($pdo) && $pdo instanceof PDO) {
        \AuditTrail::log('password_reset_update_failed', [
            'module' => 'request',
            'action' => 'password_reset_update',
            'entity_type' => 'user_account',
            'entity_id' => isset($userId) ? (string)$userId : null,
            'entity_label' => 'Validation reinitialisation mot de passe',
            'result' => 'failed',
            'severity' => 'warning',
            'details' => [
                'error_message' => $e->getMessage(),
            ],
        ], $pdo);
    }
    $response['code'] = 'server_error';
    $response['message'] = 'Erreur serveur.';
    echo json_encode($response);
}
