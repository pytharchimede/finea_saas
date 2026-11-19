<?php

class FournisseurWorkflowNotificationRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function createSystemNotification(string $eventKey, string $subKey, string $title, string $message, string $link, int $recipientUserId, array $payload, array $channels = ['system']): void
    {
        if ($recipientUserId <= 0) {
            return;
        }

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO system_notifications (
                    created_at, event_key, sub_key, title, message, link_url,
                    recipient_team, recipient_user_id, channels_json, payload_json
                ) VALUES (
                    NOW(), :event_key, :sub_key, :title, :message, :link_url,
                    NULL, :recipient_user_id, :channels_json, :payload_json
                )'
            );
            $stmt->execute([
                'event_key' => strtolower(trim($eventKey)),
                'sub_key' => strtolower(trim($subKey)),
                'title' => trim($title),
                'message' => trim($message),
                'link_url' => trim($link),
                'recipient_user_id' => $recipientUserId,
                'channels_json' => json_encode($channels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        } catch (Throwable $e) {
        }
    }

    public function listUsersByPermissionColumns(array $columns, string $scope): array
    {
        $columns = array_values(array_unique(array_filter(array_map(static function (string $column): string {
            return preg_replace('/[^a-z0-9_]/i', '', $column);
        }, $columns), static fn($column) => $column !== '')));

        if ($columns === []) {
            return [];
        }

        $conditions = array_map(static fn(string $column): string => $column . ' = 1', $columns);
        $sql = 'SELECT id, prenom, nom, mail_pro FROM user_devis_banamur WHERE active = 1 AND (' . implode(' OR ', $conditions) . ')';

        try {
            $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }

        $recipients = [];
        foreach ($rows as $row) {
            $userId = (int) ($row['id'] ?? 0);
            $email = trim((string) ($row['mail_pro'] ?? ''));
            if ($userId <= 0 || $email === '') {
                continue;
            }

            $name = trim((string) (($row['prenom'] ?? '') . ' ' . ($row['nom'] ?? '')));
            $recipients[] = [
                'user_id' => $userId,
                'email' => $email,
                'name' => $name !== '' ? $name : $email,
                'scope' => $scope,
            ];
        }

        return $recipients;
    }

    public function getUserById(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        try {
            $stmt = $this->pdo->prepare('SELECT id, prenom, nom, mail_pro FROM user_devis_banamur WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return null;
        }

        if (!$row) {
            return null;
        }

        $email = trim((string) ($row['mail_pro'] ?? ''));
        $name = trim((string) (($row['prenom'] ?? '') . ' ' . ($row['nom'] ?? '')));

        return [
            'user_id' => (int) ($row['id'] ?? 0),
            'email' => $email,
            'name' => $name !== '' ? $name : $email,
            'scope' => 'requester',
        ];
    }
}
