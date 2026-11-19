<?php

/**
 * Normalisation idempotente du planning des déboursés.
 * Elle ne touche jamais aux montants historiques : seules les périodes sont réparées.
 */
final class DisbursementPlanningIntegrityService
{
    private const MIGRATION_KEY = 'disbursement_planning_integrity_v2_parent_period_20260916';

    public static function ensure(PDO $pdo): void
    {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS finea_data_migrations (
                migration_key VARCHAR(190) NOT NULL PRIMARY KEY,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                details_json LONGTEXT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $check=$pdo->prepare('SELECT 1 FROM finea_data_migrations WHERE migration_key=:migration LIMIT 1');
            $check->execute(['migration'=>self::MIGRATION_KEY]);
            if($check->fetchColumn())return;

            // La période du déboursé parent est la référence pour ses lignes.
            $fixedDebourses=$pdo->exec('UPDATE debourse_banamur d JOIN (SELECT id,LEAST(date_debut,date_fin) fixed_start,GREATEST(date_debut,date_fin) fixed_end FROM debourse_banamur WHERE date_debut IS NOT NULL AND date_fin IS NOT NULL AND date_fin<date_debut) x ON x.id=d.id SET d.date_debut=x.fixed_start,d.date_fin=x.fixed_end');
            $fixedLines=$pdo->exec('UPDATE ligne_debourse_banamur l JOIN debourse_banamur d ON d.id=l.debourse_id SET l.date_debut=d.date_debut,l.date_fin=d.date_fin WHERE d.date_debut IS NOT NULL AND d.date_fin IS NOT NULL AND (l.date_debut IS NULL OR l.date_fin IS NULL OR l.date_debut<>d.date_debut OR l.date_fin<>d.date_fin)');
            $details=json_encode(['fixed_debourses'=>(int)$fixedDebourses,'synchronized_lines'=>(int)$fixedLines],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $insert=$pdo->prepare('INSERT INTO finea_data_migrations(migration_key,details_json) VALUES(:migration,:details)');
            $insert->execute(['migration'=>self::MIGRATION_KEY,'details'=>$details]);
        } catch(Throwable $e) {
            // La première page du pipeline réessaiera automatiquement tant que le marqueur est absent.
        }
    }
}
