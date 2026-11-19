<?php

declare(strict_types=1);

require_once __DIR__ . '/LogisticsBaseRepository.php';
require_once __DIR__.'/../services/SupplierReferenceService.php';

final class LogisticsSupplierRepository extends LogisticsBaseRepository
{
    private array $fields = ['master_supplier_id','supplier_type','tax_number','notes','name','contact_name','phone','email','address','rccm','ncc','conditions_text','status'];
    private string $table = 'logistics_suppliers';

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
        SupplierReferenceService::ensureLink($this->pdo,$this->table);
        $id = (int)($data['id'] ?? 0);
        $data=(new SupplierReferenceService($this->pdo))->bind($data,$id>0?($this->find($id)??[]):[]);
        $available=$this->pdo->query('SHOW COLUMNS FROM '.$this->table)->fetchAll(PDO::FETCH_COLUMN);
        $payload = [];
        foreach ($this->fields as $field) {
            if (in_array($field,$available,true) && array_key_exists($field, $data)) $payload[$field] = $data[$field];
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

