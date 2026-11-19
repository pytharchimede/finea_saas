<?php

declare(strict_types=1);

/**
 * Migration des FEB legacy (tables fiche_expression_besoin/besoin_expression/expression_besoin_files)
 * vers la nouvelle logique employee_legal_requests (request_type = 'feb').
 *
 * Objectif: conserver TOUTES les données legacy, en les stockant dans le payload JSON,
 * et permettre l'affichage dans les écrans modernes (Finances + Employé).
 *
 * Usage:
 *   php tools/migrate_legacy_feb_to_modern.php --source=projet
 *   php tools/migrate_legacy_feb_to_modern.php --source=app --dry-run
 *   php tools/migrate_legacy_feb_to_modern.php --source=projet --target=app
 *
 * Options:
 *   --source=projet|app (défaut: projet)
 *   --target=projet|app (défaut: projet)
 *   --dry-run (n'écrit rien)
 *   --limit=N (défaut: 0 = tout)
 */

require_once __DIR__ . '/lib/feb_legacy_migration.php';

$source = strtolower(trim((string) feb_migration_arg_value($argv, '--source', 'projet')));
if (!in_array($source, ['projet', 'app'], true)) {
    fwrite(STDERR, "Option --source invalide (projet|app)\n");
    exit(2);
}

$dryRun = feb_migration_has_flag($argv, '--dry-run');
$limitRaw = (string) feb_migration_arg_value($argv, '--limit', '0');
$limit = is_numeric($limitRaw) ? (int) $limitRaw : 0;
$limit = max(0, $limit);

$target = strtolower(trim((string) feb_migration_arg_value($argv, '--target', 'projet')));
if (!in_array($target, ['projet', 'app'], true)) {
    fwrite(STDERR, "Option --target invalide (projet|app)\n");
    exit(2);
}

try {
    $stats = migrate_legacy_feb_to_modern(
        $source,
        $dryRun,
        $limit,
        function (string $level, string $message) use ($dryRun): void {
            if ($level === 'error') {
                fwrite(STDERR, $message . "\n");
                return;
            }
            if ($dryRun && $level === 'info') {
                echo $message . "\n";
            }
        },
        $target
    );

    echo json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    exit(($stats['errors'] ?? 0) > 0 ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, "Migration failed: {$e->getMessage()}\n");
    exit(1);
}
