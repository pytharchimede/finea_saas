<?php

class AiUsageLogRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function log(array $data): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ai_usage_logs
                (user_id, action, provider, module, task_type, ip_address, user_agent)
             VALUES
                (:user_id, :action, :provider, :module, :task_type, :ip_address, :user_agent)'
        );
        $stmt->execute([
            'user_id' => $data['user_id'] ?? null,
            'action' => $data['action'],
            'provider' => $data['provider'] ?? 'internal',
            'module' => $data['module'] ?? null,
            'task_type' => $data['task_type'] ?? null,
            'ip_address' => substr((string)($data['ip_address'] ?? ''), 0, 45),
            'user_agent' => substr((string)($data['user_agent'] ?? ''), 0, 255),
        ]);
    }
}
