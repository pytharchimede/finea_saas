<?php
require_once __DIR__.'/../auth_check.php';
AccessManager::requirePageAccess('liste_debourse.php');
require_once __DIR__.'/../debourse/controllers/DebourseDashboardController.php';
require_once __DIR__.'/../model/CompanyExportBranding.php';
require_once __DIR__.'/../model/EnvConfig.php';
require_once '../vendor/autoload.php';
require_once '../model/Database.php';
require_once '../model/AuditTrail.php';
require_once '../model/Devis.php';

$chantierId = $_GET['chantier_id'] ?? null;
$search = $_GET['search'] ?? null;
$client = $_GET['client'] ?? null;

$chantierId = ($chantierId !== null && trim((string)$chantierId) !== '') ? $chantierId : null;
$search = ($search !== null && trim((string)$search) !== '') ? trim((string)$search) : null;
$client = ($client !== null && trim((string)$client) !== '') ? trim((string)$client) : null;

$pdo = Database::getConnection();
$devisModel = new Devis($pdo);
$debourses = (new \Debourse\Controllers\DebourseDashboardController($pdo))->listing($chantierId===null?null:(int)$chantierId,(string)$search,(string)$client)['rows'] ?? [];

$primary=CompanyBranding::palette()['primary'];
$foreground=CompanyBranding::contrastColor($primary);
require_once __DIR__.'/../model/CompanyPdfTemplate.php';
class DebourseListPdf extends TCPDF { use CompanyPdfTemplate; public function Header(): void {$this->drawCompanyPdfHeader();} public function Footer(): void {$this->drawCompanyPdfFooter();} }
$pdf = new DebourseListPdf('L', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator(CompanyExportBranding::creator());
$pdf->SetAuthor(CompanyExportBranding::creator());
$pdf->SetTitle('Liste des déboursés');
$pdf->companyPdfTitle='Liste des déboursés';
$pdf->companyPdfType='Rapport financier';
$pdf->companyPdfShowQr=false;
$pdf->SetMargins(11, 39, 11);
$pdf->SetAutoPageBreak(true, 25);
$pdf->AddPage();

$pdf->SetFont('helvetica', 'B', 14);
$pdf->Cell(0, 8, 'Liste des déboursés', 0, 1, 'L');

$pdf->SetFont('helvetica', '', 9);
$filters = [];
if ($chantierId !== null) {
  $filters[] = 'Chantier ID: ' . $chantierId;
}
if ($client !== null) {
  $filters[] = 'Client: ' . $client;
}
if ($search !== null) {
  $filters[] = 'Recherche: ' . $search;
}
$pdf->MultiCell(0, 5, $filters ? ('Filtres: ' . implode(' | ', $filters)) : 'Filtres: (aucun)', 0, 'L', false, 1);
$pdf->Ln(2);

$total = 0.0;
$rowsHtml = '';
foreach ($debourses as $d) {
  $montant = (float)($d['montant_debourse'] ?? 0);
  $total += $montant;

  $id = (int)($d['id'] ?? 0);
  $numeroDevis = htmlspecialchars((string)($d['numero_devis'] ?? ''), ENT_QUOTES, 'UTF-8');
  $designation = htmlspecialchars((string)($d['designation'] ?? ''), ENT_QUOTES, 'UTF-8');
  $chantier = htmlspecialchars((string)($d['titre'] ?? ''), ENT_QUOTES, 'UTF-8');
  $nomClient = htmlspecialchars((string)($d['nom_client'] ?? ''), ENT_QUOTES, 'UTF-8');
  $dateDebut = htmlspecialchars((string)($d['date_debut'] ?? ''), ENT_QUOTES, 'UTF-8');
  $dateFin = htmlspecialchars((string)($d['date_fin'] ?? ''), ENT_QUOTES, 'UTF-8');

  $rowsHtml .= '<tr>';
  $rowsHtml .= '<td style="width:4%;text-align:right;">' . $id . '</td>';
  $rowsHtml .= '<td style="width:9%;">' . $numeroDevis . '</td>';
  $rowsHtml .= '<td style="width:24%;">' . $designation . '</td>';
  $rowsHtml .= '<td style="width:18%;">' . $chantier . '</td>';
  $rowsHtml .= '<td style="width:18%;">' . $nomClient . '</td>';
  $rowsHtml .= '<td style="width:9%;">' . $dateDebut . '</td>';
  $rowsHtml .= '<td style="width:9%;">' . $dateFin . '</td>';
  $rowsHtml .= '<td style="width:9%;text-align:right;">' . number_format($montant, 0, ',', ' ') . '</td>';
  $rowsHtml .= '</tr>';
}

$html = '
<style>
  table { border-collapse: collapse; width: 100%; }
  th, td { border: 1px solid #bfc7d1; padding: 4px; }
  th { background-color:' . $primary . '; color:' . $foreground . '; font-weight:bold; }
</style>
<table>
  <thead>
    <tr>
      <th style="width:4%;">ID</th>
      <th style="width:9%;">Devis</th>
      <th style="width:24%;">Désignation</th>
      <th style="width:18%;">Chantier</th>
      <th style="width:18%;">Client</th>
      <th style="width:9%;">Début</th>
      <th style="width:9%;">Fin</th>
      <th style="width:9%;text-align:right;">Montant</th>
    </tr>
  </thead>
  <tbody>
    ' . $rowsHtml . '
    <tr>
      <td colspan="7" style="text-align:right;font-weight:bold;">TOTAL</td>
      <td style="text-align:right;font-weight:bold;">' . number_format($total, 0, ',', ' ') . '</td>
    </tr>
  </tbody>
</table>
';

$pdf->writeHTML($html, true, false, true, false, '');

$fileName = 'debourses_liste_' . date('Ymd_His') . '.pdf';
\AuditTrail::logFileEvent('export', [
  'module' => 'decaissement',
  'action' => 'debourse_list_export',
  'entity_type' => 'debourse_list',
  'entity_label' => 'Liste des déboursés',
  'format' => 'pdf',
  'details' => [
    'chantier_id' => $chantierId,
    'search' => $search,
    'client' => $client,
    'row_count' => is_countable($debourses ?? null) ? count($debourses) : null,
    'total' => $total,
  ],
], $pdo);
$pdf->Output($fileName, 'D');
exit;
