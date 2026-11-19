<?php

class TaxCalculationService
{
    public function calculate(float $sbi, array $brackets, array $params): array
    {
        $abatementRate = ((float) ($params['TAX_ABATEMENT_RATE'] ?? 0)) / 100;
        $base = round(max(0.0, $sbi - ($sbi * $abatementRate)), 2);
        $tax = 0.0;
        $details = [];

        foreach ($brackets as $bracket) {
            $min = (float) $bracket['min_amount'];
            $max = $bracket['max_amount'] !== null ? (float) $bracket['max_amount'] : null;
            $rate = (float) $bracket['rate'];
            if ($base <= $min) {
                continue;
            }

            $upper = $max === null ? $base : min($base, $max);
            $slice = max(0.0, $upper - $min);
            $amount = round($slice * ($rate / 100), 2);
            $tax += $amount;
            $details[] = [
                'min_amount' => $min,
                'max_amount' => $max,
                'rate' => $rate,
                'slice_amount' => round($slice, 2),
                'tax_amount' => $amount,
            ];
        }

        return [
            'base_imposable' => $base,
            'gross_tax' => round($tax, 2),
            'brackets' => $details,
            'abatement_rate' => round($abatementRate * 100, 2),
        ];
    }
}
