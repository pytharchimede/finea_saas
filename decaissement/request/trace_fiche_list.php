<?php

/**
 * Endpoint JSON des traces (journal d'audit)
 * Améliorations: pagination (offset), tri, filtre par type d'action dérivé,
 * statistiques (distinct fiches / users, distribution par type), meta has_more.
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../model/Database.php';
try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB']);
    exit;
}

// Détection dynamique du vrai nom de table (trace_fiche ou trace_fiches)
function resolveTraceTable(PDO $pdo): ?string
{
    try {
        $r = $pdo->query("SHOW TABLES LIKE 'trace_fiche'");
        if ($r && $r->fetch()) return 'trace_fiche';
        $r2 = $pdo->query("SHOW TABLES LIKE 'trace_fiches'");
        if ($r2 && $r2->fetch()) return 'trace_fiches';
    } catch (Throwable $e) {
        // ignore
    }
    return null;
}

$traceTable = resolveTraceTable($pdo);
if (!$traceTable) {
    echo json_encode(['ok' => false, 'error' => 'Table trace_fiche(s) introuvable']);
    exit;
}

// --- Paramètres ---
$num = trim($_GET['num_fiche'] ?? '');
$action = trim($_GET['action'] ?? '');
$user = trim($_GET['utilisateur'] ?? '');
$ip = trim($_GET['ip'] ?? '');
$from = $_GET['from'] ?? null; // date (Y-m-d)
$to = $_GET['to'] ?? null;
$limit = min(500, max(10, (int)($_GET['limit'] ?? 200)));
$offset = max(0, (int)($_GET['offset'] ?? 0));
$sort = strtolower($_GET['sort'] ?? 'desc');
if (!in_array($sort, ['asc', 'desc'])) $sort = 'desc';
$actionTypeFilter = strtoupper(trim($_GET['action_type'] ?? ''));
$q = trim($_GET['q'] ?? '');
$forceAll = isset($_GET['force_all']) && $_GET['force_all'] == '1';

// Expression SQL de classification du type d'action
$classExpr = "CASE 
    WHEN LOWER(t.action) LIKE '%création%' OR LOWER(t.action) LIKE '%creation%' THEN 'CREATION'
    WHEN LOWER(t.action) LIKE '%approb%' THEN 'APPROBATION'
    WHEN LOWER(t.action) LIKE '%conform%' THEN 'CONFORMITE'
    WHEN LOWER(t.action) LIKE '%valid%' THEN 'VALIDATION'
    WHEN LOWER(t.action) LIKE '%refus%' OR LOWER(t.action) LIKE '%rejet%' THEN 'REFUS'
    WHEN LOWER(t.action) LIKE '%décaisse%' OR LOWER(t.action) LIKE '%decaisse%' THEN 'DECAISSEMENT'
    ELSE 'AUTRE' END";

// --- Construction WHERE (appliqué seulement si force_all n'est PAS activé) ---
$where = [];
$params = [];
if (!$forceAll) {
    if ($num !== '') {
        $where[] = 't.num_fiche LIKE :num';
        $params['num'] = '%' . $num . '%';
    }
    if ($action !== '') {
        $where[] = 't.action LIKE :act';
        $params['act'] = '%' . $action . '%';
    }
    if ($user !== '') {
        $where[] = 't.utilisateur LIKE :usr';
        $params['usr'] = '%' . $user . '%';
    }
    if ($ip !== '') {
        $where[] = 't.adresse_ip LIKE :ip';
        $params['ip'] = '%' . $ip . '%';
    }
    if ($from) {
        $where[] = 't.date_action >= :dfrom';
        $params['dfrom'] = $from . ' 00:00:00';
    }
    if ($to) {
        $where[] = 't.date_action <= :dto';
        $params['dto'] = $to . ' 23:59:59';
    }
    if ($actionTypeFilter !== '') {
        $where[] = '(' . $classExpr . ') = :atype';
        $params['atype'] = $actionTypeFilter;
    }
    if ($q !== '') {
        $where[] = '(t.action LIKE :q OR t.num_fiche LIKE :q OR t.utilisateur LIKE :q OR t.adresse_ip LIKE :q)';
        $params['q'] = '%' . $q . '%';
    }
}

$sqlBase = 'FROM ' . $traceTable . ' t';
$sqlWhere = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

try {
    // Données paginées
    $sqlData = 'SELECT t.id, t.num_fiche, t.action, t.date_action, t.utilisateur, t.adresse_ip, t.user_agent, ' . $classExpr . ' AS action_type '
        . $sqlBase . $sqlWhere . ' ORDER BY t.date_action ' . strtoupper($sort) . ' LIMIT :limit OFFSET :offset';
    $st = $pdo->prepare($sqlData);
    foreach ($params as $k => $v) {
        $st->bindValue(':' . $k, $v);
    }
    $st->bindValue(':limit', $limit, PDO::PARAM_INT);
    $st->bindValue(':offset', $offset, PDO::PARAM_INT);
    $st->execute();
    $items = $st->fetchAll(PDO::FETCH_ASSOC);

    // Total global
    $sqlCount = 'SELECT COUNT(*) nb ' . $sqlBase . $sqlWhere;
    $st2 = $pdo->prepare($sqlCount);
    $st2->execute($params);
    $nb = (int)$st2->fetchColumn();

    // Distinct fiches & users
    $sqlDistinct = 'SELECT COUNT(DISTINCT t.num_fiche) df, COUNT(DISTINCT t.utilisateur) du ' . $sqlBase . $sqlWhere;
    $st3 = $pdo->prepare($sqlDistinct);
    $st3->execute($params);
    $distinct = $st3->fetch(PDO::FETCH_ASSOC) ?: ['df' => 0, 'du' => 0];

    // Distribution par type d'action
    $sqlTypes = 'SELECT action_type, COUNT(*) nb FROM (SELECT ' . $classExpr . ' AS action_type ' . $sqlBase . $sqlWhere . ') x GROUP BY action_type';
    $st4 = $pdo->prepare($sqlTypes);
    $st4->execute($params);
    $types = [];
    while ($r = $st4->fetch(PDO::FETCH_ASSOC)) {
        $types[$r['action_type']] = (int)$r['nb'];
    }

    $hasMore = ($offset + $limit) < $nb;

    $resp = [
        'ok' => true,
        'count' => $nb,
        'limit' => $limit,
        'offset' => $offset,
        'has_more' => $hasMore,
        'sort' => $sort,
        'distinct_fiches' => (int)$distinct['df'],
        'distinct_users' => (int)$distinct['du'],
        'counts_by_action_type' => $types,
        'items' => $items
    ];
    if (isset($_GET['debug']) && $_GET['debug'] == '1') {
        $resp['table'] = $traceTable;
        $resp['where'] = $sqlWhere;
        $resp['filters_disabled'] = $forceAll;
        $resp['params'] = $params;
        try {
            $resp['database'] = $pdo->query('SELECT DATABASE()')->fetchColumn();
        } catch (Throwable $e) {
        }
        $resp['sample_top5'] = [];
        if (!$items) {
            try {
                $sample = $pdo->query('SELECT id,num_fiche,action,date_action,utilisateur FROM ' . $traceTable . ' ORDER BY date_action DESC LIMIT 5');
                if ($sample) {
                    $resp['sample_top5'] = $sample->fetchAll(PDO::FETCH_ASSOC);
                }
            } catch (Throwable $e) {
                $resp['sample_error'] = $e->getMessage();
            }
            try {
                $resp['table_total'] = (int)$pdo->query('SELECT COUNT(*) FROM ' . $traceTable)->fetchColumn();
            } catch (Throwable $e) {
            }
            try {
                $resp['tables_like'] = $pdo->query("SHOW TABLES LIKE 'trace_fich%'")->fetchAll(PDO::FETCH_NUM);
            } catch (Throwable $e) {
            }
        }
        // Diagnostics avancés toujours en mode debug
        try {
            $resp['structure'] = $pdo->query('DESCRIBE ' . $traceTable)->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $resp['structure_error'] = $e->getMessage();
        }
        try {
            $create = $pdo->query('SHOW CREATE TABLE ' . $traceTable)->fetch(PDO::FETCH_NUM);
            if ($create && isset($create[1])) $resp['show_create'] = $create[1];
        } catch (Throwable $e) {
            $resp['show_create_error'] = $e->getMessage();
        }
        try {
            $resp['id_stats'] = $pdo->query('SELECT MIN(id) min_id, MAX(id) max_id FROM ' . $traceTable)->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
        }
        try {
            $recent = $pdo->query('SELECT id,num_fiche,date_action,utilisateur FROM ' . $traceTable . ' ORDER BY id DESC LIMIT 10');
            $resp['recent_ids'] = $recent->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
        }
        try {
            if (!$forceAll && !$sqlWhere) {
                // Vérification qu'un SELECT simple renvoie bien des données
                $countAll = $pdo->query('SELECT COUNT(*) FROM ' . $traceTable)->fetchColumn();
                $resp['count_no_filter_control'] = (int)$countAll;
            }
        } catch (Throwable $e) {
        }
        $resp['server_time'] = date('Y-m-d H:i:s');
    }
    echo json_encode($resp);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
