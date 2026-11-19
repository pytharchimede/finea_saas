<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/FneInvoiceLookupService.php';

final class FakeFneLookupClient extends FneClient
{
    public array $lastPayload = [];
    public function __construct() { parent::__construct('https://fne.test', 'test-key'); }
    public function isConfigured(): bool { return true; }
    public function verifyInvoice(array $payload): array
    {
        $this->lastPayload = $payload;
        return ['ok' => true, 'status' => 200, 'data' => [
            'invoiceNumber' => 'FNE-2026-001',
            'verificationCode' => 'ABC-123-XYZ',
            'invoiceDate' => '2026-07-12',
            'totalTtc' => 125000,
            'client' => ['companyName' => 'CLIENT TEST', 'ncc' => 'CI-TEST'],
            'items' => [['description' => 'Travaux', 'quantity' => 1, 'amount' => 125000]],
        ]];
    }
}

$client = new FakeFneLookupClient();
$result = (new FneInvoiceLookupService($client))->verify('ABC-123-XYZ');
if (empty($result['verified']) || ($result['invoice']['invoice_number'] ?? '') !== 'FNE-2026-001') {
    throw new RuntimeException('Normalisation de la facture FNE invalide.');
}
if (($result['invoice']['total_amount'] ?? 0) !== 125000.0) {
    throw new RuntimeException('Montant FNE non récupéré.');
}
if (($client->lastPayload['verificationCode'] ?? '') !== 'ABC-123-XYZ') {
    throw new RuntimeException('Le code de vérification n’est pas transmis correctement.');
}
echo "FneInvoiceLookupTest: OK\n";
