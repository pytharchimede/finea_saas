<?php
session_start();
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
$debourses = $devisModel->getDeboursesFiltres($chantierId, $search, $client);

$pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('Banamur');
$pdf->SetAuthor('Banamur');
$pdf->SetTitle('Liste des déboursés');
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 12);
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
  $rowsHtml .= '<td style="width:28px;text-align:right;">' . $id . '</td>';
  $rowsHtml .= '<td style="width:60px;">' . $numeroDevis . '</td>';
  $rowsHtml .= '<td style="width:210px;">' . $designation . '</td>';
  $rowsHtml .= '<td style="width:170px;">' . $chantier . '</td>';
  $rowsHtml .= '<td style="width:170px;">' . $nomClient . '</td>';
  $rowsHtml .= '<td style="width:60px;">' . $dateDebut . '</td>';
  $rowsHtml .= '<td style="width:60px;">' . $dateFin . '</td>';
  $rowsHtml .= '<td style="width:70px;text-align:right;">' . number_format($montant, 0, ',', ' ') . '</td>';
  $rowsHtml .= '</tr>';
}

$html = '
<style>
  table { border-collapse: collapse; width: 100%; }
  th, td { border: 1px solid #bfc7d1; padding: 4px; }
  th { background-color:#fabd02; color:#000000; font-weight:bold; }
</style>
<table>
  <thead>
    <tr>
      <th style="width:28px;">ID</th>
      <th style="width:60px;">Devis</th>
      <th style="width:210px;">Désignation</th>
      <th style="width:170px;">Chantier</th>
      <th style="width:170px;">Client</th>
      <th style="width:60px;">Début</th>
      <th style="width:60px;">Fin</th>
      <th style="width:70px;text-align:right;">Montant</th>
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
