<?php
declare(strict_types=1);

require_once __DIR__.'/../../auth_check.php';
require_once __DIR__.'/../../model/Database.php';
require_once __DIR__.'/../../services/ManualTreasuryService.php';
require_once __DIR__.'/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

if (!(AccessManager::hasPermission('acces_tresorerie_lecture') || AccessManager::hasPermission('acces_direction') || AccessManager::hasPermission('acces_admin'))) {
    http_response_code(403); exit('Accès refusé.');
}
$from=preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)($_GET['from']??''))?(string)$_GET['from']:date('Y-m-01');
$to=preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)($_GET['to']??''))?(string)$_GET['to']:date('Y-m-t');
$data=(new ManualTreasuryService(Database::getConnection()))->dashboard($from,$to);
$vat=$data['vat']??[];
$rows=array_values(array_filter((array)($vat['snapshots']??[]),static fn(array $r):bool=>(string)($r['snapshot_date']??'')>=$from&&(string)($r['snapshot_date']??'')<=$to));
$book=new Spreadsheet();$sheet=$book->getActiveSheet();$sheet->setTitle('TVA collectée');
$sheet->mergeCells('A1:H1');$sheet->setCellValue('A1','TVA COLLECTÉE — RÉCAPITULATIF')->getStyle('A1')->getFont()->setBold(true)->setSize(16)->getColor()->setRGB('0E7490');
$sheet->mergeCells('A2:H2');$sheet->setCellValue('A2','Période : '.date('d/m/Y',strtotime($from)).' au '.date('d/m/Y',strtotime($to)).' · Factures réellement encaissées')->getStyle('A2')->getFont()->setItalic(true)->getColor()->setRGB('64748B');
$totalHt=array_sum(array_map(static fn($r)=>(float)$r['amount_ht'],$rows));$totalVat=array_sum(array_map(static fn($r)=>(float)$r['amount_vat'],$rows));$totalTtc=array_sum(array_map(static fn($r)=>(float)$r['amount_ttc'],$rows));
$sheet->fromArray([['HT encaissé',$totalHt,'TVA collectée',$totalVat,'TTC encaissé',$totalTtc,'Réserve protégée',(float)($vat['vat_protected']??0)]],null,'A4');
$sheet->getStyle('A4:H4')->getFont()->setBold(true);$sheet->getStyle('A4:H4')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('ECFEFF');
$sheet->fromArray([['Date encaissement','Référence facture','Client','Chantier','Montant HT','TVA collectée','Montant TTC','Statut fiscal']],null,'A6');
$sheet->getStyle('A6:H6')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');$sheet->getStyle('A6:H6')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('0E7490');
$row=7;foreach($rows as $item){$chantier=trim(implode(' · ',array_filter([(string)($item['chantier_code']??''),(string)($item['chantier_title']??'')])))?:'Sans chantier';$sheet->fromArray([[date('d/m/Y',strtotime((string)$item['snapshot_date'])),(string)($item['reference_interne']??('Facture #'.$item['invoice_id'])),(string)($item['client_company_name']??''),$chantier,(float)$item['amount_ht'],(float)$item['amount_vat'],(float)$item['amount_ttc'],(float)$item['amount_vat']>0?'Réservée':'Sans TVA']],null,'A'.$row++);}
$sheet->fromArray([['TOTAL','','','',$totalHt,$totalVat,$totalTtc,'']],null,'A'.$row);$sheet->getStyle('A'.$row.':H'.$row)->getFont()->setBold(true);$sheet->getStyle('A'.$row.':H'.$row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('CFFAFE');
$money='#,##0 "FCFA"';$sheet->getStyle('B4:B4')->getNumberFormat()->setFormatCode($money);$sheet->getStyle('D4:D4')->getNumberFormat()->setFormatCode($money);$sheet->getStyle('F4:F4')->getNumberFormat()->setFormatCode($money);$sheet->getStyle('H4:H4')->getNumberFormat()->setFormatCode($money);$sheet->getStyle('E7:G'.$row)->getNumberFormat()->setFormatCode($money);
foreach(['A'=>18,'B'=>24,'C'=>34,'D'=>34,'E'=>20,'F'=>20,'G'=>20,'H'=>18] as $col=>$width)$sheet->getColumnDimension($col)->setWidth($width);
$sheet->freezePane('A7');$sheet->setAutoFilter('A6:H'.max(6,$row-1));$sheet->getStyle('A6:H'.$row)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('D1D5DB');$sheet->getStyle('E7:G'.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');header('Content-Disposition: attachment; filename="tva_collectee_'.$from.'_au_'.$to.'.xlsx"');header('Cache-Control: max-age=0');(new Xlsx($book))->save('php://output');exit;
