<?php

/**
 * Synchronisation FEB legacy -> workflow moderne.
 *
 * - Script idempotent: peut être relancé sans doublons.
 * - Conçu pour être exécuté en CLI (Task Scheduler / cron).
 */

declare(strict_types=1);

$root = realpath(__DIR__ . '/..');
if ($root === false) {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Unable to resolve project root\n");
    } else {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Unable to resolve project root\n";
    }
    exit(1);
}

require_once $root . '/tools/lib/feb_legacy_migration.php';

function feb_sync_parse_cli_args(array $argv): array
{
    $source = 'projet';
    $target = 'projet';
    $direction = '';
    $dryRun = false;
    $limit = 0;

    foreach ($argv as $arg) {
        if (strpos($arg, '--source=') === 0) {
            $source = substr($arg, strlen('--source='));
        } elseif (strpos($arg, '--target=') === 0) {
            $target = substr($arg, strlen('--target='));
        } elseif (strpos($arg, '--direction=') === 0) {
            $direction = substr($arg, strlen('--direction='));
        } elseif ($arg === '--dry-run') {
            $dryRun = true;
        } elseif (strpos($arg, '--limit=') === 0) {
            $limitValue = substr($arg, strlen('--limit='));
            if (is_numeric($limitValue)) {
                $limit = (int) $limitValue;
            }
        }
    }

    $source = strtolower(trim((string) $source));
    $target = strtolower(trim((string) $target));
    $direction = strtolower(trim((string) $direction));

    if ($direction !== '' && !in_array($direction, ['both', 'projet-to-app', 'app-to-projet'], true)) {
        throw new InvalidArgumentException('Invalid --direction value. Allowed: both|projet-to-app|app-to-projet');
    }
    if ($direction === '') {
        if (!in_array($source, ['projet', 'app'], true)) {
            throw new InvalidArgumentException('Invalid --source value. Allowed: projet|app');
        }
        if (!in_array($target, ['projet', 'app'], true)) {
            throw new InvalidArgumentException('Invalid --target value. Allowed: projet|app');
        }
    }

    $limit = max(0, (int) $limit);

    return ['source' => $source, 'target' => $target, 'direction' => $direction, 'dryRun' => $dryRun, 'limit' => $limit];
}

function feb_sync_run(string $source, string $target, bool $dryRun, int $limit, bool $cliOutput = true): array
{
    $log = function (string $level, string $message) use ($dryRun, $cliOutput): void {
        if (!$cliOutput) {
            return;
        }
        if ($level === 'error') {
            fwrite(STDERR, $message . "\n");
            return;
        }
        if ($dryRun && $level === 'info') {
            echo $message . "\n";
        }
    };

    return migrate_legacy_feb_to_modern($source, $dryRun, $limit, $log, $target);
}

function feb_sync_plan_runs(array $args): array
{
    $direction = (string) ($args['direction'] ?? '');
    if ($direction === 'both') {
        return [
            ['source' => 'projet', 'target' => 'app'],
            ['source' => 'app', 'target' => 'projet'],
        ];
    }
    if ($direction === 'projet-to-app') {
        return [['source' => 'projet', 'target' => 'app']];
    }
    if ($direction === 'app-to-projet') {
        return [['source' => 'app', 'target' => 'projet']];
    }

    return [[
        'source' => (string) ($args['source'] ?? 'projet'),
        'target' => (string) ($args['target'] ?? 'projet'),
    ]];
}

// =============================== CLI MODE ===============================
if (PHP_SAPI === 'cli') {
    try {
        $args = feb_sync_parse_cli_args($argv);
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }

    $startedAt = date('c');
    $plan = feb_sync_plan_runs($args);
    $dirLabel = (string) ($args['direction'] ?? '');
    if ($dirLabel === '') {
        $dirLabel = $args['source'] . '->' . $args['target'];
    }
    echo "[{$startedAt}] Starting FEB legacy sync (direction={$dirLabel}, dryRun=" . ($args['dryRun'] ? '1' : '0') . ")\n";

    try {
        $runs = [];
        $summary = ['total' => 0, 'imported' => 0, 'skipped' => 0, 'errors' => 0];

        foreach ($plan as $run) {
            $runStats = feb_sync_run((string) $run['source'], (string) $run['target'], (bool) $args['dryRun'], (int) $args['limit'], true);
            $runs[] = ['source' => $run['source'], 'target' => $run['target'], 'stats' => $runStats];
            foreach (['total', 'imported', 'skipped', 'errors'] as $k) {
                $summary[$k] += (int) ($runStats[$k] ?? 0);
            }
        }

        $stats = count($runs) === 1 ? ($runs[0]['stats'] ?? []) : ['runs' => $runs, 'summary' => $summary];
    } catch (Throwable $e) {
        $endedAt = date('c');
        fwrite(STDERR, "[{$endedAt}] Sync failed: {$e->getMessage()}\n");
        exit(1);
    }

    $endedAt = date('c');
    echo "[{$endedAt}] Sync finished\n";
    echo json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    $errCount = 0;
    if (isset($stats['summary']) && is_array($stats['summary'])) {
        $errCount = (int) ($stats['summary']['errors'] ?? 0);
    } else {
        $errCount = (int) ($stats['errors'] ?? 0);
    }
    exit($errCount > 0 ? 1 : 0);
}

// =============================== WEB MODE ===============================
require_once $root . '/auth_check.php';

// Sécurité: limiter au même périmètre que la validation finances / admin
$allowed = (
    hasPermission('acces_admin')
    || hasPermission('acces_compta')
    || hasPermission('acces_point_financier')
    || hasPermission('acces_direction')
);

if (!$allowed) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Accès refusé\n";
    exit(1);
}

if (empty($_SESSION['feb_legacy_sync_token'])) {
    $_SESSION['feb_legacy_sync_token'] = bin2hex(random_bytes(24));
}

$sessionToken = (string) $_SESSION['feb_legacy_sync_token'];

$result = null;
$error = '';

$direction = strtolower(trim((string) ($_POST['direction'] ?? '')));
$source = strtolower(trim((string) ($_POST['source'] ?? 'projet')));
$target = strtolower(trim((string) ($_POST['target'] ?? 'projet')));
$dryRun = !empty($_POST['dry_run']);
$limit = (string) ($_POST['limit'] ?? '0');
$limitInt = is_numeric($limit) ? max(0, (int) $limit) : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = (string) ($_POST['sync_token'] ?? '');
    if (!hash_equals($sessionToken, $postedToken)) {
        $error = 'Token invalide (rechargez la page).';
    } elseif ($direction !== '' && !in_array($direction, ['both', 'projet-to-app', 'app-to-projet'], true)) {
        $error = 'Direction invalide (both|projet-to-app|app-to-projet).';
    } elseif (!in_array($source, ['projet', 'app'], true)) {
        $error = 'Source invalide (projet|app).';
    } elseif (!in_array($target, ['projet', 'app'], true)) {
        $error = 'Cible invalide (projet|app).';
    } else {
        try {
            $plan = feb_sync_plan_runs(['direction' => $direction, 'source' => $source, 'target' => $target]);
            $runs = [];
            $summary = ['total' => 0, 'imported' => 0, 'skipped' => 0, 'errors' => 0];
            foreach ($plan as $run) {
                $runStats = feb_sync_run((string) $run['source'], (string) $run['target'], $dryRun, $limitInt, false);
                $runs[] = ['source' => $run['source'], 'target' => $run['target'], 'stats' => $runStats];
                foreach (['total', 'imported', 'skipped', 'errors'] as $k) {
                    $summary[$k] += (int) ($runStats[$k] ?? 0);
                }
            }
            $result = count($runs) === 1 ? ($runs[0]['stats'] ?? []) : ['runs' => $runs, 'summary' => $summary];
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

header('Content-Type: text/html; charset=utf-8');

?>
<!doctype html>
<html lang="fr">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sync FEB legacy</title>
    <style>
        body {
            font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
            margin: 24px;
            line-height: 1.4
        }

        .box {
            max-width: 820px
        }

        label {
            display: block;
            margin: 10px 0 4px
        }

        input,
        select,
        button {
            padding: 8px 10px;
            font-size: 14px
        }

        .row {
            display: flex;
            gap: 12px;
            flex-wrap: wrap
        }

        .row>div {
            min-width: 220px;
            flex: 1
        }

        .err {
            color: #b00020;
            margin: 12px 0
        }

        pre {
            background: #f6f8fa;
            padding: 12px;
            overflow: auto
        }
    </style>
</head>

<body>
    <div class="box">
        <h1>Synchronisation FEB legacy → moderne</h1>
        <p>À utiliser exceptionnellement en web. Pour l'automatisation, privilégier une tâche cron en CLI.</p>

        <?php if ($error !== ''): ?>
            <div class="err"><strong>Erreur:</strong> <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <form method="post">
            <input type="hidden" name="sync_token" value="<?= htmlspecialchars($sessionToken, ENT_QUOTES, 'UTF-8') ?>">
            <div class="row">
                <div>
                    <label for="direction">Direction</label>
                    <select id="direction" name="direction">
                        <option value="" <?= $direction === '' ? 'selected' : '' ?>>manuel (source → cible)</option>
                        <option value="both" <?= $direction === 'both' ? 'selected' : '' ?>>bidirectionnel (projet ↔ app)</option>
                        <option value="projet-to-app" <?= $direction === 'projet-to-app' ? 'selected' : '' ?>>projet → app</option>
                        <option value="app-to-projet" <?= $direction === 'app-to-projet' ? 'selected' : '' ?>>app → projet</option>
                    </select>
                </div>
                <div>
                    <label for="source">Source legacy</label>
                    <select id="source" name="source">
                        <option value="projet" <?= $source === 'projet' ? 'selected' : '' ?>>projet</option>
                        <option value="app" <?= $source === 'app' ? 'selected' : '' ?>>app</option>
                    </select>
                </div>
                <div>
                    <label for="target">Cible (table moderne)</label>
                    <select id="target" name="target">
                        <option value="projet" <?= $target === 'projet' ? 'selected' : '' ?>>projet</option>
                        <option value="app" <?= $target === 'app' ? 'selected' : '' ?>>app</option>
                    </select>
                </div>
                <div>
                    <label for="limit">Limit (0 = tout)</label>
                    <input id="limit" name="limit" type="number" min="0" step="1" value="<?= (int) $limitInt ?>">
                </div>
                <div>
                    <label>&nbsp;</label>
                    <label style="display:flex;gap:8px;align-items:center;margin:0">
                        <input type="checkbox" name="dry_run" value="1" <?= $dryRun ? 'checked' : '' ?>>
                        <span>Dry-run</span>
                    </label>
                </div>
            </div>
            <p style="margin-top:14px">
                <button type="submit">Lancer la sync</button>
            </p>
        </form>

        <?php if (is_array($result)): ?>
            <h2>Résultat</h2>
            <pre><?= htmlspecialchars(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?></pre>
        <?php endif; ?>
    </div>
</body>

</html>