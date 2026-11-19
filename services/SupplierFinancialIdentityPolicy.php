<?php
declare(strict_types=1);
require_once __DIR__.'/SupplierIdentity.php';

/** Identity-only adaptation of the existing FEB completion check. No workflow writes. */
final class SupplierFinancialIdentityPolicy
{
    public static function assertCompanyIdentity(array $supplier): void
    {
        if (($supplier['supplier_type'] ?? 'morale') !== 'morale') return;
        // Contact fields remain the responsibility of the existing completion form.
        $contactLabels=['Forme juridique','Dénomination','Représentant légal','Email','Téléphone','Adresse'];
        $missing=array_values(array_diff(SupplierIdentity::missing($supplier),$contactLabels));
        if ($missing) throw new DomainException('Régularisez l’identité sur la fiche fournisseur avant cette FEB : '.implode(', ', $missing).'.');
    }
}
