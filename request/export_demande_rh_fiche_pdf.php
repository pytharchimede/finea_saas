<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/EmployeeLegalRequestManager.php';
require_once __DIR__ . '/../model/CompanyBranding.php';
require_once __DIR__ . '/../model/CompanyPdfTemplate.php';
require_once __DIR__ . '/../services/RhCompanyProfileService.php';

function e($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function money($value): string { return number_format((float)$value, 0, ',', ' ') . ' FCFA'; }
function fmtDate($value): string {
    $v = trim((string)$value);
    if ($v === '') return '—';
    $ts = strtotime($v);
    return $ts ? date('d/m/Y', $ts) : $v;
}
function fmtDateTime($value): string {
    $v = trim((string)$value);
    if ($v === '') return '—';
    $ts = strtotime($v);
    return $ts ? date('d/m/Y à H:i', $ts) : $v;
}
function statusLabel(array $request): string {
    $meta = \EmployeeLegalRequestManager::getRequestStatusMeta($request);
    return (string)($meta['label'] ?? 'En attente');
}
function statusColors(string $status): array {
    $normalized = mb_strtolower($status, 'UTF-8');
    if (str_contains($normalized, 'approuv') || str_contains($normalized, 'valid')) return [[220,252,231],[21,128,61]];
    if (str_contains($normalized, 'refus') || str_contains($normalized, 'rejet')) return [[255,228,230],[190,18,60]];
    return [[255,247,214],[133,77,14]];
}

final class RhRequestPdf extends \TCPDF
{
    public array $company = [];
    public string $logo = '';
    public string $documentTitle = '';
    public string $documentCode = '';
    public string $documentVersion = '1.0';
    public string $issueDate = '';

    use CompanyPdfTemplate;
    public function Header(): void {$this->drawCompanyPdfHeader();}
    public function Footer(): void {$this->drawCompanyPdfFooter();}
    public function removeGeneratorCredit(): void {$this->tcpdflink=false;}

    public function ensureSpace(float $needed): void
    {
        if ($this->GetY() + $needed > $this->getPageHeight() - 28) $this->AddPage();
    }
}

function sectionTitle(RhRequestPdf $pdf, string $number, string $title): void
{
    $pdf->ensureSpace(16);
    $pdf->Ln(3);
    $y = $pdf->GetY();
    $pdf->SetFillColor(...$pdf->companyPdfRgb($pdf->companyPdfColors()['primary']));
    $pdf->RoundedRect(14,$y,182,10,2,'1111','F');
    $pdf->SetFillColor(...$pdf->companyPdfRgb($pdf->companyPdfColors()['accent']));
    $pdf->RoundedRect(14,$y,8,10,2,'1100','F');
    $pdf->SetXY(25,$y+2.1);
    $pdf->SetFont('dejavusans','B',10.5);
    $pdf->SetTextColor(...$pdf->companyPdfContrast($pdf->companyPdfColors()['primary']));
    $prefix = trim($number) !== '' ? $number.'. ' : '';
    $pdf->Cell(0,5,$prefix.$title,0,1,'L');
    $pdf->Ln(2.5);
}

function infoGrid(RhRequestPdf $pdf, array $items, array $highlightLabels = []): void
{
    $pdf->ensureSpace(18 + ceil(count($items)/2)*13);
    $html = '<table cellpadding="5" cellspacing="0" style="width:100%;">';
    foreach (array_chunk($items,2) as $pair) {
        $html .= '<tr>';
        foreach ($pair as $item) {
            $label = (string)$item[0];
            $highlight = in_array($label,$highlightLabels,true);
            $valueColor = $highlight ? '#b91c1c' : '#0f172a';
            $html .= '<td style="width:50%;border:1px solid #dbe3ee;background-color:#ffffff;">'
                .'<span style="font-size:7.2pt;color:#64748b;font-weight:bold;">'.e(mb_strtoupper($label,'UTF-8')).'</span><br><br>'
                .'<span style="font-size:9.7pt;font-weight:bold;color:'.$valueColor.';">'.e((string)($item[1] ?: '—')).'</span></td>';
        }
        if (count($pair) === 1) $html .= '<td style="width:50%;border:0;"></td>';
        $html .= '</tr>';
    }
    $html .= '</table>';
    $pdf->SetFont('dejavusans','',8.5);
    $pdf->writeHTML($html,true,false,true,false,'');
}

function textBlock(RhRequestPdf $pdf, string $text, bool $important = false): void
{
    $text = trim($text) !== '' ? trim($text) : '—';
    $pdf->ensureSpace(25);
    $border = $important ? '#b91c1c' : '#dbe3ee';
    $color = $important ? '#7f1d1d' : '#334155';
    $prefix = $important ? '<b style="color:#b91c1c">POINT D’ATTENTION</b><br><br>' : '';
    $html = '<table cellpadding="8" style="width:100%;border:1px solid '.$border.';background-color:#ffffff"><tr><td>'
        .$prefix.'<span style="font-size:9.2pt;line-height:1.6;color:'.$color.';">'.nl2br(e($text)).'</span></td></tr></table>';
    $pdf->SetFont('dejavusans','',8.8);
    $pdf->writeHTML($html,true,false,true,false,'');
}

function signatureCards(RhRequestPdf $pdf, array $rows): void
{
    $pdf->ensureSpace(58);
    $count = count($rows); $gap = 4; $usable = 182;
    $w = ($usable - (($count-1)*$gap)) / max(1,$count);
    $y = $pdf->GetY(); $h = 48;
    foreach ($rows as $i => $sig) {
        $x = 14 + $i * ($w+$gap);
        $pdf->SetDrawColor(203,213,225);
        $pdf->SetFillColor(248,250,252);
        $pdf->RoundedRect($x,$y,$w,$h,2.5,'1111','DF');
        $pdf->SetFillColor(...$pdf->companyPdfRgb($pdf->companyPdfColors()['accent']));
        $pdf->Rect($x,$y,$w,1.8,'F');
        $pdf->SetXY($x+3,$y+4);
        $pdf->SetFont('dejavusans','B',7.6);
        $pdf->SetTextColor(71,85,105);
        $pdf->MultiCell($w-6,4.2,mb_strtoupper((string)$sig['role'],'UTF-8'),0,'C',false,1);
        $pdf->SetX($x+3);
        $pdf->SetFont('dejavusans','B',8.3);
        $pdf->SetTextColor(15,23,42);
        $pdf->MultiCell($w-6,6,(string)$sig['name'],0,'C',false,1);
        $path = trim((string)($sig['path'] ?? ''));
        if ($path !== '' && is_file($path)) {
            try { $pdf->Image($path,$x+($w-30)/2,$y+18,30,12,'','','',false,300,'C',false,false,0,true); } catch (Throwable $ignored) {}
        } else {
            $pdf->SetXY($x+3,$y+21);
            $pdf->SetFont('dejavusans','I',7.2);
            $pdf->SetTextColor(148,163,184);
            $pdf->Cell($w-6,4.5,'Signature en attente',0,1,'C');
        }
        $pdf->SetXY($x+3,$y+35);
        $pdf->SetFont('dejavusans','',6.5);
        $pdf->SetTextColor(100,116,139);
        $pdf->MultiCell($w-6,8,(string)$sig['note'],0,'C',false,1);
    }
    $pdf->SetY($y+$h+2);
}

$pdo = \Database::getConnection();
$manager = new \EmployeeLegalRequestManager($pdo);
$id = filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT) ?: 0;
if ($id < 1) { http_response_code(400); exit('ID invalide.'); }
$request = $manager->getRequestById($id);
if (!$request) { http_response_code(404); exit('Demande introuvable.'); }
if (($request['assigned_team'] ?? '') !== 'rh') { http_response_code(403); exit('Accès refusé.'); }

$type = (string)($request['request_type'] ?? '');
$payload = (array)($request['payload'] ?? []);
$typeLabel = (string)($request['type_label'] ?? $type);
$status = statusLabel($request);
foreach (['employee','rh','drh','dg'] as $role) {
    $request[$role.'_signature_abs_path'] = $manager->getRequestSignatureAbsolutePath($id,$role) ?? '';
}

$companyService = new \RhCompanyProfileService($pdo);
$companies = $companyService->all(true);
$company = null;
foreach ($companies as $candidate) {
    $haystack = mb_strtoupper(implode(' ',[(string)($candidate['legal_name']??''),(string)($candidate['trade_name']??'')]),'UTF-8');
    if (str_contains($haystack,'BANAMUR')) { $company = $candidate; break; }
}
if (!$company) $company = $companyService->defaultCompany();
if (!$company) $company = ['legal_name'=>'BANAMUR INDUSTRIES ET TECHNOLOGIES','trade_name'=>'BANAMUR'];

// Logo officiel BANAMUR : source unique = branding global FINEA/BANAMUR.
// Aucun fallback vers Veritas n'est autorisé pour les documents RH.
$branding = \CompanyBranding::get($pdo);
$brandingLogo = \CompanyBranding::logoFile($pdo);
$logoPath = ($brandingLogo !== '' && is_file($brandingLogo)) ? $brandingLogo : '';
if ($logoPath === '') {
    $root = dirname(__DIR__);
    $fallbackBanamur = $root.'/logo/default_logo.jpg';
    if (is_file($fallbackBanamur)) $logoPath = $fallbackBanamur;
}

// Le document RH reste juridiquement attaché au profil RH BANAMUR,
// mais le logo/charte viennent du branding officiel administré.
if (!empty($branding['company_name']) && empty($company['trade_name'])) {
    $company['trade_name'] = (string)$branding['company_name'];
}

$code = 'RH-'.str_pad((string)$id,6,'0',STR_PAD_LEFT);
$pdf = new RhRequestPdf('P','mm','A4',true,'UTF-8',false);
$pdf->company = $company;
$pdf->logo = $logoPath;
$pdf->documentTitle = mb_strtoupper($typeLabel,'UTF-8');
$pdf->documentCode = $code;
$pdf->issueDate = date('d/m/Y');
$pdf->companyPdfTitle='Fiche de demande RH';
$pdf->companyPdfType=$typeLabel;
$pdf->companyPdfId=$code;
$pdf->companyPdfCode='RH-DEM-FICHE-01';
$pdf->companyPdfQrPayload=$pdf->companyPdfUrl('request/export_demande_rh_fiche_pdf.php?id='.$id);
$pdf->removeGeneratorCredit();
$pdf->SetCreator('FINEA RH');
$pdf->SetAuthor((string)($company['legal_name'] ?? 'BANAMUR'));
$pdf->SetTitle($typeLabel.' - '.$code);
$pdf->SetSubject('Demande RH maîtrisée');
$pdf->SetKeywords('FINEA, BANAMUR, RH, '.$typeLabel.', '.$code);
$pdf->SetMargins(14,42,14);
$pdf->SetHeaderMargin(0);
$pdf->SetFooterMargin(9);
$pdf->SetAutoPageBreak(true,28);
$pdf->AddPage();

[$statusFill,$statusText] = statusColors($status);
$verifyPayload = implode('|',[
    'FINEA-RH-REQUEST',
    (string)$id,
    $code,
    $type,
    (string)($request['submitted_at'] ?? ''),
    hash('sha256',$id.'|'.$type.'|'.json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))
]);
$pdf->SetFillColor(248,250,252);
$pdf->SetDrawColor(226,232,240);
$pdf->RoundedRect(14,44,182,31,3,'1111','DF');
$pdf->SetXY(19,49);
$pdf->SetTextColor(15,23,42);
$pdf->SetFont('dejavusans','B',9.4);
$pdf->Cell(122,5,'FICHE DE DEMANDE RH • DOCUMENT CONTRÔLÉ',0,1,'L');
$pdf->SetX(19);
$pdf->SetFont('dejavusans','',7.4);
$pdf->SetTextColor(71,85,105);
$pdf->MultiCell(121,4.2,'Demandeur : '.((string)($request['requester_name'] ?? '—'))."\nSoumise le : ".fmtDateTime($request['submitted_at'] ?? '')."\nRéférence interne : ".$code,0,'L');
$pdf->SetFillColor(...$statusFill);
$pdf->RoundedRect(144,49,27,8,4,'1111','F');
$pdf->SetXY(144,51);
$pdf->SetFont('dejavusans','B',7.2);
$pdf->SetTextColor(...$statusText);
$pdf->Cell(27,4,$status,0,1,'C');
$pdf->SetXY(174,66.2);
$pdf->SetFont('dejavusans','I',5.6);
$pdf->SetTextColor(100,116,139);
$pdf->Cell(19,3,'QR dans l’en-tête',0,1,'C');
$pdf->SetY(80);

if ($type === 'recrutement') {
    sectionTitle($pdf,'1','Identification de la demande');
    infoGrid($pdf,[
        ['Demandeur',$request['requester_name'] ?? '—'],
        ['Service demandeur',$payload['service_demandeur'] ?? $payload['service'] ?? '—'],
        ['Poste recherché',$payload['poste_recherche'] ?? $payload['poste'] ?? '—'],
        ['Nombre de postes',$payload['nombre_postes'] ?? $payload['nombre'] ?? '—'],
        ['Type de contrat',$payload['type_contrat'] ?? '—'],
        ['Prise de poste souhaitée',fmtDate($payload['date_souhaitee'] ?? $payload['date_prise_poste'] ?? '')],
    ],['Poste recherché','Type de contrat','Prise de poste souhaitée']);

    sectionTitle($pdf,'2','Justification du recrutement');
    textBlock($pdf,(string)($payload['motif_recrutement'] ?? $payload['motif'] ?? ''));

    sectionTitle($pdf,'3','Profil et compétences recherchés');
    textBlock($pdf,(string)($payload['profil_recherche'] ?? $payload['profil_competences'] ?? $payload['profil'] ?? ''));

    sectionTitle($pdf,'4','Analyse financière');
    $entrant = (float)($payload['impact_financier_entrant'] ?? 0);
    $sortant = (float)($payload['impact_financier_sortant'] ?? 0);
    $net = $entrant - $sortant;
    infoGrid($pdf,[
        ['Impact entrant estimé',money($entrant)],
        ['Impact sortant estimé',money($sortant)],
        ['Impact net estimé',money($net)],
    ],['Impact sortant estimé','Impact net estimé']);

    sectionTitle($pdf,'5','Valeur ajoutée attendue');
    textBlock($pdf,(string)($payload['valeur_ajoutee'] ?? ''));
} else {
    sectionTitle($pdf,'1','Identification de la demande');
    infoGrid($pdf,[
        ['Demandeur',$request['requester_name'] ?? '—'],
        ['Nature de la demande',$typeLabel],
        ['Date de soumission',fmtDateTime($request['submitted_at'] ?? '')],
        ['Statut',$status],
    ],['Nature de la demande','Statut']);

    sectionTitle($pdf,'2','Données de la demande');
    $labels = [
        'nom'=>'Nom','prenoms'=>'Prénoms','matricule'=>'Matricule','fonction'=>'Fonction','service'=>'Service',
        'date_depart'=>'Date de départ','date_retour'=>'Date de retour','date_debut'=>'Date de début','date_fin'=>'Date de fin',
        'nombre_jours'=>'Nombre de jours','date_retard'=>'Date du retard','heure_arrivee'=>'Heure d’arrivée','duree_retard'=>'Durée du retard',
        'designation_pret'=>'Désignation du prêt','montant_demande'=>'Montant demandé','montant_recouvrement'=>'Recouvrement mensuel',
        'nombre_mensualites'=>'Nombre de mensualités','montant'=>'Montant','heures'=>'Heures','motif'=>'Motif',
        'objet_reclamation_label'=>'Objet','mois_concerne'=>'Mois concerné','montant_manquant'=>'Montant manquant','details_differend'=>'Détails du différend',
        'raison'=>'Raison','commentaire'=>'Commentaire','description'=>'Description','observation'=>'Observation','objet'=>'Objet'
    ];
    $items = []; $long = [];
    foreach ($payload as $key => $value) {
        if (is_array($value) || is_object($value) || $value === null || trim((string)$value) === '') continue;
        $label = $labels[$key] ?? ucfirst(str_replace('_',' ',(string)$key));
        $v = (string)$value;
        if (str_contains((string)$key,'montant') && is_numeric($value)) $v = money($value);
        if (strlen($v) > 105 || in_array((string)$key,['motif','description','commentaire','details_differend','observation'],true)) {
            $long[] = [$label,$v];
        } else {
            $items[] = [$label,$v];
        }
    }
    if ($items) infoGrid($pdf,$items,['Montant demandé','Montant manquant','Recouvrement mensuel','Statut']);
    foreach ($long as [$label,$value]) {
        sectionTitle($pdf,'',$label);
        textBlock($pdf,$value,in_array(mb_strtolower($label,'UTF-8'),['motif','détails du différend'],true));
    }
}

$signatureSection = $type === 'recrutement' ? '6' : '3';
sectionTitle($pdf,$signatureSection,'Circuit d’approbation et signatures');
$rows = [[
    'role'=>'Demandeur',
    'name'=>(string)($request['requester_name'] ?? '—'),
    'note'=>!empty($request['employee_signed_at']) ? 'Signé le '.fmtDateTime($request['employee_signed_at']) : 'Signature à confirmer',
    'path'=>$request['employee_signature_abs_path']
]];
$rows[] = [
    'role'=>'Ressources humaines',
    'name'=>(string)(($request['rh_signed_by_name'] ?? '') ?: ($request['rh_approved_by_name'] ?? 'À compléter')),
    'note'=>!empty($request['rh_signed_at']) ? 'Signé le '.fmtDateTime($request['rh_signed_at']) : 'Visa RH en attente',
    'path'=>$request['rh_signature_abs_path']
];
if ($type === 'reclamation_salariale') {
    $rows[] = [
        'role'=>'DRH',
        'name'=>(string)(($request['drh_signed_by_name'] ?? '') ?: ($request['drh_approved_by_name'] ?? 'À compléter')),
        'note'=>!empty($request['drh_signed_at']) ? 'Signé le '.fmtDateTime($request['drh_signed_at']) : 'Visa DRH en attente',
        'path'=>$request['drh_signature_abs_path']
    ];
}
$rows[] = [
    'role'=>'Direction générale',
    'name'=>(string)(($request['dg_signed_by_name'] ?? '') ?: ($request['dg_approved_by_name'] ?? 'À compléter')),
    'note'=>!empty($request['dg_signed_at']) ? 'Signé le '.fmtDateTime($request['dg_signed_at']) : 'Visa DG en attente',
    'path'=>$request['dg_signature_abs_path']
];
signatureCards($pdf,$rows);

if (!empty($request['decision_note'])) {
    sectionTitle($pdf,$type === 'recrutement' ? '7' : '4','Décision / observations');
    textBlock($pdf,(string)$request['decision_note'],true);
}

$pdf->ensureSpace(20);
$pdf->Ln(3);
$pdf->SetFillColor(255,251,235);
$pdf->SetDrawColor(...$pdf->companyPdfRgb($pdf->companyPdfColors()['accent']));
$pdf->RoundedRect(14,$pdf->GetY(),182,15,2,'1111','DF');
$pdf->SetXY(18,$pdf->GetY()+3);
$pdf->SetFont('dejavusans','I',6.7);
$pdf->SetTextColor(71,85,105);
$pdf->MultiCell(174,4,'Document généré et tracé par FINEA. Les signatures professionnelles affichées sont celles enregistrées dans le système. Lorsqu’une Passkey est configurée, la validation est précédée d’une authentification WebAuthn explicite. Le QR code ouvre le dossier dans FINEA, après contrôle des accès.',0,'L');

$pdf->Output('demande_rh_'.$id.'_'.date('Ymd_His').'.pdf','D');
