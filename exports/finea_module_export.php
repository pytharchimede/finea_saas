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
if (!in_array($module, ['unites','clients','offres','chantiers'], true) || !in_array($format, ['pdf','xlsx'], true)) {
    http_response_code(400);
    exit('Export non pris en charge.');
}
$permission = ['clients'=>'acces_liste_client','unites'=>'acces_liste_unite','offres'=>'acces_liste_offre','chantiers'=>'acces_liste_chantier'][$module];
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
} elseif ($module === 'offres') {
    require_once dirname(__DIR__).'/model/Offre.php';
    $pdo=Database::getConnection();
    foreach (['commercial_dedie'=>'commercial_dedie','date_from'=>'date_offre_from','date_to'=>'date_offre_to'] as $input=>$key) {
        if (!isset($_GET[$input])) continue;
        if (!is_string($_GET[$input])) { http_response_code(400); exit('Filtre invalide.'); }
        $filters[$key]=trim($_GET[$input]);
    }
    $scope=$_GET['site_status']??'all';
    if (!in_array($scope,['all','active','none'],true)) {http_response_code(400);exit('Filtre invalide.');}
    $projects=(new Offre($pdo))->searchOffres($filters);
    $metrics=$pdo->prepare('SELECT (SELECT COUNT(*) FROM devis_banamur d WHERE d.offre_id=o.id_offre AND COALESCE(d.masque,0)=0) quote_count,(SELECT COUNT(*) FROM chantier_banamur ch JOIN devis_banamur d ON d.id=ch.devis_id WHERE d.offre_id=o.id_offre AND ch.archived_at IS NULL) site_count,(SELECT COALESCE(SUM(d.total_ttc),0) FROM devis_banamur d WHERE d.offre_id=o.id_offre AND COALESCE(d.masque,0)=0) project_value,cl.nom_client FROM offre_banamur o LEFT JOIN client_banamur cl ON cl.id_client=o.client_id WHERE o.id_offre=?');
    $headers=['N° projet','Référence','Client','Superviseur','Date','Devis','Chantiers actifs','Valeur TTC (FCFA)'];
    $widths=[13,23,17,15,9,6,8,9];
    $rows=[];
    foreach ($projects as $project) {
        $metrics->execute([(int)$project['id_offre']]);
        $m=$metrics->fetch(PDO::FETCH_ASSOC);
        if (!$m) continue;
        $sites=(int)$m['site_count'];
        if (($scope==='active' && !$sites)||($scope==='none' && $sites)) continue;
        $rows[]=[(string)$project['num_offre'],(string)$project['reference_offre'],(string)($m['nom_client']??''),(string)$project['commercial_dedie'],(string)$project['date_offre'],(int)$m['quote_count'],$sites,(float)$m['project_value']];
    }
} elseif ($module === 'chantiers') {
    require_once dirname(__DIR__).'/services/ChantierDashboardReport.php';
    $scope=$_GET['scope']??(!empty($_GET['include_archived'])?'all':(!empty($_GET['archives'])?'archived':'active'));
    $client=$_GET['client_id']??'0';
    if(!in_array($scope,['active','archived','all'],true)||(!is_string($client))||($client!==''&&!ctype_digit($client))){http_response_code(400);exit('Filtre invalide.');}
    $data=ChantierDashboardReport::rows(Database::getConnection(),$scope,$filters['q'],(int)$client);
    $headers=['Code','Chantier','Client','Devis','État','Conformité','Devis TTC (FCFA)','Déboursé prévu (FCFA)'];
    $widths=[10,21,15,12,10,10,11,11];
    $rows=array_map(static fn($r)=>[(string)$r['code'],(string)$r['titre'],(string)$r['nom_client'],(string)$r['numero_devis'],ChantierDashboardReport::state($r),$r['financial_data_status']==='compliant'?'Conforme':'À vérifier',$r['total_ttc']===null?'Non renseigné':(float)$r['total_ttc'],$r['budget']===null?'Non renseigné':(float)$r['budget']],$data);
} else {
    $usage=$_GET['usage']??'';
    if(!in_array($usage,['','used','unused'],true)){http_response_code(400);exit('Filtre invalide.');}
    $rows = (new UniteMesure(Database::getConnection()))->usageReport($filters+['usage'=>$usage]);
    $headers = ['Symbole','Libellé','Lignes de devis','Utilisation'];
    $widths = [18,42,20,20];
    $rows = array_map(static fn(array $row): array => [(string)$row['symbole'],(string)$row['libelle'],(int)$row['usage_count'],(int)$row['usage_count']>0?'Utilisée':'Inutilisée'], $rows);
}
$lastColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));
$brand = CompanyBranding::get();
$palette = CompanyBranding::palette();
$title = ['clients'=>'Liste des clients','unites'=>'Liste des unités de mesure','offres'=>'Portefeuille des projets','chantiers'=>'Suivi des chantiers'][$module];
if ($format === 'xlsx') {
    $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $book->getProperties()->setCreator(CompanyExportBranding::creator())->setTitle($title);
    $sheet = $book->getActiveSheet();
    $sheet->setTitle(['clients'=>'Clients','unites'=>'Unités','offres'=>'Projets','chantiers'=>'Chantiers'][$module]);
    $sheet->fromArray($headers,null,'A1');
    $sheet->getStyle('A1:'.$lastColumn.'1')->applyFromArray([
        'font'=>['bold'=>true,'color'=>['rgb'=>CompanyExportBranding::rgb('primary',true)]],
        'fill'=>['fillType'=>'solid','startColor'=>['rgb'=>CompanyExportBranding::rgb('primary')]]
    ]);
    $i=2;
    foreach ($rows as $row) {
        foreach ($row as $index=>$value) {
            $column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index+1);
            $sheet->setCellValueExplicit($column.$i,$value,is_int($value)||is_float($value)?\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC:\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
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
$pdf = new FineaUnitsExportPdf($module === 'unites' ? 'P' : 'L','mm','A4',true,'UTF-8',false);
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
    foreach ($row as $index=>$value) $html.='<td width="'.$widths[$index].'%">'.$escape(is_float($value)?number_format($value,0,',',' '):$value).'</td>';
    $html.='</tr>';
}
if (!$rows) $html.='<tr><td colspan="'.count($headers).'">Aucun résultat correspondant.</td></tr>';
$pdf->writeHTML($html.'</tbody></table>',true,false,true,false,'');
$pdf->Output($module.'.pdf','I');
