<?php
declare(strict_types=1);

$path = __DIR__.'/../fournisseur/regularisations_fournisseurs.php';
$source = file_get_contents($path);

if ($source === false) {
    throw new RuntimeException('Écran de régularisation introuvable.');
}

$checks = [
    'Ajout individuel du justificatif' =>
        str_contains($source, '$regularizationManager->attachPaymentProof('),

    'Bypass individuel' =>
        str_contains($source, '->bypassOne('),

    'Absence de validation globale implicite' =>
        !str_contains($source, '->regularizeSupplier('),

    'Absence de déblocage global direct' =>
        !str_contains($source, '->unblock('),
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        throw new RuntimeException('ÉCHEC : '.$label);
    }
    echo "OK : $label\n";
}

echo "SupplierRegularizationScopeTest: OK\n";
