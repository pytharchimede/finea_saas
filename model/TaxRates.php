<?php

require_once __DIR__ . '/../repositories/TaxRatesRepository.php';

class TaxRates
{
    public const TABLE = TaxRatesRepository::TABLE;

    /**
     * @param array<string,float|int|string> $defaults
     */
    public static function ensureTable(PDO $pdo, array $defaults = []): void
    {
        (new TaxRatesRepository($pdo))->ensureTable($defaults);
    }

    /**
     * @param array<string,float|int|string> $defaults
     * @return array<string,float>
     */
    public static function getRates(PDO $pdo, array $defaults = []): array
    {
        return (new TaxRatesRepository($pdo))->getRates($defaults);
    }

    /**
     * @param array<string,float|int|string> $rates
     */
    public static function setRates(PDO $pdo, array $rates): void
    {
        (new TaxRatesRepository($pdo))->setRates($rates);
    }
}
