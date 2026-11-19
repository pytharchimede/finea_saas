<?php

class RicfCalculationService
{
    public function calculate(array $familySituation, array $familyRules, array $params): array
    {
        $declaredParts = (float) ($familySituation['parts'] ?? 0);
        $situation = strtoupper((string) ($familySituation['situation_code'] ?? 'SINGLE'));
        $children = (int) ($familySituation['children_count'] ?? 0);
        $disabledChildren = (int) ($familySituation['disabled_children_count'] ?? 0);
        $maxParts = (float) ($params['RICF_MAX_PARTS'] ?? 5);
        $unitAmount = (float) ($params['RICF_UNIT_AMOUNT'] ?? 0);

        if ($declaredParts <= 0) {
            $baseParts = 1.0;
            foreach ($familyRules as $rule) {
                if (strtoupper((string) $rule['situation_code']) === $situation) {
                    $baseParts = (float) $rule['base_parts'];
                    break;
                }
            }
            $declaredParts = $baseParts + ($children * 0.5) + ($disabledChildren * 0.5);
        }

        $parts = min($maxParts, max(1.0, $declaredParts));
        $ricf = round((($parts - 1) / 0.5) * $unitAmount, 2);

        return [
            'situation_code' => $situation,
            'children_count' => $children,
            'disabled_children_count' => $disabledChildren,
            'parts' => $parts,
            'max_parts' => $maxParts,
            'amount' => $ricf,
        ];
    }
}
