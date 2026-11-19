<?php
require_once __DIR__ . '/AoScraperService.php';
require_once __DIR__ . '/SigomapAoAdapter.php';
require_once __DIR__ . '/ContenuLocalAoAdapter.php';

final class AoMultiSourceSyncService
{
    public function __construct(private PDO $pdo) {}

    public function run(): array
    {
        $adapters = [new SigomapAoAdapter()];
        if (ContenuLocalAoAdapter::isConfigured()) $adapters[] = new ContenuLocalAoAdapter();
        $stats = ['found' => 0, 'new' => 0, 'updated' => 0, 'errors' => 0, 'duplicates' => 0,
            'alerts_system' => 0, 'alerts_email' => 0, 'digest_emails' => 0, 'sources' => []];
        foreach ($adapters as $adapter) {
            try {
                $result = (new AoScraperService($this->pdo))->sync($adapter);
                foreach ($result as $key => $value) if (isset($stats[$key]) && is_int($value)) $stats[$key] += $value;
                $stats['sources'][$adapter->getSourceName()] = $result;
            } catch (Throwable $e) {
                $stats['errors']++;
                $stats['sources'][$adapter->getSourceName()] = ['error' => $e->getMessage()];
            }
        }
        return $stats;
    }
}
