<?php
require_once __DIR__ . '/AoScraperService.php';
require_once __DIR__.'/AoSharedCatalog.php';
require_once __DIR__ . '/SigomapAoAdapter.php';
require_once __DIR__ . '/ContenuLocalAoAdapter.php';

final class AoMultiSourceSyncService
{
    public function __construct(private PDO $pdo) {}

    public function run(): array
    {
        return AoSharedCatalog::import($this->pdo);
    }
}
