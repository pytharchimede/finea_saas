<?php

class AiFeedbackRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ai_feedback
                (ai_request_id, user_id, rating, accepted, corrected_text, comment)
             VALUES
                (:ai_request_id, :user_id, :rating, :accepted, :corrected_text, :comment)'
        );
        $stmt->execute([
            'ai_request_id' => $data['ai_request_id'],
            'user_id' => $data['user_id'] ?? null,
            'rating' => $data['rating'] ?? null,
            'accepted' => !empty($data['accepted']) ? 1 : 0,
            'corrected_text' => $data['corrected_text'] ?? null,
            'comment' => $data['comment'] ?? null,
        ]);
        return (int)$this->pdo->lastInsertId();
    }
}
