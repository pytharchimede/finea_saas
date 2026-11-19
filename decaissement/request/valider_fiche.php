<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/Fiche.php';
require_once __DIR__ . '/../../model/AuditTrail.php';
session_start();

try {
    $pdo = Database::getConnection();
    $ficheObj = new Fiche($pdo);

    $id = $_POST['id'] ?? null;
    $num = $_POST['num_fiche'] ?? null;

    if (!$num && $id) {
        $fiche = $ficheObj->getById((int)$id);
        $num = $fiche['num_fiche'] ?? null;
    }
    if (!$num) {
        throw new Exception('num_fiche manquant');
    }

    $user = $_SESSION['user_login'] ?? $_SESSION['secur'] ?? 'system';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $port = $_SERVER['REMOTE_PORT'] ?? 0;

    $ok = $ficheObj->validerFicheByNum($num, (string)$user, (string)$ip, (int)$port);

    \AuditTrail::log($ok ? 'decaissement_fiche_validated' : 'decaissement_fiche_validate_failed', [
        'module' => 'decaissement',
        'action' => 'validate_fiche_decaissement',
        'entity_type' => 'fiche_decaissement',
        'entity_id' => (string) $num,
        'entity_label' => 'Validation fiche de décaissement ' . $num,
        'result' => $ok ? 'success' : 'failed',
        'severity' => $ok ? 'info' : 'warning',
        'details' => [
            'num_fiche' => (string) $num,
            'user' => (string) $user,
            'ip' => (string) $ip,
            'port' => (int) $port,
        ],
    ], $pdo);

    echo json_encode(['ok' => (bool)$ok]);
} catch (Throwable $e) {
    if (!empty($pdo) && $pdo instanceof PDO) {
        \AuditTrail::log('decaissement_fiche_validate_failed', [
            'module' => 'decaissement',
            'action' => 'validate_fiche_decaissement',
            'entity_type' => 'fiche_decaissement',
            'entity_id' => isset($num) ? (string) $num : null,
            'entity_label' => 'Validation fiche de décaissement',
            'result' => 'failed',
            'severity' => 'warning',
            'details' => [
                'num_fiche' => isset($num) ? (string) $num : null,
                'error_message' => $e->getMessage(),
            ],
        ], $pdo);
    }
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
