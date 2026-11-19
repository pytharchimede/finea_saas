<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/FneInvoiceLookupService.php';

final class FneInvoiceLookupController
{
    private FneInvoiceLookupService $service;

    public function __construct(?FneClient $client = null)
    {
        $this->service = new FneInvoiceLookupService($client ?? FneClient::fromConfigFile());
    }

    public function verify(string $code, string $codeType = 'verificationCode'): array
    {
        return $this->service->verify($code, $codeType);
    }
}
