<?php

require_once __DIR__ . '/../services/RhPayrollService.php';

class RhPayrollController
{
    private RhPayrollService $service;

    public function __construct(PDO $pdo)
    {
        $this->service = new RhPayrollService(new RhPayrollRepository($pdo));
    }

    public function service(): RhPayrollService
    {
        return $this->service;
    }

    public function model(): PayrollManager
    {
        return $this->service->model();
    }

    public function payrollFormView(array $request): array
    {
        return $this->service->buildPayrollFormView($request);
    }

    public function holidayView(array $query): array
    {
        return $this->service->buildHolidayView($query);
    }

    public function saveHoliday(string $holidayDate, string $label): void
    {
        $this->service->saveHoliday($holidayDate, $label);
    }

    public function deleteHoliday(int $holidayId): void
    {
        $this->service->deleteHoliday($holidayId);
    }

    public function monthlyAttendanceView(array $request): array
    {
        return $this->service->buildMonthlyAttendanceView($request);
    }

    public function handleContractRulesPost(array $post, array $actor): array
    {
        return $this->service->saveContractRules((array) ($post['rules'] ?? []), $actor);
    }

    public function handlePayrollPost(array $post, array $baseFormData, string $period, int $selectedPersonnelId, array $actor): array
    {
        $action = (string) ($post['submit_action'] ?? 'calculate');
        $personnelId = (int) ($post['personnel_id'] ?? $selectedPersonnelId);
        $requestedPeriod = preg_match('/^\d{4}-\d{2}$/', (string) ($post['period'] ?? ''))
            ? (string) $post['period']
            : $period;

        $view = $this->service->buildPayrollFormView([
            'personnel_id' => $personnelId,
            'period' => $requestedPeriod,
        ]);
        $activeContract = is_array($view['contractPayrollView']['contract'] ?? null)
            ? $view['contractPayrollView']['contract']
            : null;

        if (in_array($action, ['save_employee_contract', 'save_contract_salary_component'], true)) {
            return array_merge($view, [
                'calculation' => null,
                'savedRecordId' => null,
                'message' => null,
                'errorMessage' => 'Le contrat du salarié est en lecture seule pendant l’édition de la paie. Ouvrez « Contrats des employés » pour modifier le contrat, sa rémunération ou ses éléments récurrents.',
            ]);
        }

        $actionsRequiringContract = [
            'calculate',
            'save',
            'save_payroll_adjustment',
            'approve_payroll_adjustment',
            'reject_payroll_adjustment',
        ];
        if ($personnelId > 0 && in_array($action, $actionsRequiringContract, true) && $activeContract === null) {
            return array_merge($view, [
                'calculation' => null,
                'savedRecordId' => null,
                'message' => null,
                'errorMessage' => 'Aucun contrat actif ne couvre la période sélectionnée. Définissez d’abord le contrat du salarié dans « Contrats des employés » avant de préparer sa paie.',
            ]);
        }

        if ($activeContract !== null) {
            /*
             * Le contrat associé est la source de vérité. Même si un utilisateur
             * manipule le POST dans le navigateur, la paie conserve le type et
             * les montants provenant du contrat actif chargé côté serveur.
             */
            $post['contract_code'] = (string) ($activeContract['contract_reference'] ?? 'AUTRE');
            $post['salary_category'] = (float) ($activeContract['base_salary'] ?? 0);
            $post['over_salary'] = (float) ($activeContract['over_salary'] ?? 0);
            $post['transport_locality_code'] = (string) ($activeContract['transport_locality_code'] ?? '');
            $baseFormData['contract_code'] = $post['contract_code'];
            $baseFormData['salary_category'] = $post['salary_category'];
            $baseFormData['over_salary'] = $post['over_salary'];
            $baseFormData['transport_locality_code'] = $post['transport_locality_code'];
        }

        return $this->service->handlePayrollPost($post, $baseFormData, $period, $selectedPersonnelId, $actor);
    }
}
