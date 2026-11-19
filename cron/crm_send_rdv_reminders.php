<?php
// Cron CLI - Envoi de rappels RDV CRM.

declare(strict_types=1);

require_once __DIR__ . '/../controllers/CronController.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Forbidden\n";
    exit(1);
}

function crm_cron_ensure_dir(string $dir): bool
{
    return is_dir($dir) || @mkdir($dir, 0755, true);
}

function crm_cron_log(string $message): void
{
    echo '[' . date('Y-m-d H:i:s') . "] $message\n";
}

try {
    $root = realpath(__DIR__ . '/..') ?: (__DIR__ . '/..');
    $lockDir = $root . '/storage/locks';
    crm_cron_ensure_dir($lockDir);
    $fp = @fopen($lockDir . '/crm_rdv_reminders.lock', 'c');
    if ($fp === false || !flock($fp, LOCK_EX | LOCK_NB)) {
        crm_cron_log('INFO: deja en cours (lock).');
        if (is_resource($fp)) {
            fclose($fp);
        }
        exit(0);
    }

    $stats = (new CronController())->sendCrmRdvReminders(new DateTimeImmutable('now'));

    crm_cron_log(sprintf('OK: sent=%d skipped=%d errors=%d', (int)$stats['sent'], (int)$stats['skipped'], (int)$stats['errors']));
    flock($fp, LOCK_UN);
    fclose($fp);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "ERR: Exception\n");
    exit(10);
}
