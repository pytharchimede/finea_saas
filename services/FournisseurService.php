<?php

require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../repositories/FournisseurRepository.php';
require_once __DIR__ . '/FournisseurWorkflowNotificationService.php';

class FournisseurService
{
    private FournisseurRepository $repository;
    private FournisseurWorkflowNotificationService $notifier;

    public function __construct(FournisseurRepository $repository, FournisseurWorkflowNotificationService $notifier)
    {
        $this->repository = $repository;
        $this->notifier = $notifier;
    }

    public function model(): FournisseurRepository
    {
        return $this->repository;
    }

    public function buildDashboardView(): array
    {
        $model = $this->repository;
        $stats = $this->repository->getDashboardStats();

        return [
            'model' => $model,
            'stats' => $stats,
            'lastRows' => $this->repository->getLatestDashboardRows(),
            'reporting' => $model->isReady() ? $model->getDecaissementReport(25) : ['available' => false, 'rows' => []],
            'pendingRows' => $model->isReady() ? $model->listLatest(12, false, ['validation_status' => FournisseurRepository::STATUS_PENDING]) : [],
            'alerts' => [
                [
                    'label' => 'Validations à traiter',
                    'count' => (int) ($stats['pending_validation'] ?? 0),
                    'href' => 'fournisseurs.php?validation_status=pending',
                    'tone' => 'amber',
                    'icon' => 'fa-clipboard-check',
                ],
                [
                    'label' => 'Identités fiscales à vérifier',
                    'count' => (int) ($stats['missing_ncc_when_not_rne'] ?? 0),
                    'href' => 'fournisseurs.php?quality=missing_ncc',
                    'tone' => 'rose',
                    'icon' => 'fa-id-card',
                ],
                [
                    'label' => 'Contacts incomplets',
                    'count' => (int) ($stats['missing_contact'] ?? 0),
                    'href' => 'fournisseurs.php?quality=missing_contact',
                    'tone' => 'slate',
                    'icon' => 'fa-address-book',
                ],
            ],
        ];
    }

    public function buildListView(array $query, int $currentUserId, bool $isEmployeeOnly): array
    {
        $filters = $isEmployeeOnly ? ['created_by_user_id' => $currentUserId] : [];
        $archive = (string) ($query['archive'] ?? 'active');
        if (!in_array($archive, ['active', 'archived', 'all'], true)) $archive = 'active';
        $filters['archive'] = $archive;
        $search = trim((string)($query['q'] ?? $query['search'] ?? ''));
        if ($search !== '') {
            $filters['search'] = $search;
        }
        $validationStatus = trim((string) ($query['validation_status'] ?? ''));
        if (in_array($validationStatus, [FournisseurRepository::STATUS_PENDING, FournisseurRepository::STATUS_APPROVED, FournisseurRepository::STATUS_REJECTED], true)) {
            $filters['validation_status'] = $validationStatus;
        }

        $fournisseurs = $this->repository->listLatest(200, false, $filters);
        $quality = trim((string) ($query['quality'] ?? ''));
        if ($quality !== '') {
            $fournisseurs = array_values(array_filter($fournisseurs, static function (array $row) use ($quality): bool {
                if ($quality === 'incomplete') return empty($row['document_is_complete']);
                if ($quality === 'missing_ncc') {
                    return !SupplierExceptionPolicy::isException($row) && ($row['supplier_type'] ?? 'morale') === 'morale' && (($row['fiscal_country'] ?? 'CI') !== 'CI' ? !in_array($row['identity_status']??'', ['registry_verified','documentary_verified'],true) : (empty($row['ncc']) || empty($row['dgi_verified_at'])));
                }
                if ($quality === 'missing_rne') {
                    return !empty($row['is_rne']) && trim((string) ($row['rne'] ?? '')) === '';
                }
                if ($quality === 'missing_contact') {
                    return trim((string) ($row['email'] ?? '')) === '' && trim((string) ($row['telephone'] ?? '')) === '';
                }
                return true;
            }));
        }

        $positions = $this->repository->positioning()->listPositions('supplier', array_map(static fn(array $row): int => (int) ($row['id_fournisseur'] ?? 0), $fournisseurs));

        return [
            'fournisseurs' => $fournisseurs,
            'positioningSettings' => $this->repository->positioning()->getSettings('supplier'),
            'supplierPositions' => $positions,
            'activeFilters' => [
                'validation_status' => $validationStatus,
                'quality' => $quality,
                'q' => $search,
                'archive' => $archive,
            ],
        ];
    }

    public function buildEditView(int $id): array
    {
        $supplier = $id > 0 ? $this->repository->getById($id) : null;
        return [
            'fournisseur' => $supplier,
            'incidents' => $supplier ? $this->repository->listIncidents((int) $supplier['id_fournisseur']) : [],
            'supplierLocationPickerSettings' => $this->repository->positioningSettings()->get('supplier'),
            'supplierLocationPickerInitialPosition' => $supplier ? $this->repository->positioning()->getPosition('supplier', (int) ($supplier['id_fournisseur'] ?? 0)) : null,
            'categoryOptions' => $this->repository->listCategories(false),
            'legalFormOptions' => $this->repository->listLegalForms(false),
        ];
    }

    public function buildCreateView(): array
    {
        return [
            'categoryOptions' => $this->repository->listCategories(false),
            'legalFormOptions' => $this->repository->listLegalForms(false),
            'supplierLocationPickerSettings' => $this->repository->positioningSettings()->get('supplier'),
        ];
    }

    public function buildDetailsView(int $supplierId): array
    {
        $supplier = $this->repository->getById($supplierId);
        return [
            'supplier' => $supplier,
            'context' => $supplier ? $this->repository->getSupplierDisplayContext($supplierId, $supplier) : [],
            'positioningSettings' => $this->repository->positioning()->getSettings('supplier'),
            'position' => $supplier ? $this->repository->positioning()->getPosition('supplier', $supplierId) : null,
        ];
    }

    public function handleCreate(array $post, array $files, int $currentUserId): array
    {
        if (!$this->repository->isReady()) {
            return ['ok' => false, 'error' => $this->repository->getInitError() ?: 'Module indisponible.'];
        }

        $res = $this->repository->create($post, $currentUserId, $files);
        if (!empty($res['ok'])) {
            $supplier = !empty($res['id']) ? $this->repository->getById((int) $res['id']) : null;
            if ($supplier && !SupplierExceptionPolicy::isException($supplier)) {
                try {
                    $this->notifier->notifySubmitted($supplier, [
                        'user_id' => $currentUserId,
                        'name' => (string) ($_SESSION['user_name'] ?? ''),
                    ], 'created');
                } catch (Throwable $e) {
                }
            }
        }

        return $res;
    }

    public function handleEditPost(int $id, array $supplier, array $post, array $files, int $currentUserId, bool $isValidator): array
    {
        if (!$this->repository->isReady()) {
            return ['redirect' => 'edit_fournisseur.php?id=' . $id, 'flash' => ['error', $this->repository->getInitError() ?: 'Module indisponible.']];
        }

        $action = (string) ($post['action'] ?? 'update');
        if ($action === 'approve' || $action === 'reject') {
            if (!$isValidator) {
                return ['redirect' => 'edit_fournisseur.php?id=' . $id, 'flash' => ['error', 'Validation réservée à la comptabilité / administration.']];
            }

            $res = $action === 'approve'
                ? $this->repository->approve($id, $currentUserId, $post['validation_note'] ?? null)
                : $this->repository->reject($id, $currentUserId, $post['validation_note'] ?? null);

            if (!empty($res['ok'])) {
                $updatedSupplier = $this->repository->getById($id);
                if ($updatedSupplier) {
                    try {
                        $this->notifier->notifyProcessed($updatedSupplier, $action, [
                            'user_id' => $currentUserId,
                            'name' => (string) ($_SESSION['user_name'] ?? ''),
                        ], (string) ($post['validation_note'] ?? ''));
                    } catch (Throwable $e) {
                    }
                }
            }

            return [
                'redirect' => 'edit_fournisseur.php?id=' . $id,
                'flash' => [
                    !empty($res['ok']) ? 'success' : 'error',
                    !empty($res['ok']) ? ($action === 'approve' ? 'Fournisseur validé.' : 'Fournisseur rejeté.') : (string) ($res['error'] ?? 'Action impossible.'),
                ],
            ];
        }

        if ($action === 'add_incident') {
            $res = $this->repository->addIncident($id, $post, $currentUserId);
            return [
                'redirect' => 'edit_fournisseur.php?id=' . $id . '#incidents',
                'flash' => [
                    !empty($res['ok']) ? 'success' : 'error',
                    !empty($res['ok']) ? 'Incident fournisseur enregistré.' : (string) ($res['error'] ?? 'Impossible d\'enregistrer l\'incident.'),
                ],
            ];
        }

        $res = $this->repository->update($id, $post, $currentUserId, $isValidator, $files);
        if (!empty($res['ok'])) {
            if (!$isValidator) {
                $updatedSupplier = $this->repository->getById($id);
                if ($updatedSupplier) {
                    try {
                        $this->notifier->notifySubmitted($updatedSupplier, [
                            'user_id' => $currentUserId,
                            'name' => (string) ($_SESSION['user_name'] ?? ''),
                        ], 'updated');
                    } catch (Throwable $e) {
                    }
                }
            }

            \AuditTrail::log('supplier_updated', [
                'module' => 'fournisseur',
                'action' => 'edit_fournisseur',
                'entity_type' => 'supplier',
                'entity_id' => (string) $id,
                'entity_label' => (string) ($post['raison_sociale'] ?? ($supplier['raison_sociale'] ?? 'Fournisseur')),
                'result' => 'success',
                'details' => ['code_fournisseur' => $res['code_fournisseur'] ?? null, 'old_identity_method'=>$supplier['identity_method']??'fne', 'old_exception_reason'=>SupplierExceptionPolicy::isException($supplier)?($supplier['identity_evidence']??null):null, 'new_identity_method'=>$post['identity_method']??($supplier['identity_method']??'fne'), 'exception_reason'=>($post['identity_method']??'')==='exception'?($post['exception_reason']??null):null],
            ]);

            $code = trim((string) ($res['code_fournisseur'] ?? ''));
            return [
                'redirect' => 'edit_fournisseur.php?id=' . $id,
                'flash' => [
                    'success',
                    $code !== ''
                        ? ('Fournisseur mis à jour (Code: ' . $code . ').' . ($isValidator ? '' : ' Le dossier repart en validation.'))
                        : ('Fournisseur mis à jour.' . ($isValidator ? '' : ' Le dossier repart en validation.')),
                ],
            ];
        }

        \AuditTrail::log('supplier_update_failed', [
            'module' => 'fournisseur',
            'action' => 'edit_fournisseur',
            'entity_type' => 'supplier',
            'entity_id' => (string) $id,
            'entity_label' => (string) ($post['raison_sociale'] ?? ($supplier['raison_sociale'] ?? 'Fournisseur')),
            'result' => 'failed',
            'severity' => 'warning',
            'details' => [
                'reason' => 'model_update_failed',
                'error_message' => $res['error'] ?? null,
            ],
        ]);

        return ['redirect' => 'edit_fournisseur.php?id=' . $id, 'flash' => ['error', (string) ($res['error'] ?? 'Impossible de mettre à jour.')]];
    }

    public function handleCategoryPost(array $post): array
    {
        $action = (string) ($post['action'] ?? 'create');
        if ($action === 'update') {
            $result = $this->repository->updateCategory((int) ($post['edit_id'] ?? 0), $post);
            return ['ok' => !empty($result['ok']), 'message' => !empty($result['ok']) ? 'Catégorie fournisseur mise à jour.' : (string) ($result['error'] ?? 'Impossible de mettre à jour la catégorie.')];
        }
        if ($action === 'delete') {
            $result = $this->repository->deleteCategory((int) ($post['category_id'] ?? 0));
            return ['ok' => !empty($result['ok']), 'message' => !empty($result['ok']) ? 'Catégorie fournisseur supprimée.' : (string) ($result['error'] ?? 'Impossible de supprimer la catégorie.')];
        }
        $result = $this->repository->createCategory($post);
        return ['ok' => !empty($result['ok']), 'message' => !empty($result['ok']) ? 'Catégorie fournisseur créée.' : (string) ($result['error'] ?? 'Impossible de créer la catégorie.')];
    }

    public function handleLegalFormPost(array $post): array
    {
        $action = (string) ($post['action'] ?? 'create');
        if ($action === 'update') {
            $result = $this->repository->updateLegalForm((int) ($post['edit_id'] ?? 0), $post);
            return ['ok' => !empty($result['ok']), 'message' => !empty($result['ok']) ? 'Forme juridique mise à jour.' : (string) ($result['error'] ?? 'Impossible de mettre à jour la forme juridique.')];
        }
        if ($action === 'delete') {
            $result = $this->repository->deleteLegalForm((int) ($post['legal_form_id'] ?? 0));
            return ['ok' => !empty($result['ok']), 'message' => !empty($result['ok']) ? 'Forme juridique supprimée.' : (string) ($result['error'] ?? 'Impossible de supprimer la forme juridique.')];
        }
        $result = $this->repository->createLegalForm($post);
        return ['ok' => !empty($result['ok']), 'message' => !empty($result['ok']) ? 'Forme juridique créée.' : (string) ($result['error'] ?? 'Impossible de créer la forme juridique.')];
    }

    public function __call(string $name, array $arguments)
    {
        return $this->repository->{$name}(...$arguments);
    }
}



