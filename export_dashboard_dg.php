<?php
if (ob_get_level() === 0) {
    ob_start();
}

require_once __DIR__ . '/auth_check.php';
AccessManager::requirePageAccess('dashboard_dg.php');
require_once __DIR__ . '/model/Database.php';
require_once __DIR__ . '/model/AuditTrail.php';
require_once __DIR__ . '/services/DgDashboardService.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/model/CompanyPdfTemplate.php';
require_once __DIR__ . '/model/CompanyExportBranding.php';

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$format = strtolower(trim((string) ($_GET['format'] ?? 'pdf')));
if (!in_array($format, ['pdf', 'xlsx'], true)) {
    $format = 'pdf';
}
$period = trim((string) ($_GET['period'] ?? date('Y-m')));
$period = preg_match('/^\d{4}-\d{2}$/', $period) ? $period : date('Y-m');
$pdo = Database::getConnection();
$brand = CompanyBranding::get($pdo);
$palette = CompanyBranding::palette($pdo);

$dateFrom=trim((string)($_GET['date_from']??''))?:null;$dateTo=trim((string)($_GET['date_to']??''))?:null;
$dashboard = (new DgDashboardService($pdo))->build((int) ($_SESSION['user_id'] ?? 0), $period,$dateFrom,$dateTo);
$filename = 'pilotage_direction_' . str_replace('-', '', $period) . '_' . date('Ymd_His');

function dgExportMoney($value): string
{
    return number_format((float) $value, 0, ',', ' ') . ' FCFA';
}

function dgExportPercent($value): string
{
    return number_format((float) $value, 1, ',', ' ') . ' %';
}

function dgExportClearOutput(): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
}

function dgExportSummaryRows(array $dashboard): array
{
    return [
        ['Indicateur' => 'Décaissements réalisés sur la période', 'Valeur' => dgExportMoney($dashboard['finance']['month_paid'] ?? 0), 'Lecture DG' => 'Montant effectivement décaissé'],
        ['Indicateur' => 'Décaissements en attente', 'Valeur' => dgExportMoney($dashboard['finance']['pending_amount'] ?? 0), 'Lecture DG' => (int) ($dashboard['finance']['pending_count'] ?? 0) . ' décision(s) à prendre'],
        ['Indicateur' => 'Chantiers actifs', 'Valeur' => (int) ($dashboard['chantiers']['active'] ?? 0), 'Lecture DG' => (int) ($dashboard['chantiers']['late'] ?? 0) . ' échéance(s) dépassée(s)'],
        ['Indicateur' => 'Capacité humaine disponible', 'Valeur' => (int) ($dashboard['statistics']['available_today'] ?? 0), 'Lecture DG' => 'Sur ' . (int) ($dashboard['rh']['attendance_headcount'] ?? 0) . ' personne(s) soumise(s) au pointage'],
        ['Indicateur' => 'Demandes internes ouvertes', 'Valeur' => (int) ($dashboard['tickets']['open'] ?? 0), 'Lecture DG' => (int) ($dashboard['tickets']['overdue'] ?? 0) . ' hors délai'],
        ['Indicateur' => 'Pipeline commercial', 'Valeur' => dgExportMoney($dashboard['crm']['pipeline'] ?? 0), 'Lecture DG' => (int) ($dashboard['crm']['opportunities'] ?? 0) . ' opportunité(s)'],
        ['Indicateur' => 'Appels d’offres actifs', 'Valeur' => (int) ($dashboard['ao']['active'] ?? 0), 'Lecture DG' => (int) ($dashboard['ao']['due_soon'] ?? 0) . ' échéance(s) sous 7 jours'],
        ['Indicateur' => 'Engins indisponibles', 'Valeur' => (int) ($dashboard['logistics']['unavailable_assets'] ?? 0), 'Lecture DG' => 'À rapprocher des besoins des chantiers'],
        ['Indicateur'=>'Factures réellement encaissées','Valeur'=>dgExportMoney($dashboard['treasury_decision']['real_inflows']??0),'Lecture DG'=>'Entrées réelles sur l’intervalle'],
        ['Indicateur'=>'Factures prévisionnelles','Valeur'=>dgExportMoney($dashboard['treasury_decision']['forecast_inflows']??0),'Lecture DG'=>'Encaissements attendus'],
        ['Indicateur'=>'Bons de commande décaissés','Valeur'=>dgExportMoney($dashboard['treasury_decision']['real_outflows']??0),'Lecture DG'=>'Sorties réelles des chantiers actifs'],
        ['Indicateur'=>'FEB prévisionnelles','Valeur'=>dgExportMoney($dashboard['treasury_decision']['forecast_outflows']??0),'Lecture DG'=>'Sorties attendues'],
        ['Indicateur'=>'Prêts restant à rembourser','Valeur'=>dgExportMoney($dashboard['treasury_decision']['loans_outstanding']??0),'Lecture DG'=>'Dette de trésorerie'],
        ['Indicateur'=>'Chiffre d’affaires HT encaissé','Valeur'=>dgExportMoney($dashboard['treasury_decision']['turnover_ht']??0),'Lecture DG'=>'TVA exclue · croissance : '.dgExportPercent($dashboard['treasury_decision']['inflow_growth_pct']??0)],
        ['Indicateur'=>'Bénéfice réalisé estimé','Valeur'=>dgExportMoney($dashboard['treasury_decision']['realized_profit']??0),'Lecture DG'=>'Entrées réelles moins sorties réelles'],
        ['Indicateur'=>'Impôt sur bénéfice estimé (25 %)','Valeur'=>dgExportMoney($dashboard['treasury_decision']['profit_tax_25']??0),'Lecture DG'=>'Indicateur, non décaissé automatiquement'],
        ['Indicateur'=>'TVA encaissée','Valeur'=>dgExportMoney($dashboard['treasury_decision']['vat_collected']??0),'Lecture DG'=>'Hors factures exonérées'],
        ['Indicateur'=>'TVA déductible payée','Valeur'=>dgExportMoney($dashboard['treasury_decision']['vat_deductible']??0),'Lecture DG'=>'Sur pièces d’achat payées'],
        ['Indicateur'=>'TVA reversée','Valeur'=>dgExportMoney($dashboard['treasury_decision']['vat_remitted']??0),'Lecture DG'=>'Reversements tracés sur la période'],
        ['Indicateur'=>'Solde TVA','Valeur'=>dgExportMoney($dashboard['treasury_decision']['vat_balance']??0),'Lecture DG'=>'Encaissée - déductible - reversée'],
        ['Indicateur'=>'Charges fixes payées','Valeur'=>dgExportMoney($dashboard['treasury_decision']['fixed_charges_actual']??0),'Lecture DG'=>'Incluses dans les sorties réelles'],
        ['Indicateur'=>'Solde projeté','Valeur'=>dgExportMoney($dashboard['treasury_decision']['projected_balance']??0),'Lecture DG'=>'Après flux prévisionnels'],
        ['Indicateur'=>'Fiabilité documentaire des chantiers','Valeur'=>dgExportPercent($dashboard['financial_compliance']['reliability_rate']??0),'Lecture DG'=>(int)($dashboard['financial_compliance']['incomplete']??0).' chantier(s) à régulariser ; leurs données restent incluses dans les statistiques'],
    ];
}

function dgExportStatisticRows(array $dashboard): array
{
    $stats = $dashboard['statistics'] ?? [];
    return [
        ['Ratio' => 'Chantiers avec échéance dépassée', 'Taux' => dgExportPercent($stats['chantier_late_rate'] ?? 0)],
        ['Ratio' => 'Demandes internes hors délai', 'Taux' => dgExportPercent($stats['ticket_overdue_rate'] ?? 0)],
        ['Ratio' => 'Capacité humaine disponible', 'Taux' => dgExportPercent($stats['attendance_rate'] ?? 0)],
        ['Ratio' => 'Décaissements programmés encore bloqués', 'Taux' => dgExportPercent($stats['pending_cash_rate'] ?? 0), 'Calcul' => dgExportMoney($dashboard['finance']['pending_amount'] ?? 0) . ' en attente sur ' . dgExportMoney($stats['pending_cash_program_total'] ?? 0) . ' programmés ou réalisés sur la période'],
        ['Ratio' => 'Appels d’offres déjà en préparation', 'Taux' => dgExportPercent($stats['ao_preparation_rate'] ?? 0)],
    ];
}

try {
    AuditTrail::logFileEvent('export', [
        'module' => 'direction',
        'action' => 'export_dashboard_dg',
        'entity_type' => 'dg_dashboard',
        'entity_label' => 'Pilotage Direction Générale ' . $period,
        'format' => $format,
        'details' => ['period' => $period],
    ], $pdo);
} catch (Throwable $e) {
}

if ($format === 'xlsx') {
    $spreadsheet = new Spreadsheet();
    $spreadsheet->getProperties()
        ->setCreator(CompanyExportBranding::creator())
        ->setTitle('Pilotage Direction Générale ' . $period)
        ->setSubject('Synthèse statistique et analytique');

    $datasets = [
        'Synthèse' => dgExportSummaryRows($dashboard),
        'Statistiques' => dgExportStatisticRows($dashboard),
        'Analyse DG' => array_map(static fn(array $row): array => [
            'Priorité' => ucfirst((string) ($row['severity'] ?? 'information')),
            'Constat' => (string) ($row['title'] ?? ''),
            'Explication' => (string) ($row['statement'] ?? ''),
            'Action conseillée' => (string) ($row['recommendation'] ?? ''),
        ], $dashboard['analysis']['insights'] ?? []),
        'Tendance financière' => array_map(static fn(array $row): array => [
            'Période' => (string) ($row['period'] ?? ''),
            'Décaissements FCFA' => (float) ($row['total'] ?? 0),
        ], $dashboard['finance']['trend'] ?? []),
        'Flux de trésorerie'=>array_map(static fn(array $row):array=>[
            'Période'=>(string)($row['period']??''),'Factures encaissées'=>(float)($row['real_inflows']??0),'Factures prévues'=>(float)($row['forecast_inflows']??0),'BC décaissés'=>(float)($row['real_outflows']??0),'FEB prévues'=>(float)($row['forecast_outflows']??0)
        ],$dashboard['treasury_decision']['monthly_cashflow']??[]),
    ];

    $first = true;
    foreach ($datasets as $title => $rows) {
        $sheet = $first ? $spreadsheet->getActiveSheet() : $spreadsheet->createSheet();
        $first = false;
        $sheet->setTitle(mb_substr($title, 0, 31));
        $sheet->getTabColor()->setRGB(CompanyExportBranding::rgb('accent'));
        $sheet->getHeaderFooter()->setOddHeader('&L'.str_replace('&','&&',(string)$brand['company_name']).'&RPilotage Direction Générale');
        $sheet->getHeaderFooter()->setOddFooter('&L'.str_replace('&','&&',(string)$brand['company_name']).'&RPage &P / &N');
        $headers = $rows ? array_keys($rows[0]) : ['Information'];
        $sheet->fromArray($headers, null, 'A1');
        $line = 2;
        foreach ($rows as $row) {
            $sheet->fromArray(array_values($row), null, 'A' . $line++);
        }
        if (!$rows) {
            $sheet->setCellValue('A2', 'Aucune donnée disponible.');
            $line = 3;
        }

        $lastColumn = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:' . $lastColumn . max(1, $line - 1));
        $sheet->getStyle('A1:' . $lastColumn . '1')->getFont()->setBold(true)->getColor()->setRGB(CompanyExportBranding::rgb('primary', true));
        $sheet->getStyle('A1:' . $lastColumn . '1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(CompanyExportBranding::rgb('primary'));
        $sheet->getStyle('A1:' . $lastColumn . max(1, $line - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('DFE6F1');
        $sheet->getStyle('A1:' . $lastColumn . max(1, $line - 1))->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);
        for ($column = 1; $column <= count($headers); $column++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($column))->setWidth($column >= 3 ? 55 : 28);
        }
    }

    $spreadsheet->setActiveSheetIndex(0);
    dgExportClearOutput();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
    header('Cache-Control: max-age=0');
    (new Xlsx($spreadsheet))->save('php://output');
    exit;
}

if (!class_exists('TCPDF')) {
    throw new RuntimeException('TCPDF est indisponible.');
}

class DgDashboardPdf extends TCPDF
{
    use CompanyPdfTemplate;
    public function Header(): void { $this->drawCompanyPdfHeader(); }
    public function Footer(): void { $this->drawCompanyPdfFooter(); }
}

$pdf = new DgDashboardPdf('L', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator(CompanyExportBranding::creator());
$pdf->SetAuthor((string)$brand['company_name']);
$pdf->companyPdfTitle='Pilotage Direction Générale';
$pdf->companyPdfType='Rapport de direction';
$pdf->companyPdfId=$dashboard['start'].' / '.$dashboard['end'];
$pdf->companyPdfShowQr=false;
$pdf->SetTitle('Pilotage Direction Générale ' . $period);
$pdf->SetMargins(12, 39, 12);
$pdf->SetAutoPageBreak(true, 25);
$pdf->AddPage();

$headline = (string) ($dashboard['analysis']['headline'] ?? '');
$pdf->SetFillColor(248, 250, 252);
$pdf->SetDrawColor(226, 232, 240);
$pdf->RoundedRect(12, 39, 273, 29, 4, '1111', 'DF');
$pdf->SetFillColor(...$pdf->companyPdfRgb($palette['accent']));
$pdf->RoundedRect(12, 39, 4, 29, 4, '1101', 'F');
$pdf->SetXY(20, 43);
$pdf->SetFont('dejavusans', 'B', 15);
$pdf->SetTextColor(15, 23, 42);
$pdf->Cell(0, 6, 'Note de pilotage - ' . date('m/Y', strtotime($period . '-01')), 0, 1, 'L');
$pdf->SetX(20);
$pdf->SetFont('dejavusans', '', 9);
$pdf->SetTextColor(71, 85, 105);
$pdf->MultiCell(255, 4.8, $headline, 0, 'L', false, 1);

$summaryRows = dgExportSummaryRows($dashboard);
$html = '<h2 style="color:' . $palette['text'] . ';font-size:12px;">Indicateurs de direction</h2><table cellpadding="5" cellspacing="0" style="width:100%;font-size:8px;border-collapse:collapse"><thead><tr>';
foreach (array_keys($summaryRows[0]) as $header) {
    $html .= '<th style="background-color:' . $palette['primary'] . ';color:' . CompanyBranding::contrastColor($palette['primary']) . ';border:1px solid #dfe6f1;font-weight:bold;">' . htmlspecialchars($header, ENT_QUOTES, 'UTF-8') . '</th>';
}
$html .= '</tr></thead><tbody>';
foreach ($summaryRows as $index => $row) {
    $background = $index % 2 === 0 ? '#ffffff' : '#f8fafc';
    $html .= '<tr>';
    foreach ($row as $value) {
        $html .= '<td style="background-color:' . $background . ';border:1px solid #dfe6f1;color:' . $palette['text'] . ';">' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '</td>';
    }
    $html .= '</tr>';
}
$html .= '</tbody></table>';
$pdf->SetY(max(74,$pdf->GetY()+5));
$pdf->writeHTML($html, true, false, true, false, '');

$pdf->AddPage();
$analysisHtml = '<h2 style="color:' . $palette['text'] . ';font-size:13px;">Analyse en langage de direction</h2>';
foreach (($dashboard['analysis']['insights'] ?? []) as $insight) {
    $analysisHtml .= '<div style="border:1px solid #dfe6f1;background-color:#f8fafc;padding:8px;margin-bottom:8px;">'
        . '<strong style="color:' . $palette['text'] . ';">' . htmlspecialchars((string) ($insight['title'] ?? ''), ENT_QUOTES, 'UTF-8') . '</strong><br>'
        . htmlspecialchars((string) ($insight['statement'] ?? ''), ENT_QUOTES, 'UTF-8')
        . '<br><span style="color:#475569;"><strong>Action conseillée :</strong> ' . htmlspecialchars((string) ($insight['recommendation'] ?? ''), ENT_QUOTES, 'UTF-8') . '</span></div>';
}
$pdf->writeHTML($analysisHtml, true, false, true, false, '');

dgExportClearOutput();
$pdf->Output($filename . '.pdf', 'D');
exit;
