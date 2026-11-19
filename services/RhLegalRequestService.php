<?php

require_once __DIR__ . '/PasskeySignatureGuard.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../repositories/RhLegalRequestRepository.php';

class RhLegalRequestService
{
    private RhLegalRequestRepository $repository;

    public function __construct(RhLegalRequestRepository $repository)
    {
        $this->repository = $repository;
    }

    public function legalRequests(): EmployeeLegalRequestManager
    {
        return $this->repository->legalRequests();
    }

    public function absenceRanges(): AbsenceJustificationRangeManager
    {
        return $this->repository->absenceRanges();
    }

    public function payroll(): PayrollManager
    {
        return $this->repository->payroll();
    }

    public function buildValidationListView(array $query): array
    {
        $selectedStatus = strtolower(trim((string) ($query['status'] ?? 'all')));
        $allowedStatusFilters = ['submitted', 'approved', 'rejected', 'cancelled', 'all'];
        if (!in_array($selectedStatus, $allowedStatusFilters, true)) $selectedStatus = 'all';

        $statusFilterLabel = 'En attente';
        $selectedStatuses = ['submitted', 'details_requested'];
        if ($selectedStatus === 'approved') {
            $statusFilterLabel = 'Approuvées'; $selectedStatuses = ['approved'];
        } elseif ($selectedStatus === 'rejected') {
            $statusFilterLabel = 'Refusées'; $selectedStatuses = ['rejected'];
        } elseif ($selectedStatus === 'cancelled') {
            $statusFilterLabel = 'Annulées'; $selectedStatuses = ['cancelled'];
        } elseif ($selectedStatus === 'all') {
            $statusFilterLabel = 'Toutes'; $selectedStatuses = ['submitted', 'details_requested', 'approved', 'rejected', 'cancelled'];
        }

        $manager = $this->repository->legalRequests();
        $rows = $manager->listRequestsForTeam('rh', $selectedStatuses, 300);
        $viewId = isset($query['id']) && is_numeric($query['id']) ? (int) $query['id'] : 0;
        $view = $viewId > 0 ? $manager->getRequestById($viewId) : null;
        $events = $view ? $manager->listEventsForRequest($viewId, 80) : [];
        if ($view && ($view['assigned_team'] ?? '') !== 'rh') { $view = null; $events = []; }

        return compact('selectedStatus','statusFilterLabel','selectedStatuses','rows','viewId','view','events');
    }

    public function handleValidationPost(array $post, array $actor): array
    {
        if (in_array($post['action'] ?? '', ['bulk_approve', 'bulk_reject'], true)) {
            return $this->handleBulkValidationPost($post, $actor);
        }
        if (!isset($post['action'], $post['request_id']) || !is_numeric($post['request_id'])) return ['flash' => '', 'flashType' => 'info'];

        $manager = $this->repository->legalRequests();
        $rangeManager = $this->repository->absenceRanges();
        $payrollManager = $this->repository->payroll();
        $action = (string) $post['action'];
        $id = (int) $post['request_id'];
        $note = trim((string) ($post['note'] ?? ''));
        $auditActor = ['user_id' => (int) ($actor['user_id'] ?? 0), 'user_name' => (string) ($actor['name'] ?? '')];

        $request=$manager->getRequestById($id);
        if (!$request || ($request['assigned_team'] ?? '') !== 'rh') {
            return ['flash'=>'Demande RH introuvable.', 'flashType'=>'error'];
        }
        if ($request && RhFinancialPointService::managedRequest(Database::getConnection(),$request)) {
            return ['flash'=>'Traitez cette demande dans le point financier RH : arrêt RH, décisions et signature DG.','flashType'=>'error'];
        }

        if ($action === 'save_signature') {
            $signatureRole = strtolower(trim((string) ($post['signature_role'] ?? '')));
            try {
                if ($signatureRole === '') throw new RuntimeException('Rôle de signature manquant.');
                PasskeySignatureGuard::requireVerified((int)($actor['user_id'] ?? 0), 'rh_request:' . $id . ':' . $signatureRole);
                if ($manager->saveRequestSignature($id, $signatureRole, $actor, (string) ($post['signature_base64'] ?? ''))) {
                    \AuditTrail::log('rh_request_signed', [
                        'module'=>'rh','action'=>'request_signature','entity_type'=>'employee_request','entity_id'=>(string)$id,
                        'user_id'=>$auditActor['user_id'],'user_name'=>$auditActor['user_name'],'details'=>['role'=>$signatureRole,'context'=>'rh_request:'.$id.':'.$signatureRole],
                    ]);
                    return ['flash' => 'Signature ' . $this->approvalRoleLabel($signatureRole) . ' enregistrée.', 'flashType' => 'success'];
                }
                return ['flash' => 'Impossible d’enregistrer cette signature.', 'flashType' => 'error'];
            } catch (Throwable $e) {
                return ['flash' => $e->getMessage(), 'flashType' => 'error'];
            }
        }

        if ($action === 'approve') {
            if (!$manager->approveRequest($id, $actor, $note)) {
                \AuditTrail::log('rh_request_approve_failed', ['module'=>'rh','action'=>'validation_demandes_rh_approve','entity_type'=>'employee_request','entity_id'=>(string)$id,'entity_label'=>'request_'.$id,'user_id'=>$auditActor['user_id'],'user_name'=>$auditActor['user_name'],'result'=>'failure','severity'=>'warning','details'=>['note'=>$note!==''?$note:null]]);
                return ['flash' => 'Impossible d\'approuver cette demande.', 'flashType' => 'error'];
            }
            $flash = ''; $flashType = 'success'; $approved = $manager->getRequestById($id);
            if (is_array($approved)) {
                if (($approved['request_type'] ?? '') === 'regularisation_pointage') {
                    $applyStatus = strtolower(trim((string) ($post['apply_status'] ?? '')));
                    $result = $payrollManager->applyAttendanceRegularizationFromLegalRequest($approved, $auditActor, $applyStatus !== '' ? $applyStatus : null);
                    if (!($result['ok'] ?? false)) { $flash = 'Demande approuvée, mais pointage non modifié: ' . (string) ($result['message'] ?? 'Erreur.'); $flashType = 'error'; }
                    else { $flash = 'Demande approuvée et pointage mis à jour.'; $rangeManager->syncStatusFromLegalRequest((int) ($approved['id'] ?? $id), 'approved'); }
                }
                $payrollManager->syncDebtFromLegalRequest($approved, $auditActor);
            }
            if ($flash === '') {
                $approvalRole = $manager->getExpectedApprovalRole($approved ?? []);
                $flash = ($approvalRole === '' || ($approved['status'] ?? '') === 'approved') ? 'Demande approuvée.' : ('Validation ' . $this->approvalRoleLabel((string) ($actor['approval_role'] ?? '')) . ' enregistrée.');
            }
            \AuditTrail::log('rh_request_approved', ['module'=>'rh','action'=>'validation_demandes_rh_approve','entity_type'=>'employee_request','entity_id'=>(string)$id,'entity_label'=>is_array($approved)?(string)(($approved['title']??'')?:('request_'.$id)):('request_'.$id),'user_id'=>$auditActor['user_id'],'user_name'=>$auditActor['user_name'],'details'=>['note'=>$note!==''?$note:null,'request_type'=>is_array($approved)?($approved['request_type']??null):null]]);
            return ['flash' => $flash, 'flashType' => $flashType];
        }

        if ($action === 'reject') {
            if ($manager->rejectRequest($id, $actor, $note)) {
                $rangeManager->syncStatusFromLegalRequest($id, 'rejected');
                \AuditTrail::log('rh_request_rejected', ['module'=>'rh','action'=>'validation_demandes_rh_reject','entity_type'=>'employee_request','entity_id'=>(string)$id,'entity_label'=>'request_'.$id,'user_id'=>$auditActor['user_id'],'user_name'=>$auditActor['user_name'],'details'=>['note'=>$note!==''?$note:null]]);
                return ['flash' => 'Demande refusée.', 'flashType' => 'success'];
            }
            \AuditTrail::log('rh_request_reject_failed', ['module'=>'rh','action'=>'validation_demandes_rh_reject','entity_type'=>'employee_request','entity_id'=>(string)$id,'entity_label'=>'request_'.$id,'user_id'=>$auditActor['user_id'],'user_name'=>$auditActor['user_name'],'result'=>'failure','severity'=>'warning','details'=>['note'=>$note!==''?$note:null]]);
            return ['flash' => 'Impossible de refuser cette demande.', 'flashType' => 'error'];
        }

        return ['flash' => '', 'flashType' => 'info'];
    }

    private function handleBulkValidationPost(array $post, array $actor): array
    {
        $token = (string) ($_SESSION['rh_validation_bulk_csrf'] ?? '');
        if ($token === '' || !is_string($post['_csrf'] ?? null) || !hash_equals($token, $post['_csrf'])) {
            return ['flash'=>'Session expirée. Rechargez la page avant de traiter le lot.', 'flashType'=>'error'];
        }
        $rawIds = $post['request_ids'] ?? [];
        if (!is_array($rawIds) || count($rawIds) > 100 || !$rawIds) {
            return ['flash'=>'Sélectionnez entre 1 et 100 demandes.', 'flashType'=>'error'];
        }
        $ids = [];
        foreach ($rawIds as $rawId) {
            if (!is_scalar($rawId) || !ctype_digit((string) $rawId) || (int) $rawId <= 0) {
                return ['flash'=>'La sélection contient une référence invalide.', 'flashType'=>'error'];
            }
            $ids[(int) $rawId] = (int) $rawId;
        }
        $action = $post['action'] === 'bulk_approve' ? 'approve' : 'reject';
        $manager = $this->repository->legalRequests();
        $results = [];
        $succeeded = 0;
        foreach ($ids as $id) {
            try {
                $request = $manager->getRequestById($id);
                if (!$request || ($request['assigned_team'] ?? '') !== 'rh') {
                    throw new DomainException('Demande RH introuvable.');
                }
                if (RhFinancialPointService::managedRequest(Database::getConnection(), $request) || !empty($request['payload']['rh_financial_point_id'])) {
                    throw new DomainException('À traiter dans le point financier RH.');
                }
                $allowed = $action === 'approve'
                    ? $manager->canActorApproveRequest($request, $actor)
                    : $manager->canActorRejectRequest($request, $actor);
                if (!$allowed) {
                    throw new DomainException('Décision non autorisée : vérifiez le statut, votre rôle et la signature requise.');
                }
                // Reuse the individual workflow, including payroll updates and audit entries.
                $result = $this->handleValidationPost([
                    'action'=>$action, 'request_id'=>$id, 'note'=>(string) ($post['note'] ?? ''),
                ], $actor);
                if (($result['flashType'] ?? '') === 'success') $succeeded++;
                $results[] = ['id'=>$id, 'message'=>(string) $result['flash'], 'type'=>$result['flashType']];
            } catch (Throwable $e) {
                error_log('RH bulk decision #'.$id.': '.$e->getMessage());
                $results[] = ['id'=>$id, 'message'=>$e instanceof DomainException ? $e->getMessage() : 'Traitement incomplet. Vérifiez le statut de cette demande avant de réessayer.', 'type'=>'error'];
            }
        }
        return [
            'flash'=>$succeeded.' demande(s) traitée(s) sur '.count($ids).'. Consultez le résultat de chaque demande ci-dessous.',
            'flashType'=>$succeeded === count($ids) ? 'success' : 'error',
            'bulkResults'=>$results,
        ];
    }

    public function handleLeaveInitializationPost(array $post, int $userId): array
    {
        $manager = $this->repository->legalRequests();
        $selectedPersonnelId = max(0, (int) ($post['personnel_id'] ?? 0));
        try {
            $action = trim((string) ($post['action'] ?? ''));
            if ($action === 'save_settings') {
                $manager->saveLeaveSettings($post, $userId);
                \AuditTrail::log('rh_leave_settings_updated', ['module'=>'rh','action'=>'conges_initialisation_settings','entity_type'=>'rh_leave_settings','result'=>'success']);
                return ['redirect' => 'conges_initialisation_rh.php?settings_saved=1' . ($selectedPersonnelId > 0 ? '&personnel_id=' . $selectedPersonnelId : ''), 'errors' => []];
            }
            if ($action === 'save_opening_balance') {
                $this->assertLeaveEligibleEmployee($selectedPersonnelId);
                $savedId = $manager->saveLeaveOpeningBalance($post, $userId);
                \AuditTrail::log('rh_leave_opening_balance_saved', ['module'=>'rh','action'=>'conges_initialisation_save','entity_type'=>'rh_leave_opening_balance','entity_id'=>(string)$savedId,'result'=>'success','details'=>['personnel_id'=>$selectedPersonnelId]]);
                return ['redirect' => 'conges_initialisation_rh.php?saved=1&personnel_id=' . $selectedPersonnelId, 'errors' => []];
            }
            throw new RuntimeException('Action non reconnue.');
        } catch (Throwable $e) {
            return ['redirect' => null, 'errors' => [$e->getMessage()]];
        }
    }

    private function assertLeaveEligibleEmployee(int $personnelId): void
    {
        if ($personnelId <= 0) throw new RuntimeException('Sélectionnez un salarié interne.');
        $stmt = \Database::getConnection()->prepare("SELECT personnel_category, statut_pers_soignant_code FROM personnel WHERE id_personnel_soignant = :id LIMIT 1");
        $stmt->execute(['id' => $personnelId]); $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('Salarié introuvable.');
        $category = strtolower(trim((string) ($row['personnel_category'] ?? 'interne'))); $statusCode = (int) ($row['statut_pers_soignant_code'] ?? 0);
        if ($category === 'prestataire' || in_array($statusCode, [2, 3], true)) throw new RuntimeException('Les prestataires et consultants ne sont pas concernés par les soldes de congés salariés.');
    }

    private function approvalRoleLabel(string $role): string
    {
        return match (strtolower(trim($role))) { 'requester'=>'Demandeur','employee'=>'Employé','rh'=>'RH','drh'=>'DRH','dg'=>'DG', default=>'Validation' };
    }
}


