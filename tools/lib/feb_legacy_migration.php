<?php

declare(strict_types=1);

require_once __DIR__ . '/../../model/Database.php';
require_once __DIR__ . '/../../decaissement/model/DatabaseApp.php';
require_once __DIR__ . '/../../model/EmployeeLegalRequestManager.php';

function feb_migration_arg_value(array $argv, string $name, ?string $default = null): ?string
{
    foreach ($argv as $a) {
        if (strpos($a, $name . '=') === 0) {
            return substr($a, strlen($name) + 1);
        }
    }
    return $default;
}

function feb_migration_has_flag(array $argv, string $name): bool
{
    return in_array($name, $argv, true);
}

function feb_normalize_legacy_status_to_modern(string $legacyStatut): array
{
    $raw = trim(function_exists('mb_strtolower') ? (string) mb_strtolower($legacyStatut, 'UTF-8') : strtolower($legacyStatut));
    if ($raw === '') {
        return ['status' => 'submitted', 'tag' => 'pending'];
    }

    if (strpos($raw, 'refus') !== false) {
        return ['status' => 'rejected', 'tag' => 'refused'];
    }
    if (strpos($raw, 'annul') !== false || strpos($raw, 'cancel') !== false) {
        return ['status' => 'cancelled', 'tag' => 'cancelled'];
    }
    if (strpos($raw, 'valid') !== false || strpos($raw, 'accept') !== false || strpos($raw, 'approuv') !== false) {
        return ['status' => 'approved', 'tag' => 'approved'];
    }
    if (strpos($raw, 'decaiss') !== false || strpos($raw, 'decais') !== false || strpos($raw, 'term') !== false) {
        return ['status' => 'approved', 'tag' => 'decaissed'];
    }

    return ['status' => 'submitted', 'tag' => 'unknown'];
}

function feb_safe_uploads_url_from_legacy_path(string $filePath): string
{
    $p = trim($filePath);
    if ($p === '') {
        return '';
    }

    $p = str_replace('\\', '/', $p);

    if (strpos($p, '/uploads/') === 0) {
        return $p;
    }
    if (strpos($p, 'uploads/') === 0) {
        return '/' . $p;
    }
    if (strpos($p, '../uploads/') === 0) {
        return '/' . substr($p, 3);
    }

    return '';
}

function feb_source_pdo(string $source): PDO
{
    $source = strtolower(trim($source));
    return $source === 'app' ? DatabaseApp::getConnection() : Database::getConnection();
}

function feb_target_pdo(string $target): PDO
{
    $target = strtolower(trim($target));
    return $target === 'app' ? DatabaseApp::getConnection() : Database::getConnection();
}

function feb_norm_text_for_key(?string $s): string
{
    $s = (string) ($s ?? '');
    $s = trim($s);
    if ($s === '') {
        return '';
    }
    // Normalisation légère: espaces multiples -> 1
    $s = preg_replace('/\s+/u', ' ', $s);
    return trim((string) ($s ?? ''));
}

function feb_norm_matricule_for_key(?string $s): string
{
    $s = strtoupper(trim((string) ($s ?? '')));
    $s = preg_replace('/\s+/u', '', $s);
    return (string) ($s ?? '');
}

function feb_norm_money_for_key($v): string
{
    $f = (float) ($v ?? 0);
    return number_format($f, 2, '.', '');
}

/**
 * Construit une clé stable (hash) pour dédoublonner une FEB legacy entre 2 bases.
 * On exclut volontairement les champs volatils (statut, matricule_status).
 */
function feb_legacy_make_dedupe_key(array $fiche, array $besoinsRows, array $filesRows): string
{
    $besoinsCanon = [];
    foreach ($besoinsRows as $b) {
        $besoinsCanon[] = [
            'type' => feb_norm_text_for_key($b['type'] ?? ''),
            'objet' => feb_norm_text_for_key($b['objet'] ?? ''),
            'quantite' => (int) ($b['quantite'] ?? 0),
            'fournisseur' => (int) (!empty($b['fournisseur']) ? 1 : 0),
            'nom_fournisseur' => feb_norm_text_for_key($b['nom_fournisseur'] ?? ''),
            'prix_unitaire' => feb_norm_money_for_key($b['prix_unitaire'] ?? 0),
            'telephone' => feb_norm_text_for_key($b['telephone'] ?? ''),
        ];
    }
    usort($besoinsCanon, static function (array $a, array $b): int {
        return strcmp(json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '', json_encode($b, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    });

    // IMPORTANT: on ne met PAS les fichiers dans la clé de dédoublonnage.
    // Raison: entre 2 bases, les rows `expression_besoin_files` peuvent diverger (uploads pas répliqués),
    // et on éviterait mal les doublons côté moderne.
    // (Le tableau $filesRows est conservé dans la signature pour compatibilité, mais volontairement ignoré.)

    $codeAutorisation = feb_norm_text_for_key($fiche['code_autorisation'] ?? '');
    $codeAutorisationLower = function_exists('mb_strtolower') ? (string) mb_strtolower($codeAutorisation, 'UTF-8') : strtolower($codeAutorisation);
    $codeAutorisationLower = trim($codeAutorisationLower);
    $includeCode = ($codeAutorisation !== ''
        && $codeAutorisationLower !== 'pas autorise'
        && $codeAutorisationLower !== 'pas autorisé'
        && $codeAutorisation !== '0001'
        && strlen($codeAutorisation) >= 6);

    $canonical = [
        'matricule' => feb_norm_matricule_for_key($fiche['matricule'] ?? ''),
        'departement' => (int) ($fiche['departement'] ?? 0),
        'nom' => feb_norm_text_for_key($fiche['nom'] ?? ''),
        'description' => feb_norm_text_for_key($fiche['description'] ?? ''),
        'impact' => feb_norm_text_for_key($fiche['impact'] ?? ''),
        'montant' => feb_norm_money_for_key($fiche['montant'] ?? 0),
        'date' => feb_norm_text_for_key($fiche['date'] ?? ''),
        'frequence' => feb_norm_text_for_key($fiche['frequence'] ?? ''),
        'code_autorisation' => $includeCode ? $codeAutorisation : '',
        'besoins' => $besoinsCanon,
    ];

    $json = json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        // Fallback ultra-safe
        $json = serialize($canonical);
    }

    return sha1((string) $json);
}

function feb_assert_legacy_tables_exist(PDO $pdo): void
{
    $required = ['fiche_expression_besoin', 'besoin_expression', 'expression_besoin_files'];

    $missing = [];
    foreach ($required as $t) {
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($t));
            $exists = (string) ($stmt->fetchColumn() ?: '');
            if ($exists === '') {
                $missing[] = $t;
            }
        } catch (Throwable $e) {
            $missing[] = $t;
        }
    }

    if ($missing) {
        throw new RuntimeException('Tables legacy introuvables: ' . implode(', ', $missing));
    }
}

/**
 * Migre les FEB legacy vers employee_legal_requests.
 *
 * @return array{total:int, imported:int, skipped:int, errors:int}
 */
function migrate_legacy_feb_to_modern(
    string $source = 'projet',
    bool $dryRun = false,
    int $limit = 0,
    ?callable $log = null,
    string $target = 'projet'
): array {
    $source = strtolower(trim($source));
    if (!in_array($source, ['projet', 'app'], true)) {
        throw new InvalidArgumentException('Option --source invalide (projet|app)');
    }

    $target = strtolower(trim($target));
    if (!in_array($target, ['projet', 'app'], true)) {
        throw new InvalidArgumentException('Option --target invalide (projet|app)');
    }

    $limit = max(0, $limit);

    $sourcePdo = feb_source_pdo($source);
    $targetPdo = feb_target_pdo($target);

    // Pré-check: tables legacy présentes dans la base source.
    feb_assert_legacy_tables_exist($sourcePdo);

    // Ensure schema exists/upgrades
    new EmployeeLegalRequestManager($targetPdo);

    $serviceLabels = [];
    try {
        $stmt = $targetPdo->query('SELECT id_service AS id, lib_service AS label FROM service');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $serviceLabels[(int) ($r['id'] ?? 0)] = (string) ($r['label'] ?? '');
        }
    } catch (Throwable $e) {
        // Tolérer
    }

    $checkImportedByTuple = $targetPdo->prepare('
        SELECT id
        FROM employee_legal_requests
        WHERE meta_legacy_source = :src AND meta_legacy_id = :lid
        LIMIT 1
    ');

    $checkImportedByKey = $targetPdo->prepare('
        SELECT id
        FROM employee_legal_requests
        WHERE meta_legacy_source = :src AND meta_legacy_key = :lkey
        LIMIT 1
    ');

    $upgradeExistingRow = $targetPdo->prepare('
        UPDATE employee_legal_requests
        SET meta_legacy_source = :src_new,
            meta_legacy_key = :lkey
        WHERE id = :id
        LIMIT 1
    ');

    $insertRequest = $targetPdo->prepare('
        INSERT INTO employee_legal_requests (
            requester_user_id,
            requester_name,
            requester_email,
            request_type,
            assigned_team,
            title,
            payload_json,
            meta_service_id,
            meta_legacy_source,
            meta_legacy_id,
            meta_legacy_key,
            status,
            submitted_at
        ) VALUES (
            :requester_user_id,
            :requester_name,
            :requester_email,
            "feb",
            "finance",
            :title,
            :payload_json,
            :meta_service_id,
            :meta_legacy_source,
            :meta_legacy_id,
            :meta_legacy_key,
            :status,
            :submitted_at
        )
    ');

    $insertEvent = $targetPdo->prepare('
        INSERT INTO employee_legal_request_events (
            request_id,
            event_type,
            event_note,
            event_by_user_id,
            event_by_name,
            created_at
        ) VALUES (
            :request_id,
            :event_type,
            :event_note,
            :event_by_user_id,
            :event_by_name,
            :created_at
        )
    ');

    $getBesoins = $sourcePdo->prepare('SELECT * FROM besoin_expression WHERE expression_besoin_id = :fid ORDER BY id ASC');
    $getFiles = $sourcePdo->prepare('SELECT * FROM expression_besoin_files WHERE expression_besoin_id = :fid ORDER BY id ASC');

    $sqlFiches = 'SELECT * FROM fiche_expression_besoin ORDER BY id ASC';
    if ($limit > 0) {
        $sqlFiches .= ' LIMIT ' . (int) $limit;
    }

    $fiches = $sourcePdo->query($sqlFiches)->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $stats = [
        'total' => count($fiches),
        'imported' => 0,
        'skipped' => 0,
        'errors' => 0,
    ];

    foreach ($fiches as $fiche) {
        $legacyId = (int) ($fiche['id'] ?? 0);
        if ($legacyId <= 0) {
            continue;
        }

        $getBesoins->execute(['fid' => $legacyId]);
        $besoinsRows = $getBesoins->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $getFiles->execute(['fid' => $legacyId]);
        $filesRows = $getFiles->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $legacyKey = feb_legacy_make_dedupe_key($fiche, $besoinsRows, $filesRows);

        // 1) Compat: si déjà importé via l'ancien couple (source,id), on skip mais on tente de backfiller la clé.
        $checkImportedByTuple->execute(['src' => 'feb_legacy_' . $source, 'lid' => $legacyId]);
        $alreadyTupleId = (int) ($checkImportedByTuple->fetchColumn() ?: 0);
        if ($alreadyTupleId > 0) {
            if (!$dryRun) {
                try {
                    $upgradeExistingRow->execute([
                        'src_new' => 'feb_legacy',
                        'lkey' => $legacyKey,
                        'id' => $alreadyTupleId,
                    ]);
                } catch (Throwable $e) {
                    // Tolérer (ex: UNIQUE KEY empêche la mise à jour si doublon historique)
                }
            }
            $stats['skipped']++;
            continue;
        }

        // 2) Dédoublonnage cross-bases: si une FEB identique (au sens clé) existe déjà, on skip.
        $checkImportedByKey->execute(['src' => 'feb_legacy', 'lkey' => $legacyKey]);
        $alreadyKeyId = (int) ($checkImportedByKey->fetchColumn() ?: 0);
        if ($alreadyKeyId > 0) {
            $stats['skipped']++;
            continue;
        }

        $legacyMatricule = strtoupper(trim((string) ($fiche['matricule'] ?? '')));
        $legacyNom = trim((string) ($fiche['nom'] ?? ''));
        $legacyDepartementId = (int) ($fiche['departement'] ?? 0);
        $legacyServiceLabel = (string) ($serviceLabels[$legacyDepartementId] ?? '');

        $besoins = [];
        foreach ($besoinsRows as $b) {
            $hasFournisseur = !empty($b['fournisseur']);
            $besoins[] = [
                'type' => (string) ($b['type'] ?? ''),
                'libelle' => (string) ($b['objet'] ?? ''),
                'quantite' => (int) ($b['quantite'] ?? 0),
                'has_fournisseur' => $hasFournisseur,
                'fournisseur' => $hasFournisseur ? [
                    'nom' => (string) ($b['nom_fournisseur'] ?? ''),
                    'prix_unitaire' => (float) ($b['prix_unitaire'] ?? 0),
                    'telephone' => (string) ($b['telephone'] ?? ''),
                ] : null,
                'legacy' => $b,
            ];
        }


        $justificatifs = [];
        foreach ($filesRows as $f) {
            $rawPath = (string) ($f['file_path'] ?? '');
            $safeUrl = feb_safe_uploads_url_from_legacy_path($rawPath);
            $justificatifs[] = [
                'name' => basename(str_replace('\\', '/', $rawPath)) ?: ('justificatif_' . (int) ($f['id'] ?? 0)),
                'url' => $safeUrl,
                'size' => 0,
                'type' => (string) ($f['file_type'] ?? ''),
                'legacy' => $f,
            ];
        }

        $legacyStatut = (string) ($fiche['statut'] ?? '');
        $mapped = feb_normalize_legacy_status_to_modern($legacyStatut);

        $legacyDate = (string) ($fiche['date'] ?? '');
        if ($legacyDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $legacyDate)) {
            $submittedAt = $legacyDate . ' 00:00:00';
        } else {
            $submittedAt = date('Y-m-d H:i:s');
        }

        $payload = [
            'nom' => $legacyNom,
            'matricule' => $legacyMatricule,
            'departement_id' => $legacyDepartementId,
            'departement_label' => $legacyServiceLabel,
            'description' => (string) ($fiche['description'] ?? ''),
            'impact' => (string) ($fiche['impact'] ?? ''),
            'montant' => (float) ($fiche['montant'] ?? 0),
            'date_livraison' => '',
            'frequence' => (string) ($fiche['frequence'] ?? ''),
            'besoins' => $besoins,
            'justificatifs' => $justificatifs,
            'legacy' => [
                'source' => 'feb_legacy',
                'db_source' => $source,
                'db_target' => $target,
                'fiche' => $fiche,
                'besoins' => $besoinsRows,
                'files' => $filesRows,
                'status_tag' => (string) ($mapped['tag'] ?? ''),
            ],
        ];

        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payloadJson === false) {
            $stats['errors']++;
            if ($log) {
                $log('error', "Erreur JSON pour legacy id {$legacyId}");
            }
            continue;
        }

        $title = 'FEB legacy #' . $legacyId;
        if ($legacyServiceLabel !== '') {
            $title .= ' — ' . $legacyServiceLabel;
        }

        if ($dryRun) {
            $stats['imported']++;
            if ($log) {
                $log('info', "[DRY] import legacy_id={$legacyId} status={$mapped['status']} submitted_at={$submittedAt}");
            }
            continue;
        }

        try {
            $targetPdo->beginTransaction();

            try {
                $insertRequest->execute([
                    'requester_user_id' => 0,
                    'requester_name' => $legacyNom,
                    'requester_email' => null,
                    'title' => $title,
                    'payload_json' => $payloadJson,
                    'meta_service_id' => $legacyDepartementId > 0 ? $legacyDepartementId : null,
                    'meta_legacy_source' => 'feb_legacy',
                    'meta_legacy_id' => $legacyId,
                    'meta_legacy_key' => $legacyKey,
                    'status' => (string) ($mapped['status'] ?? 'submitted'),
                    'submitted_at' => $submittedAt,
                ]);
            } catch (Throwable $e) {
                // Si la contrainte unique est présente, une exécution concurrente peut tomber ici.
                // On re-check pour être sûr et on considère comme "skipped".
                $checkImportedByKey->execute(['src' => 'feb_legacy', 'lkey' => $legacyKey]);
                $raceId = (int) ($checkImportedByKey->fetchColumn() ?: 0);
                if ($raceId > 0) {
                    $targetPdo->commit();
                    $stats['skipped']++;
                    continue;
                }
                throw $e;
            }

            $newId = (int) $targetPdo->lastInsertId();

            $insertEvent->execute([
                'request_id' => $newId,
                'event_type' => 'imported',
                'event_note' => 'Import FEB legacy #' . $legacyId . ' (' . $source . ' -> ' . $target . ')',
                'event_by_user_id' => null,
                'event_by_name' => 'migration',
                'created_at' => $submittedAt,
            ]);

            if (($mapped['status'] ?? 'submitted') !== 'submitted') {
                $insertEvent->execute([
                    'request_id' => $newId,
                    'event_type' => (string) ($mapped['status'] ?? 'submitted'),
                    'event_note' => 'Statut legacy: ' . trim($legacyStatut),
                    'event_by_user_id' => null,
                    'event_by_name' => 'migration',
                    'created_at' => $submittedAt,
                ]);
            }

            $targetPdo->commit();
            $stats['imported']++;
        } catch (Throwable $e) {
            try {
                $targetPdo->rollBack();
            } catch (Throwable $e2) {
            }
            $stats['errors']++;
            if ($log) {
                $log('error', "Erreur import legacy id {$legacyId}: {$e->getMessage()}");
            }
        }
    }

    return $stats;
}
