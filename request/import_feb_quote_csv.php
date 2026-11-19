<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/FebCategoryMigrationService.php';
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();
require_once __DIR__ . '/../model/Database.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !isset($_FILES['quote_file'])) {
        throw new RuntimeException('Donne-moi le fichier CSV complété.');
    }
    $file = (array) $_FILES['quote_file'];
    $name = trim((string) ($file['name'] ?? ''));
    if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'csv') {
        throw new RuntimeException('Ton fichier n’est pas un CSV. Enregistre-le au format CSV puis réessaie.');
    }
    if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Je n’ai pas réussi à recevoir ce fichier. Réessaie, s’il te plaît.');
    }
    if ((int) ($file['size'] ?? 0) <= 0 || (int) ($file['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new RuntimeException('Le fichier CSV doit peser moins de 5 Mo.');
    }

    $handle = fopen((string) $file['tmp_name'], 'rb');
    if (!$handle) {
        throw new RuntimeException('Je n’arrive pas à lire ce fichier CSV.');
    }
    $firstLine = (string) fgets($handle);
    rewind($handle);
    $delimiter = substr_count($firstLine, ';') >= substr_count($firstLine, ',') ? ';' : ',';
    $headers = fgetcsv($handle, 0, $delimiter);
    if (!is_array($headers)) {
        throw new RuntimeException('Le fichier CSV est vide.');
    }
    $headers = array_map(static function ($value): string {
        $value = preg_replace('/^\xEF\xBB\xBF/', '', trim((string) $value)) ?? trim((string) $value);
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', mb_strtolower($value, 'UTF-8'));
        return preg_replace('/[^a-z0-9]+/', '_', (string) $ascii) ?: '';
    }, $headers);
    $required = ['type', 'categorie', 'besoin', 'quantite', 'code_fournisseur', 'prix_unitaire_fcfa'];
    foreach ($required as $column) {
        if (!in_array($column, $headers, true)) {
            throw new RuntimeException('La colonne « ' . str_replace('_', ' ', $column) . ' » manque dans le CSV. Utilise le modèle proposé.');
        }
    }

    $pdo = \Database::getConnection();
    $supplierRows = $pdo->query('SELECT id_fournisseur,code_fournisseur,COALESCE(NULLIF(TRIM(nom_commercial),""),raison_sociale) nom,telephone,contact_nom,email FROM fournisseur_banamur WHERE actif=1 AND validation_status="approved"')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $suppliers = [];
    foreach ($supplierRows as $supplier) {
        $code = mb_strtoupper(trim((string) ($supplier['code_fournisseur'] ?? '')), 'UTF-8');
        if ($code !== '') $suppliers[$code] = $supplier;
    }

    $lines = [];
    $rowNumber = 1;
    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
        $rowNumber++;
        if (!array_filter($row, static fn($value): bool => trim((string) $value) !== '')) continue;
        $data = [];
        foreach ($headers as $index => $header) $data[$header] = trim((string) ($row[$index] ?? ''));
        $type = mb_convert_case(mb_strtolower($data['type'] ?? '', 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
        $categoryRaw = trim((string) ($data['categorie'] ?? ''));
        $categoryLookup = [
            'rh' => 'RH',
            'logistique' => 'Logistique',
            'hse' => 'HSE',
            'achat' => 'ACHAT',
            'bureau' => 'Bureau',
        ];
        $category = $categoryLookup[mb_strtolower($categoryRaw, 'UTF-8')] ?? '';
        $label = trim((string) ($data['besoin'] ?? ''));
        $quantity = (float) str_replace(',', '.', str_replace(' ', '', (string) ($data['quantite'] ?? '')));
        $price = (float) str_replace(',', '.', str_replace(' ', '', (string) ($data['prix_unitaire_fcfa'] ?? '')));
        $supplierCode = mb_strtoupper(trim((string) ($data['code_fournisseur'] ?? '')), 'UTF-8');
        $supplier = $suppliers[$supplierCode] ?? null;
        if (!in_array($type, ['Bien', 'Service'], true)) throw new RuntimeException("Ligne $rowNumber : le type doit être Bien ou Service.");
        if ($category === '') throw new RuntimeException("Ligne $rowNumber : la catégorie doit être RH, Logistique, HSE, ACHAT ou Bureau.");
        if ($label === '') throw new RuntimeException("Ligne $rowNumber : indique le besoin.");
        if ($quantity <= 0 || floor($quantity) !== $quantity) throw new RuntimeException("Ligne $rowNumber : la quantité doit être un nombre entier supérieur à zéro.");
        if ($price <= 0) throw new RuntimeException("Ligne $rowNumber : le prix unitaire doit être supérieur à zéro.");
        if (!$supplier) throw new RuntimeException("Ligne $rowNumber : le fournisseur « $supplierCode » n’est pas actif ou approuvé.");
        $lines[] = [
            'type' => $type,
            'categorie' => $category,
            'libelle' => $label,
            'quantite' => $quantity,
            'fournisseur_id' => (int) $supplier['id_fournisseur'],
            'fournisseur_nom' => (string) $supplier['nom'],
            'fournisseur_prix' => $price,
            'fournisseur_tel' => (string) ($supplier['telephone'] ?? ''),
        ];
        if (count($lines) > 100) throw new RuntimeException('Le CSV ne peut pas contenir plus de 100 besoins.');
    }
    fclose($handle);
    if (!$lines) throw new RuntimeException('Je n’ai trouvé aucun besoin dans ce fichier.');
    echo json_encode(['ok' => true, 'lines' => $lines], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
