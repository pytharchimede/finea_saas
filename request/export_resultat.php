<?php

require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/Devis.php';
require_once __DIR__ . '/../model/Client.php';
require_once __DIR__ . '/../model/Offre.php';
require_once __DIR__ . '/../model/Chantier.php';
require_once __DIR__ . '/../model/Utils.php';
require_once __DIR__ . '/../fpdf186/fpdf.php';
require_once __DIR__ . '/../phpqrcode/qrlib.php';

// Classe PDF personnalisée
class PDF extends FPDF
{
    function Header()
    {
        $this->SetFont('Arial', 'B', 14);
        $this->Cell(0, 10, Utils::toMbConvertEncoding('Liste des Devis'), 0, 1, 'C');
        $this->Ln(10);
    }

    function Footer()
    {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->Cell(0, 10, Utils::toMbConvertEncoding('Page ' . $this->PageNo() . '/{nb}'), 0, 0, 'C');
    }
}

$pdo = Database::getConnection();
$devisModel = new Devis($pdo);
$clientModel = new Client($pdo);
$offreModel = new Offre($pdo);
$chantierModel = new Chantier($pdo);

// Récupération des devis filtrés (mêmes paramètres que liste_devis.php)
$filters = [
    'q' => $_GET['q'] ?? null,
    'client_id' => $_GET['client_id'] ?? null,
    'correspondant' => $_GET['correspondant'] ?? null,
    'validation' => $_GET['validation'] ?? null,
    'chantier' => $_GET['chantier'] ?? null,
    'created_from' => $_GET['created_from'] ?? null,
    'created_to' => $_GET['created_to'] ?? null,
    'archive_status' => $_GET['archive_status'] ?? 'active',
    'billing_status' => $_GET['billing_status'] ?? null,
];

$devisList = $devisModel->searchDevis($filters);

// Création du PDF en mode paysage
$pdf = new PDF('L', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->AddPage();

// En-têtes du tableau
$pdf->SetFont('Arial', 'B', 10);
$pdf->SetFillColor(200, 200, 200);
$pdf->Cell(30, 10, Utils::toMbConvertEncoding('N° Devis'), 1, 0, 'C', true);
$pdf->Cell(70, 10, Utils::toMbConvertEncoding('Client'), 1, 0, 'C', true);
$pdf->Cell(80, 10, Utils::toMbConvertEncoding('Projet'), 1, 0, 'C', true);
$pdf->Cell(35, 10, Utils::toMbConvertEncoding('Créé le'), 1, 0, 'C', true);
$pdf->Cell(40, 10, Utils::toMbConvertEncoding('Total TTC'), 1, 0, 'C', true);
$pdf->Cell(30, 10, Utils::toMbConvertEncoding('Validation'), 1, 0, 'C', true);
$pdf->Cell(30, 10, Utils::toMbConvertEncoding('Chantier'), 1, 1, 'C', true);

// Données des devis
$pdf->SetFont('Arial', '', 10);
foreach ($devisList as $devis) {
    // Récupération des informations du client et de l'offre via les classes
    $client = $clientModel->getClientById($devis['client_id']);
    $offre = $offreModel->getOffreById($devis['offre_id']);

    $createdAt = !empty($devis['created_at']) ? date('d/m/Y', strtotime($devis['created_at'])) : '';
    $totalTtc = number_format((float)($devis['total_ttc'] ?? 0), 0, ',', ' ');

    $validation = 'Non';
    if (!empty($devis['validation_technique']) && empty($devis['validation_generale'])) {
        $validation = 'Tech';
    } elseif (empty($devis['validation_technique']) && !empty($devis['validation_generale'])) {
        $validation = 'DG';
    } elseif (!empty($devis['validation_technique']) && !empty($devis['validation_generale'])) {
        $validation = 'Validé';
    }

    $chantier = $chantierModel->existePourDevis((int)($devis['id'] ?? 0)) ? 'Débuté' : 'Non';

    $pdf->Cell(30, 10, Utils::toMbConvertEncoding($devis['numero_devis']), 1);
    $pdf->Cell(70, 10, Utils::toMbConvertEncoding($client['nom_client'] ?? ''), 1);
    $pdf->Cell(80, 10, Utils::toMbConvertEncoding($offre['reference_offre'] ?? ''), 1);
    $pdf->Cell(35, 10, Utils::toMbConvertEncoding($createdAt), 1);
    $pdf->Cell(40, 10, Utils::toMbConvertEncoding($totalTtc . ' FCFA'), 1);
    $pdf->Cell(30, 10, Utils::toMbConvertEncoding($validation), 1);
    $pdf->Cell(30, 10, Utils::toMbConvertEncoding($chantier), 1, 1);
}

$pdf->Output('I', 'liste_devis.pdf');
