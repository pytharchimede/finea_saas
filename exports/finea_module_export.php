<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/auth_check.php';
require_once dirname(__DIR__).'/model/Database.php';
require_once dirname(__DIR__).'/model/UniteMesure.php';
require_once dirname(__DIR__).'/model/CompanyPdfTemplate.php';
require_once dirname(__DIR__).'/model/CompanyExportBranding.php';
require_once dirname(__DIR__).'/vendor/autoload.php';

$module = $_GET['module'] ?? '';
$format = $_GET['format'] ?? '';
if ($module !== 'unites' || !in_array($format, ['pdf','xlsx'], true)) {
    http_response_code(400);
    exit('Export non pris en charge.');
}
if (!AccessManager::hasPermission('acces_liste_unite')) {
    AccessManager::denyAccess('acces_liste_unite','exports/finea_module_export.php');
    exit;
}
$q = $_GET['q'] ?? '';
if (!is_string($q)) { http_response_code(400); exit('Filtre invalide.'); }
$rows = (new UniteMesure(Database::getConnection()))->search(['q'=>trim($q)]);
$brand = CompanyBranding::get();
$palette = CompanyBranding::palette();
$title = 'Liste des unités de mesure';
if ($format === 'xlsx') {
    $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $book->getProperties()->setCreator(CompanyExportBranding::creator())->setTitle($title);
    $sheet = $book->getActiveSheet();
    $sheet->setTitle('Unités');
    $sheet->fromArray(['Symbole','Libellé'],null,'A1');
    $sheet->getStyle('A1:B1')->applyFromArray([
        'font'=>['bold'=>true,'color'=>['rgb'=>CompanyExportBranding::rgb('primary',true)]],
        'fill'=>['fillType'=>'solid','startColor'=>['rgb'=>CompanyExportBranding::rgb('primary')]]
    ]);
    $i=2;
    foreach ($rows as $row) {
        $sheet->setCellValueExplicit('A'.$i,(string)$row['symbole'],\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('B'.$i,(string)$row['libelle'],\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        ++$i;
    }
    $sheet->freezePane('A2');
    $sheet->setAutoFilter('A1:B'.max(1,$i-1));
    $sheet->getColumnDimension('A')->setWidth(20);
    $sheet->getColumnDimension('B')->setWidth(65);
    $sheet->getTabColor()->setRGB(CompanyExportBranding::rgb('accent'));
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="unites.xlsx"');
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save('php://output');
    exit;
}
class FineaUnitsExportPdf extends TCPDF {
    use CompanyPdfTemplate;
    public function Header(): void { $this->drawCompanyPdfHeader(); }
    public function Footer(): void { $this->drawCompanyPdfFooter(); }
}
$pdf = new FineaUnitsExportPdf('P','mm','A4',true,'UTF-8',false);
$pdf->companyPdfTitle=$title;
$pdf->companyPdfType='Référentiel';
$pdf->companyPdfShowQr=false;
$pdf->SetCreator(CompanyExportBranding::creator());
$pdf->SetAuthor((string)$brand['company_name']);
$pdf->SetTitle($title);
$pdf->SetMargins(12,39,12);
$pdf->SetAutoPageBreak(true,25);
$pdf->AddPage();
$pdf->SetFont('dejavusans','',10);
$escape=static fn($v): string => htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$html='<p>'.count($rows).' unité(s)</p><table border="1" cellpadding="5"><thead><tr style="background-color:'.$palette['primary'].';color:'.CompanyBranding::contrastColor($palette['primary']).'"><th width="30%">Symbole</th><th width="70%">Libellé</th></tr></thead><tbody>';
foreach($rows as $row) $html.='<tr><td>'.$escape($row['symbole']).'</td><td>'.$escape($row['libelle']).'</td></tr>';
if (!$rows) $html.='<tr><td colspan="2">Aucune unité correspondante</td></tr>';
$pdf->writeHTML($html.'</tbody></table>',true,false,true,false,'');
$pdf->Output('unites.pdf','I');
