<?php

class PortalUserPreferences
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureTable();
    }

    public function getFavoriteModules(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $stmt = $this->pdo->prepare('SELECT favorite_modules_json FROM portal_user_preferences WHERE user_id = :user_id LIMIT 1');
        $stmt->execute(['user_id' => $userId]);
        $rawValue = $stmt->fetchColumn();

        if (!is_string($rawValue) || trim($rawValue) === '') {
            return [];
        }

        $decoded = json_decode($rawValue, true);
        if (!is_array($decoded)) {
            return [];
        }

        return $this->sanitizeFavoriteModules($decoded);
    }

    public function saveFavoriteModules(int $userId, array $moduleIds): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $favoriteModules = $this->sanitizeFavoriteModules($moduleIds);
        $payload = json_encode($favoriteModules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return false;
        }

        $sql = 'INSERT INTO portal_user_preferences (user_id, favorite_modules_json, updated_at)
                VALUES (:user_id, :favorite_modules_json, NOW())
                ON DUPLICATE KEY UPDATE favorite_modules_json = VALUES(favorite_modules_json), updated_at = NOW()';

        $stmt = $this->pdo->prepare($sql);

        return (bool) $stmt->execute([
            'user_id' => $userId,
            'favorite_modules_json' => $payload,
        ]);
    }

    public function getModuleLauncherEnabled(int $userId): bool
    {
        if ($userId <= 0) {
            return true;
        }

        $stmt = $this->pdo->prepare('SELECT module_launcher_enabled FROM portal_user_preferences WHERE user_id = :user_id LIMIT 1');
        $stmt->execute(['user_id' => $userId]);
        $rawValue = $stmt->fetchColumn();

        if ($rawValue === false || $rawValue === null || $rawValue === '') {
            return true;
        }

        return (int) $rawValue === 1;
    }

    public function saveModuleLauncherEnabled(int $userId, bool $enabled): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $sql = 'INSERT INTO portal_user_preferences (user_id, module_launcher_enabled, updated_at)
                VALUES (:user_id, :module_launcher_enabled, NOW())
                ON DUPLICATE KEY UPDATE module_launcher_enabled = VALUES(module_launcher_enabled), updated_at = NOW()';

        $stmt = $this->pdo->prepare($sql);

        return (bool) $stmt->execute([
            'user_id' => $userId,
            'module_launcher_enabled' => $enabled ? 1 : 0,
        ]);
    }

    public function getDisplay(int $userId): array
    {
        $q=$this->pdo->prepare('SELECT display_json FROM portal_user_preferences WHERE user_id=?');
        $q->execute([$userId]);
        return self::normalizeDisplay(json_decode((string)$q->fetchColumn(),true) ?: []);
    }
    public static function normalizeDisplay(array $data): array
    {
        $out=['layout'=>in_array($data['layout']??'', ['cards','compact','list'],true)?$data['layout']:'cards'];
        foreach(['weather','title','stats','summary','favorites','sidebar'] as $key)$out[$key]=!array_key_exists($key,$data)||$data[$key]===true;
        return $out;
    }
    public function saveDisplay(int $userId,array $data): void
    {
        if($userId<=0)throw new InvalidArgumentException('Utilisateur invalide');
        $q=$this->pdo->prepare('INSERT INTO portal_user_preferences(user_id,display_json) VALUES(?,?) ON DUPLICATE KEY UPDATE display_json=VALUES(display_json)');
        $q->execute([$userId,json_encode(self::normalizeDisplay($data),JSON_THROW_ON_ERROR)]);
    }

    private function sanitizeFavoriteModules(array $moduleIds): array
    {
        $clean = [];
        foreach ($moduleIds as $moduleId) {
            if (!is_string($moduleId) && !is_numeric($moduleId)) {
                continue;
            }

            $moduleId = strtolower(trim((string) $moduleId));
            if ($moduleId === '' || isset($clean[$moduleId])) {
                continue;
            }

            $clean[$moduleId] = $moduleId;
            if (count($clean) >= 5) {
                break;
            }
        }

        return array_values($clean);
    }

    private function ensureTable(): void
    {
        static $tableReady = false;

        if ($tableReady) {
            return;
        }

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS portal_user_preferences (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                favorite_modules_json LONGTEXT NULL,
                module_launcher_enabled TINYINT(1) NOT NULL DEFAULT 1,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_portal_user_preferences_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $columns = [];
        $stmt = $this->pdo->query('SHOW COLUMNS FROM portal_user_preferences');
        if ($stmt !== false) {
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $column) {
                $field = strtolower((string) ($column['Field'] ?? ''));
                if ($field !== '') {
                    $columns[$field] = true;
                }
            }
        }

        if (!isset($columns['module_launcher_enabled'])) {
            $this->pdo->exec('ALTER TABLE portal_user_preferences ADD COLUMN module_launcher_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER favorite_modules_json');
        }

        if (!isset($columns['display_json'])) $this->pdo->exec('ALTER TABLE portal_user_preferences ADD COLUMN IF NOT EXISTS display_json TEXT NULL');
        $tableReady = true;
    }
}
