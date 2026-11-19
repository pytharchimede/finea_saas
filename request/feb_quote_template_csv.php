<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();
require_once __DIR__ . '/../model/Database.php';

$filename = 'modele_devis_FEB.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'wb');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, [
    'Type',
    'Categorie',
    'Besoin',
    'Quantite',
    'Code fournisseur',
    'Prix unitaire FCFA',
], ';');
$exampleSupplierCode = '';
try {
    $exampleSupplierCode = (string) (\Database::getConnection()->query(
        'SELECT code_fournisseur FROM fournisseur_banamur
         WHERE actif=1 AND validation_status="approved"
           AND code_fournisseur IS NOT NULL AND TRIM(code_fournisseur)<>""
         ORDER BY nom_commercial,raison_sociale LIMIT 1'
    )->fetchColumn() ?: '');
} catch (Throwable $e) {
    $exampleSupplierCode = '';
}
fputcsv($out, ['Bien', 'ACHAT', 'Exemple à supprimer : équipements de protection', '10', $exampleSupplierCode, '5500'], ';');
fclose($out);
