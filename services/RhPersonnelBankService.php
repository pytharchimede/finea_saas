<?php

final class RhPersonnelBankService
{
    public function __construct(private PDO $pdo)
    {
        $this->ensureSchema();
    }

    private function ensureSchema(): void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS rh_personnel_bank_accounts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            personnel_id INT NOT NULL,
            bank_name VARCHAR(190) NOT NULL,
            account_holder VARCHAR(190) NULL,
            account_number VARCHAR(190) NULL,
            iban VARCHAR(190) NULL,
            swift_bic VARCHAR(80) NULL,
            payment_reference VARCHAR(190) NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_by INT NULL,
            updated_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_rh_personnel_bank (personnel_id),
            KEY idx_rh_personnel_bank_active (active, personnel_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function find(int $personnelId): array
    {
        if ($personnelId < 1) return [];
        $q = $this->pdo->prepare('SELECT * FROM rh_personnel_bank_accounts WHERE personnel_id=? LIMIT 1');
        $q->execute([$personnelId]);
        return $q->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    public function mapForPersonnelIds(array $personnelIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $personnelIds), static fn(int $id): bool => $id > 0)));
        if (!$ids) return [];
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $q = $this->pdo->prepare("SELECT * FROM rh_personnel_bank_accounts WHERE active=1 AND personnel_id IN ($marks)");
        $q->execute($ids);
        $out = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) $out[(int)$row['personnel_id']] = $row;
        return $out;
    }

    public function save(array $data, int $actorId = 0): int
    {
        $personnelId = (int)($data['personnel_id'] ?? 0);
        if ($personnelId < 1) throw new RuntimeException('Salarié obligatoire.');
        $this->assertEmployee($personnelId);
        $bankName = trim((string)($data['bank_name'] ?? ''));
        $accountNumber = trim((string)($data['account_number'] ?? ''));
        $iban = trim((string)($data['iban'] ?? ''));
        if ($bankName === '') throw new RuntimeException('La banque est obligatoire.');
        if ($accountNumber === '' && $iban === '') throw new RuntimeException('Renseignez le numéro de compte ou l’IBAN.');
        $payload = [
            $personnelId,
            $bankName,
            trim((string)($data['account_holder'] ?? '')) ?: null,
            $accountNumber ?: null,
            $iban ?: null,
            trim((string)($data['swift_bic'] ?? '')) ?: null,
            trim((string)($data['payment_reference'] ?? '')) ?: null,
            !empty($data['active']) || !array_key_exists('active', $data) ? 1 : 0,
            $actorId ?: null,
            $actorId ?: null,
        ];
        $q = $this->pdo->prepare("INSERT INTO rh_personnel_bank_accounts
            (personnel_id,bank_name,account_holder,account_number,iban,swift_bic,payment_reference,active,created_by,updated_by)
            VALUES(?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE bank_name=VALUES(bank_name),account_holder=VALUES(account_holder),account_number=VALUES(account_number),iban=VALUES(iban),swift_bic=VALUES(swift_bic),payment_reference=VALUES(payment_reference),active=VALUES(active),updated_by=VALUES(updated_by)");
        $q->execute($payload);
        return (int)($this->find($personnelId)['id'] ?? 0);
    }

    public function isComplete(array $bank): bool
    {
        return !empty($bank['active']) && trim((string)($bank['bank_name'] ?? '')) !== ''
            && (trim((string)($bank['account_number'] ?? '')) !== '' || trim((string)($bank['iban'] ?? '')) !== '');
    }

    private function assertEmployee(int $personnelId): void
    {
        $q = $this->pdo->prepare("SELECT COUNT(*) FROM personnel WHERE id_personnel_soignant=?
            AND COALESCE(statut_pers_soignant_code,0) NOT IN (2,3)
            AND COALESCE(NULLIF(personnel_category,''),'interne') <> 'prestataire'");
        $q->execute([$personnelId]);
        if ((int)$q->fetchColumn() !== 1) throw new RuntimeException('Les prestataires et consultants ne sont pas concernés par les coordonnées bancaires de paie salarié.');
    }
}
