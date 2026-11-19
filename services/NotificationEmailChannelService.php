<?php

require_once __DIR__ . '/../model/Mailer.php';

class NotificationEmailChannelService
{
    public function send(string $to, string $name, string $subject, string $html, ?string $plain = null, array $options = []): bool
    {
        $to = trim($to);
        if ($to === '') {
            return false;
        }
        if (!$this->isNotificationEmailAllowed($to, $options)) {
            return false;
        }

        try {
            return (bool) \Mailer::send($to, $name, $subject, $html, $plain, $options);
        } catch (Throwable $e) {
            return false;
        }
    }

    private function isNotificationEmailAllowed(string $to, array $options): bool
    {
        $keys = $options['notification_preference_keys'] ?? [];
        if (!is_array($keys)) {
            return true;
        }

        $keys = array_values(array_unique(array_filter(array_map(
            static fn($key): string => strtolower(trim((string) $key)),
            $keys
        ), static fn(string $key): bool => $key !== '')));

        if ($keys === []) {
            return true;
        }

        try {
            $pdo = \Database::getConnection();
            $userId = (int) ($options['notification_user_id'] ?? 0);
            if ($userId <= 0) {
                $stmt = $pdo->prepare('
                    SELECT u.id
                    FROM user_devis_banamur u
                    LEFT JOIN personnel p ON p.id_personnel_soignant = u.personnel_id
                    WHERE LOWER(TRIM(u.mail_pro)) = LOWER(TRIM(:email))
                       OR LOWER(TRIM(p.email_personnel_soignant)) = LOWER(TRIM(:email))
                    ORDER BY u.active DESC, u.id ASC
                    LIMIT 1
                ');
                $stmt->execute(['email' => $to]);
                $userId = (int) ($stmt->fetchColumn() ?: 0);
            }

            if ($userId <= 0) {
                return true;
            }

            $placeholders = [];
            $params = ['user_id' => $userId];
            foreach ($keys as $index => $key) {
                $param = 'key' . $index;
                $placeholders[] = ':' . $param;
                $params[$param] = $key;
            }

            $stmt = $pdo->prepare('
                SELECT event_key, channel_email
                FROM user_notification_preferences
                WHERE user_id = :user_id
                  AND event_key IN (' . implode(',', $placeholders) . ')
            ');
            $stmt->execute($params);
            $values = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $values[strtolower((string) ($row['event_key'] ?? ''))] = (int) ($row['channel_email'] ?? 0) === 1;
            }

            foreach ($keys as $key) {
                if (array_key_exists($key, $values) && $values[$key] === false) {
                    return false;
                }
            }

            foreach ($keys as $key) {
                if (array_key_exists($key, $values)) {
                    return true;
                }
            }

            if (in_array('legal_request_submitted.feb', $keys, true)) {
                $stmt = $pdo->prepare('SELECT acces_direction FROM user_devis_banamur WHERE id = :id LIMIT 1');
                $stmt->execute(['id' => $userId]);
                if ((int) ($stmt->fetchColumn() ?: 0) === 1) {
                    return false;
                }
            }
        } catch (Throwable $e) {
            return true;
        }

        return true;
    }

    public function buildFineaEmail(string $title, string $bodyHtml, string $actionUrl = '', array $options = []): string
    {
        return \Mailer::buildFineaNotificationEmail($title, $bodyHtml, $actionUrl, $options);
    }

    public function lastEmailLogId(): ?int
    {
        return \Mailer::getLastEmailLogId();
    }

    public function sendWelcomeEmail(array $user, string $plainPassword): bool
    {
        try {
            return (bool) \Mailer::sendWelcomeEmail($user, $plainPassword);
        } catch (Throwable $e) {
            return false;
        }
    }

    public function sendPasswordResetEmail(array $user, string $resetUrl, int $ttlMinutes = 60): bool
    {
        try {
            return (bool) \Mailer::sendPasswordResetEmail($user, $resetUrl, $ttlMinutes);
        } catch (Throwable $e) {
            return false;
        }
    }

    public function sendLoginUnlockEmail(array $user, string $unlockUrl, int $ttlMinutes = 60): bool
    {
        try {
            return (bool) \Mailer::sendLoginUnlockEmail($user, $unlockUrl, $ttlMinutes);
        } catch (Throwable $e) {
            return false;
        }
    }
}
