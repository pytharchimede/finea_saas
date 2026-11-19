<?php

declare(strict_types=1);

/**
 * Reset des données d'intégration Attendance (côté Finea).
 *
 * Usage:
 *   php tools/reset_attendance_data.php --mode=soft --yes
 *   php tools/reset_attendance_data.php --mode=hard --yes
 *   php tools/reset_attendance_data.php --mode=nuclear --yes
 *   php tools/reset_attendance_data.php --mode=hard --drop-links --yes
 *
 * Modes:
 * - soft:   TRUNCATE attendance_sync_runs + attendance_events
 * - hard:   soft + suppression des lignes AUTO dans rh_attendance_daily
 * - nuclear: hard + TRUNCATE rh_attendance_daily (DANGEREUX)
 *
 * Options:
 * - --drop-links : TRUNCATE attendance_personnel_links (il faudra relier RH ↔ badgeuse)
 * - --yes        : obligatoire pour exécuter (sinon dry-run)
 */

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/AttendanceSyncService.php';

function arg_value(string $name, array $argv): ?string
{
    foreach ($argv as $arg) {
        if (strpos($arg, $name . '=') === 0) {
            return substr($arg, strlen($name) + 1);
        }
    }
    return null;
}

function has_flag(string $flag, array $argv): bool
{
    return in_array($flag, $argv, true);
}

$mode = strtolower(trim((string)(arg_value('--mode', $argv) ?? 'soft')));
$dropLinks = has_flag('--drop-links', $argv);
$confirmed = has_flag('--yes', $argv);

if (!in_array($mode, ['soft', 'hard', 'nuclear'], true)) {
    fwrite(STDERR, "Mode invalide. Valeurs possibles: soft|hard|nuclear\n");
    exit(2);
}

$pdo = Database::getConnection();

// S'assure que les tables existent (au cas où)
new AttendanceSyncService($pdo);

$dbName = (string)($pdo->query('SELECT DATABASE()')->fetchColumn() ?: '');
echo "Target DB: ", ($dbName !== '' ? $dbName : '(unknown)'), "\n";
echo "Mode: ", $mode, $dropLinks ? " + drop-links" : "", "\n";

$counts = [];
foreach (['attendance_sync_runs', 'attendance_events', 'attendance_personnel_links', 'rh_attendance_daily'] as $table) {
    try {
        $counts[$table] = (int)($pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        $counts[$table] = null;
    }
}

echo "Counts before:\n";
foreach ($counts as $t => $c) {
    echo "  - ", $t, ': ', ($c === null ? 'N/A' : (string)$c), "\n";
}

$plan = [];
$plan[] = 'TRUNCATE TABLE attendance_sync_runs';
$plan[] = 'TRUNCATE TABLE attendance_events';

if ($mode === 'hard' || $mode === 'nuclear') {
    $plan[] = "DELETE FROM rh_attendance_daily WHERE recorded_by_name = 'attendance-api' OR notes = 'Auto: attendance-api'";
}

if ($mode === 'nuclear') {
    $plan[] = 'TRUNCATE TABLE rh_attendance_daily';
}

if ($dropLinks) {
    $plan[] = 'TRUNCATE TABLE attendance_personnel_links';
}

echo "\nPlan:\n";
foreach ($plan as $sql) {
    echo "  - ", $sql, "\n";
}

if (!$confirmed) {
    echo "\nDRY-RUN: ajoute --yes pour exécuter.\n";
    exit(0);
}

// Exécution
try {
    foreach ($plan as $sql) {
        $pdo->exec($sql);
    }

    echo "\nOK: reset terminé.\n";

    echo "Counts after:\n";
    foreach (['attendance_sync_runs', 'attendance_events', 'attendance_personnel_links', 'rh_attendance_daily'] as $table) {
        try {
            $c = (int)($pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn() ?: 0);
            echo "  - ", $table, ': ', (string)$c, "\n";
        } catch (Throwable $e) {
            echo "  - ", $table, ": N/A\n";
        }
    }

    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}
