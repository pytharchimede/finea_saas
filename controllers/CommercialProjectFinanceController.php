<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/CommercialProjectFinanceMigrationService.php';
require_once __DIR__ . '/../services/CommercialProjectFinanceService.php';

final class CommercialProjectFinanceController
{
    private CommercialProjectFinanceService $service;

    public function __construct(PDO $pdo)
    {
        (new CommercialProjectFinanceMigrationService($pdo))->ensureSchema();
        $this->service = new CommercialProjectFinanceService(new CommercialProjectFinanceRepository($pdo));
    }

    public function enrichQuotes(array $quotes): array { return $this->service->enrichQuotes($quotes); }
    public function createPurchaseOrder(array $input, int $userId): array { return $this->service->registerPurchaseOrder($input, $userId); }
    public function linkPurchaseOrder(int $chantierId, int $purchaseOrderId, int $userId): void { $this->service->linkToChantier($chantierId, $purchaseOrderId, $userId); }
    public function archiveQuote(int $devisId, int $userId, string $reason): void { $this->service->archiveQuote($devisId, $userId, $reason); }
    public function restoreQuote(int $devisId, int $userId, string $note): void { $this->service->restoreQuote($devisId, $userId, $note); }
    public function treasury(int $forecastDays = 30, ?string $dateFrom = null, ?string $dateTo = null): array { return $this->service->treasury($forecastDays,$dateFrom,$dateTo); }
    public function saveLoan(array $input,int $userId): void { $this->service->saveLoan($input,$userId); }
    public function borrowVatCredit(array $input,int $userId): void { $this->service->borrowVatCredit($input,$userId); }
    public function loans(): array { return $this->service->loans(); }
    public function repayLoan(int $id,float $amount): void { $this->service->repayLoan($id,$amount); }
    public function saveOpeningBalance(string $date, float $amount, int $userId, string $note): void { $this->service->saveOpeningBalance($date, $amount, $userId, $note); }
    public function saveVatRemittance(array $input,int $userId): void { $this->service->saveVatRemittance($input,$userId); }
    public function vatRemittances(string $from,string $to): array { return $this->service->vatRemittances($from,$to); }
    public function saveFixedCharge(array $input,int $userId): void { $this->service->saveFixedCharge($input,$userId); }
    public function fixedCharges(string $from,string $to): array { return $this->service->fixedCharges($from,$to); }
    public function recurringCharges(string $from,string $to): array { return $this->service->recurringCharges($from,$to); }
    public function forecastFebDetails(string $from,string $to): array { return $this->service->forecastFebDetails($from,$to); }
    public function payFixedCharge(int $id,string $date): void { $this->service->payFixedCharge($id,$date); }
}
