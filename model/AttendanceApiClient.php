<?php

declare(strict_types=1);

require_once __DIR__ . '/EnvConfig.php';

final class AttendanceApiClient
{
    private string $baseUrl;
    private int $timeoutSeconds;
    private bool $sslVerify;
    private ?string $caInfo;
    private ?string $controlToken;
    private ?string $fineaEmail;
    private ?string $fineaPassword;
    private bool $allowInsecureTlsFallback;

    public function __construct(?string $baseUrl = null, int $timeoutSeconds = 6)
    {
        \EnvConfig::loadFromDirectory(__DIR__);

        $baseUrl = $baseUrl ?? (string) (\EnvConfig::get('ATTENDANCE_API_BASE_URL', '') ?? '');
        $baseUrl = rtrim(trim($baseUrl), '/');

        $this->baseUrl = $baseUrl;
        $this->timeoutSeconds = max(2, min(20, $timeoutSeconds));

        $this->sslVerify = true;

        $caInfo = trim((string) (\EnvConfig::get('ATTENDANCE_API_CAINFO', '') ?? ''));
        $this->caInfo = ($caInfo !== '') ? $caInfo : null;

        $this->allowInsecureTlsFallback = false;

        $token = trim((string) (\EnvConfig::get('ATTENDANCE_CONTROL_TOKEN', '') ?? ''));
        $this->controlToken = ($token !== '') ? $token : null;

        $email = trim((string) (\EnvConfig::get('ATTENDANCE_FINEA_EMAIL', '') ?? ''));
        $password = trim((string) (\EnvConfig::get('ATTENDANCE_FINEA_PASSWORD', '') ?? ''));
        $this->fineaEmail = ($email !== '') ? $email : null;
        $this->fineaPassword = ($password !== '') ? $password : null;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function health(): array
    {
        return $this->getJson('health');
    }

    public function employees(array $params = []): array
    {
        return $this->getJson('employees', $params);
    }

    public function records(array $params = []): array
    {
        return $this->getJson('logs', $params);
    }

    public function devices(array $params = []): array
    {
        return $this->getJson('devices', $params);
    }

    public function today(array $params = []): array
    {
        return $this->getJson('logs', $params);
    }

    public function controlData(array $params = []): array
    {
        $headers = [];
        if ($this->controlToken !== null) {
            $headers[] = 'Authorization: Bearer ' . $this->controlToken;
        }

        return $this->getJson('logs', $params, $headers);
    }

    private function getJson(string $route, array $params = [], array $headers = []): array
    {
        if ($this->fineaEmail !== null && $this->fineaPassword !== null) {
            $headers[] = 'X-FINEA-EMAIL: ' . $this->fineaEmail;
            $headers[] = 'X-FINEA-PASSWORD: ' . $this->fineaPassword;
        }

        if ($this->baseUrl === '') { throw new RuntimeException('Badgeuse non configurée pour cette installation.'); }
        if (!filter_var($this->baseUrl, FILTER_VALIDATE_URL) || strtolower((string)parse_url($this->baseUrl, PHP_URL_SCHEME)) !== 'https') { throw new RuntimeException('La badgeuse exige une URL HTTPS valide.'); }
        $url = $this->buildUrl($route, $params);
        $body = $this->httpGet($url, $headers);

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Réponse API attendance invalide (JSON non décodable).');
        }

        return $this->normalizePayload($route, $decoded);
    }

    private function buildUrl(string $route, array $params): string
    {
        $url = $this->baseUrl;
        $query = ['route' => trim($route, '/')];

        foreach ($params as $k => $v) {
            if ($v === null) {
                continue;
            }
            $v = is_bool($v) ? ($v ? '1' : '0') : (string) $v;
            $v = trim($v);
            if ($v === '') {
                continue;
            }
            $query[(string) $k] = $v;
        }

        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        return $url;
    }

    private function normalizePayload(string $route, array $payload): array
    {
        $route = trim($route, '/');
        if ($route !== 'logs' || isset($payload['records'])) {
            return $payload;
        }

        $rows = null;
        foreach (['logs', 'data', 'items', 'aaData'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                $rows = $payload[$key];
                break;
            }
        }

        if ($rows === null) {
            return $payload;
        }

        $records = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $devicePunchTime = (string) ($row['device_punch_time'] ?? $row['punch_time'] ?? '');
            $occurredAt = $this->officialOccurredAt($row);
            $employeeCode = (string) ($row['personnel_matricule'] ?? $row['zkteco_pin'] ?? '');
            $eventUid = (string) ($row['event_uid'] ?? $row['id'] ?? '');
            if ($eventUid === '' && ($occurredAt !== '' || $employeeCode !== '')) {
                $eventUid = sha1($employeeCode . '|' . $occurredAt . '|' . $devicePunchTime . '|' . json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }

            $records[] = [
                'event_uid' => $eventUid,
                'occurred_at' => $occurredAt,
                'occurred_on' => substr($occurredAt, 0, 10),
                'device_punch_time' => $devicePunchTime,
                'attendance_official_time_source' => isset($row['received_at']) && trim((string) $row['received_at']) !== '' ? 'received_at_gmt0' : 'punch_time_fallback',
                'employee_code' => $employeeCode,
                'employee_name' => (string) ($row['personnel_name'] ?? $row['display_name'] ?? ''),
                'event_label' => (string) ($row['event_label'] ?? ''),
                'event_code' => (string) ($row['punch_state'] ?? ''),
            ] + $row;
        }

        $payload['records'] = $records;
        return $payload;
    }

    private function officialOccurredAt(array $row): string
    {
        foreach (['received_at', 'server_received_at', 'collected_at', 'ingested_at', 'inserted_at'] as $key) {
            $value = trim((string) ($row[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return trim((string) ($row['punch_time'] ?? ''));
    }

    private function httpGet(string $url, array $headers = []): string
    {
        $timeout = $this->timeoutSeconds;
        $headers = array_values(array_filter(array_map('strval', $headers), static fn($h) => trim($h) !== ''));
        $defaultUa = 'finea-attendance-client/1.0';

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
            curl_setopt($ch, CURLOPT_USERAGENT, $defaultUa);
            if (!empty($headers)) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            }

            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->sslVerify);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $this->sslVerify ? 2 : 0);
            if ($this->caInfo !== null) {
                curl_setopt($ch, CURLOPT_CAINFO, $this->caInfo);
            }

            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            $errno = curl_errno($ch);

            if (($body === false || $body === '') && $this->allowInsecureTlsFallback && $this->sslVerify && $errno === 60) {
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                $body = curl_exec($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err = curl_error($ch);
            }

            curl_close($ch);

            if (!is_string($body) || $body === '') {
                throw new RuntimeException('Impossible de lire l\'API attendance. ' . ($err ? ('Erreur cURL: ' . $err) : ''));
            }
            if ($code < 200 || $code >= 300) {
                throw new RuntimeException('API attendance: HTTP ' . $code);
            }

            return $body;
        }

        $headerLines = "User-Agent: " . $defaultUa . "\r\n";
        foreach ($headers as $h) {
            $headerLines .= $h . "\r\n";
        }

        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'follow_location' => 0,
                'timeout' => $timeout,
                'header' => $headerLines,
            ],
            'ssl' => [
                'verify_peer' => $this->sslVerify,
                'verify_peer_name' => $this->sslVerify,
                'cafile' => $this->caInfo,
            ],
        ]);

        $body = @file_get_contents($url, false, $ctx);
        if (!is_string($body) || trim($body) === '') {
            throw new RuntimeException('Impossible de lire l\'API attendance (file_get_contents).');
        }

        return $body;
    }
}
