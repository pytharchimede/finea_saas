<?php

declare(strict_types=1);

require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../model/FneClient.php';
require_once __DIR__ . '/../model/FneValidator.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    \AuditTrail::log('fne_refund_failed', [
        'module' => 'api',
        'action' => 'fne_refund_invoice',
        'entity_type' => 'fne_invoice',
        'result' => 'failure',
        'severity' => 'warning',
        'details' => ['reason' => 'invalid_method'],
    ]);
    echo json_encode(['ok' => false, 'error' => 'Méthode invalide.']);
    exit;
}

$raw = file_get_contents('php://input');
$payload = null;
if (is_string($raw) && trim($raw) !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $payload = $decoded;
    }
}
if (!is_array($payload)) {
    $payload = $_POST;
}

if (!is_array($payload) || !$payload) {
    \AuditTrail::log('fne_refund_failed', [
        'module' => 'api',
        'action' => 'fne_refund_invoice',
        'entity_type' => 'fne_invoice',
        'result' => 'failure',
        'severity' => 'warning',
        'details' => ['reason' => 'missing_payload'],
    ]);
    echo json_encode(['ok' => false, 'error' => 'Payload manquant.']);
    exit;
}

$invoiceId = trim((string)($payload['invoice_id'] ?? $payload['invoiceId'] ?? ''));
if ($invoiceId === '') {
    \AuditTrail::log('fne_refund_failed', [
        'module' => 'api',
        'action' => 'fne_refund_invoice',
        'entity_type' => 'fne_invoice',
        'result' => 'failure',
        'severity' => 'warning',
        'details' => ['reason' => 'missing_invoice_id'],
    ]);
    echo json_encode(['ok' => false, 'error' => 'invoice_id requis.']);
    exit;
}

// Le payload attendu par l'API FNE refund ne doit pas inclure invoice_id
$refundPayload = $payload;
unset($refundPayload['invoice_id'], $refundPayload['invoiceId']);

$validation = FneValidator::validateRefundPayload($refundPayload);
if (empty($validation['ok'])) {
    \AuditTrail::log('fne_refund_failed', [
        'module' => 'api',
        'action' => 'fne_refund_invoice',
        'entity_type' => 'fne_invoice',
        'entity_id' => $invoiceId,
        'entity_label' => $invoiceId,
        'result' => 'failure',
        'severity' => 'warning',
        'details' => ['reason' => 'invalid_payload', 'error_count' => count($validation['errors'] ?? [])],
    ]);
    echo json_encode([
        'ok' => false,
        'error' => 'Payload FNE invalide.',
        'errors' => $validation['errors'] ?? [],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $client = FneClient::fromConfigFile();
    if (!$client->isConfigured()) {
        \AuditTrail::log('fne_refund_failed', [
            'module' => 'api',
            'action' => 'fne_refund_invoice',
            'entity_type' => 'fne_invoice',
            'entity_id' => $invoiceId,
            'entity_label' => $invoiceId,
            'result' => 'failure',
            'severity' => 'warning',
            'details' => ['reason' => 'fne_not_configured'],
        ]);
        echo json_encode(['ok' => false, 'error' => 'FNE non configuré (FNE_API_KEY/FNE_BASE_URL).']);
        exit;
    }

    $res = $client->refundInvoice($invoiceId, $refundPayload);
    \AuditTrail::log(empty($res['ok']) ? 'fne_refund_failed' : 'fne_refund_completed', [
        'module' => 'api',
        'action' => 'fne_refund_invoice',
        'entity_type' => 'fne_invoice',
        'entity_id' => $invoiceId,
        'entity_label' => $invoiceId,
        'result' => empty($res['ok']) ? 'failure' : 'success',
        'severity' => empty($res['ok']) ? 'warning' : 'info',
        'details' => [
            'refund_item_count' => is_array($refundPayload['items'] ?? null) ? count($refundPayload['items']) : 0,
            'api_status' => $res['status'] ?? null,
        ],
    ]);
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    exit;
} catch (Throwable $e) {
    \AuditTrail::log('fne_refund_failed', [
        'module' => 'api',
        'action' => 'fne_refund_invoice',
        'entity_type' => 'fne_invoice',
        'entity_id' => $invoiceId !== '' ? $invoiceId : null,
        'entity_label' => $invoiceId !== '' ? $invoiceId : 'refund_invoice',
        'result' => 'failure',
        'severity' => 'warning',
        'details' => ['error' => $e->getMessage()],
    ]);
    echo json_encode(['ok' => false, 'error' => 'Erreur serveur.']);
    exit;
}
