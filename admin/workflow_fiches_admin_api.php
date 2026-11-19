<?php
require_once __DIR__ . '/../auth_check.php';
AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../decaissement/model/FicheDecaissement.php';
require_once __DIR__ . '/../model/AuditTrail.php';

header('Content-Type: application/json; charset=utf-8');

function admin_stage_code_from_row(array $row): string
{
    if ((int)($row['decaisse'] ?? 0) === 1) {
        return 'decaissee';
    }
    if ((int)($row['approuve'] ?? 0) === 2 || (int)($row['etat_fiche'] ?? 0) === 2) {
        return 'refusee';
    }
    if ((int)($row['etat_fiche'] ?? 0) === 1) {
        return 'tresorerie';
    }
    if ((int)($row['approuve'] ?? 0) === 1) {
        return 'validation_achat';
    }
    if ((int)($row['conforme'] ?? 0) === 1) {
        return 'approbation';
    }
    return 'conformite';
}

function admin_stage_label(string $code): string
{
    $map = [
        'conformite' => 'Conformité',
        'approbation' => 'Approbation',
        'validation_achat' => 'Validation achat',
        'tresorerie' => 'Traitement comptable',
        'refusee' => 'Refusée',
        'decaissee' => 'Décaissée',
    ];

    return $map[$code] ?? ucfirst(str_replace('_', ' ', $code));
}

function admin_extract_refusal_reason(array $row): string
{
    $motif = trim((string)($row['motif_refus'] ?? ''));
    if ($motif !== '') {
        return $motif;
    }

    $details = trim((string)($row['details_demande'] ?? ''));
    if ($details === '') {
        return '';
    }

    if (preg_match_all('/\[(REFUS(?:_ACHAT)?)[^\]]*\]\s*(.+)/ui', $details, $matches) && !empty($matches[2])) {
        $last = trim((string)end($matches[2]));
        if ($last !== '') {
            return $last;
        }
    }

    return '';
}

function admin_build_status_meta(array $row): array
{
    $conforme = (int)($row['conforme'] ?? 0);
    $approuve = (int)($row['approuve'] ?? 0);
    $etatFiche = (int)($row['etat_fiche'] ?? 0);
    $decaisse = (int)($row['decaisse'] ?? 0);

    if ($decaisse === 1) {
        return ['code' => 'decaissee', 'label' => 'Décaissée', 'tone' => 'emerald'];
    }
    if ($approuve === 2 || $etatFiche === 2) {
        return ['code' => 'refusee', 'label' => 'Refusée', 'tone' => 'rose'];
    }
    if ($etatFiche === 1) {
        return ['code' => 'tresorerie', 'label' => 'En trésorerie', 'tone' => 'indigo'];
    }
    if ($approuve === 1) {
        return ['code' => 'validation_achat', 'label' => 'Validée achat', 'tone' => 'cyan'];
    }
    if ($conforme === 1) {
        return ['code' => 'approbation', 'label' => 'Conforme, en attente approbation', 'tone' => 'amber'];
    }

    return ['code' => 'conformite', 'label' => 'En attente conformité', 'tone' => 'slate'];
}

function admin_resolve_suggested_target_stage(array $row): string
{
    if ((int)($row['decaisse'] ?? 0) === 1) {
        return 'decaissee';
    }
    if ((int)($row['etat_fiche'] ?? 0) === 2) {
        return (int)($row['approuve'] ?? 0) === 1 ? 'validation_achat' : 'approbation';
    }
    if ((int)($row['approuve'] ?? 0) === 2) {
        return (int)($row['conforme'] ?? 0) === 1 ? 'approbation' : 'conformite';
    }
    if ((int)($row['etat_fiche'] ?? 0) === 1) {
        return 'tresorerie';
    }
    if ((int)($row['approuve'] ?? 0) === 1) {
        return 'validation_achat';
    }
    if ((int)($row['conforme'] ?? 0) === 1) {
        return 'approbation';
    }
    return 'conformite';
}

function admin_normalize_num_fiches(string $raw): array
{
    $parts = preg_split('/[\s,;]+/', trim($raw)) ?: [];
    $items = [];
    foreach ($parts as $part) {
        $value = trim((string)$part);
        if ($value === '') {
            continue;
        }
        $items[$value] = true;
    }
    return array_keys($items);
}

function admin_project_row_to_stage(array $row, string $targetStage): array
{
    $targetStage = strtolower(trim($targetStage));
    $projected = $row;

    switch ($targetStage) {
        case 'conformite':
            $projected['conforme'] = 0;
            $projected['approuve'] = 0;
            $projected['etat_fiche'] = 0;
            $projected['decaisse'] = 0;
            $projected['date_conforme'] = null;
            $projected['date_approbation'] = null;
            $projected['date_valid'] = null;
            $projected['date_refuse'] = null;
            $projected['date_decaisse'] = null;
            $projected['date_decaissement_minimum'] = null;
            break;
        case 'approbation':
            $projected['conforme'] = 1;
            $projected['approuve'] = 0;
            $projected['etat_fiche'] = 0;
            $projected['decaisse'] = 0;
            $projected['date_valid'] = null;
            $projected['date_refuse'] = null;
            $projected['date_decaisse'] = null;
            $projected['date_decaissement_minimum'] = null;
            break;
        case 'validation_achat':
            $projected['conforme'] = 1;
            $projected['approuve'] = 1;
            $projected['etat_fiche'] = 0;
            $projected['decaisse'] = 0;
            $projected['date_refuse'] = null;
            $projected['date_decaisse'] = null;
            $projected['date_decaissement_minimum'] = date('Y-m-d');
            break;
        case 'tresorerie':
            $projected['conforme'] = 1;
            $projected['approuve'] = 1;
            $projected['etat_fiche'] = 1;
            $projected['decaisse'] = 0;
            $projected['date_refuse'] = null;
            $projected['date_decaisse'] = null;
            $projected['date_decaissement_minimum'] = date('Y-m-d');
            break;
        case 'decaissee':
            $projected['conforme'] = 1;
            $projected['approuve'] = 1;
            $projected['etat_fiche'] = 1;
            $projected['decaisse'] = 1;
            $projected['date_refuse'] = null;
            $projected['date_decaissement_minimum'] = date('Y-m-d');
            break;
    }

    $projected['workflow_stage_code'] = $targetStage;
    $projected['workflow_stage_label'] = admin_stage_label($targetStage);
    $status = admin_build_status_meta($projected);
    $projected['workflow_status_code'] = $status['code'];
    $projected['workflow_status_label'] = $status['label'];
    $projected['workflow_status_tone'] = $status['tone'];
    $projected['suggested_target_stage'] = admin_resolve_suggested_target_stage($projected);
    $projected['suggested_target_stage_label'] = admin_stage_label($projected['suggested_target_stage']);
    $projected['refusal_reason'] = admin_extract_refusal_reason($projected);

    return $projected;
}

function admin_build_batch_compare_item(FicheDecaissement $model, string $num, string $targetStage): array
{
    $snapshot = build_workflow_snapshot($model, $num);
    if ($snapshot === null) {
        throw new RuntimeException('Fiche introuvable: ' . $num);
    }

    $before = $snapshot['current'];
    $after = admin_project_row_to_stage($before, $targetStage);

    return [
        'num_fiche' => $num,
        'before' => [
            'workflow_stage_code' => $before['workflow_stage_code'] ?? null,
            'workflow_stage_label' => $before['workflow_stage_label'] ?? null,
            'workflow_status_code' => $before['workflow_status_code'] ?? null,
            'workflow_status_label' => $before['workflow_status_label'] ?? null,
            'conforme' => (int)($before['conforme'] ?? 0),
            'approuve' => (int)($before['approuve'] ?? 0),
            'etat_fiche' => (int)($before['etat_fiche'] ?? 0),
            'decaisse' => (int)($before['decaisse'] ?? 0),
            'refusal_reason' => (string)($before['refusal_reason'] ?? ''),
        ],
        'after' => [
            'workflow_stage_code' => $after['workflow_stage_code'] ?? null,
            'workflow_stage_label' => $after['workflow_stage_label'] ?? null,
            'workflow_status_code' => $after['workflow_status_code'] ?? null,
            'workflow_status_label' => $after['workflow_status_label'] ?? null,
            'conforme' => (int)($after['conforme'] ?? 0),
            'approuve' => (int)($after['approuve'] ?? 0),
            'etat_fiche' => (int)($after['etat_fiche'] ?? 0),
            'decaisse' => (int)($after['decaisse'] ?? 0),
            'refusal_reason' => (string)($after['refusal_reason'] ?? ''),
        ],
        'warnings' => $snapshot['warnings'] ?? [],
    ];
}

function admin_fetch_workflow_history(PDO $pdo, int $limit = 20): array
{
    $limit = max(1, min(100, $limit));
    $stmt = $pdo->query(
        "SELECT id, event_type, action, entity_label, user_name, result, severity, details_json, created_at
         FROM audit_trail
         WHERE event_type IN ('admin_workflow_stage_changed', 'admin_workflow_partialized', 'admin_workflow_batch_transition')
         ORDER BY created_at DESC, id DESC
         LIMIT " . $limit
    );
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    return admin_map_workflow_history_rows($pdo, $rows);
}

function admin_fetch_workflow_history_item(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        "SELECT id, event_type, action, entity_label, user_name, result, severity, details_json, created_at
         FROM audit_trail
         WHERE id = :id
           AND event_type IN ('admin_workflow_stage_changed', 'admin_workflow_partialized', 'admin_workflow_batch_transition')
         LIMIT 1"
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    $items = admin_map_workflow_history_rows($pdo, [$row]);
    return $items[0] ?? null;
}

function admin_map_workflow_history_rows(PDO $pdo, array $rows): array
{
    $model = new FicheDecaissement($pdo);

    return array_map(static function (array $row) use ($model): array {
        $details = json_decode((string)($row['details_json'] ?? ''), true);
        if (!is_array($details)) {
            $details = [];
        }

        if (($row['event_type'] ?? '') === 'admin_workflow_batch_transition') {
            $numFiches = [];
            if (!empty($details['num_fiches']) && is_array($details['num_fiches'])) {
                $numFiches = array_values(array_filter(array_map('strval', $details['num_fiches'])));
            }
            $targetStage = trim((string)($details['target_stage'] ?? ''));
            if ($targetStage !== '' && $numFiches) {
                $loggedErrorsByNum = [];
                if (!empty($details['errors']) && is_array($details['errors'])) {
                    foreach ($details['errors'] as $errorItem) {
                        $errorNum = trim((string)($errorItem['num_fiche'] ?? ''));
                        if ($errorNum !== '') {
                            $loggedErrorsByNum[$errorNum] = trim((string)($errorItem['error'] ?? 'Erreur d\'exécution'));
                        }
                    }
                }

                $observedSuccess = 0;
                $observedRealErrors = 0;
                $observedRereadGaps = 0;
                $observedItems = [];
                foreach ($numFiches as $numFiche) {
                    $snapshot = null;
                    $currentStageCode = '';
                    $currentStageLabel = '';
                    $currentStatusCode = '';
                    $currentStatusLabel = '';
                    $loggedError = $loggedErrorsByNum[$numFiche] ?? null;
                    $observationError = null;

                    try {
                        $snapshot = build_workflow_snapshot($model, $numFiche);
                    } catch (Throwable $e) {
                        $observationError = $e->getMessage();
                    }

                    if ($snapshot) {
                        $currentStageCode = (string)($snapshot['current']['workflow_stage_code'] ?? '');
                        $currentStageLabel = (string)($snapshot['current']['workflow_stage_label'] ?? '');
                        $currentStatusCode = (string)($snapshot['current']['workflow_status_code'] ?? '');
                        $currentStatusLabel = (string)($snapshot['current']['workflow_status_label'] ?? '');
                    }

                    $classification = 'reread_gap';
                    $classificationLabel = 'Écart de relecture';
                    if ($snapshot && $currentStageCode === $targetStage) {
                        $classification = 'success';
                        $classificationLabel = 'Succès constaté';
                        $observedSuccess++;
                    } elseif ($loggedError !== null) {
                        $classification = 'real_error';
                        $classificationLabel = 'Erreur réelle';
                        $observedRealErrors++;
                    } else {
                        $observedRereadGaps++;
                    }

                    $observedItems[] = [
                        'num_fiche' => $numFiche,
                        'target_stage_code' => $targetStage,
                        'target_stage_label' => admin_stage_label($targetStage),
                        'current_stage_code' => $currentStageCode !== '' ? $currentStageCode : null,
                        'current_stage_label' => $currentStageLabel !== '' ? $currentStageLabel : null,
                        'current_status_code' => $currentStatusCode !== '' ? $currentStatusCode : null,
                        'current_status_label' => $currentStatusLabel !== '' ? $currentStatusLabel : null,
                        'classification' => $classification,
                        'classification_label' => $classificationLabel,
                        'logged_error' => $loggedError,
                        'observation_error' => $observationError,
                    ];
                }

                $requested = (int)($details['requested'] ?? count($numFiches));
                $details['observed_success_count'] = $observedSuccess;
                $details['observed_real_error_count'] = $observedRealErrors;
                $details['observed_reread_gap_count'] = $observedRereadGaps;
                $details['observed_error_count'] = $observedRealErrors + $observedRereadGaps;
                $details['observed_items'] = $observedItems;
                $details['observed_requested_count'] = $requested;
            }
        }

        return [
            'id' => (int)($row['id'] ?? 0),
            'event_type' => (string)($row['event_type'] ?? ''),
            'action' => (string)($row['action'] ?? ''),
            'entity_label' => (string)($row['entity_label'] ?? ''),
            'user_name' => (string)($row['user_name'] ?? 'ADMIN'),
            'result' => (string)($row['result'] ?? 'success'),
            'severity' => (string)($row['severity'] ?? 'info'),
            'created_at' => (string)($row['created_at'] ?? ''),
            'details' => $details,
        ];
    }, $rows);
}

function build_workflow_snapshot(FicheDecaissement $model, string $num): ?array
{
    $details = $model->getDetailsByNum($num);
    if (!$details) {
        return null;
    }

    $family = $model->getWorkflowFamily($num);
    $familyItems = $family['items'] ?? [];
    $current = null;
    foreach ($familyItems as $item) {
        if ((string)($item['num_fiche'] ?? '') === $num) {
            $current = $item;
            break;
        }
    }
    if ($current === null) {
        $stageCode = admin_stage_code_from_row($details);
        $details['workflow_stage_code'] = $stageCode;
        $details['workflow_stage_label'] = admin_stage_label($stageCode);
        $current = $details;
    } else {
        $details = array_merge($details, $current);
    }

    $numStr = (string)($current['num_fiche'] ?? $num);
    $hasChildren = false;
    foreach ($familyItems as $item) {
        $parentNum = trim((string)($item['parent_num_fiche'] ?? ($item['code_parentale'] ?? '')));
        if ($parentNum !== '' && $parentNum === $numStr) {
            $hasChildren = true;
            break;
        }
    }

    $warnings = [];
    if ($hasChildren) {
        $warnings[] = 'Cette fiche possède déjà une ou plusieurs héritières. Toute transition admin doit être pilotée avec prudence.';
    }
    if ((int)($current['decaisse'] ?? 0) === 1 && $hasChildren) {
        $warnings[] = 'La conversion total vers partiel est bloquée sur une fiche déjà scindée.';
    }
    if ((int)($current['approuve'] ?? 0) === 2 || (int)($current['etat_fiche'] ?? 0) === 2) {
        $warnings[] = 'Cette fiche est actuellement refusée. Le statut rouge ci-dessous reflète l’état réel, indépendamment de l’étape où vous souhaitez la repositionner.';
    }

    $current['workflow_stage_code'] = $current['workflow_stage_code'] ?? admin_stage_code_from_row($current);
    $current['workflow_stage_label'] = $current['workflow_stage_label'] ?? admin_stage_label($current['workflow_stage_code']);
    $status = admin_build_status_meta($current);
    $current['workflow_status_code'] = $status['code'];
    $current['workflow_status_label'] = $status['label'];
    $current['workflow_status_tone'] = $status['tone'];
    $current['suggested_target_stage'] = admin_resolve_suggested_target_stage($current);
    $current['suggested_target_stage_label'] = admin_stage_label($current['suggested_target_stage']);
    $current['refusal_reason'] = admin_extract_refusal_reason($current);
    $current['can_partialize'] = (int)($current['decaisse'] ?? 0) === 1 && !$hasChildren;
    $current['partialize_error'] = $current['can_partialize'] ? null : ($hasChildren
        ? 'Une héritière existe déjà pour cette fiche.'
        : 'La fiche doit être déjà décaissée pour être convertie en partiel.');
    $current['recommended_next_date'] = date('Y-m-d');

    return [
        'current' => $current,
        'family' => [
            'root' => $family['root'] ?? $numStr,
            'items' => $familyItems,
        ],
        'traces' => $model->getTraces($num, 80),
        'warnings' => $warnings,
    ];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST uniquement']);
    exit;
}

$action = trim((string)($_POST['action'] ?? 'snapshot'));
$num = trim((string)($_POST['num_fiche'] ?? ''));
$batchNums = admin_normalize_num_fiches((string)($_POST['num_fiches'] ?? ''));

if (!in_array($action, ['history', 'batch_preview', 'batch_transition'], true) && $num === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Numéro de fiche requis']);
    exit;
}

if (in_array($action, ['batch_preview', 'batch_transition'], true) && !$batchNums) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Liste de fiches requise']);
    exit;
}

$pdo = null;

try {
    $pdo = Database::getConnection();
    $model = new FicheDecaissement($pdo);
    $user = trim((string)($_SESSION['secur_projet'] ?? ($_SESSION['username'] ?? 'ADMIN')));
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $port = (int)($_SERVER['REMOTE_PORT'] ?? 0);

    if ($action === 'history') {
        echo json_encode([
            'ok' => true,
            'history' => admin_fetch_workflow_history($pdo, (int)($_POST['limit'] ?? 20)),
        ]);
        exit;
    }

    if ($action === 'history_refresh_item') {
        $historyId = (int)($_POST['history_id'] ?? 0);
        if ($historyId <= 0) {
            throw new RuntimeException('Identifiant d\'historique invalide');
        }

        $item = admin_fetch_workflow_history_item($pdo, $historyId);
        if ($item === null) {
            throw new RuntimeException('Entrée d\'historique introuvable');
        }

        echo json_encode([
            'ok' => true,
            'message' => 'Diagnostic rechargé.',
            'item' => $item,
        ]);
        exit;
    }

    if ($action === 'snapshot') {
        $snapshot = build_workflow_snapshot($model, $num);
        if ($snapshot === null) {
            throw new RuntimeException('Fiche introuvable');
        }
        echo json_encode(['ok' => true, 'message' => 'Fiche chargée.', 'snapshot' => $snapshot]);
        exit;
    }

    if ($action === 'transition') {
        $targetStage = trim((string)($_POST['target_stage'] ?? ''));
        $reason = trim((string)($_POST['reason'] ?? ''));
        $result = $model->adminMoveToStage($num, $targetStage, $user, $reason, $ip, $port);
        if (empty($result['ok'])) {
            throw new RuntimeException((string)($result['error'] ?? 'Transition admin impossible'));
        }
        AuditTrail::log('admin_workflow_stage_changed', [
            'module' => 'admin',
            'action' => 'workflow_stage_change',
            'entity_type' => 'fiche_decaissement',
            'entity_id' => $num,
            'entity_label' => 'Pilotage workflow fiche ' . $num,
            'user_name' => $user,
            'result' => 'success',
            'details' => [
                'before_stage' => $result['before_stage'] ?? null,
                'after_stage' => $result['after_stage'] ?? null,
                'reason' => $reason !== '' ? $reason : null,
            ],
        ], $pdo);
        $snapshot = build_workflow_snapshot($model, $num);
        echo json_encode([
            'ok' => true,
            'message' => 'Fiche repositionnée en ' . ($result['after_label'] ?? 'étape cible') . '.',
            'snapshot' => $snapshot,
        ]);
        exit;
    }

    if ($action === 'partialize') {
        $amount = isset($_POST['amount']) ? (float)$_POST['amount'] : 0;
        $nextDate = trim((string)($_POST['next_date'] ?? ''));
        $reason = trim((string)($_POST['reason'] ?? ''));
        $result = $model->adminConvertDecaissementToPartial($num, $amount, $nextDate, $user, $reason, $ip, $port);
        if (empty($result['ok'])) {
            throw new RuntimeException((string)($result['error'] ?? 'Conversion total vers partiel impossible'));
        }
        AuditTrail::log('admin_workflow_partialized', [
            'module' => 'admin',
            'action' => 'workflow_total_to_partial',
            'entity_type' => 'fiche_decaissement',
            'entity_id' => $num,
            'entity_label' => 'Conversion total vers partiel ' . $num,
            'user_name' => $user,
            'result' => 'success',
            'details' => [
                'amount_kept' => $amount,
                'next_date' => $nextDate,
                'child_num_fiche' => $result['child'] ?? null,
                'reason' => $reason !== '' ? $reason : null,
            ],
        ], $pdo);
        $snapshot = build_workflow_snapshot($model, $num);
        echo json_encode([
            'ok' => true,
            'message' => 'Conversion réalisée. Fiche héritière créée : ' . (string)($result['child'] ?? ''),
            'snapshot' => $snapshot,
        ]);
        exit;
    }

    if ($action === 'batch_preview') {
        $targetStage = trim((string)($_POST['target_stage'] ?? ''));
        if ($targetStage === '') {
            throw new RuntimeException('Étape cible requise pour le mode lot');
        }

        $items = [];
        $errors = [];
        foreach ($batchNums as $batchNum) {
            try {
                $items[] = admin_build_batch_compare_item($model, $batchNum, $targetStage);
            } catch (Throwable $e) {
                $errors[] = ['num_fiche' => $batchNum, 'error' => $e->getMessage()];
            }
        }

        echo json_encode([
            'ok' => true,
            'preview' => [
                'target_stage' => $targetStage,
                'target_stage_label' => admin_stage_label($targetStage),
                'items' => $items,
                'errors' => $errors,
                'summary' => [
                    'requested' => count($batchNums),
                    'ready' => count($items),
                    'errors' => count($errors),
                ],
            ],
            'history' => admin_fetch_workflow_history($pdo, 20),
        ]);
        exit;
    }

    if ($action === 'batch_transition') {
        $targetStage = trim((string)($_POST['target_stage'] ?? ''));
        $reason = trim((string)($_POST['reason'] ?? ''));
        if ($targetStage === '') {
            throw new RuntimeException('Étape cible requise pour le mode lot');
        }

        $items = [];
        $errors = [];
        foreach ($batchNums as $batchNum) {
            try {
                $beforeSnapshot = build_workflow_snapshot($model, $batchNum);
                if ($beforeSnapshot === null) {
                    throw new RuntimeException('Fiche introuvable');
                }
                $result = $model->adminMoveToStage($batchNum, $targetStage, $user, $reason, $ip, $port);
                if (empty($result['ok'])) {
                    throw new RuntimeException((string)($result['error'] ?? 'Transition lot impossible'));
                }
                $afterSnapshot = build_workflow_snapshot($model, $batchNum);
                if ($afterSnapshot === null) {
                    throw new RuntimeException('Impossible de relire la fiche après traitement');
                }
                $items[] = [
                    'num_fiche' => $batchNum,
                    'before' => $beforeSnapshot['current'],
                    'after' => $afterSnapshot['current'],
                ];
            } catch (Throwable $e) {
                $errors[] = ['num_fiche' => $batchNum, 'error' => $e->getMessage()];
            }
        }

        AuditTrail::log('admin_workflow_batch_transition', [
            'module' => 'admin',
            'action' => 'workflow_batch_transition',
            'entity_type' => 'fiche_decaissement_batch',
            'entity_label' => 'Transition workflow en lot',
            'user_name' => $user,
            'result' => count($items) > 0 ? 'success' : 'failure',
            'severity' => count($errors) > 0 ? 'warning' : 'info',
            'details' => [
                'target_stage' => $targetStage,
                'target_stage_label' => admin_stage_label($targetStage),
                'reason' => $reason !== '' ? $reason : null,
                'requested' => count($batchNums),
                'success_count' => count($items),
                'error_count' => count($errors),
                'num_fiches' => $batchNums,
                'errors' => $errors,
            ],
        ], $pdo);

        echo json_encode([
            'ok' => true,
            'message' => count($items) . ' fiche(s) repositionnée(s) en lot.',
            'batch' => [
                'target_stage' => $targetStage,
                'target_stage_label' => admin_stage_label($targetStage),
                'items' => $items,
                'errors' => $errors,
                'summary' => [
                    'requested' => count($batchNums),
                    'success' => count($items),
                    'errors' => count($errors),
                ],
            ],
            'history' => admin_fetch_workflow_history($pdo, 20),
        ]);
        exit;
    }

    throw new RuntimeException('Action inconnue');
} catch (Throwable $e) {
    if ($pdo instanceof PDO) {
        AuditTrail::log('admin_workflow_action_failed', [
            'module' => 'admin',
            'action' => 'workflow_fiches_admin_api',
            'entity_type' => 'fiche_decaissement',
            'entity_id' => $num !== '' ? $num : null,
            'entity_label' => $num !== '' ? ('Pilotage workflow fiche ' . $num) : 'Pilotage workflow fiche',
            'user_name' => (string)($_SESSION['secur_projet'] ?? ($_SESSION['username'] ?? 'ADMIN')),
            'result' => 'failure',
            'severity' => 'warning',
            'details' => [
                'action_name' => $action !== '' ? $action : null,
                'error' => $e->getMessage(),
            ],
        ], $pdo);
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
