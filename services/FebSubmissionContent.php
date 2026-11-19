<?php
declare(strict_types=1);

final class FebSubmissionContent
{
    /** Keep a useful downstream description without asking for a separate narrative step. */
    public static function description(string $title, array $needs, string $provided = '', string $historical = ''): string
    {
        if (trim($provided) !== '') return trim($provided);
        if (trim($historical) !== '') return trim($historical);
        $labels = [];
        foreach ($needs as $need) {
            $label = trim((string)($need['libelle'] ?? ''));
            if ($label !== '') $labels[] = $label;
        }
        return trim(trim($title)."\n".implode(' ; ', $labels));
    }

    public static function total(array $needs): float
    {
        $total = 0.0;
        foreach ($needs as $need) {
            $quantity = (float)($need['quantite'] ?? 0);
            $price = (float)($need['fournisseur']['prix_unitaire'] ?? 0);
            if (!is_finite($quantity) || !is_finite($price) || $quantity < 0 || $price < 0) {
                throw new DomainException('Quantité ou prix invalide.');
            }
            $total += $quantity * $price;
        }
        if (!is_finite($total)) throw new DomainException('Montant de la FEB invalide.');
        return round($total, 2);
    }
}
