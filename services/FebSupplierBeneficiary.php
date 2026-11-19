<?php
declare(strict_types=1);
require_once __DIR__.'/SupplierPaymentGuard.php';

/** Only the beneficiary explicitly selected in the approved FEB grants this path. */
final class FebSupplierBeneficiary
{
    public static function resolve(PDO $pdo, array $request): ?array
    {
        $payload = $request['payload'] ?? json_decode((string)($request['payload_json'] ?? ''), true);
        $draft = (array)($payload['provisional_decaissement'] ?? []);
        $mode = strtolower(trim((string)($draft['beneficiary_mode'] ?? '')));
        if ($mode === '') $mode = strtolower(trim((string)($draft['beneficiary_choice'] ?? $payload['beneficiary_choice'] ?? '')));
        if ($mode !== 'supplier') return null;
        $id = (int)($draft['fournisseur_id'] ?? $payload['fournisseur_id'] ?? $payload['global_fournisseur_id'] ?? 0);
        $q = $pdo->prepare('SELECT * FROM fournisseur_banamur WHERE id_fournisseur=? LIMIT 1');
        $q->execute([$id]);
        $supplier = $q->fetch(PDO::FETCH_ASSOC);
        if (!$supplier) throw new DomainException('Fournisseur bénéficiaire de la FEB introuvable.');
        $missing = SupplierPaymentGuard::missing($pdo, $supplier);
        if ($missing) throw new DomainException('Fournisseur bénéficiaire à régulariser : '.implode(' ; ', $missing).'.');
        if (trim((string)($supplier['nom_commercial'] ?? '')) === '') {
            $supplier['nom_commercial'] = trim((string)($supplier['person_last_name'] ?? '').' '.(string)($supplier['person_first_name'] ?? ''));
        }
        return $supplier;
    }

    public static function requiresIdentity(array $supplier): bool
    {
        return ($supplier['supplier_type'] ?? '') !== 'morale';
    }
}
