<?php
declare(strict_types=1);
require_once __DIR__ . '/../services/DevisOfferProjectMigrationService.php';
require_once __DIR__ . '/../services/DevisOfferProjectService.php';
require_once __DIR__ . '/../repositories/DevisOfferProjectRepository.php';
require_once __DIR__ . '/CommercialProjectFinanceController.php';

final class DevisOfferProjectController
{
    private DevisOfferProjectService $service;
    private CommercialProjectFinanceController $finance;

    public function __construct(PDO $pdo)
    {
        (new DevisOfferProjectMigrationService($pdo))->ensureSchema();
        $this->service = new DevisOfferProjectService(new DevisOfferProjectRepository($pdo));
        $this->finance = new CommercialProjectFinanceController($pdo);
    }

    public function group(array $quotes): array
    {
        return $this->service->group($this->finance->enrichQuotes($quotes));
    }

    public function classify(int $quoteId, string $type, int $userId): array
    {
        return $this->service->classify($quoteId, $type, $userId);
    }

    public function createQuote(
        Devis $devisModel,
        Offre $offreModel,
        array $data,
        array $lines,
        array $projectInput,
        int $userId
    ): array {
        return $this->service->createQuote($devisModel, $offreModel, $data, $lines, $projectInput, $userId);
    }
}
