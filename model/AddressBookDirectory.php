<?php

require_once __DIR__ . '/../repositories/FournisseurRepository.php';

class AddressBookDirectory
{
    private PDO $pdo;
    private array $tableColumns = [];
    private bool $historyEnsured = false;
    private bool $historyAvailable = false;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureHistoryTable();
    }

    public function listContacts(): array
    {
        $contacts = array_merge(
            $this->listInternalContacts(),
            $this->listSupplierContacts()
        );

        usort($contacts, static function (array $left, array $right): int {
            $leftRank = (int) ($left['sort_rank'] ?? 99);
            $rightRank = (int) ($right['sort_rank'] ?? 99);
            if ($leftRank !== $rightRank) {
                return $leftRank <=> $rightRank;
            }

            return strcasecmp((string) ($left['display_name'] ?? ''), (string) ($right['display_name'] ?? ''));
        });

        return $contacts;
    }

    public function getContactById(string $contactKey): ?array
    {
        $contactKey = trim($contactKey);
        if ($contactKey === '') {
            return null;
        }

        foreach ($this->listContacts() as $contact) {
            if ((string) ($contact['id'] ?? '') === $contactKey) {
                return $contact;
            }
        }

        return null;
    }

    public function listInteractions(string $contactKey, int $limit = 100): array
    {
        if (!$this->historyAvailable) {
            return [];
        }

        $parsed = $this->parseContactKey($contactKey);
        if ($parsed === null) {
            return [];
        }

        $limit = max(1, min(300, $limit));
        $stmt = $this->pdo->prepare(
            'SELECT id, contact_key, source_type, source_id, interaction_type, channel, subject, note, metadata_json, created_by_user_id, created_at
             FROM address_book_interactions
             WHERE contact_key = :contact_key
             ORDER BY created_at DESC, id DESC
             LIMIT ' . (int) $limit
        );
        $stmt->execute(['contact_key' => $parsed['contact_key']]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $userIds = array_values(array_unique(array_filter(array_map(static function (array $row): int {
            return (int) ($row['created_by_user_id'] ?? 0);
        }, $rows), static function (int $value): bool {
            return $value > 0;
        })));
        $usersById = $this->loadUsersByIds($userIds);

        foreach ($rows as &$row) {
            $row['id'] = (int) ($row['id'] ?? 0);
            $row['created_by_user_id'] = (int) ($row['created_by_user_id'] ?? 0);
            $row['interaction_type'] = trim((string) ($row['interaction_type'] ?? 'note'));
            $row['channel'] = trim((string) ($row['channel'] ?? ''));
            $row['subject'] = trim((string) ($row['subject'] ?? ''));
            $row['note'] = trim((string) ($row['note'] ?? ''));
            $row['created_at'] = (string) ($row['created_at'] ?? '');
            $row['metadata'] = $this->normalizeMetadata($this->decodeMetadata((string) ($row['metadata_json'] ?? '')));
            $row['actor_display'] = $usersById[$row['created_by_user_id']]['display'] ?? ('Utilisateur #' . $row['created_by_user_id']);
        }
        unset($row);

        return $rows;
    }

    public function addInteraction(string $contactKey, int $createdByUserId, string $interactionType, string $channel = '', string $subject = '', string $note = '', array $metadata = []): bool
    {
        if (!$this->historyAvailable) {
            return false;
        }

        $parsed = $this->parseContactKey($contactKey);
        if ($parsed === null || $createdByUserId <= 0) {
            return false;
        }

        $interactionType = $this->normalizeInteractionType($interactionType);
        $channel = $this->normalizeChannel($channel);
        $subject = mb_substr(trim($subject), 0, 190);
        $note = trim($note);
        $metadata = $this->normalizeMetadata($metadata);
        if ($interactionType === 'note' && $subject === '' && $note === '') {
            return false;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO address_book_interactions (
                contact_key, source_type, source_id, interaction_type, channel, subject, note, metadata_json, created_by_user_id, created_at
             ) VALUES (
                :contact_key, :source_type, :source_id, :interaction_type, :channel, :subject, :note, :metadata_json, :created_by_user_id, NOW()
             )'
        );

        return $stmt->execute([
            'contact_key' => $parsed['contact_key'],
            'source_type' => $parsed['source_type'],
            'source_id' => $parsed['source_id'],
            'interaction_type' => $interactionType,
            'channel' => $channel,
            'subject' => $subject,
            'note' => $note,
            'metadata_json' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_by_user_id' => $createdByUserId,
        ]);
    }

    public function updateInteractionStatus(string $contactKey, int $interactionId, string $status): bool
    {
        if (!$this->historyAvailable || $interactionId <= 0) {
            return false;
        }

        $parsed = $this->parseContactKey($contactKey);
        if ($parsed === null) {
            return false;
        }

        $stmt = $this->pdo->prepare(
            'SELECT metadata_json
             FROM address_book_interactions
             WHERE id = :id AND contact_key = :contact_key
             LIMIT 1'
        );
        $stmt->execute([
            'id' => $interactionId,
            'contact_key' => $parsed['contact_key'],
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }

        $metadata = $this->normalizeMetadata($this->decodeMetadata((string) ($row['metadata_json'] ?? '')));
        $metadata['status'] = $this->normalizeStatus($status);

        $update = $this->pdo->prepare(
            'UPDATE address_book_interactions
             SET metadata_json = :metadata_json
             WHERE id = :id AND contact_key = :contact_key'
        );

        return $update->execute([
            'metadata_json' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'id' => $interactionId,
            'contact_key' => $parsed['contact_key'],
        ]);
    }

    public function hasHistoryStorage(): bool
    {
        return $this->historyAvailable;
    }

    private function listInternalContacts(): array
    {
        $userColumns = $this->getTableColumns('user_devis_banamur');
        $personnelColumns = $this->getTableColumns('personnel');
        $serviceColumns = $this->getTableColumns('service');
        $fonctionColumns = $this->getTableColumns('fonction');

        $phoneExpression = $this->buildPreferredTrimmedExpression([
            ['alias' => 'u', 'column' => 'telephone', 'columns' => $userColumns],
            ['alias' => 'u', 'column' => 'phone', 'columns' => $userColumns],
            ['alias' => 'u', 'column' => 'mobile', 'columns' => $userColumns],
            ['alias' => 'u', 'column' => 'tel', 'columns' => $userColumns],
            ['alias' => 'p', 'column' => 'tel_personnel_soignant', 'columns' => $personnelColumns],
            ['alias' => 'p', 'column' => 'telephone', 'columns' => $personnelColumns],
            ['alias' => 'p', 'column' => 'phone', 'columns' => $personnelColumns],
            ['alias' => 'p', 'column' => 'mobile', 'columns' => $personnelColumns],
            ['alias' => 'p', 'column' => 'tel', 'columns' => $personnelColumns],
        ]);

        $photoExpression = in_array('photo', $userColumns, true)
            ? "COALESCE(NULLIF(TRIM(u.photo), ''), '')"
            : "''";

        $serviceIdExpression = in_array('service_id', $personnelColumns, true)
            ? 'COALESCE(p.service_id, 0)'
            : '0';

        $serviceLabelExpression = in_array('lib_service', $serviceColumns, true)
            ? "COALESCE(NULLIF(TRIM(s.lib_service), ''), '')"
            : "''";

        $jobTitleExpression = in_array('lib_fonction', $fonctionColumns, true)
            ? "COALESCE(NULLIF(TRIM(f.lib_fonction), ''), '')"
            : "''";

        $sql = "SELECT
                u.id AS contact_id,
                COALESCE(NULLIF(TRIM(u.prenom), ''), '') AS first_name,
                COALESCE(NULLIF(TRIM(u.nom), ''), '') AS last_name,
                COALESCE(NULLIF(TRIM(u.mail_pro), ''), NULLIF(TRIM(p.email_personnel_soignant), ''), '') AS email,
                {$phoneExpression} AS phone,
                {$photoExpression} AS photo,
                {$serviceIdExpression} AS service_id,
                {$serviceLabelExpression} AS service_label,
                {$jobTitleExpression} AS job_title
            FROM user_devis_banamur u
            LEFT JOIN personnel p ON p.id_personnel_soignant = u.personnel_id
            LEFT JOIN service s ON s.id_service = p.service_id
            LEFT JOIN fonction f ON f.id_fonction = p.fonction_id
            WHERE COALESCE(u.active, 0) = 1
            ORDER BY u.prenom ASC, u.nom ASC, u.id ASC";

        $stmt = $this->pdo->query($sql);
        $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

        $contacts = [];

        foreach ($rows as $row) {
            $firstName = trim((string)($row['first_name'] ?? ''));
            $lastName = trim((string)($row['last_name'] ?? ''));
            $email = trim((string)($row['email'] ?? ''));
            $phone = trim((string)($row['phone'] ?? ''));
            $serviceLabel = trim((string)($row['service_label'] ?? ''));
            $jobTitle = trim((string)($row['job_title'] ?? ''));

            $displayName = trim($firstName . ' ' . $lastName);

            if ($displayName === '') {
                $displayName = $email !== ''
                    ? $email
                    : 'Collaborateur #' . (int)($row['contact_id'] ?? 0);
            }

            $contacts[] = [
                'id' => 'internal:' . (int)($row['contact_id'] ?? 0),
                'source' => 'internal',
                'source_label' => 'Interne',
                'sort_rank' => 1,
                'display_name' => $displayName,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'contact_name' => $displayName,
                'organization' => $serviceLabel !== '' ? $serviceLabel : 'Équipe interne',
                'title' => $jobTitle,
                'organization_label' => 'Service',
                'email' => $email,
                'phone' => $phone,
                'photo' => trim((string)($row['photo'] ?? '')),
                'subtitle' => $serviceLabel !== '' ? $serviceLabel : 'Collaborateur interne',
                'meta' => $jobTitle !== ''
                    ? $jobTitle . ($serviceLabel !== '' ? ' · ' . $serviceLabel : '')
                    : ($serviceLabel !== '' ? $serviceLabel : 'Interne'),
                'search_blob' => mb_strtolower(
                    implode(' ', [
                        $displayName,
                        $firstName,
                        $lastName,
                        $email,
                        $phone,
                        $serviceLabel,
                        $jobTitle,
                    ]),
                    'UTF-8'
                ),
            ];
        }

        return $contacts;
    }

    public function filterContactsForExport(array $contacts, string $scope = 'all'): array
    {
        $scope = trim($scope);

        return array_values(array_filter($contacts, static function (array $contact) use ($scope): bool {
            $source = (string)($contact['source'] ?? '');

            if ($scope === 'internal') {
                return $source === 'internal';
            }

            if ($scope === 'supplier') {
                return in_array($source, ['supplier', 'provider'], true);
            }

            return in_array($source, ['internal', 'supplier', 'provider'], true);
        }));
    }

    private function listSupplierContacts(): array
    {
        $supplierModel = new \FournisseurRepository($this->pdo);
        $rows = $supplierModel->listLatest(500, true);
        $contacts = [];

        foreach ($rows as $row) {
            $email = trim((string) ($row['email'] ?? ''));
            $phone = trim((string) ($row['telephone'] ?? ''));
            $contactName = trim((string) ($row['contact_nom'] ?? ''));
            $personFirstName = trim((string) ($row['person_first_name'] ?? ''));
            $personLastName = trim((string) ($row['person_last_name'] ?? ''));
            $personDisplay = trim($personFirstName . ' ' . $personLastName);
            if ($contactName === '') {
                $contactName = $personDisplay;
            }

            $organization = trim((string) ($row['supplier_display_name'] ?? $row['nom_commercial'] ?? $row['raison_sociale'] ?? ''));
            if ($organization === '') {
                $organization = 'Fournisseur #' . (int) ($row['id_fournisseur'] ?? 0);
            }

            $categoryLabels = array_values(array_filter(array_map('strval', (array) ($row['category_labels'] ?? [])), static function (string $value): bool {
                return trim($value) !== '';
            }));

            $isProvider = false;
            foreach ($categoryLabels as $label) {
                if (stripos($label, 'prestation') !== false || stripos($label, 'service') !== false) {
                    $isProvider = true;
                    break;
                }
            }

            $sourceLabel = $isProvider ? 'Prestataire' : 'Fournisseur';
            $displayName = $contactName !== '' ? $contactName : $organization;

            $contacts[] = [
                'id' => 'supplier:' . (int) ($row['id_fournisseur'] ?? 0),
                'source' => $isProvider ? 'provider' : 'supplier',
                'source_label' => $sourceLabel,
                'sort_rank' => $isProvider ? 2 : 3,
                'display_name' => $displayName,
                'first_name' => $personFirstName,
                'last_name' => $personLastName,
                'contact_name' => $contactName,
                'organization' => $organization,
                'organization_label' => 'Structure',
                'email' => $email,
                'phone' => $phone,
                'photo' => trim((string) ($row['photo_path'] ?? $row['logo_path'] ?? '')),
                'subtitle' => $organization,
                'meta' => $categoryLabels ? implode(' • ', array_slice($categoryLabels, 0, 3)) : $sourceLabel,
                'search_blob' => mb_strtolower(implode(' ', [$displayName, $contactName, $personFirstName, $personLastName, $organization, $email, $phone, implode(' ', $categoryLabels)]), 'UTF-8'),
            ];
        }

        return $contacts;
    }

    private function getTableColumns(string $table): array
    {
        if (isset($this->tableColumns[$table])) {
            return $this->tableColumns[$table];
        }

        try {
            $stmt = $this->pdo->query('SHOW COLUMNS FROM ' . $table);
            $columns = $stmt ? ($stmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: []) : [];
            $this->tableColumns[$table] = array_map('strval', $columns);
        } catch (Throwable $e) {
            $this->tableColumns[$table] = [];
        }

        return $this->tableColumns[$table];
    }

    private function buildPreferredTrimmedExpression(array $candidates): string
    {
        $parts = [];
        foreach ($candidates as $candidate) {
            $column = (string) ($candidate['column'] ?? '');
            $alias = (string) ($candidate['alias'] ?? '');
            $columns = (array) ($candidate['columns'] ?? []);
            if ($column === '' || $alias === '' || !in_array($column, $columns, true)) {
                continue;
            }

            $parts[] = "NULLIF(TRIM({$alias}.{$column}), '')";
        }

        if (!$parts) {
            return "''";
        }

        return 'COALESCE(' . implode(', ', $parts) . ", '')";
    }

    private function ensureHistoryTable(): void
    {
        if ($this->historyEnsured) {
            return;
        }

        try {
            $this->pdo->exec(
                "CREATE TABLE IF NOT EXISTS address_book_interactions (
                    id INT NOT NULL AUTO_INCREMENT,
                    contact_key VARCHAR(120) NOT NULL,
                    source_type VARCHAR(30) NOT NULL,
                    source_id INT NOT NULL DEFAULT 0,
                    interaction_type VARCHAR(30) NOT NULL DEFAULT 'note',
                    channel VARCHAR(30) NULL,
                    subject VARCHAR(190) NULL,
                    note TEXT NULL,
                    metadata_json LONGTEXT NULL,
                    created_by_user_id INT NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY idx_address_book_contact_date (contact_key, created_at),
                    KEY idx_address_book_actor_date (created_by_user_id, created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
            );
            $this->historyAvailable = true;
        } catch (Throwable $e) {
            $this->historyAvailable = false;
        }

        $this->historyEnsured = true;
    }

    private function parseContactKey(string $contactKey): ?array
    {
        $contactKey = trim($contactKey);
        if (!preg_match('/^(internal|supplier):([0-9]+)$/', $contactKey, $matches)) {
            return null;
        }

        return [
            'contact_key' => $contactKey,
            'source_type' => (string) $matches[1],
            'source_id' => (int) $matches[2],
        ];
    }

    private function normalizeInteractionType(string $interactionType): string
    {
        $allowed = ['note', 'email', 'sms', 'call', 'meeting'];
        $interactionType = strtolower(trim($interactionType));
        return in_array($interactionType, $allowed, true) ? $interactionType : 'note';
    }

    private function normalizeChannel(string $channel): string
    {
        $allowed = ['email', 'sms', 'phone', 'manual'];
        $channel = strtolower(trim($channel));
        return in_array($channel, $allowed, true) ? $channel : 'manual';
    }

    private function normalizeStatus(string $status): string
    {
        $allowed = ['open', 'pending', 'scheduled', 'done'];
        $status = strtolower(trim($status));
        return in_array($status, $allowed, true) ? $status : 'open';
    }

    private function normalizeMetadata(array $metadata): array
    {
        $normalized = [];

        $normalized['status'] = $this->normalizeStatus((string) ($metadata['status'] ?? 'open'));

        $reminderAt = trim((string) ($metadata['reminder_at'] ?? ''));
        $normalized['reminder_at'] = $this->normalizeDateTimeString($reminderAt);

        $attachment = $metadata['attachment'] ?? null;
        if (is_array($attachment)) {
            $path = trim((string) ($attachment['path'] ?? ''));
            $name = trim((string) ($attachment['name'] ?? ''));
            if ($path !== '' && $name !== '') {
                $normalized['attachment'] = [
                    'path' => ltrim(str_replace('\\', '/', $path), '/'),
                    'name' => $name,
                    'mime' => trim((string) ($attachment['mime'] ?? 'application/octet-stream')),
                    'size' => max(0, (int) ($attachment['size'] ?? 0)),
                ];
            }
        }

        return $normalized;
    }

    private function normalizeDateTimeString(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $value = str_replace('T', ' ', $value);
        try {
            $dateTime = new DateTimeImmutable($value);
            return $dateTime->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            return '';
        }
    }

    private function loadUsersByIds(array $userIds): array
    {
        if (!$userIds) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = $this->pdo->prepare(
            'SELECT id, COALESCE(NULLIF(TRIM(prenom), ""), "") AS prenom, COALESCE(NULLIF(TRIM(nom), ""), "") AS nom, COALESCE(NULLIF(TRIM(mail_pro), ""), "") AS mail_pro
             FROM user_devis_banamur
             WHERE id IN (' . $placeholders . ')'
        );
        $stmt->execute($userIds);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $map = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $display = trim((string) ($row['prenom'] ?? '') . ' ' . (string) ($row['nom'] ?? ''));
            if ($display === '') {
                $display = trim((string) ($row['mail_pro'] ?? ''));
            }
            if ($display === '') {
                $display = 'Utilisateur #' . $id;
            }

            $map[$id] = ['display' => $display];
        }

        return $map;
    }

    private function decodeMetadata(string $payload): array
    {
        if ($payload === '') {
            return [];
        }

        $decoded = json_decode($payload, true);
        return is_array($decoded) ? $decoded : [];
    }


    public function exportContactsAsVcard(array $contacts): string
    {
        $content = '';

        foreach ($contacts as $contact) {
            $source = (string)($contact['source'] ?? '');
            $sourceLabel = (string)($contact['source_label'] ?? '');
            $firstName = trim((string)($contact['first_name'] ?? ''));
            $lastName = trim((string)($contact['last_name'] ?? ''));
            $fullName = trim((string)($contact['display_name'] ?? ''));
            $email = trim((string)($contact['email'] ?? ''));
            $phone = trim((string)($contact['phone'] ?? ''));
            $organization = trim((string)($contact['organization'] ?? ''));
            $title = trim((string)($contact['title'] ?? ''));

            if ($fullName === '') {
                $fullName = trim($firstName . ' ' . $lastName);
            }

            if ($fullName === '' || ($email === '' && $phone === '')) {
                continue;
            }

            if ($source === 'internal') {
                $company = 'BANAMUR INDUSTRIES';
                $department = $organization;
                $note = 'Personnel BANAMUR';
            } else {
                $company = $organization !== '' ? $organization : 'Fournisseur';
                $department = $sourceLabel !== '' ? $sourceLabel : 'Fournisseur';
                $note = 'Contact externe - ' . ($sourceLabel !== '' ? $sourceLabel : 'Fournisseur');
                if ($title === '') {
                    $title = $sourceLabel !== '' ? $sourceLabel : 'Fournisseur';
                }
            }

            $content .= "BEGIN:VCARD\r\n";
            $content .= "VERSION:3.0\r\n";
            $content .= "N:" . $this->escapeVcard($lastName) . ";" . $this->escapeVcard($firstName) . ";;;\r\n";
            $content .= "FN:" . $this->escapeVcard($fullName) . "\r\n";
            $content .= "ORG:" . $this->escapeVcard($company) . ";" . $this->escapeVcard($department) . "\r\n";

            if ($title !== '') {
                $content .= "TITLE:" . $this->escapeVcard($title) . "\r\n";
            }

            if ($email !== '') {
                $content .= "EMAIL;TYPE=INTERNET,WORK:" . $this->escapeVcard($email) . "\r\n";
            }

            if ($phone !== '') {
                $content .= "TEL;TYPE=WORK,VOICE:" . $this->escapeVcard($phone) . "\r\n";
            }

            $content .= "NOTE:" . $this->escapeVcard($note) . "\r\n";
            $content .= "END:VCARD\r\n";
        }

        return $content;
    }

    public function exportContactsAsCsv(array $contacts): string
    {
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, [
            'First Name',
            'Last Name',
            'Name',
            'E-mail 1 - Value',
            'Phone 1 - Value',
            'Organization 1 - Name',
            'Organization 1 - Department',
            'Organization 1 - Title',
        ]);

        foreach ($contacts as $contact) {
            if ((string)($contact['source'] ?? '') !== 'internal') {
                continue;
            }

            $firstName = trim((string)($contact['first_name'] ?? ''));
            $lastName = trim((string)($contact['last_name'] ?? ''));
            $fullName = trim((string)($contact['display_name'] ?? ($firstName . ' ' . $lastName)));
            $email = trim((string)($contact['email'] ?? ''));
            $phone = trim((string)($contact['phone'] ?? ''));
            $service = trim((string)($contact['organization'] ?? ''));
            $fonction = trim((string)($contact['title'] ?? ''));

            if ($fullName === '' || ($email === '' && $phone === '')) {
                continue;
            }

            fputcsv($handle, [
                $this->escapeCsvCell($firstName),
                $this->escapeCsvCell($lastName),
                $this->escapeCsvCell($fullName),
                $this->escapeCsvCell($email),
                $this->escapeCsvCell($phone),
                'BANAMUR INDUSTRIES',
                $this->escapeCsvCell($service),
                $this->escapeCsvCell($fonction),
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return (string)$csv;
    }

    private function escapeVcard(string $value): string
    {
        $value = trim($value);
        $value = str_replace("\\", "\\\\", $value);
        $value = str_replace(";", "\;", $value);
        $value = str_replace(",", "\,", $value);
        $value = str_replace(["\r\n", "\r", "\n"], "\\n", $value);

        return $value;
    }

    private function escapeCsvCell(string $value): string
    {
        $value = trim($value);

        if ($value !== '' && preg_match('/^[=+\-@]/', $value)) {
            $value = "'" . $value;
        }

        return $value;
    }
}
