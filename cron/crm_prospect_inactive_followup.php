<?php
// Cron CLI - Detection des prospects inactifs, alerte et planification de relance.
// Usage: php cron/crm_prospect_inactive_followup.php

declare(strict_types=1);

require_once __DIR__ . '/../model/EnvConfig.php';
\EnvConfig::loadFromDirectory(__DIR__);
require_once __DIR__ . '/../controllers/CronController.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Forbidden\n";
    exit(1);
}

function ensureDir(string $dir): bool
{
    if (is_dir($dir)) {
        return true;
    }
    return @mkdir($dir, 0755, true);
}

function logLine(string $message): void
{
    echo '[' . date('Y-m-d H:i:s') . "] $message\n";
}

try {
    $root = realpath(__DIR__ . '/..') ?: (__DIR__ . '/..');
    $lockDir = $root . '/storage/locks';
    ensureDir($lockDir);
    $lockPath = $lockDir . '/crm_prospect_inactive_followup.lock';

    $fp = @fopen($lockPath, 'c');
    if ($fp === false) {
        logLine('ERREUR: lock impossible: ' . $lockPath);
        exit(2);
    }
    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        logLine('INFO: deja en cours (lock).');
        fclose($fp);
        exit(0);
    }

    $stats = (new CronController())->sendInactiveProspectFollowups(
        new DateTimeImmutable('now'),
        (int) \EnvConfig::get('CRM_PROSPECT_INACTIVITY_DAYS', '14'),
        (int) \EnvConfig::get('CRM_PROSPECT_FOLLOWUP_DURATION_MIN', '30')
    );

    if (($stats['skipped_reason'] ?? '') === 'missing_columns') {
        logLine('INFO: colonnes prospects manquantes (migration incomplete) - skip.');
    } else {
        logLine(sprintf(
            'OK: alerts=%d followups=%d skipped=%d errors=%d',
            (int) $stats['alerts'],
            (int) $stats['followups'],
            (int) $stats['skipped'],
            (int) $stats['errors']
        ));
    }

    flock($fp, LOCK_UN);
    fclose($fp);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "ERR: Exception\n");
    exit(10);
}
