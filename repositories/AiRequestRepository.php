<?php

class AiRequestRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureSchema();
    }

    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ai_requests
                (user_id, module, task_type, request_type, tone, input_text, generated_text, final_text, provider, used_external_ai, status)
             VALUES
                (:user_id, :module, :task_type, :request_type, :tone, :input_text, :generated_text, :final_text, :provider, :used_external_ai, :status)'
        );
        $stmt->execute([
            'user_id' => $data['user_id'] ?? null,
            'module' => $data['module'],
            'task_type' => $data['task_type'],
            'request_type' => $data['request_type'] ?? null,
            'tone' => $data['tone'],
            'input_text' => $data['input_text'] ?? '',
            'generated_text' => $data['generated_text'] ?? '',
            'final_text' => $data['final_text'] ?? null,
            'provider' => $data['provider'] ?? 'internal',
            'used_external_ai' => !empty($data['used_external_ai']) ? 1 : 0,
            'status' => $data['status'] ?? 'generated',
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function updateFinalText(int $id, string $finalText, string $status = 'finalized'): void
    {
        $stmt = $this->pdo->prepare('UPDATE ai_requests SET final_text = :final_text, status = :status WHERE id = :id');
        $stmt->execute(['final_text' => $finalText, 'status' => $status, 'id' => $id]);
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ai_requests WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function recentByUser(int $userId, int $limit = 10): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, module, task_type, request_type, tone, generated_text, final_text, provider, used_external_ai, status, created_at
             FROM ai_requests
             WHERE user_id = :user_id
             ORDER BY created_at DESC
             LIMIT ' . max(1, min(30, $limit))
        );
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function recentAcceptedFieldExamples(string $field, int $limit = 4): array
    {
        $field = trim($field);
        if ($field === '') {
            return [];
        }

        $limit = max(1, min(8, $limit));
        $stmt = $this->pdo->prepare(
            "SELECT id, input_text, generated_text, final_text, provider, status, created_at
             FROM ai_requests
             WHERE module = 'FEB'
               AND task_type = 'generer_note'
               AND request_type = 'feb'
               AND input_text LIKE :field_marker
               AND status IN ('accepted', 'corrected', 'accepted_implicit', 'corrected_implicit')
               AND (NULLIF(TRIM(final_text), '') IS NOT NULL OR NULLIF(TRIM(generated_text), '') IS NOT NULL)
             ORDER BY created_at DESC
             LIMIT {$limit}"
        );
        $stmt->execute(['field_marker' => '%Champ cible : ' . $field . '%']);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function ensureSchema(): void
    {
        try {
            $columns = $this->pdo->query('SHOW COLUMNS FROM ai_requests')->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
            if (!in_array('request_type', $columns, true)) {
                $this->pdo->exec('ALTER TABLE ai_requests ADD COLUMN request_type VARCHAR(80) DEFAULT NULL AFTER task_type');
            }
        } catch (Throwable $e) {
            // Ne bloque pas l'assistant si l'hebergement refuse une evolution de schema.
        }
    }
}
