<?php

class CnpsCalculationService
{
    public function calculate(float $sbs, float $sbi, array $contributions): array
    {
        $employeeTotal = 0.0;
        $employerTotal = 0.0;
        $details = [];

        foreach ($contributions as $row) {
            $baseCode = strtoupper((string) ($row['calculation_base'] ?? 'SBS'));
            $rawBase = $baseCode === 'SBI' ? $sbi : $sbs;
            $ceiling = $row['ceiling'] !== null ? (float) $row['ceiling'] : null;
            $base = $ceiling !== null ? min($rawBase, $ceiling) : $rawBase;
            $employeeFixedAmount = (float) ($row['employee_fixed_amount'] ?? 0);
            $employerFixedAmount = (float) ($row['employer_fixed_amount'] ?? 0);
            $employee = round(($base * ((float) $row['employee_rate'] / 100)) + $employeeFixedAmount, 2);
            $employer = round(($base * ((float) $row['employer_rate'] / 100)) + $employerFixedAmount, 2);
            $employeeTotal += $employee;
            $employerTotal += $employer;

            $details[] = [
                'code' => (string) $row['code'],
                'label' => (string) $row['label'],
                'calculation_base' => $baseCode,
                'raw_base' => round($rawBase, 2),
                'ceiling' => $ceiling,
                'applied_base' => round($base, 2),
                'employee_rate' => (float) $row['employee_rate'],
                'employer_rate' => (float) $row['employer_rate'],
                'employee_fixed_amount' => $employeeFixedAmount,
                'employer_fixed_amount' => $employerFixedAmount,
                'employee_amount' => $employee,
                'employer_amount' => $employer,
            ];
        }

        return [
            'employee_total' => round($employeeTotal, 2),
            'employer_total' => round($employerTotal, 2),
            'details' => $details,
        ];
    }
}
