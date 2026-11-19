<?php

require_once __DIR__ . '/../services/NotificationEmailChannelService.php';

class RhMatriculeManager
{
    private PDO $pdo;
    private NotificationEmailChannelService $emailChannel;

    private const COMPANY_PREFIX = 'BNM';
    private const DIGITS = 4;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->emailChannel = new NotificationEmailChannelService();
        $this->ensureSupportTables();
    }

    public function buildPreview(): array
    {
        $employees = $this->fetchPersonnelRows();
        $byFamily = [];

        foreach ($employees as $employee) {
            $family = $this->resolveContractFamily(
                isset($employee['status_code']) ? (int) $employee['status_code'] : 0,
                (string) ($employee['status_label'] ?? '')
            );
            $prefix = $this->normalizeStatusAbbreviation(
                (string) ($employee['status_abbreviation'] ?? ''),
                (string) ($employee['status_label'] ?? ''),
                isset($employee['status_code']) ? (int) $employee['status_code'] : 0
            );
            $employee['contract_family'] = $family;
            $employee['contract_prefix'] = $prefix;
            $employee['integration_date'] = $this->resolveIntegrationDate($employee);
            $byFamily[$prefix][] = $employee;
        }

        $rows = [];
        $changed = 0;

        foreach ($byFamily as $prefixKey => $familyRows) {
            usort($familyRows, function (array $a, array $b): int {
                $dateCompare = strcmp((string) $a['integration_date'], (string) $b['integration_date']);
                if ($dateCompare !== 0) {
                    return $dateCompare;
                }

                $createdCompare = strcmp((string) ($a['created_at'] ?? ''), (string) ($b['created_at'] ?? ''));
                if ($createdCompare !== 0) {
                    return $createdCompare;
                }

                return ((int) $a['id']) <=> ((int) $b['id']);
            });

            $sequence = 1;
            foreach ($familyRows as $employee) {
                $newMatricule = $this->formatMatricule((string) $employee['contract_prefix'], $sequence);
                $oldMatricule = trim((string) ($employee['matricule'] ?? ''));
                $isChanged = strtoupper($oldMatricule) !== strtoupper($newMatricule);
                if ($isChanged) {
                    $changed++;
                }

                $rows[] = [
                    'id' => (int) $employee['id'],
                    'full_name' => (string) ($employee['full_name'] ?? ''),
                    'email' => (string) ($employee['email'] ?? ''),
                    'service_label' => (string) ($employee['service_label'] ?? ''),
                    'fonction_label' => (string) ($employee['fonction_label'] ?? ''),
                    'status_code' => isset($employee['status_code']) ? (int) $employee['status_code'] : null,
                    'status_label' => (string) ($employee['status_label'] ?? ''),
                    'contract_family' => (string) ($employee['contract_family'] ?? $prefixKey),
                    'contract_prefix' => (string) $employee['contract_prefix'],
                    'integration_date' => (string) $employee['integration_date'],
                    'sequence' => $sequence,
                    'old_matricule' => $oldMatricule,
                    'new_matricule' => $newMatricule,
                    'changed' => $isChanged,
                ];
                $sequence++;
            }
        }

        usort($rows, function (array $a, array $b): int {
            $familyCompare = strcmp((string) $a['contract_prefix'], (string) $b['contract_prefix']);
            if ($familyCompare !== 0) {
                return $familyCompare;
            }

            return ((int) $a['sequence']) <=> ((int) $b['sequence']);
        });

        return [
            'rows' => $rows,
            'total' => count($rows),
            'changed' => $changed,
            'unchanged' => count($rows) - $changed,
            'generated_at' => date('Y-m-d H:i:s'),
            'rules' => [
                'format' => self::COMPANY_PREFIX . '-{TYPE}-' . str_repeat('0', self::DIGITS - 1) . '1',
                'order' => 'Type de contrat, date de prise de service, date de recrutement, date de creation, ID personnel',
            ],
        ];
    }

    public function applyRemaniement(array $actor = []): array
    {
        $preview = $this->buildPreview();
        $changedRows = array_values(array_filter($preview['rows'], static fn(array $row): bool => !empty($row['changed'])));

        if ($changedRows === []) {
            return [
                'ok' => true,
                'updated' => 0,
                'email_sent' => 0,
                'email_failed' => 0,
                'preview' => $preview,
            ];
        }

        $runId = null;
        $updated = 0;

        $this->pdo->beginTransaction();
        try {
            $stmtRun = $this->pdo->prepare('
                INSERT INTO rh_matricule_remaniement_runs (
                    total_personnel,
                    total_changed,
                    created_by_user_id,
                    created_by_name,
                    preview_json
                ) VALUES (
                    :total_personnel,
                    :total_changed,
                    :created_by_user_id,
                    :created_by_name,
                    :preview_json
                )
            ');
            $stmtRun->execute([
                ':total_personnel' => (int) $preview['total'],
                ':total_changed' => (int) $preview['changed'],
                ':created_by_user_id' => $actor['user_id'] ?? null,
                ':created_by_name' => $this->actorName($actor),
                ':preview_json' => json_encode($preview, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            $runId = (int) $this->pdo->lastInsertId();

            $stmtUpdate = $this->pdo->prepare('
                UPDATE personnel
                SET matricule_personnel_soignant = :matricule
                WHERE id_personnel_soignant = :id
            ');
            $stmtLog = $this->pdo->prepare('
                INSERT INTO personnel_rh_change_log (
                    personnel_id,
                    change_group,
                    field_key,
                    field_label,
                    old_value,
                    new_value,
                    changed_by_user_id,
                    changed_by_name
                ) VALUES (
                    :personnel_id,
                    :change_group,
                    :field_key,
                    :field_label,
                    :old_value,
                    :new_value,
                    :changed_by_user_id,
                    :changed_by_name
                )
            ');

            $changeGroup = 'matricule-remaniement-' . $runId;
            foreach ($changedRows as $row) {
                $stmtUpdate->execute([
                    ':matricule' => $row['new_matricule'],
                    ':id' => (int) $row['id'],
                ]);

                $stmtLog->execute([
                    ':personnel_id' => (int) $row['id'],
                    ':change_group' => $changeGroup,
                    ':field_key' => 'matricule',
                    ':field_label' => 'Matricule',
                    ':old_value' => $row['old_matricule'] !== '' ? $row['old_matricule'] : null,
                    ':new_value' => $row['new_matricule'],
                    ':changed_by_user_id' => $actor['user_id'] ?? null,
                    ':changed_by_name' => $this->actorName($actor),
                ]);
                $updated++;
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $emailResult = $this->sendRemaniementEmails($preview, $runId);

        return [
            'ok' => true,
            'run_id' => $runId,
            'updated' => $updated,
            'email_sent' => $emailResult['sent'],
            'email_failed' => $emailResult['failed'],
            'preview' => $preview,
        ];
    }

    public function generateNextMatricule(int $statusCode, ?int $excludePersonnelId = null, string $statusLabel = ''): string
    {
        $meta = $this->lookupStatusMeta($statusCode);
        $prefix = $this->normalizeStatusAbbreviation(
            (string) ($meta['abbreviation'] ?? ''),
            $statusLabel !== '' ? $statusLabel : (string) ($meta['label'] ?? ''),
            $statusCode
        );
        $pattern = self::COMPANY_PREFIX . '-' . $prefix . '-%';

        $sql = '
            SELECT matricule_personnel_soignant
            FROM personnel
            WHERE matricule_personnel_soignant LIKE :pattern
        ';
        $params = [':pattern' => $pattern];
        if ($excludePersonnelId !== null && $excludePersonnelId > 0) {
            $sql .= ' AND id_personnel_soignant <> :exclude_id';
            $params[':exclude_id'] = $excludePersonnelId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $max = 0;
        while (($value = $stmt->fetchColumn()) !== false) {
            if (preg_match('/^' . preg_quote(self::COMPANY_PREFIX . '-' . $prefix . '-', '/') . '(\d+)$/i', (string) $value, $matches)) {
                $max = max($max, (int) $matches[1]);
            }
        }

        return $this->formatMatricule($prefix, $max + 1);
    }

    public function shouldChangeForStatus(?int $oldStatusCode, ?int $newStatusCode, string $oldLabel = '', string $newLabel = ''): bool
    {
        if ($newStatusCode === null || $newStatusCode <= 0) {
            return false;
        }

        if (trim($oldLabel) === '' && $oldStatusCode !== null) {
            $oldMeta = $this->lookupStatusMeta((int) $oldStatusCode);
            $oldLabel = (string) ($oldMeta['label'] ?? '');
        } else {
            $oldMeta = $oldStatusCode !== null ? $this->lookupStatusMeta((int) $oldStatusCode) : [];
        }
        if (trim($newLabel) === '') {
            $newMeta = $this->lookupStatusMeta((int) $newStatusCode);
            $newLabel = (string) ($newMeta['label'] ?? '');
        } else {
            $newMeta = $this->lookupStatusMeta((int) $newStatusCode);
        }

        $oldPrefix = $this->normalizeStatusAbbreviation((string) ($oldMeta['abbreviation'] ?? ''), $oldLabel, (int) ($oldStatusCode ?? 0));
        $newPrefix = $this->normalizeStatusAbbreviation((string) ($newMeta['abbreviation'] ?? ''), $newLabel, (int) $newStatusCode);

        return $oldPrefix !== $newPrefix;
    }

    public function resolveContractFamily(int $statusCode, string $statusLabel = ''): string
    {
        $normalized = $this->normalizeLabel($statusLabel);

        if ($normalized !== '') {
            if (str_contains($normalized, 'CDI')) {
                return 'CDI';
            }
            if (str_contains($normalized, 'CDD')) {
                return 'CDD';
            }
            if (str_contains($normalized, 'STAGE') || str_contains($normalized, 'STAGIAIRE')) {
                return 'STAGE';
            }
            if (str_contains($normalized, 'PRESTATION') || str_contains($normalized, 'PRESTATAIRE')) {
                return 'PRESTATION';
            }
            if (str_contains($normalized, 'CONSULTANT') || str_contains($normalized, 'CONSULTANCE')) {
                return 'CONSULTANT';
            }
            if (str_contains($normalized, 'MAJESTIC')) {
                return 'MAJESTIC';
            }
            if (str_contains($normalized, 'VACATAIRE') || str_contains($normalized, 'VACATION')) {
                return 'VACATAIRE';
            }
        }

        return match ($statusCode) {
            1 => 'CDD',
            2 => 'PRESTATION',
            3 => 'CONSULTANT',
            4 => 'STAGE',
            5 => 'CDI',
            6 => 'MAJESTIC',
            default => 'AUTRE',
        };
    }

    public function prefixForFamily(string $family): string
    {
        return match (strtoupper(trim($family))) {
            'CDI' => 'CDI',
            'CDD' => 'CDD',
            'STAGE' => 'STG',
            'PRESTATION' => 'PREST',
            'CONSULTANT' => 'CONS',
            'VACATAIRE' => 'VAC',
            'MAJESTIC' => 'MAJ',
            default => 'AUT',
        };
    }

    private function fetchPersonnelRows(): array
    {
        $stmt = $this->pdo->query('
            SELECT
                p.id_personnel_soignant AS id,
                p.matricule_personnel_soignant AS matricule,
                p.nom_personnel_soignant AS full_name,
                p.email_personnel_soignant AS email,
                p.statut_pers_soignant_code AS status_code,
                COALESCE(rs.label, CASE
                    WHEN p.statut_pers_soignant_code = 1 THEN "CDD"
                    WHEN p.statut_pers_soignant_code = 2 THEN "Contrat de prestation"
                    WHEN p.statut_pers_soignant_code = 3 THEN "Consultant"
                    WHEN p.statut_pers_soignant_code = 4 THEN "Stage"
                    WHEN p.statut_pers_soignant_code = 5 THEN "CDI"
                    WHEN p.statut_pers_soignant_code = 6 THEN "Majestic"
                    ELSE CONCAT("Statut ", p.statut_pers_soignant_code)
                END) AS status_label,
                rs.matricule_abbreviation AS status_abbreviation,
                p.date_prise_service AS start_date,
                p.date_recrutement AS hire_date,
                p.date_creat_pers_soign AS created_at,
                COALESCE(NULLIF(TRIM(s.lib_service), ""), "Service non renseigne") AS service_label,
                COALESCE(NULLIF(TRIM(f.lib_fonction), ""), "Fonction non renseignee") AS fonction_label
            FROM personnel p
            LEFT JOIN rh_statuts rs ON rs.code = p.statut_pers_soignant_code
            LEFT JOIN service s ON s.id_service = p.service_id
            LEFT JOIN fonction f ON f.id_fonction = p.fonction_id
            ORDER BY p.id_personnel_soignant ASC
        ');

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function resolveIntegrationDate(array $employee): string
    {
        foreach (['start_date', 'hire_date', 'created_at'] as $key) {
            $value = trim((string) ($employee[$key] ?? ''));
            if ($value === '' || str_starts_with($value, '0000-00-00')) {
                continue;
            }
            return substr($value, 0, 10);
        }

        return '9999-12-31';
    }

    private function formatMatricule(string $contractPrefix, int $sequence): string
    {
        return self::COMPANY_PREFIX . '-' . strtoupper($contractPrefix) . '-' . str_pad((string) max(1, $sequence), self::DIGITS, '0', STR_PAD_LEFT);
    }

    private function normalizeLabel(string $label): string
    {
        $label = strtoupper(trim($label));
        if ($label === '') {
            return '';
        }

        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label);
            if (is_string($converted) && $converted !== '') {
                $label = $converted;
            }
        }

        return preg_replace('/[^A-Z0-9]+/', ' ', $label) ?: '';
    }

    private function normalizeStatusAbbreviation(string $abbreviation, string $label, int $statusCode): string
    {
        $value = strtoupper(trim($abbreviation));
        if ($value === '') {
            $value = $this->defaultAbbreviationForStatus($statusCode, $label);
        }

        $value = preg_replace('/[^A-Z0-9]/', '', $value) ?: 'AUT';
        return substr($value, 0, 12);
    }

    private function defaultAbbreviationForStatus(int $statusCode, string $label): string
    {
        $byCode = [
            1 => 'CDD',
            2 => 'PREST',
            3 => 'CONS',
            4 => 'STGE',
            5 => 'CDI',
            6 => 'MAJ',
        ];
        if (isset($byCode[$statusCode])) {
            return $byCode[$statusCode];
        }

        $family = $this->resolveContractFamily($statusCode, $label);
        return $this->prefixForFamily($family);
    }

    private function lookupStatusMeta(int $statusCode): array
    {
        if ($statusCode <= 0) {
            return [];
        }

        try {
            $stmt = $this->pdo->prepare('SELECT label, matricule_abbreviation AS abbreviation FROM rh_statuts WHERE code = :code LIMIT 1');
            $stmt->execute([':code' => $statusCode]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : [];
        } catch (Throwable) {
            return [];
        }
    }

    private function sendRemaniementEmails(array $preview, ?int $runId): array
    {
        $recipients = $this->getRhRecipients();
        if ($recipients === []) {
            return ['sent' => 0, 'failed' => 0];
        }

        $csv = $this->buildListingCsv($preview['rows']);
        $subject = 'Nouveau listing des matricules RH';
        $body = '<p style="margin:0 0 14px; line-height:1.7;">Le remaniement des matricules du personnel vient d etre valide.</p>'
            . '<p style="margin:0 0 14px; line-height:1.7;"><strong>' . (int) $preview['changed'] . '</strong> matricule(s) mis a jour sur <strong>' . (int) $preview['total'] . '</strong> collaborateur(s).</p>'
            . '<p style="margin:0; line-height:1.7;">Le listing complet est joint a cet email au format CSV.</p>';
        $html = $this->emailChannel->buildFineaEmail($subject, $body, 'rh/liste_personnel_rh.php', [
            'subtitle' => 'Mise a jour automatique du referentiel RH.',
            'repere_title' => 'Remaniement #' . (int) $runId,
            'repere_text' => 'Format applique : ' . (string) ($preview['rules']['format'] ?? ''),
            'button_label' => 'Ouvrir la liste du personnel',
        ]);

        $sent = 0;
        $failed = 0;
        foreach ($recipients as $recipient) {
            $ok = $this->emailChannel->send(
                (string) $recipient['email'],
                (string) $recipient['name'],
                $subject,
                $html,
                null,
                [
                    'attachments' => [[
                        'name' => 'listing_matricules_' . date('Ymd_His') . '.csv',
                        'content' => $csv,
                        'type' => 'text/csv',
                    ]],
                ]
            );

            if ($ok) {
                $sent++;
            } else {
                $failed++;
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    private function getRhRecipients(): array
    {
        $stmt = $this->pdo->query('
            SELECT mail_pro AS email, TRIM(CONCAT(COALESCE(prenom, ""), " ", COALESCE(nom, ""))) AS name
            FROM user_devis_banamur
            WHERE active = 1
                AND acces_rh = 1
                AND mail_pro IS NOT NULL
                AND TRIM(mail_pro) <> ""
            ORDER BY prenom ASC, nom ASC
        ');

        $recipients = [];
        $seen = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $email = trim((string) ($row['email'] ?? ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $emailKey = strtolower($email);
            if (isset($seen[$emailKey])) {
                continue;
            }
            $seen[$emailKey] = true;

            $recipients[] = [
                'email' => $email,
                'name' => trim((string) ($row['name'] ?? '')) ?: $email,
            ];
        }

        $personnelStmt = $this->pdo->query('
            SELECT p.email_personnel_soignant AS email, p.nom_personnel_soignant AS name
            FROM personnel p
            INNER JOIN service s ON s.id_service = p.service_id
            WHERE p.email_personnel_soignant IS NOT NULL
                AND TRIM(p.email_personnel_soignant) <> ""
                AND (
                    UPPER(s.lib_service) LIKE "%RESSOURCES HUMAINES%"
                    OR UPPER(s.lib_service) = "RH"
                    OR UPPER(s.lib_service) LIKE "% RH%"
                    OR UPPER(s.lib_service) LIKE "RH %"
                )
            ORDER BY p.nom_personnel_soignant ASC
        ');

        foreach ($personnelStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $email = trim((string) ($row['email'] ?? ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $emailKey = strtolower($email);
            if (isset($seen[$emailKey])) {
                continue;
            }
            $seen[$emailKey] = true;

            $recipients[] = [
                'email' => $email,
                'name' => trim((string) ($row['name'] ?? '')) ?: $email,
            ];
        }

        return $recipients;
    }

    private function buildListingCsv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['Matricule', 'Ancien matricule', 'Nom', 'Type contrat', 'Date integration', 'Service', 'Fonction', 'Email'], ';');
        foreach ($rows as $row) {
            fputcsv($handle, [
                (string) ($row['new_matricule'] ?? ''),
                (string) ($row['old_matricule'] ?? ''),
                (string) ($row['full_name'] ?? ''),
                (string) ($row['status_label'] ?? ''),
                (string) ($row['integration_date'] ?? ''),
                (string) ($row['service_label'] ?? ''),
                (string) ($row['fonction_label'] ?? ''),
                (string) ($row['email'] ?? ''),
            ], ';');
        }
        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        return is_string($content) ? $content : '';
    }

    private function actorName(array $actor): ?string
    {
        $name = trim((string) ($actor['user_name'] ?? $actor['name'] ?? ''));
        return $name !== '' ? $name : null;
    }

    private function ensureSupportTables(): void
    {
        $this->ensureRhStatutAbbreviationColumn();

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS rh_matricule_remaniement_runs (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                total_personnel INT UNSIGNED NOT NULL DEFAULT 0,
                total_changed INT UNSIGNED NOT NULL DEFAULT 0,
                created_by_user_id INT DEFAULT NULL,
                created_by_name VARCHAR(150) DEFAULT NULL,
                preview_json LONGTEXT DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');
    }

    private function ensureRhStatutAbbreviationColumn(): void
    {
        try {
            $stmt = $this->pdo->query("SHOW COLUMNS FROM rh_statuts LIKE 'matricule_abbreviation'");
            if ($stmt && $stmt->fetch(PDO::FETCH_ASSOC)) {
                return;
            }

            $this->pdo->exec('ALTER TABLE rh_statuts ADD COLUMN matricule_abbreviation VARCHAR(12) NULL AFTER label');
        } catch (Throwable) {
            // Le referentiel RH sera cree par PersonnelDirectory si besoin.
        }
    }
}
