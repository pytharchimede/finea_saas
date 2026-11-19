<?php

class PayrollSlip
{
    use ArrayHydrationTrait;

    public int $id;
    public int $personnelId;
    public string $period;
    public float $netPay;

    public function __construct(array $row)
    {
        $this->id = (int) ($row['id'] ?? 0);
        $this->personnelId = (int) ($row['personnel_id'] ?? 0);
        $this->period = $this->stringValue($row, 'payroll_period');
        $this->netPay = (float) ($row['net_pay'] ?? 0);
    }
}
