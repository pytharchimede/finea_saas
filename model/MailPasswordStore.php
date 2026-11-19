<?php

require_once __DIR__ . '/EnvConfig.php';
require_once __DIR__ . '/../repositories/MailPasswordRepository.php';
\EnvConfig::loadFromDirectory(__DIR__);

class MailPasswordStore
{
    private const CIPHER = 'aes-256-gcm';

    private static $configLoaded = false;
    private static function loadConfig(): void
    {
        if (self::$configLoaded) {
            return;
        }
        self::$configLoaded = true;

        $cfg = __DIR__ . '/../decaissement/config/mail_password_config.php';
        if (is_file($cfg)) {
            require_once $cfg;
        }

        // Fallback direct si le config n'est pas présent
        if (!defined('MAIL_PASSWORD_KEY')) {
            $v = \EnvConfig::get('MAIL_PASSWORD_KEY');
            if ($v !== false && $v !== '') {
                define('MAIL_PASSWORD_KEY', $v);
            }
        }
    }

    private static function getKey(): ?string
    {
        self::loadConfig();

        $raw = '';
        if (defined('MAIL_PASSWORD_KEY')) {
            $raw = (string)MAIL_PASSWORD_KEY;
        }

        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        // Dérive une clé 32 bytes.
        return hash('sha256', $raw, true);
    }

    public static function isEnabled(): bool
    {
        return self::getKey() !== null && function_exists('openssl_encrypt') && function_exists('openssl_decrypt');
    }

    private static function repository(): MailPasswordRepository
    {
        return new MailPasswordRepository();
    }

    private static function encrypt(string $plaintext): ?string
    {
        $key = self::getKey();
        if ($key === null) {
            return null;
        }
        if (!function_exists('openssl_encrypt')) {
            return null;
        }

        $iv = random_bytes(12); // recommandé pour GCM
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false || $tag === '') {
            return null;
        }

        return base64_encode($iv) . ':' . base64_encode($tag) . ':' . base64_encode($ciphertext);
    }

    private static function decrypt(string $packed): ?string
    {
        $key = self::getKey();
        if ($key === null) {
            return null;
        }
        if (!function_exists('openssl_decrypt')) {
            return null;
        }

        $parts = explode(':', $packed);
        if (count($parts) !== 3) {
            return null;
        }

        $iv = base64_decode($parts[0], true);
        $tag = base64_decode($parts[1], true);
        $ciphertext = base64_decode($parts[2], true);

        if ($iv === false || $tag === false || $ciphertext === false) {
            return null;
        }

        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plaintext === false) {
            return null;
        }

        return $plaintext;
    }

    public static function loadPasswordForUser(int $userId): ?string
    {
        if ($userId <= 0 || !self::isEnabled()) {
            return null;
        }

        try {
            $enc = self::repository()->loadEncryptedPasswordForUser($userId);
            if ($enc === null) {
                return null;
            }

            return self::decrypt($enc);
        } catch (Throwable $e) {
            // Colonne pas encore migrée ou erreur BD: on ignore et on retombe sur la saisie.
            return null;
        }
    }

    public static function savePasswordForUser(int $userId, string $password): bool
    {
        if ($userId <= 0 || trim($password) === '' || !self::isEnabled()) {
            return false;
        }

        $enc = self::encrypt($password);
        if ($enc === null) {
            return false;
        }

        try {
            return self::repository()->saveEncryptedPasswordForUser($userId, $enc);
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function clearPasswordForUser(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        try {
            return self::repository()->clearPasswordForUser($userId);
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function hydrateSessionFromDb(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        $current = (string)($_SESSION['personnel_email_password'] ?? '');
        if (trim($current) !== '') {
            return;
        }

        $pw = self::loadPasswordForUser($userId);
        if ($pw === null || trim($pw) === '') {
            return;
        }

        $_SESSION['personnel_email_password'] = $pw;
        $_SESSION['mail_password_source'] = 'db';
        $_SESSION['mail_password_persisted'] = true;
    }

    public static function markPasswordAsManual(): void
    {
        $_SESSION['mail_password_source'] = 'manual';
        unset($_SESSION['mail_password_persisted']);
    }

    public static function persistSessionToDbIfNeeded(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        if (!empty($_SESSION['mail_password_persisted'])) {
            return;
        }

        $pw = (string)($_SESSION['personnel_email_password'] ?? '');
        if (trim($pw) === '') {
            return;
        }

        if (self::savePasswordForUser($userId, $pw)) {
            $_SESSION['mail_password_persisted'] = true;
        }
    }
}

