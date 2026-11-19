<?php
header('Content-Type: application/json');
session_start();
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/FicheDecaissement.php';
require_once __DIR__ . '/../../model/AuditTrail.php';
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Méthode invalide');
    $num = trim($_POST['num_fiche'] ?? '');
    $benef = trim($_POST['beficiaire_fiche'] ?? '');
    $montant = (float)($_POST['montant_fiche'] ?? 0);
    if (!$num || !$benef || $montant <= 0) throw new Exception('Données invalides');
    $pdo = Database::getConnection();
    $m = new FicheDecaissement($pdo);
    $user = $_SESSION['user_login'] ?? $_SESSION['secur'] ?? 'system';
    $ok = $m->modifierAvantValidation($num, $user, $benef, $montant);
    AuditTrail::log($ok ? 'achat_fiche_updated' : 'achat_fiche_update_failed', [
        'module' => 'decaissement',
        'action' => 'achat_modifier',
        'entity_type' => 'fiche_decaissement',
        'entity_id' => $num,
        'entity_label' => 'Modification achat fiche ' . $num,
        'result' => $ok ? 'success' : 'failed',
        'severity' => $ok ? 'info' : 'warning',
        'details' => [
            'num_fiche' => $num,
            'beneficiaire' => $benef,
            'montant_fiche' => $montant,
            'user' => $user,
        ],
    ], $pdo);
    echo json_encode(['ok' => $ok]);
} catch (Throwable $e) {
    if (!empty($pdo) && $pdo instanceof PDO) {
        AuditTrail::log('achat_fiche_update_failed', [
            'module' => 'decaissement',
            'action' => 'achat_modifier',
            'entity_type' => 'fiche_decaissement',
            'entity_id' => isset($num) ? (string) $num : null,
            'entity_label' => 'Modification achat fiche',
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
