<?php

require_once __DIR__ . '/../services/ConfigService.php';

class ConfigController
{
    private ConfigService $service;

    public function __construct()
    {
        $this->service = new ConfigService(new ConfigRepository());
    }

    public function ai(): array
    {
        return $this->service->ai();
    }

    public function fne(): array
    {
        return $this->service->fne();
    }

    public function microsoftTodo(): array
    {
        return $this->service->microsoftTodo();
    }

    public function documentationSecurity(): array
    {
        return $this->service->documentationSecurity();
    }

    public function pointFinancierDigest(): array
    {
        return $this->service->pointFinancierDigest();
    }

    public function taxRates(): array
    {
        return $this->service->taxRates();
    }
}
