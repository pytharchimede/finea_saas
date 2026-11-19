<?php

class PositioningSettingsManager
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function ensureTable(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS app_positioning_settings (
                entity_type VARCHAR(80) NOT NULL PRIMARY KEY,
                api_enabled TINYINT(1) NOT NULL DEFAULT 1,
                realtime_enabled TINYINT(1) NOT NULL DEFAULT 1,
                geocoding_enabled TINYINT(1) NOT NULL DEFAULT 1,
                allow_manual_override TINYINT(1) NOT NULL DEFAULT 1,
                provider_key VARCHAR(80) NOT NULL DEFAULT 'nominatim',
                tile_provider VARCHAR(80) NOT NULL DEFAULT 'openstreetmap',
                tile_url TEXT NULL,
                tile_attribution TEXT NULL,
                poll_interval_seconds INT NOT NULL DEFAULT 30,
                map_center_lat DECIMAL(10,7) NOT NULL DEFAULT 5.3480000,
                map_center_lng DECIMAL(10,7) NOT NULL DEFAULT -4.0300000,
                map_zoom INT NOT NULL DEFAULT 7,
                updated_by INT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public function get(string $entityType): array
    {
        $this->ensureTable();
        $entityType = trim($entityType);
        if ($entityType === '') {
            throw new InvalidArgumentException('Type d\'entité invalide.');
        }

        $stmt = $this->pdo->prepare('SELECT * FROM app_positioning_settings WHERE entity_type = :entity_type LIMIT 1');
        $stmt->execute(['entity_type' => $entityType]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$row) {
            $defaults = $this->defaultsFor($entityType);
            $this->save($entityType, $defaults, null);
            return $this->get($entityType);
        }

        return $this->normalizeRow($row);
    }

    public function save(string $entityType, array $input, ?int $updatedBy): void
    {
        $this->ensureTable();
        $entityType = trim($entityType);
        if ($entityType === '') {
            throw new InvalidArgumentException('Type d\'entité invalide.');
        }

        $defaults = $this->defaultsFor($entityType);
        $settings = [
            'api_enabled' => !empty($input['api_enabled']) ? 1 : 0,
            'realtime_enabled' => !empty($input['realtime_enabled']) ? 1 : 0,
            'geocoding_enabled' => !empty($input['geocoding_enabled']) ? 1 : 0,
            'allow_manual_override' => !empty($input['allow_manual_override']) ? 1 : 0,
            'provider_key' => trim((string) ($input['provider_key'] ?? $defaults['provider_key'])),
            'tile_provider' => trim((string) ($input['tile_provider'] ?? $defaults['tile_provider'])),
            'tile_url' => $this->nullableString($input['tile_url'] ?? $defaults['tile_url']),
            'tile_attribution' => $this->nullableString($input['tile_attribution'] ?? $defaults['tile_attribution']),
            'poll_interval_seconds' => max(5, min(300, (int) ($input['poll_interval_seconds'] ?? $defaults['poll_interval_seconds']))),
            'map_center_lat' => $this->normalizeCoordinate($input['map_center_lat'] ?? $defaults['map_center_lat'], (float) $defaults['map_center_lat']),
            'map_center_lng' => $this->normalizeCoordinate($input['map_center_lng'] ?? $defaults['map_center_lng'], (float) $defaults['map_center_lng']),
            'map_zoom' => max(2, min(18, (int) ($input['map_zoom'] ?? $defaults['map_zoom']))),
        ];

        $stmt = $this->pdo->prepare(
            'INSERT INTO app_positioning_settings (
                entity_type, api_enabled, realtime_enabled, geocoding_enabled, allow_manual_override,
                provider_key, tile_provider, tile_url, tile_attribution, poll_interval_seconds,
                map_center_lat, map_center_lng, map_zoom, updated_by
            ) VALUES (
                :entity_type, :api_enabled, :realtime_enabled, :geocoding_enabled, :allow_manual_override,
                :provider_key, :tile_provider, :tile_url, :tile_attribution, :poll_interval_seconds,
                :map_center_lat, :map_center_lng, :map_zoom, :updated_by
            ) ON DUPLICATE KEY UPDATE
                api_enabled = VALUES(api_enabled),
                realtime_enabled = VALUES(realtime_enabled),
                geocoding_enabled = VALUES(geocoding_enabled),
                allow_manual_override = VALUES(allow_manual_override),
                provider_key = VALUES(provider_key),
                tile_provider = VALUES(tile_provider),
                tile_url = VALUES(tile_url),
                tile_attribution = VALUES(tile_attribution),
                poll_interval_seconds = VALUES(poll_interval_seconds),
                map_center_lat = VALUES(map_center_lat),
                map_center_lng = VALUES(map_center_lng),
                map_zoom = VALUES(map_zoom),
                updated_by = VALUES(updated_by),
                updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([
            'entity_type' => $entityType,
            'api_enabled' => $settings['api_enabled'],
            'realtime_enabled' => $settings['realtime_enabled'],
            'geocoding_enabled' => $settings['geocoding_enabled'],
            'allow_manual_override' => $settings['allow_manual_override'],
            'provider_key' => $settings['provider_key'],
            'tile_provider' => $settings['tile_provider'],
            'tile_url' => $settings['tile_url'],
            'tile_attribution' => $settings['tile_attribution'],
            'poll_interval_seconds' => $settings['poll_interval_seconds'],
            'map_center_lat' => $settings['map_center_lat'],
            'map_center_lng' => $settings['map_center_lng'],
            'map_zoom' => $settings['map_zoom'],
            'updated_by' => $updatedBy,
        ]);
    }

    public function defaultsFor(string $entityType): array
    {
        $base = [
            'entity_type' => $entityType,
            'api_enabled' => 1,
            'realtime_enabled' => 1,
            'geocoding_enabled' => 1,
            'allow_manual_override' => 1,
            'provider_key' => 'nominatim',
            'tile_provider' => 'openstreetmap',
            'tile_url' => 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
            'tile_attribution' => '&copy; OpenStreetMap contributors',
            'poll_interval_seconds' => 30,
            'map_center_lat' => 5.348,
            'map_center_lng' => -4.03,
            'map_zoom' => 7,
        ];

        if ($entityType === 'supplier') {
            $base['map_zoom'] = 6;
        }

        return $base;
    }

    private function normalizeRow(array $row): array
    {
        return [
            'entity_type' => (string) ($row['entity_type'] ?? ''),
            'api_enabled' => !empty($row['api_enabled']),
            'realtime_enabled' => !empty($row['realtime_enabled']),
            'geocoding_enabled' => !empty($row['geocoding_enabled']),
            'allow_manual_override' => !empty($row['allow_manual_override']),
            'provider_key' => (string) ($row['provider_key'] ?? 'nominatim'),
            'tile_provider' => (string) ($row['tile_provider'] ?? 'openstreetmap'),
            'tile_url' => (string) ($row['tile_url'] ?? 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png'),
            'tile_attribution' => (string) ($row['tile_attribution'] ?? '&copy; OpenStreetMap contributors'),
            'poll_interval_seconds' => max(5, (int) ($row['poll_interval_seconds'] ?? 30)),
            'map_center_lat' => (float) ($row['map_center_lat'] ?? 5.348),
            'map_center_lng' => (float) ($row['map_center_lng'] ?? -4.03),
            'map_zoom' => max(2, (int) ($row['map_zoom'] ?? 7)),
            'updated_by' => isset($row['updated_by']) ? (int) $row['updated_by'] : null,
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    private function nullableString($value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function normalizeCoordinate($value, float $fallback): float
    {
        return is_numeric($value) ? (float) $value : $fallback;
    }
}
