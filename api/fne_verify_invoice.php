<?php

declare(strict_types=1);

require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../model/FneClient.php';
require_once __DIR__ . '/../model/FneValidator.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    \AuditTrail::log('fne_verify_failed', [
        'module' => 'api',
        'action' => 'fne_verify_invoice',
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
    \AuditTrail::log('fne_verify_failed', [
        'module' => 'api',
        'action' => 'fne_verify_invoice',
        'entity_type' => 'fne_invoice',
        'result' => 'failure',
        'severity' => 'warning',
        'details' => ['reason' => 'missing_payload'],
    ]);
    echo json_encode(['ok' => false, 'error' => 'Payload manquant.']);
    exit;
}

$validation = FneValidator::validateVerifyPayload($payload);
if (empty($validation['ok'])) {
    \AuditTrail::log('fne_verify_failed', [
        'module' => 'api',
        'action' => 'fne_verify_invoice',
        'entity_type' => 'fne_invoice',
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
        \AuditTrail::log('fne_verify_failed', [
            'module' => 'api',
            'action' => 'fne_verify_invoice',
            'entity_type' => 'fne_invoice',
            'result' => 'failure',
            'severity' => 'warning',
            'details' => ['reason' => 'fne_not_configured'],
        ]);
        echo json_encode(['ok' => false, 'error' => 'FNE non configuré (FNE_API_KEY/FNE_BASE_URL).']);
        exit;
    }

    $res = $client->verifyInvoice($payload);
    \AuditTrail::log(empty($res['ok']) ? 'fne_verify_failed' : 'fne_verified', [
        'module' => 'api',
        'action' => 'fne_verify_invoice',
        'entity_type' => 'fne_invoice',
        'entity_label' => (string)($payload['invoiceNumber'] ?? $payload['verificationCode'] ?? 'verification_facture_fne'),
        'result' => empty($res['ok']) ? 'failure' : 'success',
        'severity' => empty($res['ok']) ? 'warning' : 'info',
        'details' => ['api_status' => $res['status'] ?? null],
    ]);
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    exit;
} catch (Throwable $e) {
    \AuditTrail::log('fne_verify_failed', [
        'module' => 'api',
        'action' => 'fne_verify_invoice',
        'entity_type' => 'fne_invoice',
        'result' => 'failure',
        'severity' => 'warning',
        'details' => ['error' => $e->getMessage()],
    ]);
    echo json_encode(['ok' => false, 'error' => 'Erreur serveur.']);
    exit;
}
