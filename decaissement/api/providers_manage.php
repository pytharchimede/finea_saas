<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../../model/AuditTrail.php';
require_once __DIR__ . '/../model/FournisseurTransport.php';

try {
    $pdo = Database::getConnection();
    $repo = new FournisseurTransport($pdo);
    $_SESSION['provider_identity_csrf']=$_SESSION['provider_identity_csrf']??bin2hex(random_bytes(24));
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'GET') {
        $all = $pdo->query('SELECT * FROM fournisseurs_transport ORDER BY nom')->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['ok' => true, 'providers' => $all]);
        exit;
    }
    if($method!=='POST'){http_response_code(405);exit;}
    // POST create/update
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        $data = $_POST;
    }
    if(!hash_equals((string)$_SESSION['provider_identity_csrf'],(string)($data['_csrf']??'')))throw new DomainException('Session expirée. Rechargez le formulaire.');
    $id = isset($data['id']) ? (int)$data['id'] : 0;
    $payload = [
        'master_supplier_id'=>(int)($data['master_supplier_id']??0),
        'nom' => trim($data['nom'] ?? ''),
        'telephone' => trim($data['telephone'] ?? ''),
        'whatsapp' => trim($data['whatsapp'] ?? ''),
        'actif' => (int)($data['actif'] ?? 1),
        'carburant_mode' => $data['carburant_mode'] ?? 'percent',
        'carburant_valeur' => (float)($data['carburant_valeur'] ?? 0),
        'frais_mode' => $data['frais_mode'] ?? 'fixed',
        'frais_valeur' => (float)($data['frais_valeur'] ?? 0),
        'solde_mode' => 'balance',
        'solde_valeur' => 0,
        'notes' => $data['notes'] ?? null,
    ];
    if ($id > 0) {
        $ok = $repo->update($id, $payload);
        AuditTrail::log($ok ? 'transport_provider_updated' : 'transport_provider_update_failed', [
            'module' => 'decaissement',
            'action' => 'providers_manage_update',
            'entity_type' => 'transport_provider',
            'entity_id' => (string) $id,
            'entity_label' => (string) ($payload['nom'] ?? 'Prestataire transport'),
            'result' => $ok ? 'success' : 'failed',
            'severity' => $ok ? 'info' : 'warning',
            'details' => [
                'telephone' => $payload['telephone'] ?? '',
                'whatsapp' => $payload['whatsapp'] ?? '',
                'actif' => (int) ($payload['actif'] ?? 1),
            ],
        ], $pdo);
        echo json_encode(['ok' => $ok, 'id' => $id]);
    } else {
        $newId = $repo->create($payload);
        AuditTrail::log($newId ? 'transport_provider_created' : 'transport_provider_create_failed', [
            'module' => 'decaissement',
            'action' => 'providers_manage_create',
            'entity_type' => 'transport_provider',
            'entity_id' => $newId ? (string) ((int) $newId) : null,
            'entity_label' => (string) ($payload['nom'] ?? 'Prestataire transport'),
            'result' => $newId ? 'success' : 'failed',
            'severity' => $newId ? 'info' : 'warning',
            'details' => [
                'telephone' => $payload['telephone'] ?? '',
                'whatsapp' => $payload['whatsapp'] ?? '',
                'actif' => (int) ($payload['actif'] ?? 1),
            ],
        ], $pdo);
        echo json_encode(['ok' => (bool)$newId, 'id' => $newId]);
    }
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO) {
        AuditTrail::log('transport_provider_manage_failed', [
            'module' => 'decaissement',
            'action' => 'providers_manage',
            'entity_type' => 'transport_provider',
            'entity_label' => 'Gestion prestataire transport',
            'result' => 'failed',
            'severity' => 'warning',
            'details' => [
                'error_message' => $e->getMessage(),
            ],
        ], $pdo);
    }
    http_response_code($e instanceof DomainException?422:500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

