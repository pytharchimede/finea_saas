<?php

class AiSensitiveReplacementRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function createMany(?int $requestId, array $replacements): void
    {
        if (!$replacements) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO ai_sensitive_replacements (ai_request_id, placeholder, original_value)
             VALUES (:ai_request_id, :placeholder, :original_value)'
        );
        foreach ($replacements as $replacement) {
            $stmt->execute([
                'ai_request_id' => $requestId,
                'placeholder' => $replacement['placeholder'],
                'original_value' => $replacement['original_value'],
            ]);
        }
    }
}
