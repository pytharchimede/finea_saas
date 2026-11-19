<?php

declare(strict_types=1);

require_once __DIR__ . '/LogisticsBaseRepository.php';

final class LogisticsAssetRepository extends LogisticsBaseRepository
{
    private array $fields = ['asset_code','asset_type','category','designation','brand','model','registration_number','serial_number','acquisition_date','current_site_id','current_driver_id','current_site','assigned_driver_id','status','condition_state','odometer_km','odometer','hour_meter','fuel_capacity','notes'];
    private string $table = 'logistics_assets';

    public function all(array $filters = []): array
    {
        $where = [];
        $params = [];
        foreach (['status','asset_type','availability_status','need_type','priority','supplier_type','category','maintenance_type','severity','batch_id'] as $key) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                $where[] = "$key = :$key";
                $params[$key] = $filters[$key];
            }
        }
        $sql = 'SELECT * FROM ' . $this->table . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id DESC LIMIT 500';
        return $this->fetchAll($sql, $params);
    }

    public function find(int $id): ?array
    {
        return $this->fetchOne('SELECT * FROM ' . $this->table . ' WHERE id = :id', ['id'=>$id]);
    }

    public function save(array $data): int
    {
        $id = (int)($data['id'] ?? 0);
        if (empty($data['asset_code'])) {
            $data['asset_code'] = $this->nextAssetCode((string)($data['asset_type'] ?? 'engin'), (string)($data['designation'] ?? ''), (string)($data['registration_number'] ?? ''));
        }
        if (isset($data['mileage']) && !isset($data['odometer_km'])) {
            $data['odometer_km'] = $data['mileage'];
        }
        $payload = [];
        foreach ($this->fields as $field) {
            if (array_key_exists($field, $data) && $this->hasColumn($field)) $payload[$field] = $data[$field];
        }
        if ($id > 0) {
            $sets = [];
            foreach ($payload as $field => $_) $sets[] = "$field = :$field";
            if (!$sets) return $id;
            $payload['id'] = $id;
            $stmt = $this->pdo->prepare('UPDATE ' . $this->table . ' SET ' . implode(', ', $sets) . ' WHERE id = :id');
            $stmt->execute($payload);
            return $id;
        }
        $cols = array_keys($payload);
        $marks = array_map(fn($c) => ':' . $c, $cols);
        $stmt = $this->pdo->prepare('INSERT INTO ' . $this->table . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', $marks) . ')');
        $stmt->execute($payload);
        return (int)$this->pdo->lastInsertId();
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM ' . $this->table . ' WHERE id = :id');
        $stmt->execute(['id'=>$id]);
    }

    private function hasColumn(string $column): bool
    {
        static $cache = [];
        if (!array_key_exists($column, $cache)) {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column');
            $stmt->execute(['table' => $this->table, 'column' => $column]);
            $cache[$column] = (int)$stmt->fetchColumn() > 0;
        }
        return $cache[$column];
    }

    private function nextAssetCode(string $assetType, string $designation, string $registration): string
    {
        $prefix = $this->assetCodePrefix($assetType, $designation, $registration);
        $stmt = $this->pdo->prepare('SELECT asset_code FROM ' . $this->table . ' WHERE asset_code LIKE :prefix');
        $stmt->execute(['prefix' => $prefix . '-%']);
        $max = 0;
        foreach (($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []) as $code) {
            if (preg_match('/^' . preg_quote($prefix, '/') . '-(\d+)$/', (string)$code, $matches)) {
                $max = max($max, (int)$matches[1]);
            }
        }
        return sprintf('%s-%03d', $prefix, $max + 1);
    }

    private function assetCodePrefix(string $assetType, string $designation, string $registration): string
    {
        $source = trim($designation) !== '' ? $designation : (trim($registration) !== '' ? $registration : $assetType);
        if (function_exists('iconv')) {
            $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $source);
            if ($converted !== false) $source = $converted;
        }
        $letters = preg_replace('/[^A-Z]/', '', strtoupper($source)) ?: '';
        $fallback = preg_replace('/[^A-Z]/', '', strtoupper($assetType)) ?: 'ENG';
        return substr(str_pad($letters . $fallback, 3, 'X'), 0, 3);
    }
}
