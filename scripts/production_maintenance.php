<?php
declare(strict_types=1);

/**
 * Maintenance post-déploiement FINEA.
 *
 * À exécuter en CLI, jamais depuis une requête web :
 *   php scripts/production_maintenance.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require_once $root . '/model/EnvConfig.php';
\EnvConfig::loadFromDirectory($root);
require_once $root . '/model/Database.php';
require_once $root . '/services/FebCategoryMigrationService.php';
require_once $root . '/services/FebWorkflowMigrationService.php';
require_once $root . '/services/DisbursementPlanningIntegrityService.php';
require_once $root . '/services/FebBeneficiarySyncService.php';
require_once $root . '/services/FebFicheAmountMigrationService.php';
require_once $root . '/services/DevisOfferProjectMigrationService.php';

$pdo = \Database::getConnection();
$startedAt = microtime(true);

$steps = [
    'devis_offer_project_schema' => static fn() => (new DevisOfferProjectMigrationService($pdo))->ensureSchema(),
    'feb_workflow_schema' => static fn() => FebWorkflowMigrationService::ensure($pdo),
    'disbursement_planning_integrity' => static fn() => DisbursementPlanningIntegrityService::ensure($pdo),
    'feb_beneficiary_sync' => static fn() => FebBeneficiarySyncService::syncApproved($pdo),
    'feb_approved_fiche_values' => static function () use ($pdo): void {
        $result = FebFicheAmountMigrationService::apply($pdo);
        fwrite(STDOUT, '[INFO] FEB fiches: ' . json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    },
];

foreach ($steps as $name => $step) {
    $stepStartedAt = microtime(true);
    fwrite(STDOUT, '[START] ' . $name . PHP_EOL);
    try {
        $step();
        fwrite(STDOUT, sprintf("[OK] %s %.3fs\n", $name, microtime(true) - $stepStartedAt));
    } catch (Throwable $e) {
        fwrite(STDERR, sprintf("[ERROR] %s: %s\n", $name, $e->getMessage()));
        exit(1);
    }
}

fwrite(STDOUT, sprintf("[DONE] production maintenance %.3fs\n", microtime(true) - $startedAt));
