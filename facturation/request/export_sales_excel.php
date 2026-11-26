<?php
declare(strict_types=1);

require_once __DIR__.'/../../model/CompanyExportBranding.php';
require_once __DIR__.'/../../auth_check.php';
AccessManager::requirePageAccess('facturation/factures_vente.php');
require_once __DIR__.'/../includes/sales_export_data.php';
require_once __DIR__.'/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$rows=salesExportData();$book=new Spreadsheet();
$book->getProperties()->setCreator(CompanyExportBranding::creator())->setCompany((string)CompanyBranding::get()['company_name']);$sheet=$book->getActiveSheet();$sheet->setTitle('Registre factures');
$sheet->mergeCells('A1:O1')->setCellValue('A1',mb_strtoupper((string)CompanyBranding::get()['company_name']).' · REGISTRE DES FACTURES DE VENTE');
$sheet->mergeCells('A2:J2')->setCellValue('A2','DOCUMENT MAÎTRISÉ · FINÉA · ISO 9001');$sheet->mergeCells('K2:O2')->setCellValue('K2','Édité le '.date('d/m/Y à H:i'));
$headers=['Référence','Date facture','Client','Téléphone','E-mail','Chantier','Nature','Avancement','HT','Taxes','TTC','Encaissement','Date prévue/réelle','Profil FNE','Identifiant FNE'];
$sheet->fromArray($headers,null,'A4');$line=5;$total=0;
foreach($rows as $row){$totals=salesExportTotals($row);$total+=$totals['ttc'];$sheet->fromArray([(string)($row['reference_interne']??''),substr((string)($row['invoice_date']??''),0,10),(string)($row['client_company_name']??''),(string)($row['client_phone']??''),(string)($row['client_email']??''),trim((string)($row['chantier_code']??'').' · '.(string)($row['chantier_titre']??'')),ucfirst((string)($row['facture_travaux_type']??'')),(float)($row['avancement_percent']??0),$totals['ht'],$totals['tax'],$totals['ttc'],salesExportState($row),substr((string)($row['expected_collection_date']??''),0,10),(string)($row['template']??''),(string)($row['fne_invoice_id']??'')],null,'A'.$line++);}
$sheet->setCellValue('A'.$line,'TOTAL')->mergeCells('A'.$line.':J'.$line)->setCellValue('K'.$line,$total);
$sheet->getStyle('A1:O1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(CompanyExportBranding::rgb('primary'));$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(17)->getColor()->setRGB(CompanyExportBranding::rgb('primary',true));
$sheet->getStyle('A2:O2')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F1F5F9');$sheet->getStyle('A4:O4')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(CompanyExportBranding::rgb('primary'));$sheet->getStyle('A4:O4')->getFont()->setBold(true)->getColor()->setRGB(CompanyExportBranding::rgb('primary',true));$sheet->getStyle('A'.$line.':O'.$line)->getFont()->setBold(true);
$sheet->getStyle('A4:O'.$line)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('CBD5E1');$sheet->getStyle('I5:K'.$line)->getNumberFormat()->setFormatCode('#,##0 "FCFA"');$sheet->freezePane('A5');$sheet->setAutoFilter('A4:O'.max(4,$line-1));foreach(range('A','O') as $column)$sheet->getColumnDimension($column)->setAutoSize(true);$sheet->getStyle('A1:O'.$line)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
$detail=$book->createSheet();$detail->setTitle('Lignes factures');$detail->fromArray(['Référence facture','Client','Chantier','Description','Quantité','Prix unitaire','Taxe','Taux','Total ligne'],null,'A1');$detailLine=2;
foreach($rows as $row)foreach(salesExportItems($row) as $item){$qty=(float)($item['quantity']??1);$amount=(float)($item['amount']??0);$taxes=$item['taxes']??($item['tax']??'');$taxLabel=is_array($taxes)?implode(', ',$taxes):(string)$taxes;$rate=(float)($item['tax_rate']??0);$detail->fromArray([(string)($row['reference_interne']??''),(string)($row['client_company_name']??''),trim((string)($row['chantier_code']??'').' · '.(string)($row['chantier_titre']??'')),(string)($item['description']??''),$qty,$amount,$taxLabel,$rate,$qty*$amount*(1+$rate)],null,'A'.$detailLine++);}
$detail->getStyle('A1:I1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(CompanyExportBranding::rgb('primary'));$detail->getStyle('A1:I1')->getFont()->setBold(true)->getColor()->setRGB(CompanyExportBranding::rgb('primary',true));$detail->freezePane('A2');$detail->setAutoFilter('A1:I'.max(1,$detailLine-1));foreach(range('A','I') as $column)$detail->getColumnDimension($column)->setAutoSize(true);$detail->getStyle('F2:F'.$detailLine)->getNumberFormat()->setFormatCode('#,##0 "FCFA"');$detail->getStyle('I2:I'.$detailLine)->getNumberFormat()->setFormatCode('#,##0 "FCFA"');
while(ob_get_level()>0)ob_end_clean();header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');header('Content-Disposition: attachment; filename="registre_factures_vente_'.date('Ymd_His').'.xlsx"');header('Cache-Control: max-age=0');(new Xlsx($book))->save('php://output');exit;
