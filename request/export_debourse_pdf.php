<?php
session_start();
require_once('../vendor/autoload.php');
require_once("../model/Utils.php");
require_once("../model/Database.php");
require_once("../model/AuditTrail.php");
require_once("../model/Devis.php");
require_once("../model/Client.php");
require_once("../model/Offre.php");

$devis_id = $_GET['devisId'] ?? $_GET['devis_id'] ?? null;
if (!$devis_id) {
    die("ID devis manquant");
}
$pdo = Database::getConnection();
$devisModel = new Devis($pdo);
$offreModel = new Offre($pdo);

$devis = $devisModel->getDevisById($devis_id);
$debourses = $devisModel->getDeboursesByDevisId($devis_id);
$offre = $offreModel->getOffreById($devis['offre_id'] ?? null);

// Corrige l'ordre inversé: on force un tri stable haut → bas (ligne_devis_id ASC puis id ASC).
if (is_array($debourses) && count($debourses) > 1) {
    usort($debourses, static function ($a, $b) {
        $la = (int)($a['ligne_devis_id'] ?? 0);
        $lb = (int)($b['ligne_devis_id'] ?? 0);
        if ($la !== $lb) return $la <=> $lb;
        return (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0);
    });
}
// Classe PDF personnalisée
class DeboursePDF extends TCPDF
{
    public function Header()
    {
        // Personnalise si besoin
    }
    public function Footer()
    {
        $this->SetY(-15);
        $this->SetFont('helvetica', 'I', 8);
        $this->Cell(0, 10, 'Page ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, 0, 'C');
    }
}

$pdf = new DeboursePDF('L', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 12);
$pdf->AddPage('L');

// Style global (évite les aplats noirs = gaspillage d'encre)
$pdf->SetDrawColor(210, 210, 210);
$pdf->SetLineWidth(0.15);
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('dejavusans', '', 10);

// --- En-tête pro (logo + titre) ---
$numeroDevis = $devis['numero_devis'] ?? '';
$clientLabel = $devis['destine_a'] ?? '';
$logoName = $devis['logo_client'] ?? ($devis['logo'] ?? '');
$logoPath = '';
if ($logoName) {
    $candidate = realpath(__DIR__ . '/../logo/' . $logoName);
    if ($candidate && is_file($candidate)) {
        $logoPath = $candidate;
    }
}

// Réserve une hauteur de logo pour éviter le chevauchement avec le bloc infos
$headerTopY = 10;
$logoH = 18; // mm (hauteur fixe)
if ($logoPath) {
    // Logo à gauche (hauteur fixe)
    $pdf->Image($logoPath, 10, $headerTopY, 0, $logoH, '', '', '', false, 300, '', false, false, 0, false, false, false);
}

$pdf->SetXY($logoPath ? 45 : 10, $headerTopY);
$pdf->SetFont('dejavusans', 'B', 16);
$pdf->Cell(0, 8, "Déboursés du devis", 0, 1, 'L');
$pdf->SetX($logoPath ? 45 : 10);
$pdf->SetFont('dejavusans', '', 11);
$pdf->SetTextColor(70, 70, 70);
$pdf->Cell(0, 6, "N° {$numeroDevis} - {$offre['reference_offre']}", 0, 1, 'L');
$pdf->SetTextColor(0, 0, 0);

// Place le curseur sous le plus bas: logo ou titre
$yAfterTitle = $pdf->GetY();
$yAfterLogo = $headerTopY + ($logoPath ? $logoH : 0);
$pdf->SetY(max($yAfterTitle, $yAfterLogo) + 3);

// --- Bloc infos (style carte légère) ---
$totalLignesDebourse = $devisModel->getTotalDebourseByDevisId($devis_id);
$pdf->SetFillColor(248, 250, 252);
$pdf->SetDrawColor(225, 231, 239);
$pdf->SetFont('dejavusans', '', 10);
$pdf->MultiCell(
    0,
    10,
    "Client : {$clientLabel}\n" .
        "Total TTC : " . number_format($totalLignesDebourse, 0, ',', ' ') . " FCFA",
    1,
    'L',
    true,
    1
);
$pdf->Ln(2);

// CSS minimal supporté par TCPDF (évite noir, améliore lisibilité)
$baseCss = <<<CSS
<style>
  .section-title { background-color:#f1f5f9; color:#0f172a; font-weight:bold; padding:6px 8px; border:1px solid #dbe3ec; }
  table.tbl { width:100%; border-collapse:collapse; }
  table.tbl th { background-color:#f3f4f6; color:#111827; font-weight:bold; padding:6px 6px; border:1px solid #d1d5db; }
  table.tbl td { padding:6px 6px; border:1px solid #e5e7eb; color:#111827; }
  .muted { color:#475569; }
  .right { text-align:right; }
  .center { text-align:center; }
  .total-row td { background-color:#f8fafc; font-weight:bold; }
  .grand-total { background-color:#ecfdf5; border:1px solid #bbf7d0; padding:8px; font-weight:bold; }
</style>
CSS;

$totalGeneral = 0;
foreach ($debourses as $debourse) {
    $designationDebourse = $debourse['designation_ligne'] ?? ($debourse['designation'] ?? 'N/A');
    $pdf->writeHTML($baseCss . '<div class="section-title">Déboursé : ' . htmlspecialchars((string)$designationDebourse, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>', true, false, true, false, '');

    $sousLignes = $devisModel->getLignesDebourseByDebourseId($debourse['id']);
    if (is_array($sousLignes) && count($sousLignes) > 1) {
        usort($sousLignes, static function ($a, $b) {
            return (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0);
        });
    }

    $rowsHtml = '';
    $totalDebourse = 0;
    $rowAlt = false;
    foreach ($sousLignes as $ligne) {
        $rowAlt = !$rowAlt;
        $bg = $rowAlt ? '#ffffff' : '#fbfdff';

        $cat = htmlspecialchars((string)($ligne['categorie'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $des = htmlspecialchars((string)($ligne['designation'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $montant = (float)($ligne['montant'] ?? 0);
        $d1 = htmlspecialchars((string)Utils::dateJourCourtFr($ligne['date_debut'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $d2 = htmlspecialchars((string)Utils::dateJourCourtFr($ligne['date_fin'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $totalDebourse += $montant;
        $totalGeneral += $montant;

        $rowsHtml .= '<tr style="background-color:' . $bg . '">' .
            '<td width="12%">' . $cat . '</td>' .
            '<td width="53%">' . $des . '</td>' .
            '<td width="14%" class="right">' . number_format($montant, 0, ',', ' ') . ' FCFA</td>' .
            '<td width="10%" class="center">' . $d1 . '</td>' .
            '<td width="11%" class="center">' . $d2 . '</td>' .
            '</tr>';
    }

    if ($rowsHtml === '') {
        $rowsHtml = '<tr><td colspan="5" class="muted">Aucune sous-ligne.</td></tr>';
    }

    $tableHtml = $baseCss . '
        <table class="tbl" cellpadding="0" cellspacing="0">
            <thead>
                <tr>
                    <th width="12%">Catégorie</th>
                    <th width="53%">Désignation</th>
                    <th width="14%">Montant</th>
                    <th width="10%">Date début</th>
                    <th width="11%">Date fin</th>
                </tr>
            </thead>
            <tbody>
                ' . $rowsHtml . '
                <tr class="total-row">
                    <td colspan="2" class="right">Total déboursé</td>
                    <td class="right">' . number_format($totalDebourse, 0, ',', ' ') . ' FCFA</td>
                    <td colspan="2"></td>
                </tr>
            </tbody>
        </table>';

    $pdf->writeHTML($tableHtml, true, false, true, false, '');
    $pdf->Ln(2);
}

// Affiche le total général à la fin (carte légère)
$grandTotalHtml = $baseCss . '<div class="grand-total">TOTAL GÉNÉRAL : ' . number_format($totalGeneral, 0, ',', ' ') . ' FCFA</div>';
$pdf->writeHTML($grandTotalHtml, true, false, true, false, '');

ob_clean();
\AuditTrail::logFileEvent('export', [
    'module' => 'decaissement',
    'action' => 'debourse_detail_export',
    'entity_type' => 'devis',
    'entity_id' => isset($devis['id']) ? (string)$devis['id'] : (string)$devis_id,
    'entity_label' => (string)($devis['numero_devis'] ?? 'Déboursé devis'),
    'format' => 'pdf',
    'details' => [
        'offre_reference' => $offre['reference_offre'] ?? null,
        'debourse_count' => is_countable($debourses ?? null) ? count($debourses) : null,
        'total_general' => $totalGeneral,
    ],
], $pdo);
$pdf->Output('debourses_devis_' . $devis['id'] . '.pdf', 'I');
exit();
