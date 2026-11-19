<?php

class RhContractManager
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureSupportTables();
    }

    public function getSettings(): array
    {
        $defaults = $this->getDefaultSettings();
        $this->seedDefaultSettings($defaults);

        $stmt = $this->pdo->prepare('SELECT * FROM rh_contract_document_settings WHERE id = 1 LIMIT 1');
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return array_merge($defaults, [
            'employer_name' => $this->normalizeString($row['employer_name'] ?? $defaults['employer_name']),
            'employer_legal_form' => $this->normalizeString($row['employer_legal_form'] ?? $defaults['employer_legal_form']),
            'capital_label' => $this->normalizeString($row['capital_label'] ?? $defaults['capital_label']),
            'headquarters_text' => $this->normalizeString($row['headquarters_text'] ?? $defaults['headquarters_text']),
            'rccm_text' => $this->normalizeString($row['rccm_text'] ?? $defaults['rccm_text']),
            'represented_by_text' => $this->normalizeString($row['represented_by_text'] ?? $defaults['represented_by_text']),
            'signature_city' => $this->normalizeString($row['signature_city'] ?? $defaults['signature_city']),
            'dg_signatory_name' => $this->normalizeString($row['dg_signatory_name'] ?? $defaults['dg_signatory_name']),
            'dg_signatory_title' => $this->normalizeString($row['dg_signatory_title'] ?? $defaults['dg_signatory_title']),
            'rh_signatory_name' => $this->normalizeString($row['rh_signatory_name'] ?? $defaults['rh_signatory_name']),
            'rh_signatory_title' => $this->normalizeString($row['rh_signatory_title'] ?? $defaults['rh_signatory_title']),
            'footer_line_1' => $this->normalizeString($row['footer_line_1'] ?? $defaults['footer_line_1']),
            'footer_line_2' => $this->normalizeString($row['footer_line_2'] ?? $defaults['footer_line_2']),
            'employer_logo_path' => $this->normalizeString($row['employer_logo_path'] ?? $defaults['employer_logo_path']),
        ]);
    }

    public function saveSettings(array $settings, array $actor = []): bool
    {
        $defaults = $this->getDefaultSettings();
        $sql = '
            INSERT INTO rh_contract_document_settings (
                id,
                employer_name,
                employer_legal_form,
                capital_label,
                headquarters_text,
                rccm_text,
                represented_by_text,
                signature_city,
                dg_signatory_name,
                dg_signatory_title,
                rh_signatory_name,
                rh_signatory_title,
                footer_line_1,
                footer_line_2,
                employer_logo_path,
                updated_by_user_id,
                updated_by_name
            ) VALUES (
                1,
                :employer_name,
                :employer_legal_form,
                :capital_label,
                :headquarters_text,
                :rccm_text,
                :represented_by_text,
                :signature_city,
                :dg_signatory_name,
                :dg_signatory_title,
                :rh_signatory_name,
                :rh_signatory_title,
                :footer_line_1,
                :footer_line_2,
                :employer_logo_path,
                :updated_by_user_id,
                :updated_by_name
            )
            ON DUPLICATE KEY UPDATE
                employer_name = VALUES(employer_name),
                employer_legal_form = VALUES(employer_legal_form),
                capital_label = VALUES(capital_label),
                headquarters_text = VALUES(headquarters_text),
                rccm_text = VALUES(rccm_text),
                represented_by_text = VALUES(represented_by_text),
                signature_city = VALUES(signature_city),
                dg_signatory_name = VALUES(dg_signatory_name),
                dg_signatory_title = VALUES(dg_signatory_title),
                rh_signatory_name = VALUES(rh_signatory_name),
                rh_signatory_title = VALUES(rh_signatory_title),
                footer_line_1 = VALUES(footer_line_1),
                footer_line_2 = VALUES(footer_line_2),
                employer_logo_path = VALUES(employer_logo_path),
                updated_by_user_id = VALUES(updated_by_user_id),
                updated_by_name = VALUES(updated_by_name),
                updated_at = CURRENT_TIMESTAMP
        ';

        $stmt = $this->pdo->prepare($sql);
        foreach ($defaults as $key => $defaultValue) {
            $stmt->bindValue(':' . $key, $this->normalizeString($settings[$key] ?? $defaultValue));
        }
        $stmt->bindValue(':updated_by_user_id', $actor['user_id'] ?? null, isset($actor['user_id']) ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $updatedByName = $this->normalizeNullableString($actor['user_name'] ?? null);
        $stmt->bindValue(':updated_by_name', $updatedByName, $updatedByName !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        return $stmt->execute();
    }

    public function createContractRegistryEntry(int $personnelId, string $contractCode, string $documentState, string $signatureMode, string $period, array $actor = []): array
    {
        if ($personnelId <= 0) {
            throw new RuntimeException('Le collaborateur est obligatoire pour tracer le contrat.');
        }

        $documentState = $this->normalizeDocumentState($documentState);
        $signatureMode = $this->normalizeSignatureMode($signatureMode);
        $period = preg_match('/^\d{4}-\d{2}$/', $period) ? $period : date('Y-m');

        $temporaryNumber = 'TMP-' . date('YmdHis') . '-' . substr(hash('sha1', uniqid((string) $personnelId, true)), 0, 6);
        $insert = $this->pdo->prepare('
            INSERT INTO rh_contract_registry (
                personnel_id,
                contract_number,
                contract_code,
                document_state,
                signature_mode,
                payroll_period,
                generated_by_user_id,
                generated_by_name
            ) VALUES (
                :personnel_id,
                :contract_number,
                :contract_code,
                :document_state,
                :signature_mode,
                :payroll_period,
                :generated_by_user_id,
                :generated_by_name
            )
        ');
        $insert->bindValue(':personnel_id', $personnelId, PDO::PARAM_INT);
        $insert->bindValue(':contract_number', $temporaryNumber);
        $insert->bindValue(':contract_code', strtoupper(trim($contractCode)));
        $insert->bindValue(':document_state', $documentState);
        $insert->bindValue(':signature_mode', $signatureMode);
        $insert->bindValue(':payroll_period', $period);
        $insert->bindValue(':generated_by_user_id', $actor['user_id'] ?? null, isset($actor['user_id']) ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $generatedByName = $this->normalizeNullableString($actor['user_name'] ?? null);
        $insert->bindValue(':generated_by_name', $generatedByName, $generatedByName !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $insert->execute();

        $registryId = (int) $this->pdo->lastInsertId();
        $contractNumber = sprintf('CTRH-%s-%05d', date('Y'), $registryId);

        $update = $this->pdo->prepare('UPDATE rh_contract_registry SET contract_number = :contract_number WHERE id = :id');
        $update->bindValue(':contract_number', $contractNumber);
        $update->bindValue(':id', $registryId, PDO::PARAM_INT);
        $update->execute();

        return [
            'id' => $registryId,
            'contract_number' => $contractNumber,
            'document_state' => $documentState,
            'signature_mode' => $signatureMode,
            'payroll_period' => $period,
        ];
    }

    public function finalizeContractRegistryEntry(int $registryId, ?string $documentPath, array $metadata = []): bool
    {
        if ($registryId <= 0) {
            return false;
        }

        $stmt = $this->pdo->prepare('
            UPDATE rh_contract_registry
            SET document_path = :document_path,
                metadata_json = :metadata_json,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ');
        $stmt->bindValue(':document_path', $this->normalizeNullableString($documentPath), $documentPath !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $metadataJson = !empty($metadata) ? json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        $stmt->bindValue(':metadata_json', $metadataJson, $metadataJson !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':id', $registryId, PDO::PARAM_INT);
        return $stmt->execute();
    }

    private function getDefaultSettings(): array
    {
        return [
            'employer_name' => 'BANAMUR INDUSTRIES ET TECHNOLOGIES',
            'employer_legal_form' => 'SARL au capital de 100 000 000 FCFA',
            'capital_label' => 'Capital social : 100 000 000 FCFA',
            'headquarters_text' => 'Abidjan, Koumassi Bd. du Gabon prolonge',
            'rccm_text' => 'CI-ABJ-03-2022-B13-02828',
            'represented_by_text' => 'Representee pour les besoins du present contrat par la Direction Generale ou tout mandataire habilite.',
            'signature_city' => 'Abidjan',
            'dg_signatory_name' => 'A completer',
            'dg_signatory_title' => 'Directeur General',
            'rh_signatory_name' => 'A completer',
            'rh_signatory_title' => 'Responsable RH',
            'footer_line_1' => 'Abidjan, Koumassi Bd. du Gabon prolonge - RCCM CI-ABJ-03-2022-B13-02828 - Tel. +225 27 21 36 27 27',
            'footer_line_2' => 'Document RH genere depuis le module interne BANAMUR. Signature DG, RH et salarie requise pour prise d effet.',
            'employer_logo_path' => 'logo/logo_banamur.jpg',
        ];
    }

    private function seedDefaultSettings(array $defaults): void
    {
        $stmt = $this->pdo->prepare('
            INSERT IGNORE INTO rh_contract_document_settings (
                id,
                employer_name,
                employer_legal_form,
                capital_label,
                headquarters_text,
                rccm_text,
                represented_by_text,
                signature_city,
                dg_signatory_name,
                dg_signatory_title,
                rh_signatory_name,
                rh_signatory_title,
                footer_line_1,
                footer_line_2
                ,employer_logo_path
            ) VALUES (
                1,
                :employer_name,
                :employer_legal_form,
                :capital_label,
                :headquarters_text,
                :rccm_text,
                :represented_by_text,
                :signature_city,
                :dg_signatory_name,
                :dg_signatory_title,
                :rh_signatory_name,
                :rh_signatory_title,
                :footer_line_1,
                :footer_line_2
                ,:employer_logo_path
            )
        ');
        foreach ($defaults as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->execute();
    }

    private function normalizeDocumentState(string $value): string
    {
        $value = strtolower(trim($value));
        return $value === 'signed' ? 'signed' : 'draft';
    }

    private function normalizeSignatureMode(string $value): string
    {
        $value = strtolower(trim($value));
        return $value === 'standard' ? 'standard' : 'prepositioned';
    }

    private function normalizeString($value): string
    {
        return trim((string) $value);
    }

    private function normalizeNullableString($value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function ensureSupportTables(): void
    {
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS rh_contract_document_settings (
                id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
                employer_name VARCHAR(255) NOT NULL,
                employer_legal_form VARCHAR(255) NOT NULL,
                capital_label VARCHAR(255) NOT NULL,
                headquarters_text VARCHAR(255) NOT NULL,
                rccm_text VARCHAR(150) NOT NULL,
                represented_by_text VARCHAR(255) NOT NULL,
                signature_city VARCHAR(120) NOT NULL,
                dg_signatory_name VARCHAR(150) NOT NULL,
                dg_signatory_title VARCHAR(150) NOT NULL,
                rh_signatory_name VARCHAR(150) NOT NULL,
                rh_signatory_title VARCHAR(150) NOT NULL,
                footer_line_1 VARCHAR(255) NOT NULL,
                footer_line_2 VARCHAR(255) NOT NULL,
                employer_logo_path VARCHAR(500) NULL,
                updated_by_user_id INT DEFAULT NULL,
                updated_by_name VARCHAR(150) DEFAULT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
        try{$this->pdo->exec('ALTER TABLE rh_contract_document_settings ADD COLUMN employer_logo_path VARCHAR(500) NULL AFTER footer_line_2');}catch(Throwable $e){}

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS rh_contract_registry (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                personnel_id INT NOT NULL,
                contract_number VARCHAR(40) NOT NULL UNIQUE,
                contract_code VARCHAR(30) NOT NULL,
                document_state VARCHAR(20) NOT NULL DEFAULT "draft",
                signature_mode VARCHAR(20) NOT NULL DEFAULT "prepositioned",
                payroll_period VARCHAR(7) NOT NULL,
                document_path VARCHAR(255) DEFAULT NULL,
                metadata_json LONGTEXT DEFAULT NULL,
                generated_by_user_id INT DEFAULT NULL,
                generated_by_name VARCHAR(150) DEFAULT NULL,
                generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_rh_contract_registry_personnel (personnel_id),
                KEY idx_rh_contract_registry_period (payroll_period),
                KEY idx_rh_contract_registry_state (document_state)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }
}
