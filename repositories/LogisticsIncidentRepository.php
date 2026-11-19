<?php

declare(strict_types=1);

require_once __DIR__ . '/LogisticsBaseRepository.php';

final class LogisticsIncidentRepository extends LogisticsBaseRepository
{
    private array $fields = ['asset_id','driver_id','site_id','incident_type','severity','description','corrective_action','status','incident_at','resolved_at'];
    private string $table = 'logistics_incidents';

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
