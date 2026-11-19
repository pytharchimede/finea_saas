<?php
require_once __DIR__ . '/../../auth_check.php';
// Connexion base si $pdo non défini par auth_check
if (!isset($pdo)) {
    $dbFile1 = __DIR__ . '/../model/Database.php';
    $dbFile2 = __DIR__ . '/../../model/Database.php';
    if (file_exists($dbFile1)) require_once $dbFile1;
    else if (file_exists($dbFile2)) require_once $dbFile2;
    if (class_exists('Database')) {
        $pdo = Database::getConnection();
    } else {
        throw new Exception('Connexion DB indisponible');
    }
}
require_once __DIR__ . '/../model/FicheDecaissement.php';
require_once __DIR__ . '/../../model/AuditTrail.php';
header('Content-Type: application/json; charset=utf-8');

function enrichComptaRows(PDO $pdo, FicheDecaissement $model, array $rows): array
{
    if (!$rows) return $rows;
    $regularizations = [];
    try {
        if ($pdo->query("SHOW TABLES LIKE 'disbursement_regularizations'")->fetchColumn()) {
            $numbers = array_values(array_unique(array_filter(array_column($rows, 'num_fiche'))));
            $in = implode(',', array_fill(0, count($numbers), '?'));
            $stmt = $pdo->prepare("SELECT num_fiche, submitted_at, accounting_status, accounting_validated_at, completion_percentage FROM disbursement_regularizations WHERE num_fiche IN ($in)");
            $stmt->execute($numbers);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $record) $regularizations[(string)$record['num_fiche']] = $record;
        }
    } catch (Throwable $e) {
        $regularizations = [];
    }
    foreach ($rows as &$row) {
        $reg = $regularizations[(string)($row['num_fiche'] ?? '')] ?? null;
        $row['regularization_status'] = empty($row['decaisse']) ? 'not_applicable' : (!$reg || empty($reg['submitted_at']) ? 'not_submitted' : (string)($reg['accounting_status'] ?? 'pending'));
        $row['regularization_completion'] = $reg ? (float)($reg['completion_percentage'] ?? 0) : null;
        $analysis = $model->analyseBudgetFiche((string)($row['num_fiche'] ?? ''));
        $row['budget_ratio'] = $analysis ? round((float)$analysis['ratio'] * 100, 2) : null;
        $row['budget_line_amount'] = $analysis ? (float)$analysis['montant_ligne'] : null;
        $row['budget_line_label'] = $analysis ? (string)($analysis['ligne_designation'] ?? '') : '';
    }
    unset($row);
    return $rows;
}
try {
    $m = new FicheDecaissement($pdo);

    $page = (int)($_GET['page'] ?? 1);
    if ($page < 1) $page = 1;
    $perPage = (int)($_GET['per_page'] ?? 24);
    if ($perPage <= 0) $perPage = 24;
    // garde-fou anti-crash
    if ($perPage > 96) $perPage = 96;
    $offset = ($page - 1) * $perPage;

    $filters = [
        'beneficiaire' => $_GET['beneficiaire'] ?? '',
        'num_fiche' => $_GET['num_fiche'] ?? '',
        'designation' => $_GET['designation'] ?? '',
        'precision' => $_GET['precision'] ?? '',
        'montant_min' => $_GET['montant_min'] ?? '',
        'montant_max' => $_GET['montant_max'] ?? '',
        'date_min' => $_GET['date_min'] ?? '',
        'date_max' => $_GET['date_max'] ?? '',
        'date_valid_min' => $_GET['date_valid_min'] ?? '',
        'date_valid_max' => $_GET['date_valid_max'] ?? '',
        'chantier' => $_GET['chantier'] ?? '',
        'entreprise' => $_GET['entreprise'] ?? '',
        'ligne' => $_GET['ligne'] ?? '',
        'affectation' => $_GET['affectation'] ?? '',
        'status_filter' => $_GET['status_filter'] ?? ''
    ];
    $mode = $_GET['mode'] ?? '';
    if ($mode === 'all') {
        $summary = $m->summaryComptaAll($filters);
    } else {
        $summary = $m->summaryComptaReady($filters);
    }

    $totalCount = (int)($summary['count'] ?? 0);
    $total = (float)($summary['total'] ?? 0);
    $pages = $perPage > 0 ? (int)max(1, (int)ceil($totalCount / $perPage)) : 1;
    if ($page > $pages) $page = $pages;
    $offset = ($page - 1) * $perPage;

    if ($mode === 'all') {
        $rows = $m->listComptaAll($filters, $perPage, $offset);
    } else {
        $rows = $m->listComptaReady($filters, $perPage, $offset);
    }
    if (!isset($_GET['export'])) $rows = enrichComptaRows($pdo, $m, $rows);

    if (isset($_GET['export']) && $_GET['export'] === 'csv') {
        \AuditTrail::logFileEvent('export', [
            'module' => 'decaissement',
            'action' => 'fiches_compta_export',
            'entity_type' => 'fiche_compta_list',
            'entity_label' => 'Liste fiches compta',
            'format' => 'csv',
            'details' => [
                'mode' => $mode === 'all' ? 'all' : 'ready',
                'filters' => $filters,
                'count' => $totalCount,
                'total' => $total,
            ],
        ], $pdo);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="fiches_compta.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['num_fiche', 'beneficiaire', 'montant', 'designation', 'precision', 'date_creat', 'etat_fiche', 'date_min', 'last_action', 'last_action_date']);
        // Export: ignorer pagination (mais conserve les mêmes filtres et la politique de visibilité)
        $allRows = ($mode === 'all') ? $m->listComptaAll($filters) : $m->listComptaReady($filters);
        foreach ($allRows as $r) {
            fputcsv($out, [
                $r['num_fiche'],
                $r['beficiaire_fiche'],
                $r['montant_fiche'],
                $r['designation_fiche'],
                $r['precision_fiche'],
                $r['date_creat_fiche'],
                $r['etat_fiche'],
                $r['date_decaissement_minimum'] ?? '',
                $r['last_action'] ?? '',
                $r['last_action_date'] ?? ''
            ]);
        }
        fclose($out);
        exit;
    }
    echo json_encode([
        'ok' => true,
        'count' => $totalCount,
        'total' => $total,
        'page' => $page,
        'pages' => $pages,
        'per_page' => $perPage,
        'items' => $rows
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
