<?php

class BonReception
{
    private PDO $pdo;
    private bool $ready = true;
    private ?string $initError = null;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        if (!$pdo->inTransaction()) $this->ensureTables();
    }

    private function ensureTables(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS bon_reception (
            id INT NOT NULL AUTO_INCREMENT,
            num_fiche VARCHAR(60) NOT NULL,
            file_path VARCHAR(255) NOT NULL,
            original_name VARCHAR(255) NULL,
            mime_type VARCHAR(100) NULL,
            file_size INT NULL,
            uploaded_by_user_id INT NULL,
            uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_br_num_fiche (num_fiche),
            KEY idx_br_uploaded_at (uploaded_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

        try {
            $this->pdo->exec($sql);
        } catch (Throwable $e) {
            $this->ready = false;
            $this->initError = 'Initialisation SQL du bon de réception impossible (droits insuffisants ?)';
        }
    }

    public function isReady(): bool
    {
        return $this->ready;
    }

    public function getInitError(): ?string
    {
        return $this->initError;
    }

    public function getByNumFiche(string $numFiche): ?array
    {
        if (!$this->ready) {
            return null;
        }
        $numFiche = trim($numFiche);
        if ($numFiche === '') {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT * FROM bon_reception WHERE num_fiche = :num_fiche LIMIT 1');
        $stmt->execute(['num_fiche' => $numFiche]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function upsertByNumFiche(string $numFiche, array $data, ?int $userId = null): array
    {
        if (!$this->ready) {
            return ['ok' => false, 'error' => $this->initError ?? 'Module indisponible.'];
        }
        $numFiche = trim($numFiche);
        if ($numFiche === '') {
            return ['ok' => false, 'error' => 'Numéro de fiche manquant.'];
        }

        $filePath = trim((string)($data['file_path'] ?? ''));
        if ($filePath === '') {
            return ['ok' => false, 'error' => 'Chemin du fichier manquant.'];
        }

        $payload = [
            'num_fiche' => $numFiche,
            'file_path' => $filePath,
            'original_name' => trim((string)($data['original_name'] ?? '')) ?: null,
            'mime_type' => trim((string)($data['mime_type'] ?? '')) ?: null,
            'file_size' => isset($data['file_size']) ? (int)$data['file_size'] : null,
            'uploaded_by_user_id' => $userId,
        ];

        $existing = $this->getByNumFiche($numFiche);
        if ($existing) {
            $sql = 'UPDATE bon_reception SET
                file_path = :file_path,
                original_name = :original_name,
                mime_type = :mime_type,
                file_size = :file_size,
                uploaded_by_user_id = :uploaded_by_user_id
            WHERE num_fiche = :num_fiche';
            try {
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($payload);
            } catch (PDOException $e) {
                return ['ok' => false, 'error' => 'Erreur SQL lors de la mise à jour du bon de réception.'];
            }
            return ['ok' => true, 'id' => (int)($existing['id'] ?? 0)];
        }

        $sql = 'INSERT INTO bon_reception (
            num_fiche, file_path, original_name, mime_type, file_size, uploaded_by_user_id
        ) VALUES (
            :num_fiche, :file_path, :original_name, :mime_type, :file_size, :uploaded_by_user_id
        )';
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($payload);
        } catch (PDOException $e) {
            return ['ok' => false, 'error' => 'Erreur SQL lors de la création du bon de réception.'];
        }

        return ['ok' => true, 'id' => (int)$this->pdo->lastInsertId()];
    }
}
