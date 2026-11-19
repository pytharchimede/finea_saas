<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

// Un avertissement affiché avant les octets PDF suffit à rendre le document
// illisible dans Chrome. On journalise les erreurs mais on protège le flux.
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
@ini_set('memory_limit', '512M');
@set_time_limit(120);
if (ob_get_level() === 0) ob_start();
$rhExportTraceId = 'RH-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3));
register_shutdown_function(static function () use ($rhExportTraceId): void {
    $error = error_get_last();
    if (!$error || !in_array((int) ($error['type'] ?? 0), [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) return;
    $message = sprintf('[rh_pdf_export:%s] Fatal %s in %s:%d', $rhExportTraceId, (string) ($error['message'] ?? 'Erreur inconnue'), (string) ($error['file'] ?? ''), (int) ($error['line'] ?? 0));
    error_log($message);
    while (ob_get_level() > 0) @ob_end_clean();
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        header('Cache-Control: no-store');
    }
    echo "L’export PDF RH n’a pas pu être généré.\n";
    echo 'Référence : ' . $rhExportTraceId . "\n";
    echo 'Emplacement : ' . basename((string) ($error['file'] ?? 'inconnu')) . ':' . (int) ($error['line'] ?? 0) . "\n";
    echo 'Cause : ' . (string) ($error['message'] ?? 'Erreur inconnue') . "\n";
});

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/CompanyPdfTemplate.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../model/DisbursementRegularizationManager.php';
require_once __DIR__ . '/../repositories/RhDashboardRepository.php';

final class RhAnalyticsReportPdf extends TCPDF
{
    use CompanyPdfTemplate;
    public function Header(): void { $this->drawCompanyPdfHeader(); }
    public function Footer(): void { $this->drawCompanyPdfFooter(); }
}

$pdo = \Database::getConnection();
// Migration idempotente en ligne : la table et ses champs sont créés ou
// complétés avant toute lecture analytique, sans script SQL manuel. Un droit
// SQL insuffisant ne bloque pas l'export grâce aux requêtes rétrocompatibles.
try {
    new \DisbursementRegularizationManager($pdo);
} catch (Throwable $migrationError) {
    error_log('[rh_pdf_schema_migration] ' . $migrationError->getMessage());
}
$repository = new RhDashboardRepository($pdo);

function rh_pdf_param(string $key, string $default = ''): string
{
    return trim((string) ($_POST[$key] ?? $_GET[$key] ?? $default));
}
function rh_pdf_param_ids(string $key): array
{
    $raw = $_POST[$key] ?? $_GET[$key] ?? [];
    if (!is_array($raw)) $raw = preg_split('/[^0-9]+/', (string) $raw) ?: [];
    return array_values(array_unique(array_filter(array_map('intval', $raw), static fn(int $id): bool => $id > 0)));
}
function rh_pdf_h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
function rh_pdf_number($value, int $decimals = 0): string
{
    return number_format((float) $value, $decimals, ',', ' ');
}
function rh_pdf_date(string $date): string
{
    $t = strtotime($date);
    return $t ? date('d/m/Y', $t) : $date;
}
function rh_pdf_percent($value): string
{
    return rh_pdf_number($value, 1) . ' %';
}

function rh_pdf_period(): array
{
    $mode = strtolower(rh_pdf_param('report_mode', 'monthly'));
    $today = date('Y-m-d');
    if ($mode === 'daily') {
        $date = rh_pdf_param('report_date', $today);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = $today;
        }
        return [$mode, $date, $date, 'Journalier ' . rh_pdf_date($date)];
    }
    if ($mode === 'annual') {
        $year = max(2000, min(2100, (int) rh_pdf_param('report_year', date('Y'))));
        return [$mode, sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year), 'Annuel ' . $year];
    }
    if ($mode === 'custom') {
        $start = rh_pdf_param('start_date', $today);
        $end = rh_pdf_param('end_date', $today);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
            $start = $today;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
            $end = $start;
        }
        if ($start > $end) {
            [$start, $end] = [$end, $start];
        }
        return [$mode, $start, $end, 'Période libre'];
    }
    $month = rh_pdf_param('report_month', date('Y-m'));
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        $month = date('Y-m');
    }
    $startObj = new DateTimeImmutable($month . '-01');
    return ['monthly', $startObj->format('Y-m-d'), $startObj->modify('last day of this month')->format('Y-m-d'), 'Mensuel ' . $startObj->format('m/Y')];
}

function rh_pdf_scope(PDO $pdo): array
{
    $requestedIds = rh_pdf_param_ids('personnel_ids');
    $legacyRequestedId = max(0, (int) rh_pdf_param('personnel_id', '0'));
    if (!$requestedIds && $legacyRequestedId > 0) $requestedIds = [$legacyRequestedId];
    $sessionPersonnelId = max(0, (int) ($_SESSION['rh_report_personnel_id'] ?? 0));
    $sessionSelectionAge = time() - (int) ($_SESSION['rh_report_personnel_selected_at'] ?? 0);
    if (!$requestedIds && $sessionPersonnelId > 0 && $sessionSelectionAge >= 0 && $sessionSelectionAge <= 7200) $requestedIds = [$sessionPersonnelId];
    $requestedIds = array_slice($requestedIds, 0, 1);
    if (!$requestedIds) {
        header('Location: dashboard_rh.php?view=analytique&report_error=personnel_required');
        exit;
    }
    $scope = $requestedIds ? 'personnel' : strtolower(rh_pdf_param('report_scope', 'all'));
    if (!in_array($scope, ['all', 'personnel', 'service', 'fonction', 'statut'], true)) {
        $scope = 'all';
    }
    $filters = ['report_scope' => $scope];
    $label = 'Général';
    if ($scope === 'personnel') {
        $ids = $requestedIds;
        $filters['personnel_ids'] = $ids;
        if ($ids) {
            $stmt = $pdo->prepare('SELECT nom_personnel_soignant FROM personnel WHERE id_personnel_soignant IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY nom_personnel_soignant');
            $stmt->execute($ids);
            $names = array_values(array_filter(array_map('trim', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [])));
            $label = count($names) === 1 ? ('Personne : ' . $names[0]) : ('Sélection : ' . implode(', ', array_slice($names, 0, 6)) . (count($names) > 6 ? (' +' . (count($names) - 6)) : ''));
        } else {
            $label = 'Aucune personne sélectionnée';
        }
    } elseif ($scope === 'service') {
        $id = max(0, (int) rh_pdf_param('service_id', '0'));
        $filters['service_id'] = $id;
        $stmt = $pdo->prepare('SELECT lib_service FROM service WHERE id_service = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $label = 'Service : ' . (string) ($stmt->fetchColumn() ?: ('#' . $id));
    } elseif ($scope === 'fonction') {
        $id = max(0, (int) rh_pdf_param('fonction_id', '0'));
        $filters['fonction_id'] = $id;
        $stmt = $pdo->prepare('SELECT lib_fonction FROM fonction WHERE id_fonction = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $label = 'Fonction : ' . (string) ($stmt->fetchColumn() ?: ('#' . $id));
    } elseif ($scope === 'statut') {
        $code = rh_pdf_param('status_code', '');
        $filters['status_code'] = $code;
        $stmt = $pdo->prepare('SELECT label FROM rh_statuts WHERE code = :code LIMIT 1');
        $stmt->execute(['code' => $code]);
        $label = 'Statut : ' . (string) ($stmt->fetchColumn() ?: ($code !== '' ? $code : 'Non sélectionné'));
    }
    return [$scope, $filters, $label];
}

function rh_pdf_rows(array $rows, array $columns, string $empty): string
{
    $html = '';
    foreach ($rows as $row) {
        $html .= '<tr>';
        foreach ($columns as $col) {
            [$key, $decimals, $suffix, $class] = $col + [null, 0, '', ''];
            $value = $row[$key] ?? '';
            $html .= '<td class="' . rh_pdf_h($class) . '">' . rh_pdf_h(is_numeric($value) ? rh_pdf_number($value, (int) $decimals) . $suffix : (string) $value . $suffix) . '</td>';
        }
        $html .= '</tr>';
    }
    return $html ?: '<tr><td colspan="' . count($columns) . '" class="center muted">' . rh_pdf_h($empty) . '</td></tr>';
}

[$mode, $startDate, $endDate, $periodLabel] = rh_pdf_period();
[$scope, $scopeFilters, $scopeLabel] = rh_pdf_scope($pdo);
$exporterId = (int) ($_SESSION['user_id'] ?? 0);
$exporterName = trim((string) (($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? '')));
if ($exporterName === '') $exporterName = trim((string) ($_SESSION['user_name'] ?? 'Utilisateur FINEA'));
$documentId = 'RH-RAP-' . date('Ymd-His') . '-U' . $exporterId;
$certificateFingerprint = strtoupper(substr(hash('sha256', implode('|', [$documentId, $exporterId, $startDate, $endDate, (string) (($scopeFilters['personnel_ids'] ?? [0])[0] ?? 0)])), 0, 20));
$exporterSignaturePath = '';
$exporterSignatureData = '';
try {
    $signatureStmt = $pdo->prepare('SELECT signature, signature_data FROM user_devis_banamur WHERE id = :id LIMIT 1');
    $signatureStmt->execute(['id' => $exporterId]);
    $signatureRow = $signatureStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $exporterSignaturePath = trim((string) ($signatureRow['signature'] ?? ''));
    $exporterSignatureData = trim((string) ($signatureRow['signature_data'] ?? ''));
} catch (Throwable $ignored) {
}
$comments = [
    'Synthèse RH' => rh_pdf_param('comment_summary'),
    'Points d attention' => rh_pdf_param('comment_risks'),
    'Actions prévues' => rh_pdf_param('comment_actions'),
];

$analytics = $repository->getOperationalAnalyticsForRange($startDate, $endDate, $periodLabel, $scopeFilters);
$attendance = (array) ($analytics['attendance'] ?? []);
$requests = (array) ($analytics['requests'] ?? []);
$schedule = (array) ($analytics['schedule'] ?? []);
$overtime = (array) ($analytics['overtime'] ?? []);
$personnelBreakdown = $repository->getPersonnelAnalyticsBreakdown($startDate, $endDate, $scopeFilters);
$resolvedPersonnelScope = $analytics['personnel_scope'] ?? null;
$personnelCount = count($personnelBreakdown);
$requestTotal = (int) ($requests['submitted'] ?? 0) + (int) ($requests['processed'] ?? 0);
$portfolioIds = $scope === 'personnel' ? array_values(array_map('intval', (array) ($scopeFilters['personnel_ids'] ?? []))) : [];
$isIndividual = count($portfolioIds) === 1;
$portfolio = $portfolioIds ? $repository->getIndividualOperationalPortfolio($startDate, $endDate, $portfolioIds) : [];
$profile = $isIndividual ? (array) (($portfolio['profiles'] ?? [])[0] ?? []) : [];

try {
    \AuditTrail::log('rh_report_exported', [
        'module' => 'rh',
        'action' => 'export_rapport_rh_pdf',
        'entity_type' => 'rh_report',
        'entity_label' => $periodLabel,
        'result' => 'success',
        'details' => ['mode' => $mode, 'start_date' => $startDate, 'end_date' => $endDate, 'scope' => $scopeFilters],
    ], $pdo);
} catch (Throwable $ignored) {
}

$statusRows = rh_pdf_rows((array) ($attendance['by_status'] ?? []), [
    ['label', 0, '', ''],
    ['total', 0, '', 'right'],
    ['worked_days', 2, '', 'right'],
    ['overtime_hours', 2, ' h', 'right']
], 'Aucune donnée de pointage.');
$requestRows = rh_pdf_rows((array) ($requests['by_type'] ?? []), [['label', 0, '', ''], ['total', 0, '', 'right']], 'Aucune demande traitée.');
$overtimeRows = rh_pdf_rows((array) ($overtime['top_employees'] ?? []), [['employee_name', 0, '', ''], ['hours_total', 2, ' h', 'right'], ['days_count', 0, '', 'right']], 'Aucune heure supplémentaire.');
$busyRows = rh_pdf_rows((array) ($schedule['top_busy'] ?? []), [['employee_name', 0, '', ''], ['hours_count', 1, ' h', 'right'], ['events_count', 0, '', 'right']], 'Aucune charge planifiée.');
$personnelBreakdownRows = [];
foreach ($personnelBreakdown as $personRow) {
    $personRow['scope_label'] = trim((string) ($personRow['service_label'] ?? '') . ' / ' . (string) ($personRow['fonction_label'] ?? ''), ' /');
    $personnelBreakdownRows[] = $personRow;
}
$personnelRows = rh_pdf_rows($personnelBreakdownRows, [
    ['employee_name', 0, '', ''], ['scope_label', 0, '', ''], ['presence_rate', 1, ' %', 'right'],
    ['late_rate', 1, ' %', 'right'], ['absence_rate', 1, ' %', 'right'], ['overtime_hours', 1, ' h', 'right']
], 'Aucun collaborateur dans ce périmètre.');

$signalParts = [];
if ((int) ($attendance['late_rows'] ?? 0) > 0) {
    $signalParts[] = rh_pdf_number($attendance['late_rows'] ?? 0) . ' retard(s)';
}
if ((int) ($attendance['absence_rows'] ?? 0) > 0) {
    $signalParts[] = rh_pdf_number($attendance['absence_rows'] ?? 0) . ' absence(s) / maladie(s)';
}
if ((float) ($overtime['hours_total'] ?? 0) > 0) {
    $signalParts[] = rh_pdf_number($overtime['hours_total'] ?? 0, 1) . ' h sup.';
}
$signals = $signalParts ? implode(' | ', $signalParts) : 'Aucun signal critique détecté sur la période.';

$attendanceTotal = (int) ($attendance['total_rows'] ?? 0);
$lateRate = (float) ($attendance['late_rate'] ?? 0);
$absenceRate = $attendanceTotal > 0 ? (((int) ($attendance['absence_rows'] ?? 0) / $attendanceTotal) * 100) : 0.0;
$computedLateRate = rh_pdf_param('computed_late_rate');
$computedAbsenceRate = rh_pdf_param('computed_absence_rate');
if ($computedLateRate !== '' && is_numeric($computedLateRate)) $lateRate = max(0.0, min(100.0, (float) $computedLateRate));
if ($computedAbsenceRate !== '' && is_numeric($computedAbsenceRate)) $absenceRate = max(0.0, min(100.0, (float) $computedAbsenceRate));
$presenceRate = (float) ($attendance['presence_rate'] ?? 0);
$overtimeHours = (float) ($overtime['hours_total'] ?? ($attendance['overtime_hours'] ?? 0));
$attentionLevel = 'Suivi normal';
$decisionSuggestions = [];
if ($attendanceTotal === 0) {
    $attentionLevel = 'Données insuffisantes';
    $decisionSuggestions[] = 'Vérifier la complétude du pointage avant toute appréciation individuelle.';
} else {
    if ($lateRate >= 15) {
        $attentionLevel = 'Attention renforcée';
        $decisionSuggestions[] = 'Analyser les retards avec le collaborateur lors d’un entretien factuel et vérifier les contraintes opérationnelles.';
    } elseif ($lateRate >= 5) {
        $attentionLevel = 'À surveiller';
        $decisionSuggestions[] = 'Suivre les retards sur la période suivante et rappeler les horaires applicables.';
    }
    if ($absenceRate >= 10) {
        $attentionLevel = 'Attention renforcée';
        $decisionSuggestions[] = 'Distinguer les absences justifiées et non justifiées, puis échanger avec la personne avant toute décision.';
    } elseif ($absenceRate >= 5) {
        if ($attentionLevel === 'Suivi normal') $attentionLevel = 'À surveiller';
        $decisionSuggestions[] = 'Contrôler les justificatifs et la récurrence des absences sans conclure à partir du seul taux.';
    }
    if ($overtimeHours >= 20) {
        $decisionSuggestions[] = 'Évaluer la charge et envisager redistribution, récupération ou ajustement de planification.';
    }
    if ($presenceRate >= 95 && $lateRate < 5 && $absenceRate < 5) {
        $decisionSuggestions[] = 'Les indicateurs d’assiduité sont favorables ; maintenir le suivi périodique.';
    }
}
if ($isIndividual) {
    $portfolioDisbursements = (array) ($portfolio['disbursements'] ?? []);
    $emittedCount = (int) ($portfolioDisbursements['emitted_count'] ?? 0);
    $pendingFiches = (int) ($portfolioDisbursements['pending_count'] ?? 0);
    $rejectedFiches = (int) ($portfolioDisbursements['rejected_count'] ?? 0);
    if ($pendingFiches > 0) $decisionSuggestions[] = 'Examiner les ' . $pendingFiches . ' fiche(s) de décaissement encore en attente et identifier les validations ou pièces manquantes.';
    if ($emittedCount > 0 && ($rejectedFiches / $emittedCount) >= 0.15) $decisionSuggestions[] = 'Le taux de refus des fiches émises mérite une revue des motifs afin d’améliorer la qualité des prochaines demandes.';
    $openTasks = 0; $doneTasks = 0;
    foreach ((array) ($portfolio['tasks'] ?? []) as $taskStatus) {
        $taskState = strtolower((string) ($taskStatus['status'] ?? ''));
        if (in_array($taskState, ['done', 'completed', 'termine'], true)) $doneTasks += (int) ($taskStatus['total'] ?? 0); else $openTasks += (int) ($taskStatus['total'] ?? 0);
    }
    if ($openTasks > 0) $decisionSuggestions[] = 'Prioriser les ' . $openTasks . ' tâche(s) collaborative(s) non clôturée(s) et confirmer leurs échéances.';
    foreach ((array) ($portfolio['meeting_tasks'] ?? []) as $meetingStatus) {
        if (!in_array(strtolower((string) ($meetingStatus['status'] ?? '')), ['done', 'cancelled'], true) && (int) ($meetingStatus['total'] ?? 0) > 0) {
            $decisionSuggestions[] = 'Suivre les points d’action de réunion encore ouverts et documenter leur avancement avant la prochaine revue.';
            break;
        }
    }
}
if (!$decisionSuggestions) {
    $decisionSuggestions[] = 'Aucun signal quantitatif majeur ; compléter avec les objectifs, le contexte du poste et l’échange avec le collaborateur.';
}
$suggestionHtml = '<div class="decision"><strong>Niveau d’attention : ' . rh_pdf_h($attentionLevel) . '</strong><ul>';
foreach ($decisionSuggestions as $suggestion) {
    $suggestionHtml .= '<li>' . rh_pdf_h($suggestion) . '</li>';
}
$suggestionHtml .= '</ul><span class="small">Aide à l’analyse uniquement : toute décision reste humaine et tient compte des justificatifs, du contexte professionnel et du droit applicable.</span></div>';

$commentHtml = '';
foreach ($comments as $label => $comment) {
    $commentHtml .= '<div class="comment"><strong>' . rh_pdf_h($label) . '</strong><br>' . nl2br(rh_pdf_h($comment !== '' ? $comment : 'Non renseigné.')) . '</div>';
}

$faFont = TCPDF_FONTS::addTTFfont(__DIR__ . '/../decaissement/plugins/css/webfonts/fa-solid-900.ttf', 'TrueTypeUnicode', '', 32) ?: 'dejavusans';
$brand = CompanyBranding::get($pdo);
$brandPrimary = preg_match('/^#[0-9a-f]{6}$/i', (string) ($brand['primary_color'] ?? '')) ? (string) $brand['primary_color'] : '#050505';
$brandAccent = preg_match('/^#[0-9a-f]{6}$/i', (string) ($brand['accent_color'] ?? '')) ? (string) $brand['accent_color'] : '#ffcc20';
$brandText = preg_match('/^#[0-9a-f]{6}$/i', (string) ($brand['text_color'] ?? '')) ? (string) $brand['text_color'] : '#0f172a';
$brandMuted = preg_match('/^#[0-9a-f]{6}$/i', (string) ($brand['muted_color'] ?? '')) ? (string) $brand['muted_color'] : '#64748b';
$money = static fn($value): string => number_format((float) $value, 0, ',', ' ') . ' FCFA';
$disbursements = (array) ($portfolio['disbursements'] ?? []);
$financialCards = [
    ['&#xf56f;', 'Fiches émises', 'emitted_count', 'emitted_amount', '#eef2ff', 'Demandes financières créées sur la période.'],
    ['&#xf017;', 'En attente', 'pending_count', 'pending_amount', '#fff7ed', 'Fiches encore présentes dans le circuit.'],
    ['&#xf058;', 'Validées', 'approved_count', 'approved_amount', '#ecfdf5', 'Fiches ayant obtenu une validation.'],
    ['&#xf53d;', 'Décaissées', 'disbursed_count', 'disbursed_amount', '#eff6ff', 'Paiements effectivement exécutés.'],
    ['&#xf057;', 'Refusées', 'rejected_count', 'rejected_amount', '#fff1f2', 'Fiches rejetées pendant le traitement.'],
    ['&#xf2f1;', 'Régularisées', 'regularized_count', 'regularized_amount', '#f5f3ff', 'Dossiers ayant fait l’objet d’une régularisation.'],
];
$financialHtml = '';
if ($isIndividual) {
    foreach (array_chunk($financialCards, 3) as $financialRow) {
        $financialHtml .= '<table class="cards" cellpadding="9" cellspacing="5" nobr="true"><tr>';
        foreach ($financialRow as [$icon, $label, $countKey, $amountKey, $fill, $help]) {
            $financialHtml .= '<td class="metric" bgcolor="' . $fill . '"><div class="metric-title"><span class="metric-icon fa">' . $icon . '</span> <span class="metric-label">' . rh_pdf_h($label) . '</span></div><div class="metric-count">' . rh_pdf_number($disbursements[$countKey] ?? 0) . '</div><div class="metric-amount">' . rh_pdf_h($money($disbursements[$amountKey] ?? 0)) . '</div><div class="metric-help">' . rh_pdf_h($help) . '</div></td>';
        }
        $financialHtml .= '</tr></table>';
    }
}

$ficheRows = '';
foreach ((array) ($portfolio['disbursement_rows'] ?? []) as $fiche) {
    $status = !empty($fiche['date_refuse']) ? 'Refusée' : (!empty($fiche['regularized']) ? 'Régularisée' : (!empty($fiche['decaisse']) ? 'Décaissée' : (!empty($fiche['approuve']) ? 'Validée' : 'En attente')));
    $ficheRows .= '<tr><td><strong>' . rh_pdf_h($fiche['num_fiche'] ?? '') . '</strong><br><span class="small">' . rh_pdf_h(mb_strimwidth((string) ($fiche['designation_fiche'] ?? ''), 0, 80, '…', 'UTF-8')) . '</span></td><td>' . rh_pdf_h(rh_pdf_date(substr((string) ($fiche['date_creat_fiche'] ?? ''), 0, 10))) . '</td><td>' . rh_pdf_h($status) . '</td><td class="right"><strong>' . rh_pdf_h($money($fiche['montant_fiche'] ?? 0)) . '</strong></td></tr>';
}
$ficheRows = $ficheRows ?: '<tr><td colspan="4" class="center muted">Aucune fiche émise sur la période.</td></tr>';

$taskSummaryRows = rh_pdf_rows((array) ($portfolio['tasks'] ?? []), [['status', 0, '', ''], ['total', 0, '', 'right']], 'Aucune tâche collaborative assignée.');
$meetingSummaryRows = rh_pdf_rows((array) ($portfolio['meeting_tasks'] ?? []), [['status', 0, '', ''], ['total', 0, '', 'right'], ['average_progress', 1, ' %', 'right']], 'Aucun point d’action de réunion assigné.');
$taskDetailRows = rh_pdf_rows((array) ($portfolio['task_rows'] ?? []), [['title', 0, '', ''], ['priority', 0, '', ''], ['status', 0, '', ''], ['deadline', 0, '', '']], 'Aucune tâche collaborative détaillée.');
$meetingDetailRows = rh_pdf_rows((array) ($portfolio['meeting_task_rows'] ?? []), [['task_title', 0, '', ''], ['status', 0, '', ''], ['progress', 1, ' %', 'right'], ['deadline', 0, '', '']], 'Aucun point d’action de réunion détaillé.');
$scheduleDetailRows = rh_pdf_rows((array) ($portfolio['schedule_rows'] ?? []), [['title', 0, '', ''], ['start_at', 0, '', ''], ['status_code', 0, '', ''], ['duration_hours', 1, ' h', 'right']], 'Aucun évènement planifié.');
$requestDetailRows = rh_pdf_rows((array) ($portfolio['request_rows'] ?? []), [['title', 0, '', ''], ['request_type', 0, '', ''], ['status', 0, '', ''], ['submitted_at', 0, '', '']], 'Aucune demande RH sur la période.');

$profileHtml = '';
if ($isIndividual) {
    $profileStartDate = (string) ($profile['start_date'] ?? '');
    $profileStartLabel = preg_match('/^[1-9]\d{3}-\d{2}-\d{2}$/', $profileStartDate) ? rh_pdf_date($profileStartDate) : 'Non renseignée';
    $photoPath = '';
    $applicationRoot = realpath(dirname(__DIR__));
    if ($applicationRoot) {
        $photoCandidates = [];
        foreach ([(string) ($profile['photo'] ?? ''), (string) ($profile['user_photo'] ?? '')] as $rawPhoto) {
            $rawPhoto = trim($rawPhoto);
            if ($rawPhoto === '') continue;
            array_push($photoCandidates, $rawPhoto, 'uploads/personnel/' . basename($rawPhoto), 'uploads/photos/' . basename($rawPhoto), 'photo/' . basename($rawPhoto));
        }
        foreach (array_unique($photoCandidates) as $candidate) {
            $resolvedPhoto = realpath($applicationRoot . '/' . ltrim($candidate, '/'));
            if (!$resolvedPhoto || !str_starts_with($resolvedPhoto, $applicationRoot . '/') || !is_file($resolvedPhoto) || !is_readable($resolvedPhoto)) continue;
            $photoInfo = @getimagesize($resolvedPhoto);
            $photoMime = is_array($photoInfo) ? (string) ($photoInfo['mime'] ?? '') : '';
            if (!in_array($photoMime, ['image/jpeg', 'image/png'], true) || filesize($resolvedPhoto) > 8 * 1024 * 1024) continue;
            $photoPath = $resolvedPhoto;
            break;
        }
    }
    $profileVisual = $photoPath !== '' ? '<div class="photo-frame"><img src="' . rh_pdf_h($photoPath) . '" width="42" height="42"></div>' : '<div class="avatar"><span class="fa">&#xf007;</span></div>';
    $profileHtml = '<div class="profile" nobr="true"><table cellpadding="8" nobr="true"><tr><td width="14%" class="profile-photo-cell">' . $profileVisual . '</td><td width="56%"><div class="profile-kicker"><span class="fa">&#xf007;</span> PROFIL DU COLLABORATEUR</div><div class="profile-name">' . rh_pdf_h($profile['employee_name'] ?? 'Collaborateur') . '</div><div class="profile-meta"><span class="fa">&#xf2c2;</span> ' . rh_pdf_h(($profile['matricule'] ?? 'Sans matricule') . ' · ' . ($profile['fonction_label'] ?? 'Fonction non renseignée')) . '</div><div class="profile-meta"><span class="fa">&#xf1ad;</span> ' . rh_pdf_h(($profile['service_label'] ?? 'Service non renseigné') . ' · ' . ($profile['status_label'] ?? 'Statut non renseigné')) . '</div></td><td width="30%" class="right"><span class="profile-period"><span class="fa">&#xf058;</span> DOSSIER INDIVIDUEL</span><br><br><span class="small"><span class="fa">&#xf274;</span> Prise de service<br><strong>' . rh_pdf_h($profileStartLabel) . '</strong></span></td></tr></table></div>';
}
$coverEyebrow = $isIndividual ? 'DOSSIER ANALYTIQUE INDIVIDUEL · FINEA' : 'RAPPORT ANALYTIQUE RH · FINEA';
$scopeAnalysisHtml = $isIndividual ? '' : '<div class="section">4. Analyse par collaborateur</div><div class="small">Cette table contient exclusivement les personnes du périmètre sélectionné.</div><table cellpadding="5"><thead><tr><th>Collaborateur</th><th>Service / fonction</th><th class="right">Présence</th><th class="right">Retards</th><th class="right">Absent.</th><th class="right">HS</th></tr></thead><tbody>' . $personnelRows . '</tbody></table>';
$requestSectionNumber = $isIndividual ? 4 : 5;
$planningSectionNumber = $isIndividual ? 5 : 6;
$individualOperationsHtml = '';
if ($isIndividual) {
    $individualOperationsHtml = '
<br pagebreak="true"/><div class="section">6.1 <span class="fa">&#xf53d;</span> Activité financière et décaissements</div>
<div class="section-note">Fiches créées par le collaborateur pendant la période. Quantités et montants sont ventilés selon leur état réel dans FINEA.</div>
' . $financialHtml . '
<div class="beneficiary-note"><strong>Comme bénéficiaire interne :</strong> ' . rh_pdf_number($disbursements['beneficiary_count'] ?? 0) . ' fiche(s), soit ' . rh_pdf_h($money($disbursements['beneficiary_amount'] ?? 0)) . '.</div>
<table cellpadding="5"><thead><tr><th>Fiche / objet</th><th>Date</th><th>État</th><th class="right">Montant</th></tr></thead><tbody>' . $ficheRows . '</tbody></table>

<div class="section">6.2 <span class="fa">&#xf0ae;</span> Tâches et décisions de réunion</div>
<table cellpadding="5" cellspacing="4"><tr><td width="49%"><div class="subcard-title">Tâches collaboratives</div><table cellpadding="4"><thead><tr><th>État</th><th class="right">Quantité</th></tr></thead><tbody>' . $taskSummaryRows . '</tbody></table></td><td width="49%"><div class="subcard-title">Points d’action de réunion</div><table cellpadding="4"><thead><tr><th>État</th><th class="right">Qté</th><th class="right">Progression</th></tr></thead><tbody>' . $meetingSummaryRows . '</tbody></table></td></tr></table>
<div class="subcard-title">Détail des tâches assignées</div><table cellpadding="5"><thead><tr><th>Tâche</th><th>Priorité</th><th>État</th><th>Échéance</th></tr></thead><tbody>' . $taskDetailRows . '</tbody></table>
<div class="subcard-title">Détail des décisions et actions de réunion</div><table cellpadding="5"><thead><tr><th>Action</th><th>État</th><th class="right">Progression</th><th>Échéance</th></tr></thead><tbody>' . $meetingDetailRows . '</tbody></table>

<div class="section">6.3 <span class="fa">&#xf073;</span> Emploi du temps et planning</div>
<table cellpadding="5"><thead><tr><th>Activité</th><th>Début</th><th>État</th><th class="right">Durée</th></tr></thead><tbody>' . $scheduleDetailRows . '</tbody></table>

<div class="section">6.4 <span class="fa">&#xf15c;</span> Historique des demandes RH</div>
<table cellpadding="5"><thead><tr><th>Demande</th><th>Type</th><th>État</th><th>Date</th></tr></thead><tbody>' . $requestDetailRows . '</tbody></table>';
}

$html = '
<style>
    body { font-family: dejavusans; color: #0f172a; font-size: 8.6px; }
    .cover { background-color:' . $brandPrimary . '; color:#fff; padding:20px 18px; border-radius:14px; }
    .fa { font-family:' . $faFont . '; font-weight:normal; }
    .eyebrow { color:' . $brandAccent . '; font-size:8px; text-transform:uppercase; letter-spacing:1.8px; font-weight:bold; }
    .title { font-size:21px; font-weight:bold; margin-top:5px; }
    .sub { color:#cbd5e1; font-size:9px; margin-top:3px; }
    .badge { background-color:#262626; color:#ffffff; border:1px solid ' . $brandAccent . '; padding:6px 9px; border-radius:20px; font-size:7.5px; }
    .section { margin-top:16px; font-size:12px; color:' . $brandText . '; font-weight:bold; border-bottom:2px solid ' . $brandAccent . '; padding:8px 3px; }
    .section .fa { color:' . $brandPrimary . '; }
    .section-note { color:' . $brandMuted . '; font-size:7.5px; padding:7px 3px 9px; line-height:11px; }
    .kpi { border:0.35px solid #e2e8f0; background-color:#f8fafc; padding:14px 12px; border-radius:16px; }
    .kpi-icon { color:' . $brandPrimary . '; font-size:11px; }
    .kpi-label { color:' . $brandMuted . '; font-size:7px; font-weight:bold; text-transform:uppercase; line-height:10px; }
    .kpi-value { color:' . $brandText . '; font-size:15px; font-weight:bold; line-height:20px; padding-top:5px; }
    .kpi-help { color:' . $brandMuted . '; font-size:6.5px; line-height:9px; }
    .insight { border:1px solid #bbf7d0; background-color:#ecfdf5; color:#064e3b; padding:8px; border-radius:8px; }
    table { border-collapse:collapse; width:100%; }
    th { background-color:' . $brandAccent . '; color:' . $brandPrimary . '; border:0.35px solid #e2e8f0; padding:6px; font-weight:bold; }
    td { border:0.35px solid #e2e8f0; padding:6px; vertical-align:top; }
    .right { text-align:right; } .center { text-align:center; } .muted { color:#64748b; }
    .comment { border-left:3px solid #10b981; background-color:#f8fafc; padding:8px; margin-bottom:6px; min-height:30px; }
    .decision { border:1px solid #f59e0b; background-color:#fffbeb; color:#78350f; padding:9px; border-radius:8px; }
    .small { font-size:7px; color:#64748b; }
    .profile { margin-top:11px; border:0.5px solid #e2e8f0; background-color:#ffffff; border-radius:18px; padding:12px; }
    .profile-photo-cell { background-color:#ffffff; border:0 solid #ffffff; text-align:center; vertical-align:middle; }
    .photo-frame { background-color:#ffffff; padding:2px; border:0.7px solid ' . $brandAccent . '; border-radius:24px; text-align:center; }
    .avatar { background-color:' . $brandPrimary . '; color:' . $brandAccent . '; border:0.7px solid ' . $brandAccent . '; border-radius:24px; font-size:20px; padding:11px 7px; text-align:center; }
    .profile-kicker { color:' . $brandMuted . '; font-size:6.5px; font-weight:bold; letter-spacing:1px; padding-bottom:3px; }
    .profile-name { font-size:16px; font-weight:bold; color:#0f172a; padding-top:2px; }
    .profile-meta { color:#64748b; font-size:8px; padding-top:3px; }
    .profile-period { background-color:' . $brandAccent . '; color:' . $brandPrimary . '; border-radius:15px; padding:6px 9px; font-size:7px; font-weight:bold; }
    .cards { margin-top:2px; }
    .metric { border:0.35px solid #e2e8f0; border-radius:16px; padding:13px 11px; }
    .metric-title { display:block; min-height:15px; padding-bottom:5px; }
    .metric .fa { font-size:11px; color:' . $brandPrimary . '; }
    .metric-label { color:#475569; font-size:7px; font-weight:bold; text-transform:uppercase; }
    .metric-count { display:block; color:#0f172a; font-size:17px; font-weight:bold; line-height:22px; padding-top:3px; }
    .metric-amount { display:block; color:#475569; font-size:8px; font-weight:bold; line-height:12px; padding-top:2px; }
    .metric-help { display:block; color:#64748b; font-size:6.3px; line-height:8px; padding-top:4px; }
    .beneficiary-note { background-color:#eff6ff; border:1px solid #bfdbfe; border-radius:10px; color:#1e3a8a; padding:8px; margin:5px 0; }
    .subcard-title { background-color:#f1f5f9; color:#334155; border-radius:8px; padding:6px; font-weight:bold; }
</style>
<div class="cover">
    <div class="eyebrow">' . rh_pdf_h($coverEyebrow) . '</div>
    <div class="title">' . rh_pdf_h($periodLabel) . '</div>
    <div class="sub">' . rh_pdf_h($scopeLabel) . ' · du ' . rh_pdf_h(rh_pdf_date($startDate)) . ' au ' . rh_pdf_h(rh_pdf_date($endDate)) . '</div>
    <br><span class="badge">Collaborateurs inclus : ' . rh_pdf_number($personnelCount) . '</span> <span class="badge">Exporté le ' . date('d/m/Y H:i') . '</span>
</div>
' . $profileHtml . '

<div class="section"><span class="fa">&#xf201;</span> 1. Synthèse exécutive</div>
<div class="section-note">Vue immédiate de l’activité du collaborateur sur la période sélectionnée. Chaque carte mesure exclusivement son propre dossier.</div>
<table cellpadding="8" cellspacing="5" nobr="true"><tr>
<td class="kpi" bgcolor="#fff8dc"><span class="kpi-icon fa">&#xf007;</span> <span class="kpi-label">Dossier analysé</span><div class="kpi-value">' . rh_pdf_number($personnelCount) . '</div><div class="kpi-help">Une personne unique, sans consolidation collective.</div></td>
<td class="kpi" bgcolor="#eef6ff"><span class="kpi-icon fa">&#xf577;</span> <span class="kpi-label">Lignes de pointage</span><div class="kpi-value">' . rh_pdf_number($attendance['total_rows'] ?? 0) . '</div><div class="kpi-help">Enregistrements de présence trouvés sur la période.</div></td>
<td class="kpi" bgcolor="#f3f0ff"><span class="kpi-icon fa">&#xf15c;</span> <span class="kpi-label">Demandes RH</span><div class="kpi-value">' . rh_pdf_number($requestTotal) . '</div><div class="kpi-help">Demandes déposées ou traitées concernant la personne.</div></td>
</tr></table>
<table cellpadding="8" cellspacing="5" nobr="true"><tr>
<td class="kpi" bgcolor="#ecfdf5"><span class="kpi-icon fa">&#xf274;</span> <span class="kpi-label">Évènements planifiés</span><div class="kpi-value">' . rh_pdf_number($schedule['events_total'] ?? 0) . '</div><div class="kpi-help">Activités inscrites dans son emploi du temps.</div></td>
<td class="kpi" bgcolor="#fff7ed"><span class="kpi-icon fa">&#xf2f2;</span> <span class="kpi-label">Heures supplémentaires</span><div class="kpi-value">' . rh_pdf_number($overtimeHours, 1) . ' h</div><div class="kpi-help">Volume réalisé au-delà du temps de travail attendu.</div></td>
<td class="kpi" bgcolor="#eff6ff"><span class="kpi-icon fa">&#xf53d;</span> <span class="kpi-label">Fiches de décaissement</span><div class="kpi-value">' . rh_pdf_number($disbursements['emitted_count'] ?? 0) . '</div><div class="kpi-help">Fiches financières créées par le collaborateur.</div></td>
</tr></table>
<div class="insight"><strong>Lecture rapide :</strong> ' . rh_pdf_h($signals) . '</div>

<div class="section"><span class="fa">&#xf577;</span> 2. Assiduité, pointage et heures supplémentaires</div>
<div class="section-note">Lecture de la régularité, de la ponctualité et de la charge horaire. Les taux sont calculés sur les pointages disponibles dans la période.</div>
<table cellpadding="8" cellspacing="5" nobr="true"><tr>
<td class="kpi" bgcolor="#ecfdf5"><span class="kpi-icon fa">&#xf058;</span> <span class="kpi-label">Présence</span><div class="kpi-value">' . rh_pdf_percent($attendance['presence_rate'] ?? 0) . '</div><div class="kpi-help">Part des jours couverts par une présence reconnue.</div></td>
<td class="kpi" bgcolor="#fff7ed"><span class="kpi-icon fa">&#xf2f2;</span> <span class="kpi-label">Taux de retard</span><div class="kpi-value">' . rh_pdf_percent($lateRate) . '</div><div class="kpi-help">Fréquence des arrivées après l’horaire et la tolérance.</div></td>
<td class="kpi" bgcolor="#fff1f2"><span class="kpi-icon fa">&#xf2f5;</span> <span class="kpi-label">Absentéisme</span><div class="kpi-value">' . rh_pdf_percent($absenceRate) . '</div><div class="kpi-help">Part des pointages classés en absence ou maladie.</div></td>
</tr></table>
<table cellpadding="8" cellspacing="5" nobr="true"><tr>
<td class="kpi" bgcolor="#eef6ff"><span class="kpi-icon fa">&#xf274;</span> <span class="kpi-label">Jours travaillés</span><div class="kpi-value">' . rh_pdf_number($attendance['worked_days'] ?? 0, 2) . '</div><div class="kpi-help">Somme des journées effectivement comptabilisées.</div></td>
<td class="kpi" bgcolor="#f5f3ff"><span class="kpi-icon fa">&#xf252;</span> <span class="kpi-label">Heures sup.</span><div class="kpi-value">' . rh_pdf_number($overtime['hours_total'] ?? ($attendance['overtime_hours'] ?? 0), 2) . '</div><div class="kpi-help">Heures supplémentaires validées ou calculées.</div></td>
<td class="kpi" bgcolor="#fff1f2"><span class="kpi-icon fa">&#xf071;</span> <span class="kpi-label">Absences constatées</span><div class="kpi-value">' . rh_pdf_number($attendance['absence_rows'] ?? 0) . '</div><div class="kpi-help">Nombre brut de lignes classées comme absences.</div></td>
</tr></table>
<table cellpadding="4"><thead><tr><th>Statut</th><th class="right">Lignes</th><th class="right">Jours payés</th><th class="right">HS</th></tr></thead><tbody>' . $statusRows . '</tbody></table>

<div class="section"><span class="fa">&#xf0eb;</span> 3. Aide à la décision RH</div>
<div class="section-note">Signaux d’aide à l’analyse construits à partir des indicateurs du dossier. Ils éclairent la décision mais ne remplacent jamais l’appréciation humaine.</div>
' . $suggestionHtml . '

' . $scopeAnalysisHtml . '

<div class="section"><span class="fa">&#xf15c;</span> ' . $requestSectionNumber . '. Demandes RH</div>
<div class="section-note">État des demandes administratives et sociales rattachées au collaborateur pendant la période.</div>
<table cellpadding="8" cellspacing="5" nobr="true"><tr>
<td class="kpi" bgcolor="#eef6ff"><span class="kpi-icon fa">&#xf085;</span> <span class="kpi-label">Traitées</span><div class="kpi-value">' . rh_pdf_number($requests['processed'] ?? 0) . '</div><div class="kpi-help">Demandes ayant reçu une décision ou une action RH.</div></td>
<td class="kpi" bgcolor="#ecfdf5"><span class="kpi-icon fa">&#xf058;</span> <span class="kpi-label">Validées</span><div class="kpi-value">' . rh_pdf_number($requests['approved'] ?? 0) . '</div><div class="kpi-help">Demandes acceptées dans le circuit de validation.</div></td>
</tr></table>
<table cellpadding="8" cellspacing="5" nobr="true"><tr>
<td class="kpi" bgcolor="#fff1f2"><span class="kpi-icon fa">&#xf057;</span> <span class="kpi-label">Refusées</span><div class="kpi-value">' . rh_pdf_number($requests['rejected'] ?? 0) . '</div><div class="kpi-help">Demandes clôturées avec une décision défavorable.</div></td>
<td class="kpi" bgcolor="#f8fafc"><span class="kpi-icon fa">&#xf05e;</span> <span class="kpi-label">Annulées</span><div class="kpi-value">' . rh_pdf_number($requests['cancelled'] ?? 0) . '</div><div class="kpi-help">Demandes retirées ou annulées avant leur terme.</div></td>
</tr></table>
<table cellpadding="4"><thead><tr><th>Type de demande</th><th class="right">Total traité</th></tr></thead><tbody>' . $requestRows . '</tbody></table>

<div class="section"><span class="fa">&#xf073;</span> ' . $planningSectionNumber . '. Planning, charge et surcharge</div>
<div class="section-note">Mesure de l’occupation planifiée du collaborateur et rapprochement avec ses heures supplémentaires.</div>
<table cellpadding="8" cellspacing="5" nobr="true"><tr>
<td class="kpi" bgcolor="#eef6ff"><span class="kpi-icon fa">&#xf073;</span> <span class="kpi-label">Évènements</span><div class="kpi-value">' . rh_pdf_number($schedule['events_total'] ?? 0) . '</div><div class="kpi-help">Nombre d’activités inscrites à son calendrier.</div></td>
<td class="kpi" bgcolor="#ecfdf5"><span class="kpi-icon fa">&#xf017;</span> <span class="kpi-label">Heures planifiées</span><div class="kpi-value">' . rh_pdf_number($schedule['events_hours'] ?? 0, 1) . ' h</div><div class="kpi-help">Durée cumulée des évènements programmés.</div></td>
</tr></table>
<table cellpadding="8" cellspacing="5" nobr="true"><tr>
<td class="kpi" bgcolor="#fff7ed"><span class="kpi-icon fa">&#xf0ae;</span> <span class="kpi-label">Tâches planifiées</span><div class="kpi-value">' . rh_pdf_number(array_sum(array_column((array) ($portfolio['tasks'] ?? []), 'total'))) . '</div><div class="kpi-help">Tâches collaboratives assignées dans la période.</div></td>
<td class="kpi" bgcolor="#f5f3ff"><span class="kpi-icon fa">&#xf0c0;</span> <span class="kpi-label">Actions de réunion</span><div class="kpi-value">' . rh_pdf_number(array_sum(array_column((array) ($portfolio['meeting_tasks'] ?? []), 'total'))) . '</div><div class="kpi-help">Décisions de réunion dont la personne est pilote ou copilote.</div></td>
</tr></table>
<table cellpadding="4"><thead><tr><th>Collaborateur chargé</th><th class="right">Heures</th><th class="right">Évènements</th></tr></thead><tbody>' . $busyRows . '</tbody></table>
<table cellpadding="4"><thead><tr><th>Collaborateur avec HS</th><th class="right">Heures sup.</th><th class="right">Jours</th></tr></thead><tbody>' . $overtimeRows . '</tbody></table>

' . $individualOperationsHtml . '

<div class="section">7. Commentaires RH</div>
' . $commentHtml . '
<div class="small">Rapport généré par FINEA. Les données respectent le périmètre et la période sélectionnés depuis dashboard_rh.php?view=analytique.</div>';

function rh_pdf_rgb(string $hex, array $fallback = [15, 23, 42]): array
{
    $hex = ltrim($hex, '#');
    if (!preg_match('/^[0-9a-f]{6}$/i', $hex)) return $fallback;
    return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
}

function rh_pdf_native_heading(TCPDF $pdf, float $y, string $number, string $title, string $note, array $accent): float
{
    $pdf->SetXY(11, $y);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->SetFont('dejavusans', 'B', 10.5);
    $pdf->Cell(188, 6, $number . '. ' . $title, 0, 1);
    $pdf->SetDrawColor(...$accent);
    $pdf->SetLineWidth(.55);
    $pdf->Line(11, $y + 7, 199, $y + 7);
    $pdf->SetXY(11, $y + 9);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->SetFont('dejavusans', '', 6.4);
    $pdf->MultiCell(188, 5, $note, 0, 'L');
    return $y + 15;
}

function rh_pdf_native_metric(TCPDF $pdf, float $x, float $y, float $w, string $icon, string $label, string $value, string $help, array $fill, array $accent, string $faFont): void
{
    $pdf->SetFillColor(...$fill);
    $pdf->SetDrawColor(226, 232, 240);
    $pdf->SetLineWidth(.18);
    $pdf->RoundedRect($x, $y, $w, 24, 3.2, '1111', 'DF');
    $pdf->SetFillColor(...$accent);
    $pdf->Circle($x + 6.4, $y + 6.1, 3.1, 0, 360, 'F');
    $pdf->SetXY($x + 3.3, $y + 3.25);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->SetFont($faFont, '', 6.5);
    $pdf->Cell(6.2, 5.8, html_entity_decode($icon, ENT_QUOTES | ENT_HTML5, 'UTF-8'), 0, 0, 'C');
    $pdf->SetXY($x + 11, $y + 3.4);
    $pdf->SetTextColor(71, 85, 105);
    $pdf->SetFont('dejavusans', 'B', 5.6);
    $pdf->Cell($w - 13, 4.5, mb_strtoupper($label), 0, 1, 'L', false, '', 1);
    $pdf->SetXY($x + 4, $y + 9.2);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->SetFont('dejavusans', 'B', 12);
    $pdf->Cell($w - 8, 6, $value, 0, 1);
    $pdf->SetXY($x + 4, $y + 16.2);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->SetFont('dejavusans', '', 5.35);
    $pdf->MultiCell($w - 8, 5, $help, 0, 'L', false, 1, '', '', true, 0, false, true, 5, 'T');
}

$pdf = new RhAnalyticsReportPdf('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('FINEA');
$pdf->SetAuthor($exporterName);
$pdf->SetTitle('Rapport RH individuel - ' . $scopeLabel . ' - ' . $periodLabel);
$pdf->companyPdfTitle = 'Rapport analytique RH';
$pdf->companyPdfType = $periodLabel . ($isIndividual ? ' · Individuel' : '');
$pdf->companyPdfId = $documentId;
$pdf->companyPdfCode = 'RH-RAP-01';
$pdf->companyPdfQrPayload = json_encode(['document' => $documentId, 'type' => 'rapport_rh_individuel', 'personnel_id' => $portfolioIds[0] ?? 0, 'period' => [$startDate, $endDate], 'exporter' => $exporterName, 'generated_at' => date(DATE_ATOM), 'fingerprint' => $certificateFingerprint], JSON_UNESCAPED_UNICODE);
$pdf->setPrintHeader(true);
$pdf->setPrintFooter(true);
$pdf->SetMargins(10, 38, 10);
$pdf->SetHeaderMargin(0);
$pdf->SetFooterMargin(8);
$pdf->SetAutoPageBreak(true, 25);
$pdf->AddPage();
$primaryRgb = rh_pdf_rgb($brandPrimary, [5, 5, 5]);
$accentRgb = rh_pdf_rgb($brandAccent, [255, 204, 32]);
$pdf->SetFillColor(...$primaryRgb);
$pdf->SetDrawColor(...$primaryRgb);
$pdf->SetLineWidth(.15);
$pdf->RoundedRect(10, 39, 190, 30, 7, '1111', 'DF');
// Repère vertical et capsule : ils matérialisent le padding du bandeau.
$pdf->SetFillColor(...$accentRgb);
$pdf->RoundedRect(15, 44, 2.4, 20, 1.2, '1111', 'F');
$pdf->RoundedRect(21, 43, 79, 6.5, 3.2, '1111', 'F');
$pdf->SetXY(24, 44.1);
$pdf->SetTextColor(...$primaryRgb);
$pdf->SetFont('dejavusans', 'B', 6.1);
$pdf->Cell(73, 4.2, 'DOSSIER ANALYTIQUE INDIVIDUEL  ·  FINEA', 0, 1, 'C');
$pdf->SetXY(21, 51);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetFont('dejavusans', 'B', 14);
$pdf->Cell(112, 7, $periodLabel, 0, 1);
$pdf->SetXY(21, 60);
$pdf->SetTextColor(203, 213, 225);
$pdf->SetFont('dejavusans', '', 6.3);
$pdf->Cell(112, 4, $scopeLabel, 0, 1, 'L', false, '', 1);
// Bloc période isolé à droite pour une lecture immédiate.
$pdf->SetDrawColor(...$accentRgb);
$pdf->SetLineWidth(.25);
$pdf->RoundedRect(139, 46, 53, 16, 4, '1111', 'D');
$pdf->SetXY(143, 48);
$pdf->SetTextColor(...$accentRgb);
$pdf->SetFont('dejavusans', 'B', 5.5);
$pdf->Cell(45, 4, 'PÉRIODE ANALYSÉE', 0, 1, 'C');
$pdf->SetXY(143, 53);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetFont('dejavusans', 'B', 6.2);
$pdf->Cell(45, 5, rh_pdf_date($startDate) . '  →  ' . rh_pdf_date($endDate), 0, 1, 'C');

$pdf->SetFillColor(255, 255, 255);
$pdf->SetDrawColor(226, 232, 240);
$pdf->SetLineWidth(.2);
$pdf->RoundedRect(10, 71, 190, 31, 4, '1111', 'DF');
$pdf->SetFillColor(...$accentRgb);
$pdf->RoundedRect(13, 75, 24, 23, 4, '1111', 'F');
if ($photoPath !== '') {
    $pdf->Image($photoPath, 15, 77, 20, 19, '', '', '', false, 300, '', false, false, 0);
} else {
    $pdf->SetXY(13, 79);
    $pdf->SetTextColor(...$primaryRgb);
    $pdf->SetFont($faFont, '', 15);
    $pdf->Cell(24, 12, html_entity_decode('&#xf007;', ENT_QUOTES | ENT_HTML5, 'UTF-8'), 0, 1, 'C');
}
$pdf->SetXY(42, 76);
$pdf->SetTextColor(100, 116, 139);
$pdf->SetFont('dejavusans', 'B', 5.8);
$pdf->Cell(95, 4, 'PROFIL PROFESSIONNEL', 0, 1);
$pdf->SetXY(42, 81);
$pdf->SetTextColor(15, 23, 42);
$pdf->SetFont('dejavusans', 'B', 12.5);
$pdf->Cell(95, 7, (string) ($profile['employee_name'] ?? 'Collaborateur'), 0, 1, 'L', false, '', 1);
$pdf->SetXY(42, 89);
$pdf->SetTextColor(100, 116, 139);
$pdf->SetFont('dejavusans', '', 6.5);
$pdf->Cell(95, 4, (string) (($profile['matricule'] ?? 'Sans matricule') . '  ·  ' . ($profile['fonction_label'] ?? 'Fonction non renseignée')), 0, 1, 'L', false, '', 1);
$pdf->SetXY(42, 94);
$pdf->Cell(95, 4, (string) (($profile['service_label'] ?? 'Service non renseigné') . '  ·  ' . ($profile['status_label'] ?? 'Statut non renseigné')), 0, 1, 'L', false, '', 1);
$pdf->SetFillColor(255, 248, 214);
$pdf->RoundedRect(144, 77, 50, 8, 4, '1111', 'F');
$pdf->SetXY(146, 78.5);
$pdf->SetTextColor(120, 53, 15);
$pdf->SetFont('dejavusans', 'B', 6);
$pdf->Cell(46, 5, 'DOSSIER INDIVIDUEL', 0, 1, 'C');
$pdf->SetXY(145, 89);
$pdf->SetTextColor(100, 116, 139);
$pdf->SetFont('dejavusans', '', 5.8);
$pdf->MultiCell(48, 8, "PRISE DE SERVICE\n" . $profileStartLabel, 0, 'C');

$metricWidth = 61.2;
$metricGap = 3.2;
$cardX = [10.0, 10.0 + $metricWidth + $metricGap, 10.0 + (($metricWidth + $metricGap) * 2)];
$metricY = rh_pdf_native_heading($pdf, 106, '1', 'Synthèse exécutive', 'Vue immédiate de l’activité individuelle enregistrée dans FINEA.', $accentRgb);
$summaryCards = [
    ['&#xf007;', 'Dossier analysé', '1', 'Une personne, sans consolidation collective.', [255, 248, 220]],
    ['&#xf577;', 'Pointages', rh_pdf_number($attendance['total_rows'] ?? 0), 'Enregistrements de présence disponibles.', [238, 246, 255]],
    ['&#xf15c;', 'Demandes RH', rh_pdf_number($requestTotal), 'Demandes administratives de la période.', [245, 243, 255]],
    ['&#xf274;', 'Planning', rh_pdf_number($schedule['events_total'] ?? 0), 'Activités inscrites à son calendrier.', [236, 253, 245]],
    ['&#xf2f2;', 'Heures sup.', rh_pdf_number($overtimeHours, 1) . ' h', 'Temps réalisé au-delà de la cible.', [255, 247, 237]],
    ['&#xf53d;', 'Décaissements', rh_pdf_number($disbursements['emitted_count'] ?? 0), 'Fiches financières qu’il a créées.', [239, 246, 255]],
];
foreach ($summaryCards as $i => $card) rh_pdf_native_metric($pdf, $cardX[$i % 3], $metricY + (intdiv($i, 3) * 27), $metricWidth, $card[0], $card[1], $card[2], $card[3], $card[4], $accentRgb, $faFont);
$attendanceY = rh_pdf_native_heading($pdf, $metricY + 57, '2', 'Assiduité et temps de travail', 'Régularité, ponctualité et charge horaire calculées sur la période.', $accentRgb);
$attendanceCards = [
    ['&#xf058;', 'Présence', rh_pdf_percent($attendance['presence_rate'] ?? 0), 'Part des journées reconnues présentes.', [236, 253, 245]],
    ['&#xf2f2;', 'Retards', rh_pdf_percent($lateRate), 'Arrivées après l’horaire et la tolérance.', [255, 247, 237]],
    ['&#xf506;', 'Absentéisme', rh_pdf_percent($absenceRate), 'Part des absences et maladies constatées.', [255, 241, 242]],
    ['&#xf274;', 'Jours travaillés', rh_pdf_number($attendance['worked_days'] ?? 0, 2), 'Journées effectivement comptabilisées.', [238, 246, 255]],
    ['&#xf2f2;', 'Heures sup.', rh_pdf_number($overtime['hours_total'] ?? ($attendance['overtime_hours'] ?? 0), 2) . ' h', 'Volume supplémentaire validé ou calculé.', [245, 243, 255]],
    ['&#xf071;', 'Absences', rh_pdf_number($attendance['absence_rows'] ?? 0), 'Nombre brut de lignes classées absentes.', [255, 241, 242]],
];
foreach ($attendanceCards as $i => $card) rh_pdf_native_metric($pdf, $cardX[$i % 3], $attendanceY + (intdiv($i, 3) * 27), $metricWidth, $card[0], $card[1], $card[2], $card[3], $card[4], $accentRgb, $faFont);

$styleEnd = strpos($html, '</style>');
$tailMarker = '<div class="section"><span class="fa">&#xf0eb;</span> 3.';
$tailStart = strpos($html, $tailMarker);
$tailHtml = ($styleEnd !== false && $tailStart !== false) ? substr($html, 0, $styleEnd + 8) . '<table cellpadding="4"><thead><tr><th>Statut</th><th class="right">Lignes</th><th class="right">Jours payés</th><th class="right">HS</th></tr></thead><tbody>' . $statusRows . '</tbody></table>' . substr($html, $tailStart) : $html;
$pdf->AddPage();
$pdf->writeHTML($tailHtml, true, false, true, false, '');
$certificateHeight = 31.0;
if ($pdf->GetY() + $certificateHeight > 268) $pdf->AddPage();
$pdf->Ln(5);
$certificateY = $pdf->GetY();
$pdf->SetFillColor(248, 250, 252);
$pdf->SetDrawColor(203, 213, 225);
$pdf->RoundedRect(10, $certificateY, 190, $certificateHeight, 4, '1111', 'DF');
$pdf->SetXY(16, $certificateY + 4);
$pdf->SetTextColor(15, 23, 42);
$pdf->SetFont('dejavusans', 'B', 8);
$pdf->Cell(124, 5, 'CERTIFICATION DE L’EXPORT', 0, 1);
$pdf->SetX(16);
$pdf->SetTextColor(71, 85, 105);
$pdf->SetFont('dejavusans', '', 7);
$pdf->Cell(124, 4, 'Exporté et signé par : ' . $exporterName, 0, 1);
$pdf->SetX(16);
$pdf->Cell(124, 4, 'Date et heure : ' . date('d/m/Y H:i') . ' · Identifiant : ' . $documentId, 0, 1);
$pdf->SetX(16);
$pdf->SetFont('dejavusansmono', '', 6.3);
$pdf->Cell(124, 4, 'Empreinte du certificat : ' . $certificateFingerprint, 0, 1);
$pdf->SetXY(143, $certificateY + 3);
$pdf->SetFont('dejavusans', 'B', 7);
$pdf->SetTextColor(15, 23, 42);
$pdf->Cell(51, 4, 'SIGNATURE DE L’EXPORTATEUR', 0, 1, 'C');
$signatureSource = '';
if (preg_match('#^data:image/(png|jpeg);base64,(.+)$#s', $exporterSignatureData, $signatureMatch)) {
    $signatureBinary = base64_decode($signatureMatch[2], true);
    $signatureInfo = is_string($signatureBinary) ? @getimagesizefromstring($signatureBinary) : false;
    if (is_string($signatureBinary) && $signatureBinary !== '' && strlen($signatureBinary) <= 4 * 1024 * 1024 && is_array($signatureInfo) && in_array((string) ($signatureInfo['mime'] ?? ''), ['image/png', 'image/jpeg'], true)) $signatureSource = '@' . $signatureBinary;
}
if ($signatureSource === '' && $exporterSignaturePath !== '') {
    $applicationRoot = realpath(dirname(__DIR__));
    $absoluteSignature = $applicationRoot ? realpath($applicationRoot . '/' . ltrim($exporterSignaturePath, '/')) : false;
    $signatureFileInfo = $absoluteSignature && is_file($absoluteSignature) ? @getimagesize($absoluteSignature) : false;
    if ($absoluteSignature && $applicationRoot && str_starts_with($absoluteSignature, $applicationRoot . '/') && is_file($absoluteSignature) && is_array($signatureFileInfo) && in_array((string) ($signatureFileInfo['mime'] ?? ''), ['image/png', 'image/jpeg'], true)) $signatureSource = $absoluteSignature;
}
if ($signatureSource !== '') {
    $pdf->Image($signatureSource, 154, $certificateY + 9, 30, 15, '', '', '', false, 300);
} else {
    $pdf->SetXY(143, $certificateY + 13);
    $pdf->SetTextColor(148, 163, 184);
    $pdf->SetFont('dejavusans', 'I', 6.2);
    $pdf->MultiCell(51, 8, 'Aucune signature enregistrée dans le profil', 0, 'C');
}
$scopeFileToken = $isIndividual ? ('personnel_' . (int) $portfolioIds[0]) : $scope;
$fileSuffix = preg_replace('/[^A-Za-z0-9_-]+/', '_', strtolower($mode . '_' . $scopeFileToken . '_' . $startDate . '_' . $endDate));
while (ob_get_level() > 0) ob_end_clean();
$pdf->Output('rapport_rh_' . $fileSuffix . '.pdf', 'I');
exit;
