<?php
declare(strict_types=1);

require_once __DIR__ . '/../model/FneClient.php';
require_once __DIR__ . '/../model/FneValidator.php';

final class FneInvoiceLookupService
{
    public function __construct(private FneClient $client) {}

    public function verify(string $code, string $codeType = 'verificationCode'): array
    {
        $code = trim($code);
        if ($code === '') {
            throw new DomainException('Le code FNE est obligatoire.');
        }
        if (strlen($code) > 180 || !preg_match('/^[A-Za-z0-9._:\/-]+$/', $code)) {
            throw new DomainException('Format du code FNE invalide.');
        }
        $codeType = $codeType === 'invoiceNumber' ? 'invoiceNumber' : 'verificationCode';
        if (!$this->client->isConfigured()) {
            throw new RuntimeException('FNE non configuré sur cet environnement.');
        }
        $payload = [$codeType => $code];
        $validation = FneValidator::validateVerifyPayload($payload);
        if (empty($validation['ok'])) {
            throw new DomainException(implode(' ', (array) ($validation['errors'] ?? [])));
        }
        $response = $this->client->verifyInvoice($payload);
        if (empty($response['ok'])) {
            throw new RuntimeException((string) ($response['error'] ?? 'Vérification FNE échouée.'));
        }
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        return [
            'verified' => true,
            'http_status' => (int) ($response['status'] ?? 0),
            'lookup_type' => $codeType,
            'lookup_code_masked' => $this->mask($code),
            'invoice' => $this->normalizeInvoice($data),
            'raw_data' => $data,
        ];
    }

    private function normalizeInvoice(array $data): array
    {
        $root = is_array($data['data'] ?? null) ? $data['data'] : $data;
        $client = is_array($root['client'] ?? null) ? $root['client'] : [];
        $items = $root['items'] ?? $root['lines'] ?? $root['details'] ?? [];
        return [
            'invoice_number' => $this->first($root, ['invoiceNumber', 'invoice_number', 'number', 'reference', 'id']),
            'verification_code' => $this->first($root, ['verificationCode', 'verification_code', 'code']),
            'invoice_date' => $this->first($root, ['invoiceDate', 'invoice_date', 'date', 'createdAt']),
            'total_amount' => $this->number($root, ['totalTtc', 'total_ttc', 'totalAmount', 'total_amount', 'amount']),
            'client_name' => $this->first($client + $root, ['companyName', 'clientCompanyName', 'client_name', 'name']),
            'client_phone' => $this->first($client + $root, ['phone', 'clientPhone', 'client_phone']),
            'client_email' => $this->first($client + $root, ['email', 'clientEmail', 'client_email']),
            'client_ncc' => $this->first($client + $root, ['ncc', 'clientNcc', 'client_ncc']),
            'payment_method' => $this->first($root, ['paymentMethod', 'payment_method']),
            'currency' => $this->first($root, ['currency', 'foreignCurrency', 'foreign_currency']),
            'items' => is_array($items) ? array_values($items) : [],
        ];
    }

    private function first(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && !is_array($data[$key]) && trim((string) $data[$key]) !== '') {
                return trim((string) $data[$key]);
            }
        }
        return null;
    }

    private function number(array $data, array $keys): ?float
    {
        $value = $this->first($data, $keys);
        return $value !== null && is_numeric($value) ? round((float) $value, 2) : null;
    }

    private function mask(string $code): string
    {
        return strlen($code) <= 8 ? str_repeat('*', strlen($code)) : substr($code, 0, 4) . '…' . substr($code, -4);
    }
}
