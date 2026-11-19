<?php
declare(strict_types=1);

require_once __DIR__ . '/../../auth_check.php';
AccessManager::requirePageAccess('fne_verify_invoice.php');
require_once __DIR__ . '/../../controllers/FneInvoiceLookupController.php';

header('Content-Type: application/json; charset=utf-8');
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new DomainException('Méthode non autorisée.');
    }
    $body = json_decode((string) file_get_contents('php://input'), true, 32, JSON_THROW_ON_ERROR);
    $token = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (empty($_SESSION['fne_lookup_csrf']) || !hash_equals((string) $_SESSION['fne_lookup_csrf'], $token)) {
        throw new RuntimeException('Jeton CSRF invalide.');
    }
    $result = (new FneInvoiceLookupController())->verify(
        (string) ($body['code'] ?? ''),
        (string) ($body['code_type'] ?? 'verificationCode')
    );
    AuditTrail::log('fne_invoice_lookup', [
        'module' => 'facturation',
        'action' => 'fne_invoice_lookup',
        'entity_type' => 'fne_invoice',
        'result' => 'success',
        'details' => ['code_hash' => hash('sha256', (string) ($body['code'] ?? ''))],
    ]);
    echo json_encode(['ok' => true, 'data' => $result], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    http_response_code($exception instanceof RuntimeException ? 503 : 422);
    echo json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
}
