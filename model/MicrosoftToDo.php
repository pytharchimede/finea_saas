<?php

declare(strict_types=1);

final class MicrosoftToDo
{
    private PDO $pdo;
    private bool $ready = true;
    private ?string $initError = null;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureTables();
    }

    public function isReady(): bool
    {
        return $this->ready;
    }

    public function getInitError(): ?string
    {
        return $this->initError;
    }

    private function ensureTables(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS user_microsoft_todo_token (
            id INT NOT NULL AUTO_INCREMENT,
            user_id INT NOT NULL,
            access_token TEXT NOT NULL,
            refresh_token TEXT NULL,
            expires_at DATETIME NULL,
            scope TEXT NULL,
            token_type VARCHAR(50) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_user_id (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

        try {
            $this->pdo->exec($sql);
        } catch (Throwable $e) {
            $this->ready = false;
            $this->initError = 'Initialisation SQL impossible (droits insuffisants ?)';
        }
    }

    public function getTokenRow(int $userId): ?array
    {
        if (!$this->ready || $userId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT user_id, access_token, refresh_token, expires_at, scope, token_type FROM user_microsoft_todo_token WHERE user_id = :user_id LIMIT 1');
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function saveTokenRow(int $userId, array $token): void
    {
        if (!$this->ready || $userId <= 0) {
            return;
        }

        $accessToken = (string)($token['access_token'] ?? '');
        $refreshToken = array_key_exists('refresh_token', $token) ? (string)($token['refresh_token'] ?? '') : null;
        $expiresIn = isset($token['expires_in']) ? (int)$token['expires_in'] : null;
        $scope = array_key_exists('scope', $token) ? (string)($token['scope'] ?? '') : null;
        $tokenType = array_key_exists('token_type', $token) ? (string)($token['token_type'] ?? '') : null;

        $expiresAt = null;
        if ($expiresIn !== null && $expiresIn > 0) {
            $expiresAt = (new DateTimeImmutable('now'))->modify('+' . $expiresIn . ' seconds')->format('Y-m-d H:i:s');
        }

        $stmt = $this->pdo->prepare('INSERT INTO user_microsoft_todo_token (user_id, access_token, refresh_token, expires_at, scope, token_type)
            VALUES (:user_id, :access_token, :refresh_token, :expires_at, :scope, :token_type)
            ON DUPLICATE KEY UPDATE access_token = VALUES(access_token), refresh_token = VALUES(refresh_token), expires_at = VALUES(expires_at), scope = VALUES(scope), token_type = VALUES(token_type)');

        $stmt->execute([
            'user_id' => $userId,
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_at' => $expiresAt,
            'scope' => $scope,
            'token_type' => $tokenType,
        ]);
    }

    public function clearTokenRow(int $userId): void
    {
        if (!$this->ready || $userId <= 0) {
            return;
        }
        $stmt = $this->pdo->prepare('DELETE FROM user_microsoft_todo_token WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);
    }

    public function isExpired(?string $expiresAt, int $skewSeconds = 60): bool
    {
        if (!$expiresAt) {
            return false;
        }
        try {
            $exp = new DateTimeImmutable($expiresAt);
            return $exp->getTimestamp() <= (time() + $skewSeconds);
        } catch (Throwable $e) {
            return true;
        }
    }

    public function exchangeCodeForToken(string $tenantId, string $clientId, string $clientSecret, string $redirectUri, string $code): array
    {
        $tenantId = trim($tenantId);
        $clientId = trim($clientId);
        $redirectUri = trim($redirectUri);
        $code = trim($code);

        if ($tenantId === '' || $clientId === '' || $clientSecret === '' || $redirectUri === '' || $code === '') {
            return ['ok' => false, 'error' => 'Configuration Microsoft incomplète.'];
        }

        $tokenUrl = 'https://login.microsoftonline.com/' . rawurlencode($tenantId) . '/oauth2/v2.0/token';

        $body = http_build_query([
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
            'code' => $code,
            'scope' => 'offline_access Tasks.Read Tasks.ReadWrite User.Read',
        ]);

        return $this->httpPostForm($tokenUrl, $body);
    }

    public function refreshAccessToken(string $tenantId, string $clientId, string $clientSecret, string $redirectUri, string $refreshToken): array
    {
        $tenantId = trim($tenantId);
        $clientId = trim($clientId);
        $redirectUri = trim($redirectUri);
        $refreshToken = trim($refreshToken);

        if ($tenantId === '' || $clientId === '' || $clientSecret === '' || $redirectUri === '' || $refreshToken === '') {
            return ['ok' => false, 'error' => 'Refresh impossible : configuration manquante.'];
        }

        $tokenUrl = 'https://login.microsoftonline.com/' . rawurlencode($tenantId) . '/oauth2/v2.0/token';

        $body = http_build_query([
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'scope' => 'offline_access Tasks.Read Tasks.ReadWrite User.Read',
        ]);

        return $this->httpPostForm($tokenUrl, $body);
    }

    public function listTasks(string $accessToken, int $max = 40): array
    {
        $max = max(1, min(200, $max));

        $listsRes = $this->graphGet('https://graph.microsoft.com/v1.0/me/todo/lists?$top=50', $accessToken);
        if (empty($listsRes['ok'])) {
            return $listsRes;
        }

        $lists = $listsRes['data']['value'] ?? [];
        if (!is_array($lists) || count($lists) === 0) {
            return ['ok' => true, 'items' => []];
        }

        $defaultListId = null;
        foreach ($lists as $l) {
            $name = (string)($l['displayName'] ?? '');
            if (mb_strtolower(trim($name)) === 'tasks' || mb_strtolower(trim($name)) === 'tâches') {
                $defaultListId = (string)($l['id'] ?? '');
                break;
            }
        }
        if (!$defaultListId) {
            $defaultListId = (string)($lists[0]['id'] ?? '');
        }
        if (!$defaultListId) {
            return ['ok' => true, 'items' => []];
        }

        $tasksRes = $this->graphGet('https://graph.microsoft.com/v1.0/me/todo/lists/' . rawurlencode($defaultListId) . '/tasks?$top=' . (int)$max, $accessToken);
        if (empty($tasksRes['ok'])) {
            return $tasksRes;
        }

        $items = $tasksRes['data']['value'] ?? [];
        if (!is_array($items)) {
            $items = [];
        }

        $mapped = [];
        foreach ($items as $t) {
            $due = null;
            if (isset($t['dueDateTime']) && is_array($t['dueDateTime'])) {
                $due = (string)($t['dueDateTime']['dateTime'] ?? '');
            }

            $etag = null;
            if (isset($t['@odata.etag'])) {
                $etag = (string)$t['@odata.etag'];
            }

            $mapped[] = [
                'id' => (string)($t['id'] ?? ''),
                'list_id' => $defaultListId,
                'etag' => $etag,
                'title' => (string)($t['title'] ?? ''),
                'status' => (string)($t['status'] ?? ''),
                'due' => $due,
            ];
        }

        return ['ok' => true, 'items' => $mapped];
    }

    public function updateTaskStatus(string $accessToken, string $listId, string $taskId, string $status, ?string $etag = null): array
    {
        $accessToken = trim($accessToken);
        $listId = trim($listId);
        $taskId = trim($taskId);
        $status = trim($status);

        if ($accessToken === '' || $listId === '' || $taskId === '' || $status === '') {
            return ['ok' => false, 'error' => 'Paramètres Microsoft invalides.'];
        }

        $allowed = ['notStarted', 'inProgress', 'completed', 'waitingOnOthers', 'deferred'];
        if (!in_array($status, $allowed, true)) {
            return ['ok' => false, 'error' => 'Statut Microsoft invalide.'];
        }

        $url = 'https://graph.microsoft.com/v1.0/me/todo/lists/' . rawurlencode($listId) . '/tasks/' . rawurlencode($taskId);
        return $this->graphPatchJson($url, $accessToken, ['status' => $status], $etag);
    }

    private function graphGet(string $url, string $accessToken): array
    {
        $accessToken = trim($accessToken);
        if ($accessToken === '') {
            return ['ok' => false, 'error' => 'Token Microsoft manquant.'];
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
        ]);

        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            return ['ok' => false, 'error' => 'Erreur réseau Graph: ' . ($err ?: 'inconnue')];
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $data = null;
        }

        if ($status < 200 || $status >= 300) {
            $msg = null;
            if (is_array($data) && isset($data['error'])) {
                $msg = is_array($data['error']) ? (($data['error']['message'] ?? null) ?: null) : null;
            }
            return ['ok' => false, 'error' => $msg ?: ('Graph HTTP ' . $status), 'status' => $status, 'data' => $data];
        }

        return ['ok' => true, 'data' => $data];
    }

    private function graphPatchJson(string $url, string $accessToken, array $payload, ?string $etag = null): array
    {
        $accessToken = trim($accessToken);
        if ($accessToken === '') {
            return ['ok' => false, 'error' => 'Token Microsoft manquant.'];
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return ['ok' => false, 'error' => 'Payload JSON invalide.'];
        }

        $headers = [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
            'Content-Type: application/json',
        ];
        if ($etag !== null && trim($etag) !== '') {
            $headers[] = 'If-Match: ' . $etag;
        } else {
            $headers[] = 'If-Match: *';
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            return ['ok' => false, 'error' => 'Erreur réseau Graph: ' . ($err ?: 'inconnue')];
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $data = null;
        }

        if ($status < 200 || $status >= 300) {
            $msg = null;
            if (is_array($data) && isset($data['error'])) {
                $msg = is_array($data['error']) ? (($data['error']['message'] ?? null) ?: null) : null;
            }
            return ['ok' => false, 'error' => $msg ?: ('Graph HTTP ' . $status), 'status' => $status, 'data' => $data];
        }

        return ['ok' => true, 'data' => $data];
    }

    private function httpPostForm(string $url, string $body): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ]);

        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            return ['ok' => false, 'error' => 'Erreur réseau OAuth: ' . ($err ?: 'inconnue')];
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $data = null;
        }

        if ($status < 200 || $status >= 300) {
            $msg = null;
            if (is_array($data)) {
                $msg = (string)($data['error_description'] ?? ($data['error'] ?? ''));
            }
            return ['ok' => false, 'error' => $msg ?: ('OAuth HTTP ' . $status), 'status' => $status, 'data' => $data];
        }

        return ['ok' => true, 'token' => $data];
    }
}
