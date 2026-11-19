<?php
require_once dirname(__DIR__, 2) . '/auth_check.php';
require_once dirname(__DIR__) . '/model/Database.php';
require_once dirname(__DIR__) . '/model/ProjectPlanningImport.php';
require_once dirname(__DIR__) . '/includes/planning_dashboard_shared.php';
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/model/AuditTrail.php';

$filters = planningDashboardNormalizeFilters($_GET);
$pdo = \Database::getConnection();
$planningModel = new \ProjectPlanningImport($pdo);
$planningRows = $planningModel->getPlanningDashboardSummaries($filters);
$filterOptions = $planningModel->getPlanningDashboardFilterOptions();
$overview = planningDashboardComputeOverview($planningRows);

$selectedConductorLabel = 'Tous les conducteurs';
foreach (($filterOptions['conductors'] ?? []) as $conductorOption) {
    if ((int)($conductorOption['id'] ?? 0) === (int)($filters['conductor_id'] ?? 0)) {
        $selectedConductorLabel = trim((string)($conductorOption['name'] ?? ''));
        if (trim((string)($conductorOption['service_label'] ?? '')) !== '') {
            $selectedConductorLabel .= ' - ' . trim((string)$conductorOption['service_label']);
        }
        break;
    }
}

if (!class_exists('TCPDF')) {
    http_response_code(500);
    echo 'TCPDF indisponible pour l export PDF.';
    exit;
}

class DashboardPlanningDirectionPdf extends \TCPDF
{
    public string $logoPath = '';
    public string $titleText = 'Dashboard planning chantier';
    public string $subtitleText = 'Direction generale';
    public string $generatedAt = '';

    public function Header(): void
    {
        $this->SetY(8);
        if ($this->logoPath !== '' && is_file($this->logoPath)) {
            $this->Image($this->logoPath, 12, 8, 22, 22, '', '', '', false, 300, '', false, false, 0, false, false, false);
        }

        $this->SetDrawColor(15, 118, 110);
        $this->SetLineWidth(0.8);
        $this->Line(10, 33, $this->getPageWidth() - 10, 33);

        $this->SetXY(38, 9);
        $this->SetFont('helvetica', 'B', 16);
        $this->SetTextColor(17, 24, 39);
        $this->Cell(0, 7, 'BANAMUR INDUSTRIES ET TECHNOLOGIES', 0, 1, 'L');

        $this->SetX(38);
        $this->SetFont('helvetica', 'B', 10);
        $this->SetTextColor(15, 118, 110);
        $this->Cell(0, 5, strtoupper($this->subtitleText), 0, 1, 'L');

        $this->SetX(38);
        $this->SetFont('helvetica', 'B', 13);
        $this->SetTextColor(17, 24, 39);
        $this->Cell(0, 6, $this->titleText, 0, 1, 'L');

        $this->SetX(38);
        $this->SetFont('helvetica', '', 8.5);
        $this->SetTextColor(100, 116, 139);
        $this->Cell(0, 4, 'Version DG imprimable - Genere le ' . $this->generatedAt, 0, 1, 'L');
    }

    public function Footer(): void
    {
        $this->SetY(-12);
        $this->SetDrawColor(226, 232, 240);
        $this->SetLineWidth(0.4);
        $this->Line(10, $this->GetY(), $this->getPageWidth() - 10, $this->GetY());
        $this->Ln(2);
        $this->SetFont('helvetica', '', 8);
        $this->SetTextColor(100, 116, 139);
        $this->Cell(0, 4, 'BANAMUR - Direction generale - Tableau de pilotage planning et budget', 0, 0, 'L');
        $this->Cell(0, 4, 'Page ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, 0, 'R');
    }
}

function planningPdfEsc(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function planningPdfKpiCard(string $label, string $value, string $hint, string $accent): string
{
    return '<table cellpadding="0" cellspacing="0" style="width:100%;border:1px solid #e2e8f0;border-radius:12px;background-color:#ffffff;">'
        . '<tr><td style="padding:12px 14px;">'
        . '<div style="font-size:8pt;letter-spacing:1px;color:#64748b;text-transform:uppercase;">' . planningPdfEsc($label) . '</div>'
        . '<div style="font-size:18pt;font-weight:bold;color:' . $accent . ';margin-top:6px;">' . planningPdfEsc($value) . '</div>'
        . '<div style="font-size:8.5pt;color:#475569;margin-top:4px;">' . planningPdfEsc($hint) . '</div>'
        . '</td></tr></table>';
}

function planningPdfStateBadge(string $label, string $background, string $color): string
{
    return '<span style="display:inline-block;background-color:' . $background . ';color:' . $color . ';padding:4px 8px;border-radius:999px;font-size:8pt;font-weight:bold;">' . planningPdfEsc($label) . '</span>';
}

$filename = 'dashboard_planning_direction_' . date('Ymd_His') . '.pdf';
$logoPath = dirname(__DIR__, 2) . '/logo/default_logo.jpg';
$statusConfig = planningDashboardStatusConfig();

$rowsForPriority = $planningRows;
usort($rowsForPriority, static function (array $left, array $right): int {
    $leftScore = ((int)($left['blocked_count'] ?? 0) * 1000) + ((int)($left['delayed_count'] ?? 0) * 100) + abs((float)($left['progress_budget_gap'] ?? 0));
    $rightScore = ((int)($right['blocked_count'] ?? 0) * 1000) + ((int)($right['delayed_count'] ?? 0) * 100) + abs((float)($right['progress_budget_gap'] ?? 0));
    return $rightScore <=> $leftScore;
});
$priorityRows = array_slice($rowsForPriority, 0, 5);

$pdf = new DashboardPlanningDirectionPdf('L', 'mm', 'A4', true, 'UTF-8', false);
$pdf->logoPath = $logoPath;
$pdf->titleText = 'Tableau de pilotage planning et budget';
$pdf->subtitleText = 'Direction generale';
$pdf->generatedAt = date('d/m/Y H:i:s');
$pdf->SetCreator('BANAMUR');
$pdf->SetAuthor('BANAMUR Direction');
$pdf->SetTitle('Dashboard planning chantier - Direction generale');
$pdf->SetMargins(10, 38, 10);
$pdf->SetAutoPageBreak(true, 14);
$pdf->AddPage();

$meetingFilterLabel = !empty($filters['meeting_date']) ? planningDashboardFormatDate((string)$filters['meeting_date']) : 'Toutes les reunions';

$coverHtml = '<style>
body{font-family:helvetica;color:#0f172a;}
table{border-collapse:separate;border-spacing:0;}
.block-title{font-size:9pt;letter-spacing:1.2px;text-transform:uppercase;color:#64748b;}
.section-title{font-size:16pt;font-weight:bold;color:#0f172a;}
.muted{font-size:9pt;color:#475569;line-height:1.5;}
.panel{border:1px solid #e2e8f0;border-radius:14px;background-color:#ffffff;}
.panel td{padding:0;}
.thin-table{width:100%;border-collapse:collapse;}
.thin-table th,.thin-table td{border-bottom:1px solid #e2e8f0;padding:8px 10px;font-size:8.6pt;}
.thin-table th{background-color:#fabd02;color:#000000;text-transform:uppercase;font-size:7.8pt;letter-spacing:0.7px;}
</style>';

$coverHtml .= '<table width="100%" cellspacing="0" cellpadding="0"><tr>'
    . '<td width="62%" style="padding-right:8px;">'
    . '<div class="block-title">Synthese executive</div>'
    . '<div class="section-title" style="margin-top:4px;">Vision portefeuille travaux</div>'
    . '<div class="muted" style="margin-top:10px;">Ce document presente une vue Direction generale du portefeuille chantier avec lecture croisee du planning, de l execution terrain et de la consommation budgetaire decaissee. Il est concu pour impression et revue en comite.</div>'
    . '</td>'
    . '<td width="38%">'
    . '<table cellpadding="0" cellspacing="0" style="width:100%;border:1px solid #dbeafe;border-radius:14px;background-color:#f8fbff;"><tr><td style="padding:12px 14px;">'
    . '<div class="block-title" style="color:#0369a1;">Perimetre d analyse</div>'
    . '<div style="font-size:10pt;font-weight:bold;color:#0f172a;margin-top:7px;">Reunion: ' . planningPdfEsc($meetingFilterLabel) . '</div>'
    . '<div style="font-size:10pt;font-weight:bold;color:#0f172a;margin-top:5px;">Conducteur: ' . planningPdfEsc($selectedConductorLabel) . '</div>'
    . '<div class="muted" style="margin-top:8px;">Chantiers visibles: ' . number_format((int)($overview['total_chantiers'] ?? 0), 0, ',', ' ') . '</div>'
    . '</td></tr></table>'
    . '</td></tr></table>';

$coverHtml .= '<table width="100%" cellspacing="8" cellpadding="0" style="margin-top:14px;"><tr>'
    . '<td width="16%">' . planningPdfKpiCard('Chantiers dans la vue', number_format((int)($overview['total_chantiers'] ?? 0), 0, ',', ' '), 'Portefeuille apres filtres', '#0f172a') . '</td>'
    . '<td width="16%">' . planningPdfKpiCard('Avec planning', number_format((int)($overview['with_import_count'] ?? 0), 0, ',', ' '), number_format((int)($overview['without_import_count'] ?? 0), 0, ',', ' ') . ' sans import', '#0f766e') . '</td>'
    . '<td width="16%">' . planningPdfKpiCard('Avancement planifie', number_format((float)($overview['global_planned_progress'] ?? 0), 1, ',', ' ') . '%', 'Projection moyenne portefeuille', '#2563eb') . '</td>'
    . '<td width="16%">' . planningPdfKpiCard('Avancement reel', number_format((float)($overview['global_actual_progress'] ?? 0), 1, ',', ' ') . '%', number_format((int)($overview['tracked_projects'] ?? 0), 0, ',', ' ') . ' chantiers suivis', '#0f766e') . '</td>'
    . '<td width="18%">' . planningPdfKpiCard('Budget reference', number_format((float)($overview['budget_reference_total'] ?? 0), 0, ',', ' ') . ' F', number_format((float)($overview['budget_depense_total'] ?? 0), 0, ',', ' ') . ' F deposes', '#7c3aed') . '</td>'
    . '<td width="18%">' . planningPdfKpiCard('Conso budget', number_format((float)($overview['global_budget_consumption'] ?? 0), 1, ',', ' ') . '%', number_format((int)($overview['delayed_projects'] ?? 0), 0, ',', ' ') . ' retards / ' . number_format((int)($overview['blocked_projects'] ?? 0), 0, ',', ' ') . ' blocages', '#dc2626') . '</td>'
    . '</tr></table>';

$coverHtml .= '<table width="100%" cellspacing="10" cellpadding="0" style="margin-top:10px;"><tr>'
    . '<td width="35%">'
    . '<table class="panel" width="100%"><tr><td style="padding:14px;">'
    . '<div class="block-title">Etat du portefeuille</div>'
    . '<table class="thin-table" style="margin-top:10px;">'
    . '<thead><tr><th>Etat</th><th align="right">Volume</th></tr></thead><tbody>'
    . '<tr><td>Sous controle</td><td align="right">' . number_format((int)($overview['state_counts']['maitrise'] ?? 0), 0, ',', ' ') . '</td></tr>'
    . '<tr><td>En retard</td><td align="right">' . number_format((int)($overview['state_counts']['retard'] ?? 0), 0, ',', ' ') . '</td></tr>'
    . '<tr><td>Bloque</td><td align="right">' . number_format((int)($overview['state_counts']['bloque'] ?? 0), 0, ',', ' ') . '</td></tr>'
    . '<tr><td>A demarrer</td><td align="right">' . number_format((int)($overview['state_counts']['a_demarrer'] ?? 0), 0, ',', ' ') . '</td></tr>'
    . '<tr><td>Sans planning</td><td align="right">' . number_format((int)($overview['state_counts']['sans_planning'] ?? 0), 0, ',', ' ') . '</td></tr>'
    . '<tr><td>Presque termine</td><td align="right">' . number_format((int)($overview['state_counts']['termine'] ?? 0), 0, ',', ' ') . '</td></tr>'
    . '</tbody></table>'
    . '</td></tr></table>'
    . '</td>'
    . '<td width="65%">'
    . '<table class="panel" width="100%"><tr><td style="padding:14px;">'
    . '<div class="block-title">Top priorites DG</div>'
    . '<table class="thin-table" style="margin-top:10px;">'
    . '<thead><tr><th>Chantier</th><th>Etat</th><th align="right">Planifie</th><th align="right">Reel</th><th align="right">Conso budget</th><th align="right">Ecart physique</th></tr></thead><tbody>';

if (empty($priorityRows)) {
    $coverHtml .= '<tr><td colspan="6">Aucune priorite critique a remonter sur ce perimetre.</td></tr>';
} else {
    foreach ($priorityRows as $priorityRow) {
        $statusKey = (string)($priorityRow['health_status'] ?? 'sans_planning');
        $statusMeta = $statusConfig[$statusKey] ?? $statusConfig['sans_planning'];
        $coverHtml .= '<tr>'
            . '<td><strong>' . planningPdfEsc((string)($priorityRow['code'] ?? '')) . '</strong><br>' . planningPdfEsc((string)($priorityRow['libelle'] ?? '')) . '</td>'
            . '<td>' . planningPdfStateBadge((string)($priorityRow['health_label'] ?? 'Sans planning'), $statusMeta['badge'] === 'bg-red-100 text-red-800' ? '#fee2e2' : '#e2e8f0', $statusKey === 'bloque' ? '#991b1b' : '#334155') . '</td>'
            . '<td align="right">' . number_format((float)($priorityRow['planned_progress'] ?? 0), 1, ',', ' ') . '%</td>'
            . '<td align="right">' . number_format((float)($priorityRow['actual_progress'] ?? 0), 1, ',', ' ') . '%</td>'
            . '<td align="right">' . number_format((float)($priorityRow['budget_consumption_percent'] ?? 0), 1, ',', ' ') . '%</td>'
            . '<td align="right">' . number_format((float)($priorityRow['progress_budget_gap'] ?? 0), 1, ',', ' ') . ' pts</td>'
            . '</tr>';
    }
}

$coverHtml .= '</tbody></table>'
    . '</td></tr></table>'
    . '</td></tr></table>';

$coverHtml .= '<table width="100%" cellspacing="10" cellpadding="0" style="margin-top:10px;"><tr>'
    . '<td width="50%"><table class="panel" width="100%"><tr><td style="padding:14px;">'
    . '<div class="block-title">Lecture DG</div>'
    . '<div class="muted" style="margin-top:10px;">L ecart entre avancement reel et consommation budgetaire permet d identifier les chantiers en tension. Un ecart negatif signifie que le budget est consomme plus vite que la progression terrain. Un ecart positif indique au contraire un chantier physiquement avance avec une consommation budgetaire encore moderee.</div>'
    . '</td></tr></table></td>'
    . '<td width="50%"><table class="panel" width="100%"><tr><td style="padding:14px;">'
    . '<div class="block-title">Recommandation immediate</div>'
    . '<div class="muted" style="margin-top:10px;">Prioriser en revue les chantiers bloques, puis les chantiers dont le reel reste inferieur au planifie avec une consommation budgetaire deja engagee. Le detail complet par chantier est disponible en page suivante pour arbitrage et suivi.</div>'
    . '</td></tr></table></td>'
    . '</tr></table>';

$pdf->writeHTML($coverHtml, true, false, true, false, '');

$pdf->AddPage();

$detailHtml = '<style>
table{border-collapse:collapse;width:100%;}
th,td{padding:7px 8px;border-bottom:1px solid #e2e8f0;vertical-align:top;}
th{background-color:#0f766e;color:#ffffff;font-size:8pt;text-transform:uppercase;letter-spacing:0.6px;}
td{font-size:8.4pt;color:#0f172a;}
.muted{font-size:8pt;color:#64748b;}
</style>';

$detailHtml .= '<div style="font-size:9pt;letter-spacing:1.2px;text-transform:uppercase;color:#64748b;">Annexe detaillee</div>';
$detailHtml .= '<div style="font-size:16pt;font-weight:bold;color:#0f172a;margin-top:4px;">Tableau detaille des chantiers</div>';
$detailHtml .= '<div class="muted" style="margin-top:6px;margin-bottom:10px;">Vue detaillee printable pour revue DG, avec conducteurs, avancement physique, budget de reference et depenses decaissees.</div>';

$detailHtml .= '<table><thead><tr>'
    . '<th width="7%">Code</th>'
    . '<th width="23%">Chantier</th>'
    . '<th width="15%">Conducteurs</th>'
    . '<th width="10%">Etat</th>'
    . '<th width="8%">Planifie</th>'
    . '<th width="8%">Reel</th>'
    . '<th width="11%">Budget ref</th>'
    . '<th width="8%">Depenses</th>'
    . '<th width="5%">Conso</th>'
    . '<th width="5%">Ecart</th>'
    . '</tr></thead><tbody>';

if (empty($planningRows)) {
    $detailHtml .= '<tr><td colspan="10">Aucun chantier ne correspond aux filtres selectionnes.</td></tr>';
} else {
    foreach ($planningRows as $index => $row) {
        $background = $index % 2 === 0 ? '#ffffff' : '#f8fafc';
        $detailHtml .= '<tr style="background-color:' . $background . ';">'
            . '<td><strong>' . planningPdfEsc((string)($row['code'] ?? '')) . '</strong></td>'
            . '<td>' . planningPdfEsc((string)($row['libelle'] ?? '')) . '<br><span class="muted">Derniere reunion: ' . planningPdfEsc(planningDashboardFormatDate($row['last_meeting_date'] ?? null)) . '</span></td>'
            . '<td>' . planningPdfEsc((string)($row['conductor_label'] ?? 'Non affecte')) . '</td>'
            . '<td>' . planningPdfEsc((string)($row['health_label'] ?? '')) . '</td>'
            . '<td align="right">' . number_format((float)($row['planned_progress'] ?? 0), 1, ',', ' ') . '%</td>'
            . '<td align="right">' . number_format((float)($row['actual_progress'] ?? 0), 1, ',', ' ') . '%</td>'
            . '<td align="right">' . number_format((float)($row['budget_reference'] ?? 0), 0, ',', ' ') . ' F</td>'
            . '<td align="right">' . number_format((float)($row['total_depenses'] ?? 0), 0, ',', ' ') . ' F</td>'
            . '<td align="right">' . number_format((float)($row['budget_consumption_percent'] ?? 0), 1, ',', ' ') . '%</td>'
            . '<td align="right">' . number_format((float)($row['progress_budget_gap'] ?? 0), 1, ',', ' ') . '</td>'
            . '</tr>';
    }
}

$detailHtml .= '</tbody></table>';

$pdf->writeHTML($detailHtml, true, false, true, false, '');
\AuditTrail::logFileEvent('export', [
    'module' => 'planning',
    'action' => 'dashboard_planning_export',
    'entity_type' => 'planning_dashboard',
    'entity_label' => 'Dashboard planning chantier',
    'format' => 'pdf',
    'details' => [
        'meeting_date' => $filters['meeting_date'] ?? null,
        'conductor_id' => $filters['conductor_id'] ?? null,
        'row_count' => is_countable($planningRows ?? null) ? count($planningRows) : null,
    ],
], $pdo);
$pdf->Output($filename, 'I');
exit;
