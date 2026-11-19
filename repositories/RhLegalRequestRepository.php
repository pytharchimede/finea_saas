<?php

require_once __DIR__ . '/../model/EmployeeLegalRequestManager.php';
require_once __DIR__ . '/../model/AbsenceJustificationRangeManager.php';
require_once __DIR__ . '/../decaissement/model/PayrollManager.php';

class RhLegalRequestRepository
{
    private EmployeeLegalRequestManager $legalRequests;
    private AbsenceJustificationRangeManager $absenceRanges;
    private PayrollManager $payroll;

    public function __construct(PDO $pdo)
    {
        $this->legalRequests = new EmployeeLegalRequestManager($pdo);
        $this->absenceRanges = new AbsenceJustificationRangeManager($pdo);
        $this->payroll = new PayrollManager($pdo);
    }

    public function legalRequests(): EmployeeLegalRequestManager
    {
        return $this->legalRequests;
    }

    public function absenceRanges(): AbsenceJustificationRangeManager
    {
        return $this->absenceRanges;
    }

    public function payroll(): PayrollManager
    {
        return $this->payroll;
    }
}
