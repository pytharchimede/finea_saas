<?php
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/FicheDecaissement.php';
require_once __DIR__ . '/../../model/AuditTrail.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    AuditTrail::log('decaissement_bulk_failed', [
        'module' => 'decaissement',
        'action' => 'decaisser_bulk',
        'entity_type' => 'fiche_decaissement_batch',
        'entity_label' => 'decaisser_bulk',
        'result' => 'failure',
        'severity' => 'warning',
        'details' => ['reason' => 'invalid_method'],
    ]);
    echo json_encode(['ok' => false, 'error' => 'POST uniquement']);
    exit;
}

try {
    if (!isset($pdo) || !$pdo) { // fallback si non fourni par auth_check
        $pdo = Database::getConnection();
    }

    // Garde-fous: éviter les timeouts sur des lots importants
    @set_time_limit(120);

    $mode = trim((string)($_POST['mode'] ?? ''));
    $chantier = trim((string)($_POST['chantier'] ?? ''));
    $beneficiaire = trim((string)($_POST['beneficiaire'] ?? ''));
    $date = trim((string)($_POST['date'] ?? ''));
    $entreprise = trim((string)($_POST['entreprise'] ?? ''));
    $definitif = (int)($_POST['definitif'] ?? 0) === 1;

    $max = (int)($_POST['max'] ?? 200);
    if ($max <= 0) $max = 200;
    if ($max > 500) $max = 500;

    $filters = [];

    if ($entreprise !== '') {
        $filters['entreprise'] = $entreprise;
    }

    if ($mode === 'chantier') {
        if ($chantier === '') {
            AuditTrail::log('decaissement_bulk_failed', [
                'module' => 'decaissement',
                'action' => 'decaisser_bulk',
                'entity_type' => 'fiche_decaissement_batch',
                'entity_label' => 'mode_chantier',
                'result' => 'failure',
                'severity' => 'warning',
                'details' => ['reason' => 'missing_chantier'],
            ], $pdo);
            echo json_encode(['ok' => false, 'error' => 'Chantier requis']);
            exit;
        }
        $filters['chantier'] = $chantier;
    } elseif ($mode === 'beneficiaire') {
        if ($beneficiaire === '') {
            AuditTrail::log('decaissement_bulk_failed', [
                'module' => 'decaissement',
                'action' => 'decaisser_bulk',
                'entity_type' => 'fiche_decaissement_batch',
                'entity_label' => 'mode_beneficiaire',
                'result' => 'failure',
                'severity' => 'warning',
                'details' => ['reason' => 'missing_beneficiaire'],
            ], $pdo);
            echo json_encode(['ok' => false, 'error' => 'Bénéficiaire requis']);
            exit;
        }
        $filters['beneficiaire'] = $beneficiaire;
    } elseif ($mode === 'date') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            AuditTrail::log('decaissement_bulk_failed', [
                'module' => 'decaissement',
                'action' => 'decaisser_bulk',
                'entity_type' => 'fiche_decaissement_batch',
                'entity_label' => 'mode_date',
                'result' => 'failure',
                'severity' => 'warning',
                'details' => ['reason' => 'invalid_date', 'date' => $date],
            ], $pdo);
            echo json_encode(['ok' => false, 'error' => 'Date invalide (YYYY-MM-DD)']);
            exit;
        }
        $filters['date_min'] = $date;
        $filters['date_max'] = $date;
    } else {
        AuditTrail::log('decaissement_bulk_failed', [
            'module' => 'decaissement',
            'action' => 'decaisser_bulk',
            'entity_type' => 'fiche_decaissement_batch',
            'entity_label' => 'decaisser_bulk',
            'result' => 'failure',
            'severity' => 'warning',
            'details' => ['reason' => 'invalid_mode', 'mode' => $mode],
        ], $pdo);
        echo json_encode(['ok' => false, 'error' => 'Mode invalide']);
        exit;
    }

    $m = new FicheDecaissement($pdo);

    // Si définitif: verrouiller d'abord toutes les fiches déjà décaissées du périmètre.
    $finalized = 0;
    if ($definitif) {
        $finalized = $m->markDecaissementsDefinitifsScope($filters);
    }

    // Base: uniquement les fiches "prêtes" ET non décaissées.
    $readyFilters = $filters;
    $readyFilters['only_not_decaisse'] = 1;

    $summary = $m->summaryComptaReady($readyFilters);
    $totalCandidates = (int)($summary['count'] ?? 0);
    $truncated = $totalCandidates > $max;
    $toFetch = min($totalCandidates, $max);

    $candidates = $toFetch > 0 ? $m->listComptaReady($readyFilters, $toFetch, 0) : [];

    $user = $_SESSION['username'] ?? 'system';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $port = (int)($_SERVER['REMOTE_PORT'] ?? 0);

    $success = 0;
    $failed = 0;
    $failures = [];

    $progressDone=0;$progressTotal=count($candidates);
    foreach ($candidates as $r) {
        FinancialWorkflowProgress::step('Traitement des fiches du lot.',$progressDone++,$progressTotal);
        $num = (string)($r['num_fiche'] ?? '');
        if ($num === '') continue;

        try {
            $blockingError = $m->getDecaissementTotalError($num);
            if ($blockingError !== null) {
                $failed++;
                if (count($failures) < 50) {
                    $failures[] = ['num_fiche' => $num, 'error' => $blockingError];
                }
                continue;
            }

            $ok = $m->decaisserCompta($num, $user, $ip, $port, $definitif);
            if ($ok) {
                $success++;
            } else {
                $failed++;
                if (count($failures) < 50) {
                    $failures[] = ['num_fiche' => $num, 'error' => 'Décaissement impossible'];
                }
            }
        } catch (Throwable $e) {
            $failed++;
            if (count($failures) < 50) {
                $failures[] = ['num_fiche' => $num, 'error' => $e->getMessage()];
            }
        }
    }

    $response = [
        'ok' => true,
        'mode' => $mode,
        'query' => [
            'chantier' => $chantier,
            'beneficiaire' => $beneficiaire,
            'date' => $date,
            'entreprise' => $entreprise,
            'definitif' => $definitif ? 1 : 0,
        ],
        'max' => $max,
        'truncated' => $truncated,
        'total_candidates' => $totalCandidates,
        'finalized' => $finalized,
        'processed' => count($candidates),
        'success' => $success,
        'failed' => $failed,
        'failures' => $failures,
    ];

    AuditTrail::log('decaissement_bulk_processed', [
        'module' => 'decaissement',
        'action' => 'decaisser_bulk',
        'entity_type' => 'fiche_decaissement_batch',
        'entity_label' => $mode,
        'user_name' => (string)($_SESSION['username'] ?? 'system'),
        'details' => [
            'definitif' => $definitif,
            'finalized' => $finalized,
            'total_candidates' => $totalCandidates,
            'processed' => count($candidates),
            'success' => $success,
            'failed' => $failed,
            'truncated' => $truncated,
        ],
    ], $pdo);

    FinancialWorkflowProgress::step('Traitement des fiches du lot terminé.',$progressTotal,$progressTotal);
    echo json_encode($response);
} catch (Throwable $e) {
    http_response_code(500);
    AuditTrail::log('decaissement_bulk_failed', [
        'module' => 'decaissement',
        'action' => 'decaisser_bulk',
        'entity_type' => 'fiche_decaissement_batch',
        'entity_label' => isset($mode) && $mode !== '' ? $mode : 'decaisser_bulk',
        'user_name' => (string)($_SESSION['username'] ?? 'system'),
        'result' => 'failure',
        'severity' => 'warning',
        'details' => ['error' => $e->getMessage()],
    ], isset($pdo) && $pdo instanceof PDO ? $pdo : null);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

