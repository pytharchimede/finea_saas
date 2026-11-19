<?php

class RhOrganizationChartLayoutManager
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureTable();
    }

    public function get(): array
    {
        $stmt = $this->pdo->query(
            'SELECT layout_json, revision, updated_by, updated_at
             FROM rh_organization_chart_layout
             WHERE id = 1
             LIMIT 1'
        );
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$row) {
            return $this->emptyLayout();
        }

        $decoded = json_decode((string) ($row['layout_json'] ?? ''), true);
        $layout = is_array($decoded) ? $this->sanitize($decoded) : $this->emptyLayout();
        $layout['revision'] = (int) ($row['revision'] ?? 0);
        $layout['updated_by'] = (int) ($row['updated_by'] ?? 0);
        $layout['updated_at'] = (string) ($row['updated_at'] ?? '');

        return $layout;
    }

    public function save(array $payload, int $userId): array
    {
        $layout = $this->sanitize($payload);
        $json = json_encode($layout, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Impossible de serialiser la disposition.');
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO rh_organization_chart_layout (id, layout_json, revision, updated_by)
             VALUES (1, :layout_json, 1, :updated_by)
             ON DUPLICATE KEY UPDATE
                layout_json = VALUES(layout_json),
                revision = revision + 1,
                updated_by = VALUES(updated_by),
                updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([
            'layout_json' => $json,
            'updated_by' => $userId > 0 ? $userId : null,
        ]);

        return $this->get();
    }

    public function reset(int $userId): array
    {
        return $this->save($this->emptyLayout(), $userId);
    }

    private function sanitize(array $payload): array
    {
        $nodes = [];
        foreach (($payload['nodes'] ?? []) as $nodeId => $values) {
            $nodeId = trim((string) $nodeId);
            if ($nodeId === '' || strlen($nodeId) > 190 || !is_array($values)) {
                continue;
            }

            $x = $this->boundedNumber($values['x'] ?? null, -50000, 50000);
            $y = $this->boundedNumber($values['y'] ?? null, -50000, 50000);
            $width = $this->boundedNumber($values['width'] ?? null, 150, 900);
            $height = $this->boundedNumber($values['height'] ?? null, 58, 420);
            if ($x === null || $y === null || $width === null || $height === null) {
                continue;
            }

            $nodes[$nodeId] = compact('x', 'y', 'width', 'height');
        }

        $edges = [];
        foreach (($payload['edges'] ?? []) as $nodeId => $values) {
            $nodeId = trim((string) $nodeId);
            if ($nodeId === '' || strlen($nodeId) > 190 || !is_array($values)) {
                continue;
            }
            $midY = $this->boundedNumber($values['midY'] ?? null, -50000, 50000);
            if ($midY !== null) {
                $edges[$nodeId] = ['midY' => $midY];
            }
        }

        return [
            'version' => 1,
            'nodes' => $nodes,
            'edges' => $edges,
            'revision' => max(0, (int) ($payload['revision'] ?? 0)),
            'updated_by' => 0,
            'updated_at' => '',
        ];
    }

    private function boundedNumber($value, float $minimum, float $maximum): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }
        return round(max($minimum, min($maximum, (float) $value)), 2);
    }

    private function emptyLayout(): array
    {
        return [
            'version' => 1,
            'nodes' => [],
            'edges' => [],
            'revision' => 0,
            'updated_by' => 0,
            'updated_at' => '',
        ];
    }

    private function ensureTable(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS rh_organization_chart_layout (
                id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
                layout_json LONGTEXT NOT NULL,
                revision INT UNSIGNED NOT NULL DEFAULT 0,
                updated_by INT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_rh_org_layout_updated_by (updated_by)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }
}
