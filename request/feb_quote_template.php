<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../model/Database.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\NamedRange;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$book = new Spreadsheet();
$pdo = \Database::getConnection();
$supplierRows = $pdo->query('SELECT id_fournisseur, code_fournisseur, COALESCE(NULLIF(TRIM(nom_commercial), ""), raison_sociale) AS nom, telephone, contact_nom, email FROM fournisseur_banamur WHERE actif = 1 AND validation_status = "approved" ORDER BY nom')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$supplierChoices = [];
$supplierDetails = [];
foreach ($supplierRows as $supplier) {
    $id = (int) ($supplier['id_fournisseur'] ?? 0);
    if ($id <= 0) continue;
    $code = trim((string) ($supplier['code_fournisseur'] ?? ''));
    $name = trim((string) ($supplier['nom'] ?? ''));
    $choice = $id . ' | ' . ($code !== '' ? $code . ' | ' : '') . $name;
    $supplierChoices[] = $choice;
    $supplierDetails[$choice] = [
        'phone' => trim((string)($supplier['telephone'] ?? '')),
        'contact' => trim(implode(' / ', array_filter([
            trim((string)($supplier['contact_nom'] ?? '')),
            trim((string)($supplier['email'] ?? '')),
        ]))),
    ];
}
$sheet = $book->getActiveSheet();
$sheet->setTitle('Devis FEB');
$sheet->mergeCells('A1:H1')->setCellValue('A1', 'MODÈLE D’IMPORT DU DEVIS FEB');
$sheet->mergeCells('A2:H2')->setCellValue('A2', 'Tous les champs d’une ligne de besoin sont repris, sauf le justificatif à joindre après import.');
$sheet->setCellValue('A3', 'Titre du devis / futur déboursé')->setCellValue('B3', 'Exemple : Fourniture et pose du réseau électrique');
$headers = ['Type', 'Catégorie', 'Libellé du besoin', 'Quantité', 'Fournisseur approuvé', 'Prix unitaire (FCFA)', 'Téléphone fournisseur', 'Contact / email'];
foreach ($headers as $i => $header) $sheet->setCellValueByColumnAndRow($i + 1, 6, $header);
$firstSupplier = $supplierChoices[0] ?? '';
$secondSupplier = $supplierChoices[1] ?? $firstSupplier;
$examples = [
    ['Bien', 'ACHAT', 'Équipements de protection', 10, $firstSupplier, 5500, $supplierDetails[$firstSupplier]['phone'] ?? '', $supplierDetails[$firstSupplier]['contact'] ?? ''],
    ['Service', 'Logistique', 'Transport et livraison', 1, $secondSupplier, 250000, $supplierDetails[$secondSupplier]['phone'] ?? '', $supplierDetails[$secondSupplier]['contact'] ?? ''],
];
foreach ($examples as $r => $line) foreach ($line as $c => $value) $sheet->setCellValueByColumnAndRow($c + 1, $r + 7, $value);
$sheet->getStyle('A1:H1')->getFont()->setBold(true)->setSize(18)->getColor()->setRGB('FFFFFF');
$sheet->getStyle('A1:H1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('312E81');
$sheet->getStyle('A1:H2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('A6:H6')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
$sheet->getStyle('A6:H6')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('0F766E');
$sheet->getStyle('A6:H100')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('CBD5E1');
foreach (['A'=>16,'B'=>18,'C'=>42,'D'=>14,'E'=>38,'F'=>24,'G'=>22,'H'=>34] as $col=>$width) $sheet->getColumnDimension($col)->setWidth($width);
$sheet->freezePane('A7'); $sheet->setAutoFilter('A6:H100');
for ($row = 7; $row <= 100; $row++) {
    $type = $sheet->getCell("A$row")->getDataValidation();
    $type->setType(DataValidation::TYPE_LIST)->setAllowBlank(false)->setShowDropDown(true)->setFormula1('"Bien,Service"');
    $category = $sheet->getCell("B$row")->getDataValidation();
    $category->setType(DataValidation::TYPE_LIST)->setAllowBlank(false)->setShowDropDown(true)->setFormula1('"RH,Logistique,HSE,ACHAT,Bureau"');
    $supplier = $sheet->getCell("E$row")->getDataValidation();
    $supplier->setType(DataValidation::TYPE_LIST)->setAllowBlank(false)->setShowDropDown(true)->setShowInputMessage(true)->setPromptTitle('Fournisseur approuvé')->setPrompt('Cliquez sur la flèche et choisissez une valeur du référentiel.')->setShowErrorMessage(true)->setErrorStyle(DataValidation::STYLE_STOP)->setErrorTitle('Fournisseur invalide')->setError('Sélectionnez uniquement un fournisseur approuvé dans la liste.')->setFormula1('=ListeFournisseurs');
}
$supplierSheet = $book->createSheet();
$supplierSheet->setTitle('Fournisseurs approuves');
$supplierSheet->setCellValue('A1', 'ID | CODE | FOURNISSEUR APPROUVÉ');
$supplierSheet->getStyle('A1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
$supplierSheet->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('0F766E');
$supplierSheet->getColumnDimension('A')->setWidth(70);
if ($supplierChoices) {
    foreach ($supplierChoices as $index => $choice) $supplierSheet->setCellValue('A' . ($index + 2), $choice);
} else {
    $supplierSheet->setCellValue('A2', 'AUCUN FOURNISSEUR APPROUVÉ DISPONIBLE');
}
$supplierLastRow = max(2, count($supplierChoices) + 1);
$book->addNamedRange(new NamedRange('ListeFournisseurs', $supplierSheet, '$A$2:$A$' . $supplierLastRow));
$help = $book->createSheet(); $help->setTitle('Mode d’emploi');
$help->setCellValue('A1', 'COMMENT REMPLIR LE FICHIER')->getStyle('A1')->getFont()->setBold(true)->setSize(16);
$instructions = [
    '1. Saisissez le titre du devis dans la cellule B3.',
    '2. Utilisez uniquement les catégories RH, Logistique, HSE, ACHAT ou Bureau.',
    '3. La désignation doit décrire précisément le bien ou le service.',
    '4. Le montant de ligne sera calculé : quantité × prix unitaire.',
    '5. Sélectionnez le fournisseur dans la liste de la colonne E. La valeur contient son identifiant unique et ne doit jamais être modifiée manuellement.',
    '6. Le téléphone et le contact sont informatifs : l’import reprend les valeurs à jour de la fiche fournisseur.',
    '7. Le justificatif de chaque ligne est ajouté dans la FEB après l’import.',
    '8. Supprimez les deux lignes d’exemple avant l’import de votre fichier définitif.',
];
foreach ($instructions as $i => $text) $help->setCellValue('A' . ($i + 3), $text);
$help->getColumnDimension('A')->setWidth(110); $help->getStyle('A1:A10')->getAlignment()->setWrapText(true);

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="modele_devis_FEB.xlsx"');
header('Cache-Control: max-age=0');
(new Xlsx($book))->save('php://output');
