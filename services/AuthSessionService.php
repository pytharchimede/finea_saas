<?php

require_once __DIR__ . '/../model/AccessManager.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../model/MailPasswordStore.php';
require_once __DIR__ . '/PasskeyPolicyService.php';
require_once __DIR__ . '/AuthDeviceSessionService.php';
require_once __DIR__ . '/FineaInstanceIdentity.php';

final class AuthSessionService
{
    public static function login(array $user, PDO $pdo, string $method = 'password'): array
    {
        session_regenerate_id(true);

        foreach ($user as $key => $value) {
            if (
                !is_int($key)
                && !preg_match(
                    '/password|passwd|secret|token|credential|private_key|api_key|otp|totp|recovery|hash/i',
                    (string) $key
                )
            ) {
                $_SESSION[$key] = $value;
            }
        }

        FineaInstanceIdentity::bindSession();

        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['user_name'] = trim((string) ($user['prenom'] ?? '') . ' ' . (string) ($user['nom'] ?? ''));
        $_SESSION['auth_method'] = $method;
        $_SESSION['auth_policy_version'] = (new PasskeyPolicyService($pdo))->sessionVersion((int)$user['id']);
        $_SESSION['device_session_token'] = (new AuthDeviceSessionService($pdo))->register((int)$user['id'],$method);
        \MailPasswordStore::hydrateSessionFromDb((int) $user['id']);
        $_SESSION['permissions'] = \AccessManager::loadUserPermissions((int) $user['id']);

        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS login_history (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                ip VARCHAR(45) NULL,
                user_agent TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $stmt = $pdo->prepare('INSERT INTO login_history (user_id, ip, user_agent) VALUES (:user_id, :ip, :user_agent)');
            $stmt->execute([
                'user_id' => (int) $user['id'],
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            ]);
        } catch (Throwable) {
            // L'historique ne doit jamais empêcher une connexion valide.
        }

        $redirect = 'selection_portail.php';
        if (!empty($_SESSION['post_login_redirect']) && is_string($_SESSION['post_login_redirect'])) {
            $redirect = (string) $_SESSION['post_login_redirect'];
            unset($_SESSION['post_login_redirect']);
        }

        \AuditTrail::log($method === 'passkey' ? 'PASSKEY_LOGIN_SUCCESS' : 'login_success', [
            'module' => 'security',
            'action' => $method === 'passkey' ? 'passkey_login_success' : 'login_success',
            'user_id' => (int) $user['id'],
            'user_name' => $_SESSION['user_name'],
            'user_email' => (string) ($user['mail_pro'] ?? ''),
            'entity_type' => 'session',
            'entity_label' => 'erp_login',
            'result' => 'success',
            'details' => ['role_id' => $user['role_id'] ?? null, 'method' => $method],
        ]);

        return ['redirect' => $redirect];
    }
}
