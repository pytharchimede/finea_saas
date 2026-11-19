<?php

declare(strict_types=1);

require_once __DIR__ . '/LogisticsBaseRepository.php';

final class LogisticsTripBatchRepository extends LogisticsBaseRepository
{
    private array $fields = ['batch_code','site_id','material','planned_quantity','delivered_quantity','unit','loading_point','delivery_point','start_date','expected_end_date','responsible_user_id','status','notes'];
    private string $table = 'logistics_trip_batches';

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
        $payload = [];
        foreach ($this->fields as $field) {
            if (array_key_exists($field, $data)) $payload[$field] = $data[$field];
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
}
