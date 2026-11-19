<?php

require_once __DIR__ . '/../services/RhLegalRequestService.php';

class RhLegalRequestController
{
    private RhLegalRequestService $service;

    public function __construct(PDO $pdo)
    {
        $this->service = new RhLegalRequestService(new RhLegalRequestRepository($pdo));
    }

    public function legalRequests(): EmployeeLegalRequestManager
    {
        return $this->service->legalRequests();
    }

    public function absenceRanges(): AbsenceJustificationRangeManager
    {
        return $this->service->absenceRanges();
    }

    public function payroll(): PayrollManager
    {
        return $this->service->payroll();
    }

    public function validationListView(array $query): array
    {
        return $this->service->buildValidationListView($query);
    }

    public function handleValidationPost(array $post, array $actor): array
    {
        return $this->service->handleValidationPost($post, $actor);
    }

    public function handleLeaveInitializationPost(array $post, int $userId): array
    {
        return $this->service->handleLeaveInitializationPost($post, $userId);
    }
}
