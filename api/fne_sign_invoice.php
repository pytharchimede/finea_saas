<?php

declare(strict_types=1);

require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../model/FneClient.php';
require_once __DIR__ . '/../model/FneValidator.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    \AuditTrail::log('fne_sign_failed', [
        'module' => 'api',
        'action' => 'fne_sign_invoice',
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
    \AuditTrail::log('fne_sign_failed', [
        'module' => 'api',
        'action' => 'fne_sign_invoice',
        'entity_type' => 'fne_invoice',
        'result' => 'failure',
        'severity' => 'warning',
        'details' => ['reason' => 'missing_payload'],
    ]);
    echo json_encode(['ok' => false, 'error' => 'Payload manquant.']);
    exit;
}

$validation = FneValidator::validateSignInvoicePayload($payload);
if (empty($validation['ok'])) {
    \AuditTrail::log('fne_sign_failed', [
        'module' => 'api',
        'action' => 'fne_sign_invoice',
        'entity_type' => 'fne_invoice',
        'entity_label' => (string)($payload['clientCompanyName'] ?? 'signature_facture_fne'),
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
        \AuditTrail::log('fne_sign_failed', [
            'module' => 'api',
            'action' => 'fne_sign_invoice',
            'entity_type' => 'fne_invoice',
            'entity_label' => (string)($payload['clientCompanyName'] ?? 'signature_facture_fne'),
            'result' => 'failure',
            'severity' => 'warning',
            'details' => ['reason' => 'fne_not_configured'],
        ]);
        echo json_encode(['ok' => false, 'error' => 'FNE non configuré (FNE_API_KEY/FNE_BASE_URL).']);
        exit;
    }

    $res = $client->signInvoice($payload);
    \AuditTrail::log(empty($res['ok']) ? 'fne_sign_failed' : 'fne_signed', [
        'module' => 'api',
        'action' => 'fne_sign_invoice',
        'entity_type' => 'fne_invoice',
        'entity_label' => (string)($payload['clientCompanyName'] ?? 'signature_facture_fne'),
        'result' => empty($res['ok']) ? 'failure' : 'success',
        'severity' => empty($res['ok']) ? 'warning' : 'info',
        'details' => [
            'invoice_type' => $payload['invoiceType'] ?? null,
            'item_count' => is_array($payload['items'] ?? null) ? count($payload['items']) : 0,
            'api_status' => $res['status'] ?? null,
        ],
    ]);

    // On propage le format du client (ok/status/data/error)
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    exit;
} catch (Throwable $e) {
    \AuditTrail::log('fne_sign_failed', [
        'module' => 'api',
        'action' => 'fne_sign_invoice',
        'entity_type' => 'fne_invoice',
        'entity_label' => (string)($payload['clientCompanyName'] ?? 'signature_facture_fne'),
        'result' => 'failure',
        'severity' => 'warning',
        'details' => ['error' => $e->getMessage()],
    ]);
    echo json_encode(['ok' => false, 'error' => 'Erreur serveur.']);
    exit;
}
