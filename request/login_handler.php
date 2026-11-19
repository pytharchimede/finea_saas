<?php
// request/login_handler.php

session_start();

require_once '../model/Database.php';
require_once '../model/User.php';
require_once '../model/AccessManager.php';
require_once '../model/AuditTrail.php';
require_once '../services/NotificationEmailChannelService.php';
require_once '../model/MailPasswordStore.php';
require_once '../services/AuthSessionService.php';
require_once '../services/PasskeyPolicyService.php';

header('Content-Type: application/json; charset=utf-8');

$response = [
    'status' => 'error',
    'code' => null,
    'message' => '',
    'max_attempts' => 3,
    'attempts_used' => null,
    'attempts_remaining' => null,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $mail_pro = trim($_POST['mail_pro'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($mail_pro === '' || $password === '') {
        \AuditTrail::log('login_failure', [
            'module' => 'security',
            'action' => 'login_failure',
            'user_email' => $mail_pro,
            'result' => 'error',
            'severity' => 'warning',
            'details' => ['reason' => 'missing_credentials'],
        ]);
        $response['message'] = 'Veuillez saisir vos identifiants.';
        echo json_encode($response);
        exit();
    }

    try {

        $pdo = \Database::getConnection();

        // Table de sécurité login (tentatives + verrouillage)
        $pdo->exec("CREATE TABLE IF NOT EXISTS login_security (
            user_id INT PRIMARY KEY,
            failed_attempts INT NOT NULL DEFAULT 0,
            locked TINYINT(1) NOT NULL DEFAULT 0,
            unlock_token_hash CHAR(64) NULL,
            unlock_expires_at DATETIME NULL,
            unlock_email_sent_at DATETIME NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_locked (locked)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $userModel = new \User($pdo);

        // Distinguer : email inconnu (ou compte désactivé) VS mot de passe incorrect
        $existingUser = $userModel->findUserByEmail($mail_pro);

        if (!$existingUser) {

            \AuditTrail::log('login_failure', [
                'module' => 'security',
                'action' => 'login_failure',
                'user_email' => $mail_pro,
                'result' => 'error',
                'severity' => 'warning',
                'details' => ['reason' => 'email_not_found'],
            ]);

            $response['code'] = 'email_not_found';
            $response['message'] = "Aucun compte n'est associé à cet e-mail. Merci de vous rapprocher de l'administrateur pour la création / l'activation du compte.";
        } else {

            $maxAttempts = 3;
            $userId = (int)$existingUser['id'];

            // Charger / initialiser l'état de sécurité
            $stmtSec = $pdo->prepare('SELECT * FROM login_security WHERE user_id = :user_id');
            $stmtSec->execute(['user_id' => $userId]);
            $sec = $stmtSec->fetch(PDO::FETCH_ASSOC);
            if (!$sec) {
                $pdo->prepare('INSERT INTO login_security (user_id, failed_attempts, locked) VALUES (:user_id, 0, 0)')
                    ->execute(['user_id' => $userId]);
                $sec = [
                    'user_id' => $userId,
                    'failed_attempts' => 0,
                    'locked' => 0,
                    'unlock_token_hash' => null,
                    'unlock_expires_at' => null,
                    'unlock_email_sent_at' => null,
                ];
            }

            $failedAttempts = (int)($sec['failed_attempts'] ?? 0);
            $isLocked = !empty($sec['locked']);

            $response['max_attempts'] = $maxAttempts;
            $response['attempts_used'] = min($failedAttempts, $maxAttempts);
            $response['attempts_remaining'] = max(0, $maxAttempts - $failedAttempts);

            // Si compte verrouillé : imposer déblocage par email, même si le mot de passe est correct
            if ($isLocked || $failedAttempts >= $maxAttempts) {

                $now = new DateTimeImmutable('now');
                $canSend = true;
                if (!empty($sec['unlock_email_sent_at'])) {
                    try {
                        $lastSent = new DateTimeImmutable((string)$sec['unlock_email_sent_at']);
                        // Anti-spam : pas plus d'un email toutes les 2 minutes
                        $canSend = $lastSent->modify('+2 minutes') <= $now;
                    } catch (Throwable $e) {
                        $canSend = true;
                    }
                }

                if ($canSend) {
                    $rawToken = bin2hex(random_bytes(32));
                    $tokenHash = hash('sha256', $rawToken);
                    $expiresAt = $now->modify('+60 minutes');

                    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443') ? 'https' : 'http';
                    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                    $unlockUrl = sprintf('%s://%s/login_unlock.php?token=%s', $scheme, $host, urlencode($rawToken));

                    $pdo->prepare('UPDATE login_security SET locked = 1, unlock_token_hash = :h, unlock_expires_at = :exp, unlock_email_sent_at = :sent WHERE user_id = :user_id')
                        ->execute([
                            'h' => $tokenHash,
                            'exp' => $expiresAt->format('Y-m-d H:i:s'),
                            'sent' => $now->format('Y-m-d H:i:s'),
                            'user_id' => $userId,
                        ]);

                    // Envoi email de déblocage
                    try {
                        (new \NotificationEmailChannelService())->sendLoginUnlockEmail($existingUser, $unlockUrl, 60);
                    } catch (Throwable $e) {
                        // Non bloquant: on garde l'état verrouillé
                    }
                }

                $response['code'] = 'account_locked';
                $response['attempts_used'] = $maxAttempts;
                $response['attempts_remaining'] = 0;
                $response['message'] = "Compte bloqué suite à trop de tentatives de connexion (3). Pour votre sécurité, vous devez confirmer par e-mail pour débloquer votre compte.";
                \AuditTrail::log('login_locked', [
                    'module' => 'security',
                    'action' => 'login_locked',
                    'user_id' => $userId,
                    'user_email' => (string)($existingUser['mail_pro'] ?? $mail_pro),
                    'result' => 'denied',
                    'severity' => 'warning',
                    'details' => [
                        'failed_attempts' => $failedAttempts,
                        'max_attempts' => $maxAttempts,
                    ],
                ]);
                echo json_encode($response);
                exit();
            }

            $user = $userModel->verifyPassword($mail_pro, $password);

            if ($user) {

                $passkeyPolicy = new \PasskeyPolicyService($pdo);
                $policy = $passkeyPolicy->get((int)$user['id']);
                if (!empty($policy['passkey_only']) && $passkeyPolicy->hasPasskey((int)$user['id'])) {
                    $response['code'] = 'passkey_required';
                    $response['message'] = 'Ce compte autorise uniquement la connexion par Passkey.';
                    echo json_encode($response);
                    exit();
                }

                // Reset compteur d'essais après succès
                $pdo->prepare('UPDATE login_security SET failed_attempts = 0, locked = 0, unlock_token_hash = NULL, unlock_expires_at = NULL WHERE user_id = :user_id')
                    ->execute(['user_id' => $userId]);

                $response['attempts_used'] = 0;
                $response['attempts_remaining'] = $maxAttempts;

                $sessionResult = \AuthSessionService::login($user, $pdo, 'password');
                if (!empty($policy['passkey_only']) && !$passkeyPolicy->hasPasskey((int)$user['id'])) {
                    $_SESSION['passkey_setup_required'] = true;
                    $sessionResult['redirect'] = 'decaissement/profil.php#passkey-security';
                }

                $response['status'] = 'success';
                $response['code'] = 'ok';
                $response['message'] = 'Connexion réussie';
                $response['redirect'] = $sessionResult['redirect'];
            } else {

                // Incrémenter tentatives
                $newFailed = $failedAttempts + 1;
                $pdo->prepare('UPDATE login_security SET failed_attempts = :n WHERE user_id = :user_id')
                    ->execute(['n' => $newFailed, 'user_id' => $userId]);

                $remaining = max(0, $maxAttempts - $newFailed);
                $response['attempts_used'] = min($newFailed, $maxAttempts);
                $response['attempts_remaining'] = $remaining;

                if ($newFailed >= $maxAttempts) {
                    // Verrouiller et envoyer email de déblocage
                    $now = new DateTimeImmutable('now');
                    $rawToken = bin2hex(random_bytes(32));
                    $tokenHash = hash('sha256', $rawToken);
                    $expiresAt = $now->modify('+60 minutes');

                    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443') ? 'https' : 'http';
                    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                    $unlockUrl = sprintf('%s://%s/login_unlock.php?token=%s', $scheme, $host, urlencode($rawToken));

                    $pdo->prepare('UPDATE login_security SET locked = 1, unlock_token_hash = :h, unlock_expires_at = :exp, unlock_email_sent_at = :sent WHERE user_id = :user_id')
                        ->execute([
                            'h' => $tokenHash,
                            'exp' => $expiresAt->format('Y-m-d H:i:s'),
                            'sent' => $now->format('Y-m-d H:i:s'),
                            'user_id' => $userId,
                        ]);

                    try {
                        (new \NotificationEmailChannelService())->sendLoginUnlockEmail($existingUser, $unlockUrl, 60);
                    } catch (Throwable $e) {
                        // Non bloquant
                    }

                    $response['code'] = 'account_locked';
                    $response['attempts_used'] = $maxAttempts;
                    $response['attempts_remaining'] = 0;
                    $response['message'] = "Compte bloqué suite à trop de tentatives de connexion (3). Pour votre sécurité, vous devez confirmer par e-mail pour débloquer votre compte.";
                    \AuditTrail::log('login_locked', [
                        'module' => 'security',
                        'action' => 'login_locked',
                        'user_id' => $userId,
                        'user_email' => (string)($existingUser['mail_pro'] ?? $mail_pro),
                        'result' => 'denied',
                        'severity' => 'warning',
                        'details' => [
                            'failed_attempts' => $newFailed,
                            'max_attempts' => $maxAttempts,
                        ],
                    ]);
                } else {
                    $response['code'] = 'invalid_password';
                    $response['message'] = 'Le mot de passe est incorrect.';
                    \AuditTrail::log('login_failure', [
                        'module' => 'security',
                        'action' => 'login_failure',
                        'user_id' => $userId,
                        'user_email' => (string)($existingUser['mail_pro'] ?? $mail_pro),
                        'result' => 'error',
                        'severity' => 'warning',
                        'details' => [
                            'reason' => 'invalid_password',
                            'failed_attempts' => $newFailed,
                            'attempts_remaining' => $remaining,
                        ],
                    ]);
                }
            }
        }
    } catch (Throwable $e) {

        \AuditTrail::log('login_failure', [
            'module' => 'security',
            'action' => 'login_failure',
            'user_email' => $mail_pro ?? null,
            'result' => 'error',
            'severity' => 'error',
            'details' => ['reason' => 'server_error'],
        ]);

        $response['code'] = 'server_error';
        $response['message'] = 'Erreur serveur';
    }
}

echo json_encode($response);
