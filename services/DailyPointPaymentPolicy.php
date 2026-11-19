<?php
declare(strict_types=1);

/** Read-only protection shared by total, partial and bulk payment entry points. */
final class DailyPointPaymentPolicy
{
    public static function assertItem(array $item, array $fiche, float $amount): void
    {
        if (($item['point_status'] ?? '') !== 'certified' || empty($item['certified_at']) || empty($item['dg_signed'])) {
            throw new DomainException('La signature du DG sur le point journalier est requise avant paiement.');
        }
        $authorized = (float)($item['amount'] ?? 0);
        if (!is_finite($amount) || $amount <= 0 || !is_finite($authorized) || $authorized <= 0 || abs($amount - $authorized) > 0.001) {
            throw new DomainException('Le montant à décaisser doit correspondre au montant signé dans le point journalier.');
        }
        $snapshot = json_decode((string)($item['snapshot_json'] ?? ''), true);
        if (!is_array($snapshot)) throw new DomainException('Instantané du point journalier illisible : faites réviser le point.');
        foreach (['fournisseur_id','personnel_id','feb_request_id','chantier_id','ligne_debourse_id','contract_id','contract_payment_id'] as $field) {
            if ((int)($snapshot[$field] ?? 0) !== (int)($fiche[$field] ?? 0)) {
                throw new DomainException('Le bénéficiaire ou l’affectation a changé depuis l’arrêt du point : faites réviser le point.');
            }
        }
        foreach (['beficiaire_fiche','tel_beneficiaire_fiche','code_autorisation_feb','designation_fiche','precision_fiche'] as $field) {
            if (trim((string)($snapshot[$field] ?? '')) !== trim((string)($fiche[$field] ?? ''))) {
                throw new DomainException('La fiche a changé depuis l’arrêt du point : faites réviser le point.');
            }
        }
        if (abs((float)($snapshot['montant_fiche'] ?? 0) - (float)($fiche['montant_fiche'] ?? 0)) > 0.001) {
            throw new DomainException('Le montant de la fiche a changé depuis l’arrêt du point : faites réviser le point.');
        }
    }

    public static function assertPayment(PDO $pdo, array $fiche, float $amount): void
    {
        // Older installations may not have activated daily points yet. Once the
        // table exists, query failures must propagate and refuse the payment.
        $q = $pdo->query("SHOW TABLES LIKE 'daily_disbursement_points'");
        if (!$q->fetchColumn()) return;
        $q = $pdo->prepare("SELECT i.*,p.status point_status,p.certified_at,
            EXISTS(SELECT 1 FROM daily_disbursement_point_signatures s WHERE s.point_id=p.id AND s.role_code='dg' AND COALESCE(s.signature_path,'')<>'') dg_signed
            FROM daily_disbursement_point_items i JOIN daily_disbursement_points p ON p.id=i.point_id
            WHERE i.fiche_number=? AND p.status IN ('stopped','certified') ORDER BY p.id DESC LIMIT 1");
        $q->execute([(string)($fiche['num_fiche'] ?? '')]);
        $item = $q->fetch(PDO::FETCH_ASSOC);
        if ($item) self::assertItem($item, $fiche, $amount);
    }
}
