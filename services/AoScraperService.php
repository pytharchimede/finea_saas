<?php

require_once __DIR__ . '/AoSourceAdapterInterface.php';
require_once __DIR__ . '/AoScoringService.php';
require_once __DIR__ . '/AoAlertService.php';
require_once __DIR__ . '/../repositories/AoOpportunityRepository.php';
require_once __DIR__ . '/../repositories/AoSyncLogRepository.php';
require_once __DIR__ . '/../model/AoOpportunity.php';

class AoScraperService
{
    private AoOpportunityRepository $opportunities;
    private AoSyncLogRepository $logs;
    private AoScoringService $scoring;
    private AoAlertService $alerts;

    public function __construct(PDO $pdo)
    {
        $this->opportunities = new AoOpportunityRepository($pdo);
        $this->logs = new AoSyncLogRepository($pdo);
        $this->scoring = new AoScoringService();
        $this->alerts = new AoAlertService($pdo);
    }

    public function sync(AoSourceAdapterInterface $adapter): array
    {
        $logId = $this->logs->start($adapter->getSourceName());
        $stats = ['found' => 0, 'new' => 0, 'updated' => 0, 'errors' => 0, 'duplicates' => 0, 'alerts_system' => 0, 'alerts_email' => 0, 'digest_emails' => 0];
        try {
            $rawItems = $adapter->fetchPublicOpportunities();
            $stats['found'] = count($rawItems);
            $uniqueItems = [];
            foreach ($rawItems as $raw) {
                $item = $adapter->normalizeOpportunity($raw);
                $prepared = AoOpportunity::prepare($item);
                $key = $prepared['reference_normalized'] !== ''
                    ? 'reference:' . $prepared['reference_normalized']
                    : 'signature:' . $prepared['signature_hash'];
                if (!isset($uniqueItems[$key]) || $this->completeness($item) > $this->completeness($uniqueItems[$key])) {
                    $uniqueItems[$key] = $item;
                }
            }
            $stats['duplicates'] += max(0, count($rawItems) - count($uniqueItems));

            foreach ($uniqueItems as $item) {
                try {
                    $item['score_pertinence'] = $this->scoring->score($item);
                    $result = $this->opportunities->upsert($item);
                    if ($result['is_new']) {
                        $stats['new']++;
                    } elseif ($result['is_updated']) {
                        $stats['updated']++;
                    } else {
                        $stats['duplicates']++;
                    }
                } catch (Throwable $exception) {
                    $stats['errors']++;
                }
            }
            $pendingOpportunities = $this->opportunities->pendingForAlerts(1000);
            foreach ($pendingOpportunities as $opportunity) {
                try {
                    $alertStats = $this->alerts->alertNewOpportunity($opportunity);
                    $stats['alerts_system'] += (int) ($alertStats['system'] ?? 0);
                    $stats['alerts_email'] += (int) ($alertStats['email'] ?? 0);
                } catch (Throwable $exception) {
                    $stats['errors']++;
                }
            }
            try {
                $digestStats = $this->alerts->sendPendingDigests($pendingOpportunities);
                $stats['digest_emails'] = (int) ($digestStats['daily_digest'] ?? 0) + (int) ($digestStats['weekly_digest'] ?? 0);
            } catch (Throwable $exception) {
                $stats['errors']++;
            }
            $this->logs->finish($logId, $stats, $stats['errors'] ? 'partial' : 'success', 'Doublons ignores: ' . $stats['duplicates']);
            return $stats;
        } catch (Throwable $exception) {
            $stats['errors']++;
            $this->logs->finish($logId, $stats, 'failed', $exception->getMessage());
            throw $exception;
        }
    }

    private function completeness(array $item): int
    {
        $score = 0;
        foreach (['reference', 'titre', 'autorite_contractante', 'type_marche', 'date_publication', 'date_limite', 'resume'] as $field) {
            $value = trim((string) ($item[$field] ?? ''));
            if ($value !== '') {
                $score += 10 + min(20, (int) floor(strlen($value) / 30));
            }
        }
        return $score;
    }
}
