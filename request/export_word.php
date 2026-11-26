<?php
session_start();
require_once __DIR__.'/../model/CompanyExportBranding.php';
require_once __DIR__.'/../model/EnvConfig.php';

require_once __DIR__ . '/../vendor/autoload.php';

require_once("../model/Utils.php");
require_once("../model/Database.php");
require_once("../model/Devis.php");
require_once("../model/Client.php");
require_once("../model/Offre.php");
require_once("../model/UniteMesure.php");
require_once("../model/AuditTrail.php");

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;

// --- Récupération des données ---
$pdo = Database::getConnection();
$devisObj = new Devis($pdo);
$clientObj = new Client($pdo);
$offreObj = new Offre($pdo);

if (!isset($_SESSION['devisId']) && !isset($_GET['devisId'])) {
    die('ID de devis non défini.');
}
$devisId = $_GET['devisId'] ?? $_SESSION['devisId'];
$devis = $devisObj->getDevisById($devisId);
if (!$devis) die('Devis non trouvé.');
$lignes = $devisObj->getLignesDevis($devisId);
$client = $clientObj->getClientById($devis['client_id']);
if (!$client) die('Client non trouvé.');
$offre = $offreObj->getOffreById($devis['offre_id']);
if (!$offre) die('Projet non trouvé.');

$uniteModel = new UniteMesure($pdo);
$unitesArray = [];
foreach ($uniteModel->getAll() as $u) {
    $unitesArray[$u['id']] = $u;
}

// Créez un nouvel objet PhpWord
$phpWord = new PhpWord();
$section = $phpWord->addSection();

// === HEADER : Logo + Titres + QR code ===
$tableHeader = $section->addTable(['borderSize' => 0, 'cellMargin' => 0, 'alignment' => \PhpOffice\PhpWord\SimpleType\JcTable::CENTER]);
$tableHeader->addRow();

// Logo à gauche
$cellLogo = $tableHeader->addCell(2000, ['valign' => 'center']);
$logoPath = CompanyBranding::logoFile();
if ($logoPath && in_array(strtolower(pathinfo($logoPath, PATHINFO_EXTENSION)), ['png','jpg','jpeg'], true) && is_file($logoPath)) {
    $cellLogo->addImage($logoPath, ['width' => 80, 'height' => 80, 'alignment' => \PhpOffice\PhpWord\SimpleType\Jc::LEFT]);
}

// Titres au centre
$cellTitles = $tableHeader->addCell(7000, ['valign' => 'center']);
$cellTitles->addText(Utils::toMbConvertEncoding(CompanyExportBranding::identityLine()), ['bold' => true, 'size' => 16], ['alignment' => 'center']);
$cellTitles->addText(Utils::toMbConvertEncoding('DEVIS'), ['bold' => false, 'size' => 13], ['alignment' => 'center']);
$cellTitles->addText(Utils::toMbConvertEncoding((string)CompanyBranding::get()['address']), ['bold' => true, 'size' => 13, 'color' => CompanyExportBranding::rgb('primary')], ['alignment' => 'center']);
$cellTitles->addText(Utils::toMbConvertEncoding(CompanyExportBranding::contactLine()), ['bold' => false, 'size' => 14], ['alignment' => 'center']);

// QR code à gauche sous le logo
$cellQR = $tableHeader->addCell(2000, ['valign' => 'center']);
$qrCodeData = CompanyExportBranding::documentUrl('request/export_pdf.php?devisId=' . rawurlencode((string)$devis['id']));
$qrCodeFile = sys_get_temp_dir() . '/qrcode_' . uniqid() . '.png';
$qrResult = Builder::create()->writer(new PngWriter())->data($qrCodeData)->size(120)->margin(5)->build();
file_put_contents($qrCodeFile, $qrResult->getString());
$cellQR->addImage($qrCodeFile, ['width' => 60, 'height' => 60, 'alignment' => \PhpOffice\PhpWord\SimpleType\Jc::LEFT]);

$section->addTextBreak(1);

// === Bloc client à droite ===
$fmt = new IntlDateFormatter('fr_FR', IntlDateFormatter::LONG, IntlDateFormatter::NONE, 'Africa/Abidjan', IntlDateFormatter::GREGORIAN, 'dd MMMM yyyy');
$dateEmission = $fmt->format(new DateTime($devis['date_emission']));
$tableClient = $section->addTable(['borderSize' => 0, 'alignment' => \PhpOffice\PhpWord\SimpleType\Jc::RIGHT]);
$tableClient->addRow();
$cellClient = $tableClient->addCell(4000);
$cellClient->addText(Utils::toMbConvertEncoding("Abidjan, le $dateEmission"), ['size' => 11], ['alignment' => 'center']);
$cellClient->addText(Utils::toMbConvertEncoding($client['nom_client']), ['bold' => true, 'size' => 15], ['alignment' => 'center']);
$cellClient->addText(Utils::toMbConvertEncoding($client['localisation_client']), ['size' => 11], ['alignment' => 'center']);
$cellClient->addText(Utils::toMbConvertEncoding($client['bp_client']), ['size' => 11], ['alignment' => 'center']);

$section->addTextBreak(1);

// === Référence de l'offre encadrée et centrée ===
$refText = Utils::toMbConvertEncoding(strtoupper($offre['reference_offre']));
$section->addText($refText, ['bold' => true, 'size' => 14], [
    'alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER,
    'spaceAfter' => 200,
    'borderBottomSize' => 6,
    'borderBottomColor' => '000000'
]);
$section->addTextBreak(1);

// === Tableau devis ===
$tableStyle = [
    'borderSize' => 6,
    'borderColor' => '000000',
    'cellMargin' => 50
];
$phpWord->addTableStyle('DevisTable', $tableStyle, [
    'alignment' => \PhpOffice\PhpWord\SimpleType\JcTable::CENTER
]);
$table = $section->addTable('DevisTable');
$table->addRow(300);
$table->addCell(800, ['bgColor' => CompanyExportBranding::rgb('primary')])->addText('N°', ['bold' => true, 'color' => CompanyExportBranding::rgb('primary',true)], ['alignment' => 'center']);
$table->addCell(3500, ['bgColor'=>CompanyExportBranding::rgb('primary')])->addText('Désignation', ['bold'=>true,'color'=>CompanyExportBranding::rgb('primary',true)], ['alignment'=>'center']);
$table->addCell(1000, ['bgColor'=>CompanyExportBranding::rgb('primary')])->addText('Qté', ['bold'=>true,'color'=>CompanyExportBranding::rgb('primary',true)], ['alignment'=>'center']);
$table->addCell(1000, ['bgColor'=>CompanyExportBranding::rgb('primary')])->addText('U', ['bold'=>true,'color'=>CompanyExportBranding::rgb('primary',true)], ['alignment'=>'center']);
$table->addCell(1200, ['bgColor'=>CompanyExportBranding::rgb('primary')])->addText('PU', ['bold'=>true,'color'=>CompanyExportBranding::rgb('primary',true)], ['alignment'=>'center']);
$table->addCell(1400, ['bgColor'=>CompanyExportBranding::rgb('primary')])->addText('PT', ['bold'=>true,'color'=>CompanyExportBranding::rgb('primary',true)], ['alignment'=>'center']);

foreach ($lignes as $index => $ligne) {
    $table->addRow(300);
    $table->addCell(800)->addText($index + 1, [], ['alignment' => 'center']);
    $table->addCell(3500)->addText(Utils::toMbConvertEncoding($ligne['designation']));
    $table->addCell(1000)->addText($ligne['quantite'], [], ['alignment' => 'center']);
    $libelleUnite = isset($unitesArray[$ligne['unite_id']]['symbole']) ? $unitesArray[$ligne['unite_id']]['symbole'] : '';
    $table->addCell(1000)->addText(Utils::toMbConvertEncoding($libelleUnite), [], ['alignment' => 'center']);
    $table->addCell(1200)->addText(number_format($ligne['prix'], 0, ',', ' ') . ' XOF', [], ['alignment' => 'center']);
    $table->addCell(1400)->addText(number_format($ligne['total'], 0, ',', ' ') . ' XOF', [], ['alignment' => 'center']);
}

// Debug temporaire
if (empty($lignes)) {
    $section->addText('Aucune ligne de devis trouvée.', ['color' => 'FF0000']);
}

// === Totaux ===
$section->addTextBreak(1);
$section->addText('MONTANT HT: ' . number_format($devis['total_ht'], 0, ',', ' ') . ' XOF', ['bold' => true]);
if ($devis['tva_facturable'] == 1) {
    $section->addText('TVA 18%: ' . number_format($devis['tva'], 0, ',', ' ') . ' XOF', ['bold' => true]);
}
$section->addText('MONTANT TTC: ' . number_format($devis['total_ttc'], 0, ',', ' ') . ' XOF', ['bold' => true]);

// === Montant en lettres ===
$montantNetAPayer = $devis['total_ttc'];
$montantLettre = Utils::montantEnLettre(round($montantNetAPayer));
$section->addTextBreak(1);
$section->addText("Arrêtée le présent devis à la somme de :", ['underline' => 'single', 'size' => 10]);
$section->addText(strtoupper($montantLettre), ['bold' => true, 'size' => 12]);

// === Signature DT alignée à droite ===
$section->addTextBreak(2);
$tableSign = $section->addTable(['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::RIGHT]);
$tableSign->addRow();
$cellSign = $tableSign->addCell(3000);
$cellSign->addText('DIRECTEUR TECHNIQUE', ['bold' => true, 'underline' => 'single', 'size' => 11], ['alignment' => 'center']);
$nomDirecteur = '';
$signatureDirecteur = '';
$signaturePath = '';
if (isset($directeurTechnique)) {
    $nomDirecteur = trim($directeurTechnique['prenom'] . ' ' . $directeurTechnique['nom']);
    $signatureDirecteur = $directeurTechnique['signature'];
    $signaturePath = '../signatures/' . $signatureDirecteur;
}
$cellSign->addText(Utils::toMbConvertEncoding($nomDirecteur ?: 'NOM DU DIRECTEUR'), ['bold' => true, 'size' => 12], ['alignment' => 'center']);
if (!empty($devis['valide_dt']) && $devis['valide_dt'] == 1 && $signatureDirecteur && file_exists($signaturePath)) {
    $cellSign->addImage($signaturePath, ['width' => 120, 'height' => 60, 'alignment' => 'center']);
} elseif (!empty($devis['valide_dt']) && $devis['valide_dt'] == 1) {
    $cellSign->addText('Signature non trouvée', ['italic' => true, 'size' => 10], ['alignment' => 'center']);
}

// === Pied de page ===
$section->addTextBreak(2);
$section->addText(CompanyExportBranding::identityLine(), ['size' => 8], ['alignment' => 'center']);
$section->addText(CompanyExportBranding::contactLine(), ['size' => 8], ['alignment' => 'center']);

// Nettoyage du QR code temporaire
if (file_exists($qrCodeFile)) {
    unlink($qrCodeFile);
}

// Enregistrer et envoyer le fichier
$fileName = 'devis_' . $devis['id'] . '.docx';
$phpWord->save($fileName, 'Word2007');
\AuditTrail::logFileEvent('export', [
    'module' => 'request',
    'action' => 'export_devis_word',
    'entity_type' => 'devis',
    'entity_id' => (string) $devis['id'],
    'entity_label' => (string) ($devis['numero_devis'] ?? $fileName),
    'format' => 'docx',
    'user_id' => $_SESSION['user_id'] ?? null,
    'user_name' => $_SESSION['user_name'] ?? $_SESSION['username'] ?? null,
    'details' => [
        'client_id' => $devis['client_id'] ?? null,
        'offre_id' => $devis['offre_id'] ?? null,
        'line_count' => count($lignes),
        'filename' => $fileName,
    ],
], $pdo);
header('Content-Description: File Transfer');
header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Content-Length: ' . filesize($fileName));
readfile($fileName);
unlink($fileName);

unset($_SESSION['devisId']);
exit();
