<?php

class PayrollInputDto
{
    public int $personnelId;
    public string $period;
    public string $countryCode;
    public ?string $ruleVersionCode;
    public string $currencyCode;
    public array $employee;
    public array $salaryLines;
    public array $familySituation;
    public array $context;

    public function __construct(array $payload)
    {
        $this->personnelId = (int) ($payload['personnel_id'] ?? 0);
        $this->period = preg_match('/^\d{4}-\d{2}$/', (string) ($payload['period'] ?? '')) ? (string) $payload['period'] : date('Y-m');
        $this->countryCode = strtoupper((string) ($payload['country_code'] ?? 'CI'));
        $this->ruleVersionCode = isset($payload['rule_version_code']) && trim((string) $payload['rule_version_code']) !== ''
            ? strtoupper(trim((string) $payload['rule_version_code']))
            : null;
        $this->currencyCode = strtoupper((string) ($payload['currency_code'] ?? 'FCFA'));
        $this->employee = is_array($payload['employee'] ?? null) ? $payload['employee'] : [];
        $this->salaryLines = is_array($payload['salary_lines'] ?? null) ? array_values($payload['salary_lines']) : [];
        $this->familySituation = is_array($payload['family_situation'] ?? null) ? $payload['family_situation'] : [];
        $this->context = is_array($payload['context'] ?? null) ? $payload['context'] : [];
    }
}
