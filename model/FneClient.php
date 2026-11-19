<?php

/**
 * Client HTTP minimal pour l'API FNE (DGI Côte d'Ivoire).
 *
 * - Auth: Authorization: Bearer <API_KEY>
 * - Format: JSON (Content-Type + Accept)
 * - Endpoints (PDF Mai 2025):
 *   - POST {base}/external/invoices/sign
 *   - POST {base}/external/invoices/{id}/refund
 *   - POST {base}/external/invoices/verify
 */
class FneClient
{
    private string $baseUrl;
    private string $apiKey;
    private int $connectTimeout;
    private int $timeout;
    private ?bool $verifySsl;

    public function __construct(string $baseUrl, string $apiKey, array $options = [])
    {
        $this->baseUrl = rtrim(trim($baseUrl), '/');
        $this->apiKey = trim($apiKey);
        $this->connectTimeout = (int)($options['connect_timeout'] ?? 10);
        $this->timeout = (int)($options['timeout'] ?? 20);
        $this->verifySsl = array_key_exists('verify_ssl', $options) ? (bool)$options['verify_ssl'] : null;
    }

    public static function fromConfigFile(?string $configPath = null): self
    {
        $configPath = $configPath ?: (__DIR__ . '/../config/fne.php');
        $cfg = [];
        if (is_file($configPath)) {
            $loaded = require $configPath;
            if (is_array($loaded)) {
                $cfg = $loaded;
            }
        }

        $baseUrl = (string)($cfg['base_url'] ?? '');
        $apiKey = (string)($cfg['api_key'] ?? '');

        return new self($baseUrl, $apiKey, [
            'connect_timeout' => (int)($cfg['connect_timeout'] ?? 10),
            'timeout' => (int)($cfg['timeout'] ?? 20),
            'verify_ssl' => $cfg['verify_ssl'] ?? null,
        ]);
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->apiKey !== '';
    }

    public function signInvoice(array $payload): array
    {
        return $this->requestJson('POST', '/external/invoices/sign', $payload);
    }

    public function refundInvoice(string $invoiceId, array $payload): array
    {
        $invoiceId = trim($invoiceId);
        $path = '/external/invoices/' . rawurlencode($invoiceId) . '/refund';
        return $this->requestJson('POST', $path, $payload);
    }

    public function verifyInvoice(array $payload): array
    {
        return $this->requestJson('POST', '/external/invoices/verify', $payload);
    }

    private function requestJson(string $method, string $path, ?array $payload): array
    {
        $method = strtoupper(trim($method));
        $url = $this->baseUrl . $path;

        if ($this->apiKey === '') {
            return [
                'ok' => false,
                'status' => 0,
                'data' => null,
                'raw' => null,
                'error' => 'FNE non configuré : api_key manquante',
                'url' => $url,
            ];
        }

        $ch = curl_init();
        if ($ch === false) {
            return [
                'ok' => false,
                'status' => 0,
                'data' => null,
                'raw' => null,
                'error' => 'Impossible d\'initialiser cURL',
                'url' => $url,
            ];
        }

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $this->apiKey,
        ];

        $body = null;
        if ($payload !== null) {
            $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($body === false) {
                return [
                    'ok' => false,
                    'status' => 0,
                    'data' => null,
                    'raw' => null,
                    'error' => 'Payload JSON invalide (json_encode a échoué)',
                    'url' => $url,
                ];
            }
        }

        $verifySsl = $this->verifySsl;
        if ($verifySsl === null) {
            $verifySsl = (stripos($url, 'https://') === 0);
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => $verifySsl,
            CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($ch);
        $curlErr = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            return [
                'ok' => false,
                'status' => $status,
                'data' => null,
                'raw' => null,
                'error' => $curlErr ?: 'Erreur cURL inconnue',
                'url' => $url,
            ];
        }

        $decoded = null;
        $trimmed = trim((string)$raw);
        if ($trimmed !== '') {
            $decoded = json_decode($trimmed, true);
        }

        $isJson = is_array($decoded);
        $ok = ($status >= 200 && $status < 300);

        if ($ok) {
            return [
                'ok' => true,
                'status' => $status,
                'data' => $isJson ? $decoded : null,
                'raw' => $raw,
                'error' => null,
                'url' => $url,
            ];
        }

        $message = null;
        if ($isJson) {
            $message = (string)($decoded['message'] ?? $decoded['error'] ?? '');
            $message = trim($message);
        }

        return [
            'ok' => false,
            'status' => $status,
            'data' => $isJson ? $decoded : null,
            'raw' => $raw,
            'error' => $message !== '' ? $message : ('Erreur HTTP ' . $status),
            'url' => $url,
        ];
    }
}
