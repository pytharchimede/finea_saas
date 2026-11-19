<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../services/DatabaseBackupService.php';

$pdo = \Database::getConnection();
$backupService = new \DatabaseBackupService(new \DatabaseBackupRepository($pdo));

$flashError = '';
$flashSuccess = '';

function escapeIdent(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function beginSqlDownload(string $filename): void
{
    // Assure l'absence totale de sortie avant l'envoi des headers.
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
}

function sqlDumpDatabase(PDO $pdo, bool $withData = true): void
{
    set_time_limit(0);

    echo "-- Banamur - Export SQL\n";
    echo "-- Généré le: " . date('Y-m-d H:i:s') . "\n\n";
    echo "SET NAMES utf8mb4;\n";
    echo "SET time_zone = '+00:00';\n";
    echo "SET FOREIGN_KEY_CHECKS=0;\n\n";

    $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_NUM);

    foreach ($tables as $row) {
        $table = (string)($row[0] ?? '');
        if ($table === '') {
            continue;
        }

        echo "\n-- ----------------------------\n";
        echo "-- Table: " . $table . "\n";
        echo "-- ----------------------------\n\n";

        $st = $pdo->query('SHOW CREATE TABLE ' . escapeIdent($table));
        $createRow = $st ? $st->fetch(PDO::FETCH_ASSOC) : null;
        $createSql = '';
        if (is_array($createRow)) {
            $values = array_values($createRow);
            $createSql = (string)($values[1] ?? '');
        }

        echo 'DROP TABLE IF EXISTS ' . escapeIdent($table) . ";\n";
        if ($createSql !== '') {
            echo $createSql . ";\n\n";
        } else {
            echo "-- ERREUR: SHOW CREATE TABLE indisponible pour $table\n\n";
            continue;
        }

        if (!$withData) {
            continue;
        }

        $dataStmt = $pdo->query('SELECT * FROM ' . escapeIdent($table));
        if (!$dataStmt) {
            continue;
        }

        $buffer = [];
        $columns = null;
        $rowsInTable = 0;

        while ($r = $dataStmt->fetch(PDO::FETCH_ASSOC)) {
            if ($columns === null) {
                $columns = array_keys($r);
            }

            $vals = [];
            foreach ($r as $v) {
                if ($v === null) {
                    $vals[] = 'NULL';
                } elseif (is_bool($v)) {
                    $vals[] = $v ? '1' : '0';
                } elseif (is_int($v) || is_float($v)) {
                    $vals[] = (string)$v;
                } else {
                    $vals[] = $pdo->quote((string)$v);
                }
            }

            $buffer[] = '(' . implode(', ', $vals) . ')';
            $rowsInTable++;

            if (count($buffer) >= 200) {
                $colSql = $columns ? ('(' . implode(', ', array_map('escapeIdent', $columns)) . ')') : '';
                echo 'INSERT INTO ' . escapeIdent($table) . ' ' . $colSql . " VALUES\n";
                echo implode(",\n", $buffer) . ";\n\n";
                $buffer = [];
                if (function_exists('flush')) {
                    flush();
                }
            }
        }

        if (!empty($buffer)) {
            $colSql = $columns ? ('(' . implode(', ', array_map('escapeIdent', $columns)) . ')') : '';
            echo 'INSERT INTO ' . escapeIdent($table) . ' ' . $colSql . " VALUES\n";
            echo implode(",\n", $buffer) . ";\n\n";
            if (function_exists('flush')) {
                flush();
            }
        }

        echo "-- $rowsInTable lignes exportées pour $table\n";
    }

    echo "\nSET FOREIGN_KEY_CHECKS=1;\n";
}

function sqlDumpSingleTable(PDO $pdo, string $table, bool $withData = true): void
{
    set_time_limit(0);

    echo "-- Banamur - Export SQL (table unique)\n";
    echo "-- Table: " . $table . "\n";
    echo "-- Généré le: " . date('Y-m-d H:i:s') . "\n\n";
    echo "SET NAMES utf8mb4;\n";
    echo "SET time_zone = '+00:00';\n";
    echo "SET FOREIGN_KEY_CHECKS=0;\n\n";

    echo "-- ----------------------------\n";
    echo "-- Table: " . $table . "\n";
    echo "-- ----------------------------\n\n";

    $st = $pdo->query('SHOW CREATE TABLE ' . escapeIdent($table));
    $createRow = $st ? $st->fetch(PDO::FETCH_ASSOC) : null;
    $createSql = '';
    if (is_array($createRow)) {
        $values = array_values($createRow);
        $createSql = (string)($values[1] ?? '');
    }

    echo 'DROP TABLE IF EXISTS ' . escapeIdent($table) . ";\n";
    if ($createSql !== '') {
        echo $createSql . ";\n\n";
    } else {
        echo "-- ERREUR: SHOW CREATE TABLE indisponible pour $table\n\n";
        echo "SET FOREIGN_KEY_CHECKS=1;\n";
        return;
    }

    if ($withData) {
        $dataStmt = $pdo->query('SELECT * FROM ' . escapeIdent($table));
        if ($dataStmt) {
            $buffer = [];
            $columns = null;
            $rowsInTable = 0;

            while ($r = $dataStmt->fetch(PDO::FETCH_ASSOC)) {
                if ($columns === null) {
                    $columns = array_keys($r);
                }

                $vals = [];
                foreach ($r as $v) {
                    if ($v === null) {
                        $vals[] = 'NULL';
                    } else {
                        $vals[] = $pdo->quote((string)$v);
                    }
                }

                $buffer[] = '(' . implode(', ', $vals) . ')';
                $rowsInTable++;

                if (count($buffer) >= 200) {
                    $colSql = $columns ? ('(' . implode(', ', array_map('escapeIdent', $columns)) . ')') : '';
                    echo 'INSERT INTO ' . escapeIdent($table) . ' ' . $colSql . " VALUES\n";
                    echo implode(",\n", $buffer) . ";\n\n";
                    $buffer = [];
                    if (function_exists('flush')) {
                        flush();
                    }
                }
            }

            if (!empty($buffer)) {
                $colSql = $columns ? ('(' . implode(', ', array_map('escapeIdent', $columns)) . ')') : '';
                echo 'INSERT INTO ' . escapeIdent($table) . ' ' . $colSql . " VALUES\n";
                echo implode(",\n", $buffer) . ";\n\n";
                if (function_exists('flush')) {
                    flush();
                }
            }

            echo "-- $rowsInTable lignes exportées pour $table\n";
        }
    }

    echo "\nSET FOREIGN_KEY_CHECKS=1;\n";
}

$postAction = strtolower(trim((string)($_POST['action'] ?? '')));
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $postAction === 'run_backup') {
    $backupMode = strtolower(trim((string)($_POST['mode'] ?? 'full')));
    $retentionDays = (int)($_POST['retention_days'] ?? 0);

    try {
        $result = $backupService->createBackup($backupMode, null, $retentionDays, 'manual');
        \AuditTrail::log('database_backup_manual', [
            'module' => 'admin',
            'action' => 'run_database_backup',
            'entity_type' => 'database_backup',
            'entity_label' => (string)($result['name'] ?? ''),
            'details' => [
                'mode' => (string)($result['mode'] ?? $backupMode),
                'size' => (int)($result['size'] ?? 0),
                'removed' => (int)($result['removed'] ?? 0),
            ],
        ], $pdo);
        $flashSuccess = 'Sauvegarde creee avec succes : ' . (string)($result['name'] ?? 'fichier SQL gzip') . '.';
    } catch (Throwable $e) {
        $flashError = 'Sauvegarde impossible : ' . $e->getMessage();
        \AuditTrail::log('database_backup_manual_failed', [
            'module' => 'admin',
            'action' => 'run_database_backup',
            'entity_type' => 'database_backup',
            'result' => 'failed',
            'severity' => 'warning',
            'details' => ['error' => $e->getMessage()],
        ], $pdo);
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $postAction === 'restore_backup') {
    $filename = (string)($_POST['file'] ?? '');
    $confirm = strtoupper(trim((string)($_POST['confirm_restore'] ?? '')));

    if ($confirm !== 'RESTAURER') {
        $flashError = 'Restauration annulee : saisissez RESTAURER pour confirmer.';
    } else {
        try {
            $result = $backupService->restoreBackup($filename);
            $snapshot = is_array($result['snapshot'] ?? null) ? $result['snapshot'] : [];

            \AuditTrail::log('database_backup_restore', [
                'module' => 'admin',
                'action' => 'restore_database_backup',
                'entity_type' => 'database_backup',
                'entity_label' => (string)($result['restored_file'] ?? $filename),
                'details' => [
                    'safety_backup' => (string)($snapshot['name'] ?? ''),
                    'statements' => (int)($result['statements'] ?? 0),
                ],
            ], $pdo);

            $flashSuccess = 'Restauration terminee depuis ' . (string)($result['restored_file'] ?? $filename)
                . '. Une sauvegarde de securite a ete creee avant restauration : ' . (string)($snapshot['name'] ?? 'snapshot') . '.';
        } catch (Throwable $e) {
            $flashError = 'Restauration impossible : ' . $e->getMessage();
            \AuditTrail::log('database_backup_restore_failed', [
                'module' => 'admin',
                'action' => 'restore_database_backup',
                'entity_type' => 'database_backup',
                'entity_label' => $filename,
                'result' => 'failed',
                'severity' => 'critical',
                'details' => ['error' => $e->getMessage()],
            ], $pdo);
        }
    }
}

$action = strtolower(trim((string)($_GET['action'] ?? '')));
if ($action === 'download_backup') {
    try {
        $filename = (string)($_GET['file'] ?? '');
        $scope = strtolower(trim((string)($_GET['scope'] ?? 'backup')));
        $path = $scope === 'snapshot'
            ? $backupService->getRestoreSnapshotPathForDownload($filename)
            : $backupService->getBackupPathForDownload($filename);

        \AuditTrail::log('database_backup_download', [
            'module' => 'admin',
            'action' => 'download_database_backup',
            'entity_type' => 'database_backup',
            'entity_label' => basename($path),
        ], $pdo);

        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('Content-Length: ' . (string)filesize($path));
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    } catch (Throwable $e) {
        header('Content-Type: text/plain; charset=utf-8');
        http_response_code(404);
        echo 'Telechargement impossible: ' . $e->getMessage();
        exit;
    }
}
if ($action === 'download_sql' || $action === 'download_table') {
    $mode = strtolower(trim((string)($_GET['mode'] ?? 'full')));
    $withData = ($mode !== 'structure');

    try {
        if ($action === 'download_sql') {
            \AuditTrail::log('sql_export', [
                'module' => 'admin',
                'action' => 'sql_export_database',
                'entity_type' => 'database',
                'entity_label' => 'banamur_project_app',
                'details' => [
                    'scope' => 'database',
                    'mode' => $withData ? 'full' : 'structure',
                ],
            ], $pdo);
            $filename = 'backup_sql_' . date('Ymd_His') . ($withData ? '' : '_structure') . '.sql';
            beginSqlDownload($filename);
            sqlDumpDatabase($pdo, $withData);
            exit;
        }

        $table = (string)($_GET['table'] ?? '');
        $table = trim($table);
        if ($table === '') {
            http_response_code(400);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Paramètre 'table' manquant.";
            exit;
        }

        $validTables = $pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
        $tableSet = [];
        foreach ($validTables as $r) {
            $t = (string)($r[0] ?? '');
            if ($t !== '') {
                $tableSet[$t] = true;
            }
        }

        if (!isset($tableSet[$table])) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Table inconnue.";
            exit;
        }

        $safeTable = preg_replace('/[^a-zA-Z0-9_\-]+/', '_', $table);
        \AuditTrail::log('sql_export', [
            'module' => 'admin',
            'action' => 'sql_export_table',
            'entity_type' => 'table',
            'entity_id' => $table,
            'entity_label' => $table,
            'details' => [
                'scope' => 'table',
                'mode' => $withData ? 'full' : 'structure',
            ],
        ], $pdo);
        $filename = 'table_' . $safeTable . '_' . date('Ymd_His') . ($withData ? '' : '_structure') . '.sql';
        beginSqlDownload($filename);
        sqlDumpSingleTable($pdo, $table, $withData);
        exit;
    } catch (Throwable $e) {
        header('Content-Type: text/plain; charset=utf-8');
        http_response_code(500);
        echo "Export SQL impossible.\n";
        echo $e->getMessage();
        exit;
    }
}

$pageTitle = 'Base de données';
$activeMenu = '';
require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';

try {
    $dbName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    $mysqlVersion = (string)$pdo->query('SELECT VERSION()')->fetchColumn();

    $tablesCount = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type='BASE TABLE'")->fetchColumn();

    $sizeBytes = (int)$pdo->query(
        "SELECT COALESCE(SUM(data_length + index_length), 0)
         FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_type='BASE TABLE'"
    )->fetchColumn();

    $tablesRaw = $pdo->query(
        "SELECT table_name,
                table_rows,
                (data_length + index_length) AS size_bytes,
                engine,
                COALESCE(update_time, create_time) AS last_time
         FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_type='BASE TABLE'
         ORDER BY size_bytes DESC"
    )->fetchAll(PDO::FETCH_NUM);

    $allTables = [];
    foreach ($tablesRaw as $r) {
        $allTables[] = [
            'table_name' => (string)($r[0] ?? ''),
            'table_rows' => (int)($r[1] ?? 0),
            'size_bytes' => (int)($r[2] ?? 0),
            'engine' => (string)($r[3] ?? ''),
            'last_time' => (string)($r[4] ?? ''),
        ];
    }

    $topTables = array_slice($allTables, 0, 15);

    $allTablesByName = $allTables;
    usort($allTablesByName, function (array $a, array $b): int {
        return strcmp($a['table_name'], $b['table_name']);
    });
} catch (Throwable $e) {
    $dbName = '';
    $mysqlVersion = '';
    $tablesCount = 0;
    $sizeBytes = 0;
    $topTables = [];
    $allTablesByName = [];
    $flashError = 'Impossible de lire les informations de la base (droits insuffisants ou erreur SQL).';
}

try {
    $backupDir = $backupService->ensureBackupStorage();
    $backupFiles = $backupService->listBackups($backupDir);
    $restoreSnapshotDir = $backupService->ensureBackupStorage($backupService->getDefaultRestoreSnapshotDir());
    $restoreSnapshotFiles = $backupService->listRestoreSnapshots();
    $backupLogLines = $backupService->readLogLines($backupDir, 40);
} catch (Throwable $e) {
    $backupDir = '';
    $restoreSnapshotDir = '';
    $backupFiles = [];
    $restoreSnapshotFiles = [];
    $backupLogLines = [];
    if ($flashError === '') {
        $flashError = 'Impossible de lire le dossier des sauvegardes : ' . $e->getMessage();
    }
}

$formatBytes = function (int $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $n = (float)$bytes;
    while ($n >= 1024 && $i < count($units) - 1) {
        $n /= 1024;
        $i++;
    }
    return number_format($n, 2, ',', ' ') . ' ' . $units[$i];
};

$tablesPerPage = 50;
$tablesPage = (int)($_GET['tables_page'] ?? 1);
if ($tablesPage < 1) {
    $tablesPage = 1;
}
$tablesTotal = is_array($allTablesByName ?? null) ? count($allTablesByName) : 0;
$tablesTotalPages = max(1, (int)ceil($tablesTotal / $tablesPerPage));
if ($tablesPage > $tablesTotalPages) {
    $tablesPage = $tablesTotalPages;
}
$tablesOffset = ($tablesPage - 1) * $tablesPerPage;
$pagedTables = array_slice($allTablesByName ?? [], $tablesOffset, $tablesPerPage);

$buildUrl = function (array $params): string {
    $query = array_merge($_GET, $params);
    foreach ($query as $k => $v) {
        if ($v === null || $v === '') {
            unset($query[$k]);
        }
    }
    return 'db_tools.php' . (empty($query) ? '' : ('?' . http_build_query($query)));
};
?>

<style>
    .backup-progress-overlay {
        position: fixed;
        inset: 0;
        z-index: 9999;
        display: none;
        align-items: center;
        justify-content: center;
        background: rgba(15, 23, 42, 0.58);
        backdrop-filter: blur(4px);
        padding: 24px;
    }

    .backup-progress-overlay.is-visible {
        display: flex;
    }

    .backup-progress-card {
        width: min(520px, 100%);
        border: 1px solid rgba(226, 232, 240, 0.92);
        border-radius: 24px;
        background: #ffffff;
        box-shadow: 0 24px 80px rgba(15, 23, 42, 0.28);
        padding: 24px;
    }

    .backup-progress-track {
        position: relative;
        height: 12px;
        overflow: hidden;
        border-radius: 999px;
        background: #e2e8f0;
    }

    .backup-progress-bar {
        position: absolute;
        inset-block: 0;
        left: -35%;
        width: 35%;
        border-radius: inherit;
        background: linear-gradient(90deg, #059669, #2563eb);
        animation: backup-progress-slide 1.15s ease-in-out infinite;
    }

    @keyframes backup-progress-slide {
        0% {
            left: -35%;
        }
        55% {
            left: 52%;
        }
        100% {
            left: 105%;
        }
    }
</style>

<div class="min-h-screen bg-gradient-to-br from-indigo-50 via-white to-purple-50">
    <div id="backupProgressOverlay" class="backup-progress-overlay" aria-hidden="true">
        <div class="backup-progress-card">
            <div class="flex items-start gap-4">
                <div class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-700">
                    <i class="fas fa-database"></i>
                </div>
                <div class="min-w-0 flex-1">
                    <h2 class="text-lg font-bold text-gray-900">Sauvegarde en cours</h2>
                    <p class="mt-1 text-sm text-gray-600">La base est en cours d'export SQL gzip. Gardez cette page ouverte jusqu'a la confirmation.</p>
                </div>
            </div>
            <div class="backup-progress-track mt-5">
                <div class="backup-progress-bar"></div>
            </div>
            <div class="mt-3 flex items-center justify-between text-xs font-semibold text-gray-500">
                <span>Preparation, export des tables et compression</span>
                <span>Veuillez patienter...</span>
            </div>
        </div>
    </div>

    <div class="container mx-auto py-10 px-4">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-8">
            <div>
                <h1 class="text-3xl font-bold text-gray-800">Gestion de la base de données</h1>
                <p class="text-sm text-gray-600 mt-1">Statistiques et export SQL (admin).</p>
            </div>
            <div class="flex gap-3">
                <a href="dashboard_admin.php" class="inline-flex items-center px-4 py-2 bg-gray-800 text-white rounded-lg hover:bg-gray-900 transition text-sm">
                    <i class="fas fa-arrow-left mr-2"></i>Retour admin
                </a>
            </div>
        </div>

        <?php if ($flashError !== ''): ?>
            <div class="bg-red-50 border border-red-200 text-red-800 rounded-xl p-4 mb-6">
                <?= htmlspecialchars($flashError) ?>
            </div>
        <?php endif; ?>
        <?php if ($flashSuccess !== ''): ?>
            <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-xl p-4 mb-6">
                <?= htmlspecialchars($flashSuccess) ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <div class="bg-white rounded-2xl shadow-xl p-6 border border-gray-100">
                <p class="text-sm font-medium text-gray-600">Base</p>
                <p class="text-xl font-bold text-indigo-700 mt-1"><?= htmlspecialchars($dbName !== '' ? $dbName : '—') ?></p>
                <p class="text-xs text-gray-500 mt-2">MySQL <?= htmlspecialchars($mysqlVersion !== '' ? $mysqlVersion : '—') ?></p>
            </div>
            <div class="bg-white rounded-2xl shadow-xl p-6 border border-gray-100">
                <p class="text-sm font-medium text-gray-600">Tables</p>
                <p class="text-3xl font-bold text-indigo-700 mt-1"><?= number_format($tablesCount) ?></p>
                <p class="text-xs text-gray-500 mt-2">Tables (base tables)</p>
            </div>
            <div class="bg-white rounded-2xl shadow-xl p-6 border border-gray-100">
                <p class="text-sm font-medium text-gray-600">Taille estimée</p>
                <p class="text-3xl font-bold text-indigo-700 mt-1"><?= htmlspecialchars($formatBytes($sizeBytes)) ?></p>
                <p class="text-xs text-gray-500 mt-2">data + index (information_schema)</p>
            </div>
        </div>

        <div class="bg-white rounded-2xl shadow-xl p-6 border border-gray-100 mb-8">
            <div class="flex flex-col xl:flex-row xl:items-start xl:justify-between gap-5 mb-5">
                <div>
                    <h2 class="text-xl font-bold text-gray-800">Sauvegarde automatique et manuelle</h2>
                    <p class="text-sm text-gray-600 mt-1">
                        Sauvegardes complètes lundi, mercredi et vendredi à partir de 02h00 (Abidjan). Le cron vérifie chaque heure et réessaie après un échec. Conservation sans suppression automatique par défaut. Dossier actuel :
                        <span class="font-mono text-xs bg-gray-100 px-2 py-1 rounded"><?= htmlspecialchars($backupDir !== '' ? $backupDir : 'indisponible') ?></span>
                    </p>
                    <p class="text-xs text-gray-500 mt-2">
                        Commande recommandee cPanel :
                        <span class="font-mono bg-gray-100 px-2 py-1 rounded">0 * * * * sleep $((RANDOM \% 60)) && /usr/local/bin/php -q /home/fidestci/projet.fidest.ci/cron/backup_database.php --scheduled --retention_days=0 &gt;&gt; /home/fidestci/backup_database.log 2&gt;&amp;1</span>
                    </p>
                </div>
                <form method="post" class="js-backup-form grid gap-3 rounded-2xl border border-gray-200 bg-gray-50 p-4 min-w-full xl:min-w-[420px]">
                    <input type="hidden" name="action" value="run_backup">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <label class="text-sm font-semibold text-gray-700">
                            Mode
                            <select name="mode" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                                <option value="full">Structure + donnees</option>
                                <option value="structure">Structure seule</option>
                            </select>
                        </label>
                        <label class="text-sm font-semibold text-gray-700">
                            Rétention en jours (0 = sans suppression)
                            <input type="number" min="0" max="365" name="retention_days" value="0" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                        </label>
                    </div>
                    <button class="js-backup-submit inline-flex items-center justify-center px-4 py-2.5 bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 transition text-sm font-semibold disabled:cursor-wait disabled:opacity-70">
                        <i class="fas fa-shield-halved mr-2"></i>Lancer une sauvegarde maintenant
                    </button>
                </form>
            </div>

            <div class="grid grid-cols-1 xl:grid-cols-[1fr_420px] gap-5">
                <div class="overflow-x-auto rounded-2xl border border-gray-100">
                    <table class="min-w-full bg-white">
                        <thead>
                            <tr class="bg-gray-100 text-left text-sm">
                                <th class="p-3">Fichier</th>
                                <th class="p-3">Mode</th>
                                <th class="p-3">Taille</th>
                                <th class="p-3">Cree le</th>
                                <th class="p-3">Export</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($backupFiles)): ?>
                                <tr>
                                    <td class="p-4 text-center text-gray-500" colspan="5">Aucune sauvegarde trouvee.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($backupFiles as $file): ?>
                                    <tr class="border-t">
                                        <td class="p-3 font-mono text-xs text-gray-800"><?= htmlspecialchars((string)($file['name'] ?? '')) ?></td>
                                        <td class="p-3">
                                            <span class="inline-flex rounded-full px-2 py-1 text-xs font-semibold <?= (($file['mode'] ?? '') === 'structure') ? 'bg-amber-50 text-amber-700' : 'bg-emerald-50 text-emerald-700' ?>">
                                                <?= htmlspecialchars(((string)($file['mode'] ?? 'full')) === 'structure' ? 'Structure' : 'Complete') ?>
                                            </span>
                                        </td>
                                        <td class="p-3"><?= htmlspecialchars($formatBytes((int)($file['size'] ?? 0))) ?></td>
                                        <td class="p-3"><?= htmlspecialchars((string)($file['created_at'] ?? '')) ?></td>
                                        <td class="p-3">
                                            <a class="inline-flex items-center px-3 py-1.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition text-xs"
                                               href="db_tools.php?action=download_backup&file=<?= urlencode((string)($file['name'] ?? '')) ?>">
                                                <i class="fas fa-download mr-2"></i>Telecharger
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="rounded-2xl border border-gray-100 bg-gray-950 p-4 text-gray-100">
                    <h3 class="text-sm font-bold mb-3">Log backup</h3>
                    <div class="max-h-80 overflow-auto font-mono text-xs leading-5">
                        <?php if (empty($backupLogLines)): ?>
                            <div class="text-gray-400">Aucun log pour le moment.</div>
                        <?php else: ?>
                            <?php foreach (array_reverse($backupLogLines) as $line): ?>
                                <div><?= htmlspecialchars((string)$line) ?></div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-2xl shadow-xl p-6 border border-rose-100 mb-8">
            <div class="mb-5 flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4">
                <div>
                    <h2 class="text-xl font-bold text-gray-800">Restauration controlee</h2>
                    <p class="text-sm text-gray-600 mt-1">
                        Avant chaque restauration, la version actuelle de la base est automatiquement sauvegardee dans un listing separe.
                    </p>
                    <p class="text-xs text-gray-500 mt-2">
                        Dossier snapshots avant restauration :
                        <span class="font-mono bg-gray-100 px-2 py-1 rounded"><?= htmlspecialchars($restoreSnapshotDir !== '' ? $restoreSnapshotDir : 'indisponible') ?></span>
                    </p>
                </div>
                <div class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                    <span class="font-bold">Attention :</span> restaurer remplace les tables de la base actuelle par celles du fichier choisi.
                </div>
            </div>

            <div class="grid grid-cols-1 xl:grid-cols-2 gap-5">
                <div class="rounded-2xl border border-gray-100">
                    <div class="border-b border-gray-100 p-4">
                        <h3 class="font-bold text-gray-800">Choisir une sauvegarde a restaurer</h3>
                        <p class="text-xs text-gray-500 mt-1">Saisissez RESTAURER sur la ligne du fichier voulu.</p>
                    </div>
                    <div class="max-h-[520px] overflow-auto">
                        <table class="min-w-full bg-white">
                            <thead>
                                <tr class="bg-gray-100 text-left text-sm">
                                    <th class="p-3">Fichier</th>
                                    <th class="p-3">Taille</th>
                                    <th class="p-3">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($backupFiles)): ?>
                                    <tr>
                                        <td class="p-4 text-center text-gray-500" colspan="3">Aucune sauvegarde disponible pour restauration.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($backupFiles as $file): ?>
                                        <tr class="border-t align-top">
                                            <td class="p-3">
                                                <div class="font-mono text-xs text-gray-800"><?= htmlspecialchars((string)($file['name'] ?? '')) ?></div>
                                                <div class="mt-1 text-xs text-gray-500"><?= htmlspecialchars((string)($file['created_at'] ?? '')) ?> - <?= htmlspecialchars(((string)($file['mode'] ?? 'full')) === 'structure' ? 'Structure seule' : 'Complete') ?></div>
                                            </td>
                                            <td class="p-3 text-sm"><?= htmlspecialchars($formatBytes((int)($file['size'] ?? 0))) ?></td>
                                            <td class="p-3">
                                                <?php if (((string)($file['mode'] ?? 'full')) === 'structure'): ?>
                                                    <div class="rounded-lg bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-800">Structure seule non restaurable ici</div>
                                                <?php else: ?>
                                                    <form method="post" class="js-restore-form grid gap-2 min-w-[180px]">
                                                        <input type="hidden" name="action" value="restore_backup">
                                                        <input type="hidden" name="file" value="<?= htmlspecialchars((string)($file['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                                        <input type="text" name="confirm_restore" placeholder="RESTAURER" class="rounded-lg border border-gray-300 px-3 py-2 text-xs font-semibold uppercase" autocomplete="off">
                                                        <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-rose-600 px-3 py-2 text-xs font-semibold text-white transition hover:bg-rose-700 disabled:cursor-wait disabled:opacity-70">
                                                            <i class="fas fa-rotate-left mr-2"></i>Restaurer
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="rounded-2xl border border-gray-100">
                    <div class="border-b border-gray-100 p-4">
                        <h3 class="font-bold text-gray-800">Sauvegardes creees avant restauration</h3>
                        <p class="text-xs text-gray-500 mt-1">Ces fichiers conservent la version potentiellement cassee avant retour arriere.</p>
                    </div>
                    <div class="max-h-[520px] overflow-auto">
                        <table class="min-w-full bg-white">
                            <thead>
                                <tr class="bg-gray-100 text-left text-sm">
                                    <th class="p-3">Fichier</th>
                                    <th class="p-3">Taille</th>
                                    <th class="p-3">Export</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($restoreSnapshotFiles)): ?>
                                    <tr>
                                        <td class="p-4 text-center text-gray-500" colspan="3">Aucune sauvegarde avant restauration pour le moment.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($restoreSnapshotFiles as $file): ?>
                                        <tr class="border-t">
                                            <td class="p-3">
                                                <div class="font-mono text-xs text-gray-800"><?= htmlspecialchars((string)($file['name'] ?? '')) ?></div>
                                                <div class="mt-1 text-xs text-gray-500"><?= htmlspecialchars((string)($file['created_at'] ?? '')) ?></div>
                                            </td>
                                            <td class="p-3 text-sm"><?= htmlspecialchars($formatBytes((int)($file['size'] ?? 0))) ?></td>
                                            <td class="p-3">
                                                <a class="inline-flex items-center px-3 py-1.5 bg-gray-900 text-white rounded-lg hover:bg-black transition text-xs"
                                                   href="db_tools.php?action=download_backup&scope=snapshot&file=<?= urlencode((string)($file['name'] ?? '')) ?>">
                                                    <i class="fas fa-download mr-2"></i>Telecharger
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-2xl shadow-xl p-6 border border-gray-100 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-3">Export SQL</h2>
            <p class="text-sm text-gray-600 mb-4">Téléchargement direct (génération côté PHP). Pour les très grosses bases, cela peut prendre du temps.</p>

            <div class="flex flex-wrap gap-3">
                <a href="db_tools.php?action=download_sql&mode=full" class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition text-sm">
                    <i class="fas fa-download mr-2"></i>Télécharger SQL (structure + données)
                </a>
                <a href="db_tools.php?action=download_sql&mode=structure" class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-lg hover:shadow-sm transition text-sm">
                    <i class="fas fa-database mr-2"></i>Télécharger SQL (structure seule)
                </a>
            </div>
        </div>

        <div class="bg-white rounded-2xl shadow-xl p-6 border border-gray-100">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Plus grosses tables</h2>
            <div class="overflow-x-auto">
                <table class="min-w-full bg-white rounded-2xl shadow-sm">
                    <thead>
                        <tr class="bg-gray-100 text-left text-sm">
                            <th class="p-3">Table</th>
                            <th class="p-3">Lignes (estim.)</th>
                            <th class="p-3">Taille</th>
                            <th class="p-3">Engine</th>
                            <th class="p-3">Dernière maj</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($topTables)): ?>
                            <tr>
                                <td class="p-4 text-center text-gray-500" colspan="5">Aucune donnée.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($topTables as $t): ?>
                                <tr class="border-t">
                                    <td class="p-3 font-medium text-gray-800"><?= htmlspecialchars((string)($t['table_name'] ?? '')) ?></td>
                                    <td class="p-3"><?= number_format((int)($t['table_rows'] ?? 0)) ?></td>
                                    <td class="p-3"><?= htmlspecialchars($formatBytes((int)($t['size_bytes'] ?? 0))) ?></td>
                                    <td class="p-3"><?= htmlspecialchars(((string)($t['engine'] ?? '')) !== '' ? (string)$t['engine'] : '—') ?></td>
                                    <td class="p-3"><?= htmlspecialchars(((string)($t['last_time'] ?? '')) !== '' ? (string)$t['last_time'] : '—') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="bg-white rounded-2xl shadow-xl p-6 border border-gray-100 mt-8">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-4">
                <div>
                    <h2 class="text-xl font-bold text-gray-800">Toutes les tables</h2>
                    <p class="text-sm text-gray-600">Téléchargement table par table (structure ou structure + données). Pagination si nécessaire.</p>
                </div>
                <div class="text-sm text-gray-600">
                    Page <span class="font-semibold"><?= (int)$tablesPage ?></span> / <span class="font-semibold"><?= (int)$tablesTotalPages ?></span>
                    <span class="ml-2">(<?= number_format((int)$tablesTotal) ?> tables)</span>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full bg-white rounded-2xl shadow-sm">
                    <thead>
                        <tr class="bg-gray-100 text-left text-sm">
                            <th class="p-3">Table</th>
                            <th class="p-3">Lignes (estim.)</th>
                            <th class="p-3">Taille</th>
                            <th class="p-3">Engine</th>
                            <th class="p-3">Dernière maj</th>
                            <th class="p-3">Téléchargement</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($pagedTables)): ?>
                            <tr>
                                <td class="p-4 text-center text-gray-500" colspan="6">Aucune table.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($pagedTables as $t): ?>
                                <?php $tableName = (string)($t['table_name'] ?? ''); ?>
                                <tr class="border-t">
                                    <td class="p-3 font-medium text-gray-800"><?= htmlspecialchars($tableName) ?></td>
                                    <td class="p-3"><?= number_format((int)($t['table_rows'] ?? 0)) ?></td>
                                    <td class="p-3"><?= htmlspecialchars($formatBytes((int)($t['size_bytes'] ?? 0))) ?></td>
                                    <td class="p-3"><?= htmlspecialchars(((string)($t['engine'] ?? '')) !== '' ? (string)$t['engine'] : '—') ?></td>
                                    <td class="p-3"><?= htmlspecialchars(((string)($t['last_time'] ?? '')) !== '' ? (string)$t['last_time'] : '—') ?></td>
                                    <td class="p-3">
                                        <div class="flex flex-wrap gap-2">
                                            <a class="inline-flex items-center px-3 py-1.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition text-xs"
                                                href="db_tools.php?action=download_table&mode=full&table=<?= urlencode($tableName) ?>">
                                                <i class="fas fa-download mr-2"></i>Structure + données
                                            </a>
                                            <a class="inline-flex items-center px-3 py-1.5 bg-white border border-gray-300 rounded-lg hover:shadow-sm transition text-xs"
                                                href="db_tools.php?action=download_table&mode=structure&table=<?= urlencode($tableName) ?>">
                                                <i class="fas fa-database mr-2"></i>Structure seule
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="flex items-center justify-between mt-4">
                <div>
                    <?php if ($tablesPage > 1): ?>
                        <a class="inline-flex items-center px-3 py-2 bg-white border border-gray-300 rounded-lg hover:shadow-sm transition text-sm"
                            href="<?= htmlspecialchars($buildUrl(['tables_page' => $tablesPage - 1])) ?>">
                            <i class="fas fa-chevron-left mr-2"></i>Précédent
                        </a>
                    <?php endif; ?>
                </div>
                <div>
                    <?php if ($tablesPage < $tablesTotalPages): ?>
                        <a class="inline-flex items-center px-3 py-2 bg-white border border-gray-300 rounded-lg hover:shadow-sm transition text-sm"
                            href="<?= htmlspecialchars($buildUrl(['tables_page' => $tablesPage + 1])) ?>">
                            Suivant<i class="fas fa-chevron-right ml-2"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        var form = document.querySelector('.js-backup-form');
        var overlay = document.getElementById('backupProgressOverlay');
        var button = document.querySelector('.js-backup-submit');

        if (!form || !overlay || !button) {
            return;
        }

        form.addEventListener('submit', function () {
            overlay.classList.add('is-visible');
            overlay.setAttribute('aria-hidden', 'false');
            button.disabled = true;
            button.innerHTML = '<i class="fas fa-circle-notch fa-spin mr-2"></i>Sauvegarde en cours...';
        });

        document.querySelectorAll('.js-restore-form').forEach(function (restoreForm) {
            restoreForm.addEventListener('submit', function () {
                var restoreButton = restoreForm.querySelector('button[type="submit"]');
                overlay.classList.add('is-visible');
                overlay.setAttribute('aria-hidden', 'false');
                if (restoreButton) {
                    restoreButton.disabled = true;
                    restoreButton.innerHTML = '<i class="fas fa-circle-notch fa-spin mr-2"></i>Restauration...';
                }
            });
        });
    });
</script>

<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>

