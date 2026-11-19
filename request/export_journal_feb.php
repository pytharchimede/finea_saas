<?php
if (ob_get_level() === 0) ob_start();
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess('validation_demandes_finances.php');
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../services/FebFinancialJournalService.php';
require_once __DIR__ . '/../services/FebWorkflowMigrationService.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$format = strtolower(trim((string)($_GET['format'] ?? 'pdf')));
if (!in_array($format, ['pdf', 'xlsx'], true)) $format = 'pdf';
$filters = [
    'date_from'=>$_GET['date_from']??date('Y-m-d'),'date_to'=>$_GET['date_to']??date('Y-m-d'),
    'action'=>$_GET['action']??'','submitter'=>$_GET['submitter']??'','validator'=>$_GET['validator']??'',
    'service'=>$_GET['service']??'','chantier'=>$_GET['chantier']??'','debourse'=>$_GET['debourse']??$_GET['debourse_id']??'',
    'ligne_debourse'=>$_GET['ligne_debourse']??$_GET['ligne_debourse_id']??'','q'=>$_GET['q']??'',
];
$pdo = Database::getConnection();
FebWorkflowMigrationService::ensure($pdo);
$rows = (new FebFinancialJournalService($pdo))->search($filters);
$from = htmlspecialchars((string)$filters['date_from']);
$to = htmlspecialchars((string)$filters['date_to']);
$filename = 'journal_financier_feb_' . preg_replace('/\D/', '', (string)$filters['date_from']) . '_' . preg_replace('/\D/', '', (string)$filters['date_to']);
$clear = static function (): void { while (ob_get_level() > 0) ob_end_clean(); };

if ($format === 'xlsx') {
    $book = new Spreadsheet();
    $sheet = $book->getActiveSheet();
    $sheet->setTitle('Journal FEB');
    $sheet->mergeCells('A1:I1')->setCellValue('A1', 'FINÉA — JOURNAL FINANCIER FEB');
    $sheet->mergeCells('A2:I2')->setCellValue('A2', 'Période du ' . $filters['date_from'] . ' au ' . $filters['date_to'] . ' · ' . count($rows) . ' action(s)');
    $headers = ['Date et heure','FEB','Action','Précision','Soumissionnaire','Service','Chantier / déboursé','Fiche','Auteur / résultat'];
    $sheet->fromArray($headers, null, 'A4');
    $line = 5;
    foreach ($rows as $row) {
        $position = trim((string)($row['chantier_code']??'').' '.(string)($row['chantier_titre']??''));
        if ((int)($row['debourse_id']??0)>0) $position .= "\n" . (string)($row['debourse_label']??'Déboursé') . ' (#' . (int)$row['debourse_id'] . ')';
        $sheet->fromArray([
            date('d/m/Y H:i:s',strtotime((string)$row['created_at'])), 'FEB #'.(int)$row['feb_id'],
            $row['action_label'], $row['event_note'], $row['requester_name'], $row['service_label'], $position,
            trim((string)($row['num_fiche']??'')) ?: 'Aucune', (string)($row['event_by_name']??'Système').' · '.(!empty($row['is_failure'])?'Échec':'Effectuée'),
        ], null, 'A'.$line++);
    }
    $sheet->getStyle('A1:I1')->applyFromArray(['font'=>['bold'=>true,'size'=>20,'color'=>['rgb'=>'FFFFFF']],'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>'172348']],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_CENTER]]);
    $sheet->getRowDimension(1)->setRowHeight(34);
    $sheet->getStyle('A2:I2')->applyFromArray(['font'=>['bold'=>true,'color'=>['rgb'=>'4F46E5']],'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>'EEF2FF']],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_CENTER]]);
    $sheet->getStyle('A4:I4')->applyFromArray(['font'=>['bold'=>true,'color'=>['rgb'=>'FFFFFF']],'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>'4F46E5']]]);
    if ($line > 5) $sheet->getStyle('A4:I'.($line-1))->applyFromArray(['borders'=>['allBorders'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['rgb'=>'CBD5E1']]],'alignment'=>['vertical'=>Alignment::VERTICAL_TOP,'wrapText'=>true]]);
    foreach (['A'=>20,'B'=>13,'C'=>28,'D'=>55,'E'=>25,'F'=>20,'G'=>38,'H'=>22,'I'=>26] as $column=>$width) $sheet->getColumnDimension($column)->setWidth($width);
    $sheet->freezePane('A5');
    $sheet->setAutoFilter('A4:I'.max(4,$line-1));
    $clear();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="'.$filename.'.xlsx"');
    (new Xlsx($book))->save('php://output');
    exit;
}

if (!class_exists('TCPDF')) {
    $tcpdf = __DIR__ . '/../vendor/tecnickcom/tcpdf/tcpdf.php';
    if (is_file($tcpdf)) require_once $tcpdf;
}
$pdf = new TCPDF('L','mm','A4',true,'UTF-8',false);
$pdf->SetCreator('Finéa'); $pdf->SetAuthor('Finéa'); $pdf->SetTitle('Journal financier FEB');
$pdf->SetMargins(10,12,10); $pdf->SetAutoPageBreak(true,12); $pdf->AddPage();
$html = '<div style="background-color:#000000;color:#fff;border-bottom:4px solid #fabd02;padding:14px"><h1>FINÉA — Journal financier FEB</h1><p>Traçabilité des actions du '.$from.' au '.$to.'</p></div>';
$html .= '<p><b>'.count($rows).' action(s)</b> · Les simples consultations de pages sont exclues.</p><table border="1" cellpadding="5"><thead><tr style="background-color:#4f46e5;color:#fff;font-weight:bold"><th width="12%">Date / FEB</th><th width="20%">Action</th><th width="26%">Précision</th><th width="14%">Soumissionnaire</th><th width="18%">Position financière</th><th width="10%">Auteur</th></tr></thead><tbody>';
foreach ($rows as $row) {
    $position = trim((string)($row['chantier_code']??'').' '.(string)($row['chantier_titre']??''));
    if ((int)($row['debourse_id']??0)>0) $position .= '<br>'.htmlspecialchars((string)($row['debourse_label']??'Déboursé')).' (#'.(int)$row['debourse_id'].')';
    $html .= '<tr><td>'.date('d/m/Y H:i',strtotime((string)$row['created_at'])).'<br><b>FEB #'.(int)$row['feb_id'].'</b></td><td><b>'.htmlspecialchars((string)$row['action_label']).'</b><br>'.htmlspecialchars((string)$row['status_label']).'</td><td>'.nl2br(htmlspecialchars((string)($row['event_note']??''))).'</td><td>'.htmlspecialchars((string)($row['requester_name']??'')).'<br>'.htmlspecialchars((string)($row['service_label']??'')).'</td><td>'.$position.(!empty($row['num_fiche'])?'<br><b>'.htmlspecialchars((string)$row['num_fiche']).'</b>':'').'</td><td>'.htmlspecialchars((string)($row['event_by_name']??'Système')).'<br>'.(!empty($row['is_failure'])?'Échec':'Effectuée').'</td></tr>';
}
$html .= '</tbody></table>';
$pdf->writeHTML($html,true,false,true,false,'');
$clear();
$pdf->Output($filename.'.pdf','I');
