<?php
session_start();
require_once '../vendor/autoload.php';
include_once '../header/header_export_pdf.php';
require_once '../model/Utils.php';
require_once '../model/AuditTrail.php';

// Nécessite FPDI (composer require setasign/fpdi)
use setasign\Fpdi\Tcpdf\Fpdi;

// Récupération des données nécessaires
$devisId = $_GET['devisId'] ?? $_SESSION['devisId'] ?? null;
// ... Récupère $devis, $client, $offre selon ta logique ...

class CustomPDF extends TCPDF
{
    public $logoPath;
    public $enteteTexts = [];

    public function Header()
    {
        // Logo à gauche
        $logoW = 35;
        $logoH = 35;
        $this->Image($this->logoPath, 15, 13, $logoW, $logoH);

        // Textes à droite du logo, alignés verticalement
        $x = 15 + $logoW + 8;
        $y = 15;
        $this->SetXY($x, $y);

        $this->SetFont('helvetica', 'B', 14);
        $this->Cell(0, 7, $this->enteteTexts[0], 0, 2, 'C');
        $this->SetFont('helvetica', '', 11);
        $this->Cell(0, 6, $this->enteteTexts[1], 0, 2, 'C');
        $this->SetFont('helvetica', 'B', 11);
        $this->SetTextColor(255, 204, 0);
        $this->Cell(0, 6, $this->enteteTexts[2], 0, 2, 'C');
        $this->SetFont('helvetica', '', 10);
        $this->SetTextColor(0, 0, 0);
        $this->Cell(0, 6, $this->enteteTexts[3], 0, 2, 'C');
        $this->Ln(2);
    }

    public function Footer()
    {
        // Ligne noire
        $this->SetDrawColor(0, 0, 0);

        // Position à ~3cm du bas pour afficher toutes les lignes
        $this->SetY(-18);

        // Police
        $this->SetFont('helvetica', '', 7);
        $this->SetTextColor(0, 0, 0);
        // Ligne 1
        $this->Cell(0, 3.5, "S.A.R.L au Capital de 100 000 000 FCFA - Siège Social: Abidjan, Koumassi Bd. du Gabon prolongé – 01 BP 1642 Abidjan 01", 0, 1, 'C');
        // Ligne 2
        $this->Cell(0, 3.5, "RCCM N°: CI-ABJ-03-2022-B13-02828 – Tél. : +225 27 21 36 27 27 / 27 21 36 09 29 – Fax : 27 21 36 05 75", 0, 1, 'C');
        // Ligne 3
        $this->Cell(0, 3.5, "E-mail: banacerf1@gmail.com - Compte Bancaire BDU N° : CI180 01010 020401144580 11", 0, 1, 'C');
    }
}

// Création du PDF
$pdf = new CustomPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->logoPath = '../logo/' . ($devis['logo'] ?? 'default_logo.jpg');
$pdf->enteteTexts = [
    'BANAMUR INDUSTRIES ET TECHNOLOGIES',
    'BATIMENT-TRAVAUX PUBLICS',
    'RENOVATION ET TRAVAUX NEUF',
    'TUYAUTERIE-CHAUDRONNERIE-CHARPENTE METALLIQUE'
];
$pdf->SetCreator(PDF_CREATOR);
$pdf->SetAuthor('BANAMUR INDUSTRIES & TECH');
$pdf->SetTitle('Dossier Complet Devis');
$pdf->SetMargins(15, 65, 15); // top margin augmenté pour l'entête
$pdf->SetAutoPageBreak(true, 25); // 25 mm de marge basse pour laisser la place au footer

// Style CSS global
$style = '
<style>
    table {
        border-collapse: collapse;
        width: 100%;
    }
    table, th, td {
        border: 1px solid black;
        padding: 5px;
    }
</style>
';

// ----------- PAGE DE GARDE -----------

$pdf->setPrintHeader(false); // Désactive l'entête auto pour la page de garde
$pdf->AddPage();

// --- Logo à gauche et entête à droite ---
$logoPath = '../logo/' . ($devis['logo'] ?? 'default_logo.jpg');
$logoWidth = 40;
$logoHeight = 40;
$enteteX = 15;
$enteteY = 18;
$enteteW = 190;

// Afficher le logo à gauche
$pdf->Image($logoPath, $enteteX, $enteteY, $logoWidth, $logoHeight);

// Zone de texte à droite du logo
$textX = $enteteX + $logoWidth + 5;
$textW = $enteteW - $logoWidth - 5;
$textY = $enteteY;

// Les 4 lignes d'entête
$lines = [
    ['text' => 'BANAMUR INDUSTRIES ET TECHNOLOGIES', 'size' => 16, 'style' => 'B', 'color' => [0, 0, 0]],
    ['text' => 'BATIMENT-TRAVAUX PUBLICS', 'size' => 13, 'style' => '', 'color' => [0, 0, 0]],
    ['text' => 'RENOVATION ET TRAVAUX NEUF', 'size' => 13, 'style' => 'B', 'color' => [255, 204, 0]],
    ['text' => 'TUYAUTERIE-CHAUDRONNERIE-CHARPENTE METALLIQUE', 'size' => 14, 'style' => '', 'color' => [0, 0, 0]],
];
$lineH = $logoHeight / count($lines);

for ($i = 0; $i < count($lines); $i++) {
    $pdf->SetXY($textX, $textY + $i * $lineH);
    $pdf->SetFont('helvetica', $lines[$i]['style'], $lines[$i]['size']);
    $pdf->SetTextColor($lines[$i]['color'][0], $lines[$i]['color'][1], $lines[$i]['color'][2]);
    $pdf->Cell($textW, $lineH, $lines[$i]['text'], 0, 0, 'C');
}
$pdf->SetTextColor(0, 0, 0);

// Décale le curseur sous le logo
$pdf->SetY($enteteY + $logoHeight + 8);

// --- Mention OFFRE COMMERCIALE sans cadre ---
$pdf->SetFont('helvetica', 'B', 28);
$pdf->Cell(0, 14, 'OFFRE COMMERCIALE', 0, 1, 'C');
$pdf->Ln(4);

// --- Titre de l'offre dans un cadre centré, multi-ligne, avec padding ---
$pdf->SetFont('helvetica', '', 24); // Taille augmentée pour un vrai titre
$titre = isset($offre['reference_offre']) ? strtoupper($offre['reference_offre']) : '';
$margins = $pdf->getMargins();
$maxWidth = $pdf->getPageWidth() - $margins['left'] - $margins['right'];
$paddingX = 12;
$paddingTop = 5;
$paddingBottom = 5; // padding bas réduit

// Calcul hauteur du texte
$nbLines = $pdf->getNumLines($titre, $maxWidth - 2 * $paddingX);
$lineHeight = 15;
$boxHeight = $nbLines * $lineHeight + $paddingTop + $paddingBottom;

// Positionner le cadre sur toute la largeur dispo
$startX = $margins['left'];
$startY = $pdf->GetY();

// Dessiner le cadre du titre (un seul cadre)
$pdf->Rect($startX, $startY, $maxWidth, $boxHeight);

// Afficher le texte du titre avec padding
$pdf->SetXY($startX + $paddingX, $startY + $paddingTop);
$pdf->MultiCell($maxWidth - 2 * $paddingX, $lineHeight, $titre, 0, 'C', false);

// Se placer après le cadre du titre
$pdf->SetY($startY + $boxHeight + 8);

// Afficher le texte avec padding
$pdf->SetXY($startX + $paddingX, $startY + $paddingTop);
$pdf->MultiCell($textWidth - 2 * $paddingX, $lineHeight, $refText, 0, 'C', false);

// Se placer après le cadre
$pdf->SetY($startY + $boxHeight + 4);
$pdf->Ln(5);

// --- Présentée à ---
$pdf->SetFont('helvetica', '', 13);
$pdf->Cell(0, 8, 'Présentée à', 0, 1, 'C');
$pdf->Ln(8);

// --- Logo client ou nom ---
$clientLogoPath = '../uploads/logos_clients/' . ($client['logo_client'] ?? 'default_logo.jpg');
$clientLogoWidth = 50;
$pageWidth = $pdf->getPageWidth();
$logoX = ($pageWidth - $clientLogoWidth) / 2;
$currentY = $pdf->GetY();

if ($clientLogoPath && file_exists($clientLogoPath)) {
    $pdf->Image($clientLogoPath, $logoX, $currentY, $clientLogoWidth);
    $pdf->Ln($clientLogoWidth + 5);
} else {
    $pdf->SetFont('helvetica', 'B', 38);
    $pdf->Cell(0, $clientLogoWidth, $client['nom_client'], 0, 1, 'C');
    $pdf->Ln(5);
}

// --- Infos client ---
$pdf->SetFont('helvetica', '', 12);
$pdf->Cell(0, 5, 'Contact : ' . $devis['correspondant'] . ' - ' . $client['nom_client'], 0, 1, 'C');
$pdf->Cell(0, 5, 'Adresse : ' . $client['localisation_client'], 0, 1, 'C');
$pdf->Cell(0, 5, $client['bp_client'], 0, 1, 'C');
$pdf->Ln(5);

// --- Dénommé le « Client » ---
$pdf->SetFont('helvetica', '', 12);
$pdf->Cell(0, 8, 'Dénommé le « Client »', 0, 1, 'C');
$pdf->Ln(8);

// --- Référence de l’offre alignée à gauche ---
$pdf->SetFont('helvetica', '', 12);
$pdf->Cell(0, 5, "Référence du projet :", 0, 1, 'L');
$pdf->SetFont('helvetica', 'B', 12);
$pdf->Cell(0, 5, strtoupper($offre['num_offre']), 0, 1, 'L');
$pdf->Ln(10);

$pdf->setPrintHeader(true); // Réactive l'entête auto pour les pages suivantes

// ----------- SOMMAIRE -----------
$pdf->AddPage();
$pdf->SetFont('helvetica', 'B', 28);
$pdf->Cell(0, 18, 'SOMMAIRE', 0, 1, 'C');
$pdf->Ln(20);
$pdf->SetFont('helvetica', '', 14);
$pdf->SetLeftMargin(30);
$lineHeightSommaire = 20;
$pdf->Cell(0, $lineHeightSommaire, '1. Description des prestations........................................................................', 0, 1, 'L');
$pdf->Cell(0, $lineHeightSommaire, '2. Délai de réalisation.....................................................................................', 0, 1, 'L');
$pdf->Cell(0, $lineHeightSommaire, '3. Organigramme............................................................................................', 0, 1, 'L');
$pdf->Cell(0, $lineHeightSommaire, '4. CV du chef de projet.....................................................................................', 0, 1, 'L');
$pdf->Cell(0, $lineHeightSommaire, '5. Décompostion des prix...............................................................................', 0, 1, 'L');
$pdf->Cell(0, $lineHeightSommaire, '6. Conditions Financières...............................................................................', 0, 1, 'L');
$pdf->SetLeftMargin(15);
$pdf->Ln(10);

// ----------- SECTION HTML (Description, Délai, Conditions) -----------
$pdf->AddPage();
$pdf->SetFont('helvetica', '', 12);
$pdf->SetTextColor(0, 0, 0);

// Construction du HTML avec titres personnalisés
$html = $style;

// 1. Description des prestations (numéro non souligné, texte souligné)
$html .= '
<span style="font-size:18px;">
  <span>1.&nbsp;</span>
  <span style="text-decoration: underline;">Description des prestations</span>
</span>
<br><div style="margin-bottom: 18px;">' . $devis['description'] . '</div>';

// 2. Délai de réalisation
$html .= '
<span style="font-size:18px;">
  <span>2.&nbsp;</span>
  <span style="text-decoration: underline;">Délai de réalisation</span>
</span>
<br><div style="margin-bottom: 18px;">' . $devis['delai'] . '</div>';


// Forcer les bordures noires sur tous les tableaux HTML
$html = preg_replace(
    '/<table([^>]*)>/i',
    '<table$1 border="1" cellpadding="4" style="border-collapse:collapse; border:1px solid #000;">',
    $html
);

$pdf->SetDrawColor(0, 0, 0); // Bordures noires pour les tableaux
$pdf->writeHTML($html, true, false, true, false, '');



// ----------- SECTION ORGANIGRAMME EN PAYSAGE SANS ENTÊTE -----------
// Désactive l'entête pour cette page
$pdf->setPrintHeader(false);

// Sauvegarde les marges actuelles
$oldMargins = $pdf->getMargins();

// Mets des marges très faibles pour cette page
$pdf->SetMargins(2, 2, 2); // 2mm partout
$pdf->AddPage('L');

// Calcul pour occuper tout l'espace disponible
$pageWidth = $pdf->getPageWidth();
$pageHeight = $pdf->getPageHeight();
$margins = $pdf->getMargins();
$usableWidth = $pageWidth - $margins['left'] - $margins['right'];
$usableHeight = $pageHeight - $margins['top'] - $margins['bottom'];

// Récupère la taille réelle de l'image
$imagePath = '../uploads/organigramme/organigramme_devis_16.jpg';
list($imgWidth, $imgHeight) = getimagesize($imagePath);

// Calcul du ratio pour occuper le max d'espace
$ratio = min($usableWidth / $imgWidth, $usableHeight / $imgHeight);
$displayWidth = $imgWidth * $ratio;
$displayHeight = $imgHeight * $ratio;

// Position X/Y pour centrer l'image sur la page
$x = $margins['left'] + ($usableWidth - $displayWidth) / 2;
$y = $margins['top'] + ($usableHeight - $displayHeight) / 2;

// Affiche l'image centrée et agrandie
$pdf->Image($imagePath, $x, $y, $displayWidth, $displayHeight);

// Restaure les marges pour les pages suivantes
$pdf->SetMargins($oldMargins['left'], $oldMargins['top'], $oldMargins['right']);

// Réactive l'entête pour les pages suivantes
$pdf->setPrintHeader(true);


// ----------- SECTION CV CHEF DE PROJET -----------
$pdf->AddPage('P');
$pdf->SetFont('helvetica', 'B', 16);
$pdf->Cell(0, 12, '4. CV du chef de projet', 0, 1, 'L');
$pdf->Ln(4);
$pdf->Image('../uploads/cv/cv_chef_de_projet_16_page1.jpg', 15, 60, 180);
$pdf->AddPage();
$pdf->Image('../uploads/cv/cv_chef_de_projet_16_page2.jpg', 15, 60, 180);


// ----------- SECTION DÉCOMPOSITION DES PRIX -----------
$pdf->AddPage('P');
$pdf->SetFont('helvetica', 'B', 16);
$pdf->SetTextColor(0, 0, 0);
$pdf->Cell(0, 12, '5. Décomposition des prix', 0, 1, 'L');
$pdf->Ln(4);

// En-tête du tableau
$pdf->SetFont('helvetica', 'B', 11);
$pdf->SetFillColor(0, 0, 0);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell(10, 8, 'N°', 1, 0, 'C', true);
$pdf->Cell(65, 8, 'Désignation', 1, 0, 'C', true);
$pdf->Cell(20, 8, 'Qté', 1, 0, 'C', true);
$pdf->Cell(25, 8, 'U', 1, 0, 'C', true);
$pdf->Cell(30, 8, 'PU', 1, 0, 'C', true);
$pdf->Cell(40, 8, 'PT', 1, 1, 'C', true);

// Corps du tableau
$pdf->SetFont('helvetica', '', 10);
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFillColor(255, 255, 255);

$pos = 1;
foreach ($lignes as $ligne) {
    $unite = isset($unitesArray[$ligne['unite_id']]) ? $unitesArray[$ligne['unite_id']]['symbole'] : '';
    $pdf->Cell(10, 7, $pos++, 1, 0, 'C');
    // Désignation sur plusieurs lignes si besoin
    $x = $pdf->GetX();
    $y = $pdf->GetY();
    $pdf->MultiCell(65, 7, Utils::toMbConvertEncoding($ligne['designation']), 1, 'L', false, 0, '', '', true, 0, false, true, 7, 'M');
    $pdf->SetXY($x + 65, $y);
    $pdf->Cell(20, 7, $ligne['quantite'], 1, 0, 'C');
    $pdf->Cell(25, 7, Utils::toMbConvertEncoding($unite), 1, 0, 'C');
    $pdf->Cell(30, 7, number_format($ligne['prix'], 0, ',', ' ') . ' XOF', 1, 0, 'C');
    $pdf->Cell(40, 7, number_format($ligne['total'], 0, ',', ' ') . ' XOF', 1, 1, 'C');
}

// Total HT
$pdf->SetFont('helvetica', 'B', 11);
$pdf->Cell(150, 8, 'MONTANT HT', 1, 0, 'C');
$pdf->Cell(40, 8, number_format($devis['total_ht'], 0, ',', ' ') . ' XOF', 1, 1, 'C');

// TVA
if ($devis['tva_facturable'] == 1) {
    $pdf->Cell(150, 8, 'TVA 18%', 1, 0, 'C');
    $pdf->Cell(40, 8, number_format($devis['tva'], 0, ',', ' ') . ' XOF', 1, 1, 'C');
} else {
    $pdf->SetFont('helvetica', 'I', 10);
    $pdf->SetTextColor(120, 120, 120);
    $pdf->Cell(150, 8, 'TVA 18% (non facturée)', 1, 0, 'C');
    $pdf->Cell(40, 8, '(' . number_format(0.18 * $devis['total_ht'], 0, ',', ' ') . ' XOF)', 1, 1, 'C');
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->SetTextColor(0, 0, 0);
}

// Total TTC ou Net à payer
if ($devis['tva_facturable'] == 1) {
    $pdf->Cell(150, 8, 'MONTANT TTC', 1, 0, 'C');
    $pdf->Cell(40, 8, number_format($devis['total_ttc'], 0, ',', ' ') . ' XOF', 1, 1, 'C');
} else {
    $pdf->Cell(150, 8, 'MONTANT NET À PAYER', 1, 0, 'C');
    $pdf->Cell(40, 8, number_format($devis['total_ht'], 0, ',', ' ') . ' XOF', 1, 1, 'C');
}

$pdf->Ln(5);

// Montant en lettres
$pdf->SetFont('helvetica', 'U', 9);
$pdf->Cell(0, 6, "Arrêtée le présent devis à la somme de :", 0, 1, 'L');
$pdf->SetFont('helvetica', 'B', 10);
$montantLettre = Utils::montantEnLettre($devis['total_ttc']);
$pdf->MultiCell(0, 6, strtoupper(Utils::toMbConvertEncoding($montantLettre)), 0, 'L');


// ----------- SECTION HTML (Conditions financières) -----------
$pdf->AddPage('P');
$pdf->SetFont('helvetica', '', 12);
$pdf->SetTextColor(0, 0, 0);

// Construction du HTML avec titres personnalisés
$html = $style;

// 6. Conditions Financières
$html .= '
<span style="font-size:18px;">
  <span>6.&nbsp;</span>
  <span style="text-decoration: underline;">Conditions Financières</span>
</span>
<br><div style="margin-bottom: 18px;">' . $devis['conditions'] . '</div>';
$pdf->writeHTML($html, true, false, true, false, '');

// ----------- EXPORT PDF -----------
ob_clean();
\AuditTrail::logVisualEvent('preview', [
    'module' => 'devis',
    'action' => 'dossier_complet_preview',
    'entity_type' => 'devis',
    'entity_id' => isset($devis['id']) ? (string)$devis['id'] : (string)$devisId,
    'entity_label' => (string)($devis['numero_devis'] ?? $offre['num_offre'] ?? 'Dossier complet devis'),
    'details' => [
        'output_mode' => 'inline_pdf',
        'client' => $client['nom_client'] ?? null,
        'offre' => $offre['num_offre'] ?? null,
    ],
], $pdo ?? null);
$pdf->Output('devis_' . $devis['id'] . '.pdf', 'I');
