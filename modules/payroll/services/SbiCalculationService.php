<?php

class SbiCalculationService
{
    public function calculate(array $lines, array $items, array $params): array
    {
        return $this->calculateBase($lines, $items, $params, 'taxable_sbi');
    }

    protected function calculateBase(array $lines, array $items, array $params, string $flag): array
    {
        $total = 0.0;
        $details = [];

        foreach ($lines as $line) {
            $code = strtoupper((string) ($line['code'] ?? ''));
            $amount = payroll_ci_decimal($line['amount'] ?? 0);
            $item = $items[$code] ?? null;
            if ($item === null) {
                throw new RuntimeException('Rubrique inconnue: ' . $code);
            }

            $included = (int) ($item[$flag] ?? 0) === 1;
            $exemption = $this->resolveExemption($item, $line, $params, $amount);
            $taxableAmount = $included ? max(0.0, $amount - $exemption) : 0.0;
            $total += $taxableAmount;

            $details[] = [
                'code' => $code,
                'label' => (string) $item['label'],
                'amount' => $amount,
                'included' => $included,
                'exemption' => $exemption,
                'taxable_amount' => round($taxableAmount, 2),
                'exemption_type' => $item['exemption_type'] ?? null,
            ];
        }

        return ['amount' => round($total, 2), 'details' => $details];
    }

    private function resolveExemption(array $item, array $line, array $params, float $amount): float
    {
        $type = strtoupper((string) ($item['exemption_type'] ?? 'NONE'));
        $smigHourly = (float) ($params['SMIG_HOURLY'] ?? 0);
        $remunerationTotal = payroll_ci_decimal($line['remuneration_total'] ?? 0);
        $durationMonths = (int) ($line['duration_months'] ?? 0);

        $limit = 0.0;
        if ($type === 'FIXED_AMOUNT') {
            $limit = (float) ($item['ceiling_amount'] ?? 0);
        } elseif ($type === 'HOURLY_SMIG_MULTIPLE') {
            $limit = (float) ($item['ceiling_amount'] ?? 0) * $smigHourly;
        } elseif ($type === 'STAGE_ALLOWANCE') {
            $limit = $durationMonths <= 6 ? (float) ($item['ceiling_amount'] ?? 0) : 0.0;
        } elseif ($type === 'APPRENTICESHIP_ALLOWANCE') {
            $limit = $durationMonths <= 24 ? (float) ($item['ceiling_amount'] ?? 0) : 0.0;
        } elseif ($type === 'TOTAL') {
            $limit = $amount;
        } elseif ($type === 'TOTAL_REMUNERATION_PERCENT') {
            $limit = $remunerationTotal * ((float) ($item['ceiling_amount'] ?? 0) / 100);
        }

        return round(min($amount, max(0.0, $limit)), 2);
    }
}
