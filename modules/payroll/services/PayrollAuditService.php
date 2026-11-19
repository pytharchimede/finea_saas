<?php

require_once dirname(__DIR__, 3) . '/model/AuditTrail.php';

class PayrollAuditService
{
    public function log(string $action, array $details, array $actor = []): void
    {
        AuditTrail::log('payroll_' . $action, [
            'module' => 'payroll',
            'action' => $action,
            'entity_type' => 'payroll',
            'entity_id' => isset($details['slip_id']) ? (string) $details['slip_id'] : null,
            'entity_label' => $details['label'] ?? null,
            'user_id' => $actor['user_id'] ?? null,
            'user_name' => $actor['user_name'] ?? null,
            'details' => $details,
        ]);
    }
}
