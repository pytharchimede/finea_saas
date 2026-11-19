<?php

class DwServiceApiClient
{
    private const DEFAULT_BASE_URL = 'https://www.apiremoteaccess.com/en/api/json';

    private string $apiKey;
    private string $apiSecret;
    private string $baseUrl;

    public function __construct(string $apiKey, string $apiSecret, ?string $baseUrl = null)
    {
        $this->apiKey = trim($apiKey);
        $this->apiSecret = trim($apiSecret);
        $this->baseUrl = $this->normalizeBaseUrl($baseUrl ?: self::DEFAULT_BASE_URL);
    }

    public function createSession(array $payload): array
    {
        return $this->request('POST', '/sessions', $payload);
    }

    private function request(string $method, string $path, ?array $payload = null): array
    {
        if ($this->apiKey === '' || $this->apiSecret === '') {
            throw new RuntimeException('Clé API DWService ou secret manquant.');
        }

        if (!function_exists('curl_init')) {
            throw new RuntimeException('Extension cURL indisponible sur ce serveur PHP.');
        }

        $url = $this->baseUrl . '/' . ltrim($path, '/');
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'X-DW-Authorization: ' . $this->buildAuthorizationHeader(),
        ];

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Impossible d’initialiser la requête DWService.');
        }

        $jsonPayload = null;
        if ($payload !== null) {
            $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($jsonPayload === false) {
                throw new RuntimeException('Impossible d’encoder la requête DWService.');
            }
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        if ($jsonPayload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
        }

        $responseBody = curl_exec($ch);
        $curlError = curl_error($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($responseBody === false) {
            throw new RuntimeException('Erreur réseau DWService: ' . $curlError);
        }

        $decoded = json_decode($responseBody, true);
        if (!is_array($decoded)) {
            $decoded = [];
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            $message = $this->extractErrorMessage($decoded);
            if ($message === '') {
                $message = trim(strip_tags($responseBody));
            }
            if ($message === '') {
                $message = 'Réponse DWService invalide (HTTP ' . $statusCode . ').';
            }

            throw new RuntimeException($message, $statusCode);
        }

        return $decoded;
    }

    private function buildAuthorizationHeader(): string
    {
        $currentTime = (string) round(microtime(true) * 1000);
        $signature = base64_encode(hash_hmac('sha1', $currentTime, $this->apiSecret, true));

        return 'HM1 ' . $this->apiKey . ':' . $signature . ':' . $currentTime;
    }

    private function normalizeBaseUrl(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return self::DEFAULT_BASE_URL;
        }

        $value = rtrim($value, '/');
        if (!preg_match('#^https?://#i', $value)) {
            $value = 'https://' . ltrim($value, '/');
        }

        return $value;
    }

    private function extractErrorMessage(array $decoded): string
    {
        $candidates = [
            $decoded['message'] ?? null,
            $decoded['error'] ?? null,
            $decoded['description'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return '';
    }
}
