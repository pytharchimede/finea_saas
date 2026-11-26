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
if (!in_array($module, ['unites','clients'], true) || !in_array($format, ['pdf','xlsx'], true)) {
    http_response_code(400);
    exit('Export non pris en charge.');
}
$permission = $module === 'clients' ? 'acces_liste_client' : 'acces_liste_unite';
if (!AccessManager::hasPermission($permission)) {
    AccessManager::denyAccess($permission,'exports/finea_module_export.php');
    exit;
}
$q = $_GET['q'] ?? '';
if (!is_string($q)) { http_response_code(400); exit('Filtre invalide.'); }
$filters = ['q'=>trim($q)];
if ($module === 'clients') {
    require_once dirname(__DIR__).'/model/Client.php';
    foreach (['status','pays','commune','created_from','created_to'] as $key) {
        if (!isset($_GET[$key])) continue;
        if (!is_string($_GET[$key])) { http_response_code(400); exit('Filtre invalide.'); }
        $filters[$key] = trim($_GET[$key]);
    }
    if (!in_array($filters['status'] ?? '', ['', 'verified', 'pending'], true)) {
        http_response_code(400); exit('Statut invalide.');
    }
    $rows = (new Client(Database::getConnection()))->searchClients($filters);
    $headers = ['Code','Client','Identifiant fiscal','Régime fiscal','Activité','État'];
    $widths = [12,25,20,14,17,12];
    $rows = array_map(static fn(array $row): array => [
        (string)($row['code_client'] ?? ''),
        (string)($row['nom_client'] ?? ''),
        (string)(ClientBusinessPolicy::displayIdentifier($row)['value'] ?: 'Non renseigné'),
        (string)($row['tax_regime'] ?? 'Non renseigné'),
        (string)($row['fiscal_activity_label'] ?? $row['fiscal_activity_status'] ?? 'Non renseignée'),
        ClientBusinessPolicy::usable($row) ? 'Identité validée' : 'À vérifier',
    ], $rows);
} else {
    $rows = (new UniteMesure(Database::getConnection()))->search($filters);
    $headers = ['Symbole','Libellé'];
    $widths = [30,70];
    $rows = array_map(static fn(array $row): array => [(string)$row['symbole'],(string)$row['libelle']], $rows);
}
$lastColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));
$brand = CompanyBranding::get();
$palette = CompanyBranding::palette();
$title = $module === 'clients' ? 'Liste des clients' : 'Liste des unités de mesure';
if ($format === 'xlsx') {
    $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $book->getProperties()->setCreator(CompanyExportBranding::creator())->setTitle($title);
    $sheet = $book->getActiveSheet();
    $sheet->setTitle($module === 'clients' ? 'Clients' : 'Unités');
    $sheet->fromArray($headers,null,'A1');
    $sheet->getStyle('A1:'.$lastColumn.'1')->applyFromArray([
        'font'=>['bold'=>true,'color'=>['rgb'=>CompanyExportBranding::rgb('primary',true)]],
        'fill'=>['fillType'=>'solid','startColor'=>['rgb'=>CompanyExportBranding::rgb('primary')]]
    ]);
    $i=2;
    foreach ($rows as $row) {
        foreach ($row as $index=>$value) {
            $column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index+1);
            $sheet->setCellValueExplicit($column.$i,$value,\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        }
        ++$i;
    }
    $sheet->freezePane('A2');
    $sheet->setAutoFilter('A1:'.$lastColumn.max(1,$i-1));
    foreach ($widths as $index=>$width) $sheet->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index+1))->setWidth(max(18,$width*1.5));
    $sheet->getTabColor()->setRGB(CompanyExportBranding::rgb('accent'));
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="'.$module.'.xlsx"');
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save('php://output');
    exit;
}
class FineaUnitsExportPdf extends TCPDF {
    use CompanyPdfTemplate;
    public function Header(): void { $this->drawCompanyPdfHeader(); }
    public function Footer(): void { $this->drawCompanyPdfFooter(); }
}
$pdf = new FineaUnitsExportPdf($module === 'clients' ? 'L' : 'P','mm','A4',true,'UTF-8',false);
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
$html='<p>'.count($rows).' résultat(s)</p><table border="1" cellpadding="4"><thead><tr style="background-color:'.$palette['primary'].';color:'.CompanyBranding::contrastColor($palette['primary']).'">';
foreach ($headers as $index=>$label) $html.='<th width="'.$widths[$index].'%">'.$escape($label).'</th>';
$html.='</tr></thead><tbody>';
foreach ($rows as $row) {
    $html.='<tr>';
    foreach ($row as $index=>$value) $html.='<td width="'.$widths[$index].'%">'.$escape($value).'</td>';
    $html.='</tr>';
}
if (!$rows) $html.='<tr><td colspan="'.count($headers).'">Aucun résultat correspondant.</td></tr>';
$pdf->writeHTML($html.'</tbody></table>',true,false,true,false,'');
$pdf->Output($module.'.pdf','I');
