<?php

require_once __DIR__ . '/../controllers/ConfigController.php';

class AppConfig
{
    private ConfigController $controller;

    public function __construct(?ConfigController $controller = null)
    {
        $this->controller = $controller ?: new ConfigController();
    }

    public function ai(): array
    {
        return $this->controller->ai();
    }

    public function fne(): array
    {
        return $this->controller->fne();
    }

    public function microsoftTodo(): array
    {
        return $this->controller->microsoftTodo();
    }

    public function documentationSecurity(): array
    {
        return $this->controller->documentationSecurity();
    }

    public function pointFinancierDigest(): array
    {
        return $this->controller->pointFinancierDigest();
    }

    public function taxRates(): array
    {
        return $this->controller->taxRates();
    }
}
