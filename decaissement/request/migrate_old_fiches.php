<?php
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/DatabaseApp.php';
require_once __DIR__ . '/../model/FicheDecaissementMigrator.php';
require_once __DIR__ . '/../../model/AuditTrail.php';

header('Content-Type: application/json; charset=utf-8');

function mig_json_error(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function mig_norm_code(string $s): string
{
    $s = trim($s);
    $s = strtoupper($s);
    return $s;
}

function mig_table_exists(PDO $pdo, string $table): bool
{
    $st = $pdo->prepare('SHOW TABLES LIKE :t');
    $st->execute(['t' => $table]);
    return (bool)$st->fetchColumn();
}

/** @return string[] */
function mig_get_columns(PDO $pdo, string $table): array
{
    $st = $pdo->query('DESCRIBE ' . $table);
    $rows = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    $out = [];
    foreach ($rows as $r) {
        $c = (string)($r['Field'] ?? '');
        if ($c !== '') $out[] = $c;
    }
    return $out;
}

function mig_resolve_new_chantier(PDO $pdoNew, string $chantierCode): ?array
{
    $code = mig_norm_code($chantierCode);
    if ($code === '') return null;

    $st = $pdoNew->prepare('SELECT id, titre, code, devis_id FROM chantier_banamur WHERE code = :c LIMIT 1');
    $st->execute(['c' => $code]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if ($r) return $r;

    // Tolérance: CH 038 / CH-038 / etc.
    $noSep = preg_replace('/[^A-Z0-9]/', '', $code);
    if (!$noSep) return null;
    $st2 = $pdoNew->prepare("SELECT id, titre, code, devis_id FROM chantier_banamur WHERE REPLACE(REPLACE(TRIM(UPPER(code)),'-',''),' ','') = :c LIMIT 1");
    $st2->execute(['c' => $noSep]);
    $r2 = $st2->fetch(PDO::FETCH_ASSOC);
    return $r2 ?: null;
}

/**
 * Retourne [whereSql, params] pour cibler les lignes de fiche_decaissement provenant de la migration.
 * La condition utilise les colonnes de traçabilité disponibles (secur_conforme/secur_valid/secur_decaisse).
 */
function mig_build_refresh_scope(int $chantierId, array $newFicheCols): array
{
    $set = array_fill_keys($newFicheCols, true);
    $where = [];
    $params = ['chantier_id' => $chantierId, 'mig' => 'MIGRATION'];

    if (isset($set['chantier_id'])) {
        $where[] = 'chantier_id = :chantier_id';
    } else {
        // sans chantier_id, pas de purge sûre
        return ['', []];
    }

    $markers = [];
    foreach (['secur_conforme', 'secur_valid', 'secur_decaisse'] as $c) {
        if (isset($set[$c])) {
            $markers[] = $c . ' = :mig';
        }
    }
    if (!$markers) {
        return ['', []];
    }
    $where[] = '(' . implode(' OR ', $markers) . ')';

    return [implode(' AND ', $where), $params];
}

if (!hasPermission('acces_admin')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Accès refusé (admin requis)']);
    exit;
}

$action = (string)($_REQUEST['action'] ?? 'preview');
$chantierCode = (string)($_REQUEST['chantier_code'] ?? '');
$oldChantierId = isset($_REQUEST['old_chantier_id']) && $_REQUEST['old_chantier_id'] !== '' ? (int)$_REQUEST['old_chantier_id'] : null;

// Mode "payé uniquement": migrer seulement les montants réellement payés (decaissement.montant>0)
// et ignorer les fiches marquées décaissées sans lignes de table decaissement.
$paidOnly = !empty($_REQUEST['paid_only']);

$autoValidateMigDevis = !empty($_REQUEST['auto_validate_mig_devis']);
$autoPublishMigDevis = !empty($_REQUEST['auto_publish_mig_devis']);
if ($autoPublishMigDevis && !$autoValidateMigDevis) {
    // Publier sans valider n'a pas de sens métier ici; on force la validation si publish est demandé.
    $autoValidateMigDevis = true;
}

try {
    $pdoNew = Database::getConnection();
    $pdoOld = DatabaseApp::getConnection();

    $migrator = new FicheDecaissementMigrator($pdoNew, $pdoOld, [
        'autoValidateMigDevis' => $autoValidateMigDevis,
        'autoPublishMigDevis' => $autoPublishMigDevis,
        'paidOnly' => $paidOnly,
    ]);

    if ($action === 'preview') {
        $preview = $migrator->previewByChantierCode($chantierCode, $oldChantierId);
        AuditTrail::log('migrate_old_fiches_previewed', [
            'module' => 'decaissement',
            'action' => 'migrate_old_fiches_preview',
            'entity_type' => 'fiche_decaissement_migration',
            'entity_label' => 'Prévisualisation migration anciennes fiches',
            'result' => 'success',
            'details' => [
                'chantier_code' => $chantierCode,
                'old_chantier_id' => $oldChantierId,
                'paid_only' => $paidOnly,
            ],
        ], $pdoNew);
        echo json_encode($preview);
        exit;
    }

    if ($action === 'refresh_preview' || $action === 'refresh_run') {
        $chantierCode = (string)($_REQUEST['chantier_code'] ?? '');
        $chantierCode = mig_norm_code($chantierCode);
        if ($chantierCode === '') {
            mig_json_error(400, 'Code chantier manquant');
        }

        if (!mig_table_exists($pdoNew, 'fiche_decaissement')) {
            mig_json_error(400, "Table 'fiche_decaissement' introuvable dans la nouvelle base");
        }

        $newChantier = mig_resolve_new_chantier($pdoNew, $chantierCode);
        if (!$newChantier) {
            mig_json_error(404, 'Chantier introuvable dans la nouvelle base (code: ' . $chantierCode . ')');
        }
        $newChantierId = (int)($newChantier['id'] ?? 0);
        if ($newChantierId <= 0) {
            mig_json_error(400, 'ID chantier nouveau non résolu');
        }

        $cols = mig_get_columns($pdoNew, 'fiche_decaissement');
        [$whereSql, $params] = mig_build_refresh_scope($newChantierId, $cols);
        if ($whereSql === '') {
            mig_json_error(400, 'Impossible de déterminer un scope de purge sûr (colonnes de traçabilité manquantes)');
        }

        $sumExpr = in_array('montant_fiche', $cols, true) ? 'COALESCE(SUM(montant_fiche),0) AS total_montant' : '0 AS total_montant';
        $minMaxExpr = '';
        if (in_array('created_at', $cols, true)) {
            $minMaxExpr = ', MIN(created_at) AS min_created_at, MAX(created_at) AS max_created_at';
        } elseif (in_array('date_creat_fiche', $cols, true)) {
            $minMaxExpr = ', MIN(date_creat_fiche) AS min_created_at, MAX(date_creat_fiche) AS max_created_at';
        }

        $stAgg = $pdoNew->prepare('SELECT COUNT(*) AS nb, ' . $sumExpr . $minMaxExpr . ' FROM fiche_decaissement WHERE ' . $whereSql);
        $stAgg->execute($params);
        $agg = $stAgg->fetch(PDO::FETCH_ASSOC) ?: [];

        $nb = (int)($agg['nb'] ?? 0);
        $total = (float)($agg['total_montant'] ?? 0);

        // Échantillon
        $selectCols = ['num_fiche'];
        foreach (['montant_fiche', 'created_at', 'date_creat_fiche', 'secur_conforme', 'secur_valid', 'secur_decaisse'] as $c) {
            if (in_array($c, $cols, true)) $selectCols[] = $c;
        }
        $stSample = $pdoNew->prepare('SELECT ' . implode(',', $selectCols) . ' FROM fiche_decaissement WHERE ' . $whereSql . ' ORDER BY id_fiche DESC LIMIT 30');
        $stSample->execute($params);
        $sample = $stSample->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if ($action === 'refresh_preview' || ($action === 'refresh_run' && !empty($_REQUEST['dry_run']))) {
            AuditTrail::log('migrate_old_fiches_refresh_previewed', [
                'module' => 'decaissement',
                'action' => 'migrate_old_fiches_refresh_preview',
                'entity_type' => 'fiche_decaissement_migration',
                'entity_label' => 'Prévisualisation purge migration',
                'result' => 'success',
                'details' => [
                    'chantier_code' => $chantierCode,
                    'nb_fiches_a_purger' => $nb,
                    'total_montant' => $total,
                ],
            ], $pdoNew);
            echo json_encode([
                'ok' => true,
                'chantier_code' => $chantierCode,
                'new' => [
                    'id' => $newChantierId,
                    'titre' => $newChantier['titre'] ?? '',
                    'devis_id' => $newChantier['devis_id'] ?? null,
                ],
                'scope' => [
                    'where' => $whereSql,
                    'markers' => array_values(array_intersect($cols, ['secur_conforme', 'secur_valid', 'secur_decaisse'])),
                ],
                'stats' => [
                    'nb_fiches_a_purger' => $nb,
                    'total_montant' => $total,
                    'min_created_at' => $agg['min_created_at'] ?? null,
                    'max_created_at' => $agg['max_created_at'] ?? null,
                ],
                'sample' => $sample,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // refresh_run exécute la purge
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            mig_json_error(405, 'POST uniquement');
        }
        if (empty($_POST['confirm'])) {
            mig_json_error(400, 'Confirmation manquante (confirm=1)');
        }

        $deletedTrace = 0;
        $deletedFiches = 0;

        $pdoNew->beginTransaction();
        try {
            if (mig_table_exists($pdoNew, 'trace_fiche')) {
                // Supprimer les traces associées aux fiches à purger.
                // IMPORTANT: selon les bases, les collations de num_fiche peuvent différer (unicode vs general).
                // On force une collation identique pour éviter l'erreur MySQL 1267.
                $sqlDelTrace = 'DELETE t FROM trace_fiche t ' .
                    'INNER JOIN fiche_decaissement f ' .
                    'ON (f.num_fiche COLLATE utf8mb4_general_ci) = (t.num_fiche COLLATE utf8mb4_general_ci) ' .
                    'WHERE ' . $whereSql;
                $stDelTrace = $pdoNew->prepare($sqlDelTrace);
                $stDelTrace->execute($params);
                $deletedTrace = $stDelTrace->rowCount();
            }

            $stDel = $pdoNew->prepare('DELETE FROM fiche_decaissement WHERE ' . $whereSql);
            $stDel->execute($params);
            $deletedFiches = $stDel->rowCount();

            $pdoNew->commit();
        } catch (Throwable $e) {
            $pdoNew->rollBack();
            throw $e;
        }

        echo json_encode([
            'ok' => true,
            'chantier_code' => $chantierCode,
            'new' => [
                'id' => $newChantierId,
                'titre' => $newChantier['titre'] ?? '',
            ],
            'deleted' => [
                'fiche_decaissement' => $deletedFiches,
                'trace_fiche' => $deletedTrace,
            ],
            'before' => [
                'nb_fiches_a_purger' => $nb,
                'total_montant' => $total,
            ],
        ], JSON_UNESCAPED_UNICODE);
        AuditTrail::log('migrate_old_fiches_refreshed', [
            'module' => 'decaissement',
            'action' => 'migrate_old_fiches_refresh',
            'entity_type' => 'fiche_decaissement_migration',
            'entity_label' => 'Purge migration anciennes fiches',
            'result' => 'success',
            'details' => [
                'chantier_code' => $chantierCode,
                'deleted_fiches' => $deletedFiches,
                'deleted_trace' => $deletedTrace,
            ],
        ], $pdoNew);
        exit;
    }

    if ($action === 'run') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'error' => 'POST uniquement']);
            exit;
        }

        $offset = (int)($_POST['offset'] ?? 0);
        $limit = (int)($_POST['limit'] ?? 20);
        $dryRun = !empty($_POST['dry_run']);

        echo json_encode($migrator->migrateBatchByChantierCode($chantierCode, $offset, $limit, $dryRun, $oldChantierId));
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Action invalide']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
