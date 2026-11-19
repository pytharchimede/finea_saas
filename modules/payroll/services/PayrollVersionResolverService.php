<?php

class PayrollVersionResolverService
{
    private PayrollRuleRepository $rules;

    public function __construct(PayrollRuleRepository $rules)
    {
        $this->rules = $rules;
    }

    public function resolve(PayrollInputDto $input): array
    {
        $version = $this->rules->getActiveVersion($input->countryCode, $input->period, $input->ruleVersionCode);
        if ($version === null) {
            throw new RuntimeException('Aucune version reglementaire active pour la periode demandee.');
        }

        return $version;
    }
}
