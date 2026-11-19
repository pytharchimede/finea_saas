<?php

require_once __DIR__ . '/AccessManager.php';
require_once __DIR__ . '/PortalModuleManager.php';

class SearchEngineKnowledgeManager
{
    private PDO $pdo;
    private string $root;

    public function __construct(PDO $pdo, ?string $root = null)
    {
        $this->pdo = $pdo;
        $this->root = rtrim($root ?? dirname(__DIR__), '/\\');
        $this->ensureTable();
    }

    public function sync(): array
    {
        $seenKeys = [];
        $pages = 0;
        $modules = 0;

        foreach ($this->discoverPages() as $page) {
            $entityKey = 'page:' . $page;
            $seenKeys[] = $entityKey;
            $permission = $this->inferPagePermissions($page);
            $this->upsertSystemEntry([
                'entity_key' => $entityKey,
                'source_type' => 'page',
                'label' => $this->extractPageLabel($page),
                'target' => $page,
                'result_type' => $this->isFormPage($page) ? 'form' : 'page',
                'description' => $this->extractPageDescription($page),
                'auto_keywords' => $this->buildAutomaticKeywords($page),
                'required_permissions' => is_array($permission) ? $permission : [$permission],
            ]);
            $pages++;
        }

        try {
            $moduleManager = new PortalModuleManager($this->pdo);
            foreach ($moduleManager->listAll() as $module) {
                $moduleKey = trim((string) ($module['module_key'] ?? ''));
                if ($moduleKey === '') {
                    continue;
                }
                $entityKey = 'module:' . $moduleKey;
                $seenKeys[] = $entityKey;
                $this->upsertSystemEntry([
                    'entity_key' => $entityKey,
                    'source_type' => 'module',
                    'label' => (string) ($module['label'] ?? $moduleKey),
                    'target' => (string) ($module['target'] ?? ''),
                    'result_type' => 'module',
                    'description' => (string) ($module['subtitle'] ?? ''),
                    'auto_keywords' => trim(implode(' ', [
                        $moduleKey,
                        (string) ($module['slug'] ?? ''),
                        (string) ($module['label'] ?? ''),
                    ])),
                    'required_permissions' => (array) ($module['required_permissions'] ?? []),
                    'is_active' => !empty($module['is_active']) ? 1 : 0,
                ]);
                $modules++;
            }
        } catch (Throwable $ignored) {
        }

        if ($seenKeys !== []) {
            $placeholders = implode(',', array_fill(0, count($seenKeys), '?'));
            $stmt = $this->pdo->prepare(
                'UPDATE search_engine_knowledge
                 SET is_present = 0
                 WHERE is_system = 1 AND entity_key NOT IN (' . $placeholders . ')'
            );
            $stmt->execute($seenKeys);
        }

        return ['pages' => $pages, 'modules' => $modules, 'total' => $pages + $modules];
    }

    public function syncIfStale(int $maximumAgeSeconds = 300): ?array
    {
        $latest = $this->pdo->query('SELECT MAX(last_seen_at) FROM search_engine_knowledge WHERE is_system = 1')->fetchColumn();
        $latestTimestamp = $latest ? strtotime((string) $latest) : false;
        if ($latestTimestamp !== false && $latestTimestamp >= time() - max(60, $maximumAgeSeconds)) {
            return null;
        }
        return $this->sync();
    }

    public function list(array $filters = []): array
    {
        $where = [];
        $params = [];
        $query = trim((string) ($filters['q'] ?? ''));
        $type = trim((string) ($filters['type'] ?? ''));

        if ($query !== '') {
            $where[] = '(label LIKE :q OR target LIKE :q OR keywords LIKE :q OR synonyms LIKE :q OR auto_keywords LIKE :q)';
            $params['q'] = '%' . $query . '%';
        }
        if ($type !== '') {
            $where[] = 'result_type = :type';
            $params['type'] = $type;
        }

        $sql = 'SELECT * FROM search_engine_knowledge'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY is_active DESC, label ASC, id ASC LIMIT 1000';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function listActive(): array
    {
        $stmt = $this->pdo->query(
            'SELECT * FROM search_engine_knowledge
             WHERE is_active = 1 AND source_is_active = 1 AND is_present = 1 AND target <> ""
             ORDER BY label ASC'
        );
        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function get(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM search_engine_knowledge WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->hydrate($row) : null;
    }

    public function save(array $data, ?int $updatedBy = null): int
    {
        $id = (int) ($data['id'] ?? 0);
        $label = trim((string) ($data['label'] ?? ''));
        $target = trim(str_replace('\\', '/', (string) ($data['target'] ?? '')));
        $resultType = trim((string) ($data['result_type'] ?? 'page'));
        $allowedTypes = ['module', 'page', 'form', 'document', 'person', 'client', 'supplier', 'chantier', 'devis', 'invoice', 'ticket', 'asset', 'stock', 'contract', 'location'];
        if ($label === '' || $target === '') {
            throw new InvalidArgumentException('Le libellé et la cible sont obligatoires.');
        }
        if (!in_array($resultType, $allowedTypes, true)) {
            $resultType = 'page';
        }

        $permissions = array_values(array_unique(array_filter(array_map(
            static fn($permission): string => trim((string) $permission),
            (array) ($data['required_permissions'] ?? [])
        ))));
        $payload = [
            'label' => $label,
            'target' => $target,
            'result_type' => $resultType,
            'description' => trim((string) ($data['description'] ?? '')),
            'keywords' => $this->normalizeTerms((string) ($data['keywords'] ?? '')),
            'synonyms' => $this->normalizeTerms((string) ($data['synonyms'] ?? '')),
            'required_permissions_json' => json_encode($permissions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'is_active' => !empty($data['is_active']) ? 1 : 0,
            'updated_by' => $updatedBy,
        ];

        if ($id > 0) {
            $payload['id'] = $id;
            $stmt = $this->pdo->prepare(
                'UPDATE search_engine_knowledge SET
                    label=:label, target=:target, result_type=:result_type,
                    description=:description, keywords=:keywords, synonyms=:synonyms,
                    required_permissions_json=:required_permissions_json,
                    is_active=:is_active, is_customized=1, updated_by=:updated_by
                 WHERE id=:id'
            );
            $stmt->execute($payload);
            return $id;
        }

        $payload['entity_key'] = 'manual:' . bin2hex(random_bytes(12));
        $stmt = $this->pdo->prepare(
            'INSERT INTO search_engine_knowledge (
                entity_key, source_type, label, target, result_type, description,
                keywords, synonyms, required_permissions_json, is_active,
                is_system, is_customized, is_present, updated_by
             ) VALUES (
                :entity_key, "manual", :label, :target, :result_type, :description,
                :keywords, :synonyms, :required_permissions_json, :is_active,
                0, 1, 1, :updated_by
             )'
        );
        $stmt->execute($payload);
        return (int) $this->pdo->lastInsertId();
    }

    public function delete(int $id): void
    {
        $entry = $this->get($id);
        if (!$entry) {
            throw new InvalidArgumentException('Correspondance introuvable.');
        }
        if (!empty($entry['is_system'])) {
            $stmt = $this->pdo->prepare('UPDATE search_engine_knowledge SET is_active = 0 WHERE id = :id');
        } else {
            $stmt = $this->pdo->prepare('DELETE FROM search_engine_knowledge WHERE id = :id');
        }
        $stmt->execute(['id' => $id]);
    }

    public function permissionOptions(): array
    {
        try {
            return AccessManager::permissionColumns($this->pdo);
        } catch (Throwable $ignored) {
            return [];
        }
    }

    private function upsertSystemEntry(array $entry): void
    {
        $permissions = array_values(array_filter((array) ($entry['required_permissions'] ?? [])));
        $stmt = $this->pdo->prepare(
            'INSERT INTO search_engine_knowledge (
                entity_key, source_type, label, target, result_type, description,
                auto_keywords, required_permissions_json, is_active,
                source_is_active, is_system, is_present, last_seen_at
             ) VALUES (
                :entity_key, :source_type, :label, :target, :result_type, :description,
                :auto_keywords, :required_permissions_json, :is_active,
                :source_is_active, 1, 1, CURRENT_TIMESTAMP
             ) ON DUPLICATE KEY UPDATE
                source_type=VALUES(source_type),
                label=IF(is_customized=1, label, VALUES(label)),
                target=VALUES(target),
                result_type=IF(is_customized=1, result_type, VALUES(result_type)),
                description=IF(is_customized=1, description, VALUES(description)),
                auto_keywords=VALUES(auto_keywords),
                required_permissions_json=IF(is_customized=1, required_permissions_json, VALUES(required_permissions_json)),
                source_is_active=VALUES(source_is_active),
                is_present=1,
                last_seen_at=CURRENT_TIMESTAMP'
        );
        $stmt->execute([
            'entity_key' => $entry['entity_key'],
            'source_type' => $entry['source_type'],
            'label' => $entry['label'],
            'target' => $entry['target'],
            'result_type' => $entry['result_type'],
            'description' => $entry['description'],
            'auto_keywords' => $this->normalizeTerms((string) $entry['auto_keywords']),
            'required_permissions_json' => json_encode($permissions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'is_active' => array_key_exists('is_active', $entry) ? (int) $entry['is_active'] : 1,
            'source_is_active' => array_key_exists('is_active', $entry) ? (int) $entry['is_active'] : 1,
        ]);
    }

    private function discoverPages(): array
    {
        $excluded = ['/vendor/', '/node_modules/', '/uploads/', '/storage/', '/api/', '/request/', '/cron/', '/tools/', '/tests/', '/model/', '/repositories/', '/services/', '/includes/', '/partials/', '/views/'];
        $pages = [];
        $directory = new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS);
        $filter = new RecursiveCallbackFilterIterator($directory, static function (SplFileInfo $file) use ($excluded): bool {
            $path = '/' . strtolower(str_replace('\\', '/', $file->getPathname()));
            if ($file->isDir()) {
                if (!$file->isReadable()) {
                    return false;
                }
                foreach ($excluded as $segment) {
                    if (strpos($path . '/', $segment) !== false) {
                        return false;
                    }
                }
            }
            return true;
        });
        $iterator = new RecursiveIteratorIterator($filter);
        foreach ($iterator as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }
            $path = str_replace('\\', '/', $file->getPathname());
            $relative = ltrim(substr($path, strlen(str_replace('\\', '/', $this->root))), '/');
            $wrapped = '/' . strtolower($relative);
            $baseName = strtolower(basename($relative));
            if (in_array($baseName, ['auth_check.php', 'login.php', 'deconnex.php'], true)
                || preg_match('/(?:^|_)(?:delete|supprimer|desactiver|reactiver|update|save|store|process|handler|download|export|import|ajax|webhook|callback|logout|sync|migrate)(?:_|\\.)/', $baseName)) {
                continue;
            }
            foreach ($excluded as $segment) {
                if (strpos($wrapped, $segment) !== false) {
                    continue 2;
                }
            }
            $contents = @file_get_contents($path);
            if (!is_string($contents)
                || (!preg_match('/\\$pageTitle\\s*=/u', $contents)
                    && stripos($contents, '<h1') === false
                    && stripos($contents, 'includes/header.php') === false)) {
                continue;
            }
            $pages[] = $relative;
        }
        sort($pages, SORT_NATURAL | SORT_FLAG_CASE);
        return $pages;
    }

    private function inferPagePermissions(string $page)
    {
        $configured = AccessManager::PAGE_PERMISSIONS[$page]
            ?? AccessManager::PAGE_PERMISSIONS[basename($page)]
            ?? null;
        if ($configured !== null) {
            return $configured;
        }

        $modulePermissions = [
            'admin/' => ['acces_admin'],
            'employe/' => ['acces_employe', 'acces_admin'],
            'rh/' => ['acces_rh', 'acces_direction', 'acces_admin'],
            'logi/' => ['acces_logistique', 'acces_admin'],
            'facturation/' => ['acces_facturation', 'acces_direction', 'acces_admin'],
            'fournisseur/' => ['acces_fournisseur', 'acces_compta', 'acces_admin', 'acces_employe'],
            'crm/' => ['acces_crm', 'acces_direction', 'acces_admin'],
            'planning_chantier/' => ['acces_planning_chantier', 'acces_planning', 'acces_liste_chantier', 'acces_direction', 'acces_admin'],
            'documentation/' => ['acces_documentation', 'acces_admin'],
            'formation/' => ['acces_formation', 'formation_admin', 'acces_admin'],
            'recurring_needs/' => ['acces_besoins_recurrents', 'acces_employe', 'acces_admin'],
            'ticketing/' => ['acces_ticketing', 'acces_admin'],
        ];
        foreach ($modulePermissions as $prefix => $permissions) {
            if (strpos($page, $prefix) === 0) {
                return $permissions;
            }
        }

        return [];
    }

    private function extractPageLabel(string $page): string
    {
        $contents = @file_get_contents($this->root . '/' . $page);
        if (is_string($contents)) {
            if (preg_match('/\\$pageTitle\\s*=\\s*[\'"]([^\'"]+)[\'"]/iu', $contents, $match)) {
                return trim(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
            if (preg_match('/<h1\\b[^>]*>(.*?)<\\/h1>/isu', $contents, $match)) {
                $label = trim(preg_replace('/\\s+/u', ' ', strip_tags(preg_replace('/<\\?.*?\\?>/su', '', $match[1]) ?? $match[1])) ?? '');
                if ($label !== '') {
                    return html_entity_decode($label, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
            }
        }
        $name = preg_replace('/\\.php$/i', '', basename($page)) ?: basename($page);
        return mb_convert_case(str_replace(['_', '-'], ' ', $name), MB_CASE_TITLE, 'UTF-8');
    }

    private function extractPageDescription(string $page): string
    {
        $contents = @file_get_contents($this->root . '/' . $page);
        if (!is_string($contents)) {
            return '';
        }
        if (preg_match('/<meta\\s+name=[\'"]description[\'"]\\s+content=[\'"]([^\'"]+)[\'"]/iu', $contents, $match)) {
            return trim(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        return '';
    }

    private function buildAutomaticKeywords(string $page): string
    {
        $parts = preg_split('#[/_.-]+#', preg_replace('/\\.php$/i', '', $page) ?? $page) ?: [];
        return implode(' ', array_unique(array_filter($parts)));
    }

    private function isFormPage(string $page): bool
    {
        $name = strtolower(basename($page));
        if (preg_match('/form|nouve|ajout|edit|modif|generer|create/', $name)) {
            return true;
        }
        $contents = @file_get_contents($this->root . '/' . $page);
        return is_string($contents) && stripos($contents, '<form') !== false;
    }

    private function normalizeTerms(string $terms): string
    {
        $items = preg_split('/[,;\\r\\n]+/u', $terms) ?: [];
        $items = array_values(array_unique(array_filter(array_map('trim', $items))));
        return implode(', ', $items);
    }

    private function hydrate(array $row): array
    {
        $permissions = json_decode((string) ($row['required_permissions_json'] ?? '[]'), true);
        $row['required_permissions'] = is_array($permissions) ? $permissions : [];
        return $row;
    }

    private function ensureTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS search_engine_knowledge (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                entity_key VARCHAR(255) NOT NULL,
                source_type VARCHAR(40) NOT NULL DEFAULT "manual",
                label VARCHAR(255) NOT NULL,
                target VARCHAR(500) NOT NULL,
                result_type VARCHAR(40) NOT NULL DEFAULT "page",
                description TEXT NULL,
                auto_keywords TEXT NULL,
                keywords TEXT NULL,
                synonyms TEXT NULL,
                required_permissions_json LONGTEXT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                source_is_active TINYINT(1) NOT NULL DEFAULT 1,
                is_system TINYINT(1) NOT NULL DEFAULT 0,
                is_customized TINYINT(1) NOT NULL DEFAULT 0,
                is_present TINYINT(1) NOT NULL DEFAULT 1,
                last_seen_at DATETIME NULL,
                updated_by INT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_search_engine_entity (entity_key),
                KEY idx_search_engine_type_active (result_type, is_active),
                KEY idx_search_engine_source (source_type, is_present)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $columns = $this->pdo->query('SHOW COLUMNS FROM search_engine_knowledge')->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
        if (!in_array('source_is_active', $columns, true)) {
            $this->pdo->exec('ALTER TABLE search_engine_knowledge ADD COLUMN source_is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER is_active');
        }
        if (!in_array('is_customized', $columns, true)) {
            $this->pdo->exec('ALTER TABLE search_engine_knowledge ADD COLUMN is_customized TINYINT(1) NOT NULL DEFAULT 0 AFTER is_system');
        }
    }
}
