<?php

require_once __DIR__ . '/../model/AccessManager.php';
require_once __DIR__ . '/../model/PortalModuleManager.php';

class AclAuditService
{
    private PDO $pdo;
    private string $root;

    public function __construct(PDO $pdo, string $root)
    {
        $this->pdo = $pdo;
        $this->root = rtrim(str_replace('\\', '/', $root), '/');
    }

    public function buildReport(): array
    {
        $pages = $this->scanPages();
        $declared = AccessManager::PAGE_PERMISSIONS;
        $usedPermissions = [];
        foreach ($declared as $permissions) {
            foreach ((array) $permissions as $permission) {
                $usedPermissions[(string) $permission] = true;
            }
        }

        $portal = new PortalModuleManager($this->pdo);
        $modules = $portal->listAll();
        foreach ($modules as $module) {
            foreach (($module['required_permissions'] ?? []) as $permission) {
                $usedPermissions[(string) $permission] = true;
            }
        }

        $permissionColumns = $this->permissionColumns();
        $issues = [];
        $protectedCount = 0;
        foreach ($pages as $page) {
            $basename = basename($page['path']);
            $permission = $declared[$page['path']] ?? $declared[$basename] ?? null;
            $isProtected = $page['has_auth'] && ($permission !== null || $page['has_acl']);
            if ($isProtected) {
                $protectedCount++;
            }
            if (!$page['has_auth']) {
                $issues[] = $this->issue('Non protege', 'danger', 'page', $page['path'], 'Aucun chargement de auth_check.php detecte.');
            } elseif ($permission === null && !$page['has_acl']) {
                $issues[] = $this->issue('A verifier', 'warning', 'page', $page['path'], 'Authentifiee, mais sans permission ACL explicite.');
            }
        }

        foreach ($permissionColumns as $permission) {
            if (!isset($usedPermissions[$permission])) {
                $issues[] = $this->issue('Permission orpheline', 'warning', 'permission', $permission, 'Colonne utilisateur presente mais absente des pages et modules.');
            }
        }

        foreach ($modules as $module) {
            $target = ltrim((string) ($module['target'] ?? ''), '/');
            if ($target === '' || !is_file($this->root . '/' . $target)) {
                $issues[] = $this->issue('Permission manquante', 'danger', 'module', (string) ($module['label'] ?? $module['module_key']), 'La cible du menu est absente : ' . $target);
                continue;
            }
            $targetPermission = $declared[$target] ?? $declared[basename($target)] ?? null;
            if ($targetPermission === null && empty($module['required_permissions'])) {
                $issues[] = $this->issue('A verifier', 'warning', 'module', (string) ($module['label'] ?? $module['module_key']), 'Carte visible sans permission declaree.');
            }
        }

        $users = $this->incoherentUsers($permissionColumns);
        foreach ($users as $user) {
            $issues[] = $this->issue('A verifier', 'warning', 'utilisateur', $user['label'], $user['reason']);
        }

        usort($issues, static fn(array $a, array $b): int => ($a['weight'] <=> $b['weight']) ?: strcmp($a['label'], $b['label']));

        return [
            'generated_at' => date('Y-m-d H:i:s'),
            'counts' => [
                'pages' => count($pages),
                'protected' => $protectedCount,
                'issues' => count($issues),
                'permissions' => count($permissionColumns),
                'modules' => count($modules),
                'users_incoherent' => count($users),
            ],
            'pages' => $pages,
            'modules' => $modules,
            'issues' => $issues,
        ];
    }

    private function scanPages(): array
    {
        $excluded = [
            '/vendor/', '/storage/', '/uploads/', '/node_modules/', '/tests/', '/test/',
            '/model/', '/models/', '/repository/', '/repositories/', '/service/', '/services/',
            '/controller/', '/controllers/', '/includes/', '/partials/', '/config/', '/database/',
            '/migrations/', '/cron/', '/scripts/', '/twilio/', '/phpmailer/', '/fpdf/', '/plugins/',
        ];
        $result = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }
            $path = str_replace('\\', '/', $file->getPathname());
            foreach ($excluded as $segment) {
                if (strpos($path, $segment) !== false) {
                    continue 2;
                }
            }
            $relative = ltrim(substr($path, strlen($this->root)), '/');
            $segments = explode('/', $relative);
            if (count($segments) > 1) {
                $allowedEntryDirectories = [
                    'admin', 'ai_assistant', 'ajax', 'api', 'attendance_api', 'chantiers',
                    'clients', 'communication', 'crm', 'debourse', 'decaissement', 'devis',
                    'divertissements', 'documentation', 'emails', 'emploi_du_temps', 'employe',
                    'facturation', 'formation', 'fournisseur', 'logi', 'offres', 'planning_chantier',
                    'recurring_needs', 'request', 'rh', 'ticketing', 'tools', 'unites',
                ];
                if (!in_array($segments[0], $allowedEntryDirectories, true)) {
                    continue;
                }
            }
            $lowerName = strtolower($file->getBasename());
            if (preg_match('/^(__tmp|debug|test|tmp|demo|migration|migrate|create_table|diagnostic|check)_/', $lowerName)) {
                continue;
            }
            $content = (string) @file_get_contents($path);
            $result[] = [
                'path' => $relative,
                'has_auth' => strpos($content, 'auth_check.php') !== false,
                'has_acl' => strpos($content, 'requirePageAccess') !== false || strpos($content, 'hasPermission(') !== false,
            ];
        }
        return $result;
    }

    private function permissionColumns(): array
    {
        try {
            $columns = $this->pdo->query('SHOW COLUMNS FROM user_devis_banamur')->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
            return array_values(array_filter($columns, static fn(string $column): bool => str_starts_with($column, 'acces_') || str_starts_with($column, 'ao_') || str_starts_with($column, 'document_') || str_starts_with($column, 'formation_')));
        } catch (Throwable $e) {
            return [];
        }
    }

    private function incoherentUsers(array $permissionColumns): array
    {
        if ($permissionColumns === []) {
            return [];
        }
        $quoted = implode(', ', array_map(static fn(string $c): string => '`' . str_replace('`', '', $c) . '`', $permissionColumns));
        try {
            $rows = $this->pdo->query('SELECT id, prenom, nom, mail_pro, active, ' . $quoted . ' FROM user_devis_banamur ORDER BY active DESC, nom, prenom')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }

        $issues = [];
        foreach ($rows as $row) {
            $enabled = [];
            foreach ($permissionColumns as $permission) {
                if (!empty($row[$permission])) {
                    $enabled[] = $permission;
                }
            }
            $label = trim((string) ($row['prenom'] ?? '') . ' ' . (string) ($row['nom'] ?? '')) ?: (string) ($row['mail_pro'] ?? ('Utilisateur #' . $row['id']));
            if (empty($row['active']) && $enabled !== []) {
                $issues[] = ['label' => $label, 'reason' => 'Compte inactif avec ' . count($enabled) . ' permission(s) encore active(s).'];
            } elseif (!empty($row['acces_admin']) && count($enabled) <= 1) {
                $issues[] = ['label' => $label, 'reason' => 'Administrateur sans droits metier explicites; verifier le profil attendu.'];
            }
        }
        return $issues;
    }

    private function issue(string $status, string $level, string $type, string $label, string $detail): array
    {
        return ['status' => $status, 'level' => $level, 'type' => $type, 'label' => $label, 'detail' => $detail, 'weight' => $level === 'danger' ? 0 : 1];
    }
}
