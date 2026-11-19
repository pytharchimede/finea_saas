<?php

require_once __DIR__ . '/AuditTrail.php';

class AuditCoverageInspector
{
    private const MODULES = [
        'admin',
        'api',
        'crm',
        'decaissement',
        'employe',
        'emails',
        'facturation',
        'fournisseur',
        'logi',
        'request',
        'rh',
    ];

    private const EXCLUDED_PREFIXES = [
        'config/',
        'cron/',
        'model/',
        'tools/',
        'vendor/',
        'fpdf/',
        'fpdf186/',
        'plugins/',
        'phpqrcode/',
        'phpmailer/',
        'storage/',
        'uploads/',
        'photo/',
        'img/',
        'logo/',
        'qrCodeFile/',
        'Database/',
        'docs/',
        'css/',
        'js/',
        'js_form/',
        'partials/',
        'header/',
        'vendor/',
        'decaissement/twilio/',
    ];

    private const PUBLIC_INTENTIONAL = [
        'index.php',
        'login.php',
        'login_unlock.php',
        'reset_password.php',
        'reset_password_set.php',
        'tracking_pixel.php',
        'check_columns.php',
        'temp_tbody.php',
        'tmp_compare_point_financier.php',
        'menu.php',
        'menu_decaissement.php',
    ];

    public static function buildReport(string $workspaceRoot): array
    {
        $pages = [];
        $actionScopePages = [];

        foreach (self::findRelevantPhpFiles($workspaceRoot) as $filePath) {
            $relativePath = self::relativePath($workspaceRoot, $filePath);
            $content = @file_get_contents($filePath);
            if (!is_string($content)) {
                continue;
            }

            if (self::isNavigationPagePath($relativePath) && self::looksLikeVisualPage($relativePath, $content)) {
                $pages[] = self::analyzePage($relativePath, $filePath, $content);
            }

            if (self::isBusinessActionCandidatePath($relativePath)) {
                $actionScopePages[] = self::analyzePage($relativePath, $filePath, $content);
            }
        }

        usort($pages, static function (array $left, array $right): int {
            return [$left['module'], $left['path']] <=> [$right['module'], $right['path']];
        });

        $moduleSummary = [];
        foreach ($pages as $page) {
            $module = $page['module'];
            if (!isset($moduleSummary[$module])) {
                $moduleSummary[$module] = [
                    'module' => $module,
                    'total' => 0,
                    'covered_auth_check' => 0,
                    'covered_explicit' => 0,
                    'public_intentional' => 0,
                    'uncovered' => 0,
                    'risky_uncovered' => 0,
                ];
            }

            $moduleSummary[$module]['total']++;
            $moduleSummary[$module][$page['status']]++;
            if ($page['status'] === 'uncovered' && !empty($page['is_risky'])) {
                $moduleSummary[$module]['risky_uncovered']++;
            }
        }

        ksort($moduleSummary);

        $uncoveredPages = array_values(array_filter($pages, static function (array $page): bool {
            return $page['status'] === 'uncovered';
        }));

        usort($uncoveredPages, static function (array $left, array $right): int {
            $dateComparison = strcmp((string) $right['modified_at'], (string) $left['modified_at']);
            if ($dateComparison !== 0) {
                return $dateComparison;
            }

            return strcmp((string) $left['path'], (string) $right['path']);
        });

        $actionCandidates = self::detectActionCandidates($actionScopePages, $workspaceRoot);

        return [
            'generated_at' => date('Y-m-d H:i:s'),
            'pages' => $pages,
            'module_summary' => array_values($moduleSummary),
            'uncovered_pages' => $uncoveredPages,
            'action_candidates' => $actionCandidates,
            'counts' => [
                'total_pages' => count($pages),
                'covered_pages' => count(array_filter($pages, static function (array $page): bool {
                    return in_array($page['status'], ['covered_auth_check', 'covered_explicit'], true);
                })),
                'public_pages' => count(array_filter($pages, static function (array $page): bool {
                    return $page['status'] === 'public_intentional';
                })),
                'uncovered_pages' => count($uncoveredPages),
                'risky_uncovered_pages' => count(array_filter($uncoveredPages, static function (array $page): bool {
                    return !empty($page['is_risky']);
                })),
                'action_candidates' => count($actionCandidates),
            ],
        ];
    }

    public static function syncAlertState(PDO $pdo, array $report): array
    {
        AuditTrail::ensureSchema($pdo);

        $key = 'audit_coverage_snapshot';
        $statement = $pdo->prepare('SELECT details_json FROM audit_trail_maintenance WHERE maintenance_key = :maintenance_key LIMIT 1');
        $statement->execute([':maintenance_key' => $key]);

        $previous = $statement->fetchColumn();
        $previousData = is_string($previous) && trim($previous) !== '' ? json_decode($previous, true) : null;
        $previousData = is_array($previousData) ? $previousData : null;

        $currentUncoveredPaths = array_map(static function (array $page): string {
            return (string)$page['path'];
        }, $report['uncovered_pages']);

        $previousUncoveredPaths = $previousData['uncovered_paths'] ?? null;
        $previousUncoveredPaths = is_array($previousUncoveredPaths) ? $previousUncoveredPaths : null;

        $newUncoveredPaths = [];
        if ($previousUncoveredPaths !== null) {
            $newUncoveredPaths = array_values(array_diff($currentUncoveredPaths, $previousUncoveredPaths));
        }

        $snapshot = [
            'recorded_at' => date('Y-m-d H:i:s'),
            'uncovered_paths' => $currentUncoveredPaths,
            'action_candidate_paths' => array_map(static function (array $candidate): string {
                return (string)$candidate['path'];
            }, $report['action_candidates']),
            'counts' => $report['counts'],
        ];

        $upsert = $pdo->prepare(
            'INSERT INTO audit_trail_maintenance (maintenance_key, last_run_at, details_json)
             VALUES (:maintenance_key, NOW(), :details_json)
             ON DUPLICATE KEY UPDATE last_run_at = VALUES(last_run_at), details_json = VALUES(details_json)'
        );
        $upsert->execute([
            ':maintenance_key' => $key,
            ':details_json' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        return [
            'has_baseline' => $previousUncoveredPaths !== null,
            'new_uncovered_paths' => $newUncoveredPaths,
        ];
    }

    private static function findRelevantPhpFiles(string $workspaceRoot): array
    {
        $files = [];

        $directoryIterator = new RecursiveDirectoryIterator($workspaceRoot, FilesystemIterator::SKIP_DOTS);
        $filteredIterator = new RecursiveCallbackFilterIterator(
            $directoryIterator,
            static function (SplFileInfo $fileInfo, string $key, $iterator) use ($workspaceRoot): bool {
                $relativePath = self::relativePath($workspaceRoot, $fileInfo->getPathname());

                if ($fileInfo->isDir()) {
                    return !self::isExcludedPath($relativePath . '/');
                }

                return true;
            }
        );

        $iterator = new RecursiveIteratorIterator(
            $filteredIterator,
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof SplFileInfo || !$fileInfo->isFile()) {
                continue;
            }
            if (strtolower($fileInfo->getExtension()) !== 'php') {
                continue;
            }

            $relativePath = self::relativePath($workspaceRoot, $fileInfo->getPathname());
            if (self::isExcludedPath($relativePath)) {
                continue;
            }

            $files[] = $fileInfo->getPathname();
        }

        return $files;
    }

    private static function analyzePage(string $relativePath, string $filePath, string $content): array
    {
        $module = self::detectModule($relativePath);
        $hasAuthCheck = (bool)preg_match('/auth_check\.php/i', $content);
        $hasExplicitCoverage = (bool)preg_match('/(?:\\\\)?AuditTrail::logPageConsultation|(?:\\\\)?AuditTrail::logVisualEvent|(?:\\\\)?AuditTrail::logFileEvent/i', $content)
            || (bool)preg_match('/(?:\\\\)?AuditTrail::log\(\s*(?!["\'](?:request_access|auth_required)["\'])/i', $content);
        $isPublicIntentional = in_array($relativePath, self::PUBLIC_INTENTIONAL, true);

        $status = 'uncovered';
        $coverageLabel = 'Aucune convention détectée';
        if ($isPublicIntentional) {
            $status = 'public_intentional';
            $coverageLabel = 'Page publique / intentionnellement hors flux authentifié';
        } elseif ($hasAuthCheck) {
            $status = 'covered_auth_check';
            $coverageLabel = 'Couverture standard via auth_check';
        } elseif ($hasExplicitCoverage) {
            $status = 'covered_explicit';
            $coverageLabel = 'Couverture explicite via AuditTrail';
        }

        $riskSignals = self::detectRiskSignals($relativePath, $content);

        return [
            'path' => $relativePath,
            'module' => $module,
            'status' => $status,
            'coverage_label' => $coverageLabel,
            'has_auth_check' => $hasAuthCheck,
            'has_explicit_coverage' => $hasExplicitCoverage,
            'modified_at' => date('Y-m-d H:i:s', filemtime($filePath) ?: time()),
            'is_risky' => $riskSignals !== [],
            'risk_signals' => $riskSignals,
        ];
    }

    private static function detectActionCandidates(array $pages, string $workspaceRoot): array
    {
        $candidates = [];

        foreach ($pages as $page) {
            if ($page['status'] === 'public_intentional' || !self::isBusinessActionCandidatePath((string) ($page['path'] ?? ''))) {
                continue;
            }

            $filePath = $workspaceRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $page['path']);
            $content = @file_get_contents($filePath);
            if (!is_string($content)) {
                continue;
            }

            $signals = [];
            if (preg_match('/\$_POST|\$_FILES|php:\/\/input/i', $content)) {
                $signals[] = 'input_ecriture';
            }
            if (preg_match('/\bINSERT\s+INTO\b|\bUPDATE\s+[^=]+\s+SET\b|\bDELETE\s+FROM\b/i', $content)) {
                $signals[] = 'sql_mutation';
            }
            if (preg_match('/->\s*(create|update|delete|insert|save|upsert|supprimer|reactiver|desactiver|modifier|valider|refuser)[A-Za-z0-9_]*\s*\(/i', $content)) {
                $signals[] = 'appel_metier_mutation';
            }

            if ($signals === []) {
                continue;
            }

            $hasFineAudit = (bool)preg_match('/(?:\\\\)?AuditTrail::logFileEvent|(?:\\\\)?AuditTrail::logVisualEvent/i', $content)
                || (bool)preg_match('/(?:\\\\)?AuditTrail::log\(\s*(?!["\'](?:page_view|request_access|auth_required)["\'])/i', $content);

            if ($hasFineAudit) {
                continue;
            }

            $candidates[] = [
                'path' => $page['path'],
                'module' => $page['module'],
                'signals' => $signals,
                'coverage_label' => $page['coverage_label'],
                'status' => $page['status'],
            ];
        }

        usort($candidates, static function (array $left, array $right): int {
            return [count($right['signals']), $left['path']] <=> [count($left['signals']), $right['path']];
        });

        return array_slice($candidates, 0, 40);
    }

    private static function detectModule(string $relativePath): string
    {
        $segments = explode('/', $relativePath);
        $firstSegment = $segments[0] ?? 'core';
        return in_array($firstSegment, self::MODULES, true) ? $firstSegment : 'core';
    }

    private static function isExcludedPath(string $relativePath): bool
    {
        foreach (self::EXCLUDED_PREFIXES as $prefix) {
            if (strpos($relativePath, $prefix) === 0) {
                return true;
            }
        }

        $baseName = basename($relativePath);
        if (preg_match('/^(debug_|check_table_|check_columns|create_table_|verify_table_)/i', $baseName)) {
            return true;
        }

        $segments = explode('/', $relativePath);
        foreach ($segments as $segment) {
            if (in_array($segment, ['model', 'models', 'config', 'cron', 'tools', 'headers', 'lib', 'service', 'phpmailer'], true)) {
                return true;
            }
        }

        return false;
    }

    private static function isNavigationPagePath(string $relativePath): bool
    {
        $segments = explode('/', $relativePath);
        if ($segments === []) {
            return false;
        }

        if (count($segments) === 1) {
            return true;
        }

        foreach ($segments as $segment) {
            if (in_array($segment, ['model', 'models', 'config', 'cron', 'tools', 'headers', 'lib', 'service', 'api', 'request', 'includes', 'phpmailer'], true)) {
                return false;
            }
        }

        return true;
    }

    private static function isBusinessActionCandidatePath(string $relativePath): bool
    {
        $segments = explode('/', $relativePath);
        foreach ($segments as $segment) {
            if (in_array($segment, ['model', 'models', 'config', 'cron', 'tools', 'headers', 'lib', 'phpmailer'], true)) {
                return false;
            }
        }

        return true;
    }

    private static function looksLikeVisualPage(string $relativePath, string $content): bool
    {
        if (preg_match('/<!doctype\s+html|<html\b|<body\b|<form\b|<table\b|<section\b|<main\b|<div\b/i', $content)) {
            return true;
        }

        if (preg_match('/includes\/header\.php|includes\/navbar\.php|decaissement\/includes\/header\.php|decaissement\/includes\/navbar\.php/i', $content)) {
            return true;
        }

        return preg_match('/\.php$/i', basename($relativePath)) === 1 && preg_match('/echo\s+["\']\s*</i', $content) === 1;
    }

    private static function detectRiskSignals(string $relativePath, string $content): array
    {
        $signals = [];

        if (preg_match('/<form\b/i', $content) || preg_match('/\$_POST|\$_FILES|php:\/\/input/i', $content)) {
            $signals[] = 'saisie';
        }
        if (preg_match('/\bINSERT\s+INTO\b|\bUPDATE\s+[^=]+\s+SET\b|\bDELETE\s+FROM\b/i', $content)) {
            $signals[] = 'sql_mutation';
        }
        if (preg_match('/->\s*(create|update|delete|insert|save|upsert|supprimer|reactiver|desactiver|modifier|valider|refuser|approuver|rejeter|payer|signer)[A-Za-z0-9_]*\s*\(/i', $content)) {
            $signals[] = 'appel_metier_mutation';
        }
        if (preg_match('/\b(mail\(|send(mail|_mail)?|notify|notification|header\s*\(\s*["\']Location:)/i', $content)) {
            $signals[] = 'notification_ou_redirection';
        }
        if (preg_match('/\b(upload|move_uploaded_file|fopen\s*\(|file_put_contents\s*\()/i', $content)) {
            $signals[] = 'fichier';
        }
        if (preg_match('/(^|\/)(admin|api|request|decaissement\/request)\//i', $relativePath)) {
            $signals[] = 'zone_sensible';
        }

        return array_values(array_unique($signals));
    }

    private static function relativePath(string $workspaceRoot, string $absolutePath): string
    {
        $normalizedRoot = str_replace('\\', '/', rtrim($workspaceRoot, '\\/'));
        $normalizedPath = str_replace('\\', '/', $absolutePath);
        return ltrim(substr($normalizedPath, strlen($normalizedRoot)), '/');
    }
}
