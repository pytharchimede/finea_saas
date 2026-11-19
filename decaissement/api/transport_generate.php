<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../model/AuditTrail.php';
require_once __DIR__ . '/../service/TransportProviderService.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Méthode POST attendue
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    AuditTrail::log('transport_generate_failed', [
        'module' => 'decaissement',
        'action' => 'transport_generate',
        'entity_type' => 'transport_provider_request',
        'entity_label' => 'transport_generate',
        'result' => 'failure',
        'severity' => 'warning',
        'details' => ['reason' => 'invalid_method'],
    ]);
    echo json_encode(['ok' => false, 'error' => 'Méthode non autorisée']);
    exit;
}

// Récup input JSON ou form-data
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    $data = $_POST;
}

$providerId = (int)($data['providerId'] ?? 0);
$chantier_code = trim($data['chantier_code'] ?? '');
$beneficiaire = trim($data['beneficiaire'] ?? '');
$tel = trim($data['tel'] ?? '');
$details = trim($data['details'] ?? '');
$ligne_debourse_id = (int)($data['ligne_debourse_id'] ?? 0);
$montant_total = (float)($data['montant_total'] ?? 0);
$vehicule = $data['vehicule'] ?? '';
$quantite = $data['quantite'] ?? 0;

if ($providerId <= 0 || $chantier_code === '' || $beneficiaire === '' || $ligne_debourse_id <= 0 || $montant_total <= 0) {
    http_response_code(400);
    AuditTrail::log('transport_generate_failed', [
        'module' => 'decaissement',
        'action' => 'transport_generate',
        'entity_type' => 'transport_provider_request',
        'entity_label' => $chantier_code !== '' ? $chantier_code : 'transport_generate',
        'result' => 'failure',
        'severity' => 'warning',
        'details' => [
            'reason' => 'missing_required_fields',
            'provider_id' => $providerId,
            'ligne_debourse_id' => $ligne_debourse_id,
            'montant_total' => $montant_total,
        ],
    ]);
    echo json_encode(['ok' => false, 'error' => 'Champs requis manquants ou invalides']);
    exit;
}

try {
    $svc = new TransportProviderService();
    $res = $svc->generateVoyageFiches($providerId, [
        'chantier_code' => $chantier_code,
        'beneficiaire' => $beneficiaire,
        'tel' => $tel,
        'details' => $details,
        'ligne_debourse_id' => $ligne_debourse_id,
        'montant_total' => $montant_total,
        'vehicule' => $vehicule,
        'quantite' => $quantite,
        // secur/ip/port si session
        'secur' => ($_SESSION['secur_user'] ?? 'SYSTEM'),
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        'port' => (int)($_SERVER['REMOTE_PORT'] ?? 0),
    ]);
    AuditTrail::log(!empty($res['ok']) ? 'transport_generated' : 'transport_generate_failed', [
        'module' => 'decaissement',
        'action' => 'transport_generate',
        'entity_type' => 'transport_provider_request',
        'entity_id' => (string)$ligne_debourse_id,
        'entity_label' => $chantier_code,
        'user_name' => (string)($_SESSION['secur_user'] ?? 'SYSTEM'),
        'result' => !empty($res['ok']) ? 'success' : 'failure',
        'severity' => !empty($res['ok']) ? 'info' : 'warning',
        'details' => [
            'provider_id' => $providerId,
            'beneficiaire' => $beneficiaire,
            'montant_total' => $montant_total,
            'vehicule' => $vehicule !== '' ? $vehicule : null,
            'quantite' => $quantite,
        ],
    ]);
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    AuditTrail::log('transport_generate_failed', [
        'module' => 'decaissement',
        'action' => 'transport_generate',
        'entity_type' => 'transport_provider_request',
        'entity_id' => $ligne_debourse_id > 0 ? (string)$ligne_debourse_id : null,
        'entity_label' => $chantier_code !== '' ? $chantier_code : 'transport_generate',
        'user_name' => (string)($_SESSION['secur_user'] ?? 'SYSTEM'),
        'result' => 'failure',
        'severity' => 'warning',
        'details' => ['error' => $e->getMessage()],
    ]);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
