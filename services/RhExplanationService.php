<?php

require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../repositories/RhExplanationRepository.php';

class RhExplanationService
{
    private RhExplanationRepository $repository;

    public function __construct(RhExplanationRepository $repository)
    {
        $this->repository = $repository;
    }

    public function model(): RhExplanationRequestManager
    {
        return $this->repository->model();
    }

    public function notifications(): NotificationController
    {
        return $this->repository->notifications();
    }

    public function __call(string $name, array $arguments)
    {
        return $this->repository->{$name}(...$arguments);
    }

    public function handleRequestPost(array $post, array $actor, string $filter): array
    {
        try {
            $action = trim((string) ($post['action'] ?? ''));
            if ($action === 'create_request') {
                $requestId = $this->repository->model()->createRequest($actor, [
                    'target_personnel_id' => (int) ($post['target_personnel_id'] ?? 0),
                    'subject' => (string) ($post['subject'] ?? ''),
                    'is_cadre_direct' => !empty($post['is_cadre_direct']) ? 1 : 0,
                    'due_days' => (int) ($post['due_days'] ?? 0),
                    'incident_date' => (string) ($post['incident_date'] ?? ''),
                    'incident_place' => (string) ($post['incident_place'] ?? ''),
                    'context_summary' => (string) ($post['context_summary'] ?? ''),
                    'observed_facts' => (string) ($post['observed_facts'] ?? ''),
                    'expected_response' => (string) ($post['expected_response'] ?? ''),
                    'supporting_elements' => (string) ($post['supporting_elements'] ?? ''),
                ]);

                return ['redirect' => 'demandes_explication_rh.php?created=' . $requestId . '&id=' . $requestId, 'statusType' => null, 'statusMessage' => null];
            }

            $requestId = (int) ($post['request_id'] ?? 0);
            if ($requestId > 0 && $action === 'request_complement') {
                $this->repository->model()->requestComplement($requestId, $actor, (string) ($post['message_html'] ?? ''), (int) ($post['due_days'] ?? 0));
                return ['redirect' => 'demandes_explication_rh.php?updated=1&id=' . $requestId, 'statusType' => null, 'statusMessage' => null];
            }
            if ($requestId > 0 && $action === 'close_request') {
                $this->repository->model()->closeRequest($requestId, $actor, (string) ($post['message_html'] ?? ''), [
                    'fault_level_id' => (int) ($post['fault_level_id'] ?? 0),
                    'sanction_level_id' => (int) ($post['sanction_level_id'] ?? 0),
                ]);
                return ['redirect' => 'demandes_explication_rh.php?closed=1&id=' . $requestId, 'statusType' => null, 'statusMessage' => null];
            }
            if ($requestId > 0 && $action === 'cancel_request') {
                $this->repository->model()->cancelRequest($requestId, $actor, (string) ($post['message_html'] ?? ''));
                return ['redirect' => 'demandes_explication_rh.php?cancelled=1&id=' . $requestId, 'statusType' => null, 'statusMessage' => null];
            }
            if ($requestId > 0 && $action === 'save_rh_signature') {
                $this->repository->model()->saveRequestSignature($requestId, 'rh', $actor, (string) ($post['signature_base64'] ?? ''));
                return ['redirect' => 'demandes_explication_rh.php?signed=1&id=' . $requestId, 'statusType' => null, 'statusMessage' => null];
            }
            if ($requestId > 0 && $action === 'resend_pdf') {
                $this->repository->model()->resendRequestPdf($requestId, $actor);
                return ['redirect' => 'demandes_explication_rh.php?resent=1&id=' . $requestId, 'statusType' => null, 'statusMessage' => null];
            }
            if ($requestId > 0 && $action === 'delete_request_admin') {
                $this->repository->model()->deleteRequest($requestId, $actor, (string) ($post['delete_reason'] ?? ''));
                return ['redirect' => 'demandes_explication_rh.php?deleted=1&status=' . urlencode($filter), 'statusType' => null, 'statusMessage' => null];
            }
        } catch (Throwable $e) {
            return ['redirect' => null, 'statusType' => 'error', 'statusMessage' => $e->getMessage()];
        }

        return ['redirect' => null, 'statusType' => 'error', 'statusMessage' => 'Action non reconnue.'];
    }

    public function handleHierarchyPost(array $post): array
    {
        try {
            $action = trim((string) ($post['action'] ?? ''));
            if ($action === 'save_settings') {
                $this->repository->model()->saveSettings([
                    'dg_user_id' => (int) ($post['dg_user_id'] ?? 0),
                    'response_due_days' => (int) ($post['response_due_days'] ?? 3),
                    'template_intro' => (string) ($post['template_intro'] ?? ''),
                    'template_context_label' => (string) ($post['template_context_label'] ?? ''),
                    'template_facts_label' => (string) ($post['template_facts_label'] ?? ''),
                    'template_expectations_label' => (string) ($post['template_expectations_label'] ?? ''),
                    'template_supporting_label' => (string) ($post['template_supporting_label'] ?? ''),
                    'template_closing' => (string) ($post['template_closing'] ?? ''),
                    'employee_response_hint' => (string) ($post['employee_response_hint'] ?? ''),
                ]);

                \AuditTrail::log('rh_explanation_settings_updated', [
                    'module' => 'rh',
                    'action' => 'save_explanation_settings',
                    'entity_type' => 'rh_explanation_settings',
                    'entity_label' => 'workflow',
                ]);

                return ['redirect' => 'parametrage_hierarchie_rh.php?saved=1', 'statusType' => null, 'statusMessage' => null];
            }

            if ($action === 'save_service') {
                $serviceId = (int) ($post['service_id'] ?? 0);
                $existingRowsByService = [];
                foreach ($this->repository->model()->getServiceHierarchyRows() as $existingRow) {
                    $existingRowsByService[(int) ($existingRow['service_id'] ?? 0)] = $existingRow;
                }
                $previousRow = $existingRowsByService[$serviceId] ?? null;

                $newDirectionLabel = array_key_exists('direction_label', $post)
                    ? (string) ($post['direction_label'] ?? '')
                    : (string) ($previousRow['direction_label'] ?? '');
                $newDirectManagerUserId = (int) ($post['direct_manager_user_id'] ?? 0);
                $newDepartmentHeadUserId = (int) ($post['department_head_user_id'] ?? 0);
                $newContractSignerUserId = (int) ($post['contract_signer_user_id'] ?? 0);

                $this->repository->model()->saveServiceHierarchy($serviceId, [
                    'direction_label' => $newDirectionLabel,
                    'direct_manager_user_id' => $newDirectManagerUserId,
                    'department_head_user_id' => $newDepartmentHeadUserId,
                    'contract_signer_user_id' => $newContractSignerUserId,
                ]);

                $notificationManager = $this->repository->notifications();
                $notificationManager->ensureDefaultRulesForRhHierarchyAssigned();

                $serviceLabel = trim((string) ($previousRow['service_label'] ?? ''));
                $previousDirectManagerUserId = (int) ($previousRow['direct_manager_user_id'] ?? 0);
                $previousDepartmentHeadUserId = (int) ($previousRow['department_head_user_id'] ?? 0);

                if ($newDirectManagerUserId > 0 && $newDirectManagerUserId !== $previousDirectManagerUserId) {
                    $notificationManager->notifyRhHierarchyAssigned($newDirectManagerUserId, 'direct_manager', [
                        'role_label' => 'Responsable',
                        'department_label' => $serviceLabel,
                        'service_label' => $serviceLabel,
                        'direction_label' => $newDirectionLabel,
                    ]);
                }

                if ($newDepartmentHeadUserId > 0 && $newDepartmentHeadUserId !== $previousDepartmentHeadUserId) {
                    $notificationManager->notifyRhHierarchyAssigned($newDepartmentHeadUserId, 'department_head', [
                        'role_label' => 'Directeur du département',
                        'department_label' => $serviceLabel,
                        'service_label' => $serviceLabel,
                        'direction_label' => $newDirectionLabel,
                    ]);
                }

                \AuditTrail::log('rh_explanation_service_hierarchy_updated', [
                    'module' => 'rh',
                    'action' => 'save_service_hierarchy',
                    'entity_type' => 'rh_service_hierarchy',
                    'entity_id' => (string) $serviceId,
                    'entity_label' => 'service_' . $serviceId,
                ]);

                return ['redirect' => 'parametrage_hierarchie_rh.php?updated=1', 'statusType' => null, 'statusMessage' => null];
            }
        } catch (Throwable $e) {
            return ['redirect' => null, 'statusType' => 'error', 'statusMessage' => $e->getMessage()];
        }

        return ['redirect' => null, 'statusType' => 'error', 'statusMessage' => 'Action non reconnue.'];
    }
}
