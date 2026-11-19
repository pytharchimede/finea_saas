<?php

use Symfony\Component\Serializer\SerializerInterface;
use Webauthn\CredentialRecord;

final class PasskeyRepository
{
    public function __construct(private PDO $pdo, private SerializerInterface $serializer)
    {
        $this->ensureSchema();
    }

    /**
     * Migration idempotente exécutée avec la connexion PDO habituelle de FINEA.
     * Le déploiement ne demande donc aucune connexion MySQL interactive.
     */
    public function ensureSchema(): void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS user_passkeys (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT NOT NULL,
            user_handle VARBINARY(64) NOT NULL,
            credential_id VARBINARY(1024) NOT NULL,
            credential_id_hash BINARY(32) NOT NULL,
            credential_record LONGTEXT NOT NULL,
            public_key BLOB NOT NULL,
            sign_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
            transports JSON NULL,
            aaguid CHAR(36) NULL,
            backed_up TINYINT(1) NULL,
            device_name VARCHAR(100) NOT NULL DEFAULT 'Passkey',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_used_at DATETIME NULL,
            revoked_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_user_passkeys_credential_hash (credential_id_hash),
            KEY idx_user_passkeys_user_active (user_id, revoked_at),
            KEY idx_user_passkeys_user_handle (user_handle)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function getOrCreateUserHandle(int $userId): string
    {
        $stmt = $this->pdo->prepare('SELECT user_handle FROM user_passkeys WHERE user_id=:user_id LIMIT 1');
        $stmt->execute(['user_id' => $userId]);
        $handle = $stmt->fetchColumn();
        if (is_string($handle) && $handle !== '') {
            return $handle;
        }
        return random_bytes(32);
    }

    /** @return CredentialRecord[] */
    public function activeRecordsForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT credential_record FROM user_passkeys WHERE user_id=:user_id AND revoked_at IS NULL');
        $stmt->execute(['user_id' => $userId]);
        return array_map(fn (string $json) => $this->deserialize($json), $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function findActiveByCredentialId(string $credentialId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM user_passkeys WHERE credential_id_hash=:credential_id_hash AND revoked_at IS NULL LIMIT 1');
        $stmt->execute(['credential_id_hash' => hash('sha256', $credentialId, true)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row && hash_equals((string) $row['credential_id'], $credentialId) ? $row : null;
    }

    public function save(int $userId, CredentialRecord $record, string $deviceName): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO user_passkeys
            (user_id,user_handle,credential_id,credential_id_hash,credential_record,public_key,sign_count,transports,aaguid,backed_up,device_name,created_at)
            VALUES (:user_id,:user_handle,:credential_id,:credential_id_hash,:credential_record,:public_key,:sign_count,:transports,:aaguid,:backed_up,:device_name,NOW())');
        $stmt->execute([
            'user_id' => $userId,
            'user_handle' => $record->userHandle,
            'credential_id' => $record->publicKeyCredentialId,
            'credential_id_hash' => hash('sha256', $record->publicKeyCredentialId, true),
            'credential_record' => $this->serializer->serialize($record, 'json'),
            'public_key' => $record->credentialPublicKey,
            'sign_count' => $record->counter,
            'transports' => json_encode($record->transports, JSON_UNESCAPED_SLASHES),
            'aaguid' => $record->aaguid->toRfc4122(),
            'backed_up' => $record->backupStatus === null ? null : (int) $record->backupStatus,
            'device_name' => mb_substr(trim($deviceName) ?: 'Passkey', 0, 100),
        ]);
    }

    public function updateAfterUse(int $id, CredentialRecord $record): void
    {
        $stmt = $this->pdo->prepare('UPDATE user_passkeys SET credential_record=:record,sign_count=:counter,backed_up=:backed_up,last_used_at=NOW() WHERE id=:id AND revoked_at IS NULL');
        $stmt->execute([
            'record' => $this->serializer->serialize($record, 'json'),
            'counter' => $record->counter,
            'backed_up' => $record->backupStatus === null ? null : (int) $record->backupStatus,
            'id' => $id,
        ]);
    }

    public function listForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT id,device_name,credential_id_hash,transports,aaguid,backed_up,created_at,last_used_at FROM user_passkeys WHERE user_id=:user_id AND revoked_at IS NULL ORDER BY created_at DESC');
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function revoke(int $id, int $userId): bool
    {
        $stmt = $this->pdo->prepare('UPDATE user_passkeys SET revoked_at=NOW() WHERE id=:id AND user_id=:user_id AND revoked_at IS NULL');
        $stmt->execute(['id' => $id, 'user_id' => $userId]);
        return $stmt->rowCount() === 1;
    }

    public function deserialize(string $json): CredentialRecord
    {
        return $this->serializer->deserialize($json, CredentialRecord::class, 'json');
    }
}
