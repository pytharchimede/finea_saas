<?php

require_once __DIR__ . '/../repositories/ConfigRepository.php';
require_once __DIR__ . '/../repositories/TaxRatesRepository.php';

class ConfigService
{
    private ConfigRepository $configRepository;

    public function __construct(ConfigRepository $configRepository)
    {
        $this->configRepository = $configRepository;
    }

    public function ai(): array
    {
        return $this->configRepository->ai();
    }

    public function fne(): array
    {
        return $this->configRepository->fne();
    }

    public function microsoftTodo(): array
    {
        return $this->configRepository->microsoftTodo();
    }

    public function documentationSecurity(): array
    {
        return $this->configRepository->documentationSecurity();
    }

    public function pointFinancierDigest(): array
    {
        return $this->configRepository->pointFinancierDigest();
    }

    public function taxRates(): array
    {
        try {
            return (new TaxRatesRepository())->getRates($this->configRepository->defaultTaxRates());
        } catch (Throwable $e) {
            return $this->configRepository->defaultTaxRates();
        }
    }
}
