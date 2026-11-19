<?php

declare(strict_types=1);

require_once __DIR__ . '/../model/EnvConfig.php';

final class FidestIaException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly ?string $requestId = null, public readonly int $httpStatus = 0)
    {
        parent::__construct($message, $httpStatus);
    }
}

class FidestIaClient
{
    private string $baseUrl;
    private string $token;
    private int $timeout;

    public function __construct(?string $baseUrl = null, ?string $token = null, ?int $timeout = null)
    {
        $this->baseUrl = rtrim(trim($baseUrl ?? (string) EnvConfig::get('FIDEST_IA_URL', 'https://ia.fidest.ci')), '/');
        $this->token = trim($token ?? (string) EnvConfig::get('FIDEST_IA_API_TOKEN', ''));
        $this->timeout = max(10, $timeout ?? (int) EnvConfig::get('FIDEST_IA_TIMEOUT', '180'));
    }

    public function isConfigured(): bool { return $this->baseUrl !== '' && $this->token !== ''; }

    public function ocrStatus(): array
    {
        if (!$this->isConfigured()) {
            return ['status' => 'unavailable', 'available' => false, 'busy' => false, 'message' => 'FIDEST IA n’est pas configurée.'];
        }

        $ch = curl_init($this->baseUrl . '/api/v1/ocr/status');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->token, 'Accept: application/json'],
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => min(15, $this->timeout),
        ]);
        $raw = curl_exec($ch);
        $httpStatus = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $transportError = curl_error($ch);
        curl_close($ch);

        if (!is_string($raw)) {
            throw new FidestIaException('TRANSPORT_ERROR', 'FIDEST IA est temporairement inaccessible' . ($transportError !== '' ? ' : ' . $transportError : '.'), null, $httpStatus);
        }

        try { $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR); }
        catch (JsonException $e) { throw new FidestIaException('INVALID_RESPONSE', 'FIDEST IA a renvoyé un statut OCR illisible.', null, $httpStatus); }

        $requestId = isset($payload['meta']['request_id']) ? (string) $payload['meta']['request_id'] : null;
        if ($httpStatus !== 200 || !($payload['success'] ?? false)) {
            $code = (string) ($payload['error']['code'] ?? ('HTTP_' . $httpStatus));
            $message = (string) ($payload['error']['message'] ?? $this->statusMessage($httpStatus));
            throw new FidestIaException($code, $message, $requestId, $httpStatus);
        }

        $ocr = (array) ($payload['data']['ocr'] ?? []);
        $status = (string) ($ocr['status'] ?? 'unavailable');
        return [
            'status' => in_array($status, ['available', 'busy', 'unavailable'], true) ? $status : 'unavailable',
            'available' => !empty($ocr['available']),
            'busy' => !empty($ocr['busy']),
            'message' => (string) ($ocr['message'] ?? 'État OCR inconnu.'),
            'request_id' => $requestId,
        ];
    }

    public function analyze(string $absolutePath, string $clientReference, string $documentType = 'AUTO'): array
    {
        if (!$this->isConfigured()) throw new FidestIaException('NOT_CONFIGURED', 'Le contrôle documentaire FIDEST IA n’est pas encore configuré.');
        if (!is_file($absolutePath) || !is_readable($absolutePath)) throw new FidestIaException('INVALID_FILE', 'Le document à contrôler est introuvable.');
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($absolutePath) ?: 'application/octet-stream';
        $raw = false; $status = 0; $transportError = '';
        for ($attempt = 0; $attempt < 3; $attempt++) {
            if ($attempt > 0) usleep($attempt * 500000);
            $ch = curl_init($this->baseUrl . '/api/v1/documents/analyze');
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->token, 'Accept: application/json'],
                CURLOPT_POSTFIELDS => ['document' => new CURLFile($absolutePath, $mime, basename($absolutePath)), 'document_type' => $documentType, 'client_reference' => $clientReference],
                CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => $this->timeout,
            ]);
            $raw = curl_exec($ch); $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $transportError = curl_error($ch); curl_close($ch);
            if ($raw === false || ($status !== 429 && $status < 500)) break;
        }
        if (!is_string($raw)) throw new FidestIaException('TRANSPORT_ERROR', 'FIDEST IA est temporairement inaccessible' . ($transportError !== '' ? ' : ' . $transportError : '.'));
        try { $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR); }
        catch (JsonException $e) { throw new FidestIaException('INVALID_RESPONSE', 'FIDEST IA a renvoyé une réponse illisible.', null, $status); }
        if (!is_array($payload)) throw new FidestIaException('INVALID_RESPONSE', 'FIDEST IA a renvoyé une réponse invalide.', null, $status);
        $requestId = isset($payload['meta']['request_id']) ? (string)$payload['meta']['request_id'] : null;
        if ($status !== 200 || !($payload['success'] ?? false)) {
            $code = (string)($payload['error']['code'] ?? ('HTTP_' . $status));
            $message = (string)($payload['error']['message'] ?? $this->statusMessage($status));
            error_log('[fidest_ia] request_id=' . ($requestId ?? '-') . ' status=' . $status . ' code=' . $code);
            throw new FidestIaException($code, $message, $requestId, $status);
        }
        error_log('[fidest_ia] request_id=' . ($requestId ?? '-') . ' status=200 client_reference=' . preg_replace('/[^A-Za-z0-9_.-]/', '', $clientReference));
        return ['data' => (array)($payload['data'] ?? []), 'request_id' => $requestId];
    }

    private function statusMessage(int $status): string
    {
        return match (true) {
            $status === 401 => 'Clé FIDEST IA absente, invalide, expirée ou révoquée.',
            $status === 403 => 'La clé FIDEST IA ne possède pas le scope requis.',
            $status === 422 => 'Le document ou les paramètres sont invalides.',
            $status === 429 => 'FIDEST IA limite temporairement les requêtes. Réessayez dans quelques instants.',
            $status >= 500 => 'FIDEST IA est temporairement indisponible.',
            default => 'Le contrôle FIDEST IA a échoué.',
        };
    }
}
