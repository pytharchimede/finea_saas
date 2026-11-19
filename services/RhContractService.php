<?php

require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../repositories/RhContractRepository.php';

class RhContractService
{
    private RhContractRepository $repository;

    public function __construct(RhContractRepository $repository)
    {
        $this->repository = $repository;
    }

    public function model(): RhContractManager
    {
        return $this->repository->model();
    }

    public function saveSettings(array $settingsPayload, array $actor): array
    {
        try {
            $saved = $this->repository->model()->saveSettings($settingsPayload, $actor);
            \AuditTrail::log('rh_contract_settings_saved', [
                'module' => 'rh',
                'action' => 'save_contract_settings',
                'entity_type' => 'contract_settings',
                'entity_label' => 'parametrage_contrat_rh',
                'user_id' => $actor['user_id'] ?? null,
                'user_name' => $actor['user_name'] ?? null,
                'details' => [
                    'saved' => $saved,
                    'settings_count' => count($settingsPayload),
                    'setting_keys' => array_keys($settingsPayload),
                ],
            ]);

            return [
                'message' => $saved ? 'Les parametres documentaires RH ont ete enregistres.' : 'Aucune mise a jour effectuee.',
                'errorMessage' => null,
            ];
        } catch (Throwable $exception) {
            \AuditTrail::log('rh_contract_settings_failed', [
                'module' => 'rh',
                'action' => 'save_contract_settings',
                'entity_type' => 'contract_settings',
                'entity_label' => 'parametrage_contrat_rh',
                'user_id' => $actor['user_id'] ?? null,
                'user_name' => $actor['user_name'] ?? null,
                'result' => 'failure',
                'severity' => 'warning',
                'details' => ['error' => $exception->getMessage()],
            ]);

            return ['message' => null, 'errorMessage' => $exception->getMessage()];
        }
    }

    public function __call(string $name, array $arguments)
    {
        return $this->repository->{$name}(...$arguments);
    }
}
