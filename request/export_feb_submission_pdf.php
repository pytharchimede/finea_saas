<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/CompanyPdfTemplate.php';
require_once __DIR__ . '/../model/EmployeeLegalRequestManager.php';
require_once __DIR__ . '/../model/FebValidationPolicy.php';
require_once __DIR__ . '/../employe/includes/helpers.php';

$isSignedRh = false;
$isDraftPreview = $_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['draft_preview'] ?? '') === '1';
if ($isDraftPreview) {
    $token = (string)($_POST['_csrf'] ?? '');
    if ($token === '' || !hash_equals((string)($_SESSION['feb_preview_csrf'] ?? ''), $token)) { http_response_code(403); exit('Jeton de prévisualisation invalide.'); }
    $raw = (string)($_POST['payload_json'] ?? '');
    if (strlen($raw) > 100000) { http_response_code(413); exit('Aperçu trop volumineux.'); }
    $payload = json_decode($raw, true);
    if (!is_array($payload) || !is_array($payload['besoins'] ?? null)) { http_response_code(400); exit('Données de prévisualisation invalides.'); }
    if (count($payload['besoins']) > 100) { http_response_code(413); exit('Trop de besoins à prévisualiser.'); }
    foreach ($payload['besoins'] as $need) {
        if (!is_array($need) || strlen((string)($need['libelle'] ?? '')) > 1000 || !is_array($need['fournisseur'] ?? null)) { http_response_code(400); exit('Ligne de besoin invalide.'); }
    }
    $requestId = 0;
    $request = ['request_type'=>'feb', 'requester_name'=>trim((string)($_SESSION['user_name'] ?? employeeCurrentUserName())), 'status'=>'draft'];
} else {
    $pdo = \Database::getConnection();
    $manager = new \EmployeeLegalRequestManager($pdo);
    $requestId = max(0, (int) ($_GET['id'] ?? 0));
    $request = $requestId > 0 ? $manager->getRequestById($requestId) : null;
    if (!$request || (string) ($request['request_type'] ?? '') !== 'feb') { http_response_code(404); exit('FEB introuvable.'); }
    $actorId = (int) ($_SESSION['user_id'] ?? 0);
    $isOwner = $actorId > 0 && (int) ($request['requester_user_id'] ?? 0) === $actorId;
    $profile = employeeFindPersonnelProfile($pdo, (string) ($_SESSION['mail_pro'] ?? ''));
    $actorMatricule = strtoupper(trim((string) ($profile['matricule'] ?? '')));
    $payload = is_array($request['payload'] ?? null) ? (array) $request['payload'] : [];
    $payloadMatricule = strtoupper(trim((string) ($payload['matricule'] ?? '')));
    $sameMatricule = $actorMatricule !== '' && $actorMatricule === $payloadMatricule;
    $canFinance = false;
    if ((hasPermission('acces_feb') || hasPermission('acces_admin')) && $actorId > 0) {
        $servicePermissions = \FebValidationPolicy::getUserServicePermissions($pdo, $actorId);
        $canFinance = \FebValidationPolicy::canValidateService($servicePermissions, \FebValidationPolicy::requestServiceId($request));
    }
    if (!$isOwner && !$sameMatricule && !$canFinance) { http_response_code(403); exit('Accès refusé.'); }
    if (!empty($payload['rh_financial_point_id'])) {
        require_once __DIR__.'/../services/RhFinancialFebDocumentService.php';
        try {(new RhFinancialFebDocumentService($pdo))->file((int)$payload['rh_financial_point_id'],(int)($payload['rh_financial_revision'] ?? 0),$actorId,true);$isSignedRh=true;}
        catch(Throwable $e) {http_response_code(403);exit('Le point RH signé ne correspond plus à cette FEB.');}
    }
}

require_once __DIR__ . '/../services/FebSubmissionPdfBuilder.php';
$pdf = FebSubmissionPdfBuilder::build($request, $payload, $requestId, $isDraftPreview, $isSignedRh);
$pdf->Output($isDraftPreview ? 'FEB-apercu-non-soumis.pdf' : 'FEB-' . $requestId . '-fiche-expression-besoin.pdf', 'I');
exit;

function feb_pdf_h($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function feb_pdf_money($value): string { return number_format((float) $value, 0, ',', ' ') . ' FCFA'; }
function feb_pdf_date($value): string { $value = trim((string) $value); if ($value === '') return '-'; try { return (new DateTimeImmutable($value))->format('d/m/Y'); } catch (Throwable $e) { return $value; } }

$needs = array_values(array_filter((array) ($payload['besoins'] ?? []), 'is_array'));
$rows = '';
$total = 0.0;
foreach ($needs as $index => $need) {
    $supplier = is_array($need['fournisseur'] ?? null) ? (array) $need['fournisseur'] : [];
    $quantity = (float) ($need['quantite'] ?? 0);
    $unitPrice = (float) ($supplier['prix_unitaire'] ?? 0);
    $lineTotal = $quantity * $unitPrice;
    $total += $lineTotal;
    $proof = is_array($need['justificatif_devis'] ?? null) ? (array) $need['justificatif_devis'] : [];
    $rows .= '<tr><td class="center">' . ($index + 1) . '</td><td>' . feb_pdf_h($need['libelle'] ?? '') . '<br><span class="muted">' . feb_pdf_h($need['categorie'] ?? '') . ' · ' . feb_pdf_h($need['type'] ?? '') . '</span></td><td class="center">' . feb_pdf_h($quantity) . '</td><td>' . feb_pdf_h($supplier['nom'] ?? '') . '</td><td class="right">' . feb_pdf_money($unitPrice) . '</td><td class="right">' . feb_pdf_money($lineTotal) . '</td><td class="center">' . (!empty($proof) ? 'Oui' : 'Non') . '</td></tr>';
}
if ($rows === '') $rows = '<tr><td colspan="7" class="center">Aucun besoin renseigné.</td></tr>';

$status = strtolower(trim((string) ($request['status'] ?? 'submitted')));
$statusLabel = ['draft'=>'Brouillon','submitted'=>'En attente','approved'=>'Approuvée','rejected'=>'Refusée','cancelled'=>'Annulée','details_requested'=>'Compléments demandés'][$status] ?? $status;
$budgetLabel = (string) ($payload['budget_planned_label'] ?? (($payload['budget_planned'] ?? '') === 'yes' ? 'Prévue au déboursé' : 'Non prévue au déboursé'));
$draft = is_array($payload['provisional_decaissement'] ?? null) ? (array)$payload['provisional_decaissement'] : [];
$object = trim((string)($payload['feb_quote_title'] ?? $payload['description'] ?? ''));
$paymentMode = trim((string)($draft['mode_paiement'] ?? ''));
$details = trim((string)($draft['details_demande'] ?? $object));

$html = '<style>body{font-family:dejavusans;color:#172033;font-size:9px}.muted{color:#64748b;font-size:8px}.hero{border:1px solid #dbe3f0;background:#f8fafc;padding:12px;margin-bottom:10px}.hero-kicker{font-size:7px;color:#64748b;text-transform:uppercase}.hero-title{font-size:14px;font-weight:bold;color:#0f172a}.hero-amount{font-size:16px;font-weight:bold;color:#000}.section{margin-top:14px;font-size:11px;font-weight:bold;color:#000;border-bottom:2px solid #fabd02;padding-bottom:4px}.grid{width:100%;margin-top:7px}.grid td{padding:5px;border:1px solid #e2e8f0}.label{font-size:7px;color:#64748b;text-transform:uppercase}.value{font-size:9px;font-weight:bold}table.lines{width:100%;border-collapse:collapse;margin-top:7px}.lines th{background:#fabd02;color:#000;padding:6px;font-size:7px}.lines td{border:1px solid #dbe3f0;padding:5px}.right{text-align:right}.center{text-align:center}.note{margin-top:14px;padding:9px;border:1px solid #f4d35e;background:#fffbeb;color:#78350f}</style>'
. '<div class="hero"><div class="hero-kicker">FEB · Projet</div><div class="hero-title">' . feb_pdf_h($object) . '</div><div class="hero-amount">' . feb_pdf_money($total ?: ($payload['montant'] ?? 0)) . '</div></div>'
. '<div class="section">Informations générales</div><table class="grid"><tr><td><div class="label">Demandeur</div><div class="value">' . feb_pdf_h($request['requester_name'] ?? $payload['nom'] ?? '') . '</div></td><td><div class="label">Service bénéficiaire</div><div class="value">' . feb_pdf_h($payload['departement_label'] ?? '') . '</div></td><td><div class="label">Statut</div><div class="value">' . feb_pdf_h($statusLabel) . '</div></td></tr><tr><td><div class="label">Position budgétaire</div><div class="value">' . feb_pdf_h($budgetLabel) . '</div></td><td><div class="label">Mode de paiement</div><div class="value">' . feb_pdf_h($paymentMode !== '' ? $paymentMode : '-') . '</div></td><td><div class="label">Montant total</div><div class="value">' . feb_pdf_money($total ?: ($payload['montant'] ?? 0)) . '</div></td></tr></table>'
. '<div class="section">Besoins exprimés</div><table class="lines"><thead><tr><th width="5%">N°</th><th width="28%">Besoin</th><th width="8%">Qté</th><th width="20%">Fournisseur</th><th width="13%">PU</th><th width="15%">Total</th><th width="11%">Justif.</th></tr></thead><tbody>' . $rows . '</tbody></table>'
. '<div class="section">Affectation et planning</div><table class="grid"><tr><td><div class="label">Début prévu</div><div class="value">' . feb_pdf_date($payload['planned_start_date'] ?? '') . '</div></td><td><div class="label">Fin prévue</div><div class="value">' . feb_pdf_date($payload['planned_end_date'] ?? '') . '</div></td><td><div class="label">Livraison souhaitée</div><div class="value">' . feb_pdf_date($payload['date_livraison'] ?? '') . '</div></td></tr></table>'
. '<div class="section">Détails fiche</div><p>' . nl2br(feb_pdf_h($details)) . '</p><div class="note"><strong>Document maîtrisé.</strong> Export officiel FINEA conforme au gabarit documentaire BANAMUR. Le QR code permet d’identifier la FEB et son état au moment de l’édition.</div>';

class FebSubmissionPdf extends \TCPDF {
    use CompanyPdfTemplate;
    public function Header(): void {
        $this->SetAlpha(0.035);
        $this->SetTextColor(15, 23, 42);
        $this->SetFont('dejavusans', 'B', 40);
        $this->StartTransform();
        $this->Rotate(325, 105, 150);
        $this->Text(48, 150, 'BANAMUR');
        $this->StopTransform();
        $this->SetAlpha(1);
        $this->drawCompanyPdfHeader();
    }
    public function Footer(): void { $this->drawCompanyPdfFooter(); }
}
$pdf = new FebSubmissionPdf('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->companyPdfTitle = 'FICHE D’EXPRESSION DE BESOIN';
$pdf->companyPdfType = 'FEB · Projet';
$pdf->companyPdfId = $isDraftPreview ? 'APERÇU NON SOUMIS' : 'FEB-' . $requestId;
$pdf->companyPdfCode = 'FIN-FEB-ISO9001';
$pdf->companyPdfQrPayload = json_encode(['type'=>$isDraftPreview?'feb_preview':'feb','id'=>$requestId?:null,'status'=>$status,'object'=>$object,'generated_at'=>date(DATE_ATOM)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$pdf->SetCreator('FINEA');
$pdf->SetAuthor('BANAMUR Industries & Technologies');
$pdf->SetTitle($isDraftPreview ? 'Aperçu FEB avant soumission' : 'Fiche expression de besoin FEB-' . $requestId);
$pdf->SetSubject($object);
$pdf->setPrintHeader(true);
$pdf->setPrintFooter(true);
$pdf->SetMargins(12, 38, 12);
$pdf->SetAutoPageBreak(true, 25);
$pdf->AddPage();
$pdf->writeHTML($html, true, false, true, false, '');
$pdf->Output($isDraftPreview ? 'FEB-apercu-non-soumis.pdf' : 'FEB-' . $requestId . '-fiche-expression-besoin.pdf', 'I');

