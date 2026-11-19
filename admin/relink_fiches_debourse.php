<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../controllers/NotificationController.php';
require_once __DIR__ . '/../decaissement/model/FicheDecaissement.php';

$pdo = \Database::getConnection();
$model = new \FicheDecaissement($pdo);
$notificationManager = new \NotificationController($pdo);

function relinkBuildUserName(): string
{
    $userName = trim((string)($_SESSION['user_name'] ?? ''));
    if ($userName !== '') {
        return $userName;
    }

    return trim((string)(($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? '')));
}

function relinkBuildQuery(array $state): string
{
    $query = [];
    if (!empty($state['chantier_id'])) {
        $query['chantier_id'] = (int)$state['chantier_id'];
    }
    if (!empty($state['search'])) {
        $query['search'] = (string)$state['search'];
    }
    $query['manual_only'] = !empty($state['manual_only']) ? '1' : '0';

    return http_build_query($query);
}

function relinkReasonLabel(string $reason): string
{
    switch ($reason) {
        case 'absent':
            return 'Aucun lien';
        case 'ligne_supprimee':
            return 'Ligne supprimée';
        case 'debourse_supprime':
            return 'Déboursé supprimé';
        case 'mauvais_devis':
            return 'Mauvais devis';
        default:
            return 'Invalide';
    }
}

function relinkReasonDescription(string $reason): string
{
    switch ($reason) {
        case 'absent':
            return 'Aucune ligne de déboursé valide n’est enregistrée sur la fiche.';
        case 'ligne_supprimee':
            return 'La fiche pointe vers une ligne supprimée. Les libellés visibles peuvent provenir d’un fallback de la fiche.';
        case 'debourse_supprime':
            return 'La ligne n’a plus de déboursé parent valide.';
        case 'mauvais_devis':
            return 'La ligne existe mais elle appartient à un autre devis que celui du chantier.';
        default:
            return 'Le rattachement réel est invalide.';
    }
}

function relinkFormatMoney(float $amount): string
{
    return number_format($amount, 0, ',', ' ') . ' FCFA';
}

function relinkExportFilename(string $format): string
{
    return 'relink_fiches_debourse_' . date('Ymd_His') . '.' . $format;
}

function relinkBuildExportQuery(array $state, string $format): string
{
    $query = [];
    if (!empty($state['chantier_id'])) {
        $query['chantier_id'] = (int)$state['chantier_id'];
    }
    if (!empty($state['search'])) {
        $query['search'] = (string)$state['search'];
    }
    $query['manual_only'] = !empty($state['manual_only']) ? '1' : '0';
    $query['export'] = $format;

    return http_build_query($query);
}

function relinkCandidateSummary(array $row, int $limit = 3): string
{
    $labels = [];
    foreach (array_slice($row['candidate_suggestions'] ?? [], 0, $limit) as $candidate) {
        $labels[] = (string)($candidate['label'] ?? '');
    }

    if (!empty($row['auto_candidate']['label'] ?? '')) {
        array_unshift($labels, 'Exact auto: ' . (string)$row['auto_candidate']['label']);
    }

    $labels = array_values(array_filter(array_unique($labels), static function (string $value): bool {
        return trim($value) !== '';
    }));

    return implode(' | ', $labels);
}

$selectedChantierId = (int)($_REQUEST['chantier_id'] ?? 0);
if ($selectedChantierId < 0) {
    $selectedChantierId = 0;
}

$search = trim((string)($_REQUEST['search'] ?? ''));
$manualOnly = !isset($_REQUEST['manual_only']) || $_REQUEST['manual_only'] !== '0';

$flashSuccess = trim((string)($_GET['flash_success'] ?? ''));
$flashError = trim((string)($_GET['flash_error'] ?? ''));

$state = [
    'chantier_id' => $selectedChantierId,
    'search' => $search,
    'manual_only' => $manualOnly,
];

$postAction = (string)($_POST['action'] ?? '');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $postAction === 'manual_relink') {
    $selectedChantierId = (int)($_POST['chantier_id'] ?? 0);
    $search = trim((string)($_POST['search'] ?? ''));
    $manualOnly = !empty($_POST['manual_only']);
    $state = [
        'chantier_id' => $selectedChantierId,
        'search' => $search,
        'manual_only' => $manualOnly,
    ];

    $ficheId = (int)($_POST['id_fiche'] ?? 0);
    $ligneDebourseId = (int)($_POST['ligne_debourse_id'] ?? 0);
    $note = trim((string)($_POST['note'] ?? ''));

    try {
        $result = $model->relinkFicheDebourseManually(
            $ficheId,
            $ligneDebourseId,
            relinkBuildUserName(),
            (string)($_SERVER['REMOTE_ADDR'] ?? ''),
            (int)($_SERVER['REMOTE_PORT'] ?? 0),
            $note
        );

        \AuditTrail::log('admin_manual_relink_debourse', [
            'module' => 'admin',
            'action' => 'manual_relink_fiche_debourse',
            'entity_type' => 'fiche_decaissement',
            'entity_id' => (string)$ficheId,
            'entity_label' => (string)($result['num_fiche'] ?? ''),
            'result' => !empty($result['changed']) ? 'success' : 'noop',
            'details' => [
                'old_ligne_id' => $result['old_ligne_id'] ?? null,
                'new_ligne_id' => $result['new_ligne_id'] ?? null,
                'debourse_id' => $result['debourse_id'] ?? null,
                'note' => $note !== '' ? $note : null,
            ],
        ], $pdo);

        $message = !empty($result['changed'])
            ? 'Fiche ' . ($result['num_fiche'] ?? '') . ' reliée à la ligne #' . ($result['new_ligne_id'] ?? 0) . '.'
            : 'La fiche ' . ($result['num_fiche'] ?? '') . ' était déjà reliée à cette ligne.';
        $query = relinkBuildQuery($state);
        header('Location: relink_fiches_debourse.php' . ($query !== '' ? ('?' . $query . '&') : '?') . 'flash_success=' . urlencode($message));
        exit;
    } catch (Throwable $e) {
        $query = relinkBuildQuery($state);
        header('Location: relink_fiches_debourse.php' . ($query !== '' ? ('?' . $query . '&') : '?') . 'flash_error=' . urlencode($e->getMessage()));
        exit;
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $postAction === 'bulk_relink') {
    $selectedChantierId = (int)($_POST['chantier_id'] ?? 0);
    $search = trim((string)($_POST['search'] ?? ''));
    $manualOnly = !empty($_POST['manual_only']);
    $state = [
        'chantier_id' => $selectedChantierId,
        'search' => $search,
        'manual_only' => $manualOnly,
    ];

    $motifChantierId = (int)($_POST['motif_chantier_id'] ?? 0);
    $motifDesignation = trim((string)($_POST['motif_designation_fiche'] ?? ''));
    $motifPrecision = trim((string)($_POST['motif_precision_fiche'] ?? ''));
    $ligneDebourseId = (int)($_POST['ligne_debourse_id'] ?? 0);
    $note = trim((string)($_POST['note'] ?? ''));

    try {
        $result = $model->relinkFichesDebourseByMotif(
            $motifChantierId,
            $motifDesignation,
            $motifPrecision,
            $ligneDebourseId,
            relinkBuildUserName(),
            (string)($_SERVER['REMOTE_ADDR'] ?? ''),
            (int)($_SERVER['REMOTE_PORT'] ?? 0),
            $note
        );

        \AuditTrail::log('admin_bulk_relink_debourse', [
            'module' => 'admin',
            'action' => 'bulk_relink_fiche_debourse',
            'entity_type' => 'fiche_decaissement_group',
            'entity_id' => (string)$motifChantierId,
            'entity_label' => $motifDesignation . ' / ' . $motifPrecision,
            'result' => (($result['updated'] ?? 0) > 0) ? 'success' : 'noop',
            'details' => [
                'chantier_code' => $result['chantier_code'] ?? null,
                'matched' => $result['matched'] ?? 0,
                'updated' => $result['updated'] ?? 0,
                'unchanged' => $result['unchanged'] ?? 0,
                'new_ligne_id' => $result['new_ligne_id'] ?? null,
                'debourse_id' => $result['debourse_id'] ?? null,
                'note' => $note !== '' ? $note : null,
            ],
        ], $pdo);

        $message = (($result['updated'] ?? 0) > 0)
            ? (($result['updated'] ?? 0) . ' fiche(s) reliée(s) en masse au motif "' . $motifDesignation . ' / ' . $motifPrecision . '".')
            : 'Aucune fiche supplémentaire à relier pour ce motif.';
        $query = relinkBuildQuery($state);
        header('Location: relink_fiches_debourse.php' . ($query !== '' ? ('?' . $query . '&') : '?') . 'flash_success=' . urlencode($message));
        exit;
    } catch (Throwable $e) {
        $query = relinkBuildQuery($state);
        header('Location: relink_fiches_debourse.php' . ($query !== '' ? ('?' . $query . '&') : '?') . 'flash_error=' . urlencode($e->getMessage()));
        exit;
    }
}

$filters = [
    'chantier_id' => $selectedChantierId,
    'search' => $search,
    'manual_only' => $manualOnly,
];

$rows = $model->listUnresolvedDebourseLinks($filters);
$notificationContext = [
    'chantier_id' => $selectedChantierId,
    'search' => $search,
    'manual_only' => $manualOnly,
    'requester_user_id' => (int)($_SESSION['user_id'] ?? 0),
];
if ($selectedChantierId > 0) {
    foreach ($rows as $notificationRow) {
        if ((int)($notificationRow['chantier_id'] ?? 0) === $selectedChantierId) {
            $notificationContext['chantier_code'] = (string)($notificationRow['chantier_code'] ?? '');
            $notificationContext['chantier_titre'] = (string)($notificationRow['chantier_titre'] ?? '');
            break;
        }
    }
}
if ($rows) {
    $notificationManager->notifyBrokenDebourseLinksDetected(count($rows), $notificationContext);
}
$displayedCount = count($rows);
$autoCandidateCount = 0;
$suggestionCount = 0;
$optionsByDevis = [];
$motifGroups = [];

$export = strtolower(trim((string)($_GET['export'] ?? '')));

if ($export === 'excel') {
    \AuditTrail::log('admin_relink_export', [
        'module' => 'admin',
        'action' => 'export_relink_excel',
        'entity_type' => 'fiche_decaissement_list',
        'entity_label' => 'relink_fiches_debourse',
        'details' => [
            'rows' => count($rows),
            'chantier_id' => $selectedChantierId > 0 ? $selectedChantierId : null,
            'search' => $search !== '' ? $search : null,
            'manual_only' => $manualOnly ? 1 : 0,
        ],
    ], $pdo);

    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . relinkExportFilename('csv') . '"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Num fiche', 'Chantier', 'Designation', 'Precision', 'Montant', 'Motif', 'Candidat auto', 'Suggestions', 'Lien fiche'], ';');
    foreach ($rows as $row) {
        fputcsv($out, [
            (string)($row['num_fiche'] ?? ''),
            trim((string)($row['chantier_code'] ?? '') . ' - ' . (string)($row['chantier_titre'] ?? '')),
            (string)($row['designation_fiche'] ?? ''),
            (string)($row['precision_fiche'] ?? ''),
            (float)($row['montant_fiche'] ?? 0),
            relinkReasonLabel((string)($row['invalid_reason'] ?? 'invalide')),
            (string)($row['auto_candidate']['label'] ?? ''),
            relinkCandidateSummary($row),
            '/decaissement/voir_fiche_details.php?num_fiche=' . rawurlencode((string)($row['num_fiche'] ?? '')),
        ], ';');
    }
    fclose($out);
    exit;
}

if ($export === 'pdf') {
    \AuditTrail::log('admin_relink_export', [
        'module' => 'admin',
        'action' => 'export_relink_pdf',
        'entity_type' => 'fiche_decaissement_list',
        'entity_label' => 'relink_fiches_debourse',
        'details' => [
            'rows' => count($rows),
            'chantier_id' => $selectedChantierId > 0 ? $selectedChantierId : null,
            'search' => $search !== '' ? $search : null,
            'manual_only' => $manualOnly ? 1 : 0,
        ],
    ], $pdo);

    require_once __DIR__ . '/../fpdf/fpdf.php';
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    $pdf = new \FPDF('L', 'mm', 'A4');
    $pdf->AddPage();
    $pdf->SetFont('Helvetica', '', 14);
    $pdf->Cell(0, 8, utf8_decode('Fiches cassees / debourses a relier'), 0, 1);
    $pdf->SetFont('Helvetica', '', 9);
    $subtitle = 'Filtres: ';
    $subtitle .= $selectedChantierId > 0 ? ('chantier #' . $selectedChantierId . ' ') : 'tous chantiers ';
    $subtitle .= $search !== '' ? ('| recherche: ' . $search . ' ') : '';
    $subtitle .= '| mode manuel: ' . ($manualOnly ? 'oui' : 'non');
    $pdf->Cell(0, 6, utf8_decode($subtitle), 0, 1);
    $pdf->Cell(0, 6, utf8_decode('Export du ' . date('Y-m-d H:i:s') . ' - total: ' . count($rows)), 0, 1);
    $pdf->Ln(2);

    $pdf->SetFont('Helvetica', '', 8);
    $pdf->Cell(28, 7, utf8_decode('Fiche'), 1);
    $pdf->Cell(42, 7, utf8_decode('Chantier'), 1);
    $pdf->Cell(58, 7, utf8_decode('Designation'), 1);
    $pdf->Cell(72, 7, utf8_decode('Precision'), 1);
    $pdf->Cell(22, 7, utf8_decode('Montant'), 1);
    $pdf->Cell(28, 7, utf8_decode('Motif'), 1);
    $pdf->Cell(128, 7, utf8_decode('Candidats'), 1);
    $pdf->Ln();

    $pdf->SetFont('Helvetica', '', 7);
    foreach ($rows as $row) {
        $candidateSummary = relinkCandidateSummary($row, 2);
        if ($candidateSummary === '') {
            $candidateSummary = 'Aucune suggestion';
        }
        $pdf->Cell(28, 6, utf8_decode(substr((string)($row['num_fiche'] ?? ''), 0, 24)), 1);
        $pdf->Cell(42, 6, utf8_decode(substr((string)($row['chantier_code'] ?? ''), 0, 10) . ' ' . substr((string)($row['chantier_titre'] ?? ''), 0, 18)), 1);
        $pdf->Cell(58, 6, utf8_decode(substr((string)($row['designation_fiche'] ?? ''), 0, 40)), 1);
        $pdf->Cell(72, 6, utf8_decode(substr((string)($row['precision_fiche'] ?? ''), 0, 52)), 1);
        $pdf->Cell(22, 6, utf8_decode(number_format((float)($row['montant_fiche'] ?? 0), 0, ',', ' ')), 1);
        $pdf->Cell(28, 6, utf8_decode(substr(relinkReasonLabel((string)($row['invalid_reason'] ?? 'invalide')), 0, 20)), 1);
        $pdf->Cell(128, 6, utf8_decode(substr($candidateSummary, 0, 95)), 1);
        $pdf->Ln();
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . relinkExportFilename('pdf') . '"');
    $pdf->Output('I');
    exit;
}

foreach ($rows as $row) {
    if (!empty($row['auto_candidate'])) {
        $autoCandidateCount++;
    }
    if (!empty($row['candidate_suggestions'])) {
        $suggestionCount++;
    }

    $devisId = (int)($row['devis_id'] ?? 0);
    if ($devisId > 0 && !isset($optionsByDevis[$devisId])) {
        $optionsByDevis[$devisId] = $model->getDebourseLineOptionsForDevis($devisId);
    }

    $groupKey = implode('|', [
        (int)($row['chantier_id'] ?? 0),
        (string)($row['designation_fiche'] ?? ''),
        (string)($row['precision_fiche'] ?? ''),
    ]);
    if (!isset($motifGroups[$groupKey])) {
        $motifGroups[$groupKey] = [
            'chantier_id' => (int)($row['chantier_id'] ?? 0),
            'chantier_code' => (string)($row['chantier_code'] ?? ''),
            'chantier_titre' => (string)($row['chantier_titre'] ?? ''),
            'devis_id' => $devisId,
            'designation_fiche' => (string)($row['designation_fiche'] ?? ''),
            'precision_fiche' => (string)($row['precision_fiche'] ?? ''),
            'count' => 0,
            'sample_row' => $row,
        ];
    }
    $motifGroups[$groupKey]['count']++;
}

$motifGroups = array_values(array_filter($motifGroups, static function (array $group): bool {
    return ($group['count'] ?? 0) > 1;
}));
usort($motifGroups, static function (array $left, array $right): int {
    if (($left['count'] ?? 0) === ($right['count'] ?? 0)) {
        return [
            (string)($left['chantier_code'] ?? ''),
            (string)($left['designation_fiche'] ?? ''),
            (string)($left['precision_fiche'] ?? ''),
        ] <=> [
            (string)($right['chantier_code'] ?? ''),
            (string)($right['designation_fiche'] ?? ''),
            (string)($right['precision_fiche'] ?? ''),
        ];
    }

    return (int)($right['count'] ?? 0) <=> (int)($left['count'] ?? 0);
});

$chantierStmt = $pdo->query(
    "SELECT
        c.id,
        c.code,
        c.titre,
        COUNT(*) AS unresolved_count
     FROM fiche_decaissement f
     INNER JOIN chantier_banamur c ON c.id = f.chantier_id
     LEFT JOIN ligne_debourse_banamur ldb ON ldb.id = f.ligne_debourse_id
     LEFT JOIN debourse_banamur db ON db.id = ldb.debourse_id
     WHERE (f.decaisse = 1 OR f.approuve = 1 OR f.conforme = 1)
       AND (f.ligne_debourse_id IS NULL OR f.ligne_debourse_id = 0 OR ldb.id IS NULL OR db.devis_id IS NULL OR (c.devis_id IS NOT NULL AND c.devis_id > 0 AND db.devis_id <> c.devis_id))
     GROUP BY c.id, c.code, c.titre
     ORDER BY unresolved_count DESC, c.code ASC"
);
$chantiers = $chantierStmt ? ($chantierStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

$pageTitle = 'Reliaison manuelle fiches / déboursés';
$activeMenu = 'relink_debourse';

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>

<div class="min-h-screen bg-gradient-to-br from-amber-50 via-white to-slate-50">
    <div class="container mx-auto px-4 py-10">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between mb-8">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.28em] text-amber-700">Administration décaissement</p>
                <h1 class="mt-2 text-4xl font-bold text-slate-900">Relier les fiches non rattachées</h1>
                <p class="mt-3 max-w-3xl text-slate-600">Liste exacte des fiches encore non reliées, avec suggestions de rapprochement et choix manuel de la bonne ligne de déboursé.</p>
            </div>
            <div class="flex flex-wrap gap-3">
                <a href="dashboard_admin.php" class="inline-flex items-center gap-2 rounded-xl bg-slate-900 px-5 py-3 text-white shadow hover:bg-slate-800 transition">
                    <i class="fas fa-arrow-left"></i>
                    Admin
                </a>
                <a href="../decaissement/point_financier.php" class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-5 py-3 text-slate-700 shadow-sm hover:shadow transition">
                    <i class="fas fa-chart-line"></i>
                    Point financier
                </a>
            </div>
        </div>

        <?php if ($flashSuccess !== ''): ?>
            <div class="mb-6 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                <?= htmlspecialchars($flashSuccess) ?>
            </div>
        <?php endif; ?>

        <?php if ($flashError !== ''): ?>
            <div class="mb-6 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">
                <?= htmlspecialchars($flashError) ?>
            </div>
        <?php endif; ?>

        <div class="grid gap-4 md:grid-cols-3 mb-6">
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="text-sm text-slate-500">Fiches affichées</div>
                <div class="mt-2 text-3xl font-bold text-slate-900"><?= number_format($displayedCount, 0, ',', ' ') ?></div>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="text-sm text-slate-500">Avec suggestions</div>
                <div class="mt-2 text-3xl font-bold text-slate-900"><?= number_format($suggestionCount, 0, ',', ' ') ?></div>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="text-sm text-slate-500">Avec candidat auto exact</div>
                <div class="mt-2 text-3xl font-bold text-slate-900"><?= number_format($autoCandidateCount, 0, ',', ' ') ?></div>
            </div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm mb-8">
            <form method="get" class="grid gap-4 lg:grid-cols-[minmax(280px,1fr)_minmax(260px,2fr)_auto_auto] lg:items-end">
                <div>
                    <label for="chantier_id" class="mb-2 block text-sm font-semibold text-slate-700">Chantier</label>
                    <select id="chantier_id" name="chantier_id" class="w-full rounded-xl border border-slate-200 px-4 py-3 text-slate-700 focus:border-amber-300 focus:outline-none focus:ring-2 focus:ring-amber-100">
                        <option value="0">Tous les chantiers non reliés</option>
                        <?php foreach ($chantiers as $chantier): ?>
                            <?php $optionValue = (int)($chantier['id'] ?? 0); ?>
                            <option value="<?= $optionValue ?>" <?= $selectedChantierId === $optionValue ? 'selected' : '' ?>>
                                <?= htmlspecialchars((string)($chantier['code'] ?? '') . ' - ' . (string)($chantier['titre'] ?? '') . ' (' . (string)($chantier['unresolved_count'] ?? '0') . ')') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="search" class="mb-2 block text-sm font-semibold text-slate-700">Recherche</label>
                    <input id="search" name="search" type="text" value="<?= htmlspecialchars($search) ?>" placeholder="Numéro de fiche, désignation, précision, code chantier" class="w-full rounded-xl border border-slate-200 px-4 py-3 text-slate-700 focus:border-amber-300 focus:outline-none focus:ring-2 focus:ring-amber-100" />
                </div>

                <label class="flex items-center gap-3 rounded-xl border border-slate-200 px-4 py-3 text-sm font-medium text-slate-700">
                    <input type="hidden" name="manual_only" value="0" />
                    <input type="checkbox" name="manual_only" value="1" <?= $manualOnly ? 'checked' : '' ?> class="h-4 w-4 rounded border-slate-300 text-amber-600 focus:ring-amber-400" />
                    Masquer les cas déjà auto-réconciliables
                </label>

                <button type="submit" class="inline-flex items-center justify-center gap-2 rounded-xl bg-amber-600 px-5 py-3 font-semibold text-white shadow hover:bg-amber-700 transition">
                    <i class="fas fa-filter"></i>
                    Filtrer
                </button>
            </form>

            <div class="mt-4 flex flex-wrap gap-3">
                <a href="relink_fiches_debourse.php?<?= htmlspecialchars(relinkBuildExportQuery($state, 'excel')) ?>" class="inline-flex items-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800 hover:bg-emerald-100 transition">
                    <i class="fas fa-file-excel"></i>
                    Export Excel
                </a>
                <a href="relink_fiches_debourse.php?<?= htmlspecialchars(relinkBuildExportQuery($state, 'pdf')) ?>" class="inline-flex items-center gap-2 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800 hover:bg-rose-100 transition">
                    <i class="fas fa-file-pdf"></i>
                    Export PDF
                </a>
                <a href="relink_fiches_debourse.php" class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-100 transition">
                    <i class="fas fa-rotate-left"></i>
                    Réinitialiser
                </a>
            </div>
        </div>

        <?php if ($motifGroups !== []): ?>
            <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm mb-8">
                <div class="flex items-center justify-between gap-4 mb-5">
                    <div>
                        <h2 class="text-2xl font-bold text-slate-900">Actions de masse par motif</h2>
                        <p class="mt-1 text-sm text-slate-600">Chaque action applique le même rattachement à toutes les fiches non reliées du même chantier ayant exactement la même désignation et la même précision.</p>
                    </div>
                    <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-sm font-semibold text-slate-700"><?= number_format(count($motifGroups), 0, ',', ' ') ?> motif(s)</span>
                </div>

                <div class="space-y-4">
                    <?php foreach ($motifGroups as $group): ?>
                        <?php
                        $sample = $group['sample_row'] ?? [];
                        $groupOptions = $optionsByDevis[(int)($group['devis_id'] ?? 0)] ?? [];
                        $groupAutoId = (int)($sample['auto_candidate']['id'] ?? 0);
                        ?>
                        <div class="rounded-2xl border border-amber-200 bg-amber-50/40 p-4">
                            <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                                <div class="space-y-2 xl:max-w-[48%]">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="inline-flex items-center rounded-full bg-amber-600 px-3 py-1 text-xs font-semibold text-white"><?= number_format((int)($group['count'] ?? 0), 0, ',', ' ') ?> fiche(s)</span>
                                        <span class="inline-flex items-center rounded-full border border-slate-200 bg-white px-3 py-1 text-xs text-slate-700"><?= htmlspecialchars((string)($group['chantier_code'] ?? '') . ' - ' . (string)($group['chantier_titre'] ?? '')) ?></span>
                                    </div>
                                    <div>
                                        <div class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-400">Désignation</div>
                                        <div class="mt-1 font-semibold text-slate-900"><?= htmlspecialchars((string)($group['designation_fiche'] ?? '')) ?></div>
                                    </div>
                                    <div>
                                        <div class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-400">Précision</div>
                                        <div class="mt-1 text-slate-700"><?= htmlspecialchars((string)($group['precision_fiche'] ?? '')) ?></div>
                                    </div>
                                </div>

                                <div class="xl:w-[480px]">
                                    <form method="post" class="rounded-2xl border border-slate-200 bg-white p-4">
                                        <input type="hidden" name="action" value="bulk_relink" />
                                        <input type="hidden" name="chantier_id" value="<?= $selectedChantierId ?>" />
                                        <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>" />
                                        <input type="hidden" name="manual_only" value="<?= $manualOnly ? '1' : '0' ?>" />
                                        <input type="hidden" name="motif_chantier_id" value="<?= (int)($group['chantier_id'] ?? 0) ?>" />
                                        <input type="hidden" name="motif_designation_fiche" value="<?= htmlspecialchars((string)($group['designation_fiche'] ?? '')) ?>" />
                                        <input type="hidden" name="motif_precision_fiche" value="<?= htmlspecialchars((string)($group['precision_fiche'] ?? '')) ?>" />

                                        <label for="bulk_ligne_debourse_id_<?= md5((string)($group['chantier_id'] ?? 0) . '|' . (string)($group['designation_fiche'] ?? '') . '|' . (string)($group['precision_fiche'] ?? '')) ?>" class="mb-2 block text-sm font-semibold text-slate-700">Ligne à appliquer à tout le motif</label>
                                        <select id="bulk_ligne_debourse_id_<?= md5((string)($group['chantier_id'] ?? 0) . '|' . (string)($group['designation_fiche'] ?? '') . '|' . (string)($group['precision_fiche'] ?? '')) ?>" name="ligne_debourse_id" required class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 focus:border-amber-300 focus:outline-none focus:ring-2 focus:ring-amber-100">
                                            <option value="">Sélectionner une ligne</option>
                                            <?php if (!empty($sample['auto_candidate'])): ?>
                                                <option value="<?= (int)$sample['auto_candidate']['id'] ?>" <?= $groupAutoId > 0 ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars('Exact auto - ' . (string)($sample['auto_candidate']['label'] ?? '')) ?>
                                                </option>
                                            <?php endif; ?>
                                            <?php if (!empty($sample['candidate_suggestions'])): ?>
                                                <optgroup label="Suggestions proches">
                                                    <?php foreach ($sample['candidate_suggestions'] as $candidate): ?>
                                                        <option value="<?= (int)($candidate['id'] ?? 0) ?>" <?= $groupAutoId === 0 && (int)($candidate['id'] ?? 0) === (int)($sample['candidate_suggestions'][0]['id'] ?? 0) ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars((string)($candidate['label'] ?? '') . ' [score ' . (int)($candidate['score'] ?? 0) . ']') ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </optgroup>
                                            <?php endif; ?>
                                            <optgroup label="Toutes les lignes du devis">
                                                <?php foreach ($groupOptions as $option): ?>
                                                    <option value="<?= (int)($option['id'] ?? 0) ?>">
                                                        <?= htmlspecialchars((string)($option['label'] ?? '') . ' - ' . relinkFormatMoney((float)($option['montant'] ?? 0))) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </optgroup>
                                        </select>

                                        <label for="bulk_note_<?= md5((string)($group['chantier_id'] ?? 0) . '|' . (string)($group['designation_fiche'] ?? '') . '|' . (string)($group['precision_fiche'] ?? '')) ?>" class="mb-2 mt-4 block text-sm font-semibold text-slate-700">Note de validation</label>
                                        <input id="bulk_note_<?= md5((string)($group['chantier_id'] ?? 0) . '|' . (string)($group['designation_fiche'] ?? '') . '|' . (string)($group['precision_fiche'] ?? '')) ?>" type="text" name="note" placeholder="Pourquoi ce même rattachement s'applique à tout le motif" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 focus:border-amber-300 focus:outline-none focus:ring-2 focus:ring-amber-100" />

                                        <button type="submit" class="mt-4 inline-flex w-full items-center justify-center gap-2 rounded-xl bg-amber-600 px-5 py-3 font-semibold text-white shadow hover:bg-amber-700 transition">
                                            <i class="fas fa-object-group"></i>
                                            Relier en masse ce motif
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="space-y-5">
            <?php if ($rows === []): ?>
                <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 text-emerald-900 shadow-sm">
                    Aucun cas non relié ne correspond aux filtres courants.
                </div>
            <?php endif; ?>

            <?php foreach ($rows as $row): ?>
                <?php
                $devisId = (int)($row['devis_id'] ?? 0);
                $allOptions = $optionsByDevis[$devisId] ?? [];
                $selectedOptionId = (int)($row['auto_candidate']['id'] ?? 0);
                $suggestionIds = [];
                foreach ($row['candidate_suggestions'] as $candidate) {
                    $suggestionIds[(int)($candidate['id'] ?? 0)] = true;
                }
                ?>
                <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                        <div class="space-y-3">
                            <div class="flex flex-wrap items-center gap-3">
                                <span class="inline-flex items-center rounded-full bg-slate-900 px-3 py-1 text-sm font-semibold text-white"><?= htmlspecialchars($row['num_fiche']) ?></span>
                                <span class="inline-flex items-center rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-sm font-semibold text-amber-800"><?= htmlspecialchars(relinkReasonLabel((string)$row['invalid_reason'])) ?></span>
                                <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-sm text-slate-700"><?= htmlspecialchars((string)$row['chantier_code'] . ' - ' . (string)$row['chantier_titre']) ?></span>
                                <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-sm text-slate-700"><?= htmlspecialchars(relinkFormatMoney((float)$row['montant_fiche'])) ?></span>
                                <a href="../decaissement/voir_fiche_details.php?num_fiche=<?= urlencode((string)$row['num_fiche']) ?>" target="_blank" rel="noopener" class="inline-flex items-center gap-2 rounded-full border border-indigo-200 bg-indigo-50 px-3 py-1 text-sm font-semibold text-indigo-700 hover:bg-indigo-100 transition">
                                    <i class="fas fa-up-right-from-square"></i>
                                    Voir la fiche
                                </a>
                            </div>
                            <div class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">
                                <strong>État du lien réel :</strong> <?= htmlspecialchars(relinkReasonDescription((string)$row['invalid_reason'])) ?>
                            </div>

                            <div>
                                <div class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-400">Désignation fiche</div>
                                <div class="mt-1 text-lg font-semibold text-slate-900"><?= htmlspecialchars((string)$row['designation_fiche']) ?></div>
                            </div>

                            <div>
                                <div class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-400">Précision</div>
                                <div class="mt-1 text-slate-700"><?= htmlspecialchars((string)$row['precision_fiche']) ?></div>
                            </div>

                            <?php if (!empty($row['auto_candidate'])): ?>
                                <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                                    Candidat auto exact détecté : <?= htmlspecialchars((string)($row['auto_candidate']['label'] ?? '')) ?>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($row['candidate_suggestions'])): ?>
                                <div>
                                    <div class="mb-2 text-xs font-semibold uppercase tracking-[0.22em] text-slate-400">Meilleurs candidats</div>
                                    <div class="flex flex-wrap gap-2">
                                        <?php foreach ($row['candidate_suggestions'] as $candidate): ?>
                                            <span class="inline-flex items-center gap-2 rounded-full border border-sky-200 bg-sky-50 px-3 py-1 text-xs font-medium text-sky-800">
                                                <?= htmlspecialchars((string)($candidate['label'] ?? '')) ?>
                                                <span class="rounded-full bg-white px-2 py-0.5 text-[11px] text-slate-600">score <?= (int)($candidate['score'] ?? 0) ?></span>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="xl:w-[420px]">
                            <form method="post" class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                <input type="hidden" name="action" value="manual_relink" />
                                <input type="hidden" name="id_fiche" value="<?= (int)$row['id_fiche'] ?>" />
                                <input type="hidden" name="chantier_id" value="<?= $selectedChantierId ?>" />
                                <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>" />
                                <input type="hidden" name="manual_only" value="<?= $manualOnly ? '1' : '0' ?>" />

                                <label for="ligne_debourse_id_<?= (int)$row['id_fiche'] ?>" class="mb-2 block text-sm font-semibold text-slate-700">Choisir la ligne de déboursé</label>
                                <select id="ligne_debourse_id_<?= (int)$row['id_fiche'] ?>" name="ligne_debourse_id" required class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 focus:border-amber-300 focus:outline-none focus:ring-2 focus:ring-amber-100">
                                    <option value="">Sélectionner une ligne</option>
                                    <?php if (!empty($row['auto_candidate'])): ?>
                                        <option value="<?= (int)$row['auto_candidate']['id'] ?>" <?= $selectedOptionId === (int)$row['auto_candidate']['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars('Exact auto - ' . (string)($row['auto_candidate']['label'] ?? '')) ?>
                                        </option>
                                    <?php endif; ?>
                                    <?php if (!empty($row['candidate_suggestions'])): ?>
                                        <optgroup label="Suggestions proches">
                                            <?php foreach ($row['candidate_suggestions'] as $candidate): ?>
                                                <?php $candidateId = (int)($candidate['id'] ?? 0); ?>
                                                <option value="<?= $candidateId ?>" <?= $selectedOptionId === 0 && !empty($suggestionIds[$candidateId]) && array_key_first($suggestionIds) === $candidateId ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars((string)($candidate['label'] ?? '') . ' [score ' . (int)($candidate['score'] ?? 0) . ']') ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    <?php endif; ?>
                                    <optgroup label="Toutes les lignes du devis">
                                        <?php foreach ($allOptions as $option): ?>
                                            <?php $optionId = (int)($option['id'] ?? 0); ?>
                                            <option value="<?= $optionId ?>" <?= $selectedOptionId === 0 && $optionId === (int)($row['ligne_debourse_id'] ?? 0) ? 'selected' : '' ?>>
                                                <?= htmlspecialchars((string)($option['label'] ?? '') . ' - ' . relinkFormatMoney((float)($option['montant'] ?? 0))) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                </select>

                                <label for="note_<?= (int)$row['id_fiche'] ?>" class="mb-2 mt-4 block text-sm font-semibold text-slate-700">Note de validation</label>
                                <input id="note_<?= (int)$row['id_fiche'] ?>" type="text" name="note" placeholder="Pourquoi cette reliaison est correcte" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 focus:border-amber-300 focus:outline-none focus:ring-2 focus:ring-amber-100" />

                                <button type="submit" class="mt-4 inline-flex w-full items-center justify-center gap-2 rounded-xl bg-slate-900 px-5 py-3 font-semibold text-white shadow hover:bg-slate-800 transition">
                                    <i class="fas fa-link"></i>
                                    Relier cette fiche
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>