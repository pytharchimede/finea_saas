<?php

function payroll_ci_money(float $amount): string
{
    return number_format($amount, 0, ',', ' ') . ' FCFA';
}

function payroll_ci_decimal($value): float
{
    if (is_string($value)) {
        $value = str_replace([' ', ','], ['', '.'], $value);
    }

    return round(max(0.0, (float) $value), 2);
}

function payroll_ci_json(array $payload): string
{
    return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
}
