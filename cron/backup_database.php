<?php
// Cron Linux (CLI) - Sauvegarde SQL gzip avec rotation.
// Exemple cPanel: /usr/local/bin/php -q /home/fidestci/projet.fidest.ci/cron/backup_database.php --source=app

require_once __DIR__ . '/../services/DatabaseBackupService.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Forbidden\n";
    exit(1);
}

function logLine(string $message): void
{
    echo '[' . date('Y-m-d H:i:s') . "] $message\n";
}

function parseArgs(array $argv): array
{
    $out = [];
    foreach ($argv as $i => $arg) {
        if ($i === 0 || strpos($arg, '--') !== 0) {
            continue;
        }
        $eq = strpos($arg, '=');
        if ($eq === false) {
            $out[substr($arg, 2)] = true;
            continue;
        }
        $out[substr($arg, 2, $eq - 2)] = substr($arg, $eq + 1);
    }
    return $out;
}

$args = parseArgs($argv);
$finished = false;
register_shutdown_function(static function () use (&$finished): void {
    if (!$finished) {
        fwrite(STDERR, '['.date('Y-m-d H:i:s')."] ERREUR : sauvegarde interrompue avant confirmation de succès.\n");
        exit(10);
    }
});

try {
    $backupService = new DatabaseBackupService(new DatabaseBackupRepository());
    $mode = $backupService->normalizeMode((string) ($args['mode'] ?? 'full'));
    $dir = isset($args['dir']) ? (string) $args['dir'] : null;
    $retentionDays = (int) ($args['retention_days'] ?? 0);

    $slot = isset($args['scheduled']) ? DatabaseBackupService::scheduledSlot(new DateTimeImmutable('now', new DateTimeZone('Africa/Abidjan'))) : null;
    logLine('Demarrage backup ' . strtoupper($mode) . '.');
    $result = $backupService->createBackup($mode, $dir, $retentionDays, 'cron', $slot);
    if (!empty($result['skipped'])) { $finished=true; logLine('Créneau '.$slot.' déjà sauvegardé ; prochaine tentative au créneau suivant.'); exit(0); }
    logLine(
        'Backup termine: ' . (string) ($result['name'] ?? '')
        . ' (' . number_format((int) ($result['size'] ?? 0), 0, ',', ' ') . ' octets).'
        . ' Rotation: ' . (int) ($result['removed'] ?? 0) . ' fichier(s) supprime(s).'
    );
    $finished=true;
    exit(0);
} catch (Throwable $e) {
    $finished=true;
    logLine('ERREUR: ' . $e->getMessage());
    exit(10);
}

