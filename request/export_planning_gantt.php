<?php
session_start();
require_once('../vendor/autoload.php');
include_once '../header/header_export_gantt.php';
require_once("../model/Utils.php");
require_once("../model/AuditTrail.php");

function dureeFr($dateDebut, $dateFin)
{
    if (empty($dateDebut) || empty($dateFin)) return '-';
    $start = new DateTime($dateDebut);
    $end = new DateTime($dateFin);
    if ($end < $start) return '-';
    $interval = $start->diff($end);
    $txt = [];
    if ($interval->y) $txt[] = $interval->y . ' an' . ($interval->y > 1 ? 's' : '');
    if ($interval->m) $txt[] = $interval->m . ' mois';
    if ($interval->d) $txt[] = $interval->d . ' jour' . ($interval->d > 1 ? 's' : '');
    if (empty($txt)) $txt[] = '1 jour';
    return implode(' ', $txt);
}

// Classe PDF personnalisée (hérite de TCPDF)
class PlanningPDF extends TCPDF
{
    public function Header()
    {
        $margins = $this->getMargins();
        $xLeft = $margins['left'];
        $xRight = $this->getPageWidth() - $margins['right'];

        $devis = $GLOBALS['devis'] ?? [];
        $client = $GLOBALS['client'] ?? [];
        $offre = $GLOBALS['offre'] ?? [];

        $numeroDevis = $devis['numero_devis'] ?? '';
        $chantier = $offre['reference_offre'] ?? '';
        $clientNom = $client['nom_client'] ?? ($devis['destine_a'] ?? '');

        $logoName = $devis['logo'] ?? 'default_logo.jpg';
        $logoPath = '';
        if (!empty($logoName)) {
            $candidate = realpath(__DIR__ . '/../logo/' . $logoName);
            if ($candidate && is_file($candidate)) {
                $logoPath = $candidate;
            }
        }

        $yTop = 10;
        $logoH = 16;
        if ($logoPath) {
            $this->Image($logoPath, $xLeft, $yTop, 0, $logoH, '', '', '', false, 300, '', false, false, 0, false, false, false);
        }

        $textX = $logoPath ? ($xLeft + 32) : $xLeft;
        $this->SetXY($textX, $yTop);
        $this->SetFont('dejavusans', 'B', 15);
        $this->SetTextColor(15, 23, 42);
        $this->Cell(0, 7, "Planning d'exécution", 0, 1, 'L');

        $metaParts = [];
        if ($numeroDevis) $metaParts[] = "Devis : {$numeroDevis}";
        if ($chantier) $metaParts[] = "Chantier : {$chantier}";
        if ($clientNom) $metaParts[] = "Client : {$clientNom}";
        $metaLine = implode('  •  ', $metaParts);

        $this->SetX($textX);
        $this->SetFont('dejavusans', '', 10);
        $this->SetTextColor(71, 85, 105);
        $this->Cell(0, 6, $metaLine, 0, 1, 'L');

        $yAfterText = $this->GetY();
        $yAfterLogo = $yTop + ($logoPath ? $logoH : 0);
        $ySep = max($yAfterText, $yAfterLogo) + 2;

        $this->SetDrawColor(226, 232, 240);
        $this->SetLineWidth(0.2);
        $this->Line($xLeft, $ySep, $xRight, $ySep);
    }

    public function Footer()
    {
        $margins = $this->getMargins();
        $xLeft = $margins['left'];
        $xRight = $this->getPageWidth() - $margins['right'];

        $this->SetDrawColor(226, 232, 240);
        $this->SetLineWidth(0.2);
        $this->Line($xLeft, $this->getPageHeight() - 16, $xRight, $this->getPageHeight() - 16);

        $this->SetY(-13);
        $this->SetFont('dejavusans', '', 8);
        $this->SetTextColor(100, 116, 139);
        $this->Cell(0, 8, Utils::toMbConvertEncoding('Page ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages()), 0, 0, 'C');
    }
}

function renderPlanningTableHeader(TCPDF $pdf, array $margins, float $pageWidth, float $colNum, float $colTask, float $colStart, float $colEnd, float $colDuration, float $colGantt): void
{
    $pdf->SetFont('dejavusans', 'B', 7);
    $pdf->SetFillColor(241, 245, 249);
    $pdf->SetDrawColor(203, 213, 225);
    $pdf->SetLineWidth(0.15);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->SetX($margins['left']);
    $pdf->Cell($colNum, 8, 'N°', 1, 0, 'C', true);
    $pdf->Cell($colTask, 8, 'Tâche', 1, 0, 'C', true);
    $pdf->Cell($colStart, 8, 'Début', 1, 0, 'C', true);
    $pdf->Cell($colEnd, 8, 'Fin', 1, 0, 'C', true);
    $pdf->Cell($colDuration, 8, 'Durée', 1, 0, 'C', true);
    $pdf->Cell($colGantt, 8, 'Gantt', 1, 1, 'C', true);

    $pdf->SetFont('dejavusans', '', 7);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->SetDrawColor(226, 232, 240);
    $pdf->SetLineWidth(0.15);
}

function renderPlanningScale(TCPDF $pdf, array $margins, float $colNum, float $colTask, float $colStart, float $colEnd, float $colDuration, float $colGantt, ?int $minDate, ?int $maxDate): void
{
    if ($minDate === null || $maxDate === null) {
        return;
    }
    $pdf->SetFont('dejavusans', '', 6);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->SetDrawColor(226, 232, 240);
    $pdf->SetLineWidth(0.2);

    $xBase = $margins['left'] + $colNum + $colTask + $colStart + $colEnd + $colDuration;
    $yBase = $pdf->GetY();
    $nbLabels = 6;
    for ($i = 0; $i <= $nbLabels; $i++) {
        $timestamp = $minDate + ($i * ($maxDate - $minDate) / $nbLabels);
        $label = Utils::dateJourCourtFr(date('Y-m-d', (int)$timestamp));
        $xLabel = $xBase + ($i * $colGantt / $nbLabels);

        $pdf->Line($xLabel, $yBase, $xLabel, $yBase + 6);
        $pdf->SetXY($xLabel - 12, $yBase + 6);
        $pdf->Cell(24, 4, $label, 0, 0, 'C');
    }
    $pdf->SetY($yBase + 12);
    $pdf->SetTextColor(15, 23, 42);
}

// Création du PDF
$pdf = new PlanningPDF('L', 'mm', 'A4', true, 'UTF-8', false); // L = paysage

// Marges: on laisse de la place pour l'en-tête (logo + meta)
$pdf->SetMargins(10, 34, 10);
$pdf->SetAutoPageBreak(true, 16);

// Récupération correcte des marges
$margins = $pdf->getMargins();
$pageWidth = $pdf->getPageWidth() - $margins['left'] - $margins['right'];
$pdf->SetX($margins['left']);

$colNum = 10;
$colTask = 70;
$colStart = 22;
$colEnd = 22;
$colDuration = 14;
$colGantt = $pageWidth - ($colNum + $colTask + $colStart + $colEnd + $colDuration);

// ----------- SECTION PLANNING (GANTT) -----------
$pdf->AddPage('L'); // Paysage
$pdf->SetFont('dejavusans', '', 9);
$pdf->Ln(2);
renderPlanningTableHeader($pdf, $margins, $pageWidth, $colNum, $colTask, $colStart, $colEnd, $colDuration, $colGantt);

// Calcul des bornes du planning
$pos = 1;
$minDate = null;
$maxDate = null;
foreach ($lignes_debourse as $l) {
    if (!empty($l['date_debut']) && !empty($l['date_fin'])) {
        $dateDebut = strtotime($l['date_debut']);
        $dateFin = strtotime($l['date_fin']);
        if ($minDate === null || $dateDebut < $minDate) $minDate = $dateDebut;
        if ($maxDate === null || $dateFin > $maxDate) $maxDate = $dateFin;
    }
}

// Affichage de l'échelle de temps au-dessus du Gantt
if ($minDate === null || $maxDate === null) {
    // Pas de planning, donc pas d'échelle de temps à afficher
    $pdf->Cell($pageWidth, 12, "Aucune donnée de planning disponible.", 1, 1, 'C');
} else {
    renderPlanningScale($pdf, $margins, $colNum, $colTask, $colStart, $colEnd, $colDuration, $colGantt, (int)$minDate, (int)$maxDate);
}

// Affichage du planning
$pos = 1;
if ($minDate === null || $maxDate === null) {
    $pdf->Cell($pageWidth, 12, "Aucune donnée de planning disponible.", 1, 1, 'C');
} else {
    $totalDays = ($maxDate - $minDate) / 86400 + 1;
    $zebra = false;
    foreach ($lignes_debourse as $l) {
        // Empêche les lignes de se couper entre deux pages (sinon on peut perdre du texte sur la dernière ligne)
        $startPage = $pdf->getPage();
        $startY = $pdf->GetY();
        $pdf->startTransaction();

        if (!empty($l['is_titre'])) {
            // Afficher le titre du déboursé sur toute la largeur du tableau
            $pdf->SetFont('dejavusans', 'B', 8);
            $pdf->SetFillColor(236, 253, 245);
            $pdf->SetDrawColor(187, 247, 208);
            $pdf->SetX($margins['left']);
            $pdf->Cell($pageWidth, 8, (string)($l['titre'] ?? ''), 1, 1, 'L', true);
            $pdf->SetFont('dejavusans', '', 7);
            $pdf->SetDrawColor(226, 232, 240);
            $pdf->SetFillColor(255, 255, 255);

            // Si saut de page pendant l'impression, on recommence proprement
            if ($pdf->getPage() !== $startPage) {
                $pdf->rollbackTransaction(true);
                $pdf->AddPage('L');
                $pdf->Ln(2);
                renderPlanningTableHeader($pdf, $margins, $pageWidth, $colNum, $colTask, $colStart, $colEnd, $colDuration, $colGantt);
                renderPlanningScale($pdf, $margins, $colNum, $colTask, $colStart, $colEnd, $colDuration, $colGantt, (int)$minDate, (int)$maxDate);
                $pdf->SetFont('dejavusans', 'B', 8);
                $pdf->SetFillColor(236, 253, 245);
                $pdf->SetDrawColor(187, 247, 208);
                $pdf->SetX($margins['left']);
                $pdf->Cell($pageWidth, 8, (string)($l['titre'] ?? ''), 1, 1, 'L', true);
                $pdf->SetFont('dejavusans', '', 7);
                $pdf->SetDrawColor(226, 232, 240);
                $pdf->SetFillColor(255, 255, 255);
            } else {
                $pdf->commitTransaction();
            }
            continue;
        }

        $zebra = !$zebra;
        $pdf->SetFillColor($zebra ? 248 : 255, $zebra ? 250 : 255, $zebra ? 252 : 255);
        $fillRow = $zebra;

        $designation = (string)($l['designation'] ?? '');
        $dateDebut = !empty($l['date_debut']) ? Utils::dateJourCourtFr($l['date_debut']) : '-';
        $dateFin = !empty($l['date_fin']) ? Utils::dateJourCourtFr($l['date_fin']) : '-';
        $duration = dureeFr($l['date_debut'], $l['date_fin']);

        // Calcul de la hauteur nécessaire pour la cellule tâche (MultiCell)
        $nbLines = $pdf->getNumLines($designation, $colTask);
        $rowHeight = max(8, $nbLines * 4.5);

        $pdf->SetX($margins['left']);
        // N° (numérotation par déboursé)
        $pdf->MultiCell($colNum, $rowHeight, $l['numero'], 1, 'C', $fillRow, 0, '', '', true, 0, false, true, $rowHeight, 'M');
        // Tâche
        $pdf->MultiCell($colTask, $rowHeight, $designation, 1, 'L', $fillRow, 0, '', '', true, 0, false, true, $rowHeight, 'M');
        // Début
        $pdf->MultiCell($colStart, $rowHeight, $dateDebut, 1, 'C', $fillRow, 0, '', '', true, 0, false, true, $rowHeight, 'M');
        // Fin
        $pdf->MultiCell($colEnd, $rowHeight, $dateFin, 1, 'C', $fillRow, 0, '', '', true, 0, false, true, $rowHeight, 'M');
        // Durée
        $pdf->MultiCell($colDuration, $rowHeight, $duration, 1, 'C', $fillRow, 0, '', '', true, 0, false, true, $rowHeight, 'M');
        // Gantt
        $xGantt = $pdf->GetX();
        $yGantt = $pdf->GetY();
        $pdf->MultiCell($colGantt, $rowHeight, '', 1, 'C', $fillRow, 1, '', '', true, 0, false, true, $rowHeight, 'M');

        // Dessiner la barre Gantt si dates valides
        if (!empty($l['date_debut']) && !empty($l['date_fin'])) {
            $start = strtotime($l['date_debut']);
            $end = strtotime($l['date_fin']);
            $offset = ($start - $minDate) / 86400;
            $durationBar = max(1, round(($end - $start) / 86400 + 1));

            // Largeur d'un jour en mm
            $oneDayW = $colGantt / $totalDays;

            // Position de début de la barre
            $barOffset = $offset * $oneDayW;

            // Largeur de la barre (exactement la durée)
            $barW = $durationBar * $oneDayW;

            // Ne pas dépasser la colonne
            if ($barOffset + $barW > $colGantt) {
                $barW = $colGantt - $barOffset;
                if ($barW < $oneDayW) $barW = $oneDayW; // Toujours au moins un jour visible
            }

            // Barre Gantt (couleur douce + légère transparence)
            $barH = 5;
            $barY = $yGantt + ($rowHeight / 2) - ($barH / 2);
            $pdf->SetDrawColor(147, 197, 253);
            $pdf->SetFillColor(59, 130, 246);
            if (method_exists($pdf, 'SetAlpha')) {
                $pdf->SetAlpha(0.35);
            }
            if (method_exists($pdf, 'RoundedRect')) {
                $pdf->RoundedRect($xGantt + $barOffset, $barY, $barW, $barH, 1.2, '1111', 'DF');
            } else {
                $pdf->Rect($xGantt + $barOffset, $barY, $barW, $barH, 'DF');
            }
            if (method_exists($pdf, 'SetAlpha')) {
                $pdf->SetAlpha(1);
            }
        }

        // Si la ligne a provoqué un saut de page, on la réimprime entièrement sur la page suivante
        if ($pdf->getPage() !== $startPage) {
            $pdf->rollbackTransaction(true);
            $pdf->AddPage('L');
            $pdf->Ln(2);
            renderPlanningTableHeader($pdf, $margins, $pageWidth, $colNum, $colTask, $colStart, $colEnd, $colDuration, $colGantt);
            renderPlanningScale($pdf, $margins, $colNum, $colTask, $colStart, $colEnd, $colDuration, $colGantt, (int)$minDate, (int)$maxDate);

            // Re-calcul (Y/X) propre sur la nouvelle page
            $pdf->SetFillColor($fillRow ? 248 : 255, $fillRow ? 250 : 255, $fillRow ? 252 : 255);

            $designation = (string)($l['designation'] ?? '');
            $dateDebut = !empty($l['date_debut']) ? Utils::dateJourCourtFr($l['date_debut']) : '-';
            $dateFin = !empty($l['date_fin']) ? Utils::dateJourCourtFr($l['date_fin']) : '-';
            $duration = dureeFr($l['date_debut'], $l['date_fin']);
            $nbLines = $pdf->getNumLines($designation, $colTask);
            $rowHeight = max(8, $nbLines * 4.5);

            $pdf->SetX($margins['left']);
            $pdf->MultiCell($colNum, $rowHeight, $l['numero'], 1, 'C', $fillRow, 0, '', '', true, 0, false, true, $rowHeight, 'M');
            $pdf->MultiCell($colTask, $rowHeight, $designation, 1, 'L', $fillRow, 0, '', '', true, 0, false, true, $rowHeight, 'M');
            $pdf->MultiCell($colStart, $rowHeight, $dateDebut, 1, 'C', $fillRow, 0, '', '', true, 0, false, true, $rowHeight, 'M');
            $pdf->MultiCell($colEnd, $rowHeight, $dateFin, 1, 'C', $fillRow, 0, '', '', true, 0, false, true, $rowHeight, 'M');
            $pdf->MultiCell($colDuration, $rowHeight, $duration, 1, 'C', $fillRow, 0, '', '', true, 0, false, true, $rowHeight, 'M');
            $xGantt = $pdf->GetX();
            $yGantt = $pdf->GetY();
            $pdf->MultiCell($colGantt, $rowHeight, '', 1, 'C', $fillRow, 1, '', '', true, 0, false, true, $rowHeight, 'M');

            if (!empty($l['date_debut']) && !empty($l['date_fin'])) {
                $start = strtotime($l['date_debut']);
                $end = strtotime($l['date_fin']);
                $offset = ($start - $minDate) / 86400;
                $durationBar = max(1, round(($end - $start) / 86400 + 1));
                $oneDayW = $colGantt / $totalDays;
                $barOffset = $offset * $oneDayW;
                $barW = $durationBar * $oneDayW;
                if ($barOffset + $barW > $colGantt) {
                    $barW = $colGantt - $barOffset;
                    if ($barW < $oneDayW) $barW = $oneDayW;
                }
                $barH = 5;
                $barY = $yGantt + ($rowHeight / 2) - ($barH / 2);
                $pdf->SetDrawColor(147, 197, 253);
                $pdf->SetFillColor(59, 130, 246);
                if (method_exists($pdf, 'SetAlpha')) {
                    $pdf->SetAlpha(0.35);
                }
                if (method_exists($pdf, 'RoundedRect')) {
                    $pdf->RoundedRect($xGantt + $barOffset, $barY, $barW, $barH, 1.2, '1111', 'DF');
                } else {
                    $pdf->Rect($xGantt + $barOffset, $barY, $barW, $barH, 'DF');
                }
                if (method_exists($pdf, 'SetAlpha')) {
                    $pdf->SetAlpha(1);
                }
            }
        } else {
            $pdf->commitTransaction();
        }
    }
}

if (ob_get_length()) {
    ob_clean();
}
\AuditTrail::logFileEvent('export', [
    'module' => 'planning',
    'action' => 'planning_gantt_export',
    'entity_type' => 'devis',
    'entity_id' => isset($devis['id']) ? (string)$devis['id'] : null,
    'entity_label' => (string)($devis['numero_devis'] ?? 'Planning Gantt'),
    'format' => 'pdf',
    'details' => [
        'offre_reference' => $offre['reference_offre'] ?? null,
        'task_count' => is_countable($lignes_debourse ?? null) ? count($lignes_debourse) : null,
    ],
], $pdo ?? null);
$pdf->Output('planning_gantt_' . $devis['id'] . '.pdf', 'I');
exit();
