<?php
require_once __DIR__ . '/../services/FebCategoryMigrationService.php';
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../model/ZipArchiveCompatibility.php';
require_once __DIR__ . '/../model/Database.php';

header('Content-Type: application/json; charset=utf-8');
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['quote_file']['tmp_name'])) throw new RuntimeException('Fichier Excel manquant.');
    $file = $_FILES['quote_file'];
    if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || (int) ($file['size'] ?? 0) > 5 * 1024 * 1024) throw new RuntimeException('Fichier invalide ou supérieur à 5 Mo.');
    $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load((string) $file['tmp_name'])->getSheet(0);
    $title = trim((string) $sheet->getCell('B3')->getFormattedValue());
    $pdo = \Database::getConnection();
    $suppliers = [];
    foreach ($pdo->query('SELECT id_fournisseur, code_fournisseur, COALESCE(NULLIF(TRIM(nom_commercial),""), raison_sociale) nom, telephone, contact_nom, email FROM fournisseur_banamur WHERE actif=1 AND validation_status="approved"')->fetchAll(PDO::FETCH_ASSOC) as $supplier) {
        $suppliers[(int) $supplier['id_fournisseur']] = $supplier;
    }
    $lines = [];
    $seenLines = [];
    $duplicates = 0;
    for ($row = 7; $row <= $sheet->getHighestDataRow(); $row++) {
        $newFormat = mb_strtolower(trim((string)$sheet->getCell('A6')->getFormattedValue())) === 'type';
        $type = trim((string)$sheet->getCell(($newFormat ? 'A' : 'F') . $row)->getFormattedValue()) ?: 'Bien';
        $categoryRaw = trim((string) $sheet->getCell(($newFormat ? 'B' : 'A') . $row)->getFormattedValue());
        $designation = trim((string) $sheet->getCell(($newFormat ? 'C' : 'B') . $row)->getFormattedValue());
        if ($categoryRaw === '' && $designation === '') continue;
        $category = FebCategoryMigrationService::normalize($categoryRaw);
        $quantity = (float) $sheet->getCell(($newFormat ? 'D' : 'C') . $row)->getCalculatedValue();
        $price = (float) $sheet->getCell(($newFormat ? 'F' : 'D') . $row)->getCalculatedValue();
        $supplierChoice = trim((string) $sheet->getCell("E$row")->getFormattedValue());
        if ($designation === '' || $quantity <= 0 || $price <= 0 || !in_array($type, ['Bien','Service'], true)) throw new RuntimeException("Données incomplètes à la ligne $row.");
        if (!preg_match('/^([1-9][0-9]*)\s*\|/', $supplierChoice, $match)) throw new RuntimeException("Sélectionnez un fournisseur dans la liste à la ligne $row.");
        $supplierId = (int) $match[1];
        $supplier = $suppliers[$supplierId] ?? null;
        if (!$supplier) throw new RuntimeException("Le fournisseur #$supplierId n'est plus actif ou approuvé (ligne $row).");
        $expectedChoice = $supplierId . ' | ' . (trim((string) $supplier['code_fournisseur']) !== '' ? trim((string) $supplier['code_fournisseur']) . ' | ' : '') . trim((string) $supplier['nom']);
        if ($supplierChoice !== $expectedChoice) throw new RuntimeException("La valeur fournisseur a été modifiée à la ligne $row. Utilisez exclusivement la liste proposée.");
        $dedupeKey=hash('sha256',mb_strtolower($type.'|'.$category.'|'.$designation.'|'.$quantity.'|'.$supplierId.'|'.$price,'UTF-8'));
        if(isset($seenLines[$dedupeKey])){$duplicates++;continue;}
        $seenLines[$dedupeKey]=$row;
        $lines[] = [
            'categorie'=>$category,
            'libelle'=>$designation,
            'quantite'=>$quantity,
            'type'=>$type,
            'has_fournisseur'=>true,
            'fournisseur_id'=>$supplierId,
            'fournisseur_nom'=>$supplier['nom'],
            'fournisseur_prix'=>$price,
            'fournisseur_tel'=>$supplier['telephone'] ?? '',
            'fournisseur_contact'=>trim(implode(' / ', array_filter([
                trim((string)($supplier['contact_nom'] ?? '')),
                trim((string)($supplier['email'] ?? '')),
            ]))),
        ];
    }
    if (!$lines) throw new RuntimeException('Aucune ligne à importer.');
    echo json_encode(['ok'=>true,'title'=>$title,'lines'=>$lines,'duplicates_ignored'=>$duplicates], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(422); echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
