<?php

class AiTemplateRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureSchema();
    }

    public function findActiveTemplates(): array
    {
        $stmt = $this->pdo->query(
            'SELECT * FROM ai_templates
             WHERE is_active = 1 AND is_validated = 1
             ORDER BY module ASC, request_type ASC, task_type ASC, tone ASC, title ASC'
        );
        return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ai_templates
                (module, task_type, request_type, tone, title, template_text, variables_json, keywords_json, is_validated, is_active, created_by)
             VALUES
                (:module, :task_type, :request_type, :tone, :title, :template_text, :variables_json, :keywords_json, :is_validated, :is_active, :created_by)'
        );
        $stmt->execute([
            'module' => $data['module'],
            'task_type' => $data['task_type'],
            'request_type' => $data['request_type'] ?? null,
            'tone' => $data['tone'],
            'title' => $data['title'],
            'template_text' => $data['template_text'],
            'variables_json' => json_encode($data['variables'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'keywords_json' => json_encode($data['keywords'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'is_validated' => !empty($data['is_validated']) ? 1 : 0,
            'is_active' => array_key_exists('is_active', $data) ? (!empty($data['is_active']) ? 1 : 0) : 1,
            'created_by' => $data['created_by'] ?? null,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function findValidatedCorrections(string $module, string $taskType, int $limit = 3): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.input_text, f.corrected_text, f.rating
             FROM ai_feedback f
             INNER JOIN ai_requests r ON r.id = f.ai_request_id
             WHERE f.accepted = 1
               AND f.corrected_text IS NOT NULL
               AND f.corrected_text <> ""
               AND r.module = :module
               AND r.task_type = :task_type
             ORDER BY COALESCE(f.rating, 0) DESC, f.created_at DESC
             LIMIT ' . max(1, min(10, $limit))
        );
        $stmt->execute(['module' => $module, 'task_type' => $taskType]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function ensureSchema(): void
    {
        try {
            $columns = $this->pdo->query('SHOW COLUMNS FROM ai_templates')->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
            if (!in_array('request_type', $columns, true)) {
                $this->pdo->exec('ALTER TABLE ai_templates ADD COLUMN request_type VARCHAR(80) DEFAULT NULL AFTER task_type');
            }
        } catch (Throwable $e) {
            // La migration principale garde le module utilisable; l'absence de cette colonne ne doit pas bloquer la page.
        }
    }
}
