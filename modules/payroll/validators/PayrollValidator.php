<?php

class PayrollValidator
{
    public function validateInput(PayrollInputDto $input): void
    {
        if ($input->personnelId <= 0) {
            throw new RuntimeException('Le salarie est obligatoire.');
        }

        if ($input->salaryLines === []) {
            throw new RuntimeException('Aucune rubrique de paie a calculer.');
        }

        foreach ($input->salaryLines as $line) {
            if (payroll_ci_decimal($line['amount'] ?? 0) < 0) {
                throw new RuntimeException('Les montants de rubrique doivent etre positifs.');
            }
        }

        $parts = (float) ($input->familySituation['parts'] ?? 1);
        if ($parts > 5) {
            throw new RuntimeException('Le plafond de parts fiscales est limite a 5.');
        }
    }

    public function validateRates(array $rows): void
    {
        foreach ($rows as $row) {
            foreach (['rate', 'employee_rate', 'employer_rate'] as $field) {
                if (array_key_exists($field, $row) && (float) $row[$field] < 0) {
                    throw new RuntimeException('Un taux reglementaire ne peut pas etre negatif.');
                }
            }
        }
    }
}
