<?php

require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../repositories/RhPersonnelRepository.php';

class RhPersonnelService
{
    private RhPersonnelRepository $repository;

    public function __construct(RhPersonnelRepository $repository)
    {
        $this->repository = $repository;
    }

    public function model(): PersonnelDirectory
    {
        return $this->repository->model();
    }

    public function buildListView(array $query): array
    {
        $filters = [
            'q' => trim((string) ($query['q'] ?? '')),
            'service_id' => trim((string) ($query['service_id'] ?? '')),
            'fonction_id' => trim((string) ($query['fonction_id'] ?? '')),
            'status_code' => trim((string) ($query['status_code'] ?? '')),
            'gender_code' => trim((string) ($query['gender_code'] ?? '')),
            'site_id' => trim((string) ($query['site_id'] ?? '')),
            'scope' => (string) ($query['scope'] ?? 'all'),
            'search_mode' => (string) ($query['search_mode'] ?? 'include'),
            'personnel_category' => in_array((string)($query['personnel_category']??'interne'),['interne','prestataire'],true)?(string)$query['personnel_category']:'interne',
        ];

        if (!in_array($filters['scope'], ['active', 'inactive', 'all'], true)) {
            $filters['scope'] = 'active';
        }
        if (!in_array($filters['search_mode'], ['include', 'exclude'], true)) {
            $filters['search_mode'] = 'include';
        }

        $page = max(1, (int) ($query['page'] ?? 1));

        return [
            'filters' => $filters,
            'page' => $page,
            'filterOptions' => $this->repository->getFilters(),
            'quickStats' => $this->repository->getCategoryStats($filters['personnel_category']),
            'result' => $this->repository->getPersonnelList($filters, $page, 100),
        ];
    }

    public function handleExitReasonPost(array $post, array $actor): array
    {
        try {
            $action = trim((string) ($post['action'] ?? ''));

            if ($action === 'create') {
                $label = (string) ($post['label'] ?? '');
                $sortOrder = (int) ($post['sort_order'] ?? 0);
                $isActive = !empty($post['is_active']);
                $this->repository->model()->createExitReason($label, $isActive, $sortOrder);
                \AuditTrail::log('rh_exit_reason_created', [
                    'module' => 'rh',
                    'action' => 'create',
                    'entity_type' => 'exit_reason',
                    'entity_label' => $label,
                    'user_id' => $actor['user_id'] ?? null,
                    'user_name' => $actor['user_name'] ?? null,
                    'details' => ['sort_order' => $sortOrder, 'is_active' => $isActive],
                ]);

                return ['redirect' => 'parametrage_motifs_sortie_rh.php?saved=1', 'statusType' => null, 'statusMessage' => null];
            }

            if ($action === 'update') {
                $id = (int) ($post['id'] ?? 0);
                $label = (string) ($post['label'] ?? '');
                $sortOrder = (int) ($post['sort_order'] ?? 0);
                $isActive = !empty($post['is_active']);
                $this->repository->model()->updateExitReason($id, $label, $isActive, $sortOrder);
                \AuditTrail::log('rh_exit_reason_updated', [
                    'module' => 'rh',
                    'action' => 'update',
                    'entity_type' => 'exit_reason',
                    'entity_id' => (string) $id,
                    'entity_label' => $label,
                    'user_id' => $actor['user_id'] ?? null,
                    'user_name' => $actor['user_name'] ?? null,
                    'details' => ['sort_order' => $sortOrder, 'is_active' => $isActive],
                ]);

                return ['redirect' => 'parametrage_motifs_sortie_rh.php?updated=1', 'statusType' => null, 'statusMessage' => null];
            }

            if ($action === 'delete') {
                $id = (int) ($post['id'] ?? 0);
                $this->repository->model()->deleteExitReason($id);
                \AuditTrail::log('rh_exit_reason_deleted', [
                    'module' => 'rh',
                    'action' => 'delete',
                    'entity_type' => 'exit_reason',
                    'entity_id' => (string) $id,
                    'entity_label' => 'exit_reason_' . $id,
                    'user_id' => $actor['user_id'] ?? null,
                    'user_name' => $actor['user_name'] ?? null,
                ]);

                return ['redirect' => 'parametrage_motifs_sortie_rh.php?deleted=1', 'statusType' => null, 'statusMessage' => null];
            }

            throw new RuntimeException('Action non reconnue.');
        } catch (Throwable $e) {
            \AuditTrail::log('rh_exit_reason_failed', [
                'module' => 'rh',
                'action' => (string) ($post['action'] ?? ''),
                'entity_type' => 'exit_reason',
                'entity_id' => isset($post['id']) ? (string) ((int) $post['id']) : null,
                'entity_label' => (string) ($post['label'] ?? ''),
                'user_id' => $actor['user_id'] ?? null,
                'user_name' => $actor['user_name'] ?? null,
                'result' => 'failure',
                'severity' => 'warning',
                'details' => ['error' => $e->getMessage()],
            ]);

            return ['redirect' => null, 'statusType' => 'error', 'statusMessage' => $e->getMessage()];
        }
    }

    public function __call(string $name, array $arguments)
    {
        return $this->repository->{$name}(...$arguments);
    }
}
