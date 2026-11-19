<?php

require_once __DIR__ . '/../repositories/DatabaseBackupRepository.php';

class DatabaseBackupService
{
    private DatabaseBackupRepository $repository;
    private string $projectRoot;

    public function __construct(DatabaseBackupRepository $repository)
    {
        $this->repository = $repository;
        $this->projectRoot = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
    }

    public function getDatabaseName(): string
    {
        return $this->repository->getDatabaseName();
    }

    /** Latest due Monday/Wednesday/Friday slot, with catch-up after an outage. */
    public static function scheduledSlot(DateTimeImmutable $now): string
    {
        $now=$now->setTimezone(new DateTimeZone('Africa/Abidjan'));
        for ($days=0;$days<8;$days++) {
            $slot=$now->modify('-'.$days.' days')->setTime(2,0);
            if (in_array((int)$slot->format('N'),[1,3,5],true) && $slot<=$now) return $slot->format('Y-m-d');
        }
        throw new LogicException('Aucun créneau de sauvegarde calculé.');
    }

    public function getDefaultBackupDir(): string
    {
        return $this->projectRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'db_backups';
    }

    public function getDefaultRestoreSnapshotDir(): string
    {
        return $this->projectRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'db_backups_before_restore';
    }

    public function normalizeMode(string $mode): string
    {
        $mode = strtolower(trim($mode));
        return in_array($mode, ['full', 'structure'], true) ? $mode : 'full';
    }

    public function ensureBackupStorage(?string $dir = null): string
    {
        $dir = $this->normalizeDir($dir ?: $this->getDefaultBackupDir());
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            throw new RuntimeException('Impossible de creer le dossier de sauvegarde: ' . $dir);
        }
        if (!is_writable($dir)) {
            throw new RuntimeException('Le dossier de sauvegarde n\'est pas accessible en ecriture: ' . $dir);
        }

        $this->writeFileIfMissing($dir . DIRECTORY_SEPARATOR . 'index.html', "<!doctype html><title>Forbidden</title>");
        $this->writeFileIfMissing(
            $dir . DIRECTORY_SEPARATOR . '.htaccess',
            "# Interdit l'acces direct aux sauvegardes\n<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n"
        );

        return $dir;
    }

    public function createBackup(string $mode = 'full', ?string $dir = null, int $retentionDays = 0, string $source = 'manual', ?string $scheduledSlot = null): array
    {
        $mode = $this->normalizeMode($mode);
        $withData = ($mode === 'full');
        $retentionDays = max(0, min(365, $retentionDays));
        $backupDir = $this->ensureBackupStorage($dir);

        $lockPath = $backupDir . DIRECTORY_SEPARATOR . '.backup.lock';
        $lockFp = @fopen($lockPath, 'c');
        if ($lockFp === false) {
            throw new RuntimeException('Impossible d\'ouvrir le verrou de sauvegarde: ' . $lockPath);
        }

        if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
            fclose($lockFp);
            throw new RuntimeException('Une sauvegarde est deja en cours.');
        }

        $tmpPath = null;
        try {
            if ($scheduledSlot !== null) {
                if ($mode !== 'full' || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $scheduledSlot)) throw new InvalidArgumentException('Créneau de sauvegarde invalide.');
                $statePath=$backupDir.DIRECTORY_SEPARATOR.'.schedule-success.json';
                $state=is_file($statePath)?json_decode((string)file_get_contents($statePath),true):[];
                $previousFile=basename((string)($state['file']??''));
                if (($state['slot']??'') >= $scheduledSlot && $previousFile !== '' && is_file($backupDir.DIRECTORY_SEPARATOR.$previousFile) && filesize($backupDir.DIRECTORY_SEPARATOR.$previousFile)>0) return ['skipped'=>true,'slot'=>$scheduledSlot];
            }
            $dbName = $this->getDatabaseName();
            $stamp = date('Ymd_His');
            $safeDb = preg_replace('/[^a-zA-Z0-9_\-]+/', '_', $dbName) ?: 'database';
            $fileBase = 'backup_' . $safeDb . '_' . $stamp . '_' . $mode . '.sql.gz';
            $tmpPath = $backupDir . DIRECTORY_SEPARATOR . '.' . $fileBase . '.tmp';
            $finalPath = $backupDir . DIRECTORY_SEPARATOR . $fileBase;

            if (is_file($finalPath)) throw new RuntimeException('Une sauvegarde existe déjà pour cette seconde. Réessayez.');
            $this->dumpToGz($tmpPath, $withData);
            if (!@rename($tmpPath, $finalPath)) {
                @unlink($tmpPath);
                throw new RuntimeException('Impossible de finaliser le fichier de sauvegarde.');
            }

            if ($scheduledSlot !== null) {
                $stateTmp=$statePath.'.tmp';
                $stateJson=json_encode(['slot'=>$scheduledSlot,'file'=>$fileBase,'finished_at'=>gmdate('c')],JSON_THROW_ON_ERROR);
                if (file_put_contents($stateTmp,$stateJson) !== strlen($stateJson) || !rename($stateTmp,$statePath)) throw new RuntimeException('Impossible de mémoriser le succès du créneau.');
            }
            $removed = $this->rotateBackups($backupDir, $retentionDays);
            $info = $this->fileInfo($finalPath);
            $this->appendLog($backupDir, [
                'status' => 'OK',
                'source' => $source,
                'mode' => $mode,
                'file' => $fileBase,
                'size' => (int) ($info['size'] ?? 0),
                'removed' => $removed,
            ]);

            return $info + [
                'mode' => $mode,
                'removed' => $removed,
                'dir' => $backupDir,
            ];
        } catch (Throwable $e) {
            if ($tmpPath !== null && is_file($tmpPath)) @unlink($tmpPath);
            $this->appendLog($backupDir, [
                'status' => 'ERREUR',
                'source' => $source,
                'mode' => $mode,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            flock($lockFp, LOCK_UN);
            fclose($lockFp);
        }
    }

    public function listBackups(?string $dir = null): array
    {
        $backupDir = $this->ensureBackupStorage($dir);
        $files = [];
        foreach (glob($backupDir . DIRECTORY_SEPARATOR . '*.sql.gz') ?: [] as $path) {
            if (is_file($path)) {
                $files[] = $this->fileInfo($path);
            }
        }
        usort($files, static fn(array $a, array $b): int => ((int) ($b['mtime'] ?? 0)) <=> ((int) ($a['mtime'] ?? 0)));
        return $files;
    }

    public function listRestoreSnapshots(): array
    {
        return $this->listBackups($this->getDefaultRestoreSnapshotDir());
    }

    public function getBackupPathForDownload(string $filename, ?string $dir = null): string
    {
        $filename = basename($filename);
        if (!preg_match('/^backup_[a-zA-Z0-9_\-]+_\d{8}_\d{6}_(full|structure)\.sql\.gz$/', $filename)) {
            throw new RuntimeException('Fichier de sauvegarde invalide.');
        }

        $backupDir = $this->ensureBackupStorage($dir);
        $path = $backupDir . DIRECTORY_SEPARATOR . $filename;
        if (!is_file($path)) {
            throw new RuntimeException('Fichier de sauvegarde introuvable.');
        }

        return $path;
    }

    public function getRestoreSnapshotPathForDownload(string $filename): string
    {
        return $this->getBackupPathForDownload($filename, $this->getDefaultRestoreSnapshotDir());
    }

    public function restoreBackup(string $filename): array
    {
        if (substr(basename($filename), -17) === '_structure.sql.gz') {
            throw new RuntimeException('Restauration refusee: choisissez une sauvegarde complete avec donnees.');
        }

        $sourcePath = $this->getBackupPathForDownload($filename);
        $backupDir = $this->ensureBackupStorage();
        $lockPath = $backupDir . DIRECTORY_SEPARATOR . '.restore.lock';
        $lockFp = @fopen($lockPath, 'c');
        if ($lockFp === false) {
            throw new RuntimeException('Impossible d\'ouvrir le verrou de restauration: ' . $lockPath);
        }

        if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
            fclose($lockFp);
            throw new RuntimeException('Une restauration est deja en cours.');
        }

        try {
            $snapshot = $this->createBackup('full', $this->getDefaultRestoreSnapshotDir(), 365, 'before_restore');
            $statements = $this->importGzSql($sourcePath);

            $this->appendLog($backupDir, [
                'status' => 'RESTORE_OK',
                'source_file' => basename($sourcePath),
                'safety_backup' => (string) ($snapshot['name'] ?? ''),
                'statements' => $statements,
            ]);

            return [
                'restored_file' => basename($sourcePath),
                'snapshot' => $snapshot,
                'statements' => $statements,
            ];
        } catch (Throwable $e) {
            $this->appendLog($backupDir, [
                'status' => 'RESTORE_ERREUR',
                'source_file' => basename($sourcePath),
                'message' => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            flock($lockFp, LOCK_UN);
            fclose($lockFp);
        }
    }

    public function readLogLines(?string $dir = null, int $limit = 80): array
    {
        $backupDir = $this->ensureBackupStorage($dir);
        $logPath = $backupDir . DIRECTORY_SEPARATOR . 'backup.log';
        if (!is_file($logPath)) {
            return [];
        }

        $lines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        return array_slice($lines, -max(1, min(500, $limit)));
    }

    public function dumpToGz(string $gzPath, bool $withData = true): void
    {
        $gz = gzopen($gzPath, 'wb9');
        if ($gz === false) {
            throw new RuntimeException('Impossible d\'ouvrir le fichier gzip: ' . $gzPath);
        }

        $write = static function (string $value) use ($gz): void {
            if (gzwrite($gz, $value) !== strlen($value)) throw new RuntimeException('Écriture gzip incomplète (espace disque ou quota).');
        };
        try {
            $this->repository->beginExport();

            $write("-- Banamur - Export SQL\n");
            $write("-- Genere le: " . date('Y-m-d H:i:s') . "\n\n");
            $write("SET NAMES utf8mb4;\n");
            $write("SET time_zone = '+00:00';\n");
            $write("SET FOREIGN_KEY_CHECKS=0;\n\n");

            foreach ($this->repository->listBaseTables() as $table) {
                $write("\n-- ----------------------------\n");
                $write("-- Table: $table\n");
                $write("-- ----------------------------\n\n");

                $createSql = $this->repository->getCreateTableSql($table);
                $write('DROP TABLE IF EXISTS ' . $this->repository->escapeIdent($table) . ";\n");
                if ($createSql === '') {
                    throw new RuntimeException('Structure indisponible pour la table '.$table);
                }
                $write($createSql . ";\n\n");

                if (!$withData) {
                    continue;
                }

                $buffer = [];
                $columns = null;
                $rowsInTable = 0;
                $bufferBytes = 0;
                foreach ($this->repository->fetchTableRows($table) as $row) {
                    if ($columns === null) {
                        $columns = array_keys($row);
                    }

                    $values = [];
                    foreach ($row as $value) {
                        $values[] = $this->repository->quoteValue($value);
                    }

                    $tuple = '(' . implode(', ', $values) . ')';
                    $buffer[] = $tuple;
                    $bufferBytes += strlen($tuple);
                    $rowsInTable++;

                    if (count($buffer) >= 200 || $bufferBytes >= 1048576) {
                        $this->writeInsertBuffer($write, $table, $columns ?: [], $buffer);
                        $buffer = [];
                        $bufferBytes = 0;
                    }
                }

                if ($buffer) {
                    $this->writeInsertBuffer($write, $table, $columns ?: [], $buffer);
                }

                $write("-- $rowsInTable lignes exportees pour $table\n");
            }

            $write("\nSET FOREIGN_KEY_CHECKS=1;\n");
        } finally {
            try { $this->repository->endExport(); }
            finally { if (!gzclose($gz)) throw new RuntimeException('Finalisation gzip impossible.'); }
        }
    }

    private function writeInsertBuffer(callable $write, string $table, array $columns, array $buffer): void
    {
        $columnSql = $columns ? ('(' . implode(', ', array_map([$this->repository, 'escapeIdent'], $columns)) . ')') : '';
        $write('INSERT INTO ' . $this->repository->escapeIdent($table) . ' ' . $columnSql . " VALUES\n");
        $write(implode(",\n", $buffer) . ";\n\n");
    }

    private function importGzSql(string $path): int
    {
        if (!is_file($path)) {
            throw new RuntimeException('Fichier SQL gzip introuvable.');
        }

        set_time_limit(0);
        $gz = gzopen($path, 'rb');
        if ($gz === false) {
            throw new RuntimeException('Impossible d\'ouvrir le fichier gzip pour restauration.');
        }

        $statement = '';
        $statements = 0;
        $quote = null;
        $escape = false;
        $lineComment = false;
        $blockComment = false;
        $prev = '';

        try {
            while (!gzeof($gz)) {
                $chunk = gzread($gz, 8192);
                if ($chunk === false) {
                    throw new RuntimeException('Lecture du fichier gzip impossible.');
                }

                $length = strlen($chunk);
                for ($i = 0; $i < $length; $i++) {
                    $ch = $chunk[$i];
                    $next = $i + 1 < $length ? $chunk[$i + 1] : '';

                    if ($lineComment) {
                        if ($ch === "\n") {
                            $lineComment = false;
                            $statement .= $ch;
                        }
                        $prev = $ch;
                        continue;
                    }

                    if ($blockComment) {
                        if ($prev === '*' && $ch === '/') {
                            $blockComment = false;
                        }
                        $prev = $ch;
                        continue;
                    }

                    if ($quote === null && $ch === '-' && $next === '-' && ($prev === '' || ctype_space($prev))) {
                        $lineComment = true;
                        $i++;
                        $prev = '';
                        continue;
                    }

                    if ($quote === null && $ch === '/' && $next === '*') {
                        $blockComment = true;
                        $i++;
                        $prev = '';
                        continue;
                    }

                    $statement .= $ch;

                    if ($quote !== null) {
                        if ($escape) {
                            $escape = false;
                        } elseif ($ch === '\\') {
                            $escape = true;
                        } elseif ($ch === $quote) {
                            $quote = null;
                        }
                        $prev = $ch;
                        continue;
                    }

                    if ($ch === "'" || $ch === '"' || $ch === '`') {
                        $quote = $ch;
                        $prev = $ch;
                        continue;
                    }

                    if ($ch === ';') {
                        $sql = trim($statement);
                        $statement = '';
                        if ($sql !== '' && $sql !== ';') {
                            $this->repository->executeStatement($sql);
                            $statements++;
                        }
                    }

                    $prev = $ch;
                }
            }

            $sql = trim($statement);
            if ($sql !== '') {
                $this->repository->executeStatement($sql);
                $statements++;
            }
        } finally {
            gzclose($gz);
        }

        return $statements;
    }

    private function rotateBackups(string $backupDir, int $retentionDays): int
    {
        if ($retentionDays === 0) return 0; // No automatic deletion unless explicitly configured.
        $cutoff = time() - ($retentionDays * 86400);
        $removed = 0;
        foreach (glob($backupDir . DIRECTORY_SEPARATOR . '*.sql.gz') ?: [] as $path) {
            $mtime = @filemtime($path);
            if ($mtime !== false && $mtime < $cutoff && @unlink($path)) {
                $removed++;
            }
        }
        return $removed;
    }

    private function fileInfo(string $path): array
    {
        $name = basename($path);
        $mode = substr($name, -17) === '_structure.sql.gz' ? 'structure' : 'full';

        return [
            'name' => $name,
            'path' => $path,
            'size' => (int) (@filesize($path) ?: 0),
            'mtime' => (int) (@filemtime($path) ?: 0),
            'created_at' => @filemtime($path) ? date('Y-m-d H:i:s', (int) filemtime($path)) : '',
            'mode' => $mode,
        ];
    }

    private function appendLog(string $backupDir, array $data): void
    {
        $line = '[' . date('Y-m-d H:i:s') . '] ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        @file_put_contents($backupDir . DIRECTORY_SEPARATOR . 'backup.log', $line, FILE_APPEND);
    }

    private function normalizeDir(string $dir): string
    {
        return rtrim($dir, "/\\");
    }

    private function writeFileIfMissing(string $path, string $content): void
    {
        if (!is_file($path)) {
            @file_put_contents($path, $content);
        }
    }
}

