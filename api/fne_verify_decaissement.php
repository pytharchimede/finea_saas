<?php

declare(strict_types=1);

require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../model/FneClient.php';
require_once __DIR__ . '/../model/FneValidator.php';
require_once __DIR__ . '/../decaissement/model/FicheDecaissement.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    \AuditTrail::log('fne_decaissement_verify_failed', [
        'module' => 'api',
        'action' => 'fne_verify_decaissement',
        'entity_type' => 'decaissement_fiche',
        'result' => 'failure',
        'severity' => 'warning',
        'details' => ['reason' => 'invalid_method'],
    ]);
    echo json_encode(['ok' => false, 'error' => 'Méthode invalide.']);
    exit;
}

function readJsonPayload(): array
{
    $raw = file_get_contents('php://input');
    if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return is_array($_POST ?? null) ? $_POST : [];
}

function asString($v): string
{
    return trim((string)($v ?? ''));
}

function asFloat($v): float
{
    if ($v === null || $v === '') {
        return 0.0;
    }
    return is_numeric($v) ? (float)$v : 0.0;
}

function normalizeLabel(string $s): string
{
    $s = function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    $s = trim($s);
    if ($s === '') {
        return '';
    }

    // Supprimer accents si possible
    if (function_exists('iconv')) {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if (is_string($t) && $t !== '') {
            $s = $t;
        }
    }

    $s = preg_replace('/[^a-z0-9]+/i', ' ', $s);
    $s = trim(preg_replace('/\s+/', ' ', (string)$s));
    return $s;
}

function similarityRatio(string $a, string $b): float
{
    $a = normalizeLabel($a);
    $b = normalizeLabel($b);
    if ($a === '' || $b === '') {
        return 0.0;
    }
    $percent = 0.0;
    similar_text($a, $b, $percent);
    return max(0.0, min(1.0, $percent / 100.0));
}

function fetchFicheRow(PDO $pdo, string $num): ?array
{
    $st = $pdo->prepare('SELECT num_fiche, parent_num_fiche, code_parentale, montant_fiche, montant_initial_fiche, designation_fiche, precision_fiche FROM fiche_decaissement WHERE num_fiche = :num LIMIT 1');
    $st->execute(['num' => $num]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function resolveRootNum(PDO $pdo, string $num): string
{
    $guard = 0;
    $current = fetchFicheRow($pdo, $num);
    if (!$current) {
        return $num;
    }

    while ($guard < 30) {
        $parent = asString($current['parent_num_fiche'] ?? '');
        if ($parent === '') {
            break;
        }
        $parentRow = fetchFicheRow($pdo, $parent);
        if (!$parentRow) {
            break;
        }
        $current = $parentRow;
        $guard++;

        // sécurité: éviter les cycles
        if (asString($current['num_fiche'] ?? '') === $parent) {
            continue;
        }
    }

    return asString($current['num_fiche'] ?? $num) ?: $num;
}

function fetchChildren(PDO $pdo, array $frontierNums, string $rootNum): array
{
    $frontierNums = array_values(array_unique(array_filter(array_map('strval', $frontierNums), static function ($v) {
        return trim($v) !== '';
    })));

    if (!$frontierNums) {
        return [];
    }

    $in = implode(',', array_fill(0, count($frontierNums), '?'));

    $sql = "SELECT num_fiche, parent_num_fiche, code_parentale, montant_fiche, montant_initial_fiche, designation_fiche, precision_fiche
            FROM fiche_decaissement
            WHERE parent_num_fiche IN ($in)
               OR code_parentale IN ($in)
               OR code_parentale = ?";

    $params = array_merge($frontierNums, $frontierNums, [$rootNum]);

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}

function extractRemoteLabel($data): string
{
    if (!is_array($data)) {
        return '';
    }

    $candidates = [];

    foreach (['label', 'description', 'libelle', 'vendorName', 'supplierName', 'companyName', 'clientCompanyName'] as $k) {
        if (isset($data[$k]) && is_string($data[$k]) && trim($data[$k]) !== '') {
            $candidates[] = trim($data[$k]);
        }
    }

    // items[*].description
    if (isset($data['items']) && is_array($data['items'])) {
        foreach ($data['items'] as $it) {
            if (is_array($it) && isset($it['description']) && is_string($it['description'])) {
                $d = trim($it['description']);
                if ($d !== '') {
                    $candidates[] = $d;
                }
            }
        }
    }

    // data.data style
    if (isset($data['data']) && is_array($data['data'])) {
        $nested = extractRemoteLabel($data['data']);
        if ($nested !== '') {
            $candidates[] = $nested;
        }
    }

    $candidates = array_values(array_unique(array_filter($candidates, static function ($v) {
        return trim((string)$v) !== '';
    })));

    return trim(implode(' | ', $candidates));
}

function extractRemoteAmount($data): ?float
{
    if (!is_array($data)) {
        return null;
    }

    $tryKeys = ['amount', 'total', 'totalAmount', 'total_amount', 'totalTtc', 'total_ttc', 'montant', 'montantTtc', 'montant_ttc', 'invoiceAmount', 'invoiceTotal'];
    foreach ($tryKeys as $k) {
        if (isset($data[$k]) && is_numeric($data[$k])) {
            return (float)$data[$k];
        }
    }

    if (isset($data['data']) && is_array($data['data'])) {
        $v = extractRemoteAmount($data['data']);
        if ($v !== null) {
            return $v;
        }
    }

    return null;
}

function extractRemoteStatus($data): ?string
{
    if (!is_array($data)) {
        return null;
    }

    foreach (['status', 'state', 'result'] as $k) {
        if (isset($data[$k]) && is_string($data[$k])) {
            $s = trim($data[$k]);
            if ($s !== '') {
                return $s;
            }
        }
    }

    if (isset($data['valid']) && is_bool($data['valid'])) {
        return $data['valid'] ? 'VALID' : 'INVALID';
    }

    if (isset($data['data']) && is_array($data['data'])) {
        $v = extractRemoteStatus($data['data']);
        if ($v !== null) {
            return $v;
        }
    }

    return null;
}

$payload = readJsonPayload();
if (!$payload) {
    \AuditTrail::log('fne_decaissement_verify_failed', [
        'module' => 'api',
        'action' => 'fne_verify_decaissement',
        'entity_type' => 'decaissement_fiche',
        'result' => 'failure',
        'severity' => 'warning',
        'details' => ['reason' => 'missing_payload'],
    ]);
    echo json_encode(['ok' => false, 'error' => 'Payload manquant.']);
    exit;
}

$numFiche = asString($payload['num_fiche'] ?? $payload['numFiche'] ?? '');
$idFiche = (int)($payload['id_fiche'] ?? $payload['idFiche'] ?? $payload['id'] ?? 0);

try {
    $pdo = Database::getConnection();
    $model = new FicheDecaissement($pdo);

    $fiche = null;
    if ($numFiche !== '') {
        $fiche = $model->getByNum($numFiche);
    } elseif ($idFiche > 0) {
        $st = $pdo->prepare('SELECT * FROM fiche_decaissement WHERE id_fiche = :id LIMIT 1');
        $st->execute(['id' => $idFiche]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            $fiche = $row;
            $numFiche = asString($row['num_fiche'] ?? '');
        }
    }

    if (!is_array($fiche) || $numFiche === '') {
        \AuditTrail::log('fne_decaissement_verify_failed', [
            'module' => 'api',
            'action' => 'fne_verify_decaissement',
            'entity_type' => 'decaissement_fiche',
            'entity_id' => $numFiche !== '' ? $numFiche : ($idFiche > 0 ? (string)$idFiche : null),
            'entity_label' => $numFiche !== '' ? $numFiche : 'verification_decaissement_fne',
            'result' => 'failure',
            'severity' => 'warning',
            'details' => ['reason' => 'fiche_not_found'],
        ], $pdo);
        echo json_encode(['ok' => false, 'error' => 'Fiche introuvable.']);
        exit;
    }

    $rootNum = resolveRootNum($pdo, $numFiche);

    // Collecte généalogie (descendants)
    $visited = [];
    $all = [];

    $rootRow = fetchFicheRow($pdo, $rootNum);
    if ($rootRow) {
        $visited[$rootNum] = true;
        $all[] = $rootRow;
    }

    $frontier = [$rootNum];
    $guard = 0;
    while ($frontier && $guard < 50) {
        $children = fetchChildren($pdo, $frontier, $rootNum);
        $next = [];
        foreach ($children as $ch) {
            if (!is_array($ch)) {
                continue;
            }
            $n = asString($ch['num_fiche'] ?? '');
            if ($n === '' || isset($visited[$n])) {
                continue;
            }
            $visited[$n] = true;
            $all[] = $ch;
            $next[] = $n;
        }
        $frontier = $next;
        $guard++;
    }

    // Calculs montants + libellé local
    $sumMontant = 0.0;
    $maxInitial = 0.0;
    $labelParts = [];

    foreach ($all as $r) {
        $m = asFloat($r['montant_fiche'] ?? 0);
        $sumMontant += $m;

        $mi = asFloat($r['montant_initial_fiche'] ?? 0);
        $maxInitial = max($maxInitial, $mi, $m);

        $d = asString($r['designation_fiche'] ?? '');
        $p = asString($r['precision_fiche'] ?? '');
        $lp = trim($d . ' ' . $p);
        if ($lp !== '') {
            $labelParts[] = $lp;
        }
    }

    $labelParts = array_values(array_unique($labelParts));
    $localLabel = implode(' | ', $labelParts);

    $expectedTotal = $maxInitial > 0 ? $maxInitial : $sumMontant;

    $amountTolerance = isset($payload['amount_tolerance']) && is_numeric($payload['amount_tolerance'])
        ? max(0.0, (float)$payload['amount_tolerance'])
        : 0.01;

    $labelThreshold = isset($payload['label_match_threshold']) && is_numeric($payload['label_match_threshold'])
        ? max(0.0, min(1.0, (float)$payload['label_match_threshold']))
        : 0.55;

    $blockOnLabelMismatch = !empty($payload['block_on_label_mismatch']);

    // Payload verify FNE
    $verify = $payload['verify'] ?? null;
    if (!is_array($verify)) {
        // fallback: récupérer les champs possibles au niveau racine
        $verify = [];
        foreach (['invoiceNumber', 'invoice_number', 'verificationCode', 'verification_code', 'amount', 'date'] as $k) {
            if (array_key_exists($k, $payload)) {
                $verify[$k] = $payload[$k];
            }
        }
    }

    if (!is_array($verify) || !$verify) {
        \AuditTrail::log('fne_decaissement_verify_failed', [
            'module' => 'api',
            'action' => 'fne_verify_decaissement',
            'entity_type' => 'decaissement_fiche',
            'entity_id' => $numFiche,
            'entity_label' => $numFiche,
            'result' => 'failure',
            'severity' => 'warning',
            'details' => ['reason' => 'missing_verify_payload'],
        ], $pdo);
        echo json_encode(['ok' => false, 'error' => 'Payload verify manquant (clé verify ou invoiceNumber/verificationCode).']);
        exit;
    }

    $validation = FneValidator::validateVerifyPayload($verify);
    if (empty($validation['ok'])) {
        \AuditTrail::log('fne_decaissement_verify_failed', [
            'module' => 'api',
            'action' => 'fne_verify_decaissement',
            'entity_type' => 'decaissement_fiche',
            'entity_id' => $numFiche,
            'entity_label' => $numFiche,
            'result' => 'failure',
            'severity' => 'warning',
            'details' => ['reason' => 'invalid_verify_payload', 'error_count' => count($validation['errors'] ?? [])],
        ], $pdo);
        echo json_encode([
            'ok' => false,
            'error' => 'Payload verify invalide.',
            'errors' => $validation['errors'] ?? [],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $client = FneClient::fromConfigFile();
    if (!$client->isConfigured()) {
        \AuditTrail::log('fne_decaissement_verify_failed', [
            'module' => 'api',
            'action' => 'fne_verify_decaissement',
            'entity_type' => 'decaissement_fiche',
            'entity_id' => $numFiche,
            'entity_label' => $numFiche,
            'result' => 'failure',
            'severity' => 'warning',
            'details' => ['reason' => 'fne_not_configured'],
        ], $pdo);
        echo json_encode(['ok' => false, 'error' => 'FNE non configuré (FNE_API_KEY/FNE_BASE_URL).']);
        exit;
    }

    $fneRes = $client->verifyInvoice($verify);

    $remoteData = is_array($fneRes['data'] ?? null) ? $fneRes['data'] : null;
    $remoteAmount = $remoteData ? extractRemoteAmount($remoteData) : null;
    $remoteLabel = $remoteData ? extractRemoteLabel($remoteData) : '';
    $remoteStatus = $remoteData ? extractRemoteStatus($remoteData) : null;

    $amountDelta = null;
    $amountOk = null;
    if ($remoteAmount !== null) {
        $amountDelta = abs($remoteAmount - $expectedTotal);
        $amountOk = ($amountDelta <= $amountTolerance);
    }

    $labelRatio = $remoteLabel !== '' && $localLabel !== '' ? similarityRatio($localLabel, $remoteLabel) : 0.0;
    $labelOk = ($remoteLabel === '' || $localLabel === '') ? null : ($labelRatio >= $labelThreshold);

    $reasons = [];
    $warnings = [];

    $genealogyComplete = (abs($sumMontant - $expectedTotal) <= $amountTolerance);
    if (!$genealogyComplete) {
        $warnings[] = 'Somme des montants de la généalogie différente du total attendu (montant_initial_fiche/max).';
    }

    $okForDecaissement = true;

    if (empty($fneRes['ok'])) {
        $okForDecaissement = false;
        $reasons[] = 'Vérification FNE échouée (HTTP/API).';
    }

    if ($remoteStatus !== null) {
        $s = strtoupper(trim($remoteStatus));
        if (in_array($s, ['INVALID', 'REJECTED', 'FAILED', 'ERROR'], true)) {
            $okForDecaissement = false;
            $reasons[] = 'Facture FNE invalide: ' . $remoteStatus;
        }
    }

    if ($amountOk === false) {
        $okForDecaissement = false;
        $reasons[] = 'Montant FNE différent du montant attendu de la généalogie.';
    } elseif ($amountOk === null) {
        $warnings[] = 'Montant facture non détecté dans la réponse verify (comparaison montant non faite).';
    }

    if ($labelOk === false) {
        $msg = 'Libellé divergent (similarité ' . round($labelRatio * 100, 1) . '% < ' . round($labelThreshold * 100, 1) . '%).';
        if ($blockOnLabelMismatch) {
            $okForDecaissement = false;
            $reasons[] = $msg;
        } else {
            $warnings[] = $msg;
        }
    }

    $decision = $okForDecaissement ? (count($warnings) ? 'ATTENTION' : 'OK') : 'KO';

    echo json_encode([
        'ok' => true,
        'ok_for_decaissement' => $okForDecaissement,
        'decision' => $decision,
        'reasons' => $reasons,
        'warnings' => $warnings,
        'local' => [
            'num_fiche' => $numFiche,
            'root_num_fiche' => $rootNum,
            'genealogy_count' => count($all),
            'genealogy_nums' => array_values(array_map(static function ($r) {
                return asString($r['num_fiche'] ?? '');
            }, $all)),
            'genealogy_sum_montant_fiche' => $sumMontant,
            'expected_total' => $expectedTotal,
            'genealogy_complete' => $genealogyComplete,
            'label' => $localLabel,
        ],
        'fne' => [
            'verify_payload' => $verify,
            'response' => $fneRes,
            'extracted' => [
                'status' => $remoteStatus,
                'amount' => $remoteAmount,
                'label' => $remoteLabel,
            ],
        ],
        'comparisons' => [
            'amount' => [
                'tolerance' => $amountTolerance,
                'delta' => $amountDelta,
                'ok' => $amountOk,
            ],
            'label' => [
                'threshold' => $labelThreshold,
                'ratio' => $labelRatio,
                'ok' => $labelOk,
            ],
        ],
    ], JSON_UNESCAPED_UNICODE);
    \AuditTrail::log($okForDecaissement ? 'fne_decaissement_verified' : 'fne_decaissement_verify_failed', [
        'module' => 'api',
        'action' => 'fne_verify_decaissement',
        'entity_type' => 'decaissement_fiche',
        'entity_id' => $numFiche,
        'entity_label' => $numFiche,
        'result' => $okForDecaissement ? 'success' : 'failure',
        'severity' => $okForDecaissement ? ($decision === 'ATTENTION' ? 'warning' : 'info') : 'warning',
        'details' => [
            'decision' => $decision,
            'root_num_fiche' => $rootNum,
            'genealogy_count' => count($all),
            'expected_total' => $expectedTotal,
            'remote_amount' => $remoteAmount,
            'amount_ok' => $amountOk,
            'label_ratio' => $labelRatio,
            'label_ok' => $labelOk,
            'warning_count' => count($warnings),
            'reason_count' => count($reasons),
        ],
    ], $pdo);
    exit;
} catch (Throwable $e) {
    \AuditTrail::log('fne_decaissement_verify_failed', [
        'module' => 'api',
        'action' => 'fne_verify_decaissement',
        'entity_type' => 'decaissement_fiche',
        'entity_id' => $numFiche !== '' ? $numFiche : ($idFiche > 0 ? (string)$idFiche : null),
        'entity_label' => $numFiche !== '' ? $numFiche : 'verification_decaissement_fne',
        'result' => 'failure',
        'severity' => 'warning',
        'details' => ['error' => $e->getMessage()],
    ]);
    echo json_encode(['ok' => false, 'error' => 'Erreur serveur.']);
    exit;
}
