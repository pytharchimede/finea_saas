<?php

require_once __DIR__ . '/PositioningSettingsManager.php';

class EntityPositioningManager
{
    private PDO $pdo;
    private PositioningSettingsManager $settingsManager;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->settingsManager = new PositioningSettingsManager($pdo);
        $this->ensureTable();
    }

    public function ensureTable(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS app_entity_positions (
                entity_type VARCHAR(80) NOT NULL,
                entity_id INT NOT NULL,
                location_label VARCHAR(255) NULL,
                address_snapshot TEXT NULL,
                latitude DECIMAL(10,7) NULL,
                longitude DECIMAL(10,7) NULL,
                accuracy_meters DECIMAL(10,2) NULL,
                source VARCHAR(40) NOT NULL DEFAULT 'manual',
                geocoded_at DATETIME NULL,
                last_seen_at DATETIME NULL,
                updated_by INT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (entity_type, entity_id),
                KEY idx_entity_type_updated (entity_type, updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public function getSettings(string $entityType): array
    {
        return $this->settingsManager->get($entityType);
    }

    public function upsertPosition(string $entityType, int $entityId, array $payload, ?int $updatedBy = null): void
    {
        $this->ensureTable();
        if ($entityId <= 0) {
            throw new InvalidArgumentException('Identifiant entité invalide.');
        }

        $latitude = $this->normalizeCoordinate($payload['latitude'] ?? null);
        $longitude = $this->normalizeCoordinate($payload['longitude'] ?? null);
        $accuracy = $this->normalizeDecimal($payload['accuracy_meters'] ?? null);
        $source = trim((string) ($payload['source'] ?? 'manual')) ?: 'manual';
        $nowLastSeen = in_array($source, ['browser', 'gps', 'realtime'], true) ? date('Y-m-d H:i:s') : null;
        $geocodedAt = $source === 'geocoded' ? date('Y-m-d H:i:s') : null;

        $stmt = $this->pdo->prepare(
            'INSERT INTO app_entity_positions (
                entity_type, entity_id, location_label, address_snapshot, latitude, longitude,
                accuracy_meters, source, geocoded_at, last_seen_at, updated_by
            ) VALUES (
                :entity_type, :entity_id, :location_label, :address_snapshot, :latitude, :longitude,
                :accuracy_meters, :source, :geocoded_at, :last_seen_at, :updated_by
            ) ON DUPLICATE KEY UPDATE
                location_label = VALUES(location_label),
                address_snapshot = VALUES(address_snapshot),
                latitude = VALUES(latitude),
                longitude = VALUES(longitude),
                accuracy_meters = VALUES(accuracy_meters),
                source = VALUES(source),
                geocoded_at = COALESCE(VALUES(geocoded_at), geocoded_at),
                last_seen_at = COALESCE(VALUES(last_seen_at), last_seen_at),
                updated_by = VALUES(updated_by),
                updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([
            'entity_type' => trim($entityType),
            'entity_id' => $entityId,
            'location_label' => $this->nullableString($payload['location_label'] ?? null),
            'address_snapshot' => $this->nullableString($payload['address_snapshot'] ?? null),
            'latitude' => $latitude,
            'longitude' => $longitude,
            'accuracy_meters' => $accuracy,
            'source' => $source,
            'geocoded_at' => $geocodedAt,
            'last_seen_at' => $nowLastSeen,
            'updated_by' => $updatedBy,
        ]);
    }

    public function getPosition(string $entityType, int $entityId): ?array
    {
        if ($entityId <= 0) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT * FROM app_entity_positions WHERE entity_type = :entity_type AND entity_id = :entity_id LIMIT 1');
        $stmt->execute([
            'entity_type' => trim($entityType),
            'entity_id' => $entityId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        return $row ? $this->normalizePositionRow($row) : null;
    }

    public function listPositions(string $entityType, array $entityIds = []): array
    {
        $entityType = trim($entityType);
        if ($entityType === '') {
            return [];
        }

        $sql = 'SELECT * FROM app_entity_positions WHERE entity_type = :entity_type';
        $params = ['entity_type' => $entityType];
        if ($entityIds) {
            $entityIds = array_values(array_filter(array_map('intval', $entityIds), static fn(int $id): bool => $id > 0));
            if (!$entityIds) {
                return [];
            }
            $placeholders = implode(',', array_fill(0, count($entityIds), '?'));
            $sql = 'SELECT * FROM app_entity_positions WHERE entity_type = ? AND entity_id IN (' . $placeholders . ')';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(array_merge([$entityType], $entityIds));
        } else {
            $stmt = $this->pdo->prepare($sql . ' ORDER BY updated_at DESC');
            $stmt->execute($params);
        }
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $mapped = [];
        foreach ($rows as $row) {
            $normalized = $this->normalizePositionRow($row);
            $mapped[(int) $normalized['entity_id']] = $normalized;
        }
        return $mapped;
    }

    public function geocodeAddress(string $address): ?array
    {
        $address = trim($address);
        if ($address === '') {
            return null;
        }

        $url = 'https://nominatim.openstreetmap.org/search?format=jsonv2&addressdetails=1&limit=1&q=' . rawurlencode($address);
        $json = $this->fetchJson($url, 8);
        if ($json === false) {
            return null;
        }

        $items = json_decode($json, true);
        if (!is_array($items) || !$items || !is_array($items[0] ?? null)) {
            return null;
        }

        $item = $items[0];
        if (!isset($item['lat'], $item['lon']) || !is_numeric($item['lat']) || !is_numeric($item['lon'])) {
            return null;
        }

        return [
            'latitude' => (float) $item['lat'],
            'longitude' => (float) $item['lon'],
            'location_label' => trim((string) ($item['display_name'] ?? $address)),
            'source' => 'geocoded',
            'address_snapshot' => $address,
        ];
    }

    private function fetchJson(string $url, int $timeoutSeconds)
    {
        $headers = [
            'User-Agent: BanamurPositioning/1.0',
            'Accept: application/json',
        ];

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeoutSeconds);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSeconds);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ((!is_string($body) || $body === '' || $code < 200 || $code >= 300) && stripos($url, 'https://') === 0) {
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                $body = curl_exec($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            }

            curl_close($ch);
            return (is_string($body) && $body !== '' && $code >= 200 && $code < 300) ? $body : false;
        }

        $contexts = [
            stream_context_create([
                'http' => [
                    'timeout' => $timeoutSeconds,
                    'header' => implode("\r\n", $headers) . "\r\n",
                ],
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                ],
            ]),
        ];

        if (stripos($url, 'https://') === 0) {
            $contexts[] = stream_context_create([
                'http' => [
                    'timeout' => $timeoutSeconds,
                    'header' => implode("\r\n", $headers) . "\r\n",
                ],
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ],
            ]);
        }

        foreach ($contexts as $context) {
            $body = @file_get_contents($url, false, $context);
            if (is_string($body) && trim($body) !== '') {
                return $body;
            }
        }

        return false;
    }

    private function normalizePositionRow(array $row): array
    {
        return [
            'entity_type' => (string) ($row['entity_type'] ?? ''),
            'entity_id' => (int) ($row['entity_id'] ?? 0),
            'location_label' => (string) ($row['location_label'] ?? ''),
            'address_snapshot' => (string) ($row['address_snapshot'] ?? ''),
            'latitude' => isset($row['latitude']) ? (float) $row['latitude'] : null,
            'longitude' => isset($row['longitude']) ? (float) $row['longitude'] : null,
            'accuracy_meters' => isset($row['accuracy_meters']) ? (float) $row['accuracy_meters'] : null,
            'source' => (string) ($row['source'] ?? 'manual'),
            'geocoded_at' => (string) ($row['geocoded_at'] ?? ''),
            'last_seen_at' => (string) ($row['last_seen_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    private function nullableString($value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function normalizeCoordinate($value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }
        return round((float) $value, 7);
    }

    private function normalizeDecimal($value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }
        return round((float) $value, 2);
    }
}
