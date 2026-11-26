<?php
session_start();
require_once __DIR__.'/../model/CompanyExportBranding.php';
require_once __DIR__.'/../model/EnvConfig.php';

include_once '../header/header_export_pdf.php';
require_once("../model/Utils.php");

// Créez une classe dérivée de FPDF
class PDF extends FPDF
{
    function NbLines($w, $txt)
    {
        $cw = &$this->CurrentFont['cw'];
        if ($w == 0) {
            $w = $this->w - $this->rMargin - $this->x;
        }
        $wmax = ($w - 2 * $this->cMargin) * 1000 / $this->FontSize;
        $s = str_replace("\r", '', $txt);
        $nb = strlen($s);
        if ($nb > 0 && $s[$nb - 1] == "\n") {
            $nb--;
        }
        $sep = -1;
        $i = 0;
        $j = 0;
        $l = 0;
        $nl = 1;
        while ($i < $nb) {
            $c = $s[$i];
            if ($c == "\n") {
                $i++;
                $sep = -1;
                $j = $i;
                $l = 0;
                $nl++;
                continue;
            }
            if ($c == ' ') {
                $sep = $i;
            }
            $l += $cw[$c];
            if ($l > $wmax) {
                if ($sep == -1) {
                    if ($i == $j) {
                        $i++;
                    }
                } else {
                    $i = $sep + 1;
                }
                $sep = -1;
                $j = $i;
                $l = 0;
                $nl++;
            } else {
                $i++;
            }
        }
        return $nl;
    }

    function RemainingHeight()
    {
        return $this->PageBreakTrigger - $this->GetY();
    }

    function EnsureSpace($height)
    {
        if ($this->GetY() + $height > $this->PageBreakTrigger) {
            $this->AddPage();
        }
    }

    // Méthode pour l'en-tête
    function Header()
    {
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 10, '', 0, 1, 'C');
        $this->Ln(10);

        // $this->Image('../img/logo_veritas.jpg', 150, 10, 30);
    }

    // Méthode pour le pied de page
    function Footer()
    {
        // Dessiner une ligne noire
        $this->SetDrawColor(0, 0, 0);
        $this->Line(10, 272, 200, 272);

        // Position à 1.5 cm du bas
        $this->SetY(-22);

        // Arial 7
        $this->SetFont('Arial', '', 7);

        // Ligne 1
        $this->Cell(0, 3.5, Utils::toMbConvertEncoding(CompanyExportBranding::identityLine()), 0, 1, 'C');
        // Ligne 2
        $this->Cell(0, 3.5, Utils::toMbConvertEncoding(CompanyExportBranding::contactLine()), 0, 1, 'C');
        // Ligne 3
        $this->Cell(0, 3.5, Utils::toMbConvertEncoding("E-mail: banacerf1@gmail.com - Compte Bancaire BDU N° : CI180 01010 020401144580 11"), 0, 1, 'C');

        // Numéro de page
        $this->Cell(0, 10, Utils::toMbConvertEncoding('Page ' . $this->PageNo() . '/{nb}'), 0, 0, 'C');
    }
}

// Créez un nouvel objet FPDF
$pdf = new PDF('P', 'mm', 'A4');
$pdf->SetAutoPageBreak(true, 30);
$pdf->AddPage();
$pdf->SetFont('Arial', 'B', 16);
$pdf->AliasNbPages();

// Dimensions
$logoPath = CompanyBranding::logoFile();
$logoWidth = 40;
$logoHeight = 40;
$enteteX = 10;
$enteteY = 10;
$enteteW = 190;

// Afficher le logo à gauche
if ($logoPath && in_array(strtolower(pathinfo($logoPath, PATHINFO_EXTENSION)), ['png','jpg','jpeg'], true) && ($size = @getimagesize($logoPath))) { $scale=min($logoWidth/$size[0],$logoHeight/$size[1]); $pdf->Image($logoPath,$enteteX,$enteteY,$size[0]*$scale,$size[1]*$scale); }

// Zone de texte à droite du logo
$textX = $enteteX + $logoWidth + 5;
$textW = $enteteW - $logoWidth - 5;
$textY = $enteteY;

// Préparer les 4 lignes (inchangé)
$lines = [
    [
        'text' => CompanyExportBranding::identityLine(),
        'size' => 16,
        'style' => 'B',
        'color' => [0, 0, 0],
        'font' => 'Arial'
    ],
    [
        'text' => 'DEVIS',
        'size' => 13,
        'style' => '',
        'color' => [0, 0, 0],
        'font' => 'Arial'
    ],
    [
        'text' => (string)CompanyBranding::get()['address'],
        'size' => 13,
        'style' => 'B',
        'color' => CompanyExportBranding::rgbArray('primary'),
        'font' => 'Arial'
    ],
    [
        'text' => CompanyExportBranding::contactLine(),
        'size' => 14,
        'style' => '',
        'color' => [0, 0, 0],
        'font' => 'Arial'
    ],
];

// Calculer la hauteur d'une ligne pour occuper exactement la hauteur du logo
$lineH = $logoHeight / count($lines);

// Afficher chaque ligne centrée dans la zone texte, alignée verticalement avec le logo
for ($i = 0; $i < count($lines); $i++) {
    $pdf->SetXY($textX, $textY + $i * $lineH);
    $pdf->SetFont($lines[$i]['font'], $lines[$i]['style'], $lines[$i]['size']);
    $pdf->SetTextColor($lines[$i]['color'][0], $lines[$i]['color'][1], $lines[$i]['color'][2]);
    $pdf->Cell($textW, $lineH, Utils::toMbConvertEncoding($lines[$i]['text']), 0, 0, 'C');
}

// Remettre la couleur noire pour la suite
$pdf->SetTextColor(0, 0, 0);
$pdf->Ln($logoHeight + 5);

// Générer le QR code
$qrCodeData = CompanyExportBranding::documentUrl('request/export_pdf.php?devisId=' . rawurlencode((string)$devis['id']));
$qrCodeFile = '../qrCodeFile/qrcode.png';
QRcode::png($qrCodeData, $qrCodeFile, 'L', 4, 2);

// Positionner le QR code 
$pdf->Image($qrCodeFile, 16, 62, 15);

// Positionnement pour le bloc client à droite sous l'entête
$blocW = 63; // 1/3 de 190mm
$blocX = 127; // 190 - 63 = 127, mais on laisse 10mm de marge à droite
$blocY = $enteteY + $logoHeight + 5;

// Ligne 1 : Date en français (remplace strftime)
$fmt = new IntlDateFormatter('fr_FR', IntlDateFormatter::LONG, IntlDateFormatter::NONE, 'Africa/Abidjan', IntlDateFormatter::GREGORIAN, 'dd MMMM yyyy');
$dateEmission = $fmt->format(new DateTime($devis['date_emission']));
$ligne1 = "Abidjan, le $dateEmission";

// Ligne 2 : Nom du client
$ligne2 = $client['nom_client'];

// Ligne 3 : Localisation
$ligne3 = $client['localisation_client'];

// Ligne 4 : BP
$ligne4 = $client['bp_client'];

// Affichage
$pdf->SetXY($blocX, $blocY);
$pdf->SetFont('Arial', '', 11);
$pdf->Cell($blocW, 7, Utils::toMbConvertEncoding($ligne1), 0, 2, 'C');

$pdf->SetFont('Arial', 'B', 15);
$pdf->Cell($blocW, 9, Utils::toMbConvertEncoding($ligne2), 0, 2, 'C');

$pdf->SetFont('Arial', '', 11);
$pdf->Cell($blocW, 7, Utils::toMbConvertEncoding($ligne3), 0, 2, 'C');

$pdf->Cell($blocW, 7, Utils::toMbConvertEncoding($ligne4), 0, 2, 'C');

// Revenir à la position normale pour la suite
$pdf->Ln(5);




// Afficher la référence de l'offre comme titre, centré, grand, gras et encadré, avec retour à la ligne si besoin
$pdf->SetFont('Arial', 'B', 14);
$pdf->SetDrawColor(0, 0, 0);
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFillColor(255, 255, 255);

$refText = Utils::toMbConvertEncoding(strtoupper($offre['reference_offre']));

// Calcul largeur réelle du texte (max 150mm pour éviter d'aller trop loin sur la page)
$maxWidth = 180;
$textWidth = $pdf->GetStringWidth($refText) + 12; // 6mm padding de chaque côté
if ($textWidth > $maxWidth) $textWidth = $maxWidth;

// Positionner au centre
$pageWidth = 210 - 20; // A4 - marges (10mm de chaque côté)
$startX = 10 + ($pageWidth - $textWidth) / 2;

// Afficher le cadre autour du texte, MultiCell pour retour à la ligne
$pdf->SetX($startX);
$pdf->MultiCell($textWidth, 7, $refText, 1, 'C', true);

// Ajouter un petit espace après le bloc
$pdf->Ln(8);

// Tableau des lignes du devis
$pdf->SetFont('Arial', 'B', 8);

$pdf->SetFillColor(0, 0, 0);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetDrawColor(169, 169, 169);

$pdf->Cell(10, 10, Utils::toMbConvertEncoding('N°'), 1, 0, 'C', true);
$pdf->Cell(65, 10, Utils::toMbConvertEncoding('Désignation'), 1, 0, 'C', true);
$pdf->Cell(20, 10, Utils::toMbConvertEncoding('Quantité'), 1, 0, 'C', true);
$pdf->Cell(25, 10, Utils::toMbConvertEncoding('Unité'), 1, 0, 'C', true);
$pdf->Cell(30, 10, Utils::toMbConvertEncoding('Prix unitaire'), 1, 0, 'C', true);
$pdf->Cell(30, 10, Utils::toMbConvertEncoding('Prix total'), 1, 0, 'C', true);
$pdf->Ln();

$pdf->SetTextColor(0, 0, 0);
$pdf->SetFillColor(255, 255, 255);
$pdf->SetDrawColor(0, 0, 0);

$groups = [];
foreach ($lignes as $ligne) {
    $groupKey = (string) ($ligne['groupe'] ?? '');
    if (!isset($groups[$groupKey])) {
        $groups[$groupKey] = ['designations' => [], 'total' => 0];
    }
    $groups[$groupKey]['designations'][] = $ligne['designation'];
    $groups[$groupKey]['total'] += $ligne['total'];
}

$cellHeight = 6;
$groupIndex = 1;

foreach ($groups as $group) {
    $remaining = array_values($group['designations']);
    $firstChunk = true;

    while ($remaining) {
        // Une fin de page trop courte n'est pas utile : commencer le groupe
        // sur la page suivante, sans répéter l'en-tête des colonnes.
        if ($pdf->RemainingHeight() < $cellHeight) {
            $pdf->AddPage();
        }

        $pdf->SetFont('Arial', '', 9);
        $chunk = [];
        $chunkLines = 0;
        $maxLines = max(1, (int) floor($pdf->RemainingHeight() / $cellHeight));

        $firstText = Utils::toMbConvertEncoding('- ' . $remaining[0]);
        if ($pdf->NbLines(65, $firstText) > $maxLines && $pdf->GetY() > 35) {
            $pdf->AddPage();
            continue;
        }

        foreach ($remaining as $designation) {
            $candidate = array_merge($chunk, [$designation]);
            $candidateText = Utils::toMbConvertEncoding(implode("\n", array_map(fn($d) => "- $d", $candidate)));
            $candidateLines = $pdf->NbLines(65, $candidateText);
            if ($candidateLines > $maxLines && $chunk) {
                break;
            }
            $chunk = $candidate;
            $chunkLines = min($candidateLines, $maxLines);
            if ($candidateLines >= $maxLines) {
                break;
            }
        }

        $chunkCount = count($chunk);
        $remaining = array_slice($remaining, $chunkCount);
        $isLastChunk = !$remaining;
        $designationCell = Utils::toMbConvertEncoding(implode("\n", array_map(fn($d) => "- $d", $chunk)));
        $rowHeight = max($cellHeight, $cellHeight * $chunkLines);
        $pdf->EnsureSpace($rowHeight);

        $x = $pdf->GetX();
        $y = $pdf->GetY();
        $amount = number_format($group['total'], 0, ',', ' ') . ' XOF';

        $pdf->SetFont('Arial', 'B', 10);
        $pdf->SetXY($x, $y);
        $pdf->Cell(10, $rowHeight, $firstChunk ? $groupIndex : '', 1, 0, 'C');

        $pdf->SetFont('Arial', '', 9);
        $pdf->SetXY($x + 10, $y);
        $pdf->MultiCell(65, $cellHeight, $designationCell, 0, 'L', false);
        $pdf->Rect($x + 10, $y, 65, $rowHeight);

        $pdf->SetXY($x + 75, $y);
        $pdf->SetFont('Arial', '', 9);
        $pdf->Cell(20, $rowHeight, $isLastChunk ? '1' : '', 1, 0, 'C');
        $pdf->Cell(25, $rowHeight, $isLastChunk ? 'u' : '', 1, 0, 'C');
        $pdf->Cell(30, $rowHeight, $isLastChunk ? $amount : '', 1, 0, 'C');
        $pdf->Cell(30, $rowHeight, $isLastChunk ? $amount : '', 1, 0, 'C');
        $pdf->SetXY($x, $y + $rowHeight);

        $firstChunk = false;
        if (!$isLastChunk) {
            $pdf->AddPage();
        }
    }
    $groupIndex++;
}

// Garder les lignes de totaux et les mentions qui les suivent ensemble.
$totalsRows = 2 + ($devis['tva_facturable'] == 1 ? 1 : 0)
    + (!empty($devis['remise']) && $devis['remise'] != 0 ? 2 : 0);
$pdf->EnsureSpace($totalsRows * 10 + 35);

// Ligne Montant HT
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(150, 10, Utils::toMbConvertEncoding('MONTANT HT'), 1, 0, 'C');
$pdf->Cell(30, 10, number_format($devis['total_ht'], 0, ',', ' ') . ' XOF', 1, 1, 'C');

// Ligne TVA si facturable
if ($devis['tva_facturable'] == 1) {
    $pdf->Cell(150, 10, Utils::toMbConvertEncoding('TVA 18%'), 1, 0, 'C');
    $pdf->Cell(30, 10, number_format($devis['tva'], 0, ',', ' ') . ' XOF', 1, 1, 'C');
    $montantAvantRemise = $devis['total_ttc'];
    $libelleMontant = 'MONTANT TTC';
} else {
    $montantAvantRemise = $devis['total_ht'];
    $libelleMontant = 'MONTANT NET À PAYER';
}

// Affichage du montant TTC ou NAP
$pdf->Cell(150, 10, Utils::toMbConvertEncoding($libelleMontant), 1, 0, 'C');
$pdf->Cell(30, 10, number_format($montantAvantRemise, 0, ',', ' ') . ' XOF', 1, 1, 'C');

// Si remise, afficher la ligne de remise et le net à payer
if (!empty($devis['remise']) && $devis['remise'] != 0) {
    $remisePourcent = floatval($devis['remise']);
    $montantRemise = $montantAvantRemise * $remisePourcent / 100;
    $montantNetAPayer = $montantAvantRemise - $montantRemise;

    // Ligne Remise
    $pdf->SetFont('Arial', '', 10);
    $pdf->SetTextColor(0, 102, 204);
    $pdf->Cell(150, 10, Utils::toMbConvertEncoding('REMISE (' . number_format($remisePourcent, 2, ',', ' ') . ' %)'), 1, 0, 'C');
    $pdf->Cell(30, 10, '- ' . number_format($montantRemise, 0, ',', ' ') . ' XOF', 1, 1, 'C');

    // Ligne Net à Payer
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(150, 10, Utils::toMbConvertEncoding('NET À PAYER'), 1, 0, 'C');
    $pdf->Cell(30, 10, number_format($montantNetAPayer, 0, ',', ' ') . ' XOF', 1, 1, 'C');
} else {
    $montantNetAPayer = $montantAvantRemise;
}

$pdf->Ln(5);

// Ligne 1 : Arrêtée la présente facture à la somme de :
$pdf->SetFont('Arial', 'U', 8);
$pdf->Cell(0, 6, Utils::toMbConvertEncoding("Arrêtée le présent devis à la somme de :"), 0, 1, 'L');

// Ligne 2 : Montant en lettres (net à payer)
$montantLettre = Utils::montantEnLettre($montantNetAPayer);
$pdf->SetFont('Arial', 'B', 10);
$pdf->MultiCell(0, 6, Utils::toMbConvertEncoding(strtoupper($montantLettre)), 0, 'L');

// Ligne 3 : CONDITIONS DE REGLEMENT
$pdf->SetFont('Arial', 'U', 8);
$pdf->Cell(0, 6, Utils::toMbConvertEncoding("CONDITIONS DE REGLEMENT :"), 0, 1, 'L');
$pdf->SetFont('Arial', 'B', 8);

$pdf->MultiCell(0, 6, Utils::toMbConvertEncoding("Paiement 60 jours après réception du devis"), 0, 'L');

$nomDirecteur = '';
$signatureDirecteur = '';
$signaturePath = '';

if ($directeurTechnique) {
    $nomDirecteur = trim($directeurTechnique['prenom'] . ' ' . $directeurTechnique['nom']);
    $signatureDirecteur = $directeurTechnique['signature']; // ex: sign_akpo.jpg
    $signaturePath = '../signatures/' . $signatureDirecteur;
}

// Affichage de la zone signature alignée à droite et centrée dans le bloc
$pdf->EnsureSpace(47);
$pdf->Ln(10); // espace avant la zone de signature

$blocX = 120; // Position X pour aligner à droite (ajuste si besoin)
$largeurBloc = 80; // Largeur du bloc signature

// Calcul de la position X pour centrer les éléments dans le bloc aligné à droite
$centerX = $blocX;

// "DIRECTEUR TECHNIQUE" centré dans le bloc
$pdf->SetXY($centerX, $pdf->GetY());
$pdf->SetFont('Arial', 'U', 10);
$pdf->Cell($largeurBloc, 7, Utils::toMbConvertEncoding('DIRECTEUR TECHNIQUE'), 0, 2, 'C');

// Nom du directeur technique centré dans le bloc
$pdf->SetFont('Arial', 'B', 11);
$pdf->SetX($centerX);
$pdf->Cell($largeurBloc, 8, Utils::toMbConvertEncoding($nomDirecteur ?: 'NOM DU DIRECTEUR'), 0, 2, 'C');

// Afficher la signature scannée du DT si validé, centrée dans le bloc et juste sous le nom
if (!empty($devis['valide_dt']) && $devis['valide_dt'] == 1 && $signatureDirecteur) {
    if (file_exists($signaturePath)) {
        $ySignature = $pdf->GetY();
        // Centrer l'image dans le bloc signature
        $imageWidth = min(40, $largeurBloc - 10); // 40mm max ou largeur du bloc - 10mm
        $imageHeight = 20;
        $imageX = $centerX + ($largeurBloc - $imageWidth) / 2;
        $pdf->Image($signaturePath, $imageX, $ySignature, $imageWidth, $imageHeight);

        //Valeurs 
        $pdf->SetY($ySignature + $imageHeight + 2); // Passe à la ligne après l'image
    } else {
        $pdf->SetFont('Arial', '', 10);
        $pdf->SetX($centerX);
        $pdf->Cell($largeurBloc, 10, 'Signature non trouvée : ' . $signaturePath, 0, 2, 'C');
    }
}


$pdf->Ln(20);

ob_clean();

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="devis_' . $devis['id'] . '.pdf"');

$pdf->Output('I', 'devis_' . $devis['id'] . '.pdf');

unset($_SESSION['devisId']);

exit();
