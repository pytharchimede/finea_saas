<?php
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/FicheDecaissement.php';

// SSE: progression + logs en temps réel
@set_time_limit(0);
@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', '0');

while (ob_get_level() > 0) {
    @ob_end_flush();
}

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-transform');
header('X-Accel-Buffering: no');

$send = static function (string $event, $data): void {
    echo "event: {$event}\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    @ob_flush();
    @flush();
};

try {
    if (!isset($pdo) || !$pdo) {
        $pdo = Database::getConnection();
    }

    $mode = trim((string)($_GET['mode'] ?? ''));
    $chantier = trim((string)($_GET['chantier'] ?? ''));
    $beneficiaire = trim((string)($_GET['beneficiaire'] ?? ''));
    $date = trim((string)($_GET['date'] ?? ''));
    $entreprise = trim((string)($_GET['entreprise'] ?? ''));
    $definitif = (int)($_GET['definitif'] ?? 0) === 1;

    $max = (int)($_GET['max'] ?? 200);
    if ($max <= 0) $max = 200;
    if ($max > 500) $max = 500;

    $filters = [];
    if ($entreprise !== '') {
        $filters['entreprise'] = $entreprise;
    }

    if ($mode === 'chantier') {
        if ($chantier === '') {
            $send('done', ['ok' => false, 'error' => 'Chantier requis']);
            exit;
        }
        $filters['chantier'] = $chantier;
    } elseif ($mode === 'beneficiaire') {
        if ($beneficiaire === '') {
            $send('done', ['ok' => false, 'error' => 'Bénéficiaire requis']);
            exit;
        }
        $filters['beneficiaire'] = $beneficiaire;
    } elseif ($mode === 'date') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $send('done', ['ok' => false, 'error' => 'Date invalide (YYYY-MM-DD)']);
            exit;
        }
        $filters['date_min'] = $date;
        $filters['date_max'] = $date;
    } else {
        $send('done', ['ok' => false, 'error' => 'Mode invalide']);
        exit;
    }

    $m = new FicheDecaissement($pdo);

    // Si on est en mode définitif: verrouiller l'annulation pour toutes les fiches DÉJÀ décaissées du périmètre.
    // (Sans limite, rapide via UPDATE.)
    $finalized = 0;
    if ($definitif) {
        try {
            $finalized = $m->markDecaissementsDefinitifsScope($filters);
            $send('log', ['level' => 'info', 'message' => $finalized . ' fiche(s) déjà décaissée(s) passée(s) en définitif']);
        } catch (Throwable $e) {
            // On remonte l'erreur: le but du mode définitif est précisément ce verrouillage.
            $send('done', ['ok' => false, 'error' => $e->getMessage()]);
            exit;
        }
    }

    // Décaissement: uniquement les fiches prêtes ET non décaissées.
    $readyFilters = $filters;
    $readyFilters['only_not_decaisse'] = 1;

    $summary = $m->summaryComptaReady($readyFilters);
    $totalCandidates = (int)($summary['count'] ?? 0);
    $truncated = $totalCandidates > $max;
    $total = min($totalCandidates, $max);

    $candidates = $total > 0 ? $m->listComptaReady($readyFilters, $total, 0) : [];
    $send('start', [
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
        'total' => $total,
        'finalized' => $finalized,
    ]);

    if ($total === 0) {
        $send('done', [
            'ok' => true,
            'processed' => 0,
            'success' => 0,
            'failed' => 0,
            'truncated' => $truncated,
            'total_candidates' => $totalCandidates,
            'total' => 0,
            'finalized' => $finalized,
        ]);
        exit;
    }

    $user = $_SESSION['username'] ?? 'system';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $port = (int)($_SERVER['REMOTE_PORT'] ?? 0);

    $success = 0;
    $failed = 0;
    $processed = 0;

    foreach ($candidates as $r) {
        $num = (string)($r['num_fiche'] ?? '');
        if ($num === '') {
            continue;
        }

        $processed++;

        try {
            $blockingError = $m->getDecaissementTotalError($num);
            if ($blockingError !== null) {
                $failed++;
                $send('log', ['level' => 'error', 'num_fiche' => $num, 'message' => $blockingError]);
            } else {
                $ok = $m->decaisserCompta($num, $user, $ip, $port, $definitif);
                if ($ok) {
                    $success++;
                    $send('log', ['level' => 'success', 'num_fiche' => $num, 'message' => $definitif ? 'Décaissement OK (définitif)' : 'Décaissement OK']);
                } else {
                    $failed++;
                    $send('log', ['level' => 'error', 'num_fiche' => $num, 'message' => 'Décaissement impossible']);
                }
            }
        } catch (Throwable $e) {
            $failed++;
            $send('log', ['level' => 'error', 'num_fiche' => $num, 'message' => $e->getMessage()]);
        }

        $send('progress', [
            'processed' => $processed,
            'success' => $success,
            'failed' => $failed,
            'total' => $total,
        ]);

        // Permet de libérer le CPU / pousser les buffers
        usleep(25000);
    }

    $send('done', [
        'ok' => true,
        'processed' => $processed,
        'success' => $success,
        'failed' => $failed,
        'truncated' => $truncated,
        'total_candidates' => $totalCandidates,
        'total' => $total,
        'finalized' => $finalized,
    ]);
} catch (Throwable $e) {
    $send('done', ['ok' => false, 'error' => $e->getMessage()]);
}
