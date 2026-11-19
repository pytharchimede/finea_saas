<?php
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../model/CompanyPdfTemplate.php';
require_once __DIR__ . '/../vendor/autoload.php';

$id = (int)($_GET['id'] ?? 0);
$contract = $contractManager->get($id);
if (!$contract) { http_response_code(404); exit('Contrat introuvable.'); }
if ($contract['statut'] !== 'actif') { http_response_code(409); exit('Le BCI est disponible après signature complète du contrat.'); }

class ContractBciPdf extends TCPDF { use CompanyPdfTemplate; public function Header(): void { $this->drawCompanyPdfHeader(); } public function Footer(): void { $this->drawCompanyPdfFooter(); } }
$pdf = new ContractBciPdf('P', 'mm', 'A4', true, 'UTF-8', false);
$bciNumber = 'BCI-' . $contract['reference'];
$pdf->companyPdfTitle = 'Bon de commande interne';
$pdf->companyPdfType = 'BCI issu d’un contrat de sous-traitance';
$pdf->companyPdfId = $bciNumber;
$pdf->companyPdfCode = 'FIN-CST-BCI';
$pdf->companyPdfQrPayload = json_encode(['bci' => $bciNumber, 'contrat' => $contract['reference'], 'chantier' => $contract['chantier_code']], JSON_UNESCAPED_UNICODE);
$pdf->SetCreator('Finéa'); $pdf->SetTitle($bciNumber); $pdf->SetMargins(16, 40, 16); $pdf->SetAutoPageBreak(true, 28); $pdf->AddPage();
$e = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$money = static fn($value) => number_format((float)$value, 0, ',', ' ') . ' FCFA';
$rows = '';
foreach ($contract['lignes'] as $index => $line) {
    $rows .= '<tr><td width="8%" align="center">' . ($index + 1) . '</td><td width="52%"><b>' . $e($line['designation']) . '</b></td><td width="14%" align="center">' . $e($line['quantite'] . ' ' . ($line['unite'] ?: '')) . '</td><td width="13%" align="center">' . number_format((float)$line['poids'], 2, ',', ' ') . ' %</td><td width="13%" align="right">' . $money($line['montant_contractuel']) . '</td></tr>';
}
$advance = (float)$contract['montant_acompte'];
$html = '<style>h1{font-size:16px;text-align:center;color:#050505}h2{font-size:11px;color:#050505;border-bottom:2px solid #e0a800;padding-bottom:4px}p,td{font-size:9px;line-height:1.45}.box{border:1px solid #cbd5e1;background:#f8fafc;padding:8px}table.grid td{border:1px solid #cbd5e1;padding:6px}.head td{background:#050505;color:#fff;font-weight:bold}</style>'
    . '<h1>BON DE COMMANDE INTERNE</h1><p align="center"><b>N° ' . $e($bciNumber) . '</b> · Rattaché au contrat ' . $e($contract['reference']) . '</p>'
    . '<table class="grid" cellpadding="4"><tr><td width="50%"><b>CHANTIER</b><br>' . $e($contract['chantier_code'] . ' · ' . $contract['chantier_titre']) . '</td><td width="50%"><b>SOUS-TRAITANT</b><br>' . $e($contract['fournisseur_nom']) . '</td></tr><tr><td><b>OBJET</b><br>' . $e($contract['objet']) . '</td><td><b>PÉRIODE D’EXÉCUTION</b><br>Du ' . ($contract['date_debut'] ? date('d/m/Y', strtotime($contract['date_debut'])) : '—') . ' au ' . ($contract['date_fin'] ? date('d/m/Y', strtotime($contract['date_fin'])) : '—') . '</td></tr></table>'
    . '<h2>PRESTATIONS COMMANDÉES</h2><table class="grid" cellpadding="4"><tr class="head"><td width="8%">N°</td><td width="52%">POSTE / PRESTATION</td><td width="14%">QTÉ</td><td width="13%">POIDS</td><td width="13%">MONTANT</td></tr>' . $rows . '<tr class="head"><td colspan="4" align="right">TOTAL COMMANDÉ</td><td align="right">' . $money($contract['montant_ht']) . '</td></tr></table>'
    . '<h2>CONDITIONS OPÉRATIONNELLES</h2><p class="box">Le présent BCI ordonne uniquement l’exécution des prestations listées ci-dessus. Il ne remplace pas le contrat de sous-traitance, qui demeure le document juridique de référence. Acompte convenu : <b>' . number_format((float)$contract['taux_acompte'], 2, ',', ' ') . ' %</b>' . ($advance > 0 ? ', soit <b>' . $money($advance) . '</b>' : ' (aucun acompte)') . '. Les paiements sont établis sur les avancements réellement réceptionnés par poste.</p>'
    . '<br><table cellpadding="8"><tr><td width="48%" align="center"><b>POUR BANAMUR</b><br><br><br>Nom, qualité, signature</td><td width="4%"></td><td width="48%" align="center"><b>RÉCEPTION PAR LE SOUS-TRAITANT</b><br><br><br>' . $e($contract['fournisseur_nom']) . '<br>Nom, qualité, signature</td></tr></table>';
$pdf->SetFont('dejavusans', '', 9); $pdf->writeHTML($html, true, false, true, false, '');
while (ob_get_level()) ob_end_clean(); $pdf->Output('BCI_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $bciNumber) . '.pdf', 'I');
