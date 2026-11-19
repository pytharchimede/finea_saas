<?php

class NotificationSoundSettingsManager
{
    private \PDO $pdo;

    private const STORAGE_DIRECTORY = 'uploads/system_notification_sounds';
    private const SETTING_KEY = 'default';
    private const ALLOWED_EXTENSIONS = ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'webm'];

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function ensureTable(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS system_notification_sound_settings (
                setting_key VARCHAR(40) NOT NULL PRIMARY KEY,
                is_enabled TINYINT(1) NOT NULL DEFAULT 0,
                sound_path VARCHAR(255) NULL,
                original_name VARCHAR(255) NULL,
                mime_type VARCHAR(120) NULL,
                updated_by INT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    public function get(): array
    {
        $this->ensureTable();

        $stmt = $this->pdo->prepare('SELECT * FROM system_notification_sound_settings WHERE setting_key = :setting_key LIMIT 1');
        $stmt->execute(['setting_key' => self::SETTING_KEY]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

        return [
            'is_enabled' => !empty($row['is_enabled']),
            'sound_path' => trim((string) ($row['sound_path'] ?? '')),
            'original_name' => trim((string) ($row['original_name'] ?? '')),
            'mime_type' => trim((string) ($row['mime_type'] ?? '')),
            'updated_by' => isset($row['updated_by']) ? (int) $row['updated_by'] : null,
            'updated_at' => trim((string) ($row['updated_at'] ?? '')),
        ];
    }

    public function save(bool $isEnabled, ?array $uploadedFile, bool $removeSound = false, ?int $updatedBy = null): array
    {
        $this->ensureTable();

        $current = $this->get();
        $soundPath = $current['sound_path'];
        $originalName = $current['original_name'];
        $mimeType = $current['mime_type'];

        if ($removeSound) {
            $this->deleteStoredFile($soundPath);
            $soundPath = '';
            $originalName = '';
            $mimeType = '';
            $isEnabled = false;
        }

        if (is_array($uploadedFile) && (($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE)) {
            $stored = $this->storeUploadedFile($uploadedFile);
            if ($soundPath !== '' && $soundPath !== $stored['sound_path']) {
                $this->deleteStoredFile($soundPath);
            }
            $soundPath = $stored['sound_path'];
            $originalName = $stored['original_name'];
            $mimeType = $stored['mime_type'];
        }

        if ($isEnabled && $soundPath === '') {
            throw new \RuntimeException('Veuillez televerser un fichier audio avant d activer le son personnalise.');
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO system_notification_sound_settings (setting_key, is_enabled, sound_path, original_name, mime_type, updated_by)
             VALUES (:setting_key, :is_enabled, :sound_path, :original_name, :mime_type, :updated_by)
             ON DUPLICATE KEY UPDATE
                is_enabled = VALUES(is_enabled),
                sound_path = VALUES(sound_path),
                original_name = VALUES(original_name),
                mime_type = VALUES(mime_type),
                updated_by = VALUES(updated_by),
                updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([
            'setting_key' => self::SETTING_KEY,
            'is_enabled' => $isEnabled ? 1 : 0,
            'sound_path' => $soundPath !== '' ? $soundPath : null,
            'original_name' => $originalName !== '' ? $originalName : null,
            'mime_type' => $mimeType !== '' ? $mimeType : null,
            'updated_by' => $updatedBy,
        ]);

        return $this->get();
    }

    public function getPublicUrl(string $layoutRootBase = ''): string
    {
        $settings = $this->get();
        if (empty($settings['is_enabled']) || trim((string) $settings['sound_path']) === '') {
            return '';
        }

        $relative = ltrim(str_replace('\\', '/', (string) $settings['sound_path']), '/');
        return $layoutRootBase . $relative;
    }

    private function storeUploadedFile(array $uploadedFile): array
    {
        $error = (int) ($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Le televersement du son a echoue.');
        }

        $tmpName = (string) ($uploadedFile['tmp_name'] ?? '');
        $originalName = trim((string) ($uploadedFile['name'] ?? 'notification-sound'));
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new \RuntimeException('Fichier audio invalide.');
        }

        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new \RuntimeException('Format audio non supporte. Utilisez mp3, wav, ogg, m4a, aac ou webm.');
        }

        $mimeType = '';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $detected = finfo_file($finfo, $tmpName);
                finfo_close($finfo);
                if (is_string($detected)) {
                    $mimeType = $detected;
                }
            }
        }

        $directory = $this->getStorageDirectoryAbsolute();
        if (!is_dir($directory) && !@mkdir($directory, 0755, true)) {
            throw new \RuntimeException('Impossible de creer le dossier de stockage du son.');
        }

        $safeBaseName = preg_replace('/[^A-Za-z0-9_-]+/', '-', pathinfo($originalName, PATHINFO_FILENAME)) ?: 'notification-sound';
        $fileName = date('Ymd_His') . '_' . $safeBaseName . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
        $absolutePath = $directory . DIRECTORY_SEPARATOR . $fileName;

        if (!@move_uploaded_file($tmpName, $absolutePath)) {
            throw new \RuntimeException('Impossible d enregistrer le fichier audio.');
        }

        return [
            'sound_path' => self::STORAGE_DIRECTORY . '/' . $fileName,
            'original_name' => $originalName,
            'mime_type' => $mimeType,
        ];
    }

    private function deleteStoredFile(string $relativePath): void
    {
        $relativePath = trim(str_replace('\\', '/', $relativePath));
        if ($relativePath === '') {
            return;
        }

        $absolutePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($relativePath, '/'));
        if (is_file($absolutePath)) {
            @unlink($absolutePath);
        }
    }

    private function getStorageDirectoryAbsolute(): string
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::STORAGE_DIRECTORY);
    }
}
