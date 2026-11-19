<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../model/AuditCoverageInspector.php';

$pdo = \Database::getConnection();
\AuditTrail::ensureSchema($pdo);
\AuditTrail::runMaintenance($pdo);
$retentionConfig = \AuditTrail::getRetentionConfig();
$coverageReport = \AuditCoverageInspector::buildReport(dirname(__DIR__));
$coverageAlert = \AuditCoverageInspector::syncAlertState($pdo, $coverageReport);
$newUncoveredPaths = $coverageAlert['new_uncovered_paths'] ?? [];
$newUncoveredLookup = array_fill_keys($newUncoveredPaths, true);

if (($coverageAlert['has_baseline'] ?? false) && $newUncoveredPaths !== []) {
    \AuditTrail::log('audit_coverage_warning', [
        'module' => 'admin',
        'action' => 'detect_uncovered_pages',
        'entity_type' => 'audit_coverage',
        'entity_label' => 'Couverture audit ERP',
        'result' => 'failed',
        'severity' => 'warning',
        'details' => [
            'new_uncovered_paths' => $newUncoveredPaths,
            'uncovered_total' => $coverageReport['counts']['uncovered_pages'] ?? 0,
            'generated_at' => $coverageReport['generated_at'] ?? null,
        ],
    ], $pdo);
}

function auditIsValidDate(string $value): bool
{
    if ($value === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return false;
    }

    $date = DateTime::createFromFormat('Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

function auditDecodeDetails(?string $json): array
{
    if ($json === null || trim($json) === '') {
        return [];
    }

    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}

function auditShortJson(array $data, int $limit = 180): string
{
    if ($data === []) {
        return 'Aucun détail';
    }

    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || $json === '') {
        return 'Détails indisponibles';
    }

    if (mb_strlen($json) <= $limit) {
        return $json;
    }

    return mb_substr($json, 0, $limit - 3) . '...';
}

function auditBuildSourceSql(string $scope): string
{
    if ($scope === 'archive') {
        return '(SELECT
            source_id AS id,
            event_type, module, action, entity_type, entity_id, entity_label,
            user_id, user_name, user_email, session_id,
            request_method, request_path, page_name, ip_address, user_agent,
            is_ajax, result, severity, old_value, new_value, comment, details_json, created_at,
            archived_at, archived_reason,
            1 AS is_archived
        FROM audit_trail_archive) log';
    }

    if ($scope === 'all') {
        return '(
            SELECT
                id,
                event_type, module, action, entity_type, entity_id, entity_label,
                user_id, user_name, user_email, session_id,
                request_method, request_path, page_name, ip_address, user_agent,
                is_ajax, result, severity, old_value, new_value, comment, details_json, created_at,
                NULL AS archived_at, NULL AS archived_reason,
                0 AS is_archived
            FROM audit_trail
            UNION ALL
            SELECT
                source_id AS id,
                event_type, module, action, entity_type, entity_id, entity_label,
                user_id, user_name, user_email, session_id,
                request_method, request_path, page_name, ip_address, user_agent,
                is_ajax, result, severity, old_value, new_value, comment, details_json, created_at,
                archived_at, archived_reason,
                1 AS is_archived
            FROM audit_trail_archive
        ) log';
    }

    return '(SELECT
        id,
        event_type, module, action, entity_type, entity_id, entity_label,
        user_id, user_name, user_email, session_id,
        request_method, request_path, page_name, ip_address, user_agent,
        is_ajax, result, severity, old_value, new_value, comment, details_json, created_at,
        NULL AS archived_at, NULL AS archived_reason,
        0 AS is_archived
    FROM audit_trail) log';
}

function auditFetchRows(PDO $pdo, string $sourceSql, string $whereSql, array $params, int $limit = 250): array
{
    $limit = max(1, $limit);
    $stmt = $pdo->prepare(
        "SELECT *
         FROM {$sourceSql}
         WHERE {$whereSql}
         ORDER BY created_at DESC, id DESC
         LIMIT {$limit}"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as &$row) {
        $row['details'] = auditDecodeDetails($row['details_json'] ?? null);
        if (!empty($row['old_value'])) {
            $row['details']['ancien_etat'] = auditDecodeDetails($row['old_value']) ?: $row['old_value'];
        }
        if (!empty($row['new_value'])) {
            $row['details']['nouvel_etat'] = auditDecodeDetails($row['new_value']) ?: $row['new_value'];
        }
        if (!empty($row['comment'])) {
            $row['details']['commentaire'] = $row['comment'];
        }
    }
    unset($row);

    return $rows;
}

function auditExportQueryString(array $filters, string $format): string
{
    $params = $filters;
    $params['export_format'] = $format;
    return http_build_query($params);
}

function auditCoverageExportQueryString(array $filters, array $coverageFilters, string $format): string
{
    $params = $filters;
    if (!empty($coverageFilters['uncovered_only'])) {
        $params['coverage_uncovered_only'] = '1';
    }
    if (!empty($coverageFilters['risk_only'])) {
        $params['coverage_risk_only'] = '1';
    }
    $params['coverage_export_format'] = $format;
    return http_build_query($params);
}

function auditCoveragePercent(int $covered, int $total): string
{
    if ($total <= 0) {
        return '0%';
    }

    return number_format(($covered / $total) * 100, 1, ',', ' ') . '%';
}

function auditCoverageBadgeClass(string $status): string
{
    if ($status === 'covered_auth_check') {
        return 'border-emerald-200 bg-emerald-50 text-emerald-700';
    }
    if ($status === 'covered_explicit') {
        return 'border-sky-200 bg-sky-50 text-sky-700';
    }
    if ($status === 'public_intentional') {
        return 'border-slate-200 bg-slate-100 text-slate-700';
    }

    return 'border-rose-200 bg-rose-50 text-rose-700';
}

function auditCoverageBadgeLabel(string $status): string
{
    if ($status === 'covered_auth_check') {
        return 'Standard';
    }
    if ($status === 'covered_explicit') {
        return 'Explicite';
    }
    if ($status === 'public_intentional') {
        return 'Public';
    }

    return 'NON COUVERT';
}

function auditRiskSeverity(array $signals): ?string
{
    if ($signals === []) {
        return null;
    }

    $criticalSignals = ['sql_mutation', 'appel_metier_mutation', 'fichier', 'zone_sensible'];
    foreach ($criticalSignals as $signal) {
        if (in_array($signal, $signals, true)) {
            return 'problem';
        }
    }

    return 'attention';
}

function auditRiskBadgeClass(?string $severity): string
{
    if ($severity === 'problem') {
        return 'border-slate-900 bg-slate-900 text-white print:border-black print:bg-white print:text-black';
    }
    if ($severity === 'attention') {
        return 'border-slate-500 bg-white text-slate-700 print:border-black print:bg-white print:text-black';
    }

    return 'border-slate-200 bg-white text-slate-500 print:border-slate-400 print:text-slate-600';
}

function auditRiskBadgeLabel(?string $severity): string
{
    if ($severity === 'problem') {
        return 'PROBLÈME';
    }
    if ($severity === 'attention') {
        return 'ATTENTION';
    }

    return 'LECTURE';
}

function auditFilterCoverageReport(array $report, array $coverageFilters): array
{
    $riskOnly = !empty($coverageFilters['risk_only']);
    $moduleSummary = $report['module_summary'] ?? [];
    if (!empty($coverageFilters['uncovered_only']) || $riskOnly) {
        $moduleSummary = array_values(array_filter($moduleSummary, static function (array $row) use ($riskOnly): bool {
            if ($riskOnly) {
                return (int) ($row['risky_uncovered'] ?? 0) > 0;
            }
            return (int) ($row['uncovered'] ?? 0) > 0;
        }));
    }

    $allowedModules = [];
    foreach ($moduleSummary as $row) {
        $module = (string) ($row['module'] ?? '');
        if ($module !== '') {
            $allowedModules[$module] = true;
        }
    }

    $filterByModule = !empty($coverageFilters['uncovered_only']) || $riskOnly;
    $uncoveredPages = array_values(array_filter($report['uncovered_pages'] ?? [], static function (array $page) use ($allowedModules, $filterByModule, $riskOnly): bool {
        if ($riskOnly && empty($page['is_risky'])) {
            return false;
        }
        if (!$filterByModule) {
            return true;
        }
        return isset($allowedModules[(string) ($page['module'] ?? '')]);
    }));
    $actionCandidates = array_values(array_filter($report['action_candidates'] ?? [], static function (array $candidate) use ($allowedModules, $filterByModule): bool {
        if (!$filterByModule) {
            return true;
        }
        return isset($allowedModules[(string) ($candidate['module'] ?? '')]);
    }));

    $totalPages = 0;
    $coveredPages = 0;
    $publicPages = 0;
    $uncoveredCount = 0;
    $riskyUncoveredCount = 0;
    foreach ($moduleSummary as $row) {
        $totalPages += (int) ($row['total'] ?? 0);
        $coveredPages += (int) ($row['covered_auth_check'] ?? 0) + (int) ($row['covered_explicit'] ?? 0);
        $publicPages += (int) ($row['public_intentional'] ?? 0);
        $uncoveredCount += (int) ($row['uncovered'] ?? 0);
        $riskyUncoveredCount += (int) ($row['risky_uncovered'] ?? 0);
    }

    return [
        'generated_at' => $report['generated_at'] ?? null,
        'module_summary' => $moduleSummary,
        'uncovered_pages' => $uncoveredPages,
        'action_candidates' => $actionCandidates,
        'counts' => [
            'total_pages' => $totalPages,
            'covered_pages' => $coveredPages,
            'public_pages' => $publicPages,
            'uncovered_pages' => $uncoveredCount,
            'risky_uncovered_pages' => $riskyUncoveredCount,
            'action_candidates' => count($actionCandidates),
        ],
    ];
}

$filters = [
    'scope' => trim((string) ($_GET['scope'] ?? 'active')),
    'event_type' => trim((string) ($_GET['event_type'] ?? '')),
    'module' => trim((string) ($_GET['module'] ?? '')),
    'entity_type' => trim((string) ($_GET['entity_type'] ?? '')),
    'page_name' => trim((string) ($_GET['page_name'] ?? '')),
    'user_id' => max(0, (int) ($_GET['user_id'] ?? 0)),
    'result' => trim((string) ($_GET['result'] ?? '')),
    'severity' => trim((string) ($_GET['severity'] ?? '')),
    'date_from' => trim((string) ($_GET['date_from'] ?? '')),
    'date_to' => trim((string) ($_GET['date_to'] ?? '')),
    'q' => trim((string) ($_GET['q'] ?? '')),
];

if (!in_array($filters['scope'], ['active', 'archive', 'all'], true)) {
    $filters['scope'] = 'active';
}

if (!auditIsValidDate($filters['date_from'])) {
    $filters['date_from'] = '';
}
if (!auditIsValidDate($filters['date_to'])) {
    $filters['date_to'] = '';
}
if ($filters['date_from'] !== '' && $filters['date_to'] !== '' && $filters['date_from'] > $filters['date_to']) {
    [$filters['date_from'], $filters['date_to']] = [$filters['date_to'], $filters['date_from']];
}

$where = ['1=1'];
$params = [];

$sourceSql = auditBuildSourceSql($filters['scope']);
$exportFormat = strtolower(trim((string) ($_GET['export_format'] ?? '')));
$coverageFilters = [
    'uncovered_only' => !empty($_GET['coverage_uncovered_only']),
    'risk_only' => !empty($_GET['coverage_risk_only']),
];
$coverageView = auditFilterCoverageReport($coverageReport, $coverageFilters);
$coverageExportFormat = strtolower(trim((string) ($_GET['coverage_export_format'] ?? '')));

if ($filters['event_type'] !== '') {
    $where[] = 'log.event_type = :event_type';
    $params[':event_type'] = $filters['event_type'];
}
if ($filters['module'] !== '') {
    $where[] = 'log.module = :module';
    $params[':module'] = $filters['module'];
}
if ($filters['entity_type'] !== '') {
    $where[] = 'log.entity_type = :entity_type';
    $params[':entity_type'] = $filters['entity_type'];
}
if ($filters['page_name'] !== '') {
    $where[] = 'log.page_name = :page_name';
    $params[':page_name'] = $filters['page_name'];
}
if ($filters['user_id'] > 0) {
    $where[] = 'log.user_id = :user_id';
    $params[':user_id'] = $filters['user_id'];
}
if ($filters['result'] !== '') {
    $where[] = 'log.result = :result';
    $params[':result'] = $filters['result'];
}
if ($filters['severity'] !== '') {
    $where[] = 'log.severity = :severity';
    $params[':severity'] = $filters['severity'];
}
if ($filters['date_from'] !== '') {
    $where[] = 'log.created_at >= :date_from';
    $params[':date_from'] = $filters['date_from'] . ' 00:00:00';
}
if ($filters['date_to'] !== '') {
    $where[] = 'log.created_at <= :date_to';
    $params[':date_to'] = $filters['date_to'] . ' 23:59:59';
}
if ($filters['q'] !== '') {
    $where[] = '(log.event_type LIKE :q OR log.action LIKE :q OR log.entity_label LIKE :q OR log.user_name LIKE :q OR log.user_email LIKE :q OR log.page_name LIKE :q OR log.request_path LIKE :q OR log.details_json LIKE :q)';
    $params[':q'] = '%' . $filters['q'] . '%';
}

$whereSql = implode(' AND ', $where);

$eventTypesStmt = $pdo->query('SELECT DISTINCT event_type FROM ' . $sourceSql . ' ORDER BY event_type ASC');
$eventTypes = $eventTypesStmt ? ($eventTypesStmt->fetchAll(PDO::FETCH_COLUMN) ?: []) : [];
$modulesStmt = $pdo->query('SELECT DISTINCT module FROM ' . $sourceSql . ' WHERE module IS NOT NULL AND module <> "" ORDER BY module ASC');
$modules = $modulesStmt ? ($modulesStmt->fetchAll(PDO::FETCH_COLUMN) ?: []) : [];
$entityTypesStmt = $pdo->query('SELECT DISTINCT entity_type FROM ' . $sourceSql . ' WHERE entity_type IS NOT NULL AND entity_type <> "" ORDER BY entity_type ASC');
$entityTypes = $entityTypesStmt ? ($entityTypesStmt->fetchAll(PDO::FETCH_COLUMN) ?: []) : [];
$pagesStmt = $pdo->query('SELECT DISTINCT page_name FROM ' . $sourceSql . ' WHERE page_name IS NOT NULL AND page_name <> "" ORDER BY page_name ASC');
$pages = $pagesStmt ? ($pagesStmt->fetchAll(PDO::FETCH_COLUMN) ?: []) : [];
$usersStmt = $pdo->query('SELECT DISTINCT user_id, user_name, user_email FROM ' . $sourceSql . ' WHERE user_id IS NOT NULL ORDER BY user_name ASC, user_email ASC');
$users = $usersStmt ? ($usersStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

$statsStmt = $pdo->prepare(
    "SELECT
        COUNT(*) AS total_events,
        SUM(CASE WHEN log.result = 'failed' THEN 1 ELSE 0 END) AS failed_events,
        SUM(CASE WHEN log.severity IN ('warning', 'error') THEN 1 ELSE 0 END) AS sensitive_events,
        COUNT(DISTINCT log.user_id) AS distinct_users,
        COUNT(DISTINCT COALESCE(log.module, '')) AS distinct_modules
     FROM {$sourceSql}
     WHERE {$whereSql}"
);
$statsStmt->execute($params);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$topModulesStmt = $pdo->prepare(
    "SELECT COALESCE(log.module, 'non-classe') AS label, COUNT(*) AS total
     FROM {$sourceSql}
     WHERE {$whereSql}
     GROUP BY COALESCE(log.module, 'non-classe')
     ORDER BY total DESC, label ASC
     LIMIT 6"
);
$topModulesStmt->execute($params);
$topModules = $topModulesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$topEventsStmt = $pdo->prepare(
    "SELECT log.event_type AS label, COUNT(*) AS total
     FROM {$sourceSql}
     WHERE {$whereSql}
     GROUP BY log.event_type
     ORDER BY total DESC, label ASC
     LIMIT 8"
);
$topEventsStmt->execute($params);
$topEvents = $topEventsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$rows = auditFetchRows($pdo, $sourceSql, $whereSql, $params, 250);

if (in_array($coverageExportFormat, ['csv', 'pdf'], true)) {
    $coverageScopeLabel = !empty($coverageFilters['risk_only'])
        ? 'pages_risque_'
        : (!empty($coverageFilters['uncovered_only']) ? 'modules_non_couverts_' : 'global_');
    $coverageFileBase = 'audit_coverage_synthetique_' . $coverageScopeLabel . date('Ymd_His');

    \AuditTrail::logFileEvent('export', [
        'module' => 'admin',
        'action' => 'audit_coverage_export',
        'entity_type' => 'audit_coverage',
        'entity_label' => 'Vue couverture audit ERP',
        'format' => $coverageExportFormat,
        'details' => [
            'filters' => $coverageFilters,
            'module_count' => count($coverageView['module_summary'] ?? []),
            'uncovered_count' => $coverageView['counts']['uncovered_pages'] ?? 0,
            'risky_uncovered_count' => $coverageView['counts']['risky_uncovered_pages'] ?? 0,
            'action_candidate_count' => $coverageView['counts']['action_candidates'] ?? 0,
        ],
    ], $pdo);

    if ($coverageExportFormat === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $coverageFileBase . '.csv"');
        header('Cache-Control: max-age=0');

        $out = fopen('php://output', 'w');
        fputcsv($out, ['section', 'generated_at', 'filter_uncovered_only', 'filter_risk_only']);
        fputcsv($out, ['meta', (string) ($coverageView['generated_at'] ?? ''), !empty($coverageFilters['uncovered_only']) ? '1' : '0', !empty($coverageFilters['risk_only']) ? '1' : '0']);
        fputcsv($out, []);

        fputcsv($out, ['section', 'module', 'total', 'covered_auth_check', 'covered_explicit', 'public_intentional', 'uncovered', 'risky_uncovered', 'coverage_percent']);
        foreach ($coverageView['module_summary'] as $row) {
            $coveredCount = (int) ($row['covered_auth_check'] ?? 0) + (int) ($row['covered_explicit'] ?? 0);
            $totalCount = (int) ($row['total'] ?? 0);
            fputcsv($out, [
                'module_summary',
                (string) ($row['module'] ?? ''),
                $totalCount,
                (int) ($row['covered_auth_check'] ?? 0),
                (int) ($row['covered_explicit'] ?? 0),
                (int) ($row['public_intentional'] ?? 0),
                (int) ($row['uncovered'] ?? 0),
                (int) ($row['risky_uncovered'] ?? 0),
                auditCoveragePercent($coveredCount, $totalCount),
            ]);
        }

        fputcsv($out, []);
        fputcsv($out, ['section', 'path', 'module', 'status', 'coverage_label', 'is_risky', 'risk_signals', 'modified_at']);
        foreach ($coverageView['uncovered_pages'] as $page) {
            fputcsv($out, [
                'uncovered_page',
                (string) ($page['path'] ?? ''),
                (string) ($page['module'] ?? ''),
                (string) ($page['status'] ?? ''),
                (string) ($page['coverage_label'] ?? ''),
                !empty($page['is_risky']) ? '1' : '0',
                implode(', ', $page['risk_signals'] ?? []),
                (string) ($page['modified_at'] ?? ''),
            ]);
        }

        fputcsv($out, []);
        fputcsv($out, ['section', 'path', 'module', 'status', 'coverage_label', 'signals']);
        foreach ($coverageView['action_candidates'] as $candidate) {
            fputcsv($out, [
                'action_candidate',
                (string) ($candidate['path'] ?? ''),
                (string) ($candidate['module'] ?? ''),
                (string) ($candidate['status'] ?? ''),
                (string) ($candidate['coverage_label'] ?? ''),
                implode(', ', $candidate['signals'] ?? []),
            ]);
        }
        fclose($out);
        exit;
    }

    class AuditCoveragePdfExport extends TCPDF
    {
        public function Header(): void
        {
            $this->SetY(8);
            $this->SetFont('dejavusans', 'B', 14);
            $this->Cell(0, 8, 'Audit ERP - Couverture synthétique', 0, 1, 'C');
        }

        public function Footer(): void
        {
            $this->SetY(-12);
            $this->SetFont('dejavusans', '', 8);
            $this->Cell(0, 6, 'Page ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, 0, 'C');
        }
    }

    $pdf = new AuditCoveragePdfExport('L', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('Banamur');
    $pdf->SetAuthor('Banamur');
    $pdf->SetTitle('Audit ERP couverture synthétique');
    $pdf->SetMargins(8, 18, 8);
    $pdf->SetAutoPageBreak(true, 12);
    $pdf->AddPage();
    $pdf->SetFont('dejavusans', '', 8);

    $pdf->MultiCell(0, 5, 'Analyse générée le ' . (string) ($coverageView['generated_at'] ?? '') . ' | Filtre modules non couverts: ' . (!empty($coverageFilters['uncovered_only']) ? 'oui' : 'non') . ' | Filtre pages à risque: ' . (!empty($coverageFilters['risk_only']) ? 'oui' : 'non'), 0, 'L', false, 1);
    $pdf->Ln(1);

    $html = '<style>
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #cbd5e1; padding: 4px; font-size: 7px; }
        th { background-color: #eef2ff; font-weight: bold; }
        h2 { font-size: 10px; }
    </style>';

    $html .= '<h2>Résumé par module</h2><table><thead><tr>';
    $html .= '<th width="30">Module</th><th width="24">Couverture</th><th width="20">Standard</th><th width="20">Explicite</th><th width="18">Public</th><th width="18">Non couvert</th><th width="22">Risque</th>';
    $html .= '</tr></thead><tbody>';
    foreach ($coverageView['module_summary'] as $row) {
        $coveredCount = (int) ($row['covered_auth_check'] ?? 0) + (int) ($row['covered_explicit'] ?? 0);
        $totalCount = (int) ($row['total'] ?? 0);
        $html .= '<tr>';
        $html .= '<td width="30">' . htmlspecialchars((string) ($row['module'] ?? '')) . '</td>';
        $html .= '<td width="24">' . htmlspecialchars(auditCoveragePercent($coveredCount, $totalCount)) . '</td>';
        $html .= '<td width="20">' . (int) ($row['covered_auth_check'] ?? 0) . '</td>';
        $html .= '<td width="20">' . (int) ($row['covered_explicit'] ?? 0) . '</td>';
        $html .= '<td width="18">' . (int) ($row['public_intentional'] ?? 0) . '</td>';
        $html .= '<td width="18">' . (int) ($row['uncovered'] ?? 0) . '</td>';
        $html .= '<td width="22">' . (int) ($row['risky_uncovered'] ?? 0) . '</td>';
        $html .= '</tr>';
    }
    $html .= '</tbody></table>';

    $html .= '<h2>Pages non couvertes</h2><table><thead><tr>';
    $html .= '<th width="78">Chemin</th><th width="20">Module</th><th width="24">Statut</th><th width="28">Risque</th><th width="34">Modifié</th><th width="80">Convention</th>';
    $html .= '</tr></thead><tbody>';
    foreach ($coverageView['uncovered_pages'] as $page) {
        $html .= '<tr>';
        $html .= '<td width="78">' . htmlspecialchars((string) ($page['path'] ?? '')) . '</td>';
        $html .= '<td width="20">' . htmlspecialchars((string) ($page['module'] ?? '')) . '</td>';
        $html .= '<td width="24">' . htmlspecialchars(auditCoverageBadgeLabel((string) ($page['status'] ?? ''))) . '</td>';
        $riskSeverity = auditRiskSeverity($page['risk_signals'] ?? []);
        $riskLabel = auditRiskBadgeLabel($riskSeverity);
        $riskDetails = !empty($page['is_risky']) ? ($riskLabel . ' - ' . implode(', ', $page['risk_signals'] ?? [])) : $riskLabel;
        $html .= '<td width="28">' . htmlspecialchars($riskDetails) . '</td>';
        $html .= '<td width="34">' . htmlspecialchars((string) ($page['modified_at'] ?? '')) . '</td>';
        $html .= '<td width="80">' . htmlspecialchars((string) ($page['coverage_label'] ?? '')) . '</td>';
        $html .= '</tr>';
    }
    $html .= '</tbody></table>';

    $html .= '<h2>Actions métier à approfondir</h2><table><thead><tr>';
    $html .= '<th width="90">Chemin</th><th width="24">Module</th><th width="28">Statut</th><th width="90">Convention</th><th width="62">Signaux</th>';
    $html .= '</tr></thead><tbody>';
    foreach ($coverageView['action_candidates'] as $candidate) {
        $html .= '<tr>';
        $html .= '<td width="90">' . htmlspecialchars((string) ($candidate['path'] ?? '')) . '</td>';
        $html .= '<td width="24">' . htmlspecialchars((string) ($candidate['module'] ?? '')) . '</td>';
        $html .= '<td width="28">' . htmlspecialchars(auditCoverageBadgeLabel((string) ($candidate['status'] ?? ''))) . '</td>';
        $html .= '<td width="90">' . htmlspecialchars((string) ($candidate['coverage_label'] ?? '')) . '</td>';
        $html .= '<td width="62">' . htmlspecialchars(implode(', ', $candidate['signals'] ?? [])) . '</td>';
        $html .= '</tr>';
    }
    $html .= '</tbody></table>';

    $pdf->writeHTML($html, true, false, true, false, '');
    $pdf->Output($coverageFileBase . '.pdf', 'D');
    exit;
}

if (in_array($exportFormat, ['csv', 'pdf'], true)) {
    $exportLimit = $exportFormat === 'pdf' ? 1500 : 5000;
    $exportRows = auditFetchRows($pdo, $sourceSql, $whereSql, $params, $exportLimit);
    $fileBase = 'audit_erp_' . $filters['scope'] . '_' . date('Ymd_His');

    \AuditTrail::logFileEvent('export', [
        'module' => 'admin',
        'action' => 'audit_log_export',
        'entity_type' => 'audit_trail',
        'entity_label' => 'Journal audit ERP',
        'format' => $exportFormat,
        'details' => [
            'scope' => $filters['scope'],
            'row_count' => count($exportRows),
            'filters' => $filters,
        ],
    ], $pdo);

    if ($exportFormat === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $fileBase . '.csv"');
        header('Cache-Control: max-age=0');

        $out = fopen('php://output', 'w');
        fputcsv($out, ['date', 'event_type', 'action', 'module', 'entity_type', 'entity_id', 'entity_label', 'user_id', 'user_name', 'user_email', 'result', 'severity', 'request_method', 'page_name', 'request_path', 'scope', 'archived_at', 'details_json']);
        foreach ($exportRows as $row) {
            fputcsv($out, [
                (string) ($row['created_at'] ?? ''),
                (string) ($row['event_type'] ?? ''),
                (string) ($row['action'] ?? ''),
                (string) ($row['module'] ?? ''),
                (string) ($row['entity_type'] ?? ''),
                (string) ($row['entity_id'] ?? ''),
                (string) ($row['entity_label'] ?? ''),
                (string) ($row['user_id'] ?? ''),
                (string) ($row['user_name'] ?? ''),
                (string) ($row['user_email'] ?? ''),
                (string) ($row['result'] ?? ''),
                (string) ($row['severity'] ?? ''),
                (string) ($row['request_method'] ?? ''),
                (string) ($row['page_name'] ?? ''),
                (string) ($row['request_path'] ?? ''),
                !empty($row['is_archived']) ? 'archive' : 'active',
                (string) ($row['archived_at'] ?? ''),
                json_encode($row['details'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        }
        fclose($out);
        exit;
    }

    class AuditTrailPdfExport extends TCPDF
    {
        public function Header(): void
        {
            $this->SetY(8);
            $this->SetFont('dejavusans', 'B', 14);
            $this->Cell(0, 8, 'Audit ERP - Export filtre', 0, 1, 'C');
        }

        public function Footer(): void
        {
            $this->SetY(-12);
            $this->SetFont('dejavusans', '', 8);
            $this->Cell(0, 6, 'Page ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, 0, 'C');
        }
    }

    $pdf = new AuditTrailPdfExport('L', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('Banamur');
    $pdf->SetAuthor('Banamur');
    $pdf->SetTitle('Audit ERP export');
    $pdf->SetMargins(8, 18, 8);
    $pdf->SetAutoPageBreak(true, 12);
    $pdf->AddPage();
    $pdf->SetFont('dejavusans', '', 8);

    $filterSummary = [];
    foreach ($filters as $key => $value) {
        if ($value === '' || $value === 0) {
            continue;
        }
        $filterSummary[] = $key . ': ' . (is_scalar($value) ? (string) $value : json_encode($value));
    }

    $pdf->MultiCell(0, 5, 'Filtres: ' . ($filterSummary !== [] ? implode(' | ', $filterSummary) : 'aucun') . ' | Lignes: ' . count($exportRows), 0, 'L', false, 1);
    $pdf->Ln(1);

    $html = '<style>
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #cbd5e1; padding: 4px; font-size: 7px; }
        th { background-color: #eef2ff; font-weight: bold; }
    </style>';
    $html .= '<table><thead><tr>';
    $html .= '<th width="30">Date</th><th width="30">Event</th><th width="24">Module</th><th width="28">Utilisateur</th><th width="32">Entite</th><th width="14">Statut</th><th width="18">Page</th><th width="88">Details</th>';
    $html .= '</tr></thead><tbody>';
    foreach ($exportRows as $row) {
        $userLabel = trim((string) ($row['user_name'] ?? ''));
        if ($userLabel === '') {
            $userLabel = trim((string) ($row['user_email'] ?? 'Anonyme'));
        }
        $entityLabel = trim((string) ($row['entity_label'] ?? ''));
        if ($entityLabel === '') {
            $entityLabel = trim((string) ($row['entity_type'] ?? 'Non renseignee'));
        }
        $status = (string) ($row['result'] ?? 'success') . ' / ' . (string) ($row['severity'] ?? 'info');
        $html .= '<tr>';
        $html .= '<td width="30">' . htmlspecialchars((string) ($row['created_at'] ?? '')) . '</td>';
        $html .= '<td width="30">' . htmlspecialchars((string) ($row['event_type'] ?? '')) . '<br>' . htmlspecialchars((string) ($row['action'] ?? '')) . '</td>';
        $html .= '<td width="24">' . htmlspecialchars((string) ($row['module'] ?? '')) . '</td>';
        $html .= '<td width="28">' . htmlspecialchars($userLabel) . '</td>';
        $html .= '<td width="32">' . htmlspecialchars($entityLabel) . '</td>';
        $html .= '<td width="14">' . htmlspecialchars($status) . '</td>';
        $html .= '<td width="18">' . htmlspecialchars((string) ($row['page_name'] ?? '')) . '<br>' . htmlspecialchars(!empty($row['is_archived']) ? 'Archive' : 'Actif') . '</td>';
        $html .= '<td width="88">' . htmlspecialchars(auditShortJson($row['details'] ?? [], 320)) . '</td>';
        $html .= '</tr>';
    }
    $html .= '</tbody></table>';
    $pdf->writeHTML($html, true, false, true, false, '');
    $pdf->Output($fileBase . '.pdf', 'D');
    exit;
}

$csvExportUrl = 'audit_erp.php?' . auditExportQueryString($filters, 'csv');
$pdfExportUrl = 'audit_erp.php?' . auditExportQueryString($filters, 'pdf');
$coverageCsvExportUrl = 'audit_erp.php?' . auditCoverageExportQueryString($filters, $coverageFilters, 'csv');
$coveragePdfExportUrl = 'audit_erp.php?' . auditCoverageExportQueryString($filters, $coverageFilters, 'pdf');

$pageTitle = 'Audit ERP';
$activeMenu = 'admin';

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>
<div class="min-h-screen bg-[radial-gradient(circle_at_top_left,_rgba(254,242,242,0.9),_transparent_38%),radial-gradient(circle_at_top_right,_rgba(224,231,255,0.72),_transparent_32%),linear-gradient(180deg,_#f8fafc_0%,_#eef2ff_100%)] pb-12">
    <div class="container mx-auto px-4 py-8">
        <div class="relative overflow-hidden rounded-[32px] border border-rose-100 bg-gradient-to-r from-slate-950 via-slate-900 to-rose-900 px-8 py-10 text-white shadow-[0_24px_80px_rgba(15,23,42,0.32)]">
            <div class="absolute -right-14 -top-14 h-48 w-48 rounded-full bg-white/10 blur-2xl"></div>
            <div class="absolute bottom-0 right-24 h-24 w-24 rounded-full bg-rose-300/20 blur-2xl"></div>
            <div class="relative flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="mb-3 text-xs font-semibold uppercase tracking-[0.35em] text-rose-200">Administration</p>
                    <h1 class="text-3xl font-semibold tracking-tight md:text-5xl">Audit ERP central</h1>
                    <p class="mt-4 max-w-3xl text-sm text-slate-200 md:text-base">
                        Consultez en un point unique les accès, refus, exports, téléchargements et actions métier remontés par le journal transversal de l'ERP.
                    </p>
                </div>
                <div class="flex flex-wrap gap-3">
                    <a href="dashboard_admin.php" class="inline-flex items-center rounded-2xl border border-white/20 px-5 py-3 text-sm font-semibold text-white/90 transition hover:bg-white/10">
                        <i class="fas fa-arrow-left mr-2"></i>
                        Retour admin
                    </a>
                </div>
            </div>
        </div>

        <form method="get" class="mt-8 rounded-[28px] border border-white/80 bg-white/90 p-6 shadow-[0_18px_60px_rgba(15,23,42,0.07)] backdrop-blur">
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <div>
                    <label class="mb-2 block text-sm font-medium text-slate-700">Périmètre</label>
                    <select name="scope" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-rose-400 focus:ring-2 focus:ring-rose-100">
                        <option value="active" <?= $filters['scope'] === 'active' ? 'selected' : '' ?>>Actif</option>
                        <option value="archive" <?= $filters['scope'] === 'archive' ? 'selected' : '' ?>>Archive</option>
                        <option value="all" <?= $filters['scope'] === 'all' ? 'selected' : '' ?>>Actif + archive</option>
                    </select>
                </div>
                <div>
                    <label class="mb-2 block text-sm font-medium text-slate-700">Évènement</label>
                    <select name="event_type" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-rose-400 focus:ring-2 focus:ring-rose-100">
                        <option value="">Tous</option>
                        <?php foreach ($eventTypes as $eventType): ?>
                            <option value="<?= htmlspecialchars((string) $eventType) ?>" <?= $filters['event_type'] === (string) $eventType ? 'selected' : '' ?>><?= htmlspecialchars((string) $eventType) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="mb-2 block text-sm font-medium text-slate-700">Module</label>
                    <select name="module" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-rose-400 focus:ring-2 focus:ring-rose-100">
                        <option value="">Tous</option>
                        <?php foreach ($modules as $module): ?>
                            <option value="<?= htmlspecialchars((string) $module) ?>" <?= $filters['module'] === (string) $module ? 'selected' : '' ?>><?= htmlspecialchars((string) $module) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="mb-2 block text-sm font-medium text-slate-700">Type d'entité</label>
                    <select name="entity_type" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-rose-400 focus:ring-2 focus:ring-rose-100">
                        <option value="">Tous</option>
                        <?php foreach ($entityTypes as $entityType): ?>
                            <option value="<?= htmlspecialchars((string) $entityType) ?>" <?= $filters['entity_type'] === (string) $entityType ? 'selected' : '' ?>><?= htmlspecialchars((string) $entityType) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="mb-2 block text-sm font-medium text-slate-700">Page</label>
                    <select name="page_name" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-rose-400 focus:ring-2 focus:ring-rose-100">
                        <option value="">Toutes</option>
                        <?php foreach ($pages as $pageName): ?>
                            <option value="<?= htmlspecialchars((string) $pageName) ?>" <?= $filters['page_name'] === (string) $pageName ? 'selected' : '' ?>><?= htmlspecialchars((string) $pageName) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="mb-2 block text-sm font-medium text-slate-700">Utilisateur</label>
                    <select name="user_id" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-rose-400 focus:ring-2 focus:ring-rose-100">
                        <option value="0">Tous</option>
                        <?php foreach ($users as $user): ?>
                            <?php $userLabel = trim((string) ($user['user_name'] ?? '')) !== '' ? (string) $user['user_name'] : ((string) ($user['user_email'] ?? 'Utilisateur #' . ($user['user_id'] ?? ''))); ?>
                            <option value="<?= (int) ($user['user_id'] ?? 0) ?>" <?= $filters['user_id'] === (int) ($user['user_id'] ?? 0) ? 'selected' : '' ?>><?= htmlspecialchars($userLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="mb-2 block text-sm font-medium text-slate-700">Résultat</label>
                    <select name="result" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-rose-400 focus:ring-2 focus:ring-rose-100">
                        <option value="">Tous</option>
                        <option value="success" <?= $filters['result'] === 'success' ? 'selected' : '' ?>>Succès</option>
                        <option value="failed" <?= $filters['result'] === 'failed' ? 'selected' : '' ?>>Échec</option>
                    </select>
                </div>
                <div>
                    <label class="mb-2 block text-sm font-medium text-slate-700">Sévérité</label>
                    <select name="severity" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-rose-400 focus:ring-2 focus:ring-rose-100">
                        <option value="">Toutes</option>
                        <option value="info" <?= $filters['severity'] === 'info' ? 'selected' : '' ?>>Info</option>
                        <option value="warning" <?= $filters['severity'] === 'warning' ? 'selected' : '' ?>>Attention</option>
                        <option value="error" <?= $filters['severity'] === 'error' ? 'selected' : '' ?>>Erreur</option>
                    </select>
                </div>
                <div>
                    <label class="mb-2 block text-sm font-medium text-slate-700">Date début</label>
                    <input type="date" name="date_from" value="<?= htmlspecialchars($filters['date_from']) ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-rose-400 focus:ring-2 focus:ring-rose-100">
                </div>
                <div>
                    <label class="mb-2 block text-sm font-medium text-slate-700">Date fin</label>
                    <input type="date" name="date_to" value="<?= htmlspecialchars($filters['date_to']) ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-rose-400 focus:ring-2 focus:ring-rose-100">
                </div>
                <div>
                    <label class="mb-2 block text-sm font-medium text-slate-700">Recherche libre</label>
                    <input type="text" name="q" value="<?= htmlspecialchars($filters['q']) ?>" placeholder="action, fichier, utilisateur, détail..." class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-rose-400 focus:ring-2 focus:ring-rose-100">
                </div>
            </div>
            <div class="mt-5 flex flex-wrap gap-3">
                <button type="submit" class="inline-flex items-center rounded-2xl bg-slate-950 px-5 py-3 text-sm font-semibold text-white transition hover:bg-slate-800">
                    <i class="fas fa-filter mr-2"></i>
                    Filtrer
                </button>
                <a href="<?= htmlspecialchars($csvExportUrl) ?>" class="inline-flex items-center rounded-2xl bg-emerald-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-emerald-700">
                    <i class="fas fa-file-csv mr-2"></i>
                    Export CSV
                </a>
                <a href="<?= htmlspecialchars($pdfExportUrl) ?>" class="inline-flex items-center rounded-2xl bg-rose-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-rose-700">
                    <i class="fas fa-file-pdf mr-2"></i>
                    Export PDF
                </a>
                <a href="audit_erp.php" class="inline-flex items-center rounded-2xl border border-slate-200 px-5 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">
                    <i class="fas fa-rotate-left mr-2"></i>
                    Réinitialiser
                </a>
            </div>
        </form>

        <div class="mt-6 rounded-[24px] border border-indigo-100 bg-indigo-50/80 p-5 shadow-[0_18px_50px_rgba(15,23,42,0.05)]">
            <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.28em] text-indigo-600">Politique de rétention</p>
                    <p class="mt-2 text-sm text-slate-700">Les évènements actifs sont archivés après <?= (int) ($retentionConfig['active_retention_days'] ?? 180) ?> jours, puis les archives sont purgées après <?= (int) ($retentionConfig['archive_retention_days'] ?? 730) ?> jours.</p>
                </div>
                <div class="text-sm font-semibold text-indigo-700">
                    Périmètre courant : <?= htmlspecialchars($filters['scope']) ?>
                </div>
            </div>
        </div>

        <?php if (($coverageAlert['has_baseline'] ?? false) && $newUncoveredPaths !== []): ?>
            <div class="mt-6 rounded-[24px] border border-rose-200 bg-rose-50/90 p-5 shadow-[0_18px_50px_rgba(15,23,42,0.05)]">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.28em] text-rose-600">Alerte automatique</p>
                        <h2 class="mt-2 text-lg font-semibold text-rose-900">Nouvelle page PHP détectée hors convention d'audit</h2>
                        <p class="mt-2 text-sm text-rose-800">Le contrôle compare la couverture actuelle au dernier snapshot enregistré. Toute page nouvelle ou redevenue non couverte remonte ici et dans le journal central.</p>
                    </div>
                    <div class="rounded-2xl border border-rose-200 bg-white/70 px-4 py-3 text-sm font-semibold text-rose-700">
                        <?= number_format(count($newUncoveredPaths), 0, ',', ' ') ?> nouvelle(s) page(s) non couverte(s)
                    </div>
                </div>
                <div class="mt-4 flex flex-wrap gap-2">
                    <?php foreach ($coverageReport['uncovered_pages'] as $uncoveredPage): ?>
                        <?php if (!isset($newUncoveredLookup[$uncoveredPage['path']])): ?>
                            <?php continue; ?>
                        <?php endif; ?>
                        <span class="inline-flex items-center rounded-full border border-rose-200 bg-white px-3 py-1 text-xs font-semibold text-rose-700">
                            <?= htmlspecialchars((string) $uncoveredPage['path']) ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <section class="mt-8 rounded-[30px] border border-white/80 bg-white/90 p-6 shadow-[0_18px_60px_rgba(15,23,42,0.07)] backdrop-blur">
            <div class="flex flex-col gap-2 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.28em] text-indigo-600">Couverture audit</p>
                    <h2 class="mt-2 text-xl font-semibold text-slate-950">Vue synthétique par module</h2>
                    <p class="mt-2 text-sm text-slate-500">Cette vue synthétique combine couverture, pages non couvertes et candidats métier pour le pilotage audit.</p>
                    <p class="mt-1 text-xs font-medium text-slate-400">
                        <?php if (!empty($coverageFilters['risk_only'])): ?>
                            Filtre actif : pages non couvertes à risque uniquement.
                        <?php elseif (!empty($coverageFilters['uncovered_only'])): ?>
                            Filtre actif : modules avec pages non couvertes uniquement.
                        <?php else: ?>
                            Affichage global de tous les modules scannés.
                        <?php endif; ?>
                    </p>
                </div>
                <div class="text-sm text-slate-500">
                    Analyse générée le <?= htmlspecialchars((string) ($coverageView['generated_at'] ?? '')) ?>
                </div>
            </div>

            <form method="get" class="mt-5 flex flex-col gap-3 rounded-[24px] border border-slate-200 bg-slate-50/80 p-4 lg:flex-row lg:items-center lg:justify-between">
                <div class="flex flex-wrap items-center gap-3">
                    <?php foreach ($filters as $filterName => $filterValue): ?>
                        <input type="hidden" name="<?= htmlspecialchars((string) $filterName) ?>" value="<?= htmlspecialchars((string) $filterValue) ?>">
                    <?php endforeach; ?>
                    <label class="inline-flex items-center gap-3 rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-medium text-slate-700">
                        <input type="checkbox" name="coverage_uncovered_only" value="1" <?= !empty($coverageFilters['uncovered_only']) ? 'checked' : '' ?> class="h-4 w-4 rounded border-slate-300 text-rose-600 focus:ring-rose-400">
                        N'afficher que les modules avec pages non couvertes
                    </label>
                    <label class="inline-flex items-center gap-3 rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-medium text-slate-700">
                        <input type="checkbox" name="coverage_risk_only" value="1" <?= !empty($coverageFilters['risk_only']) ? 'checked' : '' ?> class="h-4 w-4 rounded border-slate-300 text-amber-600 focus:ring-amber-400">
                        Ne garder que les pages non couvertes à risque
                    </label>
                    <button type="submit" class="inline-flex items-center rounded-2xl bg-slate-950 px-4 py-3 text-sm font-semibold text-white transition hover:bg-slate-800">
                        <i class="fas fa-sliders mr-2"></i>
                        Appliquer à la couverture
                    </button>
                </div>
                <div class="flex flex-wrap gap-3">
                    <a href="<?= htmlspecialchars($coverageCsvExportUrl) ?>" class="inline-flex items-center rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700 transition hover:bg-emerald-100">
                        <i class="fas fa-file-csv mr-2"></i>
                        CSV synthétique
                    </a>
                    <a href="<?= htmlspecialchars($coveragePdfExportUrl) ?>" class="inline-flex items-center rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700 transition hover:bg-rose-100">
                        <i class="fas fa-file-pdf mr-2"></i>
                        PDF synthétique
                    </a>
                </div>
            </form>

            <div class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-5">
                <div class="rounded-[24px] border border-emerald-100 bg-emerald-50/80 p-5">
                    <p class="text-xs font-semibold uppercase tracking-[0.28em] text-emerald-600">Pages couvertes</p>
                    <p class="mt-3 text-3xl font-semibold text-emerald-700"><?= number_format((int) ($coverageView['counts']['covered_pages'] ?? 0), 0, ',', ' ') ?></p>
                    <p class="mt-2 text-sm text-emerald-800"><?= auditCoveragePercent((int) ($coverageView['counts']['covered_pages'] ?? 0), (int) ($coverageView['counts']['total_pages'] ?? 0)) ?> du périmètre affiché</p>
                </div>
                <div class="rounded-[24px] border border-rose-100 bg-rose-50/80 p-5">
                    <p class="text-xs font-semibold uppercase tracking-[0.28em] text-rose-600">Pages non couvertes</p>
                    <p class="mt-3 text-3xl font-semibold text-rose-700"><?= number_format((int) ($coverageView['counts']['uncovered_pages'] ?? 0), 0, ',', ' ') ?></p>
                    <p class="mt-2 text-sm text-rose-800">À corriger prioritairement</p>
                </div>
                <div class="rounded-[24px] border border-slate-200 bg-slate-50/80 p-5">
                    <p class="text-xs font-semibold uppercase tracking-[0.28em] text-slate-500">Pages publiques</p>
                    <p class="mt-3 text-3xl font-semibold text-slate-700"><?= number_format((int) ($coverageView['counts']['public_pages'] ?? 0), 0, ',', ' ') ?></p>
                    <p class="mt-2 text-sm text-slate-600">Exclues intentionnellement du flux authentifié</p>
                </div>
                <div class="rounded-[24px] border border-amber-200 bg-amber-50/80 p-5">
                    <p class="text-xs font-semibold uppercase tracking-[0.28em] text-amber-700">Pages à risque</p>
                    <p class="mt-3 text-3xl font-semibold text-amber-700"><?= number_format((int) ($coverageView['counts']['risky_uncovered_pages'] ?? 0), 0, ',', ' ') ?></p>
                    <p class="mt-2 text-sm text-amber-800">Formulaires, mutations, fichiers ou zones sensibles</p>
                </div>
                <div class="rounded-[24px] border border-amber-100 bg-amber-50/80 p-5">
                    <p class="text-xs font-semibold uppercase tracking-[0.28em] text-amber-600">Actions à approfondir</p>
                    <p class="mt-3 text-3xl font-semibold text-amber-700"><?= number_format((int) ($coverageView['counts']['action_candidates'] ?? 0), 0, ',', ' ') ?></p>
                    <p class="mt-2 text-sm text-amber-800">Mutations métier sans trace fine détectée</p>
                </div>
            </div>

            <div class="mt-6 overflow-x-auto rounded-[24px] border border-slate-200">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-semibold text-slate-600">Module</th>
                            <th class="px-4 py-3 text-left font-semibold text-slate-600">Couverture</th>
                            <th class="px-4 py-3 text-left font-semibold text-slate-600">Standard</th>
                            <th class="px-4 py-3 text-left font-semibold text-slate-600">Explicite</th>
                            <th class="px-4 py-3 text-left font-semibold text-slate-600">Public</th>
                            <th class="px-4 py-3 text-left font-semibold text-slate-600">Non couvert</th>
                            <th class="px-4 py-3 text-left font-semibold text-slate-600">À risque</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 bg-white">
                        <?php if (($coverageView['module_summary'] ?? []) === []): ?>
                            <tr>
                                <td colspan="7" class="px-4 py-8 text-center text-slate-500">Aucun module ne correspond au filtre de couverture courant.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($coverageView['module_summary'] as $coverageRow): ?>
                                <?php
                                $coveredCount = (int) ($coverageRow['covered_auth_check'] ?? 0) + (int) ($coverageRow['covered_explicit'] ?? 0);
                                $totalCount = (int) ($coverageRow['total'] ?? 0);
                                ?>
                                <tr>
                                    <td class="px-4 py-4 font-semibold text-slate-900"><?= htmlspecialchars((string) ($coverageRow['module'] ?? 'core')) ?></td>
                                    <td class="px-4 py-4 text-slate-700"><?= auditCoveragePercent($coveredCount, $totalCount) ?> <span class="text-xs text-slate-400">(<?= $coveredCount ?>/<?= $totalCount ?>)</span></td>
                                    <td class="px-4 py-4 text-emerald-700"><?= number_format((int) ($coverageRow['covered_auth_check'] ?? 0), 0, ',', ' ') ?></td>
                                    <td class="px-4 py-4 text-sky-700"><?= number_format((int) ($coverageRow['covered_explicit'] ?? 0), 0, ',', ' ') ?></td>
                                    <td class="px-4 py-4 text-slate-600"><?= number_format((int) ($coverageRow['public_intentional'] ?? 0), 0, ',', ' ') ?></td>
                                    <td class="px-4 py-4 font-semibold <?= (int) ($coverageRow['uncovered'] ?? 0) > 0 ? 'text-rose-700' : 'text-slate-500' ?>"><?= number_format((int) ($coverageRow['uncovered'] ?? 0), 0, ',', ' ') ?></td>
                                    <td class="px-4 py-4 font-semibold <?= (int) ($coverageRow['risky_uncovered'] ?? 0) > 0 ? 'text-amber-700' : 'text-slate-500' ?>"><?= number_format((int) ($coverageRow['risky_uncovered'] ?? 0), 0, ',', ' ') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <div class="mt-8 grid gap-6 xl:grid-cols-2">
            <section class="rounded-[28px] border border-white/80 bg-white/90 p-6 shadow-[0_18px_60px_rgba(15,23,42,0.07)]">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-semibold text-slate-950">Pages sans convention détectée</h2>
                        <p class="mt-2 text-sm text-slate-500">Triées par date de modification décroissante pour remonter rapidement les ajouts récents.</p>
                    </div>
                    <div class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-2 text-sm font-semibold text-rose-700">
                        <?= number_format((int) ($coverageView['counts']['uncovered_pages'] ?? 0), 0, ',', ' ') ?> page(s)
                    </div>
                </div>

                <div class="mt-5 space-y-3">
                    <?php if (($coverageView['uncovered_pages'] ?? []) === []): ?>
                        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-4 text-sm text-emerald-800">Aucune page PHP applicative non couverte n'a été détectée dans le périmètre scanné.</div>
                    <?php else: ?>
                        <?php foreach (array_slice($coverageView['uncovered_pages'], 0, 18) as $uncoveredPage): ?>
                            <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4">
                                <div class="flex flex-col gap-2 md:flex-row md:items-start md:justify-between">
                                    <div>
                                        <p class="font-semibold text-slate-900"><?= htmlspecialchars((string) $uncoveredPage['path']) ?></p>
                                        <p class="mt-1 text-xs text-slate-500">Module <?= htmlspecialchars((string) $uncoveredPage['module']) ?> • Modifié le <?= htmlspecialchars((string) $uncoveredPage['modified_at']) ?></p>
                                    </div>
                                    <span class="inline-flex rounded-full border px-3 py-1 text-xs font-semibold <?= auditCoverageBadgeClass((string) $uncoveredPage['status']) ?>">
                                        <?= htmlspecialchars(auditCoverageBadgeLabel((string) $uncoveredPage['status'])) ?>
                                    </span>
                                </div>
                                <p class="mt-2 text-sm text-slate-600"><?= htmlspecialchars((string) $uncoveredPage['coverage_label']) ?></p>
                                <div class="mt-3 flex flex-wrap gap-2">
                                    <?php if (!empty($uncoveredPage['is_risky'])): ?>
                                        <?php $riskSeverity = auditRiskSeverity($uncoveredPage['risk_signals'] ?? []); ?>
                                        <span class="inline-flex rounded-full border px-3 py-1 text-xs font-semibold <?= auditRiskBadgeClass($riskSeverity) ?>">
                                            <?= htmlspecialchars(auditRiskBadgeLabel($riskSeverity)) ?>
                                        </span>
                                        <?php foreach (($uncoveredPage['risk_signals'] ?? []) as $signal): ?>
                                            <span class="inline-flex rounded-full border border-slate-300 bg-white px-3 py-1 text-xs font-semibold text-slate-700 print:border-black print:text-black"><?= htmlspecialchars((string) $signal) ?></span>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <span class="inline-flex rounded-full border border-slate-300 bg-white px-3 py-1 text-xs font-semibold text-slate-500 print:border-slate-400 print:text-slate-700">LECTURE</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>

            <section class="rounded-[28px] border border-white/80 bg-white/90 p-6 shadow-[0_18px_60px_rgba(15,23,42,0.07)]">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-semibold text-slate-950">Actions métier encore peu tracées</h2>
                        <p class="mt-2 text-sm text-slate-500">Heuristique sur les écritures, mutations SQL et handlers métier sans trace fine autre qu'une consultation.</p>
                    </div>
                    <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-2 text-sm font-semibold text-amber-700">
                        <?= number_format((int) ($coverageView['counts']['action_candidates'] ?? 0), 0, ',', ' ') ?> candidate(s)
                    </div>
                </div>

                <div class="mt-5 space-y-3">
                    <?php if (($coverageView['action_candidates'] ?? []) === []): ?>
                        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-4 text-sm text-emerald-800">Aucune action métier potentiellement non tracée finement n'a été détectée avec les heuristiques actuelles.</div>
                    <?php else: ?>
                        <?php foreach (array_slice($coverageView['action_candidates'], 0, 18) as $candidate): ?>
                            <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4">
                                <div class="flex flex-col gap-2 md:flex-row md:items-start md:justify-between">
                                    <div>
                                        <p class="font-semibold text-slate-900"><?= htmlspecialchars((string) $candidate['path']) ?></p>
                                        <p class="mt-1 text-xs text-slate-500">Module <?= htmlspecialchars((string) $candidate['module']) ?> • Couverture actuelle: <?= htmlspecialchars((string) $candidate['coverage_label']) ?></p>
                                    </div>
                                    <span class="inline-flex rounded-full border px-3 py-1 text-xs font-semibold <?= auditCoverageBadgeClass((string) $candidate['status']) ?>">
                                        <?= htmlspecialchars(auditCoverageBadgeLabel((string) $candidate['status'])) ?>
                                    </span>
                                </div>
                                <div class="mt-3 flex flex-wrap gap-2">
                                    <?php foreach ($candidate['signals'] as $signal): ?>
                                        <span class="inline-flex rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-700"><?= htmlspecialchars((string) $signal) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>
        </div>

        <div class="mt-8 grid gap-4 md:grid-cols-2 xl:grid-cols-5">
            <div class="rounded-[24px] border border-white/80 bg-white/90 p-5 shadow-[0_18px_50px_rgba(15,23,42,0.07)]">
                <p class="text-xs font-semibold uppercase tracking-[0.28em] text-slate-400">Évènements</p>
                <p class="mt-3 text-3xl font-semibold text-slate-950"><?= number_format((int) ($stats['total_events'] ?? 0), 0, ',', ' ') ?></p>
            </div>
            <div class="rounded-[24px] border border-rose-100 bg-rose-50/80 p-5 shadow-[0_18px_50px_rgba(15,23,42,0.05)]">
                <p class="text-xs font-semibold uppercase tracking-[0.28em] text-rose-500">Échecs</p>
                <p class="mt-3 text-3xl font-semibold text-rose-700"><?= number_format((int) ($stats['failed_events'] ?? 0), 0, ',', ' ') ?></p>
            </div>
            <div class="rounded-[24px] border border-amber-100 bg-amber-50/80 p-5 shadow-[0_18px_50px_rgba(15,23,42,0.05)]">
                <p class="text-xs font-semibold uppercase tracking-[0.28em] text-amber-600">Sensibles</p>
                <p class="mt-3 text-3xl font-semibold text-amber-700"><?= number_format((int) ($stats['sensitive_events'] ?? 0), 0, ',', ' ') ?></p>
            </div>
            <div class="rounded-[24px] border border-sky-100 bg-sky-50/80 p-5 shadow-[0_18px_50px_rgba(15,23,42,0.05)]">
                <p class="text-xs font-semibold uppercase tracking-[0.28em] text-sky-600">Utilisateurs</p>
                <p class="mt-3 text-3xl font-semibold text-sky-700"><?= number_format((int) ($stats['distinct_users'] ?? 0), 0, ',', ' ') ?></p>
            </div>
            <div class="rounded-[24px] border border-indigo-100 bg-indigo-50/80 p-5 shadow-[0_18px_50px_rgba(15,23,42,0.05)]">
                <p class="text-xs font-semibold uppercase tracking-[0.28em] text-indigo-600">Modules</p>
                <p class="mt-3 text-3xl font-semibold text-indigo-700"><?= number_format((int) ($stats['distinct_modules'] ?? 0), 0, ',', ' ') ?></p>
            </div>
        </div>

        <div class="mt-8 grid gap-6 xl:grid-cols-3">
            <section class="rounded-[28px] border border-white/80 bg-white/90 p-6 shadow-[0_18px_60px_rgba(15,23,42,0.07)] xl:col-span-1">
                <h2 class="text-lg font-semibold text-slate-950">Modules les plus actifs</h2>
                <div class="mt-4 space-y-3">
                    <?php if ($topModules === []): ?>
                        <p class="text-sm text-slate-500">Aucune donnée sur la période.</p>
                    <?php else: ?>
                        <?php foreach ($topModules as $moduleRow): ?>
                            <div class="flex items-center justify-between rounded-2xl bg-slate-50 px-4 py-3">
                                <span class="text-sm font-medium text-slate-700"><?= htmlspecialchars((string) ($moduleRow['label'] ?? 'non-classe')) ?></span>
                                <span class="text-sm font-semibold text-slate-950"><?= number_format((int) ($moduleRow['total'] ?? 0), 0, ',', ' ') ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>

            <section class="rounded-[28px] border border-white/80 bg-white/90 p-6 shadow-[0_18px_60px_rgba(15,23,42,0.07)] xl:col-span-2">
                <h2 class="text-lg font-semibold text-slate-950">Évènements dominants</h2>
                <div class="mt-4 grid gap-3 md:grid-cols-2">
                    <?php if ($topEvents === []): ?>
                        <p class="text-sm text-slate-500">Aucune donnée sur la période.</p>
                    <?php else: ?>
                        <?php foreach ($topEvents as $eventRow): ?>
                            <div class="rounded-2xl border border-slate-200 px-4 py-3">
                                <p class="text-sm font-semibold text-slate-900"><?= htmlspecialchars((string) ($eventRow['label'] ?? '')) ?></p>
                                <p class="mt-1 text-sm text-slate-500"><?= number_format((int) ($eventRow['total'] ?? 0), 0, ',', ' ') ?> occurrence(s)</p>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>
        </div>

        <section class="mt-8 rounded-[30px] border border-white/80 bg-white/90 p-6 shadow-[0_18px_60px_rgba(15,23,42,0.07)] backdrop-blur">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h2 class="text-xl font-semibold text-slate-950">Journal détaillé</h2>
                    <p class="text-sm text-slate-500">250 entrées maximum, triées de la plus récente à la plus ancienne.</p>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <p class="text-sm text-slate-500"><?= number_format(count($rows), 0, ',', ' ') ?> ligne(s) chargée(s)</p>
                    <a href="<?= htmlspecialchars($csvExportUrl) ?>" class="inline-flex items-center rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-2 text-xs font-semibold text-emerald-700 hover:bg-emerald-100">
                        <i class="fas fa-file-csv mr-2"></i>
                        CSV filtré
                    </a>
                    <a href="<?= htmlspecialchars($pdfExportUrl) ?>" class="inline-flex items-center rounded-xl border border-rose-200 bg-rose-50 px-4 py-2 text-xs font-semibold text-rose-700 hover:bg-rose-100">
                        <i class="fas fa-file-pdf mr-2"></i>
                        PDF filtré
                    </a>
                </div>
            </div>

            <div class="mt-6 overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-semibold text-slate-600">Date</th>
                            <th class="px-4 py-3 text-left font-semibold text-slate-600">Évènement</th>
                            <th class="px-4 py-3 text-left font-semibold text-slate-600">Module</th>
                            <th class="px-4 py-3 text-left font-semibold text-slate-600">Utilisateur</th>
                            <th class="px-4 py-3 text-left font-semibold text-slate-600">Entité</th>
                            <th class="px-4 py-3 text-left font-semibold text-slate-600">Statut</th>
                            <th class="px-4 py-3 text-left font-semibold text-slate-600">Contexte</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 bg-white">
                        <?php if ($rows === []): ?>
                            <tr>
                                <td colspan="7" class="px-4 py-10 text-center text-slate-500">Aucune entrée ne correspond aux filtres.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($rows as $row): ?>
                                <?php
                                $result = (string) ($row['result'] ?? 'success');
                                $severity = (string) ($row['severity'] ?? 'info');
                                $statusClass = 'bg-emerald-50 text-emerald-700 border-emerald-200';
                                if ($result === 'failed' || $severity === 'error') {
                                    $statusClass = 'bg-rose-50 text-rose-700 border-rose-200';
                                } elseif ($severity === 'warning') {
                                    $statusClass = 'bg-amber-50 text-amber-700 border-amber-200';
                                }
                                $userLabel = trim((string) ($row['user_name'] ?? ''));
                                if ($userLabel === '') {
                                    $userLabel = trim((string) ($row['user_email'] ?? ''));
                                }
                                if ($userLabel === '') {
                                    $userLabel = 'Anonyme';
                                }
                                $entityLabel = trim((string) ($row['entity_label'] ?? ''));
                                if ($entityLabel === '') {
                                    $entityLabel = trim((string) ($row['entity_type'] ?? ''));
                                }
                                if ($entityLabel === '') {
                                    $entityLabel = 'Non renseignée';
                                }
                                $scopeLabel = !empty($row['is_archived']) ? 'Archive' : 'Actif';
                                ?>
                                <tr class="align-top hover:bg-slate-50/70">
                                    <td class="px-4 py-4 text-slate-600 whitespace-nowrap"><?= htmlspecialchars((string) ($row['created_at'] ?? '')) ?></td>
                                    <td class="px-4 py-4">
                                        <div class="font-semibold text-slate-900"><?= htmlspecialchars((string) ($row['event_type'] ?? '')) ?></div>
                                        <div class="mt-1 text-xs text-slate-500"><?= htmlspecialchars((string) ($row['action'] ?? '')) ?></div>
                                    </td>
                                    <td class="px-4 py-4 text-slate-700"><?= htmlspecialchars((string) ($row['module'] ?? 'non-classe')) ?></td>
                                    <td class="px-4 py-4">
                                        <div class="font-medium text-slate-900"><?= htmlspecialchars($userLabel) ?></div>
                                        <?php if (!empty($row['user_id'])): ?>
                                            <div class="mt-1 text-xs text-slate-500">#<?= (int) $row['user_id'] ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-4">
                                        <div class="font-medium text-slate-900"><?= htmlspecialchars($entityLabel) ?></div>
                                        <?php if (!empty($row['entity_id'])): ?>
                                            <div class="mt-1 text-xs text-slate-500">ID <?= htmlspecialchars((string) $row['entity_id']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-4">
                                        <span class="inline-flex rounded-full border px-3 py-1 text-xs font-semibold <?= $statusClass ?>">
                                            <?= htmlspecialchars($result) ?> / <?= htmlspecialchars($severity) ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-4 text-slate-600">
                                        <div class="text-xs leading-5"><?= htmlspecialchars(auditShortJson($row['details'])) ?></div>
                                        <div class="mt-2 text-xs text-slate-400">
                                            <?= htmlspecialchars((string) ($row['request_method'] ?? '')) ?>
                                            <?= htmlspecialchars((string) ($row['page_name'] ?? '')) ?>
                                        </div>
                                        <div class="mt-1 text-xs text-slate-400">
                                            <?= htmlspecialchars((string) ($row['entity_type'] ?? '')) ?>
                                            •
                                            <?= htmlspecialchars($scopeLabel) ?>
                                            <?php if (!empty($row['archived_at'])): ?>
                                                • Archivé le <?= htmlspecialchars((string) $row['archived_at']) ?>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($row['details'] !== []): ?>
                                            <details class="mt-2">
                                                <summary class="cursor-pointer text-xs font-semibold text-rose-700">Voir les détails JSON</summary>
                                                <pre class="mt-2 overflow-x-auto rounded-2xl bg-slate-950/95 p-4 text-xs text-slate-100"><?= htmlspecialchars(json_encode($row['details'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
                                            </details>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>
