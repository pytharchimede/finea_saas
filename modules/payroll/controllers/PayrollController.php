<?php

class PayrollController
{
    private PayrollRuleRepository $rules;
    private PayrollSlipRepository $slips;
    private PayrollCalculationService $calculator;
    private PayrollSlipGenerationService $generator;
    private PayrollBulkGenerationService $bulkGenerator;

    public function __construct(PDO $pdo)
    {
        $this->rules = new PayrollRuleRepository($pdo);
        $this->slips = new PayrollSlipRepository($pdo);
        $this->calculator = new PayrollCalculationService($this->rules);
        $this->generator = new PayrollSlipGenerationService($this->calculator, $this->slips, new PayrollAuditService());
        $this->bulkGenerator = new PayrollBulkGenerationService(
            new EmployeeContractService(new EmployeeContractRepository($pdo)),
            $this->slips,
            $this->generator
        );
    }

    public function preview(array $payload): array
    {
        return $this->calculator->calculate(new PayrollInputDto($payload));
    }


    public function generate(array $payload, array $actor = []): int
    {
        return $this->generator->generate(new PayrollInputDto($payload), $actor);
    }

    public function generateBulk(array $payload, array $actor = []): array
    {
        $period = (string) ($payload['payroll_period'] ?? $payload['period'] ?? '');
        $personnelIds = null;
        if (isset($payload['personnel_ids']) && is_array($payload['personnel_ids'])) {
            $personnelIds = array_map('intval', $payload['personnel_ids']);
        }

        return $this->bulkGenerator->generateForPeriod($period, $personnelIds, isset($actor['user_id']) ? (int) $actor['user_id'] : null);
    }

    public function slip(int $id): ?array
    {
        return $this->slips->findSlip($id);
    }

    public function adminViewData(string $period, int $selectedVersionId = 0): array
    {
        $version = $selectedVersionId > 0 ? $this->rules->getVersionById($selectedVersionId) : $this->rules->getActiveVersion('CI', $period);
        $versionId = (int) ($version['id'] ?? 0);

        return [
            'version' => $version,
            'versions' => $this->rules->listVersions(),
            'versionHasSlips' => $versionId > 0 && $this->rules->versionHasSlips($versionId),
            'salaryItems' => $versionId > 0 ? $this->rules->getSalaryItems($versionId, false) : [],
            'taxBrackets' => $versionId > 0 ? $this->rules->getTaxBrackets($versionId) : [],
            'socialContributions' => $versionId > 0 ? $this->rules->getSocialContributions($versionId, false) : [],
            'transportRates' => $versionId > 0 ? $this->rules->getTransportRates($versionId, false) : [],
            'parameters' => $versionId > 0 ? $this->rules->getParameterRows($versionId) : [],
            'slips' => $this->slips->listRecentSlips(25),
            'auditEvents' => $this->slips->listRecentAuditEvents(25),
            'periodLocks' => $this->slips->listPeriodLocks(24),
        ];
    }

    public function cloneVersion(array $post, array $actor = []): int
    {
        $sourceVersionId = (int) ($post['source_version_id'] ?? 0);
        $label = trim((string) ($post['label'] ?? ''));
        $effectiveDate = trim((string) ($post['effective_date'] ?? ''));
        $code = strtoupper(trim((string) ($post['code'] ?? '')));
        if ($code === '') {
            $code = 'PAYROLL_CI_' . preg_replace('/[^0-9]/', '', $effectiveDate);
        }
        $endDate = trim((string) ($post['end_date'] ?? ''));
        $newVersionId = $this->rules->cloneVersion($sourceVersionId, $code, $label, $effectiveDate, $endDate !== '' ? $endDate : null, !empty($post['active']));

        (new PayrollAuditService())->log('rule_version_created', [
            'version_id' => $newVersionId,
            'source_version_id' => $sourceVersionId,
            'label' => $label,
            'code' => $code,
            'effective_date' => $effectiveDate,
        ], $actor);

        return $newVersionId;
    }

    public function lockPeriod(array $post, array $actor = []): void
    {
        $period = trim((string) ($post['payroll_period'] ?? ''));
        $countryCode = strtoupper(trim((string) ($post['country_code'] ?? 'CI')));
        $this->slips->lockPeriod($countryCode, $period, $actor);

        (new PayrollAuditService())->log('period_locked', [
            'label' => 'Cloture periode ' . $period,
            'country_code' => $countryCode,
            'payroll_period' => $period,
        ], $actor);
    }

    public function saveSetting(array $post, array $actor = []): void
    {
        $action = (string) ($post['action'] ?? '');
        $auditAction = '';
        if ($action === 'save_salary_item') {
            $diff = $this->rules->saveSalaryItem($post);
            $auditAction = 'salary_item_saved';
        } elseif ($action === 'save_tax_bracket') {
            $diff = $this->rules->saveTaxBracket($post);
            $auditAction = 'tax_bracket_saved';
        } elseif ($action === 'save_social_contribution') {
            $diff = $this->rules->saveSocialContribution($post);
            $auditAction = 'social_contribution_saved';
        } elseif ($action === 'save_transport_rate') {
            $diff = $this->rules->saveTransportRate($post);
            $auditAction = 'transport_rate_saved';
        } elseif ($action === 'save_parameter') {
            $diff = $this->rules->saveParameter($post);
            $auditAction = 'parameter_saved';
        } else {
            throw new RuntimeException('Action de parametrage inconnue.');
        }

        (new PayrollAuditService())->log($auditAction, [
            'label' => 'Parametrage paie',
            'version_id' => (int) ($post['version_id'] ?? 0),
            'before' => $diff['before'] ?? null,
            'after' => $diff['after'] ?? null,
        ], $actor);
    }
}
