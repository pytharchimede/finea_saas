<?php
declare(strict_types=1);

$service = file_get_contents(
    __DIR__.'/../services/SupplierDisbursementUnblockService.php'
);
$manager = file_get_contents(
    __DIR__.'/../model/DisbursementRegularizationManager.php'
);

if ($service === false || $manager === false) {
    throw new RuntimeException('Sources introuvables.');
}

function assertMultiFeb(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$start = strpos($service, 'public function bypassOne(');
assertMultiFeb($start !== false, 'Méthode bypassOne absente.');

$method = substr($service, $start);

assertMultiFeb(
    str_contains($method, 'WHERE id=:id'),
    'Le bypass individuel ne cible pas explicitement un dossier.'
);

assertMultiFeb(
    str_contains($method, 'r.cancelled_at IS NULL'),
    'Les régularisations actives ne sont pas filtrées.'
);

assertMultiFeb(
    str_contains($method, 'if($left===0)'),
    'Le fournisseur pourrait être débloqué malgré des dossiers incomplets.'
);

assertMultiFeb(
    str_contains($method, 'regularization_bypass_one'),
    'La trace d’audit du bypass individuel est absente.'
);

$attach = strpos($manager, 'public function attachPaymentProof(');
assertMultiFeb($attach !== false, 'Méthode attachPaymentProof absente.');

$attachMethod = substr(
    $manager,
    $attach,
    strpos($manager, 'private function ensureProofStorage(', $attach) - $attach
);

assertMultiFeb(
    str_contains($attachMethod, 'WHERE BINARY num_fiche=BINARY :num'),
    'Le justificatif ne cible pas explicitement une FEB.'
);

assertMultiFeb(
    str_contains($attachMethod, 'if((int)$remaining->fetchColumn()===0)'),
    'Le déblocage ne vérifie pas les dossiers restants.'
);

echo "SupplierRegularizationMultiFebTest: OK (portée SQL, blocage et audit)\n";
